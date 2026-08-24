#!/usr/bin/env php
<?php

/*
 * La fase 0 de los dieciséis colegios, en UNA visita al servidor.
 *
 *     docker exec 8myvc-app-1 php tools/fase-cero-de-los-dieciseis.php colegio1 colegio2 ...
 *     docker exec 8myvc-app-1 php tools/fase-cero-de-los-dieciseis.php --colegios=/ruta/lista.txt
 *     docker exec 8myvc-app-1 php tools/fase-cero-de-los-dieciseis.php --csv colegio1 colegio2 ... > fase0.csv
 *
 * En el servidor, sin docker:
 *
 *     php tools/fase-cero-de-los-dieciseis.php --csv $(cat /ruta/colegios.txt) > fase0.csv
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE
 *
 * Había **cuatro** `for` de sólo lectura pendientes, repartidos en cuatro
 * documentos, con cuatro formatos de salida y cuatro visitas al servidor. Llevaban
 * días sin correrse, y uno de ellos —los dieciséis números de la fase 0 de las
 * definitivas— es lo único que bloquea su fase 2.
 *
 * Cuatro visitas a un servidor de cPanel al que hay que entrar a mano no son
 * cuatro veces el trabajo de una: son la diferencia entre hacerse y no hacerse.
 * Esto es una.
 *
 * ## Las cuatro preguntas
 *
 *   1. **Identidad y población** del colegio. Va primero porque **todos los ceros
 *      de abajo son ambiguos sin ella**: dieciséis ceros sin población se leen como
 *      dieciséis colegios sanos, y pueden ser dieciséis bases que no se abrieron.
 *   2. **Los interruptores que no lee nadie** (INT-1): ¿hay alguno encendido en
 *      algún colegio? Un `tinyint(1)` que nadie lee no es un interruptor apagado —
 *      puede ser uno que alguien encendió y que no hace nada desde entonces.
 *   3. **El rol `Admin` sin `is_superuser`** (09 §14): ninguna guarda del backend
 *      mira el rol `Admin`, así que un `Admin` sin `is_superuser` **no puede entrar
 *      a las once rutas que piden `esAdministrativo`**. En un colegio salió 10 = 10;
 *      eso es un colegio y no vale como respuesta.
 *   4. **Salud de las definitivas** (fase 0 del 10) y **salud de la bitácora**
 *      (fase 0 del 18), **delegadas a sus herramientas**. Ver abajo por qué.
 *
 * ## POR QUÉ LAS DOS ÚLTIMAS SE DELEGAN Y NO SE RETECLEAN
 *
 * Porque **una medición reteclada es una segunda medición, y dos mediciones de lo
 * mismo acaban discrepando**. Este repositorio ya tiene la cicatriz: la herramienta
 * de la fase 0 de definitivas *medía de menos* —contaba duplicados dentro del
 * alcance mirado cuando un índice único mira la tabla entera— y se corrigió **en
 * ella**. Copiar aquí esas consultas sería reintroducir esa clase de error, y
 * además dejaría dos sitios que hay que arreglar cada vez.
 *
 * El precio, dicho: **no es una sola conexión por colegio, son tres** —la de este
 * guion y una por herramienta delegada—. Lo que se compra con eso no es una
 * conexión: es **una visita y un formato**, que es lo que faltaba.
 *
 * ## LO QUE ESTE GUION NO PUEDE ESCRIBIR, Y QUÉ LO IMPIDE
 *
 * Tres capas, y la tercera es la que vale porque **se comprueba**:
 *
 *   1. Todas las consultas pasan por `leer()`, que **rechaza** lo que no empiece
 *      por `SELECT` o `SHOW`.
 *   2. Cada colegio se abre dentro de `START TRANSACTION READ ONLY`, así que el
 *      **servidor** rechaza cualquier escritura, no este código.
 *   3. Y antes de leer nada, `comprobarQueNoPuedeEscribir()` **intenta una
 *      escritura y exige que falle**. Si el servidor la dejara pasar, el guion
 *      **aborta el colegio entero** en vez de seguir. Una garantía que no se
 *      comprueba es un comentario, y de eso ya hay bastante.
 *
 * La escritura de prueba es `UPDATE users SET id = id WHERE 1 = 0`: la transacción
 * de sólo lectura la rechaza al ejecutarla, y **si por lo que sea se ejecutara, no
 * toca ninguna fila y no cambia ningún valor**. Las dos cosas a la vez, a propósito.
 *
 * Las dos herramientas delegadas van en su propio proceso y con su propia
 * conexión, así que **esta garantía no las cubre**, y hay que decir qué son:
 * `salud-de-la-bitacora.php` es sólo lectura; `salud-de-las-definitivas.php`
 * **crea y borra una tabla TEMPORARY**, o sea que escribe — pero sólo en una tabla
 * de su propia sesión, que muere al cerrar la conexión, y **nunca en los datos del
 * colegio**. No es lo mismo que «no escribe» y por eso se escribe aquí.
 *
 * ## EL FORMATO: LARGO, NO ANCHO
 *
 * `colegio,bloque,clave,valor,limite` — una fila por dato.
 *
 * Un CSV **ancho** (una fila por colegio, una columna por dato) parece más cómodo y
 * se rompe en cuanto un bloque gana un campo: las dieciséis filas dejan de tener la
 * misma forma y hay que cruzarlas a mano, que es justo lo que se viene a quitar. En
 * formato largo, juntar dieciséis colegios es `cat`, y añadir un dato mañana no
 * mueve ninguna columna.
 *
 * La columna `limite` no es documentación: dice **qué no contesta ese número**, en
 * la misma fila, para que nadie lo cite sin su letra pequeña.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/*
 * Los interruptores que no lee nadie, medidos por
 * `tools/interruptores-que-nadie-lee.py --clientes` el 24 ago 2026 y detallados en
 * docs/migracion/noche-2026-08-24/int-1.md: **49 columnas medidas** en 16 tablas.
 *
 * La lista está escrita a mano, y por eso lleva su propio guardián: antes de
 * contar nada, `comprobarQueLaListaEncaja()` mira que **cada par columna/tabla
 * exista en el esquema de ESE colegio**. Una lista a mano sin comprobación dura
 * hasta el siguiente cambio de esquema — y aquí, de paso, contesta una pregunta
 * que nadie había hecho: **¿tienen los dieciséis colegios el mismo esquema?** Si
 * en uno falta una columna, sale por aquí.
 *
 * La 50ª (`ws_actividades_resueltas.timeout`) NO está: se leyó a mano y no se
 * midió, y un número medido y uno leído no se suman. Está en el int-1.
 */
