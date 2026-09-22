# Los cuatro relojes: censo completo, y qué hace falta para transformar la hora al mostrarla

**21 sep 2026.** Censo pedido por Joseth después de que otra sesión le dijera que la nota
final se guarda en un sitio con `Carbon` en Bogotá y en otro con Eloquent en UTC. Es cierto,
y es **menos de la mitad del problema**: en `app/` hay **cuatro** relojes escribiendo en la
base, no dos.

> La decisión de fondo está tomada y no se re-litiga: **lo que se guarda va en hora de
> Bogotá** (decisión 1 del [18](18-auditoria.md)) y **`config/app.php` se queda en UTC**
> (decisión 2). Este documento no las discute: cuenta quién las incumple y qué cuesta
> alinearlo.

---

## El titular

**Hoy no se puede aplicar una transformación única al mostrar.** No porque falte escribirla,
sino porque **la fila no dice con qué reloj se escribió**, y en una misma columna conviven
dos. Una transformación uniforme arregla las filas de una familia y **rompe las de la otra**,
que es la conclusión a la que ya llegaron `bitacoras.created_at` —12 filas en UTC contra
74 en Bogotá— y el sello de las definitivas.

La secuencia correcta es la contraria a la que pide el cuerpo: **primero un solo reloj al
escribir, después la transformación al leer.** Mientras haya cuatro escritores, cualquier
conversión de salida es una apuesta sobre cuál escribió esa fila.

---

## §1. Los cuatro relojes, contados

Medido en `main`, árbol principal, 21 sep 2026.

| # | Reloj | Qué da | Llamadas | Ficheros |
|---|---|---|---:|---:|
| 1 | **Bogotá**, `App\Support\Reloj` | hora de pared de Bogotá | **60** | 20 |
| 1b | **Bogotá a mano**, `Carbon::now('America/Bogota')` | lo mismo, decidido en cada sitio | **138** | 53 |
| 2 | **UTC de PHP**, `now()` / `Carbon::now()` | UTC (`config/app.php`) | **34** | 14 |
| 3 | **UTC de Eloquent**, `Model::freshTimestamp()` | UTC, y **sin que aparezca un `now()` en el fichero** | 43 modelos escritos + `app/User.php` | — |
| 4 | **El del servidor MySQL**, `NOW()` | `@@session.time_zone = SYSTEM`: **dieciséis cPanel distintos** | **17** | 6 |

```bash
grep -rn "Reloj::ahora()\|Reloj::ahoraTexto()" app/ --include='*.php' | wc -l      # 60
grep -rn "Carbon::now('America/Bogota')" app/ --include='*.php' | wc -l            # 138
grep -rn "NOW()" app/ --include='*.php' | grep -v '^\s*\*' | wc -l                 # 20 vivos
docker exec 8myvc-app-1 php artisan test --filter=RelojUnicoTest                   # el censo del 2
```

### El 3 es el que no se ve

Los relojes 1, 2 y 4 se leen en el código. **El 3 no.** `Model::freshTimestamp()` devuelve
`Carbon::now()` —UTC— y rellena `created_at`, `updated_at` y, con `SoftDeletes`,
`deleted_at`. Un fichero puede escribir tres columnas en UTC sin que haya un solo `now()`
dentro, y por eso `RelojUnicoTest::no_hay_relojes_sin_zona_nuevos`, que cuenta sobre el
código, **no lo ve**.

- **43 de los 53 modelos de `app/Models/` se escriben** de verdad, todos por
  `new Modelo` + `->save()` o `Modelo::findOrFail()` + `->save()`. En todo `app/` **no hay
  un solo `::create()` ni `::updateOrCreate()`**.
- **4 llevan el rasgo** `App\Support\SellaConElReloj` y sellan en Bogotá: `Nota`,
  `Subunidad`, `Unidad`, `Matricula`.
- **39 siguen en UTC**, y el volumen está concentrado: `Year` (26 sitios), `Profesor` (14),
  `Alumno` (13), `Periodo` (12), `Ausencia` (10), `Acudiente` (9), `ImageModel` (9),
  `WsActividad` (7), `Grupo` (5).
