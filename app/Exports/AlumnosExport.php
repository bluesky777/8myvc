<?php
namespace App\Exports;

use App\User;
use \Log;
use App\Exports\AlumnosSheet;
use Illuminate\Contracts\View\View;
use DB;
use App\Models\Matricula;
use App\Models\Acudiente;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AlumnosExport implements WithMultipleSheets
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

            $consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
            $alumnos    = DB::select($consulta, [ ':grupo_id' => $grupo->id ] );

            $opera = new OperacionesAlumnos;
            $opera->recorrer_y_dividir_nombres($alumnos);

            // Traigo los acudientes de 
            $cantA = count($alumnos);
            for ($j=0; $j < $cantA; $j++) {
                $consulta                   = Matricula::$consulta_parientes;
                $acudientes                 = DB::select($consulta, [ $alumnos[$j]->alumno_id ]);

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
                $alumnos[$j]->acudientes    = $acudientes;
            }

            $sheets[] = new AlumnosSheet($grupo, $alumnos);
        }

        return $sheets;
    }
}