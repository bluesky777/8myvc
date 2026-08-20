<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `change_asked_data`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property ?string $no_matricula
 * @property ?string $nombres_new
 * @property ?int $nombres_accepted
 * @property ?string $apellidos_new
 * @property ?int $apellidos_accepted
 * @property ?string $sexo_new
 * @property ?int $sexo_accepted
 * @property ?string $fecha_nac_new
 * @property ?int $fecha_nac_accepted
 * @property ?int $ciudad_nac_new
 * @property ?int $ciudad_nac_accepted
 * @property ?int $tipo_doc_new
 * @property ?int $tipo_doc_accepted
 * @property ?string $documento_new
 * @property ?int $documento_accepted
 * @property ?int $ciudad_doc_new
 * @property ?int $ciudad_doc_accepted
 * @property ?string $tipo_sangre_new
 * @property ?int $tipo_sangre_accepted
 * @property ?string $eps_new
 * @property ?int $eps_accepted
 * @property ?string $telefono_new
 * @property ?int $telefono_accepted
 * @property ?string $celular_new
 * @property ?int $celular_accepted
 * @property ?string $direccion_new
 * @property ?int $direccion_accepted
 * @property ?string $barrio_new
 * @property ?int $barrio_accepted
 * @property ?string $estrato_new
 * @property ?int $estrato_accepted
 * @property ?int $ciudad_resid_new
 * @property ?int $ciudad_resid_accepted
 * @property ?string $religion_new
 * @property ?int $religion_accepted
 * @property ?string $email_new
 * @property ?int $email_accepted
 * @property ?string $facebook_new
 * @property ?int $facebook_accepted
 * @property ?int $pazysalvo_new
 * @property ?int $pazysalvo_accepted
 * @property ?int $foto_id_new
 * @property ?int $foto_id_accepted
 * @property ?int $image_id_new
 * @property ?int $image_id_accepted
 * @property ?int $firma_id_new
 * @property ?int $firma_id_accepted
 * @property ?int $image_to_delete_id
 * @property ?int $image_to_delete_accepted
 * @property ?int $created_by
 * @property ?int $deleted_by
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */


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