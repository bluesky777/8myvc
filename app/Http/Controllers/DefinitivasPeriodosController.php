<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

use App\User;
use App\Models\Year;
use App\Models\Profesor;
use App\Models\Asignatura;
use App\Models\Unidad;
use App\Models\Grupo;
use App\Models\NotaFinal;
use App\Models\Debugging;
use App\Http\Controllers\Alumnos\Definitivas;
use \Log;

use App\Http\Controllers\Alumnos\Solicitudes;
use App\Support\PeriodoDeLaFila;


class DefinitivasPeriodosController extends Controller {

	public function getIndex()
	{
		$user 			= User::fromToken();

		if ($user->tipo == 'Profesor') {
			$profe_id = $user->persona_id;
		} else if($user->is_superuser){
			$profe_id = Request::input('profesor_id');
		}
		
		
		$definitivas 	= new Definitivas();
		$asignaturas 	= $definitivas->asignaturas_docente($profe_id, $user->year_id);
		
		$cantAsig 		= count($asignaturas);
		
		for ($i=0; $i < $cantAsig; $i++) { 
			
			$asignaturas[$i]->alumnos = NotaFinal::alumnos_grupo_nota_final($asignaturas[$i]->grupo_id, $asignaturas[$i]->asignatura_id, $user->user_id);
			
		}
		
		return $asignaturas;
	}


	public function putCalcularNotasFinalesAsignatura()
	{
		$user 			= User::fromToken();

		if ($user->tipo == 'Profesor' || $user->is_superuser) {
			// Aquí un error por arreglar
			$asignatura_id 	= Request::input('profesor_id');
		}else{
			return 'No tienes privilegios';
		}
		
		$definitivas 	= new Definitivas();
		$definitivas->calcular_notas_finales_asignatura($asignatura_id);
		
		$cantAsig 		= count($asignaturas);
		
		for ($i=0; $i < $cantAsig; $i++) { 
			
			$asignaturas[$i]->alumnos = NotaFinal::alumnos_grupo_nota_final($asignaturas[$i]->grupo_id, $asignaturas[$i]->asignatura_id);
			
		}
		
		return $asignaturas;
	}


	

