<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Las notas que se quedan atrás cuando un alumno cambia de grupo.
 *
 * ── EL PROBLEMA, MEDIDO ─────────────────────────────────────────────────────────────────────
 *
 * Mover a un chico de 4A a 4B **no toca ni una fila de notas**: `Matricula::matricularUno`
 * (`app/Models/Matricula.php:305`) reutiliza la matrícula y le cambia el `grupo_id`, y ahí acaba.
 * Pero el boletín no se arma desde las notas: se arma **desde el grupo**. `Grupo::alumnos`
 * (`Grupo.php:153`) saca la lista por `matriculas.grupo_id`, y `Grupo::detailed_materias_notafinal`
 * (`Grupo.php:345`) parte de las **asignaturas del grupo pedido** y hace `LEFT JOIN` a
 * `notas_finales` por ese `asignatura_id`.
 *
 * Consecuencia: las definitivas del periodo 1 hechas en 4A cuelgan de las asignaturas **de 4A**,
 * así que el boletín de 4B sale con esas materias **en blanco**, y el de 4A ya no lista al chico.
 * Los datos no se pierden —vuelven solos si regresa a 4A—, pero mientras tanto no los ve nadie, y
 * la única puerta para recuperarlos era «Promocionar notas», que hay que saber que existe.
 *
 * ── LO QUE SÍ SOBREVIVE, Y POR QUÉ IMPORTA ──────────────────────────────────────────────────
 *
 * **El comportamiento no se pierde**: `nota_comportamiento` es `(alumno_id, periodo_id)` y no
 * nombra al grupo, así que sigue al alumno solo. Decirlo ahorra el arreglo que no hace falta.
 *
 * ── LO QUE NO SE TRAE, Y SE DICE ────────────────────────────────────────────────────────────
 *
 * **Sólo las definitivas.** Las notas de subunidad cuelgan de las unidades de la asignatura de
 * 4A, y traerlas sería copiar la estructura entera del periodo —eso es `periodos/copiar`, que es
 * otra operación y toca al grupo, no a una persona—. Es el mismo trato que da «Promocionar
 * notas», que copia definitivas y comportamiento «sin frases ni unidades/subunidades».
 *
 * **El pareo va por `materia_id`**, que es lo único que sobrevive a cambiar de grupo: la
 * asignatura «Matemáticas de 4A» y la de 4B son dos filas con el mismo `materia_id`. Lo que no
 * tenga pareja en el grupo nuevo se dice y no se copia — 4B puede no dar la misma materia.
 */
class NotasAlCambiarDeGrupo
{
    /**
     * Qué hay atrás. No escribe.
     *
     * @return array<string,mixed>
     */
    public static function revisar(int $alumnoId, int $grupoOrigen, int $grupoDestino): array
    {
        $filas = self::loQueHay($alumnoId, $grupoOrigen, $grupoDestino);

        $porPeriodo = [];
        $sinPareja = [];

        foreach ($filas as $f) {
            if ($f->asig_destino === null) {
                $sinPareja[$f->materia] = true;

                continue;
            }

            $clave = (int) $f->periodo_id;
            $porPeriodo[$clave] ??= [
                'periodo_id' => $clave,
                'numero' => (int) $f->periodo_numero,
                /*
                 * EL AÑO, porque el número solo no basta. En el docker salieron dos periodos
                 * «1» en la misma revisión —el grupo arrastra notas de más de un año— y en la
                 * pantalla eran dos filas idénticas entre las que no se podía elegir.
                 */
                'year' => (int) $f->periodo_year,
                'definitivas' => 0,
                'pisaria' => 0,
            ];
            $porPeriodo[$clave]['definitivas']++;

            // Ya hay nota en el grupo nuevo: traerla la reemplaza, y eso se avisa antes.
            if ($f->nota_destino !== null) {
                $porPeriodo[$clave]['pisaria']++;
            }
        }

        // Por año y número, que es como se leen: «2024 · Periodo 1», «2024 · Periodo 2»…
        uasort($porPeriodo, static fn ($a, $b) => [$a['year'], $a['numero']] <=> [$b['year'], $b['numero']]);

        return [
            'periodos' => array_values($porPeriodo),
            'definitivas' => array_sum(array_column($porPeriodo, 'definitivas')),
            'sin_pareja' => array_keys($sinPareja),
            /* Se dice aquí para que la pantalla no tenga que saberlo. Ver la cabecera. */
            'comportamiento_se_queda' => true,
        ];
    }

