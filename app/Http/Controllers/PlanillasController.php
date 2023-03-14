<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Year;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Models\Profesor;
use App\Models\Alumno;
use Carbon\Carbon;


class PlanillasController extends Controller {


	private $periodos = [];


	public function getShowGrupo($grupo_id)
	{
		$user = User::fromToken();

		$year 			= Year::datos_basicos($user->year_id);
		$asignaturas 	= Grupo::detailed_materias($grupo_id);
		$periodos 		= Periodo::delYear($user->year_id);
		

		$year->periodos = $periodos;
		
		$grupo			= Grupo::datos($grupo_id);

		foreach ($asignaturas as $keyMat => $asignatura) {
			
			$alumnos						= Grupo::alumnos($grupo_id);
			$asignatura->nombre_grupo 		= $grupo->nombre_grupo;
			$asignatura->definitiva_per1	= 0;
			$asignatura->definitiva_per2	= 0;
			$asignatura->definitiva_per3	= 0;
			$asignatura->definitiva_per4	= 0;
			//$asignatura->periodosProm = Periodo::delYear($user->year_id);

			// A cada alumno le daremos los periodos y la definitiva de cada periodo
			foreach ($alumnos as $keyAl => $alumno) {
				
				$consulta = 'SELECT  nf1.nota as nota_final_per1, nf1.id as nf_id_1,
								nf2.nota as nota_final_per2, nf2.id as nf_id_2,
								nf3.nota as nota_final_per3, nf3.id as nf_id_3,
								nf4.nota as nota_final_per4, nf4.id as nf_id_4
							FROM notas_finales nf1 
							left join notas_finales nf2 on nf2.alumno_id=:alu1 and nf2.asignatura_id=:asi1 and nf2.periodo=2
							left join notas_finales nf3 on nf3.alumno_id=:alu2 and nf3.asignatura_id=:asi2 and nf3.periodo=3
							left join notas_finales nf4 on nf4.alumno_id=:alu3 and nf4.asignatura_id=:asi3 and nf4.periodo=4
							where nf1.alumno_id=:alu4 and nf1.asignatura_id=:asi4 and nf1.periodo=1';
				
				$definitivas = DB::select($consulta, [ ':alu1' => $alumno->alumno_id, ':asi1' => $asignatura->asignatura_id, ':alu2' => $alumno->alumno_id, ':asi2' => $asignatura->asignatura_id, ':alu3' => $alumno->alumno_id, ':asi3' => $asignatura->asignatura_id, ':alu4' => $alumno->alumno_id, ':asi4' => $asignatura->asignatura_id ]);
				if (count($definitivas) > 0) {
					$alumno->definitivas 			= $definitivas[0];
					$asignatura->definitiva_per1	+= $alumno->definitivas->nota_final_per1;
					$asignatura->definitiva_per2	+= $alumno->definitivas->nota_final_per2;
					$asignatura->definitiva_per3	+= $alumno->definitivas->nota_final_per3;
					$asignatura->definitiva_per4	+= $alumno->definitivas->nota_final_per4;
				}else{
					$alumno->definitivas = [];
				}
				

			}

			$asignatura->alumnos 	= $alumnos;
			$cant 					= count($alumnos);
			
			if($cant>0){
				$asignatura->definitiva_per1 	= round($asignatura->definitiva_per1 / $cant);
				$asignatura->definitiva_per2 	= round($asignatura->definitiva_per2 / $cant);
				$asignatura->definitiva_per3 	= round($asignatura->definitiva_per3 / $cant);
				$asignatura->definitiva_per4 	= round($asignatura->definitiva_per4 / $cant);
				
				if ($asignatura->definitiva_per1==0){ $asignatura->definitiva_per1 = '<span class="invisible">.</span>'; }
				if ($asignatura->definitiva_per2==0){ $asignatura->definitiva_per2 = '<span class="invisible">.</span>'; }
				if ($asignatura->definitiva_per3==0){ $asignatura->definitiva_per3 = '<span class="invisible">.</span>'; }
				if ($asignatura->definitiva_per4==0){ $asignatura->definitiva_per4 = '<span class="invisible">.</span>'; }
			}
			
		}

		return array($year, $asignaturas);
	}



