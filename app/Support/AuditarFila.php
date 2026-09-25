<?php

namespace App\Support;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * UNA ESCRITURA DE UNA FILA, CON SU ANTES Y SU DESPUÉS. Para las pantallas de
 * configuración —Plan de evaluación y la configuración del año— cuyo historial se lee
 * en `auditoria/anio/{year_id}`: cada cambio tiene que poder decir «de qué a qué».
 *
 * Lee la fila antes de escribir y después, y apunta sólo las columnas que cambiaron.
 * Vale igual para un `UPDATE` a mano que para un `->save()`: no mira cómo se escribe,
 * mira la fila. Si no cambió nada, no escribe línea.
 *
 * Las columnas de sello no cuentan; un `deleted_at` que se llena es un BORRADO.
 */
final class AuditarFila
{
    private const SELLOS = ['created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_by'];

    /**
     * Ejecuta `$escribir` y deja la línea. Devuelve lo que devuelva `$escribir`.
     */
    public static function cambio(string $entidad, string $tabla, int $id, callable $escribir, ?int $year = null, ?string $resumen = null): mixed
    {
        $antes = self::leer($tabla, $id);
        $resultado = $escribir();
        $despues = self::leer($tabla, $id);

        self::apuntar($entidad, $tabla, $id, $antes, $despues, $year, $resumen);

        return $resultado;
    }

    /** Una fila recién creada: la línea lleva la fila entera como «después». */
    public static function creada(string $entidad, string $tabla, int $id, ?int $year = null, ?string $resumen = null): void
    {
        self::apuntar($entidad, $tabla, $id, null, self::leer($tabla, $id), $year, $resumen);
    }

    /** Se llama ANTES de borrar de verdad (un `DELETE`): después no hay fila que leer. */
    public static function borrada(string $entidad, string $tabla, int $id, ?int $year = null, ?string $resumen = null): void
    {
        self::apuntar($entidad, $tabla, $id, self::leer($tabla, $id), null, $year, $resumen);
    }

    /** @return array<string, mixed>|null */
    private static function leer(string $tabla, int $id): ?array
    {
        $fila = DB::selectOne("SELECT * FROM `{$tabla}` WHERE id = ?", [$id]);

        return $fila ? (array) $fila : null;
    }

    private static function apuntar(string $entidad, string $tabla, int $id, ?array $antes, ?array $despues, ?int $year, ?string $resumen): void
    {
        if ($antes === null && $despues === null) {
            return;
        }

        $sinSellos = fn (?array $f) => $f === null ? [] : array_diff_key($f, array_flip([...self::SELLOS, 'id']));
        $a = $sinSellos($antes);
        $d = $sinSellos($despues);

        $seBorro = $antes !== null && ($despues === null || (empty($antes['deleted_at']) && ! empty($despues['deleted_at'])));
        $seCreo = $antes === null;

        $linea = Auditoria::registrar();
        if ($seCreo) {
            $linea->crear($entidad, $id)->a(array_diff_key($d, ['deleted_at' => 1]));
        } elseif ($seBorro) {
            $linea->borrar($entidad, $id)->de(array_diff_key($a, ['deleted_at' => 1]));
        } else {
            $cambiadas = array_keys(array_filter($d, fn ($v, $k) => (string) ($a[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
            if (! $cambiadas) {
                return;
            }
            $linea->editar($entidad, $id)
                ->de(array_intersect_key($a, array_flip($cambiadas)))
                ->a(array_intersect_key($d, array_flip($cambiadas)));
            $resumen ??= 'Cambió '.implode(', ', $cambiadas);
        }

        $linea->en(year: $year ?? (isset($d['year_id']) ? (int) $d['year_id'] : (isset($a['year_id']) ? (int) $a['year_id'] : null)));
        if ($resumen !== null) {
            $linea->resumen($resumen);
        }
        $linea->guardar();
    }
}
