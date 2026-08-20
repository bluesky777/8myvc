<?php

use App\Http\Controllers\AcudientesController;
use App\Http\Controllers\Alumnos\FoliosController;
use App\Http\Controllers\Alumnos\ImportarController;
use App\Http\Controllers\AlumnosController;
use App\Http\Controllers\BuscarController;
use App\Http\Controllers\CarteraController;
use App\Http\Controllers\DetallesController;
use App\Http\Controllers\Informes\NotasActualesAlumnosController;
use App\Http\Controllers\Matriculas\EnfermeriaController;
use App\Http\Controllers\Matriculas\MatriculasController;
use App\Http\Controllers\Matriculas\PrematriculasController;
use App\Http\Controllers\Matriculas\RequisitosController;
use App\Http\Controllers\Piars\PiarsAlumnosController;
use App\Http\Controllers\PromovidosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: alumnos
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// AlumnosController
Route::get('alumnos', [AlumnosController::class, 'getIndex']);
Route::put('alumnos/cambiar-claves', [AlumnosController::class, 'putCambiarClaves']);
Route::put('alumnos/documento-check', [AlumnosController::class, 'putDocumentoCheck'])->middleware('auth.personal');
Route::put('alumnos/eps-check', [AlumnosController::class, 'putEpsCheck']);
Route::put('alumnos/guardar-valor', [AlumnosController::class, 'putGuardarValor']);
Route::put('alumnos/guardar-valor-varios', [AlumnosController::class, 'putGuardarValorVarios']);
Route::put('alumnos/personas-check', [AlumnosController::class, 'putPersonasCheck'])->middleware('auth.personal');
Route::put('alumnos/show', [AlumnosController::class, 'putShow']);
Route::get('alumnos/sin-matriculas', [AlumnosController::class, 'getSinMatriculas'])->middleware('auth.personal');
Route::post('alumnos/store', [AlumnosController::class, 'postStore']);
Route::get('alumnos/trashed', [AlumnosController::class, 'getTrashed'])->middleware('auth.personal');
Route::put('alumnos/years-con-notas', [AlumnosController::class, 'putYearsConNotas'])->middleware('persona.propia');
Route::put('alumnos/de-grupo/{grupo_id}', [AlumnosController::class, 'putDeGrupo'])->middleware('auth.personal');
Route::delete('alumnos/destroy/{id}', [AlumnosController::class, 'deleteDestroy']);
Route::delete('alumnos/forcedelete/{id}', [AlumnosController::class, 'deleteForcedelete']);
Route::put('alumnos/restore/{id}', [AlumnosController::class, 'putRestore']);
Route::put('alumnos/update/{id}', [AlumnosController::class, 'putUpdate']);

// ImportarController
Route::get('importar', [ImportarController::class, 'getIndex']);
Route::post('importar/cartera', [ImportarController::class, 'postCartera']);
Route::post('importar/algo/{year}', [ImportarController::class, 'postAlgo']);
Route::get('importar/modificar/{year}', [ImportarController::class, 'getModificar']);

// FoliosController
Route::get('folios/iniciar', [FoliosController::class, 'getIniciar']);

// AcudientesController
Route::put('acudientes/buscar', [AcudientesController::class, 'putBuscar']);
Route::post('acudientes/crear', [AcudientesController::class, 'postCrear']);
Route::post('acudientes/crear-usuario', [AcudientesController::class, 'postCrearUsuario']);
Route::put('acudientes/datos', [AcudientesController::class, 'putDatos']);
Route::put('acudientes/de-persona', [AcudientesController::class, 'putDePersona'])->middleware('persona.propia');
Route::put('acudientes/guardar-valor', [AcudientesController::class, 'putGuardarValor']);
Route::put('acudientes/mis-acudidos', [AcudientesController::class, 'putMisAcudidos']);
Route::put('acudientes/no-asignados', [AcudientesController::class, 'putNoAsignados']);
Route::put('acudientes/ocupaciones-check', [AcudientesController::class, 'putOcupacionesCheck']);
Route::put('acudientes/planillas-ausencias', [AcudientesController::class, 'putPlanillasAusencias']);
Route::put('acudientes/quitar-parentesco-alumno', [AcudientesController::class, 'putQuitarParentescoAlumno'])->middleware('auth.personal');
Route::put('acudientes/seleccionar-parentesco', [AcudientesController::class, 'putSeleccionarParentesco'])->middleware('auth.personal');
Route::put('acudientes/ultimos', [AcudientesController::class, 'putUltimos']);
Route::delete('acudientes/destroy/{id}', [AcudientesController::class, 'deleteDestroy']);

