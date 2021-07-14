<?php namespace App\Http\Controllers\Tardanzas;


use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Http\Controllers\Controller;

use Request;
use Auth;
use Hash;
use DB;

use App\Models\Debugging;
use App\User;


class TLoginController extends Controller {

	public function postIndex()
	{

		$userTemp 	= [];
		$usuario 	= [];



		$credentials = [
			'username' => Request::input('username'),
			'password' => (string)Request::input('password')
		];
		
		if (Auth::attempt($credentials)) {
			$userTemp = Auth::user();

		}else if (Request::has('username') && Request::input('username') != ''){

			$pass = Hash::make((string)Request::input('password'));
			$usuario = User::where('password', '=', $pass)
							->where('username', '=', Request::input('username'))
							->get();

			if ( count( $usuario) > 0) {
				$userTemp = Auth::login($usuario[0]);
			}else{
				$usuario = User::where('password', '=', (string)Request::input('password'))
							->where('username', '=', Request::input('username'))
							->get();
				if ( count( $usuario) > 0) {
					$usuario[0]->password = Hash::make((string)$usuario[0]->password);
					$usuario[0]->save();
					$userTemp = Auth::loginUsingId($usuario[0]->id);
				}else{
					return abort(400, 'Credenciales inválidas.');
				}
			}
		}else{
			return abort(401, 'Por favor ingrese de nuevo.');
		}



		$consulta = '';

		switch ($userTemp->tipo) {  // Alumno, Profesor, Acudiente, Usuario.
			case 'Profesor':
				
				$consulta = 'SELECT p.id as persona_id, p.nombres, p.apellidos, p.sexo, p.fecha_nac, p.user_id, u.username, u.password,
								IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,  
								per.id as periodo_id, per.numero as numero_periodo, per.year_id
							from profesores p 
							inner join users u on u.id=p.user_id 
							left join images i on i.id=:imagen_id
							left join periodos per on per.id=:periodo_id
							where p.deleted_at is null and p.user_id=:user_id';

				$usuario = DB::select($consulta, [
					':user_id'		=> $userTemp->id, 
					':imagen_id'	=> $userTemp->imagen_id, 
					':periodo_id'	=> $userTemp->periodo_id,
				]);
				
				break;



			case 'Usuario':
				
				$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.is_superuser, u.tipo, 
								u.sexo, u.email, "N/A" as fecha_nac, u.password, 
								u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
								per.id as periodo_id, per.numero as numero_periodo, per.year_id
							from users u
							left join periodos per on per.id=u.periodo_id
							left join images i on i.id=u.imagen_id 
							where u.id=:user_id and u.deleted_at is null';

				$usuario = DB::select($consulta, array(
					':user_id'		=> $userTemp->id
				));

				break;
		} 


		$usuario = (array)$usuario[0];
		$userTemp = (array)$userTemp['attributes'];
		$usuario = array_merge($usuario, $userTemp);
		$usuario = (object)$usuario;
				

		return json_decode(json_encode($usuario), true);
	}



	public function postTraerDatos()
	{

		$userTemp 	= [];
		$usuario 	= [];
		
		$credentials = [
			'username' => Request::input('username'),
			'password' => (string)Request::input('password')
		];
		
		if (Auth::attempt($credentials)) {
			$userTemp = Auth::user();

		}else if (Request::has('username') && Request::input('username') != ''){

			$pass = Hash::make((string)Request::input('password'));
			$usuario = User::where('password', '=', $pass)
							->where('username', '=', Request::input('username'))
							->get();

			if ( count( $usuario) > 0) {
				$userTemp = Auth::login($usuario[0]);
			}else{
				$usuario = User::where('password', '=', (string)Request::input('password'))
							->where('username', '=', Request::input('username'))
							->get();
				if ( count( $usuario) > 0) {
					$usuario[0]->password = Hash::make((string)$usuario[0]->password);
					$usuario[0]->save();
					$userTemp = Auth::loginUsingId($usuario[0]->id);
				}else{
					return abort(400, 'Credenciales inválidas.');
				}
			}
		}else{
			return abort(401, 'Por favor ingrese de nuevo.');
		}



		$consulta = '';

		switch ($userTemp->tipo) {  // Alumno, Profesor, Acudiente, Usuario.
			case 'Profesor':
				
				$consulta = 'SELECT p.id as persona_id, p.nombres, p.apellidos, p.sexo, p.fecha_nac, p.user_id, u.username, u.password,
								IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,  
								per.id as periodo_id, per.numero as numero_periodo, per.year_id, y.year
							from profesores p 
							inner join users u on u.id=p.user_id 
							left join images i on i.id=:imagen_id
							left join periodos per on per.id=:periodo_id
							inner join years y on y.id=per.year_id
							where p.deleted_at is null and p.user_id=:user_id';

				$usuario = DB::select($consulta, [
					':user_id'		=> $userTemp->id, 
					':imagen_id'	=> $userTemp->imagen_id, 
					':periodo_id'	=> $userTemp->periodo_id,
				]);
				
				break;



			case 'Usuario':
				
				$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.is_superuser, u.tipo, 
								u.sexo, u.email, "N/A" as fecha_nac, u.password, 
								u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
								per.id as periodo_id, per.numero as numero_periodo, per.year_id, y.year
							from users u
							left join periodos per on per.id=u.periodo_id
							inner join years y on y.id=per.year_id
							left join images i on i.id=u.imagen_id 
							where u.id=:user_id and u.deleted_at is null';

				$usuario = DB::select($consulta, array(
					':user_id'		=> $userTemp->id
				));

				break;
		}


		$usuario = (array)$usuario[0];
		$userTemp = (array)$userTemp['attributes'];
		$usuario = array_merge($usuario, $userTemp);
		$usuario = (object)$usuario;
				
		$year_id 	= $usuario->year_id;
		$anio 		= $usuario->year;

		if (Request::has('year_id')) {
		 	$year_id 	= Request::input('year_id');
		} 

		// Alumnos
		$cons_alum = "SELECT a.id, a.nombres, a.apellidos, sexo, user_id, a.fecha_nac, a.religion, a.pazysalvo, a.deuda 
			from alumnos a 
			inner join matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado='MATR' or m.estado='ASIS' or m.estado='PREM')
			inner join grupos g ON m.grupo_id=g.id and g.deleted_at is null
			inner join years y ON g.year_id=y.id and y.year=? and y.deleted_at is null
			WHERE a.deleted_at is null";
		$alumnos = DB::select($cons_alum, [$anio]);


