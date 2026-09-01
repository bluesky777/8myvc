# Lote F — los tres campos de las pantallas de varios periodos, y la fase 5

> Rama `fix/bi-lote-f`, árbol `.worktrees/f`, base `simonbolivar_testing_f`.
> Sale de `main` en **`3329703`** (el reparto decía `693649e`: entre medias se
> fundieron A, B, C, D y E).
>
> El encargo son los puntos 6 y 7 de [la cola](estado-de-la-cola.md) y la
> **fase 5** del [plan](../19-boletin-independiente.md).

## 1. `alumno.bol_independiente_periodo` en `Informes/NotasPerdidasController`

Booleano, en los `alumnos[]` de **`putProfesorGrupos`** y **`putTodos`** — las dos
rutas y no una: son dos copias de la misma consulta y de la misma pantalla, y si lo
emite una y la otra no, **la que calla se lee como «este alumno no va aparte»**. Es la
misma regla que ya gobernó `puestos_con_bol_independiente`.

### El campo se resuelve sobre los periodos QUE EL INFORME LISTA, no sobre el año

Las tres consultas abarcan `p.numero <= :periodo`, o **sólo** ese número cuando llega
`solo_periodo`. Así que el campo sale de `aplicaEnAlguno()` sobre esa misma lista
(`periodosDelInforme()`), que es literalmente el criterio de `$periodos_del_informe`
de `PuestosController` — y el mismo nombre de campo, con el mismo significado, que ya
emite ese controlador.

**Preguntar por el año entero pasaría igual de verde con nadie marcado**, y en
producción diría «va aparte» por una marca de un periodo que este informe no está
listando. En una pantalla que **acusa de perder asignaturas**, eso es explicar unas
pérdidas con una razón que no las causó. Hay test para las dos direcciones.

### ⚠️ Corrección al encargo: hoy el independiente sin estructura NO «sale perdiéndolo todo» — DESAPARECE

El encargo justificaba el campo así: *«hoy un independiente sin unidades montadas sale
ahí perdiéndolo todo y parece un alumno que no estudia»*. **Medido, ya no es lo que
pasa**, y se vio ejecutando el test, no leyendo el código: la primera versión marcaba a
un alumno del seed y volvía a pedir la lista, y el alumno **no estaba**.

La razón es la fase 1, que ya está fundida: `consulta_alums` pide
`u.alumno_id <=> ALCANCE`, y para un marcado el alcance es **su id**. Sin unidades
propias no empareja con ninguna fila, así que **no aparece en la lista en absoluto**.

O sea que la pantalla ya no le acusa de nada. Lo que hace ahora es **callárselo**, que
para el docente es peor de otra manera —un alumno que de verdad está perdiendo todo se
cae del radar sin un aviso— pero **no lo arregla este campo**: es la §9.1 y la contesta
`tools/independientes-sin-estructura.php`, el punto 4 de la cola de `reparto.md`.

**Lo que este campo sí contesta, y sigue mereciendo estar:** el independiente **con**
estructura propia que pierde algo aparece en la lista, y hasta hoy sus pérdidas se leían
como si fueran contra el reparto del grupo. Ahora la pantalla puede decir que se están
contando **contra su propio boletín**. Esa es la frase que hay que darle al front, y no
la del encargo.

### Lo que NO se tocó, y por qué

**`getShowProfesor` se queda sin el campo.** No recibe `periodo_a_calcular`: su
respuesta lleva a cada alumno con **los cuatro periodos** y una `nota_asignatura` por
cada uno. Un booleano ahí no tiene un periodo al que referirse, y el que le
correspondería es el campo del punto 2 —una lista de `numero`—, que el front **no** ha
pedido para esta pantalla. Emitir un booleano por rellenar sería un campo constante más
sobre el que alguien ramifica sin que su rama muerta se note.

### Instantáneas

Dos, y las dos por un campo **añadido**:
`muestreo-notas-perdidas-todos.json` y `muestreo-notas-perdidas-profesor-grupos.json`.
**Diff mirado: una línea añadida en cada una, cero quitadas, ningún campo renombrado.**
`muestreo-notas-perdidas-show-profesor.json` no se movió, que es la comprobación de que
`getShowProfesor` se quedó como estaba.