- **Y `App\User` no está en `app/Models/`**: vive en `app/User.php`, escribe `users` con
  ~19 `->save()`, y cualquier censo que recorra `app/Models/` —incluidos los de este repo—
  **se lo deja fuera**.
- **44 modelos usan `SoftDeletes` y 40 de ellos no llevan el rasgo**: ahí el reloj de UTC no
  entra sólo al crear, entra **al borrar**.

### Una errata que sólo cazó el servidor: eran 17 y publiqué 20

La primera versión de este documento decía **20**. La tabla de debajo sumaba 17 (6+3+3+2+2+1)
y el titular decía 20: **ninguna orden produjo ese número**, lo escribí de memoria mirando
una salida de `grep` que aún llevaba dentro las seis líneas de comentario de
`DefinitivasPeriodosController` y `DefinitivasDeAsignatura`, que mencionan `NOW()` para
explicar por qué lo quitaron.

**Lo cazó el censo de producción, no una relectura.** Joseth corrió el bucle del
[54](54-lo-que-espera-a-joseth-de-los-relojes.md) §1 sobre los diecisiete y salió **16 en
todos**. El 16 contra el 20 no cuadraba de ninguna manera; el 16 contra el 17 de `main` sí,
y con una explicación: falta el de la planilla offline, que entró en `3e16747` y aún no está
desplegado.

Dos cosas quedan de aquí, y valen más que la errata:

- **Una cifra sin la orden que la produce al lado no es una cifra.** Ésta llevaba dos días
  escrita en el documento, en un mensaje de commit y en `ESTADO-ACTUAL`, y pasó por delante
  de todas las revisiones sin que nadie —yo el primero— sumara la tabla que tenía debajo.
- **El número redondo es sospechoso.** 17 es un recuento; 20 es un número que alguien
  recordó.

### El 4 es nuevo, y es el peor

Las 17 `NOW()` vivas están en **seis ficheros**, y **todas** las tablas que escriben las creó
una migración de esta migración —no hay ni una del legado—:

| Fichero | Usos | Tabla |
|---|---:|---|
| `Informes/FormulariosInscripcionController.php` | 6 | `formularios_inscripcion`, `ordenes_inscripcion` |
| `Informes/ColillasInscripcionController.php` | 3 | `colillas_inscripcion`, `ordenes_inscripcion` |
| `Informes/PagosInscripcionController.php` | 3 | `pagos_inscripcion`, `ordenes_inscripcion` |
| `Informes/InformesRecientesController.php` | 2 | `informes_recientes` |
| `Perfiles/AccesosFavoritosController.php` | 2 | `accesos_favoritos` |
| `PlanillaOfflineController.php:879` | 1 | `descargas_de_planilla` |

`config/database.php` **no fija la zona de la sesión**, así que `NOW()` devuelve la hora del
servidor. En el docker eso es UTC (`@@system_time_zone = UTC`, comprobado). En producción son
**dieciséis cuentas de cPanel** y nadie ha medido qué zona tiene cada una: el mismo endpoint
puede escribir una hora distinta en dos colegios **y las dos parecerán correctas**.

El porqué ya estaba escrito: es literalmente el motivo por el que
`DefinitivasPeriodosController:293` y `DefinitivasDeAsignatura:417` **sacaron** `NOW()` el
mismo 21 sep 2026. Lo que este censo añade es que **el resto del código nuevo lo sigue
metiendo**.

---

## §2. La vuelta no existe

`Reloj::desdeTexto()` se escribió para leer estas columnas sin equivocarse de zona.

**Cuando este censo empezó tenía cero llamantes en `app/`.** Sólo lo usaban dos tests, y en
todo `app/` había **una sola** conversión de vuelta: la de `importaciones`. O sea que la
mitad del camino que este documento viene a pedir estaba construida y **no la usaba nadie**.

Desde el 21 sep 2026 tiene **uno**: `Matriculas/EstacionesController.php`, que es justo el
sitio donde no tenerlo costaba 300 minutos por fila (§4.1).

**Y el 22 sep se quedó en uno para siempre**, porque el otro candidato desapareció: la vuelta
de `importaciones` llegó a vivir en `PuntoDeControlDeImportacion::enLaHoraDelColegio()` y
duró un día. Al mudarse la tabla a Bogotá (§4.2) no hay nada que convertir, así que el método
y sus dos llamantes se fueron enteros. **La conversión más barata es la que no hace falta.**

