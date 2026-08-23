# Lote C — La rejilla: quién escribe una definitiva y con qué candado

> Sesión `8myvc-1e`, noche del 22 al 23 de agosto de 2026. Árbol
> `.worktrees/c`, rama `fix/lote-c-rejilla-definitivas`, base
> `simonbolivar_testing_c`.
>
> Secciones asignadas del 05: **§89–92**. Están escritas con ese número desde el
> principio para que quien coordina no tenga que renumerar al fundir.

La pregunta del lote, tal como la dejó escrita
[15 §5](../15-la-noche-en-paralelo.md): **¿cuál de estas trece rutas escribe o
borra en la rejilla sin preguntar por el interruptor del periodo?**

La respuesta corta, medida y no leída: **de las trece, las seis que escriben en
la rejilla preguntan, y preguntan de verdad** — comprobado con el periodo cerrado
y la fila intacta, no leyendo que la llamada esté escrita en el código (§92.1).

Lo que salió al mirarlas una a una es otra cosa, y en dos sitios es peor:

- **La ruta que sí reescribe la rejilla de un periodo cerrado no estaba entre las
  trece** (§90). No estaba porque ya tenía un test de contrato entero, escrito
  para mirar otra cosa.
- **Dos de las trece no borran lo que dice su nombre**: mandan un alumno a la
  papelera (§89).

Y una que no es un hallazgo sino la forma en que los tres se escondieron, escrita
al final del documento: **lo que apaga una pregunta no es un detector callado
sino un renglón que dice «ya está».**

---

## §89. `boletines2/destroy` y `boletines3/destroy` no borran un boletín: borran un ALUMNO

Y son **las dos copias que la §72 no miró** — la sección que, el mismo día,
dejó escrita la lección de por qué eso pasa.

### 89.1 La operación tiene cuatro puertas y la §72 cerró dos

`Alumno::find($id)` seguido de `->delete()` está **cuatro veces** en `app/`:

| Ruta | Guard antes de esta noche | Sobre qué tabla opera |
|---|---|---|
| `DELETE api/alumnos/destroy/{id}` | `Autoriza::puedeEditarAlumnos` | `alumnos` |
| `DELETE api/editnota/destroy/{id}` | `Autoriza::puedeEditarAlumnos` (§72) | `alumnos` |
| `DELETE api/boletines2/destroy/{id}` | **nada; solo `auth.personal`** | `alumnos` |
| `DELETE api/boletines3/destroy/{id}` | **nada; solo `auth.personal`** | `alumnos` |

La [§72](../05-codigo-muerto-y-roto.md) cerró la de `editnota` y escribió al
hacerlo:

> Cerrar una de tres es lo que pasa cuando se arregla **el sitio que se está
> mirando y no la operación**.

Y se cerró sobre la población «`EditnotaController`», que es un controlador y no
una operación. Las dos de boletines son la misma copia byte a byte y siguieron
abiertas. **La sección que escribió la regla la incumplió en el mismo commit**,
lo cual es exactamente lo que la regla predice: quien cierra mira el fichero que
tiene abierto.

Que `boletines2/destroy` opera sobre `alumnos` **ya estaba medido** —está en la
`TABLA_DE_ID` del barrido, [§21.1](../05-codigo-muerto-y-roto.md), y en el
[09](../09-pendientes.md)—, y por eso este caso vale doble: es la
[§53](../05-codigo-muerto-y-roto.md) otra vez. **Medir una ruta no es haberla
juzgado.** El dato existía, se usó para que el barrido apuntara al id correcto, y
nadie preguntó si la ruta debía poder hacer eso.

### 89.2 El hueco era real, y es el mismo que midió la §72.1

`puedeEditarAlumnos` es superusuario **o** profesor con `profes_can_edit_alumnos`,
apagada en los dieciséis colegios. Medido antes de tocar nada, con un token de
profesor y la bandera apagada:

| Ruta | Antes | Alumno en la papelera |
|---|---|---|
| `alumnos/destroy` | 400 | no |
| `editnota/destroy` | 403 | no |
| `boletines2/destroy` | **200** | **sí** |
| `boletines3/destroy` | **200** | **sí** |

### 89.3 Cómo queda, y por qué no apaga ninguna pantalla

Las dos pasan a exigir `Autoriza::puedeEditarAlumnos`, que **no es un criterio
nuevo**: es el que ya decidieron sus dos hermanas. Y el único cliente que nombra
estos controladores es `myvc_front`, en `BoletinesApi.ts`, que declara
`detailed-notas` y `detailed-notas-group` — no `destroy`. `myvc_front_2` y
`myvc_flutter` no nombran `boletines2` ni `boletines3` en **ningún** fichero
(buscado sobre los 555 y 411 ficheros de cada repo, no sobre los `.ts`).

Lo fija `BoletinesBorranAlumnosTest`, con las cuatro puertas en un mismo caso y
el viaje de ida del superusuario para que se vea que se cerró la puerta y no la
casa.

**Los cuatro códigos no coinciden y se fijan tal cual**: `alumnos/destroy`
contesta 400 porque lo tiene escrito a mano en el controlador (legacy), y las
otras tres 403 porque las cerró `Autoriza::exigir`, que es código nuevo. Lo que
tienen en común es **el resultado**, que es lo que decide si un alumno está en la
papelera. Unificar el 400 cambia el contrato de una ruta que los clientes sí
llaman: **se anota, no se hace**, y no es de este lote.