const INTERRUPTORES = [
        'change_asked_assignment' => ['defini_comport_accepted', 'frase_asignat_accepted', 'nota_accepted', 'nota_comport_accepted'],
        'change_asked_data' => ['barrio_accepted', 'celular_accepted', 'ciudad_doc_accepted', 'ciudad_nac_accepted', 'ciudad_resid_accepted', 'direccion_accepted', 'documento_accepted', 'email_accepted', 'eps_accepted', 'estrato_accepted', 'facebook_accepted', 'religion_accepted', 'telefono_accepted', 'tipo_doc_accepted', 'tipo_sangre_accepted'],
        'config_certificados' => ['encabezado_solo_primera_pagina', 'piepagina_solo_ultima_pagina'],
        'default_subunidades' => ['can_change_definicion', 'can_change_orden', 'can_change_porcentaje'],
        'default_unidades' => ['can_change_definicion', 'can_change_orden', 'can_change_porcentaje', 'show_definicion'],
        'df_alumnos' => ['per1_recuperado', 'per2_recuperado', 'per3_recuperado', 'per4_recuperado'],
        'df_asignaturas' => ['per1_manual', 'per1_recuperada', 'per2_manual', 'per2_recuperada', 'per3_manual', 'per3_recuperada', 'per4_manual', 'per4_recuperada', 'year_recuperada'],
        'dis_acciones_restaurativas' => ['cumplida'],
        'dis_procesos' => ['deriva_de_tipos1', 'deriva_de_tipos2', 'firma_acudiente', 'firma_alumno'],
        'matriculas' => ['profes_editar_notas'],
        'subunidades' => ['por_defecto'],
        'unidades' => ['por_defecto'],
        'users' => ['can_ask'],
        'ws_actividades_resueltas' => ['is_puntaje_manual'],
        'ws_contenidos_preg' => ['is_cuadricula'],
        'ws_preguntas' => ['aleatorias'],
];

