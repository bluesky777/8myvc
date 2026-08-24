# 19 — El boletín independiente

**Escrito el 24 ago 2026.** Lo pidieron los colegios y lo autorizó Joseth con las
cuatro decisiones de la §2. **No es de la migración**: es trabajo nuevo, y va
detrás de que la fase 2 de las [definitivas](10-definitivas.md) esté decidida —
las dos tocan `notas_finales` desde el mismo sitio y el orden importa (§10).

> **Se lee entero antes de escribir la primera línea.** Lo que hace esta función
> peligrosa no es lo que añade, sino lo que **le quita a la consulta de otro**:
> hay **74 consultas** en `app/` que leen `unidades` y **70 que leen
> `subunidades`** dando por hecho que una unidad pertenece a la asignatura y a
> nadie más. En cuanto una unidad pueda tener dueño, cada una de esas 74 está o
> corregida o equivocada. No hay término medio y no hay aviso: la consulta sigue
> devolviendo filas.

---

## §1 — Lo que pidieron, en sus palabras y traducido

Un alumno se puede marcar como PIAR. Los colegios quieren marcarlo también como
**«requiere boletín independiente»**, y que eso signifique cinco cosas:

| Lo que pidieron | Lo que significa en este código |
|---|---|
| «que no aparezca en esa planilla de notas normales» | `NotasController::putDetailed` deja de devolverlo entre `alumnos`, y `Nota::verificarCrearNotas` deja de crearle notas de las subunidades del grupo |
| «otra pantalla con el listado de alumnos marcados, donde el docente le escriba sus unidades y subunidades del periodo de esa asignatura y la nota» | una pantalla nueva y **tres rutas nuevas**; las unidades, las subunidades y las notas se escriben con los endpoints que **ya existen** |
| «opciones para copiar las unidades/sub en los demás alumnos mostrados» | `POST boletin-independiente/copiar`, con y sin notas |
| «al cargar los puestos salen en el grado; que haya opción de que aparezcan o no» | un interruptor del colegio en `years`, y **mueve el puesto impreso de todos los demás** (§7) |
| «en los boletines salen todos, pero el independiente no lleva las subunidades de la asignatura sino las suyas» | `Unidad::deAsignaturaCalculada` y `Subunidad::deUnidadCalculada` — **dos funciones cubren los tres boletines** |

Y la sexta, que es la que decide el diseño de la tabla:

> «En ese módulo debe tener la opción *Este periodo no tiene boletín
> independiente*. Si la marca no debe borrar los datos suministrados en ese
> periodo si los puso antes de marcar la opción, pero esos datos deben ser
> ignorados en los boletines y el estudiante deberá volver a aparecer en las
> planillas de ese periodo junto a los demás, con algún badge o icono.»

**Eso es un interruptor por periodo que no borra nada**, o sea que el dato y su
visibilidad son dos cosas distintas y hay que guardarlas por separado. Todo lo
demás del diseño sale de ahí.

Y una que se pidió **en negativo**, y vale tanto como las otras:

> «La nota de comportamiento no debe ser otro módulo.»

`nota_comportamiento` cuelga de `(alumno_id, periodo_id)` y no sabe de
asignaturas ni de subunidades (`database/schema/mysql-schema.sql:1292`). **No se
toca ni una línea**: el alumno con boletín independiente sigue apareciendo en la
pantalla de comportamiento con todos los demás, y su nota entra en el promedio
igual que hoy. Si alguien acaba escribiendo una pantalla de comportamiento
dentro de este módulo, está construyendo lo que se pidió no construir.

---

## §2 — Las cuatro decisiones, tomadas el 24 ago 2026

| | Decisión | Qué se descartó y por qué importa |
|---|---|---|
| 1 | **La marca vale para TODAS las asignaturas del alumno** | Se descartó elegir asignatura por asignatura. Cuesta un riesgo real y está medido en la §9.1: en la asignatura cuyo docente no le cree nada, el alumno sale del grupo y **no entra en ninguna parte** |
| 2 | **La marca vive en la matrícula: `matriculas.boletin_independiente`** | No en `alumnos`, que es donde vive `nee`. `alumnos` es global: la marca se arrastraría al año siguiente sin que nadie la ponga, y **repintaría los boletines de años pasados** — la matrícula es por año y por grupo, que es el alcance real de la decisión |
| 3 | **Los puestos se deciden con un interruptor del colegio**, `years.puestos_con_bol_independiente`, **por defecto 1** | No una casilla en la pantalla: el puesto también se **imprime en el boletín** (`BoletinesController:238`), y una casilla de pantalla dejaría dos criterios para el mismo número. Por defecto 1 = lo de hoy |
| 4 | **Copiar copia la estructura, y pregunta por las notas** | Dos botones en la misma pantalla: `con_notas` en el cuerpo. Copiar sin notas es preparar la planilla; copiar con notas es calificar a varios de golpe y **el docente tiene que decirlo** |

### Lo que todavía espera una decisión

- **Quién puede marcar a un alumno.** Hoy la rama de propiedades de matrícula de
  `GuardarAlumno::valor` (`app/Http/Controllers/Alumnos/GuardarAlumno.php:48-81`)
  la escriben el **titular del grupo** y el **administrativo**. `nee` la escribe
  además el **psicólogo**, por la decisión del 21 ago. La propuesta es **igualar
  las dos**: quien puede meter a alguien en el PIAR puede marcarle el boletín
  independiente. **Sin contestar, se implementa como está hoy** (titular +
  administrativo) y ampliarlo es un `case` más.
