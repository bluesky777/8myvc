<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Avisos;
use App\Services\Act\Calificador;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Interactivas;
use App\Services\Act\Planilla;
use App\Services\Act\Recorrido;
use App\Services\Act\Respuestas;
use App\Services\Act\Retos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * EDITAR UN CUESTIONARIO QUE YA TIENE NOTAS: ver el impacto y aplicar el cambio.
 *
 * Contrato §3.11 y §2.9; el porqué en `ACTIVIDADES-Y-ENCUESTAS.md` §4c. Con hojas enviadas, cambiar
 * la respuesta correcta, los puntos de una pregunta o la nota máxima **no** se hace por
 * `preguntas/{pid}/guardar` ni por `guardar` (409): va por aquí, en dos pasos.
 *
 * - `impacto` recalcula cada hoja con el cambio y dice, por alumno, la nota de antes y la de después,
 *   la de la planilla, si estaba editada a mano y si cruza la mínima (`years.nota_minima_aceptada`).
 *   No escribe nada.
 * - `aplicar-cambios` hace lo mismo y lo guarda, en una transacción: el cambio, el puntaje y la nota
 *   de todas las hojas, y la planilla según `aplicar` —`todas`, `salvo_editadas` (por defecto) o
 *   `ninguna`—. «Editada» se juzga contra la calculada de ANTES del cambio (§2.8).
 *
 * `avisar` encola `nota_cambiada` (tanda 5) a cada alumno cuya nota calculada cambió, a su tema (él y
 * sus acudientes). Sin la nota: el aviso sólo dice que cambió.
 *
 * ## La tarea (Joseth, 26 sep)
 *
 * Una tarea con entregas calificadas tampoco cambia la nota máxima por `guardar`: va por aquí, con
 * el mismo criterio. Lo único que cambia es `nota_maxima` (sus preguntas no se califican: 422 si el
 * cambio las trae). La nota que puso el docente **se queda** en la escala de la actividad —un 38
 * sigue siendo 38—; lo que cambia es su conversión a la planilla (`Planilla::aLaEscala`), así que
 * en la fila `antes` = `despues` y lo que se mueve es `antes_planilla` → `despues_planilla`. Las tres
 * salidas y «editada a mano» igual que en el cuestionario. 422 si la nueva nota máxima queda por
 * debajo de una nota ya puesta.
 */
class EditarConNotasController extends Controller
{
    use ResuelveElUsuario;

    private const SALIDAS = ['todas', 'salvo_editadas', 'ninguna'];

    /** Tipos con una sola correcta. */
    private const UNA_CORRECTA = ['unica', 'sino', 'imagen_opciones', 'video'];

    /** `POST act/{id}/impacto` `{cambios}` → `ImpactoAct`. Dueño. No escribe. */
    public function postImpacto($id)
    {
        $act = $this->conNotasDelDueno($id);
        [$notaMaxima, $preguntas] = $this->conLosCambios($act, (array) Request::input('cambios', []));

        return $this->impacto($act, $notaMaxima, $preguntas)['forma'];
    }

