#!/usr/bin/env php
<?php

/*
 * Qué rutas leen una tabla del dominio de notas **y no tienen instantánea de
 * forma**, o sea dónde una columna nueva puede salir al cliente sin que nada lo
 * diga.
 *
 *     docker exec 8myvc-app-1 php tools/rutas-sin-instantanea.php
 *     docker exec 8myvc-app-1 php tools/rutas-sin-instantanea.php --cubiertas
 *     docker exec 8myvc-app-1 php tools/rutas-sin-instantanea.php --tablas=notas,years
 *     docker exec 8myvc-app-1 php tools/rutas-sin-instantanea.php --autoprueba
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE: PORQUE EL DETECTOR BUENO TIENE UN HUECO CON FORMA DE RUTA
 *
 * La regla que salió de la noche del 2 sep 2026 es **correr la suite entera
 * después de cada migración que añada columnas**, y funciona: cazó siete sitios
 * que publicaban columnas nuevas sin que nadie tocara su método.
 *
 * Pero **sólo caza donde hay instantánea**. El octavo —`editnota/alum-asignatura`,
 * que llevaba publicando las cinco columnas de la nivelación desde su migración—
 * no lo cazó nadie: ni la suite, porque esa ruta no tenía instantánea de forma; ni
 * `tools/filas-enteras-al-cliente.php`, porque era un `Model::where(...)->first()`
 * encadenado en dos líneas, que su cabecera declara que no ve. Apareció **de
 * casualidad**, al abrir el método por otra cosa.
 *
 * Esto contesta la pregunta que quedaba: **dónde más puede estar pasando eso
 * ahora mismo**. No mide la cobertura de las 554 rutas —eso es otro trabajo—:
 * mide el cruce pequeño y accionable de las tablas que este plan toca.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE NO VE, Y HAY QUE LEERLO ANTES DE FIARSE DEL NÚMERO
 *
 * 1. **«Lee la tabla» se decide por el TEXTO del método**, más los modelos que
 *    nombra. Una ruta que llegue a `notas` a través de tres capas de servicios no
 *    sale. O sea que el número es **un suelo, no un techo**.
 * 2. **«Tiene instantánea» se decide por la URI escrita en un test que llama a
 *    `compararConInstantanea`.** Un test que la construya con variables no cuenta,
 *    así que puede marcar como descubierta una ruta que sí lo está — el error cae
 *    del lado prudente, que es el que hay que elegir aquí.
 * 3. **Una instantánea no garantiza que cubra toda la respuesta**: `forma()` de una
 *    lista guarda la forma del primer elemento. Estar cubierto es necesario, no
 *    suficiente.
 *
 * Como siempre en este repo: esto **ordena dónde mirar**, no da una lista de
 * fallos, y **imprime su población** para que un cero se pueda leer.
 */

$raiz = dirname(__DIR__);

$verCubiertas = in_array('--cubiertas', $argv, true);
$autoprueba = in_array('--autoprueba', $argv, true);

$tablas = ['notas', 'notas_finales', 'recuperacion_final', 'subunidades', 'unidades', 'years'];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tablas=')) {
        $tablas = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
    }
}

/** Los modelos que representan esas tablas: nombrar el modelo es leer la tabla. */
$modelos = [
    'notas' => 'Nota',
    'notas_finales' => 'NotaFinal',
    'recuperacion_final' => 'RecuperacionFinal',
    'subunidades' => 'Subunidad',
    'unidades' => 'Unidad',
    'years' => 'Year',
];

/**
 * El cuerpo de un método de una clase, por llaves equilibradas.
 *
 * A ojo y no con un analizador: el proyecto tiene 113 controladores y meter una
 * dependencia nueva para esto costaría más que el hueco que deja. Lo que se pierde
 * está declarado en la cabecera.
 */