	public function getShowProfesor($profesor_id)
	{
		$user = User::fromToken();

		$year 			= Year::datos_basicos($user->year_id);
		$asignaturas 	= Profesor::asignaturas($user->year_id, $profesor_id);
		$periodos 		= Periodo::where('year_id', $user->year_id)->get();

		$year->periodos 	= $periodos;
		$profesor = Profesor::detallado($profesor_id);
		
		foreach ($asignaturas as $keyAsig => $asignatura) {
			
			$alumnos	= Grupo::alumnos($asignatura->grupo_id);

			$asignatura->nombres_profesor 		= $profesor->nombres_profesor;
			$asignatura->apellidos_profesor 	= $profesor->apellidos_profesor;
			$asignatura->foto_nombre 			= $profesor->foto_nombre;
			$asignatura->foto_id 				= $profesor->foto_id;
			$asignatura->sexo 					= $profesor->sexo;


			$asignatura->periodosProm = Periodo::where('year_id', $user->year_id)->get();

			// A cada alumno le daremos los periodos y la definitiva de cada periodo
			foreach ($alumnos as $keyAl => $alumno) {

				$periodosTemp = Periodo::where('year_id', $user->year_id)->get();

				foreach ($periodosTemp as $keyPer => $periodo) {

					// Unidades y subunidades de la asignatura en el periodo
					$asignaturaTemp = Asignatura::find($asignatura->asignatura_id);
					$asignaturaTemp->unidades = Unidad::deAsignatura($asignaturaTemp->id, $periodo->id);

					foreach ($asignaturaTemp->unidades as $unidad) {
						$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
					}

					// Traemos las notas de esta asignatura segun las unidades y subunidades calculadas arriba
					Asignatura::calculoAlumnoNotas($asignaturaTemp, $alumno->alumno_id);
					$periodo->nota_asignatura = $asignaturaTemp->nota_asignatura;
					unset($asignaturaTemp);
				}

				$alumno->periodos = $periodosTemp;
				unset($periodosTemp);





				foreach ($asignatura->periodosProm as $keyPer => $periodo) {
					if (!$periodo->sumatoria) {
						$periodo->sumatoria = 0;
					}

					foreach ($alumno->periodos as $keyPerAl => $periodo_alum) {

						if ($periodo_alum->id == $periodo->id) {
							$periodo->sumatoria += $periodo_alum->nota_asignatura;
						}
					}
				}


			}

			$asignatura->alumnos = $alumnos;

		}

		return array($year, $asignaturas);
	}


	public function getVerAusencias()
	{
		$user 		= User::fromToken();
		$this->periodos 	= Periodo::where('year_id', $user->year_id)->get();


		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
			p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
			g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grupos = DB::select($consulta, [':year_id' => $user->year_id]);

		$cant = count($grupos);
		for ($i=0; $i < $cant; $i++) { 

			$alumnos = Grupo::alumnos($grupos[$i]->id);

			$grupos[$i]->periodos 	= $this->periodos; // No debería repetir tanto pero bueno
			$grupos[$i]->alumnos 	= $alumnos;



			$cant_alum		= count($alumnos);
			for ($k=0; $k < $cant_alum; $k++) { 
				$alumnos[$k]->periodos = Periodo::where('year_id', $user->year_id)->get();

				$total_aus_alum = 0;

				$cant_pers = count($alumnos[$k]->periodos);

				for ($j=0; $j < $cant_pers; $j++) { 
					

					$consulta = 'SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.tipo, a.fecha_hora, a.uploaded, a.created_by,
							u.username, u2.username as username_updater, a.updated_by, a.created_at
						FROM ausencias a
						inner join periodos p on p.id=a.periodo_id and p.id=:periodo_id
						inner join users u on u.id=a.created_by
						left join users u2 on u2.id=a.updated_by
						WHERE a.entrada=true and a.alumno_id=:alumno_id and a.deleted_at is null';

					$ausencias = DB::select($consulta, [':alumno_id' => $grupos[$i]->alumnos[$k]->alumno_id, ':periodo_id' => $grupos[$i]->alumnos[$k]->periodos[$j]->id ]);
					$grupos[$i]->alumnos[$k]->periodos[$j]->ausencias = $ausencias;
					$total_aus_alum = $total_aus_alum + count($ausencias);
				}

				$alumnos[$k]->total_aus_alum = $total_aus_alum;
			}
		}



		return $grupos;

	}




