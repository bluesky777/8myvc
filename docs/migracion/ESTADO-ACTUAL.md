# Dónde está la aguja ahora mismo

> **Léeme el primero.** Este documento existe para que una sesión nueva pueda
> continuar **sin que Joseth tenga que dar contexto**. Es corto a propósito: dice
> qué se está haciendo, qué acaba de terminar, qué es lo siguiente y qué espera una
> decisión suya. El detalle de cada cosa vive en su documento y está enlazado.
>
> **Se actualiza en el mismo commit que el trabajo**, no en uno aparte al final:
> un commit aparte es el que no se hace cuando la sesión se corta.

**Última actualización: 24 ago 2026** · `main`, fundido

---

## La migración planeada está terminada

Las fases 0–4 del [plan](00-plan-migracion.md) están cerradas, la 5 recortada y la
6 es continua por diseño. **Laravel 13 sobre PHP 8.4**, con red de seguridad y
autenticación real. Hoy: **1.284 tests, 8.640 aserciones, 535/542 rutas con la
respuesta comprobada, larastan nivel 7 `[OK]`.** Al empezar había **0 tests** y
`route:list` estaba roto.

Lo que sigue **no son fases pendientes de la migración**: es el trabajo que se
decidió hacer después.

---

## En curso: las definitivas — **fase 3 terminada**, la 2 esperando un dato tuyo

**El plan entero está en [10-definitivas.md](10-definitivas.md).** Resumen de por
qué se hace: seis sitios escriben en `notas_finales` con cinco criterios distintos
de qué borrar, ninguno transaccional, sobre una tabla sin clave única. De ahí
salen los tres síntomas que se reportaban por separado —definitivas que
desaparecen, duplicadas, y notas puestas que no aparecen— y son el mismo problema.

### Lo hecho

| | |
|---|---|
| **Fase 0** — medir | **hecha**, y la herramienta **corregida el 24 ago** (medía de menos: ver abajo). `tools/salud-de-las-definitivas.php`, sólo SELECT. Medido en un colegio: **11.988 definitivas que deberían existir y no existen**, 718 que discrepan teniendo notas detrás, 1 duplicado |
| **Fase 1** — recalculador único | **escrita y probada.** `App\Services\DefinitivasDeAsignatura`, 14 tests de ida y vuelta. **Cableada sólo en el boletín** |

### La fase 3 — hecha el 24 ago 2026

Los siete disparadores cableados al recalculador único, y con ellos los seis
escritores de la §0 reducidos a uno:

| Disparador | Estado |
|---|---|
| Abrir un boletín | **hecho** |
| Editar una nota (`putUpdate`) y **borrarla** (`deleteDestroy`) | **hecho** — era la petición de origen |
| `putSubunidad`, la nota rápida del horario | **hecho**, y de paso arreglada la §3.1: no guardaba nada **y era una inyección** |
| Unidades y subunidades (crear, editar, borrar) | **hecho** — las cuatro llamadas al calculador viejo, y **ya no dependen de que el cliente mande `asignatura_id`** |
| Copiar un periodo | **hecho** — traía la estructura y no avisaba a nadie |
| Cada carga de /notas (`putDetailed`) | **hecho** — era un DELETE+INSERT por alumno en cada carga; ahora pregunta primero |
| Crear la subunidad y sus notas en la misma transacción | **hecho** — §5.1 cerrada: nacía sola y la ventana podía durar días desde Flutter |

**La fase 3 está completa, y con ella la fase 2 queda desbloqueada.** Auditados
otra vez los `INSERT INTO notas_finales`: **ninguno alcanzable queda sin guarda.**

| Sitio | Estado |
|---|---|
| El servicio, `NotaFinal:309`, `DefinitivasPeriodosController:146` | protegidos desde antes |
| `DefinitivasPeriodosController::putUpdate` (rama sin `nf_id`) | **cerrado el 24 ago** — decide por existencia, en transacción y con `FOR UPDATE` |
| `NotaFinal::alumnos_grupo_nota_final` (4) | **cerrados el 24 ago** — sustituidos por el servicio |
| `Alumnos/Definitivas:53,83` | **sin guarda pero inalcanzables**: uno responde 410 antes de llegar, al otro no lo llama nadie. La fase 5 borra la clase entera |

