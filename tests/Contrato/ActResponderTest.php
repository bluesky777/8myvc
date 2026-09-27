<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **responder** (contrato §2.5, §2.6, §3.7, §3.8 y §3.13).
 *
 * Lo que sólo el servidor puede garantizar:
 *
 * - **El recorrido se evalúa otra vez al enviar**: la respuesta a una pregunta que quedó oculta se
 *   descarta, y una obligatoria visible sin responder es 422 con la lista. El front pinta lo mismo,
 *   pero quien manda es el servidor.
 * - **La doble respuesta se corta en los tres niveles de anonimato**, `total` incluido: el anonimato
 *   es de presentación y la base sabe quién respondió.
 * - **El borrador vive en el servidor** y se convierte en la hoja enviada, sin dejar dos.
 * - **Reentregar una tarea reescribe la misma hoja**: la tarea no gasta intentos.
 */
class ActResponderTest extends CasoDeActividades
{
    /**
     * Encuesta publicada: 1 Sí/No; 2 corta obligatoria si 1 es «Sí»; 3 párrafo suelto; 4 párrafo si 1
     * «no es Sí».
     *
     * @return array{0: int, 1: array, 2: array, 3: array, 4: array}
     */
    private function conCondicion(array $extra = []): array
    {
        $act = $this->crear($this->encuesta($extra));
        $id = $act['id'];
        $p1 = $this->pregunta($id, ['tipo' => 'sino', 'enunciado' => '¿Tienes mascota?']);
        $p2 = $this->pregunta($id, ['tipo' => 'corta', 'enunciado' => '¿Cuál?', 'obligatoria' => true]);
        $p3 = $this->pregunta($id, ['tipo' => 'parrafo', 'enunciado' => 'Comentarios']);
        $p4 = $this->pregunta($id, ['tipo' => 'parrafo', 'enunciado' => '¿Por qué no?']);

        $this->como('titular')->postJson("/api/act/preguntas/{$p2['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p1['id'], 'operador' => 'es', 'opcion_id' => $p1['opciones'][0]['id']]]]])->assertStatus(200);
        $this->como('titular')->postJson("/api/act/preguntas/{$p4['id']}/condiciones", ['grupos' => [[
            ['depende_de_id' => $p1['id'], 'operador' => 'no_es', 'opcion_id' => $p1['opciones'][0]['id']]]]])->assertStatus(200);

        $this->publicar($id);

        return [$id, $p1, $p2, $p3, $p4];
    }

    public function test_el_recorrido_se_evalua_en_el_servidor(): void
    {
        [$id, $p1, $p2, $p3, $p4] = $this->conCondicion();

        $recorrido = fn (array $respuestas) => $this->como($this->alumno())->postJson("/api/act/{$id}/recorrido", ['respuestas' => $respuestas])
            ->assertStatus(200)->json('visibles');

        $this->assertSame([$p1['id'], $p2['id'], $p3['id']], $recorrido([$this->marcar($p1, 0)]));
        $this->assertSame([$p1['id'], $p3['id'], $p4['id']], $recorrido([$this->marcar($p1, 1)]));
        // Sin responder la 1, ni `es` ni `no_es` se cumplen (§2.5).
        $this->assertSame([$p1['id'], $p3['id']], $recorrido([]));

        // `recorrido` no escribe.
        $this->assertSame(0, DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->count());
    }

    public function test_la_respuesta_a_una_pregunta_oculta_se_descarta(): void
    {
        [$id, $p1, $p2, $p3] = $this->conCondicion();

        $this->enviar($id, $this->alumno(), [
            $this->marcar($p1, 1),
            ['pregunta_id' => $p2['id'], 'texto' => 'Un secreto que no debía llegar'],
            ['pregunta_id' => $p3['id'], 'texto' => 'Todo bien'],
        ])->assertStatus(200);

        $hoja = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->first();
        $guardadas = DB::table('ws_respuestas')->where('actividad_resuelta_id', $hoja->id)->pluck('pregunta_id')->map(fn ($v) => (int) $v)->all();

        $this->assertEqualsCanonicalizing([$p1['id'], $p3['id']], $guardadas);
        $this->assertSame(0, DB::table('ws_respuestas')->where('texto', 'Un secreto que no debía llegar')->count());
    }

    public function test_una_obligatoria_visible_sin_responder_es_422(): void
    {
        [$id, $p1, $p2] = $this->conCondicion();

        $r = $this->enviar($id, $this->alumno(), [$this->marcar($p1, 0)]);

        $r->assertStatus(422);
        $this->assertSame([$p2['id']], $r->json('faltan'));
        $this->assertSame(0, DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->where('terminado', 1)->count());

        // Oculta, la misma obligatoria no hace falta.
        $this->enviar($id, $this->alumno(), [$this->marcar($p1, 1)])->assertStatus(200);
    }

    public function test_el_doble_envio_se_corta_en_los_tres_niveles(): void
    {
        foreach (['nombre', 'seguimiento', 'total'] as $nivel) {
            [$id, $p] = $this->encuestaPublicada(['anonimato' => $nivel, 'titulo' => $nivel]);

            $this->enviar($id, $this->alumno(), [$this->marcar($p, 0)])->assertStatus(200);
            $this->enviar($id, $this->alumno(), [$this->marcar($p, 1)])->assertStatus(409);

            $hojas = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->get();
            $this->assertCount(1, $hojas, "En {$nivel} quedaron dos hojas.");
            $this->assertSame((int) $this->alumno()->user_id, (int) $hojas[0]->user_id, "En {$nivel} la hoja no guarda quién (§2.6: el anonimato es de presentación).");
            $this->assertNotNull($hojas[0]->enviada_at);
        }
    }

    public function test_el_borrador_se_guarda_y_se_convierte_en_la_hoja_enviada(): void
    {
        foreach (['nombre', 'total'] as $nivel) {
            [$id, $p] = $this->encuestaPublicada(['anonimato' => $nivel, 'titulo' => $nivel]);

            $this->como($this->alumno())->postJson("/api/act/{$id}/borrador", ['respuestas' => [$this->marcar($p, 2)]])
                ->assertStatus(200)->assertJsonStructure(['guardado_at']);

            $borrador = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->first();
            $this->assertSame(0, (int) $borrador->terminado);
            $this->assertNotNull($borrador->iniciada_at);

            $r = $this->como($this->alumno())->getJson("/api/act/{$id}/responder")->assertStatus(200);
            $this->assertSame([$p['opciones'][2]['id']], $r->json('borrador.0.opcion_ids'), "En {$nivel} no volvió el borrador.");

            $fila = collect($this->como($this->alumno())->getJson('/api/act/bandeja?vista=responder')->json())->firstWhere('id', $id);
            $this->assertSame('borrador', $fila['mi_estado']);

            $this->enviar($id, $this->alumno(), [$this->marcar($p, 1)])->assertStatus(200);

            $hojas = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->get();
            $this->assertCount(1, $hojas, 'El envío dejó el borrador al lado de la hoja.');
            $this->assertSame($borrador->id, $hojas[0]->id);
            $this->assertSame(1, (int) $hojas[0]->terminado);
            $this->assertSame([(int) $p['opciones'][1]['id']], DB::table('ws_respuestas')->where('actividad_resuelta_id', $borrador->id)
                ->pluck('opcion_id')->map(fn ($v) => (int) $v)->all());
        }
    }

    public function test_fuera_de_abierta_no_se_responde(): void
    {
        $programada = $this->crear($this->encuesta(['publica_at' => '2099-01-01 08:00']));
        $p = $this->unica($programada['id'], null);
        $this->publicar($programada['id']);

        $this->como($this->alumno())->getJson("/api/act/{$programada['id']}/responder")->assertStatus(409);
        $this->enviar($programada['id'], $this->alumno(), [$this->marcar($p, 0)])->assertStatus(409);

        [$cerrada, $q] = $this->encuestaPublicada(['titulo' => 'Cerrada']);
        DB::table('ws_actividades')->where('id', $cerrada)->update(['cierra_at' => '2020-01-01 00:00:00']);
        $this->enviar($cerrada, $this->alumno(), [$this->marcar($q, 0)])->assertStatus(409);
        $this->como($this->alumno())->postJson("/api/act/{$cerrada}/borrador", ['respuestas' => []])->assertStatus(409);
    }

    /** Quien responde no ve la correcta, la explicación ni el error típico. */
    public function test_responder_no_trae_secretos(): void
    {
        $act = $this->crear($this->cuestionario());
        $this->pregunta($act['id'], ['tipo' => 'unica', 'enunciado' => '2 + 2', 'explicacion' => 'Porque sí.', 'opciones' => [
            ['definicion' => '4', 'is_correct' => true], ['definicion' => '22', 'error_tipico' => 'Pegó las cifras.']]]);
        $this->publicar($act['id']);

        $r = $this->como($this->alumno())->getJson("/api/act/{$act['id']}/responder")->assertStatus(200);
        $pregunta = $r->json('preguntas.0');

        $this->assertArrayNotHasKey('explicacion', $pregunta);
        $this->assertArrayNotHasKey('is_correct', $pregunta['opciones'][0]);
        $this->assertArrayNotHasKey('error_tipico', $pregunta['opciones'][1]);
        $this->assertStringNotContainsString('Pegó las cifras', $r->getContent());
    }

    public function test_respuestas_que_no_encajan_son_422(): void
    {
        $act = $this->crear($this->encuesta());
        $p = $this->unica($act['id'], null);
        $escala = $this->pregunta($act['id'], ['tipo' => 'escala', 'enunciado' => '¿Cuánto?']);
        $otra = $this->crear($this->encuesta(['titulo' => 'Otra']));
        $ajena = $this->unica($otra['id'], null);
        $this->publicar($act['id']);

        $malas = [
            'una opción de otra pregunta' => [['pregunta_id' => $p['id'], 'opcion_ids' => [$ajena['opciones'][0]['id']]]],
            'dos opciones en una única' => [$this->marcar($p, 0, 1)],
            'una escala de 6' => [['pregunta_id' => $escala['id'], 'valor' => 6]],
            'una pregunta de otra actividad' => [$this->marcar($ajena, 0)],
        ];

        foreach ($malas as $que => $respuestas) {
            $this->assertSame(422, $this->enviar($act['id'], $this->alumno(), $respuestas)->status(), "Aceptó {$que}.");
        }

        $this->assertSame(0, DB::table('ws_actividades_resueltas')->where('actividad_id', $act['id'])->count());
    }

    public function test_una_tarea_se_entrega_no_se_envia(): void
    {
        $act = $this->crear($this->tarea());
        $this->publicar($act['id']);

        $this->enviar($act['id'], $this->alumno(), [])->assertStatus(422);
    }

    /**
     * Reentregar una tarea con preguntas parte de lo entregado y reescribe la misma hoja; un borrador
     * guardado entre medias se retira. Calificada, ya no se reentrega.
     */
    public function test_reentregar_una_tarea_reescribe_la_misma_hoja(): void
    {
        $act = $this->crear($this->tarea());
        $q = $this->pregunta($act['id'], ['tipo' => 'corta', 'enunciado' => '¿Qué aprendiste?']);
        $this->publicar($act['id']);
        $id = $act['id'];
        $yo = $this->alumno();

        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['texto' => 'Versión 1', 'respuestas' => [
            ['pregunta_id' => $q['id'], 'texto' => 'uno']]])->assertStatus(200)->assertJsonPath('texto', 'Versión 1');
        $hoja = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->where('terminado', 1)->first();

        // Al volver a abrirla, lo entregado viene como borrador para editarlo.
        $r = $this->como($yo)->getJson("/api/act/{$id}/responder")->assertStatus(200);
        $this->assertSame('uno', $r->json('borrador.0.texto'));
        $this->assertSame('Versión 1', $r->json('mi_entrega.texto'));

        // Un borrador entre medias (otra hoja, sin terminar)…
        $this->como($yo)->postJson("/api/act/{$id}/borrador", ['respuestas' => [['pregunta_id' => $q['id'], 'texto' => 'a medias']]])->assertStatus(200);

        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['texto' => 'Versión 2', 'respuestas' => [
            ['pregunta_id' => $q['id'], 'texto' => 'dos']]])->assertStatus(200);

