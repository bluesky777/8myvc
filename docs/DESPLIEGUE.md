# Desplegar

**Los comandos, y nada más.** El porqué de cada fila —topología, las siete trampas, qué trajo
cada tanda, el bucle del front— está en [DESPLIEGUE-REFERENCIA.md](DESPLIEGUE-REFERENCIA.md).

## 🛑 CONGELADO: `myvc_flutter` está en revisión — no se despliega hasta que salga

**Joseth, 2 sep 2026: la app lleva seis días en revisión y no puede publicar una corrección
hasta que pasen los catorce.** O sea que si esta tanda rompiera algo de la app, **el arreglo
tardaría más de una semana en llegar a la tienda** y los dieciséis colegios lo comerían entero.
Por eso esto va **antes** del bloque de la tanda y no dentro: la tanda está lista, y aun así
**no se despliega**.

**Qué se midió antes de escribir esto, para que la espera sea una decisión y no un miedo.** De
los **232** commits (remedidos el 4 sep 2026; eran 191 cuando se escribió esto), lo único que un
cliente puede **perder** siguen siendo dos cosas — todo lo demás es aditivo, comprobado sobre los
snapshots del rango, no sobre los mensajes de los commits, y **recomprobado entero el 4 sep**:
de las 25 respuestas que cambian de forma, **24 sólo ganan claves**.

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

**Pero esto NO lo trae la tanda, y por eso esperar no protege de ello.** Remedido el 4 sep 2026
sobre `9474b50..main`: el rango **no toca** `VersionMinimaDeLaApp.php` ni
`config/aplicacion-movil.php`. El mecanismo se escribió el 24 ago (`1e98e28`) y **está desplegado
en los dieciséis desde el 31 ago**. O sea que **el riesgo es idéntico se despliegue o no**, y si
algún colegio tuviera un número ≥ 4 la app **ya estaría bloqueada allí ahora mismo**.

> **Aquí decía además «ni `.env.example`», y eso dejó de ser cierto: el rango SÍ lo toca**
> (`cf06f72` y `41d2a22`). Lo que cambia es `MAIL_FROM_ADDRESS`, **no**
> `APP_MOVIL_VERSION_MINIMA`, así que la conclusión de arriba aguanta entera — pero la frase que
> la sostenía era falsa, y una conclusión correcta apoyada en una premisa falsa es la que nadie
> vuelve a comprobar. Remedido fichero a fichero, no de memoria.

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

**Lo que se espera ver: ausente o vacía en los dieciséis. Y lo que se busca es CUALQUIER valor no
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

## ⛔ TANDA PENDIENTE — 232 commits desde `9474b50`. SIN LAS MIGRACIONES NO SE PUEDE NI ENTRAR

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
| **547** de las 565 rutas de `api/` llevan `auth.token` — recontado el 4 sep 2026 sobre `main`, y sigue siendo la misma cifra | **500 en el guard**, antes del controlador |
| `POST login` (`LoginController:51`) y `POST auth/login` (`Auth\SesionController:63`) | **500** — montan el contexto ellos mismos: **no se puede iniciar sesión** |
| Lo que queda en pie | `login/logout`, `login/recuperar-clave`, `login/reset-password`, `login/ver-pass`, `login/crear-prematricula`, `publicaciones/ultimas`, `colegio/logo` y el quiosco de `tardanzas/*` — que no pasan por el contexto |

**No es teoría.** Le pasó al docker la madrugada del 2 sep 2026 al fusionar, y **lo detectó la
sesión del front, no nosotros**. Reproducido aquí contra una base sin migrar: la consulta del
contexto contesta `SQLSTATE[42S22] Unknown column 'y.regla_nivelacion' in 'field list'`.

### Desde dónde: sigue siendo `9474b50` — y esto es lo que se comprobó y lo que NO

**Va primero porque es lo único cuyo error contamina las cinco cifras de abajo**, y este documento
ya perdió una medición entera por equivocarse aquí: con `eb95cbc` de base acababa listando como
pendiente una migración que llevaba días desplegada.

