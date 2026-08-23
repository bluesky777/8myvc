<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * §86 — `disciplina/update`: escribía y luego reventaba, por dos caminos.
 *
 * `DisciplinaTest` ya cubría abrir, borrar y derivar una falta. Faltaba **editar**,
 * que es la que más veces se llama de las siete, y ahí había dos 500 con la misma
 * forma: el UPDATE del proceso disciplinario **ya se había hecho** cuando el
 * método reventaba montando la respuesta. El front recibe un error sobre una
 * escritura que sí ocurrió, y lo que hace un front con eso es volver a mandarla.
 *
 * Los dos caminos:
 *
 * 1. **Sin `dependencias` en el cuerpo** — `count(null)` es un TypeError en PHP 8.
 *    Su hermana `postStore` preguntaba `is_array()` desde siempre. La asimetría
 *    entre hermanas es lo que lo escondió: quien leyó `store` dio por hecho que
 *    `update` haría lo mismo.
 * 2. **Con un `alumno_id` que no existe** — o que existe sin matrícula viva, que
 *    la consulta de la ficha lleva un INNER JOIN a `matriculas`. `[0]` sobre una
 *    lista vacía: **500 donde tocaba 404** (05 §52).
 *
 * Lo que este test fija de más: que la escritura ocurra o no ocurra sigue siendo
 * lo que era. El arreglo es del código de salida, no del efecto.
 */
class DisciplinaUpdateTest extends CasoDeContrato
{
    private function tokenDelPersonal(): string
    {
        return $this->tokenDe($this->usuarioDeTipo('Usuario')->username);
    }

