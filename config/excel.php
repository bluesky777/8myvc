<?php

use Maatwebsite\Excel\Excel;

return [
    'exports' => [

        /*
        |--------------------------------------------------------------------------
        | Chunk size
        |--------------------------------------------------------------------------
        |
        | When using FromQuery, the query is automatically chunked.
        | Here you can specify how big the chunk should be.
        |
        */
        'chunk_size'             => 1000,

        /*
        |--------------------------------------------------------------------------
        | Pre-calculate formulas during export
        |--------------------------------------------------------------------------
        */
        'pre_calculate_formulas' => false,

        /*
        |--------------------------------------------------------------------------
        | Enable strict null comparison
        |--------------------------------------------------------------------------
        |
        | When enabling strict null comparison empty cells ('') will
        | be added to the sheet.
        */
        'strict_null_comparison' => false,

        /*
        |--------------------------------------------------------------------------
        | CSV Settings
        |--------------------------------------------------------------------------
        |
        | Configure e.g. delimiter, enclosure and line ending for CSV exports.
        |
        */
        'csv'                    => [
            'delimiter'              => ',',
            'enclosure'              => '"',
            'line_ending'            => PHP_EOL,
            'use_bom'                => false,
            'include_separator_line' => false,
            'excel_compatibility'    => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Worksheet properties
        |--------------------------------------------------------------------------
        |
        | Configure e.g. default title, creator, subject,...
        |
        */
        'properties'             => [
            'creator'        => '',
            'lastModifiedBy' => '',
            'title'          => '',
            'description'    => '',
            'subject'        => '',
            'keywords'       => '',
            'category'       => '',
            'manager'        => '',
            'company'        => '',
        ],
    ],

    'imports'            => [

        /*
        |--------------------------------------------------------------------------
        | Read Only
        |--------------------------------------------------------------------------
        |
        | When dealing with imports, you might only be interested in the
        | data that the sheet exists. By default we ignore all styles,
        | however if you want to do some logic based on style data
        | you can enable it by setting read_only to false.
        |
        */
        'read_only' => true,

        /*
        |--------------------------------------------------------------------------
        | Ignore Empty
        |--------------------------------------------------------------------------
        |
        | When dealing with imports, you might be interested in ignoring
        | rows that have null values or empty strings. By default rows
        | containing empty strings or empty values are not ignored but can be
        | ignored by enabling the setting ignore_empty to true.
        |
        */
        'ignore_empty' => false,

        /*
        |--------------------------------------------------------------------------
        | Heading Row Formatter
        |--------------------------------------------------------------------------
        |
        | Configure the heading row formatter.
        | Available options: none|slug|custom
        |
        */
        'heading_row' => [
            'formatter' => 'slug',
        ],

        /*
        |--------------------------------------------------------------------------
        | CSV Settings
        |--------------------------------------------------------------------------
        |
        | Configure e.g. delimiter, enclosure and line ending for CSV imports.
        |
        */
        'csv'         => [
            'delimiter'        => ',',
            'enclosure'        => '"',
            'escape_character' => '\\',
            'contiguous'       => false,
            'input_encoding'   => 'UTF-8',
        ],

        /*
        |--------------------------------------------------------------------------
        | Worksheet properties
        |--------------------------------------------------------------------------
        |
        | Configure e.g. default title, creator, subject,...
        |
        */
        'properties'  => [
            'creator'        => '',
            'lastModifiedBy' => '',
            'title'          => '',
            'description'    => '',
            'subject'        => '',
            'keywords'       => '',
            'category'       => '',
            'manager'        => '',
            'company'        => '',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Extension detector
    |--------------------------------------------------------------------------
    |
    | Configure here which writer/reader type should be used when the package
    | needs to guess the correct type based on the extension alone.
    |
    */
    'extension_detector' => [
        'xlsx'     => Excel::XLSX,
        'xlsm'     => Excel::XLSX,
        'xltx'     => Excel::XLSX,
        'xltm'     => Excel::XLSX,
        'xls'      => Excel::XLS,
        'xlt'      => Excel::XLS,
        'ods'      => Excel::ODS,
        'ots'      => Excel::ODS,
        'slk'      => Excel::SLK,
        'xml'      => Excel::XML,
        'gnumeric' => Excel::GNUMERIC,
        'htm'      => Excel::HTML,
        'html'     => Excel::HTML,
        'csv'      => Excel::CSV,
        'tsv'      => Excel::TSV,

        /*
        |--------------------------------------------------------------------------
        | PDF Extension
        |--------------------------------------------------------------------------
        |
        | Configure here which Pdf driver should be used by default.
        | Available options: Excel::MPDF | Excel::TCPDF | Excel::DOMPDF
        |
        */
        'pdf'      => Excel::DOMPDF,
    ],

    /*
    |--------------------------------------------------------------------------
    | Value Binder
    |--------------------------------------------------------------------------
    |
    | PhpSpreadsheet offers a way to hook into the process of a value being
    | written to a cell. In there some assumptions are made on how the
    | value should be formatted. If you want to change those defaults,
    | you can implement your own default value binder.
    |
    | Possible value binders:
    |
    | [x] Maatwebsite\Excel\DefaultValueBinder::class
    | [x] PhpOffice\PhpSpreadsheet\Cell\StringValueBinder::class
    | [x] PhpOffice\PhpSpreadsheet\Cell\AdvancedValueBinder::class
    |
    */
    'value_binder' => [
        'default' => Maatwebsite\Excel\DefaultValueBinder::class,
    ],

    'cache' => [
        /*
        |--------------------------------------------------------------------------
        | Default cell caching driver
        |--------------------------------------------------------------------------
        |
        | By default PhpSpreadsheet keeps all cell values in memory, however when
        | dealing with large files, this might result into memory issues. If you
        | want to mitigate that, you can configure a cell caching driver here.
        | When using the illuminate driver, it will store each value in a the
        | cache store. This can slow down the process, because it needs to
        | store each value. You can use the "batch" store if you want to
        | only persist to the store when the memory limit is reached.
        |
        | Drivers: memory|illuminate|batch
        |
        */
        'driver'     => 'memory',

        /*
        |--------------------------------------------------------------------------
        | Batch memory caching
        |--------------------------------------------------------------------------
        |
        | When dealing with the "batch" caching driver, it will only
        | persist to the store when the memory limit is reached.
        | Here you can tweak the memory limit to your liking.
        |
        */
        'batch'     => [
            'memory_limit' => 60000,
        ],

        /*
        |--------------------------------------------------------------------------
        | Illuminate cache
        |--------------------------------------------------------------------------
        |
        | When using the "illuminate" caching driver, it will automatically use
        | your default cache store. However if you prefer to have the cell
        | cache on a separate store, you can configure the store name here.
        | You can use any store defined in your cache config. When leaving
        | at "null" it will use the default store.
        |
        */
        'illuminate' => [
            'store' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Handler
    |--------------------------------------------------------------------------
    |
    | By default the import is wrapped in a transaction. This is useful
    | for when an import may fail and you want to retry it. With the
    | transactions, the previous import gets rolled-back.
    |
    | You can disable the transaction handler by setting this to null.
    | Or you can choose a custom made transaction handler here.
    |
    | Supported handlers: null|db
    |
    */
    'transactions' => [
        // **`null` y no `'db'`, decidido por Joseth el 20 sep 2026 — y es lo que
        // hace que el punto de control sirva para algo.**
        //
        // Con `'db'`, `Reader.php:111` envuelve la importación ENTERA en una
        // transacción. `PuntoDeControlDeImportacion::anotar()` escribe dentro, así
        // que al fallar el ROLLBACK **se lleva las filas y el avance a la vez**:
        // «reanudar» sólo podía significar «se cortó y no se escribió nada».
        // Medido por `myvc-front-a6` cortando en la fila 150 de 421 con un
        // disparador: `filas` → 0, `avance` → `{}`, y los `alumnos.updated_at` sin
        // moverse.
        //
        // ## Lo que NO se pierde al quitarlo, que es lo que casi lo bloquea
        //
        // No es «ahora un fallo deja medio alumno». Eso lo impide
        // `ImportarController:177`, un `DB::transaction` **por fila** que envuelve
        // `procesarFila()` y `anotar()` juntos. Anidada dentro de la global,
        // Laravel la convertía en un **savepoint** y su commit no comprometía
        // nada; suelta, es una transacción de verdad. Lo único que se pierde es la
        // atomicidad del **fichero entero** — que es justo lo que impedía
        // reanudar.
        //
        // Y por eso **no** se hizo lo otro que se propuso, anotar el avance por
        // una segunda conexión: el avance sobreviviría diciendo «150 hechas» con
        // cero filas escritas, y al reanudar se saltarían 150 alumnos que nunca
        // entraron. Rompe el invariante que nombra el comentario de la 177 —*el
        // punto de control no puede mentir porque se guarda con ella*— y cambia
        // perder el avance sabiéndolo por perder alumnos sin saberlo.
        //
        // ## A quién más alcanza: medido, y a nadie
        //
        // `Excel::import` tiene **cinco** llamadas y todas viven en
        // `ImportarController`. Las tres viejas —`postCartera`, `getIndex`,
        // `getModificar`— usan la firma de maatwebsite 2.x y **dan 500 antes de
        // escribir nada**, desde años antes de esta migración; lo fija
        // `ExcelTest`. Y el ensayo (`postEnsayo`) **no depende de esta transacción
        // para no escribir**: `EnsayoDeLaImportacion` tiene cero escrituras.
        //
        // *El alcance se contó por LLAMADA y no por fichero, porque contado por
        // fichero daba «uno» y la pregunta era otra — lo levantó `myvc-front-a6`.*
        //
        // ## ⚠️ Y es la CADENA `'null'`, no el `null` de PHP — el comentario de
        // arriba, que es el del vendor, dice «setting this to null» y engaña
        //
        // `TransactionManager` extiende el `Manager` de Laravel, que compone el
        // nombre del método con `'create'.Str::studly($driver).'Driver'`. Con la
        // cadena sale `createNullDriver()`, que existe y devuelve un
        // `NullTransactionHandler` —invoca el callback y ya—. Con el `null` de PHP
        // sale `createDriver()` y revienta: *«Unable to resolve NULL driver»*, un
        // **500 en cada importación**. Costó 55 tests rojos el 20 sep 2026 al
        // escribir esto siguiendo el comentario de arriba al pie de la letra.
        'handler' => 'null',
    ],

    'temporary_files' => [

        /*
        |--------------------------------------------------------------------------
        | Local Temporary Path
        |--------------------------------------------------------------------------
        |
        | When exporting and importing files, we use a temporary file, before
        | storing reading or downloading. Here you can customize that path.
        |
        */
        'local_path'          => storage_path('framework/laravel-excel'),

        /*
        |--------------------------------------------------------------------------
        | Remote Temporary Disk
        |--------------------------------------------------------------------------
        |
        | When dealing with a multi server setup with queues in which you
        | cannot rely on having a shared local temporary path, you might
        | want to store the temporary file on a shared disk. During the
        | queue executing, we'll retrieve the temporary file from that
        | location instead. When left to null, it will always use
        | the local path. This setting only has effect when using
        | in conjunction with queued imports and exports.
        |
        */
        'remote_disk'         => null,
        'remote_prefix'       => null,

        /*
        |--------------------------------------------------------------------------
        | Force Resync
        |--------------------------------------------------------------------------
        |
        | When dealing with a multi server setup as above, it's possible
        | for the clean up that occurs after entire queue has been run to only
        | cleanup the server that the last AfterImportJob runs on. The rest of the server
        | would still have the local temporary file stored on it. In this case your
        | local storage limits can be exceeded and future imports won't be processed.
        | To mitigate this you can set this config value to be true, so that after every
        | queued chunk is processed the local temporary file is deleted on the server that
        | processed it.
        |
        */
        'force_resync_remote' => null,
    ],
];
