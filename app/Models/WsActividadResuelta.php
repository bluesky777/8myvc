<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;
use \stdClass;


class WsActividadResuelta extends Model {
	protected $fillable 	= [];
	
	use SoftDeletes;
	protected $softDelete 	= true;
	protected $table 		= 'ws_actividades_resueltas';





	static public function alumnos_grupo($grupo_id, $actividad_id)
	{
		$consulta 	= 'SELECT m.id as matricula_id, m.alumno_id, a.nombres, a.apellidos, a.sexo, a.user_id, 
							m.grupo_id, m.estado, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							ar.id as actividad_res_id, ar.respuesta_comentario, ar.autoevaluacion, ar.is_puntaje_manual, ar.puntaje_manual, ar.terminado, ar.timeout
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and (m.estado="MATR" or m.estado="ASIS") and m.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						left join ws_actividades_resueltas ar on ar.persona_id=a.id and ar.actividad_id=? and ar.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';
		

		$alumnos 	= DB::select($consulta, [$grupo_id, $actividad_id]);

		$cant 		= count($alumnos);

		for ($i=0; $i < $cant; $i++) { 
			

			$consulta 	= 'SELECT r.id as respuesta_i, r.actividad_resuelta_id, r.pregunta_id, r.tiempo, r.tipo_pregunta, r.opcion_id, r.opcion_cuadricula_id, 
								o.definicion, o.image_id, o.orden, o.is_correct, p.*
							FROM ws_respuestas r
							inner join ws_opciones o on r.opcion_id=o.id
							inner join ws_preguntas p on p.id=o.pregunta_id
							WHERE r.actividad_resuelta_id=?';
			

			$respuestas = DB::select($consulta, [$alumnos[$i]->actividad_res_id]);
			$alumnos[$i]->respuestas = $respuestas;

			$cant_res 	= count($respuestas);
			$correctas 	= 0;
			$puntos 	= 0;
			$tiempo 	= DB::table('ws_respuestas')->where('actividad_resuelta_id', $alumnos[$i]->actividad_res_id)->sum('tiempo');

			for ($j=0; $j < $cant_res; $j++) { 
				

				if ($respuestas[$j]->is_correct) {
					$correctas++;

					$puntos_preg 	= $respuestas[$j]->puntos;
					$puntos 		= $puntos + $puntos_preg;
				}

			}

			$cantidad_pregs = 4; // Debo hacer un código que traiga la cantidad de preguntas de la actividad
			
			// Calculamos por promedio
			if ($cantidad_pregs > 0) {
				$promedio = $correctas * 100 / $cantidad_pregs;
			}else{
				$promedio = 0;
			}
			

			$actividad_res 					= new stdClass();
			$actividad_res->promedio 		= $promedio;
			$actividad_res->cantidad_pregs 	= $cantidad_pregs;
			$actividad_res->correctas 		= $correctas;
			$actividad_res->tiempo 			= (integer)$tiempo;

			$alumnos[$i]->actividad_res 	= $actividad_res;


		}

		
		return $alumnos;

	}

}