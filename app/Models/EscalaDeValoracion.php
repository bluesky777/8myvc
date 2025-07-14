<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



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