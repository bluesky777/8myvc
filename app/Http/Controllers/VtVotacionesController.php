<?php namespace App\Http\Controllers;

use Request;
use DB;


use App\User;
use App\Models\VtAspiracion;
use App\Models\VtVotacion;
use App\Models\VtCandidato;
use App\Models\VtVoto;
use \DateTime;


class VtVotacionesController extends Controller {


	public function getIndex()
	{
		$user = User::fromToken();

		if ($user->user_id == 1) {

			$votaciones = VtVotacion::where('year_id', $user->year_id)->get();

		}else{

			$votaciones = VtVotacion::where('user_id', $user->user_id)
				->where('year_id', $user->year_id)->get();

		}

		for($i=0; $i<count($votaciones); $i++){
			$aspiraciones = VtAspiracion::where('votacion_id', $votaciones[$i]->id)->get();
			$votaciones[$i]->aspiraciones = $aspiraciones;
		}

		return $votaciones;
	}



	public function postStore()
	{

		$user = User::fromToken();

		try {

			if (Request::input('actual') == 1) {
				$consulta = 'UPDATE vt_votaciones SET actual=0 WHERE actual=1;';
				DB::statement($consulta);
			}
			
			$fecha 			= date("Y-m-d H:i:s");
			$fecha_inicio 	= Request::input('fecha_inicio');
			$fecha_fin 		= Request::input('fecha_fin');


			if ($fecha_inicio == NULL) {
				$fecha_inicio = $fecha;
			}
			if ($fecha_fin == NULL) {
				$fecha_fin = $fecha;
			}

			
			
			$datos = ['user_id'			=>	$user->user_id,
					'nombre'			=>	Request::input('nombre'),
					'year_id'			=>	$user->year_id,
					'votan_profes'		=>	Request::input('votan_profes', true),
					'votan_acudientes'	=>	Request::input('votan_acudientes', true),
					'locked'			=>	Request::input('locked', false),
					'actual'			=>	Request::input('actual', false),
					'in_action'			=>	Request::input('in_action', false),
					'fecha_inicio'		=>	$fecha_inicio,
					'fecha_fin'			=>	$fecha_fin,
					'created_at'		=>	$fecha,
					'updated_at'		=>	$fecha,
					];


			$votacion = DB::table('vt_votaciones')->insertGetId($datos);

			$datos['id'] = $votacion;



			$aspiraciones = Request::input('aspiraciones');

			for ($i=0; $i < count($aspiraciones); $i++) {
				$asp 				= new VtAspiracion;
				$asp->aspiracion 	= $aspiraciones[$i]['aspiracion'];
				$asp->abrev 		= $aspiraciones[$i]['abrev'];
				$asp->votacion_id 	= $votacion;
				$asp->save();

				$aspiraciones[$i]['id'] = $asp->id;
			}

			$datos['aspiraciones'] = $aspiraciones;

			return $datos;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
		}
	}


	public function getShow($id)
	{
		return VtVotacion::findOrFail($id);
	}
	
	public function getActual()
	{
		$user = User::fromToken();
		return (array)VtVotacion::actual($user);
	}

	public function getActualInAction()
	{
		$user = User::fromToken();
		return VtVotacion::actualInAction($user);
	}

	public function getUnsignedsusers()
	{
		$consulta = 'SELECT u.id, u.username, u.email, u.is_superuser 
					FROM users u 
					where u.id not in (select p.user_id from vt_participantes p)';
		return DB::select(DB::raw($consulta));
	}


	public function putSetVotanProfes()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$locked = Request::input('votan_profes', true);

