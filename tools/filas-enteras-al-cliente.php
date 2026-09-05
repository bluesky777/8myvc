#!/usr/bin/env php
<?php

/*
 * Qué consultas leen una fila entera de una tabla del dominio **y esa fila viaja
 * al cliente**, o sea dónde una columna nueva se publica sola.
 *
 *     docker exec 8myvc-app-1 php tools/filas-enteras-al-cliente.php
 *     docker exec 8myvc-app-1 php tools/filas-enteras-al-cliente.php --todas
 *     docker exec 8myvc-app-1 php tools/filas-enteras-al-cliente.php --tablas=notas,years
 *     docker exec 8myvc-app-1 php tools/filas-enteras-al-cliente.php --autoprueba
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE, Y ES UNA MEDICIÓN, NO UNA SOSPECHA
 *
 * El 2 sep 2026, la migración de nivelaciones añadió cinco columnas a `notas`,
 * tres a `notas_finales` y una a `years`. **Siete instantáneas de contrato se
 * movieron sin que nadie tocara su método**: siete consultas leían la fila con `*`
 * y el `ALTER TABLE` las llenó solo. Los sitios fueron `notas/update`,
 * `notas/show`, `Nota::alumnoPeriodoDetalle`, `Asignatura::calculoAlumnoNotas` y
 * su gemela `2`, `PromovidosController:189` e `Informes/BolfinalesController:508`.
 * Una octava llegó por trabajo ajeno: `subunidades.rubrica_id`, de otra sesión,
 * salió en la planilla del profesor por el `SELECT *` de `NotasController`.
 *
 * **Dos de esos ocho no estaban en la lista de ficheros de ninguna sesión.** No se
 * encontraron leyendo: los encontró la suite. Por eso la regla primera sigue
 * siendo **correr la suite entera después de cada migración que añada columnas**,
 * y esto es lo segundo, no lo primero: sirve para mirar ANTES de migrar y para
 * saber a quién avisar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE NO VE — y esto se lee antes de fiarse del número
 *
 * 1. **No sabe si la fila llega de verdad al cliente.** Mira si la consulta está
 *    en un método público de un controlador, o si su resultado se cuelga de un
 *    objeto (`$x->y = $fila`) o se devuelve. Son **candidatos ordenados**, no
 *    fallos: cada fila se lee. El detector de la §142 dio nueve ciertos que se
 *    leyeron como nueve fallos y ocho estaban bien.
 * 2. **No ve Eloquent sin columnas**: `Model::find()`, `->first()`, `->get()` y
 *    `all()` devuelven la fila entera igual que un `SELECT *`. Los busca por
 *    nombre, pero un `->where(...)->get()` encadenado en varias líneas se le
 *    escapa. Es el mismo hueco que `escrituras-sin-auditoria.php` documenta en su
 *    cabecera, y cae del mismo lado peligroso: **un «no sale» no es un «no está»**.
 * 3. **No mira `resources/`, `routes/` ni los cuatro fronts.** Sólo `app/`.
 *
 * La respuesta a las tres es la misma: esto ordena dónde mirar; **la prueba de que
 * un sitio está congelado es que su instantánea queda verde sin regenerar**.
 */

$raiz = dirname(__DIR__).'/app';

$todas = in_array('--todas', $argv, true);
$autoprueba = in_array('--autoprueba', $argv, true);

/*
 * Las tablas del dominio de notas, que son las que cambian de esquema cuando se
 * añade una función y las que se publican en boletines y certificados.
 *
 * Se enumeran en vez de mirar las noventa **para que el número signifique algo**:
 * «hay 41 sitios que leen la fila entera de alguna tabla» no ordena ningún
 * trabajo. Con `--tablas` se cambia la lista sin tocar el fichero.
 */
$tablas = ['notas', 'notas_finales', 'recuperacion_final', 'years', 'subunidades', 'unidades'];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tablas=')) {
        $tablas = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
    }
}

/*
 * Los modelos Eloquent de esas tablas, para la segunda mitad de la búsqueda.
 * `Model::find()` devuelve la fila entera igual que un asterisco, y la forma en
 * que se rompe es idéntica — `notas/show` era exactamente eso.
 */
