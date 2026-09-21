<?php namespace App\Http\Controllers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use DateTime;

use App\User;
use App\Models\Alumno;
use App\Models\Grupo;
use App\Models\Ausencia;
use App\Services\Auditoria;
use App\Support\Reloj;
use App\Models\Asignatura;
use Carbon\Carbon;
use App\Support\NombreDelAlumno;


/*
 * Las ausencias **las cierra el interruptor del periodo**, y es una decisión —la
 * segunda sobre lo mismo, que revoca la primera—.
 *
 * LA HISTORIA COMPLETA, porque aquí ya se ha decidido dos veces y en sentidos
 * contrarios, y quien lea esto dentro de un año merece no tener que adivinar:
 *
 *   hasta el 21 ago 2026   tres rutas —guardar cambios, cambiar el tipo y borrar—
 *                          llamaban a `pueden_editar_notas()` y las dos que anotan
 *                          no. O sea que con el periodo cerrado un profesor podía
 *                          apuntar una falta pero no corregirla: incoherente, y
 *                          nadie lo había decidido así.
 *   21 y 22 ago 2026       Joseth deshizo el empate por el lado abierto —«que poner
 *                          asistencias no se bloquee al bloquear periodos»— y las
 *                          tres llamadas se retiraron.
 *   29 ago 2026            Joseth lo cambia: **«la asistencia no se puede modificar
 *                          en un periodo que esté bloqueado para editar notas»**.
 *                          Las cinco rutas que escriben lo comprueban ahora.
 *
 * Lo que se conserva de agosto es el sitio donde estaba la razón: el interruptor
 * es del PERIODO, así que la pregunta se le hace al periodo de la falta que se
 * toca —no al que el profesor tenga puesto— igual que hace `notas/lote` con el
 * periodo de cada nota. Corregir una falta de un periodo cerrado y anotar en el
 * periodo abierto son cosas distintas, y así se distinguen.
 *
 * NO SE USA `pueden_editar_notas()` AUNQUE SEA LA MISMA BANDERA, y esa línea es la
 * que hay que leer antes de «simplificar» esto: aquélla contesta **403 a quien no
 * es profesor ni superusuario**, y la secretaría pasa asistencia sin tocar una
 * nota en su vida. Lo que se pidió fue cerrar el periodo, no cerrar la puerta a
 * la secretaría. `exigirPeriodoAbiertoParaNotas()` comprueba sólo el periodo; el
 * porqué está entero en `User.php`.
 *
 * LO QUE ESTO ALCANZA NO ES SÓLO LA WEB: `myvc_flutter` es **una sola app para los
 * dieciséis colegios** y escribe por estas mismas rutas —`store`,
 * `agregar-ausencia`, `agregar-tardanza`, `destroy`—. A partir de aquí, un
 * profesor con el periodo cerrado recibe 400 también desde el móvil. Es lo pedido,
 * pero la app no lo dice con palabras suyas hasta que se publique una versión que
 * lo entienda: hasta entonces enseñará su error genérico. Ver
 * docs/migracion/05-codigo-muerto-y-roto.md §40.
 */
class AusenciasController extends Controller {

	public function getIndex()
	{
		//
	}

	public function getDetailed($asignatura_id)
	{
		$user = User::fromToken();

		$asignatura = (object)Asignatura::detallada($asignatura_id, $user->year_id);
		
		$alumnos = Grupo::alumnos($asignatura->grupo_id);
		
		foreach ($alumnos as $alumno) {

			$userData = Alumno::userData($alumno->alumno_id);
			$alumno->userData = $userData;

			$consulta = 'SELECT * FROM ausencias a WHERE a.asignatura_id = ? and a.periodo_id = ? and a.alumno_id=? and a.deleted_at is null';

			$ausencias = DB::select($consulta, array($asignatura_id, $user->periodo_id, $alumno->alumno_id));

			foreach ($ausencias as $ausencia) {
				$ausencia->mes = date('n', strtotime($ausencia->fecha_hora)) - 1;
				$ausencia->dia = (int)(date('j', strtotime($ausencia->fecha_hora))) ;
			}
			
			$alumno->ausencias = $ausencias;
		}

		// No cambiar el orden!
		$resultado = [];
		array_push($resultado, $asignatura);
		array_push($resultado, $alumnos);

		return $resultado;
	}

