<?php namespace App\Http\Controllers\Matriculas;

use App\Services\Auditoria;
use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Matricula;
use App\Models\Acudiente;
use Carbon\Carbon;

use App\Events\MatriculasEvent;
use \Log;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\FichaEditada;
use App\Support\NotasAlCambiarDeGrupo;

class MatriculasController extends Controller {
	use ResuelveElUsuario;

	public function postMatricularuno()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$alumno_id 		= Request::input('alumno_id');
			$grupo_id 		= Request::input('grupo_id');
			$year_id 		= Request::input('year_id');

			$matricula = Matricula::matricularUno($alumno_id, $grupo_id, $year_id, $this->user->user_id);

			return $this->anotarLaMatricula($matricula, 'Matriculó al alumno');
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function postMatricularEn()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$alumno_id 		= Request::input('alumno_id');
			$grupo_id 		= Request::input('grupo_id');
			$year_id 		= Request::input('year_id');
			$crear_matri 	= Request::input('crear_matri');

			$consulta = 'SELECT m.id, m.alumno_id, m.grupo_id, m.estado, g.year_id 
				FROM matriculas m 
				inner join grupos g 
					on m.alumno_id = :alumno_id and g.year_id = :year_id and m.grupo_id=g.id and m.grupo_id=:grupo_id and m.deleted_at is null';

			$matriculas = DB::select($consulta, ['alumno_id'=>$alumno_id, 'year_id'=>$year_id, 'grupo_id'=>$grupo_id]);

			if (count($matriculas) > 0 && !$crear_matri) {
				return 'Ya matriculado';
			}

			$matricula = Matricula::matricularUno($alumno_id, $grupo_id, $year_id, $this->user->user_id, $crear_matri);

			return $this->anotarLaMatricula($matricula, 'Matriculó al alumno en un grupo concreto');
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putReMatricularuno()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$matricula_id 		= Request::input('matricula_id');
			
			$matri 				= Matricula::findOrFail($matricula_id);
			$antes = $matri->getOriginal('estado');
			$matri->estado 		= 'MATR';
			// Idem: el folio no se fabrica (21 §2.2).
			$matri->updated_by 	= $this->user->user_id;
			
			$matri->save();

			/*
			 * `getOriginal()` y no el cuerpo de la petición: el estado anterior sale de la
			 * fila que se acaba de pisar, que es la única fuente que no puede mentir. Se lee
			 * ANTES del `save()` — después Eloquent sincroniza el original y devolvería el
			 * valor nuevo, o sea que la línea diría «de MATR a MATR» sin fallar.
			 */
			Auditoria::registrar()
				->editar('matricula', (int) $matri->id)
				->deAlumno((int) $matri->alumno_id)
				->en(grupo: (int) $matri->grupo_id)
				->de(['estado' => $antes])
				->a(['estado' => 'MATR'])
				->resumen('Rematriculó al alumno')
				->guardar();

			return $matri;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putSetPromovido()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$now 				= Carbon::now('America/Bogota');
			$matricula_id 		= Request::input('matricula_id');
			
			$matri 				= Matricula::findOrFail($matricula_id);
			$antes = $matri->getOriginal('promovido');
			$matri->promovido 	= Request::input('valor', 'Automático');
			$matri->updated_by 	= $this->user->user_id;
			$matri->updated_at 	= $now;
			
