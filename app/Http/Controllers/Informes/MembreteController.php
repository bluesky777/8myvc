<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Year;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **Lo que hace falta para encabezar y firmar un papel.**
 *
 *     GET informes/membrete   auth.personal
 *
 * El colegio, la resolución, el DANE, la ciudad, el rector con su cédula y su firma,
 * el secretario, los títulos de los certificados. Es `Year::datos()` y nada más.
 *
 * ## POR QUÉ EXISTE, que es lo único interesante de un controlador de una línea
 *
 * Esos datos viven **sólo** dentro de `Year::datos()`, y `Year::datos()` no tenía
 * ninguna ruta propia. Así que hasta hoy el informe nuevo de *constancia de estudio*
 * los pedía a **`GET piars-config`** —la configuración del PIAR, la ruta de
 * inclusión— para poder imprimir el nombre del rector en un papel que no tiene nada
 * que ver con el PIAR. Funciona, y tiene precedente —el informe pedagógico hace lo
 * mismo—, pero es un nombre que miente sobre lo que la pantalla necesita, y el día
 * que alguien estreche el permiso de `piars-config` rompe una constancia.
 *
 * Y de paso **devuelve menos**: `piars-config` trae además la fila de `piars_config`
 * y los periodos del año, que a un membrete no le hacen falta.
 *
 * ## LAS DOS RAMAS DE `Year::datos()` SE SIRVEN POR LA MISMA RUTA, y eso se comprobó
 *
 * Sin `year_id` contesta **el año actual** —`$actual=true`, que es lo que hacen hoy
 * `piars-config` y `informes/datos`, y por tanto lo que la constancia ya recibía—. Con
 * `year_id` contesta **el de ese año**, que es lo que pide un papel de un año cerrado:
 * de los años cerrados se siguen pidiendo constancias, y el título que va impreso
 * —`titulo_constancia_estudio`— es del año.
 *
 * Las dos ramas de `Year::datos()` devuelven **las mismas 63 claves**, medido columna
 * a columna antes de colgarlas de una sola ruta. Si no fuera así esto tendrían que ser
 * dos rutas: una respuesta que cambia de forma según un parámetro opcional es la que
 * el cliente tipa una vez y rompe la otra.
 */
class MembreteController extends Controller
{
    use ResuelveElUsuario;

    public function getIndex()
    {
        if (! Request::has('year_id')) {
            // `$actual=true` **ignora el id que se le pase** —la consulta filtra por
            // `y.actual=true`—, así que se le da el del usuario por coherencia con
            // `informes/datos` y no porque decida nada.
            return Year::datos($this->user->year_id, true);
        }

        $year_id = (int) Request::input('year_id');

        // **La comprobación no es de cortesía: `Year::datos()` termina en `[0]`.** Un
        // año que no existe reventaría con «Undefined array key 0» y saldría un 500,
        // que es un fallo del servidor contando un error de quien llama.
        $existe = DB::selectOne('SELECT id FROM years WHERE id=? and deleted_at is null', [$year_id]);

        if ($existe === null) {
            abort(404, 'Ese año lectivo no existe.');
        }

        return Year::datos($year_id, false);
    }
}
