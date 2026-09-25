<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lo que el boletín de periodo pedía por alumno × asignatura, pedido una vez por
 * grupo (docs/migracion/48 §P3b): las definitivas, las faltas y las frases. En un
 * grupo de 38 con 12 asignaturas eran 3 × 456 consultas; ahora son tres.
 *
 * **Devuelve las mismas filas, con las mismas columnas y en el mismo orden** que
 * `notas_finales ... ORDER BY periodo`, `Ausencia::deAlumno()` y
 * `FraseAsignatura::deAlumno()`. Esas dos no tienen `ORDER BY` y MySQL las sirve por
 * el índice de `alumno_id`, o sea por `id`: aquí se ordena por `id` a propósito. La
 * columna con la que se reparte (`alumno_id`, `asignatura_id`) se quita antes de
 * devolver, que en la respuesta no estaba.
 */
final class LoDelGrupoDeUnaVez
{
    /** @var array<string, list<object>> */
    private array $definitivas = [];

    /** @var array<string, list<object>> */
    private array $ausencias = [];

    /** @var array<string, list<object>> */
    private array $frases = [];

    /**
     * @param  list<int>  $alumnoIds
     * @param  list<int>  $asignaturaIds
     */
    public function __construct(array $alumnoIds, array $asignaturaIds, int $periodoId, int $hastaElPeriodo)
    {
        if ($alumnoIds === [] || $asignaturaIds === []) {
            return;
        }

        $alumnos = implode(',', array_fill(0, count($alumnoIds), '?'));
        $asignaturas = implode(',', array_fill(0, count($asignaturaIds), '?'));
        $ids = array_merge(array_map('intval', $alumnoIds), array_map('intval', $asignaturaIds));

        $this->definitivas = self::repartir(DB::select(
            'SELECT alumno_id, asignatura_id, periodo, CAST(nota AS DOUBLE) AS nota, CAST(nota_original AS DOUBLE) AS nota_original,
                    nivelada_at, manual, recuperada
               FROM notas_finales
              WHERE alumno_id IN ('.$alumnos.') AND asignatura_id IN ('.$asignaturas.') AND periodo <= ?
              ORDER BY alumno_id, asignatura_id, periodo, id',
            array_merge($ids, [$hastaElPeriodo])
        ));

        $this->ausencias = self::repartir(DB::select(
            'SELECT alumno_id, asignatura_id, id, cantidad_ausencia, cantidad_tardanza, tipo, fecha_hora, created_by, created_at
               FROM ausencias
              WHERE alumno_id IN ('.$alumnos.') AND asignatura_id IN ('.$asignaturas.') AND periodo_id = ? AND deleted_at is null
              ORDER BY id',
            array_merge($ids, [$periodoId])
        ));

        $this->frases = self::repartir(DB::select(
            'SELECT fa.alumno_id, fa.asignatura_id AS asignatura_de_reparto, fa.id, IFNULL(f.frase, fa.frase) as frase, fa.frase_id, fa.asignatura_id,
                    fa.periodo_id, fa.created_by, fa.created_at, f.tipo_frase
               FROM frases_asignatura fa
               left join frases f on f.id=fa.frase_id and f.deleted_at is null
              WHERE fa.deleted_at is null AND fa.alumno_id IN ('.$alumnos.') AND fa.asignatura_id IN ('.$asignaturas.') AND fa.periodo_id = ?
              ORDER BY fa.id',
            array_merge($ids, [$periodoId])
        ), 'asignatura_de_reparto');
    }

    /** @return list<object> */
    public function definitivas(int $alumnoId, int $asignaturaId): array
    {
        return $this->definitivas[$alumnoId.'|'.$asignaturaId] ?? [];
    }

    /** @return list<object> */
    public function ausencias(int $alumnoId, int $asignaturaId): array
    {
        return $this->ausencias[$alumnoId.'|'.$asignaturaId] ?? [];
    }

    /** @return list<object> */
    public function frases(int $alumnoId, int $asignaturaId): array
    {
        return $this->frases[$alumnoId.'|'.$asignaturaId] ?? [];
    }

    /**
     * @param  list<object>  $filas
     * @return array<string, list<object>>
     */
    private static function repartir(array $filas, string $columnaDeAsignatura = 'asignatura_id'): array
    {
        $repartidas = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->alumno_id.'|'.(int) $fila->{$columnaDeAsignatura};
            unset($fila->alumno_id, $fila->{$columnaDeAsignatura});
            $repartidas[$clave][] = $fila;
        }

        return $repartidas;
    }
}
