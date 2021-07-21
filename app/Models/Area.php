<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;
use App\Models\EscalaDeValoracion;
use \Log;


class Area extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;


	public static function agrupar_asignaturas($grupo_id, $asignaturas, $escalas)
	{

		// Agrupamos por áreas
		$consulta 	= 'SELECT ar.id as area_id, ar.orden, ar.nombre as area_nombre, ar.alias as area_alias
					FROM asignaturas a
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
					where a.deleted_at is null and a.grupo_id=? and a.profesor_id is not null
					group by ar.id order by ar.orden';

		$areas 		= DB::select($consulta, [ $grupo_id ]);
		$cantAr 	= count($areas);
		$cantAs 	= count($asignaturas);

		for ($i=0; $i < $cantAr; $i++) {
			$found = 0;
			$areas[$i]->sumatoria 		= 0;
			$areas[$i]->asignaturas 	= [];
			$areas[$i]->creditos 		= 0;
			$areas[$i]->ausencias 		= 0;
			$areas[$i]->tardanzas 		= 0;
			$areas[$i]->per1 			= 0;
			$areas[$i]->per2 			= 0;
			$areas[$i]->per3 			= 0;
			$areas[$i]->per4 			= 0;

			for ($j=0; $j < $cantAs; $j++) {
				if ($areas[$i]->area_id == $asignaturas[$j]->area_id) {
					$found += 1;
					$areas[$i]->sumatoria += $asignaturas[$j]->nota_asignatura;
					if (isset($asignaturas[$j]->creditos)) {
						$areas[$i]->creditos += $asignaturas[$j]->creditos;
					}

					if(isset($asignaturas[$j]->total_ausencias)){
				        $areas[$i]->ausencias += $asignaturas[$j]->total_ausencias;
				    }else if(isset($asignaturas[$j]->ausencias)){
				        $areas[$i]->ausencias += $asignaturas[$j]->ausencias;
				    }

					if(isset($asignaturas[$j]->total_tardanzas)){
					    $areas[$i]->tardanzas += $asignaturas[$j]->total_tardanzas;
					} else if(isset($asignaturas[$j]->tardanzas)){
					    $areas[$i]->tardanzas += $asignaturas[$j]->tardanzas;
					}

					// Bol2 no tiene definitivas, creo que tienen notas_finales. Pero bolfinal sí.
					if(isset($asignaturas[$j]->definitivas)){
						foreach ($asignaturas[$j]->definitivas as $key => $value) {
							//Log::info(get_object_vars($value));
							if (isset($value->periodo)) {
								$field = 'per'.$value->periodo;
								$areas[$i]->{$field} += $value->DefMateria;
							}
						}
					}

					array_push($areas[$i]->asignaturas, $asignaturas[$j]);
				}
			}

			$areas[$i]->cant 				= $found;
			if ($found>0) {
				$areas[$i]->area_nota 		= round($areas[$i]->sumatoria / $found);
				$areas[$i]->per1 		= $areas[$i]->per1 / $found;
				$areas[$i]->per2 		= $areas[$i]->per2 / $found;
				$areas[$i]->per3 		= $areas[$i]->per3 / $found;
				$areas[$i]->per4 		= $areas[$i]->per4 / $found;
			}else{
				$areas[$i]->area_nota 			= 0;
			}

			$esca = 						EscalaDeValoracion::valoracion($areas[$i]->area_nota, $escalas);
			if ($esca) {
				$areas[$i]->area_desempenio 	= EscalaDeValoracion::valoracion($areas[$i]->area_nota, $escalas)->desempenio;
			}else{
				$areas[$i]->area_desempenio 	= '';
			}

		}
		return $areas;
	}



	public static function agrupar_asignaturas_periodos($grupo_id, $asignaturas, $escalas, $num_periodo)
	{

		// Agrupamos por áreas
		$consulta 	= 'SELECT ar.id as area_id, ar.orden, ar.nombre as area_nombre, ar.alias as area_alias
					FROM asignaturas a
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
					where a.deleted_at is null and a.grupo_id=? and a.profesor_id is not null
					group by ar.id order by ar.orden';

		$areas 		= DB::select($consulta, [ $grupo_id ]);
		$cantAr 	= count($areas);
		$cantAs 	= count($asignaturas);

		for ($i=0; $i < $cantAr; $i++) {
			$found = 0;
			$areas[$i]->sumatoria_per1 	= 0;
			$areas[$i]->sumatoria_per2 	= 0;
			$areas[$i]->sumatoria_per3 	= 0;
			$areas[$i]->sumatoria_per4 	= 0;
			$areas[$i]->creditos 		= 0;
			$areas[$i]->asignaturas 	= [];

			for ($j=0; $j < $cantAs; $j++) {
				if ($areas[$i]->area_id == $asignaturas[$j]->area_id) {
					$found += 1;

					if (isset($asignaturas[$j]->nota_final_per1)) {
						$areas[$i]->sumatoria_per1 += $asignaturas[$j]->nota_final_per1;
					}
					if (isset($asignaturas[$j]->nota_final_per2)) {
						$areas[$i]->sumatoria_per2 += $asignaturas[$j]->nota_final_per2;
					}
					if (isset($asignaturas[$j]->nota_final_per3)) {
						$areas[$i]->sumatoria_per3 += $asignaturas[$j]->nota_final_per3;
					}
					if (isset($asignaturas[$j]->nota_final_per4)) {
						$areas[$i]->sumatoria_per4 += $asignaturas[$j]->nota_final_per4;
					}
					if (isset($asignaturas[$j]->creditos)) {
						$areas[$i]->creditos += $asignaturas[$j]->creditos;
					}

					array_push($areas[$i]->asignaturas, $asignaturas[$j]);
				}
			}

			$areas[$i]->cant = $found;

			$areas[$i]->per1_nota 			= round($areas[$i]->sumatoria_per1 / $found);
			$des 							= EscalaDeValoracion::valoracion($areas[$i]->per1_nota, $escalas);
			if ($des) {
				$areas[$i]->desempenio_per1 	= $des->desempenio;
			}

			if ($num_periodo > 1) {
				$areas[$i]->per2_nota 			= round($areas[$i]->sumatoria_per2 / $found);
				$des 							= EscalaDeValoracion::valoracion($areas[$i]->per2_nota, $escalas);
				if ($des) {
					$areas[$i]->desempenio_per2 	= $des->desempenio;
				}

			}
			if ($num_periodo > 2) {
				$areas[$i]->per3_nota 			= round($areas[$i]->sumatoria_per3 / $found);
				$des 							= EscalaDeValoracion::valoracion($areas[$i]->per3_nota, $escalas);
				if ($des) {
					$areas[$i]->desempenio_per3 	= $des->desempenio;
				}
			}
			if ($num_periodo == 4) {
				$areas[$i]->per4_nota 			= round($areas[$i]->sumatoria_per4 / $found);
				$des 							= EscalaDeValoracion::valoracion($areas[$i]->per4_nota, $escalas);
				if ($des) {
					$areas[$i]->desempenio_per4 	= $des->desempenio;
				}
			}
			//$areas[$i]->area_nota 			= round($areas[$i]->sumatoria / $found);
			//$areas[$i]->area_desempenio 	= EscalaDeValoracion::valoracion($areas[$i]->area_nota, $escalas)->desempenio;
		}
		return $areas;
	}


}