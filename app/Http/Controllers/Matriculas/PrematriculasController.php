<?php namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use App\Models\Matricula;
use App\Models\Acudiente;
use Carbon\Carbon;

use App\Events\MatriculasEvent;
use \Log;


class PrematriculasController extends Controller {


	public $user;
	
	public function __construct()
	{
		$this->user = User::fromToken();
		try {
			if( ! $this->user->is_superuser){
				return 'No tienes permiso';
			}
		} catch (\Throwable $th) {
			return 'Error';
		}
		
	}
	


	public function putLlevoFormulario()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$alumno_id 		= Request::input('alumno_id');
			$llevo 			= Request::input('llevo_formulario');
			$year 			= Request::input('year');
			$now 			= Carbon::now('America/Bogota');

			$consulta = 'DELETE	FROM llevo_formulario 
				WHERE alumno_id = :alumno_id and year=:year';

			DB::delete($consulta, ['alumno_id'=>$alumno_id, ':year'=>$year]);
			
			if ($llevo) {
				$consulta = 'INSERT INTO llevo_formulario(alumno_id, year, llevo_formulario, created_at, updated_at) 
					VALUE(?,?,?,?,?)';

				$matri = DB::insert($consulta, [ $alumno_id, $year, $now, $now, $now ] );

			}
			
