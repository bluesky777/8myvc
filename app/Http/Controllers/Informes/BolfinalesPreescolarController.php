<?php namespace App\Http\Controllers\Informes;


use App\Http\Controllers\Controller;


use Request;
use DB;

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
use \Log;


class BolfinalesPreescolarController extends Controller {


	private $escalas_val = [];
	

	public function putDetailedNotasYearGroup($grupo_id)
	{
		$user = User::fromToken();

		$boletines = $this->detailedNotasGrupo($grupo_id, $user);

		//$grupo->alumnos = $alumnos;
		//$grupo->asignaturas = $asignaturas;
		//return (array)$grupo;

		return $boletines;


	}



	public function putDetailedNotasYear($grupo_id)
	{
		$user = User::fromToken();

		
		$requested_alumnos = '';

		if (Request::has('requested_alumnos')) {
			$requested_alumnos = Request::get('requested_alumnos');
		}

		$boletines = $this->detailedNotasGrupo($grupo_id, $user, $requested_alumnos);


		return $boletines;


	}

	public function detailedNotasGrupo($grupo_id, $user, $requested_alumnos='')
	{
		
		$this->escalas_val = DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$user->year_id]);

		$year_actual = true;
		if (Request::has('year_selected')) {
			if (Request::input('year_selected') == true || Request::input('year_selected') == 'true') {
				$year_actual = false;
			}
		}
		
		
		
		
		
		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id, $year_actual);
		$alumnos		= Grupo::alumnos($grupo_id, $requested_alumnos);
		
		
		$year_notas		= Year::datos($user->year_id);
		$year->minu_hora_clase 				= $year_notas->minu_hora_clase;
		
		

		$cons = 'SELECT c.*, i.nombre as encabezado_nombre, i2.nombre as piepagina_nombre 
				FROM config_certificados c 
				left join images i on i.id=c.encabezado_img_id and i.deleted_at is null
				left join images i2 on i2.id=c.piepagina_img_id and i2.deleted_at is null
					where c.id=?';
		$config_certificado = DB::select($cons, [$year->config_certificado_estudio_id]);
		if (count($config_certificado) > 0) {
			$year->config_certificado = $config_certificado[0];
		}


		// Creo que puedo borrarlo
		$cons = 'SELECT n.nombre as nivel_educativo FROM niveles_educativos n
				inner join grados gra on gra.nivel_educativo_id=n.id and gra.deleted_at is null
				inner join grupos gru on gru.grado_id=gra.id and gru.id=? and gru.deleted_at is null
				where n.deleted_at is null';

		$niveles = DB::select($cons, [$grupo_id]);
		if (count($niveles) > 0) {
			$grupo->nivel_educativo = $niveles[0]->nivel_educativo;
		}



		


		$grupo->cantidad_alumnos = count($alumnos);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades y subunides
			$this->definitivasMateriasXPeriodo($alumno, $grupo_id, $user->year_id, $user);
			
		}


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

	public function definitivasMateriasXPeriodo(&$alumno, $grupo_id, $year_id, $user)
	{

		$alumno->asignaturas	= Grupo::detailed_materias($grupo_id);

		$alumno->ausencias = 0;
		$alumno->tardanzas = 0;
		$alumno->total_creditos = 0;
		
		

		foreach ($alumno->asignaturas as $asignatura) {

			$alumno->total_creditos += $asignatura->creditos;
						
			$consulta_aus = 'SELECT count(a.id) as cantidad_ausencia FROM ausencias a
				WHERE a.alumno_id=:alumno_id and a.asignatura_id=:asignatura_id and a.cantidad_ausencia > 0';
			
			$consulta_tar = 'SELECT count(a.id) as cantidad_tardanzas FROM ausencias a
				WHERE a.alumno_id=:alumno_id and a.asignatura_id=:asignatura_id and a.cantidad_tardanza > 0';
					

			$paramentros = [
				':alumno_id'	=> $alumno->alumno_id, 
				':asignatura_id'=> $asignatura->asignatura_id
			];
				
			
			$asignatura->ausencias = DB::select($consulta_aus, $paramentros)[0];
			$asignatura->tardanzas = DB::select($consulta_tar, $paramentros)[0];
			
			
			$consulta = 'SELECT * FROM frases_preescolar WHERE asignatura_id=?';
			$asignatura->frases = DB::select($consulta, [$asignatura->asignatura_id]);

		}

		// Nota promedio de comportamiento
		$alumno->nota_comportamiento_year 	= NotaComportamiento::nota_promedio_year($alumno->alumno_id, $year_id);
		
		$escala = $this->valoracion($alumno->nota_comportamiento_year);
		if ($escala) {
			$alumno->nota_comportamiento_year_desempenio = $escala->desempenio;
		}
		
		$alumno->encabezado_comportamiento = $this->encabezado_comportamiento_boletin($alumno->nota_comportamiento_year, $user->nota_minima_aceptada, $user->mostrar_nota_comport_boletin, $alumno->sexo);
		
		
		return $alumno;
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
				$la_nota = $nota;
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
	
	
	
	public function putCrearFrase(){
		
		$user = User::fromToken();
		
		DB::insert('INSERT INTO frases_preescolar(asignatura_id, definicion) VALUES(?,?);', [ Request::input('asignatura_id'), '' ]);
		
		$last_id = DB::getPdo()->lastInsertId();
		$res = DB::select('SELECT * FROM frases_preescolar WHERE id=?;', [ $last_id ])[0];
		
		return (array)$res;
	}
	
	
	
	public function putGuardarFrase(){
		
		$user = User::fromToken();
		
		$asignatura_id 	= Request::input('asignatura_id');
		$definicion 	= Request::input('definicion');
		$id 			= Request::input('id');
		
		DB::update('UPDATE frases_preescolar SET asignatura_id=?, definicion=? WHERE id=?;', [ $asignatura_id, $definicion, $id ]);
		
		return 'Cambiada';
	}
	
	
	public function putEliminarFrase(){
		
		$user = User::fromToken();
		
		$id 			= Request::input('id');
		
		DB::delete('DELETE FROM frases_preescolar WHERE id=?;', [ $id ]);
		
		return 'ELIMINADA';
	}
	
	
	
	
	
	




}