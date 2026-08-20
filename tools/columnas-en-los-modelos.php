<?php

/**
 * Escribe las columnas reales de cada tabla en el docblock de su modelo.
 *
 * **Existe por una consecuencia de la Fase 5.** El esquema de este proyecto no
 * está en migraciones —90 tablas contra 3 ficheros—, sino congelado en
 * `database/schema/mysql-schema.sql`. Larastan no puede leer eso, así que para
 * el análisis un modelo Eloquent no tiene ninguna columna: al subir al nivel 2
 * salen 144 «propiedad no definida» que son todas columnas que existen.
 *
 * La lista NO se escribe a mano. Se genera desde el esquema real, que es la
 * misma decisión que ya se tomó en `App\Support\ColumnaSegura`: una lista a mano
 * se queda corta el día que alguien añada un campo, y entonces el análisis
 * empieza a mentir en la dirección peligrosa.
 *
 * Uso:
 *   php tools/columnas-en-los-modelos.php            # dice qué haría
 *   php tools/columnas-en-los-modelos.php --escribir # lo escribe
 *
 * Solo toca el bloque delimitado por las marcas, así que se puede volver a
 * correr cuando cambie el esquema y no pisa nada escrito por una persona.
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Str;

const MARCA_INICIO = ' * --- columnas de la tabla, generadas por tools/columnas-en-los-modelos.php ---';
const MARCA_FIN = ' * --- fin de las columnas generadas ---';

$escribir = in_array('--escribir', $argv, true);

$esquema = file_get_contents(__DIR__.'/../database/schema/mysql-schema.sql');

preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $esquema, $tablas, PREG_SET_ORDER);

$columnasPorTabla = [];
foreach ($tablas as [, $tabla, $cuerpo]) {
    // Se captura la línea entera, no solo el tipo: el resto es donde vive el
    // `NOT NULL`, y sin él la anotación miente en la dirección peligrosa —dice
    // `int` de una columna que devuelve null y el análisis deja de avisar de
    // los sitios que no lo contemplan.
    preg_match_all('/^  `(\w+)` (\w+)([^\n]*)/m', $cuerpo, $cols, PREG_SET_ORDER);
    foreach ($cols as [, $columna, $tipo, $resto]) {
        $columnasPorTabla[$tabla][$columna] = tipoPhp($tipo, str_contains($resto, 'NOT NULL'));
    }
}

/**
 * El tipo de PHP que va a devolver Eloquent para esa columna de MySQL.
 *
 * En MySQL una columna admite NULL salvo que diga lo contrario, así que la
 * pregunta es al revés: nulable es lo normal, `NOT NULL` es la excepción.
 */
function tipoPhp(string $tipoSql, bool $obligatoria): string
{
    $tipoSql = strtolower($tipoSql);
    $prefijo = $obligatoria ? '' : '?';

    if (str_contains($tipoSql, 'int')) {
        return $prefijo.'int';
    }
    if (in_array($tipoSql, ['decimal', 'float', 'double'], true)) {
        return $prefijo.'float';
    }

    return $prefijo.'string';
}

$tocados = $saltados = 0;

// `App\User` no vive en app/Models: se quedó en la raíz desde Laravel 5, y es
// justo el modelo del que más columnas se leen en todo el proyecto.
$modelos = array_merge(glob(__DIR__.'/../app/Models/*.php'), [__DIR__.'/../app/User.php']);

foreach ($modelos as $fichero) {
    $fuente = file_get_contents($fichero);
    $clase = basename($fichero, '.php');

    $tabla = tablaDe($fuente, $clase, $columnasPorTabla);

    if ($tabla === null) {
        echo "  ? {$clase}: no se encontró su tabla en el esquema\n";
        $saltados++;

        continue;
    }

    $nuevo = conBloque($fuente, $clase, $tabla, $columnasPorTabla[$tabla]);

    if ($nuevo === $fuente) {
        continue;
    }

    $tocados++;
    echo '  ✎ '.str_pad($clase, 26).' → '.$tabla.' ('.count($columnasPorTabla[$tabla])." columnas)\n";

    if ($escribir) {
        file_put_contents($fichero, $nuevo);
    }
}

echo "\n{$tocados} modelos".($escribir ? ' escritos' : ' cambiarían')."; {$saltados} sin tabla.\n";

if (! $escribir && $tocados) {
    echo "Nada se ha tocado. Repite con --escribir.\n";
}

/**
 * La tabla de un modelo: la que declara, o la que sale de la convención.
 *
 * No se adivina: si el nombre deducido no existe en el esquema, se salta y se
 * dice. Un modelo anotado con las columnas de otra tabla sería peor que uno sin
 * anotar.
 */
function tablaDe(string $fuente, string $clase, array $columnasPorTabla): ?string
{
    if (preg_match('/protected\s+\$table\s*=\s*[\'"](\w+)[\'"]/', $fuente, $m)) {
        return isset($columnasPorTabla[$m[1]]) ? $m[1] : null;
    }

    $candidatos = [
        Str::snake(Str::pluralStudly($clase)),
        Str::snake($clase),
    ];

    foreach ($candidatos as $candidato) {
        if (isset($columnasPorTabla[$candidato])) {
            return $candidato;
        }
    }

    return null;
}

/** Mete o reemplaza el bloque generado en el docblock de la clase. */
function conBloque(string $fuente, string $clase, string $tabla, array $columnas): string
{
    $lineas = [MARCA_INICIO, ' *'];

    foreach ($columnas as $columna => $tipo) {
        $lineas[] = ' * @property '.$tipo.' $'.$columna;
    }

    $lineas[] = MARCA_FIN;
    $bloque = implode("\n", $lineas);

    // Ya estaba: se reemplaza entre marcas y no se toca nada más.
    if (str_contains($fuente, MARCA_INICIO)) {
        return preg_replace(
            '/'.preg_quote(MARCA_INICIO, '/').'.*?'.preg_quote(MARCA_FIN, '/').'/s',
            $bloque,
            $fuente
        );
    }

    $declaracion = '/^(\s*(?:final\s+|abstract\s+)?class\s+'.preg_quote($clase, '/').'\b)/m';

    if (! preg_match($declaracion, $fuente)) {
        return $fuente;
    }

    $docblock = "/**\n * Las columnas de `{$tabla}`, tal como están en el esquema congelado.\n *\n".
        " * Generado desde database/schema/mysql-schema.sql — no se edita a mano.\n * Ver tools/columnas-en-los-modelos.php.\n *\n".
        $bloque."\n */\n";

    return preg_replace($declaracion, $docblock.'$1', $fuente, 1);
}
