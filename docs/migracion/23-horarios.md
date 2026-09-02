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
> siguen abiertas. Nada de esto está construido. **Las tres rutas de la §5.3 son una
> propuesta: en este repo una ruta es una decisión, no un efecto secundario.**
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
> Lo que falta es el cuerpo de los tres, y todo lo que este documento dice de la §6
> y la §7 sigue **sin construir**.
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
| Rutas del router | **550** (`route:list --json`) — hoy **566**, con las tres de la §5.3 dentro |

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
piezas:   [ { pieza_id, dia, franja, duracion,
              salon_nombre, salon_capacidad_grupos,  ← informativos, nunca reglas
              docentes:     [ profesor_id, … ],      ← profesores.id, NO users.id
              asignaciones: [ asignatura_id, … ] } ]
```

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
el año en otro computador — y eso es una **cuarta** ruta, o sea **554**, no un campo más
de la lista. La sesión del front vota por que el permiso sea **el mismo que publica**, y
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

---

## 8. La frontera nueva: el escritorio se tiene que poder vender sin MyVC detrás

Llegó el 2 sep por la tarde: Joseth quiere poder **vender el programa por licencia** a
colegios que no son clientes de MyVC, y el front lo fijó como *«ninguna pantalla le
pide un dato al servidor de MyVC»*. Para este repo son tres consecuencias, y sólo la
tercera es trabajo:

1. **El backend no gana pantallas ni rutas por esto.** Siguen siendo las tres de la
   §5.3 y la pantalla web de elegir la oficial, que es del front.
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
   puede marcar la oficial y «Clases de hoy» sigue vacía. **550 → 553**, contado el día
   que entren.
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
   > medido; el peor caso plausible es MySQL 5.7 con 4 MB por defecto, y ahí 210 KB
   > siguen siendo el 5 %, así que la decisión aguanta sin más medición. Y el que se
   > quedaría corto en un hosting compartido no sería el paquete sino `post_max_size` de
   > PHP, que suele venir en 8 M y también sobra.

   **La regla de cálculo no es «el fichero más un poco»: es el fichero × 1,4**, y lo
   midió la sesión del front. El coste es **el escapado**: meter el `.myvch` como cadena
   dentro de un JSON duplica cada tabulador y cada comilla — 30.161 bytes de fichero se
   convierten en 42.492 de cuerpo, un **41 %** más.

   **Queda abierto el tope**, y una salida escrita y **no aplicada**: comprimir y mandar
   en base64 da la vuelta al factor. **No se hace hoy y la razón pesa más que el 1,4**:
   un blob comprimido **no se puede leer con un `SELECT`** el día que alguien necesite
   mirar por qué una versión salió mal. Se aplica si un proyecto real llega a medir en
   megas.

   **Y la cota alta ya está medida, así que esto se cierra: el blob va en la fila, sin
   comprimir.** El front midió el 2 sep 2026 un proyecto con **el horario entero
   colocado** —17 salones, 134 marcas de disponibilidad sobre 47 docentes, 32
   asignaciones con bloque de dos y **312 de 313 piezas puestas**—: **128.779 bytes de
   fichero y 185.997 de cuerpo**, o sea **125,8 y 181,6 KB**. Contra los 64 MB del
   docker es el **0,28 %**; contra los 4 MB del peor caso plausible, el **4,5 %**.

   > **El factor se afina y crece con el llenado: × 1,45, no × 1,4.** Más colocaciones
   > son más comillas y más tabuladores dentro de la cadena. Un colegio más grande sube
   > de aquí **por más filas, no por un factor peor**.
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
   Sería la **cuarta**, o sea **554**, y no está pedida. Voto del front: el mismo
   permiso que publica, no el que sube (§5.4).
4. **Las siete columnas**: se derivan al marcar la oficial; falta decidir **qué las
   ata** —el test es obligatorio, la herramienta de `tools/` es opcional— y confirmar
   que el orden **no** se promete (§7).
> Lo más barato que se puede hacer sin esperar a ninguna de las cuatro es el **nivel 1
> del pre-vuelo como script de `tools/`** sobre los quince colegios (§9). No toca el
> router, no necesita permiso y contesta si este módulo se va a poder usar.
