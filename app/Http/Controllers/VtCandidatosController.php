<?php namespace App\Http\Controllers;

use App\Models\VtAspiracion;
use App\Models\VtCandidato;
use App\Models\VtVotacion;
use App\Models\VtVoto;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Los candidatos: quién sale en la papeleta.
 *
 * # LO QUE SE HA IDO DE AQUÍ
 *
 * **`update($id)`** escribía `participante_id` sobre `vt_candidatos` —columna que
 * no existe en ninguna de las dieciséis bases, y que venía de `vt_participantes`,
 * la tabla que tiró la migración `2026_09_22_400000`— y **no la enrutaba nadie**:
 * `routes/api/votaciones.php` sólo publica `getIndex`, `getConaspiraciones`,
 * `postStore` y `deleteDestroy`. Se borra con la regla del repo: *sin ruta y roto
 * se borra; con ruta y roto se documenta*.
 */
class VtCandidatosController extends Controller {


	public function getIndex()
	{
		return VtCandidato::all();
	}


	/**
	 * Inscribe a alguien como candidato a un cargo.
	 *
	 * # LO QUE SE CIERRA AQUÍ: la inscripción que se guarda y no aparece
	 *
	 * Hasta hoy esto insertaba el `user_id` que viniera en el cuerpo **sin
	 * comprobar nada** ([05 §18.1](../../docs/migracion/05-codigo-muerto-y-roto.md)).
	 * Y el fallo no era que dejara inscribir a cualquiera: era que la papeleta
	 * —`VtCandidato::porAspiracion()`— sólo une con `alumnos` matriculados en el
	 * año, así que **un candidato que no lo fuera desaparecía de la lista sin dar
	 * error** ([11 §1](../../docs/migracion/11-votaciones.md)). El colegio
	 * inscribía a alguien, lo veía guardado, y el día de la elección no estaba.
	 *
	 * `VtCandidato::elegible()` es exactamente la condición de esa consulta, para
	 * que las dos no se puedan separar.
	 *
	 * # Y EL AÑO ES EL DE LA VOTACIÓN, no el de quien inscribe
	 *
	 * La comprobación y la lista que se devuelve usan **el mismo** año, y es el de
	 * la elección. Antes la lista salía con `$user->year_id` —el año en que esté
	 * mirando el secretario, que el login mueve—, así que inscribir desde 2025 una
	 * elección de 2026 devolvía una lista vacía y parecía que no se había
	 * guardado. Si la votación no tiene año, se cae al del usuario, que es lo que
	 * hacía siempre.
	 */
	public function postStore()
	{
		$user = User::fromToken();

		$user_id 			= Request::input('user_id');
		$aspiracion_id 		= Request::input('aspiracion_id');
		$plancha 			= Request::input('plancha');
		$numero 			= Request::input('numero');
		$locked 			= Request::input('locked', false);

		$votacion = DB::selectOne('SELECT v.id, v.year_id
			FROM vt_aspiraciones a
			INNER JOIN vt_votaciones v ON v.id = a.votacion_id AND v.deleted_at IS NULL
			WHERE a.id = ? AND a.deleted_at IS NULL', [$aspiracion_id]);

		if (! $votacion) {
			return response()->json([ 'error'=> 422, 'message'=> 'Ese cargo no existe' ], 422);
		}

		$year_id = $votacion->year_id ?: $user->year_id;

		if (! VtCandidato::elegible($user_id, $year_id)) {
			return response()->json([
				'error'   => 422,
				'message' => 'Esa persona no está matriculada en el año de la votación: no saldría en la papeleta',
			], 422);
		}

		$busqueda = VtCandidato::where('user_id', $user_id)
								->where('aspiracion_id', $aspiracion_id)->first();

		if ( $busqueda ) {
			return response()->json([ 'error'=> 400, 'message'=> 'Candidato ya inscrito' ], 400);
		}else{
			$candidato = new VtCandidato;
			$candidato->user_id				=	$user_id;
			$candidato->aspiracion_id		=	$aspiracion_id;
			$candidato->plancha				=	$plancha;
			$candidato->numero				=	$numero;
			$candidato->locked				=	$locked;
			$candidato->save();
		}

		$candidatos = VtCandidato::porAspiracion($aspiracion_id, $year_id);

		return $candidatos;
	}


	/**
	 * La papeleta de prueba: los cargos de la elección en curso con sus candidatos.
	 *
	 * # ESTO RESPONDÍA 500 A ALUMNOS Y ACUDIENTES DESDE SIEMPRE
	 *
	 * Llamaba a `VtVotacion::actualInscrito($user)`, **un método que no existe**
	 * —el que hay es `actualesInscrito()`, en plural, y devuelve un array—, así
	 * que la rama de las familias reventaba antes de llegar a la primera consulta
	 * ([05 §18.5](../../docs/migracion/05-codigo-muerto-y-roto.md),
	 * [11 §7.2](../../docs/migracion/11-votaciones.md)). La comprobación de nulo
	 * existía y cubría sólo al personal: no faltaba, estaba en la otra rama.
	 *
	 * La rama de las familias pasa a `actualesInscrito()` —estamento encendido, y
	 * si es estudiante, matrícula viva en un grupo no apartado del censo nuevo— y
	 * **toma la primera**, que es la elección `actual` y en acción. Sin ninguna,
	 * contesta el mismo `sin_votaciones_propias` que el personal ya recibía: no un
	 * 500, y no una lista vacía que la pantalla pinta como una papeleta sin cargos.
	 *
	 * **La rama del personal se queda como estaba**, con `VtVotacion::actual()`, y
	 * eso es a propósito: significa «la elección que yo creé y marqué como actual»,
	 * que es otra pregunta y la que la pantalla de configuración espera. Unificar
	 * las dos ramas le cambiaría a un secretario lo que ve, y eso es un despliegue
	 * en dieciséis colegios, no un arreglo.
	 *
	 * # `votado` ES UN BOOLEANO, y antes era un array vacío siempre
	 *
	 * La consulta que lo rellenaba estaba comentada desde hacía años —y llamaba a
	 * `votesInAspiracion()`, borrada con `vt_participantes`— así que `votado`
	 * salía `[]` en todas las respuestas. Y `[]` en JavaScript es **cierto**, o
	 * sea que la pantalla llevaba años creyendo que estaba todo votado.
	 *
	 * Lo contesta `VtVoto::deUsuarioEnCargo()`, pero **sólo su existencia**: esa
	 * consulta trae el `candidato_id` y publicarlo es la fuga del voto secreto que
	 * la 11 §6 manda cerrar. Decir *que* votó es legítimo; decir *a quién*, no.
	 */
	public function getConaspiraciones()
	{
		$user = User::fromToken();

		if ($user->tipo == 'Alumno' || $user->tipo == 'Acudiente') {
			$votaciones = VtVotacion::actualesInscrito($user);
			$votacion   = count($votaciones) > 0 ? $votaciones[0] : null;
		} else {
			$votacion = VtVotacion::actual($user);
		}

		// `is_object` y no sólo `! $votacion`: `VtVotacion::actual()` devuelve un
		// **array vacío** cuando no hay elección, no `null`, y de ahí sale el
		// `$votacion->id` sobre un array que el nivel 7 caza —es la familia del §9
		// de la 05, «se usa como objeto algo que no lo es»—.
		if (! $votacion || ! is_object($votacion)) {
			// Lo que el personal ya recibía cuando no tenía elección propia. Una
			// sola forma para los cuatro tipos de usuario: la pantalla distingue
			// «no hay elección» de «elección sin cargos», que con una lista vacía
			// no podía.
			return [['sin_votaciones_propias' => true]];
		}

		$year_id = $votacion->year_id ?: $user->year_id;

		$aspiraciones = VtAspiracion::where('votacion_id', $votacion->id)->get();

		$result = array();

		foreach ($aspiraciones as $aspira) {
			// Sin el `username`, que es el documento de identidad: ver
			// `VtCandidato::sinElDocumento()`.
			$candidatos = VtCandidato::sinElDocumento(VtCandidato::porAspiracion($aspira->id, $year_id));

			$blanco = ['nombres' => 'Voto en Blanco', 'voto_blanco' => true, 'foto_nombre' => 'voto_en_blanco.jpg'];
			array_push($candidatos, $blanco);
			$aspira->candidatos = $candidatos;

			$aspira->votado = VtVoto::deUsuarioEnCargo($aspira->id, $user->user_id) !== null;

			array_push($result, $aspira);
		}

		return $result;
	}


	/**
	 * Quita un candidato de la papeleta (borrado lógico: su voto se queda).
	 *
	 * **El dueño del candidato es el dueño de su elección**, igual que en
	 * `aspiraciones/destroy` y `votaciones/destroy`. Hasta el 24 sep 2026 éste era
	 * el único borrado del módulo sin esa pregunta, y cualquiera de las cuentas de
	 * `auth.personal` quitaba un candidato de la elección de otro.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		$candidato = VtCandidato::findOrFail($id);

		// Un candidato cuyo cargo ya no existe no tiene elección que administrar.
		$aspiracion = VtAspiracion::find($candidato->aspiracion_id);

		if (! $aspiracion) {
			abort(404, 'El cargo de ese candidato no existe.');
		}

		VtVotacion::exigirAdministrable($aspiracion->votacion_id, $user);

		$candidato->delete();

		return $candidato;
	}

}
