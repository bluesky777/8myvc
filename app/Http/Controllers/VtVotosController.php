<?php namespace App\Http\Controllers;

use Request;
use DB;


use App\User;
use App\Models\VtAspiracion;
use App\Models\VtVoto;
use App\Models\VtCandidato;
use App\Models\VtParticipante;
use App\Models\VtVotacion;
use App\Models\Year;


class VtVotosController extends Controller {


	public function getIndex()
	{
		return VtVoto::all();
	}


	public function postStore()
	{
		$user = User::fromToken();

		$votacion_actual_id = Request::input('votacion_id');
		$voto_blanco 		= false;
		
		if (Request::has('blanco_aspiracion_id')) {
			$voto_blanco 		= true;
			$aspiracion_id 		= Request::input('blanco_aspiracion_id');
		}else{
			$aspiracion_id = VtCandidato::find(Request::input('candidato_id'))->aspiracion_id;
		}
		
		


		VtVoto::verificarNoVoto($aspiracion_id, $user->user_id);

		try {
			if ($voto_blanco) {
				$voto = new VtVoto;
				$voto->user_id				=	$user->user_id;
				$voto->blanco_aspiracion_id	=	$aspiracion_id;
				$voto->locked				=	false;
				$voto->save();
			}else{
				$voto = new VtVoto;
				$voto->user_id			=	$user->user_id;
				$voto->candidato_id		=	Request::input('candidato_id');
				$voto->locked			=	false;
				$voto->save();
			}
			
			$aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=?', [$votacion_actual_id]);
			$completos = VtVotacion::verificarVotosCompletos($aspiraciones, $votacion_actual_id, $user->user_id);

			$voto->completo = $completos; // Para verificar en el frontend cuando se guarde el voto.

			return $voto;
			
		} catch (Exception $e) {
			return Response::json(array('msg'=>'Error al intentar guardar el voto'), 400);
		}
	}

	
	
	public function putShow()
	{
		$user 			= User::fromToken();
		$votaciones 	= VtVotacion::actualesInscrito($user, false); // Traer aunque no esté en acción.

		// Votaciones creadas por el usuario.
		$consulta = 'SELECT v.id as votacion_id, v.*
					FROM vt_votaciones v
					where v.user_id=? and v.year_id=? and v.deleted_at is null';

		$votacionesMias = DB::select($consulta, [$user->user_id, $user->year_id]);

		foreach ($votacionesMias as $key => $votMia) {
			// Debo crear otro array para verificar que ya no tenga el mismo evento.
			array_push($votaciones, $votMia);
		}


		$cantVot = count($votaciones);

		for($j=0; $j<$cantVot; $j++){

			if ($votaciones[$j]->can_see_results || Request::input('permitir')) {

				$aspiraciones = VtAspiracion::where('votacion_id', $votaciones[$j]->id)->get();

				$result = [];

				foreach ($aspiraciones as $aspira) {
					$candidatos = VtCandidato::porAspiracion($aspira->id, $user->year_id);

					for ($i=0; $i<count($candidatos); $i++) {

						$votos 	= VtVoto::deCandidato($candidatos[$i]->candidato_id, $aspira->id)[0];
						$candidatos[$i]->cantidad 	= $votos->cantidad;
						$candidatos[$i]->total 		= $votos->total;
					}
					
					// Voto en blanco como candidato
					$blanco 	= ['nombres' => 'Voto en Blanco', 'voto_blanco' => true, 'foto_nombre' => 'voto_en_blanco.jpg'];
					$consulta 	= 'SELECT count(*) as cantidad from vt_votos vv 
									where vv.blanco_aspiracion_id=:aspiracion_id and vv.deleted_at is null';
					$vt_blancos	= DB::select($consulta, [':aspiracion_id' => $aspira->id])[0];
					$blanco['cantidad'] = $vt_blancos->cantidad;
						
					array_push($candidatos, $blanco);
					// Fin voto en blanco

					$aspira->candidatos = $candidatos;
					
					array_push($result, $aspira);
				}

				$votaciones[$j]->aspiraciones = $result;	

			}
			
		}
		
		$year			= Year::datos($user->year_id);
		
		return ['votaciones' => $votaciones, 'year' => $year];
		
	}


	public function putUpdate($id)
	{
		$candidato = VtCandidato::findOrFail($id);
		try {
			$candidato->fill([
				'tipo'		=>	Request::input('tipo'),
				'abrev'		=>	Request::input('abrev')
			]);

			$candidato->save();
		} catch (Exception $e) {
			return $e;
		}
	}


	public function deleteDestroy($id)
	{
		$candidato = VtCandidato::findOrFail($id);
		$candidato->delete();

		return $candidato;
	}

}