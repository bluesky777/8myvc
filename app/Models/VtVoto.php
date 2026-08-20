<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `vt_votos`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $user_id
 * @property ?int $candidato_id
 * @property ?int $blanco_aspiracion_id
 * @property int $locked
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property bool $completo  si el voto cubrió todas las aspiraciones
 */


class VtVoto extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;

	public static function verificarNoVoto($aspira_id, $user_id)
	{
		$consulta = 'SELECT vv.id, vv.user_id, vv.locked, vv.candidato_id
			from vt_votos vv
			inner join vt_candidatos vc on vc.id=vv.candidato_id 
				and vc.aspiracion_id=:aspiracion_id and vv.user_id=:user_id';

		$votos = DB::select($consulta, [':aspiracion_id' => $aspira_id, ':user_id' => $user_id]);
		
		foreach ($votos as $voto) {
			$voto = VtVoto::destroy($voto->id);
		}
		
		// Votos en blanco
		$consulta = 'SELECT vv.id, vv.user_id, vv.locked, vv.candidato_id
			from vt_votos vv
			inner join vt_aspiraciones va on va.id=vv.blanco_aspiracion_id 
				and va.id=:aspiracion_id and vv.user_id=:user_id';

		$votos = DB::select($consulta, [':aspiracion_id' => $aspira_id, ':user_id' => $user_id]);
		
		foreach ($votos as $voto) {
			$voto = VtVoto::destroy($voto->id);
		}
	}

	public static function hasVoted($votacion_id, $participante_id)
	{
		// Función que define si el participante ha hecho algún voto (no necesariamente en todas las aspiraciones del evento).
		$consulta = 'SELECT vv.id as voto_id, vv.candidato_id, va.aspiracion FROM vt_votos vv 
			inner join vt_candidatos vc on vv.candidato_id=vc.id
			inner join vt_aspiraciones va on va.id=vc.aspiracion_id 
				and vv.participante_id=:participante_id and va.votacion_id=:votacion_id';

		$datos = array(':participante_id' => $participante_id, ':votacion_id' => $votacion_id);
		$votos = DB::select($consulta, $datos);
		
		if ( count($votos) > 0 ) {
			return true;
		}else{
			return false;
		}
	}

	public static function votesInAspiracion($aspiracion_id, $participante_id)
	{
		// Función que define si el participante ha hecho algún voto (no necesariamente en todas las aspiraciones del evento).

		$consulta = 'SELECT vv.id as voto_id, vv.candidato_id, vc.aspiracion_id FROM vt_votos vv 
			inner join vt_candidatos vc on vv.candidato_id=vc.id
				and vv.participante_id=:participante_id and vc.aspiracion_id=:aspiracion_id';

		$datos = array(':participante_id' => $participante_id, ':aspiracion_id' => $aspiracion_id);
		$votos = DB::select($consulta, $datos);
		
		return $votos;
	}

	public static function deCandidato($candidato_id, $aspiracion_id)
	{
		// Función que define si el participante ha hecho algún voto (no necesariamente en todas las aspiraciones del evento).

		$consulta = 'SELECT count(*) as cantidad, t.total from vt_votos vv
				inner join (
					select count(*) as total from vt_votos 
					inner join vt_candidatos vc on vc.id=vt_votos.candidato_id 
						and vc.aspiracion_id=:aspiracion_id and vt_votos.deleted_at is null
				)t  where vv.candidato_id=:candidato_id and vv.deleted_at is null';

		$datos = 	[':aspiracion_id' => $aspiracion_id, 
					':candidato_id' => $candidato_id
					];
		$votos = DB::select($consulta, $datos);
		
		return $votos;
	}
}