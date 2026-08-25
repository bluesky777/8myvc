# Desplegar

**Los comandos de la tanda que toca, y nada más.** Topología, inventario, las siete trampas, el
bucle del front y lo que trajo cada tanda: [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## No hay tanda pendiente

**La del 22 al 25 ago se desplegó el 25 ago en `eb95cbc`**, con sus cuatro migraciones y con el
mismo hash comprobado en los quince. Qué se le nota a un colegio, fila a fila:
[`que-se-nota-en-un-colegio.md`](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md).

Lo de abajo son los comandos de cualquier tanda, y la tabla es **cómo se mide la siguiente** (con
las del 25 ago de ejemplo). **Se remide cuando la tanda crece, no se suma:** esa fila decía
«ninguna migración» con cuatro dentro, medida antes de fundir cuatro ramas.

| | | recalcular con |
|---|---|---|
| Migraciones | cuatro, una bloqueante | `git diff --name-only <desplegado> HEAD -- database/migrations/` |
| Rutas | 539 → 542, aditivas | `tests/Contrato/Snapshots/rutas.json` |
| Dependencias | sin tocar | `git diff --name-only <desplegado> HEAD -- composer.json composer.lock` |
| `config/` | uno nuevo, `notificaciones.php` | `git diff --name-only <desplegado> HEAD -- config/` |

> **El `migrate --force` dejó de ser higiene el 25 ago y no vuelve a serlo.** `2026_08_24_100000`
> creó `bol_ind_periodos` y el código de la misma tanda la consulta en un camino vivo
> (`Unidad:112`): con el código y sin la migración, **500 en todos los boletines**.

| Lo que dejó abierto, al 25 ago | Estado |
|---|---|
| **Cuatro columnas en blanco en la rejilla «Docentes contratados»** de la web vieja (`/panel/profesores`, la de abajo): Usuario, Nacimiento, Email y Celular | **ABIERTO en todos desde el despliegue.** El recorte de `c47ab50` está bien hecho y no se deshace; falta que `myvc_front` las repinte cruzando con la rejilla de arriba, que ya tiene los cuatro campos en memoria. La decisión —llenarlas o quitarlas— es de Joseth |
| **La versión de `myvc_flutter` que llama a las tres rutas nuevas** | **Desbloqueada**: ya están en todos, que era la condición. Es una sola app para todos, por eso no podía salir antes |
| **El typo de `PapeleraCtrl:62`** en `myvc_front` | **Desbloqueado**: era lo único que tapaba `grupos/forcedelete` y su guard ya está desplegado |

## Paso 1. Los colegios

**Si un `git pull` imprime `composer.lock`, para en seco**: ese colegio venía atrasado y `vendor/` tiene su propio procedimiento. Lo demás es idempotente.

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  echo "=== $d"; cd "$d" || continue
  git pull                                        # trae código Y migraciones
  php artisan migrate --force                     # va aquí, no después
  php artisan config:clear;  php artisan route:clear
  php artisan config:cache;  php artisan route:cache
done
```

- Repítelo en la otra cuenta de cPanel (`lalvirtual.edu.co`): otro login, el `for` no la alcanza.
  Y los cinco de `vendor/` compartido —`coal`, `colbosque`, `comad-san-andres`, `eal`,
  `maranathaarauca`— van primero: son los que no se pueden escalonar.
- **Entre el `pull` y el `migrate` ese colegio da 500**: segundos, pero existen, así que no en
  horario de clase. **Si falla una de las dos mitades, para y arréglalo antes de seguir.**

## Paso 2. Comprobar

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-52s ' "$d"
  git -C "$d" log -1 --format='%h ' 2>/dev/null || { echo 'NO ES REPO GIT'; continue; }
  (cd "$d" && php artisan migrate:status | grep -c 'Ran')
done            # el mismo hash en todos, y el mismo conteo
```

**Mira el hash, no el conteo.** «Already up to date» sólo dice que ese colegio está donde apunta
**su** remoto, que no tiene por qué ser el `origin/main` recién actualizado: el 21 ago los
dieciséis lo dijeron minutos después de un `push`. Si no coincide, `remote -v` y `branch -vv`.

Y a mano en un colegio cualquiera, de lo más usado a lo más raro: **guardar una ficha de alumno**
—y volver a mirarla— · **abrir un boletín y volver a la planilla, también como acudiente** ·
**cambiar una nota y ver moverse la definitiva** · **enfermería sin el permiso**, que debe dar
mensaje y dejarte dentro · **login de personal y de alumno**.

## Paso 3. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Las migraciones del 25 ago se quedan puestas y por eso esto vale:** son aditivas y el código
viejo las ignora. **No corras el `down`.**

## Paso 4. Las tres trampas que muerden aquí

| Trampa | Qué pasa |
|---|---|
| **`composer` en un colegio con `vendor/` compartido** | le cambia las dependencias a los otros cuatro: sigue el symlink sin avisar y sin fallar. Comprueba antes con `[ -L vendor ]` |
| **Encadenar `artisan` con `&&`** | `php artisan config:clear && route:clear` **no funciona**: el segundo muere con `command not found` y la caché vieja sigue viva. Pasó en `coal` y el login dio 404 con el código bien desplegado. **Si un `artisan` no imprime su `INFO`, no corrió** |
| **`config:cache` antes de tocar el `.env`** | el colegio sirve la configuración anterior, sin ningún síntoma que lo delate |

Y si el comportamiento sigue siendo el viejo con el código en su sitio: **OPcache**, no el `.env`.

## El front

El bucle de `up/` y **la corrección del de `app2`** —el que había aquí sustituía el legacy en vez
de convivir con él, al revés de lo decidido el 25 ago— están en la
[referencia](DESPLIEGUE-REFERENCIA.md#front-up--solo-las-tandas-que-publican-front).
