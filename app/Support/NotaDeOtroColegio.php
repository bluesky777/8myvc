<?php

namespace App\Support;

use App\Models\EscalaDeValoracion;
use Illuminate\Support\Facades\DB;

/**
 * UNA NOTA DE OTRO COLEGIO, PASADA A LA ESCALA DE ÉSTE  *(24 sep 2026)*.
 *
 * **Por tramos, anclando la mínima aprobatoria**, que es lo que decidió Joseth: lo que allá
 * aprobaba, aquí aprueba.
 *
 *     [mín_allá … aprueba_allá]  ->  [mín_aquí … aprueba_aquí]
 *     [aprueba_allá … máx_allá]  ->  [aprueba_aquí … máx_aquí]
 *
 * Con proporción simple, un 3,0 sobre 5 --aprobado allá-- da 60 sobre 100, que en un colegio que
 * aprueba con 70 es Bajo. Ver `myvc_front/NOTAS-DE-OTRO-COLEGIO.md` §1.3.
 *
 * **Una nota en letras o en palabras** (S, A, Bs, Bj, «Superior»…) no tiene número: se toma el
 * punto medio de la banda de ese desempeño en la escala de aquí. Y si no se entiende, no se inventa:
 * la nota queda sin convertir y se imprime la original.
 */
final class NotaDeOtroColegio
{
    /** Las abreviaturas de la escala nacional (Decreto 1290) y sus nombres. */
    private const LETRAS = [
        's' => 'superior', 'superior' => 'superior', 'e' => 'superior', 'excelente' => 'superior',
        'a' => 'alto', 'alto' => 'alto',
        'bs' => 'basico', 'b' => 'basico', 'basico' => 'basico', 'aceptable' => 'basico',
        'bj' => 'bajo', 'bajo' => 'bajo', 'i' => 'bajo', 'insuficiente' => 'bajo', 'd' => 'bajo', 'deficiente' => 'bajo',
    ];

    /**
     * La escala de destino: la del año `actual` de este colegio. Un año de otro colegio puede no
     * existir en `years`, así que se usa la escala con la que hoy se certifica.
     *
     * @return array{min: float, max: float, aprueba: float, bandas: array}|null null sin escala.
     */
    public static function destino(): ?array
    {
        $year = DB::selectOne('SELECT id, nota_minima_aceptada FROM years WHERE actual = 1 AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        if (! $year) {
            return null;
        }

        // Sin ORDER BY a propósito: es la misma consulta de `detailedNotasGrupo`, así que la leyenda
        // de escalas del certificado sale en el mismo orden en los años de fuera y en los de aquí.
        $bandas = DB::select('SELECT desempenio, porc_inicial, porc_final FROM escalas_de_valoracion
            WHERE year_id = ? AND deleted_at IS NULL', [$year->id]);
        if (! $bandas) {
            return null;
        }

        return [
            'min' => (float) min(array_map(fn ($b) => $b->porc_inicial, $bandas)),
            'max' => (float) max(array_map(fn ($b) => $b->porc_final, $bandas)),
            'aprueba' => (float) $year->nota_minima_aceptada,
            'bandas' => $bandas,
        ];
    }

    /**
     * @param  array{min: float, max: float, aprueba: float}  $origen
     * @return array{nota: float|null, desempenio: string|null}
     */
    public static function convertir(string $original, array $origen, array $destino): array
    {
        $texto = trim($original);
        $numero = str_replace(',', '.', $texto);

        if ($texto !== '' && is_numeric($numero)) {
            $n = max($origen['min'], min($origen['max'], (float) $numero));
            $nota = self::porTramos($n, $origen, $destino);
        } else {
            $nota = self::puntoMedioDe($texto, $destino);
        }

        if ($nota === null) {
            return ['nota' => null, 'desempenio' => null];
        }

        // La nota como la imprime este colegio: sin decimales en una escala de 0 a 50 o 100, con uno
        // en una de 1 a 5 o de 0 a 10.
        $nota = round($nota, $destino['max'] > 10 ? 0 : 1);
        $banda = EscalaDeValoracion::valoracion($nota, $destino['bandas']);

        return ['nota' => $nota, 'desempenio' => $banda->desempenio ?: null];
    }

    private static function porTramos(float $n, array $o, array $d): float
    {
        if ($o['aprueba'] <= $o['min'] || $o['aprueba'] >= $o['max']) {
            // Una escala sin mínima aprobatoria dentro: sólo queda la proporción.
            return $d['min'] + ($n - $o['min']) / max(0.0001, $o['max'] - $o['min']) * ($d['max'] - $d['min']);
        }

        return $n < $o['aprueba']
            ? $d['min'] + ($n - $o['min']) / ($o['aprueba'] - $o['min']) * ($d['aprueba'] - $d['min'])
            : $d['aprueba'] + ($n - $o['aprueba']) / ($o['max'] - $o['aprueba']) * ($d['max'] - $d['aprueba']);
    }

    private static function puntoMedioDe(string $texto, array $destino): ?float
    {
        $clave = self::llano($texto);
        $buscado = self::LETRAS[$clave] ?? null;
        if ($buscado === null) {
            return null;
        }

        foreach ($destino['bandas'] as $banda) {
            if (self::llano((string) $banda->desempenio) === $buscado) {
                return ((float) $banda->porc_inicial + (float) $banda->porc_final) / 2;
            }
        }

        return null;
    }

    private static function llano(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', '.' => '']);
    }
}
