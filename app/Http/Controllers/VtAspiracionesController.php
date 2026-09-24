<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;


use App\Support\Reloj;
use App\User;
use App\Models\VtAspiracion;
use App\Models\VtVotacion;


/**
 * Los cargos a los que se aspira en una elección: personero, contralor, representante.
 *
 * ## EL BUG VIVO: `store` guardaba la fila sin el cargo dentro
 *
 * Escribía **sólo `votacion_id`**. `aspiracion` y `abrev` son `varchar(255) NOT
 * NULL` sin defecto, así que con el `sql_mode` de estos servidores la fila nacía
 * con las dos en blanco y **respondía 200**: un cargo sin nombre en el tarjetón.
 *
 * Y no es un descuido de una línea, es la forma de la pantalla: el
 * `VotacionesCtrl` del front llama `AspiracionesApi.crear({votacion_id})` —el
 * cuerpo entero es eso— y **después** manda el nombre por `aspiraciones/update`.
 * O sea que crear-en-blanco-y-editar es el flujo de los dieciséis colegios, no un
 * error del cliente.
 *
 * Por eso el arreglo es de los dos lados y no de uno:
 *
 *   - `store` **guarda `aspiracion` y `abrev` cuando vienen** —que es lo que no
 *     hacía— y sigue admitiendo el renglón en blanco del flujo de arriba.
 *   - `update` **exige el nombre**, porque es el paso donde el cargo se llama de
 *     verdad y donde un blanco sí es un error.
 *   - `votaciones/store`, que crea la elección con sus cargos de una vez, exige el
 *     nombre de cada uno: ahí no hay segundo paso que lo arregle.
 *
 * La regla de un cargo vive en {@see self::cargoValidado()} y la usan los tres,
 * para que «cómo se llama un cargo» no tenga dos respuestas.
 *
 * ## Y los tres endpoints comprueban de quién es la elección
 *
 * `auth.personal` son los 51 docentes del colegio, y con el `id` en el cuerpo
 * cualquiera de ellos añadía, renombraba o borraba los cargos de la elección de
 * otro — el mismo agujero que los interruptores de la 11 §5, por otra puerta.
 */
class VtAspiracionesController extends Controller {

	/** `vt_aspiraciones.aspiracion` y `.abrev` son `varchar(255)`, y MySQL trunca en silencio. */
	private const LARGO_ASPIRACION = 255;

	private const LARGO_ABREV = 255;


	/**
	 * Los cargos de la elección actual de quien pregunta.
	 *
	 * Sin elección actual devolvía 500 —`$votacion->id` sobre `null`—. Ahora es un
	 * 404, que es lo que pasa: no hay elección de la que sacar cargos.
	 */
	public function index()
	{
		$user = User::fromToken();

		$votacion = VtVotacion::where('actual', true)
							->where('user_id', $user->user_id)
							->where('year_id', $user->year_id)->first();

		if (! $votacion) {
			abort(404, 'No hay ninguna elección puesta como actual.');
		}

		return VtAspiracion::where('votacion_id', $votacion->id)->get();
	}



	/**
	 * Añade un cargo a una elección.
	 *
	 * El nombre puede llegar en blanco **a propósito**: ver la cabecera. Lo que no
	 * puede es perderse cuando llega, que es lo que pasaba.
	 */
	public function postStore()
	{
		$user     = User::fromToken();
		$votacion = VtVotacion::exigirAdministrable(Request::input('votacion_id'), $user);

		$cargo = self::cargoValidado(
			Request::input('aspiracion'),
			Request::input('abrev'),
			false
		);

		$ahora = Reloj::ahoraTexto();

		$aspiracion              = new VtAspiracion;
		$aspiracion->aspiracion  = $cargo['aspiracion'];
		$aspiracion->abrev       = $cargo['abrev'];
		$aspiracion->votacion_id = $votacion->id;
		$aspiracion->created_by  = $user->user_id;
		$aspiracion->created_at  = $ahora;
		$aspiracion->updated_at  = $ahora;
		$aspiracion->save();

		return $aspiracion;
	}


