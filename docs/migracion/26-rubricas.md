# 26 — Rúbricas: el contrato del backend

> **Este documento es el contrato.** El front de `app2` construye contra él **antes de que
> el controlador exista**, igual que hace la sesión B con el 22 de nivelaciones. Si algo de
> aquí cambia, cambia **aquí primero** y se avisa; el código sigue al documento y no al
> revés.
>
> Viene del **§3.6** del plan (`myvc_front/PLAN-NIVELACIONES-Y-RUBRICAS.md`) y cubre las
> tareas **C2, C3, C4 y C9** del reparto (`TAREAS-NIVELACIONES-Y-RUBRICAS.md`, §5 «C»).
> Escrito el 2 sep 2026, rama `niv/rubricas`.

## §1 — Qué es y qué no es

Una rúbrica es la matriz **criterios × niveles**, con un descriptor en cada celda, que
**produce la nota de una subunidad** en vez de que el docente la invente (plan §1.3,
decisión 4). Tres propiedades que gobiernan todo lo que sigue:

1. **Produce la nota y nada más.** Definitivas, boletines, puestos y certificados **no
   saben que existe**: leen `notas.nota` como siempre. Por eso este carril no toca
   `NotasController`, ni `routes/api/academico.php`, ni `DefinitivasDeAsignatura`.
2. **No escribe `notas.nota`.** Guarda lo que el docente marcó y **devuelve la nota
   calculada**; escribirla es `PUT notas/update/{id}` —`NotasApi.actualizar`, tal cual
   está— para un alumno, y `PUT notas/lote` —`NotasApi.lote`— para el grupo. Así el único
   escritor de notas sigue siendo el que ya existe, con su bitácora, su escala y su
   recálculo de definitivas, y Flutter no se entera de nada (tareas §6.1).
3. **Cinco tablas nuevas y una columna NULL.** Ni un `UPDATE` sobre datos existentes.
   Volver atrás es borrar cinco tablas y una columna (tareas §6.8).

## §2 — Las tablas

Una migración, `2026_09_03_100000_rubricas`. El prefijo `09_03` es el del carril C para
que corra **después** de las `09_02` de la sesión A, aunque no dependa de ellas (tareas
§4.1).

```
rubricas                    la cabecera
  id                        int unsigned, autoincrement
  year_id                   int unsigned  FK years         ON DELETE CASCADE
  asignatura_id             int unsigned  NULL  FK asignaturas ON DELETE SET NULL
  nombre                    varchar(255)
  descripcion               text NULL
  es_plantilla              tinyint(1) NOT NULL DEFAULT 0
  created_by / updated_by / deleted_by   int NULL
  deleted_at / created_at / updated_at   (softdelete)

rubrica_criterios           las FILAS
  id, rubrica_id FK rubricas CASCADE
  definicion                text
  peso                      int NOT NULL DEFAULT 0
  orden                     int NOT NULL DEFAULT 0
  created_at / updated_at

rubrica_niveles             las COLUMNAS
  id, rubrica_id FK rubricas CASCADE
  nombre                    varchar(60)
  puntaje                   int NOT NULL
  orden                     int NOT NULL DEFAULT 0
  created_at / updated_at

rubrica_descriptores        las CELDAS
  id
  criterio_id               FK rubrica_criterios CASCADE
  nivel_id                  FK rubrica_niveles   CASCADE
  texto                     text
  UNIQUE (criterio_id, nivel_id)

rubrica_valoraciones        lo que el docente marcó
  id
  nota_id                   bigint unsigned  FK notas CASCADE     ← notas.id es bigint
  criterio_id               FK rubrica_criterios CASCADE
  nivel_id                  FK rubrica_niveles   CASCADE
  momento                   varchar(12) NOT NULL DEFAULT 'original'   'original' | 'nivelacion'
  comentario                varchar(255) NULL
  created_by / updated_by   int NULL
  created_at / updated_at
  UNIQUE (nota_id, criterio_id, momento)

subunidades
  + rubrica_id              int unsigned NULL  FK rubricas ON DELETE SET NULL
```

