<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;

use Request;
use DB;
use File;
use Image;
use \stdClass;

use App\User;
use App\Models\ImageModel;
use App\Models\Year;
use App\Http\Controllers\Perfiles\Publicaciones;
use \Log;

use Carbon\Carbon;


class CalendarioController extends Controller {

    public function putThisYear(){
        $is_prof_admin = Request::input('is_prof_admin');
        Log::info($is_prof_admin);
        if ($is_prof_admin == 'true') {
            $eventos = DB::select('SELECT * FROM calendario WHERE deleted_at is null');
        }else{
            $eventos = DB::select('SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null');
        }
        return $eventos;
    }
    

	public function putCrearEvento()
	{
        $user   = User::fromToken();
        if (($user->tipo == 'Profesor' ) || $user->is_superuser) {
            $now 	= Carbon::now('America/Bogota');
        
            $title              = Request::input('title');
            $start              = Request::input('start');
            $end                = Request::input('end');
            $allDay             = Request::input('allDay');
            $solo_profes        = Request::input('solo_profes', 0);
            $nombres            = $user->tipo == 'Usuario' ? $user->username : ($user->nombres . ' ' . $user->apellidos);

            
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, end, allDay, solo_profes, created_at, updated_at) 
                        VALUES(:created_by, :created_by_nombres, :title, :start, :end, :allDay, :solo_profes, :created_at, :updated_at)';
            DB::insert($consulta, [
                ':created_by' 	        => $user->user_id, 
                ':created_by_nombres'   => $nombres, 
                ':title'                => $title, 
                ':start'                => $start, 
                ':end'                  => $end, 
                ':allDay'               => $allDay,
                ':solo_profes'          => $solo_profes, 
                ':created_at' 	        => $now,
                ':updated_at' 	        => $now
            ]);
            
            $last_id = DB::getPdo()->lastInsertId();

            return ['evento_id' => $last_id];
        }else{
            return abort(404, 'No tienes permiso');
        }
        
	}



	public function putGuardarEvento()
	{
        $user   = User::fromToken();
        if (($user->tipo == 'Profesor' ) || $user->is_superuser) {
            $now 	= Carbon::now('America/Bogota');
            
            $title              = Request::input('title');
            $start              = null;
            $end                = null;
            $allDay             = Request::input('allDay');
            $solo_profes        = Request::input('solo_profes', 0);
            $nombres            = $user->tipo == 'Usuario' ? $user->username : ($user->nombres . ' ' . $user->apellidos);
            
            if (Request::input('start')) {
                $start = Carbon::parse(Request::input('start'));
            }
            if (Request::input('end')) {
                $end = Carbon::parse(Request::input('end'));
            }
            
            $consulta = 'UPDATE calendario SET updated_by=:updated_by, title=:title, 
                        start=:start, end=:end, allDay=:allDay, solo_profes=:solo_profes, updated_at=:updated_at
                        WHERE id=:id';
            DB::update($consulta, [
                ':updated_by' 	        => $user->user_id, 
                ':title'                => $title, 
                ':start'                => $start, 
                ':end'                  => $end, 
                ':allDay'               => $allDay, 
                ':solo_profes'          => $solo_profes, 
                ':updated_at' 	        => $now,
                ':id'                   => Request::input('id'),
            ]);
            
            return 'Modificado';
        }else{
            return abort(404, 'No tienes permiso');
        }
	}


	public function putEliminarEvento()
	{
        $user   = User::fromToken();
        if (($user->tipo == 'Profesor' ) || $user->is_superuser) {
            $now 	= Carbon::now('America/Bogota');
            
            $consulta = 'UPDATE calendario SET deleted_at=:deleted_at, deleted_by=:deleted_by WHERE id=:id';
            DB::update($consulta, [
                ':deleted_at' 	        => $now, 
                ':deleted_by'           => $user->user_id, 
                ':id' 	                => Request::input('id'), 
            ]);
            return 'Eliminado';
        }else{
            return abort(404, 'No tienes permiso');
        }
    }


	public function putSincronizarCumples()
	{
        $user       = User::fromToken();
        $nombres    = $user->tipo == 'Usuario' ? $user->username : ($user->nombres . ' ' . $user->apellidos);
        
        if (($user->tipo == 'Profesor' ) || $user->is_superuser) {
            $now 	= Carbon::now('America/Bogota');
            
            $consulta = 'DELETE FROM calendario WHERE cumple_alumno_id is not null or cumple_profe_id is not null';
            DB::delete($consulta);
            
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, allDay, cumple_alumno_id, created_at, updated_at)
                SELECT '.$user->user_id.' as created_by, "'.$nombres.'" as created_by_nombres, CONCAT("Cumple ", CONCAT(a.nombres, " ", a.apellidos), "(", g.abrev, ")") as title, 
                    CONCAT(REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), '.$user->year.'), " 05:00:00") as start, 1 as allDay, a.id as cumple_alumno_id, "'.$now.'" as created_at, "'.$now.'" as updated_at
                FROM alumnos a
                INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and a.fecha_nac is not null
                INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id='.$user->year_id.' and g.deleted_at is null';
            
            DB::insert($consulta);
            
            
            $consulta = 'INSERT INTO calendario(created_by, created_by_nombres, title, start, allDay, cumple_profe_id, created_at, updated_at)
                SELECT '.$user->user_id.' as created_by, "'.$nombres.'" as created_by_nombres, CONCAT("Cumple ", CONCAT(a.nombres, " ", a.apellidos), "(docente)") as title, 
                    CONCAT(REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), '.$user->year.'), " 05:00:00") as start, 1 as allDay, a.id as cumple_profe_id, "'.$now.'" as created_at, "'.$now.'" as updated_at
                FROM profesores a
                INNER JOIN contratos c ON c.profesor_id=a.id and c.year_id='.$user->year_id.' and c.deleted_at is null and a.fecha_nac is not null';
            
            DB::insert($consulta);
            
            
            return 'Sincronizados';
            
        }else{
            return abort(404, 'No tienes permiso');
        }
    }


}