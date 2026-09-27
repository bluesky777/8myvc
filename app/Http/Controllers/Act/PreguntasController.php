<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Recorrido;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;

/**
 * LAS PREGUNTAS DE UNA ACTIVIDAD NUEVA: crear (una o en lote), guardar, duplicar, borrar, ordenar
 * y sus condiciones.
 *
 * Contrato §3.4, con las reglas de §2.4 (tipos), §2.5 (condiciones) y §2.9 (qué se edita con
 * respuestas). Todo es del dueño. El cuerpo de una pregunta es `PreguntaNueva` y la respuesta
 * `PreguntaAct`, completa (con correctas): quien la edita es quien la creó.
 *
 * **Con respuestas dadas, sólo textos.** Añadir, quitar, reordenar, cambiar el tipo, las opciones,
 * la correcta o los puntos es 409: cambiaría lo que ya respondió alguien. Cambiar la correcta de un
 * cuestionario con notas va por `impacto` + `aplicar-cambios` (tanda 2).
 */
class PreguntasController extends Controller
{
    use ResuelveElUsuario;

    public const TIPOS = ['unica', 'multiple', 'corta', 'parrafo', 'escala', 'sino', 'imagen_opciones', 'video', 'archivo', 'fecha'];

    /** Los tipos que llevan opciones: los de elegir, y `corta` (sus opciones son respuestas aceptadas). */
    private const CON_OPCIONES = ['unica', 'multiple', 'sino', 'imagen_opciones', 'video', 'corta'];

    /** Los que no califican nunca: sus `puntos` se guardan en 0 (§2.4). */
    private const SIN_PUNTOS = ['parrafo', 'escala', 'archivo', 'fecha'];

    /** Por defecto no se comparten los textos libres ni los archivos (§2.10). */
    private const NO_SE_COMPARTEN = ['corta', 'parrafo', 'archivo', 'fecha'];

    /** Imágenes del enunciado y de las opciones: el cliente ya las reduce a 1600 px. */
    private const IMAGEN_TOPE_BYTES = 5 * 1024 * 1024;

    private const IMAGEN_LADO_MAXIMO = 1600;

