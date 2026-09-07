# 32 · La entrada de la app de escritorio

> **Todo lo de aquí se midió contra `7c3a0b9` el 6 sep 2026 entre las 21:50 y las
> 22:20 (−05)**, con `curl` contra el docker de desarrollo y leyendo el crate de
> Tauri instalado. Se anota el hash y la hora y no «sobre `main`», porque hay
> siete árboles moviéndose y *«sobre main»* no es reproducible.

`myvc_horarios` es un programa de escritorio (Tauri + Angular) que cuadra el
horario del colegio y lo sube a esta API. Es el **quinto cliente** y el primero
que no es ni un navegador ni un móvil. Este documento contesta una pregunta que
nadie había hecho desde este lado: **¿cómo entra, y qué le impide entrar?**

La respuesta corta: **entra, y funciona hoy en los dieciséis.** Lo que no hay es
nada que avise el día que deje de funcionar, y ese día tiene una causa concreta
y una fecha que depende de una tarea ya abierta.

---

## 1. Cómo entra hoy — medido, no leído

**Ya tiene login y no hay que inventarle ninguno.** Usa `POST api/login/credentials`
(la ruta vieja), con la razón escrita en su propio repositorio
(`nucleo/importadores/myvc.ts:83-86`): *«un import es un botón que se pulsa una
vez»*, así que no necesita el par acceso+refresco de `auth/login`.

```
POST /api/login/credentials     {"username": "...", "password": "..."}
  -> 200 {"el_token": "9314|<40 caracteres>"}                     y nada más
```

| Qué | Medido | Dónde vive |
|---|---|---|
| Vida del token | **1440 min = 24 h** | `config/sesion.php` → `legado_ttl` |
| Refresco | **no hay** — esa ruta no lo emite | `Sesion::abrirLegado()` |
| Forma del token | `<id>\|<40 aleatorios>`, Sanctum | `Sesion::emitir()` |
| Se acepta en | cabecera `Authorization: Bearer`, **y también `?token=`** | `Sesion::tokenPlanoDe()` |

**El token por parámetro funciona y está medido** (`GET api/auth/me?token=…` → 200).
No lo use el escritorio: deja el token en los logs de acceso del servidor, y el
propio método ya avisa al log de que la intención es retirarlo.

### Lo que NO le hace falta a la app de escritorio, y conviene saberlo antes de pedirlo

- **No hay filtro por cliente ni por versión.** `APP_MOVIL_VERSION_MINIMA` **no es
  un middleware**: es un campo (`version_minima_app`) que se *adjunta a la
  respuesta* y que decide **la app cliente**. No hay ningún `abort()` en
  ninguna parte del servidor por versión. **La app de escritorio no cae en él.**
  *Y la trampa que sí existe:* ese número es el **`versionCode` de Flutter**. Un
  cliente que copie el manejo del login del front y respete `version_minima_app`
  **se bloquearía a sí mismo con un número que no habla de él**. Hoy la clave no
  viaja en ningún colegio (`.env` vacío o ausente en los 17, barrido de
  `DESPLIEGUE.md` del 2 sep), así que es un riesgo dormido, no uno vivo.
- **No hace falta declarar nada para distinguir sus sesiones.** `auth/login` acepta
  un campo `origen` libre que acaba en `personal_access_tokens.name` como
  `escritorio:<uuid>`, **y sobrevive a la rotación del refresco** (medido: los
  tres pares de la misma sesión comparten nombre). `login/credentials` lo pone a
  `legado:` fijo, que es lo que la app tiene hoy.

---

## 2. Qué alcanza ese token — la escalera, medida ruta a ruta

`auth.personal` en la ruta **deja pasar a cualquier docente**; quien decide de
verdad es el `Autoriza::` de dentro del método. Medido con dos tokens reales:

| Ruta | docente raso | superusuario | Quién decide |
|---|---|---|---|
| `GET horario/versiones` | **200** | 200 | sólo `auth.personal` |
| `GET horario/versiones/{id}/lecciones` | **200** | 200 | sólo `auth.personal` |
| `POST horario/versiones` (subir) | **403** | 422 (cuerpo vacío) | `esAdministrativo` |
| `PUT horario/versiones/{id}/oficial` | **403** | — | `puedePublicarHorario` |
| `GET horario/versiones/{id}/proyecto` | **403** | — | `puedePublicarHorario` |
| `PUT horario/docentes/{id}/tono` | **403** | — | `puedePublicarHorario` |

