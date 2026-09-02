# 24. El panel de inicio — qué pesa, qué tarda y qué se decide

> **Estado: medido el 2 sep 2026. Nada construido, y a propósito.** Este documento
> es la mitad de backend de una pregunta que ya estaba escrita en el otro repo desde
> el **1 sep**: *«un endpoint único para la portada, al estilo de `ChangesAsked/to-me`»*
> (`myvc_front/MIGRATION.md`, PENDIENTE apuntado ese día). Allí está el lado del
> cliente —once servicios en la portada de `app2`— y aquí lo que el servidor hace de
> verdad cuando esa portada pregunta.
>
> Lo volvió a levantar la sesión de `myvc_flutter`, que llegó por su cuenta a lo
> mismo: `MuroApi.dart` ya lleva escrito *«el día que se pueda, aquí hay que apuntar
> a un endpoint que traiga sólo esto»*.
>
> **Las tres opciones de la §6 son una propuesta.** En este repo una ruta es una
> decisión, no un efecto secundario.

---

## 1. Lo que cuesta hoy, medido por rol

Sobre `simonbolivar` —la base del contenedor, que sale de un colegio real—, el 2 sep
2026. Se llamó a `getToMe()` con el contexto montado a mano (sin token, para no
escribir nada), en caliente, **mediana de tres**, contando las consultas con
`DB::listen`:

| rol | consultas | tiempo | respuesta |
|---|---:|---:|---:|
| `Usuario` (superusuario) | **39** | **~690 ms** | **274 KB** |
| `Profesor` | 75 | ~30 ms | **279 KB** |
| `Alumno` | **49** | **~620 ms** | 225 KB |
| `Acudiente` | 8 | ~5 ms | 218 KB |

Dos cosas que no se ven en la tabla y cambian cómo se lee:

- **El que más consultas hace no es el que más tarda.** El profesor lanza 75 y
  contesta en 30 ms; el superusuario lanza 39 y tarda veinte veces más. Las 75 son
  el N+1 del horario (una consulta por unidad, para sus subunidades) y son baratas;
  los 690 ms son **dos consultas agregadas por docente**, §3.
- **El acudiente medido no tiene acudidos en el año en curso** (`alumnos` vuelve
  vacío), así que sus 8 consultas son el suelo, no el caso normal. Uno con dos
  acudidos paga seis consultas más por cada uno.

---

## 2. El 84–96% de cada respuesta es la tabla `calendario` entera

Desglose de la respuesta por clave, en KB:

| clave | `Usuario` | `Profesor` | `Alumno` | `Acudiente` |
|---|---:|---:|---:|---:|
| **`eventos`** | **231,0** | **231,0** | **215,5** | **215,5** |
| `horario_hoy` + `_manana` | 0,0 | 43,5 | — | — |
| `historial` | 21,1 | 0,0 | — | — |
| `intentos_fallidos` | 9,5 | 0,0 | — | — |
| `profes_actuales` | 7,4 | 0,0 | 2,7 | — |
| `mis_publicaciones` | 2,1 | 2,1 | — | — |
| `publicaciones` | 2,1 | 2,1 | 2,1 | 2,1 |
| `comportamiento` · `ausencias_periodo` · `libro` | — | — | 4,2 | 0,0 |
| **`alumnos`** (los pedidos de cambio) | **0,5** | 0,0 | 0,0 | 0,0 |
| resto | 0,0 | 0,0 | 0,0 | 0,0 |

La consulta es literal, en las cuatro ramas:

```sql
SELECT * FROM calendario WHERE deleted_at is null            -- 630 filas
SELECT * FROM calendario WHERE solo_profes=0 and deleted_at is null   -- 593
```

**Sin filtro de año y sin filtro de fecha.** De esas 630 filas, **123 son de 2019 a
2023** (111 de 2019 solas) y 507 de 2025.

### 2.1. Y aquí la primera lectura era falsa: **el año en curso de esta copia es 2025**

`years.actual = 1` está en el año **2025** (`year_id = 8`), no en 2026. Con eso, el
reparto de las 630 filas es el contrario del que parecía:

| | filas | KB | qué son |
|---|---:|---:|---|
| del año en curso | **507** | **184,2** | **todas cumpleaños**, uno por alumno y por docente |
| de 2019 a 2023 | 123 | ~46 | los eventos de verdad: actos cívicos, entregas de boletines, festivos |

