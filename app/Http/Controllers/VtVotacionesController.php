<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;


use App\Support\Autoriza;
use App\Support\Reloj;
use App\User;
use App\Models\VtAspiracion;
use App\Models\VtVotacion;
use App\Models\VtCandidato;
use App\Models\VtVoto;


/**
 * La elección del colegio: crearla, configurarla y abrirla.
 *
 * Lo que este módulo hacía hasta el 22 sep 2026 está en
 * `docs/migracion/11-votaciones.md`, y esta clase es el lado de administración de
 * lo que allí se documentó. Lo que ha cambiado aquí, en orden de lo que más
 * dolía:
 *
 * ## 1. Los interruptores ya no mueven la elección de otro
 *
 * Los seis `set-*` recibían el `id` **por el cuerpo** y hacían el `UPDATE` sin
 * mirar dueño ni año: cualquiera de los 51 docentes con `auth.personal` cerraba,
 * abría, destapaba los resultados o ponía como actual la elección de cualquier
 * otro (11 §5). Ahora los once pasan por `VtVotacion::exigirAdministrable()`
 * —404 si no existe, 403 si no es suya— y el dueño es **el que la creó**, que es
 * la respuesta de Joseth del 21 ago 2026 (11 §5.4).
 *
 * ## 2. `UPDATE vt_votaciones SET actual=0` sin WHERE
 *
 * Eso es lo que hacía `store` con `actual=1`: **apagaba la elección actual de
 * todos los años y de todos los docentes del colegio**, no sólo la suya. Ahora el
 * apagado va acotado a `(user_id, year_id)` **de la votación que se crea**, que es
 * el mismo par que ya usaba `set-in-action`.
 *
 * ## 3. El campo que falta ya no enciende nada
 *
 * `Request::input('locked', true)` valía `true` en cuatro interruptores y `false`
 * en dos, así que una llamada con sólo el `id` dentro hacía cosas opuestas según a
 * cuál llegara (11 §5.2). Ahora **el valor es obligatorio**: sin él es un 422. Los
 * clientes mandan siempre `{id, campo}` —`VotacionesApi.ts` y
 * `app2/src/app/datos/votaciones.ts`—, así que no hay nadie a quien se le rompa.
 *
 * ## 4. Y los códigos de estado dicen lo que pasó
 *
 * 404 si no existe, 403 si no es tuya, 422 si los datos no valen. Antes `store` y
 * `update` envolvían todo en un `try` que convertía cualquier cosa —incluido un
 * `nombre` nulo contra una columna `NOT NULL`— en el mismo 422 sin decir qué
 * faltaba, y escribían **antes** de reventar (05 §13.1).
 */
class VtVotacionesController extends Controller {

	/** `vt_votaciones.nombre` es `varchar(255)` y con el `sql_mode` de estos servidores se trunca en silencio. */
	private const LARGO_NOMBRE = 255;

	/**
	 * El tope de la cuenta atrás, **en segundos**, y es el que pidió el encargo.
	 *
	 * La columna es `unsignedSmallInteger`, o sea que aguanta 65.535: el límite no
	 * lo pone la base, lo pone que treinta segundos delante de una fila de alumnos
	 * esperando ya es mucho. Cero es válido y significa «sin cuenta atrás».
	 */
	private const CUENTA_ATRAS_MAXIMA = 30;

	/** Cuántas cifras tiene la clave de la urna de doble llave. */
	private const CIFRAS_DE_LA_CLAVE = 4;

	/*
	 * Aquí vivía `NO_ES_PERSONAL = ['Alumno', 'Acudiente']`, la lista con la que
	 * `getEnAccionInscrito()` decidía quién ve el recuento con el interruptor
	 * apagado. **Se fue el 23 sep 2026 con el criterio que la usaba**: ver el
	 * recuento antes de publicarlo ya no es de todo el personal, es de quien puede
	 * publicarlo (`VtVotacion::puedePublicarResultados()`). `VtActasController` sigue
	 * teniendo la suya, que decide otra cosa —quién firma un acta de papel— y no se
	 * tocó.
	 */


