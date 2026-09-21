# Lo que todavía no se ha calificado

> **El problema que trajo Joseth el 19 sep 2026, en sus palabras:** *«Muchas veces se quiere
> imprimir el semáforo pero hay indicadores que aún no se han calificado porque son de fechas
> futuras. Lo mismo los acudientes al entrar y ver definitivas perdidas pero todas las notas que
> se han pasado están en SUPERIOR.»*
>
> **Son el mismo fallo contado dos veces**, y esto es lo que se midió antes de proponer nada.
> Todavía **no hay ninguna decisión tomada**: las que hay que tomar están en la §5.

Medido el **19 sep 2026 a las 20:08** contra la base `simonbolivar` del docker
(`8myvc-database-1`), en el **árbol principal sobre `main`** en `ffd7b52`. **Es un colegio, el de
la copia de desarrollo, no los dieciséis.**

---

> # ⛔ INCIDENTE DEL 21 SEP 2026 — EL RELLENO DE LA FASE 0 BORRÓ NOTAS DE VERDAD EN CATORCE COLEGIOS
>
> **Esto va antes que todo lo de abajo, porque lo de abajo es lo que lo dejó pasar.** La tanda se
> desplegó el **20 sep 2026 a las 23:51 -0400** en los dieciséis de `micolev1` y a las **23:59** en
> `micolevi` —`e7ed5e75`, `git pull` + `migrate --force`—. A la mañana siguiente **CADS-Itagüí**
> reportó *«se perdieron notas»*. **Era cierto**, y no sólo allí.
>
> ## Lo que se borró, contado base por base
>
> Censo del 21 sep 2026 por la mañana, con el predicado exacto del `UPDATE` de la migración
> (`nota IS NULL AND updated_by IS NULL AND created_at <=> updated_at`). La columna de la derecha
> son las filas cuya subunidad tiene **`nota_default <> 0`**, o sea las que **no** valían cero:
>
> | base | vaciadas | con `nota_default <> 0` |
> |---|---:|---:|
> | `coljordan` | 79.223 | 20.848 |
> | `arauca_maranatha` | 71.143 | 10.695 |
> | `cads_itagui` | 47.832 | **45.952** |
> | `bethel_arauquita` | 39.694 | **37.216** |
> | `fortul_adventista` | 26.512 | 17.455 |
> | `comad_san_andres` | 25.888 | 13.372 |
> | `lal` | 24.392 | 17.166 |
> | `micolevi_lalvirtual` | 19.390 | 17.128 |
> | `la_hermosa` | 18.150 | 16.077 |
> | `caz_zaragoza` | 12.603 | 7.091 |
> | `amiguitosdejesus` | 10.115 | 8.970 |
> | `demo` | 9.843 | 7.073 |
> | `quibdo` | 6.970 | 781 |
> | `simonbolivar_medellin` | 5.807 | 682 |
> | `coab_saravena` | 5.440 | 3.640 |
> | `colbosque_tame` | 3.340 | 973 |
> | `semillitas` | 1.567 | 128 |
> | `coal_bucara` | 0 | 0 |
> | **total** | **407.909** | **225.247** |
>
> `coal_bucara` sale en cero porque **ya no usa el sistema** (Joseth, 21 sep): no es una migración
> sin correr. `edilson_feryz` no tiene tabla `notas`.
>
> **En Itagüí el 96 % de lo vaciado llevaba un valor**, y en `bethel` el 94 %. En la copia de
> desarrollo de este documento era el **6 %** (1.299 de 20.755). Ésa es toda la distancia entre
> «inocuo» y «planillas enteras en blanco el día de los boletines».
>
> ## Por qué falló, que no es donde parece
>
> **El proxy es correcto.** `updated_by IS NULL AND created_at <=> updated_at` dice exactamente lo
> que promete: *nadie ha tocado esta fila desde que se sembró*. Lo que era falso es la **conclusión**
> que se le colgó encima: *«una casilla que nadie tocó no vale nada»*.
>
> En Itagüí, `coljordan`, `bethel` y `lal` **la forma de calificar es sembrar el techo y bajarle al
> que pierde**. La subunidad nace con `nota_default` = 50 o 100 —el máximo de la escala de ese
> colegio— y el docente **sólo teclea las excepciones**. Una casilla sin tocar no es una casilla sin
> calificar: es un alumno que lo hizo todo bien. El `UPDATE` no mira el valor, así que se llevó por
> delante las dos poblaciones a la vez.
>
> ### Y la §3.bis a) de este documento es donde se decidió no verlo
>
> Allí se descartó la regla *«si `nota_default > 0` es que el docente ya decidió»* con este
> argumento, que sigue escrito más abajo:
>
> > *«Un `nota_default` de 100 en una escala de 0 a 50 no es una decisión del docente: es basura que
> > nadie validó.»*
>
> **Es verdad en `simonbolivar`, cuya escala va de 0 a 50 en los nueve años. Y es falso en un
> colegio cuya escala llega a 100**, donde ese mismo 100 es el método de trabajo. El mismo número,
> la misma columna, y el significado contrario según el colegio — y la frase se escribió mirando
> una sola base.
>
> **La comprobación que habría bastado, y que no se hizo:** cruzar `subunidades.nota_default` con el
> techo de `escalas_de_valoracion` **de ese colegio y ese año**. Cuando el defecto **es** el máximo
> de la escala, no es basura: es la decisión del docente sembrada de antemano.
>
> ## Lo irreversible que no lo era, y cómo se recuperó
>
> El `down()` de la migración dice que el valor anterior *«se perdió al vaciarla»*. **No se había
> perdido**: la fila nace con `subunidades.nota_default` y el propio proxy garantiza que nadie la
> cambió desde entonces, así que **el defecto de la subunidad ES el valor que tenía** — salvo que el
> defecto se editara después, que se puede editar (`SubunidadesController:41`,
> `PlantillaNotasController:786`).
>
> La recuperación fue en dos pasadas, y la segunda es la que cubre ese salvo:
>
> 1. `UPDATE notas n JOIN subunidades s … SET n.nota = s.nota_default` sobre el mismo predicado, más
>    `created_at <` el momento del despliegue —para no rellenar las casillas nacidas ya vacías
>    después—. En Itagüí: **47.832 filas, el mismo número que el censo**.
> 2. Donde **todas** las casillas de un alumno en una asignatura estaban intactas, la **definitiva
>    guardada antes del despliegue** es, por aritmética, el valor de esas casillas. Cuando contradecía
>    al defecto, ganó ella. En `coljordan` fueron **2.196 casillas de 6.519 comprobables — el 34 %**,
>    porque allí el 75 % de las subunidades se había editado después de sembrar.
> 3. `DefinitivasDeAsignatura::recalcular` sobre los pares `(asignatura, periodo)` cuya definitiva se
>    hubiera reescrito después del despliegue: en Itagüí **4.052** filas de `notas_finales` que ya se
>    habían recalculado con las casillas vacías —4.000 de ellas la misma mañana, mientras los
>    docentes trabajaban—.
>
> **El testigo de la verificación fue `notas_finales`**, y por poco: cada guardado de un docente
> dispara un recálculo que lo sobreescribe. La prueba que cerró el diagnóstico fue que **3.221 de
> 3.234 alumnos** con todas las casillas vaciadas tenían definitiva guardada **exactamente igual al
> defecto** (los trece restantes, `manual` o `recuperada`).
>
> ## Lo que NO volvió
>
> - **950 casillas** cuya subunidad tiene `nota_default` en NULL —584 en `quibdo`, 144 en
>   `caz_zaragoza`, 114 en `la_hermosa`, 107 en `coab_saravena`, 1 en `bethel`—: nacieron con un
>   valor que alguien puso después en NULL, y no hay de dónde sacarlo.
> - Las que un **cierre** hubiera pasado a 0 entre el despliegue y el arreglo:
>   `CierreDeLoNoCalificado::pasarACero` escribe `updated_by`, y eso las saca del predicado **para
>   siempre**. No se detectó ninguna, pero nadie puede demostrar que no la hubiera.
> - **Ninguna copia de seguridad previa al despliegue existía.** La del 21 sep a las 09:52 se hizo
>   con el estropicio ya dentro; sirvió de red para el arreglo, no para deshacerlo.
>
> ## La decisión que sale de aquí — 21 sep 2026
>
> **`nota_default` deja de poder escribirse al crear una subunidad** (Joseth, 21 sep): el campo sale
> del formulario de alta de la aplicación vieja, `app/scripts/unidades/unidades.html`. Con las tres
> siembras ya en `NULL`, ese campo no alimentaba nada — sólo dejaba a mano el valor que hoy ha hecho
> ambigua la recuperación. El sustituto ya existe y está escrito en la §3.bis a): **la nota rápida**,
> que escribe notas de verdad, con autor y fecha.
>
> **Y eso cambia el método de trabajo de los colegios que sembraban el techo.** A partir del próximo
> indicador, en Itagüí o `coljordan` la casilla nace vacía y hay que poner el 50 con la nota rápida.
> No es un efecto secundario: es la consecuencia buscada, y **hay que avisarles**.
>
> **Sigue abierto:** el formulario de **editar** subunidad todavía lleva el campo, y además
> `required` (`unidades.html:118`). Es el que permite cambiar el defecto **después** de sembrar, que
> es exactamente lo que en `coljordan` obligó a la segunda pasada.

---

## 1 · La causa: un cero que nadie puso y un cero del docente valen lo mismo

`notas.nota` es `int NOT NULL DEFAULT 0`, y la fila **nace con la subunidad**:
`NotasController::putSubunidad` inserta una nota por alumno en cuanto se crea el indicador, con
`nota_default`. Desde ese instante el indicador **pesa en la definitiva**, calificado o no:

```sql
-- NotaFinal::calcularAsignaturaPeriodo, y las diez copias de RepartoDeLaNota::aportacionALaDefinitiva
sum( (u.porcentaje/100) * ((s.porcentaje/100) * n.nota) )
```

No hay denominador: **la suma de aportes no se normaliza** —decisión escrita en
`DefinitivasDeAsignatura`, regla 2—. O sea que a mitad de periodo la definitiva no es *«cómo va»*:
es *«cuánto lleva ganado del periodo entero»*, y eso, pintado con la escala del colegio, dice
**BAJO** de casi todo el mundo.

### Y la base ya distingue las dos cosas, sólo que no lo mira nadie

| | filas | |
|---|---:|---|
| notas vivas | **1.166.608** | |
| nunca las tocó nadie (`updated_by IS NULL AND created_at <=> updated_at`) | **120.532** | **10,3 %** |
| de ésas, valen 0 | 98.461 | el agujero |
| de ésas, valen más de 0 | 22.071 | sembradas con `nota_default > 0` — **regalan nota** |
| ceros que **sí** tecleó un docente | **3.940** | el 3,8 % de los 102.401 ceros |

```sql
SELECT (n.nota=0) es_cero, (n.updated_by IS NULL) sin_updated_by,
       (n.created_at<=>n.updated_at) sin_tocar, COUNT(*)
  FROM notas n WHERE n.deleted_at IS NULL GROUP BY 1,2,3;
```

**El proxy no envejece y no nació ayer**: comprobado año por año, el porcentaje de notas sin
`updated_by` va del 5,3 % al 10,9 % en los siete años completos, 45 % en 2025 (a medio calificar) y
90 % en 2026 (recién sembrado). **No hay ningún año en que la columna falte del todo**, que es lo
que habría roto el relleno de la fase 0.

---

## 2 · El tamaño del daño, en el escenario exacto que describe Joseth

Periodo 2 de 2025, **a medio calificar — 6,5 % del plan evaluado de media**:

| | |
|---:|---|
| 2.553 | pares alumno–asignatura del periodo |
| 1.786 | sin una sola nota puesta |
| **767** | con alguna nota puesta |
| **767** | **salen en rojo** — o sea, *todos* |
| **539** | **no están perdidos** si se cuenta sólo lo evaluado (el 70 %) |
| **258** | **van en SUPERIOR** (≥ 46 sobre 50) contando sólo lo evaluado (el 34 %) |

**Uno de cada tres rojos del semáforo es un alumno con todo lo calificado en la banda más alta.**
Sobre el año 2025 entero son **550 falsos perdidos**, de los cuales **260 en SUPERIOR**.

