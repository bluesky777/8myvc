<?php

namespace App\Services;

use App\Models\Grupo;
use App\Support\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **SIN LLAMADORES desde el 24 sep 2026** (Joseth): el puesto se calcula al vuelo
 * siempre, como la definitiva — «si un directivo edita una nota en una emergencia, el
 * informe se recalcula aunque salga distinto del impreso; lo importante es que el
 * historial queda». El cierre ya no llama a {@see tomar()} y `ponerPuestos` ya no llama
 * a {@see deLosAlumnos()}. Queda como memoria de lo que se probó (ff370d2) hasta que
 * alguien la borre; la tabla `puestos_del_cierre` se queda vacía.
 *
 * **La foto del puesto al cerrar el periodo** — fase 4 del cierre de periodo
 * (`myvc_front/PLAN-CIERRE-DE-PERIODO.md`, propuesta C, decisiones 4 y 5 de Joseth del
 * 23 sep 2026).
 *
 * ## El problema que arregla
 *
 * El puesto no se guardaba en ninguna tabla: se calculaba al vuelo con las definitivas
 * vigentes. Como nivelar sube la definitiva, **reimprimir en diciembre el boletín
 * entregado en septiembre daba otro puesto, también a quien no niveló nada**
 * (`INVESTIGACION-EL-PUESTO.md:14-18`).
 *
 * ## Lo que hace
 *
 * - {@see tomar()} la escribe el cierre (`PUT periodos/toggle-profes-pueden-editar-notas`),
 *   después de aplicar la política de lo no calificado, para que la foto sea la de las
 *   definitivas que quedaron.
 * - {@see deLosAlumnos()} la lee `BoletinIndependiente::ponerPuestos`, y sólo cuando el
 *   informe es **de un solo periodo** y ese periodo **está cerrado**. Con el periodo
 *   reabierto —una rendija— manda el cálculo vivo, que es lo que se está corrigiendo.
 *
 * **Decisión 5: al recerrar, la foto se rehace siempre.** Joseth lo eligió sabiendo que
 * devuelve el «dos papeles, dos números», con la mitigación de que el informe rotule
 * *«puesto a fecha de cierre: 27 sep»*: por eso cada fila lleva `congelado_at`, y
 * `ponerPuestos` la pasa al alumno como `puesto_congelado_at`.
 *
 * ## Lo que NO congela, a propósito
 *
 * - **El promedio que se imprime ni las definitivas.** Sólo el puesto. La definitiva de un
 *   periodo cerrado ya no la reescribe imprimir un boletín (17 sep), y la nivelación la
 *   sube por diseño: que el boletín la enseñe vieja con una línea de novedad al pie es la
 *   segunda mitad de la propuesta C, y no está construida.
 * - **Los informes de varios periodos** (finales, promovidos, certificados): Joseth eligió
 *   que el anual salga de las vigentes (§4 del plan, pregunta abierta).
 * - **Los periodos cerrados antes de que esto existiera**: no tienen foto, siguen al vuelo.
 *
 * ## Con qué promedio se hace la foto
 *
 * El de `BoletinesController::allNotasAlumno`, que es el que usan también `boletines2`,
 * `editnota` y `notas-actuales`: la media de `nota_asignatura` de
 * `Grupo::detailed_materias_notafinal` sobre las asignaturas del grupo, con la vacía
 * contando 0. Se llama a **la misma consulta** y no a una equivalente escrita aquí,
 * porque la foto tiene que dar, el día del cierre, el mismo puesto que imprime el
 * boletín ese día — y esa consulta tiene sus rarezas (el `inner join profesores` deja
 * fuera la asignatura sin docente) que una copia perdería.
 */
