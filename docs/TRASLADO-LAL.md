# Traslado de `lal` — los pasos

Llevar `lal` de su alojamiento propio a la cuenta de `micolevirtual.com`
**sin que cambie la URL**: `lalvirtual.edu.co` se queda, sólo cambia la IP a la
que apunta. Nada de redirecciones. Escrito el 30 ago 2026; **nada ejecutado**
salvo el subdominio del paso 1.

## Dónde va esto — 30 ago 2026, noche

**HECHO:** subdominio `lal.micolevirtual.com` creado y limpio (el `up2` que traía está en
`~/_residuos/`) · los cuatro repos clonados **en su rama y al día**, que resultan ser los
mismos hashes que sirve el viejo · `vendor/` por **symlink a `laravel_compartido`** —`lal`
es ahora el **sexto** del bloque— · `.env` puesto (**copiado del de `fortul` con la base
cambiada**, decisión de Joseth) · base `micolev1_lal_db` importada y **verificada: 93
tablas**, con la migración bloqueante en `Ran` · las cuatro cachés y `--version` 13.26.1 ·
`storage/logs/laravel.log` de 225 MB vaciado en el viejo (la rotación `daily` ya estaba).

**FALTA para cerrar el ensayo:** el `rsync` del paso 2C —`plus/`, `storage/`, `index.php`,
`favicon.ico`, `robots.txt`, `ms87615257.txt`— y la comprobación 2F.

**RUTA ELEGIDA para el cambio** (Joseth, 30 ago): **redirect primero, DNS después.** Se
cambia la URL de `lal` en el listado de la app de Flutter, se pone un `.htaccess` de
redirección en el viejo, y el dominio se mueve cuando conteste el proveedor. **El orden
que no se puede invertir:** `artisan down` en el viejo → volcado y `rsync` de nuevo →
**y entonces** el redirect. Al revés se pierde todo lo escrito entre medias.

## Los datos (medidos el 30 ago 2026)

