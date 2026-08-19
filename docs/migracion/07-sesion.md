# La sesión — Fase 3

> Reemplaza `tymon/jwt-auth` por `laravel/sanctum`.
> Documentos hermanos: [00-plan-migracion.md](00-plan-migracion.md) §2.4 · [04-auditoria-autenticacion.md](04-auditoria-autenticacion.md)
> Código: [`app/Services/Sesion.php`](../../app/Services/Sesion.php) · [`config/sesion.php`](../../config/sesion.php) · [`tests/Contrato/SesionTest.php`](../../tests/Contrato/SesionTest.php)

---

## 0. Para qué existe esta fase

Dos cosas que hoy no se pueden hacer, y una que bloquea el resto de la migración.

**Cerrar sesión no cierra nada.** `PUT login/logout` escribía la hora en
`historiales` y ya. El JWT seguía valiendo **24 horas más**. Quien copiara el
token —o quien se sentara después en el equipo compartido de la sala de
profesores— seguía dentro. Un JWT no se puede revocar: esa es su gracia y ese es
su problema.

**No hay forma de renovar.** El token dura 24 h y al expirar el usuario sale
expulsado sin aviso, en mitad de lo que estuviera haciendo.

**Y `tymon/jwt-auth` es el bloqueante duro del salto de framework.** Está
instalado como `dev-develop` —por eso el `composer.json` lleva
`"minimum-stability": "dev"`— y solo declara soporte hasta `illuminate ^9`. Sin
quitarlo no hay Laravel 10, ni 11, ni 13.

---

## 1. Una sesión son dos tokens

| | Acceso | Refresco |
|---|---|---|
| Dónde viaja | En **cada** petición | Solo a `POST /api/auth/refresh` |
| Cuánto vive | 60 min | 14 días |
| Se puede renovar a sí mismo | **No** | Rota en cada uso |
| Habilidad (`abilities`) | `acceso` | `refrescar` |

**Por qué dos y no uno.** La propuesta inicial de la sesión de `myvc_front` era
lo que hace el refresh de JWT: presentar el token de acceso ya caducado y
recibir otro. Eso significa que el token que viaja a todas partes —el más
expuesto— **sigue sirviendo después de caducar**, durante toda la ventana de
refresco. No caduca: lo parece. Separándolos, lo que se expone en cada petición
muere en una hora, y lo que dura catorce días solo se manda a una ruta.

Los dos comparten el campo `name` de la tabla, que identifica la **sesión**
(`web:<uuid>`). Por eso cerrar sesión borra el par con un solo `DELETE`, y por
eso una sesión del móvil se puede cerrar sin tocar la del portátil.

---

## 2. El contrato

Acordado con la sesión de `myvc_front` (19 ago 2026), que lo está
implementando en su fase 7b.

```
POST /api/auth/login            sin token
  cuerpo: { username, password }
  200: { el_token, refresco, expira_en, cambia_anio?, usuario }
  422  falta username o password
  400  { error: 'invalid_credentials' } | { message: 'Usuario invalidado' }
  429  { error: 'too_many_attempts', segundos }

POST /api/auth/refresh          sin guard; el REFRESCO en Authorization: Bearer
  sin cuerpo
  200: { el_token, refresco, expira_en }      el refresco rota
  401  refresco ausente, inválido, caducado, ya rotado (pasada la gracia),
       o el de acceso mandado por error
  400  { error: 'user_inactivo' }

POST /api/auth/logout           Bearer = acceso (vale caducado)
  200: { ok: true }             borra el par entero

POST /api/auth/logout-all       Bearer = acceso
  200: { ok: true, borrados: N }

GET  /api/auth/me               Bearer = acceso
  200: lo mismo que POST /api/login, votaciones pendientes incluidas
```

- `el_token` se llama así, y no `access_token`, a propósito: es la clave que ya
  devuelve `login/credentials` y que el frontend lleva años leyendo. Un cliente
  que solo la guarde y la mande como `Bearer` no nota el cambio.
- `expira_en` son **segundos** de vida del token de acceso. De ahí sale cada
  cuánto renueva el frontend (a la mitad, unos 30 min). Viene 3599, no 3600.
- `usuario` es el contexto completo —las 47 claves— para ahorrar la segunda
  vuelta a `POST /api/login`. Es el mismo código, no una copia.

### El formato del token cambia

De un JWT (`eyJ0eXAi....firma`) a uno de Sanctum (`17|hGIEXdY6Dq1...`).