class PuestosDelCierre
{
    /**
     * Rehace la foto del periodo entero: borra la que hubiera y escribe una fila por
     * alumno matriculado en cada grupo del año del periodo.
     *
     * @return int cuántos alumnos quedaron con foto
     */
    public static function tomar(int $periodoId, ?int $userId): int
    {
        if (! self::hayTabla()) {
            return 0;
        }

        $periodo = DB::selectOne('SELECT id, year_id FROM periodos WHERE id = ?', [$periodoId]);

        if ($periodo === null) {
            return 0;
        }

        $yearId = (int) $periodo->year_id;
        $ahora = Reloj::ahora()->format('Y-m-d H:i:s');
        $filas = [];

        $grupos = DB::select(
            'SELECT id FROM grupos WHERE year_id = ? AND deleted_at IS NULL',
            [$yearId]
        );

        foreach ($grupos as $grupo) {
            $alumnos = Grupo::alumnos((int) $grupo->id);

            foreach ($alumnos as $alumno) {
                $alumno->promedio = self::promedio((int) $alumno->alumno_id, (int) $grupo->id, $periodoId, $yearId);
            }

            BoletinIndependiente::ponerPuestos($alumnos, [$periodoId], $yearId);

            foreach ($alumnos as $alumno) {
                $filas[(int) $alumno->alumno_id] = [
                    'periodo_id' => $periodoId,
                    'grupo_id' => (int) $grupo->id,
                    'alumno_id' => (int) $alumno->alumno_id,
                    'promedio' => $alumno->promedio,
                    'puesto' => $alumno->puesto,
                    'congelado_at' => $ahora,
                    'congelado_por' => $userId,
                ];
            }
        }

        // Borrar y escribir en la misma transacción: una foto a medias es peor que la
        // anterior entera. Las claves por alumno ya dejaron una fila por alumno aunque
        // alguno saliera en dos grupos del mismo año.
        DB::transaction(function () use ($periodoId, $filas) {
            DB::table('puestos_del_cierre')->where('periodo_id', $periodoId)->delete();

            foreach (array_chunk(array_values($filas), 500) as $tanda) {
                DB::table('puestos_del_cierre')->insert($tanda);
            }
        });

        return count($filas);
    }

    /**
     * La foto de estos alumnos en este periodo, **sólo si el periodo está cerrado**.
     *
     * @param  list<int>  $alumnoIds
     * @return array<int, object{puesto: ?int, congelado_at: string}> por `alumno_id`
     */
    public static function deLosAlumnos(int $periodoId, array $alumnoIds): array
    {
        if ($alumnoIds === [] || ! self::hayTabla()) {
            return [];
        }

        $cerrado = DB::selectOne(
            'SELECT profes_pueden_editar_notas FROM periodos WHERE id = ? AND deleted_at IS NULL',
            [$periodoId]
        );

        if ($cerrado === null || (int) $cerrado->profes_pueden_editar_notas !== 0) {
            return [];
        }

        $filas = DB::table('puestos_del_cierre')
            ->where('periodo_id', $periodoId)
            ->whereIn('alumno_id', $alumnoIds)
            ->get(['alumno_id', 'puesto', 'congelado_at']);

        $porAlumno = [];

        foreach ($filas as $fila) {
            $porAlumno[(int) $fila->alumno_id] = $fila;
        }

        return $porAlumno;
    }

    /** El promedio del boletín del periodo, con la consulta del boletín. */
    private static function promedio(int $alumnoId, int $grupoId, int $periodoId, int $yearId): float
    {
        $asignaturas = Grupo::detailed_materias_notafinal($alumnoId, $grupoId, $periodoId, $yearId);

        if (count($asignaturas) === 0) {
            return 0;
        }

        $suma = 0;

        foreach ($asignaturas as $asignatura) {
            $suma += $asignatura->nota_asignatura;
        }

        return $suma / count($asignaturas);
    }

    /**
     * El código se despliega colegio por colegio y la migración va con él; un colegio con
     * el código nuevo y la tabla sin crear tiene que seguir cerrando e imprimiendo.
     */
    private static function hayTabla(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('puestos_del_cierre');
    }
}
