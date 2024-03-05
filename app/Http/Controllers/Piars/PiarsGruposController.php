<?php namespace App\Http\Controllers\Piars;

use Request;
use DB;
use App\Http\Controllers\Controller;

use App\User;
use App\Http\Controllers\Piars\Utils\PiarsGrupoUtils;
use App\Http\Controllers\Piars\Utils\PiarsAlumnoUtils;
use Carbon\Carbon;
use App\Models\Profesor;

class PiarsGruposController extends Controller {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
		if(!$this->user->is_superuser && !$this->user->tipo == 'Profesor'){
			return abort(401, 'No puedes cambiar');
		}
	}

	public function getGrupos()
	{
		$user = User::fromToken();

		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo, 
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, gra.nombre as nombre_grado
					FROM grupos g
					INNER JOIN grados gra on gra.id=g.grado_id and g.year_id=:year_id
					LEFT JOIN profesores p on p.id=g.titular_id
					WHERE g.deleted_at is null
					ORDER BY g.orden';

		$grados = DB::select($consulta, [':year_id'=>$user->year_id] );

		return $grados;
	}

	public function getContextoDeGrupo($grupo_id)
	{
		$consulta = 'SELECT pg.id, pg.grupo_id, pg.titular_id, pg.year_id, pg.caracterizacion_grupo, pg.updated_at, pg.updated_by  
			FROM piars_grupos pg
			INNER JOIN grupos gr on gr.id=pg.grupo_id and gr.deleted_at is null
			WHERE pg.grupo_id=?';

		$piars = DB::select($consulta, [$grupo_id]);

    $piarsGrupoUtils = new PiarsGrupoUtils();
    $piarsAlumnosUtils = new PiarsAlumnoUtils();
		$alumnos = $piarsAlumnosUtils->getAlumnosDeGrupo($grupo_id);

		if (count($piars) == 0) {
      $piarsGrupoUtils->createContextoGrupo($grupo_id);
      $piars = DB::select($consulta, [$grupo_id]);
		}

		$alumnos_piar = $piarsAlumnosUtils->getAlumnosPiar($grupo_id, $this->user->user_id);
		
		if ($this->user->is_superuser) {
			$piarsAlumnosUtils->getAcudientes($alumnos_piar);
		} else {
			$piarsAlumnosUtils->acudientes = [];
		}

    return [
			'data' => [
				'familiarContext' => $piars,
				'alumnos' => $alumnos,
				'alumnos_piar' => $alumnos_piar,
			]
		];
	}

	public function putContextoDeGrupo()
	{
		$now = Carbon::now('America/Bogota');

		$id = Request::input('id');
		$caracterizacion_grupo = Request::input('caracterizacion_grupo');
		$updated_at = $now;
		$updated_by = $this->user->user_id;

		$consulta = 'UPDATE piars_grupos 
			SET caracterizacion_grupo=?, updated_at=?, updated_by=?
			WHERE id=?';
		$piars = DB::update($consulta, [
			$caracterizacion_grupo, $updated_at, $updated_by, $id,  
		]);

    return ['piars' => $piars];
	}

	public function putField()
	{
		$now = Carbon::now('America/Bogota');

		$id = Request::input('id');
		$field = Request::input('field');
		$text = Request::input('text');
		$updated_at = $now;
		$updated_by = $this->user->user_id;

		// campos seguros para evitar ataques sql injection
		$validFields = ['contexto_sociofamiliar', 'acta_de_acuerdo'];
		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		$consulta = "UPDATE piars_alumnos 
			SET $field=?, updated_at=?, updated_by=?
			WHERE id=?";
		$piars = DB::update($consulta, [
			$text, $updated_at, $updated_by, $id,  
		]);

    return ['piars' => $piars];
	}
}