**El error inverso es despreciable y hay que decirlo igual**: 279 pares llevan notas sembradas con
`nota_default > 0` que nadie tocó, y **sólo en 1** cambian el veredicto. O sea que dejar de contar
lo no calificado **también quita regalos**, pero casi ninguno.

---

## 3 · «Fechas futuras» — la columna existe y está vacía

Esto es lo que cambia el plan respecto a lo que pedía la frase original:

```sql
SELECT COUNT(*), SUM(inicia_at IS NOT NULL), SUM(finaliza_at IS NOT NULL)
  FROM subunidades WHERE deleted_at IS NULL;    -- 36.705, 0, 0
SELECT COUNT(*), SUM(fecha IS NOT NULL) FROM unidades WHERE deleted_at IS NULL;   -- 18.762, 0
SELECT COUNT(*), SUM(fecha_inicio IS NOT NULL) FROM periodos WHERE deleted_at IS NULL;  -- 36, 12
```

`subunidades.inicia_at` y `finaliza_at` **existen desde siempre y no las escribe ninguna
pantalla**: es `profesores.tono` otra vez. Así que hoy **«es de fecha futura» no se puede
calcular**, y cualquier propuesta que dependa de fechas empieza por una campaña de captura en
dieciséis colegios.

**Lo que sí se puede calcular hoy, sin capturar nada, es «no lo ha calificado nadie»** — y para los
dos síntomas que duelen es exactamente el mismo aviso.

---

## 3.bis · Las tres preguntas de Joseth del 19 sep, contestadas midiendo

### a) «¿Necesitaríamos quitar la nota por defecto?» — **No. Y además no sirve como señal.**

`calificada_at` y `nota_default` son **ortogonales**: una dice *si alguien miró esta casilla*, la
otra *con qué valor nace*. La siembra no cambia ni una línea.

Lo que sí se cayó al medirlo es **la regla barata que estaba a mano**: *«si `nota_default > 0` es
que el docente ya decidió, así que la casilla nace calificada»*. Parece razonable y **es falsa**:

```sql
SELECT p.year_id, s.por_defecto, s.nota_default, COUNT(*) FROM subunidades s
  INNER JOIN unidades u ON u.id=s.unidad_id INNER JOIN periodos p ON p.id=u.periodo_id
 WHERE s.deleted_at IS NULL AND s.nota_default > 50 GROUP BY 1,2,3;
SELECT year_id, MIN(porc_inicial), MAX(porc_final) FROM escalas_de_valoracion
 WHERE deleted_at IS NULL GROUP BY year_id;     -- 0–50 en LOS NUEVE AÑOS
```

De las **4.417** subunidades vivas con `nota_default > 0`, **1.672 llevan un valor que la escala
del colegio no puede representar**: 1.118 con `100` y 552 con `60` en un colegio cuya escala va de
**0 a 50 en los nueve años**. Y **1.608 de las 1.653 que están en 2026 vienen de `por_defecto = 1`,
o sea de la plantilla del colegio**. La causa se lee en dos líneas:
`PlantillaNotasController` valida `nota_default` con `enteroNoNegativo` — **sin tope y sin mirar la
escala del año**.

> **Un `nota_default` de 100 en una escala de 0 a 50 no es una decisión del docente: es basura que
> nadie validó.** Si esa regla hubiera entrado, marcaría 1.653 casillas como «calificadas con 100»
> en el año en curso — y las marcaría como **verdad**, que es peor que el cero de hoy.

> **⛔ Y ESTE PÁRRAFO ES DONDE SE PERDIÓ EL 21 SEP.** Vale para `simonbolivar`, cuya escala va de 0 a
> 50. En un colegio cuya escala llega a 100, un `nota_default` de 100 **es** la decisión del docente:
> es su forma de calificar —sembrar el techo y bajarle al que pierde—, y el relleno de la fase 0 se
> la borró a catorce colegios. El incidente entero está arriba del todo.

Así que la regla es la simple: **una casilla sembrada y nunca tocada no cuenta, valga lo que
valga.** Y la forma de escribir eso la propuso Joseth el 19 sep, contra la que yo traía:

> **«Parece mejor que la nota aparezca vacía, que le valga `null` o `""` para indicar que la nota
> no debe contar, y se use la nota rápida si lo desea, al final hace lo mismo que la nota por
> defecto.»**

**Tiene razón, y por un motivo más fuerte del que dio.** Mi propuesta era una columna nueva
—`calificada_at`— dejando `nota` en 0. Eso funciona, pero deja la regla *«no cuentes las casillas
sin calificar»* **a cargo de que cada consulta se acuerde**, y hay 990 consultas crudas. La suya la
convierte en aritmética: `SUM(peso * NULL)` **ignora la fila sin que nadie lo pida**, y `NULL < 30`
tampoco cuenta como perdida. *Una consulta que nadie actualice deja de mentir sola, en vez de
seguir mintiendo en silencio* — que es la diferencia entre una norma y un mecanismo.

Y la nota rápida **ya existe y hace exactamente el trabajo**: `myvc_front/app/scripts/directives/
NotaRapida.ts` es el panel flotante donde se elige un valor y cada clic en una celda lo escribe.
O sea que el botón que yo proponía inventar **ya está construido desde hace años**, y escribe notas
de verdad, con autor y fecha. `nota_default` no hay que sustituirlo por nada: ya tiene sustituto.

#### Lo que costó comprobarlo, que es lo que decide

| | |
|---|---|
| `ALTER TABLE notas MODIFY nota int NULL, ALGORITHM=INPLACE, LOCK=NONE` | **8 s** sobre 1.166.608 filas, sin bloquear — **MySQL 8 del docker**. ~~Producción es MariaDB 10.5 y esto NO está medido ahí~~ **Medido el 20 sep 2026: `tools/ensayo-del-alter-en-maria.sh`, y el apartado de abajo.** |
| Agregados sobre `notas.nota` que cambiarían de significado (`AVG`, `MIN`, `MAX`) | **Cero.** El único `AVG(c.nota)` del proyecto (`PuestosController:226`) es de comportamiento, otra tabla. |
| `INSERT INTO notas(` que no nombren la columna | **Cero**: todos la pasan, así que ninguno empezaría a meter `NULL` por descuido. |
| **Los tres clientes** | **Ninguno se rompe, y dos ya estaban esperando esto.** |

#### Y medido contra MariaDB el 20 sep 2026 — **no bloquea el guardado de notas**

Era lo único que quedaba abierto de la Fase 0, y la pregunta **no eran los segundos**: el `ALTER`
reconstruye `notas` entera —123 MB de una base de 187, dos tercios— así que lo que decide es **si
MariaDB lo hace `INPLACE` o cae a `COPY`**. Con `COPY` la tabla queda de sólo lectura mientras dure
y un docente guardando notas a esa hora recibe un error.

Contra `mariadb:10.5` (10.5.29; producción corre **10.5.25**), con las cuatro tablas de
`simonbolivar` copiadas y **rebobinadas al estado de antes de la Fase 0** — comprobado: el proxy
vuelve a seleccionar exactamente las **20.655** filas.

| | |
|---|---|
| `ALGORITHM=INSTANT` · `ALGORITHM=NOCOPY` | **no soportados** — MariaDB contesta *«Try ALGORITHM=INPLACE»* |
| `ALGORITHM=INPLACE, LOCK=NONE` | **aceptado, 4,97 s** — es la comparación limpia con los **8 s** de MySQL 8, que se midieron con esa misma cláusula |
| `ALTER` tal como lo escribe la migración, sin cláusula | **6,96 s** |
| `UPDATE` del relleno | **0,49 s**, 20.655 filas, y el plan **no recorre `notas`**: ataca desde `periodos` (37 filas) y baja por los índices |

> **Los 6,96 s y los 4,97 s son la MISMA operación y la diferencia es ruido, no un hallazgo.** El
> mismo `INPLACE` cronometrado dentro de la prueba de concurrencia dio **5,8 s**, así que este banco
> tiene alrededor de un segundo de variación entre pasadas. Leer que «sin cláusula tarda dos
> segundos más» sería inventarse un efecto: **sin cláusula MariaDB elige exactamente `INPLACE`**, que
> es justo lo que dice la fila de arriba. Cada cifra es de **una sola pasada**; para un número que
> haya que defender, medianas.

**Y la cláusula no se creyó: se comprobó escribiendo de verdad desde otra conexión.** Dos pasadas,
la segunda corriendo el guion entero de cero:

```
                            pasada 1                     pasada 2
linea base, sin nada         212–515 ms                  135–180 ms
INPLACE / LOCK=NONE     14 escrituras, PEOR   355 ms   13 escrituras, PEOR 1.003 ms
CONTROL, COPY/LOCK=SHARED  1 escritura,      9.448 ms    1 escritura,      6.874 ms
```

> **El control es lo que hace que el verde signifique algo.** Sin él, «trece escrituras pasaron» no
> distingue *«no bloquea»* de *«mi bucle no llegó a correr»*. Con `COPY` la escritura esperó el
> `ALTER` **entero** —9,4 s y 6,9 s—: la sonda **sí sabe detectar un bloqueo**.
>
> **Y el pico de 1.003 ms de la segunda pasada se dice, aunque no cambie la conclusión.** «No
> bloquea» no significa «no se nota nunca»: un DDL en línea toma un **cerrojo de metadatos
> exclusivo, breve, al principio y al final**, y ahí es donde cabe ese segundo. La diferencia con el
> control sigue siendo de un orden de magnitud —un segundo contra siete—, y sobre todo **la
> escritura se completa**: nadie pierde una nota. Quien despliegue esto tiene que saber que **puede
> haber un tirón de ~1 s**, no que no se enterará nadie.
>
> **Los dos `ALTER` sin cláusula dieron 6,96 s y 4,78 s**, que es la variación de este banco entre
> pasadas. Ninguna cifra de aquí es una mediana: son una pasada cada una, y para un número que haya
> que defender hacen falta más.
>
> **Y la primera sonda estaba rota en la dirección alarmante**: contó **40 escrituras fallidas con
> las diez filas escritas**, porque el `if` miraba el código de salida de un `grep -vi warning`, que
> devuelve 1 cuando no selecciona nada. *El primer sitio donde mirar cuando el número sale raro es
> el detector.*

**Lo que esto NO mide, y hay que decirlo:** es un Mac con Docker Desktop y el `innodb_buffer_pool_size`
de fábrica (128 MB, con la tabla en 123 MB). **Producción es CloudLinux con límites de I/O por
cuenta**, así que los segundos de allí serán otros. Lo que viaja de aquí es **el algoritmo** —que es
lo que decidía—: al no bloquear escrituras, el reloj deja de gobernar la ventana de despliegue. Y es
**un colegio**, el de la copia de desarrollo, no los dieciséis.

**Y esa última fila es la que cierra la discusión.** No es que los clientes toleren el `null`: es
que **ya está escrito que la casilla vacía no cuenta**, en los dos clientes nuevos, y el backend es
el único que sigue mandando un cero:

```
myvc_flutter  LibroNotasApi.dart:311   final double? nota;
                                  :325   bool get puesta => nota != null;
     y su propio docblock:  «la fila existe desde que alguien abrió el libro […] LO QUE NO SE SABE
     ES SI EL 0 LO PUSO EL DOCENTE O ES EL VALOR DE FÁBRICA»

app2          promedio-ponderado.ts:67  if (nota.nota === null || … === '') { continue; }
                                        // «Una casilla sin calificar no suma.»

myvc_front    NotasCtrl.ts:1105         Number(nota.nota) * …     →  Number(null) === 0
     (la vieja: no se rompe, el término aporta 0, igual que hoy)
```

`puesta` en la app **es hoy siempre verdadera** porque el backend nunca manda `null`. El día que lo
mande, esa función empieza a decir la verdad **sin tocar una línea de la app**.

> **El que escribió ese docblock ya había diagnosticado este bug entero y no tenía cómo
> arreglarlo**, porque el arreglo no estaba de su lado. Mi propuesta habría dejado ese comentario
> siendo verdad para siempre y habría añadido una columna para rodearlo.

### b) «Nada hasta cerrar» contra el bloqueo que ya existe — **la corrección de Joseth es correcta**

