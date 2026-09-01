<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Services\BoletinIndependiente;
use App\Services\DefinitivasDeAsignatura;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

use App\User;
use App\Models\Periodo;
use App\Models\Unidad;
use App\Models\Subunidad;
use App\Models\Nota;
use \stdClass;
use App\Http\Controllers\Concerns\ResuelveElUsuario;


class PeriodosController extends Controller {
	use ResuelveElUsuario;

	public function getIndex()
	{
		$consulta = 'SELECT * FROM periodos WHERE deleted_at is null and year_id=? order by numero';
		return DB::select($consulta, [ $this->user->year_id ]);
	}

	public function postStore($year_id)
	{
		$periodo = new Periodo;
		$periodo->numero						=	Request::input('numero');
		$periodo->fecha_inicio					=	Request::input('fecha_inicio');
		$periodo->fecha_fin						=	Request::input('fecha_fin');
		$periodo->actual						=	0;
		$periodo->year_id						=	$year_id;
		$periodo->profes_pueden_editar_notas	=	1;
		$periodo->profes_pueden_nivelar			=	1;
		$periodo->fecha_plazo					=	Request::input('fecha_plazo');

		$periodo->save();

		return $periodo;
		
	}

	public function getShow($year_id)
	{
		return Periodo::where('year_id', $year_id)->get();
	}

	public function putUpdate($id)
	{
		$periodo = Periodo::findOrFail($id);

		$periodo->numero			=	Request::input('numero');
		$periodo->fecha_inicio		=	Request::input('fecha_inicio');
		$periodo->fecha_fin			=	Request::input('fecha_fin');
		$periodo->actual			=	Request::input('actual');
		$periodo->year				=	Request::input('year');
		$periodo->fecha_plazo		=	Request::input('fecha_plazo');
		$periodo->updated_by 		= 	$this->user->user_id;

		$periodo->save();

		return $periodo;
	}

	public function putCambiarFechaInicio()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->fecha_inicio	=	Carbon::parse(Request::input('fecha'));
		$periodo->updated_by 	= 	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putCambiarFechaFin()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->fecha_fin		=	Carbon::parse(Request::input('fecha'));
		$periodo->updated_by 	= 	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putToggleProfesPuedenEditarNotas()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->profes_pueden_editar_notas	=	Request::input('pueden');
		$periodo->updated_by 					=	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putToggleProfesPuedenNivelar()
	{
		$periodo = Periodo::findOrFail(Request::input('periodo_id'));
		$periodo->profes_pueden_nivelar	=	Request::input('pueden');
		$periodo->updated_by 			= 	$this->user->user_id;
		$periodo->save();

		return 'Cambiado';
	}

	public function putUseractive($periodo_id)
	{
		// El periodo tiene que existir **y no estar en la papelera**, y las dos
		// mitades cuestan distinto. Sin la primera, `users.periodo_id` se lo come
		// hasta que salta la clave ajena: 500 con el SQLSTATE dentro. La segunda es
		// la cara: la clave ajena **no filtra `deleted_at`**, así que un periodo
		// borrado entraba y contestaba 200, y el usuario se quedaba aparcado en un
		// periodo que no sale en ningún selector. Medido con el mismo token: sus
		// pantallas se vacían —0 grupos, 0 asignaturas— **en 200**, sin un error que
		// lo explique. Se sale volviendo a entrar, porque
		// `Services\Login::ponerEnElPeriodoActual` lo devuelve al periodo actual; eso
		// no lo adivina nadie desde una pantalla vacía.
		//
		// Mudarse a un periodo vivo de OTRO año sigue estando permitido: es lo que
		// hace el selector de la barra de arriba, y lo llama también la app de
		// Flutter (`ContextoAcademico.cambiarPeriodo`). Ver §95.
		Periodo::findOrFail($periodo_id);

		$usuario = User::findOrFail($this->user->user_id);
		$usuario->periodo_id 	= $periodo_id;
		$usuario->updated_by 	= 	$this->user->user_id;
		$usuario->save();

		return $usuario;
	}


	public function putEstablecerActual($periodo_id)
	{
		$periodoACambiar = Periodo::findOrFail($periodo_id);
		
		$periodos = Periodo::where('year_id', $periodoACambiar->year_id)->get();

		foreach ($periodos as $periodo) {
			
			if ($periodo->id != $periodoACambiar->id) {
				$periodo->actual = 0;
				$periodo->save();
			}
			
		}

		$periodoACambiar->actual 		= 1;
		$periodoACambiar->updated_by 	= $this->user->user_id;
		$periodoACambiar->save();

		return $periodoACambiar;
	}


