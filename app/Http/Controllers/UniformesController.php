<?php namespace App\Http\Controllers;



use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\User;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Year;
use \Log;

use Carbon\Carbon;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\PeriodoDeLaFila;



class UniformesController extends Controller {
	use ResuelveElUsuario;

	public function putAgregar()
	{
        
        // El periodo de la fila que se va a escribir es el mismo que usa la
        // escritura de más abajo. Antes se comprobaba el que nombrara
        // `num_periodo`, y por eso pedir `num_periodo=1` con el periodo 1 abierto
        // dejaba escribir en el periodo del profesor, que seguía cerrado. §27.
        User::pueden_editar_notas($this->user, (int) Request::input('periodo_id', $this->user->periodo_id));
        
        $now 			= Carbon::now('America/Bogota');
        $asignatura_id  = Request::input('asignatura_id');
        $materia        = Request::input('materia');
        $alumno_id      = Request::input('alumno_id');
        $periodo_id     = Request::input('periodo_id', $this->user->periodo_id);
        $contrario      = Request::input('contrario', 0);
        $sin_uniforme   = Request::input('sin_uniforme', 0);
        $incompleto     = Request::input('incompleto', 0);
        $cabello        = Request::input('cabello', 0);
        $accesorios     = Request::input('accesorios', 0);
        $camara          = Request::input('camara', 0);
        $otro1          = Request::input('otro1', 0);
        $excusado       = Request::input('excusado', 0);
        $descripcion    = Request::input('descripcion');
        $fecha_hora     = Request::input('fecha_hora');
        $created_by     = $this->user->user_id;

        $consulta = 'INSERT INTO uniformes (asignatura_id, materia, alumno_id, periodo_id, contrario, sin_uniforme, 
            incompleto, cabello, accesorios, otro1, camara, excusado, descripcion, fecha_hora, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

        DB::insert($consulta, [
            $asignatura_id, $materia, $alumno_id, $periodo_id, $contrario, $sin_uniforme, 
            $incompleto, $cabello, $accesorios, $otro1, $camara, $excusado, $descripcion, $fecha_hora, $created_by, $now, $now
        ]);

        $last_id 	    = DB::getPdo()->lastInsertId();
        $cons_uni = "SELECT u.id, u.asignatura_id, u.materia, u.alumno_id, u.periodo_id, u.contrario, u.sin_uniforme, u.incompleto, u.cabello, u.accesorios, u.otro1, u.camara, u.excusado, u.fecha_hora, u.uploaded, u.created_by, u.descripcion 
					FROM uniformes u 
					WHERE u.id=:id;";
		$uniforme = DB::select($cons_uni, [":id" => $last_id ]);
			
        if (count($uniforme) > 0) {
            $uniforme = $uniforme[0];
        }

        return ['uniforme' => $uniforme];
    }
    


    // No la estoy usando actualmente
	public function putGuardarCambios()
	{
		
        // Sin derivar, y a propósito: esta ruta está rota desde antes de la
        // migración —cuatro variables sin definir, con `// No la estoy usando
        // actualmente` encima— y su UPDATE apunta a `$user_id`, que no existe.
        // No hay fila de la que sacar el periodo porque no hay fila. §6.5 y §27.2.
        User::pueden_editar_notas($this->user);
        $now 			= Carbon::now('America/Bogota');
        
		if ($propiedad == 'fecha_hora' )
            $valor = Carbon::parse($valor);
        
        $consulta 	= 'UPDATE uniformes SET '.$propiedad.'=:valor, updated_by=:modificador, updated_at=:fecha WHERE id=:user_id';
		$datos 		= [ ':valor' => $valor, ':modificador' => $user->user_id, ':fecha' => $now, ':user_id' => $user_id ];
        
        $res        = DB::update($consulta, $datos);

		return $res;
	}


	public function putActualizar()
	{
		
        User::pueden_editar_notas($this->user, PeriodoDeLaFila::deUniforme(Request::input('id')));
        $now 			= Carbon::now('America/Bogota');
        $unifor_id      = Request::input('id');
        $contrario      = Request::input('contrario');
        $sin_uniforme   = Request::input('sin_uniforme');
        $incompleto     = Request::input('incompleto');
        $cabello        = Request::input('cabello');
        $accesorios     = Request::input('accesorios');
        $camara         = Request::input('camara');
        $otro1          = Request::input('otro1');
        $excusado       = Request::input('excusado');
        $descripcion    = Request::input('descripcion');
        $fecha_hora     = Carbon::parse(Request::input('fecha_hora'));
        $updated_by     = $this->user->user_id;
        
        
        $consulta 	= 'UPDATE uniformes SET contrario=?, sin_uniforme=?, incompleto=?, cabello=?, 
            accesorios=?, otro1=?, camara=?, excusado=?, descripcion=?, fecha_hora=?, updated_by=?, updated_at=?
            WHERE id=?';
        $datos 		= [ $contrario, $sin_uniforme, $incompleto, $cabello, $accesorios, 
            $otro1, $camara, $excusado, $descripcion, $fecha_hora, $updated_by, $now, $unifor_id ];
        
        $res        = DB::update($consulta, $datos);

		return $res;
	}


	public function putEliminar()
	{
		
        User::pueden_editar_notas($this->user, PeriodoDeLaFila::deUniforme(Request::input('uniforme_id')));
        $now 			= Carbon::now('America/Bogota');
        
        $consulta 	= 'UPDATE uniformes SET deleted_at=:deleted_at WHERE id=:uniforme_id';
		$datos 		= [ ':deleted_at' => $now, ':uniforme_id' => Request::input('uniforme_id') ];
        
        $res        = DB::update($consulta, $datos);


        // Uniformes
        $cons_uni = "SELECT u.id, u.asignatura_id, u.materia, u.alumno_id, u.periodo_id, u.contrario, u.sin_uniforme, u.incompleto, u.cabello, u.accesorios, u.otro1, u.camara, u.excusado, u.fecha_hora, u.uploaded, u.created_by, u.descripcion 
            FROM uniformes u
            inner join periodos p on p.id=u.periodo_id and p.id=:per_id
            WHERE u.asignatura_id=:asignatura_id and u.alumno_id=:alumno_id and u.deleted_at is null;";
        $uniformes = DB::select($cons_uni, [":per_id" => $this->user->periodo_id, ':asignatura_id' => Request::input('asignatura_id'), ':alumno_id' => Request::input('alumno_id') ]);
        
        $uniformes_count 	= count($uniformes);

		return ['uniformes' => $uniformes, 'uniformes_count' => $uniformes_count];
	}




}