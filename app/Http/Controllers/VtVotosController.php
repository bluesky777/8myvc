<?php namespace App\Http\Controllers;

use App\Models\VtAspiracion;
use App\Models\VtCandidato;
use App\Models\VtGrupoVotacion;
use App\Models\VtMesa;
use App\Models\VtVotacion;
use App\Models\VtVoto;
use App\Models\Year;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * La urna.
 *
 * # LO QUE SE HA IDO DE AQUÍ, y por qué no vuelve
 *
 * - **`getIndex()`** — `VtVoto::all()`: todos los votos del colegio con el
 *   `user_id` de quien emitió cada uno, servido a cualquier `auth.personal`. Es
 *   la fuga del voto secreto de la [11 §7.1](../../docs/migracion/11-votaciones.md),
 *   y no la llamaba ningún cliente. Lo que hace falta de verdad —saber **quién
 *   ya votó**, sin decir a quién— lo contesta `mesas/{mesa}/lista`, y la
 *   auditoría de verdad es otra ruta y sólo para superadmin.
 * - **`putUpdate()` y `deleteDestroy()`** — los dos hacían
 *   `VtCandidato::findOrFail($id)`: uno **borraba un candidato de la papeleta**
 *   con todos sus votos detrás, y el otro escribía `tipo` y `abrev` sobre
 *   `vt_candidatos`, que no tiene esas columnas, así que respondía 422 con
 *   cualquier cuerpo y no escribía nada nunca ([11 §4](../../docs/migracion/11-votaciones.md)).
 *   **El voto es inmutable**: no se cambia, no se repite y no se borra, y ahora
 *   eso lo sostiene el índice `vt_votos_un_voto_por_cargo` y no un método que
 *   borraba por detrás. Si algún día hay que anular un voto, será un endpoint
 *   nuevo con auditoría —quién lo anuló y por qué—, no un `DELETE` que recibe
 *   un id de otra tabla.
 */
class VtVotosController extends Controller {


