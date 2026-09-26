<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Avisos;
use App\Services\Act\Calificador;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Planilla;
use App\Services\Act\Recorrido;
use App\Support\EscalaDeNotas;
use App\Support\HtmlDelEditor;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * LAS ACTIVIDADES NUEVAS, LADO DE QUIEN CREA: bandeja, catálogo, conteo, crear, guardar, leer,
 * borrar y publicar.
 *
 * Contrato: `myvc_front/ACTIVIDADES-CONTRATO.md` §3.1–§3.5 (tanda 1). La propuesta —el porqué— es
 * `ACTIVIDADES-Y-ENCUESTAS.md`. Tareas, cuestionarios y encuestas en la misma `ws_actividades` que
 * el módulo viejo, distinguidas por `modo` (ver `App\Services\Act\Actividad`).
 *
 * La nota a la planilla (tanda 2, §2.8): una tarea o un cuestionario con `califica` elige el logro
 * (`unidad_id`) y, si el año reparte por porcentaje, el peso; al publicar se crea el indicador por
 * `App\Services\Act\Planilla`. `act/logros` da los logros con sus pesos para la barra de «Nueva».
 */
class ActividadesController extends Controller
{
    use ResuelveElUsuario;

    /** Los campos de `ConfigAct` que el cuerpo puede traer. */
    private const CAMPOS = ['modo', 'titulo', 'instrucciones', 'alcance', 'asignatura_id', 'grupo_id',
        'responden', 'acudiente_por_hijo', 'anonimato', 'destinatarios', 'publica_at', 'cierra_at',
        'recibir_tarde', 'entrega', 'califica', 'unidad_id', 'peso', 'nota_maxima', 'oportunidades',
        'mostrar_correctas', 'comparte_resultados', 'avisos'];

    /** Lo único que se puede cambiar con hojas enviadas (§2.9), además de subir el anonimato. */
    private const EDITABLES_CON_RESPUESTAS = ['titulo', 'instrucciones', 'publica_at', 'cierra_at'];

    private const NIVEL_DE_ANONIMATO = ['nombre' => 0, 'seguimiento' => 1, 'total' => 2];

    // ------------------------------------------------------------------ §3.1 bandeja

    /** `GET act/bandeja?vista=mias|responder|aprobar&modo=&year_id=` → `ActEnBandeja[]`. */
    public function getBandeja()
    {
        $user = $this->user;
        $vista = (string) Request::input('vista', 'responder');
        $modo = Request::input('modo');
        $yearId = Destinatarios::entero(Request::input('year_id')) ?? (int) $user->year_id;

        if ($modo !== null && $modo !== '' && ! in_array($modo, Actividad::MODOS, true)) {
            abort(422, 'Ese modo no existe.');
        }

        $modo = $modo === '' ? null : $modo;

        if ($vista === 'mias') {
            $this->exigirPersonal();

            $filas = DB::select(
                'SELECT * FROM ws_actividades
                  WHERE modo IS NOT NULL AND deleted_at IS NULL AND created_by = ? AND year_id = ?'
                .($modo ? ' AND modo = ?' : '').' ORDER BY id DESC',
                array_merge([(int) $user->user_id, $yearId], $modo ? [$modo] : [])
            );

            return array_map(fn ($a) => Formas::enBandeja($a, $user), $filas);
        }

        if ($vista === 'aprobar') {
            if (! Actividad::esDirectivo($user)) {
                abort(403, 'Sólo los directivos aprueban actividades.');
            }

            $filas = DB::select(
                "SELECT * FROM ws_actividades
                  WHERE modo IS NOT NULL AND deleted_at IS NULL AND estado = 'por_aprobar' AND year_id = ?"
                .($modo ? ' AND modo = ?' : '').' ORDER BY id',
                array_merge([$yearId], $modo ? [$modo] : [])
            );

            return array_map(fn ($a) => Formas::enBandeja($a, $user), $filas);
        }

        if ($vista !== 'responder') {
            abort(422, 'La vista es mias, responder o aprobar.');
        }

        $filas = [];

        foreach (Destinatarios::candidatasPara($user, $yearId) as $act) {
            if ($modo && $act->modo !== $modo) {
                continue;
            }

            // Nunca borrador, por aprobar ni programada; la cerrada sí, para ver lo respondido.
            if (! in_array(Actividad::estado($act), ['abierta', 'cerrada'], true)) {
                continue;
            }

            // Una fila por entrada: el acudiente «una vez por hijo» recibe una por hijo.
            foreach (Destinatarios::resolver($act, null, (int) $user->user_id) as $entrada) {
                $filas[] = Formas::enBandeja($act, $user, $entrada, \App\Services\Act\Respuestas::miEstado($act, $entrada));
            }
        }

        return $filas;
    }

    // ------------------------------------------------------------------ §3.2 catálogo y conteo

