<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;

use App\Models\Nota;
use App\User;
use App\Support\RepartoDeLaNota;
use App\Support\SellaConElReloj;

/**
 * Las columnas de `subunidades`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property ?string $definicion
 * @property ?int $porcentaje
 * @property int $unidad_id
 * @property ?int $nota_default
 * @property ?int $obligatoria
 * @property ?int $orden
 * @property ?int $por_defecto
 * @property ?string $inicia_at
 * @property ?string $finaliza_at
 * @property ?int $actividad_id
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 *
 * --- y las que no salen del volcado: las movió aquí tools/columnas-en-los-modelos.php ---
 *
 * Entran por migración, así que el esquema congelado no las tiene y esta
 * herramienta no puede generarlas. Estaban DENTRO de las marcas, que es donde
 * la siguiente corrida las habría borrado sin poner nada rojo. Aquí no se tocan.
 *
 * @property ?int $rubrica_id  ← a mano: la añade 2026_09_03_100000_rubricas y no está en el volcado (26 §4.7)
 */

class Subunidad extends Model {

	use SellaConElReloj;
	use SoftDeletes;
	
	protected $fillable = [];
	protected $table = 'subunidades';

	protected $dates = ['deleted_at', 'created_at'];
	protected $softDelete = true;



	public static function deUnidad($unidad_id)
	{
		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at
					FROM subunidades s
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';

		$unidades = DB::select($consulta, array(
			':unidad_id'	=> $unidad_id
		));

		return $unidades;
	}


	public static function deUnidad2($alumno_id, $unidad_id, $year_id)
	{
		$modo = RepartoDeLaNota::modoDelAnio($year_id);

		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, '.RepartoDeLaNota::valorDeLaNota($modo).' as valor_nota, n.nota, e.desempenio, 
						CONCAT("<div class=\"row\">
							<div class=\"col-lg-9 col-xs-9 subunidad-definicion no-padding-right\">", s.definicion, "</div>
							<div class=\"col-lg-1 col-xs-1 subunidad-porc\">", s.porcentaje,"</div>
							<div style=\"font-size: 5pt; line-height: 2;\" class=\"col-lg-1 col-xs-1 subunidad-nota\">", e.desempenio,"</div>
							<div class=\"col-lg-1 col-xs-1 subunidad-nota\">
								<span ", IF(n.nota<:min_aceptada, "class=\"nota-perdida-bold\" ", ""), " uib-tooltip=\"Valor nota: {{::subunidad.valor_nota}}\">", n.nota,"</div>
						</div>") as fila_subunidad
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and n.nota < e.porc_final + 1 and e.deleted_at is null and e.year_id=:year_id
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';

		$unidades = DB::select($consulta, array(
			':min_aceptada' => User::$nota_minima_aceptada, ':alumno_id'	=> $alumno_id, ':unidad_id'	=> $unidad_id, ':year_id'	=> $year_id 
		));

		return $unidades;
	}
	


	/**
	 * El indicador con su nota **y su par de nivelación** — A10, 27 §2.1.
	 *
	 * Las cuatro columnas de nivelación se nombran aquí y no en cada informe porque
	 * de esta consulta cuelgan los dos que tienen que imprimir el par: el boletín
	 * tipo 1 y 5 (`Informes/BoletinesController:349`) y las notas actuales del alumno
	 * (`NotasActualesAlumnosController:190`), que es la pantalla del acudiente y la
	 * que el art. 16 del 1290 tiene en mente.
	 *
	 * **La celda está nivelada ⇔ `nota_original !== null`**, igual que en
	 * `notas/detailed` (22 §3.1). No hay bandera aparte: sería un segundo sitio donde
	 * mentir. `nota` sigue siendo **la vigente** —la que ya se imprimía— así que un
	 * front que no lea las nuevas imprime exactamente lo de antes (plan §3.2).
	 *
	 * `nota_nivelacion` viaja además de `nota_original` porque con la regla `topada`
	 * las dos son distintas de `nota`: el alumno sacó 90 en la nivelación y le queda
	 * 70, y un boletín que sólo enseñara `55 → 70` escondería lo que hizo.
	 */
	public static function deUnidadCalculada($alumno_id, $unidad_id, $year_id)
	{
		$modo = RepartoDeLaNota::modoDelAnio($year_id);

		$consulta = 'SELECT n.id as nota_id, s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, '.RepartoDeLaNota::valorDeLaNota($modo).' as valor_nota, n.nota, e.desempenio, 
						n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelacion_obs,
						s.definicion, s.porcentaje, e.desempenio, IF(n.nota<:min_aceptada, "nota-perdida-bold", "") as clase_perdida, n.nota
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and n.nota < e.porc_final + 1 and e.deleted_at is null and e.year_id=:year_id
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';
		//  limit 1
		$unidades = DB::select($consulta, array(
			':min_aceptada' => User::$nota_minima_aceptada, ':alumno_id'	=> $alumno_id, ':unidad_id'	=> $unidad_id, ':year_id'	=> $year_id 
		));

		return $unidades;
	}
	

