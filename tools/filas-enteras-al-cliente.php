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
$modelos = [
    'notas' => 'Nota',
    'notas_finales' => 'NotaFinal',
    'recuperacion_final' => 'RecuperacionFinal',
    'years' => 'Year',
    'subunidades' => 'Subunidad',
    'unidades' => 'Unidad',
];

/**
 * ¿Esta línea lee la fila entera de una de las tablas?
 *
 * @return array{tabla: string, forma: string}|null
 */
function filaEntera(string $linea, array $tablas, array $modelos): ?array
{
    foreach ($tablas as $tabla) {
        // `SELECT *` o `SELECT alias.*` sobre la tabla, en la misma línea.
        if (preg_match('/SELECT\s+(?:[a-z0-9_]+\.)?\*/i', $linea)
            && preg_match('/\b(?:FROM|JOIN)\s+`?'.preg_quote($tabla, '/').'`?\b/i', $linea)) {
            return ['tabla' => $tabla, 'forma' => 'SELECT *'];
        }

        $modelo = $modelos[$tabla] ?? null;

        if ($modelo !== null && preg_match('/\b'.preg_quote($modelo, '/').'::(find|first|get|all)\s*\(/', $linea, $m)) {
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
        $salio = filaEntera($linea, $tablas, $modelos) !== null;

        if ($salio !== $esperado) {
            $fallos++;
            echo 'AUTOPRUEBA FALLA: ', $linea, ' → ', $salio ? 'la ve' : 'no la ve',
                ' y se esperaba ', $esperado ? 'verla' : 'no verla', PHP_EOL;
        }
    }

    echo $fallos === 0
        ? "autoprueba: 4 casos, los 4 como se esperaba.\n"
        : "autoprueba: {$fallos} de 4 mal.\n";

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
        $encontrada = filaEntera($linea, $tablas, $modelos);

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