/**
 * Tabla -> modelo, **derivado y comprobado contra el esquema**, no escrito a mano.
 *
 * Antes esto era una lista fija de seis pares y `--tablas=` **no la tocaba**: con
 * cualquier otra tabla, `$modelo` salía `null` y **la mitad de Eloquent no se
 * ejecutaba** — sin decirlo. Medido el 4 sep 2026: sobre `profesores` daba **1**
 * donde un barrido a mano daba **13**, y las once que faltaban eran todas Eloquent.
 * Un `1` de un detector medio apagado **se lee igual que un `1` completo**.
 *
 * Y aquella lista fija tenía además una entrada muerta: `recuperacion_final` =>
 * `RecuperacionFinal`, **un modelo que no existe** — `app/Models/RecuperacionFinal.php`
 * no está. Nunca casó nada y nadie se enteró, porque un mapeo que no encuentra no
 * se distingue de una tabla sin usos.
 *
 * Dos fuentes, en este orden, y la segunda **se verifica**:
 *
 * 1. `protected $table = '…'` del modelo (38 de los 53 lo declaran). Es exacto.
 * 2. Para los otros quince, la convención de Laravel — pero **sólo se acepta si esa
 *    tabla existe en `database/schema/mysql-schema.sql`**, que es la verdad de este
 *    repositorio. Así una singularización mal hecha no puede sobrevivir: `Nota` ->
 *    `notas` entra porque la tabla está; lo que no cuadre, no entra.
 *
 * @param  list<string>  $tablasDelEsquema
 * @return array<string, string>
 */
function modelosPorTabla(string $raizApp, array $tablasDelEsquema): array
{
    $mapa = [];
    $porConvencion = [];

    foreach (glob($raizApp.'/Models/*.php') ?: [] as $fichero) {
        $clase = basename($fichero, '.php');
        $texto = file_get_contents($fichero);

        if ($texto === false) {
            continue;
        }

        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([a-z0-9_]+)[\'"]/i', $texto, $m)) {
            $mapa[$m[1]] = $clase;

            continue;
        }

        // snake_case de la clase y los plurales que usa Laravel, a comprobar.
        $base = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $clase));

        foreach ([$base.'s', $base.'es', $base] as $candidata) {
            if (in_array($candidata, $tablasDelEsquema, true)) {
                $porConvencion[$candidata] = $clase;

                break;
            }
        }
    }

    // Lo explícito gana siempre sobre lo derivado.
    return $mapa + $porConvencion;
}

$esquema = file_get_contents(dirname(__DIR__).'/database/schema/mysql-schema.sql');
preg_match_all('/CREATE TABLE `([a-z0-9_]+)`/i', $esquema === false ? '' : $esquema, $m);
$tablasDelEsquema = $m[1];

$modelos = modelosPorTabla($raiz, $tablasDelEsquema);

/*
 * Y si alguna tabla pedida no tiene modelo, **se dice**. Ésta es la mitad que
 * faltaba: el detector seguía contestando con la cara de haber mirado.
 */
$sinModelo = array_values(array_filter($tablas, fn ($t) => ! isset($modelos[$t])));

/**
 * El literal de cadena que empieza en esta línea, unido hasta que se cierra.
 *
 * Existe porque este detector **casaba línea a línea** y una consulta partida era
 * invisible. Medido el 4 sep 2026: `ProfesoresController:116` empieza literalmente
 * por `SELECT p.*` y tiene su `FROM profesores p` **dos líneas más abajo**, así que
 * no salía — no por no reconocer el comodín cualificado, que sí lo reconoce, sino
 * porque las dos mitades nunca estaban en la misma línea.
 *
 * **Lo que NO cubre**: sólo sigue comillas simples, que es como se escriben las
 * consultas de este proyecto, y corta a las 20 líneas. Una consulta en comillas
 * dobles o más larga que eso vuelve a quedarse a medias.
 *
 * @param  list<string>  $lineas
 */
function literalDesde(array $lineas, int $desde, int $maximo = 20): string
{
    $texto = $lineas[$desde];
    $abiertas = substr_count($texto, "'") - substr_count($texto, "\\'");
    $j = $desde;

    while ($abiertas % 2 === 1 && $j - $desde < $maximo && isset($lineas[$j + 1])) {
        $j++;
        $texto .= ' '.$lineas[$j];
        $abiertas += substr_count($lineas[$j], "'") - substr_count($lineas[$j], "\\'");
    }

    return $texto;
}

