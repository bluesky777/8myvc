# 35 · El modelo de evaluación es una elección del colegio — el plan del backend

> **Qué es esto.** Las 22 decisiones que Joseth tomó el 13 sep 2026
> (`myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`) cierran las nueve que quedaban
> abiertas entre [28](28-competencias-e-indicadores.md) §7 y la investigación del
> front. Este documento **no vuelve a decidir nada de eso**: traza **en qué orden se
> construye**, qué cuesta cada trozo, qué test lo sujeta y **qué falta todavía por
> decidir** para poder escribir la fase que lo necesita.
>
> **Nada de esto está construido.** No hay código nuevo en este commit.
>
> > **13 sep 2026, unas horas después: las tres decisiones que este plan abría YA ESTÁN
> > CERRADAS** — D23, D24 y D25, §7.bis de `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`.
> > **Ninguna fase queda bloqueada.** Dos salieron como se proponían aquí; la tercera **no salió
> > por ninguna de las dos puertas que este documento planteaba** y es la que hay que leer: §1.5.

> ## Lo que comprobé y lo que NO pude comprobar
>
> Todo lo de abajo está medido el **13 sep 2026** sobre `main`, en el árbol principal.
>
> **Este documento se escribió con el contenedor caído**, así que nació con dos cosas sin medir
> y dichas como tales. **Las dos se midieron unas horas después**, al levantarlo, y se dejan aquí
> con su resultado en vez de borrar el aviso:
>
> | | cómo nació | cómo está |
> |---|---|---|
> | **las rutas** | «cota inferior razonada», sin `route:list` | ✅ **579 contadas** con `route:list --json` en el árbol principal — §1.1 |
> | **la suite del `ALTER`** | las 1.926 leídas del mensaje del commit, no de una ejecución mía | ✅ **corrida entera** sobre el árbol rebasado — Fase 0 |
>
> Y una tercera que **nació medida y salió mal**: el reparto de una columna de `years`. Decía
> **~30 instantáneas** y son **3**. Corregido en §1.3, con el fallo del detector explicado ahí
> mismo, porque es el que no se delata.

---

## 1. Siete correcciones al encargo, medidas

Ninguna cambia una decisión de Joseth. Cinco cambian un número o un paso del plan; la 4 y la
5 abrieron una decisión cada una, **y Joseth las cerró el mismo 13 sep** (D23, D24 y D25,
§7.bis del documento de decisiones). **Ya no queda nada bloqueado.**

### 1.1 · La base de rutas no es 577: `main` ya lleva dos más

`DECISIONES-MODELO-DE-EVALUACION.md` §7 y el [28](28-competencias-e-indicadores.md)
§6 dicen *«hoy hay **577** (4 sep 2026)»*. Era cierto el 4 de septiembre. Desde
entonces entraron en `main` dos rutas, cada una con su commit y su documento:

| commit | ruta | documento |
|---|---|---|
| `09cfd2b` | `GET horario/versiones/{id}/proyecto` | [23](23-horarios.md) §9 |
| `46c6660` | `GET sincronizacion/huella` | [34](34-la-huella-de-sincronizacion.md) |

`CLAUDE.md` dice **579**, contadas con `route:list --json` en `.worktrees/e5` el 7 sep
— y ese worktree **ya no existe**, así que el número estaba **heredado**, que es justo lo
que ese recuadro prohíbe.

> ### ✅ CONTADO el 13 sep 2026: **579**, y ahora sí desde un árbol que existe
>
> ```
> docker exec 8myvc-app-1 php artisan route:list --json   ->  579
> árbol: el principal, rama main @ 8e752a0
> de ellas api/: 578 · la otra es el `/` de routes/web.php
> ```
>
> **Coincide con lo que decía `CLAUDE.md`, y eso no es lo mismo que estar contado**: era la
> cifra correcta apoyada en un árbol borrado, o sea imposible de rehacer. Ahora se puede.
> **La base de este plan es 579**, y las 22 de las siete fases se cuentan encima, el día que
> entren y desde el árbol en que se escriban. El 577 del doc 28 §6 queda anotado.

> Es la tercera vez que esta cifra se mueve hacia abajo por herencia. No cuesta nada
> arreglarlo: se cuenta en el árbol donde se escribe, y se dice el árbol.

### 1.2 · Son dieciséis colegios y diecisiete carpetas, no quince

El documento de decisiones dice «los quince colegios» siete veces. Son **dieciséis
desde el 30 ago 2026** (entró `lal`), y el bucle de despliegue alcanza **diecisiete
carpetas** porque `demo` cuelga del mismo glob (`CLAUDE.md`, y el barrido del 2 sep
en `docs/DESPLIEGUE.md`). Importa exactamente en un sitio de este plan: **los dos
censos del día del despliegue** (§7). Un censo que dice «en los quince» y recorre
diecisiete carpetas deja al lector decidiendo a las tres de la mañana si el colegio
de más es legítimo. **Lo es.**

### 1.3 · Una columna nueva de `years` mueve **tres** instantáneas — y este apartado dijo treinta

> **⚠️ CORREGIDO el 13 sep 2026, unas horas después, remidiendo. La cifra de treinta era mía y
> era falsa**, y va arriba porque exageraba el riesgo de la Fase 1 en un orden de magnitud.
>
> La primera medición contó *«instantáneas que mencionan una columna de `years`»* — **31 de 125**
> con el detector ampliado, 30 con el estrecho. Pero esa no es la pregunta: lo que mueve una
> instantánea es que la respuesta **gane una clave**, y eso sólo pasa donde viaja la **fila
> entera**. Separando las dos cosas:
>
> | | |
> |---|---|
> | instantáneas con la **fila entera** de `years` (11 de 11 columnas) | **3** |
> | instantáneas con una **proyección** nombrada (1, 2, 4 o 7 columnas) | **28** |
>
> Las **tres** son `muestreo-years.json`, `muestreo-years-colegio.json` y
> `muestreo-years-trashed.json`, y salen de los tres únicos caminos que publican la fila entera:
>
> ```
> YearsController::getIndex    'SELECT y.*, i.nombre as logo FROM years y …'   <- comodín en SQL crudo
> YearsController::getColegio  'SELECT * FROM years WHERE deleted_at is null'  <- comodín en SQL crudo
> YearsController::getTrashed  Year::onlyTrashed()->get()                      <- Eloquent entero
> ```
>
> Las otras 28 llevan **subconjuntos nombrados** y por eso son inmunes: los catorce boletines y
> actas llevan las mismas cuatro —`solo_escalas_valorativas`, `show_fortaleza_bol`,
> `abrev_colegio`, `si_recupera_materia_recup_indicador`— y los cuatro contextos de login llevan
> los seis `displayname` más una. **Una columna nueva no entra en un `SELECT` que nombra
> columnas.**
>
> **Lo que eso cambia, dicho sin adornos: le di la vuelta a la frase del documento de decisiones
> y no hacía falta.** *«Con el enum en `ponderado` y las tablas vacías, los colegios dan la misma
> respuesta que hoy»* **es cierta en el comportamiento y casi cierta en los bytes**: se mueven
> tres instantáneas de 125 y tres rutas —`years`, `years/colegio`, `years/trashed`—, no treinta.
> Sigue habiendo que regenerar y leer ese diff, y sigue sin poder usarse como *«no se mueve
> nada»*; pero el riesgo de la Fase 1 es el que decía la decisión, no el que dije yo.
>
> **Y cómo se comprueba de verdad esta terna, que no es corriendo la suite una vez.** Lo aporta
> `8myvc-c1`, que se llevó la verificación, y son dos trampas encadenadas:
>
> 1. **Sin `--filter`.** Un filtro se construye con los nombres de lo que tocaste, y una columna
>    repartida por `SELECT *` sale en respuestas que **no se llaman como nada de eso** — las tres
>    de `grupos.ih` vivían en `MuestreoDeLecturasConContextoTest`.
> 2. **«N rojos» no es «N instantáneas».** Cada test asserta varias y **para en la primera**, así
>    que la primera pasada cuenta de menos: con `grupos.ih` la suite dijo **2** y los ficheros
>    eran **3**. El conjunto sale de **regenerar, volver a correr y repetir hasta el verde**, no
>    de la primera pasada.
>
> Hasta que eso corra, **la terna de arriba está razonada y no medida**, y se dice así.

> **Y el fallo es el que este repositorio ya tiene fichado**: *«el primer sitio donde mirar cuando
> el número sale raro es el detector»*. Aquí el número no salió raro —treinta es perfectamente
> creíble— y por eso no se miró hasta que se volvió a medir por otro motivo. **Un detector que
> cuenta un síntoma parecido al que te interesa es el peor de los tres casos**, porque no se
> delata: contaba *menciones* y se leyó como *respuestas que cambian*.

> ## ✅ MEDIDO el 13 sep 2026 — **son tres, no hay cuarta**
>
> Lo corrió `8myvc-2e` en `.worktrees/2e` sobre `99e0942` + una columna sonda, y **la corrió
> quien no la escribió**, que es la regla. El corte salió **limpio**, que es lo que hace que no
> haya que interpretar ningún umbral:
>
> | | instantáneas | qué llevan |
> |---|---|---|
> | fila **entera** de `years` | **3** | `muestreo-years`, `-colegio`, `-trashed` — **70 de 70** columnas |
> | proyección nombrada (`Year::datos()`) | **18** | `.year` con **36 de 70** |
> | sin la fila entera | 122 | proyecciones y ninguna — **cuántas de las 122 llevan alguna columna suelta depende del detector**: 21, 30 o 60 según se pregunte (abajo) |
>
> **El hueco que importa es el de 36 a 70, y está vacío.** El reparto completo —de `8myvc-c1`,
> que lo reprodujo por un **tercer camino**, con barrido propio y sin el script de `2e`— tiene
> más escalones de los que dijo el resumen, y conviene escribirlo entero en vez de fiarlo a dos
> umbrales:
>
> ```
> 70 columnas -> 3      <- la fila entera: las tres
> 36 columnas -> 18     <- Year::datos()
> 19 columnas -> 1
> 17 columnas -> 7
> 16 columnas -> 1
> ```
>
> **Lo que se sostiene y lo que no, después de cuatro detectores distintos:**
>
> - **«Entre 36 y 70 no hay nada» es CIERTO**, y lo confirman las tres derivaciones. Por eso el
>   corte «lleva la fila entera / no la lleva» **no necesita elegir ningún umbral**: en el hueco
>   donde habría que ponerlo no hay nada que clasificar.
> - **«70 o 36 y nada más» es FALSO**, y lo es en su forma literal: hay proyecciones por debajo
>   de 36. Ésa es la frase que se corrigió, no la de arriba.
> - **Y la cola —cuántas instantáneas «llevan `years`» en total— NO ESTÁ RESUELTA, y no hace
>   falta resolverla.** Cuatro detectores han dado **21**, **30** y **60**, y los tres son
>   defendibles: cambian según se exija que el juego de claves **sea** una fila de `years`, que
>   contenga N columnas suyas, o que el nombre aparezca en el fichero. El de 60 es mío y es el
>   burdo —busca nombres sueltos, así que `nombre` u `orden` en cualquier objeto cuentan—, que es
>   **el mismo detector que dio los ~30 de esta mañana**.
>
> **La única cifra estable de las cuatro es la que importa: TRES llevan la fila entera**, y sale
> igual con los cuatro métodos. Todo lo demás es la cola, depende del umbral y **no decide nada
> de la Fase 1**. *Un recuento afirma el corte; un reparto lo enseña* —la frase es de `2e`— y
> esto es el caso extremo: **el reparto de la cola enseña que la pregunta estaba mal puesta.**
>
> ### ✅ La pata empírica: la corrida dirigida de `8myvc-c1`, y el diff que la cierra
>
> ```
> Tests: 4 failed, 62 passed (433 assertions)
> orden: php artisan test --filter='MuestreoDeLecturasTest|CentinelaDeLasColumnasDelAnioNuevoTest'
> árbol: /app/.worktrees/c1 @ ee6453c + la sonda sin commitear   ·   13 sep 2026, 12:58
> ```
>
> **Los tres rojos de instantánea son la terna**, y el cuarto es el centinela —que no es una
> instantánea y por eso va aparte—. Lo que lo cierra no son los rojos sino **el diff de la
> regeneración**:
>
> ```
> añadidas: 4    quitadas: 0
>   3 x  + 'sonda_c1' => 'int'     <- las tres instantáneas
>   1 x  + 0 => 'sonda_c1'         <- el centinela, nombrando la columna
> ```
>
> **Ni una línea que no sea la sonda.** Es la prueba que pide este repositorio: *el diff de la
> regeneración son exactamente las claves esperadas y ninguna más* — un rojo dice que algo cambió,
> el diff dice **qué**.
>
> > **Y su límite, dicho por quien la corrió: confirma, no descubre.** Su suite entera se quedó
> > huérfana a mitad y la corrida que queda es **dirigida**, con `--filter` a dos clases. Un
> > cuarto fichero que viviera fuera de ellas **no habría salido**. Por eso la pata empírica que
> > vale es la de abajo —la Fase 1 real, con una columna de verdad y sin filtro— y ésta es **el
> > control que la acompaña**.
> >
> > **Un delator nuevo, y va escrito porque el de siempre aquí no servía.** Aquella suite murió
> > como ya está fichado —el `docker exec` se va y el `phpunit` de dentro sigue— pero **la tarea
> > avisó de «completed, exit code 0»**, así que ni la duración ni el código de salida decían
> > nada. **Lo delató que el fichero no tenía la línea `Tests:`**: se quedó congelado en 1.031
> > tests de 142 ficheros y sin resumen. *Un resumen que falta es más fiable que un exit code que
> > está.*

> ### ✅ Y la Fase 1 real la confirmó al escribirse — 12 instantáneas, de las que **tres son la terna**
>
> `myvc-front-50` commiteó la Fase 1 (`4e0033c`) y movió **doce** instantáneas. Contadas aquí con
> `git show --name-only --format="" 4e0033c | grep -c 'Snapshots/'`, y **se reparten exactamente
> como predecía esta sección**:
>
> | | cuáles |
> |---|---|
> | **3 · las movió la columna SOLA** | `muestreo-years`, `-colegio`, `-trashed` — **la terna, clavada** |
> | **6 · las movió él A MANO** | los cuatro `login-contexto-*`, `muestreo-auth-me` y `muestreo-aplicacion-descargas-detailed` — las seis proyecciones nombradas que había que ensanchar a propósito |
> | 3 · de la ruta | `rutas.json`, `guards-por-ruta.json`, `guard-por-familia.json` |
>
> **Y `familias-que-nunca-entran-en-el-candado.json` NO se movió**, que era la otra predicción:
> la familia `years` tiene de sobra hermanas con guard, así que una ruta nueva ahí no entra en ese
> censo. Cero apariciones en los dos commits.
>
> **Dos avisos para quien cite ese commit, los dos de `8myvc-c1`:**
>
> 1. **Su titular dice «diez» y son doce**, y su propio desglose lo contradice. Si se cita, **se
>    cita el cuerpo y no el encabezado** — donde además está dicho mejor que aquí: *«una
>    instantánea que se mueve SOLA delata un camino con comodín que nadie encontró; una proyección
>    nombrada que se mueve porque alguien le añadió una columna sólo dice lo que ese alguien
>    escribió»*. Es la distinción cobertura/exposición, llegada por su cuenta y por tercera vez.
> 2. **No se cuentan con `--stat`**, que trunca las rutas largas y se come el `Snapshots/` de
>    algunas líneas: da **11**. Con `--name-only`, 12.
>
> **Con esto la terna deja de ser una derivación y pasa a estar confirmada por el hecho**: se
> predijo antes de que existiera el código, y el código la cumplió sin que nadie la ajustara.

> **Con esto la terna tiene tres derivaciones independientes** —el script de `2e`, el barrido de
> `c1` y las tres columnas de los docs 22 y 23— **más una suite**, y las cuatro coinciden.

> ### La trampa que se comió `c1` midiendo esto, y que muerde en `years` y no en `grupos`
>
> `GROUP_CONCAT(COLUMN_NAME)` **corta en `group_concat_max_len` sin error, sin aviso y sin una
> fila de menos**, y le fabricó un hallazgo entero: «una columna que falta en las tres
> instantáneas», salido del tope de una función. Medido aquí:
>
> | | |
> |---|---|
> | `group_concat_max_len` | **1024** |
> | nombres de las columnas de `years` | **1024** — *o sea cortado* |
> | nombres de las columnas de `grupos` | 159 |
>
> **Los 1024 exactos son la firma del corte**, igual que los 255 exactos de
> `frases_asignatura.frase` en el doc 28 §1.ter: un número que coincide con el tope no es una
> medida, es un tope.
>
> Y lo que lo hace digno de estar escrito aquí no es el fallo, es su forma: **el punto ciego
> depende del tamaño de la tabla.** Ese detector funciona en `grupos` y miente en `years`, así
> que **funciona hasta que deja de funcionar y no dice cuándo** — la peor de las formas de fallar
> de las que este repositorio lleva contadas, porque la primera vez que se usa sale bien.
>
> **El método no fue correr la suite, y es mejor así.** Con cuatro suites en el contenedor los
> mismos tests pasaron de 0,41 s a 6,30 s —más de tres horas de corrida— y se abandonó. En su
> lugar: sacar las columnas vivas con `SHOW COLUMNS` y recorrer las 125 instantáneas buscando
> **objetos cuyo juego de claves sea una fila de `years`**, a cualquier profundidad. Eso contesta
> **la pregunta exacta** —*«¿qué respuestas publican la fila entera?»*— en vez de una parecida, y
> no depende de que un test corra, ni del orden, ni de un `foreach` que se corta. Resuelve de una
> vez el ruido no determinista y el punto ciego del bucle: **lo que no se ejecuta no tiene ninguno
> de los dos problemas.**
>
> ### La cuarta apareció, y era falsa — y así es como se caza
>
> Su primer detector buscaba **el nombre** de una columna vecina, `horario_version_id`, y dio
> **cuatro** ficheros: los tres y `muestreo-ChangesAsked-to-me.json`. Abierto antes de cantarlo,
> ahí `horario_version_id` es **un campo propio de esa respuesta** —un objeto de once claves con
> `alumnos`, `eventos`, `horario_hoy`…—, no una fila de `years`. **Cuarto hallazgo: cero.**
>
> Es el mismo error que este apartado cometió tres veces —un detector que mide algo *parecido*, con
> un número creíble— y **lo que lo cazó no fue desconfiar**: fue que estaba escrito que **una
> cuarta sería un hallazgo, y un hallazgo se mira antes de publicarlo.** Ésa es la única defensa
> que ha funcionado hoy contra esta familia de fallos: no la sospecha, sino **haber dicho de
> antemano qué resultado obligaría a mirar**.
>
> ### Y una población que no es la que parece: 74, 70, 68 y 64
>
> Al cruzar cifras salieron **cuatro números distintos de «columnas de `years`»**, y los cuatro
> son correctos sobre bases distintas:
>
> | dónde | columnas |
> |---|---|
> | base de **desarrollo** `simonbolivar` | **74** |
> | base de **tests** (volcado + migraciones) | **70** |
> | docblock de `CentinelaDeLasColumnasDelAnioNuevoTest` | **68** — envejecido |
> | volcado congelado `mysql-schema.sql` | **64** |
>
> La de tests es la que vale para todo lo de arriba, porque es contra la que corre el centinela.
> **Los 74 de desarrollo son la trampa ya fichada** —esa base tiene cuatro columnas que ni el
> volcado ni las migraciones traen— y el 68 del docblock es una cifra que envejeció sin que nada
> se pusiera rojo, igual que el `:158` del sembrador. **Ninguna de las dos se arregla aquí**;
> quedan dichas para que el siguiente que mida no crea que ha encontrado una discrepancia.

