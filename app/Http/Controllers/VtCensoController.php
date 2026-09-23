<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;


use App\Support\Reloj;
use App\User;
use App\Models\VtGrupoVotacion;
use App\Models\VtVotacion;


/**
 * **Quién vota**, y ya no es una lista de inscritos.
 *
 * > Vota quien esté en un grupo vivo del año de la votación.
 *
 * Sustituye entero a `VtParticipantesController`, que se ha borrado con la tabla
 * `vt_participantes` que leía. El cambio de signo es lo primero que hay que
 * entender, porque cambia cómo se lee todo lo de abajo:
 *
 *   - **Antes**, una elección no arrancaba hasta que alguien inscribía los veinte
 *     grupos uno a uno, y el grupo que se olvidaba no votaba **sin que nadie se
 *     enterara**: el sistema no distinguía «no inscrito» de «inscrito y sin
 *     votar».
 *   - **Ahora**, sin filas en `vt_grupos_votacion` participan todos los grupos del
 *     año, y las filas son excepciones. El olvido tiene el signo correcto: quien
 *     no se toca, vota.
 *
 * La regla del censo no vive aquí: vive en `VtVotacion::censo()` y en
 * `VtVotacion::recuentoDelCensoPorGrupo()`, para que «quién vota» tenga una sola
 * respuesta. Lo que este controlador hace es **repartirla en las cuatro cosas que
 * pide la pantalla de configuración**.
 *
 * ## Lo que NO devuelve, y es una decisión
 *
 * **Por quién votó nadie.** `participantes/votantes` devolvía, por cada alumno y
 * cada cargo, las filas de `vt_votos` con su `candidato_id` dentro: el voto
 * nominal junto al nombre y al documento de la persona, a los 51 docentes con
 * `auth.personal` ([05 §18](../../docs/migracion/05-codigo-muerto-y-roto.md),
 * [11 §6](../../docs/migracion/11-votaciones.md)). Joseth lo cerró el 21 ago 2026:
 * *«Las votaciones son secretas.»*
 *
 * Saber **si** alguien ya votó —y de qué cargos le faltan— es legítimo y hace
 * falta el día de la elección, así que eso sí sale. A quién votó, no sale de la
 * base.
 *
 * Y tampoco sale el expediente: la vieja mandaba 37 KB por grupo con el
 * documento, el celular, la dirección, la fecha de nacimiento y el correo de cada
 * alumno. Para pasar lista eso no hace falta.
 *
 * ## Y cuesta un número fijo de consultas
 *
 * La vieja hacía `P × (1 + A)` —una consulta de cargos por cada participante y
 * una de votos por cada cargo de cada uno; medidas 37 donde iba una
 * ([11 §6.2](../../docs/migracion/11-votaciones.md))—. Aquí no hay ninguna
 * consulta dentro de un bucle.
 */
class VtCensoController extends Controller {

	/**
	 * Los cuatro estamentos, **contados por `users.tipo`**.
	 *
	 * No es la nómina del año ni la lista de contratos, y eso es a propósito: lo que
	 * la pantalla tiene que enseñar es **a cuánta gente alcanza cada interruptor**, y
	 * quien decide eso al votar es `VtVotacion::admiteA()`, que mira `users.tipo` y
	 * nada más. Contar aquí los docentes por `contratos` daría un número más bonito y
	 * **distinto del que va a votar**: un docente sin contrato de este año, con la
	 * cuenta activa, vota igual.
	 *
	 * Los estudiantes no están aquí porque los suyos sí son un censo —el de
	 * `VtVotacion::censo()`— y se cuentan aparte.
	 */
	private const TIPO_DEL_ESTAMENTO = [
		VtVotacion::ESTAMENTO_DOCENTE        => 'Profesor',
		VtVotacion::ESTAMENTO_ADMINISTRATIVO => 'Usuario',
		VtVotacion::ESTAMENTO_ACUDIENTE      => 'Acudiente',
	];


