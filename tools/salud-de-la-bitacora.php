<?php

/**
 * Qué se puede creer de `bitacoras` hoy, colegio por colegio.
 *
 * Es la **fase 0** del plan de [docs/migracion/18-auditoria.md](../docs/migracion/18-auditoria.md),
 * y existe por la misma regla que su hermana `salud-de-las-definitivas.php`:
 * *antes de arreglar algo, medirlo*. El plan decide cosas que dependen de
 * números que no están en el código —si la historia vieja se puede reinterpretar
 * o hay que darla por perdida, cuánto va a crecer la tabla nueva, y si los
 * dieciséis colegios tienen la misma hora—, y esos números sólo salen de mirar
 * las bases.
 *
 * Contesta cinco preguntas, y las cinco están en el 18:
 *
 *  1. **¿Cuánta bitácora hay, y de qué?** (§0) El vocabulario real de
 *     `affected_element_type`, con desde cuándo se escribe cada uno.
 *  2. **¿Cuántas filas están en UTC y cuántas en Bogotá?** (§1) Por dos caminos
 *     independientes, y a propósito: el del escritor y el del reloj.
 *  3. **¿Qué zona tiene el servidor de este colegio?** (§1.2) Si los dieciséis no
 *     coinciden, eso ya es un incidente por sí solo.
 *  4. **¿Cuántas atribuciones a un ingreso son de fiar?** (§2) El `historial_id`
 *     se adivina con un `order by id desc limit 1`; esto mide cuántas filas caen
 *     en una ventana donde la adivinanza pudo fallar.
 *  5. **¿Cuánto crecería la tabla nueva?** Ingresos por día y acciones por
 *     ingreso, para dimensionar `auditoria` antes de crearla.
 *
 * Uso (dentro del contenedor):
 *
 *     docker exec 8myvc-app-1 php tools/salud-de-la-bitacora.php
 *     docker exec 8myvc-app-1 php tools/salud-de-la-bitacora.php --detalle
 *     docker exec 8myvc-app-1 php tools/salud-de-la-bitacora.php --csv
 *     docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/salud-de-la-bitacora.php
 *
 * `--csv` imprime **una línea por base**, para que los dieciséis se puedan juntar
 * en una tabla en vez de leerse de uno en uno. Es lo que hace falta de verdad:
 * la decisión que espera este número —reinterpretar la historia o no— se toma
 * **con los dieciséis delante**, igual que la fase 0 del [10](../docs/migracion/10-definitivas.md).
 *
 *     for c in colegio1 colegio2 ...; do
 *         DB_DATABASE=$c php tools/salud-de-la-bitacora.php --csv | tail -1
 *     done
 *
 * **No escribe nada.** Solo SELECT, por la misma razón que su hermana: la
 * corrección de datos, si la hay, va en una migración con su rastro, no en una
 * herramienta que alguien pueda lanzar dos veces.
 *
 * ---
 *
 * ## Los dos caminos para contar las horas, y por qué son dos
 *
 * **El del escritor** (bloque 3) es exacto pero **sólo desde que la migración
 * está desplegada**: `ExigirPersonaPropia` y `ExigirBoletinPropio` escriben con
 * `now()`, o sea UTC, y son los únicos que escriben sus tipos. Cualquier fila con
 * uno de esos tipos está en UTC **por construcción**, no por estimación. El
 * problema es que el código original también insertaba esas filas —se
 * conservaron a propósito, lo dice `ExigirBoletinPropio`— y de aquéllas no se
 * sabe la zona. Por eso el bloque 2 imprime **el primer y el último `created_at`
 * de cada tipo**: el corte se ve en los datos, sin que nadie tenga que recordar
 * la fecha de despliegue de cada colegio.
 *
 * **El del reloj** (bloque 4) no depende del tipo y por eso alcanza a las filas
 * viejas. `historiales.created_at` lo escribe `Services/Login.php` con
 * `Carbon::now('America/Bogota')` —comprobado, es el único sitio que lo
 * escribe—, así que es **el reloj de referencia**. Una bitácora escrita en UTC
 * durante esa sesión aparece unas cinco horas por delante de su propio ingreso.
 * Y aparecen dos cosas que son imposibles y por eso valen más que un promedio:
 * una fila escrita **antes** de que empezara su sesión, y una escrita días
 * después del último ingreso de su usuario.
 *
 * **Si los dos caminos no coinciden, el que está mal es el detector, no la
 * base.** Es la regla de CLAUDE.md, y aquí se puede aplicar porque hay dos.
 *
 * ## La cola nocturna (bloque 5), que es la que funciona sin nada más
 *
 * Un colegio trabaja de día. Si el 30% de los cambios de nota caen entre las
 * 20:00 y las 02:00, no es que los profesores sean nocturnos: son filas en UTC
 * leídas como si fueran locales. Es una señal **de población, no de fila**, así
 * que no clasifica nada — pero es la única que sigue funcionando cuando el tipo
 * no dice nada y el `historial_id` es NULL, que es el caso de las 52 filas de
 * `intento_login` del seed.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opciones = getopt('', ['detalle', 'csv', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 4200), PHP_EOL;
    exit(0);
}

$detalle = isset($opciones['detalle']);
$csv = isset($opciones['csv']);

/**
 * Los tipos que escribe un sitio con el reloj en UTC, y cuál es ese sitio.
 *
 * No es una lista de sospechosos: es el inventario de los tres relojes en UTC
 * que escriben en `bitacoras`, sacado de leer los diez INSERT del proyecto. Si
 * mañana aparece un tipo nuevo aquí abajo sin estar en esta lista, el bloque 3
 * lo cuenta como «reloj desconocido» y lo dice — callarlo sería contar de menos
 * justo en la dirección que tranquiliza.
 */
