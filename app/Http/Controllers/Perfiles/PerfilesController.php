<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;

use Request;
use DB;
use Hash;

use App\User;
use App\Models\Alumno;
use App\Models\Profesor;
use App\Models\Grupo;
use App\Models\Grado;
use App\Models\Acudiente;
use App\Models\ImageModel;


class PerfilesController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id,
			g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			where g.deleted_at is null
			order by g.orden';

		$grados = DB::select($consulta, array(':year_id'=>$user->year_id));

		return $grados;
	}


	public function postStore()
	{

		$user = User::fromToken();

		try {

			$titular_id = null;
			$grado_id = null;

			if (Request::input('titular_id')) {
				$titular_id = Request::input('titular_id');
			}else if (Request::input('titular')) {
				$titular_id = Request::input('titular')['id'];
			}else{
				$titular_id = null;
			}

			if (Request::input('grado_id')) {
				$grado_id = Request::input('grado_id');
			}else if (Request::input('grado')) {
				$grado_id = Request::input('grado')['id'];
			}else{
				$grado_id = null;
			}

			$grupo = new Grupo;
			$grupo->nombre		=	Request::input('nombre');
			$grupo->abrev		=	Request::input('abrev');
			$grupo->year_id		=	$user->year_id;
			$grupo->titular_id	=	Request::input('titular')['id'];
			$grupo->grado_id 	=	Request::input('grado')['id'];
			$grupo->valormatricula =	Request::input('valormatricula');
			$grupo->valorpension=	Request::input('valorpension');
			$grupo->orden		=	Request::input('orden');
			$grupo->caritas		=	Request::input('caritas');
			$grupo->save();

			return $grupo;
		} catch (Exception $e) {
			return abort('400', $e);
		}
	}


	public function putGuardarMiEmailRestore()
	{

		$user 		= User::fromToken();
		$consulta 	= 'UPDATE users SET email=? WHERE id=?';
		DB::update($consulta, [ Request::input('email_restore'), $user->user_id ]);
		return 'Guarddo con éxito';
	}


	public function getShow($id)
	{
		$grupo = Grupo::findOrFail($id);

		$profesor = Profesor::findOrFail($grupo->titular_id);
		$grupo->titular = $profesor;

		$grado = Grado::findOrFail($grupo->grado_id);
		$grupo->grado = $grado;

		return $grupo;
	}

	public function getUsername($username)
	{
		$consulta = 'SELECT * FROM (
				SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, "" as pazysalvo, "" as deuda, p.tipo_doc, p.num_doc as documento, 
					("Pr") as tipo, p.sexo, u.email as email_restore, p.email as email_persona, p.fecha_nac, p.ciudad_nac, p.ciudad_doc,
					u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from profesores p 
					inner join users u on p.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=p.foto_id
					where p.deleted_at is null
				union
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, a.pazysalvo, a.deuda, a.tipo_doc, a.documento, 
					("Al") as tipo, a.sexo, u.email as email_restore, a.email as email_persona, a.fecha_nac, a.ciudad_nac, a.ciudad_doc,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					inner join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS")
					inner join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null
				
				union
				SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, "" as pazysalvo, "" as deuda, "" as tipo_doc, "" as documento,
					("Us") as tipo, u.sexo, u.email as email_restore, "N/A" as email_persona, "N/A" as fecha_nac, "N/A" as ciudad_nac, "N/A" as ciudad_doc, 
					u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from users u
					left join images i on i.id=u.imagen_id 
					where u.id not in (SELECT p.user_id
								from profesores p 
								inner join users u on p.user_id=u.id
							union
							SELECT a.user_id
								from alumnos a 
								inner join users u on a.user_id=u.id
							union
							SELECT ac.user_id
								from acudientes ac 
								inner join users u on ac.user_id=u.id
						)
					and u.deleted_at is null ) usus
					where usus.username = :username';

		$user = DB::select($consulta, array(':username'=>$username));
		if ($user) {
			return $user;
		}else{
			$consulta = 'SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, "" as pazysalvo, "" as deuda, ac.tipo_doc, ac.documento, 
					("Pr") as tipo, ac.sexo, u.email as email_restore, ac.email as email_persona, ac.fecha_nac, ac.ciudad_nac, ac.ciudad_doc, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
				from acudientes ac 
				inner join users u on ac.user_id=u.id
				left join images i on i.id=u.imagen_id
				left join images i2 on i2.id=ac.foto_id
				where ac.deleted_at is null';
				
			$user = DB::select($consulta, array(':username'=>$username));
			if ($user) {
				return $user;
			}
		}

		return abort(400, 'Usuario no encontrado.');
	}

	public function getComprobarusername($username)
	{
		$users = User::withTrashed()->where('username', '=', $username)->get();
		if (count( $users ) > 0) {
			return [array('existe' => true )]; 
		}else{
			return [array('existe' => false )]; 
		}
	}

	public function getUsernames()
	{
		$usernames = DB::select(DB::raw('SELECT username FROM users'));
		return $usernames;
	}

	public function putGuardarUsername($id)
	{
		$user = User::fromToken();

		if (Request::input('username')=='') {
			return abort(400, 'El nombre de usuario no puede estar vació');
		}
		
		$perfil = User::findOrFail($id);
		$perfil->username = Request::input('username');
		$perfil->save();
		return $perfil;
	}

	public function putUpdate($id)
	{
		$user = User::fromToken();

		if (Request::input('tipo') == 'Profesor') {
			
			$perfil = Profesor::findOrFail($id);
			
			try {

				$perfil->nombres	=	Request::input('nombres');
				$perfil->apellidos	=	Request::input('apellidos');
				$perfil->sexo		=	Request::input('sexo');
				$perfil->fecha_nac	=	Request::input('fecha_nac');
				$perfil->celular	=	Request::input('celular');
				$perfil->email		=	Request::input('email_persona');

				$perfil->save();
				return $perfil;
			} catch (Exception $e) {
				return abort('400', 'Datos incorrectos');
			}
		}
		if (Request::input('tipo') == 'Alumno') {
			
			$perfil = Alumno::findOrFail($id);
			
			try {

				$perfil->nombres	=	Request::input('nombres');
				$perfil->apellidos	=	Request::input('apellidos');
				$perfil->sexo		=	Request::input('sexo');
				$perfil->fecha_nac	=	Request::input('fecha_nac');
				$perfil->celular	=	Request::input('celular');
				$perfil->email		=	Request::input('email');

				$perfil->save();
				return $perfil;
			} catch (Exception $e) {
				return abort('400', 'Datos incorrectos');
			}
		}
		if (Request::input('tipo') == 'Ac') {
			
			$perfil = Acudiente::findOrFail($id);
			
			try {

				$perfil->nombres	=	Request::input('nombres');
				$perfil->apellidos	=	Request::input('apellidos');
				$perfil->sexo		=	Request::input('sexo');
				$perfil->fecha_nac	=	Request::input('fecha_nac');
				$perfil->celular	=	Request::input('celular');
				$perfil->email		=	Request::input('email');

				$perfil->save();
				return $perfil;
			} catch (Exception $e) {
				return abort(400, 'Datos incorrectos');
			}
		}
		if (Request::input('tipo') == 'Usuario') {
			
			$perfil = Acudiente::findOrFail($id);
			
			try {

				$perfil->sexo		=	Request::input('sexo');
				$perfil->fecha_nac	=	Request::input('fecha_nac');
				$perfil->celular	=	Request::input('celular');
				$perfil->email		=	Request::input('email');

				$perfil->save();
				return $perfil;
			} catch (Exception $e) {
				return abort(400, 'Datos incorrectos');
			}
		}

		
	}

	public function putCreartodoslosusuarios()
	{
		$user = User::fromToken();
		$alumnos = Alumno::all();
		foreach ($alumnos as $alumno) {
			if ($alumno->user_id) {
				$utemp = User::find($alumno->user_id);
				if (!$utemp) {
					$this->createAndAsignUser($alumno, 'Alumno');
				}
			}else{
				$this->createAndAsignUser($alumno, 'Alumno');
			}
		}

		$profesores = Profesor::all();
		foreach ($profesores as $profesor) {
			if ($profesor->user_id) {
				$utemp = User::find($profesor->user_id);
				if (!$utemp) {
					$this->createAndAsignUser($profesor, 'Profesor');
				}
			}else{
				$this->createAndAsignUser($profesor, 'Profesor');
			}
		}

		$acudientes = Acudiente::all();
		foreach ($acudientes as $acudiente) {
			if ($acudiente->user_id) {
				$utemp = User::find($acudiente->user_id);
				if (!$utemp) {
					$this->createAndAsignUser($acudiente, 'Acudiente');
				}
			}else{
				$this->createAndAsignUser($acudiente, 'Acudiente');
			}
		}
		return 'Usuarios creados con éxito';
	}


	public function putCambiarpassword($id)
	{
		$user = User::fromToken();
		$perfil = User::findOrFail($id);


		if (Request::has('email_restore') || Request::has('email_restore') == '') {
			$perfil->email = Request::input('email_restore');
		}


		if (Request::has('oldpassword') || Request::has('oldpassword') == '') {
			if (! Hash::check((string)Request::input('oldpassword'), $perfil->password))
			{
				abort(400, 'Contraseña antigua es incorrecta');
			}

		}

		$perfil->password = Hash::make((string)Request::input('password'));

		$perfil->save();
		return (string)Request::input('password');
		
	}

	// Borrar estooooooo 1234
	// public function getResetPassword()
	// {
	// 	$password = 
	// 	$user = User::findOrFail(1);
	// 	$user->password = Hash::make("1234");
	// 	$user->save();
	// 	return 'Password cambiado';
	// }

	public function putResetPassword($id)
	{
		$user = User::fromToken();
		$perfil = User::findOrFail($id);

		if (!$user->is_superuser){
			if(!($user->tipo == 'Profesor' && $user->profes_can_edit_alumnos)){
				abort(400, 'No tiene permisos para resetear password');
			}
		}

		$perfil->password = Hash::make((string)Request::input('password'));

		$perfil->save();
		return 'Password cambiado -> '.(string)Request::input('password');
	}


	public function putCambiaremailrestore($id)
	{
		$user = User::fromToken();
		$perfil = User::findOrFail($id);


		if (Request::input('email_restore')) {
			$perfil->email = Request::input('email_restore');
			$perfil->save();
		}else{
			abort(400, 'Email no asignado');
		}

		return $perfil->password . ' - ' . (string)Request::input('password');
	}




	public function createAndAsignUser($persona, $tipo)
	{
		$newU = new User;
		$name = preg_replace('/\s+/', '', $persona->nombres);
		$nom = filter_var($name, FILTER_SANITIZE_EMAIL);

		$user = User::where('username', '=', $nom)->first();

		//mientras el user exista iteramos y aumentamos i
		if ($user) {
			$username = $user->username;
			$i = 0;
			while(sizeof((array)User::where('username', '=', $username)->first()) > 0 ){
				$i++;
				$username = $user->username.$i;
			}
			$nom = $username;
		}

		$newU->username = $nom;
		$newU->save();

		if ($tipo == 'Profesor') {
			$newU->attachRole(2);
		}
		if ($tipo == 'Alumno') {
			$newU->attachRole(3);
		}
		if ($tipo == 'Acudiente') {
			$newU->attachRole(4);
		}


		$persona->user_id = $newU->id;
		$persona->save();
	}



	public function deleteDestroy($id)
	{
		$grupo = Grupo::findOrFail($id);
		$grupo->delete();

		return $grupo;
	}
	public function deleteForcedelete($id)
	{
		$user = User::fromToken();
		$grupo = Grupo::onlyTrashed()->findOrFail($id);
		
		if ($grupo) {
			$grupo->forceDelete();
		}else{
			return abort(400, 'Grupo no encontrado en la Papelera.');
		}
		return $grupo;
	
	}

	public function putRestore($id)
	{
		$user = User::fromToken();
		$grupo = Grupo::onlyTrashed()->findOrFail($id);

		if ($grupo) {
			$grupo->restore();
		}else{
			return abort(400, 'Grupo no encontrado en la Papelera.');
		}
		return $grupo;
	}



	public function getTrashed()
	{
		$grupos = Grupo::onlyTrashed()->get();
		return $grupos;
	}

	
	
	public function getUsuariosall()
	{
		$year_id = Request::input('year_id');
		
		$consulta = 'SELECT * FROM (
				SELECT p.id as persona_id, p.nombres, p.apellidos, p.user_id, u.username, u.tipo, 
					p.sexo, u.email, p.fecha_nac, p.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					p.foto_id, IFNULL(i2.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from profesores p 
					inner join users u on p.user_id=u.id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=p.foto_id
					where p.deleted_at is null
				union
				SELECT a.id as persona_id, a.nombres, a.apellidos, a.user_id, u.username, u.tipo, 
					a.sexo, u.email, a.fecha_nac, a.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					g.id as grupo_id, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.year_id
					from alumnos a 
					inner join users u on a.user_id=u.id
					left join matriculas m on m.alumno_id=a.id and (m.estado="MATR" or m.estado="ASIS")
					left join grupos g on g.id=m.grupo_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null and g.year_id=:year_id
				union
				SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
					u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.imagen_id as foto_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
					from users u
					left join images i on i.id=u.imagen_id 
					where u.id not in (SELECT p.user_id
								from profesores p 
								inner join users u on p.user_id=u.id
							union
							SELECT a.user_id
								from alumnos a 
								inner join users u on a.user_id=u.id
							union
							SELECT ac.user_id
								from acudientes ac 
								inner join users u on ac.user_id=u.id
						)
					and u.deleted_at is null ) usus';

		$users = DB::select($consulta, [':year_id' => $year_id]);
		
		$cons = 'SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, u.tipo, 
					ac.sexo, u.email, ac.fecha_nac, ac.ciudad_nac, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
			from acudientes ac 
			inner join users u on ac.user_id=u.id
			left join images i on i.id=u.imagen_id
			where ac.deleted_at is null and u.tipo is not null';
				
		$users_acuds = DB::select($cons);
		
		$users = array_merge($users, $users_acuds);/**/

		foreach ($users as $usuario) {

			//$usuario = get_object_vars($usuario);
			$userTemp = User::find($usuario->user_id);
			
			if ($userTemp) {
				
				$roles 			= $userTemp->roles()->get();
				$usuario->roles = $roles;
				$usuario->perms = $userTemp->permissions();
			}

		}
		


		return $users;
	
	}

	public function putCambiarimgunusuario($usuarioElegido)
	{
		$user = User::findOrFail($usuarioElegido);
		$user->imagen_id = Request::input('imgParaUsuario');
		$user->save();
		return $user;
	}


	public function putCambiarimgunalumno($alumnoElegido)
	{
		$alumno = Alumno::findOrFail($alumnoElegido);
		$alumno->foto_id = Request::input('imgOficialAlumno');
		$alumno->save();
		return $alumno;
	}



	public function putCambiarimgunprofe($profeElegido)
	{
		$profesor = Profesor::findOrFail($profeElegido);
		$profesor->foto_id = Request::input('imgOficialProfe');
		$profesor->save();
		return $profesor;
	}

	public function putCambiarfirmaunprofe($profeElegido)
	{
		$profesor = Profesor::findOrFail($profeElegido);
		$profesor->firma_id = Request::input('imgFirmaProfe');
		$profesor->save();
		$img = ImageModel::find($profesor->firma_id);
		return $img;
	}

	// Para recuperar una contraseña en caso de emergencia. Volver comentario.
	/*
	public function getQuieroCambiarContrasenia()
	{

		if (!Request::has('password_nuevecito')) {
			abort(501);
		}

		$pass 	= Hash::make((string)Request::input('password_nuevecito'));

		$consulta 	= 'UPDATE users SET password=? WHERE id=1';
		DB::update($consulta, [$pass]);
		return 'ready';
	}
	 */


}