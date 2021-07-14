<?php namespace App\Http\Controllers\Actividades;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use App\Models\WsActividad;
use App\Models\WsRespuesta;
use App\Models\WsActividadResuelta;
use App\Models\Grupo;


class RespuestasController extends Controller {


	public function putActividad()
	{
		$user = User::fromToken();

		$datos 				= [];
		$actividad_id 		= Request::input('actividad_id');

		$actividad 			= WsActividad::datosActividad($actividad_id);
		$datos['actividad'] = $actividad;

		// Averiguo los entes participantes: grupos, profesores y acudientes
		if ($actividad->compartida) {
			
			if ($actividad->para_alumnos) {
				$consulta 	= 'SELECT g.id, g.nombre, g.abrev, g.titular_id, g.orden FROM ws_actividades_compartidas ac 
								INNER JOIN grupos g on g.id=ac.grupo_id and g.deleted_at is null
								WHERE actividad_id=?;';
				$grupos 	= DB::select($consulta, [$actividad->id]);
				$cant 		= count($grupos);

				for ($i=0; $i < $cant; $i++) { 
					$consulta 	= 'SELECT g.id, g.nombre, g.abrev, g.titular_id, g.orden FROM ws_actividades_compartidas ac 
								INNER JOIN grupos g on g.id=ac.grupo_id and g.deleted_at is null
								WHERE actividad_id=?;';
					$grupos 	= DB::select($consulta, [$actividad->id]);

					$cant_gru 	= count($grupos);

					for ($j=0; $j < $cant_gru; $j++) { 
						$grupos[$j]->alumnos = WsActividadResuelta::alumnos_grupo($grupos[$j]->id, $actividad->id);

					}
				
				}
				$datos['grupos'] = $grupos;
				return $datos;
			}


			if ($actividad->para_profesores) {
				return 'Profesores';
			}


			if ($actividad->para_acudientes) {
				return 'Acudientes';
			}



		}else{

			$consulta = '';

			$alumnos = DB::select($consulta, [$user->year_id]);

		}
		



		

		return $datos;

	}


}