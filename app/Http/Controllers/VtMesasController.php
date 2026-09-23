<?php namespace App\Http\Controllers;

use App\Models\VtCandidato;
use App\Models\VtMesa;
use App\Models\VtVotacion;
use App\Models\VtVoto;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;

/**
 * Las mesas de votación: un equipo, quien lo conduce y los grupos que pasan.
 *
 * # QUÉ RESUELVE, en una línea
 *
 * Un portátil, una persona del colegio delante, y los cursos que pasan por él.
 * Es el caso que el módulo viejo no sabía representar: en un colegio con veinte
 * equipos y quinientos alumnos, votar «cada uno desde su cuenta» no es una
 * elección, es una cola.
 *
 * # LAS DOS FORMAS DE CONDUCIR UNA MESA, y hay que mirar las dos
 *
 *   1. **Nombrada**: una fila en `vt_mesa_usuarios`. Es el colegio que monta las
 *      mesas a mano, con su nombre y sus grupos.
 *   2. **Implícita**: con `vt_votaciones.titulares_conducen = 1` —el defecto— el
 *      titular de un grupo conduce el suyo **sin que exista ninguna fila**. Es lo
 *      que va a pasar en casi todos los colegios: obligar a crear una mesa por
 *      salón convierte una elección de una mañana en una tarde de configuración.
 *
 * `GET mesas/mias` devuelve las dos. Y ojo al paso que se olvida:
 * `grupos.titular_id` apunta a `profesores.id`, **no a `users.id`**.
 *
 * # LA MESA IMPLÍCITA SE MATERIALIZA AL EMPEZAR, y ése es el único sitio
 *
 * `vt_votos.mesa_id` es una clave ajena de verdad: **no se puede guardar un voto
 * contra una mesa que no existe**. Así que la implícita tiene que convertirse en
 * fila en algún momento, y el momento es `POST mesas/mia-de-grupo`, que el front
 * llama cuando el titular abre de verdad la pantalla de su mesa — no antes.
 *
 * No se hace en `GET mesas/mias` **a propósito**: una lectura que escribe crea una
 * mesa por cada vez que alguien mira la pantalla, y al final del día el colegio
 * tiene cuarenta mesas vacías que nadie montó. Y es idempotente: volver a entrar
 * devuelve la misma.
 */
class VtMesasController extends Controller {


	/**
	 * Las mesas de una elección, con su equipo y sus grupos.
	 *
	 * Es la pantalla de configuración. Dos consultas más allá de la lista —los
	 * usuarios de todas las mesas y los grupos de todas las mesas— y no dos por
	 * mesa, que es el bucle de la [11 §6.2](../../docs/migracion/11-votaciones.md).
	 */
	public function getIndex()
	{
		$votacion_id = Request::input('votacion_id');

		$mesas = DB::select('SELECT m.* FROM vt_mesas m WHERE m.votacion_id = ? ORDER BY m.nombre', [$votacion_id]);

		return $this->conEquipoYGrupos($mesas);
	}


	/** Crea una mesa con su equipo y sus grupos. */
	public function postStore()
	{
		$votacion_id = Request::input('votacion_id');

		$votacion = DB::selectOne('SELECT id FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL', [$votacion_id]);

		if (! $votacion) {
			abort(422, 'Esa votación no existe');
		}

		$nombre = trim((string) Request::input('nombre'));

		if ($nombre === '') {
			abort(422, 'La mesa necesita un nombre');
		}

		$mesa = DB::transaction(function () use ($votacion, $nombre) {
			$mesa = new VtMesa;
			$mesa->votacion_id	= $votacion->id;
			$mesa->nombre		= mb_substr($nombre, 0, 120);
			$mesa->activa		= (int) Request::input('activa', 1);
			$mesa->save();

			$this->ponerEquipo($mesa, Request::input('usuarios', []));
			$this->ponerGrupos($mesa, $votacion->id, Request::input('grupos', []));

			return $mesa;
		});

		return $this->conEquipoYGrupos([$mesa])[0];
	}


