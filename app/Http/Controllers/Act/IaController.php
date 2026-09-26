<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Formas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;

/**
 * La IA de las actividades (tanda 4, contrato §3.15): proponer preguntas y mejorar una
 * conversando.
 *
 * Mismo patrón que `App\Http\Controllers\IaController::postPlantilla`: el navegador **no manda
 * un prompt ni el contexto**; manda qué actividad es y lo que pidió el docente. El grado, la
 * materia, el logro, el indicador, la escala y las preguntas que ya tiene salen de la base, de
 * la actividad **del dueño**. Se firma contra `myvc-ia-proxy` con el secreto del colegio, y el
 * proxy cuenta los usos (contador `actividades`) y corta en el tope.
 *
 * **Nada se guarda aquí.** Lo que vuelve son propuestas; el front las añade con
 * `act/{id}/preguntas/lote` o `act/preguntas/{pid}/guardar` cuando el docente elige.
 *
 * ## Por qué es un controlador aparte y no dos métodos más en `IaController`
 *
 * El árbol principal de `8myvc` tiene trabajo sin commitear en `IaController` (competencias y
 * `ia/uso`) cuando se escribió esto; dos ramas tocando el mismo fichero eran un conflicto seguro.
 * `alProxy` y `quienPide` son copia de los de allí: si uno cambia, el otro también.
 *
 * ## Quién puede
 *
 * El dueño de la actividad, sea docente o personal, en tareas, cuestionarios y encuestas
 * (decidido por Joseth el 26 sep). No hay permiso nuevo: el proxy decide por rol en su tablero.
 */
class IaController extends Controller
{
    use ResuelveElUsuario;

    /** Los tipos que la IA puede proponer. Los de imagen, video, archivo y fecha, no. */
    private const TIPOS = ['unica', 'multiple', 'sino', 'corta', 'parrafo', 'escala'];

    private const DIFICULTADES = ['facil', 'media', 'dificil', 'mezcla'];

    private const ATAJOS = ['vida_real', 'mas_dificil', 'cambiar_tipo', 'explicar'];

    /**
     * `POST ia/actividades/proponer` — `{actividad_id, cantidad, tema, tipos, dificultad,
     * indicaciones, conversacion}` → `{datos: {preguntas: PreguntaPropuesta[]}, costo, gastado,
     * tope, quedan}`.
     *
     * @return array<string, mixed>
     */
    public function postProponer(): array
    {
        $act = $this->actividadDelDueno(Request::input('actividad_id'));

        $tipos = Request::input('tipos', 'azar');
        if ($tipos !== 'azar') {
            $tipos = array_values(array_unique(array_filter(
                array_map('strval', (array) $tipos),
                static fn ($t) => in_array($t, self::TIPOS, true)
            )));
            if ($tipos === []) {
                abort(422, 'Elige al menos un tipo de pregunta, o «al azar».');
            }
        }

        $dificultad = (string) Request::input('dificultad', 'media');
        if (! in_array($dificultad, self::DIFICULTADES, true)) {
            abort(422, 'La dificultad no es válida.');
        }

        return $this->alProxy('actividades/proponer', [
            'contexto' => $this->contexto($act),
            'pedido' => [
                'cantidad' => max(1, min(20, (int) Request::input('cantidad', 5))),
                'tema' => $this->recortar(Request::input('tema'), 300),
                'tipos' => $tipos,
                'dificultad' => $dificultad,
                'indicaciones' => $this->recortar(Request::input('indicaciones'), 1000),
            ],
            'conversacion' => $this->conversacion(),
        ]);
    }

