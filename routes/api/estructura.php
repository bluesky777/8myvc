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
Route::post('niveles_educativos/store', [NivelesEducativosController::class, 'postStore']);
Route::delete('niveles_educativos/destroy/{id}', [NivelesEducativosController::class, 'deleteDestroy']);
Route::get('niveles_educativos/show/{id}', [NivelesEducativosController::class, 'getShow']);
Route::put('niveles_educativos/update/{id}', [NivelesEducativosController::class, 'putUpdate']);

// GradosController
Route::get('grados', [GradosController::class, 'getIndex']);
Route::post('grados/store', [GradosController::class, 'postStore']);
Route::delete('grados/destroy/{id}', [GradosController::class, 'deleteDestroy']);
Route::get('grados/show/{id}', [GradosController::class, 'getShow']);
Route::put('grados/update/{id}', [GradosController::class, 'putUpdate']);

// GruposController
Route::get('grupos', [GruposController::class, 'getIndex']);
Route::put('grupos/alumnos-con-datos', [GruposController::class, 'putAlumnosConDatos']);
Route::get('grupos/cant-alumnos', [GruposController::class, 'getCantAlumnos']);
Route::put('grupos/con-cantidad-alumnos', [GruposController::class, 'putConCantidadAlumnos']);
Route::put('grupos/con-disciplina', [GruposController::class, 'putConDisciplina']);
Route::get('grupos/con-paises-tipos', [GruposController::class, 'getConPaisesTipos']);
Route::get('grupos/con-paises-tipos-next-year', [GruposController::class, 'getConPaisesTiposNextYear']);
Route::get('grupos/next-year', [GruposController::class, 'getNextYear']);
Route::post('grupos/store', [GruposController::class, 'postStore']);
Route::get('grupos/trashed', [GruposController::class, 'getTrashed']);
Route::put('grupos/update', [GruposController::class, 'putUpdate']);
Route::delete('grupos/destroy/{id}', [GruposController::class, 'deleteDestroy']);
Route::delete('grupos/forcedelete/{id}', [GruposController::class, 'deleteForcedelete']);
Route::get('grupos/listado/{grupo_id}', [GruposController::class, 'getListado']);
Route::put('grupos/restore/{id}', [GruposController::class, 'putRestore']);
Route::get('grupos/show/{id}', [GruposController::class, 'getShow']);

// ProfesoresController
Route::get('profesores', [ProfesoresController::class, 'getIndex']);
Route::get('profesores/conyears', [ProfesoresController::class, 'getConyears']);
Route::put('profesores/guardar-valor', [ProfesoresController::class, 'putGuardarValor']);
Route::put('profesores/listado', [ProfesoresController::class, 'putListado']);
Route::post('profesores/store', [ProfesoresController::class, 'postStore']);
Route::get('profesores/todos', [ProfesoresController::class, 'getTodos']);
Route::get('profesores/trashed', [ProfesoresController::class, 'getTrashed']);
Route::delete('profesores/destroy/{id}', [ProfesoresController::class, 'deleteDestroy']);
Route::delete('profesores/forcedelete/{id}', [ProfesoresController::class, 'deleteForcedelete']);
Route::put('profesores/restore/{id}', [ProfesoresController::class, 'putRestore']);
Route::get('profesores/show/{id}', [ProfesoresController::class, 'getShow']);
Route::put('profesores/update/{id}', [ProfesoresController::class, 'putUpdate']);

// ContratosController
Route::get('contratos', [ContratosController::class, 'getIndex']);
Route::post('contratos', [ContratosController::class, 'postIndex']);
Route::delete('contratos/destroy/{id}', [ContratosController::class, 'deleteDestroy']);

// YearsController
Route::get('years', [YearsController::class, 'getIndex']);
Route::put('years/alumnos-can-see-notas', [YearsController::class, 'putAlumnosCanSeeNotas']);
Route::get('years/colegio', [YearsController::class, 'getColegio']);
Route::put('years/guardar-cambios', [YearsController::class, 'putGuardarCambios']);
Route::put('years/mostrar-todas-materias', [YearsController::class, 'putMostrarTodasMaterias']);
Route::put('years/profes-can-edit-alumnos', [YearsController::class, 'putProfesCanEditAlumnos']);
Route::put('years/set-actual', [YearsController::class, 'putSetActual']);
Route::post('years/store', [YearsController::class, 'postStore']);
Route::put('years/toggle-cambiar-valor', [YearsController::class, 'putToggleCambiarValor']);
Route::put('years/toggle-ignorar-notas-perdidas', [YearsController::class, 'putToggleIgnorarNotasPerdidas']);
Route::put('years/toggle-mostrar-anio-pasado-en-boletin', [YearsController::class, 'putToggleMostrarAnioPasadoEnBoletin']);
Route::put('years/toggle-mostrar-nota-comport-en-boletin', [YearsController::class, 'putToggleMostrarNotaComportEnBoletin']);
Route::put('years/toggle-mostrar-puestos-en-boletin', [YearsController::class, 'putToggleMostrarPuestosEnBoletin']);
Route::put('years/toggle-solo-valorativas', [YearsController::class, 'putToggleSoloValorativas']);
Route::get('years/trashed', [YearsController::class, 'getTrashed']);
Route::delete('years/delete/{id}', [YearsController::class, 'deleteDelete']);
Route::delete('years/destroy/{id}', [YearsController::class, 'deleteDestroy']);
Route::put('years/restore/{id}', [YearsController::class, 'putRestore']);
Route::put('years/useractive/{year_id}', [YearsController::class, 'putUseractive']);

// PeriodosController
Route::get('periodos', [PeriodosController::class, 'getIndex']);
Route::put('periodos/cambiar-fecha-fin', [PeriodosController::class, 'putCambiarFechaFin']);
Route::put('periodos/cambiar-fecha-inicio', [PeriodosController::class, 'putCambiarFechaInicio']);
Route::put('periodos/copiar', [PeriodosController::class, 'putCopiar']);
Route::put('periodos/toggle-profes-pueden-editar-notas', [PeriodosController::class, 'putToggleProfesPuedenEditarNotas']);
Route::put('periodos/toggle-profes-pueden-nivelar', [PeriodosController::class, 'putToggleProfesPuedenNivelar']);
Route::delete('periodos/destroy/{periodo_id}', [PeriodosController::class, 'deleteDestroy']);
Route::put('periodos/establecer-actual/{periodo_id}', [PeriodosController::class, 'putEstablecerActual']);
Route::get('periodos/show/{year_id}', [PeriodosController::class, 'getShow']);
Route::post('periodos/store/{year_id}', [PeriodosController::class, 'postStore']);
Route::put('periodos/update/{id}', [PeriodosController::class, 'putUpdate']);
Route::put('periodos/useractive/{periodo_id}', [PeriodosController::class, 'putUseractive']);

// PiarsGruposController
//
// El constructor comprobaba `!$user->is_superuser && !$user->tipo == 'Profesor'`,
// que PHP agrupa como `(!$tipo) == 'Profesor'` y nunca es cierto.
Route::put('piars-grupos/contexto-de-grupo', [PiarsGruposController::class, 'putContextoDeGrupo'])->middleware('auth.personal');
Route::get('piars-grupos/grupos', [PiarsGruposController::class, 'getGrupos'])->middleware('auth.personal');
Route::get('piars-grupos/contexto-de-grupo/{grupo_id}', [PiarsGruposController::class, 'getContextoDeGrupo'])->middleware('auth.personal');