- **Qué puesto lleva el boletín de un independiente** cuando el interruptor dice
  que no cuentan. La propuesta es imprimir `—`, no un puesto calculado sobre una
  lista de la que él no forma parte. Ver §7.

---

## §3 — El diseño, en una frase

> **Una unidad puede tener dueño.** Si `unidades.alumno_id` es `NULL` es del
> grupo —todas las de hoy—; si lleva un id, es de ese alumno y de nadie más. Las
> subunidades y las notas **no cambian**: siguen colgando de la unidad y de la
> subunidad como siempre.

Lo que compra:

- **`notas` no se toca.** Ni una columna. La nota del independiente es una nota
  normal colgada de una subunidad normal, así que `PUT notas/update/{id}`,
  `PUT notas/lote`, la bitácora, `PeriodoDeLaFila::deNota` y
  `DefinitivasDeAsignatura::recalcularPorNota` **funcionan sin cambio**.
- **`notas_finales` no se toca**, y por eso el independiente **sale en los
  puestos, en los boletines finales, en las actas y en los certificados** sin
  que nadie escriba una línea: su definitiva se calcula con la misma fórmula
  sobre sus propias unidades.
- **Los tres boletines se cubren en dos funciones**: los tres pasan por
  `Unidad::deAsignaturaCalculada` (`app/Models/Unidad.php:88`) y
  `Subunidad::deUnidadCalculada` (`app/Models/Subunidad.php:91`).

Lo que cuesta: **las 74 consultas de la cabecera.** Está en la §9.

### Las cuatro migraciones

```sql
-- 1. La unidad puede tener dueño. NULL = del grupo, que es todo lo que hay hoy.
ALTER TABLE unidades
    ADD COLUMN alumno_id INT UNSIGNED NULL AFTER asignatura_id,
    ADD KEY unidades_alcance_index (asignatura_id, periodo_id, alumno_id),
    ADD CONSTRAINT unidades_alumno_id_foreign
        FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE;

-- 2. La marca, por año, donde vive el año.
ALTER TABLE matriculas
    ADD COLUMN boletin_independiente TINYINT(1) NOT NULL DEFAULT 0;

-- 3. La excepción por periodo. La fila que falta significa «lo que diga la
--    matrícula»; `aplica=0` significa «este periodo no, pero no borres nada».
CREATE TABLE bol_ind_periodos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alumno_id   INT UNSIGNED NOT NULL,
    periodo_id  INT UNSIGNED NOT NULL,
    aplica      TINYINT(1) NOT NULL DEFAULT 1,
    updated_by  INT DEFAULT NULL,
    created_at  TIMESTAMP NULL DEFAULT NULL,
    updated_at  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bol_ind_periodos_unico (alumno_id, periodo_id),
    CONSTRAINT bol_ind_periodos_alumno_id_foreign
        FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE,
    CONSTRAINT bol_ind_periodos_periodo_id_foreign
        FOREIGN KEY (periodo_id) REFERENCES periodos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. El interruptor de los puestos, con el valor de hoy por defecto.
ALTER TABLE years
    ADD COLUMN puestos_con_bol_independiente TINYINT(1) NOT NULL DEFAULT 1;
```

**La clave única de `bol_ind_periodos` nace con la tabla, y es deliberado.** La
tabla `notas_finales` lleva sin ella desde 2014 y de ahí salen los tres síntomas
del [10](10-definitivas.md); una tabla nueva sin clave única es el mismo error
cometido a sabiendas. Con ella el interruptor se escribe con un
`INSERT ... ON DUPLICATE KEY UPDATE` de una línea y no hay ventana de borrado.

**`aplica` es un `tinyint` con dueño**, no uno de los de
`tools/interruptores-que-nadie-lee.py`: lo escribe una pantalla concreta, lo lee
un servicio único y hay un test que lo demuestra en los dos sentidos.

### El servicio único, `App\Services\BoletinIndependiente`

La misma forma que `DefinitivasDeAsignatura`: **un solo sitio decide**, y quien
pregunta no vuelve a escribir la regla.

```php
// ¿Este alumno, en este periodo, va por boletín independiente?
BoletinIndependiente::aplica(int $alumnoId, int $periodoId): bool

// El valor que va a `unidades.alumno_id` para las consultas de UN alumno:
// null (las del grupo) o su id (las suyas). Es el único parámetro que hace falta.
BoletinIndependiente::alcance(int $alumnoId, int $periodoId): ?int

// Los alumnos del grupo que van por independiente en ese periodo, y los que no.
BoletinIndependiente::delGrupo(int $grupoId, int $periodoId): array

// Copiar la estructura de un alumno a otros. Transaccional. §6.2
BoletinIndependiente::copiar(...): array
```

Y **la misma decisión escrita en SQL**, para las consultas que resuelven todo un
grupo de una vez y no pueden preguntar alumno por alumno:

```php
/** El `LEFT JOIN` que hay que añadir. `m` es `matriculas`, `u` es `unidades`. */
public const JOIN_ESTADO =
    'LEFT JOIN bol_ind_periodos bip
            ON bip.alumno_id = m.alumno_id AND bip.periodo_id = u.periodo_id';

/** El dueño que le toca a cada alumno. Se compara con `u.alumno_id <=> ...` */
public const ALCANCE =
    'IF(m.boletin_independiente = 1 AND COALESCE(bip.aplica, 1) = 1, m.alumno_id, NULL)';
```

**`<=>` y no `=`**, y es la mitad del diseño: el igual null-safe de MySQL empareja
`NULL` con `NULL`, así que **una sola condición resuelve las dos ramas** —el
alumno normal contra las unidades del grupo, el independiente contra las suyas—
sin un `OR` que cada consulta escribiría de una forma distinta. Con `=` a secas
la rama del alumno normal devuelve cero filas y **todas las definitivas del
colegio se van a 0** sin un solo error en el log.

**Dos formas y ninguna tercera.** Si aparece una consulta que necesita una
tercera, es que hace falta un método más en el servicio, no un `OR` a mano.

---

## §4 — La regla que hace esto desplegable

> **Con la migración puesta y ningún alumno marcado, todas las respuestas de la
> API son byte a byte las de hoy.**

Es comprobable y es el criterio de aceptación de la fase 1: `alumno_id` nace
`NULL` en las unidades que ya existen, `boletin_independiente` nace 0 en todas
las matrículas, `bol_ind_periodos` nace vacía y el interruptor de puestos nace
en 1. **Los 1.344 tests y sus snapshots tienen que seguir verdes sin regenerar
ni uno.** Un snapshot que haya que regenerar en la fase 1 no es un snapshot que
se regenera: es una consulta a la que se le olvidó el alcance.

Eso es lo que permite desplegar el backend en los dieciséis colegios **antes**
de que exista una sola pantalla, que es como tiene que ir (§10).

---

## §5 — Las fases

| | Qué | Depende de |
|---|---|---|
| **0** | **Medir.** `tools/unidades-sin-alcance.py`: recorre `app/`, encuentra las consultas que leen `unidades` o `subunidades` y dice **cuántas** y **cuáles** no llevan alcance. Hoy tienen que salir **74 y 70**, todas sin alcance: ésa es la población de la fase 1 | — |
| **1** | **Las cuatro migraciones + el servicio + el alcance en las 74.** Sin ninguna pantalla, sin ninguna ruta nueva. Termina cuando la §4 se cumple y el detector dice **0 sin alcance** | 0 |
| **2** | **La marca.** Un `case 'boletin_independiente'` en `GuardarAlumno::valor`, la columna en `Grupo::alumnos`, `Matricula` y `putShow`, **y la regla única de cuál es la matrícula del año** (§9.5). Cero rutas nuevas | 1 |
| **3** | **Las planillas normales.** `putDetailed` deja de devolver a los independientes y `verificarCrearNotas` deja de crearles notas del grupo. Devuelve `independientes` para que el front sepa a cuántos no está viendo | 1, 2 |
| **4** | **La pantalla nueva.** Las tres rutas de la §6 | 1, 2 |
| **5** | **Los boletines.** Las dos funciones de `Unidad`/`Subunidad` con alcance — ya hechas en la fase 1 si el barrido fue completo; aquí sólo se **prueban en negativo** con un alumno marcado | 1 |
| **6** | **Los puestos** y el interruptor de `years` | 1, 2 |
| **7** | **El front.** Ver §8. **No se publica hasta que la 1–6 estén DESPLEGADAS** en los dieciséis, no fusionadas | todo lo anterior desplegado |

Las fases 1 a 6 son de este repo y se pueden fusionar en una sola tanda de
despliegue. La 7 es de `myvc_front`.

---

## §6 — El contrato: qué se añade a la API

**Tres rutas nuevas.** De 542 a **545**. Una ruta nueva es una decisión: éstas
son las tres y no hay una cuarta. Todo lo demás **reutiliza lo que existe**.

### 6.1 · `PUT boletin-independiente/planilla` · `auth.personal`

La pantalla nueva, entera, en una petición.

```jsonc
// petición
{ "asignatura_id": 812 }            // el periodo es el del usuario, como en notas/detailed

// respuesta
{
  "asignatura": { "asignatura_id": 812, "materia": "Matemáticas", "grupo_id": 44, ... },
  "periodo":    { "periodo_id": 91, "numero": 3 },
  "alumnos": [
    {
      "alumno_id": 3311, "nombres": "...", "apellidos": "...", "foto_nombre": "...",
      "aplica": true,                    // false = «este periodo no tiene boletín independiente»
      "porcentaje_unidades": 100,        // la suma REAL. != 100 se pinta en rojo, no se corrige
      "definitiva": { "nota": 78, "manual": false, "recuperada": false },
      "unidades": [
        { "unidad_id": 9912, "definicion": "...", "porcentaje": 40, "orden": 0,
          "subunidades": [
            { "subunidad_id": 55021, "definicion": "...", "porcentaje": 50, "orden": 0,
              "nota": { "id": 880431, "nota": 80 } }
          ] }
      ]
    }
  ]
}
```

- **`aplica: false` viene con sus unidades y sus notas dentro.** Es la §1: los
  datos no se borran, se ignoran. La pantalla los enseña en gris; el boletín no
  los mira.
- **`porcentaje_unidades` se devuelve y no se corrige**, por la misma decisión
  que en el [10](10-definitivas.md) §9.3: una estructura mal configurada da una
  definitiva rara y **que se note es lo que la delata**.
