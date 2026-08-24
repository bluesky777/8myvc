#!/usr/bin/env php
<?php

/*
 * Qué escrituras de `app/` no dejan rastro en `auditoria`.
 *
 *     docker exec 8myvc-app-1 php tools/escrituras-sin-auditoria.php
 *     docker exec 8myvc-app-1 php tools/escrituras-sin-auditoria.php --todas
 *     docker exec 8myvc-app-1 php tools/escrituras-sin-auditoria.php --autoprueba
 *
 * Es la tercera pata de la **fase 3** de docs/migracion/18-auditoria.md, y su
 * salida es **la lista de trabajo de la fase 4** —qué dominios quedan por
 * instrumentar— y lo que dice cuándo esa fase está terminada. Hoy hay
 * **10 `INSERT INTO bitacoras` contra 256 escrituras de datos**: esa proporción
 * es el titular del documento, y esto es lo que la vuelve a medir sola.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO ES `.php` Y NO EL `.py` QUE NOMBRA EL PLAN
 *
 * Porque la pregunta «¿esto es una escritura?» la contesta **exacta** el
 * analizador de PHP y **aproximada** una expresión regular, y la diferencia se
 * cobró antes de escribir la primera línea de aquí:
 *
 *     grep -rnE "DB::(insert|update|delete|statement)\(" app/ | wc -l   ->  257
 *
 * y el plan decía **256**. La de más está en
 * `LoginController.php:147`, dentro de un comentario que **habla** de
 * `DB::update()`. Un `// DB::update()` no escribe nada, y una regex no sabe la
 * diferencia entre código, comentario y cadena.
 *
 * Con `token_get_all()` no hay que saberlo: los comentarios llegan como
 * `T_COMMENT` y el SQL como `T_CONSTANT_ENCAPSED_STRING`, así que un
 * `'DELETE FROM notas'` dentro de una consulta no se cuenta como una llamada, y
 * un `DB::insert` citado en la documentación de una clase, tampoco.
 *
 * Es la regla de CLAUDE.md cobrándose por la vía buena: **el primer sitio donde
 * mirar cuando el número sale raro es el detector**, y aquí el detector todavía
 * era el `grep`.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * QUÉ CUENTA Y QUÉ NO, dicho antes de que nadie lea un cero:
 *
 * - **La unidad es el método, no la sentencia.** Un método que escribe tres
 *   veces la misma fila —`UPDATE` y dos `INSERT` de sus hijos— es **un** cambio
 *   para quien lee el historial, no tres líneas. Pedir una llamada por sentencia
 *   llenaría la pantalla de ruido y daría por incompleto lo que está completo.
 * - **Y por eso mismo esto NO demuestra que cada escritura esté auditada.** Que
 *   un método llame al servicio dice que **alguien pensó en el rastro ahí**, no
 *   que lo haya puesto en las tres ramas. Por eso la salida imprime
 *   `escrituras:auditorías` de cada método: **`5:1` es un sitio donde mirar**, y
 *   el que lo mire decide. Es la segunda mitad de la regla del repo —un detector
 *   puede contar bien un síntoma y no estar contando la causa—, dicha aquí en vez
 *   de esperar a que alguien lea nueve y entienda otra cosa.
 * - **`app/Services/Auditoria.php` se excluye**: su `INSERT` es el rastro, no una
 *   escritura de datos. Contarse a sí mismo sería el detector midiéndose.
 */

$raiz = dirname(__DIR__);
$opciones = array_slice($argv, 1);
$todas = in_array('--todas', $opciones, true);

if (in_array('--autoprueba', $opciones, true)) {
    exit(autoprueba());
}

/* El servicio no se audita a sí mismo. */
$excluidos = [$raiz.'/app/Services/Auditoria.php'];

$ficheros = [];
$iterador = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz.'/app', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterador as $f) {
    if ($f->isFile() && $f->getExtension() === 'php' && ! in_array($f->getPathname(), $excluidos, true)) {
        $ficheros[] = $f->getPathname();
    }
}
sort($ficheros);