El que existe es **`years.alumnos_can_see_notas`**, y es **por AÑO y todo o nada**: bloquea los
cuatro periodos de ese año, no el que esté abierto. Lo escriben tres sitios de `YearsController`
(127, 952, 1496) y **lo lee uno solo**: `NotasController::getAlumno:435`, que devuelve la cadena
`'Sistema bloqueado. No puedes ver las notas'` **con un 200**.

Con **D1 decidida** —la familia ve la parcial— **la opción «nada hasta cerrar» desaparece del
plan**, así que no hay dos interruptores solapados que mantener. Queda apuntado lo otro, que es de
otro papel: ese bloqueo **tapa un método y nada más**, así que el día que un colegio lo use de
verdad hay que censar por dónde más salen las notas de un alumno.

### c) «No tengo idea cómo calcularías la definitiva si los porcentajes no dan 100 %»

**Ésa es justo la pregunta, y la respuesta es que no hace falta que den 100: hace falta dividir por
lo que sí den.** Con `peso` y `aporte` por nota:

```
peso(n)    = (u.porcentaje/100) × (s.porcentaje/100)
aporte(n)  = peso(n) × n.nota

acumulada  = Σ aporte(n)                       sobre TODAS      ← la de hoy, NO se toca
parcial    = Σ aporte(n) ÷ Σ peso(n)           sólo CALIFICADAS ← la nueva
cobertura  = Σ peso(n) calificadas ÷ Σ peso(n) todas
```

Con la planilla del lienzo —unidad 1 vale 70 % del periodo; dentro, Taller 30 % y Quiz 20 %
calificados, Exposición 25 % y Evaluación 25 % sin calificar— y Sara con 48 y 47:

```
parcial   = (0,30×48 + 0,20×47) ÷ (0,30 + 0,20) = 23,8 ÷ 0,50 = 47,6   → SUPERIOR
acumulada = 0,70 × 23,8                                        = 16,7   → BAJO
cobertura = 0,70 × 0,50                                        = 35 %
```

**Los dos números son correctos y dicen cosas distintas.** El 16,7 no está mal calculado: está
contestando *«¿cuánto del periodo entero lleva ganado?»*, que a mitad de periodo no es la pregunta
de nadie.

Cuatro consecuencias que hay que tener escritas antes de implementarlo —**cinco desde el 20 sep
2026, y una de las cuatro resultó ser falsa**: las dos correcciones salieron de implementarlo, no
de releerlo—:

1. **`Σ peso` es la cifra que hoy está implícita y nunca se escribió.** La definitiva de hoy es
   `Σ aporte` **dando por hecho que el divisor es 1**. Por eso una asignatura mal repartida da una
   nota rara en vez de un aviso.
2. **Y eso convierte un fallo conocido en un número.** `DefinitivasDeAsignatura` (regla 2) decide a
   propósito **no normalizar**, porque *«que una asignatura mal configurada dé una definitiva rara
   es la intención — es lo que la delata en la planilla»*. Eso **no cambia**: la que normaliza es
   la parcial, no la definitiva. ~~Lo que se gana es que una asignatura cuyas unidades suman 120
   termine de calificarse y salga con **cobertura 120 %**, que delata muchísimo mejor que una nota
   alta — un número que sólo puede significar una cosa.~~

   > **FALSO, y se tacha en vez de borrarse — visto el 20 sep 2026 construyendo la fase 1.** Con la
   > fórmula de tres líneas más arriba, **el mismo `Σ peso` está en el numerador y en el
   > denominador**, así que la cobertura vive en `[0, 1]` y **no puede pasar del 100 % jamás**.
   > Medido contra `simonbolivar` en periodos abiertos: **0 de 9.422 pares por encima del 100 %,
   > con 328 asignaturas mal repartidas dentro de la muestra** —106 con `Σ peso > 1`, hasta
   > **2,54**, y 222 por debajo, hasta 0,04—. O sea que la muestra tenía de sobra con qué
   > delatarlas y la cobertura no delató ninguna, porque no es lo que hace.
   >
   > **Y no se rescata metiendo un 1 en el divisor**, que es lo que haría falta para que esta
   > frase fuera cierta: con eso la cobertura mezclaría dos señales —cuánto se ha evaluado y si el
   > reparto está mal— y una asignatura **bien calificada** cuyas unidades sumen 80 diría «80 %
   > evaluado» para siempre, con el docente buscando notas que no faltan. **El delator del reparto
   > malo ya existe y ya viaja en la misma respuesta**: `porcentaje_unidades`, que `recalcular()`
   > devuelve desde siempre. Esta consecuencia pedía un delator que ya estaba puesto.
   >
   > Queda atado por `LaParcialYLaCoberturaTest::test_una_asignatura_mal_repartida_no_pasa_del_cien_por_cien`,
   > que lo fija por los dos extremos —a medias y calificada entera— precisamente para que nadie
   > venga a «arreglar» este renglón.
3. **Peso cero no mueve nada, y hoy tampoco.** **2.242 de 36.705** subunidades (6,1 %) tienen
   `porcentaje = 0`; ninguna unidad lo tiene. Calificarlas no cambia la parcial ni la cobertura, y
   la pantalla **tiene que decirlo**: una casilla de peso 0 calificada no puede pintarse como
   «evaluado», o el docente creerá que avanzó.
4. **`Σ peso` calificado = 0 → la parcial es `NULL`, nunca 0.** Ése es exactamente el gris del
   semáforo, y es la diferencia entre *«va en cero»* y *«no hay con qué decirlo»*.

5. **Y hay un SEGUNDO cero de división que esta lista no vio: `Σ peso` TOTAL = 0**, donde el que
   se queda sin respuesta es **la cobertura**. *(Añadido el 20 sep 2026 construyendo la fase 1.)*
   No es un rincón: son **3.158 de los 9.422 pares que `calcular()` devuelve de verdad en periodos
   abiertos — el 33,5 %**, de los que **3.059 no tienen ni una fila en `notas`** —porque
   `calcular()` parte de `matriculas` y no de `notas`, que es su regla 1— y **99** tienen todas sus
   casillas a peso 0. Ahí la cobertura es **`NULL`** por el mismo motivo que la parcial: un 0
   afirmaría que se conoce el plan y que no se ha tocado, y lo cierto es que **no hay plan del que
   hablar**. Los dos ceros existen, los dos dan `NULL` y **no son el mismo hecho** — el punto 4 es
   «el plan está y no se ha evaluado nada», éste es «no hay plan».

> **Ni la parcial ni la cobertura se guardan.** Se calculan y se sirven sin recortar, como manda la
> regla de Joseth del 14 sep: *se redondea en un solo sitio, el que escribe la definitiva*.
> Guardarlas sería una segunda verdad que mantener sincronizada con la primera, y la §1 del
> [10](10-definitivas.md) es la lista de lo que pasa cuando hay seis escritores de un mismo número.

> **Y el `NE` por celda (D4) sale del divisor además de la suma**, que es precisamente por qué
> tiene que ser por celda y no por subunidad: *«a Isabela no se le evaluó la exposición»* es un
> hecho de Isabela, y le cambia **su** denominador, no el de sus treinta compañeros.

---

## 4 · Cómo lo resuelven los demás

| | |
|---|---|
| **Canvas** | De serie, **lo no calificado no entra en el total**; hay un interruptor *«Treat ungraded as 0»* para el cierre, y el alumno ve *«calculated based only on graded assignments»*. |
| **Moodle** | *«Exclude empty grades»* por categoría, **activado de serie**. Y su propio aviso es el que importa aquí: **Moodle no puede distinguir «no entregado» de «aún no toca»**, así que obliga al docente a teclear el 0. |
| **PowerSchool / PowerTeacher Pro** | **Estado por celda** —*missing, late, incomplete, exempt, absent*— más fecha de entrega. *Exempt* saca la casilla del denominador de ese alumno. |
| **Colombia (SIEE / Red Académica)** | El **informe parcial de corte** (semana 6, «entre el 40 % y el 50 % del periodo») es **un documento distinto del boletín** y se rotula como tal. Y existe el estado **NE, «no evaluado»**, para el alumno que no pudo serlo. |

**Las cuatro coinciden en lo mismo**: no contar lo que no se ha evaluado, **hacer explícita** la
diferencia en vez de deducirla, y **rotular como parcial** lo que es parcial. El semáforo ya es un
informe de corte; lo único que le falta es decirlo y calcular como tal.

---

## 5 · Las decisiones

**Tres decididas por Joseth el 19 sep 2026**, con las tres poblaciones delante. Dos siguen abiertas.

| | | |
|---|---|---|
| **D1** | Qué ve la familia con el periodo abierto | ✅ **La parcial, sobre lo evaluado.** Y con eso **el interruptor de D1 deja de existir**: una sola forma para los dieciséis. Menos columna, menos ruta y un camino que mantener en vez de tres. |
| **D3** | Qué pasa al cerrar con lo no calificado | ✅ **Interruptor por colegio: lo elige cada rector.** Las tres salidas —cero, fuera de la cuenta, no dejar cerrar— viven en una columna de `years`. **Escrita el 20 sep 2026 (fase 4), y en DOS columnas**: la elección en `years` y lo aplicado congelado en `periodos`, porque el cálculo no puede leer una elección que se puede cambiar. §Fase 4. |
| **D6** | Cómo se escribe «sin calificar» | ✅ **`notas.nota` anulable, `NULL` = sin calificar.** Propuesta de Joseth contra la columna `calificada_at` que traía yo. Cambia la fase 0 entera. |
| **D7** | Si «quitar la nota» es `update` con `null` o `destroy` | ✅ **`update` con `nota: null`.** Conserva la fila, su `id`, su bitácora y su historial. `destroy` se queda para lo que de verdad es borrar la fila. |
| **D5** | Si se capturan las fechas de los indicadores | ✅ **No, fuera de alcance.** Las fases 0–4 arreglan los dos síntomas sin pedirle un dato nuevo a nadie. |
| **D2** | El cuarto color gris y desde qué % se apaga | ✅ **Gris con el 15 % evaluado o menos** — `cobertura <= 0.15`. No es el mínimo posible —el mínimo sería «sólo con 0 %»— y la razón es el papel: esto **se firma y se archiva**, así que una o dos notas sueltas no bastan para ponerle color a una asignatura delante de una familia. **El borde lo decidió Joseth el 20 sep 2026 con los 152 pares delante** (abajo). |
| **D4** | Estado **NE** por celda (el alumno que no pudo ser evaluado) | ✅ **No por ahora.** Con D3 en «queda fuera», una casilla vacía ya hace lo que haría NE; sólo haría falta si el colegio eligiera «pasa a 0» y quisiera excepciones. **Vuelve a la mesa el día que un colegio elija eso.** |

> ### El valor de fábrica de D3 es «pasa a cero», y eso no es lo mismo que la decisión
>
> Un interruptor nuevo nace con un valor en los **dieciséis colegios a la vez**, y ese valor no lo
> ha elegido ningún rector: lo elige quien escribe la migración. Como hoy el cierre pone cero, el
> defecto **tiene que ser cero** — si naciera en «fuera de la cuenta», desplegar cambiaría el
> cierre de los dieciséis sin que nadie lo hubiera pedido, y las definitivas de un periodo ya
> cerrado se moverían solas.
>
> Es literalmente el argumento de `RepartoDeLaNota::modoDelAnio`: *«el defecto no es prudencia
> genérica: `porcentaje` es el comportamiento de hoy»*. **Elegir la opción recomendada como valor
> de fábrica habría sido tomar la decisión de los dieciséis rectores desde una migración.**

> ### Y D1 decidida **quita** trabajo, que es lo contrario de lo que suele pasar
>
> El plan traía «1 columna en `years` + 1 ruta de ajuste» para que cada colegio eligiera qué ve la
> familia. Decidido que es la parcial para todos, eso **no se construye**: la fase 3 pasa a ser
> sólo los dos clientes. Se apunta porque el alcance que se queda corto respecto a lo planeado
> **se cuenta y se dice**, igual que las cinco rutas de `informes-recientes` que iban a ser seis.

---

## 6 · El plan por fases

**Ninguna fase cambia una nota ya guardada, y la definitiva de hoy no se toca en ninguna**: es la
que cierra el periodo y la que imprimen los boletines de los dieciséis.

