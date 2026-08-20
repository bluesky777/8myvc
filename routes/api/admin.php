<?php

use App\Http\Controllers\BitacorasController;
use App\Http\Controllers\CambiarUsuarios\CambiarUsuariosController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UniformesController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

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
Route::post('users/crear-administrador', [UsersController::class, 'postCrearAdministrador'])->middleware('auth.personal');
Route::post('users/crear-enfermero', [UsersController::class, 'postCrearEnfermero'])->middleware('auth.personal');
Route::post('users/crear-psicologo', [UsersController::class, 'postCrearPsicologo'])->middleware('auth.personal');
Route::get('users/export', [UsersController::class, 'getExport'])->middleware('auth.personal');
Route::put('users/usernames-check', [UsersController::class, 'putUsernamesCheck'])->middleware('auth.personal');

// CambiarUsuariosController
Route::put('cambiar-usuarios/poner-documento-como-username-acudientes', [CambiarUsuariosController::class, 'putPonerDocumentoComoUsernameAcudientes'])->middleware('auth.personal');
Route::put('cambiar-usuarios/poner-documento-como-username-alumnos', [CambiarUsuariosController::class, 'putPonerDocumentoComoUsernameAlumnos'])->middleware('auth.personal');
Route::put('cambiar-usuarios/poner-password-todos-acudientes', [CambiarUsuariosController::class, 'putPonerPasswordTodosAcudientes'])->middleware('auth.personal');
Route::put('cambiar-usuarios/poner-password-todos-alumnos', [CambiarUsuariosController::class, 'putPonerPasswordTodosAlumnos'])->middleware('auth.personal');

// UniformesController
Route::put('uniformes/actualizar', [UniformesController::class, 'putActualizar'])->middleware('auth.personal');
Route::put('uniformes/agregar', [UniformesController::class, 'putAgregar'])->middleware('auth.personal');
Route::put('uniformes/eliminar', [UniformesController::class, 'putEliminar'])->middleware('auth.personal');
Route::put('uniformes/guardar-cambios', [UniformesController::class, 'putGuardarCambios'])->middleware('auth.personal');

// BitacorasController
Route::delete('bitacoras/destroy/{id}', [BitacorasController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('bitacoras/{user_id?}', [BitacorasController::class, 'getIndex'])->middleware('auth.personal');

// RolesController
Route::get('roles', [RolesController::class, 'getIndex'])->middleware('auth.personal');
Route::get('roles/rolesconpermisos', [RolesController::class, 'getRolesconpermisos'])->middleware('auth.personal');
Route::put('roles/addpermission/{id}', [RolesController::class, 'putAddpermission'])->middleware('auth.personal');
Route::put('roles/addroletouser/{role_id}', [RolesController::class, 'putAddroletouser'])->middleware('auth.personal');
Route::put('roles/removepermission/{id}', [RolesController::class, 'putRemovepermission'])->middleware('auth.personal');
Route::put('roles/removeroletouser/{role_id}', [RolesController::class, 'putRemoveroletouser'])->middleware('auth.personal');

// PermissionsController
Route::get('permissions', [PermissionsController::class, 'getIndex'])->middleware('auth.personal');

// EventosController
Route::get('eventos', [EventosController::class, 'getIndex'])->middleware('auth.personal');
