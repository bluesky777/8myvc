<?php

namespace App\Http\Controllers\Act;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Http\Controllers\Controller;
use App\Services\Act\Actividad;
use App\Services\Act\Avisos;
use App\Services\Act\Destinatarios;
use App\Services\Act\Formas;
use App\Services\Act\Respuestas;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * TANDA 5: la campana, «recordar» y la capa del calendario (`myvc_front/ACTIVIDADES-CONTRATO.md`
 * §3.10 y §3.16).
 *
 * - `GET act/avisos` y `POST act/avisos/leidos`: la bandeja de avisos de quien pregunta, que lee la
 *   campana de app2. Salen de `ws_avisos`, las mismas filas que manda el push: lo suyo (`user_id`) y
 *   lo del tema de su alumno (él mismo, o sus acudidos). Leído = hasta una marca por usuario.
 * - `POST act/{id}/recordar`: encola `recordatorio` (ver `Avisos::recordar`).
 * - `GET act/calendario`: las actividades con cierre en el rango. **Capa derivada**: se calcula al
 *   leer y no copia filas a `calendario` (propuesta §5, opción B).
 */
class AvisosController extends Controller
{
    use ResuelveElUsuario;

    /** Cuánto atrás mira la campana. */
    private const DIAS = 60;

    /**
     * `GET act/avisos?limite=30` → `{no_leidos, hasta_id, avisos: AvisoAct[]}`. Juntos por actividad,
     * clase y alumno: 12 entregas nuevas de la misma tarea son UNA línea con `cuantas = 12`.
     */
    public function getAvisos()
    {
        $user = $this->user;
        $uid = (int) $user->user_id;
        $limite = min(100, max(1, (int) Request::input('limite', 30)));
        $alumnos = $this->alumnosDe($user);
        $leidoHasta = (int) (DB::selectOne('SELECT hasta_id FROM ws_avisos_leidos WHERE user_id = ?', [$uid])->hasta_id ?? 0);

        $donde = 'v.user_id = ?';
        $params = [$uid];

        if ($alumnos !== []) {
            $donde .= ' OR (v.user_id IS NULL AND v.alumno_id IN ('.implode(',', array_fill(0, count($alumnos), '?')).'))';
            $params = array_merge($params, array_keys($alumnos));
        }

        $filas = DB::select(
            "SELECT v.id, v.actividad_id, v.clase, v.user_id, v.alumno_id, v.created_at,
                    a.modo, a.titulo, a.anonimato, a.cierra_at, a.created_by, a.estado
               FROM ws_avisos v
              INNER JOIN ws_actividades a ON a.id = v.actividad_id AND a.deleted_at IS NULL AND a.modo IS NOT NULL
              WHERE ($donde) AND v.created_at >= ?
              ORDER BY v.id DESC
              LIMIT 1000",
            array_merge($params, [Reloj::ahora()->subDays(self::DIAS)->format('Y-m-d H:i:s')])
        );

        $grupos = [];

        foreach ($filas as $f) {
            // «Pide aprobar» sólo mientras está por aprobar: aprobada o rechazada ya no pide nada.
            if ($f->clase === 'por_aprobar' && $f->estado !== 'por_aprobar') {
                continue;
            }

            $deTema = $f->user_id === null;
            $alumnoId = $f->alumno_id === null ? null : (int) $f->alumno_id;
            // La entrega nombra al alumno que entregó; la línea del docente no lo separa.
            $clave = $f->actividad_id.'|'.$f->clase.'|'.($deTema ? 'a' : 'u').'|'.($f->clase === 'entregada' ? '' : $alumnoId);

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = ['f' => $f, 'cuantas' => 0, 'nuevas' => 0];
            }

            $grupos[$clave]['cuantas']++;
            $grupos[$clave]['nuevas'] += (int) $f->id > $leidoHasta ? 1 : 0;
        }

        $noLeidos = count(array_filter($grupos, fn ($g) => $g['nuevas'] > 0));
        $avisos = [];
        $creadores = [];

