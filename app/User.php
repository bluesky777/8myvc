<?php

namespace App;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Models\TokenDeSesion;
use App\Services\ContextoDeUsuario;
use App\Services\Sesion;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
/**
 * Las columnas de `users`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $username
 * @property string $password
 * @property string $sexo
 * @property ?string $email
 * @property ?int $imagen_id
 * @property int $is_superuser
 * @property ?string $tipo
 * @property int $is_active
 * @property int $can_ask
 * @property ?int $periodo_id
 * @property ?int $profesor_id
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $remember_token
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */

class User extends Authenticatable
{
    use Notifiable;
    use HasApiTokens;

    

	public static $nota_minima_aceptada = 0;



	public function roles()
    {
      return $this->belongsToMany('App\Models\Role', 'role_user', 'user_id', 'role_id');
    }


    
	/**
	 * El contexto del usuario de la petición en curso.
	 *
	 * Resolverlo cuesta de 5 a 8 consultas: el usuario, el periodo, la consulta
	 * de 40 columnas con seis JOIN del switch de cuatro ramas, los roles, y una
	 * más por cada rol. Se hacía entero en cada llamada, y hay peticiones que
	 * llaman varias veces: el middleware auth.token una, el controlador otra al
	 * leer $this->user, y algunos métodos otra más por su cuenta.
	 *
	 * La memoria va en la propia petición, no en una estática: una estática
	 * sobreviviría entre peticiones dentro del mismo proceso y en los tests le
	 * daría a la segunda el usuario de la primera. El objeto Request es único
	 * por petición tanto en producción como en los tests.
	 *
	 * No se memoriza cuando se pide resolver un token concreto ($already_parsed),
	 * porque entonces no se está preguntando por el usuario de la petición.
	 */
	private const CONTEXTO = 'usuario.contexto';

	public static function fromToken($already_parsed=false, $request = false)
	{
		if ($already_parsed !== false) {
			return self::resolverContexto($already_parsed);
		}

		$peticion = Request::instance();

		if ($peticion->attributes->has(self::CONTEXTO)) {
			return $peticion->attributes->get(self::CONTEXTO);
		}

		$usuario = self::resolverContexto(false);

		// null no se guarda: significa que la resolución no llegó a terminar
		// —el caso del periodo de otro año, más abajo— y la siguiente llamada
		// tiene que volver a intentarlo.
		if ($usuario !== null) {
			$peticion->attributes->set(self::CONTEXTO, $usuario);
		}

		return $usuario;
	}

	/**
	 * Valida el token y monta el contexto, en ese orden y en dos sitios
	 * distintos.
	 *
	 * Esto eran 280 líneas: el parseo del JWT, el switch de cuatro ramas con
	 * las consultas de cuarenta columnas, y los roles y permisos, todo junto.
	 * Estaban juntos porque nadie los separó, no porque tuvieran que estarlo, y
	 * mientras lo estuvieron no se podía cambiar de mecanismo de autenticación
	 * sin tocar los 325 sitios que llaman aquí.
	 *
	 * Ahora el token lo valida App\Services\Sesion —que acepta los de Sanctum y
	 * también los JWT viejos mientras dure la transición— y el contexto lo monta
	 * App\Services\ContextoDeUsuario. Este método solo los junta.
	 *
	 * @param  string|false  $already_parsed  Un token concreto, en vez del de la petición.
	 */
	private static function resolverContexto($already_parsed=false)
	{
		$sesion = app(Sesion::class);

		$userTemp = $already_parsed !== false
			? $sesion->exigirDeToken($already_parsed)
			: $sesion->exigirUsuario(Request::instance());

		$contexto = app(ContextoDeUsuario::class)->para($userTemp);

		if ($contexto !== null) {
			self::atarLaSesion($contexto, $userTemp);
		}

		return $contexto;
	}

