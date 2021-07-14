<?php namespace App\Http\Controllers\Informes;

use DB;
use App\User;

class CalcularDefinitivasAlumno {
	
	
	
	public $consulta_per4 = '';
	
	
	
	public function calcularAlumno($alumno_id, $grupo_id, $periodo_a_calcular=1)
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

