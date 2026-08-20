# Desplegar

Los comandos, en orden. El porqué de todo esto está en
[DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md) y no hace falta abrirlo para
desplegar.

Procedimiento validado colegio a colegio en los 16, el 19 de agosto de 2026.

---

## 0. Una vez, no por colegio

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

## 0.bis Qué trae esta tanda, y qué se va a notar

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

---

## 1. Desplegar un colegio

### Backend

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
cd "$d"

php -v                       # PARA si no dice 8.4
git pull                     # trae composer.lock
ls -l composer.lock          # sin él, install se comporta como update
```

**Ahora las librerías, y depende de qué tipo sea el colegio:**

```bash
# A) vendor/ PROPIO — se instala dentro del colegio
[ -L vendor ] && echo "COMPARTIDO: no sigas por aquí" || composer install --no-dev

# B) vendor/ COMPARTIDO — UNA vez para los cinco, en la carpeta real,
#    y con el composer.json/lock de la rama ya puestos ahí
cd /home/micolev1/laravel_compartido && composer install --no-dev
```

**Nunca `composer` dentro de un colegio que cuelgue por symlink**: sigue el enlace
sin avisar y le cambia las dependencias a los otros cuatro. El `[ -L vendor ]` de
arriba es la comprobación, y va antes que el comando a propósito.

`--no-dev` ahorra 38 MB de los 70. El precio es que ahí no se puede ejecutar
`php artisan test`; eso corre en el CI y en local.

```bash
cd "$d"
php artisan package:discover # OBLIGATORIO tras tocar vendor/
php artisan migrate --force  # sin esto TODOS los logins dan 500

php artisan migrate:status | grep -E 'personal_access_tokens|firmantes_acta'
# las dos tienen que decir Ran
```

**Las dos migraciones de esta tanda, y qué se rompe si falta cada una:**

| Migración | Qué hace | Si no se corre |
|---|---|---|
| `2026_08_19_000000_create_personal_access_tokens_table` | crea la tabla de tokens de Sanctum | **todos los logins dan 500** y el colegio queda inservible |
| `2026_08_19_100000_add_firmantes_acta_to_years_table` | añade `firmantes_acta` a `years`, admite NULL | el acta abre y propone rector y secretario, pero **guardar** los firmantes da 500 |

Las dos son aditivas —una tabla nueva y una columna que admite NULL—, así que
correrlas de más no rompe nada y el código viejo las ignora. Por eso volver atrás
no exige deshacerlas.

`package:discover` no es opcional: `bootstrap/cache/packages.php` es de cada
colegio y se genera a partir del `vendor/`. Sin regenerarlo, el colegio arranca
con la lista de proveedores anterior. **En los cinco compartidos hay que correrlo
en los cinco**, aunque el `composer install` se haya hecho una sola vez.

### El `.env` de este colegio

`config/` y `.env` son copia real en cada colegio, así que esto va uno por uno, y
**antes de cachear la configuración**.

```bash
php artisan key:generate --force   # una APP_KEY propia, distinta a la de los demás
```

```env
MAIL_MAILER=sendmail
MAIL_FROM_ADDRESS=josethmaster@lalvirtual.com
MAIL_FROM_NAME="MiColegioVirtual"
```

Las dos de `FROM` **no son opcionales**: si están vacías, Laravel rechaza el envío
antes de intentarlo y el reseteo de contraseña devuelve 500.

**`CORS_ALLOWED_ORIGINS` se deja SIN definir.** El fallback es `*` y hace falta: la
app Flutter no tiene el subdominio del colegio como origen y en build nativa no
manda `Origin` en absoluto. Como la autenticación va por `Bearer` y no por
cookies, `*` no abre nada.

#### Sobre la `APP_KEY` y los tokens de sesión

Tres cosas que conviene no confundir, porque llevan al paso de arriba:

**Los tokens de sesión no salen de ninguna clave del `.env`.** Son 40 caracteres
al azar y en la base solo queda su SHA-256, en `personal_access_tokens` de ese
colegio. Ni `composer install` ni cambiar la `APP_KEY` los toca: siguen valiendo.
Lo que los invalida es borrar la fila —cerrar sesión— o que caduquen.

**Lo que sí desaparece al desplegar son los JWT viejos**, y no por ninguna clave
sino porque se quitó el paquete que sabía leerlos. Todo el mundo vuelve a entrar
una vez.

**Que varios colegios compartan `APP_KEY` era un agujero de verdad, y ya está
cerrado — pero por el camino, no a propósito.** Con `JWT_SECRET` compartido, un
token firmado en el colegio A valía en el colegio B: el JWT solo lleva dentro el
id del usuario, así que quien fuera el usuario 5 en A entraba como el usuario 5
de B, que es otra persona. Con Sanctum eso no puede pasar — el token es una fila
en la base de **ese** colegio y en la del vecino no existe.

Aun así, cada colegio con su `APP_KEY`. Hoy aquí no cifra nada persistente
—no hay `encrypt()`, ni casts cifrados, ni rutas firmadas, y las cookies no se
usan porque la autenticación va por `Bearer`—, así que rotarla no rompe nada y no
hay que avisar a nadie. Pero una clave compartida entre 16 sitios es una que solo
puede empeorar: basta que mañana alguien use `Crypt::` o una URL firmada para que
lo de un colegio valga en otro. Se arregla ahora, que cuesta un comando.

### Cachés y comprobación

```bash
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache

