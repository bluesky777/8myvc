<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use App\Http\Controllers\Informes\CalcPerdidasDefinitivas;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;


class Boletines3Controller extends Controller {
	use ResuelveElUsuario;

	/**
	 * Esto lo llenaba el constructor. Lo llena ahora la primera lectura.
	 *
	 * Un constructor que consulta la base obliga a resolver al usuario antes de
	 * saber si la petición lo necesita, y eso es lo que rompía `route:list`. Ver
	 * App\Http\Controllers\Concerns\ResuelveElUsuario.
	 *
	 * La consulta iba dentro de un try/catch con el cuerpo comentado, que se
	 * tragaba cualquier error y dejaba la propiedad en null. El boletín salía
	 * entonces con los desempeños en blanco en vez de fallar, que es peor: un
	 * informe mudo se imprime y se entrega. Ahora el error sube.
	 */
	private $escalas_val;

	private function escalasVal()
	{
		if ($this->escalas_val === null) {
			$this->escalas_val = DB::select(
				'SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null',
				[$this->user->year_id]
			);
		}

		return $this->escalas_val;
	}

	

	public function putDetailedNotasGroup($grupo_id)
	{
		

		$periodo_a_calcular = Request::input('periodo_a_calcular', 10);

		$boletines = $this->detailedNotasGrupo($grupo_id, $this->user, '', $periodo_a_calcular);

		return $boletines;


	}

	/*
	 * `de_usuario` y no `10`.
	 *
	 * Hay dos funciones cuyos nombres se diferencian en una letra y que no aceptan
	 * lo mismo: `Periodo::hastaPeriodoN($year_id, $periodo_a_calcular = 10)` toma un
	 * NÚMERO —su 10 significa «hasta el periodo 10», o sea todos— y
	 * `Periodo::hastaPeriodo($year_id, $periodos_a_calcular = 'de_usuario')` toma
	 * una CADENA, y solo entiende `de_colegio`, `de_usuario` y `todos`.
	 *
	 * Este método llevaba el default de la primera y se lo pasaba por debajo a la
	 * segunda. Ninguna rama del `if` casaba con `10`, así que `$periodos` se quedaba
	 * en el `new stdClass()` con el que se inicializa; el `foreach` no iteraba y el
	 * `count()` sobre un stdClass lanzaba un TypeError que el `try/catch` de
	 * `alumnoAsignaturasPeriodosDetailed` convertía en `nota = 0`. Resultado: el
	 * acumulado del año salía entero en ceros, con 200 y sin una línea en el log.
	 * Igual en los tres controladores de boletines, que son copias.
	 *
	 * Se pone `de_usuario` —hasta el periodo del usuario— porque es el default de
	 * la propia `hastaPeriodo` y el que usa `EditnotaController`, el otro consumidor.
	 * Con `todos` en la URL se sigue pudiendo pedir el año completo.
	 */
	public function getDetailedNotasYear($grupo_id, $periodo_a_calcular='de_usuario')
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
		
		$des = EscalaDeValoracion::valoracion($alumno->promedio, $this->escalasVal());
		
		if ($des) {
			$alumno->promedio_desempenio = $des->desempenio;
		} 


		// COMPORTAMIENTO Y SUS FRASES
		if ($comport_and_frases) {
			
			$comportamiento = NotaComportamiento::nota_comportamiento($alumno->alumno_id, $periodo_id, $this->user->year_id, $this->escalasVal());

			$alumno->comportamiento = $comportamiento;
			$definiciones = [];
			
			$alumno->encabezado_comportamiento = $this->encabezado_comportamiento_boletin($alumno->comportamiento, $this->user->nota_minima_aceptada, $this->user->mostrar_nota_comport_boletin, $alumno->sexo);
			
			if ($comportamiento) {
				try {
					$definiciones = DefinicionComportamiento::frases($comportamiento->id);
					$alumno->comportamiento->definiciones = $definiciones;
				} catch (\Throwable $th) {
					$alumno->comportamiento['definiciones'] = $definiciones;
				}
			}


		}
		
		
		
		// DISCPLINA
		$alumno->situaciones = Disciplina::situaciones_year($alumno->alumno_id, $this->user->year_id, $periodo_id);
		

		
		
		// Agrupamos por áreas
		$alumno->areas = Area::agrupar_asignaturas_periodos($grupo_id, $asignaturas, $this->escalasVal(), $num_periodo);

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


	/**
	 * **Manda un ALUMNO a la papelera**, no un boletín. Ver 05 §89.
	 *
	 * Es la copia byte a byte de `EditnotaController::deleteDestroy`, que la §72
	 * cerró el 22 ago 2026 dejando escrito que cerrar una de tres es lo que pasa
	 * cuando se arregla el sitio que se está mirando y no la operación. La
	 * operación —`Alumno::find($id)` + `->delete()`— está cuatro veces en `app/`, y
	 * aquella se cerró sobre la población «este controlador»: éstas dos se
	 * quedaron abiertas el mismo día.
	 *
	 * El criterio no es nuevo: es el que ya usan `AlumnosController::deleteDestroy`
	 * y la de `editnota`. Y el hueco era real —medido con un token de profesor y
	 * `profes_can_edit_alumnos` apagada, que es como está en los dieciséis
	 * colegios—: `alumnos/destroy` contestaba 400 y ésta **200**, con el alumno en
	 * la papelera.
	 *
	 * No apaga ninguna pantalla: `BoletinesApi.ts` es el único sitio de los cuatro
	 * clientes que nombra `boletines3`, y declara `detailed-notas` y
	 * `detailed-notas-group`, no `destroy`.
	 *
	 * Lo fija `BoletinesBorranAlumnosTest`, con las cuatro puertas en el mismo caso.
	 */
	public function deleteDestroy($id)
	{
		Autoriza::exigir(Autoriza::puedeEditarAlumnos($this->user),
			'No tienes permiso para mandar un alumno a la papelera.');

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
				try {
					$la_nota = $nota->nota;
					$escala = EscalaDeValoracion::valoracion($la_nota, $this->escalasVal())->desempenio;
				} catch (\Throwable $th) {}

				if ($la_nota < $nota_minima_aceptada) {
					$clase = ' nota-perdida-bold ';
				}
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