**Lo que NO se puede comprobar desde este repositorio, y hay que decirlo:** el hash que corre hoy
en cada colegio **vive en el servidor**, no aquí. La comprobación de verdad es el bucle del
**Paso 2** —`git -C "$d" log -1 --format='%h'` colegio a colegio—, y se corre allí. Aquí no se
midió ningún servidor.

**Lo que sí se comprobó, el 4 sep 2026, y por qué basta para no re-litigarlo hoy:**

| | |
|---|---|
| el registro del despliegue anterior | la tanda del 25–30 ago se desplegó el **31 ago en `9474b50`**, y está más abajo en este mismo documento con lo que se midió aquel día |
| **ningún commit posterior registra un despliegue** | de los 232 del rango, **37** nombran el despliegue (`git log --oneline 9474b50..main --grep='despleg\|desplieg\|deploy' -i`) y se leyeron los 37: hablan de *escribirlo*, *medirlo* y *congelarlo*, **ninguno de haberlo hecho** —uno lo dice con todas las letras: *«la línea base de `horario_hoy` se movió sin que se desplegara nada»*—. **Los otros 195 no se leyeron uno a uno**, y ahí está el hueco de esta fila |
| el congelado sigue puesto | lo puso Joseth el 2 sep y **no lo levanta una sesión**; `ESTADO-ACTUAL.md` lo lleva como **«0 de 16»** |
| nada se ha ido por otro lado | `origin/main` y `main` están en el mismo commit, `8f59242` |

**Lo que eso deja:** la base es `9474b50` *mientras nadie haya desplegado a mano y sin apuntarlo*.
Es la afirmación más fuerte que se puede hacer desde aquí, y el bucle del Paso 2 la convierte en
un hecho en diez segundos el día que se descongele.

> **Y una aclaración de vocabulario que ya costó tres días de documento: aquí «los quince» pasó a
> ser «los dieciséis».** Son **dieciséis colegios más `demo`**, contados con el bucle el 2 sep 2026
> y ya corregidos en `CLAUDE.md`. Lo que **no** se ha tocado son las frases fechadas del registro de
> la tanda **ya desplegada** ni los avisos cerrados el 31 ago: aquéllas dicen quince porque se
> midieron sobre quince, y *lo que se actualiza es lo que sigue vivo*.

### Lo que es esta tanda, remedido entero el 4 sep 2026 sobre `9474b50..main`

**El extremo va escrito con su hash y no como `HEAD`**, y no es una manía: aquí se trabaja con un
árbol por sesión, así que `HEAD` es una cosa distinta en cada worktree y una tabla medida con
`HEAD` no la puede reproducir nadie. Hoy **`main` es `8f59242`**, y `origin/main` está en el
mismo commit.

| | | comprobado con |
|---|---|---|
| commits | **232** | `git rev-list --count 9474b50..main` |
| `app/` | **54** ficheros | `git diff --name-only 9474b50 main -- app/ \| wc -l` |
| `routes/` | **7** ficheros — **las rutas SÍ se movieron: 543 → 566** (24 nuevas, **1 retirada**) | `git diff --name-only 9474b50 main -- routes/` · `route:list --json` |
| `config/` · `composer.json`/`.lock` · `database/schema/` | **0** | `git diff --name-only 9474b50 main -- config/ composer.json composer.lock database/schema/` |
| **migraciones** | **SIETE**, y **cinco son bloqueantes** | `git diff --name-only 9474b50 main -- database/migrations/` |

**Las 24 y la retirada no se contaron a ojo**: salen de restar los dos
`tests/Contrato/Snapshots/rutas.json` —el de `9474b50` y el de `main`, 543 y 566 claves—, que es
el mismo dato que da `route:list --json` y además lo mueve un test. La retirada es
`POST tardanzas/login/traer-datos` y no hay ninguna otra.

