# Lote B — la planilla, las unidades y las subunidades

**Rama `fix/bi-lote-b`, árbol `.worktrees/b`, base `simonbolivar_testing_b`.**
Siete sitios de la **fase 1** en tres controladores, más la **fase 3 entera** y la
**§6.5**. `app/Models/Nota.php` entró en el lote a mitad de noche: ver el bloqueo de
abajo.

---

## 1. Lo que se cerró, sitio a sitio

De los **siete** de la fase 1: **seis acotados** y **uno decidido y no tocado**, que
también es cerrarlo.

| Sitio | Qué es | Qué se hizo |
|---|---|---|
| `NotasController:73` | `$unidadesT`, la rejilla de la planilla del profesor | `u.alumno_id IS NULL` |
| `NotasController:156` | la derivada de la **definitiva automática** que la pantalla pinta al lado de la guardada | `<=> alcanceCorrelacionado('n.alumno_id', 'u')` |
| `UnidadesController:26` | `$cons_unidades`, que usan **tres** lecturas (`getDeAsignaturaPeriodo` ×2 y `putDeProfesor`) | `unidades.alumno_id IS NULL` |
| `UnidadesController:64` | el panel de «años anteriores» de `putDeAsignaturaPeriodo` | `u.alumno_id IS NULL` |
| `UnidadesController:359` | `putEliminadas`, la papelera de unidades | `unidades.alumno_id IS NULL` |
| `SubunidadesController:362` | `putEliminadas`, la papelera de subunidades | `u.alumno_id IS NULL` |
| `UnidadesController:398` | `getTrashed`, la papelera **global** | **no se acota** — ver §4 |

Y la **fase 3** (§6.4 y §6.5 del plan), que cae en estos mismos ficheros:

- `putDetailed` deja de devolver a los independientes entre `alumnos` y devuelve
  `independientes: [{alumno_id, nombres, apellidos}]`;
- `Nota::verificarCrearNotas` deja de sembrarles las notas de las subunidades del
  grupo, y **cuando la unidad tiene dueño siembra una nota y no treinta**.

---

## 2. Los tres que no eran «pintar de más», que es lo que hace que valgan la pena

**`UnidadesController:26` decide una ESCRITURA, no una lista.** `getDeAsignaturaPeriodo`
siembra las unidades por defecto del año cuando `count($unidades) == 0`. Sin acotar, a
un grupo que **no tiene ni una unidad suya** pero sí un independiente con las suyas le
sale `count() == 1` y **se queda sin sembrar**: la asignatura entera con la rejilla
vacía, sin un error en el log y con el docente viendo una materia sin montar. Es la
guarda del 28 ago —«sin unidades no se escribe»— entrando por una puerta nueva y **del
revés**: allí se escribía un cero de más, aquí no se escribe nada.

**`NotasController:73` también escribe.** Esas unidades son las que alimentan a
`Nota::verificarCrearNotas` doce líneas más abajo, que siembra **una fila de `notas` a
cada alumno del grupo por cada subunidad que reciba**. Sin la condición, una sola unidad
de un independiente le mete a los treinta una nota dentro del boletín de otro, **en la
primera carga de la pantalla** y sin que nadie mire dos veces. Y esas filas cuentan.

**`UnidadesController:64` propone un plan de estudios.** Ese panel existe **para copiar
de él**. Una unidad con dueño de hace tres años sale ahí con el mismo aspecto que las
del curso y sin nada que diga de quién es, así que lo que se copie de ahí acaba siendo
el reparto de un curso entero.

Las dos papeleras (`:359` y `:362`) llevan al lado un `restore/{id}`: quien pulsa cree
que devuelve una unidad **al curso** y devolvería la de otro alumno, con su porcentaje
contando otra vez en la definitiva de ése. Restaurar va por id y sigue funcionando —
lo que se acota es **a quién se le ofrece**.

> **Lo que esto deja pendiente y NO es un olvido mío: la papelera del independiente se
> queda sin pantalla.** Hasta que `PUT boletin-independiente/planilla` (§6.1, lote D) la
> tenga, una unidad suya borrada sólo se recupera sabiendo su id. Hoy no se pierde nada
> —nadie está marcado—, pero es una casilla que le falta a la §6.1.

---

## 3. Fase 3: dos decisiones que no son obvias

### 3.1 · Las dos listas se parten de **una sola pasada**, y no de dos consultas