	public function getIndex()
	{
		$user = User::fromToken();

		// El superusuario ve las de todo el colegio. Esto era `$user->user_id == 1`
		// escrito a mano, que da por hecho que el administrador de los dieciséis
		// colegios es la fila número 1 —cierto hoy en los tres del docker, pero es un
		// número y no un criterio—. `Autoriza::esSuperusuario()` es el mismo que mira
		// `VtVotacion::laAdministra()`, así que quien lista y quien administra
		// coinciden.
		if (Autoriza::esSuperusuario($user)) {

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



	/**
	 * Crea la elección con sus cargos.
	 *
	 * **Todo o nada.** Va en una transacción porque la versión vieja insertaba la
	 * votación, se metía en el bucle de aspiraciones y si una fallaba dejaba una
	 * elección sin cargos —invotable y sin nada que lo dijera—. Y con `actual=1`
	 * había apagado ya la elección actual de todo el colegio antes de llegar ahí.
	 */
	public function postStore()
	{
		$user = User::fromToken();

		$nombre       = $this->nombreValidado();
		$fechas       = $this->fechasValidadas(null, null);
		$aspiraciones = $this->aspiracionesValidadas();

		$ahora = Reloj::ahoraTexto();

		$datos = [
			'user_id'               => $user->user_id,
			'nombre'                => $nombre,
			'year_id'               => $user->year_id,
			'votan_estudiantes'     => $this->booleanoOpcional('votan_estudiantes', true),
			'votan_profes'          => $this->booleanoOpcional('votan_profes', true),
			'votan_acudientes'      => $this->booleanoOpcional('votan_acudientes', false),
			'votan_administrativos' => $this->booleanoOpcional('votan_administrativos', false),
			'titulares_conducen'    => $this->booleanoOpcional('titulares_conducen', true),
			'solo_en_mesa'          => $this->booleanoOpcional('solo_en_mesa', false),
			'cuenta_atras'          => $this->cuentaAtrasValidada(5),
			'locked'                => $this->booleanoOpcional('locked', false),
			'actual'                => $this->booleanoOpcional('actual', false),
			'in_action'             => $this->booleanoOpcional('in_action', false),
			'fecha_inicio'          => $fechas['fecha_inicio'],
			'fecha_fin'             => $fechas['fecha_fin'],
			'created_by'            => $user->user_id,
			'updated_by'            => $user->user_id,
			'created_at'            => $ahora,
			'updated_at'            => $ahora,
		];

		/*
		 * `doble_llave` no se enciende al crear: encenderla **genera una clave** que
		 * se enseña una sola vez, así que tiene su propia puerta (`set-doble-llave`)
		 * y no es un campo más de un formulario de creación.
		 */

		return DB::transaction(function () use ($datos, $aspiraciones, $user, $ahora) {

			$votacion_id = DB::table('vt_votaciones')->insertGetId($datos);

			if ($datos['actual']) {
				$this->apagarLasDemas('actual', $votacion_id, $user->user_id, $datos['year_id']);
			}

			if ($datos['in_action']) {
				$this->apagarLasDemas('in_action', $votacion_id, $user->user_id, $datos['year_id']);
			}

			$guardadas = [];

			foreach ($aspiraciones as $aspiracion) {
				$fila = new VtAspiracion;
				$fila->aspiracion  = $aspiracion['aspiracion'];
				$fila->abrev       = $aspiracion['abrev'];
				$fila->votacion_id = $votacion_id;
				$fila->created_by  = $user->user_id;
				$fila->created_at  = $ahora;
				$fila->updated_at  = $ahora;
				$fila->save();

				$guardadas[] = $fila;
			}

			$datos['id']           = $votacion_id;
			$datos['aspiraciones'] = $guardadas;

			return $datos;
		});
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


	/*
	 * ─────────────────────────────────────────────────────────────────────────
	 *  LOS ONCE INTERRUPTORES
	 * ─────────────────────────────────────────────────────────────────────────
	 *
	 * Seis venían de antes y cinco son del rediseño. Todos con la misma forma —el
	 * `id` en el cuerpo, el valor en el cuerpo, `auth.personal` en la ruta— porque
	 * es lo que el front ya sabe llamar, y todos por el mismo guard.
	 *
	 * `set-actual` y `set-in-action` son los dos que no son un booleano y ya está:
	 * encender uno **apaga los demás del mismo dueño y año**, que es lo que
	 * significa «la actual».
	 */

	public function putSetVotanProfes()
	{
		return $this->cambiarInterruptor('votan_profes');
	}

	public function putSetVotanAcudientes()
	{
		return $this->cambiarInterruptor('votan_acudientes');
	}

	/** Nuevo: los alumnos. Hasta hoy se daba por hecho que votaban ellos y punto. */
	public function putSetVotanEstudiantes()
	{
		return $this->cambiarInterruptor('votan_estudiantes');
	}

	/** Nuevo: el personal con cuenta que no es docente, o sea `users.tipo = 'Usuario'`. */
	public function putSetVotanAdministrativos()
	{
		return $this->cambiarInterruptor('votan_administrativos');
	}

	/** Nuevo: el titular conduce la mesa de su grupo sin que nadie la cree. */
	public function putSetTitularesConducen()
	{
		return $this->cambiarInterruptor('titulares_conducen');
	}

	/** Nuevo: con esto encendido, un grupo en modo mesa **sólo** vota en su mesa. */
	public function putSetSoloEnMesa()
	{
		return $this->cambiarInterruptor('solo_en_mesa');
	}


	public function putSetLocked()
	{
		return $this->cambiarInterruptor('locked');
	}


	/**
	 * **Publicar el recuento**, y es el único de los once que no lo enciende el
	 * dueño y nada más.
	 *
	 * Los otros diez van por `VtVotacion::exigirAdministrable()`, o sea *superusuario
	 * o quien la creó*. Con eso, **rectoría y coordinación no podían publicar una
	 * elección que no hubieran creado ellas** —y en un colegio la crea el docente que
	 * lleva democracia escolar—, así que el interruptor que la decisión del 23 sep
	 * 2026 pone en sus manos era el único que no podían tocar. De ahí
	 * `exigirPublicable()`, que es el mismo guard con el conjunto de esa decisión.
	 *
	 * Y es el **mismo** predicado con el que los tres sitios del recuento deciden si
	 * el número viaja, a propósito: quien ve antes es quien publica.
	 */
	public function putSetPermisoVerResults()
	{
		return $this->cambiarInterruptor('can_see_results', true);
	}


	public function putSetInAction()
	{
		return $this->cambiarLaUnica('in_action');
	}


	public function putSetActual()
	{
		return $this->cambiarLaUnica('actual');
	}


	/**
	 * Los segundos que la pantalla espera antes de enseñar el tarjetón.
	 *
	 * Es el rato en que el de la mesa aparta la vista. **0 es válido** y significa
	 * que no hay espera; el tope son {@see self::CUENTA_ATRAS_MAXIMA} segundos.
	 */
	public function putSetCuentaAtras()
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable(Request::input('id'), $user);

		if (! Request::has('cuenta_atras')) {
			abort(422, 'Falta `cuenta_atras`: hay que decir cuántos segundos.');
		}

		$votacion->cuenta_atras = $this->cuentaAtrasValidada(null);
		$votacion->updated_by   = $user->user_id;
		$votacion->save();

		return 'Cambiado';
	}


	/**
	 * La urna que hace falta abrir entre dos.
	 *
	 * **La clave se enseña una sola vez y en esta respuesta.** Se genera aquí —de
	 * {@see self::CIFRAS_DE_LA_CLAVE} cifras, con `random_int`, que es el generador
	 * criptográfico y no `rand()`— y en la tabla queda sólo su `bcrypt`, por lo
	 * mismo que `users.password`: quien pueda mirar la fila no tiene por qué poder
	 * abrir la urna. El porqué entero está en el docblock de la migración
	 * `2026_09_22_300000_la_configuracion_de_la_votacion`.
	 *
	 * **Encenderla estando ya encendida genera una clave nueva**, y ésa es la única
	 * forma de recuperarse de haberla perdido: del hash no se saca la vieja. Va en
	 * la respuesta (`clave`) para que la pantalla pueda avisar de que la anterior ya
	 * no sirve.
	 *
	 * Apagarla borra el hash. Un `clave_doble_llave` que sobrevive a su interruptor
	 * es una clave que vuelve sola el día que alguien reencienda.
	 */
	public function putSetDobleLlave()
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable(Request::input('id'), $user);

		$encendida = $this->booleanoObligatorio('doble_llave');

		if (! $encendida) {
			$votacion->doble_llave       = 0;
			$votacion->clave_doble_llave = null;
			$votacion->updated_by        = $user->user_id;
			$votacion->save();

			return ['doble_llave' => 0, 'clave' => null];
		}

		$clave = str_pad(
			(string) random_int(0, (10 ** self::CIFRAS_DE_LA_CLAVE) - 1),
			self::CIFRAS_DE_LA_CLAVE,
			'0',
			STR_PAD_LEFT
		);

		$votacion->doble_llave       = 1;
		$votacion->clave_doble_llave = Hash::make($clave);
		$votacion->updated_by        = $user->user_id;
		$votacion->save();

		// La única vez que esta clave viaja en claro.
		return ['doble_llave' => 1, 'clave' => $clave];
	}


	public function putUpdate($id)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($id, $user);

		if (Request::has('nombre')) {
			$votacion->nombre = $this->nombreValidado();
		}

		$fechas = $this->fechasValidadas($votacion->fecha_inicio, $votacion->fecha_fin);

		$votacion->fecha_inicio = $fechas['fecha_inicio'];
		$votacion->fecha_fin    = $fechas['fecha_fin'];

		foreach ([
			'votan_estudiantes',
			'votan_profes',
			'votan_acudientes',
			'votan_administrativos',
			'titulares_conducen',
			'solo_en_mesa',
			'locked',
			'can_see_results',
		] as $columna) {
			if (Request::has($columna)) {
				$votacion->$columna = $this->booleanoObligatorio($columna);
			}
		}

		if (Request::has('cuenta_atras')) {
			$votacion->cuenta_atras = $this->cuentaAtrasValidada(null);
		}

		/*
		 * `actual` e `in_action` se leen igual que antes —el cuerpo parcial no los
		 * toca— pero encenderlos aquí apaga a las demás, como hace `set-actual`. Sin
		 * esto, `update` era la puerta de atrás por la que el colegio se quedaba con
		 * dos elecciones actuales a la vez.
		 */
		$encender_actual    = Request::has('actual')    ? $this->booleanoObligatorio('actual')    : null;
		$encender_in_action = Request::has('in_action') ? $this->booleanoObligatorio('in_action') : null;

		if ($encender_actual !== null) {
			$votacion->actual = $encender_actual;
		}

		if ($encender_in_action !== null) {
			$votacion->in_action = $encender_in_action;
		}

		$votacion->updated_by = $user->user_id;
		$votacion->save();

		if ($encender_actual) {
			$this->apagarLasDemas('actual', $votacion->id, $votacion->user_id, $votacion->year_id);
		}

		if ($encender_in_action) {
			$this->apagarLasDemas('in_action', $votacion->id, $votacion->user_id, $votacion->year_id);
		}

		return $votacion;
	}



