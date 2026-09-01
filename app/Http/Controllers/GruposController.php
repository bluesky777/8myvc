<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

use App\User;
use App\Models\Year;
use App\Models\Grado;
use App\Models\Profesor;
use App\Models\Grupo;
use App\Support\Autoriza;
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


	/**
	 * Los grupos con su cantidad de alumnos, el desglose por sexo y el movimiento
	 * de cada periodo.
	 *
	 * LOS TRES CONTADORES CUENTAN LO MISMO --ASIS y MATR-- Y ESO ES EL ARREGLO
	 * del 31 ago 2026. Hasta hoy los tres sumaban tambien PREM mientras
	 * `getCantAlumnos` no lo hacia, y la portada de `app2` junta las dos
	 * respuestas: la columna «Alumnos» de la primera con «Hom» y «Muj» de esta.
	 * En lal eso pintaba 199 matriculados y 126+95=221 por sexo --los 22
	 * prematriculados-- y no habia forma de verlo desde la pantalla.
	 *
	 * Se quito de los tres, no solo de los dos del sexo: el informe impreso
	 * «Cantidad de alumnos por grupos» pinta las tres cifras en la MISMA hoja, y
	 * dejar `cant_alumnos` con PREM habria trasladado el descuadre ahi. El precio
	 * es que ese informe baja 22 alumnos en lal, que es la cifra correcta.
	 *
	 * Y LAS FECHAS DE PERIODO DEJARON DE SER ESTRICTAS la noche del 31 ago 2026,
	 * que es el segundo arreglo del mismo dia y una decision de Joseth. Los dos
	 * contadores comparaban con `>` y `<` mientras los totales de mas abajo
	 * comparaban con `>=` y `<=`, asi que quien se matriculaba o se retiraba EL
	 * PRIMER DIA de un periodo no aparecia en ninguna de las dos cifras del grupo
	 * --ni en la columna ni, por tanto, en el listado que la explica--. Se cambio
	 * en las dos consultas Y en `getAlumnosDe` a la vez: tocar una sola es
	 * descuadrar la celda con su listado, que es lo unico que ese endpoint
	 * garantiza. Efecto esperado: estas columnas SUBEN en algunos colegios, y no es
	 * una regresion; es gente que hoy no se contaba en ningun sitio.
	 *
	 * Lo que sigue SIN tocarse: `periodos_matr` no filtra por estado --cuenta hasta
	 * los RETI y los FORM--. Es otra decision y no es esta.
	 */
	public function putConCantidadAlumnos()
	{
		$user = User::fromToken();
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.cupo,
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, count(m.id) as cant_alumnos,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre 
					from grupos g
					INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
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
							where g.deleted_at is null and g.id=? and m.fecha_retiro>=? and m.fecha_retiro<=? 
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
							where g.deleted_at is null and g.id=? and m.fecha_matricula>=? and m.fecha_matricula<=?
							order by g.orden';
							
				$cant_matr 			= DB::select($consulta, [$grupos[$j]->id, $periodos[$i]->fecha_inicio, $periodos[$i]->fecha_fin] )[0];
				$peri['cant_matr'] 	= ($cant_matr->cant_alumnos==0 ? '' : $cant_matr->cant_alumnos);
				
				array_push($grupos[$j]->periodos_matr, $peri);
			}
			
			
			
			// Cantidad de hombres
			$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
				from grupos g
				INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="MATR" or m.estado="ASIS")
				INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null and a.sexo="M"
				where g.deleted_at is null and g.id=?';
						
			$cant_matr 					= DB::select($consulta, [$grupos[$j]->id] )[0];
			$grupos[$j]->cant_hombres 	= $cant_matr->cant_alumnos;
				
			
			
			// Cantidad de mujeres
			$consulta = 'SELECT count(m.id) as cant_alumnos, g.nombre, g.id
				from grupos g
				INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null and (m.estado="MATR" or m.estado="ASIS")
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



	/*
	 * El listado del grupo nunca devolvió la dirección: la consulta hacía
	 * `(a.direccion + " - " + a.barrio)`, y en MySQL el `+` es suma aritmética, no
	 * concatenación. Las dos cadenas se convertían a número, así que salía 0 —o
	 * null si a alguna le faltaba valor—. La pantalla llevaba imprimiendo eso desde
	 * que se escribió.
	 *
	 * CONCAT_WS y no CONCAT porque CONCAT devuelve null si un argumento es null, y
	 * un alumno sin barrio perdería también la dirección. NULLIF para que la cadena
	 * vacía cuente como ausente y no deje un " - " colgando.
	 */
	public function getListado($grupo_id)
	{
		$user = User::fromToken();
		$consulta = 'SELECT m.alumno_id, a.user_id, u.username, a.nombres, a.apellidos, a.sexo, a.fecha_nac, m.estado,
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						CONCAT_WS(" - ", NULLIF(a.direccion, ""), NULLIF(a.barrio, "")) as direccion, a.facebook, a.pazysalvo, a.deuda
					FROM alumnos a
					inner join matriculas m on m.alumno_id=a.id and m.grupo_id=:grupo_id and (m.estado="PREM" OR m.estado="MATR" OR m.estado="ASIS") and m.deleted_at is null 
					left join users u on u.id=a.user_id
					left join images i on i.id=u.imagen_id
					left join images i2 on i2.id=a.foto_id
					where a.deleted_at is null order by apellidos, nombres';

		$list = DB::select($consulta, array(':grupo_id'=>$grupo_id));
		
		return $list;
	}


	/**
	 * Los alumnos que hay DETRÁS de cada número de «Alumnos por grupo».
	 *
	 * La tabla del panel de `app2` pinta por grupo la cantidad de alumnos, el
	 * desglose por sexo y el movimiento de cada periodo, y hasta hoy no había
	 * forma de ver QUIÉNES son. `grupos/listado/{grupo_id}` no sirve para eso:
	 * incluye los PREM y ninguno de estos cinco contadores los cuenta —desde el 31
	 * ago 2026, ver el docblock de `putConCantidadAlumnos`—, así que el listado no
	 * cuadraría con el número. Y un listado que no cuadra con la cifra que lo abrió
	 * es peor que no tener listado.
	 *
	 * POR ESO CADA CASO REPITE EL `WHERE` DE SU CONTADOR Y SOLO LE CAMBIA EL
	 * `SELECT`. Los contadores están arriba, en `getCantAlumnos` y
	 * `putConCantidadAlumnos`; si alguno se toca, este método se toca en el mismo
	 * commit. Lo ata `tests/Contrato/AlumnosDetrasDelNumeroTest.php`, que compara
	 * cada cifra con la longitud de su listado, grupo a grupo y periodo a periodo:
	 * es un test de CUADRE, no de forma, y es el único motivo de que este método
	 * duplique SQL en vez de reutilizar el de al lado.
	 *
	 * Lo que se copia tal cual **aunque parezca un fallo**, porque arreglarlo aquí
	 * y no en el contador es exactamente descuadrarlos:
	 *
	 * - `matriculados` no filtra por estado: cuenta hasta los RETI y los FORM.
	 * - Un alumno con dos matrículas vivas en el mismo grupo sale dos veces, igual
	 *   que lo cuenta dos veces el `count(m.id)` de la celda. Deduplicar aquí
	 *   rompería el cuadre, que es lo único que este método garantiza.
	 *
	 * El `g.year_id` sí se añade a los cinco, y no rompe la copia: cuatro de los
	 * contadores no lo llevan en su `WHERE` porque la lista de grupos sobre la que
	 * iteran ya venía filtrada por el año de quien pregunta. Sobre los grupos que
	 * la pantalla pinta filtra lo mismo; sin él, esta ruta contestaría por grupos
	 * de otros años que esa tabla no enseña.
	 */
	public function getAlumnosDe($grupo_id, $que)
	{
		$user = User::fromToken();

		// El estado y el sexo van colgados del `ON` del `INNER JOIN`, como en los
		// contadores. Sobre un `INNER JOIN` filtran igual que en el `WHERE`, y
		// dejarlos donde están es lo que permite comparar las dos consultas línea
		// a línea el día que alguien toque una.
		$casos = [
			'alumnos' => [
				'estado' => 'and (m.estado="ASIS" or m.estado="MATR")',
				'sexo'   => '',
				'rango'  => '',
				'extra'  => '',
			],
			'hombres' => [
				'estado' => 'and (m.estado="MATR" or m.estado="ASIS")',
				'sexo'   => 'and a.sexo="M"',
				'rango'  => '',
				'extra'  => '',
			],
			'mujeres' => [
				'estado' => 'and (m.estado="MATR" or m.estado="ASIS")',
				'sexo'   => 'and a.sexo="F"',
				'rango'  => '',
				'extra'  => '',
			],
			'retirados' => [
				'estado' => 'and (m.estado="RETI" or m.estado="DESE")',
				'sexo'   => '',
				'rango'  => ' and m.fecha_retiro>=? and m.fecha_retiro<=?',
				'extra'  => ', m.fecha_retiro',
			],
			'matriculados' => [
				'estado' => '',
				'sexo'   => '',
				'rango'  => ' and m.fecha_matricula>=? and m.fecha_matricula<=?',
				'extra'  => ', m.fecha_matricula',
			],
		];

		if (! isset($casos[$que])) {
			abort(422, 'No hay listado «'.$que.'»: los que hay son '.implode(', ', array_keys($casos)).'.');
		}

		$caso 			= $casos[$que];
		$parametros 	= [$grupo_id, $user->year_id];

		if ($caso['rango'] !== '') {
			// Las fechas se comparan con `>=` y `<=`, y hasta la noche del 31 ago 2026
			// eran `>` y `<` en los dos contadores. Se arreglaron los tres a la vez
			// —los dos de `putConCantidadAlumnos` y éste—, que era la condición: quien
			// se matricula el primer día de un periodo no estaba en ninguna cifra.
			//
			// El índice del periodo es el que pinta la cabecera Ret1/Mat1, que es la
			// POSICIÓN en `Periodo::delYear` (`Per = $i + 1`) y no `periodos.numero`.
			// Se resuelve igual aquí para que el N que manda el front sea el mismo N.
			$periodos 	= Periodo::delYear($user->year_id);
			$numero 	= Request::input('periodo');

			if (! is_numeric($numero) || (int) $numero < 1 || (int) $numero > count($periodos)) {
				abort(422, 'El periodo tiene que ser un número entre 1 y '.count($periodos).'.');
			}

			$periodo 		= $periodos[(int) $numero - 1];
			$parametros[] 	= $periodo->fecha_inicio;
			$parametros[] 	= $periodo->fecha_fin;
		}

		// La foto se resuelve como en `getListado`: la imagen del alumno, y si no
		// tiene, el maniquí que le toque por sexo.
		$consulta = 'SELECT m.alumno_id, a.nombres, a.apellidos, a.sexo, m.estado,
						a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre'.$caso['extra'].'
					from grupos g
					INNER JOIN matriculas m ON m.grupo_id=g.id and m.deleted_at is null '.$caso['estado'].'
					INNER JOIN alumnos a ON a.id=m.alumno_id and a.deleted_at is null '.$caso['sexo'].'
					left join images i on i.id=a.foto_id
					where g.deleted_at is null and g.id=? and g.year_id=?'.$caso['rango'].'
					order by a.apellidos, a.nombres';

		// Un array plano, como `getListado`. Un grupo sin nadie devuelve `[]` y no
		// un 404: hay grupos abiertos con cero matriculados y esa celda dice 0.
		return DB::select($consulta, $parametros);
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
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
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


	/**
	 * Editar un grupo. Con medio formulario se llevaba por delante el resto — §153.
	 *
	 * Diez columnas, nueve leídas con `Request::input('x')` **sin defecto**, y la
	 * décima es la que enseña la trampa: `caritas` tenía defecto —`false`— y por
	 * eso salía como «a salvo» en el barrido, **pero ese defecto la apaga**. Es la
	 * §68 con casco: el `is_active` de aquella también tenía defecto, y por eso el
	 * que se pisaba era justo ése.
	 *
	 * Y las caritas no son cosméticas: deciden si el grupo se califica con la
	 * escala de preescolar en vez de con números. Corregirle el nombre a un grupo
	 * de preescolar le cambiaba la forma de evaluar.
	 *
	 * El defecto es el valor que ya tiene la fila, no una constante: mandar el
	 * campo vacío a propósito sigue vaciándolo. `titular_id` y `grado_id` se
	 * dejan como estaban —su cadena de `if` ya distingue tres formas de nombrarlos
	 * y tocarla es otro arreglo—, pero con el suyo como último recurso en vez de
	 * `null`.
	 */
	public function putUpdate()
	{
		$user = User::fromToken();
		$grupo = Grupo::findOrFail(Request::input('id'));

		try {

			if (Request::input('titular_id')) {
				$titular_id = Request::input('titular_id');
			}else if (Request::input('titular')) {
				$titular_id = Request::input('titular')['profesor_id'];
			}else{
				$titular_id = $grupo->titular_id;
			}

			if (Request::input('grado_id')) {
				$grado_id = Request::input('grado_id');
			}else if (Request::input('grado')) {
				$grado_id = Request::input('grado')['id'];
			}else{
				$grado_id = $grupo->grado_id;
			}

			$grupo->nombre		=	Request::input('nombre', $grupo->nombre);
			$grupo->abrev		=	Request::input('abrev', $grupo->abrev);
			// **El año NO se toca al editar** — §154. Aquí ponía `$user->year_id`, sin
			// leer nunca el cuerpo, y el front tampoco lo manda: ni la rejilla
			// (`GruposCtrl`) ni el formulario (`GruposEditCtrl`) incluyen `year_id`.
			// O sea que lo que se escribía era siempre el año del que edita, y eso
			// es una de dos cosas: o el grupo ya estaba en su año —el 99% de las
			// veces, y no pasa nada— o **se lo llevaba**, con sus matrículas dentro,
			// porque cuelgan del grupo y no del año. Medido: corregirle la
			// abreviatura a un grupo del año 7 lo pasaba al 8 con **56 matrículas**.
			//
			// Y no hay forma de que el cliente lo pida, así que no se le da una: el
			// año de un grupo se decide al crearlo, y `postStore` lo sigue tomando
			// del token, que ahí es la única fuente posible. Mover un grupo de año es
			// otra operación y hoy no existe.
			$grupo->titular_id	=	$titular_id;
			$grupo->grado_id	=	$grado_id;
			$grupo->valormatricula=	Request::input('valormatricula', $grupo->valormatricula);
			$grupo->valorpension=	Request::input('valorpension', $grupo->valorpension);
			$grupo->orden		=	Request::input('orden', $grupo->orden);
			$grupo->caritas		=	Request::input('caritas', $grupo->caritas);
			$grupo->cupo		=	Request::input('cupo', $grupo->cupo);
			$grupo->updated_by	=	$user->user_id;

			$grupo->save();

			return $grupo;
		} catch (\Exception $e) {
			abort(422, 'Datos incorrectos');
		}
	}



	/**
	 * Manda un grupo a la papelera. La mitad que faltaba — §100.
	 *
	 * De las cuatro operaciones de papelera de un grupo, `forcedelete` y
	 * `restore` piden superusuario desde la §28.4 y la §76, y **las dos que
	 * mandan a la papelera se habían quedado con `auth.personal` a secas**: ésta y
	 * su duplicada `perfiles/destroy`, que es la misma línea bajo otra URL. Es el
	 * §97 por tercera vez esta noche: la pareja se cierra entera o no se cierra.
	 *
	 * Y aquí el aviso llevaba escrito desde antes, en dos sitios a la vez —el
	 * docblock del `forcedelete` de `PerfilesController` y la cabecera de
	 * `PerfilesApi.ts` en el front—, los dos diciendo que cerrar una sola no cierra
	 * nada. **Un aviso en prosa no defiende**: por eso esto va con su test.
	 *
	 * Nadie pierde un botón que hoy vea: la X de la rejilla de grupos
	 * (`GruposCtrl.eliminar`) vive en «Editar Grupos», que el menú del front
	 * enseña con `hasRoleOrPerm(['admin'])`, y los diez `Admin` son exactamente
	 * los diez `is_superuser` (§28.4). Y no se sube a `esAdministrativo` porque
	 * crear un rol no regala permisos: el alcance del Secretario no nombra dar de
	 * baja un grupo.
	 *
	 * **Población cerrada: las dos.** `perfiles/destroy` en el mismo commit.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'No tienes permiso para eliminar grupos.');

		$grupo = Grupo::findOrFail($id);
		$grupo->deleted_by = $user->user_id;
		$grupo->save();
		$grupo->delete();

		return $grupo;
	}
	public function deleteForcedelete($id)
	{
		$user = User::fromToken();

		// Este es el endpoint más destructivo del sistema y era el único de la
		// papelera sin ninguna comprobación de autorización: bastaba un token
		// válido, y el de cualquier alumno servía.
		//
		// forceDelete sobre un grupo cascadea, por FK ON DELETE CASCADE, a 27
		// tablas y hasta 6 saltos de profundidad:
		//   grupos > asignaturas > unidades > subunidades > notas
		// o sea, se lleva las notas de todo el mundo en las asignaturas de ese
		// grupo. Sus hermanos sí comprobaban: alumnos/forcedelete exige
		// superusuario o secretario, y unidades/forcedelete pasa por
		// pueden_editar_notas. Este no comprobaba nada.
		//
		// Se aplica el criterio de alumnos/forcedelete sin la rama de profesor:
		// borrar un grupo definitivamente no es tarea docente.
		// Superusuario: 27 tablas en cascada, seis saltos, y llega a `notas`.
		// La §28.4 lo fijó y el alcance del Secretario no lo nombra.
		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'No tienes permiso para eliminar grupos definitivamente.');

		$grupo = Grupo::onlyTrashed()->findOrFail($id);
		
		$grupo->forceDelete();
		return $grupo;
	
	}

	/*
	 * Restaurar pide lo mismo que borrar definitivamente, y hasta el 22 ago 2026
	 * no pedía nada.
	 *
	 * Cada operación de la papelera es una pareja, y el 21 ago se cerró **una
	 * mitad de cada una**: `forcedelete` quedó anclado a superusuario y `restore`,
	 * en el mismo controlador y dos métodos más abajo, se quedó como estaba —
	 * bastaba `auth.personal`, o sea cualquiera de los 51 profesores—. La cabecera
	 * de `Autoriza` nombra los cinco sitios de los que venía aquello: grupos,
	 * perfiles, profesores, years y editnota. Son los mismos cinco.
	 *
	 * El criterio es el del gemelo destructivo y no uno nuevo, a propósito: la
	 * regla de `Autoriza` es que crear un rol no regale permisos, y
	 * `esAdministrativo` incluiría al `Secretario` del día que exista sin que
	 * nadie lo haya pedido. Hoy los dos criterios son las mismas diez personas
	 * —`is_superuser` y el rol `Admin` coinciden fila por fila, §28.4— y la
	 * pantalla de papelera del front ya se enseña sólo con `hasRoleOrPerm('admin')`,
	 * así que **nadie pierde un botón que hoy vea**. Subirlo a `esAdministrativo`
	 * es una palabra el día que se decida; está anotado en 09 §5.
	 */
	public function putRestore($id)
	{
		$user = User::fromToken();

		Autoriza::exigir(Autoriza::esSuperusuario($user),
			'No tienes permiso para restaurar grupos.');

		$grupo = Grupo::onlyTrashed()->findOrFail($id);

		$grupo->restore();
		return $grupo;
	}



	public function getTrashed()
	{
		$grupos = Grupo::onlyTrashed()->get();
		return $grupos;
	}

}