> **La base es `9474b50` y no `eb95cbc`, y esto costó una medición entera.** El rango que había
> escrito arrancaba en `eb95cbc`, que es la tanda del **25 ago**; la del 25–30 ago
> (`eb95cbc..9474b50`, 44 commits) **se desplegó el 31** y está registrada más abajo en este mismo
> documento. Medir desde `eb95cbc` da 219 commits y **cuenta dos veces lo ya desplegado**: no es
> sólo ruido, es que la tabla de migraciones acababa listando `2026_08_30_200000_notas_finales_en_decimal`
> como pendiente cuando lleva dentro desde el 31. *Un rango sin desplegar se remide entero cada vez
> que se le toca; y lo primero que se remide es **desde dónde**.*

> **Y el 4 sep 2026 apareció la otra mitad de esa regla: el «desde dónde» estaba bien y el
> «HASTA dónde» estaba mal escrito.** Este encabezado decía `9474b50..347f137` y **ninguna de las
> cinco cifras de la tabla era de ese rango**: sobre `347f137` salen **175** commits, **52**
> ficheros de `app/`, **6** de `routes/` y **SEIS** migraciones. Los 191/54/7/SIETE que había aquí
> son exactamente `9474b50..aebf4ed` —la fusión del suelo del horario, dos horas más tarde el
> mismo día—, o sea que **las cifras se remidieron tras fusionar y la etiqueta del rango se quedó
> del sondeo anterior**. Comprobado commit a commit: `aebf4ed` es el **único** commit del rango en
> el que `git rev-list --count 9474b50..<x>` da 191.
>
> **Iba en la dirección que no se nota**: las cifras eran ciertas, el rótulo no, y un rótulo no lo
> pone rojo ningún test. Quien hubiera ido a `347f137` a comprobar la tabla habría encontrado seis
> migraciones donde dice siete y habría creído que la tabla envejeció, cuando lo que estaba mal era
> dónde miraba. *Un extremo de rango se copia del comando que produjo las cifras, no de la frase de
> antes.*

> ### Y esto es `main`. Lo que está EN VUELO y entra en la misma tanda
>
> Medido el 4 sep 2026 sobre `main..docs/horario-cuarta-ruta-y-despliegue` (**`bf83d3c`**), que es
> la rama de la cuarta ruta del horario y **todavía no está fusionada**. La rama creció dos commits
> mientras se escribía esto —de ahí que el hash vaya escrito y no el nombre—, y **las filas no se
> movieron**: los dos son correcciones, no rutas ni columnas:
>
> | | |
> |---|---|
> | **+1 ruta** | `GET horario/versiones/{id}/lecciones`, con `auth.personal`. El router pasa de **566** a **567** |
> | **+1 migración** | `2026_09_04_200000_tono_del_docente` — `profesores.tono`, `string(32)` nullable. La tanda pasa de **siete** a **OCHO** |
> | **+1 fichero de `app/`** | 54 → 55 |
> | commits | 232 → **236** |
>
> **Va escrito aparte y no sumado a la tabla de arriba a propósito.** La tabla de arriba se puede
> reproducir hoy con los comandos que lleva al lado; esto no —depende de una rama que puede crecer
> antes de entrar—. **El día que se fusione se remide el rango entero otra vez, no se suman estas
> filas a aquéllas**: es la misma regla que ya se escribió cuando entró el suelo del horario, y es
> la que acaba de cazar el rótulo de `347f137`.
>
> **Lo que sí hay que saber ya**, porque la comprobación de más abajo **ya pregunta por las ocho**:
> `profesores.tono` no es opcional para el módulo de horario. Ver la fila de la octava migración.

### Las siete de `main` y la octava en vuelo, ordenadas por lo que tumban — releídas el 4 sep 2026

