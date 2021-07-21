<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;


class ChangeAskedDetails {
	protected $fillable = [];

	protected $table = 'change_asked_data';

	use SoftDeletes;
	protected $softDelete = true;

	static $consulta = 'SELECT c.*, c.id as asked_id, d.*,
							i.nombre as image_new_nombre, i2.nombre as foto_new_nombre, i3.nombre as image_to_delete_nombre
						FROM change_asked c
						left join change_asked_assignment a on a.id=c.assignment_id
						left join change_asked_data d on d.id=c.data_id
						left join images i on i.id=d.image_id_new and i.deleted_at is null
						left join images i2 on i2.id=d.foto_id_new and i2.deleted_at is null
						left join images i3 on i3.id=d.image_to_delete_id and i3.deleted_at is null
						WHERE c.id=:asked_id and c.deleted_at is null';




	public static function detalles($asked_id)
	{
		$detalles = DB::select( ChangeAskedDetails::$consulta, [ ':asked_id'	=> $asked_id ] )[0];


		return $detalles;
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
		
		if ($pedido->data_id) {
			
			
		}
		


	}


}