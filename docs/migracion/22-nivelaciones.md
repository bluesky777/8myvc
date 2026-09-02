# 22 — Nivelaciones: el contrato

**Escrito el 2 sep 2026**, el primer día del reparto en tres sesiones que describe
`myvc_front/TAREAS-NIVELACIONES-Y-RUBRICAS.md` (§3: *«lo primero que hace A, antes de
escribir una línea de implementación, es publicar el contrato»*). El *qué* y el *por qué*
están en `myvc_front/PLAN-NIVELACIONES-Y-RUBRICAS.md`, decidido con Joseth ese mismo día;
esto es **la forma exacta de lo que el backend va a contestar**, para que el front de
nivelación (sesión B) construya entero contra un doble sin esperar a que exista el código.

> **Este documento es el contrato, no una descripción del código.** Mientras no haya
> implementación, lo que dice aquí es lo que va a haber; cuando la haya, un test de contrato
> por endpoint lo ata. **Cambiarlo es avisar**: cualquier desviación se escribe aquí primero
> y se le dice a la sesión B, nunca se cambia en el controlador y se deja que el doble se
> entere al integrar.
>
> Las formas de abajo **no se inventaron: se calcaron** de `PUT notas/update/{id}` y de
> `PUT notas/lote`, que son los dos endpoints que el front ya sabe leer. Donde este contrato
> se parece a ellos es a propósito, y donde se aparta lo dice.

---

## §0 — Lo que ya está decidido y aquí no se re-litiga

Las nueve decisiones de la §4 del plan. Las que gobiernan este documento:

| | Decisión | Consecuencia en el contrato |
|---|---|---|
| 5 | **`notas.nota` sigue siendo la vigente**; la nueva columna es `nota_original` | Ningún lector existente cambia. Los campos nuevos son **añadidos**, nunca renombrados |
| 6 | **La regla se aplica al escribir** | El backend calcula qué queda y lo devuelve; el front pinta, **no calcula** —salvo la previsualización del diálogo, §1.4— |
| 7 | `nota_original` es **editable** | «Corregir la valoración inicial» sigue yendo por `notas/update` en una celda **sin** nivelar; en una **ya nivelada** va por `PUT notas/nivelar/{id}` con `nota_original` (§1.6), porque `notas/update` escribe la vigente |
| 8 | Nivelar usa `periodos.profes_pueden_nivelar` | La guarda es la que hoy tiene `pueden_modificar_definitivas`, y **es por periodo de la nota**, no del token |
| 3 | **Un intento por indicador** | Repetir el `PUT` sobre una nota ya nivelada **sustituye** la nivelación, no añade otra (§1.3) |

Y la regla de despliegue que decide la forma de todo: **`notas/update` y `notas/lote` no
cambian ni una línea de comportamiento** (§6.1 del reparto). `myvc_flutter` es una sola app
para los quince colegios y una versión vieja convive con este backend durante meses. Por eso
nivelar son **endpoints nuevos**, y por eso el test centinela de esos dos endpoints se
escribe **con** los nuevos, no después.

---

## §1 — `PUT notas/nivelar/{id}` — registrar la nivelación de un indicador

**Guard de ruta:** `auth.token` + `auth.personal`, como toda la familia `notas/`.
**Guard de método:** `profes_pueden_nivelar` **del periodo de la nota** (resuelto por
`PeriodoDeLaFila::deNota`, como hace `notas/update` con el suyo), que es lo que ya comprueba
`User::pueden_modificar_definitivas`. Un superusuario pasa siempre; un profesor pasa si el
interruptor de ese periodo está encendido; nadie más pasa.

### §1.1 El cuerpo

```json
PUT api/notas/nivelar/8811
{
  "nota_nivelacion": 90,
  "observacion": "Taller de refuerzo y sustentación oral, 28 ago",
  "fecha": "2026-08-28"
}
```

| Campo | Tipo | Obligatorio | Qué es |
|---|---|---|---|
| `nota_nivelacion` | entero | **sí** | Lo que el estudiante obtuvo **en la superación de debilidades**. Tiene que caber en la escala del colegio (`EscalaDeNotas`), igual que en `notas/update` |
| `observacion` | cadena ≤ 255 o `null` | no | La actividad. Va a `notas.nivelacion_obs`. Vacía o ausente = `null` |
| `fecha` | `YYYY-MM-DD` o `YYYY-MM-DD HH:MM:SS`, o `null` | no | La fecha **del acta**: cuándo se hizo la nivelación. Ausente = ahora (Bogotá). Es la que va a `nivelada_at`; **la auditoría lleva su propia hora de servidor aparte**, así que datar el acta al 28 no borra que se registró el 2 |

**Lo que NO lleva el cuerpo**: ni `alumno_id`, ni `asignatura_id`, ni `periodo_id`. La nota
sabe de quién es y de qué periodo; pedirlo al cliente sería un id por el cuerpo que nadie
comprueba (`tools/identificadores-del-cuerpo.py`), y es la misma decisión que tomó
`notas/update` al quitarle `asignatura_id`.

### §1.2 La respuesta cuando va bien — `200`

```json
{
  "id": 8811,
  "alumno_id": 4021,
  "subunidad_id": 1187,
  "nota": 70,
  "nota_original": 55,
  "nota_nivelacion": 90,
  "nivelada_at": "2026-08-28 00:00:00",
  "nivelada_por": 17,
  "nivelada_por_username": "mgarcia",
  "nivelacion_obs": "Taller de refuerzo y sustentación oral, 28 ago",
  "updated_at": "2026-09-02 10:41:07",
  "regla_aplicada": {
    "regla": "topada",
    "nota_minima": 70,
    "explicacion": "Regla del colegio: la nivelación se topa en la mínima aprobatoria (70). Queda 70."
  },
  "definitiva": {
    "alumno_id": 4021,
    "asignatura_id": 233,
    "periodo_id": 41,
    "nota": 71.25,
    "manual": false,
    "recuperada": false
  }
}
```

Campo a campo, y por qué está:

- **`nota`** es **la vigente**, ya con la regla aplicada. Es lo que va al boletín y lo que la
  celda pinta en grande. Bajo `topada` no coincide con `nota_nivelacion`: ese es el caso
  normal, no un error.