| Migración | Qué rompe si falta | Radio |
|---|---|---|
| `2026_09_02_100000_nivelaciones_columnas` | **`years.regla_nivelacion`: el guard y los dos logins.** Y aparte, `notas.nota_original` y las tres de `notas_finales` las nombran la planilla (`NotasController:256` y `:306`), los boletines (`BoletinesController:298`, `Boletines2Controller:224`) y el boletín final (`BolfinalesController:529`) | **el colegio entero** |
| `2026_08_31_200000_puestos_con_bol_independiente` | `BoletinIndependiente::puestosCuentanIndependientes()` hace `SELECT puestos_con_bol_independiente FROM years WHERE id = ?` **sin condición y sin rescate** (`app/Services/BoletinIndependiente.php:394`), y todos los boletines pasan por ahí | los tres boletines, los certificados, preescolar, promovidos, `editnota` y los cuatro informes de puestos |
| `2026_09_02_200000_nivelacion_de_la_definitiva` | las dos columnas del acta de la definitiva, nombradas en `DefinitivasPeriodosController:524-526` y `:718-721` | `PUT definitivas_periodos/nivelar` |
| `2026_09_02_300000_acta_de_la_recuperacion_final` | **rompe una ruta que ya existe**, no sólo las nuevas: `putUpdateRecuperacion` nombra `nivelada_at, nivelada_por, observacion` en su `UPDATE`, su `INSERT` y su `SELECT` (`:818`, `:856`, `:884`) | `PUT definitivas_periodos/update-recuperacion` |
| `2026_09_03_100000_rubricas` | cinco tablas nuevas y `subunidades.rubrica_id`. **Nadie fuera de `RubricasController` las nombra** —comprobado uno a uno: la planilla, unidades, asignaturas y `ChangeAsked` pasaron a nombrar sus columnas justamente para que `rubrica_id` no se les colara— | las **10** rutas de `rubricas/` |
| `2026_09_04_100000_horario_versiones` | **Ya no es «una ruta detrás de un permiso»: se lleva por delante el panel de todo el mundo.** `years.horario_version_id` la lee `ChangeAskedController::horarioOficialDelAnio()` con un `SELECT horario_version_id FROM years WHERE id = ?` (`:1274`), y de ahí cuelga `getToMe` **en sus dos ramas** (`:140` y `:219`) — o sea `GET ChangesAsked/to-me`, que lleva **`auth.token` y nada más**: la pide un alumno, un acudiente y un docente, y es lo que la app llama al abrir (`MuroApi.traerMuro`). Y las tres tablas nuevas las nombran **los tres** métodos del horario, no uno: `postVersiones` con su `INSERT`, `getVersiones` con su `SELECT` y `putOficial` con el `UPDATE` de `years` más `horario_lecciones` | **4 rutas**: las 3 de `horario/` **y `GET ChangesAsked/to-me`** — que es el panel, no una pantalla de configuración |
| `2026_09_04_200000_tono_del_docente` · **EN VUELO** | `profesores.tono`, nullable y **vacía en los diecisiete** el día que salga. **No es inocua hacia delante, aunque lo parezca**: la cuarta ruta del horario —que viene en la misma rama— la nombra dos veces, en `docentesDeLaVersion()` (`SELECT … p.tono`) y en `estadoDelTono()` (`COUNT(… p.tono …)`), y las dos cuelgan de `getLecciones`. Sin la columna, esa ruta contesta **500**. Hacia atrás no rompe nada: en `main` **no la lee nadie** | **1 ruta**, la cuarta de `horario/` — que llega en la misma rama que la migración, así que **o entran las dos o no entra ninguna** |
| `2026_08_31_100000_retirar_boletin_independiente_de_matriculas` | nada del código nuevo: **retira** `matriculas.boletin_independiente`, que ya no lee nadie | ninguno hacia delante — **pero mira la fila de abajo** |

**Siete de las ocho son aditivas en `up()`** —`ADD COLUMN` y `CREATE TABLE`, sin un solo `UPDATE`
ni back-fill—, releídas una a una el 4 sep 2026 (seis de siete si se cuenta sólo `main`, que es la
misma frase de antes: la octava también es aditiva). **La que falta —`2026_08_31_100000`— no: hace
`dropColumn`**, y eso cambia dos reglas de este documento. (Va la última de esta tabla porque está
ordenada por lo que tumba, pero es la **primera** por orden de ejecución, y eso importa para el
rollback.)

