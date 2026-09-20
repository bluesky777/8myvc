# 22 · El calendario del colegio

> **Estado (1 sep 2026):** pasos 1, 2 y 3 hechos en `feat/calendario`, **sin
> fusionar y sin desplegar**. Los pasos 4 y 5 son del front y del borrado de las
> filas viejas; el 8 —Firebase y el cron de recordatorios— no se ha empezado.
>
> La épica la coordina la sesión de front `myvc-front-14`; la pantalla la escribe
> `myvc-front-08`. **El contrato de `PUT calendario/mes` está confirmado con las
> dos** y lo que sigue es lo acordado, no una propuesta.

## §1 · Por qué los cumpleaños dejan de ser filas

`CalendarioController::putSincronizarCumples()` genera los cumpleaños **como
filas de `calendario`** y hace tres cosas que no tienen arreglo dentro de ese
diseño:

1. **Sella el año.** Sustituye el año de nacimiento por `$user->year`
   (`REPLACE(a.fecha_nac, SUBSTRING_INDEX(a.fecha_nac, "-", 1), ?)`), así que los
   cumpleaños generados un año **no existen** al siguiente. Los 507 de la base
   están todos en 2025 y en 2026 no hay ninguno.
2. **Congela la matrícula.** Une contra los grupos de `$user->year_id`: la lista
   es una foto del día en que alguien pulsó el botón.
3. **Empieza por un `DELETE` sin `WHERE` de año.** Pulsarlo desde un año lectivo
   con poca gente matriculada **cambia el calendario entero por el de ese año,
   sin error y sin poder deshacerlo**.

Los tres son el mismo fallo: **estado derivado que se guarda**. Calcularlos al
pedir el mes los quita a la vez, porque no queda estado que se pueda quedar
viejo. El botón desaparece de la pantalla (paso 4, del front) y el método se
retira después.

**Las 507 filas NO se borran todavía.** Son la red mientras la pantalla nueva no
funcione; el borrado es el paso 5. Mientras tanto `PUT calendario/mes` las
**excluye** (`cumple_alumno_id IS NULL AND cumple_profe_id IS NULL`): sin ese
filtro cada cumpleaños se pintaría dos veces, y el front no se quejaría —los dos
caen el mismo día y el agrupado diría «2 cumpleaños»—.

## §2 · Lo que se añadió a la base

`2026_09_01_100000_calendario_con_destinatarios`. Todo aditivo; ni un `DROP`.

| Qué | Dónde |
|---|---|
| `descripcion` TEXT NULL | `calendario` |
| `recordatorio_minutos` INT NULL | `calendario` |
| `recordatorio_enviado_at` DATETIME NULL | `calendario` |
| índice `(start, deleted_at)` | `calendario` |
| tabla `calendario_destinatarios` | nueva |

**`recordatorio_enviado_at` es `DATETIME` y no `TIMESTAMP`**, aunque las tres
columnas de fecha que ya tiene la tabla sean `TIMESTAMP` y aunque el encargo
dijera `TIMESTAMP`. El argumento es el de la migración de `auditoria` y la §1.2:
`config/database.php` no fija la zona de la sesión de MySQL, son quince cuentas
de cPanel, y un `TIMESTAMP` convierte al leer y al escribir. Se decide ahora
porque es columna nueva y **no hay ni una fila que convertir**.

**`calendario_destinatarios` no tiene clave única, y es deliberado.** La forma
obvia —`unique(calendario_id, publico, grupo_id)`— **no protegería de nada**: en
MySQL cada NULL es distinto de los demás, así que dos filas
`(1460, 'alumnos', NULL)` —la misma frase, «todos los alumnos», que es la que se
marca con una casilla— caben las dos. Una restricción que sólo para los
duplicados que no van a ocurrir es peor que no tenerla, porque **se cuenta como
cumplida**. La deduplicación es del escritor y lo fija
`CalendarioGuardadoTest::test_los_destinatarios_se_guardan_deduplicados`.

## §3 · Los tres estados de «para quién es», y el de en medio es el que no se ve

- **Ninguna fila y `solo_profes = 0`** → público. Es el comportamiento de hoy y
  **sigue siendo el defecto**: los eventos que ya existen no tienen filas y no
  cambian de significado.
