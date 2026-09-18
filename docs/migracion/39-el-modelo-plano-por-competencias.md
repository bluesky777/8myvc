# El modelo plano por competencias — el contrato del backend (B0)

> **Éste es el documento que desbloquea al front.** La §7.2 de
> `myvc_front/CORRECCIONES-MODELO-DE-EVALUACION.md` dice que **F0 no puede empezar hasta que
> B0 publique el contrato, aunque sea en papel**. Esto es ese papel.
>
> Lo que hay **arriba** de esto: las decisiones y su porqué, en
> `myvc_front/CORRECCIONES-MODELO-DE-EVALUACION.md` (P1.bis, P1.ter, P1.quater, D31, D32) y en
> `docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md`. **Aquí no se re-litiga nada de eso**:
> aquí está la forma exacta de las rutas, la tabla y el permiso, y las **tres correcciones** que
> salieron de abrir los ficheros en vez de heredar el veredicto.

Medido el **17 sep 2026** contra el docker (`8myvc-app-1`, `8myvc-database-1`, base `simonbolivar`)
y sobre `main` en **`d540906`**, en el árbol principal.

---

## 0 · Las tres correcciones al plan, primero, porque cambian el trabajo

**Son la misma lección que costó `adoptar-men`**: un veredicto escrito que nadie había comprobado
abriendo el fichero.

### 0.1 · La migración `300000` NO se borra: crea la tabla que se queda

La §6.C dice *«se borra entero … 611 las cuatro migraciones (competencias, desempenos, marca,
competencia_congelada)»*. Abierta:

```
database/migrations/2026_09_13_300000_desempenos.php
  línea 101   Schema::create(desempenos_por_defecto, …)   ← LA QUE SE QUEDA
  línea 141   Schema::create(desempenos, …)               ← la que se va
```

**Borrarla entera borra la creación de la única tabla del modelo nuevo.** Se **edita**: fuera el
segundo `Schema::create`, fuera `competencia_id` del primero, fuera el `dropIfExists(desempenos)`
del `down`. Las otras tres sí se borran enteras.

> **Y esto hay que leerlo en `d540906`, no en el árbol de trabajo — ya confundió a una sesión el
> mismo día.** En cuanto se aplica la edición, el fichero tiene **un** `Schema::create` y un
> docblock que dice *«nació con dos»*, así que quien lo abra después mide el resultado y concluye
> que el segundo nunca existió. La orden que lo zanja, y que no depende de en qué momento se corra:
>
> ```bash
> git show d540906:database/migrations/2026_09_13_300000_desempenos.php | grep -c "Schema::create"   # 2
> ```
>
> Es la misma trampa que el docker compartido —**medir el mismo sitio en dos momentos y creer que
> se discrepa**—, sólo que en el árbol de git en vez de en la base. Por eso una medición se ancla a
> **un hash**, no a «el fichero».

### 0.2 · Copiar entre AÑOS no está roto: **calla y no copia nada**

Era la pregunta 5 del encargo, y se contesta leyendo `putCopiarPlantilla` (línea 446) con el
esquema delante:

```php
$dePeriodo = array_key_exists(periodo_id, $origen)
    ? $this->comoId($origen[periodo_id], origen.periodo_id)
    : $destino[periodo_id];        // ← un periodo_id DEL AÑO DESTINO
```

y `periodos` es **por año** —medido: `year_id` 1 a 9, ids 1‑4, 5‑8, 9‑12 … **disjuntos**—, así que
ese id no existe en el año origen, `grupoDelCatalogo` devuelve `[]` y la respuesta es
**`200` con `copiados: 0, revisados: 0, saltadas_sin_catalogo: 1`**.

**No revienta: miente por omisión.** El colegio pide «tráeme el plan de 2025» y recibe un 200 que
no se distingue de «2025 no tenía nada escrito» — y son dos cosas distintas.

**El arreglo, y es barato porque `periodos.numero` existe**: cuando el origen es otro año y
`origen.periodo_id` no viene, se resuelve **por número de periodo** del destino. Si ese número no
existe en el año origen → **422**, no un 200 vacío.

### 0.3 · `Area.php` no es una línea: son **dos funciones, seis divisiones y cinco `round()`**

La §7.0 dice *«falta sólo que `Area.php` la lea al calcular»*. Contado:

```
agrupar_asignaturas            103 area_nota = round(sumatoria / found)   104-107 per1..per4 / found
agrupar_asignaturas_periodos   203, 210, 218, 225  per1_nota..per4_nota = round(… / found)
```

**Y lo llaman cinco informes que hoy corren en los dieciséis colegios**: `Boletines3`,
`Boletines2`, `Bolfinales`, `CertificadosPersona`, `Promovidos`. No es una pantalla nueva: es el
motor del bloque de áreas de todo lo que se imprime.

Lo que lo hace seguro es la regla ya decidida —*si todas las asignaturas del (grupo, área) tienen
peso y suman 100, pondera; si no, promedia como hoy*— y **el dato**: medido en el docker,
`porcentaje_area` es anulable, por defecto `NULL`, y hay **0 de 1.219** asignaturas vivas con peso.
**El día que entre, los cinco informes imprimen exactamente lo de hoy**, y cambian el día que un
coordinador rellene la columna. Eso último hay que decirlo en el despliegue.

---

## 1 · La tabla, y es una

```sql
desempenos_por_defecto
  id · year_id · materia_id · grado_id NULL · periodo_id · tipo NULL · definicion · orden
  created_by · updated_by · deleted_by · timestamps · softDeletes
```

**Ya existe con esa forma** salvo `competencia_id`, que se cae. Se borran `competencias` y
`desempenos` enteras, y las cuatro columnas que la rejilla puso en `frases_asignatura`
(`desempeno_id`, `escala_id`, `nivel`, `competencia`).

**No hay columna de dueño y no es un olvido.** D31 dice que el colegio y el docente escriben **las
mismas filas físicas**: una columna que distinguiera «ésta es del colegio» sería el candado que la
decisión quita. Quién la escribió se sigue sabiendo por `created_by` y por `auditoria`.

> **`grado_id IS NULL` significa «todos los grados», y eso decide el permiso.** Una fila así
> alcanza a grados que el docente no da, así que **es del colegio**: ver §3.

---

## 2 · Las rutas — **siete**, y hoy son veintiuna

| método | ruta | quién | qué |
|---|---|---|---|
| `GET` | `desempenos` | personal | el catálogo de (year, materia, grado, periodo) |
| `POST` | `desempenos` | escritura con alcance | crear una fila |
| `PUT` | `desempenos/orden` | escritura con alcance | reordenar el conjunto entero |
| `PUT` | `desempenos/copiar` | escritura con alcance | traer de otro año · grado · periodo |
| `GET` | `desempenos/catalogo-men` | personal | los Estándares del MEN que casan con (materia, grado) |
| `PUT` | `desempenos/{id}` | escritura con alcance | editar texto, tipo u orden |
| `DELETE` | `desempenos/{id}` | escritura con alcance | borrado lógico |

Guard `auth.personal` en las siete; el criterio fino va **dentro** (§3), que es la forma de
`PlantillaNotasController` y de las siete de `competencias/` de hoy.

**Se van catorce**: las seis restantes de `competencias/*`, `desempenos/sembrar` —ya no hay a dónde
sembrar—, las cinco de la capa por asignatura (`GET/POST desempenos`, `PUT/DELETE desempenos/{id}`,
`PUT desempenos/orden` **con su significado viejo**) y las dos de `desempenos/rejilla`.

### 2.1 · Por qué se cae el segmento `plantilla`, y es lo único que decidí yo

Hoy el catálogo vive en `desempenos/plantilla/*` y la capa del docente en `desempenos/*`. Con una
sola capa sobra un nombre, y el que sobra es `plantilla`: **significa «lo que se aplica a una
copia», que es justo lo que D31 abolió**, y encima choca de nombre con la familia `plantilla-notas/`,
que se queda y sí es una plantilla de verdad.

**Se hace ahora porque ahora es gratis**: `datos/desempenos.ts` se reescribe entero en F0. Dentro de
un mes cuesta una migración de front.

**Los nombres de los métodos del controlador NO se tocan** —`getPlantilla`, `postPlantilla`,
`putOrdenPlantilla`…—: es lo que manda `CLAUDE.md` (*«renombrarlos es cosmético y va después»*). Lo
que es contrato es la URL, no el nombre del método.

> **La trampa de orden se mantiene y crece**: `desempenos/orden`, `desempenos/copiar` y
> `desempenos/catalogo-men` son literales y van **antes** que `desempenos/{id}`. Con `{id}` primero,
> `PUT desempenos/orden` entra por ahí con `$id = orden`.

