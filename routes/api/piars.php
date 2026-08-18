<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Piars\PiarsActasAcuerdoController;
use App\Http\Controllers\Piars\PiarsConfigController;

/*
|--------------------------------------------------------------------------
| Rutas: piars
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// PiarsConfigController
Route::get('piars-config', [PiarsConfigController::class, 'getIndex']);
Route::put('piars-config/config', [PiarsConfigController::class, 'putConfig']);

// PiarsActasAcuerdoController
Route::post('piars-actas-acuerdo/document', [PiarsActasAcuerdoController::class, 'postDocument']);
Route::delete('piars-actas-acuerdo/document/{alumno_id}', [PiarsActasAcuerdoController::class, 'deleteDocument']);
Route::get('piars-actas-acuerdo/matriculas/{grupo_id}', [PiarsActasAcuerdoController::class, 'getMatriculas']);
