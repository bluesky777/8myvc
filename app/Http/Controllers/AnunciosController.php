<?php namespace App\Http\Controllers;



use Request;
use DB;

use App\User;
use App\Models\ChangeAsked;
use App\Models\Alumno;


class AnunciosController extends Controller {

	public function getAll()
	{
		return Acudiente::all();
	}


	public function store()
	{
		try {
			$acudiente = new Acudiente;
			$acudiente->nombres		=	Request::input('nombres');
			$acudiente->apellidos	=	Request::input('apellidos');
			$acudiente->sexo		=	Request::input('sexo');
			$acudiente->user_id		=	Request::input('user_id');
			$acudiente->tipo_doc	=	Request::input('tipo_doc');
			$acudiente->documento	=	Request::input('documento');
			$acudiente->ciudad_doc	=	Request::input('ciudad_doc');
			$acudiente->telefono	=	Request::input('telefono');
			$acudiente->celular		=	Request::input('celular');
			$acudiente->ciudad_doc	=	Request::input('ocupacion');
			$acudiente->email		=	Request::input('email');

			$acudiente->save();

			return $acudiente;
		} catch (Exception $e) {
			return $e;
		}
	}


	public function update($id)
	{
		$acudiente = Acudiente::findOrFail($id);
		try {
			$acudiente->nombres		=	Request::input('nombres');
			$acudiente->apellidos	=	Request::input('apellidos');
			$acudiente->sexo		=	Request::input('sexo');
			$acudiente->user_id		=	Request::input('user_id');
			$acudiente->tipo_doc	=	Request::input('tipo_doc');
			$acudiente->documento	=	Request::input('documento');
			$acudiente->ciudad_doc	=	Request::input('ciudad_doc');
			$acudiente->telefono	=	Request::input('telefono');
			$acudiente->celular		=	Request::input('celular');
			$acudiente->ciudad_doc	=	Request::input('ocupacion');
			$acudiente->email		=	Request::input('email');


			$acudiente->save();
		} catch (Exception $e) {
			return $e;
		}
	}


	public function destroy($id)
	{
		$acudiente = Acudiente::findOrFail($id);
		$acudiente->delete();

		return $acudiente;
	}

}