	/**
	 * **Las faltas de UN alumno en UN año, para citar al acudiente.**
	 *
	 *     PUT ausencias/de-alumno   auth.personal
	 *
	 * La pidió `myvc_front` el 20 sep 2026 para el informe de *citación al acudiente*,
	 * y **no porque no se pudiera hacer**: hoy se saca de `GET planillas/ver-ausencias`,
	 * que es lo que ya usa el informe de inasistencias. Lo que pasa es que aquélla
	 * devuelve **todos los grupos del año, con todos sus alumnos y todos sus periodos**
	 * para citar a uno, y su forma es N alumnos x 4 periodos con una consulta cada una,
	 * o sea que crece lineal con el colegio. Medida en el docker dio 150 ms y 108 KB,
	 * **pero esa copia tiene 42 alumnos y CERO filas de ausencia**, así que ese número
	 * no predice un colegio de verdad — va dicho porque una medición sobre una población
	 * vacía no es una medición.
	 *
	 * ## RUTA NUEVA Y NO UN RETOQUE DE LAS SEIS DE `ausencias/*`
	 *
	 * Esa familia la comparte `myvc_flutter`, que es **una sola app para los dieciséis
	 * colegios** y cuya versión vieja convive con este backend durante meses. Es la
	 * misma razón por la que nivelar estrenó endpoints en vez de enseñarle a
	 * `notas/update`: lo que se le añade a una ruta que ya usa la móvil viaja a una app
	 * que no se puede actualizar a la vez.
	 *
	 * ## NO AGREGA, Y ESO ES LA DECISIÓN
	 *
	 * Devuelve **las filas**, no un total. En este proyecto conviven dos criterios de
	 * recuento sobre estos mismos datos —unos endpoints cuentan filas con `COUNT(*)` y
	 * otros suman `cantidad_ausencia`, y **dan números distintos** porque una fila puede
	 * valer más de una falta—. Un total aquí sería un **tercer** número, y el papel que
	 * lo imprimiera no podría decir cuál de los tres es. Quien lo imprime elige, y la
	 * hoja dice qué contó.
	 *
	 * Por lo mismo **no se filtra `tipo`**: `ausencia` y `tardanza` viajan las dos con su
	 * etiqueta. La columna «Total» del informe viejo suma las dos sin decirlo, y ése es
	 * justamente el número que el informe nuevo viene a contar bien.
	 *
	 * `fecha_hora` **es nullable y se devuelve como está**: hay filas que cuentan en los
	 * totales y no están en ningún día. Rellenarlas con la fecha de creación sería
	 * inventarse el dato que el papel imprime.
	 *
	 * ## LO QUE SUSTITUYE NO VE LO MISMO, Y ESO SE ESCRIBIÓ MAL AQUÍ EL 20 SEP
	 *
	 * Este bloque decía que `planillas/ver-ausencias` sirve «las mismas filas» y que esto
	 * es «estrictamente menos». **Es falso**, lo encontró `myvc-front-38` conduciendo, y
	 * se corrige con la medición delante:
	 *
	 *     WHERE a.entrada=true      <- PlanillasController::getVerAusencias
	 *
	 * O sea que aquella consulta **sólo ve las faltas de portería**. En la copia de
	 * desarrollo eso son **17 filas de 46.478** — el 0,04 %; las otras 46.461 (44.393
	 * `ausencia` y 2.068 `tardanza`) son de clase y **no las devuelve jamás**. Con el
	 * alumno 1 del año 9: esta ruta contesta 3 y aquélla 0.
	 *
	 * Así que esto no es la misma consulta más barata: **es la única que ve las faltas de
	 * clase de un alumno en su año**, que en una citación por inasistencia son justo las
	 * que se discuten.
	 *
	 * ## EL GUARD, dicho porque el front preguntó por él — y con el delta escrito
	 *
	 * `auth.personal` y nada dentro, como sus siete hermanas. Pero **no es «no abre
	 * nada»**, y eso hay que decirlo para que se pueda decidir:
	 *
	 *   - `ausencias/detailed/{asignatura_id}` ya sirve filas con `entrada=0` a cualquiera
	 *     del personal, sin comprobar de quién es la asignatura — pero **sólo del periodo
	 *     del token** (`$user->periodo_id`).
	 *   - `users.periodo_id` **no lo cambia ninguna ruta**: lo escriben `Login` y
	 *     `ContextoDeUsuario`, y siempre al periodo `actual`.
	 *
	 * O sea que lo que esta ruta añade son **las filas de los periodos ya cerrados del año
	 * en curso**, que antes no devolvía ninguna. Mismo tipo de dato y misma población —y
	 * sus recuentos ya viajan en cada boletín—, pero es un ensanche y no un atajo.
	 * *Se dice en vez de repetir que no abre nada, que es lo que hacía este bloque.*
	 *
	 * **No lo alcanza un acudiente**, y es a propósito: la citación es el papel con el
	 * que el colegio llama a la familia, no lo que la familia consulta. El día que se
	 * decida que un acudiente vea las faltas de su acudido, eso es `persona.propia` o
	 * `boletin.propio` sobre una ruta suya, no aflojar ésta.
	 */
	public function putDeAlumno()
	{
		$user = User::fromToken();

		$alumno_id = (int) Request::input('alumno_id');

		if ($alumno_id <= 0) {
			abort(422, 'Falta el alumno del que se piden las faltas.');
		}

		// 404 y no una lista vacía: un alumno que no existe y un alumno sin ninguna
		// falta se leen igual desde la pantalla, y sólo uno de los dos es un error de
		// quien llama.
		$alumno = DB::selectOne('SELECT id FROM alumnos WHERE id=? and deleted_at is null', [$alumno_id]);

		if ($alumno === null) {
			abort(404, 'Ese alumno no existe.');
		}

		$year_id = Request::has('year_id') ? (int) Request::input('year_id') : (int) $user->year_id;

		// **El año entra por `periodos` y no por una columna de `ausencias`**, que no la
		// tiene: la falta cuelga del periodo y el periodo del año. Por eso el `INNER
		// JOIN` con `periodos` no es adorno — es lo único que ata la fila a un año.
		$consulta = 'SELECT au.id, au.alumno_id, au.asignatura_id, au.periodo_id, p.numero as periodo,
						au.tipo, au.fecha_hora, au.cantidad_ausencia, au.cantidad_tardanza, au.entrada,
						m.materia, m.alias, asi.grupo_id,
						au.created_by, uCre.username as created_by_username, au.created_at, au.updated_at
					FROM ausencias au
					INNER JOIN periodos p ON p.id=au.periodo_id and p.deleted_at is null and p.year_id=:year_id
					LEFT JOIN asignaturas asi ON asi.id=au.asignatura_id and asi.deleted_at is null
					LEFT JOIN materias m ON m.id=asi.materia_id and m.deleted_at is null
					LEFT JOIN users uCre ON uCre.id=au.created_by
					WHERE au.alumno_id=:alumno_id and au.deleted_at is null
					ORDER BY p.numero, au.fecha_hora, au.id';

		$ausencias = DB::select($consulta, [':year_id' => $year_id, ':alumno_id' => $alumno_id]);

		// Se devuelve con qué se contestó: `year_id` puede haberlo puesto el servidor, y
		// una citación que imprime «año lectivo 2026» tiene que saber que le contestaron
		// de 2026 en vez de suponerlo del token.
		return [
			'alumno_id' => $alumno_id,
			'year_id' => $year_id,
			'ausencias' => $ausencias,
		];
	}