	/**
	 * Cuelga del contexto **de qué ingreso viene esta petición** — fase 2 de
	 * docs/migracion/18-auditoria.md.
	 *
	 * Sin esto, los nueve sitios que escriben `historial_id` lo resuelven con
	 * `order by id desc limit 1` sobre `historiales`, o sea **el último login de
	 * esa persona y no la sesión que está haciendo el cambio**. Con el refresco
	 * viviendo catorce días y rotando en cada uso, eso puede ser un ingreso de
	 * hace meses.
	 *
	 * **No cuesta ninguna consulta**: `Sesion::resolverDeVerdad()` devuelve el
	 * usuario con `withAccessToken($token)`, así que la fila del token ya está
	 * resuelta y `historial_id` viene dentro.
	 *
	 * ## Por qué `sesion_id` e `historial_id` llevan hoy el mismo número
	 *
	 * `auditoria` tiene las dos columnas y son dos preguntas distintas: el
	 * **ingreso** (la fila de `historiales`) y la **sesión** (la familia de tokens
	 * que sobrevive a las rotaciones). **Hoy son 1:1** — cada login crea una fila
	 * de `historiales` y una familia de tokens, y la familia arrastra ese id en
	 * cada rotación—, así que el mismo número contesta las dos.
	 *
	 * Se escriben las dos y no una porque el índice `aud_sesion (sesion_id, id)`
	 * está **medido con EXPLAIN** para la pregunta «qué hizo en este ingreso»
	 * (aud-3 §3): dejar `sesion_id` en NULL dejaría esa pantalla sin índice, y
	 * además `Auditoria` sólo pone `atribucion = 'sesion'` cuando `sesion_id`
	 * viene (18 §5.2) — sin él, todas las líneas seguirían diciendo «aproximada»
	 * teniendo la atribución cierta.
	 *
	 * **El día que dejen de ser 1:1** —una sesión que sobreviva a varios ingresos,
	 * o al revés— esto es la única línea que cambia. Está aquí y no repartido por
	 * los nueve sitios justamente para que sea una línea.
	 */
	private static function atarLaSesion(object $contexto, self $userTemp): void
	{
		// `currentAccessToken()` no consulta nada: `Sesion::resolverDeVerdad()`
		// devuelve el usuario con `withAccessToken($token)`, así que la fila ya
		// está resuelta y `historial_id` viene dentro. Es null por el camino de
		// consola y por el del token que no es de Sanctum.
		// `instanceof` y no `!== null`: `currentAccessToken()` promete devolver un
		// `PersonalAccessToken` de Sanctum, pero **`historial_id` es de nuestra
		// subclase** — y en el camino de consola no hay token en absoluto, aunque
		// su firma diga que sí. Las dos cosas se comprueban de una vez.
		$token = $userTemp->currentAccessToken();

		$ingreso = $token instanceof TokenDeSesion && $token->historial_id !== null
			? (int) $token->historial_id
			: null;

		// NULL cuando el token es anterior a la migración de la fase 2, y NULL se
		// queda: `Auditoria` lo lee como «no se sabe» y escribe
		// `atribucion = 'aproximada'`. **No se adivina**, que es de lo que se
		// viene.
		$contexto->historial_id = $ingreso;
		$contexto->sesion_id = $ingreso;
	}

