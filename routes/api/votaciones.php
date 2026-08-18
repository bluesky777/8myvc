<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VtAspiracionesController;
use App\Http\Controllers\VtCandidatosController;
use App\Http\Controllers\VtParticipantesController;
use App\Http\Controllers\VtVotacionesController;
use App\Http\Controllers\VtVotosController;

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
Route::put('votaciones/set-actual', [VtVotacionesController::class, 'putSetActual']);
Route::put('votaciones/set-in-action', [VtVotacionesController::class, 'putSetInAction']);
Route::put('votaciones/set-locked', [VtVotacionesController::class, 'putSetLocked']);
Route::put('votaciones/set-permiso-ver-results', [VtVotacionesController::class, 'putSetPermisoVerResults']);
Route::put('votaciones/set-votan-acudientes', [VtVotacionesController::class, 'putSetVotanAcudientes']);
Route::put('votaciones/set-votan-profes', [VtVotacionesController::class, 'putSetVotanProfes']);
Route::post('votaciones/store', [VtVotacionesController::class, 'postStore']);
Route::get('votaciones/unsignedsusers', [VtVotacionesController::class, 'getUnsignedsusers']);
Route::delete('votaciones/destroy/{id}', [VtVotacionesController::class, 'deleteDestroy'])->middleware('auth.token');
Route::get('votaciones/show/{id}', [VtVotacionesController::class, 'getShow']);
Route::put('votaciones/update/{id}', [VtVotacionesController::class, 'putUpdate'])->middleware('auth.token');

// VtAspiracionesController
Route::post('aspiraciones/store', [VtAspiracionesController::class, 'postStore'])->middleware('auth.token');
Route::put('aspiraciones/update', [VtAspiracionesController::class, 'putUpdate'])->middleware('auth.token');
Route::delete('aspiraciones/destroy/{id}', [VtAspiracionesController::class, 'deleteDestroy'])->middleware('auth.token');

// VtParticipantesController
Route::get('participantes', [VtParticipantesController::class, 'getIndex']);
Route::get('participantes/allinscritos', [VtParticipantesController::class, 'getAllinscritos']);
Route::put('participantes/datos', [VtParticipantesController::class, 'putDatos']);
Route::put('participantes/guardar-inscripciones', [VtParticipantesController::class, 'putGuardarInscripciones']);
Route::post('participantes/inscribir-profesores', [VtParticipantesController::class, 'postInscribirProfesores']);
Route::put('participantes/profesores', [VtParticipantesController::class, 'putProfesores']);
Route::put('participantes/set-locked', [VtParticipantesController::class, 'putSetLocked']);
Route::put('participantes/votantes', [VtParticipantesController::class, 'putVotantes']);
Route::delete('participantes/destroy/{id}', [VtParticipantesController::class, 'deleteDestroy'])->middleware('auth.token');

// VtCandidatosController
Route::get('candidatos', [VtCandidatosController::class, 'getIndex']);
Route::get('candidatos/conaspiraciones', [VtCandidatosController::class, 'getConaspiraciones']);
Route::post('candidatos/store', [VtCandidatosController::class, 'postStore']);
Route::delete('candidatos/destroy/{id}', [VtCandidatosController::class, 'deleteDestroy'])->middleware('auth.token');

// VtVotosController
Route::get('votos', [VtVotosController::class, 'getIndex']);
Route::put('votos/show', [VtVotosController::class, 'putShow']);
Route::post('votos/store', [VtVotosController::class, 'postStore']);
Route::delete('votos/destroy/{id}', [VtVotosController::class, 'deleteDestroy'])->middleware('auth.token');
Route::put('votos/update/{id}', [VtVotosController::class, 'putUpdate'])->middleware('auth.token');
