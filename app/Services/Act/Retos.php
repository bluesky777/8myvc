<?php

namespace App\Services\Act;

/**
 * LA CALIFICACIÓN DE LAS PREGUNTAS INTERACTIVAS: los 33 retos del motor de inglés.
 *
 * Contrato §2.4 y §2.8 (tanda 7). El motor vive en el front (`myvc_front/docs/ingles-interactivo/
 * retos.js` y `retos-mas.js`, portado a app2), y **el que califica es el backend**: el front manda
 * el estado del reto (`s`) y aquí se calcula la fracción con una traducción **exacta** del
 * `score(c, s)` de cada tipo, y su `done(c, s)` para saber si está respondida.
 *
 * ## Por qué hay un pequeño JavaScript aquí dentro
 *
 * El estado lo arma el cliente y puede llegar con cualquier forma. Para que la nota no dependa de
 * qué lado la calcula, este fichero reproduce también **cómo se comporta JavaScript** con lo que no
 * cuadra: una propiedad que no existe es `undefined` (no un aviso), leer de `null` lanza, `"3" <= 5`
 * compara números, `[1000, "500"]` sumados son `"1000500"`, una división por cero es `NaN` o
 * `Infinity`. Esas reglas son los métodos de abajo (`g`, `eq`, `num`, `str`, `suma`…), y los objetos
 * del JSON se leen como `stdClass` para distinguir `{}` de `[]`, igual que allá.
 *
 * Y una regla encima, que no es de JavaScript sino del contrato: **lo que lanza vale 0, lo que no es
 * un número finito vale 0, y el resultado se recorta a [0, 1]**. Nunca lanza hacia fuera.
 *
 * La paridad con el JS se comprobó una vez con un arnés que corre los dos lados sobre miles de
 * (cfg, estado) —soluciones, estados iniciales, mutaciones y basura— y compara con tolerancia
 * 1e-9 (1 oct 2026). Si el motor cambia un `score`, cambia aquí en el mismo commit.
 */
final class Retos
{
    /** Las 33 claves de `T`: las 12 de `retos.js` y las 21 de `retos-mas.js`. */
    public const CLAVES = [
        'pesca', 'memoria', 'ordena', 'cestas', 'deletrea', 'desliza', 'escena', 'escucha', 'sopa', 'chat',
        'cangrejos', 'parejas',
        'muneco', 'intruso', 'monstruo', 'colorea', 'reloj', 'tragamonedas', 'crucigrama', 'huecos', 'error',
        'historieta', 'mapa', 'bingo', 'tienda', 'linea', 'dictado', 'evidencia', 'transforma', 'domino',
        'candado', 'meteoros', 'laberinto',
    ];

    /** Topes del contrato (§2.4): bytes del JSON de `config` y de `estado`. */
    public const TOPE_CONFIG = 20000;

    public const TOPE_ESTADO = 20000;

    /** Profundidad máxima al leer un JSON del cliente. */
    private const PROFUNDIDAD = 64;

    // ------------------------------------------------------------------ la cara pública

    /**
     * La fracción [0, 1] de un estado, o 0 si algo no cuadra. `$cfg` y `$estado` como salen de
     * `leer()` (objetos como `stdClass`).
     */
    public static function fraccion(string $reto, mixed $cfg, mixed $estado): float
    {
        if (! in_array($reto, self::CLAVES, true) || $estado === null) {
            return 0.0;
        }

        try {
            $v = [self::class, 'score_'.$reto]($cfg, $estado);
        } catch (\Throwable) {
            return 0.0;
        }

        $v = is_bool($v) ? (float) $v : (is_int($v) || is_float($v) ? (float) $v : NAN);

        if (! is_finite($v)) {
            return 0.0;
        }

        return min(1.0, max(0.0, $v));
    }

