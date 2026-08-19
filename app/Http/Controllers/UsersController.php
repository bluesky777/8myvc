<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\User;
use \Log;

use Carbon\Carbon;
use App\Exports\AlumnosExport;
use Maatwebsite\Excel\Facades\Excel;



class UsersController extends Controller {

	public function getExport()
	{
		return Excel::download(new AlumnosExport, 'alumnos.xlsx');
	}

	public function putUsernamesCheck()
	{
		$texto = Request::input('texto');

		$consulta = 'SELECT username FROM users WHERE username like :texto;';
		
		$res = DB::select($consulta, [
			':texto'		=> $texto.'%'
		]);
		
		return [ 'usernames' => $res ];
	}


	public function postCrearAdministrador()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		
		if($user->is_superuser){
			$username = 'usuario'.rand(100, 9999);
			
			$consulta = 'INSERT INTO users(username, password, sexo, is_superuser, tipo, is_active, periodo_id, created_by, created_at) 
				VALUES("'.$username.'", "'.Hash::make('123456').'", "M", 1, "Usuario", 1, 1, ?, "'.$now.'")';
				
			DB::insert($consulta, [$user->user_id]);
			
			$id = DB::getPdo()->lastInsertId();
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES('.$id.', 1)';
				
			DB::insert($consulta);
			
			$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
				u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
				u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
				from users u
				left join images i on i.id=u.imagen_id 
				where u.id='.$id;
				
			$usuario = DB::select($consulta)[0];

			return ['usuario'=>$usuario];
		}else{
			return abort(404, 'Sin autorización');
		}
		
	}



	public function postCrearPsicologo()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		
		if($user->is_superuser){
			$username = 'psicologo'.rand(100, 9999);
			
			$consulta = 'INSERT INTO users(username, password, sexo, is_superuser, tipo, is_active, periodo_id, created_by, created_at) 
				VALUES("'.$username.'", "'.Hash::make('123456').'", "M", 0, "Usuario", 1, 1, ?, "'.$now.'")';
				
			DB::insert($consulta, [$user->user_id]);
			
			$id = DB::getPdo()->lastInsertId();
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES('.$id.', 11)'; //  Psicólogo
				
			DB::insert($consulta);
			
			$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
				u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
				u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
				from users u
				left join images i on i.id=u.imagen_id 
				where u.id='.$id;
				
			$usuario = DB::select($consulta)[0];

			return ['usuario'=>$usuario];
		}else{
			return abort(404, 'Sin autorización');
		}
		
	}

	

	public function postCrearEnfermero()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		
		if($user->is_superuser){
			$username = 'enfermero'.rand(100, 9999);
			
			$consulta = 'INSERT INTO users(username, password, sexo, is_superuser, tipo, is_active, periodo_id, created_by, created_at) 
				VALUES("'.$username.'", "'.Hash::make('123456').'", "M", 0, "Usuario", 1, 1, ?, "'.$now.'")';
				
			DB::insert($consulta, [$user->user_id]);
			
			$id = DB::getPdo()->lastInsertId();
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES('.$id.', 7)'; //  Enfermero
				
			DB::insert($consulta);
			
			$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
				u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
				u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
				from users u
				left join images i on i.id=u.imagen_id 
				where u.id='.$id;
				
			$usuario = DB::select($consulta)[0];

			return ['usuario'=>$usuario];
		}else{
			return abort(404, 'Sin autorización');
		}
		
	}

	
}