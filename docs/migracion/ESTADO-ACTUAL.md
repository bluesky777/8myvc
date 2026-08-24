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
autenticación real. Hoy: **542/542 rutas con la respuesta comprobada — el 100% —,
98/98 controladores, larastan nivel 7 `[OK]`, pint PASS.**

> **Y con qué suite se midió, que aquí decide el número:** las 542 salen de la
> **suite entera** (`medicion/lote-y-cobertura`, 1.362 tests, 9.223 aserciones,
> 848 s). Con `--testsuite=Contrato` se ven **541**, porque `GET /` sólo la toca el
> stub de `laravel new` y ahí cae siempre del lado de las no comprobadas. **El
> número citable es el de la suite entera**, y no es lo mismo que el de Contrato.
>
> **Los dos barridos siguen sin contar como comprobar** —`AutenticacionTest` toca
> 523 rutas en una ejecución y `RutasPreLoginTest` 530— y eso es lo que hace que el
> 100% signifique algo.
>
> El total de tests **varía por rama esta noche**: hay cuatro sin fundir. `7b` cerró
> con 1.374 en la suya y `ad` con 1.362 en la suya; **no se suman**, y el de `main`
> se cuenta el día que se fundan.

> Ese `[OK]` estuvo **en rojo** un rato la noche del 24: `ProfesoresController:473`
> llegó a `main` dentro de un commit que arrastró trabajo de cinco sesiones, **sin
> la pasada de larastan de su autor** ([05 §178](05-codigo-muerto-y-roto.md)).
> Arreglado en `955125a`, y **verde comprobado con la base contada antes de medir**
> —92 tablas, 2.351 usuarios—, que es el paso que la [§176.3](05-codigo-muerto-y-roto.md)
> convirtió en obligatorio. Al empezar había **0 tests** y
`route:list` estaba roto.

Lo que sigue **no son fases pendientes de la migración**: es el trabajo que se
decidió hacer después.

---

## LO QUE ESPERA TU RESPUESTA — la lista de la mañana del 25, por consecuencia

**Ordenada por lo que pasa si no se contesta**, no por antigüedad. El detalle de cada
una está en el 05 o en el 09; aquí sólo lo que decide.

### Papel oficial y cuentas — lo primero

| | Qué | Si no se contesta |
|---|---|---|
| **1** | **Abrir el certificado de periodo quema un número consecutivo, y la lectura+escritura del contador no está en transacción.** Dos personas abriéndolo a la vez → **dos certificados con el mismo número** ([05 §195](05-codigo-muerto-y-roto.md)) | Sigue pasando. **El disparador es abrir la pantalla**, y en secretaría en cierre de periodo dos a la vez **es el caso normal**. Un número saltado se justifica; **uno repetido, no**. *(Se pidió el test que falle hoy: si sale rojo, el arreglo entra con red. Si no se puede escribir, la decisión es tuya.)* |
| **2** | **`PUT bolfinales/cambiar-contador-certificados` fija el consecutivo a lo que venga en el cuerpo, sin validación, con `auth.personal`** | **Cualquiera de los 51 profesores puede fijar el número de certificados del colegio** |
| **3** | **Publicar lo terminado.** `7b` y `f8` cerraron sin empujar, y hacen bien | Cinco lotes cerrados esta noche siguen sin salir. **Fusionado no es desplegado** |
| **4** | **La firma del profesor: dos endpoints, permisos distintos, y sólo uno comprueba de quién es la imagen** ([05 §168](05-codigo-muerto-y-roto.md), §182) | La mina sigue puesta. **Y los dos criterios no se contienen**, así que *«cuál gana»* **no se puede contestar eligiendo el más restrictivo** |

### Disciplina, certificados e interruptores ([09 §15](09-pendientes.md))

| | Qué | Si no se contesta |
|---|---|---|
| **5** | **`dis_procesos.firma_alumno` / `firma_acudiente`**: módulo vivo, **nadie las lee** | **Hoy el sistema no puede contestar si un proceso disciplinario se firmó** — el dato que hace falta meses después, cuando alguien reclama. **¿Abandonada o sin terminar?** |
| **6** | **Dos interruptores de `config_certificados` que se marcan y no se aplican** | Un documento que se entrega firmado **sale distinto de lo que el colegio pidió, y quien lo marcó no tiene forma de saberlo** |
| **7** | **Seis tablas `df_*` sin una sola referencia** | Nada, hasta que alguien las borre: **es una migración destructiva en dieciséis producciones** |