const ESCRITOS_EN_UTC = [
    'AlumnoPideAjeno:user_id' => 'Middleware/ExigirPersonaPropia:304 — now()',
    'AlumnoPideAjeno:alumno_id' => 'Middleware/ExigirPersonaPropia:304 — now()',
    'AlumnoPideAjeno:persona_id' => 'Middleware/ExigirPersonaPropia:304 — now()',
    'AcudientePideAjeno:user_id' => 'Middleware/ExigirPersonaPropia:304 — now()',
    'AcudientePideAjeno:alumno_id' => 'Middleware/ExigirPersonaPropia:304 — now()',
    'AcudientePideAjeno:persona_id' => 'Middleware/ExigirPersonaPropia:304 — now()',
    'AlumnoVerBoletin' => 'Middleware/ExigirBoletinPropio:173 — now()',
    'refresco_reutilizado' => 'Services/Sesion.php:477 — Carbon::now()',
];

/**
 * Los tipos que escribe un sitio con el reloj en Bogotá.
 *
 * Los otros siete INSERT del proyecto, repartidos en seis ficheros. Se enumeran en vez de decir «todo lo que
 * no esté arriba» para que un tipo nuevo no se cuele en el saco bueno sin que
 * nadie lo mire.
 */
const ESCRITOS_EN_BOGOTA = [
    'Nota' => 'NotasController::putUpdate y ::putLote',
    'NF_UPDATE' => 'DefinitivasPeriodosController:226',
    'RF_UPDATE' => 'DefinitivasPeriodosController:329',
    'Nueva subunidad' => 'SubunidadesController:68',
    'YEAR CONFIGURACION' => 'YearsController:359',
    'intento_login' => 'Services/Login.php:126',
];

/**
 * Reparte las filas entre los dos relojes, y deja aparte lo que no conoce.
 *
 * **Está fuera del flujo para poder ejercerla sin base**, porque es la decisión de
 * esta herramienta que puede equivocarse en silencio: de este reparto sale si se
 * puede reinterpretar la historia vieja de `bitacoras`, y un tipo nuevo metido en
 * el saco bueno **no rompe nada** — sólo mueve el número en la dirección que
 * tranquiliza.
 *
 * El `else` no reparte a ojo: **lo que no está en ninguna lista se cuenta aparte**
 * y se dice. Ésa es la mitad que hace utilizable el número, y la que un `?:` con
 * un valor por defecto se habría comido.
 *
 * @param  list<object|array{tipo: string, filas: int|string}>  $tipos
 * @return array{utc: int, bogota: int, sin_clasificar: int}
 */
function repartirPorReloj(array $tipos): array
{
    $r = ['utc' => 0, 'bogota' => 0, 'sin_clasificar' => 0];

    foreach ($tipos as $t) {
        $t = (array) $t;
        $filas = (int) $t['filas'];

        if (isset(ESCRITOS_EN_UTC[$t['tipo']])) {
            $r['utc'] += $filas;
        } elseif (isset(ESCRITOS_EN_BOGOTA[$t['tipo']])) {
            $r['bogota'] += $filas;
        } else {
            $r['sin_clasificar'] += $filas;
        }
    }

    return $r;
}

/**
 * El control positivo, **sin base y sin árbol**.
 *
 * Lo corre `tests/Unit/AutopruebasDeLasHerramientasTest`. Ancla el reparto y —lo
 * que ninguna corrida enseña— **que las dos listas no se solapen**: un tipo en las
 * dos se contaría en UTC por el orden del `if`, en silencio y con las dos listas
 * pareciendo correctas por separado.
 *
 * Lo que este control NO promete, y hay que decirlo: **fija la conducta conocida,
 * no descubre cegueras nuevas.** Si mañana un INSERT nuevo escribe un tipo que ya
 * está en una lista pero con el otro reloj, esto sigue verde — eso lo caza
 * `CentinelaDeLosEscritoresDeBitacoraTest`, que cuenta los escritores, y sólo si
 * el fichero es nuevo. Las dos piezas juntas no cubren el caso de un fichero que
 * ya escribe y le cambian el reloj a una línea.
 */
