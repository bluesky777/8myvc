<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;
use App\Support\Autoriza;
use App\Support\ClaveNueva;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\User;
use App\Models\Alumno;
use App\Models\Profesor;
use App\Models\Grupo;
use App\Models\Grado;
use App\Models\Acudiente;
use App\Models\ImageModel;
use Illuminate\Support\Str;


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
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}


	public function putGuardarMiEmailRestore()
	{

		$user 		= User::fromToken();
		$consulta 	= 'UPDATE users SET email=? WHERE id=?';
		DB::update($consulta, [ Request::input('email_restore'), $user->user_id ]);
		return 'Guarddo con éxito';
	}


	/**
	 * No devuelve un perfil: devuelve el GRUPO cuyo id coincide — §156.
	 *
	 * Es uno de los cinco métodos de este controlador que operan sobre grupo, y el
	 * front lo lleva escrito en la cabecera de `PerfilesApi`. **No lo llama ningún
	 * cliente** (§14.2), así que lo de abajo era una mina y no un fallo vivo.
	 *
	 * `Profesor::findOrFail($grupo->titular_id)` donde su gemela
	 * `GruposController::getShow` hace `Profesor::find()`. Y `grupos.titular_id`
	 * es **nullable** —el formulario de «Nuevo grupo» no obliga a elegir titular—,
	 * así que con esa fila las dos rutas contestaban cosas distintas: la de grupos
	 * devolvía el grupo con `titular: null` y ésta **404, diciendo que no existe
	 * un grupo que sí existe**.
	 *
	 * Se alinea con la gemela, que es la que tiene razón: **un grupo sin titular
	 * no es un grupo que falte.** Dos copias del mismo método que divergen en una
	 * palabra es lo que pasa cuando nadie comprueba que hacen lo mismo — la misma
	 * lección que `store-firma` y que `perfiles/destroy`.
	 */
	public function getShow($id)
	{
		$grupo = Grupo::findOrFail($id);

		$profesor = Profesor::find($grupo->titular_id);
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
			// La consulta de arriba cubre profesores, alumnos y usuarios sin ficha.
			// Un **acudiente** no es ninguno de los tres y cae aquí — y aquí había
			// dos fallos encadenados, de los que el segundo tapaba al primero:
			//
			//   · esta consulta **no filtraba por el nombre** —su `WHERE` era solo
			//     `ac.deleted_at is null`—, así que devolvía **el directorio entero
			//     de acudientes** con documento, fecha de nacimiento, correo
			//     personal y correo de recuperación de cada uno;
			//   · y se le pasaba un `:username` que no aparecía en el SQL, así que
			//     PDO lanzaba «Invalid parameter number» antes de ejecutarla. **500
			//     para todo acudiente y todo nombre inventado** — 1.000 de las 1.067
			//     cuentas de la base local—, y por eso el `abort(400)` del final era
			//     inalcanzable.
			//
			// O sea que lo único que impedía la fuga era el fallo de binding. Y el
			// arreglo que sugiere el mensaje de error —quitar el parámetro que
			// sobra— es **justo el que abre la puerta**. El bueno es el otro: poner
			// el `WHERE` que tienen sus tres consultas hermanas, que es lo que se
			// hace aquí.
			$consulta = 'SELECT ac.id as persona_id, ac.nombres, ac.apellidos, ac.user_id, u.username, "" as pazysalvo, "" as deuda, ac.tipo_doc, ac.documento, 
					("Pr") as tipo, ac.sexo, u.email as email_restore, ac.email as email_persona, ac.fecha_nac, ac.ciudad_nac, ac.ciudad_doc, 
					u.imagen_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					ac.foto_id, IFNULL(i2.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
					"N/A" as grupo_id, ("N/A") as nombre_grupo, ("N/A") as abrev_grupo, "N/A" as year_id
				from acudientes ac 
				inner join users u on ac.user_id=u.id
				left join images i on i.id=u.imagen_id
				left join images i2 on i2.id=ac.foto_id
				where ac.deleted_at is null and u.username = :username';
				
			$user = DB::select($consulta, array(':username'=>$username));
			if ($user) {
				return $user;
			}
		}

		return abort(400, 'Usuario no encontrado.');
	}

	public function getComprobarusername($username)
	{
		// Sin `withTrashed()`: `App\User` no usa SoftDeletes, así que ese método
		// no existe y la llamada era un BadMethodCallException — 500 fijo desde
		// que se escribió. Como tampoco hay scope global que filtre, este
		// `where` ya devuelve también los borrados, que es lo que `withTrashed`
		// pretendía. Y es lo que hace falta: el username de alguien borrado
		// sigue ocupado.
		$users = User::where('username', '=', $username)->get();
		if (count( $users ) > 0) {
			return [array('existe' => true )]; 
		}else{
			return [array('existe' => false )]; 
		}
	}

	public function getUsernames()
	{
		$usernames = DB::select('SELECT username FROM users');
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

	/**
	 * Editar la ficha de una persona. Con medio formulario la vaciaba — §153.
	 *
	 * Las cuatro ramas de aquí son cuatro copias de las mismas seis líneas, y las
	 * veintidós asignaciones leían `Request::input('x')` **sin defecto**: un campo
	 * que el cliente no manda llegaba como `null` y se escribía encima. Era el
	 * peor del repo en proporción —22 columnas, ninguna a salvo— y justo sobre la
	 * ficha de una persona: apellidos, sexo, fecha de nacimiento, celular y
	 * correo.
	 *
	 * Es la §68 otra vez: **un campo que no se manda no es un campo que no cambia,
	 * es un campo que se pisa.** Allí el que se pisaba era `is_active` y devolvía
	 * la entrada al sistema; aquí lo que se pierde es el dato con el que el
	 * colegio llama a una familia.
	 *
	 * Se arregla con **el defecto de `Request::input()`** y no con
	 * `CamposQueVinieron`, y el discriminador está medido, no copiado: esa clase
	 * hace falta cuando el controlador hace `Request::merge()` o `sanarInput*`
	 * antes de leer, porque a esa altura `has()` ya no distingue lo que mandó el
	 * cliente de lo que se rellenó solo. **Aquí no hay ni uno ni otro**, así que
	 * el defecto basta y es una palabra por línea. En `ProfesoresController`, que
	 * sí tiene `sanarInput*`, toca la clase.
	 *
	 * El defecto es **el valor que ya tiene la fila**, no una constante: mandar el
	 * campo vacío a propósito sigue vaciándolo, que es una intención distinta de
	 * no mandarlo.
	 */
	public function putUpdate($id)
	{
		$user = User::fromToken();

		if (Request::input('tipo') == 'Profesor') {
			
			$perfil = Profesor::findOrFail($id);
			
			try {

				$perfil->nombres	=	Request::input('nombres', $perfil->nombres);
				$perfil->apellidos	=	Request::input('apellidos', $perfil->apellidos);
				$perfil->sexo		=	Request::input('sexo', $perfil->sexo);
				$perfil->fecha_nac	=	Request::input('fecha_nac', $perfil->fecha_nac);
				$perfil->celular	=	Request::input('celular', $perfil->celular);
				$perfil->email		=	Request::input('email_persona', $perfil->email);

				$perfil->save();
				return $perfil;
			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		}
		if (Request::input('tipo') == 'Alumno') {
			
			$perfil = Alumno::findOrFail($id);
			
			try {

				$perfil->nombres	=	Request::input('nombres', $perfil->nombres);
				$perfil->apellidos	=	Request::input('apellidos', $perfil->apellidos);
				$perfil->sexo		=	Request::input('sexo', $perfil->sexo);
				$perfil->fecha_nac	=	Request::input('fecha_nac', $perfil->fecha_nac);
				$perfil->celular	=	Request::input('celular', $perfil->celular);
				$perfil->email		=	Request::input('email', $perfil->email);

				$perfil->save();
				return $perfil;
			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		}
		if (Request::input('tipo') == 'Ac') {
			
			$perfil = Acudiente::findOrFail($id);
			
			try {

				$perfil->nombres	=	Request::input('nombres', $perfil->nombres);
				$perfil->apellidos	=	Request::input('apellidos', $perfil->apellidos);
				$perfil->sexo		=	Request::input('sexo', $perfil->sexo);
				$perfil->fecha_nac	=	Request::input('fecha_nac', $perfil->fecha_nac);
				$perfil->celular	=	Request::input('celular', $perfil->celular);
				$perfil->email		=	Request::input('email', $perfil->email);

				$perfil->save();
				return $perfil;
			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		}
		if (Request::input('tipo') == 'Usuario') {
			
			$perfil = Acudiente::findOrFail($id);
			
			try {

				$perfil->sexo		=	Request::input('sexo', $perfil->sexo);
				$perfil->fecha_nac	=	Request::input('fecha_nac', $perfil->fecha_nac);
				$perfil->celular	=	Request::input('celular', $perfil->celular);
				$perfil->email		=	Request::input('email', $perfil->email);

				$perfil->save();
				return $perfil;
			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		}

		
	}

	public function putCreartodoslosusuarios()
	{
		$user = User::fromToken();

		// Crea la cuenta de todos los alumnos, profesores y acudientes del colegio
		// que no la tengan. Es la misma clase de operación que `cambiar-usuarios/*`
		// —de colegio, no de aula— y el botón que la dispara vive en la pantalla de
		// usuarios, que el menú del front enseña solo con `hasRoleOrPerm('admin')`.
		// Con `auth.personal` a secas la disparaba cualquiera de los 51 profesores.
		// Superusuario y no `esAdministrativo`, que desde el 21 ago 2026 incluye
		// al Secretario: esto CREA las cuentas de alumnos, profesores y
		// acudientes, y «no crea usuarios» fue textual en el alcance que decidió
		// Joseth. Ver Autoriza::esAdministrativo() y 05 §30.2.
		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'Solo un superusuario puede crear las cuentas de todo el colegio.');

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


		// Antes decía `has('email_restore') || has('email_restore') == ''`, que es
		// siempre cierto: `has()` devuelve un booleano y `false == ''` vale true.
		// O sea que el correo se asignaba también cuando el cliente no lo mandaba,
		// y entonces `input()` es null: cambiar la contraseña BORRABA el correo de
		// recuperación, que es con lo que se recupera la cuenta si se pierde la
		// contraseña nueva. La columna es `DEFAULT NULL` y el save() no protestaba.
		if (Request::has('email_restore')) {
			$perfil->email = Request::input('email_restore');
		}


		// El `if` de aquí también era siempre cierto, por lo mismo — y esta vez
		// esa era la única razón de que el endpoint se defendiera: la comprobación
		// se hacía SIEMPRE, también cuando el cliente no mandaba `oldpassword`, y
		// ahí `Hash::check('', $hash)` falla y corta con un 400.
		//
		// Se deja escrito sin condición, que es lo que de verdad hacía. Escrito
		// como parecía pretenderse —`if (Request::has('oldpassword'))`— un token
		// robado cambiaría la contraseña sin conocer la anterior. Lo fija
		// CambiarPasswordTest para que no se pueda quitar sin que falle algo.
		if (! Hash::check((string)Request::input('oldpassword'), $perfil->password))
		{
			abort(400, 'Contraseña antigua es incorrecta');
		}

		// Lo mismo que en putResetPassword, y aquí sobre la cuenta de quien la pide:
		// mandar el campo vacío la dejaba con el hash de la cadena vacía. El front
		// exige cuatro caracteres en su pantalla, así que nunca llega vacío desde
		// ahí — y esta ruta no es solo esa pantalla.
		$clave = ClaveNueva::exigir();

		$perfil->password = Hash::make($clave);

		$perfil->save();

		// Devolvía la contraseña recién puesta. El front la ignora.
		return 'Password cambiado';
		
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

			// La bandera se llama `profes_can_edit_alumnos` y esta comprobación no
			// miraba a QUIÉN se le cambia la contraseña: un profesor con la bandera
			// encendida reseteaba la del superusuario y recibía la clave nueva en la
			// respuesta. Eso no es «qué puede hacer el personal entre sí», es subirse
			// de nivel en una petición. Medido el 20 ago 2026: 200 y la cuenta tomada.
			//
			// El criterio es el que ya tiene escrito Autoriza::puedeBorrarAlumnos —un
			// profesor con esa bandera actúa SOBRE ALUMNOS—, aplicado aquí al objetivo
			// en vez de al que pide. 403 y no el 400 de arriba: aquél es una respuesta
			// que el front ya recibe, y esto es código nuevo.
			// Ver docs/migracion/05-codigo-muerto-y-roto.md §29.
			Autoriza::exigir($perfil->tipo === 'Alumno',
				'Un docente solo puede resetear la contraseña de un alumno.');
		}

		// Sin esto, `Hash::make('')` dejaba la cuenta con el hash de la cadena vacía
		// y respondía 200. El modal del front no comprueba el largo.
		$clave = ClaveNueva::exigir();

		$perfil->password = Hash::make($clave);

		$perfil->save();

		// Devolvía la contraseña nueva dentro del cuerpo. El front no la lee —enseña
		// un aviso fijo— y una contraseña en una respuesta acaba en los registros de
		// quien esté en medio.
		return 'Password cambiado';
	}


	/**
	 * El correo de recuperación de una cuenta. **Es la llave, no un dato de perfil.**
	 *
	 * `login/recuperar-clave` busca a quien pide el reseteo con
	 * `SELECT * FROM users WHERE email = ?` y le manda el enlace ahí, así que
	 * quien escribe esta columna en una cuenta ajena se lleva esa cuenta. El único
	 * guard que tenía era `persona.propia:user_id`, que frena a alumnos y
	 * acudientes y **deja pasar de largo a todo el personal**: cualquiera de los 51
	 * profesores ponía su correo en la cuenta del superusuario. Es la misma familia
	 * que la §29 y se cierra igual — ver 05 §36.
	 *
	 * El criterio es el suyo o el de un superusuario. Se comprueba aquí y no en la
	 * ruta porque «el suyo» necesita comparar el `{id}` con el del token, y eso el
	 * middleware solo lo hace para familias.
	 *
	 * Y el `return` era `$perfil->password . ' - ' . Request::input('password')`.
	 * `User` tiene `password` en `$hidden`, así que en JSON no sale nunca; una
	 * concatenación en una cadena **se salta `$hidden` entero**. La protección
	 * estaba puesta y no cubría la única salida que se usaba.
	 *
	 * Ningún cliente llama a esta ruta —el propio front lo dejó escrito al retirar
	 * su último llamante en `UserConfiguracionCtrl`—, así que ni el 403 ni la
	 * respuesta nueva rompen ninguna pantalla. Lo que cada uno usa para cambiar el
	 * suyo es `perfiles/guardar-mi-email-restore`.
	 */
	public function putCambiaremailrestore($id)
	{
		$user = User::fromToken();
		
		Autoriza::exigir(
			(int) $id === (int) $user->user_id || Autoriza::esSuperusuario($user),
			'Solo puedes cambiar tu propio correo de recuperación.'
		);
		
		$perfil = User::findOrFail($id);


		if (Request::input('email_restore')) {
			$perfil->email = Request::input('email_restore');
			$perfil->save();
		}else{
			abort(400, 'Email no asignado');
		}

		return 'Correo de recuperación guardado.';
	}




	/**
	 * El nombre de usuario que se le fabrica a una persona que no tiene cuenta.
	 *
	 * `Str::ascii()` y no `FILTER_SANITIZE_EMAIL` a secas. El sanitizador es para
	 * correos, y lo que hace con un nombre castellano es **borrar** las tildes en
	 * vez de transliterarlas: `José Andrés` salía `JosAndrs` y `Ñoño` salía `oo`.
	 * Se sigue pasando el sanitizador después, porque quita lo que `ascii()` deja
	 * y aquí no interesa. Ver docs/migracion/12-larastan-nivel-7.md §12.
	 *
	 * Y si de ahí no sale nada —el nombre estaba vacío o era todo espacios— se cae
	 * a `{tipo}{id}`. Antes se creaba la cuenta con el username **vacío**: en la
	 * base de desarrollo hay una así desde 2019 (usuario 842, un acudiente activo
	 * cuyo `nombres` está en blanco). No es una puerta abierta —tiene su hash y la
	 * clave vacía no entra— pero es una cuenta que su dueño no puede usar, y la
	 * fabricó el mecanismo que existe justamente para que todo el mundo tenga una.
	 */
	private function usernameLibrePara($persona, $tipo)
	{
		$base = filter_var(
			preg_replace('/\s+/', '', Str::ascii((string) $persona->nombres)),
			FILTER_SANITIZE_EMAIL
		);

		if (! is_string($base) || $base === '') {
			$base = Str::lower($tipo).$persona->id;
		}

		$candidato = $base;
		$i = 0;

		// `exists()` y no `sizeof((array) ...->first())`, que funcionaba de casualidad:
		// `(array) null` es `[]` y un modelo encontrado nunca lo es. Una casualidad
		// que se lee como una comprobación es de las que sobreviven a un refactor
		// bienintencionado y dejan de funcionar sin que nadie lo note.
		while (User::where('username', '=', $candidato)->exists()) {
			$i++;
			$candidato = $base.$i;
		}

		return $candidato;
	}

	public function createAndAsignUser($persona, $tipo)
	{
		$newU = new User;
		$newU->username = $this->usernameLibrePara($persona, $tipo);
		$newU->save();

		// `attachRole()` es de Entrust, que no está instalado ni aparece en el
		// composer.lock: llamarlo aquí era un fatal seguro. Y el fatal caía
		// ENTRE el `save()` del usuario y el `$persona->user_id = $newU->id`
		// de más abajo, así que cada llamada a
		// `PUT api/perfiles/creartodoslosusuarios` creaba un usuario huérfano
		// —sin persona detrás— y devolvía 500 en la primera persona de la
		// lista. Repetido, iba dejando uno por intento.
		//
		// El reemplazo no es una decisión: `AlumnosController` ya lo tenía
		// hecho —`$usuario->roles()->attach(...)` con la línea de Entrust
		// comentada al lado— y aquí quedó sin migrar. Los ids 2, 3 y 4 son
		// Profesor, Alumno y Acudiente en la tabla `roles`.
		if ($tipo == 'Profesor') {
			$newU->roles()->attach(2);
		}
		if ($tipo == 'Alumno') {
			$newU->roles()->attach(3);
		}
		if ($tipo == 'Acudiente') {
			$newU->roles()->attach(4);
		}


		$persona->user_id = $newU->id;
		$persona->save();
	}



	/**
	 * No borra un perfil: manda un GRUPO a la papelera — §100.
	 *
	 * Duplicado de `GruposController::deleteDestroy` bajo otra URL, como el
	 * `forcedelete` y el `restore` de aquí abajo. Y con un cliente enchufado: la
	 * rejilla de Usuarios del front llama `PerfilesApi.eliminar(row.user_id)`, así
	 * que **pulsar «Eliminar» sobre un usuario deja al usuario donde está y manda
	 * a la papelera el grupo cuyo id coincide con su `user_id`**. El propio
	 * `PerfilesApi` lleva escrito que cinco métodos de este controlador operan
	 * sobre grupo; el botón sigue enchufado igual.
	 *
	 * **Lo que hace esta ruta no se cambia aquí**: la regla del repo para lo roto
	 * con ruta es documentarlo, y decidir qué debería borrar es una decisión con
	 * el front delante. Queda fijado en `BorrarUnPerfilBorraUnGrupoTest`.
	 *
	 * **Lo que sí se cierra es la autorización, que no necesita decisión**: sus
	 * dos hermanas de papelera en este mismo fichero piden superusuario desde la
	 * §28.4 y la §76, y ésta se había quedado con `auth.personal` a secas — o sea
	 * cualquiera de los 51 profesores. Nadie pierde un botón que hoy vea: la
	 * rejilla de Usuarios vive en un menú que el front enseña con
	 * `hasRoleOrPerm('admin')`, y los diez `Admin` son los diez `is_superuser`.
	 *
	 * **Sobre qué población se cierra**: las dos. Su gemela `grupos/destroy` hace
	 * exactamente lo mismo bajo otra URL y se cerró en el mismo lote, un commit
	 * después — durante esas horas hubo aquí un test **en verde** afirmando que
	 * seguía abierta, escrito para que quien la cerrara tuviera que venir a
	 * borrarlo. No se borró: se le cambió el valor esperado a 403.
	 */
	public function deleteDestroy($id)
	{
		Autoriza::exigir(Autoriza::esSuperusuario(User::fromToken()),
			'No tienes permiso para eliminar grupos.');

		$grupo = Grupo::findOrFail($id);
		$grupo->delete();

		return $grupo;
	}
	public function deleteForcedelete($id)
	{
		$user = User::fromToken();

		// Duplicado de GruposController::deleteForcedelete bajo otra ruta: borra un
		// Grupo, con la misma cascada de 27 tablas hasta notas. Cerrar solo la de
		// grupos dejaba esta puerta abierta.
		// Superusuario: borrado físico en cascada, que la §28.4 ya había fijado
		// como suyo y que el alcance del Secretario no nombra.
		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'No tienes permiso para eliminar grupos definitivamente.');

		$grupo = Grupo::onlyTrashed()->findOrFail($id);

		$grupo->forceDelete();

		return $grupo;
	
	}

	/*
	 * El gemelo de `grupos/restore` bajo otra URL, igual que su `forcedelete`.
	 *
	 * Cerrar sólo la de `grupos/` dejaba esta puerta abierta — que es literalmente
	 * lo que ya avisaba el comentario que la §28.4 dejó escrito en el `forcedelete`
	 * de este mismo fichero. El porqué del criterio está allí y en
	 * `GruposController::putRestore`.
	 */
	public function putRestore($id)
	{
		$user = User::fromToken();

		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'No tienes permiso para restaurar grupos.');

		$grupo = Grupo::onlyTrashed()->findOrFail($id);

		$grupo->restore();
		return $grupo;
	}



	public function getTrashed()
	{
		$grupos = Grupo::onlyTrashed()->get();
		return $grupos;
	}

	
	
	/**
	 * La rejilla de usuarios del colegio: profesores, alumnos y acudientes en un
	 * `UNION`.
	 *
	 * ⚠️ **NO AÑADAS `is_superuser` A ESTE `SELECT` SIN LEER ESTO.** Es una línea,
	 * es lo primero que uno hace si necesita saber quién es administrador, y
	 * **enciende un botón que manda grupos a la papelera**.
	 *
	 * El botón de borrar de la rejilla se pinta con `is_superuser`. Como esa
	 * columna no viaja en esta respuesta, la condición es siempre falsa y nadie lo
	 * pulsa nunca. Lo que hay detrás es `DELETE perfiles/destroy/{id}`, que **no
	 * borra un perfil: hace `Grupo::findOrFail($id)->delete()`** — un grupo, con su
	 * cascada. Hoy está muerto por accidente, no por diseño.
	 *
	 * Es uno de los cinco métodos de este controlador que operan sobre GRUPO y no
	 * sobre persona, y lo avisa también el front en la cabecera de `PerfilesApi.ts`.
	 * Fijado por `PerfilesEscribeEnOtraTablaTest`, que se pone en rojo el día que
	 * esta columna aparezca — y su mensaje dice qué mirar antes de actualizarlo.
	 *
	 * Y de aquí sale el otro dato que hace daño: las columnas que no aplican a cada
	 * rama del `UNION` se rellenan con la cadena **«N/A»**. La rejilla reenvía lo
	 * que recibió, y `putUpdate` la escribe tal cual; con `'strict' => false`, un
	 * «N/A» en una columna `DATE` se guarda como `0000-00-00`. Ver 05 §65.
	 */
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
		// La pestaña «Imágenes de usuarios» del gestor de archivos, que es la única
		// que llama a esto, la enseña el front con `hasRoleOrPerm('admin')`; el
		// backend pedía solo `auth.personal`. Es la situación de la §29.3 —el
		// backend dos escalones por debajo de su propia pantalla— y se cierra con
		// aquella decisión, no con una nueva. Ver 05 §36.
		Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
			'No tienes permiso para cambiar la imagen de otra persona.');
		
		$user = User::findOrFail($usuarioElegido);
		$user->imagen_id = Request::input('imgParaUsuario');
		$user->save();
		return $user;
	}


	public function putCambiarimgunalumno($alumnoElegido)
	{
		// La pestaña «Imágenes de usuarios» del gestor de archivos, que es la única
		// que llama a esto, la enseña el front con `hasRoleOrPerm('admin')`; el
		// backend pedía solo `auth.personal`. Es la situación de la §29.3 —el
		// backend dos escalones por debajo de su propia pantalla— y se cierra con
		// aquella decisión, no con una nueva. Ver 05 §36.
		Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
			'No tienes permiso para cambiar la imagen de otra persona.');
		
		$alumno = Alumno::findOrFail($alumnoElegido);
		$alumno->foto_id = Request::input('imgOficialAlumno');
		$alumno->save();
		return $alumno;
	}



	public function putCambiarimgunprofe($profeElegido)
	{
		// La pestaña «Imágenes de usuarios» del gestor de archivos, que es la única
		// que llama a esto, la enseña el front con `hasRoleOrPerm('admin')`; el
		// backend pedía solo `auth.personal`. Es la situación de la §29.3 —el
		// backend dos escalones por debajo de su propia pantalla— y se cierra con
		// aquella decisión, no con una nueva. Ver 05 §36.
		Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
			'No tienes permiso para cambiar la imagen de otra persona.');
		
		$profesor = Profesor::findOrFail($profeElegido);
		$profesor->foto_id = Request::input('imgOficialProfe');
		$profesor->save();
		return $profesor;
	}

	public function putCambiarfirmaunprofe($profeElegido)
	{
		// La pestaña «Imágenes de usuarios» del gestor de archivos, que es la única
		// que llama a esto, la enseña el front con `hasRoleOrPerm('admin')`; el
		// backend pedía solo `auth.personal`. Es la situación de la §29.3 —el
		// backend dos escalones por debajo de su propia pantalla— y se cierra con
		// aquella decisión, no con una nueva. Ver 05 §36.
		Autoriza::exigir(Autoriza::esAdministrativo(User::fromToken()),
			'No tienes permiso para cambiar la imagen de otra persona.');
		
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