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
| 1 | Las migraciones de la §3 del plan | **tres de las cuatro.** `years.puestos_con_bol_independiente` **se movió a la fase 2** — §5.ter |
| 2 | `App\Services\BoletinIndependiente`, el servicio único | **hecho** — sin `copiar()`, que necesita las rutas |
| 3 | El alcance en la puerta de los boletines | **hecho** — y son **una función, no dos**: ver §4 |
| 4 | El interruptor de puestos leído en un servicio | **movido a la fase 2**, no sin hacer — §5.ter y §6 |
| 5 | **El inventario clasificado de las 74 + 70 consultas** | **hecho** — §5, y es lo que más movió |
| 6 | `tools/unidades-sin-alcance.py`, la fase 0 | **hecho** — no existía |
| 7 | Cuatro consultas que el `ALTER TABLE` rompía con un 500 | **arregladas** — §5.bis, y no estaban previstas |
| 8 | Ocho `SELECT *` que metían la columna nueva en respuestas vivas | **arregladas** — §5.ter. Dos quedan **pedidas** a otras sesiones |

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

## §2 — El criterio de aceptación: **NO se cumple. Cinco snapshots se mueven**

> **Con las migraciones puestas y nadie marcado, los 1.344 tests pasan sin
> regenerar un solo snapshot.**

**Medido en máquina limpia el 24 ago: `Tests: 5 failed, 1358 passed (9182
assertions), 593,20 s`.** El esqueleto **mueve cinco snapshots**, así que **no es
aditivo y no está desplegable**. No he regenerado ninguno y no es mío decidirlo:
regenerarlos rompe la promesa con la que se justificó desplegar el backend antes
de que exista una pantalla, y eso hay que decírselo al front y a `myvc_flutter`,
que es **una sola app para los dieciséis**.

La corrida vale, y eso hay que justificarlo porque dos anteriores no valieron:
máquina **sin un solo `phpunit` huérfano dentro del contenedor**, base comparada
**contra las otras 27** (26 con 92 tablas, la mía con 93 por `bol_ind_periodos`,
la de otra sesión con 93 por `auditoria`; **2.351 usuarios en las 28**), fichero
de salida con fecha en el nombre, y **tiempos normales de 0,25–0,78 s** en los
cinco rojos. Las dos corridas descartadas están en la §2.1.

### Los cinco, con la columna que mueve cada uno

| Test | Columna | Por dónde entra |
|---|---|---|
| `ActasEvaluacionTest > la forma del detalle del acta` | `matriculas.boletin_independiente` | `Informes/ActasEvaluacionController:793` — `SELECT y.year, m.*, …` |
| `MuestreoDeLecturasTest > api/years` | `years.puestos_con_bol_independiente` | `YearsController:27` |
| `MuestreoDeLecturasTest > api/years/colegio` | idem | `YearsController:43` |
| `MuestreoDeLecturasTest > api/years/trashed` | idem | idem |
| **`NotasTest > la forma de la rejilla del profesor`** | **`unidades.alumno_id`** | **`NotasController:49` — `SELECT * FROM unidades u`** |

**El último no es una sospecha.** `Snapshots/notas-detailed-profesor.json` fija la
lista exacta de claves de cada unidad —`asignatura_id, created_at, created_by,
definicion, deleted_at, deleted_by, fecha, id, obligatoria, orden, periodo_id,
por_defecto, porcentaje, subunidades`— y `alumno_id` añade una decimoquinta. Un
`SELECT * FROM unidades` y una instantánea que fija las claves de `unidades` **no
pueden coexistir con una columna nueva**.

Y es el peor de los cinco porque es **la planilla**: la pantalla que más se usa.

### Lo que sí vale de aquí

**Los 7 tests de `BolIndependienteAlcanceTest` pasan**, así que el servicio hace
lo que dice en los dos sentidos —con nadie marcado y con alguien marcado—, y el
interruptor del periodo **no borra una sola fila**. Y los cuatro rojos del 1052 de
la §5.bis **ya no están**: ese arreglo está confirmado.