```bash
grep -rn "desdeTexto\|setTimezone" app/ --include='*.php' | grep -v Support/Reloj.php
```

**Que siga siendo un número tan bajo es correcto y no una deuda.** Una columna que guarda
hora de pared de Bogotá y se devuelve cruda al cliente **no necesita conversión**: la
necesita quien la compara con otra cosa, y ésos son pocos y se cuentan.

---

## §3. La zona de los dieciséis: **EDT**, y tiene horario de verano

**Medido por Joseth en el servidor el 21 sep 2026** con `tools/zona-de-los-colegios.sh`
(`MEDIDOS 17, NO MEDIDOS 0`, salida `1`): **las diecisiete instalaciones** —los dieciséis
colegios y `demo`— dan `@@session.time_zone = SYSTEM`, `@@system_time_zone = EDT` y un
desfase de **−4,0 h** contra UTC. **Ninguna excepción**, que es la parte buena: la zona es
una sola cosa que arreglar y no diecisiete.

No es UTC, que es lo que daba por hecho el docker, ni Bogotá. Es la hora del este de
Estados Unidos, y **tiene horario de verano**:

| | offset | contra Bogotá |
|---|---|---|
| **EDT** (marzo–noviembre) | UTC−4 | el servidor va **una hora por delante** |
| **EST** (noviembre–marzo) | UTC−5 | van **iguales** |

Colombia no cambia la hora, así que **el desfase aparece en marzo y desaparece en
noviembre**. Un desfase que se va solo es peor que uno fijo: nadie lo atribuye a la zona,
porque la mitad de las veces que se va a mirar ya no está.

### Lo que esto significaba para los `NOW()`

Las 17 `NOW()` que se fueron **no escribían UTC**: escribían EDT. O sea que
`ordenes_inscripcion`, `pagos_inscripcion`, `colillas_inscripcion`, `informes_recientes`,
`accesos_favoritos` y `descargas_de_planilla` llevaban **una hora de más de marzo a
noviembre y la hora correcta el resto del año**, en la misma columna y sin nada que lo
dijera. Por eso quitarlos era el primer paso y no el tercero.

### Y para las 225 columnas `TIMESTAMP`, que es más sutil de lo que parecía

Del volcado `database/schema/mysql-schema.sql`: **225 columnas `TIMESTAMP`**, 16 `DATETIME`
y 20 `DATE`; de las 90 tablas, **84 tienen `created_at`**, y **ninguna** de las
`created_at`/`updated_at`/`deleted_at` es `DATETIME`. Un `TIMESTAMP` convierte al escribir y
al leer usando la zona de la sesión, que este proyecto no fija.

**La primera versión de este documento decía que el día que cambie la zona se mueven todas
las filas a la vez. Es falso, y lo desmiente una medición de tres líneas.** MySQL convierte
**por instante**, aplicando la regla que la zona tenía ESE día, así que la ida y la vuelta
se cancelan fila a fila. Comprobado con la sesión puesta en `America/New_York`:

```
escrito 2026-01-15 09:00:00  ->  leído 2026-01-15 09:00:00     (EST)
escrito 2026-07-15 09:00:00  ->  leído 2026-07-15 09:00:00     (EDT)
escrito 2026-11-01 01:30:00  ->  leído 2026-11-01 01:30:00     (la hora repetida: elige una)
escrito 2026-03-08 02:30:00  ->  leído 2026-03-08 03:00:00     <-- AJUSTADA EN SILENCIO
```

**La que muerde es la última.** El segundo domingo de marzo, entre las 02:00 y las 02:59,
hay una hora de pared que **no existe** en la zona del servidor. Nosotros escribimos hora de
Bogotá, donde esa hora sí existe, así que el motor la **mueve a las 03:00 y no da error**.
Una vez al año, durante una hora, y de madrugada: es poco, pero es exactamente la clase de
fila que después nadie sabe explicar.

Lo demás sigue en pie: un cambio de hosting a otra zona **sí** movería todo, porque
cambiaría la regla con la que se releen los instantes ya guardados. Por eso `EDT, 21 sep
2026` queda escrito aquí: el valor importa menos que tenerlo apuntado antes de que cambie.

