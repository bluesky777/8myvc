<?php namespace App\Http\Controllers;

use Request;
use DB;

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
				$materia 			= Materia::find((int)$key);
				$materia->orden 	= (int)$value;
				$materia->save();
			}
		}
		
		
		if (Request::has('partTo')) {
			$partTo		= Request::input('partTo');
			$sortHash 	= $partTo['sortHash'];

			for($row = 0; $row < count($sortHash); $row++){
				foreach($sortHash[$row] as $key => $value){

					$materia 			= Materia::find((int)$key);
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


	public function putUpdate($id)
	{


		if (Request::input('area_id')) {
			Request::merge(array('area' => Request::input('area_id') ) );
		}else if (Request::input('area')['id']) {
			Request::merge(array('area' => Request::input('area')['id'] ) );
		}

		$materia = Materia::findOrFail($id);
		$materia->materia	=	Request::input('materia');
		$materia->alias		=	Request::input('alias');
		$materia->area_id	=	Request::input('area');


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