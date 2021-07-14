<?php namespace App\Http\Controllers;

use Request;
use DB;


use App\User;
use App\Models\VtAspiracion;
use App\Models\VtVotacion;


class VtAspiracionesController extends Controller {


	public function index()
	{
		$user = User::fromToken();

		$votacion = VtVotacion::where('actual', true)
							->where('user_id', $user->user_id)
							->where('year_id', $user->year_id)->first();

		$aspiraciones = VtAspiracion::where('votacion_id', $votacion->id)->get();
		return $aspiraciones;
	}



	public function postStore()
	{

		try {
			$aspiracion = new VtAspiracion;
			$aspiracion->votacion_id	=	Request::input('votacion_id');
			$aspiracion->save();

			return $aspiracion;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
			return $e;
		}
	}


	public function putUpdate()
	{
		$id = Request::input('id');
		$aspiracion = VtAspiracion::findOrFail($id);
		
		try {
			$aspiracion->aspiracion 	=	Request::input('aspiracion');
			$aspiracion->abrev			=	Request::input('abrev');

			$aspiracion->save();
			return $aspiracion;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
			return $e;
		}
	}


	public function deleteDestroy($id)
	{
		$aspiracion = VtAspiracion::findOrFail($id);
		$aspiracion->delete();

		return $aspiracion;
	}

}