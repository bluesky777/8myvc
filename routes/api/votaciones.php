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
// Lo que la pantalla de votar necesita y por eso se queda abierto:
// `en-accion-inscrito` y `votos/store` son las DOS únicas que llama `VotarCtrl`,
// que es el estado del front sin `needed_permissions`. `candidatos/conaspiraciones`
// es la papeleta y se acota con `actualInscrito($user)`; `votos/show` y el índice
// de `votaciones` se acotan por el `user_id` del token.
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
// Crear la votación. Sus seis `set-*`, su `update` y su `destroy` ya llevaban
// guard: es la misma pantalla de Configuración, y ésta se quedó fuera. Además
// escribía antes de reventar —un alumno creaba la votación y recibía un 500—, y
// con `actual=1` hacía `UPDATE vt_votaciones SET actual=0` sobre todas.
Route::post('votaciones/store', [VtVotacionesController::class, 'postStore'])->middleware('auth.personal');
// Pretende listar todos los usuarios del colegio con su username, su correo y si
// son superusuario, sin filtrar por nada. Está rota desde antes de la migración
// —05 §8, `vt_participantes` no tiene `user_id`— así que hoy responde 500; el
// guard es para el día que se arregle. No la llama ningún cliente.
Route::get('votaciones/unsignedsusers', [VtVotacionesController::class, 'getUnsignedsusers'])->middleware('auth.personal');
Route::delete('votaciones/destroy/{id}', [VtVotacionesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('votaciones/show/{id}', [VtVotacionesController::class, 'getShow']);
Route::put('votaciones/update/{id}', [VtVotacionesController::class, 'putUpdate'])->middleware('auth.personal');

// VtAspiracionesController
// Los cargos a los que se aspira: crear y editar. Su `destroy` ya lo llevaba, y
// es la asimetría de 05 §15 otra vez — el guard fue a la que tiene `{id}`.
Route::post('aspiraciones/store', [VtAspiracionesController::class, 'postStore'])->middleware('auth.personal');
Route::put('aspiraciones/update', [VtAspiracionesController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('aspiraciones/destroy/{id}', [VtAspiracionesController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtParticipantesController
// El censo electoral entero. La pantalla que lo usa va con
// `can_edit_participantes` en el front, y `profesores` y `destroy` ya llevaban
// el guard aquí. `votantes` es la peor: 37 KB con el documento, el celular, la
// dirección y el correo de cada uno **y a quién votó**. Ver 05 §18.
Route::get('participantes', [VtParticipantesController::class, 'getIndex'])->middleware('auth.personal');
Route::get('participantes/allinscritos', [VtParticipantesController::class, 'getAllinscritos'])->middleware('auth.personal');
Route::put('participantes/datos', [VtParticipantesController::class, 'putDatos'])->middleware('auth.personal');
Route::put('participantes/guardar-inscripciones', [VtParticipantesController::class, 'putGuardarInscripciones'])->middleware('auth.personal');
Route::post('participantes/inscribir-profesores', [VtParticipantesController::class, 'postInscribirProfesores'])->middleware('auth.personal');
// Devuelve la ficha completa de los docentes —documento, dirección, teléfono—.
Route::put('participantes/profesores', [VtParticipantesController::class, 'putProfesores'])->middleware('auth.personal');
Route::put('participantes/set-locked', [VtParticipantesController::class, 'putSetLocked'])->middleware('auth.personal');
Route::put('participantes/votantes', [VtParticipantesController::class, 'putVotantes'])->middleware('auth.personal');
Route::delete('participantes/destroy/{id}', [VtParticipantesController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtCandidatosController
// `VtCandidato::all()`, todos los candidatos de todos los años. La papeleta es
// `conaspiraciones`, que sí se acota, y ésta no la llama ningún cliente.
Route::get('candidatos', [VtCandidatosController::class, 'getIndex'])->middleware('auth.personal');
Route::get('candidatos/conaspiraciones', [VtCandidatosController::class, 'getConaspiraciones']);
// Inscribe como candidato al `user_id` que venga en el cuerpo, o sea a
// cualquiera. Su `destroy` ya llevaba guard.
Route::post('candidatos/store', [VtCandidatosController::class, 'postStore'])->middleware('auth.personal');
Route::delete('candidatos/destroy/{id}', [VtCandidatosController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtVotosController
// `VtVoto::all()`: 52 KB con todos los votos del colegio y el `user_id` de quien
// emitió cada uno. Es el voto secreto. No la llama ningún cliente — la pantalla
// de resultados usa `votos/show`, que se acota al que pregunta.
Route::get('votos', [VtVotosController::class, 'getIndex'])->middleware('auth.personal');
Route::put('votos/show', [VtVotosController::class, 'putShow']);
Route::post('votos/store', [VtVotosController::class, 'postStore']);
Route::delete('votos/destroy/{id}', [VtVotosController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('votos/update/{id}', [VtVotosController::class, 'putUpdate'])->middleware('auth.personal');