- **`unidades: []` viene con `motivo`, y eso lo pidió el front el 24 ago.** Un
  vacío que no dice por qué está vacío se lee como «no hay datos» cuando lo que
  hay es un fallo — el front lleva una noche entera cazando esa forma exacta, y
  su regla es que **«vacío legítimo» no significa «pantalla útil»**. Así que la
  respuesta no manda una lista vacía a secas:

  | `motivo` | Qué pasó, y con qué se distingue |
  |---|---|
  | `"asignatura_sin_montar"` | **tampoco hay unidades del grupo** en esa asignatura y periodo: el docente no ha entrado todavía. No es culpa de la marca y le pasa igual a los treinta |
  | `"sin_estructura_propia"` | **el grupo sí tiene unidades y este alumno no**: el docente entró, montó lo suyo y **a este alumno no le hizo nada**. Es el caso de la §9.1 y el único que la pantalla tiene que gritar |
  | `"vaciada"` | tuvo unidades propias y hoy están todas borradas. Distinto de no haber tenido nunca, y sólo se sabe mirando `deleted_at` |

  Se distinguen con datos que ya existen —las unidades del grupo, las del alumno
  y sus `deleted_at`—: no hace falta guardar nada nuevo para contestar la
  pregunta, sólo hace falta que el endpoint la conteste **en vez de dejársela a
  la pantalla**, que desde el navegador no puede.

### 6.2 · `POST boletin-independiente/copiar` · `auth.personal`

```jsonc
// petición
{ "asignatura_id": 812, "origen_alumno_id": 3311,
  "destinos": [3315, 3402], "con_notas": false, "reemplazar": false }

// respuesta
{ "copiadas": { "unidades": 6, "subunidades": 14, "notas": 0 },
  "destinos": [
    { "alumno_id": 3315, "unidades": 3, "subunidades": 7 },
    { "alumno_id": 3402, "unidades": 0, "saltado": "ya tiene estructura este periodo" }
  ] }
```

- **Una transacción para todo el lote**, y **un recálculo por alumno al final y
  fuera de ella** — lo que aprendió `PUT notas/lote`: media copia deja
  definitivas calculadas sobre estados intermedios.
- **`reemplazar: false` por defecto y el destino que ya tiene estructura se
  salta diciéndolo.** Copiar encima borraría notas que alguien tecleó, y este
  botón está justo al lado del de copiar sin notas.
- **No es el copiador que ya existe, y la pantalla tiene que decirlo.**
  `PUT periodos/copiar` —la pantalla `copiar-unidades` del front— copia **de una
  asignatura y periodo a OTRA asignatura y periodo**; ésta copia **de un alumno a
  otros alumnos dentro de la misma asignatura y el mismo periodo**. Uno copia a
  otra columna, el otro a otras filas. **Y no se reutiliza aquél por una razón
  medible**: `putCopiar` escribe en un `foreach` **sin transacción**, según su
  propio test de contrato. Rótulos acordados con el front: aquí *«Copiar a los
  demás alumnos de esta lista»*, allí *«Copiar unidades a otro grupo o periodo»*.
- **Sólo copia a alumnos que van por independiente en ese periodo.** Un destino
  que no lo sea vuelve como `saltado`, nunca como 400: la pantalla los está
  listando, y que uno se desmarque entre la carga y el clic es normal.

### 6.3 · `PUT boletin-independiente/periodo` · `auth.personal`

```jsonc
{ "alumno_id": 3311, "aplica": false }   // el periodo es el del usuario
→ { "alumno_id": 3311, "periodo_id": 91, "aplica": false }
```

`INSERT ... ON DUPLICATE KEY UPDATE` sobre `bol_ind_periodos`. **No borra ni una
fila de `unidades`, `subunidades` ni `notas`, nunca.** Hay un test que apaga y
enciende el interruptor y cuenta las filas antes y después.

### 6.4 · Los campos añadidos a lo que ya existe

Campos **añadidos**, no cambiados: las claves de hoy siguen todas ahí, y el
front tiene que tolerar que no vengan —`app/` es copia por colegio y durante el
despliegue habrá colegios con el código viejo.

| Endpoint | Campo | Qué dice |
|---|---|---|
| `PUT notas/detailed` (la planilla) | `independientes: [{alumno_id, nombres, apellidos, aplica}]` | **a quién NO estás viendo en esta lista**, para que la planilla lo diga en vez de que el docente crea que se le perdió un alumno |
| `PUT notas/detailed`, `Grupo::alumnos` | `alumno.bol_independiente` · `alumno.bol_independiente_periodo` | la marca del año y la del periodo. Con `bol_independiente=1` y `bol_independiente_periodo=false` el alumno **sí** sale en la lista y lleva el badge que pidieron |
| `PUT alumnos/guardar-valor` | acepta `propiedad: "boletin_independiente"` | un `case` más en la lista blanca de propiedades de matrícula (§6.6). **Cero rutas nuevas**, y **la ficha no manda `year_id`**: `putGuardarValor:911` lo saca del token cuando no viene |
| `PUT alumnos/show` | `boletin_independiente` | por donde **la ficha lee** la marca, al lado de `m.repitente`. El año es el del token. **Cuidado**: si el alumno no tiene matrícula ese año, `putShow` cae a una segunda consulta que sale sólo de `alumnos` (`:585`) y **el campo no viene** — `undefined` es «no matriculado este año», no «desmarcado». Con `nee` no pasa porque es columna de `alumnos` |
| boletines 1, 2 y 3 | `asignatura.bol_independiente: true` | para que el boletín pueda rotularlo si el colegio quiere; las unidades ya vienen siendo las suyas |
| puestos | `alumno.bol_independiente` · `puestos_con_bol_independiente` | el interruptor viaja en la respuesta para que la pantalla explique por qué falta alguien |

