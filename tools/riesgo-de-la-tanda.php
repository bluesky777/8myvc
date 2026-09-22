#!/usr/bin/env php
<?php

/*
 * **Qué se va a perder si corro `migrate` en ESTE colegio.** El informe va antes de
 * tocar nada, y sale de la base de verdad del colegio, no del repositorio.
 *
 *     php tools/riesgo-de-la-tanda.php                 # el colegio del `.env` de esta carpeta
 *     php tools/riesgo-de-la-tanda.php --base=simonbolivar
 *     php tools/riesgo-de-la-tanda.php --callado       # sólo lo que tiene algo que perder
 *     php tools/riesgo-de-la-tanda.php --todas         # también las migraciones ya corridas
 *
 * Los dieciséis, desde el host de cPanel, uno por carpeta y con su propio `.env`
 * —que es la forma que no supone que una credencial alcance las bases de todos,
 * exactamente por lo que explica `tools/fase-cero-de-los-dieciseis.php`—:
 *
 *     for d in /home/micolev1/*.micolevirtual.com/8myvc; do
 *         printf '\n===== %s\n' "$d"
 *         ( cd "$d" && php tools/riesgo-de-la-tanda.php --callado )
 *     done            # repetir en la otra cuenta de cPanel (lalvirtual.edu.co)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE — el despliegue del domingo 20 sep 2026
 *
 * Esa noche entró `2026_09_19_500000_la_casilla_vacia`, que pone a `NULL` toda nota
 * sembrada que nadie hubiera tocado. Está decidida, documentada y es correcta. Y aun
 * así **Joseth se enteró de que había reescrito notas cuando ya estaban reescritas**:
 * la migración imprime su cifra —`notas vaciadas: N`— *mientras* las vacía, y en ese
 * momento ya no hay decisión que tomar. Su propio `down()` lo dice con todas las
 * letras: *«revertir esto no restaura el estado anterior, lo aproxima»*.
 *
 * El fallo no fue de esa migración. Fue que **el despliegue no tenía ningún momento
 * en el que alguien viera, por colegio y con un número delante, qué filas que ya
 * existen van a cambiar de valor.** El plan de la tanda vive en `docs/DESPLIEGUE.md`
 * y lo escribe quien la programa; una cifra de filas sólo la sabe la base de cada
 * colegio, y son dieciséis bases distintas.
 *
 * Esto es ese momento. Se corre **antes** de `migrate`, contesta *«en esta base, esta
 * tanda reescribe N filas que ya existen y el `down()` no las devuelve»*, y sale con
 * código **2** para que un despliegue encadenado con `&&` se pare solo.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE ES, Y LO QUE NO ES
 *
 * **Es un detector, no un juez.** Marcar `ROJO` no quiere decir que la migración esté
 * mal: `la_casilla_vacia` es ROJO y era exactamente lo que había que hacer. Quiere
 * decir *«esto cambia filas que ya existen: mira el número y decide antes, no
 * después»*.
 *
 * **No mira el `down()` para consolarse.** Un `down()` que escribe un literal —`SET
 * nota = 0`— no restaura: aproxima. Cuando lo detecta lo dice, porque es la
 * diferencia entre «se puede volver atrás» y «hay que restaurar el respaldo».
 *
 * **No sustituye al respaldo, lo exige.** Lo único que devuelve una fila a su valor
 * de ayer es un `mysqldump` de ayer. Por eso la última línea del informe en rojo es
 * el comando de `tools/respaldo-antes-de-migrar.sh` y no un consejo.
 *
 * **No abre la boca sobre lo aditivo.** Una columna nueva con `DEFAULT`, una tabla
 * nueva: eso es VERDE y se lista en una línea. El ruido es lo que hace que un informe
 * de riesgo deje de leerse.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CÓMO MIDE, Y DÓNDE SE LE VE EL BORDE
 *
 * Lee el fichero de cada migración pendiente **con el tokenizador de PHP**, no con
 * `grep`. No es esmero: los ficheros de este repositorio llevan cabeceras de sesenta
 * líneas en las que aparecen escritas las palabras `UPDATE`, `DELETE` y `dropColumn`
 * un montón de veces. Un `grep` daría ROJO en todas y el informe no valdría nada.
 * Los comentarios se tiran antes de mirar; sólo se conserva `// CENSO: <sql>`, que es
 * cómo el autor declara a mano una cuenta que esto no sabe deducir.
 *
 * De cada operación peligrosa deduce **la consulta que la cuenta sin ejecutarla**:
 *
 *     UPDATE notas n JOIN ... SET n.nota = NULL WHERE X
 *       ->  SELECT COUNT(*) FROM notas n JOIN ... WHERE X
 *
 * y la corre dentro de `START TRANSACTION READ ONLY`, que es lo que garantiza —del
 * lado del servidor, no del lado de la buena intención— que un informe de riesgo no
 * pueda causar el daño que va a informar.
 *
 * **Dónde NO sabe deducir la cuenta:** en las escrituras de Eloquent y del query
 * builder (`DB::table('x')->update([...])`), porque el `WHERE` está repartido en
 * llamadas encadenadas y con variables de PHP dentro. Ahí no adivina: marca **ROJO
 * SIN CENSO**, sale con 2 igual, y la cuenta la escribe el autor con `// CENSO:`.
 * Una herramienta que se inventara un número ahí sería peor que no tenerla.
 *
 * **La otra frontera, y es la de verdad:** esto cuenta **filas que la tanda toca**, no
 * filas que el código nuevo vaya a estropear después. Que `putUpdate` empiece a
 * escribir `NULL` en cuanto la columna admita nulos —el último párrafo de la cabecera
 * de `la_casilla_vacia`— no lo ve esto ni lo puede ver: eso es una lectura de código,
 * y vive en `docs/DESPLIEGUE.md`.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const ROJO  = 'ROJO';
const AMBAR = 'ÁMBAR';
const VERDE = 'VERDE';

$base = null;
$soloPendientes = true;
$callado = false;

foreach (array_slice($argv, 1) as $argumento) {
    if (str_starts_with($argumento, '--base=')) {
        $base = substr($argumento, 7);
    } elseif ($argumento === '--todas') {
        $soloPendientes = false;
    } elseif ($argumento === '--callado') {
        $callado = true;
    } elseif ($argumento === '--ayuda' || $argumento === '-h') {
        echo "uso: php tools/riesgo-de-la-tanda.php [--base=NOMBRE] [--callado] [--todas]\n";
        exit(0);
    } else {
        fwrite(STDERR, "opción desconocida: {$argumento}\n");
        exit(1);
    }
}

if ($base !== null) {
    config(['database.connections.mysql.database' => $base]);
    DB::purge('mysql');
    DB::reconnect('mysql');
}

// El `SELECT 1` fuerza la conexión de verdad: sin él, una base que no existe no se
// nota hasta la primera cuenta, y el fallo sale a mitad del informe.
DB::select('SELECT 1');
$base = DB::connection()->getDatabaseName();

// Sólo lectura **del lado del servidor**. Lo que sigue no puede escribir aunque el
// código de abajo se equivoque. Es el mismo guard de `fase-cero-de-los-dieciseis.php`
// y aquí pesa el doble: esto se corre sobre la base viva de un colegio.
DB::getPdo()->exec('START TRANSACTION READ ONLY');

$directorio = __DIR__.'/../database/migrations';
$ficheros = glob($directorio.'/*.php');
sort($ficheros);

$corridas = [];
try {
    $corridas = DB::table('migrations')->pluck('migration')->all();
} catch (Throwable $e) {
    // Una base sin tabla `migrations` es una base virgen: está todo pendiente.
}
$corridas = array_flip($corridas);

$pendientes = [];
foreach ($ficheros as $fichero) {
    $nombre = basename($fichero, '.php');
    if ($soloPendientes && isset($corridas[$nombre])) {
        continue;
    }
    $pendientes[$nombre] = $fichero;
}

echo "\n";
echo "RIESGO DE LA TANDA — base `{$base}` · ".count($pendientes)." migraciones "
    .($soloPendientes ? 'pendientes' : 'en total')." · ".date('Y-m-d H:i')."\n";
echo str_repeat('─', 78)."\n";

if ($pendientes === []) {
    echo "\n  No hay nada pendiente en esta base.\n\n";
    exit(0);
}

$filasEnRiesgo = 0;
$tablasEnRiesgo = [];
$sinCenso = 0;
$rojas = 0;

foreach ($pendientes as $nombre => $fichero) {
    $hechos = analizarLaMigracion($fichero);
    $veredicto = veredictoDe($hechos);

    if ($callado && $veredicto === VERDE) {
        continue;
    }

    echo "\n  ".str_pad($veredicto, 6)." {$nombre}\n";

    foreach ($hechos as $hecho) {
        $cuenta = null;
        $nota = '';

        if ($hecho['censo'] !== null) {
            [$cuenta, $nota] = contar($hecho['censo']);
            if ($cuenta !== null && $cuenta > 0 && $hecho['color'] === ROJO) {
                $filasEnRiesgo += $cuenta;
                $tablasEnRiesgo[$hecho['tabla']] = true;
            }
        } elseif ($hecho['color'] === ROJO) {
            $nota = 'SIN CENSO — hay que contarlo a mano';
        }

        if ($cuenta === null && $hecho['color'] === ROJO && ! esUnaTablaQueNoExiste($nota)) {
            $sinCenso++;
        }

        $izquierda = '        '.$hecho['que'].' ';
        $derecha = $cuenta !== null
            ? number_format($cuenta, 0, ',', '.').' filas'.($nota !== '' ? "  ({$nota})" : '')
            : $nota;

        echo str_pad($izquierda, 56, '.').' '.$derecha."\n";
    }

    if ($veredicto === ROJO) {
        $rojas++;
        $aviso = miraElDown($fichero);
        if ($aviso !== null) {
            echo "        └─ {$aviso}\n";
        }
    }
}

echo "\n".str_repeat('─', 78)."\n";

if ($filasEnRiesgo === 0 && $sinCenso === 0) {
    echo "\n  Nada que perder en `{$base}`: la tanda no toca ninguna fila que ya exista.\n\n";
    exit(0);
}

echo "\n";
if ($filasEnRiesgo > 0) {
    echo "  EN RIESGO EN `{$base}`: ".number_format($filasEnRiesgo, 0, ',', '.')
        ." filas que ya existen, en ".implode(', ', array_map(
            fn ($t) => "`{$t}`", array_keys($tablasEnRiesgo)
        ))."\n";
}
if ($sinCenso > 0) {
    echo "  SIN MEDIR: {$sinCenso} operación(es) que esto no sabe contar. Míralas antes.\n";
}

echo "\n  ANTES DE MIGRAR, y en esta carpeta:\n\n";
echo "      tools/respaldo-antes-de-migrar.sh\n\n";
echo "  Sin ese respaldo no hay vuelta atrás para estas filas: un `down()` deshace el\n";
echo "  esquema, nunca el valor que había en una casilla.\n\n";

exit(2);

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Lee la migración y devuelve **hechos**: qué le hace a qué tabla, de qué color, y
 * con qué consulta se cuenta antes de que pase.
 */
