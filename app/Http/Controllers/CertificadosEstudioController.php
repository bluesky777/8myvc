<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use \View;
use \App;


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



	public function getCertificadoGrupo($grupo_id)
	{
		$user = User::fromToken();

		//$alumno_id = Input::get('alumno_id');
		//	$boletines = $this->detailedNotasGrupo($grupo_id, $user);
		
		$bol = new BolfinalesController;
		$datos = $bol->detailedNotasGrupo($grupo_id, $user);

		$content = View::make('certificados.estudio')->with('grupo', $datos[0])
						->with('year', $datos[1])
						->with('alumnos', $datos[2])
						->with('User', User::class);

		$pdf = App::make('dompdf.wrapper');
		$pdf->loadHTML($content);
		return $pdf->download();

		return $content;


	}


/*
											@foreach ($asignatura->definitivas as $index2 => $definitiva)
												<td>{{ $definitiva->DefMateria }}</td>
											@endforeach

*/



}