> **Esa frase vale para las fases 0 a 3, y la 4 la matiza — se escribe aquí y no sólo abajo porque
> ésta es la que se lee primero.** *(20 sep 2026, construyendo la fase 4.)* Con el valor de fábrica
> —`cero`— sigue siendo cierta byte por byte en los dieciséis colegios. Pero la salida `fuera` de
> **D3 mueve la definitiva a propósito**, en el periodo que se cierra y sólo para el colegio que la
> elija: y **tiene que moverla**, porque la definitiva no normaliza y `SUM(peso × NULL)` vale lo
> mismo que `SUM(peso × 0)` — sin normalizar, «pasa a cero» y «queda fuera de la cuenta»
> imprimirían el mismo boletín y D3 sería un adorno. *Lo que no se toca sin que nadie lo pida no es
> lo mismo que lo que no se toca nunca.*

### Fase 0 — la casilla vacía

**`notas.nota` pasa a anulable y una casilla sin calificar vale `NULL`.** No hay columna nueva y no
hay proxy que mantener: *no hay nota* deja de ser un valor y pasa a ser la ausencia de valor, que
es lo que siempre fue.

**El relleno va acotado a los periodos abiertos, y ahí está casi toda la seguridad de esta fase:**

```sql
UPDATE notas n
  JOIN subunidades s ON s.id = n.subunidad_id
  JOIN unidades    u ON u.id = s.unidad_id
  JOIN periodos    p ON p.id = u.periodo_id
   SET n.nota = NULL
 WHERE n.deleted_at IS NULL
   AND p.profes_pueden_editar_notas = 1          -- el periodo sigue abierto
   AND n.updated_by IS NULL AND n.created_at <=> n.updated_at;
```

> **⛔ Este `UPDATE` borró notas de verdad el 21 sep 2026 en catorce colegios: no filtra por valor y
> se lleva la fila entera valga lo que valga.** Las cifras, el porqué y cómo se recuperó, arriba del
> todo.

| | filas |
|---|---:|
| notas vivas | 1.166.608 |
| en periodos **cerrados** — no se tocan | 1.053.592 |
| en periodos **abiertos** | 49.884 |
| **las que pasan a `NULL`** | **20.655 — el 1,8 % de la tabla** |

**Un periodo cerrado no cambia de nota por un despliegue**, y no porque nos acordemos: porque el
`UPDATE` no lo alcanza. En los cerrados hay 46.482 filas que cumplen el proxy y **se quedan en 0**
a propósito — sus definitivas están impresas y firmadas. `profes_pueden_editar_notas` ya es la
marca de «cerrado» y es lo que el colegio apaga; **no hay que inventar ninguna columna de estado**.

> **El precio de esta forma, dicho entero: el radio es todo de golpe.** Con una columna nueva, nada
> cambia hasta que una consulta opta por mirarla. Con `NULL`, las **990** consultas crudas cambian
> de resultado a la vez, en los dieciséis colegios, el día del despliegue. Por eso el relleno se
> acota a lo abierto —20.655 filas— y por eso esta fase **deja de ser invisible**: es la única del
> plan que mueve un número que alguien puede estar mirando.

#### Borrar el contenido de una casilla la devuelve a vacía — y eso NO es gratis

Pregunta de Joseth: *«cuando se edita la nota borrando su contenido, se vuelve a guardar un `null`,
¿cierto?»*. **Sí, y hace falta**: sin marcha atrás, quien teclea un 12 en la fila equivocada no
tiene forma de quitarlo —no hay ningún número que signifique «aquí no hay nota», y un 0 es perder—.

Dos cosas ya están resueltas y una no:

**Resuelto 1 · el `""` se convierte solo.** `ConvertEmptyStringsToNull` está en el kernel global
(`app/Http/Kernel.php:23`), así que la cadena vacía del input llega al controlador como `null`. No
hay que elegir entre las dos formas.

**Resuelto 2 · la validación de escala ya lo tolera.** `EscalaDeNotas::motivoSiNoCabeEnAnio` abre
con `! is_numeric($valor) → return null`, y `is_numeric(null)` es `false`. Un borrado **no** se
rechaza por no caber en la escala. Cero cambios ahí.

**Sin resolver, y es la trampa cara: hoy el `NOT NULL` está haciendo de guarda por accidente.**

```php
// NotasController::putUpdate:628
$bit_new = Request::input('nota');          // null tanto si vino vacía como si NO VINO
DB::update('UPDATE notas SET nota=?, …', [$bit_new, …]);
```

`Request::input()` **no distingue «vino vacía» de «no vino»**. Hoy da igual, y da igual *por
accidente*: contra una columna `NOT NULL`, un cuerpo sin `nota` **aborta en producción** (MariaDB
estricto, `1048`) y se traga un 0 en el docker — el caso de
[Docker no es estricto, producción sí](29-los-env-no-son-uniformes.md). **El día que la columna sea
anulable ese error desaparece y se convierte en un borrado silencioso**: cualquier cliente que
mande el cuerpo incompleto borra la nota y nadie se entera.

> *Quitar un `NOT NULL` no es sólo permitir un valor más: es retirar la última validación de los
> que no validan.* Va en el **mismo commit** que la migración, y con test:
>
> ```php
> if (! Request::has('nota')) { abort(422, 'Falta la nota. Para borrarla, mándala vacía.'); }
> ```

**`putLote` ya está bien de forma y sólo le falta una rama.** Distingue con
`array_key_exists('nota', $pedida)` (línea 44) y hoy rechaza el vacío por ítem —*«La nota no es un
número»*, línea 54—. Basta con que el `null` explícito deje de ser un fallo y pase a ser un
borrado. **No lo llama ningún cliente todavía**, así que se puede arreglar sin coordinar nada.

**Y queda una decisión de forma (D7): hay dos caminos que acaban en lo mismo.**
`DELETE notas/destroy/{id}` **borra la fila físicamente** —no deja `deleted_at`— y el siguiente
`notas/detailed` la vuelve a crear con la nota por defecto; o sea que hoy *borrar* ya deja la
casilla recién nacida. Con la casilla naciendo vacía, **`destroy` y `update` con `null` producen
la misma pantalla**. Recomendación: **`update` con `nota: null` es EL camino de «quitar la nota»**
—conserva la fila, su `id`, su bitácora y su historial— y `destroy` se queda para lo que de verdad
es borrar la fila. Dos mecanismos para un significado es exactamente lo que esta base ya tiene de
sobra en `notas_finales`.

> La bitácora escribe `affected_element_new_value_int = NULL` en un borrado. Es correcto, pero
> **la pantalla que la lee no puede pintar «0»**: sería la misma mentira, mudada de tabla.

Mueve: 1 migración (esquema + relleno), **`putUpdate` y `putLote` en el mismo commit**, 0 rutas,
0 clientes. **Y la migración hay que medirla contra MariaDB 10.5**, no contra el docker: los 8 s
son de MySQL 8.

### Fase 1 — tres números donde hoy hay uno · **ESCRITA el 20 sep 2026** (`feat/la-parcial-y-la-cobertura`)

`DefinitivasDeAsignatura::calcular` devuelve además **nota parcial** (aportes evaluados ÷ peso
evaluado) y **cobertura** (peso evaluado ÷ peso total), y `recalcular` las pasa cuando se le pidió
un alumno. Aditivo: **0 rutas nuevas**, campos nuevos en la respuesta del servicio.

> **La cobertura se calcula sobre el peso, no sobre el número de casillas.** Un indicador del 40 %
> sin calificar y uno del 5 % no dejan el mismo hueco, y contarlos por unidades daría un porcentaje
> que no tiene que ver con lo que puede moverse la nota.

> **Este párrafo decía «mueve las instantáneas de contrato de boletines y planilla» y las dos
> mitades son falsas, cada una por su lado.** *(Medido el 20 sep 2026.)*
>
> **No mueve ninguna.** De las 129 instantáneas se movió **cero**, y ésa es justamente la prueba de
> que la definitiva no cambió ni un decimal. Los dos números viven en el servicio y **ningún
> controlador los sirve todavía**: `notas/update` toma sólo `definitiva`. La que se moverá el día
> que alguien los saque por ahí es **`notas-update.json`**, una.
>
> **Y la planilla y los boletines no pasan por `DefinitivasDeAsignatura`.** Pasan por
> **`App\Models\Asignatura::calculoAlumnoNotas`** (líneas 219–270), que es **un segundo calculador
> de la definitiva entero y paralelo, en PHP, sin denominador**, con **seis lectores**:
> `PlanillasController`, `DetallesController`, `EditnotaController`,
> `Informes\NotasPerdidasController`, `Informes\PlanillasAusenciasController` y
> `Nota::alumnoAsignaturas`. Es el que produce `nota_asignatura`.
>
> O sea que **la parcial y la cobertura no llegan a la planilla con la fase 1**, y eso no se
> arregla dentro de esta fase: o se cablea ese segundo calculador al servicio —que es mover el
> número que imprimen los dieciséis, o sea otra decisión— o la fase 2 se sirve de otro sitio.
> ~~**Queda abierto y es de Joseth.**~~
>
> **CERRADO el 20 sep 2026 por Joseth, con el coste delante, y por la tercera salida: ni se
> unifica ni se sirve de otro sitio — el segundo calculador aprende a dar los tres números.**
> Es la §Fase 1.bis de aquí abajo. Unificarlos **habría movido el número impreso** y está
> medido: 14 pares de 99 en el año que está en `promedio`, el peor 42,3 puntos.

#### Lo que cuesta, medido — y sólo cuesta en un modo

La §7 lo dejó como *«un argumento, no una medición»*. Medido el 20 sep 2026 sobre `simonbolivar`,
en la asignatura más cargada de la copia (986 notas, 45 alumnos), con los contadores `Handler_read`
en vez del reloj, que ahí es puro ruido —el `sello`, que no se toca, oscilaba entre 2,0 y 4,8 ms
entre pasadas—:

| | `Handler_read_key` | `Handler_read_next` | reloj |
|---|---:|---:|---:|
| modo `porcentaje`, antes y después | 1.024 · **1.024** | 1.665 · **1.665** | — |
| modo `promedio`, antes → después | 2.010 → **3.982** (×1,98) | 5.707 → **13.791** (×2,42) | 8,6 → **17,7 ms** |

**En `porcentaje` el coste es cero**, byte por byte: los dos `SUM` nuevos son aritmética sobre filas
que ya se recorrían. Son ocho de los nueve años de la copia y el defecto de los dieciséis.

**En `promedio` la consulta se dobla**, y la causa es concreta: el fragmento del peso arrastra la
subconsulta correlacionada de `RepartoDeLaNota::cuantasSubunidades`, que pasa de evaluarse **una vez
por fila a tres**. **Un año de la copia ya está en `promedio`**, así que no es hipotético. Bajar de
ahí exige sustituir la correlacionada por un agregado unido en el `FROM`, y eso **cambia el texto de
`aportacionALaDefinitiva`, que es contrato** —lo leen dos tests que cuentan agregados casando por
cadena—: se deja medido y no resuelto.

> **De los ms, fiarse de la razón y no del valor**: se midieron con **tres suites de otras
> sesiones corriendo en el mismo contenedor**, así que los absolutos están inflados. Los dos
> bloques se alternaron seis veces para que la carga les cayera igual a los dos, y la razón (×2)
> coincide con la de los contadores, que no dependen de la carga. **Ésa es la cifra.**

### Fase 1.bis — los tres números en el SEGUNDO calculador · **ESCRITA el 20 sep 2026** (`feat/la-parcial-en-la-planilla`)

`App\Models\Asignatura::calculoAlumnoNotas` devuelve además `nota_parcial` y `cobertura`, con
la misma semántica que el servicio. Sin esto, la fase 1 dejaba los dos números **calculados y
sin que los viera nadie**: la planilla no pasa por `DefinitivasDeAsignatura`.

**Aditivo: 0 rutas, 0 columnas, 0 migraciones.** Y **la acumulada no se mueve un decimal**, que
es lo que se comprueba en la misma línea que cada número nuevo.

#### Los seis lectores, censados — y el séptimo, que no estaba en la lista

