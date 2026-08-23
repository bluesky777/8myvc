# Desplegar

Los comandos de **la tanda que toca**, y nada más. Lo que se hace una sola vez
—PHP 8.4, el cron, el token de GitHub, la topología de `vendor/`—, las trampas
completas y lo que trajo cada tanda anterior están en
[DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

---

## Esta tanda: 23 ago 2026

Backend y nada más. **No hay migraciones nuevas**, **no cambia ninguna ruta** y
**no hay que publicar nada en `myvc_front`, `myvc_front_2` ni en la app de
Flutter**. Los tres están comprobados, no supuestos, y con el comando al lado
para recalcularlos el día que este documento envejezca:

| | | comprobado con |
|---|---|---|
| Migraciones nuevas | **ninguna** | `git diff c2c2a04 9492a2b -- database/migrations/ database/schema/` sale vacío |
| Rutas | **539 antes y 539 después** | `git log c2c2a04..9492a2b -- routes/` sale vacío, y `Route::` en `routes/api/` da **538** en los dos extremos (más el `GET /` de `web.php`) |
| Ficheros de `app/` tocados | **38**, en **46** commits | `git diff --name-only c2c2a04 9492a2b -- app/` y `git log --oneline c2c2a04..9492a2b -- app/` |

**Sin rutas nuevas ni migraciones, el orden dentro de la tanda es libre**: ningún
colegio depende de otro y no hay nada como el `password_reminders` de la tanda del
20. Pero **no es indiferente**: hay un arreglo que le está pasando a un colegio
ahora mismo, y va abajo el primero.

> **Antes de empezar, comprobar si la tanda del 22 llegó a desplegarse** —el
> comando está en *Cómo comprobar qué hay desplegado en un colegio*, en la
> [referencia](DESPLIEGUE-REFERENCIA.md)—. Si no llegó, **ésta la incluye**: su
> lista se conserva entera más abajo y hay que leer las dos antes de avisar.

### Y tres decisiones de Joseth aplicadas el 23 ago, que van en esta misma tanda

Entran después de la noche y **se notan más que casi todo lo de abajo**, así que
van primero:

- **Cambiar las claves de un grupo entero pasa a pedir superusuario.** `PUT
  alumnos/cambiar-claves` reescribía las contraseñas de todos los alumnos de un
  grupo con `auth.personal` y nada más. **No le quita el botón a nadie**: el panel
  «Cambiar claves y usuarios» vive en un menú `hasRoleOrPerm(['admin',
  'secretario'])`, y hoy hay 10 `is_superuser`, los mismos 10 con rol `Admin` y
  **cero `Secretario`**. Un profesor que llegara por API recibe 403.
- **Borrar un grado que tiene grupos deja de poder hacerse — 422 con la cuenta.**
  Antes apagaba la planilla de todos los profesores de ese grado sin decir nada y
  sin forma de deshacerlo. **Esto sí se nota y hay que avisar**: en la copia de
  producción lo bloquearía en **13 de los 14 grados**, o sea que quien tenga la
  costumbre de borrar grados se va a encontrar el aviso. El mensaje dice cuántos
  grupos dependen.
- **Borrar un ordinal del manual de convivencia que alguna falta cite, igual —
  422.** Antes la falta se quedaba en el observador del alumno **sin el artículo
  que dice qué se incumplió**. Bloquearía **7 de los 16** ordinales vivos.

> **Lo que NO cambia**, por si alguien lo pregunta: borrar frases del banco, tipos
> de documento y ciudades sigue funcionando igual — ahí el hijo no pierde nada, y
> está medido en el [09](migracion/09-pendientes.md).

### Lo que hay que avisar antes de desplegar

Son tres, y las tres se notan el primer día:

- **El listado de bitácoras encoge de golpe.** El botón de borrar marcaba la fila
  y el listado no miraba `deleted_at`, así que lo «borrado» seguía saliendo. Al
  desplegar, todo eso desaparece a la vez. **Nadie pierde nada** —estaba borrado
  desde el día que le dieron al botón— pero quien mire esa pantalla tiene que
  saberlo, porque parece una pérdida de datos y no lo es.
- **Cuatro cosas que un profesor podía hacer pasan a contestar 403**: mandar la
  ficha de otro profesor a la papelera, mandar un grupo a la papelera desde la
  rejilla de Usuarios, y poner la imagen de un tercero en una ficha (tres rutas).
  **Riesgo bajo, y por la misma razón las cuatro**: *ninguna se alcanza hoy desde
  una pantalla que el front le enseñe a un profesor* — tres viven en menús `admin`
  y la cuarta sólo rechaza un cuerpo que ningún botón sabe construir.
- **La rejilla de comportamiento deja de escribir al abrirse con el periodo
  cerrado.** Antes, abrirla ponía a cada alumno del grupo el tope de la escala —y
  en el periodo **del que mira**, no en el del grupo—. **La rejilla se sigue
  abriendo**: lo que cambia es que ya no escribe, y el 400 al guardar es el mismo
  que ya daba. El profesor ve lo mismo.

### Lo que enciende, y es lo único que devuelve algo que hoy falta

- **El boletín de una familia vuelve a salir.** En las maquetas 2 y 3, un
  acudiente que pedía el boletín de su acudido recibía **500**. Es **el único
  hallazgo de la noche que le está pasando a un colegio ahora mismo**, así que es
  el que un colegio agradecería primero.
- **La ficha de un alumno nacido en una ciudad sin país vuelve a abrir**:
  `ciudades/datosciudad` daba 500 y ahora contesta 200 con el país en null. Se
  nota en secretaría, y sólo en los colegios que tengan alguna ciudad sin país.

**Todo lo demás previene, no restaura.** Y por eso, lo que el despliegue **no**
arregla, que conviene decirlo cuando alguien pregunte:

| Lo ya escrito sigue como está |
|---|
| Las filas de `change_asked.deleted_at` y `ausencias.created_at` con la hora escrita dos veces (`hora:hora:minutos`) |
| Las 14 de 17 filas de `dis_ordinales` con `created_at` nulo |
| Los catálogos y las fichas ya vaciados por un guardado parcial |
| Las filas de `debugging` ya escritas |

### Lo que deja de pasar

Casi toda la tanda es esto: un guardado silencioso o un 500 cambiados por un
código honesto. Lo más gordo, por lo que un colegio notaría antes:

- **Una petición a medias dejaba el colegio sin nombre, sin año y sin los nombres
  de unidad que se imprimen en todos los boletines**, contestando 200. Y otra
  dejaba **dos años actuales**, con todo el colegio entrando en 2018 al siguiente
  inicio de sesión.
- **Corregirle la redacción a un logro cambiaba la nota del boletín**, porque
  borraba el peso de la unidad. Igual un cuerpo sin `porcentaje` en las
  subunidades.
- **Editar un grupo lo movía al año de quien lo editaba**, con sus matrículas
  dentro — 56 en la medición.
- **Un cuerpo parcial vaciaba 22 columnas de una ficha de perfil**, ninguna a
  salvo; y el nombre de cualquiera de **seis catálogos** se quedaba en `''`.
- **Un acudiente recibía un error después de que su prematrícula sí se hubiera
  guardado**, y volvía a darle al botón. Es la única de estas escrituras que
  alcanza a una familia.
- **Un alumno o acudiente que mandara `is_prof_admin=true` en el cuerpo recibía
  los eventos que el colegio marca como internos.** Lo que se quita no es un
  permiso: es que **el cuerpo decida el permiso**.
- Y **cinco lotes** cambian 500 por 404 o 422: un id que no lleva a ninguna fila
  deja de devolver una traza de PHP.

La lista entera, fila a fila y con su ruta, está en
[`que-se-nota-en-un-colegio.md`](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md);
los hallazgos completos, en el [05](migracion/05-codigo-muerto-y-roto.md) §81 a
§167, con su índice por lote al final.

> **Un snapshot cambiado no es una respuesta cambiada.** Quien audite la tanda
> verá que se movió `grupos-show.json` y tiene que ir a mirar cuál de las dos
> cosas se movió: aquí **se movió el test**. El snapshot viejo se había grabado
> sobre un grupo al que un fallo le había borrado el titular, o sea que **guardaba
> el vaciado como si fuera lo correcto**. `GruposController::getShow` no se tocó.

### Dos cosas que no entran en la tanda y hay que tener delante

- **`definitivas_periodos/calcular-grupo-periodo` sigue reescribiendo la rejilla
  de un periodo cerrado.** No se ha tocado. El día que se decida cerrarla, ese
  cambio **sí apaga algo** —abrir el boletín de un grupo desactualizado en periodo
  cerrado— y **hay que desplegarlo mirando el calendario del colegio**, no en
  cualquier momento.
- **Seis fallos que no se notan el día del despliegue y esperan a que alguien haga
  lo razonable**: añadir `is_superuser` a `perfiles/usuariosall`, crear la carpeta
  que le falta a `GET api/importar`, que `grados_sig` deje de ser `year + 1`…
  Ninguna afecta a esta tanda; **todas afectan a quien toque eso después**. Están
  con su detonante en la
  [§4.b](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md), y el aviso que
  vale para las tres formas es el mismo: **cuando una comprobación de negocio vive
  en la pantalla, la ruta está abierta y no lo nota nadie.**

> **Sin migraciones, el `migrate --force` del bucle no aplica nada** y se deja
> donde está: correrlo es idempotente. Si el comportamiento sigue siendo el viejo
> con el código nuevo en su sitio, lo que hay que mirar es **OPcache**, no el
> `.env` — trampa 1b de la [referencia](DESPLIEGUE-REFERENCIA.md).

---

## La tanda del 22 ago 2026 — si no llegó a desplegarse

Backend y nada más. **No hay migraciones nuevas** —las tres del 21 ago siguen
siendo las últimas— y **no hay que publicar nada en `myvc_front`, `myvc_front_2`
ni en la app de Flutter**.

Son los 28 commits de la noche del 21 al 22, con cinco sesiones trabajando a la
vez. Los hallazgos están enteros en
[05-codigo-muerto-y-roto.md](migracion/05-codigo-muerto-y-roto.md) §55 a §68;
aquí va lo que **se nota** en un colegio, que es lo que hace falta para
desplegar:

- **El boletín deja de borrar definitivas** —[10-definitivas.md §1.1](migracion/10-definitivas.md).
  Abrir un boletín borraba las definitivas automáticas de ese alumno en el
  periodo y sólo reponía las asignaturas con notas vivas; y lo disparaba también
  **el alumno o el acudiente abriendo el suyo**, con el periodo del que mira y no
  el del boletín. Ahora recalcula sólo si está desactualizada, y sin borrar.
  **Es el cambio que más se va a notar, y es a favor**: donde faltaban
  definitivas, vuelven a aparecer. Va acotado a ese alumno a propósito, para que
  un acudiente no reescriba las de treinta.
- **Cinco sitios donde un valor del cuerpo entraba crudo en el SQL**: los
  ordinales de disciplina, el calendario, la casilla del SISBEN de la hoja de
  importación y los dos `INSERT` de «Calcular definitivas». El del calendario es
  **de segundo orden** —el valor no llegaba en esa petición, lo había escrito
  antes otra ruta—, que es la razón de que no lo viera ningún detector. En
  pantalla no cambia nada.
- **Cuatro guards que no miraban el identificador que decidía** ([05 §53](migracion/05-codigo-muerto-y-roto.md)):
  el documento y la dirección de cualquiera por `change-asked-assignment`, el
  álbum privado de cualquiera, la foto oficial pedida **con la imagen de otro** y
  el comentario de un alumno en una publicación marcada sólo para
  administradores. Quien no debía deja de poder; quien sí, no nota nada.
- **Once rechazos pasan a 403** ([05 §54](migracion/05-codigo-muerto-y-roto.md)
  y [§67](migracion/05-codigo-muerto-y-roto.md)): los cuatro de `enfermeria/*`,
  que contestaban **401**; los cuatro de `calendario/*`, que contestaban 404; y
  los tres `users/crear-*`. **El 401 es el que importa**: `Sesion.ts` de
  `myvc_front` lee cualquier 401 como sesión caducada, así que a quien no tenía
  el permiso de enfermería se le rotaba la sesión en cada intento y en la carrera
  se le echaba al login — se reporta como **«me saca»**, no como «no tengo
  permiso». Comprobado en los tres fronts: ninguno mira el código, todos pintan
  el mensaje del cuerpo, así que el cambio es invisible salvo por dejar de echar
  a nadie.
- Y **un mensaje que hablaba de otra operación**: `alumnos/update` respondía «No
  tienes permiso para eliminar alumnos definitivamente», que es lo que quedaba
  escrito en el log de un colegio cuando alguien intentaba **editar**.
- **La ficha de alumno vuelve a guardar** ([05 §69](migracion/05-codigo-muerto-y-roto.md)).
  No es una mejora: **no guardaba nunca**. Contestaba 422 «Datos incorrectos»
  porque indexaba dos veces lo que su propio saneador ya había convertido, y en el
  guardado sin tocar el desplegable de grupo el 422 llegaba **después** de escribir
  la ficha y la cuenta — guardaba y decía que no. Con ella se encienden los guardas
  de la [§68](migracion/05-codigo-muerto-y-roto.md), que hasta hoy no llegaban a
  ocurrir por esa vía.
- **Editar una ficha deja de reactivar la cuenta.** `is_active` se pisaba a 1 en
  cada guardado de profesor y de alumno, así que corregirle el teléfono a alguien
  le devolvía la entrada al sistema y deshacía el interruptor de «Activo» de la
  rejilla, que es otra ruta. Igual con el correo de la cuenta, que se sustituía por
  el de la persona. **Las altas no cambian**: una cuenta que nace, nace activa.
- **El modal «quién cambió esta definitiva» abre por primera vez**
  ([05 §73](migracion/05-codigo-muerto-y-roto.md)): contestaba 500 a todo el mundo
  por una ligadura de más en la consulta. Es de sólo lectura y está en la pantalla
  de promoción; el de las notas sueltas ya funcionaba.
- **`editnota` deja de mandar alumnos a la papelera sin criterio**
  ([05 §72](migracion/05-codigo-muerto-y-roto.md)). Tres de sus rutas no tocan
  ninguna nota —mandan un alumno a la papelera, lo sacan y lo borran— y dos no
  exigían nada, así que un profesor podía por ahí lo que no puede por
  `alumnos/destroy`. Ningún cliente las llama; no se apaga ninguna pantalla.
- **Un cálculo de definitivas que borraba notas se corta**
  ([05 §71](migracion/05-codigo-muerto-y-roto.md)).
  `definitivas_periodos/calcular-notas-finales-asignatura` empezaba por un `DELETE`
  con el criterio invertido —se llevaba **las definitivas puestas a mano**, que son
  las que no se pueden recalcular— y después reventaba con 500. Ahora contesta 410
  sin ejecutar nada. **Ninguna pantalla lo llama**, así que no se nota; lo que
  cambia es que deja de poder vaciarse por ahí.
- **Tres respuestas de las escalas de valoración pasan de 200 a 404**
  ([05 §70.4](migracion/05-codigo-muerto-y-roto.md)): borrar o editar una escala
  que no existe contestaba «En papelera» y «Guardado». La pantalla de escalas ya
  tenía rama de error para las dos y no mira el código, así que lo que cambia es
  que enseña el error verdadero en vez de un éxito falso.
- **Copiar unidades a un periodo cerrado, y borrar una subunidad en uno cerrado,
  dejan de funcionar** ([05 §80](migracion/05-codigo-muerto-y-roto.md)). Son las
  dos que le faltaban al candado de la §27. **Se nota, y hay que avisar**:
  `panel.copiar` deja de traer la estructura a un periodo cerrado —copiar *desde*
  uno cerrado sigue funcionando, que es lo de enero— y el botón de borrar una
  subunidad de la rejilla de unidades dice que no. Las dos hacían por la puerta
  de atrás lo que sus vecinas de la misma pantalla ya no dejaban hacer de frente.
- **Contratar a un profesor que no existe deja de crear un contrato fantasma**
  ([05 §78.2](migracion/05-codigo-muerto-y-roto.md)). Escribía la fila igual y
  contestaba 200 con un array vacío, y la pantalla enseñaba «contratado para
  este año» mientras aquí quedaba un contrato sin profesor — invisible desde
  cualquier rejilla y por tanto imposible de quitar. **No se nota nada**: en la
  copia de producción hay cero contratos huérfanos de 164, o sea que el front
  siempre manda un id bueno. Lo que cambia es que el día que mande uno malo
  contesta 422 en vez de inventarse una fila.
- **El botón «Eliminar todas las notas de este periodo (¡peligroso!)» obedece al
  interruptor del periodo** ([05 §77](migracion/05-codigo-muerto-y-roto.md)). Es
  un `DELETE` **físico** —sin papelera y sin vuelta atrás— de todas las notas de
  un alumno en un grupo y un periodo, y no comprobaba nada. Ahora pide lo mismo
  que las otras 25 rutas de la §27. **Lo que se nota**: con el periodo cerrado a
  los profesores, ese botón deja de borrar y contesta «No tienes permiso» —el
  mismo 400 que ya da el resto de la rejilla de notas—. Con el periodo abierto
  sigue funcionando igual. Conviene avisar a quien administre, porque es la
  primera vez que ese botón dice que no.
- **Sacar de la papelera pasa a pedir superusuario, como borrar de ella**
  ([05 §76](migracion/05-codigo-muerto-y-roto.md)). Son cinco rutas —grupos,
  el mismo grupo por la puerta de `perfiles/`, profesores, años y asignaturas—
  y hasta hoy cualquiera del personal las llamaba: la revisión de la papelera
  del 21 ago cerró **la mitad que borra de cada pareja y no la que devuelve**.
  **Lo que se nota es nada**, y está comprobado antes: la pantalla de papelera
  del front ya está en el menú «Colegio» con `hasRoleOrPerm('admin')`, o sea
  que sólo la veían los mismos diez que ahora la pueden usar. De las cinco,
  **cuatro no las llama ningún cliente**. La de asignaturas cambia de otra
  manera: restaurar obedece ahora al año del que pide, igual que su listado, y
  una de otro año contesta 404.
- **Borrar una falta pasa a firmarla** ([05 §75.3](migracion/05-codigo-muerto-y-roto.md)).
  De las tres rutas que borran una ausencia, dos anotaban `deleted_by` y la de las
  pantallas web y de Flutter no anotaba nada: en la copia de producción hay **5.689
  ausencias borradas y 5.684 sin autor**. En pantalla no cambia nada — lo que
  cambia es que a partir de la copia se sabe quién borró qué. **Va con su decisión
  al lado**: quién puede corregir y borrar una falta se dejó abierto al personal a
  propósito ([05 §75.2](migracion/05-codigo-muerto-y-roto.md)), así que el rastro
  es lo único que queda, y por eso importa que no esté en blanco.
- **Y la casilla de contraseña de la ficha de alumno empieza a funcionar** — esto
  sí enciende algo, y se decidió encenderlo. Antes escribir una contraseña no hacía
  nada y **vaciarla dejaba la cuenta con el hash de la cadena vacía**, que es entrar
  sin contraseña. Conviene avisar a quien administre: la casilla ahora cambia la
  contraseña de verdad.

Lo demás son tests y documentos. Entra también `App\Services\DefinitivasDeAsignatura`,
el recalculador único, **y sólo lo llama el boletín**: el resto de la fase 3 y el
índice único de la fase 2 no van en esta tanda.

> **Sin migraciones, el `migrate --force` del bucle no aplica nada** y se deja
> donde está: correrlo es idempotente y el día que haya una, el orden ya es el
> bueno. Lo que sí hay que mirar si el comportamiento sigue siendo el viejo con
> el código nuevo en su sitio es **OPcache**, no el `.env` —trampa 1b de la
> [referencia](DESPLIEGUE-REFERENCIA.md).

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

**Ese 3 es de la tanda anterior, no de ésta**: aquí no hay migraciones nuevas, así
que sigue siendo 3 y lo que dice es que el colegio no se quedó atrás el 21 ago.
Lo que fija esta tanda es el **hash**.

Y a mano, en el navegador de un colegio cualquiera, que aquí son tres cosas
distintas:

0. **Editar una ficha de alumno y darle a guardar.** Tiene que decir que se guardó
   —y haberse guardado: vuelve a entrar y míralo—. Es la que llevaba más tiempo
   rota y la que más gente usa.
1. **Abrir el boletín de un alumno y volver a la planilla de notas.** Es lo único
   que cambia de comportamiento visible: las definitivas tienen que seguir ahí —y
   en las asignaturas sin notas, donde antes desaparecían, ahora salen. Hazlo
   además **entrando como el acudiente**, que es quien lo disparaba sin saberlo.
2. **Una acción de enfermería con alguien que no tenga el permiso.** Antes de esto
   se le echaba al login; ahora tiene que ver el mensaje y **seguir dentro**.
3. **Login de personal y login de alumno**, como siempre.

---

## 3. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Esta tanda no trae migraciones**, así que volver atrás es sólo el `checkout` y
las cachés. Las tres del 21 ago se quedan donde están: una tabla de más y dos
columnas que admiten NULL, y el código viejo las ignora.

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
