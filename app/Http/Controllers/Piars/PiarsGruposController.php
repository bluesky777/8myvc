<?php namespace App\Http\Controllers\Piars;

use Request;
use DB;
use App\Http\Controllers\Controller;

use App\User;
use App\Http\Controllers\Piars\Utils\PiarsGrupoUtils;
use Carbon\Carbon;

class PiarsGruposController extends Controller {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getContextoDeGrupo($grupo_id)
	{
		$consulta = 'SELECT pg.id, pg.grupo_id, pg.titular_id, pg.year_id, pg.caracterizacion_grupo, pg.updated_at, pg.updated_by  
			FROM piars_grupos pg
			INNER JOIN grupos gr on gr.id=pg.grupo_id and gr.deleted_at is null
			WHERE pg.grupo_id=?';

		$data = DB::select($consulta, [$grupo_id]);

		if (count($data) == 0) {
      $piarsGrupoUtils = new PiarsGrupoUtils();
      $piarsGrupoUtils->createContextoGrupo($grupo_id);
      $data = DB::select($consulta, [$grupo_id]);
			return ['data' => $data];
		}
    return ['no_data' => []];
	}
}