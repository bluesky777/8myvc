<?php

namespace Tests\Contrato;

use App\Http\Controllers\BolfinalesController;
use App\Services\BoletinIndependiente;
use App\User;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Los cuatro recuentos de «notas perdidas» de todo el grupo cuentan las unidades
 * **de quien las pierde**, y no las de otro boletín.
 *
 * Lote A de la noche del 31 ago 2026. Los cuatro sitios son dos métodos privados
 * repetidos en dos controladores —`BolfinalesController` y su gemelo de
 * `Informes`—, y los cuatro contestan la misma pregunta sumando unidades que
 * pueden no ser del alumno:
 *
 *   - `perdidasPorAlumnoDelGrupo()`      — `:474` y `:717`
 *   - `perdidasPorDefinitivaDelGrupo()`  — `:536` y `:765`
 *
 * ## Por qué este test existe, dicho antes de que alguien lo borre por «redundante»
 *
 * **Con nadie marcado, la forma correcta y la incorrecta dan el mismo verde.** Toda
 * `unidades.alumno_id` es NULL hoy, `<=> NULL` acierta en las dos, y ninguna
 * instantánea se mueve — que es justo el criterio de aceptación del lote y **no
 * puede ver esta diferencia**. Así que aquí se *construye el caso*: un alumno
 * marcado, con unidades propias, y se miran las dos direcciones de fallo que ya se
 * han visto en este repo:
 *
 *   - **de más** — el independiente se lleva las suyas *y* las del grupo;
 *   - **de menos** — el alumno normal deja de ver las del grupo.
 *
 * ## Y la trampa de este lote, que es la tercera dirección
 *
 * Estas cuatro consultas **abarcan varios periodos de una vez** —
 * `u.periodo_id IN (…)`, y la de definitivas ni siquiera filtra periodo— mientras
 * que la marca es **por periodo**. Un alcance resuelto fuera y bindeado una sola
 * vez le daría a los demás periodos el del equivocado, **sin que falte una fila ni
 * sobre otra**: salen las unidades de otro boletín. Por eso el escenario marca al
 * alumno en UN periodo y lo deja con el grupo en el otro, y comprueba los dos.
 *
 * Se llama a los métodos privados por reflexión —hay precedente en la suite— porque
 * **el mapa que devuelven es el resultado**: quien lo lee sólo hace `?? 0` sobre él.
 * Mirarlo aquí es mirar el resultado, no el estado.
 *
 * Todo dentro de la transacción del test.
 */
class PerdidasDelGrupoAcotadasTest extends CasoDeContrato
{
    /** Por debajo de esto una nota está perdida, durante este test. */
    private const MINIMA = 3.0;

    /** @var array{grupo: object, asignatura: int, p1: int, p2: int, a: int, b: int} */
    private array $escenario;

    public function test_bolfinales_cuenta_solo_las_unidades_del_boletin_de_cada_alumno(): void
    {
        $this->comprobarLosCuatro(BolfinalesController::class);
    }

    public function test_informes_bolfinales_cuenta_solo_las_unidades_de_cada_boletin(): void
    {
        $this->comprobarLosCuatro(\App\Http\Controllers\Informes\BolfinalesController::class);
    }

