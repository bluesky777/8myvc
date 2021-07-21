<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use DB;

class DefinicionComportamiento extends Model {
	protected $fillable = [];
	protected $table = "definiciones_comportamiento";

	use SoftDeletes;
	protected $softDelete = true;


	public static function frases($comport_id)
	{
		$consulta = 'SELECT dc.id, IFNULL(f.frase, dc.frase) as frase, dc.frase_id, dc.comportamiento_id, 
						dc.created_by, dc.created_at, f.tipo_frase
					FROM definiciones_comportamiento dc
					left join frases f on f.id=dc.frase_id and f.deleted_at is null
					where dc.deleted_at is null and dc.comportamiento_id=?';

		$definiciones = DB::select($consulta, [$comport_id]);

		return $definiciones;
	}

}