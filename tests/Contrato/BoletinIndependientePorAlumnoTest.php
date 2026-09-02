<?php

namespace Tests\Contrato;

use Illuminate\Support\Facades\DB;

/**
 * `PUT boletin-independiente/marcados` y `PUT boletin-independiente/alumno` — la
 * pantalla del boletín aparte **por estudiante**. §13 del
 * [19](../../docs/migracion/19-boletin-independiente.md).
 *
 * ## Todos estos tests CONSTRUYEN el caso, y esa es la regla del módulo
 *
 * `bol_ind_periodos` **nace vacía**: con nadie marcado, la respuesta correcta y la
 * incorrecta salen iguales —las dos vacías— y un test escrito sobre el seed tal cual
 * pasaría con el endpoint mal escrito. Es la §9.2 en su forma más corta: *«con nadie
 * marcado, la consulta que olvidó el alcance se comporta exactamente como la que no»*.
 *
 * ## Los tres que existen por un fallo concreto, y no por cubrir superficie
 *
 *   1. **El denominador.** El diseño que llegó del front contaba «4 de 5 montadas»
 *      sobre un alumno de trece asignaturas. Con la marca por periodo van aparte **las
 *      trece**, así que ese 5 habría subestimado la §9.1 en ocho — en la única dirección
 *      en la que esta pantalla no puede fallar. El test fija el denominador contando las
 *      asignaturas del grupo, no las que alguien tocó.
 *   2. **`notas_puestas` por `updated_by` y no por el valor.** `notas.nota` es
 *      `int NOT NULL DEFAULT 0` y la fila nace sembrada, así que `nota > 0` mezcla dos
 *      cosas: medido en `simonbolivar`, etiquetaría mal **25.642 filas** —21.703 nunca
 *      tocadas con un `nota_default` distinto de cero, y **3.939 ceros tecleados
 *      queriendo**—. El test monta las dos trampas a la vez.
 *   3. **`aplica` no está en la asignatura.** La marca es `(alumno, periodo)` y vale
 *      para todas: por asignatura sería **constante**, que es el fallo que el front cazó
 *      dos veces en la §6.4. El test comprueba la ausencia de la clave, porque un campo
 *      constante pasa cualquier test que compruebe su valor.
 */
class BoletinIndependientePorAlumnoTest extends CasoDeContrato
{
    private const MARCADOS = '/api/boletin-independiente/marcados';

    private const ALUMNO = '/api/boletin-independiente/alumno';

