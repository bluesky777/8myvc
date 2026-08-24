<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\FraseAsignatura;
use App\Services\Auditoria;
use App\Support\PeriodoDeLaFila;
use App\Support\NombreDelAlumno;


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

		// De las tres familias de frases, ésta es la que va **pegada a un alumno**:
		// sale en su boletín con su nombre al lado. Por eso lleva `deAlumno` y las
		// otras dos no — y por eso es la que más importa de las tres.
		$alumnoDeLaLinea = (int) $frase->alumno_id;

		Auditoria::registrar()
			->crear('frase_asignatura', (int) $frase->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: (int) $frase->asignatura_id, periodo: (int) $frase->periodo_id)
			->a(['frase' => $frase->frase, 'frase_id' => $frase->frase_id])
			->guardar();

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

		// Antes del `delete()`. Quitarle una frase del boletín a un alumno es el
		// cambio que un acudiente nota, y hasta hoy no quedaba de él ningún rastro.
		$alumnoDeLaLinea = (int) $frase->alumno_id;

		Auditoria::registrar()
			->borrar('frase_asignatura', (int) $frase->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: (int) $frase->asignatura_id, periodo: (int) $frase->periodo_id)
			->de(['frase' => $frase->frase, 'frase_id' => $frase->frase_id])
			->guardar();

		$frase->delete();

		return $frase;
	}

}


