<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\FraseAsignatura;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Support\AsignaturaDeLaFila;
use App\Support\CierreDeAsignatura;
use App\Support\PeriodoDeLaFila;
use App\Support\NombreDelAlumno;
use App\Support\Reloj;


/**
 * Las frases del boletín de un alumno en una asignatura y un periodo —
 * `frases_asignatura`, **11.004 filas vivas** en la copia de `simonbolivar` del
 * docker (17 sep 2026).
 *
 * **Aquí dentro hay dos familias y conviene no confundirlas.**
 *
 * - **Las tres de una en una** —`store`, `show/{alumno}/{asignatura}` y
 *   `destroy/{id}`—. Censado el 17 sep 2026 antes de tocar nada: las **tres** las
 *   llama la app vieja (`myvc_front/app/scripts/services/api/FrasesAsignaturaApi.ts`,
 *   desde el modal de frases de `NotasCtrl` y de `AsistenciasCtrl`), **dos** las
 *   llama `myvc_flutter` (`lib/Http/FrasesApi.dart`: `store` y `destroy`; las
 *   frases que ya tiene un alumno le llegan dentro de `notas/detailed`), y las tres
 *   están en `app2`, que todavía no está desplegado. Escriben siempre en
 *   `$user->periodo_id` y **no se tocan**: cualquier cosa que les cambie la forma
 *   rompe a la vez los dieciséis colegios y la app del móvil, que es una sola para
 *   todos.
 * - **Las dos del grupo entero** —`GET` y `PUT frases_asignatura/grupo/{asignatura_id}`—,
 *   que son de las pantallas propias de preescolar (§5 de
 *   [39](../../../docs/migracion/39-el-modelo-plano-por-competencias.md)) y **sí**
 *   aceptan el periodo, porque una pantalla que sólo puede escribir en el periodo
 *   de la barra de arriba no puede arreglar el boletín del periodo anterior.
 *
 * ## Por qué hacían falta las dos nuevas, medido y no supuesto
 *
 * Preescolar es el uso de texto más intenso del colegio: **2.582 filas para 83
 * alumnos** en la copia de desarrollo. Sobre un grupo de verdad —el 3,
 * «Transición» de 2018: 18 matriculados, 7 asignaturas, 4 periodos— pintar y
 * guardar **un periodo entero** costaba:
 *
 * | | hoy | con estas dos |
 * |---|---|---|
 * | pintar las 7 asignaturas | 126 `GET show` (18 × 7) | 7 `GET grupo` |
 * | guardar las 196 frases | 196 `POST store` | 7 `PUT grupo` |
 * | **total** | **322 peticiones** | **14** |
 *
 * Y una asignatura sola —la pantalla que se abre de verdad— pasa de **54
 * peticiones** (18 + 36) a **dos**.
 *
 * ## Lo que estas dos NO hacen, para que no se busque
 *
 * No tocan el boletín. Preescolar imprime por `BolfinalesPreescolarController`
 * (tipo 4), que lee `frases_preescolar` y `frases_asignatura` exactamente igual
 * que ayer: esto es sólo la pantalla que las escribe.
 *
 * Y **no llevan el candado del año cerrado** (`Autoriza::exigirEscrituraEnElAnio`),
 * que sí llevan las trece rutas de catálogo y configuración. No es un olvido: ese
 * candado es de lo que el colegio escribe una vez —frases, escalas, contratos,
 * manual de convivencia—, y esto es el dato de un alumno, que se gobierna con el
 * interruptor del periodo igual que las notas. Ponérselo sólo aquí dejaría la
 * pantalla nueva sin poder arreglar un boletín viejo mientras `store` —la que
 * está desplegada— sigue pudiendo.
 */
class FrasesAsignaturaController extends Controller {



	public function postStore($frase_id='')
	{
		$user = User::fromToken();
		// La frase se crea con `periodo_id = $user->periodo_id` tres líneas más
		// abajo, así que el periodo de la fila que se escribe es ése y no el que
		// venga en `num_periodo`. §27.
		User::pueden_editar_notas($user, (int) $user->periodo_id,
			Request::input('asignatura_id') ? (int) Request::input('asignatura_id') : null);

		$frase = new FraseAsignatura;
		$frase->alumno_id = Request::input('alumno_id');
		$frase->asignatura_id = Request::input('asignatura_id');
		$frase->periodo_id = $user->periodo_id;

		if ($frase_id=='') {
			$frase->frase = Request::input('frase');
		}else{
			$frase->frase_id = $frase_id;
		}

		$frase->save();

		// De las tres familias de frases, ésta es la que va **pegada a un alumno**:
		// sale en su boletín con su nombre al lado. Por eso lleva `deAlumno` y las
		// otras dos no — y por eso es la que más importa de las tres.
		$alumnoDeLaLinea = (int) $frase->alumno_id;

		Auditoria::registrar()
			->crear('frase_asignatura', (int) $frase->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: (int) $frase->asignatura_id, periodo: (int) $frase->periodo_id)
			->a(['frase' => $frase->frase, 'frase_id' => $frase->frase_id])
			->guardar();

		$frases = FraseAsignatura::deAlumno($frase->asignatura_id, $frase->alumno_id, $user->periodo_id);

		return $frases;
	}

	public function getShow($alumno_id, $asignatura_id)
	{
		$user = User::fromToken();

		$frases = FraseAsignatura::deAlumno($asignatura_id, $alumno_id, $user->periodo_id);
		return $frases;
	}