	/**
	 * Todo lo que necesita la pantalla de configuración de una elección.
	 *
	 *     GET  censo/{votacion}
	 *
	 * Tres cosas y tres consultas:
	 *
	 *   - **los estamentos** con su interruptor y su recuento real;
	 *   - **los grupos del año** con `participa`, `modo` y cuántos estudiantes tiene
	 *     cada uno —también los apagados: si apagar un grupo lo dejara en «0
	 *     estudiantes», volver a encenderlo parecería no hacer nada—;
	 *   - **el desglose por estado de matrícula** del censo, que es lo que alimenta
	 *     el aviso de *«12 asisten sin matrícula formal y sí votan»*. La regla es una
	 *     lista negra —no votan `RETI` ni `DESE`— así que un estado que el colegio
	 *     estrene el año que viene entra al censo solo, y esta cifra es la que
	 *     permite que alguien lo vea en vez de que pase inadvertido.
	 */
	public function getIndex($votacion_id)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($votacion_id, $user);

		$grupos = $this->gruposConSuConfiguracion($votacion);

		$censo       = ['total' => 0, 'por_estado' => []];

		foreach ($grupos as $grupo) {

			if (! $grupo['participa']) {
				continue;
			}

			$censo['total'] += $grupo['estudiantes'];

			foreach ($grupo['por_estado'] as $estado => $cantidad) {
				$censo['por_estado'][$estado] = ($censo['por_estado'][$estado] ?? 0) + $cantidad;
			}
		}

		// Ordenado de más a menos para que el aviso de la pantalla nombre primero lo
		// que más pesa, y con la clave dentro: un mapa `{estado: cantidad}` en JSON no
		// tiene orden garantizado.
		arsort($censo['por_estado']);

		$desglose = [];

		foreach ($censo['por_estado'] as $estado => $cantidad) {
			$desglose[] = ['estado' => $estado, 'cantidad' => $cantidad];
		}

