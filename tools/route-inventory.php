<?php

/**
 * Inventario de rutas implícitas de AdvancedRoute.
 *
 * Reproduce, por reflexión, la tabla de rutas que `AdvancedRoute::controller()`
 * genera hoy en routes/api.php. No arranca Laravel (los constructores de los
 * controladores llaman a User::fromToken() y abortan sin token, que es la razón
 * por la que `php artisan route:list` está roto).
 *
 * Uso:
 *   php tools/route-inventory.php                  # imprime resumen
 *   php tools/route-inventory.php ruta/salida.csv  # además escribe el CSV
 *
 * Sirve para dos cosas:
 *   1. Generar el archivo de rutas explícitas que reemplaza a AdvancedRoute.
 *   2. Comparar la tabla de rutas antes/después de la migración (debe ser idéntica).
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Str;

const HTTP_METHODS = ['any', 'get', 'post', 'put', 'patch', 'delete'];

$prefixPattern = '/^(' . implode('|', HTTP_METHODS) . ')/';

/**
 * Misma lógica que AdvancedRoute::slug(): quita el prefijo del verbo, pasa a
 * kebab-case y añade un segmento {param} por cada argumento sin type-hint.
 */
function slugFor(ReflectionMethod $method, string $prefixPattern): string
{
    $slug = Str::slug(Str::snake(preg_replace($prefixPattern, '', $method->name), ' '), '-');

    if ($slug === 'index') {
        $slug = '';
    }

    foreach ($method->getParameters() as $parameter) {
        if ($parameter->hasType()) {
            continue; // Request, etc. -> inyección, no segmento de URI
        }
        $slug .= sprintf(
            '/{%s%s}',
            strtolower($parameter->getName()),
            $parameter->isDefaultValueAvailable() ? '?' : ''
        );
    }

    return ltrim($slug, '/');
}

$source = file_get_contents(__DIR__ . '/../routes/api.php');

// Solo líneas activas: descarta las comentadas con // o #
$active = implode("\n", array_filter(
    explode("\n", $source),
    fn ($line) => ! preg_match('/^\s*(#|\/\/)/', $line)
));

preg_match_all(
    "/AdvancedRoute::controller\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/",
    $active,
    $pairs,
    PREG_SET_ORDER
);

$rows = [];
$seen = [];
$missing = [];

foreach ($pairs as [, $path, $controller]) {
    $fqcn = 'App\\Http\\Controllers\\' . $controller;

    if (! class_exists($fqcn)) {
        $missing[] = $fqcn;
        continue;
    }

    $methods = [];
    foreach ((new ReflectionClass($fqcn))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->name === 'getMiddleware') {
            continue;
        }
        $methods[] = [$method, slugFor($method, $prefixPattern)];
    }

    // AdvancedRoute ordena las rutas sin parámetros primero para que
    // 'foo/about' no quede tapada por 'foo/{any}'.
    usort($methods, function ($a, $b) {
        $aHasParam = str_contains($a[1], '{');
        $bHasParam = str_contains($b[1], '{');

        if ($aHasParam !== $bHasParam) {
            return $aHasParam ? 1 : -1;
        }

        return strcmp($a[1], $b[1]);
    });

    foreach ($methods as [$method, $slug]) {
        if ($method->name === 'missingMethod') {
            $rows[] = ['ANY', str_replace('//', '/', $path . '/{_missing}'), $fqcn, 'missingMethod'];
            continue;
        }

        foreach (HTTP_METHODS as $verb) {
            if (! str_starts_with($method->name, $verb)) {
                continue;
            }

            $uri = rtrim($path . '/' . $slug, '/');
            $rows[] = [strtoupper($verb), $uri, $fqcn, $method->name];
            $seen[strtoupper($verb) . ' ' . $uri][] = $fqcn . '@' . $method->name;
            break;
        }
    }
}

$collisions = array_filter($seen, fn ($actions) => count($actions) > 1);

printf("pares AdvancedRoute ..... %d\n", count($pairs));
printf("controladores ausentes .. %d\n", count($missing));
foreach ($missing as $class) {
    echo "   ! $class\n";
}
printf("rutas generadas ......... %d\n", count($rows));
printf("colisiones verbo+uri .... %d\n", count($collisions));
foreach ($collisions as $key => $actions) {
    echo "   ~ $key -> " . implode(' | ', $actions) . "\n";
}

if ($output = $argv[1] ?? null) {
    $handle = fopen($output, 'w');
    fputcsv($handle, ['verb', 'uri', 'controller', 'method']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    echo "CSV escrito en $output\n";
}
