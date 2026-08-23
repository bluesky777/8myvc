# Qué se nota en un colegio — la tanda de la noche del 22 al 23 de agosto

> Montado por `8myvc-2f` a petición de quien coordina, **para copiar a
> `docs/DESPLIEGUE.md`**. No sustituye a ese documento: es la parte que hay que
> tener delante para decidir **el orden de la tanda** y **qué avisar antes**.
>
> **Cada fila sale de un documento de lote o de un `git diff` contra `main`.**
> Lo que no encontré escrito está marcado como hueco con el nombre del lote — no
> está deducido. **Una tabla de despliegue con una fila inventada es peor que una
> fila que falta**, y aquí lo que se despliega a partir de ella son dieciséis
> colegios.

## Lo primero, porque cambia el orden entero de la tanda

| | |
|---|---|
| **Migraciones nuevas** | **ninguna** — comprobado con `git diff c2c2a04 main -- database/migrations/ database/schema/`, que sale vacío |
| **Rutas** | **539 antes y 539 después**. Ninguna nace, ninguna muere — y no es una suposición: **ningún commit de la noche tocó `routes/`** (`git log c2c2a04..main -- routes/` sale vacío) y el recuento de `Route::` en `routes/api/` da **538 en los dos extremos**, más el `GET /` de `web.php` |
| **Formas de respuesta** | ninguna respuesta pierde ni gana claves — **comprobado contra los snapshots**, ver la nota de abajo |
| **Capacidades que se quitan** | **cuatro**, todas del lote E — y las cuatro con **riesgo bajo**, por la misma razón |
| **Cosas que se encienden** | **una** hoy, y **dos con R dentro** — ver la nota de abajo |
| **Minas que no se notan al desplegar** | **seis**, en **tres detonantes**, y las seis esperan a que alguien haga lo razonable (§4.b) |

> **La tanda es casi toda «deja de pasar».** De los **dieciséis lotes fundidos**,
> **siete no tocan `app/` ni `routes/`** —F, G, H, I, J, M y O— y los **nueve** que
> sí —A, B, C, D, E, K, L, P, S— cambian, casi siempre, un 500 o un guardado
> silencioso por un código honesto. Eso significa que **el orden dentro de la
> tanda es libre**: no hay dependencia entre lotes, ni orden obligatorio como el
> de `password_reminders` de la tanda anterior.
>
> **Con R dentro sigue siendo libre, pero deja de ser indiferente.** R es la única
> cuyo despliegue **devuelve algo que hoy falta** —una familia que no puede ver el
> boletín de su hijo en las maquetas 2 y 3—, así que es la primera que un colegio
> agradecería. **Eso es una recomendación de orden, no una dependencia.**

---

### Las formas de respuesta, comprobadas y no supuestas

De los **seis** snapshots de contrato que cambiaron esta noche, **cinco son
nuevos** —tests que antes no existían— y **uno ya existía**: `grupos-show.json`,
donde `titular` pasa de `"null"` a un objeto entero.

**Y ése no es un cambio de contrato: es un cambio de fixture.** `GruposController::getShow`
**no se tocó** en toda la noche (`git diff c2c2a04 main` sobre ese fichero no
enseña ese método). Lo que cambió es el test: el snapshot viejo se había grabado
sobre **un grupo al que el fallo del §153 le había borrado el titular**, así que
**guardaba el vaciado como si fuera lo correcto** — que es lo que encontró el
lote E.

> **Un snapshot cambiado no es una respuesta cambiada.** Quien audite la tanda
> mirando qué snapshots se movieron encontrará uno y tendrá que ir a mirar si se
> movió la ruta o se movió el test. Aquí se movió el test, y a mejor.

---

## 1. Qué deja de pasar

Ordenado por lo que un colegio notaría antes.

