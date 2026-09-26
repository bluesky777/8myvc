<?php

use App\Http\Controllers\Act\ActividadesController;
use App\Http\Controllers\Act\AprobarYCerrarController;
use App\Http\Controllers\Act\EditarConNotasController;
use App\Http\Controllers\Act\EntregasController;
use App\Http\Controllers\Act\IaController as ActIaController;
use App\Http\Controllers\Act\PreguntasController;
use App\Http\Controllers\Act\ResponderController;
use App\Http\Controllers\Act\ResultadosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: act — tareas, cuestionarios y encuestas (el módulo nuevo)
|--------------------------------------------------------------------------
|
| Contrato: `myvc_front/ACTIVIDADES-CONTRATO.md` §3. GET para leer, POST para acciones. El módulo
| viejo (`actividades/*`, `mis-actividades/*`) sigue en `actividades.php` sin cambiar de contrato, y
| sus listados ya no ven lo nuevo (`modo IS NULL`, §2.11).
|
| Guardas: `auth.personal` en lo que sólo hace quien crea (crear, editar, preguntas, publicar,
| resultados); el resto lo puede pedir cualquier sesión, y quién puede responder lo decide
| `App\Services\Act\Destinatarios`, no el tipo de usuario. Además, TODO lo del creador exige ser el
| dueño (`created_by`), y eso lo mira el controlador.
|
| Tandas 1, 2, 3 y 4 (la IA: `ia/actividades/*`, aquí y no en `ia.php`, ver
| `Act\IaController`). Faltan (en su tanda): recordar y calendario (5).
|
| Las rutas sin {parámetro} van antes que las que lo llevan.
|
*/
Route::post('ia/actividades/proponer', [ActIaController::class, 'postProponer'])->middleware('auth.personal');
Route::post('ia/actividades/mejorar', [ActIaController::class, 'postMejorar'])->middleware('auth.personal');

// ActividadesController: bandeja, catálogo, conteo, crear, guardar, leer, borrar, publicar.
Route::get('act/bandeja', [ActividadesController::class, 'getBandeja']);
Route::get('act/catalogo', [ActividadesController::class, 'getCatalogo'])->middleware('auth.personal');
Route::get('act/logros', [ActividadesController::class, 'getLogros'])->middleware('auth.personal');
Route::post('act/conteo', [ActividadesController::class, 'postConteo'])->middleware('auth.personal');
Route::post('act/crear', [ActividadesController::class, 'postCrear'])->middleware('auth.personal');
Route::get('act/para-duplicar', [ActividadesController::class, 'getParaDuplicar'])->middleware('auth.personal');

// EntregasController: el fichero de una entrega (antes que act/{id} para que no lo tape).
Route::get('act/archivos/{archivoId}', [EntregasController::class, 'getArchivo'])->where('archivoId', '[0-9]+');

// PreguntasController, las de {pid}.
Route::post('act/preguntas/{pid}/guardar', [PreguntasController::class, 'postGuardar'])->where('pid', '[0-9]+')->middleware('auth.personal');
Route::post('act/preguntas/{pid}/duplicar', [PreguntasController::class, 'postDuplicar'])->where('pid', '[0-9]+')->middleware('auth.personal');
Route::post('act/preguntas/{pid}/borrar', [PreguntasController::class, 'postBorrar'])->where('pid', '[0-9]+')->middleware('auth.personal');
Route::post('act/preguntas/{pid}/condiciones', [PreguntasController::class, 'postCondiciones'])->where('pid', '[0-9]+')->middleware('auth.personal');

// Las de {id}.
Route::get('act/{id}', [ActividadesController::class, 'getLeer'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/guardar', [ActividadesController::class, 'postGuardar'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/borrar', [ActividadesController::class, 'postBorrar'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/publicar', [ActividadesController::class, 'postPublicar'])->where('id', '[0-9]+')->middleware('auth.personal');

Route::post('act/{id}/preguntas', [PreguntasController::class, 'postCrear'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/preguntas/lote', [PreguntasController::class, 'postLote'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/preguntas/orden', [PreguntasController::class, 'postOrden'])->where('id', '[0-9]+')->middleware('auth.personal');

Route::get('act/{id}/responder', [ResponderController::class, 'getResponder'])->where('id', '[0-9]+');
Route::post('act/{id}/recorrido', [ResponderController::class, 'postRecorrido'])->where('id', '[0-9]+');
Route::post('act/{id}/borrador', [ResponderController::class, 'postBorrador'])->where('id', '[0-9]+');
Route::post('act/{id}/enviar', [ResponderController::class, 'postEnviar'])->where('id', '[0-9]+');
Route::get('act/{id}/mis-respuestas', [ResponderController::class, 'getMisRespuestas'])->where('id', '[0-9]+');

Route::post('act/{id}/archivo', [EntregasController::class, 'postArchivo'])->where('id', '[0-9]+');
Route::post('act/{id}/entregar', [EntregasController::class, 'postEntregar'])->where('id', '[0-9]+');

Route::get('act/{id}/resultados', [ResultadosController::class, 'getResultados'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::get('act/{id}/faltan', [ResultadosController::class, 'getFaltan'])->where('id', '[0-9]+')->middleware('auth.personal');

// Tanda 2: las entregas del docente y calificar (§3.9), y editar con notas (§3.11).
Route::get('act/{id}/entregas', [EntregasController::class, 'getEntregas'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/entregas/{alumnoId}/calificar', [EntregasController::class, 'postCalificar'])->where(['id' => '[0-9]+', 'alumnoId' => '[0-9]+'])->middleware('auth.personal');
Route::post('act/{id}/impacto', [EditarConNotasController::class, 'postImpacto'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/aplicar-cambios', [EditarConNotasController::class, 'postAplicarCambios'])->where('id', '[0-9]+')->middleware('auth.personal');

// Tanda 3: aprobar y rechazar (§3.6), cerrar, compartir y anonimato (§3.12), duplicar (§3.14) y la
// imagen de una pregunta o de sus opciones.
Route::post('act/{id}/aprobar', [AprobarYCerrarController::class, 'postAprobar'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/rechazar', [AprobarYCerrarController::class, 'postRechazar'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/cerrar', [AprobarYCerrarController::class, 'postCerrar'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/compartir', [AprobarYCerrarController::class, 'postCompartir'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/anonimato', [AprobarYCerrarController::class, 'postAnonimato'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/duplicar', [ActividadesController::class, 'postDuplicar'])->where('id', '[0-9]+')->middleware('auth.personal');
Route::post('act/{id}/imagen', [PreguntasController::class, 'postImagen'])->where('id', '[0-9]+')->middleware('auth.personal');
