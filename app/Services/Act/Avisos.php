<?php

namespace App\Services\Act;

use App\Support\Reloj;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LOS AVISOS DE LAS ACTIVIDADES: la bandeja de salida `ws_avisos` (contrato tanda 5).
 *
 * Aquí sólo se ENCOLA: una fila por destinatario y hecho. El push lo manda después
 * `EnviarNotificaciones` (fuente `actividades`, marca = último `id`), y la campana web lee las mismas
 * filas (`AvisosController`). Nada de esto llama a un tercero dentro de la petición.
 *
 * ## Una fila, un destino
 *
 *   user_id NULL, alumno_id   → el TEMA DEL ALUMNO (`a_…_actividad`): le llega al alumno y a sus
 *                               acudientes, que están apuntados a los temas del hijo.
 *   user_id                   → el TEMA DE LA PERSONA (`u_…_actividad`): personal, y el acudiente
 *                               cuando la actividad es para él (con `alumno_id` = el hijo por el que
 *                               responde, sólo para el texto y el enlace).
 *
 * Se resuelve al encolar, con `Destinatarios` (el servicio único): quien entra en un grupo después
 * no recibe el aviso viejo, y el envío no vuelve a resolver nada.
 *
 * ## El anonimato es de presentación, y un aviso también presenta
 *
 * Ningún aviso le dice a un creador quién respondió: `entregada` sólo existe en la tarea (que nunca
 * es anónima), y los de «falta tu respuesta» van a quien falta, no al creador. En `total` ni eso:
 * recordatorio y «cierra pronto» van a todos, porque ahí el colegio no dice quién falta (§2.6).
 */
class Avisos
{
    public const CLASES = ['publicada', 'recordatorio', 'por_cerrar', 'por_aprobar', 'aprobada',
        'rechazada', 'calificada', 'resultados', 'nota_cambiada', 'entregada'];

    /** «Recordar» al mismo grupo otra vez antes de esto es 429 (§3.10). */
    public const HORAS_ENTRE_RECORDATORIOS = 12;

    // ------------------------------------------------------------------ encolar

    /**
     * Escribe las filas. `$filas`: `{user_id, alumno_id, grupo_id}`; se quitan las repetidas.
     *
     * @return int cuántas
     */
    public static function encolar(int $actividadId, string $clase, array $filas): int
    {
        if (! in_array($clase, self::CLASES, true)) {
            throw new \InvalidArgumentException('Clase de aviso desconocida: '.$clase);
        }

        $ahora = Actividad::ahora();
        $unicas = [];

        foreach ($filas as $f) {
            $u = $f['user_id'] ?? null;
            $a = $f['alumno_id'] ?? null;

            if ($u === null && $a === null) {
                continue;
            }

            $unicas[($u ?? '-').':'.($a ?? '-')] = [
                'actividad_id' => $actividadId,
                'clase' => $clase,
                'user_id' => $u,
                'alumno_id' => $a,
                'grupo_id' => $f['grupo_id'] ?? null,
                'created_at' => $ahora,
            ];
        }

        foreach (array_chunk(array_values($unicas), 500) as $trozo) {
            DB::table('ws_avisos')->insert($trozo);
        }

        return count($unicas);
    }

    /** La fila de una entrada de `Destinatarios::resolver` (ver la cabecera). */
    public static function filaDe(array $e): array
    {
        return match ($e['publico']) {
            'alumno' => ['user_id' => null, 'alumno_id' => $e['alumno_id'], 'grupo_id' => $e['grupo_id']],
            'acudiente' => ['user_id' => $e['user_id'], 'alumno_id' => $e['alumno_id'], 'grupo_id' => $e['grupo_id']],
            default => ['user_id' => $e['user_id'], 'alumno_id' => null, 'grupo_id' => null],
        };
    }

    /**
     * Publicada (o aprobada, que la publica): a todos sus destinatarios, si se pidió avisar y ya está
     * abierta. Una programada no se avisa aquí: la encola `delReloj()` cuando llega su hora.
     */
    public static function alPublicar(object $act): int
    {
        if (! (bool) $act->avisar_al_publicar || Actividad::estado($act) !== 'abierta') {
            return 0;
        }

        return self::encolar((int) $act->id, 'publicada', array_map([self::class, 'filaDe'], Destinatarios::resolver($act)));
    }

    /** Por aprobar: a los directivos (§2.3), menos quien la creó. */
    public static function porAprobar(object $act): int
    {
        $directivos = Destinatarios::resolver($act, [[
            'publico' => 'directivos', 'grupo_id' => null, 'grado_id' => null, 'asignatura_id' => null, 'user_id' => null,
        ]]);

        $filas = [];

        foreach ($directivos as $e) {
            if ($e['user_id'] !== (int) $act->created_by) {
                $filas[] = self::filaDe($e);
            }
        }

        return self::encolar((int) $act->id, 'por_aprobar', $filas);
    }

