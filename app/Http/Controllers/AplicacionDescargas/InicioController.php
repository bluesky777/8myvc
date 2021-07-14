<?php namespace App\Http\Controllers\AplicacionDescargas;


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


class InicioController extends Controller {


	public function putDetailed()
	{
        $user               = User::fromToken();
        return $user;
        $now 		        = Carbon::now('America/Bogota');
        $con_grupos         = Request::input('year_id');
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
        
        

		return $resultado;

	}




}