    /** `GET act/catalogo` → `CatalogoAct`: lo que el formulario de crear necesita tener a mano. */
    public function getCatalogo()
    {
        $user = $this->user;
        $yearId = (int) $user->year_id;
        $clases = [];

        // `asignaturas.profesor_id` es un `profesores.id`: sólo un usuario Profesor tiene clases.
        if (($user->tipo ?? '') === 'Profesor') {
            $clases = array_map(fn ($c) => [
                'asignatura_id' => (int) $c->asignatura_id,
                'materia' => (string) $c->materia,
                'grupo_id' => (int) $c->grupo_id,
                'grupo' => (string) $c->grupo,
                'grado_id' => (int) $c->grado_id,
            ], DB::select(
                'SELECT a.id AS asignatura_id, m.materia, g.id AS grupo_id, g.nombre AS grupo, g.grado_id
                   FROM asignaturas a
                  INNER JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
                  INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                  WHERE a.profesor_id = ? AND a.deleted_at IS NULL
                  ORDER BY g.orden, g.nombre, a.orden, m.materia',
                [$yearId, (int) $user->persona_id]
            ));
        }

        $grupos = DB::select(
            'SELECT id, nombre, grado_id FROM grupos WHERE year_id = ? AND deleted_at IS NULL ORDER BY orden, nombre',
            [$yearId]
        );

        $grados = DB::select(
            'SELECT DISTINCT gr.id, gr.nombre, gr.orden FROM grados gr
              INNER JOIN grupos g ON g.grado_id = gr.id AND g.year_id = ? AND g.deleted_at IS NULL
              WHERE gr.deleted_at IS NULL ORDER BY gr.orden, gr.nombre',
            [$yearId]
        );

        return [
            'clases' => $clases,
            'titularias' => Destinatarios::titularias($user, $yearId),
            'grupos' => array_map(fn ($g) => ['id' => (int) $g->id, 'nombre' => (string) $g->nombre, 'grado_id' => (int) $g->grado_id], $grupos),
            'grados' => array_map(fn ($g) => ['id' => (int) $g->id, 'nombre' => (string) $g->nombre], $grados),
            'periodo' => ['id' => (int) $user->periodo_id, 'numero' => (int) ($user->numero_periodo ?? 0)],
            'reparto' => RepartoDeLaNota::modoDelPeriodo($user->periodo_id),
            'escala' => [
                'minima' => EscalaDeNotas::minimo($yearId) ?? 0,
                'maxima' => Actividad::maximoDeLaEscala($yearId),
                'aprobatoria' => (int) ($user->nota_minima_aceptada ?? 0),
            ],
            'soy_directivo' => Actividad::esDirectivo($user),
        ];
    }

    /**
     * `GET act/logros?asignatura_id=&periodo_id=` → `LogroAct[]` (§3.2, tanda 2). Los logros de una
     * clase suya (o de cualquiera, si es directivo) en el periodo —por defecto el del token—, con sus
     * indicadores y lo que suman, para la barra de «Nota a la planilla».
     */
    public function getLogros()
    {
        $user = $this->user;
        $asignaturaId = Destinatarios::entero(Request::input('asignatura_id'));
        $periodoId = Destinatarios::entero(Request::input('periodo_id')) ?? (int) $user->periodo_id;

        if ($asignaturaId === null) {
            abort(422, 'Falta la clase.');
        }

        $clase = DB::selectOne(
            'SELECT a.id, a.profesor_id, g.year_id FROM asignaturas a
              INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$asignaturaId]
        );

        if (! $clase) {
            abort(404, 'Esa clase no existe.');
        }

        $esSuya = ($user->tipo ?? '') === 'Profesor' && (int) $clase->profesor_id === (int) $user->persona_id;

        if (! $esSuya && ! Actividad::esDirectivo($user)) {
            abort(403, 'Esa clase no es tuya.');
        }

        if (! DB::selectOne('SELECT id FROM periodos WHERE id = ? AND year_id = ? AND deleted_at IS NULL', [$periodoId, (int) $clase->year_id])) {
            abort(422, 'Ese periodo no es del año de la clase.');
        }

