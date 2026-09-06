#!/usr/bin/env php
<?php

/*
 * Cuántas filas ya guardadas llevan la hora escrita DOS VECES, por colegio.
 *
 *     docker exec 8myvc-app-1 php tools/hora-escrita-dos-veces.php simonbolivar
 *     docker exec 8myvc-app-1 php tools/hora-escrita-dos-veces.php --csv colegio1 colegio2 ...
 *     php tools/hora-escrita-dos-veces.php --csv $(cat /ruta/colegios.txt) > hora.csv
 *     php tools/hora-escrita-dos-veces.php --control        # sin base y sin árbol
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE
 *
 * `Carbon::now('America/Bogota')->format('Y-m-d G:H:i')` escribía la hora dos
 * veces: `G` y `H` son las dos la hora del día —una sin cero delante y otra con
 * él—, así que el formato era `hora:hora:minutos` y **los segundos no llegaban
 * nunca**. Las 21:07:33 se guardaban como **21:21:07**. Arreglado en los dos
 * sitios que escribían (05 §121 y §123); **las filas ya escritas no las arregla
 * el commit**, y eso es la decisión C del 09.
 *
 * La decisión estaba parada sobre una frase del ESTADO-ACTUAL —*«se midió y el
 * dato no distingue una fila mal escrita de una normal»*— que es cierta **de una
 * fila suelta** y falsa del conjunto: hay firma, y esto la cuenta.
 *
 * ## La firma
 *
 * En `hora:hora:minutos` el campo de los MINUTOS se queda con el valor de la
 * HORA, y el de los segundos con el minuto real. O sea que una fila escrita por
 * el bug cumple, siempre:
 *
 *     HOUR(col) = MINUTE(col)
 *
 * Se mantiene en las 24 horas, **también en las de una cifra**, que era la parte
 * que había que medir y no suponer: a las 09:07:33 la cadena sale
 * `2026-09-05 9:09:07` —`G` da "9" y `H` da "09"— y los dos motores la aceptan
 * sin warning y guardan 09:09:07. Comprobado el 5 sep 2026 contra
 * **MySQL 8.0.42** (el docker) y **MariaDB 10.5.29** (la serie de producción,
 * que corre 10.5.25): las dos, seis horas de prueba, mismo resultado. No es un
 * detalle: si MariaDB hubiera rechazado la cadena de una cifra, el daño de las
 * horas 0–9 tendría otra forma —nula o fecha cero— y esto contaría de menos.
 *
 * ## Y la firma tiene falsos positivos, así que va con su modelo nulo
 *
 * Una fila escrita bien a las 21:21 también cumple `HOUR = MINUTE`. Lo que este
 * guion compara NO es contra `N/60`:
 *
 *     esperadas = SUM_v [ n(HOUR=v) * n(MINUTE=v) ] / N
 *
 * o sea la coincidencia bajo independencia, con las marginales REALES de esa
 * columna en ESE colegio. `N/60` supone que los minutos son uniformes y en una
 * columna que escribe una persona no lo son: sobre `ausencias.fecha_hora` de
 * `simonbolivar`, `N/60` daba 758 esperadas contra 931 observadas —un 1,23× que
 * se lee como daño— y el modelo de arriba da 953,7, o sea **0,98×: ruido**. Ese
 * falso 1,23× salió en la primera pasada de esta misma medición, y es la razón
 * de que el modelo nulo esté dentro de la herramienta y no en la cabeza de quien
 * la corre.
 *
 * ## Y una razón no es un veredicto: el PISO DE DETECCIÓN
 *
 * La segunda pasada de esta herramienta llamó **DAÑO** a un control sano
 * —`change_asked.created_at`, 5 filas de 104 con 2,0 esperadas, razón 2,51×—
 * porque «el doble de lo esperado» no quiere decir nada cuando lo esperado es 2.
 * Con Poisson, ver 5 donde se esperan 2 pasa **una de cada veinte veces**.
 *
 * Así que el veredicto no lo da la razón sola: una columna sale `DAÑO` cuando lo
 * observado dobla lo esperado **y además** la cola de Poisson deja esa cuenta por
 * debajo de 1 entre 1.000. De ahí sale el número que va en la columna `piso`:
 * **cuántas filas del bug harían falta en ESA columna para que se pudieran ver**.
 * Y su porcentaje, que es el que hay que leer: un piso de `12 (14%)` dice que en
 * esa columna un daño menor del 14% es invisible desde aquí. Cuando el piso es
 * mayor que la población, la columna **no puede contestar** y sale
 * `NO CONCLUYENTE` en vez de un `ruido` que se leería como «limpio».
 *
 * ## Lo que NO contesta
 *
 * **No distingue una fila del bug de una coincidencia, fila a fila.** Contesta
 * por columna y por colegio: si lo observado dobla lo esperado hay daño, y
 * cuánto. Con `N` pequeño no contesta nada, y lo dice: `3 de 85` y `0 de 85` son
 * el mismo veredicto cuando se esperaban 3,6.
 *
 * **Y una población de CERO no es un colegio limpio**: significa que ese camino
 * no se usó ahí, que es otra cosa. Sale como `POBLACION 0` y no como `LIMPIO`.
 */

