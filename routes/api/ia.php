<?php

use App\Http\Controllers\IaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: ia
|--------------------------------------------------------------------------
|
| Las dos ayudas de «Plan de evaluación». No hablan con Anthropic: hablan con
| `myvc-ia-proxy`, que es quien tiene la clave. Ver IaController.
|
| Ninguna acepta un `prompt`, y el permiso es el de la pantalla
| (`can_edit_plantilla_notas`), no uno nuevo.
|
*/

Route::get('ia/estado', [IaController::class, 'getEstado'])->middleware('auth.personal');
Route::post('ia/plantilla/proponer', [IaController::class, 'postPlantilla'])->middleware('auth.personal');
Route::post('ia/texto/revisar', [IaController::class, 'postTexto'])->middleware('auth.personal');
