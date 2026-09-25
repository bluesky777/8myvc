<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;

use App\Models\Debugging;
use App\Support\LaParcialYLaCobertura;
use App\Support\RepartoDeLaNota;
use App\Support\SellaConElReloj;

/**
 * Las columnas de `unidades`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property ?string $definicion
 * @property ?int $porcentaje
 * @property int $periodo_id
 * @property int $asignatura_id
 * @property ?int $obligatoria
 * @property ?int $orden
 * @property ?int $por_defecto
 * @property ?string $fecha
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */

class Unidad extends Model {

	use SellaConElReloj;
	use SoftDeletes;
	
	protected $fillable = [];
	protected $table = 'unidades';

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;






	public static function arreglarOrden($unidadesT, $asignatura_id, $periodo_id)
	{
		
		for ($i=0; $i < count($unidadesT); $i++) { 
			DB::update('UPDATE unidades SET orden=? WHERE id=?', [$i, $unidadesT[$i]->id]);
			$unidadesT[$i]->orden = $i;

			for ($j=0; $j < count($unidadesT[$i]->subunidades); $j++) { 
				DB::update('UPDATE subunidades SET orden=? WHERE id=?', [$j, $unidadesT[$i]->subunidades[$j]->id]);
				$unidadesT[$i]->subunidades[$j]->orden = $j;
			}
			
		}
		


		return $unidadesT;
	}



	
	/**
	 * Las unidades de una asignatura **para un alumno**, con el alcance del boletín
	 * independiente puesto.
	 *
	 * ## Por qué el alumno es OBLIGATORIO y va el último
	 *
	 * Obligatorio porque **todos sus llamadores lo tienen a mano** y **todos calculan
	 * algo de un alumno concreto**: ninguno pinta la estructura del grupo en la
	 * respuesta. Censados uno a uno el 26 ago 2026 —eran **diecisiete**: 13 por
	 * parámetro, 3 dentro de un `foreach` de alumnos y 1 por `Request::input`—, no
	 * deducidos.
	 *
	 * > **Ese diecisiete es una foto de aquel día y no hay que creérselo.** Se recuenta
	 * > con `grep -rn 'deAsignatura(' app/ | grep -v deAsignaturaCalculada`, y **lo que
	 * > sostiene la decisión no es el número: es la propiedad**, y la propiedad la
	 * > obliga el compilador. Un llamador nuevo que se olvide del alumno no es un
	 * > documento desactualizado: es `arguments.count` en `composer run stan`.
	 *
	 * Con un `= null` por defecto nada de eso pasaría: un sitio nuevo **se acotaría al
	 * grupo en silencio** y le escondería sus unidades a un independiente.
	 *
	 * **El último, y no el primero como en `deAsignaturaCalculada`.** La consistencia
	 * pierde contra la seguridad: si fuera el primero, un llamador sin actualizar
	 * seguiría teniendo tres argumentos válidos —alumno donde va la asignatura— y
	 * **devolvería filas equivocadas sin un solo error**. Al final, faltar se nota.
	 *
	 * ## Y por qué NO se sustituyó por `deAsignaturaCalculada`
	 *
	 * Porque **no es «el mismo método con el alcance puesto»**, que es lo que decía
	 * la lista de pendientes y resultó ser falso al abrirlo. Aquélla hace `left join`
	 * a `subunidades` y a `notas`, agrupa, y devuelve además `nota_unidad` —y en una
	 * de sus tres ramas, `desempenio` y las columnas de la escala—. Cambiar un
	 * llamador de ésta a aquélla **le cambiaría la forma de la respuesta y le metería
	 * un join por alumno** en los mismos boletines que ya están fichados por tardar
	 * 24–63 s. Son dos consultas distintas: ésta es la estructura, aquélla la
	 * estructura con notas.
	 *
	 * ## El alcance
	 *
	 * `null` mientras nadie esté marcado —que es siempre hoy— y entonces
	 * `u.alumno_id <=> NULL` selecciona **exactamente las filas de antes**: por eso
	 * esto entra sin regenerar un solo snapshot.
	 *
	 * **`<=>` y no `=`**: el igual null-safe empareja NULL con NULL, así que una
	 * condición cubre las dos ramas. Con `=` a secas el alumno normal no empareja
	 * nada y **se queda sin unidades**, sin un error en el log.
	 *
	 * @param  int|string  $alumno_id  de quién es la vista que se está calculando
	 */
	public static function deAsignatura($asignatura_id, $periodo_id, $alumno_id)
	{
		$alcance = \App\Services\BoletinIndependiente::alcance((int) $alumno_id, (int) $periodo_id);

		$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
						u.asignatura_id, u.orden as orden_unidad, u.periodo_id
					FROM unidades u
					where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null
						and u.alumno_id <=> :alcance
					order by u.orden, u.id';

		$unidades = DB::select($consulta, array(
			':asignatura_id'	=> $asignatura_id,
			':periodo_id'		=> $periodo_id,
			':alcance'			=> $alcance
		));

		return $unidades;
	}