	public function deleteDestroy($id)
	{
		$user = User::fromToken();
		User::pueden_editar_notas($user, PeriodoDeLaFila::deFraseAsignatura($id), AsignaturaDeLaFila::deFraseAsignatura($id));
		
		$frase = FraseAsignatura::findOrFail($id);

		// Antes del `delete()`. Quitarle una frase del boletín a un alumno es el
		// cambio que un acudiente nota, y hasta hoy no quedaba de él ningún rastro.
		$alumnoDeLaLinea = (int) $frase->alumno_id;

		Auditoria::registrar()
			->borrar('frase_asignatura', (int) $frase->id)
			->deAlumno($alumnoDeLaLinea, NombreDelAlumno::de($alumnoDeLaLinea))
			->en(asignatura: (int) $frase->asignatura_id, periodo: (int) $frase->periodo_id)
			->de(['frase' => $frase->frase, 'frase_id' => $frase->frase_id])
			->guardar();

		$frase->delete();

		return $frase;
	}



	// ── Las dos del grupo entero ─────────────────────────────────────────────
	//
	// Guard `auth.personal` en la ruta y el criterio fino **dentro** del método,
	// que es la forma de `PlantillaNotasController`. Lo que NO llevan es
	// `persona.propia`, que es el guard de `show/{alumno_id}/{asignatura_id}`:
	// aquél pregunta si el id de UN alumno es el de quien llama, y aquí no se pide
	// un alumno sino un grupo entero — no hay id que comparar, y ponérselo dejaría
	// la ruta cerrada para todo el mundo.


	/**
	 * `GET frases_asignatura/grupo/{asignatura_id}` — los alumnos del grupo con las
	 * frases que cada uno tiene en esa asignatura.
	 *
	 * `periodo_id` viaja por la query y es **opcional**: sin él, el periodo activo
	 * de la sesión, que es lo único que saben hacer las tres de una en una.
	 *
	 *     GET frases_asignatura/grupo/129?periodo_id=1
	 *
	 *     { "asignatura_id": 129, "grupo_id": 3, "year_id": 1, "periodo_id": 1,
	 *       "periodo_abierto": true, "puede_escribir": true,
	 *       "alumnos": [
	 *         { "alumno_id": 12, "matricula_id": 31, "nombres": "Ana", "apellidos": "Pérez",
	 *           "frases": [ { "id": 8421,
	 *                         "frase": "Comparte con sus compañeros.",
	 *                         "frase_escrita": "Comparte con sus compañeros.",
	 *                         "frase_id": null, "tipo_frase": null,
	 *                         "created_at": "2018-04-02 10:11:12" } ] } ],
	 *       "poblacion": { "alumnos": 18, "frases": 36, "alumnos_con_frases": 18,
	 *                      "alumnos_sin_frases": 0, "frases_fuera_del_grupo": 12 } }
	 *
	 * **`periodo_abierto` y `puede_escribir` no son lo mismo, y por eso van los
	 * dos.** El primero es la columna del periodo; el segundo es lo que el `PUT` le
	 * va a contestar **a quien está mirando** —un superusuario escribe con el
	 * periodo cerrado y un docente no—. Sin ellos, el candado sólo se conoce
	 * perdiendo lo escrito.
	 *
	 * **`frases_fuera_del_grupo` es el renglón incómodo**, y está a propósito: son
	 * las frases vivas de esa asignatura y ese periodo que pertenecen a alumnos que
	 * **no** están matriculados hoy en el grupo —un retirado, o uno que se cambió—.
	 * Esta pantalla no las enseña y no las toca, pero *«no hay ninguna»* y *«no se
	 * miraron»* no se pueden leer igual. En el grupo 3 de la copia de desarrollo son
	 * **12 filas de 2 alumnos**, o sea que el caso no es teórico.
	 *
	 * @return array<string, mixed>
	 */
	public function getGrupo($asignatura_id)
	{
		$user = User::fromToken();

		$contexto = $this->contextoDelGrupo($user, $asignatura_id);
		$alumnos  = $this->alumnosDelGrupo($contexto->grupo_id);
		$frases   = FraseAsignatura::deGrupo($contexto->asignatura_id, $contexto->periodo_id, array_keys($alumnos));

		$poblacion = [
			'alumnos'                => count($alumnos),
			'frases'                 => 0,
			'alumnos_con_frases'     => 0,
			'alumnos_sin_frases'     => 0,
			'frases_fuera_del_grupo' => $this->frasesFueraDelGrupo($contexto, array_keys($alumnos)),
		];

		$filas = [];

		foreach ($alumnos as $alumno_id => $alumno) {
			$suyas = $frases[$alumno_id] ?? [];

			$poblacion['frases'] += count($suyas);

			if ($suyas === []) {
				$poblacion['alumnos_sin_frases']++;
			} else {
				$poblacion['alumnos_con_frases']++;
			}

			$filas[] = $this->alumnoParaElFront($alumno, $suyas);
		}

		return $this->respuestaDelGrupo($user, $contexto, $filas, $poblacion);
	}