#### La segunda corrección del mismo día: **se mueven tres instantáneas y la publican OCHO rutas**

> **Lo destapó `8myvc-2e` encontrando dos caminos que este apartado no tenía, y el censo completo
> —hecho aquí, sistemáticamente en vez de por inspección— da OCHO, no cinco ni tres.** Las dos
> cifras son ciertas y **cuentan cosas distintas**, que es justo lo que esta sección venía
> confundiendo desde que se escribió:
>
> | | |
> |---|---|
> | **exposición** — rutas cuya respuesta gana la columna | **9** |
> | **cobertura** — instantáneas que se mueven | **3** |
>
> **Las nueve**, con su ruta, leídas de `HEAD` y no del árbol de trabajo:
>
> | método | cómo publica la fila entera | ruta | ¿instantánea? |
> |---|---|---|---|
> | `getIndex` | `SELECT y.*` crudo | `GET years` | **sí** |
> | `getColegio` | `SELECT *` crudo | `GET years/colegio` | **sí** |
> | `getTrashed` | `Year::onlyTrashed()->get()` | `GET years/trashed` | **sí** |
> | `postStore` | `return $year` | `POST years/store` | **sí** `years-store` |
> | `putGuardarCambios` | `return $year` | `PUT years/guardar-cambios` | **sí** `years-guardar-cambios` |
> | `deleteDelete` | `return $year` | `DELETE years/delete/{id}` | **sí** `years-delete` |
> | `deleteDestroy` | `return $year` | `DELETE years/destroy/{id}` | no |
> | `putRestore` | `return $year` | `PUT years/restore/{id}` | no |
> | **`Perfiles\ImagesController:318`** | `return $year` | **`PUT myimages/cambiarlogocolegio`** | **no** |
>
> > **El noveno vive FUERA de `YearsController`, y ése es el error de método que cometimos los
> > tres a la vez.** Lo encontró `8myvc-c1` barriendo `return` que mencionen `$year` en **todo
> > `app/`**, no leyendo el controlador obvio: cambiar el logo del colegio hace
> > `Year::findOrFail(...)`, le pone `logo_id` y **devuelve el año entero**. Guard `auth.personal`
> > en la ruta y `esAdministrativo` dentro (~10), **sin instantánea**.
> >
> > Censamos `YearsController` **porque la tabla se llama `years`**. Es la lección de esta misma
> > sección en otra dirección: no fue contar mal, fue **contar bien sobre la carpeta equivocada**.
> >
> > **Y tres candidatos descartados comprobando sus llamadas una a una, no por su pinta:**
> > `Year::actual()` hace `SELECT *` pero sus tres llamantes usan sólo `->id`;
> > `Year::de_un_periodo()` devuelve un modelo entero y su único llamante
> > —`AsignaturasController:440`— también usa sólo `->id` y devuelve otra cosa; y
> > `RequisitosController:33` devuelve `$years` de una proyección de cuatro columnas. **Los tres
> > se parecen a un publicador y ninguno lo es.**
>
> **Y la mitad que faltaba: a quién alcanza cada una** — aportada por `8myvc-c1`, y es la que
> convierte la cuenta en una decisión:
>
> | sin instantánea | quién puede llamarla | cuántos |
> |---|---|---|
> | `POST years/store` | `auth.personal` | **74** |
> | `PUT years/guardar-cambios` | `auth.personal` | **74** |
> | `DELETE years/delete/{id}` | `auth.personal` | **74** |
> | `DELETE years/destroy/{id}` | `esSuperusuario` dentro | **11** |
> | `PUT years/restore/{id}` | `esSuperusuario` dentro | **11** |
> | **`PUT myimages/cambiarlogocolegio`** | `esAdministrativo` dentro | **11** |
>
> **74 y no 45** — contado hoy en la base de desarrollo con el criterio leído del middleware
> (`ExigirPersonal::FUERA`, `tipo NOT IN ('Alumno','Acudiente')`): 53 `Profesor` + 21 `Usuario`
> vivos, sobre **2.358** cuentas. El 45 venía de `CLAUDE.md` y **no se reproduce**; el recuadro de
> debajo lo deja escrito como lo que es en vez de sustituirlo en silencio. **Son de UN colegio.**
>
> > **Esta tabla tenía cinco filas y sin instantánea son SEIS**: faltaba el noveno camino, que
> > está en la tabla de arriba como «no» y en la nota de al lado, pero **no en la tabla que
> > convierte la cuenta en una decisión** — que es la única que alguien va a leer para decidir.
> > Es la misma forma que el titular de `23d1d98` diciendo «diez» sobre un desglose que suma
> > doce: *el cuerpo bien y el renglón que se lee, mal*. Añadida el 13 sep 2026.
>
> **Los dos que se encontraron primero son los dos mejor cerrados**, y los tres que nadie había
> nombrado son los de más público: **74** personas y ninguna comprobación más allá del guard de la
> ruta —o sea **el doble de lo que decía este documento hace un rato**, lo que hace la pregunta
> abierta más grande, no más pequeña—. *Lo que menos se mira no es lo más escondido: es lo que parece rutinario.*
>
> > **LAS CIFRAS DE ARRIBA ESTÁN REMEDIDAS EL 13 SEP 2026, Y EL `45` NO SE REPRODUCE.**
> >
> > | | dev `simonbolivar` | test `simonbolivar_testing` | lo que decía aquí |
> > |---|---|---|---|
> > | `auth.personal` | **74** | **71** | 45 |
> > | `esSuperusuario` | **11** | **10** | 11 |
> > | `esAdministrativo` | **11** | — | ~10 |
> >
> > El `45` estaba escrito como *«la cifra de `auth.personal` de la base de desarrollo»* y **en
> > esa base hay 74** (53 `Profesor` + 21 `Usuario`, vivos; el criterio del middleware es
> > `tipo NOT IN ('Alumno','Acudiente')`, `ExigirPersonal::FUERA`). Tampoco sale de la de tests
> > (71) ni de `profesores` (47). **No es que envejeciera**: sólo hay **tres** cuentas de personal
> > creadas en 2026 —`ZZTestFirma`, `ZZPruebaCelda`, `coord.academico.prueba`—, así que la base no
> > ha crecido 29 personas. Viene de `CLAUDE.md` («baja la superficie de 2.328 cuentas a 45») y
> > **ese par tampoco se reproduce**: hoy son 2.358 cuentas. Queda escrito como lo que es —*no sé
> > de dónde salió el 45*— en vez de sustituirlo en silencio, porque una cifra que nadie puede
> > reproducir y otra que alguien cambió sin decirlo se leen igual dentro de un mes.
> >
> > **Y `esAdministrativo` no es «los ~10»**: es `is_superuser || Role::isSecretario`, y el rol
> > `Secretario` tiene **cero titulares** en esa base, así que hoy coincide con los 11 superusuarios
> > **por población y no por definición** — el colegio que nombre un secretario lo descubre.
> >
> > **Cada cifra con su base delante, y ninguna es de producción.** Los 11 son 11 en dev y **10 en
> > tests**: la misma pregunta contra dos bases da dos respuestas, que es la razón de que aquí vaya
> > la tabla y no un número. **Son de un colegio y además es el docker**, no de los dieciséis.
>
> **Y una trampa descartada por el camino, que casi quita dos de la lista.** El docblock de
> `YearsTest` dice que `guardar-cambios` *«devuelve el modelo en memoria»*, lo que se lee como
> «no trae la fila de la base». Comprobado: habla de **qué valor** lleva un campo —`null` frente
> al `''` que guardó MySQL— y no de **qué columnas viajan**; `putGuardarCambios` hace
> `Year::findOrFail` y `postStore` **rehidrata** con `Year::find(...)` después del `save()`. Las
> dos traen las 70. **Tercera frase del día que es verdad con dos lecturas distintas**, después
> del «los nombra uno a uno» del 422 y del «tres respuestas vivas» de los docs 22 y 23.
>
> > **Y una cuarta, de otra especie y con peor final — la aportó `myvc-front-50`
> > diagnosticándose.** *«Omitir `forcedelete` ahorra un camino que candar»* es **cierto**;
> > *«el rodeo se cierra por construcción»* es **falso**; soldadas en una frase suenan a una sola
> > afirmación razonable. **No son dos lecturas de una frase: son dos frases, una verdadera y otra
> > no, que se avalan la una a la otra.**
> >
> > **Y es la peor de las cuatro por su consecuencia**: las otras tres dejaban un número mal.
> > Ésta dejaba **`destroy` abierto** — borrar y volver a crear devuelve la fila con
> > `por_defecto = 0`, o sea libre, y el candado se salta **sin tocar ninguna ruta prohibida**. La
> > §5.1.e del doc 28 ya lo midió sobre los nueve caminos de `unidades`: **precedente, no
> > hipótesis**.
> >
> > La prueba que lo fija, y va en la Fase 3: **borrar un desempeño del colegio y volver a crearlo
> > no puede devolver una fila libre.**

> ## ✅ 13 sep 2026 — **cubiertas las tres de 74**, decisión de Joseth
>
> `tests/Contrato/EscriturasDeYearsPublicanLaFilaTest` (`b428153`) le pone instantánea a
> `POST years/store`, `PUT years/guardar-cambios` y `DELETE years/delete/{id}` — **las tres de
> `auth.personal`, o sea las de más público**. Guardan la **forma**, no el cuerpo, y cada caso
> lleva además su propia aserción: que la respuesta traiga
> `si_recupera_materia_recup_indicador`, que no nombra ninguna proyección del proyecto, **así que
> su presencia demuestra el `SELECT *` en vez de sugerirlo**.
>
> **Comprobado con un instrumento que no es el test**: `tools/lo-que-reparte-una-columna.py years`
> pasa de decir **3** a decir **6**. Y `years-store` trae **75** claves y no 74 — `postStore`
> cuelga además `$year->periodos`.
>
> **Siguen sin cubrir TRES**, y es la decisión que se tomó: `destroy`, `restore` y
> `myimages/cambiarlogocolegio` llevan `esSuperusuario` o `esAdministrativo` **dentro** (11
> personas), así que se dejaron fichadas. **Esto no decide si esas columnas deben viajar** —eso es
> del colegio y sigue abierto—: hace que el día que se decida **se vea**, en vez de enterarse en el
> cliente.

> **Quedan TRES rutas que publican la fila entera y a las que nadie les mira el cuerpo**:
> sus tests comprueban el `assertStatus`, no la forma. **Sin instantánea no hay rojo**, así que
> las tres verificaciones que hay en marcha —la mía por lectura y las dos suites— **miden todas lo
> mismo y ninguna las vería**.
>
> **Por qué importa y no es una pedantería**: la Fase 1 necesita la cuenta de **cobertura** —son
> tres instantáneas las que hay que regenerar— pero el siguiente que añada una columna a `years`
> necesita la de **exposición**, y si lee *«los tres únicos caminos que publican la fila entera»*
> se lleva una cifra que se queda corta en cinco. Es
> [30](30-lo-que-reparte-una-columna-nueva.md) otra vez y por su lado ciego: **lo que reparte una
> columna no es el número de instantáneas, es el número de sitios que devuelven la fila entera.**
>
> **Y un camino que NO publica, para cerrar la cuenta**: `Year::actual()`
> (`app/Models/Year.php:130`) también hace `SELECT * FROM years`, pero sus tres llamadas
> —`LoginController:507`, `AlumnosController:327` y `:1010`— usan sólo `->id`. Comprobado por
> `8myvc-2e` en las tres. `datos()`, `datos_basicos()` y `de_un_profesor()` llevan proyecciones
> nombradas.

> ### Y lo que remata el día: **la terna ya estaba escrita en este repositorio, dos veces**
>
> Salió al verificar un hallazgo de `8myvc-2e` sobre `grupos.ih`, y es el renglón más incómodo de
> esta sección:
>
> - **[23-horarios.md](23-horarios.md) §**, por `horario_version_id`, literal: *«`years` la leen
>   con `SELECT *` `YearsController::getIndex`, `::getColegio` y `getTrashed` —el último por
>   Eloquent—, así que … mueve sus tres instantáneas de muestreo.»*
> - **[22-nivelaciones.md](22-nivelaciones.md) §**, por `regla_nivelacion`: *«`GET years`,
>   `GET years/colegio`, `GET years/trashed` … las tres instantáneas de `MuestreoDeLecturasTest`
>   se regeneran con esa decisión escrita.»*
>
> **Dos columnas distintas, dos documentos distintos, la misma terna y los mismos tres métodos.**
> La respuesta llevaba escrita desde antes de que se abriera este documento, y aquí se
> redescubrió en un día, con tres cifras falsas por el camino y dos suites ajenas corriendo. Un
> `grep getTrashed docs/migracion/` la daba en segundos.
>
> **Y aun así no es sólo un escarmiento, y la corrección es de `8myvc-2e`:** son **tres columnas
> distintas** —`regla_nivelacion`, `horario_version_id` y ésta— que han llegado a **la misma
> terna por tres caminos independientes y con meses de diferencia**. Eso no es una respuesta
> copiada tres veces: es **la misma medición repetida tres veces con resultado idéntico**, que es
> lo más cerca de una confirmación externa que va a tener esta sección.
>
> **Con un matiz que impide sacar de aquí la moraleja fácil**: ese `grep` habría dado también el
> *«tres respuestas vivas»* de los dos documentos —la cuenta de exposición, equivocada— y se
> habría heredado sin tocarla. O sea que *leer el repo primero* **habría dado el número bueno y el
> malo a la vez**, sin nada que distinguiera uno de otro. Leer primero ahorra el trabajo; no exime
> de preguntar **qué población contó** cada cifra que se hereda.
>
> **Lo que sí es nuevo, y conviene separarlo para no tirar el trabajo con el escarmiento**: los
> dos documentos anteriores dicen **«tres respuestas vivas»**, que es la cuenta de **cobertura**
> puesta donde va la de **exposición** — y son **ocho**. O sea que el repositorio tenía bien el
> número que hacía falta para regenerar y **arrastraba el mismo error de población** que esta
> sección cometió tres veces. La terna estaba; la distinción, no.
>
> **La regla que sale de aquí y que va antes que medir**: cuando una pregunta empieza por *«¿a
> cuántos sitios llega X?»*, **el primer sitio donde buscar es `docs/migracion/`**, porque este
> repositorio lleva un año contestándolas y escribiéndolas. Medir es el segundo paso, no el
> primero.

> ### Tres veces mal en un día, y el porqué vale más que las tres correcciones
>
> Esta sección ha dicho **~30**, luego **3**, y ha llamado a esos 3 *«los tres únicos caminos que
> publican»* cuando son **8**. Los tres errores son **el mismo**: contar con un detector que mide
> algo **parecido** a lo que interesa.
>
> - «menciona una columna de `years`» **≠** «la respuesta gana una clave» → salieron 30.
> - «tiene instantánea» **≠** «publica la fila entera» → salieron 3.
>
> Y ninguno se delató, porque **los tres números eran creíbles**. Un número raro se comprueba
> solo; **un número creíble sólo se comprueba si alguien vuelve a preguntarse qué se contó**. La
> regla del repositorio —*el primer sitio donde mirar cuando el número sale raro es el detector*—
> **tiene un agujero por ahí**: cuando el número no sale raro, nadie mira el detector. Lo que
> cierra ese agujero no es desconfiar más, es **escribir al lado de la cifra qué población contó**,
> que es lo que la tabla de arriba hace y lo que esta sección no hacía.

**El mecanismo, que es lo que no cambia y hay que tener delante al escribir la Fase 1:**

```
comodín en SQL crudo     getIndex, getColegio
Eloquent devuelto entero getTrashed, postStore, putGuardarCambios,
                         deleteDelete, deleteDestroy, putRestore
```

Son **dos** de las tres vías que censó [30](30-lo-que-reparte-una-columna-nueva.md) —falta el
Query Builder, que ahí salió a cero—, y es el mismo documento, escrito por `profesores.tono`
nueve días antes.

> Lo que **sí** se conserva byte a byte, y ése es el test que hay que escribir: **con el enum en
> `ponderado` y las tablas vacías, las unidades, las notas, las definitivas y los tres boletines
> no cambian en nada.** Ninguno de los tres boletines está entre las tres que se mueven.

### 1.4 · Nadie podría *escribir* `modelo_evaluacion` — y eso es `profesores.tono` otra vez

`YearsController::putGuardarCambios` escribe **veintiuna columnas nombradas una a
una** (líneas 605-629). Una columna nueva de `years` que no se añada ahí **no la puede
poner nadie**, ni el superusuario: se leería en las tres respuestas de §1.3 y saldría
`'ponderado'` en los dieciséis para siempre.

Es exactamente el hueco de `profesores.tono` del 4 sep —columna leída en todas partes
y escrita en ninguna—, y esta vez se ve **antes** de cometerlo, que es lo que pedía la
§5.7.a del doc 28 al hablar del alcance de la plantilla.

Y al mirar el camino de escritura aparece la pregunta que el documento de decisiones
no se hizo: **`PUT years/guardar-cambios` va con `auth.personal`, o sea que lo llama
cualquier docente.** Los dieciséis `years/*` de escritura son `auth.personal`, sin
excepción. Meter ahí `modelo_evaluacion` es dejar que **cualquier docente del colegio
cambie el modelo de evaluación del año** desde un `PUT` de dos campos.

**La propuesta, ACEPTADA ENTERA por Joseth el 13 sep como D24**:

- los **tres `displayname`** del desempeño van en `putGuardarCambios`, al lado de los
  seis que ya están, con su mismo guard: son rótulos, y el que puede renombrar
  «Subunidad» puede renombrar «Desempeño». **Cero rutas.**
- **`modelo_evaluacion` va en una ruta propia**, `PUT years/modelo-evaluacion`, con
  `auth.personal` en la ruta y `Autoriza::puedeEditarPlantillaNotas` **dentro** — la
  forma de `PlantillaNotasController`, y por el mismo motivo: lo que configura el
  colegio, el docente no lo toca. **Una ruta**, que el documento de decisiones no
  contaba.

### 1.5 · La rejilla premarcada no tenía qué premarcar — **desbloqueada el 13 sep (D23)**

**Salió de cruzar D5 con D6, y bloqueó la Fase 4 durante unas horas.** El desajuste era real y
lo sigue siendo; lo que no valía era el marco en que este apartado buscó la salida — ver el
recuadro del final.

