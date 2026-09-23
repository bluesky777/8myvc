<?php namespace App\Http\Controllers;

use App\User;
use App\Models\VtVotacion;
use App\Models\VtVoto;



/**
 * Lo que el panel tiene que avisar: hoy, sólo si hay una elección abierta.
 *
 * ## Las dos líneas que se cayeron con `vt_participantes`
 *
 * Preguntaba `VtParticipante::isSigned($user_id, $votacion_id)` —si esa persona
 * estaba inscrita en el censo— y después `VtVoto::hasVoted($votacion_id,
 * $participante_id)`. La tabla y el modelo dejaron de existir el 22 sep 2026 con
 * el rediseño del censo, así que las dos consultas hay que rehacerlas, no
 * traducirlas: **ya no hay a quién inscribir.**
 *
 * Y la segunda estaba rota de antes: `hasVoted` filtraba por
 * `vt_votos.participante_id`, una columna que **no existe en ninguna de las
 * dieciséis bases** ([05 §18](../../docs/migracion/05-codigo-muerto-y-roto.md)).
 * La sustituye `VtVoto::yaVoto($votacion_id, $user_id)`, que recibe un `users.id`
 * y no el id de un participante.
 *
 * `signed` pasa a ser **un booleano** y no la fila del censo: es la misma
 * pregunta que se hace la pantalla de votar —¿está encendido mi estamento, y si
 * soy estudiante estoy en el censo?— y la respuesta es sí o no. El nombre se
 * conserva porque es lo que lee el cliente.
 */
class EventosController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();


		$votacion = VtVotacion::where('in_action', '=', true)->first();

		$hayVotacion = false;
		$signed = false;
		$voted = false;
		$rutear = false;

		if ($votacion){

			$hayVotacion = true;

			$signed = $this->puedeVotar($votacion, $user);

			if ($signed) {
				$voted = VtVoto::yaVoto($votacion->id, $user->user_id);
				$rutear = true;
			}

			$eventos = array(
				'votaciones'=>array(
					'hay'	=> $hayVotacion,
					'signed'=> $signed,
					'voted' => $voted,
					'rutear'=> $rutear,
					'state' => 'votaciones.votar',
				)
			);


			return $eventos;

		}else{
			$eventos = array(
				'votaciones'=>array(
					'hay'	=> false,
					'signed'=> false,
					'voted' => false,
					'rutear'=> false,
					'state' => '',
				)
			);
			return $eventos;
		}


	}

	/**
	 * Las dos preguntas encadenadas de `VtVotacion::actualesInscrito()`, para una
	 * elección y una persona.
	 *
	 * El personal y las familias no tienen censo: les basta el interruptor de su
	 * estamento. El estudiante necesita además estar en un grupo vivo del año **de
	 * la votación** que participe.
	 */
	private function puedeVotar($votacion, $user)
	{
		if (! VtVotacion::admiteA($votacion, $user)) {
			return false;
		}

		if (VtVotacion::estamentoDe($user) !== VtVotacion::ESTAMENTO_ESTUDIANTE) {
			return true;
		}

		return VtVotacion::estaEnElCenso($votacion, $user->user_id) !== null;
	}

}
