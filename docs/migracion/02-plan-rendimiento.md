# Plan de rendimiento — 8myvc

> Documento hermano de [00-plan-migracion.md](00-plan-migracion.md) y [01-plan-seguridad.md](01-plan-seguridad.md).

---

## Resumen: tenías razón, pero la causa no es la que creías

Dijiste *"seguro la autenticación de la api por cada llamado está sobrecargada"*. La autenticación **sí** está sobrecargada — hace el trabajo dos veces y ejecuta entre 5 y 8 consultas por petición. Pero medí, y no es lo primero que hay que arreglar.

**Medición real, contra tu entorno local (Docker + kool), 3 corridas cada una:**

| Petición | Tiempo |
|---|---|
| `GET /api/ruta-que-no-existe` (404 — solo arranque + rutas) | **0.240 – 0.265 s** |
| `GET /api/paises` (sin auth, devuelve 2 filas) | **0.246 – 0.264 s** |
| `POST /api/login` (sin token, salida temprana) | **0.249 – 0.259 s** |

**Un 404 cuesta 250 ms.** No toca la base de datos, no autentica, no ejecuta lógica. Eso significa que **~250 ms de cada llamada se van antes de que tu código haga nada**. Una API Laravel con rutas y config cacheadas responde un 404 en 10–20 ms.

La autenticación se suma **encima** de esos 250 ms.

---

## Las cinco causas, en orden de impacto

### 1. ✅ OPcache · resuelto por el salto de imagen, y medido el 19 ago 2026

> **Ya está.** Lo cerró la Fase 4 sin que nadie lo apuntara: la imagen pasó de
> `kooldev/php:8.0-nginx` a `8.4-nginx`, y esa sí trae la extensión. Comprobado
> en FPM, no solo en el CLI: **2.037.893 aciertos contra 1.368 fallos**, 1.065
> scripts en caché, 35 MB de los 128 configurados. Con `validate_timestamps=1` y
> `revalidate_freq=2`, que es lo correcto en desarrollo.
>
> **Y se nota** (20 peticiones, **sin** `route:cache`):
>
> | Petición | Antes | Ahora |
> |---|---|---|
> | `GET /api/ruta-que-no-existe` (404) | 0,240 – 0,265 s | **0,028 s** de media, 0,024 la mejor |
>
> De 0,25 s a 0,03 s. **Esa es la única fila comparable de la tabla de arriba**,
> y por eso está sola: `GET /api/paises` medía 0,246–0,264 s cuando devolvía dos
> filas sin autenticación, y hoy contesta **401** —la Fase 2 lo puso detrás del
> guard, con quince excepciones que no lo incluyen—. Los 0,023 s que da ahora no
> son la misma medición: no llega a la base de datos. Un 404 sí es lo mismo que
> era, arranque más tabla de rutas y nada más.
>
> No es todo de OPcache: la Fase 1 quitó `AdvancedRoute`, que reflexionaba 97
> controladores y registraba 1.076 rutas en cada arranque. Los dos iban juntos
> en el plan y los dos están.
>
> **Y el paso 6 ya no vale lo que decía.** Este documento le puso 30–60 ms a
> `route:cache`. Medido hoy: 0,031 s con la caché puesta contra 0,028 s sin
> ella — o sea, ruido, y de los dos lados. La estimación era buena para el
> mundo donde se escribió, con 1.076 rutas registradas dos veces y sin OPcache;
> quitados esos dos, no queda nada que ahorrar. Se comprobó de paso que
> `route:cache` **funciona** y que la aplicación responde igual con las rutas
> cacheadas, que es lo que de verdad hacía falta saber para el despliegue.
>
> Lo que no está medido es una petición autenticada de verdad contra la base de
> desarrollo; para eso hace falta una credencial de ese colegio.
>
> **El otro lado también, desde el 20 ago 2026: OPcache está activo en
> producción.** `php -v` en el servidor dice PHP 8.4.24 *with Zend OPcache
> v8.4.24*, y en el panel la extensión `opcache` está marcada. **Con eso el paso
> 1 queda cerrado en los dos lados**, que era la ganancia más grande de todo
> este documento —de 0,25 s a 0,03 s— y la única que valía la pena confirmar
> aunque costara entrar al panel.
>
> **Dónde mirarlo, que no es obvio:** estas cuentas son CloudLinux (se reconoce
> por `/opt/alt/php84/` en el `include_path`), y ahí OPcache es una
> **extensión**, no una opción. En *Select PHP Version* está en la pestaña
> **Extensions**; en **Options** no aparece nunca, por mucho que se busque.

> ### Los límites de PHP de la cuenta, y qué significan para el paso 13
>
> De la misma pantalla, el 20 ago 2026 (cuenta `micolev1`, PHP 8.4):
>
> | Ajuste | Valor |
> |---|---|
> | `max_execution_time` | **300** s |
> | `memory_limit` | **768M** |
> | `post_max_size` · `upload_max_filesize` | 128M |
> | `display_errors` | Off · `log_errors` On |
>
> **Cinco minutos y 768 MB no es un alojamiento apretado.** Eso cambia la
> conversación del paso 13: «los imports dan timeout» se venía diciendo sin
> número, y el techo real es de cinco minutos, no de treinta segundos. Antes de
> construir colas —que son pantalla nueva en los cuatro clientes— hay que saber
> si algún import se acerca siquiera a ese techo, y eso lo dice el registro de
> consultas lentas del paso 3.
>
> `display_errors` en Off y `log_errors` en On es lo correcto, y de paso cubre
> la mitad del 🟢 «verificar APP_DEBUG en producción» del plan de seguridad: aunque
> Laravel tuviera el debug encendido, PHP no está escupiendo errores al cliente.

**Lo que se midió el 17 ago 2026, dentro del contenedor viejo:**

```
$ docker exec 8myvc-app-1 php -m | grep -i opcache
(vacío)

$ docker exec 8myvc-app-1 php -r 'echo function_exists("opcache_get_status") ? "si" : "NO";'
NO
```

La extensión **no existe** en la imagen `kooldev/php:8.0-nginx`. Ni en CLI ni en FPM — comparten el mismo `/usr/local/etc/php/conf.d`, y ahí no hay ningún `.ini` de opcache.

Medido: arrancar el framework compila **609 archivos PHP**, y toma **318 ms** en frío.

```
boot del framework (sin rutas): 318.8 ms
archivos PHP compilados: 609
memoria: 22.0 MB
```

Sin OPcache, PHP **parsea y compila esos 609 archivos en cada petición**. Los tira a la basura al terminar. Y vuelve a empezar en la siguiente.

