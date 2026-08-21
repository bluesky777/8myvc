<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\Autoriza;
use App\User;
use App\Models\Profesor;
use App\Models\ChangeAskedDetails;

use App\Http\Controllers\Alumnos\Solicitudes;

use Carbon\Carbon;


class ChangeAskedAssignmentController extends Controller {


	public function putSolicitarMateria()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');

        // Antes esto era un `if` sin `else` que devolvía **200 con
        // `['msg' => 'No puedes']`** y no escribía nada. El front hace
        // `.then(r => $ctrl.pedidos.push(r.pedido))`, así que metía un
        // `undefined` en la lista y pintaba una solicitud en blanco que
        // desaparecía al recargar. Quien lo veía era el administrativo, que
        // `auth.personal` deja pasar. Solo el docente pide cambios de
        // asignatura —un superusuario no pide, hace—, así que el criterio no
        // cambia: cambia que ahora se dice. Ver 05 §48.
        Autoriza::exigir($user->tipo == 'Profesor', 'Solo un docente puede pedir una materia.');


		$consulta       = 'INSERT INTO change_asked_assignment(materia_to_add_id, grupo_to_add_id, creditos_new, created_at) VALUES(?, ?, ?, ?)';
		DB::insert($consulta, [ Request::input('materia_id'), Request::input('grupo_id'), Request::input('creditos'), $now ]);
        $last_id 	    = DB::getPdo()->lastInsertId();

		$consulta       = 'INSERT INTO change_asked(asked_by_user_id, year_asked_id, assignment_id, created_at, tipo_user) VALUES(?, ?, ?, ?, "Profesor")';
        DB::insert($consulta, [$user->user_id, $user->year_id, $last_id, $now]);
        $last_id 	    = DB::getPdo()->lastInsertId();
        
        $solicitudes 	= new Solicitudes();
        $pedido 		= $solicitudes->asignatura_a_cambiar_de_profesor( $last_id );
                
		return [ 'pedido' => $pedido ];
	}


	
	public function putVerDetalles(){
		$user 		= User::fromToken();
		$asked_id 	= Request::input('asked_id');
		$detalles 	= ChangeAskedDetails::detalles($asked_id);
		return [ 'detalles' => $detalles ];
	}



	public function putPedirQuitarAsignatura()
	{
		$user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');

        // Antes esto era un `if` sin `else` que devolvía **200 con
        // `['msg' => 'No puedes']`** y no escribía nada. El front hace
        // `.then(r => $ctrl.pedidos.push(r.pedido))`, así que metía un
        // `undefined` en la lista y pintaba una solicitud en blanco que
        // desaparecía al recargar. Quien lo veía era el administrativo, que
        // `auth.personal` deja pasar. Solo el docente pide cambios de
        // asignatura —un superusuario no pide, hace—, así que el criterio no
        // cambia: cambia que ahora se dice. Ver 05 §48.
        Autoriza::exigir($user->tipo == 'Profesor', 'Solo un docente puede pedir que le quiten una asignatura.');


		$consulta       = 'INSERT INTO change_asked_assignment(asignatura_to_remove_id, created_at) VALUES(?, ?)';
		DB::insert($consulta, [ Request::input('asignatura_id'), $now ]);
        $last_id 	    = DB::getPdo()->lastInsertId();

		$consulta       = 'INSERT INTO change_asked(asked_by_user_id, tipo_user, year_asked_id, assignment_id, created_at) VALUES(?, "Profesor", ?, ?, ?)';
        DB::insert($consulta, [$user->user_id, $user->year_id, $last_id, $now]);
        $last_id 	    = DB::getPdo()->lastInsertId();
        
        $solicitudes 	= new Solicitudes();
        $pedido 		= $solicitudes->asignatura_a_cambiar_de_profesor( $last_id );
                
		return [ 'pedido' => $pedido ];
	}



}