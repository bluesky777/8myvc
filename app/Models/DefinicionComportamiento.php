<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `definiciones_comportamiento`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $comportamiento_id
 * @property int $frase_id
 * @property string $frase
 * @property string $fecha
 * @property int $orden
 * @property int $created_by
 * @property int $updated_by
 * @property int $deleted_by
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */

class DefinicionComportamiento extends Model {
	protected $fillable = [];
	protected $table = "definiciones_comportamiento";

	use SoftDeletes;
	protected $softDelete = true;


	public static function frases($comport_id)
	{
		$consulta = 'SELECT dc.id, IFNULL(f.frase, dc.frase) as frase, dc.frase_id, dc.comportamiento_id, 
						dc.created_by, dc.created_at, f.tipo_frase
					FROM definiciones_comportamiento dc
					left join frases f on f.id=dc.frase_id and f.deleted_at is null
					where dc.deleted_at is null and dc.comportamiento_id=?';

		$definiciones = DB::select($consulta, [$comport_id]);

		return $definiciones;
	}

}