<?php namespace App\Http\Controllers;


use DB;
use Request;

use App\User;
use App\Models\Year;
use App\Models\Grado;
use App\Models\Profesor;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Models\Periodo;
use Carbon\Carbon;
use App\Http\Controllers\Alumnos\Definitivas;



class GruposController extends Controller {


	public function getConPaisesTipos()
	{
		$user 	= User::fromToken();
		$res 	= [];

		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo, 
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
				g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$res['grupos'] = DB::select($consulta, [':year_id'=>$user->year_id] );



		$consulta = 'SELECT * from tipos_documentos t where t.deleted_at is null';
		$res['tipos_doc'] = DB::select($consulta);


		// Todos los Paises
		$consulta = 'SELECT * FROM paises WHERE deleted_at is null';
		$res['paises'] = DB::select($consulta);
		
		if ($user->tipo == 'Profesor') {
			$consulta = Grupo::$consulta_grupos_titularia;
			$res['grupos_titularia'] = DB::select($consulta, [':year_id'=>$user->year_id, ':titular_id'=>$user->persona_id] );
			
			for ($i=0; $i < count($res['grupos']); $i++) { 
				$found = false;
				for ($j=0; $j < count($res['grupos_titularia']); $j++) { 
					if ($res['grupos'][$i]->id == $res['grupos_titularia'][$j]->id) {
						$found = true;
					}
				}
				if ($found) {
					$res['grupos'][$i]->es_titular = true;
				}
			}
		}

		return $res;
	}

	
	public function getConPaisesTiposNextYear()
	{
		$user 	= User::fromToken();
		$res 	= [];
		
		// Con los prematriculados
		/*
		$consulta = 'SELECT g.id, g.nombre, g.abrev, gra.orden as orden_grado, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at, g.cupo, count(g.id) as cantidad, (g.cupo - count(g.id)) as cant_faltantes
			from grupos g
			inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
			inner join grados gra on gra.id=g.grado_id and g.year_id=y.id 
			left join (
				select m.grupo_id from matriculas m 
					inner join alumnos a ON m.alumno_id=a.id and a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" OR m.estado="MATR")
			) r on r.grupo_id=g.id 
			where g.deleted_at is null 
			group by g.id
			order by g.orden';
		*/
		$consulta = 'SELECT r1.*, r2.cantidad FROM (
			SELECT g.id, g.nombre, g.abrev, gra.orden as orden_grado, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at, g.cupo, (g.cupo - count(g.id)) as cant_faltantes
					from grupos g
					inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
					inner join grados gra on gra.id=g.grado_id and g.year_id=y.id 
					left join (
						select m.grupo_id from matriculas m 
							inner join alumnos a ON m.alumno_id=a.id and a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" OR m.estado="PREA" OR m.estado="MATR")
					) r on r.grupo_id=g.id 
					where g.deleted_at is null 
					group by g.id
					order by g.orden
			)r1 left join (
				SELECT g.id, g.grado_id, g.orden, count(g.id) as cantidad
						from grupos g
						inner join years y on y.id=g.year_id and y.year=:anio2 and y.deleted_at is null
						inner join grados gra on gra.id=g.grado_id and g.year_id=y.id 
						inner join (
							select m.grupo_id from matriculas m 
								inner join grupos g on g.id=m.grupo_id and g.deleted_at is null
								inner join years y on y.id=g.year_id and y.year=:anio3 and y.deleted_at is null
								inner join alumnos a ON m.alumno_id=a.id and a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" OR m.estado="PREA")
						) r on r.grupo_id=g.id 
						where g.deleted_at is null 
						group by g.id
						order by g.orden
			)r2 ON r1.id=r2.id order by r2.orden';
			
		$next_y = $user->year+1;
		$res['grupos'] = DB::select($consulta, [':anio'=> $next_y, ':anio2'=> $next_y, ':anio3'=> $next_y ] );
		
