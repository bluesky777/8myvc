<?php namespace App\Http\Controllers\Tardanzas;


use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Debugging;
use App\Support\Credenciales;
use App\User;
use App\Models\Ausencia;
use App\Services\Auditoria;

use Carbon\Carbon;
use \DateTime;
use App\Support\NombreDelAlumno;


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

		// Los nombres de todo el lote **en una sola consulta**, antes de entrar al
		// bucle. Cada línea de auditoría congela el nombre del alumno dentro (18
		// §2.4), y éste es el camino de más volumen del módulo: el lector sube el
		// recreo entero en una petición. Resolverlos de uno en uno duplicaría el
		// coste del endpoint — dentro del bucle `de()` ya no consulta nada.
		NombreDelAlumno::deVarios(array_column(
			is_array($ausencias_to_create) ? $ausencias_to_create : [], 'alumno_id'
		));

		foreach ($ausencias_to_create as $key => $ausencia_to) {

			if ($ausencia_to['uploaded'] == 'to_delete') {
				$aus 				= Ausencia::find($ausencia_to['id']);

				if ($aus) {
					$aus->uploaded 		= 'deleted';
					$aus->deleted_by 	= $user->id;
					$aus->save();

					// Una línea por falta y no una por subida: el lector sube el
					// recreo entero de una vez, y la pregunta que el colegio hace es
					// «quién borró ESTA falta».
					$alumnoDeLaLinea = $aus->alumno_id === null ? null : (int) $aus->alumno_id;

					Auditoria::registrar()
						->borrar('ausencia', (int) $aus->id)
						->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
						->en(asignatura: $aus->asignatura_id === null ? null : (int) $aus->asignatura_id,
							periodo: $aus->periodo_id === null ? null : (int) $aus->periodo_id)
						->de([
							'tipo' => $aus->tipo,
							'fecha_hora' => (string) $aus->fecha_hora,
							'cantidad_ausencia' => $aus->cantidad_ausencia,
							'cantidad_tardanza' => $aus->cantidad_tardanza,
						])
						->guardar();

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

				// El camino de más volumen de los dos: el lector de tardanzas sube
				// todas las faltas del recreo en una petición. Se audita **dentro
				// del bucle**, con el id que acaba de dar la base — auditar la
				// subida entera daría una línea por petición y buscar una falta
				// concreta no encontraría nada.
				//
				// El valor sale del cuerpo y no de una relectura, y aquí sí es lo
				// correcto: releer cada fila costaría una consulta por falta en el
				// camino que más filas escribe.
				$alumnoDeLaLinea = isset($ausencia_to['alumno_id']) ? (int) $ausencia_to['alumno_id'] : null;

				Auditoria::registrar()
					->crear('ausencia', (int) DB::getPdo()->lastInsertId())
					->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
					->en(asignatura: isset($ausencia_to['asignatura_id']) ? (int) $ausencia_to['asignatura_id'] : null,
						periodo: isset($ausencia_to['periodo_id']) ? (int) $ausencia_to['periodo_id'] : null)
					->a([
						'tipo' => $ausencia_to['tipo'] ?? null,
						'fecha_hora' => $ausencia_to['fecha_hora'] ?? null,
						'cantidad_ausencia' => $ausencia_to['cantidad_ausencia'] ?? null,
						'cantidad_tardanza' => $ausencia_to['cantidad_tardanza'] ?? null,
					])
					->guardar();

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

		// **Antes** del `delete()` y con `de(...)`: la línea guarda lo que la falta
		// ERA, que es lo que el colegio pregunta cuando alguien reclama.
		//
		// Y ésta es la pregunta que ningún detector de escrituras encuentra: no es
		// *«quién escribe aquí»* sino ***«quién puede quitar de aquí»***. Borrar una
		// falta lo puede hacer cualquiera del personal —decidido el 22 ago 2026, y
		// a propósito—, así que el rastro es lo único que queda; `deleted_by` dice
		// quién y no dice qué.
		$alumnoDeLaLinea = $ausencia->alumno_id === null ? null : (int) $ausencia->alumno_id;

		Auditoria::registrar()
			->borrar('ausencia', (int) $ausencia->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: $ausencia->asignatura_id === null ? null : (int) $ausencia->asignatura_id,
				periodo: $ausencia->periodo_id === null ? null : (int) $ausencia->periodo_id)
			->de([
				'tipo' => $ausencia->tipo,
				'fecha_hora' => (string) $ausencia->fecha_hora,
				'cantidad_ausencia' => $ausencia->cantidad_ausencia,
				'cantidad_tardanza' => $ausencia->cantidad_tardanza,
			])
			->guardar();

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

		// El rastro de la falta, que hasta hoy no dejaba ninguno (18 §4, fase 4).
		//
		// Va **después** del `findOrFail`, y eso hace que la línea guarde lo que
		// quedó ESCRITO en la fila y no lo que venía en el cuerpo. No es lo mismo:
		// `tipo` y las dos cantidades entran a pelo desde la petición, sin
		// validación —hay 2 validaciones en todo el proyecto—, y la columna las
		// convierte en silencio. Auditar el cuerpo contaría lo que se pidió; esto
		// cuenta lo que pasó.
		$alumnoDeLaLinea = $ausencia->alumno_id === null ? null : (int) $ausencia->alumno_id;

		Auditoria::registrar()
			->crear('ausencia', (int) $ausencia->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: $ausencia->asignatura_id === null ? null : (int) $ausencia->asignatura_id,
				periodo: $ausencia->periodo_id === null ? null : (int) $ausencia->periodo_id)
			->a([
				'tipo' => $ausencia->tipo,
				'fecha_hora' => (string) $ausencia->fecha_hora,
				'cantidad_ausencia' => $ausencia->cantidad_ausencia,
				'cantidad_tardanza' => $ausencia->cantidad_tardanza,
			])
			->guardar();

		return $ausencia;

	}




}