### 6.5 · Lo que NO hace falta escribir

Y es la mitad del valor del diseño de la §3:

- **Crear, editar, borrar y reordenar las unidades del alumno**: `POST unidades`
  con `alumno_id` en el cuerpo, y `unidades/update|destroy|update-orden` **tal
  cual** — van por id y el id ya es suyo.
- **Las subunidades**: `POST subunidades` sin cambio; nacen colgadas de una
  unidad que ya tiene dueño. `SubunidadesController::postIndex` sí cambia en un
  punto: **crea las notas de un solo alumno en vez de las del grupo** cuando la
  unidad tiene dueño.
- **Las notas**: `PUT notas/update/{id}` y `PUT notas/lote` sin tocar. Y con
  ellos, la bitácora, la guarda del periodo cerrado y el recálculo de la
  definitiva en la misma respuesta.

### 6.6 · La lista blanca de `guardar-valor`, y sus DOS modos de fallo

El front preguntó si el backend acepta esa propiedad, temiendo un «No guardado»
silencioso. **Medido el 24 ago, y son dos cosas distintas:**

1. **Sin el `case`, no es silencioso: es un 422.** `GuardarAlumno::valor` tiene
   un `switch` con casos literales; lo que no está cae al `default`, que hace
   `UPDATE alumnos SET ColumnaSegura::exigir('alumnos', $propiedad)`, y
   `boletin_independiente` **no existe en `alumnos`** —vive en `matriculas`—, así
   que `ColumnaSegura::exigir` corta con **422 «Propiedad no válida»**
   (`app/Support/ColumnaSegura.php:95`). Un colegio sin desplegar contesta 422 y
   se distingue de todo lo demás.
2. **El silencioso ya existe, y es de las veinte propiedades de hoy.** El método
   termina en `if ($res) return 'Guardado'; else return 'No guardado';` sobre lo
   que devuelve `DB::update`, **y MySQL devuelve 0 filas afectadas cuando el
   UPDATE no cambia ningún valor** — no cuando no encuentra la fila. Es
   exactamente lo que costó un test en rojo en el recalculador de definitivas.
   O sea: **marcar dos veces lo mismo contesta «No guardado» con 200 y el estado
   es correcto.** No lo introduce esta función y no se arregla aquí, pero el
   front tiene que saber que **ese texto no significa que haya fallado**, y quien
   escriba la pantalla no debe pintar un error con él.
   > **Medido después, y toca a esta función por partida doble**: son **4 sitios
   > y 6 rutas**, y una de ellas es `PUT years/toggle-cambiar-valor`, o sea **por
   > donde se guarda el interruptor de puestos de la §7**. Queda abierto en
   > [09 §13](09-pendientes.md) con sus tres opciones: **no se arregla en un solo
   > lado** y no lo decide una sesión.

---

## §7 — Los puestos, que es donde esto se ve desde fuera

`PuestosController` no calcula el puesto: devuelve `promedio` por alumno y el
puesto lo pone quien pinta (`Nota::puestoAlumno`, `app/Models/Nota.php:122`,
que cuenta cuántos promedios hay por encima). **Y ese mismo cálculo está copiado
en OCHO sitios**, medido hoy: los tres boletines
(`BoletinesController:235`, `Boletines2Controller:164`, `Boletines3Controller:169`),
los dos de finales (`Informes/BolfinalesController:233`, `BolfinalesController:114`),
los certificados (`CertificadosPersonaController:180`), `EditnotaController:215` y
`PromovidosController:136`. **El interruptor se lee en el servicio y los ocho
preguntan**, o habrá pantallas contando de una forma y papeles impresos contando
de otra — que es exactamente lo que le pasó a las definitivas con sus seis
escritores.

Con `years.puestos_con_bol_independiente = 0`:

1. el independiente **no sale** en la tabla de puestos del grupo;
2. **no cuenta para el puesto de los demás** — y esto es lo que hay que decir en
   voz alta: **si un independiente iba primero, los treinta de detrás suben un
   puesto**, en la pantalla **y en el boletín impreso**;
3. su propio boletín lleva `puesto: null`, que el front pinta `—`. Calcularle un
   puesto contra una lista de la que se le sacó sería inventarlo.

Por eso el interruptor **nace en 1** —lo de hoy— y cambiarlo es una decisión del
colegio con efecto visible, no un ajuste de pantalla. Va anotado en
[DESPLIEGUE.md](../DESPLIEGUE.md) cuando esto salga.

**Y desde el front son cuatro informes, no una pantalla** (`myvc-front-10`, 24
ago): `puestos_grupo_periodo`, `puestos_grupo_year`, `puestos_todos_periodo` y
`puestos_todos_year`, más el puesto impreso en `boletin-periodo` y
`boletin-final`. Encaja con los ocho sitios de arriba y decide una cosa: **el
interruptor viaja en la respuesta de todos ellos** (`puestos_con_bol_independiente`
y `alumno.bol_independiente`), en vez de que cada pantalla lo pregunte por su
cuenta. Si lo pregunta uno y otro no, los otros tres mienten.

