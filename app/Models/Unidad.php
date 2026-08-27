<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;

use App\Models\Debugging;
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


	public static function deAsignaturaCalculada($alumno_id, $asignatura_id, $periodo_id, $con_desempenio='sin_desempenio', $year_id=0, $nota_minima=70)
	{

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

		if($con_desempenio==='fortaleza_debilidad'){
			
			$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, IF(ROUND(sum((n.nota*s.porcentaje/100))) < :nota_minima, "Debilidad", "Fortaleza") as desempenio,
							u.asignatura_id, u.orden as orden_unidad, u.periodo_id, ROUND(sum((n.nota*s.porcentaje/100))) as nota_unidad
						FROM unidades u
						left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
						where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null and u.alumno_id <=> :alcance
						group by u.id 
						order by u.orden, u.id';

			$unidades = DB::select($consulta, [
				':nota_minima'		=> $nota_minima,
				':alumno_id'		=> $alumno_id,
				':asignatura_id'	=> $asignatura_id,
				':periodo_id'		=> $periodo_id,
				':alcance'			=> $alcance
			]);


			
		} else if ($con_desempenio == 'con_desempenio') {
			
			$consulta = 'SELECT * 
						FROM
						(SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
							u.asignatura_id, u.orden as orden_unidad, u.periodo_id, ROUND(sum((n.nota*s.porcentaje/100))) as nota_unidad
						FROM unidades u
						left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
						where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null and u.alumno_id <=> :alcance
						group by u.id ) r1
						left join escalas_de_valoracion e ON e.porc_inicial<=r1.nota_unidad and e.porc_final>=r1.nota_unidad and e.deleted_at is null and e.year_id=:year_id
						order by r1.orden_unidad, r1.unidad_id';

			$unidades = DB::select($consulta, [
				':alumno_id'		=> $alumno_id,
				':asignatura_id'	=> $asignatura_id,
				':periodo_id'		=> $periodo_id,
				':alcance'			=> $alcance,
				':year_id'			=> $year_id,
			]);
			
		}else{
			$consulta = 'SELECT u.id as unidad_id, u.definicion as definicion_unidad, u.porcentaje as porcentaje_unidad, 
							u.asignatura_id, u.orden as orden_unidad, u.periodo_id, ROUND(sum((n.nota*s.porcentaje/100))) as nota_unidad
						FROM unidades u
						left join subunidades s ON s.unidad_id=u.id and s.deleted_at is null
						left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and n.alumno_id=:alumno_id
						where u.asignatura_id=:asignatura_id and u.periodo_id=:periodo_id and u.deleted_at is null and u.alumno_id <=> :alcance
						group by u.id 
						order by u.orden, u.id';

			$unidades = DB::select($consulta, [
				':alumno_id'		=> $alumno_id,
				':asignatura_id'	=> $asignatura_id,
				':periodo_id'		=> $periodo_id,
				':alcance'			=> $alcance
			]);
		}

		return $unidades;
	}


	public static function informacionAsignatura($asignatura_id, $periodo_id)
	{
		$result = new \stdClass;

		
		$consulta = 'SELECT id, definicion, porcentaje, orden 
					FROM unidades
					where asignatura_id=:asignatura_id and periodo_id=:periodo_id and deleted_at is null
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

}