// El `use` va ARRIBA del arranque a propósito, y no debajo como en las otras
// herramientas: Pint acorta el `Kernel::class` de la línea de abajo a su nombre
// corto y deja el `use` donde estaba, y PHP registra los `use` según los compila,
// así que uno por debajo no cuenta y sale `Target class [Kernel] does not exist`.
// Medido el 2 sep 2026 sobre ocho de los doce guiones que arrancan Laravel así.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

/*
 * Las tres columnas que escribió el camino roto, y sus controles.
 *
 * Un control es una columna de LA MISMA TABLA que el camino roto nunca escribió.
 * Existe porque el veredicto no es «cuántas cumplen la firma» sino «cuántas más
 * de las que cumpliría una columna sana del mismo sitio»: si el control también
 * sale disparado, lo que falla es la medición y no el dato.
 */
const COLUMNAS = [
    // [tabla, columna, filtro o null, papel, quién la escribía]
    ['change_asked', 'deleted_at', null, 'SOSPECHOSA', 'ChangeAskedController::finalizar_si_no_hay_cambios (05 §121)'],
    ['change_asked', 'created_at', null, 'control', 'las marcas de Eloquent'],
    ['change_asked', 'accepted_at', null, 'control', 'putAceptar, que liga el Carbon directo'],
    // El filtro NO es `uploaded = 'created'`, aunque sea eso lo que pone el INSERT
    // roto: la otra rama del mismo bucle hace `$aus->uploaded = 'deleted'` sobre
    // una fila que ya existía, así que una fila escrita por el camino roto y
    // borrada después desde el lector **ya no se llama 'created'**. Con el filtro
    // estrecho esas se pierden, y son las de los colegios que más usan el lector.
    // En `simonbolivar` son 2 filas contra 7 — el filtro estrecho veía el 29%.
    ['ausencias', 'created_at', 'uploaded IS NOT NULL', 'SOSPECHOSA', 'Tardanzas/TSubirController (05 §123)'],
    ['ausencias', 'updated_at', 'uploaded IS NOT NULL', 'SOSPECHOSA', 'Tardanzas/TSubirController (05 §123)'],
    ['ausencias', 'created_at', 'uploaded IS NULL', 'control', 'el alta normal de una falta'],
    ['ausencias', 'fecha_hora', null, 'control', 'la escribe la persona — el control que desmintió el N/60'],
];

/** Lo observado tiene que doblar lo esperado... */
const UMBRAL = 2.0;

/** ...y además no poder salir por azar más de una vez entre mil. */
const ALFA = 0.001;

// ─────────────────────────────────────────────────────────────────────────────

$argumentos = array_slice($argv, 1);

if (in_array('--control', $argumentos, true)) {
    exit(controlDeLaFirma());
}

$csv = in_array('--csv', $argumentos, true);
$colegios = [];

foreach ($argumentos as $a) {
    if (str_starts_with($a, '--colegios=')) {
        $f = substr($a, 11);
        if (! is_readable($f)) {
            fwrite(STDERR, "No puedo leer la lista de colegios: {$f}\n");
            exit(2);
        }
        $colegios = array_merge($colegios, array_filter(array_map('trim', (array) file($f))));
    } elseif (! str_starts_with($a, '--')) {
        $colegios[] = $a;
    }
}

if ($colegios === []) {
    fwrite(STDERR, "Uso: php tools/hora-escrita-dos-veces.php [--csv] colegio1 colegio2 ...\n");
    fwrite(STDERR, "     php tools/hora-escrita-dos-veces.php [--csv] --colegios=lista.txt\n");
    fwrite(STDERR, "     php tools/hora-escrita-dos-veces.php --control\n");
    exit(2);
}

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fallados = [];
$conDanio = [];