	/**
	 * Emite el voto.
	 *
	 * # ESTO ERA UN AGUJERO Y AHORA ES UNA PUERTA
	 *
	 * Hasta hoy `postStore()` **no comprobaba nada** salvo `locked`: ni el censo,
	 * ni las fechas, ni que el candidato fuera de esa elección. Cualquiera con un
	 * token metía una fila acertando un `candidato_id`, y lo único que impedía que
	 * el recuento se inflara era que `verificarNoVoto()` borrase el voto anterior
	 * —o sea, un segundo fallo tapando al primero
	 * ([11 §2 y §3](../../docs/migracion/11-votaciones.md))—.
	 *
	 * Las cinco puertas, en este orden:
	 *
	 *   1. la elección existe, no está pausada, está abierta y estamos dentro de
	 *      sus fechas;
	 *   2. quien vota es de un estamento encendido **y**, si es estudiante, está
	 *      en el censo (`VtVotacion::estaEnElCenso()`);
	 *   3. el cargo —o el candidato— es de **esa** elección;
	 *   4. no ha votado ya ese cargo;
	 *   5. si la elección es `solo_en_mesa` y su grupo está en `modo = 'mesa'`,
	 *      no puede votar por su cuenta.
	 *
	 * # LOS CÓDIGOS DE ESTADO SON DE VERDAD, y eso es un cambio de contrato
	 *
	 * Antes «ya votaste» y «bloqueada» salían **dentro de un 200** con un `msg`,
	 * así que el cliente tenía que leerse el cuerpo para saber si su voto había
	 * entrado. Ahora:
	 *
	 *   - **201** — el voto entró (devuelve el modelo recién creado).
	 *   - **409** — ya votó ese cargo, con la hora y con quién delante, y **sin
	 *     borrar nada**. Nunca con el candidato.
	 *   - **423** — la urna está pausada, cerrada o fuera de fechas.
	 *   - **403** — no es su elección: fuera del censo, estamento apagado, o
	 *     votando por su cuenta un grupo que vota en mesa.
	 *   - **422** — el cuerpo no dice un cargo de esta elección.
	 *
	 * # ⚠ `in_action` SE COMPRUEBA AHORA, Y ESO CONTRADICE AL 11 §2.1
	 *
	 * Ese documento recoge de Joseth (21 ago 2026) que `in_action` **no es un
	 * candado sino un redirector del front**, y avisa de que comprobarlo aquí
	 * «habría apagado la votación por el menú». El encargo de esta tanda pide
	 * justo lo contrario —«la votación tiene que estar `in_action`»— y así queda
	 * escrito, porque con las mesas la elección pasa a tener una jornada con
	 * principio y final y `in_action` es lo que la enciende.
	 *
	 * **Queda anotado aquí porque es una decisión, no un arreglo**: si el colegio
	 * quiere que se pueda seguir votando por el menú con `in_action = 0`, lo que
	 * hay que quitar es **el `if` de `in_action` de
	 * `VtVotacion::exigirUrnaAbierta()`**, que es el único que lo mira.
	 */
	public function postStore()
	{
		$quien_pide = User::fromToken();

		$votacion = VtVotacion::exigirUrnaAbierta(Request::input('votacion_id'));

		// ── Quién vota, y desde dónde ───────────────────────────────────────
		[$votante_id, $mesa_id, $asistido_por, $origen] = $this->deQuienEsEsteVoto($quien_pide, $votacion);

		// ── Qué cargo, y a quién ────────────────────────────────────────────
		[$aspiracion_id, $candidato_id] = $this->elCargoDeEsteVoto($votacion);

		// ── El censo ────────────────────────────────────────────────────────
		$grupo_id = $this->exigirQueEsteEnElCenso($votacion, $votante_id);

		/*
		 * `solo_en_mesa` es el único sitio donde el censo y las mesas se tocan:
		 * el grupo que vota en mesa **no** puede votar por su cuenta. Sin este
		 * interruptor, la mesa es un atajo y no una mesa.
		 */
		if ($origen === VtVoto::ORIGEN_PROPIO
			&& $votacion->solo_en_mesa
			&& $this->elGrupoVotaEnMesa($votacion->id, $grupo_id)) {
			abort(403, 'Este grupo vota en su mesa');
		}

		// ── ¿Ya votó este cargo? ────────────────────────────────────────────
		$ya = VtVoto::constancia($votacion->id, $votante_id, $aspiracion_id);

		if (count($ya) > 0) {
			return $this->yaVoto($ya[0]);
		}

		// ── La fila ─────────────────────────────────────────────────────────
		try {
			$voto = new VtVoto;
			$voto->user_id			= $votante_id;
			$voto->votacion_id		= $votacion->id;
			$voto->aspiracion_id	= $aspiracion_id;
			$voto->candidato_id		= $candidato_id;   // nulo = voto en blanco
			$voto->mesa_id			= $mesa_id;
			$voto->asistido_por		= $asistido_por;
			$voto->origen			= $origen;
			$voto->segundos			= $this->segundosQueTardo();
			$voto->locked			= 0;
			$voto->save();

		} catch (QueryException $e) {
			/*
			 * 1062 es el índice único `vt_votos_un_voto_por_cargo`, y llegar aquí
			 * significa que **dos peticiones entraron a la vez**: la comprobación
			 * de arriba las dejó pasar a las dos porque en ese instante no había
			 * fila. Es la carrera que el 11 §3 describe y la razón por la que la
			 * regla vive en la base y no en PHP.
			 *
			 * Se contesta lo mismo que arriba —409 con la constancia— para que el
			 * cliente no distinga «llegué segundo por un milisegundo» de «ya había
			 * votado ayer»: para quien vota son el mismo hecho.
			 */
			if ($this->esElUnicoDelVoto($e)) {
				$ya = VtVoto::constancia($votacion->id, $votante_id, $aspiracion_id);

				return $this->yaVoto(count($ya) > 0 ? $ya[0] : null);
			}

			throw $e;
		}

		$aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=? AND deleted_at IS NULL', [$votacion->id]);

		// Para que el frontend sepa, al guardar, si ya terminó la papeleta.
		$voto->completo = VtVotacion::verificarVotosCompletos($aspiraciones, $votacion->id, $votante_id);

		return $voto;
	}


	/*
	 * `laUrnaAbierta()` vivía aquí y se fue el 22 sep 2026 a
	 * `VtVotacion::exigirUrnaAbierta()`, donde ya está junta con la copia que
	 * tenía `VtMesasController`: el docblock de allí cuenta qué hacía cada una y
	 * cuál se quedó. Aquí no queda nada que tocar.
	 */


