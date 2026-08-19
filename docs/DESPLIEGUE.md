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

### PHP 8.4 en las dos cuentas de cPanel

Laravel 13 no arranca por debajo de 8.3.

**Este paso afecta a todos los colegios de la cuenta a la vez** — la versión se
elige por cuenta, no por colegio. Es el único de toda la migración que no se puede
escalonar, así que va antes de desplegar el primero.

Mientras tanto los colegios siguen en Laravel 8.83.29, que arranca en 8.4 pero no
está soportado ahí. Para que esa ventana dure poco: subir a **8.1** con calma
(territorio soportado por Laravel 8) y a **8.4** el día que empiece el despliegue.

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

### La carpeta `vendor/` de la generación 13

```bash
cd /home/micolev1
mkdir laravel_13 && cd laravel_13
# composer.json y composer.lock de la rama que se va a desplegar
composer install --no-dev
```

`--no-dev` ahorra 38 MB de los 70 y quita lo que no se usa en producción. El
precio es que en esa carpeta no se puede ejecutar `php artisan test`; eso corre en
el CI y en local.

Cada colegio apuntará aquí con un symlink. Hay una carpeta por generación
(`laravel_8`, `laravel_13`), no una sola: es lo que permite migrar y volver atrás
colegio a colegio. **Nunca correr `composer` dentro de un colegio** — sigue el
symlink y le cambia las dependencias a todos los que cuelguen de la misma carpeta.

---

## 1. Desplegar un colegio

### Backend

```bash
d=/home/micolev1/COLEGIO.micolevirtual.com/8myvc
cd "$d"

php -v                       # PARA si no dice 8.4
readlink -f vendor           # mira a qué generación apunta hoy

git pull                     # trae composer.lock
ls -l composer.lock          # sin él, install se comporta como update

ln -sfn /home/micolev1/laravel_13 vendor
php artisan package:discover # OBLIGATORIO al cambiar de generación

php artisan migrate --force  # sin esto TODOS los logins dan 500
```

`package:discover` no es opcional: `bootstrap/cache/packages.php` es de cada
colegio y se genera a partir del `vendor/`. Sin regenerarlo, el colegio arranca
con la lista de proveedores de la generación anterior.

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

- Alumno y acudiente reciben **403** en `requisitos`, `prematriculas` y
  `piars-grupos`. Es lo esperado desde el PR #7.
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
ln -sfn /home/micolev1/laravel_8 vendor
php artisan package:discover
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

Lo que **no** se deshace es la versión de PHP: si hay que volver atrás, se vuelve
con 8.4 puesto. Las dos migraciones tampoco hace falta deshacerlas: una añade una
tabla y la otra una columna que admite NULL, y el código viejo ignora ambas.

Y al revés, si el código llega antes que la migración: el acta sigue abriendo y
propone rector y secretario como firmantes —`firmantesDelYear` consulta la
columna aparte, a propósito, para que el fallo se quede en esa pantalla—. Lo que
falla con 500 es **guardar** los firmantes. Se arregla corriendo `migrate`.

---

## 3. Las seis trampas que cuestan un colegio

1. **`composer` dentro de un colegio con `vendor/` compartido** le cambia las
   dependencias a todos los que cuelguen de esa carpeta. Sigue el symlink sin
   avisar. Se corre sobre la carpeta de generación, nunca desde un colegio.
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
   vuelve a correr `config:clear && config:cache`.

---

## 4. Estado

| | |
|---|---|
| Colegios | **los 16 desplegados y con el `vendor/` igualado** (19 ago 2026) |
| Framework en producción | Laravel 8.83.29 · PHP 8.0.30 |
| Framework en la rama | Laravel 13.26.1 · PHP 8.4 |
| Sin confirmar | si los cinco de `laravel_compartido` siguen colgando por symlink |

```bash
# Qué generación usa cada colegio hoy
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-46s ' "$d"
  [ -L "$d/vendor" ] && printf 'symlink -> %s\n' "$(readlink "$d/vendor")" || printf 'vendor propio\n'
done
```

**`plus/` es otro repositorio** (`myvc_front_2`, el Angular del PIAR) y solo lo
tienen seis colegios: `casb`, `coab`, `cads`, `coljordan`, `lal` y `coal`.