    /**
     * Y las trae.
     *
     * `$periodos` son los que se eligieron en la pantalla; vacío significa todos. Se marca
     * `manual = 1`, igual que hace «Promocionar notas»: una definitiva que no sale del cálculo de
     * sus unidades tiene que decir que la puso una persona, o el siguiente recálculo la pisa.
     *
     * @param  list<int>  $periodos
     * @return array<string,mixed>
     */
    public static function traer(int $alumnoId, int $grupoOrigen, int $grupoDestino, array $periodos, ?int $quien): array
    {
        $filas = self::loQueHay($alumnoId, $grupoOrigen, $grupoDestino);

        $creadas = 0;
        $pisadas = 0;

        DB::transaction(function () use ($filas, $periodos, $alumnoId, $quien, &$creadas, &$pisadas) {
            foreach ($filas as $f) {
                if ($f->asig_destino === null) {
                    continue;
                }
                if ($periodos !== [] && ! in_array((int) $f->periodo_id, $periodos, true)) {
                    continue;
                }

                if ($f->nota_destino_id !== null) {
                    DB::table('notas_finales')->where('id', $f->nota_destino_id)->update([
                        'nota' => $f->nota, 'manual' => 1, 'updated_by' => $quien, 'updated_at' => Reloj::ahora(),
                    ]);
                    $pisadas++;

                    continue;
                }

                DB::table('notas_finales')->insert([
                    'alumno_id' => $alumnoId,
                    'asignatura_id' => $f->asig_destino,
                    'periodo_id' => $f->periodo_id,
                    'periodo' => $f->periodo,
                    'nota' => $f->nota,
                    'manual' => 1,
                    'updated_by' => $quien,
                    'created_at' => Reloj::ahora(),
                    'updated_at' => Reloj::ahora(),
                ]);
                $creadas++;
            }
        });

        return ['creadas' => $creadas, 'pisadas' => $pisadas, 'total' => $creadas + $pisadas];
    }

    /**
     * Las definitivas del alumno en el grupo viejo, con su pareja en el nuevo si la tiene.
     *
     * @return list<object>
     */
    private static function loQueHay(int $alumnoId, int $grupoOrigen, int $grupoDestino): array
    {
        return array_values(DB::select(
            'SELECT nf.nota, nf.periodo, nf.periodo_id, p.numero AS periodo_numero, y.year AS periodo_year,
                    m.materia AS materia,
                    ad.id AS asig_destino,
                    nd.id AS nota_destino_id, nd.nota AS nota_destino
             FROM notas_finales nf
             INNER JOIN asignaturas ao ON ao.id = nf.asignatura_id AND ao.grupo_id = ?
                    AND ao.deleted_at IS NULL
             INNER JOIN materias m ON m.id = ao.materia_id
             INNER JOIN periodos p ON p.id = nf.periodo_id
             INNER JOIN years y ON y.id = p.year_id
             LEFT JOIN asignaturas ad ON ad.grupo_id = ? AND ad.materia_id = ao.materia_id
                    AND ad.deleted_at IS NULL
             LEFT JOIN notas_finales nd ON nd.alumno_id = nf.alumno_id AND nd.asignatura_id = ad.id
                    AND nd.periodo_id = nf.periodo_id
             WHERE nf.alumno_id = ?
             ORDER BY y.year, p.numero, m.materia',
            [$grupoOrigen, $grupoDestino, $alumnoId]));
    }
}
