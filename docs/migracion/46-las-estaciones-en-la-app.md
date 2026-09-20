# Las estaciones de matrícula, atendidas desde la app

> ## ✅ CONSTRUIDO EL 20 SEP 2026 — Y SON **NUEVE** RUTAS, NO OCHO
>
> Rama `feat/las-estaciones-en-la-app`, árbol `.worktrees/est`, base
> `simonbolivar_testing_est`. **Router en 634 contado con `route:list --json` en
> `.worktrees/est`. SIN FUNDIR: hay que recontarlas en el ÁRBOL PRINCIPAL el día que
> entren** — que es la frase que lleva salvando este número las últimas cinco veces, y la
> única razón de que no envejezca a mentira.
>
> **Lo que este documento pedía y lo que salió, renglón por renglón:**
>
> | | |
> |---|---|
> | Las ocho rutas de la §3 | **las ocho, más una novena**: ver abajo |
> | `requisitos_alumno + aspirante_id` | **NO entra, y por medición nueva**: ver abajo |
> | `requisitos_alumno + motivo_devolucion` | entra |
> | `notas_estacion` | entra |
> | `envios_estacion` | **tabla que este documento no previó**: sin ella la §3.4 promete algo que no pasa |
> | Mueve tres instantáneas y **no** la cuarta | **confirmado midiendo**: `estaciones: 9 de 9`, y `familias-que-nunca-entran-en-el-candado.json` no se toca |
> | `EXCEPCIONES_DE_FAMILIA` para `POST …/nota` | **NO hace falta**: ver abajo |
> | `FamiliasQueNuncaEntranTest` sigue en 26 | confirmado, el test pasa sin tocarlo |
>
> ### 1 · La NOVENA ruta, y por qué el contrato de ocho creaba dos columnas muertas
>
> `PUT estaciones/nota/{id}/resuelta`.
>
> La §3.3 de aquí abajo tiene una tabla de permisos cuya última fila es *«dar por resuelta
> una pendiente»*, y `estaciones.md` §2.10 describe **el botón que lo hace**, con el texto
> que enseña cuando está apagado. **Ninguna de las ocho rutas escribía `resuelta_por` ni
> `resuelta_at`.**
>
> O sea que el contrato, tal cual, creaba **dos columnas que no escribe nadie nunca y un
> permiso que no gobierna nada**. Es `profesores.tono` por sexta vez en un mes — y esta
> vez **dentro del documento que lo cita como error**, dos secciones más abajo.
>
> *Es la §1.4 otra vez: un hueco que el documento no vio, encontrado al construirlo y no
> al revisarlo.* Se cuenta y se dice: **el plan pasa de ocho rutas a nueve.**
>
> ### 2 · `aspirante_id` NO entra, y el motivo no es el alcance: es que no hay nombre
>
> El contrato escribe `alumno_id|aspirante_id` en las ocho. Un aspirante sería una fila de
> `ordenes_inscripcion` en modo `nuevos`, y **esa fila no tiene nombre**: sus columnas son
> `codigo, year_id, year_campana, lote_id, modo, alumno_id, grupo_id, grado_id, cierra,
> valor, vendida_por, vendida_at, estado, matricula_id, codigo_anterior` — ni nombres, ni
> apellidos, ni documento.
>
> **Una cola de aspirantes serían renglones en blanco.** Quien captura esos datos es el
> portal de la familia, que es la fase 2, y el [44](44-el-dia-de-matriculas.md) §6 ya lo
> dice: *«nada de portal público, aspirantes, pagos ni firma»*.
>
> Y hay un segundo motivo, del esquema: `requisitos_alumno.alumno_id` es **NOT NULL con
> clave ajena**. Anularla en MariaDB 10.5 **no es una columna nueva**: es un `ALTER` que
> reescribe la tabla. *El camino queda abierto y es barato — una columna y una rama— el
> día que exista quien llene el hueco.*
>
> Lo que sí hace `GET estaciones/codigo/{codigo}` con un papel sin dueño es contestar
> **409 con lo que hay que hacer**, y no 404: «ese código no existe» sería falso y mandaría
> a quien atiende a buscar un problema que no tiene.
>
> ### 3 · `envios_estacion`: la tabla que la §4 no previó
>
> La §3.4 promete dos cosas a la vez —el salteado **no deja marca en el paso** y el intento
> **sí queda registrado**— y la §4 sólo dio sitio para una. Meter el intento en
> `notas_estacion` lo contaría en el globo, que es justo lo que la §3.4 dice que no hay que
> hacer; no meterlo en ningún sitio deja a `enviar-a` sin escribir nada.
>
> **La tanda crece en una tabla respecto a lo autorizado, y se dice.** Son seis columnas
> sobre una tabla que nace vacía.
>
> ### 4 · La huella tiene TRES cifras y este documento pedía dos — lo encontró un test
>
> La §3.1 hereda del [34](34-la-huella-de-sincronizacion.md) que hacen falta `(n,
> ultimo_cambio)`. **Hace falta una tercera, `notas`, y el motivo es el reloj:**
> `timestamp` tiene precisión de **segundo**.
>
> Una nota escrita en el mismo segundo en que se cerró el paso anterior **no mueve el
> `MAX`** —los dos sellos son la misma cadena— y tampoco mueve `n`, porque una nota no
> cambia quién espera. Con dos cifras esa nota es **invisible hasta el siguiente cambio de
> la cola, y si no hay ninguno, invisible para siempre**.
>
> No es un artefacto del test que lo destapó: en un patio, cerrar un paso y que el de al
> lado escriba una nota en el mismo segundo es media mañana de un sábado. *Es la misma
> medicina que el 34 recetó para los borrados: cuando el reloj no puede, cuenta.*
>
> ### 5 · El candado que este documento anunciaba NO habla, y eso es la decisión funcionando
>
> La §4 avisaba de que `AutorizacionTest` delataría `POST estaciones/{nro}/nota` como «una
> ruta sola entre sus hermanas», porque las otras siete tendrían el rol de la estación
> dentro y ella no. **Con la respuesta de Joseth —cierra cualquiera del personal— las nueve
> llevan `auth.personal` y nada dentro salvo la de resolver**, así que la cohorte es
> uniforme y no hay excepción que declarar. Comprobado corriendo el candado, no supuesto.
>
> ### 6 · Quién lee el texto de una nota reservada — DECISIÓN DEL CÓDIGO, no de aquí
>
> La §3.3 dice «cualquiera del personal, **salvo las reservadas**» y **no dice quién sí**.
> Al pie de la letra no la leería ni quien la escribió, que no puede ser lo que se quería.
> Lo que entra es el conjunto más pequeño que hace la regla coherente: **quien la escribió
> y quien puede darla por resuelta**. Queda aquí como decisión abierta, no resuelta en
> silencio.
>
> ### 7 · EL HUECO DEL PERMISO, MEDIDO: dos superusuarios se quedan fuera
>
> La §3.3 dice «`Admin` (1), `Secretario` (12) o `Rector` (10)» y **se implementa tal
> cual**. Pero midiendo para escribirlo salieron dos cosas que este documento no podía
> saber:
>
> ```
> simonbolivar (desarrollo)   12 roles, `Secretario` es el 12
> la base de tests del repo   11 roles, `Secretario` NO EXISTE
>
> superusuarios vivos                12
> con el rol `Admin`                 10
> superusuario SIN el rol `Admin`     2      <- HOY NO PUEDEN RESOLVER
> con el rol `Admin` sin superusuario 0
> ```
>
> Lo primero se resuelve solo: se pregunta **por nombre de rol** (`Role::hasRole`) y no por
> id, así que un colegio donde el 12 sea otra cosa no da permiso a quien no debe.
>
> **Lo segundo es una decisión de Joseth y está abierta.** `Admin` es un rol; el
> administrador de verdad de este sistema es `users.is_superuser`, y no son el mismo
> conjunto. Se implementó **lo que él dijo** —los tres roles— y no se ensanchó por cuenta
> propia, que sería *«crear un rol regala permisos que nadie pidió»* al revés. Pero en este
> colegio son **dos personas que verán el botón apagado**, y la respuesta es una línea.
>
> ### 8 · Lo que el §2 SÍ cerró y lo que sigue abierto
>
> La cola **no se apoya en `estado`** —se apoya en `cerrado_at`, que es inmune al
> desacuerdo de mayúsculas que ya existe hoy— y se cerró la trampa que la §5 dejó dicha:
> `postAlumno` escribía `cerrado_at` con `COALESCE`, así que **reabrir un paso no la
> limpiaba** y la cola lo veía cerrado. Esa línea entra, con su test y con su control en
> rojo.
>
> **Lo que NO se hizo, y se dice:** migrar el vocabulario de `estado` en los dieciséis
> colegios. Sigue pendiente y sigue siendo un trabajo. Lo que sí se paga hoy es que **la
> ruta nueva rechaza con 422** lo que no esté en `cumple|observado|devuelto` —no tiene
> ningún llamante desplegado, así que puede—, mientras `postAlumno` sigue aceptando lo que
> le manden las tres pantallas vivas.