	/**
	 * `PUT frases_asignatura/grupo/{asignatura_id}` — guarda el grupo entero de una
	 * vez.
	 *
	 *     { "periodo_id": 1,
	 *       "alumnos": [
	 *         { "alumno_id": 12,
	 *           "frases": [ { "id": 8421, "frase": "Comparte con sus compañeros." },
	 *                       { "frase_id": 55 },
	 *                       { "frase": "Reconoce las vocales." } ] },
	 *         { "alumno_id": 13, "frases": [] } ] }
	 *
	 * `periodo_id` es opcional igual que en el `GET`. Cada alumno trae **la lista
	 * completa** de sus frases en esa asignatura y ese periodo, que es lo que
	 * significa `PUT`: lo que no viene, se va. Las tres formas de una entrada:
	 *
	 * - con `id` → una fila que ya existe (la que devolvió el `GET`). Se queda, y se
	 *   reescribe si su texto o su `frase_id` cambiaron.
	 * - sin `id`, con `frase_id` → una frase **del catálogo** (`GET frases`).
	 * - sin `id`, con `frase` → una escrita a mano. En la copia de desarrollo son
	 *   **611 de las 686** del grupo 3, o sea el caso normal.
	 *
	 * Si vienen `frase_id` y `frase` a la vez **gana el `frase_id` y el texto se
	 * ignora**, que es exactamente lo que hace `postStore` con `store/{frase_id}`. No
	 * es capricho: el `GET` devuelve las dos cosas en cada fila, así que el front
	 * puede devolver la fila tal como la recibió sin que eso signifique nada nuevo.
	 *
	 * ## Qué es «vacío», y aquí manda `TrimStrings`
	 *
	 * El middleware global recorta **todo** lo que entra, así que una frase de sólo
	 * espacios —o un textarea que acabe en salto de línea— llega como `''`. Una
	 * entrada sin `frase_id` y con la frase vacía **no es una frase**: no se escribe,
	 * y si traía `id`, esa fila se va con las demás que no vinieron. Es lo que pasa
	 * cuando el docente borra el texto de una casilla y le da a guardar, y es la
	 * única lectura que no obliga a la pantalla a distinguir «lo vacié» de «no lo
	 * mandé».
	 *
	 * ## Sólo se toca a los alumnos que vienen nombrados
	 *
	 * Un alumno que no está en `alumnos` **no se mira**. Así una pantalla que guarde
	 * de a poco —o que pagine— no le puede borrar las frases a nadie por omisión; lo
	 * único que se borra por omisión es dentro de la lista de un alumno que sí vino.
	 *
	 * ## La población, que es lo que se devuelve en vez de un «listo»
	 *
	 * `{alumnos_del_grupo, alumnos_revisados, frases_revisadas, escritas, cambiadas,
	 * sin_cambio, borradas, vacias}`, y **las ocho pueden subir de verdad**.
	 * `sin_cambio` no es relleno: es la diferencia entre *«se guardó y no cambió
	 * nada»* y *«no se guardó»*, que es justo lo que pregunta quien cree que ha
	 * perdido el trabajo.
	 *
	 * **Y no todas cuentan lo mismo, así que no suman entre ellas**: las cuatro del
	 * medio —`escritas`, `cambiadas`, `sin_cambio`, `borradas`— son **filas de la
	 * tabla**, y `vacias` son **entradas del cuerpo**. Una entrada vacía que traía
	 * `id` sale en las dos: es una entrada que no escribió nada y una fila que se
	 * fue. `frases_revisadas` = `escritas + cambiadas + sin_cambio + vacias`; las
	 * `borradas` no estaban en el cuerpo, por definición.
	 *
	 * > **El plan pedía además `saltados_por_periodo_cerrado`, y aquí no está a
	 * > propósito.** El periodo cerrado corta la petición **entera** con 403 antes de
	 * > mirar el cuerpo, así que ese contador no podría valer nunca más que cero — y
	 * > un contador que no puede moverse es uno que alguien leerá como «no pasó»
	 * > cuando lo que pasa es que no existe. Es la misma decisión, con las mismas
	 * > palabras, que tomó `putRejilla`: **un 200 que guardó media pantalla es una
	 * > respuesta que miente**. Quien quiera saberlo antes de escribir tiene
	 * > `puede_escribir` en el `GET`.
	 *
	 * Devuelve **el grupo entero releído**, con la misma forma que el `GET` salvo
	 * `poblacion` —aquélla cuenta lo que se leyó y ésta lo que se escribió—. Eso le
	 * ahorra al front la petición de después: las filas nuevas ya vienen con su `id`.
	 *
	 * @return array<string, mixed>
	 */
	public function putGrupo($asignatura_id)
	{
		$user = User::fromToken();

		$contexto = $this->contextoDelGrupo($user, $asignatura_id);

		/*
		 * **El interruptor del periodo DESTINO, no el de la barra de arriba.** Es la
		 * mitad que le falta a `postStore`, que comprueba y escribe siempre en
		 * `$user->periodo_id`: aquí el periodo lo elige el cuerpo, así que el que hay
		 * que preguntar es ése (§27 de 05, la razón de que exista `PeriodoDeLaFila`).
		 *
		 * Se pregunta con `permiteEditarNotas` —el gemelo de `pueden_editar_notas`
		 * que contesta en vez de abortar— para poder responder **403 y no el 400** de
		 * aquel guard, que es lo que CLAUDE.md manda para código nuevo y lo que ya
		 * hacen las rutas de nivelar y las del plan de área. `pueden_editar_notas` no
		 * se toca: lo llaman cinco métodos de definitivas desde Flutter.
		 *
		 * Y va **antes** de leer el cuerpo: lo que no se puede escribir no se valida.
		 */
		if (! User::permiteEditarNotas($user, $contexto->periodo_id, $contexto->asignatura_id)) {
			abort(403, ! $contexto->periodo_abierto
				? 'El periodo está cerrado: no se puede escribir en él.'
				: ($contexto->asignatura_cerrada
					? User::ASIGNATURA_CERRADA
					: 'No tiene permiso para escribir las frases del boletín.'));
		}

		$pedidos = $this->alumnosDelCuerpo();
		$alumnos = $this->alumnosDelGrupo($contexto->grupo_id);

		// ── Todo se comprueba antes de escribir la primera fila ──────────────
		//
		// No sobre la marcha: con las comprobaciones dentro del bucle, el alumno 1 ya
		// estaría guardado cuando el 18 aborta, y de medio guardado no se vuelve.
		foreach (array_keys($pedidos) as $alumno_id) {
			/*
			 * **La comprobación que `postStore` no hace**, y que entra aquí: que el
			 * alumno esté matriculado en el grupo de la asignatura. Sin ella se le
			 * puede poner una frase de boletín a cualquier alumno del colegio. Esto
			 * es código nuevo, así que contesta 422.
			 */
			if (! isset($alumnos[$alumno_id])) {
				abort(422, "El alumno {$alumno_id} no está matriculado en el grupo de esa asignatura.");
			}
		}

		$existentes = FraseAsignatura::deGrupo(
			$contexto->asignatura_id, $contexto->periodo_id, array_keys($pedidos)
		);

		$porId = [];

		foreach ($existentes as $filasDelAlumno) {
			foreach ($filasDelAlumno as $fila) {
				$porId[(int) $fila->id] = $fila;
			}
		}

		$vistas = [];
		$delCatalogo = [];

		foreach ($pedidos as $alumno_id => $entradas) {
			foreach ($entradas as $entrada) {
				if ($entrada['id'] !== null) {
					// **No es una comprobación de tipos.** Un `id` de otro alumno —o
					// de otra asignatura, o de otro periodo— reescribiría la frase de
					// un boletín ajeno desde una pantalla que no lo enseña.
					$suya = isset($porId[$entrada['id']])
						&& (int) $porId[$entrada['id']]->alumno_id === $alumno_id;

					if (! $suya) {
						abort(422, "La frase {$entrada['id']} no es del alumno {$alumno_id} "
							.'en esa asignatura y ese periodo.');
					}

					if (isset($vistas[$entrada['id']])) {
						abort(422, "La frase {$entrada['id']} viene dos veces: la última ganaría "
							.'en silencio, y eso no lo ha pedido nadie.');
					}

					$vistas[$entrada['id']] = true;
				}

				if ($entrada['frase_id'] !== null) {
					$delCatalogo[$entrada['frase_id']] = true;
				}
			}
		}

		$this->exigirFrasesDelCatalogo(array_keys($delCatalogo));

		// ── Y ahora sí ───────────────────────────────────────────────────────
		$conteo = [
			'alumnos_del_grupo' => count($alumnos),
			'alumnos_revisados' => count($pedidos),
			'frases_revisadas'  => 0,
			'escritas'          => 0,
			'cambiadas'         => 0,
			'sin_cambio'        => 0,
			'borradas'          => 0,
			'vacias'            => 0,
		];

		foreach ($pedidos as $entradas) {
			$conteo['frases_revisadas'] += count($entradas);
		}

		// Una sola consulta para los nombres de todo el lote, antes del bucle: con
		// `de()` dentro serían dieciocho más. Para eso existe `deVarios()`.
		NombreDelAlumno::deVarios(array_keys($pedidos));

		$ahora   = Reloj::ahoraTexto();
		$usuario = (int) $user->user_id;

		// **Dentro de una transacción**, como `notas/lote`: un guardado a medias
		// dejaría media clase con las frases de antes y la otra media con las de
		// ahora, y desde la pantalla los dos estados se ven igual. La auditoría entra
		// en la misma transacción a propósito — si el cambio no se guardó, no hay
		// línea que diga que sí.
		DB::transaction(function () use ($pedidos, $existentes, $porId, $contexto, $ahora, $usuario, &$conteo) {
			foreach ($pedidos as $alumno_id => $entradas) {
				$conservadas = [];

				foreach ($entradas as $entrada) {
					$frase_id = $entrada['frase_id'];
					$texto    = $entrada['frase'];

					if ($frase_id === null && $texto === '') {
						$conteo['vacias']++;

						continue;
					}

					// Con `frase_id`, la columna `frase` se deja en `null` y no con
					// una copia del texto del catálogo: `FraseAsignatura::deAlumno`
					// resuelve con `IFNULL(f.frase, fa.frase)`, así que esa copia no
					// se imprimiría nunca y sólo serviría para envejecer mal el día
					// que el colegio corrija la frase del catálogo.
					$valores = $frase_id === null
						? ['frase' => $texto, 'frase_id' => null]
						: ['frase' => null, 'frase_id' => $frase_id];

					if ($entrada['id'] === null) {
						$id = DB::table('frases_asignatura')->insertGetId($valores + [
							'alumno_id'     => $alumno_id,
							'asignatura_id' => $contexto->asignatura_id,
							'periodo_id'    => $contexto->periodo_id,
							// Quién la escribió. Hoy lo dicen **151 de las 12.445**
							// filas de la copia de desarrollo, porque `postStore`
							// guarda con Eloquent y nadie rellena la columna.
							'created_by'    => $usuario,
							'created_at'    => $ahora,
							'updated_at'    => $ahora,
						]);

						$conteo['escritas']++;

						$this->anotar('crear', (int) $id, $alumno_id, $contexto, null, $valores);

						continue;
					}

					$fila = $porId[$entrada['id']];
					$conservadas[$entrada['id']] = true;

					$antes = [
						'frase'    => $fila->frase_escrita,
						'frase_id' => $fila->frase_id === null ? null : (int) $fila->frase_id,
					];

					// Un reguardado sin cambio **no escribe y sí se cuenta**: la
					// pantalla manda la lista entera cada vez, así que lo normal es
					// que la mayoría de las filas lleguen iguales.
					if ($antes['frase_id'] === $valores['frase_id']
						&& (string) ($antes['frase'] ?? '') === (string) ($valores['frase'] ?? '')) {
						$conteo['sin_cambio']++;

						continue;
					}

					DB::table('frases_asignatura')->where('id', $fila->id)->update($valores + [
						'updated_by' => $usuario,
						'updated_at' => $ahora,
					]);

					$conteo['cambiadas']++;

					$this->anotar('editar', (int) $fila->id, $alumno_id, $contexto, $antes, $valores);
				}

				foreach ($existentes[$alumno_id] ?? [] as $fila) {
					if (isset($conservadas[(int) $fila->id])) {
						continue;
					}

					// Borrado **lógico**, como el de `deleteDestroy`: la frase que
					// desaparece de un boletín es el cambio que un acudiente nota.
					DB::table('frases_asignatura')->where('id', $fila->id)->update([
						'deleted_at' => $ahora,
						'deleted_by' => $usuario,
						'updated_at' => $ahora,
					]);

					$conteo['borradas']++;

					$this->anotar('borrar', (int) $fila->id, $alumno_id, $contexto, [
						'frase'    => $fila->frase_escrita,
						'frase_id' => $fila->frase_id === null ? null : (int) $fila->frase_id,
					], null);
				}
			}
		});

		/*
		 * **La auditoría va fila a fila y con el alumno dentro**, que es lo que ya
		 * decidieron `postStore` y `deleteDestroy` para esta misma tabla: de las tres
		 * familias de frases, ésta es la que sale en el boletín **con el nombre de un
		 * alumno al lado**, así que *«quién le quitó esa frase a Ana»* es exactamente
		 * la pregunta que `auditoria` existe para contestar. Una sola línea por lote
		 * —como hace `putRejilla`— la dejaría sin contestar: allí lo que se guarda es
		 * una rejilla del grupo entero y poner a uno de los treinta sería peor que no
		 * poner a ninguno; aquí cada fila **es** de alguien.
		 *
		 * Lo que evita que eso se convierta en ruido: sólo se anota lo que **cambió**
		 * —`sin_cambio` y `vacias` no escriben ninguna línea, y en un reguardado
		 * normal son casi todas— y las líneas de un mismo guardado comparten sesión,
		 * ruta y hora, así que se vuelven a agrupar sin necesidad de una línea de
		 * lote.
		 *
		 * Y por eso la única línea de lote que se escribe es la del caso en que **no
		 * hay ninguna fila que la escriba**: *«alguien le dio a guardar y no pasó
		 * nada»* es justo el suceso que se va a investigar dentro de un año, y es el
		 * criterio de `putSembrar`.
		 */
		if ($conteo['escritas'] + $conteo['cambiadas'] + $conteo['borradas'] === 0) {
			Auditoria::registrar()
				->editar('frase_asignatura')
				->en(
					asignatura: $contexto->asignatura_id,
					periodo: $contexto->periodo_id,
					grupo: $contexto->grupo_id,
					year: $contexto->year_id
				)
				->a($conteo)
				->resumen(sprintf(
					'Guardó las frases del grupo sin cambiar ninguna: %d alumnos, %d frases revisadas',
					$conteo['alumnos_revisados'],
					$conteo['frases_revisadas']
				))
				->guardar();
		}

		// **El grupo entero releído, no sólo lo que vino.** La lista de matriculados
		// es la de arriba —las matrículas no se mueven a mitad de una petición—, pero
		// las frases se vuelven a pedir: es la única forma de que las filas nuevas
		// viajen con su `id`, que es lo que el front necesita para el guardado
		// siguiente. Una consulta aquí le ahorra una petición entera a la pantalla.
		$frases = FraseAsignatura::deGrupo(
			$contexto->asignatura_id, $contexto->periodo_id, array_keys($alumnos)
		);

		$filas = [];

		foreach ($alumnos as $alumno_id => $alumno) {
			$filas[] = $this->alumnoParaElFront($alumno, $frases[$alumno_id] ?? []);
		}

		return $this->respuestaDelGrupo($user, $contexto, $filas, $conteo);
	}



