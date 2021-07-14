<?php namespace App\Http\Controllers\Disciplina;

use Request;
use DB;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Disciplina\DisciplinaController;
use App\User;
use App\Models\NotaComportamiento;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;
use App\Models\Year;
use App\Models\Matricula;
use \Log;

use Carbon\Carbon;


class ComportamientoController extends Controller {

	public function getIndex()
	{
		$user = User::fromToken();
		return NotaComportamiento::all();
	}



	public function putSituacionesPorGrupos()
	{
		$user 		= User::fromToken();


		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
				g.created_at, g.updated_at, gra.nombre as nombre_grado 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
			left join profesores p on p.id=g.titular_id and p.deleted_at is null
			where g.deleted_at is null
			order by g.orden';

		$res['grupos'] 	= DB::select($consulta, [':year_id'=>$user->year_id] );
		$discCtrl 		= new DisciplinaController;
			
		for ($i=0; $i < count($res['grupos']); $i++) { 

			$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
					a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
					m.grupo_id, u.username, 
					u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
					a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
				FROM alumnos a
				INNER JOIN matriculas m ON a.id=m.alumno_id and a.deleted_at is null and m.deleted_at is null and (m.estado="ASIS" or m.estado="PREM" or m.estado="MATR")
				left join users u on a.user_id=u.id and u.deleted_at is null
				left join images i on i.id=u.imagen_id and i.deleted_at is null
				left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
				WHERE m.grupo_id=?';

			$alumnos 		= DB::select($consulta, [$res['grupos'][$i]->id]);
			$alumnos_res 	= [];

			for ($j=0; $j < count($alumnos); $j++) { 
				
				$discCtrl->datosAlumno($alumnos[$j], $user->year_id);
				
				if (count($alumnos[$j]->periodo1) > 0 || count($alumnos[$j]->periodo2) > 0 || count($alumnos[$j]->periodo3) > 0 || count($alumnos[$j]->periodo4) > 0) {
					array_push($alumnos_res, $alumnos[$j]);
				}

			}

			$res['grupos'][$i]->alumnos = $alumnos_res;
			
		}

		return $res;
	}



