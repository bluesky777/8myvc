<?php

use App\Http\Controllers\CiudadesController;
use App\Http\Controllers\PaisesController;
use App\Http\Controllers\TipoDocumentoController;
use Illuminate\Support\Facades\Route;

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
Route::post('paises/store', [PaisesController::class, 'postStore'])->middleware('auth.personal');

// CiudadesController
Route::get('ciudades', [CiudadesController::class, 'getIndex']);
Route::put('ciudades/actualizar-ciudad', [CiudadesController::class, 'putActualizarCiudad'])->middleware('auth.personal');
Route::put('ciudades/actualizar-departamento', [CiudadesController::class, 'putActualizarDepartamento'])->middleware('auth.personal');
Route::get('ciudades/by-departamento', [CiudadesController::class, 'getByDepartamento']);
Route::put('ciudades/departamentos-by-id', [CiudadesController::class, 'putDepartamentosById'])->middleware('auth.personal');
Route::post('ciudades/guardar-ciudad', [CiudadesController::class, 'postGuardarCiudad'])->middleware('auth.personal');
Route::get('ciudades/datosciudad/{ciudad_id}', [CiudadesController::class, 'getDatosciudad']);
Route::get('ciudades/departamentos/{pais_id}', [CiudadesController::class, 'getDepartamentos']);
Route::delete('ciudades/destroy/{id}', [CiudadesController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('ciudades/paisdeciudad/{ciudad_id}', [CiudadesController::class, 'getPaisdeciudad']);
Route::get('ciudades/por-departamento/{departamento}', [CiudadesController::class, 'getPorDepartamento']);

// TipoDocumentoController
Route::get('tiposdocumento', [TipoDocumentoController::class, 'index']);
Route::post('tiposdocumento', [TipoDocumentoController::class, 'store'])->middleware('auth.personal');
Route::put('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'update'])->middleware('auth.personal');
Route::patch('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'update'])->middleware('auth.personal');
Route::delete('tiposdocumento/{tiposdocumento}', [TipoDocumentoController::class, 'destroy'])->middleware('auth.personal');
