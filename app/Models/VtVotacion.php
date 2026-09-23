<?php namespace App\Models;

use App\Support\Autoriza;
use App\Support\Reloj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\DB;
/**
 * Las columnas de `vt_votaciones`.
 *
 * --- columnas de la tabla ---
 *
 * @property int $id
 * @property ?int $user_id
 * @property ?int $year_id
 * @property string $nombre
 * @property int $votan_estudiantes
 * @property int $votan_profes
 * @property int $votan_acudientes
 * @property int $votan_administrativos
 * @property int $locked
 * @property int $actual
 * @property int $in_action
 * @property int $can_see_results
 * @property ?string $fecha_inicio
 * @property ?string $fecha_fin
 * @property int $titulares_conducen
 * @property int $cuenta_atras
 * @property int $doble_llave
 * @property ?string $clave_doble_llave
 * @property int $solo_en_mesa
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto.
 *
 * @property array $aspiraciones  las aspiraciones de la votación
 * @property mixed $grupo_id  el grupo del alumno en esta elección, resuelto por `actualesInscrito()`
 */


class VtVotacion extends Model {
	protected $fillable = [];
	protected $table = "vt_votaciones";

	/**
	 * El hash de la doble llave no sale nunca en una respuesta.
	 *
	 * `GET censo/{id}` y `votaciones/*` devuelven la fila entera, así que sin esto
	 * el hash de la clave del día viaja a la pantalla de configuración — y de ahí a
	 * cualquiera que abra las herramientas del navegador. La clave en claro se
	 * entrega UNA vez, en la respuesta de `set-doble-llave`, y no vuelve a salir.
	 */
	protected $hidden = ['clave_doble_llave'];

	/**
	 * Quita el hash de la doble llave de una fila leída con SQL crudo.
	 *
	 * `$hidden` de arriba sólo actúa sobre Eloquent, y `actual()` y
	 * `actualesInscrito()` leen con `DB::select … SELECT *`. Sin esto, el bcrypt de
	 * la clave del día viaja en `votaciones/actual` y en `en-accion-inscrito` —o sea,
	 * al tarjetón de cualquier alumno—. Es el hash de **cuatro cifras**: romperlo
	 * fuera de línea es cuestión de milisegundos, y entonces la doble llave deja de
	 * ser una llave.
	 *
	 * No se nombran las columnas en el `SELECT` a propósito: el día que se añada un
	 * interruptor, nadie se acordaría de listarlo y la pantalla se quedaría sin él en
	 * silencio. Lo que se puede olvidar aquí es dejar de ocultar algo nuevo, y eso
	 * salta a la vista en cuanto alguien mira la respuesta.
	 */
	private static function sinElHash($fila)
	{
		if (is_object($fila)) {
			unset($fila->clave_doble_llave);
		}

		return $fila;
	}

	use SoftDeletes;
	protected $softDelete = true;


	/*
	 * ─────────────────────────────────────────────────────────────────────────
	 *  LOS ESTAMENTOS, y por qué son cuatro y no seis
	 * ─────────────────────────────────────────────────────────────────────────
	 *
	 * Medido el 22 sep 2026 contra `simonbolivar` y `caz_zaragoza`: `users.tipo`
	 * toma cuatro valores y ni uno más —`Alumno`, `Acudiente`, `Profesor`,
	 * `Usuario`—, y los doce roles de `roles` no incluyen cafetería, aseo ni
	 * mantenimiento. `profesores.tipo_profesor` es la dedicación
	 * (`Catedrático`/`Tiempo completo`, y nula en 42 de 47 filas) y `contratos`
	 * sólo dice `(profesor_id, year_id)`.
	 *
	 * O sea que **el personal de apoyo no se puede consultar**: si tiene cuenta,
	 * es indistinguible de secretaría. Por eso `votan_administrativos` es uno
	 * solo y significa *el personal con cuenta que no es docente*. El porqué
	 * entero está en
	 * `database/migrations/2026_09_22_300000_la_configuracion_de_la_votacion.php`.
	 */

	public const ESTAMENTO_ESTUDIANTE      = 'estudiante';
	public const ESTAMENTO_ACUDIENTE       = 'acudiente';
	public const ESTAMENTO_DOCENTE         = 'docente';
	public const ESTAMENTO_ADMINISTRATIVO  = 'administrativo';

