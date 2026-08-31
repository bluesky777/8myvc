# Desplegar

**Los comandos, y nada más.** El porqué de cada fila —topología, las siete trampas, qué trajo
cada tanda, el bucle del front— está en [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## La tanda: del 25 ago (`eb95cbc`) a `HEAD`

| | |
|---|---|
| Migraciones | **UNA, y bloqueante.** `2026_08_26_100000_interruptores_de_certificados`. Con el código y sin ella: **500 en todo boletín y todo certificado** |
| Rutas | **543** — una nueva, `PUT users/mi-docente` |
| Dependencias · `config/` | sin tocar |
| `app/` | **veintinueve** ficheros |

Recalcular si la tanda crece —**se remide, no se suma**:

```bash
git diff --name-only eb95cbc HEAD -- database/migrations/ composer.lock config/
git diff --name-only eb95cbc HEAD -- app/ | wc -l
```

**Qué se le nota a un colegio** y el detalle de los avisos:
[referencia § tanda del 25–28 ago](DESPLIEGUE-REFERENCIA.md#lo-que-trae-la-tanda-del-2528-ago-2026--del-25-ago-eb95cbc-a-head).

## Paso 0. La comprobación que va ANTES de esta tanda — un `SELECT`, quince veces

Sólo por el aviso **H**: `GET profesores` pasa a exigir `Autoriza::esAdministrativo`, que es
`is_superuser || Role::isSecretario`. **El rol `Admin` NO está dentro**, y `app2` sí le abre la
pantalla de Docentes a un `Admin`. Hoy eso no rompe nada **porque en la base medida los diez
`Admin` son exactamente los diez `is_superuser`** — es una coincidencia, no un criterio, y basta
un colegio que le haya puesto el rol `Admin` a alguien sin superusuario para que ese alguien
**pierda la pantalla** el día del despliegue.

**Eso no se puede medir desde el repositorio: cada colegio tiene su propia base.** Se mide aquí,
y tiene que dar **cero en los quince**:

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

- **Todo ceros** → despliega sin más; el aviso **H** no le quita la pantalla a nadie.
- **Algún colegio con un número** → **para**, y son esas personas exactamente las que se quedan
  sin Docentes. Las salidas son dos y las dos son de Joseth: darles `Secretario` (una fila, sin
  migración) o ensanchar el criterio. **No se despliega H a ciegas en ese colegio.**

> **Por qué se comprueba y no se ensancha `esAdministrativo` de una vez:** ese método lo leen seis
> sitios más —las masivas de `cambiar-usuarios/*` entre ellas—, así que meterle el rol `Admin`
> reparte permisos que nadie ha pedido en cinco puertas que no son ésta. Es literalmente lo que
> `create_rol_secretario` dejó escrito: **crear o ensanchar un criterio no puede regalar permisos
> por la puerta de atrás.** Si hay que ensancharlo, se decide y se repasan las seis.

El dato de partida, medido por `myvc-front-6b` y confirmado aquí sobre `simonbolivar`: **cero**
`Admin` sin superusuario, y el único `Coord disciplinario` (id 687, `convivencia2019(inhabilitado)`)
**es superusuario**, así que tampoco pierde el informe de la §243. **Es una base de quince.**

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

| | aviso | a quién | estado |
|---|---|---|---|
| **A** | los dos 403 de `cambiar-contador-*` — esconder el control, no cambiar la llamada | `myvc_front` · `app2` | **PENDIENTE** |
| **B** | veintiuna respuestas llevan dos campos nuevos; hay dos interruptores que ofrecer en configuración | `myvc_front` · `app2` | **PENDIENTE** |
| **C** | `aumentar_contador`: **omitir** la clave, no mandar `false` | `myvc_front` | **PENDIENTE** |
| **D** | `login/crear-prematricula` cambia el 500 por un 422 con mensaje | — | **NO REQUIERE TRABAJO** — medido |
| **E** | `notificaciones/temas`: `colegio` pasa de lista a objeto | `myvc_flutter` | **LO PIDIERON ELLOS** |
| **F** | `ausencias/store` rellena `fecha_hora` y la contesta en ISO | — | **NO REQUIERE TRABAJO** — medido |
| **G** | `PUT users/mi-docente` es NUEVA y `app2` **ya la llama**: sin este despliegue, elegir docente avisa de que no quedó guardado (404) | `app2` | **EL FRONT YA ESPERA A ESTO** |
| **H** | **Lleva el paso 0 delante.** `GET profesores` pasa a exigir **superusuario o `Secretario`**: un docente que la llamara recibe **403** donde recibía los 47 expedientes ([05 §243](migracion/05-codigo-muerto-y-roto.md)). **Ninguna pantalla de las tres que la consumen cambia** —las tres son de administración, medido en los cuatro clientes—, así que **no requiere trabajo del front**; va escrito porque **cambia quién recibe qué** y eso no se despliega en silencio | `myvc_front` · `app2` | **PENDIENTE** — avisar, sin trabajo |
| **I** | **Crear un año lectivo pasa de entregar UN periodo sin fechas a entregar CUATRO con fechas** ([05 §244](migracion/05-codigo-muerto-y-roto.md)), y copia diez columnas del año anterior que se perdían — tres de ellas se **imprimen en el certificado de estudio** y hasta hoy salían con el defecto del esquema («Privado», «A», «Mañana y tarde») fuera cual fuera el colegio. La respuesta de `POST years/store` **añade** `periodos`, que es lo que `years.html` ya recorre: hoy el año recién creado aparece sin periodos hasta recargar, y con esto aparece montado. Las asignaturas se copian ahora **con su `profesor_id`**, como ya hacía `asignaturas/copiar`: el docente sale en blanco en la rejilla hasta que se le haga el contrato del año nuevo, y entonces aparece solo. **Aditivo: ningún cliente pierde una clave**, así que no requiere trabajo del front — va escrito porque **cambia lo que el colegio recibe al abrir el año**, y eso no se despliega en silencio | `myvc_front` | **PENDIENTE** — avisar, sin trabajo |

| **J** | **La definitiva de una materia deja de ser un entero: `notas_finales.nota` pasa a `DECIMAL(7,4)`** y el cálculo deja de redondear. Sobre la base real son **96.608 de 125.352 definitivas (77,1 %)** las que hoy se guardan redondeadas, así que **el primer boletín después del despliegue traerá puestos distintos** a los del periodo anterior **sin que haya cambiado ninguna nota** — es el arreglo, no un efecto secundario: `Nota::puestoAlumno` cuenta a cuántos les gana el promedio, y los empates salían de la columna. **Ninguna clave se añade, se quita ni se renombra**: los siete campos afectados (`nota`, `nota_asignatura`, `nota_final`, `nota_final_year`, `DefMateria`, `sumatoria`, `promedio_year`) **siguen viajando como número**, y eso costó castear ~40 lecturas — sin los `CAST`, PDO devuelve `DECIMAL` como **cadena** y el JSON habría pasado de `45` a `"43.7500"` en 17 respuestas. **FLUTTER NO LANZA EXCEPCIÓN — ESTA FILA DECÍA QUE SÍ Y ERA MÍO EL ERROR.** Escribí que `json['nota'] as int` reventaría: el hecho de Dart es cierto, pero **lo apliqué a un código que no había mirado**. Medido en `myvc_flutter/lib` (112 clases): **cero `as int`, cero `as double`**, los tres `toInt()` guardados por `is num`, las notas leídas por `_decimal()` —que traga `num` **y** cadena— y los campos declarados `double`. Lo midió `myvc-front-b8` y lo confirmé contra el fichero. **Así que no bloquea.** Lo que sí necesita trabajo de Flutter —**después de esta migración, nunca antes; ver el orden bajo la tabla**— y por una razón más seria que pintar, es esto: **`LibroNotasApi.dart:439` replica en Dart el `cast` que esta migración cambia** —su propio comentario dice *«el backend … castea a `DECIMAL(4,0)`. Aquí se hace lo mismo para que lo que se ve sea lo que hay guardado y no una aproximación parecida»*— y hace `promedio.roundToDouble()` al guardar una nota. Con la migración puesta, la app enseñaría **44** mientras el servidor guarda **43,75**: exactamente la «aproximación parecida» que ese código existe para evitar, sin error y hasta la siguiente recarga. **Y el pintado, que es lo cosmético:** hay **cinco** formateadores; **tres** dan un decimal (`toStringAsFixed(1)` → `43.8` donde hoy `44`) y **dos** —`LibroNotasApi:841` y `UnidadesScreen:1027`— caen en `toString()` y sacarían **`43.75` entero**. **PERO EL DE `LibroNotasApi:841` NO SE ARREGLA EN EL FORMATEADOR, Y ESTA FILA LO LLEGÓ A SUGERIR:** se llama `notaEscrita`, va emparejado con `notaLeida` y su docblock dice que es *«cómo se escribe una nota **dentro de un campo**»* — alimenta **seis `TextEditingController`** (`PlanillaScreen:149,211`, `FichaAlumnoNotasScreen:135,142,262`, `NotasPerdidasScreen:177`) y sólo **dos** usos de pintar (`LibroAsignaturaScreen:453`, y el aviso «Guardada:» de `NotasPerdidasScreen:199`). **Redondearlo ahí reintroduciría desde el cliente justo el redondeo que esta migración quita**: abrir la planilla y guardar convertiría un 43,75 en 44. **El sitio a mirar es quien lo llama para pintar —`LibroAsignaturaScreen:453`, la definitiva en grande—, no el formateador**, que ahí es lo único que está bien. Lo señaló `myvc-front-b8` con la sesión de Flutter delante; verificado aquí contra el fichero, y son **seis** casillas de edición, no cuatro. `myvc_flutter` es **una sola app para los quince** | `myvc_flutter` (**DESPUÉS del backend, ver el orden**) · `myvc_front` · `app2` (**hecho**: pipe `\| nota`) | **PENDIENTE** — no bloquea; **el orden importa más que la prisa** |

### El orden del aviso **J**, y va al revés de lo que parece

**Hoy el cliente y el servidor redondean los dos, así que coinciden.** Ése es el único motivo por el
que `trasRecalcularse` no está mal hoy: **está atada a un contrato que hoy sigue vigente**. Así que
el orden no es una preferencia, es lo que decide quién ve un número falso y cuántos:

| | qué | por qué ahí |
|---|---|---|
| **1** | `app2` | independiente y **ya hecho**: el pipe redondea igual antes que después, no abre ninguna ventana |
| **2** | **este backend**, en los quince, **verificado** | mientras rueda, la app sigue redondeando como hoy: tras guardar una nota puede enseñar `44` con `43,75` guardado, **hasta la siguiente recarga**. Es la ventana mala **pequeña**, y es inevitable |
| **3** | `myvc_flutter`: quitar el `roundToDouble()` | **sólo con el 2 confirmado**, y contra **el hash de la tanda, no contra `main`** |

**Hacer el 3 antes que el 2 es el error caro, y por poco lo escribo yo.** El cliente enseñaría
`43,75` con el servidor guardando `44` — la misma divergencia en el otro sentido, pero **de golpe en
los quince colegios**: `myvc_flutter` es **una sola app publicada por Play**, mientras que esto son
**quince despliegues** que tardan días. La ventana del 2 la abre un colegio cada vez y se cierra
recargando; la del 3-antes-que-2 la abren todos a la vez y no se cierra sola.

Por eso Flutter propone escribir ya la línea sin redondeo **detrás de un interruptor apagado** —como
`PendientesUsuarios`— y encenderlo cuando el 2 esté comprobado. Lo corrigieron ellos: el orden que
esta sección decía antes («backend y Flutter primero») era el que abre la ventana grande.

Y los dos que `myvc_flutter` pidió por su nombre:

- **`b369020` entra en esta tanda → decirles el hash desplegado.** Tienen un interruptor apagado
  esperándolo. No hay ventana rota: leen las dos formas.
- **El día que se corra el `for` de la fase 0 → pasarles el desglose por año del bloque 5.**

Lo mismo en `docs/migracion/ESTADO-ACTUAL.md`, que lleva su propia copia.

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
| **`composer` en un colegio con `vendor/` compartido** | le cambia las dependencias a los otros cuatro: sigue el symlink sin avisar y sin fallar. Comprueba antes con `[ -L vendor ]` |
| **Encadenar `artisan` con `&&`** | `php artisan config:clear && route:clear` **no funciona**: el segundo muere con `command not found` y la caché vieja sigue viva. Pasó en `coal` y el login dio 404 con el código bien desplegado. **Si un `artisan` no imprime su `INFO`, no corrió** |
| **`config:cache` antes de tocar el `.env`** | el colegio sirve la configuración anterior, sin ningún síntoma que lo delate |

Y si el comportamiento sigue siendo el viejo con el código en su sitio: **OPcache**, no el `.env`.

## El front

Otro bucle, y esta tanda **no lo necesita**: los arreglos de `myvc_front` que salieron de camino
(`8321f9a5`) son independientes en las dos direcciones. El bucle de `up/` y la corrección del de
`app2` están en la
[referencia](DESPLIEGUE-REFERENCIA.md#front-up--solo-las-tandas-que-publican-front).