/** La §14 del 09, literal. */
const ADMIN_SIN_SUPERUSUARIO = 'SELECT COUNT(*) AS n FROM role_user ru
      INNER JOIN roles r ON r.id = ru.role_id
      INNER JOIN users u ON u.id = ru.user_id
     WHERE r.name = "Admin" AND u.is_superuser = 0 AND u.deleted_at IS NULL';

// ─────────────────────────────────────────────────────────────────────────────

$argumentos = array_slice($argv, 1);
$csv = in_array('--csv', $argumentos, true);
$colegios = [];

foreach ($argumentos as $a) {
    if (str_starts_with($a, '--colegios=')) {
        $f = substr($a, 11);
        if (! is_readable($f)) {
            fwrite(STDERR, "No puedo leer la lista de colegios: {$f}\n");
            exit(1);
        }
        $colegios = array_merge($colegios, array_filter(array_map('trim', (array) file($f))));
    } elseif (! str_starts_with($a, '--')) {
        $colegios[] = $a;
    }
}

if ($colegios === []) {
    fwrite(STDERR, "Uso: php tools/fase-cero-de-los-dieciseis.php [--csv] colegio1 colegio2 ...\n");
    fwrite(STDERR, "     php tools/fase-cero-de-los-dieciseis.php [--csv] --colegios=lista.txt\n");
    exit(1);
}

$filas = [];
$fallados = [];

if ($csv) {
    echo "colegio,bloque,clave,valor,limite\n";
}

foreach ($colegios as $colegio) {
    try {
        abrir($colegio);
        comprobarQueNoPuedeEscribir($colegio);
    } catch (Throwable $e) {
        // Un colegio que no abre **no es un colegio con ceros**, y ésa es la
        // confusión que este guion existe para no permitir: sale a STDERR, se
        // cuenta aparte, y su fila lo dice.
        $fallados[$colegio] = $e->getMessage();
        fwrite(STDERR, "  !! {$colegio}: NO MEDIDO — ".$e->getMessage()."\n");
        emitir($csv, $colegio, 'identidad', 'medido', 'NO', $e->getMessage());

        continue;
    }

    if (! $csv) {
        echo "\n".str_repeat('=', 78)."\n  {$colegio}\n".str_repeat('=', 78)."\n";
    }

    bloqueIdentidad($csv, $colegio);
    bloqueInterruptores($csv, $colegio);
    bloqueAdmin($csv, $colegio);
    // A las dos se les pide `--csv` cuando este guion va en CSV: así las cinco
    // salidas son tabulables y juntar dieciséis colegios es `cat`. El de
    // `definitivas` se añadió el 24 ago 2026 —era la única de las cinco sin
    // tabular, y la que desbloquea la fase 2—; si se corriera contra una copia
    // vieja de la herramienta, el flag sale ignorado y su bloque vuelve a salir
    // como texto, que es degradar y no romper.
    bloqueDelegado($csv, $colegio, 'bitacora', 'salud-de-la-bitacora.php', $csv ? ['--csv'] : []);
    bloqueDelegado($csv, $colegio, 'definitivas', 'salud-de-las-definitivas.php', $csv ? ['--csv'] : []);

    DB::rollBack();
}

fwrite(STDERR, "\n".str_repeat('-', 78)."\n");
fwrite(STDERR, '  colegios pedidos: '.count($colegios)."\n");
fwrite(STDERR, '  medidos:          '.(count($colegios) - count($fallados))."\n");
fwrite(STDERR, '  NO medidos:       '.count($fallados)."\n");

foreach ($fallados as $c => $por) {
    fwrite(STDERR, "     {$c}: {$por}\n");
}

fwrite(STDERR, "\n  Un colegio NO MEDIDO no es un colegio limpio. Si este número no es 0,\n");
fwrite(STDERR, "  la respuesta a las cuatro preguntas todavía no está completa.\n");

exit($fallados === [] ? 0 : 2);

// ─────────────────────────────────────────────────────────────────────────────

