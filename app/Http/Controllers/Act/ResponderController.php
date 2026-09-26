<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Calificador;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Planilla;
use App\Services\Act\Recorrido;
use App\Services\Act\Respuestas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * RESPONDER UNA ACTIVIDAD NUEVA: la actividad para responder, el recorrido, el borrador, el envío
 * y «mis respuestas».
 *
 * Contrato §3.7 y §3.13, con §2.5 (condiciones), §2.6 (anonimato) y §2.8 (calificación). Lo puede
 * pedir cualquier sesión: quién puede responder lo dice `Destinatarios`, no el tipo de usuario.
 *
 * ## El anonimato NO cambia lo que se escribe  *(Joseth, 26 sep 2026; manda sobre §2.6)*
 *
 * Toda hoja lleva `user_id`, `alumno_id`, `persona_id` y horas reales, sea cual sea el anonimato: es
 * de presentación, y lo aplica `ResultadosController` a lo que ven el creador y los directivos. Así
 * que aquí los tres niveles van por el mismo camino: borrador en el servidor, una sola respuesta
 * (o los intentos del cuestionario) cortada en el servidor, y «mis respuestas» desde la base.
 */
class ResponderController extends Controller
{
    use ResuelveElUsuario;

    /** `GET act/{id}/responder?alumno_id=&previa=` → `ActParaResponder`. */
    public function getResponder($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        $previa = Request::boolean('previa') && (Actividad::esDueno($act, $user) || Actividad::esDirectivo($user));
        $entrada = null;

        if (! $previa) {
            $entrada = Respuestas::entradaDe($act, $user, Destinatarios::entero(Request::input('alumno_id')));
            $this->exigirAbierta($act);
        }

        $borrador = [];

        if ($entrada) {
            $hoja = Respuestas::hojasDe((int) $act->id, $entrada, false)[0] ?? null;
            $borrador = $hoja ? Respuestas::listaDeHoja((int) $hoja->id) : [];
        }

        $miEntrega = null;

        if ($entrada && $act->modo === 'tarea' && $entrada['alumno_id'] !== null) {
            $miEntrega = Formas::entrega(DB::selectOne('SELECT * FROM ws_entregas WHERE actividad_id = ? AND alumno_id = ?',
                [$act->id, $entrada['alumno_id']]));
        }

        return [
            'id' => (int) $act->id,
            'modo' => $act->modo,
            'titulo' => (string) $act->titulo,
            'instrucciones' => $act->instrucciones,
            'estado' => Actividad::estado($act),
            'cierra_at' => $act->cierra_at,
            'anonimato' => $act->anonimato,
            'previa' => $previa,
            'por_alumno' => $this->porAlumno($entrada),
            'entrega' => $act->modo === 'tarea' ? Formas::entregaPedida($act) : null,
            'nota_maxima' => $act->modo === 'encuesta' ? null : (int) $act->nota_maxima,
            'intentos' => [
                'usados' => $entrada ? Respuestas::intentosUsados($act, $entrada) : 0,
                'maximo' => Respuestas::maximoDeIntentos($act),
            ],
            'preguntas' => array_map([Formas::class, 'sinSecretos'], Formas::preguntas((int) $act->id)),
            'borrador' => $borrador,
            'mi_entrega' => $miEntrega,
        ];
    }

    /** `POST act/{id}/recorrido` `{alumno_id?, respuestas}` → `{visibles}`. No escribe: es para Flutter. */
    public function postRecorrido($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);

        if (! Actividad::esDueno($act, $user) && ! Actividad::esDirectivo($user)) {
            Respuestas::entradaDe($act, $user, Destinatarios::entero(Request::input('alumno_id')));
        }

        [$preguntas, $porId] = $this->preguntas($act);
        $respuestas = Respuestas::leer(Request::input('respuestas', []), $porId, $act, $user, []);

