<?php namespace App\Http\Controllers\Informes;


use App\Http\Controllers\Controller;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\User;
use App\Models\Grupo;
use App\Models\Periodo;
use App\Models\Year;
use App\Models\Nota;
use App\Models\Alumno;
use App\Models\Role;
use App\Models\Matricula;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Ausencia;
use App\Models\FraseAsignatura;
use App\Models\Asignatura;
use App\Models\NotaComportamiento;
use App\Models\DefinicionComportamiento;

use App\Services\BoletinIndependiente;

use \stdClass;



/*
 * EL INTERRUPTOR DE LOS PUESTOS VIAJA EN LA RESPUESTA DE LAS DOS RUTAS, y eso es una
 * decisión del plan y no una comodidad — §7 de
 * `docs/migracion/19-boletin-independiente.md`.
 *
 * Desde el front esto son **cuatro informes** —`puestos_grupo_periodo`,
 * `puestos_grupo_year`, `puestos_todos_periodo` y `puestos_todos_year`— colgando de
 * estas dos rutas. Si el interruptor lo preguntara cada pantalla por su cuenta, basta
 * con que una se olvide para que **las otras tres mientan**: enseñarían una tabla de
 * puestos sin decir que falta gente. Viajando en la respuesta hay un solo sitio donde
 * puede estar mal.
 *
 * ## Y aquí el puesto lo cuenta el FRONT, que es lo que obliga a filtrar
 *
 * Estas dos rutas no calculan puesto: devuelven `promedio` y el filtro `puestoAlumno`
 * de `myvc_front` cuenta **sobre el array que le llega**. O sea que sacar al
 * independiente del recuento, aquí, es **no mandarlo** — mandarlo y esperar que el
 * front lo descarte sería la regla escrita en dos sitios, que es de lo que salió el
 * recalculador único de las definitivas.
 *
 * Por eso, y sólo con el interruptor en 0, la lista viene más corta. Con 1 —el
 * default y lo de los quince colegios hoy— llega entera, fila por fila como antes.
 */
class PuestosController extends Controller {
    
    public $consulta_notas_finales_alumno4 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, CAST(sum(nf.nota)/4 AS DOUBLE) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';
    

    public $consulta_notas_finales_alumno3 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, CAST(sum(nf.nota)/3 AS DOUBLE) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and (p.numero=1 or p.numero=2 or p.numero=3) and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and (p.numero=1 or p.numero=2 or p.numero=3) and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';
        
        
    public $consulta_notas_finales_alumno2 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, CAST(sum(nf.nota)/2 AS DOUBLE) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and (p.numero=1 or p.numero=2) and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and (p.numero=1 or p.numero=2) and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';


    public $consulta_notas_finales_alumno1 = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_final_year
                FROM asignaturas a
                inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
                inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
                left join (
                    select nf.asignatura_id, CAST(avg(nf.nota) AS DOUBLE) as nota_final_year from notas_finales nf
                    inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                    inner join periodos p on p.id=nf.periodo_id and p.numero=1 and p.deleted_at is null and p.year_id=:year_id
                    group by a.id
                )r on r.asignatura_id=a.id
                left join (
                    SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                    FROM(
                        SELECT n.alumno_id, n.nota, u.asignatura_id
                        FROM unidades u 
                        inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                        inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                        inner join periodos p on p.id=u.periodo_id and p.numero=1 and p.deleted_at is null and p.year_id=:year_id2
                    )df1
                    group by df1.asignatura_id
                )p ON p.asignatura_id=r.asignatura_id 
                order by ar.orden, m.orden, a.orden';




