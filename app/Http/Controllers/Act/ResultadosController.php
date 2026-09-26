<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Calificador;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Recorrido;
use App\Services\Act\Respuestas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * LO QUE VE QUIEN CREÓ LA ACTIVIDAD (Y LOS DIRECTIVOS): resultados y quién falta.
 *
 * Contrato §3.10. Dueño o directivo; ninguno de los dos escribe nada aquí.
 *
 * ## Aquí vive el anonimato  *(Joseth, 26 sep 2026; manda sobre §2.6)*
 *
 * La base guarda siempre quién respondió qué. **Lo anónimo es lo que sale de este controlador**, y
 * es obligatorio que no se filtre nada que identifique a nadie en una actividad `seguimiento` o
 * `total`:
 *
 *   - los textos libres van sin autor y sin grupo, y ordenados alfabéticamente —no en el orden en
 *     que se escribieron, que con la hora de la bandeja diría de quién es cada uno—;
 *   - ninguna hora por persona;
 *   - k-anonimato: una celda de desglose (grupo, grado, público) con menos de 5 hojas sale
 *     `oculto: true` y sin cifras, y con menos de 5 hojas en el filtro tampoco hay textos ni
 *     conteos por pregunta —con un filtro de 1 alumno, «1 marcó la B» es su respuesta—;
 *   - `faltan`: en `seguimiento` sí (es lo que ese nivel promete: saber quién falta para
 *     recordarle); en `total`, 409.
 */
class ResultadosController extends Controller
{
    use ResuelveElUsuario;

    /** Por debajo de esto, una celda anónima no se desglosa (§2.6). */
    private const K = 5;

    private const PUBLICOS = ['alumno' => 'Alumnos', 'acudiente' => 'Acudientes', 'personal' => 'Personal'];

    /** `GET act/{id}/resultados?grupo_id=&grado_id=&publico=` → `ResultadosAct`. */
    public function getResultados($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDuenoODirectivo($act, $user);

        $anonima = $act->anonimato !== 'nombre';
        $filtroGrupo = Destinatarios::entero(Request::input('grupo_id'));
        $filtroGrado = Destinatarios::entero(Request::input('grado_id'));
        $filtroPublico = Request::input('publico');
        $filtroPublico = isset(self::PUBLICOS[$filtroPublico]) ? $filtroPublico : null;

        $filas = Destinatarios::deActividad((int) $act->id);
        $entradas = array_values(array_filter(Destinatarios::resolver($act, $filas), fn ($e) => $e['user_id'] !== null));
        $gruposDelAlcance = Destinatarios::gruposDelAlcance((int) $act->year_id, $filas);
        $gradoDe = $this->gradosDe($gruposDelAlcance);

        $pasa = function (?int $grupo, ?string $publico) use ($filtroGrupo, $filtroGrado, $filtroPublico, $gradoDe) {
            return ($filtroGrupo === null || $grupo === $filtroGrupo)
                && ($filtroGrado === null || ($grupo !== null && ($gradoDe[$grupo] ?? null) === $filtroGrado))
                && ($filtroPublico === null || $publico === $filtroPublico);
        };

        $hojas = $this->hojasQueCuentan($act);
        $hojasFiltradas = array_values(array_filter($hojas, fn ($h) => $pasa($h->grupo_id === null ? null : (int) $h->grupo_id, $h->publico)));
        $entradasFiltradas = array_values(array_filter($entradas, fn ($e) => $pasa($e['grupo_id'], $e['publico'])));

        // En la tarea, «respondió» es entregar.
        if ($act->modo === 'tarea') {
            $entregaron = array_flip(array_map(fn ($f) => (int) $f->alumno_id, DB::select(
                'SELECT alumno_id FROM ws_entregas WHERE actividad_id = ? AND entregada_at IS NOT NULL', [$act->id])));
            $respondieron = count(array_filter($entradasFiltradas, fn ($e) => isset($entregaron[$e['alumno_id']])));
        } else {
            $respondieron = count($hojasFiltradas);
        }

        $destinatarios = count($entradasFiltradas);

        return [
            'actividad' => Formas::enBandeja($act, $user),
            'participacion' => [
                'destinatarios' => $destinatarios,
                'respondieron' => $respondieron,
                'porcentaje' => $destinatarios > 0 ? round($respondieron * 100 / $destinatarios, 1) : 0,
            ],
            'por_grupo' => $this->celdas($gruposDelAlcance, Destinatarios::nombresDeGrupos($gruposDelAlcance), $entradas, $hojas, $anonima,
                fn ($grupo, $publico, $clave) => $grupo === $clave),
            'por_grado' => $this->porGrado($gruposDelAlcance, $gradoDe, $entradas, $hojas, $anonima),
            'por_publico' => $this->celdas(array_keys(self::PUBLICOS), self::PUBLICOS, $entradas, $hojas, $anonima,
                fn ($grupo, $publico, $clave) => $publico === $clave, true),
            'preguntas' => $this->preguntas($act, $hojasFiltradas, $anonima, count($hojas)),
            'notas' => $this->notas($act, $hojasFiltradas),
        ];
    }

