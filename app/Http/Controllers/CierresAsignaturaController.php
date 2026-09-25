<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveElUsuario;
use App\Support\Autoriza;
use App\Support\CierreDeAsignatura;
use App\Support\CierreDeLoNoCalificado;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * **El cierre por asignatura**: el docente cierra la suya; la reabre él mismo mientras
 * el periodo esté abierto, y coordinación siempre.
 *
 * Fase 2 de `myvc_front/PLAN-CIERRE-DE-PERIODO.md` (propuesta B del mock). Cuatro
 * rutas, todas `auth.personal` con el permiso fino dentro:
 *
 * | ruta | quién |
 * |---|---|
 * | `GET cierres-asignatura/periodo/{periodo_id}` | personal; el docente ve **las suyas**, coordinación todas |
 * | `GET cierres-asignatura/asignatura/{asignatura_id}/{periodo_id}` | personal |
 * | `PUT cierres-asignatura/cerrar` | el docente de la asignatura, o coordinación |
 * | `PUT cierres-asignatura/reabrir` | el docente de la asignatura con el periodo abierto (sin fecha), o coordinación (`Autoriza::puedeReabrirUnaAsignatura`, con fecha) |
 *
 * Lo que el cierre **hace** lo deciden los guards de `User` con
 * `CierreDeAsignatura`; aquí sólo se escribe y se lee la fila.
 */
class CierresAsignaturaController extends Controller
{
    use ResuelveElUsuario;

    /** El tablero de coordinación, y «Mis asignaturas» del docente. */
    public function getPeriodo($periodo_id): array
    {
        $periodo = $this->periodo($periodo_id);
        $esCoordinacion = Autoriza::puedeReabrirUnaAsignatura($this->user);
        $soloMias = ! $esCoordinacion || (int) Request::input('mias', 0) === 1;

        $parametros = [(int) $periodo->year_id];
        $filtro = '';

        if ($soloMias) {
            $filtro = ' AND a.profesor_id = ?';
            $parametros[] = $this->miProfesorId() ?? 0;
        }

        $asignaturas = DB::select(
            'SELECT a.id AS asignatura_id, a.grupo_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo,
                    m.materia, m.alias, a.profesor_id,
                    TRIM(CONCAT(IFNULL(p.nombres, ""), " ", IFNULL(p.apellidos, ""))) AS profesor_nombre,
                    p.nombres AS nombres_profesor, p.apellidos AS apellidos_profesor,
                    fp.nombre AS foto_nombre_profesor, iu.nombre AS imagen_nombre_profesor
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
               LEFT JOIN images fp ON fp.id = p.foto_id
               LEFT JOIN users up ON up.id = p.user_id
               LEFT JOIN images iu ON iu.id = up.imagen_id
              WHERE g.year_id = ? AND a.deleted_at IS NULL'.$filtro.'
              ORDER BY g.orden, g.nombre, a.orden, m.materia, a.id',
            $parametros
        );

        $cierres = CierreDeAsignatura::delPeriodo((int) $periodo->id);
        $faltan = $this->faltanPorAsignatura((int) $periodo->id);
        $miProfesor = $this->miProfesorId();

        $filas = [];
        $cerradas = 0;

        foreach ($asignaturas as $a) {
            $fila = $this->fila($a, $cierres[(int) $a->asignatura_id] ?? null, $faltan[(int) $a->asignatura_id] ?? 0);
            $fila['mia'] = $miProfesor !== null && (int) $a->profesor_id === $miProfesor;
            $fila['puede_reabrir'] = $this->puedeReabrir($a, $periodo);
            $cerradas += $fila['cerrada'] ? 1 : 0;
            $filas[] = $fila;
        }

        return [
            'periodo_id' => (int) $periodo->id,
            'numero' => (int) $periodo->numero,
            'periodo_abierto' => (int) $periodo->profes_pueden_editar_notas === 1,
            // El de arriba es **el de coordinación** (reabre cualquiera, también con el
            // periodo cerrado, y ve el tablero entero). El del docente va por fila
            // (`asignaturas[].puede_reabrir`) desde el 24 sep 2026.
            'puede_reabrir' => $esCoordinacion,
            'resumen' => ['asignaturas' => count($filas), 'cerradas' => $cerradas],
            'asignaturas' => $filas,
        ];
    }

