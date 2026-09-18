<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
/**
 * Las columnas de `frases_asignatura`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property int $alumno_id
 * @property ?int $frase_id
 * @property ?string $frase
 * @property int $asignatura_id
 * @property int $periodo_id
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */


class FraseAsignatura extends Model {
	protected $fillable = [];

	protected $table = 'frases_asignatura';

	use SoftDeletes;
	protected $softDelete = true;


	public static function deAlumno($asignatura_id, $alumno_id, $periodo_id)
	{
		$consulta = 'SELECT fa.id, IFNULL(f.frase, fa.frase) as frase, fa.frase_id, fa.asignatura_id, 
						fa.periodo_id, fa.created_by, fa.created_at, f.tipo_frase
					FROM frases_asignatura fa
					left join frases f on f.id=fa.frase_id and f.deleted_at is null
					where fa.deleted_at is null and fa.alumno_id=:alumno_id and fa.asignatura_id=:asignatura_id and fa.periodo_id=:periodo_id';

		$frases = DB::select($consulta, array(
			':alumno_id'		=> $alumno_id, 
			':asignatura_id'	=> $asignatura_id, 
			':periodo_id'	=> $periodo_id));

		return $frases;
	}


	/**
	 * Las frases de VARIOS alumnos en una asignatura y un periodo, **en una sola
	 * consulta**, agrupadas por alumno.
	 *
	 * Es `deAlumno()` para un grupo entero, y existe por lo mismo que las dos rutas
	 * que la llaman (§5 de
	 * [39](../../../docs/migracion/39-el-modelo-plano-por-competencias.md)):
	 * pintar la pantalla de preescolar con `deAlumno()` son **18 consultas y 18
	 * peticiones** —medido sobre el grupo 3, «Transición» de 2018, 18 matriculados—
	 * y con ésta es una de cada.
	 *
	 * ## Devuelve el texto DOS VECES, y no es redundancia
	 *
	 * - `frase` es lo que **imprime el boletín**: `IFNULL(f.frase, fa.frase)`, o sea
	 *   el del catálogo cuando la fila apunta a uno. Es lo que ya devuelve
	 *   `deAlumno()` con ese mismo nombre, y por eso se llama igual.
	 * - `frase_escrita` es lo que hay guardado **en esta fila**, que es `NULL`
	 *   cuando la frase es del catálogo.
	 *
	 * Con uno solo no se puede escribir el guardado por lotes: para saber si un
	 * `PUT` cambia algo hay que comparar contra lo que la fila tiene, no contra lo
	 * que el boletín enseña — y una frase del catálogo y una escrita a mano que
	 * diga exactamente lo mismo se leerían iguales.
	 *
	 * **El `WHERE` va por el índice.** `frases_asignatura_alumno_asig_periodo_index`
	 * es `(alumno_id, asignatura_id, periodo_id)`, así que el `IN` de alumnos es su
	 * columna de cabecera: medido con `EXPLAIN` el 17 sep 2026 sobre las 12.322
	 * filas del docker, `type=range`, 31 filas examinadas y **0,37 ms**.
	 *
	 * @param  int|string  $asignatura_id
	 * @param  int|string  $periodo_id
	 * @param  list<int>  $alumno_ids
	 * @return array<int, list<object>> por `alumno_id`, y dentro por `id` ascendente
	 */
	public static function deGrupo($asignatura_id, $periodo_id, array $alumno_ids)
	{
		// **Sin alumnos no se pregunta nada**, y no es una optimización: un `IN ()`
		// vacío es un error de sintaxis en MySQL, así que la alternativa sería una
		// consulta que revienta en el grupo recién creado que todavía no tiene a
		// nadie matriculado.
		if ($alumno_ids === []) {
			return array();
		}

		$marcas = implode(', ', array_fill(0, count($alumno_ids), '?'));

		$consulta = 'SELECT fa.id, fa.alumno_id, fa.frase_id,
						fa.frase as frase_escrita, IFNULL(f.frase, fa.frase) as frase,
						f.tipo_frase, fa.created_by, fa.created_at, fa.updated_at
					FROM frases_asignatura fa
					left join frases f on f.id=fa.frase_id and f.deleted_at is null
					where fa.deleted_at is null
					  and fa.alumno_id in ('.$marcas.')
					  and fa.asignatura_id=? and fa.periodo_id=?
					order by fa.alumno_id, fa.id';

		$filas = DB::select(
			$consulta,
			array_merge($alumno_ids, array($asignatura_id, $periodo_id))
		);

		$porAlumno = array();

		foreach ($filas as $fila) {
			// Nada impide dos filas vivas con el mismo texto para el mismo alumno
			// —esta tabla no tiene más clave que la primaria—, así que se agrupan
			// **todas** y no se colapsa ninguna: cada una es una línea del boletín.
			$porAlumno[(int) $fila->alumno_id][] = $fila;
		}

		return $porAlumno;
	}


}