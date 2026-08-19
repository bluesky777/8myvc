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
 * Lo que queda pendiente y para lo que está preparada esta configuración: los
 * 145 ficheros que importan los facades por su alias de raíz (`use DB;`,
 * `use Request;`, `use Hash;`) en vez de `Illuminate\Support\Facades\*`. Hoy
 * funcionan porque `config/app.php` mantiene los alias; el día que Laravel los
 * retire, dejan de hacerlo todos a la vez.
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