- **`nota_original`** es lo que había antes de nivelar. **Nunca es `null` en esta respuesta.**
- **`nota_nivelacion`** se guarda y se devuelve **aunque la regla no la deje en `nota`**. El
  plan (§3.2) no tenía esta columna y hacía falta: bajo `topada`, un 90 que queda en 70
  desaparecería del sistema, y bajo `mayor`, un 40 que no supera al 55 no dejaría rastro de
  qué sacó el estudiante. El art. 16 del 1290 pide el «estado de la evaluación con sus
  novedades»; una nivelación cuyo resultado no está escrito en ninguna parte no es una
  novedad registrada. **Es una columna más en la migración (A3), y va avisada al plan.**
- **`nivelada_at` / `nivelada_por` / `nivelacion_obs`** son el acta. `nivelada_por` es el id
  de `users` (el `user_id` del token, el mismo que va en `updated_by`), y
  **`nivelada_por_username`** viene al lado para que el pie del diálogo no tenga que ir a
  buscarlo: es la misma convención que `nota_final.updated_by_username` en `notas/detailed`.
- **`regla_aplicada`** lleva la regla del año **tal como estaba al escribir**, la mínima con la
  que se calculó y una frase hecha. La frase es para el mensaje de confirmación; el diálogo
  la previsualiza antes de guardar con la misma tabla de la §1.4, y **después de guardar
  pinta la del servidor**, no la suya.
- **`definitiva`** tiene **exactamente la forma de `notas/update`** (`notas-update.json`), y
  por la misma razón: la planilla repinta la columna de definitiva sin otra petición. Puede
  venir **`null`** con el 200 —cuando el alumno no tiene fila en `notas_finales`—, igual que
  allí. Y **si trae `manual: true` o `recuperada: true`, esta nivelación NO la movió**: es la
  interacción de la §3.4 del plan, y la pantalla lo avisa **antes** de guardar leyendo esos
  mismos dos booleanos de `nota_final` en `notas/detailed` (§3), no después leyendo esto.
- **No vienen** `created_by`, `deleted_*`, `created_at` ni `history_id`, que `notas/update` sí
  devuelve. Aquél devuelve la fila entera porque siempre lo hizo y quitarle una clave a cuatro
  clientes es un cambio de contrato; éste nace hoy y devuelve lo que la pantalla usa.

### §1.3 Repetir el `PUT` sobre una nota ya nivelada

**Sustituye la nivelación; no la apila.** Es la decisión 3 del plan («un intento por
indicador») llevada al endpoint, y lo que hace que un docente que tecleó 80 queriendo
teclear 85 no tenga que borrar y volver a nivelar:

- `nota_original` **se conserva** —no se pisa con la `nota` vigente, que ya era nivelada—.
- La regla se aplica otra vez **desde `nota_original`** con la `nota_nivelacion` nueva.
- `nivelada_at`, `nivelada_por`, `nivelacion_obs` y `nota_nivelacion` se reescriben.
- La auditoría registra **una línea nueva** con el valor anterior y el nuevo, así que la
  primera nivelación no se pierde: deja de estar en `notas` y pasa a estar en `auditoria`,
  que es donde viven los cambios.

Responde igual que la primera vez, `200` con la forma de la §1.2.

### §1.4 Las tres reglas, y qué queda en `nota`

Es la tabla de la §3.5 del plan, con `nota_minima_aceptada` del año (**se lee como entero**;
la columna es `varchar` y vale `'70'` por defecto). El front la usa para **previsualizar**
en el diálogo; el backend la aplica **al escribir** y lo que manda en `regla_aplicada` manda.

| `regla` | qué queda en `nota` | 55 → niveló 90 | 55 → niveló 40 | 55 → niveló 65 |
|---|---|---|---|---|
| `topada` *(defecto)* | si `nivelacion ≥ mínima`: **`mínima`**; si no: `nivelacion` tal cual | **70** | **40** | **65** |
| `mayor` | `max(original, nivelacion)` | **90** | **55** | **65** |
| `reemplaza` | `nivelacion`, sin comparar | **90** | **40** | **65** |

> Ojo con `topada` y una nivelación **por debajo** de la original —el 55 que niveló 40—: la
> tabla del plan dice *«si no aprueba, la nivelación tal cual»*, y eso es lo que hace: queda
> **40**. No se corrige por detrás porque la regla la escribe el SIEE, no el sistema; si un
> colegio quiere «nunca por debajo de la original», eso es `mayor`. El diálogo enseña el
> resultado **antes** de guardar precisamente para que el docente lo vea.

Las frases de `regla_aplicada.explicacion`, para que el doble las tenga iguales:

| `regla` | `explicacion` |
|---|---|
| `topada` | `Regla del colegio: la nivelación se topa en la mínima aprobatoria (70). Queda 70.` |
| `mayor` | `Regla del colegio: queda la mayor de las dos. Queda 90.` |
| `reemplaza` | `Regla del colegio: la nivelación reemplaza la valoración inicial. Queda 40.` |

### §1.5 Lo que contesta cuando no va bien

Códigos correctos, porque es código nuevo (regla del repo), **aunque los endpoints viejos
de al lado contesten 400 para todo**. El cuerpo del error es el de Laravel:
`{"message": "…"}`.

| Código | Cuándo | `message` |
|---|---|---|
| **403** | El interruptor `profes_pueden_nivelar` del periodo de la nota está apagado, o quien llama no es profesor ni superusuario | `No tienes permiso para nivelar en este periodo.` |
| **404** | No hay nota con ese id, está borrada, o su indicador o su unidad ya no están | `No existe la nota, o su indicador ya no está.` |
| **422** | `nota_nivelacion` ausente o no numérica | `Hace falta nota_nivelacion.` |
| **422** | `nota_nivelacion` fuera de la escala del colegio | el motivo de `EscalaDeNotas::motivoSiNoCabe`, tal cual |
| **422** | `observacion` de más de 255 | `La observación no puede pasar de 255 caracteres.` |
| **422** | `fecha` que no se puede leer, o futura | `La fecha de la nivelación no es válida.` |

