<?php namespace App\Http\Controllers\Informes;

use Illuminate\Support\Facades\DB;
use App\User;

class CalcPerdidasDefinitivas {
	
	
	
	public $consulta_per4 = 'SELECT a.nombres, a.id, CAST((IFNULL(nf1.nota, 0) + IFNULL(nf2.nota,0) + IFNULL(nf3.nota,0) + IFNULL(nf4.nota,0))/4 AS DOUBLE) as definitiva_year,
						(IFNULL(cant_perdidas_1, 0) + IFNULL(cant_perdidas_2, 0) + IFNULL(cant_perdidas_3, 0) + IFNULL(cant_perdidas_4, 0)) as cant_perdidas_year,
						CAST(nf1.nota AS DOUBLE) as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						CAST(nf2.nota AS DOUBLE) as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2,
						CAST(nf3.nota AS DOUBLE) as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3,
						CAST(nf4.nota AS DOUBLE) as nota_final_per4, nf4.id as nf_id_4, nf4.recuperada as recuperada_4, nf4.manual as manual_4,
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
	
	
	public $consulta_per3 = 'SELECT a.nombres, a.id, CAST((IFNULL(nf1.nota, 0) + IFNULL(nf2.nota,0) + IFNULL(nf3.nota,0))/3 AS DOUBLE) as definitiva_year,
						(IFNULL(cant_perdidas_1, 0) + IFNULL(cant_perdidas_2, 0) + IFNULL(cant_perdidas_3, 0)) as cant_perdidas_year,
						CAST(nf1.nota AS DOUBLE) as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						CAST(nf2.nota AS DOUBLE) as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2,
						CAST(nf3.nota AS DOUBLE) as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3,
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
	
	
	public $consulta_per2 = 'SELECT a.nombres, a.id, CAST((IFNULL(nf1.nota, 0) + IFNULL(nf2.nota,0))/2 AS DOUBLE) as definitiva_year,
						(IFNULL(cant_perdidas_1, 0) + IFNULL(cant_perdidas_2, 0)) as cant_perdidas_year,
						CAST(nf1.nota AS DOUBLE) as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
						CAST(nf2.nota AS DOUBLE) as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2,
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
	
	
	
	
	public $consulta_per1 = 'SELECT a.nombres, a.id, CAST(IFNULL(nf1.nota, 0) AS DOUBLE) as definitiva_year,
						(IFNULL(cant_perdidas_1, 0)) as cant_perdidas_year,
						CAST(nf1.nota AS DOUBLE) as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1,
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
	
	
	/**
	 * **`hastaPeriodoConDefinitivas()` para un grupo entero, en una consulta** en vez de
	 * una por alumno × asignatura (1.824 en `notas-actuales` de un grupo de 38;
	 * docs/migracion/48). Devuelve, por `alumno_id|asignatura_id`, las mismas filas.
	 *
	 * Se arma con las MISMAS expresiones que las cuatro de arriba, rarezas incluidas,
	 * porque la respuesta tiene que salir igual: la media se calcula en SQL con el mismo
	 * `CAST((IFNULL..)/n AS DOUBLE)` —en PHP no daría el mismo flotante— y **los joins de
	 * los periodos 2 a 4 siguen mirando `nf1.periodo_id`**, que es lo que hacen las
	 * originales: sin definitiva del primer periodo, las demás salen nulas. No se
	 * corrige aquí; esto sólo cambia cuántas veces se pregunta.
	 *
	 * @param list<int> $alumnoIds
	 * @param list<int> $asignaturaIds
	 * @return array<string, list<object>>
	 */
	public function delGrupo(array $alumnoIds, array $asignaturaIds, $periodo_a_calcular): array
	{
		$n = (int) $periodo_a_calcular;
		if ($alumnoIds === [] || $asignaturaIds === [] || $n < 1 || $n > 4 || (string) $n !== (string) $periodo_a_calcular) {
			return [];
		}

		$alumnos = implode(',', array_map('intval', $alumnoIds));
		$asignaturas = implode(',', array_map('intval', $asignaturaIds));
		$k = range(1, $n);

		$definitiva = $n === 1
			? 'CAST(IFNULL(nf1.nota, 0) AS DOUBLE)'
			: 'CAST(('.implode(' + ', array_map(fn ($i) => $i === 1 ? 'IFNULL(nf1.nota, 0)' : 'IFNULL(nf'.$i.'.nota,0)', $k)).')/'.$n.' AS DOUBLE)';
		$perdidasDelAnio = '('.implode(' + ', array_map(fn ($i) => 'IFNULL(cant_perdidas_'.$i.', 0)', $k)).')';
		$columnas = implode(",\n", array_map(fn ($i) => 'CAST(nf'.$i.'.nota AS DOUBLE) as nota_final_per'.$i.', nf'.$i.'.id as nf_id_'.$i.', nf'.$i.'.recuperada as recuperada_'.$i.', nf'.$i.'.manual as manual_'.$i, $k));
		$cantidades = implode(', ', array_map(fn ($i) => 'cant_perdidas_'.$i, $k));

		$joins = '';
		foreach ($k as $i) {
			$joins .= ' left join notas_finales nf'.$i.' on nf'.$i.'.alumno_id=a.id and nf'.$i.'.asignatura_id=g.id and nf'.$i.'.periodo='.$i.' and nf1.periodo_id is not null';
		}
		foreach ($k as $i) {
			$joins .= ' left join (
				SELECT df1.alumno_id, df1.asignatura_id, count( df1.nota ) cant_perdidas_'.$i.'
				FROM(
					SELECT n.alumno_id, asi.id as asignatura_id, n.nota
					FROM asignaturas asi
					inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
					inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
					inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<?
					inner join periodos p1 on p1.numero='.$i.' and p1.id=u.periodo_id and p1.deleted_at is null
					where asi.deleted_at is null and asi.id IN ('.$asignaturas.') and n.alumno_id IN ('.$alumnos.')
				)df1
				group by df1.alumno_id, df1.asignatura_id
			)r'.$i.' ON r'.$i.'.alumno_id=a.id and r'.$i.'.asignatura_id=g.id';
		}

		$filas = DB::select(
			'SELECT a.nombres, a.id, '.$definitiva.' as definitiva_year,
				'.$perdidasDelAnio.' as cant_perdidas_year,
				'.$columnas.',
				'.$cantidades.',
				g.id as asignatura_de_reparto
			FROM alumnos a
			inner join asignaturas g on g.id IN ('.$asignaturas.')
			'.$joins.'
			where a.deleted_at is null and a.id IN ('.$alumnos.')',
			array_fill(0, $n, User::$nota_minima_aceptada)
		);

		$porCelda = [];
		foreach ($filas as $fila) {
			$clave = (int) $fila->id.'|'.(int) $fila->asignatura_de_reparto;
			unset($fila->asignatura_de_reparto);
			$porCelda[$clave][] = $fila;
		}

		return $porCelda;
	}

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