**Todo este documento es propuesta.** Escrito el **20 sep 2026** a partir del plan que ya
existe en el front —`myvc_front/INVESTIGACION-MATRICULAS.md` (el embudo, las plataformas y el
modelo de datos) y `myvc_front/PANTALLAS-MATRICULA.md` (las quince pantallas)— y de una
pregunta que aquel plan dejó sin contestar: **las estaciones del día de matrículas están
diseñadas para `app2`, o sea para la web, y quien atiende una estación es un docente de pie en
un aula con una fila delante.**

El diseño de las doce pantallas de teléfono, con sus porqués, vive en
[`myvc_flutter/docs/estaciones.md`](../../../myvc_flutter/docs/estaciones.md).
**Esto es el contrato que necesitan y lo que cuesta.**
Maqueta navegable: https://claude.ai/artifact/3fixY3xaQsjGT2V4LAWPbE

> ## LEÍDO AL FUNDIR (20 sep 2026) — ERA EL 44, Y UNA DE SUS PREGUNTAS YA ESTABA CONTESTADA
>
> Este documento se escribió sobre `fe74442` y cerró a las **17:29 UTC**. Diez minutos después,
> a las **17:39**, entró en `main` la fusión del **día de matrículas**, que estrenó su propio
> **44** ([44-el-dia-de-matriculas.md](44-el-dia-de-matriculas.md)) y una migración. Ninguna de
> las dos sesiones podía ver a la otra. Se corrige mirando `main`, igual que la colisión
> gemela de esa misma mañana: **este documento pasa a 46**.
>
> **Lo que eso cambia de lo escrito abajo, dicho y no borrado:**
>
> | Lo que dice abajo | Lo que `main` ya tiene |
> |---|---|
> | §5.1 *«¿estación con rol o con persona?»* — **«la que bloquea»** | **Contestada por Joseth el 20 sep**: ninguna de las dos. *«Cualquiera del personal puede cerrar, pero queda con su nombre y su hora.»* **`rol_id` se descartó con motivo escrito** (44 §2) |
> | §4 la tanda: `+ cerrado_por, cerrado_at` y `bloquea` | **Ya desplegadas.** Migración `2026_09_20_300000`, y `postAlumno` las escribe con `COALESCE` |
> | §4 la tanda: `+ estacion_nro` | **Descartada con motivo**: el número impreso **es `requisitos_matricula.orden`**, que ya existe |
> | §4 *«con las 615 que este árbol cuenta hoy → 623»* | `main` **ya está en 623** por otra vía. El número se recuenta al fundir, que es la regla de `CLAUDE.md` |
> | §3.2 *«las escrituras piden el rol de esa estación»* | **No hay rol.** El permiso que Joseth decidió es `auth.personal` + **firma visible**. Ver abajo |
>
> **Y esto muerde el diseño, que es lo que importa antes de construir.** `estaciones.md` §2.9
> —*«ver todo, cerrar sólo lo tuyo»*— y la §3.2 de aquí ponían el candado en el servidor: 403 a
> quien no sea de esa estación. Con la respuesta de Joseth **ese 403 no existe**: cierra
> cualquiera del personal. Lo que queda protegiendo el paso es lo que este mismo diseño ya
> traía —**la firma con nombre y hora**, el **deshacer de ocho segundos** y el **motivo escrito
> que lee la familia**—, y eso **no es poca cosa**: es exactamente lo que Joseth pidió.
>
> Queda escrito sin re-litigar: la §3.2 se redactó sin esta respuesta delante. **Lo que sí hay
> que decidir antes de las rutas** es si «cualquiera del personal» aplica también a `enviar-a`
> y a **dar por resuelta** una nota pendiente (§3.3), donde este documento pedía el rol de la
> estación — y ahí el rol **no existe como concepto** en el esquema.