    /** Si el reto está terminado (`done` del motor). Lo que lanza, no lo está. */
    public static function terminado(string $reto, mixed $cfg, mixed $estado): bool
    {
        if (! in_array($reto, self::CLAVES, true) || $estado === null) {
            return false;
        }

        try {
            return self::truthy([self::class, 'done_'.$reto]($cfg, $estado));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lee un JSON del cliente como lo leería `JSON.parse`: objetos como `stdClass`, listas como
     * arrays. `null` si no es JSON. (Un `null` del JSON también vuelve `null`: para un estado es lo
     * mismo que no tenerlo.)
     */
    public static function leer(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        try {
            return json_decode($json, false, self::PROFUNDIDAD, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** Un valor ya decodificado (objetos `stdClass`) de vuelta a JSON, para guardarlo tal cual. */
    public static function json(mixed $valor): string
    {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: 'null';
    }

    // ------------------------------------------------------------------ retos.js

    private static function score_pesca($c, $s)
    {
        return self::eq(self::g($s, 'pick'), self::g($c, 'w')) ? 1 : 0;
    }

    private static function done_pesca($c, $s)
    {
        return ! self::nulo(self::g($s, 'pick'));
    }

    private static function score_memoria($c, $s)
    {
        $n = self::g($c, 'pairs', 'length');
        $b = self::div(self::g($s, 'ok', 'length'), $n);

        return self::le(self::g($s, 'tries'), self::num($n) + 2)
            ? $b
            : self::mul(self::max(.5, 1 - (self::num(self::g($s, 'tries')) - self::num($n) - 2) * .1), $b);
    }

    private static function done_memoria($c, $s)
    {
        return self::eq(self::g($s, 'ok', 'length'), self::g($c, 'pairs', 'length'));
    }

    private static function score_ordena($c, $s)
    {
        $t = self::tilesOf($c);
        $linea = array_map(fn ($i) => self::g($t['tiles'], $i), self::arr(self::g($s, 'line')));

        return self::join($linea, ' ') === self::join($t['words'], ' ') ? 1 : 0;
    }

    private static function done_ordena($c, $s)
    {
        return self::gt(self::g($s, 'line', 'length'), 0);
    }

    private static function score_cestas($c, $s)
    {
        $items = self::arr(self::g($c, 'items'));
        $bien = count(array_filter($items, fn ($it) => self::eq(self::g(self::g($s, 'w'), self::g($it, 0)), self::g($it, 2))));

        return self::div($bien, count($items));
    }

    private static function done_cestas($c, $s)
    {
        return self::eq(self::llaves(self::g($s, 'w')), self::g($c, 'items', 'length'));
    }

    private static function score_deletrea($c, $s)
    {
        $words = self::arr(self::g($c, 'words'));
        $bien = 0;

        foreach ($words as $k => $w) {
            $palabra = self::g($w, 0);
            $L = self::lettersOf($palabra);
            $armada = array_map(fn ($i) => self::nulo($i) ? '' : self::g($L, $i), self::arr(self::g(self::g($s, 'f'), $k)));

            if (self::eq(self::join($armada, ''), $palabra)) {
                $bien++;
            }
        }

        return self::div($bien, count($words));
    }

    private static function done_deletrea($c, $s)
    {
        return self::every(self::g($s, 'f'), fn ($r) => self::every($r, fn ($x) => ! self::nulo($x)));
    }

    private static function score_desliza($c, $s)
    {
        return self::cuentaPor($c, 'cards', fn ($k, $i) => self::eq(self::g(self::g($s, 'r'), $i), self::g($k, 2)));
    }

    private static function done_desliza($c, $s)
    {
        return self::every(self::g($s, 'r'), fn ($x) => ! self::nulo($x));
    }

    private static function score_escena($c, $s)
    {
        return self::cuentaPor($c, 'prompts', fn ($p, $i) => self::eq(self::g(self::g($s, 'p'), $i), self::g($p, 1)));
    }

    private static function done_escena($c, $s)
    {
        return self::every(self::g($s, 'p'), fn ($x) => self::truthy($x));
    }

    private static function score_escucha($c, $s)
    {
        return self::cuentaPor($c, 'qs', fn ($q, $i) => self::str(self::g(self::g($s, 'a'), $i)) === self::str(self::g($q, 2)));
    }

    private static function done_escucha($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => ! self::nulo($x));
    }

    private static function score_sopa($c, $s)
    {
        return self::div(self::g($s, 'found', 'length'), self::g($c, 'words', 'length'));
    }

    private static function done_sopa($c, $s)
    {
        return self::gt(self::g($s, 'found', 'length'), 0);
    }

    private static function score_chat($c, $s)
    {
        return self::cuentaPor($c, 'turns', fn ($t, $i) => self::eq(self::g(self::g($s, 'c'), $i), self::g($t, 2)));
    }

    private static function done_chat($c, $s)
    {
        return self::eq(self::g($s, 'c', 'length'), self::g($c, 'turns', 'length'));
    }

    private static function score_cangrejos($c, $s)
    {
        $x = self::div(self::num(self::g($s, 'good', 'length')) - self::num(self::g($s, 'bad')) * .5, self::g($c, 'hits', 'length'));

        return self::max(0, self::min(1, $x));
    }

    private static function done_cangrejos($c, $s)
    {
        return self::g($s, 'end');
    }

    private static function score_parejas($c, $s)
    {
        $a = self::div(self::g($s, 'ok', 'length'), self::g($c, 'pairs', 'length'));

        return self::mul($a, self::max(.3, 1 - self::num(self::g($s, 'err')) * .15));
    }

    private static function done_parejas($c, $s)
    {
        return self::eq(self::g($s, 'ok', 'length'), self::g($c, 'pairs', 'length'));
    }

    // ------------------------------------------------------------------ retos-mas.js

    /** `MAXE`: errores con los que se derrite el muñeco. */
    private const MAXE = 6;

    private static function score_muneco($c, $s)
    {
        $u = array_values(array_unique(self::puntos(self::LET(self::g($c, 'w')))));
        $hit = self::div(count(array_filter($u, fn ($ch) => self::incluye(self::g($s, 'g'), $ch))), count($u));

        return self::lt($hit, 1) ? $hit * .5 : self::max(.4, 1 - self::num(self::g($s, 'err')) * .12);
    }

    private static function done_muneco($c, $s)
    {
        if (self::ge(self::g($s, 'err'), self::MAXE)) {
            return true;
        }

        foreach (self::puntos(self::LET(self::g($c, 'w'))) as $ch) {
            if (! self::incluye(self::g($s, 'g'), $ch)) {
                return false;
            }
        }

        return true;
    }

    private static function score_intruso($c, $s)
    {
        return self::cuentaPor($c, 'rounds', fn ($rd, $i) => self::eq(self::g(self::g($s, 'a'), $i), self::g($rd, 'odd')));
    }

    private static function done_intruso($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => self::truthy($x));
    }

    /** `MPARTS`: las partes del monstruo, por su clave. */
    private const MPARTS = ['eyes', 'arms', 'legs', 'horns'];

    private static function score_monstruo($c, $s)
    {
        $color = self::eq(self::g($s, 'color'), self::g($c, 'color')) ? 1 : 0;
        $partes = count(array_filter(self::MPARTS, fn ($k) => self::eq(self::g($s, $k), self::g($c, $k))));

        return ($color + $partes) / 5;
    }

    private static function done_monstruo($c, $s)
    {
        if (! self::truthy(self::g($s, 'color'))) {
            return false;
        }

        foreach (self::MPARTS as $k) {
            if (self::gt(self::g($s, $k), 0)) {
                return true;
            }
        }

        return false;
    }

    private static function score_colorea($c, $s)
    {
        return self::cuentaPor($c, 'orders', function ($o) use ($s) {
            [$p, $col] = self::desestructura($o, 2);

            return self::eq(self::g(self::g($s, 'fill'), $p), $col);
        });
    }

    private static function done_colorea($c, $s)
    {
        return self::ge(self::llaves(self::g($s, 'fill')), self::g($c, 'orders', 'length'));
    }

    private static function score_reloj($c, $s)
    {
        return self::cuentaPor($c, 'times', function ($t, $i) use ($s) {
            return self::eq(self::mod(self::g(self::g($s, 't'), $i, 0), 12), self::mod(self::g($t, 0), 12))
                && self::eq(self::g(self::g($s, 't'), $i, 1), self::g($t, 1));
        });
    }

    private static function done_reloj($c, $s)
    {
        return self::every(self::g($s, 'moved'), fn ($x) => self::truthy($x));
    }

    private static function score_tragamonedas($c, $s)
    {
        return self::cuentaPor($c, 'reels', fn ($rl, $i) => self::eq(self::g(self::g($s, 'v'), $i), self::g($rl, 0)));
    }

    private static function done_tragamonedas($c, $s)
    {
        return self::g($s, 'touched');
    }

    private static function score_crucigrama($c, $s)
    {
        $L = self::layoutCW(self::g($c, 'words'));
        $bien = 0;

        foreach ($L['placed'] as $p) {
            $todas = true;

            foreach ($p['cells'] as $i => $k) {
                if (! self::eq(self::g(self::g($s, 'g'), $k), self::g($p['w'], $i))) {
                    $todas = false;
                    break;
                }
            }

            $bien += $todas ? 1 : 0;
        }

        return self::div($bien, count($L['placed']));
    }

    private static function done_crucigrama($c, $s)
    {
        $L = self::layoutCW(self::g($c, 'words'));

        foreach (array_keys($L['sol']) as $k) {
            if (! self::truthy(self::g(self::g($s, 'g'), (string) $k))) {
                return false;
            }
        }

        return true;
    }

    private static function score_huecos($c, $s)
    {
        $G = self::parseCloze(self::g($c, 'text'));
        $bien = 0;

        foreach ($G as $i => $o) {
            $bien += self::eq(self::g(self::g($s, 'a'), $i), self::g($o, 0)) ? 1 : 0;
        }

        return self::div($bien, count($G));
    }

    private static function done_huecos($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => self::truthy($x));
    }

    private static function score_error($c, $s)
    {
        $items = self::arr(self::g($c, 'items'));
        $t = 0;

        foreach ($items as $k => $it) {
            $a = self::g(self::g($s, 'a'), $k);
            $ok = self::eq(self::g($a, 'i'), self::errIdx($it));
            $t = $t + ($ok ? .5 : 0) + ($ok && self::clean(self::g($a, 'fix')) === self::clean(self::g($it, 2)) ? .5 : 0);
        }

        return self::div($t, count($items));
    }

    private static function done_error($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => ! self::nulo(self::g($x, 'i')) && self::truthy(self::trim(self::g($x, 'fix'))));
    }

    private static function score_historieta($c, $s)
    {
        $o = self::arr(self::g($s, 'o'));
        $bien = 0;

        foreach ($o as $i => $x) {
            $bien += self::eq($x, $i) ? 1 : 0;
        }

        return self::div($bien, self::g($c, 'panels', 'length'));
    }

    private static function done_historieta($c, $s)
    {
        return self::every(self::g($s, 'o'), fn ($x) => ! self::nulo($x));
    }

    private static function score_mapa($c, $s)
    {
        $a = self::walk(self::g($s, 'cmds'));
        $b = self::walk(self::g($c, 'steps'));

        return $a[0] === $b[0] && $a[1] === $b[1] ? 1 : 0;
    }

    private static function done_mapa($c, $s)
    {
        return self::gt(self::g($s, 'cmds', 'length'), 0);
    }

    private static function score_bingo($c, $s)
    {
        $card = self::arr(self::g($c, 'card'));
        $on = array_values(array_filter(self::arr(self::g($c, 'calls')), function ($w) use ($card) {
            foreach ($card as $x) {
                if (self::eq(self::g($x, 0), $w)) {
                    return true;
                }
            }

            return false;
        }));
        $hit = count(array_filter(self::arr(self::g($s, 'm')), fn ($w) => self::incluye($on, $w)));
        $bad = count(array_filter(self::arr(self::g($s, 'm')), fn ($w) => ! self::incluye($on, $w)));

        return self::max(0, self::div($hit - $bad, count($on)));
    }

    private static function done_bingo($c, $s)
    {
        return self::ge(self::g($s, 'k'), self::g($c, 'calls', 'length'));
    }

    private static function score_tienda($c, $s)
    {
        return self::cuentaPor($c, 'items', function ($it, $i) use ($s) {
            $total = 0;

            foreach (self::arr(self::g(self::g($s, 'pay'), $i)) as $b) {
                $total = self::suma($total, $b);
            }

            return self::eq($total, self::g($it, 2));
        });
    }

    private static function done_tienda($c, $s)
    {
        return self::every(self::g($s, 'pay'), fn ($p) => self::gt(self::g($p, 'length'), 0));
    }

    private static function score_linea($c, $s)
    {
        return self::cuentaPor($c, 'items', fn ($it, $i) => self::eq(self::g(self::g($s, 'a'), $i), self::g($it, 1)));
    }

    private static function done_linea($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => self::truthy($x));
    }

