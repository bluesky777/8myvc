<?php namespace App\Http\Controllers;

use Request;
use DB;


use App\User;
use App\Models\Alumno;
use App\Models\VtAspiracion;
use App\Models\VtVotacion;
use App\Models\VtParticipante;
use App\Models\Profesor;
use App\Models\Matricula;
use \Log;


class VtParticipantesController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		$actual = VtVotacion::actual($user);
		
		if($actual) {
			$participantes = VtParticipante::participantesDeEvento($actual->id, $user->year_id);
		} else{
			abort(400, 'Debe haber un evento establecido como actual.');
		}
		
		return $participantes;

	}


	public function putDatos()
	{
		$user 	= User::fromToken();

		if ($user->user_id==1) {
			$votacion_id = Request::input('votacion_id');
			$actual = VtVotacion::actual($user, $votacion_id);
		}else{
			$actual = VtVotacion::actual($user);
		}


		if($actual) {
			//$participantes = VtParticipante::participantesDeEvento($actual->id, $user->year_id);
			$participantes = [];
		} else{
			abort(400, 'Debe haber un evento establecido como actual.');
		}
		
		
		
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id,
						if(p.id is null, false, true) as inscrito, p.id as particip_id
					from grupos g
					left join vt_participantes p on p.grupo_profes_acudientes=g.id and p.votacion_id=:votacion_id
					where g.deleted_at is null and g.year_id=:year_id
					order by g.orden';

		$grupos = DB::select($consulta, [':year_id'=>$user->year_id, ':votacion_id'=>$actual->id] );
		
		return [ 'participantes' => $participantes, 'grupos' => $grupos, 'votacion' => $actual ];

	}


	
	public function putVotantes()
	{
		$user 	= User::fromToken();
		$grupo_id = Request::input('grupo_id');
		$votacion_id = Request::input('votacion_id');
		

		$participantes = DB::select(Matricula::$consulta_asistentes_o_matriculados, [ ':grupo_id' => $grupo_id ] );

		foreach ($participantes as $key => $particip) {
			$consulta = 'SELECT id, aspiracion, abrev FROM vt_aspiraciones WHERE votacion_id=? and deleted_at is null';
			$aspiraciones = DB::select($consulta, [$votacion_id]);

			foreach ($aspiraciones as $key => $aspiracion) {

				$consulta = 'SELECT * FROM (
						SELECT v.id, v.user_id, v.candidato_id, v.blanco_aspiracion_id, v.created_at 
						FROM vt_votos v
						INNER JOIN vt_candidatos c ON v.candidato_id=c.id and c.deleted_at is null
						INNER JOIN vt_aspiraciones a ON c.aspiracion_id=a.id and c.aspiracion_id=? and a.deleted_at is null
						WHERE a.votacion_id=? and v.user_id=? and v.deleted_at is null
					union
						SELECT v.id, v.user_id, v.candidato_id, v.blanco_aspiracion_id, v.created_at
						FROM vt_votos v
						INNER JOIN vt_aspiraciones a ON v.blanco_aspiracion_id=a.id and a.id=? and a.deleted_at is null
						WHERE a.votacion_id=? and v.user_id=? and v.deleted_at is null
					) votos';

					$aspiracion->votos = DB::select($consulta, [$aspiracion->id, $votacion_id, $particip->user_id, $aspiracion->id, $votacion_id, $particip->user_id]);
			}

			$particip->aspiraciones = $aspiraciones;

		}
		
		return [ 'participantes' => $participantes ];
	}

	
	
	public function putProfesores()
	{
		$user 	= User::fromToken();
		$votacion_id = Request::input('votacion_id');
		
		$consulta = 'SELECT * FROM profesores p
			INNER JOIN contratos c ON p.id=c.profesor_id and p.deleted_at is null and c.year_id=:year_id';

		$participantes = DB::select($consulta, [ ':year_id'=>$user->year_id ] );

		foreach ($participantes as $key => $particip) {
			$consulta = 'SELECT id, aspiracion, abrev FROM vt_aspiraciones WHERE votacion_id=? and deleted_at is null';
			$aspiraciones = DB::select($consulta, [$votacion_id]);

			foreach ($aspiraciones as $key => $aspiracion) {

				$consulta = 'SELECT * FROM (
						SELECT v.id, v.user_id, v.candidato_id, v.blanco_aspiracion_id, v.created_at 
						FROM vt_votos v
						INNER JOIN vt_candidatos c ON v.candidato_id=c.id and c.deleted_at is null
						INNER JOIN vt_aspiraciones a ON c.aspiracion_id=a.id and c.aspiracion_id=? and a.deleted_at is null
						WHERE a.votacion_id=? and v.user_id=? and v.deleted_at is null
					union
						SELECT v.id, v.user_id, v.candidato_id, v.blanco_aspiracion_id, v.created_at
						FROM vt_votos v
						INNER JOIN vt_aspiraciones a ON v.blanco_aspiracion_id=a.id and a.id=? and a.deleted_at is null
						WHERE a.votacion_id=? and v.user_id=? and v.deleted_at is null
					) votos';

					$aspiracion->votos = DB::select($consulta, [$aspiracion->id, $votacion_id, $particip->user_id, $aspiracion->id, $votacion_id, $particip->user_id]);
			}

			$particip->aspiraciones = $aspiraciones;

		}
		
		return [ 'participantes' => $participantes ];
	}

	
	public function putGuardarInscripciones()
	{
		$user = User::fromToken();

		$votacion 	= VtVotacion::actual($user);
		$grupos 	= Request::input('grupos');

		for ($i=0; $i < count($grupos); $i++) { 
			
			$vt_partic = DB::select('SELECT * FROM vt_participantes WHERE votacion_id=? and grupo_profes_acudientes=?', [ $votacion->id, $grupos[$i]['id'] ]);
				
			if($grupos[$i]['inscrito']){
				
				if ( ! count($vt_partic) > 0) {
					DB::insert('INSERT INTO vt_participantes(grupo_profes_acudientes, votacion_id, locked, intentos) VALUES(?, ?, 0, 1)', [ $grupos[$i]['id'], $votacion->id ]);
				}
				
			}else{	
				
				if (count($vt_partic) > 0) {
					DB::delete('DELETE FROM vt_participantes WHERE votacion_id=? and grupo_profes_acudientes=?', [ $votacion->id, $grupos[$i]['id'] ]);
				}
				
			}
			
		}

		return 'Inscripciones guardadas';
		
	}


	
	public function postInscribirProfesores()
	{
		$user = User::fromToken();

		$votacion = VtVotacion::actual($user);

		$profesores = Profesor::fromyear($user->year_id);

		$participantes = [];

		for($i=0; $i < count($profesores); $i++){

			$partic = VtParticipante::where('user_id', $profesores[$i]->user_id)
							->where('votacion_id', $votacion->id)
							->first();
			
			if ( !$partic ) {

				if (!$profesores[$i]->user_id){

					$dirtyName = $profesores[$i]->nombres;
					$name = preg_replace('/\s+/', '', $dirtyName);

					$usuario = new User;
					$usuario->username		=	$name;
					$usuario->password		=	'123456';
					$usuario->is_superuser	=	false;
					$usuario->is_active		=	true;
					$usuario->save();

					$profe = Profesor::find($profesores[$i]->id);
					$profe->user_id = $usuario->id;
					$profe->save();
					$profesores[$i]->user_id = $profe->user_id ;
				}

				$partic_trash = VtParticipante::onlyTrashed()
								->where('user_id', $profesores[$i]->user_id)
								->where('votacion_id', $votacion->id)
								->first();
			
				
				if ($partic_trash) {
					$participante = $partic_trash;
					$participante->restore();
				}else{

					$participante = new VtParticipante;
					$participante->user_id		=	$profesores[$i]->user_id;
					$participante->votacion_id	=	$votacion->id;
					$participante->locked		=	false;
					$participante->intentos		=	0;
					$participante->save();
				}

				array_push($participantes, $participante);
			}

		}

		return $participantes;
	}

	
	public function putSetLocked()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$locked = Request::input('locked', true);

		$vot = VtParticipante::where('id', $id)->update(['locked' => $locked]);
		return 'Cambiado';
	}


	public function getAllinscritos()
	{
		$user = User::fromToken();
		$votacion = VtVotacion::actual($user);
		if (!$votacion) {
			return [['sin_votacion_actual' => true]];
		}

		$consulta = 'SELECT usus.persona_id, usus.nombres, usus.apellidos, usus.user_id, usus.username, usus.tipo, grupo from 
						(select p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, ("Pr") as tipo, ("Profesores") as grupo 
							from profesores p 
							inner join users u on p.user_id=u.id
							inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id1 
						union
						select a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, ("Al") as tipo, g.nombre as grupo 
							from alumnos a 
							inner join users u on a.user_id=u.id and u.deleted_at is null and u.is_active=true
							inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS")
							inner join grupos g on m.grupo_id=g.id and g.year_id=:year_id2 and g.deleted_at is null
						)usus';
		
		$participantes = DB::select(DB::raw($consulta), [':year_id1' => $user->year_id, ':year_id2' => $user->year_id ]);
		//$participantes = [];

		return $participantes;
	}





	public function deleteDestroy($id)
	{
		$participante = VtParticipante::findOrFail($id);
		$participante->delete();

		return $participante;
	}

}