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

**Desde el 18 ago 2026 la respuesta corta es "todas menos quince".** `auth.token`
se aplica en grupo a toda la API en `routes/api.php`, y las excepciones se marcan
una a una con `->withoutMiddleware('auth.token')` en su propio archivo. Este
informe sigue haciendo falta para esas quince —dice qué hace cada una y si
escribe en la base— y para ver cómo se defienden por dentro las demás.

Antes el guard iba ruta por ruta y lo que protegía a las otras 445 era que su
método llamara a `User::fromToken()`, que aborta con 401 si no hay token, si
expiró o si es inválido. Llamarlo **es** una comprobación. Pero depender de eso
no se sostiene: al sacar la llamada de los constructores aparecieron tres métodos
que devuelven antes de leer `$this->user` —`acudientes/datos`,
`alumnos/personas-check` y `prematriculas/alumnos-con-grado-anterior`— y las tres
rutas quedaron abiertas sin que nadie hubiera tocado el archivo de rutas. El
guard por defecto quita esa clase entera de agujero.

Se recorren las rutas reales del router y se analiza el cuerpo de cada método con
el analizador sintáctico —no con `grep`, que contaría un `fromToken` escrito
dentro de un comentario—. Se siguen además las llamadas a métodos auxiliares de
la propia clase: el PR #3 puso las guardas en `$this->exigirAdminUsuarios()`, y
mirando solo el cuerpo directo salían como desprotegidas.

Cuenta como resuelto: el middleware `auth.token` **no excluido en esa ruta**, o
una llamada a `User::fromToken()`, `JWTAuth::*`, `Auth::*`, `auth()` o
`$this->user`, directa o vía auxiliar.

**Lo que esto NO dice:** que las que sí resuelven al usuario estén bien.
Resolverlo prueba que hay token válido, no que ese usuario tenga permiso para lo
que va a hacer. Un alumno con token es un usuario autenticado.

Esa otra auditoría ya empezó, y encontró cuatro guardas escritas que no se
ejecutaban nunca: [06-autorizacion.md](06-autorizacion.md).

## Resumen

| | Rutas |
|---|---|
| Resuelven al usuario | **{{CON}}** |
| No lo resuelven y **escriben** en la base | **{{ESCRIBEN}}** |
| No lo resuelven, solo leen | **{{LEEN}}** |
| Método vacío: la ruta existe, el método no hace nada | {{VACIOS}} |
| Ruta registrada cuyo método no existe | {{ROTAS}} |
| **Total** | **{{TOTAL}}** |

---

## 1. Escriben en la base sin resolver al usuario — {{N_ESC_REV}} a revisar

Lo urgente: permiten modificar datos de un colegio sin presentar token.

> **Las 58 que había aquí están cerradas**, y con ellas las 445 restantes: el
> guard ya no se pone ruta por ruta sino en grupo. `tests/Contrato/AutenticacionTest.php`
> fija la lista exacta de las que NO lo llevan y comprueba que las demás
> responden 401 sin token.

{{T_ESC_REV}}

### Públicas a propósito (escriben, pero son el flujo de entrada)

Todas de `login/*`. No pueden llevar guard: son justo lo que se usa sin token.
`putLogout` recibía el `user_id` por parámetro y cualquiera podía cerrar la
sesión de cualquiera; se arregló el 18 ago 2026 y ahora el usuario sale del
token.

{{T_ESC_PUB}}

---

## 2. Solo leen, sin resolver al usuario — {{N_LEE_REV}} a revisar

> **Las 35 que había aquí están cerradas** (Joseth las confirmó el 18 ago 2026).
> Varias exponían datos de menores a cualquiera que supiera la URL:
> `perfiles/usuariosall`, `users/export`, `acudientes-export/acudientes`, `simat`,
> `observador`.
>
> **Se dejaron fuera a propósito las 2 de `publicaciones/ultimas`** (GET y PUT):
> las llama la pantalla de login, con el usuario aún sin autenticar. Ver la
> sección 5.

{{T_LEE_REV}}

### Públicas a propósito (lectura)

{{T_LEE_PUB}}