| lector | ruta | ¿los publica? |
|---|---|---|
| `PlanillasController::getShowProfesor` | `GET planillas/show-profesor/{id}` | **sí**, por periodo |
| `Informes\NotasPerdidasController::getShowProfesor` | `GET notas-perdidas/show-profesor/{id}` | **sí**, por periodo |
| `Informes\PlanillasAusenciasController::getShowProfesor` | `GET planillas-ausencias/show-profesor/{id}` | **sí**, por periodo |
| `DetallesController::putGruposPeriodos` | `PUT detalles/grupos-periodos` | **sí**, sin código |
| `EditnotaController::allNotasAlumno` | `PUT editnota/detailed-notas/{grupo}` | **sí**, sin código |
| `Nota::alumnoAsignaturasPeriodosDetailed` | `GET boletines{,2,3}/detailed-notas-year/…` | **no** |

Los tres primeros son **el mismo método byte a byte** en tres controladores y por eso ganan los
tres: dejar dos iguales y uno distinto es un renglón que el siguiente no puede leer como
decisión. Los dos de en medio los ganan **sin una línea**, porque devuelven la asignatura
entera.

**El sexto no, y eso es lo que esta fase se queda corta respecto a su título**: ese método **no
publica ninguna definitiva por periodo** —promedia los cuatro y saca `nota_asignatura_year`—,
así que el único sitio donde cabrían sería *«la parcial del año»*, **que no está definida en
ninguna parte**, no es la media de las cuatro parciales —cada una tiene su denominador— y
mezclaría los periodos cerrados con el abierto, diluyendo justo la señal. *Quedarse corto se
cuenta y se dice.* O sea que **a los boletines no llega**: el boletín de un periodo
(`boletines/detailed-notas`) va por un TERCER camino —`Unidad::deAsignaturaCalculada` más
`notas_finales`— y el del año, por éste.

> **Y hay una SÉPTIMA copia de esta misma cuenta, que el censo del 43 no tenía**:
> `EditnotaController::notasDeLaAsignatura` (`:111` y `:118`) la lleva escrita **en línea**, no
> llama a `calculoAlumnoNotas` y sirve `PUT editnota/alum-asignatura`. **No gana los dos
> números**: unificarla mueve `editnota-alum-asignatura.json`, que es una instantánea publicada,
> y eso es un lote propio. Queda dicho para que el siguiente censo dé siete y no seis.

#### El divisor sale de la acumulada y NO de `RepartoDeLaNota`, y ahí hay un hallazgo

`calculoAlumnoNotas` **nunca ha pasado por `RepartoDeLaNota`**: pesa `s.porcentaje` crudo,
porque eso es lo que le traen `Unidad::deAsignatura` y `Subunidad::deUnidad` —las dos que usan
los seis lectores—. O sea que **en modo `promedio` los dos calculadores ya discrepaban antes de
esta fase**, y no en la parcial: **en la acumulada**, que es la que se imprime.

*Medido el 20 sep 2026 sobre `simonbolivar`, en el único año de la copia que está en `promedio`
(2026, con sus cuatro periodos abiertos):*

| | |
|---:|---|
| 99 | pares alumno–asignatura–periodo con notas en ese año |
| **14** | **dan distinto** entre `Σ (u%/100)·(s%/100)·nota` y `Σ (u%/100)·(1/n)·nota` |
| **42,3** | la peor diferencia, **sobre una escala de 0 a 50** |

```sql
SELECT COUNT(*), SUM(ABS(php_crudo - servicio) > 0.005), ROUND(MAX(ABS(php_crudo - servicio)),3)
FROM ( SELECT n.alumno_id, u.asignatura_id, u.periodo_id,
              SUM((u.porcentaje/100)*((s.porcentaje/100)*n.nota)) php_crudo,
              SUM((u.porcentaje/100)*((1.0/(SELECT COUNT(*) FROM subunidades sx
                    WHERE sx.unidad_id=s.unidad_id AND sx.deleted_at IS NULL))*n.nota)) servicio
         FROM unidades u
         INNER JOIN subunidades s ON s.unidad_id=u.id AND s.deleted_at IS NULL
         INNER JOIN notas n ON n.subunidad_id=s.id AND n.deleted_at IS NULL
         INNER JOIN periodos p ON p.id=u.periodo_id AND p.year_id=9 AND p.deleted_at IS NULL
        WHERE u.deleted_at IS NULL GROUP BY 1,2,3 ) t;
```

**Por eso el peso sale de los mismos dos números que la acumulada.** Sacarlo de
`RepartoDeLaNota` arreglaría el divisor y dejaría el cociente `acumulada ÷ Σ peso` midiendo
**dos repartos distintos** —que no es una nota de nada— y, sobre todo, **haría creer que el
problema está resuelto**: la parcial saldría bien al lado de una acumulada que sigue
discrepando de la que se guarda. *Un arreglo que tapa el síntoma en el número nuevo y deja el
viejo mintiendo es peor que no hacerlo.*

Lo que se gana con esta forma es un invariante comprobable: **`nota_parcial × Σ peso ==
nota_asignatura`, siempre y en los dos modos**. Lo fija
`LaParcialEnLaPlanillaTest::test_en_promedio_la_parcial_sigue_a_la_acumulada_de_este_metodo`,
que además exige que el servicio diga otra cosa — si algún día ese caso se pone verde con los
números iguales es que alguien unificó los dos calculadores, y entonces el caso **sobra y se
borra, no se relaja**.

#### El divisor se acumula en ENTEROS

`peso` se guarda como `porcentaje_unidad * porcentaje_subunidad` y los dos `/100` se reponen al
final: en la cobertura **se cancelan solos** y en la parcial se repone un `* 10000`. Sumar
cincuenta veces `0,7*0,3` en coma flotante mete un error que reaparece en el cociente; sumar
`2100` no mete ninguno. Con eso los tres números del lienzo salen **exactos** —`16.66`, `47.6`
y `0.35` con `===`— desde PHP, que es la única forma de que un caso de contrato pueda afirmar
un decimal en vez de un margen.

#### Las instantáneas: **2 de 129**, y el diff es la prueba de que la acumulada no se movió

Las dos son las únicas de los seis lectores que comparan instantánea:
`muestreo-notas-perdidas-show-profesor` y `muestreo-planillas-ausencias-show-profesor`. El
diff de cada una es **+2 líneas y nada más** —ni una quitada, ni una cambiada—:

```
                              'nota_asignatura' => 'float|int',      ← INTACTA
  +                           'cobertura' => 'int|null',
  +                           'nota_parcial' => 'float|int|null',
```

Las otras dos rutas que ganan los campos —`detalles/grupos-periodos` y
`editnota/detailed-notas`— **no tienen instantánea**, y las de boletines y
`editnota-alum-asignatura` van por caminos que esta fase no toca.

> **Y ese `int|null` de la cobertura desmiente un comentario que yo había escrito.** El código
> castea a `(float)`, pero **eso no fija el tipo en el JSON**: sin `JSON_PRESERVE_ZERO_FRACTION`,
> `json_encode(0.0)` sale `0` y `json_encode(1.0)` sale `1`, así que en los extremos al cliente
> le llegan **enteros**. En el seed la cobertura sólo sale 0 y 1, de ahí `int|null`. **Es
> exactamente lo que le pasa a `nota_asignatura` desde siempre** (`float|int`), así que no es
> nuevo ni es un fallo — pero quien lo lea en el front **compara valores, no tipos**. El
> comentario está corregido en el método. *Una instantánea contestó una pregunta que yo no le
> había hecho.*

#### Lo que cuesta, medido — **cero, y con los contadores, no con el reloj**

*Sobre `simonbolivar`, en la asignatura más cargada de la copia (432, periodo 10 — 986 notas,
45 alumnos, 22 subunidades), cargada **como la cargan los seis lectores** y con 5 vueltas por
pasada. Cuatro pasadas alternando el fichero viejo y el nuevo.*

| | `Handler_read_key` | `Handler_read_next` | reloj (mediana de 4) |
|---|---:|---:|---:|
| antes | 16.630 | 3.827.505 | 4.762 ms |
| después | **16.630** | **3.827.505** | 5.187 ms |

**Los contadores son idénticos en las ocho corridas: cero trabajo de base añadido.** Aquí no
hay una consulta que crezca —que es lo que le pasó a la fase 1 en modo `promedio`—: lo que se
añade es aritmética en PHP dentro de bucles que ya existían.

**Y el reloj no mide esto, así que su diferencia no es un efecto.** La dispersión entre pasadas
del *mismo* código va de 4.517 a 5.011 ms (11 %) y la del nuevo de 4.688 a 6.130 (31 %) — o sea
que el ruido es un orden de magnitud mayor que lo que se busca. El techo del trabajo añadido se
midió aparte: **4.950 casillas —las mismas del banco— en 0,679 ms** (mejor de 7), o sea
**0,014 %** de una pasada. Es la lección del §7: *con `tools/coste-del-recalculo.php` el reloj
oscilaba más que el efecto.*

> **Y el banco midió el árbol equivocado en su primera versión, que es la trampa de siempre.**
> El guion arrancaba Laravel con `require __DIR__.'/../../../vendor/autoload.php'` desde
> `.worktrees/pla/tools/`, o sea **el `vendor/` del árbol principal**; y como
> `autoload_psr4.php` calcula su `$baseDir` con `dirname(dirname(__DIR__))`, cargaba
> `/app/app/Models/Asignatura.php`. Las tres primeras pasadas compararon el código nuevo contra
> sí mismo. **Lo delató el propio guion**, porque imprime la parcial del último alumno y salía
> `NO EXISTE` también en la columna «después» — que es exactamente para lo que se le puso esa
> línea. *Un banco que sólo imprime milisegundos no puede avisar de que midió otra cosa.*

### Fase 2 — el semáforo deja de acusar al alumno · **ESCRITA el 20 sep 2026** (front y API)

Cuarto color gris, columna de cobertura y rótulo *informe de corte* con el % evaluado.

> #### ~~«No necesita desplegar la API si la fase 1 ya está»~~ — **ERA FALSO, y al revés: el front estaba entero y lo que faltaba era la API**
>
> Esta sección decía que la fase 2 era *«sólo `myvc_front/app2`»*. **El front la fundió el 20 sep
> por la mañana** —cuarto color con sus tres motivos distinguidos, columna de cobertura, rótulo y
> una hoja de riesgo que lista las asignaturas que callan— y al ir a encenderla midió a **qué
> endpoint llama de verdad su pantalla**:
>
> ```
> semaforo-grupo.ts:73   private readonly FORMATO = 1;
> datos/boletines.ts     {1: 'boletines', 2: 'boletines2', 3: 'boletines3', 6: 'boletines-competencias'}
> → PUT boletines/detailed-notas-group/{grupo}, SIEMPRE y en los dieciséis
> ```
>
> Y ese camino **no era ninguno de los siete del censo de la §Fase 1.bis**: va por
> `BoletinesController::putDetailedNotasGroup` → `detailedNotasGrupo` → `allNotasAlumno` →
> `Grupo::detailed_materias_notafinal`, que resuelve la definitiva con un `LEFT JOIN` sobre
> `notas_finales`. Ni el servicio, ni `calculoAlumnoNotas`, ni el séptimo de `EditnotaController`.
> **Es un OCTAVO calculador**, y el censo que lo habría encontrado no existía porque nadie había
> preguntado *«¿a qué endpoint llama la pantalla?»* — se había preguntado *«¿quién calcula la
> definitiva?»*, que es otra cosa.
>
> *El documento describía bien el trabajo del front y se equivocaba entera en el de la API. La
> frase se conserva tachada porque el error no es el dato: es haber dado por sabido el consumidor.*

> **Y gana algo que hoy no tiene nadie: delata a la asignatura que no ha reportado.** Hoy un
> docente que no calificó produce treinta rojos y la culpa se lee como del alumno. Con la cobertura
> impresa, esa casilla dice de quién es el silencio. Ése es el aviso que el colegio necesita a
> mitad de periodo, y no estaba en el encargo.

