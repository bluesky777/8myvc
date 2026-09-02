<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Support\Autoriza;
use App\Models\Nota;
use App\Models\Periodo;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Models\Grupo;
use App\Models\Year;
use App\Models\Alumno;
use App\Models\Asignatura;
use App\Models\Ausencia;
use App\Models\FraseAsignatura;
use App\Models\NotaComportamiento;
use App\Models\DefinicionComportamiento;
use App\Services\BoletinIndependiente;

use \stdClass;


class EditnotaController extends Controller {

	public function __construct()
	{
		
	}

	public function putAlumAsignatura()
	{
		$user = User::fromToken();

		$alumno_id 				= Request::input('alumno_id');
		$asignatura_id 			= Request::input('asignatura_id');
		$periodos_a_calcular 	= 'de_usuario';

		if (Request::has('periodos_a_calcular')) {
			$periodos_a_calcular = Request::input('periodos_a_calcular');
		}


		return $this->notasDeLaAsignatura($user->year_id, 
									$alumno_id, 
									$asignatura_id,
									$user->numero_periodo,
									$periodos_a_calcular);
	}

	private function notasDeLaAsignatura($year_id, $alumno_id, $asignatura_id, $periodo_usuario, $periodos_a_calcular='de_usuario')
	{
		$periodos = Periodo::hastaPeriodo($year_id, $periodos_a_calcular, $periodo_usuario);


		foreach ($periodos as $keyPer => $periodo) {

			$asigna = new stdClass();
			$asigna->unidades = Unidad::deAsignatura($asignatura_id, $periodo->id, $alumno_id);

			$nota_asignatura = 0;

			foreach ($asigna->unidades as $unidad) {
				
				$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
				$nota_unidad = 0;

				foreach ($unidad->subunidades as $subunidad) {

					// **Las columnas nombradas, y las seis de la nivelación A PROPÓSITO**
					// (22 §3.1 y §3.4). Aquí había un `->first()` pelado, que devuelve la
					// fila entera: `$subunidad->nota = $nota` la cuelga de la respuesta,
					// así que las cinco columnas de
					// `2026_09_02_100000_nivelaciones_columnas` **ya viajaban por aquí sin
					// que nadie lo hubiera decidido** — y nada lo habría cazado, porque
					// esta ruta no tiene instantánea de forma (la tiene desde hoy).
					//
					// Es el punto ciego que `tools/filas-enteras-al-cliente.php` declara en
					// su cabecera: un `Model::where(...)` encadenado en varias líneas no lo
					// ve. Se encontró leyendo el método por otra cosa, que es justo lo que
					// esa cabecera dice que hay que seguir haciendo.
					//
					// Se quedan porque **esta es la pantalla del par**: `editor-nota` pinta
					// la vigente y la original tachada, y sin ellas el docente nivela,
					// recarga y ve la nota vieja sin ninguna marca. Lo que cambia es que
					// ahora viajan porque alguien las nombró.
					// El `leftJoin` es por `nivelada_por_username`: la celda tiene **las
					// seis** claves aquí y en `notas/detailed`, porque el contrato las
					// declara juntas (22 §3.1) y el front pinta la misma celda en las dos
					// pantallas. Que una respuesta traiga cinco y la otra seis obliga a
					// escribir dos veces el mismo componente.
					//
					// `left` y no `inner`: sin nivelar no hay usuario, y con `inner`
					// **desaparecerían las celdas sin nivelar**, que son casi todas.
					$nota = Nota::select([
						'notas.id', 'notas.nota', 'notas.subunidad_id', 'notas.alumno_id',
						'notas.created_by', 'notas.updated_by', 'notas.deleted_by', 'notas.deleted_at',
						'notas.created_at', 'notas.updated_at',
						'notas.nota_original', 'notas.nota_nivelacion', 'notas.nivelada_at',
						'notas.nivelada_por', 'notas.nivelacion_obs',
						'usuarios_que_nivelaron.username as nivelada_por_username',
					])
						->leftJoin('users as usuarios_que_nivelaron', 'usuarios_que_nivelaron.id', '=', 'notas.nivelada_por')
						->where('notas.subunidad_id', $subunidad->subunidad_id)
						->where('notas.alumno_id', $alumno_id)->first();

					if ($nota) {
						$subunidad->nota = $nota;

						$subunidad->nota->valor = ($nota->nota * $subunidad->porcentaje_subunidad) / 100;
						$nota_unidad += $subunidad->nota->valor;
					}
					
				}

				$unidad->nota_unidad = $nota_unidad;
				$valor_unidad = ($unidad->nota_unidad * $unidad->porcentaje_unidad) / 100;
				$unidad->valor_unidad = $valor_unidad;

				$nota_asignatura += $unidad->valor_unidad;


			}

			$periodo->unidades = $asigna->unidades;

			$periodo->nota_asignatura_calc 	= $nota_asignatura; // Definitiva de la materia en este periodo
			
			// **Las columnas nombradas y el acta de la definitiva, que es lo que
			// `editor-nota` necesita** (22 §3.2). Esta pantalla —y no
			// `notas/detailed`— es la que edita la definitiva del periodo, así que sin
			// estas cuatro claves el docente niveló bien, recargó, y vio la nota vieja
			// **sin ninguna marca**: la escritura de A8 quedaba invisible al recargar.
			// Lo trajo el front el 2 sep.
			//
			// El `SELECT *` de antes no filtraba nada —sólo se copiaban tres campos al
			// periodo—, pero se nombra igual: la siguiente columna de `notas_finales`
			// no puede depender de que quien la añada mire también este método.
			//
			// `nota_original` con el mismo `CAST` que `nota`, y por lo mismo: la
			// columna es `DECIMAL(7,4)` y PDO la trae como cadena, así que sin él la
			// pantalla compararía un número con un texto para decidir si pinta el par.
			$nota_asignatura 		= DB::select(
				'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.periodo,
						CAST(nf.nota AS DOUBLE) AS nota, nf.manual, nf.recuperada,
						CAST(nf.nota_original AS DOUBLE) AS nota_original,
						CAST(nf.nota_nivelacion AS DOUBLE) AS nota_nivelacion,
						nf.nivelada_at, nf.nivelada_por, us.username AS nivelada_por_username,
						nf.nivelacion_obs, nf.updated_by, nf.created_at, nf.updated_at
				   FROM notas_finales nf
				   LEFT JOIN users us ON us.id = nf.nivelada_por
				  WHERE nf.alumno_id=? and nf.asignatura_id=? and nf.periodo_id=?',
				[$alumno_id, $asignatura_id, $periodo->id]
			);

			if (count($nota_asignatura) > 0) {
				$periodo->nota_asignatura 	= $nota_asignatura[0]->nota;
				$periodo->manual 			= $nota_asignatura[0]->manual;
				$periodo->recuperada 		= $nota_asignatura[0]->recuperada;

				// Las cuatro del acta, **siempre presentes** cuando hay definitiva, con
				// `null` si no está nivelada: una clave que a veces no viene obliga al
				// front a distinguir «vacío» de «no vino». Misma decisión que en
				// `notas/detailed`.
				$periodo->nota_original         = $nota_asignatura[0]->nota_original;
				$periodo->nota_nivelacion       = $nota_asignatura[0]->nota_nivelacion;
				$periodo->nivelada_at           = $nota_asignatura[0]->nivelada_at;
				$periodo->nivelada_por          = $nota_asignatura[0]->nivelada_por;
				$periodo->nivelada_por_username = $nota_asignatura[0]->nivelada_por_username;
				$periodo->nivelacion_obs        = $nota_asignatura[0]->nivelacion_obs;
			}
		}

		return $periodos;

	}



