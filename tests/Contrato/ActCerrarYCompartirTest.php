<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **cerrar y compartir resultados** (contrato §2.10, §3.12 y §3.13).
 *
 * - `respondieron`: ven los resultados quienes tienen hoja terminada; `todos`: todo destinatario.
 * - Sólo salen las preguntas marcadas; los textos libres van desmarcados por defecto y **un archivo
 *   no se comparte nunca**.
 * - Lo compartido es siempre anónimo (k-anonimato) y lleva la marca `mia` de lo que eligió quien
 *   mira.
 *
 * Para pasar del umbral de 5 hojas se escriben hojas terminadas directamente (las mismas filas que
 * deja `enviar`); la del alumno 0 va por la API.
 */
class ActCerrarYCompartirTest extends CasoDeActividades
{
    /** @return array{0: int, 1: array, 2: array} id, la única y la corta */
    private function encuestaConRespuestas(array $extra = []): array
    {
        $act = $this->crear($this->encuesta($extra));
        $p = $this->unica($act['id'], null);
        $corta = $this->pregunta($act['id'], ['tipo' => 'corta', 'enunciado' => '¿Algo más?']);
        $this->publicar($act['id']);

        $this->enviar($act['id'], $this->alumno(0), [$this->marcar($p, 0), ['pregunta_id' => $corta['id'], 'texto' => 'Nada']])->assertStatus(200);

        foreach ([1, 2, 3, 4, 5] as $i) {
            $hoja = $this->hojaTerminada($act['id'], $this->alumno($i));
            DB::table('ws_respuestas')->insert(['actividad_resuelta_id' => $hoja, 'pregunta_id' => $p['id'], 'tipo_pregunta' => 'unica',
                'opcion_id' => $p['opciones'][1]['id'], 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$act['id'], $p, $corta];
    }

    public function test_cerrar_compartiendo_con_quienes_respondieron(): void
    {
        [$id, $p, $corta] = $this->encuestaConRespuestas();

        $r = $this->como('titular')->postJson("/api/act/{$id}/cerrar", ['comparte_resultados' => 'respondieron', 'preguntas_compartidas' => [$p['id']]]);
        $r->assertStatus(200)->assertJsonPath('estado', 'cerrada')->assertJsonPath('comparte_resultados', 'respondieron');
        $this->assertNotNull($r->json('resultados_compartidos_at'));
        $this->assertNotNull($this->fila($id)->cerrada_at);
        $this->assertSame([true, false], array_column($r->json('preguntas'), 'compartir'));

        // El aviso `resultados`, sólo a quienes respondieron.
        $avisados = array_map(fn ($a) => (int) $a->alumno_id, $this->avisos($id, 'resultados'));
        $this->assertEqualsCanonicalizing(array_map(fn ($i) => (int) $this->alumno($i)->alumno_id, [0, 1, 2, 3, 4, 5]), $avisados);

        // Quien respondió: sus respuestas con `mia`, y lo compartido con su marca.
        $mis = $this->como($this->alumno(0))->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(200);
        $this->assertSame([$p['opciones'][0]['id']], $mis->json('respuestas.0.mia.opcion_ids'));
        $this->assertSame([$p['id']], array_column($mis->json('compartidos.preguntas'), 'pregunta_id'), 'Salió una pregunta sin marcar.');
        $this->assertSame([[1, true], [5, false], [0, false]], array_map(fn ($o) => [$o['n'], $o['mia']], $mis->json('compartidos.preguntas.0.opciones')));
        $this->assertStringNotContainsString('"user_id":'.$this->alumno(1)->user_id.',', (string) json_encode($mis->json('compartidos')));

        // Quien no respondió no los ve.
        $this->como($this->alumno(6))->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(409);

        // Con `todos`, sí.
        $this->como('titular')->postJson("/api/act/{$id}/compartir", ['comparte_resultados' => 'todos'])->assertStatus(200);
        $ajena = $this->como($this->alumno(6))->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(200);
        $this->assertSame([], $ajena->json('respuestas'));
        $this->assertSame([$p['id']], array_column($ajena->json('compartidos.preguntas'), 'pregunta_id'));
        $this->assertFalse($ajena->json('compartidos.preguntas.0.opciones.0.mia'));

        // Y dejar de compartir los retira.
        $this->como('titular')->postJson("/api/act/{$id}/compartir", ['comparte_resultados' => 'no'])->assertStatus(200)
            ->assertJsonPath('resultados_compartidos_at', null);
        $this->assertNull($this->como($this->alumno(0))->getJson("/api/act/{$id}/mis-respuestas")->json('compartidos'));
    }

    public function test_sin_elegir_preguntas_salen_las_marcadas_por_defecto(): void
    {
        [$id, $p] = $this->encuestaConRespuestas();

        $this->como('titular')->postJson("/api/act/{$id}/cerrar", ['comparte_resultados' => 'respondieron'])->assertStatus(200);

        // La corta (texto libre) nace sin compartir.
        $this->assertSame([$p['id']], array_column($this->como($this->alumno(0))->getJson("/api/act/{$id}/mis-respuestas")->json('compartidos.preguntas'), 'pregunta_id'));
    }

    public function test_un_archivo_no_se_comparte(): void
    {
        $act = $this->crear($this->encuesta());
        $p = $this->unica($act['id'], null);
        $archivo = $this->pregunta($act['id'], ['tipo' => 'archivo', 'enunciado' => 'Sube tu dibujo', 'compartir' => true]);
        $this->publicar($act['id']);
        $this->enviar($act['id'], $this->alumno(0), [$this->marcar($p, 0)])->assertStatus(200);

        $this->como('titular')->postJson("/api/act/{$act['id']}/cerrar", ['comparte_resultados' => 'todos', 'preguntas_compartidas' => [$p['id'], $archivo['id']]])
            ->assertStatus(422);
        $this->assertSame('publicada', $this->fila($act['id'])->estado, 'El 422 cerró la actividad igual.');

        // Aunque la columna diga que sí, lo compartido no la trae.
        $this->como('titular')->postJson("/api/act/{$act['id']}/cerrar", ['comparte_resultados' => 'todos'])->assertStatus(200);
        $this->assertTrue((bool) DB::table('ws_preguntas')->where('id', $archivo['id'])->value('compartir'));
        $ids = array_column($this->como($this->alumno(0))->getJson("/api/act/{$act['id']}/mis-respuestas")->json('compartidos.preguntas'), 'pregunta_id');
        $this->assertNotContains($archivo['id'], $ids);
    }

    public function test_compartir_es_despues_de_cerrar_y_solo_de_encuestas(): void
    {
        [$id] = $this->encuestaConRespuestas();
        $this->como('titular')->postJson("/api/act/{$id}/compartir", ['comparte_resultados' => 'todos'])->assertStatus(409);

        $this->como('titular')->postJson("/api/act/{$id}/cerrar")->assertStatus(200);
        $this->como('titular')->postJson("/api/act/{$id}/cerrar")->assertStatus(409);
        $this->como('titular')->postJson("/api/act/{$id}/compartir", [])->assertStatus(422);

        $cuestionario = $this->crear($this->cuestionario());
        $this->unica($cuestionario['id'], 0);
        $this->publicar($cuestionario['id']);
        $this->como('titular')->postJson("/api/act/{$cuestionario['id']}/cerrar", ['comparte_resultados' => 'todos'])->assertStatus(422);
    }

    /** Cerrada por la fecha, sin nadie que la cierre a mano: lo compartido también se ve (no hay cron). */
    public function test_cerrada_por_la_fecha_tambien_comparte(): void
    {
        [$id, $p] = $this->encuestaConRespuestas(['comparte_resultados' => 'todos']);
        DB::table('ws_actividades')->where('id', $id)->update(['cierra_at' => '2020-01-01 00:00:00']);

        $mis = $this->como($this->alumno(6))->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(200);
        $this->assertSame($p['id'], $mis->json('compartidos.preguntas.0.pregunta_id'));
    }
}
