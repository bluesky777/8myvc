# 34 · La huella de sincronización

> **Medido el 7 sep 2026 contra `8e38590`**, con `curl` y un token real sobre la
> base de desarrollo (`simonbolivar`, MySQL 8.0.42), y los tamaños de la huella
> desde la suite en `.worktrees/e5`. Producción es **MariaDB 10.5.25**: nada de lo
> que hay aquí usa `JSON_TABLE`, `LATERAL` ni `->>`.

Joseth pidió que la app de escritorio del horario **se sincronice con MyVC cada
minuto**. Esta ruta es lo que hace que esa frase no cueste 58 MB por jornada.

---

## 1. Lo que costaba preguntar «¿ha cambiado algo?»

`myvc_horarios` lee cinco cosas. Medido con un token real:

| Lectura | Bytes | Filas que devuelve | Filas en la tabla |
|---|---:|---:|---:|
| `GET years` | 33.331 | 9 | 9 |
| `GET grados` | 2.854 | 14 | 16 |
| `GET grupos` | 4.089 | 13 | 118 |
| `GET profesores` | 25.105 | 47 | 53 |
| `GET asignaturas` | 55.804 | 134 | 1.459 |
| **Total** | **121.183** | **217** | |

**Y no hay forma de preguntar barato: ninguna de las cinco manda `ETag` ni
`Last-Modified`**, así que la petición condicional no existe y **un 304 es
imposible**. Cada pregunta trae los 121.183 bytes enteros aunque no haya cambiado
nada.

### Lo que eso suma, con las horas dichas

**Los bytes exactos y su unidad, porque una cifra sin denominador nombrado empuja
a decidir por una razón que no existe:**

| | Bytes | Decimal | Binario |
|---|---:|---:|---:|
| una hora | 7.270.980 | 7,3 MB | 6,9 MiB |
| **jornada de 8 h** | **58.167.840** | **58,2 MB** | 55,5 MiB |
| día de 24 h | 174.503.520 | 174,5 MB | 166,4 MiB |

**La que se paga es la de jornada.** Nadie tiene ese programa abierto veinticuatro
horas: un colegio lo abre por la mañana y lo cierra por la tarde. La de 24 h va
escrita **para que nadie la use para decidir** — es tres veces la real y habría
empujado a bajar el ritmo, que no es la salida elegida.

---

## 2. Las tres opciones, con su precio

| | Qué cuesta | Qué arregla | Qué no |
|---|---|---|---|
| **(a) Bajar el ritmo** (cada 5 o 10 min) | cero código | divide los bytes por 5 o por 10 | **no arregla nada del servidor** —sigue trayendo 207 filas cada vez— y **empeora el producto**: es justo lo que Joseth pidió al revés |
| **(b) `ETag`/`Last-Modified` en las cinco** | tocar **cinco** controladores; hay que calcular la respuesta **para saber si cambió**, así que el servidor trabaja igual | los bytes, cuando no hay cambios | el trabajo del servidor. Y son cinco viajes igual |
| **(c) Un endpoint de huella** ← **elegida** | **una ruta nueva** | **un viaje, 345 bytes y cinco cuentas** en vez de cinco viajes y 207 filas | nada: cuando la huella se mueve, el cliente pide las cinco como hoy |

**(c) es la única que ahorra trabajo también al servidor**, y por eso es la que
autorizó Joseth. Las otras dos ahorran red y dejan la carga igual.

### El ahorro, medido

La huella son **345 bytes** (medidos sobre el seed; **no crece con los datos** —
son cinco bloques de dos cifras, pase lo que pase en las tablas).

```
por pregunta      121.183 B  ->      345 B     0,28 %   ·  351 veces menos
jornada de 8 h  58.167.840 B  ->  165.600 B    0,17 MB  (antes 58,2 MB)
```

---

## 3. La forma, y por qué cada pieza está así

```json
{
  "year_id": 8,
  "huellas": {
    "years":       { "filas": 8,  "ultimo_cambio": "2026-08-19 14:08:10" },
    "grados":      { "filas": 14, "ultimo_cambio": "2026-08-18 01:55:10" },
    "grupos":      { "filas": 1,  "ultimo_cambio": "2025-01-14 22:46:18" },
    "asignaturas": { "filas": 10, "ultimo_cambio": "2025-01-20 06:37:02" },
    "profesores":  { "filas": 47, "ultimo_cambio": "2026-08-18 11:50:39" }
  },
  "omitidas": {}
}
```

### 3.1 · Dos cifras, y no es redundancia

**`updated_at` no ve un borrado**: la fila que se va no deja timbre. El conteo sí.
Con sólo la fecha, borrar una asignatura sería **invisible** hasta que alguien
tocara otra. Con sólo el conteo, editar una sin borrar nada sería invisible.

Hay un test que lo fija borrando una asignatura **sin tocar `updated_at`** y
comprobando que baja `filas` **y que la fecha NO se mueve** — la segunda mitad es
la que demuestra el punto.

### 3.2 · Se calcula sobre **lo que devuelve cada lectura**, no sobre la tabla

