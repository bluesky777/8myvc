<?php namespace App\Http\Controllers\Alumnos;

use App\Http\Controllers\Controller;

use Request;
use DB;
use Carbon\Carbon;

use App\User;
use App\Models\ChangeAskedDetails;



class Solicitudes extends Controller {

    public function asignaturas_a_cambiar_de_profesor($id, $year_id){

        $consulta   = 'SELECT c.id as asked_id, cs.materia_to_add_id, cs.materia_to_add_accepted, m1.materia as nombre_materia_to_add, creditos_new, g1.nombre as nombre_grupo_add, cs.grupo_to_add_id,
                            cs.asignatura_to_remove_id, cs.asignatura_to_remove_accepted, m2.materia as nombre_materia_to_remove, g2.nombre as nombre_grupo_remove, c.assignment_id
                        FROM change_asked c
                        INNER JOIN change_asked_assignment cs ON cs.id=c.assignment_id
                        LEFT JOIN materias m1 ON m1.id=cs.materia_to_add_id
                        LEFT JOIN grupos g1 ON g1.id=cs.grupo_to_add_id
                        LEFT JOIN asignaturas a2 ON a2.id=cs.asignatura_to_remove_id 
                        LEFT JOIN materias m2 ON m2.id=a2.materia_id
                        LEFT JOIN grupos g2 ON g2.id=a2.grupo_id
                        WHERE c.asked_by_user_id=:id and c.year_asked_id=:year_id and c.deleted_at is null';
        
        $pedidos    = DB::select($consulta, [
            ':id'       => $id,
            ':year_id'  => $year_id
        ]);
        
        return $pedidos;

    }

    public function asignatura_a_cambiar_de_profesor($id){

        $consulta   = 'SELECT c.id as asked_id, cs.materia_to_add_id, cs.materia_to_add_accepted, m1.materia as nombre_materia_to_add, creditos_new, g1.nombre as nombre_grupo_add, cs.grupo_to_add_id,
                            cs.asignatura_to_remove_id, cs.asignatura_to_remove_accepted, m2.materia as nombre_materia_to_remove, g2.nombre as nombre_grupo_remove, c.assignment_id
                        FROM change_asked c
                        INNER JOIN change_asked_assignment cs ON cs.id=c.assignment_id
                        LEFT JOIN materias m1 ON m1.id=cs.materia_to_add_id
                        LEFT JOIN grupos g1 ON g1.id=cs.grupo_to_add_id
                        LEFT JOIN asignaturas a2 ON a2.id=cs.asignatura_to_remove_id 
                        LEFT JOIN materias m2 ON m2.id=a2.materia_id
                        LEFT JOIN grupos g2 ON g2.id=a2.grupo_id
                        WHERE c.id=:id ';
        
        $pedido    = DB::select($consulta, [ ':id' => $id ])[0];
        
        return $pedido;

    }

    
    public function todas_solicitudes_de_profesores($year_id){

        $consulta   = 'SELECT c.id as asked_id, cs.materia_to_add_id, cs.materia_to_add_accepted, m1.materia as nombre_materia_to_add, creditos_new, g1.nombre as nombre_grupo_add,
                            cs.asignatura_to_remove_id, cs.asignatura_to_remove_accepted, m2.materia as nombre_materia_to_remove, g2.nombre as nombre_grupo_remove, c.assignment_id,
                            p.nombres as profesor_nombres, p.apellidos as profesor_apellidos, cs.grupo_to_add_id, c.asked_by_user_id, p.id as profesor_id, 
                            p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
                            u.imagen_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as imagen_nombre
                        FROM change_asked c
                        LEFT JOIN change_asked_assignment cs ON cs.id=c.assignment_id and cs.materia_to_add_accepted is null and cs.asignatura_to_remove_accepted is null and cs.creditos_accepted is null
                        LEFT JOIN profesores p ON p.user_id=c.asked_by_user_id
                        LEFT JOIN materias m1 ON m1.id=cs.materia_to_add_id
                        LEFT JOIN grupos g1 ON g1.id=cs.grupo_to_add_id
                        LEFT JOIN asignaturas a2 ON a2.id=cs.asignatura_to_remove_id 
                        LEFT JOIN materias m2 ON m2.id=a2.materia_id
                        LEFT JOIN grupos g2 ON g2.id=a2.grupo_id
                        LEFT JOIN users u on p.user_id=u.id and u.deleted_at is null
                        LEFT JOIN images i ON i.id=p.foto_id and i.deleted_at is null
                        LEFT JOIN images i2 ON i2.id=p.foto_id and i2.deleted_at is null
                        WHERE c.year_asked_id=:year_id and c.tipo_user="Profesor" and c.deleted_at is null and c.accepted_at is null and c.rechazado_at is null';
        
        $pedidos    = DB::select($consulta, [
            ':year_id'  => $year_id
        ]);
        
        
        for ($i=0; $i < count($pedidos); $i++) { 
            $detalles = ChangeAskedDetails::detalles($pedidos[$i]->asked_id);
            $pedidos[$i]->detalles = $detalles;

            if ($pedidos[$i]->materia_to_add_id) {
                
                // para saber el nombre del profe que ya tenga esta materia 

                $consulta = 'SELECT a.id as asignatura_id, p.nombres, p.apellidos FROM asignaturas a 
                    LEFT JOIN profesores p ON p.id=a.profesor_id and p.deleted_at is null
                    WHERE a.materia_id = :asign_id and a.grupo_id=:grupo_id and a.deleted_at is null';

                $asignatura_actual = DB::select($consulta, [":asign_id" => $pedidos[$i]->materia_to_add_id, ':grupo_id' => $pedidos[$i]->grupo_to_add_id]);
                
                if (count($asignatura_actual) > 0) {
                    $asignatura_actual[0]->ocupada = true;
                    $pedidos[$i]->asignatura_actual = $asignatura_actual[0];
                    
                }else{
                    $pedidos[$i]->asignatura_actual = ['ocupada' => false];
                }
            }
        }
        
        return $pedidos;

    }

}