	/** Qué columna de `vt_votaciones` enciende a cada estamento. */
	public const FLAG_DEL_ESTAMENTO = [
		self::ESTAMENTO_ESTUDIANTE     => 'votan_estudiantes',
		self::ESTAMENTO_ACUDIENTE      => 'votan_acudientes',
		self::ESTAMENTO_DOCENTE        => 'votan_profes',
		self::ESTAMENTO_ADMINISTRATIVO => 'votan_administrativos',
	];

	/**
	 * Los estados de matrícula que NO votan.
	 *
	 * Es una **lista negra y eso es la decisión**: retirado y desertor no votan,
	 * y cualquier otro sí —`MATR`, `ASIS`, `PREM`, `PREA`, `FORM` y **el estado
	 * que el colegio invente el año que viene**—.
	 *
	 * La lista blanca es lo que hacía el código viejo (`m.estado="MATR" or
	 * m.estado="ASIS" or m.estado="PREM"`, repetido en cinco consultas y con
	 * `PREA` olvidado en todas ellas) y es justo el fallo que describe
	 * `App\Support\EstadosDeMatricula`: **un alumno en un estado que ninguna
	 * consulta nombra desaparece de las listas sin dar error**. Aquí eso
	 * significaría no poder votar y no enterarse por qué.
	 */
	public const ESTADOS_QUE_NO_VOTAN = ['RETI', 'DESE'];


	public static function actual($user, $admin=0)
	{
		if ($admin) {

			$consulta = 'SELECT * FROM vt_votaciones v WHERE v.id=:votacion_id and v.deleted_at is null ';
			$votaciones = DB::select($consulta, [ 'votacion_id' => $admin ] );

		}else{

			$consulta = 'SELECT * FROM vt_votaciones v WHERE v.user_id=:user_id and v.year_id=:year_id and v.actual=1 and v.deleted_at is null ';
			$votaciones = DB::select($consulta, [ 'user_id' => $user->user_id, 'year_id' => $user->year_id ] );

		}
		if(count($votaciones) > 0){
			return VtVotacion::sinElHash($votaciones[0]);
		}
		return [];
	}

	public static function actualInAction($user)
	{
		return VtVotacion::where('actual', true)
					->where('user_id', $user->user_id)
					->where('in_action', true)
					->where('year_id', $user->year_id)
					->first();
	}

