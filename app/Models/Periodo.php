<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use \stdClass;
use DB;
use App\User;

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