**Consecuencia para la pantalla de entrada del escritorio:** cualquiera del
personal puede escribir su usuario y su clave y **entrar**, y descubrir después,
botón a botón, que no puede hacer nada. La API **no tiene** un «¿puedo?» que
preguntar antes.

**Pero no hace falta añadirlo, y ésta es la parte que no había que suponer:** el
contexto que devuelve el login trae **48 campos**, y entre ellos van
`is_superuser` y `roles[]` **con el nombre de cada rol**. Con eso el programa
puede calcular `puedePublicarHorario` sin ninguna ruta nueva:

```
is_superuser == 1  ||  roles[] contiene 'Coord académico'
```

`login/credentials` **no** devuelve ese contexto (sólo `el_token`), pero
`GET api/auth/me` sí, y el escritorio ya la usa de canario. **Cero rutas nuevas.**

> **Lo que hay que decir junto a esa fórmula, o se lee al revés:** duplicar una
> regla del servidor en un quinto cliente es exactamente lo que después deriva. Es
> aceptable aquí porque **el servidor sigue siendo el que decide** —los 403 de la
> tabla los pone él— y el cálculo del cliente sólo sirve para no enseñar un botón
> que va a fallar. El día que se use para *conceder* algo, deja de ser aceptable.
>
> Y hay un borde ya escrito en `Autoriza::puedePublicarHorario`: el rol
> `Coord académico` **tiene cero usuarios en `simonbolivar`**, así que hoy esa
> fórmula es «superusuario» en todos los colegios donde nadie lo haya asignado.

---

## 3. CORS — aquí está el problema, y es de medición, no de código

El escritorio habla desde un **WebView**, o sea desde un navegador, o sea
cruzando origen. Lo gobierna `config/cors.php`, y ese fichero **no lo comprobaba
nadie**: `grep -rn 'Origin' tests/ tools/` daba **ocho aciertos y los ocho eran
falsos positivos** (`getClientOriginalName`, un alumno llamado «Original»).

### 3.1 El origen de Windows, que era la pregunta abierta — **CONTESTADA**

`docs/migracion/29` §5 lo dejaba así: *«`tauri://localhost` está comprobado en
macOS y sólo en macOS. Si el ejecutable de Windows manda el mismo origen, no lo
sabe nadie.»*

**No manda el mismo.** Leído del crate exacto que compila ese repositorio
—`tauri` **2.11.5**, `src/manager/mod.rs:339-346`, `tauri_protocol_url()`, y el
test de `:778-799` que lo fija—:

```rust
if cfg!(windows) || cfg!(target_os = "android") {
    Url::parse(&format!("{scheme}://tauri.localhost"))   // scheme = http, o https con useHttpsScheme
} else {
    Url::parse("tauri://localhost")
}
```

| Dónde corre | `Origin` que manda |
|---|---|
| macOS y Linux, programa construido | `tauri://localhost` |
| **Windows, `.exe` construido** | **`http://tauri.localhost`** |
| Windows con `useHttpsScheme` | `https://tauri.localhost` — `tauri.conf.json` de ese repo **no lo pone** |
| `tauri dev` (cualquier plataforma) | `devUrl`, o sea `http://localhost:4310` |

> **Qué clase de prueba es ésta, dicho con precisión.** Es el código fuente de la
> versión exacta que ese repositorio compila, más su propio test. **No es una
> ventana abierta en Windows**: eso sigue sin hacerlo nadie, y no se puede hacer
> desde aquí. Lo que sí descarta es la lectura tranquilizadora —«será el mismo»—,
> que era la que estaba en pie.

**O sea que la lista mínima son DOS entradas para tres plataformas.** Poner sólo
`tauri://localhost` —el único origen que alguien ha visto nunca, y en un mac—
deja fuera Windows, **que es donde va a estar el que cuadra el horario**.

### 3.2 Lo que pasa hoy, y lo que pasará

Medido contra el docker (`CORS_ALLOWED_ORIGINS` ausente):

```
OPTIONS api/login/credentials  Origin: tauri://localhost       -> 204  ACAO: *
OPTIONS api/login/credentials  Origin: http://tauri.localhost  -> 204  ACAO: *
```

**Pasa, porque la variable no está puesta y `config/cors.php` cae a `['*']`.**
Ausente y vacía son lo mismo (la expresión termina en `?: ['*']`).

Y con una lista puesta, simulado sobre el `CorsService` real:

```
lista = [https://simonbolivar.micolevirtual.com, tauri://localhost, http://tauri.localhost]
  tauri://localhost       -> ACAO: tauri://localhost      pasa
  http://tauri.localhost  -> ACAO: http://tauri.localhost pasa
  https://tauri.localhost -> (ninguna)                    bloqueado
  http://localhost:4310   -> (ninguna)                    bloqueado   <- `tauri dev`
```

**El mecanismo admite un esquema propio.** No hace falta ni un patrón, ni pedirle
a la app que cambie a `@tauri-apps/plugin-http`. Sólo hace falta que las dos
cadenas estén en la lista.

**El riesgo, entonces, no es de código: es la tarea abierta de cerrar CORS.** El
día que alguien rellene `CORS_ALLOWED_ORIGINS` en un colegio —que es lo que
`config/cors.php` pide por escrito— el escritorio se cae ahí, en silencio, y el
síntoma aparece en la pantalla de un coordinador.

### 3.3 Una lista de UN origen se comporta distinto, y rompe a los detectores

**Esto lo destapó el test al fallar, y es el hallazgo que más lejos llega.**

`fruitcake/php-cors`, `isSingleOriginAllowed()`: si la lista tiene **exactamente
un** elemento y no hay patrones, la librería escribe ese origen en
`Access-Control-Allow-Origin` **sin mirar quién pregunta**.

```
lista = [https://x.edu.co]        Origin: tauri://localhost
  -> 204 con `Access-Control-Allow-Origin: https://x.edu.co`
```

El usuario acaba igual de fuera —el navegador compara, no casa, bloquea— pero
**la cabecera está**. Cualquier comprobación que pregunte *«¿vino una ACAO?»* en
vez de *«¿vino la mía?»* da un **verde falso**.

Y no es un caso de laboratorio: `.env.example` recomendaba literalmente
`p.ej: https://lalvirtual.edu.co`, o sea **una sola entrada**, que es justo la que
dispara esa rama. Ya está corregido allí.

> **`tools/cors-de-los-colegios.sh` tuvo ese fallo exacto en su primera versión** y
> lo cazó `CorsDelEscritorioTest` al ponerse rojo. Es la tercera forma del aviso
> de `CLAUDE.md`: *el detector contaba bien un síntoma y no estaba contando la
> causa*. La clasificación buena es `*` **o el origen exacto**, nunca «hay algo».

### 3.4 Lo que queda, y quién puede hacerlo

`tools/cors-de-los-colegios.sh` es el arnés que faltaba. Tres modos:

```bash
tools/cors-de-los-colegios.sh --env            # en el SERVIDOR: qué dice cada .env
tools/cors-de-los-colegios.sh --url URL…       # desde cualquier sitio: qué contesta ese servidor
tools/cors-de-los-colegios.sh --origenes       # la lista mínima, para pegar en un .env
```

Imprime su población siempre, y **un glob que no casa sale con código 2 diciendo
`NO MEDIDO`** en vez de imprimir un cero — que es la lectura falsa contra la que
existe. Lo mismo con un `.env` ilegible o un servidor que no contesta: **lo no
medido nunca sale verde.**

Probado en local contra un árbol falso con los seis casos (ausente, vacía, lista
que cubre, lista sólo del front, lista sólo de macOS, `.env` ilegible) y contra
tres servidores HTTP de verdad (sin ACAO, con dos ACAO, con la ACAO de otro
origen). Los cuatro códigos de salida comprobados sin tubería en medio.

**Correr `--env` sobre los dieciséis necesita la sesión del servidor y es de
Joseth.** Sigue sin correr desde que se abrió la pregunta el 3 sep.

---

## 4. Dos cosas del canal con `myvc_horarios` que no se arreglan aquí

### 4.1 `cambia_anio` viaja y el escritorio lo tira

`login/credentials` **mueve al usuario al año actual** si se había quedado en
otro (`Services\Login::ponerEnElPeriodoActual()`), y lo devuelve en el cuerpo.
El `entrar()` del escritorio lee **sólo `el_token`**. Consecuencia: las rutas del
horario sacan el año de sitios distintos y nadie lo está cruzando. No es un
arreglo de esta API; es un aviso que sale de aquí.

### 4.2 El error de credenciales **no es un 401**, y eso ya está diciendo lo contrario de lo que pasa

Medido, los tres casos:

```
POST login/credentials  clave mala        -> 400 {"error":"invalid_credentials"}
POST login/credentials  usuario inexistente -> 400 {"error":"invalid_credentials"}
POST login/credentials  cuerpo vacío      -> 400 {"error":"invalid_credentials"}
```