### 89.4 Comprobado al revés

Quitando **solo** el guard de `boletines3` caen exactamente dos casos: el suyo del
proveedor de datos y el de las cuatro puertas. O sea que los dos caminos están
cubiertos por separado y no hay uno tapando al otro.

---

## §90. La respuesta del lote: `calcular-grupo-periodo` reescribe la rejilla de un periodo cerrado

La pregunta del lote era **cuál de las trece escribe en la rejilla sin preguntar
por el interruptor del periodo**. Ninguna de las trece. La que lo hace es una
ruta que **no está en la lista de trece porque ya tenía test** — el de la
inyección, [§63](../05-codigo-muerto-y-roto.md), que miraba otra cosa.

> **Medir una ruta no es haberla juzgado.** Es la segunda vez esta noche: en la
> §89 el dato existía en la `TABLA_DE_ID` del barrido, y aquí existe un test de
> contrato entero sobre la misma ruta.

### 90.1 Qué hace, medido

Con un token de **profesor**, los cuatro periodos del año cerrados
(`profes_pueden_editar_notas = 0` y `profes_pueden_nivelar = 0`) y el `periodo_id`
del periodo cerrado en el cuerpo:

| | |
|---|---|
| Respuesta | **200 `Calculado`** |
| Definitivas del grupo y periodo antes | 463 |
| Definitivas después | 463 |
| **Filas que sobrevivieron** | **0** |

Las 463 se borran y se vuelven a insertar: el conteo no se mueve y **los ids
cambian todos** (el máximo pasó de 7.228.862 a 7.256.524). Contar filas habría
dicho que no pasó nada, que es justo mirar el estado en vez del resultado. Y cada
fila nueva lleva `updated_by` del profesor que disparó el botón, así que además
reescribe la respuesta de «[quién cambió esta definitiva](../05-codigo-muerto-y-roto.md)»
(§73) para 463 notas de golpe.

Lo que **sí** respeta: el `DELETE` filtra `(manual is null or manual=0) and
(recuperada is null or recuperada=0)`, así que lo puesto a mano sobrevive — **al
revés que la §71**, que tenía ese mismo criterio invertido. Medido sobre el grupo
con 40 manuales y 3 recuperadas: las 43 siguen ahí.

### 90.2 Es la única de las ocho de su controlador que no pregunta

| Rutas de `DefinitivasPeriodosController` | ¿Pregunta? |
|---|---|
| `update`, `update-recuperacion`, `toggle-manual`, `toggle-recuperada` | sí |
| `eliminar-recuperada`, `destroy/{id}`, `arreglar-duplicados` | sí |
| **`calcular-grupo-periodo`** | **no** |

Y el interruptor **está puesto y funciona**: con el mismo token y el mismo
periodo cerrado, `definitivas_periodos/update` sobre una definitiva de ese
periodo contesta **400** y no la cambia. O sea que no es que el candado esté roto:
es que hay una puerta que no lo consulta, y es la que escribe más filas de una vez
que ninguna de las que sí lo consultan.

### 90.3 Cómo se escondió, que es la parte que se repite

No fue un detector ciego. `tools/escrituras-en-las-notas.py` la lista como
**«NO pregunta» desde que existe**. Lo que falló es la tabla que convierte esa
lista en veredictos, la de la [§77.2](../05-codigo-muerto-y-roto.md):

| Método | Ya estaba (según la §77.2) |
|---|---|
| `DefinitivasPeriodosController::putCalcularGrupoPeriodo` | «§71, cortada con 410» |

**La cortada con 410 es la vecina.** La §71 cortó
`putCalcularNotasFinalesAsignatura`, que es la línea siguiente del mismo fichero
de rutas:

```
academico.php:124  PUT definitivas_periodos/calcular-grupo-periodo              <-- viva, escribe
academico.php:125  PUT definitivas_periodos/calcular-notas-finales-asignatura   <-- 410 desde la §71
```

Mismo controlador, mismo `auth.personal`, nombres que empiezan igual y una línea
de distancia. La §77.2 leyó cuatro métodos «uno a uno —que es lo único que
convierte una lista en un veredicto—» y en uno de los cuatro el veredicto se le
atribuyó al de al lado.

> **Un detector que acierta no basta si el veredicto se escribe en una tabla a
> mano.** La lista estuvo bien las dos veces; lo que se equivocó es el renglón
> que decía que ya estaba resuelta. Y un renglón que dice «ya está» es más caro
> que un falso negativo del detector, porque **apaga la pregunta**.

Por eso el último caso del test golpea **las dos rutas en la misma petición** y
compara los dos códigos en un solo `assertSame`: `200` y `410`, uno al lado del
otro. Dos números en la misma línea no se confunden; una fila de una tabla, sí.

### 90.4 Qué se ha hecho y qué no

**Hecho**: `CalcularGrupoPeriodoTest`, cuatro casos, que fijan lo que hay hoy
—200, cero supervivientes, las manuales a salvo, la hermana en 400 y la vecina en
410— con el porqué al lado de cada valor. Un test que fija lo que hay fija también
lo que está mal: el día que se cierre, caen dos de los cuatro y ahí está escrito
qué se decidió.

**No hecho, y no por falta de tiempo**: ponerle el candado. Ver `## PARA JOSETH`.

### 90.5 Y una vecina más, anotada al pasar

