# 23. El horario del colegio — qué le toca a esta API

> **Estado: v2, 2 sep 2026. La parte de backend de la v1 —la de esta misma mañana—
> está retirada entera, no corregida.** La v1 diseñaba un **módulo web** con cinco
> tablas nuevas y nueve rutas. El horario ya no es eso: es un **programa de
> escritorio** (Tauri 2 + Angular) con su propio fichero de proyecto, y a esta API
> le queda una cosa mucho más pequeña — **guardar versiones del horario de un año y
> decir cuál es la oficial**.
>
> Lo decidió Joseth el 2 sep 2026 sobre unos pantallazos de aSc Timetables, y llegó
> aquí por la sesión `myvc-front-ea`. El diseño completo del cliente vive en el
> artefacto, que es de ellos y es la versión buena de esa mitad:
> <https://claude.ai/code/artifact/d2859c56-5a62-4993-9a29-3303712bfbb9>
>
> **Este documento es la otra mitad y sólo la otra mitad**: lo que se escribe en
> `8myvc`, lo que el servidor puede comprobar y lo que no, y las decisiones que
> siguen abiertas. ~~Nada de esto está construido. Las tres rutas de la §5.3 son una
> propuesta~~ — **cierto el 2 sep 2026 y falso desde esa misma noche**: hoy están
> escritas **cuatro**, las tres de la §5.3 (lotes A, B y C, 2 sep) y la de la §9.bis
> (`lecciones`, 4 sep), y el router está en **567**. Lo que sigue sin construir no es
> el módulo: es el **despliegue**, que va **0 de 16** (§11). Lo que no cambia es la
> regla que había debajo: **en este repo una ruta es una decisión, no un efecto
> secundario**, y por eso las cuatro tienen su fecha y su porqué.
>
> *Va tachada y no borrada porque este documento es también el registro de cómo se
> decidió. La frase nació bien: se escribió en presente y se leyó como el estado de
> hoy dos días después — la misma forma de envejecer que la §11.5 tiene catalogada.*
>
> **Segunda vuelta con el front, 2 sep por la tarde**, ya incorporada: el cuerpo del
> `POST` concretado y corregido en cuatro sitios (§5.2), la **opción B** recomendada
> por las dos sesiones con el veredicto guardado junto a la versión (§6), y la
> frontera nueva de que el escritorio se pueda vender **sin MyVC detrás** (§8).
>
> **El contrato quedó cerrado entre las dos sesiones el 2 sep**, después de tres
> vueltas. **No hay que volver a negociar la forma** — si algo de aquí se cambia, se
> cambia con Joseth y avisando al front, que tiene su mitad escrita sobre esta.
>
> **EL SUELO YA ESTÁ ESCRITO (lote A, 2 sep 2026, rama `feat/horario-suelo`).** Las
> tres tablas de la §5.1 y `years.horario_version_id`, `Role::isCoordAcademico()`,
> `Autoriza::puedePublicarHorario()`, `routes/api/horario.php` y un
> `HorarioController` con **los tres métodos a 501** y su autorización ya puesta
> delante. **El router pasó de 563 a 566**, contado con `route:list --json` ese día.
> Lo que faltaba entonces era el cuerpo de los tres.
>
> **Ya no falta.** Los lotes B y C lo escribieron esa misma noche —con la §6 (el
> veredicto de la opción B) y la §7 (las siete columnas de día) dentro—, y el 4 sep
> 2026 entró **la cuarta ruta** de la §9.bis: el router **en 567**, contado ese día.
> Los cuatro métodos tienen cuerpo y **ninguno contesta ya 501**.
>
> **Y ese mismo día Joseth contestó seis de las siete decisiones abiertas**: las tres
> rutas están **autorizadas** (§5.3), la revalidación es la **opción B** (§6), la oficial
> la marcan **superusuario y coordinador académico** (§5.4) —que trajo un hallazgo,
> porque «coordinador académico» nombra dos cosas distintas en esta base y hoy **ninguna
> de las dos identifica a nadie**—, listar es **`auth.personal`**, subir y publicar valen
> **en cualquier año**, y el rol vacío **se escribe igual**. **Quedan cuatro** en la
> §10.2.

---

## 0. Lo que cambió de la v1 a la v2, en una tabla

| | v1 (2 sep, mañana) | v2 (2 sep, tarde) |
|---|---|---|
| Dónde se cuadra el horario | módulo web en `app2` | **programa de escritorio**, Tauri 2 + Angular |
| Dónde vive la configuración | cinco tablas nuevas en el servidor | **fichero de proyecto local** (`colegio-2026.myvch`) |
| Salones, disponibilidad, franjas, restricciones | tablas | **no existen en el servidor** |
| Qué recibe el backend | la rejilla, ficha a ficha, mientras se cuadra | **una versión terminada**, cuando se sube |
| Rutas nuevas | nueve | **tres** |
| Quién publica | quien edita | **subir ≠ publicar**: sube un administrativo, publica un administrador |

De cinco tablas y nueve rutas a tres tablas y tres rutas. Y el cambio de fondo no es
el tamaño: es que **el servidor deja de ser el sitio donde se decide y pasa a ser el
sitio donde se guarda lo decidido** — con la consecuencia incómoda de la §6, que es
que hay reglas que ya no puede comprobar porque no tiene el dato.

---

## 1. Las cifras, medidas otra vez en este árbol

Sobre `simonbolivar` —la base del contenedor, que sale de un colegio real—, año con
`actual = 1` (`year_id = 8`), el **2 sep 2026**, con `php artisan tinker` dentro de
`8myvc-app-1`:

| | |
|---|---|
| Grupos | **13** — Jardín y Transición, Primero a Quinto, Sexto a Once |
| Asignaciones (`asignaturas`: materia × grupo × docente) | **134** |
| Docentes con clase | **12** |
| **Σ intensidades horarias** (`SUM(creditos)`) | **345** |
| Asignaciones sin IH | **0** |
| Asignaciones **sin docente** | **10**, que son **25 horas** |
| Asignaciones con algún día marcado | **2** |
| `years.minu_hora_clase` | **50** |
| El docente más cargado | **31 horas** |
| Rutas del router | **550** (`route:list --json`) |

La IH por grupo sale redonda, que es la señal de que el colegio la administra de
verdad: **30 h/semana** en los seis de bachillerato, **25** en los cinco de
primaria, **20** en los dos de preescolar.

> **Estas cifras son de un colegio, no de los quince.** `simonbolivar` es el que hay
> montado; que aquí la IH esté puesta en las 134 no dice nada de los otros catorce.
> Contestarlo es lo más barato que se puede hacer hoy y está en la §9.

Y una que no se mide aquí: **la rejilla es de 7 lecciones × 5 días = 35 casillas**.
Sale de la configuración de aSc del colegio, que Joseth enseñó en un pantallazo. La
v1 supuso 6 × 5 = 30 y con ese supuesto el docente de 31 horas era **imposible**;
con 35 le sobran cuatro. **El dato que decidía si el problema tenía solución no era
del algoritmo: era un desplegable**, y por eso la verificación va antes que nada.

---

## 2. El hallazgo que corrige a la v1: «Clases de hoy» no enseña de más — no enseña nada

La v1 escribió que, con las siete columnas de día vacías, *«el panel cae por la rama
de enséñalo todo»*. **Es falso en el año actual de este colegio**, y la dirección del
error importa: no es que el docente vea sus clases desordenadas, es que **no ve
ninguna**.

Lo que hay, leído y medido:

- `ChangeAskedController::asignaturas_dia()` ([línea 1196](../../app/Http/Controllers/ChangeAskedController.php#L1196))
  arma el filtro con un `switch` sobre el día — `and lunes=1`, `and martes=1`… — y
  **sólo se lo salta si `show_materias_todas` está encendido**.
- `years.show_materias_todas` en el año 8 vale **0**. (Vale 1 en los años 9, 6 y 5:
  la rama de «enséñalo todo» existe y está usada en otros años, pero **no en el que
  el colegio tiene abierto**.)
- Las **2 de 134** filas con un día marcado son `DIMENSIÓN COGNITIVA` y
  `DIMENSION COMUNICATIVA` de Transición, y las dos tienen **`profesor_id` nulo**.
  La consulta filtra `where a.profesor_id = ?`, así que **no le salen a nadie**.

Conclusión, y se puede repetir: **hoy `horario_hoy` y `horario_manana` vuelven vacíos
para todos los docentes, todos los días.** La pantalla desde la que se toma asistencia
y se pasan notas está en blanco, y nadie lo ha reportado porque **un `[]` se parece a
«hoy no tengo clase»**.

> Es la misma forma que ya costó cifras en este repo dos veces: **el vacío y el «no
> miré» se parecen, y de las dos lecturas la falsa es la que hace archivar el
> asunto.** Aquí la falsa era la benigna —«el panel enseña todo»—, y con ella escrita
> el módulo de horarios parecía una mejora estética. No lo es: es rellenar una
> pantalla vacía.

### 2.1. Y hay un fallo dormido debajo, que despierta el día que existan las lecciones

`getToMe` pide el día de mañana como `$dia + 1` sobre `Carbon::dayOfWeek` (0 =
domingo … 6 = sábado) y **nunca le pasa `show_materias_todas`**
([líneas 106-107](../../app/Http/Controllers/ChangeAskedController.php#L106) y
[168-169](../../app/Http/Controllers/ChangeAskedController.php#L168)):

- **El sábado**, `$dia + 1 = 7`. El `switch` no tiene caso 7, `$dia_cond` se queda en
  el espacio en blanco con el que nace, y la consulta devuelve **todas** las
  asignaciones del docente. O sea: un día a la semana, «mañana» enseña el curso
  entero.
- El domingo (`$dia = 0`) sí filtra, por `domingo=1`.

Hoy **no se ve**, porque con las columnas vacías todo sale vacío igual. **Se ve el
día que se rellenen**, que es exactamente lo que propone la §7. Va en el mismo lote,
no después: es una línea, y arreglarlo después convierte el estreno del horario en
un fallo nuevo.

---

## 3. El vocabulario, cerrado

Lo cerró la sesión del front y se adopta tal cual, porque el choque es real: aSc
llama «clase» a dos cosas distintas y ninguna de las dos es lo que MyVC llama clase.
**La tercera columna es la que va en el código.**

| En aSc | En MyVC hoy | En el sistema de horarios | Qué es |
|---|---|---|---|
| Clase | Grupo | **Grupo** | Sexto, Décimo. El curso entero; no se divide |
| Clase / Lección | — (era «ficha» en la v1) | **Lección** | la pieza que se coloca en una casilla; 1 hora salvo bloque |
| Contrato | fila de `asignaturas` | **Asignación** | asignatura × grupo(s) × docente(s) con su IH; es la madre de N lecciones |
| Aula | — | **Salón** | con capacidad **en grupos**: un aula normal 1; la iglesia, muchos |
| Tiempo libre | — | **Disponibilidad** | la rejilla de tres estados: ✓ adecuado, ? condicional, ✕ inadecuado |
| Lección | — | **Franja** | la columna: lección 1…7 del día. «Hora» se confunde con los 50 minutos |

**«Ficha» ya no se usa.** Si aparece en un comentario o en un nombre, es de la v1.

---

## 4. Lo que NO se escribe en este repo

Queda por escrito para que ninguna sesión futura las resucite leyendo la v1:

- **No entran** `franjas`, `salones`, `horario_fichas`, `eventos_comunes` ni
  `restricciones`. Ninguna de las cinco.
- **No entran** las nueve rutas de la v1 (`horario/rejilla`, `horario/insumos`,
  `horario/ficha`, `horario/prevuelo`, `horario/franjas`, `horario/evento`…).
- La rejilla del colegio, los timbres, los descansos, las jornadas por nivel, los
  salones con su capacidad, las disponibilidades, las distribuciones de bloque, los
  colores y los pesos **viven en el fichero de proyecto del escritorio**.

Y la consecuencia que hay que leer entera antes de prometer nada, porque es la que
muerde: **el servidor no tiene esos datos, así que no puede comprobar las reglas que
dependen de ellos.** Está en la §6.

---

## 5. Lo que sí hace: versiones del horario de un año

**Subir no es publicar.** Cada subida crea una **versión** del horario de ese año
—nombre, fecha, quién la subió y sus lecciones colocadas—, y una pantalla web elige
cuál es la **oficial**. Hasta que se elige, «Clases de hoy» sigue enseñando la
anterior. Eso resuelve tres cosas de una vez: dos personas pueden probar horarios sin
pisarse, el colegio guarda el historial del año, y **una subida a medias no le llega
a nadie**.

### 5.1. El modelo — tres tablas, y son una propuesta

```
horario_versiones     (id, year_id, nombre, subida_por, proyecto, comprobaciones, created_at, …)
horario_lecciones     (id, version_id, pieza_id, asignatura_id, dia, franja, duracion, salon)
horario_pieza_docente (version_id, pieza_id, profesor_id)

years.horario_version_id   -- la oficial. Una columna, no una bandera. Ver abajo.
```

Tres decisiones de forma, cada una con su porqué:

**Una fila por (pieza × asignación), no por lección con `grupo_ids` dentro.** Una
lección de varios grupos —la misa— es **una** pieza y **N** asignaciones, porque
Joseth cerró que *sale de una asignatura existente*: gasta una hora de la IH de
Religión de cada grupo unido. Si el grupo va en un JSON, entonces derivar las siete
columnas de la §7 y comprobar *Σ lecciones ≤ IH* obligan a desempaquetar JSON en
PHP; con una fila por asignación, las dos son un `GROUP BY`. El `pieza_id` es lo que
mantiene juntas las filas que son la misma pieza — y con eso **la sincronización de
la misa deja de ser una regla**: una pieza está en una sola casilla porque es una
sola pieza.

**Los docentes cuelgan de la pieza, no de la asignación.** Es el caso del capellán, y
es el que rompe lo obvio: si la misa la da el capellán, el titular de Religión de
Décimo **tiene esa hora libre**, aunque la hora salga de su asignación. Leer los
docentes de `asignaturas.profesor_id` daría la respuesta contraria, y con ella la
comprobación de «docente sin choque» de la §6 sería falsa en el único caso raro que
tiene el colegio.

**Y la columna nueva de `years` se reparte sola a tres respuestas vivas.** `years` la
leen con `SELECT *` `YearsController::getIndex`, `::getColegio` y `getTrashed` —el
último por Eloquent—, así que `horario_version_id` aparece **sin que nadie la mande** en
`GET years`, `GET years/colegio` y la de papelera, y mueve sus tres instantáneas de
muestreo. Vale `null` en todos los años hasta que se marque una oficial, así que es lo
más inofensivo que se le puede mandar a los cuatro clientes — **pero se manda dicho, no
descubierto**: una clave nueva es un *campo*, y el canal con el front cubre campo,
cuerpo, ruta o quién puede llamarla.

> **No llega al contexto de usuario, y conviene saber por qué**: `ContextoDeUsuario`
> **no tiene ni un `y.*`** —enumera columnas a mano en las cuatro ramas—, así que ahí
> sólo entra lo que alguien decide meter. Si algún día hace falta en el contexto, es una
> decisión con su porqué y no un `SELECT` que se ensancha.
>
> **Pero hay un cuarto `SELECT *` sobre `years` que hoy no mueve nada y es el que hay
> que vigilar**: `Year::actual()`, con tres llamantes, uno de ellos `LoginController`.
> Ninguno deja hoy esa fila en una instantánea — **y eso no es que sea seguro, es que
> nadie ha mirado por ahí**. Es el sitio exacto por donde una columna de `years` saldría
> mañana a la respuesta de login sin que nadie lo decidiera. Lo localizó `8myvc-29` el 2
> sep 2026 midiendo con **dos marcadores independientes** —dos columnas de `years`
> añadidas en fechas distintas— en vez de razonar sobre llamantes: con uno solo, «esta
> respuesta lleva la fila entera» y «alguien escribió esa clave a mano» son
> indistinguibles, y las dos cuentas de más que hubo ese día —un nueve y un cuatro—
> salieron justo de ahí.

**La oficial es un puntero, no una bandera.** `years.horario_version_id`, no
`horario_versiones.oficial`. MySQL no tiene índices parciales, así que una columna
`oficial tinyint(1)` no se puede atar a «como mucho una por año»: el día que haya dos
en verdadero, quien lea `WHERE oficial = 1 LIMIT 1` se lleva una de las dos **y no se
pone nada rojo**. Un puntero no admite ese estado. Marcar la oficial pasa a ser un
`UPDATE` de una columna, y «todavía no hay ninguna» es `NULL`, que es un estado y no
un accidente.

**El blob del proyecto sube siempre, y es lo único de aquí que puede crecer sin
límite.** Que suba lo decidió Joseth el 2 sep —no opcional— y la razón es buena: sin él
el trabajo de un mes vive en un portátil. Pero un JSON con la configuración, las
disponibilidades de doce docentes y 345 colocaciones **no se ha medido nunca, porque
todavía no existe ninguno**. Antes de escribir la columna quedan dos cosas por decidir
—§10.2— y ninguna es cosmética: si va en la fila o en `storage/`, y cuál es el tope. En cPanel el que corta primero no es PHP, es `max_allowed_packet` de
MySQL, y lo hace con un error que no se parece a «el fichero es muy grande».

### 5.2. El cuerpo del `POST` — la forma que propuso el front, con cuatro correcciones

```
version:  { nombre, year_id, anio, nombre_colegio }  ← y nada más viene del cuerpo
proyecto: "…"                                       ← el .myvch entero, como cadena
piezas:   [ { pieza_id, dia, franja, duracion,
              salon_nombre, salon_capacidad_grupos,  ← informativos, nunca reglas
              docentes:     [ profesor_id, … ],      ← profesores.id, NO users.id
              asignaciones: [ asignatura_id, … ] } ]
```

> **`proyecto` faltaba en este boceto, y la §5.1 y la decisión 22 lo daban por
> puesto.** Lo levantó la sesión del escritorio el 2 sep 2026 comparando
> `nucleo/envio.ts` con esta sección **campo a campo**, que es lo que ninguna de las
> dos mitades había hecho: son 13 campos y **12 calzaban exactos**; el que no,
> viajaba desde aquí sin que este documento lo pidiera. **No era una discrepancia
> entre las dos mitades — era que una no lo decía**, y esa forma no la caza releer
> el lado que sí lo dice.
>
> Y no era cosmético: `horario_versiones.proyecto` es `mediumText()` **sin
> `nullable()`**, así que el cuerpo es el único sitio del que puede salir. Un
> contrato que no menciona un campo obligatorio se implementa dos veces —una por
> mitad— y las dos veces distinto.
>
> Va **al primer nivel y no dentro de `version`**, que es donde ya lo ponía el
> emisor y el arnés que midió la cota alta (§10.2.2). Se adopta lo medido en vez de
> la simetría, por lo mismo de siempre: quien ya corrió el número tiene razón sobre
> quien lo está imaginando.

Cada elemento de `asignaciones` se explota a una fila
`(version_id, pieza_id, asignatura_id, dia, franja, duracion, salon)`, que es la forma
de la §5.1. Y **lo pone el servidor, no el cuerpo**: `subida_por`, `created_at` y
`comprobaciones`.

**0. `anio` y `nombre_colegio` viajan, y no son adorno.** Los trajo la sesión
`myvc-front-8e` con la decisión de Joseth el 2 sep 2026, después de que este
documento dijera durante unas horas `{ nombre, year_id }` *«y nada más»*. **La lista
de campos envejece; el argumento no**, así que va escrito el argumento:

- **`anio` (= `years.year`) se comprueba DURO, con 422.** Es lo único que caza un
  `.myvch` subido **al colegio equivocado**: `years.id = 8` es 2025 en un colegio y
  puede ser 2019 en otro, así que *identificador que existe + año distinto* = 422.
  Sin ese campo el servidor **no tiene contra qué contrastar su propia fila**, y
  entonces esa comprobación no es que falle: **no existe, y su ausencia no da ningún
  error**. Y el daño no es teórico por la decisión 13 —publicar vale en cualquier
  año, también uno cerrado—: una versión que entra en el año equivocado y se marca
  oficial **reescribe las siete columnas de día de ese año**. Es la misma familia que
  la cuarta comprobación de la §6, un piso más arriba: aquélla protege de
  asignaciones intrusas **dentro** de la versión, ésta de que la versión **entera**
  vaya al año de otro colegio.
- **`nombre_colegio` se comprueba BLANDO**: renglón del veredicto, **nunca puerta
  cerrada**. No es una identidad sino texto libre, editable desde configuración y
  distinto por año — un colegio que se renombró legítimamente entre el import y la
  subida **no se puede quedar sin poder subir su horario**.

Lo que esto **no** abre: `subida_por`, `created_at` y `comprobaciones` los sigue
poniendo el servidor, y `origen.servidor` no viaja.

**1. Los docentes son `profesores.id`, no `users.id`.** La propuesta decía `user_id`,
y son dos columnas distintas de la misma fila: `profesores` tiene `id` **y**
`user_id`, y la lectura que ya usa el panel devuelve las dos en la misma consulta
(`SELECT p.id as profesor_id, …, p.user_id`), así que coger la que no es sale gratis.
Lo que apunta a docente desde una asignación es `asignaturas.profesor_id →
profesores.id`. **Aquí no se notaría**: los 47 profesores del colegio tienen
`user_id`, y los 12 con clase también. Pero la columna es **NULLable**, y un docente
sin cuenta de usuario desaparecería de la revalidación **sin ningún error** — es de
las que se descubren en el colegio catorce. Y hay una segunda razón, de esta casa:
`$user->user_id` y `$user->persona_id` no son lo mismo y esa confusión ya tiene su
párrafo en `CLAUDE.md`.

**2. `subida_por` sale del token, no del cuerpo.** Un identificador de persona que
llega por el cuerpo y no lo comprueba nadie es un patrón que aquí tiene herramienta
propia —`tools/identificadores-del-cuerpo.py`— y una lista de cinco métodos donde ya
salió. El que sube es el que trae el token; aceptar otro es dejar firmar en nombre
ajeno. Igual `created_at`: la hora la pone el reloj del servidor, no el del portátil
del coordinador.

**3. `comprobaciones` lo escribe el servidor y no se lee del cuerpo nunca.** Es su
veredicto. Si viaja de fuera, un cliente puede subir un horario con «comprobado
todo ✓» encima, y el historial deja de servir para lo único que sirve.

**4. `salon_nombre` y `salon_capacidad_grupos` viajan, se guardan y NO ascienden a
regla.** Que viajen está bien: sirven para imprimir y para que el veredicto pueda
**nombrar el dato que le faltó** en vez de decir «no comprobado» a secas. Lo que hay
que dejar escrito es que **no se validan choques de salón con ellos**: la capacidad la
elige el cliente, y comprobar una regla contra un número que manda el mismo que quiere
pasar la comprobación no es comprobar. Está escrito aquí porque el día que alguien vea
la columna va a pensar que ya se puede.

**5. `dia` va de 0 a 6, con 0 = domingo — y esto es contrato, no detalle.** Y con él, la
regla que lo sostiene y que puso la sesión del front: **el índice de columna de la
rejilla no es el día**. Lo deriva quien pinta, nunca lo que se guarda ni lo que viaja —
por eso la jornada lleva la **lista de días reales** (`[1,2,3,4,5]`, o `[1..6]` en un
colegio con sábado) en vez de un número, y «día 1 del horario» deja de existir.
 Lo levantó
`8myvc-9d` el 2 sep 2026: **ningún documento lo decía**, y aSc numera de forma natural
1 = lunes. Se fija así porque es el convenio con el que se **consumen** las siete
columnas —`asignaturas_dia()` va sobre `Carbon::dayOfWeek`, 0 = domingo … 6 = sábado—,
de modo que la derivación de la §7 **no traduce nada**, y un mapeo es justo donde vive
un off-by-one.

> **Y hay que ver por qué esto no lo caza la revalidación.** Si el cliente manda 1 = lunes
> y el servidor lo lee como `dayOfWeek`, **el horario entero se corre un día**: el lunes
> se pinta el domingo y el viernes cae en jueves. No da error, no da 422, y **el veredicto
> de la opción B lo daría por bueno**, porque las tres reglas que sí comprueba —grupo,
> docente, Σ = IH— se cumplen exactamente igual con el horario corrido. Es la §8 en su
> forma barata: *no da error, da un horario equivocado.* Un `dia` fuera de 0..6 se
> rechaza con **422**; uno dentro pero con el convenio cambiado **no lo detecta nadie**,
> y por eso el convenio se declara en un sitio en vez de deducirse.

**6. Los otros dos números que admiten dos lecturas, declarados y no deducidos.**
**`franja` va en base 1** —la 1 es la primera lección del día— y **`duracion` se cuenta
en casillas**, no en minutos ni en horas de reloj: un bloque de dos es `duracion: 2` y
ocupa dos franjas consecutivas del mismo día.

> **`franja` se decide con el argumento CONTRARIO al de `dia`, y merece la pena verlo.**
> En `dia` mandó el consumidor: el backend lo lee, así que se adoptó su convenio para no
> traducir. **En `franja` no hay consumidor** —el servidor la guarda y la devuelve, pero
> **no la interpreta nunca**, que es justo por lo que la §7 no puede prometer el orden—,
> y cuando no hay consumidor gana **lo que dice el colegio**: la primera hora es la 1. Y
> si algún día llega la opción B y algo ordena por franja, base 1 ordena igual que base
> 0: no hay nada que ganar cambiándolo después.
>
> **`duracion` lleva su aviso porque hay una trampa esperando**: `years.minu_hora_clase`
> vale **50** y está en la base, así que quien vea `duracion` sin declarar va a pensar en
> minutos — y `duracion: 2` se leería como dos minutos o como dos horas de reloj según el
> día que sea.

> **La regla que dejan los tres, y la puso la sesión del front:** *si no está escrito en
> el contrato, las dos mitades lo van a deducir por separado.* Por eso la lista de lo que
> viaja como número y admite dos lecturas se hace **entera** —`dia`, `franja` y
> `duracion`— y no se declara sólo el que dio problemas.

**Y una que no es del cuerpo sino del año.** `year_id` puede ser de un año pasado, y
eso **ya está contestado**: moverse por un año pasado es el producto
([16](16-escribir-en-un-anio-pasado.md)), y lo que frena las escrituras allí es el
interruptor del **periodo**. Un horario no cuelga de ningún periodo, así que ese
candado **no le aplica** y lo único que lo frena es el permiso de la §5.4. **Joseth lo
confirmó el 2 sep: subir y volver oficial, en cualquier año**, también en los cerrados
— más abierto que lo que proponían las dos sesiones, y coherente con la 16. La
consecuencia, dicha entera: **marcar oficial una versión de 2024 reescribe las siete
columnas de las asignaturas de 2024**, y quien se mueva a ese año verá ese horario. Es
lo que se ha decidido, no un efecto colateral — pero es la razón por la que el puntero
vive en `years` y no en una bandera: cada año tiene el suyo y no se pisan.

### 5.3. Las tres rutas — AUTORIZADAS el 2 sep 2026

> **La familia `horario` ya no es de tres: es de cuatro.** La cuarta —`GET
> horario/versiones/{id}/lecciones`— se autorizó el 3 sep y se escribió el 4, y vive en
> la §9.bis porque tiene su propia decisión y su propia razón. Todo lo que dice esta
> sección sigue siendo cierto **de estas tres**; lo único que hay que releer con eso
> delante es el renglón de `guard-por-familia.json`, que hoy dice **4 de 4** y no 3 de 3.

    POST horario/versiones               sube una versión    auth.token + esAdministrativo
    GET  horario/versiones               lista las del año   auth.token + auth.personal
    PUT  horario/versiones/{id}/oficial  marca la oficial    auth.token + puedePublicarHorario (§5.4)

Las autorizó Joseth el 2 sep 2026, las tres a la vez y con esta razón: con sólo las
dos primeras se puede subir y listar, pero **nadie puede marcar la oficial y «Clases
de hoy» sigue vacía**, que es el problema que este módulo viene a resolver.

Lo que mueven el día que se escriban, que **no es sólo el router**:

- **Escritas el 2 sep 2026 (lote A).** El contador estaba en **550** cuando se
  escribió esta línea y en **563** el día que entraron —las dos épicas de esa noche
  van por medio—, así que la predicción «553» de la v2 **nunca fue el número**: eso es
  exactamente lo que la regla evita. Contado con `route:list --json` sobre el árbol
  del lote: **566**. Coincidió con 563 + 3, y contarlo es la única forma de saber que
  coincidía.
- `CLAUDE.md`, y los snapshots `rutas.json`, `guards-por-ruta.json` y
  `guard-por-familia.json` — las tres llevan guard, así que la familia `horario`
  entra como **3 de 3** y ese renglón no pide explicación. **Movidos y revisados uno
  a uno: 13 líneas en total**, y ninguna fuera de las previstas.
- **Y las tres llevan `auth.personal` de middleware, que no es ninguno de los dos
  criterios de la §5.4.** Cierra la puerta a alumnos y acudientes **antes de tocar el
  controlador**, y es la forma de la referencia que dio Joseth
  (`myimages/cambiarlogocolegio`: guard en la ruta **más** `Autoriza` dentro). Que con
  eso la familia salga «3 de 3» es una **consecuencia y no la razón** — el contador
  lo cuenta `AutorizacionTest::llevaGuardDePropiedad()`, que sólo mira `auth.personal`,
  `persona.propia` y `boletin.propio`, y un guard que se pusiera para redondear un
  contador sería un guard que el siguiente lote quita cuando le estorbe.
- **`years.horario_version_id` mueve un cuarto test que no estaba previsto**:
  `CentinelaDeLasColumnasDelAnioNuevoTest` exige que **cada** columna viva de `years`
  esté decidida —copiada por `postStore` o excusada **con su motivo**—. El puntero se
  excusa: copiarlo dejaría al año nuevo afirmando que su horario oficial es una
  versión **del año anterior**, que es el estado exacto que el puntero en `years`
  existe para impedir.
- **No** mueven `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni
  `AutenticacionTest::SIN_GUARD`: ninguna de las tres es pública, y ninguna debería
  serlo — una versión del horario dice qué docente está dónde a cada hora.

### 5.4. Autorización — decidida, y con un hallazgo dentro

Lo contestó Joseth el 2 sep 2026: **sube cualquier administrativo; marca la oficial un
superusuario o el coordinador académico.**

| | Criterio | Qué es, hoy |
|---|---|---|
| Subir una versión | `Autoriza::esAdministrativo` | `is_superuser \|\| Role::isSecretario` ([línea 73](../../app/Support/Autoriza.php#L73)) |
| Listar las versiones | **`auth.personal`** | personal del colegio: ni alumno ni acudiente |
| Marcar la oficial | **`puedePublicarHorario`, método nuevo** | `is_superuser \|\| Role::hasRole($id, 'Coord académico')` |

La subida es el mismo criterio que hoy pide `putCambiarlogocolegio`
([`ImagesController.php:285`](../../app/Http/Controllers/Perfiles/ImagesController.php#L285)),
que es la referencia que dio Joseth. **La publicación no es ninguno de los criterios
que ya existen**: no es `esSuperusuario` (deja fuera al coordinador) ni
`esAdministrativo` (mete al `Secretario`, que Joseth no nombró). Es un método nuevo en
`Autoriza`, y **eso es lo correcto**: la regla de esta casa es que un criterio nuevo se
escribe con su nombre, no se cuela ensanchando uno que leen otros seis sitios.

**Nota: la publicación NO incluye al `Secretario`, y eso es a propósito.** Secretaría
sube todas las versiones que quiera —está en `esAdministrativo`— pero no elige la que
ve el colegio. Es la asimetría que pidió Joseth desde el principio: *subir no publica*.

**Y la lectura es más ancha que la escritura a propósito: `auth.personal`.** Lo decidió
Joseth el 2 sep, más abierto que la propuesta de las dos sesiones —que era «el mismo que
sube»—: cualquier docente puede ver qué versiones hay. Tiene sentido, porque el horario
es un papel que acaba pegado en la puerta del salón.

> **Pero eso pone una condición en la respuesta, y hay que escribirla antes de la ruta:
> listar no es descargar.** `GET horario/versiones` devuelve **nombre, fecha, quién la
> subió, si es la oficial y su veredicto** — **nunca el blob del proyecto ni las
> lecciones**. Con `auth.personal`, un `SELECT *` en esa ruta le entrega a cualquiera de
> los 53 docentes el fichero de proyecto entero del colegio. El blob se descarga por
> otro camino y con otro permiso el día que haga falta; hoy no hace falta ninguno.

**Y ahí aparece una ruta que no está pedida ni autorizada: descargar el proyecto.** Si
el blob sube siempre (§5.1), antes o después alguien va a querer bajárselo para reabrir
el año en otro computador — y eso es una **cuarta** ruta, no un campo más de la lista, y
**su número se cuenta con `route:list` el día que se autorice**. Aquí no lleva ninguno a
propósito: una ruta que no existe **no puede tener número, porque nadie lo va a poder
contar hasta que exista**. El «554» que decía esta línea salió de sumar sobre 550 y quedó
stale dos veces en una sola noche; escribir el número medido de hoy no lo arregla, sólo
retrasa la tercera vez. La sesión del front vota por que el permiso sea **el mismo que publica**, y
el argumento es el correcto: *subir es dejar tu trabajo, descargar es llevarte el de
otro*. Queda en la §10.2 sin escribir, como todas.

#### El hallazgo: «coordinador académico» nombra dos cosas, y hoy ninguna identifica a nadie

Al ir a escribirlo aparecieron **dos mecanismos distintos con ese nombre**, y elegir el
que no es sería un permiso que gobierna a otra gente:

| | Qué es | Cuántos hoy |
|---|---|---|
| El **rol** `Coord académico` (`roles.id = 9`, de 2018) | se asigna por `role_user`; pueden ser varios | **0 usuarios** |
| La columna **`years.coordinador_academico_id`** | **una** persona por año | **`NULL`** en el año 8 |

**Se usa el rol, y la columna no.** La columna se escribe en un solo sitio —cuando un
año se copia del anterior ([`YearsController.php:136`](../../app/Http/Controllers/YearsController.php#L136))—
y **no la lee nadie en todo `app/`**: es un dato que se arrastra, no un permiso. Un rol
sí es el mecanismo con el que este colegio reparte quién puede qué.

**Y la consecuencia hay que decirla entera, porque la frase suena a que cambia algo y
hoy no cambia nada:** el rol `Coord académico` existe desde 2018 y **tiene cero
usuarios** en este colegio (`Coord disciplinario` tiene uno; `Secretario`, creado el 21
ago 2026, también tiene cero). Así que el día que esto se escriba, **quien puede marcar
la oficial son los 11 superusuarios y nadie más**, hasta que alguien le dé el rol a la
coordinadora. La regla queda **correcta e inerte**, que no es un fallo — pero leer
«también el coordinador académico» y suponer que ya hay alguien detrás sí lo sería.

Hace falta además un `Role::isCoordAcademico()`, que **no existe**: hay
`isCoorDisciplinario`, `isSecretario`, `isEnfermero` y `isPsicologo`, y el académico se
quedó sin el suyo. La cadena tiene que ser exactamente `'Coord académico'`, con tilde y
abreviada, porque `hasRole()` compara el nombre literal contra la tabla.

> **No es la primera vez que este repo cuelga algo de `Coord académico`: es la
> segunda**, y eso cambia la consecuencia, no la decisión. Lo levantó `8myvc-9d` y lo
> reprodujo `8myvc-29` el 2 sep 2026: `can_view_auditoria` ya se reparte a ese rol
> desde el 25 ago (`2026_08_25_200000_create_permiso_can_view_auditoria.php`, que
> siembra `['Rector', 'Coord académico']`).
>
> **Así que dar ese rol reparte hoy dos cosas y no una**: publicar el horario del
> colegio **y ver el rastro de auditoría de otras personas** —quién cambió qué nota,
> los ingresos ajenos—. Quien ejecute las quince operaciones de la §10.1.11 tiene que
> saberlo, porque son dos permisos en un movimiento. Es la regla del 21 ago por su
> otra cara: **crear un rol no regala permisos, pero dárselo a una persona sí le
> regala todos los que ya cuelgan de él.**
>
> **Y la segunda mitad no cuelga del rol en el código, cuelga de una fila.**
> `Autoriza::puedeVerAuditoria()` no pregunta por ningún rol: lee
> `in_array('can_view_auditoria', $user->perms)`. El acoplamiento es **por dato y por
> colegio** —existe donde aquella migración corrió y nadie retiró la fila, y ella misma
> hace `continue` si el rol no está—, mientras que `puedePublicarHorario` preguntaría
> por el rol directamente. Decir «este rol también ve la auditoría» a secas sería **un
> enunciado más ancho que el dato**: lo cierto es que hoy van juntos en los colegios
> donde esa fila está, y puede no estarlo en el catorce.
>
> **Ojo al leer los tests en verde**: `test-seed.sql` hace `TRUNCATE` de `permissions`
> y `permission_role`, así que en la base de tests **ese acoplamiento no existe**. Un
> test que fabrique el rol para probar `puedePublicarHorario` no hereda
> `can_view_auditoria` — y su verde **no demuestra** que los dos permisos vayan
> separados en producción, donde van juntos.

---

## 6. Qué puede revalidar el servidor, y qué no — y decir que sí sería una respuesta que miente

La regla es buena y no se discute: *el cliente decide bien, el servidor decide si es
legal*. Lo que hay que mirar antes de escribirla es **con qué dato**, porque la mitad
de los datos se acaba de mudar al escritorio.

| Regla dura | ¿La puede comprobar el servidor? | Con qué |
|---|---|---|
| Un grupo, como mucho una pieza por (día, franja) | **Sí** | `asignatura_id → grupo_id`, que ya está |
| Un docente, como mucho una pieza por (día, franja) | **Sí, si la versión sube los docentes de cada pieza** | `horario_pieza_docente`; con `asignaturas.profesor_id` **no**, por el capellán (§5.1) |
| Σ lecciones de una asignación **≤** su IH | **Sí**, y es la más barata — **422** | `asignaturas.creditos`: está en las 134, ninguna vacía |
| Σ lecciones de una asignación **=** su IH | **Sí, pero BLANDA** | renglón del veredicto **con su cuenta**: un horario a medias es una versión legítima |
| Un bloque ocupa casillas consecutivas del mismo día | **Sí** | `dia`, `franja`, `duracion` de la propia fila |
| **Cada asignación es del año de la versión** | **Sí**, y es la cuarta | **por JOIN, no por columna**: `asignaturas` no tiene `year_id`, el año le llega por `grupos.year_id` |
| **El `anio` del cuerpo es el `years.year` de ese `year_id`** | **Sí**, y es la quinta — **422** | el campo `anio` de la §5.2.0; sin él la comprobación **no existe** |
| El `nombre_colegio` del cuerpo coincide | **Sí, pero BLANDA** | renglón del veredicto, nunca puerta cerrada: es texto libre y editable |
| Un salón sin choque | **No se puede decidir** | `capacidad_grupos` no existe aquí: la iglesia con seis grupos es indistinguible de dos grupos metidos en un aula |
| La disponibilidad ✕ respetada | **No** | vive en el fichero de proyecto |
| La franja dentro de la jornada del nivel, sin cruzar descansos | **No** | la rejilla y los timbres viven en el fichero de proyecto |

**La tercera se partió en dos el 2 sep 2026, y el documento ya llevaba las dos formas
dentro.** La §5.1 escribía *«Σ lecciones **≤** IH»* y esta tabla escribía *«**=**»*; no
era una elección pendiente, era una contradicción, y la deshizo una medición del front
sobre el único proyecto real que existe: de las **313** piezas de `lleno.myvch` viajan
**312**, porque una se queda sin colocar y el emisor la avisa con su materia y su grupo.
O sea que hay una asignación que gasta **2 de sus 3 horas** — y eso no es un fichero
roto: **una versión a medias es legítima, que es justo para lo que existen las
versiones**. Con `=` dura y 422, el servidor rechazaría el único fichero real que hay.

Así que **`≤` es la dura** —gastar más horas de las que la asignación tiene es imposible
en cualquier lectura, y eso sí es un fichero mal armado— y **`=` baja al veredicto con su
cuenta**: *«134 asignaciones revisadas · 133 completas · 1 incompleta: 2 de 3 horas
colocadas (Religión de Décimo)»*. Sin la cuenta, «incompleta» se lee como «rota»; con
ella, el coordinador ve lo que le falta por colocar y decide si publica igual. **Es el
mismo criterio que ya se le aplicaba a la asignación sin IH** —no 422, sino nombrada y
contada—, aplicado al caso de al lado: un dato incompleto del colegio no puede convertir
el módulo en inutilizable. *(Pendiente de confirmación de Joseth; si prefiere la dura, es
un operador y un renglón que sube a 422.)*

**La cuarta la levantó `8myvc-e5` el 2 sep 2026, y la abrió una decisión de ese mismo
día.** Mientras subir y publicar valían sólo en el año actual, «esta asignación es del
año de esta versión» era gratis; con la decisión 13 —cualquier año— se puede subir una
versión de 2026 que traiga dentro asignaciones de 2024 y **no falla nada** —y **este es
el único sitio donde esa comprobación existe**: el modelo del escritorio no guarda año por
asignación, el año vive una sola vez en `origen.anioId`, así que el emisor **no puede**
filtrar ni detectar una asignatura de otro año—: las filas
entran, el veredicto sale limpio y la versión parece buena. Lo cobra la §7, que al
marcarla oficial derivaría las columnas **del otro año**. Se rechaza con **422 nombrando
la pieza y la asignación intrusa**. Es el patrón que conviene reconocer: **una decisión
correcta que abre un hueco en otro sitio.**

**La quinta y la sexta llegaron el 2 sep por la tarde, con la decisión de Joseth que
trajo `myvc-front-8e`** (§5.2.0), y son las que cazan **el fichero subido al colegio
equivocado**: la cuarta protege de asignaciones intrusas *dentro* de la versión; la
quinta, de que la versión *entera* vaya al año de otro colegio. Se separan en dura y
blanda a propósito — el año **identifica**, el nombre del colegio **describe**, y un
colegio que se renombró legítimamente no se puede quedar sin poder subir su horario.

Y dos más de la misma familia, del esquema: **una asignación borrada** —hay 240 en la
papelera— también es 422 nombrado, porque dejarla entrar mete basura en la versión y
calcular Σ = IH sobre las vivas con una pieza apuntando a ella **descuadra sin
explicación posible**; y **`creditos` es `int DEFAULT NULL`**, así que la Σ = IH puede
no evaporarse sino desaparecer: `SUM(...) = creditos` con un `NULL` dentro **no da
falso, se cae del resultado**, y en PHP el `==` acusa a quien no tiene culpa. **Las dos
lecturas son malas y ninguna hace ruido.** Medido: **0 de 1219 asignaturas vivas** de
este colegio tienen la IH nula, en los nueve años — pero de los otros catorce no se sabe
nada. Se resuelve **sin 422**: la asignación sin IH va al veredicto como **NO
COMPROBADA, nombrada y contada**, porque un 422 convertiría un dato incompleto del
colegio en un módulo inutilizable.

Las tres últimas son el asunto. **Un `if` que comprueba la disponibilidad contra un
dato que el servidor no tiene no falla nunca**: pasa siempre, se ve verde y no
comprueba nada. Es el patrón que este repo ya tiene medido del otro lado —métodos que
frenan la escritura y contestan 200 igual, `tools/respuestas-que-mienten.py`— y aquí
saldría en la dirección contraria y más cara: una versión ilegal aceptada con un
«validado» encima.

Así que hay dos salidas honestas, y **la elegida es la B**:

- **A. La versión sube también la configuración como datos** —rejilla y jornadas por
  nivel, salones con su capacidad, disponibilidades— y no sólo dentro del blob. El
  servidor revalida las seis. Cuesta que el contrato del `POST` sea bastante más
  grande y que esos datos se dupliquen.
- **B. El servidor revalida las tres que puede y lo dice en la respuesta**, con las
  otras tres nombradas como no comprobadas. Cuesta menos y **no miente**, que es el
  requisito.

Lo que no es una salida es aceptar y no decir nada.

**Joseth eligió B el 2 sep 2026**, que es lo que recomendaban las dos sesiones. El argumento
del front es el bueno: A le mete al servidor un modelo de disponibilidades y
capacidades **que sólo existiría para revalidar**, y el día que se desincronice del
proyecto del escritorio la revalidación **mentiría con más autoridad que ahora**. Este
repo pone dos razones propias: `app/` va **copiado colegio a colegio**, así que un
modelo que sólo se toca para revalidar envejece en quince sitios a la vez; y una tabla
que no edita ninguna pantalla es justo lo que `tools/interruptores-que-nadie-lee.py`
está ahí para encontrar. A tendría sentido el día que exista una pantalla web que
**edite** el horario, y ese día ya es otro diseño.

**Y B vale sólo con su condición**, que es la que la separa de no comprobar nada: el
veredicto **se guarda con la versión** (`horario_versiones.comprobaciones`) y **dice su
población**, no los nombres de las reglas. No «comprobado: grupo, docente, IH», sino
*«345 lecciones y 134 asignaciones revisadas · grupo ✓ · docente ✓ sobre los docentes
que trajo la versión · Σ ≤ IH ✓ · 133 de 134 completas, 1 con 2 de 3 horas ·
salón NO COMPROBADO, falta `capacidad_grupos` ·
disponibilidad NO COMPROBADA, vive en el proyecto · jornada NO COMPROBADA»*. Un
veredicto sin población es otra vez el `[]` de la §2: **se lee como «todo bien»**. Y la
población **sale de esa corrida, no del código**: 345 y 134 son cifras de
`simonbolivar`, y escritas a mano dirían 345 en el colegio catorce habiendo mirado 200
— que es exactamente la mentira que la opción B existe para impedir. Con
él guardado, «esta versión no se comprobó contra las disponibilidades» pasa a ser un
dato del historial y no la suposición de nadie.

Y dos formas, sea cual sea la elección:

- **La versión entra entera o no entra.** Una transacción, y un **422** con las
  lecciones culpables enumeradas si algo falla. Una versión a medias es peor que
  ninguna: parece un horario.
- **El error dice su población.** «Hay choques» no distingue *«revisé las 345 y
  encontré tres»* de *«me rendí en la primera»*. Van los tres, con grupo, docente,
  día y franja.

---

## 7. Las siete columnas de día: opción A, y su límite está medido

Sigue la **opción A** de la v1 —**derivar**, no sustituir—, pero ahora la fuente son
las lecciones de la versión **oficial**, no una tabla `fichas` que ya no existe:

- Al **marcar una versión como oficial** (no al subirla: hasta ahí no debe cambiar
  nada de lo que ve el docente), un servicio recalcula las siete columnas de cada
  asignación desde las lecciones de esa versión.
- `ChangeAskedController` **no se toca**. La pantalla vacía de la §2 se llena sola.

**El alcance de esa derivación es el año entero, y no las filas de la versión.** Lo
levantó `8myvc-9d`: leído literal, «recalcula las columnas de cada asignación desde las
lecciones de esa versión» sólo escribe las que aparecen — así que si la versión 2 quita
las tres horas de Sociales de Décimo que traía la versión 1, esa asignación **se queda
con el `martes = 1` de la anterior** y el docente sigue viendo una clase que ya no
existe, salida de una columna que nadie volvió a tocar. Se pone **todo el alcance del
año a 0 y luego a 1 lo que trae la versión**, en la misma transacción.

> **Y ese alcance tiene la misma trampa de esquema que la cuarta comprobación de la §6:
> `asignaturas` no tiene `year_id`.** El año le llega sólo por `grupos.year_id`, así que
> «las asignaciones de este año» es un **JOIN** y no un `WHERE`. Equivocarse ahí mientras
> se publica un año cerrado significaría **poner a cero las columnas del año abierto**, y
> con la decisión 13 eso no es teórico.

**Y el límite, que hay que escribir antes de que alguien lo prometa:**
`asignaturas_dia` ordena `by g.orden, a.orden, m.materia, m.alias, a.id`
([línea 1236](../../app/Http/Controllers/ChangeAskedController.php#L1236)). No hay
franja en esa consulta y no hay dónde meterla sin cambiar la respuesta. Así que la
opción A le da al docente **qué** clases tiene hoy, nunca **en qué orden**. La frase
de la v1 —*«las ve en el orden en que las va a dar»*— **es de la opción B**, y
escribirla debajo de la A sería prometer lo que el código no hace.

Dos cosas más que van con la A y no se pueden dejar para después:

- **El fallo del sábado de la §2.1**, que hoy es invisible y se estrena con las
  columnas llenas.
- **El dato derivado necesita quien lo vigile.** Un test que ate columnas ↔ lecciones
  de la oficial, y —si se quiere saber si los quince están sincronizados— una
  herramienta de `tools/` que **diga su población**: cuántas asignaciones miró,
  cuántas cuadran y cuántas no. Un «0 descuadradas» sin población no distingue
  *revisé las 134* de *no había versión oficial*.

### 7.1. Escrito el 2 sep 2026, y las dos cosas iban juntas

`putOficial` dejó de ser 501: marca el puntero y deriva las siete columnas **en la
misma transacción**, y el fallo del sábado entró en el mismo commit. `ChangeAskedController`
no se tocó por lo demás — la pantalla vacía de la §2 se llena sola.

**El alcance se escribió como UN solo `UPDATE` en vez de los dos pasos** («todo a 0 y
luego a 1 lo que trae la versión»). El resultado es el mismo y la propiedad es más
fuerte: con dos pasos, «poner a 0» y «poner a 1» son **dos sitios donde el alcance
puede dejar de ser el mismo**, y el día que se separen media tabla se queda a cero sin
que nada lo diga. Cada fila del alcance recibe sus siete columnas escritas de nuevo
siempre, y lo que la versión no trae sale 0 porque el `EXISTS` es falso, no porque haya
un segundo `UPDATE` que se acordó de ella. Es `EXISTS` y no una tabla derivada porque un
multi-tabla `UPDATE` contra una derivada **no vale en MySQL 5.7**, y de los quince
colegios no está verificada la versión de ninguno.

**Y la derivación es inmune a la misa por construcción, que es lo que avisó `8myvc-9c`.**
En la subida, la ocupación se indexa por `pieza_id` para que una pieza de varios grupos
no se declare choque a sí misma; aquí las filas ya no vienen del cuerpo sino de
`horario_lecciones`, donde esa misa son **N filas con el mismo `pieza_id`**. `EXISTS`
contesta *sí o no* y no *cuántas*, así que las N asignaciones quedan con su día puesto
una vez cada una — que es lo correcto: **la pieza es una y las clases son N**. El día
que esto pase a contar en vez de a comprobar, ahí vuelve a hacer falta el `pieza_id`.

La respuesta **dice su población** —alcance, asignaciones con algún día, filas **y
piezas** por separado, y el reparto por día—, y una cifra de ellas no es informativa:
`asignaciones_de_la_version_fuera_del_alcance`. La cuarta comprobación de la §6 mira que
cada asignación sea del año **el día que se sube**, y publicar es otro momento; entre los
dos alguien puede borrar una asignatura o mover su grupo, y esas filas **no entran en el
alcance**, así que su día no se escribe y el horario pierde esas clases en silencio. Se
cuentan y salen; convertirlas en 422 sería impedir publicar por algo que pasó después de
validar, y eso es decisión del colegio.

### 7.1.bis. De dónde saca el año cada una de las tres rutas — y por qué hay que decirlo

Lo levantó `myvc-horarios-83` el 3 sep 2026 **mirando los tres controladores**, que es
hoy la única forma de saberlo:

| ruta | método de `HorarioController` | de dónde sale el año |
|---|---|---|
| `POST horario/versiones` | `postVersiones()` | `$cuerpo['version']['year_id']` — el año del **PROYECTO** |
| `GET horario/versiones` | `getVersiones()` | `$this->user->year_id` — el año del **TOKEN** |
| `PUT horario/versiones/{id}/oficial` | `putOficial()` | `$version[0]->year_id` — el año de la **FILA** |

> **El ancla es el método y la expresión, no el número de línea, y eso costó
> escribirlo mal una vez.** Esta tabla se empujó citando `:196`, `:826` y `:981`, y
> **los tres estaban ya viejos al empujarlos**: el `try`/`catch` que cerró el `motivo`
> del 422 —del **mismo commit**— desplazó el fichero, y los buenos eran 197, 852 y
> 1007. Una línea citada envejece con cualquier edición de arriba, no da error al
> envejecer, y **lleva a leer otra cosa que se parece**. `grep` de la expresión sí
> sobrevive. *(Los números medidos el 3 sep 2026 sobre `0faf099`, por si alguien
> quiere rehacerlo: 197, 852 y 1007.)*

**Cada uno por separado es correcto y tiene su razón escrita** donde se decidió: el `POST`
valida contra el año que el proyecto declara; el `GET` no acepta un `year_id` de fuera
porque sería un identificador que no comprueba nadie; el `PUT` usa el de la versión porque
publicar es una operación sobre esa fila.

**Juntos son otra cosa, y el modo de fallo no se parece a un fallo.** Una pantalla que dé
por hecho que el año es el mismo en las tres: sube una versión del año 5 —correcto, va al
año del cuerpo—, el listado de debajo enseña las del año 8, y **la recién subida no
aparece**, que se lee como que la subida falló. Y lo peor viene después: lo que se marque
oficial desde esa lista **se publica en el año del token**, no en el del horario que el
coordinador tiene delante, y **el servidor lo acepta porque para él es coherente**. Ni
4xx, ni aviso, ni nada que se ponga rojo en ninguno de los dos lados.

**No se unifican**, y esa es la decisión: cada origen tiene su motivo y cambiarlos ahora
rompería clientes. Lo que hacía falta era **esta tabla**, porque hasta hoy la respuesta
exigía leer tres controladores.

**Y del lado del cliente ya hay precedente, que no pide nada a esta API**: `myvc_horarios`
compara el `year_id` del envoltorio de `getVersiones` con el del proyecto y **bloquea
publicar** mientras no cuadren. Avisar sin bloquear no valía — las demás causas de un botón
apagado se ven mirando la pantalla, y ésta no. Es, de paso, el segundo uso que le sale al
`year_id` del envoltorio que este contrato estuvo a punto de dejar como un array pelado.

### 7.2. `acepto_perder` — la decisión del colegio, tomada el 2 sep 2026

**Joseth la aprobó**, así que el párrafo de arriba deja de describir el código y pasa a
describir por qué la pregunta llegó a hacerse. Lo propuso el equipo de `myvc_horarios`.

Hoy la deriva **cierra la puerta** en `putOficial` en vez de salir en un campo de una
respuesta de éxito, que es donde no mira nadie:

| Cuerpo | Lo que cuenta el servidor | Respuesta |
|---|---|---|
| sin `acepto_perder` | 0 | **200**, publica — el camino normal no pasa por el campo |
| `acepto_perder: 0` | 0 | **200**, publica |
| sin `acepto_perder` | N > 0 | **422 `perdida-no-aceptada`**, con `asignaciones_que_se_pierden: N` y el número dentro del `message` |
| `acepto_perder: M` | N, con M ≠ N | **422 `acepto-perder-no-coincide`**, con `acepto_perder: M` **y** `asignaciones_que_se_pierden: N` |
| `acepto_perder: N` | N | **200**, publica y pierde esas N a sabiendas |
| `acepto_perder: true` (o `"2"`, o `false`) | — | **422 `acepto-perder-no-es-un-numero`** |

**Por qué un número y no un `forzar: true`, que es toda la decisión.** Un booleano no caza
la deriva: dice «adelante pase lo que pase», así que el día que se pierdan treinta en vez
de las dos que el coordinador vio en pantalla, pasa igual — y acaba puesto por costumbre,
porque nunca estorba. Un número **tiene que coincidir con el que el servidor cuenta en ese
mismo instante**, así que sólo lo puede acertar quien acaba de mirar; si la realidad se
movió entre mirar y confirmar, deja de coincidir y el cliente vuelve a mirar.

**Rebota también el número de más**, incluido `acepto_perder: 1` cuando no se pierde nada.
Es la mitad que parece de sobra y es la que impide que el campo se vuelva decorativo: un
cliente con una constante puesta pasaría los días que la deriva midiera eso y ninguno más.

**El 422 nombra los DOS números**, y eso lo pidió `myvc-horarios-5e` con su razón: su
pantalla se lo tiene que explicar a un coordinador de colegio, y *«esperaba 32, mandaste
28»* se explica y se puede comprobar contra lo que hay en pantalla; *«no coinciden»* sólo
se puede creer.

**Y «releer» NO tiene ruta, que es lo que reencuadra toda esta puerta.** El `message` del
`acepto-perder-no-coincide` decía *«vuelve a leer el listado y confirma con la cifra que
salga»* hasta que `myvc-horarios-83` fue a escribir esa relectura y descubrió que **no
existe**: `getVersiones` no devuelve la deriva —su `comprobaciones` es el veredicto guardado
**el día de la subida**, no una cuenta de hoy—, así que **la única lectura fresca es el propio
422**. Mandar a una pantalla a buscar un número que allí no está es peor que no decir nada: se
busca, no se encuentra, y se acaba tecleando el que se recuerde. Corregido.

Y el reencuadre es a mejor: **la garantía no es «el número vino de otro sitio» —no hay otro
sitio— sino que hay una persona en medio cada vez**, porque no se puede saber la cifra sin
provocar el 422 que la enseña. Así que la redacción del mensaje **no es un adorno alrededor
del mecanismo: es el mecanismo.** Y de paso estrecha el agujero conocido —que un emisor
remande el número del error—: explotarlo exige **provocar un 422 por cada intento**, que es un
argumento **en contra** del testigo de un solo uso que no se tenía cuando se planteó.

**La comprobación va dentro de la transacción** —contar fuera y escribir dentro son dos
instantes, y entre ellos cabe una asignatura más— y `abort()` desde ahí **deshace**, así
que el «Nada se escribió» de los tres mensajes es cierto y no una promesa. Está medido, no
razonado: moviendo la puerta a **después** de los dos `UPDATE`, los doce casos siguen en
verde. O sea que las escrituras ocurrieron y el rollback las quitó.

**Test**: `tests/Contrato/HorarioAceptoPerderTest.php`, 12 casos, **vistos rojos en dos
direcciones distintas**: con la puerta desactivada caen 9, y con la puerta convertida en un
`forzar: true` —cualquier valor presente vale— caen 7, que son exactamente los tres de
`no-coincide` y los cuatro de `no-es-un-numero`. El segundo control es el que importa: sin
él, «el campo es un número y no una bandera» sería una frase de un comentario.

**El test obligatorio ya existe**: `tests/Contrato/HorarioOficialTest.php`, 15 casos.
Ata columnas ↔ lecciones en las dos direcciones, recorre **los siete días uno a uno**
—un convenio corrido no da error en ningún sitio— y publica el año actual **teniendo el
vecino con columnas puestas**, que es lo único que caza el JOIN. El del sábado congela el
reloj y mira **los dos lados**: sábado vacío *y* domingo lleno, porque un «vacío» solo es
indistinguible de un endpoint roto. La herramienta de `tools/` sigue siendo opcional y no
se ha escrito.

---

## 8. La frontera nueva: el escritorio se tiene que poder vender sin MyVC detrás

Llegó el 2 sep por la tarde: Joseth quiere poder **vender el programa por licencia** a
colegios que no son clientes de MyVC, y el front lo fijó como *«ninguna pantalla le
pide un dato al servidor de MyVC»*. Para este repo son tres consecuencias, y sólo la
tercera es trabajo:

1. **El backend no gana pantallas ni rutas por esto.** Siguen siendo las de la §5.3
   y la pantalla web de elegir la oficial, que es del front.

   > **Y son cuatro desde el 4 sep 2026, no tres — pero la frase de arriba no se cae,
   > porque el «por esto» es lo que la sostiene.** `GET horario/versiones/{id}/lecciones`
   > (§9.bis) entró por la razón contraria a esta frontera: es para **mirar dentro de
   > MyVC** lo que el escritorio cuadró. Un colegio que compre sólo el programa no la
   > llama nunca, igual que no llama a las otras tres.
2. **La bajada de datos es una importación opcional, no la fuente.** El diseño del
   cliente dice a la vez que el escritorio «baja año, grupos, docentes y asignaturas
   con IH — las mismas lecturas que hoy usa la planilla» y que ninguna pantalla le
   pide nada al servidor. Las dos sólo caben juntas si la bajada es **un botón de
   importar** y todo lo demás funciona sin él.
3. **Y la que sí es nuestra: `asignatura_id` es una clave de MyVC.** Un proyecto
   armado sin MyVC no tiene `asignatura_id` de nada, así que **no se puede subir** — y
   eso está bien, no hay que arreglarlo. Lo que hay que escribir es que la ruta lo
   **diga**: un **422** nombrando la pieza que viene sin asignación. Las dos salidas
   que hay que cerrar de antemano son aceptar nulos —que dejarían «Clases de hoy»
   igual de vacía que en la §2, esta vez con un horario oficial encima— y **emparejar
   por nombres**, que es la que parece amable: «Matemáticas de 3°A» y «Matemáticas de
   3°B» se parecen lo suficiente como para que un emparejador acabe metiendo las horas
   de uno en el otro, y eso no da error, da un horario equivocado.

---

## 9. Lo que queda vivo de la v1, y dónde vive ahora

| De la v1 | Dónde vive en la v2 |
|---|---|
| El pre-vuelo en tres niveles (aritmética, flujo máximo, intentarlo) | escritorio — **menos el nivel 1, ver abajo** |
| Los pesos de las blandas y que sean dato y no `if` | escritorio, en el fichero de proyecto |
| Las cuatro fases del generador (construcción, recocido, reparación) y que nada mueva lo fijado | escritorio, en un Web Worker |
| La iglesia como **tres** reglas (sincronizar, capacidad, excepción de docente) | **una**: con `grupos[]` y `docentes[]` dentro de la pieza, dos desaparecen (§5.1) |
| «No hay horario» tiene que decir su población | **las dos mitades**: el generador allí, el 422 aquí (§6) |
| Que `dia` sea un entero y no una fecha | igual: un horario es semanal y se repite; el lunes del simulacro es `calendario`, no horario |

**Y una cosa del pre-vuelo sí es de aquí y no necesita ni rejilla, ni escritorio, ni
una ruta:** el nivel 1 sobre los datos que ya hay. Contesta la pregunta que puede
tumbar el proyecto entero — **si los datos de los quince colegios sirven**.

### 9.1. Corrido sobre `simonbolivar` el 2 sep 2026, y esto es lo que dice

**Ningún docente es imposible con la rejilla de 7 × 5.** Los 12 caben en las 35
casillas, y el más cargado —31 horas— tiene 4 de holgura. Con la rejilla de 6 × 5 que
supuso la v1, ese mismo docente **no tenía horario**: es el supuesto que costaba el
proyecto, y lo deshizo un pantallazo.

    31  ·  30  ·  28 × 6  ·  27  ·  26  ·  23  ·  15      (Σ IH por docente, techo 35)

Los grupos también caben: 30 en bachillerato, 25 en primaria, 20 en preescolar, todos
por debajo de 35 — aunque la holgura real de cada uno depende de **su** jornada, que es
el dato que falta (§10.1).

**Y el hallazgo, que no es de holgura sino de personal:** las **10 asignaciones sin
docente son las 10 de preescolar**, y no están repartidas — **Transición tiene 7 de 7
sin docente**, o sea **el grupo entero**, y Jardín 3 de 7. Son 25 de las 345 horas.
Dicho como lo diría el pre-vuelo: *el horario de Transición no se puede colocar en
absoluto, porque ninguna de sus siete asignaciones tiene a quién poner en la casilla.*
No es un fallo del horario, es un dato del colegio que hoy no lo enseña nadie — y
encaja con la §2: las dos únicas filas con día marcado en las 134 son de Transición y
las dos tienen `profesor_id` nulo.

**Y hay una segunda consecuencia que no se ve mirando sólo a Transición: preescolar sin
docentes impide comprobar el resto.** Lo midió el front el 2 sep: mientras Jardín y
Transición dejan sucio el nivel 1, **el nivel 2 —el emparejamiento, la condición de
Hall— no se ejecuta nunca**, porque no tiene sentido buscar imposibilidades finas
mientras hay una gruesa sin resolver. Repartiendo las diez huérfanas a mano *—una
mentira, y por eso detrás de un interruptor—* el nivel 2 corre por fin contra datos
reales y el colegio pasa. O sea que esas diez asignaciones no bloquean un grupo:
**bloquean el diagnóstico de los trece.**

Como script de `tools/` corre sobre los quince y contesta en una tarde en vez de en la
demo. Si en otro colegio la IH está a medias, se sabe antes de prometer nada.

### 9.2. Y ya es un script: `tools/prevuelo-del-horario.php`

Escrito el **2 sep 2026**. Reproduce las siete cifras del control de la §9.1 sobre
`simonbolivar`, año 8 —13 grupos, 134 asignaciones, 12 docentes, Σ 345, 0 sin IH, 10
sin docente que son 25 h, y el más cargado con 31 de 35—, y también el reparto:
**Transición 7 de 7 y Jardín 3 de 7**, con las diez nombradas una a una en `--detalle`.

    docker exec 8myvc-app-1 php tools/prevuelo-del-horario.php
    docker exec -e DB_DATABASE=otrocolegio 8myvc-app-1 php tools/prevuelo-del-horario.php --csv

**La rejilla entra por `--lecciones` y `--dias`, y la cabecera del informe imprime
contra cuál se midió.** Corriéndolo con `--lecciones=6` sale el tercer hallazgo que la
v1 daba por bueno —*«JOEL HERNÁNDEZ tiene 31 h y sólo caben 30: NO tiene horario
posible»*—, o sea que el supuesto que costaba el proyecto **es ejecutable en los dos
sentidos** y no una nota. Está fijado además en el control (`--control`), que corre
sin base y lo invoca `tests/Unit/AutopruebasDeLasHerramientasTest`.

La jornada por nivel de la [§10.1](#101-cerradas-por-joseth-el-2-sep-2026--no-se-re-litigan)
entra por `--lecciones-nivel=Preescolar:5,Primaria:6`, y la clave es el `abrev` de
`niveles_educativos` **como esté en la base**: en `simonbolivar` hay **cuatro** niveles
—Preescolar, Primaria, Secundaria y Media—, no los tres de la decisión, así que
traducir «bachillerato» a dos de ellos es de cada colegio. Un nivel que no exista
aborta en vez de ignorarse.

**Tres códigos de salida, y el tercero es el que importa en el bucle de los quince:**
`0` limpio, `1` sucio, `2` **NO MEDIDO** — que cubre también los abortos de parámetro,
porque `--lecciones-nivel` se escribe una vez y se corre quince y los niveles se llaman
distinto en cada colegio. Un colegio no medido no es un colegio limpio.

Y lo que el script añade a la §9.1, que son las preguntas que en `simonbolivar` dan
cero y de los otros catorce no se sabe: asignaciones con la **IH nula** —que no se
evaporan, desaparecen del `SUM`, y el total sale cuadrado habiendo mirado de menos—,
con IH **0**, con el docente **borrado** o **inexistente**, con la **materia en la
papelera** —las de la decisión abierta 1 de la §10.2, que `GET asignaturas` no
devuelve— y grupos **sin ninguna asignación**. Los JOIN de profesor y materia son
`LEFT` a propósito: con `INNER`, una asignación cuyo docente esté borrado
desaparecería de la población y el informe saldría más limpio de lo que es.

---

## 9.bis. La cuarta ruta: leer las lecciones para PINTARLAS — decidida a medias el 3 sep 2026

**Lo que Joseth decidió**, y no se re-litiga:

1. **El horario que se cuadra en el escritorio se tiene que poder MIRAR en un menú de la
   web**, sin generar nada allí.
2. **La web LEE DE LA API**; no se descarga el proyecto. La §10.2 se queda para lo que
   era —llevarse el proyecto a otro computador—, que es otra ruta y otro permiso.
3. **El permiso es `auth.personal`**, el mismo que el listado. O sea **cualquiera de los
   53 docentes**.

> **La 3 se tomó sabiendo lo que tensiona.** Esta ruta le entrega a cualquier docente el
> horario del colegio entero —qué docente está dónde a cada hora—, y eso empuja la regla
> escrita *«listar no es descargar»* hasta *«mirar no es llevarse»*. **Lo que decidió fue
> un hecho aportado por `myvc_horarios`, no un argumento**: el horario del colegio **ya se
> imprime y se cuelga** —13 hojas apaisadas, medidas en el programa instalado—, así que en
> papel no es un secreto para nadie de dentro.

**Lo que NO está decidido**: la forma exacta de la respuesta. Y **el router no se mueve
hasta que se escriba**: una ruta que no existe no puede tener número, porque nadie lo
puede contar hasta que exista.

### 9.bis.1. Lo que falte tiene que llegar MARCADO como que falta, y esto está medido

**Es la restricción que decide la forma, y no es una preferencia.** La midió
`myvc-horarios-90`: **5 mutilaciones × 8 informes × 3 colegios = 120 corridas**, más las
ocho plantillas leídas a mano —porque *«cambia y no se nota»* no se contesta ejecutando—.

| qué hace el informe si le falta un dato | cuántos |
|---|---|
| avisa solo | **2 de 8** |
| se nota que falta, sin decir por qué | **1 de 8** |
| **sale plausible y falso** | **4 de 8** |

Los cuatro, con su cifra: **75 casillas pierden el salón** —su `?? null` hace que *«no lo
sé»* y *«no tiene»* sean el mismo `null`—, otras **98 cambian el dibujo de salón a
materia**, **15 pasan de «no lectiva» a «libre»** —la hoja da por libre una hora que para
ese grupo no existe— y **75 motivos de «sin colocar» cambian de tipo**, o sea que el papel
da una razón **concreta y falsa** de por qué una lección no cabe.

**Y el peor: un centinela que se apaga justo en su caso.** El aviso *«sin horas: el
colegio todavía no ha dado los timbres»* pregunta *¿tengo timbres?* en vez de *¿me los dio
el colegio?*. Si nuestra respuesta reconstruye la jornada por defecto, **el aviso
desaparece** y la hoja imprime `06:45 – 07:35` en un nivel que nunca dio esa hora: **15
hojas pasan de avisar a no avisar**.

**La consecuencia para el diseño, en una línea:** *un catálogo que no viaja y un catálogo
vacío no pueden ser la misma cosa al otro lado.* Si la respuesta puede decir **«este
catálogo no lo tengo»** en vez de mandarlo vacío, los casos falsos se convierten en los
que avisan solos.

### 9.bis.1.a. Y eso NO basta: el caso que va a ocurrir de verdad es el catálogo A MEDIAS

**Corregido al alza el 3 sep 2026**, y la corrección va aquí porque el párrafo de arriba
**presupone que sabemos que falta algo**. Medido por `myvc-horarios-90` sobre **144
corridas** —3 colegios × 6 mutilaciones × 8 informes—, y el desglose bueno tiene **cuatro**
categorías, no tres:

| | corridas |
|---|---|
| salen igual | 76 |
| salen distinto **con un aviso que se enciende** | 5 |
| salen distinto **sin ningún aviso** | 55 |
| **salen distinto Y ADEMÁS SE LES APAGA UN AVISO QUE ESTABA ENCENDIDO** | **8** |

**Las 8 son la categoría que decide**, y no es «la hoja sale mal en silencio»: es que **la
hoja sale mal y encima deja de avisar de lo que antes avisaba**. Quien confíe en el aviso
queda **peor** que si no lo hubiera.

**Y el catálogo a medias hace MENOS ruido que el ausente**, que es lo contrario de lo que
uno esperaría:

- **Salones fuera del todo** → `horario-por-salon` saca **0 hojas**. Un documento vacío
  **se nota**, y obliga a alguien a preguntar.
- **Salones a medias** → **6 hojas se quedan en 3**, `ocupadas` baja de **88 a 50**, y
  **cero avisos**. Un informe entero, bien maquetado, al que le falta la mitad. Y los otros
  tres pierden el salón en **menos** casillas —38 en vez de 75—, o sea que hasta el síntoma
  encoge.

**A medias es exactamente lo que devolvería nuestra ruta**, porque nuestra base guarda unas
cosas y no otras por decisión de la §0. Así que la regla se endurece:

> **No basta con mandar lo que tenemos: la respuesta tiene que poder decir que lo que manda
> está INCOMPLETO.** `salones: []` y `salones: «no los tengo»` no pueden ser la misma cosa
> al otro lado — hoy allí **se imprimen igual**.

### 9.bis.1.b. Y una técnica de arnés que sale de aquí y vale para cualquier comparador

**El arnés que midió esto se equivocó sobre sí mismo dos veces en un día, y las dos las
destapó correr con datos nuevos, no releer.**

1. La primera versión contaba **cualquier aviso que se moviera** como «bien avisado», así
   que marcaba el peor caso —el centinela pasando de encendido a apagado— como **bueno**.
   **El instrumento etiquetaba como correcto justo el fallo que venía a cazar.**
2. La segunda sacó *«la materia cambió de Tecnología a Educación Física»* y *«el docente
   cambió»*, **17 veces cada uno**. Falso: el informe pasó de 6 hojas a 3 y el comparador
   **alineaba por índice**, o sea que comparaba la hoja 1 contra otro salón. Al informe no
   le cambia el docente — **le faltan tres hojas**.

**La técnica que lo arregla, y es de forma y no de cuidado**: *cuando los largos difieren,
**el largo ES el hallazgo** y no se compara dentro.*

> Y el porqué, que es lo que hay que llevarse: ese segundo fallo habría salido de un canal
> entre repositorios **como un fallo del producto** —creíble, concreto, con nombres y
> apellidos dentro— y habría costado buscar un cambio de docente que nunca ocurrió.
> **La defensa no es mirar mejor, es no poder.**

Esto muerde aquí y no allá porque **nuestra base no guarda salones, disponibilidad,
franjas ni restricciones** — la §0 decidió que no las guardara. Así que lo que podemos
devolver es **un `Proyecto` incompleto por construcción**, y la pregunta no es si se puede
sino **si lo que falta se puede nombrar**.

### 9.bis.2. Lo que ya está medido y ahorra discusiones

- **El peso no decide nada**: el proyecto entero de un colegio de 13 grupos son **74 836 b
  / 6 608 gzip**; el recorte a lo que los ocho informes usan, **59 801 / 6 190**. Un 14 %
  entre los siete que necesitan el horario, así que **partir la ruta por ahorrar bytes no
  tiene sentido**. Si se parte, se parte **en dos** —el horario y las declaradas— y nunca
  en ocho.
- **Cuatro de los ocho informes necesitan las siete listas**, y **los tres que van a la web
  son tres de esos cuatro**. Eso cierra «¿por grupo o por docente?» sin que nadie opine:
  *«quién está libre»* es una pregunta **sobre todos los docentes a la vez**.
- **`disponibilidad-declarada` no necesita el horario** —`niveles` y `docentes`, 1 890 b—,
  así que **es el único de los ocho que se puede servir hoy**, sin esta ruta y sin esperar
  al despliegue.
- **`docentes.tono`** —el color del docente— hace falta para seis de los ocho y **ninguno
  de los 22 lo trae hoy**. Es **opcional para que salga una hoja y obligatorio para que
  salga la MISMA hoja** que en el escritorio: sin él la web pinta los colores cambiados y
  **nada se pone rojo**.

> **Y una comparación que hay que no hacer**, porque es la que sale sola: el cuerpo de la
> subida son **231 135 b** y esto **no** significa que la bajada sea cuatro veces más
> barata. Son **dos direcciones, dos esquemas y dos colegios**: la subida expande las
> piezas colocadas a las filas que escribimos, y la bajada trae **el vocabulario con el que
> se pinta** —docentes, grupos, salones, niveles— que la subida no manda porque ya lo
> tenemos.

### 9.bis.3. Qué puede devolver esta base, medido — y las tres formas de vacío

**Medido el 4 sep 2026 sobre `8f59242`**, base `simonbolivar` del docker, **año 8** y su
versión **oficial 6** —la que subió `myvc-horarios-f3` con datos reales—. **La población
es un colegio, un año y una versión: 312 lecciones.** Lo que esta tabla diga de los otros
quince es **no medido**, y no se puede medir desde aquí: cada colegio tiene su base.

| lo que el escritorio pinta | ¿lo tiene esta base? | de dónde | medido en el año 8 |
|---|---|---|---|
| **lecciones colocadas** | **sí, entero** | `horario_lecciones` + `horario_pieza_docente` | 312 filas · 312 piezas · día **1..5** · franja **1..7** · **32** con `duracion > 1` |
| **grupos** | **sí** | `grupos` | 13 vivos, y **13 de 13** tienen alguna lección |
| **asignaciones** | **sí** | `asignaturas` (`creditos` = IH) | 134 vivas · **134 de 134** con lección · Σ IH **345** contra **312** colocadas · **0** con `creditos` nulo · **10 sin `profesor_id`** |
| **materias y su alias** | **sí** | `materias` | 35 vivas, **35 de 35 con `alias`** — así que `alias_materia` no es un campo raro: es el caso normal |
| **niveles** | **sí** | `grados` → `niveles_educativos` | 16 grados · 4 niveles |
| **docentes** | **sí la identidad, NO el `tono`** | `profesores` | 47 vivos · **12** con asignación en el año · `tipo_profesor` es **nulo en 42 de 47**, así que **no sirve hoy para decir quién es docente** |
| **salones** | **a medias, y sólo como texto** | `horario_lecciones.salon` | **87 de 312** lecciones traen salón · **3 nombres distintos** (`Sala de sistemas` 28, `Laboratorio` 33, `Cancha` 26) · **no hay tabla `salones`**, así que **no hay `salon_id` que mandar** |
| **timbres / rejilla / jornada por nivel** | **no** | — | `years.minu_hora_clase` = **50** y `years.jornada` = **`'Mañana y tarde'`**. Ni horas de inicio, ni descansos, ni jornada por nivel |
| **disponibilidad declarada** | **no** | — | no existe en el esquema (§4) |
| **restricciones · pesos · colores** | **no** | — | viven en el blob del proyecto (§4) |

**Y el `tono` no está «vacío»: no existe la columna.** Las 27 columnas de `profesores`
salen listadas arriba y ninguna es un color. O sea que el hallazgo de
`myvc_horarios` —«ninguno de los 22 docentes lo trae»— y el nuestro **son dos cosas
distintas que se leerían igual**: allí el dato **está previsto y vacío**; aquí **no hay
dónde ponerlo**. Un `tono: null` que salga de esta ruta no significa «el colegio no lo ha
puesto», significa **«esta API no puede saberlo»** — y ésa es exactamente la diferencia que
la respuesta tiene que poder decir.

**El caso «a medias» de la §9.bis.1.a no es una hipótesis aquí: es lo que hay.** 87 de 312
con salón y 3 nombres, contra los **17 salones** del proyecto real que midió el front. Es
la misma forma que midió `myvc-horarios-90` —6 hojas se quedan en 3, `ocupadas` de 88 a 50,
**cero avisos**—, sólo que ya con datos nuestros.

#### Las tres formas de vacío, y la tercera la obliga la decisión de Joseth

`myvc_horarios` pidió dos —**`[]` no puede ser lo mismo que «no lo tengo»**—. Con la
restricción nueva de Joseth (*el horario es opcional; lo obligatorio es crear asignaturas
con IH*) hacen falta **tres**, porque si no, un colegio que legítimamente no quiere salones
se lee como un colegio al que le falta un dato:

| estado | qué significa | ejemplo medido |
|---|---|---|
| `completo` | lo guardamos y está todo | `grupos`: 13 de 13 |
| `parcial` | lo guardamos y **hay menos de lo que la versión usa** — con su población al lado | `salones`: 87 de 312, 3 distintos |
| `vacio` | lo guardamos, **el colegio no ha creado ninguno, y eso es una respuesta legítima** | un colegio sin un solo salón nombrado |
| `sin_catalogo` | **esta API no puede saberlo, hoy ni nunca por este camino** | `timbres`, `disponibilidad`, `tono` |

**`vacio` y `sin_catalogo` separados es lo que impide que la ruta convierta el horario en
obligatorio.** Sin esa distinción, la única forma de que la pantalla no mienta sería exigir
que el colegio rellene salones y timbres — que es justo lo que Joseth dijo que **no** puede
pasar. Con ella, un colegio que sólo tiene asignaturas con IH recibe un **200** correcto con
cuatro renglones en `vacio`/`sin_catalogo` y **ni un 422**.

> **El invariante que va atado con un test el día que se escriba el código**, porque es la
> restricción de Joseth escrita como algo que se puede romper: *una versión subida sin un
> solo salón, sin una sola lección doble y sin ninguna ficha por IH tiene que devolver
> **200** con sus renglones en `vacio`, nunca un 422 y nunca una lista corta sin decir que
> es corta.* Ni la ruta ni la base ni la validación pueden pedir un dato que la §0 decidió
> no guardar.

#### La forma del sobre

**Lista plana de lecciones y los ejes declarados aparte**, que es lo que midió
`myvc-front-4f` y coincide con lo que ya hace este repo: con un árbol `dia → franja` el
cliente **tiene que inventarse las celdas vacías**, y entonces un hueco que la respuesta
nunca declaró es indistinguible de un hueco real — el `[]` de la §2 otra vez, ahora por
celda.

```
GET horario/versiones/{id}/lecciones        ← escrita el 4 sep 2026. El router pasó a **567**,
                                              contado con `route:list --json` ese día y no
                                              sumado a los 566 (§10.2.3)
```

**Por `{id}` y no `horario/oficial`**, y la razón es la asimetría que Joseth cerró en la
decisión 5: *subir no es publicar*. Quien va a publicar necesita **mirar una versión que
todavía no es la oficial**; con `horario/oficial` esa pantalla no existe. El `{id}` se
comprueba contra el año del token —**404 si la versión no es de ese año**, no 403— porque si
no es un identificador de la URL que no comprueba nadie
(`tools/identificadores-del-cuerpo.py`), y porque el año viaja por tres caminos distintos y
esta ruta es la cuarta (§7.1.bis).

```json
{
  "version": { "id": 6, "year_id": 8, "nombre": "…", "es_oficial": true,
               "created_at": "…", "comprobaciones": { } },

  "ejes": { "dias": [1,2,3,4,5], "franjas": [1,2,3,4,5,6,7],
            "minutos_por_leccion": 50, "timbres": null },

  "catalogos": {
    "grupos":         { "estado": "completo", "total": 13 },
    "asignaciones":   { "estado": "completo", "total": 134, "sin_docente": 10 },
    "docentes":       { "estado": "completo", "total": 47, "con_asignacion": 12 },
    "tono":           { "estado": "sin_catalogo", "motivo": "`profesores` no tiene columna de color" },
    "salones":        { "estado": "parcial", "con_salon": 87, "de": 312, "distintos": 3,
                        "motivo": "sólo el nombre que mandó la subida; no hay tabla de salones ni ids" },
    "timbres":        { "estado": "sin_catalogo", "motivo": "el servidor no guarda la rejilla (§4)" },
    "disponibilidad": { "estado": "sin_catalogo", "motivo": "ídem" }
  },

  "lecciones": [
    { "id": 1, "horario_version_id": 6, "pieza_id": "a1196-0",
      "dia": 4, "franja": 2, "duracion": 1,
      "asignatura_id": 1196, "materia": "Matemáticas", "alias_materia": "Mat",
      "grupo_id": 31, "nombre_grupo": "Décimo", "abrev_grupo": "10°",
      "profesor_id": 12, "nombre_profesor": "…",
      "nombre_salon": "Laboratorio" }
  ],
  "total_lecciones": 312
}
```

Cinco cosas de esa forma que **no** son preferencias:

1. **`catalogos` va SIEMPRE y va DELANTE de las listas.** Una lista vacía sin su renglón en
   `catalogos` es **un error del servidor**, no un catálogo vacío. Es la única manera de que
   la regla de la §9.bis.1.a («la respuesta tiene que poder decir que lo que manda está
   incompleto») sobreviva al día que alguien añada un catálogo y se olvide del renglón.
2. **`nombre_profesor` y `nombre_salon` son `string | null`, nunca `string`.** No es
   defensivo: **22 de las 312 piezas de la versión 6 no tienen ni una fila en
   `horario_pieza_docente`**, y **10 de las 134 asignaciones del año no tienen
   `profesor_id`**. El caso nulo es el que hay hoy en la única versión real que existe.
3. **`salon_id` NO viaja.** No hay tabla de salones: un campo que sale `null` siempre
   entrena al cliente a ignorarlo, y el día que exista la tabla ya nadie lo mira. Lo que
   viaja es `nombre_salon`, y el renglón `salones` del catálogo dice que no hay ids.
4. **`duracion` va en franjas y viaja siempre**, aunque valga 1. Sin ella el cliente deduce
   una doble de dos filas contiguas, que es de la familia «plausible y falso»: **32 de las
   312** lecciones de la versión 6 son bloques.
5. **`comprobaciones` es el veredicto GUARDADO**, igual que en `getVersiones` y por la misma
   razón: recalcularlo diría lo que el servidor opina hoy de una versión comprobada con el
   código de otro día. *(Y es la trampa que ya mordió una vez: el 422 de `acepto_perder`
   mandaba a «releer el listado» a buscar una cifra fresca que ese campo no da — `0faf099`.)*

#### El `dia`: coinciden ENTRE ESTOS DOS REPOSITORIOS — y el tercero no tiene ninguna

`myvc-front-4f` avisó de que en el front el día es **el de la semana de verdad, `0` domingo
… `6` sábado**, y de que allí no existe ningún «día 1 del horario». **Es exactamente el
convenio de la §5.2.5** —`0 = domingo`, el mismo con el que se consumen las siete columnas
sobre `Carbon::dayOfWeek`—, y la versión 6 lo confirma: sus lecciones van de **`dia` 1 a 5**,
o sea lunes a viernes. **No hay conversión que escribir y por eso hay que decirlo**: dos
convenios que coinciden no necesitan código, pero sí necesitan quedar escritos, porque el
día que uno de los dos se mueva **el horario entero se corre un día y las tres reglas de la
§6 se siguen cumpliendo** (§5.2.5).

**Y «no hay conversión que escribir» era verdadero y engañoso a la vez, así que se corrige
aquí.** Lo midió `myvc-front-4f` sobre `a34f854c`: **`app2` no tiene ninguna codificación
numérica de día** del lado de asignaturas ni de horario. `datos/asignaturas.ts:321` es
`DIAS_DE_CLASE = ['lunes'…'viernes']` y `CambioDeDia.dia` viaja como **el nombre de la
columna**; `clases-de-hoy` no calcula qué día es porque recibe `horario_hoy` y
`horario_manana` ya resueltos por esta API. O sea que **la conversión no está escrita
porque hoy no existe**, y con la cuarta ruta habrá que escribirla — es una línea, pero
alguien tiene que escribirla a sabiendas, y *«cero conversión»* es exactamente la frase que
hace que nadie la busque.

**El precedente existe y está probado, que es la parte útil**: donde `app2` sí usa números
de día es el calendario (`paginas/calendario/eventos.ts:376`), con `(primero.getDay() + 6) %
7` **porque su rejilla empieza en lunes**, y con su prueba al lado. El `0 = domingo` de aquí
entra bien; la rejilla del horario tendrá que aplicar ese mismo desplazamiento **o el lunes
se pinta en la última columna**.

#### Las cuatro que iban a Joseth, CONTESTADAS el 4 sep 2026

Ninguna se resolvía midiendo, y por eso estaban aquí. Las cuatro están cerradas y **no se
re-litigan**:

1. **El `tono` es del docente y lo guarda el back: columna nueva en `profesores`.** Descartó
   las otras dos salidas que se le plantearon —dejarlo `sin_catalogo` para siempre, y **leer
   el blob para extraer los colores**, que era la única que tocaba el fichero de proyecto y
   rozaba la decisión 12—. La columna entra con
   `2026_09_04_200000_tono_del_docente`, y **nace vacía en los diecisiete**: el contrato dice
   `string | null` porque el nulo va a ser el caso normal hasta que alguien reparta los
   colores una primera vez. *La decisión cambia dónde vive el dato, no que hoy no exista.*
2. **El menú del horario lo abre el mismo permiso que las Referencias académicas** — no hay
   permiso nuevo: quien ya puede tocar asignaturas e IH puede tocar el horario. De paso
   esquiva el problema del rol `Coord académico` con cero usuarios: nadie se queda fuera el
   primer día. **Ver y crear son dos permisos distintos y no se han mezclado**: `auth.personal`
   sigue siendo el de *leer*, y es de la decisión 3 de la §9.bis.
3. **La ruta es `GET horario/versiones/{id}/lecciones`, con `{id}` explícito.** Descartó
   `horario/oficial` a secas y descartó tener las dos. El argumento que la decidió es la
   asimetría que él mismo cerró: *subir no es publicar*, así que quien va a publicar necesita
   **mirar una versión que todavía no es la oficial**, y ésa es la pantalla que hoy no existe.
4. **Los booleanos de `asignaturas` NO alimentan el horario, y se quedan.** En sus palabras:
   *«esos booleanos por asignaturas son un esfuerzo por mostrarle solo las materias del día de
   hoy y de mañana en el panel al docente… Seguirá vigente porque el colegio podría no usar mi
   sistema de horarios pero aún así poner qué días se da tal materia»*. O sea que la cuarta
   ruta **lee siempre de `horario_lecciones`** — lo que ya exigía el que las siete columnas no
   tengan franja— y la §9.bis.4 deja de ser una ambigüedad para ser un riesgo medido.

### 9.bis.4. Ya hay dos escritores de la misma verdad, y uno es nuestro

**No es un riesgo futuro: existe hoy, y lo destapó `myvc-front-4f`.** Las siete columnas
`asignaturas.lunes … domingo` las escriben **dos sitios**:

| escritor | qué hace | de qué lado |
|---|---|---|
| `toggleDia`, pantalla `asignaturas/` de `myvc_front` | conmuta la columna de esa fila | **obligatorio-académico** — vive hoy en los dieciséis |
| `putOficial` de esta API (§7.1) | **reescribe las siete de todo el año** desde las lecciones de la versión que se publica | **opcional-horario** — desplegado en cero |

**En la dirección «publicar» la colisión ya está resuelta, y se resolvió sin saber que había
dos escritores**: lo que `putOficial` va a borrar de lo que alguien puso a mano es
exactamente lo que cuenta `acepto_perder`, y por eso publicar exige que una persona teclee
ese número (§7.2). *La puerta estaba bien puesta por otra razón.*

**En la dirección contraria no hay nada.** Si un docente conmuta un día **después** de
publicar, las columnas dejan de cuadrar con la versión oficial y **no lo detecta nadie**: no
hay error, no hay aviso, y las dos pantallas —«Clases de hoy» y la rejilla de la cuarta
ruta— empiezan a decir cosas distintas del mismo día. Es el dato derivado que la §7 dijo que
**necesita quien lo vigile**, y ese vigilante **se escribió el 4 sep 2026**: es
`tools/deriva-del-horario.php`, que Joseth decidió con el radio delante — y decidió también
que **sea lo único**: ni se toca `putOficial` para que deje de reescribir a ciegas, ni se
avisa al conmutar desde el front.

**El radio pesa más de lo que parecía, y lo midió el front:** «Clases de hoy» se alimenta de
`ChangesAsked/to-me` → `horario_hoy`/`horario_manana`, que salen **de estas mismas siete
columnas**. Así que lo que se descuadra sin aviso **no es un menú opcional: es la portada con
la que aterriza todo docente del colegio al entrar**. Y desde el front **no se puede
distinguir** una columna conmutada a mano de una reescrita por `putOficial`: llegan
idénticas.

**Medido hoy con la herramienta, y con su población: `0` de `134`.** Las 134 asignaciones vivas del año 8,
comparadas columna a columna contra las lecciones de la versión 6, **cuadran las siete**.
O sea que **nadie ha conmutado un día desde que se publicó** — no que el problema no exista.
*Un cero recién publicado es el cero más fácil de conseguir y el que menos dice* — y por eso
la herramienta lo imprime con esa advertencia debajo en vez de dejarlo solo.

**Y ese cero tiene control: sabe ponerse rojo.** Comprobado el 4 sep 2026 conmutando
`sabado` en una asignación real del año 8 y devolviéndola después a sus siete valores: el
detector pasó a **1 de 134**, nombró la fila (`#1195 · DIMENSIÓN COGNITIVA · Jardín ·
sabado: 1 → 0`) y salió con **código 1**. Sin ese control, el `0` no distinguiría *«cuadran
las 134»* de *«la consulta no compara nada»*.

**Lo que la herramienta NO mira, y lo dice ella misma en cada corrida**: la franja, la
duración y el salón —las siete columnas no los tienen, así que una lección movida de franja
**el mismo día cuadra**—; los otros años y los otros quince colegios —una corrida es una
base—; y **cuál de las dos fuentes tiene razón**, porque un `toggleDia` de ayer y una versión
publicada a sabiendas con `acepto_perder` se ven exactamente igual desde ahí. Y el estado que
más importa separar: **un año sin versión oficial sale `2`, NO MEDIDO**, nunca `0` — ahí no
hay contra qué comparar, y un `0` diría lo mismo que un año publicado y perfecto.

**Y de aquí sale lo primero que hay que responderle al front, que preguntó de cuál de los dos
lee la cuarta ruta:** de **`horario_lecciones`**, siempre. Las siete columnas **no tienen
franja** —ni la tienen ni se les puede añadir sin cambiar la respuesta de
`asignaturas_dia` (§7)—, así que para pintar una rejilla no sirven. Las columnas son el
**derivado para «Clases de hoy»**; la versión es **la verdad**. Que la ruta lea la versión es
además lo que hace posible que algún día ella misma sea quien delate la deriva, porque es el
único sitio que mira los dos mundos a la vez — **y eso es una decisión, no un añadido: hoy no
lo hace.**

---

## 10. Decisiones

### 10.1. Cerradas por Joseth el 2 sep 2026 — no se re-litigan

1. **Dónde corre**: **escritorio**, Tauri 2 + Angular. Electron queda como alternativa
   sólo si WebView2 diera guerra en algún colegio.
2. **La rejilla**: **7 lecciones × 5 días**, fin de semana sábado-domingo, **timbres
   iguales todos los días** y **descansos declarados** (raya gruesa en la rejilla; un
   bloque de 2 no los cruza). **Jornada distinta por nivel**: preescolar, primaria y
   bachillerato con su propio número de lecciones, timbres y descansos.
3. **Salones**: **aula fija por grupo + especiales**. El salón es un campo opcional de
   la lección; sin él, se da en el aula del grupo.
4. **La misa y cualquier lección de varios grupos**: **sale de una asignatura
   existente** —gasta una hora de la IH de esa asignación en cada grupo unido— y
   lleva sus propios `docentes[]` y su salón.
5. **Permisos**: **sube cualquier administrativo; la oficial la marca otro**, desde la
   web. Subir **no** publica. Quién es «otro» se concretó luego, en el punto 10.
6. **El vocabulario** de la §3.
7. **El escritorio se tiene que poder vender por licencia sin MyVC detrás**, y por eso
   ninguna pantalla suya le pide un dato al servidor de MyVC (§8). No añade nada a
   este repo; sí fija que la bajada de datos es una importación opcional.
8. **Las tres rutas de la §5.3 se autorizan**, las tres a la vez: con sólo dos, nadie
   puede marcar la oficial y «Clases de hoy» sigue vacía. Escritas el 2 sep 2026:
   **563 → 566**, contado ese día con `route:list --json` y no sumado — la predicción
   «550 → 553» que llevaba esta línea **nunca fue el número**, porque entre medias
   entraron las dos épicas de esa noche.
9. **La revalidación es la opción B** (§6): el servidor comprueba las tres que puede y
   **guarda un veredicto que nombra lo no comprobado y dice su población**.
10. **Marca la oficial un superusuario o el coordinador académico** (§5.4) — el **rol**
    `Coord académico`, no la columna del año. Secretaría sube pero no publica. Hoy el
    rol tiene **cero usuarios**, así que la regla nace correcta e inerte.

11. **El rol `Coord académico` se escribe aunque esté vacío** (§5.4). Asignárselo a
    alguien es operación de cada colegio —quince decisiones, no una nuestra—, y queda
    escrito aquí para que nadie lea el rol vacío como un fallo del permiso.
12. **Listar las versiones: `auth.personal`** (§5.4), más abierto que la propuesta.
    **Con la condición de que listar no devuelva el blob ni las lecciones.**
13. **Subir y volver oficial: en cualquier año, también los cerrados** (§5.2), más
    abierto que la propuesta y coherente con la [16](16-escribir-en-un-anio-pasado.md).
    Marcar oficial una versión de 2024 reescribe las columnas de 2024, y eso es lo
    decidido.

14. **El blob del proyecto sube siempre**, no opcional (§5.1): sin él el trabajo de un
    mes vive en un portátil. *(Contestada por Joseth en paralelo, vía la sesión del
    front.)*

Y una que es dato del colegio y no decisión: **los timbres reales de cada nivel**
—de qué hora a qué hora va cada lección en preescolar, primaria y bachillerato—
siguen sin estar.

### 10.2. Abiertas, y estas son de este repo

> **Siete se cerraron el 2 sep** y están arriba, en la §10.1 — las rutas, la opción B,
> quién marca la oficial, el rol vacío, quién lista, los años cerrados y el blob.
> **Quedan cuatro**: tres de cuando se escriba el código, y una que es de una ruta que
> ya existe y que midió el front.

1. **¿`GET asignaturas` debe traer las asignaciones cuya MATERIA está en la papelera?**
   Lo midió el front contra el docker: esa lectura hace `inner join materias … and
   m.deleted_at is null`, así que **una asignación viva con la materia borrada no llega
   ni como fila ni como aviso**, y el importador **no puede contar lo que no le
   mandaron**. Hoy en `simonbolivar` son **cero** —y el filtro de `asignaturas.deleted_at`
   sí funciona: llegan las 134 vivas—, pero cero es la foto de hoy. El horario saldría
   *cuadrado y completo* con una materia entera ausente, y nadie lo notaría hasta que un
   docente preguntara por su clase. **Arreglarlo es cambiar la respuesta de una ruta
   viva**, que aquí es decisión y no parche — y más con `myvc_flutter` compartiendo
   endpoints en los quince colegios. Mientras tanto el importador lo declara como **no
   traído y nombrado** y **cuenta las asignaciones que recibió**: si un colegio espera 134
   y recibe 130, el número que falta es la señal aunque nadie sepa cuáles son.
2. **El blob del proyecto: dónde vive y con qué tope.** **El «dónde» ya casi no es
   pregunta: cabe en la fila.** Medido el 2 sep 2026 — `max_allowed_packet` del docker
   es **67.108.864 bytes (64 MB)**, y el cuerpo entero de una subida real son **42.492
   bytes**: el **0,06 %**. Aun con el proyecto completo estimado, el 0,3 %.

   > **Este documento avisaba de `max_allowed_packet` como «el que corta primero», y ese
   > aviso estaba mal.** Sobra por tres órdenes de magnitud. Se retira con su medición
   > delante, porque **avisar de un límite que no aprieta es la clase de precaución que
   > hace tomar una decisión peor** —comprimir, partir el envío, sacarlo a `storage/`—
   > por un problema que no existe.
   >
   > Con dos salvedades: **64 MB es el docker, no los quince cPanel**, donde nadie ha
   > medido; el peor caso plausible es MySQL 5.7 con 4 MB por defecto, y ahí los **225,7
   > KB** medidos de la cota alta son el **5,51 %**, así que la decisión aguanta sin más
   > medición. (Aquí decía «210 KB, el 5 %» sobre la cifra vieja: el porcentaje sube
   > medio punto y no cambia nada.) Y el que se
   > quedaría corto en un hosting compartido no sería el paquete sino `post_max_size` de
   > PHP, que suele venir en 8 M y también sobra.

   **El escapado solo ya cuesta × 1,4**, y lo midió la sesión del front: meter el
   `.myvch` como cadena dentro de un JSON duplica cada tabulador y cada comilla —
   30.161 bytes de fichero se convierten en 42.492, un **41 %** más. **Ése es el suelo,
   no «el factor»**: es un proyecto con **cero colocaciones**, o sea la subida más barata
   que puede existir. En cuanto hay horario dentro viajan también las piezas y el total
   se va a **× 1,795** (§ de más abajo). La escala entera son esos dos extremos —
   **× 1,41 vacío · × 1,795 lleno**—, y tomar el de la izquierda por el del cuerpo es lo
   que hizo que este bloque dijera 185.997 durante unas horas.

   **Queda abierto el tope**, y una salida escrita y **no aplicada**: comprimir y mandar
   en base64 da la vuelta al factor. **No se hace hoy y la razón pesa más que el 1,4**:
   un blob comprimido **no se puede leer con un `SELECT`** el día que alguien necesite
   mirar por qué una versión salió mal. Se aplica si un proyecto real llega a medir en
   megas.

   **Y la cota alta ya está medida, así que esto se cierra: el blob va en la fila, sin
   comprimir.** El front midió el 2 sep 2026 un proyecto con **el horario entero
   colocado** —17 salones, 134 marcas de disponibilidad sobre 47 docentes, 32
   asignaciones con bloque de dos y **312 de 313 piezas puestas**—: **128.779 bytes de
   fichero y 231.135 de cuerpo**, o sea **125,8 y 225,7 KB**. Contra los 64 MB del
   docker es el **0,34 %**; contra los 4 MB del peor caso plausible, el **5,51 %**.
   **La decisión no se mueve**: el blob va en la fila y sin comprimir, y el 5,51 %
   sigue sobrando por el mismo margen que la cerró. Una cifra corregida **al alza**
   invita a reabrir lo que ya está decidido, y aquí no hay nada que reabrir.

   > **CORRECCIÓN DEL 2 SEP 2026, Y LA PRIMERA VERSIÓN DE ESTE PÁRRAFO DECÍA 185.997.**
   > Ese número era **el cuerpo con la lista de piezas VACÍA**: el arnés que lo produjo
   > medía, literal, `JSON.stringify({ …, proyecto: texto, lecciones: [] })`. O sea que
   > lo que este documento llamaba «el cuerpo, el que mide `max_allowed_packet`» era
   > **el blob escapado y nada más — el horario no estaba dentro**. Lo encontró
   > `myvc-front-8e` remidiendo, y se reprodujo desde este árbol sobre el mismo
   > `lleno.myvch`:
   >
   >     fichero .myvch                     128.779
   >     cuerpo con `lecciones: []`         185.997   <- el que estaba escrito aquí
   >     cuerpo con las 312 piezas          231.135
   >     lo que cuestan las piezas          +45.064   (+24,2 %, ~144 bytes por pieza)
   >
   > **El factor es × 1,795, no × 1,45.** Las cinco cifras de la izquierda salen iguales
   > medidas por separado en los dos repositorios; el cuerpo total baila **65 bytes**
   > según cómo se nombre el sobre —`version` contra los campos sueltos de la §5.2—, y
   > eso no toca ni el coste de las piezas ni el porcentaje.
   >
   > **Y la frase que había aquí era falsa, no imprecisa, así que se corrige en vez de
   > matizarse.** Decía que *«un colegio más grande sube de aquí por más filas, no por un
   > factor peor»*. Sube **por las dos cosas**: **las piezas escalan con las filas y el
   > blob no**, así que cuantas más colocaciones tenga un colegio, mayor es la parte del
   > cuerpo que no es el fichero — el factor **empeora** con el tamaño en vez de quedarse
   > quieto. Ése era el error de fondo; el decimal era la consecuencia.
   >
   > **Cómo se produjo, que es lo que hay que llevarse:** un `[]` **no da error, se lee
   > como «no había nada que meter» y encima produce un número creíble** — 1,45 veces el
   > fichero es exactamente lo que uno esperaría del escapado—. Por eso aguantó, se
   > propagó a dos repositorios y sólo cayó cuando alguien lo remidió en vez de releerlo.
   > Es la misma forma que la §2 —«Clases de hoy» no enseña de más, no enseña nada— en su
   > versión más limpia: **el conjunto vacío que se lee como respuesta**.
   >
   > **AVISO OPERATIVO: el arnés está arreglado A MEDIAS, y la mitad que falta va
   > nombrada.** Comprobado el 2 sep desde este árbol, copia por copia:
   >
   > - `myvc_horarios/herramientas/llenar-el-horario.ts` — **arreglado** (`081cfab`). Ya
   >   no arma su propia forma del cuerpo: mide con **`cuerpoDeSubida()`, el emisor de
   >   verdad**, e imprime 231.135. **Coincide al byte con la reproducción independiente
   >   hecha desde este repo**, que era la comprobación cruzada que faltaba — el arnés y
   >   el emisor ya no se pueden separar porque son el mismo código.
   > - `myvc_horarios.wt/importadores/…/llenar-el-horario.ts` — **NO arreglado**, sigue
   >   con el `lecciones: []` en la línea 376. Ese carril tiene el fichero modificado sin
   >   commitear y nadie lo pisa. **Quien corra el arnés desde ahí sigue obteniendo
   >   185.997**, y lo obtendrá con toda la pinta de una medición fresca.
   >
   > **La mitad viva se nombra en vez de darlo por saldado, y es la misma regla que el
   > propio fallo:** darla por cerrada entera sería otra vez leer «no queda nada» donde
   > queda algo.
   >
   > **Y el remate, que es la lección entera: la frase falsa la imprimía el arnés, no
   > este documento.** Escribía «un colegio con más docentes subiría de aquí, **pero ya
   > no por un factor**» — o sea que **el número falso y la frase falsa salían del mismo
   > fichero, y el número era lo que hacía creíble la frase**. Por eso la corrección
   > tenía que ser el código y no nuestras dos citas: arreglando sólo este §10.2.2, **la
   > siguiente corrida del arnés lo habría desmentido y habría ganado ella** — que es
   > exactamente lo que pasó la primera vez. Hoy el arnés imprime los dos extremos y dice
   > él mismo que × 1,41 vacío y × 1,795 lleno son la misma escala; y si no puede armar
   > el cuerpo **sale con 1 y no enseña ninguna cifra**, en vez de enseñar una a medias.
   > *Una cota alta que no incluye las piezas no es una cota alta.*
   >
   > **Y la otra cifra de este bloque —los 42.492— se remidió, y esa SÍ era buena.** Se
   > sospechó de ella por encajar demasiado bien (× 1,41, otra vez el factor de blob
   > solo), y el fichero lo dice: `colegio.myvch` tiene **0 colocaciones y 0 piezas**, así
   > que ahí el `[]` no ocultaba nada porque no había nada que ocultar. Comprobado por
   > `myvc-front-8e` y reproducido desde este árbol: 30.161 de fichero y **42.476** de
   > cuerpo a la forma vieja — y los **74 bytes** que la separan de la forma buena son
   > **los mismos 74** de la otra medición, o sea los dos campos de `version`. Que el
   > mismo sobre cueste lo mismo en dos ficheros distintos es la comprobación cruzada de
   > que el sobre es lo único que baila.
   >
   > **Así que esa cifra no es una cota alta ni una sospecha: es el SUELO**, el factor de
   > un proyecto con nada colocado, la subida más barata que puede existir. Y con las dos
   > la escala queda explicada entera, con dos números medidos en vez de con uno:
   >
   >     × 1,41 vacío  ·  × 1,795 lleno       — y lo que se mueve entre medias son las piezas
   >
   > Que es la demostración de la frase corregida de arriba: **el factor empeora con el
   > llenado** porque las piezas escalan con las filas y el blob no. Leer el × 1,41 como
   > «el factor» es el mismo error que leer el × 1,45 como «el cuerpo», sólo que por el
   > otro extremo.
   >
   > **Y lo que hace que esa cifra valga no es el bucle que colocó el horario, es lo que
   > le pusieron detrás.** El pre-vuelo **declara que no mira las colocaciones** —eso es
   > el nivel 3, que no existe—, así que sobre la legalidad de ese horario no había más
   > garantía que el propio colocador. *Un colocador que se comprueba a sí mismo no
   > demuestra nada: si tiene un fallo, lo tiene en las dos mitades.* Por eso hay una
   > revisión independiente que reconstruye la ocupación **desde las colocaciones
   > guardadas** —lo que abriría otro programa— y las 312 salen legales. Sin eso sería la
   > cota alta de un fichero que nadie puede usar.
   > **Y el tipo de `pieza_id` quedó cerrado el 2 sep 2026, medido por el front sobre
   > el proyecto real:** `varchar(64)`. Son **313 piezas, todas únicas, de longitud 7
   > exactos** y de la forma `a<asignatura_id>-<índice>` (`a1196-0`); **0 de 313 son
   > sólo dígitos**, así que un `int` no aguanta ni la primera subida. Con dos cosas
   > que van pegadas a la columna y no a este documento: la unicidad es
   > **(`version_id`, `pieza_id`)** —los identificadores derivan de `asignaturas.id`,
   > que es estable entre versiones, así que dos versiones del mismo año contienen
   > **las dos** `a1196-0` y un único global sólo rompería **la segunda subida**—, y
   > la longitud **se valida con 422, nunca se trunca**: dos piezas que trunquen a los
   > mismos 64 caracteres **se fusionan en una sola** y el choque de docente se
   > calcularía sobre una pieza que no existe. *No da error: da un horario equivocado.*

3. **¿Existe una ruta para DESCARGAR el proyecto de una versión, y con qué permiso?**
   Sería una **quinta** ruta, y **su número se cuenta con `route:list` el día que se
   autorice** — no está pedida. Voto del front: el mismo permiso que publica, no el que
   sube (§5.4).

   > **Aquí decía «una cuarta ruta», y eso ya nombra a otra cosa.** La cuarta se
   > escribió el 4 sep 2026 y es `GET horario/versiones/{id}/lecciones` (§9.bis) —
   > **mirar**, no llevarse. Ésta sería la quinta, y sigue sin pedirse: la decisión 12
   > dijo *«listar no es descargar»* y la §9.bis la extendió a *«mirar no es
   > llevarse»*, así que **descargar el proyecto sigue entero por decidir**. Y el
   > ordinal se corrige mientras el número de ruta se sigue sin predecir, que es lo
   > que dice el recuadro de abajo: **un ordinal cuenta lo que ya existe; un número de
   > ruta predice lo que entre por cualquier otra rama antes que ella.**

   > **Aquí decía «o sea 554», y ese número se ha retirado a propósito el 2 sep 2026.**
   > No se ha sustituido por otro: **una ruta que todavía no existe no puede llevar
   > número**, porque el suyo depende de todo lo que entre por cualquier otro sitio
   > antes que ella. El 554 se escribió cuando el router iba por 550 y quedó stale **dos
   > veces en la misma noche** sin que nada se pusiera rojo — el número de rutas no lo
   > comprueba ningún test (`CLAUDE.md`). Actualizarlo a la cifra buena no arreglaba
   > nada: volvería a caducar con la siguiente ruta de la siguiente rama.
   >
   > Es la regla que este documento ya aplica a las tres autorizadas —*«se vuelve a
   > contar ese día»*— llevada al sitio donde de verdad muerde: **lo que este repo no
   > sabe mantener es un número predicho**.
4. **Las siete columnas**: **derivadas y atadas desde el 2 sep 2026** (§7.1). El test
   obligatorio existe —`HorarioOficialTest`, 15 casos— y **la herramienta de `tools/` se
   escribió el 4 sep 2026**: `deriva-del-horario.php`, que es la que contesta si un colegio
   sigue sincronizado (§9.bis.4). **Deja de ser opcional el día que este módulo se
   despliegue**, porque desde ese día hay dos escritores vivos de las mismas columnas. Queda por confirmar que el orden **no** se promete: la
   opción A le da al docente **qué** clases tiene hoy, nunca en qué orden (§7).
> Lo más barato que se puede hacer sin esperar a ninguna de las cuatro es el **nivel 1
> del pre-vuelo como script de `tools/`** sobre los quince colegios (§9). No toca el
> router, no necesita permiso y contesta si este módulo se va a poder usar.

---

## 11. El despliegue: cero de dieciséis, y lo que queda por hacer — escrito, NO hecho

**Sigue congelado por Joseth** mientras `myvc_flutter` está en revisión
([`DESPLIEGUE.md` §🛑](../DESPLIEGUE.md)), y este apartado **no lo descongela**: existe
porque `myvc_horarios` preguntó qué trabajo queda y la respuesta no estaba en ningún sitio
completa. Escrito el 4 sep 2026 sobre `8f59242` y **remedido ese mismo día sobre
`bf83d3c`**, que es la rama entera con la cuarta ruta dentro: **eran tres rutas y 232
commits cuando se escribió, y son cuatro y 236 ahora**. *La tabla de abajo es de las que
caducan con cada commit propio, así que se remide con `git rev-list` y `route:list` en vez
de sumarle los que uno recuerda haber hecho.*

### 11.1. Dónde está hoy, medido

| | | comprobado con |
|---|---|---|
| colegios con el módulo | **0 de 16** (más `demo`) | `routes/api/horario.php` no existe en `9474b50`, que es la base desplegada |
| qué contestan allí las **cuatro** rutas | **404** | no hay fichero de rutas que las declare — **no es el 501 del docker**, que era el controlador sin cuerpo |
| commits sin desplegar | **236** desde `9474b50` | `git rev-list --count 9474b50..HEAD`, sobre `bf83d3c` |
| migraciones sin desplegar | **8** desde el 4 sep 2026 — las siete que ya contaba `DESPLIEGUE.md` más `2026_09_04_200000_tono_del_docente`, que decidió Joseth ese día | `git ls-tree` de los dos extremos |
| ficheros de rutas nuevos | **2**: `horario.php` y `rubricas.php` | ídem |

**El 404 y el 501 no son el mismo estado y conviene no mezclarlos**: en el docker la ruta
existe y contestaba 501 mientras el método estaba vacío; en un colegio real **la ruta no
existe**. Un cliente que trate el 404 como «esta versión del servidor no tiene el módulo»
acierta hoy y seguirá acertando — es la señal correcta.

### 11.2. No hay camino «sólo horario», y esto es lo primero que hay que saber

**La tanda es indivisible y la razón es de esquema, no de prudencia.** La migración del
horario mete su columna con `->after('regla_nivelacion')`, y `regla_nivelacion` la añade
`2026_09_02_100000_nivelaciones_columnas`, de la misma tanda: `ADD COLUMN … AFTER x` con una
`x` que no existe **falla**. O sea que sacar el horario solo **no da una migración aditiva
que no hace nada: da un error de columna desconocida**.

Y por encima de eso, la tanda entera es bloqueante por otro sitio: `years.regla_nivelacion`
la nombra `ContextoDeUsuario::construir()` en las cuatro ramas, y ese `SELECT` lo dispara
**el propio guard**. Con el código nuevo y la base sin migrar **no se puede ni iniciar
sesión** — está medido y documentado en [`DESPLIEGUE.md` §⛔](../DESPLIEGUE.md).

**Consecuencia para este módulo: el horario no se despliega; se despliega la tanda.** Lo que
queda por hacer del horario **no es un despliegue propio**, son los pasos 1 a 3 de
`DESPLIEGUE.md` con dos comprobaciones más, las de abajo.

### 11.3. Los pasos, y contra qué colegio se prueba primero

El bucle, el orden por colegio (`git pull` → `migrate --force` → cachés) y las tres trampas
están en `DESPLIEGUE.md` y **no se repiten aquí**. Lo que es de este módulo:

1. **Primero `demo`**, y no es una preferencia: es el único de las diecisiete carpetas que
   no es el colegio de nadie, **entra en el mismo bucle** que los dieciséis y ya se sabe que
   su `.env` es el único distinto del resto (`APP_MOVIL_VERSION_MINIMA` presente y vacía).
   Con una salvedad que hay que leer antes: **el login de `demo` está roto por un `if`
   cableado en el front**, no por el servidor (casilla 2septies de
   [`ESTADO-ACTUAL.md`](ESTADO-ACTUAL.md)) — así que se prueba **por API con `curl` y un
   token**, no por pantalla, o se confunde un fallo viejo del front con uno nuevo nuestro.
2. **Después un colegio real con datos de verdad**, y el candidato es el que ya se midió:
   `simonbolivar` es el único del que este repositorio conoce el pre-vuelo
   (§9.1) — 13 grupos, 134 asignaciones, Σ IH 345 contra una rejilla de 7×5.
3. **La comprobación de este módulo, después de la de las migraciones** —y ojo, que el
   `tinker` de `DESPLIEGUE.md` pregunta por **siete** y ahora son ocho: le falta
   `["profesores","tono"]`, añadido allí el 4 sep 2026 porque ese fragmento es una
   comprobación operativa y no una tabla medida—: las tres
   tablas existen y `years.horario_version_id` también —las cuatro ya están en el `tinker`
   de `DESPLIEGUE.md`—, y encima **`GET horario/versiones` con un token de personal tiene
   que contestar `200` con `total: 0` y `oficial_id: null`**. Ese 200 vacío es la única
   forma de distinguir *«el módulo está y este colegio no ha subido nada»* de *«el módulo no
   llegó»*, que desde fuera se parecen: un 404 y un `[]` se leen igual de bien.
4. **`tools/prevuelo-del-horario.php`, colegio a colegio, después de migrar.** No toca nada
   —sólo `SELECT`— y contesta si los datos de ese colegio sirven para cuadrar un horario.
   Tiene tres códigos de salida a propósito: **`2` es «no medido», que no es «limpio»**.

### 11.4. Qué se rompe si se hace mal

| si se hace… | qué pasa |
|---|---|
| **el `migrate` a medias** (falla en un colegio y se sigue con el siguiente) | el estado peor de todos: `2026_08_31_100000` ya retiró `matriculas.boletin_independiente` y las demás no han llegado — **no funciona ni el código viejo ni el nuevo**, y no lo delata ningún error. Ya pasó en dos bases de sesión el 2 sep 2026 |
| **`migrate` antes de `git pull`** | el colegio se queda con el código viejo y la columna ya retirada: los boletines caen por el otro lado. **No hay orden sin ventana**; los dos comandos van seguidos y por colegio |
| **desplegar el front del horario antes que esta API** | el menú llama a rutas que allí **no existen** y recibe 404. El orden es el de siempre: el guard del backend **desplegado**, no fusionado |
| **volver atrás dejando las migraciones puestas** | vale para las tandas anteriores y **no para ésta**: hay un `dropColumn` dentro. Ver el Paso 4 de `DESPLIEGUE.md` |
| **publicar una versión sin mirar el número** | `putOficial` reescribe las siete columnas de día **de todo el año**; lo que se pierda de lo que alguien puso a mano es lo que cuenta `acepto_perder` (§7.2 y §9.bis.4) |

### 11.5. Y TRES afirmaciones de `DESPLIEGUE.md` sobre este módulo han envejecido — DOS ya corregidas

**La regla por defecto es no corregirlas allí y se dice por qué**: aquellas tablas son *lo
que se midió el día que se midió*, y ese documento tiene su propia regla —*un rango sin
desplegar se remide entero cuando se le toca*—, así que se remiden **el día del despliegue**
y no hoy.

> **El 4 sep 2026 Joseth mandó excepcionar el aviso O, y las dos que lo tocan se corrigieron
> a mano ese día.** La diferencia que lo decidió: **el resto de esas filas describen el
> servidor, y el aviso O es un mensaje que sale hacia fuera.** Una fila que describe el
> servidor la ve fallar contra el servidor quien la lea el día del despliegue; **un aviso
> con una cifra corta no falla contra nada** — el front construye su menú con lo que le
> dijeron, y una ruta que no se nombró no deja hueco visible. La fila de la tabla de
> migraciones, que sí describe el servidor, **se queda como está** y es la 1 de aquí abajo.

Lo que hay que saber al leerlas:

1. La fila de `2026_09_04_100000_horario_versiones` dice que sin la migración **sólo**
   `POST horario/versiones` pasa de 501 a 500, y que `getVersiones` y `putOficial` «siguen a
   501 y no las tocan». **Era cierto sobre `347f137` y dejó de serlo con los lotes B3 y C2**:
   los tres métodos están implementados y los tres nombran las tablas nuevas.

   **Y el radio no son 3, son CUATRO — la cuarta ni siquiera es de este módulo.** Lo midió
   `8myvc-e0` el 4 sep 2026: `years.horario_version_id` la lee
   `ChangeAskedController::horarioOficialDelAnio()` desde `getToMe` **en sus dos ramas**, o
   sea **`GET ChangesAsked/to-me`** — la que pide la app al abrir, con `auth.token` a secas.
   Sin la migración **cae el panel de todo el mundo**, no una ruta de un módulo que todavía
   no usa nadie. *Yo corregí esa fila de 1 a 3 y me quedé corto por el mismo motivo por el
   que estaba mal: miré el módulo en vez de mirar quién lee la columna.* Esa fila lleva
   **tres caducidades seguidas**.
2. ~~El aviso **O** dice que las tres de `horario/` «hoy contestan 501».~~ **CORREGIDO
   el 4 sep 2026** en su sitio: las cuatro tienen cuerpo y ninguna contesta ya 501.

3. **Y el aviso O además CUENTA MAL, que es peor que caducar — porque ese aviso todavía no
   se ha dado.** Dice **«24 rutas nuevas»** y **«las 3 de `horario/`»**; con la cuarta son
   **25** y **4**. Las otras dos caducidades de esta sección son afirmaciones sobre lo que
   el código hace, y quien las lea el día del despliegue las ve fallar contra el servidor.
   **Ésta no falla contra nada**: es una lista que se le manda al front, está marcada
   **POR AVISAR**, y un aviso que nombra tres rutas cuando hay cuatro **deja la cuarta sin
   avisar sin que nadie note el hueco** — el front construye su menú con lo que le
   dijeron. Es exactamente lo que la regla del canal exige avisar: *una ruta nueva, o
   quién puede llamarla*.

   **CORREGIDO el 4 sep 2026 en su sitio, por decisión de Joseth**, con el porqué escrito
   en un recuadro al lado de la tabla: **las otras dos se arreglan solas al remedir; ésta
   no.** Remedir contesta *«¿siguen contestando 501?»*; nadie va a recontar «24» si no sabe
   que hay que hacerlo. *La escribió la misma sesión que un commit después metió la cuarta
   ruta y no volvió a esta sección: el hueco no lo abrió el tiempo, lo abrió el commit
   siguiente.*

   **Y las 25 se contaron contra la base desplegada, no se sumaron**, que es lo que este
   repo exige de un número que se escribe: `9474b50` declara **543** rutas y `HEAD`
   **567**; comparados los dos conjuntos de URIs entran **25** y se va **1** (`POST
   tardanzas/login/traer-datos`, el aviso L), y **543 + 25 − 1 = 567**. Que cuadre con el
   router es la comprobación, no el método.

**Es la misma forma de envejecer que ese documento ya tiene catalogada** —*una afirmación
sobre lo que el código hace caduca cuando el código cambia, aunque el número que la acompaña
siga siendo el mismo*—, y es la segunda vez que le pasa **a esta misma fila**. Nada de esto
cambia el plan: la tanda es bloqueante entera, así que un colegio a medio migrar no llega a
notar la diferencia entre un 500 y tres.
