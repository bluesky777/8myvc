# La bitácora: por qué no sirve, y qué la sustituye

> **Estado: plan, sin escribir código.** Las tres decisiones que lo bloqueaban
> **están contestadas** (24 ago 2026) y recogidas al final. No queda nada
> esperando: la fase 0 se puede empezar.
>
> Origen: se pidieron tres cosas —un historial fiable de notas modificadas, unas
> horas que no salgan raras, y una pantalla de «qué hizo este usuario en este
> ingreso»—. Las tres son el mismo problema y por eso van en un documento.

---

## §0. Lo que hay hoy, medido

`bitacoras` existe desde antes de la migración y nadie la ha tocado. Los números
son del **24 ago 2026**, sobre la base de tests (rebanada anonimizada de dos años
de un colegio real, ver [03](03-tests.md)) y sobre el código de `main`.

### La cobertura: 10 escrituras de bitácora contra 256 escrituras de datos

| Qué | Cuántos |
|---|---|
| Sentencias `DB::insert/update/delete/statement` en `app/` | **256** |
| Controladores que escriben algo | **56** |
| Ficheros que escriben una línea de bitácora | **8** |
| `INSERT INTO bitacoras` en todo el proyecto | **10** |

Los diez, y lo que registran de verdad:

| Sitio | `affected_element_type` |
|---|---|
| `Services/Login.php` | `intento_login` (sólo los **fallidos**) |
| `Services/Sesion.php` | `refresco_reutilizado` |
| `Middleware/ExigirPersonaPropia` | `AlumnoPideAjeno:*`, `AcudientePideAjeno:*` |
| `Middleware/ExigirBoletinPropio` | `AlumnoVerBoletin` |
| `NotasController::putUpdate` y `::putLote` | `Nota` |
| `SubunidadesController` | `Nueva subunidad` |
| `DefinitivasPeriodosController` (×2) | `NF_UPDATE`, `RF_UPDATE` |
| `YearsController` | `YEAR CONFIGURACION` |

Cinco de los diez son de **seguridad** —intentos de login, accesos denegados y
la reutilización de un refresco—, no de trabajo escolar. De trabajo escolar
quedan **cinco**: la nota (en dos sitios), la subunidad nueva, la definitiva, la
recuperación final y la configuración del año.

> **Diez y no nueve, y se deja escrito porque es el ejemplo del día.** El primer
> recuento sumó mal el listado por fichero y publicó **9**. Lo cazó el
> `CentinelaDeLosEscritoresDeBitacoraTest` en su primera ejecución, contra el
> mapa fichero→cuántos que él mismo lleva dentro. Es la regla de CLAUDE.md por la
> vía buena: **el primer sitio donde mirar cuando el número sale raro es el
> detector** — y aquí el detector que fallaba era contar a mano.

**Y eso deja fuera todo lo que se pidió ver en la pantalla.** Ninguna de estas
escribe una sola línea:

| Dominio pedido | Dónde vive | Bitácora |
|---|---|---|
| Asistencia / faltas | `ausencias`, `Tardanzas/AsistenciasController`, `AppMobile/AsistenciasAppController` | **no** |
| Comportamiento | `nota_comportamiento`, `definiciones_comportamiento`, `NotaComportamientoController`, `Disciplina/ComportamientoController` | **no** |
| Disciplina / situaciones | `dis_libro_rojo`, `dis_procesos`, `dis_ordinales`, `dis_proceso_ordinales`, `dis_acciones_restaurativas` (15 escrituras) | **no** |
| Frases de asignatura | `frases`, `frases_asignatura`, `frases_preescolar` | **no** |
| Unidades y subunidades | `UnidadesController` (3 escrituras) | **sólo crear subunidad** |
| Borrar una nota | `NotasController::deleteDestroy` | **no** |

> **La pantalla que se pide no se puede construir hoy porque no hay datos que
> mostrar.** No es que la consulta esté mal: es que las filas no se escriben. La
> primera fase de este plan no es una pantalla, es empezar a grabar.

### La proporción, que es el titular

En el seed hay **3.224 ingresos** (`historiales`) y **33 líneas de bitácora que
no son de seguridad**. Un colegio puede abrir cualquier ingreso de los 3.224 y en
el 99% de los casos la respuesta honesta es «no se sabe qué hizo».

---

## §1. Las horas raras: son tres causas superpuestas, no una

Se reportó como «salen horas extrañas». Son tres, y **cada una desplaza en una
dirección distinta**, que es justo por lo que no se arregla mirando una fila.

### §1.1 La aplicación tiene dos relojes

`config/app.php` dice `'timezone' => 'UTC'`. Y sin embargo:

| Forma | Cuántos usos en `app/` | Qué hora escribe |
|---|---|---|
| `Carbon::now('America/Bogota')` | **118** | Bogotá |
| `Carbon::now()` sin zona | **9** | UTC |
| `now()` a secas | **8** | UTC |

> Las dos filas de UTC se cuentan **por separado y sin los comentarios**, porque
> juntarlas fue el segundo error del día: la primera versión de esta tabla puso
> **9** para las dos formas —era el conteo de sólo `Carbon::now()`— y dejaba
> fuera los `now()` a secas, que son justo los dos que escriben en `bitacoras`.
> El total en código real es **17**, en siete ficheros.

**Y los dos relojes escriben en `bitacoras.created_at`.** `NotasController`,
`SubunidadesController`, `DefinitivasPeriodosController` y `YearsController`
escriben en Bogotá; `ExigirPersonaPropia:304` y `ExigirBoletinPropio:173`
escriben `now()`, o sea **UTC**. Cinco horas de diferencia dentro de la misma
columna, sin nada que diga cuál es cuál. Ordenar por `created_at` mezcla las dos
escalas y el resultado no es una línea de tiempo.

Lo mismo pasa fuera de la bitácora: `Services/Sesion.php` gestiona expiraciones
enteras en UTC y `PuntoDeControlDeImportacion` lo dice en su propia cabecera.

### §1.2 Las columnas son `TIMESTAMP`, y nadie fija la zona de la conexión

`bitacoras.created_at`, `historiales.created_at` y `notas.updated_at` son
`timestamp`. MySQL **convierte** un `TIMESTAMP` al escribirlo y al leerlo, usando
la zona de la sesión. Y `config/database.php` **no tiene `timezone`**, así que la
sesión hereda la del servidor. Medido en el contenedor:

```
@@system_time_zone = UTC      @@session.time_zone = SYSTEM
```

En cPanel eso es **lo que diga la cuenta de cada colegio**, y son dieciséis
cuentas distintas. La misma línea de bitácora escrita por el mismo código puede
leerse con una hora distinta en dos colegios, y si el hosting cambia su zona,
**todas las filas históricas se desplazan a la vez** — sin que nadie haya tocado
la base.

### §1.3 En la misma tabla conviven `TIMESTAMP` y `DATETIME`

`historiales` tiene `created_at timestamp` y `logout_at datetime`.
`ausencias` tiene `created_at timestamp` y `fecha_hora datetime`. `DATETIME` **no
convierte**. Escribir el mismo `$now` en las dos columnas y leerlas después puede
dar dos horas distintas, y la resta «cuánto duró la sesión» sale mal.

> **Las tres se arreglan con la misma regla, y sólo si es una sola:** una función
> que dé la hora, un tipo de columna que no convierta, y un test que lo fije. Va
> en la fase 1.

---

## §2. `historial_id` es una adivinanza, y la pantalla pedida se apoya justo ahí

Esta es la que rompe la pantalla aunque se grabaran todos los eventos.