> **El 403 y no el 400 del guard viejo.** `User::pueden_modificar_definitivas` contesta 400
> hoy y **no se toca** —lo usan cinco métodos de definitivas que Flutter llama—. Los
> endpoints de nivelar comprueban lo mismo y contestan 403. El front que ya distingue
> «400 del guard» en `notas/lote` no tiene que aprender nada nuevo: aquí ese caso es 403.

**Nada se escribe en ningún error.** Ni en `notas`, ni en `auditoria`, ni en `bitacoras`: la
comprobación de permiso y las de forma van **antes** de la primera escritura, y el 404 de la
nota también.

### §1.6 Corregir la valoración inicial de una celda YA nivelada

Es el hueco que apareció al escribir A3, y B lo necesita para el segundo botón del
diálogo (§5.2 del plan). En una celda **sin** nivelar, «corregir» es `PUT notas/update/{id}`
y nada cambia. En una celda **nivelada**, `notas/update` escribe en `nota` —**que es la
vigente**, no la original—, así que corregir por ahí un teclazo de la original lo que hace
es pisar el resultado de la nivelación. No hay forma de que ese endpoint lo sepa sin
cambiarle el comportamiento, y eso es lo que el §6.1 prohíbe.

Se corrige por el mismo endpoint de nivelar, con **`nota_original` en el cuerpo**:

```json
PUT api/notas/nivelar/8811
{ "nota_original": 58 }
```

- Sólo vale en una nota **ya nivelada**; en una sin nivelar contesta **422**
  `Esta nota no está nivelada: la valoración inicial se corrige con notas/update.`
- Puede ir sola o junto a `nota_nivelacion` / `observacion` / `fecha`. Con lo que llegue,
  el backend **vuelve a aplicar la regla** desde la original nueva y la nivelación que
  tenga (la del cuerpo o la guardada), y `nota` queda como diga la regla.
- Responde con la forma de la §1.2.
- Auditoría: una línea **`editar`** —no `nivelar`— con `de` = la original vieja y `a` = la
  nueva, y en `resumen` «valoración inicial corregida; queda N por regla X». Es corrección,
  y como corrección se registra: es la §1.2 del plan cumplida donde más fácil era
  romperla.
- Pasa por la misma escala y por el mismo guard que la nivelación.

### §1.7 Lo que deja escrito, para quien lo lea después

- `notas`: `nota`, `nota_original`, `nota_nivelacion`, `nivelada_at`, `nivelada_por`,
  `nivelacion_obs`, `updated_at`, `updated_by`.
- `auditoria`: una línea con acción **`nivelar`** (vocabulario nuevo, al lado de
  `crear/editar/borrar/restaurar/denegado`), entidad `nota`, `de` = la vigente anterior,
  `a` = la vigente nueva, y en `resumen` la nivelación tecleada y la regla
  (`nivelación 90, regla topada`). **Es una acción distinta de `editar` a propósito**: la
  §1.2 del plan es que corrección y nivelación no se confundan, y la pantalla de auditoría
  filtra por acción.
- `bitacoras`: **una línea igual que la de `notas/update`** (`"Al"`, `"Nota"`, valor viejo y
  nuevo). Es lo que lee el historial de la app hoy, y una nivelación no puede dejar un rastro
  distinto del que deja teclear la nota.
- La definitiva del par (asignatura, periodo) de ese alumno **se recalcula** con
  `DefinitivasDeAsignatura::recalcularPorNota`, que respeta `manual` y `recuperada`.

---

## §2 — `DELETE notas/nivelar/{id}` — quitar una nivelación

Mismos guards que el `PUT`. **Es la vuelta atrás del §6.5 del reparto**: el docente que
niveló cuando quería corregir.

Sin cuerpo. Deja la nota como estaba antes de nivelar:

- `nota` ← `nota_original`.
- `nota_original`, `nota_nivelacion`, `nivelada_at`, `nivelada_por`, `nivelacion_obs` ← `null`.
- `updated_at` / `updated_by` se mueven, porque la fila cambió.
- Auditoría: acción **`quitar_nivelacion`**, entidad `nota`, `de` = la vigente nivelada,
  `a` = la original restaurada. Bitácora igual que arriba.
- La definitiva se recalcula, con el mismo respeto a `manual` / `recuperada`.

**Respuesta `200`, con la misma forma que el `PUT`** —para que el front tenga **un solo tipo**
para las dos—, con los cinco campos del acta en `null`:

```json
{
  "id": 8811,
  "alumno_id": 4021,
  "subunidad_id": 1187,
  "nota": 55,
  "nota_original": null,
  "nota_nivelacion": null,
  "nivelada_at": null,
  "nivelada_por": null,
  "nivelada_por_username": null,
  "nivelacion_obs": null,
  "updated_at": "2026-09-02 10:52:30",
  "regla_aplicada": null,
  "definitiva": { "alumno_id": 4021, "asignatura_id": 233, "periodo_id": 41, "nota": 62.5, "manual": false, "recuperada": false }
}
```

| Código | Cuándo | `message` |
|---|---|---|
| **403** | como en el `PUT` | `No tienes permiso para nivelar en este periodo.` |
| **404** | no hay nota viva con ese id | `No existe la nota, o su indicador ya no está.` |
| **409** | la nota existe pero **no está nivelada** (`nota_original IS NULL`) | `Esta nota no tiene ninguna nivelación que quitar.` |

**409 y no 200 vacío.** Un `DELETE` que contesta 200 sobre algo que no existía es una de las
respuestas que mienten (`tools/respuestas-que-mienten.py`): el front pintaría «nivelación
retirada» sobre una celda que nunca la tuvo.

---

## §3 — Los campos nuevos en `PUT notas/detailed`

**Todos añadidos, ninguno renombrado, y todos `null` mientras no haya nivelación.** El
snapshot `notas-detailed-profesor.json` se regenera con ellos —es un cambio de contrato y
por eso está en este documento y va avisado a los cuatro clientes—, pero el front viejo y
Flutter **no se enteran**: leen por clave y las suyas siguen ahí.

