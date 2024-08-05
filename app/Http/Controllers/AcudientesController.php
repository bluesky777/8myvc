<?php namespace App\Http\Controllers;



use Request;
use DB;
use Hash;
use Carbon\Carbon;

use App\User;
use App\Models\Acudiente;
use App\Models\Parentesco;
use App\Models\Role;
use App\Models\Grupo;
use App\Models\Ausencia;
use App\Models\NotaComportamiento;
use App\Models\DefinicionComportamiento;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;
use App\Models\Year;
use App\Models\Matricula;
use \Log;


use App\Http\Controllers\Alumnos\GuardarAlumno; // para guardar datos de acudiente. No quiero crear otro archivo


class AcudientesController extends Controller {
	
	public $consulta_pariente = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, ac.telefono, pa.parentesco, pa.id as parentesco_id, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active
						FROM parentescos pa
						left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						WHERE pa.id=? and pa.deleted_at is null';
	public $user;
	
	public function __construct()
	{
		$this->user = User::fromToken();
	}
	
	

	public function putMisAcudidos()
	{
		
		$user = $this->user;
		
		$consulta 		= 'SELECT distinct(a.id) as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.tipo_doc, a.documento, a.tipo_sangre, a.eps, a.telefono, a.celular, 
							a.direccion, a.barrio, a.estrato, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
							a.pazysalvo, a.deuda, g.id as grupo_id,
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
						where a.deleted_at is null and p.deleted_at is null and g.nombre is not null
						order by g.orden, a.apellidos, a.nombres';
			
		$alumnos 	= DB::select($consulta, [ $user->persona_id, $user->year_id ]);	

		for ($i=0; $i < count($alumnos); $i++) { 

			$ausencias 			= Ausencia::totalDeAlumno($alumnos[$i]->alumno_id, $user->periodo_id);

			$comportamiento 	= NotaComportamiento::nota_comportamiento($alumnos[$i]->alumno_id, $user->periodo_id);
			if ($comportamiento) {
			$comportamiento->definiciones = DefinicionComportamiento::frases($comportamiento->id);
		}

		$alumnos[$i]->ausencias_periodo 	= $ausencias;
		$alumnos[$i]->comportamiento 		= $comportamiento;

		}

		return [ 'alumnos' => $alumnos ];
	}
	

	
	// Modulo de acudientes con sub filas de acudidos
	public function putDatos()
	{
		
		$grupo_actual 	= Request::input('grupo_actual');

		if (!$grupo_actual) {
			return;
		}

        /* Esta consulta me sirvió para eliminar parentescos que quedaron al importar de Excel:
        delete from parentescos
where id in (
    select parentesco_id as id from (
    SELECT distinct(ac.id), pa.id as parentesco_id
					FROM parentescos pa
					left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
					left join users u on ac.user_id=u.id and u.deleted_at is null
					left join images i on i.id=ac.foto_id and i.deleted_at is null
					left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
					left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
					left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
					INNER JOIN alumnos a ON pa.alumno_id=a.id and a.deleted_at is null
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.grupo_id=12 and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM" or m.estado="FORM")
					WHERE pa.deleted_at is null and ac.id is null Order by ac.is_acudiente desc, ac.id
    ) res
    )
        */                

		// , pa.id as parentesco_id lo quité para no duplicar
		$consulta = 'SELECT distinct(ac.id), ac.id, pa.id as pariente_id, ac.nombres, ac.apellidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc, c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, ac.telefono, pa.parentesco, pa.observaciones, ac.user_id, 
						ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, t1.tipo as tipo_doc_nombre, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
						ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						u.username, u.is_active, ac.is_acudiente, IF(ac.is_acudiente, "SI", "NO") as es_acudiente
					FROM parentescos pa
					left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
					left join users u on ac.user_id=u.id and u.deleted_at is null
					left join images i on i.id=ac.foto_id and i.deleted_at is null
					left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
					left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
					left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
					INNER JOIN alumnos a ON pa.alumno_id=a.id and a.deleted_at is null
					INNER JOIN matriculas m ON m.alumno_id=a.id and m.grupo_id=? and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM" or m.estado="FORM")
					WHERE pa.deleted_at is null Order by ac.is_acudiente desc, ac.id';
		
		$acudientes = DB::select($consulta, [$grupo_actual['id']]);
		
		// Traigo los alumnos de cada acudiente
		$cantA = count($acudientes);

		for ($i=0; $i < $cantA; $i++) { 
			$consulta 		= Acudiente::$consulta_alumnos_de_acudiente; // Consulta compleja
							
			$alumnos 	= DB::select($consulta, [ $acudientes[$i]->id, $this->user->year_id ]);	
			
			
			$subGridOptions 	= [
				'enableCellEditOnFocus' => false,
				'columnDefs' 	=> [
					['name' => "Grupo", 'field' => "nombre_grupo", 'maxWidth' => 60],
					['name' => "Nombres", 'field' => "nombres", 'maxWidth' => 120 ],
					['name' => "Apellidos", 'field' => "apellidos", 'maxWidth' => 110],
					['name' => "Parentesco", 'field' => "parentesco", 'maxWidth' => 90],
					['name' => "Usuario", 'field' => "username", 'maxWidth' => 135, 'cellTemplate' => "==directives/botonesResetPassword.tpl.html", 'editableCellTemplate' => "==alumnos/botonEditUsername.tpl.html" ], 
					['name' => "Documento", 'field' => "documento", 'maxWidth' => 90],
					['name' => "Teléfono", 'field' => "telefono", 'maxWidth' => 90],
					['name' => "Celular", 'field' => "celular", 'maxWidth' => 90],
				],
				'data' 			=> $alumnos
			];
			$acudientes[$i]->subGridOptions = $subGridOptions;

		}
		return [ 'acudientes' => $acudientes ];
	}


	
	// Mandar los acudientes de un alumno
	public function putDePersona()
	{
		
		$alumno_id 	= Request::input('alumno_id');

		if (!$alumno_id) {
			return;
		}

		$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc, c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, ac.telefono, pa.parentesco, pa.observaciones, pa.id as parentesco_id, ac.user_id, 
						ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, t1.tipo as tipo_doc_nombre, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
						ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						u.username, u.is_active, ac.is_acudiente, IF(ac.is_acudiente, "SI", "NO") as es_acudiente
					FROM parentescos pa
					left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
					left join users u on ac.user_id=u.id and u.deleted_at is null
					left join images i on i.id=ac.foto_id and i.deleted_at is null
					left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
					left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
					left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
					INNER JOIN alumnos a ON pa.alumno_id=a.id and a.deleted_at is null
					WHERE pa.deleted_at is null and a.id=? Order by ac.is_acudiente desc, ac.id';
		
		$acudientes = DB::select($consulta, [$alumno_id]);
		
		
		return [ 'acudientes' => $acudientes ];
	}