D6 dice que la rejilla *«se abre con las casillas ya marcadas según
`escalas_de_valoracion`»*, y señala la máquina que ya existe. La comprobé, y el
mensaje del front es correcto en todo lo que afirma:
`Unidad::deAsignaturaCalculada` (`app/Models/Unidad.php:141`) tiene los dos modos,
`fortaleza_debilidad` e `con_desempenio`, los dos derivan al imprimir y ninguno
guarda nada.

**Y aun así la regla no cierra**, porque las dos piezas no encajan:

| lo que da la máquina del rango | lo que pide la rejilla |
|---|---|
| un **nivel** por alumno — `escalas_de_valoracion.desempenio`: «Superior», «Alto», «Básico», «Bajo» | un **sí/no por cada texto**: ¿este alumno alcanzó *«Identifica los tipos de triángulo»*? |

`escalas_de_valoracion` son `(desempenio, valoracion, porc_inicial, porc_final,
descripcion, orden, perdido, year_id)` — comprobado en el volcado, línea 1031. **No
tiene ninguna relación con un catálogo de textos**, y la fila de `desempenos` que
propone D5 —`competencia_id NULL, tipo NULL, definicion, orden, por_defecto`— **no
lleva nivel**. Así que no hay ninguna función que lleve de «este alumno sacó Alto» a
«se le premarcan estos cuatro textos y estos seis no».

> ## ✅ CONTESTADA el 13 sep 2026 por Joseth — **D23, y no es ninguna de las dos salidas que
> este apartado planteaba**. `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md` §7.bis.
>
> **La celda ES el nivel.** Cada cruce alumno × desempeño guarda **Superior / Alto / Básico /
> Bajo**, premarcado con el nivel que el alumno ya tiene por su nota. Con eso **el desajuste
> desaparece por la raíz en vez de traducirse**: la máquina del rango produce un nivel y la celda
> guarda un nivel, 1:1.
>
> El error de este apartado está en su propia tabla: dio por supuesto que la celda era un
> **sí/no**, y de ahí salieron dos salidas que sólo se diferenciaban en cómo llegar de un nivel a
> un booleano. **La pregunta correcta no era «qué texto escoge el nivel», era «por qué la celda
> es binaria»** — y no lo era.
>
> Y no es un arreglo de conveniencia: es lo que **ya imprimen** los boletines de la investigación
> §2.3 —Mutis, literal: *«los indicadores de desempeño y su correspondiente **nivel valorativo**
> (Superior, Alto, Básico y Bajo)»*—. El «alcanzado / pendiente» de los otros colegios es el
> **caso degenerado**: en la escala del 1290, **Bajo es pendiente**.

**Las dos salidas que se plantearon, y se dejan escritas porque una sigue estando descartada:**

| regla | qué cuesta | qué significa de verdad |
|---|---|---|
| **A · «alcanza quien aprobó»** — se premarcan **todos** los desempeños del alumno cuya nota cae en una escala con `perdido = 0` | cero esquema | el docente abre la rejilla con **todo marcado para los que aprobaron** y su trabajo es **desmarcar**: es *«todos alcanzan y el docente quita»*, o sea la decisión 10 |
| **B · un texto por nivel** — el colegio escribe cuatro redacciones del mismo desempeño y se enseña la que toca | una columna, y **cuatro veces la escritura** | es lo que **D8 descartó**: *«un texto por nivel de la escala (lo más rico, y multiplica por cuatro el trabajo de escritura)»*, literal |

> **Una precisión, porque `myvc-front-53` la corrigió al revés y alguien va a reabrir B con
> ella.** Su mensaje dice que *«D8 descartaba que cada desempeño llevara su dificultad y su
> recomendación — una banda es otra cosa»*. Eso describe el **primero** de los dos descartados de
> D8; el **segundo** es, palabra por palabra, *«un texto por nivel de la escala … multiplica por
> cuatro el trabajo de escritura»*, que es exactamente la B de esta tabla y exactamente el motivo
> que se le puso. Comprobado en su propio documento.
>
> **No cambia nada del resultado** —B sigue descartada, y por esa misma razón, que es la que
> ellos vuelven a derivar—, y **no toca D23**: guardar el nivel **en la celda** no es escribir un
> texto por nivel, así que D23 no roza D8 por ningún lado. Se anota sólo para que el día que
> alguien diga *«D8 no descartaba esto»* no se reabra una decisión buena con una cita que no
> dice lo que se le atribuye.

#### Lo que D23 le cuesta a este backend, y no estaba en la decisión

Cuatro cosas que salen de cruzar D23 con el esquema, y ninguna es cara:

1. **La celda guarda el id del nivel Y su texto**, igual que `frase_id` guarda el id y `frase` el
   texto. `escalas_de_valoracion` es **por año y editable**, y las dos mitades están medidas:
   `EscalasDeValoracionController` hace `UPDATE … SET desempenio=:desemp … WHERE id=:id` —o sea
   que **renombrar un nivel reescribe la fila viva**, la misma que un boletín viejo leería por su
   id— y `YearsController:228` copia las escalas al año siguiente con **`new` y `save()`**, o sea
   **con ids nuevos**: las del año pasado siguen ahí, y siguen siendo editables. Una celda que
   guarde sólo el id deja que renombrar «Básico» en 2028 **cambie un boletín impreso en 2026**.
   Es la regla 1 de la §4 y el mismo argumento que ya hizo D9 con `frase`. Por eso el nivel gasta
   **dos columnas y no una** —`escala_id` para pintar la casilla y `nivel` para lo que se
   imprime—, que con `desempeno_id` hacen las **tres** de la migración de la Fase 4.
2. **El premarcado NO sale de `deAsignaturaCalculada`.** Ese método cruza la escala **por
   unidad**; la rejilla es **por asignatura y periodo**. Lo que se reutiliza es **la forma** del
   cruce (`porc_inicial <= nota <= porc_final`, `year_id`), no el método: la nota de la que sale
   el nivel es la definitiva, y está en `notas_finales`. **Una consulta por grupo y periodo.**
3. **Un alumno sin definitiva sale SIN nivel, no en «Bajo».** No tener nota y sacar 0 no son lo
   mismo, y la diferencia acaba impresa en un boletín. La celda nace `NULL` y el docente la pone.
4. **Las bandas pueden no cubrir la recta.** Nada obliga a que las `escalas_de_valoracion` de un
   año sean contiguas: con 0-59 y 61-100, **un 60 no casa con ninguna**. Ese alumno sale `NULL`
   también, y **la respuesta lo cuenta** —`sin_banda`— en vez de callárselo. Población, no `OK`:
   un colegio con la escala mal montada tiene que poder verlo desde la respuesta.

### 1.6 · `desempenos_por_defecto` hereda el problema de precedencia que la plantilla ya resolvió

D5 pone `grado_id NULL` = «para todos los grados». Es la misma forma que
`unidades_por_defecto.nivel_educativo_id`, y ahí ya se aprendió lo que cuesta: la
§5.7.a del doc 28 tuvo que inventar **cuatro gradas ordenadas** y aplicar *«la grada
entera, nunca una mezcla»*, porque mezclar dos daba una plantilla que nadie escribió.

Aquí la pregunta vuelve con dos ejes —`materia_id` y `grado_id NULL`— y **el
documento de decisiones no la contesta**. La diferencia con la plantilla es que aquí
**es menos peligrosa**: los textos no suman 100, así que «se aplican las dos» es
defendible y no produce ningún número raro. Pero se decide, no se deja al `ORDER BY`.

**ACEPTADA por Joseth el 13 sep como D25: acumulan, no compiten** — un desempeño de «Matemáticas,
todos los grados» y otro de «Matemáticas, 6.º» se siembran **los dos**, ordenados por
`orden`. Y **no se reutiliza `App\Support\AlcanceDeLaPlantilla`**: sus gradas existen
para no mezclar repartos que suman 100, y aplicarlas aquí escondería textos que el
colegio escribió.

> **Y es deliberadamente lo contrario que la plantilla, donde gana la más específica.** La frase
> es de `myvc-front-53` y hacía falta: quien lea las dos reglas seguidas sin ella va a dar por
> hecho que una de las dos está mal. **Allí son porcentajes que compiten por el 100 %; aquí son
> textos que conviven.** El día que alguien «unifique» las dos precedencias, esto es lo que hay
> que releer.

### 1.7 · La rama del `ALTER` va 169 commits por detrás: 1.926 es cierto, y no es de este árbol

La rama `fix/frases-asignatura-text` existe, está sin fusionar y es exactamente lo que
dice el mensaje del front: **tres commits, dos ficheros** —la migración
`2026_09_05_100000_frase_del_boletin_en_text` y `tests/Contrato/FraseLargaEnElBoletinTest`—
y **ninguna instantánea tocada** (`git diff --stat` sobre la fusión: 209 líneas, dos
ficheros nuevos). Y `frases_asignatura.frase` **sigue siendo `varchar(255)`** en
`database/schema/mysql-schema.sql:1078`.

Lo que el mensaje del front no dice, y cambia el paso 1 del plan:

```
merge-base:  ab23e2d
main:        c0ed278  ->  169 commits por delante
migraciones que entraron en main desde el merge-base:  7
```

> **Y ese 169 ya envejeció mientras se escribía este documento**, que es la demostración más
> barata de por qué la cifra se ancla al hash. `myvc-front-53` midió **171** una hora después y
> tenía razón: `main` había avanzado **dos commits, los de este mismo fichero**. Las dos son
> ciertas, y la que no caduca es el `merge-base`.
>
> **Y el segundo número tampoco discrepaba: contestaba otra pregunta.** Ellos midieron **ocho**
> ficheros de migración y aquí hay **siete**. Ocho es `git diff main rama` — la **diferencia
> simétrica**, que incluye la migración que trae la propia rama. Siete es `git diff base..main`:
> las que entraron **en `main`** y hay que volver a atravesar. Las dos órdenes contestan bien; la
> pregunta del plan es la segunda. Es la familia de *«el detector contesta lo que le preguntaron
> y quien pregunta mal es uno»*, y aquí no se equivocó nadie — sólo hacía falta decir cuál era la
> pregunta.

El commit `50399f6` publica su cifra **bien**, con la orden y con el aviso de
población: `Tests: 1926 passed (17317 assertions)`, 954 s, y de su puño *«1.926 no se
compara con las 1.941 de `f5` … son poblaciones distintas»*. **Es una medición de
`ab23e2d`**, no de `main`. Siete migraciones después —entre ellas el alcance de la
plantilla y `grupos_con_ih`— esa cifra no dice nada del árbol fusionado.

**Consecuencia para el plan**: la Fase 0 no es *«fusionar»*, es **rebasar, migrar y
volver a correr la suite entera**, y publicar la cifra nueva con la orden que la
produjo y el hash contra el que corrió. Es la regla de `CLAUDE.md` —una cifra de
pruebas se publica con su orden, y una medición se anota con su hash— y aquí muerde
por el sitio de siempre: un `ALTER` de tipo se corre entero después de migrar.

### 1.8 · Lo que el encargo dice y comprobé que es cierto

Para que no haya que volver a mirarlo:

- **`can_edit_plantilla_notas` existe** y es `Autoriza::PERMISO_PLANTILLA_NOTAS`
  (`app/Support/Autoriza.php:47`), creado por `2026_09_05_300000`. ✔
- **El rol «jefe de área» no existe**: cero apariciones en `app/`, `routes/` y
  `database/`. ✔
- **Los tres boletines son 629 + 605 + 586 líneas**, y viven en
  `app/Http/Controllers/Informes/`. ✔
- **`FrasesAsignaturaController::postStore` sigue sin comprobar** que la asignatura sea
  del profesor ni que el alumno esté en ella — hoy, 13 sep. Guarda una frase por
  llamada y fija `periodo_id = $user->periodo_id`. ✔
- **La definitiva del alumno está en `notas_finales`** (alumno + asignatura + periodo),
  así que el premarcado de la rejilla es **una consulta por grupo y periodo**, no una
  por alumno. No hay tabla `definitivas`.
- **La cadena para sembrar existe**: `asignaturas.grupo_id` → `grupos.grado_id` →
  `grados.nivel_educativo_id`. ✔

### 1.9 · «Entrega 1 HECHA» y «Entrega 1 en uso» no son lo mismo — las separan nueve rutas sin cliente

Medido por `myvc-front-53` el 13 sep y **recontado aquí con un control**, porque un «0 encontrados»
no distingue *«revisé y no está»* de *«no revisé»*:

| | |
|---|---|
| `plantilla-notas` en `myvc_front/app2/src` + `myvc_front/app/scripts` | **0** |
| `can_edit_plantilla_notas` en los mismos | **0** |
| `plantilla-notas` en `myvc_flutter/lib` | **0** |
| **control** — `boletin-independiente` en los dos fronts | **113** |

**Las nueve rutas de `plantilla-notas` están vivas y enrutadas, y no las llama nadie.** O sea que
la plantilla del colegio **se sigue editando en phpMyAdmin**, que es literalmente el problema que
esa entrega existía para resolver. Es la familia de `profesores.tono` otra vez — la tercera en este
mismo documento (§1.4 y ésta) — con la diferencia de que aquí no falta el camino de escritura en el
backend: falta el cliente.

**Dos consecuencias para este plan, y la segunda corrige una lectura fácil:**

1. **El molde de la Fase 3 nunca ha corrido en un colegio.** `desempenos/sembrar` va «calcado de
   `PlantillaNotasController::putSembrar`», y ese método tiene **21 casos de contrato y cero
   ejecuciones en producción**. Los tests son reales y se han visto rojos por los dos lados
   (doc 28 §5.1); lo que no hay es el desgaste de un colegio de verdad usándolo. Se dice para que
   «calcado de» no se lea como «probado en los dieciséis».

2. **Que no haya pantalla NO explica un censo de `por_defecto = 1` a cero**, y conviene fijarlo
   antes de que alguien archive ese número. Las filas que el censo cuenta **no las escribe la
   pantalla nueva**: las marca el **sembrador viejo**, `UnidadesController:184` y `:193`, que lleva
   años copiando `unidades_por_defecto` → `unidades` con `por_defecto = true` literal. Lo que la
   pantalla nueva iba a facilitar es **llenar `unidades_por_defecto`**, que es lo que hoy se hace a
   mano. Así que un censo a cero dice **«ningún colegio tiene plantilla escrita a mano que alguien
   haya abierto después»** — que es un dato sobre los colegios, no un artefacto de la pantalla que
   falta. Es la §1.bis del doc 28 otra vez: *«revisé nueve años y ninguno tenía plantilla»* no es
   *«esto no le pasa a nadie»*, y aquí la confusión iría en la dirección contraria y peor —
   descartar como ruido un cero que sí significa algo.

**Lo que NO cambia**: el orden de las fases. Este plan ordena por **qué se puede desplegar entero**,
no por qué está escrito, así que la Entrega 1 sin cliente no adelanta ni atrasa nada de aquí. Lo que
sí obliga es a no contar la Entrega 1 como capacidad que el colegio ya tiene.

---

---

## 2. El orden: siete fases, cada una desplegable sola

La regla de corte es la misma de `horario/` y de la Entrega 1: **una fase se despliega
sola si, desplegada sola, el colegio puede hacer algo entero con ella.** Lo que no
cumple eso va junto aunque sea más trabajo.

| fase | qué entrega | rutas | migraciones | instantáneas de **ruta** | de **contenido** |
|---|---|---|---|---|---|
| **0** | el `ALTER` a `text`, remedido | 0 | 1 (ya escrita) | 0 | **0** — y era lo que se venía a comprobar |
| **1** | el colegio elige su modelo y le pone nombre | **1** | 1 | 3 | **3** solas + 6 ensanchadas a mano |
| **2** | competencias, con el catálogo del MEN | 7 | 1 | 3 | 0 |
| **3** | desempeños: catálogo, siembra y los propios del docente | **12** | 1 | 3 | 0 |
| **4** | la rejilla premarcada | 2 | 1 | 3 | 0 |
| **5** | qué comparten los tres boletines *(medición, sin código)* | 0 | 0 | 0 | 0 |
| **6** | el boletín nuevo | 4 | 0 | 3 | por decidir con la maqueta |

> **Esta tabla decía «0 instantáneas» en las fases 2, 3, 4 y 6, y era una contradicción con la §3
> de este mismo documento**, que dice que **toda** tanda con rutas mueve `rutas.json`,
> `guards-por-ruta.json` y `guard-por-familia.json`. La columna contaba **contenido** y no lo
> decía — el mismo descuido de población que §1.3 cometió tres veces, cometido aquí en una tabla.
> Partida en dos, que es lo que había que hacer desde el principio.
>
> **Comprobado sobre lo ya fundido**: las Fases 2 y 3 movieron **exactamente esas tres** y nada
> más. Y **`familias-que-nunca-entran-en-el-candado.json` no se ha movido ni una vez en todo el
> día** —cero apariciones en los commits del 13 sep—, que era la predicción: `competencias` entró
> con 7 rutas guardadas y `desempenos` con 12, así que ninguna de las dos familias nuevas llega a
> ese censo, que sólo lista las de **menos de dos** hermanas con guard.

**Total: 26 rutas**, no 20 ni 22. Las 20 del documento de decisiones, **+1**
`PUT years/modelo-evaluacion` (§1.4), **+1** el `GET desempenos` de la planilla y **+4** los
desempeños propios del docente que exige D14 (Fase 3). **Las seis salieron de huecos que el
documento no vio al escribirlo, no de ampliar el encargo** — y las tres veces por la misma forma:
una capacidad decidida que no tenía por dónde ejercerse. Se cuentan el día que entren, con
`route:list --json`, **en el árbol donde se escriban** (§3).

> ### La deuda que abre la Fase 2 y no cierra nadie: `competencias` no se copia al año siguiente
>
> `competencias` es **por año**, igual que la plantilla. Tal como queda la Fase 2, **al crear el
> año siguiente no se copia**, así que el colegio reescribe su plan de área cada enero. Es la
> §1.bis del doc 28 **exacta** —las subunidades por defecto sin copiar durante años, sin un error
> en ningún log—: no rompe nada hasta enero, y en enero no hay nada que lo explique.
>
> **Se dice aquí que la abre la Fase 2, no que «ya existía»**: la tabla no existe todavía. Quien
> la crea, crea la deuda.
>
> **Dónde va, decidido aquí para que no se caiga entre dos ramas**: es **entrega propia sobre
> `main`**, después de fundir `feat/competencias`, y **no dentro de la Fase 2**. El motivo es de
> fontanería y es bueno: toca `YearsController`, que la Fase 1 ya cambió en `main`, y la rama de
> competencias sale de un commit anterior — editarlo allí es fabricar un conflicto. Va junta con
> **el centinela de las tablas que se copian al crear un año**, que este documento ya pedía en la
> Fase 3 y que es lo único que impide que vuelva a pasar con la siguiente tabla por año.

---

### Fase 0 · El `ALTER`, rebasado y remedido · **HECHA el 13 sep 2026**

**No era opinable y iba primero**: todo lo que escriben las fases 3 y 4 acaba en
`frases_asignatura.frase`, y hay 626 frases ya cortadas (doc 28 §1.ter).

