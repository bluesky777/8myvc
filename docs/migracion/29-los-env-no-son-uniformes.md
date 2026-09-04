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
| Variables censadas **en los diecisiete** | **2**: `APP_MOVIL_VERSION_MINIMA` (2 sep) y `APP_KEY` (3 sep) |

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

## 3. `MAIL_MAILER` · dos documentos del repositorio dicen lo contrario, y el `.env` real le da la razón al que nadie siguió

**La consecuencia: la decisión «configurar `sendmail`» se tomó explícitamente *sin auditar*,
y hoy sabemos que su punto de partida no era el que se supuso.**

Las dos afirmaciones llevan conviviendo desde el principio:

| Documento | Dice |
|---|---|
| [`DESPLIEGUE-REFERENCIA.md:448`](../DESPLIEGUE-REFERENCIA.md) | «**Decidido: se configura `sendmail` sin auditar el `.env` de cada colegio.**» |
| [`01-plan-seguridad.md:346`](01-plan-seguridad.md) | «usar el `Mail` de Laravel (**que ya está configurado, `MAIL_MAILER=smtp`**)» |

El `.env` de `cads-itagui` dice `smtp`. **Le da la razón al 01** — y el 01 lo escribió como
un dato de paso, no como una medición.

**Lo que esto no tumba**: la decisión de `sendmail` sigue siendo la correcta y está
**verificada en el servidor** (17 ago 2026: `sendmail_path` = `/usr/sbin/sendmail -t -i`,
binario presente, web y CLI con el mismo `php.ini`). Eso se midió de verdad.

**Lo que sí tumba** es la frase que la acompaña: *«Los valores de arriba son exactamente los
que enviaba el código anterior, así que el correo sale igual que siempre»*. En
`cads-itagui` el correo **no salía**, así que ahí no hay ningún «igual que siempre» que
conservar. La frase describe un punto de partida uniforme que no existe.

**Y el apartado sigue marcado `PENDIENTE`**, o sea que en cuántos de los dieciséis se llegó
a aplicar **no lo sabe nadie**. En al menos uno, en ninguno.

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

## 5. `CORS_ALLOWED_ORIGINS` · PENDIENTE, y no se sabe en cuántos colegios se puso

**La consecuencia: donde no se puso, el arreglo de CORS no hace nada y el origen sigue
siendo `*`.** No es una divergencia peligrosa; es una tarea de la que se desconoce el
avance.

> **Y aquí este documento se corrige a sí mismo.** La primera lectura de
> `config/cors.php:43` fue que *ausente* daba `['*']` y *presente y vacía* daba `[]` —o sea
> que un colegio que copiara `.env.example` (que la trae vacía) **bloquearía todos los
> orígenes**—. Es falso: la expresión termina en **`?: ['*']`**, que estaba en la línea
> siguiente a la que se leyó. Comprobado ejecutándolo:
>
> | `CORS_ALLOWED_ORIGINS` | `allowed_origins` |
> |---|---|
> | ausente | `["*"]` |
> | presente y vacía | `["*"]` |
> | `https://x.edu.co` | `["https://x.edu.co"]` |
>
> **Ausente y vacía son lo mismo**, igual que en `APP_MOVIL_VERSION_MINIMA`. El código es
> defensivo y no hay trampa. *Se deja escrito porque el fallo —leer la mitad de una
> expresión y deducir la consecuencia contraria— es el mismo del que va este documento:
> concluir sin medir.*

---

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

## 6. `APP_DEBUG` · ya estaba catalogado como «colegio a colegio», y ahora hay prueba

**La consecuencia: con `APP_DEBUG=true`, el cuerpo de un 500 lleva `Host`, `Port` y
`Database`** — y hay una ruta **pública y sin autenticar** que llega a ese 500
([`ESTADO-ACTUAL.md` casilla 1bis](ESTADO-ACTUAL.md), [`05:12065`](05-codigo-muerto-y-roto.md)).

Esto **no es un hallazgo nuevo**: [`09-pendientes.md:1159`](09-pendientes.md) ya dice
«comprobarlo colegio a colegio» y [`01:395`](01-plan-seguridad.md) dice «**Verificar
producción**». Lo que cambia es el peso de la palabra *comprobarlo*: mientras se creyó que
los `.env` eran copias de la misma plantilla, era una formalidad. **Con un `.env` real
delante que trae tres campos del andamiaje sin tocar, deja de serlo.**

Es, además, la variable que el `.env` de `cads-itagui` permitiría contestar **en un colegio
de los dieciséis** — y nadie ha dicho qué trae. *No se mira aquí porque este documento no
tiene ese fichero.*

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

> **La parte de `APP_KEY` ya está corrida (3 sep 2026, Joseth) y dio positivo: §1.** Las
> otras siete siguen sin mirarse. *Y una lección del que sí se corrió, para cuando se corra
> éste:* la primera salida fue un `uniq -d` a secas, que dice **que hay** un repetido pero
> **ni cuál ni cuántos** — hizo falta una segunda pasada con el nombre al lado y la población
> al final para poder escribir nada. **Un detector que contesta «sí» sin decir «quiénes» no
> cierra el asunto**; y una salida vacía sin población no distingue «miré diecisiete y ninguno»
> de «el bucle no miró nada».

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
