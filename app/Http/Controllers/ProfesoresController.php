<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Profesor;
use App\Support\Autoriza;
use App\Support\CamposQueVinieron;
use App\Models\Role;
use App\Models\Year;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class ProfesoresController extends Controller {
	use ResuelveElUsuario;

	public function getIndex()
	{
		$consulta = 'SELECT p.id, p.nombres, p.apellidos, p.sexo, p.foto_id, p.tipo_doc,
					p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
					p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
					p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
					u.email as email_usu, u.imagen_id, u.is_superuser, u.is_active,
					c.id as contrato_id, c.year_id,
					p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				from profesores p
				left join users u on p.user_id=u.id and u.is_active=true
				left join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.deleted_at is null
				LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
				where p.deleted_at is null
				order by p.nombres, p.apellidos';

		$profesores = DB::select($consulta, array(':year_id'=>$this->user->year_id));
		return $profesores;
	}


	public function getTodos()
	{
		$consulta = 'SELECT p.id as profesor_id, p.nombres, p.apellidos, concat(p.nombres, " ", p.apellidos) as nombre_completo, p.sexo, p.foto_id, p.tipo_doc,
					p.num_doc, p.ciudad_doc, p.fecha_nac, p.ciudad_nac, p.titulo,
					p.estado_civil, p.barrio, p.direccion, p.telefono, p.celular,
					p.facebook, p.email, p.tipo_profesor, p.user_id, u.username,
					u.email as email_usu, u.imagen_id, u.is_superuser,
					c.id as contrato_id, c.year_id,
					p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.deleted_at is null
				left join users u on p.user_id=u.id and u.deleted_at is null
				LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
				where p.deleted_at is null
				order by p.nombres, p.apellidos';

		$profesores = DB::select($consulta);
		return $profesores;
	}


	public function putListado()
	{	
		$year 			= Year::datos_basicos($this->user->year_id);
		
		$consulta = 'SELECT p.*, c.id as contrato_id, ci.ciudad as ciudad_nac_nombre, ci.departamento as depart_nac_nombre, 
				ci2.ciudad as ciudad_doc_nombre, ci2.departamento as depart_doc_nombre, t.tipo as tipo_doc_nombre, t.abrev, u.username 
			FROM profesores p 
			INNER JOIN contratos c ON c.profesor_id=p.id and c.deleted_at is null 
			LEFT JOIN ciudades ci ON ci.id=p.ciudad_nac and ci.deleted_at is null 
			LEFT JOIN ciudades ci2 ON ci2.id=p.ciudad_doc and ci2.deleted_at is null 
			LEFT JOIN tipos_documentos t ON t.id=p.tipo_doc and t.deleted_at is null 
			LEFT JOIN users u ON u.id=p.user_id and u.deleted_at is null 
			WHERE p.deleted_at is null and c.year_id=?';
			
		$profesores = DB::select($consulta, [$this->user->year_id]);
		
		for ($i=0; $i < count($profesores); $i++) { 
			$grupos = DB::select('SELECT g.abrev, g.id, g.orden FROM grupos g WHERE g.deleted_at is null and g.titular_id=? and year_id=?', [$profesores[$i]->id, $this->user->year_id]);
			$profesores[$i]->grupos = '';
			
			$cant_g = count($grupos);
			
			for ($j=0; $j < $cant_g; $j++) { 
				$profesores[$i]->grupos .= $grupos[$j]->abrev;
				
				if (! isset($profesores[$i]->orden_grupo)) {
					$profesores[$i]->orden_grupo = $grupos[$j]->orden;
				}
				
				if ($j < ($cant_g-1)) {
					$profesores[$i]->grupos .= ',';
				}
			}
				
		}
			
		return [ 'year'=>$year, 'profesores'=>$profesores];
		
	}

	public function postStore()
	{

	
		$this->sanarInputProfesor();

		$profesor = new Profesor;
		$profesor->nombres		=	Request::input('nombres');
		$profesor->apellidos	=	Request::input('apellidos');
		$profesor->sexo			=	Request::input('sexo');
		$profesor->tipo_doc		=	Request::input('tipo_doc');
		$profesor->num_doc		=	Request::input('num_doc');
		$profesor->ciudad_doc	=	Request::input('ciudad_doc');
		$profesor->fecha_nac	=	Request::input('fecha_nac');
		$profesor->ciudad_nac	=	Request::input('ciudad_nac');
		$profesor->titulo		=	Request::input('titulo');
		$profesor->estado_civil	=	Request::input('estado_civil');
		$profesor->barrio		=	Request::input('barrio');
		$profesor->direccion	=	Request::input('direccion');
		$profesor->telefono		=	Request::input('telefono');
		$profesor->celular		=	Request::input('celular');
		$profesor->facebook		=	Request::input('facebook');
		$profesor->email		=	Request::input('email');
		$profesor->tipo_profesor	=	Request::input('tipo_profesor'); // Catedrático o Tiempo completo
		$profesor->save();
		

		$this->sanarInputUser();

		$this->checkOrChangeUsername($profesor->user_id);

		$usuario = new User;
		$usuario->username		=	Request::input('username');
		$usuario->password		=	Hash::make(Request::input('password', '123456'));
		$usuario->email			=	Request::input('email2');
		$usuario->is_superuser	=	Autoriza::concederSuperusuario($this->user, Request::input('is_superuser'));
		$usuario->is_active		=	Request::input('is_active', 1);
		$usuario->tipo			=	'Profesor';
		$usuario->save();


		$profesor->user_id = $usuario->id;
		
		$role = Role::where('name', 'Profesor')->get();
		$usuario->roles()->attach($role[0]['id']);

		$profesor->save();

		$profesor->user = $usuario;
		/*
		if (Request::input('grupo')['id']) {
			$grupo_id = Request::input('grupo')['id'];

			$matricula = new Matricula;
			$matricula->alumno_id	=	$profesor->id;
			$matricula->grupo_id	=	$grupo_id;
			$matricula->matriculado	=	true;
			$matricula->save();

			$grupo = Grupo::find($matricula->grupo_id);
			$profesor->grupo = $grupo;
		}
		*/

		return $profesor;
		
	}

	public function sanarInputUser()
	{
		/*
		//separamos el nombre de la img y la extensión
		$info = explode(".", $file->getClientOriginalName());
		$primer = $info[0];
		*/
		
		if (!Request::input('username')) {
			$dirtyName = Request::input('nombres');
			$name = preg_replace('/\s+/', '', $dirtyName);
			Request::merge(array('username' => $name));
		}

		if (!Request::input('email1')) {

			if (Request::input('email')) {
				Request::merge(array('email2' => Request::input('email') ));
			}else{
				$email = Request::input('username') . '@myvc.com';
				Request::merge(array('email2' => $email));
			}
		}

		if (!Request::input('is_superuser')) {

			Request::merge(array('is_superuser' => false));
			
		}

		if (Request::input('password')) {
			if (Request::input('password') == Request::input('password2')) {
				Request::merge(array('nuevo_password' => Request::input('password')));
			}
			
			
		}
	}

	/*************************************************************
	 * Guardar por VALOR
	 *************************************************************/
	/**
	 * Guardar una propiedad suelta de un profesor.
	 *
	 * El `if` envolvía TODO el cuerpo y no tenía `else`: quien no fuera
	 * superusuario recibía `['Guardado.']` **sin que se hubiera guardado nada**.
	 * Una respuesta que dice que sí cuando fue que no es peor que un error, porque
	 * el que la lee deja de mirar. Ver 05 §37.
	 *
	 * Y de las propiedades que acepta solo actúa sobre `is_active`; con cualquier
	 * otra responde igual y tampoco escribe. Eso se deja como está —es la forma
	 * del método, no un permiso— pero queda dicho.
	 */
	public function putGuardarValor()
	{
		Autoriza::exigir(Autoriza::esSuperusuario($this->user),
			'No tienes permiso para cambiar los datos de un profesor.');
		
		if($this->user->is_superuser){
			$valor 		= Request::input('valor');
			$user_id 	= Request::input('user_id');
			$persona_id 	= Request::input('persona_id');
			$propiedad 	= Request::input('propiedad');
			$now 		= Carbon::now('America/Bogota');

			if(Request::input('propiedad') == 'is_active'){
				$consulta 	= 'UPDATE users SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
				$datos 		= [ ':valor' => $valor, ':modificador' => $this->user->user_id, ':fecha' => $now, ':user_id' => $user_id ];
				$res 		= DB::update($consulta, $datos);
			}
		}
		return ['Guardado.'];
	}


	public function sanarInputProfesor(){
		if (is_array( Request::input('tipo_sangre') )){
			if (!array_key_exists('sangre', Request::input('tipo_sangre'))) {
				Request::merge(array('tipo_sangre' => array('sangre'=>'')));
			}
		}else{
			Request::merge(array('tipo_sangre' => array('sangre'=>'')));
		}

		if (Request::input('estado_civil')) {
			if (isset(Request::input('estado_civil')['estado_civil'])) {
				Request::merge(array('estado_civil' => Request::input('estado_civil')['estado_civil'] ) );
			}
		}else{
			Request::merge(array('estado_civil' => null) );
		}


		if (Request::has('ciudad_nac') && Request::input('ciudad_nac') != null) {
			Request::merge( ['ciudad_nac' => Request::input('ciudad_nac')['id'] ? Request::input('ciudad_nac')['id'] : null ] );
		}else{
			Request::merge(array('ciudad_nac' => null) );
		}

		if (Request::input('ciudad_doc') && Request::input('ciudad_doc') != null) {
			Request::merge( ['ciudad_doc' => Request::input('ciudad_doc')['id'] ? Request::input('ciudad_doc')['id'] : null ] );
		}else{
			Request::merge(array('ciudad_doc' => null) );
		}

	if (Request::input('tipo_doc') && Request::input('tipo_doc') != null) {
		if (is_array(Request::input('tipo_doc'))) {
			Request::merge( ['tipo_doc' => Request::input('tipo_doc')['id'] ? Request::input('tipo_doc')['id'] : null ] );
		} else {
			Request::merge( ['tipo_doc' => Request::input('tipo_doc') ] );
		}
	}else{
		Request::merge(array('tipo_doc' => null) );
	}

		if (Request::input('foto') && Request::input('foto') != null) {
			Request::merge( ['foto_id' => Request::input('foto')['id'] ? Request::input('foto')['id'] : null ] );
		}else{
			Request::merge(array('foto_id' => null) );
		}
	}



	public function getShow($id)
	{
		$profesor = Profesor::detallado($id);
		return array( $profesor );
	}



	/**
	 * Igual que `putGuardarValor`: el `if` abarcaba el método entero y no había
	 * `else`, así que un profesor que editaba a otro recibía **200 con el cuerpo
	 * vacío** y creía que se había guardado. Ver 05 §37.
	 */
	public function putUpdate($id)
	{
		Autoriza::exigir(Autoriza::esSuperusuario($this->user),
			'No tienes permiso para editar a un profesor.');
		
		if ($this->user->is_superuser) {
			// ANTES del primer `sanar*`: los dos hacen `Request::merge()`, así que a
			// partir de aquí `Request::has()` ya no distingue lo que mandó el cliente
			// de lo que se rellenó solo. Ver App\Support\CamposQueVinieron y 05 §68.
			$vinieron = CamposQueVinieron::capturar();

			$this->sanarInputUser();
			$this->sanarInputProfesor();

			
			$profesor = Profesor::findOrFail($id);
			try {
				$profesor->nombres		=	Request::input('nombres_profesor', Request::input('nombres'));
				$profesor->apellidos	=	Request::input('apellidos_profesor', Request::input('apellidos'));
				$profesor->sexo			=	Request::input('sexo');
				$profesor->tipo_doc		=	Request::input('tipo_doc');
				$profesor->num_doc		=	Request::input('num_doc');
				$profesor->ciudad_doc	=	Request::input('ciudad_doc');
				$profesor->fecha_nac	=	Request::input('fecha_nac');
				$profesor->ciudad_nac	=	Request::input('ciudad_nac');
				$profesor->titulo		=	Request::input('titulo');
				$profesor->estado_civil	=	Request::input('estado_civil');
				$profesor->barrio		=	Request::input('barrio');
				$profesor->direccion	=	Request::input('direccion');
				$profesor->telefono		=	Request::input('telefono');
				$profesor->celular		=	Request::input('celular');
				$profesor->facebook		=	Request::input('facebook');
				$profesor->email		=	Request::input('email_usu');
				$profesor->tipo_profesor	=	Request::input('tipo_profesor'); // Catedrático o Tiempo completo

				$profesor->save();

				if ($profesor->user_id and Request::input('username')) {
					
					$this->checkOrChangeUsername($profesor->user_id);

					$usuario = User::find($profesor->user_id);
					$usuario->username		=	Request::input('username');
					$usuario->is_superuser	=	Autoriza::concederSuperusuario($this->user, Request::input('is_superuser'));

					// Esta cuenta YA EXISTE, así que lo que el cuerpo no trae no se toca.
					// Escribir el valor por defecto aquí es lo que reactivaba cuentas
					// cerradas: la pantalla de edición no tiene la casilla de «Activo»
					// —el interruptor de las rejillas llama a `guardar-valor`, otra
					// ruta— y cada guardado deshacía el interruptor. 05 §68.1.
					//
					// El `(int)` es la conclusión del nivel 3 de larastan: en un
					// `tinyint(1)` se escribe 0 o 1, no un booleano de PHP, o el mismo
					// campo sale de dos tipos según por dónde se lea.
					if ($vinieron->trae('is_active')) {
						$usuario->is_active	=	(int) Request::boolean('is_active');
					}

					// Y el correo, por lo mismo y con un agravante: `sanarInputUser`
					// **regenera** `email2` cuando no viene `email1` —que no lo manda
					// ningún cliente—, así que sin esta guarda el correo de la CUENTA
					// se sustituía por el de la persona, o por `usuario@myvc.com`.
					// Son dos columnas de dos tablas. 05 §68.3.
					if ($vinieron->trae('email2')) {
						$usuario->email		=	Request::input('email2');
					}

					if (Request::input('nuevo_password')){
						$usuario->password = Hash::make(Request::input('nuevo_password'));
					}

					$usuario->save();

					$profesor->user_id = $usuario->id;
					
					$profesor->save();

					$profesor->user = $usuario;
				} else if (!$profesor->user_id and Request::input('username')) {
					
					$this->sanarInputUser();
					$this->checkOrChangeUsername($profesor->user_id);

					$usuario = new User;
					$usuario->username		=	Request::input('username');
					$usuario->password		=	Hash::make(Request::input('password', '123456'));
					$usuario->email			=	Request::input('email2');
					$usuario->is_superuser	=	Autoriza::concederSuperusuario($this->user, Request::input('is_superuser'));
					$usuario->is_active		=	Request::input('is_active', 1);
					$usuario->save();


					$profesor->user_id = $usuario->id;
					
					$profesor->save();

					$profesor->user = $usuario;
				}

				return $profesor;
			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		}
	}


	public function checkOrChangeUsername($user_id){

		$user = User::where('username', Request::input('username'))->first();
		//mientras el user exista iteramos y aumentamos i
		if ($user) {

			if ($user->id == $user_id) {
				return;
			}
			
			$username = $user->username;
			$i = 0;
			while(sizeof((array)User::where('username', $username)->first()) > 0 ){
				$i++;
				$username = $user->username.$i;
			}
			Request::merge(array('username' => $username));
		}
		
	}



	public function getConyears()
	{
		$consulta = 'SELECT p.id, p.nombres, p.apellidos, p.sexo,
						p.foto_id, p.titulo, p.facebook, p.email, p.tipo_profesor, p.user_id,
						IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					from profesores p
					LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
					where p.deleted_at is null';

		$profesores = DB::select($consulta);

		foreach ($profesores as $profesor) {
			$profesor->years = Year::de_un_profesor($profesor->id);
		}
		return $profesores;
	}


	public function deleteDestroy($id)
	{
		$profesor = Profesor::find($id);
		if ($profesor) {
			$profesor->delete();
		}else{
			return abort(400, 'Profesor no existe o está en Papelera.');
		}
		return $profesor;
	
	}	

	public function deleteForcedelete($id)
	{
		// Autenticado por el constructor, pero sin ninguna autorización: cualquier
		// usuario con token podía borrar un profesor definitivamente, y con él
		// 31 tablas en cascada.
		// Superusuario: 31 tablas en cascada, siete saltos. Igual que las otras
		// dos de papelera, y por la misma razón.
		Autoriza::exigir(Autoriza::esSuperusuario($this->user),
			'No tienes permiso para eliminar profesores definitivamente.');

		$profesor = Profesor::onlyTrashed()->findOrFail($id);
		
		$profesor->forceDelete();
		return $profesor;
	
	}

	public function putRestore($id)
	{
		$profesor = Profesor::onlyTrashed()->findOrFail($id);

		$profesor->restore();
		return $profesor;
	}


	public function getTrashed()
	{
		$consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_superuser, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=1
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=2)
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=2
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is not null
			order by p.nombres, p.apellidos';

		return DB::select($consulta);
	}

}