	// ── Los ayudantes de las dos de arriba ───────────────────────────────────


	/**
	 * La asignatura, su grupo, su año y el periodo destino, comprobados contra el
	 * colegio **y contra quien llama**.
	 *
	 * El periodo tiene que ser del **año de la asignatura**: sin esa comprobación,
	 * un `periodo_id` de otro año escribiría filas que ningún boletín va a leer
	 * nunca y la pantalla contestaría que guardó.
	 */
	private function contextoDelGrupo($user, $asignatura_id)
	{
		$asignaturaId = $this->comoId($asignatura_id, 'asignatura_id');

		if ($asignaturaId === null) {
			abort(422, '`asignatura_id` tiene que ser un identificador.');
		}

		$asignatura = DB::selectOne(
			'SELECT a.id, a.grupo_id, a.profesor_id, g.year_id
			   FROM asignaturas a
			   INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
			  WHERE a.id = ? AND a.deleted_at IS NULL',
			[$asignaturaId]
		);

		if ($asignatura === null) {
			abort(404, 'Esa asignatura no existe.');
		}

		$this->exigirSerDeLaAsignatura($user, $asignatura);

		$periodoId = $this->comoId(Request::input('periodo_id'), 'periodo_id');

		if ($periodoId === null) {
			// **Sin `periodo_id` manda el de la sesión**, que es lo que hacen las
			// tres de una en una. Se resuelve aquí y no en la consulta para que la
			// respuesta pueda decir en qué periodo escribió: una pantalla que no
			// sabe qué periodo le tocó no puede avisar de que le tocó el que no era.
			$periodoId = ($user->periodo_id ?? null) === null ? null : (int) $user->periodo_id;
		}

		if ($periodoId === null) {
			abort(422, 'Hace falta `periodo_id`: esta sesión no tiene periodo activo.');
		}

		$periodo = DB::selectOne(
			'SELECT id, year_id, profes_pueden_editar_notas FROM periodos
			  WHERE id = ? AND deleted_at IS NULL',
			[$periodoId]
		);

		if ($periodo === null || (int) $periodo->year_id !== (int) $asignatura->year_id) {
			abort(422, '`periodo_id` no es un periodo del año de esa asignatura.');
		}

		return (object) [
			'asignatura_id'   => $asignaturaId,
			'grupo_id'        => (int) $asignatura->grupo_id,
			'year_id'         => (int) $asignatura->year_id,
			'periodo_id'      => (int) $periodo->id,
			'periodo_abierto' => (int) $periodo->profes_pueden_editar_notas === 1,
			// El cierre por asignatura (fase 2 del cierre de periodo): el periodo
			// puede estar abierto y esta asignatura cerrada por su docente.
			'asignatura_cerrada' => CierreDeAsignatura::algunaCerrada((int) $periodo->id, $asignaturaId),
		];
	}


