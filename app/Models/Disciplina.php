<?php namespace App\Models;

use DB;


class Disciplina {

    public static $reinicia_por_periodo = -1;


	public static function situaciones_year($alumno_id, $year_id, $periodo_id){

        if (self::$reinicia_por_periodo > -1) {
            $consulta = 'SELECT c.reinicia_por_periodo FROM dis_configuraciones c 
                WHERE C.year_id=:year_id AND c.deleted_at is null';

            $config = DB::select($consulta, [ ':year_id' => $year_id ]);
            if (count($config) > 0) {
                self::$reinicia_por_periodo = $config[0]->reinicia_por_periodo;
            }else{
                self::$reinicia_por_periodo = 0;
            }
        }
        
        $situaciones = [];

        if (self::$reinicia_por_periodo == 0) {
            
            $consulta = 'SELECT p.*, o.ordinal, o.descripcion as descrip_ord, o.pagina, per.numero, 
                    pro.nombres as nombres_profesor, pro.apellidos as apellidos_profesor 
                FROM dis_procesos p 
                LEFT JOIN dis_proceso_ordinales ord ON ord.proceso_id=p.id and ord.deleted_at is null
                LEFT JOIN dis_ordinales o ON o.id=ord.ordinal_id and o.deleted_at is null
                LEFT JOIN periodos per ON per.id=p.periodo_id and per.deleted_at is null
                LEFT JOIN profesores pro ON pro.id=p.profesor_id and pro.deleted_at is null
                WHERE p.alumno_id=:alumno_id and p.year_id=:year_id and p.become_id is null and p.deleted_at is null';
            $situaciones = DB::select($consulta, [
                ':alumno_id'	=>$alumno_id, 
                ':year_id'	=>$year_id
            ]);

        }else{


            $consulta = 'SELECT p.*, o.ordinal, o.descripcion as descrip_ord, o.pagina, per.numero, 
                    pro.nombres as nombres_profesor, pro.apellidos as apellidos_profesor 
                FROM dis_procesos p 
                LEFT JOIN dis_proceso_ordinales ord ON ord.proceso_id=p.id and ord.deleted_at is null
                LEFT JOIN dis_ordinales o ON o.id=ord.ordinal_id and o.deleted_at is null
                LEFT JOIN periodos per ON per.id=p.periodo_id and per.deleted_at is null
                LEFT JOIN profesores pro ON pro.id=p.profesor_id and pro.deleted_at is null
                WHERE p.alumno_id=:alumno_id and p.periodo_id=:periodo_id and p.become_id is null and p.deleted_at is null';
            $situaciones = DB::select($consulta, [
                ':alumno_id'	=>$alumno_id, 
                ':periodo_id'	=>$periodo_id
            ]);


        }
        
        

		return $situaciones;

		 
	}



	public static function situaciones_year_alumno($alumno_id, $year_id){

        $consulta = 'SELECT p.*, o.ordinal, o.descripcion as descrip_ord, o.pagina, per.numero, 
                pro.nombres as nombres_profesor, pro.apellidos as apellidos_profesor 
            FROM dis_procesos p 
            LEFT JOIN dis_proceso_ordinales ord ON ord.proceso_id=p.id and ord.deleted_at is null
            LEFT JOIN dis_ordinales o ON o.id=ord.ordinal_id and o.deleted_at is null
            LEFT JOIN periodos per ON per.id=p.periodo_id and per.deleted_at is null
            LEFT JOIN profesores pro ON pro.id=p.profesor_id and pro.deleted_at is null
            WHERE p.alumno_id=:alumno_id and p.year_id=:year_id and p.become_id is null and p.deleted_at is null';
        $situaciones = DB::select($consulta, [
            ':alumno_id'	=>$alumno_id, 
            ':year_id'	=>$year_id
        ]);
        

		return $situaciones;

		 
	}


}