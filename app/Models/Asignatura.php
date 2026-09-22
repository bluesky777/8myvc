<?php namespace App\Models;

use App\Support\SellaConElReloj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\DB;

use App\Models\Nota;
use App\Support\LaParcialYLaCobertura;
use App\User;
/**
 * Las columnas de `asignaturas`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $materia_id
 * @property int $grupo_id
 * @property ?int $profesor_id
 * @property ?int $nuevo_responsable_id
 * @property ?int $creditos
 * @property ?int $orden
 * @property ?int $domingo
 * @property ?int $lunes
 * @property ?int $martes
 * @property ?int $miercoles
 * @property ?int $jueves
 * @property ?int $viernes
 * @property ?int $sabado
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * Y los atributos que NO son columnas: el código se los cuelga al modelo en
 * tiempo de ejecución para armar la respuesta, que es un patrón repetido por
 * todo el proyecto. Eloquent los guarda entre los atributos y salen en el JSON,
 * así que forman parte del contrato con el frontend igual que las columnas;
 * anotarlos es lo que permite que el análisis siga avisando de un nombre mal
 * escrito en vez de callarse con todos.
 *
 * @property array $unidades  las unidades de la asignatura, cargadas aparte
 */


class Asignatura extends Model {
	protected $fillable = [];

	use SoftDeletes;
	use SellaConElReloj;
	protected $softDelete = true;


	/**
	 * Por qué `detallada()` no devolvió nada: **dos causas, dos mensajes**.
	 *
	 * Hasta el 2 sep 2026 las dos salían como *«Esa asignatura no es de este año»*,
	 * y para una de ellas eso es **falso**: la asignatura sí es de ese año, lo que
	 * le falta es profesor —el `inner join profesores` de arriba la deja fuera—.
	 * Es la §3.4 del [10](../../docs/migracion/10-definitivas.md) otra vez, en su
	 * forma cara: el mismo error para dos fallos distintos **manda a investigar a
	 * la persona equivocada**, que aquí se pone a mirar el año y el grupo, que
	 * están bien.
	 *
	 * ## Y no es un caso raro, está medido
	 *
	 * En la base de tests hay **146 asignaturas vivas de 1219 sin profesor** (12%),
	 * con **cero** apuntando a un profesor inexistente o borrado. O sea que no es
	 * corrupción: es un **estado normal del dominio**, la asignatura que todavía no
	 * tiene docente. Y se concentra donde más duele: **134 de 134 en el año
	 * siguiente** y 10 de 134 en el actual —los diez que midió el front—. Un
	 * colegio preparando el año que viene tiene hoy todas las planillas sin abrir y
	 * un mensaje que le habla de otra cosa.
	 *
	 * ## Y la decisión que este docblock daba por pendiente, ya se tomó
	 *
	 * Esto se escribió diciendo que el `inner join profesores` pasara a `left` era
	 * «una decisión de producto que espera a Joseth». **La contestó el mismo 2 sep
	 * 2026 y está aplicada** —ver el docblock de `detallada()`, justo debajo—, así
	 * que la planilla de una asignatura sin docente **abre**, no da 404.
	 *
	 * **Consecuencia directa: la rama de `profesor_id === null` de aquí abajo ya no
	 * la alcanza nadie por este camino.** Con el `left`, una asignatura sin profesor
	 * devuelve fila y no llega al `abort()`. Se conserva y no se borra por la regla
	 * de la casa —lo roto sin ruta se borra, lo alcanzable se documenta— y porque
	 * **es la red del día que alguien vuelva a poner un `inner`**: entonces el 404
	 * volvería, y el mensaje tiene que seguir diciendo cuál de las dos causas fue.
	 * Lo que sí sigue vivo y era el otro fallo del mismo día: distinguir «no existe»
	 * de «no es de este año».
	 *
	 * **El texto llega al cliente**, y está medido con el kernel de verdad y
	 * `APP_DEBUG=false`, que es como corren los quince colegios:
	 * `abort(404, 'texto')` devuelve `{"message": "texto"}` entero, y sólo el
	 * `abort(404)` **sin** texto sale con `message` vacío. En `app/` hay 45 `abort(404`
	 * y ninguno vivo sin texto.
	 *
	 * Una consulta más, y sólo en el camino del error: el caso bueno no la paga.
	 */
	private static function porQueNoSalio($asignatura_id, $year_id): string
	{
		$fila = DB::selectOne(
			'SELECT a.id, a.profesor_id, a.deleted_at, g.year_id
			   FROM asignaturas a
			   LEFT JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
			  WHERE a.id = ?',
			[$asignatura_id]
		);

		if ($fila === null || $fila->deleted_at !== null) {
			return 'Esa asignatura no existe';
		}

		if ((int) $fila->year_id !== (int) $year_id) {
			return 'Esa asignatura no es de este año';
		}

		if ($fila->profesor_id === null) {
			return 'Esa asignatura todavía no tiene profesor asignado';
		}

		// El profesor está puesto pero su fila no aparece. Hoy son cero casos
		// —medido: ninguna asignatura apunta a un profesor inexistente— y por eso
		// el mensaje dice lo que se sabe en vez de inventar una causa.
		return 'Esa asignatura tiene un profesor que ya no está';
	}

