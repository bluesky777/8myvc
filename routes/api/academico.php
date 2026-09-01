<?php

use App\Http\Controllers\AreasController;
use App\Http\Controllers\AsignaturasController;
use App\Http\Controllers\BoletinIndependienteController;
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
// La papelera del año, con el nombre del profesor de cada asignatura borrada. Es
// una pantalla de administración y el resto de su familia ya lo era. Ver 05 §16.
Route::get('asignaturas/papelera', [AsignaturasController::class, 'getPapelera'])->middleware('auth.personal');
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
// 29 KB con la papelera académica del colegio entero. No lleva el dato personal
// de nadie, y por eso el barrido no la vio. Ver 05 §16.
Route::get('unidades/trashed', [UnidadesController::class, 'getTrashed'])->middleware('auth.personal');
Route::put('unidades/update-orden', [UnidadesController::class, 'putUpdateOrden'])->middleware('auth.personal');
Route::put('unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}', [UnidadesController::class, 'putDeAsignaturaPeriodo'])->middleware('auth.personal');
// Es la única de `unidades/*` que no lo llevaba, y **escribe**: cuando la
// asignatura y el periodo no tienen unidades, las crea a partir de las del año
// —con `created_by` del que pregunta—. Un alumno y un acudiente creaban unidades
// y subunidades con un GET. Ver 05 §16.
Route::get('unidades/de-asignatura-periodo/{asignatura_id}/{periodo_id}/{user?}', [UnidadesController::class, 'getDeAsignaturaPeriodo'])->middleware('auth.personal');
Route::delete('unidades/destroy/{id}', [UnidadesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('unidades/eliminadas/{asignatura_id}', [UnidadesController::class, 'putEliminadas'])->middleware('auth.personal');
Route::delete('unidades/forcedelete/{id}', [UnidadesController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('unidades/restore/{id}', [UnidadesController::class, 'putRestore'])->middleware('auth.personal');
Route::put('unidades/update/{id}', [UnidadesController::class, 'putUpdate'])->middleware('auth.personal');

// SubunidadesController
Route::post('subunidades', [SubunidadesController::class, 'postIndex'])->middleware('auth.personal');
// El nombre miente: no devuelve subunidades sino los ALUMNOS BORRADOS del
// colegio, con documento, fecha de nacimiento, celular y dirección. Salía vacía
// en el seed porque ahí no hay alumnos borrados. Ver 05 §16.
Route::get('subunidades/trashed', [SubunidadesController::class, 'getTrashed'])->middleware('auth.personal');
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
// Va aquí y no abajo por la regla de la cabecera: sin parámetros antes que con
// ellos, para que `notas/{algo}` no la tape.
Route::put('notas/lote', [NotasController::class, 'putLote'])->middleware('auth.personal');
// Un alumno podía leer las notas de cualquier compañero cambiando el número de
// la URL, y un acudiente las de cualquier alumno del colegio. El modo `notas`
// del guard `sin-paz-y-salvo` comprueba la propiedad pero NO el paz y salvo, que hoy solo lo
// comprueba el navegador. Decisión pendiente del colegio: ver ExigirBoletinPropio.
Route::get('notas/alumno/{alumno_id?}/{grupo_id?}', [NotasController::class, 'getAlumno'])
    ->middleware('boletin.propio:sin-paz-y-salvo');
Route::delete('notas/destroy/{id}', [NotasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('notas/show/{nota_id}', [NotasController::class, 'getShow'])->middleware('auth.personal');
Route::put('notas/update/{id}', [NotasController::class, 'putUpdate'])->middleware('auth.personal');

// BoletinIndependienteController
//
// La ruta 545, y es la ÚNICA escritura de la marca del boletín independiente
// (19-boletin-independiente.md §6.3, fase 2). Nace con la decisión 7 del 31 ago
// 2026: la marca es **por periodo**, así que dejó de caber como un `case` de
// `alumnos/guardar-valor` —aquello escribe columnas de `matriculas` y esto ya no
// es una columna de `matriculas`—.
//
// **`auth.personal` no basta y por eso no está solo aquí**: un docente es
// personal. La decisión 5 dice administradores, secretario y rector, con el
// superusuario por encima, y explícitamente **NO el titular del grupo**, que hoy
// sí escribe la rama de matrícula de `GuardarAlumno::valor`. Es más estrecha que
// lo de hoy, así que va donde puede mirar a los roles: `Autoriza`. El guard de la
// ruta deja fuera a alumnos y acudientes, que es lo que un middleware puede
// contestar sin consultar la tabla de roles.
Route::put('boletin-independiente/periodo', [BoletinIndependienteController::class, 'putPeriodo'])->middleware('auth.personal');

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
// La misma consulta copiada que `subunidades/trashed`: alumnos borrados con sus
// datos personales, no notas editadas. Ver 05 §16.
Route::get('editnota/trashed', [EditnotaController::class, 'getTrashed'])->middleware('auth.personal');
Route::delete('editnota/destroy/{id}', [EditnotaController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('editnota/detailed-notas/{grupo_id}', [EditnotaController::class, 'putDetailedNotas'])->middleware('auth.personal');
Route::delete('editnota/forcedelete/{id}', [EditnotaController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('editnota/restore/{id}', [EditnotaController::class, 'putRestore'])->middleware('auth.personal');

// PlanillasController
// Las tres primeras NO piden grupo: devuelven todos los del año con la ficha
// completa de cada alumno —documento, EPS, tipo de sangre, teléfono, dirección—.
// Iban sin guard porque no nombran a nadie, que es el punto ciego de
// docs/migracion/05-codigo-muerto-y-roto.md §14. Las tres cuelgan de
// `panel.informes`, que es pantalla de personal.
Route::get('planillas/listas-personalizadas', [PlanillasController::class, 'getListasPersonalizadas'])->middleware('auth.personal');
Route::get('planillas/ver-ausencias', [PlanillasController::class, 'getVerAusencias'])->middleware('auth.personal');
Route::get('planillas/ver-simat', [PlanillasController::class, 'getVerSimat'])->middleware('auth.personal');
Route::get('planillas/show-grupo/{grupo_id}', [PlanillasController::class, 'getShowGrupo'])->middleware('auth.personal');
Route::get('planillas/show-profesor/{profesor_id}', [PlanillasController::class, 'getShowProfesor'])->middleware('auth.personal');

// PiarsAsignaturasController
Route::put('piars-asignaturas/field', [PiarsAsignaturasController::class, 'putField'])->middleware('auth.personal');
Route::get('piars-asignaturas/asignaturas/{grupo_id}/{alumno_id}', [PiarsAsignaturasController::class, 'getAsignaturas'])->middleware('persona.propia');
