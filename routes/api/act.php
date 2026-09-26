<?php

use App\Http\Controllers\Act\ActividadesController;
use App\Http\Controllers\Act\EntregasController;
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
| Tanda 1. Faltan (en su tanda): logros, entregas del docente y calificar, impacto y
| aplicar-cambios (2); aprobar, rechazar, cerrar, compartir, anonimato, duplicar (3); recordar y
| calendario (5).
|
| Las rutas sin {parámetro} van antes que las que lo llevan.
|
*/

// ActividadesController: bandeja, catálogo, conteo, crear, guardar, leer, borrar, publicar.
Route::get('act/bandeja', [ActividadesController::class, 'getBandeja']);
Route::get('act/catalogo', [ActividadesController::class, 'getCatalogo'])->middleware('auth.personal');
Route::post('act/conteo', [ActividadesController::class, 'postConteo'])->middleware('auth.personal');
Route::post('act/crear', [ActividadesController::class, 'postCrear'])->middleware('auth.personal');

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
