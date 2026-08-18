<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RemindersController;
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
Route::post('login', [LoginController::class, 'postIndex']);
Route::put('login/crear-prematricula', [LoginController::class, 'putCrearPrematricula']);
Route::post('login/credentials', [LoginController::class, 'postCredentials']);
Route::put('login/logout', [LoginController::class, 'putLogout']);
Route::put('login/reset-password', [LoginController::class, 'putResetPassword']);
Route::post('login/ver-pass', [LoginController::class, 'postVerPass']);

// RemindersController
Route::get('password/remind', [RemindersController::class, 'getRemind']);
Route::post('password/remind', [RemindersController::class, 'postRemind']);
Route::post('password/reset', [RemindersController::class, 'postReset']);
Route::get('password/reset/{token?}', [RemindersController::class, 'getReset']);

// TLoginController
Route::post('tardanzas/login', [TLoginController::class, 'postIndex']);
Route::post('tardanzas/login/traer-datos', [TLoginController::class, 'postTraerDatos']);
Route::post('tardanzas/login/traer-datos-ausencias', [TLoginController::class, 'postTraerDatosAusencias']);