php artisan --version        # Laravel Framework 13.26.1
```

**Van después del `.env`.** `config:cache` congela lo que haya en ese momento; si
se cachea antes de tocar la configuración, el colegio sirve la de antes y no hay
ningún síntoma que lo delate.

`route:clear` tampoco es opcional: las rutas `auth/*` no existen con la caché
vieja puesta, y `POST /api/auth/login` devuelve 404 con el código bien desplegado.
**Confirmado en `coal`**, y así se reconoce en el trace: si aparece
`CompiledRouteCollection`, las rutas vienen de `bootstrap/cache/routes-v7.php` y
la caché sigue puesta.

**Cada comando lleva su propio `php artisan`.** Encadenar
`php artisan config:clear && route:clear` **no funciona**: el `&&` no arrastra el
`php artisan`, el segundo muere con `route:clear: command not found` y la caché
vieja se queda intacta. Es el peor estado posible —parece limpiada y no lo está—.

Comprobación directa de que la ruta existe antes de probar en el navegador:

```bash
php artisan route:list --path=auth/login    # POST api/auth/login
```

### El cron de este colegio — una sola línea, y una sola vez

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

### Front (`up/`)

```bash
cd /home/micolev1/COLEGIO.micolevirtual.com/up

git fetch origin                     # sin esto te quedas en el build viejo
git checkout -f -B main origin/main
git clean -fd                        # NO uses -x: se llevaría el logo del colegio
git branch -D master
git remote prune origin

grep -o 'assets/index-[^"]*\.js' index.html
```

### Comprobar

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

---

## 2. Volver atrás

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
cd "$d"

git checkout <commit-anterior>
composer install --no-dev            # o en laravel_compartido, si cuelga de ahí
php artisan package:discover
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**En los cinco del `vendor/` compartido, volver atrás es todo o nada**: la carpeta
es una sola. O se revierten los cinco `app/` y la carpeta, o ninguno.

Lo que **no** se deshace es la versión de PHP: si hay que volver atrás, se vuelve
con 8.4 puesto. Las dos migraciones tampoco hace falta deshacerlas: una añade una
tabla y la otra una columna que admite NULL, y el código viejo ignora ambas.

Y al revés, si el código llega antes que la migración: el acta sigue abriendo y
propone rector y secretario como firmantes —`firmantesDelYear` consulta la
columna aparte, a propósito, para que el fallo se quede en esa pantalla—. Lo que
falla con 500 es **guardar** los firmantes. Se arregla corriendo `migrate`.

---

## 3. Las siete trampas que cuestan un colegio

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

---

## 4. Estado

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