	public function putNoAsignados()
	{
		

		$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc, c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, ac.telefono, ac.user_id, 
						ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, t1.tipo as tipo_doc_nombre, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
						ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
						u.username, u.is_active, ac.is_acudiente, IF(ac.is_acudiente, "SI", "NO") as es_acudiente
					FROM acudientes ac 
					left join users u on ac.user_id=u.id and u.deleted_at is null
					left join images i on i.id=ac.foto_id and i.deleted_at is null
					left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
					left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
					left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
					WHERE ac.deleted_at is null and ac.id NOT IN 
						(SELECT p.acudiente_id FROM alumnos a 
						INNER JOIN parentescos p ON p.alumno_id=a.id and p.deleted_at is null 
						WHERE a.deleted_at is null)
					Order by ac.id';
		
		$acudientes = DB::select($consulta, []);

		return [ 'acudientes' => $acudientes ];
	}

	// Planilla de asistencia padres
	public function putPlanillasAusencias()
	{
		$year			= Year::datos($this->user->year_id, false);
		
		

		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
				g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grupos = DB::select($consulta, [':year_id'=>$this->user->year_id] );
		
		
		for ($i=0; $i < count($grupos); $i++) { 
			
			
			$consulta 	= 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';

			$alumnos = DB::select($consulta, [':grupo_id' => $grupos[$i]->id]);
			
			for ($j=0; $j < count($alumnos); $j++) { 
				$consulta 		= Matricula::$consulta_parientes;
				$acudientes 	= DB::select($consulta , [ $alumnos[$j]->alumno_id ]);	
				$alumnos[$j]->acudientes = $acudientes;
			}
			
			$grupos[$i]->alumnos = $alumnos;
			
		}
		
		return ['year' => $year, 'grupos_acud' => $grupos];
	}