	/**
	 * **El censo, y ya no es una lista de inscritos.**
	 *
	 * > Vota quien esté en un grupo vivo del año de la votación.
	 *
	 * O sea: matrícula no borrada, con `estado` que no sea `RETI` ni `DESE`, en
	 * un grupo del año de **la votación** que no esté marcado `participa = 0` en
	 * `vt_grupos_votacion`.
	 *
	 * ## Lo que esto sustituye, y por qué el cambio de signo importa
	 *
	 * Antes el censo era `vt_participantes`: **una elección no arrancaba hasta
	 * que alguien inscribía los veinte grupos uno a uno**, y el grupo que se
	 * olvidaba no votaba sin que nadie se enterara —el sistema no distingue «no
	 * inscrito» de «inscrito y sin votar»—. Ahora el olvido tiene el signo
	 * correcto: **quien no se toca, vota**, y `vt_grupos_votacion` sólo guarda
	 * las excepciones.
	 *
	 * ## EL AÑO ES EL DE LA VOTACIÓN, no el del usuario
	 *
	 * `$votacion->year_id`, no `$user->year_id`. Son dos años distintos y el
	 * login mueve el segundo: un profesor que se pasa a 2025 para mirar un
	 * boletín no puede cambiar quién vota en la elección de 2026. Una votación
	 * sin `year_id` **no tiene censo** y esto devuelve vacío en vez de inventarse
	 * un año — medido el 22 sep 2026: cero filas así en `simonbolivar`.
	 *
	 * ## UNA FILA POR ALUMNO, garantizada aquí
	 *
	 * `matriculas` no tiene clave única sobre (alumno, año) y hay casos reales de
	 * dos vivas —ver el docblock de `Matricula::FILTRO_DEL_ANIO`—. Sin
	 * desempatar, ese alumno saldría dos veces y **el censo diría un votante de
	 * más**. Se aplica la misma regla que ya usa el resto del proyecto: la viva
	 * más reciente, con `MAX(id)` porque `created_at` es anulable.
	 *
	 * Y el desempate va **antes** del filtro de estado: si la última es `RETI`,
	 * el alumno está retirado aunque tenga una `MATR` más vieja debajo.
	 *
	 * ## QUIEN NO TIENE CUENTA NO ESTÁ EN EL CENSO
	 *
	 * Se vota con un usuario, así que el `INNER JOIN users` es parte de la
	 * definición y no un filtro de conveniencia. Medido: **4 de 1.246** alumnos
	 * vivos de `simonbolivar` no tienen `user_id`. Quien quiera la lista de
	 * matriculados para otra cosa, ésa no es esta consulta.
	 *
	 * @param  object|array  $votacion  la fila de `vt_votaciones` (modelo o `stdClass`)
	 * @return array<int, object>
	 */
	public static function censo($votacion)
	{
		$votacion_id = is_array($votacion) ? ($votacion['id'] ?? null) : ($votacion->id ?? null);
		$year_id     = is_array($votacion) ? ($votacion['year_id'] ?? null) : ($votacion->year_id ?? null);

		if (! $votacion_id || ! $year_id) {
			return [];
		}

		$consulta = 'SELECT u.id as user_id, a.id as alumno_id, a.nombres, a.apellidos,
					m.id as matricula_id, m.estado, m.grupo_id,
					g.nombre as grupo_nombre, g.abrev as grupo_abrev, g.orden as grupo_orden
				FROM matriculas m
				INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
				INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
				INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL
				LEFT JOIN vt_grupos_votacion vg ON vg.votacion_id = ? AND vg.grupo_id = g.id
				WHERE m.deleted_at IS NULL
				  AND m.estado NOT IN ('.self::comodinesDeEstado().')
				  AND (vg.id IS NULL OR vg.participa = 1)
				  AND m.id = (SELECT MAX(m2.id)
								FROM matriculas m2
								INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.year_id = ? AND g2.deleted_at IS NULL
							   WHERE m2.alumno_id = m.alumno_id AND m2.deleted_at IS NULL)
				ORDER BY g.orden, g.nombre, a.apellidos, a.nombres';

		$datos = array_merge([$year_id, $votacion_id], self::ESTADOS_QUE_NO_VOTAN, [$year_id]);

		return DB::select($consulta, $datos);
	}

	/**
	 * Si **este** usuario está en el censo de estudiantes de esta elección.
	 *
	 * Devuelve la fila —con su `grupo_id`— o `null`. Es la misma regla que
	 * `censo()` acotada a una persona, y existe aparte porque `votos/store` la
	 * pregunta una vez por voto: traerse las mil filas del censo para buscar una
	 * es la forma del bucle de la
	 * [11 §6.2](../../docs/migracion/11-votaciones.md).
	 */
	public static function estaEnElCenso($votacion, $user_id)
	{
		$votacion_id = is_array($votacion) ? ($votacion['id'] ?? null) : ($votacion->id ?? null);
		$year_id     = is_array($votacion) ? ($votacion['year_id'] ?? null) : ($votacion->year_id ?? null);

		if (! $votacion_id || ! $year_id || ! $user_id) {
			return null;
		}

		$consulta = 'SELECT m.grupo_id, m.estado, a.id as alumno_id
				FROM matriculas m
				INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
				INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
				INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL AND u.id = ?
				LEFT JOIN vt_grupos_votacion vg ON vg.votacion_id = ? AND vg.grupo_id = g.id
				WHERE m.deleted_at IS NULL
				  AND m.estado NOT IN ('.self::comodinesDeEstado().')
				  AND (vg.id IS NULL OR vg.participa = 1)
				  AND m.id = (SELECT MAX(m2.id)
								FROM matriculas m2
								INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.year_id = ? AND g2.deleted_at IS NULL
							   WHERE m2.alumno_id = m.alumno_id AND m2.deleted_at IS NULL)
				LIMIT 1';

		$datos = array_merge([$year_id, $user_id, $votacion_id], self::ESTADOS_QUE_NO_VOTAN, [$year_id]);

		return DB::selectOne($consulta, $datos);
	}

	/**
	 * El `?, ?` del `NOT IN` de los dos de arriba.
	 *
	 * Se genera de la constante y no se escribe a mano para que **añadir un
	 * estado a la lista no deje la consulta con un hueco**: es la trampa de
	 * `EstadosDeMatricula` por el otro lado, y aquí el síntoma sería un alumno
	 * retirado votando.
	 */
	private static function comodinesDeEstado()
	{
		return implode(', ', array_fill(0, count(self::ESTADOS_QUE_NO_VOTAN), '?'));
	}