		return [
			'votacion'   => $votacion,
			'estamentos' => $this->estamentos($votacion, $censo['total']),
			'grupos'     => $grupos,
			'censo'      => [
				'estudiantes' => $censo['total'],
				'por_estado'  => $desglose,
			],
		];
	}


	/**
	 * Guarda las excepciones de grupo.
	 *
	 *     PUT  censo/{votacion}/grupos     { grupos: [ {grupo_id, participa, modo}, … ] }
	 *
	 * **Una fila que dice lo que dice el defecto se borra.** `participa = 1` con
	 * `modo = 'solo'` significa exactamente lo mismo que no tener fila, y guardarla
	 * dejaría la tabla creciendo con las veinte filas de cada elección de cada año
	 * para no decir nada. Lo que queda escrito son las excepciones, que es lo que
	 * esta tabla es.
	 *
	 * Va en una transacción porque la pantalla manda los veinte grupos de una vez:
	 * si el decimoquinto falla, los catorce primeros no se quedan guardados con el
	 * resto sin guardar y nadie mirando.
	 */
	public function putGrupos($votacion_id)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($votacion_id, $user);

		$pedidos = $this->gruposValidados($votacion);

		$ahora = Reloj::ahoraTexto();

		DB::transaction(function () use ($pedidos, $votacion, $ahora) {

			foreach ($pedidos as $grupo) {

				$esElDefecto = $grupo['participa'] === 1 && $grupo['modo'] === VtGrupoVotacion::MODO_SOLO;

				if ($esElDefecto) {
					DB::delete('DELETE FROM vt_grupos_votacion WHERE votacion_id = ? AND grupo_id = ?',
						[$votacion->id, $grupo['grupo_id']]);

					continue;
				}

				// `INSERT ... ON DUPLICATE KEY UPDATE` sobre `vt_grupos_votacion_unico`,
				// y no comprueba-y-luego-inserta: el índice es lo que garantiza una
				// respuesta por grupo y elección, y dos pestañas guardando a la vez no
				// pueden dejar dos filas que se contradigan.
				DB::insert('INSERT INTO vt_grupos_votacion (votacion_id, grupo_id, participa, modo, created_at, updated_at)
						VALUES (?, ?, ?, ?, ?, ?)
						ON DUPLICATE KEY UPDATE participa=VALUES(participa), modo=VALUES(modo), updated_at=VALUES(updated_at)',
					[$votacion->id, $grupo['grupo_id'], $grupo['participa'], $grupo['modo'], $ahora, $ahora]);
			}
		});

		// Se devuelve la lista entera recalculada, con los recuentos puestos: la
		// pantalla acaba de cambiar quién vota y la cifra del censo cambia con ella.
		return ['grupos' => $this->gruposConSuConfiguracion($votacion)];
	}


	/**
	 * Las personas de un grupo, con si ya votaron y de qué cargos.
	 *
	 *     GET  censo/{votacion}/votantes?grupo_id=12
	 *
	 * Es lo que hacía `participantes/votantes` **sin el voto nominal** y sin el
	 * expediente de cada alumno: ver la cabecera.
	 *
	 * ## Y el grupo tiene que ser de esta elección
	 *
	 * La vieja recibía `grupo_id` y `votacion_id` por el cuerpo y los usaba por
	 * separado —uno elegía a la gente y el otro los cargos—, así que se podía pedir
	 * el censo de un grupo cualquiera contra una elección cualquiera y salía una
	 * tabla **con sentido aparente**: gente de verdad, cargos de verdad y ninguna
	 * relación entre las dos cosas ([11 §6.1](../../docs/migracion/11-votaciones.md)).
	 * Aquí el grupo se comprueba contra el año **de la votación**.
	 *
	 * ## Un grupo apagado devuelve la lista vacía, y lo dice
	 *
	 * `participa = 0` significa que ahí no vota nadie, así que el censo de ese grupo
	 * está vacío de verdad. Va acompañado de `participa` en la respuesta para que la
	 * pantalla pueda escribir *«este grupo no participa»* en vez de *«no hay
	 * alumnos»*, que es otra cosa.
	 */
	public function getVotantes($votacion_id)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($votacion_id, $user);

		$grupo = $this->grupoDeLaVotacion($votacion, Request::input('grupo_id'));

		$votantes = [];

		foreach (VtVotacion::censo($votacion) as $fila) {
			if ((int) $fila->grupo_id === (int) $grupo->id) {
				$votantes[(int) $fila->user_id] = [
					'user_id'   => (int) $fila->user_id,
					'alumno_id' => (int) $fila->alumno_id,
					'nombres'   => $fila->nombres,
					'apellidos' => $fila->apellidos,
					'estado'    => $fila->estado,
					'grupo_id'  => (int) $fila->grupo_id,
				];
			}
		}

		/*
		 * Los votos de todos ellos **en una consulta**, no una por persona y cargo.
		 * Y lo que se trae es `aspiracion_id`, nunca `candidato_id`: la diferencia
		 * entre pasar lista y abrir la urna.
		 */
		$cargos = [];

		if (count($votantes) > 0) {

			$ids       = array_keys($votantes);
			$comodines = implode(', ', array_fill(0, count($ids), '?'));

			$votos = DB::select('SELECT vv.user_id, vv.aspiracion_id, a.aspiracion, a.abrev
					FROM vt_votos vv
					INNER JOIN vt_aspiraciones a ON a.id = vv.aspiracion_id AND a.deleted_at IS NULL
					WHERE vv.votacion_id = ? AND vv.user_id IN ('.$comodines.')',
				array_merge([$votacion->id], $ids));

			foreach ($votos as $voto) {
				$cargos[(int) $voto->user_id][] = [
					'aspiracion_id' => (int) $voto->aspiracion_id,
					'aspiracion'    => $voto->aspiracion,
					'abrev'         => $voto->abrev,
				];
			}
		}

		$lista = [];

		foreach ($votantes as $quien => $votante) {
			$votante['ya_voto'] = isset($cargos[$quien]);
			$votante['cargos']  = $cargos[$quien] ?? [];

			$lista[] = $votante;
		}

		return [
			'grupo'     => [
				'id'        => (int) $grupo->id,
				'nombre'    => $grupo->nombre,
				'abrev'     => $grupo->abrev,
				'participa' => $this->participaDelGrupo($votacion, (int) $grupo->id),
			],
			'votantes'  => $lista,
		];
	}


	/**
	 * Quién puede ser candidato.
	 *
	 *     GET  censo/{votacion}/elegibles
	 *
	 * Es lo que hacía `participantes/allinscritos`, con dos arreglos:
	 *
	 *   - **el año es el de la votación**, no el del usuario. El de antes leía
	 *     `$user->year_id`, que el login mueve: un docente que se hubiera pasado a
	 *     2025 para mirar un boletín veía candidatos de 2025 en la elección de 2026.
	 *   - **los estudiantes salen del censo**, o sea de la misma regla que decide
	 *     quién vota. El de antes filtraba `m.estado="MATR" or m.estado="ASIS"`, con
	 *     `PREM` y `PREA` olvidados: un alumno preinscrito podía votar y no podía
	 *     presentarse, y nada lo decía.
	 *
	 * Se conserva la forma que pinta la pantalla de candidatos —`nombres`,
	 * `apellidos`, `username` y `grupo`, que es por donde agrupa el `ui-select`—.
	 */
	public function getElegibles($votacion_id)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($votacion_id, $user);

		$elegibles = [];

		$censo = VtVotacion::censo($votacion);

		if (count($censo) > 0) {

			// Los nombres de usuario en una sola consulta. `censo()` no los trae
			// —no hacen falta para votar— y aquí sí se pintan.
			$ids       = array_map(static fn ($fila) => (int) $fila->user_id, $censo);
			$comodines = implode(', ', array_fill(0, count($ids), '?'));

			$usuarios = DB::select('SELECT id, username FROM users WHERE id IN ('.$comodines.')', $ids);

			$nombre_de_usuario = [];

			foreach ($usuarios as $usuario) {
				$nombre_de_usuario[(int) $usuario->id] = $usuario->username;
			}

			foreach ($censo as $fila) {
				$elegibles[] = [
					'persona_id' => (int) $fila->alumno_id,
					'nombres'    => $fila->nombres,
					'apellidos'  => $fila->apellidos,
					'user_id'    => (int) $fila->user_id,
					'username'   => $nombre_de_usuario[(int) $fila->user_id] ?? null,
					'tipo'       => 'Al',
					'grupo'      => $fila->grupo_nombre,

					// El estado de matrícula, que `censo()` ya trae. Va aquí porque la
					// pantalla de candidatos marca en ámbar al que *asiste sin matrícula
					// formal* junto a CADA resultado de la búsqueda, y sin este campo sólo
					// podía hacerlo del candidato ya elegido pidiendo `votantes` grupo por
					// grupo. Es el mismo dato que explica por qué esta persona está en el
					// censo.
					'estado'     => $fila->estado,
				];
			}
		}

		$docentes = DB::select('SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username
				FROM profesores p
				INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL
				INNER JOIN contratos c ON c.profesor_id = p.id AND c.year_id = ? AND c.deleted_at IS NULL
				WHERE p.deleted_at IS NULL
				ORDER BY p.apellidos, p.nombres',
			[$votacion->year_id]);

		foreach ($docentes as $docente) {
			$elegibles[] = [
				'persona_id' => (int) $docente->persona_id,
				'nombres'    => $docente->nombres,
				'apellidos'  => $docente->apellidos,
				'user_id'    => (int) $docente->user_id,
				'username'   => $docente->username,
				'tipo'       => 'Pr',
				'grupo'      => 'Profesores',

				// Un docente no tiene matrícula, así que aquí no hay estado que dar. Se
				// manda la clave con `null` en vez de omitirla: la forma de la fila es la
				// misma para los dos y quien la pinte no tiene que saber de qué rama vino.
				'estado'     => null,
			];
		}

		return $elegibles;
	}


	/**
	 * **A quién se le puede dar una mesa.**
	 *
	 *     GET  censo/{votacion}/conductores
	 *
	 * Los docentes con contrato del año de la elección **y el personal con cuenta que
	 * no es docente**. Es una lista distinta de {@see self::getElegibles()} y no una
	 * variante suya, porque contesta otra pregunta: `elegibles` es *quién puede ser
	 * candidato* —y ahí entran los estudiantes del censo, que son la mayoría de la
	 * lista— y esto es *quién puede estar delante de la mesa*, donde un estudiante no
	 * pinta nada.
	 *
	 * ## Por qué hacía falta
	 *
	 * La pantalla de mesas venía llenando el campo «quién la conduce» con `elegibles`
	 * filtrado a `tipo: 'Pr'`, y eso **deja fuera a secretaría y a coordinación**: son
	 * cuentas de `users` sin ficha en `profesores`, así que ninguna consulta de
	 * `elegibles` las ve. El backend acepta su `users.id` en `vt_mesa_usuarios` sin
	 * problema —`ponerEquipo()` no pregunta el tipo—, o sea que la pantalla no podía
	 * ofrecer lo que el modelo sí permitía, y *la mesa de la oficina* es uno de los
	 * casos que nombra el encargo: en algunos colegios el voto asistido lo lleva una
	 * secretaria o una coordinadora, no un docente.
	 *
	 * ## Cómo se pregunta cada lado, y por qué no es la misma pregunta
	 *
	 * **Docente es tener ficha en `profesores` y contrato del año**, exactamente como
	 * en `getElegibles()`. **Administrativo es `users.tipo = 'Usuario'`**, por
	 * {@see self::TIPO_DEL_ESTAMENTO}, que es el mismo mapa con el que se cuentan los
	 * estamentos de la pantalla de configuración. Los rótulos son las constantes
	 * `VtVotacion::ESTAMENTO_*`: no hay una tercera definición de estamento aquí.
	 *
	 * Y el lado de los docentes **no** filtra además por `users.tipo = 'Profesor'`, que
	 * era lo primero que se escribió. Es una condición que sólo puede quitar gente: el
	 * docente cuya cuenta tuviera el tipo en nulo o mal puesto **desaparecería de la
	 * lista sin dar error**, que es el modo de fallo que este dominio lleva entero
	 * persiguiendo. Medido el 23 sep 2026 en `micolev1_la_hermosa`: las 14 fichas de
	 * `profesores` con cuenta tienen `tipo = 'Profesor'`, así que hoy la condición no
	 * cambiaba nada — y el día que cambiara algo, lo que haría es esconder a alguien.
	 *
	 * Lo que sí hace falta es **no repetir a nadie**: una cuenta `'Usuario'` que además
	 * tuviera ficha de docente saldría por las dos consultas, y el selector del front
	 * usa el `user_id` como clave del bucle. Se descarta en PHP con los ids ya
	 * recogidos, sin una segunda consulta. Hoy no hay ninguna (las dos cuentas
	 * `'Usuario'` del docker no tienen ficha), o sea que es un cinturón.
	 *
	 * Y `administrativo` aquí es **el personal con cuenta que no es docente**, lo
	 * mismo que enciende `votan_administrativos`. **No es `Autoriza::esAdministrativo()`**,
	 * que es un permiso y alcanza también a rectoría y a los docentes con rol: son
	 * otras personas. El porqué de que sea un solo estamento y no tres está en
	 * `VtVotacion` —la base no separa cafetería, aseo y mantenimiento de secretaría—.
	 *
	 * ## Dos detalles más que son decisión
	 *
	 *   - **`is_active = 1` en los dos lados.** Conducir una mesa empieza por entrar a
	 *     la aplicación, y el login rechaza la cuenta inactiva
	 *     (`Auth\SesionController`). Ofrecer a alguien que no puede entrar es ofrecer
	 *     una mesa que el día de la elección no abre. En este docker pesa: de las 14
	 *     fichas de docente con cuenta, **10 están inactivas**.
	 *   - **No se devuelve `persona_id`.** Lo que se guarda en `vt_mesa_usuarios` es
	 *     `users.id`, y el administrativo **no tiene ficha**: un `persona_id` que en
	 *     una rama fuera `profesores.id` y en la otra `users.id` es la clase de campo
	 *     que se usa por error. El único id de esta lista es `user_id`.
	 */
	public function getConductores($votacion_id)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($votacion_id, $user);

		$conductores = [];

		/*
		 * La foto sale como en `ProfesoresController`: el nombre del fichero de
		 * `images`, y cuando no hay foto **el respaldo por sexo resuelto en SQL**. Así
		 * el campo nunca llega vacío por no tener foto, que es lo que el selector de
		 * personas del front da por hecho.
		 */
		$docentes = DB::select('SELECT p.nombres, p.apellidos, u.id as user_id, u.username,
					IFNULL(i.nombre, IF(p.sexo = "F", "default_female.png", "default_male.png")) as foto
				FROM profesores p
				INNER JOIN users u ON u.id = p.user_id AND u.deleted_at IS NULL AND u.is_active = 1
				INNER JOIN contratos c ON c.profesor_id = p.id AND c.year_id = ? AND c.deleted_at IS NULL
				LEFT JOIN images i ON i.id = p.foto_id AND i.deleted_at IS NULL
				WHERE p.deleted_at IS NULL
				ORDER BY p.apellidos, p.nombres',
			[$votacion->year_id]);

		/** Para no repetir a quien tuviera ficha de docente y cuenta administrativa. */
		$ya_esta = [];

		foreach ($docentes as $docente) {
			$ya_esta[(int) $docente->user_id] = true;

			$conductores[] = [
				'user_id'   => (int) $docente->user_id,
				'nombres'   => $docente->nombres,
				'apellidos' => $docente->apellidos,
				'username'  => $docente->username,
				'foto'      => $docente->foto,
				'estamento' => VtVotacion::ESTAMENTO_DOCENTE,
			];
		}

		/*
		 * El personal sin ficha: `users` y nada más. `nombres` y `apellidos` salen
		 * VACÍOS a propósito y no inventados —esas columnas no existen para una cuenta
		 * administrativa, que se identifica por su `username`—, igual que hace
		 * `UsersController` con la misma gente. La foto es `users.imagen_id`, no
		 * `profesores.foto_id`, con el mismo respaldo por sexo.
		 */
		$administrativos = DB::select('SELECT u.id as user_id, u.username,
					IFNULL(i.nombre, IF(u.sexo = "F", "default_female.png", "default_male.png")) as foto
				FROM users u
				LEFT JOIN images i ON i.id = u.imagen_id AND i.deleted_at IS NULL
				WHERE u.deleted_at IS NULL AND u.is_active = 1 AND u.tipo = ?
				ORDER BY u.username',
			[self::TIPO_DEL_ESTAMENTO[VtVotacion::ESTAMENTO_ADMINISTRATIVO]]);

		foreach ($administrativos as $administrativo) {

			if (isset($ya_esta[(int) $administrativo->user_id])) {
				continue;
			}

			$conductores[] = [
				'user_id'   => (int) $administrativo->user_id,
				'nombres'   => '',
				'apellidos' => '',
				'username'  => $administrativo->username,
				'foto'      => $administrativo->foto,
				'estamento' => VtVotacion::ESTAMENTO_ADMINISTRATIVO,
			];
		}

		return $conductores;
	}


	/*
	 * ─────────────────────────────────────────────────────────────────────────
	 *  LO DE DENTRO
	 * ─────────────────────────────────────────────────────────────────────────
	 */

	/**
	 * Los grupos del año de la votación, con su excepción puesta y su recuento.
	 *
	 * Dos consultas y ningún bucle con SQL dentro: los grupos con su fila de
	 * `vt_grupos_votacion` —`LEFT JOIN`, porque **lo normal es no tenerla**— y el
	 * recuento del censo por grupo y estado.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function gruposConSuConfiguracion($votacion)
	{
		$grupos = DB::select('SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id,
					vg.participa, vg.modo
				FROM grupos g
				LEFT JOIN vt_grupos_votacion vg ON vg.grupo_id = g.id AND vg.votacion_id = ?
				WHERE g.year_id = ? AND g.deleted_at IS NULL
				ORDER BY g.orden, g.nombre',
			[$votacion->id, $votacion->year_id]);

		$recuento = [];

		foreach (VtVotacion::recuentoDelCensoPorGrupo($votacion) as $fila) {
			$recuento[(int) $fila->grupo_id][$fila->estado] = (int) $fila->cantidad;
		}

		$pintados = [];

		foreach ($grupos as $grupo) {

			$por_estado = $recuento[(int) $grupo->id] ?? [];

			$pintados[] = [
				'id'          => (int) $grupo->id,
				'nombre'      => $grupo->nombre,
				'abrev'       => $grupo->abrev,
				'orden'       => $grupo->orden,
				'grado_id'    => $grupo->grado_id,

				// Sin fila, participa y vota cada uno desde donde esté. Ver la cabecera.
				'participa'   => $grupo->participa === null ? 1 : (int) $grupo->participa,
				'modo'        => $grupo->modo === null ? VtGrupoVotacion::MODO_SOLO : $grupo->modo,

				'estudiantes' => array_sum($por_estado),
				'por_estado'  => $por_estado,
			];
		}

		return $pintados;
	}

	/**
	 * Los cuatro estamentos con su interruptor y a cuánta gente alcanza.
	 *
	 * Los tres que no son estudiantes salen de **una** consulta agrupada por
	 * `users.tipo`; ver {@see self::TIPO_DEL_ESTAMENTO} para por qué se cuentan así y
	 * no por contratos.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function estamentos($votacion, int $estudiantes)
	{
		$filas = DB::select("SELECT u.tipo, COUNT(*) as cantidad
				FROM users u
				WHERE u.deleted_at IS NULL AND u.is_active = 1 AND u.tipo IN ('Profesor', 'Usuario', 'Acudiente')
				GROUP BY u.tipo");

		$cuantos = [];

		foreach ($filas as $fila) {
			$cuantos[$fila->tipo] = (int) $fila->cantidad;
		}

		$estamentos = [];

		foreach (VtVotacion::FLAG_DEL_ESTAMENTO as $estamento => $flag) {

			$estamentos[] = [
				'estamento' => $estamento,
				'flag'      => $flag,
				'vota'      => (int) $votacion->$flag,
				'cuantos'   => $estamento === VtVotacion::ESTAMENTO_ESTUDIANTE
					? $estudiantes
					: ($cuantos[self::TIPO_DEL_ESTAMENTO[$estamento]] ?? 0),
			];
		}

		return $estamentos;
	}

	/**
	 * El grupo que viene por la URL, comprobado contra el año **de la votación**.
	 *
	 * @return object
	 */
	private function grupoDeLaVotacion($votacion, $grupo_id)
	{
		$id = filter_var($grupo_id, FILTER_VALIDATE_INT);

		if ($id === false || $id < 1) {
			abort(422, 'Falta el grupo (`grupo_id`).');
		}

		$grupo = DB::selectOne('SELECT g.id, g.nombre, g.abrev FROM grupos g
				WHERE g.id = ? AND g.year_id = ? AND g.deleted_at IS NULL',
			[$id, $votacion->year_id]);

		if (! $grupo) {
			abort(404, 'Ese grupo no es del año de esta elección.');
		}

		return $grupo;
	}

	/** Si ese grupo participa en esta elección. Sin fila, sí. */
	private function participaDelGrupo($votacion, int $grupo_id)
	{
		$fila = DB::selectOne('SELECT participa FROM vt_grupos_votacion WHERE votacion_id = ? AND grupo_id = ?',
			[$votacion->id, $grupo_id]);

		return $fila === null ? 1 : (int) $fila->participa;
	}

	/**
	 * La lista que llega a `putGrupos`, comprobada entera **antes** de escribir nada.
	 *
	 * Los `grupo_id` se cotejan contra los grupos del año de la votación de una vez:
	 * uno por uno serían veinte consultas, y sin cotejarlos se pueden meter
	 * excepciones sobre grupos de otro año que luego no pinta ninguna pantalla —una
	 * elección con un grupo apagado que nadie ve— porque la clave ajena de
	 * `vt_grupos_votacion` apunta a `grupos` y no al año.
	 *
	 * @return array<int, array{grupo_id: int, participa: int, modo: string}>
	 */
	private function gruposValidados($votacion)
	{
		$grupos = Request::input('grupos');

		if (! is_array($grupos)) {
			abort(422, 'Los grupos tienen que venir como una lista.');
		}

		$delAnio = [];

		foreach (DB::select('SELECT id FROM grupos WHERE year_id = ? AND deleted_at IS NULL', [$votacion->year_id]) as $fila) {
			$delAnio[(int) $fila->id] = true;
		}

		$validados = [];
		$vistos    = [];

		foreach ($grupos as $grupo) {

			if (! is_array($grupo)) {
				abort(422, 'Cada grupo tiene que venir como un objeto con `grupo_id`, `participa` y `modo`.');
			}

			$grupo_id = filter_var($grupo['grupo_id'] ?? null, FILTER_VALIDATE_INT);

			if ($grupo_id === false || $grupo_id < 1) {
				abort(422, 'Hay un grupo sin `grupo_id`.');
			}

			if (! isset($delAnio[$grupo_id])) {
				abort(422, 'El grupo '.$grupo_id.' no es del año de esta elección.');
			}

			if (isset($vistos[$grupo_id])) {
				abort(422, 'El grupo '.$grupo_id.' viene dos veces.');
			}

			$vistos[$grupo_id] = true;

			$modo = $grupo['modo'] ?? VtGrupoVotacion::MODO_SOLO;

			if (! is_string($modo) || ! in_array($modo, VtGrupoVotacion::MODOS, true)) {
				abort(422, 'El modo del grupo '.$grupo_id.' tiene que ser `'.implode('` o `', VtGrupoVotacion::MODOS).'`.');
			}

			$validados[] = [
				'grupo_id'  => $grupo_id,
				'participa' => $this->comoBooleano($grupo['participa'] ?? null, 'participa'),
				'modo'      => $modo,
			];
		}

		return $validados;
	}

	/**
	 * `true`, `1`, `"1"`, `"true"` y sus contrarios. Cualquier otra cosa es un 422.
	 *
	 * Mismo criterio que `VtVotacionesController`: `(bool) "0"` es `false` pero
	 * `(bool) "false"` es **`true`**, así que un cliente que mande el interruptor
	 * como texto apagaría lo que quería encender. Se nombran los valores.
	 */
	private function comoBooleano($valor, string $campo)
	{
		if (is_bool($valor)) {
			return $valor ? 1 : 0;
		}

		if ($valor === 1 || $valor === 0) {
			return $valor;
		}

		if (is_string($valor) || is_int($valor)) {
			$texto = strtolower(trim((string) $valor));

			if (in_array($texto, ['1', 'true', 'si', 'sí'], true)) {
				return 1;
			}

			if (in_array($texto, ['0', 'false', 'no'], true)) {
				return 0;
			}
		}

		abort(422, 'El valor de `'.$campo.'` no es un sí o un no.');
	}

}