**Y 6 columnas con `DEFAULT CURRENT_TIMESTAMP`** —`password_reminders`, `piars_alumnos`,
`piars_asignaturas`, `piars_config`, `piars_grupos`— son el reloj del servidor metido en el
esquema: una fila insertada sin nombrar esa columna la rellena MySQL, en EDT, y eso no lo
arregla ningún cambio en `app/`.

---

## §4. Lo que ya está roto, medido

### 4.1 El tablero de matrículas suma 300 minutos a cada espera

`Matriculas/EstacionesController.php:426`:

```php
$ahora = Carbon::now('America/Bogota');                       // :405
$esperas[] = max(0, $ahora->diffInMinutes(Carbon::parse($porque['llego_at']), true));
```

`llego_at` sale de `notas_estacion` / `envios_estacion`, que ese mismo fichero escribe en
Bogotá. `Carbon::parse()` sin zona la lee como UTC, así que la resta contra un `$ahora` en
Bogotá da **300 minutos exactos de más**. Comprobado en el contenedor:

```
diffInMinutes(now Bogota, columna leída sin zona) = 300.0
```

De ahí salen `espera_media_min`, `espera_maxima_min` y el `tapon` del tablero: **el número
que la pantalla existe para enseñar**. Es el único sitio de todo `app/` que produce hoy una
cifra visiblemente falsa por culpa de la zona.

### 4.2 El acta del libro de notas enseña UTC, la pantalla enseña Bogotá

`PlanillaOfflineController::getActa` lee `inicio`, `fin` y `created_at` de `importaciones`
(que está en UTC a propósito) y `Services/ActaDeLaImportacion.php:410-413` los pone **crudos**
en las filas «Empezó» y «Terminó» del Excel. La pantalla de la misma fila
(`ImportarController:1620`) sí convierte. **La misma tabla leída de dos maneras en el mismo
repo**, y el Excel es el que se imprime y se archiva.

### 4.3 El filtro por defecto de la auditoría: ya estaba arreglado, y el censo lo dio por roto

`Auditoria/AuditoriaController.php` calculaba el rango por defecto con `now()->toDateString()`
—UTC— contra columnas escritas en Bogotá, así que después de las 19:00 el «hoy» del filtro
era mañana. **Cuando se fue a arreglar ya usaba `Reloj::ahora()`**: otra sesión lo movió
mientras este censo corría, y las líneas se desplazaron de la 80 a la 92.

Queda escrito porque es la forma en que miente un censo hecho en el árbol compartido: **una
lectura es una foto, y ocho sesiones escriben sobre el mismo `main`**. Lo que se arregla se
vuelve a mirar en el momento de tocarlo, no cuando se midió.

### 4.4 Lo que NO está roto, y por qué conviene dejarlo escrito

`date('j', strtotime($columna))` —16 líneas en 9 ficheros, entre ellas
`AusenciasController:84-85`— **no mueve nada**. `strtotime()` y `date()` usan las dos la zona
por defecto de PHP, así que la ida y la vuelta se cancelan:

```
date("j/n", strtotime("2026-09-21 19:30:00")) -> 21/9    (tz por defecto: UTC)
```

Queda anotado porque **parece el mismo fallo que el 4.1 y no lo es**: el 4.1 desfasa porque
tiene **una punta en cada zona**, no porque lea sin decirla. Un censo que marque las dos
cosas igual da dieciséis falsos positivos y esconde el único que muerde.

---

## §5. El histórico: **495 subunidades, todas de 2026** — medido en producción

Emparejando cada subunidad con la **primera nota que nace de ella** —las crea la misma
petición, así que su hueco normal es de segundos—, una separación de 18.000 segundos exactos
significa que la subunidad la selló Eloquent en UTC y sus notas el SQL crudo en Bogotá.

| | copia del docker (21 sep) | **producción, `caz-zaragoza` (22 sep)** |
|---|---:|---:|
| subunidades con `created_at` | 28.240 | **28.448** |
| …selladas en UTC | 495 | **495** |
| pares (nota, subunidad) que arrastran | 8.058 | **8.058** |
| años | sólo 2026 | **sólo 2026** |

