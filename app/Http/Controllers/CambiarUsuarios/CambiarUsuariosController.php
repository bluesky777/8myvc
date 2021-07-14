<?php namespace App\Http\Controllers\CambiarUsuarios;


use Request;
use DB;
use Hash;
use App\User;
use \Log;

use Carbon\Carbon;
use App\Http\Controllers\Controller;


class CambiarUsuariosController extends Controller {

	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}


	public function putPonerDocumentoComoUsernameAlumnos()
	{
		$consulta = 'UPDATE IGNORE users u 
			INNER JOIN alumnos a ON a.user_id=u.id and a.deleted_at is null and u.tipo="Alumno"
			SET u.username=a.documento
			WHERE a.documento>0 and a.documento is not null and a.documento!="" and u.deleted_at is null';
		
		$res = DB::select($consulta);
		
		return [ 'resultado' => 'Usernames cambiados.' ];
	}



	public function putPonerDocumentoComoUsernameAcudientes()
	{
		$consulta = 'UPDATE IGNORE users u 
			INNER JOIN acudientes a ON a.user_id=u.id and a.deleted_at is null and u.tipo="Acudiente"
			SET u.username=a.documento
			WHERE a.documento>0 and a.documento is not null and a.documento!="" and u.deleted_at is null';
		
		$res = DB::select($consulta);
		
		return [ 'resultado' => 'Usernames cambiados.' ];
	}



	public function putPonerPasswordTodosAlumnos()
	{
		$password   = Hash::make(Request::input('clave'));
		$consulta   = 'UPDATE users SET password=:texto WHERE tipo="Alumno";';
		
		DB::update($consulta, [
			':texto'		=> $password
		]);
		
		return 'Contraseñas alumnos cambiadas';
	}


	public function putPonerPasswordTodosAcudientes()
	{
		$password   = Hash::make(Request::input('clave'));
		$consulta   = 'UPDATE users SET password=:texto WHERE tipo="Acudiente";';
		
		DB::update($consulta, [
			':texto'		=> $password
		]);
		
		return 'Contraseñas acudientes cambiadas';
	}


	
}