<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;

class WsActividad extends Model {
	protected $fillable 	= [];

	use SoftDeletes;
	protected $softDelete 	= true;
	protected $table 		= 'ws_actividades';

	static public $consActividad 	= 'SELECT a.*, ag.grupo_id, g.nombre as nombre_grupo, g.abrev, g.titular_id, ag.materia_id, m.materia, m.alias, 
											p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
											p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
									FROM ws_actividades a 
									LEFT JOIN asignaturas ag on ag.id=a.asignatura_id and ag.deleted_at is null
									LEFT JOIN grupos g on g.id=ag.grupo_id and g.deleted_at is null
									LEFT JOIN materias m on m.id=ag.materia_id and m.deleted_at is null
									LEFT JOIN profesores p on p.id=g.titular_id and p.deleted_at is null
									LEFT JOIN images i on p.foto_id=i.id and i.deleted_at is null
									WHERE a.id=? and a.deleted_at is null';

	static public function datosActividad($actividad_id)
	{
		
		$actividad 			= DB::select(WsActividad::$consActividad, [ $actividad_id ])[0];
		
		$consulta 			= 'SELECT * FROM (
									SELECT p.id, TRUE as is_preg, p.actividad_id, p.enunciado, p.orden, p.added_by, p.created_at, p.updated_at, NULL as is_cuadricula,
										p.opcion_otra, p.ayuda, p.tipo_pregunta, p.puntos, p.duracion, p.aleatorias, p.texto_arriba, p.texto_abajo 
									FROM ws_preguntas p 
									WHERE p.actividad_id=:actividad_id1 and p.deleted_at is null
								union
									SELECT c.id, TRUE as is_preg, c.actividad_id, c.enunciado, c.orden, c.added_by, c.created_at, c.updated_at, c.is_cuadricula,
										NULL as opcion_otra, NULL as ayuda, NULL as tipo_pregunta, NULL as puntos, NULL as duracion, NULL as aleatorias, NULL as texto_arriba, NULL as texto_abajo 
									FROM ws_contenidos_preg c 
									WHERE c.actividad_id=:actividad_id2 and c.deleted_at is null
								)p order by orden ASC, created_at';
		

		$preguntas 			= DB::select($consulta, [ 
										':actividad_id1' => $actividad_id,
										':actividad_id2' => $actividad_id, 
									]);

		$cant = count($preguntas);

		for ($i=0; $i < $cant; $i++) { 
			
			if ($preguntas[$i]->is_preg) {
				
				$consulta = 'SELECT o.id, o.pregunta_id, o.definicion, o.image_id, o.orden, o.is_correct, o.created_at, o.updated_at 
						FROM ws_opciones o
						where o.pregunta_id=:pregunta_id';

				$opciones = DB::select($consulta, [':pregunta_id' => $preguntas[$i]->id] );
				$preguntas[$i]->opciones = $opciones;

			}else{

				$consulta = 'SELECT p.id, TRUE as is_preg, p.actividad_id, p.enunciado, p.orden, p.added_by, p.created_at, p.updated_at, NULL as is_cuadricula,
									p.opcion_otra, p.ayuda, p.tipo_pregunta, p.puntos, p.duracion, p.aleatorias, p.texto_arriba, p.texto_abajo 
								FROM ws_preguntas p 
								WHERE p.actividad_id=:actividad_id1 and p.deleted_at is null
								ORDER BY p.order, p.id';

				$opciones = DB::select($consulta, [':pregunta_id' => $preguntas[$i]->id] );
				$preguntas[$i]->opciones = $opciones;


			}
		}

		
		$actividad->preguntas = $preguntas;

		return $actividad;

	}





	static public function datosActividadConRespuestas($actividad_id, $actividad_resuelta_id)
	{
		
		$consulta 			= 'SELECT * FROM ws_actividades a WHERE a.id=? and a.deleted_at is null';
		$actividad 			= DB::select($consulta, [ $actividad_id ])[0];
		
		$actividad->actividad_resuelta_id = $actividad_resuelta_id;

		$consulta 			= 'SELECT * FROM (
									SELECT p.id, TRUE as is_preg, p.actividad_id, p.enunciado, p.orden, p.added_by, p.created_at, p.updated_at, NULL as is_cuadricula,
										p.opcion_otra, p.ayuda, p.tipo_pregunta, p.puntos, p.duracion, p.aleatorias, p.texto_arriba, p.texto_abajo, 
										r.actividad_resuelta_id, r.tiempo, r.opcion_id, r.opcion_cuadricula_id
									FROM ws_preguntas p 
									LEFT JOIN ws_respuestas r ON r.pregunta_id=p.id AND r.actividad_resuelta_id=:actividad_resuelta_id
									WHERE p.actividad_id=:actividad_id1 and p.deleted_at is null
								union
									SELECT c.id, TRUE as is_preg, c.actividad_id, c.enunciado, c.orden, c.added_by, c.created_at, c.updated_at, c.is_cuadricula,
										NULL as opcion_otra, NULL as ayuda, NULL as tipo_pregunta, NULL as puntos, NULL as duracion, NULL as aleatorias, NULL as texto_arriba, NULL as texto_abajo, 
										NULL as actividad_resuelta_id, NULL as tiempo, NULL as opcion_id, NULL as opcion_cuadricula_id 
									FROM ws_contenidos_preg c 
									WHERE c.actividad_id=:actividad_id2 and c.deleted_at is null
								)p order by orden ASC, created_at';
		

		$preguntas 			= DB::select($consulta, [ 
										':actividad_resuelta_id' 	=> $actividad_resuelta_id,
										':actividad_id1' 			=> $actividad_id,
										':actividad_id2' 			=> $actividad_id, 
									]);

		$cant = count($preguntas);

		for ($i=0; $i < $cant; $i++) { 
			
			if ($preguntas[$i]->is_preg) {
				
				$consulta = 'SELECT o.id, IF(o.id=:selected_id, 1, 0) as seleccionada, o.pregunta_id, o.definicion, o.image_id, o.orden, o.created_at, o.updated_at 
						FROM ws_opciones o
						where o.pregunta_id=:pregunta_id';

				$opciones = DB::select($consulta, [':pregunta_id' => $preguntas[$i]->id, ':selected_id' => $preguntas[$i]->opcion_id] );
				$preguntas[$i]->opciones = $opciones;

			}else{

				$consulta = 'SELECT p.id, TRUE as is_preg, p.actividad_id, p.enunciado, p.orden, p.added_by, p.created_at, p.updated_at, NULL as is_cuadricula,
									p.opcion_otra, p.ayuda, p.tipo_pregunta, p.puntos, p.duracion, p.aleatorias, p.texto_arriba, p.texto_abajo 
								FROM ws_preguntas p 
								WHERE p.actividad_id=:actividad_id1 and p.deleted_at is null
								ORDER BY p.order, p.id';

				$opciones = DB::select($consulta, [':pregunta_id' => $preguntas[$i]->id] );
				$preguntas[$i]->opciones = $opciones;


			}
		}

		
		$actividad->preguntas = $preguntas;

		return $actividad;

	}



}