El encargo apuntaba a `BoletinIndependiente::delGrupo($grupoId, $periodoId)`, que da
`independientes` y `normales` en una consulta. **Medido, no vale aquí, y la razón es de
población:** `delGrupo()` cuenta `MATR` y `ASIS`; `Grupo::alumnos()` —la lista que esta
pantalla enseña— trae además los **`PREM`**. Un prematriculado no sale en ninguna de las
dos listas de `delGrupo()`, así que clasificarlo por ausencia lo dejaría **fuera de la
planilla o dentro del boletín ajeno** según de qué lista se partiera.

Es literalmente el descuadre del modal de «Alumnos por grupo» de esta misma noche: dos
consultas que cuentan poblaciones distintas puestas a contestar la misma pregunta.

Así que `putDetailed` recorre `$alumnos` **una vez** y pregunta
`BoletinIndependiente::aplica()` por cada uno. Las dos listas son complementarias **por
construcción**, y el test lo fija comprobando que `count(alumnos) + count(independientes)`
no se mueve.

### 3.2 · `aplica()` alumno a alumno sale además **más barato** que `delGrupo()`

Parece al revés. `alcance()` **memoriza por (alumno, periodo) durante la petición**, así
que una planilla con cuatro unidades y doce subunidades paga treinta consultas **una
vez** y las once llamadas siguientes cero. `delGrupo()` no tiene memoria: pagaría una
consulta **por subunidad**. Dentro de `verificarCrearNotas`, que se llama una vez por
subunidad, la forma «de una consulta» es la cara.

### 3.3 · La decisión de **quién** vive dentro de `verificarCrearNotas`, no en los dos llamadores

`unidades.alumno_id` dice de quién es la subunidad y `unidades.periodo_id` dice en qué
periodo se pregunta la marca: **las dos columnas están en la misma fila**, así que el
método las lee él (una fila por subunidad, al lado de un `Grupo::alumnos()` que son
treinta — no es lo caro de ese método). Los dos llamadores son
`NotasController::putDetailed` y `SubunidadesController::postIndex`, y necesitan
exactamente la misma regla; dos sitios decidiendo de quién es una unidad es de donde
salió el recalculador único.

Por eso la **§6.5** —«`postIndex` crea las notas de un solo alumno»— se cumple **sin una
línea nueva en `postIndex`**. Queda un comentario ahí diciéndolo, para que el siguiente
no lo lea como un olvido.

### 3.4 · `independientes` va **sin `aplica`**

Ese array lista justo a los que tienen alcance, así que `aplica` valdría `true` por
construcción. Un campo constante es uno sobre el que alguien ramificará sin que su rama
muerta se note nunca (§6.4).

Y los tres campos van **tal como vienen de `Grupo::alumnos()`, sin castear**: así
`independientes[].alumno_id` tiene por construcción el mismo tipo que
`alumnos[].alumno_id` —`int` en la instantánea—, en vez de que lo decida un `(int)` que
alguien puede quitar.

---

## 4. `getTrashed` NO se acota, y decidirlo es cerrarlo

`GET unidades/trashed` es la papelera **global**: todas las unidades borradas del
sistema, sin filtro de asignatura ni de periodo. La pregunta que contesta es «qué hay en
la papelera», y la respuesta correcta las incluye a todas — **es la única vista desde la
que una unidad borrada de un independiente se puede llegar a ver**. Acotarla la
escondería del único sitio que la enseña, que es la forma «de menos» de la §9.2.

Lo que sí queda dicho en el propio método: **la respuesta no distingue de quién es cada
fila**, y añadir `u.alumno_id` al `SELECT` movería su instantánea de contrato. O sea que
es un campo nuevo, o sea una decisión y un aviso al front, y no un efecto secundario de
la fase 1.

El detector lo sigue contando como «hay que acotarla», y **está bien que lo haga**: no
es un fallo suyo, es que contesta otra pregunta.

---

## 5. El detector reconoce `IS NULL` **sólo con el alias delante** — y el `<=>` sin él

Segunda ceguera de `tools/unidades-sin-alcance.py` en la misma noche, después de la que
levantó el lote A. En `alcance_de_unidades()`:

```python
ref = r'(?:\b' + re.escape(alias) + r'\.)?alumno_id'
if re.search(ref + r'\s*<=>', sql, re.I):            # el prefijo es OPCIONAL
    return 'si'
if re.search(r'\b' + alias + r'\.alumno_id\s+is\s+...null', sql, re.I):   # aquí es OBLIGATORIO
    return 'si'
```

