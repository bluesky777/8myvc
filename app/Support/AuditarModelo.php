<?php

namespace App\Support;

use App\Services\Auditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * GUARDA UN MODELO ELOQUENT Y DEJA UNA LÍNEA DE AUDITORÍA POR COLUMNA QUE CAMBIÓ. Para
 * las escrituras que van por `->save()` y no por un `UPDATE` a mano —la ficha del
 * profesor, su cuenta, sus contratos—, que hasta el 25 sep 2026 no dejaban rastro y
 * dejaban la columna Historial de Docentes sin nada que enseñar.
 *
 * Las columnas de sello (`updated_at`…) no cuentan, y las secretas se apuntan sin
 * valor: que cambió la contraseña sí, cuál es no.
 *
 * Si la cuenta es de un alumno, la línea se cuelga de él con `deAlumno()`: así la ve
 * también su ficha, que es donde la busca quien mira la rejilla de Alumnos.
 */
final class AuditarModelo
{
    private const SELLOS = ['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'];

    private const SECRETAS = ['password', 'remember_token'];

    public static function guardar(Model $modelo, string $entidad): void
    {
        $nuevo = ! $modelo->exists;
        $sucios = array_diff_key($modelo->getDirty(), array_flip(self::SELLOS));
        $antes = $modelo->getOriginal();

        $modelo->save();

        $alumnoId = $entidad === 'usuario' ? self::alumnoDeLaCuenta((int) $modelo->getKey()) : null;

        if ($nuevo) {
            Auditoria::registrar()->crear($entidad, (int) $modelo->getKey())->deAlumno($alumnoId)->guardar();
            return;
        }

        foreach ($sucios as $columna => $valor) {
            $secreta = in_array($columna, self::SECRETAS, true);
            Auditoria::registrar()
                ->editar($entidad, (int) $modelo->getKey())
                ->deAlumno($alumnoId)
                ->de($secreta ? null : ($antes[$columna] ?? null))
                ->a($secreta ? null : $valor)
                ->resumen('Cambió '.$columna.' en '.$modelo->getTable())
                ->guardar();
        }
    }

    /** Un borrado de una fila, con su id. */
    public static function borrado(string $entidad, int $id): void
    {
        Auditoria::registrar()->borrar($entidad, $id)->guardar();
    }

    private static function alumnoDeLaCuenta(int $userId): ?int
    {
        $alumno = DB::selectOne('SELECT id FROM alumnos WHERE user_id = ? AND deleted_at IS NULL', [$userId]);

        return $alumno ? (int) $alumno->id : null;
    }
}
