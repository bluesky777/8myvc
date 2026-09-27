<?php

namespace App\Services\Act;

/**
 * LA CALIFICACIÓN AUTOMÁTICA DEL CUESTIONARIO.
 *
 * Contrato §2.4 (qué califica) y §2.8 (la nota). En la tanda 1 se calcula y se guarda en la hoja
 * (`puntaje`, `puntaje_max`, `nota_calculada`); llevarla a la planilla es la tanda 2.
 *
 *   puntaje      = Σ puntos de las visibles acertadas
 *   puntaje_max  = Σ puntos de las visibles calificables
 *   nota         = round(puntaje / puntaje_max × nota_maxima), mitad hacia arriba; NULL si max = 0
 *
 * Todo o nada por pregunta, salvo `multiple` con `puntaje_parcial = 1` (lo elige el docente por
 * pregunta; Joseth, 26 sep): cada correcta marcada suma `puntos / nº de correctas`, cada incorrecta
 * marcada resta lo mismo, y la pregunta no baja de 0. «Acertó» sigue siendo el conjunto exacto.
 *
 * Las preguntas llegan como arrays con `tipo`, `puntos` y `opciones` (cada una con `id`,
 * `definicion`, `is_correct`).
 */
class Calificador
{
    /** Si la pregunta cuenta para el puntaje. */
    public static function calificable(array $p): bool
    {
        if ((int) $p['puntos'] <= 0) {
            return false;
        }

        if (in_array($p['tipo'], Recorrido::DE_OPCIONES, true)) {
            return true;
        }

        // `corta` califica sólo si tiene respuestas aceptadas (opciones `is_correct`).
        return $p['tipo'] === 'corta' && self::correctas($p) !== [];
    }

    /** @return list<int> los ids de las opciones correctas */
    public static function correctas(array $p): array
    {
        return array_values(array_map(
            fn ($o) => (int) $o['id'],
            array_filter($p['opciones'] ?? [], fn ($o) => (bool) $o['is_correct'])
        ));
    }

    /** Si la respuesta acierta. NULL si la pregunta no se califica. */
    public static function acerto(array $p, ?array $r): ?bool
    {
        if (! self::calificable($p)) {
            return null;
        }

        if ($r === null) {
            return false;
        }

        if ($p['tipo'] === 'corta') {
            $dada = Recorrido::normalizar($r['texto'] ?? '');

            foreach ($p['opciones'] as $o) {
                if ($o['is_correct'] && Recorrido::normalizar($o['definicion']) === $dada && $dada !== '') {
                    return true;
                }
            }

            return false;
        }

        // Una «otra» escrita a mano nunca es la correcta: no es ninguna de las opciones.
        $marcadas = array_values(array_unique(array_map('intval', $r['opcion_ids'] ?? [])));
        $correctas = self::correctas($p);

        sort($marcadas);
        sort($correctas);

        return $correctas !== [] && $marcadas === $correctas;
    }

    /**
     * El puntaje de una hoja.
     *
     * @param  list<array>  $preguntas
     * @param  list<int>  $visibles
     * @param  array<int, array>  $respuestas  por pregunta_id
     * @return array{puntaje: float, puntaje_max: float, correctas: int}
     */
    public static function puntuar(array $preguntas, array $visibles, array $respuestas): array
    {
        $puntaje = 0.0;
        $max = 0.0;
        $bien = 0;

        foreach ($preguntas as $p) {
            if (! in_array((int) $p['id'], $visibles, true) || ! self::calificable($p)) {
                continue;
            }

            $max += (int) $p['puntos'];
            $r = $respuestas[$p['id']] ?? null;

            if (self::acerto($p, $r)) {
                $puntaje += (int) $p['puntos'];
                $bien++;
            } elseif ($p['tipo'] === 'multiple' && ! empty($p['puntaje_parcial'])) {
                $puntaje += self::parcial($p, $r);
            }
        }

        return ['puntaje' => $puntaje, 'puntaje_max' => $max, 'correctas' => $bien];
    }

    /**
     * Lo que vale una `multiple` con puntaje parcial que no acertó entera: `puntos / C` por cada
     * correcta marcada, menos lo mismo por cada incorrecta marcada, sin bajar de 0. Con dos
     * decimales, que es lo que guarda `puntaje`.
     */
    public static function parcial(array $p, ?array $r): float
    {
        $correctas = self::correctas($p);

        if ($r === null || $correctas === []) {
            return 0.0;
        }

        $valor = (int) $p['puntos'] / count($correctas);
        $marcadas = array_unique(array_map('intval', $r['opcion_ids'] ?? []));
        $bien = count(array_intersect($marcadas, $correctas));
        $mal = count($marcadas) - $bien;

        return round(max(0.0, ($bien - $mal) * $valor), 2);
    }

    /** La nota sobre `nota_maxima`, entera, mitad hacia arriba. */
    public static function nota(float $puntaje, float $max, int $notaMaxima): ?int
    {
        if ($max <= 0) {
            return null;
        }

        // Multiplicar antes de dividir: 3 de 6 sobre 50 es 150/6 = 25 exacto, y no 0.5 × 50 con el
        // error del binario encima.
        return (int) round($puntaje * $notaMaxima / $max, 0, PHP_ROUND_HALF_UP);
    }
}
