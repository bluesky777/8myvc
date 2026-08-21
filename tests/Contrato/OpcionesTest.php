<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las opciones de una pregunta de actividad.
 *
 * Quinto y último de los controladores que la cobertura del 20 de agosto dio con
 * cero respuestas comprobadas. Estaba mudo por lo mismo que las dos rutas de la
 * §24: `ws_actividades` está vacía en el seed, así que el barrido pasaba por sus
 * cuatro rutas con identificadores que no existen y no podía decir nada. Se
 * aplica la misma regla: si falta la fila, la monta el test que la necesita.
 */
class OpcionesTest extends CasoDeContrato
{
    /** Una pregunta de opción múltiple con dos opciones, la segunda correcta. */
    private function montarUnaPregunta(): array
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        DB::insert('INSERT INTO ws_actividades (asignatura_id, periodo_id, descripcion, tipo, compartida,
                        can_upload, in_action, duracion_preg, duracion_exam, oportunidades, one_by_one,
                        contenido, created_at, updated_at)
                    VALUES (?, ?, "Con opciones", "E", 0, 0, 1, 60, 3600, 1, 0, "", ?, ?)',
            [$asignatura->id, $periodo->id, now(), now()]);
        $actividad = (int) DB::getPdo()->lastInsertId();

        DB::insert('INSERT INTO ws_preguntas (enunciado, actividad_id, tipo_pregunta, orden, puntos,
                        created_at, updated_at)
                    VALUES ("¿Cuál?", ?, "seleccion", 1, 10, ?, ?)', [$actividad, now(), now()]);
        $pregunta = (int) DB::getPdo()->lastInsertId();

        $opciones = [];

        foreach ([['A', 0], ['B', 1]] as $i => [$texto, $correcta]) {
            DB::insert('INSERT INTO ws_opciones (definicion, pregunta_id, orden, is_correct, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?)', [$texto, $pregunta, $i + 1, $correcta, now(), now()]);
            $opciones[] = (int) DB::getPdo()->lastInsertId();
        }

        return [$pregunta, $opciones];
    }

    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    public function test_se_anade_una_opcion_a_la_pregunta(): void
    {
        [$pregunta] = $this->montarUnaPregunta();

        $r = $this->withToken($this->tokenDelPersonal())->putJson('/api/opciones/add-opcion', [
            'definicion' => 'C', 'pregunta_id' => $pregunta, 'orden' => 3, 'is_correct' => 0,
        ]);

        // 201 y no 200: Laravel lo pone solo cuando la respuesta es un modelo
        // Eloquent recién creado. Es lo que reciben los clientes hoy.
        $r->assertStatus(201);
        $this->assertSame('C', $r->json('definicion'));
        $this->assertSame($pregunta, $r->json('pregunta_id'));
        $this->assertSame(3, DB::table('ws_opciones')->where('pregunta_id', $pregunta)->count());
    }

    public function test_se_edita_el_texto_de_una_opcion(): void
    {
        [, $opciones] = $this->montarUnaPregunta();

        $r = $this->withToken($this->tokenDelPersonal())->putJson('/api/opciones/guardar', [
            'id' => $opciones[0], 'definicion' => 'A corregida', 'orden' => 1,
        ]);

        $r->assertStatus(200);
        $this->assertSame('A corregida',
            DB::table('ws_opciones')->where('id', $opciones[0])->value('definicion'));
    }

    /**
     * Marcar la correcta apaga a las hermanas, que es lo que hace la ruta.
     *
     * Se comprueba mirando **las dos**: que la nueva quede en 1 y que la que lo
     * era quede en 0. Mirar solo la nueva dejaría pasar una pregunta con dos
     * respuestas correctas, que es justo el estado que esta ruta existe para
     * evitar.
     */
    public function test_marcar_la_correcta_apaga_a_las_hermanas(): void
    {
        [$pregunta, $opciones] = $this->montarUnaPregunta();

        $r = $this->withToken($this->tokenDelPersonal())->putJson('/api/opciones/set-opcion-correct', [
            'id' => $opciones[0], 'pregunta_id' => $pregunta,
        ]);

        $r->assertStatus(200);
        $this->assertSame(1, (int) DB::table('ws_opciones')->where('id', $opciones[0])->value('is_correct'));
        $this->assertSame(0, (int) DB::table('ws_opciones')->where('id', $opciones[1])->value('is_correct'));
    }

    public function test_se_borra_una_opcion(): void
    {
        [$pregunta, $opciones] = $this->montarUnaPregunta();

        $this->withToken($this->tokenDelPersonal())
            ->deleteJson('/api/opciones/destroy/'.$opciones[0])
            ->assertStatus(200);

        $this->assertSame(1, DB::table('ws_opciones')->where('pregunta_id', $pregunta)->count(),
            'El borrado de opciones es duro: la fila desaparece.');
    }

    /**
     * Una opción que no existe es 404, y era 500.
     *
     * `putGuardar` usaba `find()` y las otras tres rutas del fichero
     * `findOrFail()`. Con `find()`, `null->definicion` reventaba.
     */
    public function test_una_opcion_que_no_existe_es_404(): void
    {
        $inexistente = (int) DB::table('ws_opciones')->max('id') + 10_000;
        $token = $this->tokenDelPersonal();

        $this->withToken($token)->putJson('/api/opciones/guardar',
            ['id' => $inexistente, 'definicion' => 'x', 'orden' => 1])->assertStatus(404);

        $this->withToken($token)->deleteJson('/api/opciones/destroy/'.$inexistente)->assertStatus(404);
    }

    /** Un alumno no edita las opciones del examen: las cuatro son `auth.personal`. */
    public function test_una_familia_no_edita_las_opciones(): void
    {
        [$pregunta, $opciones] = $this->montarUnaPregunta();

        foreach (['Alumno', 'Acudiente'] as $tipo) {
            $token = $this->tokenDe($this->usuarioDeTipo($tipo)->username);

            $this->withToken($token)->putJson('/api/opciones/guardar',
                ['id' => $opciones[0], 'definicion' => 'trampa', 'orden' => 1])->assertStatus(403);
            $this->withToken($token)->putJson('/api/opciones/set-opcion-correct',
                ['id' => $opciones[0], 'pregunta_id' => $pregunta])->assertStatus(403);
            $this->withToken($token)->putJson('/api/opciones/add-opcion',
                ['definicion' => 'D', 'pregunta_id' => $pregunta, 'orden' => 9, 'is_correct' => 1])
                ->assertStatus(403);
            $this->withToken($token)->deleteJson('/api/opciones/destroy/'.$opciones[0])->assertStatus(403);
        }

        $this->assertSame('A', DB::table('ws_opciones')->where('id', $opciones[0])->value('definicion'));
        $this->assertSame(0, (int) DB::table('ws_opciones')->where('id', $opciones[0])->value('is_correct'));
    }
}
