<?php namespace App\Http\Controllers;


use DB;
use Request;
use DateTime;

use App\User;
use App\Models\Alumno;
use App\Models\Grupo;
use App\Models\Ausencia;
use App\Models\Asignatura;
use Carbon\Carbon;


class AusenciasController extends Controller {

	public function getIndex()
	{
		//
	}

	public function getDetailed($asignatura_id)
	{
		$user = User::fromToken();

		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		$alumnos = Grupo::alumnos($asignatura->grupo_id);
		
		foreach ($alumnos as $alumno) {

			$userData = Alumno::userData($alumno->alumno_id);
			$alumno->userData = $userData;

			$consulta = 'SELECT * FROM ausencias a WHERE a.asignatura_id = ? and a.periodo_id = ? and a.alumno_id=? and a.deleted_at is null';

			$ausencias = DB::select($consulta, array($asignatura_id, $user->periodo_id, $alumno->alumno_id));

			foreach ($ausencias as $ausencia) {
				$ausencia->mes = date('n', strtotime($ausencia->fecha_hora)) - 1;
				$ausencia->dia = (integer)(date('j', strtotime($ausencia->fecha_hora))) ;
			}
			
			$alumno->ausencias = $ausencias;
		}

		// No cambiar el orden!
		$resultado = [];
		array_push($resultado, $asignatura);
		array_push($resultado, $alumnos);

		return $resultado;
	}

	public function postStore()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= Request::input('cantidad_ausencia', null);
		$aus->cantidad_tardanza	= Request::input('cantidad_tardanza', null);
		$aus->fecha_hora		= Request::input('fecha_hora', null);
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		
		if (Request::input('tipo')) {
			$aus->tipo = Request::input('tipo');
		}
		if ($aus->cantidad_ausencia) {
			$aus->tipo = 'ausencia';
		}
		if ($aus->cantidad_tardanza) {
			$aus->tipo = 'tardanza';
		}

		$aus->save();
		return $aus;
	}



	public function postAgregarAusencia()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'ausencia';

		$aus->save();
		return $aus;
	}


	public function postAgregarTardanza()
	{
		$user = User::fromToken();
		
		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_tardanza	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'tardanza';

		$aus->save();
		return $aus;
	}




	public function putGuardarCambiosAusencia()
	{
		$user = User::fromToken();

		/* Debo convertir string a fecha
		$dato = Request::input('fecha_hora', null);
		if ($dato) {
			$dato = DateTime::createFromFormat('Y-m-d G:H:i', $dato);
			return $dato;
		}
		*/
		User::pueden_editar_notas($user);
		

		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));
		$aus->fecha_hora		= Request::input('fecha_hora', null);
		$aus->updated_by		= $user->user_id;

		$aus->save();
		return $aus;
	}




	public function putCambiarTipoAusencia()
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user);
		
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));
		
		

		if (Request::input('new_tipo') == 'tardanza') {
			$aus->tipo					= 'tardanza';
			$aus->cantidad_tardanza		= $aus->cantidad_ausencia;
		}
		
		if (Request::input('new_tipo') == 'ausencia') {
			$aus->tipo					= 'ausencia';
			$aus->cantidad_ausencia		= $aus->cantidad_tardanza;
		}
		
		$aus->updated_by		= $user->user_id;
		$aus->save();
		return $aus;
	}




	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user);
		
		$aus = Ausencia::findOrFail($id);
		$aus->delete();
		return $aus;
	}

}