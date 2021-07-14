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



class PuestosController extends Controller {
    
    public $consulta_notas_finales_alumno4 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, (sum(nf.nota)/4) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';
    

    public $consulta_notas_finales_alumno3 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, (sum(nf.nota)/3) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and (p.numero=1 or p.numero=2 or p.numero=3) and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and (p.numero=1 or p.numero=2 or p.numero=3) and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';
        
        
    public $consulta_notas_finales_alumno2 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, (sum(nf.nota)/2) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and (p.numero=1 or p.numero=2) and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and (p.numero=1 or p.numero=2) and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';


    public $consulta_notas_finales_alumno1 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, avg(nf.nota) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and p.numero=1 and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and p.numero=1 and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';




    public $consulta_notas_finales_periodo = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_asignatura, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura, r.manual
            FROM asignaturas a
            inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
            inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
            left join (
                select nf.asignatura_id, avg(nf.nota) as nota_asignatura, nf.manual from notas_finales nf
                inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                inner join periodos p on p.id=nf.periodo_id and p.numero=:num_periodo and p.deleted_at is null and p.year_id=:year_id
                group by a.id
            )r on r.asignatura_id=a.id
            left join (
                SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                FROM(
                    SELECT n.alumno_id, n.nota, u.asignatura_id
                    FROM unidades u 
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                    inner join periodos p on p.id=u.periodo_id and p.numero=:num_periodo2 and p.deleted_at is null and p.year_id=:year_id2
                )df1
                group by df1.asignatura_id
            )p ON p.asignatura_id=r.asignatura_id 
            order by ar.orden, m.orden, a.orden';







	// PUESTOS ANUALES
	public function putDetailedNotasYear()
	{
		$user = User::fromToken();

		$grupo_id = Request::input('grupo_id');
		$periodo_a_calcular = Request::input('periodo_a_calcular', 4);


		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		foreach ($alumnos as $keyAlum => $alumno) {
			$alumno->notas_asig = $this->definitivas_year_alumno($alumno->alumno_id, $grupo_id, $user, $periodo_a_calcular);

			$sumatoria_asignaturas_year = 0;
			$perdidos_year = 0;

			foreach ($alumno->notas_asig as $keyAsig => $asignatura) {
                
                $sumatoria_asignaturas_year += $asignatura->nota_final_year;
                $asignatura->nota_final_year = round($asignatura->nota_final_year);
                $perdidos_year += $asignatura->cant_perdidas;

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


	public function definitivas_year_alumno($alumno_id, $grupo_id, $user, $numero_periodo=4)
	{
        if ($numero_periodo == 1) {
            
            $consulta   = $this->consulta_notas_finales_alumno1;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
            
        }elseif ($numero_periodo == 2) {
            
            $consulta   = $this->consulta_notas_finales_alumno2;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
                            
        }elseif ($numero_periodo == 3) {
            
            $consulta   = $this->consulta_notas_finales_alumno3;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
                            
        }elseif ($numero_periodo == 4) {
            
            $consulta   = $this->consulta_notas_finales_alumno4;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
                            
        }
		
		
		return $notas;

    }
    
    


    
	// PUESTOS POR PERIODO
	public function putDetailedNotasPeriodo($grupo_id)
	{
		$user = User::fromToken();

		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		foreach ($alumnos as $keyAlum => $alumno) {
            
            $consulta   = $this->consulta_notas_finales_periodo;
            
			$alumno->asignaturas = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno->alumno_id, ':num_periodo' => $user->numero_periodo, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno->alumno_id, ':num_periodo2' => $user->numero_periodo, ':year_id2' => $user->year_id ]);

			$sumatoria_asignaturas = 0;
			$perdidos_year = 0;

			foreach ($alumno->asignaturas as $keyAsig => $asignatura) {
                
                $sumatoria_asignaturas += $asignatura->nota_asignatura;
                $asignatura->nota_asignatura = round($asignatura->nota_asignatura);
                $perdidos_year += $asignatura->cant_perdidas;
			}

			try {
				$cant = count($alumno->asignaturas);
				if ($cant == 0) {
					$alumno->promedio = 0;
				}else{
					$alumno->promedio = ($sumatoria_asignaturas / $cant);
					$alumno->perdidos_year = $perdidos_year;
				}
				
			} catch (Exception $e) {
				$alumno->promedio = 0;
			}

			array_push($alumnos_response, $alumno);
		}



		return ['grupo' => $grupo, 'year' => $year, 'alumnos' => $alumnos_response];


	}


    
    


}