### §2.1 — Las dos corridas que NO valieron, y por qué se dicen

Porque **una medición contaminada no se interpreta, se descarta**, y las dos se
parecían mucho a un resultado:

1. **La del OOM.** `8myvc-database-1` murió por `Exited (137)` —máquina en load
   23–26 y 15,1 GB de 16,4 en swap— **con la tanda dentro**. Traía un
   `BanderasDeUnBitTest` rojo que no toca nada de esto.
2. **La de los huérfanos**, que era la medición deliberada de la §5.bis: **141
   rojos empezando alfabéticamente por la A**, con tests de 0,5 s tardando **79,58
   s**. Corría contra la misma base que **dos suites huérfanas mías**, y de ahí el
   *«deadlock transitorio»* que había achacado a la carga del contenedor. No era
   carga ajena: era yo tres veces.

**La trampa que las explica, y es la que hay que llevarse:** un `ps` del **host**
no ve los procesos del contenedor, y **matar el `docker exec` del host no mata el
`php` de dentro**. La comprobación buena es
`docker exec 8myvc-app-1 ps -ax | grep phpunit`, y de mis dos huérfanos uno tenía
`PPid: 1` y del otro **sobrevivía el árbol entero**, padre incluido.

---


## §3 — Las migraciones, y el choque que el plan no veía

**Tres de las cuatro** de la §3 del 19, en un solo fichero:
`unidades.alumno_id` (+ índice `(asignatura_id, periodo_id, alumno_id)` + clave
foránea), `matriculas.boletin_independiente` y la tabla `bol_ind_periodos` con su
clave única de nacimiento.

**La cuarta —`years.puestos_con_bol_independiente`— se movió a la fase 2**, y no
por prudencia: movía tres respuestas vivas y no la consumía nada. El motivo
completo está en la §5.ter. Lo de abajo se escribió cuando aún entraba, y **se
conserva porque es lo que hará falta el día que vuelva** — el choque con las dos
columnas que ya existen no caduca.

### `years` ya tenía DOS interruptores de puestos

Lo levantó `myvc-front-98` desde el front —ellos ya leen uno hoy— y lo verifiqué
en el esquema congelado (`database/schema/mysql-schema.sql:2211-2212`):

| Columna | Por defecto | Qué decide |
|---|---|---|
| `mostrar_puesto_boletin` | 1 | si el puesto **se imprime** en el boletín |
| `puestos_alfabeticamente` | 0 | cómo **se ordena** la lista |
| `puestos_con_bol_independiente` | 1 | *(fase 2)* si el independiente **cuenta** |

**No son la misma pregunta y no se funden.** Pero se cruzan, y lo medí porque una
recomendación sin número no sirve:

> **1 de los 8 años vivos de esta base tiene `mostrar_puesto_boletin = 0`.**

En ese año, **toda la §7 del plan no se ve por ninguna parte**: ni el `—` del
independiente, ni el puesto de un tercero que se mueve. El interruptor nuevo es
peso muerto ahí — y esa medida es la primera mitad del argumento por el que acabó
yéndose a la fase 2. No cambia cómo se implementa; cambia **cómo se le explica a un
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

### Comprobado a mano por el lado que importa

**Un falso «hay que acotarla» cuesta media hora; un falso «bien por
construcción» imprime un boletín en blanco.** Así que la comprobación a mano fue
del lado de los buenos, sobre los cuatro bloques más grandes —44 de las 88
lecturas seguras, la mitad—:

| Bloque | `notas` por `INNER JOIN`/`FROM` | por `LEFT JOIN` | consultas con el alumno fijado |
|---|---|---|---|
| `Informes/CalcPerdidasDefinitivas` (20) | 10 | **0** | 4 de 4 |
| `Informes/PuestosController` (10) | 5 | **0** | 5 de 5 |
| `DetallesController` (8) | 4 | **0** | 4 de 4 |
| `AlumnosController` (6) | 3 | **0** | 3 de 3 |

