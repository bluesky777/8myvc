<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\NotaFinal;
use Carbon\Carbon;
use App\Support\PeriodoDeLaFila;


class SubunidadesController extends Controller {

	

	public function postIndex()
	{
		$user 	= User::fromToken();
		$now 	= Carbon::now('America/Bogota');
		// La subunidad todavía no existe: nace colgada de `unidad_id`, así que el
		// periodo al que se escribe es el de esa unidad. §27.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deUnidad(Request::input('unidad_id')));

		$cant = Subunidad::where('unidad_id', Request::input('unidad_id'))->count();

		$subunidad = new Subunidad;

		$nota_def = Request::input('nota_default');
		
		if (!$nota_def or $nota_def =='' or $nota_def < 0) {
			$nota_def = 0;
		}

		$subunidad->definicion		= Request::input('definicion');
		$subunidad->porcentaje		= Request::input('porcentaje');
		$subunidad->orden			= Request::input('orden', 0);
		$subunidad->unidad_id		= Request::input('unidad_id');
		$subunidad->nota_default	= $nota_def;
		$subunidad->orden			= $cant;
		$subunidad->created_by		= $user->user_id;

		$subunidad->save();


		$consulta 	= 'SELECT id as history_id FROM historiales where user_id=? and deleted_at is null order by id desc limit 1';

		$histo 		= DB::select($consulta, [$user->user_id])[0];

		$bit_by 	= $user->user_id;
		$bit_hist 	= $histo->history_id;
		$bit_new 	= $subunidad->definicion . ' -- ' . $subunidad->porcentaje . '%'; 	// Guardo la nota nueva
		$bit_per 	= $user->periodo_id;

		$consulta 	= 'INSERT INTO bitacoras (created_by, historial_id, affected_element_type, affected_element_id, affected_element_new_value_string, created_at) 
					VALUES (?, ?, "Nueva subunidad", ?, ?, ?)';

		DB::insert($consulta, [$bit_by, $bit_hist, $subunidad->id, $bit_new, $now]);
		

		return $subunidad;
	}





	
	/**
	 * Los ids de subunidad que vienen dentro de `sortHash`.
	 *
	 * El cuerpo llega como una lista de objetos `{id: orden}` —así lo manda el
	 * front—, o sea que el identificador es la CLAVE y no el valor. Hace falta
	 * aparte porque el bucle que las guarda las recorre de dos en dos niveles y
	 * el candado tiene que conocerlas todas antes de escribir la primera. §27.
	 *
	 * @return array<int>
	 */
	private static function idsDelSortHash($sortHash): array
	{
		$ids = [];
		
		foreach (is_array($sortHash) ? $sortHash : [] as $fila) {
			foreach (is_array($fila) ? $fila : [] as $id => $orden) {
				$ids[] = (int) $id;
			}
		}
		
		return $ids;
	}
	
	
	public function putUpdateOrden()
	{
		$user = User::fromToken();
		// Esta toca VARIAS filas de golpe. Se comprueban todas y basta que una
		// esté en periodo cerrado para que no pase ninguna: escribir la mitad de
		// un reordenado es peor que no escribir nada. §27.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deVariasSubunidades(
            self::idsDelSortHash(Request::input('sortHash'))
        ));

		$sortHash = Request::input('sortHash');

		for($row = 0; $row < count($sortHash); $row++){
			foreach($sortHash[$row] as $key => $value){

				// `find()` devolvía null con un id que no existe y la línea de abajo
				// reventaba: 500 donde tocaba 404. El bucle de reordenar está copiado en
				// cinco controladores y los cinco lo tenían; el de unidades se arregló en
				// la §47 y éste salió al contarlos. Ver 05 §52.
				$subunidad 			= Subunidad::findOrFail((int)$key);
				$subunidad->orden 	= (int)$value;
				$subunidad->save();

			}
		}

		return 'Ordenado correctamente';
	}



	
	public function putUpdateOrdenVarias()
	{
		$user = User::fromToken();
		// Mueve subunidades entre dos unidades, así que hay dos periodos que
		// mirar y tienen que estar abiertos los dos. §27.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deVariasUnidades([
            Request::input('unidad1_id'), Request::input('unidad2_id'),
        ]));

		$sortHash1 	= Request::input('sortHash1');
		$sortHash2 	= Request::input('sortHash2');
		$unidad1_id = Request::input('unidad1_id');
		$unidad2_id = Request::input('unidad2_id');

		for($row = 0; $row < count($sortHash1); $row++){
			foreach($sortHash1[$row] as $key => $value){

				$subunidad 				= Subunidad::findOrFail((int)$key);
				$subunidad->orden 		= (int)$value;
				$subunidad->unidad_id 	= (int)$unidad1_id;
				$subunidad->save();

			}
		}

		for($row = 0; $row < count($sortHash2); $row++){
			foreach($sortHash2[$row] as $key => $value){

				$subunidad 				= Subunidad::findOrFail((int)$key);
				$subunidad->orden 		= (int)$value;
				$subunidad->unidad_id 	= (int)$unidad2_id;
				$subunidad->save();

			}
		}

		return 'Ordenado correctamente';
	}






	/**
	 * **Lo que no venga en el cuerpo se queda como estaba.** Ver 05 §92.2.
	 *
	 * Es la §68 —«un campo que no se manda no es un campo que no cambia»— caída en
	 * la rejilla. Las tres columnas se asignaban con `Request::input()` sin defecto,
	 * así que un cuerpo que sólo renombrara la subunidad dejaba `porcentaje` a
	 * **null**; y `porcentaje` no es un dato descriptivo: es lo que pesa el
	 * componente dentro de la unidad —`(u.porcentaje/100)*((s.porcentaje/100)*n.nota)`—
	 * así que la definitiva que sale al boletín cambia. Y cambia enseguida, porque
	 * este mismo método recalcula la asignatura veinte líneas más abajo.
	 *
	 * El defecto se toma de la fila, que es lo que ya decidió la §68, y **no**
	 * `CamposQueVinieron`: eso hace falta donde el controlador hace `Request::merge()`
	 * antes de leer y `has()` deja de distinguir, y aquí no lo hace nadie.
	 *
	 * `nota_default` conserva su recorte a 0 letra por letra: lo único que cambia es
	 * de dónde sale cuando el campo no viene. Su vecino
	 * `NotaComportamientoController::putUpdate` no necesita nada porque envuelve cada
	 * asignación en su `Request::has()` — la diferencia está en la línea de antes, no
	 * en la asignación, que es justo lo que un barrido por
	 * `$obj->col = Request::input('col')` no ve.
	 *
	 * Ningún cliente cambia: `UnidadesCtrl.ts:651` manda las cuatro columnas siempre.
	 * Lo fija `PorcentajeQueSePisaTest`, con el caso al revés —mandar un 0 sigue
	 * poniendo 0— para que el defecto no se coma un valor legítimo.
	 */
	public function putUpdate($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deSubunidad($id));

		$subunidad = Subunidad::findOrFail($id);

		$nota_def = Request::input('nota_default', $subunidad->nota_default);

		if (!$nota_def or $nota_def =='' or $nota_def < 0) {
			$nota_def = 0;
		}

		$subunidad->definicion		= Request::input('definicion', $subunidad->definicion);
		$subunidad->porcentaje		= Request::input('porcentaje', $subunidad->porcentaje);
		$subunidad->nota_default	= $nota_def;
		$subunidad->updated_by		= $user->user_id;

		if ( Request::has('orden') ) {
			$subunidad->orden	= Request::input('orden');
		}
		
		$subunidad->save();
		
		
		if (Request::input('asignatura_id')) {
			$asignatura_id 	= Request::input('asignatura_id');
			$periodo_id 	= Request::input('periodo_id');
			$num_periodo 	= Request::input('num_periodo');
			
			NotaFinal::calcularAsignaturaPeriodo($asignatura_id, $periodo_id, $num_periodo);

		}

		return $subunidad;
	}




	/*
	 * El 26.º de la §27, y el que se quedó fuera por lo que decía un test.
	 *
	 * De los siete métodos que escriben en este controlador, seis piden
	 * `pueden_editar_notas` desde la §27 y éste no — mientras su gemelo exacto,
	 * `UnidadesController::deleteDestroy`, sí lo pide. Y no borra poco: borra un
	 * componente calificable y **recalcula las definitivas de la asignatura** en la
	 * línea de abajo.
	 *
	 * Lo que lo mantuvo abierto un mes es que había una frase que decía lo
	 * contrario. El docblock de `UnidadesTest::test_no_se_restaura_una_subunidad_con_el_periodo_cerrado`
	 * dice, para justificar por qué se cerró `subunidades/restore`, que
	 * «`subunidades/update` y `subunidades/destroy`, en el mismo fichero, sí piden
	 * el periodo». Uno de los dos sí y el otro no, y nadie volvió a mirarlo porque
	 * la frase estaba escrita al lado de un test verde. Ver 05 §80.
	 *
	 * El periodo se deriva de la fila —la subunidad cuelga de la unidad, que lleva
	 * `periodo_id`— y no del cuerpo, que es lo que hacen sus seis hermanas.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		User::pueden_editar_notas($user, PeriodoDeLaFila::deSubunidad($id));

		$subunidad = Subunidad::find($id);

		if ($subunidad) {
			$subunidad->deleted_by = $user->user_id;
			$subunidad->save();
			$subunidad->delete();
		}else{
			return abort(404, 'Subunidad no existe o está en Papelera.');
		}
		
		
		if (Request::input('asignatura_id')) {
			$asignatura_id 	= Request::input('asignatura_id');
			$periodo_id 	= Request::input('periodo_id');
			$num_periodo 	= Request::input('num_periodo');
			
			NotaFinal::calcularAsignaturaPeriodo($asignatura_id, $periodo_id, $num_periodo);

		}
		
		return $subunidad;
	
	}	

	public function deleteForcedelete($id)
	{
		$user = User::fromToken();

		// Su hermano unidades/forcedelete sí pasa por pueden_editar_notas; este no
		// comprobaba nada. Hoy es inerte por un bug (el if de abajo mira $unidad,
		// que no existe, así que siempre cae en el else), pero el guard va igual:
		// arreglar esa variable es un cambio de una palabra.
		// En la papelera: el resolutor no filtra `deleted_at` justo por esto.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deSubunidad($id));

		$subunidad = Subunidad::onlyTrashed()->findOrFail($id);
		
		$subunidad->forceDelete();
		return $subunidad;
	
	}

	
	public function putEliminadas($asignatura_id)
	{
		$user = User::fromToken();
		
		$consulta 	= 'SELECT s.id, s.definicion as definicion_subunidad, s.porcentaje, u.definicion as definicion_unidad  FROM subunidades s INNER JOIN unidades u ON u.id=s.unidad_id and s.deleted_at is not null WHERE u.asignatura_id=? and u.periodo_id=?';

		$unidades = DB::select($consulta, [$asignatura_id, $user->periodo_id]);

		$res = ['subunidades' => $unidades];
		
		return $res;
	}



	public function putRestore($id)
	{
		$user = User::fromToken();

		// Gemelo exacto de `unidades/restore`: restaurar devuelve la subunidad con
		// su `porcentaje` a la rejilla, así que es escribir en las notas igual que
		// borrarla — y `putUpdate` y `deleteDestroy`, aquí al lado, sí lo piden.
		// Las dos salieron del mismo inventario. Ver 05 §47.
		User::pueden_editar_notas($user, PeriodoDeLaFila::deSubunidad($id));

		$consulta = 'UPDATE subunidades SET deleted_at=NULL WHERE id=?';
					
		DB::update($consulta, [$id]);

		return 'Retaurada';
	}



	public function getTrashed()
	{
		$user = User::fromToken();
		$consulta = 'SELECT m2.matricula_id, a.id as alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, 
				a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion,
				m2.year_id, m2.grupo_id, m2.nombregrupo, m2.abrevgrupo, IFNULL(m2.actual, -1) as currentyear,
				u.username, u.is_superuser, u.is_active
			FROM alumnos a left join 
				(select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 0 as actual
				from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=1
				and m.alumno_id NOT IN 
					(select m.alumno_id
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=2)
					union
					select m.id as matricula_id, g.year_id, m.grupo_id, m.alumno_id, g.nombre as nombregrupo, g.abrev as abrevgrupo, 1 AS actual
					from matriculas m INNER JOIN grupos g ON m.grupo_id=g.id and g.year_id=2
				)m2 on a.id=m2.alumno_id
			left join users u on u.id=a.user_id where a.deleted_at is not null';

		return DB::select($consulta);
	}

}