	/**
	 * Las mismas filas que {@see deUnidadCalculada}, pero de **varias unidades a la
	 * vez** y agrupadas por la suya.
	 *
	 * Existe desde el 22 sep 2026, cuando la nota de unidad dejó el SQL y pasó a
	 * calcularse en PHP: sin esto, `Unidad::deAsignaturaCalculada` habría necesitado
	 * **una consulta por unidad** para poder sumar, que sobre un boletín de grupo son
	 * cuatro por asignatura y por alumno. Con el `IN` es **una por asignatura**.
	 *
	 * **La consulta es la de la hermana con dos cambios y ni uno más**: el `IN` en vez
	 * del `=`, y `s.unidad_id` en el `SELECT` para poder agrupar al volver. Se escribe
	 * así a propósito —copiada y no factorizada— porque la de al lado es contrato de
	 * cuatro informes y una plantilla compartida haría que tocar una moviera la otra
	 * sin que nadie lo pidiera.
	 *
	 * Con la lista vacía devuelve `[]` **sin ir a la base**: un `IN ()` es un error de
	 * sintaxis en MySQL, y una asignatura sin unidades es un caso normal, no un fallo.
	 *
	 * @param  list<int>  $unidad_ids
	 * @return array<int, list<object>>  unidad_id => sus subunidades, en orden
	 */
	public static function deLasUnidadesCalculadas(array $unidad_ids, $alumno_id, $year_id): array
	{
		if ($unidad_ids === []) {
			return [];
		}

		$modo = RepartoDeLaNota::modoDelAnio($year_id);

		if (self::$delGrupo !== null && is_numeric($year_id) && isset(self::$delGrupo['alumnos'][(int) $alumno_id])
			&& (string) (int) $alumno_id === (string) $alumno_id) {
			$clave = (int) $year_id.'|'.$modo.'|'.implode(',', array_map('intval', $unidad_ids));
			self::$delGrupo['lotes'][$clave] ??= self::deLasUnidadesDelGrupo(
				$unidad_ids, array_keys(self::$delGrupo['alumnos']), (int) $year_id, $modo
			);

			return self::$delGrupo['lotes'][$clave][(int) $alumno_id] ?? [];
		}

		$marcas = implode(',', array_fill(0, count($unidad_ids), '?'));

		$consulta = 'SELECT s.unidad_id, n.id as nota_id, s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, '.RepartoDeLaNota::valorDeLaNota($modo).' as valor_nota, n.nota, e.desempenio,
						n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelacion_obs,
						s.definicion, s.porcentaje, e.desempenio, IF(n.nota<?, "nota-perdida-bold", "") as clase_perdida, n.nota
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=?
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and n.nota < e.porc_final + 1 and e.deleted_at is null and e.year_id=?
					where s.unidad_id IN ('.$marcas.') and s.deleted_at is null
					order by s.unidad_id, s.orden';

		$filas = DB::select($consulta, array_merge(
			[User::$nota_minima_aceptada, $alumno_id, $year_id],
			array_map('intval', $unidad_ids)
		));

		$porUnidad = [];

		foreach ($filas as $fila) {
			$porUnidad[(int) $fila->unidad_id][] = $fila;
		}

		return $porUnidad;
	}


	/**
	 * `null` = apagada, que es lo normal. Encendida, los alumnos del grupo que se está
	 * armando y lo que ya se trajo de ellos, por unidades pedidas.
	 *
	 * @var array{alumnos: array<int, true>, lotes: array<string, array<int, array<int, list<object>>>>}|null
	 */
	private static ?array $delGrupo = null;

	/**
	 * Arma algo —un boletín de grupo— pidiendo las subunidades de
	 * {@see deLasUnidadesCalculadas} **una vez por asignatura para todos los alumnos**, y
	 * no una por alumno × asignatura: 456 consultas en un grupo de 38
	 * (docs/migracion/48). Se enciende a mano, como `RepartoDeLaNota::recordandoElReparto`:
	 * quien escribe notas en la misma petición no debe leer de aquí.
	 *
	 * @template T
	 * @param  list<int>  $alumnoIds
	 * @param  callable(): T  $armar
	 * @return T
	 */
	public static function recordandoElGrupo(array $alumnoIds, callable $armar)
	{
		$antes = self::$delGrupo;
		self::$delGrupo = ['alumnos' => array_fill_keys(array_map('intval', $alumnoIds), true), 'lotes' => []];

		try {
			return $armar();
		} finally {
			self::$delGrupo = $antes;
		}
	}

