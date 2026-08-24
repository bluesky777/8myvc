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
  `accion`           varchar(20)     NOT NULL,       -- crear|editar|borrar|restaurar
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

Dos reglas de escritura, las dos aprendidas ya en este repo:

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
| **1** | El reloj único y su test | — |
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

### Fase 1 — el reloj único

`App\Support\Reloj`, los **17** usos UTC revisados uno a uno, y `RelojUnicoTest`. **No
se cambia `config/app.php`** en esta fase: mover `'timezone'` de UTC a Bogotá
desplazaría de golpe las expiraciones de `Services/Sesion.php`, los `jobs` y las
cachés, y eso es un cambio con su propia medición. **Se decidió no moverlo**
(24 ago): el `Reloj` es la única fuente para lo que se guarda, y así esta fase no
arrastra una medición que la auditoría no necesita. Los 17 usos sueltos quedan
anotados uno a uno con su motivo, y el `RelojUnicoTest` impide que crezcan.

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

Lo último, y **su condición de entrada no es «desplegado en los dieciséis» sino
«Flutter publicado y adoptado»**, que es un dato de tienda y no del repo:

| Qué se retira | Qué hay que tener antes |
|---|---|
| `historiales/nota-detalle` y `nota-final-detalle` | los dos modales del front migrados **y** `myvc_flutter` publicado en tiendas |
| `historiales/de-usuario` y `sesion` | `mis-sesiones` migrada |
| `GET bitacoras/{user_id?}` | decidido qué pasa con `/panel/bitacora` — **es la decisión 4** |
| `DELETE bitacoras/destroy/{id}` | los **dos botones** que hay encima tienen sustituto |

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

### La cuarta, abierta desde el 24 ago — la trajo el front

**[DECISIÓN 4] `/panel/bitacora`: ¿se jubila o se queda?**

La pantalla vive en `paginas/bitacora/bitacora.ts`, va con el permiso `califica`,
y usa `GET bitacoras/{user_id?}` más el botón de borrar. La pantalla nueva de la
fase 5 dice servir para lo mismo, mejor. De la respuesta depende una cosa que el
front no puede decidir solo: **dónde cae la pantalla nueva en el menú** — si
`/panel/bitacora` se jubila, la nueva ocupa su sitio; si se queda, hay dos
pantallas que dicen servir para lo mismo y **una de las dos miente**.

Va con una pregunta pegada, porque se contestan juntas: **después de retirar
`bitacoras/destroy`, ¿quién borra un intento fallido?** Hay dos botones encima de
esa ruta. Las opciones son (a) nadie, la auditoría no se borra y los botones
desaparecen —es lo coherente con el append-only de la §4.4—, (b) sólo rectoría, y
el borrado queda a su vez auditado, o (c) se quedan como están y `bitacoras` no se
retira nunca.

> Esta decisión **no bloquea nada hasta la fase 7**. Las fases 0 a 6 se hacen
> enteras sin contestarla.

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
