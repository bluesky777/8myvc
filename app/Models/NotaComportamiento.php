<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
use App\Models\EscalaDeValoracion;
/**
 * Las columnas de `nota_comportamiento`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $alumno_id
 * @property int $periodo_id
 * @property int $nota
 * @property int $familiar_nota
 * @property int $familiar_ausencias
 * @property int $created_by
 * @property int $updated_by
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
 * @property array $definiciones  las definiciones de comportamiento de esa nota
 */


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
				if (is_numeric($nota->notas_finales[$h]->familiar_nota)) {
					$sum_notas = $nota->notas_finales[$h]->familiar_nota + $sum_notas;
				}
				$des = EscalaDeValoracion::valoracion($nota->notas_finales[$h]->familiar_nota, $escalas_val);
				$nota->notas_finales[$h]->familiar_desempenio = $des->desempenio;		
				$desh = EscalaDeValoracion::valoracion($nota->notas_finales[$h]->nota, $escalas_val);
				$nota->notas_finales[$h]->desempenio = $desh->desempenio;		
			}
			
			$nota->familiar_nota_definitiva_anio 	= round($sum_notas / $cant_n);
			$des = EscalaDeValoracion::valoracion($nota->familiar_nota_definitiva_anio, $escalas_val);
			$nota->familiar_desempenio_definitiva_anio = $des->desempenio;		
			
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


	// $periodo_a_calcular limita el promedio a los periodos con numero <= ese valor, para
	// el "Certificado periodos". Si viene null se promedian todos los periodos del year,
	// que es el comportamiento del "Certificado final".
	public static function nota_promedio_year($alumno_id, $year_id, $periodo_a_calcular = null){

		$consulta 	= 'SELECT avg(n.nota) as nota_comportamiento_year FROM nota_comportamiento n INNER JOIN periodos p ON p.id=n.periodo_id AND p.deleted_at is null AND p.year_id=:year_id
			WHERE n.alumno_id=:alumno_id and n.deleted_at is null';
		$params 	= [ ':year_id' =>$year_id, ':alumno_id' =>$alumno_id ];

		if ($periodo_a_calcular) {
			$consulta .= ' AND p.numero <= :periodo_a_calcular';
			$params[':periodo_a_calcular'] = $periodo_a_calcular;
		}

		$nota 		= DB::select($consulta, $params);

		if(count($nota) > 0){
			return (int)$nota[0]->nota_comportamiento_year;
		}else{
			return 0;
		}


	}
	
	
	// Ver la nota en nota_promedio_year: $periodo_a_calcular recorta la lista a los
	// periodos con numero <= ese valor.
	public static function todas_year($alumno_id, $year_id, $periodo_a_calcular = null){

		$consulta 	= 'SELECT n.nota as nota_comportamiento, n.id, p.numero FROM periodos p
			LEFT JOIN nota_comportamiento n ON p.id=n.periodo_id AND n.alumno_id=:alumno_id AND p.deleted_at is null
			WHERE n.deleted_at is null AND p.year_id=:year_id';
		$params 	= [ ':alumno_id' =>$alumno_id, ':year_id' =>$year_id ];

		if ($periodo_a_calcular) {
			$consulta .= ' AND p.numero <= :periodo_a_calcular';
			$params[':periodo_a_calcular'] = $periodo_a_calcular;
		}

		$notas 		= DB::select($consulta, $params);

		return $notas;

	}

}