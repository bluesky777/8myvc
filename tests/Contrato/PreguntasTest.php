<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Las preguntas de una actividad, miradas por el resultado.
 *
 * Séptimo controlador de la serie y el que cierra el dominio junto con
 * `ActividadesTest`, `MisActividadesTest` y `OpcionesTest`.
 *
 * Repite las dos formas de `actividades/*` —un campo que no viene se escribe
 * como null, y nadie mira de quién es— y añade una tercera que aquélla no tenía:
 * **resolver un id y seguir con la nada**, tres veces —dos `find()` sin `OrFail` y
 * un `[0]` sobre una consulta vacía—. Los tres daban 500 donde tocaba 404 y los
 * tres se arreglaron en el barrido del 21 ago 2026.
 *
 * `ws_actividades` está vacía en el seed, así que todo se monta aquí.
 */
class PreguntasTest extends CasoDeContrato
{
    /** Personal del colegio del año que tiene grupos con alumnos. */
    private function docente(): object
    {
        $grupo = $this->grupoConAlumnos();

        $usuario = DB::selectOne('SELECT u.id, u.username FROM users u
            INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL
            WHERE u.tipo = "Usuario" AND u.is_active = 1 AND u.deleted_at IS NULL
              AND p.year_id = ? ORDER BY u.id LIMIT 1', [$grupo->year_id]);

        $this->assertNotNull($usuario, "El seed no tiene personal en el año {$grupo->year_id}.");

        return (object) [
            'user_id' => (int) $usuario->id,
            'grupo_id' => (int) $grupo->id,
            'token' => $this->tokenDe($usuario->username),
        ];
    }

    /** Una actividad de `$duenoId` con dos preguntas puestas del todo. */
    private function actividadConPreguntas(int $duenoId, int $grupoId): object
    {
        $asignatura = DB::selectOne('SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL
                                     ORDER BY id LIMIT 1', [$grupoId]);
        $periodo = DB::selectOne('SELECT id FROM periodos WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

        $this->assertNotNull($asignatura, 'El grupo del seed no tiene asignaturas.');

        $actividadId = DB::table('ws_actividades')->insertGetId([
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id,
            'descripcion' => 'Examen con preguntas',
            'tipo' => 'E',
            'created_by' => $duenoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preguntas = [];

        foreach ([1, 2] as $orden) {
            $preguntas[] = DB::table('ws_preguntas')->insertGetId([
                'actividad_id' => $actividadId,
                'enunciado' => "Pregunta {$orden}",
                'orden' => $orden,
                'tipo_pregunta' => 'Test',
                'puntos' => 5,
                'ayuda' => 'una pista',
                'added_by' => $duenoId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (object) ['actividad_id' => $actividadId, 'preguntas' => $preguntas];
    }

    /**
     * Guardar una pregunta que no existe es 404 — **arreglado el 21 ago 2026**.
     *
     * `putGuardar()` hacía `WsPregunta::find(...)` y seguía asignando propiedades
     * sobre el `null` que devuelve, que en PHP 8 es fatal: 500 en vez del 404 que
     * le corresponde. Entró en el barrido de los `::find()` sin `OrFail`.
     */
    public function test_guardar_una_pregunta_que_no_existe_es_404(): void
    {
        $docente = $this->docente();

        $inventada = ((int) DB::table('ws_preguntas')->max('id')) + 1000;

        $this->withToken($docente->token)
            ->putJson('/api/preguntas/guardar', ['id' => $inventada, 'enunciado' => 'X'])
            ->assertStatus(404);
    }

    /** Y el interruptor de la opción «Otra», por lo mismo. */
    public function test_el_toggle_de_una_pregunta_que_no_existe_es_404(): void
    {
        $docente = $this->docente();

        $inventada = ((int) DB::table('ws_preguntas')->max('id')) + 1000;

        $this->withToken($docente->token)
            ->putJson('/api/preguntas/toggle-opcion-otra', ['id' => $inventada, 'opcion_otra' => 1])
            ->assertStatus(404);
    }

    /**
     * **`preguntas/edicion` responde 500 a todo el mundo, y no por el `[0]`.**
     *
     * Se entró aquí a arreglar un `[0]` sobre una consulta vacía, como los dos de
     * arriba. Al medirlo resultó ser otra cosa: el `ORDER BY` dice **`p.order`** y
     * la columna se llama **`orden`**, así que la consulta falla antes de devolver
     * nada. **Con una pregunta que sí existe, también es 500.**
     *
     * O sea que la pantalla de editar una pregunta no funciona en ningún colegio y
     * no ha funcionado nunca. El `?? abort(404)` que se había escrito se retiró:
     * era código inalcanzable, **que es peor que el fallo porque hace creer que el
     * caso está cubierto**.
     *
     * Se fija tal cual —con ruta y roto se documenta—, y el arreglo es una letra.
     * No se hace aquí porque enciende en los dieciséis colegios una pantalla que
     * hoy no existe. Ver 14-certificados.md §8.
     */
    public function test_editar_una_pregunta_es_500_exista_o_no(): void
    {
        $docente = $this->docente();
        $examen = $this->actividadConPreguntas($docente->user_id, $docente->grupo_id);

        $inventada = ((int) DB::table('ws_preguntas')->max('id')) + 1000;

        foreach ([$examen->preguntas[0], $inventada] as $id) {
            $this->withToken($docente->token)
                ->putJson('/api/preguntas/edicion', ['pregunta_id' => $id])
                ->assertStatus(500);
        }
    }

    /**
     * Guardar sin un campo lo borra, igual que en `actividades/guardar`.
     *
     * Siete campos asignados seguidos con `Request::input('x')` a secas. Un
     * cliente que mande solo el enunciado se lleva por delante los puntos, la
     * ayuda y la duración de la pregunta.
     */
    public function test_guardar_sin_un_campo_lo_borra(): void
    {
        $docente = $this->docente();
        $examen = $this->actividadConPreguntas($docente->user_id, $docente->grupo_id);

        $this->withToken($docente->token)
            ->putJson('/api/preguntas/guardar', [
                'id' => $examen->preguntas[0],
                'enunciado' => 'Pregunta 1 (corregida)',
            ])
            ->assertStatus(200);

        $fila = DB::table('ws_preguntas')->where('id', $examen->preguntas[0])->first();

        $this->assertSame('Pregunta 1 (corregida)', $fila->enunciado);
        $this->assertNull($fila->ayuda, 'La pista se fue.');
        $this->assertSame(0, (int) $fila->puntos, 'Y la pregunta pasó a valer cero puntos.');
    }

    /**
     * Cualquiera del personal reordena las preguntas del examen de otro.
     *
     * `putUpdateOrden()` recorre el `sortHash` del cuerpo —un mapa de
     * `id => orden`— y hace `WsPregunta::find((int)$key)` con cada clave. No mira
     * a qué actividad pertenece la pregunta ni quién la creó, así que el mapa
     * puede llevar ids de cualquier examen del colegio.
     *
     * Y como es `find()` otra vez, **un id que no exista dentro del mapa revienta
     * la llamada a media escritura**: las preguntas anteriores del bucle ya se
     * guardaron. No hay transacción.
     */
    public function test_el_personal_reordena_las_preguntas_de_otro(): void
    {
        $docente = $this->docente();
        $ajeno = $this->actividadConPreguntas($docente->user_id + 1, $docente->grupo_id);

        $this->withToken($docente->token)
            ->putJson('/api/preguntas/update-orden', [
                'sortHash' => [
                    [$ajeno->preguntas[0] => 9],
                    [$ajeno->preguntas[1] => 8],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(9, (int) DB::table('ws_preguntas')->where('id', $ajeno->preguntas[0])->value('orden'));
        $this->assertSame(8, (int) DB::table('ws_preguntas')->where('id', $ajeno->preguntas[1])->value('orden'));
    }

    /**
     * Y con un id inexistente en mitad del mapa, lo anterior queda escrito.
     *
     * Es lo que hace que el `find()` sin `OrFail` importe más aquí que en los
     * otros dos: no es solo el código de error, es que el examen queda **con la
     * mitad del orden nuevo y la mitad del viejo**, y el cliente recibe un 500
     * que le dice que no se guardó nada.
     */
    public function test_un_id_malo_en_el_reordenar_deja_lo_anterior_escrito(): void
    {
        $docente = $this->docente();
        $examen = $this->actividadConPreguntas($docente->user_id, $docente->grupo_id);

        $inventada = ((int) DB::table('ws_preguntas')->max('id')) + 1000;

        $this->withToken($docente->token)
            ->putJson('/api/preguntas/update-orden', [
                'sortHash' => [
                    [$examen->preguntas[0] => 7],
                    [$inventada => 8],
                ],
            ])
            ->assertStatus(404);

        $this->assertSame(
            7,
            (int) DB::table('ws_preguntas')->where('id', $examen->preguntas[0])->value('orden'),
            'La primera del bucle se guardó antes de reventar.'
        );
    }
}
