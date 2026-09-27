<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **el anonimato es de presentación** (contrato §2.6, decidido por Joseth el
 * 26 sep).
 *
 * La base guarda siempre quién respondió y cuándo, en los tres niveles. Lo anónimo es lo que la API
 * le enseña al creador y a los directivos: en `seguimiento` y `total`, **ningún** endpoint suyo
 * devuelve la identidad de quien respondió (`user_id`, `alumno_id`, nombre, hora de envío). Y:
 *
 * - «faltan» no existe en `total` (409): saber quién falta es saber quién respondió.
 * - k-anonimato: una celda con menos de 5 hojas va `oculto`, sin cifras; con menos de 5 hojas en
 *   toda la encuesta tampoco salen textos libres.
 * - el nivel sólo sube: bajarlo es 422, para no defraudar a quien respondió creyendo que no se le
 *   vería.
 *
 * El instrumento se comprueba a sí mismo: el mismo barrido sobre una encuesta **con nombre** tiene
 * que encontrar la identidad. Si no la encontrara, el verde de los anónimos no probaría nada.
 */
class ActAnonimatoTest extends CasoDeActividades
{
    /**
     * Encuesta publicada con una única y una corta (compartida), respondida por el alumno 0 por la
     * API con un texto reconocible.
     *
     * @return array{0: int, 1: array, 2: array}
     */
    private function respondida(string $anonimato): array
    {
        $act = $this->crear($this->encuesta(['anonimato' => $anonimato, 'titulo' => 'Clima '.$anonimato]));
        $p = $this->unica($act['id'], null);
        $corta = $this->pregunta($act['id'], ['tipo' => 'corta', 'enunciado' => '¿Qué cambiarías?', 'compartir' => true]);
        $this->publicar($act['id']);

        $this->enviar($act['id'], $this->alumno(0), [$this->marcar($p, 0), ['pregunta_id' => $corta['id'], 'texto' => 'Zanahorias en el menú']])
            ->assertStatus(200);

        return [$act['id'], $p, $corta];
    }