```sql
SELECT COUNT(DISTINCT s.id) AS subunidades_en_utc, COUNT(*) AS pares,
       MIN(YEAR(s.created_at)) AS desde, MAX(YEAR(s.created_at)) AS hasta
FROM notas n JOIN subunidades s ON s.id = n.subunidad_id
WHERE ABS(TIMESTAMPDIFF(SECOND, s.created_at, n.created_at)) = 18000;

SELECT COUNT(*) AS subunidades_totales FROM subunidades WHERE created_at > '2000-01-01';
```

Las dos siempre juntas: **un número sin su denominador no dice nada.**

### La discrepancia, resuelta: el docblock estaba mal

La cabecera de `App\Support\SellaConElReloj` decía **«34.903 pares separados 18.000
segundos exactos y 6.188 en el mismo segundo, las dos familias de 2018 a 2026»**, sobre esta
misma copia de `caz_zaragoza`. **No se reproduce.** Ni en el docker ni en producción, y no
por poco: el máximo que da cualquier emparejamiento es 8.058, y **los nueve años anteriores
a 2026 dan cero**.

| emparejamiento | pares a 5 h | años |
|---|---:|---|
| `s.created_at` ↔ `n.created_at` | 8.058 | 2026 |
| `s.updated_at` ↔ `n.updated_at` | 2.261 | 2026 |
| `s.created_at` ↔ `n.updated_at` | 2.737 | 2026 |

**Lo que sí se reproduce** son los «2.262 pares» que anotó `DefinitivasPeriodosController`:
hoy dan 2.261, o sea la misma medición con una fila más de por medio. Esa cifra está bien y
es la de `updated_at`.

Así que la reparación del histórico, si se hace, **toca 495 subunidades y 8.058 notas, todas
de 2026** — no treinta y cuatro mil repartidas por nueve años. Es una migración pequeña, de
un solo año, con `RastroDeLaMigracion::anotar()` delante.

> **Y la lección se parece demasiado a la de la errata del §1 para no decirla junta:** las
> dos cifras malas —el 20 de los `NOW()` y el 34.903 de los pares— estaban escritas en sitios
> que se leen mucho, llevaban días ahí, y **ninguna de las dos la cazó una relectura**. Las
> cazó volver a correr la orden. Un número que no lleva su orden al lado no se puede revisar
> leyéndolo; sólo se puede volver a medir.

---

## §6. Lo que hace falta para transformar al mostrar, en orden

**Ninguno de estos pasos sirve sin el anterior.**

1. ~~Las 17 `NOW()` pasan a `Reloj::ahoraTexto()`.~~ **HECHO el 21 sep 2026.** Y el §3
   explica por qué era el primero y no el tercero: no escribían UTC, escribían **EDT**, o
   sea una hora de más de marzo a noviembre y la correcta el resto del año.
2. ~~El rasgo `SellaConElReloj` a los modelos cuya tabla ya recibe fechas en Bogotá.~~
   **HECHO el 21 sep 2026: 20 en Bogotá, 32 en UTC**, y el reparto lo fija un test.

   **La lista que había escrito aquí estaba mal, y el error tiene nombre.** Decía `Year`,
   `Profesor`, `Alumno`, `Periodo`, `Ausencia`, `Acudiente`, `ImageModel`, `Grupo` y
   `App\User`, y de esos **`Profesor`, `Periodo` y `Grupo` NO cumplen el criterio**: contaba
   escrituras **a la tabla** cuando lo que importa son escrituras **del sello**.
   `UPDATE profesores SET tono = ?` escribe la tabla y no toca `updated_at`, así que a
   `profesores` nadie le escribe la fecha a mano y el rasgo le habría **creado** la mezcla en
   vez de reducirla. Se les puso y se les quitó el mismo día.

   Y el detector tuvo que rehacerse **tres veces**, dando cada vez un número distinto y
   plausible:

   | intento | qué miraba | por qué mentía |
   |---|---|---|
   | 1.º | ventana de 900 caracteres tras el `INSERT`/`UPDATE` | se comía el `deleted_at` del `SELECT` de abajo |
   | 2.º | sólo dentro de `DB::insert(` / `DB::update(` | el patrón de la casa es `$consulta = '…'` y ejecutar después: perdía `users` y `alumnos` enteros |
   | 3.º | la **cadena SQL**, venga de donde venga, cortada en el `WHERE` | el bueno |

   La lista definitiva y el porqué de cada exclusión viven en las constantes
   `SELLAN_EN_BOGOTA` y `SELLAN_EN_UTC` de `RelojUnicoTest`, no aquí: **una lista en un
   documento se queda vieja y una en un test se pone roja.**
