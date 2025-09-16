<?php namespace App\Http\Controllers;

use DB;
use Request;

use App\User;
use App\Models\Year;
use App\Models\Periodo;
use App\Models\ConfigCertificado;
use App\Models\ImageModel;
use App\Models\Grupo;
use App\Models\Asignatura;
use App\Models\EscalaDeValoracion;
use App\Models\Frase;
use App\Models\Unidad;
use Carbon\Carbon;


class YearsController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();

		$consulta 	= 'SELECT y.*, i.nombre as logo FROM years y left join images i ON i.id=y.logo_id and i.deleted_at is null WHERE y.deleted_at is null';
		$years 		= DB::select($consulta);

		foreach ($years as $year) {
			$consulta 			= 'SELECT * FROM periodos WHERE year_id=? and deleted_at is null';
			$year->periodos 	= DB::select($consulta, [$year->id]);
		}

		return $years;
	}


	public function getColegio()
	{
		$user = User::fromToken();

		$consulta = 'SELECT * FROM years WHERE deleted_at is null';
		$years    = DB::select($consulta);


		foreach ($years as $year) {
			$consulta       = 'SELECT * FROM periodos WHERE year_id=? and deleted_at is null';
			$year->periodos = DB::select($consulta, [$year->id]);

			$consulta      = 'SELECT * FROM escalas_de_valoracion WHERE year_id=? and deleted_at is null order by orden asc';
			$year->escalas = DB::select($consulta, [$year->id]);
		}

		$consulta = 'SELECT * FROM config_certificados';
		$certif   = DB::select($consulta);

		$consulta = 'SELECT * FROM images WHERE user_id=? and publica=true';
		$imagenes = DB::select($consulta, [$user->user_id]);



		$result = ['years' => $years, 'certificados' => $certif, 'imagenes' => $imagenes];

		return $result;
	}


	public function postStore()
	{
		$user = User::fromToken();

		$year = new Year;

		$year->year                   = Request::input('year');
		$year->nombre_colegio         = Request::input('nombre_colegio');
		$year->abrev_colegio          = Request::input('abrev_colegio');
		$year->nota_minima_aceptada   = Request::input('nota_minima_aceptada');
		$year->resolucion             = Request::input('resolucion');
		$year->codigo_dane            = Request::input('codigo_dane');
		$year->encabezado_certificado = Request::input('encabezado_certificado');

		$year->actual   = Request::input('actual');
		$year->telefono = Request::input('telefono');
		$year->celular  = Request::input('celular');
		
		$year->unidad_displayname      = Request::input('unidad_displayname');
		$year->unidades_displayname    = Request::input('unidades_displayname');
		$year->genero_unidad           = Request::input('genero_unidad');
		$year->subunidad_displayname   = Request::input('subunidad_displayname');
		$year->subunidades_displayname = Request::input('subunidades_displayname');
		$year->genero_subunidad        = Request::input('genero_subunidad');
		
		$year->website               = Request::input('website');
		$year->website_myvc          = Request::input('website_myvc');
		$year->alumnos_can_see_notas = Request::input('alumnos_can_see_notas');

		$year->save();

		$year_id_nuevo = $year->id;

		if ($year->actual) {
			Year::where('actual', true)->update(['actual'=>false]);
		}

		$year 				= Year::find($year_id_nuevo);
		$year->actual 		= true;
		$year->created_by 	= $user->user_id;
		$year->save();

		// Creamos un periodo
		DB::insert('INSERT INTO periodos(numero, actual, year_id) VALUES(1, 1, ?)', [$year->id]);

		// NECESITARÉ MUCHO DEL AÑO ANTERIOR
		$year_ante = $year->year - 1;
		$pasado = Year::where('year', $year_ante)->first();

		if ($pasado) {
			$year->ciudad_id                     = $pasado->ciudad_id;
			$year->logo_id                       = $pasado->logo_id;
			$year->rector_id                     = $pasado->rector_id;
			$year->secretario_id                 = $pasado->secretario_id;
			$year->tesorero_id                   = $pasado->tesorero_id;
			$year->coordinador_academico_id      = $pasado->coordinador_academico_id;
			$year->coordinador_disciplinario_id  = $pasado->coordinador_disciplinario_id;
			$year->capellan_id                   = $pasado->capellan_id;
			$year->psicorientador_id             = $pasado->psicorientador_id;
			$year->config_certificado_estudio_id = $pasado->config_certificado_estudio_id;
			$year->cant_areas_pierde_year        = $pasado->cant_areas_pierde_year;
			$year->cant_asignatura_pierde_year   = $pasado->cant_asignatura_pierde_year;
			$year->contador_certificados         = $pasado->contador_certificados;
			$year->contador_folios               = $pasado->contador_folios;
			$year->nota_minima_aceptada          = $pasado->nota_minima_aceptada;
			$year->resolucion                    = $pasado->resolucion;
			$year->codigo_dane                   = $pasado->codigo_dane;
			$year->encabezado_certificado        = $pasado->encabezado_certificado;
			$year->compromiso_familiar_label     = $pasado->compromiso_familiar_label;
			$year->mensaje_aprobo_con_pendientes = $pasado->mensaje_aprobo_con_pendientes;
			$year->minu_hora_clase     		 	 = $pasado->minu_hora_clase;
			$year->mostrar_nota_comport_boletin  = $pasado->mostrar_nota_comport_boletin;
			$year->mostrar_puesto_boletin  		 = $pasado->mostrar_puesto_boletin;
			$year->msg_when_students_blocked  	 = $pasado->msg_when_students_blocked;
			$year->profes_can_edit_alumnos  	 = $pasado->profes_can_edit_alumnos;
			$year->puestos_alfabeticamente  	 = $pasado->puestos_alfabeticamente;
			$year->show_fortaleza_bol  	 		 = $pasado->show_fortaleza_bol;
			$year->show_subasignaturas_en_finales = $pasado->show_subasignaturas_en_finales;
			$year->si_recupera_materia_recup_indicador = $pasado->si_recupera_materia_recup_indicador;
			$year->solo_escalas_valorativas 	 = $pasado->solo_escalas_valorativas;
			$year->year_pasado_en_bol 			 = $pasado->year_pasado_en_bol;
			$year->titulo_rector 				 = $pasado->titulo_rector;

			$year->save();
			
			/// COPIAREMOS LAS ESCALAS DE VALORACIÓN
			$escalas_ant = EscalaDeValoracion::where('year_id', $pasado->id)->get();

			foreach ($escalas_ant as $key => $escalas) {
				$newEsc                    = new EscalaDeValoracion;
				$newEsc->desempenio        = $escalas->desempenio;
				$newEsc->valoracion        = $escalas->valoracion;
				$newEsc->porc_inicial      = $escalas->porc_inicial;
				$newEsc->porc_final        = $escalas->porc_final;
				$newEsc->descripcion       = $escalas->descripcion;
				$newEsc->orden             = $escalas->orden;
				$newEsc->perdido           = $escalas->perdido;
				$newEsc->year_id           = $year->id;
				$newEsc->icono_infantil    = $escalas->icono_infantil;
				$newEsc->icono_adolescente = $escalas->icono_adolescente;
				$newEsc->save();
			}

			/// COPIAREMOS LAS FRASES
			$frases_ant = Frase::where('year_id', $pasado->id)->get();

			foreach ($frases_ant as $key => $frases) {
				$newFra = new Frase;
				$newFra->frase 			= $frases->frase;
				$newFra->tipo_frase 	= $frases->tipo_frase;
				$newFra->year_id 		= $year->id;
				$newFra->save();
			}

			/// COPIAREMOS LAS UNIDADES POR DEFECTO
			$unidades_ant = DB::select('SELECT * FROM unidades_por_defecto WHERE year_id=? AND deleted_at is null;', [$pasado->id]);

			foreach ($unidades_ant as $key => $unidad) {
				DB::insert('INSERT INTO unidades_por_defecto(definicion, porcentaje, year_id, obligatoria, orden, created_by) VALUES(?,?,?,?,?,?)', 
					[$unidad->definicion, $unidad->porcentaje, $year->id, $unidad->obligatoria, $unidad->orden, $unidad->created_by]);
			}

			/// COPIAREMOS LAS CONFIGURACIONES DE DISCIPLINA Y ORDINALES
			$dis_configuraciones = DB::select('SELECT * FROM dis_configuraciones WHERE year_id=? AND deleted_at is null;', [$pasado->id]);
			if (count($dis_configuraciones) > 0) {
				$dis = $dis_configuraciones[0];
				
				DB::insert('INSERT INTO dis_configuraciones(year_id, reinicia_por_periodo, falta_tipo1_displayname, faltas_tipo1_displayname, genero_falta_t1, falta_tipo2_displayname, faltas_tipo2_displayname, genero_falta_t2, 
					falta_tipo3_displayname, faltas_tipo3_displayname, genero_falta_t3, cant_tard_to_ft1, cant_ft1_to_ft2, cant_ft2_to_ft3,
					nombre_col1, nombre_col2, nombre_col3, definicion_ft1, definicion_ft2, definicion_ft3) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 
					[ $year->id, $dis->reinicia_por_periodo, $dis->falta_tipo1_displayname, $dis->faltas_tipo1_displayname, $dis->genero_falta_t1, $dis->falta_tipo2_displayname, $dis->faltas_tipo2_displayname, $dis->genero_falta_t2, 
					$dis->falta_tipo3_displayname, $dis->faltas_tipo3_displayname, $dis->genero_falta_t3, $dis->cant_tard_to_ft1, $dis->cant_ft1_to_ft2, $dis->cant_ft2_to_ft3, 
					$dis->nombre_col1, $dis->nombre_col2, $dis->nombre_col3, $dis->definicion_ft1, $dis->definicion_ft2, $dis->definicion_ft3 ]);
					
				$dis_ordinales = DB::select('SELECT * FROM dis_ordinales WHERE year_id=? AND deleted_at is null;', [$pasado->id]);
					
				foreach ($dis_ordinales as $key => $ord) {
					DB::insert('INSERT INTO dis_ordinales(year_id, tipo, ordinal, descripcion, pagina) VALUES(?,?,?,?,?)', 
						[ $year->id, $ord->tipo, $ord->ordinal, $ord->descripcion, $ord->pagina ]);
				}
			}
			
			/// AHORA COPIAMOS LOS GRUPOS Y ASIGNATURAS DEL AÑO PASADO AL NUEVO AÑO.
			$grupos_ant = Grupo::where('year_id', $pasado->id)->get();
			
			foreach ($grupos_ant as $key => $grupo) {
				$newGr = new Grupo;
				$newGr->nombre 			= $grupo->nombre;
				$newGr->abrev 			= $grupo->abrev;
				$newGr->year_id 		= $year->id;
				$newGr->grado_id 		= $grupo->grado_id;
				$newGr->valormatricula 	= $grupo->valormatricula;
				$newGr->valorpension 	= $grupo->valorpension;
				$newGr->orden 			= $grupo->orden;
				$newGr->cupo 			= $grupo->cupo;
				$newGr->caritas 		= $grupo->caritas;
				$newGr->save();

				$asigs_ant = Asignatura::where('grupo_id', $grupo->id)->get();
				
				for ($i=0; $i < count($asigs_ant); $i++) { 
					$newAsig = new Asignatura;
					$newAsig->materia_id 	= $asigs_ant[$i]->materia_id;
					$newAsig->grupo_id 		= $newGr->id;
					$newAsig->creditos 		= $asigs_ant[$i]->creditos;
					$newAsig->orden 		= $asigs_ant[$i]->orden;
					$newAsig->save();
				}
				$grupo->asigs_ant = $asigs_ant;
			}
			$year->grupos_ant = $grupos_ant;
		}
		return $year;
	}


	public function putUseractive($year_id)
	{
		$user = User::fromToken();
		$usuario = User::findOrFail($user->user_id);
		$peri = Periodo::where('year_id', $year_id)->where('numero', $user->numero_periodo)->first();

		if ($peri) {
			$usuario->periodo_id = $peri->id;
		}else{
			$peris = Periodo::where('year_id', $year_id)->get();

			if (count($peris) > 0) {
				$peri = $peris[count($peris)-1];
				$usuario->periodo_id = $peri->id;
			}else{
				abort(400, 'Año sin ningún periodo.');
			}
			
		}

		$usuario->save();

		return $peri;
	}





	public function putGuardarCambios()
	{
		$user = User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		$year = Year::findOrFail(Request::input('id'));
		
		try {
			$compromiso_familiar = null;

			if (Request::has('compromiso_familiar_label')) {
				if (Request::input('compromiso_familiar_label') != '' && Request::input('compromiso_familiar_label') != null) {
					$compromiso_familiar = Request::input('compromiso_familiar_label');
				}
			}

			$year->nombre_colegio            = Request::input('nombre_colegio');
			$year->abrev_colegio             = Request::input('abrev_colegio');
			$year->year                      = Request::input('year');
			$year->rector_id                 = Request::input('rector_id');
			$year->secretario_id             = Request::input('secretario_id');
			$year->tesorero_id               = Request::input('tesorero_id');
			$year->resolucion                = Request::input('resolucion');
			$year->codigo_dane               = Request::input('codigo_dane');
			$year->telefono                  = Request::input('telefono');
			$year->celular                   = Request::input('celular');
			$year->website                   = Request::input('website');
			$year->website_myvc              = Request::input('website_myvc');
			$year->msg_when_students_blocked = Request::input('msg_when_students_blocked');
			$year->unidad_displayname        = Request::input('unidad_displayname');
			$year->unidades_displayname      = Request::input('unidades_displayname');
			$year->genero_unidad             = Request::input('genero_unidad');
			$year->subunidad_displayname     = Request::input('subunidad_displayname');
			$year->subunidades_displayname   = Request::input('subunidades_displayname');
			$year->genero_subunidad          = Request::input('genero_subunidad');
			$year->alumnos_can_see_notas     = Request::input('alumnos_can_see_notas');
			$year->compromiso_familiar_label = $compromiso_familiar;
			$year->updated_by                = $user->user_id;

			$year->save();
			
			
			$consulta 	= 'SELECT id as history_id FROM historiales WHERE user_id=? and deleted_at is null order by id desc limit 1';
			$his 		= DB::select($consulta, [$user->user_id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= $his->history_id;

			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id, created_at, affected_element_new_value_string) 
					VALUES (?,?,?,?,?,?)';

			DB::insert($consulta, [ $bit_by, $bit_hist, 'YEAR CONFIGURACION', Request::input('id'), $now, (string) $year ]);

			return $year;
		} catch (Exception $e) {
			return $e;
		}
	}

	public function putSetActual(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$actual 	= 	(boolean) Request::input('can');

		if ($actual) {
			Year::where('actual', true)->update(['actual'=>false]);
		}

		$year = Year::findOrFail($year_id);
		$year->actual = true;
		$year->save();

		if ($actual) { return 'Ahora es año actual.';
		} else { return 'Ahora NO es año actual';}
	}

	public function putAlumnosCanSeeNotas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->alumnos_can_see_notas = $can;
		$year->save();

		if ($can) { return 'Ahora pueden ver sus notas.';
		} else { return 'Ahora NO pueden ver sus notas';}
	}


	public function putProfesCanEditAlumnos(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->profes_can_edit_alumnos = $can;
		$year->save();

		if ($can) { return 'Ahora docentes pueden editar alumnos.';
		} else { return 'Ahora docentes NO pueden editar alumnos';}
	}

	public function putToggleMostrarPuestosEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_puesto_boletin = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrarán los puestos en el boletín.';
		}else{ return 'Ahora NO se mostrarán los puestos en el boletín';}
		
	}

	public function putToggleMostrarNotaComportEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->mostrar_nota_comport_boletin = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrará la nota de comportamiento en el boletín.';
		} else { return 'Ahora NO se mostrarán la nota de comportamiento en el boletín';}
	}


	// Mostrar todas las materias al docente al entrar ignorando el horario
	public function putMostrarTodasMaterias(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->show_materias_todas = $can;
		$year->save();

		if ($can) { return 'Le apareceran todas las materias al docente ignorando el horario.';
		}else{ return 'Se mostrarán solo las materias del horario.';}

	}


	public function putToggleMostrarAnioPasadoEnBoletin(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->year_pasado_en_bol = $can;
		$year->save();

		if ($can) { return 'Ahora se mostrarán indicadores perdidos del año pasado en el boletín.';
		}else{ return 'Ahora NO se mostrarán indicadores perdidos del año pasado en el boletín';}
		
	}

	public function putToggleSoloValorativas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->solo_escalas_valorativas = $can;
		$year->save();

		if ($can) {
			return 'Ahora se mostrarán SOLO cualitativo.';
		} else {
			return 'Ahora se mostrarán cantitativo (números de las notas).';
		}
	}

	public function putToggleCambiarValor(){
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');

		$year_id 	= 	Request::input('year_id');
		$valor 		= 	Request::input('valor');
		$campo 		= 	Request::input('campo');

		$consulta 	= 'UPDATE years SET '.$campo.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:year_id';
		\Log::info($consulta);
		$datos 		= [ ':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':year_id' => $year_id ];
		$res = DB::update($consulta, $datos);

		if($res)
			return 'Guardado';
		else
			return 'No guardado';
	}


	public function putToggleIgnorarNotasPerdidas(){
		$user = User::fromToken();

		$year_id 	= 	Request::input('year_id');
		$can 		= 	(boolean) Request::input('can');

		$year = Year::findOrFail($year_id);
		$year->si_recupera_materia_recup_indicador = $can;
		$year->save();

		if ($can) { return 'Ahora se ignorarán las notas perdidas si gana la materia.';
		} else { return 'Ahora NO se ignorarán las notas perdidas si gana la materia';}
	}

	public function deleteDelete($id)
	{
		$user = User::fromToken();
		
		$year = Year::findOrFail($id);
		$year->delete();

		return $year;
	}

	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		
		$year = Year::onlyTrashed()->findOrFail($id);
		$year->forceDelete();

		return $year;
	}

	public function putRestore($id)
	{
		$year = Year::onlyTrashed()->findOrFail($id);

		if ($year) {
			$year->restore();
		} else {
			return abort(400, 'Año no encontrado en la Papelera.');
		}
		return $year;
	}


	public function getTrashed()
	{
		$years = Year::onlyTrashed()->get();
		return $years;
	}
}
