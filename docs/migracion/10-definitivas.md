# Definitivas: por qué se pierden, por qué se duplican y por qué no se actualizan

`notas_finales` es una **caché** del cálculo `notas × porcentaje de subunidad ×
porcentaje de unidad`, con dos excepciones que no se recalculan: `manual=1` (el
profesor la puso a mano) y `recuperada=1`. Todo lo que sigue sale de que esa
caché se mantiene con el patrón «borra y vuelve a insertar» repetido en seis
sitios distintos, ninguno de ellos transaccional, sobre una tabla que **no tiene
clave única**.

Este documento es el análisis. No hay código escrito todavía; el plan está al
final.

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

Esto no es un fallo del recálculo sino de la fórmula, y **no se arregla poniendo
más disparadores de recálculo**: recalcular más a menudo propaga antes un número
que ya estaba mal. Va en el plan aparte (fase 1 y fase 3).

---

## 6. Otros hallazgos del mismo recorrido

- `Alumnos/Definitivas::calcular_notas_finales_asignatura` y
  `..._periodo` ([Definitivas.php:36,66](app/Http/Controllers/Alumnos/Definitivas.php#L36))
  son dos copias del mismo método **roto**: usan `$alumno_id` y `$asignaturas`
  sin definir, y el INSERT no liga la mitad de sus parámetros. La primera está
  enrutada a través de `putCalcularNotasFinalesAsignatura`, ya documentada como
  rota en [05-codigo-muerto-y-roto.md:271](docs/migracion/05-codigo-muerto-y-roto.md#L271).
  Su DELETE, si llegara a ejecutarse, borra por `manual is null or manual=1` — el
  criterio **invertido**: se llevaría por delante justo las que se pusieron a mano.
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

## 7. Plan

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

### Fase 1 — Un solo recalculador, correcto

Una clase nueva, `App\Services\DefinitivasDeAsignatura`, que sea **el único**
sitio que escribe en `notas_finales`. Con:

- **`UPSERT` en vez de DELETE+INSERT.** `INSERT ... ON DUPLICATE KEY UPDATE`
  sobre la clave `(alumno_id, asignatura_id, periodo_id)`. Desaparecen la ventana
  de borrado, la pérdida por petición muerta y el cambio de `id` en cada carga
  (§3.5).
- **El conjunto de alumnos sale de `matriculas`, no de `notas`.** Es lo que
  arregla la §1.1 y la §1.3: un alumno sin notas recibe su definitiva igual.
- **Se decide qué escribir**: con notas → el cálculo; sin ninguna nota → hay que
  elegir entre `nota = 0` y no escribir fila. **Es una decisión del colegio, no
  mía** — hoy el sistema hace las dos cosas según por dónde entres. Ver §8.
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

Y la fórmula, que es lo de la §5. Dos cosas separadas:

- **Normalizar o no** es decisión del colegio (§8), porque cambia notas ya
  impresas. Lo que sí hay que hacer en cualquier caso es **dejar de calcular en
  silencio sobre porcentajes que no suman 100**: que el servicio devuelva la suma
  real junto a la definitiva, para que la pantalla pueda avisar.
- **Validar al guardar** en `Unidades/SubunidadesController`: hoy aceptan
  cualquier `porcentaje` sin mirar el total. Con un 422 y el total en el mensaje
  —código correcto, aunque el legacy de al lado devuelva 400— el problema deja de
  crearse. Esto vale la pena aunque no se toque nada más.

Esta comprobación es lo que hay que fijar con un test de contrato antes de
apoyarse en ella. **El criterio del §«Tests de contrato» del CLAUDE.md aplica
literal: mirar el resultado, no el estado.** El test que sirve es el viaje de ida
y vuelta: pongo una nota, pido la definitiva, la comparo; borro una nota, pido la
definitiva, la comparo; cambio un porcentaje, ídem. Un test que compruebe que
`nfinal_desactualizada` vale `1` no encuentra nada.

### Fase 2 — Cerrar la base

Migración (no phpMyAdmin — [CLAUDE.md](CLAUDE.md), «migración o no existe»), en
este orden y en la misma migración:

1. Limpiar los duplicados existentes con la regla correcta: **gana la manual; si
   hay dos manuales, la de `id` mayor; si son todas automáticas, la de `id`
   mayor**. Registrar en la bitácora lo que se elimine.
2. Rellenar `periodo` donde esté NULL o desincronizado, desde `periodo_id`.
3. `UNIQUE KEY (alumno_id, asignatura_id, periodo_id)` en `notas_finales`.
4. `UNIQUE KEY (subunidad_id, alumno_id)` en `notas`, previa limpieza de las
   notas duplicadas — decidiendo antes qué hacer con ellas (§8), porque ahí hay
   notas que un profesor escribió.

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
   (§6), moviendo su entrada en `05-codigo-muerto-y-roto.md`.

---

## 8. Lo que no puedo decidir yo

Dos preguntas son del colegio, y las dos cambian el resultado del boletín:

1. **Un alumno sin ninguna nota en una asignatura y periodo, ¿tiene definitiva 0
   o no tiene definitiva?** Hoy el sistema hace las dos cosas según por dónde
   entres: /notas le escribe un 0 (§4, final), el botón de calcular no le escribe
   nada. Afecta al promedio, al puesto y a si la materia aparece como perdida.
2. **Las notas duplicadas de la §2.5, ¿cuál se queda?** Son notas que alguien
   escribió, y hoy **las dos** cuentan en la definitiva. Puede ser la última
   editada, la mayor, o revisarlas una por una si la fase 0 dice que son pocas.
3. **Cuando los porcentajes no suman 100, ¿se normaliza o se deja tal cual?**
   (§5). Normalizar —dividir por la suma real— hace que la definitiva sea siempre
   una nota sobre la escala y que agregar un indicador no la hunda; pero **cambia
   definitivas ya publicadas** en las asignaturas que hoy están descuadradas, y
   puede tapar un error de configuración en vez de mostrarlo. La alternativa es
   no normalizar y **bloquear el guardado** hasta que sumen 100. Lo que no puede
   seguir es la tercera opción, que es la de hoy: calcular mal en silencio.

Mientras no haya respuesta, la fase 1 puede implementarse dejando el
comportamiento actual de cada punto y el sitio marcado, pero conviene resolverlo
antes de la fase 2, porque el índice único de `notas` obliga a elegir. La tercera
es más urgente que las otras dos: **hasta que esté decidida, añadir disparadores
de recálculo propaga más rápido un número que puede estar mal.**
