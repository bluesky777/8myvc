<?php

namespace Tests\Contrato;

use App\Services\BoletinIndependiente;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * **La fase 5**: los tres boletines probados **en negativo**, con un alumno marcado.
 * §5 del [plan](../../docs/migracion/19-boletin-independiente.md), punto 3 de la cola
 * de la [noche del 31 ago 2026](../../docs/migracion/noche-2026-08-31/reparto.md).
 *
 * ## No es código nuevo, y ése es exactamente el problema
 *
 * Los tres boletines ya llevan el alcance desde la fase 1: sus unidades salen de
 * `Unidad::deAsignaturaCalculada($alumno_id, …)`, que pregunta a
 * `BoletinIndependiente::alcance()`. **Lo que faltaba es el test que lo demuestra.**
 *
 * Y falta de verdad, porque **con nadie marcado la forma correcta y la incorrecta dan
 * el mismo verde**: `unidades.alumno_id` es NULL en todas las filas de los quince
 * colegios, así que `<=> NULL` y `<=> $alumno` seleccionan lo mismo. Un test escrito
 * sobre el seed tal cual no distingue nada. Por eso aquí **se construye el caso**.
 *
 * ## Las dos direcciones, y la segunda es la cara cara del fallo
 *
 * | dirección | qué se rompe | a quién le pasa |
 * |---|---|---|
 * | **de menos** | el boletín del independiente pide las del grupo y sale en blanco | al marcado |
 * | **de más** | la estructura privada se cuela en el boletín de los demás | **a los otros treinta**, que no tienen forma de saberlo |
 *
 * ## Los dos lados con números DISTINTOS, y no es adorno
 *
 * Dos subunidades del grupo contra **tres** propias. Con un 1 contra un 1, una
 * implementación que trajera **justo las contrarias** daría el mismo número de filas y
 * el test pasaría con el código malo — le pasó al lote A esta misma noche (§1.4 del
 * reparto, tercera forma de fallar). Aquí además cada fila lleva su nombre, así que lo
 * que se compara es **qué** salió y no cuántas.
 *
 * ## Qué documento prueba qué, medido y no supuesto
 *
 * | documento | unidades | subunidades |
 * |---|---|---|
 * | `boletines` | sí | **sí** — es el único que las emite (`Subunidad::deUnidadCalculada`) |
 * | `boletines2` | sí | no las trae |
 * | `boletines3` | sí | no las trae |
 *
 * Se comprobó en las instantáneas y en la respuesta, no leyendo los controladores. Así
 * que el caso de subunidades corre **sólo sobre `boletines`**, que es donde hay algo
 * que mirar; pretender comprobarlas en los tres habría dado dos verdes por vacío.
 */
class BoletinesEnNegativoTest extends CasoDeContrato
{
    /** Lo que se busca en la respuesta. Nombres y no cuentas: dicen QUÉ salió. */
    private const UNIDAD_GRUPO = 'F5 UNIDAD DEL GRUPO';

    private const UNIDAD_PROPIA = 'F5 UNIDAD PROPIA';

    private const SUB_GRUPO = 'F5-SUB-GRUPO-';

    private const SUB_PROPIA = 'F5-SUB-PROPIA-';

    /** Dos del grupo contra TRES propias: los dos lados con números distintos (§1.4). */
    private const CUANTAS_DEL_GRUPO = 2;

    private const CUANTAS_PROPIAS = 3;

    private ?object $escenario = null;

    protected function setUp(): void
    {
        parent::setUp();

        BoletinIndependiente::olvidar();
    }

    /** @return array<string, array{string}> */
    public static function losTresBoletines(): array
    {
        return [
            'boletines' => ['boletines/detailed-notas'],
            'boletines2' => ['boletines2/detailed-notas'],
            'boletines3' => ['boletines3/detailed-notas'],
        ];
    }

