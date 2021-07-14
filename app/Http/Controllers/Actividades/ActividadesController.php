<?php namespace App\Http\Controllers\Actividades;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use App\Models\WsActividad;
use App\Models\Grupo;
use App\Models\WsActividadCompartida;


class ActividadesController extends Controller {


	public function postCrear()
	{
		$user = User::fromToken();

		$acti 					= new WsActividad;
		$acti->asignatura_id 	= Request::input('asignatura_id');
		$acti->periodo_id 		= $user->periodo_id;
		$acti->tipo_calificacion = 'Por promedio';
		$acti->created_by 		= $user->persona_id;
		$acti->save();

		return $acti;
	}

	public function putDatos()
	{
		$user = User::fromToken();

		$datos 				= [];
		$mis_asignaturas 	= [];
		$otras_asignaturas 	= [];
		$grupo_id 			= Request::input('grupo_id');

		$consulta = 'SELECT * FROM grupos g WHERE g.year_id=? and g.deleted_at is null';
		$grupos = DB::select($consulta, [$user->year_id]);
		$datos['grupos'] = $grupos;

		if ($user->is_superuser) {
			if ($grupo_id) {
			 	$otras_asignaturas = Grupo::detailed_materias( $grupo_id );
			}elseif (Request::input('asign_id')) { // Si en vez del grupo_id me dan la asignatura, tengo que averiguar el grupo_id
				$consulta = 'SELECT a.grupo_id FROM asignaturas a WHERE a.id=? and a.deleted_at is null';
				$grupo_id = DB::select($consulta, [Request::input('asign_id')])[0]->grupo_id;
				
				$otras_asignaturas = Grupo::detailed_materias( $grupo_id );
			}
			
		}

		if ($user->tipo == 'Profesor') {
			if ($grupo_id) {
				$mis_asignaturas = Grupo::detailed_materias( $grupo_id, $user->persona_id );
			 	$otras_asignaturas = Grupo::detailed_materias( $grupo_id );
			}elseif (Request::input('asign_id')) { // Si en vez del grupo_id me dan la asignatura, tengo que averiguar el grupo_id
				$consulta = 'SELECT a.grupo_id FROM asignaturas a WHERE a.id=? and a.deleted_at is null';
				$grupo_id = DB::select($consulta, [Request::input('asign_id')])[0]->grupo_id;
				
				$mis_asignaturas = Grupo::detailed_materias( $grupo_id, $user->persona_id );
				$otras_asignaturas = Grupo::detailed_materias( $grupo_id, $user->persona_id, true );
			}
		}

		$cant = count($mis_asignaturas);
		for ($i=0; $i < $cant; $i++) { 

			$consulta 			= 'SELECT * FROM ws_actividades a WHERE a.asignatura_id=? and a.deleted_at is null and a.periodo_id=?';
			$actividades 		= DB::select($consulta, [ $mis_asignaturas[$i]->asignatura_id, $user->periodo_id ]);
			$mis_asignaturas[$i]->actividades = $actividades;
		
		}

		$cant = count($otras_asignaturas);
		for ($i=0; $i < $cant; $i++) { 

			$consulta 			= 'SELECT * FROM ws_actividades a WHERE a.asignatura_id=? and a.deleted_at is null and a.periodo_id=?';
			$actividades 		= DB::select($consulta, [ $otras_asignaturas[$i]->asignatura_id, $user->periodo_id ]);
			$otras_asignaturas[$i]->actividades = $actividades;
		
		}

		$datos['mis_asignaturas'] 	= $mis_asignaturas;
		$datos['otras_asignaturas'] = $otras_asignaturas;
		$datos['grupo_id'] 			= $grupo_id;
		



		return $datos;

	}