	/**
	 * Renombra un cargo. **El `id` va en el cuerpo y se queda así.**
	 *
	 * Es la única ruta del módulo que lo hace —`PUT aspiraciones/update` a secas— y
	 * es lo que llama `AspiracionesApi.actualizar()` en el front de los dieciséis
	 * colegios. Moverlo a la URL sería un cambio de ruta, o sea despliegue en
	 * dieciséis carpetas y una pantalla que deja de guardar hasta que llegue; lo que
	 * faltaba no era la forma de la ruta, era comprobar lo que trae dentro.
	 */
	public function putUpdate()
	{
		$user = User::fromToken();

		$id = filter_var(Request::input('id'), FILTER_VALIDATE_INT);

		if ($id === false || $id < 1) {
			abort(422, 'El cargo no es válido.');
		}

		$aspiracion = VtAspiracion::find($id);

		if (! $aspiracion) {
			abort(404, 'Ese cargo no existe.');
		}

		// El dueño del cargo es el dueño de su elección.
		VtVotacion::exigirAdministrable($aspiracion->votacion_id, $user);

		$cargo = self::cargoValidado(
			Request::input('aspiracion'),
			Request::input('abrev'),
			true
		);

		$aspiracion->aspiracion = $cargo['aspiracion'];
		$aspiracion->abrev      = $cargo['abrev'];
		$aspiracion->updated_by = $user->user_id;
		$aspiracion->save();

		return $aspiracion;
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$aspiracion = VtAspiracion::find($id);

		if (! $aspiracion) {
			abort(404, 'Ese cargo no existe.');
		}

		VtVotacion::exigirAdministrable($aspiracion->votacion_id, $user);

		// El borrado es FÍSICO (el modelo no lleva SoftDeletes) y el esquema cuelga
		// en cascada de `vt_aspiraciones` los candidatos, `vt_votos.aspiracion_id`
		// —blancos incluidos— y `vt_acta_votos.aspiracion_id`, actas firmadas
		// incluidas. O sea que borrar un cargo con urna era borrar su escrutinio sin
		// vuelta atrás (05 §58.1). Un cargo sin votos ni cifras se sigue pudiendo
		// quitar: es el caso de montar la elección y equivocarse.
		$conVotos = DB::selectOne('SELECT
				EXISTS(SELECT 1 FROM vt_votos WHERE aspiracion_id = ?) AS digitales,
				EXISTS(SELECT 1 FROM vt_acta_votos WHERE aspiracion_id = ?) AS de_papel',
			[$aspiracion->id, $aspiracion->id]);

		if ($conVotos->digitales || $conVotos->de_papel) {
			abort(409, 'Ese cargo ya tiene votos: borrarlo borraría su escrutinio.');
		}

		$aspiracion->delete();

		return $aspiracion;
	}


	/**
	 * Cómo se llama un cargo, en un solo sitio.
	 *
	 * Lo usan los tres escritores —`aspiraciones/store`, `aspiraciones/update` y
	 * `votaciones/store`, que crea la elección con sus cargos de una vez— porque si
	 * no, la regla vive por triplicado y el día que el colegio pida que el rótulo
	 * del acta mida menos cambia en dos de los tres.
	 *
	 * **`abrev` nunca es obligatoria y nunca es nula.** Es el rótulo corto de la
	 * columna del tarjetón, la columna es `NOT NULL`, y quien no lo escriba se queda
	 * con la cadena vacía —que es lo que la pantalla ya pinta hoy— en vez de con un
	 * `PER` inventado aquí: dos cargos que empiecen igual darían la misma
	 * abreviatura y en el tarjetón se leerían como el mismo.
	 *
	 * @param  mixed  $aspiracion
	 * @param  mixed  $abrev
	 * @param  bool   $exigirNombre  si el renglón en blanco es un error
	 * @return array{aspiracion: string, abrev: string}
	 */
	public static function cargoValidado($aspiracion, $abrev, bool $exigirNombre = true)
	{
		if ($aspiracion === null) {
			$aspiracion = '';
		}

		if (! is_string($aspiracion)) {
			abort(422, 'El nombre del cargo tiene que ser un texto.');
		}

		$aspiracion = trim($aspiracion);

		if ($exigirNombre && $aspiracion === '') {
			abort(422, 'Hay que decir cómo se llama el cargo; es lo que se lee en el tarjetón.');
		}

		if (mb_strlen($aspiracion) > self::LARGO_ASPIRACION) {
			abort(422, 'El nombre del cargo no puede pasar de '.self::LARGO_ASPIRACION.' caracteres.');
		}

		if ($abrev === null) {
			$abrev = '';
		}

		if (! is_string($abrev)) {
			abort(422, 'La abreviatura del cargo tiene que ser un texto.');
		}

		$abrev = trim($abrev);

		if (mb_strlen($abrev) > self::LARGO_ABREV) {
			abort(422, 'La abreviatura del cargo no puede pasar de '.self::LARGO_ABREV.' caracteres.');
		}

		return ['aspiracion' => $aspiracion, 'abrev' => $abrev];
	}

}
