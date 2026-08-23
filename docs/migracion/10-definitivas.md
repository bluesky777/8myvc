# Definitivas: por qué se pierden, por qué se duplican y por qué no se actualizan

`notas_finales` es una **caché** del cálculo `notas × porcentaje de subunidad ×
porcentaje de unidad`, con dos excepciones que no se recalculan: `manual=1` (el
profesor la puso a mano) y `recuperada=1`. Todo lo que sigue sale de que esa
caché se mantiene con el patrón «borra y vuelve a insertar» repetido en seis
sitios distintos, ninguno de ellos transaccional, sobre una tabla que **no tiene
clave única**.

Este documento es el análisis. No hay código escrito todavía; el plan está al
final.

> **Parado a propósito.** Decidido el 20 ago 2026: esto se hace **cuando termine
> la migración en curso**, no antes. No es duda sobre el plan —las tres
> decisiones de la §9 están tomadas— sino orden de trabajo: toca el cálculo de
> notas, que el §5 del plan de migración protege, y no conviene abrir dos frentes
> sobre lo mismo. Al retomar se empieza por la **fase 0**, la medición.
> Ver [09-pendientes.md §4](09-pendientes.md).

---

## 0. El mapa: quién escribe en `notas_finales`

| Sitio | Cuándo corre | Qué hace |
|---|---|---|
| `Informes/BoletinesController::putDetailedNotas:132` | al abrir/imprimir **un** boletín individual | DELETE masivo + INSERT parcial, **sin comprobar nada** |
| `Models/NotaFinal::calcularAsignaturaPeriodo:248` | al crear/editar/borrar unidad o subunidad (`UnidadesController:221,249`, `SubunidadesController:160,189`) | DELETE masivo + INSERT parcial |
| `NotasController::putDetailed:132-140` | en **cada carga** de la pantalla /notas | DELETE+INSERT por alumno |
| `Models/NotaFinal::alumnos_grupo_nota_final:174-226` | al abrir la pantalla de definitivas | DELETE+INSERT por alumno y periodo, este sí mirando la marca de desactualizada |
| `DefinitivasPeriodosController::putCalcularGrupoPeriodo:92` | los botones «Calcular definitivas per N» | DELETE masivo + INSERT parcial |
| `DefinitivasPeriodosController::putUpdate:181` | el profesor teclea una definitiva | UPDATE si hay `nf_id`, INSERT **incondicional** si no |

Seis escritores, cinco criterios distintos de «qué borro», tres formas distintas
de identificar la fila (`id`, `periodo_id`, `periodo`). Ninguno abre transacción.

---

## 1. Dónde se pierden las definitivas de un periodo

### 1.1 El boletín individual las borra — es la causa principal

