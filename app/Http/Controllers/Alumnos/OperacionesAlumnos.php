<?php namespace App\Http\Controllers\Alumnos;

use Request;
use DB;
use Hash;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Periodo;



class OperacionesAlumnos {


	public function dividir_nombre($name)
	{
		$parts          = explode(' ', $name);
		$name_first     = array_shift($parts);
		$name_last      = trim(implode(' ', $parts));
		return ['first' => $name_first, 'last' => $name_last ];

	}



	public function recorrer_y_dividir_nombres(&$alumnos)
	{
		$cant = count($alumnos);

		for ($i=0; $i < $cant; $i++) { 
			$alumnos[$i]->nombres_divididos     = $this->dividir_nombre($alumnos[$i]->nombres);
			$alumnos[$i]->apellidos_divididos   = $this->dividir_nombre($alumnos[$i]->apellidos);

			if($alumnos[$i]->has_sisben){
				$alumnos[$i]->sisben = $alumnos[$i]->nro_sisben;
			}else{
				$alumnos[$i]->sisben = 'No aplica';
			}
			if($alumnos[$i]->has_sisben_3){
				$alumnos[$i]->sisben_3 = $alumnos[$i]->nro_sisben_3;
			}else{
				$alumnos[$i]->sisben_3 = 'No aplica';
			}
		}
		return $alumnos;
	}
	
	
	
	public function username_no_repetido($username_a_verificar)
	{
		$username_a_verificar = str_replace(' ', '', $username_a_verificar);
		
		$i = 0;
		while(sizeof((array)DB::select('SELECT username FROM users WHERE username=?', [$username_a_verificar])) > 0 ){
			$i++;
			$username_a_verificar = $username_a_verificar.$i;
		}
		return $username_a_verificar;
	}

}