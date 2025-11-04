<?php namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use Request;
use DB;
use Log;

use App\User;
use App\Models\NotaComportamiento;
use App\Models\Grupo;
use App\Models\Alumno;
use App\Models\Frase;

use Carbon\Carbon;


class DisciplinaController extends Controller {
	
	
	private $consulta_alumno = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
			a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento, a.no_matricula, 
			m.grupo_id, m.estado, m.nuevo, m.repitente, username,
			u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
			a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
		FROM alumnos a 
		inner join matriculas m on a.id=m.alumno_id and m.deleted_at is null
		left join users u on a.user_id=u.id and u.deleted_at is null
		left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
		left join images i on i.id=u.imagen_id and i.deleted_at is null
		left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
		where a.id=? and a.deleted_at is null';




	public function putAlumnos()
	{
		$user 		= User::fromToken();
		$now 		= Carbon::now('America/Bogota');
		$grupo_id 	= Request::input('grupo_id');
		$year_id 	= Request::input('year_id', $user->year_id);
		
		$cons_periodos 	= 'SELECT id, numero FROM periodos WHERE year_id=? and deleted_at is null';
		$periodos 		= DB::select($cons_periodos, [$year_id]);
		
		$alumnos = Grupo::alumnos($grupo_id);
		
		$cant_al = count($alumnos);
		
		for ($i=0; $i < $cant_al; $i++) {
			
			$this->datosAlumno($alumnos[$i], $year_id, $periodos);
			
		}

		return ['alumnos' => $alumnos];
	}
	
	
	
	public function datosAlumno(&$alumno, $year_id, $periodos=false){
		
		if ($periodos==false) {
			
			$cons_periodos 	= 'SELECT id, numero FROM periodos WHERE year_id=? and deleted_at is null';
			$periodos 		= DB::select($cons_periodos, [$year_id]);
			
		}



		for ($j=0; $j < count($periodos); $j++) { 

			// Traigo tardanzas al colegio
			$consulta 	= 'SELECT a.* FROM ausencias a WHERE a.alumno_id=? and a.periodo_id=? and a.entrada=1 and (a.cantidad_tardanza>0 or a.cantidad_tardanza is null) and a.deleted_at is null';
			$tardanzas 	= DB::select($consulta, [ $alumno->alumno_id, $periodos[$j]->id ]);
			$name 		= 'tardanzas_per' . $periodos[$j]->numero;
			$alumno->{$name} = $tardanzas;


			// Traigo fallas de uniformes
			$consulta 	= 'SELECT a.* FROM uniformes a WHERE a.alumno_id=? and a.periodo_id=? and a.deleted_at is null';
			$uniformes 	= DB::select($consulta, [ $alumno->alumno_id, $periodos[$j]->id ]);
			$name 		= 'uniformes_per' . $periodos[$j]->numero;
			$alumno->{$name} = $uniformes;


			// Traido los procesos
			$consulta 	= 'SELECT d.*, SUBSTRING(d.fecha_hora_aprox, 1, 10) as fecha_corta, CONCAT(p.nombres, " ", p.apellidos) as profesor_nombre,
					? as periodo_numero
				FROM dis_procesos d 
				LEFT JOIN profesores p ON p.id=d.profesor_id and p.deleted_at is null
				WHERE alumno_id=? and d.periodo_id=? and d.deleted_at is null';

			$procesos 	= DB::select($consulta, [ $periodos[$j]->numero, $alumno->alumno_id, $periodos[$j]->id ]);

			for ($k=0; $k < count($procesos); $k++) { 
				$consulta 	= 'SELECT d.* FROM dis_proceso_ordinales d WHERE proceso_id=? and d.deleted_at is null';
				$procesos[$k]->proceso_ordinales 	= DB::select($consulta, [ $procesos[$k]->id ]);
			}
			$name 		= 'periodo' . $periodos[$j]->numero;
			$alumno->{$name} = $procesos;
			
			if ($periodos[$j]->numero == 1) {
				$alumno->per1_cant_t1 = 0;
				$alumno->per1_cant_t2 = 0;
				$alumno->per1_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per1_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per1_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per1_cant_t3++;
					}
				}
			}
			
