<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * LO QUE UN DOCENTE VE DE LA AUDITORÍA DE NOTAS sin el permiso de auditoría: las líneas
 * de notas, definitivas, nivelaciones y ausencias
 *
 * 1. de **sus asignaturas** —`asignaturas.profesor_id` = él, de cualquier año—, hiciera
 *    quien hiciera el cambio, y
 * 2. de **los alumnos de los grupos de los que es titular**, en cualquier asignatura,
 *    pero sólo del año de ese grupo: el director de 2024 no ve el 2026 de su alumno.
 *
 * Es la decisión de Joseth del 30 sep 2026 (contrato 2 de la pantalla `/auditoria`) y
 * ensancha la del 29 sep que ya vivía en `Autoriza::daLaAsignatura()` para la planilla.
 * Las dos reglas son las del repo y no otras: «da la asignatura» es la de
 * `daLaAsignatura` (`profesor_id`, sin borrar) y «es titular» la de
 * `CompromisosController::esTitularDelGrupo` (`grupos.titular_id`, sin borrar), las dos
 * detrás de `tipo === 'Profesor'`, porque el `persona_id` de otro tipo de cuenta es de
 * otra tabla y casaría con la ficha de un docente cualquiera.
 *
 * La asignatura de la línea se deriva como en el filtro de asignatura del listado
 * (`ListadoDeAuditoria::porAsignatura`): las líneas de nota no la traen. El año de la
 * línea, como el `anio` que pinta la pantalla: el suyo, si no el de su periodo, si no el
 * de su grupo. Una línea sin ninguno de los tres no entra por la titularidad.
 *
 * Lo usan el listado de notas, su desplegable de actores y el cajón de una entidad.
 */
final class AlcanceDelDocente
{
    /** Las entidades que puede ver un docente: las de la familia `notas`. */
    public static function entidades(): array
    {
        return ListadoDeAuditoria::entidadesDe('notas');
    }

    /** El `profesores.id` del usuario si es docente; `null` si no lo es. */
    public static function profesorDe($user): ?int
    {
        if (($user->tipo ?? null) !== 'Profesor' || ! ($user->persona_id ?? null)) {
            return null;
        }

        return (int) $user->persona_id;
    }

    /**
     * La condición sobre `auditoria a` que deja sólo lo de su alcance.
     *
     * @param  list<string>  $entidades  las entidades de la consulta, para no derivar la asignatura de las que no están
     * @return array{0: string, 1: list<mixed>}
     */
    public static function condicion(int $profesor, ?array $entidades = null): array
    {
        [$deSusAsignaturas, $parametros] = ListadoDeAuditoria::porAsignatura(
            $entidades ?? self::entidades(),
            'IN (SELECT mia.id FROM asignaturas mia WHERE mia.profesor_id = ? AND mia.deleted_at IS NULL)',
            [$profesor]
        );

        // Por pares (alumno, año) y no con un `EXISTS` correlacionado: medido en la copia de
        // caz el 30 sep 2026 (profesor 3, 17.234 líneas de notas), el `EXISTS` al lado del
        // `OR` de las asignaturas dejaba el recuento en 167 ms; el `IN` de pares se
        // materializa una vez y lo deja en 38 (sin alcance, 9). La forma
        // `(x, y) IN (SELECT …)` la tienen MySQL y MariaDB.
        $deSuGrupo = '(a.alumno_id, COALESCE(a.year_id,
                            (SELECT tp.year_id FROM periodos tp WHERE tp.id = a.periodo_id),
                            (SELECT tl.year_id FROM grupos tl WHERE tl.id = a.grupo_id)))
                       IN (SELECT tm.alumno_id, tg.year_id FROM matriculas tm
                             JOIN grupos tg ON tg.id = tm.grupo_id AND tg.deleted_at IS NULL
                            WHERE tm.deleted_at IS NULL AND tg.titular_id = ?)';
        $parametros[] = $profesor;

        return ["($deSusAsignaturas OR $deSuGrupo)", $parametros];
    }

    /**
     * Si alguna línea de esa entidad cae en su alcance: el permiso del cajón. Una entidad
     * sin líneas no está en el alcance de nadie, y es `403` y no una lista vacía.
     */
    public static function incluyeEntidad(int $profesor, string $entidad, int $id): bool
    {
        if (! in_array($entidad, self::entidades(), true)) {
            return false;
        }

        [$donde, $parametros] = self::condicion($profesor, [$entidad]);

        return DB::selectOne(
            "SELECT 1 AS si FROM auditoria a WHERE a.entidad = ? AND a.entidad_id = ? AND $donde LIMIT 1",
            [$entidad, $id, ...$parametros]
        ) !== null;
    }
}
