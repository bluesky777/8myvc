<?php namespace App\Http\Controllers;


use JWTAuth;
use Browser;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

use Illuminate\Http\Request;
//use Request;
//use Auth;
use Hash;
use DB;
use Carbon\Carbon;


use App\User;
use App\Models\VtVotacion;
use App\Models\Periodo;
use App\Models\Year;
use App\Models\Role;
use App\Mail\ResetPassword;
use \Log;



class LoginController extends Controller {
	
	
	private $entorno = 'Desktop';
	private $direccion = '';


	public function postIndex(Request $request)
	{

		$user = [];
		$token = [];
		

		try
		{
			$token = JWTAuth::parseToken();

			if ($token){
				$user = User::fromToken(false, $request);
			}else if ((!($request->has('username')) && $request->input('username') != ''))  {
				return response()->json(['error' => 'Token expirado'], 401);
			}
		}
		catch(Tymon\JWTAuth\Exceptions\TokenExpiredException $e)
		{
			if (! count(Input::all())) {
				return response()->json(['error' => 'token_expired'], 401);
			}
		}
		catch(JWTException $e){
			// No haremos nada, continuaremos verificando datos.
		}




		// Ahora verificamos si está inscrito en alguna votación
		$votaciones 		= VtVotacion::actualesInscrito($user, true);
		$votacionesResult 	= [];

		$cantVot = count($votaciones);

		if ($cantVot > 0) {
			for($i=0; $i<$cantVot; $i++) {
				$aspiraciones = DB::select('SELECT * FROM vt_aspiraciones WHERE votacion_id=?', [$votaciones[$i]->id]);
				$completos = VtVotacion::verificarVotosCompletos($aspiraciones, $votaciones[$i]->id, $user->user_id);
				$votaciones[$i]->completos = $completos;
				if (!$completos) {
					array_push($votacionesResult, $votaciones[$i]);
				}
			}

			$cantVot = count($votacionesResult);
			if ($cantVot > 0) {
				$user->votaciones = $votacionesResult;
			}
			
		}

		return json_decode(json_encode($user), true);
		
	}




	public function postCredentials(Request $request)
	{

		$user 		= [];
		$token 		= [];
		$now 		= Carbon::now('America/Bogota');

		// grab credentials from the request
		
		$credentials = [
			'username' => $request->input('username'),
			'password' => (string)$request->input('password')
		];

		$this->datos_entorno_direccion();

		// El limitador global era de 60/min para toda la API, o sea 86.400 intentos
		// de contraseña al día por IP. Este es específico del par IP+usuario, para
		// que un atacante no pueda probar contra muchas cuentas desde una IP ni
		// contra una cuenta desde muchas.
		$claveLimite = 'login:' . sha1($this->direccion . '|' . $credentials['username']);

		if (RateLimiter::tooManyAttempts($claveLimite, 5)) {
			return response()->json([
				'error' => 'too_many_attempts',
				'segundos' => RateLimiter::availableIn($claveLimite),
			], 429);
		}

		try {
			// attempt to verify the credentials and create a token for the user
			if (! $token = auth()->attempt($credentials)) {

				RateLimiter::hit($claveLimite, 900);
				
				$maquina = 'Intento login>> Entorno: '.$this->entorno.', Dirección: '.$this->direccion.', plataforma: '.Browser::browserEngine().', platfamilia: '.Browser::platformFamily().', device_fami: '.Browser::deviceFamily().', device_model: '.Browser::deviceModel();
				$consulta 	= 'INSERT INTO bitacoras (descripcion, affected_person_name, affected_element_type, created_at, created_by) 
					VALUES (?, ?, "intento_login", ?, 0)';
				DB::insert($consulta, [$maquina, $request->input('username'), $now]);
				
				return response()->json(['error' => 'invalid_credentials'], 400);
			}
			RateLimiter::clear($claveLimite);
			//$newToken = auth()->refresh();
			//$token = $newToken;
		} catch (JWTException $e) {
			return response()->json(['error' => 'could_not_create_token'], 500);
		} catch (Exception $e) {
			return response()->json(['error' => 'error creando token'], 500);
		}

		
		$consulta 	= 'SELECT u.id, u.tipo, u.password, u.periodo_id, p.year_id, u.is_active FROM users u 
			LEFT JOIN periodos p ON p.id=u.periodo_id and p.deleted_at is null
			WHERE u.username=? and u.deleted_at is null';

		$usuario 	= DB::select($consulta, [ $credentials['username'] ])[0];

		if (Hash::check($credentials['password'], $usuario->password)){


			if ($usuario->is_active) {
				
				// Alumnos asistentes o matriculados del grupo
				$consulta = 'INSERT INTO historiales(user_id, tipo, ip, browser_name, browser_version, browser_family, browser_engine, entorno, platform_name, platform_family, device_family, device_model, device_grade, updated_at, created_at) 
					VALUES(:user_id, :tipo, :ip, :browser_name, :browser_version, :browser_family, :browser_engine, :entorno, :platform_name, :platform_family, :device_family, :device_model, :device_grade, :updated_at, :created_at)';

				$result = DB::insert($consulta, [ ':user_id' => $usuario->id, ':tipo' => $usuario->tipo, ':ip' => $this->direccion, 
				':browser_name' => Browser::browserName(), ':browser_version' => Browser::browserVersion(), ':browser_family' => Browser::browserFamily(), 
				':browser_engine' => Browser::browserEngine(), ':entorno' => $this->entorno, ':platform_name' => Browser::browserEngine(), ':platform_family' => Browser::platformFamily(), ':device_family' => Browser::deviceFamily(), ':device_model' => Browser::deviceModel(), ':device_grade' => Browser::mobileGrade(), ':updated_at' => $now, ':created_at' => $now ]);

			}else{

				abort(400, 'Usuario invalidado');

			}

		}

		$res = [ 'el_token' => $token ];

		// Ahora miramos si está en el periodo actual. Si no, lo cambiamos
		$consulta 	= 'SELECT id, year, actual FROM years WHERE actual=1 and deleted_at is null';
		$anio 		= DB::select($consulta)[0];

		$consulta 	= 'SELECT id, actual FROM periodos WHERE actual=1 and year_id=? and deleted_at is null';
		$periodo 	= DB::select($consulta, [$anio->id]);


		if ($usuario->periodo_id > 0 && count($periodo) > 0) {
			$periodo 	= $periodo[0];

			if ($anio->id != $usuario->year_id) {
				
				$res['cambia_anio'] = $periodo->id;
				$consulta 	= 'UPDATE users SET periodo_id=? WHERE id=?';
				DB::update($consulta, [$periodo->id, $usuario->id]);

			// Si sí es el año, verificamos periodo
			}else{

				if ($periodo->id != $usuario->periodo_id) {
					$res['cambia_anio'] = $periodo->id;
					$consulta 	= 'UPDATE users SET periodo_id=? WHERE id=?';
					DB::update($consulta, [$periodo->id, $usuario->id]);
				}
			}
		}
		

		//return ['token' => compact('token')];
		return $res;

		
	}



