<?php namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use App\Models\Matricula;
use App\Models\Acudiente;
use Carbon\Carbon;

use App\Events\MatriculasEvent;
use \Log;


class RequisitosController extends Controller {


	public $user;
	
	public function __construct()
	{
		$this->user = User::fromToken();
		if( ! $this->user->is_superuser){
			return 'No tienes permiso';
		}
	}
	

	public function putIndex()
	{
        
        $consulta   = 'SELECT id, year, actual, abrev_colegio FROM years WHERE deleted_at is null ORDER BY year desc';
        $years      = DB::select($consulta);
        
        for ($i=0; $i < count($years); $i++) { 
           
            $consulta = 'SELECT * FROM requisitos_matricula WHERE year_id=? and deleted_at is null';
            $years[$i]->requisitos = DB::select($consulta, [$years[$i]->id]);
        }
        
        return $years;
	}
	

	public function postStore()
	{
        $requ       = Request::input('requisito');
        $descrip    = Request::input('descripcion');
        $year_id    = Request::input('year_id');
        $now 		= Carbon::now('America/Bogota');
        
        $consulta = 'INSERT INTO requisitos_matricula(requisito, descripcion, updated_by, created_at, updated_at, year_id) 
            VALUES(?,?,?,?,?,?)';
        DB::insert($consulta, [$requ, $descrip, $this->user->user_id, $now, $now, $year_id]);
        
        $consulta = 'SELECT * FROM requisitos_matricula WHERE id=?';
        $requisito = DB::select($consulta, [ DB::getPdo()->lastInsertId() ] )[0];
        
        return ['requisito' => $requisito];
	}
	


	public function putUpdate()
	{
		$id         = Request::input('id');
		$requ       = Request::input('requisito');
		$descrip    = Request::input('descripcion');
		$now 		= Carbon::now('America/Bogota');
		
		$consulta = 'UPDATE requisitos_matricula SET requisito=?, descripcion=?, updated_by=?, updated_at=? WHERE id=?';
		DB::select($consulta, [$requ, $descrip, $this->user->user_id, $now, $id]);
		
		return 'Actualizado';
	}
		



	public function postAlumno()
	{
		$id         = Request::input('requisito_alumno_id');
		$estado     = Request::input('estado');
		$descrip    = Request::input('descripcion');
		$now 		= Carbon::now('America/Bogota');
		
		$consulta = 'UPDATE requisitos_alumno
		 SET estado=?, descripcion=?, updated_by=?, updated_at=? WHERE id=?';
		DB::select($consulta, [ $estado, $descrip, $this->user->user_id, $now, $id ]);
		
		return 'Actualizado';
	}
		


	public function putListadoObservaciones()
	{
		$now 		= Carbon::now('America/Bogota');
		$year_id 	= Request::input('year_id', $this->user->year_id);
		
		
		$consulta 	= 'SELECT * FROM requisitos_matricula WHERE year_id=? and deleted_at is null';
		$requisitos = DB::select($consulta, [$year_id]);
		
		
		for ($i=0; $i < count($requisitos); $i++) { 
			
			$consulta 	= 'SELECT distinct(o.descripcion) as descripcion FROM requisitos_alumno o WHERE o.requisito_id=? and o.descripcion is not null and o.descripcion!=""';
			$requisitos[$i]->requisitos_alumnos = DB::select($consulta, [ $requisitos[$i]->id ]);
		
			
			$consulta 	= 'SELECT a.nombres, o.alumno_id, a.apellidos, a.celular, g.abrev as abrev_grupo, o.descripcion, o.id as requisito_alumno_id 
				FROM requisitos_alumno o
				INNER JOIN requisitos_matricula r ON r.id=o.requisito_id and r.deleted_at is null
				INNER JOIN alumnos a ON a.id=o.alumno_id and a.deleted_at is null
				INNER JOIN matriculas m ON a.id=m.alumno_id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null
				INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and m.deleted_at is null
				WHERE r.id=? and o.descripcion is not null and o.descripcion!="" 
				ORDER BY g.abrev, a.apellidos';
				
			$requisitos[$i]->alumnos_observaciones = DB::select($consulta, [ $year_id, $requisitos[$i]->id ]);
		
			
		}
		
		
		return [ 'requisitos' => $requisitos ];
		
	}
		

	public function deleteDestroy($id)
		{
		$now 		= Carbon::now('America/Bogota');
		$consulta   = 'UPDATE requisitos_matricula SET deleted_at=? WHERE id=?';
				DB::update($consulta, [$now, $id]);

		return 'Eliminado';
	}







}