<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\User;
use \Log;

use Carbon\Carbon;
use App\Exports\AlumnosExport;
use Maatwebsite\Excel\Facades\Excel;



class UsersController extends Controller {

	public function getExport()
	{
		return Excel::download(new AlumnosExport, 'alumnos.xlsx');
	}

	public function putUsernamesCheck()
	{
		$texto = Request::input('texto');

		$consulta = 'SELECT username FROM users WHERE username like :texto;';
		
		$res = DB::select($consulta, [
			':texto'		=> $texto.'%'
		]);
		
		return [ 'usernames' => $res ];
	}


	/**
	 * QUÉ DOCENTE MIRA UNA CUENTA ADMINISTRATIVA, escrito en su propia fila.
	 *
	 * `users.profesor_id` existe desde siempre y **nadie la escribía**: las
	 * dieciséis cuentas de tipo `Usuario` del colegio la tienen en `NULL`, y los
	 * únicos `UPDATE users` del repositorio tocan contraseña, correo, username y
	 * `periodo_id`. La columna sí se LEE, y en dos sitios que importan:
	 * `ContextoDeUsuario` la manda dentro de la sesión —de ahí la lee el front—
	 * y `ChangeAskedController::getToMe` la usa para pintarle a esa cuenta el
	 * horario de hoy y el de mañana. O sea que la mitad de la función estaba
	 * escrita y la puerta para rellenarla no existía. Ésta es esa puerta.
	 *
	 * **Por qué se guarda en el servidor y no en el navegador**: el administrador
	 * entra desde el ordenador de secretaría y desde el suyo, y elegir docente en
	 * cada uno es la clase de trabajo repetido que la migración existe para
	 * quitar. Decisión de Joseth, 28 ago 2026.
	 *
	 * ────────────────────────────────────────────────────────────────────────
	 * LAS DOS COMPROBACIONES, Y POR QUÉ NO BASTA CON `auth.personal`
	 *
	 *   1. **Sólo `tipo === 'Usuario'`.** Un profesor tiene su identidad en
	 *      `profesores.id` --por ahí sale su `persona_id`-- y `users.profesor_id`
	 *      no se mira nunca para él: dejarle escribirla guardaría un dato que no
	 *      lee nadie y que en la siguiente lectura contradice al que sí se lee.
	 *   2. **Sólo un profesor CONTRATADO en el año en curso**, con la misma
	 *      consulta de `ContratosController::postIndex`. Sin esto la columna
	 *      admite cualquier entero --no hay clave foránea-- y un id inventado
	 *      dejaría la cuenta apuntando a un profesor que no existe, que es
	 *      exactamente el contrato huérfano de la §78 con otro nombre.
	 *
	 * `null` es una respuesta legítima y no un error: es «ya no miro a nadie», y
	 * el selector del panel lo manda al limpiarse.
	 */
	public function putMiDocente()
	{
		$user = User::fromToken();

		if ($user->tipo !== 'Usuario') {
			return abort(403, 'Sólo una cuenta administrativa elige docente');
		}

		$pedido 	= Request::input('profesor_id');
		$profesorId = null;

		if ($pedido !== null && $pedido !== '') {
			$consulta = 'SELECT p.id
				from profesores p
				inner join contratos c on c.profesor_id=p.id and c.year_id=:year_id and c.deleted_at is null
				where p.id=:profesor_id and p.deleted_at is null';

			$contratado = DB::select($consulta, [
				':year_id' 		=> $user->year_id,
				':profesor_id' 	=> $pedido,
			]);

			if (count($contratado) == 0) {
				// 422 y no 404: la fila que no está no es la que se pide en la
				// URL, es la que trae el cuerpo. Es la familia de la §54 al
				// revés, y el front lo distingue para decir «ese docente no da
				// clase este año» en vez de «no se pudo guardar».
				return response()->json([ 'message' => 'Ese docente no está contratado en el año en curso' ], 422);
			}

			$profesorId = (int) $contratado[0]->id;
		}

		$now = Carbon::now('America/Bogota');

		DB::update('UPDATE users SET profesor_id=?, updated_by=?, updated_at=? WHERE id=?',
			[$profesorId, $user->user_id, $now, $user->user_id]);

		return [ 'profesor_id' => $profesorId ];
	}