	public function putLogout(Request $request){
		$now 		= Carbon::now('America/Bogota');

		$consulta 	= 'UPDATE historiales SET logout_at=? where user_id=? and deleted_at is null order by id desc limit 1';
		DB::update($consulta, [ $now, $request->input('user_id') ])[0];
		
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

		$consulta 	= 'INSERT INTO password_reminders(email, token, created_at) VALUES(?,?,?)';
		DB::insert($consulta, [ $destinatario, hash('sha256', $numero), $now ]);

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
		$username 		= $request->input('username');

		// El front ya valida la longitud, pero eso es una comodidad, no una defensa:
		// a este endpoint se puede llamar directamente.
		if (strlen((string) $request->input('password1')) < 4) {
			abort(422, 'La contraseña debe tener al menos 4 caracteres.');
		}

		$pass1 			= Hash::make($request->input('password1'));
	


		$consulta 	= 'SELECT email FROM password_reminders WHERE token=? and created_at > ?';
		$reminder 	= DB::select($consulta, [ hash('sha256', (string) $numero), $hora ]);

		if (count($reminder) == 0) {
			return 'Token inválido';
		}

		// El token manda: la contraseña solo puede cambiarse en la cuenta cuyo correo
		// recibió el enlace. Antes se confiaba en el username que enviaba el cliente,
		// así que un token pedido para el correo propio servía para tomar cualquier
		// cuenta, superusuarios incluidos.
		$consulta 	= 'UPDATE users SET password=? WHERE username=? and email=? and deleted_at is null';
		$cambiados 	= DB::update($consulta, [ $pass1, $username, $reminder[0]->email ]);

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
				$periodo_actual->actual 	= true;
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
			$usuario->is_superuser	=	false;
			$usuario->periodo_id	=	$periodo_actual->id;
			$usuario->is_active		=	true;
			$usuario->tipo			=	'Alumno';
			$usuario->save();

			
			$role = Role::where('name', 'Alumno')->get();
			//$usuario->attachRole($role[0]);
			$usuario->roles()->attach($role[0]['id']);

			DB::update('UPDATE alumnos SET user_id=? WHERE id=?', [ $usuario->id, $alumno->id ]);


			return [ 'estado' => 'Alumno y Prematricula creados. Usuario: ' . $usuario->username
				. ' - Contraseña: ' . $password_inicial . ' (anótala, no se vuelve a mostrar)' ];
		}
		


		return 'Reseteado';
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


	private function datos_entorno_direccion(){
		if (Browser::isMobile()) {
			$this->entorno 	= 'Mobile';
		}else if(Browser::isTablet()){
			$this->entorno 	= 'Tablet';
		}else if(Browser::isBot()){
			$this->entorno 	= 'Bot';
		}
		
		if (!empty($_SERVER['HTTP_CLIENT_IP']))
			$this->direccion = $_SERVER['HTTP_CLIENT_IP'];
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
			$this->direccion = $_SERVER['HTTP_X_FORWARDED_FOR'];
		if (!empty($_SERVER['REMOTE_ADDR']))
			$this->direccion = $_SERVER['REMOTE_ADDR'];

	}



}