    /**
     * Los dos métodos del controlador que toque, sobre el mismo escenario.
     *
     * @param  class-string  $clase
     */
    private function comprobarLosCuatro(string $clase): void
    {
        $e = $this->montarEscenario();

        $controlador = new $clase;

        $alumnos = [
            (object) ['alumno_id' => $e['a']],
            (object) ['alumno_id' => $e['b']],
        ];
        $periodos = [(object) ['id' => $e['p1']], (object) ['id' => $e['p2']]];

        // ── El baseline del periodo en que NO va aparte ──────────────────────────
        //
        // Se mide ANTES de marcar: en el periodo 2 el alumno sigue con el grupo, así
        // que lo que el seed ya tuviera ahí tiene que seguir contándose. Es la única
        // cifra del test que no es exacta por construcción, y por eso se mide en vez
        // de suponerse.
        $baseP2 = $this->mapa($controlador, 'perdidasPorDefinitivaDelGrupo', [$e['grupo']->id, $alumnos]);
        $baseAP2 = $baseP2[$e['a']][$e['asignatura']][$e['p2']] ?? 0;

        $this->sembrarNotas($e);

        // **Marcado en el 1, con el grupo en el 2.** El periodo 2 se escribe con
        // `aplica = 0` y no dejándolo sin fila: hoy las dos cosas dan el mismo NULL,
        // pero «este periodo no lleva boletín independiente» es una decisión que
        // alguien tomó, y se distingue de «nunca estuvo marcado».
        $this->marcarIndependiente($e['a'], $e['p1']);
        $this->marcarIndependiente($e['a'], $e['p2'], aplica: false);

        foreach (['perdidasPorDefinitivaDelGrupo', 'perdidasPorAlumnoDelGrupo'] as $metodo) {
            $argumentos = $metodo === 'perdidasPorAlumnoDelGrupo'
                ? [$e['grupo']->id, $alumnos, $periodos]
                : [$e['grupo']->id, $alumnos];

            $mapa = $this->mapa($controlador, $metodo, $argumentos);
            $donde = $clase.'::'.$metodo.'()';

            // ── 1. DE MÁS: el independiente no se lleva además las del grupo ─────
            //
            // Exacto y sin depender del seed: en el periodo 1 va aparte, y **todas las
            // unidades que ya existían son del grupo** (`alumno_id IS NULL`), así que
            // acotado bien sólo puede quedar la suya. Sin acotar salen las dos.
            $this->assertSame(1, $mapa[$e['a']][$e['asignatura']][$e['p1']] ?? 0,
                $donde.' cuenta al independiente notas de unidades del grupo en el periodo '
                .'donde va aparte. Su boletín tiene que llevar sólo las suyas: es la forma '
                .'«de más» de la §9.2 del plan, la que infla la definitiva.');

            // ── 2. DE MENOS: el alumno normal sigue viendo las del grupo ─────────
            //
            // La mitad que se rompe con `=` en vez de `<=>`, y que no da ningún error:
            // la rama del alumno normal se queda sin filas y la definitiva se va a 0.
            $this->assertGreaterThan(0, $mapa[$e['b']][$e['asignatura']][$e['p1']] ?? 0,
                $donde.' dejó al alumno normal sin ninguna nota perdida del grupo. Es la '
                .'forma «de menos»: el boletín sale en blanco. Si esto falla, mira si el '
                .'alcance se escribió con `=` en vez de `<=>`.');

            $this->assertSame(1, $mapa[$e['b']][$e['asignatura']][$e['p1']] ?? 0,
                $donde.' le sumó al alumno normal la unidad propia del independiente. Ésa es '
                .'la otra mitad de «de más»: las definitivas de los treinta salen infladas.');

            // ── 3. LA TRAMPA DEL LOTE: cada periodo resuelve SU alcance ──────────
            //
            // En el periodo 2 el alumno va con el grupo, así que su unidad propia de ese
            // periodo NO cuenta y la del grupo SÍ. Un alcance bindeado una sola vez le
            // daría aquí el del periodo 1 —donde va aparte— y contaría al revés, sin que
            // falte una fila ni sobre otra.
            $this->assertSame($baseAP2 + 2, $mapa[$e['a']][$e['asignatura']][$e['p2']] ?? 0,
                $donde.' no resolvió el alcance POR PERIODO. En el periodo 2 este alumno va '
                .'con el grupo (`aplica = 0`), así que tiene que contar la unidad del grupo '
                .'y NO la suya propia. Con el alcance bindeado una sola vez, el periodo 2 '
                .'hereda el del 1 y trae justo las contrarias. Ver '
                .'AlcanceCorrelacionadoPorPeriodoTest.');
        }
    }

    /**
     * @param  list<mixed>  $argumentos
     * @return array<int, array<int, array<int, int>>>
     */
    private function mapa(object $controlador, string $metodo, array $argumentos): array
    {
        $m = new ReflectionMethod($controlador, $metodo);
        $m->setAccessible(true);

        return $m->invokeArgs($controlador, $argumentos);
    }

    /**
     * Grupo, asignatura, dos periodos y dos alumnos `MATR`.
     *
     * `MATR` y no `ASIS` porque el gemelo de `Informes` filtra `m.estado = "MATR"`
     * y el otro admite las dos: con `MATR` el mismo escenario vale para los dos
     * controladores, que es lo que permite que este test sean dos y no cuatro.
     *
     * @return array{grupo: object, asignatura: int, p1: int, p2: int, a: int, b: int}
     */
    private function montarEscenario(): array
    {
        if (isset($this->escenario)) {
            return $this->escenario;
        }

        BoletinIndependiente::olvidar();
        User::$nota_minima_aceptada = self::MINIMA;

        $grupo = $this->grupoConAlumnos();

        $periodos = $this->periodosDelAnioDelGrupo((int) $grupo->id);

        $this->assertGreaterThanOrEqual(2, count($periodos),
            'El año de este grupo no tiene dos periodos: sin dos, «cada periodo el suyo» no se '
            .'puede distinguir de «uno para todos», que es justo la trampa de este lote.');

        $alumnos = DB::select(
            'SELECT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL AND m.estado = "MATR"
              ORDER BY m.alumno_id LIMIT 2',
            [$grupo->id]
        );

        $this->assertCount(2, $alumnos,
            'El seed no tiene dos alumnos MATR en este grupo: hacen falta uno marcado y uno '
            .'normal para ver las dos direcciones de fallo.');

        $asignatura = DB::selectOne(
            'SELECT a.id FROM asignaturas a WHERE a.grupo_id = ? AND a.deleted_at IS NULL LIMIT 1',
            [$grupo->id]
        );

        $this->assertNotNull($asignatura, 'El seed no tiene asignatura en este grupo.');

        return $this->escenario = [
            'grupo' => $grupo,
            'asignatura' => (int) $asignatura->id,
            'p1' => $periodos[0],
            'p2' => $periodos[1],
            'a' => (int) $alumnos[0]->alumno_id,
            'b' => (int) $alumnos[1]->alumno_id,
        ];
    }

