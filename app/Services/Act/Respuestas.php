<?php

namespace App\Services\Act;

use Illuminate\Support\Facades\DB;

/**
 * LAS RESPUESTAS DE QUIEN RESPONDE: a nombre de quién, cómo se leen y cómo se guardan.
 *
 * Contrato §2.4 (una fila por tipo), §2.6 (anonimato) y §3.7.
 *
 * ## A nombre de quién
 *
 * Quien responde es una **entrada** de `Destinatarios` (ver allí). La hoja se identifica por
 * `(actividad_id, user_id, alumno_id)` —el índice de §1.2—: el alumno responde con su propio
 * `alumno_id`; el acudiente «una vez por hijo», con el del hijo; el personal y el acudiente «una sola
 * vez», con NULL.
 *
 * ## El anonimato es de presentación, no de la base  *(Joseth, 26 sep 2026)*
 *
 * Manda sobre §2.6 del contrato: **toda hoja guarda quién la respondió y a qué hora**, sea cual sea
 * el anonimato. Lo anónimo es lo que la API le enseña al creador y a los directivos —ni nombres ni
 * ids ni horas por persona, ni desgloses de menos de 5—, y eso vive en `ResultadosController`. Por
 * eso aquí no hay dos caminos: la doble respuesta se corta en el servidor también en `total`, hay
 * borrador en todos los niveles, y «mis respuestas» de una anónima sale del servidor como las demás.
 * El «ya respondió» es tener una hoja terminada; la tabla aparte (`ws_participaciones`) sobraba.
 */
class Respuestas
{
    /** Tope de caracteres por tipo (§2.4). */
    private const TOPE = ['corta' => 300, 'parrafo' => 5000, 'otra' => 300];

    /**
     * La entrada con la que esta persona responde, o 403 si la actividad no le toca.
     *
     * `$alumnoId` sólo lo manda el acudiente en «una vez por hijo», para decir por cuál de sus hijos
     * responde (403 si no es su hijo oficial dentro del alcance). Si tiene varios y no lo dice, 422.
     */
    public static function entradaDe(object $act, object $user, ?int $alumnoId): array
    {
        $entradas = Destinatarios::resolver($act, null, (int) $user->user_id);

        if ($entradas === []) {
            abort(403, 'Esta actividad no te toca.');
        }

        if ($alumnoId !== null) {
            foreach ($entradas as $e) {
                if ($e['alumno_id'] === $alumnoId) {
                    return $e;
                }
            }

            abort(403, 'Ese alumno no es tu hijo, o esta actividad no le toca.');
        }

        if (count($entradas) > 1) {
            response()->json([
                'mensaje' => 'Elige por cuál de tus hijos respondes.',
                'hijos' => array_map(fn ($e) => $e['alumno_id'], $entradas),
            ], 422)->throwResponse();
        }

        return $entradas[0];
    }

    /** Las hojas de esa entrada (sólo con nombre: las anónimas no llevan a nadie). */
    public static function hojasDe(int $actividadId, array $entrada, ?bool $terminadas = null): array
    {
        $sql = 'SELECT * FROM ws_actividades_resueltas
                 WHERE actividad_id = ? AND user_id = ? AND alumno_id <=> ? AND deleted_at IS NULL';

        if ($terminadas !== null) {
            $sql .= ' AND terminado = '.($terminadas ? 1 : 0);
        }

        return DB::select($sql.' ORDER BY id', [$actividadId, $entrada['user_id'], $entrada['alumno_id']]);
    }

    /**
     * Intentos de un cuestionario (1–5, `oportunidades`); en lo demás, uno.
     */
    public static function maximoDeIntentos(object $act): int
    {
        return $act->modo === 'cuestionario' ? max(1, min(5, (int) ($act->oportunidades ?? 1))) : 1;
    }

    /** Los intentos gastados por esa entrada: sus hojas terminadas, en cualquier anonimato. */
    public static function intentosUsados(object $act, array $entrada): int
    {
        return count(self::hojasDe((int) $act->id, $entrada, true));
    }

    /**
     * Si la nota del cuestionario se le enseña ya a quien respondió.
     *
     * **Decidido aquí (el contrato sólo dice «null si no se muestra aún»)**: la nota va con la misma
     * espera que las correctas cuando es `al_cerrar` —con la actividad abierta, la nota de uno le
     * dice a los demás cuántas acertó— y se ve siempre con `al_enviar` y con `nunca` (en `nunca` lo
     * que no se enseña son las correctas, no la nota).
     */
    public static function notaVisible(object $act): bool
    {
        return $act->mostrar_correctas !== 'al_cerrar' || Actividad::estado($act) === 'cerrada';
    }

