<?php

use App\Http\Controllers\VtAspiracionesController;
use App\Http\Controllers\VtCandidatosController;
use App\Http\Controllers\VtParticipantesController;
use App\Http\Controllers\VtVotacionesController;
use App\Http\Controllers\VtVotosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: votaciones
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// VtVotacionesController
Route::get('votaciones', [VtVotacionesController::class, 'getIndex']);
Route::get('votaciones/actual', [VtVotacionesController::class, 'getActual']);
Route::get('votaciones/actual-in-action', [VtVotacionesController::class, 'getActualInAction']);
Route::get('votaciones/en-accion-inscrito', [VtVotacionesController::class, 'getEnAccionInscrito']);
// Los seis interruptores de la elección del colegio: cuál es la votación
// actual, si está abierta, si está bloqueada, quién puede votar y quién puede
// ver los resultados. La votación viaja en el cuerpo, así que la ruta no nombra
// a nadie — y el `UPDATE` de `set-actual` no lleva ninguna condición de dueño.
// Las pinta `VotacionesCtrl`, que es la pantalla de administración.
Route::put('votaciones/set-actual', [VtVotacionesController::class, 'putSetActual'])->middleware('auth.personal');
Route::put('votaciones/set-in-action', [VtVotacionesController::class, 'putSetInAction'])->middleware('auth.personal');
Route::put('votaciones/set-locked', [VtVotacionesController::class, 'putSetLocked'])->middleware('auth.personal');
Route::put('votaciones/set-permiso-ver-results', [VtVotacionesController::class, 'putSetPermisoVerResults'])->middleware('auth.personal');
Route::put('votaciones/set-votan-acudientes', [VtVotacionesController::class, 'putSetVotanAcudientes'])->middleware('auth.personal');
Route::put('votaciones/set-votan-profes', [VtVotacionesController::class, 'putSetVotanProfes'])->middleware('auth.personal');
Route::post('votaciones/store', [VtVotacionesController::class, 'postStore']);
Route::get('votaciones/unsignedsusers', [VtVotacionesController::class, 'getUnsignedsusers']);
Route::delete('votaciones/destroy/{id}', [VtVotacionesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('votaciones/show/{id}', [VtVotacionesController::class, 'getShow']);
Route::put('votaciones/update/{id}', [VtVotacionesController::class, 'putUpdate'])->middleware('auth.personal');

// VtAspiracionesController
Route::post('aspiraciones/store', [VtAspiracionesController::class, 'postStore']);
Route::put('aspiraciones/update', [VtAspiracionesController::class, 'putUpdate']);
Route::delete('aspiraciones/destroy/{id}', [VtAspiracionesController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtParticipantesController
Route::get('participantes', [VtParticipantesController::class, 'getIndex']);
Route::get('participantes/allinscritos', [VtParticipantesController::class, 'getAllinscritos']);
Route::put('participantes/datos', [VtParticipantesController::class, 'putDatos']);
Route::put('participantes/guardar-inscripciones', [VtParticipantesController::class, 'putGuardarInscripciones']);
Route::post('participantes/inscribir-profesores', [VtParticipantesController::class, 'postInscribirProfesores']);
// Devuelve la ficha completa de los docentes —documento, dirección, teléfono—.
Route::put('participantes/profesores', [VtParticipantesController::class, 'putProfesores'])->middleware('auth.personal');
Route::put('participantes/set-locked', [VtParticipantesController::class, 'putSetLocked']);
Route::put('participantes/votantes', [VtParticipantesController::class, 'putVotantes']);
Route::delete('participantes/destroy/{id}', [VtParticipantesController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtCandidatosController
Route::get('candidatos', [VtCandidatosController::class, 'getIndex']);
Route::get('candidatos/conaspiraciones', [VtCandidatosController::class, 'getConaspiraciones']);
Route::post('candidatos/store', [VtCandidatosController::class, 'postStore']);
Route::delete('candidatos/destroy/{id}', [VtCandidatosController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtVotosController
Route::get('votos', [VtVotosController::class, 'getIndex']);
Route::put('votos/show', [VtVotosController::class, 'putShow']);
Route::post('votos/store', [VtVotosController::class, 'postStore']);
Route::delete('votos/destroy/{id}', [VtVotosController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('votos/update/{id}', [VtVotosController::class, 'putUpdate'])->middleware('auth.personal');