---

## 1. Lo medido (20 sep 2026, sobre `main` en el árbol principal)

- **La lista de chequeo ya está a medio hacer**, y es la mejor noticia. En
  `database/schema/mysql-schema.sql`:

  ```
  requisitos_matricula   year_id, orden, requisito, descripcion, editable_por_profe_id, updated_by
  requisitos_alumno      alumno_id, requisito_id, estado DEFAULT 'Falta', descripcion, updated_by
  ```

  Catálogo por año + instancia por alumno + un responsable + un estado. Lo que le falta para
  ser una estación son cuatro columnas, y están listadas en `PANTALLAS-MATRICULA.md` §4.

- **Seis rutas** en `Matriculas/RequisitosController.php`, todas `auth.personal`.

- **La búsqueda de personas existe**: `PUT buscar/por-nombre` y `PUT buscar/por-apellido`,
  `auth.personal`. La pantalla de buscar no necesita ruta nueva.

- **El código del formulario y su QR existen**, con diez rutas escritas y probadas el 19 sep
  ([41](41-el-formulario-de-inscripcion.md)). La estación lee **ese** código: acuñar otro
  sería un segundo papel para la misma familia.

- **La app no puede recibir un push hoy.** `myvc_flutter/pubspec.yaml` tiene `firebase_core` y
  `firebase_analytics` y **no tiene `firebase_messaging`**. El lado del servidor sí está
  desplegado desde el 25 ago (`98e6311`), y **su disparo va cada quince minutos**: para avisar
  de notas nuevas es correcto, para una fila en un patio es no avisar.

