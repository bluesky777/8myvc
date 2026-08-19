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
| `vendor/` | **Depende del colegio.** 5 apuntan por symlink a `/home/micolev1/laravel_compartido`; los otros 11 tienen carpeta propia | …a los 5 del symlink de golpe. A los demás, solo si se les toca uno por uno |

Lo que era falso era la creencia de que `app/` también se compartía por symlink
—un proyecto llamado `coal` común a todos—. `app/` es copia real.

Y de `vendor/` resultó ser falso lo contrario: **no hay una sola carpeta real, hay
doce**, y la mayoría de los colegios no usa la compartida.

#### Inventario real de `vendor/`, 18 ago 2026

Sacado del servidor, no supuesto. Los 16 colegios tienen el mismo commit de `app/`
—`8b5a060`—, y aun así:

| Estado de `vendor/` | Cuántos | Colegios |
|---|---|---|
| Symlink a `/home/micolev1/laravel_compartido`, al día | 5 | `coal`, `colbosque`, `comad-san-andres`, `eal`, `maranathaarauca` |
| Carpeta propia, al día | 2 | `amiguitosdejesus`, `semillitasdedios` |
| **Carpeta propia, congelada en 2021** | **9** | `bethelexplora`, `cads-itagui`, `casb-medellin`, `caz-zaragoza`, `coabsaravena`, `coljordan`, `fortul`, `inseaq`, `instival` |

`instival` además **no es un repositorio git**, así que no recibe `git pull`: es el
único colegio que sigue sin la PR #6.

> **Cerrado el 19 ago 2026: los 16 colegios están desplegados y con el `vendor/`
> igualado.** La tabla de arriba es el estado del 18 de agosto y se deja como
> historia — es la que explica por qué se descubrió todo esto. Ya no hay ningún
> colegio con Laravel de 2021.
>
> **Igualado no es lo mismo que no compartido**, y para la Fase 4 lo que importa
> es lo segundo. Falta confirmar si los cinco de `laravel_compartido` siguen
> colgando por symlink o si ya tienen carpeta propia: mientras cuelguen, un
> `composer` sobre esa carpeta los cambia a los cinco a la vez y el salto de
> framework no se puede escalonar en ellos.

**Cómo se descubrió.** Al pasar el servidor a PHP 8.5, esos 9 colegios empezaron a
devolver `Return type of Illuminate\Support\Collection::offsetExists($key) should
either be compatible with…` en cada petición, y desde el arranque. Es un Laravel
anterior a los parches de compatibilidad con PHP 8.1 de finales de 2021: **no
arranca en 8.1 ni en nada posterior**. Nadie lo sabía porque en PHP 8.0 funcionaban.

> **Consecuencia 1, la que más se olvida: un arreglo fusionado NO está
> desplegado.** Llega a cada colegio por su propio despliegue. Un agujero cerrado
> en `main` sigue abierto en todos los colegios que aún no han recibido el código.
> "Arreglado" y "desplegado en el colegio X" son cosas distintas.

> **Consecuencia 2, la contraria y menos evidente: un `composer` sobre la carpeta
> compartida cambia a cinco colegios de golpe.** El que corra `composer install` o
> `composer update` sobre `/home/micolev1/laravel_compartido` está tocando la
> producción de esos cinco en ese instante, incluidos los que sigan con código de
> `app/` de hace meses. Sobre una carpeta propia solo afecta a su colegio — y por eso
> mismo las carpetas propias se quedan atrás sin que nadie se entere.

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
- ~~**En cuál de los dos alojamientos** está la carpeta real, y qué colegios cuelgan
  de ella.~~ **Contestado el 18 ago 2026**: en el host de `micolev1` la compartida es
  `/home/micolev1/laravel_compartido` y solo cuelgan 5 colegios; los otros 11 tienen
  la suya. Ver el inventario de arriba. Falta hacer lo mismo en el segundo host.

**El matiz que cambió el inventario:** 11 de 16 colegios ya tienen carpeta propia, así
que "dejar de compartir `vendor/`" está hecho en dos tercios… pero de la peor manera,
porque 9 de esas carpetas llevan cinco años sin tocarse. Dejar de compartir no basta:
hay que **igualarlas primero**, y que exista forma de comprobar que siguen iguales.
Para eso se versiona `composer.lock`, más abajo.

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

### Inventario: qué código y qué dependencias tiene cada colegio

Dos comandos para pegar tal cual en el servidor. El primero da commit, estado de
`vendor/` y si es carpeta propia o symlink:

```bash
for d in /home/micolev1/*/8myvc; do
  printf '%-28s ' "$(basename $(dirname $d))"
  printf '%-10s ' "$(git -C "$d" log --oneline -1 --format=%h)"
  grep -q 'ReturnTypeWillChange' "$d/vendor/laravel/framework/src/Illuminate/Collections/Collection.php" 2>/dev/null \
    && printf 'vendor OK    ' || printf 'vendor VIEJO '
  [ -L "$d/vendor" ] && printf 'symlink -> %s\n' "$(readlink "$d/vendor")" || printf 'vendor real\n'
done
```

