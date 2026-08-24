<?php

/**
 * Qué tablas mueven una respuesta viva si les añades una columna.
 *
 * **La lista que hay que mirar antes de cada `ALTER TABLE`, y que hoy no existe
 * en ninguna parte.**
 *
 * Sale de cruzar dos poblaciones que nadie había cruzado:
 *
 * 1. las consultas de `app/` que hacen `SELECT *` —o `SELECT alias.*`—, o sea las
 *    que **devuelven al cliente lo que haya en la tabla**, no lo que el código
 *    pidió;
 * 2. las tablas cuya **forma está fijada por una instantánea** de
 *    `tests/Contrato/Snapshots/`.
 *
 * Donde se cortan las dos está el peligro: **una columna nueva aparece sola en la
 * respuesta y mueve una pantalla que nadie tocó.**
 *
 * Existe porque el 24 ago 2026 `8myvc-9e` iba a añadir `unidades.alumno_id` para
 * el boletín independiente y eso movía `notas-detailed-profesor.json` —quince
 * columnas fijadas, `alumno_id` sería la decimosexta— entrando por un `SELECT *
 * FROM unidades` de `NotasController`. Se arregló ese sitio; **esto contesta
 * cuántos más hay.**
 *
 * Y es una forma de romper distinta de las que se venían mirando, **la peor de
 * detectar**: las otras dependen de qué código haya delante —un `1052 ambiguous`
 * rompe contra el código viejo—, y ésta **no depende del código: depende de que la
 * consulta diga `*`.** Un `ALTER TABLE` la dispara contra el código viejo **y**
 * contra el nuevo.
 *
 * Uso:
 *
 *     docker exec 8myvc-app-1 php tools/tablas-calientes.php
 *     docker exec 8myvc-app-1 php tools/tablas-calientes.php --detalle
 *     docker exec 8myvc-app-1 php tools/tablas-calientes.php --autoprueba
 *
 * **No escribe nada.** Lee `app/`, el volcado del esquema y los snapshots.
 *
 * ---
 *
 * ## Las cuatro trampas, y las cuatro costaron un número a alguien
 *
 * **0. Los comentarios no son consultas.** Contar con una regex sobre el texto del
 * fichero cuenta **menciones**. Pasó esta misma noche: el docblock de
 * `App\Services\Auditoria` explicaba que hay «10 INSERT INTO bitacoras» y un
 * centinela cantó **once**. Aquí se cuenta sobre `token_get_all()` y sólo dentro
 * de **cadenas literales**, que es donde vive el SQL de este repo.
 *
 * **1. `unidades` no es `unidades_por_defecto`.** `9e` perdió tres falsos
 * positivos por casar los ocho primeros caracteres. Hay **cuatro** familias que
 * empiezan igual —`unidades`/`unidades_por_defecto`,
 * `subunidades`/`subunidades_por_defecto`, `notas`/`notas_finales`,
 * `default_unidades`/`default_subunidades`—. El nombre se casa **entero**, con
 * frontera por delante y por detrás.
 *
 * **2. Un `SELECT *` sobre una subconsulta con las columnas nombradas no filtra
 * nada.** `SELECT * FROM (SELECT id, nota FROM notas) t` devuelve dos columnas y
 * seguirá devolviendo dos después de cualquier `ALTER`. Si lo que sigue al `FROM`
 * es un paréntesis, **no cuenta**.
 *
 * **3. Que una tabla salga en un snapshot no significa que el snapshot fije su
 * forma.** Un `id` suelto no fija nada. Aquí se exige que **casi todas** las
 * columnas de la tabla aparezcan **juntas como claves del mismo objeto**, que es
 * lo que produce un `SELECT *` y lo que un `ALTER TABLE` mueve. El umbral está
 * abajo y se imprime, porque un umbral escondido es un número inventado.
 */

require __DIR__.'/../vendor/autoload.php';

$opciones = getopt('', ['detalle', 'autoprueba', 'help']);

