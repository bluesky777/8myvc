<?php namespace App\Http\Controllers\Tardanzas;


use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Support\Credenciales;


class TLoginController extends Controller {

	/**
	 * Quién manda usuario y contraseña en el cuerpo, comprobado y del colegio.
	 *
	 * Era el mismo bloque copiado tres veces, y las tres copias conservaban los
	 * dos respaldos que TSubirController ya había quitado **por escrito**: el
	 * primero comparaba la columna contra `Hash::make()` de la contraseña recién
	 * tecleada —inalcanzable, bcrypt lleva una sal distinta en cada llamada—, y
	 * el segundo la comparaba contra la contraseña EN CLARO y, si acertaba, la
	 * hasheaba en su sitio y dejaba entrar. Ése no es un respaldo, es una puerta:
	 * cualquier fila cuya columna `password` guardara texto plano entraba al
	 * lector con ese texto. Se quitan aquí por la misma decisión que allí; no es
	 * una nueva.
	 *
	 * Y la comprobación de tipo es lo que faltaba. TSubirController exigía ser
	 * del colegio para escribir y este fichero no exigía nada para leer, así que
	 * `traer-datos-ausencias` —que a diferencia de los otros dos métodos no tiene
	 * `switch` por tipo y por tanto no se rompía solo— entregaba a cualquier
	 * alumno con su propia clave TODAS las ausencias y tardanzas del colegio del
	 * año, y de cualquier año, porque el `year_id` lo elige el cuerpo. Ver
	 * docs/migracion/05-codigo-muerto-y-roto.md §25.
	 *
	 * Se admiten Profesor y Usuario, que son los dos que el `switch` de los otros
	 * dos métodos ya sabía servir: así no cambia nada de lo que hoy funciona.
	 * **No** se copia el `is_superuser` de TSubirController, que dejaría fuera a
	 * un Usuario administrativo que hoy sí entra a leer.
	 *
	 * Y el hash de `users.password` ya no sale en la respuesta. Estaba en los
	 * cuatro `SELECT` del `switch` y la §25.4 lo dejó **a propósito**, por miedo a
	 * apagar un aparato que trabaja sin red y que podría estar validando contra
	 * él estando desconectado. Se fue a mirar el cliente del lector antes de
	 * tocarlo: no lo usa —guarda la contraseña en claro y compara contra ella—,
	 * así que quitarlo no apaga nada. Decidido por Joseth el 21 ago 2026.
	 */
	private function usuarioAutenticado()
	{
		$credentials = [
			'username' => Request::input('username'),
			'password' => (string)Request::input('password')
		];

		// Era Auth::attempt() + Auth::user(). El guard `api` ya no es el de JWT
		// sino `sesion`, que resuelve al usuario del token de la petición y por
		// tanto no tiene attempt(): llamarlo devolvía 500. Aquí no hace falta un
		// guard —el lector manda usuario y contraseña en cada petición—, solo
		// comprobar la contraseña. Ver app/Support/Credenciales.php.
		$autenticado = Credenciales::verificar($credentials['username'], $credentials['password']);

		if ($autenticado === null) {
			if (Request::has('username') && Request::input('username') != '') {
				return abort(400, 'Credenciales inválidas.');
			}

			return abort(401, 'Por favor ingrese de nuevo.');
		}

		// 403 y no el 400 de TSubirController: es código nuevo, y ahí el 400 es
		// una respuesta que el lector ya recibe hoy. Cambiarlo no arregla nada y
		// se ve desde los dieciséis colegios.
		if ($autenticado->tipo !== 'Profesor' && $autenticado->tipo !== 'Usuario') {
			return abort(403, 'No tienes permiso');
		}

		return $autenticado;
	}