function cuerpoDelMetodo(string $codigo, string $metodo): ?string
{
    if (! preg_match('/function\s+'.preg_quote($metodo, '/').'\s*\([^)]*\)[^{]*\{/', $codigo, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $inicio = $m[0][1] + strlen($m[0][0]);
    $nivel = 1;
    $largo = strlen($codigo);

    for ($i = $inicio; $i < $largo; $i++) {
        if ($codigo[$i] === '{') {
            $nivel++;
        } elseif ($codigo[$i] === '}') {
            $nivel--;

            if ($nivel === 0) {
                return substr($codigo, $inicio, $i - $inicio);
            }
        }
    }

    return substr($codigo, $inicio);
}

/**
 * El cuerpo sin comentarios.
 *
 * **No es cosmético: sin esto el detector miente.** `NotasController::putUpdate`
 * salía como «lee la fila entera» por un `SELECT n.*, h.id` que está **dentro de
 * un comentario** explicando el cruce que se quitó — o sea que el método marcado
 * era justo uno de los que ya se habían congelado. Un detector que cuenta
 * comentarios convierte el trabajo hecho en trabajo pendiente, y a la tercera
 * nadie vuelve a mirar su lista.
 *
 * Es la misma regla que ya fija `CentinelaDeLosEscritoresDeBitacoraTest` con su
 * caso «un comentario que lo menciona no cuenta como escritor».
 */
function sinComentarios(string $cuerpo): string
{
    $cuerpo = preg_replace('#/\*.*?\*/#s', ' ', $cuerpo) ?? $cuerpo;

    return preg_replace('#//[^\n]*#', ' ', $cuerpo) ?? $cuerpo;
}

/**
 * ¿Ese cuerpo lee además la **fila entera** de una de las tablas?
 *
 * Es el cruce que convierte una lista larga en una corta y accionable: «toca la
 * tabla» incluye a las escrituras, que no publican nada, mientras que **leer la
 * fila entera sin instantánea** es exactamente la forma en que
 * `editnota/alum-asignatura` estuvo publicando cinco columnas sin que nadie lo
 * supiera.
 *
 * El patrón del `Model::where(...)` encadenado va aquí a propósito: es el punto
 * ciego que `tools/filas-enteras-al-cliente.php` declara que NO ve, y que costó
 * el hallazgo del 2 sep. Los dos detectores se cubren uno al otro, y ninguno de
 * los dos cierra el asunto solo.
 */
function leeLaFilaEntera(string $cuerpo, array $tablas, array $modelos): bool
{
    foreach ($tablas as $tabla) {
        if (preg_match('/SELECT\s+(?:[a-z0-9_]+\.)?\*/i', $cuerpo)
            && preg_match('/\b(?:FROM|JOIN)\s+`?'.preg_quote($tabla, '/').'`?\b/i', $cuerpo)) {
            return true;
        }

        $modelo = $modelos[$tabla] ?? null;

        if ($modelo !== null && preg_match('/\b'.preg_quote($modelo, '/').'::(find|first|get|all|where)\s*\(/', $cuerpo)) {
            return true;
        }
    }

    return false;
}

/**
 * Qué tablas de la lista toca ese cuerpo.
 *
 * @return list<string>
 */
function tablasQueToca(string $cuerpo, array $tablas, array $modelos): array
{
    $tocadas = [];

    foreach ($tablas as $tabla) {
        $modelo = $modelos[$tabla] ?? null;

        if (preg_match('/\b'.preg_quote($tabla, '/').'\b/i', $cuerpo)
            || ($modelo !== null && preg_match('/\b'.preg_quote($modelo, '/').'::/', $cuerpo))) {
            $tocadas[] = $tabla;
        }
    }

    return $tocadas;
}

if ($autoprueba) {
    /*
     * El control: dos cuerpos que tocan y dos que no. El cuarto es el que importa
     * —un método que sólo nombra otra tabla no puede salir—, porque si saliera, el
     * número no ordenaría ningún trabajo.
     */
    $casos = [
        ["\$x = DB::select('SELECT id FROM notas WHERE id=?', [1]);", ['notas']],
        ['$n = Nota::find($id);', ['notas']],
        ["DB::select('SELECT * FROM ausencias');", []],
        ['return 1 + 1;', []],
    ];

    $fallos = 0;

    foreach ($casos as [$cuerpo, $esperado]) {
        $salio = tablasQueToca($cuerpo, $tablas, $modelos);

        if ($salio !== $esperado) {
            $fallos++;
            echo 'AUTOPRUEBA FALLA: ', $cuerpo, ' → [', implode(',', $salio), '] y se esperaba [',
                implode(',', $esperado), ']', PHP_EOL;
        }
    }

    // Y el control que costó una lectura falsa: un `SELECT *` **en un comentario**
    // no es una lectura. Sin esto, `putUpdate` —ya congelado— salía en la lista.
    $conComentario = sinComentarios("// antes decía SELECT n.* FROM notas\n\$x = 1;");

    if (leeLaFilaEntera($conComentario, $tablas, $modelos)) {
        $fallos++;
        echo "AUTOPRUEBA FALLA: un `SELECT *` dentro de un comentario cuenta como lectura.\n";
    }

    // Y que el troceador de métodos sepa dónde acaba uno.
    $codigo = "class X {\n public function uno() { if (true) { \$a = 1; } return \$a; }\n public function dos() { return 2; }\n}";
    $cuerpo = cuerpoDelMetodo($codigo, 'uno');

    if ($cuerpo === null || str_contains($cuerpo, 'return 2')) {
        $fallos++;
        echo "AUTOPRUEBA FALLA: el cuerpo de `uno()` se comió el método siguiente.\n";
    }

    echo $fallos === 0
        ? "autoprueba: 6 casos, los 6 como se esperaba.\n"
        : "autoprueba: {$fallos} de 6 mal.\n";

    exit($fallos === 0 ? 0 : 2);
}

// ── Las rutas, del router de verdad y no de un `grep` sobre `routes/`.
exec('cd '.escapeshellarg($raiz).' && php artisan route:list --json 2>/dev/null', $salida, $codigoSalida);

$rutas = json_decode(implode('', $salida), true);

if (! is_array($rutas) || $rutas === []) {
    fwrite(STDERR, "No se pudo leer `route:list --json`. Sin eso esto no mide nada.\n");
    exit(2);
}

// ── Las rutas con instantánea de forma: las URI escritas en un test que compara.
$cubiertas = [];
$testsConInstantanea = 0;

/** @var iterable<SplFileInfo> $ficheros */
$ficheros = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/tests'));

foreach ($ficheros as $fichero) {
    if ($fichero->getExtension() !== 'php') {
        continue;
    }

    $codigo = file_get_contents($fichero->getPathname());

    if ($codigo === false || ! str_contains($codigo, 'compararConInstantanea')) {
        continue;
    }

    $testsConInstantanea++;

    if (preg_match_all('#[\'"]/?(api/[a-z0-9_\-/{}\.]+)[\'"]#i', $codigo, $m)) {
        foreach ($m[1] as $uri) {
            $cubiertas[trim($uri, '/')] = true;
        }
    }
}

/** ¿Esta uri está cubierta, contando que el test puede usar un id concreto donde la ruta tiene `{id}`? */
function estaCubierta(string $uri, array $cubiertas): bool
{
    if (isset($cubiertas[$uri])) {
        return true;
    }

    $patron = '#^'.str_replace(['\{', '\}'], ['{', '}'], preg_quote($uri, '#')).'$#';
    $patron = preg_replace('#\{[a-z0-9_\?]+\}#i', '[^/]+', $patron);

    foreach (array_keys($cubiertas) as $cubierta) {
        if (preg_match($patron, $cubierta)) {
            return true;
        }
    }

    return false;
}

// ── El cruce.
$conTabla = 0;
$sinInstantanea = [];
$conInstantanea = [];
$cacheCodigo = [];

foreach ($rutas as $ruta) {
    $accion = $ruta['action'] ?? '';

    if (! str_contains($accion, '@')) {
        continue;
    }

    [$clase, $metodo] = explode('@', $accion, 2);
    $relativo = 'app/'.str_replace('\\', '/', substr($clase, strlen('App\\'))).'.php';
    $ficheroClase = $raiz.'/'.$relativo;

    if (! is_file($ficheroClase)) {
        continue;
    }

    $cacheCodigo[$ficheroClase] ??= file_get_contents($ficheroClase);
    $cuerpo = cuerpoDelMetodo((string) $cacheCodigo[$ficheroClase], $metodo);

    if ($cuerpo === null) {
        continue;
    }

    $cuerpo = sinComentarios($cuerpo);

    $tocadas = tablasQueToca($cuerpo, $tablas, $modelos);

    if ($tocadas === []) {
        continue;
    }

    $conTabla++;
    $uri = trim((string) ($ruta['uri'] ?? ''), '/');

    $fila = [
        'metodo_http' => $ruta['method'] ?? '',
        'uri' => $uri,
        'accion' => $relativo.'::'.$metodo,
        'tablas' => implode(', ', $tocadas),
        'fila_entera' => leeLaFilaEntera($cuerpo, $tablas, $modelos),
    ];

    if (estaCubierta($uri, $cubiertas)) {
        $conInstantanea[] = $fila;
    } else {
        $sinInstantanea[] = $fila;
    }
}

echo 'rutas en el router ............... ', count($rutas), PHP_EOL;
echo 'tablas vigiladas ................. ', implode(', ', $tablas), PHP_EOL;
echo 'tests que comparan instantánea ... ', $testsConInstantanea, PHP_EOL;
echo 'URIs con instantánea de forma .... ', count($cubiertas), PHP_EOL;
echo PHP_EOL;
$accionables = array_values(array_filter($sinInstantanea, fn ($f) => $f['fila_entera']));

echo 'rutas que tocan esas tablas ...... ', $conTabla, PHP_EOL;
echo '  con instantánea ................ ', count($conInstantanea), PHP_EOL;
echo '  SIN instantánea ................ ', count($sinInstantanea), PHP_EOL;
echo '    y además leen la fila ENTERA . ', count($accionables),
    '   <- las accionables: si filtran, no se entera nadie', PHP_EOL;
echo PHP_EOL;

if (! $verCubiertas) {
    echo "Las que leen la fila entera y no tienen instantánea:\n\n";
}

$aEnseñar = $verCubiertas ? $conInstantanea : $accionables;

if ($aEnseñar === []) {
    echo "Ninguna. Y eso sólo significa algo si la población de arriba no es cero.\n";
    exit(0);
}

usort($aEnseñar, fn ($a, $b) => [$a['uri'], $a['metodo_http']] <=> [$b['uri'], $b['metodo_http']]);

foreach ($aEnseñar as $f) {
    printf("  %-7s %-46s %-52s %s\n", $f['metodo_http'], $f['uri'], $f['accion'], $f['tablas']);
}

echo PHP_EOL,
    "Cada fila se lee: esto ORDENA candidatos. Una ruta sin instantánea no es una\n",
    "ruta que filtre; es una donde, si filtrara, no se enteraría nadie.\n";

exit(0);
