<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use App\Models\Grupo;
use App\Models\Nota;
use App\Models\Periodo;
use App\Models\Alumno;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Asignatura;
use App\Models\Debugging;

use \stdClass;
use DB;
use Carbon\Carbon;


class Nota extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;

	/*
	// Solo si la subunidad tiene cero notas
	public static function crearNotas($grupo_id, $subunidad, $user_id)
	{
		$alumnos 	= Grupo::alumnos($grupo_id);
		$now 		= Carbon::now('America/Bogota');

		foreach ($alumnos as $alumno) {
			DB::insert('INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$subunidad->id, $alumno->alumno_id, $subunidad->nota_default, $user_id, $now, $now]);
		}

		return;
	}
	*/

	// Verificar cada alumno si tiene nota en la subunidad
	public static function verificarCrearNotas($grupo_id, $subunidad, $user_id)
	{
		$alumnos 	= Grupo::alumnos($grupo_id);
		$now 		= Carbon::now('America/Bogota');

		foreach ($alumnos as $alumno) {
			/*
			$notVerif = DB::select('SELECT * from notas WHERE subunidad_id=? and alumno_id=? and deleted_at is null', [$subunidad->id, $alumno->alumno_id]);

			if (count($notVerif) == 0) {
				DB::insert('INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$subunidad->id, $alumno->alumno_id, $subunidad->nota_default, $user_id, $now, $now]);
			}
			*/
			$consulta = "INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at) 
						SELECT * FROM 
						(SELECT '.$subunidad->id.' as subunidad_id, '.$alumno->alumno_id.' as alumno_id, '.$subunidad->nota_default.' as nota, '.$user_id.' as created_by, '.$now.' as created_at, '.$now.' as updated_at) AS tmp
							WHERE NOT EXISTS (
								SELECT * from notas WHERE subunidad_id=? and alumno_id=? and deleted_at is null
							) LIMIT 1";
							
			DB::insert($consulta, [ $subunidad->id, $alumno->alumno_id ]);
		}

		return;
	}
	
	/*
	// Verificar nota de un alumno si tiene o crearla
	public static function verificarCrearNota($alumno_id, $subunidad)
	{
		$notVerif = Nota::where('subunidad_id', $subunidad->id)
						->where('alumno_id', $alumno_id)->first();

		if ($notVerif) {
			$nota = $notVerif;
		}else{

			$nota = new Nota;
			$nota->alumno_id 		= $alumno->id;
			$nota->subunidad_id 	= $subunidad->id;
			$nota->nota 			= $subunidad->nota_default;

			$nota->save();

			$notVerif = $nota;
		}

		return $notVerif;
	}
	*/


	public static function puestoAlumno($promedio_alumno, $alumnos)
	{
		$puesto = 1;

		foreach ($alumnos as $alumno) {
			if ($alumno->promedio > $promedio_alumno) {
				$puesto += 1;
			}
		}

		return $puesto;
	}

	
	// Todos los periodos
	public static function alumnoPeriodosDetailed($alumno_id, $year_id, $profesor_id='')
	{
		$alumno 	= Alumno::alumnoData($alumno_id, $year_id);
		
		if (!$alumno) {
			return false;
		}
		
		$periodos 	= DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [ $year_id ]); 


		foreach ($periodos as $keyPer => $periodo) {
			Nota::alumnoPeriodoDetalle($periodo, $alumno->grupo_id, $alumno_id, $year_id, $profesor_id);

		}

		$alumno->periodos = $periodos;

		return $alumno;

	}
	
	
	// Sólo UN periodo
	public static function alumnoPeriodoDetalle(&$periodo, $grupo_id, $alumno_id, $year_id, $profesor_id=''){
		
		$asignaturas = Grupo::detailed_materias_notafinal($alumno_id, $grupo_id, $periodo->id, $year_id);
		$sumatoria_asignaturas_per = 0;
		
		//Debugging::pin( count($asignaturas));
		
		foreach ($asignaturas as $keyAsig => $asignatura) {
			
			if($profesor_id != $asignatura->profesor_id && $profesor_id != ''){
				unset($asignaturas[$keyAsig]);
			}else{

				$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id);

				foreach ($asignatura->unidades as $unidad) {
					$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
					
					for ($j=0; $j < count($unidad->subunidades); $j++) { 
						$nota = DB::select('SELECT * FROM notas n WHERE n.deleted_at is null and n.subunidad_id=? and n.alumno_id=?', [$unidad->subunidades[$j]->subunidad_id, $alumno_id]);
						if (count($nota)>0) {
							$unidad->subunidades[$j]->nota = $nota[0];
						}
						
					}
				}

				$sumatoria_asignaturas_per += $asignatura->nota_asignatura; // Para sacar promedio del periodo
				
				$asignatura->ausencias	= Ausencia::deAlumno($asignatura->asignatura_id, $alumno_id, $periodo->id);
				$asignatura->frases		= FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno_id, $periodo->id);
			
			
				$cantAus = 0;
				$cantTar = 0;
				foreach ($asignatura->ausencias as $ausencia) {
					if ($ausencia->tipo == "tardanza") {
						$cantTar += (int)$ausencia->cantidad_tardanza;
					}elseif ($ausencia->tipo == "ausencia") {
						$cantAus += (int)$ausencia->cantidad_ausencia;
					}
					
				}

				$asignatura->total_ausencias = $cantAus;
				$asignatura->total_tardanzas = $cantTar;
				
			}
			
			

		}
		
		$cant_asi = count($asignaturas);
		
		if($cant_asi > 0){
			$periodo->promedio = $sumatoria_asignaturas_per / count($asignaturas);
		} else {
			$periodo->promedio = 0;
		}
		
		// Comportamiento
		$consulta = 'SELECT n.*, p.nombres, p.apellidos, p.sexo, 
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					FROM nota_comportamiento n
					inner join matriculas m on m.alumno_id=n.alumno_id and m.deleted_at is null
					inner join grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=:year_id
					inner join profesores p on p.id=g.titular_id and p.deleted_at is null 
					left join images i on i.id=p.foto_id and i.deleted_at is null
					where n.alumno_id=:alumno_id and n.periodo_id=:periodo_id and n.deleted_at is null';
					
		$nota_comportamiento = DB::select($consulta, [
			':year_id'		=>$year_id, 
			':alumno_id'	=>$alumno_id, 
			':periodo_id'	=>$periodo->id
		]);
		
		if(count($nota_comportamiento) > 0){
			$nota_comportamiento = $nota_comportamiento[0];
		}else{
			$nota_comportamiento = [];
		}

		$periodo->asignaturas 			= $asignaturas;
		$periodo->nota_comportamiento 	= $nota_comportamiento;
	}



	public static function alumnoAsignaturasPeriodosDetailed($alumno_id, $year_id, $periodos_a_calcular='de_usuario', $periodo_usuario=0)
	{

		$alumno 		= Alumno::alumnoData($alumno_id, $year_id);
		$asignaturas 	= Grupo::detailed_materias($alumno->grupo_id);

		$sumatoria_asignaturas_year = 0;
		$sub_perdidas_year = 0;

		foreach ($asignaturas as $keyAsig => $asignatura) {

			$periodos = Periodo::hastaPeriodo($year_id, $periodos_a_calcular, $periodo_usuario);
			
			
			$sum_asignatura_year = 0;

			$subunidadesPerdidas = 0;

			foreach ($periodos as $keyPer => $periodo) {

				$asigna = new stdClass();
				$asigna->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id);

				foreach ($asigna->unidades as $unidad) {
					$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
				}

				Asignatura::calculoAlumnoNotas($asigna, $alumno->alumno_id);

				$sum_asignatura_year += $asigna->nota_asignatura;

				$subunidadesPerdidas += Asignatura::notasPerdidasAsignatura($asigna);
				
			}

			try {
				$asignatura->nota_asignatura_year = ($sum_asignatura_year / count($periodos));
				$asignatura->subunidadesPerdidas = $subunidadesPerdidas;
			} catch (Exception $e) {
				$asignatura->nota_asignatura_year = 0;
			}

			$asignatura->periodos = $periodos;

			$sumatoria_asignaturas_year += $asignatura->nota_asignatura_year;
			$sub_perdidas_year += $subunidadesPerdidas;


		}

		try {
			$alumno->promedio_year = ($sumatoria_asignaturas_year / count($asignaturas));
			$alumno->sub_perdidas_year = $sub_perdidas_year;
		} catch (Exception $e) {
			$alumno->promedio_year = 0;
		}

		$alumno->asignaturas = $asignaturas;

		return $alumno;

	}


}



