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

> **Y de los dos, uno está planeado para desaparecer.** `lal` es el único colegio
> que vive en la segunda cuenta —bajo su propio dominio, `lalvirtual.edu.co`— y por
> eso es el único que **queda fuera de todos los bucles**: el de despliegue, el del
> paso 0, el de los hashes del front y el del cron, que hoy dicen «repetir a mano en
> la otra cuenta». El plan para traerlo **sin que le cambie la URL**, con lo que hay
> que medir antes y lo que hay que corregir aquí después, está en
> [TRASLADO-LAL.md](TRASLADO-LAL.md). **Escrito, no hecho.**

### La carpeta NO se llama como el host — la tabla, medida el 30 ago 2026

Esto ha costado dos mediciones falsas: el barrido de hashes del 29 dio a `lal` por
caído preguntando por un subdominio que no existe, y el de storage del 30 dejó siete
colegios en blanco por el mismo motivo. **Los bucles van por carpeta; las
comprobaciones, por host, y no coinciden.** Sacado de `uapi DomainInfo list_domains`.

| Carpeta en `/home/micolev1/` | Se sirve como |
|---|---|
| `cads-itagui` · `casb-medellin` · `caz-zaragoza` · `coabsaravena` · `comad-san-andres` · `maranathaarauca` | `cads` · `casb` · `caz` · `coab` · `comad` · `maranatha` |
| **`fortul`** | **`coaf`** |
| `bethelexplora` | `bethelexplora` **y** `bethel` — dos hosts, el mismo colegio (mismo bundle) |
| `amiguitosdejesus` · `coal` · `colbosque` · `coljordan` · `eal` · `inseaq` · `semillitasdedios` · `demo` | igual que la carpeta |
| *(`lal`, en la otra cuenta)* | `lalvirtual.edu.co` |

Y en la misma cuenta, **sin ser colegios**: `app` (la **build web de `myvc_flutter`**,
`flutter_bootstrap.js`), `ws`, `edilson`, `calculadora` (403) y `lal` (el subdominio de
pruebas del traslado).

**Dos restos que no servían nada:** `hermosa.micolevirtual.com/` (13 M), una carpeta sin
subdominio —no resolvía, no estaba en `list_domains`—, **borrada el 30 ago 2026**; y
`lalvirtual.edu.co/` (32 K, enero 2021), un docroot huérfano: ese dominio **no** está
dado de alta en esta cuenta (`addon_domains: []`).

> **Y la trampa que casi muerde: la base `micolev1_la_hermosa` NO se fue con la carpeta,
> porque es la de `eal`.** El nombre coincidía, la carpeta sobraba y la base no. Ver el
> apartado siguiente.

### Y la base tampoco se llama como la carpeta — **NINGUNA BASE SE PUEDE BORRAR**

Tercer nombre distinto para la misma cosa, y el que más caro sale equivocar. Las bases
llevan **la ciudad**, no el colegio. Confirmado por Joseth el 30 ago 2026; el mapa
definitivo lo imprime:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-38s %s\n' "$(basename $(dirname $d))" "$(grep -m1 '^DB_DATABASE=' $d/.env 2>/dev/null)"
done
```

**Las dos que no se adivinan por el nombre, y las dos parecían bases huérfanas:**

| Carpeta | Base | |
|---|---|---|
| **`eal`** | **`micolev1_la_hermosa`** | había además una carpeta `hermosa.micolevirtual.com/` sin subdominio —**el nombre viejo del mismo colegio**—, borrada el 30 ago. **La base se queda: la usa `eal`** |
| **`inseaq`** | **`micolev1_quibdo_db`** | 424 MB |

Las demás se dejan leer (`coal` → `coal_bucara`, `casb-medellin` → `simonbolivar_medellin`,
`bethelexplora` → `bethel_arauquita`, `fortul` → `fortul_adventista`, `maranathaarauca` →
`arauca_maranatha`…), pero **se leen, no se adivinan**: el bucle de arriba es la fuente.

> **De las 18 bases de la cuenta, NINGUNA sobra:** quince colegios + `demo` + `edilson`
> + la nueva de `lal`. **No hay ninguna base que borrar**, y las dos que más lo parecían
> —`la_hermosa` y `quibdo_db`— son las de dos colegios vivos. Lo que sí se puede archivar
> y borrar es **la carpeta** `hermosa.micolevirtual.com/`, que no sirve nada.

### Qué está copiado y qué está compartido por symlink

**Aquí no basta con "cada colegio tiene lo suyo": hay una parte compartida y una
copiada, y se comportan al revés.** Confirmado por Joseth el 18 ago 2026.

| | Cómo está | Un cambio llega a… |
|---|---|---|
| `app/`, `routes/`, `config/`, `.env` | **Copia real en cada colegio** | …solo al colegio donde se despliega |
| `vendor/` | **Depende del colegio.** 5 apuntan por symlink a `/home/micolev1/laravel_compartido`; los otros 10 tienen carpeta propia | …a los 5 del symlink de golpe. A los demás, solo si se les toca uno por uno |

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

> **Desde el 30 ago 2026 son SEIS: entra `lal`.** Al montarlo en la cuenta de
> `micolev1` (traslado, [TRASLADO-LAL.md](TRASLADO-LAL.md)) Joseth eligió el symlink,
> así que `lal` **se despliega y se revierte con el bloque** y **nunca se corre
> `composer` dentro de su carpeta**: seguiría el symlink y cambiaría a los otros cinco.
| Carpeta propia, al día | 2 | `amiguitosdejesus`, `semillitasdedios` |
| **Carpeta propia, congelada en 2021** | **8** | `bethelexplora`, `cads-itagui`, `casb-medellin`, `caz-zaragoza`, `coabsaravena`, `coljordan`, `fortul`, `inseaq` |

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
MAIL_FROM_ADDRESS=admin@micolevirtual.com
MAIL_FROM_NAME="MiColegioVirtual"
```

> **El remitente cambió el 2 sep 2026, y la premisa de este apartado cambió con él.**
> Aquí ponía `josethmaster@lalvirtual.com`, que es el que llevaba incrustado el `mail()`
> viejo (la línea de abajo) — y **`lalvirtual.com` no está registrado**: `dig` da
> **NXDOMAIN**. `admin@micolevirtual.com` sí existe como dominio, tiene MX propio y su
> SPF autoriza la IP del servidor por dos caminos (`+a` e `ip4:70.32.23.72`), verificado
> con `dig` ese mismo día.
>
> **Y lo que se cayó no es sólo la dirección, es el «sin auditar» de la línea de arriba.**
> El `.env` de producción de `cads-itagui` —**el primero que se lee entero**— trae
> `MAIL_MAILER=smtp` con `MAIL_HOST=mailhog` y `MAIL_FROM_ADDRESS=null`, o sea el
> andamiaje de desarrollo sin tocar: **ese colegio no ha enviado un correo nunca**. Así
> que la frase de más abajo *«el correo sale igual que siempre»* **no se sostiene en al
> menos uno de los dieciséis**: allí no salía. La decisión de `sendmail` sigue siendo la
> buena —está verificada en el servidor, más abajo—, pero **su punto de partida no era
> uniforme**, y en cuántos colegios se llegó a aplicar este apartado **no lo sabe nadie**:
> sigue `PENDIENTE`. El censo de todo lo que se concluyó «para los quince» desde un solo
> `.env` está en
> [`docs/migracion/29-los-env-no-son-uniformes.md`](migracion/29-los-env-no-son-uniformes.md).
>
> **Comprobar antes de dar por hecho nada**: `php artisan correo:probar <tu-correo>` en el
> colegio. Detecta los tres fallos de `cads-itagui` —`null` incluido, porque Laravel lee
> la cadena `null` como null de verdad y `config/mail.php` no aplica su valor por
> defecto—. La herramienta funcionaba; **nadie la había corrido allí**.

Las dos de `FROM` **no son opcionales**. El código viejo llevaba el remitente
incrustado a mano en las cabeceras del `mail()`:

```php
$headers .= "From: MiColegioVirtual <josethmaster@lalvirtual.com>\r\n";
```

> **Esta línea se deja tal cual a propósito**: no es una instrucción, es **de dónde salió
> el dominio muerto**. El `mail()` viejo fallaba en silencio —devolvía `false` y el
> controlador respondía «Enviado» igual—, así que un remitente de un dominio inexistente
> podía vivir meses sin síntoma. Reescribirla borraría el origen del fallo.

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
solo garantiza que Exim aceptó el mensaje, no que llegara.

> **Y esto decía «ahí se vería un problema de SPF con `lalvirtual.com`», que estaba
> mal planteado. Medido el 30 ago 2026: `lalvirtual.com` NO EXISTE.** `dig` da
> **NXDOMAIN** y `whois` contesta *No match for domain "LALVIRTUAL.COM"* — no está mal
> configurado, **no está registrado por nadie**. Y es el dominio del
> `MAIL_FROM_ADDRESS` que este mismo apartado manda poner **en los quince colegios**,
> y el que iba incrustado en las cabeceras del `mail()` viejo.
>
> No hay «problema de SPF»: **un dominio que no existe no puede publicar SPF**, y para
> el receptor eso no es un SPF que falla, es *sender domain does not exist* — un motivo
> de rechazo bastante más duro. Nadie lo vio porque el síntoma es correo que no llega y
> el reseteo se usa **menos de una vez al día**. Los tres caminos —remitente de un
> dominio que sí exista, registrar `lalvirtual.com`, o dejarlo sabiéndolo— y qué medir
> antes de elegir, en [TRASLADO-LAL §9.1](TRASLADO-LAL.md). **Toca el `.env` de los
> quince: es una tanda, no un `sed`.**

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

### 2. `CORS_ALLOWED_ORIGINS` — **CONTESTADO el 3 sep 2026: se queda en `*`**

Sin esta variable el fallback es `*`, que se puso a propósito para que desplegar el PR no
tumbase nada. **Censado ese día: estaba definida en 1 de 17**, o sea que dieciséis llevaban
`*` desde el principio.

**Joseth decidió que `*` es el valor bueno y no un descuido**: cada colegio recibe conexiones
de **aplicaciones externas**, así que una lista blanca de orígenes no describe a los clientes
reales de esta API. Deja de ser un pendiente.

> **Y `*` aquí no regala nada, que es lo que había que comprobar antes de darlo por bueno:**
> `supports_credentials` es **`false`** (`config/cors.php:71`) y el token viaja en
> `Authorization: Bearer` (`Sesion.php:412`), no en cookie. El navegador no manda credenciales
> a otro origen y **el JavaScript tiene que adjuntar el token a mano**, así que una página
> cualquiera puede llamar a la API pero sigue necesitando un token que vive en el
> almacenamiento del cliente legítimo. *Abre la puerta; no reparte llaves.*
>
> **Lo que obligaría a volver aquí**: poner `supports_credentials => true` —el navegador
> rechaza esa combinación con `*`— o mover la sesión a cookie. Las dos son un cambio de una
> línea en `config/`.

Si algún día hiciera falta acotarlo, varios orígenes van separados por comas y está
documentado en `.env.example`. El porqué entero, en
[`29-los-env-no-son-uniformes.md`](migracion/29-los-env-no-son-uniformes.md) §5.

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

## Lo que trajo la tanda del 31 ago – 20 sep 2026 — desplegada el 20 sep en `e7ed5e75`

**Desplegada el 20 sep 2026 a las 23:51 -0400** en los dieciséis de `micolev1` y a las **23:59** en
`micolevi`, con `git pull` + `migrate --force`. El front de la misma vuelta se construyó al día
siguiente: `myvc_dist` `1fea7e2`, sobre `myvc_front f75fd5c2`.

> ### ⛔ Y ESTA ES LA TANDA QUE BORRÓ NOTAS
>
> `2026_09_19_500000_la_casilla_vacia` vació **407.909** casillas de `notas` en **catorce**
> colegios, y **225.247** de ellas no valían cero. El censo base por base, el arreglo y las
> decisiones que salieron de ahí están en
> [doc 43 §incidente](migracion/43-lo-que-todavia-no-se-ha-calificado.md).
>
> **Las dos cosas que faltaban el 20 sep, y que ya no faltan:** nadie vio el número **antes** —hoy
> lo dice `tools/riesgo-de-la-tanda.php` base por base, que es el Paso 0 de
> [DESPLIEGUE.md](DESPLIEGUE.md)— y **no existía ninguna copia de seguridad previa** — hoy está
> [RESPALDOS.md](RESPALDOS.md).

**Lo desplegado, recontado el 22 sep contra el hash que salió de verdad** y no contra el que este
documento suponía:

| | | recalcular con |
|---|---|---|
| commits | **785** | `git rev-list --count 9474b50..e7ed5e75` |
| **migraciones** | **34 ficheros** | `git diff --name-only 9474b50 e7ed5e75 -- database/migrations/` |
| `app/` | **121** ficheros | `git diff --name-only 9474b50 e7ed5e75 -- app/ \| wc -l` |
| Rutas | **543 → 647**, 104 nuevas | `tests/Contrato/Snapshots/rutas.json` |
| `config/` · `composer.json` | **siete ficheros**: `composer.json`, `aplicacion-movil`, `cors`, `excel`, `horario`, `importacion`, `licencia` | `git diff --name-only 9474b50 e7ed5e75 -- config/ composer.json` |

> **El documento de abajo decía 321 commits, 7 migraciones y 57 ficheros de `app/`.** Esa medición
> era del **5 sep** y la tanda siguió creciendo quince días — es literalmente el caso que la regla
> *se remide, no se suma* describe, y aquí salió por un factor de dos en todas las filas. Lo que
> falló no fue medir: fue no volver a medir el día de salir.

**Lo que sigue es el documento de despliegue tal y como estaba esa noche**, movido aquí entero el
22 sep: el bloque del congelado de `myvc_flutter`, las siete migraciones ordenadas por lo que
tumban, la comprobación de esquema y los avisos para el front. Nada de ello describe ya lo que hay
que desplegar; describe lo que se desplegó.

### 🛑 CONGELADO: `myvc_flutter` está en revisión — no se despliega hasta que salga

> **Joseth, 4 sep 2026: la espera tiene fecha — el 10 de septiembre.** *«No quiero desplegar
> nada hasta no estar seguro de que no me dañe la verificación de la Play Store de
> Flutter.»* La fecha cuadra con la aritmética de abajo: seis días de revisión el 2 sep sobre
> catorce sale ~10 sep. **Pero el criterio es el suceso, no el calendario**: lo que
> desbloquea es *«la app salió de revisión»*, así que si la tienda tarda más, la fecha se
> mueve con ella y no al revés.
>
> **Y lo que la espera compra no es que la tanda se vuelva inocua: es que vuelva a haber
> salida.** Mientras la app está en revisión no se puede publicar una corrección; después,
> sí. La espera no cambia el riesgo, cambia lo que cuesta equivocarse.
>
> ### Y la pregunta que este bloque plantea YA ESTÁ CONTESTADA, medida el 4 sep 2026
>
> Las dos, y las dos dan **no**:
>
> | la pregunta | medido en `~/DESARROLLOS/myvc_flutter` |
> |---|---|
> | ¿llama a `POST tardanzas/login/traer-datos`? | **no** — cero. *(Un `grep` ingenuo da 2 y son `traerDatosDeDisciplina`, que llama a `/grupos/con-disciplina`: casó por subcadena.)* |
> | ¿lee alguna de las ocho claves del evento? | **no lee el evento siquiera.** De `to-me` saca `horario_hoy`, `horario_version_id`, `publicaciones`, `alumnos` y `ausencias_periodo` (`MuroApi.dart:109`). **La clave `eventos` no la toca.** |
>
> **Y no depende de qué commit esté en la tienda, que es lo que lo cierra**: `git log -S` sobre
> los **151 commits** del repositorio dice que **ninguna versión de esta app ha leído nunca
> `'eventos'` ni ha llamado nunca a `traer-datos`**. Así que la respuesta no cambia con la
> versión concreta que se subió a revisar.
>
> *Las tres claves que sí aparecen —`created_at`, `created_by`, `deleted_at`— están en otros
> modelos: `SituacionModel`, `AsistenciaModel`, `PublicacionModel`, `MuroApi` (el muro) y
> `HistorialNotaApi`. **Ninguna es el evento del calendario**, así que no las toca el aviso
> K. Se dicen porque un recuento que sólo mire nombres de clave las contaría.*
>
> **Y la lista de «lo único que un cliente puede perder» se comprobó completa**: la otra
> candidata era `notas_finales.nota` pasando a `DECIMAL` —un cambio de **tipo**, que es el
> disparador que una lista escrita en términos de forma no ve—, y **no entra en esta tanda**:
> `2026_08_30_200000_notas_finales_en_decimal` **ya está en `9474b50`**, la base desplegada.
> Las que faltan son **ocho** y ninguna cambia un tipo que un cliente lea.
>
> **Lo que esto NO dice, y es lo que sigue justificando esperar:** contesta por la
> *compatibilidad de la app*, no por la *ejecución del despliegue*. El ⛔ sigue entero — con
> el código nuevo y la base sin migrar **no se puede ni iniciar sesión** —, y eso no lo
> arregla ninguna medición del cliente: lo arregla hacer el despliegue bien, colegio a
> colegio, `git pull` y `migrate --force` seguidos.

