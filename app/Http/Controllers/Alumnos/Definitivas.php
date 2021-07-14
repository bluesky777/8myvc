<?php namespace App\Http\Controllers\Alumnos;



use DB;
use Carbon\Carbon;

use App\User;



class Definitivas {

    public function asignaturas_docente($profe_id, $year_id){

        $consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
						m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, 
						g.titular_id, g.caritas, p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor
					FROM asignaturas a 
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					inner join grupos g on g.id=a.grupo_id and g.year_id=:year_id and g.deleted_at is null 
					inner join profesores p on p.id=a.profesor_id 
					where p.id=:profe_id and a.deleted_at is null 
					order by g.orden, a.orden';

		$asignaturas = DB::select($consulta, [':profe_id' => $profe_id,
											':year_id' => $year_id]);


		return $asignaturas;

    }

	
	
    public function calcular_notas_finales_asignatura($asignatura_id){

		DB::delete('DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=1)', [ $asignatura_id ]);
		
		$consulta = 'SELECT alumno_id FROM alumnos a 
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null
					INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null 
					INNER JOIN asignaturas asi ON asi.id=g.asignatura_id and asi.deleted_at is null 
					WHERE asi.id=? and a.deleted_at is null';
		
		$alumnos = DB::select($consulta, [$asignatura_id]);
		
		$cant_alum = count($alumnos);
		
		for ($i=0; $i < $cant_alum; $i++) { 
			
			$alumnos[$i];
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
			$alumnos = DB::insert($consulta, [':alumno_id' => $alumno_id, ':asignatura_id' => $asignatura_id]);
		}
		
		
		
		return $asignaturas;

    }


	
    public function calcular_notas_finales_asignatura_periodo($asignatura_id){

		DB::delete('DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=1)', [ $asignatura_id ]);
		
		$consulta = 'SELECT alumno_id FROM alumnos a 
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null
					INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null 
					INNER JOIN asignaturas asi ON asi.id=g.asignatura_id and asi.deleted_at is null 
					WHERE asi.id=? and a.deleted_at is null';
		
		$alumnos = DB::select($consulta, [$asignatura_id]);
		
		$cant_alum = count($alumnos);
		
		for ($i=0; $i < $cant_alum; $i++) { 
			
			$alumnos[$i];
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
			$alumnos = DB::insert($consulta, [':alumno_id' => $alumno_id, ':asignatura_id' => $asignatura_id]);
		}
		
		
		
		return $asignaturas;

    }


}