	/**
	 * Que la asignatura sea **suya**, o que quien llama sea administrativo.
	 *
	 * Es lo que el guard de la ruta no puede distinguir: `auth.personal` deja pasar
	 * a cualquiera de los 74 del colegio, y esto escribe las frases que salen
	 * impresas en el boletín de dieciocho niños.
	 *
	 * **`persona_id` es el id de la FICHA, y sólo para un `Profesor` es
	 * `profesores.id`** —que es lo que compara `asignaturas.profesor_id`—. Para un
	 * administrativo es `users.id`, un número que casaría con la ficha de otra
	 * persona: sin la primera línea de la condición, el usuario número 5 heredaría
	 * las asignaturas del profesor número 5. Está medido en
	 * `Autoriza::puedeEscribirDesempenos`, que tropezó con la misma trampa.
	 *
	 * **El criterio vive aquí y no en `Autoriza` porque hoy tiene un solo
	 * llamante** y porque es una pregunta de aula —*¿es tuya esta asignatura?*—
	 * frente a las de colegio que aquella clase reúne. El día que una segunda
	 * pantalla la necesite sube allí con nombre propio, que es la regla de esa
	 * clase; copiarla sería exactamente lo que `Autoriza` existe para impedir.
	 *
	 * **El borde que conviene conocer antes de depurarlo**: `esAdministrativo()` es
	 * `is_superuser || Secretario`, así que un coordinador académico **sin**
	 * `is_superuser` que entre a revisar las frases de un grupo que no es suyo
	 * recibe 403. Hoy no le pasa a nadie en producción —ese rol no tiene titulares—,
	 * y ensancharlo es una decisión del colegio, no un `||` más.
	 */
	private function exigirSerDeLaAsignatura($user, $asignatura): void
	{
		if (Autoriza::esAdministrativo($user)) {
			return;
		}

		$suya = ($user->tipo ?? null) === 'Profesor'
			&& ($user->persona_id ?? null) !== null
			&& (int) $user->persona_id === (int) $asignatura->profesor_id;

		Autoriza::exigir($suya, 'Esa asignatura no es suya.');
	}