### Servidor — cuatro `for` que ahora son uno

| | Qué | Si no se contesta |
|---|---|---|
| **8** | **`php tools/fase-cero-de-los-dieciseis.php --csv $(cat colegios.txt) > fase0.csv`** — junta los cuatro `for` pendientes en **una visita y un formato** | **La fase 2 de las definitivas sigue bloqueada**, que es lo que pediste desde el principio. Y de paso: **el esquema congelado se da por igual en los dieciséis y nunca se ha comprobado** |

### Frentes nuevos que nadie ha abierto porque no los pediste

| | Qué |
|---|---|
| **9** | **BOL-1**: el boletín final tarda **24–31 s** y se cae bajo carga. `7b` se negó a empezarlo porque es un frente que no pediste, **y tenía razón**. Lo que hace falta son **cuatro peticiones**, no un experimento con copia |
| **10** | **Los seis `DB::select` que escriben** ([05 §191](05-codigo-muerto-y-roto.md)). Una palabra por sitio, **ningún cambio de conducta hoy** — y **ningún test rojo delante**, dos ficheros cogidos, y uno corre en cada petición |
| **11** | **Las dos del boletín independiente** ([19](19-boletin-independiente.md) §2): quién marca a un alumno, y qué puesto lleva su boletín |
| **12** | **Unificar los cuatro informes de puestos con los ocho de impresión**: les cambia la conducta a cuatro que hoy no preguntan nada |

### Y tres números viejos en documentos que no toco sin ti

| | |
|---|---|
| **13** | **`CLAUDE.md` dice que las excepciones públicas son quince y son once**, y **`RutasPreLoginTest` no es un inventario**: enumera once y **no comprueba que ninguna otra sea pública** |
| **14** | **Una decisión mía, revertible en un commit**: congelar ocho `SELECT *` para que la migración del boletín independiente **no mueva ninguna respuesta**. La alternativa —regenerar instantáneas— **era tuya**, porque obliga a avisar al front y a Flutter |
| **15** | **La §12 de arriba y la §14** del 09 siguen esperando desde el 24 |

---

## La noche del 24 al 25: catorce sesiones, tres repositorios, dos coordinaciones

**Coordinó `8myvc-34` en `8myvc` y `myvc-front-98` en el front**, con una sola
interfaz entre las dos y ninguna mandando lotes a las sesiones de la otra. El
reparto vive fuera de git, en `8myvc-cola/noche-2026-08-24/`. **Lo hecho:**

| Lote | Qué quedó |
|---|---|
| **AUD-1 + ESC** (`7b`) | el `Reloj` único con centinela y su vuelta (`desdeTexto`), y **la escala validada en el servidor** — Joseth lo pidió esa noche. Cambia respuestas: `notas/update` puede dar **422** donde daba 200 |
| **AUD-3** (`39`) | la tabla `auditoria` y `App\Services\Auditoria`, append-only, **con la primera regla puesta en la forma de la clase** — no tiene dónde recibir «cuántas filas salieron» |
| **BI-1** (`9e`) | el esqueleto del boletín independiente: cuatro migraciones y **el inventario de las 146 lecturas de `unidades`/`subunidades`** (88 bien por construcción, 57 a acotar, 1 sin saber) |
| **MED-1** (`ad`) | **cobertura al 100%: 542/542 rutas**; `notas/lote` cronometrado (**3,8×–5,9×**, **717→220 consultas**) y el **429 de la §1 confirmado en la petición 121 de 135** |
| **EXP-1 + PROFES-1** (`d2`) | dos exportaciones **vivas y rotas** desde el salto a Laravel Excel 3.x, y `profesores/update`, que **renombraba y degradaba la cuenta al corregir un teléfono** |