	public function putObservadorPeriodo()
	{
		$user 		= User::fromToken();
		$grupo_id 	= Request::input('grupo_id');

        $consulta 	= 'SELECT * FROM images WHERE publica=true and deleted_at is null';
        $imagenes 	= DB::select($consulta);


        $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                        p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                        g.created_at, g.updated_at, gra.nombre as nombre_grado 
                    from grupos g
                    inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                    left join profesores p on p.id=g.titular_id
                    where g.deleted_at is null AND g.id=:grupo_id
                    order by g.orden';

		$grupo = DB::select($consulta, [':year_id'=> $user->year_id, ':grupo_id' => $grupo_id ] )[0];
		
		$discCtrl 	= new DisciplinaController;
		
		$consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
		$alumnos 	= DB::select($consulta, [$grupo->id]);

		for ($j=0; $j < count($alumnos); $j++) { 
			// Datos de disciplina (situaciones, tardanzas)
			$discCtrl->datosAlumno($alumnos[$j], $user->year_id);

			// Datos comportamiento (frases de cada periodo y nota del titular)
			$alumno = $alumnos[$j];

			$consulta = 'SELECT n.*, p.fecha_fin, p.numero 
				FROM nota_comportamiento n 
				INNER JOIN periodos p ON n.periodo_id=p.id and p.year_id=:year_id and p.deleted_at is null
				WHERE n.alumno_id=:alumno_id and p.id=:periodo_id and n.deleted_at is null';

			$notas = DB::select($consulta, [':year_id'=>$user->year_id, ':alumno_id'=>$alumno->alumno_id, ':periodo_id'=>$user->periodo_id]);

			for ($i=0; $i < count($notas); $i++) { 
				$nota = $notas[$i];

				$consulta = 'SELECT * FROM (
								SELECT d.id as definicion_id, d.comportamiento_id, d.frase_id, 
									f.frase, f.tipo_frase, f.year_id
								FROM definiciones_comportamiento d
								inner join frases f on d.frase_id=f.id and d.deleted_at is null 
								where d.comportamiento_id=:comportamiento1_id and f.deleted_at is null
							union
								select d2.id as definicion_id, d2.comportamiento_id, d2.frase_id, 
									d2.frase, null as tipo_frase, null as year_id
								from definiciones_comportamiento d2 where d2.deleted_at is null and d2.frase is not null                  
								and d2.comportamiento_id=:comportamiento2_id 
								
							) defi';

				$definiciones = DB::select($consulta, ['comportamiento1_id' => $nota->id, 'comportamiento2_id' => $nota->id]);
				
				$nota->definiciones = $definiciones;

			}
			$alumno->notas = $notas;

			$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.telefono, pa.parentesco, pa.id as parentesco_id, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active
						FROM parentescos pa
						left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						WHERE pa.alumno_id=? and pa.deleted_at is null';
						
			$acudientes    = DB::select($consulta, [ $alumno->alumno_id ] );
			$alumno->acudientes = $acudientes;


			// Traigo el libro
			$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
				WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';
				
			$libro 	= DB::select($consulta, [ $alumno->alumno_id, $user->year_id ]);
			
			if (count($libro) > 0) {
				$libro = $libro[0];
			}else{
				$consulta_crear = 'INSERT INTO dis_libro_rojo (alumno_id, year_id, updated_by) VALUES (?,?,?)';
				DB::insert($consulta_crear, [ $alumno->alumno_id, $user->year_id, $user->user_id ]);
				$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
					WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';
					
				$libro 	= DB::select($consulta, [ $alumno->alumno_id, $user->year_id ]);
				if (count($libro) > 0) {
					$libro = $libro[0];
				}
			}
			$alumno->libro = $libro;


			// Traigo los procesos
			$consulta 	= 'SELECT d.*, pe.numero as periodo_numero, SUBSTRING(d.fecha_hora_aprox, 1, 10) as fecha_corta, CONCAT(p.nombres, " ", p.apellidos) as profesor_nombre 
				FROM dis_procesos d 
				INNER JOIN periodos pe ON pe.id=d.periodo_id and pe.deleted_at is null
				LEFT JOIN profesores p ON p.id=d.profesor_id and p.deleted_at is null
				WHERE alumno_id=? and d.periodo_id=? and d.deleted_at is null';
				
			$procesos 	= DB::select($consulta, [ $alumno->alumno_id, $user->periodo_id ]);
			
			for ($k=0; $k < count($procesos); $k++) { 
				$consulta 	= 'SELECT d.* FROM dis_proceso_ordinales d WHERE proceso_id=? and d.deleted_at is null';
				$procesos[$k]->proceso_ordinales 	= DB::select($consulta, [ $procesos[$k]->id ]);
			}
			$alumno->procesos_disciplinarios = $procesos;

		}

		$grupo->alumnos = $alumnos;


