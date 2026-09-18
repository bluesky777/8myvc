<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Support\Facades\DB;
use App\Models\EscalaDeValoracion;
use \Log;
/**
 * Las columnas de `areas`, tal como están en el esquema congelado.
 *
 * Generado desde database/schema/mysql-schema.sql — no se edita a mano.
 * Ver tools/columnas-en-los-modelos.php.
 *
 * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---
 *
 * @property int $id
 * @property string $nombre
 * @property ?string $alias
 * @property ?int $jefe_id
 * @property ?int $orden
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?int $deleted_by
 * @property ?string $deleted_at
 * @property ?string $created_at
 * @property ?string $updated_at
 * --- fin de las columnas generadas ---
 */


class Area extends Model {
	protected $fillable = [];

	use SoftDeletes;
	protected $softDelete = true;


	public static function agrupar_asignaturas($grupo_id, $asignaturas, $escalas)
	{

		// Agrupamos por áreas
		$consulta 	= 'SELECT ar.id as area_id, ar.orden, ar.nombre as area_nombre, ar.alias as area_alias
					FROM asignaturas a
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
					where a.deleted_at is null and a.grupo_id=? and a.profesor_id is not null
					group by ar.id order by ar.orden';

		$areas 		= DB::select($consulta, [ $grupo_id ]);
		$cantAr 	= count($areas);
		$cantAs 	= count($asignaturas);

		// Los pesos del grupo se leen UNA vez por llamada y sólo si alguna área los
		// necesita: ver `repartoDelArea`, al final de la clase.
		$pesosDelGrupo 	= null;

		for ($i=0; $i < $cantAr; $i++) {
			$found = 0;
			$areas[$i]->sumatoria 		= 0;
			$areas[$i]->asignaturas 	= [];
			$areas[$i]->creditos 		= 0;
			$areas[$i]->ausencias 		= 0;
			$areas[$i]->tardanzas 		= 0;
			$areas[$i]->per1 			= 0;
			$areas[$i]->per2 			= 0;
			$areas[$i]->per3 			= 0;
			$areas[$i]->per4 			= 0;

			for ($j=0; $j < $cantAs; $j++) {
				if ($areas[$i]->area_id == $asignaturas[$j]->area_id) {
					$found += 1;
					$areas[$i]->sumatoria += $asignaturas[$j]->nota_asignatura;
					if (isset($asignaturas[$j]->creditos)) {
						$areas[$i]->creditos += $asignaturas[$j]->creditos;
					}

					if(isset($asignaturas[$j]->total_ausencias)){
				        $areas[$i]->ausencias += $asignaturas[$j]->total_ausencias;
				    }else if(isset($asignaturas[$j]->ausencias)){
				        $areas[$i]->ausencias += $asignaturas[$j]->ausencias;
				    }

					if(isset($asignaturas[$j]->total_tardanzas)){
					    $areas[$i]->tardanzas += $asignaturas[$j]->total_tardanzas;
					} else if(isset($asignaturas[$j]->tardanzas)){
					    $areas[$i]->tardanzas += $asignaturas[$j]->tardanzas;
					}

					// Bol2 no tiene definitivas, creo que tienen notas_finales. Pero bolfinal sí.
					if(isset($asignaturas[$j]->definitivas)){
						foreach ($asignaturas[$j]->definitivas as $key => $value) {
							//Log::info(get_object_vars($value));
							if (isset($value->periodo)) {
								$field = 'per'.$value->periodo;
								$areas[$i]->{$field} += $value->DefMateria;
							}
						}
					}

					array_push($areas[$i]->asignaturas, $asignaturas[$j]);
				}
			}

			$areas[$i]->cant 				= $found;

			// ¿El colegio repartió esta área a mano? (`asignaturas.porcentaje_area`)
			$reparto = self::repartoDelArea($grupo_id, $areas[$i]->asignaturas, $pesosDelGrupo);

			if ($reparto !== null) {
				// Ponderado: cada asignatura pesa lo que el colegio le puso, no 1/n.
				//
				// El caso que lo motiva es real y está en `simonbolivar`: el área 11
				// agrupa INGLÉS · LENGUA CASTELLANA · TALLER DE LECTURA, en Sexto sólo
				// existen las dos primeras, y el promedio simple imprime 50/50 donde el
				// colegio quiere 60/40.
				//
				// **`sumatoria` y `cant` NO se tocan.** Siguen siendo la suma cruda y
				// cuántas son —lo que significan desde siempre y lo que viaja en la
				// respuesta—, porque un front que las divida por su cuenta tiene que
				// seguir obteniendo lo mismo que obtenía. Lo que cambia es la nota.
				$ponderada 	= 0;
				$areas[$i]->per1 	= 0;
				$areas[$i]->per2 	= 0;
				$areas[$i]->per3 	= 0;
				$areas[$i]->per4 	= 0;

				foreach ($areas[$i]->asignaturas as $k => $asignatura) {
					$peso = $reparto[$k] / 100;

					$ponderada += $asignatura->nota_asignatura * $peso;

					if (isset($asignatura->definitivas)) {
						foreach ($asignatura->definitivas as $definitiva) {
							if (isset($definitiva->periodo)) {
								$field = 'per'.$definitiva->periodo;
								$areas[$i]->{$field} += $definitiva->DefMateria * $peso;
							}
						}
					}
				}

				// **El mismo `round()` que la rama de siempre, y a propósito.** Sobre una
				// escala 0–50 se come medio punto y sobre una 0–5 un 4,6 cambia de banda,
				// pero eso ya pasa hoy en los dieciséis: quitarlo AQUÍ haría que encender
				// los pesos cambiara además el convenio de redondeo por la puerta de
				// atrás, y son dos decisiones distintas. Un cambio a la vez.
				$areas[$i]->area_nota 		= round($ponderada);
			}elseif ($found>0) {
				$areas[$i]->area_nota 		= round($areas[$i]->sumatoria / $found);
				$areas[$i]->per1 		= $areas[$i]->per1 / $found;
				$areas[$i]->per2 		= $areas[$i]->per2 / $found;
				$areas[$i]->per3 		= $areas[$i]->per3 / $found;
				$areas[$i]->per4 		= $areas[$i]->per4 / $found;
			}else{
				$areas[$i]->area_nota 			= 0;
			}

			$esca = 						EscalaDeValoracion::valoracion($areas[$i]->area_nota, $escalas);
			if ($esca) {
				$areas[$i]->area_desempenio 	= EscalaDeValoracion::valoracion($areas[$i]->area_nota, $escalas)->desempenio;
			}else{
				$areas[$i]->area_desempenio 	= '';
			}

		}
		return $areas;
	}



