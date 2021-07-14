<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\NotaFinal;
use Carbon\Carbon;


class SubunidadesController extends Controller {

	

	public function postIndex()
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		User::pueden_editar_notas($user);

		$cant = Subunidad::where('unidad_id', Request::input('unidad_id'))->count();

		$subunidad = new Subunidad;

		$nota_def = Request::input('nota_default');
		
		if (!$nota_def or $nota_def =='' or $nota_def < 0) {
			$nota_def = 0;
		}

		$subunidad->definicion		= Request::input('definicion');
		$subunidad->porcentaje		= Request::input('porcentaje');
		$subunidad->orden			= Request::input('orden', 0);
		$subunidad->unidad_id		= Request::input('unidad_id');
		$subunidad->nota_default	= $nota_def;
		$subunidad->orden			= $cant;
		$subunidad->created_by		= $user->user_id;

		$subunidad->save();


		$consulta 	= 'SELECT id as history_id FROM historiales where user_id=? and deleted_at is null order by id desc limit 1';

		$histo 		= DB::select($consulta, [$user->user_id])[0];

		$bit_by 	= $user->user_id;
		$bit_hist 	= $histo->history_id;
		$bit_new 	= $subunidad->definicion . ' -- ' . $subunidad->porcentaje . '%'; 	// Guardo la nota nueva
		$bit_per 	= $user->periodo_id;

		$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id, affected_element_new_value_string, created_at) 
					VALUES (?, ?, "Nueva subunidad", ?, ?, ?)';

		DB::insert($consulta, [$bit_by, $bit_hist, $subunidad->id, $bit_new, $now]);
		

		return $subunidad;
	}





	
	public function putUpdateOrden()
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user);

		$sortHash = Request::input('sortHash');

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){

				$subunidad 			= Subunidad::find((int)$key);
				$subunidad->orden 	= (int)$value;
				$subunidad->save();

			}
		}

		return 'Ordenado correctamente';
	}



	
	public function putUpdateOrdenVarias()
	{
		$user = User::fromToken();
		User::pueden_editar_notas();

		$sortHash1 	= Request::input('sortHash1');
		$sortHash2 	= Request::input('sortHash2');
		$unidad1_id = Request::input('unidad1_id');
		$unidad2_id = Request::input('unidad2_id');

		for($row = 0; $row < count($sortHash1); $row++){
			foreach($sortHash1[$row] as $key => $value){

				$subunidad 				= Subunidad::find((int)$key);
				$subunidad->orden 		= (int)$value;
				$subunidad->unidad_id 	= (int)$unidad1_id;
				$subunidad->save();

			}
		}

		for($row = 0; $row < count($sortHash2); $row++){
			foreach($sortHash2[$row] as $key => $value){

				$subunidad 				= Subunidad::find((int)$key);
				$subunidad->orden 		= (int)$value;
				$subunidad->unidad_id 	= (int)$unidad2_id;
				$subunidad->save();

			}
		}

		return 'Ordenado correctamente';
	}






	public function putUpdate($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user);

		$subunidad = Subunidad::findOrFail($id);

		$nota_def = Request::input('nota_default');

		if (!$nota_def or $nota_def =='' or $nota_def < 0) {
			$nota_def = 0;
		}

		$subunidad->definicion		= Request::input('definicion');
		$subunidad->porcentaje		= Request::input('porcentaje');
		$subunidad->nota_default	= $nota_def;
		$subunidad->updated_by		= $user->user_id;

		if ( Request::has('orden') ) {
			$subunidad->orden	= Request::input('orden');
		}
		
		$subunidad->save();
		
		
		if (Request::input('asignatura_id')) {
			$asignatura_id 	= Request::input('asignatura_id');
			$periodo_id 	= Request::input('periodo_id');
			$num_periodo 	= Request::input('num_periodo');
			
			NotaFinal::calcularAsignaturaPeriodo($asignatura_id, $periodo_id, $num_periodo);

		}

		return $subunidad;
	}




	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		$subunidad = Subunidad::find($id);

		if ($subunidad) {
			$subunidad->deleted_by = $user->user_id;
			$subunidad->save();
			$subunidad->delete();
		}else{
			return App::abort(400, 'Subunidad no existe o está en Papelera.');
		}
		
		
		if (Request::input('asignatura_id')) {
			$asignatura_id 	= Request::input('asignatura_id');
			$periodo_id 	= Request::input('periodo_id');
			$num_periodo 	= Request::input('num_periodo');
			
			NotaFinal::calcularAsignaturaPeriodo($asignatura_id, $periodo_id, $num_periodo);

		}
		
		return $subunidad;
	
	}	

	public function deleteForcedelete($id)
	{
		$user = User::fromToken();
		$subunidad = Subunidad::onlyTrashed()->findOrFail($id);
		
		if ($unidad) {
			$subunidad->forceDelete();
		}else{
			return App::abort(400, 'Subunidad no encontrada en la Papelera.');
		}
		return $subunidad;
	
	}

	
	public function putEliminadas($asignatura_id)
	{
		$user = User::fromToken();
		
		$consulta 	= 'SELECT s.id, s.definicion as definicion_subunidad, s.porcentaje, u.definicion as definicion_unidad  FROM subunidades s INNER JOIN unidades u ON u.id=s.unidad_id and s.deleted_at is not null WHERE u.asignatura_id=? and u.periodo_id=?';

		$unidades = DB::select($consulta, [$asignatura_id, $user->periodo_id]);

		$res = ['subunidades' => $unidades];
		
		return $res;
	}



	public function putRestore($id)
	{
		$user = User::fromToken();
		$consulta = 'UPDATE subunidades SET deleted_at=NULL WHERE id=?';
					
		DB::update($consulta, [$id]);

		return 'Retaurada';
	}



	public function getTrashed()
	{
		$user = User::fromToken();
		$consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_superuser, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=1
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=2)
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=2
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is not null';

		return DB::select(DB::raw($consulta));
	}

}