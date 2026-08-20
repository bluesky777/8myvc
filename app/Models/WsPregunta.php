<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/**
 * Las columnas de `ws_preguntas`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $enunciado
 * @property int $actividad_id
 * @property int $contenido_id
 * @property string $ayuda
 * @property string $tipo_pregunta
 * @property int $orden
 * @property int $puntos
 * @property int $duracion
 * @property int $aleatorias
 * @property int $opcion_otra
 * @property string $texto_arriba
 * @property string $texto_abajo
 * @property int $added_by
 * @property int $deleted_by
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property array $opciones  las opciones de la pregunta
 */



class WsPregunta extends Model {
	protected $fillable 	= [];

	use SoftDeletes;
	protected $softDelete 	= true;
	protected $table 		= 'ws_preguntas';
}