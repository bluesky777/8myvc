<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\Subunidad;
use App\Support\AsignaturaDeLaFila;
use App\Support\PeriodoDeLaFila;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DAR DE ALTA UNA SUBUNIDAD (un indicador), con sus notas y la definitiva recalculada.
 *
 * Es el cuerpo de `SubunidadesController::postIndex` sacado a un servicio **sin cambiar nada**, para
 * que el módulo de actividades (`App\Services\Act\Planilla`, contrato §2.8) cree el indicador de una
 * tarea o un cuestionario calificable por el mismo camino: permiso `pueden_editar_notas` del periodo
 * de la unidad, una transacción, la línea de `bitacoras`, la de `Auditoria`, las notas del grupo y el
 * recálculo. El porqué de cada paso sigue escrito en el controlador, donde lo leerá quien lo busque.
 *
 * `$datos` trae lo que el cuerpo de `subunidades/store` traía: `unidad_id`, `definicion`,
 * `porcentaje`, `nota_default` y `orden` (que acaba pisado por el número de hermanas, como siempre).
 * `actividad_id` sólo lo manda el módulo nuevo; si no viene, no se toca la columna y la respuesta de
 * la ruta vieja sale con las mismas claves de siempre.
 */
class SubunidadNueva
{
    public static function crear(object $user, array $datos): Subunidad
    {
        $now = Carbon::now('America/Bogota');
        // La subunidad todavía no existe: nace colgada de `unidad_id`, así que el
        // periodo al que se escribe es el de esa unidad. §27.
        User::pueden_editar_notas($user, PeriodoDeLaFila::deUnidad(($datos['unidad_id'] ?? null)), AsignaturaDeLaFila::deUnidad(($datos['unidad_id'] ?? null)));

        // **Una sola transacción para las tres cosas**: la subunidad, sus notas y la
        // definitiva. Es lo que cierra la §5.1 de verdad — crearlas en el mismo
        // método pero en escrituras sueltas dejaría la misma ventana, sólo que más
        // corta, y una petición que muriera en medio dejaría la subunidad sin notas
        // exactamente igual que hoy.
        //
        // `recalcularPorSubunidad` abre la suya dentro; Laravel la resuelve con un
        // savepoint y no hay que hacer nada.
        return DB::transaction(function () use ($user, $now, $datos) {

            $cant = Subunidad::where('unidad_id', ($datos['unidad_id'] ?? null))->count();

            $subunidad = new Subunidad;

            $nota_def = ($datos['nota_default'] ?? null);

            if (! $nota_def or $nota_def == '' or $nota_def < 0) {
                $nota_def = 0;
            }

            $subunidad->definicion = ($datos['definicion'] ?? null);
            $subunidad->porcentaje = ($datos['porcentaje'] ?? null);
            $subunidad->orden = $datos['orden'] ?? 0;
            $subunidad->unidad_id = ($datos['unidad_id'] ?? null);
            $subunidad->nota_default = $nota_def;
            $subunidad->orden = $cant;
            $subunidad->created_by = $user->user_id;

            // Sólo el módulo de actividades la manda (contrato §2.8): sin ella la fila y la
            // respuesta de `subunidades/store` quedan con las mismas claves de siempre.
            if (array_key_exists('actividad_id', $datos)) {
                $subunidad->actividad_id = $datos['actividad_id'];
            }

            $subunidad->save();

            // El ingreso sale del token (fase 2 de 18-auditoria.md), no del último
            // login de esta persona. Y de paso se va un `[0]` que reventaba con
            // "Undefined array key 0" para quien no tuviera ninguna sesión anotada.
            $bit_by = $user->user_id;
            $bit_hist = isset($user->historial_id) && is_numeric($user->historial_id)
                ? (int) $user->historial_id
                : null;
            $bit_new = $subunidad->definicion.' -- '.$subunidad->porcentaje.'%'; 	// Guardo la nota nueva
            $bit_per = $user->periodo_id;

            $consulta = 'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id, affected_element_new_value_string, created_at) 
					VALUES (?, ?, "Nueva subunidad", ?, ?, ?)';

            DB::insert($consulta, [$bit_by, $bit_hist, $subunidad->id, $bit_new, $now]);

            // El rastro nuevo, al lado del viejo (18 §4). `crear` y no `editar`: es el
            // único de los diez que da de alta una fila, y `bitacoras` lo escribía con
            // `affected_element_type = "Nueva subunidad"` —texto libre, tercera
            // convención de nombre de las tres que conviven en esa columna—.
            //
            // El valor va como estructura y no como la cadena `'X -- 30%'` que arma
            // `$bit_new`: `valor_nuevo` es `json`, y una definición y un porcentaje son
            // dos cosas. Pegadas con ` -- ` no se pueden volver a separar cuando la
            // definición lleva un guión dentro, que es texto escrito a mano.
            Auditoria::registrar()
                ->crear('subunidad', (int) $subunidad->id)
                ->en(periodo: $user->periodo_id)
                ->a(['definicion' => $subunidad->definicion, 'porcentaje' => $subunidad->porcentaje])
                ->guardar();

            // §5.1 de 10-definitivas.md — **la subunidad y sus notas nacen juntas.**
            //
            // Hasta hoy el alta creaba la subunidad y nada más: las notas las creaba
            // `Nota::verificarCrearNotas`, y sólo al abrir /notas en el navegador. Entre
            // las dos cosas queda una ventana en la que **la definitiva se guarda sin el
            // aporte de la subunidad nueva** — y si el profesor bajó los porcentajes de
            // las demás para hacerle sitio, baja el doble. Desde Flutter, que crea
            // subunidades y nunca llama a /notas, **la ventana puede durar días**.
            //
            // El grupo no viene del cuerpo: sale de la unidad. Pedírselo al cliente es
            // la misma dependencia que hacía que el recálculo de unidades no ocurriera
            // cuando el front no mandaba `asignatura_id`.
            $grupo = DB::selectOne(
                'SELECT a.grupo_id
			   FROM unidades u
			   INNER JOIN asignaturas a ON a.id = u.asignatura_id
			  WHERE u.id = ?',
                [$subunidad->unidad_id]
            );

            // **§6.5 del 19: cuando la unidad tiene dueño, aquí nace UNA nota y no
            // treinta** — y no se ve en esta línea a propósito. La decisión vive dentro
            // de `Nota::verificarCrearNotas`, que lee `unidades.alumno_id` de la unidad a
            // la que cuelga esta subunidad. Se puso allí y no aquí porque el otro
            // llamador —`NotasController::putDetailed`— necesita exactamente la misma
            // regla, y dos sitios decidiendo de quién es una unidad es de donde salió el
            // recalculador único.
            if ($grupo !== null && $grupo->grupo_id) {
                Nota::verificarCrearNotas($grupo->grupo_id, $subunidad, $user->user_id);
            }

            DefinitivasDeAsignatura::recalcularPorSubunidad((int) $subunidad->id, $user->user_id);

            return $subunidad;

        });
    }
}
