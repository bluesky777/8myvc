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


	/**
	 * **EL RECORRIDO DE UN ALUMNO EL DÍA DE MATRÍCULAS.**
	 *
	 * Fase 1 del proceso de admisión, autorizada por Joseth el 20 sep 2026. Contesta
	 * la pregunta que hoy depende de que quien atiende mire bien la hoja:
	 * **¿puede atenderlo, o hay que devolverlo, y a dónde?**
	 *
	 * Su frase es el requisito literal: *«si una estación busca al estudiante y ve
	 * que el requisito 2 no está marcado y esta es la estación 4, entonces le dice
	 * que se devuelva a la estación 3»*. Es la pantalla 09 de
	 * `myvc_front/PANTALLAS-MATRICULA.md`, y es la que más tiempo ahorra del día.
	 *
	 * ## LO QUE FRENA LO DICE `bloquea`, ESTACIÓN POR ESTACIÓN
	 *
	 * Y esa granularidad no es un lujo: sale de que *«falta»* significa **dos cosas
	 * distintas según la estación** —o la familia no entregó, o nadie lo marcó—.
	 * Donde el dato es fiable el colegio enciende `bloquea` y frena de verdad; donde
	 * nadie marca lo deja apagado y **sale igualmente en `pendientes`, informando**.
	 *
	 * Un bloqueo global habría mandado de vuelta a familias que sí entregaron, **el
	 * primer día y en la cola**. Por eso `devolver_a` mira **sólo** los que bloquean,
	 * y `pendientes` los devuelve todos.
	 *
	 * ## NO ESCRIBE NADA, y por eso es un GET
	 *
	 * Mirar el recorrido de alguien no puede cambiarlo. La estación cierra su paso
	 * con `postAlumno`, que es donde queda su nombre y su hora.
	 *
	 * ## El año es el de la SESIÓN, no el de la URL
	 *
	 * Los requisitos son por año (`requisitos_matricula.year_id`) y quien atiende una
	 * estación está trabajando el día de matrículas de su año. Aceptar un año por
	 * parámetro dejaría que una estación cerrara el recorrido de una campaña vieja
	 * sin darse cuenta.
	 */
	public function getRecorrido($alumno_id)
	{
		$user = $this->user;

		if (! is_numeric($alumno_id)) {
			abort(422, 'El alumno no es válido.');
		}

		$alumno = DB::selectOne('SELECT a.id, a.nombres, a.apellidos, a.documento
			FROM alumnos a WHERE a.id=? AND a.deleted_at IS NULL', [(int) $alumno_id]);

		if (! $alumno) {
			abort(404, 'Ese alumno no existe.');
		}

		// `LEFT JOIN` y no `INNER`: **un requisito que nadie ha tocado todavía no
		// tiene fila en `requisitos_alumno`**, y es justo el que hay que enseñar.
		// Con `INNER` el recorrido de quien acaba de llegar saldría vacío, que es el
		// caso más común de la mañana.
		$pasos = DB::select('SELECT r.id, r.orden AS estacion, r.requisito, r.descripcion,
				r.bloquea,
				ra.id AS marca_id, ra.estado, ra.descripcion AS observacion,
				ra.cerrado_por, ra.cerrado_at,
				p.nombres AS cerrado_por_nombres, p.apellidos AS cerrado_por_apellidos
			FROM requisitos_matricula r
			LEFT JOIN requisitos_alumno ra ON ra.requisito_id=r.id AND ra.alumno_id=?
			LEFT JOIN users u ON u.id=ra.cerrado_por AND u.deleted_at IS NULL
			-- **`profesores.user_id`, NO `users.profesor_id`**, y no es indiferente:
			-- las dos columnas existen y **la segunda está VACÍA**. Medido en la base
			-- de tests: 0 filas de `users` con `profesor_id`, 47 de `profesores` con
			-- `user_id`. Escrito al revés, esto no habría devuelto un nombre jamás y
			-- el renglón «cerrado por» habría salido en blanco en los diecisiete sin
			-- que nada fallara — `profesores.tono` visto antes de cometerlo.
			LEFT JOIN profesores p ON p.user_id=u.id AND p.deleted_at IS NULL
			WHERE r.year_id=? AND r.deleted_at IS NULL
			ORDER BY r.orden, r.id', [(int) $alumno->id, $user->year_id]);

		$pendientes = [];
		$devolver_a = null;

		foreach ($pasos as $paso) {
			// **«Cumplido» es cualquier estado que NO sea el de partida.** El seed
			// trae `falta` y el legacy escribe lo que la pantalla mande, así que una
			// lista blanca de estados buenos se quedaría corta en silencio el día que
			// un colegio escriba «Entregado» con mayúscula. Lo que sí es seguro es
			// que `falta` —y una fila que no existe— significan que no está.
			$paso->cumplido = $paso->marca_id !== null
				&& mb_strtolower(trim((string) $paso->estado)) !== 'falta';

			$paso->bloquea = (bool) $paso->bloquea;

			if ($paso->cumplido) {
				continue;
			}

			$pendientes[] = [
				'estacion' => (int) $paso->estacion,
				'requisito' => $paso->requisito,
				'bloquea' => $paso->bloquea,
			];

			// El primero que bloquea es a donde se devuelve: ir al último sería
			// mandarla al final de un recorrido que todavía no ha hecho.
			if ($paso->bloquea && $devolver_a === null) {
				$devolver_a = ['estacion' => (int) $paso->estacion, 'requisito' => $paso->requisito];
			}
		}

		return [
			'alumno' => $alumno,
			'year_id' => (int) $user->year_id,
			'pasos' => $pasos,
			'pendientes' => $pendientes,
			// **Las dos viajan, y no es redundancia**: `puede_continuar` es lo que
			// decide el botón, y `devolver_a` es lo que hay que decirle a la familia.
			// Una pantalla que sólo tuviera la primera diría «no» sin saber a dónde
			// mandarla, que es exactamente el trabajo que esto viene a quitar.
			'puede_continuar' => $devolver_a === null,
			'devolver_a' => $devolver_a,
		];
	}

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
        
        // `orden` ES el número de estación que va impreso en la cartulina —decidido
        // por Joseth el 20 sep 2026—, y `bloquea` es si esa estación es «obligatoria
        // antes de continuar» u «opcional». Los dos son opcionales aquí: una llamada
        // de la pantalla vieja sigue creando un requisito que no frena a nadie, que
        // es exactamente el comportamiento de antes.
        $orden   = (int) (Request::input('orden') ?? 0);
        $bloquea = Request::boolean('bloquea') ? 1 : 0;

        $consulta = 'INSERT INTO requisitos_matricula(requisito, descripcion, orden, bloquea, updated_by, created_at, updated_at, year_id) 
            VALUES(?,?,?,?,?,?,?,?)';
        DB::insert($consulta, [$requ, $descrip, $orden, $bloquea, $this->user->user_id, $now, $now, $year_id]);
        
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
		
		// **`orden` y `bloquea` sólo se escriben si VIENEN**, y esto no es simetría con
		// `postAlumno`: es lo que impide que la pantalla vieja los apague sin querer.
		//
		// Esa pantalla está desplegada en los dieciséis colegios y manda `requisito` y
		// `descripcion` y nada más. Escritos incondicionalmente, cada vez que alguien
		// corrigiera una tilde en el nombre de un paso **le pondría `orden=0` y
		// `bloquea=0`** — o sea, desharía el recorrido del día de matrículas al
		// editar un texto, sin error y sin que nadie lo note hasta la cola.
		//
		// Es el mismo caso que el `valor` del formulario de inscripción
		// ([41 §5.ter](../../../docs/migracion/41-el-formulario-de-inscripcion.md)),
		// donde la pantalla vieja «no revienta: apaga el cobro sin querer». Ahí se
		// avisó; aquí se impide.
		$sets    = ['requisito=?', 'descripcion=?'];
		$valores = [$requ, $descrip];

		if (Request::has('orden')) {
			$sets[]    = 'orden=?';
			$valores[] = (int) Request::input('orden');
		}

		if (Request::has('bloquea')) {
			$sets[]    = 'bloquea=?';
			$valores[] = Request::boolean('bloquea') ? 1 : 0;
		}

		$sets[]    = 'updated_by=?';
		$valores[] = $this->user->user_id;
		$sets[]    = 'updated_at=?';
		$valores[] = $now;
		$valores[] = $id;

		DB::update('UPDATE requisitos_matricula SET '.implode(', ', $sets).' WHERE id=?', $valores);
		
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

		// **La firma de quien CIERRA, que no es `updated_by`.** Decidido por Joseth el
		// 20 sep 2026: «cualquiera del personal puede cerrar, pero queda con su
		// nombre y hora».
		//
		// `updated_by` cambia cada vez que alguien toca la fila —corregir una
		// observación, una tilde, desmarcar— así que al final del día dice **quién
		// pasó por aquí el último**, no quién chuleó. Son dos preguntas distintas y
		// la del día de matrículas es la segunda.
		//
		// Sólo se escribe cuando el estado deja de ser «falta» **y no estaba cerrado
		// ya**: reabrir y volver a cerrar deja la firma de quien lo cerró de verdad,
		// y tocar la observación de algo ya cerrado no reescribe su hora.
		if (Request::has('estado')) {
			$pedido = mb_strtolower(trim((string) Request::input('estado')));

			// **REABRIR UN PASO TIENE QUE LIMPIAR LA FIRMA, y hasta el 20 sep 2026 no
			// lo hacía.** `cerrado_at` se escribe con `COALESCE`, o sea **una sola
			// vez**: devolver un paso a «falta» dejaba la fecha puesta para siempre.
			//
			// Con el recorrido de la fase 1 eso no se notaba —`getRecorrido` mira
			// `estado`, no `cerrado_at`—, pero **la cola de las estaciones se apoya
			// entera en `cerrado_at`**, precisamente porque `estado` es un `varchar`
			// sin vocabulario cerrado que escriben tres pantallas viejas (46 §2).
			//
			// Sin esta rama, desmarcar a alguien lo dejaba **invisible en su estación
			// y visible en la siguiente**: la cola de la 3 lo sigue teniendo por
			// cerrado y la 2 ya no lo ve. La familia espera de pie en una fila en la
			// que el sistema dice que no está. Lo dejó escrito el 46 §5 como «una
			// línea en `postAlumno`», y es ésta.
			//
			// **`devuelto` cuenta como reabrir**, y por el mismo motivo: un paso
			// devuelto es un paso que se sigue debiendo.
			$reabre = $pedido === 'falta' || $pedido === 'devuelto';

			if ($reabre) {
				$sets[] = 'cerrado_por=NULL';
				$sets[] = 'cerrado_at=NULL';
			} else {
				$sets[]    = 'cerrado_por=COALESCE(cerrado_por,?)';
				$valores[] = $this->user->user_id;
				$sets[]    = 'cerrado_at=COALESCE(cerrado_at,?)';
				$valores[] = $now;
			}
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