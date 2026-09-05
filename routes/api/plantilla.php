<?php

use App\Http\Controllers\PlantillaNotasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La plantilla de notas del colegio
|--------------------------------------------------------------------------
|
| Las nueve rutas de la §5.1.b de docs/migracion/28-competencias-e-indicadores.md,
| autorizadas por Joseth el 4 sep 2026 **como un lote**, con el precio delante: la
| Entrega 1 entera más el alcance de la Entrega 7.
|
| **Se pidieron juntas y no de una en una porque por separado no sirven.** Con el
| `GET` y los `POST` se puede escribir una plantilla y **nadie puede aplicarla**;
| con el `sembrar` y sin el alcance, la plantilla de una fila de preescolar le cae
| encima al bachillerato entero. Es la misma razón por la que las tres primeras de
| `horario/` entraron a la vez: un módulo que no llega a hacer lo que vino a hacer
| no es medio módulo, es cero.
|
| Lo que resuelven: hoy `unidades_por_defecto` y `subunidades_por_defecto` **se
| editan a mano en phpMyAdmin**. Ésa es la frase entera del problema.
|
| ## `auth.personal` en la ruta y `can_edit_plantilla_notas` DENTRO
|
| Es la forma de este repo desde `myimages/cambiarlogocolegio`: el guard cierra la
| puerta a alumnos y acudientes **antes de tocar el controlador**, y el criterio
| fino va en el método, con `Autoriza::puedeEditarPlantillaNotas`.
|
| Y aquí el criterio fino no es un adorno: **`auth.personal` deja pasar a cualquier
| docente**, y una fila de esta plantilla multiplica — un 90 % escrito aquí es un
| 90 % en todas las asignaturas que se siembren en el colegio. Un docente no
| configura la plantilla del colegio; ése es justo el punto de la entrega.
|
| **El permiso nace repartido a nadie** (ver
| `2026_09_05_300000_create_permiso_can_edit_plantilla_notas`): el superusuario
| pasa por encima, así que la pantalla funciona el primer día, y cada colegio
| decide desde su pantalla de roles si además la usan rectoría o coordinación.
|
| Ninguna de las nueve es pública ni debería serlo, así que esto **no** mueve
| `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni `AutenticacionTest::SIN_GUARD`.
|
| ## El orden, que aquí sí tiene trampa
|
| `plantilla-notas/orden` y `plantilla-notas/sembrar` son literales y van **antes**
| que cualquier `{id}`; no hay ninguna ruta `PUT plantilla-notas/{algo}` que
| pudiera tragárselas, y no se debe añadir. Los dos `{id}` van detrás de un
| segmento literal distinto —`unidad/` y `subunidad/`—, así que no se tapan entre
| sí.
|
*/

Route::get('plantilla-notas', [PlantillaNotasController::class, 'getIndex'])->middleware('auth.personal');

Route::put('plantilla-notas/orden', [PlantillaNotasController::class, 'putOrden'])->middleware('auth.personal');
Route::put('plantilla-notas/sembrar', [PlantillaNotasController::class, 'putSembrar'])->middleware('auth.personal');

Route::post('plantilla-notas/unidad', [PlantillaNotasController::class, 'postUnidad'])->middleware('auth.personal');
Route::put('plantilla-notas/unidad/{id}', [PlantillaNotasController::class, 'putUnidad'])->middleware('auth.personal');
Route::delete('plantilla-notas/unidad/{id}', [PlantillaNotasController::class, 'deleteUnidad'])->middleware('auth.personal');

Route::post('plantilla-notas/subunidad', [PlantillaNotasController::class, 'postSubunidad'])->middleware('auth.personal');
Route::put('plantilla-notas/subunidad/{id}', [PlantillaNotasController::class, 'putSubunidad'])->middleware('auth.personal');
Route::delete('plantilla-notas/subunidad/{id}', [PlantillaNotasController::class, 'deleteSubunidad'])->middleware('auth.personal');
