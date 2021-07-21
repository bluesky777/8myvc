<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;

use App\Models\Nota;


class Asignatura extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;


	public static function detallada($asignatura_id, $year_id)
	{
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
						m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, 
						g.titular_id, g.caritas, p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor
					FROM asignaturas a 
					inner join materias m on m.id=a.materia_id 
					inner join grupos g on g.id=a.grupo_id and g.year_id=:year_id and g.deleted_at is null 
					inner join profesores p on p.id=a.profesor_id 
					where a.id=:asignatura_id 
					order by g.orden, a.orden';

		$asignatura = DB::select($consulta, [':asignatura_id' => $asignatura_id,
											':year_id' => $year_id]);


		return (array)$asignatura[0];
	}


	public static function unidades_notas($alumno_id)
	{
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden as orden_asignatura,
						m.id as materia_id, m.orden as orden_materia, m.materia, m.alias as alias_materia, 
						g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id, g.caritas, 
						p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor
					
					FROM asignaturas a 
					inner join materias m on m.id=a.materia_id 
					inner join grupos g on g.id=a.grupo_id and g.id=13
					inner join profesores p on p.id=a.profesor_id 
					where a.deleted_at is null
					order by a.orden, m.orden, m.materia';

		$asignatura = DB::select(DB::raw($consulta), array(':asignatura_id' => $asignatura_id));


		return (array)$asignatura[0];
	}


	public static function calculoAlumnoNotas(&$asignatura, $alumno_id)
	{
		$nota_asignatura = 0;

		foreach ($asignatura->unidades as $unidad) {
			
			$nota_unidad = 0;

			foreach ($unidad->subunidades as $subunidad) {
				
				$nota = DB::select('SELECT * FROM notas WHERE subunidad_id=? AND alumno_id=? AND deleted_at is null', [ $subunidad->subunidad_id, $alumno_id ]);

				if (count($nota)>0) {
					$nota = $nota[0];
					$subunidad->nota = $nota;

					$subunidad->nota->valor = ($nota->nota * $subunidad->porcentaje_subunidad) / 100;
					$nota_unidad += $subunidad->nota->valor;
				}
				
			}

			$unidad->nota_unidad 	= $nota_unidad;
			$valor_unidad 			= ($unidad->nota_unidad * $unidad->porcentaje_unidad) / 100;
			$unidad->valor_unidad 	= $valor_unidad;

			$nota_asignatura += $unidad->valor_unidad;
		}


		$asignatura->nota_asignatura = round($nota_asignatura); // Definitiva de la materia

		return $asignatura;
	}




	public static function calculoAlumnoNotas2(&$asignatura, $alumno_id)
	{
		$nota_asignatura = 0;
/*
		foreach ($asignatura->unidades as $unidad) {
			
			$nota_unidad = 0;

			foreach ($unidad->subunidades as $subunidad) {
				
				$nota = DB::select('SELECT * FROM notas WHERE subunidad_id=? AND alumno_id=? AND deleted_at is null', [ $subunidad->subunidad_id, $alumno_id ]);

				if (count($nota)>0) {
					$nota = $nota[0];
					$subunidad->nota = $nota;

					$subunidad->nota->valor = ($nota->nota * $subunidad->porcentaje_subunidad) / 100;
					$nota_unidad += $subunidad->nota->valor;
				}
				
			}

			$unidad->nota_unidad 	= $nota_unidad;
			$valor_unidad 			= ($unidad->nota_unidad * $unidad->porcentaje_unidad) / 100;
			$unidad->valor_unidad 	= $valor_unidad;

			$nota_asignatura += $unidad->valor_unidad;
		}
*/

		$asignatura->nota_asignatura = $nota_asignatura; // Definitiva de la materia

		return $asignatura;
	}



	public static function notasPerdidasAsignatura($asignatura)
	{
		$notas_perdidas = 0;

		foreach ($asignatura->unidades as $unidad) {
			
			foreach ($unidad->subunidades as $subunidad) {
				
				if (isset($subunidad->nota->nota)) {
					if ($subunidad->nota->nota < User::$nota_minima_aceptada) {
						$notas_perdidas++;
					}
				}
				
			}

		}

		return $notas_perdidas;
	}



}