---

## 2. El agujero que hay que tapar ANTES de escribir ninguna ruta

**`requisitos_alumno.estado` es un `varchar(255)` sin vocabulario cerrado, y `postAlumno`
escribe literalmente lo que venga en el cuerpo.** El defecto de la columna es `'Falta'` y lo
demás lo decide cada llamante; hay tres pantallas escribiendo ahí (`app2/persona-matriculas`,
`PersonaCtrl` de la vieja y la de prematrículas).

Toda la cola de una estación es la pregunta *«¿está cerrado el paso anterior?»*. **Esa
pregunta no se puede construir encima de una columna cuyo conjunto de valores no está
fijado**: el día que una pantalla vieja escriba `cumple` en minúscula, la persona **desaparece
de la fila de la estación siguiente** y nadie se entera — ni ella, que está esperando de pie.

Es exactamente la forma del fallo que esta misma tabla ya tuvo una vez: el `UPDATE` que
escribía `estado=NULL` cuando el cuerpo no lo mandaba, arreglado el 1 sep y contado en el
docblock de `postAlumno`. Aquél borraba en silencio y devolvía `'Actualizado'`.

**Lo que hay que hacer, y va primero:** fijar los estados, migrar lo escrito, y **rechazar** lo
que no esté en la lista en vez de guardarlo. Propuesta de conjunto —cuatro, no más—:

```
Falta       nadie lo ha tocado (el defecto de hoy, se conserva)
Cumple      cerrado
Observado   cerrado, con un texto que viaja al siguiente
Devuelto    no pasa, y `motivo_devolucion` NO puede estar vacío
```

---

## 3. El contrato — ocho rutas

```
GET  estaciones                       (auth.personal)
  -> { campana:{year_campana, abierta}, estaciones:[ {nro, nombre, donde, rol, bloquea,
       puedo_atender, esperando} ], mi_estacion:nro|null }

GET  estaciones/{nro}/cola            (auth.personal + rol de la estación)
  -> { nro, nombre, al_dia_at, cola:[ {alumno_id|aspirante_id, nombres, apellidos, grupo,
       llego_at, avisos:[…], devuelto_antes:bool,
       notas_total, notas_pendientes} ] }

GET  estaciones/huella                (auth.personal)
  -> { por_estacion: { "1":{n, ultimo_cambio}, "2":{…} } }        ~300 bytes

GET  estaciones/alumno/{id}           (auth.personal)
GET  estaciones/codigo/{codigo}       (auth.personal)
  -> { persona:{…}, acudiente:{…}|null, codigo, pasos:[ {nro, nombre, estado, cerrado_por,
       cerrado_at, motivo, obligatorio, bloquea, mio:bool, requisitos:[…],
       notas:{total, pendientes, reservadas},
       notas_detalle:[ {id, texto|null, reservada, pendiente, de, cuando} ] } ],
       puede_atenderlo:bool, si_no:{devolver_a_nro, donde} }

PUT  estaciones/{nro}/marcar          (auth.personal + rol de la estación)
  { alumno_id|aspirante_id, resultado: cumple|observado|devuelto, motivo?, requisitos:[…] }

PUT  estaciones/{nro}/enviar-a/{destino}   (auth.personal + rol de la estación)
  { alumno_id|aspirante_id }     registra el intento, NO escribe el paso

POST estaciones/{nro}/nota            (auth.personal, CUALQUIERA del personal)
  { alumno_id|aspirante_id, texto, pendiente:bool, reservada:bool }
```