	/**
	 * Con `$conSubunidades` cada unidad sale con sus `subunidades`, **las mismas filas que
	 * `Subunidad::deUnidadCalculada()`** y en el mismo orden: son las que este método ya
	 * trae para calcular la nota, sin la columna `unidad_id` que sólo servía para
	 * repartirlas. El boletín de periodo las tiraba y las volvía a pedir unidad por
	 * unidad: 2.660 consultas en un grupo de 38 (docs/migracion/48 §P3b).
	 */
	public static function deAsignaturaCalculada($alumno_id, $asignatura_id, $periodo_id, $con_desempenio='sin_desempenio', $year_id=0, $nota_minima=70, bool $conSubunidades = false)
	{
		// **El modo sale del `$year_id` que este método YA recibía**, no de un
		// parámetro nuevo. Con `$year_id=0` —el defecto— cae en `porcentaje`, que es
		// el comportamiento de siempre. Los dos llamantes que lo omitían pasaron a
		// pasarlo el 14 sep 2026: sin eso, esos dos boletines se habrían quedado en
		// porcentaje mientras la definitiva se guardaba en promedio.
		$modo = RepartoDeLaNota::modoDelAnio($year_id);


		/*
		 * De quién son las unidades que hay que traer. Es la fase 1 de
		 * docs/migracion/19-boletin-independiente.md, y esta llamada es **la
		 * puerta de los tres boletines**: `BoletinesController:303`,
		 * `Boletines2Controller:226,228` y `Boletines3Controller:238` pasan por
		 * aquí, y también `Informes/NotasActualesAlumnosController:187`, que el
		 * plan no nombraba y son cuatro consumidores, no tres.
		 *
		 * `null` mientras nadie esté marcado —que es siempre hoy—, y entonces
		 * `u.alumno_id <=> NULL` selecciona exactamente las filas de antes: por
		 * eso esto entra sin regenerar un solo snapshot.
		 *
		 * **`<=>` y no `=`**: el igual null-safe empareja NULL con NULL, así que
		 * una condición cubre las dos ramas. Con `=` a secas el alumno normal no
		 * empareja nada y su definitiva sale 0, sin un error en el log.
		 *
		 * `Subunidad::deUnidadCalculada`, que es lo que se llama justo después
		 * en los cuatro sitios, **no necesita nada**: va por `s.unidad_id` y la
		 * unidad ya viene elegida de aquí. El plan hablaba de «dos funciones» y
		 * medida son una y la que hereda de ella.
		 */
		$alcance = \App\Services\BoletinIndependiente::alcance((int) $alumno_id, (int) $periodo_id);

		/*
		 * ═══ LA NOTA DE LA UNIDAD SE CALCULA AQUÍ, EN PHP, DESDE EL 22 SEP 2026 ═══
		 *
		 * Vivía dentro de la consulta —un `SUM` sobre dos `LEFT JOIN` y un `GROUP BY u.id`—
		 * y bajó a PHP por encargo de Joseth: *«lo hice en SQL porque creí que era más
		 * rápido para la página, además sólo se calculaba por porcentaje; hoy se podría
		 * calcular por promedio a petición de cada colegio»*. Las dos mitades de esa frase
		 * son el motivo entero:
		 *
		 *  - **El modo `promedio`.** En SQL, repartir a partes iguales obliga a contar las
		 *    subunidades vivas con una **subconsulta correlacionada** por fila
		 *    ({@see RepartoDeLaNota::pesoDeSubunidad}), medida en su día en **×2 de
		 *    `Handler_read_key`**. Aquí es `count($subunidades)`.
		 *  - **La fórmula deja de estar en dos idiomas.** Era la misma cuenta escrita en SQL
		 *    aquí y en PHP en `Asignatura::calculoAlumnoNotas`, y la de aquí se quedó atrás
		 *    el día que la casilla vacía dejó de contar: dos sitios, dos verdades.
		 *
		 * **Las subunidades se traen en UNA consulta para toda la asignatura**, no una por
		 * unidad ({@see \App\Models\Subunidad::deLasUnidadesCalculadas}); antes esta consulta
		 * ya las recorría por dentro para poder sumar, así que lo que cambia es dónde se
		 * suma, no cuántas filas se leen.
		 *
		 * **Y la respuesta no se mueve ni un byte**: mismas claves, mismo orden y
		 * `nota_unidad` sigue viajando como **cadena** —lo que devolvía `ROUND()` por PDO—,
		 * porque de esta consulta cuelgan cuatro informes y los cuatro clientes. Un refactor
		 * que «de paso» arregla el tipo deja de ser comprobable.
		 */
		$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad,
						u.asignatura_id, u.orden as orden_unidad, u.periodo_id
					FROM unidades u
					where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null and u.alumno_id <=> :alcance
					order by u.orden, u.id';

		$unidades = DB::select($consulta, [
			':asignatura_id'	=> $asignatura_id,
			':periodo_id'		=> $periodo_id,
			':alcance'			=> $alcance,
		]);

		$porUnidad = \App\Models\Subunidad::deLasUnidadesCalculadas(
			array_map(fn ($u) => (int) $u->unidad_id, $unidades), $alumno_id, $year_id
		);

		// Las escalas del año, una vez y no una por unidad: es una consulta que no depende de
		// la unidad, y sólo hace falta en la rama que las unía.
		$escalas = $con_desempenio === 'con_desempenio'
			? self::escalasDelAnio($year_id)
			: [];

		$calculadas = [];

		foreach ($unidades as $unidad) {
			$nota = LaParcialYLaCobertura::deSusSubunidades(
				$porUnidad[(int) $unidad->unidad_id] ?? [], $modo
			);

			// **`ROUND` a entero y devuelto como cadena**, que es lo que hacía la consulta. El
			// redondeo va aquí y no dentro del helper por lo mismo que decía
			// `RepartoDeLaNota::notaDeLaUnidad`: se redondea una vez, al final y después de
			// dividir.
			$unidad->nota_unidad = $nota === null ? null : (string) (int) round($nota);

			if ($con_desempenio === 'fortaleza_debilidad') {
				// El `IF(... < :nota_minima, ...)` de la consulta, con su misma rareza: con
				// `nota_unidad` nula, `NULL < x` es `NULL` en SQL y aquí es `null` — una
				// unidad sin una sola casilla calificada no es ni lo uno ni lo otro.
				$desempenio = $unidad->nota_unidad === null
					? null
					: ((float) $unidad->nota_unidad < (float) $nota_minima ? 'Debilidad' : 'Fortaleza');

				// **El orden de las claves es el del `SELECT` que había**, y el objeto se
				// reconstruye para conservarlo: `desempenio` iba entre `porcentaje_unidad` y
				// `asignatura_id`, y un JSON con las mismas claves en otro orden mueve las
				// instantáneas de contrato sin que haya cambiado ningún dato.
				$ordenado = new \stdClass;
				$ordenado->unidad_id = $unidad->unidad_id;
				$ordenado->definicion_unidad = $unidad->definicion_unidad;
				$ordenado->porcentaje_unidad = $unidad->porcentaje_unidad;
				$ordenado->desempenio = $desempenio;
				$ordenado->asignatura_id = $unidad->asignatura_id;
				$ordenado->orden_unidad = $unidad->orden_unidad;
				$ordenado->periodo_id = $unidad->periodo_id;
				$ordenado->nota_unidad = $unidad->nota_unidad;

				$unidad = $ordenado;
			}

			if ($con_desempenio === 'con_desempenio') {
				// El `LEFT JOIN` con `escalas_de_valoracion` y su `SELECT *`: las columnas de
				// la escala se pegan **detrás** de las de la unidad. Con `LEFT JOIN` y sin
				// banda que case, esas claves siguen ahí en `null` —por eso se escriben todas
				// aunque no haya escala—, y la regla de la banda es
				// `porc_inicial <= nota < porc_final + 1`: el `+ 1` y nunca un `<=`, que es lo
				// que vigila `CentinelaDeLaReglaDeLaBandaTest`.
				$banda = null;

				if ($unidad->nota_unidad !== null) {
					foreach ($escalas as $escala) {
						if ((float) $escala->porc_inicial <= (float) $unidad->nota_unidad
							&& (float) $unidad->nota_unidad < (float) $escala->porc_final + 1) {
							$banda = $escala;
							break;
						}
					}
				}

				foreach (self::columnasDeLaEscala() as $columna) {
					$unidad->{$columna} = $banda?->{$columna};
				}
			}

			if ($conSubunidades) {
				$unidad->subunidades = array_map(static function ($fila) {
					$sub = clone $fila;
					unset($sub->unidad_id);

					return $sub;
				}, $porUnidad[(int) $unidad->unidad_id] ?? []);
			}

			$calculadas[] = $unidad;
		}

		return $calculadas;
	}