3. ~~Un centinela que vea el reloj 3.~~ **HECHO:**
   `RelojUnicoTest::ningun_modelo_sella_en_utc_sin_estar_declarado` recorre `app/Models/`
   **y `app/User.php`** —que no está en esa carpeta y por eso se le escapaba a todos los
   censos de este repo— y compara el reparto entero contra el esperado. Un modelo nuevo no
   cae en ninguna de las dos listas y el test lo dice.

   **Comprobado rompiéndolo**, que es la única forma de saber que un test protege lo que
   dice: quitándole el rasgo a `Year` se pone rojo y nombra el modelo.
4. ~~`importaciones`: decidir.~~ **HECHO el 22 sep 2026: se movió a Bogotá**, y con ella se
   acabó la única excepción del repo. Los catorce `now()` pasaron a `Reloj::ahora()`, la
   entrada salió de `PERMITIDOS` y el método de conversión al leer se borró con sus dos
   llamantes.

   **Lo que la desbloqueó no fue técnico.** Llevaba un mes sin hacerse porque mover la tabla
   dejaría las filas viejas cinco horas por delante — dos relojes en una columna, que es la
   enfermedad. Joseth miró **qué había dentro** en vez de cómo estaba escrito: la tabla nació
   el 20 ago 2026, la importación de alumnos es para principios de año y la de notas la está
   construyendo el front. **Ningún colegio la ha usado.** Sin filas viejas, el precio que
   bloqueaba la decisión era cero, y nadie lo había comprobado.

   > La lección no es de esta tabla: **un coste que bloquea una decisión se mide sobre los
   > datos que hay, no sobre los que la tabla podría tener.** El censo entero miró código
   > durante dos días; esto se resolvió mirando el producto.

   Se comprueba antes de desplegar, que es cuando importa:
   `SELECT COUNT(*) FROM importaciones;` en los diecisiete. Si alguno tiene filas, esas
   fechas se quedan en UTC y la decisión vuelve a estar abierta.
5. **Entonces, y sólo entonces, la transformación al leer**, que ya está escrita:
   `Reloj::desdeTexto()`. Con un solo reloj detrás, aplicarla a todo es seguro; con cuatro,
   arregla unas filas y rompe otras.
6. **El histórico, aparte y con su medición**, como se hizo en
   `2026_09_06_100000_reparar_la_hora_escrita_dos_veces`: anotando antes lo que había, y
   después de resolver la discrepancia del §5.

---

## §7. La primera medición en pantalla: **cero desfase en tres muestras de tres años**

La hizo `myvc-front-89` en Chrome el 22 sep 2026, en cuanto `4d8dd4c` hizo viajar
`matriculas.updated_at` hasta la rejilla. Es la primera vez que este censo se comprueba
**donde lo ve una persona** y no en una columna de la base:

```
matricula 2212 · celda «25 ene 2026, 11:07 p. m.» ↔ 2026-01-25 23:07:05
matricula 1921 · celda «2 sep 2025, 8:12 p. m.»  ↔ 2025-09-02 20:12:27
alumno    1    · celda «22 ago 2018, 6:59 p. m.» ↔ 2018-08-22 18:59:09
```

**Lo que hace válida la medida son los años, no las filas.** Están elegidas de 2018, 2025
y 2026 a propósito: la enfermedad de §3 —EDT con horario de verano, una hora de más de
marzo a noviembre y la correcta el resto— **se habría visto en una y no en las otras**.
Tres coincidencias exactas en tres años distintos es lo que un desfase estacional no puede
producir. Una sola fila no habría dicho nada.

De paso salió que `buscar/por-apellido` **nunca estuvo rota**: su consulta ya seleccionaba
`a.updated_at` (`BuscarController.php:12`), sólo que no había columna que lo pintara.

