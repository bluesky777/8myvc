# Lote G — Los 44 interruptores que en el backend no decide nadie

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/g`, rama `fix/lote-g-interruptores`, base
> `simonbolivar_testing_g`.
>
> Secciones asignadas del 05: **§105–107**. Este lote **no es dueño de ningún
> controlador**: su entrega es este documento y sus tests. Lo que haya que
> arreglar en un controlador se anota para el lote dueño.

La pregunta: **de las 44 columnas `tinyint(1)` que en el backend no decide nadie,
¿las mira algún cliente?** Y la segunda, que el
[15](../15-la-noche-en-paralelo.md) deja escrita al lado: **las 48 que ni se
nombran, ¿llegan al cliente por un `SELECT *`?**

Las dos están contestadas. La respuesta corta:

| | |
|---|---|
| Columnas que **la herramienta** dice que no lee nadie | **49** |
| Columnas que **de verdad** no lee nadie, medido | **53** |
| De las 48 que el backend ni nombra, **cuántas sí usa un cliente** | **22**, y son la ficha médica |

El **49** ya estaba desde el 21 de agosto en la [§17 del 12](../12-larastan-nivel-7.md),
así que este lote no lo descubre. Lo que añade son tres cosas:

1. **Por qué nadie lo sabía**: la cabecera de la herramienta decía «dos» (§105).
2. **Contra qué se midió**, que es lo que hace afirmable «no lo lee nadie». Se
   había mirado `myvc_front` en `main`, y faltaban **las otras 22 ramas del
   mismo repositorio** —el front está a mitad de migración— y el bundle
   construido, que el script se salta por tamaño (§106).
3. **Cuatro columnas más**, porque aparecer en un cliente no es que el cliente la
   lea; dos de ellas son casillas que alguien enciende y no deciden nada (§107.1).

---

## §105. El número no cambió; lo que estaba mal era la cabecera de la herramienta

Corrido esta noche contra los tres clientes, desde el árbol `g`:

```
  columnas tinyint(1) distintas ... 157
  ni se nombran .................. 48
  NO DECIDEN NADA ................ 44
  alguien decide con ellas ....... 65

  SIN NADIE QUE LAS MIRE, ni aquí ni allí: 49
