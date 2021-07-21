<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;

use App\Models\Debugging;

class Unidad extends Model {
	use SoftDeletes;
	
	protected $fillable = [];
	protected $table = 'unidades';

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;




	public function subunidades()
	{
		return $this->hasMany('Subunidad');
	}



	public static function arreglarOrden($unidadesT, $asignatura_id, $periodo_id)
	{
		
		for ($i=0; $i < count($unidadesT); $i++) { 
			DB::update('UPDATE unidades SET orden=? WHERE id=?', [$i, $unidadesT[$i]->id]);
			$unidadesT[$i]->orden = $i;

			for ($j=0; $j < count($unidadesT[$i]->subunidades); $j++) { 
				DB::update('UPDATE subunidades SET orden=? WHERE id=?', [$j, $unidadesT[$i]->subunidades[$j]->id]);
				$unidadesT[$i]->subunidades[$j]->orden = $j;
			}
			
		}
		


		return $unidadesT;
	}



	
	public static function deAsignatura($asignatura_id, $periodo_id)
	{
		$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
						u.asignatura_id, u.orden as orden_unidad, u.periodo_id
					FROM unidades u
					where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null
					order by u.orden, u.id';

		$unidades = DB::select(DB::raw($consulta), array(
			':asignatura_id'	=> $asignatura_id,
			':periodo_id'		=> $periodo_id
		));

		return $unidades;
	}


	public static function deAsignaturaCalculada($alumno_id, $asignatura_id, $periodo_id, $con_desempenio='sin_desempenio', $year_id=0, $nota_minima=70)
	{

		if($con_desempenio==='fortaleza_debilidad'){
			
			$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, IF(ROUND(sum((n.nota*s.porcentaje/100))) < :nota_minima, "Debilidad", "Fortaleza") as desempenio,
							u.asignatura_id, u.orden as orden_unidad, u.periodo_id, ROUND(sum((n.nota*s.porcentaje/100))) as nota_unidad
						FROM unidades u
						left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id
						where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null
						group by u.id 
						order by u.orden, u.id';

			$unidades = DB::select(DB::raw($consulta), [
				':nota_minima'		=> $nota_minima,
				':alumno_id'		=> $alumno_id,
				':asignatura_id'	=> $asignatura_id,
				':periodo_id'		=> $periodo_id
			]);


			
		} else if ($con_desempenio == 'con_desempenio') {
			
			$consulta = 'SELECT * 
						FROM
						(SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
							u.asignatura_id, u.orden as orden_unidad, u.periodo_id, ROUND(sum((n.nota*s.porcentaje/100))) as nota_unidad
						FROM unidades u
						left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id
						where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null
						group by u.id ) r1
						left join escalas_de_valoracion e ON e.porc_inicial<=r1.nota_unidad and e.porc_final>=r1.nota_unidad and e.deleted_at is null and e.year_id=:year_id
						order by r1.orden_unidad, r1.unidad_id';

			$unidades = DB::select(DB::raw($consulta), [
				':alumno_id'		=> $alumno_id,
				':asignatura_id'	=> $asignatura_id,
				':periodo_id'		=> $periodo_id,
				':year_id'			=> $year_id,
			]);
			
		}else{
			$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
							u.asignatura_id, u.orden as orden_unidad, u.periodo_id, ROUND(sum((n.nota*s.porcentaje/100))) as nota_unidad
						FROM unidades u
						left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id
						where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null
						group by u.id 
						order by u.orden, u.id';

			$unidades = DB::select(DB::raw($consulta), [
				':alumno_id'		=> $alumno_id,
				':asignatura_id'	=> $asignatura_id,
				':periodo_id'		=> $periodo_id
			]);
		}

		return $unidades;
	}


	public static function informacionAsignatura($asignatura_id, $periodo_id)
	{
		$result = new \stdClass;

		
		$consulta = 'SELECT id, definicion, porcentaje, orden 
					FROM unidades
					where asignatura_id=:asignatura_id and periodo_id=:periodo_id and deleted_at is null
					order by orden';

		$unidades = DB::select($consulta, [
			':asignatura_id'	=> $asignatura_id,
			':periodo_id'		=> $periodo_id
		]);

		$porc_unidades = 0;
		$result->porc_subunidades_incorrecto = false;
		$result->porc_notas_incorrecto = false;

		foreach ($unidades as $unidad) {
			
			$porc_unidades += $unidad->porcentaje;

			$consulta = 'SELECT id, definicion, porcentaje, orden 
						FROM subunidades
						where unidad_id=:unidad_id and deleted_at is null
						order by orden';

			$unidad->subunidades = DB::select(DB::raw($consulta), array(
				':unidad_id'	=> $unidad->id,
			));

			$porc_subunidades = 0;

			foreach ($unidad->subunidades as $subunidad) {
				$porc_subunidades += $subunidad->porcentaje;

				#$notas = Nota::where('subunidad_id', $subunidad->id)->get();
				$notas = DB::select('SELECT * FROM notas WHERE deleted_at is null and subunidad_id=?', [$subunidad->id]);

				$subunidad->cantNotas = count($notas);

				if ($subunidad->cantNotas == 0) {
					$result->porc_notas_incorrecto = true;
				}

			}

			$unidad->porc_subunidades = $porc_subunidades ;

			if ($unidad->porc_subunidades != 100) {
				$result->porc_subunidades_incorrecto = true;
			}

		}


		$result->porc_unidades = $porc_unidades;
		$result->items = $unidades;

		return $result;
	}

}