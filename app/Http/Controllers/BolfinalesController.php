<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Year;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Asignatura;
use App\Models\Subunidad;
use App\Models\Unidad;
use App\Models\Profesor;
use App\Models\Nota;
use App\Services\BoletinIndependiente;


class BolfinalesController extends Controller {



	public function putDetailedNotasYearGroup($grupo_id)
	{
		$user = User::fromToken();

		$boletines = $this->detailedNotasGrupo($grupo_id, $user);

		//$grupo->alumnos = $alumnos;
		//$grupo->asignaturas = $asignaturas;
		//return (array)$grupo;

		return $boletines;


	}



	public function putDetailedNotasYear($grupo_id)
	{
		$user = User::fromToken();

		
		$requested_alumnos = '';

		if (Request::has('requested_alumnos')) {
			$requested_alumnos = Request::get('requested_alumnos');
		}

		$boletines = $this->detailedNotasGrupo($grupo_id, $user, $requested_alumnos);

		//$grupo->alumnos = $alumnos;
		//$grupo->asignaturas = $asignaturas;
		//return (array)$grupo;

		return $boletines;


	}

	public function detailedNotasGrupo($grupo_id, $user, $requested_alumnos='')
	{
		
		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id, $requested_alumnos);

		$year->periodos = $this->periodosDelAnio($user->year_id);

		$grupo->cantidad_alumnos = count($alumnos);

		// Los dos recuentos del grupo entero, **fuera de los bucles y en dos
		// consultas**. Eran los dos bucles anidados alumno x asignatura x periodo:
		// 1.480 + 1.480 de las 3.820 de la petición (05 §224).
		//
		// **Son dos mapas y no uno, a propósito.** Las dos consultas cuentan «notas
		// perdidas» pero no son la misma: la de `asignaturasPerdidasDeAlumno` une con
		// `matriculas` y no mira `deleted_at`, y la de `definitivasMateriasXPeriodo`
		// filtra `deleted_at` en subunidades y unidades y no une con `matriculas`.
		// Fundirlas cambiaría los números en las filas que difieren.
		$perdidasDelGrupo		= $this->perdidasPorAlumnoDelGrupo($grupo_id, $alumnos, $year->periodos);
		$perdidasPorDefinitiva	= $this->perdidasPorDefinitivaDelGrupo($grupo_id, $alumnos);

		$response_alumnos = [];
		

		foreach ($alumnos as $alumno) {

			// Todas las materias con sus unidades y subunides
			$this->definitivasMateriasXPeriodo($alumno, $grupo_id, $user->year_id, $year->periodos, $perdidasPorDefinitiva);


			$asignaturas_perdidas = $this->asignaturasPerdidasDeAlumno($alumno, $grupo_id, $user->year_id, $perdidasDelGrupo, $year->periodos);

			if (count($asignaturas_perdidas) > 0) {
				
				$alumno->asignaturas_perdidas = $asignaturas_perdidas;
				$alumno->notas_perdidas_year = 0;
				$alumno->periodos_con_perdidas = $this->periodosDelAnio($user->year_id);

				foreach ($alumno->periodos_con_perdidas as $keyPerA => $periodoAlone) {

					$periodoAlone->cant_perdidas = 0;
					
					foreach ($alumno->asignaturas_perdidas as $keyAsig => $asignatura_perdida) {

						foreach ($asignatura_perdida->periodos as $keyPer => $periodo) {

							// **Este `if` de fuera no filtra nada, y conviene saberlo antes
							// de «arreglarlo».** `periodos` no tiene columna `periodo_id`, y
							// los dos lados salen de `periodosDelAnio()`, así que los dos dan
							// `null` y la comparación siempre pasa: **quien decide es el `id`
							// de dentro**. Se deja como estaba porque el resultado es el de
							// hoy, y se anota porque es una mina: si alguien cambiara
							// `periodosDelAnio()` por un `SELECT id as periodo_id, ...` —como
							// hace `Periodo::hastaPeriodoN()`— **sólo uno de los dos lados
							// tendría el campo** y este `if` empezaría a descartar periodos de
							// verdad. Es otra razón para no cambiar aquí Eloquent por SQL crudo.
							if ($periodoAlone->periodo_id == $periodo->periodo_id) {
								if ($periodo->id == $periodoAlone->id) {
									$periodoAlone->cant_perdidas += $periodo->cantNotasPerdidas;
								}
								
							}
						}
					}

					$alumno->notas_perdidas_year += $periodoAlone->cant_perdidas;
					
				}
			}
		}


