<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/**
 * Las columnas de `materias`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $materia
 * @property string $alias
 * @property int $area_id
 * @property int $orden
 * @property int $created_by
 * @property int $updated_by
 * @property int $deleted_by
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class Materia extends Model {
	use SoftDeletes;
	
	protected $fillable = [];

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;
}