### 2.2 · `catalogo-men` cambia de familia, y por eso el censo no se mueve

Estaba en `competencias/` para no quedarse sola («1 de 1» en
`familias-que-nunca-entran-en-el-candado.json`, que es la forma exacta que tendría un agujero). La
familia entera desaparece, así que **se muda a `desempenos/`**, donde es 1 de 7.

**Comprobado, no supuesto**: hoy el censo dice `competencias: 7 de 7` y `desempenos: 14 de 14`, y
**ninguna de las dos está en `familias-que-nunca-entran-en-el-candado.json`**. Después: `desempenos:
7 de 7` y `competencias` desaparece. O sea que **se mueven TRES instantáneas** —`rutas.json`,
`guards-por-ruta.json`, `guard-por-familia.json`— y **la cuarta no**.

### 2.3 · El contador de rutas

Hoy `603`. Después, `603 − 14 = 589` — **y ese número se cuenta con `route:list --json` en el árbol
principal después de fundir, no se resta aquí.** La coincidencia con la resta es la única forma de
saber que coincidía.

---

## 3 · El permiso con alcance — la pieza que sin ella la pantalla del docente da 403

Hoy `desempenos/plantilla/*` exige `Autoriza::puedeEditarPlantillaNotas`, que el docente **no
tiene** (D13, D28: va a Coordinación académica). P1.quater pide una segunda puerta.

```php
Autoriza::puedeEscribirDesempenos($user, int $yearId, int $materiaId, ?int $gradoId): bool
```

Es **verdad** si se cumple una de las dos:

1. `puedeEditarPlantillaNotas($user)` — el colegio escribe en cualquier (materia, grado) del año;
2. el docente **tiene una asignatura viva de esa materia en un grupo de ese grado y ese año**:

```sql
SELECT 1 FROM asignaturas a
  INNER JOIN grupos g ON g.id = a.grupo_id AND g.deleted_at IS NULL
 WHERE a.deleted_at IS NULL
   AND a.profesor_id = ?
   AND g.year_id = ? AND a.materia_id = ? AND g.grado_id = ?
 LIMIT 1
```

### Las tres reglas que lo hacen defendible, y ninguna es de estilo

- **`grado_id IS NULL` es sólo del colegio.** Una fila de «todos los grados» alcanza a grados que
  ese docente no da; dejársela editar sería darle, por la puerta de atrás, el alcance que el
  permiso le niega por la de delante. Con `$gradoId === null`, sólo pasa la rama 1.
- **`nuevo_responsable_id` NO cuenta, y lo comprobé antes de escribirlo al revés.** Iba a
  incluirla «porque la usa el resto del repo». Abierta: sus **cuatro** apariciones en `app/` son una
  `@property`, un `INSERT` al duplicar asignaturas y una copia al renovar el año — **ningún camino
  de lectura la usa para decidir quién da una asignatura**, y en el docker hay **0 de 1.093**
  asignaturas vivas con ella puesta. Meterla aquí sería inventarle un significado que el repo no le
  da, y **ensanchar el permiso** con una columna que nadie escribe. Si algún colegio la usa como
  «el docente que sustituye», eso es una decisión aparte y se toma con el dato delante.
- **El periodo cerrado sigue cerrado para el docente**, con permiso y sin él: la rama 2 pide además
  `periodos.profes_pueden_editar_notas = 1`, que es lo que ya hace `exigirPeriodoAbierto`. **La
  rama 1 no lo pide**, y es a propósito: el coordinador cierra las notas *para* congelar las notas,
  y sigue teniendo que poder montar el plan de área.

### El agujero que abre eso, y va a Joseth sin envolver

Con la copia borrada, **el texto del desempeño se lee vivo al imprimir**. Un coordinador que corrija
una errata en octubre **cambia el boletín del periodo 1 que ya fue a casa**.

**No es la misma exposición que P1.bis defendió.** Aquélla defendía borrar `nivel` —derivar en vez de
congelar—, y el argumento («el sistema ya deriva la nota al imprimir») es cierto para el nivel. Pero
el **texto** no se derivaba: se congelaba a propósito, y hay una migración escrita para eso —
`2026_09_14_100000_competencia_congelada`, cuyo comentario dice literalmente que sin ella *«renombrar
una competencia en 2028 cambiaba la cabecera de un boletín de 2026»*. **Esto la deshace.**

