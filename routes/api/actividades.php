<?php

use App\Http\Controllers\Actividades\ActividadesController;
use App\Http\Controllers\Actividades\MisActividadesController;
use App\Http\Controllers\Actividades\OpcionesController;
use App\Http\Controllers\Actividades\PreguntasController;
use App\Http\Controllers\Actividades\RespuestasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: actividades
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// ActividadesController
Route::put('actividades/compartidas', [ActividadesController::class, 'putCompartidas']);
Route::post('actividades/crear', [ActividadesController::class, 'postCrear']);
Route::put('actividades/datos', [ActividadesController::class, 'putDatos']);
Route::put('actividades/edicion', [ActividadesController::class, 'putEdicion']);
Route::put('actividades/guardar', [ActividadesController::class, 'putGuardar']);
Route::put('actividades/insert-grupo-compartido', [ActividadesController::class, 'putInsertGrupoCompartido']);
Route::put('actividades/para-acudientes-toggle', [ActividadesController::class, 'putParaAcudientesToggle']);
Route::put('actividades/para-alumnos-toggle', [ActividadesController::class, 'putParaAlumnosToggle']);
Route::put('actividades/para-profesores-toggle', [ActividadesController::class, 'putParaProfesoresToggle']);
Route::put('actividades/quitando-grupo-compartido', [ActividadesController::class, 'putQuitandoGrupoCompartido']);
Route::put('actividades/set-compartida', [ActividadesController::class, 'putSetCompartida']);
Route::delete('actividades/destroy/{id}', [ActividadesController::class, 'deleteDestroy'])->middleware('auth.personal');

// MisActividadesController
Route::put('mis-actividades/datos', [MisActividadesController::class, 'putDatos'])->middleware('persona.propia');
Route::put('mis-actividades/finalizar-actividad', [MisActividadesController::class, 'putFinalizarActividad']);
Route::put('mis-actividades/guardar', [MisActividadesController::class, 'putGuardar']);
Route::put('mis-actividades/mi-actividad', [MisActividadesController::class, 'putMiActividad']);
Route::put('mis-actividades/seleccionar-opcion', [MisActividadesController::class, 'putSeleccionarOpcion']);

// PreguntasController
Route::post('preguntas/crear', [PreguntasController::class, 'postCrear']);
Route::put('preguntas/duplicar-pregunta', [PreguntasController::class, 'putDuplicarPregunta']);
Route::put('preguntas/edicion', [PreguntasController::class, 'putEdicion']);
Route::put('preguntas/guardar', [PreguntasController::class, 'putGuardar']);
Route::put('preguntas/toggle-opcion-otra', [PreguntasController::class, 'putToggleOpcionOtra']);
Route::put('preguntas/update-orden', [PreguntasController::class, 'putUpdateOrden']);
Route::delete('preguntas/destroy/{id}', [PreguntasController::class, 'deleteDestroy'])->middleware('auth.personal');

// OpcionesController
Route::put('opciones/add-opcion', [OpcionesController::class, 'putAddOpcion']);
Route::put('opciones/guardar', [OpcionesController::class, 'putGuardar']);
Route::put('opciones/set-opcion-correct', [OpcionesController::class, 'putSetOpcionCorrect']);
Route::delete('opciones/destroy/{id}', [OpcionesController::class, 'deleteDestroy'])->middleware('auth.personal');

// RespuestasController
Route::put('respuestas/actividad', [RespuestasController::class, 'putActividad']);
