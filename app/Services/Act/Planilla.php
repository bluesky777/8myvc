<?php

namespace App\Services\Act;

use App\Models\Nota;
use App\Services\Auditoria;
use App\Services\DefinitivasDeAsignatura;
use App\Services\EscrituraDeNotas;
use App\Services\SubunidadNueva;
use App\Support\AnioCerrado;
use App\Support\Autoriza;
use App\Support\CandadoDeLaPlantilla;
use App\Support\RepartoDeLaNota;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LA NOTA DE UNA ACTIVIDAD EN LA PLANILLA (tanda 2).
 *
 * Contrato: `myvc_front/docs/funciones/ACTIVIDADES-CONTRATO.md` §2.8, §3.9 y §3.11; el porqué en
 * `ACTIVIDADES-Y-ENCUESTAS.md` §4c. Una tarea o un cuestionario con `califica = 1` crea, al
 * publicarse, un indicador (subunidad) en el logro (`unidad_id`) que eligió el docente, enlazado por
 * `subunidades.actividad_id`. Todo lo que escribe va por los caminos de siempre, sacados a servicios
 * sin cambiarlos: `SubunidadNueva` (el alta de `subunidades/store`) y `EscrituraDeNotas` (la
 * escritura de `notas/lote`), así que la bitácora, la auditoría y el recálculo son los mismos que
 * teclearla en la planilla.
 *
 * **Editada a mano** (Joseth, 26 sep; sin migración): la nota de la planilla está editada si no es
 * NULL, no es la `nota_default` de la subunidad y no coincide con la calculada vigente (la mejor
 * `nota_calculada` del cuestionario, o `ws_entregas.nota` de la tarea). Lo automático no la pisa.
 * Límite sabido: si el docente puso a mano justo el mismo valor, no se distingue, y entonces
 * actualizarla no cambia nada.
 *
 * La subunidad y sus notas nunca se borran desde este módulo.
 */
class Planilla
{
    /** 423 si la actividad es de un año cerrado (salvo superusuario, como en el resto de la API). */
    public static function exigirAnioAbierto(object $act, object $user): void
    {
        if (AnioCerrado::estaCerrado((int) $act->year_id) && ! Autoriza::puedeEscribirEnUnAnioCerrado($user)) {
            abort(423, 'Esa actividad es de un año ya cerrado: su nota no se puede cambiar.');
        }
    }

    public static function anioCerrado(object $act): bool
    {
        return AnioCerrado::estaCerrado((int) $act->year_id);
    }

    /** `porcentaje` o `promedio`, el del periodo de la actividad. */
    public static function reparto(object $act): string
    {
        return RepartoDeLaNota::modoDelPeriodo((int) $act->periodo_id);
    }

    /**
     * `LogroAct[]`: los logros (unidades) de una asignatura y periodo con sus indicadores y pesos.
     * Las unidades con dueño (`alumno_id`, la de un solo alumno) no se ofrecen: el indicador de una
     * actividad es de la clase entera.
     */
    public static function logros(int $asignaturaId, int $periodoId): array
    {
        $unidades = DB::select(
            'SELECT id, definicion, porcentaje FROM unidades
              WHERE asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL
              ORDER BY orden, id',
            [$asignaturaId, $periodoId]
        );

        if ($unidades === []) {
            return [];
        }

        $ids = array_map(fn ($u) => (int) $u->id, $unidades);
        $porUnidad = [];

        foreach (DB::select(
            'SELECT id, unidad_id, definicion, porcentaje, actividad_id FROM subunidades
              WHERE unidad_id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND deleted_at IS NULL
              ORDER BY orden, id',
            $ids
        ) as $s) {
            $porUnidad[(int) $s->unidad_id][] = [
                'subunidad_id' => (int) $s->id,
                'definicion' => (string) $s->definicion,
                'porcentaje' => (int) $s->porcentaje,
                'actividad_id' => $s->actividad_id === null ? null : (int) $s->actividad_id,
            ];
        }

        return array_map(function ($u) use ($porUnidad) {
            $indicadores = $porUnidad[(int) $u->id] ?? [];

            return [
                'unidad_id' => (int) $u->id,
                'definicion' => (string) $u->definicion,
                'porcentaje' => (int) $u->porcentaje,
                'indicadores' => $indicadores,
                'suma' => array_sum(array_column($indicadores, 'porcentaje')),
            ];
        }, $unidades);
    }

