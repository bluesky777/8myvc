<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

use Illuminate\Http\Request;
//use Request;
//use Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


use App\User;
use App\Services\Login;
use App\Services\Sesion;
use App\Services\VotacionesPendientes;
use App\Models\VtVotacion;
use App\Models\Periodo;
use App\Models\Year;
use App\Models\Role;
use App\Mail\ResetPassword;
use \Log;



class LoginController extends Controller {
	
	
	/**
	 * El contexto del usuario del token.
	 *
	 * Sin token responde 200 con el cuerpo vacío, y eso es de siempre: la ruta
	 * está fuera del guard porque el frontend la llama antes de saber si tiene
	 * sesión. Con token inválido o caducado responde 401, como todas.
	 *
	 * Antes decidía si había token con `JWTAuth::parseToken()`, que con un token
	 * de Sanctum lanza excepción: la ruta habría empezado a contestar el cuerpo
	 * vacío —200, sin datos— a todo el que entrara con la Fase 3 puesta. Ahora
	 * lo decide App\Services\Sesion, que entiende los dos formatos.
	 */
	public function postIndex(Request $request)
	{
		if (app(Sesion::class)->tokenPlanoDe($request) === null) {
			return [];
		}

		$user = User::fromToken(false, $request);

		$user = app(VotacionesPendientes::class)->adjuntarA($user);

		return json_decode(json_encode($user), true);
	}




	/**
	 * La entrada de siempre: devuelve `{ el_token }` y nada más.
	 *
	 * Se mantiene tal cual porque cada colegio despliega su propio front, y
	 * durante un tiempo habrá colegios con el backend de la Fase 3 y el front
	 * de antes. Ese front no conoce `/api/auth/login` ni sabría qué hacer con
	 * un refresco, así que aquí se emite un solo token, y largo (24 h, lo que
	 * duraba el JWT) para que su sesión aguante lo mismo que aguantaba.
	 *
	 * Lo único que cambia es qué hay dentro del token. Antes era un JWT; ahora
	 * es uno de Sanctum, que sí se puede revocar — o sea que desde ya, cerrar
	 * sesión desde el front viejo también lo mata de verdad.
	 *
	 * El trámite (contraseña, límite de intentos, historial, periodo) está en
	 * App\Services\Login, compartido con la ruta nueva.
	 */
	public function postCredentials(Request $request)
	{
		$entrada = app(Login::class)->entrar($request);

		$res = [ 'el_token' => app(Sesion::class)->abrirLegado($entrada['usuario']) ];

		if ($entrada['cambia_anio'] !== null) {
			$res['cambia_anio'] = $entrada['cambia_anio'];
		}

		return $res;
	}