    /** Aprobada, rechazada, entregada: a quien la creó. `$alumnoId` sólo en `entregada`. */
    public static function alCreador(object $act, string $clase, ?int $alumnoId = null): int
    {
        return self::encolar((int) $act->id, $clase, [['user_id' => (int) $act->created_by, 'alumno_id' => $alumnoId, 'grupo_id' => null]]);
    }

    /** Calificada o nota cambiada: al tema del alumno (él y sus acudientes). */
    public static function aAlumnos(object $act, string $clase, array $alumnoIds): int
    {
        return self::encolar((int) $act->id, $clase,
            array_map(fn ($id) => ['user_id' => null, 'alumno_id' => (int) $id, 'grupo_id' => null], $alumnoIds));
    }

    /**
     * Resultados compartidos (§2.10): a quien respondió, o con `todos` a todo destinatario con
     * cuenta. Una vez por actividad: volver a compartir no vuelve a avisar.
     */
    public static function resultados(object $act): int
    {
        if ($act->modo !== 'encuesta' || ! in_array($act->comparte_resultados, ['respondieron', 'todos'], true)
            || self::yaHay((int) $act->id, 'resultados')) {
            return 0;
        }

        $ya = Destinatarios::respondieron($act);
        $filas = [];

        foreach (Destinatarios::resolver($act) as $e) {
            if ($e['user_id'] === null) {
                continue;
            }

            if ($act->comparte_resultados === 'respondieron' && ! Destinatarios::respondio($act, $e, $ya)) {
                continue;
            }

            $filas[] = self::filaDe($e);
        }

        return self::encolar((int) $act->id, 'resultados', $filas);
    }

    /**
     * Las entradas a quien le falta responder, con cuenta. En `total` todas: ahí no se sabe quién
     * falta (§2.6), y el recordatorio va a todos.
     *
     * @return list<array<string, mixed>>
     */
    public static function faltan(object $act): array
    {
        $ya = $act->anonimato === 'total' ? [] : Destinatarios::respondieron($act);

        return array_values(array_filter(Destinatarios::resolver($act),
            fn ($e) => $e['user_id'] !== null && ($act->anonimato === 'total' || ! Destinatarios::respondio($act, $e, $ya))));
    }

    /**
     * `act/{id}/recordar`: a quien falta, de un grupo o de todos. 429 si ese grupo ya se recordó
     * hace menos de 12 h; sin grupo, se saltan los recordados hace poco y es 429 si eran todos.
     *
     * @return int avisados
     */
    public static function recordar(object $act, ?int $grupoId): int
    {
        $desde = Reloj::ahora()->subHours(self::HORAS_ENTRE_RECORDATORIOS)->format('Y-m-d H:i:s');
        $recientes = [];

        foreach (DB::select(
            "SELECT DISTINCT grupo_id FROM ws_avisos WHERE actividad_id = ? AND clase = 'recordatorio' AND created_at >= ?",
            [$act->id, $desde]
        ) as $r) {
            $recientes[$r->grupo_id === null ? 'sin' : (int) $r->grupo_id] = true;
        }

        if ($grupoId !== null && isset($recientes[$grupoId])) {
            abort(429, 'A ese grupo ya se le recordó hace menos de '.self::HORAS_ENTRE_RECORDATORIOS.' horas.');
        }

        $faltan = self::faltan($act);

        if ($grupoId !== null) {
            $faltan = array_filter($faltan, fn ($e) => $e['grupo_id'] === $grupoId);
        }

        $quedan = array_filter($faltan, fn ($e) => ! isset($recientes[$e['grupo_id'] ?? 'sin']));

        if ($faltan !== [] && $quedan === []) {
            abort(429, 'Ya se les recordó hace menos de '.self::HORAS_ENTRE_RECORDATORIOS.' horas.');
        }

        return self::encolar((int) $act->id, 'recordatorio', array_map([self::class, 'filaDe'], $quedan));
    }

    // ------------------------------------------------------------------ lo que encola el reloj