Ni un `LEFT JOIN` y el alumno fijado en todas: el conjunto llega ya reducido a
una persona antes de tocar `unidades`, así que la unidad que sobrevive es la que
sostiene una nota **suya**. Y el contraejemplo sale por el otro lado, que es lo
que hace la comprobación válida en vez de circular:

| | `INNER` | `LEFT` | Clase |
|---|---|---|---|
| `Unidad::deAsignaturaCalculada` (×3) | 0 | **1 cada una** | `por-alumno` → **hay que acotarla** |

**Es la única de las cinco que usa `LEFT JOIN`, y es la única que había que
acotar.** Si el criterio estuviera mal, ésta habría salido buena — y es la puerta
de los cuatro boletines.

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

## §5.bis — La tercera forma de fallar, que el plan no nombra

**Es lo más importante que encontré, y lo encontró la suite, no el detector.**

La §9.2 del 19 dice que una consulta sin alcance «no falla: devuelve las filas de
otro», y describe **dos** formas —de más y de menos—. Hay una tercera, y **sale
antes que las otras dos**:

```
SQLSTATE[23000]: 1052 Column 'alumno_id' in on clause is ambiguous
```

Con la migración puesta y **nadie marcado**, `BoletinNoBorraDefinitivasTest` y
`BoletinDeLaFamiliaTest` se pusieron en rojo con **500**. La causa:

```sql
left join notas n ON n.subunidad_id=s.id and n.deleted_at is null and alumno_id=:alumno_id
--                                                                     ^^^^^^^^^ sin alias
```

Hasta hoy **no había ambigüedad**: `notas` era la única tabla del join con una
columna `alumno_id`, así que escribirlo desnudo funcionaba y llevaba veinte años
funcionando. En cuanto `unidades` tiene la suya, MySQL no puede elegir y aborta.

**Cuatro predicados**, ya corregidos: `Unidad::deAsignaturaCalculada` (×3) y
`Subunidad::perdidasDeAsignatura`. De paso quedaron calificados los otros dos de
`Subunidad` que hoy **no** son ambiguos —`deUnidad2` y `deUnidadCalculada`, que no
unen `unidades`—: dejar dos sin calificar al lado de uno calificado invita a
suponer que la forma desnuda vale.

**De las tres formas de fallar, ésta es la buena**: es ruidosa, sale en el primer
test y no imprime un boletín equivocado. Lo que la hace peligrosa es **cuándo**:

> **La rompe el `ALTER TABLE`, no el código.**

`app/` es copia por colegio (CLAUDE.md). En un colegio donde la migración corra
antes de que llegue el `app/` nuevo, **los boletines dan 500 durante esa
ventana** — y son los boletines, o sea la pantalla que mira una familia. Eso
**cambia la §10 del plan**: la migración de esta fase no es puramente aditiva
respecto del código viejo, y el orden dentro de cada colegio es *primero el
`app/`, después el `ALTER TABLE`*, no al revés.

`tools/unidades-sin-alcance.py` vigila la clase entera desde ahora, y hoy dice
cero. **El detector no la habría encontrado**: buscaba consultas sin alcance, y
éstas fallan por una razón que no tiene que ver con el alcance. La encontró
**mirar el resultado y no el estado**, que es la regla de `tests/Contrato/`.

---

## §5.ter — La cuarta forma de fallar: el `SELECT *`

**Es la que rompe el criterio de aceptación, y es distinta de las tres del plan
en algo que cambia el procedimiento.**

| | Forma | Señal | Con qué código aparece |
|---|---|---|---|
| 1 | de más — suma las unidades de otro | **silenciosa** | el nuevo |
| 2 | de menos — boletín en blanco | **silenciosa** | el nuevo |
| 3 | `1052 ambiguous` (§5.bis) | **500** | **el viejo** |
| 4 | **`SELECT *` mete la columna nueva en la respuesta** | **cambia la forma del cuerpo** | **los dos** |

