# Los `.env` no son uniformes, y hay decisiones apoyadas en que sí lo eran

**2 sep 2026.** Joseth le pasó a `8myvc-af` el `.env` de producción de
`cads-itagui`. Es el **primero** que se mira entero desde que empezó la migración, y
lo que trae no coincide con lo que la documentación daba por supuesto. El correo roto
de ese colegio es el hallazgo pequeño. El grande es el de al lado:

> **Todo lo que este repositorio concluyó «para los quince» a partir de un solo `.env`
> —o de ninguno— es una hipótesis, no una medición.** Y hay decisiones tomadas encima.

Este documento es el censo de esas afirmaciones, **ordenado por lo que pasa si son
falsas en un colegio**, no por dónde aparecen.

---

## 0. La población, porque un censo sin población no se puede leer

| | |
|---|---|
| Ficheros `.md` barridos bajo `docs/` | **107** |
| Líneas barridas | **61.826** |
| Variables distintas que el código lee con `env()` (`config/` + `app/`) | **100** |
| Variables que documenta `.env.example` | **50** |
| **Leídas por el código y NO documentadas en `.env.example`** | **52** |
| Documentadas y que no lee ni `config/` ni `app/` | 2 (`MIX_PUSHER_*`, restos del andamiaje de Laravel: sólo las mira `resources/js/bootstrap.js`) |
| `.env` de producción que alguien ha leído **entero** | **1 de 17** (`cads-itagui`) |
| Variables censadas **en los diecisiete** | **12**: `APP_MOVIL_VERSION_MINIMA` (2 sep); `APP_KEY`, los cuatro `MAIL_*`, `APP_DEBUG`, `APP_ENV`, `CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`, `JWT_SECRET` y los cuatro *drivers* (3 sep) |
| **Juegos de claves distintos entre los diecisiete** | **5** — y ésta es la fila que sostiene el título |

Esa última fila es el documento entero. **1 de 17** — dieciséis colegios más `demo`
(`DESPLIEGUE.md`, barrido del 2 sep 2026).

> **Y el barrido se contó con `-c` antes de cortar.** Ninguna cifra de aquí sale de un
> `| head`: con `head`, «no aparece» y «no miré» se leen igual, y de las dos lecturas la
> falsa es la que hace archivar el asunto.

---

## 1. `APP_KEY` · **MEDIDO: `fortul` y `lal` comparten clave.** La premisa era falsa, y la causa está escrita en nuestro propio procedimiento

**Estado: contestado el 3 sep 2026, arreglado y verificado.** Esta sección se escribió como
pregunta —«nadie los ha comparado»— y la respuesta llegó al día siguiente. Se conserva el
razonamiento entero porque **predijo el mecanismo exacto**, no sólo el resultado.

### Lo que se midió

```
población                          17 carpetas (16 colegios + demo)
fortul.micolevirtual.com           42bb720f546f  ┐ misma clave
lal.micolevirtual.com              42bb720f546f  ┘
los otros quince                   quince hashes distintos
```

**Y se descartaron las lecturas benignas antes de llamarlo hallazgo**, porque un hash
repetido también sale de dos colegios *sin* clave, que sería otro problema distinto:

| caso inocente | su md5 recortado |
|---|---|
| no hay línea `APP_KEY` (entrada vacía) | `d41d8cd98f00` |
| `APP_KEY=` presente y vacía | `adef725c7222` |
| `APP_KEY=base64:` truncada | `75f9a7bb3d5c` |
| `APP_KEY=SomeRandomString` (andamiaje de Laravel) | `1dbf3bbd1d09` |

Ninguno es `42bb720f546f`. **Es una clave real, repetida en dos instalaciones.**

### La causa, y es lo que de verdad hay que arreglar

No es azar ni descuido: **el procedimiento del traslado lo manda hacer así**. El paso E de
[`TRASLADO-LAL.md`](../TRASLADO-LAL.md), que es como nació `lal` el 30 ago 2026, dice:

```
nano .env          # SOLO DB_DATABASE, DB_USERNAME, DB_PASSWORD
```

**«SOLO» es el fallo.** El `git clone` no trae `.env` —está ignorado—, así que el fichero
salió de una copia de otro colegio y sólo se tocaron las tres líneas que el procedimiento
nombra. `APP_KEY` viajó dentro. `fortul` fue el donante.

Y **`key:generate` no aparece en ningún procedimiento de este repositorio** —comprobado con
`grep` sobre `docs/` y `CLAUDE.md`: sus cinco menciones están todas en textos que *suponen*
que se corre, ninguna en unas instrucciones que manden correrlo—. La premisa
«`key:generate` hace uno por instalación» describía un paso que **nadie tenía escrito**.

