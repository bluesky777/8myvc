<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Alumno;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Asignatura;
use App\Models\Debugging;
// `App\User` y no `App\Models\User`: el modelo de usuario no se mudó a Models/.
use App\User;
use \stdClass;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use \Log;
/**
 * Las columnas de `notas`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $nota
 * @property int $subunidad_id
 * @property int $alumno_id
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
 * @property mixed $valor  la nota ya calculada que se manda a la pantalla de edición
 */

class Nota extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;

	/*
	// Solo si la subunidad tiene cero notas
	public static function crearNotas($grupo_id, $subunidad, $user_id)
	{
		$alumnos 	= Grupo::alumnos($grupo_id);
		$now 		= Carbon::now('America/Bogota');

		foreach ($alumnos as $alumno) {
			DB::insert('INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?)', [$subunidad->id, $alumno->alumno_id, $subunidad->nota_default, $user_id, $now, $now]);
		}

		return;
	}
	*/

	// Verificar cada alumno si tiene nota en la subunidad
	public static function verificarCrearNotas($grupo_id, $subunidad, $user_id)
	{
		$alumnos 	= Grupo::alumnos($grupo_id);
		$now 		= Carbon::now('America/Bogota');

		foreach ($alumnos as $alumno) {
			$consulta = "INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at) 
				SELECT ?, ?, ?, ?, ?, ? FROM dual
				WHERE NOT EXISTS (
					SELECT 1 FROM notas WHERE subunidad_id=? AND alumno_id=? AND deleted_at IS NULL
				) LIMIT 1";

			DB::insert($consulta, [
				$subunidad->id,
				$alumno->alumno_id,
				$subunidad->nota_default,
				$user_id,
				$now,
				$now,
				$subunidad->id,
				$alumno->alumno_id,
			]);
		}

		return;
	}
	
	/**
	 * La fila de `notas` de UN alumno en UNA subunidad, creándola con la nota por
	 * defecto si no existe. Devuelve `true` si la creó.
	 *
	 * ## Por qué existe: «en notas de alumno no se pueden editar notas»
	 *
	 * `notas/detailed` —la planilla del profesor— llama a `verificarCrearNotas` y
	 * **crea las filas que falten antes de devolver nada**. `notas/alumno` no lo
	 * hacía: sólo leía, y cuando no había fila dejaba la subunidad **sin** la clave
	 * `nota`. El front pintaba la casilla vacía, el profesor tecleaba, y su
	 * `ng-model="subunidad.nota.nota"` se inventaba `{nota: 78}` **sin `id`**. De
	 * ahí salía `PUT notas/update/undefined`, que muere en el `catch` de
	 * `putUpdate` con un 422 «No se pudo guardar la nota» que no explica nada.
	 *
	 * Medido en la copia de desarrollo: **240 casillas** sin fila en el año 8 —228
	 * en el tercer periodo— repartidas en 40 alumnos. Le toca sobre todo **al que
	 * entra a mitad de año**, que es justo a quien se viene a poner notas a esa
	 * pantalla.
	 *
	 * ## Dos decisiones que no son obvias
	 *
	 * **Es de UN alumno y no del grupo entero**, al revés que `verificarCrearNotas`.
	 * Aquí se sabe de quién es la petición, y sembrarle el grupo completo a quien
	 * viene a mirar UNA ficha es escribir 30 veces más de lo que hace falta.
	 *
	 * **El `WHERE NOT EXISTS` y no un `SELECT` previo**: dos peticiones a la vez
	 * sobre la misma ficha —el profesor y el acudiente, o dos pestañas— insertarían
	 * las dos. Es el mismo idioma que ya usa `verificarCrearNotas`, y por lo mismo.
	 *
	 * El `deleted_at IS NULL` del NOT EXISTS también es el suyo: una nota borrada
	 * blandamente no cuenta como fila, así que la casilla vuelve a nacer. Es lo que
	 * hace hoy la planilla y no se cambia aquí — pero ojo con la fase 2 del
	 * [10](../../docs/migracion/10-definitivas.md): la clave única que se planea
	 * poner sobre `(subunidad_id, alumno_id)` **mira la tabla entera**, borradas
	 * incluidas, así que una gemela creada encima de una borrada la haría fallar.
	 * Población hoy en la base de desarrollo: **cero** pares con fila borrada y sin
	 * fila viva, de 1.165.685 notas. Medido, no supuesto, y anotado en 05 §234.
	 *
	 * ## `affectingStatement` y NO `DB::insert`, que es lo que estaba
	 *
	 * `DB::insert()` devuelve el bool de `statement()` —«la sentencia se ejecutó»—
	 * y **no** las filas afectadas: con el `NOT EXISTS` bloqueando el alta devuelve
	 * `true` igual. Medido contra la base: `DB::insert(...)` → `true`,
	 * `DB::affectingStatement(...)` → `0`, con la misma consulta y sin insertar
	 * nada. O sea que este método contestaba «la creé» siempre, y el `if` de
	 * `alumnoPeriodoDetalle` que lo lee no guardaba nada — se releía la fila
	 * también cuando no había nada que releer.
	 */
	public static function verificarCrearNota($alumno_id, $subunidad_id, $nota_default, $user_id): bool
	{
		$now = Carbon::now('America/Bogota');

		$consulta = 'INSERT INTO notas(subunidad_id, alumno_id, nota, created_by, created_at, updated_at)
			SELECT ?, ?, ?, ?, ?, ? FROM dual
			WHERE NOT EXISTS (
				SELECT 1 FROM notas WHERE subunidad_id=? AND alumno_id=? AND deleted_at IS NULL
			) LIMIT 1';

		$filas = DB::affectingStatement($consulta, [
			$subunidad_id, $alumno_id, $nota_default, $user_id, $now, $now,
			$subunidad_id, $alumno_id,
		]);

		return $filas > 0;
	}


	public static function puestoAlumno($promedio_alumno, $alumnos)
	{
		$puesto = 1;

		foreach ($alumnos as $alumno) {
			if ($alumno->promedio > $promedio_alumno) {
				$puesto += 1;
			}
		}

		return $puesto;
	}

	
	/**
	 * Todos los periodos.
	 *
	 * `$usuario` es **quién pregunta**, y es opcional para no tocar a quien ya
	 * llamaba con tres argumentos. Cuando viene, las filas de `notas` que falten se
	 * crean con la nota por defecto —ver `verificarCrearNota`—, y **periodo a
	 * periodo**: `alumnoPeriodoDetalle` le pregunta a `quienCreaLasNotas` en cada
	 * uno, porque el candado del periodo es de cada periodo y no del año.
	 */
	public static function alumnoPeriodosDetailed($alumno_id, $year_id, $profesor_id='', $usuario=null)
	{
		$alumno 	= Alumno::alumnoData($alumno_id, $year_id);
		
		if (!$alumno) {
			return false;
		}
		
		$escalas_val 	= DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$year_id]);
		$periodos 		= DB::select('SELECT * FROM periodos WHERE year_id=? and deleted_at is null', [ $year_id ]); 

		foreach ($periodos as $keyPer => $periodo) {
			Nota::alumnoPeriodoDetalle($periodo, $alumno->grupo_id, $alumno_id, $year_id, $profesor_id, $escalas_val, $usuario);
		}

		$alumno->periodos = $periodos;

		return $alumno;
	}
	
	
	/**
	 * Con qué `user_id` firmar las notas que falten en ESTE periodo, o `null` para
	 * no crear ninguna.
	 *
	 * ## Con el periodo cerrado no se crea nada, y no es una cautela mía
	 *
	 * Es la decisión que ya está tomada en `User::permiteEditarNotas` y anotada en
	 * 05 §47.2: con el periodo cerrado la pantalla **enseña lo que hay y no crea
	 * nada**. Ahí se decidió para `unidades/de-asignatura-periodo` y vale igual
	 * aquí, porque es la misma forma de ruta: una que lee y de paso escribe.
	 *
	 * Lo que sale de aplicarla:
	 *
	 *   - **superusuario** — crea siempre, también en un periodo cerrado. Es quien
	 *     arregla la ficha del alumno que llegó tarde, y el caso del parte.
	 *   - **profesor** — sólo con el periodo abierto. Con él cerrado tampoco podría
	 *     guardar la nota después: `pueden_editar_notas` le contestaría 400. Crear
	 *     la fila sería dejarle una casilla que no lleva a ningún sitio.
	 *   - **alumno y acudiente** — nunca. Vienen a mirar, y su lectura no debe
	 *     sembrar filas firmadas con su usuario.
	 *
	 * ## Se clona a `$usuario` a propósito
	 *
	 * `permiteEditarNotas` llama a `aplicarBanderasDelPeriodo`, que **escribe**
	 * `profes_pueden_editar_notas` y `profes_pueden_nivelar` sobre el objeto que
	 * recibe. Aquí se le pregunta una vez por periodo, así que sin el `clone` el
	 * usuario de la petición saldría de este bucle con las banderas del ÚLTIMO
	 * periodo mirado. Hoy nadie las lee después en `getAlumno`, pero eso es una
	 * propiedad de quien llama y no de esto.
	 *
	 * @param  object|null  $usuario
	 * @param  object  $periodo
	 */
	private static function quienCreaLasNotas($usuario, $periodo): ?int
	{
		if (!$usuario || !isset($usuario->user_id)) {
			return null;
		}

		if (!User::permiteEditarNotas(clone $usuario, (int) $periodo->id)) {
			return null;
		}

		return (int) $usuario->user_id;
	}


	/**
	 * Sólo UN periodo.
	 *
	 * `$usuario` es **quién pregunta**, no un `user_id` ya resuelto, y la decisión
	 * de sembrar se toma **aquí dentro** con `quienCreaLasNotas`. Es a propósito, y
	 * es la lección de 05 §47.2 —«al tapar un camino hay que preguntarse cuál es el
	 * otro»— aplicada a este mismo arreglo: por este método entran DOS rutas
	 * —`notas/alumno` y `notas/alumno-periodo-grupo`, la pantalla de promocionar—,
	 * las dos pintan casillas que el front guarda con `NotasApi.actualizar(nota.id)`
	 * y las dos sufrían el `notas/update/undefined`. Con la decisión resuelta por el
	 * llamante sólo la arreglaba **el llamante que se acordara**; aquí dentro la
	 * reciben las dos, y la tercera que aparezca.
	 */
	public static function alumnoPeriodoDetalle(&$periodo, $grupo_id, $alumno_id, $year_id, $profesor_id='', $escalas_val_param=null, $usuario=null) {
		$crear_por_user_id = self::quienCreaLasNotas($usuario, $periodo);

		$escalas_val = [];

		if ($escalas_val_param) {
			$escalas_val 	= $escalas_val_param;
		} else {
			$escalas_val 	= DB::select('SELECT * FROM escalas_de_valoracion WHERE year_id=? AND deleted_at is null', [$year_id]);
		}
		
		$asignaturas = Grupo::detailed_materias_notafinal($alumno_id, $grupo_id, $periodo->id, $year_id);
		$sumatoria_asignaturas_per = 0;
		
		foreach ($asignaturas as $keyAsig => $asignatura) {
			
			if($profesor_id != $asignatura->profesor_id && $profesor_id != ''){
				unset($asignaturas[$keyAsig]);
			}else{

				$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id, $alumno_id);

				foreach ($asignatura->unidades as $unidad) {
					$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
					
					for ($j=0; $j < count($unidad->subunidades); $j++) { 
						$nota = DB::select('SELECT * FROM notas n WHERE n.deleted_at is null and n.subunidad_id=? and n.alumno_id=?', [$unidad->subunidades[$j]->subunidad_id, $alumno_id]);
						
						// La casilla que no existía. Se crea con la nota por defecto y se
						// vuelve a leer, para que el resto siga trabajando sobre la fila de
						// verdad --con su `id`, que es lo único que le faltaba al front.
						//
						// `subunidad_id` y NO `id`: `Subunidad::deUnidad` renombra la columna
						// en el SELECT, así que estas filas no tienen `id`. Es la diferencia
						// con `verificarCrearNotas`, que recibe el `SELECT *` de `putDetailed`.
						if (count($nota) === 0 && $crear_por_user_id !== null) {
							$creada = Nota::verificarCrearNota(
								$alumno_id,
								$unidad->subunidades[$j]->subunidad_id,
								$unidad->subunidades[$j]->nota_default,
								$crear_por_user_id
							);

							if ($creada) {
								$nota = DB::select('SELECT * FROM notas n WHERE n.deleted_at is null and n.subunidad_id=? and n.alumno_id=?', [$unidad->subunidades[$j]->subunidad_id, $alumno_id]);
							}
						}
						
						if (count($nota) > 0) {
							$unidad->subunidades[$j]->nota = $nota[0];
							$des = EscalaDeValoracion::valoracion($unidad->subunidades[$j]->nota->nota, $escalas_val);
		
							if ($des) {
								$unidad->subunidades[$j]->nota->desempenio = $des->desempenio;
							}
						}
						
					}
				}

				// **El divisor son TODAS las asignaturas, monten unidades o no, y eso
				// es una decisión de Joseth del 28 ago 2026** — no el descuido que
				// parece.
				//
				// Se le planteó separando los dos casos, porque no son el mismo: (a) la
				// asignatura está montada y este alumno no tiene nota, y (b) **nadie
				// montó el periodo en esa asignatura**, así que no hay nada que
				// calificar para nadie del grupo. Su regla es que **las dos bajan el
				// promedio**, sin distinguir por qué falta la definitiva. Descartó
				// expresamente «si no hay unidades, no cuenta».
				//
				// O sea que el promedio de los treinta baja por una asignatura que el
				// docente no montó, y está elegido con eso escrito delante.
				//
				// **Filtrar aquí por «las que tienen nota» deshace esa decisión**, y es
				// lo que uno hace al leer esta línea sin el contexto. Si algún día hay
				// que cambiarlo, se cambia con una frase suya nueva, no con este
				// comentario.
				//
				// Nada de esto lo movió la guarda de «sin unidades no se escribe» del
				// 28 ago (`DefinitivasDeAsignatura`): `Grupo::detailed_materias_notafinal`
				// es un `LEFT JOIN`, así que la asignatura sigue en la lista aunque su
				// definitiva ya no exista, y un `null` suma 0 igual que el cero que antes
				// se inventaba. **Mismo numerador, mismo divisor, mismo número.**
				$sumatoria_asignaturas_per += $asignatura->nota_asignatura; // Para sacar promedio del periodo
				
				$asignatura->ausencias	= Ausencia::deAlumno($asignatura->asignatura_id, $alumno_id, $periodo->id);
				$asignatura->frases		= FraseAsignatura::deAlumno($asignatura->asignatura_id, $alumno_id, $periodo->id);
			
			
				$cantAus = 0;
				$cantTar = 0;
				foreach ($asignatura->ausencias as $ausencia) {
					if ($ausencia->tipo == "tardanza") {
						$cantTar += (int)$ausencia->cantidad_tardanza;
					}elseif ($ausencia->tipo == "ausencia") {
						$cantAus += (int)$ausencia->cantidad_ausencia;
					}
					
				}

				$asignatura->total_ausencias = $cantAus;
				$asignatura->total_tardanzas = $cantTar;
				
			}
		}
		
		$cant_asi = count($asignaturas);
		
		if($cant_asi > 0){
			$periodo->promedio = $sumatoria_asignaturas_per / count($asignaturas);
		} else {
			$periodo->promedio = 0;
		}
		
		// Comportamiento
		$consulta = 'SELECT n.*, p.nombres, p.apellidos, p.sexo, 
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					FROM nota_comportamiento n
					inner join matriculas m on m.alumno_id=n.alumno_id and m.deleted_at is null
					inner join grupos g on g.id=m.grupo_id and g.deleted_at is null and g.year_id=:year_id
					inner join profesores p on p.id=g.titular_id and p.deleted_at is null 
					left join images i on i.id=p.foto_id and i.deleted_at is null
					where n.alumno_id=:alumno_id and n.periodo_id=:periodo_id and n.deleted_at is null';
					
		$nota_comportamiento = DB::select($consulta, [
			':year_id'		=>$year_id, 
			':alumno_id'	=>$alumno_id, 
			':periodo_id'	=>$periodo->id
		]);
		
		if(count($nota_comportamiento) > 0){
			$nota_comportamiento = $nota_comportamiento[0];
		}else{
			$nota_comportamiento = [];
		}

		$periodo->asignaturas 			= $asignaturas;
		$periodo->nota_comportamiento 	= $nota_comportamiento;
	}



	public static function alumnoAsignaturasPeriodosDetailed($alumno_id, $year_id, $periodos_a_calcular='de_usuario', $periodo_usuario=0)
	{

		$alumno 		= Alumno::alumnoData($alumno_id, $year_id);
		$asignaturas 	= Grupo::detailed_materias($alumno->grupo_id);

		$sumatoria_asignaturas_year = 0;
		$sub_perdidas_year = 0;

		foreach ($asignaturas as $keyAsig => $asignatura) {

			$periodos = Periodo::hastaPeriodo($year_id, $periodos_a_calcular, $periodo_usuario);
			
			
			$sum_asignatura_year = 0;

			$subunidadesPerdidas = 0;

			foreach ($periodos as $keyPer => $periodo) {

				$asigna = new stdClass();
				$asigna->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo->id, $alumno_id);

				foreach ($asigna->unidades as $unidad) {
					$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
				}

				Asignatura::calculoAlumnoNotas($asigna, $alumno->alumno_id);

				$sum_asignatura_year += $asigna->nota_asignatura;

				$subunidadesPerdidas += Asignatura::notasPerdidasAsignatura($asigna);
				
			}

			try {
				$asignatura->nota_asignatura_year = ($sum_asignatura_year / count($periodos));
				$asignatura->subunidadesPerdidas = $subunidadesPerdidas;
			} catch (\Throwable $e) {
				$asignatura->nota_asignatura_year = 0;
			}

			$asignatura->periodos = $periodos;

			$sumatoria_asignaturas_year += $asignatura->nota_asignatura_year;
			$sub_perdidas_year += $subunidadesPerdidas;


		}

		try {
			$alumno->promedio_year = ($sumatoria_asignaturas_year / count($asignaturas));
			$alumno->sub_perdidas_year = $sub_perdidas_year;
		} catch (\Throwable $e) {
			$alumno->promedio_year = 0;
		}

		$alumno->asignaturas = $asignaturas;

		return $alumno;

	}


}



