<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CiudadesController;
use App\Http\Controllers\EstadosCivilesController;
use App\Http\Controllers\PaisesController;
use App\Http\Controllers\TipoDocumentoController;

/*
|--------------------------------------------------------------------------
| Rutas: catalogos
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// PaisesController
Route::get('paises', [PaisesController::class, 'getIndex']);
Route::post('paises/store', [PaisesController::class, 'postStore']);

// CiudadesController
Route::get('ciudades', [CiudadesController::class, 'getIndex']);
Route::put('ciudades/actualizar-ciudad', [CiudadesController::class, 'putActualizarCiudad']);
Route::put('ciudades/actualizar-departamento', [CiudadesController::class, 'putActualizarDepartamento']);
Route::get('ciudades/by-departamento', [CiudadesController::class, 'getByDepartamento']);
Route::put('ciudades/departamentos-by-id', [CiudadesController::class, 'putDepartamentosById']);
Route::post('ciudades/guardar-ciudad', [CiudadesController::class, 'postGuardarCiudad']);
Route::get('ciudades/datosciudad/{ciudad_id}', [CiudadesController::class, 'getDatosciudad']);
Route::get('ciudades/departamentos/{pais_id}', [CiudadesController::class, 'getDepartamentos']);
Route::delete('ciudades/destroy/{id}', [CiudadesController::class, 'deleteDestroy']);
Route::get('ciudades/paisdeciudad/{ciudad_id}', [CiudadesController::class, 'getPaisdeciudad']);
Route::get('ciudades/por-departamento/{departamento}', [CiudadesController::class, 'getPorDepartamento']);

// TipoDocumentoController
Route::get('tiposdocumento', [TipoDocumentoController::class, 'index']);
Route::get('tiposdocumento/create', [TipoDocumentoController::class, 'create']);
Route::post('tiposdocumento', [TipoDocumentoController::class, 'store']);
Route::get('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'show']);
Route::get('tiposdocumento/{tiposdocumento}/edit', [TipoDocumentoController::class, 'edit']);
Route::put('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'update']);
Route::patch('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'update']);
Route::delete('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'destroy']);

// EstadosCivilesController
Route::get('estados_civiles', [EstadosCivilesController::class, 'index']);
Route::get('estados_civiles/create', [EstadosCivilesController::class, 'create']);
Route::post('estados_civiles', [EstadosCivilesController::class, 'store']);
Route::get('estados_civiles/{estados_civile}', [EstadosCivilesController::class, 'show']);
Route::get('estados_civiles/{estados_civile}/edit', [EstadosCivilesController::class, 'edit']);
Route::put('estados_civiles/{estados_civile}', [EstadosCivilesController::class, 'update']);
Route::patch('estados_civiles/{estados_civile}', [EstadosCivilesController::class, 'update']);
Route::delete('estados_civiles/{estados_civile}', [EstadosCivilesController::class, 'destroy']);