**Trece secciones nuevas en el [05](05-codigo-muerto-y-roto.md), §168 a §180.** Las
dos que más lejos llegan: **86 escrituras crudas** que ningún detector de esta fase
mira —buscan asignaciones de Eloquent y una `UPDATE … SET` no tiene ninguna— y
**115 rutas no-`GET` que no escriben nada**, que a la auditoría le importa porque
**lo que clasifique «qué escribe» por el método HTTP mete esas 115 en el cajón
equivocado**.

### Lo que esta noche enseñó, y no es una anécdota

**Siete instrumentos mintieron, y ninguno mirando el resultado**: un `PDO` con la
contraseña inventada, un `cd` que dejó el shell en el árbol de otros, dos suites de
la misma sesión escribiendo en el mismo fichero, una base a medio construir, una
caché de larastan a medio llenar, `construir-bd-test.sh` sin `-w`, y un `ng serve`
sirviendo un árbol **borrado** y contestando **200**. La forma general:

> **El instrumento correcto sobre el objeto equivocado.** No se ve mirando el
> resultado, porque el resultado es correcto. Sólo se ve preguntando **sobre qué**
> se midió.

Y las dos reglas hermanas, que explican por qué **las siete tenían a alguien que ya
lo sabía**: **una medición no es un guardián** —dice que el índice sirve, no que siga
ahí— y **un aviso no es un control**: *«saberla no basta, hay que tener el paso
puesto»*, dicho por quien se comió la trampa **después de avisar dos veces esa misma
noche de esa forma exacta**. **Cinco de las siete se cierran con un paso en el
procedimiento, no con más conocimiento**, y por eso las reglas que quedaron caben en
una línea: contar tablas y usuarios antes de correr, `ps` **dentro** del contenedor,
`git rev-parse` antes del commit, y **nombrar los ficheros uno a uno**.

**Tres conclusiones se retiraron, las tres por quien las trajo**, y las tres más
baratas que el trabajo que habrían mandado hacer al sitio equivocado. La más caras
de las tres: *«tres peticiones colgadas tumban el backend entero»* —refutada por el
reloj de nginx— y **los porcentajes de hueco de definitivas, que eran míos**: mi
denominador daba por hecho que toda combinación debe existir, y **de 1.196
«ausentes» unos 400 eran de alumnos que se habían ido**.

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

## Lo siguiente que se pidió: la auditoría — **plan escrito, sin código**

**[18-auditoria.md](18-auditoria.md).** Salió de tres peticiones que resultaron ser
el mismo problema: un historial fiable de notas modificadas, unas horas que no
salgan raras, y una pantalla de «qué hizo este usuario en este ingreso».

Lo medido el 24 ago, que es lo que decide el plan:

- **10 `INSERT INTO bitacoras` contra 256 escrituras de datos** en 56 controladores.
  Cinco de los diez son de seguridad. **Asistencia, comportamiento, disciplina,
  situaciones y frases no graban nada** — la pantalla pedida no se puede construir
  hoy porque no hay filas que mostrar.
- **Las horas raras son tres causas a la vez**: 118 sitios escriben en Bogotá y
  **17 en UTC** (`config/app.php` dice `UTC`) **sobre la misma columna**; las columnas son
  `TIMESTAMP` y nadie fija la zona de la conexión (`@@session.time_zone = SYSTEM`),
  así que **la lectura depende del hosting de cada colegio**; y conviven
  `TIMESTAMP` con `DATETIME` en la misma tabla.
- **`historial_id` es una adivinanza**: se resuelve con `order by id desc limit 1`
  sobre `historiales`, o sea **el último login del usuario, no la sesión que hizo
  el cambio**. Con el móvil y el navegador abiertos, la pantalla mostraría una
  lista falsa sin ningún error visible. El token y el ingreso no se conocen.
- **La auditoría se puede borrar**: `DELETE bitacoras/destroy/{id}` va con
  `auth.personal`.
- Y `PUT historiales/sesion` **ya intenta ser esa pantalla**, pero sólo trae notas
  y con `INNER JOIN`, así que una nota borrada desaparece del historial.

El plan: tabla `auditoria` nueva (`bitacoras` se congela, no se migra sobre
dieciséis producciones), un solo escritor `App\Services\Auditoria`, append-only,
`DATETIME(3)` con un `Reloj` único y su test, y **la sesión atada al token** antes
de nada. Seis fases; las dos primeras —el reloj y la sesión— **no dependen de
ninguna decisión y ya mejoran la bitácora vieja**.

