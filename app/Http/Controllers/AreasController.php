<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\CamposQueVinieron;
use App\Support\CatalogoEnUso;
use App\User;
use App\Models\Area;


class AreasController extends Controller {


	public function getIndex()
	{
		return Area::orderBy('orden')->get();
	}

	public function postIndex()
	{
		try {
			$area = new Area;
			$area->nombre	=	Request::input('nombre');
			$area->alias	=	Request::input('alias');
			$area->orden	=	Request::input('orden');
			$area->save();
			
			return $area;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}
	
	
	public function putUpdateOrden()
	{
		$user = User::fromToken();

		$sortHash = Request::input('sortHash');

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){

				// `find()` devolvía null con un id que no existe y la línea de abajo
				// reventaba: 500 donde tocaba 404. El bucle de reordenar está copiado en
				// cinco controladores y los cinco lo tenían; el de unidades se arregló en
				// la §47 y éste salió al contarlos. Ver 05 §52.
				$area 			= Area::findOrFail((int)$key);
				$area->orden 	= (int)$value;
				$area->save();
			}
		}

		return 'Ordenado correctamente';
	}



	

	/**
	 * §81. **Un campo que no se manda no es un campo que no cambia: es un campo
	 * que se pisa** —la frase de la §68— y aquí se pisaba contra una columna
	 * `NOT NULL`, que es donde deja de ser un despiste y pasa a borrarle el
	 * catálogo al colegio.
	 *
	 * Medido el 22 ago 2026: `PUT areas/update/1` con el cuerpo vacío dejaba
	 * `nombre` en `''`, `alias` y `orden` en `null`, y contestaba **200 con el
	 * cuerpo vacío** — ni siquiera devuelve la fila, así que el front no tenía
	 * dónde verlo.
	 *
	 * Lo que la §78 dio por bueno para **crear** no vale para **editar**, y no
	 * porque el código sea distinto —es el mismo, igual de crédulo— sino porque
	 * MySQL trata el mismo error de dos maneras. Con `strict => false`
	 * (config/database.php) y sobre esta misma columna:
	 *
	 *     UPDATE areas SET nombre=NULL WHERE id=1   ->  Warning 1048, queda ''
	 *     INSERT INTO areas (nombre) VALUES (NULL)  ->  ERROR   1048, rechazado
	 *
	 * Mismo código 1048, distinta severidad. O sea que **el `NOT NULL` al que la
	 * §78 le atribuyó salvar a ocho de los nueve no salva a ninguno por este
	 * lado**, y aquella conclusión no se puede arrastrar hasta aquí.
	 *
	 * El arreglo es el de la §68 —asignar sólo lo que vino— y no le cambia la
	 * respuesta a ningún cliente que mande la fila entera, que es lo que manda
	 * la pantalla de áreas.
	 */
	public function putUpdate($id)
	{
		$vinieron = CamposQueVinieron::capturar();

		$area = Area::findOrFail($id);

		if ($vinieron->trae('nombre')) { $area->nombre = Request::input('nombre'); }
		if ($vinieron->trae('alias'))  { $area->alias  = Request::input('alias'); }
		if ($vinieron->trae('orden'))  { $area->orden  = Request::input('orden'); }

		$area->save();

	}

	/**
	 * Un área con materias vivas no se borra — §70 y decisión de Joseth del 23 ago.
	 *
	 * Misma forma que `grados`: la materia se queda apuntando a un área en la
	 * papelera. Se cierra con las mismas dos mitades de siempre —corta y no
	 * escribe— y **hoy bloquearía en 20 de las 22 áreas vivas**, o sea que las dos
	 * que quedan libres son las únicas donde esta ruta hacía algo inocuo.
	 *
	 * **`niveles_educativos` se dejó fuera a propósito** aunque tiene la misma
	 * forma: allí bloquearía **4 de 4**, y una ruta enrutada que siempre contesta
	 * 422 es peor que la que no existe — no dice qué pretendía hacer la pantalla.
	 */
	public function deleteDestroy($id)
	{
		$areas = Area::findOrFail($id);

		CatalogoEnUso::exigirQueNadieApunte('materias', 'area_id', $areas->id, 'materias');

		$areas->delete();

		return $areas;
	}

}