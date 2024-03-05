<?php namespace App\Http\Controllers\Piars;

use Request;
use DB;
use App\Http\Controllers\Controller;

use App\User;
use Carbon\Carbon;
use App\Http\Controllers\Piars\Utils\PiarsAsignaturasUtils;
use App\Models\Profesor;

class PiarsAsignaturasController extends Controller {
	public $user;

	public function __construct()
	{
		$this->user = User::fromToken();
	}

	public function getAsignaturas($alumno_id) {
		if ($this->user->tipo === 'Profesor') {

			$asignaturas = Profesor::asignaturas($this->user->year_id, $this->user->persona_id);

		} else if (in_array($this->user->tipo, ['Usuario'])) {

			$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
					m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, g.titular_id, g.caritas,
					gr.nivel_educativo_id
				FROM asignaturas a
				inner join materias m on m.id=a.materia_id and m.deleted_at is null
				inner join grupos g on g.id=a.grupo_id and g.year_id=:year_id and g.deleted_at is null
				inner join grados gr on gr.id=g.grado_id and gr.deleted_at is null 
				where a.deleted_at is null
				order by g.orden, a.orden, m.materia, m.alias, a.id';

			$asignaturas = DB::select(DB::raw($consulta), [
				':year_id' => $this->user->year_id,
			]);

		}

		$piarsAsignaturasUtils = new PiarsAsignaturasUtils;

		for ($i=0; $i < count($asignaturas); $i++) { 
			$asignaturas[$i]->piar_asignatura = $piarsAsignaturasUtils->getCreatePiarAsignatura($asignaturas[$i]->asignatura_id, $alumno_id);
		}

		return $asignaturas;
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
		$validFields = ['apoyo_razonable', 'seguimientos'];
		if (!in_array($field, $validFields)) {
			return response()->json(['error' => 'Invalid'], 400);
		}

		$consulta = "UPDATE piars_asignaturas 
			SET $field=?, updated_at=?, updated_by=?
			WHERE id=?";
		$piars = DB::update($consulta, [
			$text, $updated_at, $updated_by, $id,  
		]);

    return ['piars' => $piars];
	}
}