> *Esto es lo que hace que el hallazgo valga más que dos claves iguales: **el procedimiento
> sigue escrito así**, así que el próximo colegio creado copiando volvería a colisionar. La
> `.env` copiada era la hipótesis que el [05](05-codigo-muerto-y-roto.md) escribió sin
> recorrer — y era exactamente el camino.*

### El radio, acotado antes de alarmar a nadie

La primera sospecha era peor que la realidad y **por eso se midió en vez de suponerla**: si
`APP_KEY` firmara los tokens, un token de un colegio valdría en otro **hoy**. No los firma:

- `APP_KEY` se lee en **exactamente dos sitios**: `config/app.php:136` (el encriptador de
  Laravel) y `config/notificaciones.php:38` (el secreto de los temas).
- En `app/` hay **cero** usos de `Crypt::`, `encrypt(`, `decrypt(`, `signedRoute`,
  `hasValidSignature` y `temporarySignedRoute` — contados con `-c`, no supuestos.
- **El token no es una firma, es una fila**: `Sesion::buscar()` → `TokenDeSesion::findToken()`,
  un hash buscado **en la base de ese colegio**. La base de al lado no lo tiene.
- Y `routes/web.php` no tiene rutas reales, así que las cookies cifradas no alcanzan a nadie.

**Conclusión: nadie estuvo expuesto.** Lo que hacía era bloquear el push, exactamente por
donde este documento dijo que lo haría — y el solape no habría sido raro sino **casi total en
los identificadores bajos**, porque cada colegio tiene su propio autoincremento y el `alumno_id`
345 existe en los dos.

### El arreglo, y por qué sólo se tocó uno

**Se rotó `lal` y no `fortul`.** `lal` todavía **no sirve desde `micolev1`**: sigue en la
cuenta vieja bajo `lalvirtual.edu.co` y su subdominio ni resuelve, así que esa carpeta es una
copia preparada y no en uso — rotarla no le tocó a nadie. `fortul` está vivo y se quedó como
estaba. *Y era doblemente gratis: cuando el traslado se complete, la clave de `lal` iba a
cambiar de todos modos.*

```bash
d=/home/micolev1/lal.micolevirtual.com/8myvc
cp -p "$d/.env" "$d/.env.bak-$(date +%Y%m%d-%H%M%S)"
cd "$d" && php artisan key:generate --force \
  && php artisan config:clear && php artisan config:cache
```

**Verificado, y con la población delante, no con un vacío a pelo:**

```
población                 17    (igual que antes)
repetidos                  0
hashes distintos          17 de 17   <-- no «sin repetidos»: todas únicas
filas que cambiaron        1    lal  42bb720f546f -> 136f77f109de
```

**Que cambiara exactamente una fila es la mitad importante de la comprobación.** «Ya no hay
repetidos» lo cumpliría también un `.env` que se hubiera roto por el camino, o un bucle que
dejara de ver carpetas; comparar los dos censos entero dice **qué se movió y qué no**, y aquí
lo único que se movió fue `lal`. `fortul` conserva la suya —seguía viva y no se tocó— y los
otros quince están **byte a byte** donde estaban.

> **`--force` y `config:cache` no son adorno.** `key:generate` pide confirmación con
> `APP_ENV=production` y por SSH sin TTY se cuelga; y un colegio con la config cacheada
> seguiría usando la clave vieja **sin ningún síntoma**, que es la trampa de
> `DESPLIEGUE-REFERENCIA.md:1536`. Y `key:generate` **sustituye** la línea `APP_KEY=`: en un
> `.env` que no la tenga no escribe nada y sale verde igual.

### Lo que este censo NO cubre, porque un «17 medidos» no es «todo medido»

El bucle barre `/home/micolev1/*`, así que alcanza la copia preparada de `lal` **pero no su
instalación viva**, que está en la otra cuenta (`micolevi`, bajo `lalvirtual.edu.co`). **Esa
clave sigue sin medir**: hay una instalación número dieciocho fuera del censo. Hoy no cambia
nada —nada depende de `APP_KEY` todavía— pero el día que se cuente «las claves están todas
comprobadas», esa no lo está.

### Y la advertencia que ahora hay que dejar escrita para siempre

**Rotar `APP_KEY` con el push encendido re-apunta todos los temas, y los teléfonos siguen
escuchando el nombre viejo.** Los avisos dejan de llegar **sin un solo error en ningún
sitio**: publicar en un tema al que no hay nadie suscrito es válido en FCM. O sea que
cualquier rotación futura de `APP_KEY` —por este motivo o por cualquier otro— **tiene que
hacerse antes de encender Firebase, o coordinarse con una resuscripción de la app**. Es la
misma familia que todo lo demás de este documento: no da error y el resultado se lee bien.

