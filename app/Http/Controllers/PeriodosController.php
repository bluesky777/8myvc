<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use App\Support\CierreDeLoNoCalificado;
use App\Support\AuditarFila;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

use App\User;
use App\Models\Periodo;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Nota;
use \stdClass;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class PeriodosController extends Controller {
	use ResuelveElUsuario;

	public function getIndex()
	{
		$consulta = 'SELECT * FROM periodos WHERE deleted_at is null and year_id=? order by numero';
		return DB::select($consulta, [ $this->user->year_id ]);
	}

	public function postStore($year_id)
	{
		$periodo = new Periodo;
		$periodo->numero						=	Request::input('numero');
		$periodo->fecha_inicio					=	Request::input('fecha_inicio');
		$periodo->fecha_fin						=	Request::input('fecha_fin');
		$periodo->actual						=	0;
		$periodo->year_id						=	$year_id;
		$periodo->profes_pueden_editar_notas	=	1;
		$periodo->profes_pueden_nivelar			=	1;
		$periodo->fecha_plazo					=	Request::input('fecha_plazo');

		$periodo->save();
		AuditarFila::creada('periodo', 'periodos', (int) $periodo->id, (int) $year_id, 'Creó el periodo '.$periodo->numero);

		return $periodo;
		
	}

	public function getShow($year_id)
	{
		return Periodo::where('year_id', $year_id)->get();
	}

	public function putUpdate($id)
	{
		$periodo = Periodo::findOrFail($id);

		$periodo->numero			=	Request::input('numero');
		$periodo->fecha_inicio		=	Request::input('fecha_inicio');
		$periodo->fecha_fin			=	Request::input('fecha_fin');
		$periodo->actual			=	Request::input('actual');
		$periodo->year				=	Request::input('year');
		$periodo->fecha_plazo		=	Request::input('fecha_plazo');
		$periodo->updated_by 		= 	$this->user->user_id;

		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, fn () => $periodo->save(), (int) $periodo->year_id, 'Cambió los datos del periodo');

		return $periodo;
	}

	public function putCambiarFechaInicio()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->fecha_inicio	=	Carbon::parse(Request::input('fecha'));
		$periodo->updated_by 	= 	$this->user->user_id;
		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, fn () => $periodo->save(), (int) $periodo->year_id, 'Cambió la fecha de inicio del periodo');

		return 'Cambiado';
	}

	public function putCambiarFechaFin()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->fecha_fin		=	Carbon::parse(Request::input('fecha'));
		$periodo->updated_by 	= 	$this->user->user_id;
		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, fn () => $periodo->save(), (int) $periodo->year_id, 'Cambió la fecha de fin del periodo');

		return 'Cambiado';
	}

	/**
	 * **El día en que se entregan los boletines del periodo** (migración
	 * `2026_09_24_700000_la_fecha_de_entrega_de_boletines`). Misma forma que sus dos
	 * hermanas de arriba, con dos diferencias: `fecha` vacía o `null` **borra** la fecha
	 * —«sin fecha» es un valor, no un error—, y lo que no es una fecha `AAAA-MM-DD` real
	 * sale con 422 en vez de guardarse. `Carbon::parse` de arriba aceptaría «mañana».
	 */
	public function putCambiarFechaEntregaBoletines()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$fecha   = Request::input('fecha');

		if ($fecha === null || $fecha === '') {
			$fecha = null;
		} else {
			$leida = is_string($fecha) ? \DateTime::createFromFormat('!Y-m-d', $fecha) : false;
			if (! $leida || $leida->format('Y-m-d') !== $fecha) {
				abort(422, 'La fecha de entrega de boletines tiene que ser una fecha (AAAA-MM-DD) o ir vacía.');
			}
		}

		$periodo->fecha_entrega_boletines	=	$fecha;
		$periodo->updated_by 				= 	$this->user->user_id;
		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, fn () => $periodo->save(), (int) $periodo->year_id,
			$fecha === null ? 'Quitó la fecha de entrega de boletines' : 'Cambió la fecha de entrega de boletines');

		return 'Cambiado';
	}

	/**
	 * Abrir y cerrar el periodo — y, desde el 20 sep 2026, **aplicar la decisión del
	 * colegio sobre las casillas que nadie calificó**.
	 *
	 * Fase 4 de [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md),
	 * **D3**. Ésta es la ruta donde muere el hueco, y no una ruta nueva: *cerrar* ya
	 * existía y ya es este interruptor. Lo que cambia es que ahora el cierre **hace
	 * algo** con lo que queda vacío, en vez de dejarlo valiendo cero por accidente.
	 *
	 * ```
	 * cero      las casillas vacías se escriben como 0 de verdad
	 * fuera     se quedan vacías y la definitiva del periodo pasa a ser la parcial
	 * bloquear  no se cierra: 422 diciendo cuántas faltan
	 * ```
	 *
	 * ## El orden de las tres cosas no es arbitrario
	 *
	 * 1. **`bloquear` corta antes de escribir nada.** Un 422 después de haber cerrado
	 *    sería el peor de los dos mundos: el periodo cerrado y el usuario creyendo que
	 *    no.
	 * 2. **La marca se congela en la misma escritura que el cierre.** Desde ese
	 *    instante `calcular()` dice la verdad sobre este periodo, así que si lo de
	 *    abajo se corta a la mitad no queda ninguna definitiva mal calculada — sólo
	 *    alguna sin rehacer.
	 * 3. **La consecuencia se aplica después.** Con `cero` es un `UPDATE` (0,49 s para
	 *    20.655 filas, medido en la fase 0); con `fuera` son dos consultas por
	 *    definitiva ({@see \App\Services\DefinitivasDeAsignatura::rehacerElPeriodo}).
	 *
	 * ## Un periodo YA cerrado no se mueve, y tampoco cuando se vuelve a pulsar
	 *
	 * La regla dura del encargo, hecha mecanismo y no norma. Hay tres casos y sólo dos
	 * hacen algo:
	 *
	 * - **abierto -> cerrado**: se elige la política del año y se aplica. Es el cierre.
	 * - **cerrado -> cerrado, con marca**: se **reanuda** lo que se empezó, con **la
	 *   marca congelada y no con la elección vigente del rector**. Es lo que permite
	 *   terminar un cierre que se cortó, y lo que impide que volver a pulsar cambie de
	 *   política a media obra.
	 * - **cerrado -> cerrado, sin marca**: **no se toca nada.** Son todos los periodos
	 *   de los dieciséis colegios cerrados antes de que esto existiera. Ponerles ceros
	 *   «porque de todas formas valían cero» movería `updated_by` y `updated_at` de un
	 *   millón de filas de periodos cuyas definitivas están impresas y firmadas.
	 *
	 * Reabrir (`pueden = 1`) no borra la marca y no hace falta que la borre:
	 * `normalizaLaDefinitiva()` exige **cerrado y** marcado, así que un periodo
	 * reabierto vuelve solo al cálculo de siempre. Volver a cerrarlo vuelve a elegir.
	 *
	 * ## Sigue devolviendo TEXTO, y eso es contrato
	 *
	 * Los tres clientes la llaman y los dos fronts la tienen declarada como endpoint de
	 * texto (`myvc_front/scripts/endpoints-de-texto.json`, `api.putTexto` en `app2`).
	 * Devolver un objeto con el recuento habría sido más útil aquí dentro y **le habría
	 * pintado un JSON crudo al usuario** en las tres pantallas. La cuenta va dentro de
	 * la frase, que es donde el cliente ya sabe ponerla.
	 */
	public function putToggleProfesPuedenEditarNotas()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));

		// **Se lee con `(int)` y no con `(bool)`**, que es lo que hacen los doce
		// interruptores del año. Aquí la diferencia importa: lo que decide esta rama
		// tiene que ser **lo mismo que acaba guardado en la columna**, y lo que se
		// guarda es `Request::input('pueden')` crudo sobre un `tinyint` —o sea la
		// conversión de MySQL, que para `"false"` da 0—. Con `(bool)` la cadena
		// `"false"` sería «abierto» aquí y 0 en la base: el periodo se cerraría **sin
		// aplicar ninguna política**, que es el agujero exacto que esta fase viene a
		// tapar, abierto por la lectura de un campo.
		$quedaAbierto = (int) Request::input('pueden') === 1;

		$estado = CierreDeLoNoCalificado::estadoDelPeriodo($periodo->id);
		$cerrando = $estado['abierto'] && ! $quedaAbierto;
		$reanudando = ! $estado['abierto'] && ! $quedaAbierto && $estado['congelado'] !== null;

		$salida = null;

		if ($cerrando) {
			$salida = CierreDeLoNoCalificado::elegidoParaElPeriodo($periodo->id);
		} elseif ($reanudando) {
			$salida = $estado['congelado'];
		}

		// **El 422 va antes de tocar la fila**, que es la mitad de lo que hace útil a
		// `bloquear`: el colegio que lo elige quiere que no se pueda cerrar, no que se
		// cierre y le avisen. El mensaje lleva la cuenta dentro porque quien la reciba
		// tiene que poder ir a buscarlas —el desglose está en
		// `GET periodos/sin-calificar/{periodo_id}`—; un «no se puede cerrar» a secas
		// manda a alguien a buscar un permiso que no falta.
		if ($salida === CierreDeLoNoCalificado::BLOQUEAR) {
			$faltan = CierreDeLoNoCalificado::cuantasSinCalificar((int) $periodo->id);

			if ($faltan > 0) {
				abort(422, 'No se puede cerrar el periodo: quedan '.$faltan
					.' casillas sin calificar. El colegio eligió no dejar cerrar hasta '
					.'que estén todas. Puede verlas en periodos/sin-calificar/'.$periodo->id.'.');
			}

			// Sin nada pendiente, `bloquear` ya consiguió lo que quería. Se congela
			// como `cero` porque es lo que describe el resultado —no quedó ninguna
			// fuera de la cuenta— y porque `bloquear` no es un estado en el que un
			// periodo pueda quedarse: el `enum` de `periodos` tiene dos valores
			// justamente para que eso no sea representable.
			$salida = CierreDeLoNoCalificado::CERO;
		}

		// **Los ceros sólo con el sí explícito de quien cierra** (`poner_ceros: 1`). Sin él,
		// cerrar deja las vacías como están y fuera de la cuenta. El 24 sep 2026 en quibdo
		// dos cierres desde la app vieja --que no pregunta nada-- pusieron a 0 3.094
		// casillas que los docentes habían vaciado a propósito.
		if ($salida === CierreDeLoNoCalificado::CERO
			&& (int) Request::input('poner_ceros') !== 1
			&& CierreDeLoNoCalificado::cuantasSinCalificar((int) $periodo->id) > 0) {
			$salida = CierreDeLoNoCalificado::FUERA;
		}

		$periodo->profes_pueden_editar_notas	=	Request::input('pueden');

		if ($salida !== null) {
			$periodo->cierre_sin_calificar = $salida;
		}

		$periodo->updated_by 					=	$this->user->user_id;
		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, fn () => $periodo->save(), (int) $periodo->year_id,
			$quedaAbierto ? 'Abrió el periodo para que los docentes editen notas' : 'Cerró el periodo a los docentes');

		if ($salida === null) {
			// **Reabrir un periodo cerrado en `cero` cambia la fórmula**: abierto,
			// la definitiva vuelve a dividir por lo evaluado (`normalizaLaDefinitiva`),
			// y con unidades que no suman 100 eso es otro número. Censo del 25 sep 2026.
			if ($quedaAbierto && ! $estado['abierto'] && $estado['congelado'] === CierreDeLoNoCalificado::CERO) {
				DefinitivasDeAsignatura::rehacerElPeriodo((int) $periodo->id, $this->user->user_id);
			}

			return 'Cambiado';
		}

		if ($salida === CierreDeLoNoCalificado::CERO) {
			$puestas = CierreDeLoNoCalificado::pasarACero(
				(int) $periodo->id, $this->user->user_id
			);

			// **Y la definitiva SÍ se mueve, desde el 22 sep 2026.** Aquí decía que no,
			// porque la definitiva no normalizaba y `SUM(peso × NULL)` y `SUM(peso × 0)`
			// eran el mismo número. Desde que abierto se divide por lo evaluado, cerrar
			// en `cero` cambia la fórmula —deja de dividir— y la definitiva guardada es
			// la de la fórmula de antes, sin que ningún `updated_at` lo diga. Se rehace
			// como en la rama `fuera`.
			DefinitivasDeAsignatura::rehacerElPeriodo(
				(int) $periodo->id, $this->user->user_id
			);

			// Lo que cambia además es que a partir de ahora la cobertura del periodo es
			// del 100 %, la parcial coincide con lo que imprime el boletín y el semáforo
			// deja de estar gris. *En un periodo cerrado, lo que ve la familia y lo que
			// dice el papel vuelven a ser el mismo número.*
			return 'Periodo cerrado. '.$puestas
				.' casillas sin calificar pasaron a cero.';
		}

		$hecho = DefinitivasDeAsignatura::rehacerElPeriodo(
			(int) $periodo->id, $this->user->user_id
		);

		return 'Periodo cerrado dejando fuera de la cuenta lo no calificado. '
			.$hecho['escritas'].' definitivas rehechas en '.$hecho['asignaturas']
			.' asignaturas'.($hecho['respetadas'] > 0
				? ' ('.$hecho['respetadas'].' manuales o recuperadas sin tocar).'
				: '.');
	}

	/*
	 * **El cierre ya no fotografía el puesto** (Joseth, 24 sep 2026). Aquí vivía
	 * `congelarLosPuestos()`, que escribía `puestos_del_cierre` al cerrar y al reanudar
	 * (fase 4 del `PLAN-CIERRE-DE-PERIODO.md`, commit ff370d2). Se quitó el mismo día:
	 * el puesto se calcula al vuelo siempre, como la definitiva — «si un directivo edita
	 * una nota en una emergencia, el informe se recalcula aunque salga distinto del
	 * impreso; lo importante es que el historial queda». La tabla se queda, vacía y sin
	 * lector; ver `BoletinIndependiente::ponerPuestos`.
	 */

	/**
	 * Qué casillas de este periodo no ha calificado nadie. **El diálogo de cierre.**
	 *
	 * `GET periodos/sin-calificar/{periodo_id}`, fase 4 del
	 * [43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md). Es la ruta
	 * que contesta lo que el plan llama *«el diálogo de cierre pregunta qué son las
	 * casillas que quedan»*: antes de cerrar hay que poder ver cuántas son, de quién
	 * son y qué les va a pasar.
	 *
	 * ## Por qué hace falta una ruta y no valía ninguna de las 623
	 *
	 * Porque **nadie cuenta casillas vacías**. `Informes\NotasPerdidasController` cuenta
	 * notas perdidas —que es lo contrario: una nota puesta y baja—, y la planilla las
	 * enseña de una asignatura en una, que es justo lo que no sirve para decidir un
	 * cierre. Lo que no existía es la pregunta al nivel del periodo.
	 *
	 * ## Y contesta también «qué va a pasar», que es la mitad que evita el susto
	 *
	 * `salida` es lo que aplicaría el cierre hoy y `descripcion` lo dice en castellano.
	 * Sin eso, la misma pantalla con las mismas 19.735 casillas significa *«se van a
	 * poner en cero»* en un colegio y *«no vas a poder cerrar»* en el de al lado, y no
	 * hay forma de saber cuál. **Una decisión que no se ve antes de aplicarse la acaba
	 * descubriendo quien pulsa.**
	 *
	 * Con el periodo **ya cerrado** devuelve lo que se aplicó (`congelado`) en vez de lo
	 * que se aplicaría, porque en un periodo cerrado la pregunta ya tiene respuesta.
	 *
	 * ## `auth.personal` y nada dentro, y es una decisión
	 *
	 * Al contrario que `PUT years/cierre-sin-calificar`, que lleva permiso dentro. La
	 * diferencia es que **esto se lee y aquello se decide**: quien cierra el periodo son
	 * las 74 cuentas de personal, así que el diálogo que va justo antes del cierre tiene
	 * que alcanzar a las mismas 74 — un diálogo más estrecho que el botón que precede
	 * dejaría a secretaría cerrando a ciegas. Y un docente que quiera ver qué le falta
	 * por calificar no está viendo nada que su propia planilla no le enseñe.
	 *
	 * Lo que sí hay que saber si algún día se estrecha: el desglose **nombra al docente
	 * de cada asignatura**, así que dice de quién es el silencio. Eso es deliberado —es
	 * el aviso que el colegio necesita a mitad de periodo y lo que hoy nadie dice—, pero
	 * es también la única parte de esta respuesta que habla de personas.
	 */
	public function getSinCalificar($periodo_id)
	{
		$estado = CierreDeLoNoCalificado::estadoDelPeriodo($periodo_id);

		// 404 y no una respuesta vacía: «ese periodo no existe» y «ese periodo lo tiene
		// todo calificado» son dos hechos distintos, y con un `0` suelto se leen igual.
		// Es la regla de la casa sobre las poblaciones — un «0 encontrados» no distingue
		// *«revisé y no había»* de *«no revisé nada»*.
		if (! $estado['existe']) {
			abort(404, 'Ese periodo no existe.');
		}

		$periodoId = (int) $periodo_id;

		$salida = $estado['abierto']
			? CierreDeLoNoCalificado::elegidoParaElPeriodo($periodoId)
			: $estado['congelado'];

		return [
			'periodo_id' => $periodoId,
			'abierto' => $estado['abierto'],
			'salida' => $salida,
			'congelado' => $estado['congelado'],
			'descripcion' => self::comoSeLee($salida, $estado['abierto']),
			'casillas' => CierreDeLoNoCalificado::cuantasSinCalificar($periodoId),
			'asignaturas' => CierreDeLoNoCalificado::porAsignatura($periodoId),
		];
	}

	/**
	 * La frase que acompaña a `salida`, para que la pantalla no tenga que inventarla.
	 *
	 * Vive aquí y no en el cliente porque son **cuatro** clientes y la frase describe
	 * una decisión del backend: escrita en cada uno, el día que la decisión cambie de
	 * matiz habría cuatro pantallas diciendo cuatro cosas. Es lo mismo que hace
	 * `Autoriza::exigir` con los mensajes de permiso.
	 */
	private static function comoSeLee(?string $salida, bool $abierto): string
	{
		if ($salida === null) {
			return $abierto
				? 'Este periodo todavía no tiene elegido qué hacer con lo no calificado.'
				: 'Este periodo se cerró antes de que existiera esta decisión: '
					.'lo no calificado quedó valiendo cero.';
		}

		$frases = [
			CierreDeLoNoCalificado::CERO => $abierto
				? 'Al cerrar se preguntará si lo que no se haya calificado pasa a cero; '
					.'si no se confirma, queda fuera de la cuenta.'
				: 'Al cerrar, lo que no se había calificado pasó a cero.',
			CierreDeLoNoCalificado::FUERA => $abierto
				? 'Al cerrar, lo que no se haya calificado quedará fuera de la cuenta: '
					.'la definitiva se calculará sobre lo evaluado.'
				: 'Lo que no se había calificado quedó fuera de la cuenta: '
					.'la definitiva está calculada sobre lo evaluado.',
			CierreDeLoNoCalificado::BLOQUEAR =>
				'No se podrá cerrar el periodo mientras quede algo sin calificar.',
		];

		return $frases[$salida] ?? '';
	}

	public function putToggleProfesPuedenNivelar()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->profes_pueden_nivelar	=	Request::input('pueden');
		$periodo->updated_by 			= 	$this->user->user_id;
		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, fn () => $periodo->save(), (int) $periodo->year_id,
			(int) Request::input('pueden') === 1 ? 'Dejó a los docentes nivelar en el periodo' : 'Quitó a los docentes el nivelar en el periodo');

		return 'Cambiado';
	}

	public function putUseractive($periodo_id)
	{
		// El periodo tiene que existir **y no estar en la papelera**, y las dos
		// mitades cuestan distinto. Sin la primera, `users.periodo_id` se lo come
		// hasta que salta la clave ajena: 500 con el SQLSTATE dentro. La segunda es
		// la cara: la clave ajena **no filtra `deleted_at`**, así que un periodo
		// borrado entraba y contestaba 200, y el usuario se quedaba aparcado en un
		// periodo que no sale en ningún selector. Medido con el mismo token: sus
		// pantallas se vacían —0 grupos, 0 asignaturas— **en 200**, sin un error que
		// lo explique. Se sale volviendo a entrar, porque
		// `Services\Login::ponerEnElPeriodoActual` lo devuelve al periodo actual; eso
		// no lo adivina nadie desde una pantalla vacía.
		//
		// Mudarse a un periodo vivo de OTRO año sigue estando permitido: es lo que
		// hace el selector de la barra de arriba, y lo llama también la app de
		// Flutter (`ContextoAcademico.cambiarPeriodo`). Ver §95.
		Periodo::findOrFail($periodo_id);

		$usuario = User::findOrFail($this->user->user_id);
		$usuario->periodo_id 	= $periodo_id;
		$usuario->updated_by 	= 	$this->user->user_id;
		$usuario->save();

		return $usuario;
	}


	public function putEstablecerActual($periodo_id)
	{
		$periodoACambiar = Periodo::findOrFail($periodo_id);
		
		// Una línea sólo por el periodo que pasa a actual.
		AuditarFila::cambio('periodo', 'periodos', (int) $periodoACambiar->id, function () use ($periodoACambiar) {
			$periodos = Periodo::where('year_id', $periodoACambiar->year_id)->get();

			foreach ($periodos as $periodo) {
				
				if ($periodo->id != $periodoACambiar->id) {
					$periodo->actual = 0;
					$periodo->save();
				}
				
			}

			$periodoACambiar->actual 		= 1;
			$periodoACambiar->updated_by 	= $this->user->user_id;
			$periodoACambiar->save();
		}, (int) $periodoACambiar->year_id, 'Marcó el periodo como actual');

		return $periodoACambiar;
	}


	/*
	 * Copiar era la puerta de atrás del candado del periodo, y la única.
	 *
	 * Este método crea unidades, subunidades y —si se lo piden— **notas** en
	 * `periodo_to_id`, que llega en el cuerpo. Las rutas normales que hacen eso
	 * mismo de una en una sí piden permiso: `unidades/store`, `unidades/update`,
	 * `subunidades/store` y `subunidades/update` llaman todas a
	 * `pueden_editar_notas` desde la §27. O sea que un profesor no podía crear una
	 * unidad en un periodo cerrado a mano, y sí copiando treinta de golpe.
	 *
	 * Por eso el permiso se pide para el periodo **destino** y no para el origen:
	 * del origen sólo se lee. Es la regla de la §27 —el permiso del sitio al que
	 * se escribe— y la misma que Joseth aplicó el 22 ago a
	 * `detalles/eliminar-notas-periodo` (§77).
	 *
	 * **Lo encontró una herramienta que estaba mal.** `tools/escrituras-en-las-notas.py`
	 * se escribió esa misma mañana para la §77 y sólo miraba SQL crudo; aquí las
	 * notas se escriben con `new Nota` y `save()`, así que no la vio. Salió una hora
	 * después leyendo otra cosa. Ver 05 §80.
	 */
	public function putCopiar()
	{
		$grupo_from_id 		= Request::input('grupo_from_id');
		$grupo_to_id 		= Request::input('grupo_to_id');
		$asignatura_to_id	= Request::input('asignatura_to_id');
		$copiar_subunidades	= Request::input('copiar_subunidades');
		$copiar_notas		= Request::input('copiar_notas');
		$periodo_from_id	= Request::input('periodo_from_id');
		$periodo_to_id		= Request::input('periodo_to_id');
		$unidades_ids		= Request::input('unidades_ids');

		User::pueden_editar_notas($this->user, $periodo_to_id ? (int) $periodo_to_id : null,
			$asignatura_to_id ? (int) $asignatura_to_id : null);

		/*
		 * Copiar la estructura tiene que llevarse TAMBIÉN las unidades con dueño, y
		 * es la §9.4 de 19-boletin-independiente.md.
		 *
		 * `unidades_ids` la arma el front desde la pantalla de estructura, y esa
		 * pantalla enseña **la del grupo**: las de un independiente no están en la
		 * lista y nadie las echa de menos hasta abrir su boletín. Si no se copian, el
		 * periodo nuevo empieza con el marcado sin una sola unidad, su definitiva sale
		 * 0 y **nadie recibe un error** — es la §9.1 entrando por la puerta de copiar.
		 *
		 * **Quién cuenta es el periodo DESTINO y no el de origen.** La marca es por
		 * periodo desde el 31 ago 2026 (decisión 7): un alumno que fue aparte en el 1
		 * y vuelve con el grupo en el 2 **no** se lleva sus unidades al 2, o el
		 * boletín del segundo periodo le saldría aparte sin que nadie lo pidiera.
		 *
		 * Y por eso la lista sale de `delGrupo(destino)` en vez de escribir la
		 * condición a mano: contesta las dos mitades de una vez —alumnos **del grupo
		 * destino** y marcados **en el periodo destino**—, así que copiar a otro grupo
		 * deja la lista vacía sin una línea más. Es la respuesta correcta y no una
		 * casualidad: el dueño de esas unidades no es alumno del grupo al que se copia,
		 * que es el mismo motivo por el que las notas ya no se copiaban entre grupos.
		 */
		$independientes = ($grupo_to_id && $periodo_to_id)
			? BoletinIndependiente::delGrupo((int) $grupo_to_id, (int) $periodo_to_id)['independientes']
			: [];

		$unidades_ids = array_values(array_unique(array_map('intval', (array) $unidades_ids)));
		$de_independientes = $this->unidadesConDuenoQueAcompanan($unidades_ids, $independientes);

		$unidades_copiadas = 0;
		$unidades_de_independientes_copiadas = 0;
		$subunidades_copiadas = 0;
		$notas_copiadas = 0;


		foreach (array_merge($unidades_ids, $de_independientes) as $unidad_id) {

			$unidad_curr = Unidad::findOrFail($unidad_id);

			// Una unidad cuyo dueño ya NO va aparte en el destino no se copia: allí no
			// la leería nadie —`alcance()` devolvería NULL para él— y quedaría como una
			// fila muerta que sí cuenta para «esta asignatura tiene unidades».
			if ($unidad_curr->alumno_id !== null
				&& ! in_array((int) $unidad_curr->alumno_id, $independientes, true)) {
				continue;
			}

			$unidad_new = new Unidad;

			// **El dueño viaja con la unidad.** Sin esta línea, copiar la de un
			// independiente creaba una **del grupo** con su contenido: la forma «de más»
			// de la §9.2, y las definitivas de los treinta salen infladas sin que se mueva
			// nada en el log. Hoy no se ve porque no hay ninguna unidad con dueño.
			$unidad_new->alumno_id 		= $unidad_curr->alumno_id;
			$unidad_new->definicion 	= $unidad_curr->definicion;
			$unidad_new->porcentaje 	= $unidad_curr->porcentaje;
			$unidad_new->orden 			= $unidad_curr->orden;
			$unidad_new->created_by 	= $this->user->user_id;
			$unidad_new->periodo_id 	= $periodo_to_id;
			$unidad_new->asignatura_id 	= $asignatura_to_id;

			$unidad_new->save();

			// **`unidades_copiadas` sigue contando lo que pidió el front, y sólo eso.**
			// Un campo que ya se lee no cambia de significado en silencio: los tres
			// consumidores de esta respuesta —`UnidadesCtrl`, `CopiarCtrl` y la página de
			// `app2`— **se lo enseñan al docente** («Unidades copiadas: N») justo después
			// de que él haya marcado una lista con la mano. Medido el 31 ago 2026 en
			// `myvc_front`: ninguno de los tres lo compara contra `unidades_ids.length`
			// en código, así que **nada se rompería**; quien reconcilia es la persona, y
			// un número mayor que lo que marcó no lo puede contar en su lista.
			if (in_array($unidad_id, $de_independientes, true)) {
				$unidades_de_independientes_copiadas++;
			} else {
				$unidades_copiadas++;
			}


			if ($copiar_subunidades) {
				$subunidades = Subunidad::deUnidad($unidad_id);
				
				foreach ($subunidades as $subunidad) {
					$sub_new = new Subunidad;
					$sub_new->definicion 	= $subunidad->definicion_subunidad;
					$sub_new->porcentaje 	= $subunidad->porcentaje_subunidad;
					$sub_new->unidad_id 	= $unidad_new->id;
					$sub_new->nota_default 	= $subunidad->nota_default;
					$sub_new->orden 		= $subunidad->orden_subunidad;
					$sub_new->inicia_at 	= $subunidad->inicia_at;
					$sub_new->finaliza_at 	= $subunidad->finaliza_at;
					$sub_new->created_by 	= $this->user->user_id;

					$sub_new->save();
					$subunidades_copiadas++;


					if ($copiar_notas and $grupo_to_id==$grupo_from_id) {
					
						$notas = Subunidad::notas($subunidad->subunidad_id);

						foreach ($notas as $nota) {
							$nota_new = new Nota;
							$nota_new->nota 		= $nota->nota;
							$nota_new->subunidad_id = $sub_new->id;
							$nota_new->alumno_id 	= $nota->alumno_id;
							$nota_new->created_by 	= $this->user->user_id;
							
							$nota_new->save();
							$notas_copiadas++;

						}
					}
				}

			}
			

		}
		
		// Fase 3 de 10-definitivas.md: **copiar mueve unidades y hasta hoy no
		// avisaba a nadie.** Traer la estructura de otro periodo cambia los pesos
		// del periodo destino —y con `copiar_notas`, también las notas—, así que
		// las definitivas de ahí quedaban calculadas con lo que había antes.
		//
		// Se recalcula **la asignatura destino entera**, no por alumno: lo que
		// cambió es la estructura, que afecta a todos los del grupo por igual.
		if ($asignatura_to_id && $periodo_to_id) {
			DefinitivasDeAsignatura::recalcular(
				(int) $asignatura_to_id,
				(int) $periodo_to_id,
				$this->user->user_id
			);
		}

		$res = new stdClass;
		$res->unidades_copiadas		= $unidades_copiadas;
		// Campo **añadido**, no cambiado: quien no lo lea sigue funcionando igual, y `0`
		// es la respuesta honesta mientras no haya nadie marcado — que es hoy, en los
		// quince colegios.
		$res->unidades_de_independientes_copiadas = $unidades_de_independientes_copiadas;
		$res->subunidades_copiadas	= $subunidades_copiadas;
		$res->notas_copiadas		= $notas_copiadas;
		
		
		
		// La respuesta repinta **la estructura del grupo**, y por eso `alumno_id IS
		// NULL` en vez de un alcance: aquí no hay ningún alumno en el ámbito, así que
		// la única respuesta con significado es la del grupo. Sin la condición, las
		// unidades de un independiente entrarían mezcladas y **sin nada que las
		// distinga** —la consulta nombra columnas y `alumno_id` no está entre ellas—,
		// o sea filas que el cliente no puede atribuir a nadie.
		//
		// Lo copiado para los independientes se cuenta aparte, en
		// `unidades_de_independientes_copiadas`, y por eso no sale aquí tampoco.
		$consulta = 'SELECT id, definicion, porcentaje, orden 
					FROM unidades
					where asignatura_id=:asignatura_id and periodo_id=:periodo_id and deleted_at is null
						and alumno_id is null
					order by orden';

		$unidades = DB::select($consulta, [
			':asignatura_id'	=> $asignatura_to_id,
			':periodo_id'		=> $periodo_to_id
		]);


		foreach ($unidades as $unidad) {

			$consulta = 'SELECT id, definicion, porcentaje, orden, "0" as cantNotas 
						FROM subunidades
						where unidad_id=:unidad_id and deleted_at is null
						order by orden';

			$unidad->subunidades = DB::select($consulta, [':unidad_id'	=> $unidad->id]);


		}
			
		$res->unidades		= $unidades;
			

		return (array)$res;
	}

	/**
	 * **Las unidades con dueño que acompañan a la lista que pidió el front.**
	 *
	 * Es la otra mitad de la §9.4: el bucle de arriba sabe respetar un dueño, pero
	 * la lista que le llega nunca trae ninguno, porque la pantalla de estructura del
	 * docente enseña la del grupo. Aquí salen las que faltan, del mismo par
	 * (asignatura, periodo) del que se está copiando.
	 *
	 * **Devuelve sólo las añadidas** —no la lista entera— porque el que llama tiene
	 * que poder contarlas aparte: son las de `unidades_de_independientes_copiadas`.
	 *
	 * **De dónde sale el origen: de las unidades pedidas, no de `periodo_from_id`.**
	 * Ese campo del cuerpo llega y `putCopiar` no lo usa para nada —el bucle va por
	 * id—, así que nadie garantiza que case con la lista; apoyarse en él sería
	 * estrenar una dependencia que hoy nadie comprueba. El par se lee de las filas.
	 *
	 * Con nadie marcado, `$independientes` viene vacío y esto devuelve la lista tal
	 * cual, sin tocar la base.
	 *
	 * @param  list<int>  $pedidas  las que llegaron en el cuerpo, ya normalizadas
	 * @param  list<int>  $independientes  del grupo destino y en el periodo destino
	 * @return list<int>  **sólo las que se añaden**, sin las pedidas
	 */
	private function unidadesConDuenoQueAcompanan(array $pedidas, array $independientes): array
	{
		if ($pedidas === [] || $independientes === []) {
			return [];
		}

		$origen = DB::select('SELECT DISTINCT asignatura_id, periodo_id FROM unidades
			WHERE id IN ('.implode(',', array_fill(0, count($pedidas), '?')).')
			  AND deleted_at IS NULL', $pedidas);

		if ($origen === []) {
			return [];
		}

		$valores = [];

		foreach ($origen as $par) {
			$valores[] = $par->asignatura_id;
			$valores[] = $par->periodo_id;
		}

		// `(asignatura_id, periodo_id) IN ((?,?), ...)` y no dos `IN` sueltos: con dos
		// listas cruzadas, pedir dos asignaturas de dos periodos se traería los cuatro
		// pares y copiaría unidades de un periodo que nadie nombró.
		$conDueno = DB::select('SELECT u.id FROM unidades u
			WHERE (u.asignatura_id, u.periodo_id) IN ('.implode(',', array_fill(0, count($origen), '(?,?)')).')
			  AND u.deleted_at IS NULL
			  AND u.alumno_id IN ('.implode(',', array_fill(0, count($independientes), '?')).')
			ORDER BY u.alumno_id, u.orden, u.id',
			array_merge($valores, $independientes));

		$ids = array_map(static fn ($fila) => (int) $fila->id, $conDueno);

		// Sin las que el front ya pidió: si mandó una con dueño a propósito, se copia
		// una sola vez **y cuenta como suya**, no como añadida por nosotros.
		return array_values(array_diff(array_unique($ids), $pedidas));
	}

	public function deleteDestroy($periodo_id)
	{
		$periodo = Periodo::findOrFail($periodo_id);
		AuditarFila::cambio('periodo', 'periodos', (int) $periodo->id, function () use ($periodo) {
			$periodo->deleted_by 	= $this->user->user_id;
			$periodo->save();
			$periodo->delete();
		}, (int) $periodo->year_id, 'Borró el periodo '.$periodo->numero);

		return $periodo;
	}

}