Dos de mis consultas son de una sola tabla y no llevan alias
(`... FROM unidades WHERE asignatura_id=? and periodo_id=? and alumno_id is null ...`),
así que **salían «hay que acotarla» estando acotadas**. El criterio de aceptación del
reparto —«0 en la columna *hay que acotarla*»— era otra vez inalcanzable, y esta vez
para quien use la forma que la propia §1.5 bendice.

**No toqué la herramienta**: `tools/` no es de mi lote. Lo que hice fue escribir
`unidades.alumno_id` con el nombre de la tabla delante en las dos, que es más claro de
todos modos y hace visible el alcance; queda anotado en el código **por qué lleva el
prefijo**, para que nadie lo «simplifique». El arreglo del detector queda propuesto a
quien lleve `tools/`: la segunda rama debería usar `ref`, igual que la primera.

Estado del detector en este árbol, para mis ficheros:

- `NotasController` — mis dos, fuera de la lista;
- `SubunidadesController` — `:362` fuera; queda `:103`, que es la consulta nueva
  `SELECT a.grupo_id FROM unidades u WHERE u.id = ?`, clasificada **`por-id`**, o sea
  «bien por construcción»;
- `UnidadesController` — quedan tres: dos son `$cons_subunidades` (**`por-id`**) y la
  tercera es `getTrashed`, la de la §4.

---

## 6. Los tests, y que se comprobaron EN ROJO

Dos clases nuevas, **nueve casos**. Con nadie marcado la forma correcta y la incorrecta
dan el mismo verde —`bol_ind_periodos` nace vacía y `u.alumno_id <=> NULL` selecciona
exactamente las filas de hoy—, así que **los nueve construyen el escenario**.

`tests/Contrato/PlanillaSinIndependientesTest.php` (5) ·
`tests/Contrato/RejillaDelCursoSinIndependientesTest.php` (4)

**Cada uno se revirtió a mano contra su cambio y se comprobó que falla, uno por uno.**
No es una comprobación global: cada revert dejó **un solo** caso en rojo, que es lo que
demuestra que el test mide **su** arreglo y no otro.

| Revert | Rojo |
|---|---|
| `$unidadesT` sin `IS NULL` | la rejilla del grupo no trae las unidades del marcado |
| la derivada sin `alcanceCorrelacionado` | la definitiva automática no cuenta las notas del boletín ajeno |
| `$alumnos = $del_grupo` → `$alumnos` | la planilla no trae al marcado y dice a quién no está enseñando |
| `verificarCrearNotas` sin el `continue` | al marcado no se le siembran las notas del grupo |
| `verificarCrearNotas` sin la rama del dueño | la subunidad de una unidad con dueño siembra una nota y no treinta |
| `$cons_unidades` sin `IS NULL` | el curso recibe las unidades por defecto aunque un independiente tenga las suyas |
| años pasados sin `IS NULL` | los años anteriores no proponen la unidad de un independiente |
| `putEliminadas` (unidades) sin `IS NULL` | la papelera de unidades no lista la del independiente |
| `putEliminadas` (subunidades) sin `IS NULL` | la papelera de subunidades no lista la del independiente |

**Dos cosas que el seed no tiene y los tests construyen, dichas para que nadie las lea
como un fallo:**

1. **`unidades_por_defecto` está vacía.** Sin filas, `getDeAsignaturaPeriodo` devuelve
   `''` y la rama que siembra **no se ejecuta jamás**. El test las inserta.
2. **El panel de «años anteriores» viene vacío para todas las asignaturas del seed.**
   Medido: de los nueve años sólo el **7 (2024)** y el **8 (2025)** tienen asignaturas, y
   **sus grados no coinciden**, así que no hay ningún par (materia, grado) repetido entre
   años. El test construye el grupo, la asignatura y las dos unidades de aquel año. Un
   test que se hubiera conformado con lo que hay habría pasado siempre **sin ejecutar la
   consulta que mide**.

---

## 7. Las instantáneas que se movieron: DOS, no una

**`notas-detailed-profesor.json`: una línea añadida, `"independientes": []`.** Ni un
campo quitado ni renombrado — comprobado en el diff, que es lo que pedía el encargo.

**Y `huecos-del-seed.json`, que no estaba previsto y es de las que conviene saber que
existen.** Ese test lleva el mapa de **qué partes de la respuesta no comprueba nadie
aunque los tests estén verdes**: una lista vacía se describe como vacía y a partir de ahí
pasa siempre. `independientes` viene `[]` en el seed, así que entra en ese mapa.