---

## 2. `MAIL_FROM_ADDRESS` · el remitente es de un dominio que no existe, y en un colegio es `null`

**La consecuencia: el reseteo de contraseña no llega, y en `cads-itagui` ni siquiera se
intenta.** Afecta a la única función que manda correo en toda la API
(`LoginController.php:312`, `Mail::to(...)->send(new ResetPassword(...))`).

Son **tres fallos apilados** y cada uno bastaba solo:

| | Lo que hay | Lo que provoca |
|---|---|---|
| `MAIL_MAILER=smtp` + `MAIL_HOST=mailhog` | un capturador de desarrollo que **no existe en el servidor** | el transporte no conecta |
| `MAIL_FROM_ADDRESS=null` | Laravel lo lee como **null de verdad** | Laravel rechaza el envío **antes** de intentarlo |
| `MAIL_FROM_NAME` heredando `"Laravel"` | el nombre del andamiaje | cosmético, pero delata que ese bloque nunca se tocó |

**Ese colegio no ha enviado un correo nunca.**

> **Y el 3 sep se supo que no era «ese colegio»: eran dieciséis.** El censo de los `MAIL_*`
> sobre las diecisiete carpetas dio **la misma configuración rota en dieciséis**, así que el
> reseteo de contraseña devolvía 500 en todos los colegios reales. `cads-itagui` no era una
> excepción, era **la única muestra**. Censo, arreglo y verificación en el §3.

### Lo que se reprodujo, porque venía de otra sesión y de otro repositorio

Medido de nuevo aquí el 2 sep 2026, no heredado:

```
dig +short MX  micolevirtual.com  ->  0 mail.micolevirtual.com.
dig +short TXT micolevirtual.com  ->  "v=spf1 +a +mx +ip4:70.32.23.72 include:spf.a2hosting.com ~all"
dig +short A   micolevirtual.com  ->  70.32.23.72
dig           lalvirtual.com      ->  status: NXDOMAIN
```

Las tres afirmaciones de `8myvc-af` salen iguales. **Y sale una cuarta que refuerza la
suya**: la `A` de `micolevirtual.com` **es la misma IP** que autoriza el `ip4:`, así que el
SPF autoriza al servidor **por dos caminos independientes** (`+a` y `+ip4:70.32.23.72`).
Un cambio de IP que se olvidara de actualizar el `ip4:` seguiría autorizado por el `+a`.

**`admin@micolevirtual.com` es la salida buena**: dominio que existe, MX propio, SPF que
autoriza la IP desde la que se envía.

> **El buzón `admin@micolevirtual.com` EXISTE — lo confirmó Joseth el 2 sep 2026**, mirándolo
> en el servidor. Queda escrito con nombre y fecha a propósito: es un hecho del panel que **no
> se puede rehacer desde el repositorio**, y dentro de tres meses «lo confirmó Joseth el 2 sep
> 2026» se puede volver a preguntar mientras que «tiene buzón» no se puede volver a comprobar.
> Era lo último que faltaba, porque el SPF autoriza a *enviar* y no dice nada de si alguien
> **recibe los rebotes**. **Con esto la parte del correo queda cerrada entera.**

### Y la comprobación de `null` también se repitió, porque es contraintuitiva

`.env.example` avisa «Si están vacíos, Laravel rechaza el envío». Pero `null` **no es
vacío**, y la duda razonable era si `env('MAIL_FROM_ADDRESS', 'hello@example.com')` caería
al valor por defecto. **No cae.** Medido dentro del contenedor:

| `.env` | `Env::get('X', 'POR-DEFECTO')` |
|---|---|
| `X=null` | `NULL` ← **el defecto no se aplica** |
| `X=(null)` | `NULL` |
| `X=` | `''` |
| `X=admin@x.com` | `'admin@x.com'` |

Laravel convierte la **cadena** `null` en un null real y lo devuelve como valor presente,
así que el segundo argumento de `env()` no llega a usarse. `config('mail.from.address')`
sale `null` y `Mail` aborta.

**Consecuencia práctica y tranquilizadora**: `correo:probar` **sí lo detecta**.
`ProbarCorreo.php:47` hace `if (! $remitente)` y `null` es falsy, así que imprime
`SIN DEFINIR <-- MAIL_FROM_ADDRESS` y sale con 1. **La herramienta funciona; nadie la
corrió en ese colegio.**

### Lo que se ha corregido en este commit y lo que no