	public function putCalcularGrupoPeriodo()
	{
		$user 			= User::fromToken();
		$grupo_id 		= Request::input('grupo_id');
		$periodo_id 	= Request::input('periodo_id');
		$num_periodo 	= Request::input('num_periodo');
		$now 			= Carbon::now('America/Bogota');

		if ($user->tipo == 'Profesor' || $user->is_superuser) {
			//$profesor_id 	= Request::input('profesor_id');
		}else{
			return abort(400, 'No tienes privilegios.');
		}
		
		DB::delete('DELETE nf FROM notas_finales nf INNER JOIN asignaturas a ON a.id=nf.asignatura_id and a.grupo_id=? 
					WHERE (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0) and nf.periodo_id=?', 
					[ $grupo_id, $periodo_id ]);
		
		$consulta = 'SELECT nt.alumno_id, asi.id as asignatura_id, nt.periodo_id, cast(sum(nt.ValorNota) as decimal(4,0)) as nota_asignatura
				FROM asignaturas asi 
				inner join 
					(select u.asignatura_id, n.alumno_id, u.periodo_id, sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorNota
					from unidades u 
					inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.periodo_id=:periodo_id
					inner join notas n on n.subunidad_id=s.id and n.deleted_at is null
					inner join asignaturas asi2 on asi2.id=u.asignatura_id and asi2.deleted_at is null and asi2.grupo_id=:grupo_id
					where  u.deleted_at is null
					group by n.alumno_id, u.id, s.id
				) nt ON asi.id=nt.asignatura_id and asi.grupo_id=:grupo_id2 
				where asi.deleted_at is null
				group by nt.alumno_id, asi.id, nt.periodo_id';
			
		$defi_autos = DB::select($consulta, [ ':periodo_id'=>$periodo_id, ':grupo_id'=>$grupo_id, ':grupo_id2'=>$grupo_id ]);
		$cant_def = count($defi_autos);
					
		for ($i=0; $i < $cant_def; $i++) {

			$nota_asignatura = 0;
			if ($defi_autos[$i]->nota_asignatura) {
				$nota_asignatura = $defi_autos[$i]->nota_asignatura;
			}

			// **Los diez valores van ligados; antes iban concatenados.** Dos de ellos
			// —`$num_periodo` y `$periodo_id`— salen directos de `Request::input`
			// veinte líneas más arriba, así que esto era una inyección sobre un
			// INSERT en `notas_finales`, dentro de un bucle y al alcance de
			// cualquiera de los 51 profesores que pasa `auth.personal`.
			//
			// Lo encontró otra sesión el 22 ago 2026 mirando el hermano de esta
			// forma en `Disciplina\OrdinalesController::putOrdinales`, que tenía la
			// misma concatenación en un SELECT. Aquí pesa más porque **escribe**.
			//
			// Se liga y nada más: ni una línea de lógica. Este método es uno de los
			// seis escritores que la fase 3 de 10-definitivas.md va a sustituir por
			// `DefinitivasDeAsignatura`, así que el bloque entero está condenado —
			// pero **sigue desplegado en los dieciséis colegios** y la fase 3 no
			// tiene fecha, que es lo que decide que valga la pena arreglarlo ahora.
			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
						SELECT * FROM (SELECT ? as alumno_id, ? as asignatura_id, ? as periodo_id, ? as periodo,
						? as nota_asignatura, 0 as recuperada, 0 as manual, ? as crea, ? as fecha, ? as fecha2) AS tmp
						WHERE NOT EXISTS (
							SELECT id FROM notas_finales WHERE alumno_id=? and asignatura_id=? and periodo_id=?
						) LIMIT 1';

			DB::select($consulta, [
				$defi_autos[$i]->alumno_id, $defi_autos[$i]->asignatura_id,
				$defi_autos[$i]->periodo_id, $num_periodo,
				$nota_asignatura, $user->user_id, $now, $now,
				$defi_autos[$i]->alumno_id, $defi_autos[$i]->asignatura_id, $periodo_id,
			]);
			
		}
		
		return 'Calculado';
	}


	
	
	
	public function putUpdate()
	{
		$user 			= User::fromToken();
		// Las dos ramas de este método escriben en sitios distintos y por eso el
		// periodo se saca distinto:
		//
		//   - con `nf_id` se ACTUALIZA una nota final que ya existe, así que manda
		//     su `periodo_id` y no lo que diga el cuerpo. Es la llamada de la
		//     rejilla de definitivas, la que la §27.1 daba por la más difícil: el
		//     front manda `nf_id` y `num_periodo` sin `periodo_id`.
		//   - sin `nf_id` se INSERTA una fila nueva con `periodo_id` sacado de
		//     `num_periodo` veinte líneas más abajo. Ahí declarar y escribir son la
		//     misma cosa, así que comprobar el declarado sí es comprobar el escrito.
		User::pueden_modificar_definitivas($user, Request::input('nf_id')
			? PeriodoDeLaFila::deNotaFinal(Request::input('nf_id'))
			: PeriodoDeLaFila::porNumero($user, Request::input('num_periodo')));
		
		$now 		= Carbon::now('America/Bogota');
		
		if (Request::input('nf_id')) {
			$nf_id 		= Request::input('nf_id');
			
			$consulta 	= 'SELECT n.*, h.id as history_id FROM notas_finales n, 
								(select * from historiales where user_id=? and deleted_at is null order by id desc limit 1 ) h 
							WHERE n.id=? ';

			$nota 		= DB::select($consulta, [$user->user_id, $nf_id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= $nota->history_id;
			$bit_old 	= $nota->nota; 				// Guardo la nota antigua
			$bit_new 	= Request::input('nota'); 	// Guardo la nota nueva
			$bit_per 	= $user->periodo_id;

			
			$consulta 	= 'UPDATE notas_finales SET nota=?, manual=true, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ Request::input('nota'), $user->user_id, $now, $nf_id ]);
			
			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type, affected_element_type, affected_element_id, affected_element_new_value_int, affected_element_old_value_int, created_at) 
						VALUES (?, ?, ?, "Al", "NF_UPDATE", ?, ?, ?, ?)';

			DB::insert($consulta, [$bit_by, $bit_hist, $nota->alumno_id, $nf_id, $bit_new, $bit_old, $now]);

			return 'Cambiada';
		}else{

			$num_periodo 	= Request::input('num_periodo');
			$periodos 		= DB::select('SELECT * FROM periodos WHERE deleted_at is null and numero=? and year_id=?', [$num_periodo, $user->year_id]);
			
			if (count($periodos) > 0) {
				$periodo = $periodos[0];
			}else{
				return abort(400, 'No existe el peridoo.');
			}

			$consulta = 'INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, updated_by, created_at, updated_at) 
				VALUES(:alumno_id, :asignatura_id, :periodo_id, :periodo, :nota, :recuperada, :manual, :updated_by, :created_at, :updated_at)';
	
			DB::insert($consulta, [':alumno_id' => Request::input('alumno_id'), ':asignatura_id' => Request::input('asignatura_id'), ':periodo_id' => $periodo->id, 
							':periodo' => $num_periodo, ':nota' => Request::input('nota'), ':recuperada' => 0, ':manual' => 1, ':updated_by' => $user->user_id, ':created_at' => $now, ':updated_at' => $now ]);
			
			$last_id = DB::getPdo()->lastInsertId();
			return DB::select('SELECT * FROM notas_finales WHERE id=?', [$last_id]);
		}
		
		
	}


	
	
	public function putUpdateRecuperacion()
	{
		$user 			= User::fromToken();
		// `recuperacion_final` NO tiene `periodo_id` —guarda alumno, asignatura,
		// `year` y nota—, así que no hay fila de la que derivar un periodo. Lo que
		// se toca es del AÑO, y por eso se exigen abiertos **todos** los periodos
		// del año en vez de uno. Decisión de Joseth, 21 ago 2026.
		//
		// Antes se leía `num_periodo` del cuerpo, que es el hueco de la §27: la
		// nivelación se abría nombrando un periodo cualquiera. Medido en el front:
		// `DefinitivasPeriodosCtrl` manda `{rf_id, nota}` y nunca `num_periodo`,
		// así que esto no cambia ninguna pantalla — cierra la puerta, no la usa
		// nadie.
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::todosLosDelAnio($user));
		
		$now 		= Carbon::now('America/Bogota');
		
		if (Request::input('rf_id')) {
			$rf_id 		= Request::input('rf_id');
			
			$consulta 	= 'SELECT n.*, h.id as history_id FROM recuperacion_final n, 
								(select * from historiales where user_id=? and deleted_at is null order by id desc limit 1 ) h 
							WHERE n.id=? ';

			$nota 		= DB::select($consulta, [$user->user_id, $rf_id])[0];

			$bit_by 	= $user->user_id;
			$bit_hist 	= $nota->history_id;
			$bit_old 	= $nota->nota; 				// Guardo la nota antigua
			$bit_new 	= Request::input('nota'); 	// Guardo la nota nueva

			
			$consulta 	= 'UPDATE recuperacion_final SET nota=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ Request::input('nota'), $user->user_id, $now, $rf_id ]);
			
			$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_user_id, affected_person_type, affected_element_type, affected_element_id, affected_element_new_value_int, affected_element_old_value_int, created_at) 
						VALUES (?, ?, ?, "Al", "RF_UPDATE", ?, ?, ?, ?)';

			DB::insert($consulta, [$bit_by, $bit_hist, $nota->alumno_id, $rf_id, $bit_new, $bit_old, $now]);

			return 'Cambiada';
		}else{


			$consulta = 'INSERT INTO recuperacion_final(alumno_id, asignatura_id, year, nota, updated_by, created_at, updated_at) 
				VALUES(:alumno_id, :asignatura_id, :year, :nota, :updated_by, :created_at, :updated_at)';
	
			DB::insert($consulta, [':alumno_id' => Request::input('alumno_id'), ':asignatura_id' => Request::input('asignatura_id'), 
							':year' => $user->year, ':nota' => Request::input('nota'), ':updated_by' => $user->user_id, ':created_at' => $now, ':updated_at' => $now ]);
			
			$last_id = DB::getPdo()->lastInsertId();
			return (array)DB::select('SELECT * FROM recuperacion_final WHERE id=?', [$last_id])[0];
		}
		
		
	}


	
	public function getArreglarDuplicados()
	{
		$user 			= User::fromToken();
		// El mismo periodo que el método usa cuatro líneas más abajo para buscar
		// los duplicados que va a arreglar.
		User::pueden_modificar_definitivas($user, (int) Request::input('periodo_id', $user->periodo_id));
		
		$now 		= Carbon::now('America/Bogota');
		$res 		= [];
		$periodo_id = $user->periodo_id;

		if (Request::has('periodo_id')) {
			$periodo_id 		= Request::input('periodo_id');
		}
		
		
		$consulta = 'SELECT id FROM grupos g WHERE g.year_id=? and g.deleted_at is null';
		$grupos = DB::select($consulta, [$user->year_id]);
		//Log::info('$user->year_id '.$user->year_id . ' - '.$periodo_id);

		for ($i=0; $i < count($grupos); $i++) { 
			$grupo = $grupos[$i];


			$consulta = 'SELECT * FROM matriculas m WHERE m.grupo_id=? and m.deleted_at is null';
			$alumnos = DB::select($consulta, [$grupo->id]);
			$canti_alum = count($alumnos);

			for ($j=0; $j < $canti_alum; $j++) { 
				$alumno = $alumnos[$j];


				$consulta = 'SELECT * FROM asignaturas a WHERE a.grupo_id=? and a.deleted_at is null';
				$asignaturas = DB::select($consulta, [$grupo->id]);
				$canti_asig = count($asignaturas);


				for ($k=0; $k < $canti_asig; $k++) { 
					$asignatura = $asignaturas[$k];


					$consulta = 'SELECT n.id FROM notas_finales n 
						WHERE n.asignatura_id=? and alumno_id=? and  n.periodo_id=? and n.manual=1
						order by n.id ';
					$nota_ult = DB::select($consulta, [$asignatura->id, $alumno->alumno_id, $periodo_id]);
					//Log::info('$asignatura->id ' . $asignatura->id . ' - ' . $alumno->alumno_id. ' - ' .$periodo_id);

					if (count($nota_ult) > 1) {
						$nota_ult = $nota_ult[count($nota_ult)-1];
						//Log::info('mayor ' . $nota_ult->id);
						
						$consulta = 'DELETE FROM notas_finales
							WHERE asignatura_id=? and alumno_id=? and  periodo_id=? and id!=?';
						$nota_elim = DB::delete($consulta, [$asignatura->id, $alumno->alumno_id, $periodo_id, $nota_ult->id]);
						array_push($res, $nota_elim);
					}else{
						//Log::info('MENOR ');
					}


				}
			}

		}
		
		return $res;
	}


	public function putToggleRecuperada()
	{
		$user 			= User::fromToken();
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::deNotaFinal(Request::input('nf_id')));
		
		if ($user->tipo == 'Profesor' || ($user->is_superuser)) {
			// No pasa nada
		}else{
			return abort(403, 'No tienes privilegios.');
		}
		$now 		= Carbon::now('America/Bogota');
		$recu 		= Request::input('recuperada');
		
		if ($recu) {
			$consulta 	= 'UPDATE notas_finales SET recuperada=?, manual=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $recu, true, $user->user_id, $now, Request::input('nf_id') ]);
		}else{
			$consulta 	= 'UPDATE notas_finales SET recuperada=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $recu, $user->user_id, $now, Request::input('nf_id') ]);
		}
		
		return 'Cambiada';
	}


	public function putEliminarRecuperada()
	{
		$user 			= User::fromToken();
		// La otra de `recuperacion_final`, del año y no de un periodo. Ver la de
		// `putUpdateRecuperacion` más arriba.
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::todosLosDelAnio($user));
		
		if ($user->tipo == 'Profesor' || ($user->is_superuser)) {
			// No pasa nada
		}else{
			return abort(403, 'No tienes privilegios.');
		}
		
		$consulta 	= 'DELETE FROM recuperacion_final WHERE id=?';
		DB::update($consulta, [ Request::input('rf_id') ]);

		
		return 'Eliminada';
	}



	public function putToggleManual()
	{
		$user 			= User::fromToken();
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::deNotaFinal(Request::input('nf_id')));
		
		if ($user->tipo == 'Profesor' || ($user->is_superuser)) {
			// No pasa nada
		}else{
			return abort(403, 'No tienes privilegios.');
		}
		$now 		= Carbon::now('America/Bogota');
		$manual 	= Request::input('manual');
		if ($manual){
			$consulta 	= 'UPDATE notas_finales SET manual=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $manual, $user->user_id, $now, Request::input('nf_id') ]);
		}else{
			$consulta 	= 'UPDATE notas_finales SET manual=?, recuperada=?, updated_by=?, updated_at=? WHERE id=?';
			DB::update($consulta, [ $manual, false, $user->user_id, $now, Request::input('nf_id') ]);
		}
		
		return 'Cambiada';
	}


	public function deleteDestroy($id)
	{
		$user 	= User::fromToken();
		User::pueden_modificar_definitivas($user, PeriodoDeLaFila::deNotaFinal($id));
		$consulta 	= 'DELETE FROM notas_finales WHERE id=?';
		DB::delete($consulta, [$id]);

		return 'Eliminada';
	}


}

