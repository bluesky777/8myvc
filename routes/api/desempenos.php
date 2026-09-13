<?php

use App\Http\Controllers\DesempenosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Los desempeños: el plan de área del colegio y lo que el docente añade
|--------------------------------------------------------------------------
|
| La **Fase 3** de docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md, o sea
| la **Entrega 3** del doc 28 con D5, D7, D12, D13, D14 y D25 encima.
|
| ## Son DOCE y el plan dice ocho, y la diferencia no es de estilo
|
| El plan lista seis sobre el catálogo, `sembrar` y el `GET` de la planilla. En el
| párrafo siguiente pide un candado **«en cuatro caminos: `update`, `destroy`,
| `forcedelete` y `orden`»**, y esos caminos son sobre `desempenos` —la tabla que
| ve el docente— y **no existen en esas ocho**. Con las ocho:
|
|   - el candado no tiene nada que candar, y
|   - la **D14** —*«el docente no toca los del colegio, pero añade los suyos»*— no
|     se entrega, que es literalmente la mitad de esa decisión.
|
| Las cuatro que faltaban son `POST`, `PUT {id}`, `DELETE {id}` y `orden` sobre
| `desempenos`. **Es el mismo caso que la §1.4 del doc 35**, que subió el total del
| plan de 20 a 22 al ver que nadie podía escribir `modelo_evaluacion`: un hueco que
| el documento no vio, no cuatro rutas de capricho. **El total del plan pasa de 22 a
| 26**, y se cuenta el día que entren.
|
| > **Y no se pueden ahorrar juntándolas con las del catálogo.** `desempenos_por_defecto`
| > va por **materia + grado + periodo** y `desempenos` por **asignatura + periodo**:
| > no son la misma tabla ni el mismo alcance, así que un `POST` que escribiera en una
| > o en otra según quién llame serían **dos endpoints disfrazados de uno**.
|
| ## El candado son TRES caminos —`update`, `destroy` y `orden`— y los tres van candados
|
| El plan dice cuatro: `update`, `destroy`, `forcedelete` y `orden`. Aquí son tres
| **porque `forcedelete` no existe en esta familia**, no porque se pueda ahorrar
| ninguno de los otros.
|
| > **`destroy` va candado, y decir que «sin `forcedelete` el rodeo se cierra solo»
| > es FALSO.** `destroy` es un **borrado lógico**, así que un docente que pudiera
| > llamarlo borraría la fila del colegio y **la volvería a crear con
| > `por_defecto = 0`, o sea libre**: el candado de la D14 se salta entero sin tocar
| > una sola ruta prohibida. Es el rodeo que la §5.1.e midió sobre los nueve caminos
| > de `unidades`, y no es teórico — allí ya pasó.
| >
| > **Lo que sí es verdad de no tener `forcedelete`** es otra cosa: ahorra **un
| > cuarto camino que habría que candar**. Es una superficie que no existe, no un
| > agujero que se tapa solo. El día que alguien añada papelera a esta familia,
| > llega con un candado que poner: este párrafo es el aviso.
|
| ## Dos guards distintos, y ésa es toda la estructura
|
| `auth.personal` en las doce, y dentro:
|
|   - las **siete del catálogo** (`desempenos/plantilla/*` y `sembrar`) exigen
|     `Autoriza::puedeEditarPlantillaNotas` — **D13**: lo que configura el colegio,
|     el docente no lo toca;
|   - las **cinco del docente** (`GET`, `POST`, `PUT {id}`, `DELETE {id}`, `orden`)
|     no piden ese permiso: piden **el periodo abierto** y, si la fila la sembró el
|     colegio, **el candado de la D14**.
|
| **Un periodo cerrado sigue cerrado para todo el mundo**, con permiso y sin él: el
| candado es una guarda *más*, nunca en lugar de la que ya estaba.
|
| Ninguna de las doce es pública ni debería serlo, así que esto **no** mueve
| `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni `AutenticacionTest::SIN_GUARD`.
| Y `desempenos` entra en el censo de familias con **12 de 12 con guard**, así que
| `familias-que-nunca-entran-en-el-candado.json` no se mueve — **siempre que las doce
| entren en el mismo commit**, que es por lo que van juntas.
|
| ## El orden, que aquí tiene DOS trampas
|
| 1. `desempenos/orden` y `desempenos/sembrar` son literales de dos segmentos y van
|    **antes** que `desempenos/{id}`. Con `PUT desempenos/{id}` declarado primero,
|    `PUT desempenos/orden` entraría por ahí con `$id = 'orden'` y devolvería un 404
|    que no se parece en nada a la causa.
| 2. `desempenos/plantilla/orden` y `desempenos/plantilla/copiar` van **antes** que
|    `desempenos/plantilla/{id}`, por lo mismo y un nivel más abajo.
|
| Los dos bloques no se tapan entre sí porque `plantilla` es un segmento literal:
| `PUT desempenos/{id}` con `id = "plantilla"` no casa —`esIdentificador` lo
| rechaza— y además `desempenos/plantilla/{id}` tiene tres segmentos.
|
*/

// ── El plan de área del colegio ──────────────────────────────────────────────
Route::get('desempenos/plantilla', [DesempenosController::class, 'getPlantilla'])->middleware('auth.personal');
Route::post('desempenos/plantilla', [DesempenosController::class, 'postPlantilla'])->middleware('auth.personal');

Route::put('desempenos/plantilla/orden', [DesempenosController::class, 'putOrdenPlantilla'])->middleware('auth.personal');
Route::put('desempenos/plantilla/copiar', [DesempenosController::class, 'putCopiarPlantilla'])->middleware('auth.personal');

Route::put('desempenos/plantilla/{id}', [DesempenosController::class, 'putPlantilla'])->middleware('auth.personal');
Route::delete('desempenos/plantilla/{id}', [DesempenosController::class, 'deletePlantilla'])->middleware('auth.personal');

Route::put('desempenos/sembrar', [DesempenosController::class, 'putSembrar'])->middleware('auth.personal');

// ── Lo que ve y escribe el docente ───────────────────────────────────────────
Route::get('desempenos', [DesempenosController::class, 'getIndex'])->middleware('auth.personal');
Route::post('desempenos', [DesempenosController::class, 'postStore'])->middleware('auth.personal');

Route::put('desempenos/orden', [DesempenosController::class, 'putOrden'])->middleware('auth.personal');

Route::put('desempenos/{id}', [DesempenosController::class, 'putUpdate'])->middleware('auth.personal');
Route::delete('desempenos/{id}', [DesempenosController::class, 'deleteDestroy'])->middleware('auth.personal');