	/**
	 * Marca la hora de salida en el historial.
	 *
	 * Se deja a propósito SIN guard de autenticación y sin resolver al usuario:
	 * cerrar sesión con el token ya caducado tiene que funcionar. Si devolviera
	 * 401, el frontend no podría limpiar su estado y el usuario se quedaría
	 * atrapado en una sesión que ya no vale.
	 *
	 * Es idempotente: si no hay historial que cerrar, no pasa nada. Y borra el
	 * token de Sanctum, que es lo que hace que cerrar sesión deje de ser
	 * cosmético. Los JWT viejos no se pueden revocar —esa es justo la razón de
	 * la Fase 3—, así que con uno de esos solo se apunta la salida, como antes.
	 *
	 * El usuario sale del TOKEN, no del cuerpo de la petición. Antes llegaba como
	 * `user_id`, así que cualquiera podía falsificar el cierre de sesión de otro
	 * sabiendo su id — no lo echaba del sistema, pero corrompía el historial de
	 * accesos, que es justo lo que se mira cuando hay que reconstruir qué pasó.
	 *
	 * La sesión de myvc_front confirmó (18 ago 2026) que la cabecera Authorization
	 * viaja en esta llamada: AngularJS copia las cabeceras por defecto de forma
	 * síncrona al construir la petición (angular.js:13053), antes de que el propio
	 * `logout()` borre el default. O sea que el token está aquí aunque el front lo
	 * borre acto seguido.
	 */
	public function putLogout(Request $request){
		$now 		= Carbon::now('America/Bogota');

		// Del Request no se lee nada salvo el token. El `user_id` que mandaba el
		// frontend se sigue ignorando: cualquiera podía falsificar el cierre de
		// sesión de otro sabiendo su id.
		$sesion = app(Sesion::class);
		$token  = $sesion->tokenDe($request, true);

		if ($token !== null) {
			$userId = (int) $token->tokenable_id;

			// Esto es la Fase 3 en una línea. Hasta ahora cerrar sesión solo
			// escribía la hora en `historiales` y el JWT seguía valiendo 24 h:
			// quien copiara el token —o quien se sentara después en el equipo
			// compartido de la sala de profesores— seguía entrando. Ahora la
			// fila se borra y el token muere en el acto, junto con el refresco
			// de la misma sesión.
			$sesion->cerrar($token);
		} else {
			// Sin token identificable no hay sesión que registrar. Aquí caía
			// antes el camino de los JWT, que se decodificaban para sacar el
			// `sub` sin mirar la expiración. Se fue con el paquete.
			$userId = null;
		}

		// Sin token identificable no hay sesión que registrar. Se responde igual:
		// el front tiene que poder limpiar su estado pase lo que pase aquí.
		if ($userId !== null) {
			$consulta = 'UPDATE historiales SET logout_at=? where user_id=? and deleted_at is null order by id desc limit 1';

			// Antes esto acababa en `[0]`. DB::update() devuelve un entero —las
			// filas afectadas—, y aplicarle un índice reventaba: "Trying to
			// access array offset on value of type int". O sea que el logout
			// devolvía 500 SIEMPRE, también con un user_id válido.
			//
			// Estaba así desde el import de 2021 y pasó desapercibido porque
			// hasta PHP 7.3 indexar un entero devolvía null en silencio. Desde
			// 7.4 es un warning, y Laravel los convierte en excepción: se rompió
			// solo al subir de versión, sin que nadie tocara el fichero.
			DB::update($consulta, [ $now, $userId ]);
		}

		return 'Deslogueado';
	}


	/**
	 * Envía el correo con el enlace para restablecer la contraseña.
	 *
	 * Se llamaba `postVerPass` y la ruta `login/ver-pass`. El nombre engañaba:
	 * no muestra ninguna contraseña — genera un token de un solo uso, guarda su
	 * hash y manda el enlace por correo. La ruta vieja sigue existiendo como
	 * alias mientras el frontend de cada colegio se actualiza.
	 */
	public function postRecuperarClave(Request $request){
		$now 			= Carbon::now('America/Bogota');
		$hora 			= Carbon::now('America/Bogota')->subHour(); 
		$destinatario 	= (string) $request->input('email');

		if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
			abort(422, 'Correo inválido.');
		}

		// Se resuelve antes de tocar la BD para no dejar un token huérfano si falla.
		$ruta_base 	= $this->ruta_frontend_segura($request);
		// rand() no es criptográficamente seguro y el token se guardaba en claro.
		// Ahora se genera con el CSPRNG y en la tabla solo queda su hash, así que
		// un volcado de la BD no permite resetear nada.
		$numero 		= bin2hex(random_bytes(32));
		
		$username 		= '';

		$consulta 	= 'SELECT * FROM users WHERE email = ? and deleted_at is null and is_active=1';
		$persona 	= DB::select($consulta, [ $destinatario ]);