$metodos = [];        // "fichero::metodo" => ['escrituras' => [líneas], 'auditorias' => n, ...]
$totalEscrituras = 0;
$totalBitacoras = 0;
$sueltas = [];        // escrituras fuera de cualquier método

foreach ($ficheros as $fichero) {
    $relativo = str_replace($raiz.'/', '', $fichero);

    foreach (analizar((string) file_get_contents($fichero)) as $hallazgo) {
        if ($hallazgo['clase'] === 'escritura') {
            $totalEscrituras++;
        }

        if ($hallazgo['clase'] === 'bitacora') {
            $totalBitacoras++;
        }

        if ($hallazgo['metodo'] === null) {
            if ($hallazgo['clase'] === 'escritura') {
                $sueltas[] = "{$relativo}:{$hallazgo['linea']}";
            }

            continue;
        }

        $clave = $relativo.'::'.$hallazgo['metodo'];
        $metodos[$clave] ??= ['fichero' => $relativo, 'metodo' => $hallazgo['metodo'],
            'escrituras' => [], 'auditorias' => 0, 'bitacoras' => 0];

        // Con `default` que revienta, y no sin él: si mañana `llamadas()` devuelve
        // una clase nueva, esto tiene que **decirlo** en vez de contarla como
        // nada. Un hallazgo que se pierde en un `match` sin salida es una
        // escritura menos en el recuento, y el recuento es todo lo que esta
        // herramienta vende.
        match ($hallazgo['clase']) {
            'escritura' => $metodos[$clave]['escrituras'][] = $hallazgo['linea'],
            'auditoria' => $metodos[$clave]['auditorias']++,
            'bitacora' => $metodos[$clave]['bitacoras']++,
            default => throw new RuntimeException("Clase de hallazgo desconocida: '{$hallazgo['clase']}'."),
        };
    }
}

$conEscrituras = array_filter($metodos, fn ($m) => $m['escrituras'] !== []);
$sinRastro = array_filter($conEscrituras, fn ($m) => $m['auditorias'] === 0 && $m['bitacoras'] === 0);
$soloBitacora = array_filter($conEscrituras, fn ($m) => $m['auditorias'] === 0 && $m['bitacoras'] > 0);
$parciales = array_filter($conEscrituras, fn ($m) => $m['auditorias'] > 0
    && count($m['escrituras']) > $m['auditorias']);

/*
 * La población primero, siempre. Un «0 sin auditoría» no distingue «están todas»
 * de «no revisé nada», y de las dos lecturas la falsa es la que hace archivar el
 * asunto.
 */
printf("ficheros de app/ revisados ....... %d\n", count($ficheros));
printf("escrituras de datos .............. %d   (DB::insert/update/delete/statement, sin comentarios ni cadenas)\n", $totalEscrituras);
printf("de ellas, `INSERT INTO bitacoras`  %d   (el rastro viejo, contado por su consulta)\n", $totalBitacoras);
printf("métodos que escriben ............. %d\n", count($conEscrituras));
printf("  con rastro NUEVO (Auditoria) ... %d\n", count($conEscrituras) - count($sinRastro) - count($soloBitacora));
printf("  con rastro VIEJO (bitacoras) ... %d   <- traducir al servicio\n", count($soloBitacora));
printf("  SIN NINGUNO .................... %d   <- decidir qué se graba\n", count($sinRastro));
printf("  con menos auditorías que escrituras (sitios donde mirar) ... %d\n", count($parciales));

if ($sueltas !== []) {
    printf("\nescrituras fuera de cualquier método (%d): %s\n", count($sueltas), implode(', ', $sueltas));
}

$lista = $todas ? $conEscrituras : $sinRastro;
usort($lista, fn ($a, $b) => [$a['fichero'], $a['metodo']] <=> [$b['fichero'], $b['metodo']]);

echo "\n";
echo $todas
    ? "Todos los métodos que escriben, con escrituras:auditorías —\n\n"
    : "Métodos que escriben y no dejan rastro —\n\n";