	public function getDetailedNotasYear()
	{
		$user = User::fromToken();

		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		foreach ($alumnos as $keyAlum => $alumno) {
			$alumno = Nota::alumnoAsignaturasPeriodosDetailed($alumno->alumno_id, $user->year_id, $periodos_a_calcular, $user->numero_periodo);
			array_push($alumnos_response, $alumno);
		}



		return array($grupo, $year, $alumnos_response);


	}


	public function putDetailedNotas($grupo_id)
	{
		$user = User::fromToken();

		$periodos_a_calcular = 'de_colegio';

		if (Request::has('requested_alumnos')) {
			$periodos_a_calcular = Request::input('periodos_a_calcular');
		}

		$requested_alumnos = '';

		if (Request::has('requested_alumnos')) {
			$requested_alumnos = Request::input('requested_alumnos');
		}

		$boletines = $this->detailedNotasGrupo($grupo_id, $user, $requested_alumnos, $periodos_a_calcular, $user->numero_periodo);

		//$grupo->alumnos = $alumnos;
		//$grupo->asignaturas = $asignaturas;
		//return (array)$grupo;

		return $boletines;


	}

	public function detailedNotasGrupo($grupo_id, $user, $requested_alumnos='', $periodos_a_calcular='de_usuario', $periodo_usuario=0)
	{
		
		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		$year->periodos = Periodo::hastaPeriodo($user->year_id, $periodos_a_calcular, $periodo_usuario);

		$grupo->cantidad_alumnos = count($alumnos);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades e indicadores
			$this->allNotasAlumno($alumno, $grupo_id, $user->periodo_id, true);


			$asignaturas_perdidas = $this->asignaturasPerdidasDeAlumno($alumno, $grupo_id, $user->year_id, $periodos_a_calcular, $periodo_usuario);

			if (count($asignaturas_perdidas) > 0) {
				
				$alumno->asignaturas_perdidas = $asignaturas_perdidas;
				$alumno->notas_perdidas_year = 0;
				$alumno->periodos_con_perdidas = Periodo::hastaPeriodo($user->year_id, $periodos_a_calcular, $periodo_usuario);

				foreach ($alumno->periodos_con_perdidas as $keyPerA => $periodoAlone) {

					$periodoAlone->cant_perdidas = 0;
					
					foreach ($alumno->asignaturas_perdidas as $keyAsig => $asignatura_perdida) {

						foreach ($asignatura_perdida->periodos as $keyPer => $periodo) {

							if ($periodoAlone->periodo_id == $periodo->periodo_id) {
								if ($periodo->id == $periodoAlone->id) {
									$periodoAlone->cant_perdidas += $periodo->cantNotasPerdidas;
								}
								
							}
						}
					}

					$alumno->notas_perdidas_year += $periodoAlone->cant_perdidas;
					
				}
			}
		}


