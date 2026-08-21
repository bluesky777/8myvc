<?php namespace App\Http\Controllers\Alumnos;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Periodo;



class OperacionesAlumnos {


	public function dividir_nombre($name)
	{
		$parts          = explode(' ', $name);
		$name_first     = array_shift($parts);
		$name_last      = trim(implode(' ', $parts));
		return ['first' => $name_first, 'last' => $name_last ];

	}



	public function recorrer_y_dividir_nombres(&$alumnos)
	{
		$cant = count($alumnos);

		for ($i=0; $i < $cant; $i++) { 
			$alumnos[$i]->nombres_divididos     = $this->dividir_nombre($alumnos[$i]->nombres);
			$alumnos[$i]->apellidos_divididos   = $this->dividir_nombre($alumnos[$i]->apellidos);

			if($alumnos[$i]->has_sisben){
				$alumnos[$i]->sisben = $alumnos[$i]->nro_sisben;
			}else{
				$alumnos[$i]->sisben = 'No aplica';
			}
			if($alumnos[$i]->has_sisben_3){
				$alumnos[$i]->sisben_3 = $alumnos[$i]->nro_sisben_3;
			}else{
				$alumnos[$i]->sisben_3 = 'No aplica';
			}
		}
		return $alumnos;
	}
	
	
	
	/**
	 * El nombre de usuario libre que le toca a una persona, a partir de su nombre.
	 *
	 * Es el generador que **de verdad se usa**: lo llaman el importador de alumnos
	 * (dos veces) y `acudientes/crear-usuario`. El de
	 * `perfiles/creartodoslosusuarios` —§12— no llegó a crear ninguna cuenta usable
	 * en años, porque moría en un `attachRole()` de Entrust antes de enlazar la
	 * ficha. Por eso los usernames mutilados que aquel fabricaba **no están en la
	 * base**: la medición del 21 ago 2026 da cero.
	 *
	 * Tres cosas se arreglan aquí, y la primera se ve en los datos:
	 *
	 * **El sufijo se acumulaba.** `$username = $username.$i` va sobre el candidato
	 * anterior y no sobre la base, así que a la quinta colisión `Samuel` no sale
	 * `Samuel5` sino `Samuel12345`. Está escrito en las cuentas de este colegio
	 * —`SamuelSamuel12345`, `MatíasMatías1234`— y es de las que se leen como un
	 * dato del alumno y no como lo que son. (La otra mitad de esos nombres, el
	 * nombre repetido, es de una importación de febrero de 2018 y no de este
	 * código: las 75 que hay tienen todas esa fecha.)
	 *
	 * **Con el nombre en blanco salía la cadena vacía**, y eso ya no es cosmético:
	 * `users.username` es UNIQUE y en la base hay una cuenta con el username vacío
	 * desde 2019, así que el segundo en blanco es un **error de clave duplicada**
	 * —500 en `acudientes/crear-usuario`, que no tiene `catch`—. De ahí el
	 * `$respaldo`, que el llamador arma con el id de la ficha.
	 *
	 * **Las tildes se transliteran**, como en la §12, para que el username sea
	 * escribible. No es un problema de acceso: `users.username` es
	 * `utf8mb4_unicode_ci`, o sea que la comparación **ignora las tildes** y
	 * `maria.beleno` ya entra en la cuenta de `maria.beleño`. Se hace por
	 * coherencia con el otro generador y porque un identificador con tilde acaba
	 * en sitios que no son MySQL — el correo autogenerado de la §9, sin ir más
	 * lejos. Los 113 usernames con tilde que ya existen **no se tocan**.
	 *
	 * @param  string|null  $respaldo  Qué username usar si del nombre no sale nada
	 *                                 (`'acudiente227'`). Sin él se cae a `usuario`,
	 *                                 que al menos colisiona y se numera en vez de
	 *                                 reventar.
	 */
	public function username_no_repetido($username_a_verificar, ?string $respaldo = null)
	{
		$base = filter_var(
			preg_replace('/\s+/', '', Str::ascii((string) $username_a_verificar)),
			FILTER_SANITIZE_EMAIL
		);

		if (! is_string($base) || $base === '') {
			$base = $respaldo !== null && trim($respaldo) !== '' ? $respaldo : 'usuario';
		}

		$candidato = $base;
		$i = 0;

		// La consulta no filtra `deleted_at`, y es correcto: el UNIQUE de
		// `users.username` tampoco lo hace, así que un usuario en la papelera
		// sigue ocupando su nombre.
		while (DB::select('SELECT username FROM users WHERE username=?', [$candidato]) !== []) {
			$i++;
			$candidato = $base.$i;
		}

		return $candidato;
	}

}