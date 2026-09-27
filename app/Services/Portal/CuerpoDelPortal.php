<?php

namespace App\Services\Portal;

use App\Support\NotaImpresa;
use App\Support\Reloj;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El resumen de UN año de este colegio, tal como lo recibe el portal de la Unión.
 *
 * La especificación es `myvc_ucn/docs/01-diseno-tecnico.md` §2.1, y **es una lista
 * blanca**: aquí se emite un conjunto declarado de claves, nunca se recorren
 * columnas. Lo que no está escrito abajo no sale. `PortalCuerpoTest` congela el
 * conjunto (61 rutas, v2) y `NadaDeMenoresTest` serializa el cuerpo entero y
 * busca dentro nombres y documentos sembrados.
 *
 * **Cambiar una clave es cambiar el contrato**: sube `VERSION`, snapshot nuevo, y
 * el portal acepta las dos durante los meses en que `app/` viaja escalonado (§3.3).
 *
 * ## Los criterios, y de dónde salen
 *
 * - **Activo = `estado IN ('MATR','ASIS')`**, matrícula, alumno y grupo sin borrar.
 *   Es el criterio de los contadores de grupos (`GruposController::getCantAlumnos`,
 *   `putConCantidadAlumnos`, `getAlumnosDe`). `fecha_retiro` NO decide: el código
 *   no la mira nunca para saber quién está, sólo para ventanas de retirados. PREM,
 *   PREA y FORM son de camino a matricularse y no están en clase.
 * - **Retirado = `estado IN ('RETI','DESE')`**, en todo el año — el mismo par que
 *   usa `GruposController` (allí además acotado a las fechas de un periodo).
 * - **Pérdidas y promedios se cuentan EN VIVO desde `notas_finales`**, no desde
 *   `matriculas.cant_asign_perdidas` / `promedio`: esas dos columnas sólo las
 *   escribe `promovidos/calcular-grupo` al cerrar el año. Medido el 27 sep 2026 en
 *   cuatro copias reales: en 2026 están a 0 en el 100 % de las matrículas activas,
 *   así que leerlas diría «nadie pierde nada» todo el año en curso.
 *   Sin el periodo en curso del año en curso (ver `alumnosActivos`).
 *   La cuenta es la de `PromovidosController`: definitiva de la asignatura = media
 *   de sus definitivas de periodo; perdida si `NotaImpresa::perdida()` contra
 *   `years.nota_minima_aceptada`; promedio del alumno = media de sus asignaturas.
 *   **Diferencia a propósito**: sólo cuentan asignaturas con alguna definitiva. El
 *   cierre cuenta las vacías como 0 (y perdidas); a mitad de año eso sería contar
 *   como perdido lo que aún no se ha calificado.
 * - Un alumno activo sin ninguna definitiva cae en `sin_perdidas` y no entra en
 *   el promedio. Por eso **los tres cubos suman siempre `alumnos_activos`**.
 * - **Docentes** = profesores sin borrar con contrato sin borrar en el año
 *   (`Profesor.php:191`, `ContratosController`).
 * - **Procesos disciplinarios «abiertos»**: `dis_procesos` no tiene estado ni
 *   cierre, así que se cuentan todos los del año sin borrar. Si algún día hay
 *   columna de cierre, este número baja y la clave sigue siendo la misma.
 * - **Ausencias del periodo** = suma de `cantidad_ausencia` en el periodo actual
 *   del año (o el último, si ninguno está marcado); 0 si el año no tiene periodos.
 */
final class CuerpoDelPortal
{
    public const VERSION = 2;

    /** §2.1.2: por debajo de esto, las derivadas de un grado son el dato de un alumno. */
    public const UMBRAL_GRADO = 5;

    /** Los estados que están en clase (ver arriba). */
    private const ACTIVOS = "('MATR','ASIS')";

    private const RETIRADOS = "('RETI','DESE')";