`GET api/definitivas_periodos/arreglar-duplicados` es un **GET que hace `DELETE
FROM notas_finales`**, y su ruta **no lleva `auth.personal`**: va con el guard por
defecto, `auth.token`. Lo único que la cierra es
`User::pueden_modificar_definitivas` dentro del controlador, que aborta 400 para
quien no sea profesor o superusuario — o sea que hoy **no** hay hueco. Se anota
porque la protección está en el sitio frágil: el día que alguien mueva esa
llamada de sitio, la ruta queda abierta a cualquier token, alumnos incluidos, y
borra filas de `notas_finales`. Es de mi lote y no lo toco porque cambiar el guard
de una ruta que hoy no tiene hueco es ruido en una noche de seis sesiones; queda
dicho aquí.

---

## §91. El libro rojo: la única escritura de la tabla, y no mira de quién es la fila

`PUT api/nota_comportamiento/guardar-libro` recibe `{campo, valor, libro_id}` y
hace `UPDATE dis_libro_rojo SET <campo>=:valor WHERE id=:libro_id`.
`dis_libro_rojo` es el observador disciplinario: doce columnas de texto, **tres
por periodo** (`per1_col1` … `per4_col3`), una fila por alumno y año.

El **nombre de columna** está a salvo desde la §31 —lo valida `ColumnaSegura`
contra el esquema y lo fija `ColumnaConcatenadaTest`—. Lo que no mira nadie es
**`libro_id`**.

### 91.1 Lo medido

Con un token de **profesor** y un alumno que **no está en ningún grupo suyo**
—pedido al revés, exigiendo que ninguno de sus grupos tenga asignatura de ese
profesor, porque un `!=` aquí devuelve el otro grupo del mismo alumno y eso ya ha
costado cuatro veces lo mismo—:

| Caso | Respuesta | Efecto |
|---|---|---|
| Escribir el libro rojo de un alumno ajeno | **200 `Cambiado`** | **escrito** |
| Con un `libro_id` que no existe | **200 `Cambiado`** | nada |
| Con el periodo 1 cerrado, escribiendo `per1_col1` | **200** | **escrito** |

El segundo es de la familia de la [§74](../05-codigo-muerto-y-roto.md): el método
devuelve la cadena fija pase lo que pase y **`DB::update` sí devuelve el número de
filas, que se tira**. Importa más de lo normal porque la pantalla guarda campo a
campo mientras se escribe: un `libro_id` viejo en el navegador deja un observador
que parece guardado y no lo está. Y el dato está a mano: su vecina de la misma
forma, `years/toggle-cambiar-valor`, hace `$res = DB::update(...)` y contesta
`Guardado` o `No guardado`.

### 91.2 Por qué el detector no lo señalaba, y las otras cuatro que arrastra

`tools/identificadores-del-cuerpo.py` da esta ruta como **«prop = sí»**. Su señal
de propiedad es la raíz `exig`, ensanchada **a propósito** para cazar los helpers
privados que el repo conjuga de dos maneras (`exigirQue…` y `exigeQue…`), y en
este método el único `exig` es **`ColumnaSegura::exigir`**, que valida un nombre
de columna y no comprueba propiedad de nada.

Medido quitando esa llamada del texto y volviendo a pasar la misma regex: se
cuelan **cinco rutas, las cinco de escritura**.

| Ruta | Identificador que nadie mira | Lote |
|---|---|---|
| `PUT api/ordinales/guardar-valor` | `ordinal_id` | B |
| `PUT api/ordinales/guardar-valor-config` | `config_id` | B |
| `PUT api/years/toggle-cambiar-valor` | `year_id` | D |
| `PUT api/asignaturas/toggle-dia` | `asignatura_id` | fuera de los 77 |
| `PUT api/nota_comportamiento/guardar-libro` | `libro_id` | **C** |

> **Ensanchar una señal para no perder verdaderos positivos mete falsos negativos
> por el otro lado, y los falsos negativos de un detector de propiedad no se ven
> nunca: la ruta sale de la lista y ya está.**

Es la cara contraria de la trampa que la propia cabecera del script advierte —«el
detector también se queda ciego ante un nombre nuevo»—: aquí no se queda ciego
ante un nombre nuevo, **se traga uno que se parece**. Y es la tercera vez esta
noche que lo que apaga la pregunta no es un detector callado sino **un renglón que
dice «ya está»**: la §89 lo tenía en la `TABLA_DE_ID`, la §90 en la tabla de la
§77.2, y ésta en una columna calculada.

`asignaturas/toggle-dia` merece una línea aparte porque tiene **dos** renglones a
la vez: la columna `prop = sí` y un test de contrato verde. Ese test preguntó qué
código devuelve con un nombre de columna con SQL dentro, y nunca de quién es la
fila. Un test verde tampoco es un veredicto sobre lo que no preguntó.

El arreglo del script no se hace aquí: `tools/` no es de ningún lote y cambiar la
salida mientras B, D y H puedan estar corriéndolo la mueve debajo de ellas. Va al
lote H, que es el dueño de esa herramienta esta noche.

### 91.3 Qué se ha hecho y qué no

**Hecho**: `LibroRojoTest`, cuatro casos. Los tres primeros fijan lo de arriba; el
cuarto lee del código —descartando comentarios, que es el arreglo que la §72.5 le
hizo a su propio detector— **quién más escribe en `dis_libro_rojo`**, y hoy no
escribe nadie más: los otros dos controladores que la nombran sólo crean la fila
vacía o la leen.