> **Y «aditiva» no quiere decir «no tumba nada», que es la lectura que ya falló dos veces en esta
> misma tabla.** `profesores.tono` es un `ADD COLUMN` nullable, lo más inofensivo que hay, y aun
> así su ausencia manda la cuarta ruta del horario a un 500. Aditiva describe lo que le hace **al
> esquema**, no lo que le hace al código que la nombra.

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
> **Y le volvió a pasar a la MISMA fila, dos días después: van tres.** Lo que quedó escrito el 2 sep
> —«sólo `POST horario/versiones` pasa de 501 a 500; `getVersiones` y `putOficial` siguen a 501 y no
> las tocan, y `years.horario_version_id` sigue sin leerla nadie»— era cierto sobre `aebf4ed` y es
> **falso en `main` por partida triple**: los tres métodos están escritos y ninguno contesta ya 501,
> los tres nombran las tablas nuevas, y la columna la lee `getToMe`. Releída la fila entera el 4 sep
> 2026; la que más costaba ver era la tercera, porque **la ruta que se cayó no es del módulo de
> horario**. *Cuando una afirmación de esta tabla ha caducado dos veces, la tercera no se comprueba
> sólo en el fichero del módulo: se busca quién más nombra la columna, en todo `app/`.*
>
> **Aun así, todas entran en el mismo `migrate` y en la comprobación**: la pregunta que contesta esa
> comprobación es *«¿está la tanda entera dentro?»*, no *«¿qué se rompe?»*. Un colegio con siete de
> ocho es un colegio que nadie sabe en qué estado está.

### La que retira una columna, y las dos reglas que cambia

`2026_08_31_100000` hace `dropColumn('boletin_independiente')` sobre `matriculas`. El código que
está **hoy desplegado** en los dieciséis (`9474b50`) nombra esa columna en **cinco consultas vivas** de
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

### La comprobación de diez segundos, que ahora son ocho migraciones y no una

`php artisan migrate:status` **no basta**: dice que la migración corrió, no que la columna esté.
Esto pregunta por el esquema, que es lo que leen las consultas:

```bash
php artisan tinker --execute='$f=[]; foreach ([["years","regla_nivelacion"],["years","puestos_con_bol_independiente"],["years","horario_version_id"],["notas","nota_original"],["notas_finales","nota_nivelacion"],["recuperacion_final","nivelada_at"],["subunidades","rubrica_id"],["profesores","tono"]] as $c) { if (!Schema::hasColumn($c[0],$c[1])) $f[]=$c[0].".".$c[1]; } foreach (["rubricas","rubrica_criterios","rubrica_niveles","rubrica_descriptores","rubrica_valoraciones","horario_versiones","horario_lecciones","horario_pieza_docente"] as $t) { if (!Schema::hasTable($t)) $f[]="tabla ".$t; } if (Schema::hasColumn("matriculas","boletin_independiente")) $f[]="matriculas.boletin_independiente SIGUE AHI"; echo ($f ? "FALTA -> ".implode(" | ",$f) : "OK - las ocho dentro").PHP_EOL;'
```

> **`profesores.tono` se le añadió el 4 sep 2026, y la tanda pasó a OCHO migraciones.** Aquí se
> pregunta por **las ocho** aunque la columna venga de una rama sin fusionar, y es a propósito:
> **este fragmento es una comprobación que alguien va a ejecutar**, y una que no pregunta por la
> octava columna contesta que todo está bien en un colegio al que le falta una. La decisión de la
> columna es de Joseth (`23-horarios.md` §9.bis.3).
>
> **Y su mensaje de éxito dice «las ocho dentro», que es lo que mira.** Estuvo unas horas diciendo
> «las siete» sobre una lista de ocho —la columna entró en la lista y el texto no—, y lo corrigió
> la misma sesión que lo escribió el 4 sep 2026. Se anota porque **el rótulo de un `OK` es lo único
> que lee quien lo corre**: una lista completa con un rótulo viejo se archiva igual de rápido que
> una lista incompleta.

**Dice qué falta, no sólo que falta**, y **tiene control negativo**: probado el 2 sep 2026 contra la
base migrada (`OK - las ocho dentro`) **y** contra una sin migrar y con nombres inventados, donde
imprime la lista. Un `OK` que no sabe fallar es el que archiva el asunto.