- ✅ `.env.example:110` → `admin@micolevirtual.com`, con el porqué al lado.
- ✅ [`DESPLIEGUE-REFERENCIA.md:456`](../DESPLIEGUE-REFERENCIA.md) — el bloque que **manda
  poner** el remitente.
- ❌ **La línea 464 se deja como está.** Es el `From:` crudo del `mail()` viejo:
  `$headers .= "From: MiColegioVirtual <josethmaster@lalvirtual.com>\r\n";`. **No es una
  instrucción, es la prueba de dónde salió el dominio muerto** y por qué estuvo tantos meses
  sin que nadie lo notara. Reescribirla borraría el origen del fallo.
- ❌ **Los `.env` de los dieciséis colegios**: eso lo toca Joseth. Ninguna sesión.

---

## 3. `MAIL_MAILER` · **MEDIDO: el arreglo del correo estaba aplicado en 1 de 17, y era `demo`**

**Estado: censado y arreglado el 3 sep 2026.** Esta sección decía «en cuántos de los dieciséis
se llegó a aplicar **no lo sabe nadie**». Ya se sabe, y la respuesta es peor de lo que la
pregunta sugería.

### El censo, sobre las diecisiete carpetas

```
16 de 17   MAIL_MAILER=smtp   MAIL_HOST=mailhog   MAIL_FROM_ADDRESS=null   MAIL_FROM_NAME="${APP_NAME}"
 1 de 17   demo:  MAIL_MAILER=sendmail   MAIL_FROM_ADDRESS=josethmaster@lalvirtual.com
```

**Dieciséis eran idénticos, y eran el andamiaje de desarrollo sin tocar.** No es que
`cads-itagui` estuviera mal configurado: es que **ningún colegio real se configuró nunca**. El
único al que se le aplicó el PR #3 es **`demo`** — el que no es un colegio— y allí apuntaba al
dominio que no existe, así que tampoco salía.

> **La consecuencia no era un riesgo, era una función caída.** `MAIL_FROM_ADDRESS=null` hace
> que Laravel rechace el envío **antes de intentarlo**, así que **el reseteo de contraseña
> devolvía 500 en los dieciséis colegios reales** — desde el día que se cambió `mail()` por
> `Mail`. Nadie lo notó porque el reseteo se usa **menos de una vez al día** y el síntoma es
> correo que no llega.

### Y aquí este documento se corrige en su propia tesis

El título dice que **los `.env` no son uniformes**, y para `APP_KEY` era cierto (§1). **Para
`MAIL_*` resultó lo contrario: dieciséis idénticos.** El peligro no era la divergencia — era
que **toda la documentación daba por aplicado un cambio que no estaba en ningún sitio**, y un
apartado marcado `PENDIENTE` se leyó durante meses como «pendiente en alguno».

*Las dos formas hacen el mismo daño y por eso las dos están en este documento: **«cada uno
tiene lo suyo» y «todos tienen lo mismo» son igual de indistinguibles desde el repositorio**.
Lo único que las separa es el bucle.*

### El arreglo, aplicado y verificado

Los cuatro campos puestos en los diecisiete —`sendmail`, remitente `admin@micolevirtual.com`,
nombre `"MiColegioVirtual"`, `MAIL_SENDMAIL_PATH` vacío—, con respaldo de cada `.env` **fuera
del docroot** y `config:clear && config:cache` detrás. **17 tocados, 0 saltados**, y el `diff`
entre el censo de antes y el de después mueve las diecisiete líneas.

> **`MAIL_HOST=mailhog` se dejó donde estaba a propósito**: con `MAIL_MAILER=sendmail` no se
> lee, y borrarlo habría tocado un colegio que tuviera SMTP real. *El censo demostró que
> ninguno lo tiene* — pero eso **se supo después de mirar**, que es el orden correcto.

### Y la única prueba que vale: llegó

**Verificado el 3 sep 2026 con `correo:probar` contra un buzón real, y el correo llegó.** Todo
lo anterior demostraba que la *configuración* era correcta; esto demuestra que **sale, se
relevа y se entrega**.

**La primera pasada rebotó, y el rebote enseñó más que un envío bueno.** Se mandó al marcador
de posición `TU@CORREO.com` —que es un dominio **real, con MX**, así que aceptó la conexión y
rechazó el usuario con un `550 5.1.1`—. Las cabeceras del rebote traían la confirmación entera:

```
X-Postfix-Sender:  rfc822; admin@micolevirtual.com     <- el arreglo llegó al envío, no sólo al fichero
Reporting-MTA:     dns; relay.mailchannels.net         <- la salida NO es directa del servidor
```

