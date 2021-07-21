<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;

use App\Models\Nota;
use App\User;

class Subunidad extends Model {
	use SoftDeletes;
	
	protected $fillable = [];
	protected $table = 'subunidades';

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;



	public static function deUnidad($unidad_id)
	{
		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at
					FROM subunidades s
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';

		$unidades = DB::select(DB::raw($consulta), array(
			':unidad_id'	=> $unidad_id
		));

		return $unidades;
	}


	public static function deUnidad2($alumno_id, $unidad_id, $year_id)
	{
		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, ROUND((n.nota*s.porcentaje/100), 1) as valor_nota, n.nota, e.desempenio, 
						CONCAT("<div class=\"row\">
							<div class=\"col-lg-9 col-xs-9 subunidad-definicion no-padding-right\">", s.definicion, "</div>
							<div class=\"col-lg-1 col-xs-1 subunidad-porc\">", s.porcentaje,"</div>
							<div style=\"font-size: 5pt; line-height: 2;\" class=\"col-lg-1 col-xs-1 subunidad-nota\">", e.desempenio,"</div>
							<div class=\"col-lg-1 col-xs-1 subunidad-nota\">
								<span ", IF(n.nota<:min_aceptada, "class=\"nota-perdida-bold\" ", ""), " uib-tooltip=\"Valor nota: {{::subunidad.valor_nota}}\">", n.nota,"</div>
						</div>") as fila_subunidad
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and e.porc_final>=n.nota and e.deleted_at is null and e.year_id=:year_id
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';

		$unidades = DB::select(DB::raw($consulta), array(
			':min_aceptada' => User::$nota_minima_aceptada, ':alumno_id'	=> $alumno_id, ':unidad_id'	=> $unidad_id, ':year_id'	=> $year_id 
		));

		return $unidades;
	}
	


	public static function deUnidadCalculada($alumno_id, $unidad_id, $year_id)
	{
		$consulta = 'SELECT n.id as nota_id, s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, ROUND((n.nota*s.porcentaje/100), 1) as valor_nota, n.nota, e.desempenio, 
						s.definicion, s.porcentaje, e.desempenio, IF(n.nota<:min_aceptada, "nota-perdida-bold", "") as clase_perdida, n.nota
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and e.porc_final>=n.nota and e.deleted_at is null and e.year_id=:year_id
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';
		//  limit 1
		$unidades = DB::select(DB::raw($consulta), array(
			':min_aceptada' => User::$nota_minima_aceptada, ':alumno_id'	=> $alumno_id, ':unidad_id'	=> $unidad_id, ':year_id'	=> $year_id 
		));

		return $unidades;
	}
	

	public static function notas($subunidad_id)
	{
		$notas = Nota::where('subunidad_id', '=', $subunidad_id)->get();
		return $notas;
	}

	public static function perdidasDeUnidad($unidad_id, $alumno_id)
	{
		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, n.id as nota_id, n.nota
					FROM subunidades s
					inner join notas n on n.subunidad_id=s.id and n.alumno_id=:alumno_id and n.nota<:nota_minima
					where s.unidad_id=:unidad_id and s.deleted_at is null';

		$subunidades = DB::select(DB::raw($consulta), array(
			':alumno_id'	=> $alumno_id,
			':nota_minima'	=> User::$nota_minima_aceptada,
			':unidad_id'	=> $unidad_id,
		));

		return $subunidades;
	}
	
	
	
	public static function perdidasDeAsignatura($asignatura_id, $alumno_id, $periodo_id)
	{
		$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
						u.asignatura_id, u.orden as orden_unidad, u.periodo_id, count(n.nota) as cant_perdidas
					FROM unidades u
					left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id and n.nota<:nota_minima
					where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null
					group by u.id 
					order by u.orden, u.id';

		$subunidades = DB::select(DB::raw($consulta), array(
			':alumno_id'	=> $alumno_id,
			':nota_minima'	=> User::$nota_minima_aceptada,
			':asignatura_id'=> $asignatura_id,
			':periodo_id'	=> $periodo_id,
		));

		return $subunidades;
	}
	
	
}