`ReturnTypeWillChange` vale de marcador porque es el atributo que Laravel añadió a
finales de 2021 para poder correr en PHP 8.1. Si no está, ese `vendor/` es anterior.

El segundo arranca cada aplicación y le pide la versión, así que es **inventario y
prueba de arranque a la vez**: si un `vendor/` está roto, revienta justo ahí.

```bash
for d in /home/micolev1/*/8myvc; do
  printf '%-30s ' "$(basename $(dirname $d))"
  ( cd "$d" && php artisan --version 2>&1 | head -1 )
done
```

Lo esperado es que los 16 digan `Laravel Framework 8.83.29`, que es lo que fija
`composer.lock` y lo único que prueba el CI.

### `composer.lock` va versionado desde ahora

Estuvo en `.gitignore` desde 2021. La consecuencia no se vio hasta agosto de 2026:
**sin lock en el repositorio no existe ninguna fuente de verdad sobre qué versiones
debe tener un colegio**, y `git pull` no puede ni corregir la deriva ni detectarla,
porque `vendor/` también está ignorado —eso con razón—.

El resultado fueron 9 de 16 colegios congelados en un Laravel de 2021 sin que nadie
lo supiera, hasta que un cambio de versión de PHP los tumbó a todos a la vez.

Un detalle que lo confirma: `.github/workflows/ci.yml` ya cacheaba por
`hashFiles('composer.lock')`. Como el fichero no estaba en el repo, esa clave era
constante y `composer install` resolvía sin lock. Versionarlo arregla también eso.

#### Cómo igualar un colegio que se quedó atrás

`vendor/` es portable: `vendor/composer` resuelve todo con `$vendorDir =
dirname(__DIR__)` y no guarda ni una ruta absoluta. Se puede copiar de un colegio
sano a uno atrasado **sin ejecutar `composer` en el servidor**.

Con respaldo y vuelta atrás, de uno en uno:

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
cp -a /home/micolev1/laravel_compartido "$d/vendor.nuevo"
mv "$d/vendor" "$d/vendor.viejo"
mv "$d/vendor.nuevo" "$d/vendor"
( cd "$d" && php artisan config:clear && php artisan --version )
# si algo va mal:
#   mv "$d/vendor" "$d/vendor.malo" && mv "$d/vendor.viejo" "$d/vendor"
```

Son **70 MB por colegio**; los 9 atrasados suman unos 630 MB. Mirar la cuota antes.

**Copia real y no symlink, a propósito:** da el mismo resultado pero no aumenta el
número de colegios atados a la carpeta compartida, que es justo lo que hay que
deshacer antes de la Fase 4.

**Comprobar uno y entrar de verdad a la aplicación antes de seguir con el resto.**
Esos colegios llevan corriendo código nuevo sobre un framework de 2021, combinación
que no prueba nadie; igualar el `vendor/` los devuelve a lo que sí está probado, pero
el cambio hay que verlo funcionar.

#### Si `composer install` es inevitable

Comprobar primero si ese `vendor/` es un symlink:

```bash
readlink -f /home/micolev1/COLEGIO.micolevirtual.com/8myvc/vendor
```

Si apunta a `/home/micolev1/laravel_compartido`, ese `composer install` **toca la
producción de los cinco colegios que cuelgan de ahí**, no solo el que tienes delante.

---

## Procedimiento: desplegar un colegio entero

Validado en `casb-medellin` el 19 ago 2026, backend y front de una vez. Salió bien.
Esto es lo que hubo que hacer, tropiezos incluidos.

### Una vez, no por colegio: el token de GitHub

En hosting compartido la IP la comparten muchas cuentas, y `composer install` agota el
límite de la API de GitHub a mitad de la descarga. Se arregla con un token **sin ningún
scope** —solo lectura pública, sube el límite de 60 a 5.000 peticiones por hora—:

```bash
composer config -g github-oauth.github.com TU_TOKEN
```

Se guarda en `~/.composer/auth.json` y sirve para todos los colegios. Se genera en
<https://github.com/settings/tokens/new?scopes=>, sin marcar ninguna casilla, y se
revoca al terminar con todos.

### Backend: `8myvc`

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
cd "$d"

readlink -f vendor          # PARA si dice laravel_compartido: tocarías 5 colegios
git pull                    # trae composer.lock
ls -l composer.lock         # sin él, install se comporta como update

composer install --no-dev   # install, NUNCA update
php artisan migrate --force # desde la Fase 3. Sin esto el login da 500
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
php artisan --version       # debe decir 8.83.29
```