		return ['grupo' => $grupo, 'imagenes' => $imagenes];
	}





	public function putObservadorCompleto()
	{
		$user 		= User::fromToken();
		$grupo_id 	= Request::input('grupo_id');

        $consulta 	= 'SELECT * FROM images WHERE publica=true and deleted_at is null';
        $imagenes 	= DB::select($consulta);


        $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                        p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                        g.created_at, g.updated_at, gra.nombre as nombre_grado 
                    from grupos g
                    inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                    left join profesores p on p.id=g.titular_id
                    where g.deleted_at is null AND g.id=:grupo_id
                    order by g.orden';

		$grupo = DB::select($consulta, [':year_id'=> $user->year_id, ':grupo_id' => $grupo_id ] )[0];
		
		$discCtrl 	= new DisciplinaController;
		
		$consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
		$alumnos 	= DB::select($consulta, [$grupo->id]);

		for ($j=0; $j < count($alumnos); $j++) { 
			// Datos de disciplina (situaciones, tardanzas)
			$discCtrl->datosAlumno($alumnos[$j], $user->year_id);

			// Datos comportamiento (frases de cada periodo y nota del titular)
			$alumno = $alumnos[$j];

			$consulta = 'SELECT n.*, p.fecha_fin, p.numero 
				FROM nota_comportamiento n 
				INNER JOIN periodos p ON n.periodo_id=p.id and p.year_id=:year_id and p.deleted_at is null
				WHERE n.alumno_id=:alumno_id and n.deleted_at is null';

			$notas = DB::select($consulta, [':year_id'=>$user->year_id, ':alumno_id'=>$alumno->alumno_id]);

			for ($i=0; $i < count($notas); $i++) { 
				$nota = $notas[$i];

				$consulta = 'SELECT * FROM (
								SELECT d.id as definicion_id, d.comportamiento_id, d.frase_id, 
									f.frase, f.tipo_frase, f.year_id
								FROM definiciones_comportamiento d
								inner join frases f on d.frase_id=f.id and d.deleted_at is null 
								where d.comportamiento_id=:comportamiento1_id and f.deleted_at is null
							union
								select d2.id as definicion_id, d2.comportamiento_id, d2.frase_id, 
									d2.frase, null as tipo_frase, null as year_id
								from definiciones_comportamiento d2 where d2.deleted_at is null and d2.frase is not null                  
								and d2.comportamiento_id=:comportamiento2_id 
								
							) defi';

				$definiciones = DB::select($consulta, ['comportamiento1_id' => $nota->id, 'comportamiento2_id' => $nota->id]);
				
				$nota->definiciones = $definiciones;

			}
			$alumno->notas = $notas;

			$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.telefono, pa.parentesco, pa.id as parentesco_id, ac.user_id, 
							ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
							ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
							u.username, u.is_active
						FROM parentescos pa
						left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
						left join users u on ac.user_id=u.id and u.deleted_at is null
						left join images i on i.id=ac.foto_id and i.deleted_at is null
						WHERE pa.alumno_id=? and pa.deleted_at is null';
						
			$acudientes    = DB::select($consulta, [ $alumno->alumno_id ] );
			$alumno->acudientes = $acudientes;


			// Traigo el libro
			$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
				WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';
				
			$libro 	= DB::select($consulta, [ $alumno->alumno_id, $user->year_id ]);
			
			if (count($libro) > 0) {
				$libro = $libro[0];
			}else{
				$consulta_crear = 'INSERT INTO dis_libro_rojo (alumno_id, year_id, updated_by) VALUES (?,?,?)';
				DB::insert($consulta_crear, [ $alumno->alumno_id, $user->year_id, $user->user_id ]);
				$consulta 	= 'SELECT d.* FROM dis_libro_rojo d 
					WHERE alumno_id=? and d.year_id=? and d.deleted_at is null';
					
				$libro 	= DB::select($consulta, [ $alumno->alumno_id, $user->year_id ]);
				if (count($libro) > 0) {
					$libro = $libro[0];
				}
			}
			$alumno->libro = $libro;


			// Traigo los procesos
			$consulta 	= 'SELECT d.*, pe.numero as periodo_numero, SUBSTRING(d.fecha_hora_aprox, 1, 10) as fecha_corta, CONCAT(p.nombres, " ", p.apellidos) as profesor_nombre 
				FROM dis_procesos d 
				INNER JOIN periodos pe ON pe.id=d.periodo_id and pe.deleted_at is null
				LEFT JOIN profesores p ON p.id=d.profesor_id and p.deleted_at is null
				WHERE alumno_id=? and d.periodo_id=? and d.deleted_at is null';
				
			$procesos 	= DB::select($consulta, [ $alumno->alumno_id, $user->periodo_id ]);
			
			for ($k=0; $k < count($procesos); $k++) { 
				$consulta 	= 'SELECT d.* FROM dis_proceso_ordinales d WHERE proceso_id=? and d.deleted_at is null';
				$procesos[$k]->proceso_ordinales 	= DB::select($consulta, [ $procesos[$k]->id ]);
			}
			$alumno->procesos_disciplinarios = $procesos;

		}

		$grupo->alumnos = $alumnos;


		return ['grupo' => $grupo, 'imagenes' => $imagenes];
	}



}
