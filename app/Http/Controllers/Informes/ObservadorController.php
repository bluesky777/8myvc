<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Request;
use DB;
use Excel;
use View;

use App\User;
use App\Models\Year;
use App\Models\Periodo;
use App\Models\Matricula;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;
use \Log;


class ObservadorController extends Controller {

	public function getIndex()
	{
        return 'Holaa';


    }


	public function getVertical($grupo_id, $tamanio)
	{
        $user       = User::fromToken();
        $year	    = Year::datos($user->year_id, false);
        
        $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                        p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                        g.created_at, g.updated_at, gra.nombre as nombre_grado 
                    from grupos g
                    inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                    left join profesores p on p.id=g.titular_id
                    where g.deleted_at is null AND g.id=:grupo_id
                    order by g.orden';

        $grupos = DB::select($consulta, [':year_id'=> $year->year_id, ':grupo_id' => $grupo_id ] );
        
        Log::info($year->year_id);
        for ($i=0; $i < count($grupos); $i++) { 
            
            $consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
            $alumnos    = DB::select($consulta, [ ':grupo_id' => $grupos[$i]->id ] );
                
            for ($j=0; $j < count($alumnos); $j++) { 
                $consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.telefono, pa.parentesco, pa.id as parentesco_id, ac.user_id, 
                                ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
                                ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
                                u.username, u.is_active
                            FROM parentescos pa
                            left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
                            left join users u on ac.user_id=u.id and u.deleted_at is null
                            left join images i on i.id=ac.foto_id and i.deleted_at is null
                            WHERE pa.alumno_id=? and pa.deleted_at is null';
                            
                $acudientes    = DB::select($consulta, [ $alumnos[$j]->alumno_id ] );
                $alumnos[$j]->acudientes = $acudientes;
            }
            
            $grupos[$i]->alumnos = $alumnos;

        }

        
        if ($tamanio == 'carta') {
            $filas      = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26];
        }else{
            $filas      = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33];
        }
        
        $dns        = url('/') . '/images/perfil/';
        $periodos   = Periodo::hastaPeriodoN($year->year_id);

        
        if ($tamanio == 'carta') {
            $filasPer   = [1,2,3,4,5];
        }else{
            $filasPer   = [1,2,3,4,5,6];
        }
        
        $html = View::make('observador', compact('grupos', 'year', 'filas', 'dns', 'periodos', 'filasPer'))->render();

        return $html;

    }


	public function getVerticalTodos()
	{
        $user   = User::fromToken();
        $year	= Year::datos($user->year_id);
        
        $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                        p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                        g.created_at, g.updated_at, gra.nombre as nombre_grado 
                    from grupos g
                    inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                    left join profesores p on p.id=g.titular_id
                    where g.deleted_at is null
                    order by g.orden';

        $grupos = DB::select($consulta, [':year_id'=> $year->year_id] );
    
        for ($i=0; $i < count($grupos); $i++) { 
            
            $consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
            $alumnos    = DB::select($consulta, [ ':grupo_id' => $grupos[$i]->id ] );
                
            for ($j=0; $j < count($alumnos); $j++) { 
                $consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.telefono, pa.parentesco, pa.id as parentesco_id, ac.user_id, 
                                ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
                                ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
                                u.username, u.is_active
                            FROM parentescos pa
                            left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
                            left join users u on ac.user_id=u.id and u.deleted_at is null
                            left join images i on i.id=ac.foto_id and i.deleted_at is null
                            WHERE pa.alumno_id=? and pa.deleted_at is null';
                            
                $acudientes    = DB::select($consulta, [ $alumnos[$j]->alumno_id ] );
                $alumnos[$j]->acudientes = $acudientes;
            }
            
            $grupos[$i]->alumnos = $alumnos;

        }
        
        
        if ($tamanio == 'carta') {
            $filas      = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26];
        }else{
            $filas      = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33];
        }
        
        $dns        = url('/') . '/images/perfil/';
        $periodos   = Periodo::hastaPeriodoN($year->year_id);
        
        if ($tamanio == 'carta') {
            $filasPer   = [1,2,3,4,5];
        }else{
            $filasPer   = [1,2,3,4,5,6];
        }
        
        $html = View::make('observador', compact('grupos', 'year', 'filas', 'dns', 'periodos', 'filasPer'))->render();

        return $html;

    }


}