	/**
	 * La línea de auditoría de una falta, que es idéntica en las seis rutas.
	 *
	 * Se saca a un ayudante y no se copia seis veces por el motivo que este
	 * módulo ya conoce: `crearFaltaModal` repite el mismo botón «Eliminar» tres
	 * veces y **solo uno mira el rol**, que es exactamente lo que pasa cuando la
	 * misma decisión se escribe en varios sitios. Seis copias de esto acabarían
	 * siendo seis criterios, que es de lo que viene la fase 3 entera.
	 *
	 * El nombre del alumno **se congela dentro de la línea**, y cuesta una consulta
	 * por petición: cada una de estas seis rutas escribe **una** falta, así que no
	 * hay bucle que multiplicarlo. (Los dos caminos que sí escriben en bucle —el
	 * lector de tardanzas y `notas/lote`— resuelven el lote entero de una vez con
	 * `NombreDelAlumno::deVarios()`.)
	 *
	 * No es adorno: sin el nombre, la frase de serie dice «Fulano borró ausencia
	 * 4821» —un verbo, una entidad y un id—, y **una línea cuya descripción no se
	 * puede leer no cuenta como cableada**. Es lo que le pasa hoy a `bitacoras`,
	 * medido contra el cuerpo crudo: manda `descripcion: null` en las 22 filas.
	 */
	private function anotar(string $accion, Ausencia $aus): void
	{
		$alumnoDeLaLinea = $aus->alumno_id === null ? null : (int) $aus->alumno_id;

		$linea = Auditoria::registrar()
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: $aus->asignatura_id === null ? null : (int) $aus->asignatura_id,
				periodo: $aus->periodo_id === null ? null : (int) $aus->periodo_id);

