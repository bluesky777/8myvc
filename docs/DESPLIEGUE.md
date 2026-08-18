# Pendientes de despliegue

## Topología: cómo está montado esto

**Léelo antes de tocar nada.** Casi todas las decisiones de despliegue de este
proyecto se explican por aquí, y es lo que más caro sale suponer mal.

### Un colegio = un subdominio con todo dentro

Hay **dos alojamientos compartidos con cPanel**. A cada colegio o cliente se le crea
**un subdominio con su carpeta**, y ahí dentro va todo desde cero:

```
<colegio>.dominio/
├── 8myvc/          el backend (este repo)
└── up/             el frontend web (myvc_front, renombrado)
```

Y **su propia base de datos**, separada de la de los demás.

### Qué está copiado y qué está compartido por symlink

**Aquí no basta con "cada colegio tiene lo suyo": hay una parte compartida y una
copiada, y se comportan al revés.** Confirmado por Joseth el 18 ago 2026.

| | Cómo está | Un cambio llega a… |
|---|---|---|
| `app/`, `routes/`, `config/`, `.env` | **Copia real en cada colegio** | …solo al colegio donde se despliega |
| `vendor/` | **Una sola carpeta real**; los demás colegios la apuntan con **symlink** | …**todos los colegios a la vez** |

Lo que era falso era la creencia de que `app/` también se compartía por symlink
—un proyecto llamado `coal` común a todos—. `app/` es copia real. `vendor/` no.

> **Consecuencia 1, la que más se olvida: un arreglo fusionado NO está
> desplegado.** Llega a cada colegio por su propio despliegue. Un agujero cerrado
> en `main` sigue abierto en todos los colegios que aún no han recibido el código.
> "Arreglado" y "desplegado en el colegio X" son cosas distintas.

> **Consecuencia 2, la contraria y menos evidente: un `composer` cambia todos los
> colegios de golpe.** No hay despliegue gradual posible para las dependencias.
> El que corra `composer install` o `composer update` sobre la carpeta real está
> tocando la producción de todos los colegios en ese instante, incluidos los que
> siguen con código de `app/` de hace meses.

**Las dos juntas son el riesgo real:** `vendor/` avanza para todos a la vez
mientras `app/` avanza colegio a colegio, así que existe siempre la combinación
"dependencias nuevas + código viejo". Cualquier cambio de dependencia tiene que
ser compatible con **el `app/` más antiguo que haya desplegado en algún colegio**,
y nadie lleva ese inventario.

Por lo mismo que `app/` es copia, cualquier cambio de configuración —`MAIL_*`,
`CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`— hay que hacerlo **en el `.env` de cada
colegio**, uno por uno.

#### Lo que hay que confirmar antes de la Fase 4

Esto no es un detalle de despliegue: **decide cómo se puede hacer el salto de
Laravel 8 a 13.** Con `vendor/` compartido, subir el framework lo sube para todos
los colegios en el mismo instante, mientras que el `app/` adaptado llega colegio
a colegio. Eso no se puede escalonar: o se rompen los colegios que aún no tienen
el código nuevo, o hay que desplegar los 3 a la vez y sin marcha atrás por
colegio.

La salida limpia es **dejar de compartir `vendor/` antes de la Fase 4**: darle a
cada colegio su propia carpeta real, y con eso el salto de framework se despliega
como todo lo demás, uno por uno y con vuelta atrás por colegio.

Falta comprobar en el servidor, y conviene hacerlo antes de planificar la Fase 4:

- Si hay **algo más** compartido por symlink además de `vendor/` (`storage/`,
  `public/`, `bootstrap/cache/`). `bootstrap/cache/` importa especialmente ahora:
  ahí es donde caen `route:cache` y `config:cache`, y si estuviera compartida
  un colegio serviría las rutas de otro.
- **En cuál de los dos alojamientos** está la carpeta real, y qué colegios
  cuelgan de ella. Son dos hosts, así que como mínimo hay dos vendor reales.

