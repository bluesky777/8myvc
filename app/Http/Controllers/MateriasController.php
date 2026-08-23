<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\CamposQueVinieron;
use App\User;
use App\Models\Materia;

class MateriasController extends Controller {

	public function getIndex()
	{
		$user 		= User::fromToken();
		$datos 		= [];
		
		$consulta 	= 'SELECT * FROM materias WHERE deleted_at is null';
		$materias 	= DB::select($consulta);
		
		$consulta 	= 'SELECT * FROM areas WHERE deleted_at is null';
		$areas 		= DB::select($consulta);
		
		$cant_areas = count($areas);
		
		for ($i=0; $i < $cant_areas; $i++) { 
			
			$consulta 			= 'SELECT * FROM materias WHERE deleted_at is null and area_id=?';
			$materias_area 		= DB::select($consulta, [$areas[$i]->id ] );
			$areas[$i]->materias 	= $materias_area;
		
		}
		
		$datos 		= [ 'materias' => $materias, 'mat_por_areas' => $areas ];
		return $datos;
	}
	
	
	public function putUpdateOrden()
	{
		//$user = User::fromToken();
		
		$partFrom	= Request::input('partFrom');
		
		$sortHash 	= $partFrom['sortHash'];

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){
				// `find()` devolvía null con un id que no existe y la línea de abajo
				// reventaba: 500 donde tocaba 404. El bucle de reordenar está copiado en
				// cinco controladores y los cinco lo tenían; el de unidades se arregló en
				// la §47 y éste salió al contarlos. Ver 05 §52.
				$materia 			= Materia::findOrFail((int)$key);
				$materia->orden 	= (int)$value;
				$materia->save();
			}
		}
		
		
		if (Request::has('partTo')) {
			$partTo		= Request::input('partTo');
			$sortHash 	= $partTo['sortHash'];

			for($row = 0; $row < count($sortHash); $row++){
				foreach($sortHash[$row] as $key => $value){

					$materia 			= Materia::findOrFail((int)$key);
					$materia->orden 	= (int)$value;
					$materia->area_id 	= $partTo['area_id'];
					$materia->save();
				}
			}
		}

		return 'Ordenado correctamente';
	}



	public function postIndex()
	{
		User::fromToken();

		if (Request::input('area')['id']) {
			Request::merge(array('area' => Request::input('area')['id'] ) );
		}

		$materia = new Materia;
		$materia->materia	=	Request::input('materia');
		$materia->alias		=	Request::input('alias');
		$materia->area_id	=	Request::input('area');
		$materia->save();

		return $materia;

	}


	/**
	 * §81, la misma de `AreasController::putUpdate`, y §82 por el lado del id.
	 *
	 * Dos cosas, y la primera tapaba a la segunda:
	 *
	 * 1. `Request::input('area')['id']` sobre `null` reventaba **antes** del
	 *    `findOrFail`, así que esta ruta contestaba **500 «Trying to access
	 *    array offset on null»** a todo cuerpo que no trajera `area` ni
	 *    `area_id`, y también a un id que no existe — nunca llegaba al 404 que
	 *    dan sus nueve hermanas. Es el mismo `find()` sin `OrFail` de la §52,
	 *    con otra cara.
	 * 2. Con el cuerpo mínimo que sí pasa ese offset:
	 *
	 *        PUT materias/update/1  {"area_id":3}   ->  200, y `materia` queda en ''
	 *
	 *    o sea que FÍSICA se va y la respuesta devuelve la fila ya vacía. Es la
	 *    §81 entera, escondida detrás de un 500 que parecía el problema.
	 *
	 * El `area_id` de `materias` es nulable, así que aquí no hay aviso 1048 que
	 * valga: la que se pierde es `materia`, que sí es `NOT NULL`.
	 */
	public function putUpdate($id)
	{
		$vinieron = CamposQueVinieron::capturar();

		// `area.id` y no `Request::input('area')['id']`: ver el punto 1 de arriba.
		if (Request::input('area_id')) {
			Request::merge(array('area' => Request::input('area_id') ) );
		}else if (Request::input('area.id')) {
			Request::merge(array('area' => Request::input('area.id') ) );
		}

		$materia = Materia::findOrFail($id);

		if ($vinieron->trae('materia')) { $materia->materia = Request::input('materia'); }
		if ($vinieron->trae('alias'))   { $materia->alias   = Request::input('alias'); }

		if ($vinieron->trae('area') or $vinieron->trae('area_id')) {
			$materia->area_id = Request::input('area');
		}

		$materia->save();
		return $materia;
	}


	public function deleteDestroy($id)
	{
		$materia = Materia::findOrFail($id);
		$materia->delete();

		return $materia;
	}

}