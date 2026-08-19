<?php

namespace App;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Services\ContextoDeUsuario;
use App\Services\Sesion;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;

class User extends Authenticatable
{
    use Notifiable;
    use HasApiTokens;

    

	public static $nota_minima_aceptada = 0;
	public static $images = '';
	public static $perfilPath = '';
	public static $imgSharedPath = '';
	public static $intentoLogueoPorActive = 0;



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

		return app(ContextoDeUsuario::class)->para($userTemp);
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


    
	public static function pueden_editar_notas($user)
	{
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
		
		if ($user->tipo == 'Profesor' && $user->profes_pueden_editar_notas==0) {
			return abort(400, 'No tienes permiso');
		}else if(($user->is_superuser && $user->is_superuser) || $user->tipo == 'Profesor'){
			// todo bien
		}else{
			return abort(403, 'No tienes permiso.');
		}

	}

	
	public static function pueden_modificar_definitivas($user)
	{
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
		
		
		if ($user->tipo == 'Profesor' && $user->profes_pueden_nivelar==0) {
			return abort(400, 'No tienes permiso');
		}else if($user->is_superuser && $user->is_superuser){
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
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
