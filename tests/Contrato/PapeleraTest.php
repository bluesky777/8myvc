<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Borrar del todo lo que está en la papelera.
 *
 * `SubunidadesController::deleteForcedelete` preguntaba `if ($unidad)`, y en ese
 * método no hay ninguna `$unidad`: la variable se llama `$subunidad`. Es una
 * copia de `UnidadesController::deleteForcedelete` a la que se le olvidó
 * renombrar una palabra — el propio fichero lo tenía anotado desde la auditoría
 * de autorización.
 *
 * O sea que `DELETE api/subunidades/forcedelete/{id}` nunca borró nada: hasta
 * PHP 7 caía en el `else` y devolvía «no encontrada en la Papelera»; con PHP 8
 * y el manejador de Laravel, leer una variable indefinida es un 500.
 */
class PapeleraTest extends CasoDeContrato
{
    public function test_forzar_el_borrado_de_una_subunidad_la_borra_de_verdad(): void
    {
        $usuario = $this->usuarioLlanoDelPersonal();
        $this->assertSame(1, (int) $usuario->is_superuser);
        $token = $this->tokenDe($usuario->username);

        $id = DB::table('subunidades')->whereNull('deleted_at')->value('id');
        DB::table('subunidades')->where('id', $id)->update(['deleted_at' => now()]);

        $this->deleteJson("/api/subunidades/forcedelete/{$id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonPath('id', $id);

        $this->assertSame(0, DB::table('subunidades')->where('id', $id)->count(),
            'La fila debería haber desaparecido de la tabla, no solo tener deleted_at.');
    }

    public function test_forzar_el_borrado_de_algo_que_no_esta_en_la_papelera_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Usuario')->username);

        $id = DB::table('subunidades')->whereNull('deleted_at')->value('id');

        $this->deleteJson("/api/subunidades/forcedelete/{$id}", [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(404);
    }
}