			return ['modificado' => true];
		} else {
			return abort('400', 'No tiene permisos para editar');
		}
	}

	




	public function putAlumnosGradoAnterior()
	{
		$grupo_actual 	= Request::input('grupo_actual');
		$grado_ant_id 	= Request::input('grado_ant_id');
		$year_ant 		= Request::input('year_ant');
		$year_ant_id	= null;
		
		if (!$grupo_actual) {
			return;
		}

		$sqlYearAnt = 'SELECT id from years where year=:year_ant';
		
		$year_cons = DB::select($sqlYearAnt, [ ':year_ant'	=> $year_ant ]);
		if (count($year_cons) > 0) {
			$year_ant_id = $year_cons[0]->id;
		}


		// Alumnos asistentes o matriculados del grupo
		$sql1 = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';
		
		// Alumnos desertores o retirados del grupo
		$sql2 = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id2 and (m.estado="RETI" or m.estado="DESE")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';

		// Alumnos del grado anterior que no se han matriculado en este grupo
		$sql3 = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id 
						inner join grupos gru on gru.id=m.grupo_id and gru.year_id=:year_id
						inner join grados gra on gra.id=:grado_id and gru.grado_id=gra.id
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null and m.alumno_id
							not in (SELECT m.alumno_id FROM alumnos a 
								inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id3 
								where a.deleted_at is null and m.deleted_at is null)
						order by a.apellidos, a.nombres';

		$consulta = '('.$sql1.') UNION ('.$sql2.') UNION ('.$sql3.')';


		$res = DB::select($consulta, [ ':grupo_id'	=> $grupo_actual['id'], 
									':grupo_id2'	=> $grupo_actual['id'], 
									':year_id'		=> $year_ant_id, 
									':grado_id'		=> $grado_ant_id, 
									':grupo_id3'	=> $grupo_actual['id'] ]);

		return $res;

	}



	public function putAlumnosConGradoAnterior()
	{
		$grupo_actual 	= Request::input('grupo_actual');
		$grado_ant_id 	= Request::input('grado_ant_id');
		$year_ant 		= Request::input('year_ant');
		$year_ant_id	= null;
		$result 		= [];
		
		if (!$grupo_actual) {
			return;
		}
		
		// Probando Events, borrar
		//event(new MatriculasEvent());
		//! Borrar Event

		$sqlYearAnt = 'SELECT id from years where year=:year_ant';
		
		$year_cons = DB::select($sqlYearAnt, [ ':year_ant'	=> $year_ant ]);
		if (count($year_cons) > 0) {
			$year_ant_id = $year_cons[0]->id;
		}


		// Alumnos asistentes o matriculados o prematriculados del grupo
		$consulta = Matricula::$consulta_asistentes_o_matriculados;
		$result['AlumnosActuales'] = DB::select($consulta, [ ':grupo_id' => $grupo_actual['id'] ]);
		
		// Traigo los acudientes de cada alumno
		$cantA = count($result['AlumnosActuales']);

		for ($i=0; $i < $cantA; $i++) { 
			$consulta 		= Matricula::$consulta_parientes;
			$acudientes 	= DB::select($consulta, [ $result['AlumnosActuales'][$i]->alumno_id ]);	

			// Edad
			if ($result['AlumnosActuales'][$i]->fecha_nac) {
				$anio 	= date('Y', strtotime( $result['AlumnosActuales'][$i]->fecha_nac) );
				$mes 	= date('m', strtotime( $result['AlumnosActuales'][$i]->fecha_nac) );
				$dia 	= date('d', strtotime( $result['AlumnosActuales'][$i]->fecha_nac) );
				$result['AlumnosActuales'][$i]->edad = Carbon::createFromDate($anio, $mes, $dia)->age;
				//$result['AlumnosActuales'][$i]->edad = $anio.'-'. $mes.'-'. $dia;
			}else{
				$result['AlumnosActuales'][$i]->edad = '';
			}
			
			
			// Para el botón agregar
			array_push($acudientes, ['nombres' => null]);

			$btGrid1 = '<a uib-tooltip="Cambiar" ng-show="row.entity.nombres" tooltip-placement="left" class="btn btn-default btn-xs shiny icon-only info" ng-click="grid.appScope.cambiarAcudiente(grid.parentRow.entity, row.entity)" tooltip-append-to-body="true"><i class="fa fa-edit "></i></a>';
			$btGrid2 = '<a uib-tooltip="Quitar" ng-show="row.entity.nombres" tooltip-placement="right" class="btn btn-default btn-xs shiny icon-only danger" ng-click="grid.appScope.quitarAcudiente(grid.parentRow.entity, row.entity)" tooltip-append-to-body="true"><i class="fa fa-trash "></i></a>';
			$btGrid3 = '<a uib-tooltip="Seleccionar o crear acudiente para asignar a alumno" ng-show="!row.entity.nombres" class="btn btn-info btn-xs" ng-click="grid.appScope.agregarAcudiente(grid.parentRow.entity)" tooltip-append-to-body="true">Agregar...</a>';
			$btEdit = '<span style="padding-left: 2px; padding-top: 4px;" class="btn-group">' . $btGrid1 . $btGrid2 . $btGrid3 . '</span>';

			$subGridOptions 	= [
				'enableCellEditOnFocus' => true,
				'columnDefs' 	=> [
					['name' => 'edicion', 'displayName' => 'Edici', 'width' => 54, 'enableSorting' => false, 'cellTemplate' => $btEdit, 'enableCellEdit' => false],
					['name' => "Id", 'field' => "id", 'maxWidth' => 60, 'enableCellEdit' => false ],
					['name' => "Nombres", 'field' => "nombres", 'maxWidth' => 120 ],
					['name' => "Apellidos", 'field' => "apellidos", 'maxWidth' => 100],
					['name' => "Sex", 'field' => "sexo", 'maxWidth' => 40],
					['name' => "Parentesco", 'field' => "parentesco", 'maxWidth' => 90],
					['name' => "Usuario", 'field' => "username", 'maxWidth' => 135, 'cellTemplate' => "==directives/botonesResetPassword.tpl.html", 'editableCellTemplate' => "==alumnos/botonEditUsername.tpl.html" ], 
					['name' => "Documento", 'field' => "documento", 'maxWidth' => 100, 'cellFilter' => 'formatNumberDocumento'],
					['name' => "Ciudad doc", 'field' => "ciudad_doc", 'cellTemplate' => "==directives/botonCiudadDoc.tpl.html", 'enableCellEdit' => false, 'maxWidth' => 100],
					['name' => "Fecha nac", 'field' => "fecha_nac", 'cellFilter' => "date:mediumDate", 'type' => 'date', 'maxWidth' => 120],
					['name' => "Ciudad nac", 'field' => "ciudad_nac", 'cellTemplate' => "==directives/botonCiudadNac.tpl.html", 'enableCellEdit' => false, 'maxWidth' => 100],
					['name' => "Teléfono", 'field' => "telefono", 'maxWidth' => 90],
					['name' => "Celular", 'field' => "celular", 'maxWidth' => 90],
					['name' => "Ocupación", 'field' => "ocupacion", 'maxWidth' => 90],
					['name' => "Email", 'field' => "email", 'maxWidth' => 90],
					['name' => "Barrio", 'field' => "barrio", 'maxWidth' => 90],
					['name' => "Dirección", 'field' => "direccion", 'maxWidth' => 100],
				],
				'data' 			=> $acudientes
			];
			$result['AlumnosActuales'][$i]->subGridOptions = $subGridOptions;

		}
		


		// Alumnos del grado anterior que no se han matriculado en este grupo
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id 
						inner join grupos gru on gru.id=m.grupo_id and gru.year_id=:year_id
						inner join grados gra on gra.id=:grado_id and gru.grado_id=gra.id
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS")
							and m.alumno_id not in (SELECT m.alumno_id FROM alumnos a 
								inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id 
								where a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS") )
						order by a.apellidos, a.nombres';
        
        //Log::info(':year_id ' .$this->user->year_id. ' :grado_id ' .$grado_ant_id.' :grupo_id '.$grupo_actual['id']);
		$result['AlumnosSinMatricula'] = DB::select($consulta, [ ':year_id' => $this->user->year_id, ':grado_id' => $grado_ant_id, ':grupo_id'	=> $grupo_actual['id'] ]);



		// Alumnos del grado anterior que no se han matriculado en este grupo
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.celular,
				m.grupo_id, m.estado, 
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id 
			inner join grupos gru on gru.id=m.grupo_id and gru.id=:grupo_id
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			where a.deleted_at is null and m.deleted_at is null and (m.estado="FORM")
			order by a.apellidos, a.nombres';
        
		$result['AlumnosFormularios'] = DB::select($consulta, [ ':grupo_id' => $grupo_actual['id'] ]);


		
		// Solo prematriculados
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.documento, a.sexo, a.user_id, a.celular,
				m.grupo_id, m.estado, m.nuevo, 
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id 
			inner join grupos gru on gru.id=m.grupo_id and gru.id=:grupo_id
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			where a.deleted_at is null and m.deleted_at is null and (m.estado="PREA")
			order by a.apellidos, a.nombres';
        
		$result['AlumnosPrematriculadosA'] = DB::select($consulta, [ ':grupo_id' => $grupo_actual['id'] ]);
		

		return $result;

	}



}