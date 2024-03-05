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

		} else if (in_array('User', $this->user->tipo)) {

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
				':year_id' => $year_id,
				':profesor_id' => $profesor_id,
			]);

		}

		$piarsAsignaturasUtils = new PiarsAsignaturasUtils;

		foreach($asignaturas as $asignatura) {
			$asignaturas['piar_asignatura'] = $piarsAsignaturasUtils->getCreatePiarAsignatura($asignatura->asignatura_id, $alumno_id);
		}

		return $asignaturas;
	}
}