function controlDeLaBitacora(): int
{
    $fallos = [];

    // 1. El reparto, con un tipo de cada clase y uno que no conoce nadie.
    $casos = [
        ['un tipo de la lista de UTC cuenta en UTC',
            [['tipo' => 'AlumnoVerBoletin', 'filas' => 7]], ['utc' => 7, 'bogota' => 0, 'sin_clasificar' => 0]],
        ['un tipo de la lista de Bogotá cuenta en Bogotá',
            [['tipo' => 'intento_login', 'filas' => 5]], ['utc' => 0, 'bogota' => 5, 'sin_clasificar' => 0]],
        // El que importa: un escritor nuevo NO puede caer en ninguno de los dos
        // sacos buenos. Callarlo sería contar de menos en la dirección que
        // tranquiliza, que es la frase que la propia cabecera de la lista usa.
        ['un tipo que NO conoce ninguna lista se cuenta APARTE, no en el saco bueno',
            [['tipo' => 'TipoQueNadieHaVistoNunca', 'filas' => 3]], ['utc' => 0, 'bogota' => 0, 'sin_clasificar' => 3]],
        ['los tres a la vez, y ninguna fila se pierde por el camino',
            [['tipo' => 'AlumnoVerBoletin', 'filas' => 7],
                ['tipo' => 'intento_login', 'filas' => 5],
                ['tipo' => 'TipoQueNadieHaVistoNunca', 'filas' => 3]],
            ['utc' => 7, 'bogota' => 5, 'sin_clasificar' => 3]],
    ];

    foreach ($casos as [$que, $entrada, $esperado]) {
        $salio = repartirPorReloj($entrada);
        $ok = $salio === $esperado;
        echo '  '.($ok ? 'ok  ' : 'FALLA')."  {$que}\n";
        if (! $ok) {
            $fallos[] = '    esperaba '.json_encode($esperado).' y salió '.json_encode($salio);
        }

        // La invariante que ninguna corrida enseña: el reparto no pierde filas.
        $suma = array_sum($salio);
        $total = array_sum(array_map(static fn ($f) => (int) $f['filas'], $entrada));
        if ($suma !== $total) {
            $fallos[] = "    el reparto pierde filas: entraron {$total} y se repartieron {$suma}";
            echo "  FALLA  ^ y además pierde filas: {$total} -> {$suma}\n";
        }
    }

    // 2. Que las dos listas no se solapen. Un tipo en las dos se contaría en UTC
    //    por el orden del `if`, y las dos listas parecerían correctas por separado.
    $solape = array_values(array_intersect(array_keys(ESCRITOS_EN_UTC), array_keys(ESCRITOS_EN_BOGOTA)));
    $ok = count($solape) === 0;
    echo '  '.($ok ? 'ok  ' : 'FALLA')."  las dos listas de relojes no se solapan\n";
    if (! $ok) {
        $fallos[] = '    en las dos listas a la vez: '.implode(', ', $solape)
            .' — se cuentan en UTC por el orden del `if`, y en silencio.';
    }

    // 3. Y que ninguna esté vacía: una lista vacía repartiría todo a
    //    «sin clasificar» sin que nada fallara, y el bloque 3 diría 0 y 0.
    //
    //    Va por una función con el parámetro `array` a secas, y no comparando la
    //    constante en línea, **por el análisis**: de un literal larastan deduce el
    //    array exacto y da la comparación por siempre-cierta —tres errores de
    //    `staticMethod.alreadyNarrowedType`—. Tiene razón hoy, pero eso convierte
    //    «hoy no está vacía» en «no puede estarlo», que es justo lo que este caso
    //    existe para no dar por hecho. Es el mismo apaño, y por el mismo motivo,
    //    que el `noConcluyentes()` de `AutopruebasDeLasHerramientasTest`.
    $tieneEntradas = static fn (array $lista): bool => count($lista) > 0;

    foreach (['ESCRITOS_EN_UTC' => ESCRITOS_EN_UTC, 'ESCRITOS_EN_BOGOTA' => ESCRITOS_EN_BOGOTA] as $n => $l) {
        $ok = $tieneEntradas($l);
        echo '  '.($ok ? 'ok  ' : 'FALLA')."  {$n} no está vacía (".count($l)." entradas)\n";
        if (! $ok) {
            $fallos[] = "    {$n} está vacía: el bloque 3 diría 0 en UTC y 0 en Bogotá sin fallar.";
        }
    }

    echo 'Población del control: '.(count($casos) + 3)." formas comprobadas, ".count($fallos)." fallan.\n";

    if ($fallos !== []) {
        echo implode("\n", $fallos)."\n";
        echo "CONTROL FALLA: de este reparto sale si se puede reinterpretar la historia\n"
            ."vieja de `bitacoras`. Su forma de mentir es contar de MENOS en «sin\n"
            ."clasificar», que es la dirección que tranquiliza.\n";

        return 1;
    }

    echo "OK — las siete formas se clasifican como está decidido.\n";

    return 0;
}

// El despacho va AQUÍ y no arriba del todo por una razón medida: el control usa
// las dos constantes de relojes, que se declaran más abajo del bootstrap. Puesto
// justo antes de esta línea, Laravel está arrancado pero **la base todavía no se
// ha tocado** —la conexión es perezosa y `getDatabaseName()` es lo primero que la
// abre—, así que el control sigue sin depender de que haya base ninguna.
if (in_array('--control', $argv ?? [], true)) {
    exit(controlDeLaBitacora());
}

