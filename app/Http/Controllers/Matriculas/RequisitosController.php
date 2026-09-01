<?php namespace App\Http\Controllers\Matriculas;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Matricula;
use App\Models\Acudiente;
use Carbon\Carbon;

use App\Events\MatriculasEvent;
use \Log;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class RequisitosController extends Controller {
	use ResuelveElUsuario;


	public function putIndex()
	{
        
        $consulta   = 'SELECT id, year, actual, abrev_colegio FROM years WHERE deleted_at is null ORDER BY year desc';
        $years      = DB::select($consulta);
        
        for ($i=0; $i < count($years); $i++) { 
           
            $consulta = 'SELECT * FROM requisitos_matricula WHERE year_id=? and deleted_at is null';
            $years[$i]->requisitos = DB::select($consulta, [$years[$i]->id]);
        }
        
        return $years;
	}
	

	public function postStore()
	{
        $requ       = Request::input('requisito');
        $descrip    = Request::input('descripcion');
        $year_id    = Request::input('year_id');
        $now 		= Carbon::now('America/Bogota');
        
        $consulta = 'INSERT INTO requisitos_matricula(requisito, descripcion, updated_by, created_at, updated_at, year_id) 
            VALUES(?,?,?,?,?,?)';
        DB::insert($consulta, [$requ, $descrip, $this->user->user_id, $now, $now, $year_id]);
        
        $consulta = 'SELECT * FROM requisitos_matricula WHERE id=?';
        $requisito = DB::select($consulta, [ DB::getPdo()->lastInsertId() ] )[0];
        
        return ['requisito' => $requisito];
	}
	


	public function putUpdate()
	{
		$id         = Request::input('id');
		$requ       = Request::input('requisito');
		$descrip    = Request::input('descripcion');
		$now 		= Carbon::now('America/Bogota');
		
		$consulta = 'UPDATE requisitos_matricula SET requisito=?, descripcion=?, updated_by=?, updated_at=? WHERE id=?';
		DB::update($consulta, [$requ, $descrip, $this->user->user_id, $now, $id]);
		
		return 'Actualizado';
	}
		



	/*
	 * SOLO SE ESCRIBEN LAS COLUMNAS QUE VIENEN EN EL CUERPO, y antes se escribian las dos siempre.
	 *
	 * EL FALLO QUE CIERRA, que no daba ningun error y se llevaba un dato por delante:
	 *
	 *     UPDATE requisitos_alumno SET estado=?, descripcion=?, ... WHERE id=?
	 *
	 * Con `estado` fuera del cuerpo llegaba NULL y se escribia NULL. Y hay un llamante que no lo
	 * manda: la pantalla de prematriculas de la aplicacion vieja
	 * (`PrematriculasCtrl::guardarCambioRequisito`) le pasa **la fila de la observacion**, y esa
	 * fila no trae `estado` -- `putListadoObservaciones` selecciona nombres, apellidos, celular,
	 * grupo, descripcion y el id, y nada mas --. O sea que **corregir el texto de una observacion
	 * borraba si el alumno habia entregado el papel**, en silencio y con un «Actualizado» de vuelta.
	 *
	 * Con esto, quien manda una columna la escribe --incluso a NULL, que es como se vacia un
	 * texto-- y quien no la manda la deja como estaba. Los llamantes que mandan las dos
	 * (`persona-matriculas` de `app2`, `PersonaCtrl` de la vieja) no notan ningun cambio.
	 *
	 * Encontrado desde `myvc_front` al recrear la pantalla de prematriculas (2026-09-01).
	 */
	public function postAlumno()
	{
		$id         = Request::input('requisito_alumno_id');
		$now 		= Carbon::now('America/Bogota');

		$sets    = [];
		$valores = [];

		// `has` y no `filled`: un `descripcion` vacio o nulo SI es un cambio -- es como se borra
		// una observacion --, y lo que no puede tocarse es la columna que nadie nombro.
		foreach (['estado', 'descripcion'] as $columna) {
			if (Request::has($columna)) {
				$sets[]    = $columna.'=?';
				$valores[] = Request::input($columna);
			}
		}

		// Sin ninguna columna que escribir no se toca la fila. Se contesta lo mismo que siempre:
		// esta ruta devuelve 'Actualizado' pase lo que pase desde antes de esto, y cambiarlo ahora
		// moveria lo que ven las pantallas vivas de los dieciseis colegios.
		if (count($sets) === 0) {
			return 'Actualizado';
		}

		$sets[]    = 'updated_by=?';
		$valores[] = $this->user->user_id;
		$sets[]    = 'updated_at=?';
		$valores[] = $now;
		$valores[] = $id;

		DB::update('UPDATE requisitos_alumno SET '.implode(', ', $sets).' WHERE id=?', $valores);

		return 'Actualizado';
	}
		


	public function putListadoObservaciones()
	{
		$now 		= Carbon::now('America/Bogota');
		$year_id 	= Request::input('year_id', $this->user->year_id);
		
		
		$consulta 	= 'SELECT * FROM requisitos_matricula WHERE year_id=? and deleted_at is null';
		$requisitos = DB::select($consulta, [$year_id]);
		
		
		for ($i=0; $i < count($requisitos); $i++) { 
			
			$consulta 	= 'SELECT distinct(o.descripcion) as descripcion FROM requisitos_alumno o WHERE o.requisito_id=? and o.descripcion is not null and o.descripcion!=""';
			$requisitos[$i]->requisitos_alumnos = DB::select($consulta, [ $requisitos[$i]->id ]);
		
			
			// `o.estado` viaja desde el 2026-09-01: sin el, quien pinte estas filas no puede
			// devolverlo al guardar, y hasta hoy eso ponia la columna a NULL. Ver `postAlumno`.
			$consulta 	= 'SELECT a.nombres, o.alumno_id, a.apellidos, a.celular, g.abrev as abrev_grupo, o.descripcion, o.estado, o.id as requisito_alumno_id 
				FROM requisitos_alumno o
				INNER JOIN requisitos_matricula r ON r.id=o.requisito_id and r.deleted_at is null
				INNER JOIN alumnos a ON a.id=o.alumno_id and a.deleted_at is null
				INNER JOIN matriculas m ON a.id=m.alumno_id and (m.estado="MATR" or m.estado="ASIS" or m.estado="PREM") and m.deleted_at is null
				INNER JOIN grupos g ON g.id=m.grupo_id and g.year_id=? and m.deleted_at is null
				WHERE r.id=? and o.descripcion is not null and o.descripcion!="" 
				ORDER BY g.abrev, a.apellidos';
				
			$requisitos[$i]->alumnos_observaciones = DB::select($consulta, [ $year_id, $requisitos[$i]->id ]);
		
			
		}
		
		
		return [ 'requisitos' => $requisitos ];
		
	}
		

	public function deleteDestroy($id)
		{
		$now 		= Carbon::now('America/Bogota');
		$consulta   = 'UPDATE requisitos_matricula SET deleted_at=? WHERE id=?';
				DB::update($consulta, [$now, $id]);

		return 'Eliminado';
	}







}