    /** El logro elegido, si sigue vivo y es de la clase y el periodo de la actividad. */
    public static function unidadDe(object $act): ?object
    {
        if ($act->unidad_id === null || $act->asignatura_id === null) {
            return null;
        }

        return DB::selectOne(
            'SELECT id, asignatura_id, periodo_id FROM unidades
              WHERE id = ? AND asignatura_id = ? AND periodo_id = ? AND alumno_id IS NULL AND deleted_at IS NULL',
            [(int) $act->unidad_id, (int) $act->asignatura_id, (int) $act->periodo_id]
        );
    }

    /** La subunidad de la actividad (la que creó al publicarse), o null. */
    public static function subunidad(int $actividadId): ?object
    {
        return DB::selectOne(
            'SELECT id, unidad_id, porcentaje, nota_default FROM subunidades
              WHERE actividad_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$actividadId]
        );
    }

    /**
     * Crea el indicador al publicar: `definicion = titulo`, `actividad_id`, y el peso si el año
     * reparte por porcentaje (0 en `promedio`, que pesa 1/n). Con `$reajustar`, los demás
     * indicadores del logro se escalan para sumar `100 − peso` — sólo si lo pidió el botón.
     */
    public static function crearIndicador(object $act, object $user, bool $reajustar): int
    {
        $porcentaje = self::reparto($act) === RepartoDeLaNota::PORCENTAJE ? (int) $act->peso : 0;

        $subunidad = SubunidadNueva::crear($user, [
            'unidad_id' => (int) $act->unidad_id,
            'definicion' => (string) $act->titulo,
            'porcentaje' => $porcentaje,
            'nota_default' => 0,
            'orden' => 0,
            'actividad_id' => (int) $act->id,
        ]);

        if ($reajustar && $porcentaje > 0) {
            self::reajustar((int) $act->unidad_id, (int) $subunidad->id, $porcentaje, $user);
        }

        return (int) $subunidad->id;
    }

    /**
     * «Reajustar los demás» (§2.8): los otros indicadores del logro, en proporción a lo que pesaban,
     * para sumar `100 − peso`, en enteros por mayor resto. Si los demás pesaban 0 se reparte a
     * partes iguales. Cada cambio pasa por el candado de la plantilla y deja su línea de auditoría,
     * como `subunidades/update`; la definitiva se recalcula una vez al final.
     */
    public static function reajustar(int $unidadId, int $nuevaId, int $peso, object $user): void
    {
        $otras = DB::select(
            'SELECT id, unidad_id, definicion, porcentaje, nota_default, por_defecto FROM subunidades
              WHERE unidad_id = ? AND id <> ? AND deleted_at IS NULL ORDER BY orden, id',
            [$unidadId, $nuevaId]
        );

        if ($otras === []) {
            return;
        }

        $meta = max(0, 100 - $peso);
        $total = array_sum(array_map(fn ($s) => max(0, (int) $s->porcentaje), $otras));
        $exactos = [];

        foreach ($otras as $i => $s) {
            $exactos[$i] = $total > 0 ? max(0, (int) $s->porcentaje) * $meta / $total : $meta / count($otras);
        }

        $nuevos = array_map(fn ($x) => (int) floor($x), $exactos);
        $falta = $meta - array_sum($nuevos);
        $restos = [];

        foreach ($exactos as $i => $x) {
            $restos[$i] = $x - floor($x);
        }

        // Mayor resto primero; a igualdad, el que va antes en la unidad.
        uksort($restos, fn ($a, $b) => $restos[$b] <=> $restos[$a] ?: $a <=> $b);

        foreach (array_keys($restos) as $i) {
            if ($falta <= 0) {
                break;
            }

            $nuevos[$i]++;
            $falta--;
        }

        $cambios = [];

        foreach ($otras as $i => $s) {
            if ((int) $s->porcentaje !== $nuevos[$i]) {
                // Antes de escribir la primera: una fila de la plantilla del colegio no la cambia un
                // docente, y es mejor no tocar ninguna que dejar la unidad a medias.
                CandadoDeLaPlantilla::exigir($user, $s, ['porcentaje' => $nuevos[$i]], 'subunidad');
                $cambios[] = [$s, $nuevos[$i]];
            }
        }

        $ahora = Carbon::now('America/Bogota');

        foreach ($cambios as [$s, $nuevo]) {
            DB::update('UPDATE subunidades SET porcentaje = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$nuevo, $user->user_id, $ahora, $s->id]);

            Auditoria::registrar()
                ->editar('subunidad', (int) $s->id)
                ->en(periodo: $user->periodo_id)
                ->de(['definicion' => $s->definicion, 'porcentaje' => (int) $s->porcentaje, 'nota_default' => $s->nota_default])
                ->a(['definicion' => $s->definicion, 'porcentaje' => $nuevo, 'nota_default' => $s->nota_default])
                ->guardar();
        }

        if ($cambios !== []) {
            DefinitivasDeAsignatura::recalcularPorUnidad($unidadId, $user->user_id);
        }
    }

    /**
     * Las notas calculadas vigentes, por alumno: la mejor `nota_calculada` del cuestionario (§2.8,
     * con varios intentos cuenta la mejor) o la nota que puso el docente en la tarea.
     *
     * @return array<int, int|null>
     */
    public static function calculadas(object $act): array
    {
        $filas = $act->modo === 'tarea'
            ? DB::select('SELECT alumno_id, nota FROM ws_entregas WHERE actividad_id = ?', [$act->id])
            : DB::select(
                'SELECT alumno_id, MAX(nota_calculada) AS nota FROM ws_actividades_resueltas
                  WHERE actividad_id = ? AND terminado = 1 AND deleted_at IS NULL AND alumno_id IS NOT NULL
                  GROUP BY alumno_id',
                [$act->id]
            );

        $calculadas = [];

        foreach ($filas as $f) {
            $calculadas[(int) $f->alumno_id] = $f->nota === null ? null : (int) $f->nota;
        }

        return $calculadas;
    }

    public static function calculadaDe(object $act, int $alumnoId): ?int
    {
        $f = $act->modo === 'tarea'
            ? DB::selectOne('SELECT nota FROM ws_entregas WHERE actividad_id = ? AND alumno_id = ?', [$act->id, $alumnoId])
            : DB::selectOne(
                'SELECT MAX(nota_calculada) AS nota FROM ws_actividades_resueltas
                  WHERE actividad_id = ? AND alumno_id = ? AND terminado = 1 AND deleted_at IS NULL',
                [$act->id, $alumnoId]
            );

        return $f === null || $f->nota === null ? null : (int) $f->nota;
    }

    /**
     * La nota de la actividad llevada a la escala del año (Joseth, 26 sep): la actividad se
     * califica sobre `nota_maxima` (50, por ejemplo) y la planilla va sobre el máximo de la escala
     * (100 en casi todos): `round(nota / nota_maxima × máximo)`, mitad hacia arriba. Todo lo que
     * escribe en la planilla o compara con ella («editada a mano») pasa por aquí. `$notaMaxima`
     * sólo para el impacto de un cambio de nota máxima; si no, la de la actividad.
     */
    public static function aLaEscala(object $act, ?int $nota, ?int $notaMaxima = null): ?int
    {
        if ($nota === null) {
            return null;
        }

        $maximo = Actividad::maximoDeLaEscala((int) $act->year_id);
        $sobre = max(1, (int) ($notaMaxima ?? $act->nota_maxima ?? $maximo));

        return $sobre === $maximo ? $nota : (int) round($nota * $maximo / $sobre, 0, PHP_ROUND_HALF_UP);
    }

    /** @return array<int, object> las notas vivas de la subunidad, por alumno (`id`, `nota`) */
    public static function notasDe(int $subunidadId): array
    {
        $notas = [];

        foreach (DB::select(
            'SELECT id, alumno_id, nota FROM notas WHERE subunidad_id = ? AND deleted_at IS NULL ORDER BY id',
            [$subunidadId]
        ) as $n) {
            $notas[(int) $n->alumno_id] ??= $n;
        }

        return $notas;
    }

    /** La regla de «editada a mano» (ver la cabecera). */
    public static function editada(?object $nota, object $subunidad, ?int $calculada): bool
    {
        if ($nota === null || $nota->nota === null || $nota->nota === '') {
            return false;
        }

        if ((float) $nota->nota === (float) $subunidad->nota_default) {
            return false;
        }

        return $calculada === null || (float) $nota->nota !== (float) $calculada;
    }

    /**
     * Escribe en la planilla `alumno_id => nota` por `EscrituraDeNotas` (bitácora con la nota
     * anterior, auditoría, definitiva recalculada). Crea la fila del alumno si le falta —un alumno
     * que llegó al grupo después de publicar— y se salta las que ya tienen ese valor.
     *
     * `$conPermiso = false` sólo para la escritura automática al enviar un cuestionario: quien la
     * desencadena es el alumno, no el docente que tiene el permiso.
     *
     * @param  array<int, int|null>  $valores
     * @return int cuántas se escribieron
     */
    public static function escribir(object $subunidad, array $valores, object $user, bool $conPermiso = true): int
    {
        $aEscribir = [];

        foreach ($valores as $alumnoId => $valor) {
            Nota::verificarCrearNota((int) $alumnoId, (int) $subunidad->id, $user->user_id);

            $nota = DB::selectOne(
                'SELECT id FROM notas WHERE subunidad_id = ? AND alumno_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
                [(int) $subunidad->id, (int) $alumnoId]
            );
            $destino = $nota ? EscrituraDeNotas::destino((int) $nota->id) : null;

            if ($destino === null || EscrituraDeNotas::mismaNota($destino->nota, $valor)) {
                continue;
            }

            $aEscribir[] = ['id' => (int) $nota->id, 'valor' => $valor, 'destino' => $destino];
        }

        if ($aEscribir === []) {
            return 0;
        }

        return EscrituraDeNotas::guardar($aEscribir, [], $user, Carbon::now('America/Bogota'), $conPermiso)['guardadas'];
    }

    /**
     * Al enviar un cuestionario: si la nota vigente cambió y la de la planilla no estaba editada a
     * mano (juzgado contra la vigente de ANTES de este envío), se escribe. En un año cerrado no se
     * toca la planilla, pero el envío no falla por eso. Las dos, llevadas a la escala del año.
     */
    public static function alEnviar(object $act, object $user, int $alumnoId, ?int $antes): void
    {
        if (! $act->califica || self::anioCerrado($act)) {
            return;
        }

        $subunidad = self::subunidad((int) $act->id);

        if ($subunidad === null) {
            return;
        }

        $despues = self::aLaEscala($act, self::calculadaDe($act, $alumnoId));
        $nota = self::notasDe((int) $subunidad->id)[$alumnoId] ?? null;

        if ($despues === null || self::editada($nota, $subunidad, self::aLaEscala($act, $antes))) {
            return;
        }

        self::escribir($subunidad, [$alumnoId => $despues], $user, false);
    }
}