	public function postCrearAdministrador()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		
		if($user->is_superuser){
			$username = 'usuario'.rand(100, 9999);
			
			$consulta = 'INSERT INTO users(username, password, sexo, is_superuser, tipo, is_active, periodo_id, created_by, created_at) 
				VALUES("'.$username.'", "'.Hash::make('123456').'", "M", 1, "Usuario", 1, 1, ?, "'.$now.'")';
				
			DB::insert($consulta, [$user->user_id]);
			
			$id = DB::getPdo()->lastInsertId();
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES('.$id.', 1)';
				
			DB::insert($consulta);
			
			$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
				u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
				u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
				from users u
				left join images i on i.id=u.imagen_id 
				where u.id='.$id;
				
			$usuario = DB::select($consulta)[0];

			return ['usuario'=>$usuario];
		}else{
			// 403 y no 404, que es lo que decia y no lo que pasaba. Es la familia
			// de la §54 —un codigo que significa «esa fila no esta» usado para
			// «no puedes»— y no salio con los ocho de entonces porque aquel
			// barrido cubria `auth.token` y estas tres son `auth.personal`.
			// Comprobado en los cuatro clientes antes de cambiarlo: solo las
			// llama `myvc_front` desde `UsuariosCtrl.ts`, y su `.catch` no
			// recibe ni argumentos —pinta un texto fijo—, asi que no lee el
			// codigo ni el cuerpo.
			return abort(403, 'Sin autorización');
		}
		
	}



	public function postCrearPsicologo()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		
		if($user->is_superuser){
			$username = 'psicologo'.rand(100, 9999);
			
			$consulta = 'INSERT INTO users(username, password, sexo, is_superuser, tipo, is_active, periodo_id, created_by, created_at) 
				VALUES("'.$username.'", "'.Hash::make('123456').'", "M", 0, "Usuario", 1, 1, ?, "'.$now.'")';
				
			DB::insert($consulta, [$user->user_id]);
			
			$id = DB::getPdo()->lastInsertId();
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES('.$id.', 11)'; //  Psicólogo
				
			DB::insert($consulta);
			
			$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
				u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
				u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
				from users u
				left join images i on i.id=u.imagen_id 
				where u.id='.$id;
				
			$usuario = DB::select($consulta)[0];

			return ['usuario'=>$usuario];
		}else{
			// 403 y no 404, que es lo que decia y no lo que pasaba. Es la familia
			// de la §54 —un codigo que significa «esa fila no esta» usado para
			// «no puedes»— y no salio con los ocho de entonces porque aquel
			// barrido cubria `auth.token` y estas tres son `auth.personal`.
			// Comprobado en los cuatro clientes antes de cambiarlo: solo las
			// llama `myvc_front` desde `UsuariosCtrl.ts`, y su `.catch` no
			// recibe ni argumentos —pinta un texto fijo—, asi que no lee el
			// codigo ni el cuerpo.
			return abort(403, 'Sin autorización');
		}
		
	}

	

	public function postCrearEnfermero()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		
		if($user->is_superuser){
			$username = 'enfermero'.rand(100, 9999);
			
			$consulta = 'INSERT INTO users(username, password, sexo, is_superuser, tipo, is_active, periodo_id, created_by, created_at) 
				VALUES("'.$username.'", "'.Hash::make('123456').'", "M", 0, "Usuario", 1, 1, ?, "'.$now.'")';
				
			DB::insert($consulta, [$user->user_id]);
			
			$id = DB::getPdo()->lastInsertId();
			$consulta = 'INSERT INTO role_user(user_id, role_id) 
				VALUES('.$id.', 7)'; //  Enfermero
				
			DB::insert($consulta);
			
			$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.tipo, 
				u.sexo, u.email, "N/A" as fecha_nac, "N/A" as ciudad_nac, 
				u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id  
				from users u
				left join images i on i.id=u.imagen_id 
				where u.id='.$id;
				
			$usuario = DB::select($consulta)[0];

			return ['usuario'=>$usuario];
		}else{
			// 403 y no 404, que es lo que decia y no lo que pasaba. Es la familia
			// de la §54 —un codigo que significa «esa fila no esta» usado para
			// «no puedes»— y no salio con los ocho de entonces porque aquel
			// barrido cubria `auth.token` y estas tres son `auth.personal`.
			// Comprobado en los cuatro clientes antes de cambiarlo: solo las
			// llama `myvc_front` desde `UsuariosCtrl.ts`, y su `.catch` no
			// recibe ni argumentos —pinta un texto fijo—, asi que no lee el
			// codigo ni el cuerpo.
			return abort(403, 'Sin autorización');
		}
		
	}

	
}