### §3.1 En cada `alumnos[].notas[]`

```json
{
  "id": 8811, "nota": 70, "subunidad_id": 1187, "alumno_id": 4021,
  "created_by": 17, "updated_by": 17, "deleted_by": null, "deleted_at": null,
  "created_at": "…", "updated_at": "…", "asignatura_id": 233,
  "subunidad_porc": "0.2500", "unidad_porc": "0.4000", "definicion": "…",
  "subunidad_porcentaje": 25, "orden_unidad": 1, "orden_subunidad": 2,

  "nota_original": 55,
  "nota_nivelacion": 90,
  "nivelada_at": "2026-08-28 00:00:00",
  "nivelada_por": 17,
  "nivelada_por_username": "mgarcia",
  "nivelacion_obs": "Taller de refuerzo y sustentación oral, 28 ago"
}
```

Las seis claves nuevas van **siempre**, con `null` cuando la nota no está nivelada. Es la
misma decisión que tomó `notas/lote` con `definitivas`: una clave que a veces no viene
obliga al front a distinguir «vacío» de «no vino». **La celda está nivelada ⇔
`nota_original !== null`**; no hay bandera aparte, porque sería un segundo sitio donde
mentir.

> Los valores numéricos salen **como los saca PDO hoy** en esa misma consulta: `nota` viene
> entero porque la columna es `int`; `nota_original` y `nota_nivelacion` son `int` también y
> vienen igual. `subunidad_porc` sigue viniendo como cadena porque es una división en SQL —
> eso no cambia.

### §3.2 En cada `alumnos[].nota_final` (la definitiva del periodo)

Es la tarea **A8**, y va después que las §1–§2, pero la forma se fija aquí para que
`editor-nota` (B7) no tenga que esperar:

```json
{
  "alumno_id": 4021, "no_matricula": "…", "periodo": 2, "updated_by_username": "mgarcia",
  "nota_final": 71.25, "nf_id": 9910, "recuperada": 1, "manual": 1, "updated_by": 17,
  "created_at": "…", "updated_at": "…", "def_materia_auto": "62.5000",
  "updated_at_def": "…", "nfinal_desactualizada": 0,

  "nota_original": 62.5,
  "nivelada_at": "2026-08-29 15:10:00",
  "nivelada_por": 17,
  "nivelada_por_username": "mgarcia"
}
```

`recuperada` **no cambia de significado** (`1` ⇔ viene de una nivelación); lo que se gana es
que ahora dice de dónde venía. `nota_original` aquí es `float`, como `nota_final`, porque la
columna es `decimal(7,4)` desde `2026_08_30_200000`.

### §3.2bis Flutter no se rompe con las claves nuevas, y está mirado, no supuesto

`notas/detailed` lo llama `myvc_flutter` —una sola app para los quince colegios, con
versiones viejas conviviendo meses—, así que «añadir claves suele ser inocuo en Dart» no
bastaba. Mirado el 2 sep 2026 en `myvc_flutter` (`ee24f77`):

- `lib/Http/LibroNotasApi.dart:485` hace el `PUT notas/detailed` y decodifica el cuerpo
  como `Map`.
- `AlumnoDelLibro.fromJson` (`:273–283`) recorre `json['notas']` y construye cada
  `NotaDelLibro` desde un `Map<String, dynamic>`; la fila sólo se descarta si
  `subunidad_id` es 0.
- `NotaDelLibro.fromJson` (`:331–337`) **lee tres claves por nombre** —`id` (o
  `nota_id`), `subunidad_id` y `nota`— y no mira nada más. Una clave que no conoce ni
  revienta ni descarta la fila: no existe para él.
- `NotaFinalDelLibro.fromJson` (`:443–456`) hace lo mismo con `nota_final`, clave a clave.
- No hay `json_serializable`, `freezed` ni ninguna deserialización estricta en el proyecto
  (`grep` en `pubspec.yaml` y `lib/`: cero).

O sea que las seis claves de la §3.1 y las cuatro de la §3.2 **son invisibles para la app
que ya está en las tiendas**, y el plan de despliegue del §7 del reparto no cambia por
esto. Cerrado.

### §3.2ter Una nota nivelada cuya `nota` ya no es la de la regla — puede llegar, y B lo sabe

Flutter lee `nota` y escribe por `notas/update`, que **no cambia** (§6.1). Así que este
recorrido existe y no se puede cerrar: celda con original 48, nivelación 90, regla `topada`
→ `nota` 70; el docente abre el móvil, ve **70 sin ninguna marca** —las claves nuevas son
invisibles para su app— y lo corrige a 75. Queda una fila con acta y `nota_nivelacion` 90
y una `nota` de 75 que no es lo que da la regla.

**Es una decisión, no un accidente**, y la fija A6 con nombre:

- `notas/update` y `notas/lote` sobre una nota nivelada **escriben `nota` y dejan el acta
  intacta**. Ni la limpian —sería borrar un registro académico desde un móvil— ni
  recalculan por la regla —sería aprender a nivelar por la puerta de atrás—.
- La única traza es la línea `editar` de `auditoria`, y lleva en `resumen` que la nota
  estaba nivelada, para que una corrección legítima y este caso no sean indistinguibles.

Lo que eso significa para la celda de B: **puede recibir `nota_original !== null` con una
`nota` que no sea el resultado de la regla**. Pinta lo que hay —la vigente en grande, la
original tachada— y **no afirma** «queda X por regla Y»: esa frase sólo sale del servidor
al nivelar. Si quiere señalarlo, la condición es
`nota_original !== null && nota !== aplicar(regla, nota_original, nota_nivelacion)`, con
la tabla de la §1.4; es opcional.

### §3.3 Lo que NO cambia en `notas/detailed`

Ni `alumnos[]`, ni `independientes[]`, ni `unidades[]`, ni `asignatura`, ni el orden de nada.
Y **`Nota::verificarCrearNotas` sigue sembrando la fila con las columnas nuevas en `null`**,
que es su defecto de esquema: una nota recién sembrada no está nivelada.

### §3.4 Quién devuelve las columnas nuevas A PROPÓSITO, y quién las tiene congeladas