    /** `GET act/{id}/faltan` → `FaltanAct`. 409 en `total`: ahí no se sabe quién falta. */
    public function getFaltan($id)
    {
        $user = $this->user;
        $act = Actividad::cargar($id);
        Actividad::exigirDuenoODirectivo($act, $user);

        if ($act->anonimato === 'total') {
            abort(409, 'En una encuesta anónima del todo no se sabe quién falta.');
        }

        if ($act->modo === 'tarea') {
            $ya = array_flip(array_map(fn ($f) => 'alumno:'.$f->alumno_id, DB::select(
                'SELECT alumno_id FROM ws_entregas WHERE actividad_id = ? AND entregada_at IS NOT NULL', [$act->id])));
        } else {
            $ya = array_flip(array_map(fn ($f) => $f->user_id.':'.($f->alumno_id ?? 0), DB::select(
                'SELECT DISTINCT user_id, alumno_id FROM ws_actividades_resueltas
                  WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL', [$act->id])));
        }

        $grupos = [];
        $nombres = Destinatarios::nombresDeGrupos(Destinatarios::gruposDelAlcance((int) $act->year_id, Destinatarios::deActividad((int) $act->id)));

        foreach (Destinatarios::resolver($act) as $e) {
            if ($e['user_id'] === null) {
                continue;
            }

            $clave = $e['grupo_id'] ?? ('sin:'.$e['publico']);
            $grupos[$clave] ??= [
                'grupo_id' => $e['grupo_id'],
                'nombre' => $e['grupo_id'] !== null ? ($nombres[$e['grupo_id']] ?? 'Grupo '.$e['grupo_id']) : self::PUBLICOS[$e['publico']],
                'total' => 0,
                'faltan' => [],
            ];
            $grupos[$clave]['total']++;

            $respondio = $act->modo === 'tarea'
                ? isset($ya['alumno:'.$e['alumno_id']])
                : isset($ya[$e['user_id'].':'.($e['alumno_id'] ?? 0)]);

            if (! $respondio) {
                $grupos[$clave]['faltan'][] = Formas::personaDeEntrada($e);
            }
        }

        $lista = array_values($grupos);
        usort($lista, fn ($a, $b) => [$a['grupo_id'] === null, $a['nombre']] <=> [$b['grupo_id'] === null, $b['nombre']]);

        $ultimo = DB::selectOne("SELECT MAX(created_at) AS t FROM ws_avisos WHERE actividad_id = ? AND clase = 'recordatorio'", [$act->id]);

        return ['grupos' => $lista, 'ultimo_recordatorio_at' => $ultimo->t ?? null];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Las hojas que cuentan: una por persona. Con varios intentos, la mejor del cuestionario (la que
     * da la nota, §2.8) o la última en lo demás.
     *
     * @return list<object>
     */
    private function hojasQueCuentan(object $act): array
    {
        $porPersona = [];

        foreach (DB::select(
            'SELECT * FROM ws_actividades_resueltas WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL ORDER BY id',
            [$act->id]
        ) as $h) {
            $clave = $h->user_id.':'.($h->alumno_id ?? 0);
            $antes = $porPersona[$clave] ?? null;

            if ($antes === null || $act->modo !== 'cuestionario' || (int) $h->nota_calculada >= (int) $antes->nota_calculada) {
                $porPersona[$clave] = $h;
            }
        }

        return array_values($porPersona);
    }

    /**
     * Celdas de desglose (`CeldaAct[]`). `$es(grupo, publico, clave)` dice si una entrada u hoja cae
     * en la celda `clave`. Anónima y con menos de 5 hojas: `oculto`, sin cifra de respondieron.
     */
    private function celdas(array $claves, array $nombres, array $entradas, array $hojas, bool $anonima, callable $es, bool $soloConGente = false): array
    {
        $celdas = [];

        foreach ($claves as $clave) {
            $dest = count(array_filter($entradas, fn ($e) => $es($e['grupo_id'], $e['publico'], $clave)));
            $resp = count(array_filter($hojas, fn ($h) => $es($h->grupo_id === null ? null : (int) $h->grupo_id, $h->publico, $clave)));

            if ($soloConGente && $dest === 0 && $resp === 0) {
                continue;
            }

            $oculto = $anonima && $resp < self::K;
            $celdas[] = [
                'clave' => (string) $clave,
                'nombre' => (string) ($nombres[$clave] ?? $clave),
                'destinatarios' => $dest,
                'respondieron' => $oculto ? null : $resp,
                'oculto' => $oculto,
            ];
        }

        return $celdas;
    }

    private function porGrado(array $grupos, array $gradoDe, array $entradas, array $hojas, bool $anonima): array
    {
        $grados = array_values(array_unique(array_values(array_intersect_key($gradoDe, array_flip($grupos)))));

        if ($grados === []) {
            return [];
        }

        $nombres = [];

        foreach (DB::select('SELECT id, nombre FROM grados WHERE id IN ('.implode(',', array_fill(0, count($grados), '?')).') ORDER BY orden, nombre', $grados) as $g) {
            $nombres[(int) $g->id] = (string) $g->nombre;
        }

        return $this->celdas(array_keys($nombres), $nombres, $entradas, $hojas, $anonima,
            fn ($grupo, $publico, $clave) => $grupo !== null && ($gradoDe[$grupo] ?? null) === $clave);
    }

    /** @return array<int, int> grupo_id => grado_id */
    private function gradosDe(array $grupos): array
    {
        if ($grupos === []) {
            return [];
        }

        $mapa = [];

        foreach (DB::select('SELECT id, grado_id FROM grupos WHERE id IN ('.implode(',', array_fill(0, count($grupos), '?')).')', $grupos) as $g) {
            $mapa[(int) $g->id] = (int) $g->grado_id;
        }

        return $mapa;
    }

    /** `ResultadoDePregunta[]` sobre las hojas del filtro. */
    private function preguntas(object $act, array $hojas, bool $anonima, int $hojasTotales): array
    {
        $preguntas = Formas::preguntas((int) $act->id);
        $respuestas = Respuestas::deHojas(array_map(fn ($h) => (int) $h->id, $hojas));
        $esCuestionario = $act->modo === 'cuestionario';

        // k-anonimato: pocas hojas en el filtro → ninguna cifra ni texto por pregunta. Y con menos
        // de 5 en toda la encuesta, tampoco textos libres aunque el filtro sea amplio (§2.6).
        $oculto = $anonima && count($hojas) < self::K;
        $sinTextos = $oculto || ($anonima && $hojasTotales < self::K);

        $visiblesPorHoja = [];

        foreach ($hojas as $h) {
            $visiblesPorHoja[(int) $h->id] = array_flip(Recorrido::visibles($preguntas, $respuestas[(int) $h->id] ?? []));
        }

        $autores = $anonima ? [] : $this->autores($act, $hojas);
        $nombresDeGrupos = $anonima ? [] : Destinatarios::nombresDeGrupos(array_values(array_unique(array_filter(
            array_map(fn ($h) => $h->grupo_id === null ? null : (int) $h->grupo_id, $hojas)))));

        $resultado = [];

        foreach ($preguntas as $p) {
            $mostrada = 0;
            $respondida = 0;
            $porOpcion = [];
            $otra = 0;
            $escala = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $textos = [];
            $aciertos = 0;

            foreach ($hojas as $h) {
                $hid = (int) $h->id;

                if (! isset($visiblesPorHoja[$hid][$p['id']])) {
                    continue;
                }

                $mostrada++;
                $r = $respuestas[$hid][$p['id']] ?? null;

                if ($esCuestionario && Calificador::calificable($p) && Calificador::acerto($p, $r)) {
                    $aciertos++;
                }

                if (! Recorrido::respondida($p['tipo'], $r)) {
                    continue;
                }

                $respondida++;

                foreach ($r['opcion_ids'] as $o) {
                    $porOpcion[$o] = ($porOpcion[$o] ?? 0) + 1;
                }

                if ($p['tipo'] === 'escala' && $r['valor'] !== null && isset($escala[$r['valor']])) {
                    $escala[$r['valor']]++;
                }

                $texto = $p['tipo'] === 'fecha' ? $r['fecha'] : $r['texto'];

                if (in_array($p['tipo'], Recorrido::DE_OPCIONES, true) && $r['texto'] !== null) {
                    $otra++;
                }

                if ($texto !== null && $texto !== '' && in_array($p['tipo'], ['corta', 'parrafo', 'fecha', 'unica', 'multiple'], true)) {
                    $textos[] = [
                        'texto' => (string) $texto,
                        'autor' => $anonima ? null : ($autores[$h->user_id.':'.($h->alumno_id ?? 0)] ?? null),
                        'grupo' => $anonima || $h->grupo_id === null ? null : ($nombresDeGrupos[(int) $h->grupo_id] ?? null),
                    ];
                }
            }

            if ($anonima) {
                // Sin el orden de llegada: alfabético.
                usort($textos, fn ($a, $b) => strcmp($a['texto'], $b['texto']));
            }

            $opciones = [];

            if (in_array($p['tipo'], Recorrido::DE_OPCIONES, true)) {
                foreach ($p['opciones'] as $o) {
                    $fila = ['opcion_id' => $o['id'], 'definicion' => $o['definicion'], 'n' => $porOpcion[$o['id']] ?? 0];

                    if ($esCuestionario) {
                        $fila['es_correcta'] = $o['is_correct'];
                    }

                    $opciones[] = $fila;
                }

                if ($p['opcion_otra']) {
                    $opciones[] = ['opcion_id' => null, 'definicion' => 'Otra', 'n' => $otra];
                }
            }

            $votosEscala = array_sum($escala);
            $conTextos = in_array($p['tipo'], ['corta', 'parrafo', 'fecha'], true) || $p['opcion_otra'];

            $resultado[] = [
                'pregunta_id' => $p['id'],
                'tipo' => $p['tipo'],
                'enunciado' => $p['enunciado'],
                'mostrada_a' => $oculto ? 0 : $mostrada,
                'respondida_por' => $oculto ? 0 : $respondida,
                'oculto' => $oculto,
                'opciones' => $oculto ? [] : $opciones,
                'escala' => $oculto || $p['tipo'] !== 'escala' ? null
                    : array_map(fn ($v, $n) => ['valor' => $v, 'n' => $n], array_keys($escala), array_values($escala)),
                'promedio' => $oculto || $p['tipo'] !== 'escala' || $votosEscala === 0 ? null
                    : round(array_sum(array_map(fn ($v, $n) => $v * $n, array_keys($escala), array_values($escala))) / $votosEscala, 2),
                'textos' => $sinTextos || ! $conTextos ? null : $textos,
                'aciertos' => $oculto || ! $esCuestionario || ! Calificador::calificable($p) ? null : $aciertos,
            ];
        }

        return $resultado;
    }

    /** `PersonaCorta` de cada hoja con nombre, por `user_id:alumno_id`. */
    private function autores(object $act, array $hojas): array
    {
        $autores = [];

        foreach (Destinatarios::resolver($act) as $e) {
            if ($e['user_id'] !== null) {
                $autores[$e['user_id'].':'.($e['alumno_id'] ?? 0)] = Formas::personaDeEntrada($e);
            }
        }

        // Quien respondió y ya no está en el alcance (se cambió de grupo) sigue teniendo nombre.
        foreach ($hojas as $h) {
            $clave = $h->user_id.':'.($h->alumno_id ?? 0);

            if (! isset($autores[$clave]) && $h->user_id !== null) {
                $autores[$clave] = Formas::personaDeUsuario((int) $h->user_id);
            }
        }

        return $autores;
    }

    /** Promedio, aprobados y reprobados del cuestionario, sobre la mejor nota de cada uno. */
    private function notas(object $act, array $hojas): ?array
    {
        if ($act->modo !== 'cuestionario') {
            return null;
        }

        $notas = array_values(array_map(fn ($h) => (int) $h->nota_calculada, array_filter($hojas, fn ($h) => $h->nota_calculada !== null)));

        if ($notas === []) {
            return null;
        }

        // La mínima es de la escala del año; la nota está sobre `nota_maxima`, que puede ser menor.
        $minima = (int) (DB::selectOne('SELECT nota_minima_aceptada AS m FROM years WHERE id = ?', [$act->year_id])->m ?? 0);
        $maxEscala = Actividad::maximoDeLaEscala((int) $act->year_id);
        $notaMaxima = max(1, (int) $act->nota_maxima);
        $aprobaron = count(array_filter($notas, fn ($n) => $n * $maxEscala / $notaMaxima >= $minima));

        return [
            'promedio' => round(array_sum($notas) / count($notas), 1),
            'aprobaron' => $aprobaron,
            'reprobaron' => count($notas) - $aprobaron,
        ];
    }
}