if (isset($opciones['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 3600), PHP_EOL;
    exit(0);
}

$detalle = isset($opciones['detalle']);
$raiz = dirname(__DIR__);

/**
 * Qué fracción de las columnas de una tabla tienen que aparecer juntas como
 * claves de un mismo objeto para decir que ese snapshot **fija su forma**.
 *
 * 0,8 y no 1,0 porque el código a veces quita una columna del objeto antes de
 * devolverlo —`password` es el caso obvio— y eso no impide que la siguiente
 * columna nueva sí aparezca. Y un mínimo de 5 columnas absolutas para que una
 * tabla de tres campos no case con cualquier cosa.
 */
const FRACCION = 0.8;
const MINIMO_COLUMNAS = 5;

// ---------------------------------------------------------------------------
// El esquema: tabla -> columnas. Del volcado, que es la verdad (CLAUDE.md).
// ---------------------------------------------------------------------------
$esquema = [];
$sql = (string) file_get_contents($raiz.'/database/schema/mysql-schema.sql');

foreach (explode('CREATE TABLE ', $sql) as $bloque) {
    if (! preg_match('/^`([a-z_]+)`/', $bloque, $m)) {
        continue;
    }

    preg_match_all('/^\s{2}`([a-z_]+)`/m', $bloque, $cols);
    $esquema[$m[1]] = $cols[1];
}

// ---------------------------------------------------------------------------
// Los `SELECT *` de `app/`, por tabla. Sobre tokens y sólo en cadenas.
// ---------------------------------------------------------------------------
/** @return list<string> */
function cadenasDe(string $fichero): array
{
    $cadenas = [];

    foreach (token_get_all((string) file_get_contents($fichero)) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $cadenas[] = $token[1];
        }
    }

    return $cadenas;
}

/**
 * El contenido de un paréntesis equilibrado que empieza en `$abre`.
 *
 * A mano y no con una regex porque **las expresiones regulares no cuentan
 * paréntesis**, y una subconsulta puede llevar los suyos dentro.
 */
function subconsulta(string $sql, int $abre): ?string
{
    $nivel = 0;
    $largo = strlen($sql);

    for ($i = $abre; $i < $largo; $i++) {
        if ($sql[$i] === '(') {
            $nivel++;
        } elseif ($sql[$i] === ')') {
            $nivel--;

            if ($nivel === 0) {
                return substr($sql, $abre + 1, $i - $abre - 1);
            }
        }
    }

    return null;
}

/**
 * Las tablas que un `SELECT *` de esta consulta puede estar volcando.
 *
 * @return list<string>
 */
function tablasDeUnSelectEstrella(string $sql, array $esquema): array
{
    // Trampa 2: si lo que sigue al FROM es un paréntesis, es una subconsulta y
    // sus columnas ya están nombradas dentro. No cuenta.
    if (! preg_match('/SELECT\s+(?:[a-z_]+\.)?\*/i', $sql)) {
        return [];
    }

    // Trampa 2, y la escribí mal a la primera: mi primera versión miraba si
    // había un `FROM (` **y** si no había salido ninguna tabla, así que
    // `SELECT * FROM (SELECT id, nota FROM notas) t` devolvía `notas` — porque el
    // `FROM notas` de dentro casaba igual. **La autoprueba lo cazó antes de que
    // el número saliera publicado**, que es exactamente para lo que está.
    //
    // Lo correcto es que el `*` de fuera **selecciona de la subconsulta**, cuyas
    // columnas ya están nombradas dentro. Así que si el FROM abre paréntesis, la
    // pregunta se traslada al interior y **es el interior el que decide**.
    if (preg_match('/\bFROM\s*\(/i', $sql, $mm, PREG_OFFSET_CAPTURE)) {
        $dentro = subconsulta($sql, $mm[0][1] + strlen($mm[0][0]) - 1);

        if ($dentro !== null) {
            return tablasDeUnSelectEstrella($dentro, $esquema);
        }
    }

    $tablas = [];

    // Trampa 1: el nombre entero, con frontera por detrás. `\b` no basta porque
    // `_` es carácter de palabra y `unidades\b` sí casaría con el principio de
    // `unidades_por_defecto`; el `(?![a-z_])` es lo que lo impide. Comprobado con
    // las cuatro familias que empiezan igual, en la autoprueba.
    preg_match_all('/\b(?:FROM|JOIN)\s+(`?)([a-z_]+)\1(?![a-z_])/i', $sql, $m, PREG_SET_ORDER);

    foreach ($m as $par) {
        $tabla = strtolower($par[2]);

        if (isset($esquema[$tabla])) {
            $tablas[$tabla] = true;
        }
    }

    return array_keys($tablas);
}

$estrellas = [];       // tabla => [ 'fichero:linea', ... ]
$totalEstrellas = 0;
$ficherosVistos = 0;

/** @var iterable<SplFileInfo> $it */
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/app'));

foreach ($it as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }

    $ficherosVistos++;
    $relativa = str_replace($raiz.'/', '', $f->getPathname());

    foreach (cadenasDe($f->getPathname()) as $cadena) {
        if (! preg_match('/SELECT\s+(?:[a-z_]+\.)?\*/i', $cadena)) {
            continue;
        }

        $totalEstrellas++;

        foreach (tablasDeUnSelectEstrella($cadena, $esquema) as $tabla) {
            $estrellas[$tabla][] = $relativa;
        }
    }
}

