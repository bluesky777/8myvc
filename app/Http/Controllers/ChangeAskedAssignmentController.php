<?php namespace App\Http\Controllers;


use Request;
use DB;

use App\User;
use App\Models\Profesor;

use App\Http\Controllers\Alumnos\Solicitudes;

use Carbon\Carbon;


class ChangeAskedAssignmentController extends Controller {


	public function putSolicitarMateria()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');

        if ($user->tipo == 'Profesor') {

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
		
		return ['msg'=> 'No puedes'];
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

        if ($user->tipo == 'Profesor') {

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
		
		return ['msg'=> 'No puedes'];
	}



}