La 3 la rompe el `ALTER TABLE` contra el **código viejo**; la 4 **no depende de
qué código haya delante** — depende de que la consulta diga `*`—. Así que:

> **Son dos pasadas y ninguna encuentra la de la otra.**
>
> - esquema nuevo + código **viejo** → la forma 3 (`1052`): **4 consultas**
> - esquema nuevo + código **nuevo** → la forma 4 (`SELECT *`): **5 snapshots**

Eso es el procedimiento que `DESPLIEGUE.md` recoge, y **no depende de ninguno de
los dos números**: depende de que sean dos pasadas.

### El barrido, y cómo bajó de 17 a 14 sin cambiar una línea de `app/`

| Paso | `SELECT *` sobre mis tres tablas |
|---|---|
| primer barrido | 17 |
| menos los que eran **`unidades_por_defecto`** (bug del detector) | 14 |
| menos el que es un `SELECT *` **de una subconsulta** (`Models/Unidad:137`) | **13 reales** |

Los tres primeros los quitó **un arreglo del detector**: a `unidades` le faltaba
un `(?![\w])` detrás, así que casaba con los ocho primeros caracteres de
`unidades_por_defecto` —otra tabla, que no lleva `alumno_id` ni lo llevará—. Hay
**cuatro** tablas que empiezan igual. El cuarto lo quitó **leer**: `Models/Unidad:137`
nombra sus columnas dentro de la subconsulta, así que no filtra nada.

**El bug NO contaminó el inventario de la §5**, y comprobarlo era la mitad del
trabajo: `main()` filtra los literales con `\bunidades\b`, y ese `\b` ya excluía
`unidades_por_defecto` —`s` y `_` son los dos `\w`, no hay frontera—. **Las 146
lecturas siguen siendo 146.** El bicho mordía sólo en el barrido de los `SELECT *`,
escrito aparte y sin ese filtro. *Encontrar un fallo en la herramienta* y *que el
número no valga* son dos cosas distintas, y la diferencia es ir a ver hasta dónde
llegaba.

### Lo hecho, y por qué nombrar columnas no necesita permiso

**Cinco de las seis de `unidades`**, con la lista de columnas sacada del esquema
congelado y no escrita a mano: `UnidadesController` (`$cons_unidades`,
`putDeAsignaturaPeriodo`, `putEliminadas`),
`AsignaturasController::putDetalleAsignatura` y
`ChangeAskedController::asignaturas_dia`. Cada una lleva escrito **por qué** van
nombradas y que **volver a `*` reintroduce el fallo** — sin eso, el primero que
pase «simplificando» lo deshace.

**Nombrar las columnas no cambia la respuesta: la congela.** Y lo demuestran los
mismos tests que cazaron el problema: `UnidadesTest`, `AsignaturasTest`,
`CopiarUnidadesTest`, `PapeleraTest`, `ReordenarTest`, `PorcentajeQueSePisaTest` y
los 7 de `BolIndependienteAlcanceTest` → **119 en verde, 806 aserciones, sin
regenerar una sola instantánea.** Un cambio cuya corrección la comprueba un test
que ya existe y que ya estaba rojo por lo contrario es de los que se demuestran,
no de los que se prometen.

### Las dos que faltan, dichas como faltan

- **`NotasController:49`** — la que mueve `notas-detailed-profesor`, o sea **la
  planilla**. El fichero es de otra sesión esta noche: **pedida, no hecha**.
- **`DefinitivasPeriodosController:377`** — de otra sesión también. Filtra
  `boletin_independiente` y **hoy no la ve ningún test**, que es peor que verla.

### Lo decidido, y quién lo decidió

`8myvc-34` autorizó dos cosas el 24 ago, y el razonamiento de por qué podía
autorizarlas vale más que la decisión:

- **nombrar columnas no cambia comportamiento: lo impide.** Convertir un `SELECT *`
  en la lista que la instantánea ya fija **deja la respuesta byte a byte igual**, y
  **lo demuestran los mismos snapshots que cazaron el problema**: si siguen verdes
  *sin regenerarlos*, no cambió nada. Un cambio cuya corrección la comprueba un
  test que ya existe y que ya estaba rojo por lo contrario no es de los que
  necesitan permiso;