    /**
     * `POST ia/actividades/mejorar` — `{actividad_id, pregunta, atajo?, conversacion}` →
     * `{datos: {pregunta: PreguntaPropuesta, comentario}, costo, gastado, tope, quedan}`.
     * La pregunta vuelve **entera**, no un parche.
     *
     * @return array<string, mixed>
     */
    public function postMejorar(): array
    {
        $act = $this->actividadDelDueno(Request::input('actividad_id'));

        $atajo = Request::input('atajo');
        if ($atajo !== null && ! in_array($atajo, self::ATAJOS, true)) {
            abort(422, 'Ese atajo no existe.');
        }

        $pregunta = $this->preguntaDeLaPantalla((array) Request::input('pregunta', []));
        if ($pregunta['enunciado'] === '') {
            abort(422, 'Falta la pregunta que se quiere mejorar.');
        }

        return $this->alProxy('actividades/mejorar', array_filter([
            'contexto' => $this->contexto($act),
            'pregunta' => $pregunta,
            'atajo' => $atajo,
            'conversacion' => $this->conversacion(),
        ], static fn ($v) => $v !== null));
    }

    /**
     * Lo que el modelo sabe de la actividad, y sale de la base, no de la petición.
     *
     * @return array<string, mixed>
     */
    private function contexto(object $act): array
    {
        $clase = null;
        if ($act->asignatura_id !== null) {
            $clase = DB::selectOne(
                'SELECT m.materia, gr.nombre AS grado, g.nombre AS grupo
                   FROM asignaturas a
                   INNER JOIN materias m ON m.id = a.materia_id
                   INNER JOIN grupos g ON g.id = a.grupo_id
                   INNER JOIN grados gr ON gr.id = g.grado_id
                  WHERE a.id = ?',
                [(int) $act->asignatura_id]
            );
        } elseif ($act->grupo_id !== null) {
            $clase = DB::selectOne(
                'SELECT NULL AS materia, gr.nombre AS grado, g.nombre AS grupo
                   FROM grupos g INNER JOIN grados gr ON gr.id = g.grado_id WHERE g.id = ?',
                [(int) $act->grupo_id]
            );
        }

        $logro = $act->unidad_id === null ? null : DB::selectOne(
            'SELECT definicion FROM unidades WHERE id = ? AND deleted_at IS NULL',
            [(int) $act->unidad_id]
        );

        // El indicador existe desde que se publica (la subunidad enlazada, §2.8).
        $indicador = DB::selectOne(
            'SELECT definicion FROM subunidades WHERE actividad_id = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $act->id]
        );

        $maxima = (int) ($act->nota_maxima ?: Actividad::maximoDeLaEscala((int) $act->year_id));

        return array_filter([
            'modo' => $act->modo,
            'titulo' => $this->recortar($act->titulo, 160),
            'instrucciones' => $this->recortar(html_entity_decode(strip_tags((string) $act->instrucciones)), 600),
            'responden' => $act->responden,
            'materia' => $clase->materia ?? null,
            'grado' => $clase->grado ?? null,
            'grupo' => $clase->grupo ?? null,
            'logro' => $logro ? $this->recortar($logro->definicion, 600) : null,
            'indicador' => $indicador ? $this->recortar($indicador->definicion, 300) : null,
            'escala' => [
                'minima' => $this->user->nota_minima_aceptada ?? null,
                'maxima' => $maxima,
            ],
            // Para no repetir: los enunciados que ya tiene, en orden.
            'existentes' => array_slice(array_values(array_filter(array_map(
                fn ($p) => $this->recortar($p['enunciado'], 300),
                Formas::preguntas((int) $act->id)
            ))), 0, 60),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * La pregunta que manda la pantalla, reducida a los campos de `PreguntaPropuesta`: el resto
     * (ids, imagen, condiciones) no le sirve al modelo y no tiene por qué viajar.
     *
     * @return array<string, mixed>
     */
    private function preguntaDeLaPantalla(array $p): array
    {
        return [
            'tipo' => in_array($p['tipo'] ?? null, self::TIPOS, true) ? $p['tipo'] : 'unica',
            'enunciado' => $this->recortar($p['enunciado'] ?? '', 2000) ?? '',
            'opciones' => array_slice(array_map(fn ($o) => [
                'definicion' => $this->recortar(((array) $o)['definicion'] ?? '', 500) ?? '',
                'is_correct' => (bool) (((array) $o)['is_correct'] ?? false),
                'error_tipico' => $this->recortar(((array) $o)['error_tipico'] ?? null, 300),
            ], array_values((array) ($p['opciones'] ?? []))), 0, 12),
            'puntos' => max(0, min(100, (int) ($p['puntos'] ?? 1))),
            'puntaje_parcial' => (bool) ($p['puntaje_parcial'] ?? false),
            'explicacion' => $this->recortar($p['explicacion'] ?? null, 1000),
            'dificultad' => in_array($p['dificultad'] ?? null, ['facil', 'media', 'dificil'], true) ? $p['dificultad'] : 'media',
            'escala_estilo' => $p['escala_estilo'] ?? null,
            'texto_arriba' => $this->recortar($p['texto_arriba'] ?? null, 100),
            'texto_abajo' => $this->recortar($p['texto_abajo'] ?? null, 100),
        ];
    }

    /** @return list<array{rol: string, texto: string}> los últimos 20 turnos, con tope de largo */
    private function conversacion(): array
    {
        $turnos = [];
        foreach ((array) Request::input('conversacion', []) as $t) {
            $t = (array) $t;
            $turnos[] = [
                'rol' => ($t['rol'] ?? '') === 'ia' ? 'ia' : 'usuario',
                'texto' => (string) $this->recortar($t['texto'] ?? '', 20000),
            ];
        }

        return array_slice($turnos, -20);
    }

    private function recortar($texto, int $largo): ?string
    {
        if ($texto === null || is_array($texto)) {
            return null;
        }
        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }

    private function actividadDelDueno($id): object
    {
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $this->user);

        return $act;
    }

    /** Copia de `IaController::quienPide`: el proxy reparte la IA por rol y cuenta por usuario. */
    private function quienPide(): array
    {
        $nombre = trim(($this->user->nombres ?? '') . ' ' . ($this->user->apellidos ?? ''));

        return [
            'id' => (string) ($this->user->user_id ?? ''),
            'nombre' => $nombre !== '' ? $nombre : (string) ($this->user->username ?? ''),
            'roles' => array_values(array_map(
                static fn ($rol) => (string) (is_array($rol) ? ($rol['name'] ?? '') : ($rol->name ?? '')),
                (array) ($this->user->roles ?? []),
            )),
            'superusuario' => (bool) ($this->user->is_superuser ?? false),
        ];
    }

    /** Copia de `IaController::alProxy`: 503 sin configurar, 403/429 con su frase, 502 lo demás. */
    private function alProxy(string $ruta, array $cuerpo): array
    {
        $url = (string) config('services.ia.url');
        $secreto = (string) config('services.ia.secreto');

        if ($url === '' || $secreto === '') {
            abort(503, 'Este colegio todavía no tiene activada la ayuda de IA.');
        }

        // Proponer varias preguntas pasa de los 30 s de PHP (medido: 32 s con cuatro); cortar
        // aquí sería un 502 con la llamada ya pagada en el proxy. El proxy puede tardar hasta 2 × 150 s.
        set_time_limit(320);

        try {
            $respuesta = Http::withToken($secreto)
                ->timeout(300)
                ->acceptJson()
                ->post(rtrim($url, '/') . '/' . $ruta, $cuerpo + ['usuario' => $this->quienPide()]);
        } catch (\Throwable $fallo) {
            error_log('[ia] el proxy no contestó a ' . $ruta . ': ' . $fallo->getMessage());
            abort(502, 'La IA no contestó. Vuelve a intentarlo en un momento.');
        }

        if ($respuesta->failed()) {
            $conFrase = in_array($respuesta->status(), [403, 429], true);
            $motivo = $conFrase
                ? ($respuesta->json('error') ?? 'La ayuda de IA no está disponible.')
                : 'La IA no contestó. Vuelve a intentarlo en un momento.';

            abort($conFrase ? $respuesta->status() : 502, $motivo);
        }

        return (array) $respuesta->json();
    }
}