**Joseth, 2 sep 2026: la app lleva seis días en revisión y no puede publicar una corrección
hasta que pasen los catorce.** O sea que si esta tanda rompiera algo de la app, **el arreglo
tardaría más de una semana en llegar a la tienda** y los dieciséis colegios lo comerían entero.
Por eso esto va **antes** del bloque de la tanda y no dentro: la tanda está lista, y aun así
**no se despliega**.

**Qué se midió antes de escribir esto, para que la espera sea una decisión y no un miedo.** De
los **321** commits (remedidos el 5 sep 2026 por la noche; eran 191 cuando se escribió esto), lo único que un
cliente puede **perder** siguen siendo dos cosas — todo lo demás es aditivo, comprobado sobre los
snapshots del rango, no sobre los mensajes de los commits:

| Lo que desaparece | Dónde |
|---|---|
| `POST tardanzas/login/traer-datos` → **404** | la única ruta retirada de las **35** que se mueven |
| **ocho claves** de cada evento del calendario | `GET ChangesAsked/to-me`, aviso **K** |

**Así que la pregunta que decide el despliegue es sólo ésta: ¿la versión que está en revisión
llama a esa ruta o lee alguna de esas ocho claves?** Si la respuesta es no a las dos, la tanda
es inocua para la app y lo que queda por decidir es otra cosa. Si es sí a cualquiera de las
dos, **se espera** — no hay término medio, porque la salida sería una versión nueva en la
tienda y eso es justo lo que no hay.

> **Y una que NO es de esta tanda pero muerde ahora mismo: no escribas un número en
> `APP_MOVIL_VERSION_MINIMA` de ningún colegio mientras la app esté en revisión.** El
> mecanismo de `version_minima_app` **ya está desplegado desde el 31 ago** y está **inerte
> porque el `.env` viene vacío** — mandar el campo bloquea a quien tenga una versión menor, y
> hoy **no hay ninguna versión en la tienda a la que actualizar**. Escribir ahí un número
> durante la ventana deja al colegio entero fuera **y sin salida**, y la salida sería
> exactamente lo que no se puede hacer: publicar. El porqué entero está en
> `config/aplicacion-movil.php`, que lo lleva escrito encima de la línea.

#### CONTESTADO el 2 sep 2026 por `myvc-flutter-14`: la tanda es INOCUA para la app

Verificado **contra el commit exacto que está en revisión** —`083bedf`, `1.0.0+3`, el último que
tocó `lib/` antes del envío del 25 ago—, no contra el `main` de hoy, que es otro árbol:

| | |
|---|---|
| `POST tardanzas/login/traer-datos` | **no la llama**: cero apariciones en `lib/` |
| las ocho claves del calendario | **seguro**: `MuroApi.traerMuro` lee tres claves de `to-me` —`horario_hoy`, `publicaciones`, `alumnos`— y **`eventos` no se lee en ningún rol** |
| campos nuevos | **inocuos**: ni `disallowUnrecognizedKeys`, ni `checkedCreate`, ni iteración de claves; todos los `fromJson` leen por nombre |
| el 403 de `cambiar-contador-*` | **no las llama**: cero apariciones |

**Así que las dos preguntas que congelaban esto están contestadas que no, y por el lado de la app
la tanda se puede desplegar.**

#### Y LA QUINTA, QUE NO ES DE ESTA TANDA Y HAY QUE MIRARLA IGUAL — HOY

El bloqueo por `version_minima_app` **sí está activo en la versión en revisión**
(`lib/Utils/VersionMinima.dart`, entró el 24 ago, un día antes del envío). Con `buildNumber = 3`:
**campo ausente, 0 o negativo no bloquea; un valor ≥ 4 manda TODAS las rutas a la pantalla de
actualizar**, y no hay nada a lo que actualizar porque el build 3 es lo único que existe.

**Pero esto NO lo trae la tanda, y por eso esperar no protege de ello.** Remedido el 5 sep 2026
sobre `9474b50..main`: el rango **no toca** `VersionMinimaDeLaApp.php` ni
`config/aplicacion-movil.php`. El mecanismo se escribió el 24 ago (`1e98e28`) y **está desplegado
en los dieciséis desde el 31 ago**. O sea que **el riesgo es idéntico se despliegue o no**, y si
algún colegio tuviera un número ≥ 4 la app **ya estaría bloqueada allí ahora mismo**.

> **Aquí decía además «ni `.env.example`», y eso dejó de ser cierto: el rango SÍ lo toca**
> (`cf06f72` y `41d2a22`). Lo que cambia es `MAIL_FROM_ADDRESS` —de `josethmaster@lalvirtual.com`
> a `admin@micolevirtual.com`—, **no** `APP_MOVIL_VERSION_MINIMA`, así que la conclusión de arriba
> aguanta entera. Pero **la frase que la sostenía era falsa, y una conclusión correcta apoyada en
> una premisa falsa es la que nadie vuelve a comprobar.** Remedido fichero a fichero, no de
> memoria.

> **Y el correo de los colegios NO se comprueba desde aquí ni con un bucle escrito a mano: hay
> herramienta.** `tools/correo-de-los-colegios.sh` lee el `.env` de cada instalación y dice cuál
> no puede mandar correo, con `--arreglo` para imprimir el bloque que hay que pegar. Es **de sólo
> lectura**: tocar un `.env` lo decide y lo ejecuta Joseth, que es la regla del
> [29](migracion/29-los-env-no-son-uniformes.md).
>
> **No es un paso del despliegue y por eso vive aquí como nota y no como casilla**: el `.env` no
> viaja en el despliegue, así que desplegar ni rompe el correo ni lo arregla. Se corre el día que
> se quiera saber en qué estado están, y **después de tocar un `.env`**.
>
> Tres cosas que conviene saber antes de leer su salida, y las tres están medidas en su cabecera:
> los diecisiete se arreglaron el **3 sep 2026** —así que «el correo está roto» dejó de ser cierto
> ese día—; **la configuración cacheada manda sobre el fichero**, y por eso ese caso sale
> `NO MEDIDO` y nunca `OK`; y **la instalación viva de `lal` es la decimoctava y queda fuera del
> bucle canónico**, porque está en la otra cuenta de cPanel: hay que nombrarle su raíz a mano.
>
> > *Esa tercera decía «su correo no lo ha comprobado nadie» cuando se escribió, y **dejó de ser
> > cierta el mismo día**: el transporte quedó arreglado en **las dieciocho**. Se corrige aquí en
> > vez de borrarse porque lo que no caduca es el motivo — `lal` sigue fuera del glob, así que el
> > siguiente censo que se corra sin nombrarla volverá a dejarla fuera y dirá «diecisiete» como
> > si fueran todas.*

Lo que ship el repo es seguro: `config/aplicacion-movil.php` hace `env('APP_MOVIL_VERSION_MINIMA')`
**sin valor por defecto** —ausente = campo no enviado— y `.env.example` la trae **vacía**.

