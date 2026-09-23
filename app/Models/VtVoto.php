<?php namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
/**
 * Un voto. **Una vez emitido, no se toca.**
 *
 * --- columnas de la tabla ---
 *
 * @property int $id
 * @property int $user_id
 * @property int $votacion_id
 * @property ?int $candidato_id
 * @property ?int $asistido_por
 * @property int $locked
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * @property int $aspiracion_id
 * @property ?int $mesa_id
 * @property string $origen
 * @property ?int $segundos
 * --- fin de las columnas ---
 *
 * @property bool $completo  si el voto cubrió todas las aspiraciones
 *
 * # LA UNICIDAD LA DA LA BASE, NO ESTE MODELO
 *
 * > `UNIQUE (votacion_id, aspiracion_id, user_id)` — `vt_votos_un_voto_por_cargo`
 *
 * Un usuario, un voto por cargo, y el segundo `INSERT` revienta con un 1062. No
 * hay ningún método aquí que lo compruebe, y **eso es el diseño**: una
 * comprobación en PHP es una carrera entre dos peticiones, y el módulo tenía dos
 * puertas de escritura.
 *
 * ## Lo que había antes, para que nadie lo devuelva sin leer esto
 *
 * `verificarNoVoto()`. El nombre decía que comprobaba; lo que hacía era **buscar
 * el voto anterior del mismo usuario en esa aspiración y mandarlo a la
 * papelera** para que cupiera el nuevo. O sea que votar dos veces cambiaba el
 * voto, y **eso era lo único que impedía que el recuento se inflara**, porque
 * `postStore()` tampoco mira `locked`
 * ([11 §2 y §3](../../docs/migracion/11-votaciones.md)).
 *
 * Se ha quitado, y con ella el `SoftDeletes`, en la misma tanda que puso el
 * índice — que es el orden que el 11 §3 exige: *«el día que alguien arregle el
 * borrado, enciende el fallo de la §2 sin haberla tocado»*. Aquí el fallo no se
 * enciende porque la regla ya no la sostiene el borrado.
 *
 * ## `deleted_at` SIGUE EN LA TABLA, Y ESTÁ VACÍA PARA SIEMPRE
 *
 * No se tiró porque el `down()` de la migración la necesita. **Devolverle
 * `SoftDeletes` a esta clase choca de frente con el índice**: una fila borrada
 * seguiría ocupando su hueco de `(votacion_id, aspiracion_id, user_id)`, así que
 * quien cambiara de voto se encontraría con que no puede votar de nuevo. Y la
 * papelera era además una fuga del voto secreto, porque conservaba `user_id` y
 * `candidato_id` intactos (11 §6).
 *
 * # `candidato_id` NULO ES EL VOTO EN BLANCO
 *
 * Y `aspiracion_id` dice de qué cargo. Antes el blanco vivía en una columna
 * aparte —`blanco_aspiracion_id`—, así que el mismo dato tenía dos caminos y
 * cada consulta del módulo elegía uno; por eso el índice no se podía poner. Una
 * forma y no dos.
 *
 * Ver `database/migrations/2026_09_22_600000_el_voto_que_no_se_reemplaza.php`.
 */


class VtVoto extends Model {
	protected $fillable = [];
	protected $table = "vt_votos";

	/** Emitido desde la cuenta del votante, donde estuviera. */
	public const ORIGEN_PROPIO = 'propio';

	/** Emitido en una mesa, con alguien del colegio delante. */
	public const ORIGEN_MESA = 'mesa';

	/**
	 * **Sin `SoftDeletes`, y no es un olvido.** Un voto no se borra: la papelera
	 * era lo que sostenía la unicidad y ahora la sostiene el índice. Ver la
	 * cabecera antes de devolverlo.
	 */

	public function votacion()
	{
		return $this->belongsTo(VtVotacion::class, 'votacion_id');
	}

	public function aspiracion()
	{
		return $this->belongsTo(VtAspiracion::class, 'aspiracion_id');
	}

	/** Nulo = voto en blanco. */
	public function candidato()
	{
		return $this->belongsTo(VtCandidato::class, 'candidato_id');
	}

	/** Quien condujo la mesa. **No** es quien votó: las votaciones son secretas. */
	public function asistente()
	{
		return $this->belongsTo(User::class, 'asistido_por');
	}

	public function mesa()
	{
		return $this->belongsTo(VtMesa::class, 'mesa_id');
	}