// BuscarController
Route::put('buscar/por-apellido', [BuscarController::class, 'putPorApellido']);
Route::put('buscar/por-nombre', [BuscarController::class, 'putPorNombre']);

// MatriculasController
Route::put('matriculas/alumnos-con-grado-anterior', [MatriculasController::class, 'putAlumnosConGradoAnterior']);
Route::put('matriculas/alumnos-grado-anterior', [MatriculasController::class, 'putAlumnosGradoAnterior']);
Route::put('matriculas/cambiar-fecha-matricula', [MatriculasController::class, 'putCambiarFechaMatricula']);
Route::put('matriculas/cambiar-fecha-retiro', [MatriculasController::class, 'putCambiarFechaRetiro']);
Route::put('matriculas/desertar', [MatriculasController::class, 'putDesertar']);
Route::post('matriculas/matricular-en', [MatriculasController::class, 'postMatricularEn']);
Route::post('matriculas/matricularuno', [MatriculasController::class, 'postMatricularuno']);
// La única escritura de matrículas abierta a Alumno y Acudiente: la prematrícula
// del año siguiente la hace la familia desde su cuenta. No miraba de quién era el
// `alumno_id` del cuerpo, así que un alumno le cambiaba el estado y el grupo a
// cualquier compañero. Sin paz y salvo: retener el boletín de quien debe es una
// cosa, impedirle matricularse el año siguiente es otra.
Route::put('matriculas/prematricular', [MatriculasController::class, 'putPrematricular'])
    ->middleware('boletin.propio:sin-paz-y-salvo');
Route::put('matriculas/quitar-prematricula', [MatriculasController::class, 'putQuitarPrematricula']);
Route::put('matriculas/re-matricularuno', [MatriculasController::class, 'putReMatricularuno']);
Route::put('matriculas/retirar', [MatriculasController::class, 'putRetirar']);
Route::put('matriculas/set-asistente', [MatriculasController::class, 'putSetAsistente']);
Route::put('matriculas/set-new-asistente', [MatriculasController::class, 'putSetNewAsistente']);
Route::put('matriculas/set-promovido', [MatriculasController::class, 'putSetPromovido']);
Route::put('matriculas/toggle-nuevo', [MatriculasController::class, 'putToggleNuevo']);
Route::delete('matriculas/destroy/{id}', [MatriculasController::class, 'deleteDestroy']);

// EnfermeriaController
Route::post('enfermeria/crear-suceso', [EnfermeriaController::class, 'postCrearSuceso']);
Route::put('enfermeria/datos', [EnfermeriaController::class, 'putDatos'])->middleware('persona.propia');
Route::put('enfermeria/guardar-valor', [EnfermeriaController::class, 'putGuardarValor']);
Route::put('enfermeria/guardar-valor-suceso', [EnfermeriaController::class, 'putGuardarValorSuceso']);
Route::delete('enfermeria/destroy/{id}', [EnfermeriaController::class, 'deleteDestroy']);