	/**
	 * La asignatura con su materia, su grupo y su profesor, para abrir la planilla.
	 *
	 * **`profesores` entra por `LEFT` y no por `INNER`, y esto arregla un 404 de hoy.**
	 * `asignaturas.profesor_id` es NULLABLE y una materia sin docente asignado es un
	 * estado normal del dominio, no corrupción: de 1219 asignaturas vivas medidas el
	 * 2 sep 2026, **146 no tienen profesor** —2 en 2019, **10 en el año actual (2025)** y
	 * **las 134 de 134 de 2026**—, con **cero** apuntando a un profesor inexistente y
	 * **cero** a uno borrado. El reparto importa más que el total: hoy el 404 lo pegan
	 * las diez de 2025, y **el año que 2026 pase a ser el actual lo pegarían todas**.
	 * Con `INNER` la consulta no devolvía filas, el `abort(404)` de abajo se disparaba
	 * y **su planilla no abría**, diciendo además lo que no era: «Esa asignatura no es
	 * de este año». `BoletinIndependienteController::estructuraDelGrupo()` ya había
	 * llegado a lo mismo por su cuenta y lo dejó escrito allí; esto es la otra mitad.
	 *
	 * **`p.deleted_at is null` entra en el mismo `ON`, y es el `LEFT` lo que lo hace
	 * inocuo.** El resto del fichero filtra los borrados y esta línea no lo hacía.
	 *
	 * **No son «cero casos», y conviene decirlo bien**: cero entre las **vivas**, pero
	 * la medición de las vivas no cubre a lo que llega esta consulta, porque
	 * `detallada()` **no filtra `a.deleted_at`** y sirve asignaturas de la papelera. Ahí
	 * hay **una**: la asignatura 187, borrada en 2018, cuyo profesor 16 también lo está.
	 * Alcanzarla exige un token del año 2018 —el `ON` une por `g.year_id`—, así que en
	 * la práctica no la pide nadie; pero es una fila real y su respuesta cambia: hoy
	 * sale con el nombre del docente borrado y a partir de aquí sale sin profesor.
	 *
	 * Lo que de verdad decide esta línea es **a qué se degrada el día que haya uno en un
	 * año vivo**: con `INNER` habría hecho **desaparecer la asignatura entera** —un 404
	 * nuevo por borrar a un docente—, y con `LEFT` se queda en «esta asignatura no tiene
	 * profesor», que es exactamente lo que es.
	 *
	 * **Lo que NO se toca aquí es el `a.deleted_at` que falta**, y no por olvido:
	 * añadirlo convertiría en 404 las asignaturas de la papelera que hoy contestan 200,
	 * que es una decisión del colegio y no de esta rama.
	 *
	 * **Ojo al `profesor_id` duplicado del SELECT**, que no es un descuido que se pueda
	 * limpiar sin pensarlo: viajan `a.profesor_id` y `p.id as profesor_id`, y con PDO
	 * **gana el último**, así que el campo vale `p.id`. Se deja así a propósito, porque
	 * es lo que mantiene la respuesta coherente consigo misma: `profesor_id` es `null`
	 * exactamente cuando `nombres_profesor` y `apellidos_profesor` son `null`. Cambiarlo
	 * a `a.profesor_id` haría salir un id con nombres vacíos al lado en cuanto haya un
	 * docente borrado, que es la forma de que una plantilla imprima «Prof.: » y nadie
	 * sepa por qué.
	 *
	 * Los cinco llamadores se revisaron uno a uno antes de tocar esto y **ninguno lee el
	 * profesor**: `AsignaturasController::getShow` la devuelve tal cual, y los otros
	 * cuatro sólo le sacan `grupo_id` y `asignatura_id`. Lo que sí queda expuesto es el
	 * JSON —`profesor_id`, `nombres_profesor` y `apellidos_profesor` pueden ser `null`
	 * donde antes nunca lo eran—, y eso es de los clientes: las cuatro plantillas que
	 * imprimen el nombre sin comprobarlo van en la rama de `myvc-front-11`, para
	 * desplegarse a la vez que esto.
	 *
	 * **Y con el `LEFT` puesto, `porQueNoSalio()` pierde una de sus tres ramas**: la de
	 * «todavía no tiene profesor» ya no se alcanza desde aquí, porque el caso que la
	 * disparaba ahora devuelve fila. Se deja escrita allí, con el porqué. Las dos
	 * ramas vivas siguen siendo «no existe» y «no es de este año», que era el otro
	 * fallo del mismo día: **un solo mensaje para dos causas manda a investigar a la
	 * persona equivocada.**
	 */
	public static function detallada($asignatura_id, $year_id)
	{
		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
						m.materia, m.alias as alias_materia, g.nombre as nombre_grupo, g.abrev as abrev_grupo, 
						g.titular_id, g.caritas, p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor
					FROM asignaturas a 
					inner join materias m on m.id=a.materia_id 
					inner join grupos g on g.id=a.grupo_id and g.year_id=:year_id and g.deleted_at is null 
					left join profesores p on p.id=a.profesor_id and p.deleted_at is null 
					where a.id=:asignatura_id 
					order by g.orden, a.orden';

		$asignatura = DB::select($consulta, [':asignatura_id' => $asignatura_id,
											':year_id' => $year_id]);


		// El `[0]` estaba suelto: la consulta une por `g.year_id`, así que una
		// asignatura de otro año no devuelve filas y esto respondía 500 —con la
		// traza dentro si `APP_DEBUG` está puesto—. No es un error del servidor:
		// es que esa asignatura no existe en el año desde el que se pregunta.
		// Ver 05 §16.
		if ($asignatura === []) {
			abort(404, self::porQueNoSalio($asignatura_id, $year_id));
		}

		return (array)$asignatura[0];
	}


