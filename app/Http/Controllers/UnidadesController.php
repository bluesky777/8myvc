<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\DB;


use App\User;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Profesor;
use App\Models\NotaFinal;

use Carbon\Carbon;
use \Log;
use App\Support\PeriodoDeLaFila;


class UnidadesController extends Controller {


	// Las columnas van nombradas y NO se vuelve a `*`: `unidades.alumno_id` existe
	// desde el 24 ago 2026 (19-boletin-independiente.md) y un `*` la mete en la
	// respuesta, moviendo la instantánea de contrato de la ruta que use esto.
	// Es la §5.bis de noche-2026-08-24/bi-1.md.
	private $cons_unidades 		= 'SELECT id, definicion, porcentaje, periodo_id, asignatura_id, obligatoria, orden, por_defecto, fecha, created_by, updated_by, deleted_by, deleted_at, created_at, updated_at FROM unidades WHERE asignatura_id=? and periodo_id=? and deleted_at is null order by orden, id';
	private $cons_subunidades 	= 'SELECT * FROM subunidades WHERE unidad_id=? and deleted_at is null order by orden, id';


	public function putDeAsignaturaPeriodo($asignatura_id, $periodo_id)
	{
		$user = User::fromToken();


		$consulta 	= 'SELECT a.id, a.materia_id, a.grupo_id, g.grado_id FROM asignaturas a
			INNER JOIN grupos g ON g.id=a.grupo_id and g.deleted_at is null
			WHERE a.id=:asignatura_id and a.deleted_at is null';
		
		// `[0]` sobre una consulta que no trajo filas es un aviso de PHP que Laravel
		// sube a excepción: **500 con «Undefined array key 0» dentro**. Pasa con una
		// asignatura que no existe y también con una que está en la papelera o cuyo
		// grupo lo está, porque el `INNER JOIN` de arriba filtra `deleted_at`. Un id
		// que no lleva a ninguna fila es un 404, y este método además **escribe** —
		// crea las unidades por defecto—, así que conviene pararlo antes. §96.
		$asignaturas = DB::select($consulta, [":asignatura_id"=>$asignatura_id]);

		if ($asignaturas === []) {
			abort(404, 'La asignatura no existe o está en la papelera.');
		}

		$asignatura = $asignaturas[0];

		
		$consulta 	= 'SELECT p.id, p.numero as numero_periodo, p.year_id, y.year FROM periodos p
			INNER JOIN years y ON y.id=p.year_id and y.deleted_at is null
			WHERE p.numero=:numero and y.id!=:year_id and p.deleted_at is null order by p.id desc';

		$periodos = DB::select($consulta, [ ":numero"=>$user->numero_periodo, ":year_id"=>$user->year_id ]);

		for ($i=0; $i < count($periodos); $i++) {
			// Columnas nombradas, no `u.*`: con `*`, `unidades.alumno_id` (24 ago 2026,
			// 19-boletin-independiente.md) entra en la respuesta y mueve la instantánea.
			// Ver §5.bis de noche-2026-08-24/bi-1.md. No volver a `*`.
			$consulta = 'SELECT u.id, u.definicion, u.porcentaje, u.periodo_id, u.asignatura_id, u.obligatoria, u.orden, u.por_defecto, u.fecha, u.created_by, u.updated_by, u.deleted_by, u.deleted_at, u.created_at, u.updated_at
				FROM unidades u
				INNER JOIN asignaturas a ON u.asignatura_id=a.id and a.materia_id=? and u.deleted_at is null
				INNER JOIN grupos g ON g.id=a.grupo_id and g.grado_id=? and g.deleted_at is null
				WHERE u.periodo_id=? and u.deleted_at is null order by orden, id';

			$unidades 			= DB::select($consulta, [$asignatura->materia_id, $asignatura->grado_id, $periodos[$i]->id]);

			foreach ($unidades as $key => $unidad) {
				$subunidades = DB::select($this->cons_subunidades, [$unidad->id]);
				$unidad->subunidades = $subunidades;
			}


			$periodos[$i]->unidades = $unidades;

		}


		$unidades_actuales = $this->getDeAsignaturaPeriodo($asignatura_id, $periodo_id, $user);

		return ["unidades" => $unidades_actuales, "anios_pasados"=>$periodos];
	}



