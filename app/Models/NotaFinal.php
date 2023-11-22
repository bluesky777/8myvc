<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;


use Carbon\Carbon;
use App\Models\Grupo;
use App\User;
use App\Models\Periodo;
use App\Models\Debugging;
use DB;



class NotaFinal extends Model {
	protected $fillable = [];



	
	public static $consulta_alumnos_grupo_nota_final = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, m.grupo_id, m.estado, 
							nf1.nota as nota_final_per1, nf1.id as nf_id_1, nf1.recuperada as recuperada_1, nf1.manual as manual_1, nf1.updated_by as updated_by_1, nf1.created_at as created_at_1, nf1.updated_at as updated_at_1,
							nf2.nota as nota_final_per2, nf2.id as nf_id_2, nf2.recuperada as recuperada_2, nf2.manual as manual_2, nf2.updated_by as updated_by_2, nf2.created_at as created_at_2, nf2.updated_at as updated_at_2,
							nf3.nota as nota_final_per3, nf3.id as nf_id_3, nf3.recuperada as recuperada_3, nf3.manual as manual_3, nf3.updated_by as updated_by_3, nf3.created_at as created_at_3, nf3.updated_at as updated_at_3,
							nf4.nota as nota_final_per4, nf4.id as nf_id_4, nf4.recuperada as recuperada_4, nf4.manual as manual_4, nf4.updated_by as updated_by_4, nf4.created_at as created_at_4, nf4.updated_at as updated_at_4,
                            rf.id as recu_id, rf.year as recu_year, rf.nota as recu_nota, rf.updated_at as recu_updated_at, rf.updated_by as recu_updated_by,
                            
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
                        
                        left join recuperacion_final rf ON rf.alumno_id=a.id and rf.asignatura_id=nf1.asignatura_id
                        
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';




    public static function alumnos_grupo_nota_final($grupo_id, $asignatura_id, $user_id){

        $consulta = self::$consulta_alumnos_grupo_nota_final;

        $alumnos = DB::select($consulta, [':grupo_id'=>$grupo_id, ':asign_id1'=>$asignatura_id, ':asign_id2'=>$asignatura_id, ':asign_id3'=>$asignatura_id, ':asign_id4'=>$asignatura_id, 
                                            ':asign_id5'=>$asignatura_id, ':asign_id6'=>$asignatura_id, ':asign_id7'=>$asignatura_id, ':asign_id8'=>$asignatura_id ]);

        $per_desact = ['per1' => false, 'per2' => false, 'per3' => false, 'per4' => false];
        
        $now 		= Carbon::now('America/Bogota');
        $cant_alum  = count($alumnos);
        
        for ($i=0; $i < $cant_alum; $i++) { 
            
            $alumnos[$i]->promedio_automatico = round(($alumnos[$i]->nota_final_per1 + $alumnos[$i]->nota_final_per2 + $alumnos[$i]->nota_final_per3 + $alumnos[$i]->nota_final_per4) / 4, 0);
            
            if($alumnos[$i]->nfinal1_desactualizada && $alumnos[$i]->updated_at_def_1){
                $per_desact['per1'] = true;
                
                if (!$alumnos[$i]->manual_1 && !$alumnos[$i]->recuperada_1) {
                    
                    DB::delete('DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=0) and (recuperada is null or recuperada=0) and periodo=? and alumno_id=?', [ $asignatura_id, 1, $alumnos[$i]->alumno_id ]);
                    
                    $consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
                
                    DB::insert($consulta, [':alumno_id' => $alumnos[$i]->alumno_id, ':asignatura_id' => $asignatura_id, ':periodo_id' => $alumnos[$i]->periodo_id1, 
                                                    ':periodo' => 1, ':nota' => round($alumnos[$i]->def_materia_auto_1), ':recuperada' => 0, ':manual' => 0, ':updated_by' => $user_id, ':created_at' => $now, ':updated_at' => $now ]);
                
                }
                
            }
            if($alumnos[$i]->nfinal2_desactualizada && $alumnos[$i]->updated_at_def_2){
                $per_desact['per2'] = true;
                
                if (!$alumnos[$i]->manual_2 && !$alumnos[$i]->recuperada_2) {
                    DB::delete('DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=0) and (recuperada is null or recuperada=0) and periodo_id=? and alumno_id=?', [ $asignatura_id, $alumnos[$i]->periodo_id2, $alumnos[$i]->alumno_id ]);
                    
                    $consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
                
                    DB::insert($consulta, [':alumno_id' => $alumnos[$i]->alumno_id, ':asignatura_id' => $asignatura_id, ':periodo_id' => $alumnos[$i]->periodo_id2, 
                                                    ':periodo' => 2, ':nota' => round($alumnos[$i]->def_materia_auto_2), ':recuperada' => 0, ':manual' => 0, ':updated_by' => $user_id, ':created_at' => $now, ':updated_at' => $now ]);
                
                }
                
            }
            if($alumnos[$i]->nfinal3_desactualizada && $alumnos[$i]->updated_at_def_3){
                $per_desact['per3'] = true;
                
                if (!$alumnos[$i]->manual_3 && !$alumnos[$i]->recuperada_3) {
                    DB::delete('DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=0) and (recuperada is null or recuperada=0) and periodo_id=? and alumno_id=?', [ $asignatura_id, $alumnos[$i]->periodo_id3, $alumnos[$i]->alumno_id ]);
                    
                    $consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
                
                    DB::insert($consulta, [':alumno_id' => $alumnos[$i]->alumno_id, ':asignatura_id' => $asignatura_id, ':periodo_id' => $alumnos[$i]->periodo_id3, 
                                                    ':periodo' => 3, ':nota' => round($alumnos[$i]->def_materia_auto_3), ':recuperada' => 0, ':manual' => 0, ':updated_by' => $user_id, ':created_at' => $now, ':updated_at' => $now ]);
                
                }
                
            }
            if($alumnos[$i]->nfinal4_desactualizada && $alumnos[$i]->updated_at_def_4){
                $per_desact['per4'] = true;
                
                if (!$alumnos[$i]->manual_4 && !$alumnos[$i]->recuperada_4) {
                    
                    DB::delete('DELETE FROM notas_finales WHERE asignatura_id=? and (manual is null or manual=0) and (recuperada is null or recuperada=0) and periodo_id=? and alumno_id=?', [ $asignatura_id, $alumnos[$i]->periodo_id4, $alumnos[$i]->alumno_id ]);
                    
                    $consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
                
                    DB::insert($consulta, [':alumno_id' => $alumnos[$i]->alumno_id, ':asignatura_id' => $asignatura_id, ':periodo_id' => $alumnos[$i]->periodo_id4, 
                                                    ':periodo' => 4, ':nota' => round($alumnos[$i]->def_materia_auto_4), ':recuperada' => 0, ':manual' => 0, ':updated_by' => $user_id, ':created_at' => $now, ':updated_at' => $now ]);
                
                }
                
            }
        }
        
        
        if ($per_desact['per1'] == true || $per_desact['per2'] == true || $per_desact['per3'] == true || $per_desact['per4'] == true) {
            
            $alumnos = DB::select(self::$consulta_alumnos_grupo_nota_final, [':grupo_id'=>$grupo_id, ':asign_id1'=>$asignatura_id, ':asign_id2'=>$asignatura_id, ':asign_id3'=>$asignatura_id, ':asign_id4'=>$asignatura_id, 
                                            ':asign_id5'=>$asignatura_id, ':asign_id6'=>$asignatura_id, ':asign_id7'=>$asignatura_id, ':asign_id8'=>$asignatura_id ]);
        
        }
        return ['alumnos' => $alumnos, 'per_desact' => $per_desact];

    }


    
    

