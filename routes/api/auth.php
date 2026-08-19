<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\Tardanzas\TLoginController;

/*
|--------------------------------------------------------------------------
| Rutas: auth
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// LoginController
//
// Las siete son la entrada al sistema: el frontend las llama SIN sesión, así que
// ninguna puede exigir token. Inventario levantado por la sesión de myvc_front
// (18 ago 2026) recorriendo los estados que viven fuera del área autenticada.
// Ver docs/migracion/04-auditoria-autenticacion.md §5.
Route::post('login', [LoginController::class, 'postIndex'])->withoutMiddleware('auth.token');
Route::put('login/crear-prematricula', [LoginController::class, 'putCrearPrematricula'])->withoutMiddleware('auth.token');
Route::post('login/credentials', [LoginController::class, 'postCredentials'])->withoutMiddleware('auth.token');
Route::put('login/logout', [LoginController::class, 'putLogout'])->withoutMiddleware('auth.token');
// Se llama desde el enlace del correo: el usuario no ha iniciado sesión —no
// puede, ha olvidado la contraseña— y el token del reseteo viaja en la URL.
Route::put('login/reset-password', [LoginController::class, 'putResetPassword'])->withoutMiddleware('auth.token');
Route::post('login/recuperar-clave', [LoginController::class, 'postRecuperarClave'])->withoutMiddleware('auth.token');
// Alias de la anterior. La ruta se llamaba 'login/ver-pass' y el nombre engañaba:
// no muestra ninguna contraseña, manda el correo de reseteo.
//
// Se mantiene para poder desplegar el backend antes que el frontend — cada
// colegio publica su front por separado, así que durante un tiempo convivirán
// versiones que llaman a una y a otra. Se borra cuando el front de TODOS los
// colegios use ya 'login/recuperar-clave'. Anotado en docs/DESPLIEGUE.md.
Route::post('login/ver-pass', [LoginController::class, 'postRecuperarClave'])->withoutMiddleware('auth.token');

// TLoginController
//
// Tardanzas no usa token: el lector manda usuario y contraseña en el cuerpo de
// CADA petición y el método las verifica con Auth::attempt(). No son rutas
// públicas —autentican— pero el guard de token las cerraría igual, y el lector
// se quedaría sin poder entrar.
Route::post('tardanzas/login', [TLoginController::class, 'postIndex'])->withoutMiddleware('auth.token');
Route::post('tardanzas/login/traer-datos', [TLoginController::class, 'postTraerDatos'])->withoutMiddleware('auth.token');
Route::post('tardanzas/login/traer-datos-ausencias', [TLoginController::class, 'postTraerDatosAusencias'])->withoutMiddleware('auth.token');
