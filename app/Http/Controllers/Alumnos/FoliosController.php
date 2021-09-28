<?php namespace App\Http\Controllers\Alumnos;


use DB;
use Request;
use Carbon\Carbon;
use \Log;

use App\User;
use App\Models\Matricula;
use App\Models\Year;
use App\Models\Alumno;

use App\Http\Controllers\Controller;


class FoliosController extends Controller {

	public function getIniciar()
	{
        $yearactual = Year::actual();

		$consulta = 'UPDATE matriculas m
			INNER JOIN grupos g ON g.id=m.grupo_id AND (m.nro_folio is null OR m.nro_folio="") AND g.year_id=? and g.deleted_at is null
			INNER JOIN years y ON y.id=g.year_id and y.deleted_at is null
			SET m.nro_folio=CONCAT(y.year,"-", m.alumno_id);';

		$matriculas = DB::update($consulta, [$yearactual->id]);
		return $matriculas;
		$canti_matri = count($matriculas);

		for ($i=0; $i < $canti_matri; $i++) {
			
		}

        return [$matriculas];
	}

	
	
}