    /** Hojas terminadas de más, con la opción `$i` marcada, para pasar del umbral de 5. */
    private function masHojas(int $actId, array $p, array $alumnos, int $i = 1): void
    {
        foreach ($alumnos as $a) {
            $hoja = $this->hojaTerminada($actId, $a);
            DB::table('ws_respuestas')->insert(['actividad_resuelta_id' => $hoja, 'pregunta_id' => $p['id'], 'tipo_pregunta' => 'unica',
                'opcion_id' => $p['opciones'][$i]['id'], 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Todo lo que el creador y un directivo pueden pedir de una actividad, en texto. */
    private function loQueVenElCreadorYElDirectivo(int $id, bool $conFaltan): string
    {
        $rutas = ["/api/act/{$id}", "/api/act/{$id}/resultados", "/api/act/{$id}/responder?previa=1", '/api/act/avisos'];

        if ($conFaltan) {
            $rutas[] = "/api/act/{$id}/faltan";
        }

        $todo = '';

        foreach (['titular', 'directivo'] as $quien) {
            foreach ($rutas as $ruta) {
                $r = $this->como($quien)->getJson($ruta);
                $this->assertSame(200, $r->status(), "{$ruta} como {$quien}.");
                $todo .= json_encode($r->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $todo .= json_encode($this->como('titular')->getJson('/api/act/bandeja?vista=mias')->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $todo;
    }

    /**
     * Las huellas de quién respondió, como expresiones: sus ids como valor de su clave y su hora de
     * envío. **El nombre no sirve de huella**: el seed anonimizado repite nombres (el alumno de la
     * escena se llama igual que su titular), así que buscarlo daría rojos falsos.
     */
    private function huellas(int $actId): array
    {
        $a = $this->alumno(0);
        $hoja = DB::table('ws_actividades_resueltas')->where('actividad_id', $actId)->where('user_id', $a->user_id)->first();

        return [
            'user_id' => '/"user_id":'.$a->user_id.'[,}]/',
            'alumno_id' => '/"alumno_id":'.$a->alumno_id.'[,}]/',
            'enviada_at' => '/'.preg_quote((string) $hoja->enviada_at, '/').'/',
        ];
    }

    public function test_con_nombre_el_barrido_si_encuentra_quien_respondio(): void
    {
        [$id] = $this->respondida('nombre');
        $visto = $this->loQueVenElCreadorYElDirectivo($id, true);
        $h = $this->huellas($id);

        $this->assertMatchesRegularExpression($h['user_id'], $visto, 'El instrumento no ve la identidad ni siquiera con nombre.');
        $this->assertMatchesRegularExpression($h['alumno_id'], $visto);
    }

    public function test_en_seguimiento_y_total_nadie_ve_quien_respondio(): void
    {
        foreach (['seguimiento', 'total'] as $nivel) {
            [$id] = $this->respondida($nivel);

            // La base sí lo sabe (§2.6).
            $hoja = DB::table('ws_actividades_resueltas')->where('actividad_id', $id)->first();
            $this->assertSame((int) $this->alumno(0)->user_id, (int) $hoja->user_id);
            $this->assertSame((int) $this->alumno(0)->alumno_id, (int) $hoja->alumno_id);

            $visto = $this->loQueVenElCreadorYElDirectivo($id, $nivel === 'seguimiento');

            foreach ($this->huellas($id) as $que => $huella) {
                $this->assertDoesNotMatchRegularExpression($huella, $visto, "En {$nivel}, el creador o un directivo ven {$que} de quien respondió.");
            }
        }
    }

    public function test_faltan_no_existe_en_total_y_en_seguimiento_es_por_persona(): void
    {
        [$total] = $this->respondida('total');
        $this->como('titular')->getJson("/api/act/{$total}/faltan")->assertStatus(409);

        [$seguimiento] = $this->respondida('seguimiento');
        $faltan = $this->como('titular')->getJson("/api/act/{$seguimiento}/faltan")->assertStatus(200)->json('grupos.0.faltan');

        $this->assertNotEmpty($faltan);
        $this->assertNotContains((int) $this->alumno(0)->user_id, array_column($faltan, 'user_id'));
        $this->assertContains((int) $this->alumno(1)->user_id, array_column($faltan, 'user_id'));
    }

    public function test_con_menos_de_cinco_hojas_todo_va_oculto(): void
    {
        [$id, $p] = $this->respondida('seguimiento');
        $this->masHojas($id, $p, [$this->alumno(1), $this->alumno(2), $this->alumno(3)]);

        $r = $this->como('titular')->getJson("/api/act/{$id}/resultados")->assertStatus(200);
        $grupo = $r->json('por_grupo.0');
        $this->assertTrue($grupo['oculto'], 'Un grupo con 4 hojas en una anónima salió con cifras.');
        $this->assertNull($grupo['respondieron']);
        $this->assertTrue($r->json('preguntas.0.oculto'));
        $this->assertSame([], $r->json('preguntas.0.opciones'));
        $this->assertNull($r->json('preguntas.1.textos'));
        $this->assertStringNotContainsString('Zanahorias', $r->getContent());

        // Con la quinta, ya no.
        $this->masHojas($id, $p, [$this->alumno(4)]);

        $r = $this->como('titular')->getJson("/api/act/{$id}/resultados")->assertStatus(200);
        $this->assertFalse($r->json('por_grupo.0.oculto'));
        $this->assertSame(5, $r->json('por_grupo.0.respondieron'));
        $this->assertSame([1, 4, 0], array_column($r->json('preguntas.0.opciones'), 'n'));
        $this->assertSame([['texto' => 'Zanahorias en el menú', 'autor' => null, 'grupo' => null]], $r->json('preguntas.1.textos'));
    }

    /** Con nombre no hay umbral: el docente ve cada texto con su autor. */
    public function test_con_nombre_los_textos_llevan_autor(): void
    {
        [$id] = $this->respondida('nombre');

        $r = $this->como('titular')->getJson("/api/act/{$id}/resultados")->assertStatus(200);
        $this->assertFalse($r->json('por_grupo.0.oculto'));
        $this->assertSame((int) $this->alumno(0)->user_id, $r->json('preguntas.1.textos.0.autor.user_id'));
    }

    /** Quien respondió sí ve lo suyo, en los tres niveles: el servidor lo sabe. */
    public function test_mis_respuestas_en_una_anonima(): void
    {
        [$id, $p] = $this->respondida('total');

        $mis = $this->como($this->alumno(0))->getJson("/api/act/{$id}/mis-respuestas")->assertStatus(200);
        $this->assertSame([$p['opciones'][0]['id']], $mis->json('respuestas.0.mia.opcion_ids'));
        $this->assertSame('Zanahorias en el menú', $mis->json('respuestas.1.mia.texto'));
    }

    public function test_el_anonimato_solo_sube(): void
    {
        [$id] = $this->respondida('nombre');

        // Con hojas ya enviadas, subir se puede: sólo esconde más.
        $this->como('titular')->postJson("/api/act/{$id}/anonimato", ['anonimato' => 'seguimiento'])->assertStatus(200)
            ->assertJsonPath('anonimato', 'seguimiento');
        $this->como('titular')->postJson("/api/act/{$id}/guardar", ['anonimato' => 'total'])->assertStatus(200)
            ->assertJsonPath('anonimato', 'total');

        // Bajar, por cualquiera de las dos rutas, no.
        $this->como('titular')->postJson("/api/act/{$id}/anonimato", ['anonimato' => 'seguimiento'])->assertStatus(422);
        $this->como('titular')->postJson("/api/act/{$id}/guardar", ['anonimato' => 'nombre'])->assertStatus(422);
        $this->assertSame('total', $this->fila($id)->anonimato);

        // En borrador se elige libre.
        $borrador = $this->crear($this->encuesta(['anonimato' => 'total', 'titulo' => 'Borrador']));
        $this->como('titular')->postJson("/api/act/{$borrador['id']}/anonimato", ['anonimato' => 'nombre'])->assertStatus(200);
    }

    /** Una pregunta de archivo lleva quién lo subió: no cabe en una anónima. */
    public function test_archivo_y_anonimato_no_conviven(): void
    {
        $act = $this->crear($this->encuesta());
        $this->pregunta($act['id'], ['tipo' => 'archivo', 'enunciado' => 'Sube tu dibujo']);

        $this->como('titular')->postJson("/api/act/{$act['id']}/anonimato", ['anonimato' => 'seguimiento'])->assertStatus(422);
        $this->como('titular')->postJson("/api/act/{$act['id']}/guardar", ['anonimato' => 'total'])->assertStatus(422);
        $this->assertSame('nombre', $this->fila($act['id'])->anonimato);
    }

    /** Tareas y cuestionarios van con nombre siempre. */
    public function test_una_tarea_no_se_hace_anonima(): void
    {
        $act = $this->crear($this->tarea());

        $this->como('titular')->postJson("/api/act/{$act['id']}/anonimato", ['anonimato' => 'seguimiento'])->assertStatus(422);
    }
}
