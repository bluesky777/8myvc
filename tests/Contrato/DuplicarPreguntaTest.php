<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * Duplicar una pregunta, que era lo último que quedaba en la lista de
 * [13-actividades.md](../../docs/migracion/13-actividades.md) «lo que queda por
 * mirar»: *«copia una pregunta con sus opciones y es donde suelen esconderse los
 * campos que no se copian»*.
 *
 * **La corazonada no era, y merece la pena por qué**, porque la respuesta cambia
 * cómo se lee el resto del dominio:
 *
 * - `ws_opciones.image_id` **no se copia y da igual**: no lo escribe nadie —ni el
 *   backend ni ninguno de los cuatro clientes— y hay **0 filas** con valor. Es
 *   una columna muerta, no un campo perdido.
 * - `ws_opciones_cuadricula` tampoco se copia, y es lo mismo un piso más arriba:
 *   **la tabla no tiene un solo `INSERT` en toda la API** y está vacía. Es el
 *   hueco del cuarto tipo de pregunta que ya marca la §6 de aquel documento —el
 *   tipo «Cuadrícula» se lee y no se escribe—, así que duplicar no puede perder
 *   lo que no se puede crear.
 * - El `// Debo modificarlo` que el autor dejó al lado de `orden` **ya lo resuelve
 *   el front**: `EditarActividadCtrl.duplicar_pregunta` pone
 *   `pregunta.orden = $ctrl.actividad.preguntas.length` antes de mandar. El
 *   comentario describe una tarea hecha en el otro lado.
 *
 * O sea que lo que hay que dejar fijado no es un arreglo sino **el viaje de ida y
 * vuelta**: que la copia lleva de verdad lo que se le mandó, y las opciones con
 * ella. Un test que solo mirara el 200 no distingue eso de una copia vacía, y una
 * copia vacía es exactamente el fallo que se venía a buscar.
 */
class DuplicarPreguntaTest extends CasoDeContrato
{
    /**
     * Una actividad sobre la que colgar preguntas, montada aquí.
     *
     * **`ws_actividades` llega vacía en el seed**, y esa es la razón de que se
     * monte en vez de buscarse: un test que se salte el caso porque la tabla está
     * vacía pasa siempre y no mide nada, que es la trampa que ya va por siete
     * veces en este repo. Comprobado: sin esto, el test falla por «el seed
     * necesita alguna actividad» y no por lo que viene a comprobar.
     */
    private function actividad(): int
    {
        $asignatura = DB::table('asignaturas')->whereNull('deleted_at')->orderBy('id')->first();
        $periodo = DB::table('periodos')->orderBy('id')->first();

        $this->assertNotNull($asignatura, 'El seed necesita alguna asignatura.');

        return (int) DB::table('ws_actividades')->insertGetId([
            'descripcion' => 'Actividad de prueba',
            'asignatura_id' => $asignatura->id,
            'periodo_id' => $periodo->id ?? null,
            'para_alumnos' => 0,
            'para_profesores' => 0,
            'para_acudientes' => 0,
            'can_upload' => 0,
            'tipo_calificacion' => 0,
        ]);
    }

    public function test_la_pregunta_duplicada_llega_entera_con_sus_opciones(): void
    {
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);
        $actividadId = $this->actividad();

        $cuerpo = [
            'actividad_id' => $actividadId,
            'contenido_id' => null,
            'enunciado' => '¿Cuál es la capital de Colombia?',
            'ayuda' => 'Piensa en el altiplano',
            'puntos' => 5,
            'duracion' => 60,
            'aleatorias' => 1,
            'texto_arriba' => 'arriba',
            'texto_abajo' => 'abajo',
            'tipo_pregunta' => 'Test',
            'opcion_otra' => 1,
            'orden' => 7,
            'opciones' => [
                ['definicion' => 'Bogotá', 'orden' => 0, 'is_correct' => 1],
                ['definicion' => 'Medellín', 'orden' => 1, 'is_correct' => 0],
                ['definicion' => 'Cali', 'orden' => 2, 'is_correct' => 0],
            ],
        ];

        $r = $this->withToken($token)->putJson('/api/preguntas/duplicar-pregunta', $cuerpo);

