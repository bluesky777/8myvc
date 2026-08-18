# Auditoría de autenticación — qué rutas no resuelven al usuario

**GENERADO. No editar a mano.** Se regenera con:

```bash
docker exec 8myvc-app-1 php tools/auditar-autenticacion.php --md \
  > docs/migracion/04-auditoria-autenticacion.md
```

## Por qué esta lista y no una semana de registro

El plan proponía desplegar un middleware que registrara durante una semana qué
rutas llegan sin token. No sirve: hay semanas en que los colegios no usan el
sistema, así que la ausencia de registros no distingue "nadie llama a esta ruta"
de "nadie usó el sistema esa semana". Esto se determina leyendo el código, que no
depende de que alguien entre.

## Cómo se determinó

Al principio este proyecto no tenía middleware de autenticación: cada método se
defendía solo llamando a `User::fromToken()`, que aborta con 401 si no hay token,
si expiró o si es inválido (`app/User.php:85-99`). Llamarlo **es** una
comprobación.

Se recorren las rutas reales del router y se analiza el cuerpo de cada método con
el analizador sintáctico —no con `grep`, que contaría un `fromToken` escrito
dentro de un comentario—. Se siguen además las llamadas a métodos auxiliares de
la propia clase: el PR #3 puso las guardas en `$this->exigirAdminUsuarios()`, y
mirando solo el cuerpo directo salían como desprotegidas.

Cuenta como resuelto: el middleware `auth.token`, o una llamada a
`User::fromToken()`, `JWTAuth::*`, `Auth::*`, `auth()` o `$this->user` (resuelto
en el constructor), directa o vía auxiliar.

**Lo que esto NO dice:** que las que sí resuelven al usuario estén bien.
Resolverlo prueba que hay token válido, no que ese usuario tenga permiso para lo
que va a hacer. Un alumno con token es un usuario autenticado. Eso es otra
auditoría.

## Resumen

| | Rutas |
|---|---|
| Resuelven al usuario | **531** |
| No lo resuelven y **escriben** en la base | **6** |
| No lo resuelven, solo leen | **5** |
| Método vacío: la ruta existe, el método no hace nada | 10 |
| Ruta registrada cuyo método no existe | 0 |
| **Total** | **552** |

---

## 1. Escriben en la base sin resolver al usuario — 0 a revisar

Lo urgente: permiten modificar datos de un colegio sin presentar token.

> **Las 58 que había aquí están cerradas** con el middleware `auth.token`
> (Joseth las confirmó todas como error el 18 ago 2026). `tests/Contrato/AutenticacionTest.php`
> comprueba que responden 401 sin token y que no rechazan a un usuario legítimo.

_Ninguna._


### Públicas a propósito (escriben, pero son el flujo de entrada)

De `login/*` y `password/*`, que el plan ya lista como públicas. No pueden llevar
guard —son justo lo que se usa sin token—, pero conviene mirar `putLogout`:
recibe el `user_id` por parámetro, así que hoy cualquiera puede cerrar la sesión
de cualquiera.

| ✔ | Verbo | Ruta | Controlador · método | Escribe |
|---|---|---|---|---|
| ☐ | `PUT` | `api/login/crear-prematricula` | LoginController::putCrearPrematricula | DB::update, DB::insert, ->save() |
| ☐ | `PUT` | `api/login/logout` | LoginController::putLogout | DB::update |
| ☐ | `POST` | `api/login/recuperar-clave` | LoginController::postRecuperarClave | DB::delete, DB::insert |
| ☐ | `PUT` | `api/login/reset-password` | LoginController::putResetPassword | DB::update, DB::delete |
| ☐ | `POST` | `api/login/ver-pass` | LoginController::postRecuperarClave | DB::delete, DB::insert |
| ☐ | `POST` | `api/password/reset` | RemindersController::postReset | ->save() |


---

## 2. Solo leen, sin resolver al usuario — 2 a revisar

> **Las 35 que había aquí están cerradas** (Joseth las confirmó el 18 ago 2026).
> Varias exponían datos de menores a cualquiera que supiera la URL:
> `perfiles/usuariosall`, `users/export`, `acudientes-export/acudientes`, `simat`,
> `observador`.
>
> **Se dejaron fuera a propósito las 2 de `publicaciones/ultimas`** (GET y PUT):
> las llama la pantalla de login, con el usuario aún sin autenticar. Ver la
> sección 5.

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `PUT` | `api/publicaciones/ultimas` | Perfiles\PublicacionesController::putUltimas |
| ☐ | `GET` | `api/publicaciones/ultimas` | Perfiles\PublicacionesController::getUltimas |


### Públicas a propósito (lectura)

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `GET` | `api/password/remind` | RemindersController::getRemind |
| ☐ | `POST` | `api/password/remind` | RemindersController::postRemind |
| ☐ | `GET` | `api/password/reset/{token?}` | RemindersController::getReset |


---

## 3. Métodos vacíos — 10

La ruta está registrada pero el método no hace nada. No son agujeros: son
endpoints muertos. Se pueden borrar sin tocar nada más.

