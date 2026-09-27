<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Avisos;
use App\Services\Act\Formas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * APROBAR, RECHAZAR, CERRAR, COMPARTIR Y SUBIR EL ANONIMATO (tanda 3).
 *
 * Contrato: `myvc_front/ACTIVIDADES-CONTRATO.md` §2.3, §2.6, §2.10, §3.6 y §3.12.
 *
 * - Aprobar y rechazar son de los directivos (`Autoriza::puedeAprobarActividades`: superusuario o
 *   Admin, Secretario, Rector, Coord académico o disciplinario — la lista de las firmas). Nadie
 *   aprueba la suya; a un directivo tampoco le hace falta, porque la suya nunca queda por aprobar.
 * - Cerrar, compartir y el anonimato son del dueño.
 * - Compartir resultados es sólo de encuestas, y lo que se comparte nunca dice quién respondió qué
 *   (lo arma `ResultadosController::compartidos`, con k-anonimato siempre). Las preguntas de texto
 *   libre (`corta`, `parrafo`, `fecha`) nacen sin compartir; las de archivo no se comparten nunca.
 *
 * Los avisos (`aprobada`, `rechazada`, `resultados`) son de la tanda 5.
 */
class AprobarYCerrarController extends Controller
{
    use ResuelveElUsuario;

    private const NIVEL_DE_ANONIMATO = ['nombre' => 0, 'seguimiento' => 1, 'total' => 2];

    private const COMPARTE = ['no', 'respondieron', 'todos'];

    // ------------------------------------------------------------------ §3.6 aprobar

    /** `POST act/{id}/aprobar` → `{estado}`. Directivo; 409 si no está por aprobar. */
    public function postAprobar($id)
    {
        $user = $this->user;
        $act = $this->porAprobar($id, $user);
        $ahora = Actividad::ahora();

        DB::update(
            "UPDATE ws_actividades SET estado = 'publicada', aprobada_por = ?, aprobada_at = ?, rechazo_motivo = NULL, updated_at = ?
              WHERE id = ? AND estado = 'por_aprobar'",
            [(int) $user->user_id, $ahora, $ahora, (int) $act->id]
        );

        // Al creador, y la publicación a sus destinatarios (si no es programada: ésa la avisa el reloj).
        $act = Actividad::cargar($id);
        Avisos::alCreador($act, 'aprobada');
        Avisos::alPublicar($act);

        return ['estado' => Actividad::estado($act)];
    }

    /** `POST act/{id}/rechazar` `{motivo}` → `{estado: 'borrador'}`. Directivo; el motivo, obligatorio. */
    public function postRechazar($id)
    {
        $user = $this->user;
        $motivo = trim((string) Request::input('motivo', ''));

        if ($motivo === '') {
            abort(422, 'Escribe por qué la rechazas: el creador lo va a leer.');
        }

        if (mb_strlen($motivo) > 500) {
            abort(422, 'El motivo pasa de 500 caracteres.');
        }

        $act = $this->porAprobar($id, $user);

        DB::update(
            "UPDATE ws_actividades SET estado = 'borrador', rechazo_motivo = ?, aprobada_por = NULL, aprobada_at = NULL, updated_at = ?
              WHERE id = ? AND estado = 'por_aprobar'",
            [$motivo, Actividad::ahora(), (int) $act->id]
        );

        Avisos::alCreador($act, 'rechazada');

        return ['estado' => 'borrador'];
    }

    // ------------------------------------------------------------------ §3.12 cerrar y compartir

    /**
     * `POST act/{id}/cerrar` `{comparte_resultados?, preguntas_compartidas?}` → `ActEditable`.
     * Dueño; sólo una publicada que no se cerró a mano (la vencida por fecha también se cierra: así
     * queda `cerrada_at`). Sin `comparte_resultados` en el cuerpo vale lo que se eligió al crearla.
     */
    public function postCerrar($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $user);

        if ($act->estado !== 'publicada') {
            abort(409, $act->estado === 'cerrada' ? 'Ya está cerrada.' : 'Sólo se cierra una actividad publicada.');
        }

        [$comparte, $compartidas] = $this->compartirValido($act, Request::input('comparte_resultados', $act->comparte_resultados));
        $ahora = Actividad::ahora();

        DB::transaction(function () use ($act, $comparte, $compartidas, $ahora) {
            DB::update("UPDATE ws_actividades SET estado = 'cerrada', cerrada_at = ?, updated_at = ? WHERE id = ?",
                [$ahora, $ahora, (int) $act->id]);

            $this->guardarCompartir($act, $comparte, $compartidas, $ahora);
        });

        // Si comparte, el aviso `resultados` (una vez por actividad).
        Avisos::resultados(Actividad::cargar($id));

