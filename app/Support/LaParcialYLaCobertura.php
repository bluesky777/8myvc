<?php

namespace App\Support;

/**
 * **La nota sobre lo que ya se evaluó, y cuánto se ha evaluado.** Un solo sitio para
 * las dos fórmulas y para la regla de qué casilla cuenta.
 *
 * Es la [Fase 2 del 43](../../docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md)
 * llegando al camino que de verdad lee la pantalla del semáforo, que no era ninguno de
 * los siete del censo. Las fases 1 y 1.bis dieron estos dos números a
 * `DefinitivasDeAsignatura` y a `Asignatura::calculoAlumnoNotas`; el boletín no pasa por
 * ninguno de los dos.
 *
 * ## Qué son los dos números
 *
 *     nota_parcial = SUM(peso x nota) / SUM(peso)   <- SOLO sobre las calificadas
 *     cobertura    = SUM(peso) calificadas / SUM(peso) de las que existen
 *
 * `nota_asignatura` —la acumulada de siempre— contesta *«cuánto del periodo entero lleva
 * ganado»*, que a mitad de periodo no es una nota: es una barra de progreso, y pintada con
 * la escala del colegio dice BAJO de casi todo el mundo. La parcial contesta *«cómo va en lo
 * que se le ha evaluado»*, y la cobertura dice cuánto vale esa respuesta. **Con el reparto
 * bien hecho y todo calificado las dos son el mismo número**, porque `peso_total` vale
 * entonces 10.000; la parcial sólo existe como cifra distinta mientras falte algo.
 *
 * ## LO QUE DECIDE QUE UNA CASILLA CUENTE, Y ES DONDE ESTO SE ROMPE SOLO
 *
 * Son **dos preguntas distintas** y confundirlas es el bug del 43 otra vez:
 *
 * - **¿existe la fila?** Si la subunidad no tiene fila en `notas` para ese alumno, no está
 *   en el plan de nadie y no entra **ni en el divisor ni en el total**. `calculoAlumnoNotas`
 *   lo resuelve con `count($nota) > 0` sobre su propia consulta; aquí la fila llega por un
 *   **`LEFT JOIN`** (`Subunidad::deUnidadCalculada`), así que la subunidad sin fila **vuelve
 *   igual** con `nota_id` a `null`. Por eso la existencia se mira en `nota_id` y **no** en
 *   `nota`: mirando `nota` se contarían como «sin calificar» subunidades que no le tocan a
 *   este alumno, el peso total saldría inflado y **la cobertura del boletín sería más baja
 *   que la de la planilla para el mismo alumno**. Dos pantallas discrepando con el mismo dato
 *   delante, y ninguna de las dos en rojo.
 * - **¿está calificada?** `nota !== null`, desde la fase 0. **No `> 0`**: el `0` que teclea un
 *   docente es una nota que alguien puso —3.940 en la copia de desarrollo— y el `null` es la
 *   ausencia. Confundirlos aquí reintroduce el bug entero dentro del divisor.
 *
 * ## EL NUMERADOR SE RECALCULA, NO SE LEE DE `notas_finales`
 *
 * Decisión de Joseth del 20 sep 2026, con las dos opciones y su precio delante. El boletín
 * tiene a mano la definitiva **guardada** y sería más barato dividir ésa, pero:
 *
 * 1. **Tras la Fase 4, un periodo cerrado con `fuera` guarda la definitiva YA normalizada.**
 *    Dividirla otra vez por el peso evaluado normaliza dos veces y produce un número más
 *    bajo, plausible y sin significado — en un papel que se firma, y sin ninguna segunda
 *    cifra al lado que lo contradiga, porque con corte esa hoja no imprime la acumulada.
 * 2. **La parcial del boletín tiene que ser la misma que la de la planilla** para el mismo
 *    alumno. Se consigue calculándola igual: pesando `s.porcentaje` crudo, como
 *    `calculoAlumnoNotas`, y no como `DefinitivasDeAsignatura`.
 *
 * **El precio, dicho entero y no descubierto luego:** en modo `promedio` los dos calculadores
 * ya discrepan hoy —14 pares de 99 en el 2026 de la copia, hasta 42,3 puntos sobre una escala
 * de 0 a 50—, así que la parcial que sale de aquí **no cumplirá** `parcial x SUM(peso) ==
 * nota_asignatura` contra la `nota_asignatura` que el boletín publica, que es la guardada. El
 * invariante se cumple contra el numerador de aquí, que no viaja. No es un fallo nuevo: es la
 * discrepancia que ya existía, ahora con un número al lado que la deja ver. Unificar los dos
 * calculadores es un lote aparte y una decisión de Joseth (§7 del 43).
 *
 * ## Por qué esto devuelve números y `RepartoDeLaNota` devuelve SQL
 *
 * Aquél no pudo ser una función que devolviera un `float` porque sus dieciséis sitios viven
 * dentro de cadenas SQL y el que multiplica es MySQL. Aquí es al revés: los tres llamantes
 * **ya tienen las unidades y las subunidades cargadas en memoria** —las cargan para pintar el
 * boletín— así que los dos números salen de dos sumas sobre lo que ya está, sin una consulta
 * más.
 */
