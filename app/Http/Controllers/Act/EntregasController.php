<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Recorrido;
use App\Services\Act\Respuestas;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;

/**
 * LA ENTREGA DE LA TAREA Y LOS ARCHIVOS: subir la foto o el archivo, verlo, y entregar.
 *
 * Contrato §3.8. **Endpoint propio**, no `myimages/store-intacta-privada`: ése deja el fichero en
 * `public/`, sin tope y sin saber de qué actividad es. Aquí va en
 * `storage/app/actividades/{year_id}/{actividad_id}/`, fuera de `public/`, y sólo sale por
 * `GET act/archivos/{id}` con token y permiso — es trabajo de un menor.
 *
 * Topes (decididos por Joseth el 26 sep): **5 MB por fichero**, una foto y un archivo por entrega,
 * y la foto con el lado largo de 1600 px como mucho (el cliente ya la reduce a 1280). Por encima de
 * 5 MB la pantalla ofrece pegar un enlace.
 */
class EntregasController extends Controller
{
    use ResuelveElUsuario;

    private const TOPE_BYTES = 5 * 1024 * 1024;

    private const LADO_MAXIMO = 1600;

    /**
     * `POST act/{id}/archivo` multipart `file` + `clase=foto|archivo` + `alumno_id?` + `pregunta_id?`
     * → `ArchivoAct`. Subir otra foto u otro archivo a la misma entrega reemplaza el anterior.
     */
    public function postArchivo($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        $clase = (string) Request::input('clase');

        if (! in_array($clase, ['foto', 'archivo'], true)) {
            abort(422, 'La clase es foto o archivo.');
        }

        $entrada = Respuestas::entradaDe($act, $user, Destinatarios::entero(Request::input('alumno_id')));
        $this->exigirAbierta($act);

        if ($act->anonimato !== 'nombre') {
            abort(422, 'Una encuesta anónima no recibe archivos: el archivo lleva quién lo subió.');
        }

        $preguntaId = Destinatarios::entero(Request::input('pregunta_id'));

        if ($preguntaId !== null) {
            $pregunta = DB::selectOne(
                "SELECT id FROM ws_preguntas WHERE id = ? AND actividad_id = ? AND tipo_pregunta = 'archivo' AND deleted_at IS NULL",
                [$preguntaId, $act->id]
            );

            if (! $pregunta) {
                abort(422, 'Esa pregunta no es de archivo o no es de esta actividad.');
            }
        } elseif ($act->modo !== 'tarea' || ! $act->{'entrega_'.$clase}) {
            abort(422, $clase === 'foto' ? 'Esta actividad no pide una foto.' : 'Esta actividad no pide un archivo.');
        }

        $file = SafeUpload::archivoRecibido('file');

        if ($file->getSize() > self::TOPE_BYTES) {
            abort(422, 'El archivo pasa de 5 MB: pega un enlace');
        }

        $carpetaRelativa = 'actividades/'.(int) $act->year_id.'/'.(int) $act->id;
        $carpeta = storage_path('app/'.$carpetaRelativa);

        if (! File::exists($carpeta)) {
            File::makeDirectory($carpeta, 0750, true, true);
        }

        $permitidas = $clase === 'foto' ? SafeUpload::EXTENSIONES_IMAGEN : SafeUpload::EXTENSIONES_DOCUMENTO;
        // Valida la extensión declarada y la real, y da un nombre libre en la carpeta.
        $nombre = SafeUpload::nombreDisponible($file, $carpeta, $permitidas);
        $ancho = null;
        $alto = null;

        if ($clase === 'foto') {
            $medidas = @getimagesize($file->getRealPath());

            if (! $medidas) {
                abort(422, 'Esa foto no se puede leer como imagen.');
            }

            [$ancho, $alto] = $medidas;

            if (max($ancho, $alto) > self::LADO_MAXIMO) {
                abort(422, 'La foto pasa de '.self::LADO_MAXIMO.' px por el lado largo: redúcela antes de subirla.');
            }
        }

        $mime = (string) $file->getMimeType();
        $bytes = (int) $file->getSize();
        $original = mb_substr((string) SafeUpload::nombreParaGuardar($file), 0, 255);

        $file->move($carpeta, $nombre);

        $ahora = Actividad::ahora();

        $archivoId = DB::transaction(function () use ($act, $user, $entrada, $clase, $preguntaId, $nombre, $carpetaRelativa, $mime, $bytes, $original, $ancho, $alto, $ahora) {
            // La foto o el archivo de la ENTREGA es uno solo: el anterior se retira. Los de una
            // pregunta de archivo no, porque cada pregunta lleva el suyo.
            if ($preguntaId === null) {
                $this->retirarAnteriores($act, $user, $entrada, $clase);
            }

            return DB::table('ws_archivos')->insertGetId([
                'actividad_id' => (int) $act->id,
                'user_id' => (int) $user->user_id,
                'alumno_id' => $entrada['alumno_id'],
                'clase' => $clase,
                'nombre_original' => $original !== '' ? $original : $nombre,
                'ruta' => $carpetaRelativa.'/'.$nombre,
                'mime' => mb_substr($mime, 0, 100),
                'bytes' => $bytes,
                'ancho' => $ancho,
                'alto' => $alto,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        });

        return Formas::archivo(DB::selectOne('SELECT * FROM ws_archivos WHERE id = ?', [$archivoId]));
    }

    /**
     * `GET act/archivos/{archivoId}` → el fichero. Lo ven quien lo subió, los acudientes oficiales
     * del alumno, el dueño de la actividad y los directivos; 403 los demás.
     *
     * (El contrato lo pone en la tanda 2 junto a «ver del docente»; se adelanta porque el alumno
     * necesita ver la foto que acaba de subir.)
     */
    public function getArchivo($archivoId)
    {
        $user = $this->user;
        $a = DB::selectOne('SELECT * FROM ws_archivos WHERE id = ?', [(int) $archivoId]);

        if (! $a) {
            abort(404, 'Ese archivo no existe.');
        }

        $act = Actividad::cargar($a->actividad_id);

        $puede = (int) $a->user_id === (int) $user->user_id
            || Actividad::esDueno($act, $user)
            || Actividad::esDirectivo($user)
            || ($a->alumno_id !== null && ($user->tipo ?? '') === 'Acudiente' && DB::selectOne(
                'SELECT 1 AS si FROM parentescos p
                  INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL AND ac.is_acudiente = 1
                  WHERE p.alumno_id = ? AND ac.user_id = ? AND p.deleted_at IS NULL LIMIT 1',
                [$a->alumno_id, (int) $user->user_id]
            ));

        if (! $puede) {
            abort(403, 'No puedes ver ese archivo.');
        }

        $ruta = storage_path('app/'.$a->ruta);

        if (! File::exists($ruta)) {
            abort(404, 'El archivo está registrado pero el fichero no está en el servidor.');
        }

        // `octet-stream` y no el tipo real, por lo mismo que `OtrosColegiosController::getDocumento`:
        // con `image/*` el nginx del docker añade su propio `Access-Control-Allow-Origin` y el
        // navegador rechaza la foto. El tipo real viaja en `ArchivoAct.mime` para armar el `Blob`.
        return response()->file($ruta, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addcslashes($a->nombre_original, '"\\').'"',
        ]);
    }

    /**
     * `POST act/{id}/entregar` `{alumno_id?, texto?, enlace?, foto_id?, archivo_id?, respuestas?}` →
     * `EntregaAct`. Sólo lo que la tarea pide; se reentrega hasta el cierre (reemplaza), después sólo
     * con entrega tardía (marca `tarde`), y nunca una ya calificada.
     */
    public function postEntregar($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);

        if ($act->modo !== 'tarea') {
            abort(422, 'Sólo las tareas se entregan; lo demás se envía.');
        }

        $entrada = Respuestas::entradaDe($act, $user, Destinatarios::entero(Request::input('alumno_id')));

        if ($entrada['publico'] !== 'alumno' || $entrada['alumno_id'] === null) {
            abort(403, 'La tarea la entrega el alumno.');
        }

        $estado = Actividad::estado($act);
        $tarde = false;

        if ($estado !== 'abierta') {
            if (! Actividad::aceptaTarde($act)) {
                abort(409, 'Esta tarea ya no recibe entregas.');
            }

            $tarde = true;
        }

        $antes = DB::selectOne('SELECT * FROM ws_entregas WHERE actividad_id = ? AND alumno_id = ?', [$act->id, $entrada['alumno_id']]);

        if ($antes && $antes->nota !== null) {
            abort(409, 'Esta entrega ya está calificada.');
        }

        $texto = $this->opcional('texto');
        $enlace = $this->opcional('enlace');
        $fotoId = Destinatarios::entero(Request::input('foto_id'));
        $archivoId = Destinatarios::entero(Request::input('archivo_id'));

        foreach (['texto' => $texto, 'enlace' => $enlace, 'foto' => $fotoId, 'archivo' => $archivoId] as $tipo => $valor) {
            if ($valor !== null && ! $act->{'entrega_'.$tipo}) {
                abort(422, "Esta tarea no pide {$tipo}.");
            }
        }

        if ($texto !== null && mb_strlen($texto) > 10000) {
            abort(422, 'El texto de la entrega pasa de 10000 caracteres.');
        }

        if ($enlace !== null && (mb_strlen($enlace) > 500 || ! preg_match('#^https?://#i', $enlace))) {
            abort(422, 'El enlace tiene que empezar por http:// o https:// y no pasar de 500 caracteres.');
        }

        foreach (['foto' => $fotoId, 'archivo' => $archivoId] as $clase => $aid) {
            if ($aid !== null && ! DB::selectOne(
                'SELECT id FROM ws_archivos WHERE id = ? AND actividad_id = ? AND user_id = ? AND alumno_id <=> ? AND clase = ? AND deleted_at IS NULL',
                [$aid, $act->id, (int) $user->user_id, $entrada['alumno_id'], $clase]
            )) {
                abort(422, $clase === 'foto' ? 'Esa foto no es tuya o no es de esta tarea.' : 'Ese archivo no es tuyo o no es de esta tarea.');
            }
        }

        $preguntas = Formas::preguntas((int) $act->id);
        $porId = [];

        foreach ($preguntas as $p) {
            $porId[$p['id']] = $p;
        }

        $respuestas = $preguntas === [] ? [] : Respuestas::leer(Request::input('respuestas', []), $porId, $act, $user, $entrada);

        if ($texto === null && $enlace === null && $fotoId === null && $archivoId === null && $respuestas === []) {
            abort(422, 'No mandaste nada para entregar.');
        }

        $visibles = Recorrido::visibles($preguntas, $respuestas);
        $faltan = array_values(array_filter($visibles,
            fn ($pid) => $porId[$pid]['obligatoria'] && ! Recorrido::respondida($porId[$pid]['tipo'], $respuestas[$pid] ?? null)));

        if ($faltan !== []) {
            response()->json(['mensaje' => 'Faltan preguntas obligatorias por responder.', 'faltan' => $faltan], 422)->throwResponse();
        }

        $ahora = Actividad::ahora();

        DB::transaction(function () use ($act, $user, $entrada, $antes, $texto, $enlace, $fotoId, $archivoId, $tarde, $ahora, $preguntas, $porId, $respuestas, $visibles) {
            $fila = [
                'user_id' => (int) $user->user_id,
                'texto' => $texto,
                'enlace' => $enlace,
                'foto_id' => $fotoId,
                'archivo_id' => $archivoId,
                'entregada_at' => $ahora,
                'tarde' => $tarde ? 1 : 0,
                'updated_at' => $ahora,
            ];

            if ($antes) {
                DB::table('ws_entregas')->where('id', $antes->id)->update($fila);
            } else {
                DB::table('ws_entregas')->insert($fila + [
                    'actividad_id' => (int) $act->id,
                    'alumno_id' => $entrada['alumno_id'],
                    'created_at' => $ahora,
                ]);
            }

            // Las preguntas de la tarea, en una sola hoja que se reescribe al reentregar.
            if ($preguntas !== []) {
                $hoja = Respuestas::hojasDe((int) $act->id, $entrada)[0] ?? null;

                if ($hoja) {
                    $hojaId = (int) $hoja->id;
                    DB::delete('DELETE FROM ws_respuestas WHERE actividad_resuelta_id = ?', [$hojaId]);
                } else {
                    $hojaId = DB::table('ws_actividades_resueltas')->insertGetId([
                        'actividad_id' => (int) $act->id,
                        'persona_id' => empty($user->persona_id) ? null : (int) $user->persona_id,
                        'user_id' => (int) $user->user_id,
                        'alumno_id' => $entrada['alumno_id'],
                        'publico' => $entrada['publico'],
                        'grupo_id' => $entrada['grupo_id'],
                        'iniciada_at' => $ahora,
                        'created_at' => $ahora,
                    ]);
                }

                DB::update('UPDATE ws_actividades_resueltas SET terminado = 1, enviada_at = ?, updated_at = ? WHERE id = ?',
                    [$ahora, $ahora, $hojaId]);
                Respuestas::escribir($hojaId, $porId, array_intersect_key($respuestas, array_flip($visibles)), $ahora);
            }
        });

        return Formas::entrega(DB::selectOne('SELECT * FROM ws_entregas WHERE actividad_id = ? AND alumno_id = ?',
            [$act->id, $entrada['alumno_id']]));
    }

