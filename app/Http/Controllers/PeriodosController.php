<?php namespace App\Http\Controllers;

use DB;
use Request;
use Carbon\Carbon;

use App\User;
use App\Models\Periodo;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Nota;
use \stdClass;


class PeriodosController extends Controller {

	public $user;
	
	public function __construct()
	{
		$this->user = User::fromToken();
	}
	

	public function getIndex()
	{
		$consulta = 'SELECT * FROM periodos WHERE deleted_at is null and year_id=? order by numero';
		return DB::select($consulta, [ $this->user->year_id ]);
	}

	public function postStore($year_id)
	{
		$periodo = new Periodo;
		$periodo->numero						=	Request::input('numero');
		$periodo->fecha_inicio					=	Request::input('fecha_inicio');
		$periodo->fecha_fin						=	Request::input('fecha_fin');
		$periodo->actual						=	false;
		$periodo->year_id						=	$year_id;
		$periodo->profes_pueden_editar_notas	=	1;
		$periodo->profes_pueden_nivelar			=	1;
		$periodo->fecha_plazo					=	Request::input('fecha_plazo');

		$periodo->save();

		return $periodo;
		
	}

	public function getShow($year_id)
	{
		return Periodo::where('year_id', $year_id)->get();
	}

	public function putUpdate($id)
	{
		$periodo = Periodo::findOrFail($id);

		$periodo->numero			=	Request::input('numero');
		$periodo->fecha_inicio		=	Request::input('fecha_inicio');
		$periodo->fecha_fin			=	Request::input('fecha_fin');
		$periodo->actual			=	Request::input('actual');
		$periodo->year				=	Request::input('year');
		$periodo->fecha_plazo		=	Request::input('fecha_plazo');
		$periodo->updated_by 		= 	$this->user->user_id;

		$periodo->save();

		return $periodo;
	}

	public function putCambiarFechaInicio()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->fecha_inicio	=	Carbon::parse(Request::input('fecha'));
		$periodo->updated_by 	= 	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putCambiarFechaFin()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->fecha_fin		=	Carbon::parse(Request::input('fecha'));
		$periodo->updated_by 	= 	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putToggleProfesPuedenEditarNotas()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->profes_pueden_editar_notas	=	Request::input('pueden');
		$periodo->updated_by 					=	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putToggleProfesPuedenNivelar()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->profes_pueden_nivelar	=	Request::input('pueden');
		$periodo->updated_by 			= 	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putUseractive($periodo_id)
	{
		$usuario = User::findOrFail($this->user->user_id);
		$usuario->periodo_id 	= $periodo_id;
		$usuario->updated_by 	= 	$this->user->user_id;
		$usuario->save();

		return $usuario;
	}


	public function putEstablecerActual($periodo_id)
	{
		$periodoACambiar = Periodo::findOrFail($periodo_id);
		
		$periodos = Periodo::where('year_id', $periodoACambiar->year_id)->get();

		foreach ($periodos as $periodo) {
			
			if ($periodo->id != $periodoACambiar->id) {
				$periodo->actual = false;
				$periodo->save();
			}
			
		}

		$periodoACambiar->actual 		= true;
		$periodoACambiar->updated_by 	= $this->user->user_id;
		$periodoACambiar->save();

		return $periodoACambiar;
	}


	public function putCopiar()
	{
		$grupo_from_id 		= Request::input('grupo_from_id');
		$grupo_to_id 		= Request::input('grupo_to_id');
		$asignatura_to_id	= Request::input('asignatura_to_id');
		$copiar_subunidades	= Request::input('copiar_subunidades');
		$copiar_notas		= Request::input('copiar_notas');
		$periodo_from_id	= Request::input('periodo_from_id');
		$periodo_to_id		= Request::input('periodo_to_id');
		$unidades_ids		= Request::input('unidades_ids');


		$unidades_copiadas = 0;
		$subunidades_copiadas = 0;
		$notas_copiadas = 0;


		foreach ($unidades_ids as $unidad_id) {

			$unidad_curr = Unidad::findOrFail($unidad_id);
			$unidad_new = new Unidad;

			$unidad_new->definicion 	= $unidad_curr->definicion;
			$unidad_new->porcentaje 	= $unidad_curr->porcentaje;
			$unidad_new->orden 			= $unidad_curr->orden;
			$unidad_new->created_by 	= $this->user->user_id;
			$unidad_new->periodo_id 	= $periodo_to_id;
			$unidad_new->asignatura_id 	= $asignatura_to_id;

			$unidad_new->save();

			$unidades_copiadas++;


			if ($copiar_subunidades) {
				$subunidades = Subunidad::deUnidad($unidad_id);
				
				foreach ($subunidades as $subunidad) {
					$sub_new = new Subunidad;
					$sub_new->definicion 	= $subunidad->definicion_subunidad;
					$sub_new->porcentaje 	= $subunidad->porcentaje_subunidad;
					$sub_new->unidad_id 	= $unidad_new->id;
					$sub_new->nota_default 	= $subunidad->nota_default;
					$sub_new->orden 		= $subunidad->orden_subunidad;
					$sub_new->inicia_at 	= $subunidad->inicia_at;
					$sub_new->finaliza_at 	= $subunidad->finaliza_at;
					$sub_new->created_by 	= $this->user->user_id;

					$sub_new->save();
					$subunidades_copiadas++;


					if ($copiar_notas and $grupo_to_id==$grupo_from_id) {
					
						$notas = Subunidad::notas($subunidad->subunidad_id);

						foreach ($notas as $nota) {
							$nota_new = new Nota;
							$nota_new->nota 		= $nota->nota;
							$nota_new->subunidad_id = $sub_new->id;
							$nota_new->alumno_id 	= $nota->alumno_id;
							$nota_new->created_by 	= $this->user->user_id;
							
							$nota_new->save();
							$notas_copiadas++;

						}
					}
				}

			}
			

		}
		
		$res = new stdClass;
		$res->unidades_copiadas		= $unidades_copiadas;
		$res->subunidades_copiadas	= $subunidades_copiadas;
		$res->notas_copiadas		= $notas_copiadas;
		
		
		
		$consulta = 'SELECT id, definicion, porcentaje, orden 
					FROM unidades
					where asignatura_id=:asignatura_id and periodo_id=:periodo_id and deleted_at is null
					order by orden';

		$unidades = DB::select($consulta, [
			':asignatura_id'	=> $asignatura_to_id,
			':periodo_id'		=> $periodo_to_id
		]);


		foreach ($unidades as $unidad) {

			$consulta = 'SELECT id, definicion, porcentaje, orden, "0" as cantNotas 
						FROM subunidades
						where unidad_id=:unidad_id and deleted_at is null
						order by orden';

			$unidad->subunidades = DB::select(DB::raw($consulta), [':unidad_id'	=> $unidad->id]);


		}
			
		$res->unidades		= $unidades;
			

		return (array)$res;
	}

	public function deleteDestroy($periodo_id)
	{
		$periodo = Periodo::findOrFail($periodo_id);
		$periodo->deleted_by 	= $this->user->user_id;
		$periodo->save();
		$periodo->delete();

		return $periodo;
	}

}
