<?php namespace App\Http\Controllers\Actividades;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;

use App\User;
use App\Models\WsActividad;
use App\Models\WsRespuesta;
use App\Models\WsActividadResuelta;
use Carbon\Carbon;


class MisActividadesController extends Controller {


	public function putDatos()
	{
		$user = User::fromToken();

		$datos 				= [];
		$mis_asignaturas 	= [];
		$alumno_id 			= Request::input('alumno_id');

		if (!$alumno_id) {
			$alumno_id = $user->persona_id;
		}

		$consulta = 'SELECT a.id as asignatura_id, a.grupo_id, a.profesor_id, a.creditos, a.orden,
						m.materia, m.alias as alias_materia, 
						p.id as profesor_id, p.nombres as nombres_profesor, p.apellidos as apellidos_profesor,
						p.foto_id, IFNULL(i.nombre, IF(p.sexo="F","default_female.png", "default_male.png")) as foto_nombre
					FROM asignaturas a 
					inner join materias m on m.id=a.materia_id and m.deleted_at is null
					inner join profesores p on p.id=a.profesor_id and p.deleted_at is null 
					inner join grupos g on g.id=a.grupo_id and g.year_id=? and g.deleted_at is null 
					inner join matriculas mt on mt.grupo_id=a.grupo_id and mt.deleted_at is null 
					left join images i on p.foto_id=i.id and i.deleted_at is null
					where mt.alumno_id=? and a.deleted_at is null
					order by a.orden, m.orden';

		$mis_asignaturas = DB::select($consulta, [$user->year_id, $alumno_id]);

		$cant = count($mis_asignaturas);

		for ($i=0; $i < $cant; $i++) { 
			
			$consulta 			= 'SELECT * FROM ws_actividades a WHERE a.asignatura_id=? and a.deleted_at is null and a.periodo_id=?';
			$actividades 		= DB::select($consulta, [ $mis_asignaturas[$i]->asignatura_id, $user->periodo_id ]);
			$mis_asignaturas[$i]->actividades = $actividades;

		}


		$datos['mis_asignaturas'] = $mis_asignaturas;

		return $datos;

	}

	public function putMiActividad()
	{
		$user 	= User::fromToken();

		$actividad_id 	= Request::input('actividad_id');
		$datos 	= [];

		$this->exigirQueLaActividadLeCorresponda($user, $actividad_id);

		$res = WsActividadResuelta::where('actividad_id', $actividad_id)->where('persona_id', $user->persona_id)->first();
		if (!$res) {
			$res = new WsActividadResuelta();
			$res->actividad_id 		= $actividad_id;
			$res->persona_id 		= $user->persona_id;
			$res->timeout 			= 0;
			$res->save();
		}
		$actividad = WsActividad::datosActividadConRespuestas($actividad_id, $res->id);

		$datos['actividad'] = $actividad;
		$datos['actividad_resuelta'] 		= $res;
		
		return $datos;
	}

	public function putGuardar()
	{
		$user 	= User::fromToken();

		$act = WsActividad::findOrFail(Request::input('id'));

		$act->descripcion	=	Request::input('descripcion');
		$act->compartida	=	Request::input('compartida');
		$act->can_upload	=	Request::input('can_upload');
		$act->tipo			=	Request::input('tipo');
		$act->in_action		=	Request::input('in_action');
		$act->duracion_preg	=	Request::input('duracion_preg');
		$act->duracion_exam	=	Request::input('duracion_exam');
		$act->oportunidades	=	Request::input('oportunidades');
		$act->one_by_one	=	Request::input('one_by_one');
		$act->puntaje_por_promedio	=	Request::input('puntaje_por_promedio');
		$act->contenido		=	Request::input('contenido');
		$act->inicia_at		=	Request::input('inicia_at_str');
		$act->finaliza_at	=	Request::input('finaliza_at_str');
		$act->save();

		return $act;
	}

	public function putSeleccionarOpcion()
	{
		$user 	= User::fromToken();

		$actividad_resuelta_id 	= Request::input('actividad_resuelta_id');
		$pregunta_id 			= Request::input('pregunta_id');

		$this->exigirQueLaResueltaSeaSuya($user, $actividad_resuelta_id);
		$this->exigirQueElIntentoSigaAbierto($actividad_resuelta_id);

		$consulta = 'DELETE FROM ws_respuestas WHERE actividad_resuelta_id=? AND pregunta_id=?';
		DB::delete($consulta, [$actividad_resuelta_id, $pregunta_id]);

		$res 						= new WsRespuesta;
		$res->actividad_resuelta_id = $actividad_resuelta_id;
		$res->pregunta_id 			= $pregunta_id;
		//$res->tiempo 				= Request::input('tiempo');
		$res->tipo_pregunta 		= Request::input('tipo_pregunta');
		$res->opcion_id 			= Request::input('opcion_id');
		$res->opcion_cuadricula_id 	= Request::input('opcion_cuadricula_id');
		$res->save();

		return $res;
	}

