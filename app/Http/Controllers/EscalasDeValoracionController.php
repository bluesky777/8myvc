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
	/**
	 * §122 — La séptima de la §81, y la que ningún detector podía ver.
	 *
	 * La §81 cerró seis rutas de editar catálogo que **vaciaban la fila y
	 * contestaban 200**; el barrido posterior de esa misma operación por todo
	 * `app/` dio 28 métodos más. Ésta no salió en ninguna de las dos listas, y
	 * las dos la tenían delante:
	 *
	 *  - el barrido busca `find/findOrFail/first` **más** `Request::input(...)`,
	 *    y aquí la existencia se comprueba con un `SELECT` en un helper privado
	 *    y la escritura es un `DB::update` crudo. **El método no llegó a ser
	 *    candidato**: la población de partida no era `app/`, era la parte de
	 *    `app/` que usa Eloquent — y en este repo hay 990 consultas crudas;
	 *  - y en la §81 se cayó de la lista porque con el cuerpo vacío contesta
	 *    **404**, que es correcto —el id va en el cuerpo— pero **contesta a otra
	 *    pregunta**. La de verdad empieza justo después del id.
	 *
	 * Lo que quedaba escrito, medido el 23 ago 2026 con `PUT escalas/update` y el
	 * cuerpo `{"id":1}`:
	 *
	 *     SUPERIOR · S · 46-50 · orden 5   ->   '' · '' · 0-0 · orden 0
	 *
	 * Seis de las nueve columnas que escribe son `NOT NULL`, y con
	 * `strict => false` eso no es un error: es `''` y `0`. **`porc_inicial=0,
	 * porc_final=0` es la banda colapsada** en la tabla que decide cómo se pinta
	 * el desempeño en todos los boletines del año.
	 *
	 * El respaldo va con el defecto de `input()` y no con `CamposQueVinieron`
	 * —que es lo que usan las seis de la §81— porque el discriminador entre las
	 * dos está medido: la clase hace falta cuando hay un `Request::merge()` o un
	 * `sanarInput*` **antes** de leer, y este controlador no tiene ninguno de los
	 * dos.
	 *
	 * Y el defecto sale de la fila que ya está en la base **sin costar una
	 * consulta**: `exigirQueLaEscalaExista()` ya hacía ese `SELECT`, sólo que
	 * pedía `id`. Ahora devuelve la fila entera.
	 */
	public function putUpdate(Request $request)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');

		$actual = $this->exigirQueLaEscalaExista($request->id);

		// `input($clave, $defecto)` y no `?:` ni `??`: los dos ceros de esta
		// tabla son legítimos —`porc_inicial = 0` es el borde inferior de la
		// escala más baja y `perdido = 0` el valor normal de las que se
		// aprueban—, así que el respaldo tiene que mirar **si la clave vino**,
		// no si el valor es cierto. Hay un test para cada uno de los dos.
		$consulta 	= 'UPDATE escalas_de_valoracion SET porc_inicial=:ini, porc_final=:fin, desempenio=:desemp, descripcion=:descripcion, icono_adolescente=:adolesc, icono_infantil=:infantil, orden=:orden, perdido=:perdido, valoracion=:valoracion, updated_at=:updated_at
						WHERE id=:id';
		$escalas 	= DB::update($consulta, [
			':ini' 			=> $request->input('porc_inicial', $actual->porc_inicial),
			':fin' 			=> $request->input('porc_final', $actual->porc_final),
			':desemp' 		=> $request->input('desempenio', $actual->desempenio),
			':descripcion' 	=> $request->input('descripcion', $actual->descripcion),
			':adolesc' 		=> $request->input('icono_adolescente', $actual->icono_adolescente),
			':infantil' 	=> $request->input('icono_infantil', $actual->icono_infantil),
			':orden' 		=> $request->input('orden', $actual->orden),
			':perdido' 		=> $request->input('perdido', $actual->perdido),
			':valoracion' 	=> $request->input('valoracion', $actual->valoracion),
			'updated_at' 	=> $now,
			':id' 			=> $request->id,
		]);

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
	 *
	 * **Devuelve la fila entera y no sólo el id** (§122): `putUpdate` la usa de
	 * respaldo para las columnas que el cliente no mandó, y con el `SELECT` ya
	 * hecho aquí eso no cuesta una consulta de más. Pedía `id` porque hasta la
	 * §122 nadie necesitaba lo demás.
	 */
	private function exigirQueLaEscalaExista(mixed $id): object
	{
		$escala = DB::selectOne('SELECT * FROM escalas_de_valoracion
			WHERE id = ? AND deleted_at IS NULL', [ $id ]);

		if (! $escala) {
			abort(404, 'Esa escala de valoración no existe.');
		}

		return $escala;
	}

}