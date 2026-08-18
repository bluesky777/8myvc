<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BitacorasController;
use App\Http\Controllers\CambiarUsuarios\CambiarUsuariosController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UniformesController;
use App\Http\Controllers\UsersController;

/*
|--------------------------------------------------------------------------
| Rutas: admin
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// UsersController
Route::post('users/crear-administrador', [UsersController::class, 'postCrearAdministrador']);
Route::post('users/crear-enfermero', [UsersController::class, 'postCrearEnfermero']);
Route::post('users/crear-psicologo', [UsersController::class, 'postCrearPsicologo']);
Route::get('users/export', [UsersController::class, 'getExport'])->middleware('auth.token');
Route::put('users/usernames-check', [UsersController::class, 'putUsernamesCheck'])->middleware('auth.token');

// CambiarUsuariosController
Route::put('cambiar-usuarios/poner-documento-como-username-acudientes', [CambiarUsuariosController::class, 'putPonerDocumentoComoUsernameAcudientes']);
Route::put('cambiar-usuarios/poner-documento-como-username-alumnos', [CambiarUsuariosController::class, 'putPonerDocumentoComoUsernameAlumnos']);
Route::put('cambiar-usuarios/poner-password-todos-acudientes', [CambiarUsuariosController::class, 'putPonerPasswordTodosAcudientes']);
Route::put('cambiar-usuarios/poner-password-todos-alumnos', [CambiarUsuariosController::class, 'putPonerPasswordTodosAlumnos']);

// UniformesController
Route::put('uniformes/actualizar', [UniformesController::class, 'putActualizar']);
Route::put('uniformes/agregar', [UniformesController::class, 'putAgregar']);
Route::put('uniformes/eliminar', [UniformesController::class, 'putEliminar']);
Route::put('uniformes/guardar-cambios', [UniformesController::class, 'putGuardarCambios']);

// BitacorasController
Route::post('bitacoras/store', [BitacorasController::class, 'postStore']);
Route::delete('bitacoras/destroy/{id}', [BitacorasController::class, 'deleteDestroy']);
Route::put('bitacoras/update/{id}', [BitacorasController::class, 'putUpdate']);
Route::get('bitacoras/{user_id?}', [BitacorasController::class, 'getIndex']);

// RolesController
Route::get('roles', [RolesController::class, 'getIndex']);
Route::get('roles/rolesconpermisos', [RolesController::class, 'getRolesconpermisos']);
Route::put('roles/addpermission/{id}', [RolesController::class, 'putAddpermission']);
Route::put('roles/addroletouser/{role_id}', [RolesController::class, 'putAddroletouser']);
Route::put('roles/removepermission/{id}', [RolesController::class, 'putRemovepermission']);
Route::put('roles/removeroletouser/{role_id}', [RolesController::class, 'putRemoveroletouser']);

// PermissionsController
Route::get('permissions', [PermissionsController::class, 'getIndex']);
Route::post('permissions', [PermissionsController::class, 'postIndex']);
Route::delete('permissions/destroy/{id}', [PermissionsController::class, 'deleteDestroy']);
Route::get('permissions/show/{id}', [PermissionsController::class, 'getShow']);
Route::put('permissions/update/{id}', [PermissionsController::class, 'putUpdate']);

// EventosController
Route::get('eventos', [EventosController::class, 'getIndex']);