if ($csv) {
    echo "colegio,tabla,columna,filtro,papel,poblacion,observadas,esperadas,razon,piso,piso_pct,veredicto\n";
}

foreach ($colegios as $colegio) {
    try {
        abrirColegio($colegio);
    } catch (Throwable $e) {
        // Un colegio que no abre NO es un colegio con ceros. Ésa es la confusión
        // que esta herramienta no puede permitirse: se cuenta aparte y su fila lo dice.
        $fallados[$colegio] = $e->getMessage();
        fwrite(STDERR, "  !! {$colegio}: NO MEDIDO — ".$e->getMessage()."\n");
        if ($csv) {
            echo csvDeLaFirma([$colegio, '', '', '', '', '', '', '', '', '', '', 'NO MEDIDO: '.$e->getMessage()]);
        }

        continue;
    }

    if (! $csv) {
        echo "\n".str_repeat('=', 78)."\n  {$colegio}\n".str_repeat('=', 78)."\n";
        printf("  %-13s %-12s %-20s %9s %6s %9s %6s %11s  %s\n",
            'tabla', 'columna', 'filtro', 'población', 'obs.', 'esperadas', 'razón', 'piso', 'veredicto');
    }

    foreach (COLUMNAS as [$tabla, $columna, $filtro, $papel, $quien]) {
        $r = medirLaFirma($colegio, $tabla, $columna, $filtro);

        if ($r['veredicto'] === 'DAÑO' && $papel === 'SOSPECHOSA') {
            $conDanio[$colegio][] = "{$tabla}.{$columna}: {$r['obs']} de {$r['n']} (se esperaban ".round($r['esp'], 1).') — '.$quien;
        }

        if ($csv) {
            echo csvDeLaFirma([$colegio, $tabla, $columna, (string) $filtro, $papel,
                (string) $r['n'], (string) $r['obs'],
                $r['esp'] === null ? '' : (string) round($r['esp'], 2),
                $r['razon'] === null ? '' : (string) round($r['razon'], 2),
                $r['piso'] === null ? '' : (string) $r['piso'],
                $r['piso'] === null || ! $r['n'] ? '' : (string) round(100 * $r['piso'] / $r['n'], 1),
                $r['veredicto']]);
        } else {
            printf("  %-13s %-12s %-20s %9s %6s %9s %6s %11s  %s%s\n",
                $tabla, $columna, $filtro ?? '—',
                $r['n'] === null ? '—' : $r['n'],
                $r['obs'] === null ? '—' : $r['obs'],
                $r['esp'] === null ? '—' : round($r['esp'], 1),
                $r['razon'] === null ? '—' : round($r['razon'], 2),
                pisoLegible($r['piso'], $r['n']),
                $r['veredicto'],
                $papel === 'SOSPECHOSA' ? '  <- la escribía el bug' : '');
        }
    }

    DB::rollBack();
}

fwrite(STDERR, "\n".str_repeat('-', 78)."\n");
fwrite(STDERR, '  colegios pedidos: '.count($colegios)."\n");
fwrite(STDERR, '  medidos:          '.(count($colegios) - count($fallados))."\n");
fwrite(STDERR, '  NO medidos:       '.count($fallados)."\n");
fwrite(STDERR, '  con daño:         '.count($conDanio)."\n");

foreach ($conDanio as $c => $lineas) {
    fwrite(STDERR, "     {$c}:\n");
    foreach ($lineas as $l) {
        fwrite(STDERR, "        {$l}\n");
    }
}

fwrite(STDERR, "\n  Recuerda: POBLACION 0 no es un colegio limpio — es un camino que ahí no\n");
fwrite(STDERR, "  se usó. Y un colegio NO MEDIDO tampoco. Si esos dos números no son 0, la\n");
fwrite(STDERR, "  decisión C todavía no tiene su respuesta completa.\n");

exit($fallados !== [] ? 2 : ($conDanio !== [] ? 1 : 0));

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Abre el colegio y deja la sesión donde no pueda escribir.
 *
 * Los nombres de las funciones de este fichero llevan apellido —`abrirColegio` y
 * no `abrir`, `medirLaFirma` y no `medir`— y **no es estilo**: los guiones de
 * `tools/` declaran funciones globales, cada uno corre solo y por eso en
 * ejecución no chocan, pero **larastan analiza la carpeta entera** y resuelve la
 * llamada contra la otra declaración. Con `medir()` a secas, las 18 líneas que
 * usan su resultado salían `Cannot access offset on int`: la que ganaba era la
 * `medir()` de `independientes-sin-estructura.php`, que devuelve un `int`. Es la
 * convención que ya siguen `controlDeLaDeriva`, `controlDeSalud` y las otras
 * cuatro; lo que faltaba era el porqué escrito en alguna parte.
 */