**La otra acción del día, que no es una tabla:** correr `tools/independientes-sin-estructura.php`
**en los dieciséis**, uno por uno, después de migrar. Contesta la §9.1 —qué alumnos están marcados y
**no tienen ni una unidad propia**, cuya definitiva sale 0 sin que nadie reciba un error—, y **sin
la tabla contesta `exit=2 · NO CONCLUYENTE` a propósito**: un `0` limpio sería la respuesta que
archiva el asunto justo en el colegio donde no se ha mirado nada. Lo medido hasta hoy es **cero
marcados en desarrollo**, que **no** es «cero en los dieciséis»: eso sólo se sabe allí.

### Los avisos para el front que viajan en esta tanda

> **Repasados los cinco (K–O) el 4 sep 2026, y no se repasaron a ojo.** La pregunta no es «¿siguen
> siendo ciertos?» sino la de la regla de arriba —*una afirmación caduca cuando el código cambia
> aunque su número no se mueva*—, así que se midió **la población entera** en vez de releer las
> filas: se restaron las claves, una a una y hasta el fondo del árbol, de **los 125 snapshots de
> `tests/Contrato/Snapshots/` entre `9474b50` y `main`**. Cambian **35**; descontando los seis que
> no son respuestas (`rutas.json`, los dos de guards, `huecos-del-seed`, `escrituras-…` y
> `familias-…`) quedan **25 respuestas que ya existían y cambian de forma** —y ninguna cambia sin
> mover claves— más **4 snapshots nuevos**: tres son el mismo endpoint nuevo
> (`grupos/{grupo_id}/alumnos-de/{que}`, uno por valor de `{que}`) y el cuarto,
> `editnota-alum-asignatura`, es de **una ruta que ya existía** y a la que sólo ahora se le puso
> test.
>
> **Resultado: tres filas seguían bien, una se quedaba corta, una era falsa, y faltaban cuatro
> avisos enteros.** K, L y M ciertas al detalle; **N** enumera cuatro sitios y la nivelación toca
> más; **O** afirmaba algo del código que ya no es verdad. Los cuatro que faltaban —P, Q, R y S—
> no son un olvido de esta tabla: son **campos que se reparten solos**, por un `SELECT *`, por un
> modelo Eloquent devuelto entero o por una clave nueva de primer nivel. *Los avisos que faltan no
> los escribe quien escribió el campo, porque nadie escribió el campo.*
>
> **Lo único bueno de la lista:** en las 25 respuestas, **la única que PIERDE claves es
> `ChangesAsked/to-me`** (las ocho del aviso K). Las otras 24 sólo ganan.

