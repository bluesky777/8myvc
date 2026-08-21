<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Los diez superusuarios no son diez personas.
 *
 * Varios documentos de esta migración razonan sobre «los diez `Admin`, que son
 * exactamente los diez `is_superuser`» como si fuera un dato limpio. Al mirarlo
 * de cerca resulta que **seis de los diez se llaman `algo(inhabilitado)`** y
 * están `is_active = 1`, fuera de la papelera y con su hash intacto: el colegio
 * dio por apagadas seis cuentas de superusuario **renombrándolas**, y el sistema
 * lee la bandera, no el nombre.
 *
 * Lo que fija este test no es el número —cambia con cada colegio— sino que el
 * comando **separe las dos cosas**: encendida es `is_active`, y la marca en el
 * nombre es solo una pista de lo que el colegio creía. Confundirlas en cualquiera
 * de los dos sentidos es lo que hace que un diagnóstico se deje de mirar.
 *
 * Ver docs/migracion/12-larastan-nivel-7.md §15.
 */
class SuperusuariosTest extends CasoDeContrato
{
    /** Deja a todos los superusuarios con un nombre sin marcas. */
    private function ningunoMarcado(): void
    {
        DB::update('UPDATE users SET username = CONCAT("admin", id) WHERE is_superuser = 1');
    }

    private function unSuperusuarioEncendido(): int
    {
        $fila = DB::selectOne('SELECT id FROM users
            WHERE is_superuser = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($fila, 'El seed necesita un superusuario activo.');

        return (int) $fila->id;
    }

    public function test_sin_marcas_en_el_nombre_sale_bien_pero_no_dice_que_este_todo_bien(): void
    {
        $this->ningunoMarcado();

        $this->comando('usuarios:superusuarios')
            ->expectsOutputToContain('Ninguna cuenta encendida se llama a sí misma inhabilitada')
            ->expectsOutputToContain('la marca en el nombre es una')
            ->assertExitCode(0);
    }

    /**
     * Una cuenta que dice estar inhabilitada y está encendida sale marcada.
     *
     * Es el caso real de la copia de desarrollo, y el que importa: nadie va a
     * mirar `is_superuser` de una cuenta cuyo nombre ya dice que no vale.
     */
    public function test_el_nombre_dice_inhabilitada_y_la_bandera_dice_que_no(): void
    {
        $this->ningunoMarcado();

        $id = $this->unSuperusuarioEncendido();

        DB::update('UPDATE users SET username = "coordinacion(inhabilitado)" WHERE id = ?', [$id]);

        $this->comando('usuarios:superusuarios')
            ->expectsOutputToContain('y el nombre los da por rotos . 1')
            ->expectsOutputToContain('coordinacion(inhabilitado)')
            ->expectsOutputToContain('lo que las')
            ->assertExitCode(1);
    }

    /**
     * Y apagada de verdad ya no cuenta, aunque el nombre siga diciéndolo.
     *
     * Es la mitad que evita el diagnóstico ruidoso: si el colegio hace lo que hay
     * que hacer —`is_active = 0`— el comando tiene que callarse aunque el nombre
     * no se haya tocado, o la próxima vez nadie lo corre.
     */
    public function test_apagarla_de_verdad_la_saca_de_la_lista(): void
    {
        $this->ningunoMarcado();

        $id = $this->unSuperusuarioEncendido();

        DB::update('UPDATE users SET username = "AUXILIAR(inhabilitado)", is_active = 0 WHERE id = ?', [$id]);

        $this->comando('usuarios:superusuarios')
            ->expectsOutputToContain('y el nombre los da por rotos . 0')
            ->expectsOutputToContain('apagados o en la papelera ....... 1')
            ->assertExitCode(0);
    }
}