    private static function score_dictado($c, $s)
    {
        $items = self::arr(self::g($c, 'items'));
        $t = 0;

        foreach ($items as $i => $it) {
            $t = $t + self::wordSim(self::g(self::g($s, 'a'), $i), $it);
        }

        return self::div($t, count($items));
    }

    private static function done_dictado($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => self::truthy(self::trim($x)));
    }

    private static function score_evidencia($c, $s)
    {
        return (self::eq(self::g($s, 'a'), self::g($c, 'opts', 0)) ? .5 : 0) + (self::eq(self::g($s, 'ev'), self::g($c, 'ev')) ? .5 : 0);
    }

    private static function done_evidencia($c, $s)
    {
        return ! self::nulo(self::g($s, 'a')) && ! self::nulo(self::g($s, 'ev'));
    }

    private static function score_transforma($c, $s)
    {
        $items = self::arr(self::g($c, 'items'));
        $t = 0;

        foreach ($items as $i => $it) {
            $w = self::wordSim(self::g(self::g($s, 'a'), $i), self::g($it, 2));
            $t = $t + ($w === 1.0 ? 1 : ($w >= .75 ? .5 : 0));
        }

        return self::div($t, count($items));
    }

    private static function done_transforma($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => self::truthy(self::trim($x)));
    }

    private static function score_domino($c, $s)
    {
        $T2 = self::tilesOf2($c);
        $pa = [];

        foreach (self::arr(self::g($c, 'pairs')) as $p) {
            if (! is_array($p) && ! $p instanceof \stdClass && ! $p instanceof \Closure) {
                self::lanza(); // `Object.fromEntries` sólo acepta entradas que sean objetos.
            }

            $pa['k'.self::str(self::g($p, 0))] = self::g($p, 1);
        }

        $chain = [self::g($T2, 0)];

        foreach (self::arr(self::g($s, 'o')) as $i) {
            $chain[] = self::g($T2, $i);
        }

        $ok = 0;

        for ($i = 1; $i < count($chain); $i++) {
            $clave = 'k'.self::str(self::g($chain[$i - 1], 1));

            if (self::eq(array_key_exists($clave, $pa) ? $pa[$clave] : self::u(), self::g($chain[$i], 0))) {
                $ok++;
            }
        }

        return self::div($ok, self::g($c, 'pairs', 'length'));
    }

    private static function done_domino($c, $s)
    {
        return self::eq(self::g($s, 'o', 'length'), self::g($c, 'pairs', 'length'));
    }

    private static function score_candado($c, $s)
    {
        return self::cuentaPor($c, 'clues', fn ($q, $i) => self::eq(self::g(self::g($s, 'd'), $i), self::g($q, 2)));
    }

    private static function done_candado($c, $s)
    {
        return self::every(self::g($s, 'd'), fn ($x) => ! self::nulo($x));
    }

    private static function score_meteoros($c, $s)
    {
        return self::cuentaPor($c, 'words', fn ($w, $i) => self::norm(self::g(self::g($s, 'a'), $i)) === self::norm(self::g($w, 0)));
    }

    private static function done_meteoros($c, $s)
    {
        return self::ge(self::g($s, 'k'), self::g($c, 'words', 'length'));
    }

    private static function score_laberinto($c, $s)
    {
        return self::cuentaPor($c, 'rounds', fn ($rd, $i) => self::eq(self::g(self::g($s, 'a'), $i), self::g($rd, 1)));
    }

    private static function done_laberinto($c, $s)
    {
        return self::every(self::g($s, 'a'), fn ($x) => self::truthy($x));
    }

    // ------------------------------------------------------------------ ayudantes del motor

    /** `c[lista].filter(f).length / c[lista].length`, el patrón de casi todos los `score`. */
    private static function cuentaPor($c, string $lista, callable $f): float
    {
        $items = self::arr(self::g($c, $lista));
        $bien = 0;

        foreach ($items as $i => $it) {
            $bien += $f($it, $i) ? 1 : 0;
        }

        return self::div($bien, count($items));
    }

    /** `tilesOf` de `ordena`: las palabras de la oración y las fichas barajadas con la trampa. */
    private static function tilesOf($c): array
    {
        $s = self::g($c, 's');
        $sinPunto = (string) preg_replace('/[.?!]+$/D', '', self::cadena($s));
        $words = array_values(array_filter(self::split($sinPunto), fn ($w) => $w !== ''));

        return ['words' => $words, 'tiles' => self::shuf(array_merge($words, self::csv(self::g($c, 'extra'))), $s)];
    }

    /** `DECOY` y `lettersOf` de `deletrea`: las letras de la palabra y una que sobra, barajadas. */
    private const DECOY = 'BCDFGHKLMNPRSTW';

    private static function lettersOf($W): array
    {
        // `W.includes` y `[...W]` sirven con un texto y con una lista; con lo demás, `TypeError`.
        $partes = is_array($W) ? array_values($W) : self::puntos(self::cadena($W));
        $d = array_values(array_filter(str_split(self::DECOY), fn ($x) => ! self::incluye($partes, $x)));
        $extra = $d === [] ? self::u() : $d[self::hash($W) % count($d)];

        return self::shuf(array_merge($partes, [$extra]), $W);
    }

    /** `LET`: sólo las letras, en mayúsculas. */
    private static function LET($w): string
    {
        return (string) preg_replace('/[^A-Z]/', '', mb_strtoupper(self::str(self::truthy($w) ? $w : ''), 'UTF-8'));
    }

    /** `layoutCW` del crucigrama, paso por paso: el mismo orden, los mismos empates. */
    private static function layoutCW($words): array
    {
        $W = [];

        foreach (self::arr($words) as $k => $x) {
            $W[] = ['w' => self::LET(self::g($x, 0)), 'k' => $k];
        }

        $W = array_values(array_filter($W, fn ($o) => strlen($o['w']) >= 2));
        // `sort` de JS es estable desde ES2019, y `usort` de PHP desde la 8.0.
        usort($W, fn ($a, $b) => strlen($b['w']) - strlen($a['w']));

        /** @var array<string, string> $cells */
        $cells = [];
        $placed = [];
        $K = fn ($x, $y) => $x.','.$y;

        $can = function (string $w, int $x, int $y, int $dir) use (&$cells, $K): int {
            $dx = $dir ? 0 : 1;
            $dy = $dir ? 1 : 0;
            $n = strlen($w);

            if (isset($cells[$K($x - $dx, $y - $dy)]) || isset($cells[$K($x + $dx * $n, $y + $dy * $n)])) {
                return 0;
            }

            $cross = 0;

            for ($i = 0; $i < $n; $i++) {
                $cx = $x + $dx * $i;
                $cy = $y + $dy * $i;
                $c = $cells[$K($cx, $cy)] ?? null;

                if ($c !== null) {
                    if ($c !== $w[$i]) {
                        return 0;
                    }

                    $cross++;
                } elseif (isset($cells[$K($cx + $dy, $cy + $dx)]) || isset($cells[$K($cx - $dy, $cy - $dx)])) {
                    return 0;
                }
            }

            return $cross;
        };

        $put = function (array $o, int $x, int $y, int $dir) use (&$cells, &$placed, $K): void {
            for ($i = 0; $i < strlen($o['w']); $i++) {
                $cells[$K($x + ($dir ? 0 : $i), $y + ($dir ? $i : 0))] = $o['w'][$i];
            }

            $placed[] = $o + ['x' => $x, 'y' => $y, 'dir' => $dir];
        };

        foreach ($W as $n => $o) {
            if ($n === 0) {
                $put($o, 0, 0, 0);

                continue;
            }

            $best = null;

            foreach ($placed as $p) {
                for ($i = 0; $i < strlen($p['w']); $i++) {
                    for ($j = 0; $j < strlen($o['w']); $j++) {
                        if ($p['w'][$i] === $o['w'][$j]) {
                            $dir = 1 - $p['dir'];
                            $px = $p['x'] + ($p['dir'] ? 0 : $i);
                            $py = $p['y'] + ($p['dir'] ? $i : 0);
                            $x = $px - ($dir ? 0 : $j);
                            $y = $py - ($dir ? $j : 0);
                            $c = $can($o['w'], $x, $y, $dir);

                            if ($c && ($best === null || $c > $best['c'])) {
                                $best = ['x' => $x, 'y' => $y, 'dir' => $dir, 'c' => $c];
                            }
                        }
                    }
                }
            }

            if ($best !== null) {
                $put($o, $best['x'], $best['y'], $best['dir']);
            }
        }

        if ($placed === []) {
            return ['placed' => [], 'sol' => []];
        }

        $xs = [];
        $ys = [];

        foreach ($placed as $p) {
            array_push($xs, $p['x'], $p['x'] + ($p['dir'] ? 0 : strlen($p['w']) - 1));
            array_push($ys, $p['y'], $p['y'] + ($p['dir'] ? strlen($p['w']) - 1 : 0));
        }

        $mx = min($xs);
        $my = min($ys);

        foreach ($placed as &$p) {
            $p['x'] -= $mx;
            $p['y'] -= $my;
        }

        unset($p);
        usort($placed, fn ($a, $b) => ($a['y'] - $b['y']) ?: ($a['x'] - $b['x']));

        $sol = [];

        foreach ($placed as &$p) {
            $p['cells'] = [];

            for ($i = 0; $i < strlen($p['w']); $i++) {
                $p['cells'][] = $K($p['x'] + ($p['dir'] ? 0 : $i), $p['y'] + ($p['dir'] ? $i : 0));
            }

            foreach ($p['cells'] as $i => $k) {
                $sol[$k] = $p['w'][$i];
            }
        }

        unset($p);

        return ['placed' => $placed, 'sol' => $sol];
    }

    /** `parseCloze` de `huecos`: las opciones de cada hueco `{buena|mala|mala}`, la buena primero. */
    private static function parseCloze($t): array
    {
        $gaps = [];
        $partes = preg_split('/\{([^}]*)\}/u', self::str(self::truthy($t) ? $t : ''), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($partes as $i => $x) {
            if ($i % 2) {
                $gaps[] = array_values(array_filter(array_map(fn ($y) => self::trim($y), explode('|', $x)), fn ($y) => $y !== ''));
            }
        }

        return $gaps;
    }

    /** `clean` y `errIdx` de `error`. */
    private static function clean($w): string
    {
        return (string) preg_replace("/[^a-z']/", '', mb_strtolower(self::str($w), 'UTF-8'));
    }

    private static function errIdx($it): int
    {
        $buscada = self::clean(self::g($it, 1));

        foreach (self::split(self::cadena(self::g($it, 0))) as $i => $x) {
            if (self::clean($x) === $buscada) {
                return $i;
            }
        }

        return -1;
    }

    /** `walk` de `mapa`: dónde acaba el personaje tras los pasos. @return array{0: int, 1: int} */
    private static function walk($cmds): array
    {
        $N = 4;
        $DIRS = [[0, -1], [1, 0], [0, 1], [-1, 0]];
        $x = 0;
        $y = $N - 1;
        $d = 0;

        foreach (self::arr($cmds) as $c) {
            if (self::eq($c, 'L')) {
                $d = ($d + 3) % 4;
            } elseif (self::eq($c, 'R')) {
                $d = ($d + 1) % 4;
            } else {
                $nx = $x + $DIRS[$d][0];
                $ny = $y + $DIRS[$d][1];

                if ($nx < 0 || $ny < 0 || $nx >= $N || $ny >= $N) {
                    continue;
                }

                $x = $nx;
                $y = $ny;
            }
        }

        return [$x, $y];
    }

    /** `tilesOf2` del dominó: las fichas en orden, de estrella a estrella. */
    private static function tilesOf2($c): array
    {
        $P = self::arr(self::g($c, 'pairs'));
        $t = [['★', self::g(self::g($P, 0), 0)]];

        for ($i = 1; $i < count($P); $i++) {
            $t[] = [self::g($P[$i - 1], 1), self::g($P[$i], 0)];
        }

        $t[] = [self::g(self::g($P, count($P) - 1), 1), '★'];

        return $t;
    }

    /** `norm`, `lcs` y `wordSim` de `dictado` y `transforma`. */
    private static function norm($t): string
    {
        $t = mb_strtolower(self::str(self::truthy($t) ? $t : ''), 'UTF-8');
        $t = (string) \Normalizer::normalize($t, \Normalizer::FORM_D);
        $t = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $t);
        $t = str_replace(['’', '`'], "'", $t);
        $t = (string) preg_replace("/[^a-z0-9' ]/u", ' ', $t);
        $t = (string) preg_replace('/'.self::ESPACIO.'+/u', ' ', $t);

        return self::trim($t);
    }

    private static function lcs(array $a, array $b): int
    {
        $m = array_fill(0, count($a) + 1, array_fill(0, count($b) + 1, 0));

        for ($i = 1; $i <= count($a); $i++) {
            for ($j = 1; $j <= count($b); $j++) {
                $m[$i][$j] = $a[$i - 1] === $b[$j - 1] ? $m[$i - 1][$j - 1] + 1 : max($m[$i - 1][$j], $m[$i][$j - 1]);
            }
        }

        return $m[count($a)][count($b)];
    }

    private static function wordSim($got, $want): float
    {
        $a = array_values(array_filter(explode(' ', self::norm($got)), fn ($x) => $x !== ''));
        $b = array_values(array_filter(explode(' ', self::norm($want)), fn ($x) => $x !== ''));

        return $b !== [] ? fdiv(self::lcs($a, $b), max(count($a), count($b))) : 0.0;
    }

    /** `csv`: separado por comas, recortado y sin vacíos. @return list<string> */
    private static function csv($s): array
    {
        return array_values(array_filter(array_map(fn ($x) => self::trim($x), explode(',', self::str(self::truthy($s) ? $s : ''))), fn ($x) => $x !== ''));
    }

    // ------------------------------------------------------------------ el azar con semilla (32 bits)

    /** `hash`: FNV-1a sobre las unidades UTF-16 (la primera de cada carácter), como `charCodeAt(0)`. */
    private static function hash($s): int
    {
        $h = 2166136261;

        foreach (self::puntos(self::str($s)) as $ch) {
            $cp = $ch[0] === "\xFF" ? (unpack('n', substr($ch, 1, 2)) ?: [1 => 0])[1] : mb_ord($ch, 'UTF-8');
            $unidad = $cp > 0xFFFF ? 0xD800 + (($cp - 0x10000) >> 10) : $cp;
            $h = self::i32(self::u32($h) ^ $unidad);
            $h = self::imul($h, 16777619);
        }

        return self::u32($h);
    }

    /** `rngOf` (mulberry32): un generador por semilla. */
    private static function rngOf(int $seed): \Closure
    {
        $a = self::u32($seed);

        return function () use (&$a): float {
            $a = self::i32($a + 0x6D2B79F5);
            $t = self::imul(self::i32(self::u32($a) ^ (self::u32($a) >> 15)), self::i32(1 | self::u32($a)));
            $t = self::i32(self::u32($t + self::imul(self::i32(self::u32($t) ^ (self::u32($t) >> 7)), self::i32(61 | self::u32($t)))) ^ self::u32($t));

            return self::u32(self::u32($t) ^ (self::u32($t) >> 14)) / 4294967296;
        };
    }

    /** `shuf`: Fisher-Yates con la semilla (número tal cual, o `hash` del texto). */
    private static function shuf(array $arr, $seed): array
    {
        $r = self::rngOf(self::esNumero($seed) ? (int) self::u32((int) fmod((float) $seed, 4294967296.0)) : self::hash($seed));
        $a = array_values($arr);

        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = (int) floor($r() * ($i + 1));
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return $a;
    }

    private static function u32(int $x): int
    {
        return $x & 0xFFFFFFFF;
    }

    private static function i32(int $x): int
    {
        $x &= 0xFFFFFFFF;

        return $x >= 0x80000000 ? $x - 0x100000000 : $x;
    }

    private static function imul(int $a, int $b): int
    {
        return self::i32(self::i32($a) * self::i32($b));
    }

    // ------------------------------------------------------------------ JavaScript, lo justo

    /** Los espacios de `\s` y de `trim()` en JavaScript. */
    private const ESPACIO = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /** `undefined`: un objeto único, distinto de todo lo que sale de un JSON. */
    private static function u(): object
    {
        static $u = null;

        return $u ??= new class {};
    }

    /** Leer de `null` o de `undefined`: el `TypeError` de JavaScript. */
    private static function lanza(): never
    {
        throw new \DomainException('TypeError');
    }

    /** `o[k1][k2]…`: propiedad por propiedad, con la semántica de JavaScript. */
    private static function g($o, ...$claves)
    {
        foreach ($claves as $k) {
            $o = self::prop($o, $k);
        }

        return $o;
    }

    private static function prop($o, $k)
    {
        if ($o === null || $o === self::u()) {
            self::lanza();
        }

        $k = is_string($k) ? $k : (is_int($k) ? (string) $k : self::str($k));

        if (is_array($o)) {
            if ($k === 'length') {
                return count($o);
            }

            return preg_match('/^(0|[1-9]\d{0,9})$/', $k) && (int) $k < count($o) ? $o[(int) $k] : self::heredado('Array', $k);
        }

        if ($o instanceof \stdClass) {
            return property_exists($o, $k) ? $o->{$k} : self::heredado('Object', $k);
        }

        if ($o instanceof \Closure) {
            [$nombre, $aridad] = self::$funciones[spl_object_id($o)];

            return match ($k) {
                'name' => $nombre,
                'length' => $aridad,
                'caller', 'arguments' => self::lanza(),
                default => self::heredado('Function', $k),
            };
        }

        if (self::esNumero($o) || is_bool($o)) {
            return self::heredado(is_bool($o) ? 'Boolean' : 'Number', $k);
        }

        if (is_string($o)) {
            $u = self::utf16($o);

            if ($k === 'length') {
                return intdiv(strlen($u), 2);
            }

            if (preg_match('/^(0|[1-9]\d{0,9})$/', $k) && (int) $k < intdiv(strlen($u), 2)) {
                $par = substr($u, 2 * (int) $k, 2);
                $cu = (unpack('n', $par) ?: [1 => 0])[1];

                // Media pareja suelta: una cadena que ningún JSON válido puede traer.
                return $cu >= 0xD800 && $cu <= 0xDFFF ? "\xFF".$par : mb_convert_encoding($par, 'UTF-8', 'UTF-16BE');
            }

            return self::heredado('String', $k);
        }

        return self::u();
    }

    /**
     * Los métodos de los prototipos de JavaScript (nombre => `length`). `s.fill` con `s` una lista es
     * `Array.prototype.fill`, no `undefined`: leerle una propiedad no lanza y da `undefined`.
     */
    private const PROTOTIPOS = [
        'Object' => ['constructor' => 1, '__defineGetter__' => 2, '__defineSetter__' => 2, 'hasOwnProperty' => 1, '__lookupGetter__' => 1, '__lookupSetter__' => 1,
            'isPrototypeOf' => 1, 'propertyIsEnumerable' => 1, 'toString' => 0, 'valueOf' => 0, 'toLocaleString' => 0],
        'Array' => ['constructor' => 1, 'at' => 1, 'concat' => 1, 'copyWithin' => 2, 'fill' => 1, 'find' => 1, 'findIndex' => 1, 'findLast' => 1,
            'findLastIndex' => 1, 'lastIndexOf' => 1, 'pop' => 0, 'push' => 1, 'reverse' => 0, 'shift' => 0, 'unshift' => 1, 'slice' => 2, 'sort' => 1,
            'splice' => 2, 'includes' => 1, 'indexOf' => 1, 'join' => 1, 'keys' => 0, 'entries' => 0, 'values' => 0, 'forEach' => 1, 'filter' => 1,
            'flat' => 0, 'flatMap' => 1, 'map' => 1, 'every' => 1, 'some' => 1, 'reduce' => 1, 'reduceRight' => 1, 'toReversed' => 0, 'toSorted' => 1,
            'toSpliced' => 2, 'with' => 2, 'toLocaleString' => 0, 'toString' => 0],
        'String' => ['constructor' => 1, 'anchor' => 1, 'at' => 1, 'big' => 0, 'blink' => 0, 'bold' => 0, 'charAt' => 1, 'charCodeAt' => 1,
            'codePointAt' => 1, 'concat' => 1, 'endsWith' => 1, 'fontcolor' => 1, 'fontsize' => 1, 'fixed' => 0, 'includes' => 1, 'indexOf' => 1,
            'isWellFormed' => 0, 'italics' => 0, 'lastIndexOf' => 1, 'link' => 1, 'localeCompare' => 1, 'match' => 1, 'matchAll' => 1, 'normalize' => 0,
            'padEnd' => 1, 'padStart' => 1, 'repeat' => 1, 'replace' => 2, 'replaceAll' => 2, 'search' => 1, 'slice' => 2, 'small' => 0, 'split' => 2,
            'strike' => 0, 'sub' => 0, 'substr' => 2, 'substring' => 2, 'sup' => 0, 'startsWith' => 1, 'toString' => 0, 'toWellFormed' => 0, 'trim' => 0,
            'trimStart' => 0, 'trimLeft' => 0, 'trimEnd' => 0, 'trimRight' => 0, 'toLocaleLowerCase' => 0, 'toLocaleUpperCase' => 0,
            'toLowerCase' => 0, 'toUpperCase' => 0, 'valueOf' => 0],
        'Number' => ['constructor' => 1, 'toExponential' => 1, 'toFixed' => 1, 'toPrecision' => 1, 'toString' => 1, 'valueOf' => 0, 'toLocaleString' => 0],
        'Boolean' => ['constructor' => 1, 'toString' => 0, 'valueOf' => 0],
        'Function' => ['constructor' => 1, 'apply' => 2, 'bind' => 1, 'call' => 1, 'toString' => 0],
    ];

    /** @var array<int, array{0: string, 1: int}> nombre y `length` de cada función, por `spl_object_id` */
    private static array $funciones = [];

    /**
     * Lo que `x[k]` encuentra en la cadena de prototipos: una función (un `Closure` único por método,
     * para que `===` sea identidad) o `undefined`.
     */
    private static function heredado(string $proto, string $k)
    {
        static $cache = [];

        foreach ([$proto, 'Object'] as $p) {
            if (! isset(self::PROTOTIPOS[$p][$k])) {
                continue;
            }

            // `trimLeft` y `trimRight` son la misma función que `trimStart` y `trimEnd`.
            $nombre = $k === 'constructor' ? $p : (string) preg_replace(['/^trimLeft$/D', '/^trimRight$/D'], ['trimStart', 'trimEnd'], $k);
            $clave = $p.'.'.$nombre;

            if (! isset($cache[$clave])) {
                $cache[$clave] = fn () => null;
                self::$funciones[spl_object_id($cache[$clave])] = [$nombre, self::PROTOTIPOS[$p][$k]];
            }

            return $cache[$clave];
        }

        return self::u();
    }

    /** Llamar a un método de lista (`map`, `filter`, `every`…) exige una lista. */
    private static function arr($x): array
    {
        if (! is_array($x)) {
            self::lanza();
        }

        return $x;
    }

    private static function every($x, callable $f): bool
    {
        foreach (self::arr($x) as $v) {
            if (! self::truthy($f($v))) {
                return false;
            }
        }

        return true;
    }

    /** `x.includes(v)`: en una lista, con `===`; en un texto, como subcadena. */
    private static function incluye($x, $v): bool
    {
        if (is_array($x)) {
            foreach ($x as $e) {
                if (self::eq($e, $v)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($x)) {
            return str_contains($x, self::str($v));
        }

        self::lanza();
    }

    /** `const [a, b] = x`: sólo con listas y textos (lo iterable); lo que falta es `undefined`. */
    private static function desestructura($x, int $n): array
    {
        if (is_string($x)) {
            $x = self::puntos($x);
        } elseif (! is_array($x)) {
            self::lanza();
        }

        $fuera = [];

        for ($i = 0; $i < $n; $i++) {
            $fuera[] = array_key_exists($i, $x) ? $x[$i] : self::u();
        }

        return $fuera;
    }

    /** `Object.keys(x).length`. */
    private static function llaves($x): int
    {
        return match (true) {
            $x === null, $x === self::u() => self::lanza(),
            is_array($x) => count($x),
            $x instanceof \stdClass => count(get_object_vars($x)),
            is_string($x) => intdiv(strlen(self::utf16($x)), 2),
            default => 0,
        };
    }

    /** `x.trim()`: sólo existe en los textos. */
    private static function trim($x): string
    {
        if (! is_string($x)) {
            self::lanza();
        }

        return (string) preg_replace('/^'.self::ESPACIO.'+|'.self::ESPACIO.'+$/uD', '', $x);
    }

    /** `x.split(/\s+/)`. */
    private static function split(string $s): array
    {
        return preg_split('/'.self::ESPACIO.'+/u', $s) ?: [];
    }

    /** Una media pareja UTF-16 suelta (`"🍎"[0]`), como la escribe `prop`: `\xFF` y sus dos bytes. */
    private const MEDIA = '/(\xFF[\x00-\xFF]{2})/';

    /** `[...x]`: los caracteres (puntos de código) de un texto; una media pareja suelta es uno. */
    private static function puntos(string $s): array
    {
        $fuera = [];

        foreach (preg_split(self::MEDIA, $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $trozo) {
            array_push($fuera, ...($trozo[0] === "\xFF" ? [$trozo] : mb_str_split($trozo, 1, 'UTF-8')));
        }

        return $fuera;
    }

    /** Un valor sobre el que el motor llama a un método de texto (`replace`, `split`, `includes`). */
    private static function cadena($x): string
    {
        if (! is_string($x)) {
            self::lanza();
        }

        return $x;
    }

    private static function utf16(string $s): string
    {
        $fuera = '';

        foreach (preg_split(self::MEDIA, $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $trozo) {
            $fuera .= $trozo[0] === "\xFF" ? substr($trozo, 1) : mb_convert_encoding($trozo, 'UTF-16BE', 'UTF-8');
        }

        return $fuera;
    }

    /** `Array.prototype.join`: `null` y `undefined` van vacíos. */
    private static function join(array $a, string $sep): string
    {
        return implode($sep, array_map(fn ($x) => self::nulo($x) ? '' : self::str($x), $a));
    }

    private static function esNumero($x): bool
    {
        return is_int($x) || is_float($x);
    }

    /** `x == null`. */
    private static function nulo($x): bool
    {
        return $x === null || $x === self::u();
    }

    /** `===` entre valores de un JSON: números por valor, objetos nunca. */
    private static function eq($a, $b): bool
    {
        if (self::esNumero($a) && self::esNumero($b)) {
            return (float) $a === (float) $b;
        }

        if (is_string($a) || is_bool($a) || $a === null || $a === self::u()) {
            return $a === $b;
        }

        // Dos objetos son el mismo sólo si son el mismo (`===` de PHP entre objetos es identidad, como
        // en JS). Dos listas, nunca: PHP las compara por valor y JS por identidad.
        if ($a instanceof \stdClass || $a instanceof \Closure) {
            return $a === $b;
        }

        return false;
    }

    private static function truthy($x): bool
    {
        return match (true) {
            $x === null, $x === self::u() => false,
            is_bool($x) => $x,
            is_int($x) => $x !== 0,
            is_float($x) => ! is_nan($x) && $x != 0.0,
            is_string($x) => $x !== '',
            default => true,
        };
    }

    /** `ToPrimitive`: listas y objetos pasan a texto. */
    private static function primitivo($x)
    {
        return is_array($x) || $x instanceof \stdClass || $x instanceof \Closure ? self::str($x) : $x;
    }

    /** `Number(x)`. */
    private static function num($x): float
    {
        $x = self::primitivo($x);

        if ($x === null || $x === false) {
            return 0.0;
        }

        if ($x === true) {
            return 1.0;
        }

        if (self::esNumero($x)) {
            return (float) $x;
        }

        if (! is_string($x)) {
            return NAN;
        }

        $t = self::trim($x);

        if ($t === '') {
            return 0.0;
        }

        if (preg_match('/^[+-]?Infinity$/D', $t)) {
            return $t[0] === '-' ? -INF : INF;
        }

        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $t, $m)) {
            return (float) hexdec($m[1]);
        }

        if (preg_match('/^0[oO]([0-7]+)$/D', $t, $m)) {
            return (float) octdec($m[1]);
        }

        if (preg_match('/^0[bB]([01]+)$/D', $t, $m)) {
            return (float) bindec($m[1]);
        }

        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $t)) {
            return (float) $t;
        }

        return NAN;
    }

    /** `String(x)`. */
    private static function str($x): string
    {
        return match (true) {
            $x === self::u() => 'undefined',
            $x === null => 'null',
            is_bool($x) => $x ? 'true' : 'false',
            is_string($x) => $x,
            self::esNumero($x) => self::numeroATexto((float) $x),
            is_array($x) => self::join($x, ','),
            $x instanceof \Closure => 'function '.self::$funciones[spl_object_id($x)][0].'() { [native code] }',
            default => '[object Object]',
        };
    }

    /** `Number.prototype.toString()`: la forma más corta que vuelve al mismo número, a la manera de JS. */
    private static function numeroATexto(float $n): string
    {
        if (is_nan($n)) {
            return 'NaN';
        }

        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }

        if ($n == 0.0) {
            return '0';
        }

        $signo = $n < 0 ? '-' : '';
        // `serialize_precision = -1` da la forma más corta que vuelve al número; de ahí salen los
        // dígitos y el exponente, y se escriben con las reglas de JS (ECMA-262, Number::toString).
        $corta = strtoupper(var_export(abs($n), true));

        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:E([+-]?\d+))?$/D', $corta, $m)) {
            return $signo.$corta;
        }

        $digitos = $m[1].($m[2] ?? '');
        $exp = strlen($m[1]) + (int) ($m[3] ?? 0);
        $sinCeros = ltrim($digitos, '0');
        $exp -= strlen($digitos) - strlen($sinCeros);
        $digitos = rtrim($sinCeros, '0');
        $k = strlen($digitos);

        if ($k <= $exp && $exp <= 21) {
            return $signo.$digitos.str_repeat('0', $exp - $k);
        }

        if ($exp > 0 && $exp <= 21) {
            return $signo.substr($digitos, 0, $exp).'.'.substr($digitos, $exp);
        }

        if ($exp > -6 && $exp <= 0) {
            return $signo.'0.'.str_repeat('0', -$exp).$digitos;
        }

        $e = $exp - 1;

        return $signo.$digitos[0].($k > 1 ? '.'.substr($digitos, 1) : '').'e'.($e < 0 ? '-' : '+').abs($e);
    }

    /** `a + b`: si uno es texto, se pegan. */
    private static function suma($a, $b)
    {
        $a = self::primitivo($a);
        $b = self::primitivo($b);

        if (is_string($a) || is_string($b)) {
            return self::str($a).self::str($b);
        }

        return self::num($a) + self::num($b);
    }

    private static function div($a, $b): float
    {
        return fdiv(self::num($a), self::num($b));
    }

    private static function mul($a, $b): float
    {
        return self::num($a) * self::num($b);
    }

    private static function mod($a, $b): float
    {
        return fmod(self::num($a), self::num($b));
    }

    /** `Math.max` y `Math.min`: con un `NaN`, `NaN`. */
    private static function max($a, $b): float
    {
        $a = self::num($a);
        $b = self::num($b);

        return is_nan($a) || is_nan($b) ? NAN : max($a, $b);
    }

    private static function min($a, $b): float
    {
        $a = self::num($a);
        $b = self::num($b);

        return is_nan($a) || is_nan($b) ? NAN : min($a, $b);
    }

    /** `<`, `<=`, `>`, `>=`: dos textos se comparan como texto; lo demás, como números. */
    private static function compara($a, $b): ?int
    {
        $a = self::primitivo($a);
        $b = self::primitivo($b);

        if (is_string($a) && is_string($b)) {
            return strcmp(self::utf16($a), self::utf16($b)) <=> 0;
        }

        $x = self::num($a);
        $y = self::num($b);

        return is_nan($x) || is_nan($y) ? null : $x <=> $y;
    }

    private static function lt($a, $b): bool
    {
        return self::compara($a, $b) === -1;
    }

    private static function le($a, $b): bool
    {
        $c = self::compara($a, $b);

        return $c !== null && $c <= 0;
    }

    private static function gt($a, $b): bool
    {
        return self::compara($a, $b) === 1;
    }

    private static function ge($a, $b): bool
    {
        $c = self::compara($a, $b);

        return $c !== null && $c >= 0;
    }
}