    /**
     * Una asignatura del año actual con unidades **del grupo** en el periodo actual.
     *
     * Mismo montaje que `BoletinIndependientePlanillaTest::contexto()` y por la misma
     * razón: elegir la asignatura por un lado y el periodo por otro deja la respuesta
     * vacía y el test pasando sin ejecutar nada.
     */
    private function contexto(): object
    {
        $fila = DB::selectOne('SELECT a.id AS asignatura_id, a.grupo_id, g.year_id, un.periodo_id
            FROM asignaturas a
            INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
            INNER JOIN unidades un ON un.asignatura_id = a.id AND un.deleted_at IS NULL
                AND un.alumno_id IS NULL
            INNER JOIN periodos per ON per.id = un.periodo_id AND per.actual = 1
                AND per.year_id = g.year_id AND per.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id AND y.actual = 1 AND y.deleted_at IS NULL
            INNER JOIN subunidades s ON s.unidad_id = un.id AND s.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
            ORDER BY a.id LIMIT 1');

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades del grupo en el periodo actual y subunidades.');

        return $fila;
    }

    /** Un alumno matriculado en el grupo, el mismo en cada corrida. */
    private function unAlumno(int $grupoId): int
    {
        $fila = DB::selectOne(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado IN ("MATR","ASIS","PREM")
              ORDER BY m.alumno_id LIMIT 1',
            [$grupoId]
        );

        $this->assertNotNull($fila, 'El grupo del seed se quedó sin alumnos matriculados.');

        return (int) $fila->alumno_id;
    }

    /** Cuántas asignaturas vivas tiene el grupo: es el denominador que se está fijando. */
    private function cuantasAsignaturas(int $grupoId): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) c FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL',
            [$grupoId]
        )->c;
    }

    /**
     * Una unidad **con dueño** en una asignatura, con `$subunidades` subunidades dentro.
     *
     * @return list<int> Los ids de las subunidades creadas, en orden.
     */
    private function unidadPropia(int $alumnoId, int $asignaturaId, int $periodoId, int $subunidades = 1): array
    {
        $unidad = (int) DB::table('unidades')->insertGetId([
            'definicion' => 'Unidad propia (test)',
            'porcentaje' => 100,
            'periodo_id' => $periodoId,
            'asignatura_id' => $asignaturaId,
            'alumno_id' => $alumnoId,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = [];

        for ($i = 0; $i < $subunidades; $i++) {
            $ids[] = (int) DB::table('subunidades')->insertGetId([
                'definicion' => 'Subunidad propia (test) '.$i,
                'porcentaje' => (int) (100 / $subunidades),
                'unidad_id' => $unidad,
                'nota_default' => 0,
                'orden' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function marcados(object $ctx, ?string $token = null): array
    {
        $r = $this->withToken($token ?? $this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::MARCADOS, ['periodo_id' => $ctx->periodo_id]);

        $r->assertStatus(200);

        return $r->json();
    }

    private function detalle(object $ctx, int $alumnoId, ?string $token = null): array
    {
        $r = $this->withToken($token ?? $this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::ALUMNO, ['alumno_id' => $alumnoId, 'periodo_id' => $ctx->periodo_id]);

        $r->assertStatus(200);

        return $r->json();
    }

    /** @return array<int, array<string, mixed>> Los alumnos de la lista, por id. */
    private function porAlumno(array $respuesta): array
    {
        $salida = [];

        foreach ($respuesta['alumnos'] as $alumno) {
            $salida[(int) $alumno['alumno_id']] = $alumno;
        }

        return $salida;
    }

    // ─────────────────────────────────────────────────────────────────────
    // `marcados` — la lista del menú
    // ─────────────────────────────────────────────────────────────────────

    /**
     * **Sin nadie marcado la lista está vacía, y la respuesta NO lo es.**
     *
     * Es el estado de los quince colegios hoy. Lo que se comprueba es que la pantalla
     * pueda abrirse igual y **diga de qué periodo habla**: una lista vacía que no dice
     * su periodo no distingue «nadie va aparte» de «te equivocaste de pestaña».
     */
    public function test_sin_nadie_marcado_la_lista_esta_vacia_pero_dice_su_periodo(): void
    {
        $ctx = $this->contexto();
        $r = $this->marcados($ctx);

        $this->assertSame([], $r['alumnos']);
        $this->assertSame((int) $ctx->periodo_id, $r['periodo']['periodo_id']);
        $this->assertSame('todas', $r['alcance']);
        $this->assertSame(0, $r['sin_matricula']);
    }

    /**
     * **EL DENOMINADOR SON TODAS SUS ASIGNATURAS, NO LAS MONTADAS.**
     *
     * El test de la corrección: se le monta estructura propia en **una** asignatura y se
     * exige que `montadas` sea 1 **sobre el total del grupo**, con el resto en
     * `sin_unidades`. Un endpoint que contara sólo las asignaturas «tocadas» daría
     * `1 de 1` y diría que el estudiante está listo teniendo el boletín sin montar en
     * todas las demás — que es exactamente el fallo del §9.1 pintado de verde.
     */
    public function test_el_denominador_son_todas_sus_asignaturas_y_no_solo_las_montadas(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);
        $total = $this->cuantasAsignaturas((int) $ctx->grupo_id);

        $this->assertGreaterThan(1, $total,
            'El grupo del seed necesita más de una asignatura: con una sola, «4 de 5» y «4 de 13» son lo mismo '
            .'y este test no distinguiría nada.');

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $this->unidadPropia($alumno, (int) $ctx->asignatura_id, (int) $ctx->periodo_id);

        $fila = $this->porAlumno($this->marcados($ctx))[$alumno] ?? null;

        $this->assertNotNull($fila, 'El alumno marcado no salió en la lista del menú.');

        $this->assertSame($total, $fila['asignaturas'],
            'El denominador no son todas sus asignaturas. La marca es por periodo y vale para TODAS '
            .'(decisión 1): contar sólo las tocadas subestima el §9.1.');

        $this->assertSame(1, $fila['montadas']);
        $this->assertSame($total - 1, $fila['sin_unidades'],
            'Las asignaturas que nadie le montó son el riesgo, y son las que esta cifra tiene que gritar.');
        $this->assertSame($total, $fila['asignaturas_del_alumno']);
    }

    /**
     * **`notas_puestas` es `updated_by`, y el montaje está hecho para que `nota > 0` FALLE.**
     *
     * Esa asimetría es el test, y costó una corrección escribirla: la primera versión
     * ponía **una** sembrada con valor y **un** cero tecleado, y con ese montaje los dos
     * criterios dan **1** — los dos errores contrarios se cancelan y el test pasaba con
     * el endpoint escrito mal. Es la trampa de la cabecera de `CLAUDE.md` en su forma
     * exacta: *un detector puede contar bien un síntoma y no estar contando la causa.*
     *
     * Así que van **dos** sembradas con valor y nunca tocadas, **un** cero tecleado
     * queriendo, y una cuarta **sin fila de nota**:
     *
     * | Criterio | Cuenta | |
     * |---|---|---|
     * | `updated_by IS NOT NULL` | **1** | sólo el cero que alguien tecleó — **lo correcto** |
     * | `nota > 0` | **2** | las dos sembradas, y se pierde el cero |
     */
    public function test_notas_puestas_cuenta_por_updated_by_y_no_por_el_valor(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        [$sembradaA, $sembradaB, $tecleada, $sinCasilla] = $this->unidadPropia(
            $alumno, (int) $ctx->asignatura_id, (int) $ctx->periodo_id, subunidades: 4
        );

        // Dos sembradas con un `nota_default` distinto de cero y NUNCA tocadas. Son las
        // 21.703 filas de `simonbolivar` que `nota > 0` daría por calificadas.
        foreach ([[$sembradaA, 5], [$sembradaB, 3]] as [$subunidad, $nota]) {
            DB::table('notas')->insert([
                'subunidad_id' => $subunidad, 'alumno_id' => $alumno, 'nota' => $nota,
                'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Un CERO tecleado queriendo: las 3.939 que `nota > 0` daría por no puestas.
        DB::table('notas')->insert([
            'subunidad_id' => $tecleada, 'alumno_id' => $alumno, 'nota' => 0,
            'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A `$sinCasilla` no se le crea fila: es lo que deja `copiar` con `con_notas: false`.
        // Se comprueba, y no se da por hecho: es la premisa entera de `sin_casilla`, y una
        // siembra que la llenara por detrás dejaría este test midiendo otra cosa.
        $this->assertNull(
            DB::selectOne('SELECT id FROM notas WHERE subunidad_id = ? AND alumno_id = ? AND deleted_at IS NULL',
                [$sinCasilla, $alumno]),
            'La cuarta subunidad tiene que quedarse sin fila de nota.'
        );

        $fila = $this->porAlumno($this->marcados($ctx))[$alumno];

        $this->assertSame(4, $fila['notas_totales'], 'Las cuatro subunidades suyas son el total.');
        $this->assertSame(1, $fila['notas_puestas'],
            'Sólo una la tecleó alguien. **Si sale 2, el criterio es `nota > 0`**: está contando las dos '
            .'sembradas que nadie tocó y perdiendo el cero que alguien puso queriendo.');
        $this->assertSame(1, $fila['sin_casilla'],
            'La subunidad sin fila de nota no se puede teclear: es su propio estado, no «falta la nota».');

        // Y el que separa de verdad los dos criterios: la que cuenta es la del CERO.
        $detalle = $this->detalle($ctx, $alumno);
        $suya = $this->asignaturaDe($detalle, (int) $ctx->asignatura_id);
        $notas = [];

        foreach ($suya['unidades'] as $unidad) {
            foreach ($unidad['subunidades'] as $sub) {
                $notas[(int) $sub['subunidad_id']] = $sub['nota'];
            }
        }

        $this->assertSame(0, $notas[$tecleada]['nota'], 'El cero tecleado tiene que viajar como 0 y no perderse.');
        $this->assertNull($notas[$sinCasilla], 'La subunidad sin fila viaja con `nota: null`, no desaparece.');
    }

    /**
     * **El desmarcado con datos guardados NO sale en esta lista**, y sí en la planilla.
     *
     * No es una incoherencia entre las dos pantallas: contestan preguntas distintas. La
     * planilla enseña *«qué hay escrito aparte en esta asignatura»* —incluido lo que se
     * está ignorando— y esta lista contesta *«quién va aparte este periodo»*. Meter aquí
     * a quien no va aparte diluiría el único aviso que la pantalla existe para dar.
     */
    public function test_el_desmarcado_con_datos_no_sale_en_la_lista(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id, aplica: false);
        $this->unidadPropia($alumno, (int) $ctx->asignatura_id, (int) $ctx->periodo_id);

        $this->assertArrayNotHasKey($alumno, $this->porAlumno($this->marcados($ctx)),
            'La lista de «quién va aparte» incluyó a alguien que este periodo va con el grupo.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // `alumno` — el detalle
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> La asignatura pedida, dentro del detalle. */
    private function asignaturaDe(array $detalle, int $asignaturaId): array
    {
        foreach ($detalle['asignaturas'] as $asignatura) {
            if ((int) $asignatura['asignatura_id'] === $asignaturaId) {
                return $asignatura;
            }
        }

        $this->fail("La asignatura {$asignaturaId} no salió en el detalle del alumno.");
    }

    /**
     * **`aplica` va en el periodo y NO en cada asignatura.**
     *
     * Se comprueba la **ausencia de la clave**, no su valor, y esa diferencia es el
     * test: un campo constante pasa cualquier aserción sobre su valor —siempre acierta—
     * y sólo se caza mirando que no esté. Es el tercer campo de este módulo que iba a
     * nacer constante; los otros dos los cazó el front antes de escribirlos (§6.4).
     */
    public function test_aplica_va_en_el_periodo_y_no_en_cada_asignatura(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $this->unidadPropia($alumno, (int) $ctx->asignatura_id, (int) $ctx->periodo_id);

        $detalle = $this->detalle($ctx, $alumno);

        $this->assertTrue($detalle['periodo']['aplica'], 'El periodo marcado tiene que decir que aplica.');
        $this->assertTrue($detalle['periodo']['tiene_datos'], 'Tiene una unidad propia viva.');

        foreach ($detalle['asignaturas'] as $asignatura) {
            $this->assertArrayNotHasKey('aplica', $asignatura,
                'Una asignatura trae `aplica`. La marca es por periodo y vale para todas, así que ahí sería '
                .'CONSTANTE — y un campo constante hace que la pantalla ramifique sobre una rama muerta: '
                .'el gris de «va con el grupo» acabaría pintando la asignatura sin estructura propia, '
                .'que es la que saca definitiva cero.');
        }
    }

    /**
     * Las asignaturas que nadie le montó traen `motivo`, y la montada no.
     *
     * Es el §9.1 visto desde el detalle: marcado, con estructura en una y sin nada en las
     * demás. `sin_estructura_propia` es el que la pantalla tiene que gritar — el grupo sí
     * tiene unidades y a este alumno no le hizo nadie nada.
     */
    public function test_las_asignaturas_sin_estructura_propia_traen_su_motivo(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);
        $this->unidadPropia($alumno, (int) $ctx->asignatura_id, (int) $ctx->periodo_id);

        $detalle = $this->detalle($ctx, $alumno);

        $montada = $this->asignaturaDe($detalle, (int) $ctx->asignatura_id);

        $this->assertArrayNotHasKey('motivo', $montada, 'Tiene unidades: no hay vacío que explicar.');
        $this->assertNotEmpty($montada['unidades']);

        $motivos = [];

        foreach ($detalle['asignaturas'] as $asignatura) {
            if ($asignatura['unidades'] === []) {
                $this->assertArrayHasKey('motivo', $asignatura,
                    'Una lista de unidades vacía sin `motivo` se lee como «no hay datos» cuando puede ser '
                    .'el alumno que se cae por el hueco.');

                $motivos[] = $asignatura['motivo'];
            }
        }

        $this->assertNotEmpty($motivos,
            'Con estructura en una sola asignatura, las demás tienen que venir vacías y con su motivo.');

        foreach ($motivos as $motivo) {
            $this->assertContains($motivo, ['asignatura_sin_montar', 'sin_estructura_propia', 'vaciada']);
        }
    }

    /**
     * **El docente ve sólo sus asignaturas, y se le dice cuántas quedan fuera.**
     *
     * `asignaturas_del_alumno` es el total **aunque el alcance sea `mias`**: sin él, un
     * docente con una sola materia cree que el estudiante sólo tiene una y lo da por
     * terminado.
     *
     * El caso se **construye** —se le reasigna una asignatura del grupo al docente del
     * token— en vez de buscar en el seed uno que ya cuadre: «el profesor que casualmente
     * da aquí» es población, y una población que cambie deja el test pasando sin
     * comprobar el recorte.
     */
    public function test_el_docente_ve_solo_sus_asignaturas_pero_recibe_el_total(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);
        $total = $this->cuantasAsignaturas((int) $ctx->grupo_id);

        $docente = DB::selectOne(
            'SELECT u.username, pr.id AS profesor_id
               FROM users u
               INNER JOIN profesores pr ON pr.user_id = u.id AND pr.deleted_at IS NULL
               INNER JOIN periodos p ON p.id = u.periodo_id AND p.deleted_at IS NULL AND p.year_id = ?
              WHERE u.tipo = "Profesor" AND u.is_active = 1 AND u.deleted_at IS NULL
              ORDER BY u.id LIMIT 1',
            [$ctx->year_id]
        );

        $this->assertNotNull($docente, "El seed no tiene ningún docente con usuario en el año {$ctx->year_id}.");

        // Sólo una asignatura del grupo es suya. Las demás quedan fuera de su alcance.
        DB::table('asignaturas')->where('grupo_id', $ctx->grupo_id)
            ->update(['profesor_id' => DB::raw('IF(id = '.(int) $ctx->asignatura_id.', '.(int) $docente->profesor_id.', profesor_id)')]);

        DB::table('asignaturas')->where('grupo_id', $ctx->grupo_id)
            ->where('id', '!=', $ctx->asignatura_id)
            ->where('profesor_id', $docente->profesor_id)
            ->update(['profesor_id' => null]);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);

        $detalle = $this->detalle($ctx, $alumno, $this->tokenDe($docente->username));

        $this->assertSame('mias', $detalle['alcance']);
        $this->assertCount(1, $detalle['asignaturas'],
            'El docente vio asignaturas que no da. `mias` es la INTERSECCIÓN con el grupo del alumno: '
            .'`Profesor::asignaturas()` sola devuelve las suyas de TODOS sus grupos.');
        $this->assertSame((int) $ctx->asignatura_id, (int) $detalle['asignaturas'][0]['asignatura_id']);
        $this->assertSame($total, $detalle['asignaturas_del_alumno'],
            'El total tiene que llegar aunque el alcance recorte: «ves 1 de las N» es la frase que impide '
            .'que un docente dé por terminado un boletín que no ha visto entero.');
    }

    /**
     * Un alumno sin matrícula en el año de ese periodo es **404**, no una lista vacía.
     *
     * Un `[]` se leería como «no tiene nada montado» —que es el estado grave— cuando lo
     * que pasa es que ese alumno no está en ese año.
     */
    public function test_un_alumno_sin_matricula_en_el_anio_del_periodo_da_404(): void
    {
        $ctx = $this->contexto();

        $ajeno = DB::selectOne(
            'SELECT a.id FROM alumnos a
              WHERE a.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM matriculas m
                     INNER JOIN grupos g ON g.id = m.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
                     WHERE m.alumno_id = a.id AND m.deleted_at IS NULL
                       AND m.estado IN ("MATR", "ASIS", "PREM"))
              ORDER BY a.id LIMIT 1',
            [$ctx->year_id]
        );

        $this->assertNotNull($ajeno,
            'El seed no tiene ningún alumno fuera del año actual, así que este test no comprobaría nada. '
            .'`NOT EXISTS` y no `NOT IN`: un `NULL` dentro de un `NOT IN` no devuelve a nadie.');

        $this->withToken($this->tokenDelPersonalDe((int) $ctx->year_id))
            ->putJson(self::ALUMNO, ['alumno_id' => $ajeno->id, 'periodo_id' => $ctx->periodo_id])
            ->assertStatus(404);
    }

    /**
     * Las faltas viajan **dentro de su asignatura** y separadas por tipo.
     *
     * `tipo` es el único discriminador que tiene `ausencias` —no hay columna de excusa, y
     * el `excusado` del esquema es de `uniformes`—, así que el recuento no puede decir
     * más de lo que la tabla sabe.
     */
    public function test_las_faltas_van_dentro_de_su_asignatura_y_separadas_por_tipo(): void
    {
        $ctx = $this->contexto();
        $alumno = $this->unAlumno((int) $ctx->grupo_id);

        $this->marcarIndependiente($alumno, (int) $ctx->periodo_id);

        foreach ([['ausencia', 'cantidad_ausencia'], ['ausencia', 'cantidad_ausencia'], ['tardanza', 'cantidad_tardanza']] as [$tipo, $columna]) {
            DB::table('ausencias')->insert([
                'asignatura_id' => $ctx->asignatura_id,
                'alumno_id' => $alumno,
                'periodo_id' => $ctx->periodo_id,
                $columna => 1,
                'tipo' => $tipo,
                'fecha_hora' => now(),
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $suya = $this->asignaturaDe($this->detalle($ctx, $alumno), (int) $ctx->asignatura_id);

        $this->assertSame(2, $suya['faltas']['ausencias']);
        $this->assertSame(1, $suya['faltas']['tardanzas']);
        $this->assertCount(3, $suya['faltas']['detalle'],
            'El detalle lleva las tres. Si los dos recuentos no suman su tamaño, hay un `tipo` que la tabla '
            .'admite y estos recuentos no clasifican.');
    }
}
