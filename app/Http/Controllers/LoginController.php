<?php namespace App\Http\Controllers;


use JWTAuth;
use Browser;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

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
		try {
			// attempt to verify the credentials and create a token for the user
			if (! $token = auth()->attempt($credentials)) {
				
				$maquina = 'Intento login>> Entorno: '.$this->entorno.', Dirección: '.$this->direccion.', plataforma: '.Browser::browserEngine().', platfamilia: '.Browser::platformFamily().', device_fami: '.Browser::deviceFamily().', device_model: '.Browser::deviceModel();
				$consulta 	= 'INSERT INTO bitacoras (descripcion, affected_person_name, affected_element_type, created_at, created_by) 
					VALUES (?, ?, "intento_login", ?, 0)';
				DB::insert($consulta, [$maquina, $request->input('username'), $now]);
				
				return response()->json(['error' => 'invalid_credentials'], 400);
			}
			//$newToken = auth()->refresh();
			Log::info($token);
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





	public function postVerPass(Request $request){
		$now 			= Carbon::now('America/Bogota');
		$hora 			= Carbon::now('America/Bogota')->subHour(); 
		$destinatario 	= $request->input('email');
		$numero 		= rand(100000, 9999999999999999);
		
		$username 		= '';

		$consulta 	= 'INSERT INTO password_reminders(email, token, created_at) VALUES(?,?,?)';
		DB::insert($consulta, [ $destinatario, $numero, $now ]);


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
						return 'No existe';
					}

				}

			}

		}

		$ruta 		= $request->input('ruta') . '#!/reset-password/'.$numero.'/'.$username;

        $asunto = "Ver contraseña Mi Colegio Virtual";
        $cuerpo = '
        <style>
			/* Shrink Wrap Layout Pattern CSS */
			@media only screen and (max-width: 599px) {
				td[class="hero"] img {
					width: 100%;
					height: auto !important;
				}
				td[class="pattern"] td{
					width: 100%;
				}
			}
		</style>

		<table cellpadding="0" cellspacing="0">
			<tr>
				<td class="pattern" width="600">
					<table cellpadding="0" cellspacing="0">
						<tr>
							<td class="hero">
								<img src="https://lalvirtual.edu.co/up/images/Logo_MyVc_Header.gif" alt="Mi Colegio Virtual" style="display: block; border: 0;" />
							</td>
						</tr>
						<tr>
							<td align="left" style="font-family: arial,sans-serif; color: #333;">
								<h1>My Virtual College</h1>
							</td>
						</tr>
						<tr>
							<td align="left" style="font-family: arial,sans-serif; font-size: 14px; line-height: 20px !important; color: #666; padding-bottom: 20px;">
								Has solicitado resetear tu contraseña. Si es así, presiona botón de abajo. De lo contrario, puedes ignorar este mensaje. Este link sólo será válido durante una hora. Tu usuario es <b>'.$username.'</b>
							</td>
						</tr>
						<tr>
							<td align="left">
								<a href="'.$ruta.'"><img src="http://placehold.it/200x50/333&text=Resetear" alt="Resetear" style="display: block; border: 0;" /></a>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
        ';
        
        //para el envío en formato HTML
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";

        //dirección del remitente
        
        $headers .= "From: MiColegioVirtual <josethmaster@lalvirtual.com>\r\n";

        //ruta del mensaje desde origen a destino
        $headers .= "Return-path: josethmaster@lalvirtual.com\r\n";

		mail($destinatario,$asunto,$cuerpo,$headers);
		
		
		
		return 'Enviado';
	}




	public function putResetPassword(Request $request){
		$now 			= Carbon::now('America/Bogota');
		$hora 			= Carbon::now('America/Bogota')->subHour(); 

		$numero 		= $request->input('numero');
		$pass1 			= Hash::make($request->input('password1'));
		$username 		= $request->input('username');
	


		$consulta 	= 'SELECT * FROM password_reminders WHERE token=? and created_at > ?';
		$reminder 	= DB::select($consulta, [ $numero, $hora ]);

		if (count($reminder) > 0) {
			$reminder = $reminder[0];

			$consulta 	= 'UPDATE users SET password=? WHERE username = ?';
			DB::update($consulta, [ $pass1, $username ]);


			$consulta 	= 'DELETE FROM password_reminders WHERE token=?';
			DB::delete($consulta, [ $numero ]);


		} else {
			return 'Token inválido';
		}
		


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
				$periodo_actual->updated_by = $this->user->user_id;
				$periodo_actual->save();
			}

			$usuario = new User;
			$usuario->username		=	$alumno->nombres . rand(99, 999);
			$usuario->password		=	Hash::make('123456');
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


			return [ 'estado' => 'Alumno y Prematricula creados' ];
		}
		


		return 'Reseteado';
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