- **Ninguna fila y `solo_profes = 1`** → **sólo personal**.
- **Con filas** → mandan las filas. `grupo_id` NULL = todos los de ese público.

**La segunda regla es una corrección al encargo, y es la que más importa.** La
propuesta decía «sin filas de destinatarios = público» a secas. Medido por la
coordinación del front: de los 139 eventos manuales vivos, **37 tienen
`solo_profes = 1`**. Con la regla literal, **el día del despliegue esos 37 se
vuelven públicos** — el mismo fallo que la épica viene a arreglar, cometido por
la otra puerta. Y no habría dado ningún error.

En la respuesta ese caso viaja como **una fila explícita `('personal', null)`**,
no como algo que el front deduzca mirando `solo_profes`. Con eso
`destinatarios: []` significa «público» y sólo eso.

## §4 · `solo_profes` NO se retira: pasa a ser un espejo

Se escribe en **cada guardado**: 1 cuando las únicas filas de destinatarios son
de `personal`, 0 en cualquier otro caso.

**No es un residuo y no se puede limpiar.** `calendario/this-year` la siguen
leyendo **la aplicación vieja y `myvc_flutter`**, desplegadas en los quince
colegios. Si se deja de escribir, un evento «sólo personal» se ve **público**
allí y **no da ningún error**. Vive mientras vivan esos dos clientes.

## §5 · Quién ve qué, y por qué el personal lo ve todo

| Quién | Qué recibe |
|---|---|
| Personal (`tipo == 'Profesor' \|\| is_superuser`) | **todo** |
| Alumno | público + `('alumnos', null)` + `('alumnos', su grupo)` |
| Acudiente | público + `alumnos`/`acudientes` de **los grupos de sus acudidos** |
| Administrativo sin superusuario | sólo el público |

**Que el personal lo vea todo es una decisión escrita, no un olvido.** El encargo
decía «personal → `('personal', …)`». Con eso, **el docente que acaba de crear
«Salida de 7º» no la vería en su propio calendario**, y la pantalla nueva
enseñaría menos que la que sustituye. Los destinatarios existen para no llenar de
ruido a las familias, no para esconderle el calendario al colegio: `solo_profes`
esconde cosas **de** las familias, nunca del personal.

**Que el acudiente entre también por `alumnos`** es la regla de negocio de la
casa —*«un alumno solo ve lo suyo; un acudiente, lo suyo y lo completo de sus
acudidos»*—. Sin eso el colegio tendría que repartir cada evento dos veces para
que llegara a las familias, y **un reparto que se hace dos veces se hace mal una
de las dos**.

**El administrativo sin superusuario ve sólo lo público**, que es lo que ve hoy.
Sale de reusar el `if` de las otras cinco rutas en vez de inventar uno; el
candidato de «no es alumno ni acudiente» habría ampliado el calendario interno a
diez cuentas administrativas. Es la misma decisión medida del §150, y sigue
abierta la pregunta de si `solo_profes` debe significar «solo profesores» o «solo
personal».

## §6 · EL HALLAZGO QUE NO BUSCABA NADIE: siete respuestas cambiaron por añadir tres columnas

**«No tocar `calendario/this-year`» era justamente lo que la tocaba.**

El encargo insistía en que esa ruta se quedara **exactamente** como está.
`putThisYear()` hace `SELECT * FROM calendario`, así que las tres columnas nuevas
**se colaron solas en su respuesta**. Y no era una: `grep` sobre `app/` da
**siete** lectores con `*`, las dos de `putThisYear()` y **cinco de
`ChangeAskedController`**.

**Se vio porque movió dos instantáneas** —`muestreo-calendario-this-year` y
`muestreo-ChangesAsked-to-me`—, **no porque nadie lo pensara**. Y sólo una de las
cinco de `ChangeAsked` está cubierta por el muestreo: **las otras cuatro habrían
llegado a producción calladas**, que es por qué el arreglo se hizo contando la
población entera con `grep` y no fichero a fichero según lo que se pusiera rojo.