	/*
	 * Copiar era la puerta de atrás del candado del periodo, y la única.
	 *
	 * Este método crea unidades, subunidades y —si se lo piden— **notas** en
	 * `periodo_to_id`, que llega en el cuerpo. Las rutas normales que hacen eso
	 * mismo de una en una sí piden permiso: `unidades/store`, `unidades/update`,
	 * `subunidades/store` y `subunidades/update` llaman todas a
	 * `pueden_editar_notas` desde la §27. O sea que un profesor no podía crear una
	 * unidad en un periodo cerrado a mano, y sí copiando treinta de golpe.
	 *
	 * Por eso el permiso se pide para el periodo **destino** y no para el origen:
	 * del origen sólo se lee. Es la regla de la §27 —el permiso del sitio al que
	 * se escribe— y la misma que Joseth aplicó el 22 ago a
	 * `detalles/eliminar-notas-periodo` (§77).
	 *
	 * **Lo encontró una herramienta que estaba mal.** `tools/escrituras-en-las-notas.py`
	 * se escribió esa misma mañana para la §77 y sólo miraba SQL crudo; aquí las
	 * notas se escriben con `new Nota` y `save()`, así que no la vio. Salió una hora
	 * después leyendo otra cosa. Ver 05 §80.
	 */
	public function putCopiar()
	{
		$grupo_from_id 		= Request::input('grupo_from_id');
		$grupo_to_id 		= Request::input('grupo_to_id');
		$asignatura_to_id	= Request::input('asignatura_to_id');
		$copiar_subunidades	= Request::input('copiar_subunidades');
		$copiar_notas		= Request::input('copiar_notas');
		$periodo_from_id	= Request::input('periodo_from_id');
		$periodo_to_id		= Request::input('periodo_to_id');
		$unidades_ids		= Request::input('unidades_ids');

		User::pueden_editar_notas($this->user, $periodo_to_id ? (int) $periodo_to_id : null);

		/*
		 * Copiar la estructura tiene que llevarse TAMBIÉN las unidades con dueño, y
		 * es la §9.4 de 19-boletin-independiente.md.
		 *
		 * `unidades_ids` la arma el front desde la pantalla de estructura, y esa
		 * pantalla enseña **la del grupo**: las de un independiente no están en la
		 * lista y nadie las echa de menos hasta abrir su boletín. Si no se copian, el
		 * periodo nuevo empieza con el marcado sin una sola unidad, su definitiva sale
		 * 0 y **nadie recibe un error** — es la §9.1 entrando por la puerta de copiar.
		 *
		 * **Quién cuenta es el periodo DESTINO y no el de origen.** La marca es por
		 * periodo desde el 31 ago 2026 (decisión 7): un alumno que fue aparte en el 1
		 * y vuelve con el grupo en el 2 **no** se lleva sus unidades al 2, o el
		 * boletín del segundo periodo le saldría aparte sin que nadie lo pidiera.
		 *
		 * Y por eso la lista sale de `delGrupo(destino)` en vez de escribir la
		 * condición a mano: contesta las dos mitades de una vez —alumnos **del grupo
		 * destino** y marcados **en el periodo destino**—, así que copiar a otro grupo
		 * deja la lista vacía sin una línea más. Es la respuesta correcta y no una
		 * casualidad: el dueño de esas unidades no es alumno del grupo al que se copia,
		 * que es el mismo motivo por el que las notas ya no se copiaban entre grupos.
		 */
		$independientes = ($grupo_to_id && $periodo_to_id)
			? BoletinIndependiente::delGrupo((int) $grupo_to_id, (int) $periodo_to_id)['independientes']
			: [];

		$unidades_ids = array_values(array_unique(array_map('intval', (array) $unidades_ids)));
		$de_independientes = $this->unidadesConDuenoQueAcompanan($unidades_ids, $independientes);

		$unidades_copiadas = 0;
		$unidades_de_independientes_copiadas = 0;
		$subunidades_copiadas = 0;
		$notas_copiadas = 0;


		foreach (array_merge($unidades_ids, $de_independientes) as $unidad_id) {

			$unidad_curr = Unidad::findOrFail($unidad_id);

			// Una unidad cuyo dueño ya NO va aparte en el destino no se copia: allí no
			// la leería nadie —`alcance()` devolvería NULL para él— y quedaría como una
			// fila muerta que sí cuenta para «esta asignatura tiene unidades».
			if ($unidad_curr->alumno_id !== null
				&& ! in_array((int) $unidad_curr->alumno_id, $independientes, true)) {
				continue;
			}

			$unidad_new = new Unidad;

			// **El dueño viaja con la unidad.** Sin esta línea, copiar la de un
			// independiente creaba una **del grupo** con su contenido: la forma «de más»
			// de la §9.2, y las definitivas de los treinta salen infladas sin que se mueva
			// nada en el log. Hoy no se ve porque no hay ninguna unidad con dueño.
			$unidad_new->alumno_id 		= $unidad_curr->alumno_id;
			$unidad_new->definicion 	= $unidad_curr->definicion;
			$unidad_new->porcentaje 	= $unidad_curr->porcentaje;
			$unidad_new->orden 			= $unidad_curr->orden;
			$unidad_new->created_by 	= $this->user->user_id;
			$unidad_new->periodo_id 	= $periodo_to_id;
			$unidad_new->asignatura_id 	= $asignatura_to_id;

			$unidad_new->save();

			// **`unidades_copiadas` sigue contando lo que pidió el front, y sólo eso.**
			// Un campo que ya se lee no cambia de significado en silencio: los tres
			// consumidores de esta respuesta —`UnidadesCtrl`, `CopiarCtrl` y la página de
			// `app2`— **se lo enseñan al docente** («Unidades copiadas: N») justo después
			// de que él haya marcado una lista con la mano. Medido el 31 ago 2026 en
			// `myvc_front`: ninguno de los tres lo compara contra `unidades_ids.length`
			// en código, así que **nada se rompería**; quien reconcilia es la persona, y
			// un número mayor que lo que marcó no lo puede contar en su lista.
			if (in_array($unidad_id, $de_independientes, true)) {
				$unidades_de_independientes_copiadas++;
			} else {
				$unidades_copiadas++;
			}


			if ($copiar_subunidades) {
				$subunidades = Subunidad::deUnidad($unidad_id);
				
				foreach ($subunidades as $subunidad) {
					$sub_new = new Subunidad;
					$sub_new->definicion 	= $subunidad->definicion_subunidad;
					$sub_new->porcentaje 	= $subunidad->porcentaje_subunidad;
					$sub_new->unidad_id 	= $unidad_new->id;
					$sub_new->nota_default 	= $subunidad->nota_default;
					$sub_new->orden 		= $subunidad->orden_subunidad;
					$sub_new->inicia_at 	= $subunidad->inicia_at;
					$sub_new->finaliza_at 	= $subunidad->finaliza_at;
					$sub_new->created_by 	= $this->user->user_id;

					$sub_new->save();
					$subunidades_copiadas++;


					if ($copiar_notas and $grupo_to_id==$grupo_from_id) {
					
						$notas = Subunidad::notas($subunidad->subunidad_id);

						foreach ($notas as $nota) {
							$nota_new = new Nota;
							$nota_new->nota 		= $nota->nota;
							$nota_new->subunidad_id = $sub_new->id;
							$nota_new->alumno_id 	= $nota->alumno_id;
							$nota_new->created_by 	= $this->user->user_id;
							
							$nota_new->save();
							$notas_copiadas++;

						}
					}
				}

			}
			

		}
		
		// Fase 3 de 10-definitivas.md: **copiar mueve unidades y hasta hoy no
		// avisaba a nadie.** Traer la estructura de otro periodo cambia los pesos
		// del periodo destino —y con `copiar_notas`, también las notas—, así que
		// las definitivas de ahí quedaban calculadas con lo que había antes.
		//
		// Se recalcula **la asignatura destino entera**, no por alumno: lo que
		// cambió es la estructura, que afecta a todos los del grupo por igual.
		if ($asignatura_to_id && $periodo_to_id) {
			DefinitivasDeAsignatura::recalcular(
				(int) $asignatura_to_id,
				(int) $periodo_to_id,
				$this->user->user_id
			);
		}

		$res = new stdClass;
		$res->unidades_copiadas		= $unidades_copiadas;
		// Campo **añadido**, no cambiado: quien no lo lea sigue funcionando igual, y `0`
		// es la respuesta honesta mientras no haya nadie marcado — que es hoy, en los
		// quince colegios.
		$res->unidades_de_independientes_copiadas = $unidades_de_independientes_copiadas;
		$res->subunidades_copiadas	= $subunidades_copiadas;
		$res->notas_copiadas		= $notas_copiadas;
		
		
		
		// La respuesta repinta **la estructura del grupo**, y por eso `alumno_id IS
		// NULL` en vez de un alcance: aquí no hay ningún alumno en el ámbito, así que
		// la única respuesta con significado es la del grupo. Sin la condición, las
		// unidades de un independiente entrarían mezcladas y **sin nada que las
		// distinga** —la consulta nombra columnas y `alumno_id` no está entre ellas—,
		// o sea filas que el cliente no puede atribuir a nadie.
		//
		// Lo copiado para los independientes se cuenta aparte, en
		// `unidades_de_independientes_copiadas`, y por eso no sale aquí tampoco.
		$consulta = 'SELECT id, definicion, porcentaje, orden 
					FROM unidades
					where asignatura_id=:asignatura_id and periodo_id=:periodo_id and deleted_at is null
						and alumno_id is null
					order by orden';

		$unidades = DB::select($consulta, [
			':asignatura_id'	=> $asignatura_to_id,
			':periodo_id'		=> $periodo_to_id
		]);


		foreach ($unidades as $unidad) {

			$consulta = 'SELECT id, definicion, porcentaje, orden, "0" as cantNotas 
						FROM subunidades
						where unidad_id=:unidad_id and deleted_at is null
						order by orden';

			$unidad->subunidades = DB::select($consulta, [':unidad_id'	=> $unidad->id]);


		}
			
		$res->unidades		= $unidades;
			

		return (array)$res;
	}

