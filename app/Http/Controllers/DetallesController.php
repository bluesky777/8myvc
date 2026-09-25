<?php namespace App\Http\Controllers;

use App\Services\Auditoria;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Matricula;
use App\Models\Grupo;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Asignatura;


class DetallesController extends Controller {




	public function putAlumno()
	{
		$user = User::fromToken();
		$alumno_id 		= Request::input('alumno_id');
		$year_id 		= Request::input('year_id');


		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.created_at as fecha_creacion_matr, m.deleted_at as deleted_at_matricula,  
							gr.nombre as nombre_grupo, gr.abrev as abrev_grupo, gr.titular_id, gr.orden as orden_grupo, gr.deleted_at as deleted_at_grupo, 
							gr.year_id, y.year 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id
						inner join grupos gr on gr.id=m.grupo_id
						inner join years y on y.id=gr.year_id 
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null';

		$matriculas = DB::select($consulta, [':alumno_id' => $alumno_id]);

		return $matriculas;
	}



	public function putGruposPeriodos()
	{
		$user = User::fromToken();
		$year_id 		= Request::input('year_id');
		$matricula_id 	= Request::input('matricula_id');
		$alumno_id 		= Request::input('alumno_id');
		//$grupo_id 		= Request::input('grupo_id');

		$grupos_res = [];


		$consulta 	= 'SELECT * FROM grupos g WHERE g.year_id=:year_id';
		$grupos 	= DB::select($consulta, [':year_id' => $year_id]);
		$cant 		= count($grupos);

		for ($i=0; $i < $cant; $i++) { 

			// Verificamos si tiene alguna nota en ese grupo
			$consulta 	= 'SELECT * 
							FROM notas n
							inner join subunidades s on s.id=n.subunidad_id
							inner join unidades u on u.id=s.unidad_id
							inner join asignaturas a on a.id=u.asignatura_id and a.grupo_id=:grupo_id
							where n.alumno_id=:alumno_id';

			$notasS 	= DB::select($consulta, [':alumno_id' => $alumno_id, 'grupo_id' => $grupos[$i]->id]);
			$cantNotas 	= count($notasS);

			if ($cantNotas > 0) {


				$consulta = 'SELECT * FROM periodos p WHERE p.year_id=:year_id';
				$periodos = DB::select($consulta, [':year_id' => $year_id]);
				$periodos_res = [];
				
				foreach ($periodos as $keyPer => $periodo) {
					
					// Verificamos si tiene alguna nota en este periodo
					$consulta 	= 'SELECT * 
									FROM notas n
									inner join subunidades s on s.id=n.subunidad_id
									inner join unidades u on u.id=s.unidad_id and u.periodo_id=:periodo_id
									inner join asignaturas a on a.id=u.asignatura_id and a.grupo_id=:grupo_id
									where n.alumno_id=:alumno_id';

					$notasP 	= DB::select($consulta, [':periodo_id' => $periodo->id, ':alumno_id' => $alumno_id, 'grupo_id' => $grupos[$i]->id]);
					$cantNotasPer 	= count($notasP);

					if ($cantNotasPer > 0) {

						$asignaturas = Grupo::detailed_materias($grupos[$i]->id);
						$sumatoria_asignaturas_per = 0;
						$asignaturas_res = [];

						foreach ($asignaturas as $keyAsig => $asignatura) {
							
							// Verificamos si tiene alguna nota en este periodo
							$consulta 	= 'SELECT * 
											FROM notas n
											inner join subunidades s on s.id=n.subunidad_id
											inner join unidades u on u.id=s.unidad_id and u.periodo_id=:periodo_id
											inner join asignaturas a on a.id=u.asignatura_id and a.grupo_id=:grupo_id and a.id=:asignatura_id
											where n.alumno_id=:alumno_id';

							$notasA 	= DB::select($consulta, [':periodo_id' => $periodo->id, ':alumno_id' => $alumno_id, 'grupo_id' => $grupos[$i]->id, 'asignatura_id' => $asignatura->asignatura_id]);
							$cantNotasAsi 	= count($notasA);

							if ($cantNotasAsi > 0) {

								$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id, $alumno_id);

								foreach ($asignatura->unidades as $unidad) {
									$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
								}

								// Gana `nota_parcial` y `cobertura` **sin una línea de más**: aquí
								// `$asignatura` se devuelve entera, así que los dos números que pone
								// `calculoAlumnoNotas` viajan solos (fase 1.bis del 43). Es el detalle del
								// alumno periodo a periodo, o sea el mismo falso rojo de la planilla.
								// `null` en cualquiera de las dos **no es un 0**, y `cobertura` es un
								// factor de 0 a 1.
								Asignatura::calculoAlumnoNotas($asignatura, $alumno_id);
								$sumatoria_asignaturas_per += $asignatura->nota_asignatura; // Para sacar promedio del periodo
								
								array_push($asignaturas_res, $asignatura);
							}

						}
						try {
							//$periodo->promedio = $sumatoria_asignaturas_per / count($alumno->asignaturas);
							$periodo->promedio = $sumatoria_asignaturas_per / count($asignaturas);
						} catch (\Throwable $e) {
							$periodo->promedio = 0;
						}

						$periodo->asignaturas = $asignaturas_res;
						array_push($periodos_res, $periodo);
					}

				}
				$grupos[$i]->periodos = $periodos_res;

				$consulta_matrc_year_grupo = "SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, 
							m.grupo_id, 
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.created_at as fecha_creacion_matr, m.deleted_at as deleted_at_matricula,  
							gr.nombre as nombre_grupo, gr.abrev as abrev_grupo, gr.titular_id, gr.orden as orden_grupo, gr.deleted_at as deleted_at_grupo, 
							gr.year_id, y.year 
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and a.id=:alumno_id
						inner join grupos gr on gr.id=m.grupo_id and gr.id=:grupo_id 
						inner join years y on y.id=gr.year_id and y.id=:year_id";

				$matriculas_year_grupo 				= DB::select($consulta_matrc_year_grupo, [':alumno_id' => $alumno_id, 'grupo_id' => $grupos[$i]->id, 'year_id' => $year_id]);
				$grupos[$i]->matriculas_year_grupo 	= $matriculas_year_grupo;
				array_push($grupos_res, $grupos[$i]);

			}


		}

