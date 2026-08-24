# MUERTO-2 — ningún cliente resucita los 34

**Sesión `8myvc-9e`, noche del 24 ago 2026.** Es la precondición que convierte la
lista de [MUERTO-1](muerto-1.md) en algo que se puede decidir: la regla de la casa
—*sin ruta y muerto se borra*— exige antes saber que **nadie va a resucitar el
camino**, y eso no se contesta desde este repositorio.

**Lectura pura: ni base, ni contenedor, ni turno.** Y **no se ha borrado nada.**

---

## §1 — La población, y son ONCE árboles, no cuatro

El encargo decía cuatro (`myvc_front`, `myvc_front-fase11`, `myvc_front_2`,
`myvc_flutter`). **Hay once sitios donde podía estar la mención**, y dos no los
habría mirado nadie:

| Árbol | Ficheros fuente | Qué es |
|---|---|---|
| `myvc_front` (main) | 587 | AngularJS, uno por colegio |
| `myvc_front-fase11` | 1.700 | la rama `fase-11-angular`, donde vive `app2` |
| `myvc_front-feliz` | 1.707 | worktree de esta noche |
| `myvc_front-guardar` | 1.468 | worktree de esta noche |
| `myvc_front-impresion` | 1.698 | worktree de esta noche |
| `myvc_front-mirado5` | 1.473 | worktree de esta noche |
| `myvc_front_2` | 100 | Angular, sólo el PIAR |
| `myvc_flutter` | 166 | **una sola app para los dieciséis** |
| `tardanzasMyvc-old` | 137 | **cliente de tardanzas, y 10 de sus ficheros llaman a esta API** |
| `arc` | 59 | otro proyecto; su `dist/` mete ruido y se excluye |
| `myvc_dist` | 4 | salida construida |
| **total revisado** | **8.351** | sin `node_modules/`, `.git/`, `build/` ni `dist/` |

> **`myvc_front` tiene SEIS worktrees, no uno.** Cuatro son ramas `fase-11/*` de
> las sesiones de esta noche, así que **una mención escrita hace dos horas sólo
> existe en una de ellas**. Buscar en `myvc_front` a secas habría mirado 587 de
> los 8.351 ficheros: **el 7%.**
>
> Y **`tardanzasMyvc-old` es un cliente de verdad** —llama a esta API— y dos de
> los 34 están en `Tardanzas/TLoginController`. Se llama «old» y eso es
> exactamente lo que hace que no se mire.

---

## §2 — Un cliente no llama métodos: llama rutas

Buscar el nombre del método sólo sirve para los nombres **distintivos**. De los 22
nombres distintos entre los 34, **10 son genéricos** —`destroy`, `index`,
`update`, `getIndex`, `getShow`, `postIndex`, `putUpdate`, `deleteDestroy`,
`valoracion`, `getGrupos`— y buscarlos **no dice nada**: `update` sale **1.815**
veces y `index` **1.683**, casi todas en bundles minificados.

Así que la comprobación son **dos**, y la segunda es la que de verdad importa:

1. **el nombre**, sólo para los 12 distintivos;
2. **la ruta que ese método serviría**, para todos — porque **si un cliente llama
   ese camino hoy, está recibiendo un 404**, y eso no es un riesgo de resurrección:
   es un fallo vivo.

---

## §3 — Resultado: **ningún cliente llama a ninguno de los 34**

### Por nombre distintivo: 2 de 12 mencionados, y los dos son comentarios

| Nombre | Dónde | Qué dice |
|---|---|---|
| `calcular_notas_finales_asignatura` | `app2/src/app/datos/definitivas-periodos.ts` | *«NO SE DECLARA … Y ES A PROPÓSITO … el método del backend no puede funcionar»* — `count()` sobre variable no definida, **500 siempre** |
| `detailedNotasGrupo` | `app/scripts/informes/CertificadosEstudioCtrl.ts` | *«hay DOS ficheros llamados `BolfinalesController.php` … y sólo el de `Informes/` está enrutado»* |

**Los dos son el front documentando la muerte, no arriesgándose a resucitarla.** Y
el segundo es **mi trampa 2 encontrada por ellos desde el otro lado**: dos clases
con el mismo nombre corto, con la advertencia de que *leyendo el equivocado, este
`[3]` parece un fallo y no lo es*.

Los otros **10 distintivos no aparecen en ninguno de los 8.351 ficheros**:
`asignaturasPerdidasDeAlumno`, `asignaturasPerdidasDeAlumnoPorPeriodo`,
`calcular_notas_finales_asignatura_periodo`, `datosYearPasado`, `default_image_id`,
`default_image_name`, `definitivasMateriasXPeriodo`, `periodosPerdidosDeAlumno`,
`putDetailedNotasYear`, `putDetailedNotasYearGroup`.