    /**
     * Lo que no nace de una petición sino de la hora, y lo corre `notificaciones:enviar` en cada
     * pasada (el cron de cada colegio, cada 15 min) ANTES de mirar si hay Firebase: la campana web
     * lo necesita aunque el colegio no tenga push.
     *
     *   publicada    las programadas cuya `publica_at` llegó (en el último día);
     *   por_cerrar   `recordar_horas_antes` antes de `cierra_at`, a quien falta;
     *   resultados   las encuestas que se cerraron solas por la fecha y comparten.
     *
     * Cada una una sola vez por actividad: se mira si ya hay filas de esa clase. En seco no escribe.
     *
     * @return array{publicada: int, por_cerrar: int, resultados: int}
     */
    public static function delReloj(bool $seco = false): array
    {
        $ahora = Actividad::ahora();
        $ayer = Reloj::ahora()->subDay()->format('Y-m-d H:i:s');
        $cuenta = ['publicada' => 0, 'por_cerrar' => 0, 'resultados' => 0];

        $programadas = DB::select(
            "SELECT a.* FROM ws_actividades a
              WHERE a.modo IS NOT NULL AND a.deleted_at IS NULL AND a.estado = 'publicada' AND a.avisar_al_publicar = 1
                AND a.publica_at IS NOT NULL AND a.publica_at <= ? AND a.publica_at > ?
                AND (a.cierra_at IS NULL OR a.cierra_at > ?)
                AND NOT EXISTS (SELECT 1 FROM ws_avisos v WHERE v.actividad_id = a.id AND v.clase = 'publicada')",
            [$ahora, $ayer, $ahora]
        );

        foreach ($programadas as $act) {
            $filas = array_map([self::class, 'filaDe'], Destinatarios::resolver($act));
            $cuenta['publicada'] += $seco ? count($filas) : self::encolar((int) $act->id, 'publicada', $filas);
        }

        $porCerrar = DB::select(
            "SELECT a.* FROM ws_actividades a
              WHERE a.modo IS NOT NULL AND a.deleted_at IS NULL AND a.estado = 'publicada'
                AND a.recordar_horas_antes IS NOT NULL AND a.cierra_at IS NOT NULL
                AND a.cierra_at > ? AND a.cierra_at <= DATE_ADD(?, INTERVAL a.recordar_horas_antes HOUR)
                AND (a.publica_at IS NULL OR a.publica_at <= ?)
                AND NOT EXISTS (SELECT 1 FROM ws_avisos v WHERE v.actividad_id = a.id AND v.clase = 'por_cerrar')",
            [$ahora, $ahora, $ahora]
        );

        foreach ($porCerrar as $act) {
            $filas = array_map([self::class, 'filaDe'], self::faltan($act));
            $cuenta['por_cerrar'] += $seco ? count($filas) : self::encolar((int) $act->id, 'por_cerrar', $filas);
        }

        $cerradasSolas = DB::select(
            "SELECT a.* FROM ws_actividades a
              WHERE a.modo = 'encuesta' AND a.deleted_at IS NULL AND a.estado = 'publicada'
                AND a.comparte_resultados IN ('respondieron', 'todos')
                AND a.cierra_at IS NOT NULL AND a.cierra_at <= ? AND a.cierra_at > ?
                AND NOT EXISTS (SELECT 1 FROM ws_avisos v WHERE v.actividad_id = a.id AND v.clase = 'resultados')",
            [$ahora, $ayer]
        );

        foreach ($cerradasSolas as $act) {
            $cuenta['resultados'] += $seco ? 1 : self::resultados($act);
        }

        return $cuenta;
    }

    // ------------------------------------------------------------------ el texto

