<?php

/**
 * Cambia los imports por alias de raíz por el nombre completo.
 *
 *     use DB;   ->   use Illuminate\Support\Facades\DB;
 *
 * Las llamadas no se tocan: siguen siendo `DB::select()`. Lo único que cambia
 * es de dónde sale el nombre — del import del fichero en vez del `class_alias`
 * global que registra el array `aliases` de config/app.php.
 *
 *     php tools/imports-de-facades.php --dry-run
 *     php tools/imports-de-facades.php
 *
 * Quien vigila que no vuelva es tests/Unit/AliasDeFacadesTest.php.
 *
 * Por qué esto y no Rector, que está configurado para el mismo trabajo: el set
 * LARAVEL_FACADE_ALIASES_TO_FULL_NAMES escribe el nombre completo en cada
 * llamada, y aquí hay 990 `DB::`. Con `withImportNames()` sí pone el import,
 * pero de paso colapsa a import cualquier otro nombre completo que encuentre,
 * y tocaba diez ficheros que no tenían este problema. Este script cambia una
 * línea por import y ninguna más: 293 cambios en 141 ficheros, 293 líneas de
 * diff.
 *
 * El mapa sale de config/app.php, no de una lista escrita aquí: si mañana
 * alguien añade un alias, esto lo sabe.
 */

require __DIR__.'/../vendor/autoload.php';

$raiz = dirname(__DIR__);
$mapa = (require $raiz.'/config/app.php')['aliases'] ?? [];
$seco = in_array('--dry-run', $argv, true);

$ficheros = [];

foreach (['app', 'config', 'database', 'routes', 'tests', 'tools'] as $carpeta) {
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz.'/'.$carpeta, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterador as $fichero) {
        if ($fichero->getExtension() === 'php') {
            $ficheros[] = $fichero->getPathname();
        }
    }
}

sort($ficheros);

$cambios = 0;
$tocados = 0;
$conAs = [];

foreach ($ficheros as $ruta) {
    $original = $src = file_get_contents($ruta);

    foreach ($mapa as $alias => $destino) {
        $destino = ltrim($destino, '\\');
        $corto = substr($destino, strrpos($destino, '\\') + 1);

        // Si el nombre corto del destino no es el alias, el import a secas
        // cambiaría el nombre local y rompería las llamadas. `Browser` apunta a
        // hisorange\BrowserDetect\Facade, y `Eloquent` a Eloquent\Model.
        $importe = $corto === $alias ? "use $destino;" : "use $destino as $alias;";

        if ($corto !== $alias) {
            $conAs[$alias] = $importe;
        }

        $src = preg_replace(
            '/^([ \t]*)use[ \t]+'.preg_quote($alias, '/').'[ \t]*;/m',
            '$1'.str_replace('$', '\$', $importe),
            $src,
            -1,
            $encontrados
        );

        $cambios += $encontrados;

        // Un fichero que ya tuviera el import completo se queda con dos.
        if ($encontrados > 0) {
            $lineas = explode("\n", $src);
            $vistas = [];

            foreach ($lineas as $i => $linea) {
                if (! preg_match('/^[ \t]*use[ \t]+[^;]+;[ \t]*$/', $linea)) {
                    continue;
                }

                $clave = preg_replace('/\s+/', ' ', trim($linea));

                if (isset($vistas[$clave])) {
                    unset($lineas[$i]);

                    continue;
                }

                $vistas[$clave] = true;
            }

            $src = implode("\n", $lineas);
        }
    }

    if ($src === $original) {
        continue;
    }

    $tocados++;

    if (! $seco) {
        file_put_contents($ruta, $src);
    }
}

// **Se dice sobre cuántos ficheros, no sólo cuántos cambiaron.** «0 imports
// cambiados en 0 ficheros» no distingue «revisé 293 y ninguno lo necesitaba» de
// «no revisé nada» —un iterador que no encuentra la carpeta da exactamente lo
// mismo—, y la primera lectura de las dos es la que hace archivar el asunto.
//
// Es la regla que propuso `myvc-front-12` el 24 ago 2026 después de que su
// detector de textos afirmara cubrir 385 llamadas de 411: **que ninguna
// herramienta imprima OK sin decir su población.**
echo ($seco ? '[dry-run] ' : '').
    "$cambios imports cambiados en $tocados ficheros, de ".count($ficheros)." revisados\n";

foreach ($conAs as $alias => $importe) {
    echo "  $alias conserva su nombre local:  $importe\n";
}