    /** Una sola: la que enseña la planilla, y lo que falta por indicador para el diálogo. */
    public function getAsignatura($asignatura_id, $periodo_id): array
    {
        $periodo = $this->periodo($periodo_id);
        $asignatura = $this->asignaturaDelPeriodo($asignatura_id, $periodo);
        $cierre = CierreDeAsignatura::de((int) $periodo->id, (int) $asignatura->asignatura_id);
        $faltan = $this->faltanPorAsignatura((int) $periodo->id)[(int) $asignatura->asignatura_id] ?? 0;

        return $this->fila($asignatura, $cierre, $faltan) + [
            'periodo_abierto' => (int) $periodo->profes_pueden_editar_notas === 1,
            'puede_cerrar' => $this->puedeCerrar($asignatura),
            'puede_reabrir' => $this->puedeReabrir($asignatura, $periodo),
            // Si reabrir le pide fecha: a coordinación sí, al docente con el periodo
            // abierto no (su reapertura es sin plazo). El front esconde el campo con esto.
            'reabrir_pide_fecha' => ! $this->reabreSinPlazo($asignatura, $periodo),
            'faltan_por_indicador' => $this->faltanPorIndicador((int) $periodo->id, (int) $asignatura->asignatura_id),
        ];
    }

    /**
     * El docente cierra su asignatura. **Cerrar con huecos se deja**, salvo que el
     * colegio haya elegido `bloquear` para lo no calificado: ahí el 422 de siempre,
     * con la cuenta dentro, igual que al cerrar el periodo.
     */
    public function putCerrar(): array
    {
        $periodo = $this->periodo(Request::input('periodo_id'));
        $asignatura = $this->asignaturaDelPeriodo(Request::input('asignatura_id'), $periodo);

        Autoriza::exigir($this->puedeCerrar($asignatura),
            'Sólo el docente de la asignatura o coordinación pueden cerrarla.');

        $faltan = $this->faltanPorAsignatura((int) $periodo->id)[(int) $asignatura->asignatura_id] ?? 0;

        if ($faltan > 0
            && CierreDeLoNoCalificado::elegidoParaElPeriodo((int) $periodo->id) === CierreDeLoNoCalificado::BLOQUEAR) {
            abort(422, 'No se puede cerrar: faltan '.$faltan.' notas por poner, y el colegio eligió '
                .'no dejar cerrar mientras quede algo sin calificar.');
        }

        CierreDeAsignatura::cerrar((int) $periodo->id, (int) $asignatura->asignatura_id, $this->userId());

        $cierre = CierreDeAsignatura::de((int) $periodo->id, (int) $asignatura->asignatura_id);

        return $this->fila($asignatura, $cierre, $faltan) + [
            'mensaje' => 'Cerraste '.$this->nombre($asignatura).' en el periodo '.$periodo->numero.'.',
        ];
    }

