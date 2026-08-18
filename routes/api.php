<?php

/*
|--------------------------------------------------------------------------
| Rutas de la API
|--------------------------------------------------------------------------
|
| Este archivo solo reparte. Las rutas viven en routes/api/, un archivo por
| dominio, siguiendo las carpetas de app/Http/Controllers.
|
| Antes había 96 llamadas a AdvancedRoute::controller() que generaban 538 rutas
| por reflexión EN CADA PETICIÓN: autocargaba los 96 controladores y recorría sus
| métodos con ReflectionClass. Medido en este proyecto, 45 ms por petición solo
| en eso, más la construcción de los objetos Route (que además hacía dos veces
| por ruta, aunque Laravel indexa por verbo+URI y la segunda sobrescribía a la
| primera).
|
| Los archivos de routes/api/ se generaron con tools/route-emit.php a partir de
| la tabla real que registraba AdvancedRoute, y se verificaron comparando el
| volcado de tools/route-table-dump.php antes y después: tabla idéntica, mismo
| orden.
|
| El orden dentro de cada archivo es significativo. Laravel resuelve la primera
| ruta que casa, así que las rutas sin parámetros van antes que las que llevan
| {param}. No reordenar sin volver a comparar el volcado.
|
*/

require __DIR__ . '/api/auth.php';
require __DIR__ . '/api/alumnos.php';
require __DIR__ . '/api/catalogos.php';
require __DIR__ . '/api/academico.php';
require __DIR__ . '/api/admin.php';
require __DIR__ . '/api/estructura.php';
require __DIR__ . '/api/disciplina.php';
require __DIR__ . '/api/informes.php';
require __DIR__ . '/api/tardanzas.php';
require __DIR__ . '/api/perfiles.php';
require __DIR__ . '/api/votaciones.php';
require __DIR__ . '/api/actividades.php';
require __DIR__ . '/api/piars.php';