		if (count($persona) > 0) {
			$persona 	= $persona[0];
			$username 	= $persona->username;
		}else{

			$consulta 	= 'SELECT u.username FROM alumnos a INNER JOIN users u ON u.id=a.user_id and u.deleted_at is null and u.is_active=1 WHERE u.email = ? and a.deleted_at is null';
			$persona 	= DB::select($consulta, [ $destinatario ]);

			if (count($persona) > 0) {
				$persona 	= $persona[0];
				$username 	= $persona->username;
			}else{

				$consulta 	= 'SELECT u.username FROM profesores p INNER JOIN users u ON u.id=p.user_id and u.deleted_at is null and u.is_active=1 WHERE u.email = ? and p.deleted_at is null';
				$persona 	= DB::select($consulta, [ $destinatario ]);

				if (count($persona) > 0) {
					$persona 	= $persona[0];
					$username 	= $persona->username;
				}else{
					
					$consulta 	= 'SELECT u.username FROM acudientes a INNER JOIN users u ON u.id=a.user_id and u.deleted_at is null and u.is_active=1 WHERE u.email = ? and a.deleted_at is null';
					$persona 	= DB::select($consulta, [ $destinatario ]);

					if (count($persona) > 0) {
						$persona 	= $persona[0];
						$username 	= $persona->username;
					}else{

						// Antes esto devolvía 'No existe', y con eso cualquiera podía
						// averiguar si un correo está registrado en el colegio probando
						// uno a uno. Ahora la respuesta es la misma exista o no: quien
						// pregunta no aprende nada que no supiera.
						//
						// No se crea token ni se manda correo — no hay a quién.
						Log::info('Reseteo pedido para un correo que no está registrado.');

						return 'Enviado';
					}

				}

			}

		}

		// Se purga lo caducado y los tokens previos de este correo: solo puede haber
		// uno vigente a la vez. Antes la tabla no se limpiaba nunca (1.620 filas
		// acumuladas desde 2018, ninguna vigente).
		DB::delete('DELETE FROM password_reminders WHERE created_at <= ? OR email = ?', [ $hora, $destinatario ]);

		// El username se guarda **aquí**, que es el único momento en que se sabe de
		// verdad a quién va el enlace. Antes se calculaba, se metía en la URL del
		// correo y se tiraba, así que al canjear el token había que creerse el que
		// mandara el cliente — y con eso un enlace abría cualquier cuenta que
		// compartiera el correo. Ver docs/migracion/12-larastan-nivel-7.md §8.
		$consulta 	= 'INSERT INTO password_reminders(email, username, token, created_at) VALUES(?,?,?,?)';
		DB::insert($consulta, [ $destinatario, $username, hash('sha256', $numero), $now ]);

		$ruta 		= $ruta_base . '#!/reset-password/'.$numero.'/'.$username;

		// mail() con cabeceras construidas a mano permitía inyección a través del
		// destinatario. Ahora va por el Mail de Laravel.
		//
		// OJO: mail() usaba el sendmail del sistema y fallaba en SILENCIO; esta ruta
		// devolvía 'Enviado' aunque no saliera nada. Mail sí lanza excepción, así que
		// el .env de producción tiene que tener MAIL_* correcto. Si allí funcionaba
		// por sendmail, MAIL_MAILER=sendmail reproduce el comportamiento anterior.
		try {
			Mail::to($destinatario)->send(new ResetPassword($username, $ruta));
		} catch (\Throwable $e) {
			// Si el correo no sale, el token no debe quedarse vivo una hora.
			DB::delete('DELETE FROM password_reminders WHERE email = ?', [ $destinatario ]);
			Log::error('Fallo enviando el correo de reseteo a ' . $destinatario . ': ' . $e->getMessage());

			abort(500, 'No se pudo enviar el correo. Inténtalo más tarde.');
		}
		
		
		
