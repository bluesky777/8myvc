<?php

namespace App\Support;

use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * LA FICHA DE UNA PERSONA, TAL COMO LA PINTAN LAS REJILLAS: la columna «Historial» de
 * Alumnos, Matricular, Cartera, Acudientes, Usuarios y Docentes enseña la última
 * edición de CUALQUIER dato de la fila —no sólo de su tabla principal— y su diálogo
 * enseña todos esos cambios juntos.
 *
 * Qué entra en cada ficha:
 *
 *     alumno     alumnos, su matrícula, su cuenta, sus acudientes y parentescos
 *                (lo que se grabó con `deAlumno()`, entidades de `FICHA_DE_ALUMNO`)
 *     acudiente  acudientes, su cuenta y sus parentescos
 *     profesor   profesores, su cuenta y sus contratos
 *     usuario    la ficha de la persona dueña de la cuenta; si no tiene, la cuenta sola
 *
 * `users.updated_at` NO cuenta en ninguna: lo mueve cambiar de año (`years/useractive`),
 * que no es editar a nadie. Lo de la cuenta llega por la auditoría.
 */
final class FichaEditada
{
    public const TIPOS = ['alumno', 'acudiente', 'profesor', 'usuario'];

    /** El nombre del campo que se añade a cada fila. */
    public const CAMPO = 'ficha_editada_en';

