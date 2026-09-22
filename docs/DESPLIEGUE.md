# Desplegar

**Los comandos, y nada más.** El porqué de cada fila —topología, las siete trampas, qué trajo
cada tanda, el bucle del front— está en [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## ⛔ TANDA PENDIENTE — 81 commits desde `e7ed5e75`, y **ninguna toca una fila que ya exista**

**Medido el 22 sep 2026 sobre `e7ed5e75..9028607`**, que es el hash desplegado el domingo contra el
`main` de hoy. Se remide el día de salir: la tanda anterior se escribió aquí con 321 commits y
siete migraciones y salió con **785** y **34**.

| | | recalcular con |
|---|---|---|
| commits | **81** | `git rev-list --count e7ed5e75..origin/main` |
| **migraciones** | **TRES**, y las tres **aditivas puras** | `git diff --name-only e7ed5e75 main -- database/migrations/` |
| `app/` | **85** ficheros | `git diff --name-only e7ed5e75 main -- app/ \| wc -l` |
| Rutas | **647 → 665** — 18 nuevas, **ninguna retirada** | `tests/Contrato/Snapshots/rutas.json` |
| `config/` | **dos**: `cors.php` e `importacion.php` | `git diff --name-only e7ed5e75 main -- config/` |
| `composer.json` · `.lock` · `database/schema/` | sin tocar | `git diff --name-only e7ed5e75 main -- composer.json composer.lock database/schema/` |

### Las tres, y por qué el Paso 0 sale en verde

```
  VERDE  2026_09_21_100000_descargas_de_planilla
  VERDE  2026_09_21_200000_el_valor_entero_de_la_auditoria
  VERDE  2026_09_21_300000_lo_que_la_importacion_hizo
```

Una tabla nueva y dos columnas nuevas. **Ninguna escribe sobre filas que ya existan**, así que esta
vez el `UPDATE` que hay que mirar antes de migrar no existe — al revés que la tanda del 20 sep, que
salió con uno que vació 407.909 casillas.

> **Eso NO es permiso para saltarse el Paso 0.** El verde de arriba es de la copia de desarrollo y
> dice qué hacen **estas tres**; lo que el Paso 0 contesta es qué está pendiente **en esa base**, y
> un colegio que se quedara fuera del `git pull` del domingo tiene por delante las 34 de aquella
> tanda, con `la_casilla_vacia` dentro. La lista de pendientes es de cada colegio, no del
> repositorio.

### Lo que sí hay que mirar de esta tanda

| | |
|---|---|
| `config/cors.php` e `config/importacion.php` | viajan en el `app/`, pero un `config:cache` con el `.env` viejo sirve la configuración anterior sin ningún síntoma. Los cuatro `artisan` del Paso 1, en orden |
| **18 rutas nuevas y ninguna retirada** | aditivo puro: ningún cliente pierde una clave. `planilla-offline/*` (6), `auditoria/*` (4), fusión de alumnos (4), `documento-como-username` (2), notas del grupo anterior (2) |
| `descargas_de_planilla` va **antes** que el `app/` | las dos rutas que bajan fichero dan 500 sin esa tabla — `migracion/49-la-planilla-sin-internet.md:268`. Con el orden del Paso 1 (`git pull` y `migrate` pegados) ya se cumple |
| el front **no** acompaña a ésta | `myvc_dist` está construido en `myvc_front f75fd5c2` y `main` va muy por delante. Las pantallas que estrenan estas rutas —planilla sin internet, columna «Historial»— **no se ven hasta que se publique el front** |

## La tanda ANTERIOR — desplegada el 20 sep 2026 a las 23:51 en `e7ed5e75`

De `9474b50` a **`e7ed5e75`**, 785 commits y 34 migraciones: los dieciséis de `micolev1` a las
**23:51 -0400** y `micolevi` a las **23:59**. Comprobar qué queda por salir:

```bash
git fetch origin && git log --oneline e7ed5e75..origin/main
```

> **Y salió mal.** `2026_09_19_500000_la_casilla_vacia` vació **407.909** casillas de `notas` en
> **catorce** colegios —**225.247** no valían cero—, y se supo a la mañana siguiente porque
> CADS-Itagüí lo reportó. El censo base por base y el arreglo están en
> [doc 43 §incidente](migracion/43-lo-que-todavia-no-se-ha-calificado.md); de ahí salen el **Paso
> 0** de abajo y [RESPALDOS.md](RESPALDOS.md), que son las dos cosas que no había esa noche.

Qué trajo y el documento entero de aquella noche:
[referencia § la tanda del 31 ago – 20 sep](DESPLIEGUE-REFERENCIA.md#lo-que-trajo-la-tanda-del-31-ago--20-sep-2026--desplegada-el-20-sep-en-e7ed5e75).

Para la tanda siguiente, **con el comando y no a ojo** (`<base>` = el último hash desplegado):

```bash
git diff --name-only <base> HEAD -- database/migrations/ composer.lock config/
git diff --name-only <base> HEAD -- app/ | wc -l
```

## Paso 0. Qué va a pisar esta tanda, y el respaldo — **colegio por colegio, antes del `git pull`**

> **Este paso nació del 20 sep 2026.** Esa noche la tanda vació 407.909 casillas de
> `notas` en catorce colegios, y nadie lo supo hasta que CADS-Itagüí lo reportó a la
> mañana siguiente. El relleno estaba decidido y documentado; lo que no había era
> **un momento en el que alguien viera el número antes**, ni copia de seguridad
> previa —`docs/migracion/43-lo-que-todavia-no-se-ha-calificado.md:123`—. Los dos
> huecos son este paso.

### Dónde se está parado

Por **SSH al alojamiento compartido**, con el usuario de esa cuenta de cPanel, y **dentro de la
carpeta de un colegio** — la que tiene el `artisan` dentro:

```bash
cd ~/cads-itagui.micolevirtual.com/8myvc     # una cualquiera; se repite en las diecisiete
```

Ahí, **antes de tocar nada**, dos comandos y en este orden:

```bash
php tools/riesgo-de-la-tanda.php        # ¿qué filas que ya existen pisa la tanda AQUÍ?
tools/respaldo-antes-de-migrar.sh       # sólo si el de arriba salió con 2
```

> ### ⚠️ La PRIMERA vez esto no se puede correr, y es esta vuelta
>
> Los dos guiones **viven en el repositorio**, así que llegan al servidor con el mismo `git pull`
> que va a desplegar la tanda. La noche que los estrena todavía no están ahí. No tiene arreglo y
> no es un fallo: una comprobación que viaja con el código no puede correr antes de sí misma.
>
> **El sustituto para esta vuelta**, que contesta la única pregunta que hoy importa —*¿está este
> colegio donde creo que está?*— es **el hash**:
>
> ```bash
> for d in ~/*/8myvc; do printf '%-52s ' "$d"; git -C "$d" rev-parse --short HEAD; done
> ```
>
> **Tiene que dar `e7ed5e7` en todos.** El que salga con otro se quedó fuera del `git pull` del 20
> sep y tiene `la_casilla_vacia` por delante: a ése no lo migres hasta mirarlo aparte.
>
> > **Y NO sirve `migrate:status | grep -c Pending`, que es lo que este documento decía hasta el
> > 22 sep por la tarde.** Corrido en los diecisiete dio **0** en todos, y el 0 es correcto:
> > `migrate:status` lista **las migraciones que hay en el disco**, y las tres de esta tanda llegan
> > con el `git pull` que todavía no se ha hecho. Un colegio atrasado daría 0 también, con lo que
> > el instrumento era ciego justo para lo que se le preguntaba. *El 0 sí dice algo: ningún colegio
> > tiene migraciones sin correr de su propio código.*
>
> Y el respaldo de esta vuelta **ya está hecho** —18 bases, 22 sep— con el bucle suelto de
> [RESPALDOS.md](RESPALDOS.md), que es el mismo `mysqldump` sin necesitar el repositorio.
>
> **De la tanda siguiente en adelante**, los dos guiones ya están en cada carpeta y el paso se
> corre tal cual.

El primero lee las migraciones pendientes **de esta base**, deduce de cada operación
peligrosa la consulta que la cuenta sin ejecutarla, y la corre dentro de un `START
TRANSACTION READ ONLY`. Sale con **0** si la tanda no toca ninguna fila que ya exista
y con **2** si la toca, para que un despliegue encadenado con `&&` se pare solo:

```
  ROJO   2026_09_19_500000_la_casilla_vacia
        ALTER sobre `notas` ............................ 469.086 filas
        UPDATE sobre `notas` ........................... 12.632 filas
        └─ el down() reescribe filas con un valor fijo: NO devuelve el valor anterior
```

**La cifra es por colegio y no se puede sacar del repositorio**: son dieciséis bases
distintas, y en el censo del 21 sep iban de 0 en `coal_bucara` a 79.223 en `coljordan`. Por eso
el paso va dentro del bucle y no antes de él.

**ROJO no quiere decir «está mal»** —`la_casilla_vacia` era exactamente lo que había que
hacer— sino *«esto cambia filas que ya existen: míralo ahora, que después no hay
decisión que tomar»*. Y cuando el `down()` escribe un literal, el aviso de la última
línea es literal también: **el `rollback` no devuelve esas filas, sólo las aproxima**.
Lo único que las devuelve es el respaldo.

Lo que el detector **no** ve, y por eso sigue haciendo falta leer la tanda: que el
código nuevo empiece a escribir mal *después* de migrar. Cuenta lo que la migración
toca, no lo que el `app/` hará con el esquema nuevo.

Los dieciséis en una pasada, para decidir el orden antes de empezar:

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '\n===== %s\n' "$d"
  ( cd "$d" && php tools/riesgo-de-la-tanda.php --callado )
done            # repetir en la otra cuenta de cPanel (lalvirtual.edu.co)
```

**El respaldo de este paso no es el de madrugada.** `tools/respaldo-diario-cpanel.sh`
—el cron de la cuenta— cubre *«ayer funcionaba»*; restaurar el de anoche para deshacer
un `migrate` del mediodía **borra las notas que los docentes pusieron esa mañana**. El
de aquí es de hace treinta segundos y es el único que deshace una migración sin coste.

## Paso 1. Los colegios

**Si un `git pull` imprime `composer.lock`, para en seco**: ese colegio venía atrasado y
`vendor/` tiene su propio procedimiento. Lo demás es idempotente.

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
- Los **seis** de `vendor/` compartido —`coal`, `colbosque`, `comad-san-andres`, `eal`,
  `maranathaarauca` y **`lal`** (desde el 30 ago 2026, al montarlo en la cuenta de
  `micolev1`)— van **primero**: son los que no se pueden escalonar.
- **Entre el `pull` y el `migrate` ese colegio da 500**: segundos, pero existen, así que no en
  horario de clase. **Si falla una de las dos mitades, para y arréglalo antes de seguir.**

> **Y si la tanda cambia quién puede llamar a algo, la comprobación va ANTES del bucle y se hace
> colegio a colegio.** No es una precaución genérica: **cada colegio tiene su propia base y eso no
> se puede medir desde el repositorio.** El caso vivido está en la
> [referencia § el `SELECT` que fue delante](DESPLIEGUE-REFERENCIA.md#el-select-que-fue-delante-del-bucle-el-31-ago-aviso-h) —
> un aviso de autorización cuyo criterio dependía de qué roles tuviera puestos cada colegio.

## Paso 2. Comprobar

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-52s ' "$d"
  git -C "$d" log -1 --format='%h ' 2>/dev/null || { echo 'NO ES REPO GIT'; continue; }
  (cd "$d" && php artisan migrate:status | grep -c 'Ran')
done            # el mismo hash en todos, y el mismo conteo
```

**Mira el hash, no el conteo.** «Already up to date» sólo dice que ese colegio está donde apunta
**su** remoto, que no tiene por qué ser el `origin/main` recién actualizado. Si no coincide,
`remote -v` y `branch -vv`.

**Y la comprobación de esquema, si la tanda mueve columnas.** No se escribe a mano: se genera
restando dos `information_schema` —la base migrada menos la desplegada— y sale un `tinker
--execute` que pregunta por cada columna y cada tabla nueva. La de la tanda del 20 sep, con sus 69
preguntas y su control negativo, está en la
[referencia](DESPLIEGUE-REFERENCIA.md#la-comprobación-de-diez-segundos-que-ya-no-son-siete-migraciones-sino-treinta-y-cuatro).
Para la de hoy son **una tabla y dos columnas**, así que la pregunta cabe en el `migrate:status`
de arriba.

Y a mano en un colegio cualquiera, de lo más usado a lo más raro: **guardar una ficha de alumno**
—y volver a mirarla— · **abrir un boletín y volver a la planilla, también como acudiente** ·
**cambiar una nota y ver moverse la definitiva** · **enfermería sin el permiso**, que debe dar
mensaje y dejarte dentro · **login de personal y de alumno**.

## Paso 3. Cerrar los avisos — **en el mismo commit, no en uno aparte**

**El despliegue no ha terminado cuando los dieciséis tienen el hash.** Termina cuando el documento
deja de prometer cosas que ya ocurrieron: *un pendiente escrito en futuro no envejece a «hecho»,
envejece a mentira*. Cada fila pasa a `DADO el <fecha>` o se borra, y **se le dice al cliente**:
que se entere el documento no es que se entere quien tiene que publicar.

### ⛔ Los avisos de la tanda del 20 sep — **este documento no los cerró**

La tanda salió el 20 sep a las 23:51 y **el documento se quedó como estaba esa tarde**: su último
commit es del 20 sep por la mañana. Los avisos que viajaban en ella están en la
[referencia § los avisos para el front](DESPLIEGUE-REFERENCIA.md#los-avisos-para-el-front-que-viajan-en-esta-tanda),
donde se movió el documento entero, y **ninguno tiene fecha de cerrado**. Eso no quiere decir que
no se hicieran: quiere decir que nadie lo escribió, que es exactamente lo que este paso existe para
impedir — *un pendiente escrito en futuro no envejece a «hecho», envejece a mentira*.

Cerrarlos uno por uno, con su fecha, es trabajo de la próxima vuelta; no se puede hacer desde el
repositorio porque lo que decide es si se le dijo al cliente.

### Los diez de la tanda del 25–30 ago — cerrados el 31 ago 2026

| | aviso | estado |
|---|---|---|
| **A** | los dos 403 de `cambiar-contador-*` — esconder el control | **DADO el 31 ago 2026** · `myvc_front` y `app2`, desplegados en la misma vuelta |
| **B** | veintiún respuestas con dos campos nuevos; dos interruptores que ofrecer en configuración | **DADO el 31 ago 2026** · ídem |
| **C** | `aumentar_contador`: **omitir** la clave, no mandar `false` | **DADO el 31 ago 2026** · ídem |
| **D** | `login/crear-prematricula` cambia el 500 por un 422 con mensaje | **NO REQUERÍA TRABAJO** — medido |
| **E** | `notificaciones/temas`: `colegio` pasa de lista a objeto | **DADO** — lo pidieron ellos, y el hash ya está en los quince |
| **F** | `ausencias/store` rellena `fecha_hora` y la contesta en ISO | **NO REQUERÍA TRABAJO** — medido |
| **G** | `PUT users/mi-docente` es NUEVA y `app2` ya la llamaba | **DADO el 31 ago 2026** — el 404 de «no quedó guardado» se acabó al desplegar |
| **H** | `GET profesores` pasa a exigir superusuario o `Secretario` | **DADO el 31 ago 2026** — avisado; ninguna pantalla cambió, y el `SELECT` previo fue delante |
| **I** | crear un año lectivo entrega cuatro periodos con fechas y copia diez columnas | **DADO el 31 ago 2026** — avisado; aditivo, ningún cliente perdió una clave |
| **J** | `notas_finales.nota` pasa a `DECIMAL(7,4)` y el cálculo deja de redondear | **backend DADO el 31 ago 2026** — pero el aviso **sigue vivo por el lado de Flutter**, abajo |

### Lo único que queda vivo: el paso 3 del aviso **J**, y ahora sí toca

El orden de J era **`app2` → backend en los quince, verificado → `myvc_flutter`**, y hacer el
tercero antes que el segundo era el error caro. **Los dos primeros están hechos**, así que el
tercero pasa de «prohibido» a «lo siguiente»:

| | qué | estado |
|---|---|---|
| **1** | `app2`: el pipe `\| nota` | **HECHO** |
| **2** | este backend en los quince, verificado | **HECHO el 31 ago 2026**, en `9474b50` |
| **3** | `myvc_flutter`: quitar el `roundToDouble()` de `LibroNotasApi.dart:439` | **DESBLOQUEADO** — contra el hash desplegado, que desde el 20 sep es **`e7ed5e75`**, no contra `main` |

Mientras el 3 no salga, **la app enseña `44` con `43,75` guardado tras guardar una nota y hasta la
siguiente recarga**. Es la ventana pequeña y conocida: se cierra recargando, y era el precio
elegido a propósito frente a la otra, que se habría abierto en los quince a la vez. Y el sitio a
mirar para pintar es **quien llama a `notaEscrita`** (`LibroAsignaturaScreen:453`), **no el
formateador** — redondear ahí reintroduciría desde el cliente el redondeo que esta migración quita,
porque ese mismo formateador alimenta seis casillas de edición.

### Y lo que hay que decirle a `myvc_flutter`

| | qué | estado |
|---|---|---|
| **`b369020` desplegado** | su `temasDelColegio` está detrás de un interruptor apagado esperando exactamente este hash. Comprobado: `b369020` es ancestro de `9474b50`, y `9474b50` lo es de `e7ed5e75` | **PENDIENTE de decírselo** — el hash desplegado es **`e7ed5e75`** |
| el desglose por año del bloque 5 | notas fuera de escala; el dato que decide si aquello fue una precaución o un susto. La pregunta la abrieron ellos, ver [05 §240](migracion/05-codigo-muerto-y-roto.md) | **PENDIENTE** — el día que se corra el `for` de la fase 0 |

## Paso 4. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Las migraciones se quedan puestas y por eso esto vale:** son aditivas y el código viejo las
ignora. **No corras el `down`.**

> ### La excepción de agosto ya no aplica, y conviene saber por qué aplicaba
>
> La tanda del 20 sep traía `2026_08_31_100000_retirar_boletin_independiente_de_matriculas`, que
> **retira** una columna que el código de entonces nombraba en cinco consultas vivas: volver un
> colegio atrás dejando la migración puesta le dejaba los boletines en 500. El procedimiento de
> aquel `migrate:rollback` —y el aviso de que **`--step` cuenta migraciones y no lotes**— está en
> la [referencia](DESPLIEGUE-REFERENCIA.md#lo-que-trajo-la-tanda-del-31-ago--20-sep-2026--desplegada-el-20-sep-en-e7ed5e75).
>
> **La tanda pendiente de hoy no tiene esa forma**: sus tres migraciones son una tabla nueva y dos
> columnas nuevas, así que el código viejo las ignora y el `git checkout` de arriba basta. La regla
> vuelve a ser la de siempre, y sigue siendo **no correr el `down`**.
>
> El día que vuelva a haber un `dropColumn` en una tanda, quien lo note primero es el Paso 0: sale
> en ROJO y con la cuenta de filas de esa columna delante.

## Paso 5. Las tres trampas que muerden aquí

| Trampa | Qué pasa |
|---|---|
| **`composer` en un colegio con `vendor/` compartido** | le cambia las dependencias a los otros cinco: sigue el symlink sin avisar y sin fallar. Comprueba antes con `[ -L vendor ]` |
| **Encadenar `artisan` con `&&`** | `php artisan config:clear && route:clear` **no funciona**: el segundo muere con `command not found` y la caché vieja sigue viva. Pasó en `coal` y el login dio 404 con el código bien desplegado. **Si un `artisan` no imprime su `INFO`, no corrió** |
| **`config:cache` antes de tocar el `.env`** | el colegio sirve la configuración anterior, sin ningún síntoma que lo delate |

Y si el comportamiento sigue siendo el viejo con el código en su sitio: **OPcache**, no el `.env`.

## El front — **esta vuelta SÍ lo publica, y por las DOS carpetas**

No es opcional esta vez: el arreglo de «lo no calificado» está partido entre el backend y el
navegador, y la mitad del navegador **toca las dos aplicaciones** — `app/scripts/notas/NotasCtrl.ts`
(la vieja) y `app2/src/app/paginas/notas/promedio-ponderado.ts` (la nueva). Publicar sólo una deja
al docente viendo un Total que cuenta los blancos como cero junto a una definitiva que no.

**1. Construir y publicar los dos artefactos**, en *Actions* del repositorio del front, a mano
(`workflow_dispatch`):

| | escribe en | y de ahí a | |
|---|---|---|---|
| `desplegar-up` | `myvc_dist` | `up/` | **la aplicación vieja, que es lo que los dieciséis están viendo ahora mismo.** Corre el circuito entero antes de publicar, a propósito: una publicación mala aquí es una caída para todos a la vez |
| `desplegar-up2` | `myvc_dist2` | `up2/` | `app2` |

> **`up/` y `up2/` son dos artefactos distintos y ya mordió una vez.** El arreglo del «Por:
> undefined» del 6 sep viajaba por `myvc_dist` a `up/`, y subir sólo `up2/` lo habría dejado
> puesto. Lo que está en `app/` no llega por `up2/` ni al revés.

**2. Las dos carpetas en cada colegio**, con el mismo bucle del backend:

```bash
for d in ~/*/up ~/*/up2; do
  [ -d "$d/.git" ] || continue
  printf '%-52s ' "$d"
  git -C "$d" fetch -q origin
  git -C "$d" checkout -f -B main origin/main -q
  git -C "$d" clean -fd -q           # NO uses -x: se llevaría el logo del colegio
  grep -o 'assets/index-[^"]*\.js' "$d/index.html" | head -1
done            # y otra vez en la cuenta de `lalvirtual.edu.co`
```

**3. La huella tiene que ser la misma en todos.** Es la línea que imprime el bucle: si un colegio
sale con otro `index-*.js`, ése se quedó en el build viejo. El `git clean` sin `-x` es a propósito
—el logo de cada colegio no está versionado— y si `git` se queja de un `assets/index-*.js`
modificado, **cópialo antes de forzar**: minificado es una sola línea, así que el `diff` no dice
qué se pierde.

**El orden con el backend:** los dos en la misma noche, backend primero. Cualquiera de los dos
solo abre la ventana de números que no cuadran; lo que no se puede es dejarla abierta hasta mañana.

> **`demo` entra en los bucles por la carpeta pero no está en las listas de colegios de este
> documento**, y el 29 ago 2026 se descubrió atrasada a mano. El `~/*/up` de arriba la coge; las
> listas escritas, no.