---

## 3. Métodos vacíos — {{VACIOS}}

La ruta está registrada pero el método no hace nada. No son agujeros: son
endpoints muertos.

> **Las 10 que había se borraron el 18 ago 2026**, tras confirmar la sesión de
> `myvc_front` que ni el front web ni la app Flutter llaman a ninguna, y tras
> comprobarlo por segunda vez leyendo los dos repos.
>
> La que más dudas daba era `GET ausencias`: un listado vacío no se nota, el
> usuario vería "no hay ausencias" en vez de un error. Resultó que el front nunca
> la pide a secas — todas sus llamadas llevan segmento, y el listado real sale de
> `ausencias/detailed/{id}`.
>
> **Los métodos vacíos siguen en sus controladores**, solo se quitaron las rutas.
> Borrarlos es limpieza aparte.

> **`estados_civiles` se borró entero** (Joseth, 18 ago 2026), rutas y
> controlador. No tenía cliente —el front lleva la lista escrita a mano en
> `ProfesoresNewCtrl:14` y `ProfesoresEditCtrl:16`— y encima `store` y `update`
> respondían 500 por usar `Input::`, eliminada en Laravel 5.2. Ver
> [05-codigo-muerto-y-roto.md](05-codigo-muerto-y-roto.md).

{{T_VACIOS}}

---

## 4. Rutas registradas cuyo método no existe — {{ROTAS}}

Rutas cuyo controlador no implementa el método. Revientan con 500 si alguien las
llama.

> Las tres que había —`tiposdocumento/create`, `tiposdocumento/{id}` y
> `tiposdocumento/{id}/edit`, del andamiaje de recurso de Laravel— se
> eliminaron el 18 ago 2026, comprobado antes que devolvían 500.

{{T_ROTAS}}

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

- **`publicaciones/ultimas`** hace bastante más de lo que su nombre sugiere, y por
  eso es la más peligrosa de proteger por descuido. Además de las noticias que se
  pintan en el login, **su respuesta alimenta el formulario público de
  prematrícula entero**: el desplegable de "Grupo a prematricular" sale de
  `year.grados_sig`, dentro de esa misma respuesta, igual que el año.

  O sea que tocar la forma de lo que devuelve no rompe unas noticias: rompe la
  prematrícula pública.

  **Los dos verbos están abiertos, GET y PUT, y es deliberado.** El front la
  llama hoy con `PUT` desde un único sitio (`LoginCtrl.js:81`), pero el `GET` fue
  el verbo real durante cinco años y medio: `c116e3f` (2018-10-12) lo introdujo
  con `$http.get` y no cambió a `PUT` hasta `c09718e` (2024-03-05).

  Como cada colegio publica su front por separado y **no hay inventario de qué
  versión tiene cada cual**, cualquier colegio con un front anterior a marzo de
  2024 sigue llamando por `GET`. Cerrarlo le rompería la pantalla de login sin
  dar síntoma en ningún sitio hasta que alguien de ese colegio se queje.

  > **Se queda abierto, y ya no por precaución: porque cerrarlo no protege nada.**
  > Joseth confirmó (18 ago 2026) que todos los colegios se actualizan con las
  > últimas PRs, así que la condición que faltaba se cumple. Pero al ir a
  > cerrarlo apareció el dato que decide: **`getUltimas()` y `putUltimas()`
  > devolvían exactamente lo mismo** — 21 líneas duplicadas palabra por palabra.
  >
  > O sea que proteger el `GET` deja el mismo dato saliendo por el `PUT`, que
  > tiene que seguir público. Se cambia riesgo por nada.
  >
  > Lo que sí se hizo es **deduplicarlas**: `getUltimas()` ahora delega en
  > `putUltimas()`. Con dos copias, quien tocara una dejaría a los colegios del
  > otro verbo con una respuesta distinta —y siendo esto lo que alimenta la
  > prematrícula, sin forma de notarlo hasta que un padre no pudiera matricular.