	/**
	 * El recuento de un candidato en un cargo, y el total del cargo.
	 *
	 * Devuelve **una fila** con `cantidad` y `total`, que es el contrato que ya
	 * leían `VtVotacionesController` y `VtVotosController` —se conserva a
	 * propósito para no tocarlos desde aquí—.
	 *
	 * ## QUÉ SE ARREGLÓ AL REESCRIBIRLA, que no es el estilo
	 *
	 * La versión vieja contaba uniendo con `vt_candidatos`, así que **el `total`
	 * del cargo se dejaba fuera los votos en blanco**: no tienen candidato con el
	 * que unir. Un cargo con 40 votos y 8 blancos decía «total 32», y los
	 * porcentajes del tarjetón salían de ahí.
	 *
	 * Ahora las dos cuentas salen de `vt_votos.aspiracion_id`, que es la columna
	 * que la migración puso justo para esto, y el blanco cuenta en el total como
	 * cuenta en una urna de verdad.
	 *
	 * Y el filtro de `deleted_at` ya no hace falta: la tabla no tiene papelera.
	 */
	public static function deCandidato($candidato_id, $aspiracion_id)
	{
		$consulta = 'SELECT
				(SELECT COUNT(*) FROM vt_votos
				  WHERE aspiracion_id = :aspiracion_id_a AND candidato_id = :candidato_id) as cantidad,
				(SELECT COUNT(*) FROM vt_votos
				  WHERE aspiracion_id = :aspiracion_id_b) as total';

		$datos = [
			'aspiracion_id_a' => $aspiracion_id,
			'candidato_id'    => $candidato_id,
			'aspiracion_id_b' => $aspiracion_id,
		];

		return DB::select($consulta, $datos);
	}

	/**
	 * El recuento de los votos en blanco de un cargo, con el mismo `total`.
	 *
	 * Es la hermana de `deCandidato()` para el blanco, y existe porque el blanco
	 * ya no es un candidato con el que unir: es `candidato_id IS NULL`. Antes esto
	 * era SQL crudo repetido en tres sitios de los controladores, cada uno con su
	 * filtro de papelera.
	 */
	public static function enBlanco($aspiracion_id)
	{
		$consulta = 'SELECT
				(SELECT COUNT(*) FROM vt_votos
				  WHERE aspiracion_id = :aspiracion_id_a AND candidato_id IS NULL) as cantidad,
				(SELECT COUNT(*) FROM vt_votos
				  WHERE aspiracion_id = :aspiracion_id_b) as total';

		$datos = [
			'aspiracion_id_a' => $aspiracion_id,
			'aspiracion_id_b' => $aspiracion_id,
		];

		return DB::select($consulta, $datos);
	}

	/**
	 * Si este usuario ya votó algo en esta elección.
	 *
	 * Sustituye a la vieja `hasVoted($votacion_id, $participante_id)`, que
	 * filtraba por `vt_votos.participante_id` — **una columna que no existe en
	 * ninguna de las dieciséis bases** ([05 §18](../../docs/migracion/05-codigo-muerto-y-roto.md)),
	 * así que respondía 500 desde siempre. El parámetro cambia de significado y de
	 * nombre: ahora es un `users.id`.
	 */
	public static function yaVoto($votacion_id, $user_id)
	{
		$fila = DB::selectOne('SELECT vv.id FROM vt_votos vv
			WHERE vv.votacion_id = :votacion_id AND vv.user_id = :user_id LIMIT 1',
			['votacion_id' => $votacion_id, 'user_id' => $user_id]);

		return $fila !== null;
	}

	/**
	 * El voto de este usuario en este cargo, o `null`.
	 *
	 * Sustituye a `votesInAspiracion($aspiracion_id, $participante_id)`, rota por
	 * lo mismo que la de arriba. Devuelve **una fila y no un array**, porque el
	 * índice único garantiza que no puede haber dos.
	 *
	 * Ojo con para qué se usa: decir *si ya votó* es legítimo y hace falta el día
	 * de la elección; decir *a quién votó* es la fuga del voto secreto que la
	 * [11 §6](../../docs/migracion/11-votaciones.md) manda cerrar. Esta consulta
	 * devuelve `candidato_id`, así que **quien la llame decide si eso viaja al
	 * cliente**.
	 */
	public static function deUsuarioEnCargo($aspiracion_id, $user_id)
	{
		return DB::selectOne('SELECT vv.id, vv.candidato_id, vv.aspiracion_id, vv.created_at
			FROM vt_votos vv
			WHERE vv.aspiracion_id = :aspiracion_id AND vv.user_id = :user_id LIMIT 1',
			['aspiracion_id' => $aspiracion_id, 'user_id' => $user_id]);
	}