    /**
     * Reabrir. **Dos puertas** (Joseth, 24 sep 2026, que enmienda la decisión 2 del
     * 23 sep: «el docente puede volver a abrirla si no se ha cerrado el periodo»):
     *
     * - **Su docente, con el periodo abierto** (`profes_pueden_editar_notas = 1`):
     *   sin fecha. Queda abierta hasta que él la vuelva a cerrar (`reabierta_hasta`
     *   NULL, ver `CierreDeAsignatura`). Con el periodo abierto el cierre de la
     *   asignatura es suyo —lo puso él, él lo quita—; una fecha obligatoria sería
     *   pedirle que adivine cuándo termina de corregir. Si manda una, vale como
     *   rendija normal.
     * - **Coordinación**: como antes, con fecha futura, y también con el periodo
     *   cerrado. Al vencer, se cierra sola.
     *
     * El motivo es opcional en las dos. La fila no se borra nunca: `cerrada_at/por`
     * se quedan y `reabierta_at/por` dicen quién la abrió.
     */
    public function putReabrir(): array
    {
        $periodo = $this->periodo(Request::input('periodo_id'));
        $asignatura = $this->asignaturaDelPeriodo(Request::input('asignatura_id'), $periodo);

        Autoriza::exigir($this->puedeReabrir($asignatura, $periodo),
            'Reabrir una asignatura es de su docente mientras el periodo esté abierto, o de coordinación.');

        $motivo = trim((string) Request::input('motivo', ''));

        if (mb_strlen($motivo) > 500) {
            abort(422, 'El motivo no puede pasar de 500 caracteres.');
        }

        $sinPlazo = $this->reabreSinPlazo($asignatura, $periodo);
        $hasta = null;

        if (! $sinPlazo || Request::filled('hasta')) {
            try {
                $hasta = Carbon::parse((string) Request::input('hasta', ''), 'America/Bogota');
            } catch (\Throwable) {
                abort(422, 'La fecha de «hasta cuándo» no se entiende.');
            }

            if (! Request::filled('hasta') || $hasta->lessThanOrEqualTo(CierreDeAsignatura::ahora())) {
                abort(422, 'La reapertura tiene que acabar en el futuro.');
            }
        }

        $antes = CierreDeAsignatura::de((int) $periodo->id, (int) $asignatura->asignatura_id);

        // Sin plazo sólo sobre una CERRADA: sobre una rendija viva de coordinación
        // la convertiría en abierta para siempre sin que nadie lo decidiera.
        if ($hasta === null && $antes !== null && ! $antes['cerrada']) {
            abort(422, 'Esa asignatura ya está abierta.');
        }

        if (! CierreDeAsignatura::reabrir((int) $periodo->id, (int) $asignatura->asignatura_id, $hasta, $motivo === '' ? null : $motivo, $this->userId())) {
            abort(422, 'Esa asignatura no está cerrada: no hay nada que reabrir.');
        }

        $cierre = CierreDeAsignatura::de((int) $periodo->id, (int) $asignatura->asignatura_id);
        $faltan = $this->faltanPorAsignatura((int) $periodo->id)[(int) $asignatura->asignatura_id] ?? 0;

        return $this->fila($asignatura, $cierre, $faltan) + [
            'puede_reabrir' => $this->puedeReabrir($asignatura, $periodo),
            'mensaje' => $hasta === null
                ? $this->nombre($asignatura).' queda abierta hasta que la vuelvas a cerrar.'
                : $this->nombre($asignatura).' queda abierta hasta el '
                    .$hasta->settings(['locale' => 'es'])->translatedFormat('j \d\e F \a \l\a\s H:i').'.',
        ];
    }

    // ── piezas ────────────────────────────────────────────────────────────

    private function periodo($periodoId): object
    {
        $id = (int) $periodoId;
        $periodo = $id > 0 ? DB::selectOne(
            'SELECT id, numero, year_id, profes_pueden_editar_notas FROM periodos WHERE id = ? AND deleted_at IS NULL',
            [$id]
        ) : null;

        if ($periodo === null) {
            abort(404, 'Ese periodo no existe.');
        }

        return $periodo;
    }

