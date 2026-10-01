<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * **Lo que antes se leía de `bitacoras`, leído de `auditoria` con la misma forma**
 * (contrato 5, opción A del 30 sep 2026: se deja de escribir en `bitacoras`).
 *
 * Lo usan `historiales/nota-detalle`, `historiales/nota-final-detalle`,
 * `historiales/de-usuario` y `changes-asked/to-me`. Los dos primeros los llama
 * **Flutter**, una sola app para los dieciséis colegios y sin versión mínima: la
 * forma JSON —claves, tipos y orden— **no puede cambiar**, así que cada fila de
 * `auditoria` se viste aquí con las columnas exactas de la fila vieja.
 *
 * ## Cómo se juntan las dos tablas
 *
 * Las dos se han escrito a la vez desde que cada dominio pasó por la fase 4 de
 * [18-auditoria.md](../../docs/migracion/18-auditoria.md), así que en ese tramo
 * cada cambio está **dos veces**. La regla: **la vieja hasta el corte y la nueva
 * después**, con el corte = la primera línea de esa entidad en `auditoria`
 * (`corte()`), que es cuando la entidad empezó a escribirse en las dos.
 *
 * - **No se comparan relojes de dos tablas fila a fila más de lo imprescindible.**
 *   `bitacoras.created_at` es `TIMESTAMP` (convierte con la zona de la sesión de
 *   cada hosting) y lleva la hora de **inicio** de la petición; `auditoria` es
 *   `DATETIME(3)` con la hora de cada línea. En el docker la misma escritura se lee
 *   con cuatro horas de diferencia desde la consola. Por eso el corte es uno por
 *   entidad, y el emparejamiento de gemelas (`sinGemelas`) sólo mira una ventana
 *   alrededor de cada línea nueva.
 * - **El orden lo da `bit_id`, no la fecha**: Flutter y `app/` ordenan por él. Las
 *   líneas nuevas llevan `DESPLAZAMIENTO + auditoria.id`, que queda siempre por
 *   encima de cualquier `bitacoras.id` y no choca con ninguno. Es estable: no
 *   depende de cuántas filas tenga `bitacoras` hoy.
 */
final class HistorialDeLasDosTablas
{
    /**
     * Por encima de cualquier `bitacoras.id` (`int unsigned`, en producción del orden
     * del millón). Un `bit_id` desde aquí es una línea de `auditoria`.
     */
    public const DESPLAZAMIENTO = 1000000000;

    /** Las acciones que cambian el valor: las que la bitácora vieja apuntaba como cambio. */
    public const ACCIONES_DE_VALOR = ['crear', 'editar', 'nivelar', 'quitar_nivelacion'];

    /**
     * Las líneas `editar` de `nota_final` que no son un cambio de nota sino de una
     * marca (`DefinitivasPeriodosController::auditarFila`): llevan el 1/0 de la
     * marca como valor, y pintarlas como «la definitiva pasó a 1» sería falso.
     */
    private const MARCAS_DE_LA_DEFINITIVA = ['Marcó la definitiva como %', 'Quitó la marca de %'];

    /** Margen de la ventana de gemelas: una petición larga (un lote) escribe la vieja al empezar. */
    private const VENTANA_ANTES = 3600;

    private const VENTANA_DESPUES = 5;

    /**
     * Las filas `cambios` de `nota-detalle` / `nota-final-detalle`.
     *
     * @param  string  $entidad  `nota` | `nota_final` en `auditoria`
     * @param  string  $tipoViejo  `Nota` | `NF_UPDATE` en `bitacoras.affected_element_type`
     * @return array<int, object>
     */
    public static function cambios(string $entidad, string $tipoViejo, int $id): array
    {
        // La consulta vieja, tal cual: la rama de `profesores` da nombre y apellidos y
        // la otra el `username`. Las dos con las mismas columnas en el mismo orden.
        $viejas = DB::select(
            '(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, concat(p.nombres, " ", p.apellidos) as creado_por
				FROM bitacoras b
				inner join users u on u.id=b.created_by
				inner join profesores p on p.user_id=u.id
				where b.affected_element_type=? and b.affected_element_id=?)
			UNION
			(SELECT b.id as bit_id, b.created_by as created_by_user_id, b.historial_id, b.created_at, b.affected_element_new_value_int as new_value, b.affected_element_old_value_int as old_value, u.username as creado_por
				FROM bitacoras b
				inner join users u on u.id=b.created_by AND u.tipo<>"Profesor"
				where b.affected_element_type=? and b.affected_element_id=?)',
            [$tipoViejo, $id, $tipoViejo, $id]
        );