### 7.1 · Y el día que un colegio lo ponga a 0 — la pregunta la hará un rector

Lo pidió el front el 24 ago, y la respuesta es de las que hay que dar medidas:

- **Es reversible y no deja rastro.** El interruptor es un `tinyint` en `years`:
  no borra ni escribe nada en `notas`, `notas_finales` ni `matriculas`. Volver a
  1 devuelve exactamente los números de antes.
- **Pero no hay foto de lo que se imprimió, y ésa es la parte incómoda.** El
  puesto **no se guarda en ninguna tabla que la API lea**: se calcula al vuelo en
  cada carga, en los ocho sitios de arriba. Así que el papel ya entregado se
  queda como está en el papel, y **reimprimir ese mismo boletín dará otro
  puesto**. No es culpa del interruptor —hoy pasa igual si alguien corrige una
  nota del periodo pasado—, pero con el interruptor el cambio es **masivo y en un
  solo clic**.
- **Las tablas `df_*` no sirven de foto.** Tienen `puesto` y `puntaje`
  (`df_grupos:756`) y parecen justo eso, pero **no las lee nadie**: medido, **0
  ficheros de `app/` las mencionan**. Son un congelado de años pasados que quedó
  huérfano.
- **La recomendación, por tanto: que el colegio lo decida antes de emitir el
  primer boletín del año** y no a mitad. Y que la pantalla de ajustes lo diga con
  esas palabras, no con un tooltip.

---

## §8 — Lo que le toca al front

Cuatro pantallas. Ninguna se publica hasta que el backend esté **desplegado en
los dieciséis** (§10).

1. **Marcar al alumno.** Un interruptor más en la ficha, al lado del de PIAR.
   Es `PUT alumnos/guardar-valor` con `propiedad: "boletin_independiente"` — el
   endpoint que la ficha ya usa para veinte campos. **Es la pantalla más barata
   de las cuatro y la que hay que hacer primero**, porque sin ella no hay forma
   de probar ninguna otra con datos de verdad.
2. **La planilla de notas, dos cambios pequeños.** El alumno marcado ya no viene
   en `alumnos`: la pantalla tiene que **decir a cuántos no está viendo** con
   `independientes` y llevar a la pantalla nueva. Y el que tiene la marca del
   año pero `bol_independiente_periodo: false` **sí viene en la lista** y lleva
   el badge: *«este periodo va con el grupo»*.
   > **Ese badge cae en una celda que acaba de cambiar**, y lo avisó el front el
   > 24 ago: esa misma noche se restauró en ella la marca de NEE **con una
   > excepción por colegio**, los marcadores `(Asis)`/`(Prem)`, la foto y la
   > numeración. **Ya son cuatro cosas condicionadas y una excepción**, así que el
   > badge se añade mirando la celda, no encima de ella.
3. **La pantalla nueva**, por asignatura y periodo: la lista de alumnos
   marcados, y bajo cada uno sus unidades y subunidades con la casilla de la
   nota. Tres cosas que no son adorno:
   - el interruptor **«este periodo no tiene boletín independiente»** por alumno,
     que **no borra nada** y deja lo escrito en gris;
   - **la suma de porcentajes** visible por alumno, en rojo cuando no es 100;
   - **copiar a los demás**, dos botones: *copiar estructura* y *copiar
     estructura y notas*, y enseñar lo que devuelve — incluidos los `saltado`.
4. **Puestos**: enseñar por qué falta alguien cuando el interruptor está en 0, y
   pintar `—` en el puesto del independiente.

**Lo que el front NO tiene que construir:** un editor de unidades nuevo. Son los
mismos endpoints de `unidades` y `subunidades` que ya usa la pantalla de
estructura, con `alumno_id` en el cuerpo al crear la unidad. Si acaba habiendo
dos editores, uno de los dos se va a quedar viejo.

**Y lo que no tiene que construir aunque parezca que sí:** comportamiento. No va
en este módulo (§1).

---

## §9 — Los riesgos, medidos

### 9.1 · El alumno que se cae por el hueco — **es el grave**

La decisión 1 dice que la marca vale para **todas** las asignaturas. Un alumno
de once asignaturas al que su profesor de Educación Física no le crea ninguna
unidad **desaparece de la planilla de esa asignatura y no aparece en ninguna
otra parte**: su definitiva sale 0, el boletín le imprime la asignatura vacía y
**nadie recibe un error**. Es el mismo patrón de la §4 del [10](10-definitivas.md)
—un 0 que significa «no hay nada» y se lee como «sacó cero»—.

**No se arregla con un aviso al final del periodo.** Se arregla con tres cosas,
y las tres van en la fase 4:

- `unidades: []` viaja en la respuesta y **la pantalla lo pinta**, no lo esconde;
- `porcentaje_unidades` distinto de 100 se ve **por alumno**;
- una consulta de coordinación —`tools/independientes-sin-estructura.php`— que
  liste, para un periodo, **qué pares (alumno, asignatura) están marcados y no
  tienen ni una unidad propia**, con su población delante: *«revisadas 11
  asignaturas × 3 alumnos = 33 pares; 4 sin estructura»*.

