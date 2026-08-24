<?php namespace App\Http\Controllers\Tardanzas;


use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

use App\Models\Debugging;
use App\User;
use App\Models\Ausencia;
use App\Services\Auditoria;
use App\Models\Grupo;
use App\Models\Alumno;

use Carbon\Carbon;
use \DateTime;
use App\Support\NombreDelAlumno;


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
                $cons_aus = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.created_at, a.tipo 
							FROM ausencias a
                            inner join periodos p on p.id=a.periodo_id and p.id=:per_id
                            WHERE a.tipo='ausencia' and a.entrada=1 and a.alumno_id=:alumno_id and a.deleted_at is null;";
                $ausencias = DB::select($cons_aus, [":per_id" => $user->periodo_id, ':alumno_id' => $alumno->alumno_id ]);
                $alumno->ausencias 			= $ausencias;
                $alumno->ausencias_count 	= count($ausencias);

                
                // Tardanzas
                $cons_tar = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.created_at, a.tipo 
							FROM ausencias a
                            inner join periodos p on p.id=a.periodo_id and p.id=:per_id
                            WHERE a.tipo='tardanza' and a.entrada=1 and a.alumno_id=:alumno_id and a.deleted_at is null;";
                $tardanzas = DB::select($cons_tar, [":per_id" => $user->periodo_id, ':alumno_id' => $alumno->alumno_id ]);
                $alumno->tardanzas 			= $tardanzas;
                $alumno->tardanzas_count 	= count($tardanzas);


				// Ausencias a clase
				$cons_aus_clase = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza, 
										a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.created_at, a.tipo,
										m.materia, m.alias, asg.orden as asignatura_orden
									FROM ausencias a
									inner join periodos p on p.id=a.periodo_id and p.id=:per_id
									inner join asignaturas asg on asg.id=a.asignatura_id and asg.deleted_at is null
									inner join materias m on m.id=asg.materia_id and m.deleted_at is null
									WHERE a.tipo='ausencia' and a.entrada=0 and a.alumno_id=:alumno_id and a.deleted_at is null;";
				$ausencias_clase = DB::select($cons_aus_clase, [":per_id" => $user->periodo_id, ':alumno_id' => $alumno->alumno_id ]);
				$alumno->ausencias_clase 		= $ausencias_clase;
				$alumno->ausencias_clase_count 	= count($ausencias_clase);

				// Tardanzas a clase
				$cons_tar_clase = "SELECT  a.id, a.asignatura_id, a.alumno_id, a.periodo_id, a.cantidad_ausencia, a.cantidad_tardanza,
										a.entrada, a.fecha_hora, a.uploaded, a.created_by, a.created_at, a.tipo,
										m.materia, m.alias, asg.orden as asignatura_orden
									FROM ausencias a
									inner join periodos p on p.id=a.periodo_id and p.id=:per_id
									inner join asignaturas asg on asg.id=a.asignatura_id and asg.deleted_at is null
									inner join materias m on m.id=asg.materia_id and m.deleted_at is null
									WHERE a.tipo='tardanza' and a.entrada=0 and a.alumno_id=:alumno_id and a.deleted_at is null;";
				$tardanzas_clase = DB::select($cons_tar_clase, [":per_id" => $user->periodo_id, ':alumno_id' => $alumno->alumno_id ]);
				$alumno->tardanzas_clase 		= $tardanzas_clase;
				$alumno->tardanzas_clase_count 	= count($tardanzas_clase);

                
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
			':created_by'			=> $user->user_id,
			':created_at'			=> $now,
			':updated_at'			=> $now,
		];

		/*
		 * **Aquí no va la llamada a `Auditoria`, y la ausencia es la decisión.**
		 *
		 * Este método no escribe: el INSERT declara `:asignatura_id` y el array de
		 * valores no lo trae, así que la consulta revienta antes de tocar la
		 * tabla; y si llegara a pasar, la línea de abajo hace `$datos->id` sobre un
		 * **array**, que en PHP 8 es un Error. Está medido y enrutado en
		 * [05 §](../../../../docs/migracion/05-codigo-muerto-y-roto.md) — con ruta
		 * y roto se documenta, no se borra.
		 *
		 * Instrumentarlo escribiría una línea de auditoría en un camino por el que
		 * no pasa nadie, y la fase 5 lo enseñaría como una falta que se puso. El
		 * día que se decida qué es `asignatura_id` en una asistencia, la llamada va
		 * aquí y es la misma que la de `putPonerAusencia`.
		 */
		$ausenc = DB::insert($consulta, $datos);

		$id         = DB::getPdo()->lastInsertId();
        $datos->id  = $id;
        //$ausencia   = Ausencia::findOrFail($id);
        $ausencia   = $datos;

		return $ausencia;

	}




	public function putEliminarAusencia()
	{
		$user = User::fromToken();

		$id = Request::input('ausencia_id');

		$ausencia 				= Ausencia::findOrFail($id);
		$ausencia->uploaded 	= 'deleted';
		$ausencia->deleted_by 	= $user->user_id;
		$ausencia->save();

		// **Antes** del `delete()` y con `de(...)`: la línea guarda lo que la falta
		// ERA, que es lo que el colegio pregunta cuando alguien reclama.
		//
		// Y ésta es la pregunta que ningún detector de escrituras encuentra: no es
		// *«quién escribe aquí»* sino ***«quién puede quitar de aquí»***. Borrar una
		// falta lo puede hacer cualquiera del personal —decidido el 22 ago 2026, y
		// a propósito—, así que el rastro es lo único que queda; `deleted_by` dice
		// quién y no dice qué.
		$alumnoDeLaLinea = $ausencia->alumno_id === null ? null : (int) $ausencia->alumno_id;

		Auditoria::registrar()
			->borrar('ausencia', (int) $ausencia->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: $ausencia->asignatura_id === null ? null : (int) $ausencia->asignatura_id,
				periodo: $ausencia->periodo_id === null ? null : (int) $ausencia->periodo_id)
			->de([
				'tipo' => $ausencia->tipo,
				'fecha_hora' => (string) $ausencia->fecha_hora,
				'cantidad_ausencia' => $ausencia->cantidad_ausencia,
				'cantidad_tardanza' => $ausencia->cantidad_tardanza,
			])
			->guardar();

		$ausencia->delete();

		return 'Eliminada';

	}

	# Poner ausencia o tardanza
	public function putPonerAusencia()
	{
		$user = User::fromToken();

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
			':created_by'			=> $user->user_id,
			':created_at'			=> $dt,
			':updated_at'			=> $dt,
		]);

		$id = DB::getPdo()->lastInsertId();

		$ausencia = Ausencia::findOrFail($id);

		// El rastro de la falta, que hasta hoy no dejaba ninguno (18 §4, fase 4).
		//
		// Va **después** del `findOrFail`, y eso hace que la línea guarde lo que
		// quedó ESCRITO en la fila y no lo que venía en el cuerpo. No es lo mismo:
		// `tipo` y las dos cantidades entran a pelo desde la petición, sin
		// validación —hay 2 validaciones en todo el proyecto—, y la columna las
		// convierte en silencio. Auditar el cuerpo contaría lo que se pidió; esto
		// cuenta lo que pasó.
		$alumnoDeLaLinea = $ausencia->alumno_id === null ? null : (int) $ausencia->alumno_id;

		Auditoria::registrar()
			->crear('ausencia', (int) $ausencia->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: $ausencia->asignatura_id === null ? null : (int) $ausencia->asignatura_id,
				periodo: $ausencia->periodo_id === null ? null : (int) $ausencia->periodo_id)
			->a([
				'tipo' => $ausencia->tipo,
				'fecha_hora' => (string) $ausencia->fecha_hora,
				'cantidad_ausencia' => $ausencia->cantidad_ausencia,
				'cantidad_tardanza' => $ausencia->cantidad_tardanza,
			])
			->guardar();

		return $ausencia;

	}




}