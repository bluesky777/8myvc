# Desplegar

Los comandos de **la tanda que toca**, y nada más. Lo que se hace una sola vez
—PHP 8.4, el cron, el token de GitHub, la topología de `vendor/`—, las trampas
completas y lo que trajo cada tanda anterior están en
[DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

---

## Esta tanda: 22 ago 2026

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