		/*
		 * EL PUESTO LO DECIDE EL SERVICIO, no este `foreach` — fase 6 del
		 * [19](../../../docs/migracion/19-boletin-independiente.md), §7.
		 *
		 * Aquí había `Nota::puestoAlumno($alumno->promedio, $alumnos)` dentro del bucle, y
		 * ese mismo cálculo estaba **copiado en ocho sitios**. `puestoAlumno` sigue
		 * intacta y sigue siendo pura —cuenta cuántos promedios hay por encima—: lo que
		 * cambia es **quién entra en la lista contra la que se cuenta**, y eso lo decide
		 * `years.puestos_con_bol_independiente`.
		 *
		 * Con el interruptor en 1 —el default, y lo de los quince colegios hoy— esto es
		 * exactamente lo de antes, fila por fila. Con 0, el alumno con boletín
		 * independiente sale del recuento: su puesto viaja `null` (decisión 6, el front
		 * pinta `—`) y **a los demás les cambia el suyo**, porque un puesto es una
		 * posición relativa y no una nota. Si el que sale iba primero, los treinta de
		 * detrás suben uno, en pantalla y en el papel impreso.
		 *
		 * Es un informe **de un solo periodo** —el promedio sale de `allNotasAlumno` con
		 * `$user->periodo_id`—, así que la marca que decide es la de ese periodo y no la
		 * del año: quien fue independiente en el segundo cuenta con normalidad en el
		 * tercero.
		 */
		BoletinIndependiente::ponerPuestos($alumnos, [(int) $user->periodo_id], (int) $user->year_id);

		foreach ($alumnos as $alumno) {
			
			if ($requested_alumnos == '') {

				array_push($response_alumnos, $alumno);

			}else{

				foreach ($requested_alumnos as $req_alumno) {
					
					if ($req_alumno['alumno_id'] == $alumno->alumno_id) {
						array_push($response_alumnos, $alumno);
					}
				}
			}
			

		}

