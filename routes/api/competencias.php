<?php

use App\Http\Controllers\CompetenciasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Las competencias del colegio
|--------------------------------------------------------------------------
|
| Las siete rutas de la **Fase 2** de
| docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md: las seis de la §5.2
| del doc 28 —`GET`, `POST`, `PUT {id}`, `DELETE {id}`, `PUT orden`,
| `PUT copiar`— más `GET competencias/catalogo-men`, que trae la **D11**.
|
| Van juntas y no de una en una por lo mismo que las nueve de `plantilla-notas`:
| por separado no sirven. Con el `GET` y el `POST` el colegio puede escribir
| competencias **una a una y a mano**, que es exactamente el trabajo que esta
| entrega existe para quitar; y sin `copiar` el plan de área se vuelve a teclear
| cada enero.
|
| ## El catálogo del MEN cuelga de `competencias/`, y no es cosmético
|
| Una ruta sola en una familia propia —`catalogo-men/*`— entraría en
| `tests/Contrato/Snapshots/familias-que-nunca-entran-en-el-candado.json` como
| **«1 de 1»**, que es la forma exacta que tendría un agujero de autorización y
| que habría que defender por escrito. Colgada aquí, la familia `competencias`
| tiene **siete rutas y las siete con guard**, así que `conGuard = 7` y el censo
| **no se mueve**. Es gratis elegir bien el nombre.
|
| > **Y por eso las siete entran EN EL MISMO COMMIT.** Si la familia entrara a
| > trozos —una ruta hoy, seis mañana— el censo la recogería como «1 de 1» en el
| > primero y la sacaría en el segundo: dos movimientos de una instantánea
| > publicada y un renglón que alguien tendría que explicar. Entrando juntas no
| > se mueve ni una vez.
|
| ## `auth.personal` en la ruta, y el permiso DENTRO sólo en las cinco escrituras
|
| El guard cierra la puerta a alumnos y acudientes **antes de tocar el
| controlador**; el criterio fino, `Autoriza::puedeEditarPlantillaNotas`, va en el
| método (**D13**). Es la forma de `PlantillaNotasController` y de
| `myimages/cambiarlogocolegio`.
|
| **Los dos `GET` contestan 200 a cualquier docente**, y eso es distinto de la
| plantilla, donde hasta leer pide permiso. El motivo está en la Fase 3: el
| docente va a **colgar sus desempeños de una competencia**, así que necesita la
| lista. Lo que no puede es escribirla.
|
| Ninguna de las siete es pública ni debería serlo, así que esto **no** mueve
| `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni `AutenticacionTest::SIN_GUARD`.
|
| ## El orden, que aquí sí tiene trampa
|
| `competencias/catalogo-men`, `competencias/orden` y `competencias/copiar` son
| **literales** y van **antes** que `{id}`. Laravel resuelve la primera ruta que
| casa: con `PUT competencias/{id}` declarado antes, `PUT competencias/orden`
| entraría por ahí con `$id = 'orden'` y devolvería un 404 —o peor, un 422— que
| no se parece en nada a la causa.
|
| El `GET` del catálogo no choca con nada porque **no hay `GET competencias/{id}`**
| y no se debe añadir: una competencia se lee de la lista de su grupo, que es como
| la pinta la pantalla.
|
*/

Route::get('competencias', [CompetenciasController::class, 'getIndex'])->middleware('auth.personal');
Route::get('competencias/catalogo-men', [CompetenciasController::class, 'getCatalogoMen'])->middleware('auth.personal');

Route::post('competencias', [CompetenciasController::class, 'postStore'])->middleware('auth.personal');

Route::put('competencias/orden', [CompetenciasController::class, 'putOrden'])->middleware('auth.personal');
Route::put('competencias/copiar', [CompetenciasController::class, 'putCopiar'])->middleware('auth.personal');

Route::put('competencias/{id}', [CompetenciasController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('competencias/{id}', [CompetenciasController::class, 'deleteDestroy'])->middleware('auth.personal');