	public function getDeAsignaturaPeriodo($asignatura_id, $periodo_id, $user=null)
	{
		// `is_object` y no `== null`: el tercer parámetro es a la vez el argumento
		// de la llamada interna de `putDeAsignaturaPeriodo` —que pasa el objeto de
		// usuario— y el segmento `{user?}` de la URL, que solo puede llegar como
		// cadena. Con la comparación anterior, una petición con el tercer segmento
		// se metía aquí con `$user = "1"` y reventaba en `$user->year_id` unas
		// líneas más abajo: 500 seguro siempre que la asignatura y el periodo no
		// tuvieran unidades ya. Ver 05 §16.
		if (! is_object($user)) {
			$user = User::fromToken();
		}

	
		$unidades = DB::select($this->cons_unidades, [$asignatura_id, $periodo_id]);

		// Esta ruta lee y de paso escribe, así que no puede llevar el `abort()` de
		// sus hermanas: sería apagarle al profesor la vista de un periodo cerrado,
		// que es justo la que va a querer consultar cuando esté cerrado. Decidido
		// por Joseth: **enseña lo que hay y no crea nada**. Ver 05 §47.2.
		$puedeEscribir = User::permiteEditarNotas($user, (int) $periodo_id);

		if (count($unidades) == 0 && $puedeEscribir) {
			$consulta = 'SELECT * FROM unidades_por_defecto WHERE year_id=? and deleted_at is null';
			$unidades_default = DB::select($consulta, [$user->year_id]);

			if (count($unidades_default) > 0) {
				$now 		= Carbon::now('America/Bogota');

				foreach ($unidades_default as $unidad_d) {

					// Creo las nuevas unidades basado en las unidades por defecto del año
					$consulta 		= 'INSERT INTO unidades(definicion, porcentaje, periodo_id, asignatura_id, obligatoria, orden, por_defecto, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?) ';
					$insertadas 	= DB::insert($consulta, [$unidad_d->definicion, $unidad_d->porcentaje, $periodo_id, $asignatura_id, $unidad_d->obligatoria, $unidad_d->orden, true, $user->user_id, $now ]);
					$last_id 	    = DB::getPdo()->lastInsertId();
					
					$consulta 				= 'SELECT * FROM subunidades_por_defecto WHERE unidad_defec_id=? and deleted_at is null';
					$subunidades_default 	= DB::select($consulta, [$unidad_d->id]);
						
					for ($j=0; $j < count($subunidades_default); $j++) { 
						// Creo las subunidades por defecto de cada Unidad por defecto
						$consulta 		= 'INSERT INTO subunidades(definicion, porcentaje, unidad_id, nota_default, obligatoria, orden, por_defecto, created_by, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?) ';
						$insertadas 	= DB::insert($consulta, [$subunidades_default[$j]->definicion, $subunidades_default[$j]->porcentaje, $last_id, $subunidades_default[$j]->nota_default, $subunidades_default[$j]->obligatoria, $subunidades_default[$j]->orden, true, $user->user_id, $now ]);
		
					}
				}
			}else{
				return '';
			}
			
		}

		// Vuelvo a traer las unidades, por si las moscas y para arreglar orden
		$orden_duplicado 	= false;
		$orden_anterior 	= -5;
		$unidades 			= DB::select($this->cons_unidades, [$asignatura_id, $periodo_id]);

		foreach ($unidades as $unidad) {

			$subunidades 			= DB::select($this->cons_subunidades, [$unidad->id]);
			$unidad->subunidades 	= $subunidades;

			// A veces hay varios con el mismo número en el orden, debo encontrarlo y arreglarlo.
			if ($orden_anterior == $unidad->orden) {
				$orden_duplicado = true;
			}else{
				$orden_anterior = $unidad->orden;
			}
		}

		// `arreglarOrden()` no ordena la respuesta: **reescribe `orden` en la tabla**
		// de todas las unidades y subunidades, en cada lectura. O sea que este GET
		// escribía en la rejilla incluso cuando ya había unidades. Con el periodo
		// cerrado no se toca — y sin esto quedaría el mismo agujero que la §47
		// acaba de cerrar en `unidades/update-orden`, alcanzable por el otro lado:
		// la misma escritura, un camino tapado y el otro no.
		if ($puedeEscribir) {
			$unidades = Unidad::arreglarOrden($unidades, $asignatura_id, $periodo_id);
		}

		return $unidades;
	}
	
	