| | |
|---|---|
| VIEJO | `lalvirtual.edu.co` · `70.32.23.70` · `mi3-ss54` · cuenta **`micolevi`** · docroot `~/public_html` · PHP 8.4.24 |
| NUEVO | `70.32.23.72` · `mi3-ss55` · `/home/micolev1/` · PHP 8.4.24 |
| Base | `micolevi_lalvirtual` · usuario `micolevi_great` |
| `storage/` | **239 MB** |
| Laravel | **13.26.1** · `vendor/` **propio**, no symlink |
| Hashes hoy | `8myvc` **`50b0f10`** (main) · `up` **`52a0cdd`** (main) · `landing` **`528ce16`** (master) |
| Cron | **NO HAY** — `crontab -l` vacío en las dos cuentas |
| DNS | `ns1..ns4.a2hosting.com` — **los mismos para los dos dominios** |
| Viajan | `8myvc/` (926 M) · `up/` (46 M) · `landing/` (49 M) · `up2/` (13 M, **`app2`**, `<base href="/up2/">`) · `plus/` (1,4 M, PIAR) · `concurso/` + `concurso10-11/` (40 K, dan 200) · `index.php` · `.htaccess` · `favicon.ico` · `robots.txt` · `ms87615257.txt` |
| **No viajan** | `5myvc/` (265 M, **da 500**) · `wissenLaravel5.3/` (115 M) · `app/` (38 M) · `6myvc/` (816 K) · `comanditos/` · `node/` (vacío) — **418 MB**, todos 403 o rotos. Se archivan, no se mudan |
| `landing/` | [`bluesky777/landingLAL`](https://github.com/bluesky777/landingLAL), rama **`master`**, 40,7 MB |
| Correo | **en el cPanel viejo** (no hay Google Workspace) · **16 buzones, ~341 MB** |
| Hash del front hoy | `index-Bermvdik.js` — el mismo que `casb`, `coab`, `cads`, `coal` |

**Decisiones que faltan:** `vendor/` compartido o propio (paso 2E) · correo copiado
o a un Workspace (paso 3) · a nombre de quién está el dominio (paso 4).

---

## Paso 1 — El subdominio de pruebas · **HECHO**

`lal.micolevirtual.com`, document root **`/home/micolev1/lal.micolevirtual.com`**
(no el que propone cPanel: los bucles de despliegue buscan ese patrón).

Traía un residuo `up2`. Sacarlo del docroot antes de seguir:

```bash
mkdir -p /home/micolev1/_residuos
mv /home/micolev1/lal.micolevirtual.com/up2 /home/micolev1/_residuos/lal-up2-2026-08-30
```

---

## Paso 2 — El ensayo, con una copia de la base

Deja `lal.micolevirtual.com` sirviendo lo mismo que el viejo. No toca a nadie.

> **Los dos prompts se parecen y ya han costado tres pasos en la máquina equivocada.**
> Cada bloque dice en cuál va; pega esta línea delante y lo dice el propio shell:
>
> ```bash
> quien() { [ "$(whoami)@$(hostname -s)" = "$1" ] && echo "OK, estás en $1" || echo "!! ESTÁS EN $(whoami)@$(hostname -s) Y ESTO VA EN $1"; }
> quien micolevi@mi3-ss54     # A, C y D (volcado): el VIEJO
> quien micolev1@mi3-ss55     # B, D (importación), E y F: el NUEVO
> ```
>
> **Y los usuarios de MySQL son locales a cada máquina:** `micolevi_great` sólo existe
> en `mi3-ss54`. Lanzar el `mysqldump` desde el nuevo da `1045 Access denied` aunque
> la contraseña sea correcta.

### A. En el VIEJO — el inventario que alimenta B y C

```bash
V=~/public_html

whoami; hostname; php -v | head -1
ls -la "$V"
for c in 8myvc up plus landing; do
  if [ -d "$V/$c/.git" ]; then
    printf '%-8s %-46s %-10s %s\n' "$c" \
      "$(git -C "$V/$c" remote get-url origin)" \
      "$(git -C "$V/$c" rev-parse --abbrev-ref HEAD)" \
      "$(git -C "$V/$c" rev-parse --short HEAD)"
  else printf '%-8s NO ES UN CLONE -> rsync\n' "$c"; fi
done
grep -E '^(APP_URL|DB_HOST|DB_DATABASE|DB_USERNAME)=' "$V/8myvc/.env"
readlink -f "$V/8myvc/vendor"; php "$V/8myvc/artisan" --version
du -sh "$V/8myvc/storage"; ls -la "$V/index.php" "$V/.htaccess" 2>/dev/null
crontab -l
```

### B. En el NUEVO — clonar las cuatro que son repos

`plus/` es lo único que **no** es clone: va por `rsync` en C.

```bash
N=/home/micolev1/lal.micolevirtual.com; cd "$N"
git clone https://github.com/bluesky777/8myvc.git      8myvc
git clone https://github.com/bluesky777/myvc_dist.git  up
git clone https://github.com/bluesky777/myvc_dist2.git up2
git clone https://github.com/bluesky777/landingLAL.git landing

git -C 8myvc checkout main;  git -C up  checkout main
git -C up2   checkout main;  git -C landing checkout master
for r in 8myvc up up2 landing; do
  printf '%-8s %-8s ' "$r" "$(git -C $r rev-parse --abbrev-ref HEAD)"; git -C "$r" pull -q && git -C "$r" rev-parse --short HEAD
done
```

> **Decisión de Joseth, 30 ago 2026: en su rama y al día, no congelados en el hash que
> sirve hoy.** El traslado estrena versión a la vez que estrena servidor, y **eso se
> acepta a sabiendas**: si algo falla no se sabrá de entrada si es la mudanza o el
> código nuevo, y a cambio `lal` queda listo para entrar en el bucle de despliegue el
> mismo día, sin `detached HEAD` que rompa el `git pull`.
>
> **Y hecho el 30 ago, no estrenó nada:** las cuatro puntas de rama son exactamente los
> hashes que sirve el viejo —`8myvc` `50b0f10` · `up` `52a0cdd` · `up2` `ef42e3e` ·
> `landing` `528ce16`—, así que la copia es idéntica en código **y** queda en rama. Las
> ramas son `main` salvo `landing`, que usa `master`.

### C. Lo que no está en git — desde el VIEJO, sin `--delete`

```bash
D=micolev1@mi3-ss55.a2hosting.com
N=/home/micolev1/lal.micolevirtual.com
V=~/public_html

rsync -avz "$V/8myvc/storage/" "$D:$N/8myvc/storage/"    # 239 MB, lo insustituible
rsync -avz "$V/plus" "$V/concurso" "$V/concurso10-11" "$D:$N/"
rsync -avz "$V/index.php" "$V/favicon.ico" "$V/robots.txt" "$V/ms87615257.txt" "$D:$N/"
rsync -avz "$V/8myvc/.env"     "$D:$N/8myvc/.env"        # se EDITA después (E)
```

> **El `.htaccess` NO se copia en el ensayo.** Fuerza HTTPS con
> `RewriteRule ^(.*)$ https://lalvirtual.edu.co/$1`, o sea que en
> `lal.micolevirtual.com` mandaría a quien entre por HTTP **de vuelta a producción**.
> Va en el paso 5, cuando el dominio ya sea el de verdad.

**Y antes de nada, el seguro: un archivo completo del docroot viejo, guardado fuera
del servidor.** Es 1,5 GB una vez y hace inofensiva la baja.

```bash
tar czf ~/lal-public_html-$(date +%F).tar.gz -C ~ public_html   # y descargarlo
```

### D. La base

Crear base y usuario en cPanel del NUEVO (llevarán prefijo `micolev1_`).

```bash
# VIEJO
mysqldump --single-transaction --routines --triggers -u micolevi_great -p micolevi_lalvirtual \
  | gzip > ~/lal-$(date +%F-%H%M).sql.gz
scp ~/lal-*.sql.gz micolev1@mi3-ss55.a2hosting.com:~/
# NUEVO
zcat ~/lal-*.sql.gz | mysql -u micolev1_USUARIO -p micolev1_BASE
```

### E. `.env`, `vendor/` y arrancar

```bash
cd /home/micolev1/lal.micolevirtual.com/8myvc
nano .env          # SOLO DB_DATABASE, DB_USERNAME, DB_PASSWORD

ln -sfn /home/micolev1/laravel_compartido vendor   # DECISIÓN, ver abajo
php artisan package:discover

php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
php artisan --version; php artisan migrate:status | tail -3
php artisan correo:probar tucorreo@ejemplo.com
```

> Hoy `lal` tiene `vendor/` **propio** (Laravel 13.26.1) porque está en otra cuenta.
> En la nueva: symlink a `laravel_compartido` = **seis** colegios que se despliegan y
> revierten como un bloque y **nunca `composer` dentro de `lal`**; carpeta propia =
> `composer install --no-dev`, ~32 MB más.

### F. Comprobar

```bash
for u in / /landing/ /up/ /plus/ /8myvc/public/api/years; do
  printf '%-30s ' "$u"; curl -sI --max-time 12 "https://lal.micolevirtual.com$u" | head -1
done
curl -sL https://lal.micolevirtual.com/up/ | grep -o 'assets/index-[^"]*\.js' | head -1
```

Esperado: `/` **302 a `/landing/`** · `/landing/` `/up/` `/plus/` **200** · API
**401** · bundle **`index-Bermvdik.js`**.

Y a mano: login de personal y de alumno · guardar una ficha y volver a mirarla ·
un boletín · **una foto de perfil** (prueba que `storage/` viajó) · cambiar una
nota y ver moverse la definitiva.

### G. En el ensayo NO se hace

- No se toca el DNS.
- **No se manda a nadie a `lal.micolevirtual.com`.** Esa base se tira y se
  reimporta en el paso 5.
- No se crea el cron todavía.

---

## Paso 3 — El correo (16 buzones, ~341 MB)

Elegir una:

**(a) Copiarlos al cPanel nuevo.** Recrear las 16 direcciones **con las mismas
contraseñas** y `rsync` de `/home/micolevi/mail/lalvirtual.edu.co/<usuario>/`.
Gratis y va dentro de la ventana.

**(b) Google Workspace.** *Business Starter son ~US$7–8 por buzón y mes: 16 buzones
≈ US$1.400/año, más de lo que cuesta el hosting que se da de baja.* **Pero
[Workspace for Education Fundamentals es gratuito](https://edu.google.com/intl/es-419/workspace-for-education/editions/education-fundamentals/)
para instituciones educativas que califiquen** (100 TB compartidos), y el Liceo es un
colegio. Hay que solicitarlo con los documentos de acreditación y **Google tarda en
verificar**, así que se pide con semanas de antelación.

1. Solicitar Education Fundamentals · verificar el dominio (TXT) · crear los 16
   usuarios con las mismas direcciones.
2. Migrar con el **Data Migration Service** del Admin console, origen *IMAP genérico*:
   servidor `mail.lalvirtual.edu.co`, puerto 993 SSL, y la contraseña de cada buzón
   (se restablecen en cPanel si no se tienen).
3. `MX` a Google · SPF `v=spf1 include:_spf.google.com ~all` · DKIM el de Workspace.
4. **cPanel → Enrutamiento de correo → Remoto.** Si se queda en *Local*, el servidor
   se entrega a sí mismo y el correo interno nunca llega a Google.
5. Borrar los buzones locales **sólo al final**.

> **Si se elige (b), va ANTES del traslado y por separado, nunca en la ventana.** Con
> el `MX` ya en Google, el paso 5 deja de tener mitad de correo y se vuelve sólo web.
>
> **Y una consecuencia para la aplicación:** con el SPF apuntando sólo a Google,
> cualquier correo que salga del servidor con un `From:` `@lalvirtual.edu.co` falla
> SPF. Hoy no muerde porque el remitente es `@lalvirtual.com` —que no existe, ver el
> final— pero el día que se corrija hay que añadir la IP del servidor al SPF o mandar
> por SMTP autenticado de Workspace.

Mirar antes, con cualquiera de las dos: reenvíos y filtros (no viajan con el Maildir)
· `grado9@` tiene la cuota en 50 MB y los demás en 250 · `glo@` está a 0 bytes.

> ### PENDIENTE — verificar el 31 ago 2026: puede que el Workspace YA EXISTA
>
> En un buzón de `@lalvirtual.edu.co` hay un correo de **The Google Workspace Team** del
> 16 feb 2023, *«Dear EDU Administrator»*, sobre los términos de **Workspace for
> Education**, y el pie dice que llega **por ser contacto principal o secundario de una
> cuenta de Workspace**. O sea que en 2023 existía un tenant con una dirección de este
> dominio como administrador.
>
> **Hoy no está en uso**, medido: el `MX` apunta al propio servidor, el SPF es el de A2
> sin `include:_spf.google.com`, y **no hay ningún `google-site-verification`** en los TXT.
> Pero **un dominio reclamado sigue reclamado**: si el tenant existe, dar de alta uno
> nuevo fallará con «el dominio ya está en uso por otra organización».
>
> **Los tres pasos, en orden:**
> 1. Mirar el `Para:` de ese correo — dice cuál de los 16 buzones es el contacto.
> 2. `admin.google.com` con esa dirección, opción *«¿olvidaste la contraseña?»*: si Google
>    la reconoce, el tenant existe.
> 3. Si no, empezar el alta de Education Fundamentals con `lalvirtual.edu.co`: el paso de
>    verificación del dominio da la respuesta definitiva.
>
> **Mientras esto esté abierto, no borrar ni renombrar esos buzones**: si son el contacto
> de una cuenta de Google, son por donde llega cualquier recuperación.

---

## Paso 4 — El proveedor (los dos hostings son suyos)

> **Comprobado el 30 ago: el dominio NO está en la cuenta nueva, así que el mensaje va
> entero.** `uapi DomainInfo list_domains` en `micolev1` da **`addon_domains: []`** y
> **`parked_domains: []`**; el dominio principal es `micolevirtual.com` y lo demás son
> 22 subdominios. La carpeta `~/lalvirtual.edu.co/` (32 K, enero de 2021) que hay ahí
> es **un resto, no un dominio dado de alta** — no sirve nada y no ahorra el ticket.

**Mensaje 1, antes de nada:**

> Tengo dos alojamientos con ustedes: `lalvirtual.edu.co` (`mi3-ss54`) y
> `micolevirtual.com` (`mi3-ss55`). Quiero consolidar todo en el segundo y después
> dar de baja el primero. **`lalvirtual.edu.co` tiene que seguir siendo la dirección
> pública, sin redirecciones.**
>
> 1. **Muevan el dominio `lalvirtual.edu.co` de la primera cuenta a la segunda**, para
>    poder darlo de alta ahí como dominio adicional.
> 2. Que **la zona DNS se conserve**: ustedes son mis nameservers para los dos
>    dominios, y necesito que sobrevivan el `MX`, el SPF y el DKIM — tengo 16 buzones
>    en ese dominio.
> 3. Confírmenme **a nombre de quién está registrado el dominio y cuándo renueva**. El
>    dominio continúa; lo que voy a dar de baja es sólo el alojamiento.
>
> **No den de baja nada todavía.**

**Y días antes de la ventana: bajar el TTL de `@`, `www` y `mail` a 300 s.** Es lo
que hace el cambio instantáneo y la vuelta atrás de cinco minutos.

---

## Paso 5 — La ventana (de noche)

1. `cd <viejo>/8myvc && php artisan down` — nadie escribe ya en la base vieja.
2. Repetir **2C** (`storage/`), **2D** (volcado bueno) y el Maildir del paso 3.
3. Alta de `lalvirtual.edu.co` y `www` en el cPanel nuevo, **document root el de
   `lal`** (el mismo que sirve `lal.micolevirtual.com`).
4. DNS: `A` de `@`, `www` y **`mail`** → `70.32.23.72`. El `MX` sigue diciendo
   `mail.lalvirtual.edu.co`. **SPF y DKIM sí cambian** (el SPF lleva
   `ip4:70.32.23.70` dentro; el DKIM lo regenera el cPanel nuevo).
5. AutoSSL a mano en cuanto resuelva (antes del DNS no puede emitir).
6. `git pull` en los cuatro repos (ya están en su rama desde el paso 2B) y
   `php artisan migrate --force` si la tanda trae migración.
7. El cron: **no hay ninguno que recrear** (`crontab -l` vacío en las dos cuentas).
   Decidir si se crea el `schedule:run` — hoy `sesion:limpiar` no corre en ningún sitio.
8. Comprobar como en **2F**, pero contra `https://lalvirtual.edu.co`, más: la portada,
   un correo a un buzón `@lalvirtual.edu.co` **y desde él a un Gmail**, y **entrar
   desde la app de Flutter** con el servidor guardado.

---

## Paso 6 — Después

- El alojamiento viejo se deja pagado y en `down` **una semana**.
- **Punto de no retorno: la primera escritura en la base nueva.** Antes: `artisan up`
  y la IP de vuelta, cinco minutos. Después: volcar del nuevo al viejo.
- Sólo entonces, **mensaje 2 al proveedor**: *«den de baja el alojamiento de la cuenta
  antigua; no toquen el registro del dominio ni su zona DNS»*.
- Y corregir en este repositorio: [DESPLIEGUE.md](DESPLIEGUE.md) (paso 0 y paso 1,
  «la otra cuenta de cPanel»), [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md)
  («dos alojamientos», PHP por cuenta), [17-cron.md](migracion/17-cron.md),
  [ESTADO-ACTUAL.md](migracion/ESTADO-ACTUAL.md) casilla 2sexies.

---

## Los siete avisos

| | |
|---|---|
| **El orden con el proveedor** | Mover el dominio **antes** de la baja. Al revés, la zona muere con la cuenta y el dominio deja de resolver: web **y correo** |
| **Nada de redirector** | No cubre `/8myvc/public/api`, así que los móviles seguirían escribiendo en la base vieja: **dos bases vivas y ningún comando que las junte** |
| **El SPF lleva la IP dentro** | Si no se cambia, el correo sale y **cae en spam**, sin error visible |
| **`index.php` y `landing/`** | Todas las comprobaciones entran por `/up/`: el traslado puede pasar entero **sin que nadie mire la portada** |
| **El document root** | Si no es `/home/micolev1/lal.micolevirtual.com`, `lal` sigue fuera de los cuatro bucles |
| **`composer` con `vendor/` compartido** | Sigue el symlink y cambia a los otros cinco colegios, sin avisar y sin fallar |
| **Los `artisan` no se encadenan con `&&`** | El segundo muere y la caché vieja sigue viva. Si uno no imprime su `INFO`, no corrió |

> Dos hallazgos que salieron midiendo esto y **no son del traslado**, ya anotados
> donde tocan: **`lalvirtual.com` no está registrado** y es el `MAIL_FROM_ADDRESS` de
> los quince colegios ([DESPLIEGUE-REFERENCIA § correo](DESPLIEGUE-REFERENCIA.md)) ·
> **`lal` no iba atrasado de front**, el barrido preguntaba por una URL inexistente
> ([ESTADO-ACTUAL, casilla 2septies](migracion/ESTADO-ACTUAL.md)).
