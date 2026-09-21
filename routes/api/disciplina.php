<?php

use App\Http\Controllers\AusenciasController;
use App\Http\Controllers\ChangeAskedAssignmentController;
use App\Http\Controllers\ChangeAskedController;
use App\Http\Controllers\DefinicionesComportamientoController;
use App\Http\Controllers\Disciplina\ComportamientoController;
use App\Http\Controllers\Disciplina\DisciplinaController;
use App\Http\Controllers\Disciplina\OrdinalesController;
use App\Http\Controllers\Informes\PlanillasAusenciasController;
use App\Http\Controllers\MuroController;
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

// **El mismo muro, para la app.** Va pegada a su hermana a propósito: quien lea una
// tiene que ver la otra, porque la regla de esta pareja es que **`to-me` no se toca**
// —lo usa el panel del front web y ahí sí se pinta el calendario— y lo que la app
// necesita se añade aquí. Lo que se ahorra son 128 KB de `eventos` que Flutter tira
// sin mirar, y seis de las siete consultas por acudido. El porqué entero, con lo
// medido y con por qué NO se pudo hacer sin estrenar ruta, está en `MuroController`.
//
// Sin `auth.personal`: la piden alumnos y acudientes, que es de quien va el ahorro.
// Le basta el `auth.token` que la API pone por defecto a todo.
Route::get('muro/app', [MuroController::class, 'getApp']);
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

// Las faltas de UN alumno en UN año, para la citación al acudiente.
//
// **Ruta nueva y no un retoque de las siete de arriba**, y eso no es estilo: esta
// familia la comparte `myvc_flutter`, que es una sola app para los dieciséis colegios y
// cuya versión vieja convive con este backend durante meses. Es la misma razón por la
// que nivelar estrenó endpoints en vez de enseñarle a `notas/update`.
//
// Lo que sustituye es `GET planillas/ver-ausencias`, que devuelve **todos los grupos del
// año con todos sus alumnos y todos sus periodos** para citar a uno. O sea que esto no
// abre nada: es estrictamente menos, con el mismo `auth.personal`.
Route::put('ausencias/de-alumno', [AusenciasController::class, 'putDeAlumno'])->middleware('auth.personal');

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
// La ficha en modo lectura para el alumno y su familia. **No lleva
// `auth.personal`**, que es lo que cierra a Alumno y Acudiente el resto de esta
// sección: lleva la guarda de propiedad, que comprueba que el alumno pedido sea
// el suyo o el de un acudido. Sin paz y salvo a propósito — ver el método.
Route::get('disciplina/mis-fichas/{alumno_id?}', [DisciplinaController::class, 'getMisFichas'])
    ->middleware('boletin.propio:sin-paz-y-salvo');
Route::put('disciplina/alumnos', [DisciplinaController::class, 'putAlumnos'])->middleware('auth.personal');
Route::post('disciplina/asignar-ordinal', [DisciplinaController::class, 'postAsignarOrdinal'])->middleware('auth.personal');
Route::put('disciplina/cambiar-situacion-derivante', [DisciplinaController::class, 'putCambiarSituacionDerivante'])->middleware('auth.personal');
Route::put('disciplina/destroy', [DisciplinaController::class, 'putDestroy'])->middleware('auth.personal');
Route::put('disciplina/quitar-ordinal', [DisciplinaController::class, 'putQuitarOrdinal'])->middleware('auth.personal');
Route::post('disciplina/store', [DisciplinaController::class, 'postStore'])->middleware('auth.personal');
Route::put('disciplina/update', [DisciplinaController::class, 'putUpdate'])->middleware('auth.personal');