Cuatro decisiones de esquema, con su porqué:

- **`momento` nace con la tabla** y no en una segunda migración (era la tarea C9). Va
  dentro de la clave única, y una clave única que cambia de columnas después de tener
  datos es un `ALTER` que reconstruye; ponerla ahora cuesta cero. El endpoint la acepta
  desde el primer día con `original` por defecto.
- **`asignatura_id` es `SET NULL`, no `CASCADE`**, y es la única foránea del esquema que
  no cascada. Una rúbrica es trabajo del docente, no un atributo de la asignatura: si el
  borrado físico de una asignatura se llevara la rúbrica, se llevaría también las de las
  otras subunidades que la reutilizan como plantilla.
- **`subunidades.rubrica_id` es `SET NULL`** por lo mismo, y porque el borrado normal de
  una rúbrica es **softdelete**: la foránea no lo ve, y quien lo defiende es el endpoint
  (§4.6 — no se borra una rúbrica que alguna subunidad use).
- **`rubrica_valoraciones` cuelga de `nota_id`** y no de `(alumno_id, subunidad_id)`, como
  dice el plan: la fila de `notas` es la unidad de trabajo, y borrarla se lleva sus
  marcas.

Los modelos (`Rubrica`, `RubricaCriterio`, `RubricaNivel`, `RubricaDescriptor`,
`RubricaValoracion`) llevan las `@property` **escritas a mano**, a diferencia de los
demás: `tools/columnas-en-los-modelos.php` las genera desde el volcado congelado, y las
tablas que nacen por migración no están en él. Es lo mismo que pasa con `auditoria` y
`bol_ind_periodos`, que no tienen modelo.

## §3 — La regla de cálculo

```
nota_subunidad = Σ  (peso_criterio / 100) × puntaje_del_nivel_marcado
```

Es **deliberadamente la forma de la definitiva** (plan §2.2): pesos enteros, suma
ponderada, **sin normalizar**. Tres consecuencias que el front tiene que pintar tal cual:

- **La suma de pesos puede no dar 100 y no se corrige.** El backend la devuelve como
  `suma_pesos` en cada lectura y el editor avisa; es la lección de la §9.3 del 10.
- **La nota calculada es un entero.** `notas.nota` es `int`, así que `nota_calculada`
  llega **redondeada a entero** (mitad hacia arriba) y el `desglose` lleva los aportes
  con dos decimales, para que «Argumentación 40 % × Alto 85 = 34» se pueda enseñar.
- **Sólo hay nota cuando están marcados TODOS los criterios.** Una rúbrica a medias no
  produce una nota parcial: `completa: false` y `nota_calculada: null`. Escribir un 34 en
  `notas` porque se marcó una fila de tres sería inventar una nota.

Los puntajes de los niveles son los que el colegio ponga; el sembrado desde
`escalas_de_valoracion` (§4.2) propone **el punto medio del tramo**, que es lo que hace
que marcar todo «Superior» dé una nota Superior y todo «Bajo» dé una Bajo. Con
`porc_final` todo «Bajo» daría 69, que es «casi aprobó».

## §4 — El contrato: familia `rubricas/`

**Diez rutas**, en `routes/api/rubricas.php`, **todas** con `auth.token` (el guard por
defecto) y `auth.personal`. Con ellas el router pasa de **550 a 560** y se mueven los tres
snapshots de siempre: `rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json`,
donde la familia entra como «10 de 10».

El **orden dentro del fichero importa**: `rubricas/niveles-de-la-escala`,
`rubricas/calificar/…`, `rubricas/valorar/…`, `rubricas/valorar-lote` y
`rubricas/subunidad/…` van **antes** que `rubricas/{id}`, porque Laravel sirve la primera
que casa.

Todo lo que se lee o escribe es **del año del token** (`$user->year_id`). Una rúbrica de
otro año contesta **403** —existe, no es «no existe»—, que es el criterio de
`boletin-independiente/periodo`.

Errores en la forma de la casa: **404** «esa fila no está», **422** «lo que mandaste no
vale, y por qué», **403** «no puedes», siempre con `message`.

