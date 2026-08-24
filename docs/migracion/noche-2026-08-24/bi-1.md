# BI-1 — el esqueleto del boletín independiente

**Sesión `8myvc-9e`, noche del 24 ago 2026.** Rama
`feat/boletin-independiente-esqueleto`, árbol `.worktrees/9e`, base
`simonbolivar_testing_9e`. Es la **fase 1** de
[19-boletin-independiente.md](../19-boletin-independiente.md) y nada más: sin
rutas nuevas, sin pantallas, sin tocar `notas` ni `notas_finales`.

> ## ⚠️ ESTA RAMA AÑADE MIGRACIONES
>
> **Cuatro cambios de esquema.** Las bases de test de las demás sesiones se
> quedan viejas en cuanto esto se funda, y lo van a ver como **tests de contrato
> en rojo con mensajes creíbles** —columna desconocida, tabla que no existe—, que
> es la forma de fallo que se lee como un bug de verdad. Se arregla con
> `tools/construir-bd-test.sh` (o con `DB_TEST_DATABASE=…_x` delante, para la
> suya).

---

## §1 — Lo entregado, y lo que deliberadamente no

| | Qué | Estado |
|---|---|---|
| 1 | Las cuatro migraciones de la §3 del plan | **hecho** — un solo fichero, `2026_08_24_100000_boletin_independiente_esqueleto.php` |
| 2 | `App\Services\BoletinIndependiente`, el servicio único | **hecho** — sin `copiar()`, que necesita las rutas |
| 3 | El alcance en la puerta de los boletines | **hecho** — y son **una función, no dos**: ver §4 |
| 4 | El interruptor de puestos leído en un servicio | **hecho** — y con una corrección de premisa: ver §6 |
| 5 | **El inventario clasificado de las 74 + 70 consultas** | **hecho** — §5, y es lo que más movió |
| 6 | `tools/unidades-sin-alcance.py`, la fase 0 | **hecho** — no existía |

**Lo que NO entra, y por qué:**

- **Las tres rutas nuevas.** Seguimos en **542, no 545**. Una ruta nueva es una
  decisión, mueve tres documentos y dos snapshots, y **dos decisiones del plan
  siguen abiertas** (§2 del 19: quién puede marcar a un alumno, y qué puesto
  lleva su boletín cuando el interruptor dice que no cuentan).
- **`notas` y `notas_finales`, ni una línea.** Es lo que hace que `notas/update`,
  `notas/lote`, la bitácora y el recalculador único sigan funcionando sin cambio.
- **`DefinitivasPeriodosController.php` y `NotasController.php` no se han
  tocado**: los tiene otra sesión. Aparecen en el inventario de la §5 como
  trabajo **de quien los lleve**, no mío.
- **`GuardarAlumno::valor`** —el `case 'boletin_independiente'`— es la fase 2.
  Consecuencia para el front, ya avisada: **`PUT alumnos/guardar-valor` con esa
  propiedad sigue contestando 422**, que es la contradicción de su §B.2.

---

## §2 — El criterio de aceptación: se cumple

> **Con las migraciones puestas y nadie marcado, los 1.344 tests pasan sin
> regenerar un solo snapshot.**

**No he regenerado ninguno.** Si hubiera hecho falta, paraba y lo decía: un
snapshot que se mueve en la fase 1 no es un snapshot que se regenera, es una
consulta a la que se le olvidó el alcance — y dejaría de ser aditivo, que es lo
único que permite desplegar esto en dieciséis colegios antes de que exista una
pantalla.

Lo que lo hace cierto es una línea de MySQL y conviene que esté escrita:
`alcance()` devuelve `null` para todo el mundo mientras
`matriculas.boletin_independiente` sea 0 —que es como nace—, y
`u.alumno_id <=> NULL` selecciona **exactamente** las filas de hoy.

**`<=>` y no `=`.** Con el igual a secas la rama del alumno normal no empareja
NULL y devuelve cero filas: **todas las definitivas del colegio se van a 0** sin
un solo error en el log. Es el fallo más caro que esta fase podía introducir y no
da ninguna señal, así que está escrito en el servicio, en la migración y aquí.

---

## §3 — Las migraciones, y el choque que el plan no veía

Las cuatro de la §3 del 19, en un solo fichero y sin cambiarles nada… **salvo un
aviso que hay que leer antes de desplegar.**

