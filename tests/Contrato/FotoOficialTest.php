<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT api/images-users/cambiar-foto-un-usuario/{user_id}`, la foto oficial.
 *
 * Sale de la lista del nivel 7 de larastan, que señaló `save()` sobre
 * `Acudiente|Alumno|Profesor|stdClass` — o sea que había un camino por el que lo
 * que se guarda no es un modelo. Lo había, y son dos. Ver 05 §44.
 *
 * Hay dos imágenes por persona y no una, que es lo que explica el reparto:
 * `users.imagen_id` es el avatar de la cuenta y lo cambia la ruta hermana
 * `cambiar-imagen-un-usuario`; `alumnos|profesores|acudientes.foto_id` es la foto
 * oficial, la que sale en el carné y en los informes, y es ésta. Un `Usuario`
 * administrativo tiene lo primero y no tiene lo segundo.
 */
class FotoOficialTest extends CasoDeContrato
{
    private function imagen(): int
    {
        $id = DB::table('images')->whereNull('deleted_at')->orderBy('id')->value('id');

        $this->assertNotNull($id, 'El seed no tiene ninguna imagen.');

        return (int) $id;
    }

    /** El camino bueno, que es lo que no se puede romper al arreglar los otros. */
    public function test_a_un_alumno_se_le_cambia_la_foto_oficial(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');
        $imagen = $this->imagen();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson("/api/images-users/cambiar-foto-un-usuario/{$alumno->id}", ['imagen_id' => $imagen]);

        $r->assertStatus(200);
        $this->assertSame($imagen, (int) DB::table('alumnos')->where('user_id', $alumno->id)->value('foto_id'));
    }

    /** Y sin `imagen_id` la quita, que es el botón «Quitar foto» del gestor. */
    public function test_sin_imagen_la_foto_se_quita(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson("/api/images-users/cambiar-foto-un-usuario/{$alumno->id}", [])
            ->assertStatus(200);

        $this->assertNull(DB::table('alumnos')->where('user_id', $alumno->id)->value('foto_id'));
    }

    /**
     * El fatal que señaló el análisis.
     *
     * El `switch` tenía rama para Alumno, Profesor y Acudiente y **ninguna para
     * `Usuario` ni `default`**, así que `$persona` se quedaba en el `stdClass`
     * vacío con el que se inicializaba y `$persona->save()` reventaba: 500 en una
     * operación que sencillamente no existe para un administrativo. Ahora lo dice.
     */
    public function test_un_administrativo_no_tiene_foto_oficial(): void
    {
        $administrativo = DB::selectOne('SELECT id FROM users
            WHERE tipo = "Usuario" AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($administrativo, 'El seed no tiene ningún usuario administrativo.');

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson("/api/images-users/cambiar-foto-un-usuario/{$administrativo->id}",
                ['imagen_id' => $this->imagen()])
            ->assertStatus(422);
    }

    /**
     * El segundo camino al mismo fatal, y el que puede pasar de verdad: la cuenta
     * existe y su ficha no. `first()` devuelve null y `null->foto_id` revienta
     * igual. Se monta borrando la ficha del alumno y dejando su cuenta viva, que
     * es exactamente lo que queda cuando se retira a un alumno.
     */
    public function test_una_cuenta_sin_ficha_es_404(): void
    {
        $alumno = $this->usuarioDeTipo('Alumno');

        DB::table('alumnos')->where('user_id', $alumno->id)->update(['deleted_at' => now()]);

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson("/api/images-users/cambiar-foto-un-usuario/{$alumno->id}",
                ['imagen_id' => $this->imagen()])
            ->assertStatus(404);
    }

    /**
     * El `if` sin `else`: un administrativo sin `is_superuser` pasa `auth.personal`,
     * no cumple la condición de dentro y recibía **200 con el cuerpo vacío** y la
     * foto sin tocar. Una respuesta que miente, la forma de la §37.
     *
     * No se le amplía nada a nadie: el que no podía sigue sin poder. Lo que cambia
     * es que ahora se entera, y que la foto se comprueba que sigue igual.
     */
    public function test_un_administrativo_sin_superusuario_recibe_403(): void
    {
        $administrativo = DB::selectOne('SELECT id, username FROM users
            WHERE tipo = "Usuario" AND is_active = 1 AND deleted_at IS NULL
              AND (is_superuser IS NULL OR is_superuser = 0) ORDER BY id LIMIT 1');

        if (! $administrativo) {
            $this->markTestSkipped('El seed no tiene un administrativo sin is_superuser.');
        }

        $alumno = $this->usuarioDeTipo('Alumno');
        $antes = DB::table('alumnos')->where('user_id', $alumno->id)->value('foto_id');

        $this->withToken($this->tokenDe($administrativo->username))
            ->putJson("/api/images-users/cambiar-foto-un-usuario/{$alumno->id}",
                ['imagen_id' => $this->imagen()])
            ->assertStatus(403);

        $this->assertSame($antes, DB::table('alumnos')->where('user_id', $alumno->id)->value('foto_id'));
    }

    /**
     * La firma del profesor, que es la hermana de la foto y tenía la misma forma
     * con una vuelta más: aquí el `else` **existía** y devolvía la cadena
     * `'No tienes permiso'` **con 200**. Peor que no tenerlo, porque el front hace
     * `.then()` y dentro pinta la firma como cambiada —mueve la imagen de las
     * privadas a las del usuario y actualiza `firma_id` en pantalla—, así que al
     * administrativo se le enseñaba puesta y al recargar no estaba. Ver 05 §48.2.
     */
    public function test_un_administrativo_sin_superusuario_no_cambia_la_firma(): void
    {
        $administrativo = DB::selectOne('SELECT username FROM users
            WHERE tipo = "Usuario" AND is_active = 1 AND deleted_at IS NULL
              AND (is_superuser IS NULL OR is_superuser = 0) ORDER BY id LIMIT 1');

        if (! $administrativo) {
            $this->markTestSkipped('El seed no tiene un administrativo sin is_superuser.');
        }

        $profesor = DB::selectOne('SELECT id, firma_id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->withToken($this->tokenDe($administrativo->username))
            ->putJson("/api/images-users/cambiar-firma-un-profe/{$profesor->id}", ['imagen_id' => $this->imagen()])
            ->assertStatus(403);

        $this->assertSame($profesor->firma_id, DB::table('profesores')->where('id', $profesor->id)->value('firma_id'));
    }

    /** Y el profesor sí, que es lo que no se puede romper. */
    public function test_un_profesor_cambia_la_firma(): void
    {
        $profesor = DB::selectOne('SELECT id FROM profesores WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $imagen = $this->imagen();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson("/api/images-users/cambiar-firma-un-profe/{$profesor->id}", ['imagen_id' => $imagen])
            ->assertStatus(200);

        $this->assertSame($imagen, (int) DB::table('profesores')->where('id', $profesor->id)->value('firma_id'));
    }
}