	/**
	 * La consulta de {@see deLasUnidadesCalculadas} para varios alumnos: el alumno sale de
	 * una tabla de ids en vez del `?`, va delante en el `SELECT` para repartir y se quita
	 * al volver. **Mismas columnas y mismo orden por alumno**; entre dos subunidades con
	 * el mismo `orden` en la misma unidad el orden ya lo decidía MySQL, y la nota de la
	 * unidad es una suma, así que no depende de él.
	 *
	 * @param  list<int>  $unidad_ids
	 * @param  list<int>  $alumnoIds
	 * @return array<int, array<int, list<object>>>  alumno_id => unidad_id => subunidades
	 */
	private static function deLasUnidadesDelGrupo(array $unidad_ids, array $alumnoIds, int $year_id, string $modo): array
	{
		$marcas = implode(',', array_fill(0, count($unidad_ids), '?'));
		$alumnos = implode(' UNION ALL ', array_map(static fn ($id) => 'SELECT '.(int) $id.' AS id', $alumnoIds));

		$consulta = 'SELECT al.id as alumno_de_reparto, s.unidad_id, n.id as nota_id, s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, '.RepartoDeLaNota::valorDeLaNota($modo).' as valor_nota, n.nota, e.desempenio,
						n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelacion_obs,
						s.definicion, s.porcentaje, e.desempenio, IF(n.nota<?, "nota-perdida-bold", "") as clase_perdida, n.nota
					FROM subunidades s
					cross join ('.$alumnos.') al
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=al.id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and n.nota < e.porc_final + 1 and e.deleted_at is null and e.year_id=?
					where s.unidad_id IN ('.$marcas.') and s.deleted_at is null
					order by al.id, s.unidad_id, s.orden';

		$filas = DB::select($consulta, array_merge(
			[User::$nota_minima_aceptada, $year_id],
			array_map('intval', $unidad_ids)
		));

		$porAlumno = [];

		foreach ($filas as $fila) {
			$alumno = (int) $fila->alumno_de_reparto;
			unset($fila->alumno_de_reparto);
			$porAlumno[$alumno][(int) $fila->unidad_id][] = $fila;
		}

		return $porAlumno;
	}


	public static function notas($subunidad_id)
	{
		$notas = Nota::where('subunidad_id', '=', $subunidad_id)->get();
		return $notas;
	}

	public static function perdidasDeUnidad($unidad_id, $alumno_id)
	{
		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, n.id as nota_id, n.nota
					FROM subunidades s
					inner join notas n on n.subunidad_id=s.id and n.alumno_id=:alumno_id and n.nota<:nota_minima
					where s.unidad_id=:unidad_id and s.deleted_at is null';

		$subunidades = DB::select($consulta, array(
			':alumno_id'	=> $alumno_id,
			':nota_minima'	=> User::$nota_minima_aceptada,
			':unidad_id'	=> $unidad_id,
		));

		return $subunidades;
	}
	
	
	
	/*
	 * Aquí vivía `perdidasDeAsignatura($asignatura_id, $alumno_id, $periodo_id)`,
	 * borrado el 24 ago 2026 por la regla de la casa: **sin ruta y muerto se
	 * borra**. Población de la comprobación, que es la mitad del borrado:
	 * **1.430 ficheros revisados y cero llamantes** — 473 de este repo
	 * (`app/` 218, `tests/` 193, `config/` 20, `routes/` 18, `database/` 15,
	 * `resources/` 9, blades incluidos) y 957 de los tres clientes
	 * (`myvc_front` 672, `myvc_front_2` 118, `myvc_flutter` 167). La única
	 * aparición era su propia definición.
	 *
	 * **NO confundir con `perdidasDeUnidad`, que está justo encima y está VIVO
	 * con diez llamantes.** Los nombres se parecen y las funciones no son la
	 * misma: aquélla devuelve **subunidades**, fijada por `s.unidad_id`; ésta
	 * devolvía **unidades** con un `count(n.nota)`, elegidas por
	 * `(asignatura_id, periodo_id)`. Sin esta nota, el siguiente que lea el
	 * borrado va a creer que se quitó el que se usa.
	 *
	 * **Y no se pierde nada**: el `cant_perdidas` que calculaba está escrito a
	 * mano en once sitios más —diez controladores y `Models/Periodo.php`—. No la
	 * sustituyó un método mejor: la misma cuenta se copió dentro de cada
	 * pantalla y ésta se quedó atrás.
	 *
	 * **Lo que compra el borrado**: era **uno de los cuatro predicados
	 * `alumno_id` ambiguos** que el `ALTER TABLE` de `unidades.alumno_id` rompía
	 * con un 1052 (bi-1.md §5.bis y §5.quater). Queda un sitio menos que
	 * mantener y un predicado menos en el `ALTER`.
	 */

	
	
}