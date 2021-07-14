<?php namespace App\Http\Controllers\Alumnos;


use DB;
use Request;
use Excel;
use Hash;
use Carbon\Carbon;
use \Log;

use App\User;
use App\Models\Role;
use App\Models\Matricula;
use App\Models\Year;
use App\Models\Alumno;
use App\Models\Debugging;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;
use App\Http\Controllers\Alumnos\Definitivas;

use App\Http\Controllers\Alumnos\Solicitudes;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Alumnos\ImporterFixer;


class ImportarController extends Controller {

	public function postAlgo($year)
	{
		if(Request::hasFile('file')){
			$path = Request::file('file')->getRealPath();
			
			$rr = Excel::import($path, function($reader) use ($year){
				
				$now 		= Carbon::now('America/Bogota');
				$results 	= $reader->all();
				$fixer 		= new ImporterFixer();
				
				for ($i=0; $i < count($results); $i++) { 
					
					
					$abrev 		= $results[$i]->getTitle();
					Debugging::pin('$abrev', $abrev);
					
					$consulta 	= 'SELECT g.id, g.abrev, g.year_id FROM grupos g inner join years y on y.id=g.year_id WHERE g.abrev=? and g.deleted_at is null and y.deleted_at is null and y.year=?;';
					$grupo 		= DB::select($consulta, [$abrev, $year]);
					
					$grupo 		= DB::select($consulta, [$abrev, $year])[0];
					
					for ($f=0; $f < count($results[$i]); $f++) { 
						
						$alumno 	= $results[$i][$f];
						$res 		= $fixer->verificar($alumno, $year);
						$alumno->ciudad_docu_acud1 = $res['ciudad_id_A1'];
						$alumno->ciudad_docu_acud2 = $res['ciudad_id_A2'];

						if ($alumno->id) {
							$consulta 	= 'UPDATE alumnos SET no_matricula=?, nombres=?, apellidos=?, sexo=?, fecha_nac=?, 
								tipo_doc=?, documento=?, no_matricula=?, direccion=?, barrio=?, telefono=?, celular=?, estrato=?, 
								tipo_sangre=?, eps=?, religion=?, updated_at=?'.$res['consulta'].' WHERE id=?';
								
		
							DB::update($consulta, [$alumno->no_matricula, $alumno->primer_nombre.' '.$alumno->segundo_nombre, $alumno->primer_apellido.' '.$alumno->segundo_apellido, $alumno->sexo, $alumno->fecha_de_nacim, 
									$alumno->tipo_doc, $alumno->nro_de_documento, $alumno->numero_matricula, $alumno->direccion_residencia, $alumno->barrio, $alumno->telefono, $alumno->celular, $alumno->estrato, 
									$alumno->rh, $alumno->eps, $alumno->religion, $now, $alumno->id])[0];
							
									
							DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.estado=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno->es_nuevo, $alumno->estado_matricula, $now, $alumno->id]);
							
							//No eliminar!!
							Debugging::pin('Alum_id: ' . $alumno->id, 'Grupo: ' . $abrev, 'Grupo_id: ' . $grupo->id) ;
							
							
							// Acudiente 1
							$this->modificar_acudiente1($alumno, $now, $res['consultaA1']);
			
							// Acudiente 2
							$this->modificar_acudiente2($alumno, $now, $res['consultaA2']);
			
			
						}else{
							
							$alumno_row = $results[$i][$f];
							
							if ($alumno_row->primer_nombre) {
								$alumno = new Alumno;
								$alumno->nombres    			= $alumno_row->primer_nombre.' '.$alumno_row->segundo_nombre;
								$alumno->apellidos  			= $alumno_row->primer_apellido.' '.$alumno_row->segundo_apellido;
								$alumno->sexo       			= $alumno_row->sexo ? $alumno_row->sexo : 'M';
								$alumno->tipo_doc   			= $alumno_row->tipo_doc;
								$alumno->documento  			= $alumno_row->nro_de_documento;
								$alumno->no_matricula 			= $alumno_row->numero_matricula;
								$alumno->direccion 				= $alumno_row->direccion_residencia;
								$alumno->barrio 				= $alumno_row->barrio;
								$alumno->fecha_nac 				= $alumno_row->fecha_de_nacim;
								$alumno->telefono 				= $alumno_row->telefono;
								$alumno->celular 				= $alumno_row->celular;
								$alumno->estrato 				= $alumno_row->estrato;
								$alumno->eps 					= $alumno_row->eps;
								$alumno->tipo_sangre 			= $alumno_row->rh;
								$alumno->religion 				= $alumno_row->religion;
								$alumno->save();
								
								$alumno_row->id = $alumno->id;
								
								$opera = new OperacionesAlumnos();
								
								$usuario = new User;
								$usuario->username		=	$opera->username_no_repetido($alumno->nombres);
								$usuario->password		=	Hash::make('123456');
								$usuario->sexo			=	$alumno_row->sexo ? $alumno_row->sexo : 'M';
								$usuario->is_superuser	=	false;
								$usuario->periodo_id	=	1; // Verificar que haya un periodo cod 1
								$usuario->is_active		=	true;
								$usuario->tipo			=	'Alumno';
								$usuario->save();

								
								$role = Role::where('name', 'Alumno')->get();
								$usuario->attachRole($role[0]);

								$alumno->user_id = $usuario->id;
								$alumno->save();


								$matricula = new Matricula;
								$matricula->alumno_id		=	$alumno->id;
								$matricula->grupo_id		=	$grupo->id;
								$matricula->estado			=	"MATR";
								$matricula->fecha_matricula = 	$now;
								$matricula->save();


								// Acudiente 1
								$this->modificar_acudiente1($alumno_row, $now, $res['consultaA1']);
				
								// Acudiente 2
								$this->modificar_acudiente2($alumno_row, $now, $res['consultaA2']);
				
							
							}
						
						}
						
					}
					
				}
				
			});
		}
		return (array)$rr;
	}

	

	public function postCartera()
	{
		if(Request::hasFile('file')){
			$path = Request::file('file')->getRealPath();

			$rr = Excel::import($path, function($reader){
				
				$now 		= Carbon::now('America/Bogota');
				$results 	= $reader->all();
				
				for ($i=0; $i < count($results); $i++) {
					$alumno 	= $results[$i];
					
					
					if (strtolower($results[$i]->pazysalvo) == 'si' || strtolower($results[$i]->paz_y_salvo) == 'si') {
						$pazysalvo = 1;
					}else{
						$pazysalvo = 0;
					}
					
					$fecha_pension = null;
					
					if ($results[$i]->fecha_pension) {
						$fecha_pension = Carbon::parse($results[$i]->fecha_pension);
					}
					
					if ($results[$i]->fecha) {
						$fecha_pension = Carbon::parse($results[$i]->fecha);
					}
					
					if ($results[$i]->documento) {
						$consulta 	= 'UPDATE alumnos SET deuda=?, pazysalvo=? WHERE documento=?;';
						$actua 		= DB::update($consulta, [$results[$i]->deuda, $pazysalvo, $results[$i]->documento]);
						
						DB::update('UPDATE matriculas m INNER JOIN alumnos a ON a.id=m.alumno_id and m.deleted_at is null 
							SET m.fecha_pension=? WHERE a.documento=? and a.deleted_at is null', 
							[$fecha_pension, $alumno->documento]);
						
						//No eliminar para continuar si se cae el servidor!!
						Debugging::pin('Alum_documento: ' . $alumno->documento) ;
						
					}
					
				}
				
			});
		}
		return (array)$rr;
	}

	

	public function getIndex()
	{

		$rr = Excel::import('app/Http/Controllers/Alumnos/archivos/alumnos.xls', function($reader) {

			$results 	= $reader->all();
			$now 		= Carbon::parse(Request::input('fecha_matricula'));
			
			
			for ($i=0; $i < count($results); $i++) { 
				
				
				$abrev 		= $results[$i]->getTitle();
				$consulta 	= 'SELECT * FROM grupos WHERE abrev=?';
				$grupo 		= DB::select($consulta, [$abrev])[0];
				
				for ($f=0; $f < count($results[$i]); $f++) { 
					
					$alumno_row = $results[$i][$f];
					
					$alumno = new Alumno;
					$alumno->nombres    = $alumno_row->nombres;
					$alumno->apellidos  = $alumno_row->apellidos;
					$alumno->sexo       = $alumno_row->sexo ? $alumno_row->sexo : 'M';
					$alumno->save();
					
					
					$opera = new OperacionesAlumnos();
					
					$usuario = new User;
					$usuario->username		=	$opera->username_no_repetido($alumno->nombres);
					$usuario->password		=	Hash::make('123456');
					$usuario->sexo			=	$alumno_row->sexo ? $alumno_row->sexo : 'M';
					$usuario->is_superuser	=	false;
					$usuario->periodo_id	=	1; // Verificar que haya un periodo cod 1
					$usuario->is_active		=	true;
					$usuario->tipo			=	'Alumno';
					$usuario->save();

					
					$role = Role::where('name', 'Alumno')->get();
					$usuario->attachRole($role[0]);

					$alumno->user_id = $usuario->id;
					$alumno->save();


					$matricula = new Matricula;
					$matricula->alumno_id		=	$alumno->id;
					$matricula->grupo_id		=	$grupo->id;
					$matricula->estado			=	"MATR";
					$matricula->fecha_matricula = 	$now;
					$matricula->save();

				
				}
			}
		});
		
		return (array)$rr;
	}

	
	
	

	public function getModificar($year)
	{
		$host = apache_request_headers()['Host'];
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        }else{
            $extension = 'xlsx';
		}
		
		$rr = Excel::import('app/Http/Controllers/Alumnos/archivos/alumnos-modificar-'.$year.'.'.$extension, function($reader) use ($year) {

			$now 		= Carbon::now('America/Bogota');
			$results 	= $reader->all();
			$fixer 		= new ImporterFixer();
			
			for ($i=0; $i < count($results); $i++) { 
				
				
				$abrev 		= $results[$i]->getTitle();
				$consulta 	= 'SELECT g.id, g.abrev, g.year_id FROM grupos g inner join years y on y.id=g.year_id WHERE g.abrev=? and g.deleted_At is null and y.deleted_at is null and y.year=?;';
				$grupo 		= DB::select($consulta, [$abrev, $year])[0];
				
				for ($f=0; $f < count($results[$i]); $f++) { 
					
					$alumno 	= $results[$i][$f];
					$res 		= $fixer->verificar($alumno, $year);
					$alumno->ciudad_docu_acud1 = $res['ciudad_id_A1'];
					$alumno->ciudad_docu_acud2 = $res['ciudad_id_A2'];
					
					if ($alumno->id) {
						$consulta 	= 'UPDATE alumnos SET no_matricula=?, nombres=?, apellidos=?, sexo=?, fecha_nac=?, 
							tipo_doc=?, documento=?, no_matricula=?, direccion=?, barrio=?, telefono=?, celular=?, estrato=?, 
							tipo_sangre=?, eps=?, religion=?, updated_at=?'.$res['consulta'].' WHERE id=?';
							
	
						DB::update($consulta, [$alumno->no_matricula, $alumno->primer_nombre.' '.$alumno->segundo_nombre, $alumno->primer_apellido.' '.$alumno->segundo_apellido, $alumno->sexo, $alumno->fecha_de_nacim, 
								$alumno->tipo_doc, $alumno->nro_de_documento, $alumno->numero_matricula, $alumno->direccion_residencia, $alumno->barrio, $alumno->telefono, $alumno->celular, $alumno->estrato, 
								$alumno->rh, $alumno->eps, $alumno->religion, $now, $alumno->id])[0];
						
								
						DB::update('UPDATE matriculas m INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and g.deleted_at is null SET m.nuevo=?, m.estado=?, m.updated_at=? WHERE m.alumno_id=? and m.deleted_at is null', [$grupo->year_id, $alumno->es_nuevo, $alumno->estado_matricula, $now, $alumno->id]);
						
						//No eliminar!!
						Debugging::pin('Alum_id: ' . $alumno->id, 'Grupo: ' . $abrev, 'Grupo_id: ' . $grupo->id) ;
						
						
						// Acudiente 1
						$this->modificar_acudiente1($alumno, $now, $res['consultaA1']);
		
						// Acudiente 2
						$this->modificar_acudiente2($alumno, $now, $res['consultaA2']);
		
		
					}
					
				}
				
			}
			
		});
		
		return (array)$rr;
	}
	
	
	private function modificar_acudiente1(&$alumno, $now, $consulta){
		
		$alumno->sexo_acud1 = ((is_null($alumno->sexo_acud1) || $alumno->sexo_acud1 == '') ? 'M' : $alumno->sexo_acud1);
		
		
		if($alumno->id_acud1 > 0 && (!(is_null($alumno->nombres_acud1) || $alumno->nombres_acud1 == ''))){
							
			// Si tiene código y tiene nombre escrito, sólo quiere modificarlo
			DB::update('UPDATE acudientes SET nombres=?, apellidos=?, sexo=?, tipo_doc=?, documento=?, is_acudiente=?, telefono=?, celular=?, ocupacion=?, direccion=?, email=?, updated_at=?'.$consulta.' WHERE id=?', 
				[$alumno->nombres_acud1, $alumno->apellidos_acud1, $alumno->sexo_acud1, $alumno->tipo_docu_acud1_id, $alumno->documento_acud1, ($alumno->is_acudiente1?$alumno->is_acudiente1:1), 
				$alumno->telefono_acud1, $alumno->celular_acud1, $alumno->ocupacion_acud1, $alumno->direccion_acud1, $alumno->email_acud1, $now, $alumno->id_acud1 ]);
				
			DB::update('UPDATE parentescos p INNER JOIN acudientes a ON a.id=p.acudiente_id and p.alumno_id=? and p.acudiente_id=? and p.deleted_at is null and a.deleted_at is null 
				SET p.parentesco=?, p.observaciones=?, p.updated_at=?', [ $alumno->id, $alumno->id_acud1, $alumno->parentesco_acud1, $alumno->observaciones_acud1, $now ]);
				
		
		}else if($alumno->id_acud1 > 0 && (is_null($alumno->nombres_acud1) || $alumno->nombres_acud1 == '')){
			
			// Si tiene código y NO tiene nombre escrito, quiere añadirlo como nuevo acudiente de este alumno, NO modificarlo
			DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [ $alumno->id_acud1, $alumno->id, $alumno->parentesco_acud1, $alumno->observaciones_acud1, $now, $now ]);
		
		}else{
			if (!(is_null($alumno->nombres_acud1) || $alumno->nombres_acud1 == '')) {
				DB::insert('INSERT INTO acudientes(nombres, apellidos, sexo, tipo_doc, documento, is_acudiente, telefono, celular, ocupacion, direccion, email, created_at, updated_at, ciudad_doc) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 
					[$alumno->nombres_acud1, $alumno->apellidos_acud1, $alumno->sexo_acud1, $alumno->tipo_docu_acud1_id, $alumno->documento_acud1, ($alumno->is_acudiente1?$alumno->is_acudiente1:1), 
					$alumno->telefono_acud1, $alumno->celular_acud1, $alumno->ocupacion_acud1, $alumno->direccion_acud1, $alumno->email_acud1, $now, $now, $alumno->ciudad_docu_acud1]);
					
				$last_id = DB::getPdo()->lastInsertId();
				DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [ $last_id, $alumno->id, $alumno->parentesco_acud1, $alumno->observaciones_acud1, $now, $now ]);
			}
		}
	}


	private function modificar_acudiente2(&$alumno, $now, $consulta){
		
		$alumno->sexo_acud2 = ((is_null($alumno->sexo_acud2) || $alumno->sexo_acud2 == '') ? 'M' : $alumno->sexo_acud2);
		
		if($alumno->id_acud2 > 0 && (!(is_null($alumno->nombres_acud2) || $alumno->nombres_acud2 == ''))){
							
			// Si tiene código y tiene nombre escrito, sólo quiere modificarlo
			DB::update('UPDATE acudientes SET nombres=?, apellidos=?, sexo=?, tipo_doc=?, documento=?, is_acudiente=?, telefono=?, celular=?, ocupacion=?, direccion=?, email=?, updated_at=?'.$consulta.' WHERE id=?', 
				[$alumno->nombres_acud2, $alumno->apellidos_acud2, $alumno->sexo_acud2, $alumno->tipo_docu_acud2_id, $alumno->documento_acud2, ($alumno->is_acudiente2?$alumno->is_acudiente2:1), 
				$alumno->telefono_acud2, $alumno->celular_acud2, $alumno->ocupacion_acud2, $alumno->direccion_acud2, $alumno->email_acud2, $now, $alumno->id_acud2 ]);
				
			DB::update('UPDATE parentescos p INNER JOIN acudientes a ON a.id=p.acudiente_id and p.alumno_id=? and p.acudiente_id=? and p.deleted_at is null and a.deleted_at is null 
				SET p.parentesco=?, p.observaciones=?, p.updated_at=?', [ $alumno->id, $alumno->id_acud2, $alumno->parentesco_acud2, $alumno->observaciones_acud2, $now ]);
				
		
		}else if($alumno->id_acud2 > 0 && (is_null($alumno->nombres_acud2) || $alumno->nombres_acud2 == '')){
			
			// Si tiene código y NO tiene nombre escrito, quiere añadirlo como nuevo acudiente de este alumno, NO modificarlo
			DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [ $alumno->id_acud2, $alumno->id, $alumno->parentesco_acud2, $alumno->observaciones_acud2, $now, $now ]);
		
		}else{
			if (!(is_null($alumno->nombres_acud2) || $alumno->nombres_acud2 == '')) {
				DB::insert('INSERT INTO acudientes(nombres, apellidos, sexo, tipo_doc, documento, is_acudiente, telefono, celular, ocupacion, direccion, email, created_at, updated_at, ciudad_doc) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 
					[$alumno->nombres_acud2, $alumno->apellidos_acud2, $alumno->sexo_acud2, $alumno->tipo_docu_acud2_id, $alumno->documento_acud2, ($alumno->is_acudiente2?$alumno->is_acudiente2:1), 
					$alumno->telefono_acud2, $alumno->celular_acud2, $alumno->ocupacion_acud2, $alumno->direccion_acud2, $alumno->email_acud2, $now, $now, $alumno->ciudad_docu_acud2]);
				
				$last_id = DB::getPdo()->lastInsertId();
				DB::insert('INSERT INTO parentescos(acudiente_id, alumno_id, parentesco, observaciones, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$last_id, $alumno->id, $alumno->parentesco_acud2, $alumno->observaciones_acud2, $now, $now ]);
			}
		}
	}


	
}