[BoletinesController.php:132](app/Http/Controllers/Informes/BoletinesController.php#L132),
con su propio comentario al lado: `// CALCULAMOS SIN VERIFICAR QUE ESTÉ DESACTUALIZADO`.

```sql
DELETE nf FROM notas_finales nf INNER JOIN asignaturas a ON a.id=nf.asignatura_id and a.grupo_id=?
WHERE (nf.manual is null or nf.manual=0) and (nf.recuperada is null or nf.recuperada=0)
  and nf.periodo_id=? and nf.alumno_id=?
```

Borra **todas** las definitivas automáticas de ese alumno en ese grupo y periodo.
El INSERT que viene detrás las repone desde una consulta que lleva
`inner join notas n on n.subunidad_id=s.id and n.deleted_at is null`: **solo
devuelve fila para las asignaturas donde el alumno tiene al menos una nota viva
en ese periodo.** Toda asignatura sin notas registradas pierde su definitiva y no
vuelve.

Tres agravantes:

- **Usa el periodo del usuario que mira, no el del boletín**
  (`$this->user->periodo_id`). Estar en el periodo 2, pasarse al 1 y abrir un
  boletín reescribe las definitivas del periodo 1. **Este es el síntoma que se
  reportó.**
- La ruta es `boletin.propio` ([informes.php:83](routes/api/informes.php#L83)):
  no la dispara solo el coordinador — **el propio alumno o su acudiente, al abrir
  su boletín, reescriben definitivas.**
- No hay transacción. Si la petición muere entre el DELETE y el INSERT —y este
  endpoint recorre alumno × asignatura × periodo—, quedan borradas.

Solo actúa cuando `count($requested_alumnos) == 1`, es decir en el boletín
individual. Encaja con «imprimimos y descubrimos que faltaba calcular».

`Boletines2Controller`, `Boletines3Controller`, `BolfinalesController` y
`NotasActualesAlumnosController` son copias de este controlador y **no** llevan
ese bloque. El daño está en un único sitio.

### 1.2 Editar una unidad o una subunidad las borra

[NotaFinal.php:248](app/Models/NotaFinal.php#L248) — mismo patrón, ahora a nivel
de asignatura entera: borra todas las automáticas de la asignatura y el periodo,
y repone solo las de los alumnos que aparecen en el cálculo, o sea los que tienen
notas. Un alumno sin notas en esa asignatura pierde la definitiva.

Se dispara desde `UnidadesController` y `SubunidadesController`, en update y en
destroy, y lo llaman **los dos clientes**: AngularJS y también Flutter
([UnidadesApi.dart:227,297](../../myvc_flutter/lib/Http/UnidadesApi.dart)), que
es una sola app para los dieciséis colegios.

Además, su INSERT tiene las columnas desalineadas:

```sql
INSERT INTO notas_finales(alumno_id, asignatura_id, periodo_id, periodo, nota, recuperada, manual, created_at, updated_at)
SELECT ... 0 as manual, <user_id> as crea, "<fecha>" as fecha
```

Nueve columnas, nueve valores, pero `updated_by` no está en la lista: **`created_at`
recibe el `user_id`**. La fecha de creación de esas filas es basura, y con MySQL
en modo estricto el INSERT falla directamente.

### 1.3 Los botones «Calcular definitivas per N» tienen el mismo agujero

[DefinitivasPeriodosController.php:92](app/Http/Controllers/DefinitivasPeriodosController.php#L92).
Reparan lo anterior porque corren sobre el grupo entero, pero repiten el patrón:
solo reponen a quien tiene notas. Un alumno sin ninguna nota en una asignatura
sigue sin definitiva **después** de pulsar el botón.

---

## 2. Por qué se duplican

La raíz es una sola: **`notas_finales` no tiene índice único sobre
`(alumno_id, asignatura_id, periodo_id)`**
([mysql-schema.sql:779](database/schema/mysql-schema.sql#L779) — solo hay tres
índices de clave foránea). Nada en la base impide la segunda fila, y los seis
escritores son «comprueba y luego inserta», que no es atómico. Las vías
concretas:

**2.1 El SELECT y el INSERT de /notas no buscan por la misma columna.**
En [NotasController.php:109](app/Http/Controllers/NotasController.php#L109) la
definitiva se localiza por `nf1.periodo = :periodo` — la columna `periodo`, que
es el *número* de periodo — mientras el INSERT de la línea 138 escribe
`periodo_id` **y** `periodo`. Cualquier fila cuyo `periodo` esté a NULL o
desincronizado de `periodo_id` es invisible para el SELECT: `nf_id` sale NULL, el
`DELETE ... WHERE id=NULL` no borra nada, y el INSERT añade una segunda fila.
Desde ahí, el `DB::select(...)[0]` de la línea 128 elige una de las dos
arbitrariamente en cada carga — y por eso el profesor ve dos definitivas
editables.

**2.2 Concurrencia en ese mismo bloque.** DELETE+INSERT sin transacción y sin
único. Dos pestañas de /notas abiertas, o el profesor y el coordinador mirando a
la vez: ambos leen el mismo `nf_id`, uno borra, los dos insertan. Dos filas.

**2.3 `putUpdate` sin `nf_id` inserta a ciegas.**
[DefinitivasPeriodosController.php:181](app/Http/Controllers/DefinitivasPeriodosController.php#L181):
la rama `else` hace un INSERT sin comprobar si la fila ya existe. Y el front la
alcanza: `cambiaNotaDef` manda `{nf_id, nota}`
([NotasCtrl.js:330](../../myvc_front/app/scripts/notas/NotasCtrl.js#L330)) y si
`nf_id` viene `undefined`, cae ahí.

**2.4 El parche no cubre el caso que más duele.**
`getArreglarDuplicados` ([línea 273](app/Http/Controllers/DefinitivasPeriodosController.php#L273))
cuenta solo las filas con `manual=1`. Un duplicado formado por **una manual y una
automática** da `count == 1` y no se limpia — y ese es justo el que deja al
profesor editando dos definitivas. Cuando sí entra, borra por
`(asignatura, alumno, periodo, id != última)` sin mirar `manual`, así que puede
quedarse con la automática y tirar la que se puso a mano. Encima recorre
grupos × alumnos × asignaturas con una consulta por combinación, y su ruta
([academico.php:112](routes/api/academico.php#L112)) no lleva `auth.personal`.

**2.5 `notas` tampoco tiene único** sobre `(subunidad_id, alumno_id)`.
`Nota::verificarCrearNotas` ([Nota.php:69](app/Models/Nota.php#L69)) es otro
`INSERT ... WHERE NOT EXISTS`, y corre en bucle en cada carga de /notas. Dos
cargas simultáneas crean dos notas para el mismo alumno y subunidad. El cálculo
agrupa por `s.id` y suma, así que **las dos notas se suman dentro del grupo y la
definitiva sale inflada**, mientras la pantalla enseña solo una. Es el caso «la
definitiva no cuadra con las notas que veo».

---

## 3. «Puse notas y no aparecen»: tienen razón

### 3.1 `putSubunidad` está roto — no guarda nada

[NotasController.php:366](app/Http/Controllers/NotasController.php#L366). La
cadena está entre comillas **dobles** pero escrita con la sintaxis de
concatenación de las simples:

```php
$consulta = "INSERT INTO notas(...)
        SELECT * FROM
        (SELECT '.$sub_id.' as subunidad_id, '.$alumno->alumno_id.' as alumno_id, ...
```

Lo que llega a MySQL es el texto literal `.$sub_id.`, que en una columna `int` es
`0`, y la clave foránea a `subunidades` rechaza el INSERT. El `WHERE NOT EXISTS`
sí va parametrizado, así que cuando la nota ya existe no se intenta insertar y no
se nota nada; cuando no existe, revienta. Es la nota rápida desde el horario del
día.

### 3.2 El front no revierte el valor cuando falla el guardado

`cambiaNota` y `cambiaNotaDef`
([NotasCtrl.js:383](../../myvc_front/app/scripts/notas/NotasCtrl.js#L383) y
[:324](../../myvc_front/app/scripts/notas/NotasCtrl.js#L324)) sacan un `toastr.error`
y **dejan el número puesto en la pantalla**. El profesor ve 45, el servidor
guarda 30. Cuando vuelve al día siguiente, «la nota desapareció». Tienen razón, y
el toast se lo llevó el navegador hace rato.

### 3.3 La última nota tecleada se pierde al navegar

Todos los inputs llevan
`ng-model-options="{ updateOn: 'default blur', debounce: {'default': 1000, 'blur': 0} }"`
([notas.html:108](../../myvc_front/app/scripts/notas/notas.html#L108)). Si el
profesor teclea y cambia de asignatura, cierra la pestaña o pulsa imprimir antes
de que pase ese segundo **sin sacar el foco del input**, la petición no llega a
salir. No hay cola, ni reintento, ni aviso de «quedan cambios sin guardar».

### 3.4 El backend confunde los dos fallos

En `NotasController::putUpdate`
([línea 282](app/Http/Controllers/NotasController.php#L282)) el `try` envuelve el
UPDATE **y** el INSERT de la bitácora:

- Si la bitácora falla después de un UPDATE correcto → 422 «No se pudo guardar la
  nota» sobre una nota que **sí** se guardó. El profesor la vuelve a poner.
- La primera consulta hace un cross join con `historiales` y toma `[0]`: un
  usuario sin historial abierto revienta ahí, antes de tocar la nota, y recibe el
  mismo 422.

### 3.5 El `nf_id` se queda obsoleto

Como `putDetailed` borra y reinserta la definitiva en cada carga de /notas, el
`id` **cambia en cada visita**. El `nf_id` que el front guarda en memoria de una
carga anterior ya no existe: `putUpdate` hace `DB::select(...)[0]` sobre cero
filas y revienta con 500, que el front traduce a «No pudimos guardar».

---

## 4. La comprobación de «desactualizada» no es correcta

```sql
IF(nf1.updated_at > r1.updated_at, FALSE, TRUE) AS nfinal_desactualizada
-- r1.updated_at = MAX(notas.updated_at) de las notas vivas de esa asignatura y periodo
```

**4.1 Quien más la necesita no la mira.** En
[NotasController.php:135](app/Http/Controllers/NotasController.php#L135) se
calcula `nfinal_desactualizada` y el `if` de al lado la ignora: solo comprueba
`!manual && !recuperada`. La pantalla /notas **recalcula siempre**, para todos los
alumnos, en cada carga. Eso es lo que hoy disimula el problema en esa pantalla, a
costa de reescribir ids sin parar (§3.5) y de un DELETE+INSERT por alumno.

**4.2 Es ciega a los borrados.** Es un `MAX` sobre las notas vivas. Borrar una
nota, una subunidad o una unidad **baja** ese máximo: la definitiva se queda por
encima y se declara al día, con un valor que ya no corresponde.

**4.3 Es ciega a la estructura de la asignatura.** La definitiva no depende solo
de las notas: depende de **qué unidades y subunidades existen y con qué
porcentaje**. Agregar una, eliminarla o cambiarle el peso cambia la definitiva y
**no toca ningún `notas.updated_at`**, que es lo único que la comprobación mira.
Los tres casos:

- *Cambiar un porcentaje* — el sello no se mueve.
- *Eliminar una subunidad o una unidad* — es borrado blando y se lleva las notas
  con ella, así que el `MAX` incluso **baja** (§4.2). Doblemente invisible.
- *Agregar una* — no hay nota nueva que mover el `MAX`, y encima abre el hueco
  de la §5.

Hoy se compensa llamando al recálculo a mano desde
`Unidades/SubunidadesController`, pero solo si el cliente mandó `asignatura_id`,
y ese recálculo es el de la §1.2, que borra definitivas por el camino.
`PeriodosController::copiar` mueve unidades a otro periodo sin llamarlo siquiera.
Cualquier comprobación que se apoye únicamente en `notas.updated_at` es incapaz
de ver esto: el sello tiene que incluir la estructura.

**4.4 Es ciega a los alumnos nuevos.** Un alumno matriculado después no tiene fila
en `r1`, así que `updated_at_def` es NULL y el guardia de
[NotaFinal.php:169](app/Models/NotaFinal.php#L169)
(`&& $alumnos[$i]->updated_at_def_1`) corta el recálculo. Se queda sin definitiva
hasta que alguien le ponga una nota.

**4.5 Resolución de un segundo y comparación estricta.** `timestamp` guarda
segundos; en un empate `>` es falso y se recalcula de más, lo cual es inofensivo.
Lo que no lo es: los dos lados de la comparación se escriben con
`Carbon::now('America/Bogota')` desde PHP, y cualquier desajuste de reloj o de
zona entre caminos invierte el resultado.

**4.6 El `join` está mal acotado.** `r1 ON r1.alumno_id = a.id` une **solo por
alumno**. Lo que acota el periodo es el `inner join periodos p1 on p1.numero = N`
de dentro, que **no filtra por `year_id`**; y la definitiva se localiza por el
número de periodo, no por `periodo_id`. La subconsulta agrupa por
`(alumno_id, periodo_id)`, así que puede devolver más de una fila por alumno y
multiplicar el left join.

**4.7 Inconsistencia entre las cuatro ramas.** En `alumnos_grupo_nota_final`, la
rama del periodo 1 borra con `periodo = 1` (el número) y las de los periodos 2, 3
y 4 con `periodo_id = ?`. Hoy da el mismo resultado porque `asignatura_id` ya ata
el año, pero es la misma ambigüedad de la §2.1 esperando a que una fila tenga
`periodo` a NULL.

**Además:** cuando `def_materia_auto` sale NULL (alumno sin notas), `round(NULL)`
es `0` y se escribe **una definitiva de 0** —
[NotasController.php:139](app/Http/Controllers/NotasController.php#L139). No es
lo mismo «sacó cero» que «no tiene notas».

---

## 5. La fórmula no normaliza: agregar un indicador baja las definitivas

Los seis escritores calculan igual:

```sql
sum( ((u.porcentaje/100) * ((s.porcentaje/100) * n.nota)) )
```

Es una suma de aportes. **No divide por la suma de los porcentajes**, así que solo
da el resultado correcto si las subunidades de cada unidad suman exactamente 100
y las unidades del periodo también. Cuando no, la definitiva sale mal en
silencio: nadie avisa y no hay validación que lo impida — ni en
`SubunidadesController::putUpdate` ni en `UnidadesController::putUpdate`, que
guardan el `porcentaje` que llegue. Lo único que existe es un **informe** de
avance para coordinación que *cuenta* cuántas asignaturas tienen los porcentajes
descuadrados ([ChangeAskedController.php:512](app/Http/Controllers/ChangeAskedController.php#L512)),
y hay que ir a buscarlo.

De ahí salen dos efectos que se notan y que hoy no tienen explicación visible
para el profesor:

**5.1 Agregar un indicador baja las definitivas de todo el grupo.** Al crear la
subunidad, `SubunidadesController` dispara el recálculo de la §1.2 en el acto.
Pero las notas de esa subunidad **no existen todavía**: quien las crea es
`Nota::verificarCrearNotas`, y solo corre al abrir la pantalla /notas en el
navegador ([NotasController.php:47](app/Http/Controllers/NotasController.php#L47)).
Entre las dos cosas, la definitiva se guarda **sin el aporte de la subunidad
nueva** — y si el profesor bajó los porcentajes de las demás para hacerle sitio,
baja el doble. El hueco se cierra solo cuando alguien abre /notas, que es
exactamente la pantalla donde «se arregla al entrar». Desde Flutter, que crea
subunidades pero nunca llama a /notas, el hueco puede durar días.

**5.2 Eliminar un indicador sube o baja según cómo quedaron los porcentajes**, sin
que nadie reajuste el resto ni avise de que ya no suman 100.

**Decidido (§9.3): la fórmula no cambia.** Que los porcentajes descuadrados se
noten en la planilla es la intención, no un descuido — es lo que delata la
asignatura mal configurada. Lo que sí se arregla es la §5.1, que no es un
problema de porcentajes sino una ventana en la que la definitiva no corresponde a
nada: se cierra creando la subunidad y sus notas en la misma transacción
(fase 3), no tocando el cálculo.

---

## 6. Qué pasa hoy cuando falta la fila

Al principio no la tiene nadie, y hoy **no hay ningún punto que la cree a
propósito**: aparece cuando algún recálculo pasa por encima —abrir /notas, editar
una unidad, imprimir un boletín, pulsar el botón—, y ninguno de esos la crea para
el alumno que no tiene notas (§1). Así que «sin fila» no es un estado inicial que
se resuelve solo: es un estado en el que un alumno puede quedarse el periodo
entero.

Lo que hace cada consumidor con esa fila ausente **no es coherente**, y ninguna de
las tres respuestas es «no muestra nada»:

**6.1 El puesto anual la cuenta como cero, sin decirlo.**
[PuestosController.php:38](app/Http/Controllers/Informes/PuestosController.php#L38)
hace `sum(nf.nota)/4` — divide entre 4 siempre, haya cuatro filas o tres. Falta
una definitiva ⇒ **esa materia pierde el 25 % de su nota anual**, y como el puesto
sale de la suma de materias, el alumno baja de puesto por una fila que no está.
(La misma consulta con una definitiva duplicada suma cinco y divide entre cuatro:
sube. Es la §2 llegando al puesto.)

**6.2 La planilla deja al alumno entero fuera — y arrastra al grupo.**
[PlanillasController.php:54](app/Http/Controllers/PlanillasController.php#L54)
usa la definitiva del periodo 1 como **tabla base**:

```sql
FROM notas_finales nf1
left join notas_finales nf2 ... and nf2.periodo=2
...
where nf1.alumno_id=:alu4 and nf1.asignatura_id=:asi4 and nf1.periodo=1
```

Sin definitiva del periodo 1 no hay ninguna fila, y el alumno sale de la planilla
**sin ninguna definitiva, tampoco las de los periodos 2, 3 y 4** que sí existen.
Y el promedio de la asignatura suma solo a los alumnos con fila pero divide entre
`count($alumnos)`, o sea entre todos: **cada alumno sin definitiva del periodo 1
baja el promedio del grupo entero.** Los `left join` de nf2/nf3/nf4 tampoco se
relacionan con nf1 por ninguna columna —solo por parámetros sueltos—, así que un
duplicado en cualquier periodo multiplica las filas y el `[0]` de la línea 62
elige una al azar.

**6.3 El boletín la cuenta como cero y hace perder la materia.**
`CalcPerdidasDefinitivas` calcula `(IFNULL(nf1.nota,0) + IFNULL(nf2.nota,0) +
...)/4`: la definitiva que falta entra como 0 y puede dejar la materia por debajo
del mínimo. Lo mismo en
[NotaFinal.php:167](app/Models/NotaFinal.php#L167), donde `promedio_automatico`
suma los cuatro periodos con los NULL valiendo 0.

**En resumen:** no tener fila hoy no significa «todavía no hay nota», significa
**cero** en el puesto y en el boletín, y **desaparecido** en la planilla. Por eso
la decisión de la §9.1 no es cosmética.

---

## 7. Otros hallazgos del mismo recorrido

- `Alumnos/Definitivas::calcular_notas_finales_asignatura` y
  `..._periodo` ([Definitivas.php:36,66](app/Http/Controllers/Alumnos/Definitivas.php#L36))
  son dos copias del mismo método **roto**: usan `$alumno_id` y `$asignaturas`
  sin definir, y el INSERT no liga la mitad de sus parámetros. La primera está
  enrutada a través de `putCalcularNotasFinalesAsignatura`, ya documentada como
  rota en [05-codigo-muerto-y-roto.md:271](docs/migracion/05-codigo-muerto-y-roto.md#L271).
  Su DELETE borra por `manual is null or manual=1` — el criterio **invertido**: se
  lleva por delante justo las que se pusieron a mano.

  **«Si llegara a ejecutarse» decía esta línea, y sí se ejecutaba** (22 ago 2026):
  es la primera sentencia del método, y el 500 llega después. Medido: 164 → 160
  definitivas y las **cuatro manuales a cero**. El endpoint contesta **410** desde
  esa fecha y no ejecuta nada; el porqué y lo que NO se hizo están en la
  [05 §71](05-codigo-muerto-y-roto.md). La fase 3 sigue siendo la que lo sustituye
  de verdad, y la 5 la que retira la ruta.

  Vale la pena la lección: **«si llegara a ejecutarse» es una hipótesis, y estaba a
  una llamada de comprobarse.** Lo que la mantuvo sin contestar tres días fue que
  el método está documentado como roto, y un roto documentado se lee como inofensivo.
- `CalcPerdidasDefinitivas` ([líneas 18-21](app/Http/Controllers/Informes/CalcPerdidasDefinitivas.php#L18))
  tiene un copia-pega en los joins: `nf2`, `nf3` y `nf4` condicionan por
  `nf1.periodo_id is not null`. Si falta la definitiva del periodo 1, **el boletín
  muestra vacíos los periodos 2, 3 y 4**.
- `PuestosController` calcula el puesto anual con `sum(nf.nota)/4`
  ([línea 38](app/Http/Controllers/Informes/PuestosController.php#L38)): una
  definitiva duplicada **suma dos veces** e infla el puesto. Las consultas por
  periodo usan `avg`, que lo disimula.
- `GET api/definitivas_periodos` y `GET .../arreglar-duplicados` no llevan
  `auth.personal`: las alcanza cualquier sesión válida, incluida la de un alumno.

---

## 8. Plan

El orden importa: mientras el código siga insertando duplicados, poner el índice
único convierte cada duplicado en un error 500. Las fases 1 y 2 se despliegan
juntas.

### Fase 0 — Medir antes de tocar

Una herramienta en `tools/` (con su cabecera de uso, como las demás) que conteste
sobre la base de cada colegio:

1. Cuántos `(alumno_id, asignatura_id, periodo_id)` tienen más de una fila, y de
   qué tipo es cada duplicado (auto+auto, auto+manual, manual+manual).
2. Cuántos `(subunidad_id, alumno_id)` tienen más de una nota viva.
3. Cuántas definitivas discrepan del cálculo, separando «discrepa» de «no
   existe» y de «existe con 0 sin notas detrás».
4. Cuántas filas tienen `periodo` NULL o distinto del `numero` de su `periodo_id`.
5. Cuántas tienen `created_at` imposible (la basura de la §1.2).
6. **Cuántas asignaturas y periodos tienen los porcentajes descuadrados** — las
   unidades que no suman 100 y las unidades cuyas subunidades no suman 100
   (§5). La consulta ya está escrita en
   [ChangeAskedController.php:512](app/Http/Controllers/ChangeAskedController.php#L512);
   aquí hace falta el detalle por asignatura, no el conteo agregado por profesor.
7. **Cuántas subunidades vivas no tienen nota para algún alumno matriculado** —
   el hueco de la §5.1, que mide cuántas definitivas están hoy calculadas de menos.

Sin esto no se sabe si el arreglo hay que acompañarlo de una corrección de datos
ni de qué tamaño. **Antes de optimizar algo: medirlo.**

### Fase 0 — **hecha el 22 ago 2026**

La herramienta es [`tools/salud-de-las-definitivas.php`](../../tools/salud-de-las-definitivas.php)
y contesta las siete preguntas de arriba. Sólo SELECT: la corrección de datos va
en una migración con su registro en la bitácora, no en algo que alguien pueda
lanzar dos veces.

**Medido sobre la copia de desarrollo —un colegio real, todos los años—**, en 61
segundos:

| # | Qué | Cuántos |
|---|---|---|
| 1 | definitivas duplicadas | **1** (auto+auto) |
| 2 | notas duplicadas vivas | **2**, ninguna con valores distintos |
| 3 | definitivas que deberían existir y no existen | **11.988** de 132.865 |
| 3.1 | automáticas que discrepan del cálculo teniendo notas detrás | **718** |
| — | automáticas en 0 sin ninguna nota detrás | 1.073 |
| 4 | `periodo` que no concuerda con `periodo_id` | **0** |
| 5 | `created_at` imposible | **732** |
| 6 | porcentajes que no suman 100 | **14** asignaturas + **26** unidades |
| 7 | (subunidad, alumno) sin nota | **33.076** |

#### Lo que cambia del plan, y lo que lo confirma

**La fase 2 es mucho más barata de lo que parecía.** Un solo duplicado, y de los
que se resuelven sin decidir nada. El índice único se puede poner limpiando una
fila, no un censo — y el orden «fases 1 y 2 juntas» sigue valiendo por el código,
no por los datos.

**El bloque 4 sale en cero, y eso es un dato contra una hipótesis.** La §2 dice
que las tres formas distintas de identificar la fila —`id`, `periodo_id`,
`periodo`— son la mitad de por qué se duplican. En este colegio **`periodo` y
`periodo_id` concuerdan en todas las filas**, así que ese camino no ha producido
ni un duplicado aquí. No lo cierra —el riesgo sigue en el código y otro colegio
puede tener otra cosa—, pero baja su prioridad frente a lo que sí aparece.

**Y lo que sí aparece es el síntoma que se venía reportando.** Los ejemplos del
bloque 3.1, ordenados por diferencia:

```
alumno 1041, asignatura 991, periodo 25 — guardada 0, calculada 24, con 7 notas
alumno  909, asignatura 1297, periodo 31 — guardada 8, calculada 18, con 11 notas
alumno  945, asignatura 1245, periodo 31 — guardada 0, calculada  9, con 8 notas
```

Definitivas **en cero con siete y ocho notas detrás**. Es literalmente «puse
notas y no aparecen» (§3), con el número al lado. Y varias comparten asignatura y
`created_at` al segundo, que es la firma de un recálculo masivo escribiendo el
cero — no de un profesor equivocándose uno a uno.

#### Y una corrección a la §1.2, que se supo por medirla

La §1.2 dice que el INSERT desalineado hace que **`created_at` reciba el
`user_id`**. El mecanismo es ese, pero **lo guardado no es el número**: un entero
no es una fecha válida y, con `'strict' => false` en `config/database.php`, MySQL
no falla — lo convierte en `0000-00-00 00:00:00`. Las 732 filas llevan la fecha
cero y ninguna lleva un id.

La diferencia importa para la limpieza: **no hay ningún `user_id` que recuperar de
ahí**. Y es el mismo `strict => false` que la [13 §1](13-actividades.md) encontró
convirtiendo `null` en cadena vacía — dos capas que eligen callar, otra vez.

#### Lo que falta de esta fase, y necesita a Joseth

Esto está medido en **un** colegio: la copia de desarrollo. Los dieciséis tienen
bases distintas y el plan pregunta por todos. La herramienta ya acepta la base por
entorno:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  (cd "$d" && php tools/salud-de-las-definitivas.php)
done
```

Hasta tener esos dieciséis números **no se sabe si la fase 2 necesita limpieza de
datos o no**, que es justo la pregunta que la fase 0 existe para contestar.

---

### Fase 1 — Un solo recalculador, correcto

Una clase nueva, `App\Services\DefinitivasDeAsignatura`, que sea **el único**
sitio que escribe en `notas_finales`. Con:

- **`UPSERT` en vez de DELETE+INSERT.** `INSERT ... ON DUPLICATE KEY UPDATE`
  sobre la clave `(alumno_id, asignatura_id, periodo_id)`. Desaparecen la ventana
  de borrado, la pérdida por petición muerta y el cambio de `id` en cada carga
  (§3.5).
- **El conjunto de alumnos sale de `matriculas`, no de `notas`.** Es lo que
  arregla la §1.1 y la §1.3, y lo que hace cierta la §9.1: un alumno sin notas
  recibe su definitiva igual, siempre.
- **La fórmula no cambia** (§9.3): suma de aportes, sin normalizar por la suma de
  porcentajes. Junto a la definitiva, el servicio devuelve **la suma real de
  porcentajes** de la asignatura y periodo, para que la planilla pueda señalar la
  que está descuadrada.
- **Respeta `manual` y `recuperada`** en un único punto, no en cinco.
- **Transacción** por asignatura y periodo.
- **Identifica la fila siempre por `periodo_id`**, nunca por `periodo`, y mantiene
  `periodo` como columna derivada.

Y una comprobación de desactualizada que no mienta. La opción barata y correcta:
un `updated_at` en la definitiva contra un **sello de versión** de la asignatura y
periodo, que sea `GREATEST` de:

- `MAX(notas.updated_at)` de las notas vivas,
- `MAX(notas.deleted_at)` de las borradas — cierra la §4.2,
- `MAX(unidades.updated_at)` y `MAX(subunidades.updated_at)`, **y también sus
  `deleted_at`** — cierra la §4.3. Los `deleted_at` no son opcionales: el borrado
  es blando, la fila desaparece del `MAX` de las vivas, y eliminar una unidad es
  justo uno de los casos que hoy no se ve.
- `MAX(matriculas.created_at)` del grupo — cierra la §4.4,
- y los **conteos**: notas vivas, unidades vivas y subunidades vivas. Es lo que
  hace que borrar una y añadir otra dentro del mismo segundo no pase
  desapercibido, y lo que detecta un alta sin notas todavía (§5.1).

Con `>=` en vez de `>` y toda la aritmética de fechas **dentro de MySQL**
(`NOW()`), no repartida entre PHP y la base — cierra la §4.5.

Ojo con una consecuencia de la §9.1: **con la fila siempre presente, la
comprobación deja de servir para «existe o no existe» y pasa a servir solo para
«coincide o no coincide»**, que es para lo que debería haber estado desde el
principio. El guardia de [NotaFinal.php:169](app/Models/NotaFinal.php#L169)
(`&& $alumnos[$i]->updated_at_def_1`, que hoy corta el recálculo del alumno sin
notas) desaparece con ella.

Esta comprobación es lo que hay que fijar con un test de contrato antes de
apoyarse en ella. **El criterio del §«Tests de contrato» del CLAUDE.md aplica
literal: mirar el resultado, no el estado.** El test que sirve es el viaje de ida
y vuelta: pongo una nota, pido la definitiva, la comparo; borro una nota, pido la
definitiva, la comparo; cambio un porcentaje, ídem. Un test que compruebe que
`nfinal_desactualizada` vale `1` no encuentra nada.

#### El estado de la fase 1 al 22 ago 2026 — **escrita y probada, sin cablear**

`App\Services\DefinitivasDeAsignatura` existe, con las cinco reglas de arriba y
el sello de versión, y **no la llama nadie todavía**. Es deliberado: sustituir los
seis escritores es la fase 3 y va detrás de la fase 2, que no se puede desplegar
sola. Escribirla antes hace que lo que llegue a producción llegue ya medido.

La fija `DefinitivasDeAsignaturaTest`, 14 casos, con el criterio que este plan
pedía: **el viaje de ida y vuelta**, ni una sola comprobación de
`nfinal_desactualizada`. Se monta una asignatura con dos unidades al 50% y dos
subunidades al 50% cada una —para que cada nota pese un cuarto y la aritmética se
compruebe de cabeza— y se compara el número escrito con el multiplicado a mano.

**Y ese test encontró un fallo en el propio servicio antes de que existiera
ningún llamante**, que es la razón de escribirlo así:

> El UPSERT hacía `UPDATE` y, si devolvía 0 filas, `INSERT`. **MySQL devuelve 0
> filas afectadas cuando el `UPDATE` no cambia ningún valor**, no cuando no
> encuentra la fila. Recalcular tres veces dentro del mismo segundo —misma nota,
> mismo `updated_at`— dejaba **tres filas**: el fallo de la §2 reintroducido por la
> forma de escribir su propio arreglo.

Se decide ahora por si la fila existe. Lo cazó `test_recalcular_dos_veces_no_duplica`,
que **cuenta filas en la tabla** en vez de mirar lo que devuelve el servicio: un
duplicado no se ve en la respuesta.

Dos apartes del plan, los dos escritos en la clase:

- **Los conteos del sello no hacen falta.** Estaban para que «borrar una y añadir
  otra dentro del mismo segundo» no pasara desapercibido, y eso ya lo coge la
  comparación conservadora —en el empate se recalcula, que la §4.5 dice que es
  inofensivo—. Añadirlos obligaría a guardar el conteo en una columna nueva para
  un caso que el empate ya cubre.
- **El UPSERT no es `ON DUPLICATE KEY UPDATE`** porque la clave única la pone la
  fase 2, y sin ella esa forma no dispara nunca y se comporta como un INSERT a
  secas. Lo que sí consigue ya es que **no haya ventana de borrado**: no existe
  ningún instante en el que la definitiva no esté, que es la mitad de la §1.1.

---

### Antes de la fase 2: los once `INSERT` contra el índice único  *(auditado 2026-08-24)*

**El orden del plan dice «las fases 1 y 2 se despliegan juntas», y eso da por hecho
que la fase 1 sustituyó a los seis escritores. No lo hizo:** el recalculador único
está escrito y probado, pero **sólo lo llama el boletín**. Los demás siguen
insertando, y con el índice único puesto **cada `INSERT` que choque es un 500 en la
pantalla de un profesor**, no un duplicado silencioso.

Auditados los once `INSERT INTO notas_finales` que hay en `app/`:

| Sitio | ¿Protegido? | Qué pasa con el índice |
|---|---|---|
| `Services/DefinitivasDeAsignatura:164` | **sí** — decide por existencia en PHP antes de insertar | nada |
| `Models/NotaFinal:310` (`calcularAsignaturaPeriodo`) | **sí** — `WHERE NOT EXISTS` | nada |
| `DefinitivasPeriodosController:146` | **sí** — `WHERE NOT EXISTS` | nada |
| `Models/NotaFinal:176,191,206,222` (`alumnos_grupo_nota_final`) | **no** | 500 al abrir la pantalla de definitivas |
| `DefinitivasPeriodosController:224` (`putUpdate`, rama sin `nf_id`) | **no** | **500 al teclear una definitiva** |
| `NotasController:133` (`putDetailed`) | **no** | 500 al abrir /notas |
| `Alumnos/Definitivas:53,83` | **no** | código muerto — la fase 5 lo borra entero |

**Los cuatro de `alumnos_grupo_nota_final` y el de `putUpdate` son los que
importan**, y los dos por el mismo motivo: sus `DELETE` previos **excluyen
`manual` y `recuperada`** —a propósito, para no pisar lo que puso un profesor— y
después el `INSERT` repone la fila automática **del mismo alumno cuya manual se
acaba de conservar**. Hoy eso produce el duplicado auto+manual que la §2 describe;
mañana produce un error de clave duplicada.

`putUpdate` es el peor de los cinco porque **es el que teclea el profesor**: su
rama sin `nf_id` hace un `INSERT` incondicional, y el front la usa justo cuando no
tiene el `nf_id` a mano (§2.3) — que es exactamente cuando la fila puede existir ya.

#### Lo que esto cambia del plan

**La fase 2 no puede ir antes que la fase 3, y no es una preferencia de orden: es
que el índice convierte cinco pantallas en 500.** El orden bueno es:

1. **Fase 3 primero** —o al menos los cinco `INSERT` sin guarda—, sustituyéndolos
   por el recalculador único o dándoles la misma decisión por existencia que ya
   tienen los otros tres.
2. **Fase 2 después**: limpiar, rellenar y poner los dos índices.

Lo que no cambia es que **las dos tienen que llegar juntas a cada colegio**: el
índice sin el código nuevo rompe, y el código nuevo sin el índice deja el UPSERT de
la fase 1 comportándose como un INSERT a secas (ya escrito en la fase 1).

> **La comprobación que faltaba no era sobre los datos, era sobre el código.** La
> fase 0 midió la tabla y encontró **un** duplicado, o sea «la fase 2 es barata».
> Y lo es, en datos. Lo caro estaba en los once sitios que siguen escribiendo, que
> no los mira ninguna consulta.

#### Dónde se recalcula hoy, y dónde no

De los siete disparadores que lista la fase 3, **hay uno cableado**:

| Disparador | Estado |
|---|---|
| `BoletinesController::putDetailedNotas` | **hecho** — comprueba y recalcula, ya no borra |
| `NotasController::putUpdate` — al editar una nota | **hecho el 24 ago** — era la petición de origen, y el sitio donde iba tenía un `if` **vacío** |
| `NotasController::deleteDestroy` — al borrarla | **hecho el 24 ago** — no estaba ni en la lista, y quitar una nota cambia la definitiva igual |
| `NotasController::putSubunidad` | **hecho el 24 ago**, con la §3.1 arreglada: no guardaba nada **y estaba interpolada** |
| `Unidades`/`SubunidadesController` | **hecho el 24 ago** — las cuatro, y **sin depender de `asignatura_id` del cuerpo** |
| `PeriodosController::putCopiar` | **hecho el 24 ago** |
| `NotasController::putDetailed` — cada carga de /notas | **hecho el 24 ago** — pregunta por el sello antes de escribir |
| Crear la subunidad y sus notas en la misma transacción | **falta** — es lo único que queda de la fase 3, y es lo que cierra la §5.1 |

> **Lo que la fase 3 le compra a la fase 2, contado en `INSERT`:** al sustituir
> `putDetailed` y las cuatro llamadas al calculador viejo, **cinco de los seis
> `INSERT` sin guarda en pantallas vivas desaparecen**. Queda uno:
> `DefinitivasPeriodosController::putUpdate`, la rama sin `nf_id` — **el profesor
> tecleando una definitiva**— más los cuatro de `NotaFinal::alumnos_grupo_nota_final`,
> que sustituye lo que queda de la fase 3.
>
> Y un aparte que no estaba en el plan: **`putUpdate` de notas no dependía sólo de
> que no hubiera recálculo, sino de que el cliente mandara `asignatura_id`.** Las
> cuatro llamadas de unidades y subunidades llevaban `if (Request::input('asignatura_id'))`
> delante: si el front no lo mandaba —y no siempre lo manda— **el peso cambiaba y
> la definitiva no**, en 200 y sin avisar. Ahora el controlador lo saca de la
> propia unidad, que es quien lo sabe.

### Fase 2 — Cerrar la base

Migración (no phpMyAdmin — [CLAUDE.md](CLAUDE.md), «migración o no existe»), en
este orden y en la misma migración:

1. Limpiar los duplicados de `notas_finales`: **gana la manual; si hay dos
   manuales o ninguna, la de `id` mayor** (§9.2). Registrar en la bitácora lo que
   se elimine.
2. Limpiar los duplicados de `notas`: **gana la nota más alta; en empate, la de
   `id` mayor** (§9.2). También a la bitácora — son notas que escribió un
   profesor, y hoy las dos cuentan en la definitiva, así que **limpiarlas cambia
   definitivas**. Conviene correr la fase 0 justo antes para saber a cuántas
   afecta.
3. Rellenar `periodo` donde esté NULL o desincronizado, desde `periodo_id`.
4. `UNIQUE KEY (alumno_id, asignatura_id, periodo_id)` en `notas_finales`.
5. `UNIQUE KEY (subunidad_id, alumno_id)` en `notas`.
6. Rellenar las filas que faltan (§9.1): un alumno matriculado sin definitiva en
   alguna asignatura y periodo la recibe, calculada con el servicio de la fase 1.
   Es lo que devuelve a la planilla a los alumnos que hoy no salen (§6.2) y
   deshace los ceros del puesto (§6.1).

Ojo con el despliegue: son dieciséis bases, y `app/` es copia por colegio.
El único índice y el código que lo respeta tienen que llegar **juntos** a cada
colegio; el índice solo, con el código viejo, convierte los duplicados en 500.
Ver [DESPLIEGUE.md](docs/DESPLIEGUE.md).

### Fase 3 — Recalcular donde de verdad hay que recalcular

Con el recalculador de la fase 1 siendo un UPSERT barato y correcto:

- **`NotasController::putUpdate`** (editar una nota) → recalcular esa asignatura
  y periodo. Es lo que se pidió: la definitiva se actualiza al modificar la nota.
- **`NotasController::putSubunidad`**, una vez arreglada la §3.1 → ídem.
- **`Unidades/SubunidadesController`** → sustituir la llamada actual, y **dejar de
  depender de que el cliente mande `asignatura_id`**: el controlador lo sabe por
  el `id` de la unidad. Es el disparador de los tres casos de la §4.3 —
  agregar, eliminar y cambiar el porcentaje— y hoy es también el que borra
  definitivas por el camino (§1.2).
- **Crear la subunidad y crear sus notas en la misma operación**, dentro de la
  misma transacción, en vez de esperar a que alguien abra /notas. Es lo que cierra
  la §5.1: mientras el alta de una subunidad y el alta de sus notas estén
  separadas, cualquier recálculo que caiga en medio guarda una definitiva de
  menos. Vale para los dos clientes, y es lo que hace segura la vía de Flutter.
- **`PeriodosController::copiar`** → recalcular el periodo destino: hoy mueve
  unidades sin avisar a nadie (§4.3).
- **`BoletinesController::putDetailedNotas`** → quitar el bloque de la §1.1 y
  poner en su lugar «recalcular si está desactualizada», con la comprobación
  nueva. Un boletín no puede seguir borrando definitivas, y menos abierto por un
  acudiente.
- **`NotasController::putDetailed`** → dejar de recalcular a lo bruto en cada
  carga; recalcular solo si el sello dice que hace falta.

Medir el coste: `putUpdate` se llama una vez por nota tecleada, y el recálculo es
una consulta agregada sobre la asignatura. Si sale caro, la salida no es dejar de
recalcular sino recalcular **solo la fila de ese alumno**, que es lo que cambió.
Con `ConsultasPorPeticionTest` ya hay dónde fijarlo.

### Fase 4 — El frontend

- **Revertir el valor cuando falla el guardado** (§3.2): guardar el valor previo
  antes de llamar, restaurarlo en el `catch` y marcar la celda. Es el arreglo de
  más valor por línea de todo el plan.
- **No perder la última nota** (§3.3): forzar el guardado pendiente antes de
  cambiar de asignatura, de estado o de cerrar la pestaña
  (`$transitions.onBefore` y `beforeunload`), y un indicador de «sin guardar» por
  celda.
- **Refrescar la definitiva tras guardar una nota**: si la fase 3 la recalcula, la
  pantalla debe reflejarlo sin recargar.
- **`cambiaNotaDef` sin `nf_id`** (§2.3): que mande `alumno_id`, `asignatura_id` y
  `periodo_id`, y que el backend haga UPSERT en vez de INSERT ciego.
- Revisar los mismos patrones en `NotaRapida.js` y `CambiarNotaModalCtrl.js`, que
  guardan por el mismo camino.

Flutter no escribe notas —`MisNotasScreen` es de solo lectura—, pero **sí** crea y
borra unidades y subunidades, así que hereda la fase 3 sin cambios en la app. Aun
así: **el guard nuevo tiene que estar desplegado en los dieciséis colegios antes
de tocar el front**, por lo del §Despliegue.

### Fase 5 — Quitar los botones

Solo cuando las fases 1-4 estén desplegadas y la fase 0 se pueda volver a correr
enseñando cero discrepancias durante un periodo completo:

1. Convertir «Calcular definitivas per N» en una herramienta de mantenimiento
   fuera de la pantalla de Informes, no en un paso del trabajo diario.
2. Borrar `getArreglarDuplicados` y su enlace: con el índice único de la fase 2
   deja de tener sentido. **Con ruta y roto se documenta, sin ruta y roto se
   borra** — aquí se borran ruta y método a la vez, y este documento queda como
   el registro de qué hacía y por qué existió.
3. Borrar `Alumnos/Definitivas` entero y `putCalcularNotasFinalesAsignatura`
   (§7), moviendo su entrada en `05-codigo-muerto-y-roto.md`.

---

## 9. Decisiones tomadas

Las tres preguntas abiertas están resueltas. **No se re-litigan.**

### 9.1 La fila existe siempre que exista la matrícula

Todo alumno matriculado tiene fila en todas sus asignaturas y periodos, desde el
principio. Se crea **con las notas de las subunidades**, en la misma transacción
(fase 3), y el recalculador la crea igualmente para quien no tenga ninguna nota:
el conjunto de alumnos sale de `matriculas`, nunca de `notas`.

El porqué está en la §6: hoy «sin fila» no quiere decir «todavía no hay nota». En
el puesto anual vale **cero** —`sum/4` divide entre cuatro siempre—, en el boletín
vale **cero** y puede hacer perder la materia, y en la planilla **borra al alumno
de la lista** y baja el promedio de su grupo. Un estado que tres informes
interpretan de tres maneras distintas no es un estado, es un fallo esperando
turno. Con la fila siempre presente, «no hay nota» queda representado por el
único sitio que puede representarlo sin ambigüedad: las notas de las subunidades,
que sí existen o no existen.

### 9.2 Entre notas duplicadas, gana la más alta

Para las notas duplicadas de la §2.5: **se conserva la de nota más alta**, y en
empate la de `id` mayor. Se aplica en la limpieza previa al índice único de la
fase 2, y lo que se elimine queda registrado en la bitácora.

Vale la pena no confundir las dos limpiezas de la fase 2, porque son tablas
distintas y criterios distintos:

| Tabla | Duplicado sobre | Gana |
|---|---|---|
| `notas` | `(subunidad_id, alumno_id)` | la **nota más alta**; en empate, `id` mayor |
| `notas_finales` | `(alumno_id, asignatura_id, periodo_id)` | la **manual**; si hay dos manuales o ninguna, `id` mayor |

En `notas_finales` la regla no puede ser «la más alta»: la marca `manual` es una
decisión del profesor, y quedarse con la automática por ser mayor la borraría.

### 9.3 No se normaliza: los porcentajes malos se ven en la planilla

La definitiva se calcula tal cual, aunque los porcentajes no sumen 100, **salvo
que sea manual**. Esa es la intención: que el número raro aparezca en la planilla
y delate la asignatura mal configurada.

Consecuencias que se aceptan y que conviene tener escritas:

- La fórmula se queda como está — suma de aportes, sin dividir por la suma real.
  Ninguna definitiva ya impresa cambia por este motivo.
- **No se bloquea el guardado** de un porcentaje que descuadre. La propuesta de
  validar con 422 en `Unidades/SubunidadesController` **queda descartada**: sería
  justo lo que impide que el error llegue a la planilla.
- Lo que sí se hace es que el servicio devuelva **la suma real de porcentajes**
  junto a la definitiva, para que la planilla pueda señalar la asignatura en vez
  de obligar a deducirlo de una nota rara. Es información añadida, no un cambio
  de cálculo ni un bloqueo.
- La §5.1 sigue siendo un fallo aunque no se normalice, y se arregla igual: que
  al agregar un indicador la definitiva baje **durante días** hasta que alguien
  abra /notas no es un error de porcentajes que se quiera ver, es una ventana en
  la que el número no corresponde a nada. Lo cierra la creación conjunta de la
  subunidad y sus notas (fase 3), no la fórmula.
