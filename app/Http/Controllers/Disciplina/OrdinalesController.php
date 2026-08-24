<?php

namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
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
            /*
             * **Aquí no va llamada a `Auditoria`, y la ausencia es la decisión.**
             *
             * `dis_configuraciones` **no está en `Auditoria::ENTIDADES`**, y añadir
             * un nombre a esa constante es editar el vocabulario cerrado del
             * servicio: una decisión, no un `string` suelto (18 §2.3). Es la misma
             * razón por la que `dis_proceso_ordinales` tampoco tiene entrada.
             *
             * Las tres tablas que se quedan fuera de esta fase están listadas en
             * aud-4 §5, con lo que costaría entrarlas. Inventarles un nombre aquí
             * las metería en la pantalla con una etiqueta que nadie ha acordado, y
             * un tipo mal escrito se pierde en silencio — que es el cuarto problema
             * de `bitacoras` y justo de lo que se viene.
             */
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

        // Un ordinal es un artículo del manual de convivencia del colegio, escrito
        // a mano y citado por las faltas. Hasta hoy darlo de alta no dejaba rastro.
        Auditoria::registrar()
            ->crear('dis_ordinal', (int) $last_id)
            ->en(year: is_numeric($ordinal->year_id) ? (int) $ordinal->year_id : null)
            ->a([
                'ordinal' => $ordinal->ordinal,
                'tipo' => $ordinal->tipo,
                'descripcion' => $ordinal->descripcion,
                'pagina' => $ordinal->pagina,
            ])
            ->guardar();

        return (array) $ordinal;
    }

    /**
     * La foto de un ordinal del manual de convivencia, por clave primaria.
     *
     * Sólo las cuatro columnas que un ordinal ES. La línea de auditoría tiene que
     * poder leerse cuando el ordinal ya se borró — que es exactamente el caso que
     * `putDestroy` documenta: una falta que cita un artículo que ya no está.
     */
    private function fotoDelOrdinal($ordinalId): ?array
    {
        $fila = DB::selectOne(
            'SELECT year_id, ordinal, tipo, descripcion, pagina FROM dis_ordinales WHERE id = ?',
            [$ordinalId]
        );

        return $fila === null ? null : (array) $fila;
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

        $antes = $this->fotoDelOrdinal($ordinal_id);

        DB::update($consulta, $datos);

        Auditoria::registrar()
            ->editar('dis_ordinal', is_numeric($ordinal_id) ? (int) $ordinal_id : null)
            ->en(year: isset($antes['year_id']) && is_numeric($antes['year_id']) ? (int) $antes['year_id'] : null)
            ->de($antes === null ? null : [
                'ordinal' => $antes['ordinal'],
                'tipo' => $antes['tipo'],
                'descripcion' => $antes['descripcion'],
                'pagina' => $antes['pagina'],
            ])
            ->a([
                'ordinal' => Request::input('ordinal'),
                'tipo' => Request::input('tipo'),
                'descripcion' => Request::input('descripcion'),
                'pagina' => Request::input('pagina'),
            ])
            ->guardar();

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

        $antes = $this->fotoDelOrdinal($ordinal_id);

        DB::update($consulta, $datos);

        // Una celda suelta: la línea guarda **sólo la propiedad tocada**, no la
        // fila entera. Así la pantalla puede decir «cambió la página del artículo
        // 12» en vez de enseñar cuatro campos de los que tres son iguales.
        Auditoria::registrar()
            ->editar('dis_ordinal', is_numeric($ordinal_id) ? (int) $ordinal_id : null)
            ->en(year: isset($antes['year_id']) && is_numeric($antes['year_id']) ? (int) $antes['year_id'] : null)
            ->de([$propiedad => $antes[$propiedad] ?? null])
            ->a([$propiedad => Request::input('valor')])
            ->guardar();

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

        // **Antes** del borrado, y aquí el motivo está escrito en la cabecera de
        // este método: una falta que cita un ordinal borrado sale en el observador
        // del alumno con `descripcion` y `pagina` en null — el hueco ES el
        // contenido. `CatalogoEnUso` impide borrar los que alguien cita, pero los
        // que no cita nadie sí se van, y con esta línea el texto del artículo
        // sobrevive al borrado en un sitio que no se puede editar.
        $antes = $this->fotoDelOrdinal(Request::input('ordinal_id'));

        DB::delete($consulta, $datos);

        Auditoria::registrar()
            ->borrar('dis_ordinal', is_numeric(Request::input('ordinal_id')) ? (int) Request::input('ordinal_id') : null)
            ->en(year: isset($antes['year_id']) && is_numeric($antes['year_id']) ? (int) $antes['year_id'] : null)
            ->de($antes)
            ->guardar();

        return 'Eliminado';
    }
}