### 3.1 · Por qué la huella es una ruta y no un `ETag`

Es el caso de [34](34-la-huella-de-sincronizacion.md), otra vez y por el mismo motivo: la
pantalla de la estación tiene que preguntar *«¿ha llegado alguien?»* cada veinte segundos
durante ocho horas, y **ninguna de las lecturas de arriba manda `ETag` ni `Last-Modified`**, así
que hoy preguntar barato no se puede. La huella devuelve `(cuántos, último cambio)` por
estación; **las dos hacen falta**, porque `updated_at` no ve que alguien salió de la cola y el
conteo sí.

Y la regla dura de aquel documento se hereda entera: **se calcula sobre lo que devuelve la
cola, no sobre la tabla.** Una huella de `requisitos_alumno` se movería con lo que esta
estación no ve, y podría no moverse con lo que sí.

Con diez estaciones abiertas una jornada son unas 14.400 peticiones de 300 bytes. Pedir la
cola entera cada veinte segundos serían las mismas peticiones multiplicadas por cien.

### 3.2 · El permiso se parte en dos, como en las colillas

**Las lecturas las hace cualquiera del personal.** Quien atiende Documentos es un docente, y
cuando una mamá pregunta *«¿mi hija en qué va?»* tiene que poder mirarlo sin pedirle el
teléfono a secretaría. Es el mismo argumento que dejó las dos lecturas del formulario abiertas
a todo el personal ([41](41-el-formulario-de-inscripcion.md) §12).

**Las escrituras piden el rol de esa estación**, dentro del método, y contestan **403** al
resto. `auth.personal` deja pasar a cualquier docente, y cerrar un paso decide si una familia
sigue en la fila o se va a su casa. Es la forma de `plantilla-notas/`: guard en la ruta,
permiso dentro.

> **Y aquí hay una decisión que no puede tomar el backend solo**: la tabla de hoy tiene
> `editable_por_profe_id`, que es **una persona**, y el plan del front dice **rol**. El
> docente que atiende Documentos el martes no es el del miércoles. Mientras eso sea una
> persona, la estación tiene un dueño y no un turno. Va a la §5.

### 3.3 · El globo de notas, y por qué la regla del 34 ya lo tenía resuelto

La app pinta **un globo con un número sobre el círculo de cualquier estación** —la 1, la 4 o
la 5, haya llegado o no—, porque el tesorero puede dejar escrito el lunes que esa familia
tiene un saldo pendiente y la estación 5 se atiende el sábado. Entre esas dos fechas el dato
existe y no lo ve nadie.

**Y aquí hay una trampa que se ve sola si la huella está bien hecha, y no se ve nunca si está
mal.** Una nota escrita en la **estación 5** tiene que hacer aparecer el globo en la pantalla
del que atiende la **estación 2**, porque es él quien tiene delante a esa familia. Si la
huella se calculara *«por estación anotada»*, la huella de la 2 no se movería y el globo
saldría cuando alguien recargase a mano — o sea, cuando ya no sirve.

No hace falta ninguna regla nueva: es **exactamente** lo que dice el
[34](34-la-huella-de-sincronizacion.md) —*«se calcula sobre lo que devuelve la lectura, no
sobre la tabla»*—. La cola de la 2 devuelve `notas_total` de **todas** las estaciones de esa
persona, así que la huella de la 2 se calcula sobre eso y una nota en la 5 la mueve. La regla
estaba escrita hace trece días para otro módulo y contesta ésta sin tocarla; por eso se cita
en vez de inventar otra.

**Tabla nueva, y es pequeña:**