	// Un informe con todo lo del profe
	public function putDeProfesor()
	{
		$user 			= User::fromToken();
		$periodo_id 	= $user->periodo_id;
		$profesor_id	= Request::input('profesor_id');
		
		// `Profesor::detallado` acaba en `return $profesor[0];` sin comprobar que la
		// consulta trajera fila: con un id que no existe **o uno de la papelera** —que
		// su `where` descarta— eso es 500. El modelo lo comparten seis llamantes de
		// tres dominios distintos, así que se para aquí y no allí: poner un `?? null`
		// dentro convertiría seis 500 en seis comportamientos distintos sin haber
		// medido cuál es el correcto en cada pantalla. Lo encontró el lote E en su
		// llamante y eligió el mismo 404. §96.
		if (! Profesor::where('id', $profesor_id)->exists()) {
			abort(404, 'El profesor no existe o está en la papelera.');
		}

		$info_profesor 	= Profesor::detallado($profesor_id);
		$asignaturas 	= Profesor::asignaturas($user->year_id, $profesor_id);

		foreach ($asignaturas as $asignatura) {
			
			$asignatura->unidades = DB::select($this->cons_unidades, [$asignatura->asignatura_id, $periodo_id]);
			
			
			foreach ($asignatura->unidades as $unidad) {

				$subunidades 			= DB::select($this->cons_subunidades, [$unidad->id]);
				$unidad->subunidades 	= $subunidades;

			}

		}
		
		return ['info_profesor' => $info_profesor, 'asignaturas' => $asignaturas];
		
	}



	public function postIndex()
	{
		$user = User::fromToken();

		// La unidad nace con `periodo_id = $user->periodo_id` tres líneas más
		// abajo, así que el periodo de la fila que se toca ES el del usuario: no
		// hay fila de la que derivarlo todavía. Faltaba —crear una unidad con el
		// periodo cerrado devolvía 201— mientras su gemelo
		// `SubunidadesController::postIndex` sí lo pedía. Ver 05 §47.
		User::pueden_editar_notas($user, (int) $user->periodo_id);

		$cant = Unidad::where('periodo_id', $user->periodo_id)
				->where('asignatura_id', Request::input('asignatura_id'))
				->count();

		$unidad = new Unidad;
		$unidad->definicion		= Request::input('definicion');
		$unidad->porcentaje		= Request::input('porcentaje');
		$unidad->periodo_id		= $user->periodo_id;
		$unidad->created_by		= $user->user_id;
		$unidad->asignatura_id	= Request::input('asignatura_id');
		$unidad->orden			= $cant;
		$unidad->save();

		return $unidad;
	}