/** Abre el colegio y deja la sesión en sólo lectura. */
function abrir(string $colegio): void
{
    config(['database.connections.mysql.database' => $colegio]);
    DB::purge('mysql');
    DB::reconnect('mysql');

    // El `SELECT 1` es lo que fuerza la conexión de verdad: sin él, un nombre de
    // base inexistente no se nota hasta la primera consulta de un bloque, y
    // entonces el fallo sale a media medición.
    DB::select('SELECT 1');

    // Sólo lectura **del lado del servidor**. Lo que sigue no puede escribir
    // aunque el código de abajo se equivoque.
    DB::getPdo()->exec('START TRANSACTION READ ONLY');
}

/**
 * Intenta escribir y **exige que falle**.
 *
 * Es la única llamada de este fichero que no pasa por `leer()`, y es a propósito:
 * su trabajo es justamente ser lo que `leer()` rechaza. Si el servidor la dejara
 * pasar, la garantía de sólo lectura no existe y este colegio no se mide.
 *
 * `WHERE 1 = 0` y `SET id = id`: si por lo que fuera se ejecutara, no toca
 * ninguna fila y no cambia ningún valor. Las dos cosas a la vez.
 */
function comprobarQueNoPuedeEscribir(string $colegio): void
{
    try {
        DB::getPdo()->exec('UPDATE users SET id = id WHERE 1 = 0');
    } catch (Throwable) {
        return; // Falló, que es lo que tenía que pasar.
    }

    throw new RuntimeException(
        'la transacción de sólo lectura NO está impidiendo escribir. '
        .'Este guion no mide un colegio en el que podría escribir.'
    );
}

/** La única puerta de lectura. Rechaza lo que no sea una consulta. */
function leer(string $sql, array $parametros = []): array
{
    if (! preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
        throw new RuntimeException("Este guion sólo lee, y esto no es una lectura: {$sql}");
    }

    return DB::select($sql, $parametros);
}

function emitir(bool $csv, string $colegio, string $bloque, string $clave, string|int $valor, string $limite = ''): void
{
    if ($csv) {
        $escapar = static fn (string $s): string => '"'.str_replace('"', '""', $s).'"';
        echo implode(',', [$escapar($colegio), $escapar($bloque), $escapar($clave),
            $escapar((string) $valor), $escapar($limite)])."\n";

        return;
    }

    printf("  %-46s %s%s\n", $clave, $valor, $limite === '' ? '' : "   [{$limite}]");
}

/**
 * Bloque 1 — identidad y población. **Va primero y no es opcional.**
 *
 * Todos los ceros de los bloques siguientes son ambiguos sin esto: dieciséis ceros
 * sin población se leen como dieciséis colegios sanos y pueden ser dieciséis bases
 * que no se abrieron.
 */
function bloqueIdentidad(bool $csv, string $colegio): void
{
    if (! $csv) {
        echo "\n-- 1. identidad y población\n";
    }

    emitir($csv, $colegio, 'identidad', 'medido', 'SI');
    emitir($csv, $colegio, 'identidad', 'base', (string) DB::selectOne('SELECT DATABASE() AS d')->d);
    emitir($csv, $colegio, 'identidad', 'mysql', (string) DB::selectOne('SELECT VERSION() AS v')->v);

    // La zona de la sesión de MySQL, que es media enfermedad de las horas raras
    // (18 §1.2): `bitacoras.created_at` es TIMESTAMP y convierte con ella, así que
    // **si los dieciséis no coinciden, eso ya es un incidente por sí solo.**
    $zonas = DB::selectOne('SELECT @@system_time_zone AS sistema, @@session.time_zone AS sesion');
    emitir($csv, $colegio, 'identidad', 'system_time_zone', (string) $zonas->sistema,
        'si los dieciseis no coinciden, las TIMESTAMP historicas no son comparables');
    emitir($csv, $colegio, 'identidad', 'session_time_zone', (string) $zonas->sesion,
        'SYSTEM = la del hosting, no fijada por la aplicacion');

    foreach (['users', 'alumnos', 'matriculas', 'notas', 'notas_finales', 'bitacoras', 'historiales'] as $tabla) {
        emitir($csv, $colegio, 'poblacion', $tabla, (int) leer("SELECT COUNT(*) AS n FROM `{$tabla}`")[0]->n);
    }

    emitir($csv, $colegio, 'poblacion', 'tablas', (int) leer(
        'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
    )[0]->n, 'una base migrada tiene mas tablas que el volcado congelado');
}