### El rojo, comprobado y no supuesto (§1.4)

Las tres formas equivocadas, cada una contra el test que la mide:

| Forma mala | Resultado |
|---|---|
| el campo no se emite (el código de antes) | **3 rojos** |
| resuelto contra el **año entero** (se ignora `solo_periodo`) | **1 rojo** — `una marca de otro periodo no enciende el campo` |
| el campo siempre `false` | **2 rojos** |

La tercera fila es la que impide «arreglar» la segunda dejándolo apagado, que es por lo
que el test `sin_solo_periodo_el_campo_abarca_los_periodos_que_el_informe_lista` existe.

### Y por qué el test construye el caso en vez de marcar a alguien del seed

Porque marcarlo lo saca de la lista (arriba). El escenario siembra, en el periodo 1 de
la asignatura elegida, **dos subunidades del grupo y una propia de A** — números
distintos en los dos lados, que es la tercera forma de fallar de la §1.4 del reparto:
con un 1 contra un 1 el escenario equilibrado pasa en verde con la forma ingenua.

## 2. `alumno.bol_independiente_aparte_en` en `DefinitivasPeriodosController`

En `getIndex`, sobre cada alumno de `asignaturas[].alumnos.alumnos[]`. Forma fijada por
el front y no negociada:

```jsonc
"bol_independiente_aparte_en": [2, 3]   // los `numero`; [] si ninguno
```

`[]` y **no** `null` para el que va con el grupo: una lista vacía se recorre igual que
una llena y no obliga al front a decidir qué significa una ausencia. Este módulo ya
perdió una semana por leer una ausencia al revés (§6.4 del 19).

### `BoletinIndependiente::aparteEnPorAlumno(int $yearId)` — método nuevo del servicio

Se pidió a la coordinación y **la coordinación pasó el fichero al lote F** (era del
lote E, que ya no existe y cuyo trabajo está fundido). Mientras tanto estuvo como
método privado del controlador, con un comentario diciendo a quién pertenecía; al
recibir el fichero se borró de ahí.

Devuelve `alumno_id => list<numero>` en **una consulta**. Los dos que ya había no
sirven para esto: `aplica()` es por `(alumno, periodo)` y `delGrupo()` por
`(grupo, periodo)`, o sea 120 lecturas en la rejilla y ~120 en el acta — sobre un
informe cuyo docblock presume de haber pasado de 151 consultas a una.

**Tres decisiones de firma, y las tres se escribieron porque el siguiente las
re-litigaría:**

| Decisión | Por qué |
|---|---|
| recibe **`int $yearId`**, no `array $periodoIds` | el valor que sale son `numero`, así que tiene que llegar a `periodos` igual; pedir ids obligaría al llamante a traer además los `numero` y emparejarlos. Y para «los periodos que este informe promedia» ya está `aplicaEnAlguno()` — **no son la misma pregunta** |
| **sin `?array $alumnoIds`** | población: `bol_ind_periodos` sólo tiene filas que alguien escribió, así que el techo de un año es *(alumnos **marcados** × 4)*, no *(alumnos × 4)*. Hoy son cero. Un parámetro que ningún llamante usa es una rama muerta; entra el día que entre la pantalla que lo necesite (`puestos_con_bol_independiente` ya pagó esa lección) |
| **no memoiza** | cada llamante la llama una vez por petición, así que una tercera estática no ahorra nada y sí habría que acordarse de vaciarla en `olvidar()`. Lo que no se cachea no se puede olvidar mal |

Y **`aplica = 1`, no `<=>`**: es la regla partida en dos de la §1.6 (lote D). Esto no
pregunta «¿qué unidades le TOCAN?» sino «¿está marcado?», que **afirma propiedad**.

`EXPLAIN`, con su población: entra por `bol_ind_periodos` y resuelve `periodos` con
`eq_ref` sobre `PRIMARY` — una fila de `periodos` por marca, no un recorrido de
alumnos. **Medido sobre dos filas**, así que con ese tamaño el optimizador se salta el
índice; lo que el plan fija es el **orden de entrada**, que es lo que decide si esto
crece con las marcas o con el colegio.

### El rojo, comprobado (§1.4)

