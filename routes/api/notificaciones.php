<?php

use App\Http\Controllers\NotificacionesController;
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
