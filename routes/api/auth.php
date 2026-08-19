<?php

use App\Http\Controllers\Auth\SesionController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\Tardanzas\TLoginController;
use Illuminate\Support\Facades\Route;

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

// SesionController — la sesión de la Fase 3: par acceso + refresco.
//
// El contrato está en docs/migracion/07-sesion.md. Las tres primeras van sin
// guard, y cada una por su motivo:
//
//   - 'auth/login' es la entrada: quien la llama todavía no tiene token.
//   - 'auth/refresh' recibe el token de REFRESCO, no el de acceso. Si exigiera
//     un acceso vivo, no se podría renovar nunca uno caducado, que es justo
//     para lo que existe.
//   - 'auth/logout' tiene que funcionar con el token ya vencido —el caso normal
//     de quien vuelve al día siguiente y pulsa salir— y responder 200 pase lo
//     que pase, para que el frontend pueda limpiar su estado. Mismo criterio
//     que 'login/logout', su equivalente viejo.
//
// Las dos últimas sí exigen token vivo: 'auth/logout-all' es una acción de
// seguridad sobre la cuenta, y 'auth/me' devuelve datos del usuario.
Route::post('auth/login', [SesionController::class, 'login'])->withoutMiddleware('auth.token');
Route::post('auth/refresh', [SesionController::class, 'refrescar'])->withoutMiddleware('auth.token');
Route::post('auth/logout', [SesionController::class, 'logout'])->withoutMiddleware('auth.token');
Route::post('auth/logout-all', [SesionController::class, 'logoutTodas']);
Route::get('auth/me', [SesionController::class, 'yo']);

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
// colegios use ya 'login/recuperar-clave'. Anotado en docs/DESPLIEGUE-REFERENCIA.md.
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