| Lote | Deja de pasar | Ruta |
|---|---|---|
| **D** | Que una petición a medias deje el colegio **sin nombre, sin año y sin los nombres de unidad que se imprimen en todos los boletines**, contestando 200 | `PUT years/guardar-cambios` |
| **D** | Que aparezcan **dos años actuales** y todo el colegio entre en 2018 al siguiente inicio de sesión | `PUT years/toggle-cambiar-valor` |
| **D** | Que **corregirle la redacción a un logro cambie la nota del boletín**, borrando el peso de la unidad | `PUT unidades/update/{id}` |
| **D** | Que un usuario quede **aparcado en un periodo de la papelera**, con las pantallas vacías en 200 y sin forma de volver desde la interfaz | `PUT periodos/useractive/{id}` |
| **D** | Que editar una asignatura **borre sus créditos y su orden**; y que el conmutador de horario escriba en asignaturas de la papelera | `PUT asignaturas/update/{id}`, `toggle-dia` |
| **E** | Que **editar un grupo lo mueva al año de quien lo edita**, con sus matrículas dentro (56 en la medición) | `PUT grupos/update` |
| **E** | Que un profesor se lleve **la imagen privada de un alumno** y el alumno la pierda | `images-users/cambiar-*` |
| **E** | Que un cuerpo parcial vacíe **22 columnas de una ficha de perfil**, ninguna a salvo | `PerfilesController::putUpdate` |
| **E** | Que un grupo sin titular conteste **200 por una puerta y 404 por la otra** | dos copias del mismo método |
| **C** | Que un cuerpo sin `porcentaje` lo deje en `null` y **recalcule las definitivas con el peso perdido** | `PUT subunidades/update/{id}` |
| **A** | Que editar cualquiera de **seis catálogos** con un cuerpo parcial deje el nombre en `''` y el resto en null, contestando 200 | `areas`, `frases`, `niveles_educativos`, `tiposdocumento`, `grados`, `materias` |
| **B** | Que **editar una falta disciplinaria dé error después de haberla guardado** — el usuario volvía a darle a guardar y duplicaba el intento | `PUT disciplina/update` |
| **B** | Que **borrar el rastro no deje rastro**: el borrado de una bitácora no se firmaba | `DELETE bitacoras/destroy/{id}` |
| **K** | Que el cierre de un pedido de cambio se guarde con **la hora escrita dos veces** (`hora:hora:minutos`) | `PUT ChangesAsked/rechazar` y `aceptar-alumno` |
| **K** | Que aceptar o rechazar un pedido escriba **hasta cinco filas de depuración** en `debugging`, una con el texto `ENTROOOOO` | idem |
| **L** | Lo mismo de la hora, en **cada ausencia que sube el lector de tardanzas** — el camino de más volumen de los dos | `POST tardanzas/subir` |
| **S** | Que **un acudiente reciba un error después de que su prematrícula sí se haya guardado** — y que vuelva a darle al botón. Es la única escritura que alcanza una familia | `PUT matriculas/prematricular` |
| **Q** | Que **un alumno o acudiente que mandara `is_prof_admin=true` en el cuerpo recibiera los eventos que el colegio marca como internos**. Lo que se quita no es un permiso: es que **el cuerpo decida el permiso** | el calendario |
| **P** | Que **abrir la rejilla de comportamiento escriba la nota de cada alumno del grupo con el tope de la escala** con el periodo cerrado — y en el periodo **del que mira**, no el del grupo | la rejilla de comportamiento |

**Además**, en **cinco lotes** hay 500 que pasan a ser 404 o 422. No son un cambio
de capacidad: un id que no lleva a ninguna fila **deja de devolver una traza de
PHP** y pasa a decir que no existe.

Códigos añadidos, **contando las dos formas** —el `abort()` literal y
`Autoriza::exigir()`, que hace `abort(403)` por dentro—:

| Lote | 403 | 404 | 422 |
|---|---|---|---|
| **E** | **4** (1 `abort` + 3 `exigir`) | 5 | — |
| **D** | — | 5 | 1 |
| **C** | **2** (0 `abort` + 2 `exigir`) | — | — |
| **A** | — | 1 | — |
| **B** | — | 1 | — |
| **S** | — | 1 | — |

