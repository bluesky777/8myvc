# Desplegar

**Los comandos, y nada más.** El porqué de cada fila —topología, las siete trampas, qué trajo
cada tanda, el bucle del front— está en [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## No hay tanda pendiente — 31 ago 2026

La del 25–30 ago (de `eb95cbc` a **`9474b50`**, 44 commits) **está desplegada**: los quince
colegios del bucle de `micolev1` **y** la cuenta de `lalvirtual.edu.co`, con el front de la misma
vuelta. Comprobar que sigue sin haber nada que salga:

```bash
git fetch origin && git log --oneline 9474b50..origin/main
```

**Lo que se midió al desplegar, y por qué se remide:** la tabla que había aquí decía **UNA**
migración y **veintinueve** ficheros de `app/`; el día del despliegue eran **DOS** y **treinta y
ocho**. No es que la cifra envejeciera: la tanda **creció** después de escribirla, que es
exactamente para lo que está la regla *se remide, no se suma*. Lo desplegado:

| | |
|---|---|
| Migraciones | **DOS, las dos bloqueantes** — `2026_08_26_100000_interruptores_de_certificados` y `2026_08_30_200000_notas_finales_en_decimal` |
| Rutas | **543** — una nueva, `PUT users/mi-docente` |
| Dependencias · `config/` | sin tocar |
| `app/` | **treinta y ocho** ficheros |

Para la tanda siguiente, **con el comando y no a ojo** (`<base>` = el último hash desplegado):

```bash
git diff --name-only <base> HEAD -- database/migrations/ composer.lock config/
git diff --name-only <base> HEAD -- app/ | wc -l
```

Qué trajo, colegio a colegio: [referencia § la tanda del 25–30 ago](DESPLIEGUE-REFERENCIA.md#lo-que-trajo-la-tanda-del-2530-ago-2026--desplegada-el-31-ago-en-9474b50).

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

Y a mano en un colegio cualquiera, de lo más usado a lo más raro: **guardar una ficha de alumno**
—y volver a mirarla— · **abrir un boletín y volver a la planilla, también como acudiente** ·
**cambiar una nota y ver moverse la definitiva** · **enfermería sin el permiso**, que debe dar
mensaje y dejarte dentro · **login de personal y de alumno**.

## Paso 3. Cerrar los avisos — **en el mismo commit, no en uno aparte**

**El despliegue no ha terminado cuando los quince tienen el hash.** Termina cuando el documento
deja de prometer cosas que ya ocurrieron: *un pendiente escrito en futuro no envejece a «hecho»,
envejece a mentira*. Cada fila pasa a `DADO el <fecha>` o se borra, y **se le dice al cliente**:
que se entere el documento no es que se entere quien tiene que publicar.

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
| **3** | `myvc_flutter`: quitar el `roundToDouble()` de `LibroNotasApi.dart:439` | **DESBLOQUEADO** — contra el hash **`9474b50`**, no contra `main` |

Mientras el 3 no salga, **la app enseña `44` con `43,75` guardado tras guardar una nota y hasta la
siguiente recarga**. Es la ventana pequeña y conocida: se cierra recargando, y era el precio
elegido a propósito frente a la otra, que se habría abierto en los quince a la vez. Y el sitio a
mirar para pintar es **quien llama a `notaEscrita`** (`LibroAsignaturaScreen:453`), **no el
formateador** — redondear ahí reintroduciría desde el cliente el redondeo que esta migración quita,
porque ese mismo formateador alimenta seis casillas de edición.

### Y lo que hay que decirle a `myvc_flutter`

| | qué | estado |
|---|---|---|
| **`b369020` desplegado** | su `temasDelColegio` está detrás de un interruptor apagado esperando exactamente este hash. Comprobado: `b369020` es ancestro de `9474b50` | **PENDIENTE de decírselo** — el hash es **`9474b50`** |
| el desglose por año del bloque 5 | notas fuera de escala; el dato que decide si aquello fue una precaución o un susto. La pregunta la abrieron ellos, ver [05 §240](migracion/05-codigo-muerto-y-roto.md) | **PENDIENTE** — el día que se corra el `for` de la fase 0 |

## Paso 4. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Las migraciones se quedan puestas y por eso esto vale:** son aditivas y el código viejo las
ignora. **No corras el `down`.**

## Paso 5. Las tres trampas que muerden aquí

| Trampa | Qué pasa |
|---|---|
| **`composer` en un colegio con `vendor/` compartido** | le cambia las dependencias a los otros cinco: sigue el symlink sin avisar y sin fallar. Comprueba antes con `[ -L vendor ]` |
| **Encadenar `artisan` con `&&`** | `php artisan config:clear && route:clear` **no funciona**: el segundo muere con `command not found` y la caché vieja sigue viva. Pasó en `coal` y el login dio 404 con el código bien desplegado. **Si un `artisan` no imprime su `INFO`, no corrió** |
| **`config:cache` antes de tocar el `.env`** | el colegio sirve la configuración anterior, sin ningún síntoma que lo delate |

Y si el comportamiento sigue siendo el viejo con el código en su sitio: **OPcache**, no el `.env`.

## El front

Otro bucle. **La vuelta del 31 ago sí lo publicó** —ahí salieron los avisos A, B y C, y de camino
los dos arreglos independientes de la prematrícula del login (`8321f9a5`)—. El bucle de `up/` y la
corrección del de `app2` están en la
[referencia](DESPLIEGUE-REFERENCIA.md#front-up--solo-las-tandas-que-publican-front).