	public static function agrupar_asignaturas_periodos($grupo_id, $asignaturas, $escalas, $num_periodo)
	{

		// Agrupamos por áreas
		$consulta 	= 'SELECT ar.id as area_id, ar.orden, ar.nombre as area_nombre, ar.alias as area_alias
					FROM asignaturas a
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					inner join areas ar on ar.id=m.area_id and ar.deleted_at is null
					where a.deleted_at is null and a.grupo_id=? and a.profesor_id is not null
					group by ar.id order by ar.orden';

		$areas 		= DB::select($consulta, [ $grupo_id ]);
		$cantAr 	= count($areas);
		$cantAs 	= count($asignaturas);

		// Los pesos del grupo se leen UNA vez por llamada y sólo si alguna área los
		// necesita: ver `repartoDelArea`, al final de la clase.
		$pesosDelGrupo 	= null;

		for ($i=0; $i < $cantAr; $i++) {
			$found = 0;
			$areas[$i]->sumatoria_per1 	= 0;
			$areas[$i]->sumatoria_per2 	= 0;
			$areas[$i]->sumatoria_per3 	= 0;
			$areas[$i]->sumatoria_per4 	= 0;
			$areas[$i]->creditos 		= 0;
			$areas[$i]->asignaturas 	= [];

			for ($j=0; $j < $cantAs; $j++) {
				if ($areas[$i]->area_id == $asignaturas[$j]->area_id) {
					$found += 1;

					if (isset($asignaturas[$j]->nota_final_per1)) {
						$areas[$i]->sumatoria_per1 += $asignaturas[$j]->nota_final_per1;
					}
					if (isset($asignaturas[$j]->nota_final_per2)) {
						$areas[$i]->sumatoria_per2 += $asignaturas[$j]->nota_final_per2;
					}
					if (isset($asignaturas[$j]->nota_final_per3)) {
						$areas[$i]->sumatoria_per3 += $asignaturas[$j]->nota_final_per3;
					}
					if (isset($asignaturas[$j]->nota_final_per4)) {
						$areas[$i]->sumatoria_per4 += $asignaturas[$j]->nota_final_per4;
					}
					if (isset($asignaturas[$j]->creditos)) {
						$areas[$i]->creditos += $asignaturas[$j]->creditos;
					}

					array_push($areas[$i]->asignaturas, $asignaturas[$j]);
				}
			}

			$areas[$i]->cant = $found;

			// Un área puede existir en el año y no tener ninguna asignatura en
			// este grupo. Entonces $found es 0 y la división reventaba el informe
			// entero: boletines3 respondía 500 "Division by zero", no solo para
			// esa área sino para todo el grupo.
			//
			// El área sale sin nota. '' es lo que ya devuelve el desempeño cuando
			// no hay escala que aplicar (ver agrupar_asignaturas, más arriba), así
			// que la plantilla lo imprime en blanco sin tocar nada.
			if ($found === 0) {
				$areas[$i]->per1_nota 			= '';
				$areas[$i]->desempenio_per1 	= '';

				if ($num_periodo > 1) {
					$areas[$i]->per2_nota 			= '';
					$areas[$i]->desempenio_per2 	= '';
				}
				if ($num_periodo > 2) {
					$areas[$i]->per3_nota 			= '';
					$areas[$i]->desempenio_per3 	= '';
				}
				if ($num_periodo == 4) {
					$areas[$i]->per4_nota 			= '';
					$areas[$i]->desempenio_per4 	= '';
				}

				continue;
			}

			// ¿El colegio repartió esta área a mano? (`asignaturas.porcentaje_area`)
			// Con `null` —hoy, en los dieciséis— las cuatro líneas de abajo son las de
			// siempre, carácter por carácter.
			$reparto = self::repartoDelArea($grupo_id, $areas[$i]->asignaturas, $pesosDelGrupo);

			$areas[$i]->per1_nota 			= $reparto !== null
				? round(self::ponderar($areas[$i]->asignaturas, $reparto, 'nota_final_per1'))
				: round($areas[$i]->sumatoria_per1 / $found);
			$des 							= EscalaDeValoracion::valoracion($areas[$i]->per1_nota, $escalas);
			if ($des) {
				$areas[$i]->desempenio_per1 	= $des->desempenio;
			}

			if ($num_periodo > 1) {
				$areas[$i]->per2_nota 			= $reparto !== null
					? round(self::ponderar($areas[$i]->asignaturas, $reparto, 'nota_final_per2'))
					: round($areas[$i]->sumatoria_per2 / $found);
				$des 							= EscalaDeValoracion::valoracion($areas[$i]->per2_nota, $escalas);
				if ($des) {
					$areas[$i]->desempenio_per2 	= $des->desempenio;
				}

			}
			if ($num_periodo > 2) {
				$areas[$i]->per3_nota 			= $reparto !== null
					? round(self::ponderar($areas[$i]->asignaturas, $reparto, 'nota_final_per3'))
					: round($areas[$i]->sumatoria_per3 / $found);
				$des 							= EscalaDeValoracion::valoracion($areas[$i]->per3_nota, $escalas);
				if ($des) {
					$areas[$i]->desempenio_per3 	= $des->desempenio;
				}
			}
			if ($num_periodo == 4) {
				$areas[$i]->per4_nota 			= $reparto !== null
					? round(self::ponderar($areas[$i]->asignaturas, $reparto, 'nota_final_per4'))
					: round($areas[$i]->sumatoria_per4 / $found);
				$des 							= EscalaDeValoracion::valoracion($areas[$i]->per4_nota, $escalas);
				if ($des) {
					$areas[$i]->desempenio_per4 	= $des->desempenio;
				}
			}
			//$areas[$i]->area_nota 			= round($areas[$i]->sumatoria / $found);
			//$areas[$i]->area_desempenio 	= EscalaDeValoracion::valoracion($areas[$i]->area_nota, $escalas)->desempenio;
		}
		return $areas;
	}

