<?php

namespace Tests\Contrato;

use App\Support\EscalaDeNotas;
use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **la nota a la planilla** (contrato §2.8, §2.9, §3.5, §3.9 y §3.11).
 *
 * Lo que se fija, todo leído de vuelta de la base y no de la respuesta:
 *
 * - Publicar con `califica` crea el indicador (`subunidades.actividad_id`) en el logro elegido, con
 *   sus notas del grupo y su bitácora. El peso de los demás indicadores sólo se toca con
 *   `reajustar_demas`.
 * - Enviar un cuestionario escribe la mejor nota, **llevada a la escala del año**, con bitácora; y
 *   **no pisa una nota editada a mano**.
 * - Calificar una tarea: 409 sin entrega, 422 fuera de `0..nota_maxima`, y la editada a mano sólo se
 *   pisa con `forzar_planilla`.
 * - `impacto` no escribe; `aplicar-cambios` con sus tres salidas, para cuestionario y tarea.
 * - En un año cerrado, 423.
 *
 * El seed califica sobre 50 con mínima 30; la escena elige un logro de la clase del titular en el
 * periodo actual.
 */
class ActPlanillaTest extends CasoDeActividades
{
    /**
     * Cuestionario calificable publicado con dos únicas (la correcta, la A).
     *
     * @return array{0: int, 1: array, 2: array, 3: int} id, las dos preguntas y la subunidad
     */
    private function cuestionarioConNota(array $extra = [], array $publicar = []): array
    {
        $act = $this->crear($this->cuestionario(array_replace(['califica' => true, 'unidad_id' => $this->escena()->unidad_id, 'peso' => 20], $extra)));
        $q1 = $this->unica($act['id'], 0);
        $q2 = $this->unica($act['id'], 0);
        $r = $this->publicar($act['id'], $publicar);

        $this->assertNotNull($r['subunidad_id'], 'Publicar con nota no creó el indicador.');

        return [$act['id'], $q1, $q2, (int) $r['subunidad_id']];
    }

    /** @return array{0: int, 1: int} id y subunidad */
    private function tareaConNota(array $extra = []): array
    {
        $act = $this->crear($this->tarea(array_replace(['califica' => true, 'unidad_id' => $this->escena()->unidad_id, 'peso' => 20], $extra)));
        $r = $this->publicar($act['id']);

        return [$act['id'], (int) $r['subunidad_id']];
    }

    private function notaEnPlanilla(int $subunidadId, object $alumno): ?int
    {
        $n = DB::table('notas')->where('subunidad_id', $subunidadId)->where('alumno_id', $alumno->alumno_id)->whereNull('deleted_at')->value('nota');

        return $n === null ? null : (int) $n;
    }

    private function editarAMano(int $subunidadId, object $alumno, int $nota): void
    {
        DB::table('notas')->where('subunidad_id', $subunidadId)->where('alumno_id', $alumno->alumno_id)->update(['nota' => $nota]);
    }

    private function anioCerrado(): int
    {
        $actual = (int) DB::table('years')->where('id', $this->escena()->year_id)->value('year');

        return (int) DB::table('years')->where('year', '<', $actual)->whereNull('deleted_at')->orderByDesc('year')->value('id');
    }

    // ------------------------------------------------------------------ publicar

    public function test_publicar_con_nota_crea_el_indicador_con_sus_notas_y_bitacora(): void
    {
        [$id, , , $sub] = $this->cuestionarioConNota(['titulo' => 'Quiz de fracciones']);

        $s = DB::table('subunidades')->where('id', $sub)->first();
        $this->assertSame($id, (int) $s->actividad_id);
        $this->assertSame($this->escena()->unidad_id, (int) $s->unidad_id);
        $this->assertSame('Quiz de fracciones', $s->definicion);
        $this->assertSame(20, (int) $s->porcentaje);

        $this->assertSame(1, DB::table('notas')->where('subunidad_id', $sub)->where('alumno_id', $this->alumno()->alumno_id)->count(),
            'El indicador nació sin la casilla del alumno.');
        $this->assertGreaterThanOrEqual(count($this->escena()->alumnos), DB::table('notas')->where('subunidad_id', $sub)->count());

        // Desde el 30 sep 2026 el rastro va sólo a `auditoria` (contrato 5).
        $this->assertSame(1, DB::table('auditoria')->where('accion', 'crear')->where('entidad', 'subunidad')->where('entidad_id', $sub)->count());
        $this->assertSame($sub, $this->como('titular')->getJson("/api/act/{$id}")->json('subunidad_id'));
    }

