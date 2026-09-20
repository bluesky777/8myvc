<?php

use App\Http\Controllers\Matriculas\AspirantesController;
use App\Http\Controllers\Matriculas\PortalInscripcionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| El portal de la familia y la bandeja del colegio
|--------------------------------------------------------------------------
|
| Contrato y porqués: `docs/migracion/47-el-portal-de-la-familia.md`.
| Las pantallas: `myvc_front/PANTALLAS-MATRICULA.md` (02, 04, 05, 06 del lado de
| la familia; 4, 5 y 6 del lado del colegio en `INVESTIGACION-MATRICULAS.md` §8).
|
| ## OCHO RUTAS, Y TRES SON PÚBLICAS — LAS DECIMOSÉPTIMA, DECIMOCTAVA Y DECIMONOVENA
|
| **Por el mismo motivo que las cuatro del formulario de inscripción**: quien llena
| esto es la familia de un aspirante que todavía no es alumno, **no tiene cuenta y no
| puede tenerla**. No hay guard de propiedad que aplicarle, así que la llave es el
| dato.
|
| Y aquí la llave es **doble**, que es lo que las distingue de sus cuatro hermanas: el
| código abre un formulario en blanco, pero en cuanto lleva dentro el nombre de un
| menor hace falta además **el documento del aspirante**. El porqué entero está en la
| cabecera de `PortalInscripcionController`, y en una línea: `GET
| colillas-inscripcion/{codigo}` fijó el 20 sep que *un código no puede revelar el
| nombre de un menor*, y este portal tiene que devolverlo para que la familia pueda
| seguir llenando lo que dejó a medias.
|
| ## TRES LIMITADORES DISTINTOS, Y NO ES EXCESO
|
| La clave de un limitador con nombre es `md5($limiterName.$limit->key)` — **sin el
| verbo y sin la ruta**—, así que dos rutas que compartan nombre **son un solo cubo**.
| Costó un fallo reproducido el 20 sep en la colilla, donde **preguntar consumía
| subidas** y la familia leía «puede mandar otro» justo antes de recibir un 429
| (41 §10).
|
| Aquí el `GET` comparte `consulta-inscripcion` con su hermana **a propósito y con el
| motivo escrito** —misma familia, mismo coste, mismo llamante: una familia
| refrescando— y las dos escrituras llevan cubo propio, porque escribir y preguntar no
| se parecen en nada.
|
| ## LO QUE MUEVE, contado y no supuesto
|
| Familia nueva `inscripcion/` con **0 de 3** en
| `familias-que-nunca-entran-en-el-candado.json`, que es la forma exacta que tendría
| un agujero — y se acepta **con el motivo escrito, nunca metiéndola en la lista de
| exclusiones**. Es el mismo renglón que `pagos-inscripcion: 0 de 2`, aceptado el 19
| sep por lo mismo. Lo que comprueba de quién es la fila no es un middleware: es el
| carácter de control del código **más** el segundo factor.
|
| `aspirantes/` entra como **5 de 5**: las cinco llevan `auth.personal` declarado.
*/

// --- La familia, sin cuenta ---------------------------------------------------

// **LOS MÉTODOS NO SE LLAMAN `getIndex` NI `putIndex`, Y ESO LO DECIDIÓ UN CANDADO.**
//
// `AutorizacionTest::test_ninguna_ruta_se_queda_sola_entre_sus_hermanas_de_operacion`
// agrupa las rutas **por el nombre del método** y delata a la que no lleva guard
// teniéndolo sus hermanas. Con `putIndex` esta ruta salía como *«4 de 5 con guard»*, o
// sea con la forma exacta de un agujero.
//
// El arreglo **no fue declararla como excepción**: fue llamarla `putFormulario`, que es
// lo que hace. Es literalmente lo mismo que le pasó a `postIndex` -> `postAcunar` el 19
// sep en la familia de al lado. *Un candado de consistencia diciendo la verdad sobre un
// nombre.*

// **`{codigo}/documento/{requisito_id}` va declarada ANTES que `{codigo}`**, y no es
// estilo: Laravel sirve la primera que casa. Aquí no hay colisión porque los tramos
// son distintos, pero el orden se conserva por la misma disciplina que salvó a
// `…/campos` de `…/{lote}` y a `…/pendientes` de `…/{codigo}` — dos veces en dos días,
// las dos veces con la bandeja de alguien tragada por un comodín.
Route::post('inscripcion/{codigo}/documento/{requisito_id}',
    [PortalInscripcionController::class, 'postDocumento'])
    ->where('requisito_id', '[0-9]+')
    ->withoutMiddleware('auth.token')->middleware('throttle:documento-inscripcion');

Route::get('inscripcion/{codigo}', [PortalInscripcionController::class, 'getEstado'])
    ->withoutMiddleware('auth.token')->middleware('throttle:consulta-inscripcion');

Route::put('inscripcion/{codigo}', [PortalInscripcionController::class, 'putFormulario'])
    ->withoutMiddleware('auth.token')->middleware('throttle:formulario-inscripcion');

// --- El colegio ---------------------------------------------------------------

// **Cuatro con `auth.personal` y nada dentro**, que es la decisión de Joseth del 20
// sep para todo el día de matrículas: *«cualquiera del personal puede cerrar, pero
// queda con su nombre y su hora»*.
//
// **Y una con el permiso dentro**: la decisión de admisión, con
// `Autoriza::puedeDecidirAdmision`. No es simetría rota — admitir no es un paso
// reversible que corrige el de al lado, es la respuesta del colegio a una familia, y
// viaja al portal en cuanto se escribe. El porqué, con las poblaciones, está en ese
// método.
Route::get('aspirantes', [AspirantesController::class, 'getIndex'])->middleware('auth.personal');

Route::get('aspirantes/{id}', [AspirantesController::class, 'getFicha'])
    ->where('id', '[0-9]+')->middleware('auth.personal');

Route::put('aspirantes/{id}/documento/{documento_id}', [AspirantesController::class, 'putDocumento'])
    ->where(['id' => '[0-9]+', 'documento_id' => '[0-9]+'])->middleware('auth.personal');

Route::put('aspirantes/{id}/cita', [AspirantesController::class, 'putCita'])
    ->where('id', '[0-9]+')->middleware('auth.personal');

Route::put('aspirantes/{id}/decision', [AspirantesController::class, 'putDecision'])
    ->where('id', '[0-9]+')->middleware('auth.personal');
