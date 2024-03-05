<?php namespace App\Http\Controllers\Piars\Utils;

use Request;
use DB;
use App\Http\Controllers\Controller;

use App\User;
use Carbon\Carbon;

class PiarsAsignaturasUtils {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getCreatePiarAsignatura($asignatura_id, $alumno_id)
	{
    $now = Carbon::now('America/Bogota');

		$consulta = 'SELECT a.id, a.asignatura_id, a.alumno_id, a.year,
				a.apoyo_razonable, a.seguimientos, a.created_at, a.updated_at, a.updated_by
			FROM piars_asignaturas a
			WHERE a.asignatura_id=? and alumno_id=?';

		$asignatura = DB::select($consulta, [$asignatura_id, $alumno_id]);

    if (count($asignatura) == 0) {
      $consulta_create = 'INSERT INTO piars_asignaturas(
          asignatura_id, alumno_id, year,
          created_at, updated_at, updated_by)
        VALUES(:asignatura_id, :alumno_id, :year,
          :created_at, :updated_at, :updated_by)';

      DB::insert($consulta_create, [
        ':asignatura_id' => $asignatura_id,
        ':alumno_id' => $alumno_id,
        ':year' => $this->user->year,
        ':created_at' => $now,
        ':updated_at' => $now,
        ':updated_by' => $this->user->user_id,
      ]);

      $asignatura = DB::select($consulta, [$asignatura_id, $alumno_id]);
    }

		return $asignatura;
	}
}