```
notas_estacion   requisito_id (la estación), alumno_id|aspirante_id, texto,
                 pendiente tinyint, reservada tinyint, resuelta_por, resuelta_at,
                 escrita_por, created_at
```

**Los cuatro permisos, que no son el mismo:**

| | Quién |
|---|---|
| **Leer que existe** (el número del globo) | cualquiera del personal, **también las reservadas** |
| **Leer el texto** | cualquiera del personal, **salvo las reservadas** |
| **Escribir** | cualquiera del personal, en **cualquier** estación |
| **Dar por resuelta una pendiente** | **quien la escribió**, o `Admin` (1), `Secretario` (12) o `Rector` (10) |

> **Que el conteo incluya las reservadas es una decisión, no un descuido.** El plan del front
> ya lo pedía con esas palabras —*«si hay algo que tesorería deba mirar, le llega la señal sin
> el texto»* (`PANTALLAS-MATRICULA.md`, pantalla 11)—, y el motivo es que **esconder que una
> nota existe es peor que esconder su contenido**: quien ve el globo y no puede abrirlo sabe
> a quién preguntarle; quien no ve nada, no pregunta.
>
> **Y la de resolver es la que más se va a querer aflojar.** Si cualquiera pudiera marcar una
> pendiente como resuelta, la nota del tesorero la apagaría el primero a quien le estorbe para
> cerrar su paso — que es justo el escenario contra el que se escribió.
>
> **DECIDIDO por Joseth el 20 sep 2026, y no es lo que este documento pedía.** Pedía «el rol de
> esa estación», y ese rol **no existe**: desde que cerrar un paso lo puede hacer cualquiera
> del personal, la estación no tiene dueño. La regla que entra es **quien la escribió, o
> `Admin`, `Secretario` o `Rector`** —los tres están en la tabla `roles` con los ids 1, 12 y
> 10—, así que se comprueba sin acuñar un concepto nuevo que cada colegio tendría que
> configurar antes de que el módulo sirviera.
>
> **Protege lo mismo y se sostiene mejor**: el que atiende la estación 5 no es ni quien
> escribió la nota ni ninguno de esos tres, así que el aviso del tesorero sigue sin poder
> apagarlo quien tenga prisa. Y la puerta de escape es nominal —Secretaría o Rectoría— en vez
> de anónima.

**Una nota NO es `motivo_devolucion`**, y esto hay que hacerlo cumplir en el esquema, no en la
pantalla: el motivo pertenece al paso, **lo lee la familia** y va en su columna; la nota es
entre el personal y la familia no la ve nunca. El día que compartan sitio, un comentario
interno acaba en el celular de una mamá.

### 3.4 · `enviar-a` no escribe el paso, y eso es lo que la hace útil

El que llega a la 4 sin haber pasado por la 3 **no deja marca en el paso 4**: se registra el
intento. Así el tablero del rector puede decir *«en la 4 se presentan doce sin pasar por la 3»*
—el cartel está mal puesto, o la 3 está tapada— **sin ensuciar el recorrido de esa familia**
con un paso que no ocurrió.

---

## 4. Lo que mueve, contado y no supuesto

**Ocho rutas nuevas**, todas con `auth.personal` **declarado en la ruta** y una familia nueva
(`estaciones/`). Con las **615** que este árbol cuenta hoy, el router quedaría en **623** —y
ese número **se cuenta con `route:list --json` en el árbol principal después de fundir**, no se
suma aquí: es la regla de `CLAUDE.md` y ya se ha roto tres veces.

**Mueve tres instantáneas:**

| | por qué |
|---|---|
| `rutas.json` | ocho rutas nuevas |
| `guards-por-ruta.json` | las ocho llevan guard |
| `guard-por-familia.json` | familia nueva: `estaciones: 8 de 8` |

**Y NO mueve la cuarta, que es lo que hay que comprobar y no suponer.**
`familias-que-nunca-entran-en-el-candado.json` lista las familias con **menos de dos hermanas
con guard**, y ésta entra con ocho de ocho: el candado de consistencia por familia **sí la
mira**. Por lo mismo tampoco se mueve
`FamiliasQueNuncaEntranTest::test_cuantas_escrituras_viven_donde_el_candado_no_llega`, que
sigue en **26**: las tres escrituras nuevas (`marcar`, `enviar-a` y `nota`) viven en una
familia que el candado **sí** recorre.