    /**
     * `POST act/{id}/imagen` multipart `file` → `{id, imagen_url, ancho, alto, bytes}` (tanda 3).
     *
     * La imagen del enunciado de una pregunta (`imagen_id`) o de una opción (`image_id`, en
     * `imagen_opciones`). Va a `images` —la tabla a la que apuntan esas dos columnas y de la que
     * `Formas` saca `imagen_url`—, en `public/images/perfil/user_{id}/` y **pública**: la ve todo el
     * que responde, con un `<img>` sin token. No es el almacén privado de las entregas
     * (`ws_archivos`), que es para lo que suben los alumnos. Sólo el dueño; tope 5 MB, sólo
     * imágenes, y 422 si el lado largo pasa de 1600 px. Subirla no la ata a nada: se ata al guardar
     * la pregunta con ese id.
     */
    public function postImagen($id)
    {
        $user = $this->user;
        $this->actividadDelDueno($id);

        $file = SafeUpload::archivoRecibido('file');

        if ($file->getSize() > self::IMAGEN_TOPE_BYTES) {
            abort(422, 'La imagen pasa de 5 MB.');
        }

        $medidas = @getimagesize($file->getRealPath());

        if (! $medidas || ! in_array($medidas[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            abort(422, 'Eso no se puede leer como imagen (jpg, png, gif o webp).');
        }

        [$ancho, $alto] = $medidas;

        if (max($ancho, $alto) > self::IMAGEN_LADO_MAXIMO) {
            abort(422, 'La imagen pasa de '.self::IMAGEN_LADO_MAXIMO.' px por el lado largo: redúcela antes de subirla.');
        }

        $carpetaNombre = 'user_'.(int) $user->user_id;
        $carpeta = public_path('images/perfil/'.$carpetaNombre);

        if (! File::exists($carpeta)) {
            File::makeDirectory($carpeta, 0755, true, true);
        }

        // Valida la extensión declarada y la real, y da un nombre libre en la carpeta.
        $nombre = SafeUpload::nombreDisponible($file, $carpeta, SafeUpload::EXTENSIONES_IMAGEN);
        $bytes = (int) $file->getSize();
        $file->move($carpeta, $nombre);

        $ahora = Actividad::ahora();
        $imagenId = DB::table('images')->insertGetId([
            'nombre' => $carpetaNombre.'/'.$nombre,
            'user_id' => (int) $user->user_id,
            'publica' => 1,
            'created_by' => (int) $user->user_id,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        return [
            'id' => $imagenId,
            'imagen_url' => Formas::urlDeImagen($carpetaNombre.'/'.$nombre),
            'ancho' => (int) $ancho,
            'alto' => (int) $alto,
            'bytes' => $bytes,
        ];
    }

    /** `POST act/{id}/preguntas` `PreguntaNueva` → `PreguntaAct`, al final. */
    public function postCrear($id)
    {
        $act = $this->actividadDelDueno($id);
        $this->exigirSinRespuestas($act);

        [$columnas, $opciones] = $this->preguntaValida(Request::all(), $act, null);

        $pid = DB::transaction(fn () => $this->insertar($act, $columnas, $opciones, $this->siguienteOrden((int) $act->id)));

        return $this->pregunta((int) $act->id, $pid);
    }

    /** `POST act/{id}/preguntas/lote` `{preguntas}` → `PreguntaAct[]`. Para lo que propone la IA. */
    public function postLote($id)
    {
        $act = $this->actividadDelDueno($id);
        $this->exigirSinRespuestas($act);

        $dadas = Request::input('preguntas');

        if (! is_array($dadas) || $dadas === [] || count($dadas) > 50) {
            abort(422, 'Manda entre 1 y 50 preguntas.');
        }

        // Se validan TODAS antes de escribir la primera: un lote va entero o no va.
        $validas = array_map(fn ($p) => $this->preguntaValida(is_array($p) ? $p : [], $act, null), array_values($dadas));

        $ids = DB::transaction(function () use ($act, $validas) {
            $orden = $this->siguienteOrden((int) $act->id);
            $ids = [];

            foreach ($validas as [$columnas, $opciones]) {
                $ids[] = $this->insertar($act, $columnas, $opciones, $orden++);
            }

            return $ids;
        });

        $todas = Formas::preguntas((int) $act->id);

        return array_values(array_filter($todas, fn ($p) => in_array($p['id'], $ids, true)));
    }

    /**
     * `POST act/preguntas/{pid}/guardar` `PreguntaNueva` → `PreguntaAct`. Las opciones se reemplazan
     * por la lista: las que traen `id` se actualizan, las que faltan se borran, las nuevas se crean.
     */
    public function postGuardar($pid)
    {
        [$act, $antes] = $this->preguntaDelDueno($pid);
        [$columnas, $opciones] = $this->preguntaValida(Request::all(), $act, $antes);

        $validas = array_map(fn ($o) => (int) $o['id'], $antes['opciones']);

        foreach ($opciones as $o) {
            if ($o['id'] !== null && ! in_array($o['id'], $validas, true)) {
                abort(422, 'Una opción con id no es de esta pregunta.');
            }
        }

        if (Actividad::tieneRespuestas((int) $act->id)) {
            $this->exigirSoloTextos($antes, $columnas, $opciones);
        }

        if ($columnas['tipo_pregunta'] === 'archivo' && $antes['tipo'] !== 'archivo') {
            $dependen = $this->queDependenDe([(int) $pid]);

            if ($dependen !== []) {
                $this->conflicto(422, 'De una pregunta de archivo no puede depender ninguna condición.', $dependen);
            }
        }

        DB::transaction(function () use ($pid, $columnas, $opciones) {
            DB::table('ws_preguntas')->where('id', $pid)->update($columnas + ['updated_at' => Actividad::ahora()]);
            $this->reemplazarOpciones((int) $pid, $opciones);
        });

        return $this->pregunta((int) $act->id, (int) $pid);
    }

    /** `POST act/preguntas/{pid}/duplicar` → `PreguntaAct`, justo después de la original. */
    public function postDuplicar($pid)
    {
        [$act, $p] = $this->preguntaDelDueno($pid);
        $this->exigirSinRespuestas($act);

        $nueva = DB::transaction(function () use ($act, $p, $pid) {
            DB::update('UPDATE ws_preguntas SET orden = orden + 1 WHERE actividad_id = ? AND orden > ? AND deleted_at IS NULL',
                [$act->id, $p['orden']]);

            $fila = (array) DB::selectOne('SELECT * FROM ws_preguntas WHERE id = ?', [$pid]);
            unset($fila['id']);
            $ahora = Actividad::ahora();
            $fila['orden'] = $p['orden'] + 1;
            $fila['added_by'] = (int) $this->user->user_id;
            $fila['created_at'] = $ahora;
            $fila['updated_at'] = $ahora;

            $nueva = DB::table('ws_preguntas')->insertGetId($fila);

            foreach (DB::select('SELECT * FROM ws_opciones WHERE pregunta_id = ? ORDER BY orden, id', [$pid]) as $o) {
                $o = (array) $o;
                unset($o['id']);
                $o['pregunta_id'] = $nueva;
                $o['created_at'] = $ahora;
                $o['updated_at'] = $ahora;
                DB::table('ws_opciones')->insert($o);
            }

            // Las condiciones apuntan a preguntas anteriores a la original, que también lo son de
            // la copia: se copian tal cual.
            foreach (DB::select('SELECT * FROM ws_condiciones WHERE pregunta_id = ?', [$pid]) as $c) {
                $c = (array) $c;
                unset($c['id']);
                $c['pregunta_id'] = $nueva;
                $c['created_at'] = $ahora;
                $c['updated_at'] = $ahora;
                DB::table('ws_condiciones')->insert($c);
            }

            return $nueva;
        });

        return $this->pregunta((int) $act->id, $nueva);
    }

    /** `POST act/preguntas/{pid}/borrar` → `{ok: true}`; 409 si otra depende de ella. */
    public function postBorrar($pid)
    {
        [$act] = $this->preguntaDelDueno($pid);
        $this->exigirSinRespuestas($act);

        $dependen = $this->queDependenDe([(int) $pid]);

        if ($dependen !== []) {
            $this->conflicto(409, 'Otras preguntas dependen de ésta: quita primero sus condiciones.', $dependen);
        }

        DB::transaction(function () use ($act, $pid) {
            // Borrado de verdad, no `deleted_at`: sin respuestas enviadas no hay nada que guardar, y
            // la cascada se lleva sus opciones, sus condiciones y las respuestas de los borradores.
            DB::delete('DELETE FROM ws_preguntas WHERE id = ?', [$pid]);
            $this->renumerar((int) $act->id);
        });

        return ['ok' => true];
    }

    /**
     * `POST act/{id}/preguntas/orden` `{orden: [{id, seccion}]}` → `PreguntaAct[]`. La lista entera,
     * en orden; 422 si deja una condición apuntando a una posterior.
     */
    public function postOrden($id)
    {
        $act = $this->actividadDelDueno($id);
        $this->exigirSinRespuestas($act);

        $dada = Request::input('orden');
        $actuales = array_map(fn ($f) => (int) $f->id,
            DB::select('SELECT id FROM ws_preguntas WHERE actividad_id = ? AND deleted_at IS NULL', [$act->id]));

        if (! is_array($dada)) {
            abort(422, 'Falta el orden.');
        }

        $ids = array_map(fn ($o) => (int) (is_array($o) ? ($o['id'] ?? 0) : 0), $dada);
        $a = $ids;
        $b = $actuales;
        sort($a);
        sort($b);

        if ($a !== $b) {
            abort(422, 'El orden tiene que traer todas las preguntas de la actividad, una vez cada una.');
        }

        // El 422 sale DENTRO de la transacción: la excepción la deshace, y sólo a ella. Antes se
        // deshacía a mano y el `catch` volvía a hacer `rollBack()` mientras quedara un nivel abierto,
        // así que dentro de otra transacción (la de un test, o un llamador futuro) deshacía también
        // la de fuera.
        DB::transaction(function () use ($dada, $act) {
            foreach (array_values($dada) as $i => $o) {
                $seccion = max(1, (int) ($o['seccion'] ?? 1));
                DB::update('UPDATE ws_preguntas SET orden = ?, seccion = ?, updated_at = ? WHERE id = ?',
                    [$i + 1, $seccion, Actividad::ahora(), (int) $o['id']]);
            }

            $rotas = $this->condicionesRotas((int) $act->id);

            if ($rotas !== []) {
                $this->conflicto(422, 'Ese orden deja preguntas que dependen de otras que van después.', $rotas);
            }
        });

        return Formas::preguntas((int) $act->id);
    }

    /**
     * `POST act/preguntas/{pid}/condiciones` `{grupos: CondicionAct[][]}` → `PreguntaAct`. Reemplaza
     * todas; 422 si alguna apunta a una posterior, a sí misma o a una de archivo.
     */
    public function postCondiciones($pid)
    {
        [$act, $p] = $this->preguntaDelDueno($pid);
        $this->exigirSinRespuestas($act);

        $grupos = Request::input('grupos', []);

        if (! is_array($grupos)) {
            abort(422, 'Las condiciones van como una lista de grupos.');
        }

        $todas = [];

        foreach (Formas::preguntas((int) $act->id) as $q) {
            $todas[$q['id']] = $q;
        }

        $filas = [];
        $numero = 0;

        foreach ($grupos as $grupo) {
            if (! is_array($grupo) || $grupo === []) {
                continue;
            }

            $numero++;

            foreach ($grupo as $c) {
                $filas[] = $this->condicionValida(is_array($c) ? $c : [], $p, $todas) + ['grupo' => $numero];
            }
        }

        DB::transaction(function () use ($pid, $filas) {
            $ahora = Actividad::ahora();
            DB::delete('DELETE FROM ws_condiciones WHERE pregunta_id = ?', [$pid]);

            foreach ($filas as $f) {
                DB::table('ws_condiciones')->insert($f + ['pregunta_id' => (int) $pid, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        });

        return $this->pregunta((int) $act->id, (int) $pid);
    }

    // ------------------------------------------------------------------ validación

    /**
     * `PreguntaNueva` → columnas de `ws_preguntas` y la lista de opciones. `$antes` es la pregunta
     * guardada, al editar.
     *
     * @return array{0: array<string, mixed>, 1: list<array{id: ?int, definicion: string, image_id: ?int, is_correct: bool, error_tipico: ?string}>}
     */
    private function preguntaValida(array $d, object $act, ?array $antes): array
    {
        $tipo = (string) ($d['tipo'] ?? $antes['tipo'] ?? '');

        if (! in_array($tipo, self::TIPOS, true)) {
            abort(422, 'Ese tipo de pregunta no existe.');
        }

        if ($tipo === 'archivo' && $act->anonimato !== 'nombre') {
            abort(422, 'Una pregunta de archivo no cabe en una encuesta anónima: el archivo lleva quién lo subió.');
        }

        $enunciado = trim((string) ($d['enunciado'] ?? ''));

        if ($enunciado === '' || mb_strlen($enunciado) > 5000) {
            abort(422, 'El enunciado es obligatorio y no pasa de 5000 caracteres.');
        }

        $youtube = isset($d['youtube_id']) && $d['youtube_id'] !== '' ? (string) $d['youtube_id'] : null;

        if ($youtube !== null && ! preg_match('/^[A-Za-z0-9_-]{11}$/', $youtube)) {
            abort(422, 'Ese no es un id de video de YouTube: se mandan los 11 caracteres, no la URL.');
        }

        if ($tipo === 'video' && $youtube === null) {
            abort(422, 'Una pregunta de video necesita el video de YouTube.');
        }

        $inicio = Destinatarios::entero($d['youtube_inicio'] ?? null);
        $fin = Destinatarios::entero($d['youtube_fin'] ?? null);

        if (($inicio !== null && $inicio < 0) || ($fin !== null && $fin < 0) || ($inicio !== null && $fin !== null && $fin <= $inicio)) {
            abort(422, 'El fin del video tiene que ir después del inicio.');
        }

        $enlace = isset($d['enlace_url']) && $d['enlace_url'] !== '' ? trim((string) $d['enlace_url']) : null;

        if ($enlace !== null && (mb_strlen($enlace) > 500 || ! preg_match('#^https?://#i', $enlace))) {
            abort(422, 'El enlace tiene que empezar por http:// o https:// y no pasar de 500 caracteres.');
        }

        $imagen = Destinatarios::entero($d['imagen_id'] ?? null);

        if ($imagen !== null && ! DB::selectOne('SELECT id FROM images WHERE id = ? AND deleted_at IS NULL', [$imagen])) {
            abort(422, 'Esa imagen no existe.');
        }

        $estilo = $d['escala_estilo'] ?? null;

        if ($tipo === 'escala') {
            $estilo = in_array($estilo, ['numeros', 'caras', 'estrellas'], true) ? $estilo
                : ($estilo === null || $estilo === '' ? 'numeros' : abort(422, 'La escala es de números, caras o estrellas.'));
        } else {
            $estilo = null;
        }

        $puntos = in_array($tipo, self::SIN_PUNTOS, true) ? 0 : max(0, (int) ($d['puntos'] ?? 1));

        if ($puntos > 1000) {
            abort(422, 'Los puntos de una pregunta no pasan de 1000.');
        }

        $opciones = [];

        if (in_array($tipo, self::CON_OPCIONES, true)) {
            foreach ((array) ($d['opciones'] ?? []) as $o) {
                if (! is_array($o)) {
                    continue;
                }

                $definicion = trim((string) ($o['definicion'] ?? ''));
                $imagenOpcion = Destinatarios::entero($o['image_id'] ?? null);

                if ($definicion === '' && $imagenOpcion === null) {
                    abort(422, 'Una opción no tiene texto ni imagen.');
                }

                $error = isset($o['error_tipico']) && $o['error_tipico'] !== '' ? trim((string) $o['error_tipico']) : null;

                if ($error !== null && mb_strlen($error) > 300) {
                    abort(422, 'El error típico de una opción no pasa de 300 caracteres.');
                }

                $opciones[] = [
                    'id' => Destinatarios::entero($o['id'] ?? null),
                    'definicion' => $definicion,
                    'image_id' => $imagenOpcion,
                    'is_correct' => ! empty($o['is_correct']),
                    'error_tipico' => $error,
                ];
            }

            // Sí/No nace con sus dos opciones fijas (§2.4).
            if ($tipo === 'sino' && $opciones === []) {
                $opciones = [
                    ['id' => null, 'definicion' => 'Sí', 'image_id' => null, 'is_correct' => false, 'error_tipico' => null],
                    ['id' => null, 'definicion' => 'No', 'image_id' => null, 'is_correct' => false, 'error_tipico' => null],
                ];
            }

            $correctas = count(array_filter($opciones, fn ($o) => $o['is_correct']));

            if (in_array($tipo, ['unica', 'sino', 'imagen_opciones', 'video'], true) && $correctas > 1) {
                abort(422, 'Una pregunta de opción única tiene una sola correcta.');
            }
        }

        $texto = fn ($campo, $tope) => isset($d[$campo]) && trim((string) $d[$campo]) !== ''
            ? mb_substr(trim((string) $d[$campo]), 0, $tope) : null;

        return [[
            'tipo_pregunta' => $tipo,
            'enunciado' => $enunciado,
            'ayuda' => $texto('ayuda', 255),
            'seccion' => max(1, (int) ($d['seccion'] ?? $antes['seccion'] ?? 1)),
            'obligatoria' => ! empty($d['obligatoria']) ? 1 : 0,
            'puntos' => $puntos,
            'imagen_id' => $imagen,
            'youtube_id' => $youtube,
            'youtube_inicio' => $youtube === null ? null : $inicio,
            'youtube_fin' => $youtube === null ? null : $fin,
            'enlace_url' => $enlace,
            'escala_estilo' => $estilo,
            'texto_arriba' => $tipo === 'escala' ? $texto('texto_arriba', 255) : null,
            'texto_abajo' => $tipo === 'escala' ? $texto('texto_abajo', 255) : null,
            'opcion_otra' => in_array($tipo, ['unica', 'multiple'], true) && ! empty($d['opcion_otra']) ? 1 : 0,
            'aleatorias' => ! empty($d['aleatorias']) ? 1 : 0,
            'compartir' => array_key_exists('compartir', $d) && $d['compartir'] !== null
                ? (! empty($d['compartir']) ? 1 : 0)
                : (in_array($tipo, self::NO_SE_COMPARTEN, true) ? 0 : 1),
            'explicacion' => $texto('explicacion', 5000),
            // Sólo tiene sentido en `multiple`; en los demás tipos se guarda 0.
            'puntaje_parcial' => $tipo === 'multiple' && ! empty($d['puntaje_parcial']) ? 1 : 0,
        ], $opciones];
    }

    /** Una condición del cuerpo → fila de `ws_condiciones`, o 422. */
    private function condicionValida(array $c, array $p, array $todas): array
    {
        $dep = Destinatarios::entero($c['depende_de_id'] ?? null);
        $d = $dep === null ? null : ($todas[$dep] ?? null);

        if ($d === null || $dep === $p['id']) {
            $this->conflicto(422, 'Una condición apunta a una pregunta que no es de esta actividad, o a sí misma.', [$p['id']]);
        }

        if ($d['orden'] >= $p['orden']) {
            $this->conflicto(422, 'Una condición sólo puede depender de una pregunta anterior.', [$p['id']]);
        }

        if ($d['tipo'] === 'archivo') {
            $this->conflicto(422, 'Una condición no puede depender de una pregunta de archivo.', [$p['id']]);
        }

        $operador = (string) ($c['operador'] ?? '');

        if (! in_array($operador, ['es', 'no_es', 'contiene'], true)) {
            abort(422, 'El operador es «es», «no_es» o «contiene».');
        }

        $opcion = Destinatarios::entero($c['opcion_id'] ?? null);
        $valor = isset($c['valor']) ? mb_substr(trim((string) $c['valor']), 0, 200) : null;
        $deOpciones = in_array($d['tipo'], Recorrido::DE_OPCIONES, true);

        if ($deOpciones) {
            if ($opcion === null || ! in_array($opcion, array_map(fn ($o) => $o['id'], $d['opciones']), true)) {
                abort(422, 'Una condición sobre una pregunta de opciones tiene que nombrar una de sus opciones.');
            }

            $valor = null;
        } else {
            if ($valor === null || $valor === '') {
                abort(422, 'Una condición sobre una pregunta abierta necesita el valor a comparar.');
            }

            $opcion = null;
        }

        return ['depende_de_id' => $dep, 'operador' => $operador, 'opcion_id' => $opcion, 'valor' => $valor];
    }

    /**
     * Con respuestas, sólo cambian los textos (§2.9): enunciado, ayuda, etiquetas de la escala,
     * explicación y el texto de las opciones existentes.
     */
    private function exigirSoloTextos(array $antes, array $columnas, array $opciones): void
    {
        $estructura = [
            'tipo_pregunta' => $antes['tipo'], 'seccion' => $antes['seccion'], 'obligatoria' => (int) $antes['obligatoria'],
            'puntos' => $antes['puntos'], 'imagen_id' => $antes['imagen_id'], 'youtube_id' => $antes['youtube_id'],
            'youtube_inicio' => $antes['youtube_inicio'], 'youtube_fin' => $antes['youtube_fin'],
            'enlace_url' => $antes['enlace_url'], 'escala_estilo' => $antes['escala_estilo'],
            'opcion_otra' => (int) $antes['opcion_otra'], 'aleatorias' => (int) $antes['aleatorias'],
            'puntaje_parcial' => (int) $antes['puntaje_parcial'],
        ];

        foreach ($estructura as $col => $viejo) {
            if ((string) $columnas[$col] !== (string) $viejo) {
                abort(409, 'Ya hay respuestas: de esta pregunta sólo se pueden cambiar los textos.');
            }
        }

        $viejas = [];

        foreach ($antes['opciones'] as $o) {
            $viejas[$o['id']] = $o;
        }

        if (count($opciones) !== count($viejas)) {
            abort(409, 'Ya hay respuestas: no se pueden añadir ni quitar opciones.');
        }

        foreach ($opciones as $o) {
            $v = $o['id'] === null ? null : ($viejas[$o['id']] ?? null);

            if ($v === null || $v['is_correct'] !== $o['is_correct'] || $v['image_id'] !== $o['image_id']) {
                abort(409, 'Ya hay respuestas: no se pueden añadir ni quitar opciones, ni cambiar la correcta.');
            }
        }
    }

    // ------------------------------------------------------------------ escritura

    private function insertar(object $act, array $columnas, array $opciones, int $orden): int
    {
        $ahora = Actividad::ahora();

        $pid = DB::table('ws_preguntas')->insertGetId($columnas + [
            'actividad_id' => (int) $act->id,
            'orden' => $orden,
            'added_by' => (int) $this->user->user_id,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $this->reemplazarOpciones($pid, $opciones);

        return $pid;
    }

    /** Las opciones de la pregunta pasan a ser exactamente `$opciones`, en ese orden. */
    private function reemplazarOpciones(int $pid, array $opciones): void
    {
        $ahora = Actividad::ahora();
        $quedan = array_values(array_filter(array_map(fn ($o) => $o['id'], $opciones)));

        // La cascada de `ws_respuestas.opcion_id` y `ws_condiciones.opcion_id` se lleva lo que
        // colgaba de las que se van —sólo puede haber borradores: con envíos esto es 409—.
        if ($quedan === []) {
            DB::delete('DELETE FROM ws_opciones WHERE pregunta_id = ?', [$pid]);
        } else {
            DB::delete('DELETE FROM ws_opciones WHERE pregunta_id = ? AND id NOT IN ('.implode(',', array_fill(0, count($quedan), '?')).')',
                array_merge([$pid], $quedan));
        }

        foreach (array_values($opciones) as $i => $o) {
            $fila = [
                'definicion' => $o['definicion'],
                'image_id' => $o['image_id'],
                'is_correct' => $o['is_correct'] ? 1 : 0,
                'error_tipico' => $o['error_tipico'],
                'orden' => $i + 1,
                'updated_at' => $ahora,
            ];

            if ($o['id'] !== null) {
                DB::table('ws_opciones')->where('id', $o['id'])->where('pregunta_id', $pid)->update($fila);
            } else {
                DB::table('ws_opciones')->insert($fila + ['pregunta_id' => $pid, 'created_at' => $ahora]);
            }
        }
    }

    private function siguienteOrden(int $actividadId): int
    {
        return (int) DB::selectOne('SELECT COALESCE(MAX(orden), 0) + 1 AS n FROM ws_preguntas WHERE actividad_id = ? AND deleted_at IS NULL',
            [$actividadId])->n;
    }

    private function renumerar(int $actividadId): void
    {
        foreach (DB::select('SELECT id FROM ws_preguntas WHERE actividad_id = ? AND deleted_at IS NULL ORDER BY orden, id', [$actividadId]) as $i => $f) {
            DB::update('UPDATE ws_preguntas SET orden = ? WHERE id = ?', [$i + 1, $f->id]);
        }
    }

    // ------------------------------------------------------------------ lecturas y guardas

    /** Las preguntas que dependen de alguna de éstas. @return list<int> */
    private function queDependenDe(array $ids): array
    {
        $huecos = implode(',', array_fill(0, count($ids), '?'));

        return array_map(fn ($f) => (int) $f->pregunta_id,
            DB::select("SELECT DISTINCT pregunta_id FROM ws_condiciones WHERE depende_de_id IN ($huecos)", $ids));
    }

    /** Las preguntas con una condición que ya no apunta a una anterior. @return list<int> */
    private function condicionesRotas(int $actividadId): array
    {
        return array_map(fn ($f) => (int) $f->id, DB::select(
            'SELECT DISTINCT p.id FROM ws_condiciones c
              INNER JOIN ws_preguntas p ON p.id = c.pregunta_id
              INNER JOIN ws_preguntas d ON d.id = c.depende_de_id
              WHERE p.actividad_id = ? AND d.orden >= p.orden',
            [$actividadId]
        ));
    }

    private function pregunta(int $actividadId, int $pid): array
    {
        foreach (Formas::preguntas($actividadId) as $p) {
            if ($p['id'] === $pid) {
                return $p;
            }
        }

        abort(404, 'Esa pregunta no existe.');
    }

    private function actividadDelDueno($id): object
    {
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $this->user);

        return $act;
    }

    /** @return array{0: object, 1: array} la actividad y la pregunta (forma interna) */
    private function preguntaDelDueno($pid): array
    {
        $fila = DB::selectOne('SELECT actividad_id FROM ws_preguntas WHERE id = ? AND deleted_at IS NULL', [(int) $pid]);

        if (! $fila || $fila->actividad_id === null) {
            abort(404, 'Esa pregunta no existe.');
        }

        $act = $this->actividadDelDueno($fila->actividad_id);

        return [$act, $this->pregunta((int) $act->id, (int) $pid)];
    }

    private function exigirSinRespuestas(object $act): void
    {
        if (Actividad::tieneRespuestas((int) $act->id)) {
            abort(409, 'Ya hay respuestas: no se pueden añadir, quitar ni reordenar preguntas.');
        }
    }

    /** @return never */
    private function conflicto(int $codigo, string $mensaje, array $preguntas): void
    {
        response()->json(['mensaje' => $mensaje, 'preguntas' => array_values(array_unique($preguntas))], $codigo)->throwResponse();
    }
}
