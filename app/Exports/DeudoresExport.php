<?php
namespace App\Exports;

use App\User;
use \Log;
use App\Exports\DeudoresSheet;
use Illuminate\Contracts\View\View;
use DB;
use App\Models\Matricula;
use App\Models\Acudiente;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;


class DeudoresExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $user       = User::fromToken();
        $year_id 	= $user->year_id;


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
        
        $sheets = [];
        $sheets[] = new DeudoresSheet($alumnos);
        

        return $sheets;

    }
}