<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

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



	

	public function putUpdate($id)
	{
		$area = Area::findOrFail($id);

		$area->nombre	=	Request::input('nombre');
		$area->alias	=	Request::input('alias');
		$area->orden	=	Request::input('orden');
		$area->save();

	}

	public function deleteDestroy($id)
	{
		$areas = Area::findOrFail($id);
		$areas->delete();

		return $areas;
	}

}