		for ($i=0; $i < count($res['grupos']); $i++) { 
			
			
				
			// Alumnos del grado anterior que no se han matriculado en este grupo
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
					a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
					m.grupo_id, 
					m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
				FROM alumnos a 
				inner join matriculas m on a.id=m.alumno_id 
				inner join grupos gru on gru.id=m.grupo_id and gru.year_id=:year_id
				inner join grados gra on gra.orden=:orden_grado and gru.grado_id=gra.id
				where a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" OR m.estado="PREA" or m.estado="MATR" or m.estado="ASIS")
					and m.alumno_id not in (SELECT m.alumno_id FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id 
						where a.deleted_at is null and m.deleted_at is null and (m.estado="PREM" OR m.estado="PREA" or m.estado="MATR" or m.estado="ASIS") )
				order by a.apellidos, a.nombres';


			$sin_matr = DB::select($consulta, [ ':year_id'=> $user->year_id, ':orden_grado'=> ($res['grupos'][$i]->orden_grado-1), ':grupo_id'=> $res['grupos'][$i]->id ] );
			
			if(count($sin_matr) > 0){
				$res['grupos'][$i]->sin_matricular = count($sin_matr);
			}else{
				$res['grupos'][$i]->sin_matricular = count($sin_matr);
			}
		
			
			// Contamos los que llevaron formularios
			$consulta = 'SELECT count(m.id) as formularios FROM matriculas m
				INNER JOIN grupos g ON g.id=m.grupo_id and m.estado="FORM" AND m.grupo_id=:grupo_id and m.deleted_at is null and g.deleted_at is null
				INNER JOIN alumnos a ON a.id=m.alumno_id AND a.deleted_at is null
				INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:year_next 
				GROUP BY g.id;';
			
			$forms = DB::select($consulta, [ ':grupo_id'=> $res['grupos'][$i]->id, ':year_next'=> $user->year+1 ] );
			
			if(count($forms) > 0){
				$res['grupos'][$i]->cant_formularios = $forms[0]->formularios;
			}else{
				$res['grupos'][$i]->cant_formularios = 0;
			}
			
		
			// Contamos los que está 100% matriculados
			$consulta = 'SELECT count(m.id) as matriculados FROM matriculas m
				INNER JOIN grupos g ON g.id=m.grupo_id and m.estado="MATR" AND m.grupo_id=:grupo_id and m.deleted_at is null and g.deleted_at is null
				INNER JOIN alumnos a ON a.id=m.alumno_id AND a.deleted_at is null
				INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:year_next 
				GROUP BY g.id;';
			
			$matriculados = DB::select($consulta, [ ':grupo_id'=> $res['grupos'][$i]->id, ':year_next'=> $user->year+1 ] );
			
			if(count($matriculados) > 0){
				$res['grupos'][$i]->cant_matriculados = $matriculados[0]->matriculados;
			}else{
				$res['grupos'][$i]->cant_matriculados = 0;
			}
			
			
			// Contamos los asistentes
			$consulta = 'SELECT count(m.id) as asistentes FROM matriculas m
				INNER JOIN grupos g ON g.id=m.grupo_id and m.estado="ASIS" AND m.grupo_id=:grupo_id and m.deleted_at is null and g.deleted_at is null
				INNER JOIN alumnos a ON a.id=m.alumno_id AND a.deleted_at is null
				INNER JOIN years y ON y.id=g.year_id AND y.deleted_at is null and y.year=:year_next 
				GROUP BY g.id;';
			
			$asistentes = DB::select($consulta, [ ':grupo_id'=> $res['grupos'][$i]->id, ':year_next'=> $user->year+1 ] );
			
			if(count($asistentes) > 0){
				$res['grupos'][$i]->cant_asistentes = $asistentes[0]->asistentes;
			}else{
				$res['grupos'][$i]->cant_asistentes = 0;
			}
			
		
		}
		

		$consulta = 'SELECT * from tipos_documentos t where t.deleted_at is null';
		$res['tipos_doc'] = DB::select($consulta);


		// Todos los Paises
		$consulta = 'SELECT * FROM paises WHERE deleted_at is null';
		$res['paises'] = DB::select($consulta);
		