		return $grupos_res;
	}





	/*
	 * Borrar todas las notas de un periodo obedece al interruptor del periodo, y
	 * hasta el 22 ago 2026 no obedecía a nada.
	 *
	 * Es un `DELETE` **físico**: no marca `deleted_at`, no hay papelera y no hay
	 * de dónde restaurar. El botón que la llama se llama, en el propio front,
	 * «Eliminar todas las notas de este periodo (¡peligroso!)».
	 *
	 * La §27 dejó 25 de 26 rutas comprobando el permiso del sitio al que escriben
	 * y ésta no estaba entre las 26 — **su inventario se hizo de los sitios que ya
	 * llamaban a `pueden_editar_notas`, no de los que escriben en las notas**, y
	 * un sitio que nunca preguntó no aparece en una lista construida así. La §08
	 * sí la tenía apuntada desde la revisión IDOR, en «escrituras sobre otro
	 * alumno», y nunca se cerró.
	 *
	 * El periodo que se le pasa es el del **cuerpo** y eso es correcto aquí, que
	 * es justo lo contrario de lo que avisa la §27: allí el problema era que el
	 * cliente elegía con `num_periodo` el permiso que se le comprobaba mientras
	 * escribía en otro sitio. Aquí `periodo_id` es la misma ligadura que acota el
	 * `DELETE` —`u.periodo_id=:periodo_id`—, así que pedir permiso para él es
	 * pedirlo para exactamente lo que se va a borrar.
	 */
	public function putEliminarNotasPeriodo()
	{

		$user = User::fromToken();

		$periodo_id 	= Request::input('periodo_id');
		$alumno_id 		= Request::input('alumno_id');
		$grupo_id 		= Request::input('grupo_id');

		// Borra las notas del alumno en TODAS las asignaturas del grupo, así que basta
		// que su docente haya cerrado una para que no pase (fase 2 del cierre).
		$asignaturasDelGrupo = array_map(fn ($a) => (int) $a->id, DB::select(
			'SELECT id FROM asignaturas WHERE grupo_id = ? AND deleted_at IS NULL', [(int) $grupo_id]
		));

		User::pueden_editar_notas($user, $periodo_id ? (int) $periodo_id : null,
			$asignaturasDelGrupo === [] ? null : $asignaturasDelGrupo);

		$consulta 	= 'DELETE n FROM notas n
						inner join subunidades s on s.id=n.subunidad_id
						inner join unidades u on u.id=s.unidad_id and u.periodo_id=:periodo_id
						inner join asignaturas a on a.id=u.asignatura_id and a.grupo_id=:grupo_id
						where n.alumno_id=:alumno_id';
		$eliminados = DB::delete($consulta, [':periodo_id' => $periodo_id, ':grupo_id' => $grupo_id, ':alumno_id' => $alumno_id]);

		// **Borrado físico: el sello no se entera**, así que la definitiva que se hizo
		// con estas notas se quedaba puesta y dada por buena. Sólo su fila: a los
		// compañeros no les cambia nada.
		if ($eliminados > 0 && $periodo_id) {
			foreach ($asignaturasDelGrupo as $asignaturaId) {
				DefinitivasDeAsignatura::recalcular($asignaturaId, (int) $periodo_id, $user->user_id, (int) $alumno_id);
			}
		}

		return $eliminados;
	}


	public function putEliminarMatriculaDestroy()
	{

		$user = User::fromToken();

		$matricula_id 	= Request::input('matricula_id');

		/*
		 * **La fila se lee ANTES de borrarla, y ésa es la única ocasión.** Este borrado
		 * es FÍSICO —no hay papelera, no hay `deleted_at`—, así que en cuanto el
		 * `DELETE` corre no queda de dónde sacar de quién era la matrícula ni en qué
		 * grupo estaba. Es el mismo caso que el `deleteDestroy` de las notas: sin esto,
		 * «¿quién borró la matrícula de este alumno?» no tiene respuesta en los
		 * dieciséis colegios.
		 */
		$fila = DB::selectOne('SELECT id, alumno_id, grupo_id, estado, fecha_matricula
			   FROM matriculas WHERE id = ?', [$matricula_id]);

		$consulta 	= 'DELETE FROM matriculas WHERE id=:matricula_id';
		$eliminados = DB::delete($consulta, [':matricula_id' => $matricula_id]);

		// Sólo si el `DELETE` se llevó algo: un `matricula_id` que no existe contesta 0
		// y no ha pasado nada que anotar. Anotarlo igual llenaría el historial de
		// borrados que nunca ocurrieron.
		if ($eliminados > 0 && $fila !== null) {
			Auditoria::registrar()
				->borrar('matricula', (int) $fila->id)
				->deAlumno((int) $fila->alumno_id)
				->en(grupo: (int) $fila->grupo_id)
				->de([
					'estado' => $fila->estado,
					'grupo_id' => $fila->grupo_id,
					'fecha_matricula' => $fila->fecha_matricula,
				])
				->resumen('Borró la matrícula — borrado físico, no hay papelera')
				->guardar();
		}

		return $eliminados;
	}




}


