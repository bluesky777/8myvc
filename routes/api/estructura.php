<?php

use App\Http\Controllers\ContratosController;
use App\Http\Controllers\GradosController;
use App\Http\Controllers\GruposController;
use App\Http\Controllers\NivelesEducativosController;
use App\Http\Controllers\PeriodosController;
use App\Http\Controllers\Piars\PiarsGruposController;
use App\Http\Controllers\ProfesoresController;
use App\Http\Controllers\SincronizacionController;
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
// Los alumnos detrás de cada número de «Alumnos por grupo» en el panel de `app2`:
// `{que}` es `alumnos|hombres|mujeres|retirados|matriculados`, y los dos últimos
// piden además `?periodo=N`. Va la ÚLTIMA del bloque a propósito: es la única que
// empieza por un comodín, y Laravel sirve la primera que casa. Con cuatro
// segmentos hoy no tapa a ninguna, pero una `grupos/{id}/algo` futura sí.
Route::get('grupos/{grupo_id}/alumnos-de/{que}', [GruposController::class, 'getAlumnosDe'])->middleware('auth.personal');

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

// SincronizacionController
//
// **La huella de las cinco lecturas con las que se sincroniza `myvc_horarios`.**
// Un viaje y unos bytes en vez de cinco viajes y 121.183 bytes, que es lo que
// costaba preguntar «¿ha cambiado algo?» hasta hoy — 58,2 MB por jornada de ocho
// horas y por escritorio abierto, medido el 7 sep 2026. Ruta nueva autorizada por
// Joseth sobre las tres opciones con su precio delante; el porqué y las otras dos
// están en docs/migracion/34-la-huella-de-sincronizacion.md.
//
// **`auth.personal` y no sólo `auth.token`, a propósito, aunque sea MÁS estrecho
// que cuatro de las cinco lecturas que resume.** `years`, `grados`, `grupos` y
// `asignaturas` las lee cualquier cuenta con token —2.328 activas—, así que un
// guard de token no filtraría nada nuevo. Se pone el de personal porque **el único
// cliente de esta ruta es un programa de personal** y no hay ninguna pantalla de
// alumno ni de acudiente que la necesite: no le quita nada a nadie y baja la
// superficie de 2.328 a 45. Si algún día un cliente de alumno la necesitara, esto
// es lo que hay que releer antes de aflojarlo.
//
// **Y el quinto bloque —`profesores`— NO lo decide este guard, sino
// `esAdministrativo` dentro del método**, que es el mismo criterio que exige
// `GET profesores`: 10 personas de esas 45. Un guard de ruta no puede expresar
// «cuatro de las cinco cosas que devuelvo son para ti y la quinta no».
Route::get('sincronizacion/huella', [SincronizacionController::class, 'getHuella'])->middleware('auth.personal');
Route::put('years/alumnos-can-see-notas', [YearsController::class, 'putAlumnosCanSeeNotas'])->middleware('auth.personal');
// **Qué pasa al CERRAR el periodo con lo que nadie calificó** — fase 4 de
// docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md, decisión **D3** de Joseth
// del 20 sep 2026. Tres salidas —`cero`, `fuera`, `bloquear`— en
// `years.cierre_sin_calificar`, con **`cero` de fábrica**, que es el comportamiento
// de hoy.
//
// **Ruta propia y NO `years/toggle-cambiar-valor`, que sí podría**: aquélla escribe
// cualquier columna de `years` con este mismo `auth.personal`, así que esto no es una
// imposibilidad, es que **esta columna tiene dueño**. Por eso está además excluida
// allí, en la lista `$conDueno` — sin ese corte el permiso de aquí abajo se saltaría
// en una línea y no lo diría nada. Es `modelo_evaluacion` otra vez, y la cuarta de esa
// lista.
//
// **`auth.personal` en la ruta y `Autoriza::puedeElegirQuePasaAlCerrar` DENTRO**, o
// sea la forma de `toggle-mostrar-nota-numerica` y no la de los otros once
// interruptores del año, que van con `auth.personal` y nada. **Es una decisión y no un
// olvido**, y el porqué está en los dos métodos: `auth.personal` deja pasar a las 74
// cuentas de personal, de las que **53 son docentes**, y esta columna decide si a un
// alumno le cuentan como cero **las casillas que su profesor no calificó**. Es el
// único interruptor del año en el que quien lo pulsa puede ser parte interesada.
//
// **Cerrar el periodo no se estrecha**: `periodos/toggle-profes-pueden-editar-notas`
// sigue con `auth.personal` y nada dentro, con sus tres clientes intactos. Se estrecha
// elegir la política, no aplicarla.
//
// Es un segmento literal, así que no la puede tapar ningún `{id}` —los cuatro
// comodines de esta familia van detrás de un literal distinto— y no debe añadirse
// ninguna `PUT years/{algo}` que pudiera tragársela. Va entre `alumnos-can-see-notas`
// y `colegio` para no romper el orden alfabético del bloque.
//
// No es pública ni debe serlo: no mueve `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen
// dieciséis) ni `AutenticacionTest::SIN_GUARD`. Y la familia `years` tiene de sobra
// más de dos hermanas con guard, así que tampoco mueve
// `familias-que-nunca-entran-en-el-candado.json`.
Route::put('years/cierre-sin-calificar', [YearsController::class, 'putCierreSinCalificar'])->middleware('auth.personal');
Route::get('years/colegio', [YearsController::class, 'getColegio']);
Route::put('years/guardar-cambios', [YearsController::class, 'putGuardarCambios'])->middleware('auth.personal');
// **El modelo de evaluación del año**, Fase 1 de
// docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md §2. Ruta propia por
// decisión de Joseth del 13 sep 2026 (**D24**), y las dos mitades del porqué:
//
// 1. `putGuardarCambios` nombra **veintiuna columnas una a una**, así que una
//    columna nueva metida ahí **no la podría escribir nadie** —ni el superusuario—
//    y saldría `'ponderado'` en los dieciséis colegios para siempre. Es
//    `profesores.tono` otra vez, visto antes de cometerlo.
// 2. Y **los dieciséis `years/*` de escritura son `auth.personal`**, o sea que
//    colgarla de cualquiera de ellos dejaría que **cualquier docente cambiara el
//    modelo de evaluación del colegio entero** desde un `PUT` de dos campos.
//
// Por eso: `auth.personal` **en la ruta** —cierra la puerta a alumnos y acudientes
// antes de tocar el controlador— y `Autoriza::puedeEditarPlantillaNotas` **dentro
// del método**, que es la forma de `plantilla-notas/` y con el mismo permiso
// (`can_edit_plantilla_notas`): **cero permisos nuevos** (D13).
//
// **Los tres rótulos del desempeño NO pasan por aquí**: van en
// `years/guardar-cambios`, con las seis de unidad y subunidad y con su mismo guard.
// Son vocabulario, y quien puede renombrar «Subunidad» puede renombrar «Desempeño».
// **Cero rutas** por esa mitad.
//
// Va detrás de `guardar-cambios` y delante de `mostrar-todas-materias` para no
// romper el orden alfabético del bloque. Es un segmento literal, así que no la
// puede tapar ningún `{id}` —los cuatro de esta familia van detrás de un literal
// distinto— y no debe añadirse ninguna `PUT years/{algo}` que pudiera tragársela.
//
// No es pública ni debe serlo: **no mueve `RutasPreLoginTest::TOTAL_PUBLICAS`
// (siguen doce) ni `AutenticacionTest::SIN_GUARD`**. Y la familia `years` ya tiene
// muchas hermanas con guard, así que tampoco mueve
// `familias-que-nunca-entran-en-el-candado.json`.
Route::put('years/modelo-evaluacion', [YearsController::class, 'putModeloEvaluacion'])->middleware('auth.personal');
Route::put('years/mostrar-todas-materias', [YearsController::class, 'putMostrarTodasMaterias'])->middleware('auth.personal');
Route::put('years/profes-can-edit-alumnos', [YearsController::class, 'putProfesCanEditAlumnos'])->middleware('auth.personal');
Route::put('years/set-actual', [YearsController::class, 'putSetActual'])->middleware('auth.personal');
Route::post('years/store', [YearsController::class, 'postStore'])->middleware('auth.personal');
Route::put('years/toggle-cambiar-valor', [YearsController::class, 'putToggleCambiarValor'])->middleware('auth.personal');
Route::put('years/toggle-ignorar-notas-perdidas', [YearsController::class, 'putToggleIgnorarNotasPerdidas'])->middleware('auth.personal');
Route::put('years/toggle-mostrar-anio-pasado-en-boletin', [YearsController::class, 'putToggleMostrarAnioPasadoEnBoletin'])->middleware('auth.personal');
Route::put('years/toggle-mostrar-nota-comport-en-boletin', [YearsController::class, 'putToggleMostrarNotaComportEnBoletin'])->middleware('auth.personal');
// `auth.personal` en la ruta como sus cinco hermanas, y el permiso de verdad
// DENTRO —superusuario, Secretario, Coord académico o Rector—: la forma de
// `plantilla-notas/`. Mirar la familia y suponer que ésta también la mueve
// cualquier docente es el error que este renglón viene a evitar.
Route::put('years/toggle-mostrar-nota-numerica', [YearsController::class, 'putToggleMostrarNotaNumerica'])->middleware('auth.personal');
Route::put('years/toggle-mostrar-puestos-en-boletin', [YearsController::class, 'putToggleMostrarPuestosEnBoletin'])->middleware('auth.personal');
// **Los dos interruptores de la campaña de prematrícula** (19 sep 2026). La pantalla
// de ajustes del año de `app2` ya los pintaba y las dos contestaban 404: hasta hoy
// `years.prematr_nuevos` y `years.prematr_antiguos` **no las escribía ninguna
// aplicación** —sólo crear un año, que las copia del anterior—, así que abrir la
// campaña era un `UPDATE` a mano colegio por colegio. El porqué de que sean dos y de
// que no vayan por `years/toggle-cambiar-valor` está en el docblock de los métodos.
//
// **`auth.personal` y NADA dentro, por decisión de Joseth de ese día**, igual que los
// otros diez interruptores del año. No es el caso de `toggle-mostrar-nota-numerica`,
// tres líneas más arriba, que sí lleva permiso dentro — y la diferencia está escrita
// en los métodos para que no se lea como un olvido, porque `prematr_nuevos` abre una
// puerta que se ve desde internet.
//
// Son dos segmentos literales, así que no los puede tapar ningún `{id}`. No son
// públicas: no mueven `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni
// `AutenticacionTest::SIN_GUARD`, y la familia `years` ya tiene muchas hermanas con
// guard, así que tampoco mueven `familias-que-nunca-entran-en-el-candado.json`.
Route::put('years/toggle-prematricula-antiguos', [YearsController::class, 'putTogglePrematriculaAntiguos'])->middleware('auth.personal');
Route::put('years/toggle-prematricula-nuevos', [YearsController::class, 'putTogglePrematriculaNuevos'])->middleware('auth.personal');
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
// **El diálogo de cierre: qué casillas de este periodo no ha calificado nadie** —
// fase 4 del doc 43. Devuelve la cuenta, el desglose por asignatura con su docente y
// **qué va a pasar** con ellas al cerrar, leído de la elección del colegio (o de lo
// que ya se aplicó, si el periodo está cerrado).
//
// Hace falta una ruta porque **nadie cuenta casillas vacías**: `informes/notas-perdidas`
// cuenta lo contrario —notas puestas y bajas— y la planilla las enseña de una
// asignatura en una, que es justo lo que no sirve para decidir un cierre.
//
// **`auth.personal` y nada dentro, al contrario que `years/cierre-sin-calificar`**, y
// eso es una decisión: esto se **lee** y aquello se **decide**. Quien cierra el periodo
// son las 74 cuentas de personal, así que el diálogo que va justo antes del cierre
// tiene que alcanzar a las mismas 74 — más estrecho dejaría a secretaría cerrando a
// ciegas.
//
// **`sin-calificar` es un literal y va DELANTE de `{periodo_id}`… salvo que aquí no hay
// ningún `{periodo_id}` suelto en esta familia**: los tres comodines de `periodos/`
// van detrás de un literal distinto (`destroy`, `establecer-actual`, `show`), así que
// nada puede tragarse ésta. Se comprueba con `getRoutes()->match()` y no con
// `route:list`, que ordena alfabéticamente y no por orden de registro.
Route::get('periodos/sin-calificar/{periodo_id}', [PeriodosController::class, 'getSinCalificar'])->middleware('auth.personal');
// **Y desde el 20 sep 2026 esto no sólo abre y cierra: APLICA la decisión D3** sobre
// las casillas que nadie calificó (fase 4 del doc 43). No es una ruta nueva —cerrar ya
// era este interruptor— y **sigue devolviendo texto**, que es contrato con los tres
// clientes que la llaman (`myvc_front/scripts/endpoints-de-texto.json`).
//
// **El guard no se toca**: `auth.personal` y nada dentro, igual que ayer. Lo que se
// estrechó es elegir la política (`years/cierre-sin-calificar`), no aplicarla.
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
