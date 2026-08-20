<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
/**
 * Las columnas de `ws_opciones`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $definicion
 * @property int $pregunta_id
 * @property int $image_id
 * @property int $orden
 * @property int $is_correct
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class WsOpcion extends Model {
	protected $fillable 	= [];

	protected $table 		= 'ws_opciones';
}