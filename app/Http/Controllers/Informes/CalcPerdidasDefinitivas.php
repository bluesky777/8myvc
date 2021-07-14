<?php namespace App\Http\Controllers\Informes;

use DB;
use App\User;

class CalcPerdidasDefinitivas {
	
	
	
	public $consulta_per4 = 'SELECT a.nombres, a.id, (IFNULL(nf1.nota, 0) + IFNULL(nf2.nota,0) + IFNULL(nf3.nota,0) + IFNULL(nf4.nota,0))/4 as definitiva_year,
						(IFNULL(cant_perdidas_1, 0) + IFNULL(cant_perdidas_2, 0) + IFNULL(cant_perdidas_3, 0) + IFNULL(cant_perdidas_4, 0)) as cant_perdidas_year,
						nf1.nota as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						nf2.nota as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2,
						nf3.nota as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3,
						nf4.nota as nota_final_per4, nf4.id as nf_id_4, nf4.recuperada as recuperada_4, nf4.manual as manual_4,
						cant_perdidas_1, cant_perdidas_2, cant_perdidas_3, cant_perdidas_4
						
					FROM alumnos a 
					left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asi1 and nf1.periodo=1 and nf1.periodo_id is not null
					left join notas_finales nf2 on nf2.alumno_id=a.id and nf2.asignatura_id=:asi2 and nf2.periodo=2 and nf1.periodo_id is not null
					left join notas_finales nf3 on nf3.alumno_id=a.id and nf3.asignatura_id=:asi3 and nf3.periodo=3 and nf1.periodo_id is not null
					left join notas_finales nf4 on nf4.alumno_id=a.id and nf4.asignatura_id=:asi4 and nf4.periodo=4 and nf1.periodo_id is not null
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_1 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min1
							inner join periodos p1 on p1.numero=1 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi5 and n.alumno_id=:alu1
						)df1
						group by df1.alumno_id
					)r1 ON r1.alumno_id=a.id
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_2 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min2
							inner join periodos p1 on p1.numero=2 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi6 and n.alumno_id=:alu2
						)df1
						group by df1.alumno_id
					)r2 ON r2.alumno_id=a.id
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_3 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min3
							inner join periodos p1 on p1.numero=3 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi7 and n.alumno_id=:alu3
						)df1
						group by df1.alumno_id
					)r3 ON r3.alumno_id=a.id
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_4 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min4
							inner join periodos p1 on p1.numero=4 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi8 and n.alumno_id=:alu4
						)df1
						group by df1.alumno_id
					)r4 ON r4.alumno_id=a.id
					where a.deleted_at is null and a.id=:alu5';
	
	
	public $consulta_per3 = 'SELECT a.nombres, a.id, (IFNULL(nf1.nota, 0) + IFNULL(nf2.nota,0) + IFNULL(nf3.nota,0))/3 as definitiva_year,
						(IFNULL(cant_perdidas_1, 0) + IFNULL(cant_perdidas_2, 0) + IFNULL(cant_perdidas_3, 0)) as cant_perdidas_year,
						nf1.nota as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						nf2.nota as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2,
						nf3.nota as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3,
						cant_perdidas_1, cant_perdidas_2, cant_perdidas_3
						
					FROM alumnos a 
					left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asi1 and nf1.periodo=1 and nf1.periodo_id is not null
					left join notas_finales nf2 on nf2.alumno_id=a.id and nf2.asignatura_id=:asi2 and nf2.periodo=2 and nf1.periodo_id is not null
					left join notas_finales nf3 on nf3.alumno_id=a.id and nf3.asignatura_id=:asi3 and nf3.periodo=3 and nf1.periodo_id is not null
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_1 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min1
							inner join periodos p1 on p1.numero=1 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi5 and n.alumno_id=:alu1
						)df1
						group by df1.alumno_id
					)r1 ON r1.alumno_id=a.id
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_2 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min2
							inner join periodos p1 on p1.numero=2 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi6 and n.alumno_id=:alu2
						)df1
						group by df1.alumno_id
					)r2 ON r2.alumno_id=a.id
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_3 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min3
							inner join periodos p1 on p1.numero=3 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi7 and n.alumno_id=:alu3
						)df1
						group by df1.alumno_id
					)r3 ON r3.alumno_id=a.id
					where a.deleted_at is null and a.id=:alu5';
	
	
	public $consulta_per2 = 'SELECT a.nombres, a.id, (IFNULL(nf1.nota, 0) + IFNULL(nf2.nota,0))/2 as definitiva_year,
						(IFNULL(cant_perdidas_1, 0) + IFNULL(cant_perdidas_2, 0)) as cant_perdidas_year,
						nf1.nota as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						nf2.nota as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2,
						cant_perdidas_1, cant_perdidas_2
						
					FROM alumnos a 
					left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asi1 and nf1.periodo=1 and nf1.periodo_id is not null
					left join notas_finales nf2 on nf2.alumno_id=a.id and nf2.asignatura_id=:asi2 and nf2.periodo=2 and nf1.periodo_id is not null
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_1 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min1
							inner join periodos p1 on p1.numero=1 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi5 and n.alumno_id=:alu1
						)df1
						group by df1.alumno_id
					)r1 ON r1.alumno_id=a.id
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_2 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min2
							inner join periodos p1 on p1.numero=2 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi6 and n.alumno_id=:alu2
						)df1
						group by df1.alumno_id
					)r2 ON r2.alumno_id=a.id
					where a.deleted_at is null and a.id=:alu5';
	
	
	
	
	public $consulta_per1 = 'SELECT a.nombres, a.id, IFNULL(nf1.nota, 0) as definitiva_year,
						(IFNULL(cant_perdidas_1, 0)) as cant_perdidas_year,
						nf1.nota as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						cant_perdidas_1
						
					FROM alumnos a 
					left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asi1 and nf1.periodo=1 and nf1.periodo_id is not null
					
					left join (
						SELECT df1.alumno_id, count( df1.nota ) cant_perdidas_1 
						FROM(
							SELECT n.alumno_id, n.nota
							FROM asignaturas asi 
							inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min1
							inner join periodos p1 on p1.numero=1 and p1.id=u.periodo_id and p1.deleted_at is null
							where asi.deleted_at is null and asi.id=:asi5 and n.alumno_id=:alu1
						)df1
						group by df1.alumno_id
					)r1 ON r1.alumno_id=a.id
					where a.deleted_at is null and a.id=:alu5';
	
	
	public function hastaPeriodoConDefinitivas($alumno_id, $asignatura_id, $grupo_id, $periodo_a_calcular=4)
	{
		$periodos = [];
		if ($periodo_a_calcular == 1) {
			$consulta = $this->consulta_per1;
					
			$periodos = DB::select($consulta, [':asi1' => $asignatura_id,  
										':min1' => User::$nota_minima_aceptada, ':asi5' => $asignatura_id, ':alu1' => $alumno_id, ':alu5' => $alumno_id,  ] );
		}
		else if ($periodo_a_calcular == 2) {
			$consulta = $this->consulta_per2;
					
			$periodos = DB::select($consulta, [':asi1' => $asignatura_id, ':asi2' => $asignatura_id, 
										':min1' => User::$nota_minima_aceptada, ':asi5' => $asignatura_id, ':alu1' => $alumno_id, ':min2' => User::$nota_minima_aceptada, ':asi6' => $asignatura_id, ':alu2' => $alumno_id, 
										':alu5' => $alumno_id,  ] );
		}
		
		else if ($periodo_a_calcular == 3) {
			$consulta = $this->consulta_per3;
					
			$periodos = DB::select($consulta, [':asi1' => $asignatura_id, ':asi2' => $asignatura_id, ':asi3' => $asignatura_id, 
										':min1' => User::$nota_minima_aceptada, ':asi5' => $asignatura_id, ':alu1' => $alumno_id, ':min2' => User::$nota_minima_aceptada, ':asi6' => $asignatura_id, ':alu2' => $alumno_id, 
										':min3' => User::$nota_minima_aceptada, ':asi7' => $asignatura_id, ':alu3' => $alumno_id, 
										':alu5' => $alumno_id,  ] );
		}
		else if ($periodo_a_calcular == 4) {
			$consulta = $this->consulta_per4;
					
			$periodos = DB::select($consulta, [':asi1' => $asignatura_id, ':asi2' => $asignatura_id, ':asi3' => $asignatura_id, ':asi4' => $asignatura_id, 
										':min1' => User::$nota_minima_aceptada, ':asi5' => $asignatura_id, ':alu1' => $alumno_id, ':min2' => User::$nota_minima_aceptada, ':asi6' => $asignatura_id, ':alu2' => $alumno_id, 
										':min3' => User::$nota_minima_aceptada, ':asi7' => $asignatura_id, ':alu3' => $alumno_id, ':min4' => User::$nota_minima_aceptada, ':asi8' => $asignatura_id, ':alu4' => $alumno_id, 
										':alu5' => $alumno_id,  ] );
		}
		
		return $periodos;
	}


}

