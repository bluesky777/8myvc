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
     *
     * ─────────────────────────────────────────────────────────────────────
     * `Content-Disposition` ESTÁ AQUÍ PORQUE ES EL NOMBRE DEL ARCHIVO.
     *
     * Por defecto un navegador sólo deja que el JavaScript lea siete cabeceras
     * de una respuesta cruzada, y `Content-Disposition` no es ninguna de ellas.
     * O sea que **la cabecera llega y el `fetch` no la ve**: se puede leer con
     * `curl` y `response.headers.get('content-disposition')` devuelve `null`.
     * Nada falla, no hay error que leer — el front se cae a un nombre de
     * respaldo y el archivo se guarda con el nombre equivocado.
     *
     * Lo que lo destapó: el libro de notas
     * (`GET api/planilla-offline/libro/{periodo_id}`) baja como «Libro de
     * notas.xlsx» en vez de `notas-P3-2026.xlsx`, y sobre todo **se pierde el
     * `-consulta`** que distingue el libro de un periodo cerrado —el que no se
     * va a poder subir— del que sí. Ese sufijo no se puede reconstruir en el
     * front: lo decide el servidor mirando `profes_pueden_editar_notas`.
     *
     * **No es sólo del libro.** Van por aquí once rutas de `api/*` que mandan
     * `Content-Disposition`: las tres de `planilla-offline` (libro, planilla y
     * acta), las seis de `Excel::download` (`users/export`,
     * `cartera/exportar-solo-deudores`, `simat/alumnos`,
     * `simat/alumnos-exportar`, `acudientes-export/acudientes`,
     * `excel-docentes/docentes/{year}/{year_id}`), el PDF de
     * `certificados-estudio/certificado-grupo/{grupo_id}` y el proyecto de
     * `horario/versiones/{id}/proyecto`. Se arregla una vez para todas porque
     * quien pone la cabecera es `HandleCors`, que las cubre todas: `paths` de
     * aquí arriba es `api/*` y no hay ninguna fuera.
     *
     * **«Sólo pasa mientras se desarrolla» es media verdad, y la mitad que
     * falta es la que decide la prioridad.** Comprobado el 22 sep 2026, no
     * heredado: el front no cruza origen en ningún colegio —`app/scripts/app.ts:52`
     * y `app2/src/app/core/api/entorno.ts:18-23` **calculan** la base de la API
     * con `location.protocol + location.hostname + '/8myvc/public/api/'`, así
     * que por construcción es el origen desde el que se sirvió el bundle, y eso
     * vale igual para `lal`, el único con dominio propio—. Hasta ahí, cierto.
     *
     * Pero el front no es el único que baja archivos de aquí. Cruzan origen **en
     * producción, hoy**: la app de escritorio del horario (WebView, origen
     * `tauri://localhost`), la build web de `myvc_flutter` y
     * `https://horarios.micolevirtual.com`, que sirve ese mismo Angular como web
     * y al que `app2/src/app/paginas/horario/cuadrar/cuadrar.ts:49` manda al
     * usuario. Y la ruta que esos tres llaman —`horario/versiones/{id}/proyecto`—
     * es **una de las once de esta lista**. Que no se haya notado es porque su
     * cliente hoy no lee el nombre (`myvc_horarios/escritorio/src/app/subir/
     * descargar-proyecto.ts`: el transporte no le pasa las cabeceras), no porque
     * la cabecera llegue.
     *
     * Fijado en tests/Contrato/CorsExponeElNombreDelArchivoTest.php.
     * ─────────────────────────────────────────────────────────────────────
     */
    'exposed_headers' => ['Content-Disposition'],

    /*
     * Sets the Access-Control-Max-Age response header when > 0.
     */
    'max_age' => 0,

    /*
     * Sets the Access-Control-Allow-Credentials header.
     */
    'supports_credentials' => false,
];