`migrate --force` es nuevo y hace falta desde la Fase 3: crea
`personal_access_tokens`, donde viven los tokens de sesión. **Es la primera
migración que este repo ejecuta de verdad** — las tres viejas están archivadas en
`database/migrations/legacy/` y no corren. Sobre la base de un colegio no toca
nada más: crea una tabla que no existía. `--force` porque en producción `migrate`
pide confirmación y no hay quien la teclee.

El orden importa: `composer install` **antes**, porque la migración y el login
nuevo necesitan `laravel/sanctum` en `vendor/`. Un colegio con el `vendor/` de
2021 no lo tiene.

`--no-dev` ahorra **38 MB de los 70** y quita lo que no se usa en producción. El precio
es que en ese colegio ya no se puede ejecutar `php artisan test`, que corre en el CI y
en local. Si el espacio aprieta, `composer clear-cache` al terminar.

`route:cache` es la ganancia que desbloqueó el PR #7: antes abortaba con 401.

**`CORS_ALLOWED_ORIGINS` se deja sin definir a propósito.** El fallback es `*` y hace
falta: la app Flutter no tiene el subdominio del colegio como origen, y en build nativa
no manda `Origin` en absoluto. Como la autenticación va por `Bearer` y no por cookies,
`*` funciona sin problema.

### Front: `myvc_dist` → carpeta `up/`

Seis líneas. `-f` y `clean -fd` resuelven solos lo que antes eran pasos manuales;
el único imprescindible es el `git fetch`.

```bash
cd /home/micolev1/COLEGIO.micolevirtual.com/up

git fetch origin                     # sin esto te quedas en el build viejo (ver abajo)
git checkout -f -B main origin/main  # -f pisa lo sin versionar que colisione
git clean -fd                        # borra restos del build antiguo (fonts/, styles/)
git branch -D master
git remote prune origin

grep -o 'assets/index-[^"]*\.js' index.html   # debe coincidir con los demás colegios
```

**`git fetch` no es opcional.** `checkout -B ... origin/main` usa la copia **local** de
`origin/main`; si no la refrescas, el checkout dice que todo fue bien y te deja en el
build anterior. Le pasó a `coab`, que se quedó en `f38c8ee` sirviendo el bundle viejo.

**`git branch -D`, no `-d`.** Git no puede comparar `master` contra `origin/master`,
que ya no existe en el remoto, así que se niega — aunque su propio aviso dice
`merged to HEAD`, que es la confirmación de que no se pierde nada.

**`git clean -fd` no borra el logo del colegio**, porque está ignorado y `clean` no toca
ficheros ignorados. Comprobado. **No uses `-x`**, que sí se lo llevaría. Para ver qué
borraría antes de hacerlo, `git clean -nd`.


**El logo versionado NO es el del colegio.** `images/Logo_Colegio_Header.<hash>.gif`
del build viejo es el logo de **`bethelexplora`**, y lo recibieron todos los clientes.
Es exactamente el fallo que describe el `.gitignore` de `myvc_dist`. **No lo restaures.**

El logo propio va en `images/Logo_Colegio_Header.gif`, sin hash y sin versionar.
`LoginCtrl.js` lo pide y, si da 404, cae al genérico `Logo_MyVc_Header.gif`: ese 404 es
la señal de "este colegio no tiene logo propio", no un fallo.

El salto borra cientos de ficheros con hash del build antiguo. Es normal: cambió el
toolchain a Vite.

**`plus/` es otro repositorio** —`myvc_front_2`, el Angular de PIAR— y solo lo tienen
seis colegios: `casb`, `coab`, `cads`, `coljordan`, `lal` y `coal`.

### Comprobar por URL, no por carpeta

**El nombre de la carpeta no es el subdominio.** A `coabsaravena.micolevirtual.com/` la
sirve `coab.micolevirtual.com`, y el nombre largo ni siquiera existe en DNS. Lo único
que cuenta es qué bundle recibe el usuario:

```bash
for h in coab casb cads coljordan lal coal; do
  printf '%-12s ' "$h"
  curl -sL --max-time 15 "https://$h.micolevirtual.com/up/" | grep -o 'assets/index-[^"]*\.js' | head -1
done
```

Todos deben devolver el mismo hash. El que devuelva otro está en un build anterior: así
se detectó que `coab` se había quedado en `f38c8ee` pese a decir que el checkout fue bien.

El **aviso de mantenimiento del login** —*«Estamos actualizando la plataforma…»*— sirve
de comprobación visual, pero solo sale al cargar la pantalla de login, dura 6 segundos y
se cierra de un toque. Con sesión abierta no se ve, y eso no significa que falte.

### Después de cada colegio

- Probar de verdad: login de personal y de alumno, boletines, certificado de estudio,
  informes en Excel y subida de foto de perfil.
