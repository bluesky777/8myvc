<?php
namespace App\Exports;

use App\User;
use \Log;
use App\Exports\AcudientesSheet;
use Illuminate\Contracts\View\View;
use DB;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AcudientesExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $user = User::fromToken();
        $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
            p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
            g.created_at, g.updated_at, gra.nombre as nombre_grado 
        from grupos g
        inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
        left join profesores p on p.id=g.titular_id
        where g.deleted_at is null
        order by g.orden';

        $grupos = DB::select($consulta, [':year_id'=> $user->year_id] );
        $sheets = [];
        for ($i=0; $i < count($grupos); $i++) {
            $grupo = $grupos[$i];

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

            $acudientes = DB::select($consulta, [ $grupo->id ] );
            // Traigo los alumnos de 
            $cantA = count($acudientes);
            for ($j=0; $j < $cantA; $j++) { 
                $consulta = Acudiente::$consulta_alumnos_de_acudiente; // Consulta compleja
                $alumnos                    = DB::select($consulta, [ $acudientes[$j]->id, $user->year_id ]);
                $acudientes[$j]->alumnos    = $alumnos;
            }
            $sheets[] = new AcudientesSheet($grupo, $acudientes);
        }
        return $sheets;
    }
}