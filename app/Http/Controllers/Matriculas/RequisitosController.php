<?php namespace App\Http\Controllers\Matriculas;

use App\Services\Auditoria;
use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Matricula;
use App\Models\Acudiente;
use Carbon\Carbon;

use App\Events\MatriculasEvent;
use \Log;
use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\EstadosDelPaso;


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


	/**
	 * **LO MISMO, PERO PARA LA FAMILIA.** Pantalla 04 de `PANTALLAS-MATRICULA.md`.
	 *
	 * Es la ruta que faltaba para que el aviso signifique algo. El 20 sep se decidio
	 * que **al acudiente se le avisa en CADA estacion** (`myvc_flutter/docs/
	 * estaciones.md` §2.2 bis), y el propio diseno dice que el motivo de una
	 * devolucion *«se lee abriendo la app»*. Pero **las diecisiete rutas de este
	 * dominio eran `auth.personal`**: la familia recibia el aviso y abria la app a
	 * nada. Un aviso que apunta a una pantalla que no existe es peor que no avisar,
	 * porque ensena que los avisos no sirven.
	 *
	 * ## POR QUE ES OTRO METODO Y NO UN PARAMETRO DE `getRecorrido`
	 *
	 * Porque devuelven cosas distintas, no la misma con menos campos. `getRecorrido`
	 * es para quien atiende: lleva la observacion interna, quien cerro cada paso y a
	 * donde hay que devolver a la familia. Esto es para la familia: lleva **el motivo
	 * de la devolucion**, que es la columna que se escribio aparte precisamente para
	 * esto, y **no lleva** la observacion ni el nombre del docente.
	 *
	 * Un `if ($esFamilia)` dentro del otro metodo habria puesto las dos respuestas en
	 * un solo sitio, y el dia que alguien anada un campo tendria que acordarse de que
	 * hay un lector que no puede verlo. *El invariante de `motivo_devolucion` —lo del
	 * personal no viaja al celular de una madre— se sostiene separando las respuestas,
	 * no separando las columnas y volviendolas a juntar.*
	 *
	 * ## EL GUARD ES `boletin.propio:sin-paz-y-salvo`, Y LAS DOS MITADES IMPORTAN
	 *
	 * `boletin.propio` ya sabe la regla del negocio —*un alumno solo ve lo suyo; un
	 * acudiente, lo de sus acudidos*— y la comprueba contra `parentescos`, que es
	 * donde vive de verdad. Escribirla otra vez aqui seria una segunda copia que
	 * envejece sola.
	 *
	 * Y **`sin-paz-y-salvo` no es un descuido**: retener el boletin de quien debe es
	 * una cosa, y esconderle a una familia en que paso de la matricula va es otra. Es
	 * exactamente el mismo razonamiento que ya esta escrito para
	 * `matriculas/prematricular`, que tambien lo lleva.
	 *
	 * **El personal pasa de largo** —ese middleware solo mira a `Alumno` y
	 * `Acudiente`—, asi que secretaria puede abrir esta misma vista para ensenarsela
	 * a una madre por telefono sin cambiar de pantalla.
	 */
	public function getMiRecorrido($alumno_id)
	{
		$user = $this->user;

		if (! is_numeric($alumno_id)) {
			abort(422, 'El alumno no es válido.');
		}

		$alumno = DB::selectOne('SELECT a.id, a.nombres, a.apellidos
			FROM alumnos a WHERE a.id=? AND a.deleted_at IS NULL', [(int) $alumno_id]);

		if (! $alumno) {
			abort(404, 'Ese alumno no existe.');
		}

		// `LEFT JOIN` por lo mismo que en `getRecorrido`: el paso que nadie ha tocado
		// todavia no tiene fila, y es justo el que la familia necesita ver.
		//
		// **No se traen `ra.descripcion` ni `ra.cerrado_por`**, y eso es el contrato:
		// la observacion es entre el personal y el nombre del docente que atendio no
		// es asunto de la familia. Lo que si viaja es `motivo_devolucion`, que se
		// escribio en su propia columna para poder salir por aqui sin arrastrar lo
		// otro.
		$pasos = DB::select('SELECT r.id, r.orden AS estacion, r.requisito, r.descripcion,
				r.bloquea,
				ra.id AS marca_id, ra.estado, ra.motivo_devolucion, ra.cerrado_at
			FROM requisitos_matricula r
			LEFT JOIN requisitos_alumno ra ON ra.requisito_id=r.id AND ra.alumno_id=?
			WHERE r.year_id=? AND r.deleted_at IS NULL
			ORDER BY r.orden, r.id', [(int) $alumno->id, $user->year_id]);

		$salida = [];
		$faltan = 0;

		foreach ($pasos as $paso) {
			$estado = EstadosDelPaso::normalizar($paso->estado);

			// **`cerrado_at` y no el estado**, que es la misma eleccion que hizo la
			// cola de las estaciones y por el mismo motivo (46 §8): `estado` es una
			// columna que escriben tres pantallas con tres vocabularios, y
			// `cerrado_at` la escribe una sola rama de codigo.
			$cumplido = $paso->marca_id !== null && $paso->cerrado_at !== null;

			if (! $cumplido) {
				$faltan++;
			}

			$salida[] = [
				'estacion' => (int) $paso->estacion,
				'requisito' => $paso->requisito,
				'descripcion' => $paso->descripcion,
				'bloquea' => (bool) $paso->bloquea,
				'cumplido' => $cumplido,
				'devuelto' => $estado === 'devuelto',
				// La unica cosa que el colegio le escribe a la familia en todo el
				// recorrido. Sin ella, «devuelto» es una mala noticia sin instrucciones.
				'motivo_devolucion' => $paso->motivo_devolucion,
				'cerrado_at' => $paso->cerrado_at,
			];
		}

		return [
			// Sin documento y sin telefonos: quien pregunta ya sabe quien es, y esta
			// respuesta no tiene por que ser un sitio mas donde vive el documento de
			// un menor.
			'alumno' => ['id' => (int) $alumno->id, 'nombres' => $alumno->nombres,
				'apellidos' => $alumno->apellidos],
			'year_id' => (int) $user->year_id,
			'pasos' => $salida,
			'faltan' => $faltan,
			'completo' => $faltan === 0,
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

        Auditoria::registrar()
            ->crear('requisito_matricula', (int) $requisito->id)
            ->en(year: (int) $year_id)
            ->a(['requisito' => $requ, 'bloquea' => $bloquea, 'orden' => $orden])
            ->resumen('Creó el requisito de matrícula «'.$requ.'»'.($bloquea ? ' — bloquea' : ''))
            ->guardar();
        
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

		/*
		 * `$sets` se arma campo a campo más arriba según lo que vino, así que el
		 * resumen dice **qué columnas se movieron** y no «se actualizó». Sin eso, dos
		 * líneas seguidas de este método son indistinguibles y la pantalla no puede
		 * decir si alguien cambió el texto del requisito o si lo volvió bloqueante,
		 * que es la diferencia entre una corrección de redacción y un cambio de
		 * política de matrícula.
		 */
		Auditoria::registrar()
			->editar('requisito_matricula', (int) $id)
			->resumen('Cambió '.implode(', ', array_map(static fn ($s) => explode('=', $s)[0], $sets)).' del requisito')
			->guardar();

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

		// **EL ESTADO SE COMPRUEBA ANTES DE ESCRIBIR, Y LA CADENA VACIA NO ES UN ESTADO.**
		//
		// Esta columna guardaba literalmente lo que le mandaran, y de ahi salia un
		// fallo vivo: `prematriculas.ts::guardarObservacion` manda
		// `estado: observacion.estado ?? ''` al corregir una observacion, asi que con
		// el estado nulo en la fila --los hay, del `UPDATE` que escribia NULL hasta el
		// 1 sep 2026-- lo que llegaba era la cadena vacia. Y `''` no es `falta` ni
		// `devuelto`, o sea que entraba por la rama de cerrar: **corregir una tilde en
		// una observacion cerraba el paso y lo firmaba** con el nombre de quien
		// escribio el texto y su hora, sin error y con un 'Actualizado' de vuelta.
		//
		// Es la misma familia que el fallo del 1 sep, cometido por el otro lado: aquel
		// borraba el estado cuando no venia, este lo cerraba cuando venia vacio.
		//
		// Vacio se trata como si el campo no hubiera venido --que es lo que quiere
		// decir quien edita solo la observacion-- y lo que no esta en la lista se
		// rechaza. Los tres escritores desplegados mandan `falta`, `ya` o `n/a`, y los
		// tres estan dentro: esto no puede romper ninguna pantalla viva. El vocabulario
		// y los tres escritores medidos estan en `App\Support\EstadosDelPaso`.
		$estadoPedido = null;

		if (Request::has('estado')) {
			$crudo = Request::input('estado');

			if ($crudo !== null && ! is_scalar($crudo)) {
				abort(422, 'El estado del requisito tiene que ser un texto.');
			}

			$texto = (string) $crudo;

			if (! EstadosDelPaso::esVacio($texto)) {
				if (! EstadosDelPaso::valido($texto)) {
					abort(422, 'Ese estado no existe. Los que valen son: '.EstadosDelPaso::lista().'.');
				}

				$estadoPedido = $texto;
			}
		}

		if ($estadoPedido !== null) {
			$sets[]    = 'estado=?';
			$valores[] = $estadoPedido;
		}

		// `has` y no `filled`: un `descripcion` vacio o nulo SI es un cambio -- es como se borra
		// una observacion --, y lo que no puede tocarse es la columna que nadie nombro.
		if (Request::has('descripcion')) {
			$sets[]    = 'descripcion=?';
			$valores[] = Request::input('descripcion');
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
		if ($estadoPedido !== null) {
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
			if (! EstadosDelPaso::cierra($estadoPedido)) {
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

		Auditoria::registrar()
			->editar('requisito_alumno', (int) $id)
			->resumen('Cambió '.implode(', ', array_map(static fn ($s) => explode('=', $s)[0], $sets)).' del requisito del alumno')
			->guardar();

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

		// El texto se lee antes: borrado lógico o no, la pregunta «¿qué requisito
		// quitaron?» se contesta con el nombre, y el id suelto no se lo dice a nadie.
		$fila       = DB::selectOne('SELECT requisito, bloquea FROM requisitos_matricula WHERE id = ?', [$id]);

				DB::update($consulta, [$now, $id]);

		Auditoria::registrar()
			->borrar('requisito_matricula', (int) $id)
			->de($fila === null ? null : ['requisito' => $fila->requisito, 'bloquea' => $fila->bloquea])
			->resumen('Quitó el requisito de matrícula'.($fila === null ? '' : ' «'.$fila->requisito.'»'))
			->guardar();

		return 'Eliminado';
	}







}