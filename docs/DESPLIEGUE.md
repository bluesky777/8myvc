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
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache

php artisan --version        # Laravel Framework 13.26.1
```

`package:discover` no es opcional: `bootstrap/cache/packages.php` es de cada
colegio y se genera a partir del `vendor/`. Sin regenerarlo, el colegio arranca
con la lista de proveedores de la generación anterior.

`route:clear` tampoco: las rutas `auth/*` no existen con la caché vieja puesta, y
`POST /api/auth/login` devuelve 404 con el código bien desplegado.

### El `.env` del colegio

`config/` y `.env` son copia real en cada colegio, así que esto va uno por uno.

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
php artisan migrate:status | grep personal_access_tokens   # Ran

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
con 8.4 puesto. Y la migración de `personal_access_tokens` tampoco hace falta
deshacerla — es una tabla nueva que el código viejo ignora.

---

## 3. Las cinco trampas que cuestan un colegio

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