```
Tests:    2098 passed (18717 assertions)     rojos 0 · saltados 0
orden:    php artisan test
árbol:    /app/.worktrees/f @ 12890ce, rebasado sobre main @ 8e752a0, sin conflictos
base:     simonbolivar_testing_f reconstruida — 22/22 migraciones, 102 tablas
duración: 1.375,75 s
y el árbol quedó LIMPIO: ninguna instantánea de contrato se movió
```

**La rama estaba 171 commits por detrás con siete migraciones de `main` en medio**, así que sus
1.926 verdes eran ciertas y eran de `ab23e2d`. Por eso la fase no era «fusionar» sino **rebasar,
migrar y volver a medir** — y la diferencia (+172) son los tests que trajeron esos commits.

> **Y una sesión que trabajaba en paralelo lo midió por su cuenta sobre el árbol principal:
> 2.098 también, 1.372,64 s.** Dos árboles distintos, dos bases distintas, dos sesiones que no
> se coordinaron para esto — y el mismo número. Es la clase de coincidencia que **sí** vale como
> confirmación, porque ninguna de las dos copió a la otra.

#### Qué cubren esas 2.098, porque «la suite entera» no basta

Este documento dijo primero *«las tres testsuites»*, copiando la frase que anda por ahí escrita.
**Son cuatro las declaradas en `phpunit.xml`** —`Unit`, `Feature`, `Contrato` y `Barrido`— y se
excluyen **dos grupos**, no uno:

| grupo excluido | qué deja fuera |
|---|---|
| `barrido` | `tests/Barrido/`: mide qué alcanza un token de verdad, tarda y **no afirma nada** — imprime un informe |
| **`rojo`** | los tests **rojos a propósito**, que fijan un fallo que espera una decisión de Joseth |

**El segundo es el que hay que decir en voz alta**: «2.098 verdes» **no** quiere decir que no
haya nada rojo en el repositorio. Quiere decir que no hay nada rojo **fuera de los que se
excluyen por serlo a propósito**. Un lector que entienda lo primero archiva lo segundo.

#### Cómo entró en `main`, que no fue como estaba planeado

**El `ALTER` y su test entraron en `10a09a8`, un commit de documentación que no los menciona**, y
la causa es mía: `git add -A docs/migracion/` limita lo que **añado**, no lo que **commiteo** —
`git commit` se lleva **el índice entero**, y otra sesión tenía esos dos ficheros preparados en
el índice compartido de este árbol. El commit de fusión `f55878c` que vino después **no trae
ningún fichero**: la rama ya estaba dentro por accidente, y lo que aporta es la **procedencia**
—los cuatro commits de la rama, incluida la remedición con su orden, su hash y la comprobación
del árbol limpio—, que sin él no estaría en `main` en ninguna parte.

> **Lo que ese accidente NO rompe, comprobado antes de dejarlo estar: el despliegue no está
> ciego.** La pregunta razonable es si una migración escondida en un commit de documentación se
> le pasa a `docs/DESPLIEGUE.md`. **No**, y por cómo está escrito ese documento: la lista de
> migraciones de una tanda **no se teclea, se le pregunta a git** —
> `git diff --name-only <base>..main -- database/migrations/`—, y a ese comando **le da igual qué
> commit trajo el fichero**. Comprobado hoy: sobre `9474b50..main` devuelve **nueve** ficheros y
> `2026_09_05_100000_frase_del_boletin_en_text.php` está entre ellos.
>
> Lo que sí queda por hacer **el día del despliegue, no hoy**: esa migración todavía no tiene su
> renglón en la tabla de «qué se cae si falta» de `DESPLIEGUE.md`. Las tablas de ese documento son
> **lo que se midió el día de un despliegue y se remiden el día del siguiente**, así que se escribe
> entonces y con el número contado, no ahora.

> **La regla que sale de aquí, y vale para cualquier árbol donde trabaje más de una sesión:**
> **se commitea con pathspec explícito** —`git commit -- <rutas>`—, o se mira `git status` antes.
> `git add` de una carpeta **no acota el commit**. El síntoma no es un error: es un commit que
> pasa en verde y **lleva dentro algo que su mensaje no nombra**, que es exactamente lo que un
> despliegue lee mal — `10a09a8` parece documentación y **trae una migración**.
>
> No se reescribe el historial para taparlo: otra sesión ya había commiteado encima, y rehacerlo
> por debajo rompe más de lo que ordena. **Se anota, que es lo que este repositorio hace con los
> errores que no se pueden deshacer.**

**Lo que no hace, y ya está decidido**: no repara hacia atrás y no se avisa a nadie
(decisión 12 del doc 28). Cada reimpresión de un boletín viejo seguirá saliendo cortada.

> **La migración correrá FUERA DE ORDEN en los colegios, y no pasa nada — pero hay que saberlo
> antes de verlo.** `2026_09_05_100000_frase_del_boletin_en_text` lleva una marca de tiempo
> **anterior** a tres que ya están en `main`: `2026_09_05_200000_alcance_de_la_plantilla`, la
> `300000` del permiso y `2026_09_07_100000_grupos_con_ih`. En una base nueva corre en su sitio
> —se vio así en la reconstrucción—, pero donde aquéllas **ya corrieron** el migrador sólo mira
> las **pendientes**: ésta entra **la última**, con fecha de antes.
>
> **No tiene consecuencia**, y decir por qué es lo único que hace útil el aviso: toca
> `frases_asignatura`, y las otras tres tocan `unidades_por_defecto`, `permissions` y `grupos`.
> **Cero solape.** Lo que no daría igual es una migración retrasada que tocara una tabla que otra
> ya transformó — ésa es la forma que hay que buscar la próxima vez que una rama vieja se funda
> tarde.

**Y fusionado no es desplegado.** `app/` es copia por colegio; el volcado
`database/schema/mysql-schema.sql` **no se toca** —es el esquema congelado de producción— así que
ahí la columna sigue diciendo `varchar(255)` hasta que la tanda llegue a los dieciséis.

---

### Fase 1 · El colegio elige su modelo

```
2026_09_XX_100000_modelo_de_evaluacion_del_anio

years + modelo_evaluacion  enum('ponderado','competencias') NOT NULL DEFAULT 'ponderado'
      + desempeno_displayname   varchar(255) NOT NULL DEFAULT 'Desempeño'
      + desempenos_displayname  varchar(255) NOT NULL DEFAULT 'Desempeños'
      + genero_desempeno        varchar(1)   NOT NULL DEFAULT 'M'
```

**`PUT years/modelo-evaluacion`** — `auth.personal` en la ruta,
`Autoriza::puedeEditarPlantillaNotas` dentro (§1.4). Los tres `displayname` entran en
`putGuardarCambios`, sin ruta nueva.

**CUATRO cosas que hay que tocar y que nadie pediría solas:**

1. **`YearsController::postStore` copia las cuatro al crear el año siguiente.** Lo caza
   `CentinelaDeLasColumnasDelAnioNuevoTest`, que vigila que no se deje ninguna columna
   de `years` — es la única de las cuatro que tiene ya quien la vigile.
2. **`subunidad_displayname` deja de sugerir «Indicador»** en la pantalla de
   configuración (D15) **sin tocar el valor que cada colegio guardó**. Es un cambio del
   front; aquí sólo se anota para que nadie lo "arregle" en la base.
3. **El enum no toca ni un cálculo.** Es la propiedad que no hay que perder: volver
   atrás es cambiar el enum.
4. **⚠️ Y cerrar la puerta de al lado: `PUT years/toggle-cambiar-valor`.** Esta lista decía
   «tres» y le faltaba ésta.

> ### La puerta de al lado, que este apartado no vio y por poco deja a D24 en decorativa
>
> **Hallada el 13 sep 2026 por la sesión que escribió la Fase 1, y llegada por `8myvc-2e`.**
>
> `PUT years/toggle-cambiar-valor` (`routes/api/estructura.php`, `auth.personal`) es el «guardar
> un campo suelto» de la rejilla de configuración y escribe **cualquier columna de `years` que
> exista**, resolviéndola por `ColumnaSegura::exigir('years', $campo)`. Hasta hoy eso no era un
> agujero, y su propio comentario lo justificaba: *«quien pasa `auth.personal` ya las escribe
> todas por `years/guardar-cambios`»*.
>
> **`modelo_evaluacion` es la primera columna para la que esa frase es falsa**, y lo es **a
> propósito**: D24 le dio ruta propia con `can_edit_plantilla_notas` dentro precisamente para
> que no la cambie cualquier docente, así que **no** entra en las veintiuna de
> `putGuardarCambios`. Sin cerrar esta puerta, la decisión se salta con un `PUT` de tres campos
> **y no lo diría ningún test**: el de D24 comprobaría el 403 en la ruta nueva y pasaría en verde
> mientras la columna se escribe por la vieja.
>
> Se cierra como ya estaba cerrada `actual` —un `abort(422)` con el nombre de la ruta que sí
> vale—, y **son dos exclusiones por motivos distintos**, que conviene no confundir: `actual`
> tiene un **invariante de fila** (uno solo encendido); `modelo_evaluacion` tiene **dueño**. La
> lista crece por el segundo motivo cada vez que entre en `years` una columna que no pueda
> escribir todo el personal.
>
> **Y la lección que vale más que el arreglo**, porque es de la familia de *«crear un rol no
> regala permisos»*: **al darle dueño a una columna hay que repasar TODOS los caminos que
> escriben esa tabla, no sólo el que se está tocando.** Este documento contó los dieciséis
> `years/*` de escritura para decir que todos son `auth.personal` (§1.4) — y con esa misma lista
> delante **no miró qué escribe cada uno**. Un guard nuevo sobre una ruta no cierra una columna:
> la cierra el censo de quién más puede escribirla.

**Tests de contrato:**

- **El que sostiene la fase**: con `modelo_evaluacion = 'ponderado'`, la respuesta de
  `unidades/de-asignatura-periodo`, la definitiva de una asignatura y los **tres**
  boletines salen **idénticos** a antes de la migración. Se ve rojo poniendo el enum
  en `competencias` y comprobando que **tampoco** cambia — porque no debe cambiar
  ningún cálculo en ninguno de los dos modos (D3).
- **Las tres instantáneas de §1.3** —`muestreo-years`, `-colegio` y `-trashed`— se regeneran
  **en un commit aparte y con el diff leído**: lo único que puede aparecer son las cuatro claves
  nuevas, y **sólo en esas tres**. Cualquier otra cosa en ese diff es un hallazgo, no ruido — y
  **una cuarta instantánea que se mueva es el hallazgo**: querría decir que hay un cuarto camino
  que publica la fila entera y que §1.3 no encontró.
- Un docente sin `can_edit_plantilla_notas` recibe **403** en
  `PUT years/modelo-evaluacion`, y **200** en `years/guardar-cambios` con los
  displayname — que es lo que demuestra que la línea se trazó donde se quería.
- Un año creado a partir de otro **hereda las cuatro columnas**.

> ## ✅ FASE 1 HECHA — 13 sep 2026, commit `4e0033c`
>
> Migración `2026_09_13_100000_modelo_de_evaluacion_del_anio` (las cuatro columnas en **un solo
> `ALTER`**), `Year::MODELOS_DE_EVALUACION`, `YearsController::putModeloEvaluacion`, los tres
> rótulos en `putGuardarCambios`, las cuatro copiadas en `postStore`, el corte de la puerta de al
> lado en `putToggleCambiarValor`, `ContextoDeUsuario` en sus cuatro ramas,
> `PUT years/modelo-evaluacion` en `routes/api/estructura.php`, y
> `tests/Contrato/ModeloDeEvaluacionDelAnioTest` con **17 casos**.
>
> ### El contrato, tal como contesta el docker — no leído del código
>
> ```
> PUT /api/years/modelo-evaluacion          auth.token + auth.personal + can_edit_plantilla_notas
> { "modelo_evaluacion": "ponderado"|"competencias", "year_id": <int, opcional> }
>
> 200 { year_id, modelo_evaluacion, anterior,
>       desempeno_displayname, desempenos_displayname, genero_desempeno }
> 403 sin el permiso · 422 valor fuera del enum · 404 año inexistente o en la papelera
> ```
>
> Sin `year_id` escribe **el año de la sesión**. `anterior` viaja para que la pantalla pueda decir
> «pasó de X a Y» sin abrir la auditoría, que se escribe con `Auditoria::registrar()->editar('year_config', …)`.
>
> **`modelo_evaluacion` viaja en la sesión**, que es de lo que depende el front para decidir si
> enseña lo nuevo: `POST auth/login` (dentro de `usuario`), `GET auth/me` y `POST login` (en la
> raíz), con `desempeno_displayname`, `desempenos_displayname` y `genero_desempeno` al lado.
> Comprobado con el contenedor levantado, no deducido.
>
> ### La cuenta de instantáneas: **diez**, y las siete de más NO son el hallazgo que avisa §1.3
>
> Ésta es la parte que hay que leer, porque el recuadro de arriba dice *«una cuarta instantánea
> que se mueva es el hallazgo»* y se movieron diez. **No hay ningún cuarto comodín.** El reparto:
>
> | | |
> |---|---|
> | **3** · la columna sola | `muestreo-years`, `-colegio`, `-trashed` — los tres caminos con comodín. **Exactamente lo que predecía §1.3** |
> | **6** · a mano, y a propósito | los cuatro `login-contexto-*`, `muestreo-auth-me` y `muestreo-aplicacion-descargas-detailed`. Son **proyecciones nombradas**, y se movieron porque **este commit las ensanchó**: el front necesita `modelo_evaluacion` en la sesión |
> | **3** · la ruta | `rutas`, `guards-por-ruta`, `guard-por-familia` |
>
> **La distinción que hay que conservar**: §1.3 avisa de una instantánea que se mueve **sola**,
> porque eso significa un camino con comodín que nadie encontró. Una proyección nombrada que se
> mueve porque alguien le añadió una columna **no dice nada nuevo sobre el esquema** — dice lo que
> ese alguien escribió. Leer el diff sigue siendo obligatorio y **se leyó entero: 40 líneas, y no
> hay una sola que no sea una de las cuatro claves o la ruta nueva.**
>
> `familias-que-nunca-entran-en-el-candado.json` **no se movió** (`years` pasa de 17 de 19 a 18 de
> 20 con guard), `RutasPreLoginTest::TOTAL_PUBLICAS` sigue en **doce** y `AutenticacionTest::SIN_GUARD`
> no se movió.
>
> ### El router: **580**, contado
>
> `route:list --json` en el **árbol principal** (`/app`): **580**, de ellas **579** bajo `api/`. La
> 580 es `PUT years/modelo-evaluacion`. No se le sumó uno a 579: se contó, y se dice el árbol.
>
> ### Y una desviación del plan, dicha para que no se lea como descuido
>
> Este apartado pedía regenerar las instantáneas **en un commit aparte**. Van en el mismo, y el
> motivo es que la otra mitad de la regla de la casa —*«se commitea en cuanto una fase pasa sus
> pruebas»*— no se puede cumplir a la vez: un commit de código con las instantáneas viejas es un
> commit **rojo** en `main`, y `main` es lo que corre en los dieciséis colegios. Lo que el commit
> aparte compraba era que el diff se leyera; eso se compró de otra forma, escribiéndolo entero en
> el mensaje.

---

### Fase 2 · Competencias, y el catálogo del MEN que las siembra

```
2026_09_XX_200000_competencias

competencias
  id, year_id, materia_id, grado_id NULL, alumno_id NULL,
  definicion text, orden int,
  created_by/updated_by/deleted_by/deleted_at/created_at/updated_at
  KEY (year_id, materia_id, grado_id, alumno_id)

  -- sin padre, sin porcentaje y sin nota: sus hijos son los desempeños,
  -- que la apuntan con `competencia_id` desde la Fase 3.
```

`alumno_id NULL` **se queda** (Decreto 1421/2017, informe anual de competencias del
alumno con PIAR) y se lee con `<=>` a través de
`App\Services\BoletinIndependiente::alcance()`, igual que `unidades`. **Con
`alumno_id = null` se seleccionan exactamente las filas de antes.**

**7 rutas**: las 6 de la §5.2 del doc 28 —`GET`, `POST`, `PUT {id}`, `DELETE {id}`,
`PUT orden`, `PUT copiar`— más **`GET competencias/catalogo-men`** (D11).

> **El catálogo del MEN va DENTRO de la familia `competencias/`, y no es cosmético.**
> Una ruta sola en una familia propia entra en
> `familias-que-nunca-entran-en-el-candado.json` como **«1 de 1»**, que es la forma que
> tendría un agujero y hay que defender por escrito. Colgada de `competencias/` —que
> tendrá siete con guard— **ese censo no se mueve**. Es gratis elegir bien el nombre.

- Los **Estándares Básicos** y los **DBA** viajan **como fichero de datos con el
  código**, no como tabla sembrada en cada colegio: se actualizan con el despliegue y
  no hay que migrar dieciséis bases para corregir una errata del MEN.

> **Y la ruta se pide POR MATERIA Y GRADO, no entera — esto faltaba y lo destapó el front.**
> Este apartado especificaba `GET competencias/catalogo-men` sin decir **qué devuelve de una
> vez**, y eso deja abierta la puerta de que devuelva el corpus completo: los EBC de seis áreas
> por cinco conjuntos de grados, más los DBA grado a grado. **Nadie ha medido ese peso todavía**
> —`myvc-front-50` lo está midiendo— y una ruta que puede crecer sin tope en un repositorio cuyos
> informes ya tardan 24-63 s no es un detalle que se afine después.
>
> **Se cierra por diseño en vez de por medición**: la ruta lleva `materia_id` y `grado_id`, que
> es **exactamente el alcance con el que se adopta**. El colegio adopta las competencias de
> Matemáticas de 6.º, no las de todo el MEN, así que la llamada que hace falta es la pequeña. Con
> eso **el tamaño del corpus deja de decidir nada del contrato** y la medición pasa a contestar
> otra pregunta, que es la que importa: cuánto pesa en el despliegue, porque `app/` es copia real
> en los dieciséis.
>
> Es la forma barata de la regla de siempre: **no hace falta medir para elegir bien cuando se
> puede elegir algo cuyo coste no depende de lo que se mediría.**
- **Adoptar copia**; el catálogo no manda sobre nada (regla 1 de la §4 del doc 28).
- **Lenguaje, Matemáticas, Ciencias Naturales, Ciencias Sociales, Competencias
  Ciudadanas e Inglés** tienen contenido. **Religión, Artes, Ed. Física y Tecnología
  nacen vacías** y hay que decirlo en la pantalla, no dejar que el colegio lo
  descubra.

**Tests de contrato:**

- Un año **sin ninguna competencia** da la respuesta de boletín idéntica a hoy.
- `GET competencias/catalogo-men` de una materia que el MEN no cubre devuelve **la
  lista vacía y dice por qué**, no un 404: población, no `OK`.
- Adoptar dos veces no duplica.
- Un alumno con competencia propia la recibe; **uno normal recibe las de su grado** —
  el caso que `=` en vez de `<=>` deja mudo y vacío.
