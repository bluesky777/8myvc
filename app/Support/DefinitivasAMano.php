<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * **Nivelar es sólo nivelar** — fase 3 del cierre de periodo
 * (`myvc_front/PLAN-CIERRE-DE-PERIODO.md`, decisión 3 de Joseth del 23 sep 2026).
 *
 * Hasta hoy `periodos.profes_pueden_nivelar` contestaba dos preguntas: *¿puede nivelar?*
 * (`User::puedeNivelar`) y *¿puede cambiar la definitiva a mano?*
 * (`User::pueden_modificar_definitivas`). Un colegio que abría la semana de nivelaciones
 * abría también la edición suelta de definitivas, y no había forma de separarlas.
 *
 * Esto añade la segunda condición **sin tocar la primera**: la ventana sigue siendo la
 * de nivelar de cada periodo, y `years.profes_pueden_cambiar_definitivas` dice si dentro
 * de esa ventana cabe también la edición a mano. De fábrica vale 1, que es lo de siempre.
 *
 * ## Por qué aquí y no una línea dentro de `pueden_modificar_definitivas`
 *
 * El plan decía «una línea en `pueden_modificar_definitivas`», y leyendo sus siete
 * llamantes **no puede ser ahí**: dos de ellos —`update-recuperacion` y
 * `eliminar-recuperada`— son la recuperación de la asignatura del año, la sección B del
 * acta, o sea **nivelar** en el tercero de sus tres niveles (§2 del plan). Meter la
 * política dentro del guard compartido habría apagado la nivelación anual en el colegio
 * que sólo quería apagar la edición suelta. Se llama en los cuatro que SÍ son editar a
 * mano: `update`, `toggle-manual`, `toggle-recuperada` y `destroy`.
 *
 * ## A quién alcanza
 *
 * Al `tipo == 'Profesor'`, sea o no superusuario — el mismo trato que la bandera de
 * nivelar le da al profesor-superusuario: la regla es del colegio, no del despacho. Un
 * superusuario que no es profesor (coordinación, secretaría) sigue pudiendo: es quien
 * corrige cuando el docente no puede.
 *
 * ## 403 y no 400
 *
 * El 400 de `pueden_modificar_definitivas` no se toca —lo leen cinco métodos de Flutter
 * como «periodo cerrado»—. Esto es otra razón y dice cuál: con el 400 de siempre, el
 * docente leería «no tienes permiso para nivelar en este periodo» con la nivelación
 * abierta, y buscaría el fallo en el periodo.
 */
class DefinitivasAMano
{
    public const MENSAJE = 'El colegio no deja cambiar las definitivas a mano: en este año se '
        .'cambian nivelando. Si una definitiva está mal, la corrige coordinación.';

    /**
     * @param  int|array<int>|null  $periodo  el de la fila, como en los guards de `User`
     */
    public static function exigir($user, int|array|null $periodo): void
    {
        if (($user->tipo ?? null) !== 'Profesor') {
            return;
        }

        if (! self::permitidas(self::aniosDe($user, $periodo))) {
            abort(403, self::MENSAJE);
        }
    }

    /**
     * Con varios años, basta uno que no deje: la misma regla de «todos o ninguno» que
     * los guards de periodo.
     *
     * **Sin la columna, deja**: el código se despliega colegio por colegio y un colegio
     * con el código nuevo y la migración sin correr tiene que seguir como estaba. Por eso
     * `SELECT *` y `?? 1`, y no el nombre de la columna en la consulta.
     *
     * @param  list<int>  $anios
     */
    public static function permitidas(array $anios): bool
    {
        if ($anios === []) {
            return true;
        }

        $filas = DB::select(
            'SELECT * FROM years WHERE id IN ('.implode(',', array_fill(0, count($anios), '?')).')',
            $anios
        );

        foreach ($filas as $fila) {
            if ((int) ($fila->profes_pueden_cambiar_definitivas ?? 1) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * El año de cada periodo de la fila; sin periodo, el de la sesión.
     *
     * @param  int|array<int>|null  $periodo
     * @return list<int>
     */
    private static function aniosDe($user, int|array|null $periodo): array
    {
        $ids = array_values(array_unique(array_filter(
            is_array($periodo) ? $periodo : [$periodo], fn ($p) => $p !== null
        )));

        if ($ids === []) {
            return isset($user->year_id) ? [(int) $user->year_id] : [];
        }

        return array_map('intval', DB::table('periodos')
            ->whereIn('id', $ids)
            ->distinct()
            ->pluck('year_id')
            ->all());
    }
}