	public function putOcupacionesCheck()
	{
		$texto = Request::input('texto');
		$consulta = 'SELECT distinct ocupacion FROM acudientes WHERE ocupacion like :texto;';
		
		$res = DB::select($consulta, [':texto' => '%'.$texto.'%']);
		return [ 'ocupaciones' => $res ];
	}




	public function putBuscar()
	{
		$termino 	= Request::input('termino');

		$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, count(p.id) as cant_acudidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, ac.telefono, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active
						FROM acudientes ac 
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						inner join parentescos p on p.acudiente_id=ac.id and p.deleted_at is null
						WHERE (ac.nombres like ? or ac.apellidos like ?) and ac.deleted_at is null
						group by ac.id
						order by ac.nombres';

		$res = DB::select($consulta, [ '%'.$termino.'%', '%'.$termino.'%' ]);

		return $res;
	}

	public function putUltimos()
	{
		$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, count(p.id) as cant_acudidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, ac.telefono, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active
						FROM acudientes ac 
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						inner join parentescos p on p.acudiente_id=ac.id and p.deleted_at is null
						WHERE ac.deleted_at is null
						group by ac.id
						order by ac.id desc, ac.nombres limit 8';

		$res = DB::select($consulta);

		return $res;
	}



	public function postCrear()
	{
		$fecha_nac = null;
		if (Request::input('fecha_nac')) {
			$fecha_nac = Carbon::parse(Request::input('fecha_nac'));
		}

		try {
			$acudiente = new Acudiente;
			$acudiente->nombres			=	Request::input('nombres');
			$acudiente->apellidos		=	Request::input('apellidos');
			$acudiente->sexo				=	Request::input('sexo');
			$acudiente->tipo_doc		=	Request::has('tipo_doc') ? Request::input('tipo_doc')['id'] : null;
			$acudiente->documento		=	Request::input('documento');
			$acudiente->ciudad_doc	=	Request::has('ciudad_doc') ? Request::input('ciudad_doc')['id'] : null;
			$acudiente->ciudad_nac	=	Request::has('ciudad_nac') ? Request::input('ciudad_nac')['id'] : null;
			$acudiente->fecha_nac		=	$fecha_nac;
			$acudiente->telefono		=	Request::input('telefono');
			$acudiente->celular			=	Request::input('celular');
			$acudiente->ocupacion		=	Request::input('ocupacion');
			$acudiente->email				=	Request::input('email');

			$acudiente->save();

			$parentesco = new Parentesco;
			$parentesco->acudiente_id		=	$acudiente->id;
			$parentesco->alumno_id			=	Request::input('alumno_id');
			$parentesco->parentesco			=	Request::input('parentesco')['parentesco'];
			$parentesco->observaciones	=	Request::input('observaciones');
			$parentesco->created_by			=	$this->user->user_id;
			$parentesco->save();

			// Usuario nuevo
			if (Request::input('documento')) {
				$uname = Request::input('documento');
			}else{
				$dirtyName = Request::input('nombres');
				$uname = preg_replace('/\s+/', '', $dirtyName);
				$uname = $uname . rand(1000, 99999);
			}
			

			$usuario = new User;
			$usuario->username		=	$uname;
			$usuario->password		=	Hash::make(Request::input('password', '123456'));
			$usuario->email				=	Request::input('email2');
			$usuario->periodo_id	=	1;
			$usuario->sexo				=	'M';
			$usuario->tipo				=	'Acudiente';
			$usuario->created_by	=	$this->user->user_id;
			$usuario->save();

			$role = Role::where('name', 'Acudiente')->get();
			//$usuario->attachRole($role[0]);
			$usuario->roles()->attach($role[0]['id']);

			$acudiente->user_id = $usuario->id;
			$acudiente->save();

			// Traemos el acudiente con todos los datos organizados
			$acudiente = DB::select($this->consulta_pariente, [ $parentesco->id ]);

			return (array) $acudiente[0];
		} catch (Exception $e) {
			return $e;
		}
	}

	
	
