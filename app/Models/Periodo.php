<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use \stdClass;
use Illuminate\Support\Facades\DB;
use App\User;
/**
 * Las columnas de `periodos`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $numero
 * @property ?string $fecha_inicio
 * @property ?string $fecha_fin
 * @property int $actual
 * @property int $profes_pueden_editar_notas
 * @property int $profes_pueden_nivelar
 * @property int $year_id
 * @property ?string $fecha_plazo
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property int $cant_perdidas  cuántas pierde el alumno en ese periodo
 * @property int $cantNotasPerdidas  lo mismo, con el nombre que usa el otro informe
 * @property mixed $nota_asignatura  la nota de la asignatura en ese periodo
 * @property float $sumatoria  el acumulado que va sumando el informe
 * @property int $periodo_id  copia de `id`, que es como lo pide la plantilla
 */

class Periodo extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;


	public static function hastaPeriodoN($year_id, $periodo_a_calcular=10)
	{
		$consulta = 'SELECT id as periodo_id, id, actual, created_at, created_by, deleted_at, fecha_fin, fecha_inicio, fecha_plazo, numero, updated_at, updated_by, year_id
					FROM periodos p WHERE p.year_id=:year_id and p.numero <=:periodo_a_calcular and p.deleted_at is null order by p.numero';
		$periodos = DB::select($consulta, ['year_id' => $year_id, 'periodo_a_calcular' => $periodo_a_calcular] );
		return $periodos;
	}

	public static function hastaPeriodo($year_id, $periodos_a_calcular='de_usuario', $numero_periodo=0)
	{
		$periodos = new stdClass();


		// Solo los periodos pasados hasta EL ACTUAL si así fue solicitado
		if ($periodos_a_calcular == 'de_colegio') {
			$periodo_actual = Periodo::where('actual', true)
									->where('year_id', $year_id)->first();

			$periodos = Periodo::where('numero', '<=', $periodo_actual->numero)
								->where('year_id', '=', $year_id)->get();


		// Solo los periodos pasados hasta EL DE EL USUARIO
		}elseif($periodos_a_calcular == 'de_usuario'){
			$periodos = Periodo::where('numero', '<=', $numero_periodo)
								->where('year_id', '=', $year_id)->get();

		}elseif($periodos_a_calcular == 'todos'){
			$periodos = Periodo::where('year_id', '=', $year_id)->get();
		}

		return $periodos;
	}

	public static function delYear($year_id)
	{
		$consulta = 'SELECT * FROM periodos p WHERE p.year_id=:year_id and p.deleted_at is null order by p.numero';
		$periodos = DB::select($consulta, ['year_id' => $year_id]);
		return $periodos;
	}
	
	


}