/**
 * ¿El comodín de esta consulta cubre de verdad las columnas de esa tabla?
 *
 * Tres comprobaciones, y las tres nacieron de un falso positivo real:
 *
 * 1. **`alias.*` sólo cuenta si el alias es de esa tabla** en esta misma consulta.
 *    `SELECT p.* … FROM publicaciones p … JOIN profesores` no reparte `profesores`.
 * 2. **`SELECT *` a secas cuenta si la tabla está en el `FROM`/`JOIN`** — salvo que
 *    el `FROM` sea **una subconsulta**: ahí las columnas son las que la subconsulta
 *    nombre, y el asterisco de fuera no puede traer una columna nueva. Es el caso de
 *    `PerfilesController:129` y de `VtParticipante:79`.
 * 3. **`count(*)` no es leer una fila.**
 */
function cubreLaTabla(string $consulta, string $tabla): bool
{
    $sinConteos = (string) preg_replace('/\bcount\s*\(\s*\*\s*\)/i', 'count(1)', $consulta);
    $seleccion = preg_split('/\bfrom\b/i', $sinConteos)[0] ?? '';

    $enElFrom = (bool) preg_match('/\b(?:FROM|JOIN)\s+`?'.preg_quote($tabla, '/').'`?\b/i', $sinConteos);

    // (2) `SELECT *` pelado: cuenta si la tabla está, y si no se lee de una subconsulta.
    if (preg_match('/select\s+\*/i', $seleccion)) {
        return $enElFrom && ! preg_match('/\bfrom\s*\(/i', $sinConteos);
    }

    // (1) `alias.*`: hay que atar el alias a la tabla.
    if (! preg_match_all('/([a-z_][a-z0-9_]*)\s*\.\s*\*/i', $seleccion, $comodines)) {
        return false;
    }

    preg_match_all('/\b(?:FROM|JOIN)\s+`?'.preg_quote($tabla, '/').'`?\s+(?:as\s+)?([a-z_][a-z0-9_]*)/i', $sinConteos, $alias);
    $suyos = array_map('strtolower', $alias[1]);

    foreach ($comodines[1] as $q) {
        if (in_array(strtolower($q), $suyos, true)) {
            return true;
        }
    }

    return false;
}

/**
 * ¿Esta línea lee la fila entera de una de las tablas?
 *
 * `$consulta` es la línea con su literal ya unido —ver `literalDesde()`—; para
 * Eloquent basta la línea, que ahí no hay literal que seguir.
 *
 * @return array{tabla: string, forma: string}|null
 */
function filaEntera(string $linea, array $tablas, array $modelos, ?string $consulta = null): ?array
{
    $consulta ??= $linea;

    // Un comentario que nombra una consulta no es una consulta.
    $limpia = ltrim($linea);

    if ($limpia === '' || str_starts_with($limpia, '*') || str_starts_with($limpia, '//')
        || str_starts_with($limpia, '#') || str_starts_with($limpia, '/*')) {
        return null;
    }

    foreach ($tablas as $tabla) {
        // `SELECT *` o `SELECT alias.*` sobre la tabla, dentro del MISMO literal.
        //
        // **El alias se resuelve, y no es adorno.** Sin resolverlo, el `p.*` de
        // `publicaciones p` casaba con un `JOIN profesores` de más abajo y salían
        // cinco falsos positivos en `Publicaciones.php` — medido el 4 sep 2026, y
        // avisado por `8myvc-cd`, a quien le pasó lo mismo con su propio troceo.
        // Falla en la dirección cara: **de más**, no de menos.
        if (cubreLaTabla($consulta, $tabla)) {
            return ['tabla' => $tabla, 'forma' => 'SELECT *'];
        }

        $modelo = $modelos[$tabla] ?? null;

        // `findOrFail` y `firstOrFail` devuelven la misma fila entera que `find` y
        // `first`, y este detector no las miraba: de las once de Eloquent que un
        // barrido a mano encontró sobre `profesores` el 4 sep 2026, varias entran
        // justo por ahí. `onlyTrashed()->findOrFail()` sigue fuera: la cadena parte
        // la llamada del nombre del modelo, y eso es el hueco declarado de abajo.
        if ($modelo !== null && preg_match('/\b'.preg_quote($modelo, '/').'::(findOrFail|firstOrFail|find|first|get|all)\s*\(/', $linea, $m)) {
            return ['tabla' => $tabla, 'forma' => $modelo.'::'.$m[1].'()'];
        }
    }

    return null;
}