	/**
	 * **El segundo calculador de la definitiva**, en PHP y paralelo al servicio — y
	 * desde el 20 sep 2026 también el de la **parcial** y la **cobertura**, que es la
	 * fase 1.bis del
	 * [43](../../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md).
	 *
	 * Produce `nota_asignatura`, que es el número de la planilla, y lo leen **seis
	 * sitios** (abajo). `DefinitivasDeAsignatura::calcular` produce el que se GUARDA en
	 * `notas_finales`. Son dos caminos distintos para el mismo número y este documento
	 * no los unifica: lo que hace es que los tres números viajen juntos por los dos.
	 *
	 *     peso(n)       = (porcentaje_unidad/100) x (porcentaje_subunidad/100)
	 *     nota_asignatura = SUM(peso x nota)  sobre TODAS        <- la de siempre, NO se mueve
	 *     nota_parcial    = SUM(peso x nota) / SUM(peso)         <- solo CALIFICADAS
	 *     cobertura       = SUM(peso) calificadas / SUM(peso) todas
	 *
	 * **El numerador de la parcial es `nota_asignatura` tal cual, y eso no es un
	 * atajo.** Desde la fase 0 una casilla sin calificar vale `null`, y en PHP
	 * `null * porcentaje / 100` es 0: el término desaparece de la suma igual que
	 * `SUM(peso * NULL)` lo salta en SQL. Lo único que hay que contar aparte es el
	 * divisor.
	 *
	 * ## Los dos `null`, que son dos hechos distintos y ninguno es un 0
	 *
	 * - **`SUM(peso)` de lo calificado = 0 -> `nota_parcial` es `null`.** Es la
	 *   diferencia entre *«va en cero»* y *«no hay con qué decirlo»*: un 0 ahí es una
	 *   nota perdida que nadie sacó. Es el gris del semáforo (D2).
	 * - **`SUM(peso)` TOTAL = 0 -> `cobertura` es `null`.** Un 0 afirmaría que se
	 *   conoce el plan y que no se ha tocado, y aquí no hay plan del que hablar: el
	 *   alumno no tiene ni una fila en `notas`, o todas sus casillas pesan 0. Medido
	 *   por el servicio en periodos abiertos, **3.158 de 9.422 pares, el 33,5 %** — no
	 *   es un rincón.
	 *
	 * **`cobertura` es un factor de 0 a 1, no un porcentaje.** Quien pinte «35 %»
	 * multiplica. Y ninguna de las dos se redondea: la regla de Joseth del 14 sep 2026
	 * es que se redondea en un solo sitio, el que escribe la definitiva, y aquí no se
	 * escribe nada.
	 *
	 * ## El divisor se acumula en ENTEROS, y no es manía
	 *
	 * `peso` se guarda como `porcentaje_unidad * porcentaje_subunidad` —producto de dos
	 * enteros— y no como `(pu/100)*(ps/100)`. Los dos 100 se van al final: en la
	 * cobertura **se cancelan solos** —es una razón entre dos sumas de la misma
	 * unidad— y en la parcial se reponen con un `* 10000`. Sumar cincuenta veces
	 * `0,7*0,3` en coma flotante mete un error que luego aparece en el cociente; sumar
	 * `2100` no mete ninguno. Los números son pequeños: 100x100 por casilla, unas
	 * decenas de casillas.
	 *
	 * ## Por qué el peso NO sale de `RepartoDeLaNota` aquí, aunque allí viva
	 *
	 * Porque **este método no reparte por `RepartoDeLaNota` y nunca lo ha hecho**: la
	 * acumulada de arriba multiplica por `$subunidad->porcentaje_subunidad`, que es
	 * `s.porcentaje` crudo tal como lo traen `Subunidad::deUnidad` y
	 * `Unidad::deAsignatura` — **los seis lectores usan esas dos**. En modo `promedio`
	 * el servicio pesa `1/n` y esto sigue pesando `s.porcentaje`, así que los dos
	 * calculadores **ya discrepaban antes de esta fase**: medido el 20 sep 2026 sobre
	 * `simonbolivar`, en el único año de la copia que está en `promedio` (2026),
	 * **14 de 99** pares alumno-asignatura-periodo dan distinto, y el peor **42,3
	 * puntos** sobre una escala de 0 a 50.
	 *
	 * Sacar el peso de `RepartoDeLaNota` arreglaría el divisor **y dejaría la parcial
	 * midiendo un reparto que la acumulada de al lado no usa**: en `promedio` el
	 * cociente `nota_asignatura / SUM(peso)` dejaría de ser una nota de nada. Así que
	 * el peso sale **de los mismos dos números que la acumulada**, y con eso
	 * `nota_parcial * SUM(peso) == nota_asignatura` se cumple siempre, en los dos
	 * modos. *La discrepancia de `promedio` es anterior, es de la acumulada, y cerrarla
	 * mueve el número que imprimen los dieciséis: es otra decisión y está apuntada en
	 * el 43 §7.*
	 *
	 * ## Los seis lectores, y cuáles publican los dos números
	 *
	 * | lector | ruta | ¿los publica? |
	 * |---|---|---|
	 * | `PlanillasController::getShowProfesor` | `GET planillas/show-profesor/{id}` | **sí**, por periodo |
	 * | `Informes\NotasPerdidasController::getShowProfesor` | `GET notas-perdidas/show-profesor/{id}` | **sí**, por periodo |
	 * | `Informes\PlanillasAusenciasController::getShowProfesor` | `GET planillas-ausencias/show-profesor/{id}` | **sí**, por periodo |
	 * | `DetallesController::putGruposPeriodos` | `PUT detalles/grupos-periodos` | **sí**, sin código: devuelve la asignatura entera |
	 * | `EditnotaController::allNotasAlumno` | `PUT editnota/detailed-notas/{grupo}` | **sí**, sin código: ídem |
	 * | `Nota::alumnoAsignaturasPeriodosDetailed` | `GET boletines{,2,3}/detailed-notas-year/…` | **no** |
	 *
	 * El sexto no los publica y **no es un olvido**: ese método no saca ninguna
	 * definitiva por periodo — promedia las cuatro y publica `nota_asignatura_year`—,
	 * así que el único sitio donde cabrían sería una «parcial del año», que **no está
	 * definida en ninguna parte**. Promediar cuatro parciales con denominadores
	 * distintos no es la parcial de nada. Queda abierto en el 43.
	 *
	 * > **Y hay una SÉPTIMA copia de esta misma cuenta que no llama aquí**:
	 * > `EditnotaController::notasDeLaAsignatura` la lleva escrita en línea
	 * > (`:111` y `:118`). Sirve `PUT editnota/alum-asignatura` y **no gana los dos
	 * > números**, porque unificarla es tocar lo que imprime `editnota-alum-asignatura`
	 * > y eso es un lote propio. Se deja dicho para que el siguiente censo dé siete y
	 * > no seis.
	 */
	public static function calculoAlumnoNotas(&$asignatura, $alumno_id)
	{
		$nota_asignatura = 0;

		// Los dos divisores, en «puntos de porcentaje al cuadrado» y enteros — ver la
		// cabecera. Se acumulan **dentro del `if` que comprueba que la casilla existe**,
		// que es lo que hace a esta cuenta la misma que la del servicio: allí el
		// `INNER JOIN notas` deja fuera la subunidad sin fila para ese alumno, y aquí la
		// deja fuera ese `if`. Una subunidad sin fila no está en el plan de nadie.
		$peso_evaluado = 0;
		$peso_total = 0;

		foreach ($asignatura->unidades as $unidad) {
			
			$nota_unidad = 0;

			foreach ($unidad->subunidades as $subunidad) {
				
				// **Las diez columnas nombradas y no `*`**, desde el 2 sep 2026: esta fila
				// se cuelga entera de la subunidad (`$subunidad->nota = $nota`) y viaja
				// al boletín final, así que con el asterisco las cinco columnas de la
				// nivelación (`2026_09_02_100000_nivelaciones_columnas`) movieron
				// `bolfinales-detailed-notas-year*.json` sin que nadie tocara este
				// método. Lo cazó la suite. Cuándo y cómo imprime el boletín el par
				// original/nivelación es la tarea A10 del 22, y se abre ahí a propósito.
				$nota = DB::select('SELECT id, nota, subunidad_id, alumno_id, created_by, updated_by, deleted_by, deleted_at, created_at, updated_at
					FROM notas WHERE subunidad_id=? AND alumno_id=? AND deleted_at is null', [ $subunidad->subunidad_id, $alumno_id ]);

				if (count($nota)>0) {
					$nota = $nota[0];
					$subunidad->nota = $nota;

					$subunidad->nota->valor = ($nota->nota * $subunidad->porcentaje_subunidad) / 100;
					$nota_unidad += $subunidad->nota->valor;

					// Los dos `(int)` no son cosmética: `porcentaje` es `int DEFAULT 0`
					// **anulable** en las dos tablas, y un `null` tiene que pesar 0 —que es
					// exactamente lo que ya hace la línea de arriba, donde `nota * null / 100`
					// da 0—. En SQL daría `NULL` y `SUM` saltaría la fila; aquí el divisor
					// tiene que seguir a la acumulada, no al otro camino.
					$peso = (int) $unidad->porcentaje_unidad * (int) $subunidad->porcentaje_subunidad;

					$peso_total += $peso;

					// **`!== null` y no `> 0`**: desde la fase 0 el `null` es «sin calificar» y
					// el 0 es una nota que alguien puso. Eran indistinguibles y ése es el bug
					// entero del 43; confundirlos aquí lo reintroduce en el divisor.
					if ($nota->nota !== null) {
						$peso_evaluado += $peso;
					}
				}
				
			}

			$unidad->nota_unidad 	= $nota_unidad;
			$valor_unidad 			= ($unidad->nota_unidad * $unidad->porcentaje_unidad) / 100;
			$unidad->valor_unidad 	= $valor_unidad;

			$nota_asignatura += $unidad->valor_unidad;
		}


		// Sin `round()` desde la migración `2026_08_30_200000_notas_finales_en_decimal`.
		//
		// Este método **no escribe**: lo llaman seis lectores —planillas, detalles,
		// editnota, notas perdidas, planillas de ausencias y `Nota::alumnoAsignaturas`—
		// y aun así el redondeo de aquí es el mismo defecto una planta más arriba:
		// `Nota:439` suma esta definitiva de los cuatro periodos y la divide, así que
		// redondear **antes** de promediar volvía a empatar el promedio del año igual
		// que lo hacía la columna. Y es lo que hacía que la planilla y el boletín
		// dijeran números distintos del mismo alumno.
		$asignatura->nota_asignatura = $nota_asignatura; // Definitiva de la materia

		// **Las dos fórmulas viven en `App\Support\LaParcialYLaCobertura` desde el 20 sep
		// 2026, y aquí no queda más que la llamada.** No es aseo: la [Fase 2 del
		// 43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md) las necesita
		// también en el boletín, que **no pasa por este método** —va por
		// `Grupo::detailed_materias_notafinal` y `notas_finales`—, y dos copias de una
		// cuenta son dos copias que divergen. Pasó con las definitivas y con la bitácora, y
		// por eso existen `DefinitivasDeAsignatura` y `Auditoria`.
		//
		// Lo que NO se movió es el bucle de arriba: los acumuladores, los `(int)` del peso y
		// el `!== null` siguen aquí, porque cómo se sabe que una casilla existe es distinto
		// en cada llamante —aquí un `count()`, en el boletín un `nota_id` de un `LEFT JOIN`—.
		// Que esto no cambió ni un número lo prueba que no se movió ninguna instantánea.
		$asignatura->nota_parcial = LaParcialYLaCobertura::parcial($nota_asignatura, $peso_evaluado);

		// El porqué de cada `null` y la advertencia de los tipos en el JSON están en el
		// helper, que es donde ahora los lee quien vaya a tocarlos.
		$asignatura->cobertura = LaParcialYLaCobertura::cobertura($peso_evaluado, $peso_total);

		return $asignatura;
	}




	public static function calculoAlumnoNotas2(&$asignatura, $alumno_id)
	{
		$nota_asignatura = 0;
/*
		foreach ($asignatura->unidades as $unidad) {
			
			$nota_unidad = 0;

			foreach ($unidad->subunidades as $subunidad) {
				
				// **Las diez columnas nombradas y no `*`**, desde el 2 sep 2026: esta fila
				// se cuelga entera de la subunidad (`$subunidad->nota = $nota`) y viaja
				// al boletín final, así que con el asterisco las cinco columnas de la
				// nivelación (`2026_09_02_100000_nivelaciones_columnas`) movieron
				// `bolfinales-detailed-notas-year*.json` sin que nadie tocara este
				// método. Lo cazó la suite. Cuándo y cómo imprime el boletín el par
				// original/nivelación es la tarea A10 del 22, y se abre ahí a propósito.
				$nota = DB::select('SELECT id, nota, subunidad_id, alumno_id, created_by, updated_by, deleted_by, deleted_at, created_at, updated_at
					FROM notas WHERE subunidad_id=? AND alumno_id=? AND deleted_at is null', [ $subunidad->subunidad_id, $alumno_id ]);

				if (count($nota)>0) {
					$nota = $nota[0];
					$subunidad->nota = $nota;

					$subunidad->nota->valor = ($nota->nota * $subunidad->porcentaje_subunidad) / 100;
					$nota_unidad += $subunidad->nota->valor;
				}
				
			}

			$unidad->nota_unidad 	= $nota_unidad;
			$valor_unidad 			= ($unidad->nota_unidad * $unidad->porcentaje_unidad) / 100;
			$unidad->valor_unidad 	= $valor_unidad;

			$nota_asignatura += $unidad->valor_unidad;
		}
*/

		$asignatura->nota_asignatura = $nota_asignatura; // Definitiva de la materia

		return $asignatura;
	}



	public static function notasPerdidasAsignatura($asignatura)
	{
		$notas_perdidas = 0;

		foreach ($asignatura->unidades as $unidad) {
			
			foreach ($unidad->subunidades as $subunidad) {
				
				if (isset($subunidad->nota->nota)) {
					if ($subunidad->nota->nota < User::$nota_minima_aceptada) {
						$notas_perdidas++;
					}
				}
				
			}

		}

		return $notas_perdidas;
	}



}