/**
 * Bloque 2 — los interruptores de INT-1.
 *
 * Lo que se busca es **una fila en 1**, y con el `DEFAULT` al lado, porque sin él
 * el número engaña: `users.can_ask` sale a 1 en las 2.351 filas de la base de
 * pruebas **y es su valor de serie** (`NOT NULL DEFAULT '1'`), o sea una ausencia
 * de decisión y no una decisión. Reportarlo sin el `DEFAULT` mandaría a averiguar
 * quién encendió un permiso que nadie encendió.
 */
function bloqueInterruptores(bool $csv, string $colegio): void
{
    if (! $csv) {
        echo "\n-- 2. interruptores que no lee nadie (49 medidos, int-1)\n";
    }

    [$pares, $ausentes] = comprobarQueLaListaEncaja();

    emitir($csv, $colegio, 'interruptores', 'pares revisados', count($pares),
        'la lista sale de interruptores-que-nadie-lee.py del 24 ago 2026');

    if ($ausentes !== []) {
        emitir($csv, $colegio, 'interruptores', 'pares AUSENTES en este esquema',
            count($ausentes), implode(' ', $ausentes));
    }

    $encendidos = 0;

    foreach ($pares as [$tabla, $columna, $porDefecto]) {
        $f = leer("SELECT COUNT(*) AS filas, SUM(`{$columna}` = 1) AS en_uno FROM `{$tabla}`")[0];
        $enUno = (int) $f->en_uno;

        if ($enUno === 0) {
            continue;
        }

        $encendidos++;
        emitir($csv, $colegio, 'interruptores', "{$tabla}.{$columna}",
            "{$enUno} de {$f->filas} en 1",
            "DEFAULT={$porDefecto}".($porDefecto === '1' ? ' <- de serie, NO es una decision' : ''));
    }

    emitir($csv, $colegio, 'interruptores', 'con alguna fila en 1', $encendidos,
        'una tabla vacia da 0 y no significa apagado: significa sin datos');
}

/**
 * Que la lista a mano encaje con el esquema de **este** colegio.
 *
 * @return array{0: list<array{0: string, 1: string, 2: string}>, 1: list<string>}
 */