        return Formas::editable(Actividad::cargar($id));
    }

    /** `POST act/{id}/compartir`, mismo cuerpo, después de cerrada → `ActEditable`. Dueño. */
    public function postCompartir($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $user);

        if (Actividad::estado($act) !== 'cerrada') {
            abort(409, 'Los resultados se comparten cuando la actividad está cerrada.');
        }

        if (! Request::has('comparte_resultados')) {
            abort(422, 'Di con quién se comparten: no, respondieron o todos.');
        }

        [$comparte, $compartidas] = $this->compartirValido($act, Request::input('comparte_resultados'));

        DB::transaction(fn () => $this->guardarCompartir($act, $comparte, $compartidas, Actividad::ahora()));

        Avisos::resultados(Actividad::cargar($id));

        return Formas::editable(Actividad::cargar($id));
    }

    /**
     * `POST act/{id}/anonimato` `{anonimato}` → `ActEditable`. Dueño. Sólo sube (§2.6): bajarlo es
     * 422 en cuanto deja de ser borrador —quien respondió creyendo que no se le vería no puede
     * quedar con nombre—. Subirlo con respuestas sí se puede: la base no cambia, sólo lo que ve el
     * creador. Una pregunta de archivo no cabe en una anónima (§2.4).
     */
    public function postAnonimato($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $user);
        $nuevo = (string) Request::input('anonimato', '');

        if (! isset(self::NIVEL_DE_ANONIMATO[$nuevo])) {
            abort(422, 'El anonimato es nombre, seguimiento o total.');
        }

        if ($act->modo !== 'encuesta' && $nuevo !== 'nombre') {
            abort(422, 'Las tareas y los cuestionarios van con nombre: sólo una encuesta es anónima.');
        }

        if ($act->estado !== 'borrador' && self::NIVEL_DE_ANONIMATO[$nuevo] < self::NIVEL_DE_ANONIMATO[$act->anonimato]) {
            abort(422, 'El anonimato sólo sube: una encuesta anónima no vuelve a tener nombre.');
        }

        if ($nuevo !== 'nombre' && DB::selectOne(
            "SELECT 1 AS si FROM ws_preguntas WHERE actividad_id = ? AND tipo_pregunta = 'archivo' AND deleted_at IS NULL LIMIT 1",
            [$act->id]
        )) {
            abort(422, 'Tiene una pregunta de archivo, y un archivo lleva el nombre de quien lo subió: quítala para hacerla anónima.');
        }

        if ($nuevo !== $act->anonimato) {
            DB::update('UPDATE ws_actividades SET anonimato = ?, updated_at = ? WHERE id = ?', [$nuevo, Actividad::ahora(), (int) $act->id]);
        }

        return Formas::editable(Actividad::cargar($id));
    }

    // ------------------------------------------------------------------ piezas

    private function porAprobar($id, object $user): object
    {
        $act = Actividad::cargar($id);

        if (! Actividad::esDirectivo($user)) {
            abort(403, 'Sólo los directivos aprueban actividades.');
        }

        if (Actividad::esDueno($act, $user)) {
            abort(403, 'Nadie aprueba ni rechaza la suya.');
        }

        if ($act->estado !== 'por_aprobar') {
            abort(409, 'Esa actividad no está por aprobar.');
        }

        return $act;
    }

    /**
     * Valida `comparte_resultados` y `preguntas_compartidas`. Devuelve con quién se comparte y la
     * lista de preguntas marcadas, o null si el cuerpo no la trae (se quedan las marcas que había).
     *
     * @return array{0: string, 1: ?list<int>}
     */
    private function compartirValido(object $act, $comparte): array
    {
        $comparte = (string) ($comparte ?? 'no');

        if (! in_array($comparte, self::COMPARTE, true)) {
            abort(422, 'Los resultados se comparten con nadie (no), con quienes respondieron o con todos.');
        }

        if ($comparte !== 'no' && $act->modo !== 'encuesta') {
            abort(422, 'Sólo se comparten los resultados de una encuesta.');
        }

        if (! Request::has('preguntas_compartidas') || Request::input('preguntas_compartidas') === null) {
            return [$comparte, null];
        }

        $dadas = Request::input('preguntas_compartidas');

        if (! is_array($dadas)) {
            abort(422, 'Las preguntas compartidas van como una lista de ids.');
        }

        $ids = [];

        foreach ($dadas as $d) {
            if (! is_numeric($d)) {
                abort(422, 'Las preguntas compartidas van como una lista de ids.');
            }

            $ids[(int) $d] = true;
        }

        $ids = array_keys($ids);

        if ($ids !== []) {
            $suyas = DB::select(
                'SELECT id, tipo_pregunta FROM ws_preguntas WHERE actividad_id = ? AND deleted_at IS NULL AND id IN ('
                .implode(',', array_fill(0, count($ids), '?')).')',
                array_merge([(int) $act->id], $ids)
            );

            if (count($suyas) !== count($ids)) {
                abort(422, 'Alguna de esas preguntas no es de esta actividad.');
            }

            foreach ($suyas as $p) {
                if ($p->tipo_pregunta === 'archivo') {
                    abort(422, 'Una pregunta de archivo no se comparte: cada archivo lleva quién lo subió.');
                }
            }
        }

        return [$comparte, $ids];
    }

    private function guardarCompartir(object $act, string $comparte, ?array $compartidas, string $ahora): void
    {
        if ($compartidas !== null) {
            DB::update('UPDATE ws_preguntas SET compartir = 0, updated_at = ? WHERE actividad_id = ? AND deleted_at IS NULL',
                [$ahora, (int) $act->id]);

            if ($compartidas !== []) {
                DB::update('UPDATE ws_preguntas SET compartir = 1 WHERE actividad_id = ? AND id IN ('
                    .implode(',', array_fill(0, count($compartidas), '?')).')',
                    array_merge([(int) $act->id], $compartidas));
            }
        }

        // Desde cuándo se ven: se fija la primera vez que se comparte y se borra al dejar de hacerlo.
        DB::update(
            'UPDATE ws_actividades SET comparte_resultados = ?,
                    resultados_compartidos_at = CASE WHEN ? = \'no\' THEN NULL ELSE COALESCE(resultados_compartidos_at, ?) END,
                    updated_at = ?
              WHERE id = ?',
            [$comparte, $comparte, $ahora, $ahora, (int) $act->id]
        );
    }
}
