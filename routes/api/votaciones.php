<?php

use App\Http\Controllers\VtActasController;
use App\Http\Controllers\VtAspiracionesController;
use App\Http\Controllers\VtAuditoriaController;
use App\Http\Controllers\VtCandidatosController;
use App\Http\Controllers\VtCensoController;
use App\Http\Controllers\VtMesasController;
use App\Http\Controllers\VtResultadosController;
use App\Http\Controllers\VtVotacionesController;
use App\Http\Controllers\VtVotosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: votaciones
|--------------------------------------------------------------------------
|
| El orden es significativo: las rutas sin parámetros van antes que las que
| llevan {id} para que no queden tapadas. No reordenar sin comprobar con
| tools/route-table-dump.php.
|
| Reescrito el 22 sep 2026, con el rediseño del módulo. Lo que cambió de raíz:
|
| 1. **Nadie se inscribe.** `vt_participantes` ya no existe y con ella se fueron
|    las nueve rutas `participantes/*`. El censo se resuelve en caliente: vota
|    quien esté en un grupo vivo del año de la votación —matrícula cuyo `estado`
|    no sea RETI ni DESE— y cuyo grupo no esté excluido en `vt_grupos_votacion`.
|    Las rutas `censo/*` no escriben un censo: leen el que ya existe y guardan
|    las excepciones.
| 2. **El voto es inmutable.** `votos/update` y `votos/destroy` tocaban
|    candidatos, no votos, y el segundo se llevaba un candidato con sus votos:
|    borrados. `votos/store` ya no devuelve 200 con un `msg` dentro, y ya no
|    reemplaza el voto anterior: 201, o 409/423/403/422.
| 3. **`GET votos` y `votaciones/unsignedsusers`, borradas.** La primera
|    entregaba la tabla entera de votos con el `user_id` de cada uno a cualquier
|    `auth.personal` —el voto secreto— y la segunda llevaba en 500 desde antes
|    de la migración. La lista nominal ahora sólo sale por `auditoria/{id}`, y
|    ahí sólo para el superusuario.
| 4. **Las mesas son nuevas.** Un niño de preescolar no teclea su contraseña:
|    alguien le abre la papeleta y se aparta. Eso es `mesas/{id}/abrir`, y por
|    eso escribe un voto a nombre de otra persona bajo una marca firmada.
| 5. **Las actas son votos de papel**, en cantidades por cargo y candidato. Se
|    suman al escrutinio y se ven aparte en `resultados/{id}`.
|
*/