	/**
	 * **Las unidades con dueño que acompañan a la lista que pidió el front.**
	 *
	 * Es la otra mitad de la §9.4: el bucle de arriba sabe respetar un dueño, pero
	 * la lista que le llega nunca trae ninguno, porque la pantalla de estructura del
	 * docente enseña la del grupo. Aquí salen las que faltan, del mismo par
	 * (asignatura, periodo) del que se está copiando.
	 *
	 * **Devuelve sólo las añadidas** —no la lista entera— porque el que llama tiene
	 * que poder contarlas aparte: son las de `unidades_de_independientes_copiadas`.
	 *
	 * **De dónde sale el origen: de las unidades pedidas, no de `periodo_from_id`.**
	 * Ese campo del cuerpo llega y `putCopiar` no lo usa para nada —el bucle va por
	 * id—, así que nadie garantiza que case con la lista; apoyarse en él sería
	 * estrenar una dependencia que hoy nadie comprueba. El par se lee de las filas.
	 *
	 * Con nadie marcado, `$independientes` viene vacío y esto devuelve la lista tal
	 * cual, sin tocar la base.
	 *
	 * @param  list<int>  $pedidas  las que llegaron en el cuerpo, ya normalizadas
	 * @param  list<int>  $independientes  del grupo destino y en el periodo destino
	 * @return list<int>  **sólo las que se añaden**, sin las pedidas
	 */
	private function unidadesConDuenoQueAcompanan(array $pedidas, array $independientes): array
	{
		if ($pedidas === [] || $independientes === []) {
			return [];
		}

		$origen = DB::select('SELECT DISTINCT asignatura_id, periodo_id FROM unidades
			WHERE id IN ('.implode(',', array_fill(0, count($pedidas), '?')).')
			  AND deleted_at IS NULL', $pedidas);

		if ($origen === []) {
			return [];
		}

		$valores = [];

		foreach ($origen as $par) {
			$valores[] = $par->asignatura_id;
			$valores[] = $par->periodo_id;
		}

		// `(asignatura_id, periodo_id) IN ((?,?), ...)` y no dos `IN` sueltos: con dos
		// listas cruzadas, pedir dos asignaturas de dos periodos se traería los cuatro
		// pares y copiaría unidades de un periodo que nadie nombró.
		$conDueno = DB::select('SELECT u.id FROM unidades u
			WHERE (u.asignatura_id, u.periodo_id) IN ('.implode(',', array_fill(0, count($origen), '(?,?)')).')
			  AND u.deleted_at IS NULL
			  AND u.alumno_id IN ('.implode(',', array_fill(0, count($independientes), '?')).')
			ORDER BY u.alumno_id, u.orden, u.id',
			array_merge($valores, $independientes));

		$ids = array_map(static fn ($fila) => (int) $fila->id, $conDueno);

		// Sin las que el front ya pidió: si mandó una con dueño a propósito, se copia
		// una sola vez **y cuenta como suya**, no como añadida por nosotros.
		return array_values(array_diff(array_unique($ids), $pedidas));
	}

	public function deleteDestroy($periodo_id)
	{
		$periodo = Periodo::findOrFail($periodo_id);
		$periodo->deleted_by 	= $this->user->user_id;
		$periodo->save();
		$periodo->delete();

		return $periodo;
	}

}