    /** Si se enseñan las correctas y la explicación (§3.13). */
    public static function correctasVisibles(object $act): bool
    {
        return $act->mostrar_correctas === 'al_enviar'
            || ($act->mostrar_correctas === 'al_cerrar' && Actividad::estado($act) === 'cerrada');
    }

    /**
     * `mi_estado` y `mi_nota` de la bandeja para esa entrada.
     *
     * @return array{mi_estado: string, mi_nota: ?int}
     */
    public static function miEstado(object $act, array $entrada): array
    {
        $cerrada = Actividad::estado($act) === 'cerrada';
        $id = (int) $act->id;

        if ($act->modo === 'tarea') {
            $e = $entrada['alumno_id'] === null ? null : DB::selectOne(
                'SELECT * FROM ws_entregas WHERE actividad_id = ? AND alumno_id = ?', [$id, $entrada['alumno_id']]
            );

            if ($e && $e->nota !== null) {
                return ['mi_estado' => 'calificada', 'mi_nota' => (int) $e->nota];
            }

            if ($e && $e->entregada_at !== null) {
                return ['mi_estado' => $e->tarde ? 'tarde' : 'entregada', 'mi_nota' => null];
            }

            if ($cerrada && ! Actividad::aceptaTarde($act)) {
                return ['mi_estado' => 'vencida', 'mi_nota' => null];
            }

            return ['mi_estado' => $e ? 'borrador' : 'pendiente', 'mi_nota' => null];
        }

        $enviadas = self::hojasDe($id, $entrada, true);

        if ($enviadas !== []) {
            $nota = null;

            if ($act->modo === 'cuestionario' && self::notaVisible($act)) {
                $notas = array_filter(array_map(fn ($h) => $h->nota_calculada, $enviadas), fn ($n) => $n !== null);
                $nota = $notas === [] ? null : (int) max($notas);
            }

            return ['mi_estado' => 'enviada', 'mi_nota' => $nota];
        }

        if (! $cerrada && self::hojasDe($id, $entrada, false) !== []) {
            return ['mi_estado' => 'borrador', 'mi_nota' => null];
        }

        return ['mi_estado' => $cerrada ? 'vencida' : 'pendiente', 'mi_nota' => null];
    }

    /**
     * Lee y valida las respuestas del cuerpo contra las preguntas. 422 con la primera que no cuadra.
     *
     * @param  array<int, array>  $porId  las preguntas indexadas por id
     * @return array<int, array{pregunta_id: int, opcion_ids: list<int>, texto: ?string, valor: ?int, fecha: ?string, archivo_id: ?int}>
     */
    public static function leer($dadas, array $porId, object $act, object $user, array $entrada): array
    {
        if ($dadas === null) {
            return [];
        }

        if (! is_array($dadas)) {
            abort(422, 'Las respuestas tienen que ser una lista.');
        }

        $leidas = [];

        foreach ($dadas as $r) {
            if (! is_array($r)) {
                abort(422, 'Una de las respuestas no tiene forma de respuesta.');
            }

            $pid = Destinatarios::entero($r['pregunta_id'] ?? null);
            $p = $pid === null ? null : ($porId[$pid] ?? null);

            if ($pid === null || $p === null) {
                abort(422, 'Una respuesta apunta a una pregunta que no es de esta actividad.');
            }

            $leida = ['pregunta_id' => $pid, 'opcion_ids' => [], 'texto' => null, 'valor' => null, 'fecha' => null, 'archivo_id' => null];
            $tipo = $p['tipo'];
            $texto = isset($r['texto']) ? trim((string) $r['texto']) : null;
            $texto = $texto === '' ? null : $texto;

            if (in_array($tipo, Recorrido::DE_OPCIONES, true)) {
                $validas = array_map(fn ($o) => $o['id'], $p['opciones']);
                $ids = array_values(array_unique(array_map('intval', is_array($r['opcion_ids'] ?? null) ? $r['opcion_ids'] : [])));

                if (array_diff($ids, $validas) !== []) {
                    abort(422, 'Una respuesta marca una opción que no es de su pregunta.');
                }

                if ($tipo !== 'multiple' && count($ids) > 1) {
                    abort(422, 'Esa pregunta se responde con una sola opción.');
                }

                if ($texto !== null) {
                    if (! $p['opcion_otra'] || ($tipo !== 'multiple' && $ids !== [])) {
                        $texto = null;
                    } elseif (mb_strlen($texto) > self::TOPE['otra']) {
                        abort(422, 'La respuesta «otra» pasa de 300 caracteres.');
                    }
                }

                $leida['opcion_ids'] = $ids;
                $leida['texto'] = $texto;
            } elseif ($tipo === 'corta' || $tipo === 'parrafo') {
                if ($texto !== null && mb_strlen($texto) > self::TOPE[$tipo]) {
                    abort(422, 'Una respuesta pasa de '.self::TOPE[$tipo].' caracteres.');
                }

                $leida['texto'] = $texto;
            } elseif ($tipo === 'escala') {
                $v = Destinatarios::entero($r['valor'] ?? null);

                if ($v !== null && ($v < 1 || $v > 5)) {
                    abort(422, 'La escala va de 1 a 5.');
                }

                $leida['valor'] = $v;
            } elseif ($tipo === 'fecha') {
                $f = $r['fecha'] ?? null;

                if ($f !== null && $f !== '') {
                    $d = \DateTime::createFromFormat('!Y-m-d', (string) $f);

                    if (! $d || $d->format('Y-m-d') !== $f) {
                        abort(422, 'La fecha tiene que ir como AAAA-MM-DD.');
                    }

                    $leida['fecha'] = $f;
                }
            } elseif ($tipo === 'archivo') {
                $a = Destinatarios::entero($r['archivo_id'] ?? null);

                if ($a !== null) {
                    $suyo = DB::selectOne(
                        'SELECT id FROM ws_archivos WHERE id = ? AND actividad_id = ? AND user_id = ? AND deleted_at IS NULL',
                        [$a, (int) $act->id, (int) $user->user_id]
                    );

                    if (! $suyo) {
                        abort(422, 'Ese archivo no es tuyo o no es de esta actividad.');
                    }
                }

                $leida['archivo_id'] = $a;
            }

            $leidas[$pid] = $leida;
        }

        return $leidas;
    }