	public function postIndex()
	{

		$usuario 	= [];



		$userTemp = $this->usuarioAutenticado();



		$consulta = '';

		switch ($userTemp->tipo) {  // Alumno, Profesor, Acudiente, Usuario.
			case 'Profesor':
				
				$consulta = 'SELECT p.id as persona_id, p.nombres, p.apellidos, p.sexo, p.fecha_nac, p.user_id, u.username,
								IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,  
								per.id as periodo_id, per.numero as numero_periodo, per.year_id
							from profesores p 
							inner join users u on u.id=p.user_id 
							left join images i on i.id=:imagen_id
							left join periodos per on per.id=:periodo_id
							where p.deleted_at is null and p.user_id=:user_id';

				$usuario = DB::select($consulta, [
					':user_id'		=> $userTemp->id, 
					':imagen_id'	=> $userTemp->imagen_id, 
					':periodo_id'	=> $userTemp->periodo_id,
				]);
				
				break;



			case 'Usuario':
				
				$consulta = 'SELECT u.id as persona_id, "" as nombres, "" as apellidos, u.id as user_id, u.username, u.is_superuser, u.tipo, 
								u.sexo, u.email, "N/A" as fecha_nac, 
								u.imagen_id, IFNULL(i.nombre, IF(u.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
								per.id as periodo_id, per.numero as numero_periodo, per.year_id
							from users u
							left join periodos per on per.id=u.periodo_id
							left join images i on i.id=u.imagen_id 
							where u.id=:user_id and u.deleted_at is null';

				$usuario = DB::select($consulta, array(
					':user_id'		=> $userTemp->id
				));

				break;
		} 


		$usuario = (array)$usuario[0];
		$userTemp = (array)$userTemp['attributes'];
		$usuario = array_merge($usuario, $userTemp);
		$usuario = (object)$usuario;
				

		return json_decode(json_encode($usuario), true);
	}



	/*
	 * Aquí vivía `postTraerDatos()`, borrado el 2 sep 2026 por decisión de Joseth.
	 *
	 * **Con ruta y vivo, así que no cae bajo «sin ruta y roto se borra»: cae bajo una
	 * decisión del colegio**, y por eso se escribe quién la tomó. Servía a
	 * `POST tardanzas/login/traer-datos`, que se retira con él.
	 *
	 * Lo que se midió antes de proponerlo: **de los cuatro clientes, ninguno lo llama** —ni
	 * `app2`, ni el front legacy, ni `myvc_flutter`—. El único llamante de toda la máquina
	 * es `tardanzasMyvc-old/www/js/services/ConexionServ.js:425`, un quiosco AngularJS cuyo
	 * último commit es de **febrero de 2020** y que no aparece empaquetado en `myvc_dist`.
	 * Eso solo no bastaba —un cliente desplegado en 2020 puede seguir arrancando en la
	 * portería de un colegio—, y **el dato que lo cerró no estaba en el repositorio: Joseth
	 * confirmó que ese repositorio está inactivo**.
	 *
	 * **Y con él se va el arreglo de 78ce33d**, que nombraba las 68 columnas de `years` para
	 * que este método dejara de publicar `regla_nivelacion` en `$usuario->years`. No se
	 * deshizo: se lo lleva el borrado, que cierra la fuga de la única forma que no puede
	 * volver a abrirse. **`postTraerDatosAusencias` no tenía esa consulta** —comprobado, no
	 * supuesto—, así que no queda nada equivalente que arreglar en el fichero.
	 *
	 * **Se borra ESTE y sólo éste.** La decisión de Joseth fue «este endpoint», en singular.
	 * `postIndex` (`POST tardanzas/login`) y `postTraerDatosAusencias`
	 * (`POST tardanzas/login/traer-datos-ausencias`) siguen en pie, y el segundo lo llama el
	 * mismo quiosco muerto desde `ConexionServ.js:483`: si también se van, es otra decisión
	 * suya y de una en una.
	 */




	public function postTraerDatosAusencias()
	{

		$usuario 	= [];
		

		$userTemp = $this->usuarioAutenticado();


		$consulta = 'SELECT u.username, per.id as periodo_id, per.numero as numero_periodo, per.year_id
							from users u 
							inner join periodos per on per.id=u.periodo_id
							where u.deleted_at is null and u.id=:user_id';

		$usuario = DB::select($consulta, [
			':user_id'		=> $userTemp->id, 
		]);
				

		$usuario 	= (array)$usuario[0];
		$userTemp 	= (array)$userTemp['attributes'];
		$usuario 	= array_merge($usuario, $userTemp);
		$usuario 	= (object)$usuario;
				
		$year_id 	= $usuario->year_id;

		if (Request::has('year_id')) {
		 	$year_id 	= Request::input('year_id');
		} 


		// Ausencias
		$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.tipo, a.fecha_hora, a.uploaded, a.created_by FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.year_id=:year_id
					WHERE a.deleted_at is null;";
		$ausencias = DB::select($cons_aus, [":year_id" => $year_id]);

		return json_decode(json_encode($ausencias), true);
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



}