        $acciones = implode(',', array_fill(0, count(self::ACCIONES_DE_VALOR), '?'));
        $sinMarcas = $entidad === 'nota_final'
            ? ' AND (a.resumen IS NULL OR ('.implode(' AND ', array_fill(0, count(self::MARCAS_DE_LA_DEFINITIVA), 'a.resumen NOT LIKE ?')).'))'
            : '';
        $filtro = 'a.entidad = ? AND a.entidad_id = ? AND a.accion IN ('.$acciones.')'.$sinMarcas;
        $parametros = [$entidad, $id, ...self::ACCIONES_DE_VALOR, ...($sinMarcas === '' ? [] : self::MARCAS_DE_LA_DEFINITIVA)];

        // Las mismas dos ramas sobre `auditoria`, y por lo mismo: quien no tenga
        // usuario (el sistema) o sea un profesor sin fila en `profesores` no sale,
        // igual que no salía en la vieja. `CAST(... AS CHAR)` porque en MariaDB la
        // columna JSON es LONGTEXT y en MySQL es JSON: así llega igual de las dos.
        $nuevas = DB::select(
            '(SELECT a.id, a.actor_user_id, a.historial_id, a.ocurrido_en,
					a.valor_nuevo_num, a.valor_anterior_num,
					CAST(a.valor_nuevo AS CHAR) AS valor_nuevo, CAST(a.valor_anterior AS CHAR) AS valor_anterior,
					concat(p.nombres, " ", p.apellidos) as creado_por
				FROM auditoria a
				inner join users u on u.id=a.actor_user_id
				inner join profesores p on p.user_id=u.id
				where '.$filtro.')
			UNION
			(SELECT a.id, a.actor_user_id, a.historial_id, a.ocurrido_en,
					a.valor_nuevo_num, a.valor_anterior_num,
					CAST(a.valor_nuevo AS CHAR) AS valor_nuevo, CAST(a.valor_anterior AS CHAR) AS valor_anterior,
					u.username as creado_por
				FROM auditoria a
				inner join users u on u.id=a.actor_user_id AND u.tipo<>"Profesor"
				where '.$filtro.')
			ORDER BY id',
            [...$parametros, ...$parametros]
        );

        $nuevas = array_map(fn (object $a) => (object) [
            'bit_id' => self::DESPLAZAMIENTO + (int) $a->id,
            'created_by_user_id' => (int) $a->actor_user_id,
            'historial_id' => $a->historial_id === null ? null : (int) $a->historial_id,
            'created_at' => substr((string) $a->ocurrido_en, 0, 19),
            'new_value' => self::entero($a->valor_nuevo_num, $a->valor_nuevo),
            'old_value' => self::entero($a->valor_anterior_num, $a->valor_anterior),
            'creado_por' => $a->creado_por,
        ], $nuevas);

        return [...self::sinGemelas($viejas, $nuevas, self::corte($entidad)), ...$nuevas];
    }

    /**
     * `cant_cambios` de cada ingreso: lo de `auditoria` si ese ingreso tiene algo
     * allí, y si no lo de `bitacoras`, que es lo que traen las filas (la consulta
     * vieja sigue contando la vieja).
     *
     * Por ingreso y no sumando: un ingreso del tramo en que se escribían las dos
     * contaría cada cambio dos veces. `auditoria.historial_id` sale del token y es
     * real; el de `bitacoras` antes de la fase 2 era el último ingreso adivinado, y
     * esos ingresos viejos se quedan con su cifra de siempre.
     *
     * Se cuenta por `actor_user_id` —`historial_id` y actor salen del mismo usuario
     * en `Auditoria::resolverActor`— porque `historial_id` no tiene índice y
     * `aud_actor` sí: una consulta por pantalla en vez de un barrido por ingreso.
     *
     * @param  array<int, object>  $historial  filas con `id` y `cant_cambios`
     * @return array<int, object>
     */
    public static function conCambiosDeAuditoria(array $historial, int $userId): array
    {
        $ids = array_map(fn (object $h) => (int) $h->id, $historial);

        if ($ids === []) {
            return $historial;
        }

        $filas = DB::select(
            'SELECT historial_id, COUNT(*) AS n FROM auditoria
              WHERE actor_user_id = ? AND historial_id IN ('.implode(',', array_fill(0, count($ids), '?')).')
              GROUP BY historial_id',
            [$userId, ...$ids]
        );

        $nuevas = [];
        foreach ($filas as $f) {
            $nuevas[(int) $f->historial_id] = (int) $f->n;
        }

        foreach ($historial as $h) {
            if (($nuevas[(int) $h->id] ?? 0) > 0) {
                $h->cant_cambios = $nuevas[(int) $h->id];
            }
        }

        return $historial;
    }

    /**
     * `historial.bitacoras` de `historiales/sesion`: los cambios de nota de un ingreso,
     * con las columnas de `b.*` en el orden de la tabla y detrás `nombres`,
     * `apellidos` y `definicion`. Lo lee el modal «Detalle de la sesión» de `app/`
     * (`modalDetallesSesion.html`, ordena por `id`); Flutter no llama esta ruta.
     *
     * **Con la misma regla que `cant_cambios`**: si el ingreso tiene algo en
     * `auditoria`, salen sus líneas de nota de allí; si no, la consulta vieja. Así la
     * cifra del listado y el detalle hablan de la misma tabla, y un ingreso del tramo
     * en que se escribían las dos no enseña cada cambio dos veces.
     *
     * @return array<int, object>
     */
    public static function cambiosDelIngreso(int $historialId, int $userId): array
    {
        // Por `actor_user_id` además del ingreso: `historial_id` no tiene índice y
        // `aud_actor` sí (ver `conCambiosDeAuditoria`).
        $tieneNuevas = DB::selectOne(
            'SELECT 1 AS si FROM auditoria WHERE actor_user_id = ? AND historial_id = ? LIMIT 1',
            [$userId, $historialId]
        ) !== null;

        if (! $tieneNuevas) {
            return DB::select(
                'SELECT b.*, a.nombres, a.apellidos, s.definicion FROM bitacoras b
					inner join alumnos a ON b.affected_user_id=a.id and a.deleted_at is null
					inner join notas n ON n.id=b.affected_element_id
					inner join subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
					WHERE b.historial_id=? and b.deleted_at is null',
                [$historialId]
            );
        }

        $acciones = implode(',', array_fill(0, count(self::ACCIONES_DE_VALOR), '?'));

        // Lo que la fila vieja de una nota llevaba y la nueva no sabe va como lo
        // escribían `putUpdate` y `putLote`: `affected_person_type = "Al"` y el resto NULL.
        return DB::select(
            'SELECT au.id + '.self::DESPLAZAMIENTO.' AS id, au.actor_user_id AS created_by, au.historial_id,
					NULL AS descripcion, n.alumno_id AS affected_user_id, NULL AS affected_person_id,
					NULL AS affected_person_name, "Al" AS affected_person_type,
					"Nota" AS affected_element_type, au.entidad_id AS affected_element_id,
					NULL AS affected_element_new_value_string, NULL AS affected_element_old_value_string,
					au.valor_nuevo_num AS affected_element_new_value_int, au.valor_anterior_num AS affected_element_old_value_int,
					NULL AS periodo_id, NULL AS deleted_by, NULL AS deleted_at,
					DATE_FORMAT(au.ocurrido_en, "%Y-%m-%d %H:%i:%s") AS created_at, NULL AS updated_at,
					a.nombres, a.apellidos, s.definicion
			   FROM auditoria au
			   inner join notas n ON n.id=au.entidad_id
			   inner join alumnos a ON a.id=n.alumno_id and a.deleted_at is null
			   inner join subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
			  WHERE au.actor_user_id = ? AND au.historial_id = ?
			    AND au.entidad = "nota" AND au.accion IN ('.$acciones.')
			  ORDER BY au.id',
            [$userId, $historialId, ...self::ACCIONES_DE_VALOR]
        );
    }

    /**
     * Los 50 últimos intentos de entrar con ese nombre de cuenta, con las columnas
     * de `SELECT * FROM bitacoras` en el orden de la tabla.
     *
     * La línea nueva es `denegado('intento_login')` con `sinActor($username)`
     * (`Services\Login::anotarIntentoFallido`): el nombre tecleado en
     * `actor_intentado` y la descripción en `resumen`. Lo que la vieja tenía y la
     * nueva no sabe va como lo escribía el login: `created_by = 0` y el resto NULL.
     *
     * @return array<int, object>
     */
    public static function intentosFallidos(string|int $nombre): array
    {
        $nombre = (string) $nombre;
        $corte = self::corte('intento_login');

        $nuevas = DB::select(
            'SELECT a.id + '.self::DESPLAZAMIENTO.' AS id, 0 AS created_by, NULL AS historial_id,
					a.resumen AS descripcion, NULL AS affected_user_id, NULL AS affected_person_id,
					a.actor_intentado AS affected_person_name, NULL AS affected_person_type,
					"intento_login" AS affected_element_type, NULL AS affected_element_id,
					NULL AS affected_element_new_value_string, NULL AS affected_element_old_value_string,
					NULL AS affected_element_new_value_int, NULL AS affected_element_old_value_int,
					NULL AS periodo_id, NULL AS deleted_by, NULL AS deleted_at,
					DATE_FORMAT(a.ocurrido_en, "%Y-%m-%d %H:%i:%s") AS created_at, NULL AS updated_at
			   FROM auditoria a
			  WHERE a.entidad = "intento_login" AND a.accion = "denegado" AND a.actor_intentado = ?
			  ORDER BY a.id DESC LIMIT 50',
            [$nombre]
        );

        // La vieja, hasta el corte. Sin corte (la entidad aún no tiene ninguna línea
        // nueva) es la consulta de siempre.
        $viejas = DB::select(
            'SELECT * FROM bitacoras
			  WHERE affected_element_type="intento_login" and affected_person_name=? and deleted_at is null'
                .($corte === null ? '' : ' and created_at < ?').'
			  order by created_at desc limit 50',
            $corte === null ? [$nombre] : [$nombre, $corte]
        );

        // Las gemelas del segundo del corte: el mismo intento en las dos, con la vieja
        // truncada al segundo justo por debajo de la primera nueva.
        $viejas = array_values(array_filter($viejas, function (object $b) use ($nuevas) {
            foreach ($nuevas as $a) {
                $d = strtotime((string) $a->created_at) - strtotime((string) $b->created_at);
                if ($d >= -self::VENTANA_DESPUES && $d <= self::VENTANA_DESPUES && $a->descripcion === $b->descripcion) {
                    return false;
                }
            }

            return true;
        }));

        return array_slice([...$nuevas, ...$viejas], 0, 50);
    }

    /**
     * Cuándo empezó `$entidad` a escribirse en `auditoria`: su primera línea. Sale
     * del índice `aud_entidad_fecha`. Null si todavía no tiene ninguna.
     *
     * Si una limpieza borró `auditoria` hasta una fecha y dejó `bitacoras`, el corte
     * se adelanta solo y la vieja cubre el hueco: es justo lo que hay que enseñar.
     */
    public static function corte(string $entidad): ?string
    {
        $fila = DB::selectOne('SELECT MIN(ocurrido_en) AS c FROM auditoria WHERE entidad = ?', [$entidad]);

        return $fila?->c === null ? null : (string) $fila->c;
    }

    /**
     * Las viejas que no están ya en las nuevas.
     *
     * - Una vieja con gemela nueva sobra: es el mismo cambio escrito en las dos. Se
     *   empareja una a una, primero por valores y luego —una nivelación que corrige la
     *   valoración inicial apunta en `bitacoras` la nota vigente y en `auditoria` la
     *   original— por quién y al mismo tiempo.
     * - Desde el corte, una vieja sin cambio de valor (un reguardado de la planilla)
     *   sobra también: la nueva no los apunta y enseñarlos sólo ahí sería mezclar dos
     *   criterios en la misma lista.
     * - Una vieja de después del corte **con** cambio y sin gemela se queda: es un
     *   camino que escribía la vieja y aún no la nueva, y no se puede perder.
     *
     * @param  array<int, object>  $viejas
     * @param  array<int, object>  $nuevas
     * @return array<int, object>
     */
    private static function sinGemelas(array $viejas, array $nuevas, ?string $corte): array
    {
        if ($corte === null) {
            return $viejas;
        }

        $usadas = [];
        $emparejar = function (callable $casa, int $antes) use ($viejas, $nuevas, &$usadas): void {
            foreach ($nuevas as $a) {
                if (isset($usadas['n'.$a->bit_id])) {
                    continue;
                }
                $cuando = strtotime((string) $a->created_at);
                foreach ($viejas as $i => $b) {
                    $d = $cuando - strtotime((string) $b->created_at);
                    if (! isset($usadas[$i]) && $d >= -self::VENTANA_DESPUES && $d <= $antes && $casa($a, $b)) {
                        $usadas[$i] = true;
                        $usadas['n'.$a->bit_id] = true;
                        break;
                    }
                }
            }
        };

        $emparejar(fn ($a, $b) => self::igual($a->new_value, $b->new_value) && self::igual($a->old_value, $b->old_value), self::VENTANA_ANTES);
        $emparejar(fn ($a, $b) => (int) $a->created_by_user_id === (int) $b->created_by_user_id, 60);

        $quedan = [];
        foreach ($viejas as $i => $b) {
            if (isset($usadas[$i])) {
                continue;
            }
            if ((string) $b->created_at >= substr($corte, 0, 19) && self::igual($b->new_value, $b->old_value)) {
                continue;
            }
            $quedan[] = $b;
        }

        return $quedan;
    }

    private static function igual(mixed $x, mixed $y): bool
    {
        return ($x === null || $y === null) ? $x === $y : (int) $x === (int) $y;
    }

    /**
     * El entero que la vieja guardaba en `..._value_int`: la columna `_num` si lo es,
     * y si no el JSON redondeado —`nota_final` es DECIMAL y `bitacoras` lo apuntaba
     * con `round()` (`DefinitivasPeriodosController`)—.
     */
    private static function entero(mixed $num, mixed $json): ?int
    {
        if ($num !== null) {
            return (int) $num;
        }

        $valor = $json === null ? null : json_decode((string) $json, true);

        return is_numeric($valor) ? (int) round((float) $valor) : null;
    }
}
