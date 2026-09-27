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
| Ninguna acepta un `prompt`. Proponer la plantilla exige el permiso de la
| pantalla (`can_edit_plantilla_notas`); el estado y revisar un texto, sólo ser
| personal (el proxy filtra por rol); el uso del mes, rectoría.
|
*/

Route::get('ia/estado', [IaController::class, 'getEstado'])->middleware('auth.personal');
Route::post('ia/plantilla/proponer', [IaController::class, 'postPlantilla'])->middleware('auth.personal');
Route::post('ia/texto/revisar', [IaController::class, 'postTexto'])->middleware('auth.personal');
Route::post('ia/competencias/proponer', [IaController::class, 'postCompetencias'])->middleware('auth.personal');
Route::post('ia/boletin/leer', [IaController::class, 'postBoletin'])->middleware('auth.personal');
Route::get('ia/uso', [IaController::class, 'getUso'])->middleware('auth.personal');
