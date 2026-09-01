<?php namespace App\Http\Controllers\Informes;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\Year;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use \Log;


/*
 * Acta de evaluación y promoción.
 *
 * Los conteos NO se derivan de matriculas.estado. estado es un flag del presente: cuando a un
 * alumno se le pone RETI, el sistema olvida que alguna vez estuvo activo, y "cuántos iniciaron el
 * año" se vuelve incontestable desde un campo que sólo sabe "ahora". La versión anterior lo
 * intentaba igual -- filtraba estado IN (PREM,MATR,ASIS) para "iniciaron" -- y por eso el acta
 * descontaba de los que iniciaron a todo el que después se fue, y "terminaron" (mismo filtro, sin
 * filtro de fecha) era por construcción iniciaron+ingresaron, incapaz de mostrar deserción.
 *
 * Peor: la lista de alumnos impresa salía de una consulta con estado IN (MATR,PREM,DESE,RETI) --
 * sin ASIS -- y el cuadro resumen de otra con estado IN (MATR,PREM,ASIS) -- sin RETI/DESE. Dos
 * poblaciones distintas en el mismo documento, imposibles de reconciliar contando nombres.
 *
 * Aquí la pertenencia sale de las fechas, que sí guardan la historia, y estado queda para lo único
 * que sabe de verdad: el motivo de la salida (RETI retiró documentación, DESE desertó). Con eso el
 * cuadre se cumple por construcción:
 *
 *     iniciaron + ingresaron - retirados - desertores = terminaron
 *
 * Todo sale de UNA consulta de matrículas del año (antes eran 5 por grupo, 151 para 30 grupos), y
 * cada contador lleva la lista de matricula_id que lo compone, para que la pantalla pueda abrir
 * exactamente las filas que produjeron el número. Un número que no se puede reconciliar contando
 * nombres está mal.
 *
 * Las matrículas sin fecha_matricula (la columna es nullable) no se descartan en silencio: van a
 * su propio contador y el descuadre se imprime en vez de esconderse.
 */
class ActasEvaluacionController extends Controller {


	// Estados que significan "se fue". Cualquier otro cuenta como que terminó el año.
	private static $ESTADOS_SALIDA = ['RETI', 'DESE'];


