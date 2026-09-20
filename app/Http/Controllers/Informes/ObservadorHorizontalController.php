<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

use App\User;
use App\Models\Year;
use App\Models\Periodo;
use App\Models\Matricula;
use App\Http\Controllers\Alumnos\OperacionesAlumnos;


class ObservadorHorizontalController extends Controller {


	public function putHorizontal($grupo_id)
	{
        $user       = User::fromToken();
        $year	    = Year::datos($user->year_id);

        $consulta 	= 'SELECT * FROM images WHERE publica=true and deleted_at is null';
        $imagenes 	= DB::select($consulta);
        
        
        $consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
                        p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
                        g.created_at, g.updated_at, gra.nombre as nombre_grado 
                    from grupos g
                    inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
                    left join profesores p on p.id=g.titular_id
                    where g.deleted_at is null AND g.id=:grupo_id
                    order by g.orden';

        $grupos = DB::select($consulta, [':year_id'=> $year->year_id, ':grupo_id' => $grupo_id ] );
    
        for ($i=0; $i < count($grupos); $i++) { 
            
            $consulta   = Matricula::$consulta_asistentes_o_matriculados_simat;
            $alumnos    = DB::select($consulta, [ ':grupo_id' => $grupos[$i]->id ] );
                
            for ($j=0; $j < count($alumnos); $j++) { 
                $consulta = 'SELECT ac.id, ac.nombres, ac.apellidos, ac.sexo, ac.telefono, pa.parentesco, pa.id as parentesco_id, ac.user_id, 
                                ac.celular, ac.ocupacion, ac.email, ac.barrio, ac.direccion, ac.tipo_doc, ac.documento, ac.created_by, ac.updated_by, ac.created_at, ac.updated_at, 
                                ac.foto_id, IFNULL(i.nombre, IF(ac.sexo="F","default_female.png", "default_male.png")) as foto_nombre, 
                                u.username, u.is_active
                            FROM parentescos pa
                            left join acudientes ac on ac.id=pa.acudiente_id and ac.deleted_at is null
                            left join users u on ac.user_id=u.id and u.deleted_at is null
                            left join images i on i.id=ac.foto_id and i.deleted_at is null
                            WHERE pa.alumno_id=? and pa.deleted_at is null';
                            
                $acudientes    = DB::select($consulta, [ $alumnos[$j]->alumno_id ] );
                $alumnos[$j]->acudientes = $acudientes;
            }
            
            $grupos[$i]->alumnos = $alumnos;

        }

        /*
         * SIN GRUPO SE DEVUELVE NULO, Y NO SE REVIENTA  (2026-09-19)
         *
         * `$grupos[0]` a secas tiraba un 500 -- «Undefined array key 0» -- **siempre que la consulta
         * de arriba no encontraba el grupo**, y eso no es un caso raro: la consulta filtra por
         * `g.year_id = :year_id`, o sea por el año que tiene puesto el usuario, así que basta con
         * pedir un grupo del año pasado (un enlace guardado, una direccion copiada) para tumbarla.
         *
         * El observador VERTICAL, que es el mismo informe en la otra orientación, ya lo hacía bien:
         * le pasa `$grupos` entero a la vista y con la lista vacía sale una hoja sin nada. Esto es
         * la misma respuesta desde el lado del JSON, y el front ya sabe pintarla -- dice «El servidor
         * no devolvió ningún observador para este grupo», que es la verdad, en vez de un aviso rojo
         * con el mensaje generico del servidor.
         *
         * Medido contra el docker: `PUT observador-horizontal/horizontal/93` con el año 2026 puesto
         * y el grupo 93 siendo de 2025 -> 500 en esta línea.
         */
        // debería ser un solo grupo pero por pereza...
        $grupo = $grupos[0] ?? null;
        
        return ['grupo' => $grupo, 'imagenes' => $imagenes];

    }



}