O sea que **el calendario del panel es, en su 80%, la lista de cumpleaños del año que
se está cursando** —que es lo que el colegio quiere ver— y el resto son los eventos
que dejaron de escribirse en 2020. Los cumpleaños los regenera
`PUT calendario/sincronizar-cumples`, que **borra todos y los reinserta con
`$user->year`**: es una acción manual, así que la fecha que llevan dice cuándo se
pulsó por última vez, no qué año es hoy.

**Eso desarma el recorte por fechas**: cortar «del mes pasado en adelante» no quita
los eventos viejos, quita **los cumpleaños del año en curso**, que son el 80% de lo
que sí se enseña. Y cortar por año quita 123 filas de 630: **el 19% del peso**.

### 2.2. Lo que sí se lleva la mitad: las columnas

`SELECT *` manda 19 columnas por fila —`created_by_nombres`, `deleted_at`,
`deleted_by`, `updated_by`, `type`, `url`, y tres marcas de tiempo—. Los fronts leen
nueve: `id`, `title`, `start`, `end`, `allDay`, `solo_profes`, `cumple_alumno_id`,
`cumple_profe_id` y `url`. Medido sobre las mismas filas:

| | filas | KB |
|---|---:|---:|
| `SELECT *`, lo de antes | 630 | **231,0** |
| las nueve columnas que se pintan | 630 | **114,2** (−51%) |
| ídem, y sólo el año en curso | 507 | ~92 (−60%) |

**El recorte de columnas vale cuatro veces más que el de fechas y no esconde ni un
evento.** El de fechas suma nueve puntos más y decide qué ve la gente; el de columnas
no decide nada.

**Hecho el 2 sep 2026** ✅, en las cinco consultas de las cuatro ramas. Y con una
decisión dentro que hay que leer, porque es lo único de todo esto que se ve en una
pantalla:

> **`created_by_nombres` se quita a sabiendas.** Es la única de las nueve descartadas
> que sí se pintaba: la aplicación vieja la mete en el tooltip del evento —«Por:
> administrador», `AnunciosCtrl.ts:596`—, así que hasta que se arregle allí dirá
> **«Por: undefined»**. Joseth lo decidió sabiéndolo: *«created_by_nombres tampoco me
> interesa, podemos arreglar legacy después, o mejor aún, inhabilitar esos endpoints
> en el panel de legacy para que empiecen a usar sólo la app2»*.
>
> Lo que eso deja escrito son dos cosas distintas: el arreglo del tooltip es **una
> línea en el front viejo**, y **retirar el panel viejo es una decisión que no está
> tomada** y que no la toma este documento. Mientras no se tome, el tooltip queda así.
>
> Y lo primero es que **la sesión del front lo sepa**: quitar un campo de una
> respuesta es de las cuatro cosas que se avisan por el canal —cuerpo, campo, ruta o
> quién puede llamarla—, y el radio de impacto lo mide el front, no este repo.

El snapshot `muestreo-ChangesAsked-to-me.json` se regeneró **a propósito** y su diff
es exactamente esas nueve claves de `eventos`: ninguna otra línea del fichero se
movió, que es lo que hace que se pueda firmar.

> **Y `type` está a null en las 630.** La columna existe, la respuesta la lleva, y
> no la escribe nadie en este colegio. No es urgente; es la señal de que esta tabla
> se sirve sin que nadie la haya mirado en mucho tiempo.
>
> **La misma consulta está en `calendario/this-year`**, palabra por palabra y con la
> misma rama de `solo_profes`. Lo que se decida aquí hay que decidirlo allí, y allí
> hay además un rediseño en marcha en la sesión del front —`destinatarios`, medido
> el 1 sep contra un backend que **en este repo no existe**—. Razón de más para no
> tocar el calendario de paso mientras se arregla el panel.

Quién lee `eventos` de aquí, medido en los tres clientes (§4): **sólo la aplicación
vieja**. `app2` pinta su calendario con `calendario/this-year` y `myvc_flutter` no
toca la clave. Es decir: **el 84% de lo que paga un móvil es para una pantalla que
ese móvil no tiene.**

---

## 3. Los 690 ms del superusuario y los 620 del alumno son la misma función

