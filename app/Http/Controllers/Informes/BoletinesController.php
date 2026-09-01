<?php namespace App\Http\Controllers\Informes;

use App\Services\DefinitivasDeAsignatura;
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
use App\Services\BoletinIndependiente;
use \Log;
use Carbon\Carbon;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class BoletinesController extends Controller {
	use ResuelveElUsuario;

	/**
	 * Estas dos las llenaba el constructor. Las llena ahora la primera lectura.
	 *
	 * Un constructor que consulta la base obliga a resolver al usuario antes de
	 * saber si la petición lo necesita, y eso es lo que rompía `route:list`. Ver
	 * App\Http\Controllers\Concerns\ResuelveElUsuario.
	 *
	 * La consulta iba dentro de un try/catch que se tragaba cualquier error y
	 * dejaba la propiedad en null. El boletín salía entonces con los desempeños
	 * en blanco en vez de fallar, que es peor: un informe mudo se imprime y se
	 * entrega. Ahora el error sube.
	 */
	private $escalas_val;
	private $year;

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

	private function year()
	{
		if ($this->year === null) {
			$this->year = Year::datos($this->user->year_id);
		}

		return $this->year;
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


	/**
	 * El boletín de un grupo, y —cuando se pide uno solo— el recálculo de sus
	 * definitivas.
	 *
	 * **Aquí estaba la causa principal de que las definitivas desaparecieran**, con
	 * el comentario del propio autor al lado: `// CALCULAMOS SIN VERIFICAR QUE ESTÉ
	 * DESACTUALIZADO`. El bloque borraba TODAS las definitivas automáticas del
	 * alumno en ese grupo y periodo, y el INSERT de detrás sólo reponía las
	 * asignaturas **en las que el alumno tuviera alguna nota viva**. Toda asignatura
	 * sin notas registradas perdía su definitiva y no volvía.
	 *
	 * Con tres agravantes, y ninguno es teórico:
	 *
	 * - Usaba **el periodo del usuario que mira**, no el del boletín. Estar en el
	 *   periodo 2, pasarse al 1 y abrir un boletín reescribía las definitivas del
	 *   periodo 1. Ése fue el síntoma que se reportó.
	 * - La ruta es `boletin.propio`, así que no lo disparaba sólo el coordinador:
	 *   **el propio alumno o su acudiente, al abrir su boletín**.
	 * - Sin transacción. Una petición que muriera entre el DELETE y el INSERT las
	 *   dejaba borradas.
	 *
	 * Ahora **no se borra nada**: se pregunta si la definitiva está desactualizada y,
	 * si lo está, se recalcula. Los dos lados los pone `DefinitivasDeAsignatura`,
	 * que parte de las matrículas y no de las notas —así que el alumno sin notas
	 * recibe su fila en vez de perderla— y cuyo sello mira también la estructura y
	 * los borrados, que es lo que el `MAX(notas.updated_at)` de antes no veía.
	 *
	 * El recálculo se acota **a este alumno** con `soloAlumno`. Recalcular el grupo
	 * entero sería igual de correcto, pero convertiría «un acudiente abre el boletín
	 * de su hijo» en «un acudiente reescribe las definitivas de treinta alumnos», y
	 * eso no es lo que nadie espera de abrir un boletín.
	 *
	 * El periodo sigue siendo el del usuario, y ya no importa: **recalcular no
	 * destruye**. Dejarlo como estaba es lo que mantiene la intención original
	 * —«imprimimos y descubrimos que faltaba calcular»— sin el daño.
	 *
	 * Ver docs/migracion/10-definitivas.md §1.1 y su fase 3.
	 */
	public function putDetailedNotas($grupo_id)
	{
		$periodo_a_calcular 	= Request::input('periodo_a_calcular', 10);
		$requested_alumnos 		= Request::input('requested_alumnos', '');

		if (is_array($requested_alumnos) && count($requested_alumnos) == 1) {
			$this->ponerAlDiaLasDefinitivas($grupo_id, (int) $requested_alumnos[0]['alumno_id']);
		}

		$boletines = $this->detailedNotasGrupo($grupo_id, $this->user, $requested_alumnos, $periodo_a_calcular);
		return $boletines;
	}

	/**
	 * Recalcula las definitivas de un alumno en las asignaturas de su grupo que lo
	 * necesiten.
	 *
	 * Recorre las asignaturas y pregunta una por una, en vez de recalcular a lo
	 * bruto: la comprobación es una consulta agregada barata y el recálculo escribe,
	 * así que preguntar sale a cuenta en la pantalla que más veces se abre sin que
	 * nada haya cambiado.
	 */
	private function ponerAlDiaLasDefinitivas(int $grupo_id, int $alumno_id): void
	{
		$asignaturas = DB::select(
			'SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL',
			[$grupo_id]
		);

		foreach ($asignaturas as $asignatura) {
			if (! DefinitivasDeAsignatura::estaDesactualizada(
				(int) $asignatura->id, (int) $this->user->periodo_id, $alumno_id
			)) {
				continue;
			}

			DefinitivasDeAsignatura::recalcular(
				(int) $asignatura->id,
				(int) $this->user->periodo_id,
				(int) $this->user->user_id,
				$alumno_id
			);
		}
	}

	public function detailedNotasGrupo($grupo_id, &$user, $requested_alumnos='', $periodo_a_calcular=10)
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
			$this->allNotasAlumno($alumno, $grupo_id, $user->periodo_id, true);
			
			$this->asignaturasPerdidasDeAlumno($alumno, $grupo_id, $user->year_id, $periodo_a_calcular);
			
			if (isset($this->user->year_pasado_en_bol)) {
				if ($this->user->year_pasado_en_bol){
					if (!$alumno->nuevo && !$alumno->repitente) {
						$this->datosYearPasado($alumno, $grupo_id, $user->year_id);
					}
				}
			}
		}

		/*
		 * EL PUESTO LO DECIDE EL SERVICIO, no este `foreach` — fase 6 del
		 * [19](../../../../docs/migracion/19-boletin-independiente.md), §7.
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
		$escalas_val 				= DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$user->year_id]);

		return array($grupo, $year, $response_alumnos, $escalas_val);
	}


	public function allNotasAlumno(&$alumno, $grupo_id, $periodo_id, $comport_and_frases=false)
	{
		$asignaturas			= Grupo::detailed_materias_notafinal($alumno->alumno_id, $grupo_id, $periodo_id, $this->user->year_id);
		$ausencias_total		= Ausencia::totalDeAlumno($alumno->alumno_id, $periodo_id);
		$asignaturas_perdidas 	= [];
	
		$sumatoria_asignaturas 		= 0;
		$alumno->ausencias_total 	= $ausencias_total;
		$cant 						= count($asignaturas);

		for ($i=0; $i<$cant; $i++) {

			// NOTAS FINALES
			$asignaturas[$i]->notas_finales 		= DB::select('SELECT periodo, CAST(nota AS DOUBLE) AS nota, manual, recuperada FROM notas_finales WHERE alumno_id=? and asignatura_id=? and periodo<=? order by periodo asc', [$alumno->alumno_id, $asignaturas[$i]->asignatura_id, $this->user->numero_periodo]);
			$asignaturas[$i]->nota_faltante 		= 0;
			$asignaturas[$i]->nota_definitiva_anio 	= 0;

			$cant_n_o = count($asignaturas[$i]->notas_finales);
			$cant_n = ($cant_n_o>3) ? 3 : $cant_n_o ;

			for ($h=0; $h < $cant_n; $h++) { 
				$asignaturas[$i]->nota_faltante = $asignaturas[$i]->notas_finales[$h]->nota + $asignaturas[$i]->nota_faltante;
			}

			for ($h=0; $h < $cant_n_o; $h++) { 
				$des = EscalaDeValoracion::valoracion($asignaturas[$i]->notas_finales[$h]->nota, $this->escalasVal());
				if ($des) {
					$asignaturas[$i]->notas_finales[$h]->desempenio = $des->desempenio;
				} 
			}
			
			if ($cant_n_o > 3) {
				$asignaturas[$i]->nota_definitiva_anio 	= round(($asignaturas[$i]->nota_faltante + $asignaturas[$i]->notas_finales[$cant_n_o-1]->nota) / $this->user->numero_periodo);
			} else {
				$asignaturas[$i]->nota_definitiva_anio 	= round($asignaturas[$i]->nota_faltante / $this->user->numero_periodo);
			}

			$des = EscalaDeValoracion::valoracion($asignaturas[$i]->nota_definitiva_anio, $this->escalasVal());
			if ($des) {
				$asignaturas[$i]->nota_definitiva_anio_desempenio = $des->desempenio;
			}

			$asignaturas[$i]->nota_faltante 		    = $this->user->nota_minima_aceptada * 4 - $asignaturas[$i]->nota_faltante;


			// UNIDADES
			$asignaturas[$i]->unidades = Unidad::deAsignaturaCalculada($alumno->alumno_id, $asignaturas[$i]->asignatura_id, $periodo_id);

			foreach ($asignaturas[$i]->unidades as $unidad) {
				$unidad->subunidades = Subunidad::deUnidadCalculada($alumno->alumno_id, $unidad->unidad_id, $this->user->year_id);
			}
			
			if ($comport_and_frases) {
				$asignaturas[$i]->ausencias		= Ausencia::deAlumno($asignaturas[$i]->asignatura_id, $alumno->alumno_id, $periodo_id);
				$asignaturas[$i]->frases		= FraseAsignatura::deAlumno($asignaturas[$i]->asignatura_id, $alumno->alumno_id, $periodo_id);
			}

			$sumatoria_asignaturas += $asignaturas[$i]->nota_asignatura; // Para sacar promedio del periodo

			// SUMAR AUSENCIAS Y TARDANZAS
			if ($comport_and_frases) {
				$cantAus = 0;
				$cantTar = 0;
				foreach ($asignaturas[$i]->ausencias as $ausencia) {
					if ($ausencia->tipo == "tardanza") {
						$cantTar += (int)$ausencia->cantidad_tardanza;
					}elseif ($ausencia->tipo == "ausencia") {
						$cantAus += (int)$ausencia->cantidad_ausencia;
					}
				}

				$asignaturas[$i]->total_ausencias = $cantAus;
				$asignaturas[$i]->total_tardanzas = $cantTar;
			}
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
			
			$alumno->encabezado_comportamiento = $this->encabezado_comportamiento_boletin(
				$alumno->comportamiento, $this->user->nota_minima_aceptada, $this->user->mostrar_nota_comport_boletin, $alumno->sexo,
			);
			
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

			if (count($periodos)>0) {
				
				if ($this->user->si_recupera_materia_recup_indicador) {
					if (number_format($periodos[0]->definitiva_year) < $this->user->nota_minima_aceptada && $periodos[0]->cant_perdidas_year > 0) {
						$asignatura->detalle_periodos = $periodos[0];
						$des = EscalaDeValoracion::valoracion($asignatura->detalle_periodos->definitiva_year, $this->escalasVal());

						if ($des) {
							$asignatura->detalle_periodos->definitiva_year_desempenio = $des->desempenio;
						} 

						$alumno->notas_perdidas_year += $periodos[0]->cant_perdidas_year;
						$alumno->notas_perdidas_per1 += $periodos[0]->cant_perdidas_1;
						if(isset($periodos[0]->cant_perdidas_2)) $alumno->notas_perdidas_per2 += $periodos[0]->cant_perdidas_2;
						if(isset($periodos[0]->cant_perdidas_3)) $alumno->notas_perdidas_per3 += $periodos[0]->cant_perdidas_3;
						if(isset($periodos[0]->cant_perdidas_4)) $alumno->notas_perdidas_per4 += $periodos[0]->cant_perdidas_4;

						array_push($alumno->asignaturas_perdidas, $asignatura);
					}
				} else {
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
						INNER JOIN matriculas m ON m.grupo_id=g.id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null and m.alumno_id=?
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
						if (number_format($periodos[0]->definitiva_year) < $year_ant->nota_minima_aceptada && $periodos[0]->cant_perdidas_year > 0) {
							$asignatura->detalle_periodos = $periodos[0];
							$des = EscalaDeValoracion::valoracion($asignatura->detalle_periodos->definitiva_year, $this->escalasVal());
				
							if ($des) {
								$asignatura->detalle_periodos->definitiva_year_desempenio = $des->desempenio;
							} 
							
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
	
	
	private function encabezado_comportamiento_boletin($nota, $nota_minima_aceptada, $mostrar_nota_comport, $sexo){
		$icono 		= '';

		if ($sexo == 'F') {
			$icono = 'fa-male';
		} else {
			$icono = 'fa-female';
		}

		// §141. `is_object` y no `if ($nota)`: cuando el alumno no tiene nota del
		// periodo, `NotaComportamiento::nota_comportamiento()` devuelve
		// `["notas_finales" => []]` — un ARRAY, y un array no vacío es truthy.
		// El `if` pasaba, y `$nota->nota` de tres líneas más abajo reventaba con
		// «Attempt to read property "nota" on array»: 500 en el grupo entero por
		// un alumno al que le falta la nota.
		//
		// **El centinela no se toca**, y no por prudencia: ese `["notas_finales"
		// => []]` está MOLDEADO para las plantillas. `alumno.comportamiento.
		// notas_finales` lo recorre con `ng-repeat` en cuatro boletines de
		// `myvc_front` (boletinAlumnoDir, Dir2, Dir3 y Dir5), así que devolver
		// `null` desde el modelo se lo quitaría a los cuatro. Se para en el
		// llamante, que es lo mismo que se decidió para `Profesor::detallado()`.
		//
		// Está copiada en CINCO controladores de `Informes/` y ninguna de las
		// cinco distinguía el array. Se arreglan las cinco a la vez: arreglar
		// sólo la que se está mirando es lo que ha costado tres series esta noche.
		if (is_object($nota)) {
			$clase 		= '';
			$la_nota 	= '';
			$escala = '';
			
			if ( $mostrar_nota_comport ) {
				try {
					$la_nota = $nota->nota;

					if (EscalaDeValoracion::valoracion($la_nota, $this->escalasVal())) {
						$escala = EscalaDeValoracion::valoracion($la_nota, $this->escalasVal())->desempenio;
					}
				} catch (\Throwable $th) {}

				if ($la_nota < $nota_minima_aceptada) {
					$clase = ' nota-perdida-bold ';
				}

				if ($this->year()->solo_escalas_valorativas) {
					$la_nota = '';
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