    /**
     * Escribe las respuestas de una hoja: una fila por opción marcada (y una más con el texto de
     * «otra»); una fila en los demás tipos (§1.2). `$marca` es la hora de `created_at`.
     */
    public static function escribir(int $hojaId, array $porId, array $respuestas, string $marca): void
    {
        foreach ($respuestas as $pid => $r) {
            $tipo = $porId[$pid]['tipo'];

            if (! Recorrido::respondida($tipo, $r)) {
                continue;
            }

            $base = [
                'actividad_resuelta_id' => $hojaId,
                'pregunta_id' => $pid,
                'tipo_pregunta' => $tipo,
                'opcion_id' => null,
                'texto' => null,
                'valor' => null,
                'fecha' => null,
                'archivo_id' => null,
                'created_at' => $marca,
                'updated_at' => $marca,
            ];

            if (in_array($tipo, Recorrido::DE_OPCIONES, true)) {
                foreach ($r['opcion_ids'] as $o) {
                    DB::table('ws_respuestas')->insert(['opcion_id' => $o] + $base);
                }

                if ($r['texto'] !== null) {
                    DB::table('ws_respuestas')->insert(['texto' => $r['texto']] + $base);
                }

                continue;
            }

            DB::table('ws_respuestas')->insert([
                'texto' => $r['texto'],
                'valor' => $r['valor'],
                'fecha' => $r['fecha'],
                'archivo_id' => $r['archivo_id'],
            ] + $base);
        }
    }

    /**
     * Las respuestas guardadas de varias hojas, en la forma de `RespuestaEnviada`.
     *
     * @return array<int, array<int, array>> hoja_id => pregunta_id => respuesta
     */
    public static function deHojas(array $hojaIds): array
    {
        if ($hojaIds === []) {
            return [];
        }

        $huecos = implode(',', array_fill(0, count($hojaIds), '?'));
        $todas = [];

        foreach (DB::select(
            "SELECT * FROM ws_respuestas WHERE actividad_resuelta_id IN ($huecos) ORDER BY id",
            array_values($hojaIds)
        ) as $f) {
            $h = (int) $f->actividad_resuelta_id;
            $p = (int) $f->pregunta_id;
            $r = $todas[$h][$p] ?? ['pregunta_id' => $p, 'opcion_ids' => [], 'texto' => null, 'valor' => null, 'fecha' => null, 'archivo_id' => null];

            if ($f->opcion_id !== null) {
                $r['opcion_ids'][] = (int) $f->opcion_id;
            }

            $r['texto'] = $f->texto ?? $r['texto'];
            $r['valor'] = $f->valor !== null ? (int) $f->valor : $r['valor'];
            $r['fecha'] = $f->fecha ?? $r['fecha'];
            $r['archivo_id'] = $f->archivo_id !== null ? (int) $f->archivo_id : $r['archivo_id'];

            $todas[$h][$p] = $r;
        }

        return $todas;
    }

    /** Las de una hoja, como lista de `RespuestaEnviada`. */
    public static function listaDeHoja(int $hojaId): array
    {
        return array_values(self::deHojas([$hojaId])[$hojaId] ?? []);
    }
}
