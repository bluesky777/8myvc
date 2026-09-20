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
| `ALTER TABLE notas MODIFY nota int NULL, ALGORITHM=INPLACE, LOCK=NONE` | **8 s** sobre 1.166.608 filas, sin bloquear. **MySQL 8 del docker — producción es MariaDB 10.5 y esto NO está medido ahí**: va a `tools/ensayo-de-la-tanda.sh` sobre copia de un colegio antes de nada. |
| Agregados sobre `notas.nota` que cambiarían de significado (`AVG`, `MIN`, `MAX`) | **Cero.** El único `AVG(c.nota)` del proyecto (`PuestosController:226`) es de comportamiento, otra tabla. |
| `INSERT INTO notas(` que no nombren la columna | **Cero**: todos la pasan, así que ninguno empezaría a meter `NULL` por descuido. |
| **Los tres clientes** | **Ninguno se rompe, y dos ya estaban esperando esto.** |

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

Cuatro consecuencias que hay que tener escritas antes de implementarlo:

1. **`Σ peso` es la cifra que hoy está implícita y nunca se escribió.** La definitiva de hoy es
   `Σ aporte` **dando por hecho que el divisor es 1**. Por eso una asignatura mal repartida da una
   nota rara en vez de un aviso.
2. **Y eso convierte un fallo conocido en un número.** `DefinitivasDeAsignatura` (regla 2) decide a
   propósito **no normalizar**, porque *«que una asignatura mal configurada dé una definitiva rara
   es la intención — es lo que la delata en la planilla»*. Eso **no cambia**: la que normaliza es
   la parcial, no la definitiva. Lo que se gana es que una asignatura cuyas unidades suman 120
   termine de calificarse y salga con **cobertura 120 %**, que delata muchísimo mejor que una nota
   alta — un número que sólo puede significar una cosa.
3. **Peso cero no mueve nada, y hoy tampoco.** **2.242 de 36.705** subunidades (6,1 %) tienen
   `porcentaje = 0`; ninguna unidad lo tiene. Calificarlas no cambia la parcial ni la cobertura, y
   la pantalla **tiene que decirlo**: una casilla de peso 0 calificada no puede pintarse como
   «evaluado», o el docente creerá que avanzó.
4. **`Σ peso` calificado = 0 → la parcial es `NULL`, nunca 0.** Ése es exactamente el gris del
   semáforo, y es la diferencia entre *«va en cero»* y *«no hay con qué decirlo»*.

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
| **D3** | Qué pasa al cerrar con lo no calificado | ✅ **Interruptor por colegio: lo elige cada rector.** Las tres salidas —cero, fuera de la cuenta, no dejar cerrar— viven en una columna de `years`. |
| **D6** | Cómo se escribe «sin calificar» | ✅ **`notas.nota` anulable, `NULL` = sin calificar.** Propuesta de Joseth contra la columna `calificada_at` que traía yo. Cambia la fase 0 entera. |
| **D7** | Si «quitar la nota» es `update` con `null` o `destroy` | ✅ **`update` con `nota: null`.** Conserva la fila, su `id`, su bitácora y su historial. `destroy` se queda para lo que de verdad es borrar la fila. |
| **D5** | Si se capturan las fechas de los indicadores | ✅ **No, fuera de alcance.** Las fases 0–4 arreglan los dos síntomas sin pedirle un dato nuevo a nadie. |
| **D2** | El cuarto color gris y desde qué % se apaga | ✅ **Gris por debajo del 15 % evaluado.** No es el mínimo posible —el mínimo sería «sólo con 0 %»— y la razón es el papel: esto **se firma y se archiva**, así que una o dos notas sueltas no bastan para ponerle color a una asignatura delante de una familia. |
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

### Fase 1 — tres números donde hoy hay uno

`DefinitivasDeAsignatura::recalcular` devuelve además **nota parcial** (aportes evaluados ÷ peso
evaluado) y **cobertura** (peso evaluado ÷ peso total). Aditivo: **0 rutas nuevas**, campos nuevos
en la respuesta. Mueve las instantáneas de contrato de boletines y planilla, y ningún cliente se
entera hasta que quiera.

> **La cobertura se calcula sobre el peso, no sobre el número de casillas.** Un indicador del 40 %
> sin calificar y uno del 5 % no dejan el mismo hueco, y contarlos por unidades daría un porcentaje
> que no tiene que ver con lo que puede moverse la nota.

### Fase 2 — el semáforo deja de acusar al alumno

Cuarto color gris, columna de cobertura y rótulo *informe de corte* con el % evaluado. Sólo
`myvc_front/app2` (`cuentas-del-semaforo.ts` y la hoja): **no necesita desplegar la API** si la
fase 1 ya está.

> **Y gana algo que hoy no tiene nadie: delata a la asignatura que no ha reportado.** Hoy un
> docente que no calificó produce treinta rojos y la culpa se lee como del alumno. Con la cobertura
> impresa, esa casilla dice de quién es el silencio. Ése es el aviso que el colegio necesita a
> mitad de periodo, y no estaba en el encargo.

### Fase 3 — lo que ve la familia

La app enseña **la parcial** con su barra y el aviso de periodo en curso. **Es donde se nota**, y
es `myvc_flutter` —una sola app para los dieciséis, así que lo que la rompa los rompe a todos—.
Con D1 decidida no hay interruptor: **0 columnas y 0 rutas nuevas**, sólo los dos clientes.

> **Y aquí el despliegue va en un orden y no en el otro.** La app enseña un campo que la fase 1
> tiene que estar **desplegada** para devolver, colegio a colegio — no fusionada. Una app publicada
> antes que la API deja a los colegios que aún no recibieron el despliegue enseñando un hueco donde
> va la nota, y `myvc_flutter` es una sola app para los dieciséis.

### Fase 4 — el cierre, que es donde el hueco tiene que morir

El diálogo de cierre pregunta qué son las casillas que quedan, con las tres salidas de D3, más los
botones del docente para resolver en bloque y el estado **NE** por celda si entra D4. 2 rutas y
**1** columna en `years` —la de D3—, **con «pasa a cero» de fábrica** por lo dicho en la §5.

> **Ésta es la fase que impide que el arreglo se convierta en un agujero.** Dejar de contar lo no
> calificado **durante** el periodo es correcto; dejar de contarlo **al cerrar** es aprobar a quien
> no entregó. Es literalmente el aviso de Moodle, y por eso el cierre es una fase y no una línea.

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
- **El coste de la consulta.** La parcial y la cobertura son dos `SUM` más sobre las mismas filas
  que ya se recorren, pero eso es un argumento, no una medición: se mide con
  `tools/coste-del-recalculo.php` antes de la fase 1.
- **Quién lee `nota_asignatura` hoy en los cuatro clientes.** La fase 1 no lo cambia, pero la fase
  3 sí decide qué número se pinta, y el radio lo mide el front, no nosotros.
