<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\DB;

use App\Models\Nota;
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


	public static function calculoAlumnoNotas(&$asignatura, $alumno_id)
	{
		$nota_asignatura = 0;

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

