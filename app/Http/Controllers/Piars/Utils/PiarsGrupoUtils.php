<?php namespace App\Http\Controllers\Piars\Utils;

use Request;
use DB;
use App\Http\Controllers\Controller;

use App\User;
use Carbon\Carbon;

class PiarsGrupoUtils {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function createContextoGrupo($grupo_id)
	{
    $consulta = 'SELECT g.id, g.titular_id
      FROM grupos g WHERE g.id=?';
    $grupo = DB::select($consulta, [$grupo_id]);

    $user = $this->user;
    $consulta = 'INSERT INTO piars_grupos(
        grupo_id, titular_id, year_id, updated_by
      ) VALUES(?, ?, ?, ?)';
		$data = DB::select($consulta, [
      $grupo_id, $grupo[0]->titular_id, $user->year_id, $user->persona_id,  
    ]);

		return ['data' => $data];
	}
}