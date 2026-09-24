<?php namespace App\Http\Controllers\Perfiles;

use App\Http\Controllers\Controller;
use App\Services\Auditoria;
use App\Support\Autoriza;
use App\Support\Reloj;
use App\Support\SafeUpload;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * La firma del titular, PEDIDA y no puesta.
 *
 * Pedido de Joseth, 24 sep 2026: *«que el titular pueda subir su firma, pero que
 * quede como solicitud para ser aprobada por secretario, rector, coordinador, obvio
 * también administradores»*.
 *
 * ## Por qué `change_asked` y no una tabla nueva
 *
 * El mecanismo de «pedir un cambio que otro aprueba» ya existe, y ya tenía la firma
 * prevista: `change_asked_data.firma_id_new` / `firma_id_accepted` están en el
 * esquema desde siempre y **nadie los escribía** (cero filas en el docker). Y
 * `change_asked` trae lo que hace falta para el estado: `answered_by`,
 * `accepted_at`, `rechazado_at` y `comentario_respuesta` para el motivo. **Cero
 * migraciones.**
 *
 * ## Una fila POR FIRMA, no el «pedido del año» del resto
 *
 * Lo demás de `change_asked` junta todos los cambios de una persona en UNA fila por
 * año (`ChangeAsked::verificar_pedido_actual`), y al resolverlos todos la borra.
 * Para la firma eso no vale por dos razones: la aprueba otra gente (no sólo el
 * superusuario) y el titular tiene que poder leer después **qué se decidió y por
 * qué**. Así que cada firma va en su fila, que al resolverse se queda viva con
 * `answered_by` puesto. Para que el pedido del año no se cuele en ella,
 * `verificar_pedido_actual` y `todas_solicitudes_de_profesores` excluyen las filas
 * con `firma_id_new`.
 *
 * ## La pendiente no se imprime, y no porque nadie la filtre
 *
 * Todo lo que imprime la firma del titular la lee de `profesores.firma_id`
 * (`Grupo::datos`, `Year::datos`, PIAR). La pendiente vive sólo en
 * `change_asked_data.firma_id_new`, y a `profesores` llega únicamente al aprobar.
 */
class FirmasDelTitularController extends Controller
{
    /** Formatos que se aceptan: los que un boletín pinta sin sorpresas. */
    private const EXTENSIONES = ['png', 'jpg', 'jpeg'];

