<?php namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Controller;

use Request;
use DB;

use App\User;
use Carbon\Carbon;

use \Log;


class EnfermeriaController extends Controller {


	public $user;
	
	public function __construct()
	{
		$this->user = User::fromToken();
	}
	

	public function putDatos()
	{
		$now 				= Carbon::now('America/Bogota');
		
        $consulta          = 'SELECT * FROM antecedentes WHERE alumno_id=?';
        $antecedentes      = DB::select($consulta, [Request::input('alumno_id')]);
		
		if (count($antecedentes) == 0) {
			$consulta          = 'INSERT INTO antecedentes(alumno_id, updated_by, created_at, updated_at) VALUES(?,?,?,?)';
			$antecedentes      = DB::select($consulta, [Request::input('alumno_id'), $this->user->user_id, $now, $now ]);
			
			$consulta          = 'SELECT * FROM antecedentes WHERE alumno_id=?';
			$antecedentes      = DB::select($consulta, [Request::input('alumno_id')]);
			
		}
		
		
        $consulta = 'SELECT r.*, u.username as created_by_name, u2.username as updated_by_name FROM registros_enfermeria r
			LEFT JOIN users u ON u.id=r.created_by and u.deleted_at is null
			LEFT JOIN users u2 ON u2.id=r.updated_by and u2.deleted_at is null
			WHERE alumno_id=?';
			
        $registros_enfermeria 		= DB::select($consulta, [Request::input('alumno_id')]);
		
        
        return [ 'antecedentes'=>$antecedentes[0], 'registros_enfermeria'=>$registros_enfermeria ];
	}
	


	public function putGuardarValor()
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Enfermero'){
			$now 				= Carbon::now('America/Bogota');
			$propiedad 			= Request::input('propiedad');
			
			$consulta          = 'UPDATE antecedentes SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:antec_id';
			$antecedentes      = DB::select($consulta, [':valor'=>Request::input('valor'), ':modificador'=>$this->user->user_id, ':fecha'=>$now, ':antec_id'=>Request::input('antec_id')]);
				

			return 'Cambios guardados';
		}else{
			return abort(401, 'No puedes cambiar');
		}
			
	}
	

	public function postCrearSuceso()
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Usuario'){
			$now 				= Carbon::now('America/Bogota');
			$fecha_creacion 	= Carbon::parse(Request::input('fecha_suceso'));
			
			$consulta          = 'INSERT INTO registros_enfermeria
				(alumno_id, fecha_suceso, signo_fc, signo_fr, signo_t, signo_glu, signo_spo2, signo_pa_dia, signo_pa_sis, asignatura, motivo_consulta, descripcion_suceso, created_by, created_at, updated_at) 
				VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
			$antecedentes      = DB::select($consulta, [ Request::input('alumno_id'), Request::input('fecha_suceso'), Request::input('signo_fc'), 
				Request::input('signo_fr'), Request::input('signo_t'), Request::input('signo_glu'), Request::input('signo_spo2'), 
				Request::input('signo_pa_dia'), Request::input('signo_pa_sis'), Request::input('asignatura'), Request::input('motivo_consulta'), Request::input('descripcion_suceso'), $this->user->user_id, $now, $now ]);
				
			$last_id 	    = DB::getPdo()->lastInsertId();

			
			$consulta          = 'SELECT * FROM registros_enfermeria WHERE id=?';
			$registro_enfermeria      = DB::select($consulta, [ $last_id]);
				
			return (array)$registro_enfermeria[0];
		}else{
			return abort(401, 'No puedes cambiar');
		}
			
	}
	

	public function putGuardarValorSuceso()
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Usuario'){
			$now 				= Carbon::now('America/Bogota');
			$propiedad 			= Request::input('propiedad');
			
			$consulta          = 'UPDATE registros_enfermeria SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:suceso_id';
			$antecedentes      = DB::select($consulta, [':valor'=>Request::input('valor'), ':modificador'=>$this->user->user_id, ':fecha'=>$now, ':suceso_id'=>Request::input('suceso_id')]);
				

			return 'Cambios guardados';
		}else{
			return abort(401, 'No puedes cambiar');
		}
			
	}
	

	public function deleteDestroy($id)
	{
		// Debo verificar que tenga rol Enfermero. Por ahora lo dejo Usuario para que funcione
		if($this->user->is_superuser || $this->user->tipo == 'Usuario'){
			$now 				= Carbon::now('America/Bogota');
			
			$consulta          = 'DELETE FROM registros_enfermeria WHERE id=?';
			DB::delete($consulta, [ $id ]);
				
			return 'Eliminado';
		}else{
			return abort(401, 'No puedes eliminar');
		}
			
	}
	





}