    /**
     * `POST act/{id}/aplicar-cambios` `{cambios, aplicar, avisar}` → `{actualizadas, no_tocadas}`.
     * `actualizadas` = notas de la planilla que cambiaron; `no_tocadas` = las que el cambio movía y
     * se dejaron como estaban (editadas a mano con `salvo_editadas`, o todas con `ninguna`).
     */
    public function postAplicarCambios($id)
    {
        $user = $this->user;
        $act = $this->conNotasDelDueno($id);
        $aplicar = (string) (Request::input('aplicar') ?? 'salvo_editadas');

        if (! in_array($aplicar, self::SALIDAS, true)) {
            abort(422, 'Aplicar es todas, salvo_editadas o ninguna.');
        }

        Planilla::exigirAnioAbierto($act, $user);

        $cambios = (array) Request::input('cambios', []);
        [$notaMaxima, $preguntas] = $this->conLosCambios($act, $cambios);

        $cambiaron = [];

        $salida = DB::transaction(function () use ($act, $user, $aplicar, $notaMaxima, $preguntas, &$cambiaron) {
            // Con candado: dos «aplicar» a la vez no se cruzan, y un envío que llegue en medio espera.
            DB::select('SELECT id FROM ws_actividades WHERE id = ? FOR UPDATE', [$act->id]);

            $impacto = $this->impacto($act, $notaMaxima, $preguntas);
            $ahora = Actividad::ahora();

            // 1. El cambio: puntos y correctas de las preguntas, y la nota máxima.
            foreach ($preguntas as $p) {
                if (! $p['_cambia']) {
                    continue;
                }

                DB::update('UPDATE ws_preguntas SET puntos = ?, updated_at = ? WHERE id = ?', [$p['puntos'], $ahora, $p['id']]);

                if ($p['tipo'] === Interactivas::TIPO) {
                    DB::update('UPDATE ws_preguntas SET config = ? WHERE id = ?', [Retos::json($p['config']), $p['id']]);
                }

                foreach ($p['opciones'] as $o) {
                    DB::update('UPDATE ws_opciones SET is_correct = ?, updated_at = ? WHERE id = ?',
                        [$o['is_correct'] ? 1 : 0, $ahora, $o['id']]);
                }
            }

            DB::update('UPDATE ws_actividades SET nota_maxima = ?, updated_at = ? WHERE id = ?', [$notaMaxima, $ahora, $act->id]);

            // 2. Todas las hojas, con su puntaje y su nota nuevos.
            foreach ($impacto['hojas'] as $hojaId => $h) {
                DB::update(
                    'UPDATE ws_actividades_resueltas SET puntaje = ?, puntaje_max = ?, nota_calculada = ?, updated_at = ? WHERE id = ?',
                    [$h['puntaje'], $h['puntaje_max'], $h['nota'], $ahora, $hojaId]
                );
            }

            // 3. La planilla, según la salida elegida.
            $subunidad = $act->califica ? Planilla::subunidad((int) $act->id) : null;
            $aEscribir = [];
            $noTocadas = 0;

            foreach ($impacto['forma']['filas'] as $f) {
                $alumnoId = $f['alumno']['alumno_id'];

                // En la tarea la nota no cambia: cambia lo que vale en la planilla.
                $cambio = $act->modo === 'tarea' ? $f['antes_planilla'] !== $f['despues_planilla'] : $f['antes'] !== $f['despues'];

                if ($alumnoId !== null && $cambio) {
                    $cambiaron[] = (int) $alumnoId;
                }

                // Sólo las que el cambio mueve: a quien no le cambia la nota no se le toca la planilla,
                // ni con `todas` (pisaría una edición a mano que nada tiene que ver con el cambio).
                // En la escala del año, que es la de la planilla.
                if ($subunidad === null || $f['despues_planilla'] === null || $f['antes_planilla'] === $f['despues_planilla']
                    || $f['planilla'] === $f['despues_planilla']) {
                    continue;
                }

                if ($aplicar === 'ninguna' || ($aplicar === 'salvo_editadas' && $f['editada_a_mano'])) {
                    $noTocadas++;

                    continue;
                }

                $aEscribir[$alumnoId] = $f['despues_planilla'];
            }

            $actualizadas = $subunidad !== null && $aEscribir !== [] ? Planilla::escribir($subunidad, $aEscribir, $user) : 0;

            return ['actualizadas' => $actualizadas, 'no_tocadas' => $noTocadas];
        });

        if (Request::boolean('avisar') && $cambiaron !== []) {
            Avisos::aAlumnos($act, 'nota_cambiada', array_values(array_unique($cambiaron)));
        }

        return $salida;
    }

    // ------------------------------------------------------------------ el cálculo

