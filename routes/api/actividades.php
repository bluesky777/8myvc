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
// Es el lado del AUTOR: crear la actividad, editarla, compartirla con un grupo.
// El lado del alumno es `mis-actividades/*` y `respuestas/actividad`, más abajo.
// De los tres controladores de aquí solo llevaba guard el `destroy/{id}` de cada
// uno — las únicas que tienen `{id}`. Ver 05 §15.
Route::put('actividades/compartidas', [ActividadesController::class, 'putCompartidas'])->middleware('auth.personal');
Route::post('actividades/crear', [ActividadesController::class, 'postCrear'])->middleware('auth.personal');
Route::put('actividades/datos', [ActividadesController::class, 'putDatos'])->middleware('auth.personal');
Route::put('actividades/edicion', [ActividadesController::class, 'putEdicion'])->middleware('auth.personal');
Route::put('actividades/guardar', [ActividadesController::class, 'putGuardar'])->middleware('auth.personal');
Route::put('actividades/insert-grupo-compartido', [ActividadesController::class, 'putInsertGrupoCompartido'])->middleware('auth.personal');
Route::put('actividades/para-acudientes-toggle', [ActividadesController::class, 'putParaAcudientesToggle'])->middleware('auth.personal');
Route::put('actividades/para-alumnos-toggle', [ActividadesController::class, 'putParaAlumnosToggle'])->middleware('auth.personal');
Route::put('actividades/para-profesores-toggle', [ActividadesController::class, 'putParaProfesoresToggle'])->middleware('auth.personal');
Route::put('actividades/quitando-grupo-compartido', [ActividadesController::class, 'putQuitandoGrupoCompartido'])->middleware('auth.personal');
Route::put('actividades/set-compartida', [ActividadesController::class, 'putSetCompartida'])->middleware('auth.personal');
Route::delete('actividades/destroy/{id}', [ActividadesController::class, 'deleteDestroy'])->middleware('auth.personal');

// MisActividadesController
Route::put('mis-actividades/datos', [MisActividadesController::class, 'putDatos'])->middleware('persona.propia');
Route::put('mis-actividades/finalizar-actividad', [MisActividadesController::class, 'putFinalizarActividad']);
// Sobrescribe la actividad entera —descripción, duración, oportunidades, si está
// en acción—, que es la operación del profesor duplicada en el controlador del
// alumno. **No la llama ningún cliente**: lo dice el comentario del propio
// `MisActividadesApi.ts`, y la que usa el profesor es `actividades/guardar`.
// Además está rota: escribe `puntaje_por_promedio`, que no es una columna de
// `ws_actividades` — 500 seguro, como los cuatro de 05 §8. El guard es para el
// día que se arregle. Ver 05 §20.
Route::put('mis-actividades/guardar', [MisActividadesController::class, 'putGuardar'])->middleware('auth.personal');
Route::put('mis-actividades/mi-actividad', [MisActividadesController::class, 'putMiActividad']);
Route::put('mis-actividades/seleccionar-opcion', [MisActividadesController::class, 'putSeleccionarOpcion']);

// PreguntasController
Route::post('preguntas/crear', [PreguntasController::class, 'postCrear'])->middleware('auth.personal');
Route::put('preguntas/duplicar-pregunta', [PreguntasController::class, 'putDuplicarPregunta'])->middleware('auth.personal');
Route::put('preguntas/edicion', [PreguntasController::class, 'putEdicion'])->middleware('auth.personal');
Route::put('preguntas/guardar', [PreguntasController::class, 'putGuardar'])->middleware('auth.personal');
Route::put('preguntas/toggle-opcion-otra', [PreguntasController::class, 'putToggleOpcionOtra'])->middleware('auth.personal');
Route::put('preguntas/update-orden', [PreguntasController::class, 'putUpdateOrden'])->middleware('auth.personal');
Route::delete('preguntas/destroy/{id}', [PreguntasController::class, 'deleteDestroy'])->middleware('auth.personal');

// OpcionesController
Route::put('opciones/add-opcion', [OpcionesController::class, 'putAddOpcion'])->middleware('auth.personal');
Route::put('opciones/guardar', [OpcionesController::class, 'putGuardar'])->middleware('auth.personal');
Route::put('opciones/set-opcion-correct', [OpcionesController::class, 'putSetOpcionCorrect'])->middleware('auth.personal');
Route::delete('opciones/destroy/{id}', [OpcionesController::class, 'deleteDestroy'])->middleware('auth.personal');

// RespuestasController
Route::put('respuestas/actividad', [RespuestasController::class, 'putActividad']);
