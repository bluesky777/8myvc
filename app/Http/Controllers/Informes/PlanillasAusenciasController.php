<?php namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;

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
use App\Models\Alumno;


class PlanillasAusenciasController extends Controller {

	public function putTardanzaEntrada()
	{
		$user 	= User::fromToken();

		$year 	= Year::datos_basicos($user->year_id);
		

		$consulta = 'SELECT g.id, g.nombre, g.abrev, g.orden, gra.orden as orden_grado, g.grado_id, g.year_id, g.titular_id,
				p.nombres as nombres_titular, p.apellidos as apellidos_titular, p.titulo,
				g.created_at, g.updated_at, gra.nombre as nombre_grado,
				p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre 
			from grupos g
			inner join grados gra on gra.id=g.grado_id and g.year_id=:year_id 
			left join profesores p on p.id=g.titular_id
			LEFT JOIN images i on i.id=p.foto_id and i.deleted_at is null
			where g.deleted_at is null
			order by g.orden';

		$grupos = DB::select($consulta, [':year_id' => $user->year_id]);

		$cant = count($grupos);
		for ($i=0; $i < $cant; $i++) { 

			$alumnos = Grupo::alumnos($grupos[$i]->id);

			$grupos[$i]->alumnos = $alumnos;

			$cant_alum		= count($alumnos);
			for ($k=0; $k < $cant_alum; $k++) { 
				$alumnos[$k]->userData = Alumno::userData($alumnos[$k]->alumno_id);
			}
		}

		$year->grupos = $grupos;

		return [$year];
	}



	/**
	 * Con un id de profesor que no está daba 500 — §98, cuarto y quinto llamante.
	 *
	 * `Profesor::detallado()` termina en `$profesor[0]` sobre el resultado de un
	 * `DB::select`: sin filas es clave indefinida, error fatal en PHP 8. Y no hace
	 * falta un id inventado — basta uno de la papelera, porque esa consulta filtra
	 * `deleted_at is null`.
	 *
	 * El modelo se deja quieto a propósito: lo llaman **seis** sitios y un
	 * `?? null` allí convertiría seis 500 en seis comportamientos distintos sin
	 * haber medido ninguno. Se arregla en cada llamante, con su test.
	 */
	public function getShowProfesor($profesor_id)
	{
		$user = User::fromToken();

		$existe = DB::selectOne('SELECT id FROM profesores WHERE id = ? AND deleted_at IS NULL', [$profesor_id]);

		if ($existe === null) {
			abort(404, 'Ese profesor no existe o está en la papelera.');
		}

		$year 			= Year::datos_basicos($user->year_id);
		$asignaturas 	= Profesor::asignaturas($user->year_id, $profesor_id);
		$periodos 		= Periodo::where('year_id', '=', $user->year_id)->get();

		$year->periodos 	= $periodos;
		$profesor = Profesor::detallado($profesor_id);
		
		foreach ($asignaturas as $keyAsig => $asignatura) {
			
			$alumnos	= Grupo::alumnos($asignatura->grupo_id);

			$asignatura->nombres_profesor 		= $profesor->nombres_profesor;
			$asignatura->apellidos_profesor 	= $profesor->apellidos_profesor;
			$asignatura->foto_nombre 			= $profesor->foto_nombre;
			$asignatura->foto_id 				= $profesor->foto_id;
			$asignatura->sexo 					= $profesor->sexo;


			$asignatura->periodosProm = Periodo::where('year_id', '=', $user->year_id)->get();

			// A cada alumno le daremos los periodos y la definitiva de cada periodo
			foreach ($alumnos as $keyAl => $alumno) {

				$periodosTemp = Periodo::where('year_id', '=', $user->year_id)->get();

				foreach ($periodosTemp as $keyPer => $periodo) {

					// Unidades y subunidades de la asignatura en el periodo
					$asignaturaTemp = Asignatura::findOrFail($asignatura->asignatura_id);
					$asignaturaTemp->unidades = Unidad::deAsignatura($asignaturaTemp->id, $periodo->id, $alumno->alumno_id);

					foreach ($asignaturaTemp->unidades as $unidad) {
						$unidad->subunidades = Subunidad::deUnidad($unidad->unidad_id);
					}

					// Traemos las notas de esta asignatura segun las unidades y subunidades calculadas arriba
					Asignatura::calculoAlumnoNotas($asignaturaTemp, $alumno->alumno_id);
					$periodo->nota_asignatura = $asignaturaTemp->nota_asignatura;

					// **Los tres números viajan juntos o no viaja ninguno** — fase 1.bis del
					// [43](../../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md).
					// `nota_asignatura` contesta *«cuánto del periodo entero lleva ganado»*, que a
					// mitad de periodo no es la pregunta de nadie: pintada con la escala del
					// colegio dice BAJO de casi todo el mundo, y **uno de cada tres de esos rojos
					// va en SUPERIOR contando sólo lo evaluado** (43 §2). `nota_parcial` es esa
					// otra pregunta y `cobertura` dice sobre cuánto se contesta.
					//
					// **Publicar sólo la primera es lo que hace que el silencio del docente se lea
					// como una nota del alumno**, y por eso no se publica una sin las otras dos:
					// una parcial sin su cobertura es un 47,6 que puede venir de una sola casilla.
					//
					// Las dos pueden venir a `null` y **eso no es un 0**: `nota_parcial` en `null`
					// es «no hay con qué decirlo» —el gris de D2— y `cobertura` en `null` es «no
					// hay plan del que hablar». Y `cobertura` es un **factor de 0 a 1**, no un
					// porcentaje: quien compare contra `15` en vez de contra `0.15` pinta de color
					// absolutamente todo.
					$periodo->nota_parcial = $asignaturaTemp->nota_parcial;
					$periodo->cobertura = $asignaturaTemp->cobertura;

					unset($asignaturaTemp);
				}

				$alumno->periodos = $periodosTemp;
				unset($periodosTemp);





				foreach ($asignatura->periodosProm as $keyPer => $periodo) {
					if (!$periodo->sumatoria) {
						$periodo->sumatoria = 0;
					}

					foreach ($alumno->periodos as $keyPerAl => $periodo_alum) {

						if ($periodo_alum->id == $periodo->id) {
							$periodo->sumatoria += $periodo_alum->nota_asignatura;
						}
					}
				}


			}

			$asignatura->alumnos = $alumnos;

		}

		return array($year, $asignaturas);
	}





}