    /**
     * Recalcula todas las hojas enviadas con las preguntas cambiadas.
     *
     * @return array{forma: array, hojas: array<int, array{puntaje: float, puntaje_max: float, nota: ?int}>}
     */
    private function impacto(object $act, int $notaMaxima, array $preguntas): array
    {
        if ($act->modo === 'tarea') {
            return $this->impactoDeTarea($act, $notaMaxima);
        }

        $hojas = DB::select(
            'SELECT id, alumno_id, nota_calculada FROM ws_actividades_resueltas
              WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL AND alumno_id IS NOT NULL
              ORDER BY alumno_id, id',
            [$act->id]
        );

        $respuestas = Respuestas::deHojas(array_map(fn ($h) => (int) $h->id, $hojas));
        $nuevas = [];
        $porAlumno = [];

        foreach ($hojas as $h) {
            $r = $respuestas[(int) $h->id] ?? [];
            $p = Calificador::puntuar($preguntas, Recorrido::visibles($preguntas, $r), $r);
            $nota = Calificador::nota($p['puntaje'], $p['puntaje_max'], $notaMaxima);

            $nuevas[(int) $h->id] = ['puntaje' => $p['puntaje'], 'puntaje_max' => $p['puntaje_max'], 'nota' => $nota];

            // Con varios intentos cuenta la mejor, antes y después (§2.8).
            $a = (int) $h->alumno_id;
            $porAlumno[$a]['antes'] = $this->mejor($porAlumno[$a]['antes'] ?? null, $h->nota_calculada === null ? null : (int) $h->nota_calculada);
            $porAlumno[$a]['despues'] = $this->mejor($porAlumno[$a]['despues'] ?? null, $nota);
            $porAlumno[$a]['hay'] = true;
        }

        $subunidad = $act->califica ? Planilla::subunidad((int) $act->id) : null;
        $notas = $subunidad ? Planilla::notasDe((int) $subunidad->id) : [];
        $minima = (int) (DB::selectOne('SELECT nota_minima_aceptada FROM years WHERE id = ?', [(int) $act->year_id])->nota_minima_aceptada ?? 0);
        $personas = $this->personas($act, array_keys($porAlumno));

        $filas = [];
        $cambian = 0;
        $editadas = 0;
        $cruzan = 0;

        foreach ($porAlumno as $alumnoId => $a) {
            $nota = $notas[$alumnoId] ?? null;
            // Antes, sobre la nota máxima de ahora; después, sobre la del cambio. La mínima y la
            // planilla van en la escala del año.
            $antesEnEscala = Planilla::aLaEscala($act, $a['antes']);
            $despuesEnEscala = Planilla::aLaEscala($act, $a['despues'], $notaMaxima);
            $editada = $subunidad !== null && Planilla::editada($nota, $subunidad, $antesEnEscala);
            $cruza = null;

            if ($antesEnEscala !== null && $despuesEnEscala !== null) {
                if ($antesEnEscala >= $minima && $despuesEnEscala < $minima) {
                    $cruza = 'baja';
                } elseif ($antesEnEscala < $minima && $despuesEnEscala >= $minima) {
                    $cruza = 'sube';
                }
            }

            $cambian += $a['antes'] !== $a['despues'] ? 1 : 0;
            $editadas += $editada ? 1 : 0;
            $cruzan += $cruza !== null ? 1 : 0;

            $filas[] = [
                'alumno' => $personas[$alumnoId],
                'antes' => $a['antes'],
                'despues' => $a['despues'],
                'antes_planilla' => $antesEnEscala,
                'despues_planilla' => $despuesEnEscala,
                'planilla' => $nota && $nota->nota !== null ? (int) $nota->nota : null,
                'editada_a_mano' => $editada,
                'cruza' => $cruza,
            ];
        }

        return [
            'forma' => ['filas' => $filas, 'cambian' => $cambian, 'editadas_a_mano' => $editadas, 'cruzan_minima' => $cruzan],
            'hojas' => $nuevas,
        ];
    }

