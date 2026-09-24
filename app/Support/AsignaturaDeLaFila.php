<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * De qué asignatura es la fila que esta petición va a escribir.
 *
 * La pareja de `PeriodoDeLaFila`, por la misma razón y con la misma regla: el
 * cierre por asignatura (`CierreDeAsignatura`) se comprueba **contra la fila que
 * se escribe**, no contra lo que nombra el cuerpo. Mismos nombres de método para
 * que cada llamada se lea como un par:
 *
 *     User::pueden_editar_notas($user, PeriodoDeLaFila::deNota($id), AsignaturaDeLaFila::deNota($id));
 *
 * `null` es «no se pudo derivar», y ahí el cierre por asignatura no muerde: se
 * queda sólo el candado del periodo, que es exactamente lo de antes.
 */
class AsignaturaDeLaFila
{
    public static function deUnidad($unidadId): ?int
    {
        return self::uno('SELECT asignatura_id FROM unidades WHERE id = ?', $unidadId);
    }

    public static function deSubunidad($subunidadId): ?int
    {
        return self::uno(
            'SELECT u.asignatura_id FROM subunidades s
             INNER JOIN unidades u ON u.id = s.unidad_id
             WHERE s.id = ?',
            $subunidadId
        );
    }

    public static function deNota($notaId): ?int
    {
        return self::uno(
            'SELECT u.asignatura_id FROM notas n
             INNER JOIN subunidades s ON s.id = n.subunidad_id
             INNER JOIN unidades u ON u.id = s.unidad_id
             WHERE n.id = ?',
            $notaId
        );
    }

    public static function deAusencia($ausenciaId): ?int
    {
        return self::uno('SELECT asignatura_id FROM ausencias WHERE id = ?', $ausenciaId);
    }

    public static function deFraseAsignatura($fraseId): ?int
    {
        return self::uno('SELECT asignatura_id FROM frases_asignatura WHERE id = ?', $fraseId);
    }

    /** @return array<int> */
    public static function deVariasSubunidades(array $subunidadIds): array
    {
        return self::varias(array_map(fn ($id) => self::deSubunidad($id), $subunidadIds));
    }

    /** @return array<int> */
    public static function deVariasUnidades(array $unidadIds): array
    {
        return self::varias(array_map(fn ($id) => self::deUnidad($id), $unidadIds));
    }

    private static function varias(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn ($a) => $a !== null)));
    }

    private static function uno(string $consulta, ...$parametros): ?int
    {
        if (in_array(null, $parametros, true) || in_array('', $parametros, true)) {
            return null;
        }

        $fila = DB::selectOne($consulta, $parametros);

        if ($fila === null) {
            return null;
        }

        $valor = array_values((array) $fila)[0];

        return $valor === null ? null : (int) $valor;
    }
}
