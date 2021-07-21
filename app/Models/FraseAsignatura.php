<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Request;
use DB;

use App\User;


class FraseAsignatura extends Model {
	protected $fillable = [];

	protected $table = 'frases_asignatura';

	use SoftDeletes;
	protected $softDelete = true;


	public static function deAlumno($asignatura_id, $alumno_id, $periodo_id)
	{
		$consulta = 'SELECT fa.id, IFNULL(f.frase, fa.frase) as frase, fa.frase_id, fa.asignatura_id, 
						fa.periodo_id, fa.created_by, fa.created_at, f.tipo_frase
					FROM frases_asignatura fa
					left join frases f on f.id=fa.frase_id and f.deleted_at is null
					where fa.deleted_at is null and fa.alumno_id=:alumno_id and fa.asignatura_id=:asignatura_id and fa.periodo_id=:periodo_id';

		$frases = DB::select(DB::raw($consulta), array(
			':alumno_id'		=> $alumno_id, 
			':asignatura_id'	=> $asignatura_id, 
			':periodo_id'	=> $periodo_id));

		return $frases;
	}


}