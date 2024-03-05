<?php namespace App\Http\Controllers\Piars\Utils;

use Request;
use DB;
use App\Http\Controllers\Controller;

use App\User;
use Carbon\Carbon;

class PiarsAlumnoUtils {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getAlumnosDeGrupo($grupo_id)
	{
		$consulta = 'SELECT a.id, a.nombres, a.apellidos, a.sexo, m.estado,
				a.foto_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
				m.estado, a.nee, a.telefono, a.celular, a.direccion
			FROM alumnos a
			INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
			LEFT JOIN images i on i.id=a.foto_id and i.deleted_at is null
			WHERE a.deleted_at is null and m.grupo_id=?';

		$alumnos = DB::select($consulta, [$grupo_id]);

		return $alumnos;
	}

	public function getAlumnosPiar($grupo_id, $user_id)
	{
		$consulta_piar = 'SELECT pa.id, pa.alumno_id, pa.year_id, pa.contexto_sociofamiliar, pa.acta_de_acuerdo, pa.documento1,
				pa.apoyo_ajustes_razonables, pa.documento2, pa.history, pa.created_at, pa.updated_at, pa.updated_by
			FROM piars_alumnos pa
			INNER JOIN matriculas m ON m.alumno_id=pa.alumno_id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
			INNER JOIN alumnos a ON m.alumno_id=a.id and a.deleted_at is null
			WHERE m.grupo_id=? and a.nee=1';

		$alumnos_piar = DB::select($consulta_piar, [$grupo_id]);


		if (count($alumnos_piar) == 0) {
			$consulta = 'SELECT a.id, a.nombres, a.apellidos, a.sexo, m.estado, a.nee, g.year_id
				FROM alumnos a
				INNER JOIN matriculas m ON m.alumno_id=a.id and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
				INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null
				WHERE a.nee=1 and a.deleted_at is null and m.grupo_id=?';

			$alumnos = DB::select($consulta, [$grupo_id]);

			foreach ($alumnos as $key => $alumno) {
				$consulta_insert = 'INSERT INTO piars_alumnos(alumno_id, year_id, created_at, updated_by)
					VALUES(:alumno_id, :year_id, :created_at, :updated_by)';

				DB::insert($consulta_insert, [
					':alumno_id' => $alumno->id,
					':year_id' => $alumno->year_id,
					':created_at' => $now,
					':updated_by' => $user_id,
				]);
			}
		}

		$alumnos_piar = DB::select($consulta_piar, [$grupo_id]);

		return $alumnos_piar;
	}

	public function getAcudientes($alumnos_piar) {
		for ($i=0; $i < count($alumnos_piar); $i++) {
			$alumnos_piar[$i]->acudientes = [];
			$consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.direccion, 
					ac.sexo, ac.email, ac.celular, ac.telefono, p.parentesco
				FROM acudientes ac
				INNER JOIN parentescos p ON p.acudiente_id=ac.id AND p.deleted_at is null
				WHERE p.alumno_id=? AND ac.deleted_at is null';

			$alumnos_piar[$i]->acudientes = DB::select($consulta, [
				$alumnos_piar[$i]->alumno_id,
			]);
		}
	}
}