	/**
	 * **La constancia de un voto: que existe, cuándo y con quién delante.**
	 *
	 * Es lo que se le contesta a quien intenta votar dos veces —el 409 de
	 * `votos/store` y el de `mesas/{mesa}/abrir`— y lo que pinta la pantalla de
	 * bloqueo de la mesa.
	 *
	 * ## LO QUE NO DEVUELVE, que es el motivo de que exista
	 *
	 * **`candidato_id` no sale de aquí.** `deUsuarioEnCargo()` sí lo trae, y su
	 * propio docblock avisa de que quien la llame decide si eso viaja al cliente;
	 * esta es la versión para el caso en que la respuesta **se le enseña a una
	 * persona que no es el votante** —el de la mesa—, así que la decisión ya está
	 * tomada aquí dentro y no en cada llamada. Decir *que* votó hace falta el día
	 * de la elección; decir *a quién* es la fuga que la
	 * [11 §6](../../docs/migracion/11-votaciones.md) manda cerrar.
	 *
	 * `asistido_por` **no es quien votó**: es quien conducía la mesa. El nombre
	 * sale de `profesores` cuando la cuenta tiene ficha y del `username` cuando no
	 * —una cuenta de secretaría no está en `profesores`, que es la misma razón por
	 * la que `vt_mesa_usuarios.user_id` apunta a `users`—.
	 *
	 * @param  int|null  $aspiracion_id  un cargo concreto, o null para todos los de la elección
	 * @return array<int, object>
	 */
	public static function constancia($votacion_id, $user_id, $aspiracion_id = null)
	{
		$consulta = 'SELECT vv.id, vv.aspiracion_id, vv.created_at, vv.origen,
				vv.mesa_id, me.nombre as mesa_nombre, vv.asistido_por,
				TRIM(CONCAT(IFNULL(pr.nombres, ""), " ", IFNULL(pr.apellidos, ""))) as asistente_nombre,
				asi.username as asistente_username,
				asp.aspiracion, asp.abrev
			FROM vt_votos vv
			INNER JOIN vt_aspiraciones asp ON asp.id = vv.aspiracion_id
			LEFT JOIN vt_mesas me ON me.id = vv.mesa_id
			LEFT JOIN users asi ON asi.id = vv.asistido_por
			LEFT JOIN profesores pr ON pr.user_id = asi.id AND pr.deleted_at IS NULL
			WHERE vv.votacion_id = :votacion_id AND vv.user_id = :user_id';

		$datos = ['votacion_id' => $votacion_id, 'user_id' => $user_id];

		if ($aspiracion_id !== null) {
			$consulta .= ' AND vv.aspiracion_id = :aspiracion_id';
			$datos['aspiracion_id'] = $aspiracion_id;
		}

		$filas = DB::select($consulta.' ORDER BY vv.created_at', $datos);

		foreach ($filas as $fila) {
			// El nombre de quien condujo, ya resuelto: la pantalla no tiene por qué
			// saber que unas cuentas tienen ficha de profesor y otras no.
			$fila->asistente = $fila->asistente_nombre !== null && $fila->asistente_nombre !== ''
				? $fila->asistente_nombre
				: $fila->asistente_username;
		}

		return $filas;
	}

	/**
	 * Los cargos de esta elección que este usuario **todavía no ha votado**.
	 *
	 * La papeleta de la mesa sale de aquí: se abre con lo que falta, no con todo.
	 * Un `NOT EXISTS` y no un `NOT IN` con subconsulta suelta porque el índice
	 * único empieza por `votacion_id`, así que la correlación lo usa entero.
	 *
	 * @return array<int, object>
	 */
	public static function cargosQueFaltan($votacion_id, $user_id)
	{
		return DB::select('SELECT asp.id, asp.aspiracion, asp.abrev
			FROM vt_aspiraciones asp
			WHERE asp.votacion_id = :votacion_id
			  AND asp.deleted_at IS NULL
			  AND NOT EXISTS (SELECT 1 FROM vt_votos vv
							   WHERE vv.votacion_id = asp.votacion_id
								 AND vv.aspiracion_id = asp.id
								 AND vv.user_id = :user_id)
			ORDER BY asp.id', ['votacion_id' => $votacion_id, 'user_id' => $user_id]);
	}
}