$base = DB::connection()->getDatabaseName();

/**
 * Imprime un bloque con su título, su número y —si se pidió— sus primeras filas.
 *
 * El sufijo del nombre es el mismo apaño que en `salud-de-las-definitivas.php`:
 * larastan analiza los `tools/` a la vez y una `bloque()` global ya está cogida.
 *
 * @param  list<array<string, scalar|null>|object>  $ejemplos
 */
function bloqueDeLaBitacora(string $titulo, string $cifra, string $nota = '', array $ejemplos = []): void
{
    global $detalle, $csv;

    if ($csv) {
        return;
    }

    echo '  '.$titulo.PHP_EOL;
    echo '      '.$cifra.PHP_EOL;

    if ($nota !== '') {
        foreach (explode("\n", wordwrap($nota, 68)) as $linea) {
            echo '      '.$linea.PHP_EOL;
        }
    }

    if ($detalle && $ejemplos !== []) {
        echo PHP_EOL;
        foreach (array_slice($ejemplos, 0, 10) as $fila) {
            echo '        '.json_encode($fila, JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
    }

    echo PHP_EOL;
}

function titulo(string $texto): void
{
    global $csv;

    if ($csv) {
        return;
    }

    echo PHP_EOL.$texto.PHP_EOL.str_repeat('=', 78).PHP_EOL.PHP_EOL;
}

titulo("Salud de `bitacoras` — base `{$base}`");

// ---------------------------------------------------------------------------
// 1. La población. Va primera y no es decorado: sin ella, todos los ceros de
//    abajo son ambiguos. Un «0 filas en UTC» no distingue «las revisé y ninguna
//    lo estaba» de «no había filas que revisar», y de las dos lecturas la falsa
//    es la que hace archivar el asunto (CLAUDE.md).
// ---------------------------------------------------------------------------
$pob = DB::selectOne(
    'SELECT COUNT(*) AS filas,
            COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END), 0) AS vivas,
            MIN(created_at) AS desde,
            MAX(created_at) AS hasta,
            COUNT(DISTINCT created_by) AS actores,
            COALESCE(SUM(CASE WHEN created_at IS NULL THEN 1 ELSE 0 END), 0) AS sin_fecha
       FROM bitacoras'
);

$ingresos = DB::selectOne(
    'SELECT COUNT(*) AS filas,
            MIN(created_at) AS desde,
            MAX(created_at) AS hasta,
            COUNT(DISTINCT user_id) AS usuarios
       FROM historiales
      WHERE deleted_at IS NULL'
);

$totalBitacoras = (int) $pob->filas;
$totalIngresos = (int) $ingresos->filas;

bloqueDeLaBitacora(
    '1. La población',
    "{$totalBitacoras} filas en `bitacoras` ({$pob->vivas} sin borrar), de {$pob->actores} usuarios distintos · ".
    ($pob->desde !== null ? "de {$pob->desde} a {$pob->hasta}" : 'sin ninguna fecha'),
    "Y {$totalIngresos} ingresos en `historiales` de {$ingresos->usuarios} usuarios".
    ($ingresos->desde !== null ? ", de {$ingresos->desde} a {$ingresos->hasta}" : '').
    '. Esta es la cifra contra la que se leen todas las de abajo: un cero en un '.
    'bloque cualquiera significa cosas muy distintas según esta línea.'.
    ((int) $pob->sin_fecha > 0
        ? " OJO: {$pob->sin_fecha} filas tienen `created_at` NULL y no entran en ningún reparto por hora."
        : '')
);

// ---------------------------------------------------------------------------
// 2. El vocabulario real de `affected_element_type`, con su ventana.
//
//    Las fechas de cada tipo son lo que hace este bloque útil y no un simple
//    recuento: son las que dicen **desde cuándo** escribe cada sitio, y por tanto
//    a partir de qué fila se puede confiar en la clasificación del bloque 3 sin
//    tener que acordarse de la fecha de despliegue de este colegio.
// ---------------------------------------------------------------------------
$tipos = DB::select(
    'SELECT COALESCE(affected_element_type, "(NULL)") AS tipo,
            COUNT(*) AS filas,
            MIN(created_at) AS desde,
            MAX(created_at) AS hasta
       FROM bitacoras
      GROUP BY tipo
      ORDER BY filas DESC'
);

$lineasTipo = [];
$desconocidos = [];

foreach ($tipos as $t) {
    $conocido = isset(ESCRITOS_EN_UTC[$t->tipo]) || isset(ESCRITOS_EN_BOGOTA[$t->tipo]);
    $reloj = isset(ESCRITOS_EN_UTC[$t->tipo]) ? 'UTC' : (isset(ESCRITOS_EN_BOGOTA[$t->tipo]) ? 'Bogotá' : '¿?');

    if (! $conocido) {
        $desconocidos[] = $t->tipo;
    }

    $lineasTipo[] = sprintf(
        '%-32s %7d  %-7s %s → %s',
        substr((string) $t->tipo, 0, 32),
        (int) $t->filas,
        $reloj,
        substr((string) ($t->desde ?? '—'), 0, 10),
        substr((string) ($t->hasta ?? '—'), 0, 10)
    );
}