	/**
	 * La estructura de una asignatura **con sus tres banderas de configuración**, y
	 * por eso es la del GRUPO y no la de nadie en particular.
	 *
	 * ## El alcance, y por qué aquí es `alumno_id IS NULL` y no `<=>`
	 *
	 * Su hermana `deAsignaturaCalculada` recibe un `$alumno_id` y pregunta a
	 * `BoletinIndependiente::alcance()`. **Ésta no recibe ninguno**, y no es un
	 * olvido: sus dos llamantes —`AsignaturasController` :303 y :426— la usan para
	 * pintar «mis asignaturas» de un docente. Sin un alumno en el ámbito, la única
	 * respuesta con significado es la del grupo, así que el alcance correcto es la
	 * segunda forma de la §1.6 del reparto: `alumno_id IS NULL`. **La firma no
	 * cambia**, que es lo que había que comprobar antes de tocar un modelo.
	 *
	 * ## Y sin la condición, las tres banderas mienten
	 *
	 * `porc_unidades` suma los porcentajes de las unidades. Con un independiente en
	 * el grupo sumaría **el reparto del grupo más el suyo** —100 + 100 = 200— y la
	 * pantalla acusaría de mal configurada a una asignatura que está bien. Es
	 * exactamente lo que le pasó a `DefinitivasDeAsignatura::porcentajeDeLasUnidades`:
	 * *«¿las unidades suman 100?»* con dos boletines **no tiene una sola respuesta**,
	 * y allí se resolvió obligando al llamante a decir de qué boletín pregunta.
	 * Aquí no hace falta preguntarlo: el llamante es la pantalla del grupo.
	 *
	 * `porc_notas_incorrecto` va por el mismo camino: marcaría como «sin notas» las
	 * subunidades propias de un marcado, que en la planilla del grupo no las tiene
	 * nadie porque no son de nadie del grupo.
	 *
	 * **Lo que esto deja fuera a propósito:** si la estructura propia de un
	 * independiente está rota, este docente no lo ve aquí. Lo contesta la §6.1 con
	 * su `motivo = "sin_estructura_propia"` y lo barre
	 * `tools/independientes-sin-estructura.php` (§9.1), que son los dos sitios que
	 * existen para eso. Mezclarlo aquí no lo avisaría: lo taparía detrás de un
	 * porcentaje que ya no querría decir nada.
	 *
	 * Con nadie marcado no mueve una sola fila: hoy todas las unidades tienen
	 * `alumno_id` NULL.
	 */
	public static function informacionAsignatura($asignatura_id, $periodo_id)
	{
		$result = new \stdClass;

		
		$consulta = 'SELECT id, definicion, porcentaje, orden 
					FROM unidades
					where asignatura_id=:asignatura_id and periodo_id=:periodo_id and deleted_at is null
						and alumno_id is null
					order by orden';

		$unidades = DB::select($consulta, [
			':asignatura_id'	=> $asignatura_id,
			':periodo_id'		=> $periodo_id
		]);

		$porc_unidades = 0;
		$result->porc_subunidades_incorrecto = false;
		$result->porc_notas_incorrecto = false;

		foreach ($unidades as $unidad) {
			
			$porc_unidades += $unidad->porcentaje;

			$consulta = 'SELECT id, definicion, porcentaje, orden 
						FROM subunidades
						where unidad_id=:unidad_id and deleted_at is null
						order by orden';

			$unidad->subunidades = DB::select($consulta, array(
				':unidad_id'	=> $unidad->id,
			));

			$porc_subunidades = 0;

			foreach ($unidad->subunidades as $subunidad) {
				$porc_subunidades += $subunidad->porcentaje;

				#$notas = Nota::where('subunidad_id', $subunidad->id)->get();
				$notas = DB::select('SELECT * FROM notas WHERE deleted_at is null and subunidad_id=?', [$subunidad->id]);

				$subunidad->cantNotas = count($notas);

				if ($subunidad->cantNotas == 0) {
					$result->porc_notas_incorrecto = true;
				}

			}

			$unidad->porc_subunidades = $porc_subunidades ;

			if ($unidad->porc_subunidades != 100) {
				$result->porc_subunidades_incorrecto = true;
			}

		}


		$result->porc_unidades = $porc_unidades;
		$result->items = $unidades;

		return $result;
	}


