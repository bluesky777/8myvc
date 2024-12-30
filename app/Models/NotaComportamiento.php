<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;
use App\Models\EscalaDeValoracion;


class NotaComportamiento extends Model {
	protected $fillable = ['alumno_id', 'periodo_id'];  // Para poder usar firstOrNew()
	protected $table = "nota_comportamiento";

	use SoftDeletes;
	protected $softDelete = true;


	public static function crearVerifNota($alumno_id, $periodo_id, $nota_max)
	{

		$nota = NotaComportamiento::firstOrNew(['alumno_id' => $alumno_id, 'periodo_id' => $periodo_id]);
		if (!$nota->id) {
			$nota->nota = $nota_max;
			$nota->save();
		}

		return $nota;
	}


	public static function nota_comportamiento($alumno_id, $periodo_id, $year_id, $escalas_val) {
		
		$consulta = 'SELECT * FROM nota_comportamiento n WHERE n.alumno_id=:alumno_id and n.periodo_id=:periodo_id and n.deleted_at is null';
		$nota = DB::select($consulta, [
										':alumno_id'	=>$alumno_id, 
										':periodo_id'	=>$periodo_id
									]);

		if(count($nota) > 0){
			$nota = $nota[0];

			$nota->notas_finales 		= DB::select(
				'SELECT n.id, n.nota, n.periodo_id, p.numero, n.familiar_nota, n.familiar_ausencias
					FROM nota_comportamiento n
					INNER JOIN periodos p ON p.id=n.periodo_id and p.year_id=? and p.deleted_at is null 
					WHERE n.alumno_id=? and n.periodo_id<=? and n.deleted_at is null order by p.numero asc',
				[$year_id, $alumno_id, $periodo_id],
			);

			$cant_n = count($nota->notas_finales);
			$sum_notas = 0;

			for ($h=0; $h < $cant_n; $h++) {
				$sum_notas = $nota->notas_finales[$h]->nota + $sum_notas;
			}
			
			$nota->nota_definitiva_anio 	= round($sum_notas / $cant_n);
			$des = EscalaDeValoracion::valoracion($nota->nota_definitiva_anio, $escalas_val);
			$nota->desempenio = $des->desempenio;		
			
			return $nota;
		}else{
			return [ "notas_finales" => [] ];
		}

		 
	}



	public static function notas_comportamiento_year($alumno_id, $year_id){
		$periodos = DB::select('SELECT * FROM periodos p WHERE p.year_id=? and p.deleted_at is null ', [$year_id]);
		
		for ($i=0; $i < count($periodos); $i++) { 
			$periodo_id = $periodos[$i]->id;
			
			$consulta = 'SELECT * FROM nota_comportamiento n WHERE n.alumno_id=:alumno_id and n.periodo_id=:periodo_id and n.deleted_at is null';
			$nota = DB::select($consulta, [
											':alumno_id'	=>$alumno_id, 
											':periodo_id'	=>$periodo_id
										]);
			
			if(count($nota) > 0){
				$periodos[$i]->nota 			= $nota[0];
				$periodos[$i]->definiciones 	= DefinicionComportamiento::frases($nota[0]->id);
				$escalas_val 					= DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$year_id]);
				$escala 						= EscalaDeValoracion::valoracion($periodos[$i]->nota->nota, $escalas_val)->desempenio;
				$periodos[$i]->nota->juicio 	= $escala;
			}else{
				$periodos[$i]->nota 			= '';
				$periodos[$i]->definiciones 	= [];
			}
		}
		return $periodos;

		 
	}


	public static function nota_promedio_year($alumno_id, $year_id){
		
		$consulta 	= 'SELECT avg(n.nota) as nota_comportamiento_year FROM nota_comportamiento n INNER JOIN periodos p ON p.id=n.periodo_id AND p.deleted_at is null AND p.year_id=:year_id
			WHERE n.alumno_id=:alumno_id and n.deleted_at is null';
		$nota 		= DB::select($consulta, [ ':year_id' =>$year_id, ':alumno_id' =>$alumno_id ]);
		
		if(count($nota) > 0){
			return (int)$nota[0]->nota_comportamiento_year;
		}else{
			return 0;
		}

		 
	}
	
	
	public static function todas_year($alumno_id, $year_id){
		
		$consulta 	= 'SELECT n.nota as nota_comportamiento, n.id, p.numero FROM periodos p 
			LEFT JOIN nota_comportamiento n ON p.id=n.periodo_id AND n.alumno_id=:alumno_id AND p.deleted_at is null
			WHERE n.deleted_at is null AND p.year_id=:year_id';
		$notas 		= DB::select($consulta, [ ':alumno_id' =>$alumno_id, ':year_id' =>$year_id ]);
		
		return $notas;
		 
	}

}