/**
 * ¿El resultado sale hacia el cliente?
 *
 * Tres señales, y ninguna es prueba: que la línea devuelva, que cuelgue el
 * resultado de un objeto que se está armando, o que el fichero sea un controlador
 * —donde casi todo lo que se lee acaba en la respuesta—. Se dice **cuál** de las
 * tres se cumplió, porque de eso depende cuánto vale la fila.
 */
function porQueViaja(string $linea, array $contexto, string $fichero): ?string
{
    if (preg_match('/^\s*return\b/', $linea)) {
        return 'la línea devuelve';
    }

    // `$algo->campo = $fila` / `$algo["campo"] = $fila`: se está armando la respuesta.
    if (preg_match('/\$\w+(?:->\w+|\[[^\]]+\])\s*=\s*(?:\(array\)\s*)?(?:DB::|\w+::(?:find|first|get|all))/', $linea)) {
        return 'se cuelga de la respuesta';
    }

    foreach ($contexto as $siguiente) {
        if (preg_match('/^\s*return\b/', $siguiente)) {
            return 'devuelta unas líneas después';
        }

        if (preg_match('/\$\w+(?:->\w+|\[[^\]]+\])\s*=\s*\$\w+\s*;/', $siguiente)) {
            return 'se cuelga de la respuesta';
        }
    }

    if (str_contains($fichero, '/Controllers/')) {
        return 'está en un controlador';
    }

    return null;
}

if ($autoprueba) {
    /*
     * El control, que es lo que separa «no encontró nada» de «no miró».
     *
     * Dos líneas que TIENEN que salir y dos que NO. La cuarta es la que más
     * importa: una consulta con las columnas nombradas es justo lo que este
     * detector no debe marcar, porque si las marcara, arreglar un sitio no bajaría
     * el número y nadie volvería a arreglarlo.
     */
    $casos = [
        ["\$x = DB::select('SELECT * FROM notas WHERE id=?', [1]);", true],
        ['$n = Nota::find($id);', true],
        ["DB::select('SELECT n.id, n.nota FROM notas n WHERE n.id=?', [1]);", false],
        ["DB::select('SELECT * FROM ausencias WHERE id=?', [1]);", false],
    ];

    $fallos = 0;

    foreach ($casos as [$linea, $esperado]) {
        $salio = filaEntera($linea, $tablas, $modelos, literalDesde([$linea], 0)) !== null;

        if ($salio !== $esperado) {
            $fallos++;
            echo 'AUTOPRUEBA FALLA: ', $linea, ' → ', $salio ? 'la ve' : 'no la ve',
                ' y se esperaba ', $esperado ? 'verla' : 'no verla', PHP_EOL;
        }
    }

    /*
     * Y los dos casos que este detector NO veía hasta el 4 sep 2026. Van aquí y no
     * en una nota porque **los dos daban verde estando mal**: el primero no salía y
     * el segundo salía a medias, y en las dos formas el número parecía completo.
     */
    $multilinea = [
        "\t\t\$consulta = 'SELECT p.*, c.id as contrato_id,",
        "\t\t\t\tci.ciudad as ciudad_nac_nombre",
        "\t\t\tFROM notas p',",
    ];

    if (filaEntera($multilinea[0], $tablas, $modelos, literalDesde($multilinea, 0)) === null) {
        $fallos++;
        echo "AUTOPRUEBA FALLA: una consulta partida en tres líneas no se ve.\n";
    }

    // Y la misma partida, pero con las columnas nombradas: NO tiene que salir.
    $partidaLimpia = ["\t\t\$consulta = 'SELECT p.id, p.nota", "\t\t\tFROM notas p',"];

    if (filaEntera($partidaLimpia[0], $tablas, $modelos, literalDesde($partidaLimpia, 0)) !== null) {
        $fallos++;
        echo "AUTOPRUEBA FALLA: una consulta partida CON columnas nombradas sale, y no debe.\n";
    }

    // Una tabla sin modelo apaga la mitad de Eloquent: el mapa tiene que saberlo.
    if (isset($modelos['tabla_que_no_existe'])) {
        $fallos++;
        echo "AUTOPRUEBA FALLA: el mapa de modelos inventa entradas.\n";
    }

    if (! isset($modelos['profesores']) || $modelos['profesores'] !== 'Profesor') {
        $fallos++;
        echo "AUTOPRUEBA FALLA: el mapa no leyó `profesores` -> `Profesor` de app/Models.\n";
    }

    // `findOrFail` devuelve la fila entera igual que `find`, y no se miraba.
    if (filaEntera('$n = Nota::findOrFail($id);', $tablas, $modelos) === null) {
        $fallos++;
        echo "AUTOPRUEBA FALLA: `Modelo::findOrFail()` no se ve.\n";
    }

    echo $fallos === 0
        ? "autoprueba: 9 casos, los 9 como se esperaba.\n"
        : "autoprueba: {$fallos} de 9 mal.\n";

    exit($fallos === 0 ? 0 : 2);
}