```

Idéntico a la §17 del 12, que lo midió el 21 de agosto. **Y sin embargo la
cabecera del propio script dice otra cosa:**

```python
Con `--clientes` la salida separa lo que un cliente sí mira de lo que no mira
nadie. El 21 ago 2026, con los tres delante, quedaron **dos** columnas que no
aparecen ni en el backend ni en ningún cliente: `users.can_ask` y
`matriculas.profes_editar_notas`. Ver docs/migracion/12-larastan-nivel-7.md §17.
```

**Dos, y son cuarenta y nueve.** El documento que la propia cabecera cita dice
49 en un bloque de código, y luego dedica sus tres párrafos finales a `can_ask` y
a `profes_editar_notas` porque son **las dos que tienen algo que contar** — una
está encendida en las 2.351 cuentas, la otra es la hermana por matrícula de una
bandera que espera decisión. La cabecera se llevó **los dos ejemplos** y los
escribió como si fueran **el resultado**.

`git log` del script: **un solo commit**, nunca tocado. Así que no es que el
número haya cambiado: la cabecera nació diciendo eso.

> **Dos suena a «no hay nada»; cuarenta y nueve es una lista.** Y lo que se lee
> antes de correr una herramienta es su cabecera, no el documento que cita.

Es la tercera vez esta noche que lo que apaga una pregunta no es un detector
callado sino **un renglón que dice «ya está»** —las otras dos están en
[§89 y §90](c.md)—, y ésta es la variante más cara de las tres: las otras
dos vivían en tablas de un documento, y ésta vive **dentro del instrumento**, en
el sitio que se lee justo antes de decidir si vale la pena correrlo.

**Corregida la cabecera** con el número que da el propio script y con los dos
ejemplos nombrados como lo que son.

---

## §106. Contra qué se midió, que es lo que hace afirmable la frase

«No lo lee nadie, en ninguna parte» sólo vale lo que valen los ficheros que se
miraron. El 15 lo dice sin rodeos: *un grep de clientes vale lo que valen los
ficheros que mira, y «no lo manda nadie» es la afirmación más fácil de hacer con
una muestra incompleta, porque nada a la vista la contradice.*

Así que antes de repetir el 49, se midió **qué se queda fuera del barrido**.

### 106.1 Dos huecos en el barrido de clientes, medidos

| Hueco | Qué deja fuera |
|---|---|
| `EXTENSIONES = ('.js', '.ts', '.html', '.dart', '.vue')` | **30 ficheros `.mjs`** de `myvc_front`, más `.scss`, `.json`, `.md`, `.xml` |
| `if fichero.stat().st_size > 1_000_000: continue` | **todo bundle construido**, que es justo donde vive el código que corre |

Los dos se comprobaron uno a uno con las 49 columnas delante:

- **En los ficheros que la extensión deja fuera** (`.mjs`, `.scss`, `.json`,
  `.md`, `.xml`, `.yaml` de los tres clientes — **472 ficheros**): **ninguna de
  las 49 aparece.**

  Y ese cero **tiene su control**, añadido después al repasar el lote y ver que
  era el único de la noche que no lo tenía: seis columnas conocidas buscadas en
  ese mismo corpus aparecen todas —`caritas`, `presencial`,
  `mostrar_puesto_boletin`, `perdido` y `one_by_one` en un fichero cada una,
  `obligatoria` en cuatro—. O sea que el grep **sí alcanza** esas extensiones, y
  el cero de las 49 es un cero medido y no un grep que no llegaba.
- **En el bundle**: ver abajo, y es lo que más aporta.

### 106.2 El corpus que la herramienta no puede leer, y que sí contesta

`../myvc_dist` es un repositorio hermano con un **bundle construido** de
3.736.964 bytes —`assets/index-DWlqUB0R.js`, del 21 ago 17:45—, y sus mensajes de
commit siguen las fases de la migración del front («la fase 10 entera —
TypeScript en los 248 ficheros»). El script **no lo lee nunca**: pesa 3,7 veces
el límite de un mega, y ese límite está puesto con una razón escrita —«un
minificado de un mega no dice nada que no diga su fuente»— que es cierta cuando
la fuente está entera delante.

Se buscaron ahí las 49, **con control**:

| Buscado en el bundle | Resultado |
|---|---|
| Las **49** columnas sin lectores | **0 apariciones** |
| Control: `mostrar_puesto_boletin`, `mostrar_nota_comport_boletin`, `caritas`, `perdido`, `presencial` | **aparecen las cinco** |

El control es lo que convierte el cero en una medición: si el bundle no fuera
legible —minificado, comprimido, binario para `grep`— las cinco de control
habrían salido a cero también, y el cero de las 49 no habría significado nada.

O sea que **el 49 se sostiene contra un cuarto corpus que la herramienta no puede
ver**, y por un camino independiente del suyo: el código fuente y el artefacto
construido dicen lo mismo.

### 106.3 Y el hueco que no eran extensiones ni tamaños: **ramas**

Lo anterior miraba `myvc_front` **en `main`**. Al lado hay **veinte carpetas
`myvc_front-*` más**, y las veinte son **worktrees del mismo repositorio**, cada
una en su rama `fase-11/…` — el mismo montaje que el nuestro, para la noche del
front. Son 23 ramas locales en total, y el front está **a mitad de migración**,
así que ahí es justo donde puede haber aparecido un lector nuevo.

Repetido contra las 23 ramas, **con el control delante**:

| Buscado | Resultado |
|---|---|
| Control: `caritas`, `perdido`, `presencial`, `mostrar_puesto_boletin`, `mostrar_nota_comport_boletin` | **52–54 ficheros por rama** (31 en `main`) |
| Las **49** | **0 en `main`**; **5 ficheros** en cada una de las otras 22 |

O sea que el control no sólo alcanza: **alcanza más en las ramas que en `main`**,
porque llevan dentro la reescritura. Y las 49 sí aparecen ahí — en cinco ficheros
y siempre los mismos, todos bajo `app2/src/app/datos/`. Leídos uno a uno, son
**cuatro columnas y todas son declaraciones de tipo**:

```ts
app2/src/app/datos/preguntas.ts:112      aleatorias: boolean | number | null;
app2/src/app/datos/subunidades.ts:23     por_defecto: number | null;
app2/src/app/datos/unidades.ts:29        por_defecto: number | null;
app2/src/app/datos/certificados.ts:40    encabezado_solo_primera_pagina?: unknown;
app2/src/app/datos/certificados.ts:41    piepagina_solo_ultima_pagina?: unknown;
```

Más una línea de un `.spec` que rellena un objeto de prueba. **Ningún sitio las
lee.** Declarar un campo en una interfaz no es leerlo: es describir lo que llega.

Comprobado además el **contenido en disco** de los veinte worktrees —que incluye
lo que todavía no está commiteado, y hay cuatro con cambios sin commitear—: los
mismos cinco ficheros y nada más.

Así que **el 49 aguanta**, y ahora se puede decir contra qué: 23 ramas del front,
sus veinte worktrees con lo no commiteado dentro, `myvc_front_2`, `myvc_flutter`
y el bundle construido de `myvc_dist`.

> Y la lección, que es de la casa: **lo que faltaba no eran extensiones ni
> tamaños, eran ramas.** Un grep de clientes vale lo que valen los ficheros que
> mira, y «los ficheros» en un repositorio a mitad de migración no son los del
> directorio: son los de todas sus ramas.

### 106.4 El arreglo no es leer el bundle: es decir que no se ha leído

Pasarle `../myvc_dist` como cuarto cliente **no cambia el número** —siguen siendo
49— porque el fichero que importa sigue pasando del mega y se salta. Lo que sí
cambia desde esta noche es que **el script lo dice**:

```
  NO LEÍDOS por pasar de 1 MB (1). Si la pregunta es «esto no lo
  mira nadie», hay que mirarlos a mano, y con columnas de control:
       3,736,964 B  /Users/…/myvc_dist/assets/index-DWlqUB0R.js
