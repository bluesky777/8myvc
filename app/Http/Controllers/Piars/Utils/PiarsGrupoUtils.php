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
		$data = DB::insert($consulta, [
      $grupo_id, $grupo[0]->titular_id, $user->year_id, $user->persona_id,  
    ]);

		return ['data' => $data];
	}

	public function getGrupos($user) {
		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id, g.cupo, 
						p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo, g.caritas, 
						g.created_at, g.updated_at, gra.nombre as nombre_grado
					from grupos g
					inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
					left join profesores p on p.id=g.titular_id
					where g.deleted_at is null
					order by g.orden';

		$grados = DB::select($consulta, [':year_id'=>$user->year_id] );
		return $grados;
	}
}