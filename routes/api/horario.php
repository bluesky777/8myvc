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