	public function putCompartidas()
	{
		$user = User::fromToken();

		$datos 				= [];
		$act_por_responder 	= [];
		$act_creadas		= [];

		$consulta = 'SELECT * FROM grupos g WHERE g.year_id=? and g.deleted_at is null';
		$grupos = DB::select($consulta, [$user->year_id]);
		$datos['grupos'] = $grupos;

		if ($user->is_superuser) {
			
			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.para_alumnos=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->periodo_id ]);
			$actv_alumnos 			= $actividades;
			$datos['actv_alumnos'] 	= $actv_alumnos;

			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.para_profesores=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->periodo_id ]);
			$actv_profes 			= $actividades;
			$datos['actv_profes'] 	= $actv_profes;
			
			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.para_acudientes=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->periodo_id ]);
			$actv_acudi 			= $actividades;
			$datos['actv_acudi'] 	= $actv_acudi;
			
		}

		if ($user->tipo == 'Profesor') {
			
			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.created_by=? and a.para_alumnos=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->persona_id, $user->periodo_id ]);
			$actv_alumnos 			= $actividades;
			$datos['actv_alumnos'] 	= $actv_alumnos;

			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.created_by=? and a.para_profesores=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->persona_id, $user->periodo_id ]);
			$actv_profes 			= $actividades;
			$datos['actv_profes'] 	= $actv_profes;
			
			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.para_profesores=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->periodo_id ]);
			$actv_x_respon 			= $actividades;
			$datos['actv_x_respon'] = $actv_x_respon;
			
			$consulta 				= 'SELECT * FROM ws_actividades a 
										WHERE a.compartida=true and a.created_by=? and a.para_acudientes=true and a.deleted_at is null and a.periodo_id=?';
			$actividades 			= DB::select($consulta, [ $user->persona_id, $user->periodo_id ]);
			$actv_acudi 			= $actividades;
			$datos['actv_acudi'] 	= $actv_acudi;
		}

		return $datos;

	}

	public function putEdicion()
	{
		$user 			= User::fromToken();
		$actividad_id 	= Request::input('actividad_id');
		$datos 			= [];
		$daticos 		= [];
		$compartidas 	= [];


		$actividad 	= WsActividad::datosActividad($actividad_id);

		$consulta 	= 'SELECT * FROM grupos g WHERE g.year_id=? and g.deleted_at is null';
		$grupos 	= DB::select($consulta, [$user->year_id]);


		$consulta 		= 'SELECT * FROM ws_actividades_compartidas ac WHERE ac.actividad_id=? ';
		$compartidas 	= DB::select($consulta, [$actividad_id]);

		
		$datos['grupos'] 		= $grupos;
		$datos['actividad'] 	= $actividad;
		$datos['compartidas'] 	= $compartidas;
		
		return $datos;
	}

	public function putGuardar()
	{
		$user 	= User::fromToken();

		$act = WsActividad::findOrFail(Request::input('id'));

		$act->descripcion	=	Request::input('descripcion');
		$act->compartida	=	Request::input('compartida');
		$act->can_upload	=	Request::input('can_upload');
		$act->tipo			=	Request::input('tipo');
		$act->in_action		=	Request::input('in_action');
		$act->duracion_preg	=	Request::input('duracion_preg');
		$act->duracion_exam	=	Request::input('duracion_exam');
		$act->oportunidades	=	Request::input('oportunidades');
		$act->one_by_one	=	Request::input('one_by_one');
		$act->tipo_calificacion	=	Request::input('tipo_calificacion'); // 'Sin puntaje', 'Por promedio', 'Por puntos' 
		$act->contenido		=	Request::input('contenido');
		$act->inicia_at		=	Request::input('inicia_at_str');
		$act->finaliza_at	=	Request::input('finaliza_at_str');
		$act->save();

		return $act;
	}

	public function putInsertGrupoCompartido()
	{
		
		$act 				= new WsActividadCompartida();
		$act->actividad_id 	= Request::input('actividad_id');
		$act->grupo_id 		= Request::input('grupo_id');
		$act->save();
		
		return $act;
	}


	public function putQuitandoGrupoCompartido()
	{
		$user = User::fromToken();

		WsActividadCompartida::where('actividad_id', Request::input('actividad_id'))
							->where('grupo_id', Request::input('grupo_id'))
							->delete();
		
		return 'Quitado';
	}

	public function putSetCompartida()
	{
		$user 			= User::fromToken();

		$act = WsActividad::findOrFail(Request::input('actividad_id'));
		$act->compartida 	= Request::input('compartida');
		$act->save();
		
		return 'Compartida cambiada';
	}

	public function putParaAlumnosToggle()
	{
		$user 			= User::fromToken();
		$para 			= Request::input('para_alumnos');

		$act = WsActividad::findOrFail(Request::input('actividad_id'));
		$act->para_alumnos 	= $para;
		$act->save();
		

		return $act;
	}

	public function putParaProfesoresToggle()
	{
		$user 			= User::fromToken();
		$para 			= Request::input('para_profesores');

		$act = WsActividad::findOrFail(Request::input('actividad_id'));
		$act->para_profesores 	= $para;
		$act->save();
		

		return $act;
	}

	public function putParaAcudientesToggle()
	{
		$user 			= User::fromToken();
		$para 			= Request::input('para_acudientes');

		$act = WsActividad::findOrFail(Request::input('actividad_id'));
		$act->para_acudientes 	= $para;
		$act->save();


		return $act;
	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		$act 	= WsActividad::findOrFail($id);
		$act->delete();

		return $act;
	}

}