### 4.1 · `GET rubricas` — las del año

Query opcional `?asignatura_id=N`: devuelve las de esa asignatura **más las plantillas**
(`es_plantilla = 1`), que son las que el selector de una subunidad puede ofrecer.

```jsonc
→ [
  {
    "id": 7, "nombre": "Ensayo argumentativo", "descripcion": null,
    "asignatura_id": 412, "es_plantilla": 0,
    "criterios": 3, "niveles": 4, "suma_pesos": 100,
    "subunidades_que_la_usan": 2,
    "updated_at": "2026-09-02 10:15:00"
  }
]
```

### 4.2 · `GET rubricas/niveles-de-la-escala` — el sembrado

Los niveles que propone la escala del colegio para el año del token. **No escribe nada**:
el editor los mete en la matriz y se guardan con el `POST`/`PUT` de la rúbrica. Así el
botón «Sembrar niveles desde la escala del colegio» (plan §5.5) no es una ruta de
escritura más.

```jsonc
→ [
  { "nombre": "SUPERIOR", "puntaje": 96, "orden": 5 },   // (91+100)/2 = 95,5 → 96
  { "nombre": "ALTO",     "puntaje": 85, "orden": 4 },
  { "nombre": "BÁSICO",   "puntaje": 75, "orden": 3 },
  { "nombre": "BAJO",     "puntaje": 35, "orden": 2 }
]
```

`nombre` es `escalas_de_valoracion.desempenio`; `orden`, el suyo; `puntaje`, el punto
medio del tramo redondeado (§3). Un año sin escalas → `[]`.

### 4.3 · `GET rubricas/{id}` — la matriz entera

```jsonc
→ {
  "id": 7, "year_id": 12, "nombre": "Ensayo argumentativo", "descripcion": null,
  "asignatura_id": 412, "es_plantilla": 0, "suma_pesos": 100,
  "criterios": [
    { "id": 21, "definicion": "Argumentación", "peso": 40, "orden": 1 },
    { "id": 22, "definicion": "Ortografía",    "peso": 30, "orden": 2 },
    { "id": 23, "definicion": "Estructura",    "peso": 30, "orden": 3 }
  ],
  "niveles": [
    { "id": 31, "nombre": "SUPERIOR", "puntaje": 96, "orden": 5 },
    { "id": 32, "nombre": "ALTO",     "puntaje": 85, "orden": 4 },
    { "id": 33, "nombre": "BÁSICO",   "puntaje": 75, "orden": 3 },
    { "id": 34, "nombre": "BAJO",     "puntaje": 35, "orden": 2 }
  ],
  "descriptores": [
    { "criterio_id": 21, "nivel_id": 31, "texto": "Sostiene una tesis con tres argumentos…" }
  ],
  "subunidades_que_la_usan": [ { "id": 905, "definicion": "Ensayo final", "unidad_id": 301 } ]
}
```

`criterios` ordenados por `orden`; `niveles` por `orden` **descendente** (el mejor a la
izquierda, como en la escala). `descriptores` es una lista plana: el front la indexa por
el par. Sólo van las celdas que tienen texto.

### 4.4 · `POST rubricas` — crear

```jsonc
{
  "nombre": "Ensayo argumentativo",           // obligatorio, ≤ 255
  "descripcion": null,                        // opcional
  "asignatura_id": 412,                       // opcional; si viene, tiene que ser del año del token
  "es_plantilla": false,                      // opcional, por defecto false
  "criterios": [ { "definicion": "Argumentación", "peso": 40, "orden": 1 }, … ],
  "niveles":   [ { "nombre": "SUPERIOR", "puntaje": 96, "orden": 5 }, … ],
  "descriptores": [ { "fila": 0, "columna": 0, "texto": "…" } ]   // opcional
}
→ 201, el cuerpo de GET rubricas/{id}
```

**`descriptores` referencia por posición** —`fila` es el índice en `criterios`,
`columna` en `niveles`, ambos desde 0— porque las filas nuevas todavía no tienen id. Es
la misma forma en el `PUT`, para que el editor tenga **un único cuerpo** para crear y
para guardar.