### La herramienta de la fase 0 medía de menos — arreglado el 24 ago

Antes de que ese `for` salga hacia los dieciséis colegios, había que arreglarlo:
sus bloques 1 y 2 contaban duplicados **dentro del alcance mirado** (con
`--year`, filtrando `deleted_at`, exigiendo que la subunidad siguiera viva) y **un
índice único mira la tabla entera**. `notas` usa SoftDeletes, hay **35.796 notas
colgando de subunidades borradas** sólo en esta base, y `asignaturas.grupo_id` no
tiene clave foránea. Los tres caminos dejaban fuera filas que el `ALTER TABLE` sí
encuentra: un colegio podía leer *«se puede poner el índice sin limpiar nada»* y
que fallara igual.

Ahora los dos bloques dan **dos números** —el de la tabla entera, que es la
condición de entrada, y el del alcance, que dice a cuántas definitivas cambia
limpiar— y avisan cuando difieren. En esta base coinciden (1 y 2); que coincidan
es suerte de esta base, no del esquema. Está detallado en el
[10](10-definitivas.md), en la fase 0.

**No cambia el orden ni desbloquea nada**: la fase 2 sigue esperando los dieciséis
números. Lo que cambia es que ahora contestan la pregunta correcta a la primera.

### Y del backend, lo que salió de la fase 4 — 24 ago

- **`putUpdate` devuelve la definitiva recalculada** en su propia respuesta (clave
  `definitiva`). Ahorra **una petición HTTP por nota tecleada**, no milisegundos
  de base. Campo añadido: la nota sigue con sus mismas claves.
- **¿Pesa recalcular en siete sitios? No** — ~4 ms por nota tecleada, contra los
  ~40–80 ms que cuesta sólo resolver quién pregunta. Medido con
  `tools/coste-del-recalculo.php`. Y **un 3× que resultó ser la caché** se
  escribió, se midió y se revirtió: está en el [02](02-plan-rendimiento.md) para
  que no se reintente.
- **Tres 500 menos, los tres encontrados por el front verificando en el
  navegador**: `perfiles/username` reventaba para **todo acudiente** (1.000 de
  1.067 cuentas) y tapaba una fuga del directorio entero; `Grupo::datos()` daba
  500 por **diecisiete rutas** con cualquier grupo borrado —el grupo 1 lleva en la
  papelera desde 2018—; y falta `num_periodo` contestaba «no tienes permiso».
  **Ninguna suite nuestra los habría encontrado: todos nuestros tests piden ids
  que existen.**
- **Arreglado**: si falta `num_periodo`, `DefinitivasPeriodosController::putUpdate`
  reventaba en la guarda de permisos antes que en la del periodo, así que el
  profesor leía «no tienes permiso» cuando lo que faltaba era un campo. Ahora es
  **422 nombrando el campo**, comprobado antes de la guarda.

### Lo siguiente

1. **La fase 2**: la migración con los dos índices únicos, la limpieza de
   duplicados y el relleno de las que faltan. **Necesita antes los dieciséis
   números de la fase 0** — la herramienta está **y ya mide bien**, hay que
   correrla en el servidor, y es un `for` de una línea que está escrito en el 10.
   La limpieza de `notas` va **sobre la tabla entera**, no sobre las filas vivas.
2. **La fase 4 está HECHA** (24 ago, `myvc_front`, sesión `myvc-front-9a`): los
   cinco puntos en ocho commits sobre `fase-11/definitivas-9a`, con 415 pruebas
   —32 nuevas y **25 de ellas comprobadas en negativo**—. **Sin mezclar a la
   madre.** El punto que depende del backend (`cambiaNotaDef` sin `nf_id`) va
   **aislado en el último commit**, para que sacarlo sea un `reset --hard`: no
   entra hasta que esta tanda esté **desplegada**, no fusionada. Detalle y las
   cinco cosas que el plan daba por ciertas y no lo eran, en el
   [10](10-definitivas.md).