	/**
	 * Quién vota, en qué mesa y con quién delante.
	 *
	 * # EL SALTO QUE ESTO AUTORIZA
	 *
	 * En una mesa **vota el alumno pero la petición la manda la cuenta del que
	 * conduce**. O sea que hay que escribir una fila con el `user_id` de otra
	 * persona, y eso, sin nada más, es un endpoint que deja al personal votar por
	 * cualquiera.
	 *
	 * Lo autoriza la marca que devolvió `mesas/{mesa}/abrir`: un token firmado con
	 * `config('app.key')` que dice esta mesa, esta elección, este votante y este
	 * asistente, y caduca a los diez minutos. El porqué entero —y por qué no es
	 * una fila en una tabla— está en `VtMesa`.
	 *
	 * **La marca tiene que ser de quien pide.** Si el `asistente` que lleva dentro
	 * no es el del token de la petición, se rechaza: si no, la marca de un docente
	 * le serviría a cualquier otro para votar por ese alumno.
	 *
	 * Sin marca, se vota por uno mismo y `origen` es `'propio'`. `origen` **no se
	 * cree del cuerpo**: lo decide la marca, porque un cliente que puede escribir
	 * `origen = 'mesa'` puede falsear de dónde salió un voto.
	 *
	 * @return array{0:int, 1:?int, 2:?int, 3:string}
	 */
	private function deQuienEsEsteVoto($quien_pide, $votacion): array
	{
		$marca = VtMesa::leerSesion(Request::input('sesion_mesa'));

		if ($marca === null) {
			if (Request::input('origen') === VtVoto::ORIGEN_MESA) {
				abort(403, 'La sesión de la mesa no vale: vuelve a abrir la papeleta');
			}

			return [(int) $quien_pide->user_id, null, null, VtVoto::ORIGEN_PROPIO];
		}

		if ((int) $marca->votacion !== (int) $votacion->id) {
			abort(403, 'La sesión de la mesa es de otra votación');
		}

		if ((int) $marca->asistente !== (int) $quien_pide->user_id) {
			abort(403, 'La sesión de la mesa no es de quien la está usando');
		}

		return [(int) $marca->votante, (int) $marca->mesa, (int) $marca->asistente, VtVoto::ORIGEN_MESA];
	}


	/**
	 * El cargo de este voto, y el candidato si no es en blanco.
	 *
	 * **`candidato_id` nulo es el voto en blanco**, y `aspiracion_id` dice de qué
	 * cargo. Antes el blanco vivía en `blanco_aspiracion_id` —una columna aparte,
	 * o sea dos caminos para el mismo dato— y esa columna se fue con la migración
	 * `2026_09_22_600000`. Se sigue leyendo el nombre viejo del cuerpo **sólo como
	 * alias** para que un cliente sin actualizar no se quede sin poder votar en
	 * blanco el día del despliegue.
	 *
	 * Lo que aquí se cierra, y no se comprobaba: **que el candidato sea de esta
	 * elección**. Sin ello, el cuerpo podía traer el `candidato_id` de la elección
	 * de otro año y la fila entraba, contando en una urna que no era la suya.
	 *
	 * @return array{0:int, 1:?int}
	 */
	private function elCargoDeEsteVoto($votacion): array
	{
		$candidato_id = Request::input('candidato_id');

		if ($candidato_id) {
			$candidato = DB::selectOne('SELECT c.id, c.aspiracion_id
				FROM vt_candidatos c
				INNER JOIN vt_aspiraciones a ON a.id = c.aspiracion_id AND a.deleted_at IS NULL
				WHERE c.id = ? AND c.deleted_at IS NULL AND a.votacion_id = ?',
				[$candidato_id, $votacion->id]);

			if (! $candidato) {
				abort(422, 'Ese candidato no es de esta votación');
			}

			return [(int) $candidato->aspiracion_id, (int) $candidato->id];
		}

		$aspiracion_id = Request::input('aspiracion_id', Request::input('blanco_aspiracion_id'));

		$aspiracion = DB::selectOne('SELECT a.id FROM vt_aspiraciones a
			WHERE a.id = ? AND a.deleted_at IS NULL AND a.votacion_id = ?',
			[$aspiracion_id, $votacion->id]);

		if (! $aspiracion) {
			abort(422, 'Ese cargo no es de esta votación');
		}

		return [(int) $aspiracion->id, null];
	}


	/**
	 * Que quien vota pueda votar en esta elección, y devuelve su grupo.
	 *
	 * Son **dos preguntas encadenadas**, las mismas que `actualesInscrito()`:
	 * ¿está encendido su estamento, y —si es estudiante— está en el censo? El
	 * personal y las familias no tienen censo, les basta el flag; el estudiante
	 * necesita los dos, y de ahí sale el `grupo_id` que después decide lo de
	 * `solo_en_mesa`.
	 *
	 * El estamento se lee de `users.tipo` **del votante**, no del que manda la
	 * petición: en una mesa son dos personas distintas.
	 */
	private function exigirQueEsteEnElCenso($votacion, int $votante_id)
	{
		$votante = DB::selectOne('SELECT u.id, u.tipo FROM users u WHERE u.id = ? AND u.deleted_at IS NULL', [$votante_id]);

		if (! $votante) {
			abort(422, 'Ese votante no existe');
		}

		if (! VtVotacion::admiteA($votacion, $votante)) {
			abort(403, 'En esta votación no vota tu estamento');
		}

		if (VtVotacion::estamentoDe($votante) !== VtVotacion::ESTAMENTO_ESTUDIANTE) {
			return null;
		}

		$fila = VtVotacion::estaEnElCenso($votacion, $votante_id);

		if (! $fila) {
			abort(403, 'No estás en el censo de esta votación');
		}

		return $fila->grupo_id;
	}