`criterios` y `niveles` pueden ir vacíos (una cabecera que se rellena después). `peso` y
`puntaje` son enteros `≥ 0`; `es_plantilla` con el vocabulario de `aplica` de
`boletin-independiente/periodo` (`true/false/1/0/"on"/"off"`, lo demás 422).

### 4.5 · `PUT rubricas/{id}` — guardar la matriz

Mismo cuerpo que el `POST`, con una regla más para `criterios` y `niveles`:

- con `id` → se **actualiza** esa fila (tiene que ser de esta rúbrica, si no 422);
- sin `id` → se **crea**;
- las que había y **no vienen** → se **borran**.

`descriptores` se reescriben enteros en cada guardado: no tienen valoraciones colgando y
es más barato que diferenciarlos.

**Borrar un criterio o un nivel que ya tiene valoraciones es 422 y no escribe nada**:

```jsonc
→ 422 {
  "message": "No se puede quitar un criterio o un nivel que ya se usó para calificar.",
  "criterios_con_valoraciones": [22],
  "niveles_con_valoraciones": []
}
```

Cambiar el `peso` o el `puntaje` de uno que ya se usó **sí se permite**, y **no recalcula
ninguna nota**: la nota que se escribió en `notas` se escribió con la regla de ese
momento, igual que la regla de nivelación se aplica al escribir (plan §3.5). El front lo
avisa; el backend no lo impide.

Respuesta: **200**, el cuerpo de `GET rubricas/{id}`. Todo dentro de una transacción.

### 4.6 · `DELETE rubricas/{id}` — a la papelera

Softdelete. **No se borra una rúbrica que alguna subunidad viva esté usando**:

```jsonc
→ 422 {
  "message": "Esta rúbrica la usan 2 subunidades. Desenlázala antes de borrarla.",
  "subunidades": [ { "id": 905, "definicion": "Ensayo final", "unidad_id": 301 } ]
}
→ 200 { "id": 7, "deleted_at": "2026-09-02 10:20:00" }
```

Las valoraciones ya hechas **no se tocan**: siguen colgando de sus `notas` y sus
criterios, que no se borran físicamente. No hay ruta de restaurar: si hace falta, se pide.

### 4.7 · `PUT rubricas/subunidad/{subunidad_id}` — enlazar o desenlazar

```jsonc
{ "rubrica_id": 7 }        // enlazar
{ "rubrica_id": null }     // desenlazar
→ 200 { "subunidad_id": 905, "rubrica_id": 7 }
```

- 404 si la subunidad no existe o está borrada.
- 422 si la rúbrica no existe, está borrada, o **no es del año de la subunidad** (el año
  se saca de `subunidades → unidades → periodos.year_id`, no del token: la subunidad es
  la que manda).
- Desenlazar **no borra las valoraciones** hechas con la rúbrica anterior: siguen en su
  tabla por si se vuelve a enlazar. Las lecturas de §4.8 sólo enseñan las de la rúbrica
  **actualmente** enlazada.

Vive aquí y no en `subunidades/*` porque `routes/api/academico.php` no es de este carril.
Es la única columna nueva de una tabla existente, y `app/Models/Subunidad.php` gana la
`@property` a mano por lo mismo que los modelos nuevos (§2).

### 4.8 · `GET rubricas/calificar/{subunidad_id}` — lo que necesitan las dos pantallas

La lectura única para «un alumno» y «el grupo» (plan §5.6). Query opcional
`?momento=nivelacion` (por defecto `original`).

```jsonc
→ {
  "subunidad": { "id": 905, "definicion": "Ensayo final", "porcentaje": 25,
                 "unidad_id": 301, "periodo_id": 91, "asignatura_id": 412, "grupo_id": 58 },
  "rubrica": { …el cuerpo de GET rubricas/{id}… } | null,
  "momento": "original",
  "alumnos": [
    {
      "alumno_id": 3311, "nombre": "APELLIDO APELLIDO NOMBRE",
      "nota_id": 88012, "nota": 72,
      "valoraciones": [ { "criterio_id": 21, "nivel_id": 32, "comentario": null } ]
    },
    { "alumno_id": 3312, "nombre": "…", "nota_id": null, "nota": null, "valoraciones": [] }
  ]
}
```