	/**
	 * Cambia el nombre, el interruptor, el equipo o los grupos.
	 *
	 * **`activa` no es `deleted_at`**: una mesa se apaga cuando se acaba el papel
	 * o se cae la red y se vuelve a encender media hora después. Eso conserva la
	 * mesa, su equipo, sus grupos y los votos que ya salieron de ella.
	 */
	public function putUpdate($id)
	{
		$mesa = VtMesa::findOrFail($id);

		if (Request::has('nombre')) {
			$nombre = trim((string) Request::input('nombre'));

			if ($nombre === '') {
				abort(422, 'La mesa necesita un nombre');
			}

			$mesa->nombre = mb_substr($nombre, 0, 120);
		}

		if (Request::has('activa')) {
			$mesa->activa = (int) Request::input('activa');
		}

		DB::transaction(function () use ($mesa) {
			$mesa->save();

			if (Request::has('usuarios')) {
				$this->ponerEquipo($mesa, Request::input('usuarios', []));
			}

			if (Request::has('grupos')) {
				$this->ponerGrupos($mesa, $mesa->votacion_id, Request::input('grupos', []));
			}
		});

		return $this->conEquipoYGrupos([$mesa])[0];
	}


	/**
	 * Borra una mesa.
	 *
	 * Sin papelera, que es lo que decidió la migración: una mesa borrada no tiene
	 * ningún uso posterior. **Los votos que salieron de ella no se van con ella**
	 * —`vt_votos.mesa_id` es `ON DELETE SET NULL`—, y eso es a propósito: el voto
	 * manda. Lo que se pierde es de qué mesa salió, no el voto.
	 *
	 * Para apagarla durante la jornada está `activa`, no esto.
	 */
	public function deleteDestroy($id)
	{
		$mesa = VtMesa::findOrFail($id);
		$mesa->delete();

		return $mesa;
	}


