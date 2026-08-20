<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        /*
         * El canal por defecto, y desde el 20 ago 2026 rota.
         *
         * Apuntaba a `single`, que escribe siempre en el mismo `laravel.log` y
         * no lo trunca nunca. En el docker de desarrollo pesaba 48 MB; en los
         * colegios lleva años creciendo, en un alojamiento compartido donde el
         * espacio es el motivo por el que `vendor/` va por symlink.
         *
         * Se cambia AQUÍ y no en los `.env` a propósito: cada colegio tiene el
         * suyo, y son dieciséis. Puesto en `config/`, empieza a rotar en cuanto
         * se despliega el `app/`, sin tocar la configuración de nadie.
         *
         * Lo que cambia al desplegar: el fichero pasa a llamarse
         * `laravel-AAAA-MM-DD.log`. Quien tenga un `tail -f laravel.log` en la
         * memoria de los dedos, ahí está el motivo de que no salga nada.
         */
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        /*
         * Las consultas lentas, en su propio fichero y en JSON.
         *
         * Aparte de `laravel.log` porque se enciende para una temporada y se
         * lee entero: mezclado con los errores del día habría que separarlo a
         * mano. Y en JSON —una consulta por línea— porque el SQL de este
         * proyecto viene de cadenas PHP de varias líneas, y en un log de texto
         * plano no se sabe dónde acaba cada entrada. Lo agrupa
         * tools/consultas-lentas.py.
         *
         * Catorce ficheros: si nadie lo mira en dos semanas, es que no se está
         * midiendo nada y lo que sobra es el registro, no el disco.
         */
        'consultas-lentas' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'with' => [
                'filename' => storage_path('logs/consultas-lentas.log'),
                'maxFiles' => 14,
            ],
            'formatter' => JsonFormatter::class,
            'level' => 'info',
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],

];