			if ($periodos[$j]->numero == 2) {
				$alumno->per2_cant_t1 = 0;
				$alumno->per2_cant_t2 = 0;
				$alumno->per2_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per2_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per2_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per2_cant_t3++;
					}
				}
			}
			
			if ($periodos[$j]->numero == 3) {
				$alumno->per3_cant_t1 = 0;
				$alumno->per3_cant_t2 = 0;
				$alumno->per3_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per3_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per3_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per3_cant_t3++;
					}
				}
			}
			
			if ($periodos[$j]->numero == 4) {
				$alumno->per4_cant_t1 = 0;
				$alumno->per4_cant_t2 = 0;
				$alumno->per4_cant_t3 = 0;
				
				for ($k=0; $k < count($procesos); $k++) { 
					if ($procesos[$k]->tipo_situacion == 1 && $procesos[$k]->become_id == null) {
						$alumno->per4_cant_t1++;
					}elseif($procesos[$k]->tipo_situacion == 2 && $procesos[$k]->become_id == null) {
						$alumno->per4_cant_t2++;
					}elseif($procesos[$k]->tipo_situacion == 3 && $procesos[$k]->become_id == null) {
						$alumno->per4_cant_t3++;
					}
				}
			}
			
		}
		
	}

	

	
	public function postStore()
	{
		$user 	        		= User::fromToken();
		$now 					= Carbon::now('America/Bogota');
		$year_id     			= Request::input('year_id');
		$alumno_id     			= Request::input('alumno_id');
		$periodo_id     		= Request::input('periodo_id');
		$descripcion    		= Request::input('descripcion');
		$testigos    			= Request::input('testigos');
		$descargo    			= Request::input('descargo');
		$tipo_situacion 		= Request::input('tipo_situacion');
		$profesor_id 			= Request::input('profesor') ? Request::input('profesor')['profesor_id'] : null;
		$fecha_hora_aprox 		= Request::input('fecha_hora_aprox');
		$deriva_de_tardanzas 	= Request::input('deriva_de_tardanzas', 0);
		$dependencias 			= Request::input('dependencias');
		
		$depe_t1 	= 0;
		$depe_t2 	= 0;
		

		if ($fecha_hora_aprox) {
			$fecha_hora_aprox 	= Carbon::parse($fecha_hora_aprox);
		}

		
		// Inserto el proceso
		$consulta = 'INSERT INTO dis_procesos(year_id, alumno_id, periodo_id, fecha_hora_aprox, descripcion, testigos, descargo, 
			tipo_situacion, profesor_id, deriva_de_tardanzas, created_at, updated_at, added_by) 
			VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)';
			
		$datos = [ $year_id, $alumno_id, $periodo_id, $fecha_hora_aprox, $descripcion, $testigos, $descargo, $tipo_situacion, $profesor_id, $deriva_de_tardanzas, $now, $now, $user->user_id ];
		
		DB::insert($consulta, $datos);
		
		// Traemos el proceso
		$last_id = DB::getPdo()->lastInsertId();
		
		
		// Insertamos cada ordinal
		$selected_ordinales = Request::input('selected_ordinales');
		if (is_array($selected_ordinales)) {
			for ($i=0; $i < count($selected_ordinales); $i++) { 
				$consulta = 'INSERT INTO dis_proceso_ordinales(ordinal_id, proceso_id, added_by, created_at, updated_at) VALUES(?,?,?,?,?)';
				DB::insert($consulta, [ $selected_ordinales[$i]['id'], $last_id, $user->user_id, $now, $now ]);
				
			}
		}
		
		
		// Modificamos las faltas de las que depende de este proceso
		if (is_array($dependencias)) {
			for ($i=0; $i < count($dependencias); $i++) { 
				$consulta = 'UPDATE dis_procesos SET become_id=? WHERE id=?';
				DB::update($consulta, [ $last_id, $dependencias[$i]['id'] ]);
			}
		}
		
		
		
		
		$alumno 	= DB::select($this->consulta_alumno, [$alumno_id])[0];

		$this->datosAlumno($alumno, $year_id);
		
		return (array)$alumno;
	}



	public function putCambiarSituacionDerivante()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		
		$id     			= Request::input('id');
		$become_id     		= Request::input('become_id');


		$consulta 	= 'UPDATE dis_procesos SET become_id=? WHERE id=?'; // No creo que sea chévere poner la fecha y modificador
		$datos 		= [ $become_id, $id ];
		
		DB::update($consulta, $datos);
		
		return 'Guardado';
	}
	
	
	public function putUpdate()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		
		$alumno_id     		= Request::input('alumno_id');
		$proceso_id     	= Request::input('id');
		$year_id     		= Request::input('year_id', $user->year_id);
		$descripcion 		= Request::input('descripcion');
		$tipo_situacion 	= Request::input('tipo_situacion');
		$ordinales 			= Request::input('proceso_ordinales');
		$dependencias 		= Request::input('dependencias');
		$profesor 			= Request::input('profesor');
		$fecha_hora_aprox 	= Request::input('fecha_hora_aprox');
		$testigos 			= Request::input('testigos');
		$descargo 			= Request::input('descargo');
		
		if ($fecha_hora_aprox) {
			$fecha_hora_aprox 	= Carbon::parse($fecha_hora_aprox);
		}
		
		if ($profesor) {
			$profesor = $profesor['profesor_id'];
		}
		

		$consulta = 'UPDATE dis_procesos SET descripcion=?, tipo_situacion=?,
			profesor_id=?, fecha_hora_aprox=?, testigos=?, descargo=?, updated_by=?, updated_at=? WHERE id=?';
		
		$datos 		= [ $descripcion, $tipo_situacion, $profesor, $fecha_hora_aprox, $testigos, $descargo, $user->user_id, $now, $proceso_id ];
		DB::update($consulta, $datos);
		
		// Modificamos los procesos que llevaron a esta falta
		for ($i=0; $i < count($dependencias); $i++) { 

			if (array_key_exists('asignado', $dependencias[$i])) {
				
				$consulta 	= 'UPDATE dis_procesos SET become_id=? WHERE id=?'; // No creo que sea chévere poner la fecha y modificador
				$datos 		= [ $proceso_id, $dependencias[$i]['id'] ];
				
			}else{
				
				$consulta 	= 'UPDATE dis_procesos SET become_id=null WHERE id=?'; // No creo que sea chévere poner la fecha y modificador
				$datos 		= [ $dependencias[$i]['id'] ];
				
			}
			DB::update($consulta, $datos);
			
		}
		
		$alumno 	= DB::select($this->consulta_alumno, [$alumno_id])[0];
		$this->datosAlumno($alumno, $year_id);

		return (array)$alumno;
	}
	
	public function putQuitarOrdinal()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		$proceso_id     	= Request::input('proceso_id');
		$ordinal_id     	= Request::input('id');
		
		$consulta 	= 'UPDATE dis_proceso_ordinales SET deleted_at=?, deleted_by=? WHERE proceso_id=? and ordinal_id=?'; 
		$datos 		= [ $now, $user->user_id, $proceso_id, $ordinal_id ];
		
		DB::update($consulta, $datos);
		
		return 'Quitado';
	}

	public function postAsignarOrdinal()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		$proceso_id     	= Request::input('proceso_id');
		$ordinal_id     	= Request::input('id');
		
		$consulta 	= 'INSERT INTO dis_proceso_ordinales(ordinal_id, proceso_id, added_by, created_at, updated_at) VALUES(?,?,?,?,?)'; 
		$datos 		= [ $ordinal_id, $proceso_id, $user->user_id, $now, $now ];
		
		DB::update($consulta, $datos);
		
		$last_id = DB::getPdo()->lastInsertId();
		$ordinal = DB::select('SELECT * FROM dis_proceso_ordinales WHERE id=?', [$last_id])[0]; 
		
		return (array)$ordinal;
	}

	public function putDestroy()
	{
		$user 	        	= User::fromToken();
		$now 				= Carbon::now('America/Bogota');
		$proceso_id     	= Request::input('proceso_id');
		$alumno_id     		= Request::input('alumno_id');
		
		
		$consulta 	= 'UPDATE dis_procesos SET deleted_at=?, deleted_by=? WHERE id=?'; 
		$datos 		= [ $now, $user->user_id, $proceso_id ];
		
		DB::update($consulta, $datos);
		
		$alumno 	= DB::select($this->consulta_alumno, [$alumno_id])[0];

		$this->datosAlumno($alumno, $user->year_id);
		
		return (array)$alumno;
	}
	
	

	

}