> **Y eso corrige un razonamiento de este mismo documento.** El §1 y la primera versión del
> script excluían `lal` porque su IP (`70.32.23.70`) no está en el `ip4:` del SPF de
> `micolevirtual.com`. **La premisa era falsa: el servidor no envía directo.** La cadena real es
>
> ```
> micolevirtual.com -> include:spf.a2hosting.com -> include:relay.mailchannels.net -> ip4:23.83.208.0/20
> ```
>
> o sea que **la IP que envía nunca es la del servidor**, y el `ip4:70.32.23.72` no es lo que
> autoriza estos mensajes. Da igual desde qué cuenta de a2hosting salgan: pasan por el
> `include`. *El razonamiento sobre SPF era correcto para un modelo de envío que este servidor
> no usa — y sólo se vio al leer las cabeceras de un mensaje real.*

> **Lo que quedó fuera del alcance, y hay que decirlo:** el script barre `/home/micolev1/*`, y
> **la instalación viva de `lal` está en la otra cuenta**. Su `.env` sigue sin medir y sin
> arreglar. Es la misma instalación número dieciocho del §1.

> **Y una cuenta que no cuadra y queda abierta:** de los diecisiete envíos de la primera pasada
> **volvieron cuatro rebotes**, no diecisiete. Los otros trece pueden estar en cola, haber sido
> limitados por MailChannels o no haber salido. **No se sabe, y la diferencia importa**: un
> límite de envíos por hora convertiría una tanda de recuperaciones en correo perdido sin
> ningún error. Se contesta contando cuántos llegan en la pasada completa.

### Dos cosas que se midieron por el camino y corrigen lo que se había escrito

**`MAIL_FROM_NAME="${APP_NAME}"` no estaba roto.** Llegó de otra sesión como «hereda
*Laravel*», y es inexacto: el Dotenv de Laravel **sí interpola**, comprobado ejecutándolo, así
que resolvía al `APP_NAME` de cada colegio. *Una variable sin definir sí se queda literal
(`${NO_DEFINIDA}`), y esa es la forma en que este campo sí podría fallar.*

**Y la exclusión de `lal` de la primera versión del script era un error mío.** La excluí por
SPF, razonando sobre dónde **sirve** `lal` hoy (`70.32.23.70`, cuenta vieja). Pero **la carpeta
que el script toca vive en `micolev1`, que es `70.32.23.72`**: cualquier correo que envíe esa
instalación sale de `.72`, donde `admin@micolevirtual.com` **sí** está autorizado. *El
razonamiento sobre el SPF era correcto y se aplicó al servidor equivocado.*

---

## 4. Las 52 variables que el código lee y `.env.example` no documenta

**La consecuencia: una variable que no está en `.env.example` es una variable cuya
divergencia entre colegios no va a notar nadie.** No hay dónde compararla.

De las 52, las que llevan una decisión encima:

| Variable | Qué gobierna | Por qué importa que no esté documentada |
|---|---|---|
| `SESION_ACCESO_TTL`, `SESION_REFRESCO_TTL`, `SESION_LEGADO_TTL`, `SESION_GRACIA_REFRESCO` | **la vida de los tokens y los 30 s de gracia al rotar** ([`07-sesion.md`](07-sesion.md)) | un colegio con otro TTL tiene otro comportamiento de sesión y **no hay línea en `.env.example` donde se vea** |
| `NOTIFICACIONES_SECRETO` | el secreto de los temas de push, **por delante de `APP_KEY`** | es la salida del §1 y **no está escrita en ningún sitio que un despliegue vaya a leer** |
| `CONSULTAS_LENTAS_CANAL` | dónde se escribe el registro de consultas lentas | el interruptor (`CONSULTAS_LENTAS_MS`) sí está documentado; su canal no |
| `FCM_CREDENCIALES` | la ruta del JSON de la cuenta de servicio | es el paso 2 de encender el push y no aparece en la plantilla |
| `DB_TEST_*` (5) | la base de tests por sesión | de desarrollo, sin consecuencia en producción |

El resto son valores de andamiaje de Laravel que este proyecto no usa (AWS, Pusher,
Memcached, SQS, Dynamo, Papertrail, Mailgun, Postmark).

---

## 5. `CORS_ALLOWED_ORIGINS` · **DECIDIDO por Joseth el 3 sep 2026: `*` para todos, y no es una omisión**

**Censado**: presente en **1 de 17** (`FRONTEND_URL`, también en 1). Ausente,
`config/cors.php:43` cae a `['*']`, así que dieciséis servían con el origen abierto.

**Y así se queda, por decisión y con motivo**: *cada colegio recibe conexiones de aplicaciones
externas*, así que una lista blanca de orígenes no describe a los clientes reales de esta API.
**El apartado deja de estar `PENDIENTE`: está contestado.**