// PrematriculasController
//
// Las tres las cerraba —o eso pretendía— un `return 'No tienes permiso';` en el
// constructor, que no detiene nada. Ver App\Http\Middleware\ExigirPersonal.
Route::put('prematriculas/alumnos-con-grado-anterior', [PrematriculasController::class, 'putAlumnosConGradoAnterior'])->middleware('auth.personal');
Route::put('prematriculas/alumnos-grado-anterior', [PrematriculasController::class, 'putAlumnosGradoAnterior'])->middleware('auth.personal');
// `prematriculas/llevo-formulario` se borró el 19 ago 2026: escribía en una tabla
// que no existe y el dato ya se guarda como `matriculas.estado = 'FORM'`, que es
// por donde lo mueve el administrador con `matriculas/prematricular`. Ver
// PrematriculasController.

// RequisitosController
//
// Ninguno de sus seis métodos comprueba nada: el único intento estaba en el
// constructor y no se ejecutaba. Un alumno podía borrar requisitos de matrícula.
Route::put('requisitos', [RequisitosController::class, 'putIndex'])->middleware('auth.personal');
Route::post('requisitos/alumno', [RequisitosController::class, 'postAlumno'])->middleware('auth.personal');
Route::put('requisitos/listado-observaciones', [RequisitosController::class, 'putListadoObservaciones'])->middleware('auth.personal');
Route::post('requisitos/store', [RequisitosController::class, 'postStore'])->middleware('auth.personal');
Route::put('requisitos/update', [RequisitosController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('requisitos/destroy/{id}', [RequisitosController::class, 'deleteDestroy'])->middleware('auth.personal');

// CarteraController
Route::put('cartera/alumnos', [CarteraController::class, 'putAlumnos']);
Route::get('cartera/exportar-solo-deudores', [CarteraController::class, 'getExportarSoloDeudores']);
Route::put('cartera/solo-deudores', [CarteraController::class, 'putSoloDeudores']);

// DetallesController
Route::put('detalles/alumno', [DetallesController::class, 'putAlumno'])->middleware('persona.propia');
Route::put('detalles/eliminar-matricula-destroy', [DetallesController::class, 'putEliminarMatriculaDestroy'])->middleware('auth.personal');
Route::put('detalles/eliminar-notas-periodo', [DetallesController::class, 'putEliminarNotasPeriodo'])->middleware('auth.personal');
Route::put('detalles/grupos-periodos', [DetallesController::class, 'putGruposPeriodos'])->middleware('auth.personal');

// NotasActualesAlumnosController
// Misma familia que los boletines: sirve las notas de los alumnos que se le
// pidan por `requested_alumnos`.
Route::put('notas-actuales-alumnos/{grupo_id}', [NotasActualesAlumnosController::class, 'putIndex'])->middleware('boletin.propio');

// PromovidosController
Route::put('promovidos/calcular-grupo', [PromovidosController::class, 'putCalcularGrupo']);

// PiarsAlumnosController
Route::post('piars-alumnos/document', [PiarsAlumnosController::class, 'postDocument'])->middleware('persona.propia');
// `auth.personal` desde el 20 ago 2026. Era la única ruta `piars-*` de
// escritura sin guard ninguno, y el método tampoco miraba el tipo: con un token
// cualquiera —de alumno o de acudiente— se podía mandar
// `{id, field: 'reporte', text: '...'}` con el id de CUALQUIER fila de
// `piars_alumnos` y reescribir la valoración pedagógica de cualquier estudiante.
// Que además el texto se pintara sin sanear es lo que lo convertía en ejecución
// de JavaScript en la sesión del docente; eso se cierra aparte, en HtmlDelEditor.
//
// El guard deja fuera a alumnos y acudientes, que es hasta donde llega la regla
// de hoy. Que un profesor solo pueda escribir en los PIAR de SUS grupos es el
// refactor de permisos entre personal, pendiente (06-autorizacion.md).
Route::put('piars-alumnos/field', [PiarsAlumnosController::class, 'putField'])->middleware('auth.personal');
Route::get('piars-alumnos/alumnos/{grupo_id}', [PiarsAlumnosController::class, 'getAlumnos'])->middleware('auth.personal');
Route::delete('piars-alumnos/document/{alumno_id}', [PiarsAlumnosController::class, 'deleteDocument'])->middleware('persona.propia');