Ese cuarto caso hacía falta porque **no hay hermana de la que copiar el guard**.
Lo que resolvió la §89 en una línea fue que el criterio ya estaba elegido por tres
rutas iguales; aquí la operación tiene **una sola puerta** y no hay criterio
elegido. Y si mañana aparece una segunda, esta sección se queda corta sin que
falle nada — que es exactamente cómo la §72 se cerró sobre tres de cuatro.

> Y una que conviene contar: ese mismo caso nació con `preg_match` en vez de
> `preg_match_all`, o sea **quedándose con la primera escritura de cada fichero**.
> El propio `NotaComportamientoController` tiene un `INSERT` y el `UPDATE` de esta
> sección, así que el detector escrito para que no se escondiera una segunda
> puerta escondía una a dos líneas de distancia. Falló al primer intento y por eso
> se vio; si el `INSERT` hubiera estado en otro fichero, habría pasado verde.

**No hecho, y las dos razones son distintas**:

- **No se cierra el `libro_id`.** Quién puede escribir el libro rojo de quién es
  una decisión del colegio, de la misma familia que las 44 rutas de escritura de
  configuración con sólo `auth.personal` que Joseth decidió no cerrar todavía
  porque cerrarlas dejaría fuera a un coordinador que hace ese trabajo sin tener
  el rol. Aquí igual: **el coordinador de convivencia no es el titular del
  grupo.**
- **No se mete bajo el candado del periodo.** El interruptor se llama
  `profes_pueden_editar_notas`, y lo que Joseth decidió el 21 ago 2026 al meter
  `nota_comportamiento` dentro fue que **la nota de comportamiento sale en el
  boletín** ([05 §40.2](../05-codigo-muerto-y-roto.md)). El libro rojo **no sale
  en el boletín**: ningún controlador de `Informes/` lo nombra. Meterlo ahí sería
  ampliar lo que el interruptor significa, y eso no se decide de noche.


---

## §92. Las trece, una a una — el candado estaba puesto; el otro no

### 92.1 Las seis que escriben preguntan, y preguntan de verdad

| Ruta | ¿De dónde saca el periodo? |
|---|---|
| `DELETE definitivas_periodos/destroy/{id}` | `notas_finales.periodo_id` |
| `PUT definitivas_periodos/toggle-manual` | ídem |
| `PUT definitivas_periodos/toggle-recuperada` | ídem |
| `PUT definitivas_periodos/eliminar-recuperada` | **el año entero** |
| `DELETE notas/destroy/{id}` | nota → subunidad → unidad |
| `POST subunidades` | la unidad de la que va a colgar |

`RejillaLasQueFaltabanTest` no comprueba que la llamada esté escrita en el código
—eso se lee— sino **el resultado**: con el periodo cerrado, 400 **y la fila
intacta**. Las seis pasan.

Dos casos merecen su párrafo porque miden lo que nadie había golpeado:

- **`eliminar-recuperada` se cierra por el AÑO y no por un periodo**, y el caso lo
  mide con el año **abierto menos uno**. Con todo cerrado los dos criterios darían
  el mismo resultado y el test no distinguiría nada. Fija justo la otra cara de la
  decisión de Joseth del 21 ago 2026: **un solo periodo cerrado basta** para que no
  se pueda tocar la recuperación final.
- **`notas/destroy` es un `DELETE` físico**, no la papelera. Su gemela
  `notas/update` ya estaba fijada; la destructiva no.

### 92.2 Lo que sí salió: el porcentaje que se pisa

`SubunidadesController::putUpdate` asignaba `definicion`, `porcentaje` y
`nota_default` con `Request::input()` **sin defecto**. Medido: un `PUT` con sólo
`{definicion}` responde 200 y deja `porcentaje` en **null** (50 → null).

Es la [§68](../05-codigo-muerto-y-roto.md) —«un campo que no se manda no es un
campo que no cambia»— caída donde más pesa: `porcentaje` no describe nada, es lo
que pondera el componente dentro de la unidad, `(u.porcentaje/100) *
((s.porcentaje/100) * n.nota)`. **Un cuerpo parcial no borra un dato: borra un
peso, y la nota que sale al boletín cambia.** Y no espera: el mismo método
recalcula las definitivas de la asignatura veinte líneas más abajo.

**Arreglado** con el defecto de la fila, que es el criterio que ya eligió la §68.
No hace falta `CamposQueVinieron`: eso es para cuando el controlador hace
`Request::merge()` antes de leer y `has()` deja de distinguir, y aquí no lo hace
nadie —comprobado en el controlador y en los middlewares—. `nota_default` conserva
su recorte a 0 letra por letra. Ningún cliente cambia: `UnidadesCtrl.ts:651` manda
las cuatro columnas siempre.

`PorcentajeQueSePisaTest` lleva **dos casos al revés**, y el segundo lo señaló el
lote D al revertir a la solución equivocada que parecía buena y ver que **no caía
nada**:

| Cuerpo | `input('x', $def)` — el arreglo | `input('x') ?? $def` — el que parece igual |
|---|---|---|
| sin la clave | `$def` | `$def` |
| `0` | `0` | `0` |
| **`null`** | **`null`** | **`$def`** |

