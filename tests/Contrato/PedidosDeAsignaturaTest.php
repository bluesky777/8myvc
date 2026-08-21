<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Pedir una materia y pedir que le quiten a uno una asignatura.
 *
 * Las dos las encontró `tools/respuestas-que-mienten.py` **después de
 * ensancharlo**: devolvían `200` con `['msg' => 'No puedes']` y sin escribir
 * nada. Ver 05 §48.
 *
 * Lo que las hace algo más que un código mal puesto es el front:
 * `ListAsignaturasCtrl` hace `.then(r => $ctrl.pedidos.push(r.pedido))`, así que
 * con esa respuesta metía un `undefined` en la lista y pintaba **una solicitud en
 * blanco** que desaparecía al recargar.
 */
class PedidosDeAsignaturaTest extends CasoDeContrato
{
    private function grupoYMateria(): array
    {
        $fila = DB::selectOne('SELECT a.grupo_id, a.materia_id, a.id FROM asignaturas a
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene asignaturas.');

        return [(int) $fila->grupo_id, (int) $fila->materia_id, (int) $fila->id];
    }

    /** El camino bueno: un docente sí pide, y queda escrito. */
    public function test_un_docente_pide_una_materia(): void
    {
        [$grupo, $materia] = $this->grupoYMateria();
        $antes = DB::table('change_asked_assignment')->count();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAskedAssignment/solicitar-materia', [
                'grupo_id' => $grupo, 'materia_id' => $materia, 'creditos' => 3,
            ]);

        $r->assertStatus(200);
        $this->assertNotNull($r->json('pedido'));
        $this->assertSame($antes + 1, DB::table('change_asked_assignment')->count());
    }

    /**
     * Y el administrativo, que `auth.personal` deja pasar, recibía 200 con
     * «No puedes». El criterio no cambia —solo el docente pide cambios de
     * asignatura, porque un superusuario no pide, hace— pero ahora se dice.
     */
    public function test_un_administrativo_recibe_403_al_pedir_una_materia(): void
    {
        [$grupo, $materia] = $this->grupoYMateria();
        $antes = DB::table('change_asked_assignment')->count();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
            ->putJson('/api/ChangesAskedAssignment/solicitar-materia', [
                'grupo_id' => $grupo, 'materia_id' => $materia, 'creditos' => 3,
            ])->assertStatus(403);

        $this->assertSame($antes, DB::table('change_asked_assignment')->count(),
            'Tampoco escribía antes: lo que cambia es que ahora lo dice.');
    }

    public function test_un_docente_pide_que_le_quiten_una_asignatura(): void
    {
        [, , $asignatura] = $this->grupoYMateria();
        $antes = DB::table('change_asked_assignment')->count();

        $r = $this->withToken($this->tokenDe($this->usuarioDeTipo('Profesor')->username))
            ->putJson('/api/ChangesAskedAssignment/pedir-quitar-asignatura', ['asignatura_id' => $asignatura]);

        $r->assertStatus(200);
        $this->assertNotNull($r->json('pedido'));
        $this->assertSame($antes + 1, DB::table('change_asked_assignment')->count());
    }

    public function test_un_administrativo_recibe_403_al_pedir_que_quiten_una(): void
    {
        [, , $asignatura] = $this->grupoYMateria();

        $this->withToken($this->tokenDe($this->usuarioDeTipo('Usuario')->username))
            ->putJson('/api/ChangesAskedAssignment/pedir-quitar-asignatura', ['asignatura_id' => $asignatura])
            ->assertStatus(403);
    }
}