	/**
	 * A qué estamento pertenece quien pregunta.
	 *
	 * Se lee de `users.tipo`, que es la **única** columna de estamento que hay
	 * —no existe `users.rol` ni `users.rol_id`—. Todo lo que no es alumno,
	 * acudiente ni docente cae en `administrativo`, y eso incluye al personal de
	 * apoyo con cuenta: la base no los separa. Ver la nota de arriba.
	 */
	public static function estamentoDe($user)
	{
		switch ($user->tipo ?? '') {
			case 'Alumno':    return self::ESTAMENTO_ESTUDIANTE;
			case 'Acudiente': return self::ESTAMENTO_ACUDIENTE;
			case 'Profesor':  return self::ESTAMENTO_DOCENTE;
			default:          return self::ESTAMENTO_ADMINISTRATIVO;
		}
	}

	/**
	 * Si el estamento de este usuario está encendido en esta elección.
	 *
	 * **No es el censo**: contesta «¿vota gente como ésta?», no «¿vota ésta?».
	 * Para un estudiante hacen falta las dos, y por eso `actualesInscrito()` las
	 * encadena.
	 */
	public static function admiteA($votacion, $user)
	{
		$flag = self::FLAG_DEL_ESTAMENTO[self::estamentoDe($user)];

		$valor = is_array($votacion) ? ($votacion[$flag] ?? null) : ($votacion->$flag ?? null);

		return (bool) $valor;
	}

	/**
	 * Las elecciones que este usuario puede votar ahora mismo.
	 *
	 * Es la consulta de la **pantalla de votar** (`votaciones/en-accion-inscrito`)
	 * y la única del módulo que es global: no se acota por dueño, y acotarla
	 * apagaría la votación general del colegio, que es la que funciona
	 * ([11 §5.4](../../docs/migracion/11-votaciones.md)).
	 *
	 * ## QUÉ CAMBIA respecto de la versión vieja
	 *
	 * Leía `vt_participantes` —una consulta por votación y otra por fila del
	 * censo— y sólo sabía de dos estamentos: profesores por `votan_profes`, y
	 * alumnos por estar su grupo inscrito. **Un acudiente nunca entraba, aunque
	 * `votan_acudientes` exista desde 2014.**
	 *
	 * Ahora son dos preguntas encadenadas y las mismas para todos: ¿está
	 * encendido mi estamento (`admiteA`), y —si soy estudiante— estoy en el censo
	 * (`estaEnElCenso`)?
	 *
	 * Se conserva el contrato con el frontend: devuelve un **array** de filas de
	 * `vt_votaciones`, y a las del estudiante les cuelga `grupo_id`, que es lo
	 * que la pantalla ya leía.
	 */
	public static function actualesInscrito($user, $in_action=true)
	{
		if ($in_action) {
			$consulta = 'SELECT * FROM vt_votaciones v WHERE actual=true and in_action=true and v.deleted_at is null ';
		}else{
			$consulta = 'SELECT * FROM vt_votaciones v WHERE in_action=true and v.deleted_at is null ';
		}

		$votaciones     = DB::select($consulta);
		$votaciones_res = [];

		foreach ($votaciones as $fila) {
			VtVotacion::sinElHash($fila);
		}

		$estamento = VtVotacion::estamentoDe($user);

		foreach ($votaciones as $votacion) {

			if (! VtVotacion::admiteA($votacion, $user)) {
				continue;
			}

			// El personal y las familias no tienen censo: les basta el flag.
			if ($estamento !== VtVotacion::ESTAMENTO_ESTUDIANTE) {
				$votaciones_res[] = $votacion;
				continue;
			}

			$fila = VtVotacion::estaEnElCenso($votacion, $user->user_id);

			if ($fila) {
				// Lo que la pantalla ya leía de aquí. Antes salía de
				// `vt_participantes.grupo_profes_acudientes`; ahora de la matrícula.
				$votacion->grupo_id = $fila->grupo_id;
				$votaciones_res[]   = $votacion;
			}
		}

		return $votaciones_res;
	}