Un `ALTER TABLE` no puede cambiar un contrato. Cada consulta que leía la fila entera de
`notas`, `notas_finales`, `years` o `recuperacion_final` habría colado las columnas nuevas
en su respuesta **en cuanto la migración corriera en ese colegio, con el código de hoy y sin
que nadie lo decidiera**. Medido el 2 sep 2026 con la suite de contrato sobre la base migrada
(tres instantáneas se movieron solas: `bolfinales` × 2 y `grupos/promovidos`) y con el
reconocimiento de `8myvc-f2` sobre los informes —su documento, **cuyo número está por
decidir: el 25 lo ocupó `25-pedidos-de-cambio.md` en `main` el 2 sep**—. Esta tabla es la
decisión, sitio por sitio:

| Respuesta | Columnas nuevas | Cómo |
|---|---|---|
| `PUT notas/nivelar/{id}`, `DELETE notas/nivelar/{id}`, `PUT notas/nivelar/lote` | **sí, a propósito** (§1.2, §2, §4) | nacen con ellas |
| `PUT notas/detailed` — `alumnos[].notas[]` y `alumnos[].nota_final` | **sí, a propósito** (§3.1, §3.2) | A7 las **nombra** en las dos consultas de `putDetailed`, que ya van por columnas |
| `GET years`, `GET years/colegio`, `GET years/trashed` | **sí, a propósito**: `regla_nivelacion` (§5) | `YearsController:30/46` leen `SELECT y.*`; las tres instantáneas de `MuestreoDeLecturasTest` se regeneran **con esa decisión escrita** (A3) |
| `PUT editnota/alum-asignatura` — el editor de la definitiva | **sí, a propósito** | las **seis** de la celda y las **seis** del acta en cada periodo. **`editor-nota` NO lee `notas/detailed`**, lee ésta: sin el acta aquí, nivelar la definitiva y recargar enseña la nota vieja sin marca |
| `PUT notas/update/{id}` | **congelada** | `putUpdate` nombra sus diez columnas (A3) — `notas-update.json` verde sin regenerar |
| `GET notas/show/{id}` | **congelada** | `getShow` nombra sus diez columnas (A3) — `notas-show.json` verde sin regenerar |
| `GET notas/alumno/*`, `PUT notas/alumno-periodo-grupo`, `grupos/promovidos` y todo lo que pasa por `Nota::alumnoPeriodoDetalle` | **congelada** | `Nota::LAS_DIEZ_COLUMNAS` en sus dos consultas (A3). **A7 no las añade aquí**: si la ficha del alumno las quiere, es una decisión aparte y B la pide |
| `bolfinales/*` por `Asignatura::calculoAlumnoNotas` y `calculoAlumnoNotas2` | **congelada** | diez columnas nombradas (A3) |
| `Informes/BolfinalesController:508` (`SELECT nf.*` de `notas_finales`) y `CertificadosPersonaController:309/359/163` (`nf.*`, `r.*`) | **congeladas hasta A10** | `Informes/**` es de `8myvc-f2` desde el 2 sep: ella las nombra, y las abre cuando el informe imprima el par |
| `PUT definitivas_periodos/update` **sin `nf_id`** (las dos ramas devuelven la fila) | **congelada** | `DefinitivasPeriodosController::COLUMNAS_DE_LA_DEFINITIVA`. Lo llama `myvc_flutter` y el §6.1 dice que ese camino no cambia |
| `PUT promovidos/calcular-grupo` (`SELECT r.*` de `recuperacion_final`) | **congelada** | ahí se decide quién promociona, y para eso sólo hace falta la nota. El acta se pinta en la pantalla del año |
| `PUT definitivas_periodos/update` **con `nf_id`** (`SELECT n.*`) | **no filtra** | devuelve la cadena `'Cambiada'`, no la fila. Era la casilla «decidir en A8» y queda cerrada sin tocar nada |

Y una octava, encontrada el 2 sep leyendo el método por otra cosa: **`editnota/alum-asignatura`
ya publicaba las cinco columnas de `notas`** por un `Nota::where(...)->first()` encadenado, y
**nada lo habría cazado** porque esa ruta no tenía instantánea de forma. Es el punto ciego que
`tools/filas-enteras-al-cliente.php` declara en su cabecera —no ve Eloquent encadenado en
varias líneas—, y por eso la herramienta ordena candidatos en vez de cerrar el asunto. Ahora
esas columnas viajan **porque alguien las nombró**, y la ruta tiene su instantánea.

### El hueco del detector, medido — porque un número incómodo es información

La regla que salió de esta noche —**correr la suite entera después de cada migración que añada
columnas**— funciona, pero **sólo caza donde hay instantánea**. `editnota/alum-asignatura` no
la tenía, y por eso publicó cinco columnas durante horas sin que nada lo dijera. La pregunta
que faltaba era dónde más puede estar pasando. Medido el 2 sep con
`tools/rutas-sin-instantanea.php`:

| | |
|---|---|
| rutas en el router | **554** |
| que tocan `notas`, `notas_finales`, `recuperacion_final`, `subunidades`, `unidades` o `years` | **129** |
| de ésas, **con** instantánea de forma | **41** |
| de ésas, **sin** instantánea | **88** |
| y que además **leen la fila entera** — las accionables | **16** |

Las 88 no son 88 agujeros: la mayoría escriben y devuelven un mensaje. **Las 16 son la lista
que hay que mirar antes de desplegar**, porque en ellas una columna nueva sale al cliente y no
se entera nadie. De las que se han leído hasta ahora:

- **Dos eran filtraciones reales y ya están congeladas**: `historiales/nota-detalle` y
  `historiales/nota-final-detalle` devolvían la fila entera en `$res['nota']`, así que
  publicaban las cinco columnas de la nivelación desde su migración.
- **Una se descartó leyéndola**: `detalles/grupos-periodos` hace `SELECT *` sobre `notas`
  pero sólo **cuenta** las filas (`count($notasS)`), no las devuelve.
- **Cuatro son del carril de rúbricas** (`SELECT * FROM subunidades` en `AsignaturasController`,
  `UnidadesController` ×2 y `ChangeAskedController`), repartidas a esa sesión.