> **DECIDIDO el 20 sep 2026: el 15,00 % clavado va en GRIS** — `cobertura <= 0.15`, **2.265 grises**.
>
> Lo eligió Joseth con la medición delante, y **es el lado que encaja con el motivo que él mismo
> había dado para D2**: si esto se firma y se archiva, un docente que calificó **un solo indicador**
> no debería ponerle color a una asignatura delante de una familia. Los 152 pares de la frontera son
> exactamente ese caso, así que dejarlos con color habría contradicho la razón de ser del umbral.
> *Una decisión que no dice dónde está su borde la acaba tomando quien escribe el `if`.*
>
> **Lo que había escrito aquí, que es de dónde salió la pregunta.**
> *(Medido el 20 sep 2026 sobre `simonbolivar`, periodos abiertos.)* D2 dice *«gris por debajo del
> 15 % evaluado»* y no dice si el 15 clavado es gris o ya tiene color. Hay **152 pares clavados en
> 15,00 %** —**ocho asignaturas**, o sea grupos enteros: el docente que calificó **un solo
> indicador que vale el 15 %**— y eso es **más que los 42 pares que separan el 15 % del 10 %**. Con
> `<` salen **2.113** grises y con `<=`, **2.265**. La frontera no está en un sitio vacío: está
> justo encima del caso más común que hay.
>
> Y hay que leerlo con lo de arriba: **la cobertura es un factor de 0 a 1**, no un porcentaje; quien
> compare contra `15` en vez de contra `0.15` pintará de color absolutamente todo.

> **Y dos cosas de la fase 1 que cambian lo que la fase 2 puede hacer**, las dos medidas el 20 sep:
> la cobertura **nunca pasa del 100 %** —no sirve para delatar el reparto malo; eso es
> `porcentaje_unidades`— y ~~**la parcial y la cobertura no llegan hoy a la planilla**~~ **ya
> llegan desde el 20 sep 2026**, por la §Fase 1.bis: `calculoAlumnoNotas` las devuelve y cinco
> de sus seis lectores las publican. Los nombres que le llegan al front son **`nota_parcial` y
> `cobertura`**, al lado de `nota_asignatura` — y no `parcial`, que es como se llama dentro del
> servicio: junto a `nota_asignatura`, un campo llamado `parcial` se lee como una bandera.
> **A los boletines NO llega**, y el porqué está en la §Fase 1.bis.

#### Lo entregado en la API: **0 rutas, 0 columnas, 0 migraciones y 0 consultas nuevas**

| | |
|---|---|
| Nuevo | `app/Support/LaParcialYLaCobertura.php` — las dos fórmulas y la regla de qué casilla cuenta |
| Tocados | `app/Models/Asignatura.php` (sólo las dos expresiones finales), `Informes/BoletinesController`, `Informes/NotasActualesAlumnosController` |
| Prueba | `tests/Contrato/LaParcialEnElBoletinTest.php` |
| Rutas · columnas · migraciones · clientes | **0 · 0 · 0 · 0** |

**No cuesta ni una consulta**, y ésa es la parte que casi se presupuesta mal. La primera
estimación fue *«o una llamada a `calculoAlumnoNotas` por par —treinta alumnos por doce
asignaturas— o hacerlo en SQL»*, y las dos eran innecesarias: `allNotasAlumno` **ya carga** las
unidades (`Unidad::deAsignaturaCalculada`) y sus subunidades (`Subunidad::deUnidadCalculada`) para
pintar el boletín, así que los dos números son dos sumas sobre lo que ya está en memoria.

#### El octavo camino lo recorren CINCO, y lo ganan DOS — con el motivo de cada uno de los tres

**El censo son cinco, y la primera versión de este renglón decía tres.** Lo corrigió `8myvc-79`
contándolo entero; el tres salió de un `grep … | head`, o sea de una lista **truncada por el
propio comando que la producía**. *Un detector que corta su salida no dice que la cortó, y de las
dos lecturas la falsa es la que hace archivar el asunto.* La orden que lo cuenta bien, descartando
los comentarios —que son cuatro más y lo inflan—:

```bash
grep -rn "Grupo::detailed_materias_notafinal(" app/ | grep -v '\* '
```

| quien lo llama | ¿gana los dos números? | por qué |
|---|---|---|
| `Informes\BoletinesController:320` | **sí** | es el que lee la pantalla del semáforo |
| `Informes\NotasActualesAlumnosController:180` | **sí** | mismo método copiado y **ya carga las subunidades** |
| `Informes\Boletines2Controller:223` | no | **no carga las subunidades**: su `allNotasAlumno` sólo pide las unidades, y el único `subunidades` del fichero es `Subunidad::perdidasDeUnidad`, que trae **sólo las perdidas**. Ahí costaría una consulta por unidad, asignatura y alumno — *«no cuesta ni una consulta»* dejaría de ser cierto justo donde se dice |
| `Informes\BoletinPorCompetenciasController:459` | no | otro modelo de evaluación: ahí la definitiva no es una suma ponderada, así que **la parcial pesada no está definida** — el motivo del sexto lector de la §Fase 1.bis |
| `Models\Nota::alumnoPeriodoDetalle:376` | **no, y es el único candidato de verdad** | carga unidades y subunidades con la forma que `calculoAlumnoNotas` consume, así que saldría igual de barato. Queda fuera porque **no lo pide ninguna pantalla todavía** y cada campo nuevo mueve instantáneas de `notas/detailed` y `alumno-periodo-grupo`. *Se deja escrito como candidato, no como olvido.* |

**`Boletines3Controller` ni siquiera entra en este censo**: no pasa por aquí, usa
`Grupo::detailed_materias_notas_finales` —la de los cuatro periodos en una fila—, donde lo único
que cabría es «la parcial del año», que no está definida en ninguna parte.

*La regla de «no dejar dos iguales y uno distinto» vale cuando son iguales, y de estos cinco sólo
dos lo son.*

#### El numerador se recalcula — decisión de Joseth del 20 sep, y su precio

Las dos opciones se pusieron con su coste. Se recalcula en el bucle, **no** se divide la definitiva
guardada, por dos razones: tras la §Fase 4 un periodo cerrado con `fuera` guarda la definitiva **ya
normalizada** y dividirla otra vez normalizaría dos veces —dando un número más bajo, plausible y
sin significado en un papel que se firma—, y la parcial del boletín tiene que ser **la misma que la
de la planilla** para el mismo alumno.

**El precio, escrito antes de que lo encuentre una secretaría:** la parcial **no cumple**
`parcial × Σpeso == nota_asignatura` contra la `nota_asignatura` que el boletín publica, porque ésa
es la guardada y el numerador es el del bucle. En modo `promedio` los dos calculadores ya discrepan
—14 pares de 99 en el 2026 de la copia, hasta 42,3 puntos sobre 0–50—, así que **el mismo alumno
puede llevar impresa una parcial que no cuadra con su acumulada**. No es un fallo nuevo: es la
discrepancia que ya existía, ahora con un número al lado que la deja ver. Unificar los dos
calculadores sigue abierto en la §7 y es una decisión de Joseth.

#### Lo que fija el test, y los dos controles que se vieron en rojo

Cinco casos, 32 aserciones. El que sostiene el resto es
`test_el_boletin_y_la_planilla_dicen_la_misma_parcial`, que pasa **el mismo alumno y la misma
asignatura** por los dos caminos y compara: sin él, dos implementaciones de la misma cuenta se
separan y el alumno acaba con una cobertura en la planilla de su profesor y otra en su boletín, las
dos con 200 y ninguna en rojo.

| lo que se rompió | qué cayó |
|---|---|
| la guarda de la subunidad sin fila (`nota_id`) | **2** — el de la subunidad ajena y el del alumno sin casillas |
| `!== null` → `> 0` en el divisor | **1** — el del 0 tecleado |

**La trampa propia de este camino es el `LEFT JOIN`**, y no existe en el otro: aquí la subunidad
que **no le toca a este alumno vuelve igual**, con `nota_id` a `null`. Mirando `nota` en vez de
`nota_id` el peso total del lienzo sube de 10.000 a 11.500 y la cobertura cae de 0,35 a 0,304 — la
pantalla pintaría de gris a un alumno por un indicador que no es suyo.

#### El front ya estaba, y el papel ya se miró

`myvc_front` fundió la fase 2 el 20 sep (`5f8656d6`…`6c49ca40`), con `COBERTURA_QUE_APAGA = 0.15`
comparado contra el factor y no contra el redondeo, y `hayCorte()` preguntando por `undefined` —o
sea que **un colegio sin desplegar imprime el papel del 19 de septiembre byte por byte**, que es lo
que sostiene el despliegue de uno en uno—. De camino corrigió un campo que había escrito
adivinando —`parcial` en vez de `nota_parcial`— y el modo de fallo merece quedar escrito: **un
nombre equivocado se lee exactamente igual que un colegio sin desplegar**, así que no habría dado
error en consola y habría salido el papel viejo en los dieciséis.

Y condujo la hoja en Chrome interceptando la respuesta, con doce veredictos: `cobertura: 0.15` gris
**con su número impreso** —D2 apaga el color, no el dato—, `cobertura: null` con la casilla del `%`
**vacía** y no en 0, y `nota_parcial: null` con **una raya** donde antes iba un cero. *Cuando la
API emita, no será la primera vez que se mire el papel.*

### Fase 3 — lo que ve la familia

La app enseña **la parcial** con su barra y el aviso de periodo en curso. **Es donde se nota**, y
es `myvc_flutter` —una sola app para los dieciséis, así que lo que la rompa los rompe a todos—.
Con D1 decidida no hay interruptor: **0 columnas y 0 rutas nuevas**, sólo los dos clientes.

> **Y aquí el despliegue va en un orden y no en el otro.** La app enseña un campo que la fase 1
> tiene que estar **desplegada** para devolver, colegio a colegio — no fusionada. Una app publicada
> antes que la API deja a los colegios que aún no recibieron el despliegue enseñando un hueco donde
> va la nota, y `myvc_flutter` es una sola app para los dieciséis.

### Fase 4 — el cierre, que es donde el hueco tiene que morir · **ESCRITA el 20 sep 2026** (`feat/el-cierre-y-lo-no-calificado`)

El diálogo de cierre pregunta qué son las casillas que quedan, con las tres salidas de D3, más los
botones del docente para resolver en bloque y el estado **NE** por celda si entra D4. 2 rutas y
**1** columna en `years` —la de D3—, **con «pasa a cero» de fábrica** por lo dicho en la §5.

> **Ésta es la fase que impide que el arreglo se convierta en un agujero.** Dejar de contar lo no
> calificado **durante** el periodo es correcto; dejar de contarlo **al cerrar** es aprobar a quien
> no entregó. Es literalmente el aviso de Moodle, y por eso el cierre es una fase y no una línea.

#### Lo entregado: **2 rutas —las que decía el plan— y 2 columnas, que decía una**

```
PUT  years/cierre-sin-calificar          la elección del rector       auth.personal + permiso dentro
GET  periodos/sin-calificar/{periodo_id} el diálogo: cuántas y de quién   auth.personal
```

Y **ninguna ruta nueva para el cierre**: cerrar ya era `PUT
periodos/toggle-profes-pueden-editar-notas`, con sus tres clientes, y lo que cambia es que ahora
**hace algo** con lo que queda vacío. Sigue devolviendo **texto**, que es contrato
(`myvc_front/scripts/endpoints-de-texto.json`), así que la cuenta va dentro de la frase.

**Los botones del docente para resolver en bloque no gastan ruta y eso se cuenta:** `PUT
notas/lote` ya escribe muchas casillas de una vez y desde la fase 0 acepta `nota: null`, así que
*«ponerle 0 a todo lo que falta»* y *«vaciarlas»* ya se pueden hacer. Una ruta que duplica a otra
hay que mantenerla, documentarla y probarla para siempre. **D4 —el estado NE por celda— no entra**,
por la §5: sólo vuelve el día que un colegio elija «pasa a 0» y quiera excepciones.

#### Las DOS columnas, que es lo único donde el plan se quedó corto

```
years.cierre_sin_calificar     enum('cero','fuera','bloquear') NOT NULL DEFAULT 'cero'
periodos.cierre_sin_calificar  enum('cero','fuera')            NULL     DEFAULT NULL
```

La de `years` es **la elección del rector**. La de `periodos` es **lo que se aplicó el día que se
cerró**, y es la que lee el cálculo — la elección **no la lee nadie más que el propio cierre**.

