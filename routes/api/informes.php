<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CertificadosEstudioController;
use App\Http\Controllers\ConfigCertificadosController;
use App\Http\Controllers\Historiales\HistorialesController;
use App\Http\Controllers\Informes\ActasEvaluacionController;
use App\Http\Controllers\Informes\AcudientesExportController;
use App\Http\Controllers\Informes\Boletines2Controller;
use App\Http\Controllers\Informes\Boletines3Controller;
use App\Http\Controllers\Informes\BoletinesController;
use App\Http\Controllers\Informes\BolfinalesPreescolarController;
use App\Http\Controllers\Informes\CertificadosPersonaController;
use App\Http\Controllers\Informes\ExcelListadoDocentesController;
use App\Http\Controllers\Informes\InformesController;
use App\Http\Controllers\Informes\NotasPerdidasController;
use App\Http\Controllers\Informes\ObservadorController;
use App\Http\Controllers\Informes\ObservadorHorizontalController;
use App\Http\Controllers\Informes\PuestosController;
use App\Http\Controllers\Informes\SimatController;

/*
|--------------------------------------------------------------------------
| Rutas: informes
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// ConfigCertificadosController
Route::get('certificados', [ConfigCertificadosController::class, 'getIndex']);
Route::put('certificados/actual', [ConfigCertificadosController::class, 'putActual']);
Route::put('certificados/encabezado', [ConfigCertificadosController::class, 'putEncabezado']);
Route::post('certificados/store', [ConfigCertificadosController::class, 'postStore']);
Route::put('certificados/update', [ConfigCertificadosController::class, 'putUpdate']);
Route::delete('certificados/destroy/{id}', [ConfigCertificadosController::class, 'deleteDestroy']);

// HistorialesController
Route::put('historiales/de-usuario', [HistorialesController::class, 'putDeUsuario']);
Route::put('historiales/nota-detalle', [HistorialesController::class, 'putNotaDetalle']);
Route::put('historiales/nota-final-detalle', [HistorialesController::class, 'putNotaFinalDetalle']);
Route::put('historiales/sesion', [HistorialesController::class, 'putSesion']);

// InformesController
Route::put('informes/cumpleanos-por-meses', [InformesController::class, 'putCumpleanosPorMeses']);
Route::put('informes/datos', [InformesController::class, 'putDatos']);

// CertificadosPersonaController
Route::put('certificados-persona', [CertificadosPersonaController::class, 'putIndex']);

// BolfinalesPreescolarController
Route::put('bolfinales-preescolar/crear-frase', [BolfinalesPreescolarController::class, 'putCrearFrase']);
Route::put('bolfinales-preescolar/eliminar-frase', [BolfinalesPreescolarController::class, 'putEliminarFrase']);
Route::put('bolfinales-preescolar/guardar-frase', [BolfinalesPreescolarController::class, 'putGuardarFrase']);
Route::put('bolfinales-preescolar/detailed-notas-year-group/{grupo_id}', [BolfinalesPreescolarController::class, 'putDetailedNotasYearGroup']);
Route::put('bolfinales-preescolar/detailed-notas-year/{grupo_id}', [BolfinalesPreescolarController::class, 'putDetailedNotasYear']);

// PuestosController
Route::put('puestos/detailed-notas-year', [PuestosController::class, 'putDetailedNotasYear']);
Route::put('puestos/detailed-notas-periodo/{grupo_id}', [PuestosController::class, 'putDetailedNotasPeriodo']);

// NotasPerdidasController
Route::put('notas-perdidas/profesor-grupos', [NotasPerdidasController::class, 'putProfesorGrupos']);
Route::put('notas-perdidas/todos', [NotasPerdidasController::class, 'putTodos']);
Route::get('notas-perdidas/show-profesor/{profesor_id}', [NotasPerdidasController::class, 'getShowProfesor']);

// BoletinesController
Route::put('boletines/detailed-notas-group/{grupo_id}', [BoletinesController::class, 'putDetailedNotasGroup']);
Route::get('boletines/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [BoletinesController::class, 'getDetailedNotasYear']);
Route::put('boletines/detailed-notas/{grupo_id}', [BoletinesController::class, 'putDetailedNotas']);

// Boletines2Controller
Route::delete('boletines2/destroy/{id}', [Boletines2Controller::class, 'deleteDestroy']);
Route::put('boletines2/detailed-notas-group/{grupo_id}', [Boletines2Controller::class, 'putDetailedNotasGroup']);
Route::get('boletines2/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [Boletines2Controller::class, 'getDetailedNotasYear']);
Route::put('boletines2/detailed-notas/{grupo_id}', [Boletines2Controller::class, 'putDetailedNotas']);

// Boletines3Controller
Route::delete('boletines3/destroy/{id}', [Boletines3Controller::class, 'deleteDestroy']);
Route::put('boletines3/detailed-notas-group/{grupo_id}', [Boletines3Controller::class, 'putDetailedNotasGroup']);
Route::get('boletines3/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [Boletines3Controller::class, 'getDetailedNotasYear']);
Route::put('boletines3/detailed-notas/{grupo_id}', [Boletines3Controller::class, 'putDetailedNotas']);

// SimatController
Route::get('simat', [SimatController::class, 'getIndex'])->middleware('auth.token');
Route::get('simat/alumnos', [SimatController::class, 'getAlumnos']);
Route::get('simat/alumnos-exportar', [SimatController::class, 'getAlumnosExportar']);

// AcudientesExportController
Route::get('acudientes-export/acudientes', [AcudientesExportController::class, 'getAcudientes'])->middleware('auth.token');

// ExcelListadoDocentesController
Route::get('excel-docentes', [ExcelListadoDocentesController::class, 'getIndex'])->middleware('auth.token');
Route::get('excel-docentes/docentes/{year}/{year_id}', [ExcelListadoDocentesController::class, 'getDocentes']);

// ObservadorController
Route::get('observador', [ObservadorController::class, 'getIndex'])->middleware('auth.token');
Route::get('observador/vertical-todos', [ObservadorController::class, 'getVerticalTodos']);
Route::get('observador/vertical/{grupo_id}/{tamanio}', [ObservadorController::class, 'getVertical']);

// ObservadorHorizontalController
Route::put('observador-horizontal/horizontal/{grupo_id}', [ObservadorHorizontalController::class, 'putHorizontal']);

// ActasEvaluacionController
Route::put('actas-evaluacion/acta-evaluacion-promocion', [ActasEvaluacionController::class, 'putActaEvaluacionPromocion']);
Route::put('actas-evaluacion/detalle', [ActasEvaluacionController::class, 'putDetalle']);

// CertificadosEstudioController
Route::get('certificados-estudio/certificado-alumno/{grupo_id}', [CertificadosEstudioController::class, 'getCertificadoAlumno']);
Route::get('certificados-estudio/certificado-grupo/{grupo_id}', [CertificadosEstudioController::class, 'getCertificadoGrupo']);
