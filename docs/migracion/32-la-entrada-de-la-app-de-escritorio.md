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
verdad es el `Autoriza::` de dentro del método. Medido con **tres** tokens reales
—docente raso, superusuario y coordinador académico—, los tres por HTTP contra el
docker:

| Ruta | docente raso | **Coord académico** | superusuario | Quién decide |
|---|---|---|---|---|
| `GET horario/versiones` | **200** | **200** | 200 | sólo `auth.personal` |
| `GET horario/versiones/{id}/lecciones` | **200** | **200** | 200 | sólo `auth.personal` |
| `GET horario/versiones/{id}/proyecto` | **403** | **200** | — | `puedePublicarHorario` |
| `PUT horario/versiones/{id}/oficial` | **403** | **200** | — | `puedePublicarHorario` |
| `PUT horario/docentes/{id}/tono` | **403** | **200** | — | `puedePublicarHorario` |
| `POST horario/versiones` (subir) | **403** | **403** | 422 (cuerpo vacío) | `esAdministrativo` |

### La columna del medio se midió el 6 sep 2026, y hasta ese día estaba vacía

**No porque nadie la hubiera intentado: porque no había a quién pedírsela.** El rol
`Coord académico` tiene **cero usuarios** en `simonbolivar` —y en los dieciséis
colegios—, así que la mitad de arriba de esta tabla estaba escrita **desde el
código y no desde una respuesta**. Se creó un usuario de prueba en la base de
desarrollo local (§2.1) y se ejercitó entera.

**Y la asimetría de la última fila es la que importa, porque ahora está vista
funcionar y no deducida:** un coordinador académico **publica, descarga y pinta,
y NO puede subir**. No es un descuido — es la decisión 10 de Joseth escrita en dos
criterios distintos a propósito:

- `puedePublicarHorario` = superusuario **o** `Coord académico` → las tres del medio.
- `esAdministrativo` = superusuario **o** `Secretario` → subir.

O sea que **secretaría sube todas las versiones que quiera y no elige la que ve el
colegio, y el coordinador elige la que ve el colegio y no sube ninguna.** Es
exactamente *«subir no publica»* llevado hasta el final, y ninguno de los dos
criterios se podía ensanchar para cubrir al otro sin colar la decisión en los
otros seis sitios que los leen.

### 2.1 · El usuario de prueba, para que esto se pueda repetir mañana

**Sólo en la base de desarrollo local (`simonbolivar`, la del docker). No toca
producción, ni el seed, ni ninguna migración.**

| | |
|---|---|
| `users.id` | **2449** |
| `username` | `coord.academico.prueba` |
| contraseña | `test-1234` (la misma que usa el seed) |
| `tipo` | `Usuario` |
| `is_superuser` | **0** — y es lo único que hace que la prueba demuestre algo |
| rol | `Coord académico` (`roles.id = 9`) vía `role_user` |

Comprobado antes de la escalera, ejecutando los criterios en vez de razonarlos:

```
Role::isCoordAcademico(2449)      = true
Autoriza::esSuperusuario          = false
Autoriza::puedePublicarHorario    = true
Autoriza::esAdministrativo        = false
```

`isCoordAcademico` compara **la cadena literal `'Coord académico'` en PHP**, así
que la tilde no la salva ninguna collation: que salga `true` es la comprobación
de que el rol se insertó con los bytes correctos, no un detalle.

> **Lo que esto NO arregla, y no se puede escribir como si lo arreglara.** En los
> dieciséis colegios el rol **sigue teniendo cero usuarios**, así que allí publicar
> el horario lo pueden los once superusuarios y nadie más. Asignarlo es operación
> de cada colegio —dieciséis decisiones, no una nuestra (decisión 11)—. Lo que se
> resuelve aquí es que **la escalera se pueda probar**, que hasta hoy no se podía.

*Las dos escrituras de la escalera se repusieron:* `profesores.tono` del docente 1
volvió a `NULL`, y el `PUT .../oficial` fue **inerte** porque la 8 ya era la
oficial —`years.horario_version_id` seguía en 8 antes y después—.

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

> **Y «probablemente Windows» dejó de ser probablemente el 6 sep 2026.** `8myvc-d3`
> lo trajo de la mesa de Joseth al cursar este hallazgo: **la máquina de referencia
> de Joseth es un Pentium de 2010 con Windows**, la misma con la que se mide el
> listón de los 20 s del generador de horarios. O sea que la plataforma cuyo origen
> **no está en ninguna lista de las que alguien escribiría** no es un caso de borde:
> es la máquina en la que se prueba el programa.
>
> *Este dato no se midió aquí* — llega por el canal entre sesiones y se anota con
> quién lo trajo, no como una medición propia.

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