    /**
     * El impacto en una tarea: una fila por entrega calificada, con su nota (la misma antes y después)
     * y lo que vale en la planilla con la nota máxima de ahora y con la del cambio.
     *
     * @return array{forma: array, hojas: array}
     */
    private function impactoDeTarea(object $act, int $notaMaxima): array
    {
        $entregas = DB::select(
            'SELECT alumno_id, nota FROM ws_entregas WHERE actividad_id = ? AND nota IS NOT NULL ORDER BY alumno_id',
            [$act->id]
        );

        $subunidad = $act->califica ? Planilla::subunidad((int) $act->id) : null;
        $notas = $subunidad ? Planilla::notasDe((int) $subunidad->id) : [];
        $minima = (int) (DB::selectOne('SELECT nota_minima_aceptada FROM years WHERE id = ?', [(int) $act->year_id])->nota_minima_aceptada ?? 0);
        $personas = $this->personas($act, array_map(fn ($e) => (int) $e->alumno_id, $entregas));

        $filas = [];
        $cambian = 0;
        $editadas = 0;
        $cruzan = 0;

        foreach ($entregas as $e) {
            $alumnoId = (int) $e->alumno_id;
            $nota = $notas[$alumnoId] ?? null;
            $antesEnEscala = Planilla::aLaEscala($act, (int) $e->nota);
            $despuesEnEscala = Planilla::aLaEscala($act, (int) $e->nota, $notaMaxima);
            $editada = $subunidad !== null && Planilla::editada($nota, $subunidad, $antesEnEscala);
            $cruza = null;

            if ($antesEnEscala >= $minima && $despuesEnEscala < $minima) {
                $cruza = 'baja';
            } elseif ($antesEnEscala < $minima && $despuesEnEscala >= $minima) {
                $cruza = 'sube';
            }

            $cambian += $antesEnEscala !== $despuesEnEscala ? 1 : 0;
            $editadas += $editada ? 1 : 0;
            $cruzan += $cruza !== null ? 1 : 0;

            $filas[] = [
                'alumno' => $personas[$alumnoId],
                'antes' => (int) $e->nota,
                'despues' => (int) $e->nota,
                'antes_planilla' => $antesEnEscala,
                'despues_planilla' => $despuesEnEscala,
                'planilla' => $nota && $nota->nota !== null ? (int) $nota->nota : null,
                'editada_a_mano' => $editada,
                'cruza' => $cruza,
            ];
        }

        return [
            'forma' => ['filas' => $filas, 'cambian' => $cambian, 'editadas_a_mano' => $editadas, 'cruzan_minima' => $cruzan],
            'hojas' => [],
        ];
    }