Lo que la acota: las filas son **por año**, así que sólo la puede tocar quien esté trabajando en ese
año. La exposición real es **dentro del mismo año, después de cerrar un periodo**.

| salida | coste |
|---|---|
| **aceptarlo** (lo implementado) | el papel del periodo 1 puede cambiar hasta que acabe el año |
| pedir periodo abierto **también** al coordinador | no puede arreglar una errata del plan una vez cerrado el periodo |

**Se implementa la primera** —es la que describe el plan— y **se deja escrita la segunda**, que son
tres líneas el día que Joseth diga.

---

## 4 · El boletín: de qué se lee ahora cada línea

`BoletinPorCompetenciasController` hoy imprime **lo que el docente marcó en la rejilla**, leyendo
`frases_asignatura` y sus cuatro copias congeladas. Sin rejilla, no hay marcas.

| | hoy | contrato nuevo |
|---|---|---|
| qué filas salen | las de `frases_asignatura` con `desempeno_id` | **todas** las de `desempenos_por_defecto` que le tocan a la asignatura |
| qué filas le tocan | — | las de su `grado_id` **y** las de `grado_id IS NULL` (D25), `ORDER BY grado_id IS NOT NULL, orden, id` |
| el nivel | `fa.nivel`, congelado al marcar | **derivado**: `bandaDeLaNota(definitiva de ESA asignatura en ESE periodo)`, uno para todas sus líneas (H4) |
| la cabecera | la competencia, congelada | **no hay cabecera**: el modelo es plano (H3, P3) |
| el prefijo | no hay | `escalas_de_valoracion.descripcion` de la banda, delante del texto. Vacía = como hoy (P2, H5) |