    /**
     * @return array<string, mixed>
     */
    public function armar(int $anio, bool $retroactivo = false, ?Carbon $ahora = null): array
    {
        $ahora = ($ahora ?? Reloj::ahora())->copy()->setTimezone(Reloj::ZONA);

        $year = DB::selectOne('SELECT id, year, nombre_colegio, nota_minima_aceptada
            FROM years WHERE year = ? AND deleted_at IS NULL
            ORDER BY actual DESC, id DESC LIMIT 1', [$anio]);
        if ($year === null) {
            throw new RuntimeException("No hay año {$anio} en `years`.");
        }
        $yearId = (int) $year->id;
        $minima = $year->nota_minima_aceptada;

        $periodo = DB::selectOne('SELECT id, numero FROM periodos
            WHERE year_id = ? AND actual = 1 AND deleted_at IS NULL
            ORDER BY numero DESC LIMIT 1', [$yearId]);

        $alumnos = $this->alumnosActivos($yearId, $minima);
        $retiradosPorGrado = $this->retiradosPorGrado($yearId);

        return [
            'sobre' => [
                'version_emisor' => self::VERSION,
                'codigo_dane' => self::codigoDane(),
                'anio' => (int) $year->year,
                'fecha_corte' => $ahora->format('Y-m-d'),
                'es_retroactivo' => $retroactivo,
                'generado_en' => $ahora->format('c'),
            ],
            'colegio' => [
                'nombre_colegio' => (string) $year->nombre_colegio,
                'periodo_actual' => $periodo === null ? null : (int) $periodo->numero,
            ],
            'matricula' => $this->matricula($yearId, $alumnos, array_sum($retiradosPorGrado)),
            'academico' => $this->academico($yearId, $alumnos),
            'convivencia' => [
                'procesos_disciplinarios_abiertos' => $this->contar('SELECT COUNT(*) AS n FROM dis_procesos
                    WHERE year_id = ? AND deleted_at IS NULL', [$yearId]),
                // Nunca null: el receptor la declara `conteo` (no anulable) y un año
                // pasado sin periodo marcado como actual rompería la carga inicial
                // con un 422. Sin periodo actual, el último del año; sin ninguno, 0.
                'ausencias_del_periodo' => $this->contar(
                    'SELECT COALESCE(SUM(au.cantidad_ausencia), 0) AS n FROM ausencias au
                     WHERE au.deleted_at IS NULL AND au.periodo_id = (
                        SELECT p.id FROM periodos p WHERE p.year_id = ? AND p.deleted_at IS NULL
                        ORDER BY p.actual DESC, p.numero DESC LIMIT 1)', [$yearId]),
            ],
            'escala' => $this->escala($yearId, $year->nota_minima_aceptada),
            'despliegue' => Despliegue::parte(),
            'grados' => $this->grados($yearId, $alumnos, $retiradosPorGrado),
            'salud' => ['meses' => $this->salud((int) $year->year, $ahora)],
        ];
    }

    /**
     * El remitente: el código DANE de HOY, no el del año que se manda.
     *
     * `codigo_dane` vive en `years` y se copia año a año, así que es un dato por
     * año que casi nunca cambia — pero **cambia**. Medido el 27 sep 2026 en
     * `micolev1_la_hermosa`: 2019 lleva `381736001849` y 2020–2026 `481794005085`
     * (un colegio nuevo se crea copiando otro, y su primer año se quedó con el
     * DANE del de origen). Con el del año, la carga inicial de 2019 llegó al
     * portal firmada como OTRO colegio y el portal la rechazó con 401 — o, peor,
     * la habría aceptado si ese otro tuviera clave. El remitente es una identidad
     * del colegio, no un atributo del año: se toma del año actual, y si no lo
     * tiene, del más reciente que lo tenga. Lo comparte `portal:respaldo-inicial`
     * para el GET, que tiene que preguntar como el mismo remitente que luego manda.
     */
    public static function codigoDane(): ?string
    {
        $fijado = trim((string) config('portal.dane'));
        if ($fijado !== '') {
            return $fijado;   // PORTAL_DANE manda sobre `years` (config/portal.php)
        }

        $fila = DB::selectOne("SELECT codigo_dane FROM years
            WHERE deleted_at IS NULL AND codigo_dane IS NOT NULL AND TRIM(codigo_dane) <> ''
            ORDER BY actual DESC, year DESC LIMIT 1");

        return $fila === null ? null : trim((string) $fila->codigo_dane);
    }

    /**
     * Los bytes que se firman y se mandan. Se firman ESTOS, no una reserialización
     * (§3.1 punto 1).
     *
     * @param  array<string, mixed>  $cuerpo
     */
    public static function json(array $cuerpo): string
    {
        return json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Una fila por matrícula activa, con sus pérdidas y su promedio ya contados.
     * Sale del colegio sólo agregada; esta lista no se devuelve nunca.
     *
     * @return list<object{grado_id:int, sexo:string, nuevo:bool, repitente:bool, perdidas:int, promedio:float|null}>
     */
    private function alumnosActivos(int $yearId, mixed $minima): array
    {
        $filas = DB::select('SELECT m.id, g.grado_id, a.sexo, m.nuevo, m.repitente
            FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN '.self::ACTIVOS, [$yearId]);

        // Media de las definitivas de periodo por (matrícula, asignatura de SU grupo).
        //
        // **Sin el periodo en curso del año en curso.** Medido el 27 sep 2026 en
        // `caz_zaragoza`: el periodo 3 de 2026, abierto, tenía 523 definitivas a 0
        // de 1.602 (media 24,6 frente a 41–43 de los cerrados) — casillas aún sin
        // calificar—, y con él 72 de 214 alumnos salían con 3 o más perdidas. Un
        // periodo a medio calificar no es un hecho del colegio todavía. En un año
        // que ya no es el actual, su último periodo sí cuenta aunque siga marcado.
        $definitivas = DB::select('SELECT m.id AS matricula_id, AVG(nf.nota) AS nota
            FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            INNER JOIN asignaturas asg ON asg.grupo_id = g.id AND asg.deleted_at IS NULL
            INNER JOIN materias mat ON mat.id = asg.materia_id AND mat.deleted_at IS NULL
            INNER JOIN notas_finales nf ON nf.alumno_id = m.alumno_id AND nf.asignatura_id = asg.id
            INNER JOIN periodos p ON p.id = nf.periodo_id AND p.year_id = g.year_id AND p.deleted_at IS NULL
            INNER JOIN years y ON y.id = g.year_id
            WHERE m.deleted_at IS NULL AND m.estado IN '.self::ACTIVOS.'
              AND NOT (y.actual = 1 AND p.actual = 1)
            GROUP BY m.id, asg.id', [$yearId]);

        $porMatricula = [];
        foreach ($definitivas as $d) {
            $porMatricula[(int) $d->matricula_id][] = (float) $d->nota;
        }

        $alumnos = [];
        foreach ($filas as $f) {
            $notas = $porMatricula[(int) $f->id] ?? [];
            $perdidas = 0;
            foreach ($notas as $nota) {
                if (NotaImpresa::perdida($nota, $minima)) {
                    $perdidas++;
                }
            }
            $alumnos[] = (object) [
                'grado_id' => (int) $f->grado_id,
                'sexo' => strtoupper(trim((string) $f->sexo)),
                'nuevo' => (bool) $f->nuevo,
                'repitente' => (bool) $f->repitente,
                'perdidas' => $perdidas,
                'promedio' => $notas === [] ? null : array_sum($notas) / count($notas),
            ];
        }

        return $alumnos;
    }

    /** @return array<int, int> grado_id => retirados */
    private function retiradosPorGrado(int $yearId): array
    {
        $filas = DB::select('SELECT g.grado_id, COUNT(*) AS n
            FROM matriculas m
            INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
            INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.estado IN '.self::RETIRADOS.'
            GROUP BY g.grado_id', [$yearId]);

        $porGrado = [];
        foreach ($filas as $f) {
            $porGrado[(int) $f->grado_id] = (int) $f->n;
        }

        return $porGrado;
    }

    /**
     * @param  list<object>  $alumnos
     * @return array<string, int>
     */
    private function matricula(int $yearId, array $alumnos, int $retirados): array
    {
        return [
            'alumnos_activos' => count($alumnos),
            'alumnos_retirados' => $retirados,
            'alumnos_nuevos' => count(array_filter($alumnos, fn ($a) => $a->nuevo)),
            'alumnos_repitentes' => count(array_filter($alumnos, fn ($a) => $a->repitente)),
            'alumnos_f' => count(array_filter($alumnos, fn ($a) => $a->sexo === 'F')),
            'alumnos_m' => count(array_filter($alumnos, fn ($a) => $a->sexo === 'M')),
            'grupos' => $this->contar('SELECT COUNT(*) AS n FROM grupos WHERE year_id = ? AND deleted_at IS NULL', [$yearId]),
            'docentes' => $this->contar('SELECT COUNT(DISTINCT p.id) AS n FROM profesores p
                INNER JOIN contratos c ON c.profesor_id = p.id AND c.year_id = ? AND c.deleted_at IS NULL
                WHERE p.deleted_at IS NULL', [$yearId]),
            'asignaturas' => $this->contar('SELECT COUNT(*) AS n FROM asignaturas a
                INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                INNER JOIN materias mat ON mat.id = a.materia_id AND mat.deleted_at IS NULL
                WHERE a.deleted_at IS NULL', [$yearId]),
        ];
    }

    /**
     * @param  list<object>  $alumnos
     * @return array<string, int|float|null>
     */
    private function academico(int $yearId, array $alumnos): array
    {
        return $this->cubos($alumnos) + [
            'promedio_del_colegio' => $this->promedio($alumnos),
            'definitivas_registradas' => $this->contar('SELECT COUNT(*) AS n FROM notas_finales nf
                INNER JOIN periodos p ON p.id = nf.periodo_id AND p.year_id = ? AND p.deleted_at IS NULL', [$yearId]),
        ];
    }

    /**
     * @param  list<object>  $alumnos
     * @return array{alumnos_sin_perdidas:int, alumnos_con_1_2_perdidas:int, alumnos_con_3_o_mas_perdidas:int}
     */
    private function cubos(array $alumnos): array
    {
        return [
            'alumnos_sin_perdidas' => count(array_filter($alumnos, fn ($a) => $a->perdidas === 0)),
            'alumnos_con_1_2_perdidas' => count(array_filter($alumnos, fn ($a) => $a->perdidas >= 1 && $a->perdidas <= 2)),
            'alumnos_con_3_o_mas_perdidas' => count(array_filter($alumnos, fn ($a) => $a->perdidas >= 3)),
        ];
    }

    /** @param  list<object>  $alumnos */
    private function promedio(array $alumnos): ?float
    {
        $promedios = array_values(array_filter(array_map(fn ($a) => $a->promedio, $alumnos), fn ($p) => $p !== null));

        return $promedios === [] ? null : round(array_sum($promedios) / count($promedios), 2);
    }

    /**
     * Cuenta sólo los alumnos con alguna definitiva. Es lo que impide que un grado
     * de treinta con UN alumno calificado pase la regla del grado pequeño y mande
     * la nota de ese alumno como «promedio del grado».
     *
     * @param  list<object>  $alumnos
     */
    private function conNotas(array $alumnos): int
    {
        return count(array_filter($alumnos, fn ($a) => $a->promedio !== null));
    }

    /**
     * §2.1.2: con menos de 5 activos, las cuatro derivadas van a `null` y el
     * recuento de cabezas sí va. Y el promedio además exige 5 alumnos CON NOTAS
     * (ver `conNotas`): sin eso la regla se salta a mitad de periodo.
     *
     * @param  list<object>  $alumnos
     * @param  array<int, int>  $retiradosPorGrado
     * @return list<array<string, mixed>>
     */
    private function grados(int $yearId, array $alumnos, array $retiradosPorGrado): array
    {
        $grados = DB::select("SELECT gr.id, gr.nombre, COALESCE(NULLIF(TRIM(gr.abrev), ''), gr.nombre) AS grado
            FROM grados gr
            WHERE gr.id IN (SELECT g.grado_id FROM grupos g WHERE g.year_id = ? AND g.deleted_at IS NULL)
            ORDER BY gr.orden, gr.id", [$yearId]);

        // El receptor rechaza un cuerpo con grados repetidos, y la abreviatura la
        // escribe cada colegio a mano: dos grados con la misma pasan al nombre, y
        // si también coinciden, al nombre con su id.
        $veces = array_count_values(array_map(fn ($g) => (string) $g->grado, $grados));
        $nombres = array_count_values(array_map(fn ($g) => (string) $g->nombre, $grados));
        foreach ($grados as $gr) {
            if ($veces[(string) $gr->grado] > 1) {
                $gr->grado = $nombres[(string) $gr->nombre] > 1 ? $gr->nombre.' #'.$gr->id : $gr->nombre;
            }
        }

        $lista = [];
        foreach ($grados as $gr) {
            $suyos = array_values(array_filter($alumnos, fn ($a) => $a->grado_id === (int) $gr->id));
            $pequeno = count($suyos) < self::UMBRAL_GRADO;
            $cubos = $this->cubos($suyos);

            $lista[] = [
                'grado' => (string) $gr->grado,
                'alumnos_activos' => count($suyos),
                'alumnos_retirados' => $retiradosPorGrado[(int) $gr->id] ?? 0,
                'alumnos_repitentes' => count(array_filter($suyos, fn ($a) => $a->repitente)),
                'alumnos_f' => count(array_filter($suyos, fn ($a) => $a->sexo === 'F')),
                'alumnos_m' => count(array_filter($suyos, fn ($a) => $a->sexo === 'M')),
                'alumnos_sin_perdidas' => $pequeno ? null : $cubos['alumnos_sin_perdidas'],
                'alumnos_con_1_2_perdidas' => $pequeno ? null : $cubos['alumnos_con_1_2_perdidas'],
                'alumnos_con_3_o_mas_perdidas' => $pequeno ? null : $cubos['alumnos_con_3_o_mas_perdidas'],
                'promedio_del_grado' => $pequeno || $this->conNotas($suyos) < self::UMBRAL_GRADO
                    ? null : $this->promedio($suyos),
            ];
        }

        return $lista;
    }

    /**
     * La escala con la que este colegio interpreta sus notas (§1.6). El portal no
     * reinterpreta nada: recibe la escala al lado de los números.
     *
     * @return array<string, mixed>
     */
    private function escala(int $yearId, mixed $minima): array
    {
        $niveles = DB::select('SELECT desempenio, porc_inicial, porc_final, perdido
            FROM escalas_de_valoracion WHERE year_id = ? AND deleted_at IS NULL
            ORDER BY orden, porc_inicial', [$yearId]);

        return [
            'escala_min' => $niveles === [] ? null : min(array_map(fn ($n) => (int) $n->porc_inicial, $niveles)),
            'escala_max' => $niveles === [] ? null : max(array_map(fn ($n) => (int) $n->porc_final, $niveles)),
            // varchar(3) tal cual: «30» y «3.0» no son lo mismo y no se adivina.
            'nota_minima_aceptada' => $minima === null ? null : (string) $minima,
            'niveles' => array_map(fn ($n) => [
                'desempenio' => (string) $n->desempenio,
                'porc_inicial' => (int) $n->porc_inicial,
                'porc_final' => (int) $n->porc_final,
                'perdido' => (bool) $n->perdido,
            ], $niveles),
        ];
    }

    /**
     * §2.1.6: dos conteos por mes, nunca por grado, motivo ni alumno.
     *
     * La lista empieza en el mes del primer registro del año —antes no existía la
     * enfermería en ese colegio— y llega hasta el mes de `fecha_corte` (o a
     * diciembre si el año ya pasó). Los meses de en medio sin filas van con 0:
     * «ese mes no hubo atenciones» es un dato. Sin registros, lista vacía, y el
     * portal deja al colegio fuera de salud en vez de dentro con cero.
     *
     * `registros_enfermeria` no tiene `year_id` ni `deleted_at`: el año sale de
     * `fecha_suceso`, en un rango (usa el índice si lo hay; `YEAR()` no).
     *
     * @return list<array{mes:int, atenciones:int, alumnos_atendidos:int}>
     */
    private function salud(int $anio, Carbon $corte): array
    {
        $desde = sprintf('%04d-01-01 00:00:00', $anio);
        $hastaAnio = sprintf('%04d-01-01 00:00:00', $anio + 1);
        $hastaCorte = $corte->copy()->addDay()->format('Y-m-d 00:00:00');
        $hasta = min($hastaAnio, $hastaCorte);

        $filas = DB::select('SELECT MONTH(fecha_suceso) AS mes, COUNT(*) AS atenciones,
                COUNT(DISTINCT alumno_id) AS alumnos
            FROM registros_enfermeria
            WHERE fecha_suceso >= ? AND fecha_suceso < ?
            GROUP BY MONTH(fecha_suceso)', [$desde, $hasta]);

        if ($filas === []) {
            return [];
        }

        $porMes = [];
        foreach ($filas as $f) {
            $porMes[(int) $f->mes] = $f;
        }
        $primero = min(array_keys($porMes));
        $ultimo = (int) $corte->format('Y') > $anio ? 12 : (int) $corte->format('n');

        $meses = [];
        for ($mes = $primero; $mes <= $ultimo; $mes++) {
            $meses[] = [
                'mes' => $mes,
                'atenciones' => isset($porMes[$mes]) ? (int) $porMes[$mes]->atenciones : 0,
                'alumnos_atendidos' => isset($porMes[$mes]) ? (int) $porMes[$mes]->alumnos : 0,
            ];
        }

        return $meses;
    }

    /** @param  list<mixed>  $datos */
    private function contar(string $sql, array $datos): int
    {
        return (int) (DB::selectOne($sql, $datos)->n ?? 0);
    }
}