	public function putActaEvaluacionPromocion()
	{
		$user 	= User::fromToken();
		$year 	= Year::datos_basicos($user->year_id);

		$periodos = DB::select('SELECT id, numero, fecha_inicio, fecha_fin FROM periodos
			WHERE year_id=? and deleted_at is null order by numero', [$user->year_id]);

		if (count($periodos) === 0) {
			// Antes esto era $periodos[0] a pelo, y un año sin periodos tumbaba el informe.
			return response()->json([
				'msg' => 'El año lectivo no tiene periodos definidos, y el acta se calcula contra el calendario de periodos.',
			], 422);
		}

		// El corte de "inició el año" es el fin del primer periodo, como en la versión anterior.
		//
		// Pero periodos.fecha_fin es nullable, y hay colegios con el calendario sin llenar. Sin
		// corte, la pregunta "¿inició el año o ingresó después?" no tiene respuesta, y la versión
		// anterior la respondía igual: comparar contra NULL en SQL da NULL, así que "iniciaron"
		// salía cero sin que nadie supiera por qué. Aquí se dice en vez de inventar.
		$fin_periodo1 = $periodos[0]->fecha_fin;
		$hay_corte    = ($fin_periodo1 !== null && $fin_periodo1 !== '');

		$grupos = DB::select('SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado,
				g.grado_id, g.year_id, g.titular_id,
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
				g.created_at, g.updated_at, gra.nombre as nombre_grado
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id
			left join profesores p on p.id=g.titular_id
			where g.deleted_at is null
			order by g.orden', [':year_id' => $user->year_id]);

		// UNA consulta para todas las matrículas del año, con TODOS los estados. Los filtros se
		// hacen después y sobre el mismo conjunto de filas, que es lo que garantiza que la lista
		// impresa y el cuadro resumen hablen de la misma gente.
		$matriculas = DB::select('SELECT m.id as matricula_id, m.alumno_id, m.grupo_id, m.estado,
				m.fecha_matricula, m.fecha_retiro, m.razon_retiro, m.nuevo, m.repitente,
				m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas,
				m.anios_in_cole, m.nro_folio,
				IF(m.nuevo, "SI", "NO") as es_nuevo,
				a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id, a.egresado,
				a.fecha_nac, a.ciudad_nac, c1.departamento as departamento_nac_nombre,
				c1.ciudad as ciudad_nac_nombre, a.documento, a.ciudad_doc,
				a.tipo_sangre, a.eps, CONCAT(a.telefono, " / ", a.celular) as telefonos,
				a.direccion, a.barrio, a.estrato, a.ciudad_resid, a.religion, a.email, a.facebook,
				a.created_by, a.updated_by, a.pazysalvo, a.deuda, a.is_urbana,
				IF(a.is_urbana, "Urbano", "Rural") as es_urbana,
				u2.username as creado_por,
				t1.tipo as tipo_doc, t1.abrev as tipo_doc_abrev,
				u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
				u.username, u.is_superuser, u.is_active,
				a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre,
				a.has_sisben, a.nro_sisben, a.has_sisben_3, a.nro_sisben_3
			FROM matriculas m
			inner join grupos g on g.id=m.grupo_id and g.year_id=:year_id and g.deleted_at is null
			inner join alumnos a on a.id=m.alumno_id and a.deleted_at is null
			left join users u on a.user_id=u.id and u.deleted_at is null
			left join users u2 on a.created_by=u2.id and u2.deleted_at is null
			left join images i on i.id=u.imagen_id and i.deleted_at is null
			left join tipos_documentos t1 on t1.id=a.tipo_doc and t1.deleted_at is null
			left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
			left join ciudades c1 on c1.id=a.ciudad_nac and c1.deleted_at is null
			where m.deleted_at is null
			order by a.apellidos, a.nombres', [':year_id' => $user->year_id]);

		// Agrupamos en PHP en vez de repetir la consulta por grupo.
		$por_grupo = [];
		foreach ($matriculas as $m) {
			$this->prepararMatricula($m);
			if (!isset($por_grupo[$m->grupo_id])) $por_grupo[$m->grupo_id] = [];
			$por_grupo[$m->grupo_id][] = $m;
		}

		$usa_areas = $year->cant_areas_pierde_year > 0;

		foreach ($grupos as $grupo) {
			$alumnos = isset($por_grupo[$grupo->id]) ? $por_grupo[$grupo->id] : [];

			$grupo->alumnos        = $alumnos;
			$grupo->resumen        = $this->resumenMovimiento($alumnos, $fin_periodo1, $hay_corte);
			$grupo->promocion      = $this->resumenPromocion($alumnos, $usa_areas);
			$grupo->periodos       = $this->movimientoPorPeriodo($alumnos, $periodos);
			$grupo->razones_retiro = $this->razonesDeRetiro($alumnos);
			$grupo->perfil         = $this->perfilDelGrupo($alumnos);
		}

		return [
			'grupos'      => $grupos,
			'year'        => $year,
			'periodos'    => $periodos,
			'usa_areas'   => $usa_areas,
			'hay_corte'   => $hay_corte,
			'consolidado' => $this->consolidado($grupos, $periodos),
			'duplicados'  => $this->buscarDuplicados($matriculas),
			'firmantes'   => $this->firmantesDelYear($year),
		];
	}


	/*
	 * Normaliza los campos derivados de una matrícula. Antes esto vivía dentro del triple bucle y
	 * mezclaba cálculo con conteo.
	 */
	private function prepararMatricula($m)
	{
		$m->edad = '';
		if ($m->fecha_nac) {
			try {
				$m->edad = Carbon::parse($m->fecha_nac)->age;
			} catch (\Exception $e) {
				$m->edad = '';
			}
		}

		$m->promedio = $m->promedio > 0 ? round($m->promedio, 1) : '';

		// Clasificación de promoción en sus TRES estados reales. La versión anterior hacía
		// strpos($promovido, 'No promovido') y mandaba todo lo demás a "Sí": 'Automático' (el
		// DEFAULT de la columna, o sea todo alumno cuya promoción nunca se calculó) y
		// 'Promoción pendiente' se imprimían como promovidos. Lo segundo contradecía al boletín
		// final y al certificado de estudio, que sí tratan la promoción pendiente como tercer
		// estado.
		$m->estado_promocion = $this->clasificarPromocion($m->promovido);
		$m->promovido_label  = $this->etiquetaPromocion($m->estado_promocion);
		$m->sexo_norm        = $this->sexoNormalizado($m->sexo);
		$m->salio            = in_array($m->estado, self::$ESTADOS_SALIDA, true);
		$m->termino_year     = !$m->salio;

		return $m;
	}


	/*
	 * El dominio real de matriculas.promovido lo fija PromovidosController: 'Automático' (default
	 * de la columna), 'Promovido (calculado|manual)', 'No promovido (calculado|manual)' y
	 * 'Promoción pendiente (calculado|manual)'.
	 *
	 * El orden de las comparaciones importa: 'no promovido' contiene 'promovido'.
	 */
	private function clasificarPromocion($valor)
	{
		$v = trim((string) $valor);
		if ($v === '') return 'SIN_DEFINIR';

		$v = $this->sinTildes(mb_strtolower($v, 'UTF-8'));

		if (strpos($v, 'no promovid') !== false)         return 'NO_PROMOVIDO';
		if (strpos($v, 'promocion pendiente') !== false) return 'PENDIENTE';
		if (strpos($v, 'promovid') !== false)            return 'PROMOVIDO';

		// 'Automático' y cualquier valor escrito a mano que no reconozcamos. No se asume promovido:
		// asumirlo es lo que inflaba promovidos_0_perdidas con todos los retirados.
		return 'SIN_DEFINIR';
	}


	private function etiquetaPromocion($clasificacion)
	{
		if ($clasificacion === 'PROMOVIDO')    return 'Sí';
		if ($clasificacion === 'NO_PROMOVIDO') return 'No';
		if ($clasificacion === 'PENDIENTE')    return 'Pendiente';
		return 'Sin definir';
	}


	private function sinTildes($texto)
	{
		return strtr($texto, ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u']);
	}


	/*
	 * Antes, todo `else` de `if (sexo == 'M')` contaba como femenino: sexo NULL, vacío o 'm'
	 * minúscula engordaban la columna de mujeres. Ahora sin dato es su propia categoría.
	 */
	private function sexoNormalizado($sexo)
	{
		$s = strtoupper(trim((string) $sexo));
		if ($s === 'M') return 'M';
		if ($s === 'F') return 'F';
		return 'SD';
	}


	private function nuevoContador()
	{
		return (object) ['total' => 0, 'm' => 0, 'f' => 0, 'sd' => 0, 'ids' => []];
	}


	/*
	 * Suma una matrícula a un contador y guarda su id. Los ids son lo que permite que la pantalla
	 * abra la lista exacta detrás de cada número.
	 */
	private function sumar($contador, $m)
	{
		$contador->total++;
		if ($m->sexo_norm === 'M')     $contador->m++;
		elseif ($m->sexo_norm === 'F') $contador->f++;
		else                           $contador->sd++;
		$contador->ids[] = $m->matricula_id;
	}


	/*
	 * Movimiento del grupo durante el año. El cuadre sale por construcción porque todos los
	 * contadores se llenan recorriendo UNA sola vez el MISMO arreglo de matrículas.
	 */
	private function resumenMovimiento($alumnos, $fin_periodo1, $hay_corte = true)
	{
		$r = (object) [
			'iniciaron'           => $this->nuevoContador(),
			'ingresaron'          => $this->nuevoContador(),
			'sin_fecha_matricula' => $this->nuevoContador(),
			'sin_clasificar'      => $this->nuevoContador(),
			'retirados'           => $this->nuevoContador(),
			'desertores'          => $this->nuevoContador(),
			'terminaron'          => $this->nuevoContador(),
			'total_matriculas'    => $this->nuevoContador(),
		];

		foreach ($alumnos as $m) {
			$this->sumar($r->total_matriculas, $m);

			// Entrada: por fecha, no por estado. Quien inició el año en febrero y se retiró en
			// agosto SÍ inició el año.
			//
			// Sin corte no se clasifica: meter a todos en "ingresaron durante el año" sería
			// afirmar algo que los datos no dicen, y una fila cuya etiqueta miente es peor que una
			// fila ausente.
			if ($m->fecha_matricula === null || $m->fecha_matricula === '') {
				$this->sumar($r->sin_fecha_matricula, $m);
			} elseif (!$hay_corte) {
				$this->sumar($r->sin_clasificar, $m);
			} elseif ($m->fecha_matricula <= $fin_periodo1) {
				$this->sumar($r->iniciaron, $m);
			} else {
				$this->sumar($r->ingresaron, $m);
			}

			// Salida: aquí sí manda estado, que es lo único que distingue el motivo.
			if ($m->estado === 'RETI') {
				$this->sumar($r->retirados, $m);
			} elseif ($m->estado === 'DESE') {
				$this->sumar($r->desertores, $m);
			} else {
				$this->sumar($r->terminaron, $m);
			}
		}

		// iniciaron + ingresaron + sin_fecha - retirados - desertores = terminaron
		$esperado = $r->iniciaron->total + $r->ingresaron->total + $r->sin_fecha_matricula->total
					+ $r->sin_clasificar->total
					- $r->retirados->total - $r->desertores->total;

		$r->descuadre = $r->terminaron->total - $esperado;
		$r->cuadra    = ($r->descuadre === 0);

		return $r;
	}


	/*
	 * Promoción, contada SÓLO sobre quienes terminaron el año. Un retirado no se promueve ni se
	 * reprueba, y mezclarlos era lo que hacía que "Total PROMOVIDOS" superara a "terminaron".
	 *
	 * Los baldes por cantidad de pendientes ya no se pisan: antes la condición era
	 * `cant_asign_perdidas == N || cant_areas_perdidas == N`, y como cant_areas_perdidas es
	 * NOT NULL DEFAULT 0, en un colegio que no lleva áreas TODO promovido caía en el balde 0 y
	 * promovidos_1_perdidas era permanentemente cero.
	 */
	private function resumenPromocion($alumnos, $usa_areas)
	{
		$p = (object) [
			'promovidos_0'        => $this->nuevoContador(),
			'promovidos_1'        => $this->nuevoContador(),
			'promovidos_otros'    => $this->nuevoContador(),
			'no_promovidos_2'     => $this->nuevoContador(),
			'no_promovidos_3'     => $this->nuevoContador(),
			'no_promovidos_4'     => $this->nuevoContador(),
			'no_promovidos_otros' => $this->nuevoContador(),
			'pendientes'          => $this->nuevoContador(),
			'sin_definir'         => $this->nuevoContador(),
			'total_promovidos'    => $this->nuevoContador(),
			'total_no_promovidos' => $this->nuevoContador(),
			'evaluados'           => $this->nuevoContador(),
		];

		foreach ($alumnos as $m) {
			if ($m->salio) continue;

			$this->sumar($p->evaluados, $m);

			// Una sola métrica de pendientes, la que el año lectivo usa de verdad.
			$perdidas = $usa_areas
				? (int) $m->cant_areas_perdidas
				: (int) $m->cant_asign_perdidas;

			if ($m->estado_promocion === 'PROMOVIDO') {
				$this->sumar($p->total_promovidos, $m);
				if ($perdidas === 0)     $this->sumar($p->promovidos_0, $m);
				elseif ($perdidas === 1) $this->sumar($p->promovidos_1, $m);
				else                     $this->sumar($p->promovidos_otros, $m);

			} elseif ($m->estado_promocion === 'NO_PROMOVIDO') {
				$this->sumar($p->total_no_promovidos, $m);
				if ($perdidas === 2)     $this->sumar($p->no_promovidos_2, $m);
				elseif ($perdidas === 3) $this->sumar($p->no_promovidos_3, $m);
				elseif ($perdidas >= 4)  $this->sumar($p->no_promovidos_4, $m);
				// Un "No promovido (manual)" con 0 ó 1 pendientes es coherente -- lo decidió la
				// comisión -- pero no cabe en los baldes 2/3/4+. Antes desaparecía y el total,
				// que era la suma de los tres baldes, subcontaba.
				else                     $this->sumar($p->no_promovidos_otros, $m);

			} elseif ($m->estado_promocion === 'PENDIENTE') {
				$this->sumar($p->pendientes, $m);

			} else {
				$this->sumar($p->sin_definir, $m);
			}
		}

		$suma = $p->total_promovidos->total + $p->total_no_promovidos->total
				+ $p->pendientes->total + $p->sin_definir->total;

		$p->descuadre = $p->evaluados->total - $suma;
		$p->cuadra    = ($p->descuadre === 0);

		return $p;
	}


	/*
	 * Movimiento por periodo -- lo que el informe nunca tuvo. En el template había una tabla
	 * "Cantidades por periodos" a medio construir, comentada y con las celdas vacías; los datos
	 * para llenarla siempre estuvieron ahí (fecha_retiro más el calendario de periodos).
	 *
	 * Las fechas que no caen en ningún periodo no se pierden: van al balde 'fuera_calendario', que
	 * suele delatar periodos mal configurados o retiros con fecha de otro año.
	 */
	private function movimientoPorPeriodo($alumnos, $periodos)
	{
		$filas = [];
		foreach ($periodos as $periodo) {
			$filas[] = (object) [
				'periodo_id'   => $periodo->id,
				'numero'       => $periodo->numero,
				'fecha_inicio' => $periodo->fecha_inicio,
				'fecha_fin'    => $periodo->fecha_fin,
				'ingresos'     => $this->nuevoContador(),
				'retiros'      => $this->nuevoContador(),
				'deserciones'  => $this->nuevoContador(),
			];
		}

		$fuera = (object) [
			'periodo_id'  => null,
			'numero'      => null,
			'ingresos'    => $this->nuevoContador(),
			'retiros'     => $this->nuevoContador(),
			'deserciones' => $this->nuevoContador(),
		];

		foreach ($alumnos as $m) {
			$fila = $this->periodoDe($filas, $m->fecha_matricula);
			if ($fila)                   $this->sumar($fila->ingresos, $m);
			elseif ($m->fecha_matricula) $this->sumar($fuera->ingresos, $m);

			if (!$m->salio || !$m->fecha_retiro) continue;

			$fila    = $this->periodoDe($filas, $m->fecha_retiro);
			$destino = $fila ? $fila : $fuera;
			if ($m->estado === 'RETI') $this->sumar($destino->retiros, $m);
			else                       $this->sumar($destino->deserciones, $m);
		}

		return (object) ['filas' => $filas, 'fuera_calendario' => $fuera];
	}


	private function periodoDe($filas, $fecha)
	{
		if ($fecha === null || $fecha === '') return null;

		// Las fechas vienen como 'Y-m-d' de MySQL, así que comparar como texto ordena bien.
		$dia = substr((string) $fecha, 0, 10);

		foreach ($filas as $fila) {
			if (!$fila->fecha_inicio || !$fila->fecha_fin) continue;
			if ($dia >= substr((string) $fila->fecha_inicio, 0, 10)
				&& $dia <= substr((string) $fila->fecha_fin, 0, 10)) return $fila;
		}
		return null;
	}


	/*
	 * razon_retiro se imprime por alumno desde siempre, pero nunca se agregó. Es el dato que la
	 * comisión necesita para hablar de causas y no sólo de cantidades.
	 */
	private function razonesDeRetiro($alumnos)
	{
		$razones = [];
		foreach ($alumnos as $m) {
			if (!$m->salio) continue;

			$razon = trim((string) $m->razon_retiro);
			$clave = $razon === '' ? '(sin registrar)' : mb_strtolower($razon, 'UTF-8');

			if (!isset($razones[$clave])) {
				$razones[$clave] = (object) [
					'razon'    => $razon === '' ? '(sin registrar)' : $razon,
					'contador' => $this->nuevoContador(),
				];
			}
			$this->sumar($razones[$clave]->contador, $m);
		}

		$lista = array_values($razones);
		usort($lista, function($a, $b) {
			return $b->contador->total - $a->contador->total;
		});

		return $lista;
	}


	/*
	 * repitente y nuevo existen en la tabla desde siempre y no aparecían en ningún resumen.
	 *
	 * Extraedad: sin un mapeo confiable de grado a edad esperada (grados.orden es un entero libre,
	 * sin garantía de ser el número del grado), se mide contra la edad modal del propio grupo --
	 * 2 o más años por encima. NO es la definición del MEN, y la pantalla lo dice.
	 */
	private function perfilDelGrupo($alumnos)
	{
		$perfil = (object) [
			'repitentes' => $this->nuevoContador(),
			'nuevos'     => $this->nuevoContador(),
			'extraedad'  => $this->nuevoContador(),
			'edad_modal' => null,
			'edad_min'   => null,
			'edad_max'   => null,
		];

		$edades = [];
		foreach ($alumnos as $m) {
			if ($m->repitente) $this->sumar($perfil->repitentes, $m);
			if ($m->nuevo)     $this->sumar($perfil->nuevos, $m);
			if ($m->edad !== '' && $m->edad !== null) $edades[] = (int) $m->edad;
		}

		if (count($edades) > 0) {
			$frecuencias = array_count_values($edades);
			arsort($frecuencias);
			$claves = array_keys($frecuencias);
			$perfil->edad_modal = (int) $claves[0];
			$perfil->edad_min   = min($edades);
			$perfil->edad_max   = max($edades);

			foreach ($alumnos as $m) {
				if ($m->edad === '' || $m->edad === null) continue;
				if ((int) $m->edad >= $perfil->edad_modal + 2) $this->sumar($perfil->extraedad, $m);
			}
		}

		return $perfil;
	}


	/*
	 * Totales de la institución. El acta nunca los tuvo: sólo había cuadros por grupo, y el rector
	 * terminaba sumando a mano.
	 */
	private function consolidado($grupos, $periodos)
	{
		$filas_periodo = [];
		foreach ($periodos as $periodo) {
			$filas_periodo[] = (object) [
				'numero'      => $periodo->numero,
				'ingresos'    => $this->nuevoContador(),
				'retiros'     => $this->nuevoContador(),
				'deserciones' => $this->nuevoContador(),
			];
		}

		$c = (object) [
			'resumen'   => (object) [
				'iniciaron'           => $this->nuevoContador(),
				'ingresaron'          => $this->nuevoContador(),
				'sin_fecha_matricula' => $this->nuevoContador(),
				'sin_clasificar'      => $this->nuevoContador(),
				'retirados'           => $this->nuevoContador(),
				'desertores'          => $this->nuevoContador(),
				'terminaron'          => $this->nuevoContador(),
				'total_matriculas'    => $this->nuevoContador(),
			],
			'promocion' => (object) [
				'promovidos_0'        => $this->nuevoContador(),
				'promovidos_1'        => $this->nuevoContador(),
				'promovidos_otros'    => $this->nuevoContador(),
				'no_promovidos_2'     => $this->nuevoContador(),
				'no_promovidos_3'     => $this->nuevoContador(),
				'no_promovidos_4'     => $this->nuevoContador(),
				'no_promovidos_otros' => $this->nuevoContador(),
				'pendientes'          => $this->nuevoContador(),
				'sin_definir'         => $this->nuevoContador(),
				'total_promovidos'    => $this->nuevoContador(),
				'total_no_promovidos' => $this->nuevoContador(),
				'evaluados'           => $this->nuevoContador(),
			],
			'periodos'            => $filas_periodo,
			'razones_retiro'      => [],
			'grupos_descuadrados' => [],
		];

		$razones = [];

		foreach ($grupos as $grupo) {
			// El `(array)` no cambia nada en tiempo de ejecución —recorrer un
			// stdClass ya recorre sus campos— pero dice en voz alta que lo que
			// se recorre son los contadores del objeto, no una lista.
			foreach ((array) $c->resumen as $clave => $contador) {
				if (!isset($grupo->resumen->$clave)) continue;
				$this->acumular($contador, $grupo->resumen->$clave);
			}
			foreach ((array) $c->promocion as $clave => $contador) {
				if (!isset($grupo->promocion->$clave)) continue;
				$this->acumular($contador, $grupo->promocion->$clave);
			}
			foreach ($grupo->periodos->filas as $i => $fila) {
				if (!isset($c->periodos[$i])) continue;
				$this->acumular($c->periodos[$i]->ingresos,    $fila->ingresos);
				$this->acumular($c->periodos[$i]->retiros,     $fila->retiros);
				$this->acumular($c->periodos[$i]->deserciones, $fila->deserciones);
			}

			foreach ($grupo->razones_retiro as $r) {
				$clave = mb_strtolower($r->razon, 'UTF-8');
				if (!isset($razones[$clave])) {
					$razones[$clave] = (object) ['razon' => $r->razon, 'contador' => $this->nuevoContador()];
				}
				$this->acumular($razones[$clave]->contador, $r->contador);
			}

			if (!$grupo->resumen->cuadra || !$grupo->promocion->cuadra) {
				$c->grupos_descuadrados[] = $grupo->nombre;
			}
		}

		$c->razones_retiro = array_values($razones);
		usort($c->razones_retiro, function($a, $b) {
			return $b->contador->total - $a->contador->total;
		});

		$esperado = $c->resumen->iniciaron->total + $c->resumen->ingresaron->total
					+ $c->resumen->sin_fecha_matricula->total + $c->resumen->sin_clasificar->total
					- $c->resumen->retirados->total - $c->resumen->desertores->total;

		$c->resumen->descuadre = $c->resumen->terminaron->total - $esperado;
		$c->resumen->cuadra    = ($c->resumen->descuadre === 0);

		$suma = $c->promocion->total_promovidos->total + $c->promocion->total_no_promovidos->total
				+ $c->promocion->pendientes->total + $c->promocion->sin_definir->total;

		$c->promocion->descuadre = $c->promocion->evaluados->total - $suma;
		$c->promocion->cuadra    = ($c->promocion->descuadre === 0);

		return $c;
	}


	private function acumular($destino, $origen)
	{
		$destino->total += $origen->total;
		$destino->m     += $origen->m;
		$destino->f     += $origen->f;
		$destino->sd    += $origen->sd;
		// Los ids del consolidado no se acumulan a propósito: la lista de un total institucional no
		// cabe en un modal y el payload se dispararía. El detalle se consulta por grupo.
	}


	/*
	 * No hay UNIQUE(alumno_id, grupo_id) en matriculas: sólo índices simples. Si un alumno tiene
	 * dos filas en el mismo grupo, TODOS los conteos -- que son sobre filas -- lo cuentan dos
	 * veces. Es la clase de cosa que produce "los números no cuadran" sin dejar rastro, así que el
	 * acta lo denuncia en vez de sumar callada.
	 */
	private function buscarDuplicados($matriculas)
	{
		$vistos     = [];
		$duplicados = [];

		foreach ($matriculas as $m) {
			$clave = $m->alumno_id . '-' . $m->grupo_id;
			if (isset($vistos[$clave])) {
				$duplicados[$clave] = (object) [
					'alumno_id' => $m->alumno_id,
					'grupo_id'  => $m->grupo_id,
					'nombre'    => trim($m->apellidos . ' ' . $m->nombres),
				];
			}
			$vistos[$clave] = true;
		}

		return array_values($duplicados);
	}


	/*
	 * Firmantes de la comisión. Un acta certifica que un grupo de personas se reunió y decidió; sin
	 * firmas no certifica nada, y el documento terminaba en la última tabla.
	 *
	 * Si el año no tiene firmantes guardados se propone el rector, que es el único cargo que
	 * siempre existe en years.
	 */
	private function firmantesDelYear($year)
	{
		// Consulta propia y no una columna en Year::datos_basicos, que la llaman docenas de
		// pantallas: si el código se despliega antes de correr la migración, el fallo se queda en
		// esta pantalla en vez de tumbar toda la aplicación. Los despliegues van colegio por
		// colegio, así que ese desfase es una posibilidad real y no una hipótesis.
		$guardados = null;
		try {
			$fila = DB::select('SELECT firmantes_acta FROM years WHERE id=? and deleted_at is null',
				[$year->year_id]);
			if (count($fila) > 0) $guardados = $fila[0]->firmantes_acta;
		} catch (\Exception $e) {
			Log::warning('years.firmantes_acta no existe todavía; falta correr la migración.');
		}

		if ($guardados) {
			$lista = json_decode($guardados, true);
			if (is_array($lista) && count($lista) > 0) return $lista;
		}

		$sugeridos = [];
		if (!empty($year->nombres_rector)) {
			$sugeridos[] = [
				'nombre'    => trim($year->nombres_rector . ' ' . $year->apellidos_rector),
				'cargo'     => $year->titulo_rector ? $year->titulo_rector : 'Rector(a)',
				'documento' => '',
			];
		}
		if (!empty($year->nombres_secretario)) {
			$sugeridos[] = [
				'nombre'    => trim($year->nombres_secretario . ' ' . $year->apellidos_secretario),
				'cargo'     => 'Secretario(a)',
				'documento' => '',
			];
		}

		return $sugeridos;
	}


	/*
	 * Texto libre y firmantes del acta.
	 *
	 * La pantalla llamaba desde siempre a 'actas-evaluacion/cambiar-descripcion', una ruta que no
	 * existía: guardar el texto fallaba con 404 en silencio. Aquí está la ruta, y de paso guarda
	 * los firmantes de la comisión.
	 */
	public function putGuardarTextoActa()
	{
		$user = User::fromToken();

		$texto     = Request::input('texto_acta_eval');
		$firmantes = Request::input('firmantes_acta');

		$campos = [];
		$datos  = [
			':year_id'     => $user->year_id,
			':modificador' => $user->user_id,
			':fecha'       => Carbon::now('America/Bogota'),
		];

		if ($texto !== null) {
			$campos[] = 'texto_acta_eval=:texto';
			$datos[':texto'] = $texto;
		}

		if ($firmantes !== null) {
			// Llega como arreglo desde la pantalla y se guarda como JSON. Se normaliza para no
			// almacenar lo que el cliente mande tal cual.
			$limpios = [];
			foreach ((array) $firmantes as $f) {
				$f      = (array) $f;
				$nombre = isset($f['nombre'])    ? $f['nombre']    : '';
				$cargo  = isset($f['cargo'])     ? $f['cargo']     : '';
				$doc    = isset($f['documento']) ? $f['documento'] : '';

				if (trim((string) $nombre) === '' && trim((string) $cargo) === '') continue;

				$limpios[] = [
					'nombre'    => mb_substr(trim((string) $nombre), 0, 150),
					'cargo'     => mb_substr(trim((string) $cargo), 0, 100),
					'documento' => mb_substr(trim((string) $doc), 0, 50),
				];
			}
			$campos[] = 'firmantes_acta=:firmantes';
			$datos[':firmantes'] = json_encode($limpios, JSON_UNESCAPED_UNICODE);
		}

		if (count($campos) === 0) {
			return response()->json(['msg' => 'Nada que guardar'], 422);
		}

		$campos[] = 'updated_by=:modificador';
		$campos[] = 'updated_at=:fecha';

		DB::update('UPDATE years SET ' . implode(', ', $campos) . ' WHERE id=:year_id', $datos);

		return ['ok' => true];
	}




	public function putDetalle()
	{
		$user 			= User::fromToken();
		$alumno_id 		= Request::input('alumno_id');


		$consulta = 'SELECT m.id as matricula_id, m.alumno_id, a.no_matricula, a.nombres, a.apellidos, a.sexo, a.user_id,
                a.fecha_nac, a.ciudad_nac, a.celular, a.direccion, a.religion, t.tipo as tipo_doc, t.abrev as tipo_doc_abrev, a.documento,
                m.grupo_id, m.estado, m.nuevo, m.repitente, username, a.created_at,
                u.imagen_id, IFNULL(i.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as imagen_nombre,
                a.foto_id, IFNULL(i2.nombre, IF(a.sexo="F","default_female.png", "default_male.png")) as foto_nombre
            FROM alumnos a
            inner join matriculas m on a.id=m.alumno_id and m.grupo_id=?
            left join users u on a.user_id=u.id and u.deleted_at is null
            left join tipos_documentos t on a.tipo_doc=t.id and t.deleted_at is null
            left join images i on i.id=u.imagen_id and i.deleted_at is null
            left join images i2 on i2.id=a.foto_id and i2.deleted_at is null
            where a.deleted_at is null and m.deleted_at is null
            order by a.apellidos, a.nombres';

        $alumnos = DB::select($consulta, [ Request::input('grupo_id') ]);

		// Años de estadía
		// Las columnas de `matriculas` nombradas, NO `m.*`: con `*`,
		// `matriculas.boletin_independiente` (24 ago 2026) entraba aquí y movía la
		// instantánea `actas-evaluacion-detalle`. Fue el primero de los cinco que cazó
		// el criterio de la §4.
		//
		// **Esa columna se retiró el 31 ago 2026** (§2.2 del 19-boletin-independiente.md)
		// y la instantánea **no se movió al quitarla**, porque esta lista nunca la
		// nombró. La regla sigue: la próxima columna de `matriculas` movería esta
		// respuesta con `*` y no con esto. §5.ter de noche-2026-08-24/bi-1.md.
		$consulta = 'SELECT y.year, m.id, m.alumno_id, m.grupo_id, m.estado, m.prematriculado, m.fecha_retiro, m.fecha_matricula, m.fecha_pension, m.razon_retiro, m.programar, m.descripcion_recomendacion, m.efectuar_una, m.descripcion_efectuada, m.profes_editar_notas, m.nuevo, m.repitente, m.promovido, m.promedio, m.cant_asign_perdidas, m.cant_areas_perdidas, m.anios_in_cole, m.nro_folio, m.created_by, m.updated_by, m.deleted_by, m.deleted_at, m.created_at, m.updated_at, g.nombre, m.id as matricula_id
			FROM matriculas m
			INNER JOIN alumnos a ON a.id=m.alumno_id and m.deleted_at is null and a.deleted_at is null
			INNER JOIN grupos g ON g.id=m.grupo_id and g.deleted_at is null
			INNER JOIN years y ON g.year_id=y.id and y.deleted_at is null
			WHERE a.id=? order by y.year';

		$anios = DB::select($consulta, [$alumno_id]);


        return [ 'alumnos' => $alumnos, 'matriculas' => $anios ];
    }

}
