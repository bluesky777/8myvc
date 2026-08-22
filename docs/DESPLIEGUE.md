# Desplegar

Los comandos de **la tanda que toca**, y nada más. Lo que se hace una sola vez
—PHP 8.4, el cron, el token de GitHub, la topología de `vendor/`—, las trampas
completas y lo que trajo cada tanda anterior están en
[DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

---

## Esta tanda: 21 ago 2026

Backend y nada más. **No hay que publicar nada en `myvc_front`, `myvc_front_2` ni
en la app de Flutter.**

- **Una decena de arreglos de autorización.** El más gordo: `GET api/alumnos`
  entregaba el directorio del colegio —nombre, fecha de nacimiento, celular,
  dirección, religión y deuda de cada alumno— a cualquier alumno o acudiente. Los
  demás, en [09-pendientes.md §0](migracion/09-pendientes.md).
- **Tres migraciones nuevas**, las tres aditivas:

  | Migración | Qué hace | Si no se corre |
  |---|---|---|
  | `..._create_rol_secretario` | crea la fila `Secretario` en `roles`. **No se la da a nadie**: el colegio decide después quién es su secretaria | nada se rompe; los once sitios que preguntan por ese rol siguen contestando `false`, como hasta hoy |
  | `..._add_username_to_password_reminders_table` | añade `username`, nullable | **la recuperación de contraseña cae entera**: el código nuevo inserta en una columna que no existe |
  | `..._add_deleted_at_to_frases_preescolar_table` | papelera para las frases del boletín de preescolar | borrar una frase da 500 |

> **Por eso `migrate --force` va pegado al `git pull`, no «para luego».** En
> cuanto el `app/` nuevo está en su sitio, `postRecuperarClave` escribe en la
> columna nueva desde la primera petición — y esa es la única vía que le queda al
> **91% de las cuentas** para recuperar su clave.

**Fuera de horario de clase**: son `ALTER TABLE` y en alojamiento compartido
tardan.

---

## 1. Los colegios, de una vez

**Si algún `git pull` imprime `composer.lock`, para en seco.** Esta tanda no
toca dependencias, así que no debería salir; si sale es que ese colegio venía
atrasado de una tanda anterior, y tocar `vendor/` tiene su propio procedimiento
—trampa 1 y la referencia—. Lo demás del bucle es idempotente: correrlo dos veces
no hace daño.

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  echo "=== $d"; cd "$d" || continue
  git pull                                        # trae código Y migraciones
  php artisan migrate --force                     # va aquí, no después
  php artisan config:clear;  php artisan route:clear
  php artisan config:cache;  php artisan route:cache
done
```

Repítelo en la otra cuenta de cPanel (`lalvirtual.edu.co`) con su propia ruta:
es otro login, así que el `for` no la alcanza.

### Los dieciséis de `micolev1`, y el que no entra

Leídos del servidor el 21 ago 2026, con el bucle de arriba. Los cinco que
**comparten `vendor/`** por symlink con `/home/micolev1/laravel_compartido` van
primero, porque son los únicos que no se pueden escalonar el día que haya que
tocar dependencias:

```
coal   colbosque   comad-san-andres   eal   maranathaarauca
```

Y los once de `vendor/` propio:

```
amiguitosdejesus   bethelexplora   cads-itagui   casb-medellin   caz-zaragoza
coabsaravena       coljordan       fortul        inseaq          instival
semillitasdedios
```

La ruta de cada uno es `/home/micolev1/<nombre>.micolevirtual.com/8myvc`. El
colegio es **`eal`**: el inventario viejo lo escribía así en un sitio y `lal` en
otros tres, y el bucle del 21 ago zanjó cuál de los dos existe aquí — `lal` está
en la otra cuenta.

> **`instival` no se despliega con este bucle y hay que mirarlo aparte.** El 21
> ago contestó `fatal: not a git repository` y, lo que es peor, `Could not open
> input file: artisan` cinco veces: en esa carpeta no hay ni repositorio ni
> aplicación. O sea que **no recibe ni código ni migraciones**, y se queda con lo
> que tuviera — arreglos de autorización incluidos. Ya salía como caso raro en el
> inventario del 18 ago («no es un repositorio git»), y el cierre del 19 que dio
> los 16 por desplegados **no lo comprobó**. Es el único colegio del que no se
> sabe qué está sirviendo.

---

## 2. Comprobar

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-52s ' "$d"
  git -C "$d" log -1 --format='%h ' 2>/dev/null || { echo 'NO ES REPO GIT'; continue; }
  (cd "$d" && php artisan migrate:status 2>/dev/null \
     | grep -cE 'Ran.*(rol_secretario|password_reminders|frases_preescolar)')
done            # el mismo commit en todos, y un 3 detrás
```

**Mira el commit, no solo el 3.** «Already up to date» significa que ese colegio ya
estaba donde apunta **su** remoto, que no tiene por qué ser el `origin/main` que
acabas de actualizar: el 21 ago los dieciséis dijeron «Already up to date» minutos
después de un `push`, y eso solo se distingue de un despliegue bueno mirando el
hash. Si no coincide, `git -C "$d" remote -v` y `git -C "$d" branch -vv`.

Y a mano, en el navegador de un colegio cualquiera: **login de personal, login de
alumno y recuperar contraseña**. Los tres tocan lo que cambió.

---

## 3. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Las tres migraciones no hay que deshacerlas**: una tabla de más y dos columnas
que admiten NULL, y el código viejo las ignora.

---

## 4. Las tres trampas que cuestan un colegio

Las siete completas están en la referencia; estas son las que muerden en un
despliegue como el de hoy.

1. **`composer` dentro de un colegio con `vendor/` compartido** le cambia las
   dependencias a los otros cuatro: sigue el symlink sin avisar y sin fallar.
   Comprueba antes con `[ -L vendor ]`.
2. **Cada comando con su `php artisan`.** `php artisan config:clear && route:clear`
   **no funciona**: el segundo muere con `command not found` y la caché vieja se
   queda viva. Pasó en `coal` y el login devolvió 404 con el código bien
   desplegado. Si un `artisan` de la cadena no imprime su `INFO`, no corrió.
3. **`config:cache` antes de tocar el `.env`** deja al colegio sirviendo la
   configuración anterior, sin ningún síntoma que lo delate.