| ✔ | Verbo | Ruta | Controlador · método |
|---|---|---|---|
| ☐ | `GET` | `api/ausencias` | AusenciasController::getIndex |
| ☐ | `POST` | `api/bitacoras/store` | BitacorasController::postStore |
| ☐ | `PUT` | `api/bitacoras/update/{id}` | BitacorasController::putUpdate |
| ☐ | `GET` | `api/estados_civiles/create` | EstadosCivilesController::create |
| ☐ | `GET` | `api/estados_civiles/{estados_civile}` | EstadosCivilesController::show |
| ☐ | `GET` | `api/estados_civiles/{estados_civile}/edit` | EstadosCivilesController::edit |
| ☐ | `POST` | `api/permissions` | PermissionsController::postIndex |
| ☐ | `DELETE` | `api/permissions/destroy/{id}` | PermissionsController::deleteDestroy |
| ☐ | `GET` | `api/permissions/show/{id}` | PermissionsController::getShow |
| ☐ | `PUT` | `api/permissions/update/{id}` | PermissionsController::putUpdate |


---

## 4. Rutas registradas cuyo método no existe — 0

Rutas cuyo controlador no implementa el método. Revientan con 500 si alguien las
llama.

> Las tres que había —`tiposdocumento/create`, `tiposdocumento/{id}` y
> `tiposdocumento/{id}/edit`, del andamiaje de recurso de Laravel— se
> eliminaron el 18 ago 2026, comprobado antes que devolvían 500.

_Ninguna._


---

## 5. Rutas que el frontend llama SIN sesión

**Ninguna de estas puede exigir token, aunque escriba.** Inventario levantado por
la sesión de `myvc_front` (18 ago 2026) recorriendo los cuatro estados que viven
fuera del área autenticada —`main`, `login`, `reset-password` y `logout`— y
`AuthService`. Todo lo demás cuelga de `panel`, que sí resuelve la sesión.

| Verbo | Ruta | Quién la llama |
|---|---|---|
| `PUT` | `api/login/crear-prematricula` | `LoginCtrl` — alta de prematrícula desde la pantalla pública |
| `PUT` | `api/publicaciones/ultimas` | `LoginCtrl` — las noticias que se pintan en el propio login |
| `POST` | `api/login/recuperar-clave` | `LoginCtrl` (y su alias `ver-pass`) |
| `PUT` | `api/login/reset-password` | `ResetPasswordCtrl` |
| `POST` | `api/login` | `AuthService` |
| `POST` | `api/login/credentials` | `AuthService` |
| `PUT` | `api/login/logout` | `AuthService` |

**Esto es lo que la auditoría no puede saber leyendo el backend.** Dos de ellas
escriben datos de una persona y parecen "de usuario", pero por definición se
ejecutan sin sesión:

- **`publicaciones/ultimas`** pinta las noticias dentro de la pantalla de login.
  Está entre las 37 de la sección 2, así que es la que más fácil se protege por
  descuido. El front la llama con `PUT` aunque solo lee.
- **`login/reset-password`** se llama desde el enlace del correo: el usuario no ha
  iniciado sesión —no puede, ha olvidado la contraseña— y el token del reseteo
  viaja en la URL, no en la cabecera. Si se protege, la recuperación queda rota de
  punta a punta y el usuario no tiene forma de salir de ahí.

`tests/Contrato/RutasPreLoginTest.php` comprueba que ninguna lleva guard y que
ninguna responde 401 sin token.

**Regla:** antes de proteger cualquiera de las 37 de la sección 2, preguntar al
front si la llama algo antes del login.

---

## 6. Quién consume esta API

Resumen; **la explicación completa de la topología está en
[`docs/DESPLIEGUE.md`](../DESPLIEGUE.md)**. Tres clientes, y **no todos comparten
host con la API**.

| Cliente | Despliegue | Origen |
|---|---|---|
| `myvc_front` (AngularJS) | Por colegio, en el subdominio del colegio (carpeta `up`) | Mismo host que la API |
| App **Flutter** (`myvc_flutter`, móvil y web) | **Una sola app para todos**; el usuario elige el servidor de su colegio al entrar | **Distinto host**, o ninguno en la build nativa |
| `8myvc` (esta API) | Por colegio, carpeta `8myvc`, con su propia base de datos | — |

Cada colegio es un subdominio con carpeta propia en uno de los dos *shared
hosting* con cPanel, y dentro va todo desde cero. Confirmado por Joseth el
18 ago 2026.

### Qué significa para la comprobación de host de `recuperar-clave`

`ruta_frontend_segura()` exige que el host del parámetro `ruta` coincida con el
de la petición (guarda del PR #3, contra recibir un correo legítimo con el token
apuntando al sitio de un atacante).

**Hoy no afecta a nadie**, y el motivo correcto no es que todos compartan host
—la app Flutter no lo hace—, sino que **la app Flutter no tiene recuperación de
contraseña**. Esa función solo existe en el front web, que sí comparte host.

> **Si algún día se añade "olvidé mi contraseña" a la app Flutter**, la
> comprobación la rechazará con 422 en todos los colegios: una app nativa no
> tiene `location.origin`. Haría falta `FRONTEND_URL` en el `.env` de cada
> colegio, o una excepción pensada para clientes sin origen web.

### Superficie de la app Flutter

Llama a cinco rutas. Todas menos el login mandan `Authorization: Bearer`.
Comprobado que **ninguna llevaba guard nuevo y las cinco ya resolvían al usuario
por su cuenta**, así que el PR #7 no la toca:

`POST login/credentials` (sin token, es el login) · `POST login` ·
`GET grupos` · `PUT asistencias/detailed` · `POST ausencias/store`

Su única superficie pre-login es `login/credentials`, ya incluida en la
sección 5.
