<?php

use App\Http\Controllers\DesempenosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Los desempeños: el plan de área del colegio, plano y en una sola capa
|--------------------------------------------------------------------------
|
| El contrato es docs/migracion/39-el-modelo-plano-por-competencias.md §2, que
| reescribe la Fase 3 de docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md
| con **D31** —el colegio y el docente escriben las mismas filas físicas— y
| **P1.bis** —el nivel se deriva al imprimir, no se congela al marcar—.
|
| ## Son SIETE, y el 13 sep eran veintiuna
|
| Se van catorce, y **ninguna por ahorro**: se van porque el modelo que les daba
| sentido ya no existe.
|
|   - las **siete de `competencias/`**, porque el boletín es plano y no hay
|     cabecera de competencia que agrupar (H3, P3). Sólo `catalogo-men` sobrevive,
|     y **se muda a esta familia** — abajo el porqué;
|   - **`desempenos/sembrar`**, porque con una sola capa **no hay a dónde sembrar**;
|   - las **cinco de la capa por asignatura** —`GET`/`POST desempenos`,
|     `PUT`/`DELETE desempenos/{id}` y `PUT desempenos/orden` **con su significado
|     viejo**—, porque la tabla `desempenos` se va entera;
|   - las **dos de `desempenos/rejilla`**, porque la casilla que marcaba el docente
|     **era el nivel**, y el nivel ahora lo deriva el boletín de la definitiva.
|
| ## El segmento `plantilla` se cae de la URL, y los métodos NO se renombran
|
| Hoy el catálogo vivía en `desempenos/plantilla/*` y la capa del docente en
| `desempenos/*`. Con una sola capa sobra un nombre, y el que sobra es `plantilla`:
| significa *«lo que se aplica a una copia»*, que es justo lo que D31 abolió, y
| encima choca de nombre con la familia `plantilla-notas/`, que se queda y sí es una
| plantilla de verdad.
|
| Se hace **ahora porque ahora es gratis**: `datos/desempenos.ts` se reescribe
| entero en F0. Dentro de un mes cuesta una migración de front.
|
| Los métodos siguen llamándose `getPlantilla`, `postPlantilla`,
| `putOrdenPlantilla`… porque **lo que es contrato es la URL, no el nombre del
| método**, y `CLAUDE.md` dice que renombrarlos es cosmético y va después.
|
| ## `catalogo-men` cambia de familia, y por eso el censo no se mueve
|
| Estaba en `competencias/` para no quedarse sola: una familia de una sola ruta
| entra en `familias-que-nunca-entran-en-el-candado.json` como **«1 de 1»**, que es
| la forma exacta que tendría un agujero de autorización. Esa familia desaparece
| entera, así que se muda aquí, donde es **1 de 7**. Comprobado y no supuesto: hoy
| el censo dice `competencias: 7 de 7` y `desempenos: 14 de 14`, y **ninguna de las
| dos está** en ese fichero; después dice `desempenos: 7 de 7` y `competencias` ya
| no está.
|
| **Mudarse de familia cambia la URL, no las respuestas**: `catalogo-men` contesta
| exactamente lo mismo que contestaba en `competencias/`, códigos de error
| incluidos.
|
| ## Dos puertas, y ésa es toda la estructura
|
| `auth.personal` en las siete, y el criterio fino **dentro**, que es la forma de
| `PlantillaNotasController`:
|
|   - **las dos lecturas** —`GET desempenos` y `GET desempenos/catalogo-men`—
|     contestan 200 a cualquier docente: son la pantalla donde va a escribir;
|   - **las cinco escrituras** piden `Autoriza::puedeEscribirDesempenos`, que es la
|     **P1.quater**: o el permiso del colegio (`can_edit_plantilla_notas`), o dar
|     esa materia en ese grado. Sin esa segunda puerta la pantalla del docente
|     nace en 403, que es lo que pasaba hasta hoy.
|
| Ninguna de las siete es pública ni debería serlo, así que esto **no** mueve
| `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni `AutenticacionTest::SIN_GUARD`.
|
| ## El orden, que sigue teniendo trampa y ahora tiene una literal más
|
| `desempenos/orden`, `desempenos/copiar` y `desempenos/catalogo-men` son
| **literales** y van **antes** que `desempenos/{id}`. Laravel resuelve la primera
| ruta que casa: con `PUT desempenos/{id}` declarado antes, `PUT desempenos/orden`
| entraría por ahí con `$id = 'orden'` y devolvería un 404 que no se parece en nada
| a la causa.
|
| `GET desempenos/catalogo-men` no choca con nada porque **no hay
| `GET desempenos/{id}`** — se declara arriba de todas formas, porque separarlo de
| sus hermanos literales es invitar a que el día que alguien añada el `GET {id}`
| sólo mueva los otros dos.
|
*/

// ── Lo que se lee: las dos las contesta cualquier docente ────────────────────
Route::get('desempenos', [DesempenosController::class, 'getPlantilla'])->middleware('auth.personal');
Route::get('desempenos/catalogo-men', [DesempenosController::class, 'getCatalogoMen'])->middleware('auth.personal');

// ── Lo que se escribe: `puedeEscribirDesempenos` dentro de cada método ───────
Route::post('desempenos', [DesempenosController::class, 'postPlantilla'])->middleware('auth.personal');

Route::put('desempenos/orden', [DesempenosController::class, 'putOrdenPlantilla'])->middleware('auth.personal');
Route::put('desempenos/copiar', [DesempenosController::class, 'putCopiarPlantilla'])->middleware('auth.personal');

Route::put('desempenos/{id}', [DesempenosController::class, 'putPlantilla'])->middleware('auth.personal');
Route::delete('desempenos/{id}', [DesempenosController::class, 'deletePlantilla'])->middleware('auth.personal');