			$matri->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matri->id)
				->deAlumno((int) $matri->alumno_id)
				->en(grupo: (int) $matri->grupo_id)
				->de(['promovido' => $antes])
				->a(['promovido' => $matri->promovido])
				->resumen('Cambió la promoción del alumno')
				->guardar();

			return $matri;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putSetAsistente()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$alumno_id 		= Request::input('alumno_id');
			$matricula_id 	= Request::input('matricula_id');
			
			$matricula 				= Matricula::findOrFail($matricula_id);
			$antes = $matricula->getOriginal('estado');
			$matricula->estado 		= 'ASIS';
			$matricula->updated_by 	= $this->user->user_id;
			$matricula->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matricula->id)
				->deAlumno((int) $matricula->alumno_id)
				->en(grupo: (int) $matricula->grupo_id)
				->de(['estado' => $antes])
				->a(['estado' => 'ASIS'])
				->resumen('Pasó al alumno a asistente')
				->guardar();

			return $matricula;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putSetNewAsistente()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$alumno_id 	= Request::input('alumno_id');
			$grupo_id 	= Request::input('grupo_id');

			$matricula = new Matricula;
			$matricula->alumno_id 	= $alumno_id;
			$matricula->grupo_id	= $grupo_id;
			$matricula->estado 		= 'ASIS';
			$matricula->updated_by 	= $this->user->user_id;
			$matricula->save();
			// Fila nueva: no hay `de()`. El id sale del modelo recién guardado y no de un
			// `lastInsertId()`, que en una petición con más escrituras señalaría a otra fila.
			Auditoria::registrar()
				->crear('matricula', (int) $matricula->id)
				->deAlumno((int) $matricula->alumno_id)
				->en(grupo: (int) $matricula->grupo_id)
				->a(['estado' => 'ASIS', 'grupo_id' => $matricula->grupo_id])
				->resumen('Dio de alta al alumno como asistente')
				->guardar();

			return $matricula;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putCambiarFechaRetiro()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$matricula_id = Request::input('matricula_id');
			$fecha_retiro = Request::input('fecha_retiro');
			
			$matricula 					= Matricula::findOrFail($matricula_id);
			$antes = $matricula->getOriginal('fecha_retiro');
			$matricula->fecha_retiro 	= $fecha_retiro;
			$matricula->updated_by 		= $this->user->user_id;
			$matricula->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matricula->id)
				->deAlumno((int) $matricula->alumno_id)
				->en(grupo: (int) $matricula->grupo_id)
				->de(['fecha_retiro' => $antes])
				->a(['fecha_retiro' => $matricula->fecha_retiro])
				->resumen('Cambió la fecha de retiro')
				->guardar();

			return $matricula;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putCambiarFechaMatricula()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$matricula_id 		= Request::input('matricula_id');
			$fecha_matricula 	= Carbon::parse(Request::input('fecha_matricula'));
			
			$matricula 					= Matricula::findOrFail($matricula_id);
			$antes = $matricula->getOriginal('fecha_matricula');
			$matricula->fecha_matricula = $fecha_matricula;
			$matricula->updated_by 		= $this->user->user_id;
			$matricula->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matricula->id)
				->deAlumno((int) $matricula->alumno_id)
				->en(grupo: (int) $matricula->grupo_id)
				->de(['fecha_matricula' => $antes])
				->a(['fecha_matricula' => $matricula->fecha_matricula])
				->resumen('Cambió la fecha de matrícula')
				->guardar();

			return $matricula;
		} else {
			return abort(400, 'No tiene permisos para editar');
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

		$sqlYearAnt = 'SELECT id from years where year=:year_ant and deleted_at is null;';
		
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
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.updated_at,
							a.updated_at as alumno_updated_at 
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
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.updated_at,
							a.updated_at as alumno_updated_at 
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
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.updated_at,
							a.updated_at as alumno_updated_at 
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

		FichaEditada::poner('alumno', $res, 'alumno_id');

		return $res;

	}

	/* ── Las notas que se quedan atrás al cambiar de grupo ───────────────────────────────── */

	/*
	 * QUÉ NOTAS TIENE EN EL GRUPO VIEJO. No escribe.
	 *
	 * Mover a un chico de 4A a 4B no toca ninguna nota, pero el boletín se arma desde el grupo:
	 * las definitivas de 4A cuelgan de las asignaturas de 4A, así que el boletín de 4B sale con
	 * esas materias EN BLANCO y nadie se entera hasta que se imprime. Esto se pregunta justo
	 * después del cambio para poder ofrecer traerlas.
	 *
	 * Ver App\Support\NotasAlCambiarDeGrupo.
	 */
	public function putRevisarNotasDelGrupoAnterior()
	{
		return NotasAlCambiarDeGrupo::revisar(
			(int) Request::input('alumno_id'),
			(int) Request::input('grupo_origen'),
			(int) Request::input('grupo_destino'),
		);
	}

	/*
	 * Y las trae. Sólo las definitivas, pareadas por `materia_id`.
	 *
	 * **El mismo permiso que calificar**, no el de matricular: esto escribe notas. Quien mueve al
	 * alumno de grupo puede no ser quien puede tocarle el boletín.
	 */
	public function putTraerNotasDelGrupoAnterior()
	{
		Autoriza::exigir(Autoriza::esAdministrativo($this->user),
			'Solo un administrativo puede traer las notas del grupo anterior.');

		$periodos = Request::input('periodos', []);

		return NotasAlCambiarDeGrupo::traer(
			(int) Request::input('alumno_id'),
			(int) Request::input('grupo_origen'),
			(int) Request::input('grupo_destino'),
			is_array($periodos) ? array_map('intval', $periodos) : [],
			$this->user->user_id ?? null,
		);
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

		$sqlYearAnt = 'SELECT id from years where year=:year_ant and deleted_at is null;';
		
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
				$result['AlumnosActuales'][$i]->edad = Carbon::createFromDate((int) $anio, (int) $mes, (int) $dia)->age;
				//$result['AlumnosActuales'][$i]->edad = $anio.'-'. $mes.'-'. $dia;
			}else{
				$result['AlumnosActuales'][$i]->edad = '';
			}
			
			
			// Para el botón agregar
			array_push($acudientes, ['nombres' => null]);

			$btGrid1 = '<a uib-tooltip="Cambiar" ng-show="row.entity.nombres" tooltip-placement="left" class="btn btn-default btn-xs shiny icon-only info" ng-click="grid.appScope.cambiarAcudiente(grid.parentRow.entity, row.entity)" tooltip-append-to-body="true"><i class="fa fa-edit "></i></a>';
			$btGrid2 = '<a uib-tooltip="Quitar" ng-show="row.entity.nombres" tooltip-placement="right" class="btn btn-default btn-xs shiny icon-only danger" ng-click="grid.appScope.quitarAcudiente(grid.parentRow.entity, row.entity)" tooltip-append-to-body="true"><i class="fa fa-trash "></i></a>';
			$btGrid3 = '<a uib-tooltip="Asignar también a otro alumno" ng-show="row.entity.nombres" class="btn btn-default btn-xs shiny" ng-click="grid.appScope.asignarAOtro(row.entity)" tooltip-append-to-body="true" style="height: 24px;">Compartir</a>';
			$btGrid4 = '<a uib-tooltip="Seleccionar o crear acudiente para asignar a alumno" ng-show="!row.entity.nombres" class="btn btn-info btn-xs" ng-click="grid.appScope.agregarAcudiente(grid.parentRow.entity)" tooltip-append-to-body="true">Agregar...</a>';
			$btEdit = '<span style="padding-left: 2px; padding-top: 4px;" class="btn-group">' . $btGrid1 . $btGrid2 . $btGrid3 . $btGrid4 . '</span>';

			$subGridOptions 	= [
				'enableCellEditOnFocus' => true,
				'columnDefs' 	=> [
					['name' => 'edicion', 'displayName' => 'Edici', 'width' => 123, 'enableSorting' => false, 'cellTemplate' => $btEdit, 'enableCellEdit' => false],
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
		

		// Alumnos desertores o retirados del grupo
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.updated_at,
							a.updated_at as alumno_updated_at 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="RETI" or m.estado="DESE")
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';

		$result['AlumnosDesertRetir'] = DB::select($consulta, [ ':grupo_id' => $grupo_actual['id'] ]);

		// Alumnos del grado anterior que no se han matriculado en este grupo
		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, gru.nombre as nombre_grupo, gru.abrev as abrev_grupo,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.updated_at,
							a.updated_at as alumno_updated_at 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id 
						inner join grupos gru on gru.id=m.grupo_id and gru.year_id=:year_id
						inner join grados gra on gra.id=:grado_id and gru.grado_id=gra.id
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null and m.alumno_id
							not in (SELECT m.alumno_id FROM alumnos a 
								inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id 
								where a.deleted_at is null and m.deleted_at is null)
						order by a.apellidos, a.nombres';
		
		$result['AlumnosSinMatricula'] = DB::select($consulta, [ ':year_id' => $year_ant_id, ':grado_id' => $grado_ant_id, ':grupo_id'	=> $grupo_actual['id'] ]);

		FichaEditada::poner('alumno', $result['AlumnosActuales'], 'alumno_id');
		FichaEditada::poner('alumno', $result['AlumnosDesertRetir'], 'alumno_id');
		FichaEditada::poner('alumno', $result['AlumnosSinMatricula'], 'alumno_id');

		return $result;

	}

	public function putToggleNuevo()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$id 		= Request::input('matricula_id');
			$is_nuevo 	= Request::input('is_nuevo');

			$matri 	= Matricula::findOrFail($id);
			$antes = $matri->getOriginal('nuevo');
			$matri->nuevo 			= $is_nuevo;
			$matri->updated_by 		= $this->user->user_id;
			$matri->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matri->id)
				->deAlumno((int) $matri->alumno_id)
				->en(grupo: (int) $matri->grupo_id)
				->de(['nuevo' => $antes])
				->a(['nuevo' => $matri->nuevo])
				->resumen('Marcó o desmarcó al alumno como nuevo')
				->guardar();

			return $matri;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putRetirar()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$id 	= Request::input('matricula_id');
			$fecha 	= Carbon::parse(Request::input('fecha_retiro'));

			$matri 	= Matricula::findOrFail($id);
			$antes = $matri->getOriginal();
			$matri->estado 			= 'RETI';
			$matri->fecha_retiro 	= $fecha;
			$matri->updated_by 		= $this->user->user_id;
			$matri->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matri->id)
				->deAlumno((int) $matri->alumno_id)
				->en(grupo: (int) $matri->grupo_id)
				->de(['estado' => $antes['estado'] ?? null, 'fecha_retiro' => $antes['fecha_retiro'] ?? null])
				->a(['estado' => 'RETI', 'fecha_retiro' => $matri->fecha_retiro])
				->resumen('Retiró al alumno')
				->guardar();

			return $matri;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function putPrematricular()
	{
		/*
		if ($this->user->tipo == 'Acudiente') {
			$obj 		= new \stdClass;
			$obj->name 	= 'Acudiente';
			$this->user->roles = [ $obj ];
		}
		 */
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser || ($this->user->tipo == 'Acudiente' || $this->user->tipo == 'Alumno') ) {
			$alumno_id 		= Request::input('alumno_id');
			$grupo_id 		= Request::input('grupo_id');
			$estado 		= Request::input('estado', 'PREM');
			$year_id 		= Request::input('year_id', $this->user->year_id);
			$now 			= Carbon::now('America/Bogota');
			$anio_sig 		= intval(Request::input('anio_sig', 1));
			
			
			// Traigo el año por el grupo
			$consulta = 'SELECT g.id, g.year_id 
				FROM grupos g 
				WHERE g.id=:grupo_id and g.deleted_at is null';

			$grupo = DB::select($consulta, ['grupo_id'=>$grupo_id]);

			if (count($grupo) > 0) {
				$grupo 		= $grupo[0];
				$year_id 	= $grupo->year_id;
			}else{
				return abort(400, 'Asigne grupo que corresponda a algún año creado.');
			}

			
			
			// Traigo matriculas del alumno este año aunque estén borradas
			$consulta = 'SELECT m.id, m.alumno_id, m.grupo_id, m.estado, g.year_id 
				FROM matriculas m 
				inner join grupos g 
					on m.alumno_id = :alumno_id and g.year_id = :year_id and m.grupo_id=g.id and m.deleted_at is null';

			$matriculas = DB::select($consulta, ['alumno_id'=>$alumno_id, 'year_id'=>$year_id]);
			//Log::info(count($matriculas) . ' -- ' . $alumno_id . '---$year_id' . $year_id);
			if ( count($matriculas) == 0 ) {
				
				if($estado=='FORM' || $estado=='ASIS'){
					DB::insert('INSERT INTO matriculas(alumno_id, grupo_id, estado, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?)', [$alumno_id, $grupo_id, $estado, $this->user->user_id, $now, $now]);
				}
				if($estado=='PREM' || $estado=='PREA'){
					DB::insert('INSERT INTO matriculas(alumno_id, grupo_id, estado, prematriculado, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?)', [$alumno_id, $grupo_id, $estado, $now, $this->user->user_id, $now, $now]);
				}
				if($estado=='MATR'){
					DB::insert('INSERT INTO matriculas(alumno_id, grupo_id, estado, fecha_matricula, created_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?)', [$alumno_id, $grupo_id, $estado, $now, $this->user->user_id, $now, $now]);
				}
				
			}else{
				$matric = $matriculas[0];
				if($estado=='FORM' || $estado=='ASIS'){
					DB::update('UPDATE matriculas SET alumno_id=?, grupo_id=?, estado=?, updated_by=?, updated_at=? WHERE id=?', [$alumno_id, $grupo_id, $estado, $this->user->user_id, $now, $matric->id]);
				}
				if($estado=='PREM' || $estado=='PREA'){
					DB::update('UPDATE matriculas SET alumno_id=?, grupo_id=?, estado=?, prematriculado=?, updated_by=?, updated_at=? WHERE id=?', [$alumno_id, $grupo_id, $estado, $now, $this->user->user_id, $now, $matric->id]);
				}
				if($estado=='MATR'){
					DB::update('UPDATE matriculas SET alumno_id=?, grupo_id=?, estado=?, fecha_matricula=?, updated_by=?, updated_at=? WHERE id=?', [$alumno_id, $grupo_id, $estado, $now, $this->user->user_id, $now, $matric->id]);
				}
				
			}

			/*
			 * **Una línea, después de las seis ramas.** Las seis hacen lo mismo visto
			 * desde fuera —dejar a este alumno en este grupo con este estado— y sólo se
			 * diferencian en qué columna de fecha tocan. Auditar rama por rama serían
			 * seis líneas que dicen lo mismo con distinto nombre.
			 *
			 * El `in_array` no es defensivo de más: si llega un `estado` que no es
			 * ninguno de los cinco, **ninguna rama escribió nada**, y sin esta
			 * comprobación se anotaría un cambio que no ocurrió — y para la fila nueva,
			 * con un `lastInsertId()` de otra petición.
			 */
			if (in_array($estado, ['FORM', 'ASIS', 'PREM', 'PREA', 'MATR'], true)) {
				$anterior = $matriculas[0] ?? null;

				$linea = Auditoria::registrar();
				$linea = $anterior === null
					? $linea->crear('matricula', (int) DB::getPdo()->lastInsertId())
					: $linea->editar('matricula', (int) $anterior->id);

				$linea->deAlumno((int) $alumno_id)
					->en(grupo: (int) $grupo_id, year: (int) $year_id)
					->de($anterior === null ? null : ['estado' => $anterior->estado, 'grupo_id' => $anterior->grupo_id])
					->a(['estado' => $estado, 'grupo_id' => $grupo_id])
					->guardar();
			}

			
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, g.nombre as grupo_nombre, g.abrev as grupo_abrev, m.estado, m.nuevo, m.repitente, m.prematriculado, m.fecha_matricula, y.id as year_id, y.year as year 
				FROM alumnos a 
				inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id 
				INNER JOIN grupos g ON g.id=m.grupo_id AND g.deleted_at is null
				INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:anio
				where a.deleted_at is null and m.deleted_at is null
				order by y.year, g.orden';

			// El `[0]` estaba desnudo, y la consulta busca la matrícula **en el año
			// `$this->user->year + $anio_sig`**, que puede no existir: un colegio que
			// todavía no ha creado el año siguiente —o que lo tiene en la papelera—
			// no tiene nada que devolver aquí. Eso era **500 después de haber
			// escrito**: la fila ya está cambiada cuando revienta.
			//
			// Y es lo peor que puede pasarle a esta ruta en concreto, porque es **la
			// única escritura que alcanza una familia**: el acudiente ve un error
			// sobre una prematrícula que sí ocurrió, y lo natural es repetirla.
			// `AnunciosDir.ts` lee `r.matricula.prematriculado` dentro del `.then`,
			// así que con un 500 no llega nunca.
			//
			// 404 y no 500, que es lo que ya eligieron la §52 y la §86 para esta
			// misma forma. **Lo que no arregla el código de salida es que la
			// escritura ya se hizo**, y por eso el mensaje lo dice. Ver 05 §144.
			$matri = DB::select($consulta, [ ':alumno_id' => $alumno_id, ':anio'=> ($this->user->year+$anio_sig) ]);

			if (count($matri) === 0) {
				abort(404, 'La matrícula se guardó, pero no hay ninguna en el año pedido para devolver.');
			}

			return ['matricula' => $matri[0]];
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	
	// Inutil:
	public function putQuitarPrematricula()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {

			$matricula_id 	= Request::input('matricula_id');
			//$now 			= Carbon::now('America/Bogota');

			$consulta = 'DELETE FROM matriculas WHERE id=?';

			/*
			 * **La fila se lee ANTES, porque este `DELETE` es físico.** No hay
			 * `deleted_at` que mirar después ni papelera de la que sacarla: en cuanto
			 * corre la línea de abajo, de qué grupo y en qué estado estaba ese alumno
			 * no lo sabe ya nadie. Es el único sitio de este rastro donde no anotar no
			 * es perder información, es perder el dato entero.
			 */
			$fila = DB::selectOne(
				'SELECT id, alumno_id, grupo_id, estado, prematriculado, fecha_matricula
				   FROM matriculas WHERE id = ?',
				[$matricula_id]
			);

			DB::delete($consulta, [$matricula_id]);

			if ($fila !== null) {
				Auditoria::registrar()
					->borrar('matricula', (int) $fila->id)
					->deAlumno((int) $fila->alumno_id)
					->en(grupo: (int) $fila->grupo_id)
					->de([
						'estado' => $fila->estado,
						'grupo_id' => $fila->grupo_id,
						'prematriculado' => $fila->prematriculado,
						'fecha_matricula' => $fila->fecha_matricula,
					])
					->resumen('Quitó la prematrícula — borrado físico, no hay papelera')
					->guardar();
			}

			return 'Quitada';
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	
	public function putDesertar()
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$id 	= Request::input('matricula_id');
			$fecha 	= Carbon::parse(Request::input('fecha_retiro'));

			$matri 	= Matricula::findOrFail($id);
			$antes = $matri->getOriginal();
			$matri->estado 			= 'DESE';
			$matri->fecha_retiro 	= $fecha;
			$matri->updated_by 		= $this->user->user_id;
			$matri->save();

			Auditoria::registrar()
				->editar('matricula', (int) $matri->id)
				->deAlumno((int) $matri->alumno_id)
				->en(grupo: (int) $matri->grupo_id)
				->de(['estado' => $antes['estado'] ?? null, 'fecha_retiro' => $antes['fecha_retiro'] ?? null])
				->a(['estado' => 'DESE', 'fecha_retiro' => $matri->fecha_retiro])
				->resumen('Marcó al alumno como desertor')
				->guardar();

			return $matri;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	public function deleteDestroy($id)
	{
		if (($this->user->tipo == 'Profesor' && $this->user->profes_can_edit_alumnos) || $this->user->is_superuser) {
			$matri = Matricula::findOrFail($id);
			$antes = $matri->getOriginal();
			$matri->estado 		= 'RETI';
			$matri->deleted_by 	= $this->user->user_id;
			$matri->save();
			$matri->delete();

			/*
			 * **`borrar` y no `editar`, aunque el método haga las dos cosas.** Pone `RETI` y
			 * acto seguido manda la fila a la papelera; lo que ocurrió visto desde fuera es un
			 * borrado, y el `RETI` es cómo está implementado. Se audita el acto, no la fila.
			 */
			Auditoria::registrar()
				->borrar('matricula', (int) $matri->id)
				->deAlumno((int) $matri->alumno_id)
				->en(grupo: (int) $matri->grupo_id)
				->de(['estado' => $antes['estado'] ?? null, 'grupo_id' => $antes['grupo_id'] ?? null])
				->resumen('Mandó la matrícula a la papelera')
				->guardar();

			return $matri;
		} else {
			return abort(400, 'No tiene permisos para editar');
		}
	}

	/**
	 * La línea de auditoría de `Matricula::matricularUno()`, que es una para las dos rutas.
	 *
	 * **Se audita aquí y no dentro del modelo**, y no es una preferencia de estilo: ese
	 * método tiene cuatro ramas —restaurar de la papelera, mover de grupo, crear nueva y
	 * el `catch` de rescate— y **el acto es uno solo**: matricular a este alumno en este
	 * grupo. Auditar rama por rama serían cuatro líneas que dicen lo mismo, que es justo
	 * lo que la regla «se audita el acto y no la fila» prohíbe.
	 *
	 * `wasRecentlyCreated` distingue crear de editar sin tener que rastrear por cuál de
	 * las cuatro pasó: lo pone Eloquent en el `save()` que de verdad insertó.
	 *
	 * **Aviso para quien mida:** `tools/escrituras-sin-auditoria.php` cuenta por método y
	 * no ve a través de una llamada, así que seguirá dando `postMatricularuno` y
	 * `postMatricularEn` como métodos sin rastro. No lo son.
	 */
	private function anotarLaMatricula(Matricula $matricula, string $resumen): Matricula
	{
		$linea = Auditoria::registrar();
		$linea = $matricula->wasRecentlyCreated
			? $linea->crear('matricula', (int) $matricula->id)
			: $linea->editar('matricula', (int) $matricula->id);

		$linea->deAlumno((int) $matricula->alumno_id)
			->en(grupo: (int) $matricula->grupo_id)
			->a(['estado' => $matricula->estado, 'grupo_id' => $matricula->grupo_id])
			->resumen($resumen)
			->guardar();

		return $matricula;
	}

}