- **Mandar un `0`** sigue poniendo 0. Caza un `?:` o un `empty()`, pero **no
  distingue las dos columnas de arriba**, porque `0 ?? 50` es `0`.
- **Mandar `null` a propósito** sí borra. Es el único cuerpo que las separa:
  `Request::input($clave, $defecto)` devuelve el defecto **sólo si la clave no
  viene** —por dentro es `Arr::get`, que pregunta por `array_key_exists`—,
  mientras que `??` mira el valor.

Medido al revés, cambiando el arreglo por el del `??`: **cae exactamente ese
caso y ningún otro**. O sea que sin él el test habría dado por bueno un arreglo
distinto del que hay.

Y lo que fija no es un detalle técnico, es la decisión: **no mandar un campo y
mandarlo vacío no son la misma petición.** Lo primero es un cliente que sólo
manda lo que cambió; lo segundo es un cliente diciendo «quítalo». Tratarlos igual
convierte el arreglo de la §68 en un campo que **ya no se puede vaciar nunca** —
un fallo nuevo con mejor pinta que el viejo.

**Con una condición**, que la sesión del lote E encontró al aplicarla: eso vale
salvo donde un `merge()` previo ya los ha igualado. En `ProfesoresController`,
`sanarInputProfesor()` hace `merge(['ciudad_nac' => null])` cuando la clave falta,
así que el campo ausente y el `null` explícito llegan **idénticos** al
controlador, y ahí lo que hay que arreglar es el `merge()`, no el defecto.

#### Y su vecino, que parecía igual y no lo es

`NotaComportamientoController::putUpdate` asigna también tres columnas del cuerpo,
pero **cada una dentro de su `if (Request::has(...))`**. Medido: con `{nota: 90}`,
`familiar_nota` y `familiar_ausencias` siguen donde estaban. **No hay nada que
arreglar ahí.**

Los dos salieron de la misma lista, hecha con un barrido que cuenta
`$obj->col = Request::input('col')`. La asignación es idéntica en los dos sitios;
**lo que los separa es la línea de antes**. Esa lista tiene además la ceguera
contraria —no ve los `DB::update` crudos, y hay 990 consultas crudas en el
proyecto—, así que arrastra falsos positivos y falsos negativos a la vez:

> **El tamaño de la lista no dice nada sobre el tamaño del problema.** Es lo que
> hay que escribir al lado de cualquier «salieron N sitios».

**Y el gemelo que no se toca**: `UnidadesController::putUpdate:244` tiene la misma
forma exacta y `unidades.porcentaje` es el factor **de fuera** del mismo producto,
así que perderlo se lleva por delante todas las subunidades de esa unidad de
golpe. Es de otro lote y va anotado, no tocado.

### 92.3 Las cuatro que sólo leen, y a quién le contestan

Ninguna se cierra, y **no por falta de criterio sino porque el criterio ya está
decidido y es que no**: «hoy un profesor alcanza todo lo que alcanza un
administrativo, y **es lo que Joseth dejó fuera a propósito**»
([08](../08-revision-idor.md)). Las cuatro llevan `auth.personal`, así que no
alcanzan ni a un alumno ni a un acudiente: lo que miden es personal contra
personal, que es exactamente el punto aplazado. Se fija lo que contestan.

- **`GET notas/show/{nota_id}`** devuelve cualquier nota por su id, sin mirar de
  quién es ni de qué asignatura. Y con una nota en la papelera contesta **200 con
  el cuerpo vacío**, no 404 — `Nota::find()` respeta el borrado lógico y devuelve
  `null`. Se fija sin juzgarlo: el 404 sería lo correcto por el criterio de
  códigos, pero es cambio de contrato de una ruta que un cliente lee.
- **`PUT puestos/detailed-notas-year`** es la que más enseña, y **eso sólo se ve
  en el snapshot de la forma**. Por cada alumno del grupo que se pida devuelve,
  además de las definitivas: `documento`, `direccion`, `celular`, `fecha_nac`,
  `religion`, `nee`, `no_matricula`, `username` y `user_id`. Es un informe de
  **puestos** —un orden de mérito— y viaja con la ficha personal entera de cada
  alumno dentro.

  Encogerlo es contrato con dieciséis copias del front, así que **se mide y se
  anota**, igual que la planilla de la [§75.6](../05-codigo-muerto-y-roto.md). Lo
  que aporta esta noche es el número: no es que «devuelva de más», es que la
  respuesta de un ranking lleva el número de documento y la dirección de cada
  menor. Va a la lista de Joseth.
- **`PUT subunidades/eliminadas/{asignatura_id}`** y **`PUT
  nota_comportamiento/frases-check`** devuelven catálogo del colegio, sin datos
  de ninguna persona dentro — que es el criterio con el que el 08 separa una fuga
  de un catálogo. Fijadas por su forma.

> El caso de `subunidades/eliminadas` nació **hueco**: pedía la primera asignatura
> del seed y la respuesta salía `[]`, así que el snapshot fijaba una lista vacía y
> habría pasado igual el día que la consulta dejara de traer columnas. La consulta
> filtra por el periodo **del usuario**, no por la asignatura. Se vio mirando el
> `.json` generado, no leyendo el test — que es la misma forma en la que se
> descubrió la trampa de `formaDeLaTupla` en la clase base.


---

## La respuesta del lote, en una tabla

Las trece rutas que el [15](../15-la-noche-en-paralelo.md) me asignó, con lo que
contesta cada una hoy y por qué es eso:

| Ruta | Escribe en la rejilla | Pregunta por el candado | Qué salió |
|---|---|---|---|
| `DELETE definitivas_periodos/destroy/{id}` | sí | **sí**, por la fila | fijado |
| `PUT definitivas_periodos/toggle-manual` | sí | **sí**, por la fila | fijado |
| `PUT definitivas_periodos/toggle-recuperada` | sí | **sí**, por la fila | fijado |
| `PUT definitivas_periodos/eliminar-recuperada` | sí | **sí**, por el año entero | fijada la otra cara: un periodo cerrado basta |
| `DELETE notas/destroy/{id}` | sí | **sí** | fijado; es un `DELETE` físico |
| `POST subunidades` | sí | **sí**, por la unidad | fijado |
| `PUT nota_comportamiento/guardar-libro` | escribe en `dis_libro_rojo` | **no** | **§91** — y no mira de quién es la fila |
| `GET notas/show/{nota_id}` | no | — | fijado: devuelve cualquier nota; 200 vacío si está borrada |
| `PUT nota_comportamiento/frases-check` | no | — | fijado por su forma; catálogo |
| `PUT puestos/detailed-notas-year` | no | — | **§92.3** — el ranking viaja con la ficha personal |
| `PUT subunidades/eliminadas/{asignatura_id}` | no | — | fijado por su forma; catálogo |
| `DELETE boletines2/destroy/{id}` | no toca la rejilla | — | **§89** — manda un alumno a la papelera. **Cerrado** |
| `DELETE boletines3/destroy/{id}` | ídem | — | **§89**. **Cerrado** |

Y dos que **no estaban entre las trece** y son lo que de verdad contesta la
pregunta del lote:

| Ruta | Por qué no estaba | Qué salió |
|---|---|---|
| `PUT definitivas_periodos/calcular-grupo-periodo` | ya tenía test de contrato — el de la inyección, que miraba otra cosa | **§90** — reescribe la rejilla de un periodo cerrado |
| `PUT subunidades/update/{id}` | está fuera de los 77 huecos | **§92.2** — un cuerpo parcial le borraba el porcentaje. **Arreglado** |

### Lo que se lleva la noche de aquí

Tres veces, en tres formas distintas, **lo que apagó la pregunta no fue un
detector callado sino un renglón que decía «ya está»**:

| Sección | Dónde estaba el renglón |
|---|---|
| §89 | la `TABLA_DE_ID` del barrido: `boletines2/destroy` estaba medida **con otro propósito** |
| §90 | la tabla de la §77.2, que le atribuyó a una ruta el 410 de su vecina |
| §91 | una columna calculada: `prop = sí` porque el método valida un nombre de columna |

Un falso negativo de un detector deja un sitio sin mirar. **Un «ya está» deja a
todo el mundo convencido de que se miró**, y eso sale más caro: nadie vuelve.

Y la otra, que vale para los cuatro detectores de `tools/` y no sólo para el que
la produjo: cuando un instrumento tiene una ceguera que mete falsos negativos y
otra que mete falsos positivos —éste no ve el SQL crudo y no ve el `has()`—,

> **el tamaño de la lista no dice nada sobre el tamaño del problema.**


---

## Lo que se nota en un colegio (para DESPLIEGUE.md)

Tres cambios de comportamiento, ninguna migración. Lo escribe quien coordina en
`docs/DESPLIEGUE.md`; esto es el texto listo para copiar.

| Cambio | Antes | Después | Quién lo nota |
|---|---|---|---|
| §89 `DELETE boletines2/destroy/{id}` y `boletines3/destroy/{id}` | cualquiera de los 51 profesores mandaba un **alumno** a la papelera, 200 | **403** | nadie: ninguna pantalla llama a esas dos rutas |
| §92.2 `PUT subunidades/update/{id}` | un cuerpo sin `porcentaje` lo dejaba en `null` y recalculaba las definitivas con el peso perdido | conserva el valor de la fila | nadie: `UnidadesCtrl.ts:651` manda las cuatro columnas siempre |
| — | — | — | — |

**Ninguna migración**, así que el orden dentro de la tanda es libre y las bases de
test de las demás sesiones no se quedan viejas por esto.

**Y una advertencia para el día del despliegue**, que no es un cambio pero se
nota igual: §90 deja escrito que `definitivas_periodos/calcular-grupo-periodo`
**sigue reescribiendo la rejilla de un periodo cerrado**. No se ha tocado. Si
Joseth decide cerrarla, ese cambio **sí** apaga algo —abrir el boletín de un
grupo desactualizado en periodo cerrado— y entonces hay que desplegarlo mirando
el calendario del colegio, no en cualquier momento.


---

## Apéndice: la mentira de instrumento de esta noche, y la fabriqué yo

No es del lote, pero es de la familia que este repo colecciona, así que se cuenta
donde se cuentan las demás.

Corriendo la suite de Contrato en mi árbol salieron **cinco rojos en familias que
no había tocado** —`AceptarCambiosTest`, `ActividadesTest`, `AcudientesTest`,
`ConcederSuperusuarioTest`, `ConfigCertificadosTest`—, uno de ellos tardando
**53,48 s** en un caso que hace tres consultas. Tres hipótesis razonables y las
tres falsas: que fueran mías, que la base heredada de la noche anterior tuviera
datos dentro, y que fuera contención con las otras sesiones.