**Arreglo:**

```dockerfile
RUN docker-php-ext-install opcache
```
```ini
; conf.d/opcache.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1     ; en desarrollo
opcache.revalidate_freq=0
; en producción: validate_timestamps=0 + opcache_reset() en cada despliegue
```

**Impacto esperado: 60–75 % del tiempo de arranque.** Es una línea de Dockerfile.

> ⚠️ **Lo más importante de este documento: verifica producción.**
> Si el servidor de producción también corre sin OPcache, esto por sí solo explica la lentitud que reportan los usuarios, y se arregla hoy sin tocar una línea de PHP.
> ```bash
> php -i | grep opcache.enable
> ```

---

### 2. 🔴 `AdvancedRoute` reflexiona 97 controladores en cada petición · ~45 ms + registro de 1.076 rutas

`AdvancedRoute::controller()` no es una tabla de rutas: es **reflexión en tiempo de ejecución**. En cada petición, `routes/api.php` obliga a PHP a autocargar los 97 controladores (32.477 líneas) y a recorrer sus métodos con `ReflectionClass`.

**Medido en tu contenedor:**

```
clases reflejadas: 97
métodos públicos inspeccionados: 1.818
tiempo autoload + reflexión: 45.0 ms
```

Y luego registra las rutas — **cada una dos veces**. En [`AdvancedRoute.php:73`](../../vendor/lesichkovm/laravel-advanced-route/src/AdvancedRoute.php#L73) llama a `Route::$httpMethod(...)`, y en la línea 79 la vuelve a registrar solo para ponerle nombre:

```php
Route::$httpMethod($slug_path, $controllerClassName . '@' . $methodName);          // ← registro 1
// ...
Route::$httpMethod($slug_path, $controllerClassName . '@' . $methodName)->name($routeName);  // ← registro 2
```

Son **538 rutas útiles registradas como ~1.076 objetos `Route`**, cada uno con su compilación de patrón, en cada petición.

**Arreglo:** Fase 1 del [plan de migración](00-plan-migracion.md#fase-1--quitar-advancedroute--12-días) — rutas explícitas generadas. Elimina la reflexión, elimina el doble registro, y **habilita `route:cache`** (punto 3).

**Impacto esperado: 45 ms directos + hace posible el siguiente punto.**

---

### 3. ✅ No hay caché de rutas ni de configuración · ya se puede activar

> **Resuelto el 18 ago 2026.** `route:list` imprime las 533 rutas y
> `route:cache` funciona. Faltaba sacar `User::fromToken()` de los 24
> constructores; lo hace ahora el trait `Concerns\ResuelveElUsuario`, que
> resuelve `$this->user` en la primera lectura. Queda **poner los `*:cache` en
> el despliegue**, que es lo único que aún no está hecho.
>
> Lo de abajo es cómo estaba.

```
$ ls bootstrap/cache/
.gitignore  packages.php  services.php
```

Faltan `config.php` y `routes-v7.php`. **No se está usando ninguna de las dos cachés.**

Y no es un olvido: **no se pueden activar hoy**. `php artisan route:cache` requiere listar las rutas, y eso falla:

```
$ php artisan route:list
Symfony\Component\HttpKernel\Exception\HttpException: No existe Token
  at app/User.php:85
  App\Http\Controllers\AlumnosController.php:38  → App\User::fromToken()
```

Laravel instancia el controlador para leer su middleware; el constructor llama a `User::fromToken()`; sin token hace `abort(401)`. **El diseño de autenticar en el constructor bloquea las dos optimizaciones más baratas del framework.**

**Arreglo:** hecho. Falta el despliegue, y antes hay que comprobar una cosa:
`bootstrap/cache/` es donde caen las dos cachés, y en producción **hay carpetas
compartidas entre colegios por symlink** —`vendor/` seguro, el resto sin
confirmar—. Si `bootstrap/cache/` estuviera compartida, un colegio serviría las
rutas de otro sin ningún síntoma. Ver [`docs/DESPLIEGUE-REFERENCIA.md`](../DESPLIEGUE-REFERENCIA.md).



```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**Impacto esperado: 30–60 ms adicionales.**

---

### 4. 🟠 La autenticación se ejecuta dos veces, con 5–8 consultas · ~40–80 ms

> **A medias el 18 ago 2026.** El contexto ya se resuelve **una sola vez por
> petición**: el resultado viaja en los `attributes` de la petición, así que el
> guard, el controlador y cualquier método que vuelva a pedirlo comparten la
> misma resolución. Con un test que lo comprueba contando las consultas
> (`ContextoDeUsuarioTest`), que daba 2 antes y da 1 ahora.
>
> **Y una sola vez el token, desde el 19 ago 2026.** Los puntos 1 y 2 de
> "Arreglo" están hechos: `GET api/periodos` pasó de **9 consultas a 7**, y de
> las 7 solo una es del endpoint. Lo cuenta `ConsultasPorPeticionTest`.
>
> El limitador de `RouteServiceProvider` llama a `$request->user()` solo para
> decidir la clave del cubo, y eso resuelve el token entero; después el
> middleware `auth.token` volvía a resolverlo por su cuenta. Ahora la
> resolución se memoriza en los `attributes` de la petición —el mismo sitio
> donde `User::fromToken()` guarda el contexto, y no una propiedad del servicio,
> que sobreviviría a la petición bajo Octane—.
>
> El N+1 de permisos es ahora una consulta con `IN`, con el orden escrito
> (`role_id` en el orden de los roles, `permission_id` dentro de cada uno). Ese
> era el orden que devolvía el bucle viejo por el índice, sin que nadie se lo
> hubiera pedido; el test lo compara contra el algoritmo antiguo con un usuario
> de dos roles.
>
> **Sigue pendiente** la caché del contexto entre peticiones (punto 3) y el
> driver de caché (punto 4).

Aquí sí es la autenticación, tal como sospechabas. Pero el detalle importa.

**Ejecución 1 — el rate limiter.** [`RouteServiceProvider.php`](../../app/Providers/RouteServiceProvider.php):

```php
Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
```

`$request->user()` con el guard por defecto (`api` → driver `jwt`) **dispara la autenticación completa**: parseo del JWT, verificación de firma, `SELECT * FROM users WHERE id = ?`. En **cada petición a la API**, solo para decidir la clave del rate limit.

**Ejecución 2 — el constructor del controlador.** [`AlumnosController.php:36`](../../app/Http/Controllers/AlumnosController.php#L36):

```php
public function __construct() { $this->user = User::fromToken(); }   // ya no existe
```

Y [`User::fromToken()`](../../app/User.php#L59) vuelve a hacerlo **todo desde cero**:

| # | Consulta | Origen |
|---|---|---|
| 1 | `JWTAuth::parseToken()->authenticate()` → `SELECT * FROM users WHERE id = ?` | `User.php:83` |
| 2 | `Periodo::where('actual', true)->first()` (si el usuario no tiene periodo) | `User.php:102` |
| 3 | La consulta monstruo: 4 ramas de `switch`, 5–6 `JOIN`, ~40 columnas contra `alumnos`/`profesores`/`acudientes`/`users` + `matriculas` + `grupos` + `periodos` + `years` + `images` ×3 | `User.php:113-224` |
| 4 | `SELECT r.* FROM roles r INNER JOIN role_user …` | `User.php:306` |
| 5..N | **Una consulta por cada rol**, dentro de un `foreach` | `User.php:314-325` |

```php
foreach($usuario->roles as $role) {
    $permisos = DB::select($consulta, array(':role_id' => $role->id));  // ← N+1
}
```

**Total: 5 a 8 consultas por petición**, ninguna cacheada, repetidas idénticamente en cada llamada del mismo usuario. Con 2.346 filas en `role_user`, hay usuarios con varios roles — cada uno suma una consulta.

**Arreglo (Fase 3 del plan de migración):**

1. **Eliminar la ejecución duplicada.** El rate limiter debe usar el usuario ya resuelto por el middleware, no volver a autenticar.
2. **Colapsar el N+1 de permisos** en una sola consulta con `IN`:
   ```sql
   SELECT DISTINCT pm.name FROM permission_role pmr
     INNER JOIN permissions pm ON pm.id = pmr.permission_id
   WHERE pmr.role_id IN (?, ?, ?)
   ```
   Reduce de 5–8 consultas a 3.
3. **Cachear el contexto de usuario.** Roles, permisos, año, periodo y configuración del colegio **no cambian entre peticiones**. Cachear por `user_id + periodo_id` con TTL de 5–15 min, invalidando cuando cambia el rol o el periodo:
   ```php
   Cache::remember("user.context.{$userId}.{$periodoId}", 900, fn () => $this->build($userId));
   ```
   Reduce de 3 consultas a **0** en el caso normal.
4. **Cambiar el driver de caché.** Hoy es `CACHE_DRIVER=file` sobre un bind mount `:delegated` de macOS — de lo más lento que hay. **La extensión `redis` ya está instalada en el contenedor** y hay un `REDIS_HOST` en el `.env`. Solo falta el servicio en `docker-compose.yml` y cambiar la variable.

**Impacto esperado: de 5–8 consultas a 0–1 por petición.**

> ### El punto 3 (cachear el contexto) hecho el 20 ago 2026 — y **apagado**, porque lo que ahorra es ruido
>
> El plan lo daba por un día de trabajo con un `Cache::remember` de quince
> minutos y lo pintaba en 🟠. Está escrito, probado y con su interruptor, y viene
> **apagado** (`CONTEXTO_SEGUNDOS=0`). El motivo es la medición.
>
> **Medido con el driver `file`, que es el de producción**, 200 resoluciones del
> contexto de un profesor en el docker de desarrollo:
>
> | | por resolución | consultas |
> |---|---|---|
> | Sin caché | 1,41 ms | 3 |
> | Con caché | 0,66 ms | 0 |
>
> **0,75 ms y tres consultas por petición.** Sobre una petición que costaba 250
> ms y que con OPcache aspira a 40–70, eso es menos del 2%: la misma familia que
> el paso 6, que ya quedó marcado como ruido. Encenderlo por defecto en los
> dieciséis colegios sería pagar un techo de obsolescencia sobre la
> configuración del año a cambio de algo que no se nota.
>
> Se queda el código, con el interruptor, para el colegio cuyo registro de
> consultas lentas diga que allí sí paga — que es justo la pregunta que el paso 3
> ahora sabe contestar.
>
> **Lo que se comprobó antes de escribirlo, y sale al revés de lo esperado.** Las
> comprobaciones que de verdad deciden algo NO leen del contexto, releen de la
> base:
>
> | Comprobación | De dónde saca el dato |
> |---|---|
> | El boletín y el paz y salvo | `SELECT pazysalvo FROM alumnos` en el guard — fresco |
> | ¿El profesor puede editar notas o nivelar? | `SELECT * FROM periodos` en `User::pueden_modificar_notas` — fresco |
> | ¿Es acudiente de este alumno? | `SELECT id FROM parentescos` en el guard — fresco |
>
> O sea que cachear **no** deja sin boletín a quien acaba de pagar ni mantiene
> abierta una ventana de notas recién cerrada, que era el miedo razonable. Lo que
> sí queda obsoleto es lo que el frontend pinta con el contexto —los mismos
> flags, pero para decidir si enseña el botón— y los permisos.
>
> **Los permisos son la parte que no podía quedarse a medias**, porque
> `RolesController` decide con `in_array('can_edit_usuarios', $user->perms)`.
> Quitar un rol borra el contexto de esa persona en el acto
> (`ContextoDeUsuario::olvidar`), sin esperar a que caduque. Con test.
>
> **Y la clave lleva dentro el nombre de la base.** Joseth confirmó el 20 ago
> 2026 que `storage/` es propia de cada colegio, así que hoy no hay colisión
> posible; el día que deje de serlo —o que se pase a un Redis compartido— una
> clave `usuario.contexto.5` le serviría al usuario 5 de un colegio el contexto
> del 5 de otro. Cuesta nada dejarlo cerrado de antemano, y lo fija un test.
>
> **El paso 10 (Redis) queda descartado: no hay Redis.** Joseth lo confirmó el 20
> ago 2026. Lo que aparece en la lista de PHP 8.4 de cPanel es `phpredis`, la
> extensión cliente, y sin un servidor corriendo en la cuenta no sirve de nada.
> Da igual: con la medición de arriba, el paso 10 aceleraría una caché que hoy no
> compensa tener encendida.

> ### Y el paso 2 (instrumentación en dev) no necesita paquete nuevo
>
> Pedía Clockwork o Debugbar «para ver consultas por petición y tiempos». Las dos
> cosas ya se pueden sin añadir dependencia: `CONSULTAS_LENTAS_MS=1` en el `.env`
> de desarrollo anota todas las consultas con su ruta, y el conteo por petición
> lo fija `tests/Contrato/ConsultasPorPeticionTest.php`, que además impide que
> vuelva a subir sin que nadie se entere — que es más de lo que da una barra en
> el navegador de una API sin vistas.

---

### 5. 🟠 `QUEUE_CONNECTION=sync` · los procesos pesados bloquean la petición HTTP

Todo corre dentro del request HTTP: importar el Excel de alumnos, generar boletines de un grupo completo, exportar SIMAT, calcular definitivas y puestos.

La tabla `jobs` existe y está vacía. La infraestructura está; nadie la usó.

**Arreglo:** `QUEUE_CONNECTION=database` (o `redis`), convertir a Jobs los importadores y los informes largos, y devolver un identificador de tarea que el frontend consulte. No es urgente para el rendimiento *percibido* de las llamadas normales, pero elimina una clase entera de incidentes por timeout.

> ### Mirado el 20 ago 2026: el paso 13 no depende de escribir los Jobs
>
> Convertir los importadores a Jobs es la parte fácil. Lo que hay debajo son dos
> cosas que no se resuelven en este repo:
>
> **1. ~~Una cola sin worker no es una cola~~ — resuelto el mismo día: sí hay
> cron.** Joseth lo miró en el panel de A2 Hosting y está ahí. La duda venía de
> `App\Console\Commands\LimpiarSesiones`, que decía «no hay garantía de que
> corra el cron», y era infundada: bastó abrir la pantalla.
>
> Con eso queda puesto un cron por colegio —`schedule:run` cada minuto, y lo
> demás se decide en `app/Console/Kernel.php`— y `sesion:limpiar` ya corre solo.
> Un worker de cola es el mismo mecanismo: `queue:work --stop-when-empty` desde
> el scheduler. **Deja de ser un bloqueo y pasa a ser trabajo.**
>
> Lo que sigue mereciendo cuidado: si el cron de un colegio no está puesto, un
> import encolado no falla, se queda ahí — y eso es peor que el timeout de hoy,
> porque nadie se entera. Encolar exige antes comprobar el cron colegio por
> colegio, y que la cola avise cuando no se vacía.
>
> **2. Cambia el contrato con los clientes, y son cuatro.** Hoy el importador
> responde con el resultado; encolado responde con un identificador y el cliente
> tiene que preguntar. Eso es trabajo en `myvc_front`, en `myvc_front_2` y en la
> app de Flutter —que es **una sola para los dieciséis colegios**, así que no se
> puede escalonar—. No es una optimización interna: es una funcionalidad nueva
> con pantalla.
>
> **Lo que sí se puede hacer sin nada de eso**, si el problema real es que un
> import grande se corta: subir `max_execution_time` para esas rutas y trocear el
> importador para que reporte avance. Pero antes de tocarlo conviene saber si de
> verdad pasa: el registro de consultas lentas (paso 3) dice cuánto tarda cada
> ruta en cada colegio, y hasta ahora "los imports dan timeout" es una impresión,
> no una medición.
>
> **Hecho el 20 ago 2026, y por ese camino.** El importador de alumnos es
> reanudable: si se corta, volver a subir el mismo archivo continúa por donde
> iba. No cambia el contrato de ningún cliente —sigue respondiendo
> `'Importados.'`— así que no depende de la pantalla nueva ni del cron. El
> porqué de cada decisión está en [09 §1](09-pendientes.md).
>
> Y con ello llega la medición que faltaba, sin instrumentar nada: la tabla
> `importaciones` guarda `inicio`, `fin` y `filas` de cada una, así que
> «cuánto tarda un import» deja de ser una impresión en cuanto pase una
> temporada de matrículas. Ese número es el que decide si
> `max_execution_time` puede bajar de los 300 s — y de paso, si el paso 13
> sigue haciendo falta.
>
> **Contestado el 20 ago 2026: sí hay cron.** Así que el paso 13 es posible. Lo
> que queda por decidir ya no es técnico: es si vale la pena la pantalla nueva en
> los cuatro clientes para un problema —los timeouts en los imports— que todavía
> nadie ha medido.

---

## Lo que hay que medir antes de seguir optimizando

Todo lo anterior está medido. Lo siguiente **no**, y no conviene tocarlo a ciegas:

### Índices en las tablas grandes

| Tabla | Filas |
|---|---|
| `notas` | **1.163.307** |
| `notas_finales` | 127.810 |
| `ausencias` | 52.118 |
| `subunidades` | 37.197 |
| `unidades` | 18.724 |
| `definiciones_comportamiento` | 13.409 |
| `frases_asignatura` | 11.446 |

Con 1,16 millones de filas en `notas`, un índice ausente en la consulta de boletines es la diferencia entre 50 ms y 8 segundos. **Pero no adivines.** El método:

1. Activar el log de consultas lentas de MySQL (`long_query_time = 0.5`) durante una semana en producción.
2. `EXPLAIN` sobre las que salgan.
3. Crear solo los índices que el `EXPLAIN` justifique.

Añadir índices a ciegas en una tabla de 1,16 M de filas ralentiza las escrituras y ocupa disco sin garantía de ganancia.

> ### Hecho el 20 ago 2026, y el punto 1 no se podía hacer como está escrito
>
> **El `slow_query_log` de MySQL no está a nuestro alcance**: los colegios viven
> en cuentas de cPanel compartidas, sin `my.cnf` ni `SET GLOBAL`. El paso 3 se
> montó entonces dentro de la aplicación —`App\Support\ConsultasLentas`, que se
> enciende con `CONSULTAS_LENTAS_MS` en el `.env` de cada colegio— y de paso da
> algo que el registro de MySQL no da: **qué ruta hizo la consulta**. Con 538
> rutas y 990 consultas crudas, un SQL suelto en un log no dice a quién ir a
> mirar. Lo agrupa `tools/consultas-lentas.py`, por tiempo TOTAL y no por la
> consulta más lenta.
>
> Va **apagado**, y anota la forma de la consulta pero **no sus valores**: por
> ahí pasan nombres y fechas de nacimiento de menores y el fichero cae en un
> disco compartido. Las dos reglas llevan test.
>
> **Y hubo una medición que no hacía falta esperar.** Lo que decide si un índice
> FALTA no es el volumen: es que `possible_keys` venga vacío en el EXPLAIN —que
> no exista ningún índice que el optimizador pudiera considerar—, y eso es una
> propiedad del esquema. Se mide igual con el seed que con la base de un colegio;
> lo que cambia con las filas es cuánto cuesta el escaneo, no si el índice está.
>
> Así que se midió con lo que ya había: `EXPLICAR_CONSULTAS` anota las consultas
> que ejecuta la suite de contrato (493 distintas) y `tools/indices-que-faltan.php`
> les pasa EXPLAIN. **16 tablas** filtran por columnas sin ningún índice detrás.
>
> **Entraron tres**, con el criterio de que fallara *todo* lo siguiente: ningún
> índice para esa columna, tabla que crece sin techo, y consulta en un camino que
> se recorre muchas veces.
>
> | Índice | Por qué | Dónde vive la consulta |
> |---|---|---|
> | `parentescos(alumno_id, acudiente_id)` y `(acudiente_id)` | Sin un solo índice. Es el guard que cerró los 27 IDOR: recorría la tabla entera **en cada petición de un acudiente** para contestar sí o no | `ExigirPersonaPropia`, `ExigirBoletinPropio` |
> | `frases_asignatura(alumno_id, asignatura_id, periodo_id)` | 11.446 filas y ningún índice. No se llama una vez por boletín: **una por asignatura de cada alumno** | `FraseAsignatura::deAlumno()` |
> | `images(user_id)` | Cada foto de cada alumno y profesor de todos los años, sin ningún índice secundario | galería del perfil, logo del colegio |
>
> **Lo único medido en tiempo, y bajo qué condiciones.** Con 11.446 filas —las
> mismas que tiene producción— y las 360 consultas que son una tanda de boletines
> de un grupo de treinta con doce asignaturas: **970 ms sin el índice, 44 ms con
> él.** Es un banco sintético en el MySQL de desarrollo, no un colegio; lo que
> mide bien es la forma de la ganancia, no el número exacto.
>
> **Las otras trece se quedan fuera, y el motivo es la parte interesante.**
> Catálogos de nueve filas (`years.actual`) y columnas de dos valores
> (`images.publica`, `users.is_active`) no ganan nada con un índice. Y
> `bitacoras` enseña dónde está el límite de EXPLAIN: se lee en una pantalla de
> administración de vez en cuando y se le **INSERTA en cada petición** que pasa
> por un guard. El índice se pagaría siempre y se cobraría rara vez, y esa cuenta
> no la decide EXPLAIN — la decide el registro de consultas lentas de producción,
> que es justo para lo que está.

### Instrumentación en desarrollo

Instalar en `require-dev` una de estas para ver consultas por petición y tiempos:

- **Laravel Debugbar** — la más rápida de montar
- **Clockwork** — mejor para una API sin vistas (extensión de navegador + endpoint)
- **Telescope** — más completo, más pesado

Sin esto, "está lento" no es accionable. Con esto, cada endpoint reporta su conteo de consultas.

---

## Plan de ejecución

| # | Acción | Esfuerzo | Impacto esperado | Depende de |
|---|---|---|---|---|
| 1 | **Instalar y configurar OPcache** (dev **y producción**) | 1 h | 🔴 **150–200 ms/petición** | nada |
| 2 | ~~Instrumentación (Clockwork/Debugbar) en dev~~ · **no hace falta paquete**: ver la nota del §4 | 1 h | medición | nada |
| 3 | ~~Log de consultas lentas en producción, 1 semana~~ · **montado el 20 ago 2026**, falta encenderlo | 30 min | medición | nada |
| 4 | Quitar `AdvancedRoute` → rutas explícitas | 1–2 d | 🔴 45 ms + doble registro | Fase 1 |
| 5 | ~~Sacar `fromToken()` de los constructores~~ · **hecho 18 ago 2026** | 1 d | habilita el paso 6 | Fase 2 |
| 6 | `route:cache` + `config:cache` en despliegue | 2 h | ~~🔴 30–60 ms~~ · **medido: ruido** (0,031 s con, 0,028 s sin) — la ganancia ya la dieron la Fase 1 y OPcache | paso 5 |
| 7 | ~~Eliminar la doble autenticación (rate limiter)~~ · **hecho 19 ago 2026** | 2 h | 🟠 **2 consultas menos, medidas** | Fase 3 |
| 8 | ~~Colapsar el N+1 de permisos~~ · **hecho 19 ago 2026** | 1 h | 🟠 N-1 consultas | Fase 3 |
| 9 | ~~Cachear el contexto de usuario~~ · **hecho el 20 ago 2026 y apagado** | 1 d | ~~🟠 3 consultas → 0~~ · **medido: 1,41 → 0,66 ms, ruido** | Fase 3 |
| 10 | ~~Redis como caché y sesión~~ · **descartado**: no hay servidor Redis (Joseth, 20 ago 2026); en cPanel solo está la extensión cliente | 2 h | — | paso 9 |
| 11 | PHP 8.0 → **8.4** | incluido en Fase 4 | 🟡 10–20 % | Fase 4 |
| 12 | Índices según el `EXPLAIN` · **tres puestos el 20 ago 2026**, el resto espera al paso 3 | variable | 🟠 **medido: 970 ms → 44 ms** en una tanda de boletines | paso 3 |
| 13 | Colas para importadores e informes · **posible desde el 20 ago 2026** (sí hay cron); lo que frena es que cambia el contrato de los cuatro clientes. La importación de alumnos ya es **reanudable** sin tocar ese contrato ([09 §1](09-pendientes.md)), y su tabla es la medición que este paso pedía | 2–3 d | 🟡 elimina timeouts | medir antes |

**Los pasos 1, 2 y 3 no dependen de la migración. Hazlos esta semana.** El paso 1, solo, puede resolver la mayor parte de lo que percibes como lentitud.

---

## Meta

Con los pasos 1, 4, 6, 7, 8 y 9 completos:

| | Hoy (medido) | Meta |
|---|---|---|
| 404 / arranque | 250 ms | **20–40 ms** |
| Endpoint autenticado simple | 250 ms + 5–8 consultas | **40–70 ms + 0–1 consulta** |

Ese es el orden de magnitud realista: **de ~5 peticiones por segundo a ~25–50**, sin tocar la lógica de negocio y sin reescribir ninguna de las 990 consultas crudas.

---

## Nota sobre Octane / FrankenPHP

Es la respuesta moderna de Laravel al rendimiento (la app queda en memoria entre peticiones, arranque ≈ 0). Con Octane, el problema #1 y el #3 de este documento **desaparecen por completo**.

**Era una mina antipersona con este código, y el 19 ago 2026 quedó una sola
mina.** `App\User` tenía cinco propiedades estáticas mutables:

```php
public static $nota_minima_aceptada = 0;
public static $images = '';          // borradas: no las leía nadie
public static $perfilPath = '';      // borradas
public static $imgSharedPath = '';   // borradas
public static $intentoLogueoPorActive = 0;   // ahora es estado de la petición
```

En un worker persistente esas propiedades se filtran entre peticiones de
usuarios distintos. De las cinco:

- **Las tres de rutas de imágenes estaban muertas.** Se escribían en cada
  petición —`'images/'`, `'images/perfil/'`, `'images/shared/'`, siempre lo
  mismo— y no las leía nadie en todo el repo. Fuera.
- **`$intentoLogueoPorActive` era la peligrosa de verdad**, y no por Octane: es
  un guardia contra la recursión que se ponía a 1 y **no lo reiniciaba nadie**.
  El primer usuario que pasara por ahí lo dejaba puesto para el worker entero, y
  a partir de ese momento todos recibirían `user_inactivo_por_mucho_logueo` sin
  haber reintentado nada. Ahora vive en los `attributes` de la petición, y hay
  un test que lo ve **hoy, sin Octane**, porque PHPUnit corre las dos peticiones
  en el mismo proceso — que es la condición que Octane crea en producción.
- **`$nota_minima_aceptada` se queda.** La leen 26 sitios del cálculo de notas,
  desde métodos estáticos de `Subunidad` y `Asignatura` que no reciben usuario.
  Sacarla de ahí es tocar el cálculo de notas, y eso es lo que el §5 del plan de
  migración protege. Es una decisión, no una limpieza.

**Octane sigue esperando a esa última.** OPcache + caché de rutas da la mayor
parte de la ganancia sin ese riesgo, y ya está dando.


---

## Las dos series se tapan una a la otra — 21 ago 2026

`tools/indices-que-faltan.php` mide **las consultas que la suite ejecuta**. Está
en la primera línea de su cabecera y aun así es fácil leer su salida como «no
falta ningún índice», cuando lo que dice es «no falta ninguno **en lo que hay
comprobado**». Ese día había **194 rutas sin un solo test**
(`tools/cobertura-de-rutas.py`), o sea que el 36% de la API queda fuera de la
medición de índices — y no estar en la lista se lee igual que estar bien.

El caso concreto, encontrado por el barrido y no por ninguna de las dos
herramientas: `GET api/ChangesAsked/to-me` —la pantalla de inicio del
superusuario y del profesor— hace

```sql
SELECT * FROM bitacoras
WHERE affected_element_type="intento_login" AND affected_person_name=?
  AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 50
```

y `bitacoras` solo tiene índice por `id` y por `historial_id`. `EXPLAIN` da
**`type=ALL, possible_keys=NULL`**: candidato de manual, y nunca ha salido en la
lista porque ningún test golpea esa ruta.

**No se crea el índice**, y por lo de siempre: en la copia de desarrollo
`bitacoras` tiene 59 filas y no cuesta nada, pero ahí se escribe **un intento de
login fallido por cada uno** y cuánto ha crecido depende de cada colegio. Sin el
paso 3 —`CONSULTAS_LENTAS_MS` una temporada en producción— crear un índice aquí
es exactamente lo que este documento lleva prohibiendo desde la primera página.

Lo que sí queda hecho es escribirlo en la cabecera de la herramienta, para que el
que la corra sepa qué **no** está mirando. Y la regla que se lleva: **subir la
cobertura no solo encuentra fallos, también amplía lo que las demás herramientas
pueden ver.** Las dos series no son independientes.


---

## El paso 3 sigue apagado, y ahora por una razón distinta — 21 ago 2026

Se le preguntó a Joseth si encender `CONSULTAS_LENTAS_MS` en producción, que es
lo que desbloquea crear índices con fundamento y bajar el `max_execution_time`
de 300 s. La respuesta cambia el marco y por eso se escribe aquí:

> **«Quiero refactorizar o hacer de nuevo ese importador de alumnos después, con
> tu ayuda, cuando terminemos la migración.»**

O sea que el importador —que es el motivo por el que `max_execution_time` está en
300 s— **no se va a optimizar: se va a rehacer**. Y medir lo que tarda hoy un
código que va a desaparecer no vale la pena. Lo que queda dicho:

- **`CONSULTAS_LENTAS_MS` se queda apagado**, y no por precaución sino porque la
  pregunta que iba a contestar ya tiene otra respuesta.
- **El índice de `bitacoras` de la sección anterior sigue sin crearse**, por lo
  mismo de siempre: sin números de producción es adivinar. Queda escrito el
  `EXPLAIN` para el día que haga falta.
- **La importación reanudable de la [§1 de 09-pendientes](09-pendientes.md) no se
  toca.** Es de ayer y funciona; lo que se rehará es el importador que hay debajo,
  y la tabla `importaciones` seguirá contando lo mismo con el código nuevo.
- Cuando llegue ese momento, lo que **sí** hay que traer a la mesa es lo que ya
  está medido: los 300 s de cPanel, los 768M de `memory_limit`, la transacción
  por fila que evita reprocesar, la idempotencia por documento y la huella sha256
  del contenido. Todo eso está en la §1 de `09-pendientes.md` y no hay que volver
  a averiguarlo.

---

## Recalcular la definitiva en siete sitios: **no pesa** — 24 ago 2026

La pregunta la hizo Joseth al cerrarse la [fase 3 de las
definitivas](10-definitivas.md), y es la correcta: *«¿no es muy pesado recalcular
la definitiva en cada uno de estos lugares?»*. Siete disparadores pasaron a
recalcular, y uno de ellos —`putUpdate`— corre **en cada nota que teclea un
profesor**.

Medido con [`tools/coste-del-recalculo.php`](../../tools/coste-del-recalculo.php),
sobre las diez asignaturas con más notas de la copia de desarrollo (hasta 47
alumnos y 986 notas), medianas de 21 pasadas:

| | media | peor |
|---|---|---|
| `calcular()` — la agregada sobre la asignatura | **1,70 ms** | 2,17 ms |
| `selloDeVersion()` | **1,93 ms** | 3,44 ms |
| `estaDesactualizada()` | **0,68 ms** | 2,40 ms |

**Una nota tecleada cuesta `calcular` + `sello` + un UPSERT: unos 4 ms**, contra
los ~40–80 ms que la §4 mide **sólo para resolver quién pregunta**. O sea que el
recálculo **no es lo caro de esa petición ni de lejos**, y la respuesta a la
pregunta es que no hace falta tocar nada.

### Y el 3× que no existía, que es la parte que hay que leer

El plan de definitivas dejaba escrita una salida por si salía caro: *«recalcular
solo la fila de ese alumno, que es lo que cambió»*. Hoy `recalcularPorNota` pide
un alumno pero `calcular()` agrega el grupo entero y **filtra en PHP**, así que
parecía dinero tirado.

La primera medición —**una pasada por asignatura, en orden fijo**— dio **123,8 ms
para el grupo contra 42,5 ms para un alumno**. Un 3× limpio, y se llegó a escribir
el estrechamiento del SQL.

**Era la caché.** La primera consulta calentaba el buffer pool y la segunda
cobraba el beneficio. Alternando el orden y tomando medianas:

```
grupo entero  1,87 ms      un alumno  1,48 ms      →  1,26x
filas leídas  1.753        filas leídas  1.669     →  5%
```

Y el `EXPLAIN` dice por qué el 5%: el plan entra por
`notas_subunidad_id_foreign`, o sea que **recorre las notas de cada subunidad y
descarta las de los otros alumnos después**. El filtro por alumno no evita la
lectura, sólo la suma.

O sea que estrechar ahorra **~0,35 ms por pulsación**. Este documento ya decidió
no encender `CONTEXTO_SEGUNDOS` porque ahorraba **0,75 ms**; por la misma vara,
esto es ruido. **El estrechamiento se escribió, se midió y se revirtió**, y queda
aquí para que nadie lo reintente creyendo que hay un 3× esperando.

> Tres lecciones, y la del medio es la cara:
>
> 1. **Medir una vez es no medir.** Una sola pasada mide el estado de la caché.
> 2. **Un orden fijo entre dos variantes las compara con la caché del otro.** La
>    segunda siempre parece mejor. Hay que alternar.
> 3. **Las filas leídas no dependen de la caché y el tiempo sí.** Cuando las dos
>    no cuentan la misma historia, la que miente es la del tiempo.

### Lo que sí quedó, y no es una optimización

`putUpdate` devuelve ahora **la definitiva recalculada dentro de su propia
respuesta**. Eso no ahorra milisegundos de base: ahorra **una petición HTTP
entera por nota tecleada**, que es lo que la planilla necesitaba para repintar la
celda. Es un campo añadido —la nota se sigue devolviendo igual—, y el front tiene
que seguir sabiendo re-pedirla cuando no venga, porque durante el despliegue
habrá colegios con el código viejo.

---

## El contador que tumbaba los colegios era `Entry Processes` — 24 ago 2026

**Confirmado por Joseth**, que lo miró en el panel durante las caídas. Lo escribe
aquí porque cambia qué se optimiza y en qué orden, y porque hasta hoy la causa de
aquellos meses era una impresión sin nombre.

### Qué mide, y por qué llegaba al 100%

La cuenta es CloudLinux (se reconoce por `/opt/alt/php84/`, ver el §1), y ahí
cada cuenta de cPanel vive dentro de una **LVE** con varios límites a la vez:

| Contador | Qué cuenta | El límite de esta cuenta |
|---|---|---|
| **Entry Processes** | peticiones HTTP **dentro de PHP en este instante**. Suma 1 al entrar, resta 1 al salir la respuesta | **50** |
| Number of Processes | *todos* los procesos del usuario: hijos de PHP-FPM incluidos los ociosos, cron, una sesión SSH | 75 |

Cuando se pasa de 50, el servidor contesta **508 Resource Limit Reached** a
**todo lo de esa cuenta** — que es por qué se caían los dieciséis a la vez y
costaba entrar al propio cPanel.

**Y no mide usuarios: mide `llegadas por segundo × duración de cada petición`.**
Con 50 ranuras, una petición de 250 ms satura a 200 peticiones/s y una de 3 s
satura a 16. **La palanca es la duración**, no el tráfico.

> **El medidor en reposo no dice nada.** `0 / 50 (0%)` es una lectura
> instantánea: marca 0 siempre que en ese milisegundo no haya ninguna petición
> ejecutándose. Lo que sí queda es el histórico de *faults* por límite en
> *Resource Usage* del panel, y ahí se distingue EP de NPROC de CPU de IO.

### Por qué hoy es otra conversación

Todo lo cerrado en este documento **era, sin saberlo, trabajo sobre ese
contador**, porque todo él acorta lo que dura una petición:

| | Antes | Ahora |
|---|---|---|
| arranque, que paga **cada** petición (§1, OPcache) | 250 ms | **28 ms** |
| `AdvancedRoute`: 97 controladores reflexionados y 1.076 rutas por petición (§2) | sí | eliminado |
| doble autenticación y N+1 de permisos (§4, pasos 7 y 8) | 5–8 consultas | reducidas |
| una tanda de boletines, con los tres índices del 20 ago (paso 12) | 970 ms | **44 ms** |

El primero solo divide por nueve la parte que paga toda petición. **El tráfico
que llenaba las cincuenta ranuras entonces ocupa hoy una fracción.**

Y lo estructural, que Joseth ya resolvió comprando el segundo alojamiento: **el
límite es por cuenta de cPanel, no por sitio.** Dieciséis colegios en una cuenta
comparten las mismas cincuenta ranuras, así que el que importaba el Excel tumbaba
a los otros quince. La regla que queda: **si un colegio es pesado, lo que
necesita es su propia cuenta, no sólo su propia base de datos.**

### La regla que se lleva, y que reordena lo que viene

> **En `Entry Processes`, quitar una petición vale más que hacerla rápida.**

Una petición ocupa su ranura desde que entra hasta que sale, valga 4 ms o 400. Por
eso cuentan como mejora de recursos dos cosas que no ahorran ni un milisegundo de
base de datos: `putUpdate` devolviendo la definitiva en su propia respuesta, y
`putLote` sustituyendo cuarenta y cinco peticiones por una. La segunda es el
[plan 20](20-pantalla-de-notas.md).

### El orden decidido, 24 ago 2026

Joseth lo fijó el mismo día. **No se re-litiga:**

| | Qué | Dónde |
|---|---|---|
| 1 | **Historiales y bitácora** | [18-auditoria.md](18-auditoria.md) |
| 2 | **Las llamadas del panel de inicio** — `GET api/ChangesAsked/to-me` recorre `bitacoras` entera (`type=ALL, possible_keys=NULL`) en la primera pantalla del superusuario y del profesor, y esa tabla crece un intento de login fallido cada vez | la sección «Las dos series se tapan una a la otra» de este documento |
| — | La **planilla de notas por lotes** no depende de ninguna de las dos y puede ir en paralelo | [20-pantalla-de-notas.md](20-pantalla-de-notas.md) |

**El paso 2 va después del 1 a propósito y no por capricho de orden:** lo que el
plan de auditoría decida sobre `bitacoras` —si se sustituye, si se purga, si
cambia de forma— decide también qué índice tiene sentido crear para esa pantalla.
Crearlo antes es indexar una tabla que quizá deje de existir.

### El importador de alumnos: fuera de esta conversación

Puede retener una ranura hasta **300 s** (el `max_execution_time` de la cuenta),
que es lo más largo que hay en toda la aplicación. **Y aun así no es prioritario:
Joseth confirmó el 24 ago 2026 que importar alumnos se hace una vez al año.** Una
ranura de cincuenta, unos días al año, no es lo que tumbó nada.

Sigue en pie lo ya decidido: **ese importador no se optimiza, se rehace** (ver
«El paso 3 sigue apagado» más arriba, y la [§1 de 09-pendientes](09-pendientes.md)
con lo que hay que traer a la mesa ese día). La importación reanudable no se toca.

## `PUT notas/lote` cronometrado — noche del 24 ago 2026

Lo midió `8myvc-ad`. Cierra el hueco que el [20](20-pantalla-de-notas.md) §5.c
declaraba como *«estimado»*, y **la forma de medirlo importa tanto como el número**,
porque se tomó en una máquina que estaba al 97% de swap.

**Una columna de 45 notas: entre 3,8× y 5,9× más rápida en un lote que en 45
peticiones sueltas. Y de 717 consultas a 220.**

| | corrida 1 | corrida 2 |
|---|---|---|
| 45 × `notas/update` | 3.845 ms | 8.729 ms |
| 1 × `notas/lote` | 1.018 ms | 1.928 ms |
| **razón (mediana)** | **3,8×** | **4,5×** |
| consultas | **717 → 220** | **717 → 220** |

Población: 45 notas = una subunidad × 45 alumnos, grupo `Cuarto`, asignatura 1239,
periodo 31, base propia. **11 pasadas, cuatro bloques rotando de orden en la misma
ventana, mediana.**

### Las tres cosas de método, que es lo que hace releíble el número

1. **Los milisegundos absolutos se duplicaron entre las dos corridas y la razón
   aguantó, con las consultas idénticas al dígito.** Ésa es la demostración de por
   qué aquí se compara contra uno mismo en la misma ventana: **la razón sobrevive a
   la carga y el absoluto no**, y `717 → 220` no depende del swap en absoluto.
2. **La carga no exagera esta ventaja: la esconde.** Se suma casi igual a los dos
   lados, y una suma igual a los dos lados **acerca cualquier razón a 1**. Todo lo
   de arriba es **cota inferior** — y además por CLI no se paga el arranque del
   framework por petición (~28 ms × 45 contra × 1), ni php-fpm, ni la red.
   *(Y los absolutos están contaminados por otra cosa: en esa ventana había cinco
   `ng test` de `myvc_front` vivos a la vez. Las razones, no.)*
3. **El limitador se apagó durante el cronómetro a propósito**, porque **un 429 es
   rapidísimo** y con él puesto **el caso roto habría sido el que mejor midiera**.
   Se mide en su propio bloque, y cada respuesta del bloque suelto se comprueba 200.

### Lo que confirma del plan, y lo que le corrige

**Confirma** que el coste fijo de resolver quién pregunta es el término grande:
medido con un bloque de control (45 × `GET periodos`, la misma ruta del §4), sale
**6 consultas por petición** y el **47%–69%** del tiempo de una `notas/update`.

> **⚠️ Este reparto no cuadra y está sin resolver.** Se publicó como *«de las **497**
> consultas que el lote ahorra, ~264 son el camino común y ~260 el recálculo: mitad y
> mitad»* — **y 264 + 260 = 524, no 497: sobran 27.** El **497** sí cierra
> (**717 − 220**), así que **el que falla es uno de los dos sumandos**. Lo encontró
> `8myvc-f8` revisando el documento; **no se toca ninguno de los dos porque adivinar cuál
> mover sería inventar**, y la medición es de otra sesión. **El reparto «mitad y mitad»
> sobrevive como orden de magnitud; los dos números exactos, no.**

**Corrige** una frase que ya circulaba: *«lo caro no es el recálculo, son 1,7 ms»*
es cierto de `calcular()` y **falso del recálculo entero** —`recalcularPorNota` son
~6 consultas por nota—. De las **497 consultas que el lote ahorra, ~264 son el
camino común y ~260 el recálculo**: **mitad y mitad**. No cambia ninguna decisión;
cambia dónde mirará el próximo que quiera ahorrar consultas.

### La corrida limpia: **5,3×**, y el reparto que corrige a la sucia — 25 ago 2026

Repetido con **cero huérfanos comprobados dentro del contenedor** y `load 1,94–2,26`.
**El propio cronómetro bajó de 322 s a 16 s**, que es otra medida de lo que era la
ventana mala.

| | |
|---|---|
| 45 × `PUT notas/update` | **344,4 ms** |
| 1 × `PUT notas/lote` | **64,8 ms** |
| **razón** | **5,3×** (y 5,3× también entre las mejores pasadas) |
| consultas | **717 → 220**, idéntico en las tres corridas |

**La predicción estaba escrita antes de medir** —*«la razón subirá, y por debajo de
3,8× hay algo que no entiendo»*— y salió **5,3×**. Y `717 → 220` **salió igual con la
máquina al 97% de swap y sin ella**: la razón y las consultas sobreviven a la carga,
los milisegundos no.

```
Una `notas/update` cuesta 7,65 ms:
  resolver quién pregunta ..................... 4,06 ms   53%
  recalcular la definitiva de esa nota ........ 2,68 ms   35%
  lo demás (UPDATE, bitácora, serializar) ..... 0,91 ms   12%
```

**Y la corrida limpia corrige a la sucia por un factor de 16:** el recálculo por nota
que se había reportado como **42,9 ms** son **2,68 ms** — y ese 2,68 **cuadra con
`coste-del-recalculo.php`** (1,70 ms el agregado). O sea que **la corrida sucia no
medía el recálculo: medía el swap**, y la limpia **valida esa herramienta**.

> ### Aviso sobre los ~40–80 ms de la §4
>
> Esa cifra es de **otro entorno** y **no se puede citar como si fuera de esta
> máquina**: aquí el camino de resolver quién pregunta cuesta **4,06 ms**, un orden de
> magnitud menos. No es que la §4 esté mal —son dos entornos, y aquí **no se paga el
> arranque del framework**, que la §4 cuenta aparte, ni php-fpm, ni la red—. **Lo que
> la medición limpia establece es el reparto (53 / 35 / 12), no los milisegundos.**
>
> Y con eso, la corrección al [20](20-pantalla-de-notas.md) sigue en pie con números
> limpios: **el coste fijo es el término mayor, pero el recálculo es un tercio largo**,
> y **en consultas está mitad y mitad**.
