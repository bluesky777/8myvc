<?php namespace App\Http\Controllers\Actividades;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use App\Models\WsActividad;
use App\Models\WsPregunta;
use App\Models\WsOpcion;


class OpcionesController extends Controller {



	public function putGuardar()
	{
		$user 	= User::fromToken();
		
		$opc 					= WsOpcion::find(Request::input('id'));
		$opc->definicion 		= Request::input('definicion');
		$opc->orden 			= Request::input('orden');
		$opc->save();

		return $opc;
	}



	public function putAddOpcion()
	{
		$user 	= User::fromToken();
		
		$opcion 				= new WsOpcion();
		$opcion->definicion 	= Request::input('definicion');
		$opcion->pregunta_id 	= Request::input('pregunta_id');
		$opcion->orden 			= Request::input('orden');
		$opcion->is_correct 	= Request::input('is_correct');
		$opcion->save();

		return $opcion;
	}


	public function putSetOpcionCorrect()
	{
		$user 	= User::fromToken();
		$opcion	= WsOpcion::findOrFail(Request::input('id')); // La pongo aquí para que falle sin cambiar lo de abajo

		WsOpcion::where('pregunta_id', Request::input('pregunta_id'))
					->update(['is_correct' => false]);
		
		$opcion->is_correct 	= true;
		$opcion->save();

		return $opcion;
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$opc = WsOpcion::findOrFail($id);
		$opc->delete();

		return $opc;
	}

}