> **Esta tabla contaba mal hasta ahora, y merece la pena decir cómo**: la primera
> versión buscaba `abort(403` en el diff y daba **uno**. Pero la forma idiomática
> de este repo es **`Autoriza::exigir(...)`**, que hace `abort(403)` dentro, así
> que **cinco de los seis 403 de la noche no llevan la palabra `abort` al lado**.
>
> Quien auditara «qué capacidades se quitaron» con un `grep abort(403` encontraría
> una y concluiría una. Es la **forma 6** del [barrido de cegueras](las-cegueras.md)
> —*la señal que se busca no es la forma que tiene el fallo*— en el documento que
> decide qué se avisa antes de desplegar.

---

## 2. Qué capacidad quita, y a quién

**Cuatro, y las cuatro del lote E.** Las cuatro pasan de 200 a **403 para los 51
profesores**.

> *(Corrección: aquí ponía «una sola». El número era un resumen de coordinación;
> el recuento es del lote E, que es quien las midió.)*

| § | Ruta | Qué podía cualquiera de los 51 | Riesgo al desplegar |
|---|---|---|---|
| §97 | `DELETE profesores/destroy` | mandar **la ficha de un profesor** a la papelera | **bajo** — vive en un menú `admin` |
| §100 | `perfiles/destroy` y `grupos/destroy` | mandar **un grupo** a la papelera desde la rejilla de Usuarios | **bajo** — ver la fila de abajo |
| §99 | `images-users/cambiar-*` (3 rutas) | poner **la imagen de un tercero** en una ficha | **bajo** — solo rechaza un cuerpo que ningún botón sabe construir |

**Lo que deja las cuatro en riesgo bajo es lo mismo, y hay que escribirlo en vez
de darlo por sabido:** *ninguna se alcanza hoy desde una pantalla que el front
enseñe a un profesor.* Tres viven en menús `admin` y la cuarta solo rechaza un
cuerpo que ningún botón construye.

Lo que hace defendible el §97 es la asimetría que lo destapó: **las otras tres
operaciones de esa misma ficha ya pedían superusuario** —`update` (§37), `restore`
(§76) y `forcedelete` (§28.4)—. La que borraba era la única que no pedía nada.

Y el §100 tiene su propia frase, porque lo que se nota **no es lo que parece**:

> «Nada. Ese botón no se puede pulsar hoy: el front lo pinta con una columna que
> la API no manda.»

Un superusuario **sigue** mandando el grupo a la papelera —el botón sigue haciendo
lo que no dice, ahora para menos gente—, pero se dibuja con
`ng-show="row.entity.is_superuser"` y **`perfiles/usuariosall` no devuelve esa
columna**: la condición es siempre falsa.

**Y una que quita capacidad sin que la pierda ninguna persona**, del lote P:

| Lote | Qué |
|---|---|
| **P** | **La pierde una pantalla, no alguien.** El profesor ve lo mismo y recibe el mismo 400 si intenta guardar — solo que ahora **antes de que se escriba nada**. Sin `abort()` en la lectura: con el periodo cerrado la rejilla sigue abriéndose, que es el precedente que Joseth fijó en la §47.2 |

**Y una que NO quita capacidad aunque lo parezca**, porque conviene decirlo antes
de que alguien lo lea al revés:

| Lote | Qué |
|---|---|
| **C** | `DELETE boletines2/destroy/{id}` y `boletines3/destroy/{id}` pasan de 200 a **403**. **No lo nota nadie**: esas dos rutas no las llama ninguna pantalla, y lo que hacían no era borrar un boletín sino **mandar un alumno a la papelera** |

---

## 3. Qué enciende que hoy no funciona

**La columna que casi nunca llega escrita, y esta noche está casi vacía — que es
un resultado, no un descuido.**

