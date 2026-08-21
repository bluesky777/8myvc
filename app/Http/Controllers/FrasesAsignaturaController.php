<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\FraseAsignatura;
use App\Support\PeriodoDeLaFila;


class FrasesAsignaturaController extends Controller {



	public function postStore($frase_id='')
	{
		$user = User::fromToken();
		// La frase se crea con `periodo_id = $user->periodo_id` tres líneas más
		// abajo, así que el periodo de la fila que se escribe es ése y no el que
		// venga en `num_periodo`. §27.
		User::pueden_editar_notas($user, (int) $user->periodo_id);

		$frase = new FraseAsignatura;
		$frase->alumno_id = Request::input('alumno_id');
		$frase->asignatura_id = Request::input('asignatura_id');
		$frase->periodo_id = $user->periodo_id;

		if ($frase_id=='') {
			$frase->frase = Request::input('frase');
		}else{
			$frase->frase_id = $frase_id;
		}

		$frase->save();

		$frases = FraseAsignatura::deAlumno($frase->asignatura_id, $frase->alumno_id, $user->periodo_id);

		return $frases;
	}

	public function getShow($alumno_id, $asignatura_id)
	{
		$user = User::fromToken();

		$frases = FraseAsignatura::deAlumno($asignatura_id, $alumno_id, $user->periodo_id);
		return $frases;
	}



	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deFraseAsignatura($id));
		
		$frase = FraseAsignatura::findOrFail($id);
		$frase->delete();

		return $frase;
	}

}


