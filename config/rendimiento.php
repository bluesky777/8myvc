<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registro de consultas lentas
    |--------------------------------------------------------------------------
    |
    | El paso 3 del plan de rendimiento —«log de consultas lentas en producción,
    | una semana»— está escrito para el `slow_query_log` de MySQL, y en estos
    | alojamientos no se puede: son cuentas de cPanel compartidas, sin acceso al
    | `my.cnf` ni a `SET GLOBAL`. Así que el registro vive aquí dentro, donde sí
    | se llega, y a cambio ve algo que el de MySQL no ve: qué ruta hizo la
    | consulta.
    |
    | Va apagado. `umbral_ms` a 0 —el valor por defecto— no registra ni siquiera
    | el `DB::listen`, así que en un colegio que no lo encienda esto no cuesta
    | nada. Se enciende colegio a colegio poniendo el umbral en su `.env`.
    |
    */

    'consultas_lentas' => [

        // Milisegundos a partir de los cuales una consulta se anota. 0 = apagado.
        // 500 es el valor que propone el plan para la primera semana; bajarlo a
        // 100 después, cuando lo gordo ya esté arreglado.
        'umbral_ms' => (float) env('CONSULTAS_LENTAS_MS', 0),

        'canal' => env('CONSULTAS_LENTAS_CANAL', 'consultas-lentas'),

        // Los valores de la consulta NO se anotan por defecto, y es a propósito.
        // Aquí dentro viajan nombres y fechas de nacimiento de menores, y el
        // fichero acaba en un disco compartido con otros quince colegios. Para
        // decidir un índice basta la forma de la consulta; los valores solo
        // hacen falta si se quiere reproducir un EXPLAIN concreto, y para eso
        // se encienden un rato y se vuelven a apagar.
        'bindings' => (bool) env('CONSULTAS_LENTAS_BINDINGS', false),
    ],

];