**Las tres decisiones que lo bloqueaban están contestadas** (24 ago): `ocurrido_en`
en hora de Bogotá con `DATETIME`; `config/app.php` **se queda en UTC** y el `Reloj`
es la única fuente de lo que se guarda; y la auditoría se ve con un permiso nuevo
`can_view_auditoria`, **sembrado sólo a rector y coordinación**, con la regla
añadida de que **cada quien ve siempre lo suyo** sin permiso. Eso obliga a cerrar
en la misma fase las seis rutas viejas que hoy van con `auth.personal` — dejarlas
abiertas convertiría el permiso nuevo en decoración.

**La fase 0 ya tiene herramienta**: `tools/salud-de-la-bitacora.php` (sólo
`SELECT`, diez bloques, `--csv` para juntar los dieciséis). Corrida sobre el seed
da **18 de 3.229 ingresos con algo que enseñar** (99,4% vacíos), **12 filas en UTC
contra 74 en Bogotá** en la misma columna, y **67,6% de las atribuciones a un
ingreso sin poder comprobar**. Sus bloques 3 y 4 se cruzan solos —clasifican por
caminos que no comparten supuesto— y **coincidieron: 12 y 12**, así que el
desfase de cinco horas está confirmado y no supuesto.

Su lista de escritores es a mano y por eso lleva centinela:
`CentinelaDeLosEscritoresDeBitacoraTest` fija que sigan siendo diez, en los mismos
ficheros, **y que los tres de UTC no cambien de reloj** — lo que ningún conteo
vería. Cazó un error en su primera ejecución: se habían publicado 9 escritores y
son 10.

**Lo siguiente es correrla en los dieciséis**, como el `for` de la fase 0 de
definitivas, y con esos números decidir si la historia vieja se reinterpreta o se
da por perdida.

### Lo que la noche del 24 añadió al plan — vino de las otras sesiones

El documento pasó de 740 a ~880 líneas hablando con `myvc-front-10`, `8myvc-dd`,
`8myvc-d2` y (vía el front) `myvc-flutter-fe`. **Los cuatro hallazgos eran ciertos
y los cuatro apuntaban un poco al lado**; se verificaron todos contra el código
antes de aceptarlos, y dos corrigieron el esquema:

| Vino de | Qué era | Qué cambió |
|---|---|---|
| `front` | el plan **no mencionaba `myvc_front` ni una vez** y las fases 5–6 tocaban 6 pantallas vivas | **§4.6 nueva**; las rutas nuevas son **aditivas**; la retirada se va a una **fase 7** |
| `front`/`flutter` | los `intento_login` los pinta `mis-sesiones` | destapó que **`actor_user_id NOT NULL` era un error**: un login fallido no tiene actor (hoy `created_by = 0`) |
| `dd` ([§13](09-pendientes.md)) | `DB::update` devuelve filas **afectadas**, y son 0 si el valor no cambia | **primera regla del escritor**: la escritura ocurrió porque no hubo excepción, no por filas. Y un reguardado sin cambio **sí se registra** |
| `d2` | el `order by id desc limit 1` está en **9 sitios**, no en 2 | la §2 reescrita — y **son 7 + 2**: dos son middlewares que anotan un intento **rechazado**. Mismo arreglo, **fila distinta**: `accion` gana un quinto valor, `denegado` |

Y **la fase 7 pasó a estar sin fecha, que no es lo mismo que lejana**:
`myvc_flutter` **no comprueba versión mínima en ninguna parte**, así que un
teléfono viejo llama indefinidamente y nadie se entera. Mientras eso no exista,
**retirar cualquier endpoint depende de la buena voluntad de dieciséis colegios** —
le pasa igual a la Fase 5 del [00](00-plan-migracion.md), no sólo a esto.

### Y tres cosas que NO son de la auditoría y salieron de camino

Ninguna se buscaba y ninguna estaba en la pregunta original. **No se arreglan en
el 18** — están escritas en su §4.5.1 con la medición, y esperan decisión:

1. **Se pueden teclear decimales en las cuatro pantallas de notas y nada los
   valida** — `notas.nota` es `int` y MySQL trunca en silencio. Y por eso no lo ha
   reportado nadie en veinte años: el aviso verde **repite el número tecleado, no
   el guardado** (`planilla-notas.ts:253`). El profesor lee «Cambiada: 85,5» y hay
   85.
2. **La escala de este colegio es de 0 a 50**, no de 0 a 100 como se suponía, y
   `porc_inicial`/`porc_final` son `int`: el sistema de calificación entero está
   construido sobre enteros. **Es configurable por colegio y por año**, así que si
   en alguno fuera de 1 a 5 la pregunta pasa a ser cuántos años llevan
   perdiéndolos. Se mide con el `for` de la fase 0.
3. **Nada en el backend rechaza una nota por pasarse de la escala.** Diez sitios
   comparan contra `porc_final` y **los diez son para pintar la banda**; ninguno
   aborta. El único guardián es el cliente, y de tres pantallas hermanas **dos
   guardan y una no**.

---

## Y lo último que pidieron los colegios: el boletín independiente — **plan escrito, sin código**

**[19-boletin-independiente.md](19-boletin-independiente.md).** Un alumno se
puede marcar como PIAR; los colegios quieren marcarlo además como **«requiere
boletín independiente»**: sale de las planillas normales, tiene una pantalla
propia donde su docente le escribe **sus** unidades y subunidades del periodo,
y en el boletín aparece como todos pero con las suyas.

Lo medido el 24 ago, que es lo que decide el diseño:

- **74 consultas leen `unidades` y 70 leen `subunidades`** en `app/`, repartidas
  en 24 ficheros, y **todas dan por hecho que una unidad es de la asignatura y de
  nadie más**. El diseño es `unidades.alumno_id` (NULL = del grupo), así que en
  cuanto exista, **cada una de esas 74 está corregida o equivocada** — y una
  consulta sin alcance no falla: devuelve las filas de otro.
- **`notas` y `notas_finales` no se tocan.** La nota del independiente es una
  nota normal colgada de una subunidad normal, así que `notas/update`,
  `notas/lote`, la bitácora y el recalculador único **funcionan sin cambio**, y
  el alumno sale en puestos, finales, actas y certificados sin escribir nada.
- **Los tres boletines se cubren en dos funciones**: `Unidad::deAsignaturaCalculada`
  y `Subunidad::deUnidadCalculada`.
- **`Nota::puestoAlumno` está copiado en ocho sitios**, así que el interruptor de
  los puestos se lee en un servicio y preguntan los ocho.

**Cuatro decisiones tomadas** (todas las asignaturas · la marca en `matriculas`,
por año · el interruptor de puestos en `years` · copiar estructura y preguntar
por las notas) y **la regla que lo hace desplegable**: con las migraciones
puestas y nadie marcado, **los 1.344 tests pasan sin regenerar un solo
snapshot**. Tres rutas nuevas, de 542 a 545.

