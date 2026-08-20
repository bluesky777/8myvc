<?php

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
use Illuminate\Support\Facades\Route;

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
Route::post('areas', [AreasController::class, 'postIndex'])->middleware('auth.personal');
Route::put('areas/update-orden', [AreasController::class, 'putUpdateOrden'])->middleware('auth.personal');
Route::delete('areas/destroy/{id}', [AreasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('areas/update/{id}', [AreasController::class, 'putUpdate'])->middleware('auth.personal');

// MateriasController
Route::get('materias', [MateriasController::class, 'getIndex']);
Route::post('materias', [MateriasController::class, 'postIndex'])->middleware('auth.personal');
Route::put('materias/update-orden', [MateriasController::class, 'putUpdateOrden'])->middleware('auth.personal');
Route::delete('materias/destroy/{id}', [MateriasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('materias/update/{id}', [MateriasController::class, 'putUpdate'])->middleware('auth.personal');

// AsignaturasController
Route::get('asignaturas', [AsignaturasController::class, 'getIndex']);
Route::post('asignaturas', [AsignaturasController::class, 'postIndex'])->middleware('auth.personal');
Route::post('asignaturas/copiar', [AsignaturasController::class, 'postCopiar'])->middleware('auth.personal');
Route::put('asignaturas/datos-asignaturas', [AsignaturasController::class, 'putDatosAsignaturas'])->middleware('auth.personal');
Route::put('asignaturas/detalle-asignatura', [AsignaturasController::class, 'putDetalleAsignatura'])->middleware('auth.personal');
Route::get('asignaturas/listasignaturas-alone', [AsignaturasController::class, 'getListasignaturasAlone']);
Route::get('asignaturas/papelera', [AsignaturasController::class, 'getPapelera']);
Route::put('asignaturas/restaurar', [AsignaturasController::class, 'putRestaurar'])->middleware('auth.personal');
Route::put('asignaturas/toggle-dia', [AsignaturasController::class, 'putToggleDia'])->middleware('auth.personal');
Route::delete('asignaturas/destroy/{id}', [AsignaturasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('asignaturas/list-asignaturas-year/{profesor_id}/{periodo_id}', [AsignaturasController::class, 'getListAsignaturasYear'])->middleware('auth.personal');
Route::get('asignaturas/listasignaturas/{persona_id?}', [AsignaturasController::class, 'getListasignaturas'])->middleware('persona.propia');
Route::get('asignaturas/show/{asignatura_id}', [AsignaturasController::class, 'getShow']);
Route::put('asignaturas/update/{id}', [AsignaturasController::class, 'putUpdate'])->middleware('auth.personal');

// UnidadesController
Route::post('unidades', [UnidadesController::class, 'postIndex'])->middleware('auth.personal');
Route::put('unidades/de-profesor', [UnidadesController::class, 'putDeProfesor'])->middleware('auth.personal');
Route::get('unidades/trashed', [UnidadesController::class, 'getTrashed']);
Route::put('unidades/update-orden', [UnidadesController::class, 'putUpdateOrden'])->middleware('auth.personal');
Route::put('unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}', [UnidadesController::class, 'putDeAsignaturaPeriodo'])->middleware('auth.personal');
Route::get('unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}/{user?}', [UnidadesController::class, 'getDeAsignaturaPeriodo']);
Route::delete('unidades/destroy/{id}', [UnidadesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('unidades/eliminadas/{asignatura_id}', [UnidadesController::class, 'putEliminadas'])->middleware('auth.personal');
Route::delete('unidades/forcedelete/{id}', [UnidadesController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('unidades/restore/{id}', [UnidadesController::class, 'putRestore'])->middleware('auth.personal');
Route::put('unidades/update/{id}', [UnidadesController::class, 'putUpdate'])->middleware('auth.personal');

// SubunidadesController
Route::post('subunidades', [SubunidadesController::class, 'postIndex'])->middleware('auth.personal');
Route::get('subunidades/trashed', [SubunidadesController::class, 'getTrashed']);
Route::put('subunidades/update-orden', [SubunidadesController::class, 'putUpdateOrden'])->middleware('auth.personal');
Route::put('subunidades/update-orden-varias', [SubunidadesController::class, 'putUpdateOrdenVarias'])->middleware('auth.personal');
Route::delete('subunidades/destroy/{id}', [SubunidadesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('subunidades/eliminadas/{asignatura_id}', [SubunidadesController::class, 'putEliminadas'])->middleware('auth.personal');
Route::delete('subunidades/forcedelete/{id}', [SubunidadesController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('subunidades/restore/{id}', [SubunidadesController::class, 'putRestore'])->middleware('auth.personal');
Route::put('subunidades/update/{id}', [SubunidadesController::class, 'putUpdate'])->middleware('auth.personal');

// NotasController
Route::put('notas/alumno-periodo-grupo', [NotasController::class, 'putAlumnoPeriodoGrupo'])->middleware('auth.personal');
Route::put('notas/detailed', [NotasController::class, 'putDetailed'])->middleware('auth.personal');
Route::put('notas/subunidad', [NotasController::class, 'putSubunidad'])->middleware('auth.personal');
// Un alumno podía leer las notas de cualquier compañero cambiando el número de
// la URL, y un acudiente las de cualquier alumno del colegio. El modo `notas`
// del guard `sin-paz-y-salvo` comprueba la propiedad pero NO el paz y salvo, que hoy solo lo
// comprueba el navegador. Decisión pendiente del colegio: ver ExigirBoletinPropio.
Route::get('notas/alumno/{alumno_id?}/{grupo_id?}', [NotasController::class, 'getAlumno'])
    ->middleware('boletin.propio:sin-paz-y-salvo');
Route::delete('notas/destroy/{id}', [NotasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('notas/show/{nota_id}', [NotasController::class, 'getShow'])->middleware('auth.personal');
Route::put('notas/update/{id}', [NotasController::class, 'putUpdate'])->middleware('auth.personal');

// NotaComportamientoController
Route::get('nota_comportamiento', [NotaComportamientoController::class, 'getIndex']);
Route::put('nota_comportamiento/crear', [NotaComportamientoController::class, 'putCrear'])->middleware('auth.personal');
Route::put('nota_comportamiento/frases-check', [NotaComportamientoController::class, 'putFrasesCheck'])->middleware('auth.personal');
Route::put('nota_comportamiento/guardar-libro', [NotaComportamientoController::class, 'putGuardarLibro'])->middleware('auth.personal');
Route::post('nota_comportamiento/store', [NotaComportamientoController::class, 'postStore'])->middleware('auth.personal');
Route::delete('nota_comportamiento/destroy/{id}', [NotaComportamientoController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('nota_comportamiento/detailed/{grupo_id}', [NotaComportamientoController::class, 'getDetailed'])->middleware('auth.personal');
Route::put('nota_comportamiento/update/{id}', [NotaComportamientoController::class, 'putUpdate'])->middleware('auth.personal');

// DefinitivasPeriodosController
Route::get('definitivas_periodos', [DefinitivasPeriodosController::class, 'getIndex']);
Route::get('definitivas_periodos/arreglar-duplicados', [DefinitivasPeriodosController::class, 'getArreglarDuplicados']);
Route::put('definitivas_periodos/calcular-grupo-periodo', [DefinitivasPeriodosController::class, 'putCalcularGrupoPeriodo'])->middleware('auth.personal');
Route::put('definitivas_periodos/calcular-notas-finales-asignatura', [DefinitivasPeriodosController::class, 'putCalcularNotasFinalesAsignatura'])->middleware('auth.personal');
Route::put('definitivas_periodos/eliminar-recuperada', [DefinitivasPeriodosController::class, 'putEliminarRecuperada'])->middleware('auth.personal');
Route::put('definitivas_periodos/toggle-manual', [DefinitivasPeriodosController::class, 'putToggleManual'])->middleware('auth.personal');
Route::put('definitivas_periodos/toggle-recuperada', [DefinitivasPeriodosController::class, 'putToggleRecuperada'])->middleware('auth.personal');
Route::put('definitivas_periodos/update', [DefinitivasPeriodosController::class, 'putUpdate'])->middleware('auth.personal');
Route::put('definitivas_periodos/update-recuperacion', [DefinitivasPeriodosController::class, 'putUpdateRecuperacion'])->middleware('auth.personal');
Route::delete('definitivas_periodos/destroy/{id}', [DefinitivasPeriodosController::class, 'deleteDestroy'])->middleware('auth.personal');

// FrasesController
Route::get('frases', [FrasesController::class, 'getIndex']);
Route::post('frases/store', [FrasesController::class, 'postStore'])->middleware('auth.personal');
Route::delete('frases/destroy/{id}', [FrasesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('frases/update/{id}', [FrasesController::class, 'putUpdate'])->middleware('auth.personal');

// EscalasDeValoracionController
Route::get('escalas', [EscalasDeValoracionController::class, 'getIndex']);
Route::post('escalas/store', [EscalasDeValoracionController::class, 'postStore'])->middleware('auth.personal');
Route::put('escalas/update', [EscalasDeValoracionController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('escalas/destroy/{id}', [EscalasDeValoracionController::class, 'deleteDestroy'])->middleware('auth.personal');

// FrasesAsignaturaController
Route::delete('frases_asignatura/destroy/{id}', [FrasesAsignaturaController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('frases_asignatura/show/{alumno_id}/{asignatura_id}', [FrasesAsignaturaController::class, 'getShow'])->middleware('persona.propia');
Route::post('frases_asignatura/store/{frase_id?}', [FrasesAsignaturaController::class, 'postStore'])->middleware('auth.personal');

// BolfinalesController
//
// Los boletines finales son la misma familia que los de periodo: el front los
// pide con el mismo `requested_alumnos`, desde las mismas pantallas. Aquí nunca
// hubo comprobación escrita siquiera.
Route::put('bolfinales/cambiar-contador-certificados', [BolfinalesController::class, 'putCambiarContadorCertificados'])->middleware('auth.personal');
Route::put('bolfinales/cambiar-contador-folios', [BolfinalesController::class, 'putCambiarContadorFolios'])->middleware('auth.personal');
Route::put('bolfinales/detailed-notas-year-group/{grupo_id}', [BolfinalesController::class, 'putDetailedNotasYearGroup'])->middleware('boletin.propio');
Route::put('bolfinales/detailed-notas-year/{grupo_id}', [BolfinalesController::class, 'putDetailedNotasYear'])->middleware('boletin.propio');

// EditnotaController
Route::put('editnota/alum-asignatura', [EditnotaController::class, 'putAlumAsignatura'])->middleware('auth.personal');
Route::get('editnota/detailed-notas-year', [EditnotaController::class, 'getDetailedNotasYear']);
Route::get('editnota/trashed', [EditnotaController::class, 'getTrashed']);
Route::delete('editnota/destroy/{id}', [EditnotaController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('editnota/detailed-notas/{grupo_id}', [EditnotaController::class, 'putDetailedNotas'])->middleware('auth.personal');
Route::delete('editnota/forcedelete/{id}', [EditnotaController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('editnota/restore/{id}', [EditnotaController::class, 'putRestore'])->middleware('auth.personal');

// PlanillasController
Route::get('planillas/listas-personalizadas', [PlanillasController::class, 'getListasPersonalizadas']);
Route::get('planillas/ver-ausencias', [PlanillasController::class, 'getVerAusencias']);
Route::get('planillas/ver-simat', [PlanillasController::class, 'getVerSimat']);
Route::get('planillas/show-grupo/{grupo_id}', [PlanillasController::class, 'getShowGrupo'])->middleware('auth.personal');
Route::get('planillas/show-profesor/{profesor_id}', [PlanillasController::class, 'getShowProfesor'])->middleware('auth.personal');

// PiarsAsignaturasController
Route::put('piars-asignaturas/field', [PiarsAsignaturasController::class, 'putField'])->middleware('auth.personal');
Route::get('piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}', [PiarsAsignaturasController::class, 'getAsignaturas'])->middleware('persona.propia');
