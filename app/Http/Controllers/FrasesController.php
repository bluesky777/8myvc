<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Frase;


class FrasesController extends Controller {


	public function getIndex()
	{
		$user = User::fromToken();

		$frases = Frase::where('year_id', '=', $user->year_id)->get();
		return $frases;
	}

	public function postStore()
	{
		$user = User::fromToken();
		
		$frase = new Frase;
		$frase->frase		= Request::input('frase');
		$frase->tipo_frase	= Request::input('tipo_frase');
		$frase->year_id		= $user->year_id;
		$frase->save();

		return $frase;
	}



	public function putUpdate($id)
	{
		$user = User::fromToken();

		$frase = Frase::findOrFail($id);
		$frase->frase 		= Request::input('frase');
		$frase->tipo_frase 	= Request::input('tipo_frase');
		
		$frase->save();
		return $frase;
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$frase = Frase::findOrFail($id);
		$frase->delete();
		return $frase;
	}

}