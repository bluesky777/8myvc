<?php namespace App\Http\Controllers\Informes;


use App\Http\Controllers\Controller;


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

use \stdClass;



class PuestosAnualesController extends Controller {

	# Los usaba para los puestos anuales, pero no trae de la tabla notas_finales
	public function putDetailedNotasYear()
	{
		$user = User::fromToken();

		$grupo_id = Request::input('grupo_id');
		$periodo_a_calcular = Request::input('periodo_a_calcular', 10);


		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		foreach ($alumnos as $keyAlum => $alumno) {
			$alumno->notas_asig = $this->definitivas_year_alumno($alumno->alumno_id, $user->year_id, $periodo_a_calcular);

			$alumno->userData = Alumno::userData($alumno->alumno_id);

			$sumatoria_asignaturas_year = 0;
			$perdidos_year = 0;

			foreach ($alumno->notas_asig as $keyAsig => $asignatura) {

				$sumatoria_asignaturas_year += $asignatura->nota_asignatura_year;
				$perdidos_year += $asignatura->perdidos;

			}

			try {
				$cant = count($alumno->notas_asig);
				if ($cant == 0) {
					$alumno->promedio_year = 0;
				}else{
					$alumno->promedio_year = ($sumatoria_asignaturas_year / $cant);
					$alumno->perdidos_year = $perdidos_year;
				}
				
			} catch (Exception $e) {
				$alumno->promedio_year = 0;
			}

			array_push($alumnos_response, $alumno);
		}



		return ['grupo' => $grupo, 'year' => $year, 'alumnos' => $alumnos_response];


	}


	public function definitivas_year_alumno($alumno_id, $year_id, $numero_periodo=10)
	{
		$consulta = 'SELECT R2.asignatura_id, R2.materia, R2.alias, SUM(R2.perdidos) as perdidos,
				 ROUND(AVG(R2.nota_asignatura), 5) AS nota_asignatura_year, R2.alumno_id,
				 R2.profesor_id, R2.nombres_profesor, R2.apellidos_profesor, R2.sexo, R2.foto_id, R2.foto_nombre
			FROM
				(
				SELECT R1.materia, R1.alias, SUM(valor_nota) as nota_asignatura, R1.periodo_id, R1.asignatura_id,
					SUM(R1.perdido) as perdidos, R1.alumno_id,
					R1.profesor_id, R1.nombres_profesor, R1.apellidos_profesor, R1.sexo, R1.foto_id, R1.foto_nombre, R1.grupo_id
			    FROM
					(
			        SELECT m.materia, m.alias, n.id as nota_id, n.nota, n.subunidad_id, n.alumno_id, 
						AVG((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) as valor_nota, 
			            IF(n.nota < ?, 1, 0) as perdido, 
						s.definicion, s.porcentaje as porc_subuni, s.unidad_id, u.porcentaje as porc_uni, u.periodo_id, u.asignatura_id,
						pr.id as profesor_id, pr.nombres as nombres_profesor, pr.apellidos as apellidos_profesor, pr.sexo,
						pr.foto_id, IFNULL(i.nombre, IF(pr.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						a.grupo_id 
			        FROM notas n 
						inner join subunidades s on s.id=n.subunidad_id and s.deleted_at is null
						inner join unidades u on u.id=s.unidad_id and u.deleted_at is null
			            inner join periodos p on p.id=u.periodo_id and p.numero <= ? and p.year_id = ?
			            inner join asignaturas a on a.id=u.asignatura_id and a.deleted_at is null
			            inner join profesores pr on pr.id=a.profesor_id and pr.deleted_at is null
			            inner join materias m on m.id=a.materia_id and m.deleted_at is null
			            left join images i on i.id=pr.foto_id
			        WHERE alumno_id = ?
			        group by n.id
			        )R1
				GROUP BY R1.asignatura_id, R1.periodo_id, R1.alumno_id
			    )R2
                inner join grupos g on g.id=R2.grupo_id
                inner join matriculas m on m.grupo_id=g.id and R2.alumno_id=m.alumno_id and m.deleted_at is null
			GROUP BY R2.asignatura_id, R2.alumno_id';

		$notas = DB::select($consulta, [
						User::$nota_minima_aceptada, 
						$numero_periodo, 
						$year_id,
						$alumno_id
				]);
		
		return $notas;

	}


}