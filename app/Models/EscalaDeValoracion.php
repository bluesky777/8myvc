<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/**
 * Las columnas de `escalas_de_valoracion`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $desempenio
 * @property string $valoracion
 * @property int $porc_inicial
 * @property int $porc_final
 * @property string $descripcion
 * @property int $orden
 * @property int $perdido
 * @property int $year_id
 * @property string $icono_infantil
 * @property string $icono_adolescente
 * @property int $created_by
 * @property int $updated_by
 * @property int $deleted_by
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * --- fin de las columnas generadas ---
 */



class EscalaDeValoracion extends Model {
	protected $fillable = [];

	protected $table = 'escalas_de_valoracion';

	use SoftDeletes;
	protected $softDelete = true;
	
	
	
	public static function valoracion($nota, $escalas_val)
	{
		$nota = round($nota);

		foreach ($escalas_val as $key => $escala_val) {
			//Debugging::pin($escala_val->porc_inicial, $escala_val->porc_final, $nota);

			if (($escala_val->porc_inicial <= $nota) &&  ($escala_val->porc_final >= $nota)) {
				return $escala_val;
			}
		}
		return (object)[ 'desempenio' => '' ];
	}
	
	
}