        $vivas = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->whereNull('deleted_at')->get();
        $this->assertCount(1, $vivas, 'Reentregar dejó otra hoja viva (o el borrador).');
        $this->assertSame($hoja->id, $vivas[0]->id);
        $this->assertSame(['dos'], DB::table('ws_respuestas')->where('actividad_resuelta_id', $hoja->id)->pluck('texto')->all());
        $this->assertSame(1, DB::table('ws_entregas')->where('actividad_id', $id)->count());
        $this->assertSame('Versión 2', DB::table('ws_entregas')->where('actividad_id', $id)->value('texto'));

        $this->como('titular')->postJson("/api/act/{$id}/entregas/{$yo->alumno_id}/calificar", ['nota' => 40])->assertStatus(200);
        $this->como($yo)->postJson("/api/act/{$id}/entregar", ['texto' => 'Versión 3'])->assertStatus(409);
    }

    /** La entrega tardía: vencida por la fecha y con `recibir_tarde`, entra marcada. */
    public function test_la_entrega_tardia(): void
    {
        $act = $this->crear($this->tarea(['recibir_tarde' => true]));
        $this->publicar($act['id']);
        $sinTarde = $this->crear($this->tarea(['titulo' => 'Sin tarde']));
        $this->publicar($sinTarde['id']);
        DB::table('ws_actividades')->whereIn('id', [$act['id'], $sinTarde['id']])->update(['cierra_at' => '2020-01-01 00:00:00']);

        $this->como($this->alumno())->postJson("/api/act/{$act['id']}/entregar", ['texto' => 'Tarde'])->assertStatus(200)
            ->assertJsonPath('tarde', true);
        $this->como($this->alumno())->postJson("/api/act/{$sinTarde['id']}/entregar", ['texto' => 'Tarde'])->assertStatus(409);
    }
}
