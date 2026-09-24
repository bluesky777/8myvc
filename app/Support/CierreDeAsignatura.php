<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * **El cierre por asignatura**: ¿el docente ya cerró esta asignatura en este periodo?
 *
 * Fase 2 de `myvc_front/PLAN-CIERRE-DE-PERIODO.md`. La tabla es
 * `cierres_asignatura` (`2026_09_24_300000_el_cierre_por_asignatura.php`) y la
 * regla, entera, es ésta:
 *
 * - **sin fila** → abierta. Así nace el colegio que actualiza: nada cambia hasta
 *   que alguien cierra una.
 * - `estado = cerrada` → cerrada.
 * - `estado = reabierta` con `reabierta_hasta` **en el futuro** → abierta (la
 *   rendija de coordinación). **Pasada la hora, cerrada otra vez**, sin que
 *   nadie escriba nada: la hora se compara al leer.
 *
 * ## Qué cierra y qué NO
 *
 * Cierra lo que gobierna `profes_pueden_editar_notas` —notas, indicadores,
 * frases, asistencia de esa asignatura— y **sólo para el tipo Profesor**, igual
 * que el candado del periodo: coordinación y superusuario siguen escribiendo.
 *
 * **No cierra la nivelación ni las definitivas.** «Cerrar una asignatura no
 * cierra su nivelación: son dos puertas distintas» (mock, propuesta B): el
 * diálogo de cierre le dice al docente *«Sí podrás nivelar lo perdido cuando el
 * colegio abra la semana de nivelaciones»*. Por eso `puedeNivelar()` y
 * `pueden_modificar_definitivas()` no miran esta tabla.
 *
 * ## Las horas van en hora de Bogotá
 *
 * Es la hora de `Reloj`, la única que escribe este repo: la rendija la teclea coordinación en hora local («hasta el 27 a
 * las 18:00») y se compara contra la hora local. Mezclar UTC aquí movería el
 * cierre cinco horas.
 */
class CierreDeAsignatura
{
    public const CERRADA = 'cerrada';
    public const REABIERTA = 'reabierta';

    public static function ahora(): Carbon
    {
        return Reloj::ahora();
    }

    /**
     * ¿Alguna de las asignaturas está cerrada en alguno de los periodos?
     *
     * Se cruzan **todos con todos**. Las peticiones reales tocan un par —una
     * columna de notas es una subunidad—, y cuando mezclan, basta que un cruce
     * esté cerrado para que la petición entera no pase: la regla de
     * `aplicarBanderasDelPeriodo`, escribir la mitad es peor que no escribir.
     *
     * @param  int|array<int>|null  $periodos
     * @param  int|array<int>|null  $asignaturas
     */
    public static function algunaCerrada(int|array|null $periodos, int|array|null $asignaturas): bool
    {
        $p = self::ids($periodos);
        $a = self::ids($asignaturas);

        if ($p === [] || $a === []) {
            return false;
        }

        $fila = DB::selectOne(
            'SELECT 1 AS si FROM cierres_asignatura
              WHERE periodo_id IN ('.self::marcas($p).')
                AND asignatura_id IN ('.self::marcas($a).')
                AND (estado = ? OR reabierta_hasta IS NULL OR reabierta_hasta <= ?)
              LIMIT 1',
            [...$p, ...$a, self::CERRADA, self::ahora()->toDateTimeString()]
        );

        return $fila !== null;
    }

    /**
     * Las filas de un periodo, por `asignatura_id`, con el estado **efectivo** ya
     * resuelto (`abierta` / `cerrada` / `reabierta`).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function delPeriodo(int $periodoId): array
    {
        $filas = DB::select(
            'SELECT c.*, CONCAT(pc.nombres, " ", pc.apellidos) AS cerrada_por_nombre,
                    CONCAT(pr.nombres, " ", pr.apellidos) AS reabierta_por_nombre
               FROM cierres_asignatura c
               LEFT JOIN users uc ON uc.id = c.cerrada_por
               LEFT JOIN profesores pc ON pc.user_id = uc.id AND pc.deleted_at IS NULL
               LEFT JOIN users ur ON ur.id = c.reabierta_por
               LEFT JOIN profesores pr ON pr.user_id = ur.id AND pr.deleted_at IS NULL
              WHERE c.periodo_id = ?',
            [$periodoId]
        );

        $salida = [];

        foreach ($filas as $f) {
            $salida[(int) $f->asignatura_id] = self::comoSeVe($f);
        }

        return $salida;
    }

    /** Una fila, o `null` si nunca se cerró. */
    public static function de(int $periodoId, int $asignaturaId): ?array
    {
        return self::delPeriodo($periodoId)[$asignaturaId] ?? null;
    }

    /** Lo que viaja al cliente: la fila con su estado efectivo. */
    public static function comoSeVe(object $f): array
    {
        $rendijaViva = $f->estado === self::REABIERTA
            && $f->reabierta_hasta !== null
            && $f->reabierta_hasta > self::ahora()->toDateTimeString();

        return [
            'asignatura_id' => (int) $f->asignatura_id,
            'periodo_id' => (int) $f->periodo_id,
            'estado' => $rendijaViva ? self::REABIERTA : self::CERRADA,
            'cerrada' => ! $rendijaViva,
            'cerrada_at' => $f->cerrada_at,
            'cerrada_por' => $f->cerrada_por !== null ? (int) $f->cerrada_por : null,
            'cerrada_por_nombre' => $f->cerrada_por_nombre ?? null,
            'reabierta_hasta' => $f->reabierta_hasta,
            'reabierta_at' => $f->reabierta_at,
            'reabierta_por_nombre' => $f->reabierta_por_nombre ?? null,
            'motivo' => $f->motivo,
        ];
    }

    /** El docente cierra (o vuelve a cerrar antes de que venza la rendija). */
    public static function cerrar(int $periodoId, int $asignaturaId, ?int $userId): void
    {
        $ahora = self::ahora()->toDateTimeString();

        DB::statement(
            'INSERT INTO cierres_asignatura
                    (periodo_id, asignatura_id, estado, cerrada_at, cerrada_por, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), cerrada_at = VALUES(cerrada_at),
                    cerrada_por = VALUES(cerrada_por), reabierta_hasta = NULL,
                    updated_at = VALUES(updated_at)',
            [$periodoId, $asignaturaId, self::CERRADA, $ahora, $userId, $ahora, $ahora]
        );
    }

    /**
     * Coordinación abre una rendija hasta `$hasta`. **Sólo sobre una fila que
     * existe**: reabrir lo que nunca se cerró no tiene sentido y devolvería una
     * fila que dice «cerrada por nadie».
     */
    public static function reabrir(int $periodoId, int $asignaturaId, Carbon $hasta, string $motivo, ?int $userId): bool
    {
        $ahora = self::ahora()->toDateTimeString();

        return DB::update(
            'UPDATE cierres_asignatura
                SET estado = ?, reabierta_hasta = ?, reabierta_at = ?, reabierta_por = ?,
                    motivo = ?, updated_at = ?
              WHERE periodo_id = ? AND asignatura_id = ?',
            [self::REABIERTA, $hasta->toDateTimeString(), $ahora, $userId, $motivo, $ahora,
                $periodoId, $asignaturaId]
        ) > 0;
    }

    /** @return array<int> */
    private static function ids(int|array|null $valor): array
    {
        $lista = is_array($valor) ? $valor : [$valor];

        return array_values(array_unique(array_map('intval', array_filter(
            $lista, fn ($v) => $v !== null && $v !== '' && (int) $v > 0
        ))));
    }

    private static function marcas(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