	public function getVerSimat()
	{
		$user 		= User::fromToken();



		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
			p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
			g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grupos = DB::select($consulta, [':year_id'=>$user->year_id] );


		for ($i=0; $i < count($grupos); $i++) { 
			
			
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
					a.fecha_nac, a.ciudad_nac, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, a.documento, a.ciudad_doc, a.tipo_sangre, a.eps, a.telefono, a.celular, 
					a.direccion, a.barrio, a.estrato, a.ciudad_resid, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
					a.pazysalvo, a.deuda, m.grupo_id, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.username, u.is_superuser, u.is_active,
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
					m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula 
				FROM alumnos a 
				inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR")
				left join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
				where a.deleted_at is null and m.deleted_at is null
				order by a.apellidos, a.nombres';

			$grupos[$i]->alumnos = DB::select($consulta, [':grupo_id' => $grupos[$i]->id]);


		}


		return $grupos;

	}



	public function getListasPersonalizadas()
	{
		$user 		= User::fromToken();



		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
			p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
			g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grupos = DB::select($consulta, [':year_id'=>$user->year_id] );


		for ($i=0; $i < count($grupos); $i++) { 
			

			
			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
					a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre, c1.ciudad as ciudad_nac_nombre, a.tipo_doc, t1.tipo as tipo_doc_name, a.documento, a.ciudad_doc, 
					a.tipo_sangre, a.eps, 
					CONCAT(COALESCE(a.telefono, ""), " / ", COALESCE(a.celular, "")) as telefonos, 
					a.direccion, a.barrio, a.estrato, a.ciudad_resid, a.religion, a.email, a.facebook, a.created_by, a.updated_by,
					a.pazysalvo, a.presencial, a.deuda, m.grupo_id, a.is_urbana, IF(a.is_urbana, "Urbano", "Rural") as es_urbana,
					t1.tipo as tipo_doc, t1.abrev as tipo_doc_abrev,
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					u.username, u.is_superuser, u.is_active,
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
					m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.nuevo, IF(m.nuevo, "SI", "NO") as es_nuevo, m.repitente,
					a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3 
				FROM alumnos a 
				inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM")
				left join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
				where a.deleted_at is null and m.deleted_at is null
				order by a.apellidos, a.nombres';
			
			$grupos[$i]->alumnos = DB::select($consulta, [':grupo_id' => $grupos[$i]->id]);

			
			// Recorro para calcular edad
			$cantA = count($grupos[$i]->alumnos);

			for ($j=0; $j < $cantA; $j++) { 
				// Edad
				if ($grupos[$i]->alumnos[$j]->fecha_nac) {
					$anio 	= date('Y', strtotime( $grupos[$i]->alumnos[$j]->fecha_nac) );
					$mes 	= date('m', strtotime( $grupos[$i]->alumnos[$j]->fecha_nac) );
					$dia 	= date('d', strtotime( $grupos[$i]->alumnos[$j]->fecha_nac) );
					$grupos[$i]->alumnos[$j]->edad = Carbon::createFromDate($anio, $mes, $dia)->age;
				}else{
					$grupos[$i]->alumnos[$j]->edad = '';
				}
			}

		}


		return $grupos;

	}



}