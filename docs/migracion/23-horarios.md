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
> **Y ese mismo día Joseth contestó tres de las siete decisiones abiertas**: **las tres
> rutas están autorizadas** (§5.3), la revalidación es la **opción B** (§6), y la
> oficial la marcan **superusuario y coordinador académico** (§5.4) — que trajo un
> hallazgo, porque «coordinador académico» nombra dos cosas distintas en esta base y
> hoy **ninguna de las dos identifica a nadie**. Quedan cuatro en la §10.2.

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

**La oficial es un puntero, no una bandera.** `years.horario_version_id`, no
`horario_versiones.oficial`. MySQL no tiene índices parciales, así que una columna
`oficial tinyint(1)` no se puede atar a «como mucho una por año»: el día que haya dos
en verdadero, quien lea `WHERE oficial = 1 LIMIT 1` se lleva una de las dos **y no se
pone nada rojo**. Un puntero no admite ese estado. Marcar la oficial pasa a ser un
`UPDATE` de una columna, y «todavía no hay ninguna» es `NULL`, que es un estado y no
un accidente.

**El blob del proyecto es lo único de aquí que puede crecer sin límite.** Guardarlo
es lo que permite que otro computador abra la versión oficial y siga desde ahí, que
es lo que pasa cuando el coordinador cambia de máquina; pero un JSON con la
configuración, las disponibilidades de doce docentes y 345 colocaciones **no se ha
medido nunca porque todavía no existe ninguno**. Antes de escribir la columna hay que
decidir dos cosas —§10.2— y ninguna es cosmética: si va en la fila o en `storage/`, y
cuál es el tope. En cPanel el que corta primero no es PHP, es `max_allowed_packet` de
MySQL, y lo hace con un error que no se parece a «el fichero es muy grande».

### 5.2. El cuerpo del `POST` — la forma que propuso el front, con cuatro correcciones

```
version:  { nombre, year_id }                        ← y nada más viene del cuerpo
piezas:   [ { pieza_id, dia, franja, duracion,
              salon_nombre, salon_capacidad_grupos,  ← informativos, nunca reglas
              docentes:     [ profesor_id, … ],      ← profesores.id, NO users.id
              asignaciones: [ asignatura_id, … ] } ]
```

Cada elemento de `asignaciones` se explota a una fila
`(version_id, pieza_id, asignatura_id, dia, franja, duracion, salon)`, que es la forma
de la §5.1. Y **lo pone el servidor, no el cuerpo**: `subida_por`, `created_at` y
`comprobaciones`.

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

**Y una que no es del cuerpo sino del año.** `year_id` puede ser de un año pasado, y
eso **ya está contestado**: moverse por un año pasado es el producto
([16](16-escribir-en-un-anio-pasado.md)), y lo que frena las escrituras allí es el
interruptor del **periodo**. Un horario no cuelga de ningún periodo, así que ese
candado **no le aplica** y lo único que lo frena es el permiso de la §5.4. Si el
colegio quiere que un año cerrado no admita versiones nuevas, eso es una decisión
(§10.2), no un `if` que se añade de paso.

### 5.3. Las tres rutas — AUTORIZADAS el 2 sep 2026

    POST horario/versiones               sube una versión    auth.token + esAdministrativo
    GET  horario/versiones               lista las del año   auth.token + ¿quién? (§10.2)
    PUT  horario/versiones/{id}/oficial  marca la oficial    auth.token + puedePublicarHorario (§5.4)

Las autorizó Joseth el 2 sep 2026, las tres a la vez y con esta razón: con sólo las
dos primeras se puede subir y listar, pero **nadie puede marcar la oficial y «Clases
de hoy» sigue vacía**, que es el problema que este módulo viene a resolver.

Lo que mueven el día que se escriban, que **no es sólo el router**:

- El contador está en **550**, contado con `route:list --json` en este árbol el 2
  sep 2026. Con las tres pasa a **553**, y ese número **se vuelve a contar ese
  día**, no se suma aquí: es la regla que ya costó una cifra el 1 sep.
- `CLAUDE.md`, y los snapshots `rutas.json`, `guards-por-ruta.json` y
  `guard-por-familia.json` — las tres llevan guard, así que la familia `horario`
  entra como **3 de 3** y ese renglón no pide explicación.
- **No** mueven `RutasPreLoginTest::TOTAL_PUBLICAS` (siguen doce) ni
  `AutenticacionTest::SIN_GUARD`: ninguna de las tres es pública, y ninguna debería
  serlo — una versión del horario dice qué docente está dónde a cada hora.

### 5.4. Autorización — decidida, y con un hallazgo dentro

Lo contestó Joseth el 2 sep 2026: **sube cualquier administrativo; marca la oficial un
superusuario o el coordinador académico.**

