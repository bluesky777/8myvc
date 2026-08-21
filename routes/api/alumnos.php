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
// El directorio de alumnos del colegio ENTERO —nombre, fecha de nacimiento,
// celular, dirección, religión y estado de deuda de cada uno— sin filtrar por
// grupo ni por año. Iba sin más guard que el token: cualquier alumno o acudiente
// lo leía. Es el quinto de la familia de la §14, y el que faltaba: no nombra a
// nadie, así que ningún inventario tenía nada que señalar, y no está mudo, así
// que tampoco cayó en las listas de «sin juzgar» de la §24. Lo encontró volver a
// correr el barrido después de tocar la autorización.
//
// `auth.personal` es lo que llevan sus quince hermanas de este mismo fichero, y
// lo que la §14 decidió siete veces para exactamente esta forma. Ningún cliente
// la llama —`AlumnosApi.ts` enumera diecisiete rutas de este recurso y ésta no
// está—. Ver 05 §34.
Route::get('alumnos', [AlumnosController::class, 'getIndex'])->middleware('auth.personal');
// Toma `clave` y `grupo_id` del CUERPO y le pone esa contraseña a todos los
// alumnos del grupo. No nombra a ninguna persona —nombra un grupo—, que es por
// lo que ningún inventario lo señaló. Ver 05 §15.
Route::put('alumnos/cambiar-claves', [AlumnosController::class, 'putCambiarClaves'])->middleware('auth.personal');
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
//
// Las cuatro con `auth.personal`, y la que importa es `algo/{year}`: es el
// importador VIVO —los otros tres están rotos con la firma de maatwebsite 2.x,
// 05 §8— y no llevaba guard ninguno. Medido: un alumno sube una hoja y la
// importación se ejecuta ENTERA a su nombre —`estado: completada`, 37 filas,
// `created_by` el suyo— escribiendo alumnos, matrículas, acudientes y
// parentescos. El barrido no podía verlo porque `Request::hasFile('file')` es
// false cuando se golpea sin archivo, y él golpea sin archivo. Ver 05 §19.
Route::get('importar', [ImportarController::class, 'getIndex'])->middleware('auth.personal');
Route::post('importar/cartera', [ImportarController::class, 'postCartera'])->middleware('auth.personal');
Route::post('importar/algo/{year}', [ImportarController::class, 'postAlgo'])->middleware('auth.personal');
Route::get('importar/modificar/{year}', [ImportarController::class, 'getModificar'])->middleware('auth.personal');

// FoliosController
// Un `UPDATE matriculas` sobre todas las del año actual sin número de folio, sin
// mirar el token ni una vez —el método no llama a `fromToken()`—. En el seed
// afecta a cero filas porque todas tienen folio, y por eso el barrido la
// enseñaba escribiendo sin que se viera el daño. Ver 05 §19.
Route::get('folios/iniciar', [FoliosController::class, 'getIniciar'])->middleware('auth.personal');

// AcudientesController
// Las cuatro devuelven el fichero de acudientes con documento, celular,
// dirección y fecha de nacimiento. Se leen con PUT —el patrón de este
// proyecto—, y por eso el primer barrido, que solo miró GET, no las vio.
// Las piden `NewAcudienteModalCtrl`, `AcudientesCtrl` e `informes`.
Route::put('acudientes/buscar', [AcudientesController::class, 'putBuscar'])->middleware('auth.personal');
Route::post('acudientes/crear', [AcudientesController::class, 'postCrear']);
// Crea un `User` de tipo Acudiente con `Hash::make('123456')` y REAPUNTA
// `acudientes.user_id` a la cuenta nueva, así que la que hubiera queda fuera y
// entra una cuya contraseña conoce quien la pidió. Sin una sola comprobación.
// Solo lo llaman pantallas de personal —el botón «Crear su usuario (aún no
// tiene)» de `AlumnosCtrl` y `PrematriculasCtrl`—. Ver 05 §23.
Route::post('acudientes/crear-usuario', [AcudientesController::class, 'postCrearUsuario'])->middleware('auth.personal');
// Devuelve TODOS los acudientes del grupo que le nombren, con documento,
// teléfono, celular, email, dirección y fecha de nacimiento — y la consulta
// filtra por grupo y no por año, así que vale cualquier grupo del colegio. Es la
// rejilla del personal: sus `columnDefs` traen el botón de resetear contraseña.
// Ver 05 §23.
Route::put('acudientes/datos', [AcudientesController::class, 'putDatos'])->middleware('auth.personal');
Route::put('acudientes/de-persona', [AcudientesController::class, 'putDePersona'])->middleware('persona.propia');
Route::put('acudientes/guardar-valor', [AcudientesController::class, 'putGuardarValor']);
Route::put('acudientes/mis-acudidos', [AcudientesController::class, 'putMisAcudidos']);
Route::put('acudientes/no-asignados', [AcudientesController::class, 'putNoAsignados'])->middleware('auth.personal');
Route::put('acudientes/ocupaciones-check', [AcudientesController::class, 'putOcupacionesCheck']);
Route::put('acudientes/planillas-ausencias', [AcudientesController::class, 'putPlanillasAusencias'])->middleware('auth.personal');
Route::put('acudientes/quitar-parentesco-alumno', [AcudientesController::class, 'putQuitarParentescoAlumno'])->middleware('auth.personal');
Route::put('acudientes/seleccionar-parentesco', [AcudientesController::class, 'putSeleccionarParentesco'])->middleware('auth.personal');
Route::put('acudientes/ultimos', [AcudientesController::class, 'putUltimos'])->middleware('auth.personal');
Route::delete('acudientes/destroy/{id}', [AcudientesController::class, 'deleteDestroy']);