**La comprobación, que hay que correr aunque no se despliegue nada** (y sobre todo en `demo`, que
es donde entra el revisor de Google con un login nuevo):

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-45s %s
' "$d" "$(grep -E '^APP_MOVIL_VERSION_MINIMA=' "$d/.env" || echo 'ausente')"
done
```

#### CORRIDO el 2 sep 2026 — **limpio en todos, y de paso salió un colegio de más**

```
16 carpetas de colegio  ->  ausente (ni la línea)
demo                    ->  APP_MOVIL_VERSION_MINIMA=   (presente y VACÍA)
```

**Nadie está bloqueado y la revisión de la app no corre peligro.** `demo` —el único que toca el
revisor de Google— la tiene puesta pero **vacía**, y eso es exactamente el estado seguro:
`VersionMinimaDeLaApp` hace `if ($puesto === null || $puesto === '') return null;`, así que
**«ausente» y «vacía» son lo mismo** y el campo no viaja a nadie. No hay nada que borrar.

> **Y el barrido destapó otra cosa, que no es de la app: el servidor tiene DIECISÉIS colegios, no
> quince.** Las carpetas que devolvió el bucle son 17: dieciséis colegios más `demo`. `CLAUDE.md`
> dice **«Quince colegios»** desde la baja del 25 ago, y **nadie sumó `lal`**, que entró el 30 ago
> por el traslado de `9474b50` — el commit que es justo la base de esta tanda.
>
> `DESPLIEGUE-REFERENCIA.md` ya lo decía por su lado sin que cuadrara con el otro: *«quince
> colegios + `demo` + `edilson` + **la nueva de `lal`**»*. O sea que **los dos documentos llevaban
> discrepando desde el 30 ago** y el servidor le da la razón a la referencia.
>
> **Por qué importa el día del despliegue y no es cosmético:** el bucle escribe sobre
> `*.micolevirtual.com/8myvc`, así que **alcanza a los dieciséis y a `demo`**. Quien despliegue
> contando quince va a ver un colegio de más y tendrá que decidir a las tres de la mañana si es
> uno legítimo o algo que sobra — y la respuesta es que es legítimo. **El número de `CLAUDE.md` lo
> mueve Joseth**, no una sesión; queda aquí medido para que el día del despliegue nadie tenga que
> averiguarlo.

**Lo que se espera ver: ausente o vacía en los dieciséis. Y lo que se busca es CUALQUIER valor no
vacío, no «un valor ≥ 4»** — el porqué está justo debajo y corrige lo que decía esta línea.

> **Corrección del 2 sep 2026, de `myvc-flutter-14`: el suelo NO es el build 3.** La app se
> reparte por **dos canales** (`publicacion-play.md`): Play —en prueba cerrada, 27 verificadores—
> y **APK directo**, «enlace en la web del colegio, WhatsApp». Quien instaló por WhatsApp tiene el
> build que llevara ese APK, y **nadie garantiza que sea el 3**: puede andar por el **1 o el 2**.
>
> Así que **un `3` puede estar bloqueando gente ahora mismo** —a todo el que ande por el 1 o el
> 2—, y un `2` bloquearía a los del 1. Por eso el criterio no es un umbral: **cualquier valor no
> vacío es sospechoso** hasta saber qué builds circulan en ese colegio, y se borra hoy.

> **Y no se arregla poniendo «un número bajo», que es la salida que parece prudente.** Un `3` es
> una trampa cargada por los dos lados: hoy bloquea a los de los canales directos, y el día que se
> publique el build 4 quien ponga `4` bloquea de golpe a todo el que siga en el 3. Mientras no haya
> una versión más nueva **en la tienda**, lo correcto es **ausente o 0**.

> **Y esto no lo va a delatar nada más, así que el barrido no es una confirmación: es la única
> detección que existe.** Medido por `myvc-flutter-14` el 2 sep 2026: el camino del bloqueo
> —`VersionMinima`, `RouteGenerator`, `ActualizarScreen`— **no tiene ni una llamada a la
> analítica**. Cero. Y no es que la app no mida: hay eventos para `planilla_abierta`,
> `notas_guardadas`, `usuarios_abierta`, `situacion_creada`… pero **ninguno para la única pantalla
> que significa «esta persona no puede usar la app»**. Un colegio con un número malo tiene a sus
> usuarios contra un muro **y eso no aparece en ningún contador, ningún evento y ningún log**.
> Queda como deuda del lado de Flutter para el próximo build; no llega al que está en revisión.

**Esto se levanta cuando la app salga de revisión**, o antes si las dos preguntas de arriba se
contestan que no. Lo levanta Joseth, no una sesión. — **Contestadas que no el 2 sep**; lo que
queda por decidir ya no es de la app.

---

### ⛔ TANDA PENDIENTE — 321 commits desde `9474b50`. SIN LAS MIGRACIONES NO SE PUEDE NI ENTRAR

**El aviso que había aquí decía «los tres boletines contestan 500», y se quedaba corto por dos
órdenes de magnitud.** Lo que cae con el código nuevo y la base sin migrar **no es una pantalla:
es el colegio entero, empezando por el login**.

`2026_09_02_100000_nivelaciones_columnas` añade `years.regla_nivelacion`, y esa columna la nombra
`ContextoDeUsuario::construir()` en **las cuatro ramas** del `switch`
(`app/Services/ContextoDeUsuario.php:135, 165, 195, 228`) — o sea en el `SELECT` que arma
`$this->user`. Y ese `SELECT` no lo dispara un controlador: lo dispara **el propio guard**, porque
`ExigirAutenticacion::handle()` llama a `User::fromToken()`
(`app/Http/Middleware/ExigirAutenticacion.php:39`), que va a parar a `construir()`. **Ninguna
petición llega a su método.**

| Sin la migración, con el código nuevo dentro | |
|---|---|
| **558** de las 576 rutas de `api/` llevan `auth.token` — recontado el 5 sep 2026 con `route:list --json`; las 18 que no lo llevan **no se movieron** | **500 en el guard**, antes del controlador |
| `POST login` (`LoginController:51`) y `POST auth/login` (`Auth\SesionController:63`) | **500** — montan el contexto ellos mismos: **no se puede iniciar sesión** |
| Lo que queda en pie | `login/logout`, `login/recuperar-clave`, `login/reset-password`, `login/ver-pass`, `login/crear-prematricula`, `publicaciones/ultimas`, `colegio/logo` y el quiosco de `tardanzas/*` — que no pasan por el contexto |

**No es teoría.** Le pasó al docker la madrugada del 2 sep 2026 al fusionar, y **lo detectó la
sesión del front, no nosotros**. Reproducido aquí contra una base sin migrar: la consulta del
contexto contesta `SQLSTATE[42S22] Unknown column 'y.regla_nivelacion' in 'field list'`.

> ## ⚠️ ESTA SECCIÓN LLEVA CATORCE DÍAS Y LA TANDA HA TRIPLICADO — REMEDIR ANTES DE DESPLEGAR
>
> **Medido el 19 sep 2026 por la noche en el árbol principal, sobre `main` tras vaciar la cola de
> ramas (`8279c62`):**
>
> | | decía (5 sep, `24967af`) | es hoy (19 sep) | **REMEDIDO el 20 sep (`f4177a7`)** |
> |---|---|---|---|
> | commits | **321** | **632** | **771** |
> | migraciones | **SIETE**, cuatro bloqueantes | **25** | **34** |
> | rutas | 578 | **615** | **647** |
>
> **Y la tercera columna trae lo que ninguna de las otras dos midió: el esquema.** Comparadas dos
> bases —una con la tanda y otra con las migraciones que había en `9474b50`—, la tanda son
> **23 tablas nuevas, 43 columnas nuevas y DOS columnas que se retiran**:
> `matriculas.boletin_independiente`, la que ya estaba escrita aquí, y **`areas.jefe_id`**, que no
> lo estaba. Cero lectores de `jefe_id` en los cuatro clientes, comprobado uno a uno.
>
> **Y las rutas: 543 → 647, y sigue yéndose UNA SOLA**, la del aviso L. O sea que las dos filas de
> «lo único que un cliente puede perder» aguantan quince días y trescientos commits después,
> restando los 61 snapshots de contrato del rango: sólo pierden claves `ChangesAsked/to-me` (las
> siete de auditoría de **K**, que Flutter no lee) y `areas`/`materias` (`jefe_id`). Las demás
> **sólo ganan**.
>
> **No se rehace la tabla de abajo aquí, y es a propósito**: lo que hay debajo es *lo que se midió
> el día de aquel despliegue*, y la regla de `CLAUDE.md` es que se remide **el día del siguiente**,
> entero y de una vez, no fila a fila cada vez que alguien funde algo. Lo que sí hace falta es que
> nadie la lea creyendo que describe el árbol de hoy — que es exactamente el fallo que la propia
> sección denuncia dos párrafos más abajo («una tabla con dos extremos es peor que una tabla
> vieja: la vieja se remide, la mezclada se cree»).
>
> **Y ahora muerde de verdad, que es por lo que este aviso se escribe hoy y no se dejó para el
> día del despliegue: el congelado se levantó el 19 sep** —la app salió de revisión—, así que el
> siguiente que abra este documento puede ser alguien a punto de desplegar. Ir a los dieciséis
> colegios con «son siete migraciones» en la cabeza cuando son veinticinco no es un susto: es
> decidir a las tres de la mañana si las dieciocho que sobran son legítimas.
>
> Las tres cifras se rehacen con las órdenes que ya están en la tabla de abajo, cambiando el
> extremo por el hash de hoy:
>
> ```bash
> git rev-list --count 9474b50..<hash>
> git diff --name-only 9474b50 <hash> -- database/migrations/ | wc -l
> docker exec -w /app 8myvc-app-1 php artisan route:list --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'
> ```

#### Lo que es esta tanda, remedido entero el 5 sep 2026 a las 22:1x sobre `9474b50..24967af`

**El extremo va escrito con su hash y no como `HEAD`**, y no es una manía: aquí se trabaja con un
árbol por sesión, así que `HEAD` es una cosa distinta en cada worktree y una tabla medida con
`HEAD` no la puede reproducir nadie. **`24967af` es la punta de `main`**, y **`origin/main` es el mismo commit**: cero de divergencia,
comprobado el 5 sep 2026 tras empujar. O sea que **esta tabla sí describe lo que trae un `git pull`**.

> **Y esta tabla se volvió a medir ENTERA en vez de corregirle la fila que cambió, porque durante
> una hora estuvo mezclando dos hashes.** A las 16:57 se cerró sobre `3970cea` con **307** commits
> y **577** rutas; a las 18:0x entró la sexta de `horario/` y se le actualizó la fila de rutas a
> **578** dejando el resto —y el rótulo— de `3970cea`. **Las dos cifras eran ciertas y ninguna era
> del mismo commit que la otra**, bajo un encabezado que anunciaba un solo rango. *Una tabla con
> dos extremos es peor que una tabla vieja: la vieja se remide, la mezclada se cree.*

| | | comprobado con |
|---|---|---|
| commits | **321** | `git rev-list --count 9474b50..origin/main` |
| `app/` | **57** ficheros | `git diff --name-only 9474b50 main -- app/ \| wc -l` |
| `routes/` | **8** ficheros — **las rutas SÍ se movieron: 543 → 578** (36 nuevas, **1 retirada**) | `git diff --name-only 9474b50 main -- routes/` · `route:list --json` |
| `config/` · `composer.json`/`.lock` · `database/schema/` | **0** | `git diff --name-only 9474b50 main -- config/ composer.json composer.lock database/schema/` |
| **migraciones** | **SIETE ficheros**, y **cuatro son bloqueantes** | `git diff --name-only 9474b50 main -- database/migrations/` |

> **El commit que escribe esta tabla queda, por construcción, por encima de ella.** `321` se contó
> contra `24967af`, que era la punta al medir; el commit que registra el recuento hace **322**, y el
> siguiente, **323**. Eso no es un error que corregir: es la razón de que el extremo se escriba
> **con su hash y nunca como `HEAD`**. Quien quiera comprobar esta fila corre
> `git rev-list --count 9474b50..24967af` y le da **321** hoy y dentro de un mes; quien corra
> `..origin/main` está midiendo **otro rango**, el suyo, que es el que hay que remedir el día que
> se despliegue.

**Las 36 y la retirada no se contaron a ojo**: salen de restar los dos
`tests/Contrato/Snapshots/rutas.json` —el de `9474b50` y el de `main`, **543** y **578** claves—,
que es el mismo dato que da `route:list --json` y además lo mueve un test. **543 + 36 − 1 = 578**,
y la retirada es `POST tardanzas/login/traer-datos`, que no tiene ninguna otra.

> **Y en el remedido de las 22:1x eso dejó de ser una comodidad y fue la única salida: Docker
> estaba caído** (se lo llevó el reinicio de la máquina), así que **`route:list --json` no se podía
> correr**. Restar los dos snapshots dio **543 → 578, 36 nuevas y una retirada**, idéntico a lo que
> ya decía esta tabla y **reproducible sin levantar nada**. La otra mitad, la que impide heredar el
> número: `git diff --name-only d606839 24967af` da **cuatro ficheros y los cuatro son documentos**
> —cero de `routes/` y cero de `app/`—, o sea que **el router no ha podido moverse** desde que se
> contó. *Un número no se hereda porque siga escrito; se hereda cuando se demuestra que su entrada
> no ha cambiado.*

> ### Antes de desplegar: que `origin/main` sea lo que mediste
>
> ```bash
> git rev-parse --short origin/main main    # los dos hashes, y tienen que ser el MISMO
> ```
>
> **El Paso 1 despliega con `git pull`, así que lo que se despliega es `origin`, no tu `main`.**
> El 5 sep 2026 a las 18:0x coinciden los dos en `d606839` y esta tabla describe exactamente lo
> que trae un `git pull`.
>
> **Se deja la orden y no el resultado, y esto costó una caja entera.** Durante unas horas de ese
> día `main` local iba **14 commits por delante** y aquí hubo un aviso que lo decía: contra
> `origin` la tanda era de **CINCO** migraciones y no de siete —las dos de la plantilla estaban
> sólo en local—, así que la comprobación de diez segundos habría contestado `FALTA` **con razón**.
> En cuanto se empujó, **ese aviso pasó a decir lo contrario de lo que había**, y un aviso sobre un
> estado *envejece hacia el peligro*: de proteger a engañar, sin que nada se ponga rojo. Lo que no
> caduca es **la comprobación**, así que es lo único que se queda.

> ### Por qué esta sección se remide entera y no se le suma
>
> **En veinticuatro horas este rango tuvo NUEVE cifras, y las nueve fueron ciertas el minuto en que
> se midieron:** el documento decía **191**; la rama `docs/despliegue-remedido` midió **232** el 4
> sep; `8myvc-ae` contó **274** la tarde del 5 y al ir a escribirlo eran **279**; al cerrar la
> plantilla **285**; al rescatar el `CLAUDE.md` huérfano **286**; y al cerrar con el Lote G
> **307**; **315** al entrar la sexta ruta del horario; y **321** al fundir
> `docs/despliegue-remedido-2` y limpiar el espacio de trabajo. **Nueve cifras.** Nadie se
> equivocó en ninguna.
>
> *Y este párrafo se equivocó en la suya: decía «cuatro cifras» y llevaba siete en la lista, porque
> cada remedido añadía una y nadie tocaba el recuento de arriba. **El párrafo que explica que los
> números envejecen envejeció por dentro**, que es la demostración más barata de que la regla no es
> manía.*
>
> **Lo que falla no es medir, es el hueco entre medir y escribir** — `main` se mueve en medio,
> porque aquí trabajan varias sesiones a la vez. De ahí las dos reglas de esta sección, que
> parecen manía y no lo son: **el extremo del rango se escribe con su hash**, y **la cifra se
> vuelve a contar el día que se toca el documento**, nunca se hereda de la frase de antes.

> **Y el 4 sep 2026 apareció la otra mitad de la regla de abajo: el «desde dónde» estaba bien y el
> «HASTA dónde» estaba mal escrito.** Este encabezado decía `9474b50..347f137` y **ninguna de las
> cinco cifras de la tabla era de ese rango**: sobre `347f137` salen **175** commits, **52**
> ficheros de `app/`, **6** de `routes/` y **SEIS** migraciones. Los 191/54/7/SIETE que había aquí
> son exactamente `9474b50..aebf4ed` —la fusión del suelo del horario, dos horas más tarde el mismo
> día—, o sea que **las cifras se remidieron tras fusionar y la etiqueta del rango se quedó del
> sondeo anterior**. Comprobado commit a commit: `aebf4ed` es el **único** commit del rango en el
> que `git rev-list --count 9474b50..<x>` da 191.
>
> **Iba en la dirección que no se nota**: las cifras eran ciertas, el rótulo no, y un rótulo no lo
> pone rojo ningún test. Quien hubiera ido a `347f137` a comprobar la tabla habría encontrado seis
> migraciones donde dice siete y habría creído que la tabla envejeció, cuando lo que estaba mal era
> dónde miraba. *Un extremo de rango se copia del comando que produjo las cifras, no de la frase de
> antes.*

> **La base es `9474b50` y no `eb95cbc`, y esto costó una medición entera.** El rango que había
> escrito arrancaba en `eb95cbc`, que es la tanda del **25 ago**; la del 25–30 ago
> (`eb95cbc..9474b50`, 44 commits) **se desplegó el 31** y está registrada más abajo en este mismo
> documento. Medir desde `eb95cbc` da 219 commits y **cuenta dos veces lo ya desplegado**: no es
> sólo ruido, es que la tabla de migraciones acababa listando `2026_08_30_200000_notas_finales_en_decimal`
> como pendiente cuando lleva dentro desde el 31. *Un rango sin desplegar se remide entero cada vez
> que se le toca; y lo primero que se remide es **desde dónde**.*

#### Las siete, ordenadas por lo que tumban

> **Eran OCHO ficheros hasta el 4 sep 2026 y son CINCO, sin que cambie una sola columna.**
> Se fusionaron `2026_09_02_200000_nivelacion_de_la_definitiva` y
> `2026_09_02_300000_acta_de_la_recuperacion_final` dentro de
> `2026_09_02_100000_nivelaciones_columnas`, y `2026_09_04_200000_tono_del_docente` dentro de
> `2026_09_04_100000_horario_versiones`. **Ninguna se había desplegado nunca** —el despliegue
> está congelado— y el día que salgan esto ya no se puede hacer.
>
> Comprobado construyendo las dos bases desde cero y comparando `information_schema`:
> **1.526 columnas, mismas posiciones físicas, mismos índices y mismas foráneas**, 102 tablas
> en las dos. La fusión de nivelaciones además **quita una reconstrucción de `notas_finales`**
> en un colegio que corra MySQL 5.7 (dos `ALTER` sobre esa tabla pasan a uno), que ~~es la única
> incógnita abierta de este despliegue: no sabemos qué MySQL corren los diecisiete~~ **era la última
> incógnita abierta, y la cerró Joseth el 5 sep 2026** — el bloque siguiente.

> ### PRODUCCIÓN CORRE MARIADB 10.5.25, NO MYSQL 8 — contestado por Joseth el 5 sep 2026
>
> `SELECT VERSION();` desde phpMyAdmin contesta **`10.5.25-MariaDB-cll-lve`**, y **lo mismo en los
> dos shared hostings** —el bucle de `micolev1` y la cuenta de `lalvirtual.edu.co`—; el sufijo es
> CloudLinux con límites LVE por cuenta. **El docker corre MySQL 8.0.42**, así que el ensayo de la
> tanda se hizo sobre OTRO motor. Lo que eso cambia y lo que no, medido sobre `d606839`:
>
> - **La reconstrucción de `notas` que temía el bloque de arriba no ocurre.** MariaDB añade y quita
>   columnas al instante desde la 10.4, también con `AFTER`, siempre que la tabla sea InnoDB sin
>   `ROW_FORMAT=COMPRESSED`: **las 90 del volcado lo son**. Las cinco columnas de `notas` deberían
>   costar lo del docker (11,8 ms), no los 4.870 ms del `ALGORITHM=COPY`. *«Deberían»: se midió en
>   MySQL 8 y se razona para MariaDB; el número de MariaDB no existe todavía.*
> - **El código nuevo no usa nada que MariaDB 10.5 no tenga.** `grep` sobre las **14.264 líneas
>   añadidas** entre `9474b50` y `d606839` en `app/`, `database/` y `routes/` buscando `JSON_TABLE`,
>   `LATERAL`, `->>`, `->'`, `MEMBER OF`, `REGEXP_LIKE`, `JSON_ARRAYAGG`, `JSON_OVERLAPS` y
>   `utf8mb4_0900`: **cero fuera de comentarios** (los cuatro que salen son docblocks que hablan de
>   `ALGORITHM=INSTANT`). La población es sólo lo nuevo a propósito: lo desplegado corre allí desde
>   el 31 ago y ya contestó por sí mismo.
> - **Lo que sigue sin medir: la tanda sobre MariaDB.** `tools/ensayo-de-la-tanda.sh` la corrió
>   sobre MySQL 8. Convertir «deberían» en un número es un contenedor `mariadb:10.5` con la copia de
>   `simonbolivar` y las siete encima. No cambia el plan del día 10; es lo único que queda entre la
>   tanda y un ensayo sobre el motor de verdad.
>
> ### Y una pregunta que la versión NO contesta: el motor de cada tabla, colegio por colegio
>
> La preguntó Joseth el 5 sep 2026 —*«¿es posible que algunas de mis bases sean muy viejas y tengan
> tablas no InnoDB?»*— y **desde este repositorio no se puede contestar**: el volcado y la copia del
> docker son **un** colegio (90 tablas en el volcado, 102 vivas, todas InnoDB), y un colegio nuevo se
> crea copiando la base de otro, con sus motores dentro. Nadie ha censado los otros.
>
> **Y no es una pregunta de tiempo, es de si la tanda termina.** `rubricas` crea
> `rubrica_valoraciones` con una clave ajena hacia **`notas`**, y `horario_versiones` y `rubricas`
> apuntan a `years`, `asignaturas`, `profesores` y `subunidades`. InnoDB **no puede apuntar a una
> tabla MyISAM**: el `CREATE TABLE` falla con el errno 150 y la tanda se queda **a medias**, que es el
> estado que este documento llama el peor. En el colegio del volcado esas cuatro ya reciben claves
> ajenas (13 hacia `years`, 10 hacia `asignaturas`, 6 hacia `profesores`, 1 hacia `subunidades`),
> así que allí son InnoDB; **`notas` no recibe ninguna hoy y es la que la tanda estrena.** Las
> migraciones ya desplegadas el 31 ago sólo probaron `alumnos` y `periodos`.
>
> **Se contesta con dos consultas en el phpMyAdmin de cada hosting**, sin elegir base —van contra
> `information_schema` y ven todas las de la cuenta—. La primera es la población; sin ella un
> resultado vacío de la segunda no distingue *«revisé diecisiete bases»* de *«no vi ninguna»*:
>
> ```sql
> -- 1. población: cuántas tablas y de qué motor tiene cada base de la cuenta
> SELECT table_schema, engine, COUNT(*) AS tablas
> FROM information_schema.tables
> WHERE table_type = 'BASE TABLE'
>   AND table_schema NOT IN ('information_schema', 'mysql', 'performance_schema')
> GROUP BY table_schema, engine ORDER BY table_schema, engine;
>
> -- 2. las que rompen la tanda: cualquier fila aquí es un colegio donde NO se migra hasta convertirla
> SELECT table_schema, table_name, engine, row_format
> FROM information_schema.tables
> WHERE table_name IN ('notas', 'notas_finales', 'recuperacion_final', 'matriculas', 'years',
>                      'asignaturas', 'profesores', 'subunidades', 'unidades_por_defecto')
>   AND (engine <> 'InnoDB' OR row_format = 'Compressed');
> ```
>
> Si la segunda devuelve filas, la salida es `ALTER TABLE x ENGINE=InnoDB` **antes** de la tanda y en
> ese colegio sólo, y es una reconstrucción entera de esa tabla: se mide allí, no aquí. Se corre en
> **los dos hostings**, porque la versión salió igual en los dos y eso no dice nada del motor.
>
> #### CONTESTADA para `micolev1` el 5 sep 2026: 18 bases, todas InnoDB, y la segunda consulta vacía
>
> Joseth las corrió esa tarde en el phpMyAdmin de `micolev1`. **Las 18 bases de la cuenta salen
> InnoDB en todas sus tablas** —los dieciséis colegios, `demo` y `edilson_feryz`, que no es un
> colegio— y la segunda consulta devolvió **cero filas con esa población delante**. El otro hosting es
> el servidor viejo de `lal` (`micolevi`, [TRASLADO-LAL.md](TRASLADO-LAL.md)), y la base de `lal` ya
> vive aquí como `micolev1_lal_db`: **las diecisiete bases que recorre el bucle del día 10 están en
> esta lista**, así que la pregunta del motor queda cerrada para lo que se despliega.
>
> **Y la misma consulta trajo un número que nadie tenía: las bases NO tienen las mismas tablas.**
>
> ```
> demo, simonbolivar_medellin           94   <- lo que debe tener un colegio en `9474b50`
> coab_saravena, lal_db                 93
> arauca_maranatha                      92
> cads_itagui, coal_bucara, colbosque_tame, coljordan, semillitas   89
> bethel_arauquita, caz_zaragoza, comad_san_andres, fortul_adventista,
> la_hermosa, quibdo_db                 88
> amiguitosdejesus                      87
> ```
>
> El 94 no es una suposición: el docker con las siete migraciones pendientes tiene **102**, y la
> tanda crea **8** tablas (cinco de `rubricas`, tres de `horario`); 102 − 8 = 94, contado sobre
> `information_schema`. O sea que **quince colegios tienen entre una y siete tablas menos que el
> esquema del repositorio**, y desde aquí no se sabe cuáles. Con el código desplegado funcionando en
> los quince, lo que falta son tablas viejas que esas bases nunca tuvieron, no las cuatro que creó
> la tanda del 31 ago; pero eso es una inferencia, no una medición.
>
> **Lo que le importa a la tanda es una sola pregunta: si las once tablas que ALTERA existen en las
> diecisiete.** Un `Schema::table()` sobre una tabla que no está es el mismo errno que una MyISAM:
> la tanda a medias. Y de las once, `recuperacion_final` y `unidades_por_defecto` son tablas de
> función, no de núcleo, o sea las que una base vieja podría no tener:
>
> ```sql
> -- 3. las once que altera la tanda: tiene que salir 11 en las diecisiete bases
> SELECT table_schema, COUNT(*) AS de_once
> FROM information_schema.tables
> WHERE table_schema LIKE 'micolev1\_%'
>   AND table_name IN ('notas', 'notas_finales', 'recuperacion_final', 'matriculas', 'years',
>                      'asignaturas', 'profesores', 'subunidades', 'unidades_por_defecto',
>                      'permissions', 'permission_role')
> GROUP BY table_schema ORDER BY de_once, table_schema;
>
> -- 4. el censo de la deriva: qué tablas NO están en las diecisiete, y en cuántas sí
> SELECT table_name, COUNT(*) AS en_bases
> FROM information_schema.tables
> WHERE table_schema LIKE 'micolev1\_%' AND table_schema <> 'micolev1_edilson_feryz'
>   AND table_type = 'BASE TABLE'
> GROUP BY table_name HAVING COUNT(*) < 17 ORDER BY en_bases, table_name;
> ```
>
> La 3 es la que decide; la 4 es la que explica el 87 y evita que el día 10 alguien lo descubra con
> un `SELECT *`. ~~Ninguna de las dos se ha corrido todavía.~~ **Las dos las corrió Joseth esa misma
> tarde:**
>
> - **La 3 da `11` en las diecisiete.** La tanda no se va a encontrar ninguna tabla que falte:
>   **cerrado.**
> - **La 4 nombra las siete** y ninguna la toca la tanda: `df_notas_finales` (en 4 bases, muerta),
>   las cinco `piars_*` (en 5, y `piars_actas_acuerdo` en 7: el módulo PIAR de `myvc_front_2`) y
>   `uniformes` (en 16 **el 5 sep por la tarde; esa misma noche pasó a los diecisiete, ver abajo**:
>   la que no la tenía era `amiguitosdejesus`, y su panel de inicio la
>   consulta sin condición — contesta 500 allí hoy, para alumnos y profesores con grupo). El censo
>   entero, con las catorce tablas que no lee nadie, está en
>   [05 §246](migracion/05-codigo-muerto-y-roto.md). **Nada de esto cambia el día 10.**

> #### ⚠️ `PUT calendario/mes` NO EXISTE EN LA API, y `app2` come de ahí — 6 sep 2026
>
> **Salió de contestarle una pregunta al front, y no lo buscaba nadie.** `myvc-front-05` y
> `myvc-front-21` preguntaron, por separado, si `calendario/mes` pierde `created_by_nombres` como
> `ChangesAsked/to-me`. La respuesta a eso es **no** —el recorte vive sólo en
> `ChangeAskedController:102`, `putThisYear` hace `SELECT *`, el controlador no cambió entre
> `9474b50` y hoy y su snapshot tampoco—, pero al mirarlo salió lo otro:
>
> ```
> routes/api/perfiles.php tiene HOY:  crear-evento · eliminar-evento · guardar-evento
>                                     sincronizar-cumples · this-year
> calendario/mes y calendario/proximos:  SÓLO en la rama `feat/calendario`
> ```
>
> Esa rama tiene **2 commits propios y 230 de retraso, y trae una migración**, que es por lo que
> Joseth la dejó fuera de la tanda el 5 sep: entrar cuesta reensayar la tanda entera. O sea que
> **esa ruta no existe hoy y tampoco existirá el día 10**, y `app2` tiene un fijo de contrato
> (`paginas/calendario/contrato-mes.spec.ts`) con un volcado real de una respuesta que en
> producción es un **404**.
>
> **Lo que esto NO es**: un problema que arregle fundir la rama. Fundirla mete la octava migración
> en una tanda ensayada sobre siete.
>
> ##### CONTESTADO por `myvc-front-05` el 6 sep: la pantalla está PUBLICADA
>
> ```
> ruta      /calendario, en app.routes.ts:1608
> permiso   `conSesion` — CUALQUIERA con sesión: docentes, alumnos y acudientes
> menú      entrada «Calendario» visible con el mismo criterio
> entró     en su `main` el 23 ago 2026
> llama     `PUT calendario/mes` al abrir, y `calendario/proximos`
> ```
>
> O sea que **las dos rutas que sólo existen en la rama congelada son justo las dos que usa**, y la
> entrada del menú la ve todo el colegio.
>
> **Y la mitad que ya estaba escrita, para no atribuirse el hallazgo entero:** el acoplamiento lleva
> documentado **desde el 2 sep 2026** en `myvc_front@DESPLIEGUE-UP2.md`, primera fila de «lo que hay
> que mirar ANTES de desplegar», y con una distinción que aquí no teníamos: **falta la ruta → 404 y
> «No se pudo cargar el calendario»; `pull` hecho y `migrate` sin correr → 500**, que es peor. O sea
> que **`up2/` no se despliega a ciegas**: esto no es un riesgo desconocido.
>
> **Lo que aporta este bloque es la otra mitad, y es la que cambia el plan:** *por qué* falta la
> ruta. `feat/calendario` está fuera de la tanda del día 10 **a propósito**, así que **esperar a que
> llegue no es esperar unos días**. Juntas, las dos mitades dicen: *si el día 10 se sube `up2/`, el
> calendario se rompe en los dieciséis y la entrada la ve cualquiera con sesión.* **Ruidoso y no
> peligroso** —404 y un mensaje, no un dato mal guardado— pero visible para todos.
>
> ##### Y NO HAY ATAJO: las rutas no se pueden llevar sin la migración
>
> Comprobado aquí antes de ofrecerlo como salida, porque era la tentación evidente —*«funde sólo
> las dos rutas y deja la migración fuera»*—:
>
> ```
> eventosManualesDelRango()  SELECT c.descripcion, c.recordatorio_minutos   <- columnas NUEVAS
> filtroDeDestinatarios()    NOT EXISTS (SELECT 1 FROM calendario_destinatarios d …)  <- tabla NUEVA
> ```
>
> Las dos las usa `respuestaDelRango()`, que es de donde comen `putMes` y `putProximos`. **Sin la
> migración, esas dos rutas contestan 500 en vez de 404**, que es peor. La rama entra entera o no
> entra.
>
> ##### LAS TRES SALIDAS, y ninguna la decide una sesión
>
> | | qué cuesta |
> |---|---|
> | **(a)** meter `feat/calendario` en la tanda | reensayar la tanda entera con la octava migración, sobre una copia con datos, antes de tocar dieciséis colegios |
> | **(b)** subir `up2/` con el calendario tapado | esconder la entrada del menú y guardar la ruta hasta que exista su backend. **Es trabajo del front y es poco** |
> | **(c)** no subir `up2/` el día 10 | **la más barata, y puede que se le haya pasado a todo el mundo**: lo que el día 10 necesita es la API y `up/` —el arreglo del tooltip viaja por ahí—. `up2/` no hace falta para nada de ese día |
| ~~**(d)** apuntar la pantalla a `calendario/this-year`, que sí existe~~ | **DESCARTADA el 6 sep, medida antes de proponerla.** Ver abajo |

> ##### Por qué la (d) no vale, porque es la que se le va a ocurrir al siguiente
>
> `calendario/this-year` existe hoy y devuelve eventos, así que apuntar ahí la pantalla nueva parece
> gratis. Medido lado a lado, no lo es:
>
> ```
> this-year   lista PELADA, 17 claves por evento
> mes         {desde, hasta, eventos:[…]}, 14 claves por evento
> ```
>
> 1. **El envoltorio no es el mismo.**
> 2. **Seis claves que la pantalla lee no están en `this-year`** —`clave`, `origen`, `persona_id`,
>    `descripcion`, `recordatorio_minutos`, `destinatarios`—, y **tres de ellas no pueden estar**:
>    son las columnas y la tabla que crea la migración congelada.
> 3. **Y la que de verdad la descarta: `this-year` filtra MENOS.** Hoy hace
>    `SELECT *` para profesor o superusuario y `WHERE solo_profes = 0` para todos los demás, o sea
>    que a un alumno o a un acudiente le manda **todo lo que no sea interno**. La rama filtra además
>    por `calendario_destinatarios`, o sea sólo lo que le toca por público y por grupo. **Apuntar la
>    pantalla nueva a `this-year` no le enseñaría menos: le enseñaría MÁS de lo que su diseño
>    quiere.** Eso deja de ser un apaño de compatibilidad y pasa a ser cambiar quién ve qué.
> 4. Y de paso, `this-year` devuelve el año entero y no un mes.
>
> *Con la (c) no hace falta ninguna de estas contorsiones y el coste es cero por los dos lados.*
>
> ##### Y LA RAZÓN 3 NO ES «UNA MÁS»: es de otra especie, y lo afinó el front
>
> `myvc-front-05` comprobó las tres por su lado —contó las claves en su propio código:
> `destinatarios` sale **16 veces**, `clave` 13, `origen` 4— y fue a leer `putThisYear` **en este
> repositorio** en vez de fiarse. Y al confirmarlo dijo mejor que nosotros por qué la 3 decide:
>
> > **Las claves que faltan son un problema de FORMA, y los de forma se resuelven con trabajo. «Enseña
> > más de lo que debía» no es un problema de forma.** Un traductor que rellena huecos se puede
> > escribir mal **y se nota**. Un filtro que se relaja **no se nota nunca**, porque el síntoma es que
> > alguien ve algo de más — y nadie va a decirlo.
>
> Es la misma familia que el modal del calendario que ya costó una vez en el front: *escribía los
> grupos en ningún sitio, sin error, mientras el resumen decía que lo verían 6º y 7º*. Aquí sería el
> mismo animal por el otro lado.
>
> *De ahí sale el criterio que sirve fuera de este caso: **cuando un apaño toca a la vez la forma de
> una respuesta y su filtro, el filtro decide.** Lo de la forma se prueba; lo del filtro sólo se
> prueba si alguien se acuerda de probarlo.*
>
> ##### Lo que NADIE sabe todavía: si ya está roto HOY
>
> El front no puede verlo —no tiene copia local de `myvc_dist2`, así que no sabe cuándo se
> construyó `up2/` por última vez—, y desde aquí tampoco. **Lo sabe Joseth, que es quien despliega.**
> Lo único medido es que `myvc_dist`, el de la aplicación vieja, se construyó por última vez el
> **21 ago 2026**, o sea antes de que esa pantalla existiera. *«No medido» no es «bien».*
>
> *Y el fijo del front no se pondría rojo: es un volcado literal, no una llamada viva. Una spec de
> contrato que describe una ruta inexistente **envejece en silencio**, que es justo lo que esas
> specs existen para evitar.*

> #### Y `uniformes` ya está creada — 5 sep 2026, 22:4x, en producción
>
> **La creó Joseth desde phpMyAdmin** con [`tools/crear-uniformes-donde-falta.sql`](../tools/crear-uniformes-donde-falta.sql),
> y las comprobaciones del script contestaron sobre la base de verdad
> (`micolev1_amiguitosdejesus`, motor `10.5.25-MariaDB-cll-lve`): antes, la tabla no estaba y los
> tres destinos eran InnoDB con su `id` en `INT UNSIGNED`; después, **22 columnas, 3 claves ajenas,
> 0 filas, InnoDB**. Ese colegio pasa de 87 a 88 tablas y **`uniformes` está en los diecisiete**.
>
> *De paso, el paso 1 convirtió en medición lo que hasta entonces era aritmética: que el colegio de
> 87 fuera el que no tenía `uniformes` se había deducido de 94 − 7 = 87, y ahora lo dijo la base.*
>
> **Esto no toca la tanda del día 10 y no cambia una línea de código**: quita 500 que ya estaban
> ocurriendo. **Lo que queda debiendo es la migración con `Schema::hasTable('uniformes')`**, que va
> después del día 10 y que en ese colegio será un no-op.

> **Y desde el 5 sep 2026 son SIETE, no cinco: entraron las dos de la plantilla de notas.**
> `2026_09_05_200000_alcance_de_la_plantilla` y
> `2026_09_05_300000_create_permiso_can_edit_plantilla_notas`, con la Entrega 1 del
> [28](migracion/28-competencias-e-indicadores.md). **Contadas con
> `git diff --name-only 9474b50 HEAD -- database/migrations/`, no sumadas** — 5 + 2 habría dado
> el número correcto describiendo mal la tanda, que es justo lo que avisa el bloque de rollback:
> desde la fusión **una migración son varias columnas de varias tablas**.
>
> Las dos son **aditivas y no tumban ninguna ruta viva** —ver sus filas—, que es lo que las
> distingue de las cinco de arriba y por lo que **no cambian el veredicto de esta tanda**.

> #### Y la tanda de SIETE está ensayada, no supuesta — 5 sep 2026
>
> `tools/ensayo-de-la-tanda.sh` sobre una copia de `simonbolivar` (210 MB, 102 tablas,
> **1.166.139 filas en `notas`**), con las dos ramas dentro:
>
> | | |
> |---|---|
> | migraciones que entran | **7**, preguntadas a git en el rango `9474b50..HEAD` |
> | corren | **7 de 7**, en **1.659 ms** de migraciones · **4.569 ms** el comando entero |
> | delta real | **8 tablas nuevas**, **20 columnas nuevas** en 7 tablas, **1 columna retirada** |
> | la copia migrada contra `simonbolivar` | idénticas: **1.528 columnas, mismo tipo** |
> | pendientes al terminar | **0** |
> | el módulo de horario en la copia | `200` con `total: 0` y el `403` donde toca — **LLEGÓ** |
>
> **Los dos controles saltan**, que es lo que hace que ese «OK» valga: contra la copia **sin
> migrar** la comprobación enumera las veinte cosas que faltan —las dos columnas de
> `unidades_por_defecto` y el permiso incluidos— y después de migrar dice `OK - la tanda entera
> dentro`. Y el ensayo verifica además la **cobertura**: *«de cada tabla que cambia pregunta al
> menos una cosa»*, que es la regla que se escribió el día que a la comprobación se le quedó
> fuera `profesores.tono`.
>
> > ⚠️ **Y una trampa del propio ensayo, pagada aquí para que no la pague el siguiente:
> > `PHP_EXEC` no lleva `-w` por defecto**, así que `artisan` corre en el árbol **principal**.
> > Desde un worktree, las migraciones que sólo existen en tu rama salen **`Migration not
> > found`** en el rebobinado y el ensayo aborta con `NO MEDIDO` — que se lee como «la tanda
> > está mal» y es «estás midiendo otro árbol». Es la misma trampa que
> > `tools/construir-bd-test.sh` ya documenta y **detecta**, y que ésta todavía no detecta. Se
> > corre así:
> >
> > ```bash
> > DB_ENSAYO=simonbolivar_ensayo_x \
> >   PHP_EXEC="docker exec -i -w /app/.worktrees/x 8myvc-app-1" \
> >   tools/ensayo-de-la-tanda.sh --limpiar
> > ```

| Migración | Qué rompe si falta | Radio |
|---|---|---|
| `2026_09_02_100000_nivelaciones_columnas` | **`years.regla_nivelacion`: el guard y los dos logins.** Y aparte: `notas.nota_original` y las de `notas_finales` las nombran la planilla (`NotasController:256` y `:306`), los boletines (`BoletinesController:298`, `Boletines2Controller:224`) y el boletín final (`BolfinalesController:529`); las dos del acta de la definitiva —absorbidas de `..._200000`— las nombra `DefinitivasPeriodosController:524-526` y `:718-721`; y las tres del acta de la recuperación del año —absorbidas de `..._300000`— **rompen una ruta que ya existe**: `putUpdateRecuperacion` nombra `nivelada_at, nivelada_por, observacion` en su `UPDATE`, su `INSERT` y su `SELECT` (`:818`, `:856`, `:884`) | **el colegio entero**, más `PUT definitivas_periodos/nivelar` y `PUT definitivas_periodos/update-recuperacion` |
| `2026_08_31_200000_puestos_con_bol_independiente` | `BoletinIndependiente::puestosCuentanIndependientes()` hace `SELECT puestos_con_bol_independiente FROM years WHERE id = ?` **sin condición y sin rescate** (`app/Services/BoletinIndependiente.php:394`), y todos los boletines pasan por ahí | los tres boletines, los certificados, preescolar, promovidos, `editnota` y los cuatro informes de puestos |
| `2026_09_03_100000_rubricas` | cinco tablas nuevas y `subunidades.rubrica_id`. **Nadie fuera de `RubricasController` las nombra** —comprobado uno a uno: la planilla, unidades, asignaturas y `ChangeAsked` pasaron a nombrar sus columnas justamente para que `rubrica_id` no se les colara— | las **10** rutas de `rubricas/` |
| `2026_09_04_100000_horario_versiones` | tres tablas nuevas, `years.horario_version_id` y —absorbida de `..._200000`— **`profesores.tono`, `varchar(32)` nullable**. Sin las tablas, `POST horario/versiones` revienta; sin `tono` caen `GET horario/versiones/{id}/lecciones`, que la nombra en su `SELECT`, y `PUT horario/docentes/{profesor_id}/tono`, que la escribe. **Y el radio de `years.horario_version_id` no es de este módulo**: la lee `ChangeAskedController::horarioOficialDelAnio()` desde `getToMe` en sus dos ramas, o sea `GET ChangesAsked/to-me` — la que pide la app al abrir. Sin la migración **cae el panel de todo el mundo** (23 §11.5.1) | **`GET ChangesAsked/to-me` + las rutas de `horario/`.** Con esta fila el colegio SÍ se cae |
| `2026_08_31_100000_retirar_boletin_independiente_de_matriculas` | nada del código nuevo: **retira** `matriculas.boletin_independiente`, que ya no lee nadie | ninguno hacia delante — **pero mira la fila de abajo** |
| `2026_09_05_200000_alcance_de_la_plantilla` | dos columnas anulables en `unidades_por_defecto` —`nivel_educativo_id` y `materia_id`—. Sin ellas caen las **9 rutas de `plantilla-notas/`** y **`GET unidades/de-asignatura-periodo`**, que desde esta tanda resuelve el alcance por `App\Support\AlcanceDeLaPlantilla` y **nombra esas dos columnas**. **Y ninguna respuesta viva las reparte sola**: las tres puertas están cerradas —el `SELECT *` de `UnidadesController` se retiró aquí, el de `YearsController` no devuelve las filas, y **no hay modelo Eloquent de esa tabla**—, así que a diferencia de `profesores.tono` no aparece en ningún sitio que nadie haya decidido | **las 9 de `plantilla-notas/` y la rejilla de cada mañana.** Con esta fila el colegio SÍ se nota |
| `2026_09_05_300000_create_permiso_can_edit_plantilla_notas` | una fila en `permissions`, **sin repartir a ningún rol**. Si falta, `Autoriza::puedeEditarPlantillaNotas` no lo encuentra en `perms` y la pantalla de la plantilla **queda sólo para superusuarios** — o sea que funciona, y por eso el fallo es mudo: nadie ve un error, ve que a rectoría «no le sale el menú» | ninguna ruta se cae; **cambia quién entra**, que es más difícil de detectar |

> ### La fila del horario ha envejecido DOS veces, y la segunda con la cifra quieta
>
> Esa fila decía, hasta esta reescritura, *«**1 ruta**, y detrás de `esAdministrativo`: el
> colegio no se cae»*, más *«`getVersiones` y `putOficial` siguen a 501 y no las tocan»* y
> *«`years.horario_version_id` sigue sin leerla nadie»*. **Las tres eran falsas.** Medido en
> `main` el 5 sep 2026, y reproducido por dos sesiones:
>
> ```
> ChangeAskedController:140 y :219   'horario_version_id' => horarioOficialDelAnio(...)
> ChangeAskedController:1274         SELECT horario_version_id FROM years WHERE id = ?
> HorarioController:907, :1113, :1851   la leen y la escriben
> routes/api/disciplina.php:44       ChangesAsked/to-me — SIN middleware extra
> ```
>
> `auth.token` a secas **son todos los roles**, alumnos y acudientes incluidos, y `to-me` es
> lo que pinta el muro de `myvc_flutter`. Sobre una copia sin la columna, `to-me` contesta
> **500 `Unknown column 'horario_version_id'`**. O sea que sin esta migración no es «quien
> suba un horario recibe un 500»: **es la pantalla de inicio de la app contestando 500 a
> todo el mundo.** El login vive y el colegio no se cae entero; el muro sí.
>
> **Y lo que hay que sacar de aquí no es el radio corregido, es que la corrección no bastó.**
> Esta fila **ya documenta su propio envejecimiento** —lleva escrito que decía «no rompe en
> ninguna dirección, porque su código son tres 501» y que dejó de ser cierto el mismo día con
> `371062c`—, y **ha vuelto a envejecer exactamente igual, por segunda vez, sin que la cifra
> ni el rango se movieran**. La regla que ese párrafo enuncia se le aplicó a sí misma y no
> alcanzó. *Por eso el radio se REMIDE el día del despliegue en vez de leerse: una fila que
> avisa de que caduca sigue caducando.*
>
> Medida por `8myvc-c3`, reproducida por `8myvc-7c` y comprobada aquí antes de escribirla.

> ### ⚠ Una afirmación de esta tabla NACIÓ MAL, y se corrigió el 4 sep 2026
>
> La fila de `tono` decía: *«Entra con `AFTER regla_nivelacion`, que llega en `2026_09_02_100000`
> de esta misma tanda»*. **Es falso, y no es una cifra que envejeciera: nunca fue cierto.**
> `2026_09_04_200000_tono_del_docente` **no tenía ni un `->after()`** —su cabecera lo declaraba
> como decisión: *«SIN `after`, y es a propósito … no se compra la dependencia»*— y además `tono`
> va sobre **`profesores`**, mientras que `regla_nivelacion` es una columna de **`years`**: ese
> `AFTER` no era ni siquiera expresable.
>
> ```bash
> grep -c 'after(' database/migrations/2026_09_04_200000_tono_del_docente.php   # → 0
> ```
>
> La que sí lleva `->after('regla_nivelacion')` es **`2026_09_04_100000_horario_versiones`**, con
> `years.horario_version_id`; y `23 §11.2`, que aquella fila citaba, **lo dice bien**. O sea que la
> fila citaba correctamente una sección que decía otra cosa.
>
> **Queda escrito para que nadie la «restaure» dentro de dos meses creyendo que se rompió aquí.**
> Y es de una especie que `23 §11.5` no tenía catalogada: las otras tres afirmaciones caducadas de
> este documento **describen el servidor y fallan contra él** el día del despliegue; ésta no falla
> contra nada — si despliegas en orden funciona, y si no, el error acusa al fichero equivocado.
> *La dependencia de orden es real y sigue viva: `09_02` antes que `09_04`. Lo que estaba mal era
> de quién.*

**Cuatro de las cinco son aditivas en `up()`** —`ADD COLUMN` y `CREATE TABLE`, sin un solo `UPDATE`
ni back-fill—, leídas una a una. **La que falta —`2026_08_31_100000`— no: hace `dropColumn`**, y
eso cambia dos reglas de este documento. (Va la última de esta tabla porque está ordenada por lo
que tumba, pero es la **primera** por orden de ejecución, y eso importa para el rollback.)

> **La del `dropColumn` no rompe hacia delante** —retira algo que el código nuevo ya no lee— pero
> sí hacia atrás, que es la fila de abajo.
>
> **Y aquí había escrito que la de `horario_versiones` «no rompe en ninguna dirección, porque su
> código son tres 501». Dejó de ser cierto el mismo día, y la forma en que envejeció es nueva.**
> Cuando se midió, los tres métodos eran `501` y ninguna consulta nombraba las tablas; `371062c`
> implementó `postVersiones` unas horas después y ahora hace `INSERT` en las tres. **La medición era
> correcta el día que se hizo: lo que cambió no es la cifra ni el rango, es el código que se midió.**
> Lo levantó `8myvc-23` el 2 sep 2026. Teníamos catalogado que el resumen de un rango envejece cuando
> se le añade un commit —y por eso se remide entero—; **ésta envejece sin que la cifra ni el rango se
> muevan**, y no la teníamos escrita. La regla que deja: *una afirmación sobre lo que el código hace
> caduca cuando el código cambia, aunque el número que la acompaña siga siendo el mismo.*
>
> **Aun así, las dos entran en el mismo `migrate` y en la comprobación**: la pregunta que contesta esa
> comprobación es *«¿está la tanda entera dentro?»*, no *«¿qué se rompe?»*. Un colegio con seis de
> siete es un colegio que nadie sabe en qué estado está.

#### La que retira una columna, y las dos reglas que cambia

`2026_08_31_100000` hace `dropColumn('boletin_independiente')` sobre `matriculas`. El código que
está **hoy desplegado** en los dieciséis (`9474b50`) nombra esa columna en **cinco consultas vivas** de
`app/Services/BoletinIndependiente.php` (líneas 73, 136, 202, 280 y 297). De ahí salen dos cosas:

1. **El orden `git pull` → `migrate` deja de ser una costumbre y pasa a ser obligatorio.** Migrar
   antes de traer el código deja al colegio con el código viejo y la columna ya retirada: los
   boletines caen igual, sólo que por el otro lado. **No hay orden sin ventana**; la hay en las dos
   direcciones y por eso los dos comandos van seguidos, en la misma vuelta del bucle y por colegio.
2. **Volver atrás dejando las migraciones puestas ya NO vale para esta tanda.** El «Paso 4» de este
   documento dice que las migraciones se quedan porque son aditivas y el código viejo las ignora:
   cierto para todas las tandas anteriores y **falso para ésta**. Ver el aviso del Paso 4.

**Y el estado peor no es «sin migrar»: es «migrado a medias».** Con `2026_08_31_100000` corrida y
las siguientes no, la columna vieja ya no está y las nuevas todavía no: **no funciona ni el código
viejo ni el nuevo**. No es un supuesto: **dos** bases de sesión aparecieron exactamente así la
noche del 2 sep 2026 —`migrations` parada en `2026_08_31_100000`, `matriculas.boletin_independiente`
ya retirada y `years.regla_nivelacion` sin llegar—, y ninguna de las dos servía para nada. **Cómo
llegaron a ese estado no se sabe**: reconstruirlas sale completo. Lo que importa aquí es que ese
estado **existe y no lo delata ningún error**. Por eso: **si el `migrate` de un colegio falla, ese
colegio se arregla antes de tocar el siguiente.**

#### El orden por colegio, y no hay otro

`git pull` → `php artisan migrate --force` → **la comprobación de abajo** → un boletín y un login
**antes de pasar al siguiente colegio**.

#### La comprobación de diez segundos, que ya no son siete migraciones sino treinta y cuatro

`php artisan migrate:status` **no basta**: dice que la migración corrió, no que la columna esté.
Esto pregunta por el esquema, que es lo que leen las consultas:

```bash
php artisan tinker --execute='$f=[]; foreach ([["asignaturas","porcentaje_area"],["calendario","descripcion"],["calendario","recordatorio_enviado_at"],["calendario","recordatorio_minutos"],["grupos","ih"],["importaciones","avisos"],["importaciones","filas_totales"],["importaciones","respuestas"],["notas","nivelacion_obs"],["notas","nivelada_at"],["notas","nivelada_por"],["notas","nota_nivelacion"],["notas","nota_original"],["notas_finales","nivelacion_obs"],["notas_finales","nivelada_at"],["notas_finales","nivelada_por"],["notas_finales","nota_nivelacion"],["notas_finales","nota_original"],["periodos","cierre_sin_calificar"],["profesores","tono"],["recuperacion_final","nivelada_at"],["recuperacion_final","nivelada_por"],["recuperacion_final","observacion"],["requisitos_alumno","cerrado_at"],["requisitos_alumno","cerrado_por"],["requisitos_alumno","motivo_devolucion"],["requisitos_matricula","bloquea"],["subunidades","rubrica_id"],["unidades_por_defecto","materia_id"],["unidades_por_defecto","nivel_educativo_id"],["years","cierre_sin_calificar"],["years","desempeno_displayname"],["years","desempenos_displayname"],["years","genero_desempeno"],["years","horario_version_id"],["years","modelo_evaluacion"],["years","mostrar_nota_numerica_boletin"],["years","puestos_con_bol_independiente"],["years","regla_nivelacion"],["years","reparto_subunidades"],["years","titulo_certificado_final"],["years","titulo_certificado_periodos"],["years","titulo_constancia_estudio"]] as $c) { if (!Schema::hasColumn($c[0],$c[1])) $f[]=$c[0].".".$c[1]; } foreach (["accesos_favoritos","aspirantes","calendario_destinatarios","citas_admision","colillas_inscripcion","config_formulario_inscripcion","config_pasarela","desempenos_por_defecto","documentos_admision","envios_estacion","horario_lecciones","horario_pieza_docente","horario_versiones","informes_recientes","jefes_de_area","notas_estacion","ordenes_inscripcion","pagos_inscripcion","rubrica_criterios","rubrica_descriptores","rubrica_niveles","rubrica_valoraciones","rubricas"] as $t) { if (!Schema::hasTable($t)) $f[]="tabla ".$t; } if (Schema::hasColumn("matriculas","boletin_independiente")) $f[]="matriculas.boletin_independiente SIGUE AHI"; if (Schema::hasColumn("areas","jefe_id")) $f[]="areas.jefe_id SIGUE AHI"; if (!DB::table("permissions")->where("name","can_edit_plantilla_notas")->exists()) $f[]="permiso can_edit_plantilla_notas"; echo ($f ? "FALTA -> ".implode(" | ",$f) : "OK - la tanda entera dentro").PHP_EOL;' 
```

> **Esto pregunta por COSAS, no por migraciones, y desde la consolidación no son lo mismo.** La
> tanda son **treinta y cuatro ficheros** y esta comprobación hace **sesenta y nueve preguntas**:
> **43 columnas**, **23 tablas**, **una fila de `permissions`** y **DOS columnas que tienen que
> haber DESAPARECIDO** — `matriculas.boletin_independiente` y **`areas.jefe_id`**, que se retira
> con `2026_09_17_200000_jefe_de_area_por_anio` y a la que nadie había puesto una línea aquí.
>
> **Y desde el 20 sep 2026 la lista no se escribe a mano: se genera.** Se montan dos bases —el
> esquema congelado con las migraciones que hay en `9474b50`, y el mismo con las 34— y se restan
> sus `information_schema`. Una lista escrita a mano se queda corta en silencio, que es lo que le
> pasó a `areas.jefe_id`; una restada no puede. **Con su control negativo**: contra la base sin la
> tanda imprime las 66 que faltan, y contra la migrada sólo el permiso —que en una base de TESTS
> siempre falta por lo que dice el aviso de abajo—.
>
> *Lo que decía antes de ese día, cuando la tanda eran siete ficheros: «veinte preguntas: diez
> columnas, ocho tablas, una fila de `permissions` y una columna que tiene que haber
> DESAPARECIDO».* Se toca **esto** en
> cuanto la tanda añade algo, y no
> se espera al día del despliegue como las tablas de arriba: aquéllas son lo que se midió el día
> que se midió; **este fragmento es una comprobación que alguien va a ejecutar**, y una que no
> pregunta por lo último que entró contesta que todo está bien en un colegio al que le falta algo.
>
> `profesores.tono` se le añadió el 4 sep 2026 (decisión de Joseth, `23-horarios.md` §9.bis.3) y
> **ya no vive en una migración propia**: la absorbió `2026_09_04_100000_horario_versiones`. Que su
> fichero desapareciera **no le quitó ni una columna a la tanda**, y es exactamente por eso que
> esta lista se escribe por columnas y no por migraciones.
>
> **Y desde el 5 sep comprueba también las dos de la plantilla**, con una fila por cada cosa que
> la tanda añade y no sólo por migración: las **dos columnas de `unidades_por_defecto`** y —lo que
> no es una columna— **la fila `can_edit_plantilla_notas` de `permissions`**. Esa última es la que
> ninguna comprobación de esquema habría cazado, y es justo la que falla en silencio: sin ella la
> pantalla de la plantilla funciona **sólo para superusuarios**, así que el síntoma no es un error
> sino que a rectoría «no le sale el menú». Es la misma regla que habría cazado el hueco de
> `profesores.tono`: **al menos una comprobación por cada tabla que la tanda toca.**
>
> > ⚠️ **Y esa línea del permiso dirá siempre `FALTA` si la corres contra una base de TESTS.**
> > No es un fallo de la comprobación ni de la migración: `database/dumps/test-seed.sql` hace
> > `TRUNCATE TABLE permissions` **después** de que corran las migraciones
> > (`tools/construir-bd-test.sh` las ejecuta antes del seed), así que la fila que siembra
> > `2026_09_05_300000` se la lleva el seed por delante. **Medido el 5 sep 2026** sobre
> > `simonbolivar_testing_p`: las dos columnas de `unidades_por_defecto` sí aparecen y el permiso
> > no, con 19 filas en `permissions`. En un colegio de verdad no hay nada que trunque, así que
> > ahí la línea es válida — **se corre contra la base del colegio, no contra una de pruebas**.
>
> **Y desde el 4 sep la etiqueta ya no lleva número: dice `OK - la tanda entera dentro`.**
> Contar migraciones ahí era contar la cosa equivocada —el fragmento pregunta por columnas y
> tablas, no por ficheros—, y la fusión de ese día lo dejó claro: **las mismas columnas pasaron
> de ocho ficheros a cinco sin que la comprobación cambiara una letra**. Una etiqueta con un
> número dentro es una que hay que acordarse de mover; ésta ya no.

**Dice qué falta, no sólo que falta**, y **tiene control negativo**: probado el 2 sep 2026 contra la
base migrada (`OK - la tanda entera dentro`) **y** contra una sin migrar y con nombres inventados, donde
imprime la lista. Un `OK` que no sabe fallar es el que archiva el asunto.

**La otra acción del día, que no es una tabla:** correr `tools/independientes-sin-estructura.php`
**en los dieciséis**, uno por uno, después de migrar. Contesta la §9.1 —qué alumnos están marcados y
**no tienen ni una unidad propia**, cuya definitiva sale 0 sin que nadie reciba un error—, y **sin
la tabla contesta `exit=2 · NO CONCLUYENTE` a propósito**: un `0` limpio sería la respuesta que
archiva el asunto justo en el colegio donde no se ha mirado nada. Lo medido hasta hoy es **cero
marcados en desarrollo**, que **no** es «cero en los dieciséis»: eso sólo se sabe allí.

#### Los avisos para el front que viajan en esta tanda

> **Repasados los nueve (K–S) el 5 sep 2026, y no se repasaron a ojo.** La pregunta no es
> «¿siguen siendo ciertos?» sino la de la regla de arriba —*una afirmación caduca cuando el código
> cambia aunque su número no se mueva*—, así que **se midió la población entera** en vez de releer
> las filas: se restaron las claves, una a una y hasta el fondo del árbol, de los **125 snapshots
> de `tests/Contrato/Snapshots/` entre `9474b50` y `main`**.
>
> **Cambian 29.** Descontando los **3** que no son respuestas (`rutas.json`, `guard-por-familia.json`
> y `familias-que-nunca-entran-en-el-candado.json`) quedan **26 respuestas que ya existían y cambian
> de forma**, más **4 snapshots nuevos**: tres son el mismo endpoint nuevo
> (`grupos/{grupo_id}/alumnos-de/{que}`, uno por valor de `{que}`) y el cuarto,
> `editnota-alum-asignatura`, es de **una ruta que ya existía** y a la que sólo ahora se le puso test.
>
> **Lo único bueno de la lista:** de esas 26, **la única que PIERDE claves es `ChangesAsked/to-me`**
> (las ocho del aviso K). **Las otras 25 sólo ganan.**
>
> **Y así salieron los tres que faltaban.** Q, R y S no son un olvido de quien escribió esta tabla:
> son **campos que se reparten solos**, por un `SELECT *`, por un modelo Eloquent devuelto entero o
> por una clave nueva de primer nivel. *Los avisos que faltan no los escribe quien escribió el
> campo, porque nadie escribió el campo.*

| | aviso | estado |
|---|---|---|
| **K** | **⟲ Corregido el 6 sep 2026: son SIETE columnas, no ocho — Joseth devolvió `created_by_nombres`** (*«no me importa que llegue»*), así que el evento pasa de 17 claves a **10** y **el «Por: undefined» ya no ocurre**. Lo de abajo es lo que decía hasta ese día. `GET ChangesAsked/to-me` deja de mandar **ocho** columnas de cada evento del calendario y conserva nueve. **Aquí decía «nueve» y era la cifra de las que se QUEDAN**, contada como si fueran las que se van; medido sobre el snapshot el 2 sep 2026, el evento pasa de 17 claves a 9. Las ocho que se van son `created_at`, `created_by`, `created_by_nombres`, `deleted_at`, `deleted_by`, `type`, `updated_at` y `updated_by`. Una de ellas, **`created_by_nombres`, la pinta la aplicación vieja** en el tooltip del evento (`AnunciosCtrl.ts:596`): al desplegar dirá **«Por: undefined»** hasta que se arregle allí, que es una línea | **AVISADO Y ARREGLADO EN EL FRONT el 6 sep 2026** (`myvc_front@9419ccc3`, condicional en vez de borrado). ⚠️ **Y trae una trampa de despliegue que no era nuestra**: ese arreglo viaja por `myvc_dist` a **`up/`**, no por `myvc_dist2` a `up2/`. **Si ese día sólo se sube `up2/`, el «Por: undefined» se queda.** Hay que subir las dos |
| **L** | **`POST tardanzas/login/traer-datos` desaparece**: pasa a 404. Decisión de Joseth del 2 sep. El único llamante de toda la máquina es `tardanzasMyvc-old` (último commit feb 2020), y Joseth confirmó que ese repositorio está inactivo — el dato que lo cerró **no estaba en el repositorio** | **DECIDIDO** — es la única ruta que la tanda quita |
| **M** | **`regla_nivelacion` aparece en el bloque de la sesión**, en las cuatro ramas (alumno, acudiente, profesor y usuario). Es un campo **nuevo**, para previsualizar en el diálogo de nivelación qué nota va a quedar (22 §1.4 y §5.1) | **ADITIVO** — Flutter no se rompe: `ConfiguracionColegio.deLogin` lee campo a campo y no hay `json_serializable` ni `freezed`. Medido, no supuesto (22 §3.2bis) |
| **N** | **Campos nuevos de nivelación en respuestas que ya existían.** Aquí decía «la planilla (`PUT notas/detailed`), los boletines, el boletín final y `PUT editnota/alum-asignatura`»: **son cuatro nombres para DIEZ respuestas, y faltaban dos sitios** — `PUT notas-actuales-alumnos/{grupo_id}` y **`GET notas/alumno/…`, la que llama un ALUMNO para ver sus propias notas** (gana `nota_original_asignatura` y `nivelada_at_asignatura`). Y «los boletines» son **`boletines` y `boletines2`, cuatro respuestas: `boletines3` no gana ni una clave**, que es justo lo que aquí no se veía. Qué respuesta abre las columnas **a propósito** y cuál las tiene **congeladas** está decidido sitio por sitio en la tabla de [22 §3.4](migracion/22-nivelaciones.md) y en el [27](migracion/27-nivelaciones-en-los-informes.md) | **ADITIVO** — ningún cliente pierde una clave. La enumeración, **corregida el 5 sep 2026** restando los snapshots: 10 respuestas, no 4, y una de ellas es de un alumno sobre sí mismo |
| **O** | **36 rutas nuevas**: las 10 de `rubricas/`, las **9 de `plantilla-notas/`**, las 5 de `boletin-independiente/`, las **6** de `horario/` —**las seis con cuerpo: ninguna contesta ya 501**—, las 4 de nivelar, `GET grupos/{grupo_id}/alumnos-de/{que}` y `GET colegio/logo`. **Todas menos `colegio/logo` llevan `auth.personal`**: un alumno o un acudiente que las llame recibe **403**, y las de nivelar además exigen `periodos.profes_pueden_nivelar` al profesor (`User.php:425`). **Y TRES de `horario/` no bastan con `auth.personal`**, que es de lo que va este aviso: `PUT horario/versiones/{id}/oficial`, `PUT horario/docentes/{profesor_id}/tono` y **`GET horario/versiones/{id}/proyecto`** exigen además `puedePublicarHorario` **dentro** —superusuario o `Coord académico`, un rol con **cero usuarios**—, así que a un docente llano le contestan 403 aunque pase el guard | **POR AVISAR** — es de las de «quién puede llamarla». **Aquí decía 26 y luego 35; son 36**: las nueve de `plantilla-notas/` entraron el 5 sep por la mañana y **la sexta de `horario/` esa misma tarde**. *Tercera vez que esta fila se queda corta en el RECUENTO, y las tres por lo mismo: se escribe una lista y sigue entrando código.* **Y la corrección de las 36 se quedó corta a su vez en el CRITERIO**: dijo «dos de `horario/`» cuando son **tres** —se le pasó `putTonoDocente`, que ya estaba nombrada veinte líneas más abajo con ese mismo criterio—. Recontado sobre `HorarioController` el 5 sep 2026: tres llamadas a `puedePublicarHorario`, ni una más. *Contar rutas y contar quién puede llamarlas son dos censos distintos, y arreglar el primero no arregla el segundo.* |
| **P** | **`profesores.tono` es un campo NUEVO en TRECE respuestas vivas**, y sólo dos son del horario. Once por Eloquent —las cinco de `profesores/` (`store`, `update`, `destroy`, `forcedelete`, `restore`), las **tres de `perfiles/`** (`show/{id}`, `update/{id}`, `cambiarimgunprofe/{id}`), las **dos de `images-users/`** (`cambiar-foto-un-usuario/{id}`, `cambiar-firma-un-profe/{id}`) y el `titular` de `GET grupos/show/{id}`— y **dos por `SELECT p.*`**: `PUT profesores/listado` y `PUT participantes/profesores`, ésta de **votaciones**. Vale `null` en los diecisiete hasta que alguien reparta colores | **ADITIVO** — ningún cliente pierde una clave. Va dicho porque **un campo nuevo se manda dicho, no descubierto**, y porque **cinco de las trece son pantallas de perfil e imágenes que no tienen nada que ver con el horario**. *Medido por dos sesiones que no se copiaron: 7 + 5 + 1 = 13.* **Y es de RESPUESTAS, no de pantallas**: `myvc_front` midió que dos de esas trece no las llama —`perfiles/show/{id}` y `perfiles/cambiarimgunprofe/{id}`— y `myvc_flutter` y `myvc_front_2` **no están medidos** |
| **Q** | **`GET ChangesAsked/to-me` gana una clave de primer nivel: `horario_version_id`** (`null` mientras el año no tenga horario publicado, que es hoy en los diecisiete). **Es la misma respuesta del aviso K y va aparte a propósito**: K cuenta lo que se VA y esto es lo que LLEGA. La respuesta la piden **todos los roles** —lleva `auth.token` y nada más— y es lo que la app llama al abrir (`MuroApi.traerMuro`) | **POR AVISAR** — campo nuevo. **Faltaba en esta tabla**: se detectó restando los snapshots, no leyendo el commit |
| **R** | **Tres respuestas de años reparten las tres columnas nuevas de `years` sin que nadie lo pidiera**: `GET years`, `GET years/colegio` y `GET years/trashed` ganan `regla_nivelacion`, `puestos_con_bol_independiente` y `horario_version_id`. **No lo hace un cambio: lo hace el `SELECT y.*` de `YearsController`**, que estaba ahí desde antes. Las dos primeras llevan sólo `auth.token`, o sea que le llegan también a un alumno y a un acudiente | **POR AVISAR** — campo nuevo, y de los que **no aparecen en ningún diff de la respuesta**. **Faltaba en esta tabla** |
| **S** | **Los campos del boletín independiente también se reparten solos, y no tenían aviso**: `bol_independiente` en los boletines y `bol_independiente_aparte_en` en el acta de promoción; `bol_independiente_periodo` en los dos informes de notas perdidas y en `PUT puestos/detailed-notas-year` (que además gana `puestos_con_bol_independiente`); y `bol_independiente_datos` más `independientes` en la planilla. **ONCE respuestas** — contadas restando snapshots, sin contar las tres de `years`, que van en **R** | **CERRADO el 6 sep 2026: SÍ estaba avisado**, y en cinco documentos del front —`PANTALLAS-HISTORIAL-Y-BOLETIN.md` (§B, con la tabla de qué pantalla necesita qué campo), `TAREAS-NIVELACIONES-Y-RUBRICAS.md:282`, `TAREAS-PLANTILLA-Y-COMPETENCIAS.md` (§4.bis), `COORDINACION-NOCHE.md` y `MIGRATION.md`—, confirmado por `myvc-front-05` y `myvc-front-21` por separado. *Lo que no se podía comprobar desde este repositorio lo contestó preguntando.* — texto original: es posible que viajara en el documento del front (`DESPLIEGUE-NIVELACIONES-Y-RUBRICAS.md`), que **no se puede comprobar desde este repositorio**. Lo que sí se comprobó es que **en esta tabla no estaba** |

> **La fila O decía «24» y «las 3 de `horario/`», y se ha corregido a mano el 4 sep 2026
> — que es una excepción a la regla de este documento y por eso va escrito.** Aquí las
> tablas son *lo que se midió el día que se midió* y se remiden el día del siguiente
> despliegue; **ésta no podía esperar**, y la diferencia es que **las demás filas describen
> el servidor y ésta es un mensaje que sale hacia fuera**. Está marcada **POR AVISAR**: el
> front construye su menú con lo que le dijeron, así que un aviso que nombra tres rutas
> cuando hay cuatro **deja la cuarta sin avisar sin que se note el hueco** — no hay error
> que lo delate, sólo un menú al que le falta una entrada. Y no se arregla sola al remedir:
> remedir contesta *«¿siguen contestando 501?»*, **nadie recuenta un «24» si no sabe que hay
> que hacerlo**. Lo decidió Joseth ese día.
>
> **Las 36 están CONTADAS contra la base desplegada, no sumadas** — que es lo que este repo
> exige de un número que se escribe: `9474b50` declara **543** rutas y `main` (`d606839`)
> **578**; comparados los dos conjuntos de URIs, **36 entran y 1 se va** (`POST
> tardanzas/login/traer-datos`, el aviso L), y **543 + 36 − 1 = 578**.
>
> **Las TRES de `horario/` que no bastan con `auth.personal`** —el detalle que hace que este
> aviso sea de «quién puede llamarla» y no de «qué rutas hay»— son
> `PUT horario/versiones/{id}/oficial`, `PUT horario/docentes/{profesor_id}/tono` y
> `GET horario/versiones/{id}/proyecto`. Las tres llaman a
> `Autoriza::exigir(Autoriza::puedePublicarHorario(...))` **dentro del método**
> (`HorarioController` :2614, :2649 y :3050), o sea **superusuario o `Coord académico`**, un rol
> que tiene **cero usuarios**. **A un docente llano le contestan 403 aunque pase el guard.**
>
> **Y las nueve de `plantilla-notas/` tienen el suyo, que es el que hay que avisar:** las
> nueve llevan `auth.token` + `auth.personal`, y **las nueve —la de leer incluida—** exigen
> además `Autoriza::puedeEditarPlantillaNotas()`, o sea superusuario **o** el permiso
> `can_edit_plantilla_notas`. **Ese permiso nace repartido a nadie a propósito** (lo explica
> su migración), así que **el día del despliegue la pantalla entera es de los superusuarios y
> de nadie más en los diecisiete** — incluido el `GET`. No es un error ni un 500: a rectoría
> «no le sale el menú» hasta que el colegio le dé el permiso desde su pantalla de roles.
>
> *Recontado entero el 5 sep 2026 al fusionar la plantilla. **Se volvió a contar, no se le
> sumaron nueve a 26** — que es la única forma de que el 577 signifique algo. Y de paso salió
> que el párrafo anterior **no cuadraba consigo mismo**: decía `HEAD` **567** y a la vez
> `543 + 26 − 1 = 568`. Las dos no podían ser ciertas, y **no hay test que mire la aritmética
> de una frase**.*
>
> *Y se volvió a contar **esa misma tarde**, porque entró la sexta de `horario/`: **578**, y
> `543 + 36 − 1 = 578` vuelve a cuadrar. **El 577 de arriba no se corrige: era cierto a
> mediodía** y describe cómo se contó aquella vez. Lo que caduca es el número vivo, y ése
> está en la tabla del rango y en el aviso **O**. La lección de la mañana, cobrada dos veces
> el mismo día: **una cifra se anota con el momento en que se contó, o se lee como una
> contradicción.**
>
> **Lo que NO se ha tocado y sigue esperando al día del despliegue** es la fila de
> `2026_09_04_100000_horario_versiones` de la tabla de migraciones, que dice que sólo
> `POST horario/versiones` pasa de 501 a 500: ésa describe el servidor, falla contra él, y
> su caducidad está escrita en [23 §11.5](migracion/23-horarios.md).

**Nivelar son rutas NUEVAS por diseño, y esto es lo que hay que decirle al front:** `notas/update` y
`notas/lote` **no pueden** aprender a nivelar. `myvc_flutter` es una sola app para los dieciséis y una
versión vieja convive meses, así que un 95 tecleado desde el móvil se guardaría **topado** sin que
nadie lo pidiera. El porqué, en [22-nivelaciones.md](migracion/22-nivelaciones.md).

**Y el panel adelgaza a la mitad en la misma tanda**, que es lo que hay que mirar después de
desplegar: 274→157 KB el superusuario, 279→162 el docente, 225→112 el alumno, 218→108 el
acudiente, y **el panel del alumno pasa de ~620 ms a ~24 ms**.

---


## Lo que trajo la tanda del 25–30 ago 2026 — desplegada el 31 ago en `9474b50`

**Desplegada el 31 ago 2026**: los quince del bucle de `micolev1` **y** la cuenta de
`lalvirtual.edu.co`, con el front de la misma vuelta. El estado de los avisos y el procedimiento
están en [DESPLIEGUE.md](DESPLIEGUE.md); aquí está el porqué de cada fila.

| | | recalcular con |
|---|---|---|
| Migraciones | **DOS**, y ninguna es opcional | `git diff --name-only eb95cbc 9474b50 -- database/migrations/` |
| Rutas | **543** — una nueva, `PUT users/mi-docente` (28 ago) | `tests/Contrato/Snapshots/rutas.json` |
| Dependencias | sin tocar | `git diff --name-only eb95cbc 9474b50 -- composer.json composer.lock` |
| `config/` | sin tocar | `git diff --name-only eb95cbc 9474b50 -- config/` |
| `app/` | **treinta y ocho ficheros** | `git diff --name-only eb95cbc 9474b50 -- app/` |

> **Esa última fila decía ocho, se corrigió a once, luego a veintiocho, y el día del despliegue
> eran treinta y ocho.** Las dos primeras correcciones se hicieron **a mano** —quien las escribió
> añadió los que había visto, no los que da el comando— y por eso la de once se quedó a quince
> ficheros del número real. **Un número recalculado a ojo no es un número recalculado.** Por eso
> la columna derecha lleva **el comando** y no una lista escrita a mano.
>
> **La fila de migraciones envejeció de otra forma, y es la que hay que saber distinguir: decía
> UNA y no estaba mal cuando se escribió.** La tanda **creció** —entró
> `2026_08_30_200000_notas_finales_en_decimal` cuatro días después—, así que no era una cifra
> vieja: era una cifra medida sobre una tanda que ya no era la misma. Ésa es exactamente la
> diferencia entre *remedir* y *sumar*, y la que hace que el recálculo vaya **el día del
> despliegue** y no el día en que se escribe la tabla.
>
> **El despliegue no cambia por esto**: el bucle hace `git pull` del árbol entero, no fichero a
> fichero. Lo que cambia es lo que se puede afirmar al revisar un colegio a mano.

### La segunda migración: la definitiva deja de ser un entero

`2026_08_30_200000_notas_finales_en_decimal` pasa `notas_finales.nota` a **`DECIMAL(7,4)`** y el
cálculo deja de redondear. **Es bloqueante en el mismo sentido que la otra**: el código de la
tanda lee la columna esperando la escala nueva, y **sin la migración las definitivas se siguen
guardando redondeadas** aunque el código ya no redondee — o sea, el arreglo no llega.

**Lo que se le nota al colegio, y es el arreglo y no un efecto secundario:** de las 125.352
definitivas medidas, **96.608 (77,1 %) se guardaban redondeadas**, y de ahí salían los empates de
puesto. **El primer boletín después del despliegue trae puestos distintos sin que nadie haya
tocado una nota.** Ningún cliente pierde una clave: los siete campos afectados siguen viajando
como número, y eso costó castear ~40 lecturas — sin los `CAST`, PDO devuelve `DECIMAL` como
**cadena** y 17 respuestas habrían pasado de `45` a `"43.7500"`, que es un cambio de **tipo**.

### El `SELECT` que fue delante del bucle, el 31 ago (aviso H)

`GET profesores` pasó a exigir `Autoriza::esAdministrativo` —`is_superuser || Role::isSecretario`—
y **el rol `Admin` NO está dentro**, mientras `app2` sí le abre la pantalla de Docentes a un
`Admin`. En la base medida los diez `Admin` eran exactamente los diez `is_superuser`, **pero eso
es una coincidencia, no un criterio**: bastaba un colegio que le hubiera puesto `Admin` a alguien
sin superusuario para que ese alguien perdiera la pantalla el día del despliegue.

**Y eso no se puede medir desde el repositorio: cada colegio tiene su propia base.** Por eso el
`SELECT` fue colegio a colegio **antes** del bucle, exigiendo cero en los quince:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-52s ' "$d"
  (cd "$d" && php artisan tinker --execute="echo DB::table('role_user')
    ->join('users','users.id','=','role_user.user_id')
    ->join('roles','roles.id','=','role_user.role_id')
    ->where('roles.name','Admin')->where('users.is_superuser',0)
    ->whereNull('users.deleted_at')->count();")
  echo
done            # repetir en la otra cuenta de cPanel (lalvirtual.edu.co)
```

**Por qué se comprobó y no se ensanchó `esAdministrativo` de una vez, que es lo reutilizable:** ese
método lo leen seis sitios más —las masivas de `cambiar-usuarios/*` entre ellas—, así que meterle
el rol `Admin` habría repartido permisos que nadie pidió en cinco puertas que no eran ésta. Es
literalmente lo que `create_rol_secretario` dejó escrito: **crear o ensanchar un criterio no puede
regalar permisos por la puerta de atrás.**

### La migración de los certificados es bloqueante, como la del 25

`2026_08_26_100000_interruptores_de_certificados` añade `usa_consecutivo_certificados` y
`usa_folio_certificados` a `years`, y **el código de esta misma tanda las consulta en un camino
vivo** —`Year::datos()`, que es de donde sale cualquier boletín y cualquier certificado—. **Con
el código y sin la migración: 500 en todo.** Por eso el `migrate --force` va **entre el `pull` y
el `config:cache`**.

**Y lo que hace que sea segura: no siembra ningún valor por defecto.** Deriva los dos
interruptores de lo que cada colegio hace hoy —`contador <> ''`, que es la condición que el front
ya usaba para ocultar cada casilla—, así que **ningún colegio imprime nada distinto el día del
despliegue**.

> **El `migrate --force` dejó de ser higiene el 25 ago y no vuelve a serlo.** `2026_08_24_100000`
> creó `bol_ind_periodos` y el código de la misma tanda la consulta en `Unidad:112`: con el
> código y sin la migración, **500 en todos los boletines**.

### Qué se le nota a un colegio

| | |
|---|---|
| **El boletín final va de 3.820 consultas a 455** (GEMELO-1). Es la queja de los 24–63 s y las caídas bajo carga | `docs/migracion/noche-2026-08-25/gemelo-1.md` |
| **La ficha del alumno crea las notas que faltan** — 240 huecos medidos | `05 §234` |
| **Fijar el consecutivo de certificados pasa a ser de secretaría**, y contesta **403** al resto del personal | [`cert-2`](migracion/noche-2026-08-26/cert-2.md) |
| **Mover el consecutivo deja rastro en `auditoria`**, tanto al quemarlo abriendo el certificado como al fijarlo a mano | [`cert-2 §3`](migracion/noche-2026-08-26/cert-2.md) |
| **El consecutivo y el folio pasan a ser OPCIONALES por colegio.** Nada cambia de aspecto: cada colegio arranca como está hoy. Lo que cambia es que **el que no imprime el número deja de gastarlo** — hasta ahora su contador subía solo en cada apertura | [`21 §4`](migracion/21-certificados-y-folios.md) |
| **La prematrícula pública deja de escribir la ficha de un menor a medias.** Con un grupo que falta o que no existe contestaba **500 con la ficha ya escrita** —sin matrícula y sin cuenta—, y **el reintento daba un 200 mintiendo**: *«Ya existe el alumno, entre con su cuenta»* por una cuenta que nunca se creó. Ahora es **422 antes de escribir nada**, y las cuatro escrituras van en transacción. *Los huérfanos ya escritos **no los toca**: eso es del colegio* | `05 §236` |
| **Una falta anotada sin fecha deja de quedarse sin día.** Contaba en los totales del boletín y no salía en ningún listado por día —el calendario de la app la descarta—. Sólo las nuevas: **las 5.071 que ya hay en la copia de un colegio no las toca** | `05 §242` |
| **La cuenta administrativa puede decir qué docente mira, y queda guardado en su fila.** `users.profesor_id` existía y **no la escribía nadie** —las dieciséis cuentas de tipo `Usuario` la tienen en `NULL`—; ahora `PUT users/mi-docente` la rellena. **Efecto secundario querido, y hay que saberlo: el panel VIEJO le empezará a pintar a esa cuenta el horario de hoy y el de mañana de ese docente**, porque `ChangeAskedController::getToMe` ya leía esa columna | `UsersController::putMiDocente` |
| **El folio deja de fabricarse.** Ya no se escribe `año-alumno_id` al matricular, y `GET folios/iniciar` —que llenaba todos los huecos del año de una sentencia, y **no lo llama ningún cliente**— contesta 409. Los folios ya escritos **se quedan**: borrarlos cambia lo impreso y es decisión aparte | [`21 §4.3`](migracion/21-certificados-y-folios.md) |

### Los avisos al front, en detalle — **los diez cerrados el 31 ago 2026**

> **Cerrados al desplegar.** A, B y C salieron en el front de la misma vuelta; D y F no
> requerían trabajo (medido); E lo habían pedido ellos; G, H e I se avisaron. **Lo único que
> sigue vivo es el paso 3 del aviso J —`myvc_flutter`—, que el despliegue no cierra: lo
> DESBLOQUEA.** El estado al día está en el paso 3 de [DESPLIEGUE.md](DESPLIEGUE.md); lo que
> sigue es el porqué de cada uno, que no caduca.

> **Cada aviso lleva un estado, y no está en futuro por una razón que costó un día entero.**
> El bloque equivalente de la tanda anterior decía *«`myvc_flutter` tiene tres interruptores
> esperando el despliegue»* **y seguía diciéndolo después de desplegar**: Flutter acabó pidiendo
> que se fusionara y desplegara una rama que no existía, y que se escribiera un endpoint que
> llevaba tres días en los quince. **Un pendiente escrito en futuro no envejece a «hecho»:
> envejece a mentira.** Lo midió `8myvc-43` el 26 ago (`f5f6235`).

**A y B son de las de «quién puede llamarla».** El detalle en
[`cert-2 §6`](migracion/noche-2026-08-26/cert-2.md) y el reparto completo de qué hace el backend
y qué les toca a ellos en `myvc_front/TAREAS-AUDITORIA-CERTIFICADOS.md`.

**A.** `PUT bolfinales/cambiar-contador-certificados` y `-folios` **contestan 403 a quien no sea
administrativo**. Las dos pantallas que llaman a la primera —`certificadoEstudioDir.html` de la
vieja y `certificados-estudio.ts` de `app2`— **enseñan el control sin mirar el rol**, así que un
docente verá «Contador no guardado». Lo que toca allí es **esconder el control**, no cambiar la
llamada. *(`-folios` no lo llama nadie vivo.)*

**B.** Hay **dos interruptores nuevos que configurar**, `usa_consecutivo_certificados` y
`usa_folio_certificados`, y **cambian la forma de veintiuna respuestas** —las instantáneas de
contrato lo fijan—. **El cambio es aditivo: no quita ni renombra ningún campo**, así que ningún
cliente se rompe por recibirlos; pero conviene saber dónde caen:

| endpoints | qué significa para el front |
|---|---|
| `GET years` · `years/colegio` · `years/trashed` | **aquí les vienen bien**: es de donde la pantalla de configuración los va a leer |
| los 13 de `boletines/*` y `bolfinales/*` | el objeto `year` viaja dentro del boletín y del certificado; es donde tienen que mirar el interruptor para esconder las casillas |
| `informes/datos` · `piars-config` · `grupos-con-disciplina` · `notas/actuales-alumnos` | por arrastre, no les toca nada |

El front tiene que (a) ocultar las dos casillas por el interruptor en vez de por «la columna está
vacía», y (b) ofrecerlos en la pantalla de configuración de certificados, que es donde el colegio
los va a buscar. Hasta que lo haga **no se rompe nada**: la derivación deja a cada colegio como
estaba.

**C.** `aumentar_contador` hay que **OMITIRLO**, no mandar `false`. El backend ya no quema con la
cadena `"false"` desde el 25, pero **las copias de `myvc_front` de los quince colegios van a
versiones distintas** y esa medición no las ve.

**D.** `PUT login/crear-prematricula` **contesta 422** —y no 500— cuando el `grupo_id` no existe,
no viene o no es un id, **con el texto en `message`**. **Al front no le toca nada, y eso está
comprobado y no supuesto**: `mensajeError.ts` lleva `422` en su lista `CON_MENSAJE`, así que
`LoginCtrl:217` ya pinta el texto del servidor en el toast *«No se pudo prematricular»*. Lo que
cambia es **a mejor**: con el 500 salía el texto genérico, porque 500 **no** está en esa lista.
*(Y de paso: un grupo **en la papelera** también da 422 ahora. Antes pasaba y dejaba la
prematrícula colgada de un grupo borrado.)*

> **Y de camino salieron dos cosas del front que NO son de esta tanda: ARREGLADAS**, las dos en
> `myvc_front`, commit `8321f9a5` — **commiteado en su `main`, sin subir y sin publicar**.
> **(a)** El desplegable de grupo lleva `allow-clear="true"` (`login.html`) y el controlador hacía
> `year.grupo_prematr.id` **sin comprobar nada**: limpiar el grupo y pulsar «Prematricular» era un
> `TypeError` dentro del `ng-click` — **botón mudo**, el mismo fallo que ese fichero ya había
> arreglado para `$ctrl.year` doce líneas más arriba, en el campo de al lado. **(b)**
> `$ctrl.guardando` se ponía a `true` al enviar y **no lo leía nadie**: era el `ng-disabled` que
> falta, a medio poner. Ahora lo lee el botón y se repone en las cuatro salidas. Siete pruebas
> nuevas (`test/login/prematriculaDelLogin.test.js`) y **los dos controles vistos rojos**: cada
> arreglo tiene sus dos, y ninguno tapa al otro.
>
> **Ojo al orden, que aquí sí importa:** esto es del front y **su despliegue es otro bucle**
> —`up/`, un `git pull` de `myvc_dist`—. El 422 del backend y estos dos arreglos **no tienen que
> salir juntos**: son independientes en las dos direcciones.

**E.** `GET notificaciones/temas` devolvía `"colegio": ["colegio_muro", "colegio_avisos"]` y ahora
devuelve `"colegio": {"colegio_muro": "c_1a2b…", "colegio_avisos": "c_3c4d…"}` — **de lista a
objeto, y con el tema ya compuesto**. Es de `myvc_flutter` y **no lo toca ningún front web**. **No
rompe a nadie hoy**: la app no está publicada y ellos dijeron por escrito que no suscriben esos
dos temas hasta que llevaran prefijo. El porqué, en
[05 §238](migracion/05-codigo-muerto-y-roto.md).

**F.** `POST ausencias/store` **rellena `fecha_hora` cuando no se manda**, y la contesta **en
ISO**. No requiere trabajo del front: `app2` ya lee los dos formatos, y con su prueba.

**G.** **`PUT users/mi-docente` es una ruta NUEVA** —la primera desde las tres de `myvc_flutter`
del 24— y escribe `users.profesor_id` de la cuenta que la llama. **Sólo cuentas de tipo
`Usuario`** (un profesor recibe 403: su identidad sale de `profesores.id`, no de esa columna) y
**sólo un profesor contratado en el año en curso** (si no, 422 — la columna no tiene clave
foránea, así que la comprobación tiene que estar aquí).

> **El orden aquí SÍ importa, y al revés que el del 422 de arriba:** `app2` **ya la llama**, desde
> el botón de docente de la portada del panel. En un colegio con el front nuevo y sin este
> despliegue, elegir docente **funciona en pantalla** —se ven sus asignaturas— y sale un aviso de
> que no quedó guardado, porque la ruta contesta 404. No se rompe nada más; lo que no dura es la
> elección. **Este backend va antes que ese front, o el aviso lo ve el administrador el primer
> día.**

### Los dos que `myvc_flutter` pidió por su nombre

Los dos son **acciones nuestras con fecha en el futuro**, y una promesa que sólo vive en un
mensaje entre sesiones es una promesa que se cae en cuanto la sesión se cierra. Por eso están
escritas aquí y en el paso 3 del procedimiento.

| cuándo | qué hay que hacer |
|---|---|
| **YA** — `b369020` entró en la tanda desplegada el 31 ago, comprobado con `git merge-base --is-ancestor b369020 9474b50` | **decirles el hash desplegado: `9474b50`.** Su `temasDelColegio` está detrás de un interruptor apagado esperando exactamente eso: los temas del colegio pasan de literal a `c_`+HMAC, y hasta que el backend esté en los quince suscribirse sería apuntarse al tema viejo. Ellos leen las dos formas, así que **no hay ventana rota**: sólo hay un interruptor que encender |
| el día que se corra el **`for` de la fase 0** | **pasarles el desglose por año del bloque 5** (notas fuera de escala). No tienen que hacer nada con él: es el dato que decide si aquello fue *«una precaución razonable»* o *«un susto»*, y la pregunta la abrieron ellos. Ver [05 §240](migracion/05-codigo-muerto-y-roto.md) |

> **Por qué cerrar los avisos es un paso del procedimiento y no una buena costumbre:** ya falló
> una vez, y el coste no fue la línea desactualizada. Fue que la sesión de `myvc_flutter` planificó
> una vuelta entera —fusionar una rama, desplegarla, escribir un endpoint— **sobre trabajo que
> llevaba tres días en producción**. El documento no estaba viejo: estaba diciendo algo falso con
> la cara de un pendiente.

### Y la tabla de arriba se remide, no se suma

**Se recalcula cuando la tanda crece.** La del 25 decía «ninguna migración» con cuatro dentro,
porque se había medido antes de fundir cuatro ramas.

## Lo que trajo la tanda del 22–25 ago 2026 — desplegada el 25 ago en `eb95cbc`

**Comprobada por Joseth con el mismo hash en los quince.** Esa cifra se escribió primero como
«dieciséis» y era falsa: uno de ellos no tenía repositorio git ni aplicación, así que **nunca
pudo devolver un hash** y no estuvo en ninguna tanda. Ese colegio se dio de baja y se borró del
servidor ese mismo día, y por eso desde el 25 ago son quince. Es la primera tanda desde
la Fase 4 que lleva migraciones a producción: las cuatro son aditivas y **se quedan
puestas al volver atrás**.

| | |
|---|---|
| Migraciones | `bol_ind_periodos` + `unidades.alumno_id` + `matriculas.boletin_independiente`; `auditoria`; `personal_access_tokens.historial_id`; el permiso `can_view_auditoria` |
| Rutas | 539 → 542: `PUT notas/lote`, `GET disciplina/mis-fichas/{alumno_id?}`, `GET notificaciones/temas` — las tres para `myvc_flutter`, ninguna quita nada |
| `config/` | `config/notificaciones.php`, y `notificaciones:enviar` entra en el scheduler cada quince minutos (no hay cron nuevo: viaja en el `schedule:run` de cada minuto) |
| Lo que se nota | [`que-se-nota-en-un-colegio.md`](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md) |

### Lo que este despliegue ABRIÓ, y sigue abierto

**`c47ab50` recortó `Profesor::contratos()` y con eso vació cuatro columnas de la rejilla
«Docentes contratados»** —la de abajo de `/panel/profesores` en la web vieja—: Usuario
(`username`), Nacimiento (`fecha_nac`), Email (`email_usu`) y Celular (`celular`),
declaradas en `ProfesoresCtrl.ts:266-269` y alimentadas por `ContratosApi.listar()`.

**El recorte está bien hecho y no se deshace:** `GET contratos` es la única ruta de su
controlador **sin `auth.personal`**, así que entregaba el documento, el domicilio y el
móvil de los docentes a cualquier sesión válida —la de un alumno incluida—.

Lo que falló fue el censo de consumidores del propio commit, que dice «once consumidores y
ninguno toca lo que se quita»: **acertó con Flutter y se dejó esta rejilla.** Lo midió la
coordinación del front (`myvc_front/RELEVO-DEUDAS.md §1`) contra el docker.

> **Y una que salió bien por haber ido en la misma tanda, no por diseño.** Esa rejilla
> guarda **la fila entera** al editar cualquier celda
> (`ProfesoresApi.actualizar(profesor_id, rowEntity)`), y la fila ya no trae esos cuatro
> campos. Con el código del 21 ago eso los habría **borrado en la base** — y `users.username`
> es UNIQUE, o sea dejar a un docente fuera del sistema. No ocurre porque
> `ProfesoresController::putUpdate` guarda las diecisiete columnas de la ficha y las cinco de
> la cuenta detrás de `$vinieron->trae(...)`: **lo que el cuerpo no trae no se toca**. Ese
> arreglo iba en esta misma tanda. **Separar los dos commits en dos despliegues habría
> borrado datos**, y eso es un argumento de orden que nadie planteó.

**Decisión pendiente de Joseth:** llenar las cuatro columnas con un `valueGetter` que cruce
con la rejilla de arriba —cuesta **cero peticiones**, los cuatro campos ya están en memoria
vía `GET profesores`, y **no deshace el recorte** porque esa ruta sí lleva `auth.personal`—
o quitarlas.

### Lo que este despliegue DESBLOQUEÓ

- **La versión de `myvc_flutter`** que llama a las tres rutas nuevas: la condición era estar
  en todos, y ya lo está.
- **El typo de `PapeleraCtrl:62`** en `myvc_front`, que era lo único que tapaba
  `grupos/forcedelete` desde la interfaz: su guard ya está desplegado.

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
desplegado** —los colegios lo dijeron minutos después de un `push`, porque cada uno
apunta a su propio remoto, y sólo el hash lo distingue— y que **una carpeta sin
repositorio ni aplicación no recibe nada** y se queda sin los arreglos sin decirlo.

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
   colegio, y con quince, 21.600. La propia página lo avisa en letra pequeña.
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

> **`demo` no está en la lista de colegios de este documento, y el 29 ago 2026 se descubrió
> atrasada a mano.** Ni en el `for` de comprobación ni en el recuento. Al ponerla al día salió
> además un `if` cableado en `app.ts` del front (`indexOf('demo') > 0` → `demo/5myvc/public/`)
> que manda el login de ese colegio a la API vieja, **en un bundle que comparten los quince**.
> Las tres decisiones que abre —arreglo en el front o parche local, si `demo` entra en las
> listas, y `coljordan` atrasado con `lal` sin contestar— están en la casilla **2septies** de
> [`ESTADO-ACTUAL.md`](migracion/ESTADO-ACTUAL.md#lo-que-espera-tu-respuesta--la-lista-de-la-mañana-del-25-por-consecuencia),
> **esperando a Joseth**.
>
> **Y una trampa nueva del `checkout -f`:** el bundle de `up/` puede llevar un parche a mano, y
> el `checkout -f` se lo lleva **sin que se pueda leer qué era** — minificado es una línea, así
> que `git diff --stat` da `1 insertion(+), 1 deletion(-)` igual para un carácter que para el
> fichero entero. Si `git pull` se queja de un `assets/index-*.js` modificado, **cópialo antes**
> (`cp assets/index-*.js ~/index.antes-del-parche`), que es el único modo de saber qué se pierde.

### El bucle de `app2` que había en DESPLIEGUE.md SUSTITUÍA el legacy — corregido el 25 ago

Decía: construir `app2` con `--base-href /up/`, copiarlo a `myvc_dist` y pullear en
`up/`. Pero **`up/` es el legacy** (`myvc_front`) y **`myvc_dist` es su bundle
construido**, así que ese bucle no estrena el front nuevo: **lo pone encima del viejo
en los quince**. El ensayo que pasó lo probó sustituyendo.

**La decisión de Joseth (25 ago) es la contraria: los dos vivos y el usuario elige.**
El legacy se queda en `up/` y el nuevo va en una carpeta hermana. De ahí cuelga todo
lo demás, y **el artefacto de hoy no sirve**: lleva `<base href="/up/">` dentro.

Lo que hay que hacer antes de la primera tanda de `app2`, todo en el repo del front:

1. **El nombre de la carpeta, y no debería llevar `+`.** `up+` es legal en una ruta
   pero se decodifica como espacio en demasiados sitios —mod_rewrite, el
   `<base href>`, el router— y el fallo parecerá otra cosa. `up2` no cuesta nada.
2. **Reconstruir con `--base-href /up2/`.** Sin esto: pantalla en blanco y once
   recursos en 404, ya medido contra Apache de verdad.
3. **`RewriteBase /up2/`** en el `.htaccess` de dentro. **La reescritura nunca en la
   raíz**: el contrafactual está montado y visto fallar — `GET /8myvc/public/api/login`
   devolvía **200 `text/html`** con Angular donde iba JSON, y un 200 con el cuerpo
   equivocado se diagnostica muchísimo peor que un 404.
4. **Comprobar que el `.htaccess` viaja al renombrar.** El glob `**/*` de
   `angular.json` no casa ficheros que empiezan por punto; se cerró una vez y mover la
   carpeta lo vuelve a abrir.
5. **`app2` necesita su propio repo de dist.** `myvc_dist` está ocupado por el legacy y
   no puede llevar los dos.
   > **CONTESTADO el 30 ago 2026, y ya existe: `myvc_dist2`.** Está clonado en la
   > carpeta **`up2/`** de cada colegio —`git remote` de `lal` medido en el servidor,
   > `HEAD` en `ef42e3e` del 29 ago— y **`/up2/` contesta 200 en los dieciséis**: los
   > quince colegios, `demo` y `lal`. O sea que **`app2` no está por desplegar: está
   > desplegado en todos**, con `<base href="/up2/">`, que es el valor que le
   > corresponde a esa carpeta. Son **cinco** carpetas por colegio —`8myvc`, `up`,
   > `up2`, `plus`, `landing`— y no cuatro.

**Y el hueco que no cierra nadie más que Joseth, ahora por duplicado: quién copia
`dist/browser/*` al repo de dist, y con qué.** No hay guion, ni hook, ni README; los
commits son suyos y ponen `build: …`. Es el único paso de toda la cadena —backend y
front— que no existe por escrito.

Del backend **no hace falta nada**: mismo subdominio, misma API bajo
`/8myvc/public/api`, mismo origen (CORS no entra) y varias sesiones simultáneas del
mismo usuario ya funcionan (`app/Services/Sesion.php:493` solo limpia caducados). La
pregunta que sí abre este plan es del front: **los dos comparten `localStorage`** por
compartir origen, así que si usan la misma clave para el token la sesión se comparte
al saltar de uno a otro, y si usan claves distintas quedan dos sesiones vivas y
cerrar una no cierra la otra.

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

---

# El orden entre el `ALTER` y el `app/`

Movido desde `DESPLIEGUE.md` el 25 ago 2026 al vaciarlo: son decisiones y
mediciones, no comandos de la tanda que toca.

## No hay un orden universal, y ésa es la regla

La migración del boletín independiente añade `unidades.alumno_id`. Con la columna
puesta y **nadie marcado**, cuatro consultas de boletines empiezan a dar **500**:

```
SQLSTATE[23000]: 1052 Column 'alumno_id' in on clause is ambiguous
```

Cuatro predicados nombraban `alumno_id` **sin alias delante** dentro de consultas
que unen `unidades`. Hasta hoy no había ambigüedad —`notas` era la única tabla del
join con esa columna—, así que escribirlo desnudo **llevaba veinte años
funcionando**. En cuanto `unidades` tiene la suya, MySQL no puede elegir y aborta.

Lo que importa no es el arreglo —ya está hecho— sino cuándo se rompe:

> **La rompe el `ALTER TABLE`, no el código.**

Y `app/` es **copia por colegio**. En un colegio donde la migración corra **antes**
de que llegue el `app/` nuevo, los boletines dan 500 durante esa ventana. Así que
ahí va primero el `app/` y después el `ALTER`, **al revés que la tanda de
`password_reminders`**, donde la migración iba delante.

> **La migración va delante cuando el código nuevo LA NECESITA para funcionar; va
> detrás cuando es la migración la que ROMPE el código viejo.** Antes de cada tanda
> con esquema hay que preguntarse cuál de los dos casos es, y la forma de saberlo
> es la que lo encontró aquí: **correr la suite con la migración puesta y el código
> viejo**.

Lo encontró `8myvc-9e` la noche del 24, **y lo encontró la suite, no un detector**.
Es el tercer modo de fallo de la §9.2 del plan, y el único bueno: los otros dos
—contar de más y contar de menos— son silenciosos; éste revienta en el primer test
en vez de imprimir un boletín equivocado.

## El paso que va ANTES de escribir un `ALTER TABLE` — `tools/tablas-calientes.php`

La regla de arriba dice **en qué orden** desplegar una migración. Ésta dice **si esa
migración mueve una respuesta**, que es la pregunta que había que hacerse primero y
no tenía respuesta en ninguna parte.

```
ficheros de app/ revisados ......... 220
consultas con SELECT * ............. 251   (resueltas a 360 sobre 57 tablas)
instantáneas leídas ................ 121
tablas con la forma fijada ......... 47
>>> CALIENTES ...................... 35
```

**Caliente** = la tabla tiene alguna consulta que dice `SELECT *` **y** su forma está
fijada por una instantánea. En esas 35, añadir una columna **aparece sola en la
respuesta y mueve una pantalla que nadie tocó** — y hay que avisar a los cuatro
clientes, con `myvc_flutter` siendo una sola app para los quince.

Las de más consultas: `dis_ordinales`, `historiales`, **`years` (64 columnas)**,
`tipos_documentos`, `unidades`, `dis_configuraciones`, `config_certificados`,
`recuperacion_final`, `contratos`, `areas`, `dis_libro_rojo` y **`alumnos` (39
columnas)**.

**Por qué es la peor de detectar, y por eso va como paso y no como consejo:** las
otras formas de romper dependen de qué código haya delante —un `1052 ambiguous`
rompe contra el código viejo—; **ésta no depende del código: depende de que la
consulta diga `*`**, así que un `ALTER` la dispara contra el viejo y contra el nuevo
a la vez.

De ahí que la comprobación con la suite sean **dos pasadas y ninguna encuentre la de
la otra**:

| Pasada | Encuentra | Medido el 24 ago |
|---|---|---|
| esquema nuevo + código **viejo** | el `1052 ambiguous` | 4 consultas |
| esquema nuevo + código **nuevo** | el `SELECT *` | 5 snapshots |

> La herramienta lleva `--autoprueba`, **y se corre primero**: comprueba que distingue
> `unidades` de `unidades_por_defecto` —el falso positivo que se comió una medición
> esa noche, porque los ocho primeros caracteres coinciden— y que **no cuenta
> subconsultas**, que fue el cuarto falso positivo y sólo se vio leyendo.

## La pasada se publica con DOS números, no con uno — medido el 25 ago

Correr la suite con el esquema nuevo y el código viejo se ejecutó, y **su resultado
obliga a cambiar cómo se publica**:

```
predicados ambiguos que EXISTEN ......... 4   (barrido estático del patrón, sin correr nada)
predicados que la SUITE ejerce ........... 2
```

**Los dos que no ejerce no son el mismo caso:**

- `Subunidad::perdidasDeAsignatura` es **código muerto** —los diez llamantes usan
  `perdidasDeUnidad`, que es **otro método**—;
- **la rama `fortaleza_debilidad` de `Unidad` está VIVA y detrás de un interruptor por
  colegio**: la alcanza `Boletines2Controller:228` cuando el año tiene
  `years.show_fortaleza_bol = 1`, y **la base de test tiene 1 de 8 años con ese
  interruptor encendido… y ningún test usa ese año**.

> **Un colegio con `show_fortaleza_bol` puesto recibe un 500 que esa pasada no ve.**
> Y no es que esté mal hecha: **su alcance es el de la suite, no el del código.**

Por eso se publica con los dos números, y **un `0` significa «la suite no encontró
nada», nunca «no hay nada»**. El guion `tools/esquema-nuevo-codigo-viejo.sh` lo lleva
en su cabecera, que es donde lo leerá quien lo corra.

Y el interruptor por colegio es **el patrón general, no la anécdota de este caso**: es
la misma familia que `mostrar_puesto_boletin` (1 de 8 años a 0) y que la excepción por
colegio que avisó el front.

> **Lo que un interruptor apaga, la suite no lo prueba** — y hay quince colegios con
> quince combinaciones. Antes de un `ALTER TABLE`, la pregunta no es sólo *«¿qué
> rompe?»* sino **«¿qué rompe en la combinación de interruptores que la suite no
> tiene?»**
