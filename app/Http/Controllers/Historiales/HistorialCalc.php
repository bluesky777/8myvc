<?php namespace App\Http\Controllers\Historiales;



use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

use App\User;
use App\Support\HistorialDeLasDosTablas;
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

        // `cant_cambios` de `auditoria` cuando el ingreso tiene algo allí (contrato 5).
        return HistorialDeLasDosTablas::conCambiosDeAuditoria($historial, (int) $user_id);

	}


	public function intentos_fallidos_de_usuario($user_id)
	{

			# Intentos de Logueo Fallidos, de `bitacoras` hasta el corte y de `auditoria`
			# después (contrato 5). **Sigue buscando por el `user_id`**, que nunca casa con
			# el nombre de cuenta: es el fallo que fija `HistorialesTest`, y arreglarlo es
			# una decisión aparte, no un efecto de cambiar de tabla.
			$intentos_fallidos = HistorialDeLasDosTablas::intentosFallidos($user_id);

                            
        return $intentos_fallidos;

	}



}