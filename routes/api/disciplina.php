<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AusenciasController;
use App\Http\Controllers\ChangeAskedAssignmentController;
use App\Http\Controllers\ChangeAskedController;
use App\Http\Controllers\DefinicionesComportamientoController;
use App\Http\Controllers\Disciplina\ComportamientoController;
use App\Http\Controllers\Disciplina\DisciplinaController;
use App\Http\Controllers\Disciplina\OrdinalesController;
use App\Http\Controllers\Informes\PlanillasAusenciasController;

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
Route::get('definiciones_comportamiento', [DefinicionesComportamientoController::class, 'getIndex'])->middleware('auth.token');
Route::post('definiciones_comportamiento/store', [DefinicionesComportamientoController::class, 'postStore'])->middleware('auth.token');
Route::post('definiciones_comportamiento/store-escrita', [DefinicionesComportamientoController::class, 'postStoreEscrita'])->middleware('auth.token');
Route::delete('definiciones_comportamiento/destroy/{id}', [DefinicionesComportamientoController::class, 'deleteDestroy'])->middleware('auth.token');

// ComportamientoController
Route::get('comportamiento', [ComportamientoController::class, 'getIndex']);
Route::put('comportamiento/observador-completo', [ComportamientoController::class, 'putObservadorCompleto']);
Route::put('comportamiento/observador-periodo', [ComportamientoController::class, 'putObservadorPeriodo']);
Route::put('comportamiento/situaciones-por-grupos', [ComportamientoController::class, 'putSituacionesPorGrupos']);

// ChangeAskedController
Route::put('ChangesAsked/aceptar-alumno', [ChangeAskedController::class, 'putAceptarAlumno']);
Route::put('ChangesAsked/aceptar-asignatura', [ChangeAskedController::class, 'putAceptarAsignatura']);
Route::put('ChangesAsked/destruir', [ChangeAskedController::class, 'putDestruir']);
Route::put('ChangesAsked/destruir-pedido-asignatura', [ChangeAskedController::class, 'putDestruirPedidoAsignatura']);
Route::put('ChangesAsked/rechazar', [ChangeAskedController::class, 'putRechazar']);
Route::put('ChangesAsked/solicitar-cambios', [ChangeAskedController::class, 'putSolicitarCambios']);
Route::get('ChangesAsked/to-me', [ChangeAskedController::class, 'getToMe']);
Route::put('ChangesAsked/ver-detalles', [ChangeAskedController::class, 'putVerDetalles']);

// ChangeAskedAssignmentController
Route::put('ChangesAskedAssignment/pedir-quitar-asignatura', [ChangeAskedAssignmentController::class, 'putPedirQuitarAsignatura']);
Route::put('ChangesAskedAssignment/solicitar-materia', [ChangeAskedAssignmentController::class, 'putSolicitarMateria']);
Route::put('ChangesAskedAssignment/ver-detalles', [ChangeAskedAssignmentController::class, 'putVerDetalles']);

// AusenciasController
Route::post('ausencias/agregar-ausencia', [AusenciasController::class, 'postAgregarAusencia']);
Route::post('ausencias/agregar-tardanza', [AusenciasController::class, 'postAgregarTardanza']);
Route::put('ausencias/cambiar-tipo-ausencia', [AusenciasController::class, 'putCambiarTipoAusencia']);
Route::put('ausencias/guardar-cambios-ausencia', [AusenciasController::class, 'putGuardarCambiosAusencia']);
Route::post('ausencias/store', [AusenciasController::class, 'postStore']);
Route::delete('ausencias/destroy/{id}', [AusenciasController::class, 'deleteDestroy']);
Route::get('ausencias/detailed/{asignatura_id}', [AusenciasController::class, 'getDetailed']);

// PlanillasAusenciasController
Route::put('planillas-ausencias/tardanza-entrada', [PlanillasAusenciasController::class, 'putTardanzaEntrada']);
Route::get('planillas-ausencias/show-profesor/{profesor_id}', [PlanillasAusenciasController::class, 'getShowProfesor']);

// OrdinalesController
Route::put('ordinales/destroy', [OrdinalesController::class, 'putDestroy']);
Route::put('ordinales/guardar-valor', [OrdinalesController::class, 'putGuardarValor']);
Route::put('ordinales/guardar-valor-config', [OrdinalesController::class, 'putGuardarValorConfig']);
Route::put('ordinales/ordinales', [OrdinalesController::class, 'putOrdinales']);
Route::post('ordinales/store', [OrdinalesController::class, 'postStore']);
Route::put('ordinales/update', [OrdinalesController::class, 'putUpdate']);

// DisciplinaController
Route::put('disciplina/alumnos', [DisciplinaController::class, 'putAlumnos']);
Route::post('disciplina/asignar-ordinal', [DisciplinaController::class, 'postAsignarOrdinal']);
Route::put('disciplina/cambiar-situacion-derivante', [DisciplinaController::class, 'putCambiarSituacionDerivante']);
Route::put('disciplina/destroy', [DisciplinaController::class, 'putDestroy']);
Route::put('disciplina/quitar-ordinal', [DisciplinaController::class, 'putQuitarOrdinal']);
Route::post('disciplina/store', [DisciplinaController::class, 'postStore']);
Route::put('disciplina/update', [DisciplinaController::class, 'putUpdate']);