### Cuatro clientes, no uno

| Cliente | Qué es | Despliegue | ¿Comparte host con la API? |
|---|---|---|---|
| **`myvc_front`** | Web, AngularJS 1.8 + Vite | Uno por colegio, en la carpeta `up` de su subdominio | **Sí**, siempre |
| **`myvc_front_2`** | Web, **Angular 17** · solo la funcionalidad de **PIAR** | Uno por colegio, en la carpeta `plus` de su subdominio | **Sí**, siempre |
| **`myvc_flutter`** | App móvil y web, Flutter | **Una sola para todos los colegios** | **No** |
| `8myvc` | Esta API | Uno por colegio, carpeta `8myvc` | — |

**`myvc_front_2` es fácil de olvidar** porque no se parece a los otros: es una
aplicación aparte, en Angular moderno, que cubre **una sola funcionalidad** —el
Plan Individual de Ajustes Razonables— y se publica en `plus/` junto a `up/`. La
intención a futuro es **absorber su funcionalidad en el proyecto principal cuando
`myvc_front` se migre a Angular** (Joseth, 18 ago 2026); hasta entonces son dos
front distintos sobre la misma API.

Consume **catorce rutas**, todas de `piars-*` salvo `grupos`, `years` y `login`.
Manda `Authorization: Bearer` en todas por un interceptor
(`core/interceptors/auth.interceptor.ts`), igual que `myvc_front`. Es para el
**personal**: comprueba `tipo === 'Profesor'` contra el titular del grupo
(`core/services/profile.service.ts`).

> Sus scripts de build nombran **seis subdominios** —`casb`, `coab`, `cads`,
> `coljordan`, `lal` y `coal`—, cada uno con su `--base-href`. Es el inventario
> de colegios más concreto que hay escrito en algún repo, y de paso aclara de
> dónde salía la confusión del proyecto "coal compartido": **`coal` es un
> colegio**, no un proyecto común.

La app Flutter es la que rompe la intuición: **no se despliega por colegio**. Es una
sola aplicación, y **en la pantalla de login el usuario elige el servidor de su
colegio**; a partir de ahí todo apunta a esa URI. Construye la base así
(`lib/Http/Server.dart`):

```dart
Server.urlApi = '$servidor/8myvc/public/api';
```

O sea que su origen **no** es el subdominio del colegio, y en la build nativa no tiene
origen web en absoluto.

### Por qué esto importa para el código

1. **Guardas que comparan el origen.** `ruta_frontend_segura()` exige que el host del
   parámetro `ruta` coincida con el de la petición. Hoy no molesta a nadie, pero **no
   porque todos compartan host** —la app Flutter no lo hace—, sino porque **la app
   Flutter no tiene recuperación de contraseña**, y esa es la única función que usa esa
   comprobación. Si algún día se le añade, dará 422 en todos los colegios y hará falta
   `FRONTEND_URL` en cada `.env`, o una excepción para clientes sin origen web.

2. **Cualquier cambio que rompa el contrato del front** hay que coordinarlo **por
   colegio**, no una vez: los dos front web se publican colegio a colegio, pero la app
   Flutter se actualiza para todos a la vez. Un cambio que rompa a la app Flutter rompe
   a todos los colegios de golpe.

   Y son **dos** front web, no uno: un cambio en las rutas `piars-*` no lo nota
   `myvc_front` —no las usa— sino `myvc_front_2`, que se publica aparte.

3. **Orden de despliegue.** Cuando un cambio del backend habilita algo que el front
   necesita, en cada colegio va **primero el backend**. Al revés queda roto.

### Un punto único de fallo fuera de este repo

Antes de elegir colegio —y por tanto antes de cualquier login—, la app Flutter pide
el directorio de colegios a:

```
POST https://micolevirtual.com/app/listado_colegios.php
```

Es un PHP suelto en un host central: no es Laravel, no está en ningún backend por
colegio, y no aparece en ninguna auditoría de rutas de este repo.