| | aviso | estado |
|---|---|---|
| **K** | `GET ChangesAsked/to-me` deja de mandar **ocho** columnas de cada evento del calendario y conserva nueve. **Aquí decía «nueve» y era la cifra de las que se QUEDAN**, contada como si fueran las que se van; medido sobre el snapshot el 2 sep 2026, el evento pasa de 17 claves a 9. Las ocho que se van son `created_at`, `created_by`, `created_by_nombres`, `deleted_at`, `deleted_by`, `type`, `updated_at` y `updated_by`. Una de ellas, **`created_by_nombres`, la pinta la aplicación vieja** en el tooltip del evento (`AnunciosCtrl.ts:596`): al desplegar dirá **«Por: undefined»** hasta que se arregle allí, que es una línea | **POR AVISAR** — decidido a sabiendas el 2 sep 2026 |
| **L** | **`POST tardanzas/login/traer-datos` desaparece**: pasa a 404. Decisión de Joseth del 2 sep. El único llamante de toda la máquina es `tardanzasMyvc-old` (último commit feb 2020), y Joseth confirmó que ese repositorio está inactivo — el dato que lo cerró **no estaba en el repositorio** | **DECIDIDO** — es la única ruta que la tanda quita |
| **M** | **`regla_nivelacion` aparece en el bloque de la sesión**, en las cuatro ramas (alumno, acudiente, profesor y usuario). Es un campo **nuevo**, para previsualizar en el diálogo de nivelación qué nota va a quedar (22 §1.4 y §5.1) | **ADITIVO** — Flutter no se rompe: `ConfiguracionColegio.deLogin` lee campo a campo y no hay `json_serializable` ni `freezed`. Medido, no supuesto (22 §3.2bis) |
| **N** | **Campos nuevos de nivelación en respuestas que ya existían.** Aquí decía «la planilla (`PUT notas/detailed`), los boletines, el boletín final y `PUT editnota/alum-asignatura`»: **son cuatro nombres para DIEZ respuestas, y faltaban dos sitios** — `PUT notas-actuales-alumnos/{grupo_id}` y **`GET notas/alumno/…`, la que llama un alumno para ver sus propias notas** (gana `nota_original_asignatura` y `nivelada_at_asignatura`). Y «los boletines» son **`boletines` y `boletines2`, cuatro respuestas: `boletines3` no gana ni una clave**, que es justo lo que aquí no se veía. Qué respuesta abre las columnas **a propósito** y cuál las tiene **congeladas** está decidido sitio por sitio en la tabla de [22 §3.4](migracion/22-nivelaciones.md) y en el [27](migracion/27-nivelaciones-en-los-informes.md) | **ADITIVO** — ningún cliente pierde una clave. La enumeración, **corregida el 4 sep 2026**: 10 respuestas, no 4, y una de ellas es de un alumno sobre sí mismo |
| **O** | **24 rutas nuevas**: las 10 de `rubricas/`, las 4 de nivelar, las 5 de `boletin-independiente/`, las **3** de `horario/`, `GET grupos/{grupo_id}/alumnos-de/{que}` y `GET colegio/logo`. **Todas menos `colegio/logo` llevan `auth.personal`** —recontado ruta a ruta el 4 sep 2026 sobre `route:list --json`—: un alumno o un acudiente que las llame recibe **403**, y las de nivelar además exigen `periodos.profes_pueden_nivelar` al profesor (`User.php:425`) | **POR AVISAR** — es de las de «quién puede llamarla». **Aquí decía que las tres de `horario/` «hoy contestan 501» y es FALSO desde el 3 sep**: los tres métodos están escritos y contestan de verdad. Corregido el 4 sep 2026 |
| **P** | **`GET ChangesAsked/to-me` gana una clave de primer nivel: `horario_version_id`** (`null` mientras el año no tenga horario publicado, que es hoy en los diecisiete). Entró en `dff8361`. **Es la misma respuesta del aviso K y va aparte**: K cuenta lo que se va y esto es lo que llega, y la respuesta la piden **todos los roles** —lleva `auth.token` a secas—, incluida la app en `MuroApi.traerMuro` | **POR AVISAR** — campo nuevo. **Faltaba en esta tabla**: se detectó el 4 sep 2026 restando los snapshots, no leyendo el commit |
| **Q** | **Tres respuestas de años reparten las tres columnas nuevas de `years` sin que nadie lo pidiera**: `GET years`, `GET years/colegio` y `GET years/trashed` ganan `regla_nivelacion`, `puestos_con_bol_independiente` y `horario_version_id`. **No lo hace un cambio: lo hace el `SELECT y.*` de `YearsController`**, que estaba ahí desde antes. Las dos primeras llevan sólo `auth.token`, o sea que le llegan también a un alumno y a un acudiente | **POR AVISAR** — campo nuevo, y de los que **no aparecen en ningún diff de la respuesta**. **Faltaba en esta tabla** |
| **R** · **EN VUELO** | **`profesores.tono` se repartirá solo a SEIS respuestas vivas** que devuelven el modelo Eloquent entero: `POST profesores/store`, `PUT profesores/update/{id}`, `DELETE profesores/destroy/{id}`, `DELETE profesores/forcedelete/{id}`, `PUT profesores/restore/{id}` y **`GET grupos/show/{id}`**, que mete la ficha del titular dentro del grupo. Vale `null` en todos. **`GET profesores/show/{id}` NO está en la lista**: usa `Profesor::detallado()`, que nombra sus columnas. **Y el SQL crudo se miró aparte, porque Eloquent no es el único camino**: de las lecturas de fila entera sobre `profesores` en todo `app/`, la única real es el `SELECT p.*` de `DocentesExport`, y **ahí `tono` no llega a la hoja** —`listado-docentes.blade.php` pinta 17 columnas nombradas—; `getTodos` las nombra una a una. **Seis y no siete** | **POR AVISAR** con la rama de la cuarta ruta — campo nuevo. Contadas una a una el 4 sep 2026: **seis, no cinco**, y las dos de papelera son `deleteDestroy` y `deleteForcedelete` |
| **S** | **Los campos del boletín independiente también se reparten solos, y no tenían aviso**: `bol_independiente` y `bol_independiente_aparte_en` en los boletines y el acta de promoción, `bol_independiente_periodo` en los dos informes de notas perdidas y en `PUT puestos/detailed-notas-year` (que además gana `puestos_con_bol_independiente`), y `bol_independiente_datos` más `independientes` en la planilla. **ONCE respuestas** — contadas restando snapshots, sin contar las tres de `years`, que van en **Q** | **POR AVISAR, o cerrar con fecha si ya se avisó** — es posible que viajara en el documento del front (`DESPLIEGUE-NIVELACIONES-Y-RUBRICAS.md`), que **no se puede comprobar desde este repositorio**. Lo que sí se comprobó es que **en esta tabla no estaba** |