3. **La fase 5 —quitar los botones «Calcular definitivas per N»— no antes** de que
   las 1–4 estén **desplegadas** y la fase 0 dé cero discrepancias durante un
   periodo completo. Hoy esos botones son el parche con el que un colegio se
   arregla; quitarlos antes deja el problema y quita el parche.

### Y el orden, que se corrigió el 24 ago

**La fase 2 —los índices únicos— no puede ir antes que la 3.** Auditados los once
`INSERT INTO notas_finales`: tres están protegidos, dos son código muerto y
**seis están en pantallas vivas sin guarda**. Con el índice puesto, cada choque es
**un 500 en la pantalla de un profesor** — el peor, `putUpdate`, es el que teclea
la definitiva. Está detallado en el 10, justo antes de la fase 2.

---

## Y en paralelo: las tres cosas que pidió la app — **hechas las tres**

Joseth las autorizó el **24 ago 2026**. Vienen de
`~/DESARROLLOS/myvc_flutter/docs/backend-pendiente.md`, que lleva el contrato de
cada una y la evidencia que la justifica. **No son de la migración**: son lo que
`myvc_flutter` no puede resolver desde su lado.

| | Qué | Estado |
|---|---|---|
| 1 | `PUT notas/lote` — pasar una columna en una petición | **hecho el 24 ago**, 12 tests |
| 2 | `GET disciplina/mis-fichas/{alumno_id?}` — que el alumno y el acudiente vean lo suyo | **hecho el 24 ago**, 10 tests |
| 3 | Notificaciones: endpoint de temas con HMAC, `notificaciones:enviar` y la entrada de cron | **hecho el 24 ago**, 19 tests — falta que Joseth cree el proyecto de Firebase |

### 1 — `PUT notas/lote`, hecho

Una columna de treinta alumnos eran treinta peticiones. Ahora es una, con
`auth.personal`, el permiso comprobado **una vez y antes de escribir**, las
escrituras en **una transacción** y **un recálculo por par (asignatura,
periodo)** al final y fuera de ella. Devuelve `{guardadas, fallidas, definitivas}`
— las fallidas con su motivo, para que la app reintente sólo ésas.

**Y la justificación que traía escrita era la equivocada, lo cual importa más que
el endpoint.** El contrato decía que lo caro era la agregación del recálculo. No
lo es: la sesión de al lado lo midió el mismo día y lo dejó en el
[02](02-plan-rendimiento.md) — **~1,7 ms**, y el *3×* que parecía haber al
estrecharla a un alumno **era la caché**. Lo que sí ahorra es otra cosa y es más
grande:

- **treinta peticiones son treinta veces el coste fijo de resolver quién
  pregunta**, ~40–80 ms (02 §4). Un orden de magnitud por encima del recálculo, y
  sin depender de ninguna caché;
- y **treinta transacciones independientes** dejan, cuando una columna se guarda a
  medias, definitivas calculadas sobre estados intermedios. Un lote entra entero o
  no entra. Eso no es velocidad, es la misma familia de fallos que la fase 3.

**De paso, una trampa que estaba esperando a cualquiera**, no sólo al lote:
`User::aplicarBanderasDelPeriodo` decide con `count($filas) === count($ids)` para
que un periodo borrado cuente como cerrado. Con la lista **sin deduplicar**,
treinta notas del mismo periodo son treinta ids contra una fila y **deniega la
petición entera** con un *«no tienes permiso»* que manda a buscar el fallo donde
no está. Ahora **deduplica ella**, en vez de exigírselo a cada llamante.

> **La app no puede llamar a `notas/lote` hasta que esté desplegado en los
> dieciséis**, no cuando esté fusionado: `app/` es copia por colegio y
> `myvc_flutter` es **una sola app para todos**, así que no hay forma de
> escalonar el cliente. En el colegio que faltara sería un 404 gastado antes de
> caer al método viejo. Está en [DESPLIEGUE.md](../DESPLIEGUE.md) §5.b.

### 2 — `GET disciplina/mis-fichas/{alumno_id?}`, hecho