bloqueDeLaBitacora(
    '2. El vocabulario de `affected_element_type`, y desde cuándo escribe cada uno',
    count($tipos).' tipos distintos en '.$totalBitacoras.' filas',
    'La columna del reloj sale de leer los diez INSERT del proyecto, no de los '.
    'datos. Las fechas sí salen de los datos, y son las que dicen a partir de '.
    'cuándo la clasificación del bloque 3 es cierta: antes del despliegue de la '.
    'migración esos mismos tipos los escribía el código original, y de aquél no '.
    'se sabe la zona.'.
    ($desconocidos !== []
        ? ' >>> '.count($desconocidos).' tipo(s) sin reloj conocido: '.implode(', ', array_slice($desconocidos, 0, 8)).'. '.
          'Hay un escritor que esta herramienta no conoce — actualízala antes de creerte el bloque 3.'
        : '')
);

if (! $csv) {
    foreach ($lineasTipo as $linea) {
        echo '        '.$linea.PHP_EOL;
    }
    echo PHP_EOL;
}

// ---------------------------------------------------------------------------
// 3. Camino uno: clasificar por quién escribió.
// ---------------------------------------------------------------------------
$reparto = repartirPorReloj($tipos);
$enUtc = $reparto['utc'];
$enBogota = $reparto['bogota'];
$sinClasificar = $reparto['sin_clasificar'];

bloqueDeLaBitacora(
    '3. Los dos relojes, contados por el escritor',
    "{$enUtc} filas en UTC · {$enBogota} en Bogotá · {$sinClasificar} sin clasificar".
    ' — sobre '.$totalBitacoras.' revisadas',
    'Cinco horas de diferencia dentro de la misma columna, sin nada en la fila '.
    'que diga cuál es cuál. Es exacto para lo escrito después del despliegue de '.
    'la migración (ver las fechas del bloque 2) y una suposición para lo '.
    'anterior. El bloque 4 lo comprueba por otro camino: si los dos no '.
    'coinciden, el que está mal es el detector.'
);

// ---------------------------------------------------------------------------
// 4. Camino dos: medir contra `historiales`, que es el reloj de referencia.
//
//    `Services/Login.php:45` escribe `historiales.created_at` con
//    `Carbon::now('America/Bogota')` y es el único sitio que lo escribe. Así que
//    una bitácora de esa sesión escrita en UTC aparece unas cinco horas por
//    delante de su propio ingreso.
//
//    Los dos extremos valen más que la media, porque son **imposibles**: una fila
//    no puede escribirse antes de que empiece su sesión.
// ---------------------------------------------------------------------------
$desfase = DB::selectOne(
    'SELECT COUNT(*) AS emparejadas,
            COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, h.created_at, b.created_at) <  -60 THEN 1 ELSE 0 END), 0) AS imposibles,
            COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, h.created_at, b.created_at) BETWEEN  -60 AND  240 THEN 1 ELSE 0 END), 0) AS a_la_par,
            COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, h.created_at, b.created_at) BETWEEN  241 AND  360 THEN 1 ELSE 0 END), 0) AS cinco_horas,
            COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, h.created_at, b.created_at) >  360 THEN 1 ELSE 0 END), 0) AS mucho_despues
       FROM bitacoras b
       INNER JOIN historiales h ON h.id = b.historial_id
      WHERE b.created_at IS NOT NULL AND h.created_at IS NOT NULL'
);

$emparejadas = (int) $desfase->emparejadas;

bloqueDeLaBitacora(
    '4. El desfase contra el ingreso, que es el reloj de referencia',
    $emparejadas === 0
        ? '0 filas emparejables con su ingreso — este camino no puede decir nada en esta base'
        : "{$emparejadas} filas emparejadas con su ingreso · ".
          "imposibles (antes de su sesión): {$desfase->imposibles} · ".
          "a la par (−1h a +4h): {$desfase->a_la_par} · ".
          "en la banda de las 5 horas: {$desfase->cinco_horas} · ".
          "más de 6h después: {$desfase->mucho_despues}",
    $emparejadas === 0
        ? 'Ojo: no significa que no haya desfase. Significa que ninguna fila tiene '.
          'un `historial_id` que apunte a un ingreso vivo, y eso es el bloque 7.'
        : 'La banda de las cinco horas es la firma del `now()` en UTC. Las '.
          '«imposibles» son las que más valen: una bitácora no puede escribirse '.
          'antes de que empiece la sesión que la escribe, así que sólo el reloj '.
          'las explica. Y «más de 6h después» no es necesariamente un error — el '.
          'token de refresco vive '.((int) config('sesion.refresco_ttl') / 1440).
          ' días—, pero sí es la zona donde el desfase y una sesión larga se '.
          'confunden, y por eso va en su propio cubo en vez de sumarse a otro.'
);

