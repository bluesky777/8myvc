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
 * **El cierre por asignatura**: el docente cierra la suya, coordinación la reabre.
 *
 * Fase 2 de `myvc_front/PLAN-CIERRE-DE-PERIODO.md` (propuesta B del mock). Cuatro
 * rutas, todas `auth.personal` con el permiso fino dentro:
 *
 * | ruta | quién |
 * |---|---|
 * | `GET cierres-asignatura/periodo/{periodo_id}` | personal; el docente ve **las suyas**, coordinación todas |
 * | `GET cierres-asignatura/asignatura/{asignatura_id}/{periodo_id}` | personal |
 * | `PUT cierres-asignatura/cerrar` | el docente de la asignatura, o coordinación |
 * | `PUT cierres-asignatura/reabrir` | coordinación (`Autoriza::puedeReabrirUnaAsignatura`) |
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
        $puedeReabrir = Autoriza::puedeReabrirUnaAsignatura($this->user);
        $soloMias = ! $puedeReabrir || (int) Request::input('mias', 0) === 1;

        $parametros = [(int) $periodo->year_id];
        $filtro = '';

        if ($soloMias) {
            $filtro = ' AND a.profesor_id = ?';
            $parametros[] = $this->miProfesorId() ?? 0;
        }

        $asignaturas = DB::select(
            'SELECT a.id AS asignatura_id, a.grupo_id, g.nombre AS nombre_grupo, g.abrev AS abrev_grupo,
                    m.materia, m.alias, a.profesor_id,
                    TRIM(CONCAT(IFNULL(p.nombres, ""), " ", IFNULL(p.apellidos, ""))) AS profesor_nombre
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
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
            $cerradas += $fila['cerrada'] ? 1 : 0;
            $filas[] = $fila;
        }

        return [
            'periodo_id' => (int) $periodo->id,
            'numero' => (int) $periodo->numero,
            'periodo_abierto' => (int) $periodo->profes_pueden_editar_notas === 1,
            'puede_reabrir' => $puedeReabrir,
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
            'puede_reabrir' => Autoriza::puedeReabrirUnaAsignatura($this->user),
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

    /** Coordinación abre una rendija con fecha y motivo. Al vencer, se cierra sola. */
    public function putReabrir(): array
    {
        Autoriza::exigir(Autoriza::puedeReabrirUnaAsignatura($this->user),
            'Reabrir una asignatura es de coordinación.');

        $periodo = $this->periodo(Request::input('periodo_id'));
        $asignatura = $this->asignaturaDelPeriodo(Request::input('asignatura_id'), $periodo);

        $motivo = trim((string) Request::input('motivo', ''));

        if ($motivo === '') {
            abort(422, 'Falta el motivo: queda escrito junto a la reapertura.');
        }

        if (mb_strlen($motivo) > 500) {
            abort(422, 'El motivo no puede pasar de 500 caracteres.');
        }

        try {
            $hasta = Carbon::parse((string) Request::input('hasta', ''), 'America/Bogota');
        } catch (\Throwable) {
            abort(422, 'La fecha de «hasta cuándo» no se entiende.');
        }

        if (! Request::filled('hasta') || $hasta->lessThanOrEqualTo(CierreDeAsignatura::ahora())) {
            abort(422, 'La reapertura tiene que acabar en el futuro.');
        }

        if (! CierreDeAsignatura::reabrir((int) $periodo->id, (int) $asignatura->asignatura_id, $hasta, $motivo, $this->userId())) {
            abort(422, 'Esa asignatura no está cerrada: no hay nada que reabrir.');
        }

        $cierre = CierreDeAsignatura::de((int) $periodo->id, (int) $asignatura->asignatura_id);
        $faltan = $this->faltanPorAsignatura((int) $periodo->id)[(int) $asignatura->asignatura_id] ?? 0;

        return $this->fila($asignatura, $cierre, $faltan) + [
            'mensaje' => $this->nombre($asignatura).' queda abierta hasta el '
                .$hasta->locale('es')->translatedFormat('j \d\e F \a \l\a\s H:i').'.',
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
                    TRIM(CONCAT(IFNULL(p.nombres, ""), " ", IFNULL(p.apellidos, ""))) AS profesor_nombre
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               LEFT JOIN materias m ON m.id = a.materia_id AND m.deleted_at IS NULL
               LEFT JOIN profesores p ON p.id = a.profesor_id AND p.deleted_at IS NULL
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

    /** El docente de la asignatura, o quien puede reabrirla. */
    private function puedeCerrar(object $asignatura): bool
    {
        if (Autoriza::puedeReabrirUnaAsignatura($this->user)) {
            return true;
        }

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

    /**
     * Las casillas sin nota de cada asignatura del periodo — la misma cuenta que
     * `CierreDeLoNoCalificado::porAsignatura`, que es la que cierra el periodo.
     *
     * @return array<int, int>
     */
    private function faltanPorAsignatura(int $periodoId): array
    {
        $salida = [];

        foreach (CierreDeLoNoCalificado::porAsignatura($periodoId) as $f) {
            $salida[(int) $f->asignatura_id] = (int) $f->casillas;
        }

        return $salida;
    }

    /** @return list<array{unidad:string, subunidad:string, faltan:int}> */
    private function faltanPorIndicador(int $periodoId, int $asignaturaId): array
    {
        return array_map(fn ($f) => [
            'unidad' => (string) $f->unidad,
            'subunidad' => (string) $f->subunidad,
            'faltan' => (int) $f->faltan,
        ], DB::select(
            'SELECT u.definicion AS unidad, s.definicion AS subunidad, COUNT(*) AS faltan
               FROM notas n
               INNER JOIN subunidades s ON s.id = n.subunidad_id AND s.deleted_at IS NULL
               INNER JOIN unidades u ON u.id = s.unidad_id AND u.deleted_at IS NULL
              WHERE u.periodo_id = ? AND u.asignatura_id = ? AND n.deleted_at IS NULL AND n.nota IS NULL
              GROUP BY u.id, u.orden, u.definicion, s.id, s.orden, s.definicion
              ORDER BY u.orden, u.id, s.orden, s.id',
            [$periodoId, $asignaturaId]
        ));
    }
}
