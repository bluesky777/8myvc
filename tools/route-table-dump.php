<?php

/**
 * Vuelca la tabla de rutas REAL que registra Laravel.
 *
 * A diferencia de `php artisan route:list`, no instancia los controladores, así
 * que funciona aunque sus constructores llamen a User::fromToken() y aborten sin
 * token. (route:list los instancia para leer su middleware; por eso está roto.)
 *
 * Uso:
 *   php tools/route-table-dump.php > antes.txt
 *   ... cambiar routes/api.php ...
 *   php tools/route-table-dump.php > despues.txt
 *   diff antes.txt despues.txt      # debe estar vacío
 *
 * Esa comparación es toda la verificación que necesita el reemplazo de
 * AdvancedRoute por rutas explícitas: si la tabla no cambia, el comportamiento
 * de enrutado no cambia.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$filas = [];

foreach (Illuminate\Support\Facades\Route::getRoutes() as $ruta) {
    $accion = $ruta->getActionName();

    foreach ($ruta->methods() as $verbo) {
        if ($verbo === 'HEAD') {
            continue; // Laravel lo añade solo junto a cada GET
        }

        $filas[] = sprintf('%-7s %-58s %s', $verbo, $ruta->uri(), $accion);
    }
}

// NO se ordena: el orden de registro es significativo. Laravel resuelve la
// primera ruta que casa, así que 'puestos/{id}' registrada antes que
// 'puestos/detailed' taparía a la segunda. AdvancedRoute ordenaba las rutas sin
// parámetros primero justamente por eso, y el reemplazo tiene que conservarlo.
// Para comparar solo el CONJUNTO de rutas, pasar la salida por `sort`.

echo implode("\n", $filas), "\n";
echo '# total: ' . count($filas) . "\n";
