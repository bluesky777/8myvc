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
use App\Models\Debugging;
use \stdClass;

use Carbon\Carbon;


class NotasActualesAlumnosController extends Controller {
	
	public $user;
	public $escalas_val;
	
	public function __construct()
	{
		$this->user = User::fromToken();
		try {
			$this->escalas_val = DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$this->user->year_id]);
		} catch (\Throwable $th) {
			return 'Error';
		}
	}
	
    /*
	public function putGroup($grupo_id)
	{
		$periodo_a_calcular = Request::input('periodo_a_calcular', 4);
		$boletines = $this->detailedNotasGrupo($grupo_id, $this->user, '', $periodo_a_calcular);

		return $boletines;
    }
    */
    
	public function putIndex($grupo_id)
	{
		$periodo_a_calcular 	= Request::input('periodo_a_calcular', 4);
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
		
		$grupo->cantidad_alumnos = count($alumnos);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {
            
            
            foreach ($requested_alumnos as $req_alumno) {
                
                if ($req_alumno['alumno_id'] == $alumno->alumno_id) {
                    
                    $alumno->periodos = Periodo::hastaPeriodoN($user->year_id, $periodo_a_calcular);
                    
                    for ($i=0; $i < count($alumno->periodos); $i++) { 
                        
                        $alumno->periodos[$i]->alumno_id    = $alumno->alumno_id;
                        $alumno->periodos[$i]->sexo         = $alumno->sexo;
                        
                        // Todas las materias con sus unidades y subunides
                        $this->allNotasAlumno($alumno->periodos[$i], $grupo_id, true);

                        //$alumno->userData = Alumno::userData($alumno->alumno_id);
                        
                        $this->asignaturasPerdidasDeAlumno($alumno->periodos[$i], $grupo_id);
                        
                    }
                    
                    array_push($response_alumnos, $alumno);
                }
            }
            
		}


		return array($grupo, $year, $response_alumnos);
	}

	public function allNotasAlumno(&$alumno, $grupo_id, $comport_and_frases=false)
	{
        $periodo_id             = $alumno->periodo_id;
		$asignaturas			= Grupo::detailed_materias_notafinal($alumno->alumno_id, $grupo_id, $periodo_id, $alumno->year_id);
		$ausencias_total		= Ausencia::totalDeAlumno($alumno->alumno_id, $periodo_id);
		$asignaturas_perdidas 	= [];
	
		$sumatoria_asignaturas = 0;
		$alumno->ausencias_total = $ausencias_total;

		foreach ($asignaturas as $asignatura) {
			$asignatura->unidades = Unidad::deAsignaturaCalculada($alumno->alumno_id, $asignatura->asignatura_id, $periodo_id);

			foreach ($asignatura->unidades as $unidad) {
				$unidad->subunidades = Subunidad::deUnidadCalculada($alumno->alumno_id, $unidad->unidad_id, $this->user->year_id);
			}
			
			if ($comport_and_frases) {
				$asignatura->ausencias	= Ausencia::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $periodo_id);
				$asignatura->frases		= FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $periodo_id);
			}
			

			$sumatoria_asignaturas += $asignatura->nota_asignatura; // Para sacar promedio del periodo


			// SUMAR AUSENCIAS Y TARDANZAS
			if ($comport_and_frases) {
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
		}

		$alumno->asignaturas = $asignaturas;


		if (count($alumno->asignaturas) == 0) {
			$alumno->promedio = 0;
		} else {
			$alumno->promedio = $sumatoria_asignaturas / count($alumno->asignaturas);
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
		

		return $alumno;
	}


	public function asignaturasPerdidasDeAlumno(&$alumno, $grupo_id)
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
			$periodos = $calcPerdidas->hastaPeriodoConDefinitivas($alumno->alumno_id, $asignatura->asignatura_id, $grupo_id, $alumno->numero);
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