    /**
     * GET firmas-del-titular/mia — la firma vigente, la última solicitud y si puede pedir.
     */
    public function getMia()
    {
        $user = User::fromToken();

        $vigente = null;
        $grupos = [];

        if ($user->tipo === 'Profesor' && $user->persona_id) {
            $vigente = DB::selectOne('SELECT p.firma_id, i.nombre AS firma_nombre
                FROM profesores p
                LEFT JOIN images i ON i.id = p.firma_id AND i.deleted_at IS NULL
                WHERE p.id = ? AND p.deleted_at IS NULL', [$user->persona_id]);

            $grupos = $this->gruposDelTitular($user);
        }

        return [
            'es_titular' => count($grupos) > 0,
            'grupos' => $grupos,
            'vigente' => ($vigente && $vigente->firma_nombre) ? $vigente : null,
            'solicitud' => $this->ultimaSolicitud((int) $user->user_id),
        ];
    }

    /**
     * POST firmas-del-titular/solicitar — sube la imagen y la deja pendiente.
     *
     * Si ya había una pendiente se RETIRA (queda en la papelera de `change_asked`):
     * quien sube otra está diciendo que la anterior ya no la quiere, y dos pendientes
     * del mismo docente harían que el aprobador eligiera cuál vale.
     */
    public function postSolicitar()
    {
        $user = User::fromToken();

        Autoriza::exigir($user->tipo === 'Profesor' && count($this->gruposDelTitular($user)) > 0,
            'Sólo el docente titular de un grupo puede pedir que se cambie su firma.');

        $file = SafeUpload::archivoRecibido('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, self::EXTENSIONES, true)) {
            abort(422, 'La firma tiene que ser una imagen PNG o JPG.');
        }

        $imagen = (new ImagesController)->guardar_imagen($user);
        $imagen->publica = false;
        $imagen->save();

        $ahora = Reloj::ahora();

        $asked_id = DB::transaction(function () use ($user, $imagen, $ahora) {
            foreach ($this->pendientesDe((int) $user->user_id) as $vieja) {
                DB::update('UPDATE change_asked SET deleted_at=?, deleted_by=? WHERE id=?',
                    [$ahora, $user->user_id, $vieja->asked_id]);
            }

            $data_id = DB::table('change_asked_data')->insertGetId([
                'firma_id_new' => $imagen->id,
                'created_by' => $user->user_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            return DB::table('change_asked')->insertGetId([
                'asked_by_user_id' => $user->user_id,
                'asked_for_user_id' => $user->user_id,
                'tipo_user' => 'Profesor',
                'data_id' => $data_id,
                'year_asked_id' => $user->year_id,
                'periodo_asked_id' => $user->periodo_id ?? null,
                'created_by' => $user->user_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        });

        Auditoria::registrar()
            ->crear('pedido_de_cambio', (int) $asked_id)
            ->a(['imagen_id' => $imagen->id])
            ->resumen('Pidió cambiar su firma de titular')
            ->guardar();

        return response()->json(['solicitud' => $this->ultimaSolicitud((int) $user->user_id)], 201);
    }

    /**
     * PUT firmas-del-titular/retirar — el titular retira su pendiente.
     */
    public function putRetirar()
    {
        $user = User::fromToken();
        $ahora = Reloj::ahora();

        $pendientes = $this->pendientesDe((int) $user->user_id);

        if (count($pendientes) === 0) {
            abort(404, 'No tienes ninguna firma pendiente.');
        }

        foreach ($pendientes as $p) {
            DB::update('UPDATE change_asked SET deleted_at=?, deleted_by=? WHERE id=?',
                [$ahora, $user->user_id, $p->asked_id]);
        }

        return ['solicitud' => $this->ultimaSolicitud((int) $user->user_id)];
    }

    /**
     * GET firmas-del-titular/pendientes — la bandeja de quien aprueba.
     */
    public function getPendientes()
    {
        $user = $this->aprobador();

        $filas = DB::select('SELECT c.id AS asked_id, c.asked_by_user_id, c.created_at,
                p.id AS profesor_id, p.nombres, p.apellidos, p.sexo,
                IFNULL(fi.nombre, IF(p.sexo="F","default_female.png","default_male.png")) AS foto_nombre,
                d.firma_id_new, inew.nombre AS firma_nueva_nombre,
                p.firma_id, ivig.nombre AS firma_vigente_nombre,
                (SELECT GROUP_CONCAT(g.nombre ORDER BY g.orden SEPARATOR ", ") FROM grupos g
                  WHERE g.titular_id = p.id AND g.year_id = c.year_asked_id AND g.deleted_at IS NULL) AS grupos
            FROM change_asked c
            INNER JOIN change_asked_data d ON d.id = c.data_id AND d.firma_id_new IS NOT NULL
            INNER JOIN profesores p ON p.user_id = c.asked_by_user_id AND p.deleted_at IS NULL
            LEFT JOIN images inew ON inew.id = d.firma_id_new AND inew.deleted_at IS NULL
            LEFT JOIN images ivig ON ivig.id = p.firma_id AND ivig.deleted_at IS NULL
            LEFT JOIN images fi ON fi.id = p.foto_id AND fi.deleted_at IS NULL
            WHERE c.deleted_at IS NULL AND c.answered_by IS NULL
            ORDER BY c.created_at, c.id');

        foreach ($filas as $f) {
            $f->es_mia = (int) $f->asked_by_user_id === (int) $user->user_id;
        }

        return ['pendientes' => $filas];
    }

    /**
     * GET firmas-del-titular/cuantas — el número del menú. Sin las filas: el menú lo
     * pide en cada arranque y no necesita más que la cifra.
     */
    public function getCuantas()
    {
        $user = $this->aprobador();

        $n = DB::selectOne('SELECT COUNT(*) AS n FROM change_asked c
            INNER JOIN change_asked_data d ON d.id = c.data_id AND d.firma_id_new IS NOT NULL
            WHERE c.deleted_at IS NULL AND c.answered_by IS NULL AND c.asked_by_user_id <> ?',
            [$user->user_id]);

        return ['cuantas' => (int) $n->n];
    }

    /**
     * PUT firmas-del-titular/aprobar/{asked_id} — la pendiente pasa a vigente.
     */
    public function putAprobar($asked_id)
    {
        $user = $this->aprobador();
        $pedido = $this->pendienteAjena($user, (int) $asked_id);
        $ahora = Reloj::ahora();

        $profesor = DB::selectOne('SELECT id, firma_id FROM profesores WHERE user_id=? AND deleted_at IS NULL',
            [$pedido->asked_by_user_id]);

        if ($profesor === null) {
            abort(404, 'La solicitud no es de ningún docente.');
        }

        DB::transaction(function () use ($user, $pedido, $profesor, $ahora) {
            DB::update('UPDATE profesores SET firma_id=?, updated_by=?, updated_at=? WHERE id=?',
                [$pedido->firma_id_new, $user->user_id, $ahora, $profesor->id]);

            DB::update('UPDATE images SET user_id=?, publica=0 WHERE id=?',
                [$pedido->asked_by_user_id, $pedido->firma_id_new]);

            DB::update('UPDATE change_asked_data SET firma_id_accepted=1, updated_at=? WHERE id=?',
                [$ahora, $pedido->data_id]);

            DB::update('UPDATE change_asked SET accepted_at=?, answered_by=?, updated_by=?, updated_at=? WHERE id=?',
                [$ahora, $user->user_id, $user->user_id, $ahora, $pedido->asked_id]);
        });

        Auditoria::registrar()
            ->editar('pedido_de_cambio', (int) $pedido->asked_id)
            ->de(['profesor_id' => (int) $profesor->id, 'firma_id' => $profesor->firma_id])
            ->a(['firma_id' => (int) $pedido->firma_id_new])
            ->resumen('Aprobó la firma del titular (solicitud '.$pedido->asked_id.')')
            ->guardar();

        return ['ok' => true];
    }

    /**
     * PUT firmas-del-titular/rechazar/{asked_id} — con motivo, que el titular lee.
     */
    public function putRechazar($asked_id)
    {
        $user = $this->aprobador();
        $pedido = $this->pendienteAjena($user, (int) $asked_id);

        $motivo = trim((string) Request::input('motivo', ''));

        if ($motivo === '') {
            abort(422, 'Escribe el motivo: es lo que el titular va a leer para corregirla.');
        }

        $motivo = mb_substr($motivo, 0, 255);
        $ahora = Reloj::ahora();

        DB::transaction(function () use ($user, $pedido, $motivo, $ahora) {
            DB::update('UPDATE change_asked_data SET firma_id_accepted=0, updated_at=? WHERE id=?',
                [$ahora, $pedido->data_id]);

            DB::update('UPDATE change_asked SET rechazado_at=?, answered_by=?, comentario_respuesta=?, updated_by=?, updated_at=? WHERE id=?',
                [$ahora, $user->user_id, $motivo, $user->user_id, $ahora, $pedido->asked_id]);
        });

        Auditoria::registrar()
            ->editar('pedido_de_cambio', (int) $pedido->asked_id)
            ->a(['estado' => 'rechazada', 'motivo' => $motivo])
            ->resumen('Rechazó la firma del titular (solicitud '.$pedido->asked_id.')')
            ->guardar();

        return ['ok' => true];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function aprobador(): object
    {
        $user = User::fromToken();

        Autoriza::exigir(Autoriza::puedeAprobarFirmas($user),
            'Sólo administración, secretaría, rectoría o coordinación aprueban firmas.');

        return $user;
    }

    /** La pendiente con ese id, y que NO sea de quien la resuelve. */
    private function pendienteAjena(object $user, int $asked_id): object
    {
        $pedido = DB::selectOne('SELECT c.id AS asked_id, c.asked_by_user_id, c.data_id, d.firma_id_new
            FROM change_asked c
            INNER JOIN change_asked_data d ON d.id = c.data_id AND d.firma_id_new IS NOT NULL
            WHERE c.id = ? AND c.deleted_at IS NULL AND c.answered_by IS NULL', [$asked_id]);

        if ($pedido === null) {
            abort(404, 'Esa solicitud de firma no existe o ya se resolvió.');
        }

        Autoriza::exigir((int) $pedido->asked_by_user_id !== (int) $user->user_id,
            'Tu propia firma la tiene que aprobar otra persona.');

        return $pedido;
    }

    private function gruposDelTitular(object $user): array
    {
        if (! $user->persona_id || ! $user->year_id) {
            return [];
        }

        return DB::select('SELECT id, nombre, abrev FROM grupos
            WHERE titular_id = ? AND year_id = ? AND deleted_at IS NULL ORDER BY orden',
            [$user->persona_id, $user->year_id]);
    }

    private function pendientesDe(int $userId): array
    {
        return DB::select('SELECT c.id AS asked_id FROM change_asked c
            INNER JOIN change_asked_data d ON d.id = c.data_id AND d.firma_id_new IS NOT NULL
            WHERE c.asked_by_user_id = ? AND c.deleted_at IS NULL AND c.answered_by IS NULL', [$userId]);
    }

    /** La última solicitud de firma de esa persona que no retiró, con su estado. */
    private function ultimaSolicitud(int $userId): ?object
    {
        $s = DB::selectOne('SELECT c.id AS asked_id, c.created_at, c.accepted_at, c.rechazado_at,
                c.comentario_respuesta AS motivo, c.answered_by, d.firma_id_accepted,
                d.firma_id_new, i.nombre AS firma_nombre,
                TRIM(CONCAT(IFNULL(pr.nombres, u.username), " ", IFNULL(pr.apellidos, ""))) AS respondida_por
            FROM change_asked c
            INNER JOIN change_asked_data d ON d.id = c.data_id AND d.firma_id_new IS NOT NULL
            LEFT JOIN images i ON i.id = d.firma_id_new AND i.deleted_at IS NULL
            LEFT JOIN users u ON u.id = c.answered_by
            LEFT JOIN profesores pr ON pr.user_id = c.answered_by AND pr.deleted_at IS NULL
            WHERE c.asked_by_user_id = ? AND c.deleted_at IS NULL
            ORDER BY c.id DESC LIMIT 1', [$userId]);

        if ($s === null) {
            return null;
        }

        $s->estado = $s->answered_by === null ? 'pendiente'
            : ((int) $s->firma_id_accepted === 1 ? 'aprobada' : 'rechazada');

        unset($s->answered_by, $s->firma_id_accepted);

        return $s;
    }
}