| Lote | Qué vuelve a funcionar | Para quién |
|---|---|---|
| **B** | **La ficha de un alumno nacido en una ciudad sin país vuelve a abrir.** `ciudades/datosciudad` daba 500 y ahora contesta 200 con el país en null | secretaría, en los colegios que tengan alguna ciudad guardada sin país |

Nada más enciende. **Los demás arreglos previenen, no restauran**: impiden que
vuelva a pasar, y no deshacen lo ya escrito. Eso importa para el aviso al colegio:

| Lo que **no** arregla el despliegue |
|---|
| Las filas de `change_asked.deleted_at` y de `ausencias.created_at` **ya escritas con la hora mal** siguen mal (K, L) |
| Las **14 de 17** filas de `dis_ordinales` con `created_at` nulo siguen nulas (D) |
| Los catálogos y fichas **ya vaciados** por un guardado parcial siguen vacíos (A, C, D, E) |
| Las filas de `debugging` **ya escritas** siguen ahí, y siguen siendo el único rastro de las importaciones viejas (L) |

---

## 4. Lo que se nota de golpe el primer día

Un cambio que no es ni «deja de pasar» ni «quita»: **lo que se ve distinto la
primera vez que se abre esa pantalla**.

| Lote | Qué |
|---|---|
| **B** | **El listado de bitácoras encogerá de golpe.** El botón de borrar marcaba la fila y el listado no miraba `deleted_at`, así que lo «borrado» seguía saliendo. Al desplegar, todo eso desaparece a la vez. Nadie ha perdido nada — estaba borrado desde el día que le dieron al botón— pero conviene que quien mire esa pantalla lo sepa |

---

## 4.b Las seis minas: no se notan al desplegar, y esperan a que alguien haga lo razonable

**Ninguna cambia nada el día del despliegue.** Están aquí porque la columna que
importa de ellas no es «qué se nota» sino **qué enciende el fallo** — y todas lo
encienden con un cambio que alguien hará un martes sin relacionarlo con esto.

| Mina | No se nota porque | **Lo que enciende el fallo** | Lote |
|---|---|---|---|
| El botón de la rejilla de Usuarios que manda **un grupo** a la papelera | el front lo pinta con `ng-show="row.entity.is_superuser"` y **`perfiles/usuariosall` no devuelve esa columna** | **añadir `is_superuser` a `perfiles/usuariosall`** — lo primero que hace cualquiera que necesite saber quién es administrador | E |
| `PUT grupos/update` sellaba el grupo con **el año de quien lo edita** *(ya arreglado)* | las dos pantallas que editan grupos solo listan los del año en curso | **una pantalla nueva que liste grupos de otro año**, o un cliente que reutilice `grupos/update` | E |
| `matriculas/prematricular` no mira **el año del grupo ni el estado**, y los dos vienen del cuerpo | **las tres comprobaciones viven en el front**: el desplegable solo ofrece grupos de `year + 1`, el botón se esconde para los estados ya decididos, y manda `estado: 'PREA'` fijo | **que `grados_sig` deje de ser `year + 1`** (`ChangeAskedController:305` y `:428`) — un cambio de una palabra. O cualquier cliente que llame sin esas tres comprobaciones | S |
| `folios/iniciar` | es idempotente **por la condición de los datos, no por diseño** | **una reescritura que pierda esa condición** | P |
| `GET api/importar` | **la carpeta que necesita no existe** | **que alguien la cree** | P |
| `arreglar-duplicados` | lleva su comprobación **dentro del método** | **que alguien le quite el `pueden_modificar_definitivas` creyendo que la ruta ya está protegida** | P |

### Las seis se agrupan en tres detonantes, y eso es lo que hay que recordar

No son seis avisos sueltos. **Son tres maneras de encender un fallo apagado**, y
las tres son cosas que alguien hará **por hacer bien su trabajo**:

| Detonante | Cuáles | Por qué se hará |
|---|---|---|
| **Completar lo que falta** | `is_superuser` en `usuariosall`, la carpeta de `importar` | El fallo está apagado **porque falta algo**. Añadir lo que falta es lo correcto en su propio contexto, y enciende el fallo en otro |
| **Ampliar lo que hay** | una pantalla de grupos de otro año, `grados_sig` con otro año | El fallo está apagado **porque el alcance de una consulta es estrecho**. Ensancharlo es una mejora, y quita la única barrera |
| **Quitar lo que sostiene** | el `ng-hide` de los estados decididos, el `pueden_modificar_definitivas` de dentro, la condición de `folios/iniciar` | El fallo está apagado **por algo que parece redundante**. Quitarlo es limpieza, y es lo que lo enciende |

> **El tercero es el más peligroso de los tres**, y `arreglar-duplicados` es su
> caso puro: se enciende si alguien le quita la comprobación de dentro **creyendo
> que la ruta ya está protegida** — o sea, **el error lo induce la propia
> documentación de la ruta**. El aviso ahí no es «no toques esto», es **«lo que
> vas a leer te va a decir que ya está»**.

Y la razón de que las seis existan es la misma, escrita una vez:

> **Cuando una comprobación de negocio vive en la pantalla, la ruta está abierta y
> nadie lo nota.** Cinco de las seis son eso. La sexta —`folios/iniciar`— es la
> variante sin pantalla: la comprobación no vive en ningún sitio, la sostienen
> los datos.

---

## 5. Los lotes que no tocan `app/`

Siete de trece. **No hay nada que avisar de ellos**, y por eso pueden ir en
cualquier punto de la tanda o quedarse fuera sin coste:

| Lote | Qué dejó |
|---|---|
| **F** | 30 tests. Dos rutas cuyo 500 queda escrito con su mensaje exacto |
| **G** | La medición de los 44 interruptores contra los cuatro clientes |
| **H** | `tools/identificadores-del-cuerpo.py` arreglado: **28 familias, no 29** |
| **I** | El barrido por tipo de token |
| **J** | Las rutas cubiertas sin juzgar |
| **M** | 7 tests que impiden «arreglar» dos modelos con un `?? null` |
| **O / P** | *(sin fundir al montar esto — ver hueco abajo)* |

---

## Huecos: lo que no pude poner sin inventarlo

**Se dejan como huecos a propósito.** Cada uno necesita una línea de su sesión.

| Lote | Qué falta |
|---|---|
| ~~**G**, **I**, **H**~~ | **Cerrado por coordinación**: medido con el `git diff` de sus propios merges, **no tocan `app/` ni `routes/`**. Era ausencia de impacto, no solo ausencia de sección |
| **P**, **Q**, **R** | Abiertos al escribir esto. **R es el único hallazgo de la noche que le pasa a un colegio ahora mismo** —una familia recibe un 500 en vez del boletín en las maquetas 2 y 3—, así que su fila irá en la sección 3 y no en la 1 |
| ~~**E §100**~~ | **Cerrado**: su sesión lo midió. No se nota nada al desplegar, y **lo que enciende el fallo es añadir `is_superuser` a `perfiles/usuariosall`**. Está en la §4.b |
| **A** | `definiciones_comportamiento/destroy` y `contratos/destroy` contestaban **200 con `No se encontró` y con `0`**. El diff de A añade un solo `abort(404)`, así que **no las dos**. Su sesión tiene que decir cuál se arregló y qué contesta la otra hoy |

---

## Y una advertencia que no es un cambio, del lote C

No entra en la tanda, pero **decide cuándo se puede desplegar el día que se
decida**:

> `definitivas_periodos/calcular-grupo-periodo` **sigue reescribiendo la rejilla
> de un periodo cerrado**. No se ha tocado. Si se decide cerrarla, ese cambio
> **sí apaga algo** —abrir el boletín de un grupo desactualizado en periodo
> cerrado— y entonces **hay que desplegarlo mirando el calendario del colegio**,
> no en cualquier momento.
