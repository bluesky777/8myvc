<?php namespace App\Models;

use App\Support\NotaImpresa;
use App\Support\SellaConElReloj;
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
 * @property ?string $descripcion
 * @property int $orden
 * @property int $perdido
 * @property int $year_id
 * @property ?string $icono_infantil
 * @property ?string $icono_adolescente
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */



class EscalaDeValoracion extends Model {
	protected $fillable = [];

	protected $table = 'escalas_de_valoracion';

	use SoftDeletes;
	use SellaConElReloj;
	protected $softDelete = true;
	
	
	
	public static function valoracion($nota, $escalas_val)
	{
		// **El `round()` que había aquí se retiró el 13 sep 2026, y no era inocuo.**
		// `notas_finales.nota` es `decimal(7,4)` desde `2026_08_30_200000`, así que
		// redondear subía un 45,5 a 46 y lo imprimía SUPERIOR donde el colegio había
		// escrito que ALTO llega hasta 45 — y el camino de SQL, que no redondeaba, lo
		// dejaba directamente **sin nivel**. Los trece sitios usan ahora la misma regla:
		// la banda llega hasta justo antes del primer entero de la siguiente.
		//
		// **Y el 24 sep 2026 vuelve el redondeo, esta vez por decisión de producto**:
		// la banda es la de la nota IMPRESA (`NotaImpresa`), así que 45,5 se imprime
		// 46 y es SUPERIOR. Ahora los caminos de SQL redondean igual (`ROUND(x, 0)`).
		$nota = NotaImpresa::valor($nota);

		foreach ($escalas_val as $key => $escala_val) {
			//Debugging::pin($escala_val->porc_inicial, $escala_val->porc_final, $nota);

			if (($escala_val->porc_inicial <= $nota) && ($nota < $escala_val->porc_final + 1)) {
				return $escala_val;
			}
		}
		return (object)[ 'desempenio' => '' ];
	}
	
	
}