    private function mejor(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /**
     * Valida `CambiosConNota` y devuelve la nota máxima y las preguntas como quedarían, cada una con
     * `_cambia`. 422 si nombra una pregunta que no es de la actividad, una opción que no es de la
     * pregunta, o deja una pregunta calificable sin correcta.
     *
     * @return array{0: int, 1: array<int, array>}
     */
    private function conLosCambios(object $act, array $cambios): array
    {
        $escala = Actividad::maximoDeLaEscala((int) $act->year_id);
        $notaMaxima = (int) ($act->nota_maxima ?? $escala);

        if (array_key_exists('nota_maxima', $cambios) && $cambios['nota_maxima'] !== null) {
            $n = Destinatarios::entero($cambios['nota_maxima']);

            if ($n === null || $n < 1 || $n > $escala) {
                abort(422, "La nota máxima va de 1 a {$escala}, la escala del colegio.");
            }

            $notaMaxima = $n;
        }

        if ($act->modo === 'tarea') {
            if ((array) ($cambios['preguntas'] ?? []) !== []) {
                abort(422, 'En una tarea sólo cambia la nota máxima: sus preguntas no se califican.');
            }

            $mayor = DB::selectOne('SELECT MAX(nota) AS n FROM ws_entregas WHERE actividad_id = ? AND nota IS NOT NULL', [$act->id])->n;

            if ($mayor !== null && (int) $mayor > $notaMaxima) {
                abort(422, "Ya hay una entrega calificada con {$mayor}: la nota máxima no puede quedar por debajo.");
            }

            return [$notaMaxima, []];
        }

        $preguntas = Formas::preguntas((int) $act->id);
        $indice = [];

        foreach ($preguntas as $i => $p) {
            $preguntas[$i]['_cambia'] = false;
            $indice[$p['id']] = $i;
        }

        foreach ((array) ($cambios['preguntas'] ?? []) as $i => $c) {
            $pid = is_array($c) ? Destinatarios::entero($c['id'] ?? null) : null;

            if ($pid === null || ! isset($indice[$pid])) {
                abort(422, 'Una de las preguntas no es de esta actividad.');
            }

            $p = &$preguntas[$indice[$pid]];
            $esInteractiva = $p['tipo'] === Interactivas::TIPO;
            $calificable = in_array($p['tipo'], Recorrido::DE_OPCIONES, true) || $p['tipo'] === 'corta' || $esInteractiva;

            // Tanda 7: en una interactiva, «cambiar la correcta» es cambiar su reto (el cfg).
            if (array_key_exists('config', $c) && $c['config'] !== null) {
                if (! $esInteractiva) {
                    abort(422, 'La pregunta '.$p['orden'].' no es interactiva: no lleva configuración de reto.');
                }

                $p['config'] = Interactivas::config(Interactivas::configValida(
                    Interactivas::crudo(['cambios', 'preguntas', $i, 'config'], $c['config'])));
                $p['_cambia'] = true;
            }

            if ($esInteractiva && array_key_exists('correctas', $c) && $c['correctas'] !== null) {
                abort(422, 'La pregunta '.$p['orden'].' es interactiva: su respuesta correcta se cambia con «config».');
            }

            if (array_key_exists('puntos', $c) && $c['puntos'] !== null) {
                $puntos = Destinatarios::entero($c['puntos']);

                if ($puntos === null || $puntos < 0 || $puntos > 1000) {
                    abort(422, 'Los puntos de una pregunta van de 0 a 1000.');
                }

                if (! $calificable && $puntos > 0) {
                    abort(422, 'La pregunta '.$p['orden'].' no se califica: no lleva puntos.');
                }

                $p['puntos'] = $puntos;
                $p['_cambia'] = true;
            }

            if (array_key_exists('correctas', $c) && $c['correctas'] !== null) {
                if (! $calificable) {
                    abort(422, 'La pregunta '.$p['orden'].' no tiene respuesta correcta.');
                }

                $correctas = array_values(array_unique(array_map('intval', (array) $c['correctas'])));
                $deLaPregunta = array_map(fn ($o) => (int) $o['id'], $p['opciones']);

                if (array_diff($correctas, $deLaPregunta) !== []) {
                    abort(422, 'Una de las correctas no es opción de la pregunta '.$p['orden'].'.');
                }

                if ($correctas === [] || (in_array($p['tipo'], self::UNA_CORRECTA, true) && count($correctas) !== 1)) {
                    abort(422, in_array($p['tipo'], self::UNA_CORRECTA, true)
                        ? 'La pregunta '.$p['orden'].' lleva exactamente una correcta.'
                        : 'La pregunta '.$p['orden'].' necesita al menos una correcta.');
                }

                foreach ($p['opciones'] as $k => $o) {
                    $p['opciones'][$k]['is_correct'] = in_array((int) $o['id'], $correctas, true);
                }

                $p['_cambia'] = true;
            }

            unset($p);
        }

        return [$notaMaxima, $preguntas];
    }

    /** @return array<int, array> `PersonaCorta` por alumno */
    private function personas(object $act, array $alumnoIds): array
    {
        $personas = [];

        foreach (Destinatarios::resolver($act) as $e) {
            if ($e['publico'] === 'alumno' && in_array($e['alumno_id'], $alumnoIds, true)) {
                $personas[$e['alumno_id']] = Formas::personaDeEntrada($e);
            }
        }

        // El que respondió y ya no está en el grupo (retirado, cambiado) sale igual, con su nombre.
        foreach (array_diff($alumnoIds, array_keys($personas)) as $alumnoId) {
            $a = DB::selectOne('SELECT user_id, nombres, apellidos FROM alumnos WHERE id = ?', [$alumnoId]);
            $personas[$alumnoId] = Formas::persona(
                $a && $a->user_id !== null ? (int) $a->user_id : null,
                $alumnoId,
                $a ? Destinatarios::nombre($a->nombres, $a->apellidos) : 'Alumno '.$alumnoId,
                null
            );
        }

        return $personas;
    }

    private function conNotasDelDueno($id): object
    {
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $this->user);

        if (! in_array($act->modo, ['cuestionario', 'tarea'], true)) {
            abort(422, 'Editar con notas es de los cuestionarios y las tareas: una encuesta no tiene notas.');
        }

        return $act;
    }
}
