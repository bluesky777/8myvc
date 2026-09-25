<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * LO QUE UNA REJILLA ACADÉMICA ENSEÑA DE UN ALUMNO, COMO HISTORIAL. La planilla de una
 * asignatura en un periodo, las asistencias de un grupo, el comportamiento…: la columna
 * «Historial» de esas pantallas dice cuándo se tocó por última vez algo QUE ESA TABLA PINTA
 * de ese alumno —sus notas de esa asignatura y periodo, sus faltas, sus frases—, y su
 * diálogo enseña esas líneas. El nombre del alumno cambiado no sale aquí: no está en la tabla.
 *
 * El alcance se decide por la FILA de la entidad, no por las columnas de contexto de la
 * línea de auditoría: `notas/update` y `notas/lote` graban `asignatura_id` en null, y
 * `definicion_comportamiento` ni siquiera graba el alumno. Así que para cada entidad se
 * buscan sus filas del alcance en su tabla (con el alumno de la tabla) y se cruzan con
 * `auditoria` por (entidad, entidad_id), que tiene índice. Y se suman las líneas cuyo
 * contexto sí cuadra aunque la fila ya no exista (un borrado duro).
 */
final class AlcanceAcademico
{
    /**
     * Por entidad: la consulta de sus filas del alcance, con `id` y `alumno_id`, y qué filtro
     * entiende. `{A}` son las marcas de los alumnos; los filtros se añaden si vienen.
     */
    private const ENTIDADES = [
        'nota' => [
            'sql' => 'SELECT n.id, n.alumno_id FROM notas n
                        JOIN subunidades s ON s.id = n.subunidad_id
                        JOIN unidades u ON u.id = s.unidad_id
                       WHERE n.alumno_id IN ({A})',
            'filtros' => ['asignatura_id' => 'u.asignatura_id', 'periodo_id' => 'u.periodo_id'],
        ],
        'nota_final' => [
            'sql' => 'SELECT t.id, t.alumno_id FROM notas_finales t WHERE t.alumno_id IN ({A})',
            'filtros' => ['asignatura_id' => 't.asignatura_id', 'periodo_id' => 't.periodo_id'],
        ],
        'ausencia' => [
            'sql' => 'SELECT t.id, t.alumno_id FROM ausencias t WHERE t.alumno_id IN ({A})',
            'filtros' => ['asignatura_id' => 't.asignatura_id', 'periodo_id' => 't.periodo_id'],
        ],
        'frase_asignatura' => [
            'sql' => 'SELECT t.id, t.alumno_id FROM frases_asignatura t WHERE t.alumno_id IN ({A})',
            'filtros' => ['asignatura_id' => 't.asignatura_id', 'periodo_id' => 't.periodo_id'],
        ],
        'recuperacion_final' => [
            'sql' => 'SELECT t.id, t.alumno_id FROM recuperacion_final t WHERE t.alumno_id IN ({A})',
            'filtros' => ['asignatura_id' => 't.asignatura_id', 'year' => 't.year'],
        ],
        'comportamiento' => [
            'sql' => 'SELECT t.id, t.alumno_id FROM nota_comportamiento t WHERE t.alumno_id IN ({A})',
            'filtros' => ['periodo_id' => 't.periodo_id'],
        ],
        'definicion_comportamiento' => [
            'sql' => 'SELECT d.id, c.alumno_id FROM definiciones_comportamiento d
                        JOIN nota_comportamiento c ON c.id = d.comportamiento_id
                       WHERE c.alumno_id IN ({A})',
            'filtros' => ['periodo_id' => 'c.periodo_id'],
        ],
        'dis_libro_rojo' => [
            'sql' => 'SELECT t.id, t.alumno_id FROM dis_libro_rojo t WHERE t.alumno_id IN ({A})',
            'filtros' => ['year_id' => 't.year_id'],
        ],
    ];

    /** Los filtros de contexto que se aceptan, y su columna en `auditoria`. */
    private const CONTEXTO = ['asignatura_id' => 'asignatura_id', 'periodo_id' => 'periodo_id', 'year_id' => 'year_id'];

    /** @return string[] */
    public static function entidades(): array
    {
        return array_keys(self::ENTIDADES);
    }

    /**
     * Una subconsulta de `auditoria.id` (alias `x.id`) y `alumno_id` con las líneas del
     * alcance, para los alumnos dados.
     *
     * @param  string[]  $entidades
     * @param  int[]  $alumnos
     * @param  array<string, int|null>  $filtros  asignatura_id, periodo_id, year, year_id
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function lineas(array $entidades, array $alumnos, array $filtros): array
    {
        $marcasA = implode(',', array_fill(0, count($alumnos), '?'));
        $partes = [];
        $parametros = [];

        foreach ($entidades as $entidad) {
            $def = self::ENTIDADES[$entidad] ?? null;
            if (! $def) {
                continue;
            }

            // 1) Por la fila de la entidad, con el alumno de su tabla.
            $filas = str_replace('{A}', $marcasA, $def['sql']);
            $pFilas = $alumnos;
            foreach ($def['filtros'] as $clave => $columna) {
                if (isset($filtros[$clave])) {
                    $filas .= " AND $columna = ?";
                    $pFilas[] = $filtros[$clave];
                }
            }
            $partes[] = "SELECT a.id, f.alumno_id FROM auditoria a JOIN ($filas) f ON a.entidad = ? AND a.entidad_id = f.id";
            array_push($parametros, ...$pFilas);
            $parametros[] = $entidad;

            // 2) Por el contexto de la línea, para las filas que ya no existen.
            $porLinea = "SELECT a.id, a.alumno_id FROM auditoria a WHERE a.alumno_id IN ($marcasA) AND a.entidad = ?";
            $pLinea = [...$alumnos, $entidad];
            $conFiltro = false;
            foreach (self::CONTEXTO as $clave => $columna) {
                if (isset($filtros[$clave]) && array_key_exists($clave, $def['filtros'])) {
                    $porLinea .= " AND a.$columna = ?";
                    $pLinea[] = $filtros[$clave];
                    $conFiltro = true;
                }
            }
            // Sin ningún filtro que la línea pueda cumplir, la vía 2 traería todo el alumno.
            if ($conFiltro || count(array_intersect_key($filtros, $def['filtros'])) === 0) {
                $partes[] = $porLinea;
                array_push($parametros, ...$pLinea);
            }
        }

        if (! $partes) {
            return ['SELECT NULL AS id, NULL AS alumno_id FROM DUAL WHERE 1 = 0', []];
        }

        return [implode(' UNION ', $partes), $parametros];
    }

    /**
     * La última línea del alcance por alumno.
     *
     * @return array<int, string> alumno_id => 'Y-m-d H:i:s'
     */
    public static function fechas(array $entidades, array $alumnos, array $filtros): array
    {
        if (! $alumnos) {
            return [];
        }
        [$sub, $parametros] = self::lineas($entidades, $alumnos, $filtros);
        $filas = DB::select(
            "SELECT x.alumno_id, MAX(a.ocurrido_en) AS f FROM ($sub) x JOIN auditoria a ON a.id = x.id GROUP BY x.alumno_id",
            $parametros
        );

        $fechas = [];
        foreach ($filas as $fila) {
            $fechas[(int) $fila->alumno_id] = substr((string) $fila->f, 0, 19);
        }

        return $fechas;
    }
}
