<?php namespace App\Http\Controllers;


//use Request;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\User;
use App\Models\EscalaDeValoracion;


class EscalasDeValoracionController extends Controller {

	public function getIndex()
	{
		$user 	= User::fromToken();

		$consulta 	= 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
		$year_id 	= $user->year_id ? $user->year_id : 1;
		$escalas 	= DB::select($consulta, [$year_id]);

		return $escalas;
	}


	public function postStore()
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$consulta 	= 'INSERT INTO escalas_de_valoracion(desempenio, orden, valoracion, porc_inicial, porc_final, year_id, perdido, created_at) 
														VALUES("SUPERIOR", 5, "S", 91, 100, ?, 0, ?)';
		DB::insert($consulta, [ $user->year_id, $now ]);

		$consulta 	= 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by id desc';
		$escala 	= DB::select($consulta, [$user->year_id])[0];


		return (array)$escala;
	}


	/**
	 * El id viene en el CUERPO —la ruta es `PUT escalas/update` a secas—, así que
	 * si no llega, o llega uno que no existe, el `UPDATE` no encuentra la fila.
	 *
	 * Antes contestaba «Guardado» igual. Es la familia que persigue
	 * `tools/respuestas-que-mienten.py`: una respuesta que dice que sí cuando fue
	 * que no es peor que un error, porque quien la lee deja de mirar (05 §37, §45).
	 * Ahora es 404, que en esta API significa «esa fila no está» desde la serie
	 * §44/§47/§49/§50/§53.
	 *
	 * **La comprobación es un SELECT y no las filas afectadas**, y eso no es un
	 * capricho: MySQL devuelve 0 filas afectadas cuando el UPDATE no cambia ningún
	 * valor, no sólo cuando no encuentra la fila. Contar filas aquí convertiría
	 * «guardar sin cambiar nada» en un 404. Es el mismo tropiezo que se cazó al
	 * escribir el UPSERT de las definitivas (10-definitivas.md, fase 1).
	 */
	public function putUpdate(Request $request)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$this->exigirQueLaEscalaExista($request->id);

		$consulta 	= 'UPDATE escalas_de_valoracion SET porc_inicial=:ini, porc_final=:fin, desempenio=:desemp, descripcion=:descripcion, icono_adolescente=:adolesc, icono_infantil=:infantil, orden=:orden, perdido=:perdido, valoracion=:valoracion, updated_at=:updated_at
						WHERE id=:id';
		$escalas 	= DB::update($consulta, [ ':ini' => $request->porc_inicial, ':fin' => $request->porc_final, ':desemp' => $request->desempenio, ':descripcion' => $request->descripcion, ':adolesc' => $request->icono_adolescente, ':infantil' => $request->icono_infantil, ':orden' => $request->orden, ':perdido' => $request->perdido, ':valoracion' => $request->valoracion, 'updated_at' => $now, ':id' => $request->id ]);

		return 'Guardado';

	}


	/**
	 * A la papelera, no borrada: la columna es `deleted_at` y las escalas de un año
	 * pasado siguen decidiendo cómo se pinta el desempeño en los boletines de ese
	 * año.
	 *
	 * Sobre el 404, lo mismo que en `putUpdate`. Y sobre el año: **se puede borrar
	 * la escala de otro año a propósito**, que es la decisión ya tomada para
	 * escribir en años pasados (05 §27.4); queda fijado por un test para que se vea
	 * si alguien la cambia.
	 */
	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$this->exigirQueLaEscalaExista($id);

		$consulta 	= 'UPDATE escalas_de_valoracion SET deleted_at=?  WHERE `id`=?';
		$escalas 	= DB::update($consulta, [ $now, $id ]);

		return 'En papelera';
	}


	/**
	 * 404 si la fila no está. Mira también `deleted_at`: una escala que ya está en
	 * la papelera no está, y volver a borrarla no es «hecho».
	 */
	private function exigirQueLaEscalaExista(mixed $id): void
	{
		$escala = DB::selectOne('SELECT id FROM escalas_de_valoracion
			WHERE id = ? AND deleted_at IS NULL', [ $id ]);

		if (! $escala) {
			abort(404, 'Esa escala de valoración no existe.');
		}
	}

}