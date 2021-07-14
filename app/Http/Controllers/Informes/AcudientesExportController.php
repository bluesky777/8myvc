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


class AcudientesExportController extends Controller {



	public function getAcudientes()
	{
        $user = User::fromToken();
        
        $host = parse_url(request()->headers->get('referer'), PHP_URL_HOST);
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        }else{
            $extension = 'xlsx';
        }

		Excel::create('Acudientes '.$user->year, function($excel) use ($user) {

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

                $excel->sheet($grupos[$i]->abrev, function($sheet) use ($grupo, $user) {
                    
                    $consulta   = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.fecha_nac, ac.ciudad_nac, c1.ciudad as ciudad_nac_nombre, ac.ciudad_doc, c2.ciudad as ciudad_doc_nombre, c2.departamento as departamento_doc_nombre, ac.telefono, pa.parentesco, pa.observaciones, pa.id as parentesco_id, ac.user_id, 
                                    ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, t1.tipo as tipo_doc_nombre, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
                                    ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
                                    u.username, u.is_active, ac.is_acudiente, IF(ac.is_acudiente, "SI", "NO") as es_acudiente
                                FROM parentescos pa
                                left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
                                left join users u on ac.user_id=u.id and u.deleted_at is null
                                left join images i on i.id=ac.foto_id and i.deleted_at is null
                                left join tipos_documentos t1 on t1.id=ac.tipo_doc and t1.deleted_at is null
                                left join ciudades c1 on c1.id=ac.ciudad_nac and c1.deleted_at is null
                                left join ciudades c2 on c2.id=ac.ciudad_doc and c2.deleted_at is null
                                INNER JOIN alumnos a ON pa.alumno_id=a.id and a.deleted_at is null
                                INNER JOIN matriculas m ON m.alumno_id=a.id and m.grupo_id=? and m.deleted_at is null and (m.estado="ASIS" or m.estado="MATR")
                                WHERE pa.deleted_at is null Order by ac.is_acudiente desc, ac.id';
                                
                    $acudientes    = DB::select($consulta, [ $grupo->id ] );
                    
                    //$sheet->setBorder('A3:N'.(count($acudientes)+5), 'thin', "D8572C");
                    $sheet->getStyle('A3:N3')->getAlignment()->setWrapText(true); 
                    $sheet->mergeCells('A2:E2');
                    
                    $this->Comentarios($sheet, 3);
                    
                    
                    // Traigo los alumnos de 
		            $cantA = count($acudientes);
                    for ($i=0; $i < $cantA; $i++) { 
                        $consulta = Acudiente::$consulta_alumnos_de_acudiente; // Consulta compleja
                                            
                        $alumnos                    = DB::select($consulta, [ $acudientes[$i]->id, $user->year_id ]);
                        
                        $acudientes[$i]->alumnos    = $alumnos;
                    }
                    
                    $sheet->loadView('acudientes', compact('acudientes', 'grupo') )->mergeCells('A1:E1');
                    
                    //$sheet->setAutoFilter();
                    $sheet->setWidth(['A'=>5, 'B'=>8, 'C'=>13, 'D'=>16, 'E'=>16, 'F'=>16, 'G'=>16, 'H'=>16, 'I'=>16, 'J'=>16, 'K'=>12, 'N'=>7, ]);
                    $sheet->setHeight(3, 30);
                    
                });

            }

            
        
        })->download($extension, ['Access-Control-Allow-Origin' => '*']);


    }
    
    
    
    private function Comentarios(&$sheet, $numero=1){
        
        $sheet->getComment('C'.$numero)->getText()->createTextRun('Coloque: "CÉDULA", "PERMISO ESPECIAL DE PERMANENCIA", "TARJETA DE IDENTIDAD", "CÉDULA EXTRANJERA", "REGISTRO CIVIL", "NÚMERO DE IDENTIFICACIÓN PERSONAL", "NÚMERO ÚNICO DE IDENTIFICACIÓN PERSONAL", "NÚMERO DE SECRETARÍA", "PASAPORTE"');
        $sheet->getComment('E'.$numero)->getText()->createTextRun('No coloque departamento, solo ciudad');
        $sheet->getComment('K'.$numero)->getText()->createTextRun('Ignore esta columna');
        $sheet->getComment('L'.$numero)->getText()->createTextRun('Coloque: MATR, ASIS, RETI, DESE');
        $sheet->getComment('Q'.$numero)->getText()->createTextRun('¿Es urbano? SI o NO');
        $sheet->getComment('U'.$numero)->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el antiguo SISBEN.');
        $sheet->getComment('V'.$numero)->getText()->createTextRun('Coloque "No aplica" o deje vacío si no tiene el nuevo SISBEN tipo 3.');
        $sheet->getComment('AA'.$numero)->getText()->createTextRun('Si el año pasado NO finalizó en la institución, coloque SI, de lo contrario, especifique que NO es nuevo.');
        
        
    }


}