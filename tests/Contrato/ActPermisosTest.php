<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Actividades nuevas — **quién puede qué** (contrato §2.2, §2.3 y §3).
 *
 * Tres reglas, y cada una se comprueba con las dos caras:
 *
 * 1. **Todo lo del creador exige ser el dueño** (`created_by`): otro docente recibe 403 al leer,
 *    editar, publicar, borrar, poner preguntas, ver resultados o calificar.
 * 2. **Los directivos LEEN de cualquiera, pero no editan**: resultados, faltan, entregas y la
 *    actividad entera sí; guardar y calificar no.
 * 3. **Quien responde sólo ve lo suyo**: una actividad que no le toca es 403, y las rutas del
 *    creador exigen personal (`auth.personal`).
 *
 * Cada petición va con `como()`, que pasa por `withToken()` y suelta el controlador de la anterior:
 * sin eso, dos identidades contra la misma ruta medirían dos veces la primera (03-tests.md).
 */
class ActPermisosTest extends CasoDeActividades
{
    public function test_otro_docente_recibe_403_en_todo_lo_del_creador(): void
    {
        $act = $this->crear($this->cuestionario());
        $p = $this->unica($act['id']);
        $id = $act['id'];

        $prohibidas = [
            ['get', "/api/act/{$id}", []],
            ['post', "/api/act/{$id}/guardar", ['titulo' => 'Mío']],
            ['post', "/api/act/{$id}/publicar", []],
            ['post', "/api/act/{$id}/borrar", []],
            ['post', "/api/act/{$id}/preguntas", ['tipo' => 'parrafo', 'enunciado' => 'x']],
            ['post', "/api/act/preguntas/{$p['id']}/guardar", ['tipo' => 'parrafo', 'enunciado' => 'x']],
            ['post', "/api/act/preguntas/{$p['id']}/borrar", []],
            ['get', "/api/act/{$id}/resultados", []],
            ['get', "/api/act/{$id}/faltan", []],
            ['post', "/api/act/{$id}/cerrar", []],
            ['post', "/api/act/{$id}/impacto", ['cambios' => []]],
        ];

        foreach ($prohibidas as [$verbo, $ruta, $cuerpo]) {
            $r = $verbo === 'get' ? $this->como('ajeno')->getJson($ruta) : $this->como('ajeno')->postJson($ruta, $cuerpo);
            $this->assertSame(403, $r->status(), "{$ruta} dejó entrar a otro docente ({$r->status()}).");
        }

        $this->assertSame('Cuestionario de prueba', $this->fila($id)->titulo, 'El 403 no impidió que se guardara.');
        $this->assertNull($this->fila($id)->deleted_at);
    }

    public function test_el_directivo_lee_resultados_entregas_y_faltan_pero_no_edita(): void
    {
        $tarea = $this->crear($this->tarea());
        $this->publicar($tarea['id']);
        [$encuesta] = $this->encuestaPublicada();

        foreach (["/api/act/{$tarea['id']}", "/api/act/{$tarea['id']}/entregas", "/api/act/{$encuesta}/resultados", "/api/act/{$encuesta}/faltan"] as $ruta) {
            $this->como('directivo')->getJson($ruta)->assertStatus(200);
        }

        $this->como('directivo')->postJson("/api/act/{$tarea['id']}/guardar", ['titulo' => 'Del rector'])->assertStatus(403);
        $this->como('directivo')->postJson("/api/act/{$tarea['id']}/entregas/{$this->alumno()->alumno_id}/calificar", ['nota' => 10])
            ->assertStatus(403);
        $this->como('directivo')->postJson("/api/act/{$encuesta}/cerrar")->assertStatus(403);

        $this->assertSame('Tarea de prueba', $this->fila($tarea['id'])->titulo);
    }

    /** Alguien del personal que no es directivo no ve lo de otro: la lectura es sólo del directivo. */
    public function test_el_personal_que_no_es_directivo_no_lee_lo_ajeno(): void
    {
        [$encuesta] = $this->encuestaPublicada();

        $this->como('llano')->getJson("/api/act/{$encuesta}/resultados")->assertStatus(403);
        $this->como('llano')->getJson("/api/act/{$encuesta}")->assertStatus(403);
    }