- Sin `can_edit_plantilla_notas`: **403** en las seis de escritura, **200** en el `GET`.

---

### Fase 3 · Los desempeños: catálogo, siembra y los del docente

```
2026_09_XX_300000_desempenos

desempenos_por_defecto
  id, year_id, materia_id, grado_id NULL, periodo_id,
  competencia_id NULL, tipo NULL, definicion text, orden int, …

desempenos
  id, asignatura_id, periodo_id, alumno_id NULL,
  competencia_id NULL, tipo NULL, definicion text, orden int,
  por_defecto tinyint(1) NOT NULL DEFAULT 0, …
```

Es el par que ya funciona en este sistema: `unidades_por_defecto` → `unidades`. Y
`por_defecto` significa aquí **lo mismo** que en `unidades`: «esta fila la sembró el
colegio» — que es lo que hace cumplir D14 sin inventar nada.

**12 rutas**, en dos grupos que **no son el mismo endpoint con otro permiso, porque no son la
misma tabla**:

- **8 sobre el catálogo del colegio** (`desempenos_por_defecto`): `GET`, `POST`, `PUT {id}`,
  `DELETE {id}`, `PUT orden`, `PUT copiar`, **`PUT desempenos/sembrar`** y `GET desempenos` de una
  asignatura+periodo, que es lo que lee la planilla.
- **4 sobre los del docente** (`desempenos`, la tabla sembrada): `POST`, `PUT {id}`,
  `DELETE {id}` y `PUT orden`.

> **Este apartado decía 8 y se le había olvidado la mitad de D14.** Escribía *«y lo que el docente
> sí puede: añadir los suyos»* **sin darle ni una ruta por donde hacerlo** — o sea una capacidad
> declarada que **no podría ejercer nadie**. Es la forma de `profesores.tono` por tercera vez en
> este documento (§1.4 y §1.9 son las otras dos), y esta vez la vio `myvc-front-50` al ir a
> escribirla.
>
> Y no se arregla reusando las ocho con un permiso distinto dentro: el catálogo vive en
> `desempenos_por_defecto` —materia + grado + periodo— y lo del docente en `desempenos`
> —asignatura + periodo—. **Un `POST` no puede escribir en una tabla o en otra según quién llame**
> sin convertirse en dos endpoints disfrazados de uno.
>
> **Sin esas cuatro, la rejilla del docente es de sólo lectura**: si al área se le olvidó un
> desempeño, el periodo se pierde. Que es literalmente lo que D14 dice que separa una pantalla que
> se usa de una que se abandona.

> **`PUT desempenos/sembrar` es explícito y no cuelga de un `GET`.** El `GET` que
> escribe —`UnidadesController::getDeAsignaturaPeriodo`— está fichado en
> [05](05-codigo-muerto-y-roto.md) §16 y **no se repite en código nuevo**. Que el doc
> 28 §8 diga que no se le quita al viejo no es permiso para escribir otro.

**El contrato de `sembrar`**, calcado del de la plantilla (`PlantillaNotasController::putSembrar`),
que ya aprendió tres cosas por las malas:

- **devuelve la población, no `OK`** — `{revisadas, sembradas, saltadas_por_estructura,
  saltadas_por_periodo_cerrado, saltadas_sin_catalogo, independientes_respetadas}`.
  Un «0 sembradas» tiene que poder distinguirse de «no revisé nada», y
  `saltadas_sin_catalogo` es el que **delata un catálogo mal dirigido**;
- **sólo periodos abiertos** (regla 2 de la §4) y **nunca encima de lo que ya tiene
  desempeños propios**;
- **no toca filas con `alumno_id IS NOT NULL`**, y el contador que lo demuestra es
  `independientes_respetadas` —sube cuando había filas con dueño y se dejaron—, no uno
  que valdría cero siempre;
- **se registra con `Auditoria`**: es una escritura masiva sobre el colegio entero;
- **acumula por grado, no compite** (§1.6);
- y **el error nombra lo que el colegio puede arreglar**, que es donde el molde falla — abajo.

> ### ⚠️ Lo que NO hay que calcar del sembrador de la plantilla: su 422 habla de destinos
>
> **Medido por `myvc-front-50` contra el docker y recontado aquí, que es como se confirma un
> número.** `PlantillaNotasController::exigirRepartosCompletos` agrupa por una `clave` que se
> construye con el **nivel y la materia de la asignatura DESTINO**
> (`PlantillaNotasController:517-519`), no con la fila de plantilla que le toca. Y entonces:
>
> ```
> pares (nivel, materia) distintos entre las asignaturas del año actual  ->  42
> (base de desarrollo `simonbolivar`, año actual, asignaturas vivas)
> ```
>
> **Una sola fila de plantilla al 70 % devuelve 42 entradas en el 422**, cada una diciendo
> `{nivel_educativo_id, materia_id, unidades: 1, suma_porcentajes: 70}`: **cuarenta y dos maneras
> de repetir el mismo hecho sobre una fila que el colegio ve una vez**. El `array_unique` está
> puesto y no ayuda, porque deduplica destinos, no plantillas.
>
> No es un fallo de cálculo —el 422 llega cuando debe y el reparto está mal de verdad— sino de
> **con qué vocabulario se lo cuenta al colegio**: quien abre esa pantalla ve **su plantilla**, y
> el error le contesta en unidades de «asignaturas a las que iba a ir». La §5.1.d del doc 28 decía
> *«el 422 los nombra uno a uno»* pensando en grupos de alcance, y la implementación acabó
> nombrando grupos de destino. **Nadie lo notó porque la frase encaja con las dos lecturas.**
>
> **Lo que hereda la Fase 3, entonces, no es el código sino la advertencia**: `desempenos/sembrar`
> nombra en su error **las filas de catálogo que están mal**, y da los destinos como **cuenta**
> —«afecta a 42 combinaciones»— no como lista. Es la misma regla de «la respuesta dice la
> población» aplicada al error: un número grande no informa más, informa menos.
>
> Y queda fichado, sin arreglarlo aquí: **el 422 de `plantilla-notas/sembrar` tiene el mismo
> defecto hoy**, en un endpoint vivo. Cambiarlo es tocar un contrato ya escrito y merece su propia
> decisión — pero el día que alguien abra esa pantalla y reciba cuarenta y dos renglones, esto
> explica por qué.

**El candado del docente** es la marca `por_defecto`, y **son TRES caminos aquí, no los nueve de
`unidades`**: `update`, `destroy` y `orden`. No hay `forcedelete` ni `restore` en esta familia
porque no se han pedido.

> ⚠️ **Y el porqué importa más que el número, porque la razón fácil es falsa.** No es que
> *«sin `forcedelete` el rodeo de borrar-y-volver-a-crear se cierre por construcción»*: **no se
> cierra**. `destroy` es un borrado lógico, así que un docente que pudiera borrar volvería a crear
> la fila **con `alumno_id` nuevo y `por_defecto = 0`**, o sea libre — exactamente el rodeo de la
> §5.1.e del doc 28. **Lo que lo cierra es candar `destroy`**, no omitir `forcedelete`.
>
> Omitir `forcedelete` **ahorra un camino que candar**, que es otra cosa y también vale. Pero
> escrito como «se cierra solo» invita a dejar `destroy` abierto, que es justo el agujero. Poner el candado sólo en `update` lo
deja decorativo por el rodeo de siempre —borrar y volver a crear—, que es lo que la
§5.1.e del doc 28 midió con los nueve caminos de `unidades`. Y compara **valores, no
presencia del campo**: los clientes mandan el objeto entero.

**Y lo que el docente sí puede** (D14): **añadir los suyos**. Un desempeño con
`por_defecto = 0` y su `asignatura_id` se edita y se borra sin permiso ninguno.

> **Y un defecto de la copia actual, encontrado auditando las nueve tablas que `postStore`
> copia** (`8myvc-2e`, 13 sep, comparando cada `INSERT` contra las columnas vivas): **ninguna
> columna se queda fuera por descuido** —las ausentes son contables o exclusiones deliberadas ya
> documentadas: `editable_por_profe_id` de `requisitos_matricula` e `inicia_at`/`finaliza_at` de
> `subunidades_por_defecto`— **pero la copia de `unidades_por_defecto` no pone `created_at`**, así
> que esas filas nacen sin fecha. Las tablas que añaden las Fases 2 y 3 **no heredan ese
> descuido**: se copian con sus marcas de tiempo, y su test lo comprueba.

> **El centinela que falta, y que esta fase obliga a escribir.** `competencias` y
> `desempenos_por_defecto` son **tablas por año**, así que `YearsController` tiene que
> copiarlas al crear el año siguiente o el colegio reescribe su plan de área cada
> enero. El `CentinelaDeLasColumnasDelAnioNuevoTest` **no puede cazar esto**: vigila
> columnas, no tablas hijas — es exactamente el fallo de la §1.bis del doc 28, que
> dejó las subunidades por defecto sin copiar durante años y sin un error en el log.
> El doc 28 §5.0 ya dijo que ese centinela «no está escrito». **Esta fase lo escribe**,
> con su lista de excepciones y el motivo al lado de cada una.
>
> **HECHO el 13 sep 2026**, detrás de las Fases 2 y 3 y sobre `main`: la copia es
> `YearsController::copiarElPlanDeArea` y el centinela es
> `tests/Contrato/CentinelaDeLasTablasDelAnioNuevoTest`. El censo entero está en la
> §2.bis de aquí abajo.

**Tests de contrato:**

- **Con las dos tablas vacías, la respuesta de la planilla y de los tres boletines es
  la de hoy, byte a byte.**
- `sembrar` con el periodo cerrado **no escribe nada**; con una asignatura que ya tiene
  desempeños propios, la deja y la reporta.
- **El control que hace valer el candado**: apagarlo tiene que poner rojos **los
  cuatro** caminos. Si sólo cae `update`, el rodeo sigue abierto.
- Guardar sin cambiar nada sigue dando **200** (trampa 1 de la §5.1.e).
- **Un año nuevo hereda competencias y desempeños por defecto** — el test hermano del
  de la §1.bis, y va al lado del suyo, que es donde alguien vendrá a mirar.
- El alumno del boletín independiente recibe **los suyos**; el normal, los del grupo.

---

### 2.bis · Las 23 tablas por año, y cuáles hereda el año nuevo · **13 sep 2026**

Salió de escribir el centinela de la Fase 3, y se deja aquí porque **la lista no se
puede reconstruir leyendo `postStore` de arriba abajo**: **cuatro** de las diez que
copia no nombran su tabla en ninguna parte —`escalas_de_valoracion`, `frases`,
`grupos` y `periodos` van por Eloquent— y **tres** no están dentro de `postStore`
sino en dos métodos privados suyos: `periodos` en `crearLosPeriodos`, y `competencias`
y `desempenos_por_defecto` en `copiarElPlanDeArea`.

Contadas contra la base viva (`information_schema`, no el volcado congelado), que es
lo único donde aparecen las tablas nuevas:

| | tablas | por qué |
|---|---|---|
| **se copian** (10) | `escalas_de_valoracion` · `frases` · `unidades_por_defecto` · `requisitos_matricula` · `dis_configuraciones` · `dis_ordinales` · `grupos` · `periodos` · **`competencias`** · **`desempenos_por_defecto`** | son **lo que el colegio escribió para decir cómo evalúa**: se escriben una vez y valen para siempre, así que no copiarlas obliga a reescribirlas cada enero |
| **datos del año** (9) | `auditoria` · `contratos` · `dis_libro_rojo` · `dis_procesos` · `horario_versiones` · `piars_actas_acuerdo` · `piars_alumnos` · `piars_grupos` · `vt_votaciones` | **pasaron en un año concreto**. Un proceso disciplinario, un PIAR firmado, una votación o un horario subido no son trabajo que ahorrarle al colegio: copiarlos sería **fabricar historia que no ocurrió** |
| **muertas** (3) | `default_unidades` · `df_alumnos` · `df_grupos` | ya censadas en el [05](05-codigo-muerto-y-roto.md), cero filas y ningún lector. Su excepción **se borra sola** el día que se borren las tablas |
| **sin decidir** (1) | `rubricas` | abajo |

La línea que separa las dos primeras filas es **una sola pregunta**: *¿esto lo escribió
el colegio para decir cómo evalúa, o lo produjo el año al vivirse?*

> **`rubricas` es la única que no contesta esa pregunta**, y por eso no está en ninguna
> de las dos listas. Tiene las dos caras a la vez: `rubricas.asignatura_id` la ata a una
> asignatura que el año nuevo vuelve a crear **con otro id** —copiarla tal cual sería la
> referencia cruzada que `copiarElPlanDeArea` existe para evitar—, pero
> `es_plantilla = 1` con `asignatura_id` en NULL es **una rúbrica de biblioteca**, sin
> dueño y escrita para reusarse, y ésa tiene la misma cara que `unidades_por_defecto`.
>
> **Y no es un `INSERT` más**: son cuatro tablas hijas —`rubrica_criterios`,
> `rubrica_niveles`, `rubrica_descriptores` y el enganche de `subunidades`— con sus ids
> remapeados, y **ninguna lleva `year_id`**, o sea que el centinela nuevo tampoco las
> vería. No hay hoy ninguna ruta que copie una rúbrica, ni entre años ni dentro del
> mismo año.
>
> Queda **declarada sin decidir y con un test rojo esperándola**
> (`hay_tablas_por_anio_sin_decidir`, grupo `rojo`), que es como en esta casa una duda
> deja de disfrazarse de decisión. La pregunta para Joseth es la primera de todas:
> **¿son las rúbricas de biblioteca (`es_plantilla = 1`) configuración del colegio?**

**Lo que la copia del plan de área tuvo que resolver y las otras nueve tablas no**: sus
filas **se apuntan entre ellas**. El desempeño cuelga de la competencia
(`competencia_id`, D10) **y de un periodo** (`periodo_id`, NOT NULL), y las dos cosas
nacen con ids nuevos. Copiar las dos tablas sin remapear no da ningún error —la clave
ajena acepta filas de otro año— y el resultado es **200, pantalla llena y rejilla
vacía**: `GET desempenos/plantilla` los enseña porque filtra por `year_id`, y la
planilla del docente no encuentra ni uno porque lee por `year_id` **y** `periodo_id`.
Por eso la copia va **detrás** de `crearLosPeriodos` y no junto a las escalas y las
frases, que es donde parece que va.

Un desempeño cuyo periodo del año viejo **no tiene equivalente** —un quinto periodo, o
uno en la papelera— **se queda**, se cuenta y se dice en el log. Medido contra el docker
de desarrollo el 13 sep 2026 con `tools/probar-el-anio-nuevo-en-el-docker.php`, y de
paso salió el dato que lo hace probable: **el año 2026 de esa base tiene UN periodo**,
no cuatro — es el que creó esta misma ruta antes del arreglo del 30 ago 2026.

---

### Fase 4 · La rejilla premarcada · **desbloqueada el 13 sep por D23**

```
GET  desempenos/rejilla?asignatura_id=&periodo_id=
PUT  desempenos/rejilla
     { asignatura_id, periodo_id,
       celdas: [ {alumno_id, desempeno_id, escala_id | null}, … ] }
```

```
2026_09_XX_400000_marca_del_desempeno

frases_asignatura + desempeno_id int NULL       -- de qué casilla salió
                  + escala_id    int NULL       -- qué nivel se le puso
                  + nivel        varchar(255) NULL   -- y cómo se llamaba ese nivel ese día
```

**La celda no es un sí/no: es el nivel** (D23). Y son **tres** columnas anulables y no una, por la
razón de §1.5: el `id` pinta la casilla, el **texto** es lo que se imprime y lo que impide que
renombrar una escala en 2028 cambie un boletín de 2026 — exactamente el papel que `frase` ya hace
frente a `frase_id`.

**Dos rutas y dos usos** —desempeños y preescolar (§5.7.c del doc 28)—, que es lo que
hace que la decisión 10 y la Entrega 7 compartan pantalla en vez de duplicarla.

- Escribe en **`frases_asignatura`**, que es lo que el boletín ya lee: **no añade ni
  una consulta** al camino que tarda 24-63 s.
- **El texto se sigue copiando en `frase`** — eso es lo que protege los boletines
  viejos (regla 1 de la §4). `desempeno_id` sirve **sólo para saber de qué casilla
  salió**.
- **Una llamada, no ~300.** Es la medición que justifica la ruta:
  `FrasesAsignaturaController::postStore` guarda una frase por petición.
- **Devuelve la población**: `{recibidas, escritas, cambiadas, borradas,
  saltadas_por_periodo_cerrado, saltadas_por_no_ser_del_grupo}`. Y el `GET` devuelve además
  **`sin_banda`** — cuántos alumnos no casaron con ninguna escala (§1.5, punto 4).
- **La comprobación que hoy falta entra aquí**: que el alumno esté en el grupo de la
  asignatura, que es justo lo que `postStore` no mira (§1.8). Código nuevo → **403 y
  422**, no 400.
- **No escribe nada al abrir.** El `GET` premarca en memoria; la fila sólo existe
  cuando el docente guarda. **Es lo que mantiene en pie la decisión 10**: ningún boletín afirma
  un nivel que nadie miró.
- **El nivel premarcado sale de la definitiva, no de la unidad.** `notas_finales` cruzada con
  `escalas_de_valoracion` por `porc_inicial`/`porc_final` y `year_id`: **una consulta por grupo y
  periodo**. `Unidad::deAsignaturaCalculada` hace ese mismo cruce **por unidad** y por eso no
  sirve aquí — se reutiliza la forma, no el método.

**Tests de contrato:**

- El `GET` **no escribe**: contar filas de `frases_asignatura` antes y después. Es el
  test que hace que «premarcada» no se convierta en «marcada de oficio» por un
  descuido, y es el que sujeta la decisión 10 entera.
- Marcar en un **periodo cerrado** no escribe nada.
- Marcar a un alumno **que no es del grupo** → 422, y no escribe.
- Un desempeño marcado y luego **corregido en el catálogo** no cambia el texto ya
  impreso — `frase` se copió. **Y lo mismo renombrando la escala**: una celda puesta como
  «Básico» sigue diciendo «Básico» después de que el colegio renombre esa fila de
  `escalas_de_valoracion`. Es el test que justifica la tercera columna; sin él, alguien la quitará
  por redundante.
- **Un alumno sin definitiva abre la celda vacía, no en «Bajo»** — y un alumno cuya nota cae en un
  hueco entre dos bandas, también, y **sale contado en `sin_banda`**. Los dos se ven rojos
  montando una escala con un agujero, que es lo único que distingue el caso de una suposición.
- El texto de un desempeño de **388 caracteres** viaja entero de ida y vuelta: es
  `FraseLargaEnElBoletinTest` aplicado a este camino, y **sin la Fase 0 se ve rojo**.

---

### Fase 5 · Qué comparten los tres boletines · **MEDIDA el 13 sep 2026**

**629 + 605 + 586 líneas**, comprobadas. El argumento de D16 se sostiene, pero **el
número que lo acompañaba no era el que había que mirar**: lo que comparten no se mide
en líneas repetidas —casi ninguna lo está, porque tres copias divergidas se parecen
poco al `diff`— sino en **campos de la respuesta**, y ahí el reparto es otro.