    public $consulta_notas_finales_periodo = 'SELECT a.id as asignatura_id, m.materia, m.alias, p.cant_perdidas, r.nota_asignatura, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura, r.manual
            FROM asignaturas a
            inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=:gr_id
            inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
            left join (
                select nf.asignatura_id, CAST(avg(nf.nota) AS DOUBLE) as nota_asignatura, nf.manual from notas_finales nf
                inner join asignaturas a on a.id=nf.asignatura_id and a.deleted_at is null and nf.alumno_id=:alu_id
                inner join periodos p on p.id=nf.periodo_id and p.numero=:num_periodo and p.deleted_at is null and p.year_id=:year_id
                group by a.id
            )r on r.asignatura_id=a.id
            left join (
                SELECT df1.alumno_id, count( df1.nota ) cant_perdidas, df1.asignatura_id
                FROM(
                    SELECT n.alumno_id, n.nota, u.asignatura_id
                    FROM unidades u 
                    inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
                    inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<:min and n.alumno_id=:alu_id2
                    inner join periodos p on p.id=u.periodo_id and p.numero=:num_periodo2 and p.deleted_at is null and p.year_id=:year_id2
                )df1
                group by df1.asignatura_id
            )p ON p.asignatura_id=r.asignatura_id 
            order by ar.orden, m.orden, a.orden';

    public $consulta_notas_comportamiento_periodo = 'SELECT *
            FROM nota_comportamiento WHERE alumno_id=? and periodo_id=? and deleted_at is null;';





	// PUESTOS ANUALES
	public function putDetailedNotasYear()
	{
		$user = User::fromToken();

		$grupo_id = Request::input('grupo_id');
		$periodo_a_calcular = Request::input('periodo_a_calcular', 4);

		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		/*
		 * Los periodos que ESTE informe promedia, que no son siempre los cuatro:
		 * `periodo_a_calcular` llega del cuerpo y `definitivas_year_alumno` elige una
		 * de sus cuatro consultas con él. Preguntar por el año entero sacaría del
		 * recuento a quien fue independiente en un periodo que aquí no se está
		 * promediando — y eso no le cambia el puesto a él, se lo cambia a los treinta
		 * de detrás.
		 */
		$periodos_hasta = Periodo::hastaPeriodoN($user->year_id, $periodo_a_calcular);
		$periodos_del_informe = array_map(
			fn ($periodo) => (int) $periodo->id,
			$periodos_hasta
		);

		/*
		 * `con_independientes`: el informe «Lo que necesita en el P4» pide estas mismas notas y
		 * no reparte puestos, así que quiere a TODOS los alumnos -- también a los que van aparte,
		 * que tienen que pasar el año igual. Sin el campo, todo sigue como estaba.
		 */
		if (!Request::boolean('con_independientes')) {
			$alumnos = BoletinIndependiente::losQueCuentanParaElPuesto($alumnos, $periodos_del_informe, (int) $user->year_id);
		}

		// **Una consulta por grupo y no una por alumno** (docs/migracion/48 §Los boletines,
		// uno por uno): las notas, el comportamiento y la marca de independiente.
		$notasDelGrupo = $this->definitivasYearDelGrupo($alumnos, $grupo_id, $user, $periodo_a_calcular, Request::boolean('con_periodos'));
		$comportamientoDelGrupo = $this->comportamientoYearDelGrupo($alumnos, $user->year_id, $periodo_a_calcular);

		// La marca, con el mapa del año en vez de `aplicaEnAlguno()` periodo a periodo.
		// `aparteEnPorAlumno()` da los `numero` de los periodos vivos del año en que va
		// aparte; los del informe son los vivos con `numero <= periodo_a_calcular`, así que
		// basta con ver si alguno de esos números es de un periodo del informe.
		$numeros_del_informe = [];
		foreach ($periodos_hasta as $periodo) {
			$numeros_del_informe[(int) $periodo->numero] = true;
		}
		$aparteEn = $numeros_del_informe === [] ? [] : BoletinIndependiente::aparteEnPorAlumno((int) $user->year_id);

		foreach ($alumnos as $keyAlum => $alumno) {
			// «¿este alumno va aparte en alguno de los periodos que se promedian?».
			// Con el interruptor en 0 es `false` en todas las filas **porque los que
			// serían `true` ya no están en la lista**, y ahí lo que explica la ausencia
			// es `puestos_con_bol_independiente`. Con 1 es el dato que distingue, en una
			// tabla donde sí están, a quién se le calculó el promedio sobre su propio
			// reparto.
			$alumno->bol_independiente_periodo = false;
			foreach ($aparteEn[(int) $alumno->alumno_id] ?? [] as $numero) {
				if (isset($numeros_del_informe[$numero])) {
					$alumno->bol_independiente_periodo = true;
				}
			}

			$alumno->notas_asig = $notasDelGrupo[(int) $alumno->alumno_id] ?? [];

			// `AVG` sin filas es una fila con `NULL`: el alumno sin comportamiento lleva `null`.
			$alumno->comportamiento_prom = $comportamientoDelGrupo[(int) $alumno->alumno_id] ?? null;

			$sumatoria_asignaturas_year = 0;
			$perdidos_year = 0;

			foreach ($alumno->notas_asig as $keyAsig => $asignatura) {
                
                // El `round()` de aquí **no movía el promedio** —la suma es la línea de
                // arriba, y por eso el encargo daba esta cadena por buena—: era un
                // redondeo de presentación. Se quita porque presentar es del front, que
                // pidió los decimales crudos para poder desempatar, y un redondeo hecho
                // aquí es el único que allí no se puede deshacer.
                $sumatoria_asignaturas_year += $asignatura->nota_final_year;
                $perdidos_year += $asignatura->cant_perdidas;

			}

			try {
				$cant = count($alumno->notas_asig);
				if ($cant == 0) {
					$alumno->promedio_year = 0;
				}else{
					$alumno->promedio_year = ($sumatoria_asignaturas_year / $cant);
					$alumno->perdidos_year = $perdidos_year;
				}
				
			} catch (\Throwable $e) {
				$alumno->promedio_year = 0;
			}

			array_push($alumnos_response, $alumno);
		}



		return [
			'grupo' => $grupo,
			'year' => $year,
			'puestos_con_bol_independiente' => BoletinIndependiente::puestosCuentanIndependientes((int) $user->year_id),
			'alumnos' => $alumnos_response,
		];


	}