    /**
     * Las unidades y las notas perdidas del escenario.
     *
     * En cada periodo, **una unidad del grupo y una del alumno A**, cada una con su
     * subunidad. Las notas perdidas quedan así:
     *
     * | periodo | A en las del grupo | A en la suya | B en la del grupo |
     * |---|---|---|---|
     * | 1 (va aparte)     | 1 | 1 | 1 |
     * | 2 (va con grupo)  | **2** | 1 | — |
     *
     * **El 2 del periodo 2 es lo único que hace que el test distinga la trampa del
     * lote, y se descubrió ejecutándolo.** Con un 1 ahí, «las del grupo» y «la suya»
     * valían las dos 1: el alcance sin correlacionar por periodo contaba justo las
     * contrarias y la aserción no lo veía — verde con la forma equivocada. Con dos, el
     * acierto en el periodo 2 vale 2 y la forma ingenua vale 1.
     *
     * En el periodo 1 el acierto es al revés que en el 2 —sólo la suya—, que es la
     * asimetría que el alcance tiene que resolver por periodo y no de una vez.
     *
     * @param  array{grupo: object, asignatura: int, p1: int, p2: int, a: int, b: int}  $e
     */
    private function sembrarNotas(array $e): void
    {
        foreach (['p1' => 1, 'p2' => 2] as $clave => $cuantasDelGrupo) {
            $periodo = $e[$clave];

            $delGrupo = $this->crearUnidadConSubunidades($e['asignatura'], $periodo, null, $cuantasDelGrupo);
            $propia = $this->crearUnidadConSubunidades($e['asignatura'], $periodo, $e['a'], 1);

            foreach ($delGrupo as $subunidad) {
                $this->perder($subunidad, $e['a']);
            }

            $this->perder($propia[0], $e['a']);

            // B sólo en el periodo 1: es donde se comprueba que al alumno normal no le
            // desaparece lo del grupo ni le aparece lo del independiente.
            if ($clave === 'p1') {
                $this->perder($delGrupo[0], $e['b']);
            }
        }
    }

    /**
     * Una unidad con `$cuantas` subunidades. `$alumnoId` NULL es una unidad del grupo.
     *
     * **`$cuantas` no es un adorno de generalidad: es lo que hace que el test
     * distinga.** Con una subunidad en cada lado, «la del grupo» y «la suya» valían
     * las dos 1 en el periodo 2, y la forma ingenua —el alcance sin correlacionar por
     * periodo— pasaba en verde contando justo las contrarias. Se vio ejecutándola.
     *
     * @return list<int> los ids de las subunidades creadas
     */
    private function crearUnidadConSubunidades(int $asignaturaId, int $periodoId, ?int $alumnoId, int $cuantas): array
    {
        DB::insert(
            'INSERT INTO unidades (definicion, porcentaje, asignatura_id, periodo_id, alumno_id, orden, created_at, updated_at)
             VALUES (?, 100, ?, ?, ?, 1, NOW(), NOW())',
            [$alumnoId === null ? 'Del grupo' : 'Propia', $asignaturaId, $periodoId, $alumnoId]
        );

        $unidadId = (int) DB::getPdo()->lastInsertId();

        $subunidades = [];

        for ($i = 1; $i <= $cuantas; $i++) {
            DB::insert(
                'INSERT INTO subunidades (definicion, porcentaje, unidad_id, orden, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                ['Actividad '.$i, (int) (100 / $cuantas), $unidadId, $i]
            );

            $subunidades[] = (int) DB::getPdo()->lastInsertId();
        }

        return $subunidades;
    }

    /** Una nota perdida de ese alumno en esa subunidad. */
    private function perder(int $subunidadId, int $alumnoId): void
    {
        DB::insert(
            'INSERT INTO notas (nota, subunidad_id, alumno_id, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())',
            [self::MINIMA - 1, $subunidadId, $alumnoId]
        );
    }
}
