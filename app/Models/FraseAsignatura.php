<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
/**
 * Las columnas de `frases_asignatura`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $alumno_id
 * @property ?int $frase_id
 * @property ?string $frase
 * @property int $asignatura_id
 * @property int $periodo_id
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */


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

		$frases = DB::select($consulta, array(
			':alumno_id'		=> $alumno_id, 
			':asignatura_id'	=> $asignatura_id, 
			':periodo_id'	=> $periodo_id));

		return $frases;
	}


}