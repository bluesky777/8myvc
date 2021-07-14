<?php namespace App\Http\Controllers\Historiales;



use Request;
use DB;
use Hash;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Periodo;



class HistorialCalc {


	public function historial_sesiones_de_usuario($user_id)
	{

        # Historial de sesiones
        $historial = DB::select('SELECT h.*, count(b.id) as cant_cambios FROM historiales h  
								left join bitacoras b  on b.historial_id=h.id 
								WHERE h.user_id=? 
								group by h.id
								order by h.created_at desc 
								limit 50', [ $user_id ]);
                            
        return $historial;

	}


	public function intentos_fallidos_de_usuario($user_id)
	{

			# Intentos de Logueo Fallidos
			$intentos_fallidos = DB::select('SELECT * FROM bitacoras 
							WHERE affected_element_type="intento_login" and affected_person_name=? and deleted_at is null 
							order by created_at desc limit 50', 
							[ $user_id ]);

                            
        return $intentos_fallidos;

	}



}