    private function asignaturaDelPeriodo($asignaturaId, object $periodo): object
    {
        $id = (int) $asignaturaId;
        $a = $id > 0 ? DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo,
                    m.materia, m.alias, a.profesor_id, g.year_id,
                    TRIM(CONCAT(IFNULL(p.nombres, ""), " ", IFNULL(p.apellidos, ""))) AS profesor_nombre,
                    p.nombres AS nombres_profesor, p.apellidos AS apellidos_profesor,
                    fp.nombre AS foto_nombre_profesor, iu.nombre AS imagen_nombre_profesor
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
               LEFT JOIN images fp ON fp.id = p.foto_id
               LEFT JOIN users up ON up.id = p.user_id
               LEFT JOIN images iu ON iu.id = up.imagen_id
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$id]
        ) : null;

        if ($a === null) {
            abort(404, 'Esa asignatura no existe.');
        }

        if ((int) $a->year_id !== (int) $periodo->year_id) {
            abort(422, 'Esa asignatura no es del año de ese periodo.');
        }

        return $a;
    }

    /** El docente de la asignatura, o coordinación. */
    private function puedeCerrar(object $asignatura): bool
    {
        return Autoriza::puedeReabrirUnaAsignatura($this->user) || $this->esSuDocente($asignatura);
    }

    /**
     * Quién reabre ESTA asignatura en ESTE periodo: coordinación siempre; su docente
     * sólo con el periodo abierto (24 sep 2026). **Un solo sitio**: lo usan la ruta,
     * el `puede_reabrir` de cada fila del tablero y el de la planilla.
     */
    private function puedeReabrir(object $asignatura, object $periodo): bool
    {
        return Autoriza::puedeReabrirUnaAsignatura($this->user)
            || $this->reabreSinPlazo($asignatura, $periodo);
    }

    /** El docente de la asignatura con el periodo abierto: la puerta sin fecha. */
    private function reabreSinPlazo(object $asignatura, object $periodo): bool
    {
        return (int) $periodo->profes_pueden_editar_notas === 1 && $this->esSuDocente($asignatura);
    }

    private function esSuDocente(object $asignatura): bool
    {
        $mio = $this->miProfesorId();

        return $mio !== null && $asignatura->profesor_id !== null && (int) $asignatura->profesor_id === $mio;
    }

    private function miProfesorId(): ?int
    {
        return ($this->user->tipo ?? '') === 'Profesor' && isset($this->user->persona_id)
            ? (int) $this->user->persona_id
            : null;
    }

    private function userId(): ?int
    {
        return isset($this->user->user_id) ? (int) $this->user->user_id : null;
    }

    private function nombre(object $a): string
    {
        return trim(($a->materia ?? 'La asignatura').' '.($a->abrev_grupo ?: $a->nombre_grupo));
    }

    /** @return array<string, mixed> */
    private function fila(object $a, ?array $cierre, int $faltan): array
    {
        return [
            'asignatura_id' => (int) $a->asignatura_id,
            'materia' => $a->materia,
            'alias' => $a->alias,
            'grupo_id' => (int) $a->grupo_id,
            'nombre_grupo' => $a->nombre_grupo,
            'abrev_grupo' => $a->abrev_grupo,
            'profesor_id' => $a->profesor_id === null ? null : (int) $a->profesor_id,
            'profesor_nombre' => $a->profesor_nombre !== '' ? $a->profesor_nombre : null,
            // Su cara, para la fila (regla: toda fila que nombra a un docente lleva su foto).
            'nombres_profesor' => $a->nombres_profesor,
            'apellidos_profesor' => $a->apellidos_profesor,
            'foto_nombre_profesor' => $a->foto_nombre_profesor,
            'imagen_nombre_profesor' => $a->imagen_nombre_profesor,
            'faltan' => $faltan,
            'estado' => $cierre['estado'] ?? 'abierta',
            'cerrada' => $cierre['cerrada'] ?? false,
            'cerrada_at' => $cierre['cerrada_at'] ?? null,
            'cerrada_por_nombre' => $cierre['cerrada_por_nombre'] ?? null,
            'reabierta_hasta' => $cierre['reabierta_hasta'] ?? null,
            'reabierta_por_nombre' => $cierre['reabierta_por_nombre'] ?? null,
            'motivo' => $cierre['motivo'] ?? null,
        ];
    }

    /** @return array<int, int> Ver `CierreDeAsignatura::ES_FALTANTE`: el criterio vive allí. */
    private function faltanPorAsignatura(int $periodoId): array
    {
        return CierreDeAsignatura::faltantesPorAsignatura($periodoId);
    }

    /** @return list<array{unidad:string, subunidad:string, faltan:int}> */
    private function faltanPorIndicador(int $periodoId, int $asignaturaId): array
    {
        return CierreDeAsignatura::faltantesPorIndicador($periodoId, $asignaturaId);
    }
}
