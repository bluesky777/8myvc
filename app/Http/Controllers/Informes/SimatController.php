<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Request;
use DB;
use App\Exports\AlumnosExport;
use Maatwebsite\Excel\Facades\Excel;

use App\User;
use App\Models\Year;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;


class SimatController extends Controller {

	public function getIndex()
	{
        return 'Holaa';


    }


	public function getAlumnos()
	{
        $user = User::fromToken();
        
        $host = parse_url(request()->headers->get('referer'), PHP_URL_HOST);
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        }else{
            $extension = 'xlsx';
        }

		Excel::create('Alumnos con acudientes '.$user->year, function($excel) use ($user) {

            $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                g.created_at, g.updated_at, gra.nombre as nombre_grado 
                from grupos g
                inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                left join profesores p on p.id=g.titular_id
                where g.deleted_at is null
                order by g.orden';

            $grupos = DB::select($consulta, [':year_id'=> $user->year_id] );
            
            for ($i=0; $i < count($grupos); $i++) { 
                $grupo = $grupos[$i];

                $excel->sheet($grupos[$i]->abrev, function($sheet) use ($grupo) {
                    
                    $consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
                    $alumnos    = DB::select($consulta, [ ':grupo_id' => $grupo->id ] );
                    
                    $sheet->setBorder('A3:BL'.(count($alumnos)+5), 'thin', "D8572C");
                    $sheet->getStyle('A3:BL3')->getAlignment()->setWrapText(true); 
                    $sheet->mergeCells('A2:E2');
                    
                    $this->Comentarios($sheet, 3);
                    
                    $opera = new OperacionesAlumnos;
                    $opera->recorrer_y_dividir_nombres($alumnos);
                    
                    // Traigo los acudientes de 
		            $cantA = count($alumnos);
                    for ($i=0; $i < $cantA; $i++) { 
                        $consulta                   = Matricula::$consulta_parientes;
                        $acudientes                 = DB::select($consulta, [ $alumnos[$i]->alumno_id ]);
                        
                        if (count($acudientes) == 0) {
                            $acu1       = (object)Acudiente::$acudiente_vacio;
                            //$acu1->id   = -1;
                            array_push($acudientes, $acu1);
                            
                            $acu2       = (object)Acudiente::$acudiente_vacio;
                            //$acu2->id   = 0;
                            array_push($acudientes, $acu2);
                        }else if (count($acudientes) == 1) {
                            $acu1 = (object)Acudiente::$acudiente_vacio;
                            //$acu1->id = -1;
                            array_push($acudientes, $acu1);
                        }
                        $alumnos[$i]->acudientes    = $acudientes;
                    }
                    
                    $sheet->loadView('simat', compact('alumnos', 'grupo') )->mergeCells('A1:E1');
                    
                    //$sheet->setAutoFilter();
                    $sheet->setWidth(['A'=>5, 'B'=>5, 'C'=>10, 'D'=>11, 'E'=>10, 'F'=>16, 'P'=>13, 'Q'=>7, 'R'=>11, 'S'=>11, 'T'=>7, 'Y'=>14, 'Z'=>5, 'AA'=>7, 'X'=>10, 'AB'=>5, 'AD'=>10, 
                                        'AF'=>12, 'AG'=>12, 'AH'=>6, 'AL'=>11, 'AN'=>14, 'AO'=>11, 'AP'=>11, 'AU'=>17,
                                        'AW'=>12, 'AX'=>12, 'AY'=>6, 'BC'=>11, 'BE'=>14, 'BF'=>11, 'BG'=>11, 'BL'=>17,]);
                    $sheet->setHeight(3, 30);
                    
                });

            }

            
        
        })->download($extension, ['Access-Control-Allow-Origin' => '*']);


    }
    
    

	public function getAlumnosExportar()
	{
        return Excel::download(new AlumnosExport, 'alumnos.xlsx');
        $user = User::fromToken();
        
        $host = parse_url(request()->headers->get('referer'), PHP_URL_HOST);
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        }else{
            $extension = 'xlsx';
        }

		Excel::create('Alumnos a importar '.$user->year, function($excel) {

            $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                g.created_at, g.updated_at, gra.nombre as nombre_grado 
                from grupos g
                inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                left join profesores p on p.id=g.titular_id
                where g.deleted_at is null
                order by g.orden';

            $grupos = DB::select($consulta, [':year_id'=> Year::actual()->id] );
            
            for ($i=0; $i < count($grupos); $i++) { 
                $grupo = $grupos[$i];

                $excel->sheet($grupos[$i]->abrev, function($sheet) use ($grupo) {
                    
                    $consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
                    $alumnos    = DB::select($consulta, [ ':grupo_id' => $grupo->id ] );
                    
                    $sheet->setBorder('A1:BL'.(count($alumnos)+5), 'thin', "D8572C");
                    $sheet->getStyle('A1:BL1')->getAlignment()->setWrapText(true); 
                    //$sheet->mergeCells('A2:E2');
                    
                    $this->Comentarios($sheet, 1);
                    
                    $opera = new OperacionesAlumnos;
                    $opera->recorrer_y_dividir_nombres($alumnos);
                    
                    // Traigo los acudientes de 
		            $cantA = count($alumnos);
                    for ($i=0; $i < $cantA; $i++) { 
                        $consulta                   = Matricula::$consulta_parientes;
                        $acudientes                 = DB::select($consulta, [ $alumnos[$i]->alumno_id ]);
                        
                        if (count($acudientes) == 0) {
                            $acu1       = (object)Acudiente::$acudiente_vacio;
                            //$acu1->id   = -1;
                            array_push($acudientes, $acu1);
                            
                            $acu2       = (object)Acudiente::$acudiente_vacio;
                            //$acu2->id   = 0;
                            array_push($acudientes, $acu2);
                        }else if (count($acudientes) == 1) {
                            $acu1 = (object)Acudiente::$acudiente_vacio;
                            //$acu1->id = -1;
                            array_push($acudientes, $acu1);
                        }
                        $alumnos[$i]->acudientes    = $acudientes;
                    }
                    
                    $sheet->loadView('alumnosexportar', compact('alumnos', 'grupo') );
                    
                    //$sheet->setAutoFilter();
                    $sheet->setWidth(['A'=>5, 'B'=>5, 'C'=>10, 'D'=>11, 'E'=>10, 'F'=>16, 'P'=>13, 'Q'=>7, 'R'=>11, 'S'=>11, 'T'=>7, 'Y'=>14, 'Z'=>5, 'AA'=>7, 'X'=>10, 'AB'=>5, 'AD'=>10, 
                                        'AF'=>12, 'AG'=>12, 'AH'=>6, 'AL'=>11, 'AN'=>14, 'AO'=>11, 'AP'=>11, 'AU'=>17,
                                        'AW'=>12, 'AX'=>12, 'AY'=>6, 'BC'=>11, 'BE'=>14, 'BF'=>11, 'BG'=>11, 'BL'=>17,]);
                    $sheet->setHeight(1, 30);
                    
                });

            }

            
        
        })->download($extension, ['Access-Control-Allow-Origin' => '*']);


    }
    
    
    private function Comentarios(&$sheet, $numero=1){
        
        $sheet->getComment('A'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('B'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('C'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('E'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('E'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('K'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('L'.$numero)->getText()->createTextRun('Coloque: MATR, ASIS, RETI, DESE');
        $sheet->getComment('P'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('Q'.$numero)->getText()->createTextRun('¿Es urbano? SI o NO');
        $sheet->getComment('U'.$numero)->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el antiguo SISBEN.');
        $sheet->getComment('V'.$numero)->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el nuevo SISBEN tipo 3.');
        $sheet->getComment('X'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('Y'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('Z'.$numero)->getText()->createTextRun('M o F');
        $sheet->getComment('AA'.$numero)->getText()->createTextRun('Si el año pasado NO finalizó en la institución, coloque SI, de lo contrario, especifique que NO es nuevo.');
        
        
        $sheet->getComment('AE'.$numero)->getText()->createTextRun('Si sabe el ID del acudiente, coloquelo aquí e ignore las demás columnas para asignar el acudiente con ese ID a este alumno. Si es un acudiente nuevo, no debe poner ID, ignore esta columna');
        $sheet->getComment('AH'.$numero)->getText()->createTextRun('M o F');
        $sheet->getComment('AI'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('AJ'.$numero)->getText()->createTextRun('SI o NO');
        $sheet->getComment('AK'.$numero)->getText()->createTextRun('Padre, Madre, Hermano, Hermana, Abuelo, Abuela, Tío, Tía, Primo(a), Otro');
        $sheet->getComment('AM'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('AN'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('AS'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('AU'.$numero)->getText()->createTextRun('Comentarios sobre este acudiente del alumno');
        
        $sheet->getComment('AV'.$numero)->getText()->createTextRun('Si sabe el ID del acudiente, coloquelo aquí e ignore las demás columnas para asignar el acudiente con ese ID a este alumno. Si es un acudiente nuevo, no debe poner ID, ignore esta columna');
        $sheet->getComment('AY'.$numero)->getText()->createTextRun('M o F');
        $sheet->getComment('AZ'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('BA'.$numero)->getText()->createTextRun('SI o NO');
        $sheet->getComment('BB'.$numero)->getText()->createTextRun('Padre, Madre, Hermano, Hermana, Abuelo, Abuela, Tío, Tía, Primo(a), Otro');
        $sheet->getComment('BD'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('BE'.$numero)->getText()->createTextRun('Si sabe el ID de la ciudad, colóquelo aquí. De lo contrario ignore esta columna');
        $sheet->getComment('BJ'.$numero)->getText()->createTextRun('Sólo lectura (ignore esta columna)');
        $sheet->getComment('BL'.$numero)->getText()->createTextRun('Comentarios sobre este acudiente del alumno');
        
    }


}