<?php namespace App\Http\Controllers;

use Request;
use DB;
use Carbon\Carbon;

use App\User;
use App\Models\Nota;
use App\Models\Profesor;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Asignatura;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Bitacora;
use App\Models\FraseAsignatura;
use App\Http\Controllers\Informes\PuestosController;
use \Log;


class NotasController extends Controller {



	public function putDetailed()
	{
		$user = User::fromToken();
		
		$profe_id 			= Request::input('profesor_id');
		$asignatura_id 		= Request::input('asignatura_id');
		$con_asignaturas 	= Request::input('con_asignaturas');

		$resultado = [];
		
		// Unidades con Subunidades
		$unidadesT 			= DB::select('SELECT * FROM unidades u WHERE u.asignatura_id=? and u.deleted_at is null and u.periodo_id=? order by u.orden, u.id', [$asignatura_id, $user->periodo_id]);
		$unidades 			= [];
		$orden_duplicado 	= false;
		$orden_anterior 	= -5;
		
		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		foreach ($unidadesT as $unidad) {
			$subunidades = DB::select('SELECT * FROM subunidades s WHERE s.unidad_id=? and s.deleted_at is null order by s.orden, s.id', [$unidad->id]);

			foreach ($subunidades as $subunidad) {
				Nota::verificarCrearNotas($asignatura->grupo_id, $subunidad, $user->user_id);
			}

			// A veces hay varios con el mismo número en el orden, debo encontrarlo y arreglarlo.
			if ($orden_anterior == $unidad->orden) {
				$orden_duplicado = true;
			}else{
				$orden_anterior = $unidad->orden;
			}

			$unidad->subunidades = $subunidades;
			if (count($subunidades) > 0) {
				array_push($unidades, $unidad);
			}
		}


		$unidadesT = Unidad::arreglarOrden($unidadesT, $asignatura_id, $user->periodo_id);
		

		// alumnos con sus notas
		$alumnos = Grupo::alumnos($asignatura->grupo_id);

		foreach ($alumnos as $alumno) {

			$userData = Alumno::userData($alumno->alumno_id);
			$alumno->userData = $userData;
			$frases = FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno->alumno_id, $user->periodo_id);
			$alumno->frases = $frases;

			// Ausencias
			$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
						inner join periodos p on p.id=a.periodo_id and p.id=:per_id
						WHERE a.tipo='ausencia' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$ausencias = DB::select($cons_aus, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura->asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			$alumno->ausencias 			= $ausencias;
			$alumno->ausencias_count 	= count($ausencias);

			// Tardanzas
			$cons_tar = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
						inner join periodos p on p.id=a.periodo_id and p.id=:per_id
						WHERE a.tipo='tardanza' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$tardanzas = DB::select($cons_tar, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura->asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			
			// Notas
			$cons = "SELECT n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by, n.deleted_by, n.deleted_at, n.created_at, n.updated_at, u.asignatura_id,
							s.porcentaje/100 as subunidad_porc, u.porcentaje/100 as unidad_porc, s.definicion, s.porcentaje as subunidad_porcentaje, u.orden as orden_unidad, s.orden as orden_subunidad
						FROM notas n
						INNER JOIN alumnos a ON a.id=n.alumno_id and n.deleted_at is null
						INNER JOIN subunidades s ON s.id=n.subunidad_id and s.deleted_at is null
						INNER JOIN unidades u ON u.id=s.unidad_id and u.deleted_at is null and u.periodo_id=:per_id
						INNER JOIN asignaturas asi ON asi.id=u.asignatura_id and asi.deleted_at is null and asi.grupo_id=:grupo_id
						WHERE n.alumno_id=:alumno_id and asi.id=:asignatura_id
						order by u.orden, s.orden;";
			$notas = DB::select($cons, [":per_id" => $user->periodo_id, ':grupo_id' => $asignatura->grupo_id, ':alumno_id' => $alumno->alumno_id, ':asignatura_id' => $asignatura->asignatura_id ]);
			
			
			// Traemos las Definitivas
			$cons_nf  = 'SELECT a.id as alumno_id, a.no_matricula, nf1.periodo, u.username as updated_by_username,
							nf1.nota as nota_final, nf1.id as nf_id, nf1.recuperada, nf1.manual, nf1.updated_by, nf1.created_at, nf1.updated_at,
							cast(r1.DefMateria as decimal(4,1)) as def_materia_auto, r1.updated_at as updated_at_def, IF(nf1.updated_at > r1.updated_at, FALSE, TRUE) AS nfinal_desactualizada 
						FROM alumnos a 
						left join notas_finales nf1 on nf1.alumno_id=a.id and nf1.asignatura_id=:asign_id1 and nf1.periodo=:periodo
						left join users u on u.id=nf1.updated_by 
						left join (
							SELECT df1.alumno_id, df1.periodo_id, MAX(df1.updated_at) as updated_at, df1.numero_periodo, sum( df1.ValorUnidad ) DefMateria 
							FROM(
								SELECT n.alumno_id, u.periodo_id, u.id as unidad_id, p1.numero as numero_periodo, MAX(n.updated_at) as updated_at, 
									sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad
								FROM asignaturas asi 
								inner join unidades u on u.asignatura_id=asi.id and u.deleted_at is null
								inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
								inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
								inner join periodos p1 on p1.numero='.$user->numero_periodo.' and p1.id=u.periodo_id and p1.deleted_at is null
								where asi.deleted_at is null and asi.id=:asign_id2
								group by n.alumno_id, s.unidad_id, s.id
							)df1
							group by df1.alumno_id, df1.periodo_id
						)r1 ON r1.alumno_id=a.id
						where a.deleted_at is null and a.id=:alumno_id';
				
			$nota_final = DB::select($cons_nf, [':asign_id1'=>$asignatura->asignatura_id, ':periodo'=>$user->numero_periodo, ':asign_id2'=>$asignatura->asignatura_id, ':alumno_id'=>$alumno->alumno_id])[0];
			
			$now 		= Carbon::now('America/Bogota');
			
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
				VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
		
			if (!$nota_final->manual && !$nota_final->recuperada) {
				DB::delete('DELETE FROM notas_finales WHERE id=?', [ $nota_final->nf_id ]);
				
				DB::insert($consulta, [':alumno_id' => $alumno->alumno_id, ':asignatura_id' => $asignatura_id, ':periodo_id' => $user->periodo_id, 
					':periodo' => $user->numero_periodo, ':nota' => round($nota_final->def_materia_auto), ':recuperada' => 0, ':manual' => 0, ':updated_by' => $user->user_id, ':created_at' => $now, ':updated_at' => $now ]);
			}
				
			$nota_final = DB::select($cons_nf, [':asign_id1'=>$asignatura->asignatura_id, ':periodo'=>$user->numero_periodo, ':asign_id2'=>$asignatura->asignatura_id, ':alumno_id'=>$alumno->alumno_id])[0];
			
			

			$alumno->nota_final 		= $nota_final;
			$alumno->notas 				= $notas;
			$alumno->tardanzas 			= $tardanzas;
			$alumno->tardanzas_count 	= count($tardanzas);

		}
		
		
		// Traermos las asignaturas si las pidieron
		if ($con_asignaturas) {
			$asignaturas 				= Profesor::asignaturas($user->year_id, $profe_id);
			$resultado['asignaturas'] 	= $asignaturas;
		}

		
		$resultado['asignatura'] 	= $asignatura;
		$resultado['alumnos'] 		= $alumnos;
		$resultado['unidades'] 		= $unidades;
		

		return $resultado;
	}

	
	
	
	public function getAlumno($alumno_id='', $grupo_id='')
	{
		$user = User::fromToken();


		if ($user->alumnos_can_see_notas==false) {
			$usuario = User::find($user->user_id);
			if ($usuario->tipo == 'Alumno' || $usuario->tipo=='Acudiente') {
				return 'Sistema bloqueado. No puedes ver las notas';				
			}
		}

		if ($alumno_id=='') {
			if ($user->tipo == 'Alumno') {
				$alumno_id = $user->persona_id;
			}else{
				return abort(400, 'No hay id de alumno');
			}
		}

		$profesor_id = '';

		if ($user->tipo == 'Profesor') {
			$profesor_id = $user->persona_id;
		}
		

		$datos = Nota::alumnoPeriodosDetailed($alumno_id, $user->year_id, $profesor_id);

		
		// Definitivas hasta el tercer periodo para calcular nota faltante
		$puestosCtrl 	= new PuestosController();
		$consulta 		= $puestosCtrl->consulta_notas_finales_alumno3;
		$notas_asig     = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
		
		foreach ($notas_asig as $keyAsig => $asignatura) {
			$asignatura->nota_final_year = round($asignatura->nota_final_year);
		}
		$datos->notas_tercer_per = $notas_asig;
		// !! Definitivas
		
		
		if ($user->tipo == 'Acudiente') {
			if ($datos->pazysalvo){
				return [$datos];
			}else{
				return ['msg'=>'No está a pazysalvo'];
			}
		}
		
		return [$datos];
	}



	
	
	public function putAlumnoPeriodoGrupo()
	{
		$user = User::fromToken();


		if(($user->is_superuser && $user->is_superuser) || $user->tipo == 'Profesor'){
			// Todo bien
		}else{
			return App::abort(400, 'No tienes permiso.');
		}

		$alumno_id 	= Request::input('alumno_id');
		$periodo_id = Request::input('periodo_id');
		$grupo_id 	= Request::input('grupo_id');

		$profesor_id = '';

		if ($user->tipo == 'Profesor') {
			$profesor_id = $user->persona_id;
		}
		
		$periodo 	= DB::select('SELECT * FROM periodos WHERE id=? and deleted_at is null', [ $periodo_id ])[0]; 


		Nota::alumnoPeriodoDetalle($periodo, $grupo_id, $alumno_id, $periodo->year_id, $profesor_id);


		return ['notas' => $periodo];
	}



	
	
	public function getShow($nota_id)
	{
		$user 	= User::fromToken();
		$nota 	= Nota::find($nota_id);
		return $nota;
	}

	
	


	
	public function putUpdate($id)
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		
		User::pueden_editar_notas($user);
		
		try {

			$consulta 	= 'SELECT n.*, h.id as history_id FROM notas n, 
								(select * from historiales where user_id=? and deleted_at is null order by id desc limit 1 ) h 
							WHERE n.id=? and n.deleted_at is null ';

			$nota 		= DB::select($consulta, [$user->user_id, $id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= $nota->history_id;
			$bit_old 	= $nota->nota; 				// Guardo la nota antigua
			$bit_new 	= Request::input('nota'); 	// Guardo la nota nueva
			$bit_per 	= $user->periodo_id;

			$nota->nota 		= $bit_new;
			$nota->updated_at 	= $now;
			$nota->updated_by 	= $user->user_id;

			$consulta 	= 'UPDATE notas SET nota=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [$bit_new, $user->user_id, $now, $id]);

			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type, affected_element_type, affected_element_id, affected_element_new_value_int, affected_element_old_value_int, created_at) 
						VALUES (?, ?, ?, "Al", "Nota", ?, ?, ?, ?)';

			DB::insert($consulta, [$bit_by, $bit_hist, $nota->alumno_id, $id, $bit_new, $bit_old, $now]);
			
		} catch (Exception $e) {
			return abort(400, 'No se pudo guardar la nota');
		}
		
		
		if (Request::has('asignatura_id')) {
			# code...
		}

		
	
		return (array)$nota;
	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		User::pueden_editar_notas($user);
		$consulta 	= 'DELETE FROM notas WHERE id=?';
		DB::delete($consulta, [$id]);

		return 'Eliminada';
	}
	
	
	
	// Para notas individuales en horario hoy
	public function putSubunidad()
	{
		$user 			= User::fromToken();
		$grupo_id 		= Request::input('grupo_id');
		$subunidad 		= Request::input('subunidad');
		$asignatura_id 	= Request::input('asignatura_id');
		$sub_id 		= $subunidad ? $subunidad["id"] : null;
		$nota_default 	= $subunidad ? $subunidad["nota_default"] : null;
		$now 			= Carbon::now('America/Bogota');


		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.nombres, a.apellidos, a.sexo, a.user_id, a.fecha_nac, 
				m.grupo_id, m.estado, 
				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
				m.fecha_retiro as fecha_retiro 
			FROM alumnos a 
			inner join matriculas m on a.id=m.alumno_id and m.grupo_id=? and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null 
			left join users u on a.user_id=u.id and u.deleted_at is null
			left join images i on i.id=u.imagen_id and i.deleted_at is null
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			where a.deleted_at is null and m.deleted_at is null
			order by a.apellidos, a.nombres';
		
		$alumnos = DB::select($consulta, [$grupo_id]);
		
		
		foreach ($alumnos as $alumno) {
			
			if ($sub_id) {
				$consulta = "INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at) 
						SELECT * FROM 
						(SELECT '.$sub_id.' as subunidad_id, '.$alumno->alumno_id.' as alumno_id, '.$nota_default.' as nota, '.$user->user_id.' as created_by, '.$now.' as created_at, '.$now.' as updated_at) AS tmp
							WHERE NOT EXISTS (
								SELECT * from notas WHERE subunidad_id=? and alumno_id=? and deleted_at is null
							) LIMIT 1";
								
				DB::insert($consulta, [ $sub_id, $alumno->alumno_id ]);
				
				// Notas
				$cons = "SELECT n.id, n.nota, n.subunidad_id, n.alumno_id, n.created_by, n.updated_by, n.deleted_by, n.deleted_at, n.created_at, n.updated_at
					FROM notas n
					WHERE n.alumno_id=:alumno_id and n.subunidad_id=:subunidad_id;";
			
				$nota = DB::select($cons, [':alumno_id' => $alumno->alumno_id, ':subunidad_id' => $sub_id ]);
				$alumno->nota 				= $nota[0];
			}
			
			
			
			$frases = FraseAsignatura::deAlumno($asignatura_id, $alumno->alumno_id, $user->periodo_id);
			$alumno->frases = $frases;

			// Ausencias
			$cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.id=:per_id
					WHERE a.tipo='ausencia' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$ausencias = DB::select($cons_aus, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			$alumno->ausencias 			= $ausencias;
			$alumno->ausencias_count 	= count($ausencias);

			// Tardanzas
			$cons_tar = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
					inner join periodos p on p.id=a.periodo_id and p.id=:per_id
					WHERE a.tipo='tardanza' and a.asignatura_id=:asignatura_id and a.alumno_id=:alumno_id and a.deleted_at is null;";
			$tardanzas = DB::select($cons_tar, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			
			$alumno->tardanzas 			= $tardanzas;
			$alumno->tardanzas_count 	= count($tardanzas);


			// Uniformes
			$cons_uni = "SELECT u.id, u.asignatura_id, u.materia, u.alumno_id, u.periodo_id, u.contrario, u.sin_uniforme, u.incompleto, u.cabello, u.accesorios, u.otro1, u.camara, u.excusado, u.fecha_hora, u.uploaded, u.created_by, u.descripcion 
					FROM uniformes u
					inner join periodos p on p.id=u.periodo_id and p.id=:per_id
					WHERE u.asignatura_id=:asignatura_id and u.alumno_id=:alumno_id and u.deleted_at is null;";
			$uniformes = DB::select($cons_uni, [":per_id" => $user->periodo_id, ':asignatura_id' => $asignatura_id, ':alumno_id' => $alumno->alumno_id ]);
			
			$alumno->uniformes 			= $uniformes;
			$alumno->uniformes_count 	= count($uniformes);

		}
		
		
		return [ 'alumnos'=> $alumnos ];
	}

}