**El alumno y su familia ya pueden ver su situación disciplinaria.** No entraban
porque los cuatro controladores que tocan `dis_procesos` llevan `auth.personal`
en **todas** sus rutas, y ése aborta con 403 a `Alumno` y `Acudiente`. No era una
decisión de privacidad: era que nadie había escrito la puerta de lectura.

La guarda **ya existía** y hace exactamente esto: `boletin.propio:sin-paz-y-salvo`.
Sin id significa «lo mío» y lo resuelve el controlador —el middleware, al no ver
alumno concreto, deja pasar—, igual que `notas/alumno`. Un acudiente recibe 400 si
no dice de cuál de sus acudidos habla.

**El paz y salvo no aplica**, y es la misma decisión de `notas/alumno` y
`matriculas/prematricular`: retener el boletín de quien debe es una cosa, y
esconderle a una familia la situación disciplinaria de su hijo es otra, y esa
nadie la ha pedido. Tiene su test, con la deuda puesta a mano.

Devuelve `{alumno, config, ordinales}`. **`alumno` con la forma exacta de un
elemento de `PUT disciplina/alumnos`**, y eso no es comodidad: la app reutiliza
`AlumnoDisciplinaModel` y `FichaDisciplinaScreen` tal cual, en modo lectura, y esa
pantalla ya está escrita. **El test que lo sostiene compara las dos respuestas
clave a clave**, no contra una lista escrita a mano — una lista se queda vieja el
día que alguien añada una columna a `Grupo::alumnos`, y el test seguiría verde con
la promesa rota. Sin `grupos` ni `descripciones_typeahead`: eso es del editor.

Dos cosas que salieron por el camino:

- **Las dos consultas de este repo que devuelven «un alumno para disciplina» no
  traen lo mismo.** `Grupo::alumnos` —la del editor— lleva siete columnas que
  `fichaDelAlumno` —la de las tres escrituras— no. Reusar la segunda habría sido
  más corto y habría roto el contrato en silencio.
- **Aquí no se crea la configuración del año si falta.** Sus dos hermanas
  —`grupos/con-disciplina` y `ordinales/ordinales`— insertan la fila. Ésta la abre
  una familia, y una lectura que escribe es la forma más silenciosa de que un
  endpoint de sólo lectura deje de serlo. Sin fila va `config: null` y el cliente
  usa sus valores por defecto.

### 3 — Las notificaciones: endpoint, comando y cron

Las tres piezas escritas. Lo que falta **no es código**: es que Joseth cree el
proyecto de Firebase (ver abajo).

**El endpoint, `GET notificaciones/temas`, es la pieza de seguridad de todo el
diseño.** Firebase reparte por *temas* y **el teléfono se apunta él mismo**, así
que el nombre del tema es en la práctica la única puerta: si se llamara
`alumno_345`, cualquiera con la app se apuntaría al `alumno_346` y recibiría los
avisos de un menor que no es suyo. Por eso el nombre **no se calcula en el
teléfono**: se deriva con `HMAC-SHA256(alumno_id, secreto)` y el teléfono lo
recibe ya hecho, sólo los suyos —los propios si es alumno, los de sus acudidos si
es acudiente, ninguno si es personal—.

El secreto **es `APP_KEY` por defecto, y es una decisión**: hace falta uno
distinto por colegio y que no salga del servidor, y `APP_KEY` ya es las dos
cosas. Así esto funciona sin editar dieciséis `.env`.

**El comando, `notificaciones:enviar`**, saca de `bitacoras`, `ausencias`,
`dis_procesos` y `publicaciones` lo ocurrido desde la última pasada, **agrupa** y
publica. Tres decisiones que valen más que el código:

- **Agrupar es lo que lo hace viable y de paso lo hace mejor.** Un docente que
  pasa una columna genera treinta cambios en dos minutos: sin agrupar son treinta
  avisos y el acudiente apaga las notificaciones para siempre. Agrupado por
  alumno y asignatura es uno.
- **La primera pasada no manda nada**: pone la marca y se va. Sin eso, encender
  el push en un colegio le manda a cada familia un aviso por cada nota del año.
