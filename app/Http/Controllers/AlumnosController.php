<?php namespace App\Http\Controllers;



use Request;
use DB;
use Hash;

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


class AlumnosController extends Controller {

	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

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


	public function putCambiarClaves()
	{
		$clave 		= Request::input('clave');
		$grupo_id 	= Request::input('grupo_id');
		$clave 		= Hash::make($clave);
		
		$consulta = 'UPDATE users u 
			INNER JOIN alumnos a ON a.user_id=u.id and a.deleted_at is null
			INNER JOIN matriculas m ON a.id=m.alumno_id and m.deleted_at is null
			SET u.password=:clave
			WHERE m.grupo_id=:grupo_id';

		DB::select(DB::raw($consulta), [
			':clave'			=> $clave,
			':grupo_id'			=> $grupo_id
		]);

		return 'Cambiadas';
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

		return DB::select(DB::raw($consulta), array(
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
			($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos)
			|| $this->user->is_superuser || Role::isSecretario($this->user->user_id))
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
					$periodo_actual->actual 	= true;
					$periodo_actual->updated_by = $this->user->user_id;
					$periodo_actual->save();
				}

				$usuario = new User;
				$usuario->username		=	Request::input('username');
				$usuario->password		=	Hash::make(Request::input('password', '123456'));
				$usuario->email			=	Request::input('email');
				$usuario->sexo			=	Request::input('sexo');
				$usuario->is_superuser	=	Request::input('is_superuser', false);
				$usuario->periodo_id	=	$periodo_actual->id;
				$usuario->is_active		=	Request::input('is_active', true);
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
				if (Request::input('grupo')['id']) {
					$grupo_id = Request::input('grupo')['id'];
				}elseif (Request::input('grupo_sig')['id']) {
					$grupo_id = Request::input('grupo_sig')['id'];
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

					$grupo = Grupo::find($matricula->grupo_id);
					$alumno->grupo = $grupo;
				
				}

				return $alumno;

			} catch (Exception $e) {
				return abort('400', $alumno);
				//return $e;
			}
		
		 
		} else {
			return abort('400', 'No tiene permisos para editar');
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

		try {
			if (Request::has('foto')){

				if (isset( Request::input('foto')['id'])) {
					Request::merge(array('foto_id' => Request::input('foto')['id'] ) );
				}else if (is_string(Request::input('foto')) ){
					Request::merge(array('foto_id' => Request::input('foto')) );
				}else{
					Request::merge(array('foto_id' => null) );
				}
			}
		} catch (Exception $e) {
			
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

		if (!Request::input('email1')) {

			if (Request::input('email')) {
				Request::merge(array('email2' => Request::input('email') ));
			}else{
				$email = Request::input('username') . '@myvc.com';
				Request::merge(array('email2' => $email));
			}
		}
	}



	public function putShow()
	{
		$id 		= Request::input('id');
		$con_grupos = Request::input('con_grupos');
		
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
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser || Role::isSecretario($this->user->user_id)) {
			
			$alumno = Alumno::findOrFail($id);

			$this->sanarInputAlumno();

			try {
				$alumno->no_matricula = Request::input('no_matricula');
				$alumno->nombres 	=	Request::input('nombres');
				$alumno->apellidos	=	Request::input('apellidos');
				$alumno->sexo		=	Request::input('sexo', 'M');
				$alumno->fecha_nac	=	Request::input('fecha_nac');
				$alumno->ciudad_nac =	Request::input('ciudad_nac')['id'];
				$alumno->tipo_doc	=	Request::input('tipo_doc')['id'];
				$alumno->documento	=	Request::input('documento');
				$alumno->ciudad_doc	=	Request::input('ciudad_doc')['id'];
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
					$usuario->email			=	Request::input('email2');
					$usuario->is_superuser	=	false;
					$usuario->is_active		=	Request::input('is_active', true);
					$usuario->updated_by 	= $this->user->user_id;

					if (Request::has('password')) {
						if (Request::input('password') == ""){
							$usuario->password	=	Hash::make(Request::input('password'));
						}
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
					$usuario->is_superuser	=	false;
					$usuario->is_active		=	Request::input('is_active', true);
					$usuario->periodo_id	=	$periodo_actual->id;
					$usuario->created_by 	= $this->user->user_id;
					$usuario->save();

					$alumno->user_id = $usuario->id;
					
					$alumno->save();

					$alumno->user = $usuario;
				}



				if (Request::input('grupo')['id']) {
					
					$grupo_id = Request::input('grupo')['id'];

					$matricula = Matricula::matricularUno($alumno->id, $grupo_id, false, $this->user->user_id);

					$grupo = Grupo::find($matricula->grupo_id);
					$alumno->grupo = $grupo;
				}


				return $alumno;
			} catch (Exception $e) {
				return abort('400', $e);
			}
		} else {
			return abort('400', 'No tiene permisos');
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
			
		} else if($this->user->is_superuser || Role::isSecretario($this->user->user_id)){
			
			$guardarAlumno = new GuardarAlumno();
			return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));
			
		// Debo verificar que tenga rol Psicólogo. Por ahora lo dejo Usuario para que funcione
		} else if($this->user->tipo == 'Psicólogo' && (Request::input('propiedad') == 'nee' || Request::input('propiedad') == 'nee_descripcion')){
			
			$guardarAlumno = new GuardarAlumno();
			return $guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), Request::input('user_id'), $year_id, Request::input('alumno_id'));
			
		} else {
			return abort('400', 'No tiene permisos');
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
				
		} else if($this->user->is_superuser || Role::isSecretario($this->user->user_id)){
			
			$alumnos 	= Request::input('alumnos');
			$cant 		= count($alumnos);
			
			for ($i=0; $i < $cant; $i++) { 

				$guardarAlumno = new GuardarAlumno();
				$guardarAlumno->valor($this->user, Request::input('propiedad'), Request::input('valor'), false, $year_id, $alumnos[$i]['alumno_id']);
				
			}
			return 'Cambios realizados';
		} else {
			return abort('400', 'No tiene permisos');
		}
		
	}




	public function deleteDestroy($id)
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser || Role::isSecretario($this->user->user_id)) {
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
			return abort('400', 'No tiene permisos');
		}
	}	

	public function deleteForcedelete($id)
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser || Role::isSecretario($this->user->user_id)) {
			$alumno = Alumno::onlyTrashed()->findOrFail($id);
			
			if ($alumno) {
				$alumno->forceDelete();
			}else{
				return abort(400, 'Alumno no encontrado en la Papelera.');
			}
			return $alumno;
		} else {
			return abort('400', 'No tiene permisos');
		}
	}

	public function putRestore($id)
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser || Role::isSecretario($this->user->user_id)) {
			$alumno = Alumno::onlyTrashed()->findOrFail($id);

			if ($alumno) {
				$alumno->restore();
			}else{
				return abort(400, 'Alumno no encontrado en la Papelera.');
			}
			return $alumno;
		} else {
			return abort('400', 'No tiene permisos');
		}
	}


	public function getTrashed()
	{
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

		return DB::select(DB::raw($consulta), array(
						':id_previous_year'	=>$id_previous_year, 
						':year_id'			=>$user->year_id,
						':year2_id'			=>$user->year_id
				));
	}

}