        return ['visibles' => Recorrido::visibles($preguntas, $respuestas)];
    }

    /**
     * `POST act/{id}/borrador` `{alumno_id?, respuestas}` → `{guardado_at}`. En todos los niveles
     * de anonimato: el contrato lo limitaba a `nombre`, y con el anonimato de presentación ya no hay
     * por qué.
     */
    public function postBorrador($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);

        $entrada = Respuestas::entradaDe($act, $user, Destinatarios::entero(Request::input('alumno_id')));
        $this->exigirAbierta($act);

        if ($act->modo !== 'tarea' && Respuestas::intentosUsados($act, $entrada) >= Respuestas::maximoDeIntentos($act)) {
            abort(409, 'Ya respondiste esta actividad y no te quedan intentos.');
        }

        [, $porId] = $this->preguntas($act);
        $respuestas = Respuestas::leer(Request::input('respuestas', []), $porId, $act, $user, $entrada);
        $ahora = Actividad::ahora();

        DB::transaction(function () use ($act, $user, $entrada, $porId, $respuestas, $ahora) {
            $hoja = Respuestas::hojasDe((int) $act->id, $entrada, false)[0] ?? null;

            if ($hoja) {
                DB::delete('DELETE FROM ws_respuestas WHERE actividad_resuelta_id = ?', [$hoja->id]);
                DB::update('UPDATE ws_actividades_resueltas SET updated_at = ? WHERE id = ?', [$ahora, $hoja->id]);
                $hojaId = (int) $hoja->id;
            } else {
                $hojaId = $this->nuevaHoja($act, $user, $entrada, $ahora);
            }

            Respuestas::escribir($hojaId, $porId, $respuestas, $ahora);
        });

        return ['guardado_at' => $ahora];
    }

    /**
     * `POST act/{id}/enviar` `{alumno_id?, respuestas}` → `ResultadoDeEnvio`.
     *
     * Vuelve a evaluar el recorrido (§2.5): descarta lo que se respondió en preguntas que no quedaron
     * visibles y exige las visibles obligatorias (422 `{mensaje, faltan}`). Hoja, respuestas y
     * participación en una transacción. En el cuestionario, puntaje y nota en la hoja y, si califica,
     * en la planilla (`Planilla::alEnviar`).
     */
    public function postEnviar($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);

        if ($act->modo === 'tarea') {
            abort(422, 'Una tarea se entrega con act/{id}/entregar.');
        }

        $entrada = Respuestas::entradaDe($act, $user, Destinatarios::entero(Request::input('alumno_id')));
        $this->exigirAbierta($act);

        [$preguntas, $porId] = $this->preguntas($act);
        $respuestas = $this->validasYCompletas($preguntas, $porId, $act, $user, $entrada);
        $visibles = Recorrido::visibles($preguntas, $respuestas);
        $maximo = Respuestas::maximoDeIntentos($act);

        $resultado = DB::transaction(function () use ($act, $user, $entrada, $preguntas, $porId, $respuestas, $visibles, $maximo) {
            // Dentro de la transacción y con candado: dos envíos a la vez no gastan dos intentos.
            DB::select('SELECT id FROM ws_actividades WHERE id = ? FOR UPDATE', [$act->id]);

            $usados = Respuestas::intentosUsados($act, $entrada);

            if ($usados >= $maximo) {
                abort(409, $maximo > 1 ? 'Ya usaste todos tus intentos.' : 'Ya respondiste esta actividad.');
            }

            $marca = Actividad::ahora();

            $calificacion = ['puntaje' => null, 'puntaje_max' => null, 'nota' => null];

            if ($act->modo === 'cuestionario') {
                $p = Calificador::puntuar($preguntas, $visibles, $respuestas);
                $calificacion = [
                    'puntaje' => $p['puntaje'],
                    'puntaje_max' => $p['puntaje_max'],
                    'nota' => Calificador::nota($p['puntaje'], $p['puntaje_max'], (int) $act->nota_maxima),
                ];
            }

            // La nota vigente de ANTES de este envío: contra ella se juzga si la de la planilla
            // estaba editada a mano (§2.8).
            $vigenteAntes = $entrada['alumno_id'] !== null ? Planilla::calculadaDe($act, (int) $entrada['alumno_id']) : null;

            $borrador = Respuestas::hojasDe((int) $act->id, $entrada, false)[0] ?? null;

            if ($borrador) {
                $hojaId = (int) $borrador->id;
                DB::delete('DELETE FROM ws_respuestas WHERE actividad_resuelta_id = ?', [$hojaId]);
            } else {
                $hojaId = $this->nuevaHoja($act, $user, $entrada, $marca);
            }

            DB::update(
                'UPDATE ws_actividades_resueltas
                    SET terminado = 1, intento = ?, enviada_at = ?, puntaje = ?, puntaje_max = ?, nota_calculada = ?, updated_at = ?
                  WHERE id = ?',
                [$usados + 1, $marca, $calificacion['puntaje'], $calificacion['puntaje_max'],
                    $calificacion['nota'], $marca, $hojaId]
            );

            $soloVisibles = array_intersect_key($respuestas, array_flip($visibles));
            Respuestas::escribir($hojaId, $porId, $soloVisibles, $marca);

            // A la planilla (tanda 2), dentro de la misma transacción: si falla, no queda un envío
            // sin su nota. No pisa una nota editada a mano.
            if ($act->modo === 'cuestionario' && $entrada['alumno_id'] !== null) {
                Planilla::alEnviar($act, $user, (int) $entrada['alumno_id'], $vigenteAntes);
            }

            $verNota = $act->modo === 'cuestionario' && Respuestas::notaVisible($act);

            return [
                'enviada_at' => $marca,
                'nota' => $verNota ? $calificacion['nota'] : null,
                'puntaje' => $verNota ? $calificacion['puntaje'] : null,
                'puntaje_max' => $verNota ? $calificacion['puntaje_max'] : null,
                'quedan_intentos' => max(0, $maximo - $usados - 1),
            ];
        });

        return $resultado;
    }

    /**
     * `GET act/{id}/mis-respuestas?alumno_id=` → `MisRespuestasAct`.
     *
     * Quien respondió, o su acudiente oficial con el `alumno_id` del hijo. Las correctas y la
     * explicación sólo si `mostrar_correctas` lo permite. **También en las anónimas**: el
     * anonimato es frente al creador, no frente a uno mismo (Joseth, 26 sep; el contrato decía
     * `respuestas` vacío y copia local del cliente).
     */
    public function getMisRespuestas($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        $alumnoId = Destinatarios::entero(Request::input('alumno_id'));
        $entrada = null;

        if ($alumnoId !== null && ($user->tipo ?? '') === 'Acudiente') {
            $this->exigirHijoOficial($user, $alumnoId);

            // Las hojas del hijo (un cuestionario) o las mías por ese hijo (una encuesta por hijo).
            $hojas = DB::select(
                "SELECT * FROM ws_actividades_resueltas
                  WHERE actividad_id = ? AND alumno_id = ? AND terminado = 1 AND deleted_at IS NULL
                    AND (user_id = ? OR publico = 'alumno') ORDER BY id",
                [$act->id, $alumnoId, (int) $user->user_id]
            );
            $entrega = DB::selectOne('SELECT * FROM ws_entregas WHERE actividad_id = ? AND alumno_id = ?', [$act->id, $alumnoId]);

            foreach (Destinatarios::resolver($act) as $e) {
                if ($e['alumno_id'] === $alumnoId && ($e['user_id'] === (int) $user->user_id || $e['publico'] === 'alumno')) {
                    $entrada = $e;
                    break;
                }
            }
        } else {
            $entradas = Destinatarios::resolver($act, null, (int) $user->user_id);
            $entrada = $alumnoId === null ? ($entradas[0] ?? null)
                : (array_values(array_filter($entradas, fn ($e) => $e['alumno_id'] === $alumnoId))[0] ?? null);

            $hojas = DB::select(
                'SELECT * FROM ws_actividades_resueltas
                  WHERE actividad_id = ? AND user_id = ? AND alumno_id <=> ? AND terminado = 1 AND deleted_at IS NULL ORDER BY id',
                [$act->id, (int) $user->user_id, $entrada['alumno_id'] ?? $alumnoId]
            );
            $entrega = DB::selectOne('SELECT * FROM ws_entregas WHERE actividad_id = ? AND user_id = ?', [$act->id, (int) $user->user_id]);
        }

        // Sin responder sólo se entra a ver los resultados compartidos «con todos» (§2.10).
        $soloCompartidos = $hojas === [] && $entrada !== null && Actividad::compartidos($act) && $act->comparte_resultados === 'todos';

        if ($hojas === [] && ! ($entrega && $entrega->entregada_at !== null) && ! $soloCompartidos) {
            abort(409, 'Todavía no has respondido esta actividad.');
        }

        return $this->misRespuestas($act, $user, $entrada, $this->mejorHoja($act, $hojas), $hojas, $entrega);
    }

    // ------------------------------------------------------------------ piezas

    private function misRespuestas(object $act, object $user, ?array $entrada, ?object $hoja, array $hojas, ?object $entrega): array
    {
        $verNota = $act->modo === 'cuestionario' && Respuestas::notaVisible($act);
        $verCorrectas = $act->modo === 'cuestionario' && Respuestas::correctasVisibles($act);
        $filas = [];
        $correctas = null;

        if ($hoja) {
            [$preguntas] = $this->preguntas($act);
            $mias = Respuestas::deHojas([(int) $hoja->id])[(int) $hoja->id] ?? [];
            $visibles = Recorrido::visibles($preguntas, $mias);

            foreach ($preguntas as $p) {
                if (! in_array($p['id'], $visibles, true)) {
                    continue;
                }

                $publica = $p;

                if ($verCorrectas) {
                    $publica['opciones'] = array_map(function ($o) {
                        unset($o['error_tipico']);

                        return $o;
                    }, $publica['opciones']);
                } else {
                    $publica = Formas::sinSecretos($p);
                }

                $filas[] = [
                    'pregunta' => $publica,
                    'mia' => $mias[$p['id']] ?? null,
                    'acerto' => $verCorrectas ? Calificador::acerto($p, $mias[$p['id']] ?? null) : null,
                ];
            }

            if ($verNota) {
                $correctas = Calificador::puntuar($preguntas, $visibles, $mias)['correctas'];
            }
        }

        // Los resultados compartidos, con la marca de lo que eligió (§2.10): a quien respondió, y
        // con `todos` también a quien le llegó y no respondió.
        $compartidos = null;

        if (Actividad::compartidos($act) && ($hoja !== null || ($act->comparte_resultados === 'todos' && $entrada !== null))) {
            $compartidos = (new ResultadosController)->compartidos($act, $user, $entrada,
                $hoja ? (Respuestas::deHojas([(int) $hoja->id])[(int) $hoja->id] ?? []) : null);
        }

        $tiempo = null;

        if ($hoja && $hoja->iniciada_at !== null && $hoja->enviada_at !== null) {
            $tiempo = max(0, strtotime($hoja->enviada_at) - strtotime($hoja->iniciada_at));
        }

        return [
            'actividad' => Formas::enBandeja($act, $user, $entrada, $entrada ? Respuestas::miEstado($act, $entrada) : null),
            'enviada_at' => $hoja?->enviada_at ?? $entrega?->entregada_at,
            'nota' => $verNota && $hoja && $hoja->nota_calculada !== null ? (int) $hoja->nota_calculada : null,
            'puntaje' => $verNota && $hoja && $hoja->puntaje !== null ? (float) $hoja->puntaje : null,
            'puntaje_max' => $verNota && $hoja && $hoja->puntaje_max !== null ? (float) $hoja->puntaje_max : null,
            'correctas' => $correctas,
            'tiempo_segundos' => $tiempo,
            'intentos' => count($hojas),
            'promedio_grupo' => $verNota && $hoja && $hoja->grupo_id !== null ? $this->promedioDelGrupo($act, (int) $hoja->grupo_id) : null,
            'respuestas' => $filas,
            'entrega' => Formas::entrega($entrega),
            'compartidos' => $compartidos,
        ];
    }

    /** Con varios intentos cuenta el mejor (§2.8); a igual nota, el último. Fuera del cuestionario, el último. */
    private function mejorHoja(object $act, array $hojas): ?object
    {
        $mejor = null;

        foreach ($hojas as $h) {
            if ($mejor === null || $act->modo !== 'cuestionario' || (int) $h->nota_calculada >= (int) $mejor->nota_calculada) {
                $mejor = $h;
            }
        }

        return $mejor;
    }

    /** El promedio del grupo, sólo con 5 o más que respondieron (k-anonimato, §2.6). */
    private function promedioDelGrupo(object $act, int $grupoId): ?float
    {
        $mejores = DB::select(
            'SELECT MAX(nota_calculada) AS nota FROM ws_actividades_resueltas
              WHERE actividad_id = ? AND grupo_id = ? AND terminado = 1 AND deleted_at IS NULL AND nota_calculada IS NOT NULL
              GROUP BY user_id, alumno_id',
            [$act->id, $grupoId]
        );

        if (count($mejores) < 5) {
            return null;
        }

        return round(array_sum(array_map(fn ($f) => (int) $f->nota, $mejores)) / count($mejores), 1);
    }

    /**
     * Lee las respuestas y exige las obligatorias visibles: 422 `{mensaje, faltan}`.
     */
    private function validasYCompletas(array $preguntas, array $porId, object $act, object $user, array $entrada): array
    {
        $respuestas = Respuestas::leer(Request::input('respuestas', []), $porId, $act, $user, $entrada);
        $visibles = Recorrido::visibles($preguntas, $respuestas);
        $faltan = [];

        foreach ($visibles as $pid) {
            if ($porId[$pid]['obligatoria'] && ! Recorrido::respondida($porId[$pid]['tipo'], $respuestas[$pid] ?? null)) {
                $faltan[] = $pid;
            }
        }

        if ($faltan !== []) {
            response()->json(['mensaje' => 'Faltan preguntas obligatorias por responder.', 'faltan' => $faltan], 422)->throwResponse();
        }

        return $respuestas;
    }

    /**
     * Una hoja nueva, con quien responde y la hora real en cualquier anonimato. `publico` y
     * `grupo_id` son los que permiten desglosar los resultados.
     */
    private function nuevaHoja(object $act, object $user, array $entrada, string $marca): int
    {
        return DB::table('ws_actividades_resueltas')->insertGetId([
            'actividad_id' => (int) $act->id,
            'persona_id' => empty($user->persona_id) ? null : (int) $user->persona_id,
            'user_id' => (int) $user->user_id,
            'alumno_id' => $entrada['alumno_id'],
            'publico' => $entrada['publico'],
            'grupo_id' => $entrada['grupo_id'],
            'terminado' => 0,
            'intento' => 1,
            'iniciada_at' => $marca,
            'created_at' => $marca,
            'updated_at' => $marca,
        ]);
    }

    /** @return array{0: list<array>, 1: array<int, array>} las preguntas completas, en lista y por id */
    private function preguntas(object $act): array
    {
        $preguntas = Formas::preguntas((int) $act->id);
        $porId = [];

        foreach ($preguntas as $p) {
            $porId[$p['id']] = $p;
        }

        return [$preguntas, $porId];
    }

    /** Responder sólo en `abierta`, salvo la entrega tardía de una tarea (§2.1). */
    private function exigirAbierta(object $act): void
    {
        if (Actividad::estado($act) !== 'abierta' && ! Actividad::aceptaTarde($act)) {
            abort(409, 'Esta actividad no está abierta.');
        }
    }

    private function porAlumno(?array $entrada): ?array
    {
        if ($entrada === null || $entrada['publico'] !== 'acudiente' || $entrada['alumno_id'] === null) {
            return null;
        }

        return Formas::persona(null, $entrada['alumno_id'], (string) $entrada['hijo'], null);
    }

    /** Hijo oficial = parentesco vivo con `acudientes.is_acudiente = 1` y esta cuenta. */
    private function exigirHijoOficial(object $user, int $alumnoId): void
    {
        $es = DB::selectOne(
            'SELECT 1 AS si FROM parentescos p
              INNER JOIN acudientes ac ON ac.id = p.acudiente_id AND ac.deleted_at IS NULL AND ac.is_acudiente = 1
              WHERE p.alumno_id = ? AND ac.user_id = ? AND p.deleted_at IS NULL LIMIT 1',
            [$alumnoId, (int) $user->user_id]
        );

        if (! $es) {
            abort(403, 'Ese alumno no es tu hijo.');
        }
    }
}