| Forma mala | Resultado |
|---|---|
| el campo no se emite | rojo en `assertNotSame([], $visto)` |
| lista siempre vacía (el booleano aplanado) | rojo en `assertSame($marcados, ...)` |
| sin `WHERE aplica = 1` (la fila apagada se cuela) | rojo: sale `[1, 2, 3]` donde tocaba `[2, 3]` |

**El test marca DOS periodos, y el 2 y el 3 — ni el primero ni el último.** Con uno
solo, una lista correcta y un booleano disfrazado de lista (`[periodo_del_token]`)
darían el mismo verde; empezando por el 1 acertaría también un `range()` o un `<=`.
Y deja un tercer periodo con `aplica = 0` **con la fila puesta**, que no es lo mismo
que no tener fila.

### El test usa un token de **Profesor**, y es obligatorio

`getIndex` saca el docente de `$user->persona_id` si el tipo es `Profesor`, y de
`Request::input('profesor_id')` si es superusuario. Con un `Usuario` llano no saca
ninguno: la respuesta sale `[]` en 200 y el test pasaría sin mirar nada. Es
exactamente por eso que `api/definitivas_periodos` está en `lecturasVacias()` del
muestreo, y por eso esa instantánea **no se mueve** con este cambio.

### Un aviso de instrumento, que costó cinco minutos y podía costar una noche

`docker exec ... php -r '...'` **no lee la base de tests**: sin el entorno de testing
carga el `.env` y pega contra `simonbolivar`, la de desarrollo. Se ve en el propio
`EXPLAIN`, que dice `ref: simonbolivar.bip.periodo_id`. Dos filas de
`bol_ind_periodos` que parecían una fuga de la suite —tests que escriben fuera de su
transacción— resultaron ser datos de desarrollo de otro lote probando su endpoint a
mano. **El árbol es parte del instrumento (§1.8), y la base también.**

## 3. El mismo campo en `Informes/ActasEvaluacionController`

En `putActaEvaluacionPromocion`, sobre cada matrícula (`grupos[].alumnos[]`), con la
misma forma y el mismo método del servicio.

**Aquí es el campo que corresponde, no el que cabe:** el acta es de **todo el año**, así
que decir «va aparte» sin decir en cuál de los cuatro periodos no contesta nada — el
mismo argumento por el que este campo no se aplanó a un booleano en
`definitivas_periodos`.

Y **una** consulta para el acta entera, en la línea del resto del método: las matrículas
del año ya vienen de una sola —antes eran 151 para 30 grupos, y su docblock lo
presume—, así que preguntar por `(grupo, periodo)` habría devuelto justo esas ~120.

### El acta NO lleva `asignatura.bol_independiente`, y ahora hay un centinela

Decisión tomada (cazada por el front el 1 sep 2026, punto 6 de la cola): su respuesta
son grupos con matrículas, resumen, promoción y periodos, y **no tiene ni una
asignatura por alumno**. Emitirlo ahí **no pintaría nada y no daría ningún error**: una
rama muerta invisible.

Por eso `test_el_acta_no_lleva_el_rotulo_de_asignatura` **busca el campo en el cuerpo
entero** y falla si aparece. Es la única forma de que un intento futuro se note: un
campo que no pinta y no rompe no lo ve nadie. Comprobado en rojo emitiéndolo a mano.

### Instantánea

`actas-evaluacion-promocion.json`: **una línea añadida, cero quitadas**,
`"bol_independiente_aparte_en": []`. `actas-evaluacion-detalle.json` **no se movió** —
`putDetalle` no lleva el campo y es a propósito: su lista de matrículas abarca **todos
los años del alumno**, así que una lista de `numero` de un año no tendría a qué
referirse. Se le dijo a la coordinación.

### El rojo (§1.4)

| Forma mala | Resultado |
|---|---|
| el campo no se emite | rojo — `array has the key 460` |
| lista siempre vacía | rojo — `two arrays are identical` |
| `asignatura.bol_independiente` emitido de más | rojo en el centinela |

## 4. La FASE 5 — los tres boletines probados en negativo