Cuando `NotasController::putUpdate` anota la bitácora, resuelve el ingreso así
([NotasController.php:307](../../app/Http/Controllers/NotasController.php#L307)):

```sql
select * from historiales where user_id=? and deleted_at is null order by id desc limit 1
```

**El último ingreso de ese usuario. No el ingreso que está haciendo el cambio.**

**Y no es de ese método: está en los nueve.** Comprobado el 24 ago tras un aviso
de `myvc-front-10` (que lo traía de `8myvc-5f`/`d2`) — el `order by id desc limit
1` sobre `historiales` aparece en **nueve sitios de `app/`**, o sea en **todos**
los que escriben `historial_id`:

| Fichero | Cuántos |
|---|---|
| `NotasController` (313, 544) · `DefinitivasPeriodosController` (211, 315) | 2 + 2 |
| `ExigirPersonaPropia:291` · `ExigirBoletinPropio:160` · `SubunidadesController:59` · `YearsController:353` · `LoginController` | 1 cada uno |

Así que no hay ninguna atribución fiable en la tabla: **las hay adivinadas y las
hay NULL.**

> **Y los nueve no son nueve iguales — son siete y dos.** Lo señaló `8myvc-d2` el
> 24 ago, y es la forma de fallo que este repo tiene catalogada en CLAUDE.md: *un
> detector puede contar bien un síntoma y no estar contando la causa*. Comprobado
> leyendo los dos:
>
> - **Siete son controladores** y usan el `historial_id` para atribuir una
>   escritura: *«quién hizo esto»*.
> - **Dos son middlewares** —`ExigirPersonaPropia:291`, `ExigirBoletinPropio:160`—
>   y anotan un intento **rechazado**: *«a quién le dijimos que no»*. No hay
>   `affected_element_id` ni valor viejo ni valor nuevo, porque no se escribió
>   nada.
>
> **El arreglo sí es el mismo para los nueve** —los dos middlewares corren después
> de `auth.token`, así que tienen el `$usuario` resuelto y la sesión es igual de
> conocible—. Lo que cambia es **la fila**: un intento denegado no es una
> escritura, y por eso `accion` tiene un quinto valor, `denegado` (§4.2). Contarlos
> como nueve idénticos habría metido dos denegaciones disfrazadas de edición en la
> pantalla de «qué hizo en este ingreso», que es justo lo contrario de lo que
> pasó.
El token de sesión (`TokenDeSesion`) y la fila de `historiales` no se conocen: se
crean en el mismo login y luego nunca vuelven a hablarse. Consecuencias reales:

- **El caso normal, no el raro: meses de deriva con un solo aparato.** El token de
  refresco vive **14 días y rota en cada uso** (`config/sesion.php`), así que
  alguien que entre a diario **puede llevar meses sin teclear la contraseña** — y
  no hay login nuevo, así que `historiales` no crece y **todas sus escrituras de
  esos meses cuelgan del mismo ingreso de hace meses**. No hacen falta dos
  aparatos para que la atribución sea falsa; basta con usar la aplicación.
- Un profesor con la app en el móvil y el navegador abierto: **todo lo que haga en
  el navegador se atribuye al ingreso del móvil**, si ese fue el último login.
- Una sesión larga con logins nuevos por medio reparte sus cambios entre ingresos
  a los que no pertenecen.
- La pantalla «qué hizo en este ingreso» mostraría, con toda confianza y sin
  ningún error visible, **una lista falsa**.

Y hay una segunda forma del mismo hueco: **52 de las 85 líneas del seed tienen
`historial_id` NULL** —los `intento_login`, que por definición no tienen sesión—.
No es un fallo, pero significa que un tercio largo de la tabla no cabe en una
pantalla organizada por ingresos.

### Lo que ya existe y casi es la pantalla

`PUT historiales/sesion` ([HistorialesController.php:117](../../app/Http/Controllers/Historiales/HistorialesController.php#L117))
**ya intenta ser exactamente lo que se pidió.** Lo que la deja inservible:

- **Sólo trae notas.** `inner join notas ... inner join subunidades ...`: cualquier
  evento que no sea una nota desaparece de la respuesta. Como hoy casi todo lo que
  se graba no es una nota, la pantalla sale vacía casi siempre.
- Los `INNER JOIN` son filtros disfrazados: una nota borrada o una subunidad
  borrada **eliminan la línea del historial**. Justo el caso que más se reclama.
- Lleva dentro un comentario del autor original: *«Se supone que debe ser con el
  user_id, pero la embarré»*, con la consulta buena comentada al lado.

Vale como prueba de que la pantalla se quiere desde hace tiempo. No vale como
base: se reescribe.

---

## §3. Lo que el esquema de `bitacoras` no puede hacer

```
created_by · historial_id · descripcion · affected_user_id · affected_person_id
affected_person_name · affected_person_type · affected_element_type
affected_element_id · affected_element_new_value_string
affected_element_old_value_string · affected_element_new_value_int
affected_element_old_value_int · periodo_id
```

Cuatro problemas, en orden de gravedad:

1. **`affected_element_type` es texto libre y nadie lo valida.** Los valores vivos
   son `Nota`, `NF_UPDATE`, `RF_UPDATE`, `Nueva subunidad`, `YEAR CONFIGURACION`,
   `intento_login`, `AlumnoPideAjeno:user_id`… Tres convenciones de nombres
   distintas en diez escrituras. Una pantalla que agrupe por tipo tiene que
   conocer la lista de memoria, y un tipo nuevo mal escrito se pierde en silencio.
2. **El valor viejo y el nuevo están partidos en `_int` y `_string`**, y quien lee
   tiene que saber cuál mirar según el tipo. Una asistencia («presente» → «tarde»)
   o una frase no caben en ninguna de las dos formas sin convenio.
3. **No hay índices.** `PRIMARY KEY(id)` y la clave foránea de `historial_id`, y
   nada más. `WHERE created_by=?` —lo que hace `bitacoras/{user_id}`— recorre la
   tabla entera. Con 85 filas no se nota; con dos años de auditoría de verdad, sí.
4. **La auditoría se puede borrar.** `DELETE bitacoras/destroy/{id}` está enrutada
   con `auth.personal`: **cualquier miembro del personal puede borrar líneas del
   registro que lo vigila**, incluidas las suyas. El borrado es lógico y
   `getIndex` filtra `deleted_at is null`, así que desaparece de la vista. Ya se
   arregló que al menos quedara `deleted_by` (05 §88), pero eso documenta el
   borrado, no lo impide.

**Y falta la mitad del contexto.** Para escribir *«cambió la nota de Ana Pérez en
Matemáticas, unidad 2, subunidad "Quiz 1", periodo 3»* hay que salir a `notas`,
`subunidades`, `unidades`, `asignaturas`, `materias` y `alumnos`. Si cualquiera de
esas filas se borró después, la línea de auditoría **deja de poder leerse**. Una
auditoría cuyo significado depende de datos que sí cambian no es una auditoría.

---

## §4. Lo que se propone

Cuatro decisiones de diseño, y las cuatro salen de los problemas de arriba.

### §4.1 Tabla nueva, y `bitacoras` se congela

**No se hace `ALTER TABLE` sobre `bitacoras`.** Tiene historia que los colegios
consultan, son dieciséis bases, y las columnas viejas no se pueden reinterpretar
sin adivinar (§1.1: no se sabe qué filas están en UTC). Se crea `auditoria` al
lado, `bitacoras` pasa a **sólo lectura** —se deja de escribir en ella el día que
el escritor nuevo se despliega— y las pantallas viejas siguen leyéndola.

Esto también evita el riesgo de una migración de datos sobre dieciséis
producciones, que es el tipo de cosa que el plan de despliegue de este repo
desaconseja.

### §4.2 El esquema

```sql
CREATE TABLE `auditoria` (
  `id`               bigint unsigned NOT NULL AUTO_INCREMENT,

  -- Quién, y desde dónde. sesion_id es real, no adivinado (ver §2).
  `sesion_id`        bigint unsigned DEFAULT NULL,
  `historial_id`     int unsigned    DEFAULT NULL,   -- puente al ingreso
  -- NULL a propósito: un `intento_login` fallido NO TIENE actor autenticado.
  -- Hoy `Login.php` le pone `created_by = 0`, que es un id que no existe
  -- disfrazado de id que sí. Lo destapó `myvc-front-10` el 24 ago al recordar
  -- que `mis-sesiones` pinta esos intentos: si la tabla nueva exige actor, o no
  -- caben o vuelven a entrar con un 0 mentiroso.
  `actor_user_id`    int unsigned    DEFAULT NULL,
  `actor_persona_id` int unsigned    DEFAULT NULL,
  `actor_tipo`       varchar(20)     DEFAULT NULL,   -- Profesor|Alumno|Acudiente|Usuario|sistema
  `actor_nombre`     varchar(120)    DEFAULT NULL,   -- congelado: la persona puede borrarse
  `actor_intentado`  varchar(120)    DEFAULT NULL,   -- el username tecleado en un login fallido

  -- Qué. Los dos son de vocabulario cerrado, con constantes en el código.
  -- crear|editar|borrar|restaurar son escrituras. `denegado` NO lo es: es un
  -- intento rechazado, y lo graban los dos middlewares (§2). Sin él no cabrían
  -- en la tabla, o entrarían disfrazados de escritura que nunca ocurrió.
  `accion`           varchar(20)     NOT NULL,       -- crear|editar|borrar|restaurar|denegado
  `entidad`          varchar(40)     NOT NULL,       -- nota|nota_final|ausencia|...
  `entidad_id`       bigint unsigned DEFAULT NULL,

  -- Sobre quién y en qué contexto. Denormalizado A PROPÓSITO: la línea se tiene
  -- que poder leer aunque la nota, la subunidad o el alumno se borren después.
  `alumno_id`        int unsigned    DEFAULT NULL,
  `alumno_nombre`    varchar(120)    DEFAULT NULL,
  `grupo_id`         int unsigned    DEFAULT NULL,
  `asignatura_id`    int unsigned    DEFAULT NULL,
  `periodo_id`       int unsigned    DEFAULT NULL,
  `year_id`          int unsigned    DEFAULT NULL,

  -- El cambio. JSON, no dos pares int/string: una nota, una asistencia y una
  -- frase caben en el mismo sitio sin que quien lee sepa cuál mirar.
  `valor_anterior`   json            DEFAULT NULL,
  `valor_nuevo`      json            DEFAULT NULL,
  `resumen`          varchar(255)    DEFAULT NULL,   -- la frase ya construida

  -- Desde dónde llegó, para poder reconstruir un incidente.
  `ip`               varchar(45)     DEFAULT NULL,
  `ruta`             varchar(120)    DEFAULT NULL,   -- 'PUT notas/update/{id}'

  -- Cómo se supo de qué sesión salió esto. La pantalla TIENE que poder decirlo
  -- y no lo puede deducir: el navegador no sabe qué día se desplegó la fase 2 en
  -- su colegio. Viaja en la respuesta (§6.3).
  --   'sesion'     — el token lo dijo. Cierto.
  --   'aproximada' — se adivinó con el último login (§2). Anterior a la fase 2.
  `atribucion`       varchar(12)     NOT NULL DEFAULT 'sesion',

  -- DATETIME(3), no TIMESTAMP: no convierte con la zona del servidor (§1.2).
  -- Una sola columna de tiempo. No hay updated_at: una línea no se edita.
  -- No hay deleted_at: no se borra (§4.4).
  `ocurrido_en`      datetime(3)     NOT NULL,

  PRIMARY KEY (`id`),
  KEY `aud_sesion`   (`sesion_id`, `id`),                    -- «qué hizo en este ingreso»
  KEY `aud_actor`    (`actor_user_id`, `ocurrido_en`),       -- «qué ha hecho este profe»
  KEY `aud_alumno`   (`alumno_id`, `ocurrido_en`),           -- «qué le han hecho a este alumno»
  KEY `aud_entidad`  (`entidad`, `entidad_id`, `id`),        -- «quién cambió esta nota»
  KEY `aud_fecha`    (`ocurrido_en`)                         -- barrido por rango
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Sin claves foráneas a `users`, `alumnos` ni `notas`, a propósito.** Una `FK` con
`ON DELETE CASCADE` —como la que `bitacoras` tiene hoy a `historiales`— convierte
borrar el objeto en **borrar su auditoría**, que es exactamente lo contrario de
lo que se quiere. Los nombres se copian en la fila por la misma razón.

Los cinco índices salen de las cinco preguntas que la pantalla hace, no de la
intuición. Antes de crear cualquiera, `EXPLAIN` (regla del repo).

### §4.3 Un solo escritor: `App\Services\Auditoria`

Igual que las definitivas: seis escritores con cinco criterios se redujeron a uno
([10](10-definitivas.md)) y eso es lo que hizo el problema tratable. Aquí se
empieza por ahí.

```php
Auditoria::registrar()
    ->editar('nota', $id)
    ->deAlumno($alumno_id)
    ->en(asignatura: $asignatura_id, periodo: $periodo_id)
    ->de($valorViejo)->a($valorNuevo)
    ->guardar();
```

El servicio resuelve solo: el actor y la sesión (del contexto), la hora (§4.5), la
ruta y la IP (de la petición), y el `resumen` legible. **Quien llama no decide
ninguna de esas cosas** — son justo las que hoy cada sitio decide distinto.

**Y una que hay que decir antes que las otras, porque es la que se hace mal por
defecto:** el servicio decide que la escritura ocurrió **por que no hubo
excepción**, nunca por las filas afectadas. `DB::update` devuelve filas
**afectadas**, y MySQL devuelve **0 cuando el UPDATE no cambia ningún valor** —
guardar 85 encima de 85 es un guardado correcto con 0 filas—. Colgar la auditoría
de un `if ($res)` registraría esa escritura **como fallida teniendo el estado
correcto**. Es la [§13](09-pendientes.md) que midió `8myvc-dd` el 24 ago: **4
sitios y 6 rutas** contestan hoy `'No guardado'` con 200 por exactamente ese
motivo, y la auditoría se estaría enganchando al mismo error.

Un reguardado sin cambio **sí se registra** —alguien tocó esa nota, y «quién la
tocó» es la pregunta que la tabla existe para contestar—, y se reconoce solo
porque `valor_anterior` y `valor_nuevo` son iguales. No hace falta columna nueva:
la pantalla puede filtrarlos y el rastro no pierde nada.

Dos reglas más de escritura, las dos aprendidas ya en este repo:

- **Dentro de la misma transacción que el cambio.** Si el cambio no se guardó, no
  hay línea; si se guardó, la línea existe. Hoy la bitácora de `putUpdate` está
  dentro del `try` con el `UPDATE`, pero sin transacción: un fallo entre las dos
  deja la nota cambiada y sin rastro.
- **Nunca abortar la petición por un fallo de auditoría** — pero **sí** dejar
  constancia en el log. Que falte el rastro no puede impedir guardar la nota
  (razonado ya en `putLote`); que falte **en silencio** sí es inaceptable.

### §4.4 Append-only: no se edita y no se borra

Sin `updated_at` y sin `deleted_at`. `DELETE bitacoras/destroy/{id}` **no tiene
equivalente en la tabla nueva**, y la vieja se desenruta cuando la nueva esté
desplegada. Una tabla de auditoría con botón de borrar responde «no se sabe» a la
única pregunta para la que existe.

La retención se resuelve con un archivado por fecha (fase 6), no con un `DELETE`
por fila.

### §4.5 Una sola hora, y un test que la fija

Un `App\Support\Reloj` con un único `Reloj::ahora()`, y la regla en un sitio.
Todo `auditoria.ocurrido_en` sale de ahí. Como es `DATETIME(3)`, lo que se escribe
es lo que se lee, en los dieciséis colegios y pase lo que pase con el hosting.

Y un test de contrato —`RelojUnicoTest`— que recorra `app/` y **falle si aparece
un `Carbon::now()` o un `now()` nuevo** en un sitio que escriba una fecha en la
base. Como el test que impide resolver el usuario en el constructor: la regla que
no tiene test se deshace sola. Los 17 usos actuales se anotan uno a uno con su
motivo, o se convierten.

**`ocurrido_en` se guarda en hora de Bogotá** (decisión del 24 ago). Colombia no
tiene horario de verano, es lo que ya hacen 118 sitios, y `DATETIME` no convierte:
lo que escribe el `Reloj` es lo que se lee en phpMyAdmin y en la pantalla, en los
dieciséis colegios. Si algún día hay un colegio fuera de Colombia, esto se
revisa — y por eso el `Reloj` es un solo sitio.

---

### §4.5.1 Los decimales: la nota es un `int`, y no sólo en la bitácora

Vino de `myvc_flutter` a través de `myvc-front-10` el 24 ago: su
`HistorialNotaApi.dart` lleva escrito que la bitácora guarda las notas como
enteros y que **un 85,5 quedó registrado como 85**, así que la app no enseña
decimales para no inventarlos. El aviso era que la tabla nueva, con `valor_nuevo`
en JSON, sí podría guardarlos — y entonces el historial viejo y el nuevo dejarían
de ser comparables.

**Comprobado en el esquema, y el efecto es real pero la causa está antes:**

    notas.nota           int NOT NULL DEFAULT '0'
    notas_finales.nota   int NOT NULL DEFAULT '0'
    bitacoras.affected_element_new_value_int   int

**La nota es un entero en la tabla, no sólo en el registro.** La bitácora no
pierde nada respecto a lo guardado: es fiel. Quien pierde el 85,5 es
`notas.nota`, y lo pierde **para todo el mundo** — el boletín, la definitiva y la
planilla ven 85, no sólo el historial.

Dos consecuencias, y la segunda no es de este plan:

1. **Para `auditoria` no hay discontinuidad.** `valor_nuevo` en JSON copiará lo
   que haya en la columna, y ahí hay un entero. Mientras `notas.nota` sea `int`,
   el historial nuevo y el viejo **son comparables**. El aviso de Flutter estaba
   bien traído y la respuesta es que no aplica todavía.
2. **Pero si algún día `notas.nota` pasa a decimal, sí lo habría** — y entonces la
   pantalla tendría que decir desde cuándo el número es exacto, que es justo lo
   que pedía el front. Queda anotado aquí para que quien haga ese cambio lo
   encuentre.

#### Y de paso, lo que esto destapó — que es más gordo que la auditoría

Nada valida ni redondea la nota al entrar: `Request::input('nota')` va directo a
una columna `int` y **MySQL trunca en silencio**, sin aviso ni error. Preguntado
al front el 24 ago, y **contestado que sí en las cuatro pantallas**:

    app2, planilla       paginas/notas/planilla-notas.html:154      type="number" SIN step
    app2, definitivas    promocionar-notas/panel-de-notas.html:18,65,121   sin nzPrecision
    la vieja, planilla   app/scripts/notas/notas.html:108 y :116    SIN step
    la vieja, detalle    app/scripts/notas/notaFinalDetalleModal.html:44   SIN step

Un `type="number"` **sin `step` acepta decimales tecleados** —el `step` sólo
gobierna las flechas— y del lado del código tampoco hay red: `planilla-notas.ts`
manda la cadena tal cual y sólo comprueba el máximo de la escala.

**Y por qué nadie lo ha reportado en veinte años, que es la parte que lo explica
todo** (`planilla-notas.ts:253`):

    next: () => this.aviso.success(`Cambiada: ${nota.nota}`)

**El aviso repite el número que se TECLEÓ, no el que quedó guardado.** Quien
escriba `85,5` lee **«Cambiada: 85,5»** en verde y en la columna hay `85`. Al
recargar ve 85 y lo natural es pensar que se equivocó al teclear. La pantalla le
da la razón al profesor en el instante exacto en que el dato se pierde. Es la
familia de los «200 que mienten», con el agravante de que aquí **el servidor no
miente: miente el cliente al repetir lo tecleado**. El front lo arregla por su
lado, y lo arregla aunque el backend no cambie nada.

**Lo que hace falta para saber si esto es cosmético o grave es la escala, y sólo
lo puede contestar el backend.** Medido en la base que hay aquí:

| | |
|---|---|
| Escala de este colegio | **0 a 50** — la banda SUPERIOR es 46–50 |
| `porc_inicial` / `porc_final` | **`int` las dos**: la escala misma no puede expresar un límite decimal |
| Configurable | **por colegio y por año** (`escalas_de_valoracion.year_id`) |

**No es de 0 a 100**, que es lo que suponía el front. En una escala de 0 a 50 un
entero es el 2% del rango, así que el decimal pesa **el doble** que en una de 100.

> **Y aquí no se puede generalizar desde una base, que es el error contra el que
> avisa CLAUDE.md.** La escala es configurable por colegio y por año, y sólo se ve
> una. Si en alguno de los dieciséis fuera de **1 a 5** —donde el decimal no es un
> capricho sino la forma normal de calificar— la pregunta deja de ser «¿teclean
> decimales?» y pasa a ser **«¿cuántos años llevan perdiéndolos?»**. Se contesta
> con el mismo `for` de la fase 0:
>
> ```sql
> SELECT year_id, MIN(porc_inicial) AS min, MAX(porc_final) AS max
>   FROM escalas_de_valoracion WHERE deleted_at IS NULL GROUP BY year_id;
> ```
>
#### Y una tercera, que sí es del backend

Mirando el front con una escala de 50 delante, `myvc-front-10` encontró un
`[nzMax]="100"` escrito a mano en `panel-de-notas.html:126` — en un colegio de 0 a
50 ese campo acepta **el doble** de la máxima. Es suyo y lo arreglan ellos. Pero
al comprobarlo por mi lado sale algo que es mío:

**Nada en el backend rechaza una nota por pasarse de la escala.** Comprobado: hay
**diez** sitios que comparan contra `porc_inicial`/`porc_final` y **los diez son
para pintar la banda** —SUPERIOR, ALTO, BÁSICO— no para rechazar. Ninguno aborta.
En todo el proyecto hay **2 validaciones** (CLAUDE.md) y ninguna es ésta.

O sea que **el único guardián de la escala es el cliente**, y de las tres
pantallas hermanas dos tienen guarda y una no. Con dos agravantes que puso el
front y que son ciertos:

- El límite se resuelve con `escalaMaxima() ?? 100`, así que **un fallo de la
  caché de escalas afloja el límite en vez de apretarlo**. Lo seguro sería
  negarse a guardar — que es justo lo que sí hace la definitiva.
- Si algún día el backend valida, **esos campos son por donde entra lo que hoy
  pasa callando**: un 422 haría visible mañana lo que hoy se guarda sin ruido.

No lo arreglo aquí y no lo propongo en este plan: meter la primera validación de
escala del proyecto es una decisión con su propia medición —cuántas notas fuera de
rango hay ya guardadas en los dieciséis, y qué se hace con ellas—. Queda escrito
porque **una nota fuera de la escala es un dato que la auditoría va a registrar
como si fuera normal**, y quien lea el rastro mañana merece saber que el sistema
nunca lo impidió.

> **Nada de esto es de la auditoría y no se arregla aquí.** Está escrito en este
> documento porque es donde salió, y porque el rastro de cómo se encontró vale
> tanto como el hallazgo: **lo destapó Flutter avisando de un detalle de
> pantalla**, siguió con una escala que resultó no ser la que todos suponíamos, y
> acabó en que el sistema de calificación entero está construido sobre enteros y
> sin guardas en el servidor. Ninguna de las tres se buscaba.

### §4.6 Los cuatro clientes — lo que este plan no miraba

**Este documento no mencionaba `myvc_front` ni una vez**, y sus fases 5 y 6 tocan
**seis sitios vivos** del front más uno de Flutter. Lo levantó la sesión
`myvc-front-10` la noche del 24 ago, con fichero y línea, y está comprobado
contra este repo: los seis consumidores existen y las rutas son las que dice.

| Consumidor | Qué usa | Qué le pasa con el plan tal como estaba |
|---|---|---|
| `datos/historiales.ts` | los cuatro `PUT historiales/*` | los cuatro **sólo leen** |
| `paginas/notas/detalle-nota.ts` | `historiales/nota-detalle` | la fase 5 la sustituía |
| `paginas/promocionar-notas/detalle-definitiva.ts` | `nota-final-detalle` | la fase 5 la sustituía |
| `paginas/mis-sesiones/*` | `de-usuario`, `sesion`, `DELETE bitacoras/destroy/{id}` | la fase 6 le quitaba el botón **y los `intento_login`** |
| `paginas/bitacora/bitacora.ts` | `GET bitacoras/{user_id?}` + destroy | la fase 6 la dejaba sin las dos |
| **`myvc_flutter`** `HistorialNotaApi.dart` | `historiales/nota-detalle` | **una sola app para los dieciséis** |

De ahí salen cuatro reglas que ya están metidas en las fases, y una decisión que
sube a Joseth.

**1. Nada viejo se retira en la fase 5.** Las rutas nuevas son **aditivas**. La
frase original —*«sustituye a `historiales/nota-detalle` y `nota-final-detalle`»*—
daba por hecho que eran dos consumidores y son **tres**: el tercero es Flutter,
que se publica en tiendas y es una sola app para los dieciséis. Retirar las viejas
antes de que Flutter publique deja el historial de notas del móvil en 404 **en
dieciséis colegios a la vez**. La retirada es su propia fase (la 7) y su condición
de entrada no es «fusionado» ni «desplegado»: es **«Flutter publicado y adoptado»**,
que es un dato de tienda, no del repo.

**2. Los alias de las respuestas viejas no se tocan.** Los dos modales del front
leen `bit_id`, `old_value`, `new_value`, `creado_por` y `created_at` —los **alias**
del `SELECT` de `HistorialesController`, no los nombres de columna—. Está
comprobado: la consulta los produce en su línea 23. Renombrarlos **vacía los dos
modales sin ningún error**, que es el modo de fallo que ya ocurrió una vez en esa
pantalla. Las rutas nuevas usan sus propios nombres; las viejas conservan los
suyos mientras existan.

**3. Los `intento_login` van a la tabla nueva, no se congelan.** Son las 52 filas
sin `historial_id` del seed y son lo que `mis-sesiones` pinta para avisar de quién
intentó entrar en una cuenta ajena — o sea lo que la pantalla existe para
enseñar. **Y esto arregló el esquema**: un intento fallido **no tiene actor
autenticado**, así que `actor_user_id NOT NULL` era un error. Hoy `Login.php` le
pone `created_by = 0`, un id que no existe disfrazado de id que sí. En la tabla
nueva el actor es nullable y el username tecleado va en `actor_intentado` (§4.2).

**4. La atribución viaja en la respuesta.** El plan decía que la pantalla tiene
que avisar cuando el `historial_id` es una adivinanza (§2), **y la pantalla no lo
puede saber**: el navegador no sabe qué día se desplegó la fase 2 en su colegio.
Por eso `auditoria.atribucion` es una columna (`'sesion'` \| `'aproximada'`) y sale
en el cuerpo. Sin ella el aviso es impintable.

> **Y una que no se decide aquí.** `can_view_auditoria` puede **regresar** una
> pantalla que hoy llega a todos: `/panel/mis-sesiones` alcanza a cualquiera con
> `auth.personal`, incluida una secretaría. La regla «cada quien ve lo suyo sin
> permiso» hay que sostenerla **endpoint por endpoint**, y hay un agravante ya
> medido: la [09 §E](09-pendientes.md) dice que `historiales/de-usuario` no lee
> sólo el rastro — lee `bitacoras`, `historiales`, `notas` **y `notas_finales`**.
> Colgarle `can_view_auditoria` tal cual **autorizaría de paso las
> calificaciones**, que es una pregunta que no ha hecho nadie. Esa ruta se parte
> antes de ponerle guard.

## §5. Las fases

| | Qué | Depende de |
|---|---|---|
| **0** | Medir en los dieciséis | — |
| **1** | El reloj único y su test | — · **hecha el 24 ago** |
| **2** | La sesión de verdad: atar `historiales` al token | — |
| **3** | La tabla, el servicio y el detector | 1, 2 |
| **4** | Instrumentar los dominios pedidos | 3 |
| **5** | Los endpoints de la pantalla | 3, 4 |
| **6** | Retención y archivado de `auditoria` | 5 desplegado |
| **7** | Retirar lo viejo — `bitacoras` y las rutas de `historiales/*` | **Flutter publicado**, no sólo desplegado |

### Fase 0 — medir en los dieciséis, antes de tocar nada — **la herramienta ya está**

`tools/salud-de-la-bitacora.php`, escrita el 24 ago 2026. Sólo `SELECT`, diez
bloques, e imprime su población en el primero porque todos los ceros de abajo son
ambiguos sin ella. Con `--csv` saca **una línea por base**, que es lo que hace
falta de verdad: la decisión que espera este número se toma con los dieciséis
delante, no de uno en uno.

```bash
docker exec 8myvc-app-1 php tools/salud-de-la-bitacora.php
for c in colegio1 colegio2 ...; do
    DB_DATABASE=$c php tools/salud-de-la-bitacora.php --csv | tail -1
done
```

**Lo que ya dijo, corrida sobre el seed** (rebanada de dos años de un colegio
real, así que las formas valen aunque los volúmenes no):

| | |
|---|---|
| Ingresos con algo que enseñar | **18 de 3.229** — el 99,4% salen vacíos |
| Los dos relojes | **12 filas en UTC, 74 en Bogotá**, en la misma columna |
| Atribución al ingreso | **23 de 34** emparejadas caen donde la adivinanza pudo fallar (67,6%), y 52 no tienen `historial_id` |
| Cola nocturna | **39,5%** de las filas entre las 19:00 y las 04:59 |
| Zona del servidor | `@@system_time_zone = UTC`, `@@session.time_zone = SYSTEM` — **sin fijar** |

> **Los bloques 3 y 4 se cruzan solos, y coincidieron: 12 y 12.** Uno clasifica
> por quién escribió (el tipo de la fila) y el otro por el reloj (el desfase
> contra `historiales`, que es Bogotá porque lo escribe `Login.php`). No comparten
> ningún supuesto, así que coincidir es lo más cerca de una comprobación que hay
> aquí: **el desfase de cinco horas está confirmado, no supuesto.** La herramienta
> lo dice ella misma al final en vez de dejar los dos números en dos bloques
> esperando que alguien los compare — que es cómo se leyeron mal los nueve sitios
> de la §142.

Lo que la herramienta contesta, y por qué cada bloque:



`tools/salud-de-la-bitacora.php`, sólo `SELECT`, y **imprimiendo su población**
(regla del repo: un «0 encontrados» no distingue «revisé y no hay» de «no revisé»).
Contesta:

- Cuántas filas tiene `bitacoras` de verdad en producción, y desde cuándo.
- **Cuántas están en UTC y cuántas en Bogotá** — se puede estimar cruzando
  `created_at` con el `historiales.created_at` de la misma sesión, y con los tipos
  que sólo escribe cada middleware. Sin este número no se sabe si la historia
  vieja se puede reinterpretar o hay que darla por perdida.
- Cuál es el `@@system_time_zone` de cada colegio. Si los dieciséis no coinciden,
  eso ya es un incidente por sí solo.
- Cuántos ingresos hay al día y por usuario, para dimensionar el crecimiento de la
  tabla nueva.

> **La lista de escritores de la herramienta está a mano, y tiene centinela.**
> `ESCRITOS_EN_UTC` / `ESCRITOS_EN_BOGOTA` salen de leer los diez INSERT uno a
> uno, y una lista a mano sin test dura hasta el siguiente que escriba — fallando
> además hacia el lado que tranquiliza. Lo fija
> `tests/Contrato/CentinelaDeLosEscritoresDeBitacoraTest`, con tres
> comprobaciones: que sigan siendo diez, que estén en los mismos ficheros, y
> **que los tres de UTC sigan usando el reloj sin zona** — esta última es la que
> caza el cambio que ningún conteo ve, que alguien le ponga la zona a un `now()`
> y el reparto pase a mentir sin que se mueva un solo número.

### Fase 1 — el reloj único — **hecha el 24 ago 2026**

`App\Support\Reloj::ahora()` en hora de Bogotá, y `RelojUnicoTest` fijándolo.

**Movidos: los tres que escribían `bitacoras.created_at` con el reloj
equivocado** — `ExigirPersonaPropia`, `ExigirBoletinPropio` y `Sesion.php:477`.
Con eso la columna queda **uniforme a partir del despliegue**: los otros siete
escritores ya estaban en Bogotá, y las 12 filas UTC históricas se quedan como
están, documentadas y sin forma de distinguirlas — por eso la fase 0 se corre
antes de decidir si se reinterpretan.

**No movidos, y con el motivo escrito uno a uno en el test**: los trece usos
restantes no guardan una fecha que alguien vaya a leer —o se restan consigo
mismos (expiraciones de token, el corte de `LimpiarSesiones`) o son un TTL
relativo (la caché de FCM)—. Ése es el criterio para quedarse en UTC, y el único.

Con una excepción declarada: **`PuntoDeControlDeImportacion` sí guarda fechas y
se queda**. Su propia cabecera documenta desde antes que `importaciones.inicio` y
`fin` sólo se restan entre sí, así que unificar la zona no cambiaría ningún
resultado — **sólo desplazaría cinco horas lo que se lee en pantalla**. O sea que
moverlo arregla la pantalla y a cambio deja *esa* tabla con dos relojes en su
historia, que es la enfermedad que esta fase viene a curar. Elegir entre las dos
cosas no es de la fase 1: es de quien lleve las importaciones, y está anotado en
el test para que se encuentre.

Un número corregido de paso: los usos de reloj sin zona eran **16 líneas, 19
llamadas, 7 ficheros**. Antes en este documento ponía 17, contado con un filtro
que mezclaba líneas y llamadas.

#### Lo que la fase 1 NO arregla, y hay que decirlo

**La mitad de las horas raras es del esquema, no del código.** Las columnas
`created_at` son `TIMESTAMP`, que convierte al leer con la zona de la sesión de
MySQL, y ésa sigue sin fijarse (`@@session.time_zone = SYSTEM`). El `Reloj`
garantiza que lo que *escribimos* es una sola hora; no garantiza que lo que se
*lee* en un colegio sea la misma. Eso se cierra en la tabla nueva, que es
`DATETIME(3)` — y para `bitacoras` no se cierra nunca, porque se congela.

**`config/app.php` no se tocó**, según la decisión 2: sigue en UTC, así que
`now()` y `Carbon::now()` siguen dando UTC. Lo que separa esta fase es **lo que
se guarda** —que pasa por el `Reloj`— de **lo que se compara consigo mismo**, que
puede seguir en UTC mientras sea coherente. Mover la configuración habría
desplazado de golpe expiraciones, `jobs` y cachés, y eso es un cambio con su
propia medición que la auditoría no necesita.

### Fase 2 — la sesión de verdad

Ahora mismo el token y el ingreso no se conocen (§2). Se ata:

- `tokens_de_sesion` gana una columna `historial_id`, puesta en el login, donde se
  crean los dos.
- El contexto de usuario expone `sesion_id`/`historial_id` como expone
  `persona_id`. Resolverlo **en la primera lectura**, no en el constructor — hay
  un test que lo impide y con razón.
- El `order by id desc limit 1` de `putUpdate` y `putLote` desaparece.

**Esta fase se puede desplegar sola y ya mejora la bitácora vieja**, porque los
`historial_id` que escriben las cuatro rutas actuales pasan a ser ciertos.

Para las sesiones anteriores al despliegue, `historial_id` es una atribución
aproximada y **la pantalla lo tiene que decir**, no disimularlo.

### Fase 3 — la tabla, el servicio y el detector

La migración (migración de verdad, no phpMyAdmin: regla del repo), el
`App\Services\Auditoria` con sus tests de ida y vuelta, y:

`tools/escrituras-sin-auditoria.py`, que compara **las 256 escrituras** con las
que llaman al servicio y lista las que faltan, con su fichero y su línea. Es la
lista de trabajo de la fase 4 y la que dice cuándo está terminada.

> Dos avisos que este repo ya se ha ganado: la herramienta **imprime su
> población** («256 escrituras revisadas, 247 sin auditoría»), y hay que
> comprobar que **detecta lo que dice su nombre** — que una escritura sin
> auditoría lo esté de verdad, y no que el detector no sepa reconocer la llamada.

### Fase 4 — instrumentar, empezando por lo que se pidió

Por dominio, y en este orden, porque es el orden en que se reclama:

1. **Notas** — editar, borrar, lote, la nota rápida del horario. Es la petición de
   origen y ya tiene la mitad hecha.
2. **Unidades y subunidades** — crear, editar, borrar. Hoy sólo se graba crear.
3. **Definitivas** — ya pasan todas por `DefinitivasDeAsignatura` (fase 3 del
   [10](10-definitivas.md)), así que **es un solo sitio**. Y aquí hay que separar
   dos cosas que no son iguales: la definitiva que un profesor **teclea** y la que
   el sistema **recalcula**. Si las dos entran como «editar», la pantalla se llena
   de ruido automático y deja de leerse. Van con `actor_tipo='sistema'` o con una
   acción distinta.
4. **Asistencia y faltas** — `ausencias`, las dos rutas de Tardanzas y la de la app.
5. **Comportamiento** — `nota_comportamiento` y `definiciones_comportamiento`.
6. **Disciplina y situaciones** — las 15 escrituras de `Disciplina/`.
7. **Frases** — `frases`, `frases_asignatura`, `frases_preescolar`.

Cada dominio es un commit con su test de contrato: **hacer el cambio por HTTP y
comprobar que la línea aparece** con el actor, la sesión, el alumno y los dos
valores correctos. Mirar el resultado, no el 200 — es lo que ha encontrado todo lo
que se ha encontrado en este repo.

El resto de los 56 controladores (matrículas, perfiles, roles, importaciones) va
después, con el detector de la fase 3 como lista.

### Fase 5 — la pantalla

Cuatro rutas nuevas. **Cuatro rutas nuevas son una decisión, no un efecto
secundario**: mueven el contador de 542 a 546, tres documentos y dos snapshots.

| Ruta | Qué contesta |
|---|---|
| `GET auditoria/ingresos` | Los ingresos de un usuario en un rango: cuándo entró, desde dónde, con qué dispositivo, **y cuántas acciones de cada tipo hizo**. El contador es lo que hace la lista útil: se ve de un vistazo cuál merece abrirse. |
| `GET auditoria/ingresos/{id}` | El detalle de un ingreso: la lista de acciones en orden, agrupada por tipo, con el alumno, la asignatura y el antes/después de cada una. **Esta es la pantalla que se pidió.** |
| `GET auditoria/entidad/{tipo}/{id}` | La vida entera de una nota, una asistencia o una frase. Sustituye a `historiales/nota-detalle` y `nota-final-detalle`. |
| `GET auditoria/alumno/{id}` | Todo lo que se le ha hecho a un alumno. Es la que contesta un reclamo de acudiente. |

Sin `INNER JOIN` a las tablas de datos: la fila de auditoría ya trae lo que hace
falta para pintarla (§4.2). Es lo que hoy vacía `putSesion`.

> **El estado vacío es el caso normal, no el borde.** En la copia de producción
> hay **4 filas de tipo `Nota` y 3 de `NF_UPDATE`. En total** — el mismo número
> que da el seed, así que no es un artefacto de la rebanada. Con 3.229 ingresos y
> el 99,4% vacíos (fase 0), **casi todo el que abra esta pantalla no va a ver
> nada** hasta que la fase 4 lleve tiempo desplegada. La pantalla se diseña
> alrededor de ese estado —diciendo *desde cuándo* hay registro y *qué* se
> registra ya—, no con un «sin resultados» de relleno. Lo trajo `myvc-front-10`
> el 24 ago y cambia el dibujo, no sólo una nota al pie.

**Las cuatro son aditivas: no retiran nada** (§4.6). `historiales/nota-detalle`,
`nota-final-detalle`, `de-usuario`, `sesion` y las dos de `bitacoras` **siguen
contestando igual y con los mismos alias** hasta la fase 7.

Y dos reglas de respuesta, para que el front pueda construir contra nombres:

- **Un vacío es una lista vacía con 200, nunca un 400.** Hoy `historiales/sesion`
  aborta con `400 {message:'No hay historial'}` cuando no encuentra la fila: el
  manejador de errores del front recibe algo que parece un fallo de red y es «no
  hay datos». Un id que no existe es **404**; un id válido sin actividad es **200
  con `acciones: []`**. Es la regla de los códigos correctos de CLAUDE.md, y aquí
  además es la diferencia entre una pantalla vacía y una pantalla rota.
- **`atribucion` viaja en cada ingreso** (`'sesion'` \| `'aproximada'`), porque el
  navegador no puede deducirla (§4.6).

**Y el IDOR de `historiales/de-usuario` se cierra en esta fase**, no después: la
ruta acepta `user_id` del cuerpo y **no comprueba de quién es** —`$user` se
resuelve y no se usa—. Está en la [08](08-revision-idor.md) y en el lote E de la
[09](09-pendientes.md). Es independiente de la decisión 3: comprobar que quien
pregunta puede preguntar por ese usuario hace falta con cualquier respuesta que se
dé a «quién ve la auditoría».

**Quién puede verlo, decidido el 24 ago.** No es un detalle: la pantalla dice qué
hizo cada persona minuto a minuto. Las tres piezas, y van juntas:

1. **Un permiso nuevo, `can_view_auditoria`.** Sigue la convención de los 19 que ya
   existen (`can_edit_notas`, `can_edit_unidades_subunidades`): verbo en inglés,
   objeto en español. Los permisos **viajan dentro del contexto de usuario**
   (`ContextoDeUsuario`), así que se pregunta como se pregunta cualquier otro y
   retirarlo tiene efecto sin tocar la sesión.
2. **Sembrado a rector y coordinación**, no a «personal». Que el permiso exista no
   sirve si por defecto lo tiene todo el mundo, y un colegio que no llegue a
   configurarlo tiene que quedar en el lado seguro.
3. **Y cada quien ve siempre lo suyo, sin permiso.** Un profesor puede consultar
   sus propios ingresos y sus propios cambios: es su defensa cuando alguien
   reclama, y no expone a nadie más.

> Ojo con lo ya sabido en este repo: **crear un rol no regala permisos.** Antes de
> cablear `can_view_auditoria` hay que repasar **todas** las llamadas que cuelguen
> del criterio, no sólo las cuatro rutas nuevas — las dos viejas
> (`bitacoras/{user_id?}` y las cuatro de `historiales/`) van hoy con
> `auth.personal` y **cualquiera del personal lee a cualquiera, incluido su
> rector**. Se cierran en la misma fase, no después: dejarlas abiertas convierte
> el permiso nuevo en decoración.

Y una consecuencia de la topología que no se puede olvidar: `myvc_flutter` es
**una sola app para los dieciséis colegios**. La pantalla no se publica hasta que
las rutas estén **desplegadas** en los dieciséis, no fusionadas. En el que faltara
sería un 404.

### Fase 6 — retención

Un archivado por fecha con la retención que diga el colegio. Con la fase 0 medida
ya se sabrá cuánto crece la tabla al año.

**Aquí ya no se congela nada**: la versión original de esta fase desenrutaba
`bitacoras/destroy` y dejaba de escribir en `bitacoras`, y las dos cosas rompían
pantallas vivas (§4.6). Se han movido a la 7, con la condición de entrada que les
corresponde.

### Fase 7 — retirar lo viejo, y no antes de tiempo

**Esta fase no tiene fecha, y no es lo mismo que tenerla lejos.** Lo corrigió
`myvc-flutter-fe` vía `myvc-front-10` el 24 ago, y es el hallazgo que más cambia
cómo se planifica.

**Cada ruta vieja tiene su propia lista de clientes y su propia condición de
salida.** Ponerles una condición común —como estaba escrito— retira de más o
espera de más:

| Qué se retira | Quién la llama hoy | **Qué front la retiene** | Condición de salida |
|---|---|---|---|
| `historiales/nota-detalle` | `app/` · `app2` · **Flutter** | `app/` **y** Flutter | Flutter publicado **y adoptado** |
| `historiales/nota-final-detalle` | `app/` · `app2` | **`app/`** | que `app2` sustituya a `app/` — Flutter **no** la llama |
| `historiales/de-usuario` y `sesion` | `mis-sesiones` | `app/` | esa pantalla migrada en los dieciséis |
| `GET bitacoras/{user_id?}` | `/panel/bitacora` ×2 | **`app/`**, `BitacoraCtrl.ts` | **que `app2` sustituya a `app/`** — no basta con jubilar la de `app2` |
| `DELETE bitacoras/destroy/{id}` | **dos** botones | `app/` | que el superviviente tenga sustituto |

> **La columna «qué front la retiene» no es decorado: es la que evita el error que
> se cometió tres veces esta noche.** «La pantalla» significa dos cosas según quién
> la nombre — la de `app2`, que no se ha publicado, y la de `app/`, que **es la que
> corre hoy en los dieciséis colegios**. Jubilar `/panel/bitacora` (decisión 4) es
> una decisión sobre `app2`; el endpoint lo retiene `app/scripts/bitacora/BitacoraCtrl.ts`
> con **dos** entradas de menú, y ése no se va hasta que `app2` sustituya a `app/`.
> **Dos pantallas con el mismo nombre y dos calendarios distintos.** Lo corrigió
> `myvc-front-10` sobre una fila que yo tenía mal.

> **Y una red del front que juega a favor**, apuntada aquí porque afecta al orden:
> `cascara/menu/cobertura.spec.ts` cruza el menú con las rutas y afirma la lista
> entera de pantallas sin puerta, así que **quitar la ruta dejando la entrada —o
> estrenar la nueva sin entrada— pone su prueba en rojo**. Esa prueba nació porque
> `bitacora` estuvo hecha y sin forma de entrar a ella.

Medido por Flutter: **cero referencias a `nota-final-detalle` en su `lib/`**, sólo
`nota-detalle`. Y `nota-final-detalle` la retiene `app/`, que es **la versión que
corre hoy en los dieciséis** —`app2` aún no se publica—. O sea: Flutter suelta una
y el front retiene la otra, por motivos distintos y con calendarios distintos.

#### Por qué «adoptada» no es una fecha que llegue sola

`myvc_flutter` está en `1.0.0+1`, sin publicar, y necesita doce probadores catorce
días seguidos antes de poder pedir producción. **Esa es la parte fácil.** La otra:

> **La app no comprueba versión mínima en ninguna parte de `lib/`.** No hay forma
> de obligar a nadie a actualizarse, así que **un teléfono con la versión vieja
> seguirá llamando a `nota-detalle` indefinidamente y nadie se entera.**

Y eso convierte la condición de entrada de esta fase en algo que **hoy no se puede
comprobar**. Sólo hay dos maneras de que llegue a serlo:

1. **Que la app aprenda a exigir versión mínima** — trabajo de Flutter más un
   endpoint diminuto de este lado. Es la que deja el problema resuelto.
2. **Decidir la retirada mirando el reparto por versión de Play Console**, que es
   un dato de tienda y lo tiene Joseth. Sirve una vez, no arregla la próxima.

> **Y esto es más grande que este plan, aunque salga en este plan.** Mientras no
> exista la comprobación de versión mínima, **la retirada de cualquier endpoint
> depende de la buena voluntad de dieciséis colegios.** La fase 7 es el primer
> sitio donde se ve, no el único que lo tiene: le pasa igual a toda la Fase 5 del
> [00](00-plan-migracion.md) y a cualquier cambio de contrato futuro. Se anota
> aquí porque aquí se encontró, y quien planifique una retirada después debería
> leer esto antes de poner una fecha.

Sobre el borrado, que es el que más cuidado necesita: la tabla nueva es
append-only y **no tiene equivalente de `destroy`** (§4.4), pero hoy hay dos
botones vivos —borrar un intento fallido en `mis-sesiones` y la rejilla de
`/panel/bitacora`—. Quitar la ruta sin decidir qué hace ese botón deja dos
pantallas con un control que revienta. Y un detalle de cuerpo que hay que avisar
aunque sea una mejora: `deleteDestroy` contesta hoy la **cadena** `'Bitácora
eliminada'` en `text/html`, y el front ya se comió ese fallo y va por
`deleteTexto`. Si su sustituto contesta JSON, **es un cambio de contrato**.

---

## Las decisiones

| | Qué se preguntó | Qué se decidió |
|---|---|---|
| **1** | Zona de `ocurrido_en` | **`DATETIME` en hora de Bogotá.** Colombia no tiene horario de verano y `DATETIME` no convierte: lo escrito es lo leído. Se revisa el día que haya un colegio fuera de Colombia, y por eso el `Reloj` es un único sitio |
| **2** | ¿Mover `config/app.php` a `America/Bogota`? | **No, por ahora.** El `Reloj` es la única fuente para lo que se guarda. Evita arrastrar una medición de expiraciones de sesión, `jobs` y cachés que la auditoría no necesita. Los 17 usos UTC se anotan con su motivo y el `RelojUnicoTest` impide que crezcan |
| **3** | ¿Quién ve la auditoría? | **Las tres cosas a la vez**: permiso `can_view_auditoria` por rol · sembrado sólo a rector y coordinación · y cada quien ve siempre lo suyo sin permiso. Detallado en la fase 5 |

### La cuarta, contestada el 24 ago — la trajo el front

**[DECISIÓN 4] `/panel/bitacora` SE JUBILA.** La pantalla nueva de la fase 5
ocupa su sitio en el menú.

La vieja vive en `paginas/bitacora/bitacora.ts`, va con el permiso `califica`, y
usa `GET bitacoras/{user_id?}` más el botón de borrar. Se jubila porque la nueva
sirve para lo mismo y mejor, y porque mantener las dos deja **dos pantallas que
dicen servir para lo mismo, y una de las dos miente** — el argumento es del front
y es el que decidió.

**Consecuencias, y la primera es una obligación del front, no una sugerencia:**

- **`myvc_front` retira `/panel/bitacora`** y pone la pantalla nueva en su sitio
  del menú. Está escrito como **tarea obligatoria** en la sección C de
  `myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, no como una nota — se avisó el
  24 ago y era lo único que les bloqueaba.
- **`GET bitacoras/{user_id?}` se retira con ella**, en la fase 7, y no antes: la
  pantalla vieja sigue viva hasta que la nueva esté desplegada en los dieciséis.
- **El permiso `califica` deja de gobernar quién ve el rastro.** Pasa a
  `can_view_auditoria` (decisión 3), que es más estrecho: hoy `califica` lo tiene
  cualquiera que ponga notas. **Eso es un endurecimiento, y hay que decirlo en voz
  alta** — un profesor que hoy entra a `/panel/bitacora` puede dejar de poder. Si
  el colegio quiere que sigan entrando, la respuesta no es dejar la pantalla
  vieja: es sembrar el permiso más ancho.

**Y la pregunta pegada, contestada también el 24 ago: NADIE borra, y el botón
desaparece.** Tras retirar `bitacoras/destroy` no hay sustituto y el control se
quita de `mis-sesiones`. Es lo coherente con el append-only de la §4.4, y cierra
de paso lo que la §3 llamaba el cuarto problema del esquema viejo: hoy
**cualquier miembro del personal puede borrar el registro que lo vigila, incluido
el suyo**. La auditoría deja de tener botón de borrar en ninguna parte.

> Nada de esto **bloquea las fases 0 a 6**. Se ejecutan enteras con la decisión
> tomada o sin ella; lo que desbloquea es el trabajo del front.

**Lo demás no espera a nadie.** Lo siguiente es la fase 0: correr
`tools/salud-de-la-bitacora.php` colegio por colegio, igual que el `for` de una
línea de la fase 0 del [10](10-definitivas.md).

## Lo que se arregla de camino, y no cuesta aparte

- `Bitacora::saveUpdateNota()` **no lo llama nadie**: código muerto, y sin ruta.
  Por la regla del repo, se borra (05).
- `Bitacora extends Model` con `SoftDeletes` escribiría sus timestamps en **UTC**
  vía Eloquent, contra los SQL crudos que escriben en Bogotá. Muere con el punto
  anterior.
- `HistorialesController::putSesion` lleva un `inner join ... b.affected_user_id=a.id`
  con la versión correcta comentada al lado y un *«la embarré»*. Se reescribe en
  la fase 5.
