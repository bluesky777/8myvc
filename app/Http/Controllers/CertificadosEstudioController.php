<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use \View;


class CertificadosEstudioController extends Controller {



	public function getCertificadoAlumno($grupo_id)
	{
		$user = User::fromToken();

		//$alumno_id = Input::get('alumno_id');
		//	$boletines = $this->detailedNotasGrupo($grupo_id, $user);

		$bol = new BolfinalesController;

		$content = View::make('certificados.estudio');
		return $user;


	}



	/*
	 * `getCertificadoGrupo` se borró el 24 sep 2026, a pedido de Joseth: daba 500 en toda llamada (la
	 * vista `certificados.estudio` no existe y dompdf no está instalado) y no la llamaba ninguna app
	 * —ni la vieja, ni app2, ni Flutter—. Los certificados de verdad van por
	 * `bolfinales/detailed-notas-year`. Ver `docs/migracion/48-los-informes-pesados.md`.
	 */


/*
											@foreach ($asignatura->definitivas as $index2 => $definitiva)
												<td>{{ $definitiva->DefMateria }}</td>
											@endforeach

*/



}