- Alumno y acudiente reciben **403** en `requisitos`, `prematriculas` y `piars-grupos`.
  Es lo esperado, no un fallo.
- Ya se puede arreglar el typo de `PapeleraCtrl:62` **para ese colegio**, que es la
  condición por pareja de más abajo.

### Qué queda

**Los 16 están hechos (19 ago 2026)**, `vendor/` incluido. Se terminó en el día,
con el procedimiento de arriba.

Con eso caen dos cosas que estaban bloqueadas:

- **PHP ya puede subir de 8.0.** Estaba clavado ahí porque nueve colegios corrían
  un Laravel anterior a los parches de compatibilidad con PHP 8.1, y la versión
  se elige por cuenta de cPanel —o sea, para todos a la vez—. Ya no hay ninguno.
- **La Fase 3 se puede desplegar** en cuanto se fusione: necesita Sanctum en
  `vendor/`, y ahora lo tienen todos.

Lo que **no** queda cerrado por esto: si los cinco de `laravel_compartido` siguen
colgando por symlink. Igualar el `vendor/` los pone al día, pero no los
independiza, y es lo segundo lo que decide si la Fase 4 se puede escalonar
colegio a colegio. Se comprueba con una línea desde el host:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-46s ' "$d"
  [ -L "$d/vendor" ] && printf 'symlink -> %s\n' "$(readlink "$d/vendor")" || printf 'vendor propio\n'
done
```

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

---

## Del PR #8 (Fase 3 — la sesión)

El detalle completo, con el contrato para los clientes, está en
[docs/migracion/07-sesion.md](migracion/07-sesion.md). Aquí solo lo que hay que
hacer o vigilar al desplegar.

### 1. Correr la migración — OBLIGATORIO

Va en el procedimiento de arriba. Sin la tabla `personal_access_tokens`, **el
login devuelve 500** — todos los logins, también el viejo. Es el único paso de
esta fase que no perdona.

Comprobación después de desplegar, desde el propio colegio:

```bash
php artisan migrate:status | grep personal_access_tokens   # debe decir Ran
```

### 2. Qué ve el usuario

Nada, si todo va bien. Pero conviene saber qué es normal y qué no:

- **Nadie tiene que volver a entrar.** Los JWT ya emitidos se siguen aceptando
  hasta que caduquen solos (máximo 24 h después del despliegue). Eso lo controla
  `SESION_ACEPTA_JWT`, que por defecto está en `true`.
- **Cerrar sesión ahora cierra de verdad.** Antes el token seguía valiendo 24 h
  después de pulsar "salir". Si alguien tenía la costumbre de cerrar sesión y
  volver atrás con el botón del navegador, ahora se encuentra la sesión cerrada.
  Es lo correcto, pero es un cambio visible.
- **El token cambia de forma**, de `eyJ0eXAi...` a `17|hGIEXdY6...`. Da igual
  para el navegador; importa solo si algún cliente lo decodificaba.

### 3. El orden entre backend y front da igual

`login/credentials`, `POST /api/login` y `login/logout` siguen funcionando
exactamente igual, así que:

- **backend nuevo + front viejo**: entra por la ruta vieja, sesión de 24 h sin
  refresco, como siempre. Y ya se beneficia del logout real.
- **front nuevo + backend viejo**: el front intenta `/api/auth/login`, recibe
  404 y cae solo a la ruta vieja. La sesión de `myvc_front` lo escribió así a
  propósito.

### 4. `route:clear` no es opcional aquí

Las cinco rutas `auth/*` son nuevas. Con el caché de rutas viejo puesto, no
existen: `POST /api/auth/login` devuelve **404** aunque el código esté
desplegado. Ya pasó en local durante el desarrollo.

### 5. Limpieza de la tabla — opcional

```bash
php artisan sesion:limpiar          # borra los caducados hace más de 7 días
```

No es urgente: al abrir sesión ya se tiran los tokens caducados de ese usuario,
así que la tabla no crece en el caso normal. Lo que recoge es lo de quien no
vuelve a entrar. Si el colegio tiene cron, una vez por semana sobra.

### 6. Lo que queda pendiente para la Fase 4

Quitar `tymon/jwt-auth`. No se puede hasta que **todos** los colegios lleven
desplegada la Fase 3 y haya pasado el tiempo suficiente para que no quede ningún
JWT vivo. El orden es: desplegar en todos → poner `SESION_ACEPTA_JWT=false` en
todos → esperar → `composer remove tymon/jwt-auth`.

Y ese `composer remove` **es exactamente el caso peligroso del `vendor/`
compartido**: sobre `/home/micolev1/laravel_compartido` le quita el paquete a
cinco colegios a la vez, incluidos los que aún no tengan el `app/` nuevo. Otra
razón más para dejar de compartirlo antes de la Fase 4.