- El resto son borrados y escrituras que devuelven un mensaje, y se descartan igual, **una a
  una**.

> **Y el número lleva su propio aviso**: es un **suelo, no un techo**. «Lee la tabla» se decide
> por el texto del método, así que una ruta que llegue a `notas` por tres capas de servicios no
> sale; y «tiene instantánea» se decide por la URI escrita en un test, así que una construida
> con variables cuenta como descubierta. El error cae del lado prudente a propósito.

Regla para lo que venga: **una columna nueva viaja porque alguien la nombró**, nunca por un
asterisco. Y la prueba de que un sitio está congelado es que su instantánea **queda verde sin
regenerar**.

---

## §4 — `PUT notas/nivelar/lote` — la semana de nivelaciones

La pantalla de la §5.3 del plan: todo lo perdido del grupo, una casilla por indicador,
guardando en tandas. **Tiene exactamente los tres desenlaces de `notas/lote`**, con los
mismos nombres y en el mismo sitio, porque el front ya tiene escrito el agrupador de tandas
contra esa forma (`comunes/notas-en-lote/`) y no debería necesitar otro.

Guards: los mismos que el `PUT` suelto. El permiso se comprueba **una vez, con la lista de
periodos únicos de las notas del lote y antes de la primera escritura** —como en
`notas/lote`—, y una sola nota en un periodo con el interruptor apagado tumba el lote entero.

### §4.1 El cuerpo

```json
PUT api/notas/nivelar/lote
{
  "notas": [
    { "id": 8811, "nota_nivelacion": 90, "observacion": "Taller 28 ago", "fecha": "2026-08-28" },
    { "id": 8812, "nota_nivelacion": 65 },
    { "id": 8813, "nota_nivelacion": 80, "observacion": null }
  ]
}
```

Cada elemento lleva **lo mismo que el cuerpo del `PUT` suelto más `id`**. `observacion` y
`fecha` son por nota y opcionales; no hay una «observación del lote» que se copie a todas,
porque lo que se copia sin querer acaba impreso en una constancia. **Tope: 200 notas**, el
mismo `LOTE_MAXIMO`, y por la misma razón: por encima **aborta el lote entero con 422**, no
avisa. El cliente parte en tandas.

### §4.2 Desenlace 1 — todo bien: `200`

```json
{
  "guardadas": 3,
  "fallidas": [],
  "niveladas": [
    { "id": 8811, "alumno_id": 4021, "subunidad_id": 1187, "nota": 70, "nota_original": 55, "nota_nivelacion": 90,
      "nivelada_at": "2026-08-28 00:00:00", "nivelada_por": 17, "nivelada_por_username": "mgarcia",
      "nivelacion_obs": "Taller 28 ago", "updated_at": "…",
      "regla_aplicada": { "regla": "topada", "nota_minima": 70, "explicacion": "…" } },
    { "id": 8812, "…": "…" },
    { "id": 8813, "…": "…" }
  ],
  "definitivas": [
    { "alumno_id": 4021, "asignatura_id": 233, "periodo_id": 41, "nota": 71.25, "manual": false, "recuperada": false },
    { "alumno_id": 4022, "asignatura_id": 233, "periodo_id": 41, "nota": null,  "manual": false, "recuperada": false }
  ]
}
```

- **`guardadas`, `fallidas`, `definitivas`: idénticos a `notas/lote`** en nombre, tipo y forma
  de elemento. `definitivas` trae **a todos los alumnos tocados**, uno por par (asignatura,
  periodo), y el que no tiene fila viene con `nota: null` en vez de omitirse — es la decisión
  ya tomada allí.
- **`niveladas` es la clave que `notas/lote` no tiene, y aquí hace falta**: en `notas/lote` lo
  que se escribe es lo que se mandó, así que devolverlo sería repetirlo; aquí **lo que queda en
  `nota` depende de la regla** y el front no puede pintar la celda sin que el servidor le diga
  qué quedó. Cada elemento tiene **la forma de la §1.2 sin `definitiva`** —la definitiva de
  cada alumno ya viene una vez en `definitivas`, no una por nota—.

### §4.3 Desenlace 2 — algo rechazado, con su motivo: `200`

```json
{
  "guardadas": 2,
  "fallidas": [
    { "id": 8812, "motivo": "La nota no cabe en la escala del colegio (0 a 100)." },
    { "id": 99999, "motivo": "No existe la nota, o su indicador ya no está." },
    { "id": null, "motivo": "La posición 3 no trae un id de nota." }
  ],
  "niveladas": [ "…las dos que sí…" ],
  "definitivas": [ "…" ]
}
```

**Éxito parcial y `200`**, como en `notas/lote`: una nota mala no se lleva por delante las
demás. Los motivos posibles, en el orden en que se comprueban:

| `motivo` | Cuándo |
|---|---|
| `La posición N no trae un id de nota.` | elemento sin `id` numérico (`id: null` en la fila) |
| `La nota no es un número.` | `nota_nivelacion` ausente o no numérica |
| `No existe la nota, o su indicador ya no está.` | id sin fila viva, o sin unidad viva |
| el motivo de `EscalaDeNotas::motivoSiNoCabe` | fuera de escala. **Se comprueba después del permiso**, no antes, por lo que se aprendió en `notas/lote`: si fuera antes, un lote en un periodo cerrado contestaría 200 con la lista en vez del 403 |
| `La observación no puede pasar de 255 caracteres.` | |
| `La fecha de la nivelación no es válida.` | |

Y el corte de siempre: si después de apartar las malas **no queda ninguna**, responde
`{ "guardadas": 0, "fallidas": [...], "niveladas": [], "definitivas": [] }` **sin abrir
transacción** y sin recalcular nada.

### §4.4 Desenlace 3 — permiso denegado, que tumba el lote entero: `403`

```json
{ "message": "No tienes permiso para nivelar en este periodo." }
```

**Nada escrito, ni una nota, ni una línea de auditoría.** Es el mismo desenlace que el 400
del guard en `notas/lote`, con el código correcto. Y los otros dos que abortan el lote
entero, también sin escribir:

| Código | `message` |
|---|---|
| **422** | `Hace falta una lista de notas.` — `notas` ausente, no es lista, o está vacía |
| **422** | `El lote no puede pasar de 200 notas.` |

### §4.5 Lo que deja escrito

Igual que el `PUT` suelto **por cada nota**, dentro de **una transacción** para las escrituras
en `notas`, `auditoria` y `bitacoras`, y **un recálculo por par** (asignatura, periodo) fuera
de la transacción y al final. Es la estructura exacta de `notas/lote`, y la razón está en su
cabecera: treinta transacciones son treinta estados intermedios.

---

## §5 — Lo que B también necesita y todavía no es un endpoint: la regla del año

La pantalla de ajustes (B9) lee y escribe `years.regla_nivelacion`. Va con la migración
(A3) y con **un endpoint de la familia `years/`**, calcado de los `toggle-*` que ya usa esa
pantalla, salvo que lleva tres valores y no dos:

```
GET  years/colegio            → cada año trae además  "regla_nivelacion": "topada"
PUT  years/regla-nivelacion   { "year_id": 8, "regla": "mayor" }
     → 200 { "regla_nivelacion": "mayor", "nota_minima_aceptada": 70,
             "ejemplo": { "original": 55, "nivelacion": 90, "queda": 90,
                          "explicacion": "Regla del colegio: queda la mayor de las dos. Queda 90." } }
     → 422 { "message": "La regla tiene que ser topada, mayor o reemplaza." }
     → 403 si no es personal
```

`ejemplo` se calcula con **la mínima real de ese colegio**, que es lo que la §5.7 del plan
pide enseñar al lado de cada opción; el front lo pinta, no lo calcula. **Cambiar la regla no
reescribe ninguna nivelación anterior** (decisión 6).

### §5.1 Y la regla viaja además en el bloque de la sesión

Lo pidió el front el 2 sep y está hecho: `regla_nivelacion` sale en **las cuatro ramas** del
contexto de usuario, al lado de `nota_minima_aceptada`, `profes_pueden_editar_notas` y
`profes_pueden_nivelar`, que ya viajaban ahí.

Va ahí y no en una petición aparte porque **la regla sólo se usa acompañada de la mínima**, y
las dos son del mismo año: separadas, alguien acabaría leyéndolas de años distintos y la
previsualización diría un número mientras el servidor guarda otro. Hoy la única forma de
obtenerla era `GET years/colegio`, que trae todos los años del colegio para leer un campo del
actual.

Con esto, **el diálogo puede previsualizar sin pedir nada más**. Lo que decide sigue siendo el
servidor: `regla_aplicada` de la respuesta manda sobre lo que el front pintó antes de guardar.

Mueve cinco instantáneas, **regeneradas a propósito y con el diff comprobado**: las cuatro de
`login-contexto-*` y `muestreo-aplicacion-descargas-detailed`; una clave nueva en cada una y
nada más. Y no rompe a `myvc_flutter`: `ConfiguracionColegio.deLogin`
(`lib/Utils/ConfiguracionColegio.dart:87-110`) lee campo a campo con su valor por defecto, y
no hay `json_serializable` ni `freezed` en el proyecto.

> Esto es una ruta nueva más —serán **cuatro** en total: `PUT`/`DELETE notas/nivelar/{id}`,
> `PUT notas/nivelar/lote` y `PUT years/regla-nivelacion`— y cada una **mueve `CLAUDE.md` y
> los tres snapshots de rutas**, contando con `route:list` el día que entren, no sumando.

---

## §6 — Lo que este contrato deja para después, y dónde

| Qué | Tarea | Forma provisional, por si B llega antes |
|---|---|---|
| ~~Nivelar la **definitiva** del periodo~~ | **A8, hecha** | Ya no es provisional: **§8** de este documento |
| ~~El acta en `recuperacion_final`~~ | **A9, hecha** | Ya no es provisional: **§9** de este documento |
| Boletines, constancias, certificados con el par | A10 | se escribe cuando haya qué imprimir |

**Estas tres no son contrato todavía**: son la forma que tendrán si nada las mueve, para que
B7 y B8 no arranquen a ciegas. Cuando cada una se escriba de verdad, se sube a su § propio
de este documento y se avisa.

---

## §7 — Lo que este contrato NO hace, dicho para que nadie lo espere

- **No cambia `notas/update` ni `notas/lote`.** Ni una clave, ni un código, ni un orden.
  El test centinela (A6) fija sus snapshots tal como están hoy y se escribe **con** A5.
- **No rellena `nota_original` desde `bitacoras`** (§6.6 del reparto). Empieza vacía en los
  quince colegios.
- **No propaga** una nivelación de indicador a una definitiva `manual`/`recuperada`, ni una de
  definitiva a los indicadores. Lo dice §1.2 y lo avisa la pantalla.
- **No congela el puesto.** Nivelar mueve la definitiva y el puesto se calcula al vuelo
  (§6.4 del reparto): es decisión del colegio y se pregunta antes de A10.
- **No cambia `notas/update` ni `notas/show` de forma**, y hubo que hacer algo para que no
  cambiaran: los dos leían la fila entera (`SELECT n.*` y `Nota::find`), así que la
  migración de A3 les habría colado las cinco columnas nuevas en la respuesta **sin que
  nadie tocara el método**. Ahora nombran sus diez columnas, y la prueba de que está bien
  es que `notas-update.json` y `notas-show.json` **quedan verdes sin regenerar**.
- **`definitivas_periodos/update` tampoco cambia**, y su `SELECT n.*` de `notas_finales` no
  filtra nada: ese método **devuelve la cadena `'Cambiada'`**, no la fila. Era la casilla
  «decidir en A8» de la §3.4 y queda cerrada sin tocar nada.