	/**
	 * Si este usuario ya votó todos los cargos de esta elección.
	 *
	 * Eran **dos consultas** —una por los votos a candidato y otra por los
	 * blancos, que vivían en `blanco_aspiracion_id`— sumadas a mano. Con
	 * `vt_votos.votacion_id` y `vt_votos.aspiracion_id` en la propia fila es una
	 * sola, y el voto en blanco deja de ser un caso aparte: es `candidato_id`
	 * nulo.
	 *
	 * Se cuenta **por cargos distintos** y no por filas: el índice único
	 * `vt_votos_un_voto_por_cargo` ya impide que haya dos del mismo, pero contar
	 * `DISTINCT` deja la respuesta correcta aunque el índice desapareciera, que
	 * es la propiedad que este método tiene que dar.
	 */
	public static function verificarVotosCompletos($aspiraciones, $votacion_id, $user_id)
	{
		$cons = 'SELECT COUNT(DISTINCT vv.aspiracion_id) as votados
			FROM vt_votos vv
			WHERE vv.votacion_id = :votacion_id AND vv.user_id = :user_id';

		$fila = DB::selectOne($cons, ['votacion_id' => $votacion_id, 'user_id' => $user_id]);

		$cantVotados = $fila ? (int) $fila->votados : 0;

		return $cantVotados >= count($aspiraciones);
	}


	/** Las excepciones de grupo de esta elección. Sin filas, participan todos. */
	public function gruposVotacion()
	{
		return $this->hasMany(VtGrupoVotacion::class, 'votacion_id');
	}

	/** Las mesas de esta elección. */
	public function mesas()
	{
		return $this->hasMany(VtMesa::class, 'votacion_id');
	}

	/** Las actas de papel de esta elección. */
	public function actas()
	{
		return $this->hasMany(VtActa::class, 'votacion_id');
	}

	/**
	 * Los cargos a los que se aspira.
	 *
	 * Se llama `cargos()` y no `aspiraciones()` **a propósito**: el código del
	 * módulo le cuelga al modelo un atributo `aspiraciones` en tiempo de
	 * ejecución —está anotado arriba y sale en el JSON—, y una relación con ese
	 * nombre se lo pisaría. Es la misma familia de trampa que
	 * `vt_participantes.grupo_profes_acudientes`: un nombre que ya significa otra
	 * cosa.
	 */
	public function cargos()
	{
		return $this->hasMany(VtAspiracion::class, 'votacion_id');
	}


	/**
	 * La elección que este usuario puede administrar, o el código de estado que toca.
	 *
	 * **404 si no existe, 403 si no es suya.** Hasta hoy los seis `set-*` hacían
	 * `where('id', $id)->update(...)` con el id que viniera en el cuerpo y sin
	 * mirar nada, así que cualquiera de los 51 docentes con `auth.personal` abría
	 * el candado, destapaba los resultados o ponía como actual **la elección de
	 * otro** ([11 §5](../../docs/migracion/11-votaciones.md)).
	 *
	 * ## QUIÉN LA ADMINISTRA: el que la creó
	 *
	 * Contestado por Joseth el 21 ago 2026 y recogido en la
	 * [11 §5.4](../../docs/migracion/11-votaciones.md): *«una por profesor»* —y ahí
	 * mismo el aviso de que **eso vale para quién administra, no para quién
	 * vota**—. Por eso este guard es de las pantallas de configuración y
	 * `actualesInscrito()` sigue sin acotarse: acotarla apagaría la votación
	 * general del colegio, que es la que funciona.
	 *
	 * El superusuario entra a todas. Es lo que `getIndex()` ya hacía a mano con
	 * `$user->user_id == 1`, escrito con el criterio que el resto del proyecto usa
	 * —`Autoriza::esSuperusuario()`— en vez de con el número 1, que en un colegio
	 * cualquiera no tiene por qué ser el administrador.
	 *
	 * ## Y NO exige que el año de la elección sea el del usuario
	 *
	 * Son dos años distintos y el login mueve el segundo: un docente que se pasa a
	 * 2025 para mirar un boletín no deja de ser el dueño de su elección de 2026.
	 * Lo que sí se acota por año es **a quién apaga** el `UPDATE` masivo de
	 * `set-actual` y `set-in-action`, que es otro problema y se arregla en el
	 * controlador.
	 */
	public static function exigirAdministrable($votacion_id, $user)
	{
		$id = filter_var($votacion_id, FILTER_VALIDATE_INT);

		if ($id === false || $id < 1) {
			abort(422, 'La elección no es válida.');
		}

		// `find()` lleva el scope de SoftDeletes puesto: una elección en la
		// papelera es un 404 y no una fila que se pueda seguir moviendo, que es
		// justo lo que hacían los dos interruptores de SQL crudo (11 §5.1).
		$votacion = self::find($id);

		if (! $votacion) {
			abort(404, 'Esa elección no existe.');
		}

		if (! self::laAdministra($votacion, $user)) {
			abort(403, 'Esa elección es de otra persona.');
		}

		return $votacion;
	}