- **La marca se guarda después de publicar, no antes.** Si el proceso se cae en
  medio, la pasada siguiente repite; guardándola antes, lo perdería. Un aviso
  repetido es una molestia, uno perdido es la función sin cumplir.

Y **ningún aviso lleva el dato dentro**: «hay 4 notas nuevas en Matemáticas»,
nunca «sacó 45». Se ve en la pantalla bloqueada, con gente al lado. Tiene su
test, con un valor inconfundible metido a propósito.

**El cron no es el que decía el plan de la app, y es mejor.** Aquel proponía una
entrada nueva con un bucle por los dieciséis directorios. No hace falta: este
proyecto ya decidió **un solo cron por colegio** —`schedule:run` cada minuto— y lo
que corre se decide en `app/Console/Kernel.php`, que **viaja con el `app/`**. Así
que la tercera pieza son tres líneas ahí, `everyFifteenMinutes()` con
`withoutOverlapping()`, y **cero visitas a paneles de cPanel**. Ver
[17-cron.md](17-cron.md).

> **Lo que hace falta de Joseth para que esto llegue a un teléfono**, y hasta
> entonces el comando corre, no manda nada y lo dice:
>
> 1. **Un proyecto de Firebase** y una cuenta de servicio (un JSON).
> 2. Ese JSON **en `storage/` de cada colegio** —no en el repositorio: `app/` es
>    copia por colegio pero el repositorio es común, así que meterlo dentro sería
>    publicar la credencial de push de los dieciséis— y `FCM_PROYECTO` en su
>    `.env`.
> 3. Para iOS, una clave de APNs, que pide cuenta de desarrollador de Apple de
>    pago. Si no la hay, esto sale primero en Android.
>
> Se puede probar antes de todo eso con `php artisan notificaciones:enviar --seco`,
> que dice qué mandaría sin mandar nada y sin mover la marca.

---

## Lo que espera una decisión de Joseth

Están en [09-pendientes.md](09-pendientes.md), agrupadas. Las que quedan sin
contestar:

- **La hora mal escrita** en filas ya guardadas — y ojo, **se midió y el dato no
  distingue** una fila mal escrita de una normal.
- **Los interruptores `para_*`** — hay que contestarlos con los tres delante.
- **Quién del personal puede qué** — cinco lotes preguntan variantes.
- **Los dieciséis números de la fase 0** de definitivas: la herramienta está, hay
  que correrla en el servidor colegio por colegio (`for` de una línea en el 10).

---

## Lo que está fusionado y NO desplegado

**Fusionado no es desplegado**, y `app/` es copia por colegio.
[DESPLIEGUE.md](../DESPLIEGUE.md) **se vació y se rehízo el 24 ago**: llevaba dos
tandas apiladas —la del 22 y la del 23—, cada una diciendo «si la anterior no llegó
a desplegarse», y para saber qué se nota había que leer las dos y cruzarlas. Ahora
es **una sola tanda con todo lo pendiente dentro**, del 22 al 24, para desplegar de
una vez cuando Joseth lo decida.

Medido sobre el rango entero, no sumando tanda a tanda: **0 migraciones, 0 cambios
de esquema, 0 de dependencias**, **1 fichero nuevo en `config/`** sin ningún valor
obligatorio, y **539 rutas antes y 542 después** — las tres nuevas son las de la
app y ninguna quita ni cambia nada.

**Nada que publicar hoy en ningún cliente, y una condición nueva para mañana:**
la app de Flutter es **una sola para los dieciséis**, así que no puede empezar a
usar los tres endpoints nuevos hasta que estén **desplegados en todos**, no
fusionados. Está en [DESPLIEGUE.md](../DESPLIEGUE.md) §5.b.

Dentro está **el boletín que hoy devuelve 500 a una familia**, **la ficha de alumno
que no guarda nunca** y **la fase 3 de las definitivas** — o sea, lo que se pidió:
que la definitiva se actualice al cambiar la nota.

Y en `myvc_front` queda apuntado, sin hacer, el arreglo de **las cuatro altas de
la planilla de notas que no mandan `fecha_hora`** (`MIGRATION.md` §4b.3b).