// VtVotacionesController
Route::get('votaciones', [VtVotacionesController::class, 'getIndex']);
// Lo que la pantalla de votar necesita y por eso se queda abierto:
// `en-accion-inscrito` y `votos/store` son las DOS únicas que llama `VotarCtrl`,
// que es el estado del front sin `needed_permissions`. `candidatos/conaspiraciones`
// es la papeleta y se acota al censo del que pregunta; `votos/show` y el índice
// de `votaciones` se acotan por el `user_id` del token.
Route::get('votaciones/actual', [VtVotacionesController::class, 'getActual']);
Route::get('votaciones/actual-in-action', [VtVotacionesController::class, 'getActualInAction']);
Route::get('votaciones/en-accion-inscrito', [VtVotacionesController::class, 'getEnAccionInscrito']);
// Los interruptores de la elección. La votación viaja en el cuerpo, así que la
// ruta no nombra a nadie: el dueño se comprueba dentro (antes no se comprobaba,
// y cualquiera con `auth.personal` movía la elección de otro).
Route::put('votaciones/set-actual', [VtVotacionesController::class, 'putSetActual'])->middleware('auth.personal');
Route::put('votaciones/set-in-action', [VtVotacionesController::class, 'putSetInAction'])->middleware('auth.personal');
Route::put('votaciones/set-locked', [VtVotacionesController::class, 'putSetLocked'])->middleware('auth.personal');
Route::put('votaciones/set-permiso-ver-results', [VtVotacionesController::class, 'putSetPermisoVerResults'])->middleware('auth.personal');
Route::put('votaciones/set-votan-acudientes', [VtVotacionesController::class, 'putSetVotanAcudientes'])->middleware('auth.personal');
Route::put('votaciones/set-votan-profes', [VtVotacionesController::class, 'putSetVotanProfes'])->middleware('auth.personal');
// Los seis nuevos. `set-votan-administrativos` es el personal con cuenta que no
// es docente (`users.tipo = 'Usuario'`): no hay dato en la base para separar
// cafetería, aseo y mantenimiento de secretaría, así que es un interruptor y no
// tres. `set-doble-llave` devuelve la clave en claro UNA vez, al encenderla.
Route::put('votaciones/set-votan-estudiantes', [VtVotacionesController::class, 'putSetVotanEstudiantes'])->middleware('auth.personal');
Route::put('votaciones/set-votan-administrativos', [VtVotacionesController::class, 'putSetVotanAdministrativos'])->middleware('auth.personal');
Route::put('votaciones/set-titulares-conducen', [VtVotacionesController::class, 'putSetTitularesConducen'])->middleware('auth.personal');
Route::put('votaciones/set-solo-en-mesa', [VtVotacionesController::class, 'putSetSoloEnMesa'])->middleware('auth.personal');
Route::put('votaciones/set-cuenta-atras', [VtVotacionesController::class, 'putSetCuentaAtras'])->middleware('auth.personal');
Route::put('votaciones/set-doble-llave', [VtVotacionesController::class, 'putSetDobleLlave'])->middleware('auth.personal');
Route::post('votaciones/store', [VtVotacionesController::class, 'postStore'])->middleware('auth.personal');
Route::delete('votaciones/destroy/{id}', [VtVotacionesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('votaciones/show/{id}', [VtVotacionesController::class, 'getShow']);
Route::put('votaciones/update/{id}', [VtVotacionesController::class, 'putUpdate'])->middleware('auth.personal');

// VtAspiracionesController
// Los cargos a los que se aspira. `store` sigue admitiendo el cargo en blanco a
// propósito: `VotacionesCtrl` crea y manda el nombre después, por `update`.
Route::post('aspiraciones/store', [VtAspiracionesController::class, 'postStore'])->middleware('auth.personal');
Route::put('aspiraciones/update', [VtAspiracionesController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('aspiraciones/destroy/{id}', [VtAspiracionesController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtCensoController
// Sustituye a las nueve rutas `participantes/*`. `votantes` ya no devuelve a
// quién votó cada uno (era la filtración de 05 §18): devuelve si votó y en qué
// cargos, que es lo que la pantalla necesita.
Route::get('censo/{id}', [VtCensoController::class, 'getIndex'])->middleware('auth.personal');
Route::put('censo/{id}/grupos', [VtCensoController::class, 'putGrupos'])->middleware('auth.personal');
Route::get('censo/{id}/votantes', [VtCensoController::class, 'getVotantes'])->middleware('auth.personal');
Route::get('censo/{id}/elegibles', [VtCensoController::class, 'getElegibles'])->middleware('auth.personal');
// `conductores` NO es `elegibles` filtrado: es a quién se le puede dar una mesa, o
// sea los docentes con contrato del año **y el personal con cuenta que no es
// docente** (`users.tipo = 'Usuario'`). `elegibles` no los ve nunca —son cuentas
// sin ficha en `profesores`— y por eso la pantalla de mesas no podía ofrecer la
// mesa de la oficina, que el backend sí acepta.
Route::get('censo/{id}/conductores', [VtCensoController::class, 'getConductores'])->middleware('auth.personal');

// VtMesasController
// `mias` devuelve además las mesas implícitas del titular con `id: null` cuando
// `titulares_conducen` está encendido; `mia-de-grupo` las materializa. No se
// materializan en el GET porque una lectura que escribe deja cuarenta mesas
// vacías al final del día.
Route::get('mesas', [VtMesasController::class, 'getIndex'])->middleware('auth.personal');
Route::get('mesas/mias', [VtMesasController::class, 'getMias'])->middleware('auth.personal');
Route::post('mesas/store', [VtMesasController::class, 'postStore'])->middleware('auth.personal');
Route::post('mesas/mia-de-grupo', [VtMesasController::class, 'postMiaDeGrupo'])->middleware('auth.personal');
Route::get('mesas/{id}/lista', [VtMesasController::class, 'getLista'])->middleware('auth.personal');
Route::post('mesas/{id}/abrir', [VtMesasController::class, 'postAbrir'])->middleware('auth.personal');
Route::put('mesas/update/{id}', [VtMesasController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('mesas/destroy/{id}', [VtMesasController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtCandidatosController
// `conaspiraciones` es la papeleta y llevaba dando 500 a Alumno y Acudiente
// desde siempre. `candidatos` a secas entrega todos los candidatos de todos los
// años y no la llama ningún cliente; se queda con guard.
Route::get('candidatos', [VtCandidatosController::class, 'getIndex'])->middleware('auth.personal');
Route::get('candidatos/conaspiraciones', [VtCandidatosController::class, 'getConaspiraciones']);
Route::post('candidatos/store', [VtCandidatosController::class, 'postStore'])->middleware('auth.personal');
Route::delete('candidatos/destroy/{id}', [VtCandidatosController::class, 'deleteDestroy'])->middleware('auth.personal');

// VtVotosController
// Dos rutas y ninguna más. `store` está abierto a propósito —es lo que llama la
// pantalla de votar— y por eso valida dentro: censo, urna abierta, fechas, que
// el candidato sea de esa votación, y la marca firmada de la mesa cuando el voto
// viene de una.
Route::put('votos/show', [VtVotosController::class, 'putShow']);
Route::post('votos/store', [VtVotosController::class, 'postStore']);

// VtActasController
// Los votos de papel, en cantidades por cargo y candidato. Firmar es
// irreversible: anular un acta firmada sería otra operación, con otro permiso y
// su propio rastro, y todavía no existe.
Route::get('actas/{id}', [VtActasController::class, 'getIndex'])->middleware('auth.personal');
Route::post('actas/firmar/{id}', [VtActasController::class, 'postFirmar'])->middleware('auth.personal');
Route::delete('actas/destroy/{id}', [VtActasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('actas/{id}/grupo/{grupo}', [VtActasController::class, 'getGrupo'])->middleware('auth.personal');
Route::put('actas/{id}/grupo/{grupo}', [VtActasController::class, 'putGrupo'])->middleware('auth.personal');

// VtResultadosController
// Sin `auth.personal`: la lee el alumno. `can_see_results` se comprueba dentro,
// y a quien no es personal con el interruptor apagado no se le da ni el total.
Route::get('resultados/{id}', [VtResultadosController::class, 'getShow']);

// VtAuditoriaController
// La única ruta del módulo que rompe el secreto del voto: dice por quién votó
// cada persona. `auth.personal` es la puerta de fuera; dentro exige
// `Autoriza::esSuperusuario()` —`users.is_superuser` y nada más— porque
// `puedeVerAuditoria()` siembra su permiso a rectoría y coordinación. Cada
// consulta queda registrada a nombre de quien la hizo.
Route::get('auditoria/{id}', [VtAuditoriaController::class, 'getShow'])->middleware('auth.personal');