    /**
     * Retira la foto (o el archivo) anterior de esta entrega: `deleted_at`, y fuera del disco sólo
     * si nunca se entregó —si una entrega entregada lo nombra, el docente todavía tiene que verlo—.
     */
    private function retirarAnteriores(object $act, object $user, array $entrada, string $clase): void
    {
        $anteriores = DB::select(
            'SELECT a.* FROM ws_archivos a
              WHERE a.actividad_id = ? AND a.user_id = ? AND a.alumno_id <=> ? AND a.clase = ? AND a.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM ws_respuestas r WHERE r.archivo_id = a.id)',
            [$act->id, (int) $user->user_id, $entrada['alumno_id'], $clase]
        );

        foreach ($anteriores as $a) {
            DB::update('UPDATE ws_archivos SET deleted_at = ? WHERE id = ?', [Actividad::ahora(), $a->id]);

            $entregado = DB::selectOne(
                'SELECT 1 AS si FROM ws_entregas WHERE (foto_id = ? OR archivo_id = ?) AND entregada_at IS NOT NULL LIMIT 1',
                [$a->id, $a->id]
            );

            if (! $entregado) {
                File::delete(storage_path('app/'.$a->ruta));
            }
        }
    }

    private function opcional(string $campo): ?string
    {
        $v = Request::input($campo);

        return $v === null || trim((string) $v) === '' ? null : trim((string) $v);
    }

    private function exigirAbierta(object $act): void
    {
        if (Actividad::estado($act) !== 'abierta' && ! Actividad::aceptaTarde($act)) {
            abort(409, 'Esta actividad no está abierta.');
        }
    }
}