> **Y lo que esta medición NO demuestra**, que es la mitad del renglón: dice que **el front
> no mete desfase**, no que la hora escrita sea la correcta. Para eso hace falta comparar
> contra líneas de `auditoria`, y hoy **`matricula` no tiene ni una**: el censo de
> entidades da `nota 7339 · subunidad 496 · comportamiento 255 · …` y ni `matricula` ni
> `alumno`. La medida se repite cuando la fase 4 instrumente esa tabla.

## El precio de mudar una tabla, visto en dos filas

La mudanza de `importaciones` (§6.4) se hizo porque **no había filas viejas**. Unas horas
después, `8myvc-b7` avisó de que la base de desarrollo tenía **dos**, y resultaron ser la
mejor ilustración posible de lo que la premisa venía a evitar. Las dos son de pruebas de la
importación de planilla, del mismo día y del mismo usuario:

| id | `created_at` en la columna | escrita de verdad a las (UTC) | con qué reloj |
|---:|---|---|---|
| 2 | `2026-09-22 09:48:13` | 09:48 | `now()` — UTC |
| 3 | `2026-09-22 05:08:57` | **10:08** | `Reloj::ahora()` — Bogotá |

La 3 se escribió **veinte minutos después** que la 2, y `ORDER BY created_at` **las devuelve
al revés**. Nada en la fila dice cuál es cuál. Es exactamente lo que le pasaba a
`bitacoras.created_at` —12 filas en UTC contra 74 en Bogotá— y el motivo por el que existe
{@see Reloj}: *ordenar por esa columna no da una línea de tiempo*.

**Lo que esto NO cambia:** la decisión sigue siendo correcta, porque se tomó sobre los
diecisiete y allí la premisa es que no hay filas. Lo que sí hace es convertir una premisa en
algo que **hay que comprobar y no dar por hecho**, que es justo lo que dijo `8myvc-b7`:
alguien escribió esas dos filas aquí, y si un colegio probó la importación de alumnos desde
el 20 ago, tendrá las suyas.

**Lo que sí cambia:** el aviso pasa al bloque de despliegue de
[ESTADO-ACTUAL](ESTADO-ACTUAL.md), que es donde se mira a las tres de la mañana, y no sólo a
este documento, que es donde se mira cuando ya se sabe que hay un problema de relojes.

---

## La quinta forma en que una suite miente: el árbol se movió debajo

CLAUDE.md lista cuatro —muerta, contaminada, cortada por timeout, base desfasada—. La noche
del 21 sep 2026 apareció una quinta, y costó una corrida entera de veinte minutos:

**Se lanzó la suite y se siguieron editando ficheros mientras corría.** PHP carga cada
fichero en el momento en que el test lo necesita, así que los tests que pasaron por
`PuntoDeControlDeImportacion` e `ImportarController` **a mitad de la edición** vieron un
estado que no existió nunca: un llamante ya movido y el método al que llama todavía sin
escribir. Salieron dos rojos —`la hora que sale es la del colegio` y `reimportar lo exportado
no cambia a los alumnos`— y **los dos pasan en aislamiento**.

El delator es que **los dos rojos eran de ficheros tocados durante la corrida**, no de
ficheros al azar. Si los rojos caen justo en lo que estabas editando, la sospecha no es el
código: es el reloj de pared.

Y hay una variante peor en este repo, porque el árbol principal lo comparten varias sesiones:
**otra sesión puede mover el suelo aunque tú no toques nada.** Al ir a relanzarla había medio
commit ajeno preparado en el índice. La regla que sale de aquí es corta: **una suite entera
se lanza sobre un árbol quieto, y si no se puede garantizar que lo esté, se lanza un
subconjunto y se dice cuál.**

---

## Lo que este documento NO mira

- ~~Qué zona tiene el MySQL de cada colegio.~~ **Medido el 21 sep 2026: EDT en los
  diecisiete** (§3). Lo que queda sin mirar es si el `php.ini` de la WEB coincide con el del
  CLI, que es el que leyó la herramienta.
- **`myvc_front`, `myvc_front_2` y `myvc_flutter`.** Si alguno ya compensa cinco horas por su
  cuenta, unificar el backend **le mueve las fechas**. El radio lo mide el front, no nosotros
  ([canal con el front](../../CLAUDE.md)).