**Si algún cliente decodifica el token para leer `exp`, deja de poder.** No hay
nada dentro: es un identificador y un secreto. Para programar la renovación, se
usa `expira_en`.

---

## 3. La gracia del refresco

El refresco rota: al usarlo, muere y sale uno nuevo. Eso, tal cual, **cierra
sesiones solo**:

> La pestaña A renueva a las 12:00:00 y guarda el par nuevo. La pestaña B, que
> tenía el refresco anterior, renueva a las 12:00:01 → 401 → a login. El usuario
> pierde la sesión sin haber hecho nada, y de forma intermitente.

No es hipotético: en informes se trabaja con varias pestañas abiertas —el
listado en una y el certificado en otra—. Lo levantó la sesión de `myvc_front`.

**Solución: 30 segundos de gracia** (`SESION_GRACIA_REFRESCO`). Al rotar, el
refresco viejo no se borra; se le pone `expires_at` a 30 segundos vista y se
apunta en `reemplazado_por` cuál lo sustituyó. Durante esa ventana se sigue
aceptando.

Un matiz que importa para el cliente: **devuelve un par NUEVO, no el que se
emitió la primera vez.** De un token solo se guarda su hash, así que el par que
recibió la pestaña A no se puede volver a emitir. Las dos pestañas acaban con
pares distintos y los dos válidos — y no se pierde nada, porque **al rotar no se
borra el token de acceso anterior**: sigue vivo hasta que caduque solo.

Pasada la gracia, presentar un refresco ya rotado es 401 y queda escrito en
`bitacoras` con `affected_element_type = 'refresco_reutilizado'`.

**No se cierra la sesión entera al detectarlo**, aunque es lo que recomienda el
manual (RFC 6819: reutilizar un refresco significa que uno de los dos es un
atacante). Con multipestaña real, un cliente despistado echaría a todo el mundo
sin que nadie hubiera hecho nada malo. Se anota y se puede mirar.

---

## 4. Compatibilidad: qué NO cambia

Cada colegio despliega su propio `app/` y su propio front, por separado. La
combinación que **no puede fallar** es «backend nuevo + front viejo», porque es
la que ocurre durante el despliegue.

| | Sigue igual |
|---|---|
| `POST login/credentials` | Devuelve `{ el_token }` (+ `cambia_anio`). Lo que cambia es qué hay dentro del token |
| `POST /api/login` | El contexto del usuario. Sin token, 200 con cuerpo vacío, como siempre |
| `PUT login/logout` | 200 pase lo que pase. Y ahora además mata el token |
| Los tres mensajes de 401 | `No existe Token`, `Token ha expirado.`, `Token inválido, prohibido entrar.` |
| `tardanzas/*` | El lector sigue mandando usuario y contraseña en cada petición |

**El token de `login/credentials` dura 24 h**, no una hora
(`SESION_LEGADO_TTL`). Un front que no conoce `/api/auth/*` no sabría qué hacer
con un refresco, así que su sesión tiene que aguantar de una vez lo que
aguantaba. Si emitiera el token corto, esos colegios sacarían al usuario cada
hora sin que nadie supiera por qué.

**Los JWT ya emitidos se siguen aceptando** (`SESION_ACEPTA_JWT=true`). El día
del despliegue hay tokens vivos en el navegador de todo el mundo con hasta 24 h
por delante; si dejaran de valer de golpe, el colegio entero se quedaría fuera a
la vez. Poner la variable en `false` los mata en el acto — y es **lo único que
puede revocarlos**.

---

## 5. Decisiones de implementación

### Sanctum como librería, no como paquete

`composer.json` lleva `laravel/sanctum` en `dont-discover`. Se usan el trait, el
modelo y la tabla; su `ServiceProvider` no se carga. Traía tres cosas que aquí
estorban:

- registraba la ruta `/sanctum/csrf-cookie` — esta API tiene 538 rutas
  explícitas y un test que compara la tabla entera; una ruta que aparece sola es
  justo lo que se lleva meses quitando;
- añadía un guard `sanctum` que no sirve (abajo);
- cargaba su propia migración de `personal_access_tokens`, que chocaría con la
  de este repo.

Lo que hacía su provider está en [`AuthServiceProvider`](../../app/Providers/AuthServiceProvider.php).

### `expires_at` por fila, y no la caducidad global de Sanctum