**Si ese fichero se cae, la app móvil no arranca en ningún colegio**, porque no
puede ni ofrecer la lista de servidores donde elegir. El front web no se ve
afectado: cada colegio tiene el suyo en su propio subdominio.

### Cómo comprobar qué hay desplegado en un colegio

No hay inventario en el repo. La única fuente fiable es mirar el subdominio del colegio
directamente.

**Joseth confirma (18 ago 2026) que todos los colegios se actualizan siempre con las
últimas PRs.** No hay colegios que se queden atrás a propósito.

Eso acorta la ventana, pero **no la elimina**: entre que una PR se fusiona y llega a
cada subdominio hay un despliegue de por medio, y son varios. Sigue siendo cierto que
*fusionado* no es *desplegado*, y sigue haciendo falta desplegar el backend antes que
el front en cada colegio.

---

## Del PR #3 (seguridad — 4 críticos)

### 1. Poner el correo en `sendmail` — PENDIENTE

**Decidido: se configura `sendmail` sin auditar el `.env` de cada colegio.**
`sendmail` reproduce el camino que usaba la función `mail()`, así que es el valor
que menos probablemente cambie el comportamiento.

**En el `.env` de cada colegio:**

```env
MAIL_MAILER=sendmail
MAIL_FROM_ADDRESS=josethmaster@lalvirtual.com
MAIL_FROM_NAME="MiColegioVirtual"
```

Las dos de `FROM` **no son opcionales**. El código viejo llevaba el remitente
incrustado a mano en las cabeceras del `mail()`:

```php
$headers .= "From: MiColegioVirtual <josethmaster@lalvirtual.com>\r\n";
```

Ahora sale de la configuración. Si están vacías, Laravel **rechaza el envío antes
de intentarlo** y el reseteo devuelve 500. Los valores de arriba son exactamente
los que enviaba el código anterior, así que el correo sale igual que siempre.

**Comprobar en 30 segundos, sin provocar un reseteo real:**

```bash
php artisan correo:probar tu-correo@ejemplo.com
```

Imprime el transporte, si el binario de sendmail existe, el `sendmail_path` que
usa PHP y el remitente configurado. Si falla, dice cuál de los tres es.

**VERIFICADO EN EL SERVIDOR (17 ago 2026).** Ya no es una hipótesis. Medido con
un script temporal en el docroot, bajo el SAPI real:

| | |
|---|---|
| SAPI web | `litespeed` |
| PHP | 8.0.30, `php.ini` en `/opt/alt/php80/etc/php.ini` |
| `sendmail_path` | `/usr/sbin/sendmail -t -i` |
| binario | existe, ejecutable, no es enlace |
| `mail()` | disponible, `disable_functions` vacío |

**Web y CLI cargan el mismo `php.ini`**, comprobado por separado. Tres
consecuencias:

1. **`MAIL_SENDMAIL_PATH` se deja vacío.** El valor por defecto de
   `config/mail.php` resuelve a `/usr/sbin/sendmail -t -i` en ambos contextos.
2. **`config:cache` es seguro.** No hay divergencia CLI/web que congelar mal.
   Si algún día dejaran de coincidir, habría que fijar `MAIL_SENDMAIL_PATH`
   explícitamente antes de cachear.
3. **`correo:probar` es fiable.** Lo que reporta en la terminal es lo que hará
   el reseteo real por web.

Este servidor es exactamente el caso que motivó el cambio: el valor por defecto
de Laravel (`-bs`) habría fallado aquí con *"Expected response code 220 but got
an empty response"*. Leer el `php.ini` da el mismo invocador que usaba `mail()`.

Estado completo del entorno en `PHP-BASELINE.md`.

**Confirmar que salió de verdad:** cPanel → *Rastreo de entrega*. `correo:probar`
solo garantiza que Exim aceptó el mensaje, no que llegara. Ahí se vería un
problema de SPF con `lalvirtual.com`.