### 9.2 · Las 74 consultas

Es el trabajo de la fase 1 y su forma de fallar es callada: una consulta sin
alcance **sigue devolviendo filas**, sólo que las de otro. Dos formas de
equivocarse, y las dos ya han pasado en este repo:

- **de más**: la planilla del grupo suma las unidades del independiente y las
  definitivas de los treinta salen infladas;
- **de menos**: el boletín del independiente pide las del grupo, no encuentra
  sus notas y le imprime la asignatura en blanco.

Por eso la fase 0 es un **detector**, y por eso imprime su población. Y por eso
la §4 exige que **los 1.344 tests pasen sin regenerar un solo snapshot**: los
snapshots de contrato son, esta vez, el detector más fiel que hay — están
escritos sobre un colegio sin ningún alumno marcado, así que cualquier consulta
a la que se le olvide el alcance mueve alguno.

> Y la advertencia del `CLAUDE.md` aplica entera: **el primer sitio donde mirar
> cuando el número salga raro es el detector**. Un `0 sin alcance` puede
> significar «las 74 están bien» o «no encontré ninguna consulta».

### 9.3 · El alumno que se desmarca a mitad de periodo

Escenario real: se marca, el docente le crea sus unidades y le pone notas, y a
la semana el colegio marca *«este periodo no»*. El alumno vuelve a la planilla
del grupo y **no tiene notas en las subunidades del grupo**. `verificarCrearNotas`
se las crea en la siguiente carga de `/notas` —ya lo hace hoy con cualquier
alumno nuevo—, pero **desde Flutter, que no llama a `/notas` nunca**, esa
ventana puede durar días: es exactamente la §5.1 del [10](10-definitivas.md).

**Por eso `PUT boletin-independiente/periodo` crea las notas que falten dentro de
su propia transacción**, en vez de esperar a que alguien abra una pantalla.

### 9.4 · Copiar un periodo

`PUT periodos/copiar` copia la estructura de un periodo al siguiente y avisa al
recalculador (fase 3 del 10, hecha el 24 ago) — y **copia también las notas si se
lo piden**, según su propio test. **Tiene que copiar también las
unidades con dueño**, y sólo para los alumnos que sigan marcados en el periodo
destino. Si se olvida, el periodo nuevo empieza con los independientes sin nada
y volvemos a la §9.1. Hay test: `CopiarUnidadesTest.php` existe y hay que
ampliarlo, no escribir otro.

### 9.5 · Se lee de una matrícula y se escribe en otra — encontrado el 24 ago

Salió comprobando una pregunta del front (`myvc-front-10`) que resultó no ser el
problema. **La ficha y el guardado no eligen la matrícula del año de la misma
manera:**

| | Consulta | `m.deleted_at` | `m.estado` | `ORDER BY` | Se queda con |
|---|---|---|---|---|---|
| **escribe** | `GuardarAlumno::valor:62-67` | **no filtra** | **no filtra** | **ninguno** | `[0]` |
| **lee** | `AlumnosController::putShow:562-573` | filtra | no filtra | `a.apellidos` | `[0]` |

Un alumno con **dos matrículas en el mismo año** —cambió de grupo a mitad de
curso, o una quedó borrada— puede **leerse de una y escribirse en otra**. Hoy eso
ya pasa con `repitente`, `promovido` y `nro_folio`, **y nadie lo ha visto porque
nadie mira esos campos al día siguiente**.

**Con esta marca se ve al día siguiente y en la pantalla de otro**: el alumno
sigue apareciendo entre los normales de la planilla y la ficha jura que está
marcado. Y el front lo afinó todavía más (`myvc-front-10`): **el sitio donde se
ve no es la ficha, es la planilla de OTRO docente**, sin ninguna marca, y **quien
lo note no tiene forma de relacionarlo con un interruptor que alguien tocó en otra
pantalla**. Por eso va en la fase 2 y no después: con la pantalla ya hecha, el
primer informe sería *«el interruptor no guarda»* y se buscaría en el front. Es de la familia de la §28 —`WHERE actual=1` quedándose con el primero
sin `ORDER BY`— y se arregla igual: **una sola regla de cuál es la matrícula del
año, compartida por la lectura y la escritura**, con su test de un alumno con dos
matrículas. Va en la fase 2, antes de que exista la primera pantalla.

---

## §10 — Despliegue y orden

- **Esto lleva migraciones de esquema, y son las primeras en tocar tablas de
  producción de los dieciséis colegios.** `unidades`, `matriculas` y `years` son
  tablas grandes y vivas: un `ALTER TABLE` sobre `unidades` bloquea la escritura
  de notas mientras dura. Hay que medir el tamaño real de `unidades` en el
  colegio más grande **antes**, con el mismo `for` de una línea de la fase 0 de
  las definitivas.
- **Ningún cambio a mano en phpMyAdmin: migración o no existe.**
- **Orden respecto a las definitivas**: la [fase 2 del 10](10-definitivas.md)
  —los índices únicos de `notas_finales`— **va antes o va después, pero no a la
  vez**. Las dos tocan el mismo camino de escritura y, si algo sale mal en el
  despliegue, con las dos dentro no se sabe cuál fue. La recomendación es
  **definitivas primero**: lleva más tiempo medido y ya está esperando los
  dieciséis números.