```

Subir el límite habría sido el arreglo equivocado: un bundle minificado dentro
del mismo `grep` que el código fuente ensucia la respuesta de las 108 columnas
que sí tienen lectores, y no arregla el problema de fondo, que es que **nadie
sabía que había algo sin leer**. Un barrido que encoge en silencio se lee igual
que uno que no encontró nada — la misma familia que el `| head -5` que dijo cinco
donde había seis.

Y el otro hueco, el de las extensiones, sí se cerró donde era barato:
**`.mjs` entra en `EXTENSIONES`** —`myvc_front` tiene 30— después de comprobar
que ninguna de las 49 aparecía en ellos, para que el cambio no se justifique a sí
mismo.

**De qué es ese build, medido y no supuesto**: su `index.html` abre con
`<html ng-app="myvcFrontApp">` y el bundle trae `angular.module` **36 veces** y
cero `@angular/core`. O sea que es la forma construida de **`myvc_front`**, el
AngularJS — **no** de `myvc_front_2`, que es Angular—. Así que no es un cliente
más: es el mismo cliente compilado, y por eso su cero vale como confirmación y no
como corpus nuevo.

**Lo que esto no dice**, y no se afirma: que sea lo que está desplegado hoy en
los dieciséis colegios. No lo nombran ni `DESPLIEGUE.md` ni
`DESPLIEGUE-REFERENCIA.md`. Y hay un detalle que conviene tener delante si
alguien lo usa para decidir algo: **el bundle es del 21 ago 17:45 y `myvc_front`
lleva 35 commits desde entonces**, así que describe un front de hace dos días.
Para la pregunta de este lote da igual —una columna que no estaba tampoco está
ahora— pero para cualquier otra, no. Queda como pregunta abajo.

---

## §107. La segunda pregunta: sí llegan al cliente por un `SELECT *`, y son 22

De las **48 columnas que el backend ni nombra**, hay **22 que un cliente sí
mira**, y todas son de la misma tabla —`antecedentes`— y llegan por la misma
puerta:

```php
// app/Http/Controllers/Matriculas/EnfermeriaController.php:43
$consulta = 'SELECT * FROM antecedentes WHERE alumno_id=?';
```

Son los antecedentes médicos del alumno: siete `fami_*` (antecedentes
familiares), ocho `patol_*` (patologías) y siete de vacunación, `varicela`
incluida. `myvc_front` las pinta y las guarda —el guardado va por
`EnfermeriaController`, campo a campo, con `ColumnaSegura`—, y el backend **no
las nombra en ningún sitio**: existen en el esquema, viajan por el `*` y sólo
tienen sentido en la pantalla.

> **Una columna que el backend no nombra no es una columna muerta.** Es lo que
> este lote tenía que contestar, y la respuesta es que **el `SELECT *` es una
> puerta de verdad**: 22 de 48. Buscarlas en `app/` da cero apariciones, y con
> ese cero delante la conclusión natural —«esto no lo usa nadie, se puede
> borrar»— habría borrado la ficha médica de los alumnos.

### 107.1 Y cuatro más, porque **aparecer en el cliente no es leerlo**

El detector contesta «lo mira `myvc_front`» con un `grep` de la palabra. Eso
mete en el montón de las vivas cosas que no deciden nada:

- una **declaración de tipo** (`otro1?: number`), que describe lo que llega;
- un **`ng-model`**, que es justamente lo contrario de leerla: es la casilla con
  la que alguien la **enciende**.

Clasificadas las 21 columnas del montón B que algún cliente «mira», por cómo
aparecen en los tres clientes:

| Columna | apariciones | declaración | `ng-model` | lecturas de verdad |
|---|---|---|---|---|
| **`can_upload`** | 1 | 0 | **1** | **0** |
| **`deriva_de_tardanzas`** | 2 | 0 | **2** | **0** |
| **`mensaje_aprobo_con_pendientes`** | 2 | **2** | 0 | **0** |
| **`otro1`** | 1 | **1** | 0 | **0** |
| `one_by_one` | 7 | 1 | 1 | 5 (`ng-hide="…one_by_one"`) |
| `obligatoria` | 8 | 2 | 0 | 6 (`ng-if="unidad.obligatoria==0"`) |
| `puestos_alfabeticamente` | 8 | 3 | 0 | 5 (`if ($ctrl.USER.puestos_alfabeticamente)`) |
| … las otras 14 | | | | todas con lecturas de verdad |

O sea que **son 53 y no 49** las columnas que no lee nadie en ninguna parte. Las
cuatro que faltaban estaban en la mitad limpia de la tabla.

**La columna «lecturas de verdad» de esa tabla es un recuento, no un veredicto**,
y hay que decirlo porque el recuento tiene la ceguera de la
[§72.5](../05-codigo-muerto-y-roto.md): cuenta también **lo que se escribió
sobre** la columna. En `contrario`, de sus 44 «otras», una es una frase de un
documento del front; en `allDay`, una es el README de `angular-ui-calendar`, que
es código de terceros; en `caritas`, una es una línea de `PREGUNTAS-MANANA.md`.

Por eso las diecisiete con recuento distinto de cero **se leyeron**, no se
contaron, empezando por las de recuento más bajo —que son las que un par de
líneas de prosa podría estar sosteniendo—. Las cinco más ajustadas tienen lectura
de verdad y sin discusión:

```
caritas                          ng-show="row.entity.caritas"
mostrar_puesto_boletin           if ($ctrl.year.mostrar_puesto_boletin && …)
allDay                           if (!evento.allDay && !evento.end)
show_subasignaturas_en_finales   ng-if="::year.show_subasignaturas_en_finales …"
sin_uniforme                     ng-show="uniforme.sin_uniforme"
```

**El recuento sirvió para ordenar por dónde mirar; el 53 sale de leer.** Es la
misma regla que el resto de la noche: un detector da sitios donde mirar, nunca
una lista de fallos.

Y **dos de las cuatro son interruptores con su casilla en pantalla**:

| Interruptor | Dónde se enciende | Qué decide |
|---|---|---|
| `ws_actividades.can_upload` | `editarActividad.html:124`, casilla del examen | **nada** — el backend lo guarda (`ActividadesController:197`, `MisActividadesController:95`) y no lo lee nadie |
| `dis_procesos.deriva_de_tardanzas` | formulario del proceso disciplinario | **nada** — se inserta (`DisciplinaController:202`) y no lo lee nadie |

Es **exactamente la forma que el script existe para encontrar** —«el colegio
expresa una intención por un camino que el código no mira», la de la
[§74](../05-codigo-muerto-y-roto.md)— y el propio script las clasificaba como
vivas. La casilla que enciende una columna cuenta como lector de esa columna
cuando lo único que se busca es su nombre.

> **Una columna que sólo aparece donde se escribe no tiene lectores: tiene
> autores.** Y un detector que busca el nombre no distingue las dos cosas.

Lo que **no** se hizo: meter esa distinción dentro del script. `ng-model` y una
declaración de tipo se reconocen con dos expresiones regulares, pero un `grep` que
además juzga **qué clase de aparición** es deja de ser «sitios donde mirar» y
empieza a ser una lista de fallos, que es lo que este repo tiene escrito que no
hay que hacer. La clasificación queda aquí, con el comando, para quien la quiera
repetir.

Las **26 restantes** de ese montón no las mira nadie tampoco en los clientes, y
son las que la §17 ya explicó una a una: las veinte `per{1..4}_*` de
`df_alumnos` / `df_asignaturas` (la copia denormalizada de las definitivas que
alguien empezó y no terminó, **cero filas**), las de `default_unidades` /
`default_subunidades` (**cero filas**), las firmas de `dis_procesos` y los
`*_accepted` de `change_asked_assignment`.


### 106.5 Y una tercera forma de mentir, que salió al usar la herramienta desde un worktree

El ejemplo de la propia cabecera dice `--clientes ../myvc_front ../myvc_front_2
../myvc_flutter`. **Desde un worktree eso apunta a otro sitio**:
`.worktrees/g/../myvc_front` no existe. Y con un árbol por sesión —lo que hace
esta noche— ésa es la forma normal de correrlo.

Lo que hacía el script hasta esta noche, medido:

| Rutas dadas | Clientes mirados | «SIN NADIE QUE LAS MIRE» |
|---|---|---|
| las tres buenas | `myvc_front, myvc_front_2, myvc_flutter` | **49** |
| una buena y dos que no existen | `myvc_front` | **50** |

Seguía adelante con lo que encontrara y **el aviso iba por `stderr`**, así que
dentro de un tubo desaparece y queda el número solo.

> **El error da un número MÁS GRANDE que el bueno.** Las columnas del cliente que
> falta caen del lado de «no las mira nadie», así que una ejecución rota **parece
> un hallazgo mejor** que una correcta. Es la peor dirección posible para
> equivocarse, y la que nadie va a cuestionar.

**Ahora aborta**, que es lo que ya hizo `escrituras-en-las-notas.py` cuando le
pasó lo suyo: *un cero tiene la misma cara que un arreglo*, y aquí un cincuenta
tiene la misma cara que un cuarenta y nueve mejor.

---

## §107.2 El test, que es lo único que impide que un cero borre la ficha médica

`ColumnasQueSoloViajanTest`, dos casos, y **ninguno lleva la lista escrita a
mano**: la derivan del volcado del esquema y del código, igual que la
herramienta. Una lista a mano se queda vieja el día que alguien añada la columna
23, que es justo el día en que el test tendría que avisar.

| Caso | Qué mide | Cuándo cae |
|---|---|---|
| `la ficha medica trae las columnas que el backend no nombra` | que `PUT enfermeria/datos` las devuelva **todas** | si la **respuesta** encoge |
| `son veintidos y todas de antecedentes` | cuántas hay | si cambia el **esquema** o alguien empieza a nombrar una |

Van separados a propósito: miden dos cosas distintas y se rompen por motivos
distintos, y un solo caso que las mezclara diría «cambió algo» sin decir qué.

**Comprobado al revés**, que es lo que convierte un verde en una medición: se
encogió el `SELECT *` a `SELECT id, alumno_id, observaciones` —o sea, se simuló
exactamente la limpieza que este test existe para impedir— y **cayó el primer
caso y sólo el primero**. El segundo siguió verde, porque el esquema no había
cambiado. Es lo que se esperaba de cada uno.

Lo que el test no puede hacer, y hay que decirlo: **no comprueba que el front las
use**, sólo que llegan. Si mañana `myvc_front` deja de pintarlas, las 22 pasan a
ser esquema muerto de verdad y aquí seguiría todo verde. Esa mitad no es
comprobable desde este repositorio.


---

## Lo que se lleva la noche de este lote

Este lote no descubrió el 49 —lo descubrió la §17 del 12 el 21 de agosto—.
Descubrió **por qué nadie lo sabía**, y eso resultó ser lo mismo que encontraron
las otras dos secciones de esta sesión ([§89 y §90](c.md)).

Cuatro instrumentos mintieron esta noche, y **los cuatro en la misma dirección**:

| Dónde | Qué decía | Qué era |
|---|---|---|
| §89 | `boletines2/destroy` estaba en la `TABLA_DE_ID` del barrido | medida **con otro propósito**, nunca juzgada |
| §90 | «§71, cortada con 410» en la tabla de la §77.2 | era **su vecina** la cortada |
| §105 | «quedaron **dos** columnas» en la cabecera del script | eran **49** |
| §106.5 | el ejemplo de uso con rutas relativas | desde un worktree da **50**, y sólo avisa por `stderr` |

Ninguno de los cuatro es un fallo al medir. **Los cuatro son fallos al decidir si
vale la pena medir**, y por eso son más caros que un detector que se equivoca:
un detector equivocado se corre y su salida se lee; un renglón que dice «ya está»
hace que nadie lo corra.

Y los cuatro empujan hacia el mismo lado —hacia «no hay nada» o hacia «ya está»,
nunca hacia la duda—, con una excepción que es la más peligrosa de todas: la de
la §106.5 **da un número más grande que el bueno**. Una ejecución rota que parece
un hallazgo mejor que una correcta no la cuestiona nadie.

> Lo que se puede hacer con esto, y es barato: **cuando una cabecera, una tabla o
> un renglón diga que algo ya está resuelto, comprobar el número contra la
> herramienta antes de creerlo.** Las cuatro veces bastó con correr lo que ya
> existía.

---

## PARA JOSETH

### 1. ¿Quién despliega `../myvc_dist`? (§106.2)

**Qué es, ya está medido**: la forma construida de `myvc_front` —su `index.html`
lleva `ng-app="myvcFrontApp"` y el bundle trae `angular.module` 36 veces y cero
`@angular/core`—, versionada en su propio repositorio con `.htaccess` dentro. No
es un cliente más: es el mismo cliente compilado.

**Lo que no se sabe** es si es lo que corre en los dieciséis colegios. Ningún
documento de despliegue lo nombra. Si lo es, hay una consecuencia que no es de
este lote: **el artefacto desplegado es del 21 ago 17:45 y `myvc_front` lleva 35
commits desde entonces**, o sea que lo que se está midiendo del front estos días
no es lo que ven los colegios. Si es sólo un artefacto de trabajo, no hay nada
que hacer.

### 2. Dos interruptores con casilla en pantalla que no deciden nada (§107.1)

| Interruptor | Dónde se enciende | Qué hace hoy |
|---|---|---|
| `ws_actividades.can_upload` | casilla «puede subir archivos» del examen, `editarActividad.html:124` | **nada**: se guarda y no lo lee nadie, ni aquí ni en ningún cliente |
| `dis_procesos.deriva_de_tardanzas` | formulario del proceso disciplinario | **nada**: se inserta y no lo lee nadie |

Es la [§74](../05-codigo-muerto-y-roto.md) otra vez: alguien enciende una casilla
creyendo que decide algo. **No se tocan** —encender un interruptor por iniciativa
propia enciende pantallas en dieciséis colegios, y apagar la casilla es una
decisión de producto—, pero conviene saber que hoy no hacen nada.

### 3. Las 53 columnas: qué se hace con ellas

Ya está contestado que **no las lee nadie**, y la [§17 del 12](../12-larastan-nivel-7.md)
ya midió que **casi ninguna tiene datos dentro**. No hay nada que arreglar. Lo
único que queda es una decisión de limpieza que no es de esta noche: `df_alumnos`,
`df_asignaturas`, `df_grupos`, `default_unidades` y `default_subunidades` son
**cinco tablas con cero filas y cero menciones**. Borrarlas es un cambio de
esquema —migración— y no lo pide nadie; dejarlas cuesta que la próxima persona
que mire `notas_finales` crea que hay una copia denormalizada que mantener.

## PARA OTRO LOTE

- Las 22 columnas de `antecedentes` que llegan por `SELECT *`
  (`EnfermeriaController:43`) no son de ningún lote de esta noche. **No hay nada
  que arreglar**: se anotan porque el cero de apariciones en `app/` invita a
  borrarlas y borrarlas apagaría la ficha médica.
