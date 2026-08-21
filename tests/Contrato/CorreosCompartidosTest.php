<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El comando que separa «no puede recuperar» de «comparte el correo».
 *
 * Lo que hay que comprobar no es que cuente: es que **no confunda las dos
 * preguntas**. Un correo que `filter_var` rechaza no puede usarse para pedir un
 * enlace —`postRecuperarClave` aborta con 422 antes de tocar la base—, así que
 * un grupo con dirección inválida no es un grupo compartido por muy grande que
 * sea. En la copia de desarrollo esa distinción es la diferencia entre «690
 * cuentas en peligro» y «16», y la primera cifra habría mandado a mirar
 * dieciséis colegios por un problema que no es ese.
 *
 * Y desde el arreglo del §10 hay una tercera que comprobar, que es la que un
 * diagnóstico viejo se traga: de un grupo que comparte correo **solo la cuenta
 * de id más bajo puede pedir el enlace**. Las demás no son un riesgo, son
 * cuentas que no pueden recuperar, y tienen que salir contadas ahí.
 *
 * Ver docs/migracion/12-larastan-nivel-7.md §8 y §13.
 */
class CorreosCompartidosTest extends CasoDeContrato
{
    /** Deja a todos los usuarios activos con una dirección propia y válida. */
    private function cadaUnoConLaSuya(): void
    {
        DB::update('UPDATE users SET email = CONCAT("u", id, "@ejemplo.test")
            WHERE deleted_at IS NULL AND is_active = 1');
    }

    /** @return array<int, int> los ids de los primeros usuarios activos */
    private function primeros(int $cuantos): array
    {
        return array_map(fn ($f) => (int) $f->id, DB::select(
            'SELECT id FROM users WHERE deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT '.$cuantos));
    }

    public function test_con_una_direccion_propia_cada_uno_no_hay_nada_que_hacer(): void
    {
        $this->cadaUnoConLaSuya();

        $this->comando('usuarios:correos-compartidos')
            ->expectsOutputToContain('Nada que hacer')
            ->assertExitCode(0);
    }

    /**
     * Un correo repetido pero **inválido** no cuenta como grupo compartido.
     *
     * Es el caso que separa este diagnóstico de uno que solo suma filas
     * repetidas: `@gmail.com` —un dominio suelto, sin parte local— lo llevaban
     * 674 alumnos en la base de desarrollo, y ninguno de ellos puede pedir un
     * enlace de reseteo.
     */
    public function test_un_correo_repetido_pero_invalido_no_cuenta_como_compartido(): void
    {
        $this->cadaUnoConLaSuya();

        [$a, $b] = $this->primeros(2);

        DB::update('UPDATE users SET email = "@gmail.com" WHERE id IN (?, ?)', [$a, $b]);

        $this->comando('usuarios:correos-compartidos')
            ->expectsOutputToContain('COMPARTEN CORREO ................ 0 cuentas')
            ->expectsOutputToContain('el correo no es una dirección')
            ->assertExitCode(1);
    }

    public function test_un_correo_repetido_y_valido_si_lo_es(): void
    {
        $this->cadaUnoConLaSuya();

        [$a, $b] = $this->primeros(2);

        DB::update('UPDATE users SET email = "hermanos@ejemplo.test" WHERE id IN (?, ?)', [$a, $b]);

        $this->comando('usuarios:correos-compartidos')
            ->expectsOutputToContain('COMPARTEN CORREO ................ 2 cuentas en 1 grupos')
            ->assertExitCode(1);
    }

    /** Un superusuario dentro del grupo sube el caso al primer bloque. */
    public function test_un_grupo_con_superusuario_sale_el_primero_y_en_rojo(): void
    {
        $this->cadaUnoConLaSuya();

        $super = DB::selectOne('SELECT id FROM users
            WHERE deleted_at IS NULL AND is_active = 1 AND is_superuser = 1 ORDER BY id LIMIT 1');

        $this->assertNotNull($super, 'El seed necesita un superusuario activo.');

        $otro = DB::selectOne('SELECT id FROM users
            WHERE deleted_at IS NULL AND is_active = 1 AND id != ? ORDER BY id LIMIT 1', [$super->id]);

        DB::update('UPDATE users SET email = "compartido@ejemplo.test" WHERE id IN (?, ?)',
            [$super->id, $otro->id]);

        $this->comando('usuarios:correos-compartidos')
            ->expectsOutputToContain('CON SUPERUSUARIO DENTRO')
            ->assertExitCode(1);
    }

    /**
     * La segunda cuenta de un correo compartido **no puede recuperar**, y el
     * comando tiene que contarla ahí y no solo en el bloque de compartidos.
     *
     * Es la consecuencia del arreglo del §10, y la razón de que este comando
     * haya tenido que cambiar: antes esa cuenta sí llegaba a un enlace, porque
     * podía nombrarse en el cuerpo de la petición — que era el agujero. Cerrarlo
     * le quitó la única vía que tenía. Un diagnóstico que siga diciendo «se
     * resetean entre sí» manda a arreglar lo arreglado y **esconde esto**.
     */
    public function test_la_segunda_de_un_correo_compartido_no_puede_recuperar(): void
    {
        $this->cadaUnoConLaSuya();

        [$a, $b] = $this->primeros(2);

        DB::update('UPDATE users SET email = "hermanos@ejemplo.test" WHERE id IN (?, ?)', [$a, $b]);

        $primero = DB::selectOne('SELECT username FROM users WHERE id = ?', [min($a, $b)]);

        $this->comando('usuarios:correos-compartidos')
            ->expectsOutputToContain('lo comparten y no son la 1ª .. 1')
            ->expectsOutputToContain('recibe el enlace: '.$primero->username)
            ->assertExitCode(1);
    }
}
