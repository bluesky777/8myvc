# Desplegar

**Los comandos de la tanda que toca, y nada más.** Topología, inventario, las siete trampas, el
bucle del front y lo que trajo cada tanda: [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## La tanda pendiente: del 25 ago (`eb95cbc`) a hoy — **ocho ficheros y UNA migración bloqueante**

**La anterior, del 22 al 25 ago, se desplegó el 25 ago en `eb95cbc`**, con sus cuatro migraciones
y con el mismo hash comprobado en los quince. Qué se le notó a un colegio, fila a fila:
[`que-se-nota-en-un-colegio.md`](migracion/noche-2026-08-23/que-se-nota-en-un-colegio.md).

**Lo que hay desde entonces**, medido sobre el rango entero y no sumando commit a commit:

| | | recalcular con |
|---|---|---|
| Migraciones | **UNA**, y **no es opcional** | `git diff --name-only eb95cbc HEAD -- database/migrations/` |
| Rutas | **542, sin mover** | `tests/Contrato/Snapshots/rutas.json` |
| Dependencias | sin tocar | `git diff --name-only eb95cbc HEAD -- composer.json composer.lock` |
| `config/` | sin tocar | `git diff --name-only eb95cbc HEAD -- config/` |
| `app/` | **ocho ficheros** | `Informes/BolfinalesController`, `Alumnos/FoliosController`, `AlumnosController`, `Matriculas/MatriculasController`, `YearsController`, `NotasController`, `Models/Year`, `Models/Nota` |

> **La migración es bloqueante, como la del 25.** `2026_08_26_100000_interruptores_de_certificados`
> añade `usa_consecutivo_certificados` y `usa_folio_certificados` a `years`, y **el código de
> esta misma tanda las consulta en un camino vivo** —`Year::datos()`, que es de donde sale
> cualquier boletín y cualquier certificado—. **Con el código y sin la migración: 500 en todo.**
> El `migrate --force` va **entre el `pull` y el `config:cache`**, que es donde ya está en el
> bucle del paso 1.
>
> **Y lo que hace que sea segura: no siembra ningún valor por defecto.** Deriva los dos
> interruptores de lo que cada colegio hace hoy —`contador <> ''`, que es la condición que el
> front ya usaba para ocultar cada casilla—, así que **ningún colegio imprime nada distinto el
> día del despliegue**.

### Qué se le nota a un colegio

| | |
|---|---|
| **El boletín final va de 3.820 consultas a 455** (GEMELO-1). Es la queja de los 24–63 s y las caídas bajo carga | `docs/migracion/noche-2026-08-25/gemelo-1.md` |
| **La ficha del alumno crea las notas que faltan** — 240 huecos medidos | `05 §234` |
| **Fijar el consecutivo de certificados pasa a ser de secretaría**, y contesta **403** al resto del personal | [`cert-2`](migracion/noche-2026-08-26/cert-2.md) |
| **Mover el consecutivo deja rastro en `auditoria`**, tanto al quemarlo abriendo el certificado como al fijarlo a mano | [`cert-2 §3`](migracion/noche-2026-08-26/cert-2.md) |
| **El consecutivo y el folio pasan a ser OPCIONALES por colegio.** Nada cambia de aspecto: cada colegio arranca como está hoy. Lo que cambia es que **el que no imprime el número deja de gastarlo** — hasta ahora su contador subía solo en cada apertura | [`21 §4`](migracion/21-certificados-y-folios.md) |
| **El folio deja de fabricarse.** Ya no se escribe `año-alumno_id` al matricular, y `GET folios/iniciar` —que llenaba todos los huecos del año de una sentencia, y **no lo llama ningún cliente**— contesta 409. Los folios ya escritos **se quedan**: borrarlos cambia lo impreso y es decisión aparte | [`21 §4.3`](migracion/21-certificados-y-folios.md) |

### Lo que hay que decirle al front el día que esto se despliegue

> **Cada aviso lleva un estado, y no está en futuro por una razón que costó un día entero.**
> El bloque equivalente de la tanda anterior decía *«`myvc_flutter` tiene tres interruptores
> esperando el despliegue»* **y seguía diciéndolo después de desplegar**: Flutter acabó
> pidiendo que se fusionara y desplegara una rama que no existía, y que se escribiera un
> endpoint que llevaba tres días en los quince. **Un pendiente escrito en futuro no envejece a
> «hecho»: envejece a mentira.** Lo midió `8myvc-43` el 26 ago (`f5f6235`).
>
> **Por eso el paso 3 de abajo cierra estos avisos en el mismo commit que anota el
> despliegue.** Si alguno se queda en `PENDIENTE` después de desplegar, está mintiendo.

| | aviso | estado |
|---|---|---|
| **A** | los dos 403 de `cambiar-contador-*` | **PENDIENTE** |
| **B** | **veintiún respuestas cambian de forma**: les llegan dos campos nuevos | **PENDIENTE** |
| **C** | `aumentar_contador`: omitir la clave | **PENDIENTE** |

**Ninguno se entera solo, y A y B son de las de «quién puede llamarla».** El detalle en
[`cert-2 §6`](migracion/noche-2026-08-26/cert-2.md) y el reparto completo de qué hace el
backend y qué les toca a ellos en `myvc_front/TAREAS-AUDITORIA-CERTIFICADOS.md`:

1. `PUT bolfinales/cambiar-contador-certificados` y `-folios` **contestan 403 a quien no sea
   administrativo**. Las dos pantallas que llaman a la primera —`certificadoEstudioDir.html` de la
   vieja y `certificados-estudio.ts` de `app2`— **enseñan el control sin mirar el rol**, así que un
   docente verá «Contador no guardado». Lo que toca allí es **esconder el control**, no cambiar la
   llamada. *(`-folios` no lo llama nadie vivo.)*
2. **Hay dos interruptores nuevos que configurar**, `usa_consecutivo_certificados` y
   `usa_folio_certificados`, y **cambian la forma de veintiún respuestas** —las
   instantáneas de contrato lo fijan—. **El cambio es aditivo: no quita ni renombra ningún
   campo**, así que ningún cliente se rompe por recibirlos; pero conviene saber dónde caen:

   | endpoints | qué significa para vosotros |
   |---|---|
   | `GET years` · `years/colegio` · `years/trashed` | **aquí os vienen bien**: es de donde la pantalla de configuración los va a leer |
   | los 13 de `boletines/*` y `bolfinales/*` | el objeto `year` viaja dentro del boletín y del certificado; es donde tenéis que mirar el interruptor para esconder las casillas |
   | `informes/datos` · `piars-config` · `grupos-con-disciplina` · `notas/actuales-alumnos` | por arrastre, no os toca nada | El front
   tiene que (a) ocultar las dos casillas por el interruptor en vez de por «la columna está
   vacía», y (b) ofrecerlos en la pantalla de configuración de certificados, que es donde el
   colegio los va a buscar. Hasta que lo haga **no se rompe nada**: la derivación deja a cada
   colegio como estaba.
3. `aumentar_contador` hay que **OMITIRLO**, no mandar `false`. El backend ya no quema con la
   cadena `"false"` desde el 25, pero **las copias de `myvc_front` de los quince colegios van a
   versiones distintas** y esa medición no las ve.

### Y la tabla de arriba se remide, no se suma

**Se recalcula cuando la tanda crece.** La del 25 decía «ninguna migración» con cuatro dentro,
porque se había medido antes de fundir cuatro ramas.

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

## Paso 3. Cerrar los avisos — **en el mismo commit, no en uno aparte**

**El despliegue no ha terminado cuando los quince tienen el hash.** Termina cuando el
documento deja de prometer cosas que ya ocurrieron.

En el **mismo commit** que anota la tanda como desplegada:

1. **Cada aviso de la tabla de arriba pasa de `PENDIENTE` a `DADO el <fecha>`**, o se borra
   si dejó de aplicar. No se deja «para luego»: un commit aparte al final es el que no se
   hace cuando la sesión se corta.
2. **Lo mismo en `ESTADO-ACTUAL.md`**, que lleva su propia copia de los avisos.
3. **Y si un aviso era para un cliente concreto, se le dice a ese cliente** — que se entere el
   documento no es que se entere quien tiene que publicar.

> **Por qué esto es un paso del procedimiento y no una buena costumbre:** ya falló una vez, y
> el coste no fue la línea desactualizada. Fue que la sesión de `myvc_flutter` planificó una
> vuelta entera —fusionar una rama, desplegarla, escribir un endpoint— **sobre trabajo que
> llevaba tres días en producción**. El documento no estaba viejo: estaba diciendo algo falso
> con la cara de un pendiente.

## Paso 4. Volver atrás

```bash
cd "$d" && git checkout <commit-anterior>
php artisan config:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache
```

**Las migraciones del 25 ago se quedan puestas y por eso esto vale:** son aditivas y el código
viejo las ignora. **No corras el `down`.**

## Paso 5. Las tres trampas que muerden aquí

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