function comprobarQueLaListaEncaja(): array
{
    $reales = [];

    foreach (leer('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_DEFAULT AS d
                     FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_TYPE = "tinyint(1)"') as $f) {
        $reales[$f->t.'.'.$f->c] = $f->d === null ? 'NULL' : (string) $f->d;
    }

    $pares = [];
    $ausentes = [];

    foreach (INTERRUPTORES as $tabla => $columnas) {
        foreach ($columnas as $columna) {
            $clave = $tabla.'.'.$columna;

            if (! array_key_exists($clave, $reales)) {
                $ausentes[] = $clave;

                continue;
            }

            $pares[] = [$tabla, $columna, $reales[$clave]];
        }
    }

    return [$pares, $ausentes];
}

/** Bloque 3 — la §14: un `Admin` sin `is_superuser` no entra donde debería. */
function bloqueAdmin(bool $csv, string $colegio): void
{
    if (! $csv) {
        echo "\n-- 3. el rol Admin sin is_superuser (09 §14)\n";
    }

    $conRol = (int) leer('SELECT COUNT(DISTINCT ru.user_id) AS n FROM role_user ru
        INNER JOIN roles r ON r.id = ru.role_id
        INNER JOIN users u ON u.id = ru.user_id
        WHERE r.name = "Admin" AND u.deleted_at IS NULL')[0]->n;

    $superusuarios = (int) leer('SELECT COUNT(*) AS n FROM users
        WHERE is_superuser = 1 AND deleted_at IS NULL')[0]->n;

    emitir($csv, $colegio, 'admin', 'usuarios con rol Admin', $conRol);
    emitir($csv, $colegio, 'admin', 'usuarios is_superuser', $superusuarios);

    emitir($csv, $colegio, 'admin', 'Admin SIN is_superuser',
        (int) leer(ADMIN_SIN_SUPERUSUARIO)[0]->n,
        'si no es 0, esas personas NO entran a las once rutas de esAdministrativo');

    emitir($csv, $colegio, 'admin', 'is_superuser SIN rol Admin',
        (int) leer('SELECT COUNT(*) AS n FROM users u
            WHERE u.is_superuser = 1 AND u.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM role_user ru
                    INNER JOIN roles r ON r.id = ru.role_id
                    WHERE ru.user_id = u.id AND r.name = "Admin")')[0]->n,
        'la mitad contraria: el front les esconde el menu y el backend les deja entrar');
}

/**
 * Bloques 4 y 5 — delegados, no reteclados.
 *
 * Se ejecutan en su propio proceso con `DB_DATABASE` puesto, que es como están
 * documentados. Su salida se emite **verbatim**: este guion no la interpreta, y por
 * eso no puede equivocarse al interpretarla.
 */
function bloqueDelegado(bool $csv, string $colegio, string $bloque, string $herramienta, array $flags): void
{
    $ruta = __DIR__.'/'.$herramienta;

    if (! is_file($ruta)) {
        emitir($csv, $colegio, $bloque, 'herramienta', 'AUSENTE', $herramienta);

        return;
    }

    if (! $csv) {
        echo "\n-- {$bloque}: ".$herramienta." (delegado)\n";
    }

    $orden = 'DB_DATABASE='.escapeshellarg($colegio).' '.escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($ruta).' '.implode(' ', array_map('escapeshellarg', $flags)).' 2>&1';

    exec($orden, $salida, $codigo);

    emitir($csv, $colegio, $bloque, 'salida de la herramienta', $codigo === 0 ? 'OK' : "FALLO({$codigo})",
        $herramienta === 'salud-de-las-definitivas.php'
            ? 'crea y borra una TEMPORARY de su propia sesion: escribe, pero nunca en los datos'
            : 'solo lectura');

    if (expandirCsvDelegado($csv, $colegio, $bloque, $salida)) {
        return;
    }

    foreach ($salida as $i => $linea) {
        if (trim($linea) === '') {
            continue;
        }

        emitir($csv, $colegio, $bloque, 'linea '.($i + 1), trim($linea));
    }
}

/**
 * Convierte el CSV de una herramienta delegada en filas `clave,valor` de este CSV.
 *
 * ## Por qué esto NO es «interpretar su medición», que es lo que dije que no haría
 *
 * Hay dos cosas distintas y la diferencia es todo: **retéclear sus consultas** sería
 * una segunda medición que puede discrepar; **leer el CSV que ellas publican** es
 * usar el formato que existe para eso. Sus números pasan por aquí **sin tocarse**:
 * lo único que se hace es emparejar cabecera con fila.
 *
 * Sin esto, su CSV viaja **dentro de una celda del mío** —«linea 1» con veintiuna
 * columnas dentro—, y entonces juntar dieciséis colegios ya no es `cat`: es `cat` y
 * después partir celdas a mano, que es justo lo que este guion viene a quitar.
 *
 * ## Y no supone la forma: la comprueba
 *
 * Sólo expande si hay **exactamente dos líneas con datos** y **la cabecera y la
 * fila tienen el mismo número de campos**. Si la herramienta cambia de forma
 * mañana, esto **no adivina**: devuelve `false` y su salida sale verbatim, línea a
 * línea, como antes. Degrada, no rompe — y el CSV lo dice, porque las filas pasan
 * a llamarse «linea N» en vez de por su nombre.
 *
 * @param  list<string>  $salida
 */
function expandirCsvDelegado(bool $csv, string $colegio, string $bloque, array $salida): bool
{
    $lineas = array_values(array_filter(array_map('trim', $salida), static fn (string $l): bool => $l !== ''));

    if (count($lineas) !== 2) {
        return false;
    }

    $cabecera = explode(',', $lineas[0]);
    $valores = explode(',', $lineas[1]);

    if (count($cabecera) !== count($valores) || count($cabecera) < 2) {
        return false;
    }

    // Una cabecera de CSV no lleva espacios ni acentos; si los lleva, esto es
    // texto y no una cabecera, y expandirlo sería inventarse columnas.
    foreach ($cabecera as $campo) {
        if (! preg_match('/^[a-z0-9_]+$/', $campo)) {
            return false;
        }
    }

    foreach ($cabecera as $i => $campo) {
        emitir($csv, $colegio, $bloque, $campo, $valores[$i]);
    }

    return true;
}