	/**
	 * Que la actividad que se abre sea de un grupo de quien la abre.
	 *
	 * `putMiActividad()` recibía un `actividad_id` y **no miraba nada**: ni de qué
	 * asignatura es, ni si está compartida con el grupo de quien pide, ni si el
	 * profesor la ha soltado ya. Medido con un token de alumno contra una
	 * actividad de otro grupo: **200 con el examen entero** —enunciados y opciones—
	 * teniendo `para_alumnos = 0` e `in_action = 0`, o sea un examen que el profesor
	 * todavía no ha abierto a nadie. Ver 05 §43.
	 *
	 * Y no solo leía: la primera línea del método **crea el intento**, así que
	 * abrir el examen de otro grupo dejaba una fila en `ws_actividades_resueltas`
	 * a nombre del que miraba, que es lo que después sale en la pantalla de
	 * corregir de ese profesor (`respuestas/actividad`). Por eso la comprobación
	 * va delante de la creación y no después.
	 *
	 * **La ruta no puede llevar guard**, y es lo que la había dejado sin ninguno:
	 * `panel.mi_actividad` tiene dos entradas en el front y son de bandos
	 * distintos —`misActividades.html`, que es la lista del alumno, y
	 * `actividades.html`, que es la del profesor—, así que `auth.personal`
	 * apagaría la pantalla del alumno. Es la forma de la §20: el identificador
	 * nombra una actividad, no una persona, y no hay guard que sepa mirarla.
	 *
	 * Al personal no se le toca: abre cualquiera, como hoy. Lo que se cierra es
	 * la familia, con la regla de siempre —un alumno solo lo suyo; un acudiente,
	 * lo de sus acudidos—, y «lo suyo» son las dos formas en que una actividad
	 * llega a un grupo: ser de una asignatura de ese grupo, o estar compartida
	 * con él en `ws_actividades_compartidas`. Comprobar solo la primera apagaría
	 * el compartir entre grupos, que es una función viva.
	 *
	 * Y desde el 21 ago 2026 comprueba además **que el profesor la haya soltado**:
	 * `in_action` e `inicia_at`. Lo decidió Joseth con las dos listas delante, y no
	 * es un arreglo de autorización sino una regla de procedimiento que hasta hoy
	 * no existía — ver 05 §43.1. Se aplica **solo a la familia**: el profesor
	 * necesita abrirla antes que nadie, que es lo que es la vista previa.
	 *
	 * `oportunidades` y `para_alumnos` siguen sin comprobarse, eso sí, y siguen en
	 * la tabla del §5 de 09-pendientes: limitar los intentos es la que más puede
	 * sorprender a un colegio a mitad de periodo y se dejó fuera a sabiendas.
	 */
	private function exigirQueLaActividadLeCorresponda($user, $actividad_id): void
	{
		$actividad = DB::selectOne(
			'SELECT id, asignatura_id, in_action, inicia_at FROM ws_actividades
			 WHERE id = ? AND deleted_at IS NULL',
			[$actividad_id]
		);

		// Antes esto no era 404 sino 500: `datosActividadConRespuestas()` indexa
		// con [0] el resultado de la consulta y con un id que no existe revienta
		// —con el intento ya creado—.
		if (! $actividad) {
			abort(404, 'Esa actividad no existe');
		}

		$tipo = $user->tipo ?? '';

		if ($tipo !== 'Alumno' && $tipo !== 'Acudiente') {
			return;
		}

		$alumnos = $tipo === 'Alumno'
			? [(int) $user->persona_id]
			: $this->acudidosDe((int) $user->persona_id);

		if ($alumnos === []) {
			abort(403, 'Esa actividad no es de tu grupo');
		}

		$huecos = implode(',', array_fill(0, count($alumnos), '?'));

		$suya = DB::selectOne(
			'SELECT 1 AS si FROM matriculas mt
			 INNER JOIN grupos g ON g.id = mt.grupo_id AND g.deleted_at IS NULL AND g.year_id = ?
			 WHERE mt.alumno_id IN ('.$huecos.') AND mt.deleted_at IS NULL
			   AND (
			        mt.grupo_id = (SELECT grupo_id FROM asignaturas WHERE id = ?)
			        OR EXISTS (SELECT 1 FROM ws_actividades_compartidas ac
			                   WHERE ac.actividad_id = ? AND ac.grupo_id = mt.grupo_id)
			   )
			 LIMIT 1',
			array_merge([$user->year_id], $alumnos, [$actividad->asignatura_id, $actividad->id])
		);

		if (! $suya) {
			abort(403, 'Esa actividad no es de tu grupo');
		}

		// El interruptor con el que el profesor la abre. Va DESPUÉS del de grupo a
		// propósito: quien no es del grupo no tiene por qué enterarse de si hay un
		// examen ahí ni de cuándo empieza.
		if (! $actividad->in_action) {
			abort(403, 'Esta actividad todavía no está abierta');
		}

		// `inicia_at` se compara en hora de Colombia porque es la zona en la que la
		// escribe el profesor —`config/app.php` dice UTC y el código de siempre
		// llama a `Carbon::now('America/Bogota')`. Mientras las dos zonas convivan
		// (09 §2), comparar una fecha de esta tabla con `now()` a secas la adelanta
		// cinco horas.
		if ($actividad->inicia_at && Carbon::now('America/Bogota')->lt(Carbon::parse($actividad->inicia_at))) {
			abort(403, 'Esta actividad todavía no ha empezado');
		}
	}

