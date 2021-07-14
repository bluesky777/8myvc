<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\NivelEducativo;
use App\Models\Grado;

class GradosController extends Controller {


	public function getIndex()
	{	
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.nivel_educativo_id, g.created_at, g.updated_at, n.nombre as nombre_nivel 
			from grados g
			inner join niveles_educativos n on n.id=g.nivel_educativo_id and g.deleted_at is null
			order by g.orden';

		$grados = DB::select($consulta);

		return $grados;
	}

	
	public function postStore()
	{
		$user = User::fromToken();
		try {
			$grado = new Grado;
			$grado->nombre		=	Request::input('nombre');
			$grado->abrev		=	Request::input('abrev');
			$grado->orden		=	Request::input('orden');
			$grado->nivel_educativo_id =	Request::input('nivel')['id'];
			$grado->save();
			
			return $grado;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
		}
	}


	public function getShow($id)
	{
		$grado = Grado::findOrFail($id);
		$nivel = NivelEducativo::findOrFail($grado->nivel_educativo_id);
		$grado->nivel = $nivel;
		return $grado;
	}

	public function putUpdate($id)
	{
		$grado = Grado::findOrFail($id);

		if (!Request::input('nivel') and Request::input('nivel_educativo_id')) {
			Request::merge(array('nivel' => array('id' => Request::input('nivel_educativo_id') ) ));
		}

		try {
			$grado->nombre		=	Request::input('nombre');
			$grado->abrev		=	Request::input('abrev');
			$grado->orden		=	Request::input('orden');
			$grado->nivel_educativo_id	=	Request::input('nivel')['id'];


			$grado->save();
			return 'Cambiado';
		} catch (Exception $e) {
			return abort('400', $e);
		}
	}


	public function deleteDestroy($id)
	{
		$grado = Grado::findOrFail($id);
		$grado->delete();

		return 'Eliminado';
	}

}