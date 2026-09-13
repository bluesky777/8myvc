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
> | sin `years` dentro | 104 | — |
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
> O sea que **«70 o 36, nada entre medias» era una simplificación mía y es falsa**: hay
> proyecciones de 19, 17 y 16. Lo que sí es cierto y es lo único que hace falta: **entre 36 y 70
> no hay nada**, así que el corte «lleva la fila entera / no la lleva» no necesita elegir ningún
> umbral. *Un recuento afirma el corte; un reparto lo enseña* — la frase es de `2e` y por eso el
> reparto va aquí y no la cuenta.
>
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
> | **exposición** — rutas cuya respuesta gana la columna | **8** |
> | **cobertura** — instantáneas que se mueven | **3** |
>
> **Las ocho**, con su ruta, leídas de `HEAD` y no del árbol de trabajo:
>
> | método | cómo publica la fila entera | ruta | ¿instantánea? |
> |---|---|---|---|
> | `getIndex` | `SELECT y.*` crudo | `GET years` | **sí** |
> | `getColegio` | `SELECT *` crudo | `GET years/colegio` | **sí** |
> | `getTrashed` | `Year::onlyTrashed()->get()` | `GET years/trashed` | **sí** |
> | `postStore` | `return $year` | `POST years/store` | no |
> | `putGuardarCambios` | `return $year` | `PUT years/guardar-cambios` | no |
> | `deleteDelete` | `return $year` | `DELETE years/delete/{id}` | no |
> | `deleteDestroy` | `return $year` | `DELETE years/destroy/{id}` | no |
> | `putRestore` | `return $year` | `PUT years/restore/{id}` | no |
>
> **La brecha son cinco rutas que publican la fila entera y a las que nadie les mira el cuerpo**:
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

| fase | qué entrega | rutas | migraciones | instantáneas |
|---|---|---|---|---|
| **0** | el `ALTER` a `text`, remedido | 0 | 1 (ya escrita) | 0 |
| **1** | el colegio elige su modelo y le pone nombre | **1** | 1 | **3** |
| **2** | competencias, con el catálogo del MEN | 7 | 1 | 0 |
| **3** | desempeños: catálogo, siembra y los propios del docente | 8 | 1 | 0 |
| **4** | la rejilla premarcada | 2 | 1 | 0 |
| **5** | qué comparten los tres boletines *(medición, sin código)* | 0 | 0 | 0 |
| **6** | el boletín nuevo | 4 | 0 | 0 |

**Total: 22 rutas**, no 20 — las 20 del documento de decisiones más
`PUT years/modelo-evaluacion` (§1.4) y menos ninguna. Se cuentan el día que entren,
con `route:list --json`, **en el árbol donde se escriban** (§3).

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

**8 rutas**: `GET`/`POST`/`PUT {id}`/`DELETE {id}`/`PUT orden`/`PUT copiar` sobre el
catálogo, **`PUT desempenos/sembrar`** y `GET desempenos` de una asignatura+periodo
(lo que lee la planilla).

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

**El candado del docente** es la marca `por_defecto`, y **son cuatro caminos, no dos**:
`update`, `destroy`, `forcedelete` y `orden`. Poner el candado sólo en `update` lo
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

### Fase 5 · Qué comparten los tres boletines *(medición, sin código)*

**629 + 605 + 586 líneas**, comprobadas. El documento de decisiones tiene razón en el
argumento y el número lo respalda: escribir el cuarto a ciegas deja el problema **un
33 % peor**, porque el próximo arreglo habrá que escribirlo cuatro veces.

Lo que esa medición tiene que contestar, y no es «cuántas líneas se repiten»:

1. **Qué consulta hace cada uno y en qué se diferencian** — los tres pasan por
   `Unidad::deAsignaturaCalculada`, y son **cuatro** los consumidores, no tres:
   `Informes/NotasActualesAlumnosController:187` también.
2. **Dónde divergieron de verdad**: qué hace uno que los otros no, y si alguna de esas
   diferencias es un fallo en vez de una intención.
3. **Qué parte es la maqueta y qué parte es el dato.** El boletín nuevo nace del dato
   común; la maqueta es suya.
4. **Cuál de las doce instantáneas de boletín cubre cada rama**, porque lo que no esté
   cubierto es lo que se romperá al extraer.

Es **una noche**, no un mes, y no entrega nada al colegio — por eso va aquí y no
antes: las fases 1 a 4 sí entregan.

---

### Fase 6 · El boletín nuevo

**4 rutas**, calcadas de `boletines3`. Se elige **llamando a su ruta**, como ya pasa
con `boletines2` y `boletines3`: **no hay interruptor** (decisión 6 del doc 28, y por
eso `show_competencias_bol` se retiró).

- Imprime **nota + nivel + los textos del alumno**.
- **Honra `grupos.caritas` imprimiendo el desempeño en TEXTO** (D17): la columna ya
  existe, ya es por grupo, ya se copia al año siguiente (`YearsController:382`) y ya
  viaja al front — **lo único que falta es que el backend la lea**. El icono queda de
  adorno y nunca solo: Decreto 2247 art. 10 y 1411/2022 piden *«informes descriptivos
  … de corte cualitativo»*, y una carita no lo es.
- **No se tocan `BoletinesController:611` ni `Boletines2Controller:585`.** La regla
  nace en la maqueta nueva, y así no se mueve ninguna instantánea publicada. Meterlo
  en los de siempre es **entrega propia** y sólo si un colegio lo pide (D16 cierra la
  decisión 19 por omisión).

> **`caritas` se toca con el guante puesto**: es la columna de la §153 de
> `GruposController` —tenía defecto `false` y ese defecto la apagaba, así que
> corregirle el nombre a un grupo de preescolar le cambiaba la forma de evaluar—.
> Todo endpoint nuevo que la lea hereda ese aviso.

---

## 3. La cuenta de rutas, y cómo se cuenta

**22**, no 20: las 20 del documento de decisiones, más `PUT years/modelo-evaluacion`
(§1.4) y más el `GET desempenos` de la planilla, que la §5.3 del doc 28 daba por
incluido en «6 calcadas» y no lo está.

Sobre una base que **hay que contar**, no heredar: `CLAUDE.md` dice 579 y el doc 28
dice 577 (§1.1).

**Lo que mueve cada tanda**, y son **tres instantáneas, a veces cuatro**:

| fichero | se mueve |
|---|---|
| `rutas.json` | siempre |
| `guards-por-ruta.json` | siempre — las 22 llevan guard |
| `guard-por-familia.json` | siempre; estrena `competencias` y `desempenos` |
| `familias-que-nunca-entran-en-el-candado.json` | **sólo si alguna familia nueva se queda con menos de dos rutas con guard** — que es justo lo que se evita colgando el catálogo del MEN de `competencias/` (§2, Fase 2) |

`RutasPreLoginTest::TOTAL_PUBLICAS` **sigue en doce** y `AutenticacionTest::SIN_GUARD`
no se mueve: **ninguna de las 22 es pública ni debe serlo.**

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
  rutas nuevas. Con D5 encima, al marcar se siembran **también los desempeños** del
  grupo a nombre del alumno, así que su sitio natural es **detrás de la Fase 3**.
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

### Lo que sigue abierto de verdad, y no lo desbloquea ninguna decisión

- **El nombre y la maqueta del boletín nuevo** — espera además la medición de la Fase 5.
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