		foreach ($alumnos as $alumno) {
			
			$alumno->puesto = Nota::puestoAlumno($alumno->promedio, $alumnos);
			
			if ($requested_alumnos == '') {

				array_push($response_alumnos, $alumno);

			}else{

				foreach ($requested_alumnos as $req_alumno) {
					
					if ($req_alumno['alumno_id'] == $alumno->alumno_id) {
						array_push($response_alumnos, $alumno);
					}
				}
			}
			

		}


		return array($grupo, $year, $response_alumnos);
	}

	/**
	 * @param  array<int, array<int, array<int, int>>>|null  $perdidasPorDefinitiva  ver perdidasPorDefinitivaDelGrupo()
	 */
	public function definitivasMateriasXPeriodo(&$alumno, $grupo_id, $year_id, $periodos, $perdidasPorDefinitiva = null)
	{
		$deEsteAlumno = $perdidasPorDefinitiva[(int) $alumno->alumno_id] ?? [];

		$alumno->asignaturas	= Grupo::detailed_materias($grupo_id);

		$alumno->promedio = 0;
		$alumno->cant_lost_asig = 0;
		$alumno->ausencias = 0;
		$alumno->tardanzas = 0;
		$alumno->notas_perdidas = 0;


		foreach ($alumno->asignaturas as $asignatura) {
			
			$consulta = 'SELECT alumno_id, asignatura_id, periodo_id, numero_periodo,
							creditos, sum( ValorUnidad ) DefMateria, cantidad_ausencia, cantidad_tardanza 
						FROM(
							SELECT n.alumno_id, a.id as asignatura_id, a.profesor_id, 
								a.creditos, u.periodo_id, u.definicion, u.id as unidad_id, u.porcentaje as porc_unidad, 
								s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad, p.numero as numero_periodo, 
								sum( ((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) ) ValorUnidad,
								aus.cantidad_ausencia, tar.cantidad_tardanza
							FROM asignaturas a 
							inner join unidades u on u.asignatura_id=a.id and u.deleted_at is null
							inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null
							inner join notas n on n.subunidad_id=s.id and n.alumno_id=:alumno_id and n.deleted_at is null
							inner join periodos p on p.year_id=:year_id and p.id=u.periodo_id and p.deleted_at is null
							left join (
								select count(au.id) as cantidad_ausencia, au.alumno_id, au.periodo_id, au.asignatura_id
								from ausencias au 
								where au.deleted_at is null and au.cantidad_ausencia > 0
								group by au.alumno_id, au.periodo_id, au.asignatura_id
								
								)as aus on aus.alumno_id=n.alumno_id and aus.asignatura_id=a.id and aus.periodo_id=p.id
							left join (
								select count(au.id) as cantidad_tardanza, au.alumno_id, au.periodo_id, au.asignatura_id
								from ausencias au 
								where au.deleted_at is null and au.cantidad_tardanza > 0
								group by au.alumno_id, au.periodo_id, au.asignatura_id
								
								)as tar on tar.alumno_id=n.alumno_id and tar.asignatura_id=a.id and tar.periodo_id=p.id
							where a.grupo_id=:grupo_id and a.deleted_at is null and a.id=:asignatura_id
							group by n.alumno_id, s.unidad_id
						)r
						group by alumno_id, asignatura_id, periodo_id
						order by numero_periodo, asignatura_id, periodo_id';
		
			$asignatura->definitivas = DB::select($consulta, array(
										':alumno_id'	=> $alumno->alumno_id, 
										':year_id'		=> $year_id,
										':grupo_id'		=> $grupo_id,
										':asignatura_id'=> $asignatura->asignatura_id
									));




			// Agrego Periodos ficticios al array para llenar la tabla con espacios vacios.
			$per_faltantes = count($periodos) - count($asignatura->definitivas);

			if($per_faltantes > 0){
				for($i=0; $i<$per_faltantes; $i++){
					$prov = (object)['DefMateria'=>0,'cantidad_ausencia'=>0,'cantidad_tardanza'=>0,'periodo_id'=>-1];
					array_push($asignatura->definitivas, $prov);
				}
			}


			// Hallamos las ausencias y tardanzas
			$suma_def = 0;
			$suma_aus = 0;
			$suma_tar = 0;
			$notas_perd = 0;
			
			foreach ($asignatura->definitivas as $keydef => $definitiva) {
				$suma_def += (float)$definitiva->DefMateria;
				$suma_aus += (int)$definitiva->cantidad_ausencia;
				$suma_tar += (int)$definitiva->cantidad_tardanza;


				// Cuántas notas tiene perdidas por cada definitiva: **del mapa del
				// grupo, no de una consulta por definitiva**. Era una por (alumno x
				// asignatura x periodo) — la mitad de los dos bucles anidados.
				//
				// Un par sin fila en el `GROUP BY` es cero perdidas, que es lo mismo
				// que devolvía el `COUNT()` sobre ninguna fila. Y el `COUNT()` siempre
				// traía exactamente una fila, así que el `if (count(...) > 0)` de antes
				// se cumplía siempre: no había una rama de «sin datos» que perder.
				$definitiva->notas_perdidas = $deEsteAlumno[(int) $asignatura->asignatura_id][(int) $definitiva->periodo_id] ?? 0;

				$notas_perd += $definitiva->notas_perdidas;
			}
			$asignatura->promedio = $suma_def / count($asignatura->definitivas);
			$asignatura->ausencias = $suma_aus;
			$asignatura->tardanzas = $suma_tar;
			$asignatura->notas_perdidas = $notas_perd;

			$alumno->promedio += $asignatura->promedio;
			$alumno->ausencias += $asignatura->ausencias;
			$alumno->tardanzas += $asignatura->tardanzas;
			$alumno->notas_perdidas += $asignatura->notas_perdidas;


			// Si es un promedio perdido, debo sumarlo como una asignatura perdida
			if ($asignatura->promedio < User::$nota_minima_aceptada) {
				$alumno->cant_lost_asig += 1;
			}

		}

		$alumno->promedio = $alumno->promedio / count($alumno->asignaturas);


		return $alumno;
	}


	/**
	 * @param  array<int, array<int, array<int, int>>>|null  $perdidasDelGrupo  ver perdidasPorAlumnoDelGrupo()
	 * @param  \Illuminate\Support\Collection<int, \App\Models\Periodo>|null  $periodosDelAnio
	 */
	public function asignaturasPerdidasDeAlumno($alumno, $grupo_id, $year_id, $perdidasDelGrupo = null, $periodosDelAnio = null)
	{
		$asignaturas	= Grupo::detailed_materias($grupo_id);

		$deEsteAlumno = $perdidasDelGrupo[(int) $alumno->alumno_id] ?? [];


		foreach ($asignaturas as $keyAsig => $asignatura) {
			
			// **`clone` y no el mismo objeto, y esto no es prudencia.** El bucle de
			// abajo escribe `cantNotasPerdidas` DENTRO del periodo, así que compartir
			// los objetos entre asignaturas haría que todas mostraran la cuenta de la
			// última. Antes no pasaba porque cada asignatura hacía su propio
			// `Periodo::where(...)->get()` y recibía objetos nuevos; al sacar la
			// consulta del bucle, el clon es lo que conserva ese comportamiento.
			//
			// **No lo ve ninguna cota de consultas** —mismo número de consultas,
			// resultado distinto—, que es la trampa que el hermano ya pagó.
			$asignatura->periodos = $periodosDelAnio !== null
				? $periodosDelAnio->map(static fn ($p) => clone $p)
				: $this->periodosDelAnio($year_id);

			$asignatura->cantTotal = 0;

			foreach ($asignatura->periodos as $keyPer => $periodo) {

				
				// Del mapa del grupo, no de una consulta por periodo. Era la otra
				// mitad de los dos bucles anidados: una por (alumno x asignatura x
				// periodo). Un par sin fila en el `GROUP BY` es cero perdidas, que es
				// lo mismo que devolvía `count()` sobre un resultado vacío.
				$periodo->cantNotasPerdidas = $deEsteAlumno[(int) $asignatura->asignatura_id][(int) $periodo->id] ?? 0;

				$asignatura->cantTotal += $periodo->cantNotasPerdidas;


				if ($periodo->cantNotasPerdidas == 0) {
					unset($asignatura->periodos[$keyPer]);
				}
				
				
			}

			if (count($asignatura->periodos) == 0) {
				unset($asignaturas[$keyAsig]);
			}

			$hasPeriodosConPerdidas = false;

			foreach ($asignatura->periodos as $keyPer => $periodo) {
				if ($periodo->cantNotasPerdidas > 0) {
					$hasPeriodosConPerdidas = true;
				}
			}

			if (!$hasPeriodosConPerdidas) {
				unset($asignaturas[$keyAsig]);
			}

		}

		return $asignaturas;

	}

	/**
	 * Los periodos del año, **una vez por petición**, y en COPIAS.
	 *
	 * Las tres llamadas a `Periodo::where('year_id', ...)->get()` que había aquí
	 * —una en `detailedNotasGrupo`, una por alumno con perdidas y **una por
	 * (alumno x asignatura)**— eran **408 ejecuciones de la misma consulta** en una
	 * sola petición, medidas sobre 37 alumnos x 10 asignaturas (05 §224). La
	 * consulta no depende del alumno ni de la asignatura: sólo del año.
	 *
	 * ## Se conserva Eloquent a propósito: NO es el `DB::select` del hermano
	 *
	 * `Informes/BolfinalesController::periodosDelAnio()` devuelve `stdClass` porque
	 * **alli el original ya era `DB::select`**. Aqui el original es Eloquent, y
	 * cambiarlo movería la forma del objeto —`Periodo` castea `created_at`,
	 * `updated_at` y `deleted_at` a fecha y las serializa distinto que una fila
	 * cruda—. **El límite de este arreglo es que la respuesta no se mueva**, así que
	 * lo que se memoiza es la Collection de modelos tal cual.
	 *
	 * El `deleted_at is null` que el hermano escribe a mano **aquí lo pone el
	 * modelo**: `Periodo` usa `SoftDeletes`. Añadirlo otra vez sería escribir dos
	 * veces la misma condicion.
	 *
	 * ## Devuelve COPIAS, y eso no es una precaucion: es el arreglo
	 *
	 * Quien recibe estos objetos **les escribe encima**:
	 * `asignaturasPerdidasDeAlumno` pone `$periodo->cantNotasPerdidas` en cada uno y
	 * el bucle de alumnos pone `$periodoAlone->cant_perdidas`. Compartir los objetos
	 * haría que **todas las asignaturas mostraran la cuenta de la última**, y eso
	 * **no lo ve ninguna cota de consultas**: mismo número de consultas, resultado
	 * distinto. Por eso tiene su propio test, que mira el resultado.
	 *
	 * El `clone` es superficial y basta: `Model` guarda sus atributos en un array,
	 * que PHP copia por valor al clonar, y dentro sólo hay escalares.
	 *
	 * ## Y el memo vive en los `attributes` de la petición, no en una propiedad
	 *
	 * `Illuminate\Routing\Route::getController()` **memoiza la instancia del
	 * controlador**, así que el controlador **sobrevive a la petición** en cualquier
	 * proceso que atienda más de una: un memo en `private array $...` serviría a la
	 * segunda petición los periodos de la primera, y datos viejos en cuanto alguien
	 * edite un periodo. Los `attributes` de la petición son el sitio que este
	 * proyecto ya eligio para esto, y por la misma razón escrita: `User::fromToken()`
	 * guarda ahi el contexto y no en una propiedad del servicio (02 §4).
	 *
	 * **Y aquí el controlador ni siquiera lo instancia el router**: lo construye
	 * `new BolfinalesController` desde `CertificadosEstudioController`, con lo que
	 * una propiedad viviria lo que viva esa variable — otra vida distinta de la de
	 * la petición, y otra razón para no usarla.
	 *
	 * @return \Illuminate\Support\Collection<int, \App\Models\Periodo>
	 */
	private function periodosDelAnio($year_id)
	{
		$clave		= 'bolfinales.raiz.periodos.'.$year_id;
		$peticion	= Request::instance();

		if (! $peticion->attributes->has($clave)) {
			$peticion->attributes->set($clave, Periodo::where('year_id', $year_id)->get());
		}

		return $peticion->attributes->get($clave)->map(static fn ($periodo) => clone $periodo);
	}

	/**
	 * El recuento de notas perdidas por (alumno, asignatura, periodo) de **todo el
	 * grupo**, en una consulta.
	 *
	 * Sustituye el bucle anidado de `asignaturasPerdidasDeAlumno`: una consulta por
	 * cada (alumno x asignatura x periodo), o sea **1.480 de las 3.820** de la
	 * petición medida (05 §224).
	 *
	 * ## `(MATR or ASIS)` y no `MATR`, que es lo que separa esto de su hermano
	 *
	 * La consulta equivalente de `Informes/BolfinalesController` filtra
	 * `m.estado = "MATR"` a secas. **La de aquí filtra `(m.estado="MATR" or
	 * m.estado="ASIS")`**, porque eso es lo que decia el original de ESTE fichero.
	 * Copiar el SQL del hermano —que es correcto, y esta probado, en el hermano—
	 * **borraría del recuento a los alumnos en ASIS sin que ninguna cota de
	 * consultas lo viera**: mismo número de consultas, resultado distinto. Es la
	 * misma forma que el `clone`, y por eso se dice aquí.
	 *
	 * Es además la diferencia que importa: `Grupo::alumnos()` trae `MATR`, `ASIS` y
	 * `PREM`, así que el grupo tiene alumnos en `ASIS` de verdad.
	 *
	 * `COUNT(DISTINCT n.id)` porque el original era `SELECT distinct ...` contando
	 * filas, y `matriculas` multiplica: un alumno con dos matriculas vivas daría el
	 * doble sin el `DISTINCT`.
	 *
	 * El original filtraba `a.id = :asignatura_id`; aquí se filtra `a.grupo_id` y se
	 * agrupa. El mapa puede traer asignaturas que `Grupo::detailed_materias()` no
	 * devuelve, y **no pasa nada: nadie las lee**, porque quien consulta el mapa
	 * recorre esa lista y no esta.
	 *
	 * @param  array<int, object>  $alumnos
	 * @param  \Illuminate\Support\Collection<int, \App\Models\Periodo>  $periodos
	 * @return array<int, array<int, array<int, int>>>  [alumno_id][asignatura_id][periodo_id] => perdidas
	 * ## El alcance va al `WHERE` y correlacionado, y las dos mitades están medidas
	 *
	 * Esta consulta abarca **muchos alumnos y muchos periodos a la vez**
	 * (`n.alumno_id IN (…)`, `u.periodo_id IN (…)`), y la marca de boletín
	 * independiente es **por periodo**: un alumno puede ir aparte en el 3 y con el
	 * grupo en el 2. Un alcance resuelto fuera y bindeado una vez le daría a los
	 * demás periodos el del equivocado, y **no hay ningún error que lo señale**: no
	 * falta una fila ni sobra otra — salen las unidades de otro boletín. Por eso
	 * `alcanceCorrelacionado()`, que correlaciona por `u.periodo_id`. Lo fija
	 * `AlcanceCorrelacionadoPorPeriodoTest`.
	 *
	 * **Y no `JOIN_ESTADO`, aunque `matriculas m` esté en el ámbito.** El `FROM` de
	 * aquí es una lista de comas, y en MySQL la coma tiene **menos precedencia que
	 * `JOIN`**: el `ON` de un `LEFT JOIN` pegado detrás no alcanza a nombrar `u`.
	 * Medido contra el esquema real, no supuesto: `1054 Unknown column
	 * 'u.periodo_id' in 'on clause'` — o sea un 500, no una fila de más.
	 *
	 */
	private function perdidasPorAlumnoDelGrupo($grupo_id, $alumnos, $periodos)
	{
		$alumnoIds = [];

		foreach ($alumnos as $alumno) {
			if (! empty($alumno->alumno_id)) {
				$alumnoIds[] = (int) $alumno->alumno_id;
			}
		}

		$periodoIds = [];

		foreach ($periodos as $periodo) {
			if (! empty($periodo->id)) {
				$periodoIds[] = (int) $periodo->id;
			}
		}

		// Sin alumnos o sin periodos no hay nada que contar, y una `IN ()` vacia es
		// un error de sintaxis en MySQL, no una consulta que no devuelve nada.
		if ($alumnoIds === [] || $periodoIds === []) {
			return [];
		}

		$huecosAlu = implode(',', array_fill(0, count($alumnoIds), '?'));
		$huecosPer = implode(',', array_fill(0, count($periodoIds), '?'));

		$filas = DB::select(
			'SELECT n.alumno_id, u.asignatura_id, u.periodo_id, COUNT(DISTINCT n.id) AS perdidas
			   FROM notas n, subunidades s, unidades u, asignaturas a, matriculas m
			  WHERE n.subunidad_id = s.id AND s.unidad_id = u.id
				AND u.asignatura_id = a.id AND a.grupo_id = ?
				AND m.alumno_id = n.alumno_id AND m.deleted_at IS NULL
				AND (m.estado = "MATR" OR m.estado = "ASIS")
				AND n.alumno_id IN ('.$huecosAlu.')
				AND u.periodo_id IN ('.$huecosPer.')
				AND n.nota < ?
				AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
			  GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id',
			array_merge([$grupo_id], $alumnoIds, $periodoIds, [User::$nota_minima_aceptada])
		);

		$mapa = [];

		foreach ($filas as $fila) {
			$mapa[(int) $fila->alumno_id][(int) $fila->asignatura_id][(int) $fila->periodo_id] = (int) $fila->perdidas;
		}

		return $mapa;
	}

	/**
	 * El recuento de notas perdidas **por definitiva** de todo el grupo, en una
	 * consulta. Sustituye el otro bucle anidado, el de
	 * `definitivasMateriasXPeriodo`: los otros **1.480**.
	 *
	 * **Es un mapa distinto del de `perdidasPorAlumnoDelGrupo()` aunque los dos
	 * cuenten «notas perdidas», y son dos a propósito.** Esta consulta **filtra
	 * `deleted_at` en subunidades y unidades** y **no une con `matriculas`**; la otra
	 * hace justo al revés. Fundirlas daría números distintos de los de hoy en las
	 * filas en que difieren, y este arreglo no puede cambiar la respuesta.
	 *
	 * `COUNT(n.id)` y no `COUNT(DISTINCT n.id)`: aquí no hay `matriculas` que
	 * multiplique, y el original tampoco deduplicaba.
	 *
	 * Tampoco se filtra `n.deleted_at`, porque **el original no lo filtraba**.
	 *
	 * Los periodos ficticios que `definitivasMateriasXPeriodo` inventa para rellenar
	 * la tabla llevan `periodo_id = -1`: no estan en el mapa, y el `?? 0` de quien
	 * lo lee da el mismo cero que daba el `COUNT()` sobre ninguna fila.
	 *
	 * @param  array<int, object>  $alumnos
	 * @return array<int, array<int, array<int, int>>>  [alumno_id][asignatura_id][periodo_id] => perdidas
	 * ## El alcance, correlacionado por el periodo de la unidad
	 *
	 * Es **más ancha aún que la de `perdidasPorAlumnoDelGrupo()`**: muchos alumnos
	 * (`n.alumno_id IN (…)`) y **ni siquiera filtra periodo**, así que abarca el año
	 * entero. Como la marca es por periodo, un alcance bindeado una sola vez
	 * repartiría el de un periodo a los cuatro sin ningún error que lo señalara;
	 * `alcanceCorrelacionado()` lo resuelve dentro, por `u.periodo_id`.
	 *
	 * Aquí no hay `matriculas` en el ámbito, así que `JOIN_ESTADO` no era ni una
	 * opción: ésta es la forma de la subconsulta correlacionada y la única que cabe.
	 *
	 */
	private function perdidasPorDefinitivaDelGrupo($grupo_id, $alumnos)
	{
		$alumnoIds = [];

		foreach ($alumnos as $alumno) {
			if (! empty($alumno->alumno_id)) {
				$alumnoIds[] = (int) $alumno->alumno_id;
			}
		}

		if ($alumnoIds === []) {
			return [];
		}

		$huecos = implode(',', array_fill(0, count($alumnoIds), '?'));

		$filas = DB::select(
			'SELECT n.alumno_id, u.asignatura_id, u.periodo_id, COUNT(n.id) AS notas_perdidas
			   FROM notas n
			   INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
			   INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
			   INNER JOIN asignaturas a ON a.id = u.asignatura_id AND a.grupo_id = ?
			  WHERE n.nota < ? AND n.alumno_id IN ('.$huecos.')
				AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
			  GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id',
			array_merge([$grupo_id, User::$nota_minima_aceptada], $alumnoIds)
		);

		$mapa = [];

		foreach ($filas as $fila) {
			$mapa[(int) $fila->alumno_id][(int) $fila->asignatura_id][(int) $fila->periodo_id] = (int) $fila->notas_perdidas;
		}

		return $mapa;
	}

	public function periodosPerdidosDeAlumno($alumno, $grupo_id, $year_id, $periodos)
	{
		//$periodos = Periodo::where('year_id', '=', $year_id)->get();

		foreach ($periodos as $key => $periodo) {
			$periodo->asignaturas = $this->asignaturasPerdidasDeAlumnoPorPeriodo($alumno->alumno_id, $grupo_id, $periodo->id);

			if (count($periodo->asignaturas)==0) {
				unset($periodos[$key]);
			}
		}
	}

	public function asignaturasPerdidasDeAlumnoPorPeriodo($alumno_id, $grupo_id, $periodo_id)
	{


		$asignaturas	= Grupo::detailed_materias($grupo_id);

		foreach ($asignaturas as $keyAsig => $asignatura) {

			$asignatura->unidades = Unidad::deAsignatura($asignatura->asignatura_id, $periodo_id, $alumno_id);

			foreach ($asignatura->unidades as $keyUni => $unidad) {
				$unidad->subunidades = Subunidad::perdidasDeUnidad($unidad->unidad_id, $alumno_id);

				if (count($unidad->subunidades) == 0) {
					unset($asignatura->unidades[$keyUni]);
				}
			}
			if (count($asignatura->unidades) == 0) {
				unset($asignaturas[$keyAsig]);
			}
		}


		return $asignaturas;
	}







}