### Por qué `*` aquí no regala nada, que es lo que hay que comprobar antes de dar una decisión así por buena

Medido, no supuesto:

| | |
|---|---|
| `supports_credentials` | **`false`** (`config/cors.php:71`) |
| Cómo viaja el token | **`Authorization: Bearer`** (`Sesion.php:412`, `bearerToken()`) |
| Cookies de sesión en la API | ninguna |

Con `supports_credentials => false` el navegador **no manda cookies** a otro origen, y el token
no va en cookie: **lo tiene que adjuntar el JavaScript a mano**. O sea que una página cualquiera
puede *llamar* a la API, pero **sigue necesitando un token**, y el token vive en el
almacenamiento del cliente legítimo, que es por origen. *`*` abre la puerta; no reparte llaves.*

> **Lo que sí cambiaría el análisis, y por eso queda escrito:** si algún día se pusiera
> `supports_credentials => true`, `*` dejaría de ser válido —el propio navegador rechaza esa
> combinación— y habría que volver aquí. Y si la sesión pasara alguna vez a cookie, `*` sí
> repartiría llaves. **Las dos son cambios de una línea en `config/`, así que este razonamiento
> depende de dos valores que hoy nadie está mirando.**

### `FRONTEND_URL`: 1 de 17, y es una bomba de relojería, no un fallo de hoy