	/**
	 * Si el grupo de este votante está apartado para votar en mesa.
	 *
	 * Sin fila en `vt_grupos_votacion` el grupo vota como todos: la tabla son
	 * **excepciones**, no un censo.
	 */
	private function elGrupoVotaEnMesa($votacion_id, $grupo_id): bool
	{
		if (! $grupo_id) {
			return false;
		}

		$fila = DB::selectOne('SELECT modo FROM vt_grupos_votacion WHERE votacion_id = ? AND grupo_id = ? LIMIT 1',
			[$votacion_id, $grupo_id]);

		return $fila !== null && $fila->modo === VtGrupoVotacion::MODO_MESA;
	}


	/**
	 * El 409 de «aquí ya hay un voto», con lo que se puede contar y nada más.
	 *
	 * Lleva la hora y, si fue en mesa, cuál y quién estaba delante — que es lo que
	 * pinta la pantalla de bloqueo. **No lleva el candidato**: decir *que* votó
	 * hace falta, decir *a quién* es la fuga del voto secreto (11 §6).
	 *
	 * Y no borra nada. El voto anterior se queda donde está, que es la diferencia
	 * entera con `verificarNoVoto()`.
	 */
	private function yaVoto($constancia)
	{
		return response()->json([
			'error' => 409,
			'message' => 'Ya votaste este cargo',
			'voto' => $constancia,
		], 409);
	}


	/**
	 * Cuánto tardó en votar, para la auditoría de la mesa.
	 *
	 * Lo cuenta el cliente y por eso **no se cree del todo**: se acota a lo que
	 * cabe en la columna (`smallint unsigned`) y se descarta lo que no sea un
	 * número. Nulo es «no se sabe», que es distinto de cero.
	 */
	private function segundosQueTardo(): ?int
	{
		$segundos = Request::input('segundos');

		if (! is_numeric($segundos) || (int) $segundos < 0) {
			return null;
		}

		return min((int) $segundos, 65535);
	}


	/** Si esta excepción es el índice único del voto y no otra cosa. */
	private function esElUnicoDelVoto(QueryException $e): bool
	{
		return ($e->errorInfo[1] ?? null) === 1062
			&& strpos($e->getMessage(), 'vt_votos_un_voto_por_cargo') !== false;
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
			//
			// ── 23 sep 2026: y aquí el cambio va en sentido CONTRARIO al de los
			// otros dos sitios del recuento ──
			//
			// El §1 dejó esto en `can_see_results` **a secas, sin excepción para
			// nadie**, mientras `resultados/{id}` y `en-accion-inscrito` se lo daban a
			// todo el personal. Con la decisión del 23 sep los tres contestan lo mismo
			// —lo ve quien puede publicarlo—, y para esta puerta eso significa
			// **ensanchar**: sin ello, coordinación no puede mirar el tarjetón con
			// números antes de publicar, que es justo lo que se le acaba de encargar.
			// El predicado y quién lo decidió, en `VtVotacion::puedePublicarResultados()`.
			$conEstructura = $votaciones[$j]->can_see_results || Request::input('permitir');
			$conConteo = (bool) $votaciones[$j]->can_see_results
				|| VtVotacion::puedePublicarResultados($votaciones[$j], $user);

			if ($conEstructura) {

				$aspiraciones = VtAspiracion::where('votacion_id', $votaciones[$j]->id)->get();

				$result = [];

				foreach ($aspiraciones as $aspira) {
					// Sin el `username`, que es el documento de identidad: ver
					// `VtCandidato::sinElDocumento()`. Esta ruta no lleva
					// `auth.personal`, así que la papeleta la recibe cualquier
					// alumno con token — era la tercera puerta.
					$candidatos = VtCandidato::sinElDocumento(
						VtCandidato::porAspiracion($aspira->id, $user->year_id));

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
						/*
						 * Antes era SQL crudo contra `blanco_aspiracion_id`, la
						 * columna que la migración `2026_09_22_600000` tiró: el
						 * blanco ya no es una columna aparte, es `candidato_id`
						 * nulo. Y el conteo vive en el modelo porque este mismo
						 * SQL estaba repetido en tres sitios, cada uno con su
						 * filtro de papelera — y el viejo dejaba los blancos
						 * fuera del `total`, así que los porcentajes del tarjetón
						 * salían de una base que no era la urna.
						 */
						$vt_blancos	= VtVoto::enBlanco($aspira->id)[0];
						$blanco['cantidad'] = $vt_blancos->cantidad;
						$blanco['total'] = $vt_blancos->total;
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

}