	/**
	 * `definitivas_year_alumno()` para el grupo entero: `[alumno_id => filas]`.
	 *
	 * Aquella es una consulta por alumno, y la cara (1,1 s de 1,4 en quibdo 223): sus dos
	 * derivadas agrupan por asignatura las notas del alumno en todo el año, y MySQL las
	 * materializa alumno por alumno. Aquí van las tres piezas por separado y una vez: las
	 * asignaturas del grupo en el orden de siempre, la nota del año de cada celda y las
	 * notas perdidas de cada celda; y se juntan en PHP con la forma de la fila de antes.
	 *
	 * Lo que se conserva a propósito, porque la respuesta sale de ahí:
	 * - La cuenta de cada `N` es la suya (`sum/4` con TODOS los periodos del año, `sum/3`,
	 *   `sum/2`, `avg` para el 1), escrita igual; con otro `periodo_a_calcular`, `[]`.
	 * - El orden, **sin desempate**. Dos asignaturas con el mismo `(ar.orden, m.orden,
	 *   a.orden)` salen en el orden en que el plan lee el `JOIN`: por `materias.id` en
	 *   simon (Inglés y Lengua) y por `asignaturas.id` en quibdo (grupo 168). Un desempate
	 *   escrito acierta en un colegio y le da la vuelta en el otro; la lista de asignaturas
	 *   es el mismo `JOIN` de antes y el plan elige igual. Que el empate dependa del plan
	 *   es anterior a esto.
	 * - `cant_perdidas` se unía a `r` y no a `a`: una asignatura sin nota del año lleva
	 *   `cant_perdidas` nulo aunque tenga notas perdidas.
	 *
	 * `con_periodos`: cada fila lleva además `definitivas_periodo`, `[{numero, nota}]` de los
	 * periodos que entran en la cuenta. Lo pide «Lo que necesita en el P4» para explicar de dónde
	 * sale el promedio; sin el campo la respuesta es la de siempre. La nota de un periodo es la
	 * SUMA de sus filas de `notas_finales`, igual que la usa la media: con una definitiva
	 * duplicada sale el doble, y así se ve por qué el promedio no cuadra.
	 *
	 * @param  array<int, object>  $alumnos
	 * @return array<int, array<int, object>>
	 */
	private function definitivasYearDelGrupo(array $alumnos, $grupo_id, $user, $numero_periodo = 4, bool $conPeriodos = false): array
	{
		if ($numero_periodo == 1) {
			$nota    = 'CAST(avg(nf.nota) AS DOUBLE)';
			$periodo = 'p.numero=1 and ';
		} elseif ($numero_periodo == 2) {
			$nota    = 'CAST(sum(nf.nota)/2 AS DOUBLE)';
			$periodo = '(p.numero=1 or p.numero=2) and ';
		} elseif ($numero_periodo == 3) {
			$nota    = 'CAST(sum(nf.nota)/3 AS DOUBLE)';
			$periodo = '(p.numero=1 or p.numero=2 or p.numero=3) and ';
		} elseif ($numero_periodo == 4) {
			$nota    = 'CAST(sum(nf.nota)/4 AS DOUBLE)';
			$periodo = '';
		} else {
			return [];
		}

		$alumnoIds = array_values(array_unique(array_map(static fn ($a) => (int) $a->alumno_id, $alumnos)));
		if ($alumnoIds === []) {
			return [];
		}

		$asignaturas = DB::select('SELECT a.id as asignatura_id, m.materia, m.alias
				FROM asignaturas a
				inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=?
				inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
				order by ar.orden, m.orden, a.orden', [$grupo_id]);
		if ($asignaturas === []) {
			return array_fill_keys($alumnoIds, []);
		}

		$enAlumnos     = implode(',', array_fill(0, count($alumnoIds), '?'));
		$asignaturaIds = array_map(static fn ($a) => (int) $a->asignatura_id, $asignaturas);
		$enAsignaturas = implode(',', array_fill(0, count($asignaturaIds), '?'));

		$notas = [];
		foreach (DB::select('SELECT nf.alumno_id, nf.asignatura_id, '.$nota.' as nota_final_year from notas_finales nf
				inner join periodos p on p.id=nf.periodo_id and '.$periodo.'p.deleted_at is null and p.year_id=?
				where nf.alumno_id IN ('.$enAlumnos.') and nf.asignatura_id IN ('.$enAsignaturas.')
				group by nf.alumno_id, nf.asignatura_id',
				array_merge([$user->year_id], $alumnoIds, $asignaturaIds)) as $fila) {
			$notas[(int) $fila->alumno_id][(int) $fila->asignatura_id] = $fila->nota_final_year;
		}

		$porPeriodo = [];
		if ($conPeriodos) {
			foreach (DB::select('SELECT nf.alumno_id, nf.asignatura_id, p.numero, CAST(sum(nf.nota) AS DOUBLE) as nota from notas_finales nf
					inner join periodos p on p.id=nf.periodo_id and '.$periodo.'p.deleted_at is null and p.year_id=?
					where nf.alumno_id IN ('.$enAlumnos.') and nf.asignatura_id IN ('.$enAsignaturas.')
					group by nf.alumno_id, nf.asignatura_id, p.numero
					order by p.numero',
					array_merge([$user->year_id], $alumnoIds, $asignaturaIds)) as $fila) {
				$porPeriodo[(int) $fila->alumno_id][(int) $fila->asignatura_id][] = (object) [
					'numero' => (int) $fila->numero,
					'nota'   => $fila->nota,
				];
			}
		}

		$perdidas = [];
		foreach (DB::select('SELECT n.alumno_id, u.asignatura_id, count(n.nota) cant_perdidas
				FROM unidades u
				inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
				inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<? and n.alumno_id IN ('.$enAlumnos.')
				inner join periodos p on p.id=u.periodo_id and '.$periodo.'p.deleted_at is null and p.year_id=?
				where u.asignatura_id IN ('.$enAsignaturas.')
				group by n.alumno_id, u.asignatura_id',
				array_merge([$user->nota_minima_aceptada], $alumnoIds, [$user->year_id], $asignaturaIds)) as $fila) {
			$perdidas[(int) $fila->alumno_id][(int) $fila->asignatura_id] = $fila->cant_perdidas;
		}

		$porAlumno = [];
		foreach ($alumnoIds as $alumnoId) {
			$filas = [];
			foreach ($asignaturas as $asignatura) {
				$id = (int) $asignatura->asignatura_id;
				$tieneNota = isset($notas[$alumnoId]) && array_key_exists($id, $notas[$alumnoId]);
				$fila = (object) [
					'asignatura_id'   => $asignatura->asignatura_id,
					'materia'         => $asignatura->materia,
					'alias'           => $asignatura->alias,
					'cant_perdidas'   => $tieneNota ? ($perdidas[$alumnoId][$id] ?? null) : null,
					'nota_final_year' => $tieneNota ? $notas[$alumnoId][$id] : null,
				];
				if ($conPeriodos) {
					$fila->definitivas_periodo = $porPeriodo[$alumnoId][$id] ?? [];
				}
				$filas[] = $fila;
			}
			$porAlumno[$alumnoId] = $filas;
		}

		return $porAlumno;
	}

	/**
	 * El `AVG` de comportamiento de cada alumno hasta `periodo_a_calcular`, en una
	 * consulta. La de antes, por alumno, no miraba `c.deleted_at`; ésta tampoco.
	 *
	 * @param  array<int, object>  $alumnos
	 * @return array<int, mixed>
	 */
	private function comportamientoYearDelGrupo(array $alumnos, $year_id, $periodo_a_calcular): array
	{
		$alumnoIds = array_values(array_unique(array_map(static fn ($a) => (int) $a->alumno_id, $alumnos)));
		if ($alumnoIds === []) {
			return [];
		}

		$promedios = [];
		foreach (DB::select('SELECT c.alumno_id, AVG(c.nota) as comportamiento_prom
				FROM nota_comportamiento c
				INNER JOIN periodos p ON p.id=c.periodo_id and p.numero<=?
				WHERE p.deleted_at is null and c.alumno_id IN ('.implode(',', array_fill(0, count($alumnoIds), '?')).') and p.year_id=?
				GROUP BY c.alumno_id',
				array_merge([$periodo_a_calcular], $alumnoIds, [$year_id])) as $fila) {
			$promedios[(int) $fila->alumno_id] = $fila->comportamiento_prom;
		}

		return $promedios;
	}

	/**
	 * `consulta_notas_finales_periodo` y `consulta_notas_comportamiento_periodo` para el
	 * grupo entero: `[[alumno_id => filas], [alumno_id => nota de comportamiento]]`.
	 *
	 * Lo mismo que `definitivasYearDelGrupo()`, con dos cosas más que conservar:
	 * - `r.manual` no está agregado (el `GROUP BY` es por asignatura): MySQL daba el de
	 *   la primera fila que leía, y el plan las leía por id. Con dos definitivas del mismo
	 *   periodo en una celda, es el `manual` de la de id menor, y así se pide.
	 * - El comportamiento era `[0]` de un `SELECT *` sin orden, leído por id: el de id menor.
	 *
	 * @param  array<int, object>  $alumnos
	 * @return array{0: array<int, array<int, object>>, 1: array<int, object>}
	 */
	private function notasPeriodoDelGrupo(array $alumnos, $grupo_id, $user): array
	{
		$alumnoIds = array_values(array_unique(array_map(static fn ($a) => (int) $a->alumno_id, $alumnos)));
		if ($alumnoIds === []) {
			return [[], []];
		}
		$enAlumnos = implode(',', array_fill(0, count($alumnoIds), '?'));

		$comportamiento = [];
		foreach (DB::select('SELECT * FROM nota_comportamiento WHERE alumno_id IN ('.$enAlumnos.') and periodo_id=? and deleted_at is null ORDER BY alumno_id, id',
				array_merge($alumnoIds, [$user->periodo_id])) as $fila) {
			$comportamiento[(int) $fila->alumno_id] ??= $fila;
		}

		$asignaturas = DB::select('SELECT a.id as asignatura_id, m.materia, m.alias, ar.orden as orden_area, m.orden as orden_materia, a.orden as orden_asignatura
				FROM asignaturas a
				inner join materias m on m.id=a.materia_id and m.deleted_at is null and a.deleted_at is null and a.grupo_id=?
				inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
				order by ar.orden, m.orden, a.orden', [$grupo_id]);
		if ($asignaturas === []) {
			return [array_fill_keys($alumnoIds, []), $comportamiento];
		}

		$asignaturaIds = array_map(static fn ($a) => (int) $a->asignatura_id, $asignaturas);
		$enAsignaturas = implode(',', array_fill(0, count($asignaturaIds), '?'));

		$notas = [];
		foreach (DB::select('SELECT x.alumno_id, x.asignatura_id, x.nota_asignatura, nf2.manual FROM (
					select nf.alumno_id, nf.asignatura_id, CAST(avg(nf.nota) AS DOUBLE) as nota_asignatura, MIN(nf.id) as primera from notas_finales nf
					inner join periodos p on p.id=nf.periodo_id and p.numero=? and p.deleted_at is null and p.year_id=?
					where nf.alumno_id IN ('.$enAlumnos.') and nf.asignatura_id IN ('.$enAsignaturas.')
					group by nf.alumno_id, nf.asignatura_id
				) x INNER JOIN notas_finales nf2 ON nf2.id=x.primera',
				array_merge([$user->numero_periodo, $user->year_id], $alumnoIds, $asignaturaIds)) as $fila) {
			$notas[(int) $fila->alumno_id][(int) $fila->asignatura_id] = $fila;
		}

		$perdidas = [];
		foreach (DB::select('SELECT n.alumno_id, u.asignatura_id, count(n.nota) cant_perdidas
				FROM unidades u
				inner join subunidades s on s.unidad_id=u.id and s.deleted_at is null and u.deleted_at is null
				inner join notas n on n.subunidad_id=s.id and n.deleted_at is null and n.nota<? and n.alumno_id IN ('.$enAlumnos.')
				inner join periodos p on p.id=u.periodo_id and p.numero=? and p.deleted_at is null and p.year_id=?
				where u.asignatura_id IN ('.$enAsignaturas.')
				group by n.alumno_id, u.asignatura_id',
				array_merge([$user->nota_minima_aceptada], $alumnoIds, [$user->numero_periodo, $user->year_id], $asignaturaIds)) as $fila) {
			$perdidas[(int) $fila->alumno_id][(int) $fila->asignatura_id] = $fila->cant_perdidas;
		}

		$porAlumno = [];
		foreach ($alumnoIds as $alumnoId) {
			$filas = [];
			foreach ($asignaturas as $asignatura) {
				$id = (int) $asignatura->asignatura_id;
				$nota = $notas[$alumnoId][$id] ?? null;
				$filas[] = (object) [
					'asignatura_id'    => $asignatura->asignatura_id,
					'materia'          => $asignatura->materia,
					'alias'            => $asignatura->alias,
					'cant_perdidas'    => $nota !== null ? ($perdidas[$alumnoId][$id] ?? null) : null,
					'nota_asignatura'  => $nota?->nota_asignatura,
					'orden_area'       => $asignatura->orden_area,
					'orden_materia'    => $asignatura->orden_materia,
					'orden_asignatura' => $asignatura->orden_asignatura,
					'manual'           => $nota?->manual,
				];
			}
			$porAlumno[$alumnoId] = $filas;
		}

		return [$porAlumno, $comportamiento];
	}

	public function definitivas_year_alumno($alumno_id, $grupo_id, $user, $numero_periodo=4)
	{
        $notas = [];

        if ($numero_periodo == 1) {
            
            $consulta   = $this->consulta_notas_finales_alumno1;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
            
        }elseif ($numero_periodo == 2) {
            
            $consulta   = $this->consulta_notas_finales_alumno2;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
                            
        }elseif ($numero_periodo == 3) {
            
            $consulta   = $this->consulta_notas_finales_alumno3;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
                            
        }elseif ($numero_periodo == 4) {
            
            $consulta   = $this->consulta_notas_finales_alumno4;
            $notas      = DB::select($consulta, [ ':gr_id' => $grupo_id, ':alu_id' => $alumno_id, ':year_id' => $user->year_id, ':min' => $user->nota_minima_aceptada, ':alu_id2' => $alumno_id, ':year_id2' => $user->year_id ]);
                            
        }
		
		
		return $notas;
    }
    
    


    
	// PUESTOS POR PERIODO
	public function putDetailedNotasPeriodo($grupo_id)
	{
		$user = User::fromToken();

		$alumnos_response = [];

		$grupo			= Grupo::datos($grupo_id);
		$year			= Year::datos($user->year_id);
		$alumnos		= Grupo::alumnos($grupo_id);

		// Un solo periodo, el del token: `consulta_notas_finales_periodo` filtra por
		// `p.numero=:num_periodo`. Aquí la marca que decide es la de ese periodo y no
		// la del año — quien fue independiente en el segundo cuenta con normalidad en
		// el tercero, que es la decisión 7.
		$periodos_del_informe = [(int) $user->periodo_id];

		$alumnos = BoletinIndependiente::losQueCuentanParaElPuesto($alumnos, $periodos_del_informe, (int) $user->year_id);

		// Las notas y el comportamiento, una consulta por grupo y no una por alumno.
		[$notasDelGrupo, $comportamientoDelGrupo] = $this->notasPeriodoDelGrupo($alumnos, $grupo_id, $user);

		foreach ($alumnos as $keyAlum => $alumno) {

			$alumno->bol_independiente_periodo = BoletinIndependiente::aplicaEnAlguno((int) $alumno->alumno_id, $periodos_del_informe);

			$alumno->asignaturas = $notasDelGrupo[(int) $alumno->alumno_id] ?? [];
			$alumno->comportamiento = $comportamientoDelGrupo[(int) $alumno->alumno_id] ?? [];

			$sumatoria_asignaturas = 0;
			$perdidos_year = 0;

			foreach ($alumno->asignaturas as $keyAsig => $asignatura) {
                
                // Mismo caso que en `putDetailedNotas`: redondeo de presentación que el
                // front deshace solo, y que aquí le tapaba los decimales.
                $sumatoria_asignaturas += $asignatura->nota_asignatura;
                $perdidos_year += $asignatura->cant_perdidas;
			}

			try {
				$cant = count($alumno->asignaturas);
				if ($cant == 0) {
					$alumno->promedio = 0;
				}else{
					$alumno->promedio = ($sumatoria_asignaturas / $cant);
					$alumno->perdidos_year = $perdidos_year;
				}
				
			} catch (\Throwable $e) {
				$alumno->promedio = 0;
			}

			array_push($alumnos_response, $alumno);
		}

		return [
			'grupo' => $grupo,
			'year' => $year,
			'puestos_con_bol_independiente' => BoletinIndependiente::puestosCuentanIndependientes((int) $user->year_id),
			'alumnos' => $alumnos_response,
		];
	}
}
