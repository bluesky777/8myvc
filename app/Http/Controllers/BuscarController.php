<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Matricula;


class BuscarController extends Controller {

	private $consulta_ini = "SELECT a.no_matricula, a.id as alumno_id, a.nombres, a.apellidos, a.sexo, a.user_id, a.created_by, a.updated_by, a.deleted_by, a.deleted_at, a.created_at, a.updated_at,
					a.foto_id, IFNULL(i.nombre, IF(a.sexo='F','default_female.png', 'default_male.png')) as foto_nombre,
					m.estado, m.nombre as nombre_grupo, m.abrev as abrev_grupo 
					FROM alumnos a
					left join images i on i.id=a.foto_id and i.deleted_at is null
					left join 
						(SELECT mt.estado, mt.alumno_id, g.* FROM matriculas mt 
						inner join grupos g on g.id=mt.grupo_id and g.deleted_at is null and g.year_id=? and mt.deleted_at is null
						)m on m.alumno_id=a.id ";


	public function putPorNombre()
	{
		$user 			= User::fromToken();
		$texto_a_buscar = Request::input('texto_a_buscar');
		
		if (!$texto_a_buscar) {
			return abort(400, 'Texto Inválido');
		}
		
		$consulta = $this->consulta_ini . " WHERE a.nombres like '%$texto_a_buscar%'";

		$res = DB::select(DB::raw($consulta), [$user->year_id]);
		return $res;
	}



	public function putPorApellido()
	{
		$user 			= User::fromToken();
		$texto_a_buscar = Request::input('texto_a_buscar');
		
		if (!$texto_a_buscar) {
			return 'Texto Inválido';
		}
		
		$consulta = $this->consulta_ini . " WHERE a.apellidos like '%$texto_a_buscar%'";

		$res = DB::select(DB::raw($consulta), [$user->year_id]);
		return $res;
	}




}