// ---------------------------------------------------------------------------
// Las tablas cuya FORMA fija una instantánea. Trampa 3.
// ---------------------------------------------------------------------------
$conjuntosDeClaves = [];   // [ [claves...], ... ] con su fichero
$snapshots = glob($raiz.'/tests/Contrato/Snapshots/*.json') ?: [];

function recogerClaves(mixed $nodo, string $fichero, array &$acc): void
{
    if (is_array($nodo)) {
        $claves = array_keys($nodo);

        if ($claves !== [] && ! array_is_list($nodo)) {
            $acc[] = ['claves' => array_map('strval', $claves), 'fichero' => basename($fichero)];
        }

        foreach ($nodo as $hijo) {
            recogerClaves($hijo, $fichero, $acc);
        }
    }
}

foreach ($snapshots as $s) {
    $datos = json_decode((string) file_get_contents($s), true);
    recogerClaves($datos, $s, $conjuntosDeClaves);
}

$fijadas = [];   // tabla => [snapshots...]

foreach ($esquema as $tabla => $columnas) {
    if (count($columnas) < MINIMO_COLUMNAS) {
        continue;
    }

    foreach ($conjuntosDeClaves as $conjunto) {
        $comunes = count(array_intersect($columnas, $conjunto['claves']));

        if ($comunes >= MINIMO_COLUMNAS && $comunes / count($columnas) >= FRACCION) {
            $fijadas[$tabla][$conjunto['fichero']] = true;
        }
    }
}

// ---------------------------------------------------------------------------
// El cruce.
// ---------------------------------------------------------------------------
$calientes = [];

foreach ($estrellas as $tabla => $sitios) {
    if (isset($fijadas[$tabla])) {
        $calientes[$tabla] = [
            'sitios' => array_values(array_unique($sitios)),
            'snapshots' => array_keys($fijadas[$tabla]),
            'columnas' => count($esquema[$tabla]),
        ];
    }
}

