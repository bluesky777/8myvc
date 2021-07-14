<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\DefinicionComportamiento;

class DefinicionesComportamientoController extends Controller {


	public function getIndex()
	{
		return DefinicionComportamiento::all();
	}


	public function postStore()
	{
		$def = new DefinicionComportamiento;
		$def->comportamiento_id	=	Request::input('comportamiento_id');
		$def->frase_id			=	Request::input('frase_id');
		//$def->fecha			=	Request::input('fecha');

		$def->save();

		return $def;
	}

	public function postStoreEscrita()
	{
		$def = new DefinicionComportamiento;
		$def->comportamiento_id	=	Request::input('comportamiento_id');
		$def->frase				=	Request::input('frase');
		//$def->fecha			=	Request::input('fecha');

		$def->save();

		return $def;
	}

	public function show($id)
	{
		//
	}

	public function update($id)
	{
		$def = DefinicionComportamiento::findOrFail($id);
		$def->comportamiento_id	=	Request::input('comportamiento_id');
		$def->frase_id			=	Request::input('frase_id');
		//$def->fecha			=	Request::input('fecha');

		$def->save();
	}

	public function deleteDestroy($id)
	{
		$def = DefinicionComportamiento::find($id);

		if ($def) {
			$def->delete();
		}else{
			return 'No se encontró';
		}


		return $def;
	}

}