    /**
     * Título y cuerpo de un aviso. Lo usan el push y la campana, para que digan lo mismo.
     *
     * `$quien`: el primer nombre del alumno cuando el aviso va a su tema («Laura tiene…»), o null
     * para hablarle a quien lo lee («Tienes…»). `$hijo`: el acudiente que responde por un hijo.
     * **Nunca lleva la nota ni quién respondió**: se lee en la pantalla bloqueada.
     *
     * @return array{titulo: string, cuerpo: string}
     */
    public static function frase(string $clase, object $act, ?string $quien = null, ?string $hijo = null, int $cuantas = 1, ?string $creador = null): array
    {
        [$quien, $hijo, $creador] = [self::bonito($quien), self::bonito($hijo), self::bonito($creador)];
        $nombre = ['tarea' => 'tarea', 'cuestionario' => 'cuestionario', 'encuesta' => 'encuesta'][$act->modo] ?? 'actividad';
        $una = $act->modo === 'cuestionario' ? 'un '.$nombre : 'una '.$nombre;
        $nueva = $act->modo === 'cuestionario' ? 'nuevo' : 'nueva';
        $titulo = '«'.trim((string) $act->titulo).'»';
        $por = $hijo !== null ? ' (por '.$hijo.')' : '';
        $total = ($act->anonimato ?? 'nombre') === 'total';
        $falta = $act->modo === 'tarea' ? 'entregar' : 'responder';
        $cierra = self::cuandoCierra($act->cierra_at ?? null);
        $lo = $act->modo === 'cuestionario' ? 'lo' : 'la';

        return match ($clase) {
            'publicada' => [
                'titulo' => ucfirst($nombre).' '.$nueva,
                'cuerpo' => ($quien !== null ? $quien.' tiene ' : 'Tienes ').$una.' '.$nueva.': '.$titulo.$por.'.',
            ],
            'recordatorio' => [
                'titulo' => 'Recordatorio',
                'cuerpo' => $total
                    ? 'Si aún no lo has hecho, recuerda '.$falta.' '.$titulo.'.'
                    : ($quien !== null ? $quien.' todavía no ha podido '.$falta.' ' : 'Te falta '.$falta.' ').$titulo.$por.'.',
            ],
            'por_cerrar' => [
                'titulo' => 'Cierra pronto',
                'cuerpo' => $titulo.' cierra '.$cierra.'.'.($total ? '' : ($quien !== null
                    ? ' '.$quien.' todavía no '.$lo.' ha '.($act->modo === 'tarea' ? 'entregado' : 'respondido').'.'
                    : ' Te falta '.$falta.$lo.$por.'.')),
            ],
            'por_aprobar' => [
                'titulo' => 'Por aprobar',
                'cuerpo' => ($creador ?? 'Un docente').' pide aprobar '.$una.': '.$titulo.'.',
            ],
            'aprobada' => [
                'titulo' => ucfirst($nombre).' aprobada',
                'cuerpo' => 'Aprobaron '.$titulo.': ya está publicada.',
            ],
            'rechazada' => [
                'titulo' => ucfirst($nombre).' sin aprobar',
                'cuerpo' => 'No aprobaron '.$titulo.'. Ábrela para ver el motivo.',
            ],
            'entregada' => [
                'titulo' => $cuantas === 1 ? 'Entrega nueva' : 'Entregas nuevas',
                'cuerpo' => ($cuantas === 1 ? 'Hay 1 entrega nueva' : 'Hay '.$cuantas.' entregas nuevas').' en '.$titulo.'.',
            ],
            'calificada' => [
                'titulo' => ucfirst($nombre).' calificada',
                'cuerpo' => ($quien !== null ? 'Calificaron '.$titulo.' de '.$quien : 'Calificaron tu '.$nombre.' '.$titulo).'.',
            ],
            'nota_cambiada' => [
                'titulo' => 'Nota cambiada',
                'cuerpo' => 'Cambió la nota de '.$titulo.($quien !== null ? ' de '.$quien : '').'.',
            ],
            'resultados' => [
                'titulo' => 'Resultados',
                'cuerpo' => 'Ya puedes ver los resultados de '.$titulo.$por.'.',
            ],
            default => ['titulo' => ucfirst($nombre), 'cuerpo' => $titulo],
        };
    }

    /** «hoy a las 18:00», «mañana a las 7:30» o «el 28/09 a las 18:00», en hora de Colombia. */
    public static function cuandoCierra(?string $cierraAt): string
    {
        if ($cierraAt === null) {
            return 'pronto';
        }

        $c = Carbon::parse($cierraAt);
        $hoy = Reloj::ahora()->format('Y-m-d');
        $hora = $c->format('G:i');

        return match ($c->format('Y-m-d')) {
            $hoy => 'hoy a las '.$hora,
            Carbon::parse($hoy)->addDay()->format('Y-m-d') => 'mañana a las '.$hora,
            default => 'el '.$c->format('d/m').' a las '.$hora,
        };
    }

    /**
     * El enlace de app2 al que lleva un aviso (la campana), y la pantalla de la app (el push).
     */
    public static function enlace(string $clase, int $actividadId, ?int $alumnoId): string
    {
        $alumno = $alumnoId !== null ? '?alumno='.$alumnoId : '';

        return match ($clase) {
            'publicada', 'recordatorio', 'por_cerrar' => "/act/{$actividadId}/responder{$alumno}",
            'calificada', 'nota_cambiada', 'resultados' => "/act/{$actividadId}/mis-respuestas{$alumno}",
            'por_aprobar' => "/act/{$actividadId}/responder?previa=1",
            'aprobada' => "/act/{$actividadId}/resultados",
            'rechazada' => "/act/{$actividadId}/editar",
            'entregada' => "/act/{$actividadId}/entregas",
            default => '/act',
        };
    }

    /** «JUAN» → «Juan»: los nombres de las fichas vienen en mayúsculas y un aviso no grita. */
    private static function bonito(?string $nombre): ?string
    {
        if ($nombre === null || mb_strtoupper($nombre) !== $nombre) {
            return $nombre;
        }

        return mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE);
    }

    private static function yaHay(int $actividadId, string $clase): bool
    {
        return DB::selectOne('SELECT 1 AS si FROM ws_avisos WHERE actividad_id = ? AND clase = ? LIMIT 1', [$actividadId, $clase]) !== null;
    }
}
