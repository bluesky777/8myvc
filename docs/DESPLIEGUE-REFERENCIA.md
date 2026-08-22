# Despliegue — referencia

> **Esto no es el procedimiento.** El procedimiento, con los comandos y en orden,
> está en [DESPLIEGUE.md](DESPLIEGUE.md) y cabe en una pantalla. Esto es el
> porqué: cómo está montado el servidor, qué se descubrió por el camino y qué
> exigió cada cambio al desplegarse.
>
> Se lee una vez, o cuando algo no cuadra. No hace falta abrirlo para desplegar.

---

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

#### `vendor/` compartido y la Fase 4: una carpeta por generación

> **Revocada el 20 ago 2026.** Joseth decidió quedarse con la topología mixta que
> hay: unos colegios con `vendor/` propio y cinco colgando de
> `laravel_compartido`. No se crean carpetas por generación. La consecuencia que
> hay que tener presente está en [DESPLIEGUE.md](DESPLIEGUE.md): **los cinco que
> comparten se despliegan y se revierten como un bloque**, porque la carpeta es
> una sola y Composer sigue el symlink. Lo de abajo se conserva porque el
> razonamiento sigue siendo válido si algún día se retoma.

**Decisión de Joseth, 19 ago 2026: no se independizan los `vendor/`. Al
contrario** — se borran las carpetas propias y se apuntan por symlink a la
compartida, dejando quizá una independiente para pruebas. El servidor está
saturado y pide espacio.