	/**
	 * El reparto del área —cuánto pesa cada asignatura dentro de ella—, o `null`.
	 *
	 * Devuelve un array paralelo a `$asignaturas` con el `porcentaje_area` de cada
	 * una, y **sólo** cuando TODAS lo tienen y entre todas suman 100. En cualquier
	 * otro caso devuelve `null` y quien llama promedia como lleva promediando
	 * siempre, que es lo que hace seguro encender esto: el día que entró había
	 * **0 de 1.219** asignaturas vivas con peso puesto, así que los cinco informes
	 * imprimen exactamente lo de ayer hasta que un coordinador rellene la columna.
	 *
	 * **Es «todas o ninguna» a propósito.** Media área con pesos no es un reparto
	 * que haya escrito nadie: ponderar con la mitad daría un número que no cuadra
	 * ni con el promedio ni con lo que quiso el coordinador, y que además cambiaría
	 * otra vez el día que rellene la otra mitad.
	 *
	 * **Y se mide sobre las asignaturas que se están sumando de verdad** —las que
	 * trajo quien llama—, no sobre las que el grupo tiene en la base. Si a este
	 * boletín le falta una, los pesos que quedan ya no suman 100 y el área vuelve
	 * al promedio: es la única lectura en la que el número del área cuadra con las
	 * notas impresas a su lado.
	 *
	 * **La consulta es una por llamada y sólo si hace falta.** Un área de una sola
	 * asignatura no se puede repartir —el 100 % de una nota es esa nota—, así que
	 * un grupo sin áreas compuestas no gasta ninguna. `$pesosDelGrupo` entra por
	 * referencia para que las veintidós áreas de un mismo alumno compartan la
	 * lectura en vez de pedirla cada una.
	 */
	private static function repartoDelArea($grupo_id, $asignaturas, &$pesosDelGrupo)
	{
		if (count($asignaturas) < 2) {
			return null;
		}

		if ($pesosDelGrupo === null) {
			$pesosDelGrupo = [];

			$filas = DB::select(
				'SELECT a.id, a.porcentaje_area FROM asignaturas a
					where a.grupo_id=? and a.deleted_at is null',
				[ $grupo_id ]
			);

			foreach ($filas as $fila) {
				// `null` es «nadie lo decidió» y `0` es «decidieron que no cuenta»: sólo
				// entran los decididos, y abajo `isset` distingue los dos casos.
				if ($fila->porcentaje_area !== null) {
					$pesosDelGrupo[(int) $fila->id] = (int) $fila->porcentaje_area;
				}
			}
		}

		$reparto 	= [];
		$suma 		= 0;

		foreach ($asignaturas as $k => $asignatura) {
			// `asignatura_id` puede llegar nulo: el boletín tipo 3 lo saca de un
			// `right join` contra `notas_finales`, así que una asignatura sin nota en
			// el primer periodo viene sin id. Sin id no hay peso, y sin peso no se
			// pondera: el área cae al promedio, que es la caída segura.
			$id = isset($asignatura->asignatura_id) ? (int) $asignatura->asignatura_id : 0;

			if (!isset($pesosDelGrupo[$id])) {
				return null;
			}

			$reparto[$k] 	= $pesosDelGrupo[$id];
			$suma 			+= $pesosDelGrupo[$id];
		}

		return $suma === 100 ? $reparto : null;
	}



	/**
	 * La nota del área aplicando el reparto: cada asignatura por su peso.
	 *
	 * `$campo` es la propiedad que se pondera (`nota_final_per1`, …). Una asignatura
	 * que no la traiga suma 0, **igual que en la rama del promedio**: allí tampoco se
	 * acumula y el divisor la sigue contando, así que las dos ramas tratan el hueco
	 * de la misma manera.
	 */
	private static function ponderar($asignaturas, $reparto, $campo)
	{
		$nota = 0;

		foreach ($asignaturas as $k => $asignatura) {
			if (isset($asignatura->{$campo})) {
				$nota += $asignatura->{$campo} * ($reparto[$k] / 100);
			}
		}

		return $nota;
	}


}
