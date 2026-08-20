<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `debugging`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $accion
 * @property string $dato1
 * @property string $dato2
 * @property int $created_by
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */

class Debugging extends Model {
	

	protected $table = 'debugging';
	

	public static function pin($accion, $dato1=null, $dato2=null, $created_by=null)
	{
		$deb 			= new Debugging;
		$deb->accion 	= $accion;
		$deb->dato1 	= $dato1;
		$deb->dato2 	= $dato2;
		$deb->created_by = $created_by;
		$deb->save();
		return $deb;
	}
}