    /**
     * La condición sobre `auditoria a` que saca las líneas de una ficha, para
     * `AuditoriaController::lineas()`.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function condicion(string $tipo, int $id): array
    {
        if ($tipo === 'usuario') {
            [$tipo, $id] = self::duenoDeLaCuenta($id);
        }

        switch ($tipo) {
            case 'alumno':
                $entidades = Auditoria::FICHA_DE_ALUMNO;

                return ['a.alumno_id = ? AND a.entidad IN ('.self::marcas($entidades).')', [$id, ...$entidades]];
            case 'acudiente':
                return ["(a.entidad = 'acudiente' AND a.entidad_id = ?)
                      OR (a.entidad = 'usuario' AND a.entidad_id = (SELECT user_id FROM acudientes WHERE id = ?))
                      OR (a.entidad = 'parentesco' AND a.entidad_id IN (SELECT id FROM parentescos WHERE acudiente_id = ?))", [$id, $id, $id]];
            case 'profesor':
                return ["(a.entidad = 'profesor' AND a.entidad_id = ?)
                      OR (a.entidad = 'usuario' AND a.entidad_id = (SELECT user_id FROM profesores WHERE id = ?))
                      OR (a.entidad = 'contrato' AND a.entidad_id IN (SELECT id FROM contratos WHERE profesor_id = ?))", [$id, $id, $id]];
            default: // una cuenta sin persona
                return ["a.entidad = 'usuario' AND a.entidad_id = ?", [$id]];
        }
    }

    /**
     * Pone `ficha_editada_en` en cada fila: la mayor entre el `updated_at` de la tabla
     * principal, el de la matrícula de la fila si la trae (`matricula_id`), y la última
     * línea de auditoría de la ficha. Pocas consultas por lista, no una por fila.
     *
     * @param  array<int, object>  $filas
     */
    public static function poner(string $tipo, array $filas, string $campoId): void
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($f) => (int) ($f->$campoId ?? 0), $filas))));
        if (count($ids) === 0) {
            foreach ($filas as $fila) {
                $fila->{self::CAMPO} = null;
            }

            return;
        }

        $fechas = self::fechas($tipo, $ids);

        $matriculas = array_values(array_unique(array_filter(array_map(fn ($f) => (int) ($f->matricula_id ?? 0), $filas))));
        $deMatricula = $tipo === 'alumno' && count($matriculas)
            ? collect(DB::select('SELECT id, updated_at FROM matriculas WHERE id IN ('.self::marcas($matriculas).')', $matriculas))->pluck('updated_at', 'id')
            : collect();

        foreach ($filas as $fila) {
            $id = (int) ($fila->$campoId ?? 0);
            $fila->{self::CAMPO} = self::mayor([
                $fechas[$id] ?? null,
                isset($fila->matricula_id) ? ($deMatricula[(int) $fila->matricula_id] ?? null) : null,
            ]);
        }
    }

    /** @return array<int, string|null> id => fecha */
    private static function fechas(string $tipo, array $ids): array
    {
        $m = self::marcas($ids);

        switch ($tipo) {
            case 'alumno':
                $entidades = Auditoria::FICHA_DE_ALUMNO;
                $tabla = DB::select("SELECT id, updated_at FROM alumnos WHERE id IN ($m)", $ids);
                $auditoria = DB::select(
                    "SELECT alumno_id AS id, MAX(ocurrido_en) AS f FROM auditoria
                      WHERE alumno_id IN ($m) AND entidad IN (".self::marcas($entidades).')
                      GROUP BY alumno_id',
                    [...$ids, ...$entidades]
                );
                break;

            case 'acudiente':
                $tabla = DB::select("SELECT id, updated_at FROM acudientes WHERE id IN ($m)", $ids);
                $auditoria = DB::select(
                    "SELECT x.id, MAX(x.f) AS f FROM (
                        SELECT ac.id, au.ocurrido_en AS f FROM acudientes ac
                          JOIN auditoria au ON au.entidad = 'acudiente' AND au.entidad_id = ac.id WHERE ac.id IN ($m)
                        UNION ALL
                        SELECT ac.id, au.ocurrido_en FROM acudientes ac
                          JOIN auditoria au ON au.entidad = 'usuario' AND au.entidad_id = ac.user_id WHERE ac.id IN ($m)
                        UNION ALL
                        SELECT p.acudiente_id, au.ocurrido_en FROM parentescos p
                          JOIN auditoria au ON au.entidad = 'parentesco' AND au.entidad_id = p.id WHERE p.acudiente_id IN ($m)
                     ) x GROUP BY x.id",
                    [...$ids, ...$ids, ...$ids]
                );
                break;

            case 'profesor':
                $tabla = DB::select("SELECT id, updated_at FROM profesores WHERE id IN ($m)", $ids);
                $auditoria = DB::select(
                    "SELECT x.id, MAX(x.f) AS f FROM (
                        SELECT p.id, au.ocurrido_en AS f FROM profesores p
                          JOIN auditoria au ON au.entidad = 'profesor' AND au.entidad_id = p.id WHERE p.id IN ($m)
                        UNION ALL
                        SELECT p.id, au.ocurrido_en FROM profesores p
                          JOIN auditoria au ON au.entidad = 'usuario' AND au.entidad_id = p.user_id WHERE p.id IN ($m)
                        UNION ALL
                        SELECT c.profesor_id, au.ocurrido_en FROM contratos c
                          JOIN auditoria au ON au.entidad = 'contrato' AND au.entidad_id = c.id WHERE c.profesor_id IN ($m)
                     ) x GROUP BY x.id",
                    [...$ids, ...$ids, ...$ids]
                );
                break;

            case 'usuario':
                return self::fechasDeCuentas($ids);

            default:
                return [];
        }

        $fechas = [];
        foreach ($tabla as $fila) {
            $fechas[(int) $fila->id] = $fila->updated_at;
        }
        foreach ($auditoria as $fila) {
            $fechas[(int) $fila->id] = self::mayor([$fechas[(int) $fila->id] ?? null, $fila->f]);
        }

        return $fechas;
    }

    /**
     * Por cuenta: la ficha de su dueño. Una cuenta con dueño en dos tablas (no debería,
     * pero la base no lo impide) se queda con la fecha mayor de las dos.
     *
     * @return array<int, string|null> user_id => fecha
     */
    private static function fechasDeCuentas(array $userIds): array
    {
        $m = self::marcas($userIds);
        $fechas = [];

        foreach (['alumno' => 'alumnos', 'acudiente' => 'acudientes', 'profesor' => 'profesores'] as $tipo => $tabla) {
            $duenos = collect(DB::select("SELECT id, user_id FROM $tabla WHERE user_id IN ($m) AND deleted_at IS NULL", $userIds))
                ->pluck('user_id', 'id');
            if ($duenos->isEmpty()) {
                continue;
            }
            foreach (self::fechas($tipo, $duenos->keys()->all()) as $personaId => $fecha) {
                $userId = (int) $duenos[$personaId];
                $fechas[$userId] = self::mayor([$fechas[$userId] ?? null, $fecha]);
            }
        }

        // Y la cuenta misma, que para las que no tienen dueño es lo único que hay.
        $cuentas = DB::select(
            "SELECT entidad_id AS id, MAX(ocurrido_en) AS f FROM auditoria
              WHERE entidad = 'usuario' AND entidad_id IN ($m) GROUP BY entidad_id",
            $userIds
        );
        foreach ($cuentas as $fila) {
            $fechas[(int) $fila->id] = self::mayor([$fechas[(int) $fila->id] ?? null, $fila->f]);
        }

        return $fechas;
    }

    /** @return array{0: string, 1: int} */
    private static function duenoDeLaCuenta(int $userId): array
    {
        foreach (['alumno' => 'alumnos', 'acudiente' => 'acudientes', 'profesor' => 'profesores'] as $tipo => $tabla) {
            $dueno = DB::selectOne("SELECT id FROM $tabla WHERE user_id = ? AND deleted_at IS NULL", [$userId]);
            if ($dueno) {
                return [$tipo, (int) $dueno->id];
            }
        }

        return ['usuario', $userId];
    }

    /** La mayor de varias fechas `Y-m-d H:i:s`; `ocurrido_en` trae milésimas y se cortan. */
    private static function mayor(array $fechas): ?string
    {
        $fechas = array_map(fn ($f) => substr((string) $f, 0, 19), array_filter($fechas));

        return count($fechas) ? max($fechas) : null;
    }

    private static function marcas(array $lista): string
    {
        return implode(',', array_fill(0, count($lista), '?'));
    }
}
