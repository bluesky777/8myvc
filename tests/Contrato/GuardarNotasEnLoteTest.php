<?php

namespace Tests\Contrato;

use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;

/**
 * `PUT notas/lote` — **una columna de notas se guarda con un recálculo, no con
 * treinta.**
 *
 * Lo pide la app (`myvc_flutter`). **Lo que lo justifica no es que recalcular
 * sea caro** —no lo es: `tools/coste-del-recalculo.php` lo midió en ~1,7 ms la
 * agregada, y el *3×* que parecía haber al estrecharla a un alumno resultó ser
 * la caché (02 §«el 3× que no existía»)—. Son dos cosas distintas:
 *
 * - **treinta peticiones son treinta veces el coste fijo de resolver quién
 *   pregunta**, que el 02 §4 mide en ~40–80 ms. Un orden de magnitud por encima
 *   del recálculo, y sin depender de ninguna caché;
 * - y **treinta transacciones independientes**, así que una columna a medio
 *   guardar deja definitivas calculadas sobre estados intermedios. Un lote es
 *   una transacción y un recálculo: entra entera o no entra. Eso no es
 *   velocidad, es corrección, y es lo que la fase 3 vino a cerrar.
 *
 * ## Por qué estos tests cuentan consultas
 *
 * Aunque el recálculo no sea lo caro, **agrupar por par (asignatura, periodo) es
 * la promesa de este endpoint**, y no se ve en la respuesta: un lote que
 * recalcule treinta veces devuelve el mismo `{"guardadas": 4}` que uno que
 * recalcule una. Un test que sólo mirara el cuerpo pasaría en verde con la
 * agrupación deshecha, que es un fallo fácil de reintroducir — basta llamar a
 * `recalcularPorNota` dentro del bucle porque «queda más simple».
 *
 * Así que se cuenta la agregación de `calcular()` por su firma en el SQL. Es la
 * misma idea que el resto de contrato: **mirar el resultado y no el estado**;
 * aquí el resultado que importa es el trabajo hecho.
 *
 * El montaje es el de `EditarUnaNotaActualizaLaDefinitivaTest` —dos unidades al
 * 50% y dos subunidades al 50%, para que cada nota pese un cuarto exacto— pero
 * guarda **las notas de los dos alumnos**, porque un lote de una columna las
 * toca a todas y eso es lo que hay que medir.
 */
class GuardarNotasEnLoteTest extends CasoDeContrato
{
    /**
     * La firma en el SQL de la agregación de `DefinitivasDeAsignatura::calcular()`.
     *
     * Se elige un trozo de la **fórmula** y no del `FROM`: los nombres de tabla
     * salen en media docena de consultas del recálculo —el UPSERT, el sello, el
     * porcentaje— y contarlas todas mediría otra cosa. Este producto sólo aparece
     * en el agregado, que es lo caro y lo que este endpoint viene a hacer una vez.
     */
    private const FIRMA_DEL_AGREGADO = '(s.porcentaje / 100) * n.nota';

    /**
     * Cuatro notas en un lote, **un solo agregado**. Es el endpoint entero.
     *
     * El contraste va en el mismo test y a propósito: primero se mide lo que
     * cuestan las mismas cuatro notas de una en una, y después lo que cuestan en
     * lote. Dos números en la misma ejecución, sobre el mismo montaje, es lo
     * único que demuestra el ahorro — un número suelto no dice de qué se ahorra.
     */
    public function test_un_lote_recalcula_una_vez_y_de_una_en_una_recalcula_por_nota(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $sueltas = $this->agregadosDurante(function () use ($token, $ctx) {
            foreach ($ctx['notas'] as $notaId) {
                $this->withToken($token)
                    ->putJson('/api/notas/update/'.$notaId, ['nota' => 40])
                    ->assertStatus(200);
            }
        });

        $this->assertSame(4, $sueltas,
            'El montaje ya no mide lo que cree: cuatro `notas/update` tienen que dar cuatro agregados.');

        // 45 y no 60. El 60 era un número cualquiera —sólo tenía que diferir del
        // 40 de arriba— y desde el 24 ago **no cabe en la escala**: la de este
        // colegio va de 0 a 50 y `EscalaDeNotas` rechaza lo que se pase, así que
        // el lote entero caía en `fallidas` y recalculaba cero veces. El test
        // seguía siendo correcto; el dato dejó de serlo.
        $enLote = $this->agregadosDurante(function () use ($token, $ctx) {
            $this->withToken($token)->putJson('/api/notas/lote', [
                'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 45], $ctx['notas']),
            ])->assertStatus(200);
        });

        $this->assertSame(1, $enLote,
            'El lote recalculó '.$enLote.' veces en vez de una: la agrupación por '
            .'(asignatura, periodo) se deshizo y el endpoint ya no ahorra nada.');
    }

