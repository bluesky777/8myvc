<?php

namespace Tests\Contrato\Concerns;

use App\Services\BoletinIndependiente;
use App\Support\RepartoDeLaNota;
use Illuminate\Support\Facades\DB;

/**
 * **El montaje del lienzo del [43](../../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md),
 * una sola vez** — la planilla con la que se explicó la parcial y la cobertura.
 *
 * Unidad 1 al **70 %** con cuatro indicadores al **30/20/25/25**, de los que sólo el
 * Taller (30) y el Quiz (20) están calificados —**48** y **47**—, y unidad 2 al
 * **30 %** con un indicador al 100 % sin calificar:
 *
 *     acumulada  = 0,70 × (0,30×48 + 0,20×47)        = 0,70 × 23,8  = 16,66   BAJO
 *     parcial    = 16,66 ÷ (0,70×0,30 + 0,70×0,20)   = 16,66 ÷ 0,35 = 47,60   SUPERIOR
 *     cobertura  = 0,35 ÷ 1,00                                      = 0,35
 *
 * ## Por qué es un trait y no dos copias
 *
 * Porque **hay dos calculadores de la definitiva** —`DefinitivasDeAsignatura`, que
 * escribe, y `Asignatura::calculoAlumnoNotas`, que produce el número de la
 * planilla— y la afirmación entera de la fase 1.bis del 43 es que **los dos dicen lo
 * mismo**. Eso no se puede comprobar con dos montajes que se parecen: en cuanto uno
 * de los dos ficheros cambiara un porcentaje, los dos tests seguirían verdes por
 * separado y la afirmación dejaría de estar probada **sin que nada se pusiera rojo**.
 *
 * Con un montaje único, `LaParcialEnLaPlanillaTest` le pasa la MISMA planilla a los
 * dos y compara —`test_los_dos_calculadores_dicen_lo_mismo`—. *Un test de equivalencia sobre dos montajes distintos no
 * compara nada.*
 *
 * ## Los dos indicadores desiguales son el caso, no decoración
 *
 * Aquí hay **2 de 5** casillas calificadas —el 40 %— y la cobertura es **35 %**. Con
 * los pesos iguales los dos números coincidirían y una fórmula que contara casillas
 * pasaría igual. Un indicador del 40 % sin calificar y uno del 5 % no dejan el mismo
 * hueco.
 */
trait LaPlanillaDelLienzo
{
    /** Los cuatro indicadores de la unidad 1, con su nota — `null` es sin calificar. */
    private const UNIDAD_1 = [
        ['porcentaje' => 30, 'nota' => 48],
        ['porcentaje' => 20, 'nota' => 47],
        ['porcentaje' => 25, 'nota' => null],
        ['porcentaje' => 25, 'nota' => null],
    ];

    /**
     * Una subunidad con su nota para un alumno. Devuelve el id de la nota.
     *
     * `nota` viaja tal cual: `null` es **sin calificar**, que desde la fase 0 es lo
     * que la base sabe decir.
     */
    private function casilla(int $unidadId, int $porcentaje, ?int $nota, int $alumnoId): int
    {
        $subunidadId = DB::table('subunidades')->insertGetId([
            'unidad_id' => $unidadId,
            'definicion' => 'INDICADOR AL '.$porcentaje.' %',
            'porcentaje' => $porcentaje,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('notas')->insertGetId([
            'subunidad_id' => $subunidadId,
            'alumno_id' => $alumnoId,
            'nota' => $nota,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * La planilla del lienzo del doc 43, montada sobre una asignatura **vacía**.
     *
     * **La asignatura se elige sin una sola unidad en ese periodo, y se comprueba.**
     * Sin eso, unas unidades del seed entrarían en las mismas sumas y todos los
     * números de arriba dejarían de ser los del lienzo — en silencio, porque el
     * cálculo seguiría siendo correcto.
     *
     * Las notas se crean **sólo para el primer alumno**: el segundo es el caso del
     * alumno sin casillas, que a mitad de periodo es la mayoría.
     *
     * @return array<string, mixed>
     */
    private function laPlanillaDelLienzo(): array
    {
        // El alcance del boletín independiente se resuelve una vez y se cachea; sin
        // olvidarlo, un caso anterior de la misma tanda decide por éste.
        BoletinIndependiente::olvidar();

        $donde = DB::selectOne(
            'SELECT a.id AS asignatura_id, a.grupo_id, p.id AS periodo_id, g.year_id
               FROM asignaturas a
               INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
               INNER JOIN periodos p ON p.year_id = g.year_id AND p.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
                AND (SELECT COUNT(DISTINCT m.alumno_id) FROM matriculas m
                      WHERE m.grupo_id = a.grupo_id AND m.deleted_at IS NULL) >= 2
                AND NOT EXISTS (SELECT 1 FROM unidades u
                                 WHERE u.asignatura_id = a.id AND u.periodo_id = p.id
                                   AND u.deleted_at IS NULL)
              ORDER BY a.id, p.id LIMIT 1'
        );

        $this->assertNotNull($donde,
            'El seed no tiene una asignatura con dos matriculados y sin unidades en algún '
            .'periodo: sin un par limpio, las sumas de este fichero no son las del lienzo.');

        $alumnos = DB::select(
            'SELECT DISTINCT m.alumno_id FROM matriculas m
              WHERE m.grupo_id = ? AND m.deleted_at IS NULL ORDER BY m.alumno_id LIMIT 2',
            [$donde->grupo_id]
        );

        $alumno = (int) $alumnos[0]->alumno_id;

        // El reparto se deja explícito en `porcentaje` aunque sea el defecto: el caso
        // del modo promedio lo cambia, y un defecto heredado no se puede afirmar.
        DB::table('years')->where('id', $donde->year_id)
            ->update(['reparto_subunidades' => RepartoDeLaNota::PORCENTAJE]);

        $unidad1 = (int) DB::table('unidades')->insertGetId([
            'asignatura_id' => $donde->asignatura_id,
            'periodo_id' => $donde->periodo_id,
            'definicion' => 'UNIDAD 1 DEL LIENZO',
            'porcentaje' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notas = [];

        foreach (self::UNIDAD_1 as $indicador) {
            $notas[] = $this->casilla($unidad1, $indicador['porcentaje'], $indicador['nota'], $alumno);
        }

        $unidad2 = (int) DB::table('unidades')->insertGetId([
            'asignatura_id' => $donde->asignatura_id,
            'periodo_id' => $donde->periodo_id,
            'definicion' => 'UNIDAD 2 DEL LIENZO',
            'porcentaje' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notas[] = $this->casilla($unidad2, 100, null, $alumno);

        return [
            'asignatura' => (int) $donde->asignatura_id,
            'periodo' => (int) $donde->periodo_id,
            'year' => (int) $donde->year_id,
            'unidad_1' => $unidad1,
            'unidad_2' => $unidad2,
            'alumno' => $alumno,
            'alumno_sin_notas' => (int) $alumnos[1]->alumno_id,
            'notas' => $notas,
        ];
    }
}
