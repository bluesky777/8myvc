<?php

use App\Http\Controllers\NotificacionesController;
use App\Http\Controllers\PendientesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: notificaciones
|--------------------------------------------------------------------------
|
| Archivo nuevo (24 ago 2026) y no generado por tools/route-emit.php: esto no
| existía en la tabla vieja. El plan entero está en
| ~/DESARROLLOS/myvc_flutter/docs/notificaciones.md.
|
*/

// NotificacionesController
//
// **Sin guard propio a propósito, y no es un descuido.** Le basta el `auth.token`
// que la API pone por defecto: quien pregunta recibe SÓLO lo suyo, y eso lo
// decide el controlador mirando su `tipo` — un alumno sus temas, un acudiente
// los de sus acudidos, el personal ninguno. Poner `boletin.propio` aquí sería
// pedirle que compruebe un `alumno_id` que esta ruta no acepta: no se pide de
// quién, se contesta quién eres.
Route::get('notificaciones/temas', [NotificacionesController::class, 'getTemas']);

// PendientesController
//
// Las cosas pendientes del colegio: el aviso que sale una vez por ingreso y el
// bloque de Inicio › Pendientes. `auth.personal` porque alumnos y acudientes no
// tienen nada que ver aquí; el reparto fino —sólo directivos, y qué tipo a quién—
// lo hace el controlador, que al resto del personal le contesta la lista vacía.
Route::get('pendientes/mios', [PendientesController::class, 'getMios'])->middleware('auth.personal');
// Posponer (7 días) o silenciar (el año) uno que no sea Firme, y deshacerlo. Cada uno
// oculta SÓLO lo suyo: la fila va por `user_id` del token.
Route::put('pendientes/ocultar', [PendientesController::class, 'putOcultar'])->middleware('auth.personal');
Route::put('pendientes/mostrar', [PendientesController::class, 'putMostrar'])->middleware('auth.personal');