**El canal con el front es `myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, sección
C**, no este repo: lo pidió Joseth el 24 ago porque **el front no lee `8myvc` por
su cuenta** y este plan estuvo un día escrito sin que nadie lo viera. Toda
decisión que cambie un cuerpo, un nombre de campo o una ruta se escribe **ahí
además de aquí**.

**Comunicado a `myvc_front` el 24 ago** para hacerlo conjuntamente, en dos vueltas
—`myvc-front-12` y `myvc-front-10`, ésta con el inventario de pantallas—. Sus
siete avisos y preguntas están dentro del plan y contestados en su buzón; **uno de
ellos destapó un fallo vivo que no era el que preguntaban** (§9.5: la ficha lee de
una matrícula y escribe en otra cuando hay dos del mismo año) —el más útil, que **un vacío tiene que decir por
qué está vacío**, arregló el punto más flojo (§6.1)—. El front no publica hasta
que esto esté **desplegado** en los dieciséis, y espera además el aviso de que la
tanda de DESPLIEGUE.md salga: tiene cuatro cosas congeladas detrás.

---

## Y la planilla de notas por lotes — **plan escrito, y el endpoint ya estaba**

**[20-pantalla-de-notas.md](20-pantalla-de-notas.md).** Lo pidió Joseth el 24 ago:
que el docente teclee varias notas seguidas sin esperar a cada guardado, que cada
celda diga por sí misma si ya viajó, y que la nota rápida deje de mandar una
petición por nota.

**La noticia que abarata el plan entero: el endpoint del backend ya existe.**
`PUT notas/lote` se escribió el 24 ago *para `myvc_flutter`* y sirve igual para la
planilla web sin tocar una línea — recibe ids de nota sueltos, así que una
columna, una fila y un puñado de celdas recién tecleadas son **el mismo
endpoint**. Casi todo el trabajo es de `myvc_front`.

Lo que el plan deja escrito y no era evidente:

- **El error que sale hoy al pulsar la nota rápida es, con toda probabilidad, un
  `429`**: `throttle:api` son 120/min por usuario y tres columnas de 45 son 135.
  El arreglo es el lote, **no subir el límite**.
- **Un docente pulsando una columna ocupa hasta seis `Entry Processes` a la vez**
  (el navegador abre ~6 conexiones por dominio) y las repone hasta acabar las 45.
  Ocho docentes a la vez, que es lo que pasa en cierre de periodo, son 48 de 50.
  Con lotes, un docente es **una** ranura.
- **El borde no es un borde**: es un elemento flotante **detrás del input y un
  poco más grande**, del que sólo asoma el reborde. Así el input hace de máscara
  —no hay que recortar ningún anillo—, nada queda por encima del campo y
  `box-sizing` ni entra en la conversación. Y tiene que ser así porque
  `_estado-notas.scss` **ya usa el `border-color` del input** para decir *perdida*
  (rojo), *superior* (azul) y *hover de nota rápida* (ámbar), y una nota recién
  tecleada puede ser perdida **y** estar sin guardar a la vez.
- **El truco depende de que el input sea opaco, y hoy lo es por accidente**:
  `input.input-nota` no declara `background-color` — el blanco es el valor por
  defecto del navegador. Se declara como parte del trabajo, o un tema oscuro
  forzado convierte el reborde en un relleno.
- **«El borde se queda pero la animación quieta» es una sola propiedad**:
  `animation-play-state: paused`.
- **Ya hay un temporizador puesto que hay que contar**: el input trae
  `ng-model-options` con `debounce: 1000`, así que el modelo se entera un segundo
  tarde. Con los 2 s del agrupador son **3 s** hasta el PUT, y el halo saldría un
  segundo después de teclear si el estado cuelga de `ng-change`.
- **Y una carrera que está abierta hoy**: `DefinitivasDeAsignatura::recalcular`
  decide crear o actualizar con un `SELECT … ORDER BY id LIMIT 1` **sin `FOR
  UPDATE`**, así que dos recálculos concurrentes del mismo par pueden insertar los
  dos. El flood de 45 peticiones simultáneas de hoy **ya la está ejerciendo**. El
  lote la mitiga; **lo que la cierra es la clave única de la
  [fase 2](10-definitivas.md)**, y una mitigación en uno de los cuatro clientes no
  protege a los otros tres.

**Aquí el front sí se puede escalonar**, al revés que las tres cosas de la app:
`myvc_front` es copia por colegio, así que se publica en el colegio cuyo backend
ya lo tiene.

Falta una medición y está anotada como tal: **nadie ha cronometrado `putLote`**
(tiene 13 tests y ninguna medida). La tabla de la §2 del plan dice «estimado»
hasta que exista.

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
- **Las tres primeras de la auditoría** se contestaron el 24 ago y están cerradas
  en el [18](18-auditoria.md). Quedaron abiertas **cuatro** después:
  **(a)** `/panel/bitacora`, ¿se jubila o se queda? —de ahí depende dónde cae la
  pantalla nueva en el menú del front, y es lo único que les bloquea a ellos—;
  **(b)** tras retirar `bitacoras/destroy`, ¿quién borra un intento fallido? hay
  dos botones encima; **(c)** ¿se persigue lo de los decimales? la consulta de
  escalas en los dieciséis dice si es cosmético o si un colegio lleva años
  perdiéndolos; **(d)** ¿validación de escala en el servidor? es la que cierra el
  agujero de verdad y la más cara — necesita su propia medición.
  **Ninguna de las cuatro bloquea las fases 0 a 6.**

- **[§13](09-pendientes.md) — «No guardado» con 200 cuando sí se guardó.** Salió
  de coordinar el 19 con el front. `DB::update` devuelve filas **afectadas** y
  MySQL devuelve 0 cuando el UPDATE no cambia nada: **guardar el valor que ya
  estaba contesta «No guardado» y el estado es correcto**. Medido: **4 sitios, 6
  rutas**, entre ellas las ~20 propiedades de la ficha del alumno y la rejilla de
  configuración del colegio. **Es el reverso de los «200 que mienten»** —allí el
  tipo, aquí el texto— y **no se arregla en un solo lado**: cambia el cuerpo de
  seis rutas vivas y `myvc_flutter` es una sola app para los dieciséis.

- **Las dos del boletín independiente** ([19](19-boletin-independiente.md) §2):
  **quién puede marcar a un alumno** —hoy la propiedad de matrícula la escriben
  titular y administrativo, y `nee` la escribe además el psicólogo: la propuesta
  es igualarlas— y **qué puesto lleva el boletín de un independiente** cuando el
  interruptor dice que no cuentan (la propuesta es `—`, no un puesto calculado
  sobre una lista de la que se le sacó).

### Y cuatro nuevas del 24 ago, las cuatro con la medición delante

- **[§7](09-pendientes.md) — «restaurar» contesta tres cosas distintas.** Diez
  endpoints: seis devuelven el objeto, tres `'Retaurada'` (mal escrito) y uno
  `'Restaurada'`. **Corregir sólo uno de los tres es la peor opción**: deja la
  misma operación contestando dos cadenas dentro del mismo colegio. Y su
  despliegue va **al revés**: el front delante.
- **[§8](09-pendientes.md) — el año se queda viejo mientras la sesión sigue
  abierta.** No es de acudientes: el login repara `users.periodo_id`, pero nada lo
  mueve con la sesión ya abierta. Decidir si se arregla **en general** o endpoint a
  endpoint.
- **[§9](09-pendientes.md) — el personal ve la ficha de cualquiera por su nombre
  de usuario.** Es la decisión del 21 ago funcionando; lo que nadie llegó a
  preguntarse es qué debe ver un **docente**. **Pasan 43 cuentas y sólo 10 son
  Admin**; para las otras 33 no hay pantalla que lleve ahí.
- **[§10](09-pendientes.md) — `GET api/contratos`. RECORTADO, y la decisión era
  tuya.** Entregaba el domicilio y el móvil de los dieciséis docentes a cualquier
  alumno. El §5 reservaba «qué columnas se recortan» y la tomé con la medición
  delante —los once consumidores leen id, nombre, foto y `user_id`—. **Sin
  desplegar; revertirlo es un commit.**

### Y una de las dos del 24 ago por la tarde sigue abierta — la otra se cerró

- **[§12](09-pendientes.md) — las masivas de cuentas: elegiste la C (por alcance)
  y hecha está la mitad de abajo.** `alumnos/cambiar-claves` pasa a
  `esAdministrativo`. **La mitad de arriba está parada a propósito**: bajar las
  cuatro `cambiar-usuarios/*` a `esSuperusuario` **reversaría una decisión tuya
  del 21 ago** —«puede cambiarle la contraseña/username a los alumnos y acudientes
  solamente», citada literal en `SecretarioTest`—, y la C se propuso sin ese dato
  delante. Las dos salidas que quedan están en el 09. **Nada se toca hasta que
  contestes.**

  > Falló el método, no la conclusión: el barrido miró `Autoriza`, los
  > controladores y sus docblocks, y **no miró los tests**, que es donde vivía tu
  > frase. Aquí una decisión tuya puede estar anotada en un test y no en el código
  > que la aplica.

- **[§11](09-pendientes.md) — cualquier profesor renombraba cualquier cuenta.
  ARREGLADO, no espera nada.** Está aquí sólo para que se despliegue: con
  `users.username` UNIQUE, dejaba a un superusuario fuera del sistema en una
  petición. Lo encontró la sesión de `myvc_flutter` leyendo la ruta que su pantalla
  nueva iba a consumir, y avisó en vez de cablearla.

- **[§14](09-pendientes.md) — ninguna guarda del backend mira el rol `Admin`.**
  `Autoriza::esAdministrativo` es `is_superuser || Secretario`; el `esAdmin` del
  front es `tieneAlgunRol(['admin'])`. **Se llaman casi igual, protegen las mismas
  pantallas y no son la misma condición**, y eso es anterior a todo lo de esta
  noche. En la copia local coinciden —10 y 10, ni uno suelto por ningún lado—
  **pero eso es un colegio y no lo impone el esquema**. Si en alguno hay un `Admin`
  sin `is_superuser`, hoy ya está rebotando en los **once** sitios que piden
  `esAdministrativo`. **Falta el `for` de los dieciséis**; la consulta está escrita
  en el 09.

### El relevo de la sesión de guardas de cuentas (`8myvc-d2`), 24 ago noche

Lo que dejo cerrado, lo que dejo a medias y por qué, para que no haya que
reconstruirlo:

**Commiteado en `fix/username-y-simetria-de-guardas`** (sin publicar, sin fusionar,
sin desplegar): `0e7208c` la §11 y la mitad de abajo de la §12; `8e4d089` la forma
del 422; `e7632cf` los cuatro ficheros de `7b` y `dd` que sólo estaban en el árbol.

**Lo que NO hice y no es un olvido:**

- **La mitad de arriba de la §12** —bajar las cuatro `cambiar-usuarios/*` a
  `esSuperusuario`—. Joseth eligió la C, pero la C se le propuso **sin saber que
  reversaba una decisión suya del 21 ago** que vivía citada en un test y en ningún
  otro sitio. Hay que volver a preguntársela; las dos salidas están en el 09 §12.
- **Los ocho endpoints de la pantalla de cuentas de la app.** Sin autorizar.
  `myvc-flutter-fe` avisa de que **cada uno tiene su interruptor y se encienden por
  separado**, así que se pueden autorizar sueltos y no hace falta el paquete.
- **El `for` de la §14.** Necesita servidor.

**Lo que hay que decirle al front cuando esto se despliegue**, porque no se entera
solo:

1. `PUT alumnos/cambiar-claves` **cambia de forma** —`"Cambiadas"` pasa a
   `{resultado, cambiadas}`— y `app2` la lee con `responseType: 'text'`
   (`datos/alumnos.ts:90-93`, con su prueba en `alumnos.spec.ts:122-130`). **Se
   migra el día del despliegue y no antes**: en un colegio sin desplegar sigue
   llegando texto.
2. Esa misma ruta **ya no alcanza a retirados ni a cuentas borradas**, así que la
   N que `panel-alumnos.ts:684-696` promete antes de apretar deja de coincidir con
   las que cambian. Por eso ahora devuelve el número.
3. `myvc_flutter` tiene **tres interruptores apagados** esperando el despliegue, no
   la fusión.

> **Y una advertencia de método que costó cara esta noche, escrita aquí porque es
> donde la va a leer quien releve:** llegó el aviso de que una sesión se había
> cerrado dejando trabajo sin commitear, y se leyó como *«todo lo que no está
> trackeado es huérfano»*. No lo era: dos de esos ficheros los estaban escribiendo
> sesiones vivas y uno había crecido 10 KB en veinte minutos. **Lo caro no fue
> commitearlo —eso dejó el trabajo a salvo— sino repetirlo**: el error llegó al
> front, que se lo dijo a los dos autores, y un plan que circula como huérfano lo
> re-litiga cualquiera desde cero. **Lo que una sesión te cuenta del árbol se
> comprueba en el árbol**, y costaba un `git status`.

- **Y lo que espera de la pantalla de cuentas de la app**: ocho endpoints nuevos
  que aún **no están autorizados**. El detalle, con lo que ya existe y lo que de
  verdad falta, en el 09 §12 y en
  `~/DESARROLLOS/myvc_flutter/docs/backend-pendiente.md`.

> **La copia local tiene cuatro cuentas con contraseña de prueba y once bitácoras
> borradas** — lo que se le hizo a `simonbolivar` no está en git:
> [15](15-la-noche-en-paralelo.md).

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