	/**
	 * Los matriculados vigentes del grupo, indexados por alumno.
	 *
	 * Los tres estados son los de `Grupo::alumnos()` —`MATR`, `ASIS`, `PREM`—, y
	 * aquél no se llama a propósito: devuelve treinta columnas por alumno
	 * —documento, dirección, celular, religión, fotos— y esta pantalla necesita el
	 * nombre. Lo que no viaja no se puede filtrar mal.
	 *
	 * **Indexar por alumno colapsa las matrículas repetidas**: `matriculas` no tiene
	 * clave única sobre (alumno, grupo) y nada impide dos filas vivas del mismo
	 * alumno. Aquí eso sería el mismo niño dos veces, con dos casillas distintas que
	 * se pisarían.
	 *
	 * @return array<int, object>
	 */
	private function alumnosDelGrupo(int $grupo_id): array
	{
		$filas = DB::select(
			'SELECT m.id AS matricula_id, m.alumno_id, al.nombres, al.apellidos
			   FROM matriculas m
			   INNER JOIN alumnos al ON al.id = m.alumno_id AND al.deleted_at IS NULL
			  WHERE m.grupo_id = ? AND m.deleted_at IS NULL
			    AND m.estado IN ("MATR", "ASIS", "PREM")
			  ORDER BY al.apellidos, al.nombres, m.alumno_id',
			[$grupo_id]
		);

		$alumnos = [];

		foreach ($filas as $fila) {
			$alumnos[(int) $fila->alumno_id] = (object) [
				'alumno_id'    => (int) $fila->alumno_id,
				'matricula_id' => (int) $fila->matricula_id,
				'nombres'      => $fila->nombres,
				'apellidos'    => $fila->apellidos,
			];
		}

		return $alumnos;
	}