function abrirColegio(string $colegio): void
{
    config(['database.connections.mysql.database' => $colegio]);
    DB::purge('mysql');
    DB::reconnect('mysql');
    DB::beginTransaction();
    DB::selectOne('SELECT 1');
}

/**
 * Cuenta la firma en una columna, con su población y su modelo nulo.
 *
 * Devuelve `n` a null cuando la tabla o la columna no existen en ESE colegio:
 * las bases no tienen todas las mismas tablas —de 87 a 94, medido el 4 sep 2026—
 * y una consulta a una columna que no está es un error, no un cero.
 *
 * @return array{n:?int, obs:?int, esp:?float, razon:?float, piso:?int, veredicto:string}
 */
function medirLaFirma(string $colegio, string $tabla, string $columna, ?string $filtro): array
{
    $vacio = ['n' => null, 'obs' => null, 'esp' => null, 'razon' => null, 'piso' => null, 'veredicto' => ''];

    $existe = DB::selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.columns
          WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        [$colegio, $tabla, $columna]
    );

    if ((int) $existe->n === 0) {
        return $vacio + ['veredicto' => 'NO EXISTE AQUÍ'];
    }

    $donde = "`{$columna}` IS NOT NULL".($filtro !== null ? " AND {$filtro}" : '');

    $tot = DB::selectOne("SELECT COUNT(*) AS n,
                                 SUM(HOUR(`{$columna}`) = MINUTE(`{$columna}`)) AS obs
                            FROM `{$tabla}` WHERE {$donde}");

    $n = (int) $tot->n;
    $obs = (int) $tot->obs;

    if ($n === 0) {
        return ['n' => 0, 'obs' => 0, 'esp' => null, 'razon' => null, 'piso' => null, 'veredicto' => 'POBLACION 0'];
    }

    // El modelo nulo: coincidencia bajo independencia con las marginales reales.
    // Ver la cabecera — con `N/60` esta misma medición dio un falso 1,23×.
    $e = DB::selectOne("SELECT SUM(h.n * m.n) / {$n} AS esp FROM
            (SELECT HOUR(`{$columna}`) v, COUNT(*) n FROM `{$tabla}` WHERE {$donde} GROUP BY 1) h
            JOIN
            (SELECT MINUTE(`{$columna}`) v, COUNT(*) n FROM `{$tabla}` WHERE {$donde} GROUP BY 1) m
            ON h.v = m.v");

    $esp = (float) $e->esp;

    // El modelo empírico puede dar 0 cuando N es pequeño —simplemente porque en
    // esas pocas filas no coincidió ninguna hora con ningún minuto—, y un 0 ahí
    // no es «imposible por azar»: es «no tengo datos». Se le pone de suelo la
    // tasa a priori, N/60, que es lo que valdría si los minutos fueran uniformes.
    $espEfectiva = max($esp, $n / 60);

    $piso = pisoDeDeteccion($espEfectiva);
    $razon = $espEfectiva > 0 ? $obs / $espEfectiva : null;

    return [
        'n' => $n,
        'obs' => $obs,
        'esp' => $esp,
        'razon' => $razon,
        'piso' => $piso,
        'veredicto' => $piso > $n ? 'NO CONCLUYENTE' : ($obs >= $piso ? 'DAÑO' : 'ruido'),
    ];
}

/**
 * Cuántas filas del bug harían falta para poder llamarlo daño.
 *
 * El menor `k` que cumple las dos condiciones a la vez: que doble lo esperado y
 * que la cola de Poisson lo deje por debajo de ALFA. Existe porque una razón
 * suelta miente con población pequeña — llamó DAÑO a un control de 5 sobre 104.
 */
function pisoDeDeteccion(float $lambda): int
{
    $k = max(1, (int) ceil(UMBRAL * $lambda));

    // La cola es monótona decreciente en k, así que en cuanto baja de ALFA ya no
    // vuelve a subir. El tope es sólo un seguro contra un lambda absurdo.
    for ($i = 0; $i < 100000; $i++) {
        if (colaDePoisson($k, $lambda) <= ALFA) {
            return $k;
        }
        $k++;
    }

    return PHP_INT_MAX;
}

/**
 * P(X >= k) para X ~ Poisson(lambda).
 *
 * En logaritmos: con lambda ~ 950 y k ~ 1900, `lambda**k` desborda un double
 * mucho antes de dividirlo por `k!`, y el término real vale 1e-300.
 */
function colaDePoisson(int $k, float $lambda): float
{
    if ($k <= 0) {
        return 1.0;
    }
    if ($lambda <= 0.0) {
        return 0.0;
    }

    $logLambda = log($lambda);

    // Se suma desde k hacia arriba y se para cuando los términos ya no mueven el
    // total: pasado el máximo la cola cae más rápido que una geométrica.
    $suma = 0.0;
    $tope = (int) ceil($k + $lambda + 10 * sqrt($lambda) + 100);

    for ($i = $k; $i <= $tope; $i++) {
        $log = -$lambda + $i * $logLambda - logFactorialDe($i);
        $termino = $log < -745 ? 0.0 : exp($log);
        $suma += $termino;

        if ($i > $lambda && $termino < 1e-18 * max($suma, 1e-300)) {
            break;
        }
    }

    return min(1.0, $suma);
}

/** log(n!), con memoria: `colaDePoisson` lo pide miles de veces seguidas. */
function logFactorialDe(int $n): float
{
    static $tabla = [0 => 0.0, 1 => 0.0];

    if (isset($tabla[$n])) {
        return $tabla[$n];
    }

    $ultimo = array_key_last($tabla);
    $valor = $tabla[$ultimo];
    for ($i = $ultimo + 1; $i <= $n; $i++) {
        $valor += log($i);
        $tabla[$i] = $valor;
    }

    return $valor;
}

/**
 * El piso con su porcentaje, que es lo que hay que leer.
 *
 * `12 (14%)` dice que en esa columna un daño menor del 14% de las filas no se
 * puede ver desde aquí. Sin el porcentaje, un `12` se lee como «hay 12».
 */
function pisoLegible(?int $piso, ?int $n): string
{
    if ($piso === null || $n === null || $n === 0) {
        return '—';
    }

    return $piso > $n ? '>'.$n : $piso.' ('.round(100 * $piso / $n).'%)';
}

/** @param list<string> $campos */
function csvDeLaFirma(array $campos): string
{
    return implode(',', array_map(
        fn (string $c) => str_contains($c, ',') || str_contains($c, '"')
            ? '"'.str_replace('"', '""', $c).'"'
            : $c,
        $campos
    ))."\n";
}

/**
 * El control de la herramienta: que detecte lo que su nombre promete.
 *
 * Corre sin base y sin Laravel, porque su trabajo es decir si la FIRMA es cierta
 * y si el VEREDICTO se lee bien — dos cosas que no necesitan un colegio y que,
 * si están mal, hacen que todo lo de arriba mienta en silencio.
 *
 * Lo que NO cubre y por eso está medido a mano en la cabecera: que el motor
 * acepte la cadena de las horas de una cifra. Eso se comprobó contra MySQL 8.0.42
 * y MariaDB 10.5.29 el 5 sep 2026, y no se puede comprobar sin un motor delante.
 */
function controlDeLaFirma(): int
{
    $fallos = 0;
    echo "CONTROL — la firma, sobre las 24 horas del día\n\n";

    // 1. La firma es cierta: en `G:H:i` el campo 1 (hora) y el 2 (minuto) salen
    //    siempre iguales, sea la hora de una cifra o de dos.
    foreach (range(0, 23) as $h) {
        foreach ([[7, 33], [0, 0], [59, 59], [$h, 11]] as [$m, $s]) {
            $cadena = (new DateTimeImmutable(sprintf('2026-09-05 %02d:%02d:%02d', $h, $m, $s)))
                ->format('Y-m-d G:H:i');

            [, $hora] = explode(' ', $cadena);
            [$c1, $c2, $c3] = explode(':', $hora);

            if ((int) $c1 !== (int) $c2) {
                printf("  FALLO  %02d:%02d:%02d -> %s  (campo 1 != campo 2)\n", $h, $m, $s, $cadena);
                $fallos++;
            }
            if ((int) $c3 !== $m) {
                printf("  FALLO  %02d:%02d:%02d -> %s  (el 3er campo no es el minuto real)\n", $h, $m, $s, $cadena);
                $fallos++;
            }
        }
    }

    printf("  %d horas x 4 minutos = %d cadenas comprobadas, %d fallos\n\n", 24, 24 * 4, $fallos);

    // 2. La cola de Poisson, contra valores que se calculan a mano. Va antes que
    //    el veredicto porque el veredicto se apoya entera en ella: si esto está
    //    mal, el piso está mal y todo lo de arriba miente con buena cara.
    echo "CONTROL — la cola de Poisson, contra el papel\n\n";

    $colas = [
        // [k, lambda, valor exacto, cómo se calcula a mano]
        [1, 1.0, 1 - M_E ** -1, '1 - e^-1'],
        [2, 1.0, 1 - 2 * M_E ** -1, '1 - 2e^-1'],
        [1, 2.0, 1 - M_E ** -2, '1 - e^-2'],
        [3, 2.0, 1 - 5 * M_E ** -2, '1 - 5e^-2'],
        [1, 0.5, 1 - M_E ** -0.5, '1 - e^-0.5'],
    ];

    foreach ($colas as [$k, $lambda, $exacto, $formula]) {
        $dado = colaDePoisson($k, $lambda);
        $ok = abs($dado - $exacto) < 1e-12;
        printf("  %-6s P(X>=%d | %s) = %.10f   esperado %.10f   %s\n",
            $ok ? 'ok' : 'FALLO', $k, $lambda, $dado, $exacto, $formula);
        if (! $ok) {
            $fallos++;
        }
    }

    // 3. El veredicto, sobre casos con respuesta conocida. Los cuatro primeros
    //    son medidas reales del 5 sep 2026 y los otros son los bordes.
    echo "\nCONTROL — el veredicto, sobre casos con respuesta conocida\n\n";

    $casos = [
        // [n, observadas, lambda, veredicto esperado, de dónde sale el caso]
        [85, 0, 3.56, 'ruido', 'change_asked.deleted_at de simonbolivar'],
        [104, 5, 2.0, 'ruido', 'el control sano al que la razón sola llamó DAÑO (2,51x)'],
        [52157, 800, 831.5, 'ruido', 'ausencias.created_at de simonbolivar'],
        [45480, 931, 953.7, 'ruido', 'fecha_hora — el que N/60 leía como 1,23x'],
        [1000, 1000, 16.7, 'DAÑO', 'una columna escrita ENTERA por el bug'],
        [1000, 500, 16.7, 'DAÑO', 'media columna'],
        [10, 10, 0.17, 'DAÑO', 'diez filas y las diez con la firma'],
        // Estos dos son la frontera de verdad, y no está donde parecía. El caso
        // de tres filas se escribió esperando NO CONCLUYENTE —«tan pocas no
        // dicen nada»— y la herramienta contestó DAÑO. Tenía razón: que las tres
        // cumplan la firma pasa por azar (1/60)^3, o sea una vez entre 216.000.
        // Lo que una población chica limita es el ALCANCE de la conclusión —se
        // sabe de tres filas, no del colegio—, no su fuerza. La frontera real
        // está en 1: con una sola fila, cumplir la firma pasa 1 de cada 60.
        [2, 2, 0.033, 'DAÑO', 'dos filas y las dos: población chica, evidencia fuerte'],
        [1, 1, 0.017, 'NO CONCLUYENTE', 'una sola fila: cumplir la firma pasa 1 de cada 60'],
    ];

    foreach ($casos as [$n, $obs, $lambda, $esperado, $de]) {
        $piso = pisoDeDeteccion($lambda);
        $veredicto = $piso > $n ? 'NO CONCLUYENTE' : ($obs >= $piso ? 'DAÑO' : 'ruido');
        $ok = $veredicto === $esperado;
        printf("  %-6s %6d de %-6d  lambda %-7s piso %-11s -> %-15s %s\n",
            $ok ? 'ok' : 'FALLO', $obs, $n, $lambda, pisoLegible($piso, $n), $veredicto, $de);
        if (! $ok) {
            $fallos++;
        }
    }

    echo "\n";
    echo $fallos === 0
        ? "CONTROL OK — 96 cadenas, 5 colas y 9 veredictos, 0 fallos.\n"
        : "CONTROL EN ROJO — {$fallos} fallos. No te fíes de ninguna cifra de arriba.\n";

    return $fallos === 0 ? 0 : 1;
}