		// Periodos
		$cons_per = "SELECT p.* FROM periodos p inner join years y ON p.year_id=y.id and y.year=? and y.deleted_at is null";
		$periodos = DB::select($cons_per, [$anio]);


		// Matriculas
		$cons_matri = "SELECT m.id, m.alumno_id, m.grupo_id, m.estado, g.nombre as nombre_grupo, g.abrev, g.year_id 
					FROM matriculas m
					inner join grupos g on g.id=m.grupo_id and (m.estado='MATR' or m.estado='ASIS' or m.estado='PREM')
					inner join years y ON g.year_id=y.id and y.year=? and y.deleted_at is null";
		$matriculas = DB::select($cons_matri, [$anio]);

		// Grupos
		$cons_gr = "SELECT * FROM grupos WHERE year_id=? and deleted_at is null";
		$grupos = DB::select($cons_gr, [$year_id]);

		// Profesores
		$cons_pr = "SELECT p.id, p.nombres, p.apellidos, p.sexo, p.fecha_nac FROM profesores p
					inner join contratos c on p.id=c.profesor_id and p.deleted_at is null
					inner join years y ON c.year_id=y.id and y.year=? and y.deleted_at is null
					WHERE p.deleted_at is null and c.deleted_at is null
					group by p.id;";
		$profesores = DB::select($cons_pr, [$anio]);

		// Ausencias
		$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.tipo, a.fecha_hora, a.uploaded, a.created_by 
					FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.deleted_at is null
					inner join years y ON p.year_id=y.id and y.year=? and y.deleted_at is null
					WHERE a.deleted_at is null and a.asignatura_id is null;";
		$ausencias = DB::select($cons_aus, [$anio]); // , [":year_id" => $year_id]


		// Años
		$cons_ye = "SELECT * FROM years y WHERE y.year=? and y.deleted_at is null";
		$years = DB::select($cons_ye, [$anio]);


		$usuario->alumnos 		= $alumnos;
		$usuario->matriculas 	= $matriculas;
		$usuario->periodos 		= $periodos;
		$usuario->grupos 		= $grupos;
		$usuario->profesores 	= $profesores;
		$usuario->ausencias 	= $ausencias;
		$usuario->years 		= $years;

		//return json_decode(json_encode($user[0]), true);

		return json_decode(json_encode($usuario), true);
	}


	public function postTraerDatosAusencias()
	{

		$userTemp 	= [];
		$usuario 	= [];
		

		$credentials = [
			'username' => Request::input('username'),
			'password' => (string)Request::input('password')
		];
		
		if (Auth::attempt($credentials)) {
			$userTemp = Auth::user();

		}else if (Request::has('username') && Request::input('username') != ''){

			$pass = Hash::make((string)Request::input('password'));
			$usuario = User::where('password', '=', $pass)
							->where('username', '=', Request::input('username'))
							->get();

			if ( count( $usuario) > 0) {
				$userTemp = Auth::login($usuario[0]);
			}else{
				$usuario = User::where('password', '=', (string)Request::input('password'))
							->where('username', '=', Request::input('username'))
							->get();
				if ( count( $usuario) > 0) {
					$usuario[0]->password = Hash::make((string)$usuario[0]->password);
					$usuario[0]->save();
					$userTemp = Auth::loginUsingId($usuario[0]->id);
				}else{
					return abort(400, 'Credenciales inválidas.');
				}
			}
		}else{
			return abort(401, 'Por favor ingrese de nuevo.');
		}


		$consulta = 'SELECT u.username, per.id as periodo_id, per.numero as numero_periodo, per.year_id
							from users u 
							inner join periodos per on per.id=u.periodo_id
							where u.deleted_at is null and u.id=:user_id';

		$usuario = DB::select($consulta, [
			':user_id'		=> $userTemp->id, 
		]);
				

		$usuario 	= (array)$usuario[0];
		$userTemp 	= (array)$userTemp['attributes'];
		$usuario 	= array_merge($usuario, $userTemp);
		$usuario 	= (object)$usuario;
				
		$year_id 	= $usuario->year_id;

		if (Request::has('year_id')) {
		 	$year_id 	= Request::input('year_id');
		} 


		// Ausencias
		$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.tipo, a.fecha_hora, a.uploaded, a.created_by FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.year_id=:year_id
					WHERE a.deleted_at is null;";
		$ausencias = DB::select($cons_aus, [":year_id" => $year_id]);

		return json_decode(json_encode($ausencias), true);
	}

	function default_image_id($sexo)
	{
		if ($sexo == 'F') {
			return 2;
		}else{
			return 1; // ID de la imagen masculina
		}
	}
	function default_image_name($sexo)
	{
		if ($sexo == 'F') {
			return 'default_female.png';
		}else{
			return 'default_male.png';
		}
	}



}