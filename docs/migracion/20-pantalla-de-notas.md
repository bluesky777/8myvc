# 20 — La pantalla de notas: guardar por lotes

**Escrito el 24 ago 2026**, a partir de tres cosas que pidió Joseth el mismo día:
que el docente pueda cambiar varias notas seguidas sin esperar a cada guardado,
que cada celda diga por sí misma si ya se guardó, y que la nota rápida deje de
mandar una petición por nota. Y de una cuarta que las ordena todas: **el
contador que tumbaba los colegios era `Entry Processes`**, confirmado por Joseth
mirando el panel.

> **La mayor parte de este plan es del front.** El endpoint del backend **ya
> existe** desde el 24 ago y no hay que escribir ninguno nuevo — la §0 explica
> por qué, y es lo que hace que este trabajo sea barato. Lo que sigue vive en
> `myvc_front` salvo la §7.

---

## §0 — El endpoint ya está, y sirve para los tres casos

`PUT notas/lote` ([NotasController:460](../../app/Http/Controllers/NotasController.php#L460),
13 tests en `tests/Contrato/GuardarNotasEnLoteTest.php`) se escribió para
`myvc_flutter` y se dio por «lo que pide la app». **Sirve igual para la
planilla web, sin tocar una línea**, y esa es la noticia que cambia el tamaño de
este plan.

```
PUT api/notas/lote
{ "notas": [ {"id": 8811, "nota": 45}, {"id": 8812, "nota": 50}, … ] }

→ 200 { "guardadas": 2, "fallidas": [], "definitivas": [ … ] }
```

Lo que importa de su contrato, y por qué cada pieza encaja con lo que se pide:

| Lo que hace | Por qué sirve aquí |
|---|---|
| Recibe **ids de nota sueltos**, no una columna ni una fila | una columna (una subunidad × N alumnos), una fila (un alumno × N subunidades) y **un puñado disperso de celdas recién tecleadas** son la misma cosa: una lista de ids. Los tres casos de Joseth son **un solo endpoint** |
| Agrupa el recálculo **por par (asignatura, periodo)** | un lote de varios alumnos recalcula **una vez**, no una por nota |
| Devuelve `fallidas[]` con `{id, motivo}` y responde **200 igual** | es exactamente lo que necesita el borde que se queda quieto: éxito parcial, celda por celda |
| Devuelve `definitivas[]` de los alumnos tocados | la columna de definitiva se repinta **sin una petición más** |
| Tope de **200** (`LOTE_MAXIMO`) | una columna de un grupo grande son 45. Cabe la fila, la columna y varias tandas de tecleo |
| Comprueba el permiso **una vez y antes de escribir**, y escribe en **una transacción** | una columna entra entera o no entra. Hoy, 45 transacciones independientes dejan definitivas calculadas sobre estados intermedios |

**Lo único que no sabe hacer es crear una nota que no existe**: actualiza por
`id`. No hace falta — `putDetailed` llama a `Nota::verificarCrearNotas` en cada
carga de la planilla, así que toda celda pintada ya tiene su fila y su id. Queda
escrito porque es el borde del contrato: **si algún día la planilla pinta una
celda sin id, el lote no es el camino para esa celda.**

---

## §1 — Lo que hace el front hoy, leído

Tres caminos, y los tres mandan **una petición por nota**. Los ficheros son de
`myvc_front`, que es **otro repositorio**: por eso se citan por ruta y no por
enlace.

| Sitio | Qué hace |
|---|---|
| `NotasCtrl.ts:560` — teclear una nota | un `NotasApi.actualizar` por celda |
| `NotasCtrl.ts:681` — `columnaNotaRapida` | recorre los alumnos y **empuja un `actualizar` por cada uno**, todos a la vez |
| `NotasCtrl.ts:742` — `filaNotaRapida` | lo mismo por las notas del alumno |

Y el aviso de esas dos está roto de una forma que el lote arregla de paso: el
éxito sale cuando `contadorGuardadas === alumnos.length - 1`, o sea que **si una
sola falla, el «N notas guardadas» no aparece nunca** — el docente ve N-1
errores y ningún éxito, sin saber cuáles entraron. Con un lote hay **una
respuesta con `guardadas` y `fallidas` dentro**, y el mensaje deja de depender de
contar promesas.

### El error que sale «cuando lleva muchos envíos a la vez»

La sospecha, y es barata de confirmar: **es un `429`**. Toda la API va detrás de
`throttle:api` ([Kernel.php:43](../../app/Http/Kernel.php#L43)), que son
**120 peticiones por minuto y por usuario**
([RouteServiceProvider:63](../../app/Providers/RouteServiceProvider.php#L63)).
Tres columnas de 45 son **135**: las últimas quince se van con *Too Many
Requests*.

**El arreglo es el lote, no subir el límite.** Subirlo dejaría al front seguir
llenando ranuras de entrada, que es justo lo que se quiere parar; con lotes esas
tres columnas son **tres peticiones**, y el límite deja de rozarse.

> Confirmarlo es abrir la pestaña de red y mirar el código de la que falla. Si
> resulta ser otra cosa —un 500, un corte de conexión— hay que escribirlo aquí,
> porque cambia qué más hay que arreglar. Lo que **no** cambia es el plan: el
> lote quita el problema en las dos lecturas.

---

## §2 — Qué le hace esto a los `Entry Processes`

La pregunta de Joseth. La respuesta es **sí, y por más de lo que parece**, porque
lo que llena ese contador no es el trabajo: es **cuántas peticiones hay dentro de
PHP a la vez**.

Una petición cuesta, antes de tocar la nota:

| Coste fijo, por petición | Medido en |
|---|---|
| arranque del framework con OPcache | ~28 ms ([02 §1](02-plan-rendimiento.md)) |
| resolver **quién pregunta** (`fromToken`, 5–8 consultas) | 40–80 ms ([02 §4](02-plan-rendimiento.md)) |

O sea **~70–110 ms que se pagan enteros por cada nota**. Guardar una columna de 45
notas de una en una gasta ese coste fijo **45 veces**; en lote, una.

> **Aquí decía además que «el recálculo no es lo caro, ~4 ms», y eso venció.** Es
> cierto de `calcular()` —1,70 ms
> ([coste-del-recalculo.php](../../tools/coste-del-recalculo.php))— y **falso del
> recálculo entero**: `recalcularPorNota` son ~6 consultas por nota, así que a
> escala de columna pesa tanto como el camino común. El desglose está en la §7.c y
> en el [02](02-plan-rendimiento.md). No cambia ninguna decisión —el lote se lleva
> las dos— pero citar «el recálculo no cuenta» manda al sitio equivocado al
> primero que quiera ahorrar más.

**Medido la noche del 24 por `8myvc-ad`** (era una estimación hasta entonces; el
detalle, la población y las condiciones están en el
[02](02-plan-rendimiento.md)):

| Una columna de 45 notas | Hoy | En lote |
|---|---|---|
| peticiones | **45** | **1** |
| veces que se resuelve quién pregunta | 45 | 1 |
| recálculos de la asignatura | 45 | **1** |
| transacciones | 45 | 1 |
| **consultas** | **717** | **220** |
| **tiempo** | — | **3,8×–5,9× más rápido** |

**La razón es cota inferior, no una cifra optimista.** Los milisegundos se
duplicaron entre corridas —la máquina estaba cargada— y la razón aguantó con las
consultas idénticas al dígito: la carga se suma **igual a los dos lados** y acerca
cualquier razón a 1, o sea que **esconde la ventaja en vez de exagerarla**. Es la
misma lección de la §«medir una vez es no medir» del 02, por el lado bueno.

### Pero lo que de verdad muerde es la simultaneidad

`$http` de AngularJS no limita nada por su cuenta; el navegador abre unas **seis
conexiones a la vez** por dominio, así que **un docente pulsando una columna
ocupa hasta seis de las cincuenta ranuras**, y las va reponiendo hasta acabar las
45.

Ocho docentes haciendo eso a la vez —que es exactamente lo que pasa en cierre de
periodo, todos el mismo día y a la misma hora— son **48 de 50**. Ahí es donde el
contador llega al 100% y el servidor empieza a contestar **508** a todo el
colegio.

**Con lotes, un docente ocupa una ranura, una vez, y la suelta.** Ocho docentes
son ocho. Ése es el cambio, y es de forma, no de porcentaje: deja de haber una
acción de un solo usuario que pueda ocupar seis ranuras a la vez.

> La regla que se lleva, y vale para todo lo que venga después: **en `Entry
> Processes`, quitar una petición vale más que hacerla rápida.** Por eso
> `putUpdate` devolviendo la definitiva dentro de su respuesta —que no ahorra ni
> un milisegundo de base— cuenta como mejora de recursos: quita el viaje de
> vuelta.

---

## §3 — La máquina de estados de la celda

Es el corazón del front y conviene escribirla entera, porque la mitad de los
fallos de esta clase de pantalla salen de una transición que nadie escribió.

| Estado | Qué se ve | Qué significa |
|---|---|---|
| `limpia` | sin borde | lo que hay en el input es lo que hay en la base |
| `pendiente` | borde **animado** | modificada, todavía no enviada |
| `enviando` | borde **animado**, el mismo | va dentro del lote que está en vuelo |
| `fallida` | borde **quieto** | el último intento no la guardó |

`pendiente` y `enviando` se pintan **igual a propósito**: al docente no le sirve
distinguirlas —no puede hacer nada distinto con una que con la otra— y dos
animaciones parecidas en la misma rejilla sólo añaden ruido. Se separan por
dentro porque el agrupador necesita saber cuáles ya salieron.

### Las transiciones

```
editar la celda        cualquiera  →  pendiente     (también desde `fallida`: la animación
                                                     se reanuda, que es lo que se pidió)
vence el debounce
o se llenan 7          pendiente   →  enviando      (se congela el conjunto y sale el PUT)
id en `guardadas`      enviando    →  limpia
id en `fallidas`       enviando    →  fallida
red caída / 5xx / 429  enviando    →  fallida       (el lote entero)
```

### La transición que hay que escribir aunque no se le pregunte

**Una celda que el docente volvió a editar mientras el lote volaba no puede pasar
a `limpia`.** Su valor actual ya no es el que se envió, y pintarla limpia le
diría al docente que se guardó un número que nadie mandó.

La regla, y es la única parte de este diseño donde un descuido corrompe lo que ve
el usuario:

> Cada celda enviada viaja con **el valor con el que salió**. Al volver la
> respuesta, sólo se mueve de estado si `valor_actual === valor_enviado`. Si no
> coincide, la celda **sigue `pendiente`** y entrará en el lote siguiente.

Eso hace que reeditar durante el vuelo sea seguro sin cancelar nada, y es también
lo que permite que el docente siga tecleando sin mirar: es el caso que Joseth
describe —«seguramente ya hay otros inputs modificados y con la animación, porque
el docente justo después de los 2 segundos hizo otras modificaciones»—.

### Las definitivas, gratis

`definitivas[]` de la respuesta repinta la columna de definitiva de los alumnos
tocados. Ya viene en el cuerpo; no pedirla es no aprovecharla, y pedirla aparte
sería devolver una petición de las que este plan quita.

Ojo con la forma: el alumno **sin fila** viaja con `nota: null` en vez de
omitirse, a propósito, para que el front distinga «no tiene definitiva» de «no
vino en la respuesta». Se pintan distinto.

---

## §4 — El agrupador: siete notas o dos segundos

Una cola por celda, `nota_id → {valor, valor_enviado, estado}`. Dispara cuando:

- la cola llega a **7** celdas pendientes, **o**
- pasan **2 s** desde la última edición.

Los dos números son de Joseth y **no hay que medirlos para empezar**: siete y dos
segundos son un punto de partida razonable, y lo que sí conviene es dejarlos en
una constante con nombre para poder moverlos cuando un docente diga que se le
adelanta o se le retrasa.

### Un solo lote en vuelo a la vez

**No se manda un lote nuevo mientras hay uno volando; se encola.** Tres razones,
y las tres son reales:

1. **`Entry Processes`.** Es lo que garantiza el «un docente, una ranura» de la
   §2. Sin esta regla, un docente rápido vuelve a tener varias peticiones
   simultáneas y se pierde la mitad de la ganancia.
2. **Orden.** Dos lotes en vuelo pueden llegar al revés. Si la misma celda va en
   los dos —posible, porque el docente la reeditó—, el valor viejo puede ganar.
   Serializar hace que la última escritura sea, de verdad, la última.
3. **La carrera del recálculo, que está abierta hoy.**
   `DefinitivasDeAsignatura::recalcular` decide crear o actualizar con un
   `SELECT … ORDER BY id LIMIT 1` **sin `FOR UPDATE`**
   ([DefinitivasDeAsignatura.php:205](../../app/Services/DefinitivasDeAsignatura.php#L205)),
   y sólo después inserta. Dos recálculos concurrentes del mismo par
   `(asignatura, periodo)` pueden ver los dos «no existe» y **insertar los dos**:
   un duplicado en `notas_finales`, que es la familia de fallos que el
   [plan de definitivas](10-definitivas.md) existe para cerrar.

> **La tercera razón importa más de lo que parece, y en la dirección contraria a
> la intuitiva: el flood de hoy ya está ejerciendo esa carrera.** Cuarenta y
> cinco `notas/update` simultáneos son cuarenta y cinco recálculos concurrentes
> del mismo par. La fase 0 midió **1 duplicado** en un colegio; éste es un camino
> por el que sale.
>
> **Y por eso serializar en el front es una mitigación, no el arreglo.** El
> arreglo es la **clave única de la [fase 2](10-definitivas.md)**, que convierte
> la carrera en un error en vez de en un duplicado. Una mitigación que vive en
> **uno de los cuatro clientes** no protege a los otros tres — la app de Flutter
> llama al mismo endpoint. Se escribe aquí para que quien lea este plan no lo
> cuente como cerrado.

### El mensaje

**Uno por lote, no uno por nota.** Con `{guardadas, fallidas}` en la mano:

- `fallidas` vacío → *«7 notas guardadas.»*
- con fallidas → *«5 guardadas, 2 no. Vuelve a escribirlas.»*, y esas dos ya se
  ven solas: son las que se quedaron con el borde quieto.

Nada de un `toastr` por celda: cuarenta y cinco avisos apilados es la versión
ruidosa de no avisar.

---

## §5 — El borde que no es un borde

**No es un `border`, y la distinción es el diseño entero.** Es un elemento
flotante, posicionado respecto al contenedor de la celda, que se pone **detrás
del input y un poco más grande que él**. Lo único que se ve de él es el reborde
que asoma por los cuatro lados, y eso **da la impresión de ser un borde sin
serlo**.

La rejilla ya trae el contenedor que hace falta —`notas.html:107` envuelve cada
input en un `div` que es el que lleva `inputnota-perdida` / `cursorcell`—, así
que la marca sólo crece en un hermano:

```html
<div class="celda-nota" ng-class="{'inputnota-perdida': …, 'cursorcell': …}">
	<div class="celda-nota__halo" ng-class="…"></div>   <!-- nuevo, y va ANTES -->
	<input class="input-nota" …>
</div>
```

```scss
.celda-nota {
	position: relative;   // no saca del flujo ni añade tamaño: no mueve nada
}

.celda-nota__halo {
	position: absolute;
	inset: -3px;          // 3 px más grande que el input por cada lado
	border-radius: 6px;   // el del input + 3, o las esquinas se ven cortadas
	z-index: 0;           // DETRÁS
	pointer-events: none;
}

.input-nota {
	position: relative;
	z-index: 1;           // DELANTE, y es lo que tapa el centro del halo
}
```

### Por qué esto es mejor que dibujar un anillo

- **No hay que recortar nada.** Un anillo animado obliga a un `mask` o a cuatro
  gradientes; aquí **el input es la máscara**. El halo puede ser un rectángulo
  lleno con el degradado girando entero, y sólo asoman los 3 px del borde.
- **No hay nada por encima del input.** El foco, el cursor, la rueda del ratón
  sobre un `type="number"` y el `uib-tooltip` siguen llegando sin que nadie tenga
  que acordarse del `pointer-events`. Se pone igual, por si un día el halo crece.
- **`box-sizing` no entra en la conversación.** Un `position: absolute` está
  fuera del flujo: no ocupa espacio y no puede empujar a nadie. El input no se
  toca —ni su `border`, ni su `padding`, ni sus 41 px de ancho— así que la rejilla
  no se mueve un píxel. Que la técnica **no toque el input** es justamente lo que
  lo garantiza.

### La condición que hace que el truco funcione, y hoy se cumple por accidente

**El input tiene que ser opaco.** Si su fondo es transparente, el degradado del
halo se ve por el centro y en vez de un borde hay una celda pintada.

Hoy lo es, pero **no porque nadie lo haya decidido**: `input.input-nota` en
`_formularios.scss:196` declara `-moz-appearance` y `width`, y **ningún
`background-color`**. El fondo blanco viene del valor por defecto del navegador
para un campo. Los estados que sí lo declaran son los otros tres —
`.inputnota-perdida input` (`rgb(230,224,224)`), `.inputnota-superior input`
(`#f9fcff`) y `.cursorcell input:hover` (`#fefbf1`)—, o sea justo los que hoy
tapan bien.

> **Se declara el fondo en `.input-nota` como parte de este trabajo.** Es una
> línea, y sin ella el diseño depende de un valor de agente de usuario: un
> navegador con tema oscuro forzado o una hoja de accesibilidad que ponga los
> campos transparentes convierte el halo en un relleno. Un fallo así no se ve en
> la máquina de quien lo escribe.

### El color del halo

Rojo, azul y ámbar **ya están cogidos** en esa rejilla: `#E61900` es *perdida*,
`#a4d3fe` es *superior* y `#fecf49` es el hover de la nota rápida
(`app/scripts/notas/_estado-notas.scss`). El halo necesita un tono que no esté en
uso —violeta es el hueco natural— o el docente leerá «perdida» donde dice «sin
guardar».

Y aquí está la ventaja de que el halo viva **detrás y aparte**: el color del
input sigue diciendo **qué nota es** y el halo dice **si ya viajó**, a la vez y
sin competir. Poner el estado de guardado en el `border` del input obligaría a
elegir entre las dos cosas justo cuando hacen falta las dos — al teclear un 30,
la celda es perdida **y** está sin guardar.

### La animación, y el detalle que sale gratis

**Parar la animación conservando el halo es una sola propiedad:**

```scss
.celda-nota__halo--fallida { animation-play-state: paused; }
```

Eso es literalmente lo que se pidió —*«que se quede el borde pero con la
animación quieta»*— y no hace falta ningún estado nuevo en CSS: es el mismo
elemento, la misma animación, detenida.

Sobre **qué** animar, y aquí sí hay una restricción real: puede haber **45 halos
animándose a la vez**, en los portátiles que tienen los colegios.

- Girar un degradado cónico con `transform: rotate()` lo resuelve el compositor y
  no repinta. Es lo que la forma «rectángulo lleno tapado por el input» hace
  natural, porque no hay que recortar el giro.
- Animar `background-position` repinta cada fotograma, 45 veces. Es la forma fácil
  y es la que hay que evitar.

**Esto hay que medirlo en una máquina de colegio, no en la de desarrollo**, y es
la única parte del front de este plan que puede salir mal por rendimiento. Si 45
halos no van fluidos, la salida no es quitar la animación: es animar **sólo las
celdas visibles**.

### `prefers-reduced-motion`

Con `reduce`, **el halo se queda y la animación no arranca**. Entonces
`pendiente` y `fallida` dejan de distinguirse por el movimiento y tienen que
distinguirse por otra cosa —grosor o tono—. No es un extra: cuarenta y cinco
halos girando es exactamente el patrón que provoca malestar a quien tiene esa
preferencia puesta.

### Y un temporizador que ya existe y hay que contar

`notas.html:108` trae `ng-model-options="{ updateOn: 'default blur', debounce:
{'default': 1000, 'blur': 0} }"`. O sea que **el modelo no se entera de lo que se
teclea hasta un segundo después**, y `ng-change` —de donde tiene que salir el
paso a `pendiente`— tampoco.

Dos consecuencias, y ninguna es teórica:

- **Los dos retrasos se suman.** El segundo del `ng-model` más los dos del
  agrupador (§4) son **tres** desde la última tecla hasta el PUT, no dos. Si se
  quieren dos de verdad, el que se baja es el del `ng-model`, no el del
  agrupador: el del agrupador es el que agrupa.
- **La celda no se puede animar en cuanto se teclea** si el estado depende de
  `ng-change`: aparecería un segundo tarde y el docente vería la rejilla
  reaccionar con retraso. Para que el halo salga **al instante**, el paso a
  `pendiente` tiene que colgar de un evento sin debounce —el `input` del propio
  campo— y dejar que `ng-change` siga encargándose de encolar el valor.

## §6 — La nota rápida: la fila y la columna

Son el caso más claro y el que hoy más duele, porque el docente pulsa **una vez**
y salen 45 peticiones.

El cambio es pequeño: `columnaNotaRapida` y `filaNotaRapida` ya recorren las
notas y **ya calculan el valor nuevo de cada una** (con su `backup` para el
toggle). Lo único que cambia es qué hacen con el resultado del bucle: en vez de
empujar una promesa por nota, **empujan a la misma cola del §4** y dejan que
salga un lote.

Con eso, los tres caminos de la §1 pasan por **un solo agrupador**, que es lo que
hace que las reglas de orden y de «un lote en vuelo» valgan para todos y no haya
que escribirlas tres veces.

Dos detalles que no se pierden por el camino:

- **El `backup` del toggle es del front y sigue siéndolo.** El lote manda valores
  finales; que el segundo clic devuelva la nota anterior es lógica de pantalla y
  el backend no tiene que enterarse.
- **Una fila de un alumno son varias subunidades de la misma asignatura**, o sea
  **un solo par** `(asignatura, periodo)` y **un solo recálculo**. Lo mismo la
  columna. El endpoint ya agrupa así.

---

## §7 — Lo que sí toca en el backend (poco)

**a. Nada obligatorio.** `putLote` cubre los tres casos tal como está. Es la
conclusión de la §0 y conviene no perderla entre lo que sigue.

**b. `putSubunidad` merece una mirada aparte, y no es parte de esto.** La nota
rápida de columna que *crea* la nota por defecto
([NotasController:730](../../app/Http/Controllers/NotasController.php#L730))
hace **cinco consultas por alumno** —nota, frases, ausencias, tardanzas,
uniformes— y devuelve la rejilla entera: con 45 alumnos son más de **200
consultas en una petición**. Es una petición larga, o sea ranura ocupada mucho
rato, que es lo que llena el contador. **No se toca en este plan** —hace otra
cosa que guardar— pero queda anotado como candidato con nombre y línea.

**c. ~~Falta cronometrar `putLote`.~~ MEDIDO — noche del 24, `8myvc-ad`.** Ya no
es estimado: **una columna de 45 notas es entre 3,8× y 5,9× más rápida en un
`PUT notas/lote` que en 45 `PUT notas/update`, y pasa de 717 consultas a 220.**
El detalle, la población y las condiciones están en el
[02](02-plan-rendimiento.md); aquí sólo lo que cambia este plan:

- **El 429 de la §1 deja de ser sospecha.** Medido con el limitador puesto: **la
  petición número 121 de 135 devolvió 429**. Tres columnas de 45 contra los
  120/min de `throttle:api`. En lote, esas tres columnas son **tres** peticiones.
- **Y una cabecera de este documento vence antes de tiempo.** La §2 dice que lo
  caro no es el recálculo. Es cierto de `calcular()` —1,7 ms— y **falso del
  recálculo entero**: `recalcularPorNota` son ~6 consultas por nota. De las **497
  consultas que el lote ahorra, ~264 son el camino común y ~260 el recálculo**:
  **mitad y mitad**. No cambia ninguna decisión —el lote se lleva las dos— pero
  citado como *«el recálculo no cuenta»* manda al sitio equivocado al primero que
  quiera ahorrar más.

**d. La clave única de la [fase 2](10-definitivas.md) sigue siendo lo que cierra
la carrera del §4.3.** Este plan la mitiga en un cliente; no la cierra.

---

## §8 — El orden, y qué bloquea a qué

Este plan **no depende** del de la [bitácora](18-auditoria.md) ni del de las
[llamadas del panel](02-plan-rendimiento.md), y ellos no dependen de éste. Puede
ir en paralelo. Dos cruces que sí conviene tener presentes:

- **El lote escribe bitácora, una por nota, y es idéntica a la de editar una
  nota** — hay un test que lo fija
  (`test_la_bitacora_del_lote_es_identica_a_la_de_editar_una_nota`). O sea que lo
  que el plan de auditoría le haga a una, se lo hace a la otra. **No hay
  conflicto, y ese test es lo que lo garantiza.**
- **La fase 2 de las definitivas** cierra la carrera del §4.3. El lote se puede
  publicar antes; simplemente la mitigación del front sigue siendo mitigación.

### El despliegue: aquí el front sí se puede escalonar

La regla de la [§5.b de DESPLIEGUE.md](../DESPLIEGUE.md) dice que la app de
Flutter no puede llamar a `notas/lote` hasta que esté desplegado en los
dieciséis, porque **es una sola app para todos**.

**`myvc_front` es una copia por colegio, así que ahí la regla no aplica**: se
publica en el colegio cuyo backend ya lo tiene, y sólo en ése. Es la diferencia
que hace que este trabajo pueda empezar sin esperar a la tanda completa — y una
de las pocas veces que la topología de despliegue juega a favor.

---

## §9 — Lo que este plan NO hace

- **No sube el límite de `throttle:api`.** Ver §1: con lotes deja de rozarse, y
  subirlo devolvería el problema que se está quitando.
- **No estrecha el recálculo a un alumno.** Medido y revertido, está en el
  [02](02-plan-rendimiento.md): ahorra ~0,35 ms por pulsación y ese 3× que
  parecía haber **era la caché**.
- **No convierte nada en cola ni en Job.** Un lote de 45 notas es una petición de
  décimas de segundo; encolar cambiaría el contrato de cuatro clientes por un
  problema que no existe ([02 §5](02-plan-rendimiento.md)).
- **No toca `notas` ni `notas_finales`.** Ni esquema, ni escritores nuevos: el
  lote pasa por el mismo recalculador único de la
  [fase 3](10-definitivas.md).