Es el **respaldo** de la URL de retorno del reseteo
([`LoginController.php:635`](../../app/Http/Controllers/LoginController.php#L635)): si el
cliente manda una `ruta` cuyo host coincide con el de la petición, se usa ésa; si no, se usa
`FRONTEND_URL`; y si tampoco existe, **`abort(422)`**.

**Hoy no afecta a nadie** y el motivo correcto no es que todos compartan host —la app Flutter
no lo hace— sino que **la app Flutter no tiene recuperación de contraseña**
([04 §…](04-auditoria-autenticacion.md)). El día que se la añadan, **el reseteo desde el móvil
dará 422 en dieciséis colegios**, porque una app nativa no tiene `location.origin`. *Estaba
escrito como hipótesis desde la auditoría de autenticación; ahora está contado.*

### Y cuando se mida: **cómo NO medirlo**, que lo trajo el otro repositorio

Lo levantó `myvc-horarios-42` el 3 sep 2026 yendo a mirar dónde pone su programa el
`Origin`, y la respuesta es que **no lo pone nadie a mano y no se puede**: `Origin` es un
*forbidden header name* del Fetch — si un cliente lo mete, el navegador lo tira sin decir
nada. Lo pone **la ventana**, y depende de quién sirve la aplicación:

| quién corre | qué `Origin` manda |
|---|---|
| `tauri dev` | `http://localhost:4310` |
| el `.app` construido | `tauri://localhost` |
| un arnés de Node | **ninguno** |

**De ahí salen dos formas de medir esto que dan verde sin medir nada:**

1. **Una comprobación que corra desde Node no está midiendo CORS en absoluto**, aunque
   pase: sin `Origin`, el middleware no tiene nada que comparar y la petición sale igual.
2. **Un `curl -H 'Origin: tauri://localhost'` dice qué hace el servidor con esa cadena, no
   qué manda la ventana.** Sirve para contestar *«¿lo acepta la lista?»* y **no** para
   *«¿funciona la aplicación?»* — son dos preguntas y sólo se parecen.

> **Comprobado el 3 sep 2026: en este repositorio no hay ninguna medición de CORS, ni bien
> ni mal hecha.** `grep -rn 'Origin' tests/ tools/` da cuatro resultados y **los cuatro son
> falsos positivos** —`getClientOriginalName`, `$leidaOriginal`—. O sea que no hemos caído
> en la trampa, pero no por cuidado: **es que nunca se ha mirado**. La distinción importa
> el día que alguien vea «cero hallazgos» y lo lea como «cero problemas».

**Y una que nadie ha medido de ninguno de los dos lados**: `tauri://localhost` está
comprobado **en macOS y sólo en macOS**. Si el ejecutable de Windows manda el mismo origen,
no lo sabe nadie.

---

## 6. `APP_DEBUG` · **MEDIDO: estaba en `true` en cinco colegios. Arreglado el 3 sep 2026**

**Era el pendiente más viejo de la lista** —[`01:395`](01-plan-seguridad.md) decía «**Verificar
producción**», [`09:1159`](09-pendientes.md) decía «comprobarlo colegio a colegio»— y llevaba
así desde el principio porque comprobarlo exigía la sesión del servidor. Comprobado:

```
APP_DEBUG=true    5 de 17   caz-zaragoza · coabsaravena · coal · inseaq · maranathaarauca
APP_DEBUG=false  12 de 17
```

**La consecuencia no era teórica.** Con `APP_DEBUG=true` el cuerpo de cualquier 500 lleva
`Host`, `Port` y `Database`, y esta API tiene **doce rutas públicas sin token** — entre ellas
`PUT login/crear-prematricula`, viva y con `withoutMiddleware('auth.token')`
([`routes/api/auth.php:49`](../../routes/api/auth.php#L49)), que es justo la que la casilla
1bis describe llegando a un 500.

**Arreglado**: `APP_DEBUG=false` en los cinco, con respaldo fuera del docroot y
`config:clear && config:cache` detrás. Los cinco dieron `OK`.

### Y `APP_ENV=local` en quince, que asusta y no es lo que parece

Sólo `demo` y `eal` están en `production`. **Pero el código no consulta `APP_ENV` ni una vez**
—cero usos de `App::environment()`, `app()->environment()` y `config('app.env')` en `app/`,
`config/`, `bootstrap/` y `routes/`, contados—, así que **no cambia ningún comportamiento de la
aplicación**. Lo que sí cambia es que **artisan no pide confirmación** para los comandos
destructivos: en quince colegios un `migrate:fresh` correría sin preguntar. *Es riesgo
operativo, no de exposición, y conviene no venderlo como lo segundo.*

---

## 7. El método ya existe en este repositorio, y ya destapó una divergencia

Nada de lo de arriba necesita una herramienta nueva. `DESPLIEGUE.md` corrió el 2 sep 2026
**el barrido bien hecho** —un bucle sobre las diecisiete carpetas, para
`APP_MOVIL_VERSION_MINIMA`—, y hay que leer lo que le pasó:

```
16 carpetas de colegio  ->  ausente (ni la línea)
demo                    ->  APP_MOVIL_VERSION_MINIMA=   (presente y VACÍA)
```

**Ya salió un `.env` distinto de los demás**, y se archivó con razón porque en *esa*
variable ausente y vacía son lo mismo. Pero el dato que llevaba dentro —**los `.env` no son
copias idénticas**— no se generalizó a ninguna otra variable. Y **de paso ese mismo barrido
destapó que el servidor tenía dieciséis colegios y no quince**, que es la clase de cosa que
sólo aparece cuando se recorre la población entera.

**La forma de cerrar este documento es un solo bucle** que imprima, por colegio, las
variables que llevan una decisión encima:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '\n== %s\n' "$(basename "$(dirname "$d")")"
  grep -E '^(MAIL_MAILER|MAIL_FROM_ADDRESS|MAIL_HOST|APP_DEBUG|APP_ENV|CORS_ALLOWED_ORIGINS|FRONTEND_URL)=' "$d/.env" \
    || echo '  (ninguna de las siete)'
  printf '  APP_KEY(hash) %s\n' "$(grep -E '^APP_KEY=' "$d/.env" | md5sum | cut -c1-12)"
done
```

**Es de sólo lectura y no saca ningún secreto**: `APP_KEY` sale como hash, y las otras siete
no son secretas. Lo corre quien tenga la sesión del servidor — **no una sesión de Claude**,
que no la tiene.

> **CORRIDO ENTERO el 3 sep 2026 (Joseth), y las siete variables están medidas.** No quedó
> ninguna por mirar, y **cinco de las siete dieron hallazgo**: `APP_KEY` compartida entre dos
> colegios (§1), el correo sin configurar en dieciséis (§3), `APP_DEBUG` encendido en cinco
> (§6), `CORS_ALLOWED_ORIGINS` en uno solo (§5, y resultó ser la decisión correcta) y
> `FRONTEND_URL` en uno solo (§5, latente). **Y salió una octava medición que nadie había
> pedido: los diecisiete tienen cinco juegos de claves distintos**, que es la fila que sostiene
> el título de este documento. *Y una lección del que sí se corrió, para cuando se corra
> éste:* la primera salida fue un `uniq -d` a secas, que dice **que hay** un repetido pero
> **ni cuál ni cuántos** — hizo falta una segunda pasada con el nombre al lado y la población
> al final para poder escribir nada. **Un detector que contesta «sí» sin decir «quiénes» no
> cierra el asunto**; y una salida vacía sin población no distingue «miré diecisiete y ninguno»
> de «el bucle no miró nada».

---

## 8. Lo que queda pendiente, con su consecuencia y de quién es

Ordenado por lo que pasa si no se hace. **Nada de esto bloquea nada hoy**; está aquí para que
no haya que volver a descubrirlo.

| # | Pendiente | Si no se hace | De quién |
|---|---|---|---|
| 1 | **La instalación viva de `lal`**, en la cuenta vieja (`micolevi`, `lalvirtual.edu.co`). El censo barre `/home/micolev1/*` y **no la alcanza**: su `APP_KEY` y sus `MAIL_*` siguen sin medir ni arreglar | Es la **instalación número dieciocho**. El día que se diga «están todas comprobadas», ésa no lo está — y su correo sigue como estaban los otros dieciséis antes del 3 sep | Joseth (sesión del otro cPanel) |
| 2 | **La cuenta de rebotes: volvieron 4 de 17.** Cola, límite de MailChannels, o no salieron | Un **límite de envíos por hora** convertiría una tanda de recuperaciones en correo perdido **sin ningún error**. Se contesta contando cuántos llegan en una pasada completa | Joseth |
| 3 | **El logo del correo de recuperación** sale de `https://lalvirtual.edu.co/up/images/…` ([`reset-password.blade.php:23`](../../resources/views/emails/reset-password.blade.php#L23)). Hoy responde **200**; en `micolevirtual.com` esa ruta da **404** | **El correo de los dieciséis depende de que un colegio conserve su dominio.** Es cambio de código y de dónde alojar el fichero, no de `.env` | decisión de Joseth, luego una sesión |
| 4 | **`FRONTEND_URL` en 1 de 17** | **Latente, no roto**: el día que la app Flutter tenga «olvidé mi contraseña», el reseteo dará **422 en dieciséis** — una app nativa no tiene `location.origin` (§5) | esperar a que se pida |
| 5 | **Limpiar lo muerto**: `JWT_*` (16 de 17), `AWS_*`, `PUSHER_*`, `MIX_PUSHER_*`, `MEMCACHED_HOST`, `REDIS_*`. Seguro: `FILESYSTEM_DRIVER=local` y `BROADCAST_DRIVER=log` en los diecisiete, y cero usos de `s3` o de broadcasting | **Nada.** Es higiene: no arregla ni rompe. *Va el último a propósito — borrar líneas que no hacen nada no mejora ningún comportamiento, y tocar diecisiete `.env` sí tiene riesgo* | cuando apetezca |
| 6 | **Los cinco juegos de claves no están desglosados**: se sabe **cuántos** grupos hay y quién está en cada uno, no **qué línea** separa a un grupo de otro | Sin el desglose, «cinco juegos» es un número sin acción detrás | una sesión, con `diff` sobre `~/claves/` |
| 7 | **`APP_ENV=local` en quince** (sólo `demo` y `eal` en `production`) | **No cambia el comportamiento** —cero usos de `APP_ENV` en el código, contados—, pero **artisan no pide confirmación**: un `migrate:fresh` correría sin preguntar en quince | Joseth |
| 8 | **`FCM_CREDENCIALES` en 0 de 17** (`FCM_PROYECTO` sí está en algunos) | Falta **la mitad del push**, y es esperable: no hay proyecto de Firebase todavía. Va con el punto 2 de la casilla del push | Joseth, cuando exista Firebase |

> **Y una que no es una tarea sino una condición**, del §1: **rotar `APP_KEY` con el push ya
> encendido re-apunta todos los temas** y los teléfonos siguen escuchando el nombre viejo. Los
> avisos dejan de llegar **sin un solo error**. Cualquier rotación futura va **antes** de
> encender Firebase.

---

## Lo que este documento NO hace, y es a propósito

- **No toca ningún `.env` de ningún colegio.** Eso lo decide y lo ejecuta Joseth.
- **No reescribe ninguna cifra fechada.** Lo medido sobre quince colegios sigue diciendo
  quince: se actualiza lo que sigue vivo, no lo que se midió.
- **No corrige `CLAUDE.md`.** Si algo de aquí obliga a moverlo, lo autoriza Joseth.
- **No afirma que los otros quince estén rotos.** Afirma lo contrario de lo que se venía
  afirmando: que **no se sabe**, y que la diferencia entre «no se sabe» y «está bien» es un
  bucle de seis líneas que nadie ha corrido.

> **Y el 3 sep 2026 se corrió el primero de esos bucles, el del §1 — y salió el hallazgo.**
> O sea que la respuesta a «¿estaba bien?» era **no** en la primera variable que se miró, y
> lo era desde el 30 ago sin que nada lo señalara. Eso no convierte en rotas las otras seis
> del §7: las deja **exactamente igual de sin medir que antes**, sólo que ahora se sabe que
> «se creó copiando» sí produce colisiones reales en este servidor y no sólo en teoría.
