<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use App\Exports\AcudientesExport;
use Maatwebsite\Excel\Facades\Excel;

use App\User;
use App\Models\Year;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;


class AcudientesExportController extends Controller {

	public function getAcudientes()
	{
        return Excel::download(new AcudientesExport, 'acudientes.xlsx');
    }
}