- **Y `notas` NO tiene softdelete de verdad, aunque el §6 del reparto lo afirme.** Medido
  en A1: `DELETE notas/destroy/{id}` hace un `DELETE` físico, sin `deleted_at` y sin
  bitácora —la columna existe, nadie la escribe por esa ruta—. **No se cambia**: es un
  endpoint vivo y fuera de este plan. Lo que hay que saber para construir encima es que
  **borrar una nota nivelada se lleva la nivelación y su acta enteras**, y lo único que
  queda es la línea `borrar` de `auditoria` que A1 añadió, con la vigente que se fue.
  Ninguna garantía de «se puede recuperar» se apoya en `deleted_at` para esta tabla.

---

## §8 — `PUT definitivas_periodos/nivelar` — la definitiva del periodo (A8)

**Endpoint nuevo, y por lo mismo que los del indicador**: `definitivas_periodos/update`
teclea la definitiva a mano y lo llama `myvc_flutter` (`DefinitivasApi.dart`); si aprendiera
a nivelar, un número tecleado desde el móvil se guardaría topado. Aquél no cambia ni una
línea y hay un test que lo fija.

Guards: `auth.token` + `auth.personal` en la ruta, y `profes_pueden_nivelar` **del periodo de
la fila** en el método, con **403** — el guard viejo `pueden_modificar_definitivas` conserva
su 400 intacto.

```json
PUT api/definitivas_periodos/nivelar
{ "nf_id": 9910, "nota_nivelacion": 45, "observacion": "Sustentación de la asignatura", "fecha": "2026-08-29" }

→ 200
{
  "nf_id": 9910, "alumno_id": 4021, "asignatura_id": 233, "periodo_id": 41, "periodo": 2,
  "nota": 35, "nota_original": 28, "nota_nivelacion": 45,
  "nivelada_at": "2026-08-29 00:00:00", "nivelada_por": 17, "nivelada_por_username": "mgarcia",
  "nivelacion_obs": "Sustentación de la asignatura",
  "recuperada": true, "manual": true,
  "updated_at": "2026-09-02 11:20:04",
  "regla_aplicada": { "regla": "topada", "nota_minima": 35, "explicacion": "…" }
}
```

Tres cosas que lo separan del indicador y que B necesita saber:

- **Marca `recuperada` y `manual`.** No es cosmético: es lo que la desengancha del recálculo.
  Sin ellas, la nivelación duraría hasta que alguien abriera la planilla y
  `DefinitivasDeAsignatura` la pisara, sin error y sin que nadie tocara nada. `recuperada`
  **no cambia de significado**: sigue siendo «viene de una nivelación», y ahora además se
  sabe de dónde venía.
- **Los números son `float`**, no enteros: la columna es `DECIMAL(7,4)`. La regla decide en
  enteros —es la escala del colegio— pero **con `mayor`, la original se conserva con sus
  decimales**: una definitiva de 43,7500 que nivela por debajo sigue siendo 43,7500 y no 44.
- **Dos columnas más en `notas_finales`**, en su propia migración
  (`2026_09_02_200000_nivelacion_de_la_definitiva`): `nota_nivelacion` —bajo `topada` el 45
  que queda en 35 no estaría en ninguna parte, el mismo argumento que en `notas`— y
  `nivelacion_obs`, para que la constancia pueda imprimir con qué actividad se superó en los
  dos niveles y no sólo en el indicador.

Errores: **422** sin `nf_id` o sin `nota_nivelacion`, fuera de escala, observación de más de
255, fecha ilegible o futura, o regla del año inválida; **404** si no hay definitiva con ese
id o su periodo ya no está; **403** con el interruptor apagado. Ninguno escribe nada.

Repetir el `PUT` **sustituye** la nivelación y **conserva** `nota_original`, igual que en el
indicador (§1.3). Lo que **no** tiene todavía es `DELETE`: quitar la nivelación de una
definitiva es volver a `manual = 0` y dejar que el recálculo mande, y eso es una decisión
distinta de la del indicador. Se abre cuando B tenga la pantalla y diga si hace falta.

---

## §9 — El acta de la recuperación del año (A9)

**Aquí no hay endpoint nuevo, y es la decisión.** `recuperacion_final` ya guardaba la nota de
la recuperación **aparte** en vez de pisar la original —es el único sitio del proyecto que lo
hacía—, así que la fila **entera es** la recuperación: cada escritura es el acta, y no hay un
«corregir» que distinguir de un «nivelar» como en el indicador y en la definitiva, donde la
fila existe antes.

Lo que se añade es lo que le faltaba, en `PUT definitivas_periodos/update-recuperacion`, que
**no cambia de comportamiento**:

| Campo del cuerpo | Obligatorio | Qué hace |
|---|---|---|
| `observacion` | no | Con qué actividad se superó. Ausente o vacía = `null` |
| `fecha` | no | La del acta. Ausente = la del servidor. No puede ser futura |

Y la fila gana tres columnas (`2026_09_02_300000_acta_de_la_recuperacion_final`):
`nivelada_at`, `nivelada_por` y `observacion`. **`nivelada_por` no es `updated_by`**: aquél
dice quién la tocó la última vez, y para firmar la constancia del art. 17 hace falta quién la
registró.

La rama que **crea** devuelve la fila con **las diez columnas nombradas** y las tres del acta
dentro, a propósito, porque es lo que pinta la pantalla del año (B8):

```json
{ "id": 771, "alumno_id": 4021, "asignatura_id": 233, "year": 2026, "nota": 40,
  "nivelada_at": "2026-09-02 11:41:00", "nivelada_por": 17,
  "observacion": "Plan de mejoramiento de fin de año",
  "updated_by": 17, "created_at": "…", "updated_at": "…" }
```

La rama que **edita** sigue devolviendo la cadena `'Cambiada'`, tal cual.

Dos cosas que **no** hace, y las dos son a propósito:

- **No rellena el acta hacia atrás.** Las recuperaciones ya escritas se quedan sin ella;
  copiar `updated_by`/`updated_at` sería inventar un acta, que es lo que el §6.6 del reparto
  prohíbe hacer desde `bitacoras`.
- **`year` sigue siendo el número y no el id.** Es un refactor de permisos ya decidido en
  `PeriodoDeLaFila::todosLosDelAnio` —de ahí sale que esta escritura exija **todos** los
  periodos abiertos y no uno— y hay un test que lo fija, porque el cambio es tentador y
  silencioso.