- `rubrica: null` cuando la subunidad no tiene ninguna enlazada. Es 200 y no 422: la
  pantalla decide si ofrece enlazar una.
- **`nota_id` puede ser `null`**, y entonces **no se puede calificar a ese alumno
  todavía**. La fila de `notas` la siembra `PUT notas/detailed` al abrir la planilla; este
  endpoint **no la crea**, porque crear filas de `notas` es escribir notas y este carril
  no escribe notas. El front pide `notas/detailed` de esa asignatura y vuelve a leer.
- Los alumnos son los **matriculados en el grupo de la asignatura**, en el mismo conjunto
  que la planilla. Si la unidad es de un **boletín independiente** (`unidades.alumno_id`
  no nulo, 19 §3), la lista es **ese alumno y nadie más**.
- `nota` es la vigente de `notas.nota`, para pintar «hoy tiene 72» al lado de lo que va a
  quedar.

### 4.9 · `PUT rubricas/valorar/{nota_id}` — marcar un alumno

```jsonc
{
  "momento": "original",                       // opcional; 'original' | 'nivelacion'
  "valoraciones": [
    { "criterio_id": 21, "nivel_id": 32, "comentario": "Falta la conclusión" },
    { "criterio_id": 22, "nivel_id": 31 },
    { "criterio_id": 23, "nivel_id": null }    // null = quitar la marca de ese criterio
  ]
}
→ 200 {
  "nota_id": 88012, "momento": "original",
  "completa": true,
  "nota_calculada": 88,                        // entero; null si no está completa
  "suma_pesos": 100,
  "desglose": [
    { "criterio_id": 21, "peso": 40, "nivel_id": 32, "puntaje": 85, "aporte": 34.00 },
    { "criterio_id": 22, "peso": 30, "nivel_id": 31, "puntaje": 96, "aporte": 28.80 },
    { "criterio_id": 23, "peso": 30, "nivel_id": 33, "puntaje": 75, "aporte": 22.50 }
  ]
}
```

Reglas:

- **Permiso: el mismo que `notas/update`** — `User::pueden_editar_notas($user, periodo de
  la nota)`, con sus mismos códigos (400 con el periodo cerrado para un docente, 403 para
  quien no es docente ni superusuario). No se inventa uno más estrecho ni más ancho: la
  llamada siguiente del front es `notas/update` y tiene que fallar **por lo mismo**.
- 404 si la nota no existe o está borrada.
- 422 si la subunidad de esa nota **no tiene rúbrica enlazada**, si un `criterio_id` o
  `nivel_id` no es de esa rúbrica, si un criterio viene dos veces, o si `momento` no está
  en el vocabulario. **Nada se escribe** en ese caso.
- Guarda por `(nota_id, criterio_id, momento)`: lo que viene se pisa, lo que no viene se
  **conserva**, `nivel_id: null` **borra** esa marca. Por eso la pantalla de un alumno
  puede mandar una celda cada vez.
- `desglose` lleva **todos los criterios de la rúbrica**, marcados o no (`nivel_id`,
  `puntaje` y `aporte` a `null` en los sin marcar), para que la pantalla pinte la fila
  entera.
- **No escribe `notas.nota`.** Con `completa: true` el front llama a
  `NotasApi.actualizar(nota_id, { nota: nota_calculada, asignatura_id })` tal como está.
  Con `momento: 'nivelacion'` la escritura es `PUT notas/nivelar/{id}` del carril A (22
  §…), y a este endpoint le da igual.

### 4.10 · `PUT rubricas/valorar-lote` — marcar el grupo

```jsonc
{
  "momento": "original",
  "notas": [
    { "nota_id": 88012, "valoraciones": [ { "criterio_id": 21, "nivel_id": 32 }, … ] },
    { "nota_id": 88013, "valoraciones": [ … ] }
  ]
}
→ 200 { "momento": "original", "notas": [ …una entrada por nota, con la forma de §4.9… ] }
```