	/**
	 * Cuántas frases vivas de esa asignatura y ese periodo son de alumnos que esta
	 * pantalla **no** enseña — un retirado, o uno que se cambió de grupo.
	 *
	 * **Cuesta un recorrido de tabla y se paga a sabiendas.** El índice de
	 * `frases_asignatura` empieza por `alumno_id`, así que un `WHERE` que sólo filtra
	 * asignatura y periodo no lo puede usar: medido con `EXPLAIN` el 17 sep 2026
	 * sobre las 12.322 filas del docker, `type=ALL` y **4,4 ms**, frente a los 0,37
	 * de la consulta de al lado. Se acepta porque es una vez al abrir la pantalla y
	 * porque un cero sin denominador no vale: sin este renglón, un retirado con
	 * frases es invisible y nadie puede saber que lo es.
	 *
	 * @param  list<int>  $alumno_ids
	 */
	private function frasesFueraDelGrupo($contexto, array $alumno_ids): int
	{
		$condicion = '';
		$parametros = [$contexto->asignatura_id, $contexto->periodo_id];

		if ($alumno_ids !== []) {
			$condicion = ' AND fa.alumno_id NOT IN ('.implode(', ', array_fill(0, count($alumno_ids), '?')).')';
			$parametros = array_merge($parametros, $alumno_ids);
		}

		$fila = DB::selectOne(
			'SELECT COUNT(*) AS n FROM frases_asignatura fa
			  WHERE fa.deleted_at IS NULL AND fa.asignatura_id = ? AND fa.periodo_id = ?'.$condicion,
			$parametros
		);

		return (int) ($fila->n ?? 0);
	}


	/**
	 * El `alumnos` del cuerpo, comprobado y ordenado por alumno.
	 *
	 * @return array<int, list<array{id: ?int, frase_id: ?int, frase: string}>>
	 */
	private function alumnosDelCuerpo(): array
	{
		$pedidos = Request::input('alumnos');

		if (! is_array($pedidos) || $pedidos === []) {
			abort(422, '`alumnos` tiene que ser una lista de `{alumno_id, frases: [...]}`.');
		}

		$salida = [];

		foreach ($pedidos as $pedido) {
			if (! is_array($pedido)) {
				abort(422, '`alumnos` trae algo que no es un alumno.');
			}

			$alumnoId = $this->comoId($pedido['alumno_id'] ?? null, 'alumnos.alumno_id');

			if ($alumnoId === null) {
				abort(422, 'Cada alumno tiene que traer `alumno_id`.');
			}

			if (isset($salida[$alumnoId])) {
				abort(422, "El alumno {$alumnoId} viene dos veces: la última lista ganaría "
					.'en silencio, y eso no lo ha pedido nadie.');
			}

			// **La lista tiene que venir, aunque venga vacía.** Omitirla y mandarla
			// vacía son dos cosas distintas y las dos se saben decir: `[]` es
			// «quítaselas todas», y no traer la clave sería borrarlas sin que nadie
			// lo hubiera pedido. Es la regla de `escala_id` en `putRejilla`.
			if (! array_key_exists('frases', $pedido) || ! is_array($pedido['frases'])) {
				abort(422, "El alumno {$alumnoId} tiene que traer `frases`, la lista COMPLETA de las "
					.'suyas en esa asignatura y ese periodo. `[]` es «quítaselas todas».');
			}

			$salida[$alumnoId] = $this->frasesDelCuerpo($alumnoId, $pedido['frases']);
		}

		return $salida;
	}


	/**
	 * Las frases de un alumno tal como vienen en el cuerpo.
	 *
	 * @param  array<mixed>  $frases
	 * @return list<array{id: ?int, frase_id: ?int, frase: string}>
	 */
	private function frasesDelCuerpo(int $alumno_id, array $frases): array
	{
		$salida = [];

		foreach ($frases as $entrada) {
			if (! is_array($entrada)) {
				abort(422, "El alumno {$alumno_id} trae una frase que no es una frase.");
			}

			$texto = $entrada['frase'] ?? null;

			if ($texto !== null && ! is_string($texto)) {
				abort(422, "El alumno {$alumno_id} trae una `frase` que no es texto.");
			}

			$salida[] = [
				'id'       => $this->comoId($entrada['id'] ?? null, 'frases.id'),
				'frase_id' => $this->comoId($entrada['frase_id'] ?? null, 'frases.frase_id'),
				// `TrimStrings` ya recortó lo que venía en el cuerpo; esto es para lo
				// que no pasa por él —un valor puesto a mano en un test— y para dejar
				// escrito en un solo sitio qué cuenta como vacío.
				'frase'    => $texto === null ? '' : trim($texto),
			];
		}

		return $salida;
	}