Aquí estaba escrito lo contrario ("la salida limpia es dejar de compartir
`vendor/` antes de la Fase 4"). El razonamiento era correcto pero la conclusión
era falsa, porque daba por supuesto que solo hay dos opciones: una carpeta para
todos, o una por colegio. Hay una tercera, y es mejor que las dos.

**Una carpeta compartida POR GENERACIÓN DE FRAMEWORK, no una para todo.**

```
/home/micolev1/laravel_8      <- vendor de Laravel 8.83.29   (lo que hoy es laravel_compartido)
/home/micolev1/laravel_9      <- vendor de Laravel 9.52.22
/home/micolev1/laravel_13     <- cuando toque
```

Cada colegio apunta su `vendor` a la que corresponda a su `app/`. Migrar un
colegio pasa a ser **desplegar su `app/` y mover un symlink**; volverse atrás,
mover el symlink al revés. O sea que el salto de framework se escalona colegio a
colegio, con vuelta atrás por colegio, que es exactamente lo que parecía que
compartir impedía.

**Y ocupa menos que hoy, no más.** Medido: `vendor/` completo son 70 MB, y con
`--no-dev` unos 32 MB (se van `fakerphp` 11 MB, `phpunit` 4 MB y compañía).

| | Carpetas reales | Ocupado |
|---|---|---|
| Hoy (12 propias + 1 compartida) | 13 | ~416 MB |
| Una sola compartida | 1 | ~32 MB |
| **Dos, una por generación** | **2** | **~64 MB** |

Frente a los 416 MB de hoy, la tercera opción libera unos 350 MB **y** deja la
Fase 4 escalonable. Es la de una sola compartida la que obliga a un big-bang, no
la de compartir.

##### Las dos trampas de operar así

**1. `composer install` dentro de un colegio escribe en la carpeta compartida.**
Composer sigue el symlink, así que no falla ni avisa: actualiza a todos los que
cuelguen de ella. Con esta topología, `composer install` **nunca** se corre desde
un colegio. Se corre una vez sobre cada carpeta de generación, desde un
directorio que tenga el `composer.json` y el `composer.lock` de esa generación.

Por eso el procedimiento de despliegue de más abajo empieza con `readlink -f
vendor` y para si dice que es compartida.

**2. Al mover el symlink hay que tirar los cachés de ese colegio.**
`bootstrap/cache/packages.php` y `services.php` los genera `package:discover` a
partir del `vendor/`, y son de cada colegio. Si se cambia la carpeta debajo y no
se regeneran, el colegio arranca con la lista de proveedores de la generación
anterior:

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
ln -sfn /home/micolev1/laravel_9 "$d/vendor"
cd "$d" && php artisan package:discover
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
php artisan --version        # debe decir la de la generación nueva
```

##### Lo que falta comprobar en el servidor

- Si hay **algo más** compartido por symlink además de `vendor/` (`storage/`,
  `public/`, `bootstrap/cache/`). `bootstrap/cache/` importa especialmente:
  ahí es donde caen `route:cache` y `config:cache`, y si estuviera compartida un
  colegio serviría las rutas de otro — y con esta topología, además, la lista de
  proveedores de otra generación.
- Qué colegios cuelgan hoy de `laravel_compartido` y cuáles tienen carpeta
  propia, para saber cuántas hay que borrar:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-46s ' "$d"
  [ -L "$d/vendor" ] && printf 'symlink -> %s\n' "$(readlink "$d/vendor")" || printf 'vendor propio\n'
done
```

- ~~**En cuál de los dos alojamientos** está la carpeta real, y qué colegios cuelgan
  de ella.~~ **Contestado el 18 ago 2026**: en el host de `micolev1` la compartida es
  `/home/micolev1/laravel_compartido` y solo cuelgan 5 colegios. Falta hacer lo
  mismo en el segundo host.

**El colegio de pruebas con `vendor/` propio sí tiene sentido**, y no como
excepción: es donde se estrena cada generación antes de que ninguna carpeta
compartida cambie. Conviene que sea uno pequeño y que quede escrito cuál es.

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

---

# Lo que exigió cada cambio

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
| PHP | 8.0.30, `php.ini` en `/opt/alt/php80/etc/php.ini` (**medido en 8.0**; desde el 19 ago 2026 la cuenta va en 8.4 y el `php.ini` es otro fichero: `/opt/alt/php84/etc/php.ini`) |
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

**Al subir a 8.4 esto hay que volver a comprobarlo**, porque el `php.ini` que se
midió es el de la 8.0 y cada versión tiene el suyo. Un `sendmail_path` distinto
en `/opt/alt/php84/etc/php.ini` rompe el reseteo de contraseña sin más síntoma:

```bash
php -i | grep sendmail_path
php artisan correo:probar tu-correo@ejemplo.com
```

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

Y ese `composer remove` **no se corre nunca sobre la carpeta de la generación
que está sirviendo**: le quitaría el paquete de golpe a todos los colegios que
cuelguen de ella, incluidos los que aún tengan el `app/` viejo. Va en la carpeta
de la generación siguiente, que se construye aparte. Ver «`vendor/` compartido y
la Fase 4: una carpeta por generación», más arriba.

---

## De la Fase 4 (Laravel 8 → 13)

### 1. Subir PHP a 8.4 — ANTES, y no se puede escalonar

Laravel 13 exige **PHP 8.3 como mínimo**; el repo va a 8.4, que es la versión con
soporte activo. Producción corría 8.0.30 y **subió a 8.4 el 19 ago 2026, en las
dos cuentas** (`micolevirtual.com` y `lalvirtual.edu.co`). Este paso ya está
hecho; queda el 3.

**La versión de PHP se elige por cuenta de cPanel, no por colegio**, así que sube
para todos los colegios de esa cuenta en el mismo instante. Es el único paso de
toda la migración que no se puede hacer colegio a colegio.

Y por eso el orden es este y no otro:

1. **Primero** los 16 con el `vendor/` igualado. Hecho el 19 ago 2026. Sin esto,
   subir PHP tumba a los que corran un Laravel anterior a los parches de
   compatibilidad de 2021 — que es justo como se descubrió el problema.
2. **Después** subir PHP a 8.4 en las dos cuentas. Hecho el 19 ago 2026. Desde
   ese momento todos los colegios siguen en Laravel 8.83.29, que arranca en 8.4
   pero **no está soportado ahí**. Es una ventana incómoda: funciona, pero con
   avisos de obsolescencia. Está abierta ahora, y solo la cierra el paso 3.

   Y con la versión nueva puesta, dos cosas que **no se heredan** de la 8.0: las
   extensiones marcadas (`sodium`, `opcache`) y el `php.ini` (`/opt/alt/php84/`,
   no `php80`). Ver el paso 0 de `DESPLIEGUE.md`.
3. **Entonces** desplegar colegio a colegio, moviendo cada uno al `vendor/` de la
   generación 13.

### 2. La vuelta atrás es el symlink

Con una carpeta por generación (ver «`vendor/` compartido y la Fase 4», arriba),
deshacer el salto en un colegio es:

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
cd "$d"
git checkout <commit-anterior>          # el app/ de antes
ln -sfn /home/micolev1/laravel_8 vendor # y su vendor
php artisan package:discover
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

Lo que **no** se deshace moviendo el symlink es la versión de PHP. Si hubo que
volver atrás por algo que no era el framework, se vuelve con PHP 8.4 puesto.

### 3. Todo el mundo vuelve a entrar una vez

`tymon/jwt-auth` se quitó en esta fase — solo soportaba hasta `illuminate ^9` y
era el bloqueante duro. Con él se fue la ventana de compatibilidad de los JWT, así
que los tokens que haya vivos en los navegadores dejan de valer en el momento del
despliegue y el usuario aterriza en el login. Pasa una sola vez.

### 4. Laravel 10 y 11 no existen como destino

Si alguien se pregunta por qué se saltaron: composer se niega a instalarlos.
Todas sus versiones arrastran avisos de seguridad sin parchear —entre ellos
CVE-2026-48019, inyección CRLF en la regla de validación `email`— porque salieron
de soporte antes de que llegara la corrección. Solo `>=12.60.0` y `>=13.10.0`
están limpias. **No es una preferencia: es que no se pueden instalar** sin apagar
la comprobación de seguridad de composer en el repo.

### 5. Qué comprobar en el colegio después

Lo de siempre (login de personal y de alumno, boletines, certificado de estudio,
informes en Excel, subida de foto), más:

```bash
php artisan --version                                      # Laravel Framework 13.26.1
php -v                                                     # 8.4.x
php artisan migrate:status | grep personal_access_tokens   # Ran
```


---

# Archivo del operativo largo

Lo que sigue vivía en `DESPLIEGUE.md` hasta el 21 ago 2026, cuando ese documento
se rehízo corto —Joseth: «pocas líneas de lo que tengo que hacer»— y se quedó solo
con la tanda que toca desplegar. **Nada de esto se borró porque nada de esto es
relleno**: son el montaje que se hace una vez, las trampas que costaron un colegio
cada una, y lo que trajo cada tanda ya desplegada.

Se lee cuando algo no sale como dice el corto, o cuando entra un colegio nuevo.

## Se hace una vez, no por colegio

### Token de GitHub

En hosting compartido la IP la comparten muchas cuentas y `composer install` agota
el límite de la API de GitHub a mitad de la descarga.

```bash
composer config -g github-oauth.github.com TU_TOKEN
```

Token **sin ningún scope** (<https://github.com/settings/tokens/new?scopes=>, sin
marcar nada). Se guarda en `~/.composer/auth.json`, vale para todos los colegios,
y se revoca al terminar.

### PHP 8.4 en las dos cuentas de cPanel — HECHO

Las dos cuentas (`micolevirtual.com` y `lalvirtual.edu.co`) ya están en 8.4, y
Joseth lo confirmó el 20 ago 2026. **Lo único que queda de este paso es comprobar
extensiones y OPcache, que NO se heredan de la versión anterior.** Si ya se
comprobaron, sáltate a la carpeta `vendor/`.

Laravel 13 no arranca por debajo de 8.3.

**Este paso afecta a todos los colegios de la cuenta a la vez** — la versión se
elige por cuenta, no por colegio. Es el único de toda la migración que no se puede
escalonar, así que va antes de desplegar el primero.

Ahora mismo los 16 colegios están en Laravel 8.83.29 sobre PHP 8.4: arranca, pero
**Laravel 8 no está soportado ahí**. Funciona con avisos de obsolescencia. Esa
ventana se cierra desplegando, así que conviene que dure horas y no días.

**En alt-php la selección de extensiones es por versión.** Marcar `sodium` y
`opcache` en 8.0 (17 ago 2026, ver [PHP-BASELINE.md](PHP-BASELINE.md)) no las deja
marcadas en 8.4: cada versión arranca con sus propias casillas. Lo primero tras
cambiar es volver a comparar:

```bash
diff <(sort ~/ext-php80.txt) <(php -m | sort)   # las líneas con `<` son lo perdido
php -v                                          # 8.4.x, en las DOS cuentas
```

Y lo que tumba la aplicación entera si se descuadra: `nd_pdo_mysql` marcada y
`pdo_mysql` no — nunca las dos. Está explicado en `PHP-BASELINE.md`.

**Con la versión ya cambiada, comprobar que OPcache está activo.** No es un
detalle de afinado: sin él, cada petición recompila los 609 ficheros del
framework, y eso son 150–200 ms por petición según lo medido en
[el plan de rendimiento](migracion/02-plan-rendimiento.md) — el problema número
uno de ese documento. En el contenedor de desarrollo vino con la imagen 8.4 y se
nota: de 0,25 s a 0,03 s por petición.

En cPanel se activa en *Select PHP Version → Extensions → opcache*. Para
confirmarlo desde el colegio ya desplegado, sin subir ningún fichero:

```bash
php -r 'var_dump(function_exists("opcache_get_status"));'
```

Eso responde por el **CLI**, y lo que sirve las peticiones es **FPM**. Comparten
la configuración de la cuenta, pero si hay duda, la respuesta buena sale de una
petición HTTP real. La forma corta y sin dejar rastro es mirar `phpinfo()` desde
el propio cPanel (*MultiPHP INI Editor*), no subir un `.php` al `public/`.

### Los `vendor/` se quedan como están

**Decisión de Joseth, 20 ago 2026: no se crea ninguna carpeta por generación.** Se
sigue con la topología de hoy, que es mixta: unos colegios tienen `vendor/` propio
y cinco cuelgan por symlink de `/home/micolev1/laravel_compartido`.

Lo primero es saber cuál es cuál, porque el comando de instalar cambia:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-46s ' "$d"
  [ -L "$d/vendor" ] && printf 'COMPARTIDO -> %s\n' "$(readlink "$d/vendor")" || printf 'propio\n'
done
```

**Los que comparten van como un bloque, y no hay forma de escalonarlos.** Composer
sigue el symlink: actualizar la carpeta compartida cambia las dependencias de los
cinco a la vez, en ese instante. Y el `app/` de cada colegio sí es copia propia. O
sea que entre actualizar el `vendor/` compartido y terminar de desplegar el quinto
`app/`, los que falten están corriendo código viejo sobre librerías nuevas. Hay que
hacerlos seguidos, con los cinco `git pull` preparados.

Volver atrás en esos cinco también es todo o nada. Los de `vendor/` propio se
despliegan y se revierten uno a uno, sin ataduras.

## Lo que trajeron las tandas ya desplegadas (19–21 ago 2026)

Se conserva porque explica **qué se notó** en cada una: es lo que se mira cuando
un colegio reporta algo raro y hay que saber desde cuándo es así.

### Una decena de arreglos de autorización, y tres migraciones (21 ago 2026)

Backend y nada más; no se publicó nada en los clientes. Los seis primeros salieron
de la serie de cobertura y están en
[09-pendientes.md §0](migracion/09-pendientes.md), con el detalle en el 05. El más
gordo: **`GET api/alumnos` entregaba el directorio del colegio entero** —nombre,
fecha de nacimiento, celular, dirección, religión y deuda de cada alumno— a
cualquier alumno o acudiente.

**Tres migraciones, las tres aditivas:**

| Migración | Qué hace | Si no se corre |
|---|---|---|
| `..._create_rol_secretario` | crea la fila `Secretario` en `roles`. **No se la da a nadie**: el colegio decide después quién es su secretaria | nada se rompe; los once sitios que preguntan por ese rol siguen contestando `false` |
| `..._add_username_to_password_reminders_table` | añade `username`, nullable | **la recuperación de contraseña cae entera**: el código nuevo inserta en una columna que no existe |
| `..._add_deleted_at_to_frases_preescolar_table` | papelera para las frases del boletín de preescolar | borrar una frase da 500 |

**De aquí sale la regla de que `migrate --force` va pegado al `git pull` y no
«para luego»**: en cuanto el `app/` nuevo está en su sitio, `postRecuperarClave`
escribe en la columna nueva desde la primera petición — y ésa es la única vía que
le queda al **91% de las cuentas** para recuperar su clave.

Y dos cosas que enseñó el despliegue en sí, las dos ya recogidas en
[DESPLIEGUE.md](DESPLIEGUE.md): que **«Already up to date» no significa
desplegado** —los dieciséis lo dijeron minutos después de un `push`, porque cada
colegio apunta a su propio remoto, y sólo el hash lo distingue— y que **`instival`
no recibe nada**: no hay repositorio ni aplicación en esa carpeta, así que se
quedó sin estos arreglos como sin los anteriores.

### La importación de alumnos, reanudable (20 ago 2026)

**Hay una migración nueva**: `2026_08_20_200000_create_importaciones_table`.
Crea la tabla `importaciones` y añade un índice a `alumnos.documento`. El
`migrate --force` del paso 1 la aplica sola. La tabla se crea vacía —no toca
nada de lo que hay— y el índice es un `ALTER TABLE` sobre `alumnos`, que en un
colegio grande son unos miles de filas: **fuera de horario de clase**, como los
tres del rendimiento. `down()` deshace las dos cosas.

**Qué se nota.** Si una importación de alumnos se corta —el corte es el
`max_execution_time` de 300 s de cPanel— volver a subir **el mismo archivo**
continúa por donde iba en vez de empezar de cero. La pantalla no cambia: el
endpoint sigue respondiendo `Importados.`, así que **no hace falta desplegar
nada en los clientes**, ni en `myvc_front` ni en la app de Flutter.

Y cambia una cosa que no se ve pero conviene saber: una fila cuyo alumno ya
existe **con ese documento** ahora se actualiza en vez de crear un alumno
repetido. Antes, cada importación cortada y reintentada dejaba duplicados.

**Lo que hay que recoger, y es el motivo de media tanda.** Después de una
temporada de matrículas, en el colegio que más importa:

```bash
php artisan tinker --execute="print_r(DB::select(
  'SELECT archivo, year, filas, estado, TIMESTAMPDIFF(SECOND, inicio, fin) AS segundos
   FROM importaciones ORDER BY id DESC LIMIT 20'));"
```

Ese `segundos` es el número que nadie tenía. Es lo que decide si
`max_execution_time` puede bajar de 300, y es lo que hay que traer de vuelta.

### Lo de medir el rendimiento (20 ago 2026)

**Hay una migración nueva**, la primera desde `firmantes_acta`:
`2026_08_20_100000_add_indices_medidos_con_explain`. Añade tres índices a
`parentescos`, `frases_asignatura` e `images`. El `migrate --force` del paso 1
la aplica sola; son `ALTER TABLE` en línea, pero en un alojamiento compartido
tardan, así que **fuera de horario de clase**. `down()` los quita, o sea que
volver atrás es inmediato y no toca datos.

Por qué esos tres y no otros trece está en
[02-plan-rendimiento.md](migracion/02-plan-rendimiento.md); el resumen es que
son los que la tabla no tenía de ninguna forma y están en caminos que se
recorren mucho — el guard de cada petición de un acudiente, y una llamada por
asignatura dentro de cada boletín. Medido: **970 ms → 44 ms** en las 360
consultas de una tanda de boletines de un grupo.

**El log cambia de nombre.** `storage/logs/laravel.log` pasa a
`laravel-AAAA-MM-DD.log`, y se conservan catorce días. Escribía siempre en el
mismo fichero sin truncarlo nunca —48 MB solo en el docker de desarrollo—, y el
espacio en disco es el motivo por el que `vendor/` va compartido. **El fichero
viejo no se borra solo**: conviene mirarlo y borrarlo a mano en cada colegio.

```bash
ls -lh storage/logs/laravel.log     # el de siempre, ya sin escribir
rm storage/logs/laravel.log         # cuando ya no interese lo que tenga dentro
```

**Y hay un registro de consultas lentas que se puede encender**, para saber por
fin qué endpoint cuesta. Va apagado; se enciende en el `.env` del colegio:

```
CONSULTAS_LENTAS_MS=500      # 0 = apagado, que es como llega
```

Escribe en `storage/logs/consultas-lentas-AAAA-MM-DD.log`, una consulta por
línea y **sin los valores** (por ahí pasan datos de menores). Se deja una
temporada, se baja el fichero y se lee con `tools/consultas-lentas.py`. Eso es
lo que falta para decidir el resto de los índices.

### Lo de la revisión de IDOR

**No hay migraciones nuevas.** Siguen siendo las dos de siempre
(`personal_access_tokens` y `firmantes_acta`), así que el `migrate --force` del
paso 1 es el mismo de antes.

Lo que sí cambia, y se nota desde el minuto uno:

**Alumnos y acudientes pierden acceso a casi todo lo que no es suyo.** Es el
cambio grande de esta tanda y viene de la revisión de IDOR
([08-revision-idor.md](migracion/08-revision-idor.md)): 141 rutas no comprobaban
de quién era el dato que servían. La regla que se aplicó es **un alumno solo ve lo
suyo; un acudiente, lo suyo y lo completo de sus acudidos**. En números, 141 rutas
sin guard pasan a 12, y las 12 son catálogos que no exponen a nadie.

Antes de esto, cualquier alumno con su token podía cambiarle el nombre de usuario
al rector, leer antecedentes médicos ajenos, sacar el listado de sus compañeros con
documento y dirección, abrirle un proceso disciplinario a otro y borrar un año
lectivo entero. Ya no.

**Lo que hay que mirar en el navegador después de desplegar el primer colegio**, y
con calma, porque es donde el riesgo cambió de sitio: entrar **como alumno y como
acudiente** y dar una vuelta por sus pantallas. Los tests cubren que siguen viendo
lo suyo —perfil, fotos, notas, boletín, matrículas, acudientes, ficha de
enfermería—, pero salen del backend: si `myvc_front` llama a alguna ruta desde una
pantalla de familia que no esté en esa lista, saldrá un 403 donde antes había
datos. Es lo único de esta tanda que no se puede comprobar sin el front delante.

Y tres arreglos que también se ven:

- **El certificado de notas acumuladas del año deja de salir en ceros.** Llevaba
  así desde siempre: `GET boletines/detailed-notas-year/{grupo}` sin el segmento de
  la URL devolvía 200 con todo a 0. Ahora calcula.
- **El listado del grupo deja de imprimir «0» en la dirección.** La consulta usaba
  `+` en vez de `CONCAT`, y en MySQL eso es una suma.
- **`PUT prematriculas/llevo-formulario` ya no existe**: escribía en una tabla que
  nunca se creó, o sea que era un 500 seguro. Si el front la llama, ahora recibirá
  404 en vez de 500. Quién llevó el formulario se guarda —y siempre se guardó— como
  `matriculas.estado = 'FORM'`, que es lo que mueve la pantalla de prematrículas.

## El cron de este colegio — una sola línea, y una sola vez

En cPanel: **Advanced → Cron Jobs → Add New Cron Job**, cada minuto
(`* * * * *`):

```
* * * * * /usr/local/bin/php /home/micolev1/COLEGIO.micolevirtual.com/artisan schedule:run >/dev/null 2>&1
```

**Un solo cron por colegio, y ya no se vuelve a tocar el panel.** Lo que corre y
cada cuánto se decide en `app/Console/Kernel.php`, que viaja con el `app/`. Hoy
solo hay `sesion:limpiar`, semanal.

Dos trampas de esta pantalla, y las dos se pagan caro:

1. **`>/dev/null 2>&1` no es opcional.** cPanel manda un correo con la salida
   **en cada ejecución**, y esto corre cada minuto: son 1.440 correos al día por
   colegio, y con dieciséis, 23.000. La propia página lo avisa en letra pequeña.
2. **`/usr/local/bin/php` es el PHP por defecto de la cuenta, no necesariamente
   el 8.4.** Laravel 13 no arranca con menos. Compruébalo antes de guardar el
   cron:

   ```bash
   /usr/local/bin/php -v          # tiene que decir 8.4.x
   ```

   **Comprobado el 20 ago 2026 en la cuenta `micolev1`: PHP 8.4.24.** La línea
   de arriba sirve tal cual ahí. En la otra cuenta hay que volver a mirarlo: la
   versión se elige por cuenta de cPanel, no por colegio.

   Si dice otra cosa, usa la ruta con versión —en cPanel EA4 suele ser
   `/opt/cpanel/ea-php84/root/usr/bin/php`— o cambia la versión de la cuenta.
   Un cron con el PHP viejo no avisa: falla en silencio, porque acabas de
   mandar su salida a `/dev/null`.

Para comprobar que quedó bien, sin esperar a la semana:

```bash
php artisan schedule:list       # qué hay programado y cuándo toca
php artisan sesion:limpiar      # correrlo a mano una vez
```

## Front (`up/`) — solo las tandas que publican front

```bash
cd /home/micolev1/COLEGIO.micolevirtual.com/up

git fetch origin                     # sin esto te quedas en el build viejo
git checkout -f -B main origin/main
git clean -fd                        # NO uses -x: se llevaría el logo del colegio
git branch -D master
git remote prune origin

grep -o 'assets/index-[^"]*\.js' index.html
```

## Comprobar, la lista larga

```bash
php artisan migrate:status | grep -E 'personal_access_tokens|firmantes_acta'   # Ran, Ran

for h in coab casb cads coljordan lal coal; do
  printf '%-12s ' "$h"
  curl -sL --max-time 15 "https://$h.micolevirtual.com/up/" \
    | grep -o 'assets/index-[^"]*\.js' | head -1
done
```

Todos los colegios ya desplegados deben devolver el mismo hash. **Comprueba por
URL, no por carpeta**: a `coabsaravena.micolevirtual.com/` la sirve
`coab.micolevirtual.com`, y el nombre largo ni existe en DNS.

Y a mano, en el navegador: login de personal y de alumno, boletines, certificado
de estudio, informes en Excel y subida de foto de perfil.

**Lo que parece un fallo y no lo es:**

- Alumno y acudiente reciben **403 en casi todo lo que no es suyo**, no solo en
  `requisitos`, `prematriculas` y `piars-grupos` como hasta el PR #7. Desde el
  PR #11 son más de trescientas rutas: el listado del grupo, el observador, la
  rejilla de notas del profesor, la configuración del colegio y cualquier ruta que
  nombre a otra persona. El mensaje es «No tienes permiso» o «Solo puedes consultar
  lo tuyo», y **cada rechazo queda anotado en `bitacoras`**, que es donde mirar si
  alguien reclama. Lo que NO debe dar 403 es un alumno pidiendo lo suyo o un
  acudiente pidiendo lo de su acudido; si pasa, es un fallo y hay que reportarlo.
- Todos los usuarios activos aterrizan en el login **una vez**. Los tokens JWT
  dejaron de valer al quitarse el paquete. Pasa solo el día del despliegue.
- El logo del colegio da 404 si ese colegio no tiene uno propio: cae al genérico.
- **El acta de evaluación puede salir con todo en «sin clasificar».** No es un
  fallo del cálculo: significa que los `periodos` de ese año tienen
  `fecha_inicio` y `fecha_fin` en NULL. Sin calendario no se puede separar a
  quien empezó el año de quien entró después, y el acta prefiere decirlo antes
  que afirmar algo que los datos no dicen. La pantalla pide llenar las fechas.
  Comprobado así en la base de desarrollo, con sus cuatro periodos vacíos; en
  producción puede pasar en cualquier colegio que nunca las haya puesto:

  ```sql
  SELECT numero, fecha_inicio, fecha_fin FROM periodos
   WHERE year_id = (SELECT id FROM years WHERE deleted_at IS NULL ORDER BY year DESC LIMIT 1);
  ```

## Las siete trampas que cuestan un colegio

Las tres que muerden en un despliegue normal están también en el corto; estas son
las siete, y cada una salió de un incidente.

1. **`composer` dentro de un colegio con `vendor/` compartido** le cambia las
   dependencias a los otros cuatro. Sigue el symlink sin avisar y sin fallar.
   Comprueba siempre antes: `[ -L vendor ]`.
2. **`git fetch` antes del checkout del front.** `checkout -B ... origin/main` usa
   la copia local del ref: sin refrescarla dice que todo fue bien y te deja en el
   build anterior. Le pasó a `coab`.
3. **`git clean -x` en `up/`** borra el logo del colegio, que está ignorado. Sin
   `-x` no lo toca. Para ver qué borraría: `git clean -nd`.
4. **No restaurar `images/Logo_Colegio_Header.<hash>.gif`** del build viejo: ese es
   el logo de `bethelexplora` y lo recibieron todos. El propio va sin hash y sin
   versionar.
5. **Un arreglo fusionado no está desplegado.** `app/`, `routes/`, `config/` y
   `.env` son copia real en cada colegio; llegan uno a uno.
6. **`config:cache` antes de tocar el `.env`** deja al colegio sirviendo la
   configuración anterior, sin ningún síntoma. Si editas el `.env` después,
   vuelve a correr `php artisan config:clear && php artisan config:cache`.
7. **Encadenar `artisan` con `&&` sin repetir `php artisan`.** El segundo comando
   no existe como binario suelto, la cadena se corta ahí y la caché vieja sigue
   viva. Pasó en `coal`: `config:clear` corrió, `route:clear` no, y el login
   devolvió 404 con el código bien desplegado. Si un `artisan` de la cadena no
   imprime su `INFO`, no se ejecutó.

## Estado al cerrar la tanda del 20 ago 2026

| | |
|---|---|
| Colegios | **los 16 desplegados y con el `vendor/` igualado** (19 ago 2026) |
| Framework en producción | Laravel 8.83.29 · **PHP 8.4** (subido en las dos cuentas el 19 ago 2026) |
| Framework en la rama | Laravel 13.26.1 · PHP 8.4 |
| Topología | mixta y se queda así (Joseth, 20 ago 2026): unos con `vendor/` propio, cinco colgando de `laravel_compartido`. **Esos cinco se despliegan y se revierten como bloque** |
| Sin confirmar | si `sodium` y `opcache` siguen activas en 8.4 — se marcaron en 8.0 y **la selección de extensiones es por versión** |
| Sin confirmar | si alguna pantalla de familia de `myvc_front` llama a una ruta que el guard nuevo cierra. Solo se ve con el front delante |
| Esta tanda | **cerrada: los 16 colegios desplegados** (20 ago 2026). `coal` fue el primero, y es de donde salieron las trampas 6 y 7. Migraciones en `Ran` y login de personal comprobado en el navegador tras `route:clear` |
| Sin confirmar | si `coal` cuelga del `vendor/` compartido. El trace de producción apunta a `/home/micolev1/laravel_compartido/`, y ahí el `composer install --no-dev` se corrió **dentro** del colegio, que es la trampa 1. La segunda pasada dijo «Nothing to install», así que el `lock` ya coincidía y probablemente no cambió nada — pero hay que comprobarlo con `[ -L vendor ]` |

**Laravel 8.83.29 corriendo sobre PHP 8.4 es la ventana incómoda descrita en el
paso 0: arranca, pero no está soportado ahí.** Cuanto antes empiece el despliegue
colegio a colegio, menos dura.

```bash
# Qué generación usa cada colegio hoy
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-46s ' "$d"
  [ -L "$d/vendor" ] && printf 'symlink -> %s\n' "$(readlink "$d/vendor")" || printf 'vendor propio\n'
done
```

**`plus/` es otro repositorio** (`myvc_front_2`, el Angular del PIAR) y solo lo
tienen seis colegios: `casb`, `coab`, `cads`, `coljordan`, `lal` y `coal`.