	public function postCrearUsuario()
	{
		$acu 				= Request::input('acudiente');
		
		$opera 			= new OperacionesAlumnos();
		$username 	= $opera->username_no_repetido($acu['nombres']);
		
		$usu 								= new User;
		$usu->password 			= Hash::make('123456');
		$usu->username 			= $username;
		$usu->sexo 					= $acu['sexo'];
		$usu->is_superuser 	= 0;
		$usu->tipo 					= 'Acudiente';
		$usu->periodo_id 		= 1;
		$usu->created_by 		= $this->user->user_id;
		$usu->save();

		DB::update('UPDATE acudientes SET user_id=?, updated_by=?, updated_at=? WHERE id=?', [
			$usu->id,
			$this->user->user_id,
			Carbon::now('America/Bogota'),
			$acu['id'],
		]);
		
		return $usu;
	}
	



	/*************************************************************
	 * Guardar por VALOR
	 *************************************************************/
	public function putGuardarValor()
	{
		$guardarAlumno = new GuardarAlumno();

		return $guardarAlumno->valorAcudiente(
				Request::input('acudiente_id'), 
				Request::input('parentesco_id'),  
				Request::input('user_id'), 
				Request::input('propiedad'), 
				Request::input('valor'), 
				$this->user->user_id
		);
		
	}




	public function putQuitarParentescoAlumno()
	{
		$parentesco = Parentesco::findOrFail(Request::input('parentesco_id'));
		$parentesco->deleted_by 	= $this->user->user_id;
		$parentesco->save();
		$parentesco->delete();

		return $parentesco;
	}


	public function putSeleccionarParentesco()
	{
		if (Request::has('parentesco_acudiente_cambiar_id')) {
			$parentesco = Parentesco::findOrFail(Request::input('parentesco_acudiente_cambiar_id'));
			$parentesco->updated_by		=	$this->user->user_id;
		}else{
			$parentesco = new Parentesco;
			$parentesco->created_by		=	$this->user->user_id;
		}
		
		$parentesco->acudiente_id		=	Request::input('acudiente_id');
		$parentesco->alumno_id			=	Request::input('alumno_id');
		$parentesco->parentesco			=	Request::input('parentesco');
		$parentesco->observaciones	=	Request::input('observaciones');
		$parentesco->save();

		$acudiente = DB::select($this->consulta_pariente, [ $parentesco->id ]);

		return (array) $acudiente[0];
	}


	public function deleteDestroy($id)
	{
		$acudiente = Acudiente::findOrFail($id);
		$acudiente->delete();

		$consulta = 'UPDATE parentescos SET deleted_by=?, deleted_at=? WHERE acudiente_id = ?;';
		DB::update($consulta, [ $this->user->user_id, Carbon::now('America/Bogota'), $id ]);	

		return $acudiente;
	}

}