	public static function calcularAsignaturaPeriodo($asignatura_id, $periodo_id, $num_periodo)
	{
		$user 			= User::fromToken();
		$now 			= Carbon::now('America/Bogota');

        /*
		DB::delete('DELETE nf FROM notas_finales nf
					WHERE (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0) and nf.asignatura_id=? and nf.periodo_id=?', 
                    [ $asignatura_id, $periodo_id ]);
        */
		DB::delete('DELETE FROM notas_finales
                        WHERE id IN (select * from
                            (SELECT id FROM notas_finales nf WHERE 
                                (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0) and nf.asignatura_id=? and nf.periodo_id=?
                                ORDER BY id
                            )  as res
                        )', 
                        [ $asignatura_id, $periodo_id ]);
        

		$consulta = 'SELECT r1.alumno_id,
			    cast(r1.DefMateria as decimal(4,0)) as def_materia_auto, r1.updated_at, r1.periodo_id
			FROM (
				SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
				FROM(
					SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
						sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
					FROM asignaturas asi 
					inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
					inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
					inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
					inner join periodos p1 on p1.numero=:num_periodo and p1.id=u.periodo_id and p1.deleted_at is null
					where asi.deleted_at is null and asi.id=:asignatura_id
					group by n.alumno_id, s.unidad_id, s.id
				)df1
				group by df1.alumno_id, df1.periodo_id
			)r1';
		
		$defi_autos = DB::select($consulta, [ ':num_periodo'=>$num_periodo, ':asignatura_id'=>$asignatura_id ]);
		$cant_def = count($defi_autos);
					
		for ($i=0; $i < $cant_def; $i++) { 
			
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, created_at, updated_at) 
						SELECT * FROM (SELECT '.$defi_autos[$i]->alumno_id.' as alumno_id, '.$asignatura_id.' as asignatura_id, '.$periodo_id.' as periodo_id, '.$num_periodo.' as periodo, '.$defi_autos[$i]->def_materia_auto.' as nota_asignatura, 0 as recuperada, 0 as manual, '.$user->user_id.' as crea, "'.$now.'" as fecha) AS tmp
						WHERE NOT EXISTS (
							SELECT id FROM notas_finales WHERE alumno_id='.$defi_autos[$i]->alumno_id.' and asignatura_id='.$asignatura_id.' and periodo_id='.$periodo_id.'
						) LIMIT 1';

			DB::select($consulta);
			
		}
		
		return 'Calculado';
	}



}



