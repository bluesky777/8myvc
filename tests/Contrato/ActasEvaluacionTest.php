<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * El acta de evaluación y promoción: el documento que cierra el año lectivo.
 *
 * Es el informe con más cuentas del sistema y el único que se puede comprobar
 * sin saber nada del negocio, porque **lleva sus propias identidades escritas**:
 * `resumen.cuadra` y `promocion.cuadra`. El controlador las calcula recorriendo
 * una sola vez el mismo arreglo de matrículas, precisamente para que el cuadre
 * salga por construcción, y guarda en `ids` las matrículas que componen cada
 * número para que la pantalla pueda abrir la lista exacta detrás de cada celda.
 *
 * Un test de forma sobre esto sería flojo: la respuesta puede conservar todas
 * sus claves y traer los números mal. Así que aquí, además del snapshot, se
 * comprueban las dos identidades y se recalculan los totales desde los `ids`.
 * Un número que no se puede reconciliar contando nombres está mal, y eso vale
 * también para el test que lo mira.
 */
class ActasEvaluacionTest extends CasoDeContrato
{
    private function acta(): array
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $r = $this->putJson('/api/actas-evaluacion/acta-evaluacion-promocion', [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $grupos = $r->json('grupos');

        $this->assertNotEmpty($grupos, 'El acta salió sin grupos.');

        return [$r, $grupo, $token];
    }

    // ------------------------------------------------------------- La forma

    public function test_la_forma_del_acta_de_evaluacion_y_promocion(): void
    {
        [$r] = $this->acta();

        $cuerpo = $r->json();

        $this->assertSame(
            ['grupos', 'year', 'periodos', 'usa_areas', 'hay_corte', 'consolidado', 'duplicados', 'firmantes'],
            array_keys($cuerpo),
            'Cambió el juego de bloques del acta, o su orden.');

        $this->assertNotEmpty($cuerpo['grupos'][0]['alumnos'], 'El primer grupo salió sin alumnos.');

        // formaUnida y no forma: el acta ordena por apellidos y nombres, y el
        // seed anonimizado tiene ocho alumnos llamados igual. Con `forma()` el
        // snapshot describía la fila que MySQL pusiera primera, y cambiaba sola
        // entre corridas. Ver el comentario de formaUnida() en CasoDeContrato.
        $this->compararConInstantanea('actas-evaluacion-promocion', $this->formaUnida($cuerpo));
    }

    // --------------------------------------------------------- Las dos identidades

    /**
     * `iniciaron + ingresaron + sin_fecha + sin_clasificar - retirados - desertores = terminaron`.
     *
     * No se comprueba mirando `cuadra`, que es lo que el propio controlador dice
     * de sí mismo: se rehace la resta aquí. Si alguien cambia la fórmula de
     * `$descuadre` y la de los contadores a la vez, `cuadra` seguiría diciendo
     * que sí y este test diría que no, que es el punto de tenerlo.
     */
    public function test_el_movimiento_de_cada_grupo_cuadra(): void
    {
        [$r] = $this->acta();

        foreach ($r->json('grupos') as $grupo) {
            $m = $grupo['resumen'];

            $esperado = $m['iniciaron']['total'] + $m['ingresaron']['total']
                + $m['sin_fecha_matricula']['total'] + $m['sin_clasificar']['total']
                - $m['retirados']['total'] - $m['desertores']['total'];

            $this->assertSame($esperado, $m['terminaron']['total'],
                "El movimiento del grupo {$grupo['nombre']} no cuadra.");

            $this->assertSame(0, $m['descuadre']);
            $this->assertTrue($m['cuadra']);

            $this->assertSame(count($grupo['alumnos']), $m['total_matriculas']['total'],
                'El total de matrículas no es el número de alumnos impresos. Son dos poblaciones '.
                'distintas en el mismo documento, que es justo lo que este controlador vino a arreglar.');
        }
    }

    /** Promovidos + no promovidos + pendientes + sin definir = evaluados. */
    public function test_la_promocion_de_cada_grupo_cuadra(): void
    {
        [$r] = $this->acta();

        foreach ($r->json('grupos') as $grupo) {
            $p = $grupo['promocion'];

            $this->assertSame(
                $p['total_promovidos']['total'] + $p['total_no_promovidos']['total']
                    + $p['pendientes']['total'] + $p['sin_definir']['total'],
                $p['evaluados']['total'],
                "La promoción del grupo {$grupo['nombre']} no cuadra.");

            $this->assertSame(0, $p['descuadre']);
            $this->assertTrue($p['cuadra']);

            // Los que se fueron no se evalúan: es lo que hacía que "Total PROMOVIDOS"
            // superara a "terminaron" en la versión anterior.
            $this->assertSame($grupo['resumen']['terminaron']['total'], $p['evaluados']['total'],
                'Se está evaluando a alguien que se fue del colegio.');
        }
    }

    /**
     * Cada número trae la lista de matrículas que lo compone, y son esas.
     *
     * `ids` es la razón de ser del rediseño: la pantalla abre detrás de cada
     * celda exactamente las filas que la produjeron. Si `total` e `ids` se
     * separan, la celda dice 12 y la lista enseña 9, y nadie sabe cuál de los
     * dos miente.
     *
     * Se recorren los grupos y **no el consolidado**, que trae los `ids` vacíos
     * a propósito: la lista de un total institucional no cabe en un modal y
     * dispararía el tamaño de la respuesta. El detalle se consulta por grupo.
     * Si algún día se acumulan también ahí, este test no se entera; el de abajo
     * sí, porque compara totales.
     */
    public function test_cada_contador_trae_los_ids_que_lo_componen(): void
    {
        [$r] = $this->acta();

        $revisados = 0;

        foreach ($r->json('grupos') as $grupo) {
            foreach (['resumen', 'promocion'] as $bloque) {
                foreach ($grupo[$bloque] as $nombre => $contador) {
                    if (! is_array($contador) || ! array_key_exists('ids', $contador)) {
                        continue;   // descuadre y cuadra no son contadores
                    }

                    $this->assertCount($contador['total'], $contador['ids'],
                        "En {$grupo['nombre']}, `{$bloque}.{$nombre}` dice {$contador['total']} ".
                        'y trae '.count($contador['ids']).' ids.');

                    $this->assertSame(
                        $contador['m'] + $contador['f'] + $contador['sd'],
                        $contador['total'],
                        "En {$grupo['nombre']}, `{$bloque}.{$nombre}` no reparte el total por sexo.");

                    $revisados++;
                }
            }
        }

        $this->assertGreaterThan(0, $revisados, 'No se revisó ningún contador.');
    }

    /**
     * Las tres salidas del año son una partición: cada matrícula está en una y solo una.
     *
     * Se comprueba con los ids y no con los totales porque los totales pueden
     * cuadrar sumando dos veces al mismo alumno y saltándose a otro.
     */
    public function test_retirados_desertores_y_terminaron_reparten_a_todos_sin_repetir(): void
    {
        [$r] = $this->acta();

        foreach ($r->json('grupos') as $grupo) {
            $m = $grupo['resumen'];

            $repartidos = array_merge(
                $m['retirados']['ids'], $m['desertores']['ids'], $m['terminaron']['ids']);

            sort($repartidos);
            $todos = $m['total_matriculas']['ids'];
            sort($todos);

            $this->assertSame($todos, $repartidos,
                "En {$grupo['nombre']} hay matrículas contadas dos veces o ninguna.");
        }
    }

    /**
     * El consolidado es la suma de los grupos, contador a contador.
     *
     * El acta nunca tuvo totales de institución: había cuadros por grupo y el
     * rector sumaba a mano. Ahora los trae, y como se calculan en un segundo
     * recorrido —no salen del mismo bucle que los de grupo— son el único número
     * del informe que puede desviarse sin que ninguna de las dos identidades
     * `cuadra` se entere.
     */
    public function test_el_consolidado_es_la_suma_de_los_grupos(): void
    {
        [$r] = $this->acta();

        $grupos = $r->json('grupos');

        foreach (['resumen', 'promocion'] as $bloque) {
            foreach ($r->json("consolidado.{$bloque}") as $nombre => $contador) {
                if (! is_array($contador)) {
                    continue;   // descuadre y cuadra
                }

                foreach (['total', 'm', 'f', 'sd'] as $campo) {
                    $suma = array_sum(array_map(fn ($g) => $g[$bloque][$nombre][$campo], $grupos));

                    $this->assertSame($suma, $contador[$campo],
                        "consolidado.{$bloque}.{$nombre}.{$campo} no es la suma de los grupos.");
                }
            }
        }
    }

    /**
     * Dos matrículas del mismo alumno en el mismo grupo salen denunciadas.
     *
     * No hay `UNIQUE(alumno_id, grupo_id)` en `matriculas`, solo índices
     * simples, así que el caso es posible en producción; y como todos los
     * conteos del acta son sobre filas, el alumno duplicado se cuenta dos veces
     * en cada cuadro. El acta lo denuncia en vez de sumar callada, y ese aviso
     * no lo ejercita nadie: el seed viene de una base sin duplicados y
     * `duplicados` sale siempre vacío. Aquí se construye el caso dentro de la
     * transacción del test.
     *
     * Y se comprueba también lo otro: que con el duplicado dentro las dos
     * identidades siguen cuadrando. Tienen que hacerlo —cuentan filas, y hay una
     * fila más— y es lo que demuestra que `cuadra` no basta para fiarse de los
     * números, que es exactamente para lo que existe la lista de duplicados.
     */
    public function test_una_matricula_duplicada_sale_en_la_lista_de_duplicados(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $original = DB::selectOne('SELECT alumno_id FROM matriculas
            WHERE grupo_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$grupo->id]);

        DB::insert('INSERT INTO matriculas (alumno_id, grupo_id, estado, created_at, updated_at)
            VALUES (?, ?, "MATR", ?, ?)', [$original->alumno_id, $grupo->id, now(), now()]);

        $r = $this->putJson('/api/actas-evaluacion/acta-evaluacion-promocion', [],
            ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $duplicados = $r->json('duplicados');

        $this->assertCount(1, $duplicados, 'El acta no denunció la matrícula duplicada.');
        $this->assertSame($original->alumno_id, $duplicados[0]['alumno_id']);
        $this->assertSame((int) $grupo->id, $duplicados[0]['grupo_id']);

        foreach ($r->json('grupos') as $g) {
            $this->assertTrue($g['resumen']['cuadra'],
                'El duplicado descuadró el movimiento; debería contarse dos veces y cuadrar igual.');
            $this->assertTrue($g['promocion']['cuadra']);
        }
    }

    /**
     * Sin calendario de periodos, el acta no clasifica las entradas: lo dice.
     *
     * El corte entre «inició el año» e «ingresó después» es el fin del primer
     * periodo, y `periodos.fecha_fin` es nullable. El año del seed la tiene en
     * null en los cuatro periodos, así que `hay_corte` es false y todas las
     * matrículas van a `sin_clasificar` en vez de repartirse a ojo.
     *
     * Es el comportamiento que el controlador escogió a propósito —«una fila cuya
     * etiqueta miente es peor que una fila ausente»— y no se ve en ningún test
     * si nadie lo mira, porque el acta responde 200 igual. Y es también la razón
     * de que `iniciaron` e `ingresaron` salgan en cero en el snapshot: no es que
     * el informe esté vacío.
     */
    public function test_sin_fechas_de_periodo_el_acta_manda_todo_a_sin_clasificar(): void
    {
        [$r] = $this->acta();

        $conFecha = (int) DB::selectOne('SELECT COUNT(*) n FROM periodos
            WHERE year_id = ? AND deleted_at IS NULL AND fecha_fin IS NOT NULL
            ORDER BY numero LIMIT 1', [$this->grupoConAlumnos()->year_id])->n;

        if ($conFecha > 0) {
            $this->markTestSkipped('El seed ya trae el calendario de periodos lleno.');
        }

        $this->assertFalse($r->json('hay_corte'));

        foreach ($r->json('grupos') as $grupo) {
            $m = $grupo['resumen'];

            $this->assertSame(0, $m['iniciaron']['total']);
            $this->assertSame(0, $m['ingresaron']['total']);
            $this->assertSame(
                $m['total_matriculas']['total'] - $m['sin_fecha_matricula']['total'],
                $m['sin_clasificar']['total'],
                'Sin corte, todo lo que tiene fecha debería quedar sin clasificar.');
        }
    }

    /**
     * Un año sin periodos responde 422 en vez de reventar.
     *
     * Antes era `$periodos[0]` a pelo. El caso se construye borrando los
     * periodos dentro de la transacción del test, que es la única forma de
     * llegar a él: el seed viene de una base real y ninguna tiene un año así.
     * El token se pide ANTES del borrado a propósito — el contexto del usuario
     * se resuelve por su periodo, y sin periodos no habría con qué entrar.
     */
    public function test_un_year_sin_periodos_responde_422(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        DB::update('UPDATE periodos SET deleted_at = ? WHERE year_id = ? AND deleted_at IS NULL',
            [now(), $grupo->year_id]);

        $this->putJson('/api/actas-evaluacion/acta-evaluacion-promocion', [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('msg',
                'El año lectivo no tiene periodos definidos, y el acta se calcula contra el calendario de periodos.');
    }

    // -------------------------------------------------- Texto y firmantes del acta

    /**
     * Guardar el texto del acta, que es la ruta que faltaba.
     *
     * La pantalla llamaba a `actas-evaluacion/cambiar-descripcion` desde siempre
     * y la ruta no existía: guardar fallaba con 404 en silencio. El test lee el
     * valor de vuelta desde `years` en vez de conformarse con el `ok: true`.
     */
    public function test_el_texto_del_acta_se_guarda(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $texto = 'Acta de prueba del test de contrato.';

        $this->putJson('/api/actas-evaluacion/cambiar-descripcion', ['texto_acta_eval' => $texto],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertSame($texto,
            DB::selectOne('SELECT texto_acta_eval t FROM years WHERE id = ?', [$grupo->year_id])->t);
    }

    /**
     * Los firmantes se normalizan antes de guardarse: no se almacena lo que llegue.
     *
     * Se guardan como JSON en `years.firmantes_acta`, y lo que entra es un
     * arreglo que manda el navegador. Sin recorte, un campo de texto libre acaba
     * metiendo en la columna lo que quien firme pegue dentro. Se comprueban las
     * tres reglas: recorte a 150/100/50, descarte de las filas sin nombre ni
     * cargo, y que solo queden esas tres claves.
     */
    public function test_los_firmantes_se_normalizan_al_guardarse(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $this->putJson('/api/actas-evaluacion/cambiar-descripcion', [
            'firmantes_acta' => [
                ['nombre' => '  '.str_repeat('a', 200).'  ', 'cargo' => str_repeat('b', 200),
                    'documento' => str_repeat('9', 80), 'colado' => 'no debería llegar'],
                ['nombre' => '   ', 'cargo' => '', 'documento' => '123'],
                ['nombre' => 'Rectora'],
            ],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $guardados = json_decode(
            DB::selectOne('SELECT firmantes_acta f FROM years WHERE id = ?', [$grupo->year_id])->f,
            true);

        $this->assertCount(2, $guardados, 'La fila sin nombre ni cargo no se descartó.');

        $this->assertSame(['nombre', 'cargo', 'documento'], array_keys($guardados[0]));
        $this->assertSame(150, mb_strlen($guardados[0]['nombre']));
        $this->assertSame(100, mb_strlen($guardados[0]['cargo']));
        $this->assertSame(50, mb_strlen($guardados[0]['documento']));

        $this->assertSame(['nombre' => 'Rectora', 'cargo' => '', 'documento' => ''], $guardados[1]);
    }

    /** Sin nada que guardar es 422, no un UPDATE con la lista de campos vacía. */
    public function test_guardar_sin_texto_ni_firmantes_es_422(): void
    {
        [, $token] = $this->grupoYPersonal();

        $this->putJson('/api/actas-evaluacion/cambiar-descripcion', [],
            ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('msg', 'Nada que guardar');
    }

    // ----------------------------------------------------------------- Detalle

    /**
     * El detalle: los alumnos del grupo y los años que lleva uno en el colegio.
     *
     * Las dos listas responden a preguntas distintas —`alumnos` va por
     * `grupo_id` y `matriculas` por `alumno_id`— y llegan en la misma petición.
     * El test pide las dos cosas del mismo grupo para que ninguna salga vacía,
     * que es como una respuesta a medias pasaría por buena.
     */
    public function test_la_forma_del_detalle_del_acta(): void
    {
        [$grupo, $token] = $this->grupoYPersonal();

        $alumno = DB::selectOne('SELECT a.id FROM alumnos a
            INNER JOIN matriculas m ON m.alumno_id = a.id AND m.deleted_at IS NULL
            WHERE m.grupo_id = ? AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1', [$grupo->id]);

        $r = $this->putJson('/api/actas-evaluacion/detalle', [
            'grupo_id' => $grupo->id,
            'alumno_id' => $alumno->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $r->assertStatus(200);

        $this->assertNotEmpty($r->json('alumnos'), 'El detalle salió sin alumnos del grupo.');
        $this->assertNotEmpty($r->json('matriculas'), 'El detalle salió sin años de estadía.');

        $this->compararConInstantanea('actas-evaluacion-detalle', $this->formaUnida($r->json()));
    }
}