### Por ruta: **cero llamadas a un camino que no exista**

Comprobados los caminos que servirían los de nombre genérico:

| Camino | ¿Lo llama algún cliente? |
|---|---|
| `paises/update`, `paises/destroy` | **no** — sólo aparece en **documentación** del front (ver abajo) |
| `candidatos/update` | no |
| `permissions/show`, `/store`, `/update`, `/destroy` | no. El único enrutado es `GET permissions` → `getIndex`, **que no está en los 34** |
| `GET aspiraciones` (lista) | no: el front usa `RECURSO = 'aspiraciones'` para construir `store`, `update` y `destroy`, **los tres enrutados** |
| `GET ausencias` (lista) | no: `RECURSO = 'ausencias'` se usa para los sub-caminos enrutados; los demás aciertos son **claves de datos** (`'ausencias_clase'`), no URLs |

**Y la tercera confirmación independiente**, en `MIGRATION.md` y
`PREGUNTAS-MANANA.md` del front:

> *«`paises/actualizar` y `paises/destroy` no existen como ruta. Los métodos están
> escritos y no registrados en las rutas.»*

**Tres veces, y las tres desde el front, mientras yo lo medía desde el backend por
cierre transitivo.** Es la confirmación cruzada que faltaba.

---

## §4 — Los tres cajones

### A · Nadie los nombra en ninguna parte — **borrables cuando Joseth lo diga** (25)

Los 10 distintivos con cero menciones, más los de nombre genérico cuyo camino no
llama nadie: `AusenciasController::getIndex`, `PermissionsController` (`getShow`,
`postIndex`, `putUpdate`, `deleteDestroy`), `VtAspiracionesController::index`,
`VtCandidatosController::update`, `CertificadosPersonaController::valoracion`,
`PiarsGrupoUtils::getGrupos`, `LoginController` y `TLoginController`
(`default_image_id`, `default_image_name`), `NotasActualesAlumnosController::datosYearPasado`,
`BolfinalesController` (`putDetailedNotasYear`, `putDetailedNotasYearGroup`).

### B · Aparecen en un comentario o un documento — **hablar con el front primero** (4)

| Método | Por qué |
|---|---|
| `Definitivas::calcular_notas_finales_asignatura` | el front explica en su código **por qué no lo declara**. Borrarlo deja el comentario apuntando a nada |
| `CertificadosPersonaController::detailedNotasGrupo` | el comentario del front avisa de los dos `BolfinalesController`. **El aviso sigue siendo útil aunque el método se borre**, y hay que decírselo |
| `PaisesController::update` y `::destroy` | están en dos documentos del front como *«escritos y no registrados»*. Si se borran, esos documentos pasan a describir algo que ya no existe |

**Ninguno de los cuatro es un riesgo de que alguien lo cablee: los cuatro son el
front diciendo que ya lo miró.** Pero borrarlos **invalida documentación viva de
otro repositorio**, y eso se avisa, no se descubre.

### C · Subárbol — **enteros o nada** (11)

- **`periodosPerdidosDeAlumno`**: definido en **5** controladores, **sin camino en
  los 5**.
- **`asignaturasPerdidasDeAlumnoPorPeriodo`**: definido en **8**, **sin camino en
  6**. Su único llamante donde existe **es el anterior**.

Borrar uno sin el otro deja un método sin llamantes o una llamada sin método. **Y
el par de nombres al lado**: `asignaturasPerdidasDeAlumno` —sin `PorPeriodo`— está
**vivo con 17 llamadas**. Es el séptimo par de nombres parecidos de la noche que no
son la misma cosa.

---

## §5 — Lo que esta comprobación NO prueba

- **No mira ramas sin fundir de los clientes que no estén en disco.** Las seis
  worktrees de `myvc_front` que hay aquí son las de esta máquina; una rama en el
  remoto que nadie haya traído no se ve.
- **No prueba que no exista un `Route::` en una rama del backend sin fundir.** Mi
  cierre parte de `routes/` **en mi árbol**; otra sesión podría estar añadiendo
  una ruta a uno de los 34 ahora mismo.
- **`arc` y `myvc_dist` entran en la población pero su `dist/` se excluye**: es
  salida generada, y un nombre ahí es el mismo de la fuente, contado dos veces.
- Y sigue en pie lo de MUERTO-1: **esto sigue llamadas, no ramas.**

**Con eso, la frase que hace decidible la lista:** *34 candidatos, 1.019 líneas,
revisados 8.351 ficheros en once árboles de cliente, y ningún cliente llama a
ninguno* — con 4 que **tienen documentación del front apuntándoles** y 11 que
**se borran en dos bloques o no se borran**.