		$vot = VtVotacion::where('id', $id)->update(['votan_profes' => $locked]);
		return 'Cambiado';
	}

	public function putSetVotanAcudientes()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$locked = Request::input('votan_acudientes', true);

		$vot = VtVotacion::where('id', $id)->update(['votan_acudientes' => $locked]);
		return 'Cambiado';
	}


	public function putSetLocked()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$locked = Request::input('locked', true);

		$vot = VtVotacion::where('id', $id)->update(['locked' => $locked]);
		return 'Cambiado';
	}


	public function putSetInAction()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$in_action = Request::input('in_action', false);

		if ($in_action) {
			
			$consulta = 'UPDATE vt_votaciones v SET v.in_action=false 
						WHERE v.id<>? and v.user_id=? 
							and v.year_id=? and v.in_action=true AND v.deleted_at is null';

			DB::statement($consulta, [$id, $user->user_id, $user->year_id]);

			
			$consulta = 'UPDATE vt_votaciones v SET v.in_action=true WHERE v.id=?';
			$vot = DB::statement($consulta, [$id]);

			return 'Cambiado true';

		}else{

			$consulta = 'UPDATE vt_votaciones v SET v.in_action=false WHERE v.id=?';
			$vot = DB::statement($consulta, [$id]);
			return 'Cambiado false';

		}
	}


	public function putSetPermisoVerResults()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$can_see_results = Request::input('can_see_results', false);

		$vot = VtVotacion::where('id', $id)->update(['can_see_results' => $can_see_results]);
		return 'Cambiado';
	}


	public function putSetActual()
	{
		$user = User::fromToken();
		$id = Request::input('id');
		$actual = Request::input('actual', true);

		if ($actual) {
			
			$consulta = 'UPDATE vt_votaciones v SET v.actual=false 
						WHERE v.id<>? and v.user_id=? 
							and v.year_id=? and v.actual=true AND v.deleted_at is null';

			DB::statement($consulta, [$id, $user->user_id, $user->year_id]);

			
			$consulta = 'UPDATE vt_votaciones v SET v.actual=true WHERE v.id=?';
			$vot = DB::statement($consulta, [$id]);

			return 'Cambiado true';

		}else{

			$consulta = 'UPDATE vt_votaciones v SET v.actual=false WHERE v.id=?';
			$vot = DB::statement($consulta, [$id]);
			return 'Cambiado false';

		}
		
	}


	public function putUpdate($id)
	{
		$votacion = VtVotacion::findOrFail($id);
		try {
			$votacion->nombre			=	Request::input('nombre', $votacion->nombre);
			$votacion->votan_profes		=	Request::input('votan_profes', $votacion->votan_profes);
			$votacion->votan_acudientes	=	Request::input('votan_acudientes', $votacion->votan_acudientes);
			$votacion->locked			=	Request::input('locked', $votacion->locked);
			$votacion->actual			=	Request::input('actual', $votacion->actual);
			$votacion->in_action		=	Request::input('in_action', $votacion->in_action);
			$votacion->fecha_inicio		=	Request::input('fecha_inicio', $votacion->fecha_inicio);
			$votacion->fecha_fin		=	Request::input('fecha_fin', $votacion->fecha_fin);

			$votacion->save();
			return $votacion;
		} catch (Exception $e) {
			return abort(400, 'Datos incorrectos');
			return $e;
		}
	}



	// Para cuando entra alguien a votar. Necesita todos los eventos en acción a 
	// los que está inscrito.
	public function getEnAccionInscrito()
	{
		$user = User::fromToken();

		$votaciones = VtVotacion::actualesInscrito($user, true);

		$cantVot = count($votaciones);

		if ($cantVot > 0) {

			for($i=0; $i < $cantVot; $i++){
				
				$aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=?', [$votaciones[$i]->id]);
				
				$completos = VtVotacion::verificarVotosCompletos($aspiraciones, $votaciones[$i]->id, $user->user_id);
				$votaciones[$i]->completos = $completos;
				
				$cantAsp = count($aspiraciones);

				if ($cantAsp > 0) {

					for ($j=0; $j<$cantAsp; $j++) {

						$candidatos = VtCandidato::porAspiracion($aspiraciones[$j]->id, $votaciones[$i]->year_id);

						// Verificamos si ya votó en esta aspiración
						$consulta = "SELECT * FROM vt_votos v 
								inner join users u on u.id=v.user_id and u.id=:user_id 
								inner join vt_candidatos c on c.id=v.candidato_id and c.deleted_at is null
								where c.aspiracion_id=:aspiracion_id and v.deleted_at is null";
						
						$votado = DB::select($consulta, ["user_id"=>$user->user_id, "aspiracion_id"=>$aspiraciones[$j]->id]);

						if (count($votado)>0) {
							$aspiraciones[$j]->votado = true;
						}else{
							$consulta = "SELECT * FROM vt_votos v 
									inner join users u on u.id=v.user_id and u.id=:user_id 
									where v.blanco_aspiracion_id=:aspiracion_id and v.deleted_at is null";
						
							$votado = DB::select($consulta, ["user_id"=>$user->user_id, "aspiracion_id"=>$aspiraciones[$j]->id]);
							if (count($votado)>0) {
								$aspiraciones[$j]->votado = true;
							}
						}

						// Traemos los votos que tiene cada candidato
						for ($k=0; $k<count($candidatos); $k++) {

							$votos = VtVoto::deCandidato($candidatos[$k]->candidato_id, $aspiraciones[$j]->id)[0];
							$candidatos[$k]->cantidad = $votos->cantidad;
							$candidatos[$k]->total = $votos->total;
						}
						
						// Voto en blanco
						$blanco 			= ['nombres' => 'Voto en Blanco', 'voto_blanco' => true, 'foto_nombre' => 'voto_en_blanco.jpg', 'imagen_nombre' => 'voto_en_blanco.jpg'];
						array_push($candidatos, $blanco);
						$consulta 			= 'SELECT count(*) as cantidad from vt_votos vv 
												where vv.blanco_aspiracion_id=:aspiracion_id and vv.deleted_at is null';
						$vt_blancos			= DB::select($consulta, [':aspiracion_id' => $aspiraciones[$j]->id])[0];
						$blanco['cantidad'] = $vt_blancos->cantidad;
						$blanco['total'] 	= $vt_blancos->cantidad;
						
						$aspiraciones[$j]->candidatos = $candidatos;
						// Fin Voto en blanco

						$aspiraciones[$j]->candidatos = $candidatos;
						
					}

					$votaciones[$i]->aspiraciones = $aspiraciones;

				}else{
					$votaciones[$i]->aspiraciones = [];
				}
			}
		}else{
			return ['msg' => 'No está inscrito en algún evento que se encuentre en acción.'];
		}
		

		return $votaciones;
	}



	public function deleteDestroy($id)
	{
		$votaciones = VtVotacion::findOrFail($id);
		$votaciones->delete();

		return $votaciones;
	}

}