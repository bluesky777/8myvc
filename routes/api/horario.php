<?php

use App\Http\Controllers\HorarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Horario
|--------------------------------------------------------------------------
|
| Las tres rutas de la §5.3 de docs/migracion/23-horarios.md, autorizadas por
| Joseth el 2 sep 2026 **las tres a la vez**: con sólo las dos primeras se puede
| subir y listar, pero nadie puede marcar la oficial y «Clases de hoy» sigue
| vacía, que es el problema que este módulo viene a resolver.
|
| El horario se cuadra en un programa de escritorio; aquí sólo se guardan
| versiones del horario de un año y se dice cuál es la oficial.
|
| ## Las tres llevan `auth.personal`, y el porqué NO es un contador
|
| Cierra la puerta a alumnos y acudientes **antes de tocar el controlador**, y es
| la forma de la referencia que dio Joseth para este módulo:
| `myimages/cambiarlogocolegio` es guard en la ruta **más** `Autoriza` dentro
| (routes/api/perfiles.php:72 e ImagesController.php:285). Que con eso la familia
| `horario` salga «3 de 3» en `guard-por-familia.json` es una **consecuencia**, no
| la razón: un guard no se pone para que un contador salga redondo, y quitarlo
| porque el contador estorbe sería abrirle la puerta a los alumnos.
|
| Ninguna de las tres es pública ni debería serlo — una versión del horario dice
| qué docente está dónde a cada hora—, así que esto **no** mueve
| `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni `AutenticacionTest::SIN_GUARD`.
|
| ## Los dos criterios que van DENTRO del método, no aquí
|
| `esAdministrativo` (subir) y `puedePublicarHorario` (publicar) son estáticos de
| `App\Support\Autoriza`, no middlewares, y se comprueban en el controlador. Es la
| asimetría que pidió Joseth desde el principio: **subir no publica**. Secretaría
| sube todas las versiones que quiera y no elige la que ve el colegio.
|
| El orden no tiene trampa: `versiones` es literal en las tres y el `{id}` va
| seguido de un segmento fijo, así que ninguna tapa a otra.
|
*/

Route::post('horario/versiones', [HorarioController::class, 'postVersiones'])->middleware('auth.personal');
Route::get('horario/versiones', [HorarioController::class, 'getVersiones'])->middleware('auth.personal');
Route::put('horario/versiones/{id}/oficial', [HorarioController::class, 'putOficial'])->middleware('auth.personal');

/*
|--------------------------------------------------------------------------
| La cuarta: leer las lecciones para PINTARLAS
|--------------------------------------------------------------------------
|
| §9.bis del 23. La decidió Joseth el 3 sep 2026 —el horario cuadrado en el
| escritorio se tiene que poder MIRAR en un menú de la web, y la web LEE de la
| API— y la forma la cerró el 4 sep con lo medido en los dos repositorios.
|
| **`auth.personal`, el mismo que listar**, y lo decidió sabiendo lo que tensiona:
| esta ruta le entrega a cualquier docente el horario del colegio entero. Lo que lo
| pesó fue un hecho y no un argumento — ese horario **ya se imprime y se cuelga**,
| trece hojas apaisadas, así que en papel no es un secreto para nadie de dentro.
|
| **Y sigue sin ser descargar**: aquí no viaja el fichero de proyecto. La decisión
| 12 dijo «listar no es descargar» y ésta la extiende a «mirar no es llevarse»;
| llevarse el proyecto a otro computador es otra ruta y otro permiso (§10.2.3).
|
| El `{id}` va después de `versiones/`, que es literal, y seguido de un segmento
| fijo: no tapa a `GET horario/versiones` ni la tapa ella.
|
*/
Route::get('horario/versiones/{id}/lecciones', [HorarioController::class, 'getLecciones'])->middleware('auth.personal');

/*
|--------------------------------------------------------------------------
| La quinta: el color de un docente
|--------------------------------------------------------------------------
|
| §9.bis del 23. La decidió Joseth el 4 sep 2026 —«un color automático inicial,
| pero que se pueda cambiar por el usuario»— y con ella cierra el hueco que
| destapó `myvc-front-84` ese mismo día: la cuarta ruta LEE `profesores.tono` y
| **no había en toda la API una sola escritura de esa columna**, así que el
| renglón `tono` iba a salir `vacio` en los diecisiete para siempre.
|
| ## Por qué es ruta y no una línea en la ficha del docente
|
| El front costeó meterla en la lista blanca de `ProfesoresController::putUpdate`,
| que no habría movido el router. No vale, y **no por trabajo sino por permiso**:
| esa ruta exige `Autoriza::esSuperusuario` dentro, así que por ahí el color lo
| elegirían once personas en toda la red y **ningún coordinador**. Joseth decidió
| que lo elijan también los coordinadores.
|
| ## `puedePublicarHorario`, el mismo criterio que marcar la oficial
|
| Superusuario **o** `Coord académico`. No es `auth.personal` —que es el de
| *mirar*— ni `esAdministrativo` —que es el de *subir*—: elegir los colores con
| los que el colegio entero va a leer su horario se parece a publicarlo, no a
| consultarlo. El guard de la ruta sigue siendo `auth.personal`, que cierra la
| puerta a alumnos y acudientes antes de tocar el controlador; el criterio fino va
| dentro, como en `putOficial`.
|
| **Aviso medido el 4 sep 2026: el rol `Coord académico` tiene CERO usuarios.** Así
| que el primer día sólo podrán elegir colores los superusuarios, no por la regla
| sino porque no hay a quién. No es un fallo de esta ruta y no se arregla aquí.
|
| El `{profesor_id}` va detrás de `docentes/`, literal, y seguido de un segmento
| fijo: no tapa a ninguna de las cuatro de arriba ni ellas a ella.
|
*/
Route::put('horario/docentes/{profesor_id}/tono', [HorarioController::class, 'putTonoDocente'])->middleware('auth.personal');
