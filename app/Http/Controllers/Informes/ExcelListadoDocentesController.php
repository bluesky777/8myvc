<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Request;
use DB;
use Excel;

use App\User;
use App\Models\Year;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;


class ExcelListadoDocentesController extends Controller {

	public function getIndex()
	{
        return 'Holaa';


    }


	public function getDocentes($year, $year_id)
	{
        $user = User::fromToken();
        
        $host = parse_url(request()->headers->get('referer'), PHP_URL_HOST);
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        }else{
            $extension = 'xlsx';
        }

		Excel::create('Listado de docentes '.$year, function($excel) use($year_id) {

            $consulta = 'SELECT p.*, c.id as contrato_id, ci.ciudad as ciudad_nac_nombre, ci.departamento as depart_nac_nombre, 
                    ci2.ciudad as ciudad_doc_nombre, ci2.departamento as depart_doc_nombre, t.tipo as tipo_doc_nombre, t.abrev, u.username 
                FROM profesores p 
                INNER JOIN contratos c ON c.profesor_id=p.id and c.deleted_at is null 
                LEFT JOIN ciudades ci ON ci.id=p.ciudad_nac and ci.deleted_at is null 
                LEFT JOIN ciudades ci2 ON ci2.id=p.ciudad_doc and ci2.deleted_at is null 
                LEFT JOIN tipos_documentos t ON t.id=p.tipo_doc and t.deleted_at is null 
                LEFT JOIN users u ON u.id=p.user_id and u.deleted_at is null 
                WHERE p.deleted_at is null and c.year_id=?';

            $profesores = DB::select($consulta, [$year_id] );
            
            for ($i=0; $i < count($profesores); $i++) { 
                $grupos = DB::select('SELECT g.abrev, g.id, g.orden FROM grupos g WHERE g.deleted_at is null and g.titular_id=? and year_id=?', [$profesores[$i]->id, $year_id]);
                $profesores[$i]->grupos = '';
                
                $cant_g = count($grupos);
                
                for ($j=0; $j < $cant_g; $j++) { 
                    $profesores[$i]->grupos .= $grupos[$j]->abrev;
                    
                    if (! isset($profesores[$i]->orden_grupo)) {
                        $profesores[$i]->orden_grupo = $grupos[$j]->orden;
                    }
                    
                    if ($cant_g > 0 && $j < ($cant_g-1)) {
                        $profesores[$i]->grupos .= ',';
                    }
                }
                    
            }
            
            
            $excel->sheet('Docentes', function($sheet) use ($profesores) {
                
                $sheet->setBorder('A1:Q'.(count($profesores)+3), 'thin', "D8572C");
                $sheet->getStyle('A1:Q1')->getAlignment()->setWrapText(true);
                
                $sheet->loadView('listado-docentes', compact('profesores') );
                
                $sheet->setWidth(['A'=>5, 'B'=>8, 'C'=>30, 'D'=>5, 'E'=>14, 'F'=>6, 'G'=>11, 'P'=>13, 'Q'=>18, ]);
                $sheet->setHeight(1, 30);
                
            });

            
        
        })->download($extension, ['Access-Control-Allow-Origin' => '*']);


    }
    
    
    private function Comentarios(&$sheet){
        
        $sheet->getComment('C3')->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('E3')->getText()->createTextRun('No coloque departamento, solo ciudad');
        $sheet->getComment('K3')->getText()->createTextRun('Ignore esta columna');
        $sheet->getComment('L3')->getText()->createTextRun('Coloque: MATR, ASIS, RETI, DESE');
        $sheet->getComment('Q3')->getText()->createTextRun('¿Es urbano? SI o NO');
        $sheet->getComment('U3')->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el antiguo SISBEN.');
        $sheet->getComment('V3')->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el nuevo SISBEN tipo 3.');
        $sheet->getComment('AA3')->getText()->createTextRun('Si el año pasado NO finalizó en la institución, coloque SI, de lo contrario, especifique que NO es nuevo.');
        
        $sheet->getComment('AE3')->getText()->createTextRun('Coloque un código e ignore las demás columnas para asignar un acudiente a este alumno que ya está agregado');
        $sheet->getComment('AI3')->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('AJ3')->getText()->createTextRun('SI o NO');
        $sheet->getComment('AK3')->getText()->createTextRun('Padre, Madre, Hermano, Hermana, Abuelo, Abuela, Tío, Tía, Primo(a), Otro');
        $sheet->getComment('AM3')->getText()->createTextRun('Ignore esta columna');
        $sheet->getComment('AS3')->getText()->createTextRun('Ignore esta columna');
        $sheet->getComment('AU3')->getText()->createTextRun('Comentarios sobre este acudiente del alumno');
        
        $sheet->getComment('AV3')->getText()->createTextRun('Coloque un código e ignore las demás columnas para asignar un acudiente a este alumno que ya está agregado');
        $sheet->getComment('AZ3')->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('BA3')->getText()->createTextRun('SI o NO');
        $sheet->getComment('BB3')->getText()->createTextRun('Padre, Madre, Hermano, Hermana, Abuelo, Abuela, Tío, Tía, Primo(a), Otro');
        $sheet->getComment('BD3')->getText()->createTextRun('Ignore esta columna');
        $sheet->getComment('BJ3')->getText()->createTextRun('Ignore esta columna');
        $sheet->getComment('BL3')->getText()->createTextRun('Comentarios sobre este acudiente del alumno');
        
    }


}