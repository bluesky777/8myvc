<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Intervention\Image\Laravel\Facades\Image;
use \stdClass;

use App\User;
use App\Models\ImageModel;
use App\Models\Year;
use App\Models\Debugging;
use App\Http\Controllers\Perfiles\Publicaciones;
use \Log;

use Carbon\Carbon;


class PublicacionesController extends Controller {

    public function putUltimas(){
        # Las publicaciones
        $publicaciones = Publicaciones::ultimas_publicaciones('Todos');
        
        $year = DB::select('SELECT id, prematr_nuevos, year FROM years WHERE actual=1 and deleted_at is null');
        
        if (count($year) > 0) {
            $year = $year[0];
            if ($year->prematr_nuevos) {
                
				// Grupos próximo año
				$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, g.grado_id, g.year_id, g.titular_id, g.created_at, g.updated_at
                    from grupos g
                    inner join years y on y.id=g.year_id and y.year=:anio and y.deleted_at is null
                    where g.deleted_at is null order by g.orden';
                
                $grados_sig = DB::select($consulta, [':anio'=> ($year->year+1) ] );
                $year->grados_sig = $grados_sig;
            }
        }

        return ['publicaciones' => $publicaciones, 'year' => $year];
    }
    

    /**
     * Alias de putUltimas(). MISMA respuesta, otro verbo.
     *
     * No es scaffolding: el `GET` fue el verbo real del frontend durante cinco
     * años y medio —de c116e3f (2018-10-12) a c09718e (2024-03-05), en
     * myvc_front—, así que cualquier colegio con un front de esa época sigue
     * llamando por aquí.
     *
     * Delega en vez de repetir. Antes eran 21 líneas duplicadas palabra por
     * palabra, y eso es peligroso aquí en concreto: **esta respuesta alimenta el
     * formulario público de prematrícula**, no solo las noticias del login. El
     * desplegable de "Grupo a prematricular" sale de `year.grados_sig`. Con dos
     * copias, quien tocara una dejaría a los colegios del otro verbo con una
     * respuesta distinta, y sin forma de notarlo.
     */
    public function getUltimas(){
        return $this->putUltimas();
    }
    

	public function putStore()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');
        
        $publi_para             = Request::input('publi_para');
        $para_alumnos           = 0;
        $para_acudientes        = 0;
        $para_profes            = 0;
        $para_administradores   = 0;

		if ($publi_para == "publi_para_todos"){
            $para_todos             = 1;
            $para_alumnos           = 1;
            $para_acudientes        = 1;
            $para_profes            = 1;
            $para_administradores   = 1;
        }elseif($publi_para == "publi_privada") {
            $para_todos             = 0;
            
            if (Request::input('para_alumnos'))   $para_alumnos = 1;
            if (Request::input('para_acudientes'))      $para_acudientes = 1;
            if (Request::input('para_profes'))          $para_profes = 1;
            if (Request::input('para_administradores')) $para_administradores = 1;

		}
        
        $imagen         = Request::input('imagen');
        $imagen_id      = null;
        $imagen_nom     = null;
        if ($imagen) {
            $imagen_id  = $imagen['id'];
            $imagen_nom = $imagen['nombre'];
        }
        
        $consulta = 'INSERT INTO publicaciones(persona_id, tipo_persona, contenido, imagen_id, imagen_nombre, para_todos, para_alumnos, para_acudientes, para_profes, para_administradores, created_at, updated_at) 
                    VALUES(:persona_id, :tipo_persona, :contenido, :imagen_id, :imagen_nombre, :para_todos, :para_alumnos, :para_acudientes, :para_profes, :para_administradores, :created_at, :updated_at)';
        DB::insert($consulta, [
            ':persona_id' 	        => $user->persona_id, 
            ':tipo_persona' 	    => $user->tipo, 
            ':contenido' 	        => Request::input('contenido'), 
            ':imagen_id' 	        => $imagen_id, 
            ':imagen_nombre' 	    => $imagen_nom, 
            ':para_todos' 	        => $para_todos, 
            ':para_alumnos' 	    => $para_alumnos, 
            ':para_acudientes'	    => $para_acudientes, 
            ':para_profes'	        => $para_profes, 
            ':para_administradores'	=> $para_administradores, 
            ':created_at' 	        => $now,
            ':updated_at' 	        => $now
        ]);
        
        $last_id = DB::getPdo()->lastInsertId();

