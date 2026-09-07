<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel CORS Options
    |--------------------------------------------------------------------------
    |
    | The allowed_methods and allowed_headers options are case-insensitive.
    |
    | You don't need to provide both allowed_origins and allowed_origins_patterns.
    | If one of the strings passed matches, it is considered a valid origin.
    |
    | If ['*'] is provided to allowed_methods, allowed_origins or allowed_headers
    | all methods / origins / headers are allowed.
    |
    */

    /*
     * You can enable CORS for 1 or multiple paths.
     * Example: ['api/*']
     */
    'paths' => ['api/*'],

    /*
    * Matches the request method. `['*']` allows all methods.
    */
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
     * Matches the request origin. `['*']` allows all origins. Wildcards can be used, eg `*.mydomain.com`
     *
     * Sale de CORS_ALLOWED_ORIGINS, una lista separada por comas, y si no está
     * definida se mantiene ['*'].
     *
     * ─────────────────────────────────────────────────────────────────────
     * `*` ES LA POLÍTICA, NO UN PENDIENTE. Decisión de Joseth, 6 sep 2026:
     * «siempre va a ser CORS `*` porque necesita ser llamado desde múltiples
     * orígenes, diferentes.»
     *
     * Aquí decía «hay que definirla en el .env de producción para que el
     * arreglo sirva de algo», de cuando cerrar la lista era el plan. **Se quita
     * porque manda hacer justo lo que rompe un cliente**: esta API la llaman
     * cuatro con orígenes distintos —un myvc_front por colegio, myvc_front_2, la
     * app de móvil y la de escritorio del horario—, y esa última habla desde un
     * WebView, o sea cruzando origen.
     *
     * Y la forma que tiene la equivocación, que es la que hay que reconocer:
     * **una lista con UN SOLO origen no bloquea de forma visible**. La librería
     * devuelve ese origen a todo el que pregunte (`isSingleOriginAllowed()`), así
     * que la respuesta trae cabecera y parece que funciona — el navegador la
     * compara con la suya, no casa y bloquea. Medido y fijado en
     * tests/Contrato/CorsDelEscritorioTest.php.
     *
     * Quién tiene una lista puesta: tools/cors-de-los-colegios.sh --env.
     * El porqué entero: docs/migracion/32-la-entrada-de-la-app-de-escritorio.md §3.
     * ─────────────────────────────────────────────────────────────────────
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', '*'))
    ))) ?: ['*'],

    /*
     * Patterns that can be used with `preg_match` to match the origin.
     */
    'allowed_origins_patterns' => [],

    /*
     * Sets the Access-Control-Allow-Headers response header. `['*']` allows all headers.
     */
    'allowed_headers' => ['*'],

    /*
     * Sets the Access-Control-Expose-Headers response header with these headers.
     */
    'exposed_headers' => [],

    /*
     * Sets the Access-Control-Max-Age response header when > 0.
     */
    'max_age' => 0,

    /*
     * Sets the Access-Control-Allow-Credentials header.
     */
    'supports_credentials' => false,
];