**Es la restricción de la que depende todo lo demás.** La tabla y la respuesta no
se parecen (§1): `asignaturas` tiene 1.459 filas y la lectura devuelve **134**.

Una huella de la tabla entera **se movería con filas que el cliente nunca ve** —y,
peor, **podría no moverse con filas que sí ve**—. Así que cada consulta de
`SincronizacionController` repite los `WHERE` y los `JOIN` de su `getIndex`,
**incluido el año del usuario**.

> **Y eso no se protege con un comentario, se protege con un test.** El caso
> principal no compara contra números escritos a mano: llama a la huella **y a las
> cinco lecturas con el mismo token**, y compara `filas` contra `count()` de lo que
> devuelve cada una. El día que alguien cambie un filtro en un `getIndex` y no
> aquí, se pone rojo.

### 3.3 · Las tablas unidas cuentan, porque sus datos viajan en la respuesta

Las cinco lecturas devuelven columnas de otras tablas —el nombre de la materia y
del área en `asignaturas`, el titular y el grado en `grupos`, el usuario y el
contrato en `profesores`, el nivel en `grados`— **y `years` devuelve las filas
enteras de `periodos` dentro**. Cambiar cualquiera de esas cambia la respuesta sin
tocar la tabla principal.

Así que `ultimo_cambio` es el máximo entre la tabla y sus unidas. **Costaba 9 ms
sin ellas y 11 ms con ellas**, así que no había nada que decidir.

> **Los periodos cuentan para la FECHA pero no para el conteo de `filas`**: la
> lectura devuelve un elemento por año, no por periodo, y meterlos en el conteo
> mentiría sobre el tamaño de la respuesta.

### 3.4 · La comparación de fechas se hace en PHP

`GREATEST` sobre un `datetime` y el `0` de un `COALESCE` obliga al motor a
convertir, y **MariaDB 10.5 y MySQL 8 no tienen por qué coincidir en esa
conversión**. Aquí las dos llegan como cadenas `Y-m-d H:i:s` —que **ordenan igual
como texto que como fecha**— y el `max()` es de PHP. Es la lección de
[`33-la-tilde-que-sql-no-ve.md`](33-la-tilde-que-sql-no-ve.md): lo que se puede
sacar del motor, se saca.

`ultimo_cambio: null` significa **«ninguna de las filas que se devuelven tiene
fecha»**, no «no hay filas» — eso lo dice `filas`.

---

## 4. El guard, que no se eligió por comodidad

**Las cinco lecturas no exigen lo mismo**, y ésa es toda la dificultad:

| | Ruta | Dentro del método |
|---|---|---|
| `years`, `grados`, `grupos`, `asignaturas` | `auth.token` | — |
| **`profesores`** | `auth.token` + **`auth.personal`** | **`esAdministrativo`** |

Medido sobre `simonbolivar` el 7 sep 2026:

```
auth.token         2.328 cuentas activas
auth.personal         45
esAdministrativo      10      (10 superusuarios + 0 secretarios)
```

**Una huella que devolviera el movimiento de `profesores` a todo el que pasa el
guard de la ruta se lo estaría contando a 35 personas que no pueden leer esa
tabla.** Que sean dos números no lo hace inocuo: *«el personal cambió hace un
minuto»* es información sobre personas.

**La decisión, en dos partes:**

1. **La ruta lleva `auth.personal`**, aunque sea **más estrecho** que cuatro de
   las cinco lecturas que resume. No filtra nada nuevo —esas cuatro las lee
   cualquiera con token— y **el único cliente de esta ruta es un programa de
   personal**: baja la superficie de 2.328 a 45 sin quitarle nada a nadie. *Si
   algún día un cliente de alumno la necesitara, esto es lo que hay que releer
   antes de aflojarlo.*
2. **El bloque de `profesores` lo decide `esAdministrativo` DENTRO**, el mismo
   criterio que la lectura de verdad. Un guard de ruta no puede expresar «cuatro
   de las cinco cosas que devuelvo son para ti y la quinta no».

### Y cuando se omite: la clave **se queda a `null`** y además se anuncia

```json
{
  "huellas": { "years": {…}, "grados": {…}, "grupos": {…}, "asignaturas": {…},
               "profesores": null },
  "omitidas": { "profesores": "Necesitas permiso de administración para ver esta huella…" }
}
```

**Las cinco claves están siempre.** Lo que cambia es si traen algo.

**Y esto se implementó primero al revés —quitando la clave— y se corrigió**, porque
es la misma decisión que ya tomó esta API sobre **este mismo cliente**: la decisión
7 del `inadecuado`, donde se pudo quitar `profesor_id` de la respuesta y **a
propósito no se quitó**, se hizo anulable. La razón escrita entonces vale aquí
palabra por palabra:

> *un lector que exija la clave se rompe con el usuario raso y no con el
> administrativo — el fallo que sólo sale en producción y en la mitad de las
> cuentas.*

