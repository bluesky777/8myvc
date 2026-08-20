<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/**
 * Las columnas de `frases`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $frase
 * @property string $tipo_frase
 * @property int $year_id
 * @property int $created_by
 * @property int $updated_by
 * @property int $deleted_by
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class Frase extends Model {
	

	protected $fillable = [];
	
	use SoftDeletes;
	protected $softDelete = true;
}