El mensaje real, sacado corriendo esas cinco clases solas:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock
(Connection: mysql_testing, Database: simonbolivar_testing_c,
 SQL: insert into `personal_access_tokens` (...))
   at tests/Contrato/CasoDeContrato.php:314    ← tokenDe(), el login de cada test
```

Un deadlock necesita **dos** transacciones, y yo era la única sesión en `_c`. Las
otras dos eran mías:

```
84365 cwd=/app/.worktrees/c ppid=1   21 min corriendo
84987 cwd=/app/.worktrees/c ppid=1   14 min corriendo
```

**`php artisan test` es un envoltorio que lanza `vendor/phpunit/phpunit` como
hijo.** Matar el envoltorio —da igual con qué— **no mata al hijo**: queda
reparentado a init y sigue corriendo la suite entera contra la misma base,
haciendo login en cada test. Yo había parado dos tandas y tenía tres.

Tres cosas que se llevan de aquí:

1. **Una base por sesión no protege de la sesión.** El aislamiento del
   [15 §3](../15-la-noche-en-paralelo.md) supone una tanda por base y no hay nada
   que lo garantice. El punto caliente no está en las tablas del dominio: está en
   `personal_access_tokens`, porque **cada test hace login**. Por eso dos tandas
   contra la misma base chocan **corran los tests que corran**, sin compartir ni
   una tabla de negocio.
2. **Un huérfano no da la cara como huérfano.** Da la cara como un test de
   contrato en rojo, en una familia que no has tocado y en un sitio creíble. Es
   la forma de siempre: el instrumento miente con la cara del problema.
3. **El envoltorio no distingue las sesiones y el `cwd` no aguanta.** Los seis
   envoltorios dicen `artisan test --testsuite=Contrato`, así que hay que mirar
   al hijo; y **el `cwd` del hijo cambia**: uno de los huérfanos ya no estaba en
   su worktree sino en `/tmp/contrato-subidas-<pid>-…`, porque algún test se mete
   en un directorio temporal. Buscando por `cwd` habría parecido un proceso de
   nadie — la trampa de siempre, un rato después.

   Lo que sí aguanta es el `--configuration=` del cmdline del hijo, y para saber
   contra qué base escribe, `DB_TEST_DATABASE` en `/proc/<pid>/environ`:

   ```bash
   docker exec 8myvc-app-1 sh -c 'for p in $(pgrep -f phpunit); do
       echo "$p ppid=$(awk "{print \$4}" /proc/$p/stat)"
       tr "\0" "\n" < /proc/$p/environ | grep -E "^DB_TEST_DATABASE="
       tr "\0" " "  < /proc/$p/cmdline | grep -o -- "--configuration=[^ ]*"
   done'
   ```

   **`ppid=1` es el discriminador bueno**: nadie lee su salida y sigue
   escribiendo. Y para parar lo propio de verdad hay que matar al hijo, no al
   envoltorio.

Lo que lo convierte en apéndice y no en anécdota es cómo empezó: **con un
`pkill -f "artisan test"` mío dentro del contenedor compartido**. El árbol y la
base son por sesión; el contenedor no. Ese `pkill` mató envoltorios ajenos y dejó
huérfanos vivos en los árboles de otros dos lotes, que a partir de ahí midieron
con dos tandas por base sin saberlo.


---

## Apéndice 2: las tres afirmaciones sobre los clientes, repetidas contra las 23 ramas

Escrito **después** de cerrar el lote, al aplicar hacia atrás lo que salió en el
[lote G](g.md) §106.3: los greps de clientes de este documento se hicieron sobre
`myvc_front` **en `main`**, y ese repositorio tiene **veintidós ramas más**
—`fase-11/*`, una por worktree— con la migración del front dentro.

Tres afirmaciones de este documento se apoyan en un cero de esos greps, y **un
cero sin control no es una medición**. Repetidas contra las 23 ramas, con control
delante:

| Afirmación | Dónde | Control | Resultado |
|---|---|---|---|
| «ningún cliente llama a `boletines2/destroy` ni a `boletines3/destroy`» | §89.3 | `detailed-notas`: 13 ficheros en `main`, **24–26 por rama** | **0 en las 23**. El único `destroy` de un fichero de boletines, en todas, es `$scope.$on('$destroy', …)` — el gancho de Angular, no la ruta |
| «`calcularGrupoPeriodo` se llama en tres sitios, y el tercero decide» | §90, PARA JOSETH | — | **idéntico en las 23**: 6 apariciones en `InformesCtrl.ts` y el mismo `:499` dentro de `verBoletinesGrupo` |
| «`UnidadesCtrl.ts:651` manda las cuatro columnas siempre» | §92.2 | — | **las 23 mandan `porcentaje:`** en `actualizarSubunidad` |

Las tres **aguantan**. Lo que cambia no es la conclusión: es que antes eran ciertas
sobre una muestra y ahora lo son sobre el corpus.

> Y el control es lo que lo hace valer: las ramas dan **más** apariciones que
> `main` —24–26 frente a 13—, así que el grep alcanzaba de sobra y el cero de
> `boletines2/destroy` es un cero medido. Sin esa comprobación, un cero en las
> 22 ramas se habría podido explicar igual de bien por un `git grep` que no
> llegaba.

Se anota aquí y no se toca nada más: **el resultado del lote no cambia**, y lo
que se lleva es el método — *un grep de clientes vale lo que valen los ficheros
que mira, y en un repositorio a mitad de migración «los ficheros» no son los del
directorio: son los de todas sus ramas.*

---

## PARA JOSETH

### 1. ¿Se le pone el candado del periodo a «Calcular definitivas per N»? (§90)

**La pregunta**: hoy un profesor recalcula —borra y reescribe— las definitivas
automáticas de un periodo que el colegio ha cerrado. Ponerle
`pueden_modificar_definitivas` lo cierra en una línea. **No se ha hecho porque
apaga algo**, y hay que decidirlo mirando qué apaga.

**Qué apaga, medido en el front**: `myvc_front` llama a esta ruta en **tres**
sitios, y sólo dos son el botón.

| Dónde | Qué pasaría con un 400 |
|---|---|
| `informes.html:13` → `InformesCtrl.ts:410` | el botón «Calcular per N» sale con un `toastr.error`. Es lo que se querría |
| `InformesCtrl.ts:451` | el bucle que calcula todos los grupos de un periodo: `toastr.warning` y sigue con el siguiente |
| **`InformesCtrl.ts:499`, dentro de `verBoletinesGrupo`** | **el profesor no puede abrir los boletines de ese grupo** |

El tercero es el que decide. `verBoletinesGrupo` sólo llama al cálculo cuando el
periodo está en `periodos_desactualizados` **y** el grupo está dentro; y si el
cálculo falla, muestra un aviso y **no llama a `verBoletinesGrupoCargar`**. O sea
que con el periodo cerrado y las definitivas marcadas como desactualizadas, un
profesor se quedaría sin poder abrir el boletín del grupo.

**Hay precedente y no es el mismo caso**: en [05 §47.2](../05-codigo-muerto-y-roto.md)
decidiste, para `unidades/de-asignatura-periodo`, que con el periodo cerrado la
pantalla **enseñe lo que hay y no cree nada** — para eso existe
`User::permiteEditarNotas`, que contesta en vez de abortar. Aquí valdría lo mismo:
no recalcular y dejar que el boletín se abra con las definitivas que ya hay. La
diferencia con aquél es que aquél es una lectura que de paso escribe, y éste es un
botón que escribe y que además una pantalla dispara sola.

Las tres formas, para que la decisión sea entre cosas y no entre palabras:

1. **Abortar 400** como sus siete hermanas. Cierra del todo; deja a un profesor sin
   abrir el boletín de un grupo desactualizado en periodo cerrado.
2. **No recalcular y contestar 200** (la forma de la §47.2). El boletín se abre con
   lo que hay. Contrapartida: contesta `Calculado` sin haber calculado, que es la
   familia de «[respuestas que mienten](../05-codigo-muerto-y-roto.md)» (§74).
3. **No recalcular y decirlo** — 200 con otro cuerpo, o 409. Lo más honesto y lo
   único que **cambia el contrato** de una ruta que el front sí llama.

No decido ninguna. Queda fijado lo que hay con `CalcularGrupoPeriodoTest`.

### 2. ¿Quién puede escribir el libro rojo de un alumno? (§91)

Hoy **cualquiera de los 51 profesores** escribe el observador disciplinario de
cualquier alumno del colegio, y con el periodo cerrado. Medido, no supuesto.

No se cierra porque **no hay criterio del que copiar**: `guardar-libro` es la
única escritura de `dis_libro_rojo` en toda la API. Las dos preguntas son:

1. ¿Debe poder escribirlo alguien que no sea el titular del grupo? (El
   coordinador de convivencia no lo es, y por eso esto no es un `persona.propia`
   automático.)
2. ¿Debe cerrarse con el periodo? Sus columnas son por periodo, pero el libro
   rojo **no sale en el boletín** — que fue el motivo por el que la nota de
   comportamiento sí entró bajo el candado (05 §40.2).

Y una que no necesita decisión, sólo trabajo: contesta `Cambiado` con un
`libro_id` que no existe. Su vecina de la misma forma
(`years/toggle-cambiar-valor`) sí mira las filas afectadas y contesta `Guardado` o
`No guardado`.

### 3. El informe de puestos viaja con la ficha personal de cada alumno (§92.3)

`PUT puestos/detailed-notas-year` devuelve, por cada alumno del grupo que se pida,
`documento`, `direccion`, `celular`, `fecha_nac`, `religion`, `nee`,
`no_matricula`, `username` y `user_id`, además de las definitivas. Es un orden de
mérito, no una ficha.

No se encoge porque **encoger una respuesta es contrato con dieciséis copias del
front** (la misma razón que la §75.6). La pregunta es si alguna pantalla lee
alguno de esos campos de este endpoint; si no, es un recorte y no un cambio.
Queda fijado en `Snapshots/puestos-detailed-notas-year.json`, así que el recorte
se verá en un diff el día que se haga.

### 4. Lo que hay que corregir en el 05, y no es mío (§90.3)

La fila de `putCalcularGrupoPeriodo` en la tabla de la **§77.2** dice «§71, cortada
con 410» y eso es falso: la cortada es su vecina,
`putCalcularNotasFinalesAsignatura`. Como esa tabla es la que convierte los cuatro
«NO pregunta» del detector en veredictos, **la fila fabrica un veredicto falso y lo
deja escrito**. Lo lleva quien coordina al fundir el 05; queda medido aquí y con
test.