	/** Si este usuario manda en esta elección. Ver `exigirAdministrable()`. */
	public static function laAdministra($votacion, $user)
	{
		if (Autoriza::esSuperusuario($user)) {
			return true;
		}

		$duenio = is_array($votacion) ? ($votacion['user_id'] ?? null) : ($votacion->user_id ?? null);

		return $duenio !== null && (int) $duenio === (int) ($user->user_id ?? 0);
	}

	/**
	 * ─────────────────────────────────────────────────────────────────────────
	 *  QUIÉN PUBLICA EL RECUENTO — y por lo tanto quién lo ve antes
	 * ─────────────────────────────────────────────────────────────────────────
	 *
	 * Decisión de Joseth, **23 sep 2026**: *«nadie puede ver los resultados hasta
	 * que sea permitido; el coordinador o superuser decide cuándo»*. Al preguntarle
	 * quién es quién contestó las dos mitades que hacen que esto sea **un solo
	 * predicado y no dos**:
	 *
	 *   1. *«Antes de publicar, el recuento lo ve exactamente quien puede
	 *      publicarlo. Nadie más, ni el docente ni la secretaria.»*
	 *   2. *Puede publicar: el superusuario, rectoría, coordinación, **y quien creó
	 *      la elección**, aunque sea un docente.*
	 *
	 * O sea: **ver antes = poder publicar**. Se pregunta en cuatro sitios —los tres
	 * del recuento (`resultados/{id}`, `votaciones/en-accion-inscrito`,
	 * `votos/show`) y el guard del interruptor `set-permiso-ver-results`— y si
	 * alguna vez dejan de contestar lo mismo, la fuga vuelve por la puerta que se
	 * quede corta, que es exactamente lo que pasó entre el §1 y el §9 de la
	 * [11](../../docs/migracion/11-votaciones.md).
	 *
	 * ## ESTO DEJA SIN VIGENCIA EL CRITERIO DEL §9, y no por un arreglo
	 *
	 * Hasta esta mañana los tres sitios decían `$esPersonal || can_see_results`, con
	 * `$esPersonal` = *todo el que no es Alumno ni Acudiente*, y el §9 lo había
	 * escrito como «la mitad de la regla»: *al personal del colegio se le da
	 * siempre*. **Era más ancho de lo que el colegio quería**: un docente de
	 * matemáticas, la enfermera y la secretaria veían el escrutinio en vivo sin que
	 * nadie lo publicara. No se cambió porque estuviera roto, se cambió porque el
	 * dueño del producto dijo otra cosa.
	 *
	 * ## Y el movimiento de `votos/show` es EN SENTIDO CONTRARIO
	 *
	 * Allí el §1 había dejado `can_see_results` **a secas, sin excepción para
	 * nadie**, así que coordinación no podía mirar su propio tarjetón con números
	 * antes de publicar. Con este predicado entra. La misma frase de Joseth cierra
	 * dos puertas y abre una tercera, y por eso el diff no es «quitar `$esPersonal`».
	 *
	 * ## Qué hace falta en `$votacion`
	 *
	 * Sólo `user_id`, y acepta el array o la fila. Ojo con las lecturas que nombran
	 * columnas: `VtResultadosController::getShow()` tenía un `SELECT` con la lista
	 * escrita y **sin `user_id`**, así que el dueño de la elección no se habría
	 * reconocido a sí mismo — y sin fallar nada, que es lo peor que puede pasar aquí.
	 */
	public static function puedePublicarResultados($votacion, $user)
	{
		// Superusuario y dueño. El primero ya lo resuelve `laAdministra()`.
		if (self::laAdministra($votacion, $user)) {
			return true;
		}

		return Autoriza::puedePublicarCualquierVotacion($user);
	}