- **El front no publica hasta que el backend esté DESPLEGADO**, no fusionado. En
  un colegio sin desplegar, la pantalla nueva es un 404 y el interruptor de la
  ficha un «No guardado» silencioso.
- **`myvc_flutter` es una sola app para los dieciséis.** Hoy la app crea
  subunidades y pone notas: mientras no sepa de esto, a un alumno marcado le
  seguirá enseñando la planilla del grupo. **No se rompe** —las unidades del
  grupo siguen existiendo— pero enseña una planilla incompleta. Hay que
  decidir, cuando esto se despliegue, si la app oculta a los independientes o
  los enseña con un aviso.

---

## §11 — Los tests, y cuáles encuentran algo

La regla de `tests/Contrato/`: comprueban que **la respuesta no ha cambiado**, y
lo que los hace encontrar cosas es **mirar el resultado y no el estado**.

| Test | Qué mira, y por qué ése |
|---|---|
| `BolIndependienteNoMueveNadaTest` | **el de la §4**: con la migración puesta y nadie marcado, las respuestas de planilla, boletines 1/2/3, puestos y definitivas son las de antes. Es el que hace desplegable la fase 1 |
| `BolIndependienteDefinitivaTest` | **ida y vuelta**: marcar, crear estructura propia, poner notas, leer la definitiva. Que salga el número que sale de SUS porcentajes, no de los del grupo |
| `BolIndependienteNoBorraTest` | apagar y encender `aplica` **contando filas de `unidades`, `subunidades` y `notas` antes y después**. Un borrado no se ve en la respuesta |
| `BolIndependienteVuelveALaPlanillaTest` | con `aplica=0`, el alumno vuelve a `alumnos` en `notas/detailed`, **con sus notas del grupo creadas** (§9.3) y con el badge |
| `BolIndependienteBoletinTest` | el boletín del independiente trae **sus** subunidades y **ninguna** del grupo. Y el del compañero de al lado, ninguna de las suyas |
| `BolIndependientePuestosTest` | el interruptor en los dos valores, **comprobando que el puesto de un tercero cambia** — que es el efecto que nadie espera (§7) |
| `BolIndependienteCopiarTest` | copiar con y sin notas, un destino que ya tiene estructura (`saltado`) y uno que dejó de estar marcado |
| `SuperficieDeUnTokenTest` | el barrido que ya existe: que un docente no pueda escribirle estructura a un alumno de un grupo que no es suyo |

Y el que no es un test sino una herramienta: **`tools/unidades-sin-alcance.py`,
que se corre en cada fase y siempre imprime su población.**

---

## §12 — La coordinación con el front

> **El canal es un fichero del front, no este documento.**
> `~/DESARROLLOS/myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, sección **C**, es
> el buzón: **toda decisión que cambie un cuerpo, un nombre de campo o una ruta se
> escribe ahí**, además de aquí. Lo pidió Joseth el 24 ago después de que este
> plan estuviera un día escrito sin que nadie del front lo viera — **el front no
> lee este repo por su cuenta**.

**Comunicado a `myvc_front` el 24 ago 2026** (sesión `myvc-front-12`, la
coordinadora). Las cuatro sesiones del front se habían cerrado ya, así que **el
plan lo revisa la sesión de mañana** y entra en su traspaso con la ruta de este
documento.

**Sus tres avisos ya están incorporados**, y los tres mejoraron el plan:

1. **La celda del alumno de la planilla acaba de cambiar** — §8, punto 2.
2. **El vacío tiene que decir por qué está vacío**, con tres casos de esa misma
   madrugada detrás: una pantalla que enseñaba el promedio correcto y las notas
   vacías, y tres columnas que **creaban notas** porque su lista no traía
   identificador. De ahí sale el `motivo` de la §6.1, que era el punto más flojo
   del plan: decía «píntalo» sin dar con qué distinguirlo.
3. **Qué pasa el día que el interruptor de puestos se pone a 0** — §7.1, que no
   estaba.

Y su pregunta contestada: **la propiedad nueva de `guardar-valor` falla con 422,
no en silencio** — pero *«No guardado» con 200 sí existe hoy* y no significa que
haya fallado (§6.6).

**Y una segunda vuelta el 24 ago con `myvc-front-10`**, que inventarió las
pantallas y trajo cuatro preguntas. **Contestadas en la sección C de su fichero**,
y las cuatro caben sin ruta nueva:

1. **`year_id` no era un problema**: sale del token si no viene. Pero comprobarlo
   destapó la §9.5, que sí muerde.
2. **La ficha lee la marca de `alumnos/show`**, con la trampa del alumno sin
   matrícula (§6.4).
3. **Puestos son cuatro informes más el papel**: el interruptor viaja en los seis
   (§7).
4. **Los botones de copiar son de la pantalla nueva**, y `copiar-unidades` es otra
   operación (§6.2).

**Lo que el front pidió de vuelta, y esto lo decide Joseth, no una sesión:**

- si el backend **empieza la fase 1 antes** de que ellos revisen el plan, hay que
  decírselo para que el relevo lo escriba así;
- **avisar cuando la tanda pendiente esté desplegada**: tienen cuatro cosas
  esperándola, incluida una que **cambia el cuerpo de una petición** y está
  congelada a propósito. Está en [DESPLIEGUE.md](../DESPLIEGUE.md).
