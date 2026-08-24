<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

use App\User;
use App\Models\Year;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;
use App\Exports\DocentesExport;


class ExcelListadoDocentesController extends Controller {

	public function getIndex()
	{
        return 'Holaa';


    }


	public function getDocentes($year, $year_id)
	{
        // **Contestaba 500 a todo el mundo desde el salto a `maatwebsite/excel`
        // 3.x**, y no lo reportó nadie en años. Lo de dentro era
        // `Excel::create(...)->sheet(...)->download($extension)`, que es la API
        // **2.x**: la 3.1.70 instalada sólo expone `download`, `store`, `queue`,
        // `raw`, `import`, `toArray`, `toCollection` y `queueImport`, y no hay
        // `__call` que rescate la llamada — `BadMethodCallException` seco.
        //
        // Lo llama `myvc_front` desde `InformesCtrl.ts:626`, o sea la aplicación
        // que corre HOY en los dieciséis colegios: es un botón de Informes que
        // lleva años sin dar un fichero. Medido, no leído: el test de
        // `ExportacionesExcelTest` lo tenía en rojo antes de esto.
        //
        // Se arregla **copiando el patrón de los tres que sí funcionan**
        // —`Excel::download(new XExport, ...)` con `FromView`, como
        // `cartera/exportar-solo-deudores`, `simat/alumnos-exportar` y
        // `acudientes-export/acudientes`— y no inventando uno nuevo.
        //
        // El `$extension` que se calculaba mirando el `referer` se va con ello:
        // decidía entre `xls` y `xlsx` según se estuviera en localhost, que no es
        // una propiedad del fichero sino del sitio desde el que se pide. Sale
        // `.xlsx` siempre, como sus tres vecinos.
        return Excel::download(new DocentesExport((int) $year_id),
            'Listado de docentes '.$year.'.xlsx');
    }
    
    
}
