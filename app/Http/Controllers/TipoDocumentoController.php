<?php namespace App\Http\Controllers;


use Request;
use DB;

use App\User;
use App\Models\TipoDocumento;

class TipoDocumentoController extends Controller {


	public function index()
	{
		return TipoDocumento::all();
	}

	public function store()
	{
		try {
			$tipo 			= new TipoDocumento;
			$tipo->tipo		= Request::input('tipo');
			$tipo->abrev	= Request::input('abrev');
			$tipo->save();

			return $tipo;
		} catch (Exception $e) {
			return $e;
		}
	}

	public function update($id)
	{
		$tipo = TipoDocumento::findOrFail($id);
		try {
			$tipo->tipo		= Request::input('tipo');
			$tipo->abrev	= Request::input('abrev');
			$tipo->save();

		} catch (Exception $e) {
			return $e;
		}
	}

	public function destroy($id)
	{
		$tipo = TipoDocumento::findOrFail($id);
		$tipo->delete();

		return $tipo;
	}

}