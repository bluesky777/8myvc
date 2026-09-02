<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;

use App\Models\Nota;
use App\User;
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
 * @property ?int $rubrica_id  ← a mano: la añade 2026_09_03_100000_rubricas y no está en el volcado (26 §4.7)
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */

class Subunidad extends Model {
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
		$consulta = 'SELECT s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, ROUND((n.nota*s.porcentaje/100), 1) as valor_nota, n.nota, e.desempenio, 
						CONCAT("<div class=\"row\">
							<div class=\"col-lg-9 col-xs-9 subunidad-definicion no-padding-right\">", s.definicion, "</div>
							<div class=\"col-lg-1 col-xs-1 subunidad-porc\">", s.porcentaje,"</div>
							<div style=\"font-size: 5pt; line-height: 2;\" class=\"col-lg-1 col-xs-1 subunidad-nota\">", e.desempenio,"</div>
							<div class=\"col-lg-1 col-xs-1 subunidad-nota\">
								<span ", IF(n.nota<:min_aceptada, "class=\"nota-perdida-bold\" ", ""), " uib-tooltip=\"Valor nota: {{::subunidad.valor_nota}}\">", n.nota,"</div>
						</div>") as fila_subunidad
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and e.porc_final>=n.nota and e.deleted_at is null and e.year_id=:year_id
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
		$consulta = 'SELECT n.id as nota_id, s.id as subunidad_id, s.definicion as definicion_subunidad, s.porcentaje as porcentaje_subunidad,
						s.nota_default, s.orden as orden_subunidad, s.inicia_at, s.finaliza_at, ROUND((n.nota*s.porcentaje/100), 1) as valor_nota, n.nota, e.desempenio, 
						n.nota_original, n.nota_nivelacion, n.nivelada_at, n.nivelacion_obs,
						s.definicion, s.porcentaje, e.desempenio, IF(n.nota<:min_aceptada, "nota-perdida-bold", "") as clase_perdida, n.nota
					FROM subunidades s
					left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
					left join escalas_de_valoracion e ON e.porc_inicial<=n.nota and e.porc_final>=n.nota and e.deleted_at is null and e.year_id=:year_id
					where s.unidad_id=:unidad_id and s.deleted_at is null
					order by s.orden';
		//  limit 1
		$unidades = DB::select($consulta, array(
			':min_aceptada' => User::$nota_minima_aceptada, ':alumno_id'	=> $alumno_id, ':unidad_id'	=> $unidad_id, ':year_id'	=> $year_id 
		));

		return $unidades;
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