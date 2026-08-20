<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/**
 * Las columnas de `ws_respuestas`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $actividad_resuelta_id
 * @property int $pregunta_id
 * @property int $tiempo
 * @property string $tipo_pregunta
 * @property int $opcion_id
 * @property int $opcion_cuadricula_id
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class WsRespuesta extends Model {
	protected $fillable 	= [];

	protected $table 		= 'ws_respuestas';
}