	/**
	 * Las bandas de valoración del año, **una vez por llamada y no una por unidad**.
	 *
	 * Sustituyen al `LEFT JOIN escalas_de_valoracion` que llevaba dentro la consulta de
	 * `deAsignaturaCalculada`. Se ordenan por `porc_inicial` y se toma **la primera que
	 * case**, y ahí hay una diferencia con el `JOIN` que conviene tener escrita: si dos
	 * bandas se solaparan, el `JOIN` devolvía **la unidad repetida** —una fila por banda— y
	 * esto devuelve una sola. Es un colegio mal configurado en los dos casos; lo que cambia
	 * es que antes el boletín imprimía el criterio dos veces y ahora no.
	 *
	 * @return list<object>
	 */
	private static function escalasDelAnio($year_id): array
	{
		return array_values(DB::select(
			'SELECT * FROM escalas_de_valoracion
			  WHERE year_id = ? AND deleted_at IS NULL
			  ORDER BY porc_inicial, id',
			[$year_id]
		));
	}

	/**
	 * Los nombres de columna de `escalas_de_valoracion`, para poder **poner todas en `null`**
	 * cuando ninguna banda case.
	 *
	 * Es la mitad del `LEFT JOIN` que se olvida al traducirlo a PHP: sin banda, la fila
	 * seguía trayendo las claves vacías, y un cliente que pregunte por `valoracion` no
	 * encuentra lo mismo si la clave no está que si está en `null`.
	 *
	 * **Se leen del esquema y no se escriben a mano**: es la regla de esta casa para las
	 * columnas —`tools/columnas-en-los-modelos.php` existe por lo mismo—, y aquí además una
	 * lista a mano se quedaría corta el día que la tabla gane una columna, que es justo lo
	 * que `SELECT *` repartía solo. Se cachea por proceso porque no cambia dentro de una
	 * petición y este método se llama por unidad.
	 *
	 * @return list<string>
	 */
	private static function columnasDeLaEscala(): array
	{
		static $columnas = null;

		return $columnas ??= \Illuminate\Support\Facades\Schema::getColumnListing('escalas_de_valoracion');
	}

}