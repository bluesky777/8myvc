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
