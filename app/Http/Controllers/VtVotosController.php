<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;


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


	/**
	 * Emite el voto.
	 *
	 * **`locked` es una PAUSA, no un cierre**, y eso decide cómo está escrito lo de
	 * abajo. Lo dijo Joseth el 21 ago 2026: «se puede inactivar la votación tal como
	 * esté y luego continuar para que los alumnos que no habían podido votar sigan
	 * votando». O sea que el colegio la pausa a mitad —un recreo, un corte de luz—
	 * y la reanuda; no es el final de nada.
	 *
	 * Por eso el mensaje dice «pausada» y no «cerrada»: el front lo enseña tal cual,
	 * y decirle a un alumno que la votación terminó cuando va a seguir en diez
	 * minutos es la misma clase de mentira que esta serie lleva persiguiendo.
	 *
	 * **`in_action` NO se comprueba, y es a propósito.** No es un candado: hace que
	 * el front lleve al usuario a la pantalla de votar nada más entrar. Con
	 * `in_action = 0` se puede votar igual por el menú, que es un camino legítimo, y
	 * comprobarlo aquí lo apagaría. Ver 11-votaciones.md §2.1.
	 */
	public function postStore()
	{
		$user = User::fromToken();

		$votacion_actual_id = Request::input('votacion_id');

		$this->exigirQueLaUrnaNoEstePausada($votacion_actual_id);
		$voto_blanco 		= false;
		
		if (Request::has('blanco_aspiracion_id')) {
			$voto_blanco 		= true;
			$aspiracion_id 		= Request::input('blanco_aspiracion_id');
		}else{
			$aspiracion_id = VtCandidato::findOrFail(Request::input('candidato_id'))->aspiracion_id;
		}
		
		


		VtVoto::verificarNoVoto($aspiracion_id, $user->user_id);

		try {
			if ($voto_blanco) {
				$voto = new VtVoto;
				$voto->user_id				=	$user->user_id;
				$voto->blanco_aspiracion_id	=	$aspiracion_id;
				$voto->locked				=	0;
				$voto->save();
			}else{
				$voto = new VtVoto;
				$voto->user_id			=	$user->user_id;
				$voto->candidato_id		=	Request::input('candidato_id');
				$voto->locked			=	0;
				$voto->save();
			}
			
			$aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=?', [$votacion_actual_id]);
			$completos = VtVotacion::verificarVotosCompletos($aspiraciones, $votacion_actual_id, $user->user_id);

			$voto->completo = $completos; // Para verificar en el frontend cuando se guarde el voto.

			return $voto;
			
		} catch (\Exception $e) {
			return response()->json(['msg' => 'Error al intentar guardar el voto'], 422);
		}
	}

	
	/**
	 * Que la votación no esté pausada.
	 *
	 * Se busca por `id` y sin filtrar la papelera a propósito: si la votación no
	 * existe, esto **no dice nada** y el método sigue como siempre —fallar aquí
	 * cambiaría el comportamiento de un caso que no es el que se está arreglando—.
	 * Lo único que se añade es el candado.
	 */
	private function exigirQueLaUrnaNoEstePausada($votacion_id): void
	{
		$votacion = DB::selectOne('SELECT locked FROM vt_votaciones WHERE id = ?', [$votacion_id]);

		if ($votacion && $votacion->locked) {
			abort(423, 'La votación está pausada');
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

			// `permitir` NO significa «déjame ver los resultados», aunque lo
			// parezca: significa «dame la papeleta aunque estén ocultos». Lo dice
			// el front, que es quien lo manda — `TarjetonesCtrl` pide
			// `permitir: true` y `tarjetones.html` **no pinta `cantidad` por
			// ninguna parte**; solo foto, plancha y nombre. El que sí lo pinta es
			// `resultados.html`, y su controlador manda `permitir: false`.
			//
			// Así que quien decide si el conteo viaja es `can_see_results`, y solo
			// él. `permitir` decide otra cosa: si viaja la ESTRUCTURA. Hasta el 21
			// de agosto de 2026 el `if` mezclaba las dos y el conteo salía con la
			// papeleta, o sea que cualquier alumno con la elección abierta recibía
			// el escrutinio en vivo dentro del JSON — no en pantalla, pero en el
			// JSON, y el botón «Tarjetones» del front no lleva `ng-if`.
			//
			// Se recorta el número y NO la papeleta porque quitar `permitir`
			// apagaría el tarjetón en los dieciséis colegios. Ver 11-votaciones.md §1.
			$conEstructura = $votaciones[$j]->can_see_results || Request::input('permitir');
			$conConteo = (bool) $votaciones[$j]->can_see_results;

			if ($conEstructura) {

				$aspiraciones = VtAspiracion::where('votacion_id', $votaciones[$j]->id)->get();

				$result = [];

				foreach ($aspiraciones as $aspira) {
					$candidatos = VtCandidato::porAspiracion($aspira->id, $user->year_id);

					if ($conConteo) {
						for ($i=0; $i<count($candidatos); $i++) {

							$votos 	= VtVoto::deCandidato($candidatos[$i]->candidato_id, $aspira->id)[0];
							$candidatos[$i]->cantidad 	= $votos->cantidad;
							$candidatos[$i]->total 		= $votos->total;
						}
					}
					
					// Voto en blanco como candidato
					$blanco 	= ['nombres' => 'Voto en Blanco', 'voto_blanco' => true, 'foto_nombre' => 'voto_en_blanco.jpg'];

					if ($conConteo) {
						$consulta 	= 'SELECT count(*) as cantidad from vt_votos vv 
										where vv.blanco_aspiracion_id=:aspiracion_id and vv.deleted_at is null';
						$vt_blancos	= DB::select($consulta, [':aspiracion_id' => $aspira->id])[0];
						$blanco['cantidad'] = $vt_blancos->cantidad;
					}
						
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
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	public function deleteDestroy($id)
	{
		$candidato = VtCandidato::findOrFail($id);
		$candidato->delete();

		return $candidato;
	}

}