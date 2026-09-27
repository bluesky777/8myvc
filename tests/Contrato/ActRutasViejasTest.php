<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **la app vieja no las ve** (contrato §2.11).
 *
 * Las nuevas viven en la misma `ws_actividades` que las del módulo viejo, distinguidas por `modo`.
 * Los listados viejos (`actividades/*`, `mis-actividades/*`) llevan `a.modo is null` para no
 * enseñar tipos que no saben pintar. Cada test pone al lado una vieja en la misma clase y periodo:
 * que la vieja salga es lo que demuestra que el listado mira esa clase, y que la nueva no salga es
 * lo que se fija. Las columnas de «compartida» se le ponen también a la nueva, para que no la
 * esconda otro filtro que no es el suyo.
 */
class ActRutasViejasTest extends CasoDeActividades
{
    /** @return array{0: int, 1: int} la nueva y la vieja, en la clase del titular */
    private function unaNuevaYUnaVieja(): array
    {
        $e = $this->escena();
        $nueva = $this->crear($this->cuestionario())['id'];

        $vieja = DB::table('ws_actividades')->insertGetId([
            'asignatura_id' => $e->clase,
            'periodo_id' => $e->periodo_id,
            'descripcion' => 'Vieja de pruebas',
            'created_by' => $e->titular->profesor_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ws_actividades')->whereIn('id', [$nueva, $vieja])
            ->update(['compartida' => 1, 'para_alumnos' => 1, 'para_profesores' => 1, 'para_acudientes' => 1]);

        return [$nueva, $vieja];
    }

    /** @return array<int, int> los ids de actividades de la clase en un `mis_asignaturas` */
    private function deLaClase(array $asignaturas): array
    {
        $clase = collect($asignaturas)->firstWhere('asignatura_id', $this->escena()->clase);
        $this->assertNotNull($clase, 'El listado no trae la clase: no mediría nada.');

        return array_map(fn ($a) => (int) $a['id'], $clase['actividades']);
    }

    public function test_actividades_datos_del_docente(): void
    {
        [$nueva, $vieja] = $this->unaNuevaYUnaVieja();

        $r = $this->como('titular')->putJson('/api/actividades/datos', ['asign_id' => $this->escena()->clase])->assertStatus(200);
        $ids = $this->deLaClase($r->json('mis_asignaturas'));

        $this->assertContains($vieja, $ids);
        $this->assertNotContains($nueva, $ids);
    }

    public function test_mis_actividades_del_alumno(): void
    {
        [$nueva, $vieja] = $this->unaNuevaYUnaVieja();

        $r = $this->como($this->alumno())->putJson('/api/mis-actividades/datos')->assertStatus(200);
        $ids = $this->deLaClase($r->json('mis_asignaturas'));

        $this->assertContains($vieja, $ids);
        $this->assertNotContains($nueva, $ids);
    }

    public function test_las_compartidas(): void
    {
        [$nueva, $vieja] = $this->unaNuevaYUnaVieja();

        $r = $this->como('directivo')->putJson('/api/actividades/compartidas')->assertStatus(200);

        foreach (['actv_alumnos', 'actv_profes', 'actv_acudi'] as $lista) {
            $ids = array_map(fn ($a) => (int) $a['id'], $r->json($lista));
            $this->assertContains($vieja, $ids, "{$lista} no trae la vieja: no mediría nada.");
            $this->assertNotContains($nueva, $ids, "{$lista} enseña una actividad nueva a la app vieja.");
        }
    }
}