	/**
	 * Las mesas que me toca conducir a mí.
	 *
	 * Las nombradas —fila en `vt_mesa_usuarios`— y, si la elección lleva
	 * `titulares_conducen`, **una mesa implícita por cada grupo del que soy
	 * titular**, sin que nadie la haya creado. Las implícitas llegan con
	 * `id: null` e `implicita: true`; para usarlas hay que pedirle una fila de
	 * verdad a `POST mesas/mia-de-grupo`, y el porqué está en la cabecera.
	 *
	 * Sin `votacion_id`, las de todas las elecciones en acción. El año que se
	 * mira para el titular es **el de la votación**, no el del usuario: son dos
	 * años distintos y el login mueve el segundo.
	 */
	public function getMias()
	{
		$user = User::fromToken();

		$votacion_id = Request::input('votacion_id');

		if ($votacion_id) {
			$votaciones = DB::select('SELECT * FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL', [$votacion_id]);
		} else {
			$votaciones = DB::select('SELECT * FROM vt_votaciones WHERE in_action = 1 AND deleted_at IS NULL');
		}

		$salida = [];

		foreach ($votaciones as $votacion) {

			$nombradas = DB::select('SELECT m.* FROM vt_mesas m
				INNER JOIN vt_mesa_usuarios mu ON mu.mesa_id = m.id AND mu.user_id = ?
				WHERE m.votacion_id = ?
				ORDER BY m.nombre', [$user->user_id, $votacion->id]);

			$nombradas = $this->conEquipoYGrupos($nombradas);

			foreach ($nombradas as $mesa) {
				$mesa->implicita       = false;
				$mesa->votacion_nombre = $votacion->nombre;
				$mesa->cuenta_atras    = (int) $votacion->cuenta_atras;
				$mesa->doble_llave     = (bool) $votacion->doble_llave;
				$salida[]              = $mesa;
			}

			if (! $votacion->titulares_conducen) {
				continue;
			}

			// Los grupos que ya están en alguna mesa mía no se repiten como
			// implícitos: sería el mismo salón dos veces en la misma pantalla.
			$yaCubiertos = [];

			foreach ($nombradas as $mesa) {
				foreach ($mesa->grupos as $grupo) {
					$yaCubiertos[$grupo->id] = true;
				}
			}

			foreach (VtMesa::gruposDondeEsTitular($user->user_id, $votacion->year_id) as $grupo) {

				if (isset($yaCubiertos[$grupo->id])) {
					continue;
				}

				$salida[] = (object) [
					'id'              => null,
					'implicita'       => true,
					'votacion_id'     => $votacion->id,
					'votacion_nombre' => $votacion->nombre,
					'nombre'          => $grupo->nombre,
					'activa'          => 1,
					'cuenta_atras'    => (int) $votacion->cuenta_atras,
					'doble_llave'     => (bool) $votacion->doble_llave,
					'usuarios'        => [],
					'grupos'          => [$grupo],
				];
			}
		}

		return $salida;
	}


	/**
	 * Convierte mi mesa implícita de un grupo en una mesa de verdad.
	 *
	 * Idempotente: si ya hay una mesa de esta elección que atiende a ese grupo y
	 * que yo conduzco, devuelve **esa**. Sólo crea cuando no existe.
	 *
	 * Existe porque `vt_votos.mesa_id` es una clave ajena y un voto no se puede
	 * guardar contra una mesa que no está en la tabla. Ver la cabecera.
	 */
	public function postMiaDeGrupo()
	{
		$user = User::fromToken();

		$votacion = DB::selectOne('SELECT * FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL',
			[Request::input('votacion_id')]);

		if (! $votacion) {
			abort(422, 'Esa votación no existe');
		}

		if (! $votacion->titulares_conducen) {
			abort(403, 'En esta votación las mesas se nombran a mano');
		}

		$grupo_id = (int) Request::input('grupo_id');

		$esTitular = false;

		foreach (VtMesa::gruposDondeEsTitular($user->user_id, $votacion->year_id) as $grupo) {
			if ((int) $grupo->id === $grupo_id) {
				$esTitular = $grupo;
			}
		}

		if (! $esTitular) {
			abort(403, 'No eres titular de ese grupo');
		}

		$mesa = DB::transaction(function () use ($votacion, $grupo_id, $user, $esTitular) {

			$existente = DB::selectOne('SELECT m.* FROM vt_mesas m
				INNER JOIN vt_mesa_grupos mg ON mg.mesa_id = m.id AND mg.grupo_id = ?
				INNER JOIN vt_mesa_usuarios mu ON mu.mesa_id = m.id AND mu.user_id = ?
				WHERE m.votacion_id = ? LIMIT 1', [$grupo_id, $user->user_id, $votacion->id]);

			if ($existente) {
				return VtMesa::findOrFail($existente->id);
			}

			$mesa = new VtMesa;
			$mesa->votacion_id	= $votacion->id;
			$mesa->nombre		= mb_substr((string) $esTitular->nombre, 0, 120);
			$mesa->activa		= 1;
			$mesa->save();

			$this->ponerEquipo($mesa, [$user->user_id]);
			$this->ponerGrupos($mesa, $votacion->id, [$grupo_id]);

			return $mesa;
		});

		return $this->conEquipoYGrupos([$mesa])[0];
	}


	/**
	 * La lista del grupo para la pantalla de la mesa.
	 *
	 * Cada persona con su nombre, su foto y su estado: `sin_votar`, o `voto` con
	 * la hora y **dónde** —en esta mesa, en otra, o por su cuenta—.
	 *
	 * # LO QUE NO DEVUELVE
	 *
	 * **Por quién votó.** Ni el `candidato_id`, ni nada de donde deducirlo. Saber
	 * *quién ya votó* hace falta el día de la elección y es lo único que esto
	 * contesta; *a quién* es la fuga del voto secreto que la
	 * [11 §6](../../docs/migracion/11-votaciones.md) manda cerrar, y que hasta hoy
	 * salía entera por `participantes/votantes`.
	 *
	 * # ESTO SE PIDE CADA POCOS SEGUNDOS, así que es UNA consulta
	 *
	 * Una sola, con los votos colgados por `LEFT JOIN` sobre `vt_votos.user_id` y
	 * agregados en el mismo `GROUP BY` — o sea, una búsqueda por índice por
	 * alumno del salón, treinta y pico, y no un barrido de los mil y pico votos de
	 * la elección. Y ni una consulta dentro del bucle, que es la forma del fallo
	 * de la 11 §6.2: `P × (1 + A)` para P matriculados y A cargos.
	 *
	 * El censo se replica aquí —matrícula viva, estado que no sea `RETI` ni
	 * `DESE`, la más reciente por `MAX(id)`— en vez de llamar a
	 * `VtVotacion::censo()`, que devuelve el colegio entero: traerse mil filas
	 * para pintar treinta es el mismo bucle por el otro lado.
	 */
	public function getLista($id)
	{
		$user = User::fromToken();

		[$mesa, $votacion] = $this->laMesaQueConduzco($id, $user);

		$grupo_id = (int) Request::input('grupo_id');

		if (! VtMesa::atiendeAlGrupo($mesa->id, $grupo_id)) {
			abort(403, 'Ese grupo no pasa por esta mesa');
		}

		$estados   = VtVotacion::ESTADOS_QUE_NO_VOTAN;
		$comodines = implode(', ', array_fill(0, count($estados), '?'));

		$consulta = 'SELECT u.id as user_id, a.id as alumno_id, a.nombres, a.apellidos, a.sexo,
					m.estado, m.grupo_id,
					IFNULL(i.nombre, IF(a.sexo="F","default_female.png","default_male.png")) as foto_nombre,
					IFNULL(i2.nombre, IF(a.sexo="F","default_female.png","default_male.png")) as imagen_nombre,
					COUNT(DISTINCT vv.aspiracion_id) as cargos_votados,
					MAX(vv.created_at) as ultimo_voto,
					MAX(vv.mesa_id <=> ?) as en_esta_mesa,
					MAX(vv.mesa_id IS NOT NULL AND NOT (vv.mesa_id <=> ?)) as en_otra_mesa,
					MAX(vv.origen = ?) as por_su_cuenta
				FROM matriculas m
				INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
				INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
				INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL
				LEFT JOIN images i ON i.id = a.foto_id
				LEFT JOIN images i2 ON i2.id = u.imagen_id
				LEFT JOIN vt_votos vv ON vv.user_id = u.id AND vv.votacion_id = ?
				WHERE m.grupo_id = ? AND m.deleted_at IS NULL
				  AND m.estado NOT IN ('.$comodines.')
				  AND m.id = (SELECT MAX(m2.id)
								FROM matriculas m2
								INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.year_id = ? AND g2.deleted_at IS NULL
							   WHERE m2.alumno_id = m.alumno_id AND m2.deleted_at IS NULL)
				GROUP BY u.id, a.id, a.nombres, a.apellidos, a.sexo, m.estado, m.grupo_id, i.nombre, i2.nombre
				ORDER BY a.apellidos, a.nombres';

		$datos = array_merge(
			[$mesa->id, $mesa->id, VtVoto::ORIGEN_PROPIO, $votacion->year_id, $votacion->id, $grupo_id],
			$estados,
			[$votacion->year_id]
		);

		$personas = DB::select($consulta, $datos);

		$cargos = DB::selectOne('SELECT COUNT(*) as cuantos FROM vt_aspiraciones
			WHERE votacion_id = ? AND deleted_at IS NULL', [$votacion->id]);

		$cuantos_cargos = $cargos ? (int) $cargos->cuantos : 0;

		foreach ($personas as $persona) {
			$persona->cargos_votados = (int) $persona->cargos_votados;
			$persona->estado_voto    = $persona->cargos_votados > 0 ? 'voto' : 'sin_votar';
			$persona->completo       = $cuantos_cargos > 0 && $persona->cargos_votados >= $cuantos_cargos;
			$persona->donde          = $this->dondeVoto($persona);

			// Las tres banderas crudas no viajan: `donde` ya dice lo mismo y en
			// una palabra que la pantalla puede pintar.
			unset($persona->en_esta_mesa, $persona->en_otra_mesa, $persona->por_su_cuenta);
		}

		return [
			'mesa'     => ['id' => $mesa->id, 'nombre' => $mesa->nombre, 'activa' => (int) $mesa->activa],
			'grupo_id' => $grupo_id,
			'cargos'   => $cuantos_cargos,
			'personas' => $personas,
		];
	}


	/**
	 * Abre la papeleta de un votante en esta mesa. **El momento clave.**
	 *
	 * Lo que se comprueba, en orden y con su código:
	 *
	 *   - **403** si quien pide no conduce la mesa, si la mesa no atiende al
	 *     grupo del votante, o si el votante no está en el censo.
	 *   - **403** si la elección lleva `doble_llave` y la clave no cuadra con el
	 *     hash de `vt_votaciones.clave_doble_llave`.
	 *   - **423** si la urna está pausada, cerrada, fuera de fechas o la mesa
	 *     está apagada.
	 *   - **409** si ese votante **ya votó todo**, con la hora, si fue en mesa y
	 *     quién condujo. Es lo que pinta la pantalla de bloqueo, y no lleva el
	 *     candidato por lo de siempre.
	 *
	 * Si queda algo por votar —lo normal, y también el caso de quien se quedó a
	 * medias porque se cayó la red— devuelve **sólo los cargos que le faltan**,
	 * con sus candidatos, la cuenta atrás y la marca de sesión que `votos/store`
	 * va a exigir para aceptar un voto con `origen = 'mesa'`.
	 *
	 * La clave **se compara con `Hash::check`**, nunca en claro: la columna guarda
	 * un `bcrypt` por la misma razón que `users.password` — quien pueda mirar la
	 * tabla no tiene por qué poder abrir la urna.
	 */
	public function postAbrir($id)
	{
		$user = User::fromToken();

		[$mesa, $votacion] = $this->laMesaQueConduzco($id, $user);

		if (! $mesa->activa) {
			abort(423, 'Esta mesa está apagada');
		}

		// La fila ya está cargada, así que va tal cual: el 422 de «la votación de
		// esta mesa ya no existe» lo dio `laMesaQueConduzco()`. Y el orden es el de
		// antes —mesa, urna, clave—, que es el que decide el 423/403 de más abajo.
		VtVotacion::exigirUrnaAbierta($votacion);

		if ($votacion->doble_llave) {
			$clave = (string) Request::input('clave', '');

			if (! $votacion->clave_doble_llave || ! Hash::check($clave, $votacion->clave_doble_llave)) {
				abort(403, 'La clave de la urna no es correcta');
			}
		}

		$votante_id = (int) Request::input('votante_user_id');

		$censado = VtVotacion::estaEnElCenso($votacion, $votante_id);

		if (! $censado) {
			abort(403, 'Esa persona no está en el censo de esta votación');
		}

		if (! VtMesa::atiendeAlGrupo($mesa->id, $censado->grupo_id)) {
			abort(403, 'Esa persona no es de un grupo de esta mesa');
		}

		$faltan = VtVoto::cargosQueFaltan($votacion->id, $votante_id);

		if (count($faltan) === 0) {
			return response()->json([
				'error'   => 409,
				'message' => 'Esta persona ya votó',
				'votos'   => VtVoto::constancia($votacion->id, $votante_id),
			], 409);
		}

		$year_id = $votacion->year_id ?: $user->year_id;

		$papeleta = [];

		foreach ($faltan as $cargo) {
			$candidatos = VtCandidato::porAspiracion($cargo->id, $year_id);

			// El voto en blanco, como en el resto del módulo: un candidato más en
			// la lista, y al guardarlo es `candidato_id` nulo.
			array_push($candidatos, ['nombres' => 'Voto en Blanco', 'voto_blanco' => true, 'foto_nombre' => 'voto_en_blanco.jpg']);

			$cargo->candidatos = $candidatos;
			$papeleta[]        = $cargo;
		}

		return [
			'mesa'         => ['id' => $mesa->id, 'nombre' => $mesa->nombre],
			'votacion_id'  => $votacion->id,
			'votante'      => $this->fichaDelVotante($votante_id, $censado),
			'cuenta_atras' => (int) $votacion->cuenta_atras,
			'papeleta'     => $papeleta,
			'ya_votados'   => VtVoto::constancia($votacion->id, $votante_id),
			'sesion_mesa'  => VtMesa::sellarSesion($mesa->id, $votacion->id, $votante_id, (int) $user->user_id),
			'expira_en'    => VtMesa::SEGUNDOS_DE_SESION,
		];
	}


	/*
	 * ─────────────────────────────────────────────────────────────────────────
	 *  Lo de dentro
	 * ─────────────────────────────────────────────────────────────────────────
	 */

	/**
	 * La mesa y su elección, si quien pide puede estar delante de ella.
	 *
	 * Conduce quien tiene fila en `vt_mesa_usuarios` o —con `titulares_conducen`—
	 * es titular de alguno de sus grupos. Y además pasa el dueño de la elección:
	 * quien la montó tiene que poder mirar cómo va sin que lo apunten en el equipo
	 * de las veinte mesas.
	 */
	private function laMesaQueConduzco($mesa_id, $user): array
	{
		$mesa = VtMesa::findOrFail($mesa_id);

		$votacion = DB::selectOne('SELECT * FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL', [$mesa->votacion_id]);

		if (! $votacion) {
			abort(422, 'La votación de esta mesa ya no existe');
		}

		$esElDueno = $votacion->user_id && (int) $votacion->user_id === (int) $user->user_id;

		if (! $esElDueno && ! VtMesa::conduce($mesa->id, $user->user_id, (bool) $votacion->titulares_conducen)) {
			abort(403, 'No conduces esta mesa');
		}

		return [$mesa, $votacion];
	}


	/*
	 * `exigirUrnaAbierta()` era la copia de la de `VtVotosController` y se fue el
	 * 22 sep 2026 a `VtVotacion::exigirUrnaAbierta()`, que es donde las dos
	 * decían que tenían que estar. Allí queda escrito qué hacía cada una.
	 */


	/** Nombre y foto de quien va a votar, para que la mesa confirme que es él. */
	private function fichaDelVotante($votante_id, $censado)
	{
		$ficha = DB::selectOne('SELECT u.id as user_id, a.id as alumno_id, a.nombres, a.apellidos, a.sexo,
					IFNULL(i.nombre, IF(a.sexo="F","default_female.png","default_male.png")) as foto_nombre
				FROM users u
				INNER JOIN alumnos a ON a.user_id = u.id AND a.deleted_at IS NULL
				LEFT JOIN images i ON i.id = a.foto_id
				WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1', [$votante_id]);

		if ($ficha) {
			$ficha->grupo_id = $censado->grupo_id;
		}

		return $ficha;
	}


	/**
	 * Dónde votó: en esta mesa, en otra, o por su cuenta.
	 *
	 * Las tres banderas salen de agregados sobre **todos** sus votos de la
	 * elección, así que pueden ser ciertas a la vez —alguien que empezó en su casa
	 * y terminó en la mesa—. El orden de la respuesta es el que la pantalla
	 * necesita: lo que pasó aquí manda, porque es lo que el de la mesa está
	 * mirando.
	 */
	private function dondeVoto($persona): ?string
	{
		if ((int) $persona->cargos_votados === 0) {
			return null;
		}

		if ($persona->en_esta_mesa) {
			return 'esta_mesa';
		}

		if ($persona->en_otra_mesa) {
			return 'otra_mesa';
		}

		return 'propio';
	}


	/**
	 * Cuelga a cada mesa su equipo y sus grupos con **dos consultas en total**.
	 *
	 * No dos por mesa: veinte mesas serían cuarenta consultas para pintar una
	 * pantalla de configuración, que es el bucle de la 11 §6.2.
	 *
	 * @param  array<int, object>  $mesas
	 * @return array<int, object>
	 */
	private function conEquipoYGrupos($mesas): array
	{
		/*
		 * Todo se aplana a `stdClass` antes de tocarlo, venga de `DB::select` o de
		 * un `VtMesa` recién guardado.
		 *
		 * **Y no es cosmético.** En el modelo, `usuarios` y `grupos` son
		 * relaciones: colgarle un array con ese nombre lo mete en `$attributes`, y
		 * a partir de ahí un `->usuarios[] = …` es una «indirect modification of
		 * overloaded property» que PHP **descarta en silencio** —medido el 22 sep
		 * 2026: la mesa recién creada volvía con `usuarios: []` y su fila en
		 * `vt_mesa_usuarios` bien puesta, o sea lo guardado bien y la respuesta
		 * mintiendo—. Y si alguien llamara a `save()` después, Eloquent intentaría
		 * escribir una columna `usuarios` que no existe.
		 */
		$mesas = array_values(array_map(
			fn ($mesa) => $mesa instanceof VtMesa ? (object) $mesa->toArray() : $mesa,
			$mesas
		));

		if (count($mesas) === 0) {
			return [];
		}

		$ids       = array_map(fn ($mesa) => $mesa->id, $mesas);
		$comodines = implode(', ', array_fill(0, count($ids), '?'));

		$usuarios = DB::select('SELECT mu.mesa_id, u.id as user_id, u.username,
					TRIM(CONCAT(IFNULL(p.nombres, ""), " ", IFNULL(p.apellidos, ""))) as nombre
				FROM vt_mesa_usuarios mu
				INNER JOIN users u ON u.id = mu.user_id
				LEFT JOIN profesores p ON p.user_id = u.id AND p.deleted_at IS NULL
				WHERE mu.mesa_id IN ('.$comodines.')', $ids);

		$grupos = DB::select('SELECT mg.mesa_id, g.id, g.nombre, g.abrev, g.orden
				FROM vt_mesa_grupos mg
				INNER JOIN grupos g ON g.id = mg.grupo_id
				WHERE mg.mesa_id IN ('.$comodines.')
				ORDER BY g.orden, g.nombre', $ids);

		/*
		 * Se agrupa en arrays sueltos y se asigna **de una vez al final**, y no
		 * `$mesa->usuarios[] = $fila` dentro del bucle.
		 *
		 * Porque una mesa puede ser un `VtMesa`, y en un modelo de Eloquent
		 * `usuarios` y `grupos` son **relaciones**: escribirles encima los mete en
		 * `$attributes`, y a partir de ahí `$mesa->usuarios[] = …` es una
		 * «indirect modification of overloaded property», que PHP avisa por el log
		 * y **descarta en silencio**. Medido el 22 sep 2026 en el docker: la mesa
		 * recién creada volvía con `usuarios: []` y su fila en
		 * `vt_mesa_usuarios` bien puesta, o sea el peor modo de fallo posible —lo
		 * guardado está bien y la respuesta miente—.
		 */
		$porMesaUsuarios = [];
		$porMesaGrupos   = [];

		foreach ($usuarios as $fila) {
			$porMesaUsuarios[$fila->mesa_id][] = $fila;
		}

		foreach ($grupos as $fila) {
			$porMesaGrupos[$fila->mesa_id][] = $fila;
		}

		foreach ($mesas as $mesa) {
			$mesa->usuarios = $porMesaUsuarios[$mesa->id] ?? [];
			$mesa->grupos   = $porMesaGrupos[$mesa->id] ?? [];
		}

		return $mesas;
	}


	/**
	 * Deja el equipo de la mesa exactamente como dice la lista.
	 *
	 * `sync()` y no borrar-y-poner a mano: es lo mismo, y de paso las dos tablas
	 * puente se sellan con **el mismo reloj que `vt_mesas`** —el de Eloquent—, en
	 * vez de quedar una columna con dos horas dentro, que es el fallo que
	 * documenta `App\Support\SellaConElReloj`.
	 *
	 * Las cuentas que no existen se descartan en silencio: la clave ajena las
	 * rechazaría con un 500 y lo que hay detrás es una pantalla con una lista
	 * vieja abierta, no un ataque.
	 *
	 * @param  mixed  $usuarios
	 */
	private function ponerEquipo(VtMesa $mesa, $usuarios): void
	{
		$ids = $this->idsUnicos($usuarios);

		$vivos = count($ids) === 0 ? [] : DB::select('SELECT id FROM users
			WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).') AND deleted_at IS NULL', $ids);

		$mesa->usuarios()->sync(array_map(fn ($fila) => $fila->id, $vivos));
	}


	/**
	 * Deja los grupos de la mesa exactamente como dice la lista.
	 *
	 * **Y sólo grupos del año de la votación**: una mesa con un grupo de 2024 en
	 * una elección de 2026 es una lista que no se puede pintar, y el censo nunca
	 * devolvería a nadie de ahí. Se descartan en silencio, como los usuarios que
	 * no existen.
	 *
	 * @param  mixed  $grupos
	 */
	private function ponerGrupos(VtMesa $mesa, $votacion_id, $grupos): void
	{
		$votacion = DB::selectOne('SELECT year_id FROM vt_votaciones WHERE id = ?', [$votacion_id]);

		$ids = $this->idsUnicos($grupos);

		if (! $votacion || ! $votacion->year_id || count($ids) === 0) {
			$mesa->grupos()->sync([]);

			return;
		}

		$vivos = DB::select('SELECT id FROM grupos
			WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')
			  AND year_id = ? AND deleted_at IS NULL', array_merge($ids, [$votacion->year_id]));

		$mesa->grupos()->sync(array_map(fn ($fila) => $fila->id, $vivos));
	}


	/**
	 * Los ids de una lista que llega del cuerpo, sin repetidos y sin basura.
	 *
	 * @param  mixed  $lista
	 * @return array<int, int>
	 */
	private function idsUnicos($lista): array
	{
		if (! is_array($lista)) {
			return [];
		}

		$ids = [];

		foreach ($lista as $valor) {
			// La pantalla puede mandar `[3, 7]` o `[{id: 3}, {id: 7}]`; las dos
			// formas valen y así el front no tiene que aplanar antes de guardar.
			if (is_array($valor)) {
				$valor = $valor['id'] ?? $valor['user_id'] ?? null;
			}

			if (is_numeric($valor) && (int) $valor > 0) {
				$ids[(int) $valor] = (int) $valor;
			}
		}

		return array_values($ids);
	}

}
