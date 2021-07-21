<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;


class VtVotacion extends Model {
	protected $fillable = [];
	protected $table = "vt_votaciones";

	use SoftDeletes;
	protected $softDelete = true;


	public static function actual($user, $admin=0)
	{
		if ($admin) {

			$consulta = 'SELECT * FROM vt_votaciones v WHERE v.id=:votacion_id and v.deleted_at is null ';
			$votaciones = DB::select($consulta, [ 'votacion_id' => $admin ] );

		}else{

			$consulta = 'SELECT * FROM vt_votaciones v WHERE v.user_id=:user_id and v.year_id=:year_id and v.actual=1 and v.deleted_at is null ';
			$votaciones = DB::select($consulta, [ 'user_id' => $user->user_id, 'year_id' => $user->year_id ] );

		}
		if(count($votaciones) > 0){
			return $votaciones[0];
		}
		return [];
	}

	public function actualInAction($user)
	{
		return VtVotacion::where('actual', true)
					->where('user_id', $user->user_id)
					->where('in_action', true)
					->where('year_id', $user->year_id)
					->first();
	}

	public static function actualesInscrito($user, $in_action=true)
	{
		if ($in_action) {
			$consulta = 'SELECT * FROM vt_votaciones v WHERE actual=true and in_action=true and v.deleted_at is null ';
		}else{
			$consulta = 'SELECT * FROM vt_votaciones v WHERE in_action=true and v.deleted_at is null ';
		}
		
		$votaciones 		= DB::select($consulta);
		$votaciones_res 	= [];
		
		if (count($votaciones) > 0) {
			
			for ($j=0; $j < count($votaciones); $j++) { 
				
				if ($user->tipo == 'Profesor') {
					if ($votaciones[$j]->votan_profes) {
						array_push($votaciones_res, $votaciones[$j]);
					}
				}
				
				if ($user->tipo == 'Alumno') {
					$consulta 		= 'SELECT * FROM vt_participantes p WHERE votacion_id=?';
					$participantes 	= DB::select($consulta, [$votaciones[$j]->id]);
					
					for ($i=0; $i < count($participantes); $i++) { 
						$consulta 		= 'SELECT m.grupo_id FROM matriculas m 
											INNER JOIN vt_participantes p ON p.grupo_profes_acudientes=m.grupo_id and p.votacion_id=? and m.deleted_at is null
											WHERE m.alumno_id=? and m.grupo_id=? and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and deleted_at is null';
						$inscripc 	= DB::select($consulta, [$votaciones[$j]->id, $user->persona_id, $participantes[$i]->grupo_profes_acudientes]);
						if (count($inscripc)>0) {
							$votaciones[$j]->grupo_id = $inscripc[0]->grupo_id;
							array_push($votaciones_res, $votaciones[$j]);
						}
					}
				}
			}
		}
		return $votaciones_res;
	}




	public static function verificarVotosCompletos($aspiraciones, $votacion_id, $user_id)
	{
		
		$cons = 'SELECT vv.user_id, vv.candidato_id, va.votacion_id, vv.created_at
			FROM vt_votos vv
			inner join users u on u.id=vv.user_id and vv.user_id=:user_id
			inner join vt_candidatos vc on vc.id=vv.candidato_id
			inner join vt_aspiraciones va on va.id=vc.aspiracion_id and va.votacion_id=:votacion_id 
			WHERE vv.deleted_at is null';

		$votosVotados = DB::select($cons, ['votacion_id' => $votacion_id, 'user_id' => $user_id]);

		
		$cons = 'SELECT vv.user_id, vv.candidato_id, va.votacion_id, vv.created_at
			FROM vt_votos vv
			inner join users u on u.id=vv.user_id and vv.user_id=:user_id
			inner join vt_aspiraciones va on va.id=vv.blanco_aspiracion_id and va.votacion_id=:votacion_id
			WHERE vv.deleted_at is null';

		$votosVotadosBlancos = DB::select($cons, ['votacion_id' => $votacion_id, 'user_id' => $user_id]);

		$cantVotados = count($votosVotados) + count($votosVotadosBlancos);

		if ($cantVotados < count($aspiraciones)) {
			$completo = false;
		}else{
			$completo = true;
		}
		return $completo;
	}



}