    private function pesosDeLaUnidad(?int $sin = null): array
    {
        return DB::table('subunidades')->where('unidad_id', $this->escena()->unidad_id)->whereNull('deleted_at')
            ->when($sin, fn ($q) => $q->where('id', '<>', $sin))->orderBy('id')->pluck('porcentaje', 'id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_sin_el_boton_los_demas_pesos_no_se_tocan(): void
    {
        $antes = $this->pesosDeLaUnidad();
        $this->assertNotEmpty($antes, 'El logro de la escena no tiene indicadores que reajustar.');

        [, , , $sub] = $this->cuestionarioConNota();

        $this->assertSame($antes, $this->pesosDeLaUnidad($sub), 'Sin reajustar_demas se cambiaron los pesos de los demás.');
    }

    public function test_con_el_boton_los_demas_suman_lo_que_queda(): void
    {
        $antes = $this->pesosDeLaUnidad();
        $suma = array_sum($antes);

        [, , , $sub] = $this->cuestionarioConNota(['peso' => 30], ['reajustar_demas' => true]);
        $despues = $this->pesosDeLaUnidad($sub);

        $this->assertSame(array_keys($antes), array_keys($despues));
        $this->assertSame(70, array_sum($despues), 'Los demás no suman 100 − peso.');

        foreach ($despues as $id => $nuevo) {
            $this->assertEqualsWithDelta($antes[$id] * 70 / $suma, $nuevo, 1, "El indicador {$id} no se escaló en proporción.");
        }

        $cambiados = array_keys(array_filter($despues, fn ($nuevo, $id) => $nuevo !== $antes[$id], ARRAY_FILTER_USE_BOTH));
        $this->assertNotEmpty($cambiados);
        $this->assertSame(count($cambiados), DB::table('auditoria')->where('entidad', 'subunidad')->whereIn('entidad_id', $cambiados)->count(),
            'Cada peso cambiado lleva su línea de auditoría.');
    }

    public function test_en_promedio_no_se_pide_peso(): void
    {
        DB::table('years')->where('id', $this->escena()->year_id)->update(['reparto_subunidades' => 'promedio']);

        $act = $this->crear($this->cuestionario(['califica' => true, 'unidad_id' => $this->escena()->unidad_id, 'peso' => null]));
        $this->unica($act['id'], 0);
        $r = $this->publicar($act['id']);

        $this->assertSame(0, (int) DB::table('subunidades')->where('id', $r['subunidad_id'])->value('porcentaje'));
    }

    public function test_un_logro_de_otra_clase_no_vale(): void
    {
        $ajena = DB::table('unidades')->where('asignatura_id', '<>', $this->escena()->clase)->whereNull('alumno_id')->whereNull('deleted_at')->value('id');

        $this->como('titular')->postJson('/api/act/crear', $this->cuestionario(['califica' => true, 'unidad_id' => $ajena]))->assertStatus(422);
    }

    // ------------------------------------------------------------------ enviar un cuestionario

    public function test_enviar_escribe_la_nota_con_bitacora(): void
    {
        [$id, $q1, $q2, $sub] = $this->cuestionarioConNota();

        $this->enviar($id, $this->alumno(), [$this->marcar($q1, 0), $this->marcar($q2, 1)])->assertStatus(200)->assertJsonPath('nota', 25);

        $this->assertSame(25, $this->notaEnPlanilla($sub, $this->alumno()));

        $notaId = DB::table('notas')->where('subunidad_id', $sub)->where('alumno_id', $this->alumno()->alumno_id)->value('id');
        // Desde el 30 sep 2026 el rastro va sólo a `auditoria` (contrato 5).
        $bitacora = DB::table('auditoria')->where('entidad', 'nota')->where('entidad_id', $notaId)->first();
        $this->assertNotNull($bitacora, 'La nota se escribió sin línea de auditoría.');
        $this->assertSame(25, (int) $bitacora->valor_nuevo_num);
        $this->assertNull($bitacora->valor_anterior_num);
        $this->assertSame((int) $this->alumno()->alumno_id, (int) $bitacora->alumno_id);
    }

    public function test_con_varios_intentos_la_planilla_lleva_la_mejor(): void
    {
        [$id, $q1, $q2, $sub] = $this->cuestionarioConNota(['oportunidades' => 3]);
        $yo = $this->alumno();

        $this->enviar($id, $yo, [$this->marcar($q1, 0), $this->marcar($q2, 1)])->assertStatus(200);
        $this->assertSame(25, $this->notaEnPlanilla($sub, $yo));

        $this->enviar($id, $yo, [$this->marcar($q1, 0), $this->marcar($q2, 0)])->assertStatus(200);
        $this->assertSame(50, $this->notaEnPlanilla($sub, $yo));

        $this->enviar($id, $yo, [$this->marcar($q1, 1), $this->marcar($q2, 1)])->assertStatus(200);
        $this->assertSame(50, $this->notaEnPlanilla($sub, $yo), 'Un intento peor bajó la nota de la planilla.');
    }

    public function test_enviar_no_pisa_una_nota_editada_a_mano(): void
    {
        [$id, $q1, $q2, $sub] = $this->cuestionarioConNota(['oportunidades' => 2]);
        $yo = $this->alumno();

        $this->enviar($id, $yo, [$this->marcar($q1, 0), $this->marcar($q2, 1)])->assertStatus(200);
        $this->editarAMano($sub, $yo, 44);

        $this->enviar($id, $yo, [$this->marcar($q1, 0), $this->marcar($q2, 0)])->assertStatus(200)->assertJsonPath('nota', 50);

        $this->assertSame(44, $this->notaEnPlanilla($sub, $yo), 'El segundo envío pisó la nota que el docente cambió a mano.');
    }

    /** `nota_maxima` 50 en un año que califica sobre 100: 25 de 50 son 50 en la planilla. */
    public function test_la_nota_se_lleva_a_la_escala_del_anio(): void
    {
        $tope = DB::table('escalas_de_valoracion')->where('year_id', $this->escena()->year_id)->whereNull('deleted_at')->orderByDesc('porc_final')->value('id');
        DB::table('escalas_de_valoracion')->where('id', $tope)->update(['porc_final' => 100]);
        EscalaDeNotas::olvidar();

        [$id, $q1, $q2, $sub] = $this->cuestionarioConNota(['nota_maxima' => 50]);
        $yo = $this->alumno();

        $this->enviar($id, $yo, [$this->marcar($q1, 0), $this->marcar($q2, 1)])->assertStatus(200)->assertJsonPath('nota', 25);

        $this->assertSame(50, $this->notaEnPlanilla($sub, $yo));

        $mis = $this->como($yo)->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(200);
        $this->assertSame(25, $mis->json('nota'));
        $this->assertSame(50, $mis->json('nota_maxima'));
        $this->assertSame(50, $mis->json('nota_planilla'));
    }

    // ------------------------------------------------------------------ calificar una tarea

    public function test_calificar_una_tarea(): void
    {
        [$id, $sub] = $this->tareaConNota();
        $yo = $this->alumno();

        $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => 40])->assertStatus(409);

        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['texto' => 'Mi ensayo'])->assertStatus(200);

