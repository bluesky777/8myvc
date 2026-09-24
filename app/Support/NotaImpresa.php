<?php

namespace App\Support;

/**
 * La nota tal como se imprime: el entero más cercano.
 *
 * Decisión de producto (24 sep 2026): las notas **conservan los decimales para
 * calcular** —promedios, puestos—, pero el veredicto «¿perdió?» y la banda de
 * desempeño se juzgan sobre **lo que ve el usuario**. Un 59,9 con mínima 60 se
 * imprime «60» y no está perdida; un 59,4 se imprime «59» y sí. Un 69,83 se
 * imprime «70» y lleva la banda que contiene al 70.
 *
 * Es la misma regla que el front (`myvc_front` app2 `nota.pipe.ts`,
 * `notaPerdida`): `Math.round`. Las notas no son negativas, así que el `round()`
 * de PHP —mitad lejos del cero— coincide con él, y con `ROUND(x, 0)` de MySQL
 * sobre `DECIMAL`.
 *
 * **Lo que no es un número se devuelve tal cual** (`null`, `''`): los sitios que
 * comparan dependían de cómo PHP compara esos valores, y redondearlos a 0 cambiaría
 * veredictos que esta decisión no toca.
 */
final class NotaImpresa
{
    public static function valor(mixed $nota): mixed
    {
        return is_numeric($nota) ? round((float) $nota) : $nota;
    }

    /** Perdida = lo impreso queda por debajo de la mínima. */
    public static function perdida(mixed $nota, mixed $minima): bool
    {
        return self::valor($nota) < $minima;
    }
}
