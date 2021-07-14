<?php namespace App\Http\Controllers\Tardanzas;


use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Http\Controllers\Controller;

use Request;
use Auth;
use Hash;
use DB;

use App\Models\Debugging;
use App\User;
use App\Models\Ausencia;
use App\Models\Grupo;
use App\Models\Alumno;

use Carbon\Carbon;
use \DateTime;


class AsistenciasController extends Controller {


	public function putDetailed()
	{
		$user               = User::fromToken();
        $now 		        = Carbon::now('America/Bogota');
        $resultado          = [];
        $con_grupos         = Request::input('con_grupos');
        $grupo_id 		    = Request::input('grupo_id');
        
        // Traemos los grupos si los pidieron
        if ($con_grupos) {
            
            $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                    p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
                    g.created_at, g.updated_at, gra.nombre as nombre_grado
                from grupos g
                inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
                left join profesores p on p.id=g.titular_id
                where g.deleted_at is null
                order by g.orden';

            $grados = DB::select($consulta, [':year_id'=>$user->year_id] );
            $resultado['grupos'] = $grados;
        }
        
        // Traemos los alumnos
        if ($grupo_id) {
            
            $alumnos = Grupo::alumnos($grupo_id);
            
            foreach ($alumnos as $alumno) {

                $userData = Alumno::userData($alumno->alumno_id);
                $alumno->userData = $userData;

                // Ausencias
                $cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
                            inner join periodos p on p.id=a.periodo_id and p.id=:per_id
                            WHERE a.tipo='ausencia' and a.entrada=1 and a.alumno_id=:alumno_id and a.deleted_at is null;";
                $ausencias = DB::select($cons_aus, [":per_id" => $user->periodo_id, ':alumno_id' => $alumno->alumno_id ]);
                $alumno->ausencias 			= $ausencias;
                $alumno->ausencias_count 	= count($ausencias);

                
                // Tardanzas
                $cons_tar = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.tipo FROM ausencias a
                            inner join periodos p on p.id=a.periodo_id and p.id=:per_id
                            WHERE a.tipo='tardanza' and a.entrada=1 and a.alumno_id=:alumno_id and a.deleted_at is null;";
                $tardanzas = DB::select($cons_tar, [":per_id" => $user->periodo_id, ':alumno_id' => $alumno->alumno_id ]);
                $alumno->tardanzas 			= $tardanzas;
                $alumno->tardanzas_count 	= count($tardanzas);
                
                $ausencias_total		    = Ausencia::totalDeAlumno($alumno->alumno_id, $user->periodo_id);
                $alumno->ausencias_total    = $ausencias_total;
            }
            
            $resultado['alumnos'] = $alumnos;
        }
        

		return $resultado;

	}

	// /5myvc/public/taxis/all    - Para electron votaciones
	public function getDatosSoloAlumnos()
	{
		$year_id 		    = Request::input('year_id', 4);
        $resultado          = [];
        
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
				g.created_at, g.updated_at, gra.nombre as nombre_grado
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden';

		$grados = DB::select($consulta, [':year_id'=> $year_id] );
		
		for ($i=0; $i < count($grados); $i++) { 
			$grados[$i]->alumnos = Grupo::alumnos($grados[$i]->id);
		}
		
		$resultado['grupos'] = $grados;
        
        

		return $resultado;

	}



	# Sube todos los cambios hechos
	public function postIndex()
	{
		$user       = User::fromToken();
        $now 		= Carbon::now('America/Bogota');
        
		$consulta = 'INSERT INTO ausencias
						(alumno_id, asignatura_id, cantidad_ausencia, cantidad_tardanza, entrada, tipo, fecha_hora, periodo_id, uploaded, created_by, created_at, updated_at)
					VALUES (:alumno_id, :asignatura_id, :cantidad_ausencia, :cantidad_tardanza, :entrada, :tipo, :fecha_hora, :periodo_id, :uploaded, :created_by, :created_at, :updated_at)';
        
        $datos = [
			':alumno_id'			=> Request::input('alumno_id'), 
			':cantidad_ausencia'	=> Request::input('cantidad_ausencia'), 
			':cantidad_tardanza'	=> Request::input('cantidad_tardanza'), 
			':entrada'				=> Request::input('entrada'), 
			':tipo'					=> Request::input('tipo'), 
			':fecha_hora'			=> Request::input('fecha_hora'), 
			':periodo_id'			=> Request::input('periodo_id'),
			':uploaded'				=> 'created',
			':created_by'			=> Request::input('created_by'),
			':created_at'			=> $now,
			':updated_at'			=> $now,
		];

		$ausenc = DB::insert($consulta, $datos);

		$id         = DB::getPdo()->lastInsertId();
        $datos->id  = $id;
        //$ausencia   = Ausencia::findOrFail($id);
        $ausencia   = $datos;

		return $ausencia;

	}




	public function putEliminarAusencia()
	{
		$user = $this->user();

		$id = Request::input('ausencia_id');

		$ausencia 				= Ausencia::findOrFail($id);
		$ausencia->uploaded 	= 'deleted';
		$ausencia->deleted_by 	= $user->id;
		$ausencia->save();
		$ausencia->delete();
		return 'Eliminada';

	}

	# Poner ausencia o tardanza
	public function putPonerAusencia()
	{
		$user = $this->user();

		$dt = Carbon::now('America/Bogota');

		$consulta = 'INSERT INTO ausencias
						(alumno_id, asignatura_id, cantidad_ausencia, cantidad_tardanza, entrada, tipo, fecha_hora, periodo_id, uploaded, created_by, created_at, updated_at)
					VALUES (:alumno_id, :asignatura_id, :cantidad_ausencia, :cantidad_tardanza, :entrada, :tipo, :fecha_hora, :periodo_id, :uploaded, :created_by, :created_at, :updated_at)';


		$ausenc = DB::insert($consulta, [
			':alumno_id'			=> Request::input('alumno_id'), 
			':asignatura_id'		=> Request::input('asignatura_id'),
			':cantidad_ausencia'	=> Request::input('cantidad_ausencia'), 
			':cantidad_tardanza'	=> Request::input('cantidad_tardanza'), 
			':entrada'				=> Request::input('entrada'), 
			':tipo'					=> Request::input('tipo'), 
			':fecha_hora'			=> Request::input('fecha_hora'), 
			':periodo_id'			=> Request::input('periodo_id'),
			':uploaded'				=> 'created',
			':created_by'			=> Request::input('created_by'),
			':created_at'			=> $dt,
			':updated_at'			=> $dt,
		]);

		$id = DB::getPdo()->lastInsertId();

		$ausencia = Ausencia::findOrFail($id);

		return $ausencia;

	}




}