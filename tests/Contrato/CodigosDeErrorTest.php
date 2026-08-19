<?php

namespace Tests\Contrato;

/**
 * Los guards que fallaban con 500 en vez de con su código.
 *
 * Diecinueve sitios del código escribían `App::abort(400, ...)`. Dentro de
 * `namespace App\Http\Controllers`, ese `App` sin barra inicial resuelve a
 * `App\Http\Controllers\App`, una clase que no existe: PHP lanza un Error de
 * clase no encontrada y el usuario recibe **500**, nunca el 400 que el autor
 * escribió. Es el mismo mecanismo del `catch (Tymon\JWTAuth\...)` sin barra que
 * ya documentamos — un fallo que se ve en la consola del navegador y no en la
 * pantalla, así que nadie lo reporta.
 *
 * Al arreglarlos no se restaura el 400: como hasta hoy respondían 500, ningún
 * cliente puede estar leyendo un 400 de aquí, y no hay contrato que preservar.
 * Cada uno pasa al código que le toca.
 *
 * Ver docs/migracion/05-codigo-muerto-y-roto.md.
 */
class CodigosDeErrorTest extends CasoDeContrato
{
    public function test_quien_no_es_personal_no_crea_acudientes_y_recibe_403(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Alumno')->username);

        $this->postJson('/api/acudientes/crear', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso.');
    }

    public function test_borrar_una_subunidad_que_no_existe_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->deleteJson('/api/subunidades/destroy/999999999', [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Subunidad no existe o está en Papelera.');
    }

    public function test_borrar_una_unidad_que_no_existe_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);

        $this->deleteJson('/api/unidades/destroy/999999999', [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Unidad no existe o está en Papelera.');
    }
}