if (isset($opciones['autoprueba'])) {
    $trampas = [
        ['SELECT * FROM unidades u WHERE u.id=?', ['unidades'], 'el caso real'],
        ['SELECT * FROM unidades_por_defecto WHERE id=?', ['unidades_por_defecto'], 'trampa 1: NO es `unidades`'],
        ['SELECT * FROM notas_finales WHERE id=?', ['notas_finales'], 'trampa 1: NO es `notas`'],
        ['SELECT * FROM (SELECT id, nota FROM notas) t', [], 'trampa 2: subconsulta con columnas nombradas'],
        ['SELECT u.* FROM unidades u INNER JOIN notas n ON n.id=1', ['unidades', 'notas'], 'alias.* cuenta, y el JOIN también'],
        ['SELECT id, definicion FROM unidades', [], 'sin asterisco no cuenta'],
    ];

    echo PHP_EOL.'Autoprueba del detector'.PHP_EOL.str_repeat('=', 78).PHP_EOL.PHP_EOL;
    $fallos = 0;

    foreach ($trampas as [$sql, $esperado, $porque]) {
        $dio = tablasDeUnSelectEstrella($sql, $esquema);
        sort($dio);
        $esp = $esperado;
        sort($esp);
        $ok = $dio === $esp;
        $fallos += $ok ? 0 : 1;
        printf("  %s  %-52s\n      esperado [%s]  dio [%s]\n      %s\n\n",
            $ok ? 'ok ' : 'MAL', substr($sql, 0, 52),
            implode(',', $esp), implode(',', $dio), $porque);
    }

    echo '  '.($fallos === 0 ? 'las seis trampas dan lo que deben.' : "{$fallos} mal.").PHP_EOL.PHP_EOL;
    exit($fallos === 0 ? 0 : 1);
}

echo PHP_EOL.'Tablas calientes — añadirles una columna mueve una respuesta viva'.PHP_EOL;
echo str_repeat('=', 78).PHP_EOL.PHP_EOL;

echo "  Población, que es lo que hace legibles los ceros de abajo:".PHP_EOL;
echo "      ficheros de `app/` revisados ............ {$ficherosVistos}".PHP_EOL;
echo '      consultas con `SELECT *` ................ '.$totalEstrellas.PHP_EOL;
echo '      de ésas, resueltas a una tabla real .... '.array_sum(array_map('count', $estrellas)).' sobre '.count($estrellas).' tablas'.PHP_EOL;
echo '      instantáneas leídas .................... '.count($snapshots).PHP_EOL;
echo '      objetos con claves dentro .............. '.count($conjuntosDeClaves).PHP_EOL;
echo '      tablas con la forma fijada ............. '.count($fijadas).' (umbral: '.(int) (FRACCION * 100).'% de sus columnas, mínimo '.MINIMO_COLUMNAS.')'.PHP_EOL;
echo PHP_EOL;
echo '  >>> CALIENTES: '.count($calientes).PHP_EOL.PHP_EOL;

if ($calientes === []) {
    echo '  Ninguna. Con la población de arriba delante, eso significa que ningún'.PHP_EOL;
    echo '  `SELECT *` toca una tabla cuya forma fije una instantánea.'.PHP_EOL.PHP_EOL;
} else {
    uasort($calientes, fn ($a, $b) => count($b['sitios']) <=> count($a['sitios']));

    foreach ($calientes as $tabla => $d) {
        printf("  %-26s %2d columnas · %d consulta(s) · %d instantánea(s)\n",
            $tabla, $d['columnas'], count($d['sitios']), count($d['snapshots']));

        if ($detalle) {
            foreach ($d['sitios'] as $sitio) {
                echo '        consulta: '.$sitio.PHP_EOL;
            }
            foreach ($d['snapshots'] as $snap) {
                echo '        fija:     '.$snap.PHP_EOL;
            }
            echo PHP_EOL;
        }
    }

    echo PHP_EOL;
}

echo str_repeat('=', 78).PHP_EOL;
echo 'Esto se mira ANTES de escribir un `ALTER TABLE`. Si la tabla está aquí,'.PHP_EOL;
echo 'añadirle una columna mueve una respuesta que hay que avisar a los cuatro'.PHP_EOL;
echo 'clientes — y `myvc_flutter` es UNA app para los dieciséis colegios.'.PHP_EOL.PHP_EOL;
echo 'Con --autoprueba se comprueba que el detector distingue `unidades` de'.PHP_EOL;
echo '`unidades_por_defecto` y que no cuenta subconsultas. Correr eso primero.'.PHP_EOL.PHP_EOL;