foreach ($lista as $m) {
    printf("  %-72s %s:%d   líneas %s\n",
        $m['fichero'].'::'.$m['metodo'],
        count($m['escrituras']),
        $m['auditorias'],
        implode(',', $m['escrituras']));
}

if ($parciales !== [] && ! $todas) {
    echo "\nY estos SÍ auditan, pero menos veces de las que escriben. No son fallos:\n";
    echo "son sitios donde mirar si el rastro cubre todas las ramas —\n\n";

    usort($parciales, fn ($a, $b) => [$a['fichero'], $a['metodo']] <=> [$b['fichero'], $b['metodo']]);

    foreach ($parciales as $m) {
        printf("  %-72s %s:%d\n", $m['fichero'].'::'.$m['metodo'],
            count($m['escrituras']), $m['auditorias']);
    }
}

/**
 * Los hallazgos de un fichero: cada escritura de datos y cada llamada al
 * servicio, con su línea y el método que la contiene.
 *
 * @return list<array{clase: string, linea: int, metodo: string|null}>
 */
function analizar(string $codigo): array
{
    $tokens = token_get_all($codigo);
    $hallazgos = [];

    /*
     * El mapa método→rango de líneas primero, y las llamadas después. Mezclar
     * las dos cosas en un solo bucle hacía el estado imposible de seguir.
     */
    $rangos = rangosDeMetodos($tokens);

    foreach (llamadas($tokens) as $llamada) {
        $hallazgos[] = [
            'clase' => $llamada['clase'],
            'linea' => $llamada['linea'],
            'metodo' => metodoDeLaLinea($rangos, $llamada['linea']),
        ];
    }

    return $hallazgos;
}

/**
 * Nombre de método => [primera línea, última línea].
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LA TRAMPA DE LAS LLAVES, que costó un «fuera de cualquier método» falso:
 *
 * En `"UPDATE {$tabla} SET …"`, el `}` de cierre llega como el token suelto
 * `'}'` —igual que el que cierra un método— pero el `{$` de apertura llega como
 * `T_CURLY_OPEN`, que es un token **de array**. Contando sólo los literales,
 * **cada variable interpolada resta una llave sin haber sumado ninguna**, y a
 * partir de ahí la profundidad va corrida: el método se da por cerrado antes de
 * tiempo y sus escrituras quedan sin dueño.
 *
 * Se vio porque el recuento sacó **una** escritura «fuera de cualquier método»,
 * en `LimpiarHtmlPiar.php:132`, que está dentro de un método de toda la vida —
 * y justo debajo de un `"UPDATE {$tabla} SET {$asignaciones} …"`. Un uno es lo
 * bastante pequeño para archivarlo como rareza; era el detector.
 *
 * Por eso se cuentan también `T_CURLY_OPEN` y `T_DOLLAR_OPEN_CURLY_BRACES`.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<array{nombre: string, desde: int, hasta: int}>
 */
function rangosDeMetodos(array $tokens): array
{
    $rangos = [];
    $profundidad = 0;
    $pendiente = null;
    $esperandoNombre = false;
    $esperandoLlave = false;
    $abierto = null;

    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $texto, $linea] = $token;

            if ($id === T_FUNCTION) {
                $esperandoNombre = true;

                continue;
            }

            if ($esperandoNombre) {
                if ($id === T_STRING) {
                    $pendiente = ['nombre' => $texto, 'desde' => $linea];
                    $esperandoNombre = false;
                    $esperandoLlave = true;
                } elseif ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                    $esperandoNombre = false;
                }
            }

            // Las dos aperturas de llave que NO son el literal `{`. Ver la
            // cabecera: sin esto, cada `"{$var}"` descuadra la cuenta.
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $profundidad++;
            }

            $ultimaLinea = $linea;

            continue;
        }

        if ($token === '{') {
            $profundidad++;

            if ($esperandoLlave && $pendiente !== null) {
                $abierto = $pendiente + ['profundidad' => $profundidad];
                $esperandoLlave = false;
            }

            continue;
        }

        if ($token === '}') {
            if ($abierto !== null && $profundidad === $abierto['profundidad']) {
                $rangos[] = ['nombre' => $abierto['nombre'], 'desde' => $abierto['desde'],
                    'hasta' => $ultimaLinea ?? $abierto['desde']];
                $abierto = null;
            }

            $profundidad--;

            continue;
        }

        if ($token === ';' && $esperandoLlave) {
            $esperandoLlave = false;
        }
    }

    return $rangos;
}

