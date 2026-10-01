<?php

namespace App\Support;

use App\Services\PuntoDeControlDeImportacion;
use Illuminate\Support\Facades\DB;

/**
 * **Borrar el historial hasta una fecha**, la pestaña «Limpieza» de `/auditoria`
 * (contrato 4). Sólo la usa `AuditoriaLimpiezaController`, detrás de
 * `Autoriza::esSuperusuario`.
 *
 * ## Qué se borra, y qué no
 *
 * Hasta la fecha incluida (`< hasta + 1 día`), y de cada tabla sólo si viene en `incluir`:
 *
 * - `auditoria` por `ocurrido_en` (hora de pared de Bogotá, `Reloj`). **Nunca** las
 *   líneas `limpieza`: son la constancia de las limpiezas anteriores.
 * - `importaciones.cambios` se pone a NULL en las `completada` cuya fecha
 *   (`COALESCE(fin, inicio)`, la del listado) cae dentro. La fila se queda.
 * - `bitacoras` por `created_at`, borradas o no (`deleted_at`).
 * - `historiales` por `created_at`, **salvo los que todavía tengan un token o una fila de
 *   `bitacoras` apuntándoles**. No es un adorno: `bitacoras.historial_id` es
 *   `ON DELETE CASCADE`, así que borrar el ingreso se llevaría bitácoras posteriores a la
 *   fecha, y `personal_access_tokens.historial_id` es `nullOnDelete`, así que una sesión
 *   todavía abierta perdería de qué ingreso sale lo que haga a partir de ahora.
 *   `auditoria.historial_id` no tiene clave ajena: esas líneas se quedan con el «Hecho
 *   desde» vacío, y es lo que cuenta `historial_huerfano`.
 *
 * `auditoria_limpiezas` no se toca nunca.
 *
 * ## Tandas, sin transacción
 *
 * `DELETE … WHERE … ORDER BY id LIMIT 5000` en bucle (y `UPDATE … ORDER BY id LIMIT` para
 * las importaciones): una sola tabla, sin JOIN, que es la forma que aceptan igual MySQL 8
 * y MariaDB 10.5. Cada tanda se confirma sola; si la petición se corta, lo borrado queda
 * borrado, la fila de `auditoria_limpiezas` dice hasta dónde se llegó, y volver a lanzar
 * la misma fecha termina el trabajo.
 */
final class LimpiezaDeAuditoria
{
    public const TABLAS = ['auditoria', 'importaciones', 'bitacoras', 'historiales'];

    /** Las casillas que vienen marcadas: todas salvo los ingresos. */
    public const POR_DEFECTO = ['auditoria', 'importaciones', 'bitacoras'];

    public const TANDA = 5000;

    /** La entidad de `auditoria` con la que se apunta cada limpieza. Nunca se borra. */
    public const ENTIDAD = 'limpieza';

    public const EN_PROCESO = 'en_proceso';

    public const TERMINADA = 'terminada';

    public const CORTADA = 'cortada';

    /**
     * El último día que se puede elegir: **el último mes no se borra nunca** (Joseth, 30 sep 2026).
     * Hoy menos un mes, y un día más atrás: el 30 de septiembre se puede borrar como mucho hasta
     * el 29 de agosto, incluido. `NoOverflow` para que el 31 de marzo dé el 28 de febrero y no el
     * 3 de marzo. En la hora de pared del colegio.
     */
    public static function ultimoDiaQueSePuedeBorrar(): string
    {
        return Reloj::ahora()->subMonthNoOverflow()->subDay()->format('Y-m-d');
    }

    /** El primer instante que NO se borra: el día siguiente a `hasta`, a las 00:00. */
    public static function limite(string $hasta): string
    {
        return date('Y-m-d', (int) strtotime($hasta.' +1 day')).' 00:00:00';
    }