	/**
	 * Como `exigirAdministrable()`, pero para publicar el recuento: **rectoría y
	 * coordinación entran en una elección que no crearon ellos**, que es la parte de
	 * la decisión del 23 sep que el guard de los interruptores no daba.
	 *
	 * Se queda aparte y no se funde con `exigirAdministrable()` porque son dos
	 * conjuntos distintos a propósito: borrar la elección, cambiarle el censo o
	 * encender `locked` sigue siendo del dueño, y sólo **publicar** se ensancha. Los
	 * tres códigos son los de su hermana —422 si el id no es un id, 404 si no existe
	 * o está en la papelera, 403 si no le toca—, y el mensaje del 403 es otro porque
	 * la razón es otra: no es que la elección sea de otra persona, es que publicar no
	 * es de cualquiera.
	 */
	public static function exigirPublicable($votacion_id, $user)
	{
		$id = filter_var($votacion_id, FILTER_VALIDATE_INT);

		if ($id === false || $id < 1) {
			abort(422, 'La elección no es válida.');
		}

		$votacion = self::find($id);

		if (! $votacion) {
			abort(404, 'Esa elección no existe.');
		}

		if (! self::puedePublicarResultados($votacion, $user)) {
			abort(403, 'Publicar los resultados le toca a rectoría, a coordinación o a quien creó la elección.');
		}

		return $votacion;
	}

	/**
	 * Que se pueda votar en esta elección ahora mismo, o el código que toca.
	 *
	 * Acepta **el id** de la elección —la busca, y una que no existe o está en la
	 * papelera es un **422**— o **la fila ya cargada**, que es lo que tiene
	 * `VtMesasController` después de `laMesaQueConduzco()`. Devuelve la fila, que
	 * es lo que `votos/store` necesita a continuación.
	 *
	 * Lo que se exige, en este orden y todo con **423**: `locked` apagado —«si
	 * está lock, nadie puede votar», Joseth, 21 ago 2026—, `in_action` encendido
	 * (ver el aviso de más abajo) y el día de hoy dentro de la ventana.
	 *
	 * # DE DÓNDE SALE: dos copias escritas a sabiendas, juntadas el 22 sep 2026
	 *
	 * El rediseño del módulo se hizo con tres sesiones en paralelo y esta
	 * comprobación salió dos veces, las dos con el comentario de que su sitio era
	 * este modelo:
	 *
	 *   - **`VtVotosController::laUrnaAbierta($votacion_id)`** — recibía el id,
	 *     hacía el `SELECT ... AND deleted_at IS NULL`, **422 «La votación no
	 *     existe»** si no había fila, después las cuatro señales, y **devolvía la
	 *     fila**, porque `postStore()` la usa entera.
	 *   - **`VtMesasController::exigirUrnaAbierta($votacion)`** — recibía la fila
	 *     ya cargada y hacía **sólo las cuatro señales**, sin devolver nada: su
	 *     422 —«La votación de esta mesa ya no existe»— lo da antes
	 *     `laMesaQueConduzco()`, que es quien hace ese mismo `SELECT`.
	 *
	 * Las cuatro señales eran **idénticas**: mismo orden, mismos mensajes y los
	 * mismos 423. La única diferencia real era la de arriba, así que **se quedó la
	 * de `VtVotosController`, que es la más estricta** —trae con ella la existencia
	 * y la papelera—, y aceptar también la fila ya cargada evita que las mesas
	 * repitan la consulta y les deja su propio 422, que dice algo más concreto.
	 * **Ningún código de estado cambió**: 422 la votación no existe, 423 la urna
	 * está pausada, apagada o fuera de fechas.
	 *
	 * # EL ORDEN DE LAS MESAS NO SE TOCÓ, y se nota en un caso
	 *
	 * `postAbrir()` mira **antes** si la mesa está apagada, y la clave de doble
	 * llave **después** de llamar aquí. Así que con la elección cerrada y una
	 * clave equivocada la respuesta es el 423 de la urna y no el 403 de la clave.
	 * Era así antes de juntar los dos métodos y sigue siendo así.
	 *
	 * # ⚠ `in_action` — EL `if` QUE HAY QUE BORRAR SI ÉL LO DICE
	 *
	 * La [11 §2.1](../../docs/migracion/11-votaciones.md) recoge de Joseth (21 ago
	 * 2026) que `in_action` **es un redirector del front, no un candado**, y que
	 * exigirlo al votar «habría apagado la votación por el menú». El encargo del
	 * 22 sep pidió exigirlo y hoy se exige, pero **eso sigue sin decidir** (11 §8,
	 * punto 1). Por eso vive en **un solo `if` y en un solo sitio**: el día que se
	 * revierta es borrar ese bloque y nada más, y las fechas siguen dando la
	 * ventana de verdad.
	 *
	 * # Y `Reloj::ahora()`, no `now()`
	 *
	 * `config/app.php` sigue en UTC, así que a las siete de la tarde de Bogotá
	 * `now()->toDateString()` ya es el día siguiente y cerraría la urna cinco horas
	 * antes de tiempo. Las dos fechas son `DATE` y no `DATETIME`: la comparación es
	 * por día y **los dos extremos entran**, o sea que una elección con `fecha_fin`
	 * hoy se vota hoy hasta la noche, que es lo que el colegio entiende al
	 * escribirla.
	 */
	public static function exigirUrnaAbierta($votacion)
	{
		// Un id: se busca. Una fila ya cargada: se respeta, y su 422 —si hacía
		// falta— lo dio quien la cargó.
		if (! is_object($votacion)) {
			$votacion = DB::selectOne('SELECT * FROM vt_votaciones WHERE id = ? AND deleted_at IS NULL', [$votacion]);
		}

		if (! $votacion) {
			abort(422, 'La votación no existe');
		}

		if ($votacion->locked) {
			abort(423, 'La votación está pausada');
		}

		/*
		 * ── `in_action`, y esto es lo que se quita ───────────────────────────
		 * Es el único sitio del backend que lo exige para votar. Ver el aviso del
		 * docblock: está sin decidir, y se revierte borrando este `if` entero.
		 */
		if (! $votacion->in_action) {
			abort(423, 'La votación no está abierta');
		}

		$hoy = Reloj::ahora()->toDateString();

		if ($votacion->fecha_inicio && $hoy < substr((string) $votacion->fecha_inicio, 0, 10)) {
			abort(423, 'La votación todavía no ha empezado');
		}

		if ($votacion->fecha_fin && $hoy > substr((string) $votacion->fecha_fin, 0, 10)) {
			abort(423, 'La votación ya cerró');
		}

		return $votacion;
	}