/**
 * El método más **interno** que contiene esa línea.
 *
 * Los rangos se solapan cuando hay clases anónimas o funciones nombradas
 * anidadas; el que manda es el más estrecho, que es quien tiene la escritura
 * delante.
 *
 * @param  list<array{nombre: string, desde: int, hasta: int}>  $rangos
 */
function metodoDeLaLinea(array $rangos, int $linea): ?string
{
    $mejor = null;
    $ancho = PHP_INT_MAX;

    foreach ($rangos as $r) {
        if ($linea >= $r['desde'] && $linea <= $r['hasta'] && ($r['hasta'] - $r['desde']) < $ancho) {
            $mejor = $r['nombre'];
            $ancho = $r['hasta'] - $r['desde'];
        }
    }

    return $mejor;
}

/**
 * Las llamadas que importan: `DB::insert|update|delete|statement` y `Auditoria::`.
 *
 * Sobre los tokens, así que un `DB::update()` escrito en un comentario o un
 * `'DELETE FROM notas'` dentro de una consulta **no cuentan**: son `T_COMMENT` y
 * `T_CONSTANT_ENCAPSED_STRING`, no una llamada.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<array{clase: string, linea: int}>
 */
function llamadas(array $tokens): array
{
    $escrituras = ['insert', 'update', 'delete', 'statement'];
    $resultado = [];

    // Sólo tokens con significado: los espacios y comentarios estorban al mirar
    // «el de antes y el de después».
    $utiles = array_values(array_filter($tokens, fn ($t) => ! is_array($t)
        || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

    foreach ($utiles as $i => $token) {
        /*
         * El rastro VIEJO, reconocido por la consulta y no por la llamada.
         *
         * Hace falta distinguirlo de «no hay rastro», porque son dos trabajos
         * distintos: los diez sitios que hoy escriben en `bitacoras` hay que
         * **traducirlos** al servicio nuevo, y en los demás hay que **decidir
         * qué se graba** en un dominio donde nunca se grabó nada. Juntarlos
         * daría un número más grande y una lista peor.
         *
         * Se mira la cadena de la consulta y no `DB::insert`, porque `DB::insert`
         * no dice en qué tabla escribe. Aquí sí: es la única forma de saberlo sin
         * ejecutar nada.
         */
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/insert\s+into\s+`?bitacoras`?/i', $token[1])) {
            $resultado[] = ['clase' => 'bitacora', 'linea' => $token[2]];
        }

        if (! is_array($token) || $token[0] !== T_DOUBLE_COLON) {
            continue;
        }

        $antes = $utiles[$i - 1] ?? null;
        $despues = $utiles[$i + 1] ?? null;

        if (! is_array($antes) || ! is_array($despues) || $despues[0] !== T_STRING) {
            continue;
        }

        $clase = $antes[1];
        $metodo = strtolower($despues[1]);

        if ($clase === 'DB' && in_array($metodo, $escrituras, true)) {
            $resultado[] = ['clase' => 'escritura', 'linea' => $despues[2]];
        }


        if ($clase === 'Auditoria') {
            $resultado[] = ['clase' => 'auditoria', 'linea' => $despues[2]];
        }
    }

    return $resultado;
}

/**
 * Que el detector detecta lo que dice su nombre.
 *
 * No es adorno: **mientras no reconozca una escritura que sí lo es, su «0 sin
 * auditoría» no significa nada**, y un cero es justo lo que hace archivar el
 * asunto. Se le da un fichero con las cuatro trampas dentro y se comprueban los
 * cuatro veredictos, no sólo el total.
 */
function autoprueba(): int
{
    $muestra = <<<'PHP'
<?php
class Ejemplo
{
    // Un comentario que HABLA de DB::update() y no escribe nada.
    public function sinRastro()
    {
        DB::update('UPDATE notas SET nota = ? WHERE id = ?', [1, 2]);
        DB::insert('INSERT INTO notas (nota) VALUES (?)', [1]);
    }

    public function conRastro()
    {
        DB::update('UPDATE notas SET nota = ?', [1]);
        Auditoria::registrar()->editar('nota', 1)->guardar();
    }

    public function soloUnaCadena()
    {
        $sql = 'DELETE FROM notas WHERE id = 1';
        return DB::select($sql);
    }

    public function dentroDeUnClosure()
    {
        DB::transaction(function () {
            DB::delete('DELETE FROM notas WHERE id = ?', [1]);
        });
    }

    public function conLlavesInterpoladas($tabla)
    {
        DB::update("UPDATE {$tabla} SET x = 1 WHERE id = {$id}", []);
    }

    public function elDeDespues()
    {
        DB::insert('INSERT INTO notas (nota) VALUES (1)');
    }

    public function conElRastroViejo()
    {
        DB::update('UPDATE notas SET nota = 1');
        DB::insert('INSERT INTO bitacoras (created_by) VALUES (?)', [1]);
    }
}
PHP;

    $hallazgos = analizar($muestra);

    $porMetodo = [];
    foreach ($hallazgos as $h) {
        $porMetodo[$h['metodo'] ?? '(fuera)'][$h['clase']] ??= 0;
        $porMetodo[$h['metodo'] ?? '(fuera)'][$h['clase']]++;
    }

    $esperado = [
        // método             escrituras  auditorías   por qué
        'sinRastro' => [2, 0],           // y el DB::update() del comentario NO cuenta
        'conRastro' => [1, 1],
        'soloUnaCadena' => [0, 0],       // 'DELETE FROM …' es una cadena, y select() no escribe
        'dentroDeUnClosure' => [1, 0],   // se atribuye al método, no al closure anónimo
        'conLlavesInterpoladas' => [1, 0],
        // El de después es el que importa de los dos: si `"{$tabla}"` descuadra
        // la cuenta de llaves, ESTE se queda huérfano y el de arriba no. Un
        // detector con la cuenta corrida falla en el método SIGUIENTE.
        'elDeDespues' => [1, 0],
        // Dos escrituras: el UPDATE y el propio INSERT en bitacoras. La segunda
        // se cuenta como escritura Y como rastro viejo a propósito: es las dos
        // cosas, y esconder una de las dos falsearía uno de los dos números.
        'conElRastroViejo' => [2, 0, 1],
    ];

    $fallos = 0;

    foreach ($esperado as $metodo => $quiero) {
        [$escrituras, $auditorias] = $quiero;
        $bitacoras = $quiero[2] ?? 0;

        $e = $porMetodo[$metodo]['escritura'] ?? 0;
        $a = $porMetodo[$metodo]['auditoria'] ?? 0;
        $b = $porMetodo[$metodo]['bitacora'] ?? 0;

        $bien = $e === $escrituras && $a === $auditorias && $b === $bitacoras;
        $fallos += $bien ? 0 : 1;

        printf("  %-22s escrituras %d/%d   auditorías %d/%d   bitácora %d/%d   %s\n",
            $metodo, $e, $escrituras, $a, $auditorias, $b, $bitacoras, $bien ? 'ok' : '← MAL');
    }

    if (isset($porMetodo['(fuera)'])) {
        echo "  ← MAL: algo quedó sin atribuir a ningún método.\n";
        $fallos++;
    }

    $cuantas = count($esperado);

    echo $fallos === 0
        ? "\nAutoprueba: las {$cuantas} trampas reconocidas. Su recuento vale.\n"
        : "\nAutoprueba: {$fallos} mal. NO te fíes de ningún número de esta herramienta.\n";

    return $fallos === 0 ? 0 : 1;
}