Su docblock manda mirar cuál de las dos cosas pasó antes de regenerar —«el seed dejó de
traer un dato» o «la ruta dejó de devolverlo», la segunda es una regresión que **no ve
ningún otro test**—. Aquí no es ninguna de las dos: es una **tercera**, un campo nuevo
que en un colegio sin nadie marcado vale `[]` **por construcción**. Queda escrito como
fila propia en la tabla de ese test, con la nota de que su forma la fija
`PlanillaSinIndependientesTest`, que **construye** el caso en vez de esperarlo del seed.

Es la fase 3 moviendo el contrato de `notas/detailed`, que es legítimo; **el resto de la
suite pasa sin regenerar nada**, que es el criterio de aceptación de la §4 para la fase 1.

Y viene **vacía**, que es el caso de los quince colegios de hoy: la clave es aditiva y
`app/` es copia por colegio, así que durante el despliegue habrá colegios que no la
manden.

> **Aviso de contrato para quien lleve el buzón del front:** `notas/detailed` gana
> `independientes`. El campo `bol_independiente_datos` de cada fila de `alumnos` **no es
> mío**: sale de `Grupo::alumnos`, que es del lote D.

---

## 8. El bloqueo de la noche: `Nota.php` estaba en dos lotes

El reparto daba `app/Models/Nota.php` al **lote E** (`reparto.md:334`) y a mí me daba la
fase 3, que lo necesita. No se podía hacer desde el llamador: `verificarCrearNotas`
recibe **un `grupo_id`** y resuelve la lista dentro con `Grupo::alumnos()`.

Se levantó con la regla 1.1 —**no tocarlo y avisar**— en vez de editarlo, y el
coordinador me lo reasignó entero: el lote E no lo necesita, porque `puestoAlumno` es una
función pura y sacar al independiente del recuento se hace **eligiendo `$alumnos` en los
ocho llamadores**.

> **Una corrección al traspaso, pequeña pero que engaña si se copia:** se dijo que «la
> decisión de sembrar ya se toma dentro de `verificarCrearNotas`, en
> `quienCreaLasNotas`». **No es ahí.** `quienCreaLasNotas` la llama
> `alumnoPeriodoDetalle` (`Nota.php:270`); `verificarCrearNotas` recibe el `$user_id`
> **del llamador** y siembra siempre. La conclusión que acompañaba al aviso sí aguanta
> —mi cambio es de *a quién* y no de *si*—, pero quien vaya a poner ahí la guarda del
> periodo cerrado se encontrará con que no existe.


---

## 9. Dos cosas que quedan fuera, y por qué

### 9.1 · `NotasController:113` — el periodo a `Grupo::alumnos` NO se puede escribir todavía

El encargo del coordinador es pasarle el periodo a `Grupo::alumnos` para que viaje
`bol_independiente_datos`, el badge de la planilla. **Medido: la otra mitad no está.**
En `main` (`47352b8`) `Grupo::alumnos` sigue siendo `($grupo_id, $con_retirados='')` —dos
parámetros— y **`bol_independiente_datos` no aparece en una sola línea de `app/`**.

PHP deja pasar un tercer argumento a un método de dos sin protestar, así que escribirlo
hoy **compilaría, pasaría los tests y no haría nada**: una llamada en verde contra una
firma que no existe, que es exactamente la forma de fallo que este proyecto lleva dos
semanas nombrando —*la premisa del fallo vive en el otro repositorio*—. Y si D aterriza
con otro nombre, otro orden u otro tipo, habría que volver a tocarlo.

**Es una línea y va en cuanto el cambio de D esté en `main`.**

### 9.2 · El rojo de `AutopruebasDeLasHerramientasTest` es del entorno, no del código

Falla en **los cinco** worktrees. Su mensaje dice *«no se pudo leer `2837171^` (¿worktree
sin ese commit?)»* y **el paréntesis es la hipótesis equivocada**: el commit se lee sin
problema. Lo que no funciona es **`git` dentro del contenedor, en un worktree**:

```
$ cat .worktrees/b/.git
gitdir: /Users/josethguerrero/DESARROLLOS/8myvc/.git/worktrees/b
```

Es una ruta **del host**, y dentro del contenedor el repo está en `/app`, así que esa
ruta no existe: `git` contesta `fatal: not a git repository: (null)`. Comprobado también
en el árbol del lote A. Cualquier herramienta que llame a `git` desde dentro de un
worktree se queda sin su control.

**No se toca**: `tools/` no es de mi lote, y el arreglo es del script de worktrees o del
propio control, no de este lote.
