<?php

use App\Http\Controllers\RubricasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rúbricas
|--------------------------------------------------------------------------
|
| La familia `rubricas/`, entera con `auth.personal`: la matriz la edita y la
| califica el personal del colegio. El contrato es docs/migracion/24-rubricas.md
| (§4), y las diez entraron el 2 sep 2026 con la decisión 4 de Joseth: la
| rúbrica PRODUCE la nota de la subunidad.
|
| Ninguna de estas rutas escribe `notas.nota`. `valorar` guarda las marcas y
| devuelve la nota calculada; escribirla sigue siendo `notas/update` y
| `notas/lote`, tal como están, en routes/api/academico.php.
|
| El orden importa: las literales y las que llevan un segmento fijo delante del
| parámetro van ANTES que `rubricas/{id}`, porque Laravel sirve la primera que
| casa. `GET rubricas/niveles-de-la-escala` debajo de `GET rubricas/{id}` sería
| un 404 «esa rúbrica no existe» con el id «niveles-de-la-escala».
|
*/

Route::get('rubricas', [RubricasController::class, 'getIndex'])->middleware('auth.personal');
Route::get('rubricas/niveles-de-la-escala', [RubricasController::class, 'getNivelesDeLaEscala'])->middleware('auth.personal');
Route::post('rubricas', [RubricasController::class, 'postStore'])->middleware('auth.personal');
Route::put('rubricas/valorar-lote', [RubricasController::class, 'putValorarLote'])->middleware('auth.personal');
Route::put('rubricas/valorar/{nota_id}', [RubricasController::class, 'putValorar'])->middleware('auth.personal');
Route::put('rubricas/subunidad/{subunidad_id}', [RubricasController::class, 'putSubunidad'])->middleware('auth.personal');
Route::get('rubricas/calificar/{subunidad_id}', [RubricasController::class, 'getCalificar'])->middleware('auth.personal');
Route::get('rubricas/{id}', [RubricasController::class, 'getShow'])->middleware('auth.personal');
Route::put('rubricas/{id}', [RubricasController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('rubricas/{id}', [RubricasController::class, 'deleteDestroy'])->middleware('auth.personal');