    public function test_las_rutas_del_creador_exigen_personal(): void
    {
        $act = $this->crear($this->cuestionario());

        foreach ([$this->alumno(), 'acudiente'] as $quien) {
            $this->como($quien)->postJson('/api/act/crear', $this->encuesta())->assertStatus(403);
            $this->como($quien)->getJson('/api/act/catalogo')->assertStatus(403);
            $this->como($quien)->getJson("/api/act/{$act['id']}")->assertStatus(403);
            $this->como($quien)->postJson('/api/act/conteo', ['alcance' => 'colegio', 'responden' => 'alumnos'])->assertStatus(403);
            $this->como($quien)->getJson('/api/act/bandeja?vista=mias')->assertStatus(403);
        }
    }

    public function test_a_un_alumno_de_otro_grupo_no_le_toca(): void
    {
        // Se publica al grupo de la escena (a un grupo vacío no se puede: nadie la recibiría) y
        // después se muda a un grupo sin el alumno.
        $ajeno = $this->grupoAjenoDelMismoAnio($this->escena()->year_id);
        [$suya] = $this->encuestaPublicada();
        DB::table('destinatarios')->where('origen_tipo', 'actividad')->where('origen_id', $suya)
            ->update(['grupo_id' => $ajeno->grupo_id]);

        $this->como($this->alumno())->getJson("/api/act/{$suya}/responder")->assertStatus(403);
        $this->como($this->alumno())->postJson("/api/act/{$suya}/enviar", ['respuestas' => []])->assertStatus(403);
        $this->como($this->alumno())->postJson("/api/act/{$suya}/borrador", ['respuestas' => []])->assertStatus(403);
    }

    /** Un alumno no llega a las respuestas de otro pidiéndolas con su `alumno_id`. */
    public function test_mis_respuestas_de_otro_alumno_no_se_ven(): void
    {
        [$id, $p] = $this->encuestaPublicada();
        $this->enviar($id, $this->alumno(0), [$this->marcar($p, 1)])->assertStatus(200);

        $r = $this->como($this->alumno(1))->getJson("/api/act/{$id}/mis-respuestas?alumno_id={$this->alumno(0)->alumno_id}");

        $this->assertSame(409, $r->status(), 'Un alumno pidió las respuestas de otro y no le dijeron que no.');
        $this->assertStringNotContainsString((string) $p['opciones'][1]['id'], (string) json_encode($r->json('respuestas')));
    }

    /** El acudiente sólo pide por sus hijos oficiales. */
    public function test_el_acudiente_no_pide_por_un_alumno_que_no_es_su_hijo(): void
    {
        [$id] = $this->encuestaPublicada(['responden' => 'acudientes']);
        $otro = collect($this->escena()->alumnos)->first(fn ($a) => $a->alumno_id !== $this->escena()->acudiente->alumno_id);

        $this->como('acudiente')->getJson("/api/act/{$id}/responder?alumno_id={$otro->alumno_id}")->assertStatus(403);
        $this->como('acudiente')->getJson("/api/act/{$id}/mis-respuestas?alumno_id={$otro->alumno_id}")->assertStatus(403);
    }

    /** `act/*` no ve las actividades viejas (`modo IS NULL`): 404, igual que una borrada. */
    public function test_una_actividad_vieja_no_existe_para_act(): void
    {
        $vieja = DB::table('ws_actividades')->insertGetId([
            'asignatura_id' => $this->escena()->clase,
            'periodo_id' => $this->escena()->periodo_id,
            'descripcion' => 'Vieja',
            'created_by' => $this->escena()->titular->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->como('titular')->getJson("/api/act/{$vieja}")->assertStatus(404);
        $this->como('titular')->postJson("/api/act/{$vieja}/guardar", ['titulo' => 'x'])->assertStatus(404);
        $this->como($this->alumno())->getJson("/api/act/{$vieja}/responder")->assertStatus(404);
    }

    /** La bandeja «por aprobar» es de directivos. */
    public function test_la_bandeja_por_aprobar_es_de_directivos(): void
    {
        $this->como('titular')->getJson('/api/act/bandeja?vista=aprobar')->assertStatus(403);
        $this->como('llano')->getJson('/api/act/bandeja?vista=aprobar')->assertStatus(403);
        $this->como('directivo')->getJson('/api/act/bandeja?vista=aprobar')->assertStatus(200);
    }
}