        // **201 y no 200**, y no lo escribió nadie: Laravel lo pone solo cuando la
        // acción devuelve un modelo Eloquent con `wasRecentlyCreated`. Sus
        // hermanas de este controlador devuelven cadenas o modelos ya existentes y
        // salen 200, así que el código depende de qué se devuelve y no de qué se
        // hace. Se fija tal cual: es lo que reciben los clientes hoy.
        $r->assertStatus(201);

        $nuevaId = (int) $r->json('id');
        $this->assertGreaterThan(0, $nuevaId, 'No devolvió la pregunta creada.');

        // El viaje de vuelta: se lee de la base, no de la respuesta. Una respuesta
        // construida en memoria diría que todo fue bien aunque el `save()` no
        // hubiera escrito nada.
        $fila = DB::table('ws_preguntas')->where('id', $nuevaId)->first();

        $this->assertNotNull($fila);
        $this->assertSame('¿Cuál es la capital de Colombia?', $fila->enunciado);
        $this->assertSame('Piensa en el altiplano', $fila->ayuda);
        $this->assertSame(5, (int) $fila->puntos);
        $this->assertSame(60, (int) $fila->duracion);
        $this->assertSame('arriba', $fila->texto_arriba);
        $this->assertSame('abajo', $fila->texto_abajo);
        $this->assertSame('Test', $fila->tipo_pregunta);
        $this->assertSame(1, (int) $fila->opcion_otra);
        $this->assertSame(7, (int) $fila->orden);
        $this->assertSame($actividadId, (int) $fila->actividad_id);

        // `added_by` guarda `user_id` — y su hermana `ws_actividades.created_by`
        // guarda `persona_id`. Las dos columnas de propiedad del mismo dominio
        // guardan cosas distintas con nombres igual de genéricos, y esto lo deja
        // fijado para el día que alguien escriba el guard de propiedad que la §2
        // de 13-actividades dejó aplazado: escrito de la forma obvia, no casaría
        // nunca.
        $this->assertSame((int) $profesor->id, (int) $fila->added_by,
            '`added_by` dejó de guardar el user_id; el guard de propiedad que viene depende de esto.');

        $opciones = DB::table('ws_opciones')->where('pregunta_id', $nuevaId)
            ->orderBy('orden')->get();

        $this->assertCount(3, $opciones, 'Las opciones no viajaron con la pregunta.');
        $this->assertSame(['Bogotá', 'Medellín', 'Cali'],
            $opciones->pluck('definicion')->all());
        $this->assertSame([1, 0, 0],
            $opciones->pluck('is_correct')->map(fn ($v) => (int) $v)->all());
    }

    /**
     * Sin `opciones` en el cuerpo revienta, y se deja escrito porque es 500.
     *
     * `count($opciones)` sobre el null que devuelve un `Request::input` ausente es
     * un TypeError en PHP 8 —el mismo de la §13—, **y la pregunta ya está creada
     * cuando llega**: queda una pregunta sin ninguna opción y el cliente recibe un
     * error de una operación que sí ocurrió a medias.
     *
     * No se arregla aquí: `EditarActividadCtrl` manda siempre el objeto entero de
     * una pregunta que ya existe, así que no hay camino real que llegue sin
     * opciones, y decidir qué debe pasar —¿400? ¿una pregunta sin opciones es
     * válida?— es del colegio. Se fija para que se vea el día que alguien toque
     * este método.
     */
    public function test_sin_opciones_revienta_con_la_pregunta_ya_creada(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $actividadId = $this->actividad();

        $antes = DB::table('ws_preguntas')->where('actividad_id', $actividadId)->count();

        $this->withToken($token)->putJson('/api/preguntas/duplicar-pregunta', [
            'actividad_id' => $actividadId,
            'enunciado' => 'sin opciones',
            'tipo_pregunta' => 'Test',
            'orden' => 0,
        ])->assertStatus(500);

        $this->assertSame($antes + 1,
            DB::table('ws_preguntas')->where('actividad_id', $actividadId)->count(),
            'La pregunta se crea antes de reventar, y eso es lo que hay que ver.');
    }

    /** Y borrar una pregunta que no existe es 404, no 500: `findOrFail` ya lo hacía. */
    public function test_borrar_una_pregunta_que_no_existe_es_404(): void
    {
        $token = $this->tokenDe($this->usuarioDeTipo('Profesor')->username);
        $inexistente = ((int) DB::table('ws_preguntas')->max('id')) + 1000;

        $this->withToken($token)->deleteJson('/api/preguntas/destroy/'.$inexistente)
            ->assertStatus(404);
    }
}