### `years` ya tenía DOS interruptores de puestos

Lo levantó `myvc-front-98` desde el front —ellos ya leen uno hoy— y lo verifiqué
en el esquema congelado (`database/schema/mysql-schema.sql:2211-2212`):

| Columna | Por defecto | Qué decide |
|---|---|---|
| `mostrar_puesto_boletin` | 1 | si el puesto **se imprime** en el boletín |
| `puestos_alfabeticamente` | 0 | cómo **se ordena** la lista |
| `puestos_con_bol_independiente` | 1 | *(nueva)* si el independiente **cuenta** |

**No son la misma pregunta y no se funden.** Pero se cruzan, y lo medí porque una
recomendación sin número no sirve:

> **1 de los 8 años vivos de esta base tiene `mostrar_puesto_boletin = 0`.**

En ese año, **toda la §7 del plan no se ve por ninguna parte**: ni el `—` del
independiente, ni el puesto de un tercero que se mueve. El interruptor nuevo es
peso muerto ahí. No cambia cómo se implementa; cambia **cómo se le explica a un
rector**, y por eso va en la §7 del 19 y no en un comentario. `grep -c
mostrar_puesto_boletin` sobre el 19 daba **0**: el plan no lo nombraba ni una vez.

### Lo que hay que medir antes de desplegar, y no se puede medir aquí

`unidades` es una tabla grande y viva, y un `ALTER TABLE` sobre ella **bloquea la
escritura de notas mientras dura**. Hace falta el tamaño real en el colegio más
grande, con el mismo `for` de una línea de la fase 0 de las definitivas. **Es
servidor: se deja escrito, no se corre.**

Y `down()` **pierde datos en cuanto alguien esté marcado** —borra la tabla del
interruptor por periodo y la columna del dueño, así que las unidades propias se
quedan huérfanas y vuelven a leerse como del grupo—. Mientras nadie esté marcado,
que es todo lo de esta noche, es exactamente reversible. Está dicho en el `down()`
mismo, no sólo aquí.

---

## §4 — «Las dos funciones» son una, y la otra hereda

El plan dice que los tres boletines se cubren en `Unidad::deAsignaturaCalculada` y
`Subunidad::deUnidadCalculada`. **Medido, sólo la primera necesita algo.**

`Subunidad::deUnidadCalculada` va por `where s.unidad_id = :unidad_id`, y la
unidad **ya viene elegida** de la llamada anterior. Los cuatro consumidores
tienen exactamente esta forma:

```php
$asignatura->unidades = Unidad::deAsignaturaCalculada($alumno->alumno_id, …);
foreach (…)  $unidad->subunidades = Subunidad::deUnidadCalculada($alumno->alumno_id, $unidad->unidad_id, …);
```

Si la primera trae las unidades del dueño correcto, la segunda trae sus
subunidades y no hay nada que decidir. **Acotarla también sería escribir una
segunda regla para la misma pregunta**, que es justo lo que este diseño evita.

### Y son CUATRO consumidores, no tres

El plan habla de «los tres boletines». `grep` dice cuatro:

| Consumidor | Línea |
|---|---|
| `Informes/BoletinesController` | 303 |
| `Informes/Boletines2Controller` | 226, 228 |
| `Informes/Boletines3Controller` | 238 |
| **`Informes/NotasActualesAlumnosController`** | **187** |

El cuarto no aparece en el 19 ni una vez. Como el alcance se puso **dentro de la
función**, los cuatro quedan cubiertos igual — pero si alguien hubiera acotado
«los tres boletines» desde fuera, llamada a llamada, el cuarto se habría quedado
enseñando las unidades del grupo a un independiente. **Es el argumento entero a
favor de meter la regla en la función y no en sus llamantes.**

---

## §5 — El inventario: 146 lecturas en 24 ficheros

**Ésta es la parte que se pidió y la que más costó, y no por el recuento.**

### La población, dicha antes que ningún resultado

- **218 ficheros `.php` recorridos** bajo `app/`;
- **24 nombran una de las dos tablas**;
- **75 lecturas de `unidades`** y **71 de `subunidades`** — 146 en total;
- más **4 escrituras** (`UPDATE`), que son otra pregunta y están al final.