`datos_de_docentes_este_anio()` ([línea 470](../../app/Http/Controllers/ChangeAskedController.php#L470))
lista los docentes contratados del año —aquí **16**— y **por cada uno lanza dos
consultas agregadas**: el porcentaje de avance de sus unidades y subunidades, y
cuántos de sus grupos tienen nota de comportamiento. Las cuatro más lentas de cada
rol son todas la misma:

```
   127,3 ms  SELECT sum(if( r2.porc_uni=100, 1, 0)) uni_correctas, SUM(r2.sub_correctas) ...
   122,0 ms  (idem, otro docente)
   109,3 ms  (idem)
   102,6 ms  (idem)
```

**Eso es el panel entero del superusuario**: 32 consultas de las 39, y prácticamente
los 690 ms.

### 3.1. Y el alumno paga lo mismo — por un dato que es de gestión

La rama de `Alumno` llama a la misma función. El alumno recibe, por cada uno de los
16 docentes:

```php
[profesor_id] => 7   [nombres] => ARIOLFO   [apellidos] => GÓMEZ PICO
[cant_asignaturas] => 4   [foto_nombre] => ...   [porcentaje] => 15
```

**`porcentaje` es lo al día que va ese docente con su planilla.** No es un dato
personal de nadie —no cae bajo la regla del alumno—, pero es un indicador de
desempeño del profesorado, y llega al móvil de un alumno de once años sin que
ninguna pantalla lo pinte. Los ~600 ms de su panel son eso y sólo eso.

> **Esto no lo encontró el barrido** ([05 §14](05-codigo-muerto-y-roto.md)) y hay que
> saber por qué, porque es la misma forma de siempre: el barrido pregunta *«¿sale el
> dato personal de alguien?»*, y `porcentaje` no es de nadie. La pregunta que lo
> encuentra es la de este documento —*«¿qué está pagando esta pantalla?»*—, y es
> distinta.

### 3.2. La decisión de interfaz que hoy la toma el cliente por parámetro

En la rama de `Profesor`, esa función **sólo se llama si el cliente manda
`?anchoWindow=1280`**:

```php
if (Request::input('anchoWindow') > 500) { $profes_actuales = ...; }
```

El ancho de la ventana del navegador decide qué consultas corre el servidor. Funciona
—es lo que hace que el profesor tarde 30 ms y no 700—, pero es la mitigación puesta
en el sitio donde no se puede razonar sobre ella: un cliente que no mande el
parámetro recibe la respuesta corta y otro que mande `9999` dispara el barrido de los
docentes sin que nada lo justifique.

---

## 4. Tres clientes, y cada uno usa un trozo distinto

| clave | app vieja (AngularJS) | `app2` (Angular) | `myvc_flutter` |
|---|:--:|:--:|:--:|
| `alumnos` (pedidos de cambio) | sí (`AnunciosDir`) | sí (`peticiones`) | **sí, pero para otra cosa**: son los acudidos |
| `publicaciones` · `mis_publicaciones` | sí | sí (el muro) | sí (el muro) |
| `horario_hoy` / `_manana` | sí | sí («Clases de hoy») | sí (`HorarioDeHoy`) |
| `profes_actuales` | sí | sí («Docentes del año») | no |
| `historial` · `intentos_fallidos` | sí | no (tiene su pantalla) | no |
| **`eventos`** | **sí** (su calendario) | **no** (usa `calendario/this-year`) | **no** |
| `ausencias_periodo` · `comportamiento` · `situaciones` · `uniformes` · `libro` | sí | sí | sí (`AsistenciaAlumnoApi`) |

**Ninguna clave la usan los tres para lo mismo**, y una la usan para cosas
contrarias: `alumnos` son *pedidos de cambio* para un superusuario y *los acudidos*
para un acudiente, en el mismo campo del mismo endpoint. Flutter lo lleva escrito en
`MuroApi.dart` con un aviso para el que llegue después.

Esa tabla es lo que hace que **«mejorarlo de verdad» y «no romper nada» sean la misma
frase con dos respuestas**: cualquier cosa que se le quite a la respuesta se la quita
a los tres, y el cliente al que le duele —la aplicación vieja— es el que está
desplegado en los quince colegios y el que menos se toca.

---

## 5. Por qué la caché no es la respuesta, y qué parte sí

La idea de cachear `to-me` choca con lo que ya está medido en
[`02-plan-rendimiento.md`](02-plan-rendimiento.md): `CONTEXTO_SEGUNDOS` existe, ahorra
**0,75 ms** y por eso está apagado. Aquí sería peor que inútil:

- **La respuesta es por usuario y por momento.** El horario cambia de día, los
  intentos de login fallidos y el historial son de la sesión de ahora mismo, y los
  pedidos de cambio son justo lo que la bandeja tiene que ver **sin retraso**. Una
  caché de la respuesta entera devuelve una bandeja vieja, que es el fallo peor de
  esta pantalla: un pedido atendido que sigue apareciendo.
- **La parte cacheable es la que no es de nadie**, y es justo la que pesa:
  `eventos` (idéntica para todos salvo `solo_profes`) y `profes_actuales` (idéntica
  para todo el colegio dentro de un periodo). Pero cachear 231 KB por colegio en un
  hosting compartido para no leer una tabla de 630 filas que tarda **1,6 ms**
  es tratar el síntoma: **el problema de `eventos` no es leerla, es mandarla.**

**Lo que sí se sostiene**, si se quiere una caché: la de `profes_actuales`, que son
32 consultas agregadas de 690 ms sobre datos que sólo cambian cuando un docente toca
sus unidades. Pero antes de cachearla hay una pregunta más barata: **¿la pantalla de
un alumno tiene que calcularla?**

---

## 6. Las tres opciones, con lo que cuesta cada una

### A. Recortar `to-me` por dentro, sin ruta nueva — **dos hechos el 2 sep 2026**

Ninguno toca la forma de la respuesta: las diez claves siguen ahí.

**1. `profes_actuales` vacío en la rama de `Alumno`** ✅. Es la §3.1: dos consultas
agregadas por docente para un indicador de desempeño del profesorado que no pinta
ningún cliente.

**2. El N+1 de dos pisos del horario, en dos consultas** ✅ — una de unidades con
`IN` y una de subunidades con `IN`, agrupadas en PHP. Comprobado contra el algoritmo
viejo sobre la población real del colegio (17 asignaturas, 36 unidades, 54
subunidades): **la respuesta es idéntica byte a byte**.

Lo que cuestan las dos, medido igual que la §1 —en caliente, mediana de tres—:

| rol | consultas | tiempo | respuesta |
|---|---|---|---|
| `Usuario` | 39 → **39** | ~700 ms → ~700 ms | 274 KB (igual) |
| `Profesor` | 75 → **17** | ~30 ms → ~27 ms | 279 KB (igual) |
| **`Alumno`** | 49 → **24** | **~620 ms → ~20 ms** | 225 → 222 KB |
| `Acudiente` | 8 → **8** | ~5 ms | 218 KB (igual) |

**El panel de un alumno pasó de 620 ms a 20 ms.** El del superusuario no se movió, y
es correcto que no se moviera: él sí pinta el avance de sus docentes, así que sus 32
consultas agregadas son trabajo pedido. Bajarlas es cachearlas (§5) o cambiar lo que
la pantalla enseña, y eso es otra decisión.

Lo fija `tests/Contrato/PanelDeInicioTest.php`, dos casos, y **comprobado al revés**:
con el código de antes caen los dos.

**3. Las nueve columnas del calendario en vez de `SELECT *`** ✅. Es la §2.2, y es lo
que parte en dos el peso de la respuesta en los cuatro roles. Con el recorte del
calendario dentro, la tabla de arriba queda así:

| rol | consultas | tiempo | respuesta |
|---|---|---|---|
| `Usuario` | 39 → **39** | ~700 ms → ~760 ms | **274 → 157 KB** |
| `Profesor` | 75 → **17** | ~30 → ~13 ms | **279 → 162 KB** |
| **`Alumno`** | 49 → **24** | **~620 → ~24 ms** | **225 → 112 KB** |
| `Acudiente` | 8 → **8** | ~5 → ~8 ms | **218 → 108 KB** |

**El panel pesa la mitad para todo el mundo**, y el del alumno además tarda treinta
veces menos.

**Lo que queda de la opción A y no se ha hecho:**

4. El `$dia + 1 = 7` del sábado, que hoy no se ve porque el horario vuelve vacío
   para todos ([23 §2.1](23-horarios.md)). Va con el módulo de horarios, no aquí.

**Barato, sin ruta nueva, y no arregla el problema de fondo**: el panel sigue siendo
un cajón de sastre que le sirve a tres clientes lo que necesita el que más pide.

### B. Un endpoint agregador nuevo al lado — `panel/portada`

Es lo que propone el pendiente del front del 1 sep, y **es la opción que menos riesgo
tiene por una razón concreta: `to-me` no se toca**. La aplicación vieja sigue
recibiendo byte a byte lo mismo, y `app2` y Flutter se mudan cuando quieran.

Lo que hay que decidir antes de escribirlo está ya escrito allí y no se re-litiga
aquí: qué devuelve por rol (armado **desde el token**, no la unión de todo), qué pasa
cuando falla una mitad (cada bloque con su estado, no un 500 global), y cuánto pesa.

**Lo que cuesta:** una ruta nueva (mueve `CLAUDE.md` y tres snapshots), y **quince
despliegues** antes de que ningún front pueda usarla.

### C. Retirar `to-me` del panel

**No es posible hoy, y conviene decirlo para no volver a proponerlo**: es el único
sitio del que la aplicación vieja saca su calendario, sus publicaciones, el historial
de sesiones y la bandeja de pedidos. Retirarlo del panel de `app2` y de Flutter sí es
posible —es la opción B vista desde el cliente—, pero la ruta se queda viva mientras
la aplicación vieja esté desplegada en los quince colegios.

---

## 7. Los pedidos de cambio: cambiar la tabla **no arregla el panel**

La pregunta llegó junto a la anterior —*«¿podemos cambiar la estructura en base de
datos para llevar los pedidos de alumnos y profesores de otra forma más adecuada?»*—
y hay que contestar primero lo que las une, que es nada:

**Los pedidos son 0,5 KB de los 274 KB y una consulta de las 39.** Rediseñar
`change_asked` no le quita un milisegundo al panel. Son dos decisiones distintas y se
toman por separado.

Dicho eso, la estructura de hoy sí tiene un problema real y se puede nombrar con
precisión:

| tabla | forma | filas hoy |
|---|---|---:|
| `change_asked` | la cabecera: quién pide, a quién, sobre quién, y `data_id` / `assignment_id` | 104 (6 pendientes) |
| `change_asked_data` | **54 columnas**: un par `campo_new` / `campo_accepted` por cada campo de la ficha del alumno | |
| `change_asked_assignment` | **19 columnas** del mismo estilo, para notas, frases y asignaturas | |

Es un espejo campo a campo de `alumnos`. Las consecuencias, que no son teóricas:

- **Añadir un campo a la ficha del alumno son dos columnas más aquí**, en los quince
  colegios, más el `if` que las lee en `ChangeAskedController` y en los dos fronts.
- **No se puede pedir un cambio sobre algo que no sea un alumno o una asignatura.**
  Un docente que quiera corregir su propio apellido no tiene dónde.
- **Un pedido no guarda el valor viejo**, así que cuando se aprueba no queda el
  «de X a Y» que hace auditable la aprobación. Lo que queda es la ficha ya cambiada.

La forma que quita las tres es la estrecha: una cabecera (`pedido`: quién, sobre qué
entidad y qué fila, estado, quién resolvió y cuándo) y una fila por campo
(`pedido_campo`: nombre del campo, valor viejo, valor nuevo, aceptado). Deja de haber
columnas que añadir, sirve para cualquier entidad, y la bandeja sale de **una**
consulta.

**Lo que cuesta, y por eso es una decisión y no una limpieza:** una migración de
esquema en los quince colegios con traspaso de las 104 filas (aquí; en los otros
catorce sin medir), tres controladores reescritos, y **dos fronts** —la aplicación
vieja incluida, que es la que menos se toca—. Y la aplicación vieja no se puede
desplegar a medias: mientras exista, la bandeja tiene que seguir contestando con la
forma de hoy o hay que cambiarla también.

Antes de decidirlo hay una medición que no está hecha y es barata: **cuántos pedidos
vivos hay en los quince colegios**. Si el mecanismo se usa en dos, la respuesta buena
puede ser otra.

---

## 8. Lo decidido el 2 sep 2026, y lo que sigue abierto

Joseth decidió, sobre la §1 y la §3 de este documento:

1. **A y luego B**: recortar ahora por dentro y escribir después el agregador
   `panel/portada` al lado, con `to-me` intacto. Los dos primeros recortes están
   hechos (§6.A).
2. **`eventos`**: la primera respuesta fue «por rango de fechas», tomada sobre una
   premisa que la medición siguiente desmintió —aquí el año en curso es **2025**, sus
   507 filas son **cumpleaños** y son el 80% del peso, mientras que lo viejo son 123
   filas (19%): cortar por fecha quita lo que la gente mira y deja lo que no mira
   nadie—. Volvió a la mesa como **recorte de columnas** y así se hizo (§2.2),
   **incluida `created_by_nombres`**. Las filas de `calendario` **no se tocan**: lo
   único que cambió es qué columnas manda el endpoint.

   > Y quedó dicha, sin decidir, una tercera cosa: **inhabilitar estos endpoints en
   > el panel viejo para forzar el paso a `app2`**. No está tomada y no se toma aquí.
3. **Los pedidos se rediseñan**, en la forma estrecha. El diseño vive en su propio
   documento: [25-pedidos-de-cambio.md](25-pedidos-de-cambio.md).

Y una que nadie ha pedido pero está medida y conviene no perder: **los 700 ms del
superusuario son 32 consultas agregadas para calcular el avance de sus dieciséis
docentes**. Cachearlas por periodo es lo único que las baja sin cambiar la pantalla.