    /**
     * Grupo, asignatura, dos alumnos y **el periodo del token**.
     *
     * **El periodo del token no es montaje, es la mitad del escenario.** Los tres
     * boletines piden las unidades con `$this->user->periodo_id`, no con el del grupo:
     * eligiendo el grupo por su cuenta sale uno de otro año, la respuesta llega 200 y
     * **sin una sola unidad dentro**, y el test falla por el escenario y no por el
     * código. Es la vuelta que ya dio `BoletinDelIndependienteTest` y está escrita ahí.
     */
    private function escenario(): object
    {
        if ($this->escenario !== null) {
            return $this->escenario;
        }

        $usuario = $this->usuarioDeTipo('Usuario');

        $fila = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, u.periodo_id
               FROM asignaturas a
              INNER JOIN unidades u ON u.asignatura_id = a.id AND u.deleted_at IS NULL
                    AND u.alumno_id IS NULL AND u.periodo_id = ?
              INNER JOIN matriculas m ON m.grupo_id = a.grupo_id AND m.deleted_at IS NULL
                    AND m.estado IN ("MATR", "ASIS")
              WHERE a.deleted_at IS NULL
              GROUP BY a.id, a.grupo_id, u.periodo_id
             HAVING COUNT(DISTINCT m.alumno_id) >= 2
              ORDER BY a.id LIMIT 1', [$usuario->periodo_id]);

        $this->assertNotNull($fila,
            'El seed necesita una asignatura con unidades EN EL PERIODO DEL TOKEN y DOS alumnos '
            .'matriculados. Con uno solo, «lo suyo» y «lo de todos» son lo mismo; con otro '
            .'periodo la respuesta viene vacía y el test pasa o falla por el montaje.');

        $alumnos = DB::select(
            'SELECT alumno_id FROM matriculas
              WHERE grupo_id = ? AND deleted_at IS NULL AND estado IN ("MATR", "ASIS")
              ORDER BY alumno_id LIMIT 2', [$fila->grupo_id]);

        $fila->marcado = (int) $alumnos[0]->alumno_id;
        $fila->companero = (int) $alumnos[1]->alumno_id;
        $fila->token = $this->tokenDe($usuario->username);
        $fila->numero_periodo = (int) DB::selectOne(
            'SELECT numero FROM periodos WHERE id = ?', [$fila->periodo_id])->numero;

        return $this->escenario = $fila;
    }

    /**
     * Monta el caso: una unidad del grupo y una propia del marcado, **cada una con sus
     * subunidades nombradas**, y marca al alumno.
     *
     * Las del grupo se crean aquí en vez de reutilizar las del seed porque hay que
     * poder decir **qué** subunidad concreta no debería estar: comparar cuentas contra
     * un seed que se regenera no distingue «no salió la del grupo» de «salió otra».
     */
    private function montarElCaso(object $e): void
    {
        $this->unidadConSubunidades($e, self::UNIDAD_GRUPO, null, self::SUB_GRUPO, self::CUANTAS_DEL_GRUPO);
        $this->unidadConSubunidades($e, self::UNIDAD_PROPIA, $e->marcado, self::SUB_PROPIA, self::CUANTAS_PROPIAS);

        $this->marcarIndependiente($e->marcado, (int) $e->periodo_id);
    }

    private function unidadConSubunidades(object $e, string $definicion, ?int $alumnoId, string $prefijo, int $cuantas): void
    {
        DB::insert(
            'INSERT INTO unidades (asignatura_id, periodo_id, alumno_id, definicion, porcentaje, orden, created_at, updated_at)
             VALUES (?, ?, ?, ?, 100, 99, NOW(), NOW())',
            [$e->asignatura_id, $e->periodo_id, $alumnoId, $definicion]);

        $unidadId = (int) DB::getPdo()->lastInsertId();

        for ($i = 1; $i <= $cuantas; $i++) {
            DB::insert(
                'INSERT INTO subunidades (definicion, porcentaje, unidad_id, orden, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$prefijo.$i, (int) (100 / $cuantas), $unidadId, $i]);
        }
    }

    /**
     * El documento de un alumno.
     *
     * **`periodo_a_calcular` va SIEMPRE**, y `boletines3` es la razón: su
     * `putDetailedNotas` lo toma por defecto **10**, y
     * `Grupo::detailed_materias_notas_finales` sólo tiene ramas para 1–4, así que con
     * 10 devuelve cero asignaturas y el documento sale con las áreas vacías. Sin este
     * parámetro el test daría un rojo que parecería del alcance y sería de otra cosa —
     * lo dejó escrito `BolIndependienteRotuloTest` y aquí vale igual.
     *
     * @return array<string, mixed>
     */
    private function pedir(string $ruta, object $e, int $alumnoId): array
    {
        $r = $this->withToken($e->token)->putJson("/api/{$ruta}/{$e->grupo_id}", [
            'requested_alumnos' => [['alumno_id' => $alumnoId]],
            'periodo_a_calcular' => $e->numero_periodo,
        ]);

        $this->assertSame(200, $r->status(), "`{$ruta}` tiene que contestar 200.");

        return (array) $r->json();
    }

    /**
     * Todas las `definicion_unidad` (o `definicion_subunidad`) de la respuesta, **sin
     * repetir**, busque donde busque.
     *
     * ## Por qué se busca a lo ancho y por qué se hace `unique`, que es la misma razón
     *
     * `boletines/detailed-notas` cuelga la misma estructura de **dos sitios**:
     * `alumnos[].asignaturas[]` —el boletín— y `alumnos[].asignaturas_perdidas[]`, y las
     * dos salen de llamadores distintos. **Medido en la respuesta**: las tres
     * subunidades propias aparecían seis veces, y el primer `assertCount(3)` se cayó
     * por eso y no por el alcance.
     *
     * **Las dos ramas tienen que estar acotadas**, así que buscar a lo ancho es lo
     * correcto aquí: si una lo estuviera y la otra no, apuntar sólo al boletín dejaría
     * pasar la fuga. Lo que no vale es *contar* filas de las dos, y por eso esto
     * deduplica: lo que se compara son **nombres**, y cada nombre existe una vez en la
     * base.
     *
     * > Y no contradice el aviso de `BoletinDelIndependienteTest`, que dice justo lo
     * > contrario —«no barras la respuesta entera»—. Allí se comparaban **las listas de
     * > dos alumnos entre sí**, y `asignaturas_perdidas` difiere de un alumno a otro
     * > **con razón y sin nadie marcado**, así que un extractor ancho daba un falso
     * > positivo. Aquí se compara contra **marcadores con nombre propio** creados por el
     * > test, que no dependen de las notas de nadie. El control que sí compara dos
     * > alumnos entre sí —`sin_marcar_a_nadie`— usa el extractor estrecho, abajo.
     *
     * @return list<string>
     */
    private function definiciones(mixed $nodo, string $clave): array
    {
        $encontradas = [];

        if (is_array($nodo)) {
            foreach ($nodo as $k => $hijo) {
                if ($k === $clave) {
                    $encontradas[] = (string) $hijo;
                } else {
                    $encontradas = array_merge($encontradas, $this->definiciones($hijo, $clave));
                }
            }
        }

        return array_values(array_unique($encontradas));
    }

    /**
     * Las `definicion_unidad` **del boletín y nada más**: sólo lo que cuelga de
     * `asignaturas[]`, sin `asignaturas_perdidas[]`.
     *
     * Lo usa el único caso que compara **dos alumnos entre sí**. Ahí el extractor ancho
     * daría un falso positivo: `asignaturas_perdidas` está filtrada por las notas
     * perdidas de cada alumno, así que difiere de uno a otro **con razón y sin que nadie
     * esté marcado** — y el control lo leería como «la respuesta se movió». Es el aviso
     * de `BoletinDelIndependienteTest`, que ya costó una vuelta.
     *
     * @return list<string>
     */
    private function unidadesDelBoletin(mixed $nodo): array
    {
        $encontradas = [];

        if (is_array($nodo)) {
            foreach ($nodo as $k => $hijo) {
                $encontradas = array_merge(
                    $encontradas,
                    $k === 'asignaturas' ? $this->definiciones($hijo, 'definicion_unidad')
                                         : $this->unidadesDelBoletin($hijo)
                );
            }
        }

        return array_values(array_unique($encontradas));
    }

    /** Las que empiezan por ese prefijo. @return list<string> */
    private function conPrefijo(array $todas, string $prefijo): array
    {
        return array_values(array_filter($todas, fn (string $d) => str_starts_with($d, $prefijo)));
    }

    /**
     * **El boletín del independiente trae LO SUYO y nada del grupo.** La dirección «de
     * menos» y su recíproca, en la misma respuesta.
     */
    #[DataProvider('losTresBoletines')]
    public function test_el_boletin_del_independiente_trae_solo_sus_unidades(string $ruta): void
    {
        $e = $this->escenario();
        $this->montarElCaso($e);

        $suyas = $this->definiciones($this->pedir($ruta, $e, $e->marcado), 'definicion_unidad');

        $this->assertNotEmpty($suyas,
            "`{$ruta}` no devolvió ni una unidad para el alumno marcado, así que este test no "
            .'distingue nada. Mira antes el `periodo_a_calcular` y el periodo del token.');

        $this->assertContains(self::UNIDAD_PROPIA, $suyas,
            "`{$ruta}` no le da al independiente su propia unidad: su boletín sale en blanco. Es "
            .'la forma «de menos» de la §9.2, la que se ve si el alcance se escribió con `=` en '
            .'vez de `<=>` o si a ese llamador se le pasó el alumno equivocado.');

        $this->assertNotContains(self::UNIDAD_GRUPO, $suyas,
            "`{$ruta}` le mete al independiente una unidad DEL GRUPO. Su boletín es aparte: eso "
            .'es la forma «de más», y le infla la definitiva con lo que no le tocaba.');
    }

    /**
     * **Y al compañero no le entra ninguna de las suyas.**
     *
     * Va aparte y no dentro del de arriba porque **el perjudicado es otro**: aquí el
     * afectado no es el alumno marcado sino los treinta de al lado, y ninguno de ellos
     * tiene forma de enterarse.
     */
    #[DataProvider('losTresBoletines')]
    public function test_al_companero_no_le_entra_ninguna_del_independiente(string $ruta): void
    {
        $e = $this->escenario();
        $this->montarElCaso($e);

        $delCompanero = $this->definiciones($this->pedir($ruta, $e, $e->companero), 'definicion_unidad');

        $this->assertNotEmpty($delCompanero,
            "`{$ruta}` no devolvió ni una unidad para el compañero: el test no distingue nada.");

        $this->assertContains(self::UNIDAD_GRUPO, $delCompanero,
            "`{$ruta}` dejó al alumno normal sin la unidad del grupo. Es la forma «de menos» por "
            .'el otro lado: el boletín de los que no van aparte sale en blanco.');

        $this->assertNotContains(self::UNIDAD_PROPIA, $delCompanero,
            "`{$ruta}` cuela la unidad privada del independiente en el boletín del compañero. Es "
            .'el fallo que más caro sale: el perjudicado no es quien está marcado, son los demás.');
    }

    /**
     * **Las SUBUNIDADES, que es lo que pedía la fase 5 con esa palabra.**
     *
     * Sólo `boletines` — es el único de los tres que las emite, medido en la respuesta
     * y en las instantáneas. Y aquí es donde los números distintos hacen su trabajo:
     * **tres propias contra dos del grupo**, así que una implementación que trajera
     * justo las contrarias no sólo traería otros nombres, traería otra cuenta.
     */
    public function test_las_subunidades_del_independiente_son_las_suyas(): void
    {
        $e = $this->escenario();
        $this->montarElCaso($e);

        $suyas = $this->definiciones($this->pedir('boletines/detailed-notas', $e, $e->marcado), 'definicion_subunidad');
        $delCompanero = $this->definiciones($this->pedir('boletines/detailed-notas', $e, $e->companero), 'definicion_subunidad');

        $this->assertNotEmpty($suyas, '`boletines` no devolvió ni una subunidad para el marcado.');
        $this->assertNotEmpty($delCompanero, '`boletines` no devolvió ni una subunidad para el compañero.');

        $this->assertCount(self::CUANTAS_PROPIAS, $this->conPrefijo($suyas, self::SUB_PROPIA),
            'Al independiente no le llegan sus '.self::CUANTAS_PROPIAS.' subunidades propias.');

        $this->assertSame([], $this->conPrefijo($suyas, self::SUB_GRUPO),
            'Al independiente le llegan subunidades DEL GRUPO. Su boletín es aparte: éstas son '
            .'las que no vio y sobre las que no tiene nota — la §9.1 vestida de boletín normal.');

        $this->assertCount(self::CUANTAS_DEL_GRUPO, $this->conPrefijo($delCompanero, self::SUB_GRUPO),
            'Al compañero no le llegan las '.self::CUANTAS_DEL_GRUPO.' subunidades del grupo.');

        $this->assertSame([], $this->conPrefijo($delCompanero, self::SUB_PROPIA),
            'Al compañero le entran las subunidades privadas del independiente. Ésa es la mitad '
            .'que infla las definitivas de los treinta.');
    }

    /**
     * **El control que no puede faltar: sin nadie marcado, los dos ven lo mismo.**
     *
     * Es el criterio de aceptación de la §4 del plan —la fase 1 es aditiva— y aquí,
     * además, es lo que dice que **estos tests miden la marca y no el montaje**: la
     * unidad propia existe en la base en los dos casos, y lo único que cambia es la
     * fila de `bol_ind_periodos`.
     *
     * Se monta el caso **sin marcar**, así que la unidad con dueño está creada: sin
     * marca, el alcance de todo el mundo es `NULL` y **a nadie le sale**, ni siquiera a
     * su dueño. Ésa es la decisión 7 escrita en su forma más corta.
     */
    #[DataProvider('losTresBoletines')]
    public function test_sin_marcar_a_nadie_los_dos_ven_lo_mismo(string $ruta): void
    {
        $e = $this->escenario();

        $this->unidadConSubunidades($e, self::UNIDAD_GRUPO, null, self::SUB_GRUPO, self::CUANTAS_DEL_GRUPO);
        $this->unidadConSubunidades($e, self::UNIDAD_PROPIA, $e->marcado, self::SUB_PROPIA, self::CUANTAS_PROPIAS);

        // Extractor ESTRECHO: es el único caso que compara dos alumnos entre sí, y
        // `asignaturas_perdidas` difiere de uno a otro con razón. Ver su docblock.
        $delDueno = $this->unidadesDelBoletin($this->pedir($ruta, $e, $e->marcado));
        $delCompanero = $this->unidadesDelBoletin($this->pedir($ruta, $e, $e->companero));

        sort($delDueno);
        sort($delCompanero);

        $this->assertNotEmpty($delDueno, 'Sin unidades, este control no controla nada.');

        $this->assertSame($delDueno, $delCompanero,
            'Sin nadie marcado, dos alumnos del mismo grupo tienen que ver las mismas unidades en '
            ."`{$ruta}`. Si esto cae, la fase 1 NO fue aditiva y hay una respuesta moviéndose en "
            .'los quince colegios.');

        $this->assertNotContains(self::UNIDAD_PROPIA, $delDueno,
            'Sin la fila de `bol_ind_periodos`, ni siquiera al dueño de la unidad le sale: la fila '
            .'que falta significa «va con el grupo» (decisión 7). Si le sale, el alcance está '
            .'mirando `unidades.alumno_id` en vez de la marca.');
    }
}
