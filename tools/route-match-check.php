<?php

/**
 * Comprueba QUÉ RUTA GANA para cada URI concreta.
 *
 * Comparar el conjunto de rutas no basta: Laravel resuelve la primera que casa,
 * así que reordenar puede hacer que 'puestos/{id}' tape a 'puestos/detailed' sin
 * que el conjunto cambie en absoluto.
 *
 * Esto pregunta al router real, para cada URI literal de la tabla, qué acción
 * atiende. Si la salida es idéntica antes y después de un cambio de rutas, el
 * enrutado se comporta igual.
 *
 * Uso:
 *   git stash && php tools/route-match-check.php > antes.txt && git stash pop
 *   php tools/route-match-check.php > despues.txt
 *   diff antes.txt despues.txt
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$coleccion = Illuminate\Support\Facades\Route::getRoutes();

$candidatas = [];

foreach ($coleccion as $ruta) {
    $uri = $ruta->uri();

    foreach ($ruta->methods() as $verbo) {
        if ($verbo === 'HEAD') {
            continue;
        }

        // Se prueban las URIs literales; son las que una ruta con {param} puede tapar.
        if (! str_contains($uri, '{')) {
            $candidatas[$verbo . ' ' . $uri] = [$verbo, $uri];
        }

        /*
         * Y también las que llevan parámetros, sustituyéndolos por un valor
         * cualquiera: así se detecta el caso inverso, que una literal nueva
         * intercepte lo que antes atendía la paramétrica.
         */
        if (str_contains($uri, '{')) {
            $concreta = preg_replace('/\{[^}]+\?\}/', '1', $uri);
            $concreta = preg_replace('/\{[^}]+\}/', '1', $concreta);
            $candidatas[$verbo . ' ' . $concreta] = [$verbo, $concreta];
        }
    }
}

ksort($candidatas);

$lineas = [];

foreach ($candidatas as $clave => [$verbo, $uri]) {
    $peticion = Illuminate\Http\Request::create('/' . ltrim($uri, '/'), $verbo);

    try {
        $ganadora = $coleccion->match($peticion);
        $accion = $ganadora->getActionName();
    } catch (\Throwable $e) {
        $accion = 'SIN COINCIDENCIA (' . class_basename($e) . ')';
    }

    $lineas[] = sprintf('%-7s %-58s -> %s', $verbo, $uri, $accion);
}

echo implode("\n", $lineas), "\n";
echo '# URIs comprobadas: ' . count($lineas) . "\n";