> ### Dos cosas que esta tabla decía mal, corregidas al implementarla (17 sep 2026, 20:2x)
>
> **1 · El `ORDER BY` era `orden, id` y está mal, y no es cosmético.** Con filas de «todos los
> grados» mezcladas, los dos órdenes dan **papeles distintos**:
>
> | `orden, id` (lo que decía) | `grado_id IS NOT NULL, orden, id` (lo que va) |
> |---|---|
> | g11/0 · NULL/5 · g11/7 · NULL/9 | NULL/5 · NULL/9 · g11/0 · g11/7 |
>
> El motivo bueno **no** es que sea el de `catalogoPara` —ése es el de `sembrar`—: es que
> **`getPlantilla`, la ruta que lee la pantalla ③ donde el colegio teclea el plan**, ordena por
> `d.grado_id`, y en MySQL un `ORDER BY` ascendente pone los **`NULL` primero**. O sea que las dos
> expresiones agrupan igual —«todos los grados» arriba, el grado debajo— y **el papel sale en el
> orden de la pantalla**. Un boletín que ordenara esas mismas filas distinto de la pantalla es un
> fallo que sólo se ve comparando dos papeles.
>
> **2 · «las de `frases_asignatura` con `desempeno_id IS NULL`» — ese filtro NO SE PUEDE
> ESCRIBIR**, y escribirlo sería un `Unknown column` en los dieciséis. Las cuatro columnas de la
> rejilla las añadía `2026_09_13_400000_marca_del_desempeno`, que **nunca corrió en ningún
> colegio**, y la prueba es el volcado, que es la verdad del esquema:
>
> ```
> awk '/CREATE TABLE `frases_asignatura`/,/ENGINE=/' database/schema/mysql-schema.sql \
>   | grep -c '^  `'
> # 12 -- id alumno_id frase_id frase asignatura_id periodo_id created_by updated_by
> #       deleted_by deleted_at created_at updated_at.  Ninguna de las cuatro.
> ```
>
> **Así que no hay filtro: en producción, toda fila viva de esa tabla es una frase escrita a
> mano**, porque las otras no las pudo crear nadie. Es la premisa de la §7 vista **desde el
> esquema** en vez de desde los clientes — y es la comprobación más dura de las dos, porque no
> depende de que nadie despliegue nada.
>
> *(En el docker sí existieron hasta hoy y hubo 151 filas marcadas; ahí esas 151 se imprimirán como
> frases a mano. En un colegio no puede pasar.)*

**Lo que NO se toca y hay que decirlo**: las frases escritas a mano de `frases_asignatura`
**siguen imprimiéndose**. Son datos de producción de los dieciséis colegios y no vienen de ninguna
rejilla. Medidas en el docker el **17 sep 2026 a las 20:14**: **11.004 vivas** y **12.445 contando
las borradas**.

> **Aquí ponía «12.294» y yo no la había medido: la copié de un comentario de
> `BoletinPorCompetenciasController`.** Es exactamente lo que este repositorio prohíbe —una cifra
> se publica con la orden que la produjo—, y lo destapó el front midiendo y obteniendo otra.
>
> **Las tres son ciertas y cuentan poblaciones distintas**, que es lo que hace que reconciliarlas
> valga más que sustituir una por otra: `12.445 − 151 = 12.294`, o sea que el comentario contaba
> **todas las filas, borradas incluidas, sin `desempeno_id`**. Esa cuenta **ya no se puede rehacer**
> —`desempeno_id` deja de existir con este cambio—, y por eso la que se queda escrita es la de las
> vivas, con su hora al lado.

**`bandaDeLaNota` ya existe** (línea 686) y **no se toca**: su comparación
`nota >= porc_inicial && nota < porc_final + 1` la vigila `CentinelaDeLaReglaDeLaBandaTest`, que
falla si alguien vuelve a escribir `<= porc_final`. Se reutiliza tal cual.

### El contador `poblacion` tiene que cambiar de pregunta

Hoy cuenta `desempenos_del_grupo` frente a `desempenos_impresos` — *«cuántas casillas nadie miró»*.
Sin casillas que marcar, esa pregunta desaparece y hay que sustituirla por la que sí se va a hacer:
**cuántas asignaturas imprimieron sin una sola fila de catálogo**, o sea el plan de área que el
colegio no escribió para esa materia y ese grado. Es el `saltadas_sin_catalogo` de `copiar`, pero en
el papel. Los dos motivos de nivel vacío —`sin_definitiva` y `sin_banda`— **ya están contados por
asignatura** (línea 355) y se heredan tal cual.

---

## 5 · Preescolar: lo que le falta al backend

Decidido por Joseth el 17 sep: **preescolar se queda en el boletín tipo 4** —lee `frases_preescolar`
y `frases_asignatura`, no sabe nada de competencias— y **tendrá pantallas propias** para sus frases.
Eso cierra la decisión que §6.B daba por bloqueante.

Lo que hace falta, medido en `FrasesAsignaturaController` (90 líneas):

- **`postStore` guarda UNA frase por petición** — 175 peticiones para un grupo y un periodo — y
  escribe siempre en `$user->periodo_id`: no hay forma de guardar en otro periodo.
- **`getShow/{alumno_id}/{asignatura_id}` es de UN alumno** y tampoco acepta periodo.

Hacen falta dos rutas nuevas, con la población en la respuesta y no un `OK`:

| método | ruta | qué |
|---|---|---|
| `GET` | `frases_asignatura/grupo/{asignatura_id}` | los alumnos del grupo con sus frases; `periodo_id` opcional |
| `PUT` | `frases_asignatura/grupo/{asignatura_id}` | guarda el grupo entero; contesta el grupo releído más `alumnos_del_grupo · alumnos_revisados · frases_revisadas · escritas · cambiadas · sin_cambio · borradas · vacias` |

Guard `auth.personal` y, dentro, que la asignatura sea suya o que sea administrativo —**no
`persona.propia`**, que es de la ruta de un alumno y aquí no aplica. La escritura respeta
`pueden_editar_notas` del periodo destino.

> **Aquí ponía `saltados_por_periodo_cerrado` y ese contador no puede existir.** Corregido al
> implementarlo, y la corrección es mejor que lo que pedí: **el periodo cerrado corta la petición
> entera con 403 antes de mirar el cuerpo**, así que ese renglón valdría cero siempre — y la regla
> de esta casa es justo que *un contador que no puede subir no dice si la regla corrió*. Pedí la
> población y describí un contador inerte en la misma frase.
>
> Es la decisión de `putRejilla`: **un 200 que guardó media pantalla miente**. Quien quiera saberlo
> **antes** de mandar el cuerpo tiene `puede_escribir` en el `GET`, que no es lo mismo que
> `periodo_abierto` — la primera es la columna del periodo, la segunda es lo que el `PUT` le
> contestará **a quien está mirando**: un superusuario escribe con el periodo cerrado y un docente
> no.
>
> **Y el `PUT` es declarativo con un límite**: cada alumno trae su lista completa y lo que no viene
> se va, pero **sólo se tocan los alumnos nombrados**, así que una pantalla que guarde por partes
> no puede borrarle nada a nadie por omisión. `frases: []` es «quítaselas todas» y **omitir la
> clave es 422**, porque «la vacié» y «no la mandé» tienen que poder decirse distinto.

> ### El grano es por ASIGNATURA y la pantalla es por GRUPO — apuntado, y a propósito sin hacer
>
> Lo levantó el front al recibir el contrato, y no es una petición suya: su pantalla lleva a la
> izquierda los niños con su «7/7», o sea que necesita **las siete dimensiones a la vez** y hace
> **siete peticiones para pintarse**.
>
> **No se toca ahora, y el motivo es un número**: siete contra las **126** de hoy. Un `?grupo_id=`
> que devolviera las asignaturas del grupo en una respuesta bajaría de 7 a 1, y esa séptima parte
> **no paga** añadir hoy un segundo grano a una familia que acaba de estrenar el primero — con dos
> formas de pedir lo mismo, la que se use de verdad será la que alguien eligió por costumbre.
>
> Queda escrito aquí **con su salida ya elegida** para el día que estorbe, que es lo que impide que
> se rediseñe desde cero: `?grupo_id=` en el mismo `GET`, no una ruta nueva.

---

## 6 · Lo que se borra, y lo que la §6.C daba por borrado y no lo está

| se borra entero | |
|---|---|
| `app/Http/Controllers/CompetenciasController.php` | 1.304 |
| `routes/api/competencias.php` | 75 |
| `database/migrations/2026_09_13_200000_competencias.php` | |
| `database/migrations/2026_09_13_400000_marca_del_desempeno.php` | |
| `database/migrations/2026_09_14_100000_competencia_congelada.php` | |
| `tests/Contrato/CompetenciasTest.php` + instantáneas | 825 |

| se **edita**, no se borra | por qué |
|---|---|
| `2026_09_13_300000_desempenos.php` | crea la tabla que se queda (§0.1) |
| `app/Support/CatalogoDelMen.php` (396) y `resources/datos/catalogo-men.json` (4.372) | **el catálogo del MEN ya es plano** — reconfirmado: 523 entradas, `tipo` 83 `enunciado` / 50 `eje` / 390 `estandar`, 24 `grupo` distintos, sin anidamiento. `grupo` → `tipo`, `conjunto` → grado |
| `DesempenosController.php` (2.480) | fuera rejilla, sembrar y la capa por asignatura |
| `BoletinPorCompetenciasController.php` (755) | §4 |
| `tests/Contrato/DesempenosTest.php` · `BoletinPorCompetenciasTest.php` · `PreescolarCalificaPorCompetenciasTest.php` | quitan lo que prueba rutas que ya no existen |

**Los tests que prueban comportamiento borrado se quitan en el mismo commit que el borrado** — no es
escribir tests nuevos (que espera a que Joseth pruebe a mano), es no dejar la suite roja afirmando
un contrato que ya no existe.

---

## 7 · El paso operativo que no es código

El docker **ya tiene las cuatro migraciones corridas** — comprobado en `migrations`:
`2026_09_13_200000`, `300000`, `400000` y `2026_09_14_100000`. Borrar los ficheros deja las columnas
y las filas huérfanas: `migrate` sigue, **`migrate:rollback` y `migrate:status` no**.

O se limpian a mano las cuatro filas y las columnas, o se levanta la base de cero. **En los colegios
no hace falta**, y ése es el porqué de que todo esto sea barato: la premisa de que **ninguna pantalla
ha podido crear un dato** porque su único cliente (`app2`) no está desplegado.

**Reconfirmada hoy, 17 sep 2026, desde este lado**: `grep -rlE "(desempenos|competencias)/"` sobre
`myvc_front_2`, `myvc_flutter` y `myvc_dist` da **cero**; los únicos aciertos están en
`myvc_front/app2/src` y en sus `dist/browser/chunk-*.js`, que son el build de `app2`.
**`myvc_dist origin/main` no tiene ni un `chunk-*.js`**, o sea que lo desplegado es la app vieja.

> **Y caduca.** El día que `app2` se despliegue, esto deja de ser cierto y el borrado deja de ser
> gratis. **Se vuelve a comprobar antes de ejecutar, no se hereda de aquí.**