**Tampoco se mueve `RutasPreLoginTest::TOTAL_PUBLICAS`**: ninguna de las ocho es pública, y
no puede serlo. Quien atiende una estación tiene cuenta —es personal del colegio— a
diferencia de la familia del aspirante, que es lo que obligó a abrir las tres del formulario.

> **Y hay un candado del repositorio que va a hablar de `POST estaciones/{nro}/nota`.**
> `AutorizacionTest` compara las hermanas de cada cohorte de método, y aquí la familia tiene
> **siete rutas cuyo permiso real se decide dentro** —el rol de la estación— **y una que a
> propósito no**: escribir una nota lo puede hacer cualquiera del personal. Eso se declara en
> `EXCEPCIONES_DE_FAMILIA` **con el motivo escrito**, nunca regenerando la instantánea y
> pasando: es el mismo renglón que la colilla del formulario, y por el mismo motivo —la
> excepción es la decisión, no el ruido—.

**Y una tanda de migración**, que va antes que las rutas:

```
requisitos_matricula  + tipo, rol_id, obligatorio, bloquea, estacion_nro, dias_limite
requisitos_alumno     + aspirante_id, cerrado_por, cerrado_at, motivo_devolucion
                      + el vocabulario cerrado de `estado` (§2)
notas_estacion        TABLA NUEVA (§3.3)
```

Son **columnas anulables sobre dos tablas pequeñas** más una tabla nueva, así que en MariaDB
10.5 entran al instante — la misma medida que dejó escrita la tanda de `notas`. Lo que **no** es gratis es el
§2: migrar lo escrito exige mirar los dieciséis colegios uno a uno.

---

## 5. ~~Lo que espera una decisión de Joseth~~ — LAS CINCO, CONTESTADAS EL 20 SEP 2026

1. ~~**¿Estación con rol o con persona?**~~ — **ninguna de las dos**: cierra cualquiera del
   personal y queda con su nombre y su hora. `rol_id` descartado
   ([44](44-el-dia-de-matriculas.md) §2), y ya desplegado.
2. ~~**¿Se le avisa al acudiente en cada estación, o solo cuando lo devuelven?**~~ — **en cada
   estación.** Con el nombre del menor dentro y **sin el motivo**: `notificaciones.md` permite
   lo primero y prohíbe lo segundo, y el motivo se lee abriendo la app.
3. ~~**¿El push inmediato entra ahora o después?**~~ — **ahora**, y por tanto **no** por la
   tanda de los quince minutos: hay que publicar en el momento, y el lado Flutter de
   `notificaciones.md` hay que empezarlo (`firebase_messaging` no está en `pubspec.yaml`).
   **La cola sondeada con huella no se retira**: un push perdido no puede dejar a una familia
   invisible.
4. ~~**¿La nota de una estación se le avisa a alguien?**~~ — **no: espera ahí.** El globo la
   enseña a cualquiera que abra la ficha, y nadie puede resolverla hasta que la familia llegue.
   **Esto quita trabajo del contrato**: `POST estaciones/{nro}/nota` no dispara nada.
5. **Las ocho rutas, con el precio delante** — **lo único que sigue esperando.** Una familia
   nueva entra **entera en un commit**; el precio está en la §4 y no ha cambiado con lo de
   arriba, salvo que la 4 lo abarata: la nota no necesita cola de avisos.

### Y una medición que acorta el §2, hecha el 20 sep al fundir

El §2 pedía cerrar el vocabulario de `estado` **antes que las rutas**. Sigue haciendo falta,
pero ya no está solo: **el desacuerdo de mayúsculas existe hoy** —`AlumnosController:899`
inserta `"falta"` en minúscula y el defecto de la tabla es `'Falta'`—, y sin embargo
`cerrado_at` **es inmune**, porque `postAlumno` compara con `mb_strtolower`. Una cola apoyada
en `cerrado_at IS NOT NULL` no se rompe con una pantalla vieja.

**La trampa, para que no se prometa sin mirarla:** `cerrado_at` se escribe con `COALESCE`, o
sea **una sola vez**. Reabrir un paso no la limpia, así que la cola lo vería cerrado. Limpiarla
al reabrir es una línea en `postAlumno`; migrar el vocabulario de dieciséis colegios es un
trabajo. **Las dos son de aquí**, pero dejaron de estar empatadas.