		return $res;
	}

	
	public function getIndex()
	{
		$user = User::fromToken();
		//return $user;
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo, 
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, gra.nombre as nombre_grado
					from grupos g
					inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
					left join profesores p on p.id=g.titular_id
					where g.deleted_at is null
					order by g.orden';

		$grados = DB::select($consulta, [':year_id'=>$user->year_id] );

		return $grados;
	}



	
	public function putConDisciplina()
	{
		$user 		= User::fromToken();
		$year_id 	= Request::input('year_id', $user->year_id);
		$res 		= [];

		$year 		= Year::datos($user->year_id, true); // Datos del año actual
		
		$res['year'] 	= $year;
		
		
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo, 
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, gra.nombre as nombre_grado
					from grupos g
					inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
					left join profesores p on p.id=g.titular_id
					where g.deleted_at is null
					order by g.orden';

		$res['grupos'] = DB::select($consulta, [':year_id'=>$year_id] );
		
		
		
		// CONFIGURACIONES
		$consulta = 'SELECT c.* FROM dis_configuraciones c
			WHERE c.year_id=:year_id and c.deleted_at is null';
			
		$config = DB::select($consulta, [':year_id'		=> $year_id, ]);
		
		if (count($config) > 0) {
			$config = $config[0];
		}else{
			$now 		= Carbon::now('America/Bogota');
			$consulta 	= 'INSERT INTO dis_configuraciones(year_id, created_at, updated_at) VALUES(?,?,?)';
			DB::insert($consulta, [$year_id, $now, $now]);
			
			$last_id 	= DB::getPdo()->lastInsertId();
			
			$consulta 	= 'SELECT c.* FROM dis_configuraciones c WHERE c.id=? and c.deleted_at is null';
			$config 	= DB::select($consulta, [$last_id])[0];
		
		}
		$res['config'] = $config;
		
		
		// ORDINALES
		$consulta = 'SELECT c.* FROM dis_ordinales c WHERE c.year_id=? and c.deleted_at is null';
		$ordinales = DB::select($consulta, [ $year_id ]);
		
		$res['ordinales'] = $ordinales;
		
		
		
		// DESCRIPCIÓN PARA TYPEAHEAD
		$consulta = 'SELECT distinct(c.descripcion) as descripcion FROM dis_procesos c WHERE (c.year_id=? or c.year_id=?) and c.deleted_at is null';
		$descripciones_typeahead = DB::select($consulta, [ $year_id, $year_id-1 ]);
		
		$res['descripciones_typeahead'] = $descripciones_typeahead;
		
		
		return $res;
	}


	public function getNextYear()
	{
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at
				from grupos g
				inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
				where g.deleted_at is null order by g.orden';
			
		$grados_sig = DB::select($consulta, [':anio'=> ($user->year+1) ] );

		return $grados_sig;
	}


	public function getCantAlumnos()
	{
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo,
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, gra.nombre as nombre_grado, count(a.id) as cant_alumnos 
					from grupos g
					INNER join grados gra on gra.id=g.grado_id and g.year_id=:year_id
					LEFT JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
					LEFT JOIN alumnos a ON a.id=m.alumno_id and m.deleted_at is null and a.deleted_at is null
					left join profesores p on p.id=g.titular_id
					where g.deleted_at is null
					GROUP BY g.id 
					order by g.orden';

		$grados = DB::select($consulta, [':year_id'=>$user->year_id] );

		return $grados;
	}


	public function putConCantidadAlumnos()
	{
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.cupo,
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, count(m.id) as cant_alumnos,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre 
					from grupos g
					INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="PREM" or m.estado="ASIS" or m.estado="MATR")
					INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
					left join profesores p on p.id=g.titular_id
					LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
					where g.deleted_at is null and g.year_id=:year_id
					GROUP BY g.id 
					order by g.orden';

		$grupos 	= DB::select($consulta, [':year_id'=>$user->year_id] );
		$periodos 	= Periodo::delYear($user->year_id);
		
		for ($j=0; $j < count($grupos); $j++) { 
			
			$grupos[$j]->periodos_ret	= [];
			$grupos[$j]->periodos_matr	= [];
			
			for ($i=0; $i < count($periodos); $i++) { 
				$peri 				= [];
				$peri['Per'] 		= $i + 1;
				
				// Retirados y desertores del periodo
				$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
							from grupos g
							INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="RETI" or m.estado="DESE")
							INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
							where g.deleted_at is null and g.id=? and m.fecha_retiro>? and m.fecha_retiro<? 
							order by g.orden';
							
				$cant_reti 			= DB::select($consulta, [$grupos[$j]->id, $periodos[$i]->fecha_inicio, $periodos[$i]->fecha_fin] )[0];
				$peri['cant_reti'] 	= ($cant_reti->cant_alumnos==0 ? '' : $cant_reti->cant_alumnos);				
				
				array_push($grupos[$j]->periodos_ret, $peri);
				
				
				$peri 				= [];
				$peri['Per'] 		= $i + 1;
				
				// Matriculados del periodo
				$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
							from grupos g
							INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null 
							INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
							where g.deleted_at is null and g.id=? and m.fecha_matricula>? and m.fecha_matricula<?
							order by g.orden';
							
				$cant_matr 			= DB::select($consulta, [$grupos[$j]->id, $periodos[$i]->fecha_inicio, $periodos[$i]->fecha_fin] )[0];
				$peri['cant_matr'] 	= ($cant_matr->cant_alumnos==0 ? '' : $cant_matr->cant_alumnos);
				
				array_push($grupos[$j]->periodos_matr, $peri);
			}
			
			
			
			// Cantidad de hombres
			$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
				from grupos g
				INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS")
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null and a.sexo="M"
				where g.deleted_at is null and g.id=?';
						
			$cant_matr 					= DB::select($consulta, [$grupos[$j]->id] )[0];
			$grupos[$j]->cant_hombres 	= $cant_matr->cant_alumnos;
				
			
			
			// Cantidad de mujeres
			$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
				from grupos g
				INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="PREM" or m.estado="MATR" or m.estado="ASIS")
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null and a.sexo="F"
				where g.deleted_at is null and g.id=?';
						
			$cant_matr 					= DB::select($consulta, [$grupos[$j]->id] )[0];
			$grupos[$j]->cant_mujeres 	= $cant_matr->cant_alumnos;
				
			
		}
		
		
		// Totales por periodo
		$periodos 	= Periodo::delYear($user->year_id);
		
		for ($i=0; $i < count($periodos); $i++) { 
			
			$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
						from grupos g
						INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="RETI" or m.estado="DESE")
						INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
						where g.deleted_at is null and m.fecha_retiro>=? and m.fecha_retiro<=? 
						order by g.orden';
						
			$periodos[$i]->total_reti = DB::select($consulta, [$periodos[$i]->fecha_inicio, $periodos[$i]->fecha_fin] )[0];
			
			if ($periodos[$i]->numero == 1) {
				$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
							from grupos g
							INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null
							INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
							where g.deleted_at is null and m.fecha_matricula<=?
							order by g.orden';
					
				$periodos[$i]->total_matr = DB::select($consulta, [$periodos[$i]->fecha_fin] )[0];
			
			}else{
				$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
							from grupos g
							INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null
							INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null
							where g.deleted_at is null and m.fecha_matricula>=? and m.fecha_matricula<=?
							order by g.orden';
					
				$periodos[$i]->total_matr = DB::select($consulta, [$periodos[$i]->fecha_inicio, $periodos[$i]->fecha_fin] )[0];
			
			}
			
		}
		

		return [ 'grupos'=>$grupos, 'periodos_total'=>$periodos ];
	}



	public function putAlumnosConDatos()
	{
		$user = User::fromToken();
		$grupo_actual 	= Request::input('grupo_actual');
		$result 		= [];
		
		if (!$grupo_actual) {
			return;
		}


		// Alumnos asistentes o matriculados del grupo
		$consulta = Matricula::$consulta_asistentes_o_matriculados;
		$result['AlumnosActuales'] = DB::select($consulta, [ ':grupo_id' => $grupo_actual['id'] ]);
		
		// Traigo los acudientes de 
		$cantA = count($result['AlumnosActuales']);

		for ($i=0; $i < $cantA; $i++) { 
			$consulta = Matricula::$consulta_parientes;
			
			$acudientes 		= DB::select($consulta, [ $result['AlumnosActuales'][$i]->alumno_id ]);	

			// Para el botón agregar
			array_push($acudientes, ['nombres' => null]);

			$btGrid1 = '<a uib-tooltip="Cambiar" ng-show="row.entity.nombres" tooltip-placement="left" class="btn btn-default btn-xs shiny icon-only info" ng-click="grid.appScope.cambiarAcudiente(grid.parentRow.entity, row.entity)"><i class="fa fa-edit "></i></a>';
			$btGrid2 = '<a uib-tooltip="Quitar" ng-show="row.entity.nombres" tooltip-placement="right" class="btn btn-default btn-xs shiny icon-only danger" ng-click="grid.appScope.quitarAcudiente(grid.parentRow.entity, row.entity)"><i class="fa fa-trash "></i></a>';
			$btGrid3 = '<a uib-tooltip="Seleccionar o crear acudiente para asignar a alumno" ng-show="!row.entity.nombres" class="btn btn-info btn-xs" ng-click="grid.appScope.agregarAcudiente(grid.parentRow.entity)">Agregar...</a>';
			$btEdit = '<span style="padding-left: 2px; padding-top: 4px;" class="btn-group">' . $btGrid1 . $btGrid2 . $btGrid3 . '</span>';

			$subGridOptions 	= [
				'enableCellEditOnFocus' => true,
				'columnDefs' 	=> [
					['name' => 'edicion', 'displayName' => 'Edici', 'width' => 54, 'enableSorting' => false, 'cellTemplate' => $btEdit, 'enableCellEdit' => false],
					['name' => "Nombres", 'field' => "nombres", 'maxWidth' => 120 ],
					['name' => "Apellidos", 'field' => "apellidos", 'maxWidth' => 100],
					['name' => "Sex", 'field' => "sexo", 'maxWidth' => 40],
					['name' => "Parentesco", 'field' => "parentesco", 'maxWidth' => 90],
					['name' => "Usuario", 'field' => "username", 'maxWidth' => 135, 'cellTemplate' => "==directives/botonesResetPassword.tpl.html", 'editableCellTemplate' => "==alumnos/botonEditUsername.tpl.html" ], 
					['name' => "Documento", 'field' => "documento", 'maxWidth' => 70],
					['name' => "Ciudad doc", 'field' => "ciudad_doc", 'cellTemplate' => "==directives/botonCiudadDoc.tpl.html", 'enableCellEdit' => false, 'maxWidth' => 100],
					['name' => "Fecha nac", 'field' => "fecha_nac", 'cellFilter' => "date:mediumDate", 'type' => 'date', 'maxWidth' => 120],
					['name' => "Ciudad nac", 'field' => "ciudad_nac", 'cellTemplate' => "==directives/botonCiudadNac.tpl.html", 'enableCellEdit' => false, 'maxWidth' => 100],
					['name' => "Teléfono", 'field' => "telefono", 'maxWidth' => 80],
					['name' => "Celular", 'field' => "celular", 'maxWidth' => 80],
					['name' => "Ocupación", 'field' => "ocupacion", 'maxWidth' => 80],
					['name' => "Email", 'field' => "email", 'maxWidth' => 80],
					['name' => "Barrio", 'field' => "barrio", 'maxWidth' => 80],
					['name' => "Dirección", 'field' => "direccion", 'maxWidth' => 80],
				],
				'data' 			=> $acudientes
			];
			$result['AlumnosActuales'][$i]->subGridOptions = $subGridOptions;

		}
		


		return $result;
	}



	public function getListado($grupo_id)
	{
		$user = User::fromToken();
		$consulta = 'SELECT m.alumno_id, a.user_id, u.username, a.nombres, a.apellidos, a.sexo, a.fecha_nac, m.estado,
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						(a.direccion + " - " + a.barrio) as direccion, a.facebook, a.pazysalvo, a.deuda
					FROM alumnos a
					inner join matriculas m on m.alumno_id=a.id and m.grupo_id=:grupo_id and (m.estado="PREM" OR m.estado="MATR" OR m.estado="ASIS") and m.deleted_at is null 
					left join users u on u.id=a.user_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null order by apellidos, nombres';

		$list = DB::select(DB::raw($consulta), array(':grupo_id'=>$grupo_id));
		
		return $list;
	}


	public function postStore()
	{
		
		$user = User::fromToken();

		try {

			$titular_id = null;
			$grado_id = null;

			if (Request::input('titular_id')) {
				$titular_id = Request::input('titular_id');
			}else if (Request::input('titular')) {
				$titular_id = Request::input('titular')['profesor_id'];
			}else{
				$titular_id = null;
			}

			if (Request::input('grado_id')) {
				$grado_id = Request::input('grado_id');
			}else if (Request::input('grado')) {
				$grado_id = Request::input('grado')['id'];
			}else{
				$grado_id = null;
			}

			$grupo = new Grupo;
			$grupo->nombre		=	Request::input('nombre');
			$grupo->abrev		=	Request::input('abrev');
			$grupo->year_id		=	$user->year_id;
			$grupo->titular_id	=	$titular_id;
			$grupo->grado_id	=	Request::input('grado')['id'];
			$grupo->valormatricula=	Request::input('valormatricula');
			$grupo->valorpension=	Request::input('valorpension');
			$grupo->orden		=	Request::input('orden');
			$grupo->caritas		=	Request::input('caritas');
			$grupo->save();
			
			return $grupo;
		} catch (Exception $e) {
			return abort('400', $e);
			return $e;
		}
	}


	public function getShow($id)
	{
		$grupo = Grupo::findOrFail($id);

		$profesor = Profesor::find($grupo->titular_id);
		$grupo->titular = $profesor;

		$grado = Grado::findOrFail($grupo->grado_id);
		$grupo->grado = $grado;

		return $grupo;
	}


	public function putUpdate()
	{
		$user = User::fromToken();
		$grupo = Grupo::findOrFail(Request::input('id'));

		try {

			$titular_id = null;
			$grado_id = null;

			if (Request::input('titular_id')) {
				$titular_id = Request::input('titular_id');
			}else if (Request::input('titular')) {
				$titular_id = Request::input('titular')['profesor_id'];
			}else{
				$titular_id = null;
			}

			if (Request::input('grado_id')) {
				$grado_id = Request::input('grado_id');
			}else if (Request::input('grado')) {
				$grado_id = Request::input('grado')['id'];
			}else{
				$grado_id = null;
			}

			$grupo->nombre		=	Request::input('nombre');
			$grupo->abrev		=	Request::input('abrev');
			$grupo->year_id		=	$user->year_id;
			$grupo->titular_id	=	$titular_id;
			$grupo->grado_id	=	$grado_id;
			$grupo->valormatricula=	Request::input('valormatricula');
			$grupo->valorpension=	Request::input('valorpension');
			$grupo->orden		=	Request::input('orden');
			$grupo->caritas		=	Request::input('caritas', false);
			$grupo->cupo		=	Request::input('cupo');
			$grupo->updated_by	=	$user->user_id;

			$grupo->save();

			return $grupo;
		} catch (Exception $e) {
			return abort('400', 'Datos incorrectos');
			return $e;
		}
	}



	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		$grupo = Grupo::findOrFail($id);
		$grupo->deleted_by = $user->user_id;
		$grupo->save();
		$grupo->delete();

		return $grupo;
	}
	public function deleteForcedelete($id)
	{
		$user = User::fromToken();
		$grupo = Grupo::onlyTrashed()->findOrFail($id);
		
		if ($grupo) {
			$grupo->forceDelete();
		}else{
			return abort(400, 'Grupo no encontrado en la Papelera.');
		}
		return $grupo;
	
	}

	public function putRestore($id)
	{
		$user = User::fromToken();
		$grupo = Grupo::onlyTrashed()->findOrFail($id);

		if ($grupo) {
			$grupo->restore();
		}else{
			return abort(400, 'Grupo no encontrado en la Papelera.');
		}
		return $grupo;
	}



	public function getTrashed()
	{
		$grupos = Grupo::onlyTrashed()->get();
		return $grupos;
	}

}