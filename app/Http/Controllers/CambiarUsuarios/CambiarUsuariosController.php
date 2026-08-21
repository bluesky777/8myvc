<?php namespace App\Http\Controllers\CambiarUsuarios;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use \Log;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;


class CambiarUsuariosController extends Controller {
	use ResuelveElUsuario;

	/**
	 * La clave nueva, exigida.
	 *
	 * Sin esto, una llamada sin `clave` —o con la cadena vacía— llegaba a
	 * `Hash::make(null)` y dejaba a los 1.280 alumnos del colegio con el hash de
	 * la cadena vacía. Medido: `login/credentials` con la contraseña vacía
	 * responde 200 detrás de eso. Y la ruta hermana pone el número de documento
	 * como nombre de usuario, así que las dos juntas dejan el colegio entero
	 * abierto a quien tenga una lista de documentos.
	 *
	 * No es una hipótesis sobre un cliente descuidado: **ningún cliente llama a
	 * estas cuatro rutas** —comprobado en los tres—, así que quien las use las
	 * escribe a mano.
	 *
	 * Solo exige que venga y que no esté vacía. Cuánto debe medir o qué forma
	 * debe tener es una política del colegio y no se inventa aquí.
	 */
	private function claveNueva(): string
	{
		$clave = Request::input('clave');

		if (! is_string($clave) || $clave === '') {
			abort(422, 'Falta la clave nueva.');
		}

		return $clave;
	}


	public function putPonerDocumentoComoUsernameAlumnos()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores. Mismo criterio que la papelera de
		// grupos y profesores, que es la misma clase de operación: de colegio, no
		// de aula. Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$consulta = 'UPDATE IGNORE users u 
			INNER JOIN alumnos a ON a.user_id=u.id and a.deleted_at is null and u.tipo="Alumno"
			SET u.username=a.documento
			WHERE a.documento>0 and a.documento is not null and a.documento!="" and u.deleted_at is null';
		
		$res = DB::select($consulta);
		
		return [ 'resultado' => 'Usernames cambiados.' ];
	}



	public function putPonerDocumentoComoUsernameAcudientes()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores. Mismo criterio que la papelera de
		// grupos y profesores, que es la misma clase de operación: de colegio, no
		// de aula. Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$consulta = 'UPDATE IGNORE users u 
			INNER JOIN acudientes a ON a.user_id=u.id and a.deleted_at is null and u.tipo="Acudiente"
			SET u.username=a.documento
			WHERE a.documento>0 and a.documento is not null and a.documento!="" and u.deleted_at is null';
		
		$res = DB::select($consulta);
		
		return [ 'resultado' => 'Usernames cambiados.' ];
	}



	public function putPonerPasswordTodosAlumnos()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores. Mismo criterio que la papelera de
		// grupos y profesores, que es la misma clase de operación: de colegio, no
		// de aula. Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$password   = Hash::make($this->claveNueva());
		$consulta   = 'UPDATE users SET password=:texto WHERE tipo="Alumno";';
		
		DB::update($consulta, [
			':texto'		=> $password
		]);
		
		return 'Contraseñas alumnos cambiadas';
	}


	public function putPonerPasswordTodosAcudientes()
	{
		// Las cuatro rutas reescriben la cuenta de TODOS los alumnos o de todos
		// los acudientes del colegio. Con `auth.personal` a secas las disparaba
		// cualquiera de los 51 profesores. Mismo criterio que la papelera de
		// grupos y profesores, que es la misma clase de operación: de colegio, no
		// de aula. Ver app/Support/Autoriza.php.
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede cambiar las cuentas de todo el colegio.');

		$password   = Hash::make($this->claveNueva());
		$consulta   = 'UPDATE users SET password=:texto WHERE tipo="Acudiente";';
		
		DB::update($consulta, [
			':texto'		=> $password
		]);
		
		return 'Contraseñas acudientes cambiadas';
	}


	
}