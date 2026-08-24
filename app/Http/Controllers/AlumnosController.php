<?php namespace App\Http\Controllers;



use App\Support\Autoriza;
use App\Support\CamposQueVinieron;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\User;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Year;
use App\Models\Nota;
use App\Models\Alumno;
use App\Models\Role;
use App\Models\Matricula;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Ausencia;
use App\Models\FraseAsignatura;
use App\Models\Asignatura;
use App\Models\NotaComportamiento;
use App\Models\DefinicionComportamiento;
use App\Models\ImageModel;
use \Log;

use Carbon\Carbon;

use App\Http\Controllers\Alumnos\GuardarAlumno;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class AlumnosController extends Controller {
	use ResuelveElUsuario;

	public function getIndex()
	{
		$previous_year 		= $this->user->year - 1;
		$id_previous_year 	= 0;
		$previous_year 		= Year::where('year', $previous_year)->first();

		if ($previous_year) {
			$id_previous_year = $previous_year->id;
		}

		$consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, a.pazysalvo, a.deuda,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_superuser, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:id_previous_year
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id and m.deleted_at is null )
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year2_id and m.deleted_at is null 
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is null';

		return DB::select($consulta, [
						':id_previous_year'	=>$id_previous_year, 
						':year_id'			=>$this->user->year_id,
						':year2_id'			=>$this->user->year_id
				]);
	}


	/**
	 * Cambiar de una vez la contraseña de todos los alumnos de un grupo.
	 *
	 * **Pedía `auth.personal` y nada más**, o sea que cualquiera de los 51
	 * profesores de la copia de producción podía reescribir las contraseñas de un
	 * grupo entero con dos campos del cuerpo. Se cerró a superusuario por decisión
	 * de Joseth el 23 ago 2026 (09 §cierre de la noche del 22 al 23).
	 *
	 * **No es una restricción nueva de hecho, sino de derecho: nadie pierde un
	 * botón que hoy vea.** El único cliente que la llama es `myvc_front`, desde el
	 * panel «Cambiar claves y usuarios» de la pantalla de Alumnos, que el menú
	 * enseña con `hasRoleOrPerm(['admin', 'secretario'])`; y medido el 23 ago en la
	 * copia de producción: **10 `is_superuser`, 10 con rol `Admin`, los mismos
	 * diez, y cero `Secretario`** —el rol existe desde el 21 ago y no lo tiene
	 * nadie—. Es la misma equivalencia de la §28.4 y el mismo razonamiento del
	 * §97.
	 *
	 * Se ancla a `esAdministrativo` y no a `puedeEditarAlumnos` porque **editar la
	 * ficha de un alumno y reescribir la contraseña de treinta son dos cosas
	 * distintas**: lo segundo deja a un grupo entero fuera de su cuenta y no se
	 * puede deshacer —el hash anterior no se guarda en ningún sitio—.
	 *
	 * **Y `esAdministrativo`, no `esSuperusuario`, desde el 24 ago 2026.** El
	 * cierre del 23 la trajo desde `auth.personal` —cualquiera de los 51
	 * profesores— y eligió el criterio más estrecho de los dos sin compararlo con
	 * el de al lado. Comparados, salía al revés de lo razonable: esto alcanza a
	 * **un grupo**, y las cuatro `cambiar-usuarios/*`, que alcanzan al **colegio
	 * entero**, piden `esAdministrativo`, o sea menos. La operación pequeña pedía
	 * más que la grande.
	 *
	 * Joseth lo resolvió **por alcance** (opción C, 24 ago): quien puede lo de un
	 * grupo es el administrativo, y lo irreversible de 1.280 se reserva. Además
	 * coincide con lo que ya había dicho el 21 ago —«puede cambiarle la
	 * contraseña/username a los alumnos y acudientes solamente»—, que es
	 * literalmente esto.
	 *
	 * Hoy no le da un botón a nadie que no lo tuviera: cero `Secretario` en la
	 * base y los 10 `Admin` son los mismos 10 `is_superuser` (§28.4).
	 */
	public function putCambiarClaves()
	{
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'No tienes permiso para cambiar las contraseñas de un grupo.');

		$clave 		= Request::input('clave');
		$grupo_id 	= Request::input('grupo_id');
		$clave 		= Hash::make($clave);
		
		// **`m.estado` y `u.deleted_at` faltaban, y no era una decisión.** Sin el
		// primero alcanzaba a los retirados que siguen colgando del grupo, y sin
		// el segundo a cuentas de la papelera. Que fue un descuido y no un
		// criterio lo dice el vecino: la masiva de colegio entero
		// —`CambiarUsuariosController:31`— sí lleva `u.deleted_at is null`, y
		// `alumnos/de-grupo` sí filtra MATR/ASIS. El docblock de arriba discute
		// A QUIÉN se le permite llamar y no dice ni una palabra sobre a quién
		// alcanza: se decidió el guard y no se miró la consulta.
		//
		// Lo vio la sesión de `myvc_flutter` el 24 ago comparando esta consulta
		// con la de `alumnos/de-grupo`, que es la que su pantalla usa para pintar
		// la lista sobre la que se aprieta este botón. Sin esto, la pantalla
		// enseña 30 alumnos y la operación toca 34.
		$consulta = 'UPDATE users u 
			INNER JOIN alumnos a ON a.user_id=u.id and a.deleted_at is null
			INNER JOIN matriculas m ON a.id=m.alumno_id and m.deleted_at is null
			SET u.password=:clave
			WHERE m.grupo_id=:grupo_id and m.estado in ("MATR","ASIS") and u.deleted_at is null';

		// `DB::update` y no `DB::select`: devuelve las filas tocadas, y la pantalla
		// necesita decir «cambiadas 31» en vez de un «Listo» a ciegas. Con
		// `DB::select` el número no existe y nadie puede comprobar el alcance de
		// una operación irreversible.
		$cambiadas = DB::update($consulta, [
			':clave'			=> $clave,
			':grupo_id'			=> $grupo_id
		]);

		// **Cambiar la forma aquí no rompe a nadie, comprobado en los dos clientes
		// que la llaman**: `myvc_front` hace `.then(() => toastr.success('Claves
		// cambiadas'))` con un texto fijo suyo y no mira el cuerpo
		// (`AlumnosCtrl.ts:454`), y `myvc_flutter` solo mira el código de estado
		// —su propio docblock anota «no devuelve cuántas cambió», que es justo lo
		// que se arregla aquí—. Se conserva la palabra por si algún colegio tiene
		// una copia vieja del front que sí la lea.
		return [ 'resultado' => 'Cambiadas', 'cambiadas' => $cambiadas ];
	}


	public function getSinMatriculas()
	{
		$consulta = 'SELECT m.id as matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				g.year_id, m.grupo_id, g.nombre as nombre_grupo, g.abrev as abrevgrupo,
				a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
				m.estado 
			FROM alumnos a 
			INNER JOIN matriculas m on m.alumno_id=a.id and a.deleted_at is null and m.deleted_at is null 
			INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id and a.id=m.alumno_id and g.deleted_at is null
			LEFT JOIN images i on i.id=a.foto_id and i.deleted_at is null';

		return DB::select($consulta, array(
						':year_id'			=> $this->user->year_id
				));
	}


	public function putDeGrupo($grupo_id)
	{
		$alumnos = DB::select('SELECT a.id, a.nombres, a.apellidos, a.sexo, m.estado,
						a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						m.estado  
					FROM alumnos a
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
					LEFT JOIN images i on i.id=a.foto_id and i.deleted_at is null
					WHERE a.deleted_at is null and m.grupo_id=?'
					, [$grupo_id]);

		return ['alumnos' => $alumnos];
	}



	public function putYearsConNotas()
	{
		$alumno_id 	= Request::input('alumno_id');
		$res 		= [];
		
		$years 		= DB::select('SELECT distinct(y.id) as year_id, y.year FROM years y 
						INNER JOIN periodos p ON p.year_id=y.id and p.deleted_at is null
						INNER JOIN unidades u ON u.periodo_id=p.id and u.deleted_at is null
						INNER JOIN subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						INNER JOIN notas n ON n.alumno_id=? and n.subunidad_id=s.id and n.deleted_at is null
						WHERE y.deleted_at is null', [$alumno_id]);
		
		for ($i=0; $i < count($years); $i++) { 
			
			$grupos 	= DB::select('SELECT distinct(g.id) as grupo_id, g.abrev, g.nombre, g.year_id FROM grupos g  
							INNER JOIN asignaturas a ON a.grupo_id=g.id and a.deleted_at is null
							INNER JOIN unidades u ON u.asignatura_id=a.id and u.deleted_at is null
							INNER JOIN subunidades s ON s.unidad_id=u.id and s.deleted_at is null
							INNER JOIN notas n ON n.alumno_id=? and n.subunidad_id=s.id and n.deleted_at is null
							WHERE g.deleted_at is null and g.year_id=?', [ $alumno_id, $years[$i]->year_id ]);
							
			$years[$i]->grupos = $grupos;
			
				
			for ($j=0; $j < count($years[$i]->grupos); $j++) { 
				
				$periodos 	= DB::select('SELECT distinct(p.id), p.numero, p.year_id FROM periodos p  
								INNER JOIN unidades u ON u.periodo_id=p.id and u.deleted_at is null
								INNER JOIN subunidades s ON s.unidad_id=u.id and s.deleted_at is null
								INNER JOIN notas n ON n.alumno_id=? and n.subunidad_id=s.id and n.deleted_at is null
								WHERE p.deleted_at is null and p.year_id=?', [ $alumno_id, $years[$i]->year_id ]);
								
				$years[$i]->grupos[$j]->periodos = $periodos;

			}
			array_push($res, $years[$i]);
		}
		
		
		# Años para el destino de las notas
		$years_dest = DB::select('SELECT y.id as year_id, y.year, m.estado, m.created_at, m.updated_at, m.updated_by, g.id as grupo_id, g.abrev, g.nombre
						FROM years y 
						INNER JOIN grupos g ON g.year_id=y.id and g.deleted_at is null 
						INNER JOIN matriculas m ON m.grupo_id=g.id and m.alumno_id=? and m.deleted_at is null 
						WHERE y.deleted_at is null', [$alumno_id]);
		
		for ($i=0; $i < count($years_dest); $i++) { 
			
			$periodos 	= DB::select('SELECT p.id, p.numero, p.year_id FROM periodos p  
							WHERE p.deleted_at is null and p.year_id=?', [ $years_dest[$i]->year_id ]);
							
			$years_dest[$i]->periodos = $periodos;
		}
		
		return ['years' => $res, 'years_dest' => $years_dest];
	}


	public function checkOrChangeUsername($user_id){

		$user = User::where('username', Request::input('username'))->first();
		//mientras el user exista iteramos y aumentamos i
		if ($user) {

			if ($user->id == $user_id) {
				return;
			}
			
			$username = $user->username;
			$i = 0;
			while(sizeof((array)User::where('username', $username)->first()) > 0 ){
				$i++;
				$username = $user->username.$i;
			}
			Request::merge(array('username' => $username));
		}
		
	}


	public function putEpsCheck()
	{
		$texto = Request::input('texto');
		$consulta = 'SELECT distinct eps FROM alumnos WHERE eps like :texto;';
		
		$res = DB::select($consulta, [':texto' => '%'.$texto.'%']);
		return [ 'eps' => $res ];
	}


	public function postStore()
	{
		if (
			Autoriza::puedeEditarAlumnos($this->user))
		{

			$alumno = [];

			try {
				$now 	= Carbon::parse(Request::input('fecha_matricula'));
				$this->sanarInputAlumno();

				$date = Carbon::createFromFormat('Y-m-d', Request::input('fecha_nac'));

				$alumno = new Alumno;
				$alumno->no_matricula	=	Request::input('no_matricula');
				$alumno->nombres	=	Request::input('nombres');
				$alumno->apellidos	=	Request::input('apellidos');
				$alumno->sexo		=	Request::input('sexo');
				#$alumno->user_id	=	Request::input('user_id');
				$alumno->fecha_nac	=	$date->format('Y-m-d');
				$alumno->ciudad_nac	=	Request::input('ciudad_nac');
				$alumno->tipo_doc	=	Request::input('tipo_doc');
				$alumno->documento	=	Request::input('documento');
				$alumno->ciudad_doc	=	Request::input('ciudad_doc');
				$alumno->tipo_sangre	=	Request::input('tipo_sangre')['sangre'];
				$alumno->eps		=	Request::input('eps');
				$alumno->telefono	=	Request::input('telefono');
				$alumno->celular	=	Request::input('celular');
				$alumno->barrio		=	Request::input('barrio');
				$alumno->estrato	=	Request::input('estrato');
				$alumno->ciudad_resid	=	Request::input('ciudad_resid');
				$alumno->religion	=	Request::input('religion');
				$alumno->email		=	Request::input('email');
				$alumno->facebook	=	Request::input('facebook');
				$alumno->pazysalvo	=	Request::input('pazysalvo');
				$alumno->deuda		=	Request::input('deuda');
				$alumno->updated_by	=	$this->user->user_id;
				$alumno->save();

				$this->sanarInputUser();

				$this->checkOrChangeUsername($alumno->user_id);

				$yearactual = Year::actual();
				$periodo_actual = Periodo::where('actual', true)
										->where('year_id', $yearactual->id)->first();

				if (!is_object($periodo_actual)) {
					$periodo_actual = Periodo::where('year_id', $yearactual->id)->first();
					$periodo_actual->actual 	= 1;
					$periodo_actual->updated_by = $this->user->user_id;
					$periodo_actual->save();
				}

				$usuario = new User;
				$usuario->username		=	Request::input('username');
				$usuario->password		=	Hash::make(Request::input('password', '123456'));
				$usuario->email			=	Request::input('email');
				$usuario->sexo			=	Request::input('sexo');
				$usuario->is_superuser	=	Autoriza::concederSuperusuario($this->user, Request::input('is_superuser'));
				$usuario->periodo_id	=	$periodo_actual->id;
				$usuario->is_active		=	Request::input('is_active', 1);
				$usuario->tipo			=	'Alumno';
				$usuario->updated_by	=	$this->user->user_id;
				$usuario->save();


				$role = Role::where('name', 'Alumno')->get();
				//$usuario->attachRole($role[0]);
				$usuario->roles()->attach($role[0]['id']);

				$alumno->user_id = $usuario->id;
				$alumno->save();

				$alumno->user = $usuario;

				$grupo_id = false;
				// `grupo.id` en vez de `Request::input('grupo')['id']`: indexar un
				// cuerpo que no trae el campo es un aviso de PHP, y Laravel arranca con
				// `error_reporting(-1)`, así que ese aviso es una excepción y el `catch`
				// la convierte en 422 **después de haber creado el alumno**. Un alta sin
				// grupo es un alumno sin matrícula, que es lo que dice `$grupo_id =
				// false`; no es un error que haya que esconder detrás de un 422. 05 §69.
				if (Request::input('grupo.id')) {
					$grupo_id = Request::input('grupo.id');
				}elseif (Request::input('grupo_sig.id')) {
					$grupo_id = Request::input('grupo_sig.id');
				}

				if ($grupo_id){
					$matricula = new Matricula;
					$matricula->alumno_id		=	$alumno->id;
					$matricula->nro_folio		=	$this->user->year . '-' . $alumno->id;
					$matricula->grupo_id		=	$grupo_id;
					$matricula->nuevo			=	Request::input('nuevo');
					$matricula->repitente		=	Request::input('repitente');
					$matricula->created_by 		= 	$this->user->user_id;
					
					if (Request::input('prematricula')) {
						$matricula->estado			=	"PREM";
						$matricula->prematriculado 	= 	$now;
					}else if (Request::input('llevo_formulario')) {
						$matricula->estado			=	"FORM";
					}else{
						$matricula->estado			=	"MATR";
						$matricula->fecha_matricula = 	$now;
					}
					
					$matricula->save();

					$grupo = Grupo::find((int) $matricula->grupo_id);
					$alumno->grupo = $grupo;
				
				}

				return $alumno;

			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		
		 
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function sanarInputAlumno(){
		if (is_array( Request::input('tipo_sangre') )){
			if (!array_key_exists('sangre', Request::input('tipo_sangre'))) {
				Request::merge(array('tipo_sangre' => array('sangre'=>'')));
			}
		}else{
			Request::merge(array('tipo_sangre' => array('sangre'=>'')));
		}

		if(Request::has('ciudad_nac')){
			if (Request::input('ciudad_nac')['id']) {
				Request::merge(array('ciudad_nac' => Request::input('ciudad_nac')['id'] ) );
			}else{
				Request::merge(array('ciudad_nac' => null) );
			}
		}

		if(Request::has('tipo_doc')){
			if (Request::input('tipo_doc')['id']) {
				Request::merge(array('tipo_doc' => Request::input('tipo_doc')['id'] ) );
			}else{
				Request::merge(array('tipo_doc' => null) );
			}
		}


		if(Request::has('ciudad_doc')){
			if (Request::input('ciudad_doc')['id']) {
				Request::merge(array('ciudad_doc' => Request::input('ciudad_doc')['id'] ) );
			}else{
				Request::merge(array('ciudad_doc' => null) );
			}
		}

		if (Request::has('foto')){

			if (isset( Request::input('foto')['id'])) {
				Request::merge(array('foto_id' => Request::input('foto')['id'] ) );
			}else if (is_string(Request::input('foto')) ){
				Request::merge(array('foto_id' => Request::input('foto')) );
			}else{
				Request::merge(array('foto_id' => null) );
			}
		}
		
	}

	public function sanarInputUser()
	{
		/*
		//separamos el nombre de la img y la extensión
		$info = explode(".", $file->getClientOriginalName());
		$primer = $info[0];
		*/
		
		if (!Request::input('username')) {
			if (Request::input('documento')) {
				Request::merge(['username' => Request::input('documento')]);
			}else{
				$dirtyName = Request::input('nombres');
				$name = preg_replace('/\s+/', '', $dirtyName);
				Request::merge(array('username' => $name));
			}
		}

		// **`email1` no existe: cero apariciones en los cuatro clientes**, comprobado
		// el 24 ago 2026. Así que esta rama corría SIEMPRE y pisaba el `email2` que
		// el cliente sí manda con el correo de la FICHA —dos columnas de dos tablas—
		// o con `usuario@myvc.com`.
		//
		// Y la guarda de arriba no lo paraba: `$vinieron->trae('email2')` contesta
		// *«¿vino la clave?»* —sí, vino— y no *«¿es éste el valor que vino?»*. La
		// §68.3 cerró el caso de que el campo NO viniera; éste es el de que venga y
		// llegue sustituido.
		//
		// **Y aquí estaba disparado**, al contrario que en el gemelo de profesores:
		// `AlumnosEditCtrl.ts:122` hace `$ctrl.alumno.email2 = alumno.user.email`, o
		// sea que la pantalla manda el correo de la cuenta de vuelta **y** el
		// `username` que abre el bloque. En cada guardado de una ficha de alumno.
		//
		// La condición correcta es la que `email1` quería decir: derivar un correo
		// **sólo si no hay ninguno**. Cambia una palabra, y el defecto del alta
		// —cuenta nueva sin correo— se conserva. Ver 05 §173.2 y
		// noche-2026-08-24/profes-1.md.
		if (!Request::input('email2')) {

			if (Request::input('email')) {
				Request::merge(array('email2' => Request::input('email') ));
			}else{
				$email = Request::input('username') . '@myvc.com';
				Request::merge(array('email2' => $email));
			}
		}
	}



	/**
	 * La ficha de un alumno. **Es la hermana en detalle de la §34.**
	 *
	 * Devuelve documento, tipo de sangre, EPS, dirección, teléfono, religión,
	 * sisbén, deuda y `nee`/`nee_descripcion` —las necesidades educativas
	 * especiales—, y la ruta lleva solo `auth.token`. Tenía una rama para
	 * acudientes que sí comprueba el vínculo y **un `else` que cubría a todos los
	 * demás, incluido un alumno**, buscando por `a.id` sin mirar de quién es. Con
	 * token de alumno y el id de otro respondía 200 con la ficha entera.
	 *
	 * **Por qué no lo cazó `persona.propia`**, que existe justo para esto: el
	 * identificador aquí se llama **`id`**, y la lista de nombres que ese guard
	 * reconoce es `alumno_id`, `user_id`, `persona_id`, `acudiente_id`,
	 * `profesor_id`, `matricula_id`, `imagen_id`, `img_id`. Su propio docblock lo
	 * había previsto —«comprobar solo la que uno espera deja abierta la que no»— y
	 * aun así faltaba ésta. **`id` no se le puede añadir a esa lista**: media API
	 * lo usa para cosas que no son personas —una unidad, una nota, un año— y el
	 * guard intentaría resolverlas como si lo fueran. Por eso se cierra aquí.
	 *
	 * Ver 05 §41.
	 */
	public function putShow()
	{
		$id 		= Request::input('id');
		$con_grupos = Request::input('con_grupos');
		
		if ($this->user->tipo === 'Alumno' && (int) $id !== (int) $this->user->persona_id) {
			abort(403, 'Solo puedes consultar lo tuyo');
		}
		
		if ($this->user->tipo == 'Acudiente') {
			
			$consulta 		= 'SELECT distinct(a.id) as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
								a.fecha_nac, a.tipo_doc, a.documento, a.tipo_sangre, a.eps, a.telefono, a.celular, 
								a.direccion, a.barrio, a.estrato, a.religion, u.email, a.facebook, a.created_by, a.updated_by,
								a.pazysalvo, a.deuda, 
								u.username, u.is_superuser, u.is_active,
								u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
								a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
								p.parentesco, p.observaciones, g.nombre as nombre_grupo, g.orden
							FROM alumnos a 
							inner join parentescos p on p.alumno_id=a.id and p.acudiente_id=?
							left join users u on a.user_id=u.id and u.deleted_at is null
							left join images i on i.id=u.imagen_id and i.deleted_at is null
							left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
							left join matriculas m on m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
							left join grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=?
							where a.deleted_at is null and p.deleted_at is null  and g.nombre is not null
							order by g.orden, a.apellidos, a.nombres';
							
			$alumnos 	= DB::select($consulta, [ $this->user->persona_id, $this->user->year_id ]);	
			$encontrado = false;
			for ($i=0; $i < count($alumnos); $i++) { 
				if ($alumnos[$i]->alumno_id == $id) {
					$encontrado = true;
				}
			}
			if (!$encontrado) {
				return response()->json([ 'autorizado'=> false, 'msg'=> 'No es tu acudido' ], 400);
			}
		}
		
		
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, g.nombre as grupo_nombre, g.abrev as grupo_abrev, 
				a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, a.deleted_at,
				c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, a.egresado,
				a.direccion, a.barrio, a.is_urbana, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, c3.departamento as departamento_resid_nombre, a.religion, u.email, a.facebook, a.created_by, a.updated_by,
				a.pazysalvo, a.deuda, m.grupo_id, a.is_urbana, IF(a.is_urbana, "SI", "NO") as es_urbana,
				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				u.username, u.is_active, a.nee, a.nee_descripcion,
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
				m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, IF(m.nuevo, "SI", "NO") as es_nuevo, m.repitente, m.fecha_pension,
				a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3, m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada 
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
			INNER JOIN grupos g ON g.id=m.grupo_id AND g.year_id=:year_id and g.deleted_at is null
			left join users u on a.user_id=u.id and u.deleted_at is null
			left join images i on i.id=u.imagen_id and i.deleted_at is null
			left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
			left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
			left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
			where m.deleted_at is null
			order by a.apellidos, a.nombres';
			// he quitado el      a.deleted_at is null
			
		// \Log::info('Año '.$this->user->year_id);
		$alumno = DB::select($consulta, [ ':alumno_id' => $id, ':year_id' => $this->user->year_id ]);
		
		if( count($alumno) > 0){
			
			$alumno 	= $alumno[0];
			return $this->comprobar_alumno_con_grupos($alumno, $con_grupos);
			
		}else{
			
			$consulta = 'SELECT a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
					a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, a.deleted_at,
					c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, a.tipo_sangre, a.eps, a.telefono, a.celular, a.egresado,
					a.direccion, a.barrio, a.is_urbana, a.estrato, a.ciudad_resid, c3.ciudad as ciudad_resid_nombre, c3.departamento as departamento_resid_nombre, a.religion, u.email, a.facebook, a.created_by, a.updated_by,
					a.pazysalvo, a.deuda, a.is_urbana, IF(a.is_urbana, "SI", "NO") as es_urbana,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.username, u.is_active, a.nee, a.nee_descripcion,
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
					a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3
				FROM alumnos a 
				left join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
				left join ciudades c2 on c2.id=a.ciudad_doc and c2.deleted_at is null
				left join ciudades c3 on c3.id=a.ciudad_resid and c3.deleted_at is null
				where a.id=:alumno_id
				order by a.apellidos, a.nombres';
				// he quitado el      a.deleted_at is null
				
			$alumno = DB::select($consulta, [ ':alumno_id' => $id ]);
			
			if( count($alumno) > 0){
				
				$alumno 	= $alumno[0];
				return $this->comprobar_alumno_con_grupos($alumno, $con_grupos);
					
			}else{
				return ['pailas' => 'nada'];
			}
		}

	}
	public function comprobar_alumno_con_grupos($alumno, $con_grupos){
		$grados 	= [];
		$grados_sig = [];
		$tipos_doc 	= [];
		
		$consulta = 'SELECT y.id, y.id as year_id, y.year, y.actual FROM years y WHERE y.deleted_at is null ORDER BY y.year desc limit 1';
		$year_ult = DB::select($consulta)[0];
		
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, 
				m.*, y.year, g.year_id, m.estado
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
			INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
			INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null
			where a.deleted_at is null and m.deleted_at is null
			order by y.year desc, g.orden';

		$matriculas = DB::select($consulta, [ ':alumno_id' => $alumno->alumno_id ] );
		
		// Requisitos de cada año
		for ($i=0; $i < count($matriculas); $i++) { 
			
			// Verifico si el último año, está en las matrículas de este alumno
			if ($year_ult->id == $matriculas[$i]->year_id) {
				$year_ult->entrado = true;
			}
			
			$matriculas[$i]->requisitos = $this->traer_requisitos_detalle($alumno->alumno_id, $matriculas[$i]);
		}
		

		if (!isset($year_ult->entrado)) {
			$year_ult->requisitos = $this->traer_requisitos_detalle($alumno->alumno_id, $year_ult);
			array_unshift($matriculas, $year_ult);
		}
			
	
		// Matrícula del siguiente año
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, m.grupo_id, m.estado, m.nuevo, m.repitente, m.prematriculado, m.fecha_matricula, y.id as year_id, y.year as year,
				m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada 
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
			INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
			INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:anio
			where a.deleted_at is null and m.deleted_at is null
			order by y.year, g.orden';

		$matri_next = DB::select($consulta, [ ':alumno_id' => $alumno->alumno_id, ':anio'=> ($this->user->year+1) ] );
		
		$alumno->next_year = [];
		if (count($matri_next) > 0) {
			$alumno->next_year = $matri_next[0];
		}
			
	
		if ($con_grupos) {
			// Grupos actuales
			$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
					p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
					g.created_at, g.updated_at, gra.nombre as nombre_grado
				from grupos g
				inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
				left join profesores p on p.id=g.titular_id
				where g.deleted_at is null
				order by g.orden';

			$grados = DB::select($consulta, [':year_id'=>$this->user->year_id] );
			
			// Grupos próximo año
			$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at
				from grupos g
				inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
				where g.deleted_at is null order by g.orden';
			
			$grados_sig = DB::select($consulta, [':anio'=> ($this->user->year+1) ] );
			
			// Tipos documentos
			$consulta = 'SELECT * from tipos_documentos where deleted_at is null';
			$tipos_doc = DB::select($consulta);
		}
		return [ 'alumno' => $alumno, 'grupos' => $grados, 'grupos_siguientes' => $grados_sig, 
			'tipos_doc' => $tipos_doc, 'matriculas' => $matriculas ];
	}
	
	
	public function traer_requisitos_detalle($alumno_id, $matricula){
		
			// Traemos los requisitos de cada año y su detalle si ya lo tiene
			$consulta_requisitos = 'SELECT m.*, m.descripcion as descripcion_titulo FROM requisitos_matricula m
				WHERE m.year_id=?';

			$requisitos_year = DB::select($consulta_requisitos, [ $matricula->year_id ] );
			
			$consulta_requisitos = 'SELECT m.*, m.descripcion as descripcion_titulo, a.id as requisito_alumno_id, a.estado, a.descripcion FROM requisitos_matricula m
				LEFT JOIN requisitos_alumno a ON a.requisito_id=m.id
				WHERE m.year_id=? and a.alumno_id='.$alumno_id;

			$requisitos_alumno = DB::select($consulta_requisitos, [ $matricula->year_id ] );
			
			
			$now 	= Carbon::parse(Request::input('fecha_matricula'));
			
			for ($j=0; $j < count($requisitos_year); $j++) { 
				$requi_year = $requisitos_year[$j];
				$found = false;
				
				for ($k=0; $k < count($requisitos_alumno); $k++) { 
					
					if ($requi_year->id == $requisitos_alumno[$k]->id) {
						$found = true;
					}
				}
				
				if (!$found) {
					$consulta = 'INSERT INTO requisitos_alumno(alumno_id, requisito_id, estado, created_at) 
						VALUES(?, ?, "falta", ?)';
			
					DB::insert($consulta, [ $alumno_id, $requisitos_year[$j]->id, $now ] );
						
				}
			}
			
			// Ejecutamos otra vez para traer con los nuevos requisitos_alumnos ingresados
			$requisitos_year = DB::select($consulta_requisitos, [ $matricula->year_id ] );
			return $requisitos_year;
	}


	
	
	

	public function putUpdate($id)
	{
		if (Autoriza::puedeEditarAlumnos($this->user)) {
			
			$alumno = Alumno::findOrFail($id);

			// ANTES del primer `sanar*`, que hace `Request::merge()`: después,
			// `Request::has()` ya no distingue lo que mandó el cliente. 05 §68.
			$vinieron = CamposQueVinieron::capturar();

			$this->sanarInputAlumno();

			try {
				$alumno->no_matricula = Request::input('no_matricula');
				$alumno->nombres 	=	Request::input('nombres');
				$alumno->apellidos	=	Request::input('apellidos');
				$alumno->sexo		=	Request::input('sexo', 'M');
				$alumno->fecha_nac	=	Request::input('fecha_nac');
				// Sin `['id']`, y no es cosmético: `sanarInputAlumno` ya convirtió los
				// tres de `{id: N}` al número. Volver a indexar era indexar un entero
				// —o un null—, y Laravel arranca con `error_reporting(-1)`, así que ese
				// aviso de PHP se convierte en excepción, la caza el `catch` de abajo y
				// **la ficha entera contestaba 422 «Datos incorrectos» sin guardar
				// nada**. La hermana de al lado, `postStore`, lee estos mismos tres
				// campos sin `['id']` desde siempre: la asimetría entre hermanas es lo
				// que lo señaló. 05 §69.
				$alumno->ciudad_nac =	Request::input('ciudad_nac');
				$alumno->tipo_doc	=	Request::input('tipo_doc');
				$alumno->documento	=	Request::input('documento');
				$alumno->ciudad_doc	=	Request::input('ciudad_doc');
				$alumno->tipo_sangre=	Request::input('tipo_sangre')['sangre'];
				$alumno->eps 		=	Request::input('eps');
				$alumno->telefono 	=	Request::input('telefono');
				$alumno->celular 	=	Request::input('celular');
				$alumno->barrio 	=	Request::input('barrio');
				$alumno->estrato 	=	Request::input('estrato');
				$alumno->ciudad_resid =	Request::input('ciudad_resid');
				$alumno->religion	=	Request::input('religion');
				$alumno->email		=	Request::input('email');
				$alumno->facebook	=	Request::input('facebook');
				$alumno->foto_id	=	Request::input('foto_id');
				$alumno->pazysalvo	=	Request::input('pazysalvo', true);
				$alumno->deuda		=	Request::input('deuda');




				if ($alumno->user_id and Request::has('username')) {
					
					$this->sanarInputUser();
					$this->checkOrChangeUsername($alumno->user_id);
					
					$usuario = User::find($alumno->user_id);
					$usuario->username		=	Request::input('username');
					$usuario->is_superuser	=	0;
					$usuario->updated_by 	= $this->user->user_id;

					// Cuenta que ya existe: lo que el cuerpo no trae, no se toca. Ver el
					// gemelo en ProfesoresController y 05 §68.1.
					if ($vinieron->trae('is_active')) {
						$usuario->is_active	=	(int) Request::boolean('is_active');
					}

					// `sanarInputUser` regenera `email2` desde el correo de la PERSONA
					// cuando no viene `email1` —que no lo manda nadie—, así que sin esta
					// guarda el correo de la cuenta se mudaba de columna. 05 §68.3.
					if ($vinieron->trae('email2')) {
						$usuario->email		=	Request::input('email2');
					}

					// La condición estaba invertida: escribía la contraseña **sólo si
					// venía vacía**, o sea que teclear una de verdad no hacía nada y
					// borrar la casilla dejaba la cuenta con el hash de la cadena vacía
					// —y entrar con la contraseña vacía responde 200, que es la §26—.
					// La casilla existe y es alcanzable: `alumnosEdit.html:229` la ata a
					// `$ctrl.alumno`, que es el objeto entero que se manda.
					// `filled` es las dos cosas a la vez: si no viene, no se toca; si
					// viene vacía, tampoco. 05 §68.2.1.
					if (Request::filled('password')) {
						$usuario->password	=	Hash::make(Request::input('password'));
					}

					$usuario->save();

					$alumno->user_id 	= $usuario->id;
					$alumno->updated_by = $this->user->user_id;
					
					$alumno->save();

					$alumno->user = $usuario;
				}

				if (!$alumno->user_id and Request::has('username')) {
					
					$this->sanarInputUser();
					$this->checkOrChangeUsername($alumno->user_id);

					$yearactual = Year::actual();
					$periodo_actual = Periodo::where('actual', true)
										->where('year_id', $yearactual->id)->first();


					$usuario = new User;
					$usuario->username		=	Request::input('username');
					$usuario->password		=	Hash::make(Request::input('password', '123456'));
					$usuario->email			=	Request::input('email2');
					$usuario->is_superuser	=	0;
					$usuario->is_active		=	Request::input('is_active', 1);
					$usuario->periodo_id	=	$periodo_actual->id;
					$usuario->created_by 	= $this->user->user_id;
					$usuario->save();

					$alumno->user_id = $usuario->id;
					
					$alumno->save();

					$alumno->user = $usuario;
				}



				// El desplegable de grupo de la ficha sólo pone `grupo` en el cuerpo
				// cuando alguien lo toca —`putShow` no devuelve ese objeto—, así que en
				// el guardado normal esto indexaba un null y tiraba el 422 **después**
				// de haber escrito ya la ficha y la cuenta: guardaba y decía que no.
				// 05 §69.
				if (Request::input('grupo.id')) {
					
					$grupo_id = Request::input('grupo.id');

					$matricula = Matricula::matricularUno($alumno->id, $grupo_id, false, $this->user->user_id);

					$grupo = Grupo::find((int) $matricula->grupo_id);
					$alumno->grupo = $grupo;
				}


				return $alumno;
			} catch (\Exception $e) {
				abort(422, 'Datos incorrectos');
			}
		} else {
			// El mensaje decía «eliminar alumnos definitivamente», copiado del
			// `forcedelete` de más abajo. Esta ruta EDITA, y quien lea el aviso o el
			// log de un colegio creería que alguien intentó borrar a un alumno. El
			// criterio que se comprueba arriba es `puedeEditarAlumnos`, no
			// `puedeBorrarAlumnos`. Salió del barrido de cobertura del 21 ago 2026,
			// que fue el primero que leyó lo que responde esta ruta. Ver 05 §54.
			return abort(403, 'No tienes permiso para editar alumnos.');
		}
	}



	/*************************************************************
	 * Guardar por VALOR
	 *************************************************************/
	public function putGuardarValor()
	{
		$year_id = Request::input('year_id', $this->user->year_id);

		if ($this->user->tipo == 'Acudiente') {
			return response()->json([ 'autorizado'=> false, 'msg'=> 'No puedes cambiar a un alumno' ], 400);
		}
		
		if ($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) {
			$consulta 	= 'SELECT a.id, a.user_id, g.id as grupo_id, g.titular_id, m.id as matricula_id FROM alumnos a
							INNER JOIN matriculas m ON m.alumno_id=a.id
							INNER JOIN grupos g ON g.id=m.grupo_id AND g.year_id=? AND g.titular_id=?
							WHERE a.id=?';
			$alumno 	= DB::select($consulta, [ $year_id, $this->user->persona_id, Request::input('alumno_id') ]);
			
			if (count($alumno)>0) {
				$alumno = $alumno[0];
				$guardarAlumno = new GuardarAlumno();
				return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));
			}else{
				return response()->json([ 'autorizado'=> false, 'msg'=> 'No eres el titular' ], 400);
			}
			
		} else if(Autoriza::esAdministrativo($this->user)){
			
			$guardarAlumno = new GuardarAlumno();
			return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));
			
		// El comentario que había aquí decía «Debo verificar que tenga rol
		// Psicólogo. Por ahora lo dejo Usuario para que funcione», y lo que estaba
		// escrito debajo era `$this->user->tipo == 'Psicólogo'` — un valor que
		// `tipo` no toma nunca, así que la rama no se ejecutaba jamás y las
		// necesidades educativas especiales solo las escribía un superusuario.
		// Su autor sabía cuál era el criterio bueno; lo que faltaba era que el rol
		// se preguntara donde vive. Decidido por Joseth el 21 ago 2026 después de
		// ver que el PIAR filtra por `nee=1` y que sin esto el psicólogo no puede
		// meter a nadie en él. Ver 05 §30.2 y §35.3.
		} else if(Role::isPsicologo($this->user->user_id) && (Request::input('propiedad') == 'nee' || Request::input('propiedad') == 'nee_descripcion')){
			
			$guardarAlumno = new GuardarAlumno();
			return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));
			
		} else {
			return abort(400, 'No tiene permisos');
		}
		
	}
	
	
	public function putPersonasCheck()
	{
		$texto = Request::input('texto');
		//$todos_anios = Request::input('todos_anios');
		$todos_anios = true;
		
		if ($todos_anios) {
				$consulta = 'SELECT a.id as alumno_id, a.nombres, a.apellidos, "alumno" as tipo, a.deleted_at, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					FROM alumnos a
					INNER JOIN matriculas m on a.id=m.alumno_id and m.deleted_at is null
					LEFT JOIN images i2 on i2.id=a.foto_id and i2.deleted_at is null
					WHERE a.deleted_at is null and nombres like :texto or apellidos like :texto2
					GROUP BY a.id order by a.nombres, a.apellidos';
					// INNER JOIN matriculas para evitar que se repita. Sólo traerá los que tengan alguna matricula en el sistema.
			
			$res = DB::select($consulta, [':texto' => '%'.$texto.'%', ':texto2' => '%'.$texto.'%']);
			return [ 'personas' => $res ];
		}else{
			$consulta = 'SELECT m.alumno_id, a.nombres, a.apellidos, m.id as matricula_id, "alumno" as tipo, g.abrev, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				FROM alumnos a
				INNER JOIN matriculas m on a.id=m.alumno_id and (m.estado="ASIS" or m.estado="MATR")
				INNER JOIN grupos g on g.year_id=:anio and g.id=m.grupo_id and g.deleted_at is null
				LEFT JOIN images i2 on i2.id=a.foto_id and i2.deleted_at is null
				WHERE nombres like :texto or apellidos like :texto2
				GROUP BY m.alumno_id, m.id order by g.orden';
			
			$res = DB::select($consulta, [':anio' => $this->user->year_id, ':texto' => '%'.$texto.'%', ':texto2' => '%'.$texto.'%']);
			return [ 'personas' => $res ];
			
		}
	}


	
	public function putDocumentoCheck()
	{
		$texto = Request::input('texto');

		$consulta = 'SELECT a.id as alumno_id, a.documento, a.nombres, a.apellidos, "alumno" as tipo, a.deleted_at
			FROM alumnos a
			WHERE documento like :texto';
			
		$res = DB::select($consulta, [':texto' => '%'.$texto.'%']);
		return [ 'personas' => $res ];

	}





	public function putGuardarValorVarios()
	{
		$year_id = Request::input('year_id', $this->user->year_id);
		
		if ($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) {
			
			$alumnos 	= Request::input('alumnos');
			$cant 		= count($alumnos);
			
			for ($i=0; $i < $cant; $i++) { 
				$consulta 	= 'SELECT a.id, a.user_id, g.id as grupo_id, g.titular_id, m.id as matricula_id FROM alumnos a
								INNER JOIN matriculas m ON m.alumno_id=a.id
								INNER JOIN grupos g ON g.id=m.grupo_id AND g.year_id=? AND g.titular_id=?
								WHERE a.id=?';
				$alumno 	= DB::select($consulta, [ $this->user->year_id, $this->user->persona_id, $alumnos[$i]['alumno_id'] ]);
				
				if (count($alumno)>0) {
					$alumno = $alumno[0];
					$guardarAlumno = new GuardarAlumno();
					return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, $alumnos[$i]['alumno_id']);
				}else{
					return response()->json([ 'autorizado'=> false, 'msg'=> 'No eres el titular' ], 400);
				}
			
			}
				
		} else if(Autoriza::esAdministrativo($this->user)){
			
			$alumnos 	= Request::input('alumnos');
			$cant 		= count($alumnos);
			
			for ($i=0; $i < $cant; $i++) { 

				$guardarAlumno = new GuardarAlumno();
				$guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), false, $year_id, $alumnos[$i]['alumno_id']);
				
			}
			return 'Cambios realizados';
		} else {
			return abort(400, 'No tiene permisos');
		}
		
	}




	public function deleteDestroy($id)
	{
		if (Autoriza::puedeEditarAlumnos($this->user)) {
			$alumno = Alumno::find($id);
			//Alumno::destroy($id);
			//$alumno->restore();
			//$queries = DB::getQueryLog();
			//$last_query = end($queries);
			//return $last_query;

			if ($alumno) {
				$alumno->delete();
			}else{
				return abort(400, 'Alumno no existe o está en Papelera.');
			}
			return $alumno;
		} else {
			return abort(400, 'No tiene permisos');
		}
	}	

	public function deleteForcedelete($id)
	{
		if (Autoriza::puedeBorrarAlumnos($this->user)) {
			$alumno = Alumno::onlyTrashed()->findOrFail($id);
			
			$alumno->forceDelete();
			return $alumno;
		} else {
			return abort(400, 'No tiene permisos');
		}
	}

	public function putRestore($id)
	{
		if (Autoriza::puedeEditarAlumnos($this->user)) {
			$alumno = Alumno::onlyTrashed()->findOrFail($id);

			$alumno->restore();
			return $alumno;
		} else {
			return abort(400, 'No tiene permisos');
		}
	}


	public function getTrashed()
	{
		$user = $this->user;

		$previous_year = $user->year - 1;
		$id_previous_year = 0;
		$previous_year = Year::where('year', '=', $previous_year)->first();


		$consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:id_previous_year
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year_id)
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=:year2_id
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is not null';

		return DB::select($consulta, array(
						':id_previous_year'	=>$id_previous_year, 
						':year_id'			=>$user->year_id,
						':year2_id'			=>$user->year_id
				));
	}

}