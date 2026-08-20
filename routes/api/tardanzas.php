<?php

use App\Http\Controllers\AplicacionDescargas\InicioController;
use App\Http\Controllers\AppMobile\AsistenciasAppController;
use App\Http\Controllers\Tardanzas\AsistenciasController;
use App\Http\Controllers\Tardanzas\TSubirController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: tardanzas
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// AsistenciasController
Route::post('asistencias', [AsistenciasController::class, 'postIndex'])->middleware('auth.personal');
Route::get('asistencias/datos-solo-alumnos', [AsistenciasController::class, 'getDatosSoloAlumnos'])->middleware('auth.personal');
Route::put('asistencias/detailed', [AsistenciasController::class, 'putDetailed'])->middleware('auth.personal');
Route::put('asistencias/eliminar-ausencia', [AsistenciasController::class, 'putEliminarAusencia'])->middleware('auth.personal');
Route::put('asistencias/poner-ausencia', [AsistenciasController::class, 'putPonerAusencia'])->middleware('auth.personal');

// InicioController
Route::put('aplicacion-descargas/detailed', [InicioController::class, 'putDetailed']);

// AsistenciasAppController
Route::post('asistencias-app', [AsistenciasAppController::class, 'postIndex'])->middleware('auth.personal');
Route::get('asistencias-app/datos-solo-alumnos', [AsistenciasAppController::class, 'getDatosSoloAlumnos'])->middleware('auth.personal');
Route::put('asistencias-app/detailed', [AsistenciasAppController::class, 'putDetailed'])->middleware('auth.personal');
Route::put('asistencias-app/eliminar-ausencia', [AsistenciasAppController::class, 'putEliminarAusencia'])->middleware('auth.personal');
Route::put('asistencias-app/poner-ausencia', [AsistenciasAppController::class, 'putPonerAusencia'])->middleware('auth.personal');

// TSubirController
// Como las de tardanzas/login: el usuario y la contraseña viajan en el cuerpo de
// cada petición (aquí dentro de `loginData`) y las verifica $this->user() con
// Auth::attempt(). No hay token que exigir.
Route::post('tardanzas/subir', [TSubirController::class, 'postIndex'])->withoutMiddleware('auth.token');
Route::put('tardanzas/subir/eliminar-ausencia', [TSubirController::class, 'putEliminarAusencia'])->withoutMiddleware('auth.token');
Route::put('tardanzas/subir/poner-ausencia', [TSubirController::class, 'putPonerAusencia'])->withoutMiddleware('auth.token');