	/**
	 * La papeleta: lo que recibe quien entra a votar, con todos los eventos en
	 * acción a los que está inscrito.
	 *
	 * ## EL CONTEO VOLVÍA A VIAJAR CON LA PAPELETA, POR ESTA PUERTA
	 *
	 * Este método le colgaba `cantidad` y `total` a cada candidato —y al voto en
	 * blanco— **sin mirar `can_see_results` en ningún sitio**: el interruptor no
	 * aparecía en el método. O sea que cualquier alumno con la elección abierta
	 * recibía el escrutinio en vivo **en la misma respuesta con la que iba a
	 * votar**, y sabía quién iba ganando antes de marcar.
	 *
	 * Es exactamente la fuga del §1 de `docs/migracion/11-votaciones.md` —«el
	 * conteo viajaba con la papeleta»—, cerrada en `votos/show` el 21 ago 2026 y
	 * abierta aquí hasta el 23 sep. Que la pantalla no lo pinte no la cierra:
	 * el dato iba en el cable, a un F12 de distancia.
	 *
	 * ## EL CRITERIO ES EL QUE YA ESTABA, NO OTRO
	 *
	 * El mismo de `VtVotosController::putShow()` y `VtResultadosController::getShow()`:
	 *
	 *   - la **estructura** —cargos, candidatos, el blanco— viaja siempre, porque
	 *     es lo que hace falta para votar;
	 *   - el **número** sólo con `can_see_results`, y con la excepción que es la
	 *     mitad de la regla: **antes de publicarlo lo ve quien puede publicarlo**
	 *     —superusuario, rectoría, coordinación y quien creó la elección—, que es
	 *     `VtVotacion::puedePublicarResultados()`. El interruptor existe para que
	 *     nadie vea el marcador antes de que se decida publicarlo, no para que el
	 *     rector no pueda mirar su propia elección.
	 *
	 * > **Aquí decía «al personal del colegio se le da siempre» y eso duró un día.**
	 * > El 23 sep 2026 el colegio lo estrechó: `$esPersonal` era *todo el que no es
	 * > Alumno ni Acudiente*, así que un docente de matemáticas, la enfermera y la
	 * > secretaria recibían el marcador en vivo **en la misma respuesta con la que
	 * > se vota**. La decisión entera está en el predicado del modelo y en la 11 §9.
	 *
	 * ## LAS CLAVES DESAPARECEN; NO VAN EN CERO
	 *
	 * Un cero es una **afirmación falsa** —«este candidato no tiene votos»— y
	 * una pantalla que lo pinte miente con cara de dato bueno, justo en la
	 * pantalla donde se está decidiendo el voto. La clave ausente sólo dice que
	 * el conteo no viajó. Y es lo que ya hace `putShow()` con el tarjetón, así
	 * que las dos puertas contestan igual.
	 *
	 * Comprobado antes de elegirlo, que es lo que hizo que el arreglo del §1 no
	 * fuera el de una línea:
	 *
	 *   - `app2` declara `cantidad?` y `total?` **opcionales**
	 *     (`datos/votos.ts`, `CandidatoDelTarjeton`) y `paginas/votaciones/votar/`
	 *     no los lee: cero apariciones en el componente y en la plantilla;
	 *   - `myvc_flutter` **tira los dos campos al leer** y ningún modelo tiene
	 *     sitio donde guardarlos, a propósito (`lib/Http/VotacionesApi.dart`).
	 *
	 * Así que no hay pantalla que se rompa, y al personal no le cambia nada:
	 * sigue recibiendo las dos cifras con el interruptor en cualquier posición.
	 *
	 * No se añade un `conteo_visible` como el de `resultados/{id}`: allí la
	 * pantalla tiene que explicar por qué no hay números, y aquí no hay números
	 * que explicar —la papeleta nunca los pinta—.
	 *
	 * De regalo, un alumno deja de disparar una consulta por candidato más otra
	 * por cargo (`VtVoto::deCandidato` y `enBlanco`) cada vez que abre la
	 * papeleta.
	 */
	public function getEnAccionInscrito()
	{
		$user = User::fromToken();

		$votaciones = VtVotacion::actualesInscrito($user, true);

		$cantVot = count($votaciones);

		if ($cantVot > 0) {

			for($i=0; $i < $cantVot; $i++){

				$aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=? and deleted_at is null', [$votaciones[$i]->id]);

				$completos = VtVotacion::verificarVotosCompletos($aspiraciones, $votaciones[$i]->id, $user->user_id);
				$votaciones[$i]->completos = $completos;

				/*
				 * Ver la cabecera del método: el interruptor decide el número, y antes
				 * de publicarlo lo ve quien puede publicarlo. Se pregunta por elección
				 * y no una vez, porque el dueño es de cada elección; el interruptor va
				 * primero para que la respuesta publicada no cueste la consulta de
				 * roles, que es lo único que hay detrás del predicado.
				 */
				$conConteo = (bool) $votaciones[$i]->can_see_results
					|| VtVotacion::puedePublicarResultados($votaciones[$i], $user);

				$cantAsp = count($aspiraciones);

				if ($cantAsp > 0) {

					for ($j=0; $j<$cantAsp; $j++) {

						// Sin el `username`, que es el documento de identidad: ver
						// `VtCandidato::sinElDocumento()`.
						$candidatos = VtCandidato::sinElDocumento(
							VtCandidato::porAspiracion($aspiraciones[$j]->id, $votaciones[$i]->year_id));

						/*
						 * Si ya votó este cargo. Eran **dos consultas** —una uniendo con
						 * `vt_candidatos` y otra contra `blanco_aspiracion_id`, la columna del
						 * voto en blanco— y la segunda dejó de existir el 22 sep 2026, así que
						 * esta pantalla respondía 500 desde esa migración. Con
						 * `vt_votos.aspiracion_id` es una sola y el blanco no es un caso
						 * aparte: es `candidato_id` nulo.
						 *
						 * Lo que se mira es **si** votó, nunca **a quién**: la fila que vuelve
						 * trae `candidato_id` dentro y no sale de aquí (11 §6).
						 */
						$aspiraciones[$j]->votado = VtVoto::deUsuarioEnCargo($aspiraciones[$j]->id, $user->user_id) !== null;

						// Traemos los votos que tiene cada candidato, si el conteo viaja.
						if ($conConteo) {
							for ($k=0; $k<count($candidatos); $k++) {

								$votos = VtVoto::deCandidato($candidatos[$k]->candidato_id, $aspiraciones[$j]->id)[0];
								$candidatos[$k]->cantidad = $votos->cantidad;
								$candidatos[$k]->total = $votos->total;
							}
						}

						/*
						 * Voto en blanco. Las dos cifras se ponen **antes** del `push`: el
						 * array se copia al meterlo, así que la versión vieja —que las
						 * escribía después— dejaba la opción del tarjetón sin `cantidad` ni
						 * `total`, y nadie lo notó porque el blanco no se pinta con número.
						 *
						 * Y con el conteo recortado **el blanco tampoco las lleva**: es una
						 * casilla más de la urna, y dejarle el número diría cuántos votos
						 * hay echados en ese cargo, que es media parte del marcador.
						 */
						$blanco = [
							'nombres'       => 'Voto en Blanco',
							'voto_blanco'   => true,
							'foto_nombre'   => 'voto_en_blanco.jpg',
							'imagen_nombre' => 'voto_en_blanco.jpg',
						];

						if ($conConteo) {
							$vt_blancos = VtVoto::enBlanco($aspiraciones[$j]->id)[0];

							$blanco['cantidad'] = $vt_blancos->cantidad;
							$blanco['total']    = $vt_blancos->total;
						}

						array_push($candidatos, $blanco);

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
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable($id, $user);

		$votacion->deleted_by = $user->user_id;
		$votacion->save();
		$votacion->delete();

		return $votacion;
	}


	/*
	 * ─────────────────────────────────────────────────────────────────────────
	 *  LO QUE COMPRUEBA CADA COSA
	 * ─────────────────────────────────────────────────────────────────────────
	 *
	 * A mano y no con `Validator::make`, que es lo que hace el resto de este
	 * proyecto —un `Validator` devuelve un 422 con la forma de Laravel y ninguno de
	 * los clientes la lee—. El mensaje dice qué falta, que es lo que el `try/catch`
	 * de antes no decía.
	 */

	/**
	 * Enciende o apaga una columna booleana de la elección.
	 *
	 * `$paraPublicar` cambia **el guard y sólo el guard**: con él entran también
	 * rectoría y coordinación en una elección que no crearon, que es lo que pide la
	 * decisión del 23 sep 2026 para `can_see_results`. Los otros diez interruptores
	 * siguen siendo del dueño —ver `putSetPermisoVerResults()`—, y el parámetro está
	 * aquí en vez de en un método aparte para que no haya dos copias de las tres
	 * líneas que escriben la columna.
	 */
	private function cambiarInterruptor(string $columna, bool $paraPublicar = false)
	{
		$user     = User::fromToken();
		$votacion = $paraPublicar
			? VtVotacion::exigirPublicable(Request::input('id'), $user)
			: VtVotacion::exigirAdministrable(Request::input('id'), $user);

		$votacion->$columna   = $this->booleanoObligatorio($columna);
		$votacion->updated_by = $user->user_id;
		$votacion->save();

		return 'Cambiado';
	}

	/**
	 * `actual` e `in_action`: encender una apaga a las demás del mismo dueño y año.
	 *
	 * Los dos escribían con `DB::statement`, que **se salta el scope de
	 * `SoftDeletes`**: una elección en la papelera seguía cambiando de estado, así
	 * que restaurarla devolvía algo distinto de lo que se borró (11 §5.1). Ahora van
	 * por el modelo, como sus cuatro hermanos.
	 */
	private function cambiarLaUnica(string $columna)
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable(Request::input('id'), $user);

		$encender = $this->booleanoObligatorio($columna);

		$votacion->$columna   = $encender;
		$votacion->updated_by = $user->user_id;
		$votacion->save();

		if ($encender) {
			$this->apagarLasDemas($columna, $votacion->id, $votacion->user_id, $votacion->year_id);
		}

		return $encender ? 'Cambiado true' : 'Cambiado false';
	}

	/**
	 * Apaga esa columna en las **otras** elecciones del mismo dueño y del mismo año.
	 *
	 * El año es el **de la votación** y no el del usuario: son dos años distintos y
	 * el login mueve el segundo, así que un docente que se hubiera pasado a 2025
	 * habría apagado las elecciones de 2025 al poner como actual la suya de 2026.
	 *
	 * Y el `user_id` está porque «la elección actual» es de cada docente
	 * —`VtVotacion::actual()` filtra por él—: sin ese filtro, poner la mía como
	 * actual apaga la de todos los demás. Que es exactamente lo que hacía `store`:
	 * `UPDATE vt_votaciones SET actual=0 WHERE actual=1`, sin más condición, sobre
	 * todos los años del colegio.
	 */
	private function apagarLasDemas(string $columna, $votacion_id, $user_id, $year_id)
	{
		VtVotacion::where('id', '<>', $votacion_id)
			->where('user_id', $user_id)
			->where('year_id', $year_id)
			->where($columna, true)
			->update([$columna => false]);
	}

	/** El nombre de la elección, que es lo que sale impreso en el acta. */
	private function nombreValidado()
	{
		$nombre = Request::input('nombre');

		if (! is_string($nombre) || trim($nombre) === '') {
			abort(422, 'Hay que ponerle nombre a la elección.');
		}

		$nombre = trim($nombre);

		if (mb_strlen($nombre) > self::LARGO_NOMBRE) {
			abort(422, 'El nombre de la elección no puede pasar de '.self::LARGO_NOMBRE.' caracteres.');
		}

		return $nombre;
	}

	/**
	 * Las dos fechas, y que la de cierre no sea anterior a la de apertura.
	 *
	 * **Las columnas son `date` y no `datetime`**, y eso importa: la versión vieja
	 * metía `date('Y-m-d H:i:s')` cuando no venían, o sea que MySQL truncaba la hora
	 * en silencio y la fila quedaba con una fecha que nadie había escrito. Ausentes
	 * se quedan como estaban —nulas al crear—, que es lo que el esquema permite y lo
	 * honesto: una elección sin fechas es una elección sin fechas, no una que
	 * empieza y acaba hoy.
	 *
	 * @param  ?string  $inicio_actual  lo que la fila ya tenía, para el cuerpo parcial de `update`
	 * @param  ?string  $fin_actual
	 * @return array{fecha_inicio: ?string, fecha_fin: ?string}
	 */
	private function fechasValidadas($inicio_actual, $fin_actual)
	{
		$inicio = Request::has('fecha_inicio') ? $this->fechaValidada('fecha_inicio') : $this->soloElDia($inicio_actual);
		$fin    = Request::has('fecha_fin')    ? $this->fechaValidada('fecha_fin')    : $this->soloElDia($fin_actual);

		if ($inicio !== null && $fin !== null && $fin < $inicio) {
			abort(422, 'La elección no puede cerrar antes de abrir.');
		}

		return ['fecha_inicio' => $inicio, 'fecha_fin' => $fin];
	}

	/** Una fecha del cuerpo, en `Y-m-d`, o nula. */
	private function fechaValidada(string $campo)
	{
		$valor = Request::input($campo);

		if ($valor === null || $valor === '') {
			return null;
		}

		if (! is_string($valor)) {
			abort(422, 'La fecha `'.$campo.'` no es una fecha.');
		}

		$dia = $this->soloElDia($valor);

		if ($dia === null) {
			abort(422, 'La fecha `'.$campo.'` no es una fecha.');
		}

		return $dia;
	}

	/** `2026-09-22` de lo que venga, o `null` si no hay día que sacar. */
	private function soloElDia($valor)
	{
		if (! is_string($valor) || $valor === '') {
			return null;
		}

		$fecha = date_create(substr($valor, 0, 10));

		return $fecha === false ? null : $fecha->format('Y-m-d');
	}

	/**
	 * Los cargos con los que nace la elección: **al menos uno**.
	 *
	 * Una elección sin cargos no se puede votar —la pantalla pinta un tarjetón
	 * vacío— y se creaba sin avisar: el `count()` del bucle sobre un `null` era un
	 * `TypeError` en PHP 8, o sea un 500 **después** de haber insertado la fila.
	 *
	 * @return array<int, array{aspiracion: string, abrev: string}>
	 */
	private function aspiracionesValidadas()
	{
		$aspiraciones = Request::input('aspiraciones');

		if (! is_array($aspiraciones) || count($aspiraciones) === 0) {
			abort(422, 'Una elección necesita al menos un cargo al que aspirar.');
		}

		$limpias = [];

		foreach ($aspiraciones as $aspiracion) {

			if (! is_array($aspiracion)) {
				abort(422, 'Cada cargo tiene que venir como un objeto con `aspiracion` y `abrev`.');
			}

			$limpias[] = VtAspiracionesController::cargoValidado(
				$aspiracion['aspiracion'] ?? null,
				$aspiracion['abrev'] ?? null
			);
		}

		return $limpias;
	}

	/** Un interruptor que TIENE que venir en el cuerpo. Ver el punto 3 de la cabecera. */
	private function booleanoObligatorio(string $campo)
	{
		if (! Request::has($campo)) {
			abort(422, 'Falta `'.$campo.'`: hay que decir si se enciende o se apaga.');
		}

		return $this->comoBooleano(Request::input($campo), $campo);
	}

	/** Un interruptor que puede no venir, y entonces vale lo que diga la columna. */
	private function booleanoOpcional(string $campo, bool $defecto)
	{
		if (! Request::has($campo)) {
			return $defecto ? 1 : 0;
		}

		return $this->comoBooleano(Request::input($campo), $campo);
	}

	/**
	 * `true`, `1`, `"1"`, `"true"` y sus contrarios. Cualquier otra cosa es un 422.
	 *
	 * Los clientes mandan JSON con booleanos de verdad, pero un
	 * `application/x-www-form-urlencoded` manda `"1"` y `"0"`, y `(bool) "0"` es
	 * `false` mientras que `(bool) "false"` es **`true`**. Por eso se nombran los
	 * valores en vez de castear.
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

	/** Los segundos de la cuenta atrás, entre 0 y {@see self::CUENTA_ATRAS_MAXIMA}. */
	private function cuentaAtrasValidada($defecto)
	{
		if (! Request::has('cuenta_atras')) {
			return $defecto;
		}

		$valor = Request::input('cuenta_atras');

		if (is_bool($valor) || ! is_numeric($valor) || (string) (int) $valor !== trim((string) $valor)) {
			abort(422, 'La cuenta atrás se mide en segundos enteros.');
		}

		$segundos = (int) $valor;

		if ($segundos < 0 || $segundos > self::CUENTA_ATRAS_MAXIMA) {
			abort(422, 'La cuenta atrás va de 0 a '.self::CUENTA_ATRAS_MAXIMA.' segundos.');
		}

		return $segundos;
	}

}