Sanctum 2.15 —la última que soporta Laravel 8— solo sabe de una caducidad
**global** calculada sobre `created_at`. Aquí conviven tres vidas: acceso 60
min, refresco 14 días, legado 24 h. Así que la caducidad va por fila, en una
columna `expires_at` que la migración de este repo añade y que **Sanctum 4 trae
de serie**: no es una desviación, es adelantarla.

Con ella dentro, el guard `sanctum` del paquete no vale: daría por bueno un
token caducado. Por eso el guard `api` es `sesion`, registrado con
`Auth::viaRequest`, y pregunta a `App\Services\Sesion` — el mismo sitio al que
preguntan el middleware `auth.token` y `User::fromToken()`. Una sola respuesta a
«¿este token vale?».

### `User::fromToken()` sigue existiendo

Se llama en **325 sitios**. No se ha tocado ninguno.

Lo que era un método de 280 líneas —parsear el JWT, el `switch` de cuatro ramas
con las consultas de cuarenta columnas, los roles y los permisos— ahora junta
dos servicios: [`Sesion`](../../app/Services/Sesion.php) valida el token y
[`ContextoDeUsuario`](../../app/Services/ContextoDeUsuario.php) monta el objeto.
Estaban juntos porque nadie los separó, y mientras lo estuvieron no se podía
cambiar de mecanismo de autenticación sin tocar 325 sitios.

Las consultas se movieron **tal cual**. Que la forma del objeto no cambió lo
comprueban los snapshots de `tests/Contrato/Snapshots/login-contexto-*.json`,
uno por cada tipo de usuario.

### `Auth::attempt()` ya no existe

El guard `api` pasó de `jwt` a `sesion`, que resuelve al usuario a partir del
token de la petición. Un guard así no tiene `attempt()`: no hay dónde meter unas
credenciales. Los cinco sitios que lo llamaban —`LoginController` y los cuatro
de Tardanzas— comprueban ahora la contraseña con
[`App\Support\Credenciales`](../../app/Support/Credenciales.php), que hace lo
mismo que hacía `EloquentUserProvider`.

Sin esto, **el login viejo devolvía 500** y el colegio que recibiera la Fase 3
antes que el front nuevo se quedaba sin ninguna forma de entrar.

---

## 6. Lo que esta fase NO hace

- **No quita `tymon/jwt-auth`.** Sigue instalado para poder aceptar los tokens
  ya emitidos. Se quita —junto con `"minimum-stability": "dev"`, `config/jwt.php`,
  `JWT_SECRET` y el `implements JWTSubject` de `App\User`— cuando todos los
  colegios lleven tiempo desplegados y `SESION_ACEPTA_JWT` esté en `false`.
  Es el primer paso de la Fase 4.
- **No impone límite de inactividad.** Mientras el refresco valga, se refresca.
  El límite absoluto y el de inactividad los cuenta el frontend (fase 7b de
  `myvc_front`), que es donde se sabe si el usuario está delante.
- **No toca `myvc_flutter`.** La app manda `Bearer` y no mira dentro del token,
  así que sigue funcionando por la ruta vieja. Migrarla al par es trabajo aparte,
  y hay que tener en cuenta que es **una sola app para todos los colegios**: no
  puede depender de que un colegio concreto tenga ya la Fase 3.
- **No arregla que Tardanzas deje entrar a un usuario borrado.**
  `Auth::attempt()` no filtraba `deleted_at` y `Credenciales` tampoco, a
  propósito: aquí solo tocaba quitar el guard. Anotado en
  [04-auditoria-autenticacion.md](04-auditoria-autenticacion.md).

---

## 7. Al desplegar

El procedimiento completo está en [DESPLIEGUE.md](../DESPLIEGUE.md). Lo
específico de esta fase:

1. **`php artisan migrate`** — crea `personal_access_tokens`. Es la primera
   migración de verdad de este repo. **Sin ella, el login devuelve 500.**
2. **`php artisan route:clear && route:cache`** — las cinco rutas `auth/*` son
   nuevas y no aparecen hasta regenerar el caché.
3. **`vendor/` tiene que tener Sanctum.** Un colegio con el `vendor/` de 2021 no
   lo tiene, así que `composer install` va **antes** que el código nuevo.
4. Nada que poner en el `.env`: los valores por defecto de `config/sesion.php`
   son los acordados. Ver `.env.example` para cambiarlos.
5. Opcional, si el colegio tiene cron: `php artisan sesion:limpiar` de vez en
   cuando. No es urgente — al abrir sesión ya se tiran los tokens caducados de
   ese usuario.