	/**
	 * Entregado es entregado.
	 *
	 * `finalizar-actividad` ponía `terminado = true` y **nadie volvía a mirar esa
	 * columna**, así que `seleccionar-opcion` seguía borrando la respuesta anterior
	 * y escribiendo la nueva: el profesor corregía lo último que se escribió, no lo
	 * que había al entregar. Decidido por Joseth el 21 ago 2026 — ver 05 §43.1.
	 *
	 * **La consecuencia que hay que tener escrita**: quien entregue sin querer se
	 * queda fuera, y hoy no hay ninguna ruta que reabra un intento. Se eligió a
	 * sabiendas; si aparece la necesidad, el sitio es una ruta nueva del profesor y
	 * no relajar esto.
	 */
	private function exigirQueElIntentoSigaAbierto($actividad_resuelta_id): void
	{
		$terminado = WsActividadResuelta::where('id', $actividad_resuelta_id)->value('terminado');

		if ($terminado) {
			abort(403, 'Ya entregaste esta actividad');
		}
	}

	/** Los alumnos de un acudiente, como los resuelve `ExigirPersonaPropia`. */
	private function acudidosDe(int $acudiente_id): array
	{
		$filas = DB::select(
			'SELECT alumno_id FROM parentescos WHERE acudiente_id = ? AND deleted_at IS NULL',
			[$acudiente_id]
		);

		return array_map(fn ($f) => (int) $f->alumno_id, $filas);
	}

	/**
	 * Que la actividad resuelta sea de quien la está tocando.
	 *
	 * Estas dos rutas no pueden llevar `auth.personal` —responder y terminar un
	 * examen es justo lo que hace un alumno— y `persona.propia` tampoco sirve: el
	 * identificador que viaja es `actividad_resuelta_id`, que no nombra a una
	 * persona sino a un intento, y el guard recoge los identificadores por su
	 * nombre. O sea que la comprobación tiene que estar aquí.
	 *
	 * Sin ella, medido antes de escribirla: un alumno **cambiaba la respuesta del
	 * examen de otro** —`seleccionar-opcion` borra la respuesta anterior y escribe
	 * la suya— y **daba por terminado el examen de otro** en mitad de la prueba.
	 * Ver 05 §20.
	 *
	 * Se compara contra `persona_id`, que es lo que guarda la tabla y lo que ya
	 * usa `putMiActividad()` para buscarla. Un acudiente no tiene intentos, así
	 * que esto le cierra las dos — y es lo correcto: ver el examen de su acudido
	 * es una cosa y responderlo por él es otra.
	 */
	private function exigirQueLaResueltaSeaSuya($user, $actividad_resuelta_id): void
	{
		$suya = WsActividadResuelta::where('id', $actividad_resuelta_id)
			->where('persona_id', $user->persona_id)
			->exists();

		if (! $suya) {
			abort(403, 'Solo puedes responder tu actividad');
		}
	}


	public function putFinalizarActividad()
	{
		$user 	= User::fromToken();

		$actividad_resuelta_id 	= Request::input('actividad_resuelta_id');

		$this->exigirQueLaResueltaSeaSuya($user, $actividad_resuelta_id);

		$res 						= WsActividadResuelta::findOrFail($actividad_resuelta_id);
		$res->terminado 			= true;
		$res->save();

		return 'Terminada';
	}

}