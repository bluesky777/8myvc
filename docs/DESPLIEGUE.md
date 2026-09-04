# Desplegar

**Los comandos, y nada más.** El porqué de cada fila —topología, las siete trampas, qué trajo
cada tanda, el bucle del front— está en [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## 🛑 CONGELADO: `myvc_flutter` está en revisión — no se despliega hasta que salga

> **Joseth, 4 sep 2026: la espera tiene fecha — el 10 de septiembre.** *«No quiero desplegar
> nada hasta no estar seguro de que no me dañe la verificación de la Play Store de
> Flutter.»* La fecha cuadra con la aritmética de abajo: seis días de revisión el 2 sep sobre
> catorce sale ~10 sep. **Pero el criterio es el suceso, no el calendario**: lo que
> desbloquea es *«la app salió de revisión»*, así que si la tienda tarda más, la fecha se
> mueve con ella y no al revés.
>
> **Y lo que la espera compra no es que la tanda se vuelva inocua: es que vuelva a haber
> salida.** Mientras la app está en revisión no se puede publicar una corrección; después,
> sí. La espera no cambia el riesgo, cambia lo que cuesta equivocarse.
>
> ### Y la pregunta que este bloque plantea YA ESTÁ CONTESTADA, medida el 4 sep 2026
>
> Las dos, y las dos dan **no**:
>
> | la pregunta | medido en `~/DESARROLLOS/myvc_flutter` |
> |---|---|
> | ¿llama a `POST tardanzas/login/traer-datos`? | **no** — cero. *(Un `grep` ingenuo da 2 y son `traerDatosDeDisciplina`, que llama a `/grupos/con-disciplina`: casó por subcadena.)* |
> | ¿lee alguna de las ocho claves del evento? | **no lee el evento siquiera.** De `to-me` saca `horario_hoy`, `horario_version_id`, `publicaciones`, `alumnos` y `ausencias_periodo` (`MuroApi.dart:109`). **La clave `eventos` no la toca.** |
>
> **Y no depende de qué commit esté en la tienda, que es lo que lo cierra**: `git log -S` sobre
> los **151 commits** del repositorio dice que **ninguna versión de esta app ha leído nunca
> `'eventos'` ni ha llamado nunca a `traer-datos`**. Así que la respuesta no cambia con la
> versión concreta que se subió a revisar.
>
> *Las tres claves que sí aparecen —`created_at`, `created_by`, `deleted_at`— están en otros
> modelos: `SituacionModel`, `AsistenciaModel`, `PublicacionModel`, `MuroApi` (el muro) y
> `HistorialNotaApi`. **Ninguna es el evento del calendario**, así que no las toca el aviso
> K. Se dicen porque un recuento que sólo mire nombres de clave las contaría.*
>
> **Y la lista de «lo único que un cliente puede perder» se comprobó completa**: la otra
> candidata era `notas_finales.nota` pasando a `DECIMAL` —un cambio de **tipo**, que es el
> disparador que una lista escrita en términos de forma no ve—, y **no entra en esta tanda**:
> `2026_08_30_200000_notas_finales_en_decimal` **ya está en `9474b50`**, la base desplegada.
> Las que faltan son **ocho** y ninguna cambia un tipo que un cliente lea.
>
> **Lo que esto NO dice, y es lo que sigue justificando esperar:** contesta por la
> *compatibilidad de la app*, no por la *ejecución del despliegue*. El ⛔ sigue entero — con
> el código nuevo y la base sin migrar **no se puede ni iniciar sesión** —, y eso no lo
> arregla ninguna medición del cliente: lo arregla hacer el despliegue bien, colegio a
> colegio, `git pull` y `migrate --force` seguidos.

**Joseth, 2 sep 2026: la app lleva seis días en revisión y no puede publicar una corrección
hasta que pasen los catorce.** O sea que si esta tanda rompiera algo de la app, **el arreglo
tardaría más de una semana en llegar a la tienda** y los quince colegios lo comerían entero.
Por eso esto va **antes** del bloque de la tanda y no dentro: la tanda está lista, y aun así
**no se despliega**.

**Qué se midió antes de escribir esto, para que la espera sea una decisión y no un miedo.** De
los 191 commits, lo único que un cliente puede **perder** son dos cosas — todo lo demás es
aditivo, comprobado sobre los snapshots del rango, no sobre los mensajes de los commits:

| Lo que desaparece | Dónde |
|---|---|
| `POST tardanzas/login/traer-datos` → **404** | la única ruta retirada de las 24 que se mueven |
| **ocho claves** de cada evento del calendario | `GET ChangesAsked/to-me`, aviso **K** |

**Así que la pregunta que decide el despliegue es sólo ésta: ¿la versión que está en revisión
llama a esa ruta o lee alguna de esas ocho claves?** Si la respuesta es no a las dos, la tanda
es inocua para la app y lo que queda por decidir es otra cosa. Si es sí a cualquiera de las
dos, **se espera** — no hay término medio, porque la salida sería una versión nueva en la
tienda y eso es justo lo que no hay.

> **Y una que NO es de esta tanda pero muerde ahora mismo: no escribas un número en
> `APP_MOVIL_VERSION_MINIMA` de ningún colegio mientras la app esté en revisión.** El
> mecanismo de `version_minima_app` **ya está desplegado desde el 31 ago** y está **inerte
> porque el `.env` viene vacío** — mandar el campo bloquea a quien tenga una versión menor, y
> hoy **no hay ninguna versión en la tienda a la que actualizar**. Escribir ahí un número
> durante la ventana deja al colegio entero fuera **y sin salida**, y la salida sería
> exactamente lo que no se puede hacer: publicar. El porqué entero está en
> `config/aplicacion-movil.php`, que lo lleva escrito encima de la línea.

### CONTESTADO el 2 sep 2026 por `myvc-flutter-14`: la tanda es INOCUA para la app

Verificado **contra el commit exacto que está en revisión** —`083bedf`, `1.0.0+3`, el último que
tocó `lib/` antes del envío del 25 ago—, no contra el `main` de hoy, que es otro árbol:

| | |
|---|---|
| `POST tardanzas/login/traer-datos` | **no la llama**: cero apariciones en `lib/` |
| las ocho claves del calendario | **seguro**: `MuroApi.traerMuro` lee tres claves de `to-me` —`horario_hoy`, `publicaciones`, `alumnos`— y **`eventos` no se lee en ningún rol** |
| campos nuevos | **inocuos**: ni `disallowUnrecognizedKeys`, ni `checkedCreate`, ni iteración de claves; todos los `fromJson` leen por nombre |
| el 403 de `cambiar-contador-*` | **no las llama**: cero apariciones |

**Así que las dos preguntas que congelaban esto están contestadas que no, y por el lado de la app
la tanda se puede desplegar.**

### Y LA QUINTA, QUE NO ES DE ESTA TANDA Y HAY QUE MIRARLA IGUAL — HOY

El bloqueo por `version_minima_app` **sí está activo en la versión en revisión**
(`lib/Utils/VersionMinima.dart`, entró el 24 ago, un día antes del envío). Con `buildNumber = 3`:
**campo ausente, 0 o negativo no bloquea; un valor ≥ 4 manda TODAS las rutas a la pantalla de
actualizar**, y no hay nada a lo que actualizar porque el build 3 es lo único que existe.

**Pero esto NO lo trae la tanda, y por eso esperar no protege de ello.** Medido: el rango
`9474b50..HEAD` **no toca** `VersionMinimaDeLaApp.php`, ni `config/aplicacion-movil.php`, ni
`.env.example`. El mecanismo se escribió el 24 ago (`1e98e28`) y **está desplegado en los quince
desde el 31 ago**. O sea que **el riesgo es idéntico se despliegue o no**, y si algún colegio
tuviera un número ≥ 4 la app **ya estaría bloqueada allí ahora mismo**.

Lo que ship el repo es seguro: `config/aplicacion-movil.php` hace `env('APP_MOVIL_VERSION_MINIMA')`
**sin valor por defecto** —ausente = campo no enviado— y `.env.example` la trae **vacía**.

**La comprobación, que hay que correr aunque no se despliegue nada** (y sobre todo en `demo`, que
es donde entra el revisor de Google con un login nuevo):

```bash
for d in /home/micolev1/*.micolevirtual.com/8myvc; do
  printf '%-45s %s
' "$d" "$(grep -E '^APP_MOVIL_VERSION_MINIMA=' "$d/.env" || echo 'ausente')"
done
```

### CORRIDO el 2 sep 2026 — **limpio en todos, y de paso salió un colegio de más**

```
16 carpetas de colegio  ->  ausente (ni la línea)
demo                    ->  APP_MOVIL_VERSION_MINIMA=   (presente y VACÍA)
```

**Nadie está bloqueado y la revisión de la app no corre peligro.** `demo` —el único que toca el
revisor de Google— la tiene puesta pero **vacía**, y eso es exactamente el estado seguro:
`VersionMinimaDeLaApp` hace `if ($puesto === null || $puesto === '') return null;`, así que
**«ausente» y «vacía» son lo mismo** y el campo no viaja a nadie. No hay nada que borrar.

> **Y el barrido destapó otra cosa, que no es de la app: el servidor tiene DIECISÉIS colegios, no
> quince.** Las carpetas que devolvió el bucle son 17: dieciséis colegios más `demo`. `CLAUDE.md`
> dice **«Quince colegios»** desde la baja del 25 ago, y **nadie sumó `lal`**, que entró el 30 ago
> por el traslado de `9474b50` — el commit que es justo la base de esta tanda.
>
> `DESPLIEGUE-REFERENCIA.md` ya lo decía por su lado sin que cuadrara con el otro: *«quince
> colegios + `demo` + `edilson` + **la nueva de `lal`**»*. O sea que **los dos documentos llevaban
> discrepando desde el 30 ago** y el servidor le da la razón a la referencia.
>
> **Por qué importa el día del despliegue y no es cosmético:** el bucle escribe sobre
> `*.micolevirtual.com/8myvc`, así que **alcanza a los dieciséis y a `demo`**. Quien despliegue
> contando quince va a ver un colegio de más y tendrá que decidir a las tres de la mañana si es
> uno legítimo o algo que sobra — y la respuesta es que es legítimo. **El número de `CLAUDE.md` lo
> mueve Joseth**, no una sesión; queda aquí medido para que el día del despliegue nadie tenga que
> averiguarlo.

**Lo que se espera ver: ausente o vacía en los quince. Y lo que se busca es CUALQUIER valor no
vacío, no «un valor ≥ 4»** — el porqué está justo debajo y corrige lo que decía esta línea.

> **Corrección del 2 sep 2026, de `myvc-flutter-14`: el suelo NO es el build 3.** La app se
> reparte por **dos canales** (`publicacion-play.md`): Play —en prueba cerrada, 27 verificadores—
> y **APK directo**, «enlace en la web del colegio, WhatsApp». Quien instaló por WhatsApp tiene el
> build que llevara ese APK, y **nadie garantiza que sea el 3**: puede andar por el **1 o el 2**.
>
> Así que **un `3` puede estar bloqueando gente ahora mismo** —a todo el que ande por el 1 o el
> 2—, y un `2` bloquearía a los del 1. Por eso el criterio no es un umbral: **cualquier valor no
> vacío es sospechoso** hasta saber qué builds circulan en ese colegio, y se borra hoy.

> **Y no se arregla poniendo «un número bajo», que es la salida que parece prudente.** Un `3` es
> una trampa cargada por los dos lados: hoy bloquea a los de los canales directos, y el día que se
> publique el build 4 quien ponga `4` bloquea de golpe a todo el que siga en el 3. Mientras no haya
> una versión más nueva **en la tienda**, lo correcto es **ausente o 0**.

> **Y esto no lo va a delatar nada más, así que el barrido no es una confirmación: es la única
> detección que existe.** Medido por `myvc-flutter-14` el 2 sep 2026: el camino del bloqueo
> —`VersionMinima`, `RouteGenerator`, `ActualizarScreen`— **no tiene ni una llamada a la
> analítica**. Cero. Y no es que la app no mida: hay eventos para `planilla_abierta`,
> `notas_guardadas`, `usuarios_abierta`, `situacion_creada`… pero **ninguno para la única pantalla
> que significa «esta persona no puede usar la app»**. Un colegio con un número malo tiene a sus
> usuarios contra un muro **y eso no aparece en ningún contador, ningún evento y ningún log**.
> Queda como deuda del lado de Flutter para el próximo build; no llega al que está en revisión.

**Esto se levanta cuando la app salga de revisión**, o antes si las dos preguntas de arriba se
contestan que no. Lo levanta Joseth, no una sesión. — **Contestadas que no el 2 sep**; lo que
queda por decidir ya no es de la app.

---

## ⛔ TANDA PENDIENTE — 191 commits desde `9474b50`. SIN LAS MIGRACIONES NO SE PUEDE NI ENTRAR

**El aviso que había aquí decía «los tres boletines contestan 500», y se quedaba corto por dos
órdenes de magnitud.** Lo que cae con el código nuevo y la base sin migrar **no es una pantalla:
es el colegio entero, empezando por el login**.

`2026_09_02_100000_nivelaciones_columnas` añade `years.regla_nivelacion`, y esa columna la nombra
`ContextoDeUsuario::construir()` en **las cuatro ramas** del `switch`
(`app/Services/ContextoDeUsuario.php:135, 165, 195, 228`) — o sea en el `SELECT` que arma
`$this->user`. Y ese `SELECT` no lo dispara un controlador: lo dispara **el propio guard**, porque
`ExigirAutenticacion::handle()` llama a `User::fromToken()`
(`app/Http/Middleware/ExigirAutenticacion.php:39`), que va a parar a `construir()`. **Ninguna
petición llega a su método.**

| Sin la migración, con el código nuevo dentro | |
|---|---|
| **547** de las 565 rutas de `api/` llevan `auth.token` | **500 en el guard**, antes del controlador |
| `POST login` (`LoginController:51`) y `POST auth/login` (`Auth\SesionController:63`) | **500** — montan el contexto ellos mismos: **no se puede iniciar sesión** |
| Lo que queda en pie | `login/logout`, `login/recuperar-clave`, `login/reset-password`, `login/ver-pass`, `login/crear-prematricula`, `publicaciones/ultimas`, `colegio/logo` y el quiosco de `tardanzas/*` — que no pasan por el contexto |

**No es teoría.** Le pasó al docker la madrugada del 2 sep 2026 al fusionar, y **lo detectó la
sesión del front, no nosotros**. Reproducido aquí contra una base sin migrar: la consulta del
contexto contesta `SQLSTATE[42S22] Unknown column 'y.regla_nivelacion' in 'field list'`.

### Lo que es esta tanda, medido el 2 sep 2026 sobre `9474b50..347f137`

| | | comprobado con |
|---|---|---|
| commits | **191** | `git rev-list --count 9474b50..HEAD` |
| `app/` | **54** ficheros | `git diff --name-only 9474b50 HEAD -- app/ \| wc -l` |
| `routes/` | **7** ficheros — **las rutas SÍ se movieron: 543 → 566** (24 nuevas, **1 retirada**) | `git diff --name-only 9474b50 HEAD -- routes/` · `route:list --json` |
| `config/` · `composer.json`/`.lock` · `database/schema/` | **0** | `git diff --name-only 9474b50 HEAD -- config/ composer.lock database/schema/` |
| **migraciones** | **SIETE**, y **cinco son bloqueantes** | `git diff --name-only 9474b50 HEAD -- database/migrations/` |

> **La base es `9474b50` y no `eb95cbc`, y esto costó una medición entera.** El rango que había
> escrito arrancaba en `eb95cbc`, que es la tanda del **25 ago**; la del 25–30 ago
> (`eb95cbc..9474b50`, 44 commits) **se desplegó el 31** y está registrada más abajo en este mismo
> documento. Medir desde `eb95cbc` da 219 commits y **cuenta dos veces lo ya desplegado**: no es
> sólo ruido, es que la tabla de migraciones acababa listando `2026_08_30_200000_notas_finales_en_decimal`
> como pendiente cuando lleva dentro desde el 31. *Un rango sin desplegar se remide entero cada vez
> que se le toca; y lo primero que se remide es **desde dónde**.*

### Las siete, ordenadas por lo que tumban

| Migración | Qué rompe si falta | Radio |
|---|---|---|
| `2026_09_02_100000_nivelaciones_columnas` | **`years.regla_nivelacion`: el guard y los dos logins.** Y aparte, `notas.nota_original` y las tres de `notas_finales` las nombran la planilla (`NotasController:256` y `:306`), los boletines (`BoletinesController:298`, `Boletines2Controller:224`) y el boletín final (`BolfinalesController:529`) | **el colegio entero** |
| `2026_08_31_200000_puestos_con_bol_independiente` | `BoletinIndependiente::puestosCuentanIndependientes()` hace `SELECT puestos_con_bol_independiente FROM years WHERE id = ?` **sin condición y sin rescate** (`app/Services/BoletinIndependiente.php:394`), y todos los boletines pasan por ahí | los tres boletines, los certificados, preescolar, promovidos, `editnota` y los cuatro informes de puestos |
| `2026_09_02_200000_nivelacion_de_la_definitiva` | las dos columnas del acta de la definitiva, nombradas en `DefinitivasPeriodosController:524-526` y `:718-721` | `PUT definitivas_periodos/nivelar` |
| `2026_09_02_300000_acta_de_la_recuperacion_final` | **rompe una ruta que ya existe**, no sólo las nuevas: `putUpdateRecuperacion` nombra `nivelada_at, nivelada_por, observacion` en su `UPDATE`, su `INSERT` y su `SELECT` (`:818`, `:856`, `:884`) | `PUT definitivas_periodos/update-recuperacion` |
| `2026_09_03_100000_rubricas` | cinco tablas nuevas y `subunidades.rubrica_id`. **Nadie fuera de `RubricasController` las nombra** —comprobado uno a uno: la planilla, unidades, asignaturas y `ChangeAsked` pasaron a nombrar sus columnas justamente para que `rubrica_id` no se les colara— | las **10** rutas de `rubricas/` |
| `2026_09_04_100000_horario_versiones` | **`POST horario/versiones` pasa de 501 a 500.** `postVersiones` se implementó en `371062c` (2 sep, 18:29) y **hace `INSERT` en las tres tablas nuevas**, así que sin ellas revienta. `getVersiones` y `putOficial` **siguen a 501** y no las tocan, y **`years.horario_version_id` sigue sin leerla nadie** —`YearsController` la sirve por `SELECT y.*`, y a un `*` la columna que falta no le duele— | **1 ruta**, y detrás de `esAdministrativo`: el colegio no se cae, pero quien suba un horario recibe un 500 |
| `2026_08_31_100000_retirar_boletin_independiente_de_matriculas` | nada del código nuevo: **retira** `matriculas.boletin_independiente`, que ya no lee nadie | ninguno hacia delante — **pero mira la fila de abajo** |

**Seis de las siete son aditivas en `up()`** —`ADD COLUMN` y `CREATE TABLE`, sin un solo `UPDATE`
ni back-fill—, leídas una a una. **La que falta —`2026_08_31_100000`— no: hace `dropColumn`**, y
eso cambia dos reglas de este documento. (Va la última de esta tabla porque está ordenada por lo
que tumba, pero es la **primera** por orden de ejecución, y eso importa para el rollback.)

> **La del `dropColumn` no rompe hacia delante** —retira algo que el código nuevo ya no lee— pero
> sí hacia atrás, que es la fila de abajo.
>
> **Y aquí había escrito que la de `horario_versiones` «no rompe en ninguna dirección, porque su
> código son tres 501». Dejó de ser cierto el mismo día, y la forma en que envejeció es nueva.**
> Cuando se midió, los tres métodos eran `501` y ninguna consulta nombraba las tablas; `371062c`
> implementó `postVersiones` unas horas después y ahora hace `INSERT` en las tres. **La medición era
> correcta el día que se hizo: lo que cambió no es la cifra ni el rango, es el código que se midió.**
> Lo levantó `8myvc-23` el 2 sep 2026. Teníamos catalogado que el resumen de un rango envejece cuando
> se le añade un commit —y por eso se remide entero—; **ésta envejece sin que la cifra ni el rango se
> muevan**, y no la teníamos escrita. La regla que deja: *una afirmación sobre lo que el código hace
> caduca cuando el código cambia, aunque el número que la acompaña siga siendo el mismo.*
>
> **Aun así, las dos entran en el mismo `migrate` y en la comprobación**: la pregunta que contesta esa
> comprobación es *«¿está la tanda entera dentro?»*, no *«¿qué se rompe?»*. Un colegio con seis de
> siete es un colegio que nadie sabe en qué estado está.

### La que retira una columna, y las dos reglas que cambia

`2026_08_31_100000` hace `dropColumn('boletin_independiente')` sobre `matriculas`. El código que
está **hoy desplegado** en los quince (`9474b50`) nombra esa columna en **cinco consultas vivas** de
`app/Services/BoletinIndependiente.php` (líneas 73, 136, 202, 280 y 297). De ahí salen dos cosas:

1. **El orden `git pull` → `migrate` deja de ser una costumbre y pasa a ser obligatorio.** Migrar
   antes de traer el código deja al colegio con el código viejo y la columna ya retirada: los
   boletines caen igual, sólo que por el otro lado. **No hay orden sin ventana**; la hay en las dos
   direcciones y por eso los dos comandos van seguidos, en la misma vuelta del bucle y por colegio.
2. **Volver atrás dejando las migraciones puestas ya NO vale para esta tanda.** El «Paso 4» de este
   documento dice que las migraciones se quedan porque son aditivas y el código viejo las ignora:
   cierto para todas las tandas anteriores y **falso para ésta**. Ver el aviso del Paso 4.

**Y el estado peor no es «sin migrar»: es «migrado a medias».** Con `2026_08_31_100000` corrida y
las siguientes no, la columna vieja ya no está y las nuevas todavía no: **no funciona ni el código
viejo ni el nuevo**. No es un supuesto: **dos** bases de sesión aparecieron exactamente así la
noche del 2 sep 2026 —`migrations` parada en `2026_08_31_100000`, `matriculas.boletin_independiente`
ya retirada y `years.regla_nivelacion` sin llegar—, y ninguna de las dos servía para nada. **Cómo
llegaron a ese estado no se sabe**: reconstruirlas sale completo. Lo que importa aquí es que ese
estado **existe y no lo delata ningún error**. Por eso: **si el `migrate` de un colegio falla, ese
colegio se arregla antes de tocar el siguiente.**

### El orden por colegio, y no hay otro

`git pull` → `php artisan migrate --force` → **la comprobación de abajo** → un boletín y un login
**antes de pasar al siguiente colegio**.

### La comprobación de diez segundos, que ahora son siete migraciones y no una

`php artisan migrate:status` **no basta**: dice que la migración corrió, no que la columna esté.
Esto pregunta por el esquema, que es lo que leen las consultas:

```bash
php artisan tinker --execute='$f=[]; foreach ([["years","regla_nivelacion"],["years","puestos_con_bol_independiente"],["years","horario_version_id"],["notas","nota_original"],["notas_finales","nota_nivelacion"],["recuperacion_final","nivelada_at"],["subunidades","rubrica_id"],["profesores","tono"]] as $c) { if (!Schema::hasColumn($c[0],$c[1])) $f[]=$c[0].".".$c[1]; } foreach (["rubricas","rubrica_criterios","rubrica_niveles","rubrica_descriptores","rubrica_valoraciones","horario_versiones","horario_lecciones","horario_pieza_docente"] as $t) { if (!Schema::hasTable($t)) $f[]="tabla ".$t; } if (Schema::hasColumn("matriculas","boletin_independiente")) $f[]="matriculas.boletin_independiente SIGUE AHI"; echo ($f ? "FALTA -> ".implode(" | ",$f) : "OK - las ocho dentro").PHP_EOL;'
```

> **`profesores.tono` se le añadió el 4 sep 2026, y la tanda pasó a OCHO migraciones.** Se
> toca **esto** y no las tablas de arriba: aquéllas son lo que se midió sobre `347f137` y se
> remiden el día del despliegue; **este fragmento es una comprobación que alguien va a
> ejecutar**, y una comprobación que no pregunta por la octava columna contesta «OK - las
> siete dentro» en un colegio al que le falta una. La decisión de la columna es de Joseth
> (`23-horarios.md` §9.bis.3); el título de este bloque sigue diciendo siete porque es el de
> la medición, no el de la comprobación.

**Dice qué falta, no sólo que falta**, y **tiene control negativo**: probado el 2 sep 2026 contra la
base migrada (`OK - las ocho dentro`) **y** contra una sin migrar y con nombres inventados, donde
imprime la lista. Un `OK` que no sabe fallar es el que archiva el asunto.

**La otra acción del día, que no es una tabla:** correr `tools/independientes-sin-estructura.php`
**en los quince**, uno por uno, después de migrar. Contesta la §9.1 —qué alumnos están marcados y
**no tienen ni una unidad propia**, cuya definitiva sale 0 sin que nadie reciba un error—, y **sin
la tabla contesta `exit=2 · NO CONCLUYENTE` a propósito**: un `0` limpio sería la respuesta que
archiva el asunto justo en el colegio donde no se ha mirado nada. Lo medido hasta hoy es **cero
marcados en desarrollo**, que **no** es «cero en los quince»: eso sólo se sabe allí.

### Los avisos para el front que viajan en esta tanda

| | aviso | estado |
|---|---|---|
| **K** | `GET ChangesAsked/to-me` deja de mandar **ocho** columnas de cada evento del calendario y conserva nueve. **Aquí decía «nueve» y era la cifra de las que se QUEDAN**, contada como si fueran las que se van; medido sobre el snapshot el 2 sep 2026, el evento pasa de 17 claves a 9. Las ocho que se van son `created_at`, `created_by`, `created_by_nombres`, `deleted_at`, `deleted_by`, `type`, `updated_at` y `updated_by`. Una de ellas, **`created_by_nombres`, la pinta la aplicación vieja** en el tooltip del evento (`AnunciosCtrl.ts:596`): al desplegar dirá **«Por: undefined»** hasta que se arregle allí, que es una línea | **POR AVISAR** — decidido a sabiendas el 2 sep 2026 |
| **L** | **`POST tardanzas/login/traer-datos` desaparece**: pasa a 404. Decisión de Joseth del 2 sep. El único llamante de toda la máquina es `tardanzasMyvc-old` (último commit feb 2020), y Joseth confirmó que ese repositorio está inactivo — el dato que lo cerró **no estaba en el repositorio** | **DECIDIDO** — es la única ruta que la tanda quita |
| **M** | **`regla_nivelacion` aparece en el bloque de la sesión**, en las cuatro ramas (alumno, acudiente, profesor y usuario). Es un campo **nuevo**, para previsualizar en el diálogo de nivelación qué nota va a quedar (22 §1.4 y §5.1) | **ADITIVO** — Flutter no se rompe: `ConfiguracionColegio.deLogin` lee campo a campo y no hay `json_serializable` ni `freezed`. Medido, no supuesto (22 §3.2bis) |
| **N** | **Campos nuevos de nivelación en respuestas que ya existían**: la planilla (`PUT notas/detailed`), los boletines, el boletín final y `PUT editnota/alum-asignatura`. Qué respuesta abre las columnas nuevas **a propósito** y cuál las tiene **congeladas** está decidido sitio por sitio en la tabla de [22 §3.4](migracion/22-nivelaciones.md) y en el [27](migracion/27-nivelaciones-en-los-informes.md) | **ADITIVO** — ningún cliente pierde una clave |
| **O** | **25 rutas nuevas**: las 10 de `rubricas/`, las 4 de nivelar, las 5 de `boletin-independiente/`, las **4** de `horario/` —**las cuatro con cuerpo: ninguna contesta ya 501**—, `GET grupos/{grupo_id}/alumnos-de/{que}` y `GET colegio/logo`. **Todas menos `colegio/logo` llevan `auth.personal`**: un alumno o un acudiente que las llame recibe **403**, y las de nivelar además exigen `periodos.profes_pueden_nivelar` al profesor (`User.php:425`) | **POR AVISAR** — es de las de «quién puede llamarla» |

> **La fila O decía «24» y «las 3 de `horario/`», y se ha corregido a mano el 4 sep 2026
> — que es una excepción a la regla de este documento y por eso va escrito.** Aquí las
> tablas son *lo que se midió el día que se midió* y se remiden el día del siguiente
> despliegue; **ésta no podía esperar**, y la diferencia es que **las demás filas describen
> el servidor y ésta es un mensaje que sale hacia fuera**. Está marcada **POR AVISAR**: el
> front construye su menú con lo que le dijeron, así que un aviso que nombra tres rutas
> cuando hay cuatro **deja la cuarta sin avisar sin que se note el hueco** — no hay error
> que lo delate, sólo un menú al que le falta una entrada. Y no se arregla sola al remedir:
> remedir contesta *«¿siguen contestando 501?»*, **nadie recuenta un «24» si no sabe que hay
> que hacerlo**. Lo decidió Joseth ese día.
>
> **Las 25 están CONTADAS contra la base desplegada, no sumadas** — que es lo que este repo
> exige de un número que se escribe: `9474b50` declara **543** rutas y `HEAD` **567**;
> comparados los dos conjuntos de URIs, **25 entran y 1 se va** (`POST
> tardanzas/login/traer-datos`, el aviso L), y 543 + 25 − 1 = 567. La cuarta de `horario/`
> es `GET horario/versiones/{id}/lecciones` (23 §9.bis), con `auth.personal` como las otras
> tres.
>
> **Lo que NO se ha tocado y sigue esperando al día del despliegue** es la fila de
> `2026_09_04_100000_horario_versiones` de la tabla de migraciones, que dice que sólo
> `POST horario/versiones` pasa de 501 a 500: ésa describe el servidor, falla contra él, y
> su caducidad está escrita en [23 §11.5](migracion/23-horarios.md).

**Nivelar son rutas NUEVAS por diseño, y esto es lo que hay que decirle al front:** `notas/update` y
`notas/lote` **no pueden** aprender a nivelar. `myvc_flutter` es una sola app para los quince y una
versión vieja convive meses, así que un 95 tecleado desde el móvil se guardaría **topado** sin que
nadie lo pidiera. El porqué, en [22-nivelaciones.md](migracion/22-nivelaciones.md).

**Y el panel adelgaza a la mitad en la misma tanda**, que es lo que hay que mirar después de
desplegar: 274→157 KB el superusuario, 279→162 el docente, 225→112 el alumno, 218→108 el
acudiente, y **el panel del alumno pasa de ~620 ms a ~24 ms**.

---

## La tanda ANTERIOR — desplegada el 31 ago 2026 en `9474b50`

> **Esto es el registro de lo que ya salió, no un «no hay nada que desplegar».** El título
> decía «No hay tanda pendiente» y era cierto el 31 de agosto; con el bloque ⛔ de arriba encima,
> un título así es lo que se lee de refilón cuando alguien baja a buscar los comandos.

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

> ### ⛔ Esto NO vale para la tanda pendiente de arriba, y es la excepción de la regla
>
> `2026_08_31_100000_retirar_boletin_independiente_de_matriculas` **retira**
> `matriculas.boletin_independiente`, y el código que hay hoy en los quince (`9474b50`) la nombra
> en **cinco consultas vivas** de `app/Services/BoletinIndependiente.php` (73, 136, 202, 280, 297).
> Volver un colegio a `9474b50` **dejando la migración puesta le deja los boletines en 500**, que
> es justo lo que este paso existe para no hacer.
>
> Para volver atrás de esa tanda hay que **volver también el esquema**, y **no se puede volver sólo
> esa**: `2026_08_31_100000` es la **primera** de las siete por orden de ejecución, así que llegar a
> ella es deshacer las siete. Como corrieron en el mismo `migrate`, son un solo lote:
>
> ```bash
> php artisan migrate:status | tail -9      # confirma que el lote pendiente son las SIETE
> php artisan migrate:rollback --step=7     # las siete, en orden inverso
> ```
>
> **`--step=1` NO sirve aquí**: revierte la última, que es `2026_09_04_100000_horario_versiones`, y deja la
> columna retirada exactamente igual.
>
> **Y esto sí pierde datos, al revés que en las tandas anteriores.** El `down()` de la del
> `dropColumn` es exacto —devuelve la columna **a 0 en todas las filas, que es lo que había**: nunca
> llegó a tener un 1 en ninguna base—, pero los `down()` de las otras seis se llevan **lo que se
> haya registrado desde el despliegue**: las nivelaciones (`notas.nota_original` y compañía), las
> actas de la definitiva y de la recuperación, las rúbricas enteras con sus valoraciones y las
> versiones de horario subidas — que hoy son cero, porque el lote B no existe. Las
> notas que produjeron **no** se pierden: `nota` nunca dejó de ser la vigente. Antes de correrlo,
> mira si ese colegio ha nivelado algo.

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
