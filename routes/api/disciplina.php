<?php

use App\Http\Controllers\AusenciasController;
use App\Http\Controllers\ChangeAskedAssignmentController;
use App\Http\Controllers\ChangeAskedController;
use App\Http\Controllers\DefinicionesComportamientoController;
use App\Http\Controllers\Disciplina\ComportamientoController;
use App\Http\Controllers\Disciplina\DisciplinaController;
use App\Http\Controllers\Disciplina\OrdinalesController;
use App\Http\Controllers\Informes\PlanillasAusenciasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: disciplina
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// DefinicionesComportamientoController
Route::get('definiciones_comportamiento', [DefinicionesComportamientoController::class, 'getIndex']);
Route::post('definiciones_comportamiento/store', [DefinicionesComportamientoController::class, 'postStore'])->middleware('auth.personal');
Route::post('definiciones_comportamiento/store-escrita', [DefinicionesComportamientoController::class, 'postStoreEscrita'])->middleware('auth.personal');
Route::delete('definiciones_comportamiento/destroy/{id}', [DefinicionesComportamientoController::class, 'deleteDestroy'])->middleware('auth.personal');

// ComportamientoController
Route::get('comportamiento', [ComportamientoController::class, 'getIndex']);
Route::put('comportamiento/observador-completo', [ComportamientoController::class, 'putObservadorCompleto'])->middleware('auth.personal');
Route::put('comportamiento/observador-periodo', [ComportamientoController::class, 'putObservadorPeriodo'])->middleware('auth.personal');
Route::put('comportamiento/situaciones-por-grupos', [ComportamientoController::class, 'putSituacionesPorGrupos'])->middleware('auth.personal');

// ChangeAskedController
Route::put('ChangesAsked/aceptar-alumno', [ChangeAskedController::class, 'putAceptarAlumno'])->middleware('auth.personal');
Route::put('ChangesAsked/aceptar-asignatura', [ChangeAskedController::class, 'putAceptarAsignatura'])->middleware('auth.personal');
Route::put('ChangesAsked/destruir', [ChangeAskedController::class, 'putDestruir'])->middleware('auth.personal');
Route::put('ChangesAsked/destruir-pedido-asignatura', [ChangeAskedController::class, 'putDestruirPedidoAsignatura'])->middleware('auth.personal');
Route::put('ChangesAsked/rechazar', [ChangeAskedController::class, 'putRechazar'])->middleware('auth.personal');
Route::put('ChangesAsked/solicitar-cambios', [ChangeAskedController::class, 'putSolicitarCambios'])->middleware('auth.personal');
Route::get('ChangesAsked/to-me', [ChangeAskedController::class, 'getToMe']);
Route::put('ChangesAsked/ver-detalles', [ChangeAskedController::class, 'putVerDetalles'])->middleware('auth.personal');

// ChangeAskedAssignmentController
Route::put('ChangesAskedAssignment/pedir-quitar-asignatura', [ChangeAskedAssignmentController::class, 'putPedirQuitarAsignatura'])->middleware('auth.personal');
Route::put('ChangesAskedAssignment/solicitar-materia', [ChangeAskedAssignmentController::class, 'putSolicitarMateria'])->middleware('auth.personal');
Route::put('ChangesAskedAssignment/ver-detalles', [ChangeAskedAssignmentController::class, 'putVerDetalles'])->middleware('auth.personal');

// AusenciasController
Route::post('ausencias/agregar-ausencia', [AusenciasController::class, 'postAgregarAusencia'])->middleware('auth.personal');
Route::post('ausencias/agregar-tardanza', [AusenciasController::class, 'postAgregarTardanza'])->middleware('auth.personal');
Route::put('ausencias/cambiar-tipo-ausencia', [AusenciasController::class, 'putCambiarTipoAusencia'])->middleware('auth.personal');
Route::put('ausencias/guardar-cambios-ausencia', [AusenciasController::class, 'putGuardarCambiosAusencia'])->middleware('auth.personal');
Route::post('ausencias/store', [AusenciasController::class, 'postStore'])->middleware('auth.personal');
Route::delete('ausencias/destroy/{id}', [AusenciasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('ausencias/detailed/{asignatura_id}', [AusenciasController::class, 'getDetailed'])->middleware('auth.personal');

// PlanillasAusenciasController
Route::put('planillas-ausencias/tardanza-entrada', [PlanillasAusenciasController::class, 'putTardanzaEntrada'])->middleware('auth.personal');
Route::get('planillas-ausencias/show-profesor/{profesor_id}', [PlanillasAusenciasController::class, 'getShowProfesor'])->middleware('auth.personal');

// OrdinalesController
Route::put('ordinales/destroy', [OrdinalesController::class, 'putDestroy'])->middleware('auth.personal');
Route::put('ordinales/guardar-valor', [OrdinalesController::class, 'putGuardarValor'])->middleware('auth.personal');
Route::put('ordinales/guardar-valor-config', [OrdinalesController::class, 'putGuardarValorConfig'])->middleware('auth.personal');
Route::put('ordinales/ordinales', [OrdinalesController::class, 'putOrdinales'])->middleware('auth.personal');
Route::post('ordinales/store', [OrdinalesController::class, 'postStore'])->middleware('auth.personal');
Route::put('ordinales/update', [OrdinalesController::class, 'putUpdate'])->middleware('auth.personal');

// DisciplinaController
Route::put('disciplina/alumnos', [DisciplinaController::class, 'putAlumnos'])->middleware('auth.personal');
Route::post('disciplina/asignar-ordinal', [DisciplinaController::class, 'postAsignarOrdinal'])->middleware('auth.personal');
Route::put('disciplina/cambiar-situacion-derivante', [DisciplinaController::class, 'putCambiarSituacionDerivante'])->middleware('auth.personal');
Route::put('disciplina/destroy', [DisciplinaController::class, 'putDestroy'])->middleware('auth.personal');
Route::put('disciplina/quitar-ordinal', [DisciplinaController::class, 'putQuitarOrdinal'])->middleware('auth.personal');
Route::post('disciplina/store', [DisciplinaController::class, 'postStore'])->middleware('auth.personal');
Route::put('disciplina/update', [DisciplinaController::class, 'putUpdate'])->middleware('auth.personal');