| | Criterio | Qué es, hoy |
|---|---|---|
| Subir una versión | `Autoriza::esAdministrativo` | `is_superuser \|\| Role::isSecretario` ([línea 73](../../app/Support/Autoriza.php#L73)) |
| Listar las versiones | sin decidir (§10.2) | propuesta: el mismo que sube |
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

> Ésta sería **la primera vez que este repo cuelga un permiso del rol `Coord
> académico`**. Va en la dirección segura de la regla que dejó escrita el 21 ago
> —*crear un rol no regala permisos*—: aquí es un permiso que se le da a un rol que ya
> existía, nombrándolo, y no un rol que hereda permisos sin que nadie lo decida.

---

## 6. Qué puede revalidar el servidor, y qué no — y decir que sí sería una respuesta que miente

La regla es buena y no se discute: *el cliente decide bien, el servidor decide si es
legal*. Lo que hay que mirar antes de escribirla es **con qué dato**, porque la mitad
de los datos se acaba de mudar al escritorio.

| Regla dura | ¿La puede comprobar el servidor? | Con qué |
|---|---|---|
| Un grupo, como mucho una pieza por (día, franja) | **Sí** | `asignatura_id → grupo_id`, que ya está |
| Un docente, como mucho una pieza por (día, franja) | **Sí, si la versión sube los docentes de cada pieza** | `horario_pieza_docente`; con `asignaturas.profesor_id` **no**, por el capellán (§5.1) |
| Σ lecciones de una asignación = su IH | **Sí**, y es la más barata | `asignaturas.creditos`: está en las 134, ninguna vacía |
| Un bloque ocupa casillas consecutivas del mismo día | **Sí** | `dia`, `franja`, `duracion` de la propia fila |
| Un salón sin choque | **No se puede decidir** | `capacidad_grupos` no existe aquí: la iglesia con seis grupos es indistinguible de dos grupos metidos en un aula |
| La disponibilidad ✕ respetada | **No** | vive en el fichero de proyecto |
| La franja dentro de la jornada del nivel, sin cruzar descansos | **No** | la rejilla y los timbres viven en el fichero de proyecto |

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
que trajo la versión · Σ = IH ✓ · salón NO COMPROBADO, falta `capacidad_grupos` ·
disponibilidad NO COMPROBADA, vive en el proyecto · jornada NO COMPROBADA»*. Un
veredicto sin población es otra vez el `[]` de la §2: **se lee como «todo bien»**. Con
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

**Y una cosa del pre-vuelo sí es de aquí y se puede hacer hoy, sin decidir nada y sin
tocar el router:** el nivel 1 sobre los datos que ya hay, como script de `tools/`.
Contesta la única pregunta que hoy no tiene respuesta y que puede tumbar el proyecto
entero — **si los datos de los quince colegios sirven** —, y no necesita ni rejilla,
ni escritorio, ni una ruta. En `simonbolivar` ya da dos números: la IH está puesta en
las 134, pero **10 asignaciones no tienen docente y son 25 de las 345 horas**, o sea
25 lecciones que nadie puede colocar. Si en otro colegio la IH está a medias, eso se
sabe en una tarde en vez de en la demo.

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

Y una que es dato del colegio y no decisión: **los timbres reales de cada nivel**
—de qué hora a qué hora va cada lección en preescolar, primaria y bachillerato—
siguen sin estar.

### 10.2. Abiertas, y estas son de este repo

> **Tres de las siete se cerraron el 2 sep** y están arriba, en la §10.1: **las rutas**,
> **la opción B** y **quién marca la oficial**. Quedan las cuatro que no se tocaron, más
> **una nueva que salió al medir la tercera** — el rol que no tiene a nadie dentro.

1. **¿Quién puede listar las versiones?** El contrato nombró dos permisos y hacen falta
   tres. Propuesta: `esAdministrativo`, el mismo que sube.
2. **¿Hay que darle el rol `Coord académico` a alguien?** Es operación del colegio y no
   código, pero sin ella el permiso que se acaba de decidir **no alcanza a nadie**
   (§5.4). Y es una pregunta por colegio, no una para los quince.
3. **El blob del proyecto**: ¿en la fila o en `storage/`? ¿Con qué tope? Nadie ha
   medido uno todavía porque no existe ninguno (§5.1).
4. **Las siete columnas**: se derivan al marcar la oficial; falta decidir **qué las
   ata** —el test es obligatorio, la herramienta de `tools/` es opcional— y confirmar
   que el orden **no** se promete (§7).
5. **¿Un año cerrado admite versiones nuevas?** Hoy la respuesta por defecto es **sí**,
   y no por descuido: la [16](16-escribir-en-un-anio-pasado.md) dejó cerrado que
   moverse por un año pasado es el producto, y el candado que frena las escrituras
   allí es el del **periodo**, que a un horario no le aplica (§5.2). Cerrarlo sería
   una decisión nueva.

   **La forma que proponen las dos sesiones —y sigue sin decidir—: subir sí, volver
   oficial sólo el año actual.** Rehacer el horario de 2024 para consultarlo es
   legítimo; que el panel empiece a leerlo, no. Traducido a la §5.1, la regla es
   **mover el puntero `years.horario_version_id` sólo en el año actual**: los años
   pasados conservan el suyo, que es el historial y hay que dejarlo quieto.

   > **Y hay que ser exacto con lo que eso compra, porque parece comprar más.** No
   > impide que el panel enseñe el horario de un año pasado: un docente que **se mueve
   > a 2024** lee las asignaturas de 2024 y por tanto sus siete columnas, que es
   > exactamente el producto que describe la 16. Lo que la regla impide es **cambiarle
   > el horario a un año cerrado por debajo**. La garantía vale para quien está en el
   > año actual, y no para todos.

> Lo más barato que se puede hacer sin esperar a ninguna de las cinco es el **nivel 1
> del pre-vuelo como script de `tools/`** sobre los quince colegios (§9). No toca el
> router, no necesita permiso y contesta si este módulo se va a poder usar.