        foreach (array_slice(array_values($grupos), 0, $limite) as $g) {
            $f = $g['f'];
            $alumnoId = $f->alumno_id === null || $f->clase === 'entregada' ? null : (int) $f->alumno_id;
            $esElAlumno = ($user->tipo ?? '') === 'Alumno' && $alumnoId !== null && isset($alumnos[$alumnoId]);
            $nombre = $alumnoId !== null ? ($alumnos[$alumnoId] ?? $this->primerNombre($alumnoId)) : null;

            if ($f->clase === 'por_aprobar') {
                $creadores[(int) $f->created_by] ??= Formas::personaDeUsuario((int) $f->created_by)['nombre'] ?? null;
            }

            $frase = Avisos::frase(
                (string) $f->clase,
                $f,
                // Al tema del alumno: «Laura tiene…» al acudiente, «Tienes…» al propio alumno.
                $f->user_id === null && ! $esElAlumno ? $nombre : null,
                $f->user_id !== null ? $nombre : null,
                $g['cuantas'],
                $creadores[(int) $f->created_by] ?? null
            );

            $avisos[] = [
                'id' => (int) $f->id,
                'clase' => (string) $f->clase,
                'actividad' => ['id' => (int) $f->actividad_id, 'modo' => (string) $f->modo, 'titulo' => (string) $f->titulo],
                'titulo' => $frase['titulo'],
                'texto' => $frase['cuerpo'],
                'alumno' => $alumnoId !== null ? ['alumno_id' => $alumnoId, 'nombre' => $nombre] : null,
                'cuantas' => $g['cuantas'],
                'nuevas' => $g['nuevas'],
                'creado_at' => (string) $f->created_at,
                'leido' => $g['nuevas'] === 0,
                'enlace' => Avisos::enlace((string) $f->clase, (int) $f->actividad_id,
                    $f->user_id === null && ($user->tipo ?? '') === 'Alumno' ? null : $alumnoId),
            ];
        }