> **Cómo se midió.** `tools/comparar-los-tres-boletines.php`, que levanta el kernel
> HTTP **del worktree** y le pide las tres rutas con el token real de
> `administrador` contra la base de desarrollo `simonbolivar` —nginx sirve `/app`, el
> árbol principal, así que desde un worktree no hay puerto por el que llamar—. Grupo
> 96 («Segundo»), periodo 31 (nº 2, año 8), un alumno, `periodo_a_calcular = 4`.
> Los recuentos de línea y de método salen de `token_get_all` sobre los tres ficheros,
> sin comentarios ni blancos. **Dos hipótesis mías murieron aquí**, y están dichas más
> abajo con lo que las mató.

#### 1 · Qué consulta hace cada uno, y qué cuesta

| | `boletines` | `boletines2` | `boletines3` |
|---|---|---|---|
| la estructura | `Grupo::detailed_materias_notafinal` | `…notafinal` | **`…detailed_materias_notas_finales`** (otra consulta) |
| las unidades | `deAsignaturaCalculada` → rama **`sin_desempenio`** | rama **`con_desempenio`** o **`fortaleza_debilidad`** según `years.show_fortaleza_bol` | rama `con_desempenio`, **y llega ahí por accidente** |
| subunidades | **sí**, `Subunidad::deUnidadCalculada` | no | no |
| áreas | **no** | `Area::agrupar_asignaturas` | `Area::agrupar_asignaturas_periodos` |
| `escalas_de_valoracion` en la respuesta | sí (4.º elemento) | sí | **no: devuelve 3 elementos** |
| recálculo de definitivas | **sí**, `ponerAlDiaLasDefinitivas` | no | no |

Medido en la misma petición, tres veces y reproducible:

| | estado | ms † | consultas | bytes |
|---|---|---|---|---|
| `boletines` | 200 | 1.406 – 1.877 | **1.061** | 25.007 |
| `boletines2` | 200 | 1.024 – 1.503 | 924 | 39.013 |
| `boletines3` | 200 | **15.531 – 15.572** | **762** | 25.980 |

> **† Los ms son una cota POR ARRIBA, y la carga era nuestra.** Se tomaron el 13 sep 2026
> **con una suite de PHPUnit de este mismo proyecto corriendo dentro del contenedor** —ese
> día hubo varias, de 1.100 a 1.700 s—, así que la máquina no estaba limpia. Y no era «una
> VM ajena»: la VM de `Virtualization.framework` que se lleva el 114 % **es el Docker de
> este repo**. La diferencia no es de matiz — lo ajeno suena a mala suerte y no se puede
> evitar; **lo propio es reproducible y evitable**, y le dice al que remida cómo tomar la
> cifra limpia: con `pgrep -af phpunit` vacío.
>
> **Las otras dos columnas no son cotas: son exactas.** El número de consultas y los bytes
> no dependen de la máquina, y son los que sostienen lo de abajo.

> **El tercero tarda diez veces más haciendo TRESCIENTAS consultas menos.** El coste
> de este boletín no está en el N+1 —que es lo que se supone cuando se lee «24–63 s»—
> sino en **una** consulta: la de cuatro periodos de `Grupo::detailed_materias_notas_finales`.
> Es el dato que decide dónde se optimiza, y dice que contar consultas aquí engaña.
>
> **Y esto aguanta aunque los ms sean sucios**, que es lo que hay que saber antes de
> citarlos: los tres se tomaron **bajo la misma carga**, así que el ruido tiende a
> cancelarse en el **cociente**; y el «diez veces» no se sostiene por la medida sino **por
> mecanismo** —762 consultas contra 1.061, y una de ellas es la de cuatro periodos—. Lo
> que **no** se puede citar a pelo es el **15.531 ms** como si fuera lo que ve un usuario:
> eso hay que remedirlo con la máquina quieta.

> **La regla, que vale para cualquier cifra de tiempo de este repo:** un **✓** contra un
> umbral, medido con la máquina sucia, es **conservador y vale** —salió bien cargando de
> más—; un **✕** **no concluye nada** y hay que repetirlo limpio.

**«Llega ahí por accidente»** no es una forma de hablar: `Boletines3Controller:281`
pasa **`true`** donde los otros pasan una cadena. La primera rama compara con `===` y
falla; la segunda con `==`, y `true == 'con_desempenio'` es **`true`** (PHP 8.4.24,
comprobado en el contenedor). Funciona hoy; lo sostiene una comparación laxa contra un
booleano, no una decisión.

#### 2 · Dónde divergieron de verdad — y **tres de las diferencias son fallos**

**a) `number_format` sobre la definitiva del año: 115 pares (alumno, asignatura) en los
que los tres boletines NO dicen lo mismo.**

`BoletinesController:440` y `:501` preguntan
`number_format($periodos[0]->definitiva_year) < nota_minima_aceptada`; `boletines2` y
`boletines3` preguntan lo mismo **sin el `number_format`**. `number_format` redondea a
cero decimales y **devuelve una cadena**: un 29,6 se vuelve `"30"`, y con la mínima en
30 la asignatura deja de estar perdida. Contado sobre `simonbolivar`, pares cuya
definitiva del año cae en `[mínima − 0,5, mínima)`:

| año | 2018 | 2019 | 2020 | 2021 | 2022 | 2023 | **2024** | 2025 |
|---|---|---|---|---|---|---|---|---|
| pares que discrepan | 4 | 6 | 4 | 6 | 5 | 7 | **83** | 0 |

**115 en total, sobre 35.653 pares.** En los nueve años `si_recupera_materia_recup_indicador`
vale 1, así que la rama que lo lleva es **la que se ejecuta**. El efecto impreso: el
mismo alumno, el mismo periodo, **sale con la asignatura perdida en los boletines 2 y 3
y sin ella en el 1**. No es una intención —nadie elige `number_format` para comparar
números— y está **en una sola de las tres copias**, que es la forma que tiene este repo
de decir «se arregló aquí y no llegó allí»… salvo que aquí el arreglo es el que rompe.

**b) `PREM` en el año pasado: sólo el primero lo mira.**
`BoletinesController::datosYearPasado` acepta `m.estado IN ("MATR","ASIS","PREM")`; los
otros dos, sólo `MATR` y `ASIS`. Un alumno cuya matrícula del año anterior quedara en
prematrícula **tiene bloque de año pasado en el boletín 1 y no en los otros dos**. Hoy
son **13 filas `PREM`** vivas en toda la base —12 en 2026 y 1 en 2022—, así que es
pequeño **y va a crecer**: 2026 es el año que se está armando.

**c) `years.solo_escalas_valorativas` lo honran dos de tres.**
`encabezado_comportamiento_boletin` vacía la nota cuando el colegio pide sólo escalas
—`boletines` y `boletines2`—. `Boletines3Controller` **no tiene siquiera el método
`year()`**, así que imprime el número aunque el colegio haya dicho que no. Es un
interruptor del colegio que un boletín ignora.

Y dos divergencias que **son intención** y conviene no «arreglar» al extraer:

- **El recálculo de definitivas** (`ponerAlDiaLasDefinitivas`) está sólo en `boletines`,
  y así lo dejó a propósito la fase 3 del [10](10-definitivas.md).
- **Las subunidades** viajan sólo en `boletines`. Ya está fichado en
  `BoletinesEnNegativoTest`, que por eso corre el caso de subunidades sobre uno solo.

#### 3 · Qué parte es la maqueta y qué parte es el dato

**547** caminos de campo distintos en la unión de las tres respuestas. **211 comunes a
las tres: el 39 %.**

| | campos | sólo suyos |
|---|---|---|
| `boletines` | 346 | 38 — **todos** son `…unidades[].subunidades[]` |
| `boletines2` | 451 | 47 — 30 del bloque `areas[]`, 17 columnas crudas de `unidades` |
| `boletines3` | 365 | 58 — `nota_final_perN`, `nf_id_N`, `nivelada_at_perN`… |

Leído al derecho: **el dato común es el alumno con sus asignaturas, sus notas por
periodo, su comportamiento, sus ausencias y sus frases** —los 211—, y **lo exclusivo de
cada uno es exactamente su maqueta**: el detalle hasta subunidad del primero, la
agrupación por áreas del segundo, la tabla de cuatro periodos del tercero. La división
que la Fase 6 necesitaba **existe y cae donde el plan suponía.**

Dos cosas que no se ven desde el código y sí desde la respuesta:

- **`boletines3` no emite `alumno.asignaturas`**: un `unset` lo borra y deja sólo
  `areas[]`. Es un **contrato distinto**, no una maqueta distinta: quien consuma el
  tercero tiene que entrar por `areas[].asignaturas[]`.
  > **Aquí murió mi primera hipótesis.** Leyendo el `unset` di por hecho que el tercero
  > tiraba a la basura las unidades que acababa de calcular —la consulta cara— y lo
  > escribí. El docker dijo que no: las unidades **sobreviven dentro de
  > `areas[].asignaturas[].unidades`** (medido: 2 y 4 unidades en dos áreas del alumno
  > 968). No hay trabajo desperdiciado; hay un contrato movido.
- **`boletines2` filtra la escala entera dentro de cada unidad.** Su rama
  `con_desempenio` hace `SELECT *` sobre el `left join` a `escalas_de_valoracion`, así
  que cada unidad llega con `created_by`, `deleted_at`, `deleted_by`, `year_id`,
  `porc_inicial`, `porc_final`, `valoracion`, `desempenio` y **`icono_infantil` /
  `icono_adolescente`**. Es lo que infla su respuesta un 56 % sobre la del primero.
  > Y es el hallazgo que le sirve a la Fase 6: **el nombre del nivel y su icono ya
  > viajan hoy**, en un solo boletín y por un `SELECT *`. Lo que D17 pide no es una
  > columna nueva, es **emitirla a propósito**.

#### 4 · Cuál de las doce instantáneas cubre cada rama

Las «doce» son exactas: `tests/Contrato/Snapshots/boletines{,2,3}-detailed-notas{,-group,-year}.json`.
Lo que cubren, contado por quién llama a cada ruta en `tests/`:

| ruta | quién la cubre |
|---|---|
| `boletines/detailed-notas` | `BoletinDeLaFamilia`, `BoletinImprimeElPar`, `BoletinNoBorraDefinitivas`, `BolIndependientePuestos`, `AcudienteSobreUnAjeno` |
| `boletines2/detailed-notas` | `BoletinDeLaFamilia`, `BoletinFortalezaDebilidad`, `AcudienteSobreUnAjeno` |
| `boletines3/detailed-notas` | `BoletinDeLaFamilia`, `BoletinImprimeElPar`, `AcudienteSobreUnAjeno` |
| `boletines/detailed-notas-group` | **sólo** `GrupoBorradoNoEs500` (un 404, no el contenido) |
| `boletines2/detailed-notas-group` | **nadie** |
| `boletines3/detailed-notas-group` | **nadie** |
| `…/detailed-notas-year` (las tres) | **sólo** `GrupoAjenoDelMismoAnio` (autorización, no contenido) |

**Seis de las doce instantáneas no tienen detrás ni un test que mire lo que devuelven**:
las tres de `-group` y las tres de `-year`. La instantánea se regenera y pasa, porque
una instantánea compara contra sí misma; lo que no hay es **nadie que afirme nada**
sobre esas seis respuestas. Y `-group` es el camino que el front usa para imprimir el
grupo entero.

> **Aquí murió mi segunda hipótesis.** Di por supuesto que «doce instantáneas de
> boletín» eran doce clases de test. Son doce **ficheros `.json`**, y las clases que los
> sujetan son **siete**. La diferencia no es de vocabulario: es que la mitad de la
> superficie que se iba a extraer está cubierta por una instantánea y por nada más.

#### Lo que esto le deja a la Fase 6

1. **El dato común existe y son 211 campos**; la maqueta es lo exclusivo de cada uno.
   El boletín nuevo **no necesita un cuarto controlador de 600 líneas**: necesita el
   dato común más su bloque propio.
2. **No se extrae nada todavía.** Las tres divergencias de arriba son fallos, y extraer
   un tronco común obligaría a elegir cuál de los tres comportamientos es el bueno
   —o sea, a cambiar boletines publicados—. **La Fase 6 nace al lado, no encima**, que
   es lo que D16 ya decía y lo que la medición confirma.
3. **Lo que hay que arreglar antes de extraer está nombrado**: `number_format`, `PREM`
   y `solo_escalas_valorativas`. Son tres entregas propias, cada una con su instantánea
   moviéndose, y ninguna es de este plan.
4. **Antes de tocar `-group` o `-year`, escribir el test que hoy no existe.** Seis de
   las doce instantáneas no defienden nada.

---

### Fase 6 · El boletín nuevo · **HECHA el 13 sep 2026**

`BoletinPorCompetenciasController` + `BoletinPorCompetenciasTest`. Se elige **llamando a
su ruta**, como ya pasa con `boletines2` y `boletines3`: **no hay interruptor** (decisión
6 del doc 28, y por eso `show_competencias_bol` se retiró).

#### Son DOS rutas, y este documento decía cuatro

Decía *«4 rutas, calcadas de `boletines3`»*. Medido antes de calcarlas, **las otras dos no
se pueden**:

| la ruta que faltaría | por qué no entra |
|---|---|
| `boletines3/destroy/{id}` | **no borra un boletín: manda un ALUMNO a la papelera.** Lo dice su propio comentario (05 §89) y lo fija `BoletinesBorranAlumnosTest` con las cuatro puertas en el mismo caso. Calcarla sería **una quinta puerta** a la papelera, escondida en la familia de informes |
| `…/detailed-notas-year` | es **byte a byte la misma en los tres** —las tres instantáneas comparten md5, `054346c7…`— y **ningún cliente la llama**: `app2/src/app/datos/boletines.ts` declara exactamente dos métodos, `deAlumnos` y `deGrupo`. Una cuarta copia idéntica nace muerta |

Y las dos que quedan son **exactamente** las que `BoletinesApi` sabe llamar, así que para
el front la variante 6 es **un cuarto valor en su `RECURSOS`** y nada más.

#### El nombre, que estaba abierto

Se llama **`boletines-competencias`**, y **no `boletines4`**, que es lo que pedía la
inercia de la familia. El motivo es que ese número ya está cogido y por el otro lado:
`app2/src/app/datos/boletines.ts` deja escrito que **«el boletín que el colegio llama 4 se
sirve de `boletines3`»**, y la tabla de su cabecera reparte cinco pantallas entre tres
recursos sin que ninguna coincida con su número. Meter un `boletines4` real ahí dentro es
garantizar que alguien, algún día, sirva la pantalla equivocada.

`boletines-competencias` además **se lee**: dice qué imprime, que es lo que distingue esta
variante de las otras cinco. Y encaja con cómo se nombra en este repo lo que nació después
de la época de los números —`boletin-independiente`, `bolfinales-preescolar`,
`notas-actuales-alumnos`—.

Queda por decidir **cómo lo llama el colegio en la pantalla**, que es del front y no de
aquí.

#### El contrato

```
PUT api/boletines-competencias/detailed-notas/{grupo_id}          boletin.propio
PUT api/boletines-competencias/detailed-notas-group/{grupo_id}    boletin.propio

cuerpo (sólo la primera):  { "requested_alumnos": [ {"alumno_id": 123}, … ] }
                           sin él, o vacío, sale el grupo entero
```

La respuesta tiene **CINCO** posiciones y no cuatro. Las cuatro primeras son las de
`boletines` y `boletines2` —el front hace `const [grupo, year, alumnos, escalas] = r`, así
que la quinta es aditiva y no le mueve nada—:

```
[0] grupo      Grupo::datos + cantidad_alumnos   ← trae `caritas`
[1] year       Year::datos + periodo (el número del periodo del usuario)
[2] alumnos    el boletín de cada uno
[3] escalas    escalas_de_valoracion del año — la leyenda del pie
[4] poblacion  el recuento
```

**La competencia arriba, sus desempeños debajo, y los sueltos al final**, que es §4.3 del
plan del front:

```
alumnos[].asignaturas[]  = lo de Grupo::detailed_materias_notafinal
                           (materia, alias_materia, area_*, creditos, profesor,
                            nota_asignatura, desempenio, nf_id, nota_original_asignatura,
                            nivelada_at_asignatura, recuperada, manual, …)
                         + total_ausencias, total_tardanzas    int
                         + bol_independiente                   bool
                         + motivo_del_nivel   "sin_definitiva" | "sin_banda" | null
                         + competencias[]     { competencia_id, definicion, codigo_men,
                                                orden, desempenos[] }
                         + desempenos_sueltos[]                 las mismas filas, al final

una fila de desempeño   = { frase_asignatura_id, desempeno_id|null, texto, tipo, orden,
                            escala_id|null, nivel|null,
                            icono_infantil|null, icono_adolescente|null,
                            origen: "rejilla" | "frase" }

poblacion               = { alumnos, asignaturas, desempenos_del_grupo, competencias,
                            desempenos_impresos, desempenos_sueltos, frases_sueltas,
                            con_nivel, sin_nivel,
                            asignaturas_sin_definitiva, asignaturas_sin_banda, caritas }
```

Los errores, pedidos uno a uno al docker y no leídos del código:

| | |
|---|---|
| **401** | sin token, o caducado — el guard global `auth.token`. **Pedido: 401** |
| **403** | un Alumno o un Acudiente pidiendo un boletín que no es suyo — `boletin.propio`. Pedido desde el test con token de alumno: **403**, y con el mensaje de siempre (`No puedes ver el de otros` / `Pedis más de lo que debes`) |
| **404** | el grupo no existe **o está en la papelera** — `Grupo::datos`, que contesta 404 desde el 24 ago. **Pedido: 404**, `{"message":"El grupo no existe o está en la papelera."}` |

No hay 422: las dos rutas no aceptan nada que validar más allá de `requested_alumnos`, y
una lista vacía o mal formada significa «el grupo entero», que es lo que hacen los tres de
hoy. **`periodo_a_calcular` se ignora**, ver abajo.

#### Lo que NO es, y por qué

**No es una cuarta copia.** La Fase 5 midió que de los 547 campos de los tres boletines de
hoy **211 son comunes** y todo lo exclusivo es maqueta, así que esto **nace del dato
común**: llama a los mismos modelos —`Grupo::datos`, `Grupo::alumnos`,
`Grupo::detailed_materias_notafinal`, `NotaComportamiento`, `Disciplina`,
`BoletinIndependiente::ponerPuestos`— y le añade su bloque. Medido con el mismo criterio
que la Fase 5 —sin comentarios ni blancos—: **271 líneas de código**, contra **368 + 355 +
312** de los tres de hoy. Menos que cualquiera de ellos **haciendo además el árbol de
competencias**, y no por apretarlo: por no repetir lo que ya está en los modelos.

Y **ninguna de las tres divergencias que la Fase 5 encontró está copiada aquí**, aunque las
tres por motivos distintos y conviene decir cuál:

- **`number_format` sobre la definitiva del año** —los 115 pares que discrepan— vive en
  `asignaturasPerdidasDeAlumno`, y **este boletín no tiene bloque de asignaturas perdidas**:
  es un informe de un periodo y el acumulado del año es de los otros. No se hereda porque no
  se copia el método.
- **El `PREM` que sólo mira uno** vive en `datosYearPasado`, y **tampoco hay bloque de año
  pasado**, por lo mismo.
- **`years.solo_escalas_valorativas`** vive en `encabezado_comportamiento_boletin`, que es
  **texto de maqueta** —«Su comportamiento fue…», conjugado por sexo— y esa frase la escribe
  la maqueta nueva. Aquí el comportamiento viaja como dato y sin frase montada, así que no
  hay dónde honrar mal un interruptor.

Dicho al revés, que es lo honesto: **no se arreglaron; se quedaron fuera porque los bloques
que las contienen no son de esta variante.** Arreglarlas en los tres de hoy sigue siendo
entrega propia, y sigue sin hacerse.

**Y no hereda su coste.** Dos consultas por alumno donde los tres de siempre hacen una por
asignatura: `marcasDelAlumno` trae todas las celdas del grupo de una vez y
`faltasPorAsignatura` agrega las ausencias en una. Pedido al docker, con
`tools/probar-el-boletin-por-competencias-en-el-docker.php`:

| | estado | ms † | consultas | bytes |
|---|---|---|---|---|
| `detailed-notas`, **1 alumno** | 200 | 155 | 307 | 12.825 |
| `detailed-notas-group`, **37 alumnos** | 200 | 117 | **268** | 323.955 |

> **† Mismo día, mismo contenedor, misma advertencia que en la Fase 5**: los ms son una
> **cota por arriba** y no se citan a pelo como «lo que tarda para un usuario». Las
> consultas y los bytes sí son exactos.

Contra las **1.061 consultas** que la Fase 5 midió en una petición de **UN** alumno del
primero — y **la comparación que vale es ésa, la de consultas**, no la de milisegundos: es
la que no depende de cómo estuviera la máquina. Y las dos filas de arriba son de la
**misma** cantidad de trabajo: la ruta de un alumno calcula el grupo entero igual, porque
el puesto lo exige; lo que cambia es a quién se le devuelve.

**No recalcula definitivas.** `BoletinesController` sí lo hace —fase 3 del
[10](10-definitivas.md)— y ahí está bien; un boletín nuevo que escribiera al abrirse
repetiría la avería que borró las definitivas del periodo 1. Lo sujeta
`test_abrir_el_boletin_no_escribe_ni_una_fila`, que cuenta `frases_asignatura` y
`notas_finales` antes y después de las dos rutas.

**Ignora `periodo_a_calcular`**, que el front manda en el mismo cuerpo que a los otros.
Es un informe **de un periodo**: el del usuario. No emite `year.periodos` por lo mismo.

#### `caritas`: el nivel va en TEXTO, siempre (D17)

`grupos.caritas` viaja en `grupo.caritas` y en `poblacion.caritas`, y **el icono nunca va
solo**: cada fila lleva su `nivel` —el texto congelado el día que se puso— y, *además*,
`icono_infantil` / `icono_adolescente` cuando el grupo la tiene encendida. El Decreto 2247
art. 10 y el 1411/2022 piden *«informes descriptivos … de corte cualitativo»*, y una carita
no lo es. **El front no puede pintar sólo el icono porque el texto siempre está**, y lo
sujetan dos casos, uno por cada valor de la columna.

Aquí `caritas` **sólo se lee**, nunca se escribe, y se lee de `Grupo::datos`, que ya la
traía: el aviso de la §153 de `GruposController` —defecto `false` que apagaba la columna—
no se hereda porque no hay escritura que lo herede.

> **Y hoy no hay ni un grupo con `caritas = 1` en `simonbolivar`.** Medido. Por eso la
> prueba del docker la enciende y la devuelve a su valor, dentro de una transacción que
> revierte siempre: sin eso, D17 se comprobaría sólo por la mitad que no importa.

Así sale, pedido al docker con la columna encendida y tres celdas puestas:

```
TECNOLOGÍA E INFORMÁTICA   nota=0  nivel="BAJO"  motivo=null  IH=2  F=0
  [48] Resuelve problemas con números racionales en contextos cotidianos
      · Identifica fracciones equivalentes y las ordena   ["SUPERIOR"]  icono="carita-feliz.png"
  sueltos:
      · Felicitaciones por su desempeño en el periodo.   [null]      origen=frase
      · Entrega sus trabajos a tiempo                    ["SUPERIOR"] origen=rejilla
```

El texto del nivel está en los dos que lo tienen; el icono, **al lado** y nunca en su
lugar. Y la frase escrita a mano sale con el nivel en `null`, que es la verdad: nadie le
puso uno.

#### Los dos motivos de un nivel vacío, separados

La Fase 4 los separó y aquí se respetan, porque **son dos averías distintas**:

- **`sin_definitiva`** — el alumno no tiene `notas_finales` en esa asignatura y ese
  periodo. No hay nota que traducir.
- **`sin_banda`** — sí tiene nota y **ninguna banda la cubre**.

> **Esto se escribió con el hueco de la frontera abierto, y `bd02f66` lo cerró esa misma
> tarde.** Decía que `sin_banda` era el [36](36-la-nota-decimal-y-las-bandas-enteras.md)
> —nota `DECIMAL(7,4)` contra bandas `int`, un hueco en cada frontera, cuatro alumnos del
> año en curso imprimiendo el nivel vacío— y **eso ya no es así**: con
> `porc_inicial <= nota AND nota < porc_final + 1`, un 45,5 es ALTO y un 29,5 es BAJO. De
> las trece definitivas huérfanas de `simonbolivar` quedan **nueve**.
>
> **`sin_banda` sigue existiendo, y con dos formas, las dos del colegio y no del
> redondeo:**
>
> 1. **una escala con un agujero de verdad** —`[0,59]` y `[61,100]`, y un 60—, escrita así
>    por el colegio;
> 2. **una nota por encima del techo** de la banda más alta: son esas nueve, y son **un
>    dato malo, no un hueco** — taparlo lo escondería.
>
> El caso de contrato se remontó sobre **las dos** el 13 sep 2026, con un alumno por
> motivo. Antes se apoyaba en la frontera de enteros, así que al cerrarse el hueco **el
> test se puso en rojo comprobando que existiera algo que se acababa de arreglar**. Lo que
> se cambió fue el montaje, no la afirmación: `asignaturas_sin_banda` pasó de esperar 1 a
> esperar **2**, no a esperar 0.

Los dos van en `asignaturas[].motivo_del_nivel` **y contados aparte** en `poblacion`. Eso
es lo único que hace que un nivel que falta se vea **antes** de que salga el papel, que es
justo lo que el doc 36 señaló que no existía en ninguna pantalla.

#### Lo que se imprime es lo que el docente guardó, y ni un nivel más

**Sólo salen las filas que existen en `frases_asignatura`.** Un desempeño del grupo que
nadie marcó **no aparece**: es la decisión 10, y la Fase 4 la sostiene por el otro lado
—*«ningún boletín afirma un nivel que nadie miró»*—. Lo que la respuesta sí hace es
**contarlo**: `desempenos_del_grupo` frente a `desempenos_impresos + desempenos_sueltos`
dice cuántas casillas quedaron sin mirar, que es la pregunta que el colegio hace antes de
imprimir.

Y **suelto son dos cosas** que el front tiene que poder distinguir, por eso va `origen`:

- **`frase`** — escrita a mano en la pantalla de siempre (`desempeno_id IS NULL`). Son las
  **12.294** filas que ya hay en `simonbolivar`, y **ninguna tiene nivel**: inventárselo
  sería afirmar algo que nadie puso.
- **`rejilla`** — la celda de un desempeño que el colegio dejó **sin competencia**
  (`desempenos.competencia_id IS NULL`), que es legal por D5. Ésa **sí** trae nivel.

#### Y su instantánea defiende más que las seis de la Fase 5

`HuecosDelSeedTest` saca de los ficheros el mapa de qué partes de cada respuesta **no
comprueba nadie**. Lo que aporta la nueva, comparada con las de los tres de hoy:

| instantánea | huecos |
|---|---|
| `boletines2-detailed-notas` | 7 |
| `boletines-detailed-notas` | 5 |
| `boletines3-detailed-notas` | 4 |
| **`boletines-competencias-detailed-notas`** | **2** |

Y los dos que quedan —`alumnos.comportamiento.definiciones` y `alumnos.situaciones`— los
tienen **los siete**: son texto que el docente escribe a mano y el seed no lo trae para
todos. Lo que **no** es hueco aquí y sí lo es en los otros seis es `…frases` y el bloque de
desempeños: el caso **se construye** en vez de esperarlo del seed, que es lo que la Fase 5
señaló que faltaba.

#### Lo que se encontró y este plan no decía

1. **Las cuatro rutas eran dos** (arriba).
2. **El texto de la COMPETENCIA no está congelado en ninguna parte — y es de los que no
   duelen hasta que ya no tienen arreglo.**

   La Fase 4 añadió **tres** columnas a `frases_asignatura` y la tercera, `nivel`, existe
   por un argumento que está escrito entero en su migración: *«el `id` pinta la casilla y
   el **texto** es lo que se imprime»*, porque `escalas_de_valoracion` es **por año y
   editable** y sin la copia, renombrar «Básico» en 2028 cambiaría un boletín impreso en
   2026. Es el mismo papel que `frase` hace frente a `frase_id`.

   **Ese argumento vale un piso más arriba y ahí no hay dónde copiar nada.** La cabecera de
   cada bloque sale de `competencias.definicion` leída **hoy**, porque `frases_asignatura`
   guarda de qué desempeño salió la celda (`desempeno_id`) pero **no de qué competencia**,
   y la competencia se alcanza saltando por `desempenos.competencia_id`, que también es
   editable y borrable. O sea:

   - **renombrar una competencia en 2028 cambia la cabecera de un boletín de 2026**;
   - y **borrarla** deja el desempeño entre los sueltos —lo comprueba
     `test_si_borran_la_competencia_su_desempeno_cae_entre_los_sueltos`—, que es lo menos
     malo de las dos salidas posibles, pero **también cambia el papel de un año cerrado**.

   **Por qué no se tapó aquí.** Es una **cuarta columna** en `frases_asignatura`
   —`competencia` en texto, anulable, copiada al guardar la celda— y eso es una migración,
   una escritura más en `PUT desempenos/rejilla` (que es de la Fase 4, no de ésta) y su
   caso de contrato. **Entrega propia**, y no entra de rebote en un boletín que sólo lee.

   **Por qué no se puede dejar para «cuando duela».** Lo que se pierde no se recupera: el
   día que un colegio renombre una competencia, los boletines ya impresos de los años
   anteriores **pasan a decir otra cosa y no hay de dónde sacar la que decían**. La columna
   sólo sirve si está **antes** del primer renombrado. Está apuntada también en
   *«Lo que sigue abierto de verdad»*, al final de este documento.

   > **CONSTRUIDA** *(14 sep 2026)* — `2026_09_14_100000_competencia_congelada`,
   > `frases_asignatura.competencia` en `text`, anulable y **sin back-fill**. La escribe
   > `DesempenosController::putRejilla` con las otras tres y la lee
   > `Informes\BoletinPorCompetenciasController::repartirLasMarcas`, donde **gana la
   > congelada** y el texto vivo queda de último recurso para las filas anteriores a la
   > migración, que no la van a tener nunca. La sujeta
   > `test_renombrar_la_competencia_no_cambia_la_cabecera_ya_impresa`, **visto en rojo
   > antes de escribir el arreglo**.
3. **`FraseAsignatura::deAlumno` ya prefiere el texto de hoy** cuando la frase vino del
   catálogo: hace `IFNULL(f.frase, fa.frase)`, o sea que para las frases con `frase_id` el
   congelado **no gana**. Las celdas de la rejilla no tienen `frase_id`, así que la Fase 4
   está a salvo; las frases de catálogo llevan años así y **no es de este plan**. Este
   boletín reproduce ese `IFNULL` a propósito: cambiarlo aquí haría que la misma frase se
   imprimiera distinta en dos boletines del mismo alumno.
4. **`Grupo::alumnos($grupo, $requested_alumnos)` devuelve un SUPERCONJUNTO, y eso es un
   riesgo de seguridad latente en nueve sitios.** Es el hallazgo más grave de esta fase y
   **no es de esta fase**: estaba ahí antes y sigue estando.

   El parámetro se llama `$con_retirados`, pero lo que el front le pasa es
   `requested_alumnos` —a quién se quiere imprimir—, y el método **no filtra**: entra por
   su otra rama y devuelve **todos los matriculados vigentes del grupo MÁS** los retirados
   que se pidan por `matricula_id`. **El filtro lo tiene que hacer quien llama.**

   **El modo de fallo es que no se rompe nada: sale de más.** Las rutas de boletín llevan
   `boletin.propio`, que deja pasar a un alumno **cuando pide el suyo**; sin el filtro de
   después, esa misma petición contesta **200 con el boletín del grupo entero** — los
   treinta compañeros dentro de la cuenta de un acudiente, sin un error, sin una línea en
   el log y sin que ninguna pantalla se vea rara. La primera versión de
   `BoletinPorCompetenciasController` no lo tenía, y **pasaba sus tests**: lo único que lo
   sacó fue leer `Grupo::alumnos` entero para entender por qué los tres de siempre llevan
   un segundo `foreach` que parece una copia inútil.

   **Censo del 13 sep 2026, con `grep -rn 'Grupo::alumnos(' app/` y no deducido: nueve
   llamantes pasan el segundo argumento y LOS NUEVE FILTRAN.** No hay ninguno expuesto
   hoy, y eso hay que decirlo igual de claro que el riesgo:

   | llama | filtra en |
   |---|---|
   | `BolfinalesController:66` | `:169` |
   | `Informes/BoletinesController:211` | `:268` |
   | `Informes/Boletines2Controller:137` | `:193` |
   | `Informes/Boletines3Controller:140` | `:202` |
   | `Informes/BolfinalesController:286` | `:472` |
   | `Informes/BolfinalesPreescolarController:92` | `:187` |
   | `Informes/CertificadosPersonaController:106` | `:254` |
   | `Informes/NotasActualesAlumnosController:90` | `:127` |
   | `Informes/BoletinPorCompetenciasController:181` | `soloLosPedidos()` |

   Ocho de los nueve lo hacen con **la misma copia del mismo `foreach` de cuatro líneas**,
   y ésa es exactamente la forma que tiene en este repo «se arregló aquí y no llegó allí».
   Hoy no falta en ninguno; **el día que falte en uno, ese uno responde 200**.

   **El aviso se escribió donde lo va a leer el siguiente**: en el docblock de
   `Grupo::alumnos` (`app/Models/Grupo.php`), con el censo dentro y con la orden que lo
   recuenta. Un `.md` no lo lee quien va a llamar al método.

   **Y no se «arregla» filtrando dentro del método**: la lista completa la necesitan los
   llamantes para el **puesto**, que es una posición relativa al grupo —filtrar dentro le
   daría el primer puesto a cualquiera que pida su propio boletín—. Si algún día se separa,
   son **dos métodos** —el conjunto contra el que se compara y la lista que se imprime—, no
   un `if` más. Eso es entrega propia y no se hizo aquí.
5. ~~**`EscalaDeValoracion::valoracion` redondea y nunca devuelve nada**~~ — **ARREGLADO
   el 13 sep 2026 por `bd02f66`, y con más alcance del que este punto le veía.**

   Lo que se midió aquí: `valoracion()` hacía `round($nota)` y devolvía
   `(object)['desempenio' => '']`, mientras el `left join` de
   `Grupo::detailed_materias_notafinal` ni redondeaba ni rellenaba, así que los tres
   boletines usaban **las dos reglas en la misma respuesta** —el nivel de la asignatura
   por el join, `promedio_desempenio` por `valoracion()`— y **4 filas del año 8** salían
   sin nivel en la asignatura y con nivel en el promedio.

   `bd02f66` no lo arregló igualando una a la otra: puso **una tercera regla, la buena**,
   en los trece sitios —`porc_inicial <= nota AND nota < porc_final + 1`—, que **ni
   redondea ni deja hueco**. El `round()` se fue. Lo que este punto contaba como 4 filas
   era además la mitad del problema: el otro camino, el de PHP, imprimía **SUPERIOR**
   donde el colegio había escrito que ALTO llega hasta 45.

   > **Y el barrido de trece aterrizó sobre quince.** `bandaDeLaNota()` de este boletín
   > (Fase 6) y `DesempenosController:2305` (Fase 4) se escribieron **en paralelo**, con el
   > censo ya cerrado, y nacieron con `<= porc_final`. Resultado dentro de este módulo:
   > `Grupo::detailed_materias_notafinal` daba la asignatura con la regla nueva y
   > `promedio_desempenio` con la vieja — **la misma avería de este punto, dentro de la
   > respuesta que venía a no repetirla**. Los dos los cerró `dcb00bc`.
   >
   > **Cómo se destapó, que es lo que hay que llevarse:** no lo vio la suite —ningún test
   > miraba esa comparación—, lo vio alguien **leyendo el docblock**. Decía que la regla
   > *«coincide con lo que el `left join` ya hace, que es lo que no se puede cambiar sin
   > tocar los boletines de siempre»*, y `bd02f66` había cambiado justo eso: **las dos
   > mitades de la justificación eran falsas**. Una justificación que dice lo contrario de
   > lo que hace es peor que ninguna — la ninguna manda a leer el código.
   >
   > **Y la lección de método no es «contar mejor»: es que un barrido caduca.** Está
   > completo cuando se corre e incompleto cuando aterriza, y con cuatro sesiones
   > escribiendo caduca en minutos. Por eso lo que cierra esto no es otro recuento sino
   > `CentinelaDeLaReglaDeLaBandaTest`, que falla si alguien vuelve a escribir
   > `<= porc_final` en `app/` — **tokenizando y no con `grep`**, porque media docena de
   > docblocks describen la regla vieja para explicar por qué se cambió, éste incluido.

   > **Lo que quedó de esta casa, y era el rojo que este punto dejó vivo:** el caso
   > `sin definitiva y fuera de escala` se apoyaba en el hueco de la frontera —29,5 con la
   > escala cortada en 29/30— y al cerrarse el hueco **pasó a comprobar que existiera algo
   > que se acababa de arreglar**. Remontado sobre las **dos** formas de `sin_banda` que
   > sobreviven, con un alumno por motivo: espera **2**, no 0. Y con un caso hermano que
   > mira **las dos mitades de la misma respuesta** —asignatura y promedio— para que el
   > desalineamiento no pueda volver en silencio.
6. **`NotaComportamiento::nota_comportamiento` cambia de tipo.** Sin fila devuelve
   `["notas_finales" => []]`, que es un array **no vacío** y por tanto *truthy*: el
   `if ($comportamiento)` de los tres boletines entra, el `->definiciones` de dentro
   revienta, y un `try/catch` lo recoge asignando `$alumno->comportamiento['definiciones']`.
   O sea que el campo `comportamiento` sale **objeto o array según si el alumno tenía
   nota**, en los tres. Aquí se mira el tipo: sin fila va `null`.
