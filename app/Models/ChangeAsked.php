<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
use App\Support\UsuarioSinCredenciales;
/**
 * Las columnas de `change_asked`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $asked_by_user_id
 * @property ?int $asked_to_user_id
 * @property ?int $asked_for_user_id
 * @property ?string $tipo_user
 * @property ?int $data_id
 * @property ?int $assignment_id
 * @property ?int $comentario_pedido
 * @property ?string $comentario_respuesta
 * @property ?string $rechazado_at
 * @property ?string $accepted_at
 * @property ?int $periodo_asked_id
 * @property ?int $year_asked_id
 * @property ?int $answered_by
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */


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


		// Los tres bloques de aquí abajo hacían `SELECT * FROM users` y colgaban
		// del pedido la fila entera, con `password` dentro: una consulta cruda no
		// tiene modelo al que ocultarle nada, así que el `$hidden` de `App\User`
		// no intervenía. Y ningún cliente lee estos tres objetos. Ver 05 §38.
		$asked_by_user = UsuarioSinCredenciales::porId($pedido->asked_by_user_id);
			if ($asked_by_user !== null) {
				$pedido->asked_by_user = $asked_by_user;
			}

		if ($pedido->asked_to_user_id) {
			
			$asked_to_user = UsuarioSinCredenciales::porId($pedido->asked_to_user_id);
			if ($asked_to_user !== null) {
				$pedido->asked_to_user = $asked_to_user;
			}
		}

		if ($pedido->asked_for_user_id) {
			
			$asked_for_user = UsuarioSinCredenciales::porId($pedido->asked_for_user_id);
			if ($asked_for_user !== null) {
				$pedido->asked_for_user = $asked_for_user;
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