function analizarLaMigracion(string $fichero): array
{
    $fuente = file_get_contents($fichero);
    $tokens = token_get_all($fuente);

    $censosDeclarados = [];
    foreach ($tokens as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
            && preg_match('/CENSO:\s*(.+?)\s*(?:\*\/)?$/m', $token[1], $coincide)) {
            $censosDeclarados[] = trim($coincide[1]);
        }
    }

    $cuerpo = cuerpoDeLaFuncion($tokens, 'up');
    $hechos = [];

    // 1. SQL crudo. Se miran los literales de cadena uno a uno, no el texto entero:
    //    así una variable de PHP con la palabra `UPDATE` dentro no cuenta.
    foreach (literales($cuerpo) as $sql) {
        $sql = trim(preg_replace('/\s+/', ' ', $sql));

        if (preg_match('/^UPDATE\s+(.+?)\s+SET\s+(.*?)(?:\s+WHERE\s+(.*))?$/i', $sql, $m)) {
            $donde = $m[3] ?? '1 = 1';
            $hechos[] = hecho(ROJO, primeraTabla($m[1]),
                'UPDATE sobre `'.primeraTabla($m[1]).'`',
                "SELECT COUNT(*) AS n FROM {$m[1]} WHERE {$donde}");
            continue;
        }

        if (preg_match('/^DELETE\s+FROM\s+(\S+)(?:\s+WHERE\s+(.*))?$/i', $sql, $m)) {
            $donde = $m[2] ?? '1 = 1';
            $hechos[] = hecho(ROJO, limpiar($m[1]),
                'DELETE en `'.limpiar($m[1]).'`',
                "SELECT COUNT(*) AS n FROM {$m[1]} WHERE {$donde}");
            continue;
        }

        if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?(\S+)/i', $sql, $m)) {
            $hechos[] = hecho(ROJO, limpiar($m[1]), 'TRUNCATE de `'.limpiar($m[1]).'`',
                "SELECT COUNT(*) AS n FROM {$m[1]}");
            continue;
        }

        if (preg_match('/^ALTER\s+TABLE\s+(\S+)\s+(.*)$/i', $sql, $m)) {
            $tabla = limpiar($m[1]);
            $resto = $m[2];

            if (preg_match('/(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?`?(\w+)`?.*\bNOT\s+NULL\b/i', $resto, $c)) {
                $hechos[] = hecho(ROJO, $tabla,
                    "ALTER `{$tabla}`.`{$c[1]}` pasa a NOT NULL",
                    "SELECT COUNT(*) AS n FROM {$tabla} WHERE {$c[1]} IS NULL");
            } elseif (preg_match('/DROP\s+(?:COLUMN\s+)?`?(\w+)`?/i', $resto, $c)
                && !preg_match('/DROP\s+(?:CONSTRAINT|INDEX|KEY|FOREIGN)/i', $resto)) {
                $hechos[] = hecho(ROJO, $tabla,
                    "ALTER quita `{$tabla}`.`{$c[1]}`",
                    "SELECT COUNT(*) AS n FROM {$tabla} WHERE {$c[1]} IS NOT NULL");
            } else {
                // Reconstruye la tabla o cambia su forma sin perder valores. No es
                // pérdida de datos; es tiempo de bloqueo, que en una tabla de un
                // millón de filas y con MariaDB detrás tampoco es gratis.
                $hechos[] = hecho(AMBAR, $tabla, "ALTER sobre `{$tabla}`",
                    "SELECT COUNT(*) AS n FROM {$tabla}");
            }
            continue;
        }

        if (preg_match('/^DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+(\S+)/i', $sql, $m)) {
            $hechos[] = hecho(ROJO, limpiar($m[1]), 'DROP TABLE `'.limpiar($m[1]).'`',
                "SELECT COUNT(*) AS n FROM {$m[1]}");
        }
    }

    // 2. El constructor de esquema. `Schema::create` NO entra: una tabla que nace
    //    vacía no tiene nada que perder.
    if (preg_match_all('/Schema::dropIfExists\(\s*[\'"](\w+)[\'"]/', $cuerpo, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            $hechos[] = hecho(ROJO, $c[1], "Schema::dropIfExists `{$c[1]}`",
                "SELECT COUNT(*) AS n FROM {$c[1]}");
        }
    }

    foreach (bloquesDeTabla($cuerpo) as [$tabla, $bloque]) {
        foreach (explode(';', $bloque) as $sentencia) {
            if (preg_match('/dropColumn\(\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $sentencia, $c)) {
                foreach (nombresDe($c[1]) as $columna) {
                    $hechos[] = hecho(ROJO, $tabla, "dropColumn `{$tabla}`.`{$columna}`",
                        "SELECT COUNT(*) AS n FROM {$tabla} WHERE {$columna} IS NOT NULL");
                }
                continue;
            }

            if (preg_match('/renameColumn\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"](\w+)[\'"]/', $sentencia, $c)) {
                // No se pierde un valor, pero el código viejo deja de encontrar la
                // columna en el mismo segundo — que es el modo de fallo de
                // `tools/esquema-nuevo-codigo-viejo.sh`.
                $hechos[] = hecho(AMBAR, $tabla,
                    "renameColumn `{$c[1]}` → `{$c[2]}` en `{$tabla}`", null);
                continue;
            }

            if (preg_match('/->change\(\)/', $sentencia)) {
                $hechos[] = hecho(AMBAR, $tabla, "->change() sobre `{$tabla}`", null);
                continue;
            }

            if (preg_match('/->unique\(\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $sentencia, $c)) {
                $columnas = implode(', ', nombresDe($c[1]));
                // Un índice único sobre una tabla con duplicados **no avisa: falla**,
                // y deja la tanda a medias en ese colegio.
                $hechos[] = hecho(ROJO, $tabla,
                    "UNIQUE({$columnas}) en `{$tabla}` — duplicados",
                    "SELECT COUNT(*) AS n FROM (SELECT 1 FROM {$tabla} "
                    ."GROUP BY {$columnas} HAVING COUNT(*) > 1) AS duplicados");
                continue;
            }

            if (preg_match('/->(string|char|text|integer|bigInteger|unsignedBigInteger|tinyInteger'
                .'|smallInteger|boolean|date|dateTime|timestamp|decimal|float|double|enum|json'
                .'|foreignId|year|time)\(\s*[\'"](\w+)[\'"]/', $sentencia, $c)
                && !str_contains($sentencia, '->nullable(')
                && !str_contains($sentencia, '->default(')) {
                // Columna NOT NULL sin defecto sobre una tabla que ya tiene filas: o
                // la migración falla, o MariaDB rellena con un valor que no eligió
                // nadie. En una tabla vacía no pasa nada, y por eso se cuenta.
                $hechos[] = hecho(AMBAR, $tabla,
                    "columna nueva `{$c[2]}` NOT NULL y sin defecto",
                    "SELECT COUNT(*) AS n FROM {$tabla}");
            }
        }
    }

    // 3. Escrituras del query builder. Aquí no se deduce nada: se declara.
    if (preg_match_all('/DB::table\(\s*[\'"](\w+)[\'"]\s*\)((?:(?!;).)*)/s', $cuerpo, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            if (preg_match('/->(update|delete|truncate|insertOrIgnore|upsert)\(/', $c[2], $q)) {
                $hechos[] = hecho(ROJO, $c[1],
                    "DB::table('{$c[1]}')->{$q[1]}()", array_shift($censosDeclarados));
            }
        }
    }

    // Un `// CENSO:` que sobre no se tira: es una cuenta que el autor quiso ver.
    foreach ($censosDeclarados as $censo) {
        $hechos[] = hecho(ROJO, 'declarado', 'censo declarado a mano', $censo);
    }

    return $hechos;
}

/**
 * Una tabla que todavía no existe **no es una medición que falte**: la crea otra
 * migración de esta misma tanda, y en ese momento nace vacía. Distinguirlo importa
 * porque «sin medir» es lo que hace parar el despliegue.
 */
function esUnaTablaQueNoExiste(string $nota): bool
{
    return str_contains($nota, 'todavía no existe');
}

function hecho(string $color, string $tabla, string $que, ?string $censo): array
{
    return ['color' => $color, 'tabla' => $tabla, 'que' => $que, 'censo' => $censo];
}

function veredictoDe(array $hechos): string
{
    foreach ([ROJO, AMBAR] as $color) {
        foreach ($hechos as $hecho) {
            if ($hecho['color'] === $color) {
                return $color;
            }
        }
    }

    return VERDE;
}

/**
 * Corre la cuenta. Devuelve `[null, 'por qué no se pudo']` cuando la tabla todavía no
 * existe en esta base — que es lo normal si la crea otra migración de la misma tanda.
 */
function contar(string $sql): array
{
    if (preg_match('/\?|(?<![:\w]):\w+/', $sql)) {
        // La consulta venía con marcadores (`WHERE id = ?`) porque la migración la
        // corre en un bucle con valores de PHP. Contarla exigiría ejecutar el bucle.
        return [null, 'la consulta lleva marcadores — cuéntalo a mano'];
    }

    try {
        $fila = DB::selectOne($sql);

        return [(int) ($fila->n ?? 0), ''];
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        if (str_contains($mensaje, "doesn't exist") || str_contains($mensaje, 'Unknown column')) {
            return [null, 'la tabla o columna todavía no existe aquí'];
        }

        return [null, 'no se pudo contar: '.substr(strtok($mensaje, "\n"), 0, 60)];
    }
}

/**
 * ¿El `down()` devuelve las filas a donde estaban? Si escribe un literal, no: las
 * aproxima. Es la diferencia entre revertir y restaurar, y va dicha en el informe
 * porque es lo que decide si hace falta el respaldo o basta con el `rollback`.
 */
function miraElDown(string $fichero): ?string
{
    $cuerpo = cuerpoDeLaFuncion(token_get_all(file_get_contents($fichero)), 'down');

    if (trim($cuerpo) === '') {
        return 'el down() está vacío: esta migración no se puede revertir';
    }

    foreach (literales($cuerpo) as $sql) {
        if (preg_match('/^\s*UPDATE\b/i', $sql)) {
            return 'el down() reescribe filas con un valor fijo: NO devuelve el valor anterior';
        }
    }

    return null;
}

/** El cuerpo de `up()` o de `down()`, sin comentarios y con las llaves equilibradas. */
function cuerpoDeLaFuncion(array $tokens, string $nombre): string
{
    $indice = null;
    for ($i = 0; $i < count($tokens); $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $nombre) {
                    $indice = $j;
                }
                break;
            }
        }
        if ($indice !== null) {
            break;
        }
    }

    if ($indice === null) {
        return '';
    }

    $nivel = 0;
    $dentro = false;
    $texto = '';

    for ($i = $indice; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $trozo = is_array($token) ? $token[1] : $token;

        if ($trozo === '{'
            || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $nivel++;
            if (! $dentro) {
                $dentro = true;
                continue;
            }
        } elseif ($trozo === '}') {
            $nivel--;
            if ($nivel === 0) {
                break;
            }
        }

        if ($dentro) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $texto .= $trozo;
        }
    }

    return $texto;
}