`tests/Contrato/BoletinesEnNegativoTest.php`, **10 casos**. No es código nuevo: los tres
boletines ya llevan el alcance desde la fase 1 (sus unidades salen de
`Unidad::deAsignaturaCalculada($alumno_id, …)`). **Lo que faltaba es el test que lo
demuestra**, y falta de verdad: con nadie marcado, `<=> NULL` y `<=> $alumno`
seleccionan lo mismo, así que un test escrito sobre el seed tal cual no distingue nada.

### Las dos direcciones, y quién paga cada una

| dirección | qué se rompe | a quién le pasa |
|---|---|---|
| **de menos** | el boletín del independiente pide las del grupo y sale en blanco | al marcado |
| **de más** | la estructura privada se cuela en el boletín de los demás | **a los otros treinta**, que no tienen cómo saberlo |

Van en **tests distintos** a propósito: el perjudicado no es el mismo, y un solo test
con las dos aserciones escondería cuál de las dos cayó.

### Qué documento prueba qué — medido, no leído

| documento | unidades | subunidades |
|---|---|---|
| `boletines` | sí | **sí** — el único que las emite (`Subunidad::deUnidadCalculada`) |
| `boletines2` | sí | no las trae |
| `boletines3` | sí | no las trae |

Comprobado en las instantáneas y en la respuesta. Por eso el caso de **subunidades**
—la palabra que usa el encargo— corre **sólo sobre `boletines`**: pretender
comprobarlas en los tres habría dado **dos verdes por vacío**.

### Los dos lados con números distintos (§1.4, tercera forma)

**Dos subunidades del grupo contra tres propias.** Con un 1 contra un 1, una
implementación que trajera *justo las contrarias* daría el mismo número de filas y el
test pasaría con el código malo — le pasó al lote A esta misma noche. Y además cada
fila lleva **su nombre**, así que lo que se compara es **qué** salió, no cuántas.

### El rojo, contra el código de ANTES de la fase 1 — y la primera vez no valía

| Forma | Resultado |
|---|---|
| **sin la condición de alcance** (el código de antes de la fase 1) | **10 de 10 rojos**, 85 aserciones |
| **`=` en vez de `<=>`** | **7 rojos, 3 verdes** |

Los 3 verdes de la segunda fila **son correctos y hay que leerlos**: con `=`, el
*marcado* sigue emparejando sus propias unidades (su alcance es su id), así que
«el boletín del independiente trae lo suyo» pasa. Lo que se rompe es el **compañero**,
cuyo alcance es `NULL` y con `=` no empareja nada: la forma «de menos» por el otro
lado, exactamente como la describe el docblock del servicio.

> **Y el primer intento del rojo no valía, aunque saliera rojo.** Quitando sólo la
> condición del SQL y dejando el `':alcance' => $alcance` en los parámetros, los 10
> caían **en 0,3 s cada uno**: eso no es una aserción, es PDO reventando por un
> parámetro que sobra. Se rehízo quitando también el binding y entonces los 10 caen
> **con el mensaje que toca** —`does not contain 'F5 UNIDAD DEL GRUPO'` y
> `does not contain 'F5 UNIDAD PROPIA'`— en 1,5–3 s. **Mirar el tipo del fallo antes que
> el mensaje** es la regla de la §4 del estado de la cola, y aquí decidía si el test
> mide algo.

### El extractor: ancho para los marcadores, estrecho para comparar dos alumnos

`boletines/detailed-notas` cuelga la estructura de **dos sitios** —`asignaturas[]` y
`asignaturas_perdidas[]`—, así que las tres subunidades propias aparecían **seis
veces** y el primer `assertCount(3)` se cayó por eso y **no por el alcance**.

Las dos ramas tienen que estar acotadas, así que **buscar a lo ancho es lo correcto
aquí** —si una lo estuviera y la otra no, apuntar sólo al boletín dejaría pasar la
fuga— y lo que se arregla es *contar*: el extractor deduplica, porque lo que se compara
son nombres y cada nombre existe una vez en la base.