**Nivelar son rutas NUEVAS por diseño, y esto es lo que hay que decirle al front:** `notas/update` y
`notas/lote` **no pueden** aprender a nivelar. `myvc_flutter` es una sola app para los dieciséis y una
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

**El despliegue no ha terminado cuando los dieciséis tienen el hash.** Termina cuando el documento
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
> `matriculas.boletin_independiente`, y el código que hay hoy en los dieciséis (`9474b50`) la nombra
> en **cinco consultas vivas** de `app/Services/BoletinIndependiente.php` (73, 136, 202, 280, 297).
> Volver un colegio a `9474b50` **dejando la migración puesta le deja los boletines en 500**, que
> es justo lo que este paso existe para no hacer.
>
> Para volver atrás de esa tanda hay que **volver también el esquema**, y **no se puede volver sólo
> esa**: `2026_08_31_100000` es la **primera de la tanda por orden de ejecución**, así que llegar a
> ella es deshacerlas todas. Como corrieron en el mismo `migrate`, son un solo lote:
>
> ```bash
> php artisan migrate:status | grep -c Pending   # CUÉNTALAS, no las supongas: es el <n> de abajo
> php artisan migrate:rollback --step=<n>        # todas las de la tanda, en orden inverso
> ```
>
> **El número va contado y no escrito aquí, y esto cambió el 4 sep 2026.** Antes ponía `--step=7`
> con un `tail -9` al lado; la tanda pasó a **ocho** en cuanto entró `profesores.tono`, y un `7`
> sobre ocho migraciones deja dentro justo la primera del rollback —o sea **no llega** a la del
> `dropColumn`, que es la única razón por la que se está haciendo esto—. Un número escrito a mano
> en un comando de vuelta atrás sólo es correcto hasta la siguiente migración.
>
> **`--step=1` NO sirve aquí** en ningún caso: revierte la última y deja la columna retirada
> exactamente igual.
>
> **Y esto sí pierde datos, al revés que en las tandas anteriores.** El `down()` de la del
> `dropColumn` es exacto —devuelve la columna **a 0 en todas las filas, que es lo que había**: nunca
> llegó a tener un 1 en ninguna base—, pero los `down()` de **todas las demás** se llevan **lo que
> se haya registrado desde el despliegue**: las nivelaciones (`notas.nota_original` y compañía), las
> actas de la definitiva y de la recuperación, las rúbricas enteras con sus valoraciones, las
> versiones de horario subidas —que hoy son cero, porque el lote B no existe— y, si ya ha entrado
> la octava, **los colores de los docentes** (`profesores.tono`). Las
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
