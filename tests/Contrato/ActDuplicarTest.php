<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **duplicar** (contrato §3.14).
 *
 * Una copia por destino, en borrador, en el año y el periodo del token, con `duplicada_de`. Se
 * copian las partes pedidas (`preguntas`, `configuracion`, `instrucciones`, `avisos`,
 * `destinatarios`) y **nunca** hojas, respuestas, entregas, notas ni subunidad: la copia crea su
 * indicador al publicarse. Las condiciones de la copia apuntan a las preguntas de la copia.
 */
class ActDuplicarTest extends CasoDeActividades
{
    /**
     * Un cuestionario calificable publicado, respondido, con una condición y con nota en la planilla.
     *
     * @return array{0: int, 1: array, 2: array}
     */
    private function origen(): array
    {
        $act = $this->crear($this->cuestionario(['califica' => true, 'unidad_id' => $this->escena()->unidad_id, 'peso' => 10,
            'instrucciones' => '<p>Lee con calma.</p>', 'oportunidades' => 2, 'avisos' => ['recordar_horas_antes' => 24]]));
        $q1 = $this->unica($act['id'], 0);
        $q2 = $this->unica($act['id'], 1);
        $this->como('titular')->postJson("/api/act/preguntas/{$q2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $q1['id'], 'operador' => 'es', 'opcion_id' => $q1['opciones'][0]['id']]]]])->assertStatus(200);
        $this->publicar($act['id']);
        $this->enviar($act['id'], $this->alumno(), [$this->marcar($q1, 0), $this->marcar($q2, 1)])->assertStatus(200);

        return [$act['id'], $q1, $q2];
    }

    public function test_una_copia_por_clase_en_borrador_sin_hojas_ni_notas(): void
    {
        [$id] = $this->origen();
        $e = $this->escena();

        $r = $this->como('titular')->postJson("/api/act/{$id}/duplicar", [
            'destinos' => [['asignatura_id' => $e->clase], ['asignatura_id' => $e->clase2]],
            'titulo' => 'Quiz (copia)',
        ]);
        $r->assertStatus(200);
        $creadas = $r->json('creadas');
        $this->assertCount(2, $creadas);

        foreach ($creadas as $i => $copia) {
            $leida = $this->como('titular')->getJson("/api/act/{$copia}")->assertStatus(200)->json();
            $fila = $this->fila($copia);

            $this->assertSame('borrador', $leida['estado']);
            $this->assertSame($id, $leida['duplicada_de']);
            $this->assertSame([$e->clase, $e->clase2][$i], $leida['asignatura_id']);
            $this->assertSame('Quiz (copia)', $leida['titulo']);
            $this->assertSame($e->year_id, (int) $fila->year_id);
            $this->assertSame($e->periodo_id, (int) $fila->periodo_id);
            $this->assertSame('<p>Lee con calma.</p>', $leida['instrucciones']);
            $this->assertSame(2, $leida['oportunidades']);
            $this->assertSame(24, $leida['avisos']['recordar_horas_antes']);
            $this->assertNull($leida['unidad_id'], 'El logro es de la clase del origen: el destino elige el suyo.');

            // Nunca hojas, entregas, notas ni subunidad.
            $this->assertSame(0, $leida['hojas_n']);
            $this->assertNull($leida['subunidad_id']);
            $this->assertFalse($leida['tiene_notas']);
            $this->assertSame(0, DB::table('ws_actividades_resueltas')->where('actividad_id', $copia)->count());
            $this->assertSame(0, DB::table('ws_entregas')->where('actividad_id', $copia)->count());
            $this->assertSame(0, DB::table('subunidades')->where('actividad_id', $copia)->count());

            // Las preguntas, y la condición apuntando a la copia.
            $this->assertCount(2, $leida['preguntas']);
            [$c1, $c2] = $leida['preguntas'];
            $this->assertSame($c1['id'], $c2['condiciones'][0][0]['depende_de_id']);
            $this->assertSame($c1['opciones'][0]['id'], $c2['condiciones'][0][0]['opcion_id']);
            $this->assertTrue($c2['opciones'][1]['is_correct']);
        }

        $this->assertSame(1, DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->count(), 'Duplicar tocó el origen.');
    }

    public function test_solo_las_partes_pedidas(): void
    {
        [$id] = $this->origen();

        $copia = $this->como('titular')->postJson("/api/act/{$id}/duplicar", [
            'destinos' => [['asignatura_id' => $this->escena()->clase]],
            'copiar' => ['preguntas' => false, 'instrucciones' => false, 'configuracion' => false, 'avisos' => false],
        ])->assertStatus(200)->json('creadas.0');

        $leida = $this->como('titular')->getJson("/api/act/{$copia}")->json();
        $this->assertSame([], $leida['preguntas']);
        $this->assertNull($leida['instrucciones']);
        $this->assertSame(1, $leida['oportunidades']);
        $this->assertFalse($leida['califica']);
        $this->assertNull($leida['avisos']['recordar_horas_antes']);
    }

    public function test_la_de_otro_docente_solo_la_duplica_un_directivo(): void
    {
        [$id] = $this->origen();

        $this->como('ajeno')->postJson("/api/act/{$id}/duplicar", ['destinos' => [['asignatura_id' => $this->escena()->clase_ajeno]]])->assertStatus(403);

        $this->como('directivo')->postJson("/api/act/{$id}/duplicar", ['destinos' => [['asignatura_id' => $this->escena()->clase]]])->assertStatus(200);
    }

    public function test_la_clase_del_destino_tiene_que_ser_suya(): void
    {
        [$id] = $this->origen();
        $antes = DB::table('ws_actividades')->count();

        $this->como('titular')->postJson("/api/act/{$id}/duplicar", ['destinos' => [
            ['asignatura_id' => $this->escena()->clase], ['asignatura_id' => $this->escena()->clase_ajeno]]])->assertStatus(403);

        $this->assertSame($antes, DB::table('ws_actividades')->count(), 'Un destino malo dejó creadas las copias de los buenos.');

        $this->como('titular')->postJson("/api/act/{$id}/duplicar", ['destinos' => []])->assertStatus(422);
        $this->como('titular')->postJson("/api/act/{$id}/duplicar", ['destinos' => [[]]])->assertStatus(422);
    }

    /** Desde otro año: la copia nace en el año y el periodo del token; lo del año viejo no viaja. */
    public function test_desde_otro_anio(): void
    {
        $e = $this->escena();
        $viejo = (int) DB::table('years')->where('id', '<>', $e->year_id)->whereNull('deleted_at')->orderByDesc('year')->value('id');

        $encuesta = $this->crear($this->encuesta(['titulo' => 'Del año pasado']));
        $this->unica($encuesta['id'], null);
        DB::table('ws_actividades')->where('id', $encuesta['id'])->update(['year_id' => $viejo]);

        // Buscarla entre las mías de ese año.
        $this->assertContains($encuesta['id'], array_column($this->como('titular')->getJson("/api/act/para-duplicar?year_id={$viejo}&q=pasado")->json(), 'id'));

        $copia = $this->como('titular')->postJson("/api/act/{$encuesta['id']}/duplicar", ['destinos' => [[]]])->assertStatus(200)->json('creadas.0');

        $leida = $this->como('titular')->getJson("/api/act/{$copia}")->json();
        $this->assertSame($e->year_id, (int) $this->fila($copia)->year_id);
        $this->assertSame('grupos', $leida['alcance'], 'El grupo del año viejo no existe en éste: el alcance queda por elegir.');
        $this->assertSame([], $leida['destinatarios']);
        $this->assertCount(1, $leida['preguntas']);
    }
}
