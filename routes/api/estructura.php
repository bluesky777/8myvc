<?php

use App\Http\Controllers\ContratosController;
use App\Http\Controllers\GradosController;
use App\Http\Controllers\GruposController;
use App\Http\Controllers\NivelesEducativosController;
use App\Http\Controllers\PeriodosController;
use App\Http\Controllers\Piars\PiarsGruposController;
use App\Http\Controllers\ProfesoresController;
use App\Http\Controllers\YearsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas: estructura
|--------------------------------------------------------------------------
|
| Generado por tools/route-emit.php a partir de la tabla de rutas que
| AdvancedRoute registraba. El orden es el de registro y es significativo:
| las rutas sin parámetros van antes que las que llevan {param} para que no
| queden tapadas. No reordenar sin comprobar con tools/route-table-dump.php.
|
*/

// NivelesEducativosController
Route::get('niveles_educativos', [NivelesEducativosController::class, 'getIndex']);
Route::post('niveles_educativos/store', [NivelesEducativosController::class, 'postStore'])->middleware('auth.personal');
Route::delete('niveles_educativos/destroy/{id}', [NivelesEducativosController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('niveles_educativos/show/{id}', [NivelesEducativosController::class, 'getShow']);
Route::put('niveles_educativos/update/{id}', [NivelesEducativosController::class, 'putUpdate'])->middleware('auth.personal');

// GradosController
Route::get('grados', [GradosController::class, 'getIndex']);
Route::post('grados/store', [GradosController::class, 'postStore'])->middleware('auth.personal');
Route::delete('grados/destroy/{id}', [GradosController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::get('grados/show/{id}', [GradosController::class, 'getShow']);
Route::put('grados/update/{id}', [GradosController::class, 'putUpdate'])->middleware('auth.personal');

// GruposController
Route::get('grupos', [GruposController::class, 'getIndex']);
Route::put('grupos/alumnos-con-datos', [GruposController::class, 'putAlumnosConDatos'])->middleware('auth.personal');
Route::get('grupos/cant-alumnos', [GruposController::class, 'getCantAlumnos'])->middleware('auth.personal');
Route::put('grupos/con-cantidad-alumnos', [GruposController::class, 'putConCantidadAlumnos'])->middleware('auth.personal');
Route::put('grupos/con-disciplina', [GruposController::class, 'putConDisciplina'])->middleware('auth.personal');
Route::get('grupos/con-paises-tipos', [GruposController::class, 'getConPaisesTipos'])->middleware('auth.personal');
Route::get('grupos/con-paises-tipos-next-year', [GruposController::class, 'getConPaisesTiposNextYear'])->middleware('auth.personal');
Route::get('grupos/next-year', [GruposController::class, 'getNextYear'])->middleware('auth.personal');
Route::post('grupos/store', [GruposController::class, 'postStore'])->middleware('auth.personal');
Route::get('grupos/trashed', [GruposController::class, 'getTrashed'])->middleware('auth.personal');
Route::put('grupos/update', [GruposController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('grupos/destroy/{id}', [GruposController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::delete('grupos/forcedelete/{id}', [GruposController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::get('grupos/listado/{grupo_id}', [GruposController::class, 'getListado'])->middleware('auth.personal');
Route::put('grupos/restore/{id}', [GruposController::class, 'putRestore'])->middleware('auth.personal');
// Devuelve el grupo con la ficha ENTERA de su titular —documento, dirección,
// teléfono, correo, fecha de nacimiento—, y `{id}` es un grupo, no una persona:
// por eso ningún inventario de autorización lo señaló. No la llama ningún
// cliente. §14 del mismo documento.
Route::get('grupos/show/{id}', [GruposController::class, 'getShow'])->middleware('auth.personal');

// ProfesoresController
// El listado es la única ruta de este controlador que no llevaba `auth.personal`,
// y es la que trae la hoja de vida de los 47 docentes. Lo que la piden son cinco
// pantallas de administración; la app de Flutter usa /contratos, no esta.
Route::get('profesores', [ProfesoresController::class, 'getIndex'])->middleware('auth.personal');
Route::get('profesores/conyears', [ProfesoresController::class, 'getConyears'])->middleware('auth.personal');
Route::put('profesores/guardar-valor', [ProfesoresController::class, 'putGuardarValor'])->middleware('auth.personal');
Route::put('profesores/listado', [ProfesoresController::class, 'putListado'])->middleware('auth.personal');
Route::post('profesores/store', [ProfesoresController::class, 'postStore'])->middleware('auth.personal');
Route::get('profesores/todos', [ProfesoresController::class, 'getTodos'])->middleware('auth.personal');
Route::get('profesores/trashed', [ProfesoresController::class, 'getTrashed'])->middleware('auth.personal');
Route::delete('profesores/destroy/{id}', [ProfesoresController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::delete('profesores/forcedelete/{id}', [ProfesoresController::class, 'deleteForcedelete'])->middleware('auth.personal');
Route::put('profesores/restore/{id}', [ProfesoresController::class, 'putRestore'])->middleware('auth.personal');
Route::get('profesores/show/{id}', [ProfesoresController::class, 'getShow'])->middleware('auth.personal');
Route::put('profesores/update/{id}', [ProfesoresController::class, 'putUpdate'])->middleware('auth.personal');

// ContratosController
Route::get('contratos', [ContratosController::class, 'getIndex']);
Route::post('contratos', [ContratosController::class, 'postIndex'])->middleware('auth.personal');
Route::delete('contratos/destroy/{id}', [ContratosController::class, 'deleteDestroy'])->middleware('auth.personal');

// YearsController
Route::get('years', [YearsController::class, 'getIndex']);
Route::put('years/alumnos-can-see-notas', [YearsController::class, 'putAlumnosCanSeeNotas'])->middleware('auth.personal');
Route::get('years/colegio', [YearsController::class, 'getColegio']);
Route::put('years/guardar-cambios', [YearsController::class, 'putGuardarCambios'])->middleware('auth.personal');
Route::put('years/mostrar-todas-materias', [YearsController::class, 'putMostrarTodasMaterias'])->middleware('auth.personal');
Route::put('years/profes-can-edit-alumnos', [YearsController::class, 'putProfesCanEditAlumnos'])->middleware('auth.personal');
Route::put('years/set-actual', [YearsController::class, 'putSetActual'])->middleware('auth.personal');
Route::post('years/store', [YearsController::class, 'postStore'])->middleware('auth.personal');
Route::put('years/toggle-cambiar-valor', [YearsController::class, 'putToggleCambiarValor'])->middleware('auth.personal');
Route::put('years/toggle-ignorar-notas-perdidas', [YearsController::class, 'putToggleIgnorarNotasPerdidas'])->middleware('auth.personal');
Route::put('years/toggle-mostrar-anio-pasado-en-boletin', [YearsController::class, 'putToggleMostrarAnioPasadoEnBoletin'])->middleware('auth.personal');
Route::put('years/toggle-mostrar-nota-comport-en-boletin', [YearsController::class, 'putToggleMostrarNotaComportEnBoletin'])->middleware('auth.personal');
Route::put('years/toggle-mostrar-puestos-en-boletin', [YearsController::class, 'putToggleMostrarPuestosEnBoletin'])->middleware('auth.personal');
Route::put('years/toggle-solo-valorativas', [YearsController::class, 'putToggleSoloValorativas'])->middleware('auth.personal');
Route::get('years/trashed', [YearsController::class, 'getTrashed'])->middleware('auth.personal');
Route::delete('years/delete/{id}', [YearsController::class, 'deleteDelete'])->middleware('auth.personal');
Route::delete('years/destroy/{id}', [YearsController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('years/restore/{id}', [YearsController::class, 'putRestore'])->middleware('auth.personal');
Route::put('years/useractive/{year_id}', [YearsController::class, 'putUseractive'])->middleware('auth.personal');

// PeriodosController
Route::get('periodos', [PeriodosController::class, 'getIndex']);
Route::put('periodos/cambiar-fecha-fin', [PeriodosController::class, 'putCambiarFechaFin'])->middleware('auth.personal');
Route::put('periodos/cambiar-fecha-inicio', [PeriodosController::class, 'putCambiarFechaInicio'])->middleware('auth.personal');
Route::put('periodos/copiar', [PeriodosController::class, 'putCopiar'])->middleware('auth.personal');
Route::put('periodos/toggle-profes-pueden-editar-notas', [PeriodosController::class, 'putToggleProfesPuedenEditarNotas'])->middleware('auth.personal');
Route::put('periodos/toggle-profes-pueden-nivelar', [PeriodosController::class, 'putToggleProfesPuedenNivelar'])->middleware('auth.personal');
Route::delete('periodos/destroy/{periodo_id}', [PeriodosController::class, 'deleteDestroy'])->middleware('auth.personal');
Route::put('periodos/establecer-actual/{periodo_id}', [PeriodosController::class, 'putEstablecerActual'])->middleware('auth.personal');
Route::get('periodos/show/{year_id}', [PeriodosController::class, 'getShow']);
Route::post('periodos/store/{year_id}', [PeriodosController::class, 'postStore'])->middleware('auth.personal');
Route::put('periodos/update/{id}', [PeriodosController::class, 'putUpdate'])->middleware('auth.personal');
Route::put('periodos/useractive/{periodo_id}', [PeriodosController::class, 'putUseractive'])->middleware('auth.personal');

// PiarsGruposController
//
// El constructor comprobaba `!$user->is_superuser && !$user->tipo == 'Profesor'`,
// que PHP agrupa como `(!$tipo) == 'Profesor'` y nunca es cierto.
Route::put('piars-grupos/contexto-de-grupo', [PiarsGruposController::class, 'putContextoDeGrupo'])->middleware('auth.personal');
Route::get('piars-grupos/grupos', [PiarsGruposController::class, 'getGrupos'])->middleware('auth.personal');
Route::get('piars-grupos/contexto-de-grupo/{grupo_id}', [PiarsGruposController::class, 'getContextoDeGrupo'])->middleware('auth.personal');