**Sin esa segunda columna la regla dura no se puede cumplir por mecanismo.** Si el cálculo leyera
la elección del año, un rector que cambiara de opinión en octubre movería las definitivas de los
periodos que ya tiene cerrados e impresos, sin tocar una nota y sin un solo error en ningún log.
Leyendo la congelada, **no hay ninguna secuencia de pulsaciones que alcance un periodo cerrado**:
la única escritura de esa columna es el cierre, y el cierre sólo ocurre sobre un periodo abierto.
Es la forma de la migración de la fase 0, que acotó su `UPDATE` con `profes_pueden_editar_notas =
1` en vez de confiar en que nadie lo corriera dos veces.

`NULL` es un estado y no un hueco —*«no se ha cerrado nunca por este camino»*—, y es lo que tienen
**los 36 periodos de la copia y los de los dieciséis colegios el día del despliegue**: con él, el
cálculo es byte por byte el de ayer. **No se rellena hacia atrás**: marcar un periodo de 2021 como
`'cero'` afirmaría que alguien tomó esa decisión, y la tomó el `NOT NULL` de `notas.nota`.

**Precio medido antes de escribirla**, con `tools/lo-que-reparte-una-columna.py`: la de `years`
mueve **6** instantáneas y la de `periodos` **16**, de las que dos coinciden — **veinte en total**.
`periodos` viaja dentro del boletín, del año y de media docena de informes con `SELECT *`. A
cambio, los tres clientes **reciben el estado** sin ruta nueva.

#### Qué hace cada salida DE VERDAD, que es donde estaba la trampa

| | la casilla vacía | la definitiva |
|---|---|---|
| `cero` | **se escribe un 0 real** | **no se mueve ni un decimal** |
| `fuera` | se queda vacía | **pasa a ser la parcial** |
| `bloquear` | no se cierra (422) | no hay cierre |

**La primera fila es la que engaña.** La definitiva **no normaliza** —regla 2 de
`DefinitivasDeAsignatura`—, así que `SUM(peso × NULL)` y `SUM(peso × 0)` dan **el mismo número**.
De ahí salen las dos consecuencias que gobiernan esta fase:

1. **`fuera` obliga a normalizar la definitiva, o D3 es un adorno.** Sin eso, «pasa a cero» y
   «queda fuera de la cuenta» imprimirían el mismo boletín y la decisión del rector no tendría
   ninguna consecuencia observable. Con el lienzo de la §3.bis c: `cero` deja **16,66** y `fuera`
   deja **47,60**. *Ésos son los dos números que un rector está eligiendo.*
2. **`cero` tiene que ESCRIBIR los ceros aunque no muevan la definitiva.** Lo que mueven es la
   **cobertura** —pasa a 1— y la **parcial** —pasa a coincidir con lo que imprime el boletín—. Sin
   esa escritura, un periodo cerrado se queda **gris en el semáforo para siempre** y la familia ve
   47,60 donde el papel dice 16,66. *Ahí es donde muere el hueco: en un periodo cerrado, lo que ve
   la familia y lo que dice el papel vuelven a ser el mismo número.*

> **Y esto corrige la primera línea de la §6**, que dice *«la definitiva de hoy no se toca en
> ninguna fase»*. Vale para las fases 0 a 3 y para los dieciséis colegios con el defecto puesto;
> **`fuera` sí la mueve**, a propósito, sólo en el periodo cerrado y sólo para quien lo elija. Es
> literalmente lo que D3 pone en manos del rector.

#### `cerrar con cero` es IRREVERSIBLE, y eso no estaba en el plan

*(Salió al escribir el control de un test, no al releer el documento.)* Las dos salidas **no son
simétricas**: `fuera` conserva la información —las casillas siguen vacías, así que reabrir y cerrar
con `cero` todavía puede ponerles el 0— y `cero` **la destruye**: escribe un 0 real, y a partir de
ahí *«nadie lo calificó»* y *«sacó cero»* vuelven a ser indistinguibles, que es el fallo entero que
la fase 0 vino a quitar. Reabrir y cerrar con `fuera` ya no devuelve 47,60: devuelve 16,66.

**No se arregla y no es un fallo**: hacerlo reversible pediría guardar qué casillas se cerraron a
cero, o sea la columna `calificada_at` que **D6 descartó**. Lo que hace falta es que esté escrito,
porque la pantalla que pregunte *«¿seguro?»* tiene que poder decir por qué. Lo fija
`ElCierreYLoNoCalificadoTest::cerrar_con_cero_es_irreversible_y_reabrir_no_lo_deshace`.

#### El cierre es la ÚLTIMA escritura posible, y por eso la decisión se aplica ahí

No es una elección de diseño: es la decisión de Joseth del 17 sep 2026 vista desde este lado.
`DefinitivasDeAsignatura::ponerAlDiaUnInforme()` **no escribe si el periodo está cerrado**
—*imprimir un histórico no debería reescribir definitivas de hace tres años*— y con el periodo
cerrado las notas tampoco se pueden tocar, así que **ningún recálculo posterior se dispara**. Si el
cierre no escribe, `fuera` no llega nunca al papel.

**Lo que cuesta, medido** el 20 sep 2026 sobre `simonbolivar`, periodo 2 de 2025 (79 asignaturas,
3.611 definitivas) y **contra el código que se entrega, no contra el de antes**: **7.779
consultas**, 7.154 ms dentro de MySQL y **7,88 s de pared**. **De las tres cifras la que vale es la
de consultas**: el reloj de este banco dio entre **7 y 34 s para la misma operación** según lo que
hubiera corriendo al lado —esa tarde había cinco suites y el contenedor al 1.300 % de CPU—, y
producción es CloudLinux con límites de I/O por cuenta. Lo que viaja es el orden de magnitud: **dos
consultas por definitiva**.

> **Las 80 de diferencia con las 7.699 que daba el mismo periodo antes de esta fase son el precio
> de la fase, y se dicen**: una consulta por asignatura, la que `calcular()` hace ahora para
> preguntarle al periodo cómo se cerró. Es el 1 %, y **se paga también con el defecto puesto**, o
> sea en los dieciséis colegios. *Un coste que no se mide se convierte en un argumento.* Es un acto que ocurre cuatro veces al año, así que el precio es
asumible; **lo que no sería asumible es pagarlo sin haberlo elegido**, y por eso el defecto es
`cero`, que no llama a eso ni una vez —su coste es **un `UPDATE`**, cronometrado por la fase 0 en
**0,49 s para 20.655 filas** contra MariaDB 10.5—.

**Si la petición se corta a la mitad, se reanuda**, y eso no es un arreglo aparte: el cierre
congela la marca **antes** de rehacer nada, así que desde ese instante el cálculo ya dice la
verdad; un corte deja definitivas sin rehacer pero **ninguna mal calculada**, y volver a pulsar
«cerrar» termina el trabajo. Por eso el cierre de un periodo ya cerrado **y marcado** reanuda —con
la marca congelada, nunca con la elección vigente— y el de un periodo cerrado **sin** marca no
toca nada.

#### La puerta de atrás que había que cerrar, y las dos que no

Al darle dueño a un número se repasan **todos** los caminos que lo escriben. Los escritores de una
definitiva automática que podían alcanzar un periodo cerrado son tres, y sólo uno estaba vivo:

| | |
|---|---|
| `DefinitivasPeriodosController::putCalcularGrupoPeriodo` | **VIVO** — lo llaman los dos fronts. Su consulta es la acumulada a pelo, así que pulsarlo tras cerrar con `fuera` habría devuelto las definitivas a la otra fórmula **en silencio y con 200**. Ahora contesta **422** en un periodo marcado como `fuera` y dice a dónde ir: volver a cerrar. |
| `NotaFinal::calcularAsignaturaPeriodo` | muerto — **no tiene un solo camino** en todo `app/`, comprobado en BI-2 y escrito en su propio docblock |
| `Alumnos\Definitivas` | roto — usa `$alumno_id` sin definirla; no puede escribir una fila |

**No se le enseña a normalizar al que está vivo**, y no es pereza: está condenado —es uno de los
seis escritores que la fase 3 del [10](10-definitivas.md) sustituye— y enseñarle la fórmula nueva
sería la decimoséptima copia del reparto. Lo que se hace es impedir que deshaga una decisión que él
no conoce.

#### El permiso: **dentro**, y es el de `toggle-mostrar-nota-numerica`

`auth.personal` en la ruta y `Autoriza::puedeElegirQuePasaAlCerrar` dentro del método
—superusuario, Secretario, Coord académico y Rector, **12 personas de las 74** de la copia—. La
familia `years/*` va con `auth.personal` y nada dentro salvo `toggle-mostrar-nota-numerica`, y éste
va con el segundo grupo. **La razón no es simetría: es de quién es el interés.**

`auth.personal` deja pasar a las 74 cuentas de personal, de las que **53 son docentes**, y esta
columna decide si a un alumno le cuentan como cero **las casillas que su profesor no calificó**.
Puesta en `fuera`, la consecuencia de no haber calificado desaparece del boletín. O sea que con el
permiso de la familia **el docente que no calificó podría borrar la huella de no haber
calificado**, y para el colegio entero. Es el único de los interruptores del año en el que quien lo
pulsa puede ser parte interesada.

**Y cerrar el periodo NO se estrecha**, que es la mitad que hay que leer para no tomarlo por un
olvido: `periodos/toggle-profes-pueden-editar-notas` sigue con `auth.personal` y nada dentro, con
sus tres clientes intactos. Se estrecha **elegir** la política, no **aplicarla**. El diálogo
—`GET periodos/sin-calificar/{periodo_id}`— va con el permiso del cierre y no con el de la
elección, porque un diálogo más estrecho que el botón que precede dejaría a secretaría cerrando a
ciegas.

**Y la columna queda excluida de `PUT years/toggle-cambiar-valor`**, que escribe cualquier columna
de `years` con el mismo `auth.personal`: es la **cuarta** de esa lista y sin ese corte el permiso
se saltaría en una línea. *Al darle dueño a una columna se repasan todos los caminos que escriben
esa tabla, no sólo el que se está tocando.*

#### Lo que NO lleva, dicho para que no se lea como un olvido

**`Autoriza::exigirEscrituraEnElAnio`**, que sí lleva `years/modelo-evaluacion` desde el 15 sep.
Allí hacía falta porque **el modo se lee vivo en cada cálculo del año**, así que cambiarlo
reescribía definitivas de un año cerrado. Aquí el cálculo **no lee esta columna**: lee la congelada
del periodo, que sólo escribe el cierre. Esto no puede alcanzar un periodo cerrado ni aunque se
quiera, y **un guard que no protege nada es peor que no ponerlo** — el día que alguien lo lea creerá
que hay algo protegido ahí.

#### Lo que queda abierto de esta fase

- **La planilla de un periodo cerrado con `fuera` sigue pintando la acumulada.** Es el pendiente
  que la fase 1 ya dejó escrito y que esta fase **no cierra**: `Asignatura::calculoAlumnoNotas`
  —PHP, sin denominador, seis lectores— produce `nota_asignatura` y no pasa por el servicio. El
  **boletín sí** queda bien, porque imprime `notas_finales` (`BoletinesController:335`), que es lo
  que el cierre reescribe. Unificar los dos calculadores es la decisión de Joseth que sigue
  pendiente.
- **Las pantallas.** Esta fase deja el backend: el diálogo de cierre y el selector de tres opciones
  los pinta `app2`.

### ~~Fase 5 — las fechas~~ · **descartada el 19 sep 2026 (D5)**

Distinguir *«aún no toca»* de *«el docente va atrasado»* exige fecha por indicador, y hoy la tienen
**0 de 36.705**. Las fases 0–4 resuelven los dos síntomas sin pedirle un dato nuevo a nadie, así
que esto se queda fuera.

**Se deja escrito y no se borra**, porque el día que vuelva a pedirse lo que hace falta saber es
por qué no se hizo y cómo se haría: se captura **en la plantilla del colegio** —donde ya se editan
unidades y subunidades— y **no indicador a indicador**. Una columna que le cuesta trabajo al
docente en cada asignatura **no la va a llenar nadie**, que es exactamente por qué `inicia_at`
lleva años vacía.

---

## 7 · Lo que este documento no ha medido

- **Los dieciséis colegios.** Todo lo de arriba es un colegio, el del docker. El reparto de
  `nota_default > 0` (4.417 de 36.705 subunidades, el 12 %) puede ser muy distinto en otro, y es
  justo el dato que decide cuánto baja una definitiva al dejar de contar los regalos.