        return [
            'no_leidos' => $noLeidos,
            'hasta_id' => $filas === [] ? $leidoHasta : max($leidoHasta, (int) $filas[0]->id),
            'avisos' => $avisos,
        ];
    }

    /**
     * `POST act/avisos/leidos` `{hasta_id?}` → `{hasta_id, no_leidos: 0}`. Todo hasta ese aviso
     * (por defecto, hasta el último que hay) queda leído. La marca sólo avanza.
     */
    public function postLeidos()
    {
        $uid = (int) $this->user->user_id;
        $ultimo = (int) (DB::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM ws_avisos')->m ?? 0);
        $pedido = Destinatarios::entero(Request::input('hasta_id'));
        $hasta = $pedido === null ? $ultimo : min(max(0, $pedido), $ultimo);
        $antes = (int) (DB::selectOne('SELECT hasta_id FROM ws_avisos_leidos WHERE user_id = ?', [$uid])->hasta_id ?? 0);
        $hasta = max($antes, $hasta);

        DB::statement(
            'INSERT INTO ws_avisos_leidos (user_id, hasta_id, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE hasta_id = GREATEST(hasta_id, VALUES(hasta_id)), updated_at = VALUES(updated_at)',
            [$uid, $hasta, Actividad::ahora()]
        );

        return ['hasta_id' => $hasta];
    }

    /**
     * `POST act/{id}/recordar` `{grupo_id?}` → `{avisados}`. Dueño; sólo abierta. 429 si ese grupo
     * ya se recordó hace menos de 12 h. En `total`, a todos (§2.6).
     */
    public function postRecordar($id)
    {
        $act = Actividad::cargar($id);
        Actividad::exigirDueno($act, $this->user);

        if (Actividad::estado($act) !== 'abierta') {
            abort(409, 'Sólo se recuerda una actividad abierta.');
        }

        return ['avisados' => Avisos::recordar($act, Destinatarios::entero(Request::input('grupo_id')))];
    }

    /**
     * `GET act/calendario?desde=AAAA-MM-DD&hasta=AAAA-MM-DD` → `ActEnCalendario[]`: las que creé
     * (programada, abierta o cerrada) y las que me tocan (abierta o cerrada: una programada todavía
     * no existe para quien la recibe), con `en_calendario = 1` y `cierra_at` en el rango.
     */
    public function getCalendario()
    {
        $user = $this->user;
        $uid = (int) $user->user_id;
        $desde = (string) Request::input('desde', '');
        $hasta = (string) Request::input('hasta', '');

        foreach ([$desde, $hasta] as $fecha) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || ! strtotime($fecha)) {
                abort(422, 'desde y hasta son fechas AAAA-MM-DD.');
            }
        }

        if ($hasta < $desde || (strtotime($hasta) - strtotime($desde)) > 400 * 86400) {
            abort(422, 'El rango va de desde a hasta, y como mucho un año.');
        }

        $enRango = fn (object $a) => (bool) $a->en_calendario && $a->cierra_at !== null
            && $a->cierra_at >= $desde.' 00:00:00' && $a->cierra_at <= $hasta.' 23:59:59';

        $salida = [];

        $mias = DB::select(
            "SELECT * FROM ws_actividades
              WHERE modo IS NOT NULL AND deleted_at IS NULL AND created_by = ? AND en_calendario = 1
                AND estado IN ('publicada', 'cerrada') AND cierra_at BETWEEN ? AND ?",
            [$uid, $desde.' 00:00:00', $hasta.' 23:59:59']
        );

        foreach ($mias as $a) {
            $salida[(int) $a->id] = $this->enCalendario($a, true, null);
        }

        foreach (Destinatarios::candidatasPara($user, (int) $user->year_id) as $a) {
            if (! $enRango($a) || ! in_array(Actividad::estado($a), ['abierta', 'cerrada'], true)) {
                continue;
            }

            $entradas = Destinatarios::resolver($a, null, $uid);

            if ($entradas === []) {
                continue;
            }

            // Un acudiente por dos hijos: una línea, con lo que más le urge (lo que falta).
            $estados = array_map(fn ($e) => Respuestas::miEstado($a, $e)['mi_estado'], $entradas);
            $miEstado = current(array_filter($estados, fn ($s) => in_array($s, ['pendiente', 'borrador'], true))) ?: $estados[0];

            $salida[(int) $a->id] = $this->enCalendario($a, isset($salida[(int) $a->id]), $miEstado);
        }

        $lista = array_values($salida);
        usort($lista, fn ($x, $y) => [$x['cierra_at'], $x['id']] <=> [$y['cierra_at'], $y['id']]);

        return $lista;
    }

    // ------------------------------------------------------------------ piezas

    private function enCalendario(object $a, bool $soyDueno, ?string $miEstado): array
    {
        return [
            'id' => (int) $a->id,
            'modo' => (string) $a->modo,
            'titulo' => (string) $a->titulo,
            'cierra_at' => (string) $a->cierra_at,
            'donde' => Formas::donde($a),
            'soy_dueno' => $soyDueno,
            'mi_estado' => $miEstado,
        ];
    }

    /**
     * Los alumnos cuyo tema recibe esta persona, con su primer nombre: el alumno, él mismo; el
     * acudiente, sus acudidos (los mismos que `GET notificaciones/temas`).
     *
     * @return array<int, string>
     */
    private function alumnosDe(object $user): array
    {
        $filas = match ($user->tipo ?? '') {
            'Alumno' => DB::select('SELECT id, nombres FROM alumnos WHERE id = ? AND deleted_at IS NULL', [(int) $user->persona_id]),
            'Acudiente' => DB::select(
                'SELECT a.id, a.nombres FROM parentescos p
                  INNER JOIN alumnos a ON a.id = p.alumno_id AND a.deleted_at IS NULL
                  WHERE p.acudiente_id = ? AND p.deleted_at IS NULL',
                [(int) $user->persona_id]
            ),
            default => [],
        };

        $alumnos = [];

        foreach ($filas as $f) {
            $alumnos[(int) $f->id] = $this->primero((string) $f->nombres);
        }

        return $alumnos;
    }

    private function primerNombre(int $alumnoId): string
    {
        return $this->primero((string) (DB::selectOne('SELECT nombres FROM alumnos WHERE id = ?', [$alumnoId])->nombres ?? ''));
    }

    private function primero(string $nombres): string
    {
        $nombres = trim($nombres);

        return $nombres === '' ? 'Tu hijo' : explode(' ', $nombres)[0];
    }
}
