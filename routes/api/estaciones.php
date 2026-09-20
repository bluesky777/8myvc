<?php

use App\Http\Controllers\Matriculas\EstacionesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Las estaciones del día de matrículas, atendidas desde la app
|--------------------------------------------------------------------------
|
| Contrato y porqués: `docs/migracion/46-las-estaciones-en-la-app.md`.
| Las doce pantallas de teléfono: `myvc_flutter/docs/estaciones.md`.
|
| ## SON NUEVE Y EL CONTRATO DECÍA OCHO, y se cuenta y se dice
|
| La novena es `PUT estaciones/nota/{id}/resuelta`. El 46 §3.3 describe **quién
| puede dar por resuelta una nota pendiente** y `estaciones.md` §2.10 describe **el
| botón que lo hace**, pero ninguna de las ocho escribía `resuelta_por` ni
| `resuelta_at`: el contrato creaba dos columnas que no escribe nadie y un permiso
| que no gobierna nada. Es `profesores.tono`, esta vez dentro del documento que lo
| cita como error. El porqué entero está en el docblock del método.
|
| ## LAS NUEVE CON `auth.personal` Y UNA SOLA CON CANDADO DENTRO
|
| **Decidido por Joseth el 20 sep 2026**: *«cualquiera del personal puede cerrar,
| pero queda con su nombre y su hora»*. Así que el 403 por estación **no existe** —
| lo que protege un paso es la firma visible, el deshacer y el motivo que lee la
| familia—. La única con permiso dentro es la de resolver una nota, y su porqué
| está en `Autoriza::puedeResolverNotaDeEstacion`.
|
| Ninguna es pública y ninguna puede serlo: **quien atiende una estación tiene
| cuenta**, a diferencia de la familia del aspirante, que es lo que obligó a abrir
| las tres del formulario de inscripción.
|
| ## EL ORDEN NO ES LO QUE PROTEGE AQUÍ, Y ESO ES A PROPÓSITO
|
| Laravel sirve **la primera ruta que casa**, así que un comodín declarado antes de
| una literal se la traga —`…/{lote}` comiéndose `…/campos`, `…/{codigo}` comiéndose
| `…/pendientes`: dos veces en dos días—. Aquí las literales van primero **y
| además** `{nro}` lleva `->where('nro', '[0-9]+')`, que es lo que de verdad lo
| cierra: con la restricción puesta, `estaciones/alumno/5` no puede entrar por
| `estaciones/{nro}/cola` aunque alguien reordene el fichero mañana.
|
| *Un orden correcto se estropea al editar y nadie se entera; una restricción de
| parámetro no.* Lo comprueba `RutasTest::test_ninguna_ruta_literal_la_atiende_un_comodin`,
| que le pregunta al router y no a lo declarado.
|
| **Y `{nro}` admite el 0**, que no es un caso raro: es lo que hay hoy. `orden`
| tiene defecto 0 y en la copia de desarrollo la única fila vale 0, así que un
| colegio que no haya numerado sus pasos los tiene todos en la estación 0.
*/

// --- Las cinco lecturas -----------------------------------------------------

Route::get('estaciones', [EstacionesController::class, 'getIndex'])
    ->middleware('auth.personal');

Route::get('estaciones/huella', [EstacionesController::class, 'getHuella'])
    ->middleware('auth.personal');

// EL TABLERO DEL DÍA. Pantalla 15 de `myvc_front/PANTALLAS-MATRICULA.md`, la única
// de las quince que no tenía endpoint.
//
// **No devuelve el informe de campaña**, que esa misma pantalla también pide: eso ya
// es `GET informes/formularios-inscripcion/campana` desde el 20 sep, con `sin_volver`
// dentro. La pantalla llama a las dos; duplicarlo aquí serían dos cifras que algún
// día se contradicen.
//
// `auth.personal` y nada dentro **aunque sea la pantalla del rector**, con el motivo:
// no enseña nada que quien atiende no vea ya en `estaciones/{nro}/cola`. Un permiso
// aquí no taparía ningún dato, sólo daría la impresión de que sí.
Route::get('estaciones/tablero', [EstacionesController::class, 'getTablero'])
    ->middleware('auth.personal');

Route::get('estaciones/alumno/{alumno_id}', [EstacionesController::class, 'getAlumno'])
    ->middleware('auth.personal');

Route::get('estaciones/codigo/{codigo}', [EstacionesController::class, 'getCodigo'])
    ->middleware('auth.personal');

Route::get('estaciones/{nro}/cola', [EstacionesController::class, 'getCola'])
    ->where('nro', '[0-9]+')
    ->middleware('auth.personal');

// --- Las cuatro escrituras --------------------------------------------------

Route::put('estaciones/nota/{id}/resuelta', [EstacionesController::class, 'putNotaResuelta'])
    ->middleware('auth.personal');

Route::put('estaciones/{nro}/marcar', [EstacionesController::class, 'putMarcar'])
    ->where('nro', '[0-9]+')
    ->middleware('auth.personal');

Route::put('estaciones/{nro}/enviar-a/{destino}', [EstacionesController::class, 'putEnviarA'])
    ->where(['nro' => '[0-9]+', 'destino' => '[0-9]+'])
    ->middleware('auth.personal');

Route::post('estaciones/{nro}/nota', [EstacionesController::class, 'postNota'])
    ->where('nro', '[0-9]+')
    ->middleware('auth.personal');
