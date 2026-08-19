<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

/**
 * Rector NO se corre entero sobre este repo.
 *
 * Son 32.000 líneas sin tests que las cubran, y la regla que sale del plan de
 * migración es correrlo por carpeta y revisar cada diff:
 *
 *     vendor/bin/rector process app/Http/Controllers/Perfiles --dry-run
 *
 * Los imports por alias de raíz —lo que quedaba pendiente de la Fase 4— ya
 * están hechos, pero NO con esto. El set LARAVEL_FACADE_ALIASES_TO_FULL_NAMES
 * escribe el nombre completo en cada llamada, y aquí hay 990 `DB::`; con
 * `withImportNames()` sí pone el import, pero de paso colapsa cualquier otro
 * nombre completo que encuentre y tocaba diez ficheros que no tenían el
 * problema. Se hizo con `tools/imports-de-facades.php`, que cambia una línea
 * por import y ninguna más, y lo vigila `tests/Unit/AliasDeFacadesTest.php`.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        LaravelLevelSetList::UP_TO_LARAVEL_130,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
    ]);