**Nunca 401 y nunca 422.** Y el `entrar()` del escritorio
(`escritorio/src/app/subir/transporte.ts`) hace:

```ts
respuesta.estado === 401 || respuesta.estado === 422
  ? 'El usuario o la clave no son de este colegio (401)…'
  : `El servidor contestó ${estado} … Esto no es la clave: es el servidor.`
```

O sea que **hoy, quien teclea mal su contraseña en el escritorio lee «esto no es
la clave: es el servidor»** — la única frase que no debería leer. Cae en la rama
`else` porque el 400 no está contemplado.

Y hay un segundo cuerpo que también cae ahí, con **dos formas distintas** en la
misma ruta:

| Origen del 429 | Cuerpo | Cuándo |
|---|---|---|
| `throttle:login` (middleware) | `{"message":"Too Many Attempts.",…}` + `Retry-After` | a la **3.ª** petición del minuto (medido; el cubo es 5/min por IP y lo comparten todos los logins de esa IP) |
| `Services\Login` | `{"error":"too_many_attempts","segundos":N}` | tras 5 fallos del par IP+usuario, con 900 s de espera |

Para el escritorio el arreglo es de una línea en **su** repositorio: tratar
**400** como «usuario o clave», y **429** como «espera N segundos» leyendo
`Retry-After` o `segundos`. **No se toca nada aquí** — cambiar el 400 a 401
rompería a los cuatro clientes que ya lo distinguen.

---

## 5. Lo que espera una decisión de Joseth

**Nada de esto se ha implementado**, y ninguna necesita ruta nueva.

### (a) ¿Pasa el escritorio a `auth/login` + `auth/refresh`?

**El problema.** `login/credentials` da un token de **24 h y sin refresco**. El
argumento con el que se eligió —*«un import es un botón que se pulsa una vez»*—
**ya no cubre lo que hay**: la pantalla `/subir` lista, mira, publica y descarga
en una tarde. A las 24 h el token muere y **no hay forma de renovarlo**: hay que
volver a teclear la contraseña. No es un fallo hoy —cuadrar un horario cabe en un
día— y **es una cuenta atrás que nadie ve**.

| Salida | Precio | Qué deja |
|---|---|---|
| **Se queda como está** | cero | a las 24 h, volver a entrar. Cero trabajo en los dos repos |
| **Pasa a `auth/login` + `auth/refresh`** | trabajo **sólo en `myvc_horarios`**: guardar dos tokens y rotar | sesión de 14 días. **Cero cambios en esta API** — las rutas existen y están probadas |
| Subir `SESION_LEGADO_TTL` | una línea en 17 `.env` | alarga **también** la sesión de `myvc_flutter` y `myvc_front_2`, que comparten esa ruta. **Es la salida barata y es otra decisión** |

Recomendación: la segunda. Es la única que no le cambia la vida del token a
clientes que no lo pidieron, y no cuesta nada aquí.

### (b) ¿Cuándo se cierra CORS, y con qué lista?

El arnés está escrito y probado; **falta correrlo, y eso necesita el servidor**.
La decisión no es técnica: es **en qué orden** se hacen las dos cosas.

- Si se cierra CORS **antes** de meter los dos orígenes del escritorio, **se cae
  el escritorio en ese colegio** y el síntoma no apunta a la causa.
- Si se meten los dos orígenes **antes** de cerrar CORS, no pasa nada: hoy la
  lista es `['*']` y añadirlos es inerte.

Recomendación: correr `--env` primero para saber **en cuántos colegios está ya
puesta** —cifra que **nadie tiene**— y meter los dos orígenes en el mismo
movimiento que cierre cualquiera.

---

## 6. Lo que sigue sin medirse, dicho con esas palabras

- **Nadie ha abierto el programa construido en Windows ni en Linux.** El origen de
  §3.1 está leído del crate y de su test, no visto en una ventana. Es prueba
  fuerte y **no es lo mismo**.
- **Los dieciséis `.env` reales no se han mirado.** Es la §5 de
  [`29-los-env-no-son-uniformes.md`](29-los-env-no-son-uniformes.md) y sigue
  abierta desde el 3 sep. El arnés existe desde hoy; correrlo, no.
- **Ningún vhost real se ha sondeado con `--url`.** Un Apache o un nginx delante
  puede añadir su propia `Access-Control-Allow-Origin` encima de la de Laravel:
  **dos cabeceras y el navegador rechaza las dos.** El guion lo clasifica aparte
  porque es un fallo que el `.env` no explica, pero no se ha visto ocurrir.
