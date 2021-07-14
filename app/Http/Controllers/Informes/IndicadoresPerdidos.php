<?php namespace App\Http\Controllers\Informes;



use DB;
use Carbon\Carbon;

use App\User;



class IndicadoresPerdidos {

    public function de_asignaturas_por_periodos($alumno_id, $grupo_id, $periodos){
        
        
        

	
	    $consulta_alumnos_grupo_nota_final = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
                a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, m.grupo_id, m.estado, 
                nf1.nota as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1, nf1.updated_by as updated_by_1, nf1.created_at as created_at_1, nf1.updated_at as updated_at_1,
                nf2.nota as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2, nf2.updated_by as updated_by_2, nf2.created_at as created_at_2, nf2.updated_at as updated_at_2,
                nf3.nota as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3, nf3.updated_by as updated_by_3, nf3.created_at as created_at_3, nf3.updated_at as updated_at_3,
                nf4.nota as nota_final_per4, nf4.id as nf_id_4, nf4.recuperada as recuperada_4, nf4.manual as manual_4, nf4.updated_by as updated_by_4, nf4.created_at as created_at_4, nf4.updated_at as updated_at_4,
                
                cast(r1.DefMateria as decimal(4,1)) as def_materia_auto_1, r1.updated_at as updated_at_def_1, IF(nf1.updated_at > r1.updated_at, FALSE, TRUE) AS nfinal1_desactualizada, r1.periodo_id as periodo_id1, 
                cast(r2.DefMateria as decimal(4,1)) as def_materia_auto_2, r2.updated_at as updated_at_def_2, IF(nf2.updated_at > r2.updated_at, FALSE, TRUE) AS nfinal2_desactualizada, r2.periodo_id as periodo_id2, 
                cast(r3.DefMateria as decimal(4,1)) as def_materia_auto_3, r3.updated_at as updated_at_def_3, IF(nf3.updated_at > r3.updated_at, FALSE, TRUE) AS nfinal3_desactualizada, r3.periodo_id as periodo_id3, 
                cast(r4.DefMateria as decimal(4,1)) as def_materia_auto_4, r4.updated_at as updated_at_def_4, IF(nf4.updated_at > r4.updated_at, FALSE, TRUE) AS nfinal4_desactualizada, r4.periodo_id as periodo_id4, 
                
                u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
                a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
            FROM alumnos a 
            inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="MATR" or m.estado="ASIS") and m.deleted_at is null
            left join users u on a.user_id=u.id and u.deleted_at is null
            left join images i on i.id=u.imagen_id and i.deleted_at is null
            left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
            left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asign_id1 and nf1.periodo=1
            left join notas_finales nf2 on nf2.alumno_id=a.id and nf2.asignatura_id=:asign_id2 and nf2.periodo=2
            left join notas_finales nf3 on nf3.alumno_id=a.id and nf3.asignatura_id=:asign_id3 and nf3.periodo=3
            left join notas_finales nf4 on nf4.alumno_id=a.id and nf4.asignatura_id=:asign_id4 and nf4.periodo=4

            left join (
                SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                FROM(
                    SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
                        sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                    FROM asignaturas asi 
                    inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                    inner join periodos p1 on p1.numero=1 and p1.id=u.periodo_id and p1.deleted_at is null
                    where asi.deleted_at is null and asi.id=:asign_id5
                    group by n.alumno_id, s.unidad_id, s.id
                )df1
                group by df1.alumno_id, df1.periodo_id
            )r1 ON r1.alumno_id=a.id

            left join (
                SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                FROM(
                    SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
                        sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                    FROM asignaturas asi 
                    inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                    inner join periodos p1 on p1.numero=2 and p1.id=u.periodo_id and p1.deleted_at is null
                    where asi.deleted_at is null and asi.id=:asign_id6
                    group by n.alumno_id, s.unidad_id, s.id
                )df1
                group by df1.alumno_id, df1.periodo_id
            )r2 ON r2.alumno_id=a.id

            left join (
                SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                FROM(
                    SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
                        sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                    FROM asignaturas asi 
                    inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                    inner join periodos p1 on p1.numero=3 and p1.id=u.periodo_id and p1.deleted_at is null
                    where asi.deleted_at is null and asi.id=:asign_id7
                    group by n.alumno_id, s.unidad_id, s.id
                )df1
                group by df1.alumno_id, df1.periodo_id
            )r3 ON r3.alumno_id=a.id

            left join (
                SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
                FROM(
                    SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at,
                        sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
                    FROM asignaturas asi 
                    inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
                    inner join periodos p1 on p1.numero=4 and p1.id=u.periodo_id and p1.deleted_at is null
                    where asi.deleted_at is null and asi.id=:asign_id8
                    group by n.alumno_id, s.unidad_id, s.id
                )df1
                group by df1.alumno_id, df1.periodo_id
            )r4 ON r4.alumno_id=a.id

            where a.deleted_at is null and m.deleted_at is null
            order by a.apellidos, a.nombres';


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

	
	


}