final class LaParcialYLaCobertura
{
    /**
     * La nota sobre lo evaluado. `null` cuando no hay nada calificado.
     *
     * Un 0 ahí afirmaría que le fue mal, que es exactamente la mentira que el 43 viene a
     * quitar. El `* 10000` repone los dos `/100` que el acumulador entero se ahorró: el
     * divisor real es `$pesoEvaluado / 10000`, así que dividir por él es multiplicar por
     * 10.000 y dividir por el entero. Una operación de coma flotante en vez de cincuenta.
     */
    public static function parcial(float|int $notaAsignatura, int $pesoEvaluado): ?float
    {
        return $pesoEvaluado === 0
            ? null
            : (float) (($notaAsignatura * 10000) / $pesoEvaluado);
    }

    /**
     * Qué parte del plan se ha evaluado, como **factor de 0 a 1**. `null` cuando no hay nada
     * que calificar.
     *
     * `null` y no 0: un 0 afirmaría que no se ha evaluado nada de un plan que existe, y aquí
     * lo que pasa es que no hay plan. Son cosas distintas y la pantalla las pinta distinto
     * —la casilla del `%` vacía, no un cero—.
     *
     * **El `(float)` no fija el tipo en el JSON, y conviene no creérselo**: sin
     * `JSON_PRESERVE_ZERO_FRACTION`, `json_encode(0.0)` sale `0` y `json_encode(1.0)` sale
     * `1`, así que al cliente le llegan enteros en los extremos. Quien lo lea en el front
     * **compara valores, no tipos**.
     */
    public static function cobertura(int $pesoEvaluado, int $pesoTotal): ?float
    {
        return $pesoTotal === 0
            ? null
            : (float) ($pesoEvaluado / $pesoTotal);
    }

    /**
     * Los tres acumuladores, sobre las unidades **ya cargadas** por
     * `Unidad::deAsignaturaCalculada` + `Subunidad::deUnidadCalculada`.
     *
     * Espera la forma plana de esas dos consultas: `porcentaje_unidad` en la unidad y
     * `nota_id`, `porcentaje_subunidad` y `nota` en cada subunidad. No consulta nada.
     *
     * Los dos `(int)` del peso no son cosmética: `porcentaje` es `int DEFAULT 0` **anulable**
     * en las dos tablas, y un `null` tiene que pesar 0 —que es lo que ya hace la línea del
     * numerador, donde `nota * null / 100` da 0—. En SQL daría `NULL` y `SUM` saltaría la
     * fila; aquí el divisor tiene que seguir a la acumulada, no al otro camino.
     *
     * @param  array<int, \stdClass>  $unidades
     * @return array{nota_asignatura: float, peso_evaluado: int, peso_total: int}
     */
    public static function deLasUnidades(array $unidades): array
    {
        $notaAsignatura = 0.0;
        $pesoEvaluado = 0;
        $pesoTotal = 0;

        foreach ($unidades as $unidad) {
            $notaUnidad = 0;

            foreach ($unidad->subunidades as $subunidad) {
                // No hay fila en `notas` para este alumno: la subunidad no le toca. Ver la
                // cabecera — es la mitad de la regla que el `LEFT JOIN` hace fácil de perder.
                if ($subunidad->nota_id === null) {
                    continue;
                }

                $notaUnidad += ($subunidad->nota * $subunidad->porcentaje_subunidad) / 100;

                $peso = (int) $unidad->porcentaje_unidad * (int) $subunidad->porcentaje_subunidad;

                $pesoTotal += $peso;

                if ($subunidad->nota !== null) {
                    $pesoEvaluado += $peso;
                }
            }

            $notaAsignatura += ($notaUnidad * $unidad->porcentaje_unidad) / 100;
        }

        return [
            'nota_asignatura' => (float) $notaAsignatura,
            'peso_evaluado' => $pesoEvaluado,
            'peso_total' => $pesoTotal,
        ];
    }
}