**Dato de la comprobación local, por si ayuda a interpretar un fallo:** en el
contenedor de desarrollo `mail()` **ya devolvía `false`** — `sendmail` es un
enlace a `busybox` y no hay ningún MTA. El código viejo ignoraba ese `false` y
respondía `Enviado` igual. O sea que en cualquier entorno donde el reseteo
"funcionara" sin enviar nada, esto lo va a destapar. Eso no es una regresión: es
el fallo haciéndose visible.

**Qué pasa si esto se olvida:** el reseteo devuelve 500 en vez de fallar en
silencio. A menos de un reseteo al día históricamente, la ventana de exposición
es pequeña, y la causa queda en `storage/logs/laravel.log` en un `Log::error`.
El token no se queda colgado: se borra al fallar el envío.

### 1b. OPcache está activa desde ahora — afecta a CÓMO se despliega

Se activó `opcache` el 17 ago 2026 (estaba apagada; Laravel recompilaba cada
archivo en cada request). Es una mejora grande, pero **cambia el despliegue**:
copiar ficheros ya no basta necesariamente, porque PHP puede seguir sirviendo el
bytecode viejo.

Comprobar el modo antes del primer despliegue:

```bash
php -i | grep -E "opcache.(enable|validate_timestamps|revalidate_freq)"
```

- `opcache.validate_timestamps = 1` (lo habitual): PHP releé los ficheros
  cambiados cada `revalidate_freq` segundos. No hay que hacer nada.
- `opcache.validate_timestamps = 0`: **el código nuevo no se aplica nunca** hasta
  reiniciar. Con LiteSpeed, cPanel → *Restart PHP*, o `killall lsphp`.

Si tras desplegar el arreglo de correo el comportamiento sigue siendo el viejo,
esto es lo primero que hay que mirar — no el `.env`.

Se activó también `sodium`, que faltaba: `lcobucci/jwt` la declara requisito
duro y `composer install` habría fallado el chequeo de plataforma.

### 2. Definir `CORS_ALLOWED_ORIGINS` — PENDIENTE

Sin esta variable el arreglo de CORS **no hace nada**: el fallback sigue siendo `*`,
puesto a propósito para que desplegar el PR no tumbe nada.

```env
CORS_ALLOWED_ORIGINS=https://<dominio del colegio>
```

Varios orígenes van separados por comas. Documentado en `.env.example`.

### 3. Coordinar con el frontend — PENDIENTE

`myvc_front` tiene un typo en `PapeleraCtrl:62` (`'::'` en vez de `'=='`) que impide
abrir el modal de borrado definitivo de grupos. Ese typo es lo único que hoy tapa el
agujero de `grupos/forcedelete` desde la interfaz.

**El typo no debe arreglarse hasta que el backend de ese colegio tenga el guard
desplegado.** No basta con que el PR esté fusionado.

Como el frontend se publica **por colegio**, la condición es por pareja: el typo puede
salir en el colegio X cuando el backend del colegio X ya esté desplegado.

Avisar a la sesión/persona que lleve `myvc_front` cuando cada colegio esté listo.

---

## Del PR #7 (auditoría de autenticación)

### 1. Toda la API pasa a exigir token, menos quince rutas

Este PR sí cambia el comportamiento. El guard `auth.token` se aplica en grupo a
las 533 rutas, y las excepciones se marcan una a una con
`->withoutMiddleware('auth.token')`. Son quince: nueve de entrada al sistema
(`login/*`, `publicaciones/ultimas`) y seis de tardanzas, que autentican con
usuario y contraseña en el cuerpo de cada petición. La lista está en
`docs/migracion/04-auditoria-autenticacion.md`.

**El riesgo de que el front llame sin token está descartado**, y no por los
tests: `myvc_front` pone `Authorization: Bearer` como cabecera **por defecto**
de `$http` (`AuthService.js`), tanto al hacer login como al arrancar con un token
guardado. No hay llamada autenticada que pueda salir sin él.

