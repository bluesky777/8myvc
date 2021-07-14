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
use App\Models\Ausencia;

use Carbon\Carbon;
use \DateTime;


class TSubirController extends Controller {

	public function user()
	{
		if (Request::has('loginData')) {
			
			$credentials = [
				'username' => Request::input('loginData')['username'],
				'password' => (string)Request::input('loginData')['password']
			];
		}else{
			$credentials = [
				'username' => Request::input('username'),
				'password' => (string)Request::input('password')
			];
		}
		
		
		
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

		if (!($userTemp->tipo == 'Profesor' || $userTemp->is_superuser)) {  // Alumno, Profesor, Acudiente, Usuario.
			return abort(400, 'No tienes permiso');
		}

		return $userTemp;


	}


	# Sube todos los cambios hechos
	public function postIndex()
	{
		$user = $this->user();

		$ausencias_to_create = Request::input('ausencias_to_create');

		foreach ($ausencias_to_create as $key => $ausencia_to) {

			if ($ausencia_to['uploaded'] == 'to_delete') {
				$aus 				= Ausencia::find($ausencia_to['id']);

				if ($aus) {
					$aus->uploaded 		= 'deleted';
					$aus->deleted_by 	= $user->id;
					$aus->save();
					$aus->delete();
				}
				

			}else{

				$dt = Carbon::now('America/Bogota')->format('Y-m-d G:H:i');

				$consulta = 'INSERT INTO ausencias
								(alumno_id, asignatura_id, cantidad_ausencia, cantidad_tardanza, entrada, tipo, fecha_hora, periodo_id, uploaded, created_by, created_at, updated_at)
							VALUES (:alumno_id, :asignatura_id, :cantidad_ausencia, :cantidad_tardanza, :entrada, :tipo, :fecha_hora, :periodo_id, :uploaded, :created_by, :created_at, :updated_at)';


				$ausenc = DB::insert($consulta, [
					':alumno_id'			=> $ausencia_to['alumno_id'], 
					':asignatura_id'		=> $ausencia_to['asignatura_id'],
					':cantidad_ausencia'	=> $ausencia_to['cantidad_ausencia'], 
					':cantidad_tardanza'	=> $ausencia_to['cantidad_tardanza'], 
					':entrada'				=> $ausencia_to['entrada'], 
					':tipo'					=> $ausencia_to['tipo'], 
					':fecha_hora'			=> $ausencia_to['fecha_hora'], 
					':periodo_id'			=> $ausencia_to['periodo_id'],
					':uploaded'				=> 'created',
					':created_by'			=> $ausencia_to['created_by'],
					':created_at'			=> $dt,
					':updated_at'			=> $dt,
				]);

			}
			

		}
		
	

		return json_decode(json_encode(['result' => 'Datos subidos']), true);
	}




	public function putEliminarAusencia()
	{
		$user = $this->user();

		$id = Request::input('ausencia_id');

		$ausencia 				= Ausencia::findOrFail($id);
		$ausencia->uploaded 	= 'deleted';
		$ausencia->deleted_by 	= $user->id;
		$ausencia->save();
		$ausencia->delete();
		return 'Eliminada';

	}

	# Poner ausencia o tardanza
	public function putPonerAusencia()
	{
		$user = $this->user();

		$dt = Carbon::now('America/Bogota');

		$consulta = 'INSERT INTO ausencias
						(alumno_id, asignatura_id, cantidad_ausencia, cantidad_tardanza, entrada, tipo, fecha_hora, periodo_id, uploaded, created_by, created_at, updated_at)
					VALUES (:alumno_id, :asignatura_id, :cantidad_ausencia, :cantidad_tardanza, :entrada, :tipo, :fecha_hora, :periodo_id, :uploaded, :created_by, :created_at, :updated_at)';


		$ausenc = DB::insert($consulta, [
			':alumno_id'			=> Request::input('alumno_id'), 
			':asignatura_id'		=> Request::input('asignatura_id'),
			':cantidad_ausencia'	=> Request::input('cantidad_ausencia'), 
			':cantidad_tardanza'	=> Request::input('cantidad_tardanza'), 
			':entrada'				=> Request::input('entrada'), 
			':tipo'					=> Request::input('tipo'), 
			':fecha_hora'			=> Request::input('fecha_hora'), 
			':periodo_id'			=> Request::input('periodo_id'),
			':uploaded'				=> 'created',
			':created_by'			=> Request::input('created_by'),
			':created_at'			=> $dt,
			':updated_at'			=> $dt,
		]);

		$id = DB::getPdo()->lastInsertId();

		$ausencia = Ausencia::findOrFail($id);

		return $ausencia;

	}




}