**El plan decía 74 y 70. Cuento 75 y 71, y la diferencia no es un hallazgo: es
una definición.** Aquí se cuenta **cada referencia a la tabla**, y
`ChangeAskedController:511` nombra `unidades` **dos veces en la misma consulta**
—una en el `FROM` y otra dentro de una subconsulta—. Con el criterio «una
consulta, una fila» salen 74; con «una referencia, una fila» salen 75. Las dos
son ciertas y **la segunda es la que hay que usar para este trabajo**, porque lo
que se acota es cada referencia, no cada consulta.

### La clasificación

La pregunta útil no es «¿nombra `alumno_id`?» —ninguna lo hace hoy, la columna no
existía— sino **cómo elige el conjunto de filas**, porque ahí es donde mañana
caben dentro las de otro:

| Clase | `unidades` | `subunidades` | Veredicto |
|---|---|---|---|
| `por-id` — la fila viene nombrada | 6 | 14 | **bien por construcción** |
| `por-nota` — se llega desde la nota de un alumno fijado | 34 | 34 | **bien por construcción** |
| `por-alumno` — conjunto por asignatura, con alumno nombrado | 4 | 4 | **hay que acotarla** |
| `por-asignatura` — conjunto por asignatura, sin alumno | 31 | 18 | **hay que acotarla** |
| `mas-ancho` | 0 | 1 | **no se sabe** |

**40 de las 75 lecturas de `unidades` quedan bien sin tocarlas**, y eso es el
diseño funcionando: `notas` no se toca, así que todo lo que camina desde una nota
llega a la unidad de su dueño **sin que nadie escriba un alcance**.

### Fichero a fichero

| Fichero (`app/`) | Lecturas | Bien por construcción | Hay que acotar | No se sabe |
|---|---|---|---|---|
| `Console/Commands/EnviarNotificaciones.php` | 2 | 0 | 2 | 0 |
| `Http/Controllers/AlumnosController.php` | 6 | 6 | 0 | 0 |
| `Http/Controllers/AsignaturasController.php` | 2 | 1 | 1 | 0 |
| `Http/Controllers/BolfinalesController.php` | 6 | 6 | 0 | 0 |
| `Http/Controllers/ChangeAskedController.php` | 5 | 1 | 4 | 0 |
| `Http/Controllers/DefinitivasPeriodosController.php` | 2 | 0 | 2 | 0 |
| `Http/Controllers/DetallesController.php` | 8 | 8 | 0 | 0 |
| `Http/Controllers/Historiales/HistorialesController.php` | 1 | 0 | 0 | **1** |
| `Http/Controllers/Informes/BolfinalesController.php` | 4 | 4 | 0 | 0 |
| `Http/Controllers/Informes/CalcPerdidasDefinitivas.php` | 20 | 20 | 0 | 0 |
| `Http/Controllers/Informes/CertificadosPersonaController.php` | 4 | 4 | 0 | 0 |
| `Http/Controllers/Informes/InformesController.php` | 2 | 0 | 2 | 0 |
| `Http/Controllers/Informes/NotasPerdidasController.php` | 8 | 0 | 8 | 0 |
| `Http/Controllers/Informes/PuestosController.php` | 10 | 10 | 0 | 0 |
| `Http/Controllers/NotasController.php` | 10 | 7 | 3 | 0 |
| `Http/Controllers/PeriodosController.php` | 2 | 1 | 1 | 0 |
| `Http/Controllers/SubunidadesController.php` | 3 | 1 | 2 | 0 |
| `Http/Controllers/UnidadesController.php` | 6 | 2 | 4 | 0 |
| `Models/Bitacora.php` | 2 | 2 | 0 | 0 |
| `Models/NotaFinal.php` | 10 | 0 | 10 | 0 |
| `Models/Subunidad.php` | 6 | 4 | 2 | 0 |
| `Models/Unidad.php` | 9 | 1 | 8 | 0 |
| `Services/DefinitivasDeAsignatura.php` | 13 | 5 | 8 | 0 |
| `Support/PeriodoDeLaFila.php` | 5 | 5 | 0 | 0 |
| **Total** | **146** | **88** | **57** | **1** |

### Las que hay que acotar, con nombre

**De las 35 lecturas de `unidades` que quedan por acotar, tres ya lo están** —las
tres variantes de `deAsignaturaCalculada`, hechas esta noche—. Las 32 restantes,
por orden de lo que muerde:

| Dónde | Qué pasa si se olvida |
|---|---|
| `Services/DefinitivasDeAsignatura.php` :324 `calcular`, :375 `selloDeVersion` (×3), :460 `porcentajeDeLasUnidades` | **el «de más» de la §9.2**: la definitiva del independiente sumaría las unidades del grupo **y** las suyas. Es el sitio único que escribe definitivas |
| `Models/NotaFinal.php` :57 (×4), :267 `calcularAsignaturaPeriodo` | lo mismo por el camino viejo, y alimenta boletines finales y actas |
| `Informes/NotasPerdidasController.php` :54, :64, :269, :284 | el informe de perdidas contaría las del grupo como suyas |
| `UnidadesController.php` :22, :57, :351, :390 · `SubunidadesController.php` :345 | la **pantalla de estructura del docente**: aquí lo correcto es casi seguro `u.alumno_id IS NULL`, no el `<=>` — es la lista del grupo |
| `NotasController.php` :49, :118 · `DefinitivasPeriodosController.php` :107 | **de otra sesión esta noche**. Anotado, no tocado |
| `Models/Unidad.php` :73 `deAsignatura`, :184 `informacionAsignatura` · `Models/Subunidad.php` :137 | estructura y porcentajes |
| `AsignaturasController.php` :52 · `PeriodosController.php` :274 `putCopiar` · `InformesController.php` :107 · `ChangeAskedController.php` :511 (×2), :1229 · `EnviarNotificaciones.php` :195 | el resto. `putCopiar` es además la §9.4: **tiene que copiar también las unidades con dueño** |

### Y las que el detector marca y hay que mirar a mano

Dos, y las digo porque **un detector da sitios donde mirar, nunca una lista de
fallos**:

1. **`EnviarNotificaciones:195`** sale como «hay que acotarla» y **casi seguro no
   lo es**: llega `bitacoras → notas → subunidades → unidades`, o sea camina desde
   una nota concreta. La marca porque el enlace es `n.id = b.affected_element_id`
   —columna, no parámetro— y la comprobación pide un parámetro. **Falso positivo
   de la definición, no del código.**
2. **`HistorialesController:135 putSesion`**, la única `mas-ancho` de las 146: lee
   `subunidades` desde `bitacoras` sin pasar por `unidades` en ningún momento. No
   sé clasificarla sin decidir antes qué tiene que enseñar esa pantalla, y esa
   decisión es del [18](../18-auditoria.md), no de aquí.

### Las 4 escrituras, que son otra pregunta

`Unidad::arreglarOrden` (×2), `SubunidadesController::putRestore` y
`UnidadesController::putRestore`. **Las cuatro van por `WHERE id = ?`**, así que
ninguna necesita alcance: quien tiene el id ya tiene la fila de su dueño. Lo que
sí necesitan es **guarda de autorización**, y ésa es una pregunta anterior a esta
función y no la abro aquí.

---

## §6 — Los puestos: la premisa del plan es falsa, y no lo he «arreglado»

El plan dice que `Nota::puestoAlumno` está copiado en ocho sitios y que **el
interruptor se lee en un servicio y preguntan los ocho**. Lo primero es cierto y
lo verifiqué —ocho llamadas, todas de impresión: `BoletinesController:235`,
`Boletines2:164`, `Boletines3:169`, `BolfinalesController:114`,
`Informes/Bolfinales:233`, `CertificadosPersona:180`, `Editnota:215`,
`Promovidos:136`—.

**Lo que no es cierto es la parte que venía del front, y la corrigió `8myvc-34`:**

- **Las cuatro rutas de puestos no calculan puesto.** Devuelven `promedio`, y el
  front pinta **la posición de fila** (`$index + 1`). **Ninguna de las ocho
  llamadas es de `PuestosController`.**
- Así que un servicio que hiciera «que los seis contesten lo mismo» **le cambiaría
  la conducta a cuatro informes que hoy no preguntan nada**. Eso es una decisión,
  no una limpieza. **No la he tomado.** El servicio existe y contesta; quién le
  pregunta es de la fase 6.
- Y la corrección de la corrección, comprobada en `app/Models/Nota.php:122`:
  **`puestoAlumno` arranca en 1**, no en 0. Es 1-based igual que la posición de
  fila, así que **la única diferencia entre los cuatro informes y los dos
  boletines aparece con empates**: la posición da `1,2,3,4`; el puesto da
  `1,1,3,4`. **El empate es el caso que distingue todo**, y sin él un test de esto
  pasa sin probar nada.