    /**
     * Y las notas quedan guardadas, con la definitiva que sale de la cuenta.
     *
     * Sin esto, lo de arriba lo cumpliría un endpoint que no escribiera nada: un
     * agregado, cero notas. **Contar el trabajo y comprobar el resultado son dos
     * tests y hacen falta los dos.**
     */
    public function test_el_lote_guarda_las_notas_y_mueve_la_definitiva(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $this->assertSame(20.0, $this->definitivaDe($ctx, $ctx['alumno']));

        $cuerpo = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 40], $ctx['notas']),
        ])->assertStatus(200)->json();

        $this->assertSame(4, $cuerpo['guardadas']);
        $this->assertSame([], $cuerpo['fallidas']);

        foreach ($ctx['notas'] as $notaId) {
            $this->assertSame(40, (int) DB::table('notas')->where('id', $notaId)->value('nota'));
        }

        // Las cuatro a 40, cada una un cuarto: 40.
        $this->assertSame(40.0, $this->definitivaDe($ctx, $ctx['alumno']));
    }

    /**
     * Un lote que toca a **varios alumnos** recalcula igual una sola vez, y las
     * definitivas de todos quedan bien.
     *
     * Éste es el caso real —una columna es un alumno por fila— y es el que
     * distingue este endpoint de un bucle: `recalcular()` sin `soloAlumno` agrega
     * a los dos alumnos en la **misma** consulta, mientras que pedirlos de uno en
     * uno serían dos. Con un solo alumno el test no vería la diferencia.
     */
    public function test_un_lote_de_varios_alumnos_recalcula_una_vez_para_todos(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $todas = array_merge($ctx['notas'], $ctx['notas_del_otro']);

        $agregados = $this->agregadosDurante(function () use ($token, $todas) {
            $this->withToken($token)->putJson('/api/notas/lote', [
                'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 40], $todas),
            ])->assertStatus(200)->assertJson(['guardadas' => 8]);
        });

        $this->assertSame(1, $agregados,
            'Ocho notas de dos alumnos dieron '.$agregados.' agregados: se está recalculando por alumno.');

        $this->assertSame(40.0, $this->definitivaDe($ctx, $ctx['alumno']));
        $this->assertSame(40.0, $this->definitivaDe($ctx, $ctx['otro_alumno']),
            'El segundo alumno del lote se quedó con la definitiva vieja.');
    }

    /**
     * **Una nota inventada no tumba el lote.**
     *
     * Es la razón de que la respuesta lleve `fallidas` y no sea un 422 a secas: la
     * app reintenta sólo lo que falló sin que el docente vuelva a teclear nada, y
     * para eso necesita saber **cuáles**. Que una columna entera se pierda por un
     * id viejo sería peor que guardar de una en una.
     */
    public function test_una_nota_que_no_existe_se_reporta_y_las_demas_se_guardan(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $inventada = 1 + (int) DB::table('notas')->max('id');

        $cuerpo = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [
                ['id' => $ctx['notas'][0], 'nota' => 40],
                ['id' => $inventada, 'nota' => 40],
                ['id' => $ctx['notas'][1], 'nota' => 40],
            ],
        ])->assertStatus(200)->json();

        $this->assertSame(2, $cuerpo['guardadas']);
        $this->assertCount(1, $cuerpo['fallidas']);
        $this->assertSame($inventada, $cuerpo['fallidas'][0]['id']);

        $this->assertSame(40, (int) DB::table('notas')->where('id', $ctx['notas'][0])->value('nota'));
        $this->assertSame(40, (int) DB::table('notas')->where('id', $ctx['notas'][1])->value('nota'));
    }

    /**
     * **Con el periodo cerrado no se escribe ni una.**
     *
     * El permiso se comprueba una sola vez y **antes** de la primera escritura,
     * que es lo que hace que un lote sea todo o nada. Por eso el test no mira el
     * código de la respuesta sino las notas: un 400 con la mitad de la columna ya
     * guardada cumpliría el aserto del código y sería el fallo entero.
     */
    public function test_con_el_periodo_cerrado_el_lote_no_escribe_nada(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        DB::table('periodos')->where('id', $ctx['periodo'])
            ->update(['profes_pueden_editar_notas' => 0]);

        $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 99], $ctx['notas']),
        ])->assertStatus(400);

        foreach ($ctx['notas'] as $notaId) {
            $this->assertSame(20, (int) DB::table('notas')->where('id', $notaId)->value('nota'),
                'Se escribió una nota en un periodo cerrado: el permiso se está comprobando tarde.');
        }
    }

    /**
     * Y con el periodo **abierto**, un lote de un solo periodo pasa — que suena a
     * perogrullada y es la trampa que más cerca estuvo de hundir esto.
     *
     * `User::aplicarBanderasDelPeriodo` decide con `count($filas) === count($ids)`
     * para que un periodo borrado debajo de una fila cuente como cerrado en vez de
     * regalar permiso. Si el controlador le pasa la lista **sin deduplicar**,
     * treinta notas del mismo periodo son treinta ids contra **una** fila, la
     * cuenta no cuadra y la comprobación deniega el lote entero. O sea que el caso
     * normal de este endpoint —una columna, un periodo— sería el único que nunca
     * pasa, y con un 400 que no significa nada.
     *
     * Los tests de arriba lo cazarían de rebote; éste dice **por qué** para que
     * quien lo ponga en rojo sepa dónde mirar.
     */
    public function test_muchas_notas_del_mismo_periodo_no_se_deniegan_entre_ellas(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $todas = array_merge($ctx['notas'], $ctx['notas_del_otro']);

        $this->assertGreaterThan(1, count($todas), 'El montaje necesita varias notas del mismo periodo.');

        $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 40], $todas),
        ])->assertStatus(200);
    }

    /**
     * **La bitácora del lote es la misma que la de `putUpdate`.**
     *
     * Es el rastro que mira el colegio cuando una familia reclama una nota, y el
     * historial de la app lo lee. Si el lote dejara un rastro distinto, cambiar de
     * pantalla cambiaría lo que quedó escrito de la misma acción.
     *
     * Se comparan las dos filas **columna a columna** en vez de comprobar que
     * «hay una bitácora»: lo que se puede perder por el camino es un campo
     * —`affected_element_old_value_int`, que es el valor viejo y el que hace útil
     * el rastro—, y contar filas no lo vería.
     */
    public function test_la_bitacora_del_lote_es_identica_a_la_de_editar_una_nota(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $this->withToken($token)
            ->putJson('/api/notas/update/'.$ctx['notas'][0], ['nota' => 40])
            ->assertStatus(200);

        $deUpdate = $this->ultimaBitacoraDe($ctx['notas'][0]);

        $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [['id' => $ctx['notas'][1], 'nota' => 40]],
        ])->assertStatus(200);

        $deLote = $this->ultimaBitacoraDe($ctx['notas'][1]);

        $this->assertNotNull($deLote, 'El lote no dejó bitácora.');

        foreach (['created_by', 'historial_id', 'affected_user_id', 'affected_person_type',
            'affected_element_type', 'affected_element_new_value_int',
            'affected_element_old_value_int'] as $columna) {
            $this->assertSame($deUpdate->{$columna}, $deLote->{$columna},
                'La bitácora del lote difiere de la de putUpdate en `'.$columna.'`.');
        }

        // Y el id afectado es el de SU nota, no el de la otra: comparar las dos
        // filas columna a columna no lo cogería, porque ahí tienen que diferir.
        $this->assertSame($ctx['notas'][1], (int) $deLote->affected_element_id);
    }

    /**
     * Una bitácora por nota, no una por lote.
     *
     * Separado del anterior porque son dos promesas distintas: aquélla es que el
     * rastro **dice lo mismo**, ésta es que **hay uno por cada nota**. Un lote que
     * anotara una sola línea «se guardaron cuatro notas» pasaría la primera.
     */
    public function test_el_lote_deja_una_bitacora_por_nota(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $antes = (int) DB::table('bitacoras')->where('affected_element_type', 'Nota')->count();

        $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 40], $ctx['notas']),
        ])->assertStatus(200);

        $this->assertSame(4, (int) DB::table('bitacoras')
            ->where('affected_element_type', 'Nota')->count() - $antes);
    }

    /**
     * **Un usuario sin historial guarda igual, y deja su bitácora con
     * `historial_id` en null.**
     *
     * `putUpdate` saca el historial con un cross join dentro del SELECT de la
     * nota, así que quien no tenga ninguna sesión registrada no trae fila, el
     * `[0]` revienta y la respuesta es un **422 «no se pudo guardar la nota»**
     * sobre una nota que se podía guardar perfectamente. `bitacoras.historial_id`
     * admite null, así que aquí se pide aparte y se acepta que falte.
     *
     * El test **borra los historiales del usuario** en vez de buscar uno sin
     * ellos: entrar para conseguir el token crea uno, así que un usuario sin
     * historial no existe en el momento en que hace falta. Es la misma regla del
     * 09 — si lo que falta es la fila, se monta.
     */
    public function test_sin_historial_el_lote_guarda_y_anota_con_historial_nulo(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $userId = (int) DB::table('users')->where('username', $ctx['username'])->value('id');
        DB::table('historiales')->where('user_id', $userId)->delete();

        $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [['id' => $ctx['notas'][0], 'nota' => 40]],
        ])->assertStatus(200)->assertJson(['guardadas' => 1]);

        $this->assertSame(40, (int) DB::table('notas')->where('id', $ctx['notas'][0])->value('nota'));

        $bitacora = $this->ultimaBitacoraDe($ctx['notas'][0]);

        $this->assertNotNull($bitacora, 'Sin historial no se anotó la bitácora, y eso sí se puede.');
        $this->assertNull($bitacora->historial_id);
    }

    /**
     * Un cuerpo sin lista de notas es un 422, no un 500.
     *
     * Y el tope tampoco es capricho: sin él, un cuerpo con cien mil ids es un
     * bucle de cien mil consultas dentro de una transacción abierta.
     */
    public function test_un_cuerpo_sin_notas_o_demasiado_grande_contesta_422(): void
    {
        [$token] = $this->asignaturaConNotas();

        $this->withToken($token)->putJson('/api/notas/lote', [])->assertStatus(422);
        $this->withToken($token)->putJson('/api/notas/lote', ['notas' => []])->assertStatus(422);

        $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_fill(0, 201, ['id' => 1, 'nota' => 10]),
        ])->assertStatus(422);
    }

    /**
     * **La respuesta trae las definitivas ya recalculadas**, para que la planilla
     * no pida una más por celda.
     *
     * Es el mismo contrato que `putUpdate`, en plural: aquélla devuelve
     * `definitiva` porque toca a un alumno, y un lote es una columna. Que las dos
     * digan lo mismo es lo que evita que el front acabe con dos ideas distintas
     * de la misma cosa según por dónde haya guardado.
     */
    public function test_la_respuesta_del_lote_trae_las_definitivas_de_los_alumnos_tocados(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        $todas = array_merge($ctx['notas'], $ctx['notas_del_otro']);

        $cuerpo = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 40], $todas),
        ])->assertStatus(200)->json();

        $this->assertCount(2, $cuerpo['definitivas'],
            'El lote tocó a dos alumnos y no devolvió dos definitivas.');

        $porAlumno = [];

        foreach ($cuerpo['definitivas'] as $definitiva) {
            $porAlumno[$definitiva['alumno_id']] = $definitiva;
        }

        foreach ([$ctx['alumno'], $ctx['otro_alumno']] as $alumnoId) {
            $this->assertArrayHasKey($alumnoId, $porAlumno);
            $this->assertSame(40, $porAlumno[$alumnoId]['nota']);
            $this->assertSame($ctx['asignatura'], $porAlumno[$alumnoId]['asignatura_id']);
            $this->assertSame($ctx['periodo'], $porAlumno[$alumnoId]['periodo_id']);
            $this->assertFalse($porAlumno[$alumnoId]['manual']);

            // Y coincide con la tabla, que es lo que separa «devolver un número»
            // de «devolver el número que se acaba de escribir».
            $this->assertSame((float) $porAlumno[$alumnoId]['nota'],
                $this->definitivaDe($ctx, $alumnoId));
        }
    }

    /**
     * Y si la definitiva es **manual**, la respuesta trae **la guardada**.
     *
     * Misma promesa que en `putUpdate` y por la misma razón: el servicio no pisa
     * las filas `manual`, así que lo calculado no es lo que hay. Devolver lo
     * calculado haría que la celda pintara un número que la base no tiene, y al
     * recargar volvería el de verdad.
     */
    public function test_una_definitiva_manual_vuelve_tal_cual_en_el_lote(): void
    {
        [$token, $ctx] = $this->asignaturaConNotas();

        DB::table('notas_finales')
            ->where('alumno_id', $ctx['alumno'])
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->update(['nota' => 99, 'manual' => 1]);

        $cuerpo = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => array_map(fn ($id) => ['id' => $id, 'nota' => 40], $ctx['notas']),
        ])->assertStatus(200)->json();

        $suya = null;

        foreach ($cuerpo['definitivas'] as $definitiva) {
            if ($definitiva['alumno_id'] === $ctx['alumno']) {
                $suya = $definitiva;
            }
        }

        $this->assertNotNull($suya);
        $this->assertSame(99, $suya['nota'],
            'Devolvió lo calculado en vez de lo guardado: la celda pintaría un número que no existe.');
        $this->assertTrue($suya['manual']);

        $this->assertSame(99.0, $this->definitivaDe($ctx, $ctx['alumno']),
            'Y además la pisó, que es lo que el servicio promete no hacer.');
    }

    /**
     * **La respuesta trae siempre las tres claves**, incluso cuando no se guardó
     * ninguna nota.
     *
     * Un cuerpo al que le falta `definitivas` obliga al front a distinguir
     * «vacío» de «no vino en la respuesta», y las dos cosas se pintan distinto.
     * Es la misma razón por la que el alumno sin fila viaja con `nota: null` en
     * vez de omitirse.
     *
     * El caso lo dispara un lote **entero de ids inventados**: es el que sale por
     * la puerta de atrás del método, antes de comprobar permisos y de escribir
     * nada, y por eso es el que se dejaba una clave por el camino.
     */
    public function test_la_respuesta_trae_las_tres_claves_aunque_no_se_guarde_nada(): void
    {
        [$token] = $this->asignaturaConNotas();

        $inventada = 1 + (int) DB::table('notas')->max('id');

        $cuerpo = $this->withToken($token)->putJson('/api/notas/lote', [
            'notas' => [['id' => $inventada, 'nota' => 40]],
        ])->assertStatus(200)->json();

        $this->assertSame(['guardadas', 'fallidas', 'definitivas'], array_keys($cuerpo),
            'La respuesta cambió de claves cuando no se guardó nada.');

        $this->assertSame(0, $cuerpo['guardadas']);
        $this->assertCount(1, $cuerpo['fallidas']);
        $this->assertSame([], $cuerpo['definitivas']);
    }

    /**
     * Cuántas veces corrió la agregación de `calcular()` mientras se hacía algo.
     */
    private function agregadosDurante(callable $accion): int
    {
        $cuantos = 0;

        DB::listen(function ($consulta) use (&$cuantos) {
            if (str_contains($consulta->sql, self::FIRMA_DEL_AGREGADO)) {
                $cuantos++;
            }
        });

        $accion();

        // No hay `DB::unlisten`, así que el oyente se queda hasta el final del
        // test. Da igual: cada test tiene su contador y su cierre de base.
        return $cuantos;
    }

    private function ultimaBitacoraDe(int $notaId): ?object
    {
        return DB::table('bitacoras')
            ->where('affected_element_type', 'Nota')
            ->where('affected_element_id', $notaId)
            ->orderByDesc('id')
            ->first();
    }

    private function definitivaDe(array $ctx, int $alumnoId): ?float
    {
        $valor = DB::table('notas_finales')
            ->where('alumno_id', $alumnoId)
            ->where('asignatura_id', $ctx['asignatura'])
            ->where('periodo_id', $ctx['periodo'])
            ->value('nota');

        return $valor === null ? null : (float) $valor;
    }

    /**
     * Dos unidades al 50%, dos subunidades al 50% en cada una, y una nota de 20
     * por subunidad para **dos** alumnos — con los ids de las notas de los dos.
     *
     * Es el montaje de `EditarUnaNotaActualizaLaDefinitivaTest` con una diferencia
     * que aquí es el sujeto: allí sólo hacían falta las notas de un alumno, y un
     * lote de verdad es una **columna**, o sea todos los alumnos del grupo a la
     * vez. Sin las del segundo no se puede comprobar lo único que distingue este
     * endpoint de un bucle.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function asignaturaConNotas(): array
    {
        // Profesor y periodo abierto: `pueden_editar_notas` sólo deja pasar a un
        // `Profesor` o a un superusuario, y además mira el interruptor del periodo
        // (§27). Con cualquiera de las dos mal, estos tests fallarían por el guard
        // y no por el lote.
        $profesor = $this->usuarioDeTipo('Profesor');
        $token = $this->tokenDe($profesor->username);

        $suyo = DB::selectOne('SELECT p.id, p.year_id FROM periodos p
            INNER JOIN users u ON u.periodo_id = p.id WHERE u.id = ?', [$profesor->id]);

        $this->assertNotNull($suyo, 'El profesor del seed se quedó sin periodo al entrar.');

        DB::table('periodos')->where('year_id', $suyo->year_id)
            ->update(['profes_pueden_editar_notas' => 1]);

        $periodoId = (int) $suyo->id;

        $asignatura = DB::selectOne('SELECT a.id, a.grupo_id FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN periodos p ON p.year_id = g.year_id AND p.id = ?
            WHERE a.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM matriculas m WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
            ) ORDER BY a.id LIMIT 1', [$periodoId]);

        $this->assertNotNull($asignatura, 'El seed no tiene una asignatura con matrículas en el año del usuario.');

        $alumnos = DB::select('SELECT DISTINCT m.alumno_id FROM matriculas m
            WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$asignatura->grupo_id]);

        $this->assertCount(2, $alumnos, 'El montaje necesita dos alumnos matriculados.');

        $notas = [];
        $notasDelOtro = [];

        foreach ([1, 2] as $numeroUnidad) {
            $unidadId = DB::table('unidades')->insertGetId([
                'asignatura_id' => $asignatura->id,
                'periodo_id' => $periodoId,
                'definicion' => 'UNIDAD DE PRUEBA '.$numeroUnidad,
                'porcentaje' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([1, 2] as $numeroSub) {
                $subId = DB::table('subunidades')->insertGetId([
                    'unidad_id' => $unidadId,
                    'definicion' => 'SUB '.$numeroUnidad.'.'.$numeroSub,
                    'porcentaje' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($alumnos as $alumno) {
                    $notaId = DB::table('notas')->insertGetId([
                        'subunidad_id' => $subId,
                        'alumno_id' => $alumno->alumno_id,
                        'nota' => 20,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ((int) $alumno->alumno_id === (int) $alumnos[0]->alumno_id) {
                        $notas[] = $notaId;
                    } else {
                        $notasDelOtro[] = $notaId;
                    }
                }
            }
        }

        // El estado de partida lo deja el propio servicio: así los asertos sobre la
        // definitiva miden el DISPARADOR y no el cálculo.
        DefinitivasDeAsignatura::recalcular(
            (int) $asignatura->id, $periodoId, (int) $profesor->id
        );

        return [$token, [
            'asignatura' => (int) $asignatura->id,
            'periodo' => $periodoId,
            'alumno' => (int) $alumnos[0]->alumno_id,
            'otro_alumno' => (int) $alumnos[1]->alumno_id,
            'notas' => $notas,
            'notas_del_otro' => $notasDelOtro,
            'username' => $profesor->username,
        ]];
    }
}