**Comprobado además golpeando las 533 rutas con un token real de cada tipo de
usuario, antes y después de esta rama.** Para el personal —Usuario y Profesor—
cambia **una sola ruta en 533**, y a mejor: `PUT login/logout`, de 500 a 200.
Para alumnos y acudientes cambian 31, que son exactamente los agujeros que se
cierran. El detalle está en el cuerpo del PR.

### 2. Lo que cambia para alumnos y acudientes

- **Un alumno ya no puede pedir el boletín de un compañero, ni un acudiente el de
  quien no es su acudido, ni con deuda.** Estaba escrito desde hace años y no se
  ejecutaba. El front ya comprobaba el paz y salvo antes de llamar, así que una
  familia al día no nota nada.
- **"Ver mi boletín" empieza a funcionar.** Respondía 500 desde 2021.
- Alumnos y acudientes reciben **403** en `requisitos/*`, `prematriculas/*`,
  `piars-grupos/*` y `certificados-persona`, donde antes entraban.

### 3. `login/ver-pass` se renombra a `login/recuperar-clave` — alias temporal

El nombre viejo engañaba: no muestra ninguna contraseña, manda el correo de
reseteo. **Las dos rutas funcionan**, apuntan al mismo método, así que el
backend se puede desplegar antes que el front.

**El alias se borra cuando el front de TODOS los colegios use la ruta nueva.**
Como cada colegio publica su front por separado, hay que confirmarlo colegio a
colegio, no una vez. `tests/Contrato/RecuperarClaveTest.php` falla el día que se
borre, como recordatorio de que hay que comprobarlo.

### 4. El reseteo ya no dice si un correo existe — cambia lo que ve el usuario

Antes devolvía `'No existe'` para un correo no registrado y `'Enviado'` para uno
registrado, y con eso cualquiera podía averiguar qué correos están dados de alta
en el colegio probándolos uno a uno. **Ahora devuelve `'Enviado'` en ambos
casos.**

Si el front muestra un mensaje distinto según la respuesta, hay que cambiarlo:
ya no puede decir "ese correo no está registrado". Lo correcto es un mensaje
neutro del tipo *"Si el correo está registrado, te llegará un enlace"*.

### 5. Activar `route:cache` y `config:cache` — PENDIENTE, y es la ganancia

Hasta este PR `php artisan route:list` **abortaba con 401**: Laravel instancia el
controlador para leerle el middleware y 24 constructores llamaban a
`User::fromToken()`. Sin poder listar las rutas, `route:cache` era imposible.

Ya no. Las dos cachés se pueden activar, y son las dos optimizaciones más baratas
que da el framework (`docs/migracion/02-plan-rendimiento.md`, punto 3):

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**Hay que volver a ejecutarlas en CADA despliegue, después de copiar el código.**
Una caché de rutas vieja sirve las rutas viejas y no hay ningún síntoma que lo
delate: la aplicación responde, simplemente responde lo de antes. Como el
despliegue aquí es copiar ficheros por colegio, el paso hay que añadirlo a mano
en cada uno.

Si algo va raro después de desplegar, lo primero es `php artisan route:clear &&
php artisan config:clear` y volver a probar: si con eso funciona, es la caché.

**Comprobado que la suite pasa entera con `route:cache` activo**, que es lo que
había que verificar: las quince excepciones se marcan con `withoutMiddleware()`,
y si eso no sobreviviera al cacheado se cerraría la entrada al sistema.

**`config:cache` NO se ejecuta en desarrollo**, y menos antes de los tests:
congela el `.env`, así que `phpunit.xml` deja de poder apuntar a la base de
tests y la suite iría contra la base de desarrollo. No llega a pasar —
`CasoDeContrato` aborta al ver que la base no acaba en `_testing`— pero el
mensaje despista si no se sabe de dónde viene. En producción sí, y ahí es
seguro: web y CLI cargan el mismo `php.ini` (ver más arriba).

---
