<?php namespace App\Http\Controllers;

use Request;
use DB;

use App\User;
use App\Models\Matricula;
use Excel;
use App\Exports\DeudoresExport;


class CarteraController extends Controller {






	public function putSoloDeudores()
	{
		
		$year_id 	= Request::input('year_id');


		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, a.pazysalvo, a.deuda, a.documento,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							u.username, u.is_superuser, u.is_active, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, 
							gr.nombre as nombre_grupo, gr.abrev as abrev_grupo, gr.titular_id, gr.orden as orden_grupo, m.fecha_pension
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM")
						inner join grupos gr on gr.id=m.grupo_id and gr.year_id=:year_id and gr.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null and gr.deleted_at is null 
							and a.pazysalvo=false
						order by gr.orden, a.apellidos, a.nombres';


		$res = DB::select($consulta, [ ':year_id'	=> $year_id ]);

		return $res;
	}




	public function getExportarSoloDeudores()
	{
		return Excel::download(new DeudoresExport, 'Deudores.xlsx');
		
        $host = parse_url(request()->headers->get('referer'), PHP_URL_HOST);
        if ($host == '0.0.0.0' || $host == 'localhost' || $host == '127.0.0.1') {
            $extension = 'xls';
        }else{
            $extension = 'xlsx';
		}
		
		$user 		= User::fromToken();
		$year_id 	= $user->year_id;

		Excel::create('Alumnos con acudientes '.$year_id, function($excel) use ($year_id) {
			
            $excel->sheet('Deudores por grupos', function($sheet) use ($year_id) {


				$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
						a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, a.pazysalvo, a.deuda, a.documento,
						m.grupo_id, 
						u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
						u.username, u.is_superuser, u.is_active, 
						a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
						m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, 
						gr.nombre as nombre_grupo, gr.abrev as abrev_grupo, gr.titular_id, gr.orden as orden_grupo, m.fecha_pension
					FROM alumnos a 
					inner join matriculas m on a.id=m.alumno_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM")
					inner join grupos gr on gr.id=m.grupo_id and gr.year_id=:year_id and gr.deleted_at is null
					left join users u on a.user_id=u.id and u.deleted_at is null
					left join images i on i.id=u.imagen_id and i.deleted_at is null
					left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
					where a.deleted_at is null and m.deleted_at is null and gr.deleted_at is null 
						and a.pazysalvo=false
					order by gr.orden, a.apellidos, a.nombres';


				$alumnos = DB::select($consulta, [ ':year_id'	=> $year_id ]);
				

				$cantA = count($alumnos);
				for ($i=0; $i < $cantA; $i++) { 
                    
					$alumno = $alumnos[$i];
					
                    $sheet->setBorder('A1:I'.(count($alumnos)+2), 'thin', "D8572C");
                    $sheet->getStyle('A1:I3')->getAlignment()->setWrapText(true); 
                    
                    
                    $sheet->loadView('deudores', compact('alumnos') );
                    
                    //$sheet->setAutoFilter();
                    $sheet->setWidth(['A'=>5, 'B'=>5, 'C'=>18, 'D'=>18, 'E'=>15, 'F'=>13, 'G'=>13, 'H'=>13, 'I'=>13 ]);
                    $sheet->setHeight(1, 30);
                    
            	}

            });

            
        
        })->download($extension, ['Access-Control-Allow-Origin' => '*']);

	}



	public function putAlumnos()
	{
		//$user = User::fromToken();
		if (Request::input('grupo_actual') == ''){
			return '';
		}
		$grupo_actual 	= Request::input('grupo_actual');
		



		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
							a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, a.pazysalvo, a.deuda, a.documento,
							m.grupo_id, 
							u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre, 
							u.username, u.is_superuser, u.is_active, 
							a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
							m.fecha_retiro as fecha_retiro, m.estado, m.fecha_matricula, m.fecha_pension, 
							gr.nombre as nombre_grupo, gr.abrev as abrev_grupo, gr.titular_id, gr.orden as orden_grupo
						FROM alumnos a 
						inner join matriculas m on a.id=m.alumno_id and m.grupo_id=:grupo_id and (m.estado="ASIS" or m.estado="MATR" or m.estado="PREM")
						inner join grupos gr on gr.id=m.grupo_id and gr.deleted_at is null
						left join users u on a.user_id=u.id and u.deleted_at is null
						left join images i on i.id=u.imagen_id and i.deleted_at is null
						left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
						where a.deleted_at is null and m.deleted_at is null
						order by a.apellidos, a.nombres';


		$res = DB::select($consulta, [ ':grupo_id'	=> $grupo_actual['id'] ]);

		return $res;

	}




}