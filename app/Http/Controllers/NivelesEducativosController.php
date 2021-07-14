<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\NivelEducativo;


class NivelesEducativosController extends Controller {

	public function getIndex()
	{
		return NivelEducativo::orderBy("orden")->get();
	}


	public function postStore()
	{
		try {
			$nivel = new NivelEducativo;
			$nivel->nombre	=	Request::input('nombre');
			$nivel->abrev	=	Request::input('abrev');
			$nivel->orden	=	Request::input('orden');
			$nivel->save();

			return $nivel;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
		}
	}


	public function getShow($id)
	{
		return NivelEducativo::findOrFail($id);
	}


	public function putUpdate($id)
	{
		$nivel = NivelEducativo::findOrFail($id);
		try {
			$nivel->nombre	=	Request::input('nombre');
			$nivel->abrev	=	Request::input('abrev');
			$nivel->orden	=	Request::input('orden');

			$nivel->save();
			return $nivel;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
		}
	}


	public function deleteDestroy($id)
	{
		$nivel = NivelEducativo::findOrFail($id);
		$nivel->delete();

		return $nivel;
	}

}