		return ['publicacion_id' => $last_id];
	}


    

	public function putGuardarEdicion()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');

        // La hermana que se quedó fuera cuando se cerraron `putDelete` y
        // `putRestaurar`. Y nombra la publicación `id` y no `publi_id`, que es por
        // lo que buscar la clave por su nombre no la encontró.
        $this->exigeQueLaPublicacionSeaSuya($user, Request::input('id'));

        $publi_para             = Request::input('publi_para');
        $para_alumnos           = 0;
        $para_acudientes        = 0;
        $para_profes            = 0;
        $para_administradores   = 0;

		if ($publi_para == "publi_para_todos"){
            $para_todos             = 1;
            $para_alumnos           = 1;
            $para_acudientes        = 1;
            $para_profes            = 1;
            $para_administradores   = 1;
        }elseif($publi_para == "publi_privada") {
            $para_todos             = 0;
            
            if (Request::input('para_alumnos'))   $para_alumnos = 1;
            if (Request::input('para_acudientes'))      $para_acudientes = 1;
            if (Request::input('para_profes'))          $para_profes = 1;
            if (Request::input('para_administradores')) $para_administradores = 1;

		}
        
        $imagen         = Request::input('imagen');
        $imagen_id      = null;
        $imagen_nom     = null;
        if ($imagen) {
            $imagen_id  = $imagen['id'];
            $imagen_nom = $imagen['nombre'];
        }
        
        $consulta = 'UPDATE publicaciones SET contenido=:contenido, imagen_id=:imagen_id, imagen_nombre=:imagen_nombre, 
            para_todos=:para_todos, para_alumnos=:para_alumnos, para_acudientes=:para_acudientes, para_profes=:para_profes, 
            para_administradores=:para_administradores, updated_at=:updated_at WHERE id=:id';
            
        DB::update($consulta, [
            ':contenido' 	        => Request::input('contenido'), 
            ':imagen_id' 	        => $imagen_id, 
            ':imagen_nombre' 	    => $imagen_nom, 
            ':para_todos' 	        => $para_todos, 
            ':para_alumnos' 	    => $para_alumnos, 
            ':para_acudientes'	    => $para_acudientes, 
            ':para_profes'	        => $para_profes, 
            ':para_administradores'	=> $para_administradores, 
            ':updated_at' 	        => $now,
            ':id'                   => Request::input('id'),
        ]);
        
		return 'Modificada';
	}


    
    
	public function putComentar()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');
        
        $consulta = 'INSERT INTO comentarios(publicacion_id, persona_id, tipo_persona, comentario, created_at, updated_at) 
            VALUES (:publicacion_id, :persona_id, :tipo_persona, :comentario, :created_at, :updated_at)';
        
        DB::insert($consulta, [
            ':publicacion_id' 	   => Request::input('publi_id'), 
            ':persona_id'          => $user->persona_id, 
            ':tipo_persona' 	   => $user->tipo, 
            ':comentario' 	       => Request::input('comentario'), 
            ':created_at' 	       => $now, 
            ':updated_at' 	       => $now, 
        ]);
        
        $last_id = DB::getPdo()->lastInsertId();

		return ['comentario_id' => $last_id];
    }

    
    
    
	public function putBorrarComentario()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');
        
        if ($user->is_superuser || $user.persona_id==comentario.persona_id) {
            
            $consulta = 'UPDATE comentarios SET deleted_at=:deleted_at, deleted_by=:deleted_by WHERE id=:id';
            DB::update($consulta, [
                ':deleted_at' 	        => $now, 
                ':deleted_by'           => $user->user_id, 
                ':id' 	                => Request::input('comentario_id'), 
            ]);
            return 'Eliminado';
        }else{
            return abort(400, 'No tienes permitido borrar este comentario');
        }
        
    }

    
    
    
	public function putDelete()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');

        $this->exigeQueLaPublicacionSeaSuya($user, Request::input('publi_id'));

        $consulta = 'UPDATE publicaciones SET deleted_at=:deleted_at, deleted_by=:deleted_by WHERE id=:id';
        DB::update($consulta, [
            ':deleted_at' 	        => $now, 
            ':deleted_by'           => $user->user_id, 
            ':id' 	                => Request::input('publi_id'), 
        ]);
        return 'Eliminada';
    }

    
	public function putRestaurar()
	{
        $user   = User::fromToken();
        $now 	= Carbon::now('America/Bogota');

        $this->exigeQueLaPublicacionSeaSuya($user, Request::input('publi_id'));

        $consulta = 'UPDATE publicaciones SET deleted_at=null, updated_at=:updated_at WHERE id=:id';
        DB::update($consulta, [
            ':updated_at' 	        => $now,
            ':id' 	                => Request::input('publi_id'), 
        ]);
        return 'Restaurada';
    }



    /**
     * Borrar, restaurar y editar solo lo propio.
     *
     * **La regla existía y vivía únicamente en el frontend**: el botón de la
     * papelera de `publicacionesPanelDir.html` va dentro de un
     * `ng-if="publi.persona_id==$ctrl.USER.persona_id || $ctrl.USER.is_superuser"`.
     * El backend borraba por `publi_id` sin mirar de quién era, así que cualquiera
     * con un token —un alumno, sin ir más lejos— borraba del muro la publicación
     * de quien fuera. Se comprueba aquí lo mismo que el front ya decide, ni más ni
     * menos: así nada de lo que hoy se puede hacer deja de poderse.
     *
     * `tipo_persona` va en la comparación aunque el front no lo mire, porque
     * `persona_id` solo es único DENTRO de su tabla: el alumno 12 y el profesor 12
     * existen los dos. Es lo mismo que guarda `postStore` al publicar.
     *
     * **Y la edición pide lo mismo**, que es lo que faltaba: el lápiz de
     * `publicacionesPanelDir.html` va dentro de un
     * `ng-if="$ctrl.USER.persona_id==$ctrl.publicacion_actual.persona_id"`, o sea
     * solo el autor. El backend reescribía por `id` sin mirar, y no solo el texto:
     * también a quién se le enseña. Ver 05 §22.
     */
    private function exigeQueLaPublicacionSeaSuya(object $user, $publiId): void
    {
        if ($user->is_superuser) {
            return;
        }

        $suya = DB::selectOne(
            'SELECT id FROM publicaciones WHERE id = ? AND persona_id = ? AND tipo_persona = ?',
            [$publiId, $user->persona_id, $user->tipo]
        );

        if ($suya === null) {
            abort(403, 'Solo puedes tocar tus publicaciones');
        }
    }
}