/** @var iterable<SplFileInfo> $ficheros */
$ficheros = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz));

$revisados = 0;
$hallazgos = [];

foreach ($ficheros as $fichero) {
    if ($fichero->getExtension() !== 'php') {
        continue;
    }

    $revisados++;
    $ruta = str_replace(dirname(__DIR__).'/', '', $fichero->getPathname());
    $lineas = file($fichero->getPathname(), FILE_IGNORE_NEW_LINES);

    if ($lineas === false) {
        continue;
    }

    foreach ($lineas as $i => $linea) {
        /*
         * El literal sólo se une desde una línea que **abre un `SELECT`**. Sin esa
         * condición, empezar a contar comillas en una línea que ya está EN MITAD de
         * otra cadena descuadra la cuenta y la unión se cuela hasta la sentencia
         * siguiente: así salieron `VtParticipantes:98` —un `) votos';` suelto— y
         * `YearsController:347` —un `INSERT` que se comía el `SELECT` de abajo—,
         * los dos falsos y los dos medidos el 4 sep 2026.
         */
        $consulta = preg_match('/\bselect\b/i', $linea) ? literalDesde($lineas, $i) : $linea;
        $encontrada = filaEntera($linea, $tablas, $modelos, $consulta);

        if ($encontrada === null) {
            continue;
        }

        $motivo = porQueViaja($linea, array_slice($lineas, $i + 1, 6), $ruta);

        if ($motivo === null && ! $todas) {
            continue;
        }

        $hallazgos[] = [
            'fichero' => $ruta,
            'linea' => $i + 1,
            'tabla' => $encontrada['tabla'],
            'forma' => $encontrada['forma'],
            'motivo' => $motivo ?? 'no parece salir',
        ];
    }
}

/*
 * La población primero, y **siempre**, aunque el resultado sea cero: un «0
 * encontrados» no distingue *«revisé 226 ficheros y ninguno lo era»* de *«no
 * revisé nada»*, y de las dos lecturas la falsa es la que hace archivar el asunto.
 */
echo 'ficheros de app/ revisados ....... ', $revisados, PHP_EOL;
echo 'tablas vigiladas ................. ', implode(', ', $tablas), PHP_EOL;
echo 'modelos encontrados .............. ', count($modelos), ' (leídos de app/Models/*.php)', PHP_EOL;

if ($sinModelo !== []) {
    echo PHP_EOL,
        'AVISO — SIN MODELO: ', implode(', ', $sinModelo), PHP_EOL,
        '  De esas tablas SÓLO se está mirando el SQL crudo: la mitad de Eloquent', PHP_EOL,
        '  (`Modelo::find/first/get/all`) no se ejecuta. El número de abajo es', PHP_EOL,
        '  incompleto y no se puede leer como si no lo fuera.', PHP_EOL;
}
echo 'sitios que leen la fila entera ... ', count($hallazgos),
    $todas ? " (todos, con --todas)\n" : " (sólo los que parecen viajar; --todas los enseña todos)\n";
echo PHP_EOL;

if ($hallazgos === []) {
    echo "Ninguno. Y eso significa lo que dice sólo si la población de arriba no es cero.\n";
    exit(0);
}

usort($hallazgos, fn ($a, $b) => [$a['tabla'], $a['fichero'], $a['linea']] <=> [$b['tabla'], $b['fichero'], $b['linea']]);

$tablaActual = '';

foreach ($hallazgos as $h) {
    if ($h['tabla'] !== $tablaActual) {
        $tablaActual = $h['tabla'];
        echo PHP_EOL, '── ', $tablaActual, PHP_EOL;
    }

    printf("  %-62s %-22s %s\n", $h['fichero'].':'.$h['linea'], $h['forma'], $h['motivo']);
}

echo PHP_EOL,
    "Cada fila se lee: esto ORDENA candidatos, no da una lista de fallos.\n",
    "Lo que decide es la instantánea — un sitio congelado queda verde SIN regenerarla.\n";

exit(0);
