<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Corregirle el teléfono a un docente no le cambia la cuenta.
 *
 * `PUT profesores/update/{id}` hacía tres cosas que nadie pidió, en la misma
 * petición con la que la rejilla guarda un campo cualquiera:
 *
 * 1. **renombraba** al docente —`sanarInputUser()` fabrica `username` desde
 *    `nombres` cuando no viene, y la rejilla no lo manda—;
 * 2. lo **degradaba** —fabrica `is_superuser = false` por lo mismo—;
 * 3. le **perdía el correo de recuperación** —fabrica `email2` porque su llave es
 *    `if (!Request::input('email1'))` y **`email1` no existe en ninguno de los
 *    cuatro clientes**: cero apariciones, comprobado—.
 *
 * Con `users.username` UNIQUE, la primera deja a alguien fuera del sistema. Es la
 * §11 por la otra puerta: allí era **quién puede** renombrar, aquí es que **se
 * renombra sin que nadie lo pida**.
 *
 * **Y las tres estaban tapadas por una guarda que sí existía.** El escritor ya
 * usaba `CamposQueVinieron` para `is_active` y `email2` desde la §68, pero esa
 * clase contesta *«¿vino la clave?»* y no *«¿es éste el valor que vino?»*: con el
 * `merge()` delante, `trae('email2')` es cierto y el valor llega pisado.
 *
 * Ver docs/migracion/noche-2026-08-24/profes-1.md.
 */
class ProfesorQueSePisaTest extends CasoDeContrato
{
    /** Un profesor con cuenta, y su cuenta. */
    private function profesorConCuenta(): object
    {
        $fila = DB::selectOne('SELECT p.id, p.nombres, p.apellidos, p.celular, p.user_id,
                   u.username, u.is_superuser, u.email, u.is_active
              FROM profesores p
              INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
             WHERE p.deleted_at IS NULL AND p.user_id IS NOT NULL
             ORDER BY p.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún profesor con cuenta.');

        return $fila;
    }

    private function cuenta(int $userId): object
    {
        return DB::selectOne('SELECT username, is_superuser, email, is_active
                                FROM users WHERE id = ?', [$userId]);
    }

    /**
     * **El cuerpo es el de la rejilla, no uno inventado.** Manda la ficha del
     * docente —lo que el formulario tiene— y NO manda `username`, `is_superuser`
     * ni `email2`, que es justo el caso: esos tres no están en esa pantalla.
     */
    private function cuerpoDeLaRejilla(object $p, string $celular): array
    {
        return [
            'id' => $p->id,
            'nombres' => $p->nombres,
            'apellidos' => $p->apellidos,
            'celular' => $celular,
        ];
    }

    public function test_corregir_el_telefono_no_renombra_al_docente(): void
    {
        $p = $this->profesorConCuenta();
        $antes = $this->cuenta((int) $p->user_id);

        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->withToken($token)
            ->putJson('/api/profesores/update/'.$p->id, $this->cuerpoDeLaRejilla($p, '3001234567'))
            ->assertStatus(200);

        $despues = $this->cuenta((int) $p->user_id);

        $this->assertSame($antes->username, $despues->username,
            'Le cambió el nombre de usuario al corregirle el teléfono.');
    }

    public function test_corregir_el_telefono_no_degrada_a_un_superusuario(): void
    {
        $p = $this->profesorConCuenta();

        // Se le sube a superusuario dentro de la transacción del test: el caso
        // sólo se ve con alguien que tenga algo que perder.
        DB::update('UPDATE users SET is_superuser = 1 WHERE id = ?', [$p->user_id]);

        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->withToken($token)
            ->putJson('/api/profesores/update/'.$p->id, $this->cuerpoDeLaRejilla($p, '3009876543'))
            ->assertStatus(200);

        $this->assertSame(1, (int) $this->cuenta((int) $p->user_id)->is_superuser,
            'Perdió el superusuario al corregirle el teléfono.');
    }

    /**
     * **Éste pasa TAMBIÉN con el código roto, y se queda a propósito.**
     *
     * Comprobado al revés —revirtiendo el arreglo y contando—: de los cinco caen
     * cuatro, y este no. No es que no cace nada: es que **el síntoma que fija ya
     * estaba arreglado** por la guarda `trae('email2')` de la §68, que cubre el
     * caso de que el cliente NO mande el campo.
     *
     * Importa porque corrige la medición de partida: el parte decía que corregir
     * el teléfono pierde el correo de recuperación, y **eso sólo pasa si la
     * rejilla SÍ manda `email2`** —el caso del último test de este fichero, que
     * sí cae—. Con un cuerpo que no lo manda, el correo estaba a salvo desde el
     * 21 ago. Los tres síntomas reportados no tenían la misma causa.
     */
    public function test_corregir_el_telefono_no_pierde_el_correo_de_recuperacion(): void
    {
        $p = $this->profesorConCuenta();

        DB::update('UPDATE users SET email = ? WHERE id = ?',
            ['recuperacion@ejemplo.test', $p->user_id]);

        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->withToken($token)
            ->putJson('/api/profesores/update/'.$p->id, $this->cuerpoDeLaRejilla($p, '3005555555'))
            ->assertStatus(200);

        $this->assertSame('recuperacion@ejemplo.test', $this->cuenta((int) $p->user_id)->email,
            'Sustituyó el correo de la cuenta por uno fabricado.');
    }

    /**
     * La otra mitad, sin la cual las tres de arriba pasarían con el método roto de
     * otra forma —por ejemplo dejando de escribir nunca—: **cuando el cuerpo SÍ
     * trae los campos, se escriben.**
     */
    public function test_cuando_el_cuerpo_si_los_trae_se_escriben(): void
    {
        $p = $this->profesorConCuenta();
        $nombre = 'renombrado'.random_int(100000, 999999);

        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->withToken($token)
            ->putJson('/api/profesores/update/'.$p->id,
                $this->cuerpoDeLaRejilla($p, '3001112222') + [
                    'username' => $nombre,
                    'email2' => 'nuevo@ejemplo.test',
                ])
            ->assertStatus(200);

        $despues = $this->cuenta((int) $p->user_id);

        $this->assertSame($nombre, $despues->username, 'No escribió el username que sí venía.');
        $this->assertSame('nuevo@ejemplo.test', $despues->email, 'No escribió el email2 que sí venía.');
    }

    /**
     * Y el `email2` mandado por el cliente **no se pisa con el fabricado**, que es
     * la mitad que la guarda de `CamposQueVinieron` no podía coger: allí
     * `trae('email2')` era cierto y el valor llegaba ya sustituido.
     */
    public function test_el_correo_mandado_gana_al_fabricado(): void
    {
        $p = $this->profesorConCuenta();

        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $this->withToken($token)
            ->putJson('/api/profesores/update/'.$p->id,
                $this->cuerpoDeLaRejilla($p, '3003334444') + [
                    'email2' => 'elmio@ejemplo.test',
                    // `email` es el de la FICHA. Antes ganaba éste y sobrescribía
                    // el de la cuenta, que es lo que se comprueba en negativo.
                    'email' => 'ficha@ejemplo.test',
                ])
            ->assertStatus(200);

        $this->assertSame('elmio@ejemplo.test', $this->cuenta((int) $p->user_id)->email,
            'El correo de la ficha pisó al de la cuenta.');
    }
}