        return Planilla::logros($asignaturaId, $periodoId);
    }

    /** `POST act/conteo` → `ConteoAct`. No escribe nada: el front lo pide al mover el selector. */
    public function postConteo()
    {
        $user = $this->user;
        $modo = (string) Request::input('modo', 'encuesta');
        $alcance = (string) Request::input('alcance', '');
        $responden = (string) Request::input('responden', '');

        if (! in_array($alcance, Destinatarios::ALCANCES, true) || ! in_array($responden, Destinatarios::RESPONDEN, true)) {
            abort(422, 'Falta el alcance o quién responde.');
        }

        $filas = Destinatarios::normalizar($alcance, $responden, (array) Request::input('destinatarios', []), null, null);

        $act = (object) [
            'id' => 0,
            'year_id' => (int) $user->year_id,
            'acudiente_por_hijo' => (bool) Request::input('acudiente_por_hijo', true),
            'modo' => $modo,
            'responden' => $responden,
        ];

        return Destinatarios::conteo($act, $filas, $user);
    }

    // ------------------------------------------------------------------ §3.3 crear, guardar, leer, borrar

    /** `POST act/crear` → `ActEditable` en borrador. */
    public function postCrear()
    {
        $user = $this->user;
        $dada = $this->cuerpo();

        if (! in_array($dada['modo'] ?? null, Actividad::MODOS, true)) {
            abort(422, 'El modo es tarea, cuestionario o encuesta.');
        }

        $esEncuesta = $dada['modo'] === 'encuesta';
        $config = array_replace([
            'titulo' => '',
            'instrucciones' => null,
            'alcance' => $esEncuesta ? null : 'clase',
            'asignatura_id' => null,
            'grupo_id' => null,
            'responden' => $esEncuesta ? null : 'alumnos',
            'acudiente_por_hijo' => true,
            'anonimato' => 'nombre',
            'destinatarios' => [],
            'publica_at' => null,
            'cierra_at' => null,
            'recibir_tarde' => false,
            'entrega' => ['texto' => false, 'foto' => false, 'archivo' => false, 'enlace' => false],
            'califica' => false,
            'unidad_id' => null,
            'peso' => null,
            'nota_maxima' => null,
            'oportunidades' => 1,
            'mostrar_correctas' => 'al_cerrar',
            'comparte_resultados' => 'no',
            'avisos' => ['al_publicar' => true, 'recordar_horas_antes' => null, 'en_calendario' => true],
        ], $dada);

        $config['entrega'] = array_replace(['texto' => false, 'foto' => false, 'archivo' => false, 'enlace' => false], (array) $config['entrega']);
        $config['avisos'] = array_replace(['al_publicar' => true, 'recordar_horas_antes' => null, 'en_calendario' => true], (array) $config['avisos']);

        [$columnas, $filas] = $this->configValida($config, $user, (int) $user->year_id, (int) $user->periodo_id);
        $ahora = Actividad::ahora();

        $id = DB::transaction(function () use ($columnas, $filas, $user, $ahora) {
            $id = DB::table('ws_actividades')->insertGetId($columnas + [
                'estado' => 'borrador',
                'year_id' => (int) $user->year_id,
                'periodo_id' => (int) $user->periodo_id,
                'created_by' => (int) $user->user_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            Destinatarios::guardar($id, $filas);

            return $id;
        });

        return Formas::editable(Actividad::cargar($id));
    }

    /** `POST act/{id}/guardar` con la config parcial → `ActEditable`. Dueño; reglas de §2.9. */
    public function postGuardar($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $user);

        $antes = Formas::config($act);
        $dada = $this->cuerpo();

        if (isset($dada['modo']) && $dada['modo'] !== $act->modo) {
            abort(422, 'El modo de una actividad no se cambia: crea otra.');
        }

        $config = array_replace($antes, $dada);
        $config['entrega'] = array_replace($antes['entrega'], (array) ($dada['entrega'] ?? []));
        $config['avisos'] = array_replace($antes['avisos'], (array) ($dada['avisos'] ?? []));

        [$columnas, $filas] = $this->configValida($config, $user, (int) $act->year_id, (int) $act->periodo_id);

        $cambiaron = $this->camposQueCambian($act, $columnas, $filas);
        $estado = $act->estado;

        // Con hojas o entregas, sólo textos, fechas y subir el anonimato (§2.9).
        if ($cambiaron !== [] && Actividad::tieneRespuestas((int) $act->id)) {
            $prohibidos = array_diff($cambiaron, self::EDITABLES_CON_RESPUESTAS, ['anonimato']);

            if ($prohibidos !== []) {
                response()->json([
                    'mensaje' => 'Ya hay respuestas: sólo se pueden cambiar el título, las instrucciones, las fechas y subir el anonimato.',
                    'campos' => array_values($prohibidos),
                ], 409)->throwResponse();
            }
        }

        // La nota a la planilla se decide antes de publicar: el indicador ya se creó con ese logro
        // y ese peso, y moverlo dejaría la subunidad colgada de otro sitio.
        // (La nota máxima con hojas enviadas ya la corta el 409 de §2.9: va por «Editar con notas».)
        $deLaNota = array_intersect($cambiaron, ['califica', 'unidad_id', 'peso']);

        if ($deLaNota !== [] && in_array($act->estado, ['publicada', 'cerrada'], true)) {
            abort(409, 'La nota a la planilla (logro y peso) se decide antes de publicar.');
        }

        $aQuien = array_intersect($cambiaron, ['alcance', 'responden', 'destinatarios', 'acudiente_por_hijo', 'asignatura_id', 'grupo_id']);

        if ($aQuien !== []) {
            // Cambiar a quién va de una publicada saltaría la aprobación: se hace con una nueva.
            if (in_array($act->estado, ['publicada', 'cerrada'], true)) {
                abort(409, 'A quién va una actividad publicada no se cambia; duplícala o crea otra.');
            }

            // Por aprobar: vuelve a borrador, y al publicar se recalcula si hay que aprobarla.
            if ($act->estado === 'por_aprobar') {
                $estado = 'borrador';
            }
        }

        if (in_array('anonimato', $cambiaron, true)) {
            $this->exigirQueElAnonimatoSoloSuba($act, $columnas['anonimato']);

            // Una pregunta de archivo lleva quién lo subió: no cabe en una encuesta anónima (§2.4).
            if ($columnas['anonimato'] !== 'nombre' && DB::selectOne(
                "SELECT 1 AS si FROM ws_preguntas WHERE actividad_id = ? AND tipo_pregunta = 'archivo' AND deleted_at IS NULL LIMIT 1",
                [$act->id]
            )) {
                abort(422, 'Tiene una pregunta de archivo, y un archivo lleva el nombre de quien lo subió: quítala para hacerla anónima.');
            }
        }

        DB::transaction(function () use ($act, $columnas, $filas, $estado) {
            DB::table('ws_actividades')->where('id', $act->id)->update($columnas + [
                'estado' => $estado,
                'updated_at' => Actividad::ahora(),
            ]);

            Destinatarios::guardar((int) $act->id, $filas);
        });

        return Formas::editable(Actividad::cargar($id));
    }

    /** `GET act/{id}` → `ActEditable`. Dueño o directivo. */
    public function getLeer($id)
    {
        $act = Actividad::cargar($id);
        Actividad::exigirDuenoODirectivo($act, $this->user);

        return Formas::editable($act);
    }

    /** `POST act/{id}/borrar` → `{ok: true}`. Sólo borrador o por aprobar: lo publicado se cierra. */
    public function postBorrar($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $user);

        if (! in_array($act->estado, ['borrador', 'por_aprobar'], true)) {
            abort(409, 'Una actividad publicada no se borra: se cierra.');
        }

        DB::update('UPDATE ws_actividades SET deleted_at = ?, deleted_by = ? WHERE id = ?',
            [Actividad::ahora(), (int) $user->user_id, (int) $act->id]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ §3.5 publicar

    /**
     * `POST act/{id}/publicar` → `{estado, requiere_aprobacion, motivo_aprobacion, subunidad_id}`.
     *
     * 422 con la lista de problemas si no está lista; 409 si no está en borrador. Si la encuesta
     * necesita aprobación (§2.3) queda `por_aprobar` —aprobar es la tanda 3—; si no, `publicada`, y
     * desde `publica_at` (o ya) la ven sus destinatarios.
     */
    public function postPublicar($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $user);

        if ($act->estado !== 'borrador') {
            abort(409, 'Sólo se publica un borrador.');
        }

        $problemas = [];
        $preguntas = Formas::preguntas((int) $act->id);

        if (trim((string) $act->titulo) === '') {
            $problemas[] = 'Falta el título.';
        }

        if ($act->modo !== 'tarea' && $preguntas === []) {
            $problemas[] = 'No tiene preguntas.';
        }

        if ($act->modo === 'tarea' && $preguntas === []
            && ! ($act->entrega_texto || $act->entrega_foto || $act->entrega_archivo || $act->entrega_enlace)) {
            $problemas[] = 'La tarea no pide ninguna entrega ni tiene preguntas.';
        }

        if ($act->modo === 'cuestionario') {
            foreach ($preguntas as $p) {
                if (in_array($p['tipo'], Recorrido::DE_OPCIONES, true) && (int) $p['puntos'] > 0 && Calificador::correctas($p) === []) {
                    $problemas[] = 'La pregunta '.$p['orden'].' no tiene respuesta correcta.';
                }
            }
        }

        $ahora = Actividad::ahora();

        if ($act->cierra_at !== null && $act->cierra_at <= $ahora) {
            $problemas[] = 'La fecha de cierre ya pasó.';
        }

        if ($act->cierra_at !== null && $act->publica_at !== null && $act->cierra_at <= $act->publica_at) {
            $problemas[] = 'La fecha de cierre es anterior a la de publicación.';
        }

        $filas = Destinatarios::deActividad((int) $act->id);
        $conCuenta = array_filter(Destinatarios::resolver($act, $filas), fn ($e) => $e['user_id'] !== null);

        if ($conCuenta === []) {
            $problemas[] = 'No le llega a nadie: el alcance está vacío.';
        }

        $reparto = Planilla::reparto($act);

        if ($act->califica) {
            // Antes que los problemas: en un año cerrado no se crea el indicador, y eso es un 423,
            // no una lista de cosas que arreglar.
            Planilla::exigirAnioAbierto($act, $user);

            if ($act->unidad_id === null) {
                $problemas[] = 'Falta el logro al que va la nota.';
            } elseif (! Planilla::unidadDe($act)) {
                $problemas[] = 'El logro elegido ya no existe en esta clase y periodo.';
            }

            if ($reparto === RepartoDeLaNota::PORCENTAJE && $act->peso === null) {
                $problemas[] = 'Falta el peso del indicador.';
            }
        }

        if ($problemas !== []) {
            response()->json(['mensaje' => 'Todavía no se puede publicar.', 'problemas' => $problemas], 422)->throwResponse();
        }

        [$requiere, $motivo] = Destinatarios::requiereAprobacion($act, $filas, $user);

        // «Reajustar los demás» sólo con el botón: cambia pesos de indicadores que ya existen.
        $reajustar = Request::boolean('reajustar_demas');

        // El estado y el indicador en una transacción: publicada sin su subunidad no existe.
        $subunidadId = DB::transaction(function () use ($act, $user, $requiere, $ahora, $reajustar) {
            DB::update(
                'UPDATE ws_actividades SET estado = ?, requiere_aprobacion = ?, rechazo_motivo = NULL, updated_at = ? WHERE id = ?',
                [$requiere ? 'por_aprobar' : 'publicada', $requiere ? 1 : 0, $ahora, (int) $act->id]
            );

            if (! $act->califica || $requiere) {
                return null;
            }

            $ya = Planilla::subunidad((int) $act->id);

            return $ya ? (int) $ya->id : Planilla::crearIndicador($act, $user, $reajustar);
        });

        $act = Actividad::cargar($id);

        // Los avisos (tanda 5): a los directivos si queda por aprobar; si no, a sus destinatarios.
        $requiere ? Avisos::porAprobar($act) : Avisos::alPublicar($act);

        return [
            'estado' => Actividad::estado($act),
            'requiere_aprobacion' => $requiere,
            'motivo_aprobacion' => $motivo,
            'subunidad_id' => $subunidadId,
        ];
    }

    // ------------------------------------------------------------------ §3.14 duplicar (tanda 3)

    /**
     * `GET act/para-duplicar?q=&year_id=` → `ActEnBandeja[]`: las mías (de todos los años, o de
     * `year_id`), buscando `q` en el título. Las más nuevas primero, hasta 200.
     */
    public function getParaDuplicar()
    {
        $user = $this->user;
        $yearId = Destinatarios::entero(Request::input('year_id'));
        $q = trim((string) Request::input('q', ''));

        $sql = 'SELECT * FROM ws_actividades WHERE modo IS NOT NULL AND deleted_at IS NULL AND created_by = ?';
        $params = [(int) $user->user_id];

        if ($yearId !== null) {
            $sql .= ' AND year_id = ?';
            $params[] = $yearId;
        }

        if ($q !== '') {
            $sql .= ' AND titulo LIKE ?';
            $params[] = '%'.addcslashes($q, '%_\\').'%';
        }

        $filas = DB::select($sql.' ORDER BY id DESC LIMIT 200', $params);

        return array_map(fn ($a) => Formas::enBandeja($a, $user), $filas);
    }

    /**
     * `POST act/{id}/duplicar` `{destinos, titulo?, publica_at?, cierra_at?, copiar}` →
     * `{creadas: number[]}`.
     *
     * Una copia por destino, en borrador, en el año y el periodo del token (así se trae una de un año
     * pasado), con `duplicada_de`. **Nunca** hojas, respuestas, entregas, archivos, notas ni
     * subunidad: la copia crea su indicador al publicarse. Cada copia pasa por la misma validación
     * que `crear` (la clase tiene que ser tuya, la nota máxima cabe en la escala de este año…).
     *
     * `copiar` (cada parte, `true` si no viene): `preguntas` (con opciones y condiciones),
     * `configuracion` (intentos, correctas, nota a la planilla, anonimato, entrega, a quién
     * responde…), `instrucciones`, `avisos` y `destinatarios` (sólo encuestas sin destino; los
     * grupos y clases de otro año no se copian porque no existen en éste).
     *
     * Destino: tarea y cuestionario, `{asignatura_id, unidad_id?, peso?}` (una clase); encuesta,
     * `{asignatura_id}`, `{grupo_id}` o `{}` (el alcance de la original).
     */
    public function postDuplicar($id)
    {
        $user = $this->user;
        $origen = Actividad::cargar($id);

        if (! Actividad::esDueno($origen, $user) && ! Actividad::esDirectivo($user)) {
            abort(403, 'Sólo se duplican las tuyas (un directivo, cualquiera).');
        }

        $destinos = Request::input('destinos');

        if (! is_array($destinos) || $destinos === [] || count($destinos) > 60) {
            abort(422, 'Elige al menos un destino (hasta 60).');
        }

        $copiar = array_replace(
            ['preguntas' => true, 'configuracion' => true, 'instrucciones' => true, 'avisos' => true, 'destinatarios' => true],
            array_map(fn ($v) => (bool) $v, (array) Request::input('copiar', []))
        );

        $yearId = (int) $user->year_id;
        $periodoId = (int) $user->periodo_id;
        $mismoAnio = (int) $origen->year_id === $yearId;
        $base = Formas::config($origen);
        $maximo = Actividad::maximoDeLaEscala($yearId);
        $titulo = Request::has('titulo') && trim((string) Request::input('titulo')) !== ''
            ? (string) Request::input('titulo') : (string) $origen->titulo;

        // La config común a todas las copias; lo de cada destino se pone después.
        $comun = [
            'modo' => $origen->modo,
            'titulo' => $titulo,
            'instrucciones' => $copiar['instrucciones'] ? $origen->instrucciones : null,
            'alcance' => $base['alcance'],
            'asignatura_id' => null,
            'grupo_id' => null,
            'responden' => $base['responden'],
            'acudiente_por_hijo' => $base['acudiente_por_hijo'],
            'anonimato' => $copiar['configuracion'] ? $base['anonimato'] : 'nombre',
            'destinatarios' => [],
            'publica_at' => Request::input('publica_at'),
            'cierra_at' => Request::input('cierra_at'),
            'recibir_tarde' => $copiar['configuracion'] && $base['recibir_tarde'],
            'entrega' => $copiar['configuracion'] ? $base['entrega'] : ['texto' => false, 'foto' => false, 'archivo' => false, 'enlace' => false],
            'califica' => $copiar['configuracion'] && $base['califica'],
            'unidad_id' => null,
            'peso' => null,
            // Una nota máxima del año pasado que no cabe en la escala de éste se queda en el máximo.
            'nota_maxima' => $copiar['configuracion'] && $base['nota_maxima'] !== null ? min($maximo, (int) $base['nota_maxima']) : $maximo,
            'oportunidades' => $copiar['configuracion'] ? $base['oportunidades'] : 1,
            'mostrar_correctas' => $copiar['configuracion'] ? $base['mostrar_correctas'] : 'al_cerrar',
            'comparte_resultados' => $copiar['configuracion'] ? $base['comparte_resultados'] : 'no',
            'avisos' => $copiar['avisos'] ? $base['avisos'] : ['al_publicar' => true, 'recordar_horas_antes' => null, 'en_calendario' => true],
        ];

        $validadas = [];

        foreach (array_values($destinos) as $i => $d) {
            if (! is_array($d)) {
                abort(422, 'Cada destino es un objeto.');
            }

            $c = $comun;
            $asignaturaId = Destinatarios::entero($d['asignatura_id'] ?? null);
            $grupoId = Destinatarios::entero($d['grupo_id'] ?? null);

            if ($origen->modo !== 'encuesta') {
                if ($asignaturaId === null) {
                    abort(422, 'Las tareas y los cuestionarios se duplican a una clase: falta la clase del destino '.($i + 1).'.');
                }

                $c['asignatura_id'] = $asignaturaId;
                $c['unidad_id'] = $c['califica'] ? Destinatarios::entero($d['unidad_id'] ?? null) : null;
                $c['peso'] = $c['califica'] ? Destinatarios::entero($d['peso'] ?? null) : null;
            } elseif ($asignaturaId !== null) {
                $c['alcance'] = 'clase';
                $c['asignatura_id'] = $asignaturaId;

                if ($c['responden'] === 'personal') {
                    $c['responden'] = 'alumnos';
                }
            } elseif ($grupoId !== null) {
                $c['alcance'] = 'grupo';
                $c['grupo_id'] = $grupoId;

                if ($c['responden'] === 'personal') {
                    $c['responden'] = 'alumnos';
                }
            } else {
                $c['destinatarios'] = $copiar['destinatarios'] ? $this->destinatariosQueSirven($origen, $mismoAnio) : [];

                // Una clase o un grupo que no se copia (otro año, o sin destinatarios) deja el
                // alcance abierto a «grupos», para elegirlos en la copia.
                if (in_array($c['alcance'], ['clase', 'grupo'], true)) {
                    $sirve = $mismoAnio && $copiar['destinatarios'];
                    $c['asignatura_id'] = $sirve ? $base['asignatura_id'] : null;
                    $c['grupo_id'] = $sirve ? $base['grupo_id'] : null;

                    if (! $sirve) {
                        $c['alcance'] = 'grupos';
                        $c['destinatarios'] = [];
                    }
                }
            }

            $validadas[] = $this->configValida($c, $user, $yearId, $periodoId);
        }

        $ahora = Actividad::ahora();

        $creadas = DB::transaction(function () use ($origen, $validadas, $copiar, $user, $yearId, $periodoId, $ahora) {
            $creadas = [];

            foreach ($validadas as [$columnas, $filas]) {
                $nueva = DB::table('ws_actividades')->insertGetId($columnas + [
                    'estado' => 'borrador',
                    'year_id' => $yearId,
                    'periodo_id' => $periodoId,
                    'created_by' => (int) $user->user_id,
                    'duplicada_de' => (int) $origen->id,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                Destinatarios::guardar($nueva, $filas);

                if ($copiar['preguntas']) {
                    $this->copiarPreguntas((int) $origen->id, $nueva, $user, $ahora);
                }

                $creadas[] = $nueva;
            }

            return $creadas;
        });

        return ['creadas' => $creadas];
    }

    /**
     * Los destinatarios de la original que valen en el año del token: todo el colegio, el personal,
     * los grados (no son de un año) y personas sueltas siempre; grupos y clases sólo del mismo año.
     */
    private function destinatariosQueSirven(object $origen, bool $mismoAnio): array
    {
        return array_values(array_filter(
            Destinatarios::deActividad((int) $origen->id),
            fn ($f) => $mismoAnio || ($f['grupo_id'] === null && $f['asignatura_id'] === null)
        ));
    }

    /** Copia preguntas, opciones y condiciones, con las condiciones apuntando a las copias. */
    private function copiarPreguntas(int $origenId, int $nuevaId, object $user, string $ahora): void
    {
        $preguntas = [];
        $opciones = [];

        foreach (DB::select('SELECT * FROM ws_preguntas WHERE actividad_id = ? AND deleted_at IS NULL ORDER BY orden, id', [$origenId]) as $p) {
            $fila = (array) $p;
            $viejo = (int) $fila['id'];
            unset($fila['id']);
            $fila['actividad_id'] = $nuevaId;
            $fila['added_by'] = (int) $user->user_id;
            $fila['created_at'] = $ahora;
            $fila['updated_at'] = $ahora;
            $preguntas[$viejo] = DB::table('ws_preguntas')->insertGetId($fila);

            foreach (DB::select('SELECT * FROM ws_opciones WHERE pregunta_id = ? ORDER BY orden, id', [$viejo]) as $o) {
                $o = (array) $o;
                $vieja = (int) $o['id'];
                unset($o['id']);
                $o['pregunta_id'] = $preguntas[$viejo];
                $o['created_at'] = $ahora;
                $o['updated_at'] = $ahora;
                $opciones[$vieja] = DB::table('ws_opciones')->insertGetId($o);
            }
        }

        if ($preguntas === []) {
            return;
        }

        foreach (DB::select('SELECT * FROM ws_condiciones WHERE pregunta_id IN ('
            .implode(',', array_fill(0, count($preguntas), '?')).') ORDER BY id', array_keys($preguntas)) as $c) {
            $c = (array) $c;

            // Una condición que apunta a una pregunta u opción borrada no se copia.
            if (! isset($preguntas[(int) $c['depende_de_id']]) || ($c['opcion_id'] !== null && ! isset($opciones[(int) $c['opcion_id']]))) {
                continue;
            }

            unset($c['id']);
            $c['pregunta_id'] = $preguntas[(int) $c['pregunta_id']];
            $c['depende_de_id'] = $preguntas[(int) $c['depende_de_id']];
            $c['opcion_id'] = $c['opcion_id'] === null ? null : $opciones[(int) $c['opcion_id']];
            $c['created_at'] = $ahora;
            $c['updated_at'] = $ahora;
            DB::table('ws_condiciones')->insert($c);
        }
    }

    // ------------------------------------------------------------------ la config

    /** El cuerpo, sólo con los campos de `ConfigAct`. */
    private function cuerpo(): array
    {
        return array_intersect_key(Request::all(), array_flip(self::CAMPOS));
    }

    /**
     * Valida una config entera y la convierte en columnas de `ws_actividades` y filas de
     * destinatarios. Las reglas de quién crea qué son las de §2.2.
     *
     * @return array{0: array<string, mixed>, 1: list<array>}
     */
    private function configValida(array $c, object $user, int $yearId, int $periodoId): array
    {
        $modo = $c['modo'];
        $alcance = (string) ($c['alcance'] ?? '');
        $responden = (string) ($c['responden'] ?? '');
        $anonimato = (string) ($c['anonimato'] ?? 'nombre');

        if (! in_array($alcance, Destinatarios::ALCANCES, true)) {
            abort(422, 'El alcance es clase, grupo, grupos, colegio o personal.');
        }

        if (! in_array($responden, Destinatarios::RESPONDEN, true)) {
            abort(422, 'Responden alumnos, acudientes, ambos o personal.');
        }

        if (! isset(self::NIVEL_DE_ANONIMATO[$anonimato])) {
            abort(422, 'El anonimato es nombre, seguimiento o total.');
        }

        if ($modo !== 'encuesta' && ($alcance !== 'clase' || $responden !== 'alumnos' || $anonimato !== 'nombre')) {
            abort(422, 'Las tareas y los cuestionarios son para una clase: los responden sus alumnos, con nombre.');
        }

        $califica = ! empty($c['califica']);

        if ($califica && $modo === 'encuesta') {
            abort(422, 'Una encuesta no lleva nota a la planilla.');
        }

        $titulo = trim((string) ($c['titulo'] ?? ''));

        if (mb_strlen($titulo) > 160) {
            abort(422, 'El título pasa de 160 caracteres.');
        }

        $asignaturaId = null;
        $grupoId = null;

        if ($alcance === 'clase') {
            $asignaturaId = Destinatarios::entero($c['asignatura_id'] ?? null);

            foreach ((array) ($c['destinatarios'] ?? []) as $d) {
                $asignaturaId ??= Destinatarios::entero(is_array($d) ? ($d['asignatura_id'] ?? null) : null);
            }

            if ($asignaturaId === null) {
                abort(422, 'Falta la clase.');
            }

            $clase = DB::selectOne(
                'SELECT a.id, a.grupo_id, a.profesor_id FROM asignaturas a
                  INNER JOIN grupos g ON g.id = a.grupo_id AND g.year_id = ? AND g.deleted_at IS NULL
                  WHERE a.id = ? AND a.deleted_at IS NULL',
                [$yearId, $asignaturaId]
            );

            if (! $clase) {
                abort(422, 'Esa clase no existe en este año.');
            }

            $esSuya = ($user->tipo ?? '') === 'Profesor' && (int) $clase->profesor_id === (int) $user->persona_id;

            if (! $esSuya && ! Actividad::esDirectivo($user)) {
                abort(403, 'Esa clase no es tuya.');
            }

            $grupoId = (int) $clase->grupo_id;
        } elseif ($alcance === 'grupo') {
            $grupoId = Destinatarios::entero($c['grupo_id'] ?? null);

            foreach ((array) ($c['destinatarios'] ?? []) as $d) {
                $grupoId ??= Destinatarios::entero(is_array($d) ? ($d['grupo_id'] ?? null) : null);
            }

            if ($grupoId === null
                || ! DB::selectOne('SELECT id FROM grupos WHERE id = ? AND year_id = ? AND deleted_at IS NULL', [$grupoId, $yearId])) {
                abort(422, 'Falta el grupo, o no es de este año.');
            }
        }

        $filas = Destinatarios::normalizar($alcance, $responden, (array) ($c['destinatarios'] ?? []), $asignaturaId, $grupoId);

        $maximo = Actividad::maximoDeLaEscala($yearId);
        $notaMaxima = Destinatarios::entero($c['nota_maxima'] ?? null) ?? $maximo;

        if ($notaMaxima < 1 || $notaMaxima > $maximo) {
            abort(422, "La nota máxima va de 1 a {$maximo}, la escala del colegio.");
        }

        $oportunidades = Destinatarios::entero($c['oportunidades'] ?? null) ?? 1;

        if ($oportunidades < 1 || $oportunidades > 5) {
            abort(422, 'Los intentos van de 1 a 5.');
        }

        $mostrar = (string) ($c['mostrar_correctas'] ?? 'al_cerrar');
        $comparte = (string) ($c['comparte_resultados'] ?? 'no');

        if (! in_array($mostrar, ['nunca', 'al_enviar', 'al_cerrar'], true)) {
            abort(422, 'Las correctas se muestran nunca, al enviar o al cerrar.');
        }

        if (! in_array($comparte, ['no', 'respondieron', 'todos'], true)) {
            abort(422, 'Los resultados se comparten con nadie, con quienes respondieron o con todos.');
        }

        $publica = $this->fechaHora($c['publica_at'] ?? null, 'publicación');
        $cierra = $this->fechaHora($c['cierra_at'] ?? null, 'cierre');
        $avisos = (array) ($c['avisos'] ?? []);
        $recordar = Destinatarios::entero($avisos['recordar_horas_antes'] ?? null);

        if ($recordar !== null && ($recordar < 1 || $recordar > 720)) {
            abort(422, 'El recordatorio va de 1 a 720 horas antes del cierre.');
        }

        $entrega = (array) ($c['entrega'] ?? []);
        $esTarea = $modo === 'tarea';

        // La nota a la planilla (§2.8). Al guardar se admite a medias —el front va rellenando— y lo
        // que falta lo lista `publicar`; lo que llega sí tiene que ser válido.
        $unidadId = $califica ? Destinatarios::entero($c['unidad_id'] ?? null) : null;
        $peso = null;

        if ($unidadId !== null && ! DB::selectOne(
            'SELECT id FROM unidades WHERE id = ? AND asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [$unidadId, (int) $asignaturaId, $periodoId]
        )) {
            abort(422, 'Ese logro no es de esta clase en este periodo.');
        }

        if ($califica && RepartoDeLaNota::modoDelPeriodo($periodoId) === RepartoDeLaNota::PORCENTAJE) {
            $peso = Destinatarios::entero($c['peso'] ?? null);

            if ($peso !== null && ($peso < 1 || $peso > 100)) {
                abort(422, 'El peso del indicador va de 1 a 100.');
            }
        }

        return [[
            'modo' => $modo,
            'titulo' => $titulo,
            'instrucciones' => HtmlDelEditor::limpiar(isset($c['instrucciones']) ? (string) $c['instrucciones'] : null),
            'alcance' => $alcance,
            'asignatura_id' => $asignaturaId,
            'grupo_id' => $grupoId,
            'responden' => $responden,
            'acudiente_por_hijo' => ! empty($c['acudiente_por_hijo']) ? 1 : 0,
            'anonimato' => $anonimato,
            'publica_at' => $publica,
            'cierra_at' => $cierra,
            'recibir_tarde' => $esTarea && ! empty($c['recibir_tarde']) ? 1 : 0,
            'entrega_texto' => $esTarea && ! empty($entrega['texto']) ? 1 : 0,
            'entrega_foto' => $esTarea && ! empty($entrega['foto']) ? 1 : 0,
            'entrega_archivo' => $esTarea && ! empty($entrega['archivo']) ? 1 : 0,
            'entrega_enlace' => $esTarea && ! empty($entrega['enlace']) ? 1 : 0,
            'califica' => $califica ? 1 : 0,
            'unidad_id' => $unidadId,
            'peso' => $peso,
            'nota_maxima' => $notaMaxima,
            'oportunidades' => $modo === 'cuestionario' ? $oportunidades : 1,
            'mostrar_correctas' => $mostrar,
            'comparte_resultados' => $comparte,
            'avisar_al_publicar' => array_key_exists('al_publicar', $avisos) && ! $avisos['al_publicar'] ? 0 : 1,
            'recordar_horas_antes' => $recordar,
            'en_calendario' => array_key_exists('en_calendario', $avisos) && ! $avisos['en_calendario'] ? 0 : 1,
        ], $filas];
    }

    /** `AAAA-MM-DD HH:MM:SS` (o sin segundos, o con la `T` de ISO); NULL si viene vacío. */
    private function fechaHora($valor, string $cual): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = str_replace('T', ' ', substr((string) $valor, 0, 19));

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $formato) {
            $d = \DateTime::createFromFormat('!'.$formato, $texto);

            if ($d && $d->format($formato) === $texto) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        abort(422, "La fecha de {$cual} tiene que ir como AAAA-MM-DD HH:MM:SS.");
    }

    /** @return list<string> los campos de la config que cambian (con los nombres de `ConfigAct`) */
    private function camposQueCambian(object $act, array $columnas, array $filas): array
    {
        $cambian = [];
        $nombres = [
            'entrega_texto' => 'entrega', 'entrega_foto' => 'entrega', 'entrega_archivo' => 'entrega',
            'entrega_enlace' => 'entrega', 'avisar_al_publicar' => 'avisos', 'recordar_horas_antes' => 'avisos',
            'en_calendario' => 'avisos',
        ];

        foreach ($columnas as $col => $nuevo) {
            $viejo = $act->$col;

            if ((string) $viejo !== (string) $nuevo || ($viejo === null) !== ($nuevo === null)) {
                $cambian[$nombres[$col] ?? $col] = true;
            }
        }

        if (Destinatarios::deActividad((int) $act->id) != $filas) {
            $cambian['destinatarios'] = true;
        }

        return array_keys($cambian);
    }

    /**
     * El anonimato sólo sube (§2.6), y sólo una vez publicada importa: en borrador se elige libre.
     * Bajarlo le enseñaría al creador los nombres de quien respondió creyendo que no se verían.
     *
     * Subirlo con respuestas ya dadas sí se puede: desde el 26 sep el anonimato es de presentación
     * (la base guarda siempre quién respondió), así que subir sólo esconde más. El contrato lo
     * cortaba con 409 porque entonces las hojas viejas se quedaban firmadas en la base.
     */
    private function exigirQueElAnonimatoSoloSuba(object $act, string $nuevo): void
    {
        if ($act->estado === 'borrador') {
            return;
        }

        if (self::NIVEL_DE_ANONIMATO[$nuevo] < self::NIVEL_DE_ANONIMATO[$act->anonimato]) {
            abort(422, 'El anonimato sólo sube: una encuesta anónima no vuelve a tener nombre.');
        }
    }

    private function exigirPersonal(): void
    {
        if (in_array($this->user->tipo ?? '', ['Alumno', 'Acudiente'], true)) {
            abort(403, 'No tienes permiso');
        }
    }
}