	/**
	 * Que los `frase_id` que vengan sean frases vivas del catálogo. Una consulta
	 * para todo el lote, y **antes** de escribir nada.
	 *
	 * Un `frase_id` que no existe deja una fila que el boletín imprime **en blanco**
	 * —`IFNULL(f.frase, fa.frase)` con las dos a `null`— y no se entera nadie hasta
	 * que sale el boletín.
	 *
	 * **No se comprueba el año del catálogo**, y no es un olvido: `frases.year_id`
	 * existe y `GET frases` ya devuelve sólo las del año de la sesión, así que la
	 * pantalla no puede ofrecer otras. Rechazarlas aquí sería una regla que no ha
	 * decidido nadie.
	 *
	 * @param  list<int>  $ids
	 */
	private function exigirFrasesDelCatalogo(array $ids): void
	{
		if ($ids === []) {
			return;
		}

		$filas = DB::select(
			'SELECT id FROM frases
			  WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')
			    AND deleted_at IS NULL',
			$ids
		);

		$vivas = [];

		foreach ($filas as $fila) {
			$vivas[(int) $fila->id] = true;
		}

		foreach ($ids as $id) {
			if (! isset($vivas[$id])) {
				abort(422, "`frase_id` {$id} no es una frase viva del catálogo.");
			}
		}
	}


	/**
	 * Una línea de auditoría por fila, con el alumno dentro. El porqué está en
	 * `putGrupo`.
	 *
	 * @param  array<string, mixed>|null  $antes
	 * @param  array<string, mixed>|null  $despues
	 */
	private function anotar(string $accion, int $id, int $alumno_id, $contexto, ?array $antes, ?array $despues): void
	{
		$linea = Auditoria::registrar();

		$linea = match ($accion) {
			'crear'  => $linea->crear('frase_asignatura', $id),
			'borrar' => $linea->borrar('frase_asignatura', $id),
			default  => $linea->editar('frase_asignatura', $id),
		};

		$linea->deAlumno($alumno_id, NombreDelAlumno::de($alumno_id))
			->en(
				asignatura: $contexto->asignatura_id,
				periodo: $contexto->periodo_id,
				grupo: $contexto->grupo_id,
				year: $contexto->year_id
			);

		if ($antes !== null) {
			$linea->de($antes);
		}

		if ($despues !== null) {
			$linea->a($despues);
		}

		$linea->guardar();
	}


	/**
	 * @param  list<object>  $frases
	 * @return array<string, mixed>
	 */
	private function alumnoParaElFront($alumno, array $frases): array
	{
		return [
			'alumno_id'    => $alumno->alumno_id,
			'matricula_id' => $alumno->matricula_id,
			'nombres'      => $alumno->nombres,
			'apellidos'    => $alumno->apellidos,
			'frases'       => array_map(fn ($fila) => $this->fraseParaElFront($fila), $frases),
		];
	}


	/** @return array<string, mixed> */
	private function fraseParaElFront($fila): array
	{
		return [
			'id'            => (int) $fila->id,
			// Lo que IMPRIME el boletín: el texto del catálogo cuando la fila apunta
			// a uno, y si no el suyo. Es el mismo campo que devuelve `show`.
			'frase'         => $fila->frase,
			// Lo que hay guardado EN ESTA FILA, que es `null` cuando es del catálogo.
			// Es lo que el `PUT` compara para saber si un guardado cambia algo.
			'frase_escrita' => $fila->frase_escrita,
			'frase_id'      => $fila->frase_id === null ? null : (int) $fila->frase_id,
			'tipo_frase'    => $fila->tipo_frase,
			'created_at'    => $fila->created_at,
		];
	}


	/**
	 * @param  list<array<string, mixed>>  $alumnos
	 * @param  array<string, int|bool>  $poblacion
	 * @return array<string, mixed>
	 */
	private function respuestaDelGrupo($user, $contexto, array $alumnos, array $poblacion): array
	{
		return [
			'asignatura_id'   => $contexto->asignatura_id,
			'grupo_id'        => $contexto->grupo_id,
			'year_id'         => $contexto->year_id,
			'periodo_id'      => $contexto->periodo_id,
			'periodo_abierto' => $contexto->periodo_abierto,
			'asignatura_cerrada' => $contexto->asignatura_cerrada,
			'puede_escribir'  => User::permiteEditarNotas($user, $contexto->periodo_id, $contexto->asignatura_id),
			'alumnos'         => $alumnos,
			'poblacion'       => $poblacion,
		];
	}


	/*
	 * Los dos de abajo vienen de `DesempenosController`, con la misma forma y las
	 * mismas respuestas: un identificador es un entero positivo, y lo que no lo sea
	 * es 422 y no un `(int)` silencioso — `(int) "abc"` es 0, y un 0 aquí sería una
	 * consulta que no encuentra nada y una pantalla que dice que no hay frases.
	 */

	private function comoId($valor, string $campo): ?int
	{
		if ($valor === null || $valor === '') {
			return null;
		}

		if (! $this->esIdentificador($valor)) {
			abort(422, "`{$campo}` no es un identificador.");
		}

		return (int) $valor;
	}

	private function esIdentificador($valor): bool
	{
		return is_scalar($valor) && preg_match('/^\d+$/', (string) $valor) === 1 && (int) $valor > 0;
	}

}