		$valor = [
			'tipo' => $aus->tipo,
			'fecha_hora' => (string) $aus->fecha_hora,
			'cantidad_ausencia' => $aus->cantidad_ausencia,
			'cantidad_tardanza' => $aus->cantidad_tardanza,
		];

		match ($accion) {
			Auditoria::CREAR => $linea->crear('ausencia', (int) $aus->id)->a($valor),
			Auditoria::BORRAR => $linea->borrar('ausencia', (int) $aus->id)->de($valor),
			default => $linea->editar('ausencia', (int) $aus->id)->a($valor),
		};

		$linea->guardar();
	}


	public function postStore()
	{
		$user = User::fromToken();

		// La falta se escribe en `$user->periodo_id` —tres líneas más abajo—, así
		// que es a ESE periodo al que hay que preguntarle si está abierto.
		User::exigirPeriodoAbiertoParaNotas($user, (int) $user->periodo_id);

		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= Request::input('cantidad_ausencia', null);
		$aus->cantidad_tardanza	= Request::input('cantidad_tardanza', null);
		// Sin fecha, la falta es de hoy. Una `fecha_hora` en null deja una falta
		// que cuenta en los totales y no está en ningún día: no sale al filtrar por
		// fecha ni se puede saber después a cuál era. El cliente que no manda el
		// campo está anotando la de ahora, que es lo que ya hacen sus dos vecinas
		// —`agregar-ausencia` y `agregar-tardanza`, donde `Carbon::parse(null)` es
		// ahora—. Se deja pasar el valor recibido tal cual para no cambiarle el
		// formato a quien sí lo manda.
		//
		// `Reloj::ahora()` y no `Carbon::now()`: esto acaba en una columna, y la
		// aplicación guarda en Bogotá aunque `config/app.php` siga en UTC (18,
		// decisión 1). Lo cazó `RelojUnicoTest` — con `Carbon::now()` la falta
		// anotada después de las 19:00 se habría escrito con la fecha de mañana.
		$aus->fecha_hora		= Request::input('fecha_hora') ?: Reloj::ahora();
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		
		if (Request::input('tipo')) {
			$aus->tipo = Request::input('tipo');
		}
		if ($aus->cantidad_ausencia) {
			$aus->tipo = 'ausencia';
		}
		if ($aus->cantidad_tardanza) {
			$aus->tipo = 'tardanza';
		}

		$aus->save();
		// Hasta hoy anotar una falta no dejaba rastro de ningún tipo: ni en
		// `bitacoras` ni en ninguna otra parte. Una falta que sale en el boletín
		// y en el observador, y nadie sabía quién la había puesto.
		$this->anotar(Auditoria::CREAR, $aus);

		return $aus;
	}



	public function postAgregarAusencia()
	{
		$user = User::fromToken();

		User::exigirPeriodoAbiertoParaNotas($user, (int) $user->periodo_id);

		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_ausencia	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'ausencia';

		$aus->save();

		$this->anotar(Auditoria::CREAR, $aus);

		return $aus;
	}


	public function postAgregarTardanza()
	{
		$user = User::fromToken();

		User::exigirPeriodoAbiertoParaNotas($user, (int) $user->periodo_id);

		$aus = new Ausencia;
		$aus->alumno_id 		= Request::input('alumno_id');
		$aus->asignatura_id 	= Request::input('asignatura_id', null);
		$aus->periodo_id		= $user->periodo_id;
		$aus->cantidad_tardanza	= 1;
		$aus->fecha_hora		= Carbon::parse(Request::input('now'));
		$aus->entrada			= Request::input('entrada', 0);
		$aus->created_by		= $user->user_id;
		$aus->tipo 				= 'tardanza';

		$aus->save();

		$this->anotar(Auditoria::CREAR, $aus);

		return $aus;
	}

	/*
	 * Corregir el día de una falta lo puede hacer **cualquiera del personal**, y
	 * es una decisión tomada, no un olvido.
	 *
	 * Aquí había una comprobación de permisos calculada y tirada a la basura:
	 *
	 *     $isCoorDisciplinario = Role::isCoorDisciplinario($user->user_id);
	 *     if (!$isCoorDisciplinario) {
	 *     }
	 *
	 * El cuerpo del `if` vacío, en éste método y en `deleteDestroy`. `myvc_front`
	 * ya lo había visto en la fase 11 y lo dejó apuntado por ser del backend.
	 * Leído en frío parece un descuido con arreglo obvio —rellenar el `if`— y es
	 * justo lo que no se puede hacer: **el rol no gobierna esto en ningún
	 * cliente**. El menú de AngularJS enseña «Asistencias» a `profesor`;
	 * `crearFaltaModal` repite el mismo botón «Eliminar» tres veces y solo uno
	 * mira el rol; y `myvc_flutter` —una sola app para los dieciséis colegios—
	 * borra desde la pantalla de asistencia del profesor sin mirar ninguno.
	 * Rellenar el `if` dejaría a los 51 profesores sin poder corregir una falta
	 * mal puesta, en dieciséis colegios y de golpe, por una app que no se puede
	 * publicar el mismo día.
	 *
	 * Joseth lo decidió el 22 ago 2026: **se queda abierto**. Lo que se cerró en
	 * su lugar fue el rastro: ver `deleteDestroy`. Lo fija `AusenciasTest`, que
	 * además cuenta qué habría que publicar antes si algún día se cierra.
	 *
	 * ESTO SIGUE EN PIE Y NO LO TOCA EL CAMBIO DEL 29 ago, aunque se apoyaba en él
	 * al escribirse: aquella frase decía «en la misma línea que el interruptor del
	 * periodo», y ese interruptor ahora sí cierra la asistencia. **Son dos
	 * preguntas distintas y sólo cambió una.** Quién puede corregir una falta —
	 * cualquier profesor, sobre cualquier falta— sigue abierto por lo que dice el
	 * párrafo de arriba, que no ha dejado de ser cierto: los 51 profesores de los
	 * dieciséis colegios y una app que no se publica el mismo día. Lo que se cerró
	 * es CUÁNDO: en un periodo bloqueado, ni él ni nadie.
	 *
	 * `Role::isCoorDisciplinario()` se queda sin llamantes con esto, y es el
	 * cuarto rol de la familia que no gobierna nada — tras Psicólogo y Enfermero
	 * (05 §30.2), que fallaban al revés: cerraban de más.
	 */
	public function putGuardarCambiosAusencia()
	{
		$user = User::fromToken();

		/* Debo convertir string a fecha
		$dato = Request::input('fecha_hora', null);
		if ($dato) {
			$dato = DateTime::createFromFormat('Y-m-d G:H:i', $dato);
			return $dato;
		}
		*/
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));

		// EL PERIODO DE LA FALTA, no el que el profesor tenga puesto: se está
		// tocando una fila que ya existe y que pertenece a un periodo concreto.
		// Con el periodo del usuario, corregir una falta de un periodo cerrado
		// pasaría sólo con cambiar de periodo en la barra. Es el mismo criterio
		// que `notas/lote`, que saca el periodo de cada nota por sus unidades.
		//
		// `?:` y no `??`: hay filas antiguas con `periodo_id` a 0 además de a
		// null, y las dos significan lo mismo aquí —no se sabe de qué periodo
		// es—. Sin id, la guarda cae en el periodo del usuario, que es el lado
		// prudente: la que decide es la bandera que ese profesor tiene delante.
		User::exigirPeriodoAbiertoParaNotas($user, (int) ($aus->periodo_id ?: $user->periodo_id));

		$aus->fecha_hora		= Request::input('fecha_hora', null);
		$aus->updated_by		= $user->user_id;

		$aus->save();

		// Corregir el día de una falta lo puede hacer cualquiera del personal —es
		// una decisión tomada, no un olvido (ver la cabecera de este método)—, y
		// justamente por eso el rastro es lo único que queda.
		$this->anotar(Auditoria::EDITAR, $aus);

		return $aus;
	}

	public function putCambiarTipoAusencia()
	{
		$user = User::fromToken();
		
		$aus = Ausencia::findOrFail(Request::input('ausencia_id'));

		User::exigirPeriodoAbiertoParaNotas($user, (int) ($aus->periodo_id ?: $user->periodo_id));

		if (Request::input('new_tipo') == 'tardanza') {
			$aus->tipo					= 'tardanza';
			$aus->cantidad_tardanza		= $aus->cantidad_ausencia;
		}
		
		if (Request::input('new_tipo') == 'ausencia') {
			$aus->tipo					= 'ausencia';
			$aus->cantidad_ausencia		= $aus->cantidad_tardanza;
		}
		
		$aus->updated_by		= $user->user_id;
		$aus->save();

		$this->anotar(Auditoria::EDITAR, $aus);

		return $aus;
	}

	/*
	 * Borrar una falta **la firma**, y hasta el 22 ago 2026 no la firmaba.
	 *
	 * Las otras dos rutas que borran una ausencia —la del lector y la de la app—
	 * ponen `deleted_by` antes del `delete()`; ésta, que es la de las tres
	 * pantallas web y la de Flutter, no ponía nada. En la copia de producción del
	 * 22 ago hay **5.689 ausencias borradas y 5.684 sin autor**: las cinco que lo
	 * tienen son las que pasaron por el lector.
	 *
	 * Importa justo por lo que se decidió el 22 ago: que corregir y borrar una
	 * falta siga abierto a cualquier profesor. Si no cierra el permiso, lo único
	 * que queda es el rastro — y el rastro estaba en blanco.
	 *
	 * El `save()` va antes del `delete()` y no es cosmético: el borrado suave de
	 * Eloquent escribe solo `deleted_at`, así que un `deleted_by` sin guardar se
	 * pierde. Es lo que hacen las dos hermanas.
	 */
	public function deleteDestroy($id)
	{
		$user = User::fromToken();

		$aus = Ausencia::findOrFail($id);

		User::exigirPeriodoAbiertoParaNotas($user, (int) ($aus->periodo_id ?: $user->periodo_id));

		$aus->deleted_by = $user->user_id;
		$aus->save();

		// **Antes** del `delete()`, y con `de(...)`: la línea guarda lo que la
		// falta ERA. Después del borrado suave la fila sigue ahí, pero la
		// pregunta que el colegio hace cuando alguien reclama es «qué falta se
		// borró», y eso es el valor viejo.
		//
		// Es la mitad que faltaba de lo que se cerró el 22 ago: `deleted_by` dice
		// quién, y en la copia de producción de ese día había **5.689 ausencias
		// borradas y 5.684 sin autor**. `deleted_by` no dice cuándo ni qué; esta
		// línea sí, y no se puede borrar.
		$this->anotar(Auditoria::BORRAR, $aus);

		$aus->delete();

		return $aus;
	}

}