	/**
	 * El censo contado, por grupo y por estado de matrícula.
	 *
	 * Misma regla que `censo()` **menos una cosa, y la diferencia es el motivo de
	 * que exista**: aquí NO se filtra por `vt_grupos_votacion.participa`.
	 *
	 * La pantalla de configuración tiene que decir cuántos estudiantes tiene un
	 * grupo **también cuando está apagado** —si no, apagarlo lo deja en «0
	 * estudiantes» y volver a encenderlo parece que no hace nada—. Quien quiera el
	 * censo de verdad suma sólo los grupos que participan, que es lo que hace
	 * `VtCensoController`.
	 *
	 * Devuelve `(grupo_id, estado, cantidad)`. De ahí salen las tres cifras que
	 * pide la pantalla —el total del censo, cuántos por grupo y el desglose por
	 * estado— **con una consulta**, en vez de traerse las mil filas de `censo()`
	 * para contarlas en PHP.
	 *
	 * @param  object|array  $votacion
	 * @return array<int, object>
	 */
	public static function recuentoDelCensoPorGrupo($votacion)
	{
		$year_id = is_array($votacion) ? ($votacion['year_id'] ?? null) : ($votacion->year_id ?? null);

		if (! $year_id) {
			return [];
		}

		$consulta = 'SELECT m.grupo_id, m.estado, COUNT(*) as cantidad
				FROM matriculas m
				INNER JOIN grupos g ON g.id = m.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
				INNER JOIN alumnos a ON a.id = m.alumno_id AND a.deleted_at IS NULL
				INNER JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL
				WHERE m.deleted_at IS NULL
				  AND m.estado NOT IN ('.self::comodinesDeEstado().')
				  AND m.id = (SELECT MAX(m2.id)
								FROM matriculas m2
								INNER JOIN grupos g2 ON g2.id = m2.grupo_id AND g2.year_id = ? AND g2.deleted_at IS NULL
							   WHERE m2.alumno_id = m.alumno_id AND m2.deleted_at IS NULL)
				GROUP BY m.grupo_id, m.estado';

		$datos = array_merge([$year_id], self::ESTADOS_QUE_NO_VOTAN, [$year_id]);

		return DB::select($consulta, $datos);
	}

}