	public function putUpdateOrden()
	{
		$user = User::fromToken();

		$sortHash = Request::input('sortHash');

		// Los periodos de TODAS las unidades que se mueven, como hace su gemelo
		// `SubunidadesController::putUpdateOrdenVarias`: reordenar es escribir en
		// la rejilla de notas, y faltaba. Ver 05 §47.
		$ordenes = [];

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){
				$ordenes[(int)$key] = (int)$value;
			}
		}

		User::pueden_editar_notas($user, PeriodoDeLaFila::deVariasUnidades(array_keys($ordenes)));

		foreach ($ordenes as $id => $orden) {
			// `find()` devolvía null con un id que no existe y `->orden` sobre null
			// era un 500. Es 404 porque el cliente nombró una unidad que no está.
			$unidad = Unidad::findOrFail($id);
			$unidad->orden = $orden;
			$unidad->save();
		}

		return 'Ordenado correctamente';
	}


	public function putUpdate($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deUnidad($id));

		$unidad = Unidad::findOrFail($id);
		// **`porcentaje` no es un dato descriptivo: es un factor de la definitiva.**
		// La nota sale de `(u.porcentaje/100) * ((s.porcentaje/100) * n.nota)`, y el
		// recálculo está diez líneas más abajo, en este mismo método. Con
		// `Request::input('porcentaje')` a secas, un cuerpo que sólo traía
		// `definicion` —corregirle la redacción a un logro— dejaba el peso en null y
		// **cambiaba la nota que va al boletín**, en 200 y sin avisar. Y el de la
		// unidad es el factor de fuera: se lleva por delante todas las subunidades
		// que cuelgan de ella de golpe, no una.
		//
		// Es la §68 otra vez, y aquí no hace falta `CamposQueVinieron`: este método no
		// tiene ningún `merge()` delante, así que el defecto de `Request::input()`
		// distingue igual. §96.
		$unidad->definicion		= Request::input('definicion', $unidad->definicion);
		$unidad->porcentaje		= Request::input('porcentaje', $unidad->porcentaje);
		$unidad->updated_by		= $user->user_id;
		$unidad->save();
		
		
		// Fase 3 de 10-definitivas.md: el recálculo lo hace el servicio único, y
		// **deja de depender de que el cliente mande `asignatura_id`**. Ese
		// `if (Request::input('asignatura_id'))` era la mitad del problema: si el
		// front no lo mandaba —y no siempre lo manda— **el peso cambiaba y la
		// definitiva no**, en 200 y sin avisar. La unidad sabe de qué asignatura y
		// periodo es; no hay nada que preguntarle al cuerpo.
		DefinitivasDeAsignatura::recalcularPorUnidad((int) $id, $user->user_id);
		
		return $unidad;
	}


	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deUnidad($id));
		$unidad = Unidad::find($id);

		if ($unidad) {
			$unidad->deleted_by = $user->user_id;
			$unidad->save();
			$unidad->delete();
		}else{
			return abort(404, 'Unidad no existe o está en Papelera.');
		}
		
		
		// Fase 3 de 10-definitivas.md: el recálculo lo hace el servicio único, y
		// **deja de depender de que el cliente mande `asignatura_id`**. Ese
		// `if (Request::input('asignatura_id'))` era la mitad del problema: si el
		// front no lo mandaba —y no siempre lo manda— **el peso cambiaba y la
		// definitiva no**, en 200 y sin avisar. La unidad sabe de qué asignatura y
		// periodo es; no hay nada que preguntarle al cuerpo.
		DefinitivasDeAsignatura::recalcularPorUnidad((int) $id, $user->user_id);
		
		return $unidad;
	
	}	

	public function deleteForcedelete($id)
	{
		$user = User::fromToken();
		// La unidad está en la papelera —esto es un forcedelete—, así que el
		// resolutor no filtra `deleted_at`. §27.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deUnidad($id));

		$unidad = Unidad::onlyTrashed()->findOrFail($id);
		
		$unidad->forceDelete();
		return $unidad;
	
	}
	
	
	
	public function putEliminadas($asignatura_id)
	{
		$user = User::fromToken();
		
		// Columnas nombradas, no `*` — ver el comentario de `$cons_unidades` arriba.
		$cons_unidades 		= 'SELECT id, definicion, porcentaje, periodo_id, asignatura_id, obligatoria, orden, por_defecto, fecha, created_by, updated_by, deleted_by, deleted_at, created_at, updated_at FROM unidades WHERE asignatura_id=? and periodo_id=? and deleted_at is not null';
		$cons_subunidades 	= 'SELECT * FROM subunidades WHERE unidad_id=? and deleted_at is null';

		$unidades = DB::select($cons_unidades, [$asignatura_id, $user->periodo_id]);

		foreach ($unidades as $unidad) {

			$subunidades 			= DB::select($cons_subunidades, [$unidad->id]);
			$unidad->subunidades 	= $subunidades;

		}
		$res = ['unidades_eliminadas' => $unidades];
		
		return $res;
	}



	public function putRestore($id)
	{
		$user = User::fromToken();

		// Restaurar devuelve la unidad con su `porcentaje` a la rejilla, así que
		// es escribir en las notas igual que borrarla — y `deleteDestroy` sí lo
		// pedía. `PeriodoDeLaFila::deUnidad()` no filtra `deleted_at` justo para
		// esto. Ver 05 §47.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deUnidad($id));

		$consulta = 'UPDATE unidades SET deleted_at=NULL WHERE id=?';
					
		DB::update($consulta, [$id]);

		return 'Retaurada';
	}


	public function getTrashed()
	{
		$user = User::fromToken();
		$consulta = 'SELECT u.id, u.definicion, u.porcentaje, u.periodo_id, u.orden,
						p.numero as numero_periodo, p.actual as periodo_actual, a.id as asignatura_id, a.materia_id,
						m.materia, m.alias as alias_materia, 
						gru.id as grupo_id, gru.nombre as nombre_grupo, gru.abrev as abrev_grupo
					FROM unidades u 
					inner join asignaturas a on a.id=u.asignatura_id
					inner join materias m on m.id=a.materia_id
					inner join grupos gru on gru.id=a.grupo_id
					inner join periodos p on p.id=u.periodo_id
					where u.deleted_at is not null';

		return DB::select($consulta);
	}

}