		return 'Enviado';
	}




	public function putResetPassword(Request $request){
		$now 			= Carbon::now('America/Bogota');
		$hora 			= Carbon::now('America/Bogota')->subHour(); 

		$numero 		= $request->input('numero');

		// El cuerpo sigue trayendo un `username` —el enlace del correo lo lleva y el
		// front lo reenvía—, y **no se lee a propósito**. No se borra de la línea de
		// arriba porque nunca estuvo ahí: se quita de aquí para que se vea que la
		// decisión es ignorarlo, y no un descuido al que alguien le añada un `if`.

		// El front ya valida la longitud, pero eso es una comodidad, no una defensa:
		// a este endpoint se puede llamar directamente.
		if (strlen((string) $request->input('password1')) < 4) {
			abort(422, 'La contraseña debe tener al menos 4 caracteres.');
		}

		$pass1 			= Hash::make($request->input('password1'));
	


		$consulta 	= 'SELECT email, username FROM password_reminders WHERE token=? and created_at > ?';
		$reminder 	= DB::select($consulta, [ hash('sha256', (string) $numero), $hora ]);

		if (count($reminder) == 0) {
			return 'Token inválido';
		}

		// El token manda del todo: el usuario sale de la fila del token, no del
		// cuerpo. El `$username` que manda el cliente **se ignora**, no se compara —
		// compararlo dejaría el mismo agujero con un paso más.
		//
		// Antes aquí se filtraba por `username=? and email=?` con el username del
		// cuerpo. Eso cerraba «cualquier cuenta del colegio» y dejaba abierto
		// «cualquier cuenta con tu mismo correo», que es un agujero más pequeño y
		// menos visible. 12-larastan-nivel-7.md §8.
		//
		// Un token emitido ANTES de la migración no tiene username, y entonces no se
		// puede saber a quién iba: se rechaza. Caducan en una hora, así que el coste
		// es que quien pidiera el enlace justo antes del despliegue lo pida otra vez.
		if ($reminder[0]->username === null || $reminder[0]->username === '') {
			return 'Token inválido';
		}

		$consulta 	= 'UPDATE users SET password=? WHERE username=? and email=? and deleted_at is null';
		$cambiados 	= DB::update($consulta, [ $pass1, $reminder[0]->username, $reminder[0]->email ]);

		if ($cambiados === 0) {
			return 'Token inválido';
		}

		$consulta 	= 'DELETE FROM password_reminders WHERE token=?';
		DB::delete($consulta, [ hash('sha256', (string) $numero) ]);
		


		return 'Reseteado';
	}





	public function putCrearPrematricula(Request $request){
		$now 			= Carbon::now('America/Bogota');

		$nombres 		= $request->input('nombres');
		$apellidos 		= $request->input('apellidos');
		$sexo 			= $request->input('sexo');
		$documento 		= $request->input('documento');
		$celular 		= $request->input('celular');
		$grupo_id 		= $request->input('grupo_id');
		$anio 			= $request->input('year');
		$estado 		= 'PREA';
	



		$consulta 	= 'SELECT id, nombres FROM alumnos WHERE nombres=? and apellidos=? and documento=?';
		$alumno 	= DB::select($consulta, [ $nombres, $apellidos, $documento ]);

		if (count($alumno) > 0) {
			$alumno = $alumno[0];

			$consulta 	= 'SELECT m.id, estado FROM matriculas m 
				INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null
				INNER JOIN years y ON y.id=g.year_id and y.deleted_at is null
				WHERE alumno_id=? and year=?';

			$matri = DB::select($consulta, [ $alumno->id, $anio ]);

			if (count($matri) > 0) {
				if ($matri[0]->estado == 'PREA') {
					// SI el padre fue quien lo matriculó, podemos cambiar el grupo.
					DB::update('UPDATE matriculas SET alumno_id=?, grupo_id=?, estado=?, updated_at=? WHERE id=?', [$alumno->id, $grupo_id, $estado, $now, $matri[0]->id]);
					return [ 'estado' => 'Prematriculado previamente. Cambiado el grupo' ];
				}else{
					// Si NO fue el padre quien lo matriculó, no puede cambiar el grupo.
					return [ 'estado' => 'No puede cambiar el grupo de este alumno. Debe acercarse a Secretaría.' ];
				}
				
			}else{
				// Existe el alumno, pero no está prematriculado en ese año
				/*
				$consulta 	= 'INSERT INTO matriculas(alumno_id, grupo_id, estado, created_at, updated_at) VALUES(?,?,?,?,?)';
				DB::insert($consulta, [$alumno->id, $grupo_id, $estado, $now, $now]);
				*/
				return [ 'estado' => 'Ya existe el alumno. Entre con su cuenta para poder prematricularc correctamente' ];

			}

		} else {

			$consulta 	= 'INSERT INTO alumnos(nombres, apellidos, sexo, documento, celular, tipo_doc, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)';
			DB::insert($consulta, [$nombres, $apellidos, $sexo, $documento, $celular, 3, $now, $now]);

			$last_id 	= DB::getPdo()->lastInsertId();

			$consulta 	= 'SELECT id, nombres FROM alumnos WHERE id=?';
			$alumno 	= DB::select($consulta, [ $last_id ]);
	
			$alumno 	= $alumno[0];

			$consulta 	= 'INSERT INTO matriculas(alumno_id, grupo_id, estado, nuevo, created_at, updated_at) VALUES(?,?,?,1,?,?)';
			DB::insert($consulta, [$alumno->id, $grupo_id, $estado, $now, $now]);

			// Para crear el usuario, necesitamos periodo actual y roles
			$yearactual = Year::actual();
			$periodo_actual = Periodo::where('actual', true)
									->where('year_id', $yearactual->id)->first();

			if (!is_object($periodo_actual)) {
				$periodo_actual = Periodo::where('year_id', $yearactual->id)->first();
				$periodo_actual->actual 	= 1;
				$periodo_actual->updated_by = 0; // endpoint público: no hay usuario autenticado
				$periodo_actual->save();
			}

			// Antes era Hash::make('123456') para todas las cuentas creadas por este
			// endpoint, que además es público: con adivinar el patrón del username se
			// entraba. Ahora es aleatoria y se devuelve para poder entregarla.
			$password_inicial = self::password_legible();

			$usuario = new User;
			$usuario->username		=	$alumno->nombres . rand(99, 999);
			$usuario->password		=	Hash::make($password_inicial);
			$usuario->sexo			=	$sexo;
			$usuario->is_superuser	=	0;
			$usuario->periodo_id	=	$periodo_actual->id;
			$usuario->is_active		=	1;
			$usuario->tipo			=	'Alumno';
			$usuario->save();

			
			$role = Role::where('name', 'Alumno')->get();
			//$usuario->attachRole($role[0]);
			$usuario->roles()->attach($role[0]['id']);

			DB::update('UPDATE alumnos SET user_id=? WHERE id=?', [ $usuario->id, $alumno->id ]);


			return [ 'estado' => 'Alumno y Prematricula creados. Usuario: ' . $usuario->username
				. ' - Contraseña: ' . $password_inicial . ' (anótala, no se vuelve a mostrar)' ];
		}
	}






	/**
	 * Contraseña aleatoria que un padre pueda teclear sin equivocarse:
	 * sin caracteres ambiguos (l/1/I, O/0) y sin símbolos.
	 */
	private static function password_legible($largo = 10)
	{
		$alfabeto = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$max = strlen($alfabeto) - 1;
		$password = '';

		for ($i = 0; $i < $largo; $i++) {
			$password .= $alfabeto[random_int(0, $max)];
		}

		return $password;
	}


	function default_image_id($sexo)
	{
		if ($sexo == 'F') {
			return 2;
		}else{
			return 1; // ID de la imagen masculina
		}
	}
	function default_image_name($sexo)
	{
		if ($sexo == 'F') {
			return 'default_female.png';
		}else{
			return 'default_male.png';
		}
	}
	
	
	/**
	 * Base del enlace de reseteo que se envía por correo.
	 *
	 * Antes se usaba tal cual lo que mandara el cliente en 'ruta', así que un
	 * atacante podía pedir un reseteo para la víctima con ruta a su propio sitio:
	 * el correo salía legítimo, desde este dominio, con el token dentro de una URL
	 * que apuntaba a él.
	 *
	 * El frontend se sirve del mismo host que la API, así que exigir que coincidan
	 * cierra el agujero sin necesidad de configurar nada.
	 */
	private function ruta_frontend_segura(Request $request)
	{
		$enviada = (string) $request->input('ruta');

		if ($enviada !== '' && parse_url($enviada, PHP_URL_HOST) === $request->getHost()) {
			return $enviada;
		}

		$configurada = config('app.frontend_url');

		if ($configurada) {
			return rtrim($configurada, '/') . '/';
		}

		abort(422, 'Ruta de retorno no permitida.');
	}





}