- ~~**El coste de la consulta.**~~ **MEDIDO el 20 sep 2026**, y el argumento *«dos `SUM` más sobre
  las mismas filas»* era cierto **sólo en un modo**: cero en `porcentaje` y **×2 en `promedio`**,
  donde el peso arrastra una subconsulta correlacionada. Está en la §Fase 1 con los contadores.
  De paso: **`tools/coste-del-recalculo.php` no sirve para medir esto** — su reloj oscila más que
  el efecto (el `sello`, que nadie tocó, dio entre 2,0 y 4,8 ms entre pasadas). Se midió con
  `Handler_read_key`/`Handler_read_next`, que son deterministas.
- **Quién lee `nota_asignatura` hoy en los cuatro clientes.** La fase 1 no lo cambia, pero la fase
  3 sí decide qué número se pinta, y el radio lo mide el front, no nosotros.
- ~~**El segundo calculador de la definitiva.**~~ **MEDIDO el 20 sep 2026**, y las dos preguntas
  tienen respuesta. *Cuánto cuesta darle los tres números*: **cero** —contadores idénticos, 0,014 %
  de aritmética—, §Fase 1.bis. *Si unificarlo mueve algún número impreso*: **sí, y por eso no se
  unificó** — en modo `promedio` los dos calculadores ya discrepan hoy, **14 pares de 99 en el año
  2026 de la copia y hasta 42,3 puntos** sobre una escala de 0 a 50, porque este método pesa
  `s.porcentaje` crudo y el servicio pesa `1/n`. **Lo que sigue sin medir es qué pasa el día que se
  unifiquen**: cuántos números impresos se mueven en los dieciséis colegios y cuál de los dos es el
  que hay que conservar. Es una decisión de Joseth y no un arreglo.

  > ### ⚠️ ESAS 14 PAREJAS SE MIDIERON EN EL AÑO QUE ALGUIEN TRASTEÓ ESE MISMO DÍA
  >
  > *(Anotado el 20 sep 2026 por la noche. **No es una medición nueva**: es mirar de qué población
  > salió la que ya está escrita arriba.)*
  >
  > El renglón dice *«14 pares de 99 **en el año 2026 de la copia**»* y dice por qué discrepan:
  > *«este método pesa `s.porcentaje` crudo y el servicio pesa `1/n`»* — o sea que **la
  > discrepancia es del modo `promedio`**. Y 2026 está en `promedio` porque **una sesión de
  > desarrollo lo puso ahí ese mismo día a las 00:18**, junto con `modelo_evaluacion =
  > competencias`; los ocho años anteriores están en `porcentaje` y Joseth confirmó que **ningún
  > colegio ha usado nunca otra cosa**. El detalle está en `ESTADO-ACTUAL.md`.
  >
  > **Lo que eso pone en duda no es el número, es la frase que cuelga de él.** Arriba se lee
  > *«¿unificarlo mueve algún número impreso? **sí, y por eso no se unificó**»*, y esa decisión
  > descansa entera en una medición hecha sobre un modo que **no usa ningún colegio**. En
  > `porcentaje` —los ocho años reales y los dieciséis colegios— los dos calculadores **podrían
  > coincidir perfectamente y nadie lo ha mirado**: `s.porcentaje` crudo y `1/n` sólo divergen
  > cuando el reparto no es por porcentaje.
  >
  > **No se midió aquí**: Joseth dijo el 20 sep que este renglón es de `8myvc-79` y que no se pisa.
  >
  > #### Y lo midió el front esa misma noche, sin que se lo pidiéramos: EN `porcentaje` COINCIDEN
  >
  > `myvc-front-a7` condujo el boletín de **Quinto, periodo 1 de 2025** —año en `porcentaje`, 10
  > asignaturas con plan, 3.997 notas y **todas calificadas**— y `nota_parcial` y
  > `nota_asignatura` salieron **el mismo número en las diez**:
  >
  > ```
  > MAT 40.7 / 40.7      EdF 46.15 / 46.15    NAT 43.72 / 43.72    ESP 34.65 / 34.65
  > ART 45.925 / 45.925  ING 30 / 30          INF 39.6 / 39.6
  > SOC 33.69 / 33.690000000000005            ETI 45.52 / 45.519999999999996
  > REL 37.35 / 37.349999999999994
  > ```
  >
  > Sólo el último bit de coma flotante. Y las dos cifras vienen de sitios distintos —la parcial la
  > recalcula el bucle, la `nota_asignatura` es **la guardada en `notas_finales`**—, así que
  > coincidir significa que **el camino que escribió la definitiva y el que la recalcula dan lo
  > mismo** cuando el reparto es por porcentaje y el plan está completo.
  >
  > **Lo que NO cierra, y conviene decirlo antes de que alguien lo cite de más:** son **10 parejas
  > de un grupo y un periodo**, no las 99 del censo original. Es una señal fuerte y **no** un censo
  > en `porcentaje`. La §7 sigue necesitando la comparación completa sobre 2025 antes de decidir
  > nada — lo que ha cambiado es hacia dónde apunta la evidencia, no que esté medido.
  >
  > *Se dice así a propósito: la lección del día es que una población pequeña y cierta se lee como
  > si describiera el todo, y este resultado favorece la hipótesis de quien escribe — que es
  > exactamente cuándo hay que contar las parejas en vez de celebrarlas.*
  >
  > #### CENSADO el 20 sep 2026, por encargo de Joseth. Y la respuesta es ANALÍTICA
  >
  > **En modo `porcentaje` los dos calculadores no «coinciden»: usan la MISMA fórmula.**
  > `RepartoDeLaNota::pesoDeSubunidad()` es literalmente:
  >
  > ```php
  > if ($modo === self::PROMEDIO) {
  >     return '(1.0/'.self::cuantasSubunidades($s).')';
  > }
  > return "{$s}.porcentaje/100";
  > ```
  >
  > y `calculoAlumnoNotas` calcula `nota * s.porcentaje / 100` a mano. **Es la misma expresión**,
  > así que la divergencia en `porcentaje` no es improbable: es **estructuralmente imposible**. La
  > §7 queda acotada al modo `promedio`, y ningún colegio lo usa.
  >
  > *Esto vale más que cualquier muestra —las diez parejas del front incluidas— porque no depende
  > de qué datos te toquen.* Y por eso se dice primero: el censo de abajo **confirma**, no
  > demuestra.
  >
  > #### Y el censo empírico, con lo que NO contesta dicho antes que lo que sí
  >
  > **La consulta que se corrió NO compara los dos calculadores.** Compara la definitiva
  > **guardada** en `notas_finales` contra una recomposición en SQL, que es otra pregunta y
  > arrastra al menos tres confusores: las nivelaciones, los alumnos con boletín independiente
  > —que tienen unidades propias— y la normalización del cierre de la Fase 4. *Se dice antes del
  > número porque es lo que decide qué puede concluirse de él.*
  >
  > Sobre el año **2025** (`porcentaje`), con `manual = 0`:
  >
  > | periodo | pares | discrepan | % |
  > |---|---|---|---|
  > | 30 | 4.538 | **0** | 0,0 |
  > | 31 | 2.538 | **616** | 24,3 |
  > | 32 | 3 | 0 | 0,0 |
  > | 33 | 45 | **0** | 0,0 |
  >
  > **4.586 parejas en tres periodos con CERO discrepancias**, y las 616 **enteras en uno solo**.
  > Eso es lo contrario de un desacuerdo sistemático entre calculadores: un desacuerdo de fórmula
  > se repartiría por todos los periodos.
  >
  > **Qué tiene el 31, medido pero SIN cerrar**: tiene **138** parejas con notas tocadas *después*
  > de que se guardara su definitiva, contra **2** en el periodo 30 y **0** en el 33 — o sea
  > definitivas rancias, que es un fenómeno conocido y ajeno a esto. Pero 138 **no explica 616**,
  > así que **el resto queda sin atribuir y no se finge**. Lo que sí se puede afirmar es que el
  > fenómeno vive en un periodo y no en la aritmética.
  >
  > > **Lo midió quien escribió la hipótesis, y eso se dice.** La regla de la casa es que una cifra
  > > la corra alguien que no la propuso; aquí Joseth encargó explícitamente *«encárgate tú»* con
  > > el dueño del renglón ya desaparecido. La parte que **no** depende de quién mida es la
  > > analítica de arriba, que es una lectura del código y se puede comprobar en treinta segundos.
  >
  > **Qué queda para quien retome la §7**: la frase *«¿unificarlo mueve algún número impreso? sí,
  > y por eso no se unificó»* se sostenía en una medición hecha en `promedio`, sobre el año
  > trasteado. En `porcentaje` no hay nada que unificar. **La decisión de unificar o no se
  > convierte en «qué hacer el día que un colegio elija `promedio`»**, que es mucho más pequeño —
  > y lo que sí sigue abierto, aparte, es de dónde salen las 616 del periodo 31.
  >
  > *Es la tercera vez en el mismo día que una cifra cierta describe el año trasteado y se lee
  > como si describiera los dieciséis colegios.* Las otras dos: el bloqueante del semáforo, y la
  > cobertura del boletín independiente.

  > **Y este renglón decía «de la planilla Y DE LOS BOLETINES», que era falso — lo midieron DOS
  > ramas el mismo día, por caminos distintos, y coincidieron.** La de la planilla censando los
  > seis lectores de `calculoAlumnoNotas`; la del cierre mirando quién lee `notas_finales`
  > (`Informes\BoletinesController:335`, directamente, o sea la tabla que el cierre reescribe).
  > **El boletín no pasa por el segundo calculador.** Lo que sí se queda con él es **la planilla y
  > los cinco informes**, así que un periodo cerrado con `fuera` imprime bien el boletín y pinta la
  > acumulada en la planilla. Eso sigue abierto y es de Joseth.
  >
  > > **Y este renglón decía «un TERCER camino» — son más, y el censo bueno es de CINCO.**
  > > *(20 sep 2026, por la noche.)* Lo destapó `myvc-front-a7` desde la pantalla del semáforo,
  > > que lee `PUT boletines/detailed-notas-group/{grupo}` y acaba en
  > > `Grupo::detailed_materias_notafinal` + `notas_finales` — ni el sexto lector ni el séptimo.
  > > Reproducido aquí antes de escribirlo, y contado con
  > > `grep -rn "Grupo::detailed_materias_notafinal(" app/` **descartando los comentarios**, que
  > > son cuatro más y si no inflan el número:
  > >
  > > ```
  > > app/Models/Nota.php:376                                      Nota::alumnoPeriodoDetalle
  > > Informes/BoletinesController.php:319
  > > Informes/Boletines2Controller.php:223
  > > Informes/NotasActualesAlumnosController.php:179
  > > Informes/BoletinPorCompetenciasController.php:459
  > > ```
  > >
  > > **Lo que no cambia es la conclusión** —el boletín sigue sin pasar por `calculoAlumnoNotas`,
  > > que es lo que las dos ramas midieron—; lo que cambia es **cuántos caminos hay al otro lado**,
  > > y eso es lo que decide el alcance de quien vaya a darles la parcial. *Escribir «un tercer
  > > camino» cuando hay cinco no falla nada y hace que el siguiente crea que ya está contado.*
  >
  > *Dos mediciones independientes que se tocan valen más que una: ninguna de las dos sesiones
  > podía ver a la otra, y por eso esto se escribe como corroboración y no como repetición.*

- **El séptimo calculador.** `EditnotaController::notasDeLaAsignatura` lleva la misma cuenta escrita
  en línea y no pasa por ninguno de los dos. No está medido cuánto se separa de ellos.

- **Los otros cinco escritores de `notas_finales`, en un periodo cerrado con `fuera`.** La fase 4
  cerró el único vivo —`putCalcularGrupoPeriodo`, con un 422— y **comprobó que los otros dos que
  escriben una definitiva automática no tienen camino**: uno está muerto y el otro roto. Los tres
  que quedan escriben `manual`, `recuperada` o la nivelación, que `recalcular()` respeta por
  diseño. **Lo que no está medido es qué pasa el día que la fase 2 del 10 ponga la clave única** y
  este censo tenga que rehacerse.