    private function alumnoConContexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS alumno_id, g.year_id, p.id AS periodo_id
            FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
                AND m.estado IN ("MATR","ASIS","PREM")
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
            WHERE a.deleted_at IS NULL ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila, 'El seed no tiene ningún alumno matriculado con periodo.');

        return $fila;
    }

    /** Abre una falta y devuelve su id, que es de donde parten todos los de abajo. */
    private function unProceso(string $token, object $ctx): int
    {
        $this->withToken($token)->postJson('/api/disciplina/store', [
            'year_id' => $ctx->year_id,
            'alumno_id' => $ctx->alumno_id,
            'periodo_id' => $ctx->periodo_id,
            'descripcion' => 'Llegó tarde tres veces en la semana.',
            'tipo_situacion' => 1,
            'fecha_hora_aprox' => '2026-08-20 07:15:00',
        ])->assertStatus(200);

        return (int) DB::table('dis_procesos')->orderByDesc('id')->value('id');
    }

    /**
     * El camino bueno, que es el que no puede cambiar: edita y devuelve la ficha.
     */
    public function test_editar_una_falta_devuelve_la_ficha_del_alumno(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $proceso = $this->unProceso($token, $ctx);

        $r = $this->withToken($token)->putJson('/api/disciplina/update', [
            'id' => $proceso,
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'descripcion' => 'Rectificado: llegó tarde dos veces.',
            'tipo_situacion' => 2,
            'testigos' => 'El coordinador de convivencia',
            'descargo' => 'Dice que el bus se retrasó.',
            'dependencias' => [],
        ]);

        // 200 y la ficha entera, igual que `store`: es lo que repinta la pantalla
        // del observador de una vez, y por eso no devuelve el proceso.
        $r->assertStatus(200);
        $this->assertSame((int) $ctx->alumno_id, (int) $r->json('alumno_id'));

        $fila = DB::table('dis_procesos')->where('id', $proceso)->first();
        $this->assertSame('Rectificado: llegó tarde dos veces.', $fila->descripcion);
        $this->assertSame(2, (int) $fila->tipo_situacion);
        $this->assertNotNull($fila->updated_by, 'Editar una falta se firma; abrirla también.');
    }

    /**
     * §86.1 — Sin `dependencias`, que es un campo opcional del cuerpo.
     *
     * Antes: 500 `count(): Argument #1 ($value) must be of type Countable|array,
     * null given`, con el UPDATE ya escrito. Ahora: el mismo 200 que con la lista
     * vacía, porque no tener dependencias y tener una lista vacía **son lo mismo**.
     */
    public function test_editar_sin_dependencias_no_revienta(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $proceso = $this->unProceso($token, $ctx);

        $sinLaClave = $this->withToken($token)->putJson('/api/disciplina/update', [
            'id' => $proceso,
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'descripcion' => 'Sin la clave dependencias',
            'tipo_situacion' => 1,
        ]);

        $sinLaClave->assertStatus(200);
        $this->assertSame('Sin la clave dependencias',
            DB::table('dis_procesos')->where('id', $proceso)->value('descripcion'));

        // Y `dependencias: null` explícito, que es lo que manda un front que
        // limpia el formulario en vez de no mandar la clave.
        $this->withToken($token)->putJson('/api/disciplina/update', [
            'id' => $proceso,
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'descripcion' => 'Con dependencias en null',
            'tipo_situacion' => 1,
            'dependencias' => null,
        ])->assertStatus(200);

        $this->assertSame('Con dependencias en null',
            DB::table('dis_procesos')->where('id', $proceso)->value('descripcion'));
    }

    /** Y con dependencias de verdad el enlace se pone y se quita, que es su trabajo. */
    public function test_las_dependencias_enlazan_y_desenlazan_la_falta_anterior(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $anterior = $this->unProceso($token, $ctx);
        $nueva = $this->unProceso($token, $ctx);

        $cuerpo = [
            'id' => $nueva,
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'descripcion' => 'La que deriva de la anterior',
            'tipo_situacion' => 2,
        ];

        // `asignado` presente —da igual su valor— significa «esta falta lleva a la
        // que estoy editando». Se comprueba con `array_key_exists`, no con el valor.
        $this->withToken($token)->putJson('/api/disciplina/update',
            $cuerpo + ['dependencias' => [['id' => $anterior, 'asignado' => true]]])->assertStatus(200);

        $this->assertSame($nueva,
            (int) DB::table('dis_procesos')->where('id', $anterior)->value('become_id'));

        // Sin la clave `asignado`, el enlace se deshace.
        $this->withToken($token)->putJson('/api/disciplina/update',
            $cuerpo + ['dependencias' => [['id' => $anterior]]])->assertStatus(200);

        $this->assertNull(DB::table('dis_procesos')->where('id', $anterior)->value('become_id'));
    }

    /**
     * §86.2 — Un `alumno_id` que no existe: 404, no 500.
     *
     * Y la parte que importa del contrato: **la escritura del proceso sí ocurrió**.
     * El 404 habla de la ficha que no se puede devolver, no de la edición. Eso no
     * lo arregla un código de salida y por eso queda escrito aquí en vez de en un
     * comentario: quien reordene este método tiene que ver qué se pierde.
     */
    public function test_un_alumno_que_no_existe_es_404_aunque_la_falta_ya_se_haya_editado(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $proceso = $this->unProceso($token, $ctx);
        $inexistente = ((int) DB::table('alumnos')->max('id')) + 1000;

        $this->withToken($token)->putJson('/api/disciplina/update', [
            'id' => $proceso,
            'alumno_id' => $inexistente,
            'year_id' => $ctx->year_id,
            'descripcion' => 'Editada contra un alumno que no existe',
            'tipo_situacion' => 3,
            'dependencias' => [],
        ])->assertStatus(404);

        $this->assertSame('Editada contra un alumno que no existe',
            DB::table('dis_procesos')->where('id', $proceso)->value('descripcion'),
            'El UPDATE va antes de montar la respuesta: el 404 no lo deshace.');
    }

    /** Su hermana `destroy` comparte la consulta de la ficha, y ahora el 404. */
    public function test_borrar_contra_un_alumno_que_no_existe_tambien_es_404(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $proceso = $this->unProceso($token, $ctx);
        $inexistente = ((int) DB::table('alumnos')->max('id')) + 1000;

        $this->withToken($token)->putJson('/api/disciplina/destroy',
            ['proceso_id' => $proceso, 'alumno_id' => $inexistente])->assertStatus(404);

        // El borrado sí se hizo, por lo mismo que arriba.
        $this->assertNotNull(DB::table('dis_procesos')->where('id', $proceso)->value('deleted_at'));
    }

    /**
     * §86.3 — Y `store` con ese mismo alumno inexistente contesta otra cosa: 500.
     *
     * No llega a la ficha. La para **la clave ajena de `dis_procesos`**, tres
     * líneas antes, y eso deja un mensaje de MySQL con el nombre de la restricción
     * y el SQL entero dentro. Es la misma forma que la §78: tres rutas hermanas y
     * **lo que las separa no es el código sino el esquema**.
     *
     * Se mide y se anota, no se arregla: taparlo con una comprobación aquí y no en
     * las otras noventa escrituras del sistema es cambiar un 500 honesto por un
     * criterio que solo vale en un fichero.
     */
    public function test_abrir_una_falta_contra_un_alumno_que_no_existe_lo_para_el_esquema(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $antes = DB::table('dis_procesos')->count();
        $inexistente = ((int) DB::table('alumnos')->max('id')) + 1000;

        $this->withToken($token)->postJson('/api/disciplina/store', [
            'year_id' => $ctx->year_id,
            'alumno_id' => $inexistente,
            'periodo_id' => $ctx->periodo_id,
            'descripcion' => 'Contra un alumno que no existe',
            'tipo_situacion' => 1,
        ])->assertStatus(500);

        // Lo que salva la situación: no escribe. La restricción es de verdad.
        $this->assertSame($antes, DB::table('dis_procesos')->count());
    }

    /**
     * Y un alumno que existe pero no tiene matrícula viva cae por el mismo sitio.
     *
     * Es el camino que no se ve leyendo: la consulta de la ficha lleva
     * `inner join matriculas`, así que «no hay ficha» no significa «no hay alumno».
     * Un alumno retirado a mitad de año entra por aquí.
     */
    public function test_un_alumno_sin_matricula_viva_tambien_es_404(): void
    {
        $token = $this->tokenDelPersonal();
        $ctx = $this->alumnoConContexto();
        $proceso = $this->unProceso($token, $ctx);

        DB::table('matriculas')->where('alumno_id', $ctx->alumno_id)->update(['deleted_at' => now()]);

        $this->withToken($token)->putJson('/api/disciplina/update', [
            'id' => $proceso,
            'alumno_id' => $ctx->alumno_id,
            'year_id' => $ctx->year_id,
            'descripcion' => 'Sin matrícula viva',
            'tipo_situacion' => 1,
            'dependencias' => [],
        ])->assertStatus(404);
    }
}