        foreach ([51, -1, 3.5, 'mucho'] as $mala) {
            $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => $mala])->assertStatus(422);
        }

        $r = $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => 40, 'comentario' => 'Bien argumentado.']);
        $r->assertStatus(200)->assertJson(['planilla' => 'escrita', 'nota_planilla' => 40])->assertJsonPath('entrega.nota', 40);
        $this->assertSame(40, $this->notaEnPlanilla($sub, $yo));
        $this->assertCount(1, $this->avisos($id, 'calificada'));

        // Editada a mano: recalificar no la pisa…
        $this->editarAMano($sub, $yo, 20);
        $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => 45])
            ->assertStatus(200)->assertJson(['planilla' => 'no_tocada', 'nota_planilla' => 20]);
        $this->assertSame(20, $this->notaEnPlanilla($sub, $yo));
        $this->assertSame('Bien argumentado.', DB::table('ws_entregas')->where('actividad_id', $id)->value('comentario'), 'Recalificar sin comentario borró el que había.');

        // …salvo que se fuerce.
        $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => 46, 'forzar_planilla' => true])
            ->assertStatus(200)->assertJson(['planilla' => 'escrita', 'nota_planilla' => 46]);
        $this->assertSame(46, $this->notaEnPlanilla($sub, $yo));
        $this->assertCount(2, $this->avisos($id, 'nota_cambiada'));

        // Las entregas del docente lo reflejan.
        $fila = collect($this->como('titular')->getJson("/api/act/{$id}/entregas")->assertStatus(200)->json('filas'))
            ->firstWhere('alumno.alumno_id', $yo->alumno_id);
        $this->assertSame('calificada', $fila['estado']);
        $this->assertSame(46, $fila['nota_planilla']);
        $this->assertFalse($fila['editada_a_mano']);
    }

    public function test_una_tarea_sin_nota_a_la_planilla_no_toca_la_planilla(): void
    {
        $act = $this->crear($this->tarea());
        $this->publicar($act['id']);
        $this->como($this->alumno())->postJson("/api/act/{$act['id']}/entregar", ['texto' => 'Hecho'])->assertStatus(200);

        $this->como('titular')->postJson("/api/act/{$act['id']}/entregas/{$this->alumno()->alumno_id}/calificar", ['nota' => 30])
            ->assertStatus(200)->assertJson(['planilla' => 'sin_nota', 'nota_planilla' => null]);
    }

    // ------------------------------------------------------------------ impacto y aplicar-cambios

    /**
     * Cuestionario con dos respuestas: el alumno 0 acierta las dos (50), el 1 sólo la primera (25).
     * El cambio de todos estos tests: la correcta de la segunda pasa a ser la B.
     *
     * @return array{0: int, 1: array, 2: int, 3: array} id, la segunda pregunta, subunidad y los cambios
     */
    private function cuestionarioRespondido(): array
    {
        [$id, $q1, $q2, $sub] = $this->cuestionarioConNota();
        $this->enviar($id, $this->alumno(0), [$this->marcar($q1, 0), $this->marcar($q2, 0)])->assertStatus(200);
        $this->enviar($id, $this->alumno(1), [$this->marcar($q1, 0), $this->marcar($q2, 1)])->assertStatus(200);

        return [$id, $q2, $sub, ['preguntas' => [['id' => $q2['id'], 'correctas' => [$q2['opciones'][1]['id']]]]]];
    }

    public function test_impacto_dice_quien_cambia_y_quien_cruza_la_minima_sin_escribir(): void
    {
        [$id, $q2, $sub, $cambios] = $this->cuestionarioRespondido();
        $hojasAntes = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->orderBy('id')->pluck('nota_calculada')->all();

        $r = $this->como('titular')->postJson("/api/act/{$id}/impacto", ['cambios' => $cambios])->assertStatus(200);

        $filas = collect($r->json('filas'))->keyBy('alumno.alumno_id');
        $this->assertSame([50, 25, 'baja'], [$filas[$this->alumno(0)->alumno_id]['antes'], $filas[$this->alumno(0)->alumno_id]['despues'], $filas[$this->alumno(0)->alumno_id]['cruza']]);
        $this->assertSame([25, 50, 'sube'], [$filas[$this->alumno(1)->alumno_id]['antes'], $filas[$this->alumno(1)->alumno_id]['despues'], $filas[$this->alumno(1)->alumno_id]['cruza']]);
        $this->assertSame(2, $r->json('cambian'));
        $this->assertSame(2, $r->json('cruzan_minima'));
        $this->assertSame(0, $r->json('editadas_a_mano'));

        $this->assertSame($hojasAntes, DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->orderBy('id')->pluck('nota_calculada')->all());
        $this->assertTrue((bool) DB::table('ws_opciones')->where('id', $q2['opciones'][0]['id'])->value('is_correct'), 'impacto cambió la correcta.');
        $this->assertSame(50, $this->notaEnPlanilla($sub, $this->alumno(0)));
    }

    public function test_aplicar_todas(): void
    {
        [$id, $q2, $sub, $cambios] = $this->cuestionarioRespondido();

        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => $cambios, 'aplicar' => 'todas', 'avisar' => true])
            ->assertStatus(200)->assertJson(['actualizadas' => 2, 'no_tocadas' => 0]);

        $this->assertSame([25, 50], [$this->notaEnPlanilla($sub, $this->alumno(0)), $this->notaEnPlanilla($sub, $this->alumno(1))]);
        $this->assertFalse((bool) DB::table('ws_opciones')->where('id', $q2['opciones'][0]['id'])->value('is_correct'));
        $this->assertTrue((bool) DB::table('ws_opciones')->where('id', $q2['opciones'][1]['id'])->value('is_correct'));
        $this->assertSame(25, (int) DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->where('user_id', $this->alumno(0)->user_id)->value('nota_calculada'));
        $this->assertCount(2, $this->avisos($id, 'nota_cambiada'));
    }

    public function test_aplicar_salvo_editadas_respeta_la_editada_a_mano(): void
    {
        [$id, , $sub, $cambios] = $this->cuestionarioRespondido();
        $this->editarAMano($sub, $this->alumno(1), 33);

        $impacto = $this->como('titular')->postJson("/api/act/{$id}/impacto", ['cambios' => $cambios])->json();
        $this->assertSame(1, $impacto['editadas_a_mano']);

        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => $cambios, 'aplicar' => 'salvo_editadas', 'avisar' => false])
            ->assertStatus(200)->assertJson(['actualizadas' => 1, 'no_tocadas' => 1]);

        $this->assertSame([25, 33], [$this->notaEnPlanilla($sub, $this->alumno(0)), $this->notaEnPlanilla($sub, $this->alumno(1))]);
        $this->assertCount(0, $this->avisos($id, 'nota_cambiada'), 'avisar: false avisó.');
    }

    public function test_aplicar_ninguna_cambia_la_actividad_pero_no_la_planilla(): void
    {
        [$id, $q2, $sub, $cambios] = $this->cuestionarioRespondido();

        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => $cambios, 'aplicar' => 'ninguna'])
            ->assertStatus(200)->assertJson(['actualizadas' => 0, 'no_tocadas' => 2]);

        $this->assertSame([50, 25], [$this->notaEnPlanilla($sub, $this->alumno(0)), $this->notaEnPlanilla($sub, $this->alumno(1))]);
        $this->assertTrue((bool) DB::table('ws_opciones')->where('id', $q2['opciones'][1]['id'])->value('is_correct'));
        $this->assertSame(25, (int) DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->where('user_id', $this->alumno(0)->user_id)->value('nota_calculada'));
    }

    /** En la tarea la nota del docente se queda; lo que se mueve es su conversión a la planilla. */
    public function test_la_nota_maxima_de_una_tarea_calificada(): void
    {
        [$id, $sub] = $this->tareaConNota();
        $yo = $this->alumno();
        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['texto' => 'Hecho'])->assertStatus(200);
        $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => 20])->assertStatus(200);

        // Con una entrega calificada, `guardar` no la cambia: 409 con el campo.
        $this->como('titular')->postJson("/api/act/{$id}/guardar", ['nota_maxima' => 25])->assertStatus(409)
            ->assertJsonPath('campos', ['nota_maxima']);

        $r = $this->como('titular')->postJson("/api/act/{$id}/impacto", ['cambios' => ['nota_maxima' => 25]])->assertStatus(200);
        $this->assertSame(['antes' => 20, 'despues' => 20, 'antes_planilla' => 20, 'despues_planilla' => 40, 'cruza' => 'sube'],
            array_intersect_key($r->json('filas.0'), array_flip(['antes', 'despues', 'antes_planilla', 'despues_planilla', 'cruza'])));

        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => ['nota_maxima' => 10], 'aplicar' => 'todas'])->assertStatus(422);
        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => ['nota_maxima' => 25, 'preguntas' => [['id' => 1]]], 'aplicar' => 'todas'])
            ->assertStatus(422);

        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => ['nota_maxima' => 25], 'aplicar' => 'todas'])
            ->assertStatus(200)->assertJson(['actualizadas' => 1]);

        $this->assertSame(25, (int) $this->fila($id)->nota_maxima);
        $this->assertSame(20, (int) DB::table('ws_entregas')->where('actividad_id', $id)->value('nota'));
        $this->assertSame(40, $this->notaEnPlanilla($sub, $yo));
    }

    /** Sin ninguna entrega calificada, la nota máxima de la tarea se cambia por `guardar`. */
    public function test_la_nota_maxima_de_una_tarea_sin_calificar_se_guarda(): void
    {
        [$id] = $this->tareaConNota();
        $this->como($this->alumno())->postJson("/api/act/{$id}/entregar", ['texto' => 'Hecho'])->assertStatus(200);

        $this->como('titular')->postJson("/api/act/{$id}/guardar", ['nota_maxima' => 25])->assertStatus(200)->assertJsonPath('nota_maxima', 25);
    }

    public function test_una_encuesta_no_edita_con_notas(): void
    {
        [$id] = $this->encuestaPublicada();

        $this->como('titular')->postJson("/api/act/{$id}/impacto", ['cambios' => []])->assertStatus(422);
    }

    // ------------------------------------------------------------------ año cerrado

    public function test_en_un_anio_cerrado_es_423(): void
    {
        $cerrado = $this->anioCerrado();

        // Publicar con nota.
        $act = $this->crear($this->tarea(['califica' => true, 'unidad_id' => $this->escena()->unidad_id, 'peso' => 20]));
        DB::table('ws_actividades')->where('id', $act['id'])->update(['year_id' => $cerrado]);
        $this->como('titular')->postJson("/api/act/{$act['id']}/publicar")->assertStatus(423);

        // Calificar y aplicar cambios.
        [$id] = $this->tareaConNota(['titulo' => 'Del año pasado']);
        $this->como($this->alumno())->postJson("/api/act/{$id}/entregar", ['texto' => 'Hecho'])->assertStatus(200);
        DB::table('ws_actividades')->where('id', $id)->update(['year_id' => $cerrado]);

        $this->como('titular')->postJson("/api/act/{$id}/entregas/{$this->alumno()->alumno_id}/calificar", ['nota' => 30])->assertStatus(423);
        $this->como('titular')->postJson("/api/act/{$id}/aplicar-cambios", ['cambios' => ['nota_maxima' => 40], 'aplicar' => 'todas'])->assertStatus(423);
        $this->assertNull(DB::table('ws_entregas')->where('actividad_id', $id)->value('nota'));
    }
}