// BuscarController
// Los otros dos buscadores. `alumnos/personas-check` y `alumnos/documento-check`
// se cerraron en 05 §11.3 y éstos se quedaron, porque viven en otra familia y
// ninguna herramienta mira una ruta que recibe `texto_a_buscar` y no un id.
// Devolvían a cualquier alumno 49 compañeros con su `alumno_id` y su grupo, que
// es justo «quién reparte las llaves» de 08 §4. Ver 05 §17.
Route::put('buscar/por-apellido', [BuscarController::class, 'putPorApellido'])->middleware('auth.personal');
Route::put('buscar/por-nombre', [BuscarController::class, 'putPorNombre'])->middleware('auth.personal');

// MatriculasController
// Su gemela de `prematriculas`, cuatro líneas más abajo, sí lo llevaba.
Route::put('matriculas/alumnos-con-grado-anterior', [MatriculasController::class, 'putAlumnosConGradoAnterior'])->middleware('auth.personal');
// La cuarta hermana, y la única sin guard: `matriculas/alumnos-con-grado-anterior`
// y las dos de `prematriculas` lo llevan desde siempre. Devuelve el grupo entero
// que le nombren con `fecha_nac`, `celular`, `direccion` y `religion`. Ver 05 §23.
Route::put('matriculas/alumnos-grado-anterior', [MatriculasController::class, 'putAlumnosGradoAnterior'])->middleware('auth.personal');
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
// La cartera del colegio, y ninguna de las tres miraba el token: el `year_id` y
// el `grupo_actual` vienen del cuerpo y el exportador no lleva parámetros. Un
// alumno se descargaba el Excel de deudores. Ver 05 §17.
Route::put('cartera/alumnos', [CarteraController::class, 'putAlumnos'])->middleware('auth.personal');
Route::get('cartera/exportar-solo-deudores', [CarteraController::class, 'getExportarSoloDeudores'])->middleware('auth.personal');
Route::put('cartera/solo-deudores', [CarteraController::class, 'putSoloDeudores'])->middleware('auth.personal');

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
// Escribe `matriculas.promovido` —si el alumno pasa el año— de todo el grupo que
// se nombre en el cuerpo, y devuelve 331 KB con sus notas. El barrido no la vio
// porque el `grupo_id` viaja en el cuerpo y él golpea con el cuerpo vacío.
// Ver 05 §17.
Route::put('promovidos/calcular-grupo', [PromovidosController::class, 'putCalcularGrupo'])->middleware('auth.personal');

// PiarsAlumnosController — las cuatro son del PIAR y el PIAR es del personal.
//
// `myvc_front_2` es la única aplicación que las llama y no tiene camino de
// familia: en todo su código solo distingue `Usuario`, `Profesor` titular e
// `is_superuser`. Las cuatro llevaban otra cosa (ver 05 §35):
//
//   - `field` no llevaba **ninguno**, y las tres columnas que escribe se eligen
//     por `id` de la fila del PIAR, así que cualquiera con token reescribía la
//     valoración pedagógica de cualquier alumno. Que además el texto se pintara
//     sin sanear es lo que lo convertía en ejecución de JavaScript en la sesión
//     del docente; **eso se cierra aparte, en `App\Support\HtmlDelEditor`**.
//   - las dos de `document` llevaban `persona.propia`, que deja pasar al alumno
//     sobre lo suyo: un alumno subía y borraba los documentos de su propio PIAR.
//     Decisión de Joseth, 21 ago 2026: los documentos del PIAR los pone el
//     colegio.
//
// El guard deja fuera a alumnos y acudientes, que es hasta donde llega la regla
// de hoy. Que un profesor solo pueda escribir en los PIAR de SUS grupos es el
// refactor de permisos entre personal, pendiente (06-autorizacion.md).
Route::post('piars-alumnos/document', [PiarsAlumnosController::class, 'postDocument'])->middleware('auth.personal');
Route::put('piars-alumnos/field', [PiarsAlumnosController::class, 'putField'])->middleware('auth.personal');
Route::get('piars-alumnos/alumnos/{grupo_id}', [PiarsAlumnosController::class, 'getAlumnos'])->middleware('auth.personal');
Route::delete('piars-alumnos/document/{alumno_id}', [PiarsAlumnosController::class, 'deleteDocument'])->middleware('auth.personal');