7. **Las rutas de `competencias/` + `desempenos/` en `main` son 19, no 21.** Siete y doce,
   contadas en `routes/api/competencias.php` y `routes/api/desempenos.php`.

#### Lo que hay que correr al desplegar

Ni una migración **de esta fase**. Lo que necesita es la de la **Fase 4**,
`2026_09_13_400000_marca_del_desempeno` —las tres columnas de `frases_asignatura`—, que es
de quien lee. Con la tabla sin migrar, las dos rutas **fallan**: el `SELECT` nombra
`fa.desempeno_id`, `fa.escala_id` y `fa.nivel`.

> **Y el 13 sep por la noche `simonbolivar` —la base de desarrollo— todavía no la tiene**:
> 75 migraciones y ninguna columna de las tres. **No se corrió desde aquí**: la migración
> es de otra fase y de otra sesión, y meterle un `ALTER` a la base que comparten cuatro
> árboles no es de esta entrega. Por eso la prueba del docker se corre con
> `-e DB_DATABASE=simonbolivar_testing_f6` —la base de la sesión, que sí está migrada— y
> por eso lleva dentro un superusuario de paso: en el seed anonimizado **no existe
> `administrador`**.

---

## 3. La cuenta de rutas, y cómo se cuenta

**26**, no 20: las 20 del documento de decisiones, más `PUT years/modelo-evaluacion`
(§1.4), más el `GET desempenos` de la planilla, más **las cuatro de los desempeños propios del
docente** que exige D14 (Fase 3), que la §5.3 del doc 28 daba por
incluido en «6 calcadas» y no lo está.

> **Y quedaron en 24, no en 26** *(13 sep 2026, al construir la Fase 6)*. Aquí se contaban
> **cuatro** para el boletín nuevo, «calcadas de `boletines3`». Son **dos**: la tercera de
> aquéllas manda un **alumno** a la papelera y no borra ningún boletín, y la cuarta es byte
> a byte la misma en los tres y **no la llama ningún cliente**. Los dos motivos están
> medidos en la §Fase 6. **La resta es a la baja y por medición, no por recorte**: las dos
> que no entran no entrarían mejor mañana.
>
> Así que: 7 de `competencias/` + 12 de `desempenos/` + 1 de `years/` + 2 de
> `desempenos/rejilla` (Fase 4) + **2** del boletín nuevo = **24**.

Sobre una base que **hay que contar**, no heredar. *(Cuando esto se escribió, `CLAUDE.md`
decía 579 y el doc 28, 577. Contado con `route:list --json` **en `.worktrees/f6`** el 13 sep
por la noche: **599** antes de la Fase 6 y **601** después — y `CLAUDE.md` ya dice 599, así
que lo que había que recontar era esta línea.)*

**Lo que mueve cada tanda**, y son **tres instantáneas, a veces cuatro**:

| fichero | se mueve |
|---|---|
| `rutas.json` | siempre |
| `guards-por-ruta.json` | siempre — las 24 llevan guard |
| `guard-por-familia.json` | siempre; estrena `competencias`, `desempenos` y `boletines-competencias` |
| `familias-que-nunca-entran-en-el-candado.json` | **sólo si alguna familia nueva se queda con menos de dos rutas con guard** — que es justo lo que se evita colgando el catálogo del MEN de `competencias/` (§2, Fase 2) |
| `huecos-del-seed.json` | **la cuarta que se movió de verdad, y no es la que esta tabla esperaba** — ver debajo |

> **La «a veces cuatro» resultó ser otra.** Esta tabla daba por cuarta a
> `familias-que-nunca-entran-en-el-candado.json`, y con la Fase 6 **no se movió**: la
> familia nueva entra con 2 de 2 con guard y el umbral es `conGuard < 2`. La que sí se
> movió —y puso la tanda entera en rojo con **un solo** test, `HuecosDelSeedTest`— es
> **`huecos-del-seed.json`**, que **no depende de las rutas sino de los ficheros de
> instantánea**: los lee todos con un `glob` y saca el mapa de qué partes de la respuesta
> no comprueba nadie.
>
> O sea: **se mueve cada vez que nace una instantánea, tenga o no rutas nuevas detrás.**
> Va escrito aquí porque su rojo no se parece en nada a su causa —dice «los huecos son los
> conocidos» y la causa es que hay un fichero `.json` más en la carpeta—.

`RutasPreLoginTest::TOTAL_PUBLICAS` **sigue en doce** y `AutenticacionTest::SIN_GUARD`
no se mueve: **ninguna de las 24 es pública ni debe serlo.**

Y `familias-que-nunca-entran-en-el-candado.json` **tampoco se movió con `boletines-competencias`**,
por lo mismo que con las otras dos familias: entra con **2 de 2 con guard**, y el umbral es
`conGuard < 2`. Dos rutas es el mínimo que no abre el agujero — una sola habría bastado
para que la familia entrara en ese censo el día que naciera.

---

## 4. Lo que este plan NO toca

Se repite entero porque es lo que hace que todo lo de arriba sea seguro, y porque la
lista es el contrato:

`unidades` · `subunidades` · `notas` · **la fórmula de la definitiva** · los **tres
boletines de hoy** · `frases` y su pantalla · `frases_preescolar` y sus tres rutas ·
**la rejilla de notas del boletín independiente** (`unidades.alumno_id`,
`BoletinIndependiente::alcance()`, la marca para todas las asignaturas y el
interruptor por periodo).

Y **las cinco reglas de la §4 del doc 28 las hereda todo lo que se escriba aquí**: la
plantilla siembra y no manda; nada se siembra en un periodo cerrado; la fórmula no
cambia; nada se siembra encima de lo que ya tiene notas; y `alumno_id IS NULL` en todo
lo que hable del reparto del curso.

---

## 5. Lo que queda fuera de estas fases, y no es un olvido

- **El candado del docente sobre `unidades`/`subunidades`** (decisión 14 del doc 28).
  Espera al censo de `por_defecto = 1`, que **no se puede correr desde una sesión de
  desarrollo**. No lo bloquea nada de este plan y este plan no lo desbloquea.
- **La Entrega 4 del doc 28** —tercer origen `{tipo:"plantilla"}` en `copiar` y sembrar
  al marcar— está aprobada (D18) y **es independiente de estas siete fases**: cero
  rutas nuevas.

  > ### ⚠️ La última línea de D18 NO se puede cumplir, y no por coste: haría daño
  >
  > **Medido el 13 sep 2026 por `myvc-front-50` y verificado aquí en el código.** D18 pide que al
  > marcar a un alumno se le copien **también los desempeños del grupo** a su nombre, «igual que
  > las unidades». **No es igual, y la diferencia está en cómo se leen las dos tablas:**
  >
  > ```
  > unidades      u.alumno_id <=> :alcance                        <- EXCLUYE: o las suyas o las del grupo
  > desempenos    d.alumno_id IS NULL OR d.alumno_id IN (…)       <- SUMA: las del grupo Y las suyas
  > ```
  >
  > (`DesempenosController::desempenosDeLaRejilla`, el filtro que arma antes del `SELECT`.)
  >
  > Con la lectura aditiva, copiarle al alumno los desempeños del grupo **duplica cada columna de
  > la rejilla** — y no sólo las suyas: la rejilla es por asignatura, así que el grupo entero vería
  > cada columna dos veces. **La premisa de D18 era que la lectura excluía, y en esta tabla no.**
  >
  > Su agente no la siguió, le puso un test y lo dejó escrito en el código. **Se anota aquí con el
  > porqué delante y no se borra**: una decisión retirada sin su motivo se vuelve a pedir dentro de
  > seis meses y parece un olvido en vez de una imposibilidad.
  >
  > **Lo que sí se cumplió de D18**, y por eso la decisión no cae entera: el tercer origen
  > `origen.tipo: "plantilla"` en `POST boletin-independiente/copiar` —llamando a
  > `AlcanceDeLaPlantilla` y no reescribiendo su precedencia— y que **marcar siembre la rejilla de
  > notas**. Lo que no se hace es lo de los desempeños.

  ~~Con D5 encima, al marcar se siembran **también los desempeños** del
  grupo a nombre del alumno~~ — **retirado por el recuadro de arriba**; lo demás de D18 sigue
  entero y su sitio natural es **detrás de la Fase 3**.

  > **Y está HECHA** *(13 sep 2026)*: el tercer origen, marcar-siembra con sus ocho números y la
  > previa de la plantilla en `planilla`. Contrato y porqués en las §§6.1-6.3 del
  > [19](19-boletin-independiente.md); la línea que no se cumple la fija
  > `test_marcar_no_siembra_desempenos`.
- **La fase 0 de la Entrega 5** —sacar `nota × % / 100` de sus **18 sitios en 9
  ficheros** a un punto único— está aprobada y **se despliega sola** (D19). No cambia
  ni un resultado y se verifica con las instantáneas tal como están. **No depende de
  nada de este documento y nada de este documento depende de ella**, así que puede ir
  en paralelo en otro árbol.

---

## 6. Las tres decisiones — **cerradas el 13 sep 2026**

Se hicieron y se contestaron el mismo día. Se dejan aquí con lo que se propuso al lado, porque
**una decisión sin su alternativa no se puede revisar dentro de dos años**.

| | qué se preguntó | qué decidió Joseth |
|---|---|---|
| **D24** | ¿quién puede cambiar el modelo de evaluación del año? | **la propuesta, entera**: `PUT years/modelo-evaluacion` con `auth.personal` en la ruta y `can_edit_plantilla_notas` dentro. El argumento fueron las **21 columnas nombradas** de `putGuardarCambios` y el `auth.personal` de los dieciséis `years/*` de escritura (§1.4) |
| **D25** | los desempeños de «todos los grados» y los de 6.º, ¿acumulan o compiten? | **acumulan** — y va escrito que es **deliberadamente lo contrario** que la plantilla, donde gana la más específica (§1.6) |
| **D23** | **¿qué premarca la rejilla?** | **ninguna de las dos salidas que se plantearon: la celda ES el nivel.** Superior/Alto/Básico/Bajo por cruce, premarcado por la nota. El desajuste se cierra por la raíz en vez de traducirse (§1.5) |

**D23 es la que conviene leer entera**, y no porque contradiga nada: porque **la pregunta estaba
mal hecha aquí**. Este documento preguntó *«qué texto escoge el nivel»* dando por supuesto que la
celda era un sí/no, y con ese supuesto sólo había dos salidas y las dos eran malas. La pregunta
que sí tenía respuesta era *«¿por qué es binaria la celda?»*. **El hallazgo —que las dos piezas no
encajaban— era correcto y sigue siéndolo; lo que estaba mal era el marco en que se buscó la
salida.**

### D18, REVISADA POR MEDICIÓN el 13 sep 2026 — media decisión no se puede cumplir

Está en la lista de decisiones y no sólo en el controlador que la ejecuta **porque es una
decisión la que cae, no una línea de código**. El mecanismo está contado entero en el recuadro
de la §5; aquí va lo que hace falta para **decidir**, que es otra cosa.

| la mitad de D18 | qué se hizo |
|---|---|
| *«el tercer origen `{tipo:"plantilla"}` en `copiar`»* | **hecho**, con la precedencia de `AlcanceDeLaPlantilla` llamada y no reescrita (§6.2 del [19](19-boletin-independiente.md)) |
| *«y sembrar al marcar»* | **hecho**, con ocho números en la respuesta (§6.3 del 19) |
| *«y con D5, al marcar se siembran **también los desempeños**»* | **no se hace: su premisa es falsa en esa tabla** (§5) |

**La asimetría, que es lo que hay que entender para no volver a pedirlo.** Las dos tablas
tienen una columna `alumno_id` que se llama igual y **se lee con el operador contrario**:
`unidades` con `<=>`, que **excluye**, y `desempenos` con un `OR`, que **suma**. De ahí salen
dos conclusiones opuestas sobre la misma acción:

- al marcado **le desaparecen las unidades del curso**, así que **hay que dárselas** — es la
  §9.1 del 19, «el alumno que se cae por el hueco», y es la mitad de D18 que sí se cumplió;
- al marcado **no le desaparece ningún desempeño**, así que dárselos **no le añade nada y le
  duplica todo**.

**Lo que decide es el operador de la lectura, no la columna.** Quien dentro de seis meses vea
`unidades.alumno_id` y `desempenos.alumno_id` una al lado de la otra va a suponer que se
gobiernan igual — y ésa es exactamente la suposición que hizo D18.

**Lo que sigue en pie de D5**: escribirle desempeños **suyos** a un alumno PIAR. Eso es el CRUD
de `desempenos` con `alumno_id`, una decisión del colegio alumno a alumno, y no una siembra
automática al marcar.

> **La D18 canónica vive en `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md` §5**, que es del
> repositorio del front, y esta sesión tenía dicho que no lo tocara. **Allí sigue la frase
> entera sin este matiz**: quien tenga el front tiene que llevárselo.

### Lo que sigue abierto de verdad, y no lo desbloquea ninguna decisión

- ~~**La cuarta columna que congelaría el texto de la COMPETENCIA.**~~ **HECHA el 14 sep
  2026**, y se queda escrita porque el motivo por el que no podía esperar sigue siendo la
  parte que hay que entender. Decía: sale de construir la Fase 6, `frases_asignatura`
  congela el texto del desempeño y **no hay dónde congelar el de su competencia**, así que
  renombrar una competencia en 2028 cambia la cabecera de un boletín de 2026; es
  exactamente el argumento de la tercera columna de la Fase 4, un piso más arriba, y **no
  lo desbloquea ninguna decisión**: es una entrega con su migración. Lo es:
  `2026_09_14_100000_competencia_congelada` más una escritura en `PUT desempenos/rejilla`
  y una lectura en `Informes\BoletinPorCompetenciasController`. **El comentario que
  marcaba el sitio ya no dice que falte**: dice cuál de los dos textos gana y por qué.
- **¿Las rúbricas de biblioteca (`es_plantilla = 1`) son configuración del colegio?** Si lo
  son, el año nuevo tiene que heredarlas y eso es una entrega con su plan —cuatro tablas
  hijas y sus ids—, no una línea en `postStore`. Está declarada en la §2.bis y la vigila
  un test del grupo `rojo`, no un comentario.
- **El boletín de periodo no puede imprimir un periodo pasado, y es un límite y no un fallo.**
  Medido el 14 sep 2026 al reproducir un aviso del front que lo contaba como *«el controlador
  ignora el `periodo_a_calcular` de la URL»*: **la ruta de la API nunca ha aceptado uno**.
  `boletines-competencias/detailed-notas/{grupo_id}` recibe **sólo el grupo**, el `PUT` no lee
  periodo ni año del cuerpo, y `periodo_a_calcular` vive únicamente en `Periodo::hastaPeriodoN` y
  `NotaComportamiento`, que son de los boletines de **fin de año**. Los cinco de periodo hacen lo
  mismo: `$this->user->periodo_id`.

  O sea que **está atado al periodo activo del usuario por construcción**, y para ver un periodo
  pasado hay que mover el contexto. **La diferencia entre las dos redacciones no es cosmética**:
  «no lo lee» suena a descuido y manda a arreglar un método; «no lo acepta» dice que hay una
  decisión que tomar —si alguien quiere imprimir un periodo pasado, es entrega propia— y que
  mientras tanto el comportamiento es coherente. *Un hecho idéntico con dos nombres manda a la
  siguiente sesión a dos sitios distintos.*

- **Los dos censos del día del despliegue** (§7). No se pueden correr desde una sesión de
  desarrollo.
- **Las materias que el MEN no cubre**: Religión, Artes, Ed. Física y Tecnología **nacen vacías**,
  y eso se dice en la pantalla.
- **La escala del alumno con PIAR dentro de un grupo numérico**: se resuelve con texto (D17), pero
  **qué** texto —la escala del grupo o una propia— no está decidido. Con D23 encima, la pregunta
  se afila: la celda guarda un nivel de `escalas_de_valoracion`, que es **por año** y no por
  grupo, así que un alumno con escala propia **no tiene hoy dónde guardarla**. No bloquea las
  siete fases —la columna es anulable y el docente escribe el texto que quiera— pero es lo
  primero que va a preguntar el colegio que tenga uno.

---

## 7. Lo que se cuenta el día del despliegue

Con el bucle de [DESPLIEGUE.md](../DESPLIEGUE.md) sobre
`/home/micolev1/*.micolevirtual.com/8myvc` — **diecisiete carpetas: dieciséis colegios
y `demo`** (§1.2). Los tres son de sólo lectura y se escriben **con el denominador
delante**: «X de 17, N de M».

1. **`por_defecto = 1` en `unidades` y `subunidades`** (doc 28 §5.1.e). Son literalmente
   las filas que el día del candado dejan de poder tocarse. En `simonbolivar`: **cero
   de 51.519**.
2. **`default_unidades` / `default_subunidades`** (D21). No las lee nadie en `app/`,
   y nadie sabe qué hay dentro en producción. **Contar antes de tocar.**
3. **Las frases cortadas** — `SUM(CHAR_LENGTH(frase) = 255)` — queda escrita en el doc
   28 §1.ter **por si algún día se quiere contar**: Joseth ya decidió que no se avisa
   (decisión 12). Se cuenta sólo si se pide.

---

## 8. De dónde sale cada cosa

- `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md` — las 22 decisiones del 13 sep 2026.
- `myvc_front/INVESTIGACION-COMPETENCIAS-Y-DESEMPENOS.md` — cuatro decretos, catorce
  SIEE, trece programas.
- [28-competencias-e-indicadores.md](28-competencias-e-indicadores.md) — las siete
  entregas y su precio. Lo que aquí se traza es **el orden**, no otra propuesta.
- [30-lo-que-reparte-una-columna-nueva.md](30-lo-que-reparte-una-columna-nueva.md) —
  por qué §1.3 cuenta treinta instantáneas y no cero.
- `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md` **§7.bis** — D23, D24 y D25, las tres que
  este documento abrió y que se cerraron el mismo día (commit `8bedcaf6` del front).
- Medido el **13 sep 2026** sobre `main` en **`c0ed278`**, árbol principal, **sin
  contenedor levantado**: `frases_asignatura.frase` todavía `varchar(255)`
  (`mysql-schema.sql:1078`); `fix/frases-asignatura-text` en `50399f6`, base `ab23e2d`,
  **169 commits** por detrás y 7 migraciones en medio; `Unidad::deAsignaturaCalculada`
  en la línea **141** con sus dos modos de rango; `escalas_de_valoracion` sin ninguna
  relación con un catálogo de textos (`mysql-schema.sql:1031`);
  `Autoriza::PERMISO_PLANTILLA_NOTAS` en `app/Support/Autoriza.php:47`; **cero**
  apariciones de «jefe de área»; **3 de 125** instantáneas con la **fila entera** de `years`
  dentro (31 la mencionan, 28 por una proyección nombrada — §1.3); `putGuardarCambios` con **21** columnas nombradas; los tres boletines en
  **629 + 605 + 586** líneas.