		return array($grupo, $year, $response_alumnos);
	}

	public function allNotasAlumno(&$alumno, $grupo_id, $periodo_id, $comport_and_frases=false)
	{


		$asignaturas	= Grupo::detailed_materias($grupo_id);

		foreach ($asignaturas as $asignatura) {
			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id, $alumno->alumno_id);

			foreach ($asignatura->unidades as $unidad) {
				$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
			}
		}

		$alumno->asignaturas = $asignaturas;

		$sumatoria_asignaturas = 0;

		foreach ($alumno->asignaturas as $asignatura) {

			if ($comport_and_frases) {
				$asignatura->ausencias	= Ausencia::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $periodo_id);
				$asignatura->frases		= FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $periodo_id);
			}

			Asignatura::calculoAlumnoNotas($asignatura, $alumno->alumno_id);

			$sumatoria_asignaturas += $asignatura->nota_asignatura; // Para sacar promedio del periodo


			// SUMAR AUSENCIAS Y TARDANZAS
			if ($comport_and_frases) {
				$cantAus = 0;
				$cantTar = 0;
				foreach ($asignatura->ausencias as $ausencia) {
					$cantAus += (int)$ausencia->cantidad_ausencia;
					$cantTar += (int)$ausencia->cantidad_tardanza;
				}

				$asignatura->total_ausencias = $cantAus;
				$asignatura->total_tardanzas = $cantTar;
			}

		}
		try {
			$alumno->promedio = $sumatoria_asignaturas / count($alumno->asignaturas);
		} catch (\Throwable $e) {
			$alumno->promedio = 0;
		}



		// COMPORTAMIENTO Y SUS FRASES
		if ($comport_and_frases) {

			$comportamiento = NotaComportamiento::where('alumno_id', '=', $alumno->alumno_id)
												->where('periodo_id', '=', $periodo_id)
												->first();

			$alumno->comportamiento = $comportamiento;
			$definiciones = [];

			if ($comportamiento) {
				$definiciones = DefinicionComportamiento::frases($comportamiento->id);
				$alumno->comportamiento->definiciones = $definiciones;
			}


		}
		


		return $alumno;
	}


	public function asignaturasPerdidasDeAlumno($alumno, $grupo_id, $year_id, $periodos_a_calcular, $periodo_usuario)
	{
		$asignaturas	= Grupo::detailed_materias($grupo_id);


		foreach ($asignaturas as $keyAsig => $asignatura) {
			
			$periodos = Periodo::hastaPeriodo($year_id, $periodos_a_calcular, $periodo_usuario);

			$asignatura->cantTotal = 0;

			foreach ($periodos as $keyPer => $periodo) {

				$periodo->cantNotasPerdidas = 0;
				$periodo->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id, $alumno->alumno_id);


				foreach ($periodo->unidades as $keyUni => $unidad) {
					
					$subunidades = Subunidad::perdidasDeUnidad($unidad->unidad_id, $alumno->alumno_id);
					
					if (count($subunidades) > 0) {
						$unidad->subunidades = $subunidades;
						$periodo->cantNotasPerdidas += count($subunidades);
					}else{
						$uniTemp = $periodo->unidades;
						unset($uniTemp[$keyUni]);
						$periodo->unidades = $uniTemp;
					}
				}
				//$periodo->unidades = $unidades;

				$asignatura->cantTotal += $periodo->cantNotasPerdidas;
				/*
				if (count($unidades) > 0) {
					$periodo->unidades = $unidades;
				}else{
					unset($periodos[$keyPer]);
				}
				*/
				
			}

			if (count($periodos) > 0) {
				$asignatura->periodos = $periodos;
			}else{
				unset($asignaturas[$keyAsig]);
			}

			$hasPeriodosConPerdidas = false;

			foreach ($periodos as $keyPer => $periodo) {
				if (count($periodo->unidades) > 0) {
					$hasPeriodosConPerdidas = true;
				}
			}

			if (!$hasPeriodosConPerdidas) {
				unset($asignaturas[$keyAsig]);
			}

		}

		return $asignaturas;

	}

	public function periodosPerdidosDeAlumno($alumno, $grupo_id, $year_id, $periodos)
	{
		//$periodos = Periodo::where('year_id', '=', $year_id)->get();

		foreach ($periodos as $key => $periodo) {
			$periodo->asignaturas = $this->asignaturasPerdidasDeAlumnoPorPeriodo($alumno->alumno_id, $grupo_id, $periodo->id);

			if (count($periodo->asignaturas)==0) {
				unset($periodos[$key]);
			}
		}
	}

	public function asignaturasPerdidasDeAlumnoPorPeriodo($alumno_id, $grupo_id, $periodo_id)
	{


		$asignaturas	= Grupo::detailed_materias($grupo_id);

		foreach ($asignaturas as $keyAsig => $asignatura) {

			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id, $alumno_id);

			foreach ($asignatura->unidades as $keyUni => $unidad) {
				$unidad->subunidades = Subunidad::perdidasDeUnidad($unidad->unidad_id, $alumno_id);

				if (count($unidad->subunidades) == 0) {
					unset($asignatura->unidades[$keyUni]);
				}
			}
			if (count($asignatura->unidades) == 0) {
				unset($asignaturas[$keyAsig]);
			}
		}


		return $asignaturas;
	}


	/**
	 * **Manda un ALUMNO a la papelera**, no una nota. Ver 05 §72.
	 *
	 * El criterio no es nuevo: es el que ya usa la hermana de al lado,
	 * `AlumnosController::deleteDestroy`. Aquí faltaba, y el hueco era real —
	 * `puedeEditarAlumnos` es superusuario **o** profesor con
	 * `profes_can_edit_alumnos`, que está apagada en los dieciséis colegios, así que
	 * un profesor no podía mandar un alumno a la papelera por `alumnos/destroy` y sí
	 * por aquí.
	 *
	 * El `forceDelete` de más abajo se cerró en su día y estos dos se quedaron: es lo
	 * que pasa cuando se arregla **el sitio que se está mirando** y no la operación.
	 */
	public function deleteDestroy($id)
	{
		Autoriza::exigir(Autoriza::puedeEditarAlumnos(User::fromToken()),
			'No tienes permiso para mandar un alumno a la papelera.');

		$alumno = Alumno::find($id);
		//Alumno::destroy($id);
		//$alumno->restore();
		//$queries = DB::getQueryLog();
		//$last_query = end($queries);
		//return $last_query;

		if ($alumno) {
			$alumno->delete();
		}else{
			return abort(404, 'Alumno no existe o está en Papelera.');
		}
		return $alumno;
	
	}	

	public function deleteForcedelete($id)
	{
		// Este método no tenía NINGUNA autenticación: el constructor está vacío y
		// aquí no se llamaba a fromToken. Hoy es inerte por accidente, porque falta
		// el use de App\Models\Alumno y revienta con "class not found" antes de
		// borrar. Basta que alguien añada ese import en una limpieza para que se
		// convierta en un borrado de alumnos sin token. Se cierra ahora.
		$user = User::fromToken();

		Autoriza::exigir(Autoriza::puedeBorrarAlumnos($user),
			'No tienes permiso para eliminar alumnos definitivamente.');

		$alumno = Alumno::onlyTrashed()->findOrFail($id);
		
		$alumno->forceDelete();
		return $alumno;
	
	}

	/** Saca un ALUMNO de la papelera. Mismo criterio que `deleteDestroy`, y por lo mismo. */
	public function putRestore($id)
	{
		Autoriza::exigir(Autoriza::puedeEditarAlumnos(User::fromToken()),
			'No tienes permiso para restaurar un alumno.');

		$alumno = Alumno::onlyTrashed()->findOrFail($id);

		$alumno->restore();
		return $alumno;
	}


	public function getTrashed()
	{
		$user = User::fromToken();
		$previous_year = $user->year - 1;
		$id_previous_year = 0;
		$previous_year = Year::where('year', '=', $previous_year)->first();


		$consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_superuser, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:id_previous_year
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id)
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year2_id
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is not null';

		return DB::select($consulta, array(
						':id_previous_year'	=>$id_previous_year, 
						':year_id'			=>$user->year_id,
						':year2_id'			=>$user->year_id
				));
	}

}