- **`login/reset-password`** se llama desde el enlace del correo: el usuario no ha
  iniciado sesión —no puede, ha olvidado la contraseña— y el token del reseteo
  viaja en la URL, no en la cabecera. Si se protege, la recuperación queda rota de
  punta a punta y el usuario no tiene forma de salir de ahí.

`tests/Contrato/RutasPreLoginTest.php` comprueba que ninguna lleva guard y que
ninguna responde 401 sin token.

### Y seis más que no son públicas, pero tampoco llevan token

**Tardanzas no usa token.** El lector manda usuario y contraseña en el cuerpo de
**cada** petición, y el método los verifica con `Auth::attempt()`. Autentican —no
son públicas— pero el guard de token las cerraría igual y el lector se quedaría
sin poder entrar. No aparecían en el inventario de `myvc_front` porque no son de
`myvc_front`.

| Verbo | Ruta | Cómo autentica |
|---|---|---|
| `POST` | `api/tardanzas/login` | `Auth::attempt()` con lo que venga en el cuerpo |
| `POST` | `api/tardanzas/login/traer-datos` | ídem |
| `POST` | `api/tardanzas/login/traer-datos-ausencias` | ídem |
| `POST` | `api/tardanzas/subir` | ídem, vía `$this->user()`, con las credenciales en `loginData` |
| `PUT` | `api/tardanzas/subir/eliminar-ausencia` | ídem |
| `PUT` | `api/tardanzas/subir/poner-ausencia` | ídem |

Salieron al aplicar el guard por defecto: hasta entonces nadie había tenido que
enumerarlas, porque el guard se ponía ruta a ruta y a estas simplemente no se les
ponía. **Son deuda de autenticación**, no una excepción legítima: mandar la
contraseña en cada petición es peor que un token, y la Fase 3 (Sanctum) es el
momento de darles uno.

**Regla:** antes de dejar sin guard cualquier ruta nueva, preguntar al front si la
llama algo antes del login.

### El invariante que esto hace posible

**La superficie sin token de la API son quince rutas: las nueve de arriba y las
seis de tardanzas.** Ya no es una observación sobre el resultado de una
auditoría: es una propiedad del archivo de rutas, porque el guard va por defecto
y salir de él exige escribir `->withoutMiddleware('auth.token')`.

Dos tests lo sostienen, y hacen falta los dos:

- `AutenticacionTest::test_solo_estas_rutas_no_exigen_token` lee la tabla de
  rutas y compara la lista contra la escrita a mano. Es instantáneo y detecta
  la excepción puesta por descuido.
- `RutasPreLoginTest::test_ninguna_otra_ruta_responde_sin_token` recorre las 533
  rutas sin cabecera de autenticación y comprueba que **ninguna responde 2xx**.
  Afirma el resultado, no el mecanismo: da igual que la ruta se defienda con
  middleware o con `User::fromToken()` dentro del método.

El segundo es el que de verdad importa —una ruta puede llevar el middleware en
la tabla y responder igual— y es el que hay que mantener verde.

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

Llama a **seis** rutas de esta API. Todas menos el login mandan
`Authorization: Bearer`. Comprobado que ninguna llevaba guard nuevo y que las
seis ya resolvían al usuario por su cuenta, así que el PR #7 no la toca:

| | |
|---|---|
| `POST login/credentials` | **sin token** — es el login |
| `POST login` | con token |
| `GET grupos` | con token |
| `PUT asistencias/detailed` | con token |
| `POST ausencias/store` | con token |
| `DELETE ausencias/destroy/{id}` | con token |

Su única superficie pre-login es `login/credentials`, ya incluida en la
sección 5.

> **Y una séptima llamada que no es de esta API.** Antes de elegir colegio y antes
> de cualquier login, la app pide el directorio de colegios a
> `POST https://micolevirtual.com/app/listado_colegios.php` — un PHP suelto en un
> host central, fuera de Laravel y fuera del despliegue por colegio.
>
> **Es un punto único de fallo que ninguna auditoría de rutas ve:** si ese fichero
> se cae, la app móvil no arranca en **ningún** colegio, porque no puede ni ofrecer
> la lista de servidores. Queda anotado aquí porque no tiene otro sitio donde
> constar.
