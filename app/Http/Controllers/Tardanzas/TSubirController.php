<?php namespace App\Http\Controllers\Tardanzas;


use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Debugging;
use App\Support\Credenciales;
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
		
		
		
		// Era Auth::attempt() + Auth::user(). El guard `api` ya no es el de JWT
		// sino `sesion`, que resuelve al usuario del token de la petición y por
		// tanto no tiene attempt(): llamarlo devolvía 500. Aquí no hace falta un
		// guard —el lector manda usuario y contraseña en cada petición—, solo
		// comprobar la contraseña. Ver app/Support/Credenciales.php.
		$autenticado = Credenciales::verificar($credentials['username'], $credentials['password']);

		if ($autenticado !== null) {
			$userTemp = $autenticado;

		}else if (Request::has('username') && Request::input('username') != ''){
			// Aquí colgaban dos respaldos que había que quitar.
			//
			// El primero comparaba la columna contra Hash::make() de la
			// contraseña recién escrita. Inalcanzable por construcción: bcrypt
			// lleva una sal distinta en cada llamada, así que su salida no
			// coincide nunca con un hash guardado.
			//
			// El segundo comparaba la columna contra la contraseña EN CLARO, y
			// si acertaba la hasheaba en su sitio y dejaba entrar. Era el camino
			// de subida para las cuentas guardadas sin hashear — que las había,
			// porque VtParticipantesController las creaba así. Tratar una
			// columna sin hashear como credencial válida es exactamente lo que
			// no se puede hacer, y menos en el único endpoint que acepta
			// usuario y contraseña en cada petición.
			return abort(400, 'Credenciales inválidas.');
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

				// `'Y-m-d G:H:i'` escribía **la hora dos veces**: `G` y `H` son las dos la
				// hora del día —una sin cero delante y otra con él—, así que el formato era
				// hora:hora:minutos y los segundos no llegaban nunca. Las 21:07:33 se
				// guardaban como 21:21:07. Es el mismo de `ChangeAskedController` (05 §121);
				// aquí escribe el `created_at` y el `updated_at` de **cada ausencia que sube
				// el lector de tardanzas**, que es el camino de más volumen de los dos.
				//
				// Se arregla y no rompe a nadie porque **no hay quien lo lea así**: el único
				// sitio que parseaba con ese formato —`AusenciasController:177`— lleva años
				// dentro de un `/* */`. Ver 05 §123.
				$dt = Carbon::now('America/Bogota')->format('Y-m-d H:i:s');

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
					':created_by'			=> $user->id,
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
			':created_by'			=> $user->id,
			':created_at'			=> $dt,
			':updated_at'			=> $dt,
		]);

		$id = DB::getPdo()->lastInsertId();

		$ausencia = Ausencia::findOrFail($id);

		return $ausencia;

	}




}