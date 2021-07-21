<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use DB;
use App\User;


class VtParticipante extends Model {

	protected $fillable = [];
	protected $table = "vt_participantes";

	use SoftDeletes;
	protected $softDelete = true;



	public static function one($user_id){
		$particip = VtParticipante::where('user_id', $user_id)->first();
		if ($particip) {
			return $particip;
		}
		return $particip;
	}



	public static function participanteDeAspiracion($aspira_id, $userT = '')
	{
		$user = [];
		if ($userT == '') {
			$user = User::fromToken();
		}else{
			$user = $userT;
		}
		
		$votacion_id = VtAspiracion::find($aspira_id)->votacion_id;
		$participante = VtParticipante::where('user_id', $user->user_id)
								->where('votacion_id', $votacion_id)
								->first();

		return $participante;
	}

	
	public static function participantesDeEvento($votacion_id, $year_id)
	{


		$consulta = 'SELECT * FROM (
				SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, 
					("Pr") as tipo, p.sexo, u.email, p.fecha_nac, p.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from profesores p 
					inner join users u on p.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=p.foto_id
					where p.deleted_at is null
				union
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, 
					("Al") as tipo, a.sexo, u.email, a.fecha_nac, a.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				union
				SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, 
					("Pr") as tipo, ac.sexo, u.email, ac.fecha_nac, ac.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
					from acudientes ac 
					inner join users u on ac.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=ac.foto_id
					where ac.deleted_at is null
				union
				SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username,
					("Us") as tipo, u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, :year_idd as year_id  
					from users u
					left join images i on i.id=u.imagen_id 
					where u.id not in (SELECT p.user_id
								from profesores p 
								inner join users u on p.user_id=u.id
							union
							SELECT a.user_id
								from alumnos a 
								inner join users u on a.user_id=u.id
							union
							SELECT ac.user_id
								from acudientes ac 
								inner join users u on ac.user_id=u.id
						)
					and u.deleted_at is null ) usus
				inner join vt_participantes vp on vp.user_id=usus.user_id and vp.votacion_id=:votacion_id and usus.year_id=:year_id and vp.deleted_at is null';
		
		$participantes = DB::select($consulta, ['year_idd' => $year_id, 'votacion_id' => $votacion_id, 'year_id' => $year_id]);

		foreach ($participantes as $participante) {
			$aspiraciones = VtAspiracion::where('votacion_id', '=', $votacion_id)->get();
			
			$cons = 'SELECT vv.participante_id, vv.candidato_id, vp.votacion_id, vv.created_at
					FROM vt_votos vv
					inner join vt_participantes vp on vp.id=vv.participante_id and vv.participante_id=:participante_id and vp.deleted_at is null
					inner join vt_candidatos vc on vc.id=vv.candidato_id and vc.deleted_at is null
					inner join vt_aspiraciones va on va.id=vc.aspiracion_id and va.votacion_id=:votacion_id 
					WHERE vv.deleted_at is null';

			$votosVotados = DB::select($cons, array('votacion_id' => $votacion_id, 'participante_id' => $participante->id));
			$participante->votosVotados = $votosVotados;

			$cantVotados = count($votosVotados);
			$participante->votados = $cantVotados;

			if ($cantVotados < count($aspiraciones)) {
				$participante->completo = 'No';
			}else{
				$participante->completo = 'Si';
			}
		}

		return $participantes;
	}


	public static function isSigned($user_id, $votacion_id)
	{
		$signed = VtParticipante::where('user_id', '=', $user_id)
					->where('votacion_id', '=', $votacion_id)
					->where('locked', '=', false)
					->get();

		if (count($signed) > 0) {
			return $signed[0];
		}else{
			return false;
		}
	}
}