**No contradice el aviso de `BoletinDelIndependienteTest`**, que dice lo contrario
—«no barras la respuesta entera»—, y la diferencia importa: allí se comparaban **las
listas de dos alumnos entre sí**, y `asignaturas_perdidas` difiere de un alumno a otro
con razón y sin nadie marcado. Aquí se compara contra **marcadores con nombre propio**
creados por el test, que no dependen de las notas de nadie. **El único caso que sí
compara dos alumnos entre sí —`sin_marcar_a_nadie`— usa el extractor estrecho.**

### El control, y lo que fija de más

`sin_marcar_a_nadie_los_dos_ven_lo_mismo` monta el caso **sin marcar**: la unidad con
dueño existe en la base y lo único que falta es la fila de `bol_ind_periodos`. Fija dos
cosas:

- que la fase 1 **fue aditiva** (los dos alumnos ven lo mismo) — el criterio de la §4
  del plan;
- y que **sin la fila no le sale ni a su dueño**, que es la decisión 7 en su forma más
  corta. Si le saliera, el alcance estaría mirando `unidades.alumno_id` en vez de la
  marca.

## 8. La §9.5 — cuál es «la matrícula del año» (el escritor y la regla)

`matriculas` **no tiene clave única sobre (alumno, año)**, y la ficha y el guardado no
elegían la misma fila:

| | Consulta | `m.deleted_at` | `g.deleted_at` | `ORDER BY` | Se queda con |
|---|---|---|---|---|---|
| **escribe** | `Alumnos\GuardarAlumno::valor` | **no filtraba** | **no filtraba** | **ninguno** | `[0]` |
| **lee** | `AlumnosController::putShow` | filtra | filtra | `a.apellidos, a.nombres` | `[0]` |

**Y el `ORDER BY` de la lectura no desempata nada:** para un solo alumno, ordenar por su
apellido y su nombre es un empate total. Las dos se quedaban con «la primera que
devuelva MySQL» y **nada garantiza que sea la misma**.

> **Son TRES columnas y no cuatro** (corrección de la coordinación, aceptada):
> `repitente`, `promovido` y `nro_folio`. La marca del boletín independiente salió de
> `matriculas` el 31 ago 2026 y vive en `bol_ind_periodos`, con clave única sobre
> `(alumno_id, periodo_id)` — ahí no hay dos filas entre las que equivocarse.

### La decisión: **la VIVA, y entre varias vivas, la MÁS RECIENTE**

No sale de una preferencia: sale de lo que ya hace `Matricula::matricularUno()`, que es
el único sitio que crea matrículas. Cuando encuentra varias del mismo año **activa una y
borra las demás**, o sea que el sistema **ya promete «una viva por año»**. Lo que
faltaba es que quien lee y quien escribe **lean esa promesa igual cuando no se cumple**.

Y entre dos vivas gana la más reciente porque una segunda fila sólo aparece si **alguien
volvió a matricular**: el acto posterior sustituye al anterior. El `id DESC` no es
decoración — `matriculas.created_at` es *nullable*, y sin él dos filas sin fecha
volverían a quedar en manos del orden físico, que es el fallo del que va todo esto.

En `app/Models/Matricula.php`, en **un solo sitio**:

```php
public const FILTRO_DEL_ANIO = 'm.deleted_at IS NULL AND g.deleted_at IS NULL';
public const ORDEN_DEL_ANIO  = 'm.created_at DESC, m.id DESC';
public static function laDelAnio(int $alumno_id, int $year_id): ?object
```

Dos constantes y no una porque en SQL crudo los dos trozos caen en cláusulas distintas;
**son una sola regla**, y quien use una sin la otra se queda con la mitad. `laDelAnio()`
es para quien sólo necesita la fila —el escritor—; quien ya tiene su propio `JOIN`
grande —la ficha— pega las constantes a su consulta. Las dos formas contestan lo mismo
**porque citan la misma regla**, que es el punto entero de la §9.5.

### La población, medida antes de decidir

En la copia de `simonbolivar` el 1 sep 2026, sobre **3.579 matrículas**:

| | |
|---|---|
| pares (alumno, año) con matrícula viva | **3.578** |
| de ellos, con **dos o más vivas** | **1** — el alumno 1097 en el año 7, grupos 81 y 82 |
| matrículas borradas en toda la tabla | **0** |
| matrículas vivas colgando de un grupo borrado | **0** |