    /**
     * Lo que se borraría. Sólo lectura.
     *
     * @param  list<string>  $incluir
     * @return array<string, mixed>
     */
    public static function previa(string $hasta, array $incluir): array
    {
        $limite = self::limite($hasta);
        $con = fn (string $tabla) => in_array($tabla, $incluir, true);

        $cuenta = array_fill_keys(self::TABLAS, 0);
        $porFamilia = ['datos' => 0, 'notas' => 0, 'convivencia' => 0, 'otras' => 0];
        $periodos = [];
        $sinPeriodo = 0;
        $tocaPeriodo = false;
        $tocaAnio = false;
        $fechas = [];
        $quedan = [];

        $actual = DB::selectOne('SELECT p.id, p.numero, p.fecha_inicio, p.year_id, y.year
              FROM periodos p JOIN years y ON y.id = p.year_id
             WHERE y.actual = 1 AND p.actual = 1 AND p.deleted_at IS NULL AND y.deleted_at IS NULL
             ORDER BY p.id LIMIT 1');
        $anioActual = DB::selectOne('SELECT y.id, y.year,
                   (SELECT MIN(p.fecha_inicio) FROM periodos p WHERE p.year_id = y.id AND p.deleted_at IS NULL) AS inicio
              FROM years y WHERE y.actual = 1 AND y.deleted_at IS NULL ORDER BY y.id LIMIT 1');

        if ($con('auditoria')) {
            $familiaDe = [];
            foreach (array_keys(ListadoDeAuditoria::FAMILIAS) as $familia) {
                foreach (ListadoDeAuditoria::entidadesDe($familia) as $entidad) {
                    $familiaDe[$entidad] = $familia;
                }
            }

            $grupos = DB::select('SELECT a.periodo_id, a.entidad, COUNT(*) AS total, MIN(a.ocurrido_en) AS primera
                  FROM auditoria a
                 WHERE a.ocurrido_en < ? AND a.entidad <> ?
                 GROUP BY a.periodo_id, a.entidad', [$limite, self::ENTIDAD]);

            $aBorrar = [];
            foreach ($grupos as $g) {
                $n = (int) $g->total;
                $cuenta['auditoria'] += $n;
                $porFamilia[$familiaDe[$g->entidad] ?? 'otras'] += $n;
                $fechas[] = $g->primera;
                if ($g->periodo_id !== null) {
                    $aBorrar[(int) $g->periodo_id] = ($aBorrar[(int) $g->periodo_id] ?? 0) + $n;
                }
            }

            $conocidos = [];
            if ($aBorrar !== []) {
                $ids = array_keys($aBorrar);
                $marcas = implode(',', array_fill(0, count($ids), '?'));
                $conocidos = DB::select("SELECT p.id, p.numero, p.year_id, y.year
                      FROM periodos p LEFT JOIN years y ON y.id = p.year_id
                     WHERE p.id IN ($marcas)", $ids);
                $siguen = [];
                foreach (DB::select("SELECT a.periodo_id, COUNT(*) AS total FROM auditoria a
                         WHERE a.ocurrido_en >= ? AND a.periodo_id IN ($marcas)
                         GROUP BY a.periodo_id", [$limite, ...$ids]) as $s) {
                    $siguen[(int) $s->periodo_id] = (int) $s->total;
                }
                // Las líneas `limpieza` no llevan periodo, así que no hace falta restarlas.

                usort($conocidos, fn ($x, $y) => [(int) $x->year, (int) $x->numero, (int) $x->id]
                    <=> [(int) $y->year, (int) $y->numero, (int) $y->id]);
                foreach ($conocidos as $p) {
                    $borra = $aBorrar[(int) $p->id];
                    $total = $borra + ($siguen[(int) $p->id] ?? 0);
                    $periodos[] = [
                        'periodo_id' => (int) $p->id,
                        'year' => $p->year === null ? null : (int) $p->year,
                        'numero' => (int) $p->numero,
                        'total_linea' => $total,
                        'a_borrar' => $borra,
                        'completo' => $borra === $total,
                        'actual' => $actual !== null && (int) $p->id === (int) $actual->id,
                    ];
                    if ($actual !== null && (int) $p->id === (int) $actual->id) {
                        $tocaPeriodo = true;
                    }
                    if ($anioActual !== null && (int) $p->year_id === (int) $anioActual->id) {
                        $tocaAnio = true;
                    }
                }
            }
            // Sin periodo: las que no lo traen y las que apuntan a un periodo que ya no está.
            $sinPeriodo = $cuenta['auditoria'] - array_sum(array_column($periodos, 'a_borrar'));

            // El año, derivado como en el listado: el de la línea, si no el de su periodo,
            // si no el de su grupo.
            if (! $tocaAnio && $anioActual !== null) {
                $tocaAnio = DB::selectOne('SELECT 1 AS si FROM auditoria a
                     WHERE a.ocurrido_en < ? AND a.entidad <> ?
                       AND (a.year_id = ? OR (a.year_id IS NULL AND (
                            a.periodo_id IN (SELECT p.id FROM periodos p WHERE p.year_id = ?)
                            OR (a.periodo_id IS NULL AND a.grupo_id IN (SELECT g.id FROM grupos g WHERE g.year_id = ?)))))
                     LIMIT 1', [$limite, self::ENTIDAD, $anioActual->id, $anioActual->id, $anioActual->id]) !== null;
            }

            $quedan[] = DB::selectOne('SELECT MIN(a.ocurrido_en) AS f FROM auditoria a WHERE a.ocurrido_en >= ?', [$limite])->f;
        }

        // Además de las líneas, la propia fecha: si llega al inicio del periodo (o del año)
        // actual, se borraría lo de este periodo aunque hoy no haya líneas con él.
        if ($actual !== null && $actual->fecha_inicio !== null && $hasta >= $actual->fecha_inicio) {
            $tocaPeriodo = true;
        }
        if ($anioActual !== null && $anioActual->inicio !== null && $hasta >= $anioActual->inicio) {
            $tocaAnio = true;
        }

        if ($con('importaciones')) {
            $fila = DB::selectOne('SELECT COUNT(*) AS total, MIN(COALESCE(i.fin, i.inicio)) AS primera
                  FROM importaciones i
                 WHERE i.estado = ? AND i.cambios IS NOT NULL AND COALESCE(i.fin, i.inicio) < ?',
                [PuntoDeControlDeImportacion::COMPLETADA, $limite]);
            $cuenta['importaciones'] = (int) $fila->total;
            $fechas[] = $fila->primera;
        }

        if ($con('bitacoras')) {
            $fila = DB::selectOne('SELECT COUNT(*) AS total, MIN(b.created_at) AS primera
                  FROM bitacoras b WHERE b.created_at < ?', [$limite]);
            $cuenta['bitacoras'] = (int) $fila->total;
            $fechas[] = $fila->primera;
            $quedan[] = DB::selectOne('SELECT MIN(b.created_at) AS f FROM bitacoras b WHERE b.created_at >= ?', [$limite])->f;
        }

        $huerfano = 0;
        if ($con('historiales')) {
            [$donde, $parametros] = self::historialesQueSeBorran($limite, $con('bitacoras'));
            $fila = DB::selectOne("SELECT COUNT(*) AS total, MIN(h.created_at) AS primera FROM historiales h WHERE $donde", $parametros);
            $cuenta['historiales'] = (int) $fila->total;
            $fechas[] = $fila->primera;
            $quedan[] = DB::selectOne('SELECT MIN(h.created_at) AS f FROM historiales h WHERE h.created_at >= ?', [$limite])->f;

            // Los cambios que se quedan y apuntan a un ingreso que se va.
            // Partido en dos (lo que queda por fecha, y las líneas `limpieza` viejas) para que
            // cada mitad vaya por `aud_fecha`: con un OR, MySQL recorre la tabla entera.
            $contar = fn (string $queQueda, array $mas) => (int) DB::selectOne("SELECT COUNT(*) AS total FROM auditoria a
                  JOIN historiales h ON h.id = a.historial_id
                 WHERE $donde $queQueda", [...$parametros, ...$mas])->total;
            $huerfano = $con('auditoria')
                ? $contar('AND a.ocurrido_en >= ?', [$limite]) + $contar('AND a.ocurrido_en < ? AND a.entidad = ?', [$limite, self::ENTIDAD])
                : $contar('', []);
        }

        $fechas = array_filter($fechas, fn ($f) => $f !== null);
        $quedan = array_filter($quedan, fn ($f) => $f !== null);

        return [
            'hasta' => $hasta,
            'incluir' => $incluir,
            'cuenta' => $cuenta,
            'por_familia' => $porFamilia,
            'periodos' => $periodos,
            'sin_periodo' => $sinPeriodo,
            'toca_periodo_actual' => $tocaPeriodo,
            'toca_anio_actual' => $tocaAnio,
            'periodo_actual' => $actual === null ? null : [
                'periodo_id' => (int) $actual->id,
                'year' => (int) $actual->year,
                'numero' => (int) $actual->numero,
            ],
            'primera_fecha' => $fechas === [] ? null : (string) min($fechas),
            // El primer día que queda intacto: el siguiente a la fecha tope.
            'queda_desde' => substr($limite, 0, 10),
            'ultima_fecha_que_queda' => $quedan === [] ? null : (string) min($quedan),
            'historial_huerfano' => $huerfano,
        ];
    }

    /**
     * Borra en tandas y va apuntando lo borrado en su fila de `auditoria_limpiezas`.
     * Orden: las importaciones, la auditoría, la bitácora y al final los ingresos, que
     * dependen de qué bitácoras quedan.
     *
     * @param  list<string>  $incluir
     * @return array<string, int>
     */
    public static function borrar(string $hasta, array $incluir, int $limpiezaId): array
    {
        $limite = self::limite($hasta);
        $borrados = array_fill_keys(self::TABLAS, 0);

        $enTandas = function (string $tabla, string $sql, array $parametros) use (&$borrados, $limpiezaId) {
            do {
                $n = DB::affectingStatement($sql.' ORDER BY id LIMIT '.self::TANDA, $parametros);
                $borrados[$tabla] += $n;
                DB::table('auditoria_limpiezas')->where('id', $limpiezaId)
                    ->update(['borrados_'.$tabla => $borrados[$tabla]]);
            } while ($n >= self::TANDA);
        };

        if (in_array('importaciones', $incluir, true)) {
            $enTandas('importaciones', 'UPDATE importaciones SET cambios = NULL
                 WHERE estado = ? AND cambios IS NOT NULL AND COALESCE(fin, inicio) < ?',
                [PuntoDeControlDeImportacion::COMPLETADA, $limite]);
        }

        if (in_array('auditoria', $incluir, true)) {
            $enTandas('auditoria', 'DELETE FROM auditoria WHERE ocurrido_en < ? AND entidad <> ?',
                [$limite, self::ENTIDAD]);
        }

        if (in_array('bitacoras', $incluir, true)) {
            $enTandas('bitacoras', 'DELETE FROM bitacoras WHERE created_at < ?', [$limite]);
        }

        if (in_array('historiales', $incluir, true)) {
            // Sin alias: el alias en un `DELETE` de una sola tabla no lo aceptan MariaDB 10.5
            // ni MySQL anterior a 8.0.16, así que la condición nombra la tabla entera. Y aquí
            // cuenta cualquier bitácora que quede: si la casilla de bitácoras no venía, las
            // viejas siguen ahí y el CASCADE se las llevaría.
            [$donde, $parametros] = self::historialesQueSeBorran($limite, false, 'historiales');
            $enTandas('historiales', 'DELETE FROM historiales WHERE '.$donde, $parametros);
        }

        return $borrados;
    }

    /**
     * Los ingresos hasta la fecha que se pueden borrar sin arrastrar nada: ni un token
     * vivo ni una fila de `bitacoras` les apunta. Con `$bitacorasHastaLaFecha`, la previa
     * cuenta como que las bitácoras hasta la fecha ya no estarán (se borran antes).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function historialesQueSeBorran(string $limite, bool $bitacorasHastaLaFecha, string $h = 'h'): array
    {
        $bitacora = $bitacorasHastaLaFecha ? 'AND (b.created_at >= ? OR b.created_at IS NULL)' : '';

        return [
            "$h.created_at < ?
             AND NOT EXISTS (SELECT 1 FROM personal_access_tokens t WHERE t.historial_id = $h.id)
             AND NOT EXISTS (SELECT 1 FROM bitacoras b WHERE b.historial_id = $h.id $bitacora)",
            $bitacorasHastaLaFecha ? [$limite, $limite] : [$limite],
        ];
    }

    /**
     * Las limpiezas hechas, de la más nueva a la más vieja.
     *
     * @return list<array<string, mixed>>
     */
    public static function historial(): array
    {
        $filas = DB::select('SELECT * FROM auditoria_limpiezas ORDER BY id DESC');

        // La foto, como en los desplegables de la auditoría (`ListadoDeAuditoria::personas`).
        $fotos = [];
        $quienes = [];
        foreach ($filas as $f) {
            if ($f->user_id !== null) {
                $quienes[(int) $f->user_id] = (object) ['user_id' => (int) $f->user_id, 'nombre' => $f->actor_nombre, 'tipo' => null];
            }
        }
        foreach (ListadoDeAuditoria::personas(array_values($quienes)) as $p) {
            $fotos[$p['user_id']] = $p['foto'];
        }

        return array_values(array_map(fn ($f) => [
            'id' => (int) $f->id,
            'user_id' => $f->user_id === null ? null : (int) $f->user_id,
            'nombre' => $f->actor_nombre,
            'foto' => $f->user_id === null ? null : ($fotos[(int) $f->user_id] ?? null),
            'hasta' => $f->hasta,
            'incluir' => $f->incluir === '' ? [] : explode(',', $f->incluir),
            'previa' => [
                'auditoria' => (int) $f->previa_auditoria,
                'importaciones' => (int) $f->previa_importaciones,
                'bitacoras' => (int) $f->previa_bitacoras,
                'historiales' => (int) $f->previa_historiales,
            ],
            'cuenta' => [
                'auditoria' => (int) $f->borrados_auditoria,
                'importaciones' => (int) $f->borrados_importaciones,
                'bitacoras' => (int) $f->borrados_bitacoras,
                'historiales' => (int) $f->borrados_historiales,
            ],
            'inicio' => $f->inicio,
            'fin' => $f->fin,
            'estado' => $f->estado,
            'error' => $f->error,
        ], $filas));
    }
}
