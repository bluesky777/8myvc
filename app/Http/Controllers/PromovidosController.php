<?php namespace App\Http\Controllers;



use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\User;
use App\Models\Year;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Models\Profesor;
use App\Models\Nota;
use App\Models\ConfigCertificado;
use App\Models\EscalaDeValoracion;
use App\Models\Debugging;
use App\Models\NotaComportamiento;
use App\Models\Area;
use App\Services\BoletinIndependiente;
use \Log;

use Carbon\Carbon;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class PromovidosController extends Controller {
	use ResuelveElUsuario;

	private $escalas_val = [];


	public function putCalcularGrupo()
	{
		$year_id 		= $this->user->year_id;
		$year_actual 	= true;
		$periodo_a_calcular = 4;
		$grupo_id 		= Request::input('grupo_id');
		
		
		$year			= Year::datos($year_id);
		$alumnos		= Grupo::alumnos($grupo_id);
		
		$this->escalas_val = DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$this->user->year_id]);

		$year->periodos = DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [$this->user->year_id]);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades y subunides
			$this->definitivasMateriasXPeriodo($alumno, $grupo_id, $this->user->year_id, $year->periodos, $this->user->si_recupera_materia_recup_indicador );

			
			
			$consulta = 'SELECT r.*, m.materia, m.alias, m.area_id FROM recuperacion_final r 
				INNER JOIN asignaturas a ON a.id=r.asignatura_id and a.deleted_at is null
				INNER JOIN materias m ON m.id=a.materia_id and m.deleted_at is null
				WHERE alumno_id=? and year=?';
				
			$alumno->recuperaciones = DB::select($consulta, [$alumno->alumno_id, $this->user->year]);

			$canti_recu = count($alumno->recuperaciones);
			for ($k=0; $k < $canti_recu; $k++) { 
				$recu = $alumno->recuperaciones[$k];
				
				$consulta = 'SELECT ar.* FROM areas ar 
					INNER JOIN materias m ON m.area_id=ar.id and m.deleted_at is null
					INNER JOIN asignaturas a ON a.materia_id=m.id and a.deleted_at is null
					WHERE ar.id=? and ar.deleted_at is null';
					
				$canti_asignaturas_en_area = count(DB::select($consulta, [$recu->area_id]));
				
				if ($canti_asignaturas_en_area > 0) {
					$recu->es_area = true;
	
					$alumno->cant_lost_areas = $alumno->cant_lost_areas - 1;
				}
			}

			
			$alumno->cant_lost_asig = $alumno->cant_lost_asig - count($alumno->recuperaciones);

	


			//****************************
			// Guardamos todos los calculos
			
			$diagnostico = "Automático";

			if ($year->cant_areas_pierde_year > 0 && $alumno->cant_lost_areas > 0  && $alumno->cant_lost_areas < $year->cant_areas_pierde_year) {
				$diagnostico = "Promoción pendiente (calculado)";
			}
			if ($year->cant_areas_pierde_year > 0 && $alumno->cant_lost_areas == 0) {
				$diagnostico = "Promovido (calculado)";
			}
			if ($year->cant_areas_pierde_year > 0 && $alumno->cant_lost_areas >= $year->cant_areas_pierde_year) {
				$diagnostico = "No promovido (calculado)";
			}

			if ($alumno->cant_lost_asig > 0 && $alumno->cant_lost_asig < $year->cant_asignatura_pierde_year && $year->cant_asignatura_pierde_year > 0) {
				$diagnostico = "Promoción pendiente (calculado)";
			}
			if ($year->cant_asignatura_pierde_year > 0 && ($alumno->cant_lost_asig == 0 || $alumno->cant_lost_asig >= $year->cant_asignatura_pierde_year)) {
				$diagnostico = "Promovido (calculado)";
			}
			if ($alumno->cant_lost_asig >= $year->cant_asignatura_pierde_year && $year->cant_asignatura_pierde_year>0) {
				$diagnostico = "No promovido (calculado)";
			}

			$alumno->promovido = $diagnostico;

			$consulta = "UPDATE matriculas 
				SET promovido=:promovido, promedio=:promedio, cant_asign_perdidas=:cant_asign_perdidas, cant_areas_perdidas=:cant_areas_perdidas
				WHERE id=:matricula_id AND promovido NOT LIKE '%(manual)%'";

			$res = DB::update($consulta, [
				':promovido' => $diagnostico,
				':promedio' => $alumno->promedio,
				':cant_asign_perdidas' => $alumno->cant_lost_asig,
				':cant_areas_perdidas' => $alumno->cant_lost_areas,
				':matricula_id' => $alumno->matricula_id,
			]);
			
			
		}


		/*
		 * EL PUESTO LO DECIDE EL SERVICIO, no este `foreach` — fase 6 del
		 * [19](../../../docs/migracion/19-boletin-independiente.md), §7.
		 *
		 * Aquí había `Nota::puestoAlumno($alumno->promedio, $alumnos)` dentro del bucle, y
		 * ese mismo cálculo estaba **copiado en ocho sitios**. `puestoAlumno` sigue
		 * intacta y sigue siendo pura: lo que cambia es **quién entra en la lista contra
		 * la que se cuenta**, y eso lo decide `years.puestos_con_bol_independiente`.
		 *
		 * Con el interruptor en 1 —el default— esto es exactamente lo de antes. Con 0, el
		 * alumno con boletín independiente sale del recuento: su puesto viaja `null`
		 * (decisión 6) y **a los demás les cambia el suyo**.
		 *
		 * **Y aquí se le pasan VARIOS periodos, que es la diferencia con los tres
		 * boletines de periodo.** Este promedio se calcula sobre las definitivas de todos
		 * los periodos que `$year->periodos` trae, así que basta con que el alumno haya
		 * ido aparte en **uno** de ellos para que su promedio no se haya calculado sobre
		 * el reparto del grupo. Preguntar sólo por el periodo del token dejaría dentro del
		 * recuento a quien tuvo el accidente en el segundo y hoy va con el grupo — y
		 * preguntar por el año entero sacaría a quien lo tuvo en un periodo que este
		 * informe no está promediando. Los periodos que se promedian son los que deciden.
		 */
		BoletinIndependiente::ponerPuestos(
			$alumnos,
			array_map(fn ($periodo) => (int) $periodo->id, $year->periodos),
			(int) $this->user->year_id
		);


		return $alumnos;
		
	}



	

	public function definitivasMateriasXPeriodo(&$alumno, $grupo_id, $year_id, $periodos, $si_recupera_materia_recup_indicador=false)
	{

		$alumno->asignaturas	= Grupo::detailed_materias($grupo_id);

		$alumno->promedio = 0;
		$alumno->cant_lost_asig = 0;
		$alumno->total_creditos = 0;
		$alumno->notas_perdidas = 0;
		
		
		
		
		foreach ($alumno->asignaturas as $asignatura) {

			$alumno->total_creditos += $asignatura->creditos;
						
			// **Las once columnas nombradas y no `nf.*`**, desde el 2 sep 2026: esta
			// fila viaja entera al cliente, así que con el asterisco las tres columnas
			// de la nivelación de la definitiva (`2026_09_02_100000_nivelaciones_columnas`)
			// habrían aparecido solas en el cálculo de promovidos **sin que nadie
			// tocara este método**. Lo cazó `GruposTest::la_forma_del_calculo_de_promovidos`.
			// Qué respuestas llevan las columnas nuevas a propósito y cuáles están
			// congeladas está en la §3.4 de docs/migracion/22-nivelaciones.md; ésta
			// está congelada.
			$consulta = 'SELECT nf.id, nf.alumno_id, nf.asignatura_id, nf.periodo_id, nf.periodo, nf.recuperada,
							nf.manual, nf.updated_by, nf.created_at, nf.updated_at,
							CAST(nf.nota AS DOUBLE) AS nota, CAST(nf.nota AS DOUBLE) as DefMateria, aus.cantidad_ausencia, tar.cantidad_tardanza
						FROM notas_finales nf
						INNER JOIN periodos p on p.year_id=:year_id and p.id=nf.periodo_id and p.deleted_at is null
						left join (
								select count(au.id) as cantidad_ausencia, au.alumno_id, au.periodo_id, au.asignatura_id
								from ausencias au 
								where au.deleted_at is null and au.cantidad_ausencia > 0
								group by au.alumno_id, au.periodo_id, au.asignatura_id
								
								)as aus on aus.alumno_id=nf.alumno_id and aus.asignatura_id=nf.asignatura_id and aus.periodo_id=nf.periodo_id
						left join (
								select count(au.id) as cantidad_tardanza, au.alumno_id, au.periodo_id, au.asignatura_id
								from ausencias au 
								where au.deleted_at is null and au.cantidad_tardanza > 0
								group by au.alumno_id, au.periodo_id, au.asignatura_id
									
						)as tar on tar.alumno_id=nf.alumno_id and tar.asignatura_id=nf.asignatura_id and tar.periodo_id=nf.periodo_id
						WHERE nf.alumno_id=:alumno_id and nf.asignatura_id=:asignatura_id
						ORDER BY nf.periodo';
					
			
			$paramentros = [
				':year_id'		=> $year_id,
				':alumno_id'	=> $alumno->alumno_id, 
				':asignatura_id'=> $asignatura->asignatura_id
			];
				
			
			$asignatura->definitivas = DB::select($consulta, $paramentros);


			$suma_def = 0;
			$notas_perd = 0;
			
			foreach ($asignatura->definitivas as $keydef => $definitiva) {
				
				$suma_def += (float)$definitiva->DefMateria;
				
			}
			if(count($asignatura->definitivas)){
				$asignatura->promedio 			= $suma_def / count($asignatura->definitivas);
				$asignatura->nota_asignatura 	= $asignatura->promedio;

			}else{
				$asignatura->promedio 			= 0;
				$asignatura->nota_asignatura 	= 0;

			}
			

			$escala = $this->valoracion($asignatura->promedio);

			if ($escala) {
				$asignatura->desempenio 	= $escala->desempenio;
				$asignatura->perdido 		= $escala->perdido;
				$asignatura->valoracion 	= $escala->valoracion;
			}
			

			$alumno->promedio += $asignatura->promedio;
			//$alumno->notas_perdidas += $asignatura->notas_perdidas;



			// Si es un promedio perdido, debo sumarlo como una asignatura perdida
			if (round($asignatura->promedio) < User::$nota_minima_aceptada) {
				$alumno->cant_lost_asig += 1;
			}

		}
		
		if (count($alumno->asignaturas) > 0) {
			$alumno->promedio = $alumno->promedio / count($alumno->asignaturas);
		}else{
			$alumno->promedio = 0;
		}
		

		
		// Agrupamos por áreas
		$areas = Area::agrupar_asignaturas($grupo_id, $alumno->asignaturas, $this->escalas_val);		
		$cant_lost_areas = 0;
		
		for ($k=0; $k < count($areas); $k++) { 
			if ($areas[$k]->area_nota < User::$nota_minima_aceptada){
				$cant_lost_areas = $cant_lost_areas + 1;
			}
		}
		
		$alumno->areas 				= $areas;
		$alumno->cant_lost_areas 	= $cant_lost_areas;


		
		return $alumno;
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



	


	public function valoracion($nota)
	{
		$nota = round($nota);

		foreach ($this->escalas_val as $key => $escala_val) {
			//Debugging::pin($escala_val->porc_inicial, $escala_val->porc_final, $nota);

			if (($escala_val->porc_inicial <= $nota) &&  ($escala_val->porc_final >= $nota)) {
				return $escala_val;
			}
		}
		return [];
	}





}