O sea que **los dos filtros que le faltaban al escritor hoy no cambian nada en este
colegio**: son latentes, no activos. Se ponen porque la promesa tiene que ser la misma
en los dos lados, no porque se estén disparando. **Lo alcanzable hoy es el caso de las
dos vivas**, y ahí las dos filas tienen `promovido` y `nro_folio` **distintos** — que es
el fallo entero, en datos reales.

**Un colegio, no quince.** Lo que se midió es la copia que hay delante.

### El rojo, y las tres formas malas dicen cosas distintas

| Forma | Rojos | Qué enseña |
|---|---|---|
| **el escritor de antes** (su consulta propia) | **4 de 5** | el verde es `con_una_sola_matricula`: el caso de todos los días **nunca estuvo roto**. Y `sin_matricula_del_anio` cae con `200 is identical to 400` — con la única matrícula en la papelera, el escritor viejo **la encontraba igual y devolvía «Guardado»**: escribía en la basura y la ficha seguía enseñando lo de antes |
| **la regla sin el `ORDER BY`** (sólo los filtros) | **2 de 5** | exactamente los dos casos que dependen del desempate |
| **la regla sin el filtro de borradas** (sólo el orden) | **1 de 5** | y esto es lo que hay que leer: con `created_at DESC` la fila viva **gana igual** a una borrada más antigua, así que el filtro sólo muerde cuando la **única** matrícula está borrada. **Las dos mitades cubren casos distintos y ninguna sobra** |

### El lector, y la decisión de Joseth

**Decidida el 1 sep 2026: unificar en «la más reciente», lector incluido.** Se le
planteó con la población delante —**1 de 3.578**— y con el argumento de que hoy **no hay
una decisión detrás**: sale la de id más bajo por orden físico, y dejar dos criterios de
orden distintos es volver a tener dos reglas, sólo que ahora escritas. **Queda cerrada.**

Lo que cambia para alguien de verdad, y va escrito aquí y en el commit porque es **el
efecto visible del arreglo**: **la ficha del alumno 1097 pasa a enseñar el otro par de
valores** de `promovido` y `nro_folio`. Es el único caso en esta copia.

En `putShow` desaparece `order by a.apellidos, a.nombres` —que para **un** alumno era un
empate total— y `g.deleted_at` sube del `JOIN` al `WHERE` para que la regla entera quepa
en `FILTRO_DEL_ANIO` y se lea de un tirón; con un `INNER JOIN` las dos formas seleccionan
lo mismo.

**Y lo que NO se tocó, avisado por el lote D:** `bol_independiente_periodos` no se cuelga
de la matrícula elegida. Sale del año del token y de `bol_ind_periodos`, y por eso viaja
**también** por la rama del alumno sin matrícula del año. Colgarlo de aquí devolvería a
`undefined` sus dos significados —«no matriculado» y «desmarcado»—.
`BolIndependienteEnLaFichaTest` sigue en **9 passed (366 assertions)**, incluido
`el campo viene tambien sin matricula del anio`.

### El rojo del lector

Contra `putShow` tal y como estaba: **2 de 7 rojos**, y son exactamente los dos casos del
lector; los cinco del escritor siguen verdes, que es la comprobación de que no dependen
de él.

Los dos casos del lector **apuntan a la regla y no a un id**: comparan contra
`Matricula::laDelAnio()`, así que si algún día la decisión cambia, **sólo cambia
`ORDEN_DEL_ANIO`**. Y el que cierra el asunto —`lo que se guarda es lo que la ficha
enseña`— **no nombra ninguna fila**: le da igual cuál gane, sólo exige que gane la misma
en los dos lados. Es el *viaje de ida y vuelta* que este repo ya tiene escrito como
criterio: mirando sólo la escritura, el guardado responde «Guardado»; mirando sólo la
lectura, la ficha devuelve un `repitente` creíble. **El fallo sólo existe entre las dos.**

### Un detalle de test que costó un rojo falso

`PUT alumnos/guardar-valor` devuelve **la cadena suelta `Guardado`**, que Laravel manda
como texto plano. `->json()` revienta ahí con *«Invalid JSON was returned from the
route»* — un rojo del test y no del código. En la rama del 400 sí hay JSON. Está escrito
al lado del helper.