Es exactamente lo que se pagó el **24 ago 2026** en los cuatro `SELECT *` sobre
`matriculas`, y **la regla no caduca con estas tres columnas**: la próxima que se
añada a `calendario` entra por `*` igual de callada. Por eso la lista vive en
**un solo sitio**, `CalendarioController::COLUMNAS`, y no copiada siete veces:
con siete copias, la siguiente columna entra por las seis que alguien olvide.

> **La forma general, que es lo que se hereda:** *añadir una columna a una tabla
> es tocar todas las respuestas que la leen con `\*`.* Una épica puede prometer
> «esta ruta no se toca» y romperla **sin escribir una línea en esa ruta**.

### §6.1 · Y un aviso para quien vuelva a contar esto con `grep`

Este documento y los docblocks de este arreglo **explican el fallo citando la
cosa que quitaron**, así que hoy `grep -rn "SELECT \* FROM calendario" app/ tests/
docs/` da **seis** líneas y **ninguna es un lector de respuesta**: cuatro son
prosa —el docblock de `COLUMNAS`, dos de `CalendarioSoloProfesTest`, uno de
`CalendarioInternoTest`, y el §6 de aquí— y la sexta es
`CalendarioGuardadoTest`, que lee una fila para comprobarla y no devuelve nada.
Lectores de respuesta con `*` quedan **cero**.

La forma general, que llegó del front el 1 sep 2026 con un caso propio —la puerta
`check:no-guardado` contaba un docblock de `FilaQueSeVaAEscribir` como si fuera un
`return` vivo, y por eso su rojo pedía bajar la lista al número equivocado—:
**una explicación de por qué algo ya no está, escrita con las palabras de la cosa
que ya no está, es indistinguible de la cosa para cualquier contador que mire
texto.** No es razón para dejar de explicarlo, que es lo mejor que tiene este
repositorio; es razón para que **el primer sitio donde mirar cuando el número
sale raro siga siendo el detector**, y para que quien cuente aquí cuente
`DB::select(` y no la cadena suelta.

*Y un rojo que señala el sitio equivocado es peor que ninguno: manda a arreglar
lo que no está roto y deja lo que sí.*

## §7 · El rango del mes

`desde` = el lunes en o antes de (día 1 − 7 días).
`hasta` = el domingo en o antes de (último día + 7 días).

Septiembre de 2026 → **2026-08-24 … 2026-10-04**, que es el ejemplo del contrato.
Da semanas completas y cubre tanto una rejilla que empieza en lunes como una que
empieza en domingo. Comprobado por la coordinación del front sobre **132 meses
(2024–2034) contra las dos rejillas, cero fallos**, y por
`CalendarioMesTest::test_el_rango_cubre_las_dos_rejillas_en_once_anios`.

**El front lee `desde`/`hasta` de la respuesta y no recalcula la regla.** Es lo
que permite ensanchar el margen algún día sin desplegar el front.

> La prosa del encargo —«una semana antes y otra después»— **no da esas fechas**
> (daría el 25 de agosto y el 7 de octubre). Las dos fechas del ejemplo eran una
> ilustración sin regla detrás; ésta es la regla que las reproduce, y la
> coordinación la probó antes de aprobarla.

## §8 · El rango se pregunta por SOLAPE, no por `start`

`c.start < findelrango AND COALESCE(c.end, c.start) >= desde`. Con un filtro
sobre `start` a secas, un evento que empieza el 25 de agosto y acaba el 30 de
septiembre —una semana cultural, un periodo— **no saldría en septiembre en
absoluto**, y el front no tendría con qué pintar la barra que lo cruza.

## §9 · Decisiones tomadas aquí, que alguien puede querer revisar

1. **29 de febrero → 28 en los años no bisiestos.** En SQL
   (`STR_TO_DATE`) sale **NULL**: el cumpleaños desaparecería tres de cada cuatro
   años **sin que nada fallara**. Por eso la proyección se hace en PHP. Medido
   por la coordinación: **cero** alumnos y **cero** profesores nacidos un 29 de
   febrero en esta base, o sea que es preventivo.