// ---------------------------------------------------------------------------
// 5. La cola nocturna. La única que sigue funcionando cuando el tipo no dice
//    nada y el `historial_id` es NULL.
// ---------------------------------------------------------------------------
$porHora = DB::select(
    'SELECT HOUR(created_at) AS hora, COUNT(*) AS filas
       FROM bitacoras
      WHERE created_at IS NOT NULL
      GROUP BY hora
      ORDER BY hora'
);

$conHora = array_fill(0, 24, 0);
foreach ($porHora as $h) {
    $conHora[(int) $h->hora] = (int) $h->filas;
}

$totalConHora = array_sum($conHora);
// 19:00–04:59 leído como hora local. Un colegio no califica de madrugada; una
// fila escrita en UTC a las 14:00 de Bogotá aterriza aquí.
$nocturnas = 0;
foreach ([19, 20, 21, 22, 23, 0, 1, 2, 3, 4] as $hora) {
    $nocturnas += $conHora[$hora];
}

$pctNocturno = $totalConHora > 0 ? round($nocturnas * 100 / $totalConHora, 1) : 0.0;

bloqueDeLaBitacora(
    '5. La cola nocturna — reparto por hora del día',
    "{$nocturnas} de {$totalConHora} filas caen entre las 19:00 y las 04:59 ({$pctNocturno}%)",
    'Es una señal de población, no de fila: no clasifica nada y no hay que '.
    'usarla para decidir sobre una fila concreta. Sirve porque alcanza donde no '.
    'llegan los otros dos caminos —el tipo desconocido y el `historial_id` '.
    'NULL—. Un colegio trabaja de día; un porcentaje nocturno alto son filas en '.
    'UTC leídas como locales, no profesores desvelados.'
);

if (! $csv && $totalConHora > 0) {
    $pico = max($conHora);
    for ($h = 0; $h < 24; $h++) {
        $ancho = $pico > 0 ? (int) round($conHora[$h] * 40 / $pico) : 0;
        $marca = in_array($h, [19, 20, 21, 22, 23, 0, 1, 2, 3, 4], true) ? '·' : ' ';
        printf("        %02d:00 %s %-40s %d\n", $h, $marca, str_repeat('#', $ancho), $conHora[$h]);
    }
    echo PHP_EOL;
}

// ---------------------------------------------------------------------------
// 6. La zona del servidor. Es el dato que hay que comparar entre los dieciséis.
//
//    Las columnas son `TIMESTAMP`, o sea que MySQL convierte al escribir y al
//    leer con la zona de la sesión. `config/database.php` no la fija, así que
//    hereda la del servidor — y son dieciséis cuentas de cPanel distintas.
// ---------------------------------------------------------------------------
$zona = DB::selectOne('SELECT @@system_time_zone AS sistema, @@session.time_zone AS sesion, NOW() AS ahora_mysql');
$ahoraPhp = date('Y-m-d H:i:s');
$ahoraBogota = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
$saltoMysqlPhp = (int) round((strtotime((string) $zona->ahora_mysql) - strtotime($ahoraPhp)) / 60);

bloqueDeLaBitacora(
    '6. La zona del servidor de este colegio',
    "@@system_time_zone = {$zona->sistema} · @@session.time_zone = {$zona->sesion}",
    "MySQL dice que son las {$zona->ahora_mysql}; PHP (config/app.php = ".
    config('app.timezone').") dice {$ahoraPhp}; en Bogotá son {$ahoraBogota}. ".
    'Diferencia MySQL−PHP: '.$saltoMysqlPhp.' minutos. '.
    ($zona->sesion === 'SYSTEM'
        ? 'La sesión hereda la zona del servidor: si la cuenta de cPanel cambia '.
          'la suya, TODAS las filas históricas de las columnas TIMESTAMP se '.
          'desplazan a la vez, sin que nadie toque la base. Esto es lo que hay '.
          'que comparar entre los dieciséis: si no coinciden, ya es un incidente.'
        : 'La zona de sesión está fijada explícitamente, que es lo deseable.')
);

// ---------------------------------------------------------------------------
// 7. La atribución al ingreso: cuántas se pueden creer.
//
//    Los controladores resuelven `historial_id` con
//    `... order by id desc limit 1`, o sea **el último login del usuario, no la
//    sesión que hizo el cambio**. No se puede saber fila a fila cuál está mal, y
//    esta herramienta no lo finge: cuenta cuántas caen en una ventana donde la
//    adivinanza pudo fallar, que es una lista de sitios donde mirar y no una
//    lista de fallos (CLAUDE.md).
// ---------------------------------------------------------------------------
$sinHistorial = (int) DB::selectOne('SELECT COUNT(*) AS n FROM bitacoras WHERE historial_id IS NULL')->n;
$colgando = (int) DB::selectOne(
    'SELECT COUNT(*) AS n
       FROM bitacoras b
       LEFT JOIN historiales h ON h.id = b.historial_id
      WHERE b.historial_id IS NOT NULL AND h.id IS NULL'
)->n;

