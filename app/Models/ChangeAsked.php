<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;


class ChangeAsked extends Model {
	protected $fillable = [];

	protected $table = 'change_asked';

	use SoftDeletes;
	protected $softDelete = true;

	static $consulta_all = 'SELECT *, c.id as asked_id FROM change_asked c
					left join change_asked_assignment a on a.id=c.assignment_id
					left join change_asked_data d on d.id=c.data_id
					WHERE c.asked_by_user_id=:user_id and c.year_asked_id=:year_id and c.deleted_at is null and c.answered_by is null';




	public static function verificar_pedido_actual($user_id, $year_id, $tipo_usu, $crear_if_non=true)
	{


		$pedido = DB::select( ChangeAsked::$consulta_all, [ ':user_id'	=> $user_id, ':year_id'	=> $year_id ]);

		if (count($pedido) > 0) {
			$pedido = $pedido[0];
			ChangeAsked::extender_datos($pedido);

		}else{

			if ($crear_if_non) {
				$pedido 					= new ChangeAsked;
				$pedido->asked_by_user_id 	= $user_id;
				$pedido->year_asked_id 		= $year_id;
				$pedido->tipo_user 			= $tipo_usu;
				$pedido->save();
				
				$pedido = DB::select( ChangeAsked::$consulta_all, [ ':user_id'	=> $user_id, ':year_id'	=> $year_id ]);
				$pedido = $pedido[0];
				ChangeAsked::extender_datos($pedido);
				
			}

		}



		return $pedido;
	}


	public static function pedido($asked_id)
	{

		$consulta = 'SELECT *, c.id as asked_id FROM change_asked c
					left join change_asked_assignment a on a.id=c.assignment_id
					left join change_asked_data d on d.id=c.data_id
					WHERE c.id=:asked_id and c.deleted_at is null';


		$pedido = DB::select( $consulta, [ ':asked_id'	=> $asked_id ]);

		if (count($pedido) > 0) {
			$pedido = $pedido[0];
			ChangeAsked::extender_datos($pedido);

		}

		return $pedido;
	}


	public static function extender_datos(&$pedido)
	{


		$consulta = 'SELECT * FROM users WHERE id=:user_id and deleted_at is null';
		$asked_by_user = DB::select($consulta, [ ':user_id'	=> $pedido->asked_by_user_id ]);
		if (count($asked_by_user)>0) {
			$pedido->asked_by_user = $asked_by_user[0];
		}

		if ($pedido->asked_to_user_id) {
			
			$consulta = 'SELECT * FROM users WHERE id=:user_id and deleted_at is null';
			$asked_to_user = DB::select($consulta, [ ':user_id'	=> $pedido->asked_to_user_id ]);
			if (count($asked_to_user)>0) {
				$pedido->asked_to_user = $asked_to_user[0];
			}
		}

		if ($pedido->asked_for_user_id) {
			
			$consulta = 'SELECT * FROM users WHERE id=:user_id and deleted_at is null';
			$asked_for_user = DB::select($consulta, [ ':user_id'	=> $pedido->asked_for_user_id ]);
			if (count($asked_for_user)>0) {
				$pedido->asked_for_user = $asked_for_user[0];
			}
		}
		/*
		if ($pedido->data_id) {
			
			$consulta = 'SELECT * FROM users WHERE id=:user_id and deleted_at is null';
			$asked_for_user = DB::select($consulta, [ ':user_id'	=> $pedido->user_id ]);
			if (count($asked_for_user)>0) {
				$pedido->asked_for_user = $asked_for_user[0];
			}
		}
		*/


	}


}