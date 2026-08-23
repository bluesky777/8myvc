<?php

namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use App\Support\CatalogoEnUso;
use App\Support\ColumnaSegura;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class OrdinalesController extends Controller
{
    public function putOrdinales()
    {
        $user = User::fromToken();
        $now = Carbon::now('America/Bogota');
        $year_id = Request::input('year_id', $user->year_id);

        // El `year_id` sale del cuerpo y aquí se concatenaba en el SQL, mientras las otras dos
        // consultas de este mismo método ya ligaban el parámetro. Con `OR 1=1` dentro salían los
        // ordinales de TODOS los años del colegio en vez de los del año pedido: `and` liga más
        // fuerte que `or`, así que el `deleted_at is null` se quedaba colgando del `or`.
        // No es la familia de ColumnaSegura —allí se concatena el NOMBRE de la columna y el valor
        // va ligado—, y por eso no lo tapaba. Lo fija CatalogosDelColegioTest.
        $consulta = 'SELECT * FROM dis_ordinales WHERE year_id=:year_id and deleted_at is null order by ordinal';

        $ordinales = DB::select($consulta, [':year_id' => $year_id]);

        $consulta = 'SELECT c.* FROM dis_configuraciones c
			WHERE c.year_id=:year_id and c.deleted_at is null';

        $config = DB::select($consulta, [':year_id' => $year_id]);

        if (count($config) > 0) {
            $config = $config[0];
        } else {
            $consulta = 'INSERT INTO dis_configuraciones(year_id, created_at, updated_at) VALUES(?,?,?)';
            DB::insert($consulta, [$year_id, $now, $now]);

            $last_id = DB::getPdo()->lastInsertId();

            $consulta = 'SELECT c.* FROM dis_configuraciones c
				WHERE c.id=? and c.deleted_at is null';

            $config = DB::select($consulta, [$last_id])[0];

        }

        $consulta = 'SELECT distinct o.tipo FROM dis_ordinales o
			WHERE o.year_id=:year_id and o.deleted_at is null order by o.tipo';

        $tipos = DB::select($consulta, [':year_id' => $year_id]);

        return ['ordinales' => $ordinales, 'configuracion' => $config, 'tipos' => $tipos];
    }

    public function postStore()
    {
        $user = User::fromToken();
        $now = Carbon::now('America/Bogota');

        $consulta = 'INSERT INTO dis_ordinales(year_id, ordinal, tipo, descripcion, pagina, created_at, updated_at) VALUES(?,?,?,?,?,?,?)';
        $datos = [
            Request::input('year_id'),
            Request::input('ordinal'),
            Request::input('tipo'),
            Request::input('descripcion'),
            Request::input('pagina'),
            $now,
            $now,
        ];

        DB::insert($consulta, $datos);

        $last_id = DB::getPdo()->lastInsertId();
        $consulta = 'SELECT d.* FROM dis_ordinales d WHERE d.id=?';

        $ordinal = DB::select($consulta, [$last_id])[0];

        return (array) $ordinal;
    }

    public function putUpdate()
    {
        $user = User::fromToken();
        $now = Carbon::now('America/Bogota');
        $ordinal_id = Request::input('id');

        $consulta = 'UPDATE dis_ordinales SET tipo=?, ordinal=?, descripcion=?, pagina=?, updated_by=?, updated_at=? WHERE id=?';
        $datos = [
            Request::input('tipo'),
            Request::input('ordinal'),
            Request::input('descripcion'),
            Request::input('pagina'),
            $user->user_id,
            $now,
            $ordinal_id,
        ];
        DB::update($consulta, $datos);

        return 'Cambiado';
    }

    public function putGuardarValor()
    {
        $user = User::fromToken();
        $now = Carbon::now('America/Bogota');
        $ordinal_id = Request::input('ordinal_id');
        $propiedad = Request::input('propiedad');

        $consulta = 'UPDATE dis_ordinales SET '.ColumnaSegura::exigir('dis_ordinales', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:ordinal_id';
        $datos = [
            ':valor' => Request::input('valor'),
            ':modificador' => $user->user_id,
            ':fecha' => $now,
            ':ordinal_id' => $ordinal_id,
        ];
        DB::update($consulta, $datos);

        return 'Cambiado';
    }

    public function putGuardarValorConfig()
    {
        $user = User::fromToken();
        $now = Carbon::now('America/Bogota');
        $config_id = Request::input('config_id');
        $propiedad = Request::input('propiedad');

        $consulta = 'UPDATE dis_configuraciones SET '.ColumnaSegura::exigir('dis_configuraciones', $propiedad).'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:id';
        $datos = [
            ':valor' => Request::input('valor'),
            ':modificador' => $user->user_id,
            ':fecha' => $now,
            ':id' => $config_id,
        ];
        DB::update($consulta, $datos);

        return 'Cambiado';
    }

    /**
     * Borrar un ordinal del manual de convivencia **dejaba en pie las faltas que
     * lo citaban, sin el artículo que dice qué se incumplió** (lote B).
     *
     * `Disciplina` une con `LEFT JOIN dis_ordinales o … and o.deleted_at is null`,
     * así que la situación sigue saliendo en el observador del alumno con
     * `ordinal`, `descripcion` y `pagina` en null. Por la regla de la §70.2 eso es
     * «un hueco visible» y no «esconder la fila» — pero aquí **el hueco es el
     * contenido**: una falta sin su artículo ya no dice qué norma se incumplió, y
     * es un registro disciplinario de un menor.
     *
     * **Joseth, 23 ago 2026: se impide, y el aviso dice cuántas dependen.** Hoy
     * lo bloquearía en 7 de los 16 ordinales vivos.
     */
    public function putDestroy()
    {
        $user = User::fromToken();
        $now = Carbon::now('America/Bogota');

        CatalogoEnUso::exigirQueNadieApunte('dis_proceso_ordinales', 'ordinal_id',
            Request::input('ordinal_id'), 'situaciones de disciplina');

        $consulta = 'UPDATE dis_ordinales SET deleted_at=?, deleted_by=? WHERE id=?';
        $datos = [$now, $user->user_id, Request::input('ordinal_id')];

        DB::delete($consulta, $datos);

        return 'Eliminado';
    }
}
