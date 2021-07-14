<?php namespace App\Http\Controllers;

use Request;
use DB;

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
		} catch (Exception $e) {
			return $e;
		}
	}
	
	
	public function putUpdateOrden()
	{
		$user = User::fromToken();

		$sortHash = Request::input('sortHash');

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){

				$area 			= Area::find((int)$key);
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