2. **`matriculas` sin filtro de estado — DECIDIDO POR JOSETH el 1 sep 2026, y la
   implementación se queda como está.** Su respuesta, literal:

   > *«Todos, pero sólo con matrículas del año presente, no los retirados el año
   > pasado.»*

   O sea: **no se filtra por `estado`**. Los retirados **de este año** siguen
   saliendo, igual que salen hoy con el botón —medido contra el docker de
   trabajo, año 8: **377 `MATR`, 116 `RETI`, 1 `ASIS`**, casi uno de cada
   cuatro—, y lo que tenía que quedar fuera son los de **años anteriores**.

   > ### Y lo que cumple esa decisión es el `JOIN` por `year_id`. NO SE TOCA.
   >
   > `INNER JOIN grupos g ON … AND g.year_id = ?` con el año **del token** es
   > **la única pieza** que hace lo que Joseth pidió. No es un detalle de la
   > consulta: es la condición entera.
   >
   > Quien lo «optimice» —quitarlo, o cambiarlo por un `IN (años)` para que el
   > calendario enseñe más— **devuelve los retirados de cursos anteriores**, y no
   > lo canta nada: no hay error, no hay test de estado que se ponga rojo, sólo
   > aparecen nombres de gente que se fue hace tres años. Es el mismo `year_id`
   > en las dos consultas de `cumplesDelRango()`, la de alumnos y la de
   > profesores.

   **Efecto que hay que saber antes de que alguien lo reporte como fallo:** en un
   año lectivo recién creado y **con la matrícula todavía sin hacer, el
   calendario sale casi vacío de cumpleaños**. El año 9 tiene 37 matriculados. Es
   correcto y es exactamente lo que se pidió —el año del token manda—, pero un
   rector que abra enero de un año nuevo va a ver un mes en blanco.
3. **`GROUP BY` en las dos consultas de cumpleaños.** `matriculas` **no tiene
   clave única sobre (alumno, año)** —la §9.5 del 19—, así que un alumno con dos
   matrículas daría el mismo cumpleaños dos veces, con la **misma `clave`**, y el
   `track` del front rompe con claves repetidas.
4. **Lo que un cliente no manda, no se toca.** `descripcion`,
   `recordatorio_minutos` y los destinatarios sólo entran en el `UPDATE` si el
   cuerpo trae su clave, y `solo_profes` sale del cuerpo cuando no hay
   `destinatarios`. Sin eso, **la primera edición desde la aplicación vieja
   borraría el cuerpo y el reparto** —esos clientes no mandan campos que no
   conocen— y un evento interno suyo nacería público.

## §10 · Al desplegar

Los tres pasos van en **una tanda y un despliegue por colegio**, no tres.

1. `git pull` → **`migrate --force`** → comprobar. Sin la tabla
   `calendario_destinatarios`, `PUT calendario/mes` contesta **500**: un colegio
   con el `pull` hecho y el `migrate` sin correr **está caído** en la pantalla
   nueva.
2. **`ALTER TABLE calendario` bloquea la escritura mientras dura.** La tabla es
   pequeña —645 filas en el seed de tests— pero **el tamaño real en los quince no
   se ha medido**, y la versión de MySQL de esas cuentas de cPanel tampoco se
   conoce. Es una cifra que hay que mirar el día del despliegue, no suponer.
3. **El front no publica hasta DESPLEGADO**, no fusionado.
4. **`calendario/this-year` tiene que seguir contestando lo mismo** después de
   migrar. Es la comprobación que cierra la §6, y se hace mirando la respuesta en
   un colegio, no releyendo el código.

## §11 · Lo que NO se ha hecho

- **Paso 5:** borrar las 507 filas de cumpleaños. Va **después** de que la
  pantalla nueva funcione.
- **`putSincronizarCumples()` sigue viva y enrutada.** La quita el front de la
  pantalla primero. **Mientras esté enrutada, alguien puede pulsarla y el
  `DELETE` sigue ahí** — el riesgo no baja porque los cumpleaños ya no se lean de
  la tabla, porque `this-year` sí los lee.
- **Paso 8:** Firebase por grupo y por personal, y `notificaciones:recordatorios`
  cada minuto. `recordatorio_enviado_at` existe para eso y **no la lee nadie
  todavía**; por eso tampoco lleva índice propio.