- **sacar de la fase lo que la fase no necesita** tampoco promete nada nuevo;
- **regenerar snapshots sí es de Joseth**, sin discusión: añade claves a respuestas
  vivas y obliga a avisar al front y a `myvc_flutter`, que es **una sola app para
  los dieciséis**.

Y una distinción suya que conviene no perder, porque desde fuera parecen el mismo
caso: a otra sesión le dijo **no** a nombrar columnas en `subunidades` y aquí
**sí** en `matriculas`. La diferencia es que **`subunidades` no recibe columna
nueva en esta fase** —sería arreglar un fallo hipotético de un `ALTER` futuro— y
`matriculas` sí, con **tres respuestas cambiando hoy**.

**Ocho consultas congeladas en total** —cinco de `unidades`, tres de `matriculas`—
y **dos pedidas**: `NotasController:49` (de `8myvc-7b`, es la planilla) y
`DefinitivasPeriodosController:377`.

> **Y la razón de congelar las que NO tienen test es la que más importa.** La §4
> no dice *«no mueve ningún snapshot»*: dice **«no mueve ninguna respuesta»**. Un
> snapshot que no existe no vuelve cierta una respuesta que cambió. Congelar las
> ocho es cumplir la promesa; congelar sólo las que tienen test es cumplir la
> medición.

### La trampa dentro de la trampa: un `SELECT *` sobre un join

`CertificadosPersonaController:43` no era un `m.*`, era un **`SELECT *` a secas
sobre `FROM matriculas m INNER JOIN grupos g`**. Ahí `*` significa *las columnas
de las dos tablas, en ese orden*, y **en las repetidas —`id`, `created_at`…— gana
la última, o sea `grupos`**. Así que:

- convertirlo en la lista de `matriculas` a secas **habría borrado de la respuesta
  todas las columnas de `grupos`**;
- y poner `g.*` **delante** habría cambiado el valor de `id` **sin tocar una sola
  clave del cuerpo** — o sea sin que ninguna comprobación de forma lo viera.

Va como `m.<28 columnas>, g.*`: mismo conjunto y mismo orden. Está escrito en el
comentario porque es exactamente lo que alguien deshace «simplificando».

### Y el que no se puede posponer

`matriculas.boletin_independiente` **no se puede sacar de esta fase**, y
recomendarlo fue un error mío: `BoletinIndependiente::consultar()` y `::delGrupo()`
la leen, y `consultar()` es lo que hay detrás de `alcance()`, que está llamado
desde `Unidad::deAsignaturaCalculada`. **Sin la columna, los cuatro boletines dan
500** — no un test rojo, la pantalla. Lo dije mirando los entregables en vez de mi
propio código.

`years.puestos_con_bol_independiente` **sí** se puede, y **se ha ido a la fase
2**: no lo consume nada —`puestosCuentanIndependientes()` no lo llamaba nadie, los
ocho sitios de impresión son fase 6, y las cuatro rutas de puestos no calculan
puesto— así que aquí era **una columna que nadie lee moviendo tres respuestas
vivas**. Se lleva con ella el método del servicio y sus dos tests.

**Es un entregable movido, no un entregable sin hacer**, y la diferencia importa
para quien lea el plan mañana: el interruptor **entra con quien lo escriba**. Un
servicio que decide sobre una columna que nadie escribe todavía tiene la mitad
positiva sin comprobar, que es la misma objeción que se le pone a dejar
`alcance()` devolviendo `null` a mano.

Lo que queda guardado en su sitio, y hace falta cuando vuelva: **contesta «¿está
activado el interruptor?» y nunca «¿se enseña el puesto?»**, y **el empate es el
único caso que distingue** el puesto calculado de la posición de fila.

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
3.bis. **Repetir la tanda completa con la base sana** — es lo único que separa
   esto de estar dado por desplegable. Ver la §2.
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