Y aquí es peor de lo normal, porque **el escritorio lo usan administrativos**: en
cualquier prueba saldrían las cinco claves, y la respuesta de cuatro sólo
aparecería el día que entrara alguien del personal llano. **Dos respuestas del
mismo módulo resolviendo esto de formas contrarias es lo que nadie reconstruye a
los seis meses**, así que se resuelve igual.

**Hacen falta las dos piezas, no una.** `null` mantiene la forma; `omitidas` dice
**por qué**. Un `null` a secas y «no ha cambiado nada» se leen igual desde el
cliente, y de las dos lecturas la falsa es la que deja al escritorio con datos
viejos creyendo que está al día. Es el mismo cuidado que un `0` sin denominador.

*Lo ata `test_las_cinco_claves_salen_pregunte_quien_pregunte`, que compara las
claves de los dos sujetos. Sin ese caso, «limpiar» la clave nula sería un cambio
que pasa la suite entera.*

---

## 5. El limitador: entra como todas, y no hace falta excepción

**La pregunta era si una ruta que se llama cada minuto debería quedar fuera de los
121/min. No: no se acerca.**

- **Medido**: el limitador general es `Limit::perMinute(120)->by(<id de usuario>
  ?: <ip>)`, y con token **la clave es el id del usuario**, no la IP. O sea que un
  colegio entero detrás de un NAT no comparte cubo.
- **Aritmética sobre eso**: el escritorio hace **1 petición por minuto** en el caso
  normal, y **6** el minuto en que algo cambió (la huella más las cinco lecturas).
  Son **5 %** del cubo en el peor minuto y **0,8 %** en el resto.

> **Y sacarla del limitador sería peor que no hacer nada**: el limitador es lo
> único que separa «un cliente pregunta cada minuto» de «un cliente con un bucle
> roto pregunta mil veces por minuto», que es exactamente el fallo que un
> temporizador mal puesto produce. Una ruta barata llamada en bucle sigue siendo
> una consulta por llamada.

---

## 6. El límite conocido, con su cifra

**`asignaturas` tiene 5 filas de 1.459 con `updated_at` a NULL.** Modificar una de
esas cinco no movería `max(updated_at)` y sería invisible para la fecha —un alta o
una baja sí se verían por el conteo—.

**Y medido sobre lo que devuelve la lectura, hoy son 0 de 134**: las cinco viven en
`year_id = 1`, y la lectura filtra por el año del usuario, que es el 8. O sea que
**el límite existe y hoy no toca a nadie**, y tocaría a un colegio cuyo año actual
fuera aquél.

Las otras cuatro lecturas están al 100 %: `years` 9/9, `grados` 14/14, `grupos`
13/13, `profesores` 47/47 — **contadas sobre lo que devuelven, no sobre la tabla**,
que es la misma distinción de la §3.2 aplicada al límite.

*No se arregla: rellenar esos cinco `updated_at` es escribir en datos de un colegio
para que una herramienta nuestra funcione mejor, y no es una decisión de una
sesión.*

---

## 7. El cuarto snapshot, que el procedimiento no nombraba

**`CLAUDE.md` decía que una ruta nueva mueve el documento y `tres` snapshots. Son
cuatro cuando la ruta estrena familia**, y esto lo destapó la suite entera en rojo
después de haber movido los tres «correctos».

El cuarto es `familias-que-nunca-entran-en-el-candado.json`, y esta ruta entra
como:

```
"sincronizacion": "1 de 1"
```

**Eso no es un agujero y no hay nada que arreglar.** Ese censo no lista rutas sin
guard: lista **familias con menos de dos hermanas con guard**, que son las que el
candado de consistencia por familia descarta con un `continue` **y no mira nunca**.
`sincronizacion` es una familia de **una** ruta, y esa ruta **tiene su guard** —el
renglón que sí sería un agujero es `0 de 1`—.

Lo que el renglón dice de verdad es que **a esta familia no la puede proteger el
candado de familia**, porque ese candado compara hermanas y aquí no hay ninguna con
la que comparar. Lo que la protege es su propio guard y
`HuellaDeSincronizacionTest`. El día que `sincronizacion` tenga una segunda ruta
con guard, **saldrá sola del censo**.

*Corregido en `CLAUDE.md` en este mismo commit, con la condición: el cuarto se
mueve al estrenar familia, y también cuando una familia que tenía una sola guardada
pasa a dos —entonces sale—. No se mueve si la familia ya tenía dos o más.*

---

## 8. Lo que sigue sin medirse, dicho con esas palabras

- **Nadie ha corrido esto contra un colegio real.** Todo es el docker y la base de
  tests.
- **No se ha medido con el escritorio de verdad conectado**: los 345 bytes y el
  ritmo de una vez por minuto son la forma de la respuesta y la petición de Joseth,
  no un tráfico observado.
- **La huella no vigila `images`** (el logo del año, la foto del profesor), que
  también viaja en dos de las respuestas. Se dejó fuera porque cambiar una imagen
  no cambia el horario, que es lo que este cliente cuadra — **pero es una decisión,
  no un olvido**, y el día que alguien la eche en falta ésta es la línea que
  explica por qué no está.