/** Las cadenas literales de un trozo de código, ya desentrecomilladas. */
function literales(string $codigo): array
{
    $encontradas = [];
    foreach (token_get_all('<?php '.$codigo) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $encontradas[] = substr($token[1], 1, -1);
        }
    }

    return $encontradas;
}

/**
 * Los bloques `Schema::table('x', function (...) { ... })`, con su tabla.
 *
 * El emparejamiento es por posición —cada `dropColumn` pertenece al `Schema::table`
 * que lo precede— y es una heurística, no un análisis. Aguanta la forma que usan las
 * migraciones de este repositorio; con un `Schema::table` construido a base de
 * variables no acertaría, y entonces la operación se cuela como no vista.
 */
function bloquesDeTabla(string $cuerpo): array
{
    $bloques = [];
    // El `?: []` no es defensa de adorno: `preg_split` devuelve `false` si el patrón
    // falla, y sin eso el bucle de abajo indexa un booleano.
    $trozos = preg_split('/Schema::table\(\s*[\'"](\w+)[\'"]/', $cuerpo, -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

    for ($i = 0; $i < count($trozos) - 1; $i++) {
        if (preg_match('/^\w+$/', $trozos[$i]) && isset($trozos[$i + 1])) {
            $bloques[] = [$trozos[$i], $trozos[$i + 1]];
        }
    }

    return $bloques;
}

/** `['a', 'b']` o `'a'` -> `['a', 'b']`. */
function nombresDe(string $literal): array
{
    preg_match_all('/[\'"](\w+)[\'"]/', $literal, $m);

    return $m[1];
}

function limpiar(string $identificador): string
{
    return trim($identificador, '`"\'');
}

function primeraTabla(string $desde): string
{
    return limpiar(strtok(trim($desde), " \t\n"));
}
