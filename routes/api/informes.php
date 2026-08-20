<?php

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
use Illuminate\Support\Facades\Route;

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
Route::get('certificados', [ConfigCertificadosController::class, 'getIndex'])->middleware('auth.personal');
Route::put('certificados/actual', [ConfigCertificadosController::class, 'putActual'])->middleware('auth.personal');
Route::put('certificados/encabezado', [ConfigCertificadosController::class, 'putEncabezado'])->middleware('auth.personal');
Route::post('certificados/store', [ConfigCertificadosController::class, 'postStore'])->middleware('auth.personal');
Route::put('certificados/update', [ConfigCertificadosController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('certificados/destroy/{id}', [ConfigCertificadosController::class, 'deleteDestroy'])->middleware('auth.personal');

// HistorialesController
Route::put('historiales/de-usuario', [HistorialesController::class, 'putDeUsuario'])->middleware('auth.personal');
Route::put('historiales/nota-detalle', [HistorialesController::class, 'putNotaDetalle'])->middleware('auth.personal');
Route::put('historiales/nota-final-detalle', [HistorialesController::class, 'putNotaFinalDetalle'])->middleware('auth.personal');
Route::put('historiales/sesion', [HistorialesController::class, 'putSesion'])->middleware('auth.personal');

// InformesController
Route::put('informes/cumpleanos-por-meses', [InformesController::class, 'putCumpleanosPorMeses'])->middleware('auth.personal');
Route::put('informes/datos', [InformesController::class, 'putDatos'])->middleware('auth.personal');

// CertificadosPersonaController
//
// Devuelve las matrículas de un alumno. Pide el alumno con `alumno_id` suelto en
// vez de `requested_alumnos`; el middleware entiende las dos formas.
Route::put('certificados-persona', [CertificadosPersonaController::class, 'putIndex'])->middleware('boletin.propio');

// BolfinalesPreescolarController
Route::put('bolfinales-preescolar/crear-frase', [BolfinalesPreescolarController::class, 'putCrearFrase'])->middleware('auth.personal');
Route::put('bolfinales-preescolar/eliminar-frase', [BolfinalesPreescolarController::class, 'putEliminarFrase'])->middleware('auth.personal');
Route::put('bolfinales-preescolar/guardar-frase', [BolfinalesPreescolarController::class, 'putGuardarFrase'])->middleware('auth.personal');
Route::put('bolfinales-preescolar/detailed-notas-year-group/{grupo_id}', [BolfinalesPreescolarController::class, 'putDetailedNotasYearGroup'])->middleware('boletin.propio');
Route::put('bolfinales-preescolar/detailed-notas-year/{grupo_id}', [BolfinalesPreescolarController::class, 'putDetailedNotasYear'])->middleware('boletin.propio');

// PuestosController
Route::put('puestos/detailed-notas-year', [PuestosController::class, 'putDetailedNotasYear'])->middleware('auth.personal');
Route::put('puestos/detailed-notas-periodo/{grupo_id}', [PuestosController::class, 'putDetailedNotasPeriodo'])->middleware('auth.personal');

// NotasPerdidasController
Route::put('notas-perdidas/profesor-grupos', [NotasPerdidasController::class, 'putProfesorGrupos'])->middleware('auth.personal');
Route::put('notas-perdidas/todos', [NotasPerdidasController::class, 'putTodos'])->middleware('auth.personal');
Route::get('notas-perdidas/show-profesor/{profesor_id}', [NotasPerdidasController::class, 'getShowProfesor'])->middleware('auth.personal');

// BoletinesController
//
// Los tres controladores de boletines son copias con distinta maqueta y sirven
// el mismo dato. `boletin.propio` impide que un alumno pida el de otro y que un
// acudiente pida el de quien no es su acudido. Estaba escrito en el constructor
// del primero y no se ejecutaba nunca; los otros dos ni lo tenían. Ver
// App\Http\Middleware\ExigirBoletinPropio.
Route::put('boletines/detailed-notas-group/{grupo_id}', [BoletinesController::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');
Route::get('boletines/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [BoletinesController::class, 'getDetailedNotasYear'])->middleware('boletin.propio');
Route::put('boletines/detailed-notas/{grupo_id}', [BoletinesController::class, 'putDetailedNotas'])->middleware('boletin.propio');

// Boletines2Controller
Route::delete('boletines2/destroy/{id}', [Boletines2Controller::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('boletines2/detailed-notas-group/{grupo_id}', [Boletines2Controller::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');
Route::get('boletines2/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [Boletines2Controller::class, 'getDetailedNotasYear'])->middleware('boletin.propio');
Route::put('boletines2/detailed-notas/{grupo_id}', [Boletines2Controller::class, 'putDetailedNotas'])->middleware('boletin.propio');

// Boletines3Controller
Route::delete('boletines3/destroy/{id}', [Boletines3Controller::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('boletines3/detailed-notas-group/{grupo_id}', [Boletines3Controller::class, 'putDetailedNotasGroup'])->middleware('boletin.propio');
Route::get('boletines3/detailed-notas-year/{grupo_id}/{periodo_a_calcular?}', [Boletines3Controller::class, 'getDetailedNotasYear'])->middleware('boletin.propio');
Route::put('boletines3/detailed-notas/{grupo_id}', [Boletines3Controller::class, 'putDetailedNotas'])->middleware('boletin.propio');

// SimatController
Route::get('simat', [SimatController::class, 'getIndex'])->middleware('auth.personal');
Route::get('simat/alumnos', [SimatController::class, 'getAlumnos'])->middleware('auth.personal');
Route::get('simat/alumnos-exportar', [SimatController::class, 'getAlumnosExportar'])->middleware('auth.personal');

// AcudientesExportController
Route::get('acudientes-export/acudientes', [AcudientesExportController::class, 'getAcudientes'])->middleware('auth.personal');

// ExcelListadoDocentesController
Route::get('excel-docentes', [ExcelListadoDocentesController::class, 'getIndex'])->middleware('auth.personal');
Route::get('excel-docentes/docentes/{year}/{year_id}', [ExcelListadoDocentesController::class, 'getDocentes'])->middleware('auth.personal');

// ObservadorController
Route::get('observador', [ObservadorController::class, 'getIndex'])->middleware('auth.personal');
Route::get('observador/vertical-todos', [ObservadorController::class, 'getVerticalTodos'])->middleware('auth.personal');
Route::get('observador/vertical/{grupo_id}/{tamanio}', [ObservadorController::class, 'getVertical'])->middleware('auth.personal');

// ObservadorHorizontalController
Route::put('observador-horizontal/horizontal/{grupo_id}', [ObservadorHorizontalController::class, 'putHorizontal'])->middleware('auth.personal');

// ActasEvaluacionController
Route::put('actas-evaluacion/acta-evaluacion-promocion', [ActasEvaluacionController::class, 'putActaEvaluacionPromocion'])->middleware('auth.personal');
Route::put('actas-evaluacion/detalle', [ActasEvaluacionController::class, 'putDetalle'])->middleware('auth.personal');
// La pantalla del acta llamaba a esta ruta desde siempre y no existía: guardar el texto del
// acta fallaba con 404 en silencio. Ahora guarda el texto y los firmantes de la comisión.
// Con auth.personal porque es la única escritura de este módulo: el resto de actas-evaluacion
// son lecturas, y el texto del acta es configuración del año lectivo.
Route::put('actas-evaluacion/cambiar-descripcion', [ActasEvaluacionController::class, 'putGuardarTextoActa'])->middleware('auth.personal');

// CertificadosEstudioController
Route::get('certificados-estudio/certificado-alumno/{grupo_id}', [CertificadosEstudioController::class, 'getCertificadoAlumno'])->middleware('auth.personal');
Route::get('certificados-estudio/certificado-grupo/{grupo_id}', [CertificadosEstudioController::class, 'getCertificadoGrupo'])->middleware('auth.personal');