// La ventana es la vida del token de refresco, que es lo que de verdad puede
// tener dos sesiones del mismo usuario vivas a la vez. Sale de config/sesion.php
// y no de un número escrito aquí: si el acuerdo cambia, esto cambia con él.
$ventanaMin = (int) config('sesion.refresco_ttl');

$dudosas = (int) DB::selectOne(
    'SELECT COUNT(*) AS n
       FROM bitacoras b
       INNER JOIN historiales h ON h.id = b.historial_id
      WHERE EXISTS (
                SELECT 1 FROM historiales h2
                 WHERE h2.user_id = h.user_id
                   AND h2.id <> h.id
                   AND h2.deleted_at IS NULL
                   AND h2.created_at BETWEEN DATE_SUB(h.created_at, INTERVAL ? MINUTE) AND h.created_at
            )',
    [$ventanaMin]
)->n;

$fiables = $emparejadas - $dudosas;
$pctDudoso = $emparejadas > 0 ? round($dudosas * 100 / $emparejadas, 1) : 0.0;

bloqueDeLaBitacora(
    '7. La atribución al ingreso: cuántas se pueden creer',
    "{$sinHistorial} sin `historial_id` · {$colgando} apuntando a un ingreso que ya no existe · ".
    "{$dudosas} de {$emparejadas} emparejadas caen donde la adivinanza pudo fallar ({$pctDudoso}%) · ".
    "{$fiables} sin otra sesión cerca",
    'La ventana son los '.round($ventanaMin / 1440, 1).' días que vive el token de '.
    'refresco (config/sesion.php), no un número inventado: es el tiempo real en '.
    'que un usuario puede tener el móvil y el navegador a la vez. Las «dudosas» '.
    'NO están mal necesariamente — están sin comprobar, que para una auditoría '.
    'es lo mismo. Este porcentaje es el argumento de la fase 2 del 18: mientras '.
    'el token y el ingreso no se conozcan, la pantalla de «qué hizo en este '.
    'ingreso» muestra una lista que nadie puede defender.'
);

// ---------------------------------------------------------------------------
// 8. El titular: cuántos ingresos tienen algo que enseñar.
//
//    Es la pregunta que trajo todo esto. Un colegio abre un ingreso cualquiera y
//    quiere ver qué se hizo; esto dice en qué fracción de los casos la respuesta
//    honesta es «no se sabe».
// ---------------------------------------------------------------------------
$conAlgo = (int) DB::selectOne(
    'SELECT COUNT(DISTINCT b.historial_id) AS n
       FROM bitacoras b
       INNER JOIN historiales h ON h.id = b.historial_id AND h.deleted_at IS NULL
      WHERE b.deleted_at IS NULL'
)->n;

$pctVacio = $totalIngresos > 0 ? round(($totalIngresos - $conAlgo) * 100 / $totalIngresos, 1) : 0.0;

bloqueDeLaBitacora(
    '8. Cuántos ingresos tienen algo que enseñar',
    "{$conAlgo} de {$totalIngresos} ingresos tienen alguna línea de bitácora · ".
    'los otros '.($totalIngresos - $conAlgo)." salen vacíos ({$pctVacio}%)",
    'Éste es el número que hay que enseñar cuando alguien pregunte por qué se '.
    'reescribe la bitácora. No mide un fallo: mide que sólo diez sitios del '.
    'código escriben, contra 256 escrituras de datos. La pantalla que se pidió '.
    'no está mal construida — es que hoy no hay filas que enseñarle.'
);

// ---------------------------------------------------------------------------
// 9. Cuánto crecería `auditoria`. Sale de los ingresos, no de las bitácoras:
//    la tabla nueva graba lo que las diez de hoy NO graban, así que el volumen
//    de hoy no sirve para dimensionarla y el de ingresos sí es el suelo.
// ---------------------------------------------------------------------------
$ritmo = DB::selectOne(
    'SELECT COUNT(*) AS ingresos,
            COUNT(DISTINCT DATE(created_at)) AS dias,
            COUNT(DISTINCT user_id) AS usuarios
       FROM historiales
      WHERE deleted_at IS NULL AND created_at IS NOT NULL
        AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)'
);

$dias = max(1, (int) $ritmo->dias);
$porDia = round((int) $ritmo->ingresos / $dias, 1);

bloqueDeLaBitacora(
    '9. El ritmo, para dimensionar la tabla nueva',
    "{$ritmo->ingresos} ingresos en {$dias} días con actividad ({$porDia}/día) de {$ritmo->usuarios} usuarios, último año",
    ($dias < 30
        ? '>>> OJO: sólo '.$dias.' días con actividad en el último año. Esta media '.
          'no es un ritmo, es una ráfaga — en una base de tests o en un colegio '.
          'recién migrado el número de arriba no dimensiona nada. Vale cuando los '.
          'días con actividad se parecen al calendario escolar. '
        : '').
    'La tabla nueva se dimensiona con esto y no con el volumen de `bitacoras`: '.
    'lo que va a grabar es justo lo que hoy NO se graba. Como referencia, si '.
    'cada ingreso deja del orden de diez acciones, son unas '.
    round($porDia * 10).' filas al día y ~'.round($porDia * 10 * 365 / 1000).
    'k al año en este colegio — el orden de magnitud que decide si la retención '.
    'de la fase 6 hace falta el primer año o el tercero.'
);