	// Todos los permisos de un usuario, con el objeto permiso, o solo con el string name del permiso
	public function permissions($detailed=false)
	{
		$perms = [];

		foreach( $this->roles()->get() as $role )
		{
			$permisos = $role->permissions($detailed);
			// No quiero un array con multiples arrays dentro que contengan los permisos
			// así que recorro cada array con permisos y voy agregando cada elemento permiso al array $perms donde estarán unidos.
			foreach ($permisos as $value) {
				array_push($perms, $value);
			}
		}

		return $perms;
	}


    
	/**
	 * Deja en `$user` las dos banderas del periodo que toca comprobar.
	 *
	 * Con `$periodo` se leen las del periodo de la fila que se escribe. Sin él se
	 * conserva **letra por letra** lo que había antes —`num_periodo` del cuerpo, o
	 * el del usuario si no viene, y si ningún periodo coincide no se toca nada y
	 * quedan las que trajo el contexto—, porque ese camino sigue vivo en las dos
	 * llamadas que no tienen fila de la que derivar.
	 *
	 * Con varias filas se cruzan con AND: basta que una esté en periodo cerrado
	 * para que la petición entera no pase.
	 *
	 * El periodo se lee **por id y sin filtrar año**, y es a propósito: el candado
	 * es por (año, periodo) —el periodo 1 de un año puede estar bloqueado con el 1
	 * del siguiente abierto—, así que la bandera que vale es la de la fila, viva
	 * en el año que viva. Ver 05 §27.4, donde Joseth lo confirmó el 21 ago 2026
	 * frente a la alternativa de exigir además `years.actual`.
	 *
	 * @param  int|array<int>|null  $periodo
	 */
	private static function aplicarBanderasDelPeriodo($user, int|array|null $periodo): void
	{
		// **Los ids se deduplican aquí y no en el llamante**, y no es cosmético: la
		// decisión de abajo es `count($filas) === count($ids)`, así que una lista
		// con el mismo periodo repetido da N ids contra **una** fila y deniega la
		// petición entera. El caso que lo dispara es el normal —`notas/lote` manda
		// una columna, o sea treinta notas del mismo periodo—, y el 400 que sale es
		// «no tienes permiso», que manda a buscar el fallo justo donde no está.
		//
		// Exigirle `array_unique` al que llama es un contrato que se rompe con el
		// primero que no lea el comentario, y el reordenado de subunidades ya pasa
		// varias filas por aquí.
		$ids = array_values(array_unique(array_filter(
			is_array($periodo) ? $periodo : [$periodo], fn ($p) => $p !== null
		)));
		
		if ($ids !== []) {
			$filas = DB::select(
				'SELECT profes_pueden_editar_notas, profes_pueden_nivelar FROM periodos
				 WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND deleted_at IS NULL',
				$ids
			);
			
			// Un id que no resuelve a ninguna fila es un periodo borrado debajo de
			// una fila que sigue apuntándolo. No se inventa permiso: cuenta como
			// cerrado, igual que si la bandera estuviera en 0.
			$editar  = count($filas) === count($ids);
			$nivelar = $editar;
			
			foreach ($filas as $fila) {
				$editar  = $editar  && (bool) $fila->profes_pueden_editar_notas;
				$nivelar = $nivelar && (bool) $fila->profes_pueden_nivelar;
			}
			
			$user->profes_pueden_editar_notas = $editar ? 1 : 0;
			$user->profes_pueden_nivelar      = $nivelar ? 1 : 0;
			
			return;
		}
		
		$periodos = DB::select('SELECT * FROM periodos p WHERE p.deleted_at is null and p.year_id=?', [$user->year_id]);
		
		$num_periodo = (int)Request::input('num_periodo');
		
		if ($num_periodo) {
			# Todo bien
		}else{
			$num_periodo = (int)$user->numero_periodo;
		}
		
		$cant_p = count($periodos);
		
		for ($i=0; $i < $cant_p; $i++) { 
			if ($periodos[$i]->numero == $num_periodo){
				$user->profes_pueden_nivelar 		= $periodos[$i]->profes_pueden_nivelar;
				$user->profes_pueden_editar_notas 	= $periodos[$i]->profes_pueden_editar_notas;
			}
		}
	}
	
	
	/**
	 * El interruptor con el que el colegio le cierra un periodo a los profesores.
	 *
	 * `$periodo` es **el periodo de la fila que esta petición va a escribir**, y
	 * es lo que arregla la §27: antes se comprobaba la bandera del periodo que
	 * nombraba el cuerpo (`num_periodo`) y la escritura iba a otro sitio, así que
	 * el candado se abría nombrando el periodo de al lado. Lo deriva
	 * `App\Support\PeriodoDeLaFila`, un método por cada forma que tiene una
	 * llamada de saber a qué fila le toca.
	 *
	 * Acepta un id, una lista de ids —hay llamadas que tocan varias filas de
	 * golpe, como el reordenado de subunidades— o `null`. **Con lista, tienen que
	 * pasar todos**: si una de las filas está en un periodo cerrado, la petición
	 * entera no va, porque escribir la mitad de un reordenado es peor que no
	 * escribir nada.
	 *
	 * `null` es «no se pudo derivar», no «adelante»: se vuelve al comportamiento
	 * de antes, que es leer `num_periodo`. Pasa en las dos de `recuperacion_final`
	 * y por una razón de esquema —esa tabla se guarda por año y no tiene
	 * `periodo_id`—, no por descuido.
	 *
	 * @param  int|array<int>|null  $periodo
	 */
	/**
	 * Lo mismo que `pueden_editar_notas()` pero contestando en vez de abortar.
	 *
	 * Hace falta para las rutas que **leen y de paso escriben**: no se les puede
	 * poner el `abort()` delante porque apagaría la lectura, pero tampoco pueden
	 * escribir en un periodo cerrado. La primera es
	 * `unidades/de-asignatura-periodo`, que es la pantalla con la que el profesor
	 * mira la rejilla — y decidió Joseth que con el periodo cerrado **enseñe lo
	 * que hay y no cree nada** (05 §47.2).
	 *
	 * Repite la forma del de arriba en vez de compartirla a propósito: aquél
	 * distingue 400 de 403 y ésta solo dice sí o no, así que unificarlos obligaría
	 * a que uno de los dos dejara de decir lo que dice hoy.
	 *
	 * @param  int|array<int>|null  $periodo
	 */
	public static function permiteEditarNotas($user, int|array|null $periodo = null): bool
	{
		self::aplicarBanderasDelPeriodo($user, $periodo);

		if ($user->tipo == 'Profesor' && $user->profes_pueden_editar_notas == 0) {
			return false;
		}

		return (bool) ($user->is_superuser ?? false) || $user->tipo == 'Profesor';
	}

