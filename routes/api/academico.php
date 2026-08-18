<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AreasController;
use App\Http\Controllers\AsignaturasController;
use App\Http\Controllers\DefinitivasPeriodosController;
use App\Http\Controllers\EditnotaController;
use App\Http\Controllers\EscalasDeValoracionController;
use App\Http\Controllers\FrasesAsignaturaController;
use App\Http\Controllers\FrasesController;
use App\Http\Controllers\Informes\BolfinalesController;
use App\Http\Controllers\MateriasController;
use App\Http\Controllers\NotaComportamientoController;
use App\Http\Controllers\NotasController;
use App\Http\Controllers\Piars\PiarsAsignaturasController;
use App\Http\Controllers\PlanillasController;
use App\Http\Controllers\SubunidadesController;
use App\Http\Controllers\UnidadesController;

/*
|--------------------------------------------------------------------------
| Rutas: academico
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// AreasController
Route::get('areas', [AreasController::class, 'getIndex']);
Route::post('areas', [AreasController::class, 'postIndex']);
Route::put('areas/update-orden', [AreasController::class, 'putUpdateOrden']);
Route::delete('areas/destroy/{id}', [AreasController::class, 'deleteDestroy']);
Route::put('areas/update/{id}', [AreasController::class, 'putUpdate']);

// MateriasController
Route::get('materias', [MateriasController::class, 'getIndex']);
Route::post('materias', [MateriasController::class, 'postIndex']);
Route::put('materias/update-orden', [MateriasController::class, 'putUpdateOrden']);
Route::delete('materias/destroy/{id}', [MateriasController::class, 'deleteDestroy']);
Route::put('materias/update/{id}', [MateriasController::class, 'putUpdate']);

// AsignaturasController
Route::get('asignaturas', [AsignaturasController::class, 'getIndex']);
Route::post('asignaturas', [AsignaturasController::class, 'postIndex']);
Route::post('asignaturas/copiar', [AsignaturasController::class, 'postCopiar']);
Route::put('asignaturas/datos-asignaturas', [AsignaturasController::class, 'putDatosAsignaturas']);
Route::put('asignaturas/detalle-asignatura', [AsignaturasController::class, 'putDetalleAsignatura']);
Route::get('asignaturas/listasignaturas-alone', [AsignaturasController::class, 'getListasignaturasAlone']);
Route::get('asignaturas/papelera', [AsignaturasController::class, 'getPapelera']);
Route::put('asignaturas/restaurar', [AsignaturasController::class, 'putRestaurar']);
Route::put('asignaturas/toggle-dia', [AsignaturasController::class, 'putToggleDia']);
Route::delete('asignaturas/destroy/{id}', [AsignaturasController::class, 'deleteDestroy']);
Route::get('asignaturas/list-asignaturas-year/{profesor_id}/{periodo_id}', [AsignaturasController::class, 'getListAsignaturasYear']);
Route::get('asignaturas/listasignaturas/{persona_id?}', [AsignaturasController::class, 'getListasignaturas']);
Route::get('asignaturas/show/{asignatura_id}', [AsignaturasController::class, 'getShow']);
Route::put('asignaturas/update/{id}', [AsignaturasController::class, 'putUpdate']);

// UnidadesController
Route::post('unidades', [UnidadesController::class, 'postIndex']);
Route::put('unidades/de-profesor', [UnidadesController::class, 'putDeProfesor']);
Route::get('unidades/trashed', [UnidadesController::class, 'getTrashed']);
Route::put('unidades/update-orden', [UnidadesController::class, 'putUpdateOrden']);
Route::put('unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}', [UnidadesController::class, 'putDeAsignaturaPeriodo']);
Route::get('unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}/{user?}', [UnidadesController::class, 'getDeAsignaturaPeriodo']);
Route::delete('unidades/destroy/{id}', [UnidadesController::class, 'deleteDestroy']);
Route::put('unidades/eliminadas/{asignatura_id}', [UnidadesController::class, 'putEliminadas']);
Route::delete('unidades/forcedelete/{id}', [UnidadesController::class, 'deleteForcedelete']);
Route::put('unidades/restore/{id}', [UnidadesController::class, 'putRestore']);
Route::put('unidades/update/{id}', [UnidadesController::class, 'putUpdate']);

// SubunidadesController
Route::post('subunidades', [SubunidadesController::class, 'postIndex']);
Route::get('subunidades/trashed', [SubunidadesController::class, 'getTrashed']);
Route::put('subunidades/update-orden', [SubunidadesController::class, 'putUpdateOrden']);
Route::put('subunidades/update-orden-varias', [SubunidadesController::class, 'putUpdateOrdenVarias']);
Route::delete('subunidades/destroy/{id}', [SubunidadesController::class, 'deleteDestroy']);
Route::put('subunidades/eliminadas/{asignatura_id}', [SubunidadesController::class, 'putEliminadas']);
Route::delete('subunidades/forcedelete/{id}', [SubunidadesController::class, 'deleteForcedelete']);
Route::put('subunidades/restore/{id}', [SubunidadesController::class, 'putRestore']);
Route::put('subunidades/update/{id}', [SubunidadesController::class, 'putUpdate']);

// NotasController
Route::put('notas/alumno-periodo-grupo', [NotasController::class, 'putAlumnoPeriodoGrupo']);
Route::put('notas/detailed', [NotasController::class, 'putDetailed']);
Route::put('notas/subunidad', [NotasController::class, 'putSubunidad']);
Route::get('notas/alumno/{alumno_id?}/{grupo_id?}', [NotasController::class, 'getAlumno']);
Route::delete('notas/destroy/{id}', [NotasController::class, 'deleteDestroy']);
Route::get('notas/show/{nota_id}', [NotasController::class, 'getShow']);
Route::put('notas/update/{id}', [NotasController::class, 'putUpdate']);

// NotaComportamientoController
Route::get('nota_comportamiento', [NotaComportamientoController::class, 'getIndex']);
Route::put('nota_comportamiento/crear', [NotaComportamientoController::class, 'putCrear']);
Route::put('nota_comportamiento/frases-check', [NotaComportamientoController::class, 'putFrasesCheck']);
Route::put('nota_comportamiento/guardar-libro', [NotaComportamientoController::class, 'putGuardarLibro']);
Route::post('nota_comportamiento/store', [NotaComportamientoController::class, 'postStore']);
Route::delete('nota_comportamiento/destroy/{id}', [NotaComportamientoController::class, 'deleteDestroy']);
Route::get('nota_comportamiento/detailed/{grupo_id}', [NotaComportamientoController::class, 'getDetailed']);
Route::put('nota_comportamiento/update/{id}', [NotaComportamientoController::class, 'putUpdate']);

// DefinitivasPeriodosController
Route::get('definitivas_periodos', [DefinitivasPeriodosController::class, 'getIndex']);
Route::get('definitivas_periodos/arreglar-duplicados', [DefinitivasPeriodosController::class, 'getArreglarDuplicados']);
Route::put('definitivas_periodos/calcular-grupo-periodo', [DefinitivasPeriodosController::class, 'putCalcularGrupoPeriodo']);
Route::put('definitivas_periodos/calcular-notas-finales-asignatura', [DefinitivasPeriodosController::class, 'putCalcularNotasFinalesAsignatura']);
Route::put('definitivas_periodos/eliminar-recuperada', [DefinitivasPeriodosController::class, 'putEliminarRecuperada']);
Route::put('definitivas_periodos/toggle-manual', [DefinitivasPeriodosController::class, 'putToggleManual']);
Route::put('definitivas_periodos/toggle-recuperada', [DefinitivasPeriodosController::class, 'putToggleRecuperada']);
Route::put('definitivas_periodos/update', [DefinitivasPeriodosController::class, 'putUpdate']);
Route::put('definitivas_periodos/update-recuperacion', [DefinitivasPeriodosController::class, 'putUpdateRecuperacion']);
Route::delete('definitivas_periodos/destroy/{id}', [DefinitivasPeriodosController::class, 'deleteDestroy']);

// FrasesController
Route::get('frases', [FrasesController::class, 'getIndex']);
Route::post('frases/store', [FrasesController::class, 'postStore']);
Route::delete('frases/destroy/{id}', [FrasesController::class, 'deleteDestroy']);
Route::put('frases/update/{id}', [FrasesController::class, 'putUpdate']);

// EscalasDeValoracionController
Route::get('escalas', [EscalasDeValoracionController::class, 'getIndex']);
Route::post('escalas/store', [EscalasDeValoracionController::class, 'postStore']);
Route::put('escalas/update', [EscalasDeValoracionController::class, 'putUpdate']);
Route::delete('escalas/destroy/{id}', [EscalasDeValoracionController::class, 'deleteDestroy']);

// FrasesAsignaturaController
Route::delete('frases_asignatura/destroy/{id}', [FrasesAsignaturaController::class, 'deleteDestroy']);
Route::get('frases_asignatura/show/{alumno_id}/{asignatura_id}', [FrasesAsignaturaController::class, 'getShow']);
Route::post('frases_asignatura/store/{frase_id?}', [FrasesAsignaturaController::class, 'postStore']);

// BolfinalesController
Route::put('bolfinales/cambiar-contador-certificados', [BolfinalesController::class, 'putCambiarContadorCertificados']);
Route::put('bolfinales/cambiar-contador-folios', [BolfinalesController::class, 'putCambiarContadorFolios']);
Route::put('bolfinales/detailed-notas-year-group/{grupo_id}', [BolfinalesController::class, 'putDetailedNotasYearGroup']);
Route::put('bolfinales/detailed-notas-year/{grupo_id}', [BolfinalesController::class, 'putDetailedNotasYear']);

// EditnotaController
Route::put('editnota/alum-asignatura', [EditnotaController::class, 'putAlumAsignatura']);
Route::get('editnota/detailed-notas-year', [EditnotaController::class, 'getDetailedNotasYear']);
Route::get('editnota/trashed', [EditnotaController::class, 'getTrashed']);
Route::delete('editnota/destroy/{id}', [EditnotaController::class, 'deleteDestroy']);
Route::put('editnota/detailed-notas/{grupo_id}', [EditnotaController::class, 'putDetailedNotas']);
Route::delete('editnota/forcedelete/{id}', [EditnotaController::class, 'deleteForcedelete']);
Route::put('editnota/restore/{id}', [EditnotaController::class, 'putRestore']);

// PlanillasController
Route::get('planillas/listas-personalizadas', [PlanillasController::class, 'getListasPersonalizadas']);
Route::get('planillas/ver-ausencias', [PlanillasController::class, 'getVerAusencias']);
Route::get('planillas/ver-simat', [PlanillasController::class, 'getVerSimat']);
Route::get('planillas/show-grupo/{grupo_id}', [PlanillasController::class, 'getShowGrupo']);
Route::get('planillas/show-profesor/{profesor_id}', [PlanillasController::class, 'getShowProfesor']);

// PiarsAsignaturasController
Route::put('piars-asignaturas/field', [PiarsAsignaturasController::class, 'putField']);
Route::get('piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}', [PiarsAsignaturasController::class, 'getAsignaturas']);
