<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Informes\CalcPerdidasDefinitivas;

use Request;
use DB;
use Hash;

use App\User;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Year;
use App\Models\Nota;
use App\Models\Alumno;
use App\Models\Role;
use App\Models\Matricula;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Ausencia;
use App\Models\FraseAsignatura;
use App\Models\Asignatura;
use App\Models\NotaComportamiento;
use App\Models\DefinicionComportamiento;
use App\Models\ImageModel;
use App\Models\EscalaDeValoracion;
use App\Models\Area;
use App\Models\Debugging;
use App\Models\Disciplina;
use \Log;

use Carbon\Carbon;


class Boletines3Controller extends Controller {
	
	public $user;
	public $escalas_val;
	
	public function __construct()
	{
		$this->user = User::fromToken();
		$this->escalas_val = DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$this->user->year_id]);
	}
	

	public function putDetailedNotasGroup($grupo_id)
	{
		

		$periodo_a_calcular = Request::input('periodo_a_calcular', 10);

		$boletines = $this->detailedNotasGrupo($grupo_id, $this->user, '', $periodo_a_calcular);

		return $boletines;


	}

	public function getDetailedNotasYear($grupo_id, $periodo_a_calcular=10)
	{
		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($this->user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		//return Nota::alumnoAsignaturasPeriodosDetailed($alumno->alumno_id, $user->year_id, $periodos_a_calcular, $user->numero_periodo); // borrar

		foreach ($alumnos as $keyAlum => $alumno) {
			$alumno = Nota::alumnoAsignaturasPeriodosDetailed($alumno->alumno_id, $this->user->year_id, $periodo_a_calcular, $this->user->numero_periodo);
			array_push($alumnos_response, $alumno);
		}


		return array($grupo, $year, $alumnos_response);

	}


	public function putDetailedNotas($grupo_id)
	{
		$periodo_a_calcular 	= Request::input('periodo_a_calcular', 10);
		$requested_alumnos 		= Request::input('requested_alumnos', '');

		$boletines = $this->detailedNotasGrupo($grupo_id, $this->user, $requested_alumnos, $periodo_a_calcular);
		return $boletines;


	}

	public function detailedNotasGrupo($grupo_id, &$user, $requested_alumnos='', $periodo_a_calcular=4)
	{
		
		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id, $requested_alumnos);

		$year->periodos = Periodo::hastaPeriodoN($user->year_id, $periodo_a_calcular);
		$year->periodo = $this->user->numero_periodo;
		
		$grupo->cantidad_alumnos = count($alumnos);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades y subunides
			$this->allNotasAlumno($alumno, $grupo_id, $user->periodo_id, true, $periodo_a_calcular);

			
			$this->asignaturasPerdidasDeAlumno($alumno, $grupo_id, $user->year_id, $periodo_a_calcular);
			
			if ($this->user->year_pasado_en_bol) {
				if (!$alumno->nuevo && !$alumno->repitente) {
					$this->datosYearPasado($alumno, $grupo_id, $user->year_id);
				}
			}
			
			unset($alumno->asignaturas);
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

	public function allNotasAlumno(&$alumno, $grupo_id, $periodo_id, $comport_and_frases=false, $num_periodo=4)
	{

		$asignaturas				= Grupo::detailed_materias_notas_finales($alumno->alumno_id, $grupo_id, $this->user->year_id, $num_periodo);
		$ausencias_total			= Ausencia::totalDeAlumno($alumno->alumno_id, $periodo_id);
		$asignaturas_perdidas 		= [];
	
		$sumatoria_asignaturas 		= 0;
		$alumno->ausencias_total 	= $ausencias_total;

		foreach ($asignaturas as $asignatura) {
			
			
			if ($num_periodo == 1) {
				$asignatura->prom_year 	= $asignatura->nota_final_per1;
			}elseif ($num_periodo == 2) {
				$asignatura->prom_year 	= ($asignatura->nota_final_per1 + $asignatura->nota_final_per2) / 2;
			}elseif ($num_periodo == 3) {
				$asignatura->prom_year 	= ($asignatura->nota_final_per1 + $asignatura->nota_final_per2 + $asignatura->nota_final_per3) / 3;
			}elseif ($num_periodo == 4) {
				$asignatura->prom_year 	= ($asignatura->nota_final_per1 + $asignatura->nota_final_per2 + $asignatura->nota_final_per3 + $asignatura->nota_final_per4) / 4;
			}
			
			$sumatoria_asignaturas += $asignatura->prom_year; // Para sacar promedio del periodo
			
			// SUMAR AUSENCIAS Y TARDANZAS
			if ($comport_and_frases) {
				$asignatura->ausencias	= Ausencia::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $periodo_id);
				$asignatura->frases		= FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $periodo_id);
				
				$cantAus = 0;
				$cantTar = 0;
				foreach ($asignatura->ausencias as $ausencia) {
					if ($ausencia->tipo == "tardanza") {
						$cantTar += (int)$ausencia->cantidad_tardanza;
					}elseif ($ausencia->tipo == "ausencia") {
						$cantAus += (int)$ausencia->cantidad_ausencia;
					}
					
				}

				$asignatura->total_ausencias = $cantAus;
				$asignatura->total_tardanzas = $cantTar;
			}
			
			
			
			$asignatura->unidades = Unidad::deAsignaturaCalculada($alumno->alumno_id, $asignatura->asignatura_id, $this->user->periodo_id, true, $this->user->year_id);
			
			
		}

		$alumno->asignaturas = $asignaturas;


		if (count($alumno->asignaturas) == 0) {
			$alumno->promedio = 0;
		} else {
			$alumno->promedio = $sumatoria_asignaturas / count($alumno->asignaturas);
		}
		
		$des = EscalaDeValoracion::valoracion($alumno->promedio, $this->escalas_val);
		
		if ($des) {
			$alumno->promedio_desempenio = $des->desempenio;
		} 


		// COMPORTAMIENTO Y SUS FRASES
		if ($comport_and_frases) {
			
			$comportamiento = NotaComportamiento::nota_comportamiento($alumno->alumno_id, $periodo_id);

			$alumno->comportamiento = $comportamiento;
			$definiciones = [];
			
			$alumno->encabezado_comportamiento = $this->encabezado_comportamiento_boletin($alumno->comportamiento, $this->user->nota_minima_aceptada, $this->user->mostrar_nota_comport_boletin, $alumno->sexo);
			
			if ($comportamiento) {
				
				$definiciones = DefinicionComportamiento::frases($comportamiento->id);
				$alumno->comportamiento->definiciones = $definiciones;
			}


		}
		
		
		
		// DISCPLINA
		$alumno->situaciones = Disciplina::situaciones_year($alumno->alumno_id, $this->user->year_id, $periodo_id);
		

		
		
		// Agrupamos por áreas
		$alumno->areas = Area::agrupar_asignaturas_periodos($grupo_id, $asignaturas, $this->escalas_val, $num_periodo);

		return $alumno;
	}


	public function asignaturasPerdidasDeAlumno(&$alumno, $grupo_id, $year_id, $periodo_a_calcular)
	{
		//$asignaturas	= Grupo::detailed_materias_notas_finales($alumno->alumno_id, $grupo_id, $this->user->year_id);
		$alumno->asignaturas_perdidas = [];
		$alumno->notas_perdidas_year = 0;
		$alumno->notas_perdidas_per1 = 0;
		$alumno->notas_perdidas_per2 = 0;
		$alumno->notas_perdidas_per3 = 0;
		$alumno->notas_perdidas_per4 = 0;

		foreach ($alumno->asignaturas as $keyAsig => $asignatura) {
			
			$calcPerdidas = new CalcPerdidasDefinitivas();
			$periodos = $calcPerdidas->hastaPeriodoConDefinitivas($alumno->alumno_id, $asignatura->asignatura_id, $grupo_id, $periodo_a_calcular);
			if(count($periodos)>0){
				
				if ($this->user->si_recupera_materia_recup_indicador){
					if ($periodos[0]->definitiva_year < $this->user->nota_minima_aceptada && $periodos[0]->cant_perdidas_year > 0) {
						$asignatura->detalle_periodos = $periodos[0];
						
						$alumno->notas_perdidas_year += $periodos[0]->cant_perdidas_year;
						$alumno->notas_perdidas_per1 += $periodos[0]->cant_perdidas_1;
						if(isset($periodos[0]->cant_perdidas_2)) $alumno->notas_perdidas_per2 += $periodos[0]->cant_perdidas_2;
						if(isset($periodos[0]->cant_perdidas_3)) $alumno->notas_perdidas_per3 += $periodos[0]->cant_perdidas_3;
						if(isset($periodos[0]->cant_perdidas_4)) $alumno->notas_perdidas_per4 += $periodos[0]->cant_perdidas_4;
						
						array_push($alumno->asignaturas_perdidas, $asignatura);
					}
					
				}else{
					if ($periodos[0]->cant_perdidas_year > 0) {
						$asignatura->detalle_periodos = $periodos[0];
						
						$alumno->notas_perdidas_year += $periodos[0]->cant_perdidas_year;
						$alumno->notas_perdidas_per1 += $periodos[0]->cant_perdidas_1;
						if(isset($periodos[0]->cant_perdidas_2)) $alumno->notas_perdidas_per2 += $periodos[0]->cant_perdidas_2;
						if(isset($periodos[0]->cant_perdidas_3)) $alumno->notas_perdidas_per3 += $periodos[0]->cant_perdidas_3;
						if(isset($periodos[0]->cant_perdidas_4)) $alumno->notas_perdidas_per4 += $periodos[0]->cant_perdidas_4;
						
						array_push($alumno->asignaturas_perdidas, $asignatura);
					}
				}
				
			} 

		}

		return $alumno;

	}



	public function datosYearPasado(&$alumno, $grupo_id, $year_id)
	{
		$year_ant_num 	= $this->user->year - 1;
		
		$consulta 		= 'SELECT y.year, y.id as year_id, g.id as grupo_id, si_recupera_materia_recup_indicador, nota_minima_aceptada
						FROM years y
						INNER JOIN grupos g ON g.year_id=y.id and g.deleted_at is null
						INNER JOIN matriculas m ON m.grupo_id=g.id and (m.estado="MATR" or m.estado="ASIS") and m.deleted_at is null and m.alumno_id=?
						WHERE y.deleted_at is null and y.year=?';
						
		$year_ant 		= DB::select($consulta, [$alumno->alumno_id, $year_ant_num]);
		
		if (count($year_ant) > 0) {
			//Debugging::pin('Mas de cero');
			$year_ant 				= $year_ant[0];
			$asignaturas			= Grupo::detailed_materias($year_ant->grupo_id);
			
			$alumno->asignaturas_year_pasado = [];
			$alumno->yp_notas_perdidas_year = 0;
			$alumno->yp_notas_perdidas_per1 = 0;
			$alumno->yp_notas_perdidas_per2 = 0;
			$alumno->yp_notas_perdidas_per3 = 0;
			$alumno->yp_notas_perdidas_per4 = 0;

			foreach ($asignaturas as $keyAsig => $asignatura) {
				
				$calcPerdidas = new CalcPerdidasDefinitivas();
				$periodos = $calcPerdidas->hastaPeriodoConDefinitivas($alumno->alumno_id, $asignatura->asignatura_id, $grupo_id, 4);
				if(count($periodos)>0){
					
					if ($year_ant->si_recupera_materia_recup_indicador){
						if ($periodos[0]->definitiva_year < $year_ant->nota_minima_aceptada && $periodos[0]->cant_perdidas_year > 0) {
							$asignatura->detalle_periodos = $periodos[0];
							
							$alumno->yp_notas_perdidas_year += $periodos[0]->cant_perdidas_year;
							$alumno->yp_notas_perdidas_per1 += $periodos[0]->cant_perdidas_1;
							$alumno->yp_notas_perdidas_per2 += $periodos[0]->cant_perdidas_2;
							$alumno->yp_notas_perdidas_per3 += $periodos[0]->cant_perdidas_3;
							$alumno->yp_notas_perdidas_per4 += $periodos[0]->cant_perdidas_4;
							
							array_push($alumno->asignaturas_year_pasado, $asignatura);
						}
						
					}else{
						if ($periodos[0]->cant_perdidas_year > 0) {
							$asignatura->detalle_periodos = $periodos[0];
							
							$alumno->yp_notas_perdidas_year += $periodos[0]->cant_perdidas_year;
							$alumno->yp_notas_perdidas_per1 += $periodos[0]->cant_perdidas_1;
							$alumno->yp_notas_perdidas_per2 += $periodos[0]->cant_perdidas_2;
							$alumno->yp_notas_perdidas_per3 += $periodos[0]->cant_perdidas_3;
							$alumno->yp_notas_perdidas_per4 += $periodos[0]->cant_perdidas_4;
							
							array_push($alumno->asignaturas_year_pasado, $asignatura);
						}
					}
						
					
				} 
				
			}

			
		}
		
		return $alumno;
	}
	

	public function periodosPerdidosDeAlumno($alumno, $grupo_id, $year_id, $periodos)
	{
		$perdidos = new IndicadoresPerdidos();
		$perdidos->de_asignaturas_por_periodos($alumno->alumno_id, $grupo_id, $periodos);
		/*
		foreach ($periodos as $key => $periodo) {
			$periodo->asignaturas = $this->asignaturasPerdidasDeAlumnoPorPeriodo($alumno->alumno_id, $grupo_id, $periodo->id);

			if (count($periodo->asignaturas)==0) {
				unset($periodos[$key]);
			}
		}*/
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
		
		if ($alumno) {
			$alumno->delete();
		}else{
			return abort(400, 'Alumno no existe o está en Papelera.');
		}
		return $alumno;
	
	}	

	
	
	private function encabezado_comportamiento_boletin($nota, $nota_minima_aceptada, $mostrar_nota_comport, $sexo){
		
		$icono 		= '';
		
		if ($sexo == 'F') {
			$icono = 'fa-male';
		}else{
			$icono = 'fa-female';
		}
		
		if ($nota) {
			$clase 		= '';
			$la_nota 	= '';
			$escala = '';
			
			if ( $mostrar_nota_comport ) {
				$la_nota = $nota->nota;
				if ($la_nota < $nota_minima_aceptada) {
					$clase = ' nota-perdida-bold ';
				}
				$escala = EscalaDeValoracion::valoracion($la_nota, $this->escalas_val)->desempenio;
			}
			
			
			
			$res = '<div class="row comportamiento-head">
						<div class="col-lg-10 col-xs-10 comportamiento-title"><i style="padding-right: 5px;" class="fa '.$icono.'"></i>  Comportamiento</div>
						<div style="padding: 0px; text-align: center;" class="col-lg-1 col-xs-1 comportamiento-desempenio ">'.$escala.'</div>
						<div class="col-lg-1 col-xs-1 comportamiento-nota '. $clase .'">'.$la_nota.'</div>
					</div>';
			
		}else{
			$res = '<div class="row comportamiento-head">
						<div class="col-lg-10 col-xs-10 comportamiento-title"><i style="padding-right: 5px;" class="fa '.$icono.'"></i>  Comportamiento</div>
					</div>';
		}
		return $res;
	}
	

}