	/**
	 * **Sólo la mitad del periodo**: ¿está abierto para lo que gobierna
	 * `profes_pueden_editar_notas`? Sin decidir nada sobre QUIÉN es el que llama.
	 *
	 * Es la diferencia con las dos de arriba, y es toda la razón de que exista.
	 * Aquéllas responden dos preguntas a la vez —«¿está abierto?» y «¿eres de los
	 * que escriben notas?»— y por eso su rama final **contesta 403 a cualquiera
	 * que no sea profesor ni superusuario**. Ponerlas en asistencias habría
	 * cerrado la puerta a la secretaría, que hoy pasa asistencia y no toca una
	 * nota en su vida: un efecto que nadie pidió, escondido dentro de un cambio
	 * que sí se pidió.
	 *
	 * Así que ésta comprueba **una cosa sola**, y quien la llama sigue decidiendo
	 * el resto de su autorización como la tenía. La condición es literalmente la
	 * primera rama de `pueden_editar_notas()`, con el mismo 400 —el front ya lo
	 * traduce— y con el mismo trato al profesor que además es superusuario: la
	 * bandera es del periodo, no del despacho.
	 *
	 * @param  int|array<int>|null  $periodo
	 */
	public static function exigirPeriodoAbiertoParaNotas($user, int|array|null $periodo = null): void
	{
		self::aplicarBanderasDelPeriodo($user, $periodo);

		if ($user->tipo == 'Profesor' && $user->profes_pueden_editar_notas == 0) {
			abort(400, 'El periodo está bloqueado y no se puede modificar.');
		}
	}

	public static function pueden_editar_notas($user, int|array|null $periodo = null)
	{
		self::aplicarBanderasDelPeriodo($user, $periodo);
		
		if ($user->tipo == 'Profesor' && $user->profes_pueden_editar_notas==0) {
			return abort(400, 'No tienes permiso');
		}else if(($user->is_superuser) || $user->tipo == 'Profesor'){
			// todo bien
		}else{
			return abort(403, 'No tienes permiso.');
		}

	}

	
	/**
	 * El otro interruptor, el de nivelar. Mismo criterio de periodo que el de
	 * arriba y por la misma razón; ver pueden_editar_notas().
	 *
	 * @param  int|array<int>|null  $periodo
	 */
	public static function pueden_modificar_definitivas($user, int|array|null $periodo = null)
	{
		self::aplicarBanderasDelPeriodo($user, $periodo);
		
		if ($user->tipo == 'Profesor' && $user->profes_pueden_nivelar==0) {
			return abort(400, 'No tienes permiso');
		}else if($user->is_superuser){
			// todo bien
		}else if($user->tipo == 'Profesor' && $user->profes_pueden_nivelar==1){
			// todo bien
		}else{
			return abort(400, 'No tienes permiso.');
		}

	}




    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
