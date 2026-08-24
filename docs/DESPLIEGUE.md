# Desplegar

Los comandos de **la tanda que toca**, y nada más. Lo que se hace una sola vez
—PHP 8.4, el cron, el token de GitHub, la topología de `vendor/`—, las trampas
completas y lo que trajo cada tanda anterior están en
[DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

> **Este documento se vació y se rehízo el 24 ago 2026**, a petición de Joseth:
> llevaba dos tandas apiladas —la del 22 y la del 23— cada una diciendo «si la
> anterior no llegó a desplegarse», y eso obliga a leer dos listas y cruzarlas
> para saber qué se nota. **Aquí va una sola tanda con todo lo pendiente dentro.**
> El detalle hallazgo a hallazgo no se ha perdido: está en el
> [05](migracion/05-codigo-muerto-y-roto.md) y en
> [`que-se-nota-en-un-colegio.md`](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md),
> y las tandas ya desplegadas siguen en la referencia.

---

## La tanda pendiente: del 22 al 24 de agosto de 2026, toda de una vez

Es lo acumulado desde el último despliegue, el del **21 ago**. Backend y nada
más. Los cuatro hechos que deciden cómo se despliega, **medidos sobre el rango
entero** —no sumando tanda a tanda— y con el comando al lado para recalcularlos
el día que esto envejezca:

| | | comprobado con |
|---|---|---|
| Migraciones nuevas | **DOS, y una es bloqueante** | `git diff --name-only a82cec3 HEAD -- database/migrations/ database/schema/` da `2026_08_24_100000_boletin_independiente_esqueleto` y `2026_08_24_120000_create_auditoria_table`. **Esta fila decía «ninguna» hasta el 25 ago y era el número más peligroso del documento**: se midió antes de fundir las cuatro ramas de la noche del 24 y nadie la volvió a medir. Ver el aviso de debajo |
| Rutas | **539 antes y 542 después** | Tres nuevas, todas del 24 ago y todas para la app: `PUT notas/lote`, `GET disciplina/mis-fichas/{alumno_id?}` y `GET notificaciones/temas`. Ninguna quita ni cambia nada — ver la §5.b, que lleva la condición de publicación del cliente. Comprobable con `tests/Contrato/Snapshots/rutas.json` |
| Dependencias | **sin tocar** | `git diff --name-only a82cec3 HEAD -- composer.json composer.lock` sale vacío |
| `config/` | **un fichero nuevo**, `config/notificaciones.php` | `git diff --name-only a82cec3 HEAD -- config/`. **No obliga a tocar ningún `.env`**: sin credenciales el comando no manda nada y lo dice, y el secreto sale de `APP_KEY` |
| Tamaño | **61 ficheros de `app/`**, en 73 commits de 330 | `git diff --name-only a82cec3 HEAD -- app/` |

**Nada que publicar en `myvc_front`, `myvc_front_2` ni en la app de Flutter** —
las tres rutas nuevas son *para* la app, pero la app no las llama hasta que estén
desplegadas en los dieciséis (§5.b).

> **Los números de esta tabla se vuelven a medir cuando la tanda crece, no se
> suman.** Se corrigieron el 24 ago al entrar tres endpoints nuevos: `config/`
> pasó de «sin tocar» a un fichero, y el tamaño de 52 ficheros en 66 commits a 61
> en 73. Una tabla que dice «medido» y lleva números viejos es peor que no
> tenerla — es la misma trampa que este documento describe en el paso 2 con el
> «Already up to date».

**Sin rutas nuevas, sin migraciones y sin dependencias, el orden es libre**:
ningún colegio depende de otro. Pero no es indiferente — hay dos arreglos que le
están pasando a un colegio ahora mismo, y por eso la lista de abajo empieza por
lo que restaura.

> **`instival` no entra en el bucle y hay que mirarlo aparte.** No hay repositorio
> ni aplicación en su carpeta, así que **no recibe ni código ni migraciones** desde
> hace tandas. Es el único colegio del que no se sabe qué está sirviendo. Detalle
> en el paso 1.

---

### 1. Lo que vuelve a funcionar — lo único que restaura algo que hoy falta

Cuatro cosas, y son las que un colegio agradecería el primer día:

- **El boletín de una familia vuelve a salir.** En las maquetas 2 y 3 un acudiente
  que pedía el boletín de su acudido recibía **500**. Es lo que le está pasando a
  un colegio ahora mismo.
- **La ficha de alumno vuelve a guardar.** No es una mejora: **no guardaba nunca**
  —contestaba 422 «Datos incorrectos»—, y en el guardado sin tocar el desplegable
  de grupo escribía la ficha y decía que no. Es la que llevaba más tiempo rota y la
  que más gente usa ([05 §69](migracion/05-codigo-muerto-y-roto.md)).
- **El boletín deja de borrar definitivas.** Abrirlo borraba las definitivas
  automáticas del alumno en el periodo y sólo reponía las asignaturas con notas
  vivas; lo disparaba también **el alumno o el acudiente abriendo el suyo**, con el
  periodo del que mira. Donde faltaban definitivas, vuelven a aparecer
  ([10 §1.1](migracion/10-definitivas.md)).
- **El modal «quién cambió esta definitiva» abre por primera vez** (daba 500 a
  todo el mundo), y **la ficha de un alumno nacido en una ciudad sin país** también.

### 2. Y lo que se pidió: la definitiva se actualiza al cambiar la nota

Entra la **fase 3 completa** del [plan de definitivas](migracion/10-definitivas.md):
los siete disparadores cableados a un único recalculador, con lo que los seis
escritores de `notas_finales` quedan reducidos a uno.

**Lo que se nota**: editar o borrar una nota **actualiza la definitiva en el acto**
—era la petición de origen—, y lo mismo al tocar unidades y subunidades o al copiar
un periodo. De paso, la nota rápida del horario (`putSubunidad`) **empieza a
guardar**: no guardaba nada, y además era una inyección.

> **No entra el índice único de la fase 2** ni la limpieza de datos: esa espera los
> dieciséis números de la fase 0. Y **la fase 4 es del front** —revertir el valor
> cuando falla el guardado— y sigue sin hacer, así que la pantalla de notas puede
> seguir enseñando un valor que no se guardó. Es lo de siempre y no lo empeora esta
> tanda.

### 2.b Tres 500 que dejan de serlo, encontrados el 24 ago

Los tres los destaparon las sesiones del front verificando **en el navegador**, no
una suite. Van juntos porque comparten la causa de fondo: **ninguna suite nuestra
los habría encontrado, porque todos nuestros tests piden ids que existen.**

- **`GET perfiles/username/{u}` deja de reventar para todo acudiente** — eran
  **1.000 de las 1.067 cuentas** de la copia de desarrollo, y el mensaje de «no
  encontrado» del final era inalcanzable. Debajo había algo peor: la consulta de
  esa rama **no filtraba por el nombre**, así que devolvía el **directorio entero
  de acudientes** con documento, fecha de nacimiento, correo personal y correo de
  recuperación. Lo único que impedía la fuga era el propio fallo que causaba el
  500.
- **Pedir un grupo borrado o inexistente contesta 404 y no 500**, por
  **diecisiete** rutas a la vez — los tres controladores de boletines, planillas,
  puestos, certificados, PIAR, `editnota`, `bolfinales`—. El grupo 1 de la copia
  de producción **existe y está en la papelera desde enero de 2018**, así que
  cualquier pantalla que lo pidiera daba una traza de PHP.
- **Teclear una definitiva sin decir el periodo contesta 422 nombrando el campo**,
  no «no tienes permiso». Antes el rechazo salía por la guarda de permisos, así
  que **el mensaje mandaba a investigar a la persona equivocada**: quien lo recibía
  miraba los roles del profesor y no el cuerpo de la petición.

> **Lo que se nota de los tres es nada, salvo que dejan de fallar.** Ninguno quita
> capacidad a nadie: el primero devuelve un perfil donde antes había un error, el
> segundo cambia una traza por un «no existe» y el tercero cambia un mensaje
> equivocado por el correcto.

### 3. Lo que hay que avisar antes de desplegar

Son seis, y todas se notan el primer día:

| Qué cambia | A quién se le nota |
|---|---|
| **El listado de bitácoras encoge de golpe.** El botón de borrar marcaba la fila y el listado no miraba `deleted_at`. **Nadie pierde nada** —estaba borrado desde el día que le dieron al botón— pero parece pérdida de datos | quien mire esa pantalla |
| **Borrar un grado que tiene grupos deja de poder hacerse — 422 con la cuenta.** Antes apagaba la planilla de todos los profesores de ese grado sin decir nada y sin deshacer. En la copia de producción lo bloquearía en **13 de los 14 grados** | quien tenga la costumbre de borrar grados |
| **Borrar un ordinal del manual de convivencia que alguna falta cite — 422.** Antes la falta se quedaba en el observador sin el artículo que dice qué se incumplió. Bloquearía **7 de los 16** ordinales vivos. Igual con áreas y materias de las que dependan otras filas | coordinación |
| **Cambiar las claves de un grupo entero pasa a pedir superusuario.** No le quita el botón a nadie: el panel vive en un menú `admin`/`secretario`, y hoy hay 10 `is_superuser`, los mismos 10 con rol Admin y **cero Secretario** | nadie, salvo quien llegara por API |
| **El botón «Eliminar todas las notas de este periodo (¡peligroso!)» obedece al interruptor del periodo.** Es un `DELETE` físico sin papelera y no comprobaba nada. Con el periodo cerrado a los profesores deja de borrar | quien administre — es la primera vez que ese botón dice que no |
| **La casilla de contraseña de la ficha de alumno empieza a funcionar.** Antes escribirla no hacía nada y **vaciarla dejaba la cuenta con el hash de la cadena vacía**, que es entrar sin contraseña | quien administre |

Y tres más pequeñas, del mismo tipo: **copiar unidades a un periodo cerrado y
borrar una subunidad en uno cerrado dejan de funcionar** (copiar *desde* uno
cerrado sigue, que es lo de enero); **la rejilla de comportamiento deja de escribir
al abrirse** con el periodo cerrado —se sigue abriendo, sólo que ya no escribe—; y
**sacar de la papelera pasa a pedir superusuario**, como borrar de ella, que sólo
alcanza a los mismos diez que ya la veían.

### 4. Lo que deja de pasar, sin que nadie lo note

Casi toda la tanda es esto: un guardado silencioso o un 500 cambiados por un
código honesto. **Ninguna de estas apaga una pantalla.** Lo más gordo, por lo que
un colegio habría notado antes:

- Una petición a medias **dejaba el colegio sin nombre, sin año y sin los nombres
  de unidad que se imprimen en todos los boletines**, contestando 200. Y otra
  dejaba **dos años actuales**, con todo el colegio entrando en 2018.
- **Corregirle la redacción a un logro cambiaba la nota del boletín**, porque
  borraba el peso de la unidad.
- **Editar un grupo lo movía al año de quien lo editaba**, con sus matrículas
  dentro — 56 en la medición.
- **Un cuerpo parcial vaciaba 22 columnas de una ficha de perfil** y el nombre de
  seis catálogos se quedaba en `''`.
- **Editar una ficha reactivaba la cuenta**: `is_active` se pisaba a 1 en cada
  guardado, así que corregirle el teléfono a alguien le devolvía la entrada.
- **Un alumno o acudiente que mandara `is_prof_admin=true` en el cuerpo** recibía
  los eventos internos del colegio. Lo que se quita no es un permiso: es que **el
  cuerpo decida el permiso**.
- **Cinco sitios donde un valor del cuerpo entraba crudo en el SQL**, y **cuatro
  guards que no miraban el identificador que decidía** — el documento y la
  dirección de cualquiera, el álbum privado, la foto oficial pedida con la imagen
  de otro.
- **Once rechazos pasan a 403.** Los cuatro de `enfermeria/*` contestaban **401**,
  y `Sesion.ts` lee cualquier 401 como sesión caducada: a quien no tenía el permiso
  **se le echaba al login**. Se reportaba como «me saca», no como «no tengo
  permiso».
- **Un acudiente recibía un error después de que su prematrícula sí se hubiera
  guardado**, y volvía a darle al botón.
- Y unos cuantos lotes cambian **500 por 404 o 422**: un id que no lleva a ninguna
  fila deja de devolver una traza de PHP.

Fila a fila y con su ruta:
[`que-se-nota-en-un-colegio.md`](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md).
Los hallazgos completos van en dos sitios y conviene saberlo antes de buscar: las
**§53 a §80** están en el [05](migracion/05-codigo-muerto-y-roto.md), y las **§81 a
§167** en los veinte documentos de lote de
[`noche-2026-08-23/`](migracion/noche-2026-08-23/README.md) — con la tabla que dice
qué § cae en qué lote **al final del propio 05**.

> **Un snapshot cambiado no es una respuesta cambiada.** Quien audite la tanda verá
> que se movió `grupos-show.json` y tiene que ir a mirar cuál de las dos cosas se
> movió: aquí **se movió el test**. El snapshot viejo se había grabado sobre un
> grupo al que un fallo le había borrado el titular, o sea que guardaba el vaciado
> como si fuera lo correcto. `GruposController::getShow` no se tocó.

### 5. Lo que el despliegue NO arregla

Conviene tenerlo a mano cuando alguien pregunte: **lo ya escrito sigue como está.**

| |
|---|
| Las filas de `change_asked.deleted_at` y `ausencias.created_at` con la hora escrita dos veces (`hora:hora:minutos`) |
| Las 14 de 17 filas de `dis_ordinales` con `created_at` nulo |
| Los catálogos y las fichas ya vaciados por un guardado parcial |
| Las filas de `debugging` ya escritas |
| Las **11.988 definitivas que deberían existir y no existen** — eso lo arregla la fase 2, que aún no entra |

### 5.b Los endpoints nuevos que pide la app — **el cliente no se puede escalonar**

Desde el 24 ago la tanda lleva **endpoints nuevos escritos para `myvc_flutter`**,
y eso cambia una regla del despliegue que hasta ahora no hacía falta:

> **La app no puede empezar a usarlos hasta que estén desplegados en los
> DIECISÉIS**, no cuando estén fusionados.

`app/` es copia por colegio, pero **`myvc_flutter` es una sola app para todos**.
No hay versión por colegio, así que no se puede publicar «para los que ya lo
tienen». Un colegio sin desplegar convierte cada llamada en un **404 gastado**
antes de que la app caiga al método viejo — y en `notas/lote` eso es un viaje de
más por cada columna que pase un profesor, justo en el colegio más atrasado.

De ahí el orden, que es al revés del habitual:

1. desplegar el backend en los dieciséis y **comprobarlo colegio a colegio** (paso 2);
2. **después** publicar la versión de la app que los llama.

Los de esta tanda:

| Endpoint | Para qué | Si falta en un colegio |
|---|---|---|
| `PUT api/notas/lote` | pasar una columna de notas en una petición y una transacción | la app sigue guardando de una en una, tras gastar un 404 |
| `GET api/disciplina/mis-fichas/{alumno_id?}` | que el alumno y el acudiente vean su situación disciplinaria | la opción del menú no lleva a ninguna parte para las familias de ese colegio |
| `GET api/notificaciones/temas` | dar al teléfono los temas de push a los que puede suscribirse | ese colegio no recibe notificaciones; la app tiene que aguantarlo sin romperse |

**Ninguno quita nada**: los métodos viejos siguen ahí y siguen siendo el camino
hasta que la app cambie. O sea que desplegar esto no se nota en ninguna pantalla
— lo que hay que vigilar es lo contrario, publicar la app antes de tiempo.

Y dos cosas más que trae esta tanda y no son rutas:

- **`config/notificaciones.php`, fichero nuevo.** Es el único cambio de `config/`
  del rango y **no obliga a tocar ningún `.env`**: sin credenciales de Firebase el
  comando no manda nada y lo dice, y el secreto con el que se derivan los temas
  sale de `APP_KEY` si no se pone otro.
- **`notificaciones:enviar` entra en el scheduler**, cada quince minutos. **No
  hay cron nuevo que crear**: es el `schedule:run` de cada minuto que ya está
  documentado aquí, y lo que corre viaja dentro de `app/Console/Kernel.php`. En
  el colegio que no tenga ese cron puesto, esto simplemente no corre — y ése es
  el momento de ponerlo, con la línea de siempre.

---

### 6. Lo que no entra en la tanda y hay que tener delante

- **`definitivas_periodos/calcular-grupo-periodo` sigue reescribiendo la rejilla de
  un periodo cerrado.** No se ha tocado. El día que se decida cerrarla, ese cambio
  **sí apaga algo** y **hay que desplegarlo mirando el calendario del colegio**.
- **La fase 2 de definitivas** —los dos índices únicos, la limpieza de duplicados y
  el relleno de las que faltan—. Espera los dieciséis números de la fase 0.
- **En `myvc_front`**, sin hacer: el arreglo de las cuatro altas de la planilla de
  notas que no mandan `fecha_hora` (`MIGRATION.md` §4b.3b), y la fase 4 entera.
- **Seis fallos que no se notan el día del despliegue** y esperan a que alguien haga
  lo razonable —añadir `is_superuser` a `perfiles/usuariosall`, crear la carpeta que
  le falta a `GET api/importar`, que `grados_sig` deje de ser `year + 1`…—. Están con
  su detonante en la [§4.b](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md).
  El aviso que vale para las tres formas es el mismo: **cuando una comprobación de
  negocio vive en la pantalla, la ruta está abierta y no lo nota nadie.**

---

## Paso 1. Los colegios, de una vez

**Si algún `git pull` imprime `composer.lock`, para en seco.** Esta tanda no toca
dependencias —está medido arriba—, así que no debería salir; si sale es que ese
colegio venía atrasado de una tanda anterior, y tocar `vendor/` tiene su propio
procedimiento (trampa 1 y la referencia). Lo demás del bucle es idempotente:
correrlo dos veces no hace daño.

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  echo "=== $d"; cd "$d" || continue
  git pull                                        # trae código Y migraciones
  php artisan migrate --force                     # va aquí, no después
  php artisan config:clear;  php artisan route:clear
  php artisan config:cache;  php artisan route:cache
done
```

Repítelo en la otra cuenta de cPanel (`lalvirtual.edu.co`) con su propia ruta: es
otro login, así que el `for` no la alcanza.

> ## AVISO: **el `migrate --force` de este bucle ya no es opcional** — 25 ago
>
> Hasta el 24 esta tanda no traía migraciones y el `migrate` estaba puesto por
> higiene. **Ya no.** `2026_08_24_100000_boletin_independiente_esqueleto` crea
> `bol_ind_periodos`, y **el código que va en la misma tanda la consulta en un
> camino vivo**: `App\Models\Unidad:112` llama a
> `BoletinIndependiente::alcance()`, que hace `LEFT JOIN bol_ind_periodos`. Los
> boletines pasan por ahí.
>
> **Un colegio que reciba el código y no la migración devuelve 500 en las pantallas
> de boletines**, con este mensaje y no otro:
>
> ```
> SQLSTATE[42S02]: Base table or view not found: 1146
> Table '<colegio>.bol_ind_periodos' doesn't exist
> ```
>
> **Comprobado, no supuesto**: la base de desarrollo `simonbolivar` tiene el código
> fundido y **no** la tabla, y da exactamente ese 500. Lo encontró
> `myvc-front-94` abriendo la pantalla en Chrome — **ninguna suite nuestra lo
> habría visto**, porque las bases de test sí llevan la migración.
>
> Consecuencias para el bucle, y las tres importan:
>
> 1. **El `migrate --force` va donde está —justo después del `git pull`— y no se
>    salta.** Entre las dos líneas hay una ventana en la que ese colegio da 500;
>    es de segundos, pero existe, así que **no se hace el bucle en horario de
>    clase si se puede evitar**.
> 2. **Si el `git pull` de un colegio falla y el `migrate` no**, o al revés, ese
>    colegio queda con las dos mitades desparejadas. **Para en seco y arréglalo
>    antes de pasar al siguiente**: el bucle es idempotente, volver a entrar no
>    hace daño.
> 3. **Y esta fila se vuelve a medir cada vez que crece la tanda.** Decía
>    «ninguna» porque se midió antes de fundir cuatro ramas, y **una tabla que
>    dice «medido» con un número viejo es peor que no tenerla** — el propio
>    documento lo dice dos párrafos más arriba, y aun así pasó.
>
> Si el comportamiento sigue siendo el viejo con el código nuevo en su sitio, lo
> que hay que mirar es **OPcache**, no el `.env` — trampa 1b de la
> [referencia](DESPLIEGUE-REFERENCIA.md).

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
otros tres, y el bucle del 21 ago zanjó cuál de los dos existe aquí — `lal` está en
la otra cuenta.

> **`instival` no se despliega con este bucle y hay que mirarlo aparte.** El 21 ago
> contestó `fatal: not a git repository` y, lo que es peor, `Could not open input
> file: artisan` cinco veces: en esa carpeta no hay ni repositorio ni aplicación. O
> sea que **no recibe ni código ni migraciones**, y se queda con lo que tuviera —
> arreglos de autorización incluidos. Ya salía como caso raro en el inventario del
> 18 ago, y el cierre del 19 que dio los 16 por desplegados **no lo comprobó**. Es
> el único colegio del que no se sabe qué está sirviendo.

## Paso 2. Comprobar

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-52s ' "$d"
  git -C "$d" log -1 --format='%h ' 2>/dev/null || { echo 'NO ES REPO GIT'; continue; }
  (cd "$d" && php artisan migrate:status 2>/dev/null \
     | grep -cE 'Ran.*(rol_secretario|password_reminders|frases_preescolar)')
done            # el mismo commit en todos, y un 3 detrás
```

**Mira el commit, no sólo el 3.** «Already up to date» significa que ese colegio ya
estaba donde apunta **su** remoto, que no tiene por qué ser el `origin/main` que
acabas de actualizar: el 21 ago los dieciséis dijeron «Already up to date» minutos
después de un `push`, y eso sólo se distingue de un despliegue bueno mirando el
hash. Si no coincide, `git -C "$d" remote -v` y `git -C "$d" branch -vv`.

**Ese 3 es de la tanda del 21 ago, no de ésta**: aquí no hay migraciones nuevas, así
que sigue siendo 3 y lo que dice es que el colegio no se quedó atrás entonces. Lo
que fija esta tanda es el **hash**.

Y a mano, en el navegador de un colegio cualquiera. Son cinco cosas distintas y van
en este orden, de lo más usado a lo más raro:

0. **Editar una ficha de alumno y darle a guardar.** Tiene que decir que se guardó
   —y haberse guardado: vuelve a entrar y míralo—. Es la que llevaba más tiempo rota
   y la que más gente usa.
1. **Abrir el boletín de un alumno y volver a la planilla de notas.** Las definitivas
   tienen que seguir ahí —y en las asignaturas sin notas, donde antes desaparecían,
   ahora salen—. Hazlo además **entrando como el acudiente**, que es quien lo
   disparaba sin saberlo.
2. **Cambiar una nota en la planilla y mirar la definitiva.** Es lo que se pidió y lo
   que enciende la fase 3: tiene que moverse sin recalcular nada a mano.
3. **Una acción de enfermería con alguien que no tenga el permiso.** Antes se le
   echaba al login; ahora tiene que ver el mensaje y **seguir dentro**.
4. **Login de personal y login de alumno**, como siempre.

## Paso 3. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Esta tanda no trae migraciones**, así que volver atrás es sólo el `checkout` y las
cachés. Las tres del 21 ago se quedan donde están: una tabla de más y dos columnas
que admiten NULL, y el código viejo las ignora.

## Paso 4. Las tres trampas que cuestan un colegio

Las siete completas están en la referencia; éstas son las que muerden en un
despliegue como el de hoy.

1. **`composer` dentro de un colegio con `vendor/` compartido** le cambia las
   dependencias a los otros cuatro: sigue el symlink sin avisar y sin fallar.
   Comprueba antes con `[ -L vendor ]`.
2. **Cada comando con su `php artisan`.** `php artisan config:clear && route:clear`
   **no funciona**: el segundo muere con `command not found` y la caché vieja se
   queda viva. Pasó en `coal` y el login devolvió 404 con el código bien desplegado.
   Si un `artisan` de la cadena no imprime su `INFO`, no corrió.
3. **`config:cache` antes de tocar el `.env`** deja al colegio sirviendo la
   configuración anterior, sin ningún síntoma que lo delate.

---

## Una regla de orden que sale del boletín independiente, para cuando esa tanda salga

**Todavía no hay nada que desplegar de esto** —la fase 1 del
[19](migracion/19-boletin-independiente.md) es de la noche del 24 y no está
fusionada—, pero la regla se escribe ahora porque **contradice el orden que este
documento usó la última vez** y no conviene descubrirlo el día del despliegue.

La migración de esa fase añade `unidades.alumno_id`. Con la columna puesta y
**nadie marcado**, cuatro consultas de boletines empiezan a dar **500**:

```
SQLSTATE[23000]: 1052 Column 'alumno_id' in on clause is ambiguous
```

Cuatro predicados nombraban `alumno_id` **sin alias delante** dentro de consultas
que unen `unidades`. Hasta hoy no había ambigüedad —`notas` era la única tabla del
join con esa columna—, así que escribirlo desnudo **llevaba veinte años
funcionando**. En cuanto `unidades` tiene la suya, MySQL no puede elegir y aborta.

**Lo que importa no es el arreglo —ya está hecho— sino cuándo se rompe:**

> **La rompe el `ALTER TABLE`, no el código.**

Y `app/` es **copia por colegio**. En un colegio donde la migración corra **antes**
de que llegue el `app/` nuevo, **los boletines dan 500 durante esa ventana**. Así
que **dentro de cada colegio va primero el `app/` y después el `ALTER TABLE`**, al
revés que la tanda de `password_reminders`, donde la migración iba delante.

**No hay un orden universal, y ésa es la regla que se lleva:** la migración va
delante cuando el código nuevo **la necesita** para funcionar; va detrás cuando es
la migración la que **rompe el código viejo**. Antes de cada tanda con esquema hay
que preguntarse cuál de los dos casos es — y la forma de saberlo es la que lo
encontró aquí: **correr la suite con la migración puesta y el código viejo**.

Lo encontró `8myvc-9e` la noche del 24, **y lo encontró la suite, no un detector**.
Es además el tercer modo de fallo de la §9.2 del plan, y el único bueno: los otros
dos —contar de más y contar de menos— **son silenciosos**; éste revienta en el
primer test en vez de imprimir un boletín equivocado.

### El paso que va ANTES de escribir un `ALTER TABLE` — `tools/tablas-calientes.php`

La regla de arriba dice **en qué orden** desplegar una migración. Ésta dice **si esa
migración mueve una respuesta**, que es la pregunta que había que hacerse primero y
**no tenía respuesta en ninguna parte**.

```
ficheros de app/ revisados ......... 220
consultas con SELECT * ............. 251   (resueltas a 360 sobre 57 tablas)
instantáneas leídas ................ 121
tablas con la forma fijada ......... 47
>>> CALIENTES ...................... 35
```

**Caliente** = la tabla tiene alguna consulta que dice `SELECT *` **y** su forma está
fijada por una instantánea. En esas 35, **añadir una columna aparece sola en la
respuesta y mueve una pantalla que nadie tocó** — y hay que avisar a los cuatro
clientes, con `myvc_flutter` siendo **una sola app para los dieciséis**.

Las de más consultas incluyen `dis_ordinales`, `historiales`, **`years` (64
columnas)**, `tipos_documentos`, `unidades`, `dis_configuraciones`,
`config_certificados`, `recuperacion_final`, `contratos`, `areas`, `dis_libro_rojo` y
**`alumnos` (39 columnas)**.

**Por qué es la peor de detectar, y por eso va como paso y no como consejo:** las
otras formas de romper **dependen de qué código haya delante** —un `1052 ambiguous`
rompe contra el código viejo—; **ésta no depende del código: depende de que la
consulta diga `*`**, así que **un `ALTER` la dispara contra el viejo y contra el nuevo
a la vez**.

Y de ahí que la comprobación con la suite sean **dos pasadas y ninguna encuentre la de
la otra**:

| Pasada | Encuentra | Medido el 24 ago |
|---|---|---|
| esquema nuevo + código **viejo** | el `1052 ambiguous` | 4 consultas |
| esquema nuevo + código **nuevo** | el `SELECT *` | 5 snapshots |

> La herramienta lleva `--autoprueba`, **y se corre primero**: comprueba que distingue
> `unidades` de `unidades_por_defecto` —el falso positivo que se comió una medición
> esa noche, porque los ocho primeros caracteres coinciden— y que **no cuenta
> subconsultas**, que fue el cuarto falso positivo y sólo se vio leyendo.

#### La pasada se publica con DOS números, no con uno — medido el 25 ago

El procedimiento de arriba —correr la suite con el esquema nuevo y el código viejo— se
ejecutó, y **su resultado obliga a cambiar cómo se publica**:

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

**Por eso se publica con los dos números, y un `0` significa «la suite no encontró
nada», nunca «no hay nada».** El guion `tools/esquema-nuevo-codigo-viejo.sh` lo lleva en
su cabecera, que es donde lo leerá quien lo corra.

**Y el interruptor por colegio es el patrón general, no la anécdota de este caso:** es la
misma familia que `mostrar_puesto_boletin` (**1 de 8 años a 0**) y que la excepción por
colegio que avisó el front.

> **Lo que un interruptor apaga, la suite no lo prueba** — y hay **dieciséis colegios con
> dieciséis combinaciones**. Antes de un `ALTER TABLE`, la pregunta no es sólo *«¿qué
> rompe?»* sino **«¿qué rompe en la combinación de interruptores que la suite no
> tiene?»**
