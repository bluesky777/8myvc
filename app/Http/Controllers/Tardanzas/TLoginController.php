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



	public function postTraerDatos()
	{

		$usuario 	= [];
		
		$userTemp = $this->usuarioAutenticado();



		$consulta = '';

		switch ($userTemp->tipo) {  // Alumno, Profesor, Acudiente, Usuario.
			case 'Profesor':
				
				$consulta = 'SELECT p.id as persona_id, p.nombres, p.apellidos, p.sexo, p.fecha_nac, p.user_id, u.username,
								IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,  
								per.id as periodo_id, per.numero as numero_periodo, per.year_id, y.year
							from profesores p 
							inner join users u on u.id=p.user_id 
							left join images i on i.id=:imagen_id
							left join periodos per on per.id=:periodo_id
							inner join years y on y.id=per.year_id
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
								per.id as periodo_id, per.numero as numero_periodo, per.year_id, y.year
							from users u
							left join periodos per on per.id=u.periodo_id
							inner join years y on y.id=per.year_id
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
				
		$year_id 	= $usuario->year_id;
		$anio 		= $usuario->year;

		if (Request::has('year_id')) {
		 	$year_id 	= Request::input('year_id');
		} 

		// Alumnos
		$cons_alum = "SELECT a.id, a.nombres, a.apellidos, sexo, user_id, a.fecha_nac, a.religion, a.pazysalvo, a.deuda 
			from alumnos a 
			inner join matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado='MATR' or m.estado='ASIS' or m.estado='PREM')
			inner join grupos g ON m.grupo_id=g.id and g.deleted_at is null
			inner join years y ON g.year_id=y.id and y.year=? and y.deleted_at is null
			WHERE a.deleted_at is null";
		$alumnos = DB::select($cons_alum, [$anio]);


		// Periodos
		$cons_per = "SELECT p.* FROM periodos p inner join years y ON p.year_id=y.id and y.year=? and y.deleted_at is null";
		$periodos = DB::select($cons_per, [$anio]);


		// Matriculas
		$cons_matri = "SELECT m.id, m.alumno_id, m.grupo_id, m.estado, g.nombre as nombre_grupo, g.abrev, g.year_id 
					FROM matriculas m
					inner join grupos g on g.id=m.grupo_id and (m.estado='MATR' or m.estado='ASIS' or m.estado='PREM')
					inner join years y ON g.year_id=y.id and y.year=? and y.deleted_at is null";
		$matriculas = DB::select($cons_matri, [$anio]);

		// Grupos
		$cons_gr = "SELECT * FROM grupos WHERE year_id=? and deleted_at is null";
		$grupos = DB::select($cons_gr, [$year_id]);

		// Profesores
		$cons_pr = "SELECT p.id, p.nombres, p.apellidos, p.sexo, p.fecha_nac FROM profesores p
					inner join contratos c on p.id=c.profesor_id and p.deleted_at is null
					inner join years y ON c.year_id=y.id and y.year=? and y.deleted_at is null
					WHERE p.deleted_at is null and c.deleted_at is null
					group by p.id;";
		$profesores = DB::select($cons_pr, [$anio]);

		// Ausencias
		$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.tipo, a.fecha_hora, a.uploaded, a.created_by 
					FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.deleted_at is null
					inner join years y ON p.year_id=y.id and y.year=? and y.deleted_at is null
					WHERE a.deleted_at is null and a.asignatura_id is null;";
		$ausencias = DB::select($cons_aus, [$anio]); // , [":year_id" => $year_id]


		// Años
		// **Las 68 columnas de `years` nombradas, y NO `*`** — 22 §3.4 y 27 §4.
		//
		// Esta fila viaja entera al quiosco en `$usuario->years`, así que con el asterisco
		// **`regla_nivelacion` se publicaba desde el minuto en que corriera la migración**,
		// con este código y sin que nadie lo hubiera decidido. Lo que se escapa es una
		// cadena de configuración del colegio, y la ruta exige sesión —lleva
		// `withoutMiddleware('auth.token')` pero `RutasPreLoginTest` deja escrito que las
		// seis de `tardanzas/*` contestan 401 igual—, así que no frenaba el despliegue: se
		// arregla porque **nadie decidió publicarla**.
		//
		// **Van TODAS las que la tabla tiene hoy, no una selección de las que parezca usar
		// el quiosco**, y ésa es la mitad importante: no sabemos qué versión corre ese
		// cliente ni quién lo mantiene, así que la única forma de no romperlo es que reciba
		// exactamente lo de hoy menos la columna nueva. Es lo mismo que hizo
		// `Nota::LAS_DIEZ_COLUMNAS`.
		//
		// La lista salió del esquema **migrado** y no del volcado congelado: `firmantes_acta`,
		// `usa_consecutivo_certificados`, `usa_folio_certificados` y
		// `puestos_con_bol_independiente` están en la tabla y no en el volcado, así que
		// copiarla de ahí habría **quitado cuatro columnas** que el quiosco recibe hoy.
		$cons_ye = "SELECT y.id, y.year, y.nombre_colegio, y.abrev_colegio, y.genero_colegio, y.ciudad_id, y.logo_id, y.img_encabezado_id,
					y.rector_id, y.secretario_id, y.tesorero_id, y.coordinador_academico_id, y.coordinador_disciplinario_id, y.capellan_id, y.psicorientador_id, y.nota_minima_aceptada,
					y.minu_hora_clase, y.unidad_displayname, y.unidades_displayname, y.genero_unidad, y.subunidad_displayname, y.subunidades_displayname, y.genero_subunidad, y.resolucion,
					y.codigo_dane, y.caracter, y.calendario, y.jornada, y.encabezado_certificado, y.frase_final_certificado, y.actual, y.telefono,
					y.celular, y.website, y.website_myvc, y.alumnos_can_see_notas, y.profes_can_edit_alumnos, y.mostrar_puesto_boletin, y.puestos_alfabeticamente, y.titulo_rector,
					y.mostrar_nota_comport_boletin, y.si_recupera_materia_recup_indicador, y.year_pasado_en_bol, y.show_fortaleza_bol, y.solo_escalas_valorativas, y.config_certificado_estudio_id, y.cant_areas_pierde_year, y.cant_asignatura_pierde_year,
					y.show_subasignaturas_en_finales, y.mensaje_aprobo_con_pendientes, y.show_materias_todas, y.msg_when_students_blocked, y.contador_certificados, y.usa_consecutivo_certificados, y.contador_folios, y.usa_folio_certificados,
					y.texto_acta_eval, y.firmantes_acta, y.prematr_antiguos, y.prematr_nuevos, y.compromiso_familiar_label, y.created_by, y.updated_by, y.deleted_by,
					y.deleted_at, y.created_at, y.updated_at, y.puestos_con_bol_independiente
					FROM years y WHERE y.year=? and y.deleted_at is null";
		$years = DB::select($cons_ye, [$anio]);


		$usuario->alumnos 		= $alumnos;
		$usuario->matriculas 	= $matriculas;
		$usuario->periodos 		= $periodos;
		$usuario->grupos 		= $grupos;
		$usuario->profesores 	= $profesores;
		$usuario->ausencias 	= $ausencias;
		$usuario->years 		= $years;

		//return json_decode(json_encode($user[0]), true);

		return json_decode(json_encode($usuario), true);
	}


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