### 4.3 `version_minima_app` · el riesgo dormido, y qué lo despertaría

**Está dormido, no muerto, y la diferencia es que despertarlo cuesta una línea en
un `.env` que nadie revisa.** Va aquí y no en el informe de una noche porque el
día que alguien escriba esa clave **no va a recordar nada de esto**.

**El campo ya le llega al escritorio hoy.** `postCredentials()` termina en
`VersionMinimaDeLaApp::adjuntarA($res)` (`LoginController.php:105`), o sea que
`login/credentials` —la ruta que usa el escritorio— **adjunta `version_minima_app`
igual que las otras tres**. No lo hace por él: está ahí porque es la ruta que
llama `myvc_flutter`.

**Por qué está dormido: hacen falta DOS cosas y hoy no se da ninguna.**

1. **Ningún colegio tiene un número puesto.** Barrido del 2 sep 2026 sobre las 17
   carpetas: 16 con la línea ausente y `demo` con ella presente y **vacía**, que
   es lo mismo. Sin valor, la clave **no viaja** — no viaja como `null`: no está.
2. **El escritorio no lo lee.** Su `entrar()` saca `el_token` del cuerpo y tira lo
   demás. Es la misma indiferencia por la que tira `cambia_anio` (§4.1), y aquí
   resulta ser lo único que lo protege.

**Cualquiera de las dos, sola, es inerte. Las dos juntas dejan al colegio fuera.**

**Y la mitad barata es la peligrosa.** Escribir el número es *operación*: una línea
en un `.env`, sin despliegue, sin revisión y sin que este repositorio se entere.
Es exactamente el movimiento que alguien hará el día que quiera forzar una
actualización de la app de móvil — y lo hará pensando en Flutter, porque el campo
es de Flutter.

**Lo que lo hace peor aquí que en Flutter, y es lo que hay que entender:** para la
app de móvil ese número **es su propio `versionCode`**, así que existe una versión
que lo satisface — publicarla es la salida. Para `myvc_horarios` **no existe
ninguna**: su versionado es independiente y no está en ninguna tienda. Un bloqueo
por ese campo sería **un bloqueo sin salida**, y la escapatoria que sí tiene la
app de móvil —«¿Tienes cuenta en otro colegio?», que al cerrar sesión olvida el
número— **no existe en un programa de escritorio**.

> **La regla que sale de aquí, escrita para el que venga a implementar el bloqueo
> del escritorio:** si algún día `myvc_horarios` tiene que respetar una versión
> mínima, **necesita un campo propio**, no éste. Reutilizar `version_minima_app`
> es gratis de escribir y convierte el número de un binario en la condición de
> entrada de otro que no lo puede cumplir. **Un campo nuevo es un cambio de
> contrato, o sea una decisión de Joseth**, y por eso esto es un aviso y no un
> arreglo.
>
> Es el mismo patrón que `profesores.tono` (la quinta ruta del horario): una
> columna que gobernaba algo y que **no podía escribir nadie**. Aquí es al revés
> —un campo que puede gobernar a quien no le corresponde— y se ve **antes** de
> cometerlo, que es la única ventaja que tenemos.

**Y el aviso no llega por donde llega el peligro.** Lo levantó `8myvc-d3` al
fundir el recuadro de `config/aplicacion-movil.php`, y es lo que hace que este
párrafo no baste por sí solo:

| | Qué hace falta para que ocurra |
|---|---|
| **Escribir el número** | una línea en el `.env` de un colegio. **Ningún despliegue.** |
| **Que llegue el aviso** | un despliegue, porque `config/` es copia real en cada colegio |

**O sea que hay una ventana en la que el número se puede escribir y el aviso
todavía no ha llegado a ese colegio**, y hoy esa ventana está abierta en los
diecisiete: el despliegue está congelado hasta que la app salga de revisión, y
estos comentarios no están en ninguno. No lo arregla nadie esta noche y no es
motivo para descongelar nada — se escribe porque **un aviso que aún no ha
viajado no protege**, y quien lea el recuadro dentro de seis meses lo leerá ya
desplegado y no sabrá que hubo un tramo en que no lo estaba.

*Se anota con quién lo trajo: no salió de una medición de aquí.*

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