### Y una regla del servicio que ahorra un fallo silencioso

`puestosCuentanIndependientes()` contesta **«¿está activado el interruptor?»** y
**nunca «¿se enseña el puesto?»**. El front aplica una segunda regla que este lado
no ve: esconde el puesto al `Acudiente` y al `Alumno` aunque el año lo tenga
activado. Si el servicio contestara «se enseña», o le filtraría el puesto a las
familias por su cuenta o dejaría muerta la regla del front — **las dos en
silencio, y las dos son dos sitios decidiendo lo mismo con criterios distintos**,
que es de lo que salió el recalculador único.

---

## §7 — Lo que enseñó el detector, que es la mitad del trabajo

`tools/unidades-sin-alcance.py` no existía; la fase 0 lo pedía por nombre.

**Y la lección no es el número: es que en una noche di CUATRO números distintos
para la misma pregunta, sin que cambiara una línea de `app/`.**

| Definición | «bien por construcción» (unidades) |
|---|---|
| ¿nombra `alumno_id`? | 0 |
| ¿tiene un `id = ?` en cualquier sitio? | 35 |
| ¿tiene un `id = ?` **de su propio alias**? | 7 |
| ¿se **elige** por su asignatura, o se **alcanza** desde una nota? | **40** |

**Ninguno de los cuatro era un hallazgo. Los cuatro eran definiciones.** Por eso
la definición está escrita en la cabecera del script, antes que el código, y por
eso el script **imprime siempre su población**: un `0 sin alcance` significa «las
75 están bien» o «no encontré ninguna consulta», y de las dos lecturas la falsa
es la que hace archivar el asunto.

### Y la distinción que decide, que casi se cuela al revés dos veces

> Una unidad se **ELIGE** cuando la consulta filtra por su propia
> `asignatura_id`: ahí hay un conjunto, y ahí es donde mañana caben las de otro.
> Se **ALCANZA** cuando se llega a ella caminando desde una nota o una subunidad:
> ahí no hay conjunto, hay una fila que ya es de su dueño.

Y dentro de eso, **el `INNER JOIN` contra el `LEFT JOIN` a `notas`**, que es lo
más fino y lo que más importa:

```sql
inner join notas n on … and n.alumno_id = :alumno_id   -- ACOTA: sólo sobrevive la unidad que sostiene una nota SUYA
left  join notas n on … and alumno_id  = :alumno_id    -- NO ACOTA: la unidad sale igual, con la nota a NULL
```

**El segundo es literalmente `Unidad::deAsignaturaCalculada`**: nombra al alumno
en la línea de al lado **y aun así hay que acotarla**, porque es la puerta de los
cuatro boletines. Un detector que sólo mire «¿nombra al alumno?» da las dos por
buenas, y la segunda es la que imprime el boletín.

---

## §8 — Lo que queda abierto, y de quién es

1. **Las dos decisiones del plan siguen sin contestar** (§2 del 19): **quién puede
   marcar a un alumno**, y **qué puesto lleva su boletín** cuando el interruptor
   dice que no cuentan. Sin ellas no entran las rutas.
2. **Unificar los cuatro informes de puestos con los dos boletines**: medido en la
   §6, **es un cambio de conducta**. Decisión de Joseth, vía `8myvc-34`.
3. **El tamaño de `unidades` en el colegio más grande**, antes del `ALTER TABLE`.
   Servidor.
4. **La §9.5 —una sola regla de cuál es la matrícula del año— sigue viva.** El
   servicio implementa **una** regla (la más reciente de las vivas, con desempate
   por `id`) y lo dice en su docblock, para no añadir **una tercera**. Unificarla
   con `GuardarAlumno::valor` y `AlumnosController::putShow` es la fase 2, y esos
   dos ficheros los lleva otra sesión esta noche.
5. **`putCopiar` tiene que copiar las unidades con dueño** (§9.4 del 19), y
   `CopiarUnidadesTest` existe y hay que **ampliarlo**, no escribir otro.

---

## §9 — Para el front

Escrito también en la **sección C** de
`~/DESARROLLOS/myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, que es el canal —el
front no lee este repo por su cuenta—. En una línea: **esta noche ninguna
respuesta que el front consuma cambia ni un byte**, no hay ruta nueva y el 422 de
su §B.2 sigue vivo.
