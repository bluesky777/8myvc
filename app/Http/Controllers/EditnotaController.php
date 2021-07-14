<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Nota;
use App\Models\Periodo;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Models\Grupo;
use App\Models\Year;

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
			$asigna->unidades = Unidad::deAsignatura($asignatura_id, $periodo->id);

			$nota_asignatura = 0;

			foreach ($asigna->unidades as $unidad) {
				
				$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
				$nota_unidad = 0;

				foreach ($unidad->subunidades as $subunidad) {
					
					$nota = Nota::where('subunidad_id', $subunidad->subunidad_id)
								->where('alumno_id', $alumno_id)->first();

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
			
			$nota_asignatura 		= DB::select('SELECT * FROM notas_finales WHERE alumno_id=? and asignatura_id=? and periodo_id=?',
													[$alumno_id, $asignatura_id, $periodo->id]);
													
			if (count($nota_asignatura) > 0) {
				$periodo->nota_asignatura 	= $nota_asignatura[0]->nota;
				$periodo->manual 			= $nota_asignatura[0]->manual;
				$periodo->recuperada 		= $nota_asignatura[0]->recuperada;
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


		foreach ($alumnos as $alumno) {
			
			$alumno->puesto = Nota::puestoAlumno($alumno->promedio, $alumnos);
			
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
			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id);

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
		} catch (Exception $e) {
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
				$periodo->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id);


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

			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id);

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


	public function deleteDestroy($id)
	{
		$alumno = Alumno::find($id);
		//Alumno::destroy($id);
		//$alumno->restore();
		//$queries = DB::getQueryLog();
		//$last_query = end($queries);
		//return $last_query;

		if ($alumno) {
			$alumno->delete();
		}else{
			return App::abort(400, 'Alumno no existe o está en Papelera.');
		}
		return $alumno;
	
	}	

	public function deleteForcedelete($id)
	{
		$alumno = Alumno::onlyTrashed()->findOrFail($id);
		
		if ($alumno) {
			$alumno->forceDelete();
		}else{
			return App::abort(400, 'Alumno no encontrado en la Papelera.');
		}
		return $alumno;
	
	}

	public function putRestore($id)
	{
		$alumno = Alumno::onlyTrashed()->findOrFail($id);

		if ($alumno) {
			$alumno->restore();
		}else{
			return App::abort(400, 'Alumno no encontrado en la Papelera.');
		}
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

		return DB::select(DB::raw($consulta), array(
						':id_previous_year'	=>$id_previous_year, 
						':year_id'			=>$user->year_id,
						':year2_id'			=>$user->year_id
				));
	}

}