// ---------------------------------------------------------------------------
// 10. Lo borrado. `DELETE bitacoras/destroy/{id}` va con `auth.personal`:
//     cualquiera del personal puede borrar el registro que lo vigila. Esto mide
//     si ha pasado.
// ---------------------------------------------------------------------------
$borradas = DB::selectOne(
    'SELECT COUNT(*) AS n,
            COUNT(DISTINCT deleted_by) AS quienes,
            COALESCE(SUM(CASE WHEN deleted_by IS NULL THEN 1 ELSE 0 END), 0) AS sin_autor
       FROM bitacoras
      WHERE deleted_at IS NOT NULL'
);

bloqueDeLaBitacora(
    '10. Líneas de auditoría borradas',
    "{$borradas->n} de {$totalBitacoras} · por {$borradas->quienes} usuario(s) distinto(s) · ".
    "{$borradas->sin_autor} sin `deleted_by`",
    'Las que no tienen `deleted_by` son anteriores al arreglo de la 05 §88: se '.
    'borraron sin dejar quién. La tabla nueva no tiene `deleted_at` (18 §4.4) y '.
    'la ruta de borrar no tiene equivalente, así que este bloque sólo puede '.
    'crecer hasta que la fase 6 desenrute `bitacoras/destroy`.'
);

// ---------------------------------------------------------------------------
// Salida CSV: una línea por base, para juntar los dieciséis.
// ---------------------------------------------------------------------------
if ($csv) {
    $campos = [
        'base' => $base,
        'bitacoras' => $totalBitacoras,
        'ingresos' => $totalIngresos,
        'ingresos_con_algo' => $conAlgo,
        'pct_ingresos_vacios' => $pctVacio,
        'reloj_utc' => $enUtc,
        'reloj_bogota' => $enBogota,
        'reloj_desconocido' => $sinClasificar,
        'tipos_sin_reloj' => count($desconocidos),
        'desfase_imposibles' => (int) $desfase->imposibles,
        'desfase_banda_5h' => (int) $desfase->cinco_horas,
        'pct_nocturno' => $pctNocturno,
        'system_time_zone' => (string) $zona->sistema,
        'session_time_zone' => (string) $zona->sesion,
        'salto_mysql_php_min' => $saltoMysqlPhp,
        'sin_historial' => $sinHistorial,
        'historial_colgando' => $colgando,
        'atribucion_dudosa' => $dudosas,
        'pct_atribucion_dudosa' => $pctDudoso,
        'ingresos_por_dia' => $porDia,
        'borradas' => (int) $borradas->n,
    ];

    echo implode(',', array_keys($campos)).PHP_EOL;
    echo implode(',', array_map(static fn ($v): string => (string) $v, $campos)).PHP_EOL;
    exit(0);
}

// El cruce lo hace la herramienta, no el lector. Dejar dos números en dos
// bloques y confiar en que alguien los compare es la forma de que nadie los
// compare: la §142 de la noche del 23 salió de nueve sitios ciertos leídos mal.
$banda = (int) $desfase->cinco_horas;
$brecha = abs($enUtc - $banda);

echo str_repeat('=', 78).PHP_EOL;
echo 'EL CRUCE — los bloques 3 y 4 no comparten ningún supuesto:'.PHP_EOL;
echo "  por el escritor .......... {$enUtc} filas en UTC".PHP_EOL;
echo "  por el reloj ............. {$banda} filas en la banda de las 5 horas".PHP_EOL;

if ($emparejadas === 0) {
    echo '  >>> sin filas emparejables, el camino del reloj no opina. El del'.PHP_EOL;
    echo '      escritor va solo, y solo no basta para las filas anteriores'.PHP_EOL;
    echo '      al despliegue de la migración.'.PHP_EOL;
} elseif ($brecha === 0) {
    echo '  coinciden. Dos caminos independientes dando lo mismo es lo más'.PHP_EOL;
    echo '  cerca de una comprobación que hay aquí: el desfase de cinco horas'.PHP_EOL;
    echo '  está confirmado en esta base, no supuesto.'.PHP_EOL;
} else {
    echo "  >>> NO coinciden: {$brecha} filas de diferencia. Antes de creerse".PHP_EOL;
    echo '      ninguno de los dos, mirar el detector — puede haber un escritor'.PHP_EOL;
    echo '      nuevo que ESCRITOS_EN_UTC no conoce, o filas anteriores al'.PHP_EOL;
    echo '      despliegue con el tipo de un middleware y otra zona.'.PHP_EOL;
}

echo PHP_EOL;
echo 'Y el bloque 5 alcanza donde no llegan los otros dos —tipo desconocido,'.PHP_EOL;
echo '`historial_id` NULL—, pero es señal de población: no clasifica filas.'.PHP_EOL.PHP_EOL;
echo 'Con --csv sale una línea por base, para juntar los dieciséis en una tabla.'.PHP_EOL.PHP_EOL;
