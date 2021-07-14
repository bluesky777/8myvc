<?php namespace App\Http\Controllers;

use Request;
use DB;

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

								$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id);

								foreach ($asignatura->unidades as $unidad) {
									$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
								}

								Asignatura::calculoAlumnoNotas($asignatura, $alumno_id);
								$sumatoria_asignaturas_per += $asignatura->nota_asignatura; // Para sacar promedio del periodo
								
								array_push($asignaturas_res, $asignatura);
							}

						}
						try {
							//$periodo->promedio = $sumatoria_asignaturas_per / count($alumno->asignaturas);
							$periodo->promedio = $sumatoria_asignaturas_per / count($asignaturas);
						} catch (Exception $e) {
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





	public function putEliminarNotasPeriodo()
	{

		$user = User::fromToken();

		$periodo_id 	= Request::input('periodo_id');
		$alumno_id 		= Request::input('alumno_id');
		$grupo_id 		= Request::input('grupo_id');

		$consulta 	= 'DELETE n FROM notas n
						inner join subunidades s on s.id=n.subunidad_id
						inner join unidades u on u.id=s.unidad_id and u.periodo_id=:periodo_id
						inner join asignaturas a on a.id=u.asignatura_id and a.grupo_id=:grupo_id
						where n.alumno_id=:alumno_id';
		$eliminados = DB::delete($consulta, [':periodo_id' => $periodo_id, ':grupo_id' => $grupo_id, ':alumno_id' => $alumno_id]);

		return $eliminados;
	}


	public function putEliminarMatriculaDestroy()
	{

		$user = User::fromToken();

		$matricula_id 	= Request::input('matricula_id');

		$consulta 	= 'DELETE FROM matriculas WHERE id=:matricula_id';
		$eliminados = DB::delete($consulta, [':matricula_id' => $matricula_id]);

		return $eliminados;
	}




}