- **Todas las notas tienen que ser de la misma subunidad** (422 si no): el lote es la
  pantalla del grupo, y el permiso de periodo se comprueba **una vez**, sobre ese periodo.
- **Un solo desenlace o el otro, nunca a medias**: cualquier fila inválida → 422 con
  `{ message, fila, nota_id, motivo }` y **nada escrito**. Es distinto de `notas/lote`, que
  salta y sigue, a propósito: marcar 45 rúbricas es un acto, y un lote que deja 30
  marcadas y 15 sin marcar no se distingue del docente que no llegó a las 15.
- El backend no fija tamaño máximo; el front parte en tandas como ya hace
  `comunes/notas-en-lote/`, y después manda a `notas/lote` las que salieron `completa`.

## §5 — Quién puede, y lo que queda a Joseth

| Ruta | Guard de ruta | Dentro del método |
|---|---|---|
| `GET rubricas`, `GET rubricas/{id}`, `GET rubricas/niveles-de-la-escala`, `GET rubricas/calificar/{id}` | `auth.personal` | año del token |
| `POST rubricas`, `PUT rubricas/{id}`, `DELETE rubricas/{id}`, `PUT rubricas/subunidad/{id}` | `auth.personal` | año del token / de la subunidad |
| `PUT rubricas/valorar/{id}`, `PUT rubricas/valorar-lote` | `auth.personal` | `pueden_editar_notas` sobre el periodo de la nota |

**Lo que NO se comprueba, y es una pregunta abierta:** que el docente que edita o califica
con una rúbrica **dé esa asignatura**. Hoy `notas/update` tampoco lo comprueba —cualquier
docente con el periodo abierto puede escribir cualquier nota del colegio—, y este carril
mantiene **paridad** con eso en vez de estrenar una regla de alcance por su cuenta.
Ponerla aquí y no en `notas/update` daría un sistema donde la rúbrica te dice que no y la
planilla te dice que sí. Es decisión de Joseth, y es del dominio de notas, no de éste.

**La tarea C9 del reparto queda absorbida aquí** (aprobado por el coordinador el 2 sep
2026): la columna `momento` nace en C2 y los dos endpoints de valorar la aceptan desde el
primer día. Lo que queda de C9 es sólo la mitad del front —distinguir en pantalla la
valoración original de la de nivelación—, y no se busque como pendiente del backend.

**Tampoco pasa por `App\Services\Auditoria`.** La fase 4 del 18 no ha llegado a notas, y
lo que importa académicamente —el cambio de `notas.nota`— lo audita `notas/update` como
hasta hoy. Añadir `rubrica` al vocabulario cerrado de entidades es una línea el día que se
decida.

## §6 — Lo que el front tiene que saber para construir contra un doble

1. Las diez rutas de §4, con los cuerpos y respuestas tal cual.
2. Que **calificar es dos llamadas**: `rubricas/valorar` y después `notas/update` (o
   `notas/lote`). Ninguna de las dos se inventa aquí.
3. Que `nota_id: null` en §4.8 significa «abre la planilla primero», no «error».
4. Que `nota_calculada` viene ya redondeada a entero y que `null` significa incompleta.
5. Que la suma de pesos **no se corrige**: `suma_pesos` viene en cada lectura para avisar.
6. El bloque de rutas de `app.routes.ts` es de la sesión B (tareas §4.3) y el botón de la
   planilla también (§4.4); esto no lo cambia.

## §7 — Tests que van con el código

- `RubricasTest` (contrato): crear → leer → guardar la matriz → enlazar → calificar → leer
  de nuevo, mirando **lo que queda escrito** y no el 200; el 422 de quitar un criterio
  usado; el 422 de borrar una rúbrica en uso; el lote que no escribe nada si una fila
  falla; y que **`notas.nota` no cambia** en ninguna de las llamadas de esta familia.
- Los tres snapshots de rutas y guards, regenerados **una vez** y leídos: la familia
  entra como «10 de 10».
- `RutasPreLoginTest` **no se mueve**: ninguna es pública.
