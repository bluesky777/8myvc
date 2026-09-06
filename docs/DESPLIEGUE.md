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
tardaría más de una semana en llegar a la tienda** y los dieciséis colegios lo comerían entero.
Por eso esto va **antes** del bloque de la tanda y no dentro: la tanda está lista, y aun así
**no se despliega**.

**Qué se midió antes de escribir esto, para que la espera sea una decisión y no un miedo.** De
los **321** commits (remedidos el 5 sep 2026 por la noche; eran 191 cuando se escribió esto), lo único que un
cliente puede **perder** siguen siendo dos cosas — todo lo demás es aditivo, comprobado sobre los
snapshots del rango, no sobre los mensajes de los commits:

| Lo que desaparece | Dónde |
|---|---|
| `POST tardanzas/login/traer-datos` → **404** | la única ruta retirada de las **35** que se mueven |
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

**Pero esto NO lo trae la tanda, y por eso esperar no protege de ello.** Remedido el 5 sep 2026
sobre `9474b50..main`: el rango **no toca** `VersionMinimaDeLaApp.php` ni
`config/aplicacion-movil.php`. El mecanismo se escribió el 24 ago (`1e98e28`) y **está desplegado
en los dieciséis desde el 31 ago**. O sea que **el riesgo es idéntico se despliegue o no**, y si
algún colegio tuviera un número ≥ 4 la app **ya estaría bloqueada allí ahora mismo**.

> **Aquí decía además «ni `.env.example`», y eso dejó de ser cierto: el rango SÍ lo toca**
> (`cf06f72` y `41d2a22`). Lo que cambia es `MAIL_FROM_ADDRESS` —de `josethmaster@lalvirtual.com`
> a `admin@micolevirtual.com`—, **no** `APP_MOVIL_VERSION_MINIMA`, así que la conclusión de arriba
> aguanta entera. Pero **la frase que la sostenía era falsa, y una conclusión correcta apoyada en
> una premisa falsa es la que nadie vuelve a comprobar.** Remedido fichero a fichero, no de
> memoria.

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

## ⛔ TANDA PENDIENTE — 321 commits desde `9474b50`. SIN LAS MIGRACIONES NO SE PUEDE NI ENTRAR

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
| **558** de las 576 rutas de `api/` llevan `auth.token` — recontado el 5 sep 2026 con `route:list --json`; las 18 que no lo llevan **no se movieron** | **500 en el guard**, antes del controlador |
| `POST login` (`LoginController:51`) y `POST auth/login` (`Auth\SesionController:63`) | **500** — montan el contexto ellos mismos: **no se puede iniciar sesión** |
| Lo que queda en pie | `login/logout`, `login/recuperar-clave`, `login/reset-password`, `login/ver-pass`, `login/crear-prematricula`, `publicaciones/ultimas`, `colegio/logo` y el quiosco de `tardanzas/*` — que no pasan por el contexto |

**No es teoría.** Le pasó al docker la madrugada del 2 sep 2026 al fusionar, y **lo detectó la
sesión del front, no nosotros**. Reproducido aquí contra una base sin migrar: la consulta del
contexto contesta `SQLSTATE[42S22] Unknown column 'y.regla_nivelacion' in 'field list'`.

### Lo que es esta tanda, remedido entero el 5 sep 2026 a las 22:1x sobre `9474b50..24967af`

**El extremo va escrito con su hash y no como `HEAD`**, y no es una manía: aquí se trabaja con un
árbol por sesión, así que `HEAD` es una cosa distinta en cada worktree y una tabla medida con
`HEAD` no la puede reproducir nadie. **`24967af` es la punta de `main`**, y **`origin/main` es el mismo commit**: cero de divergencia,
comprobado el 5 sep 2026 tras empujar. O sea que **esta tabla sí describe lo que trae un `git pull`**.

> **Y esta tabla se volvió a medir ENTERA en vez de corregirle la fila que cambió, porque durante
> una hora estuvo mezclando dos hashes.** A las 16:57 se cerró sobre `3970cea` con **307** commits
> y **577** rutas; a las 18:0x entró la sexta de `horario/` y se le actualizó la fila de rutas a
> **578** dejando el resto —y el rótulo— de `3970cea`. **Las dos cifras eran ciertas y ninguna era
> del mismo commit que la otra**, bajo un encabezado que anunciaba un solo rango. *Una tabla con
> dos extremos es peor que una tabla vieja: la vieja se remide, la mezclada se cree.*

| | | comprobado con |
|---|---|---|
| commits | **321** | `git rev-list --count 9474b50..origin/main` |
| `app/` | **57** ficheros | `git diff --name-only 9474b50 main -- app/ \| wc -l` |
| `routes/` | **8** ficheros — **las rutas SÍ se movieron: 543 → 578** (36 nuevas, **1 retirada**) | `git diff --name-only 9474b50 main -- routes/` · `route:list --json` |
| `config/` · `composer.json`/`.lock` · `database/schema/` | **0** | `git diff --name-only 9474b50 main -- config/ composer.json composer.lock database/schema/` |
| **migraciones** | **SIETE ficheros**, y **cuatro son bloqueantes** | `git diff --name-only 9474b50 main -- database/migrations/` |

> **El commit que escribe esta tabla queda, por construcción, por encima de ella.** `321` se contó
> contra `24967af`, que era la punta al medir; el commit que registra el recuento hace **322**, y el
> siguiente, **323**. Eso no es un error que corregir: es la razón de que el extremo se escriba
> **con su hash y nunca como `HEAD`**. Quien quiera comprobar esta fila corre
> `git rev-list --count 9474b50..24967af` y le da **321** hoy y dentro de un mes; quien corra
> `..origin/main` está midiendo **otro rango**, el suyo, que es el que hay que remedir el día que
> se despliegue.

**Las 36 y la retirada no se contaron a ojo**: salen de restar los dos
`tests/Contrato/Snapshots/rutas.json` —el de `9474b50` y el de `main`, **543** y **578** claves—,
que es el mismo dato que da `route:list --json` y además lo mueve un test. **543 + 36 − 1 = 578**,
y la retirada es `POST tardanzas/login/traer-datos`, que no tiene ninguna otra.

> **Y en el remedido de las 22:1x eso dejó de ser una comodidad y fue la única salida: Docker
> estaba caído** (se lo llevó el reinicio de la máquina), así que **`route:list --json` no se podía
> correr**. Restar los dos snapshots dio **543 → 578, 36 nuevas y una retirada**, idéntico a lo que
> ya decía esta tabla y **reproducible sin levantar nada**. La otra mitad, la que impide heredar el
> número: `git diff --name-only d606839 24967af` da **cuatro ficheros y los cuatro son documentos**
> —cero de `routes/` y cero de `app/`—, o sea que **el router no ha podido moverse** desde que se
> contó. *Un número no se hereda porque siga escrito; se hereda cuando se demuestra que su entrada
> no ha cambiado.*

> ### Antes de desplegar: que `origin/main` sea lo que mediste
>
> ```bash
> git rev-parse --short origin/main main    # los dos hashes, y tienen que ser el MISMO
> ```
>
> **El Paso 1 despliega con `git pull`, así que lo que se despliega es `origin`, no tu `main`.**
> El 5 sep 2026 a las 18:0x coinciden los dos en `d606839` y esta tabla describe exactamente lo
> que trae un `git pull`.
>
> **Se deja la orden y no el resultado, y esto costó una caja entera.** Durante unas horas de ese
> día `main` local iba **14 commits por delante** y aquí hubo un aviso que lo decía: contra
> `origin` la tanda era de **CINCO** migraciones y no de siete —las dos de la plantilla estaban
> sólo en local—, así que la comprobación de diez segundos habría contestado `FALTA` **con razón**.
> En cuanto se empujó, **ese aviso pasó a decir lo contrario de lo que había**, y un aviso sobre un
> estado *envejece hacia el peligro*: de proteger a engañar, sin que nada se ponga rojo. Lo que no
> caduca es **la comprobación**, así que es lo único que se queda.

> ### Por qué esta sección se remide entera y no se le suma
>
> **En veinticuatro horas este rango tuvo NUEVE cifras, y las nueve fueron ciertas el minuto en que
> se midieron:** el documento decía **191**; la rama `docs/despliegue-remedido` midió **232** el 4
> sep; `8myvc-ae` contó **274** la tarde del 5 y al ir a escribirlo eran **279**; al cerrar la
> plantilla **285**; al rescatar el `CLAUDE.md` huérfano **286**; y al cerrar con el Lote G
> **307**; **315** al entrar la sexta ruta del horario; y **321** al fundir
> `docs/despliegue-remedido-2` y limpiar el espacio de trabajo. **Nueve cifras.** Nadie se
> equivocó en ninguna.
>
> *Y este párrafo se equivocó en la suya: decía «cuatro cifras» y llevaba siete en la lista, porque
> cada remedido añadía una y nadie tocaba el recuento de arriba. **El párrafo que explica que los
> números envejecen envejeció por dentro**, que es la demostración más barata de que la regla no es
> manía.*
>
> **Lo que falla no es medir, es el hueco entre medir y escribir** — `main` se mueve en medio,
> porque aquí trabajan varias sesiones a la vez. De ahí las dos reglas de esta sección, que
> parecen manía y no lo son: **el extremo del rango se escribe con su hash**, y **la cifra se
> vuelve a contar el día que se toca el documento**, nunca se hereda de la frase de antes.

> **Y el 4 sep 2026 apareció la otra mitad de la regla de abajo: el «desde dónde» estaba bien y el
> «HASTA dónde» estaba mal escrito.** Este encabezado decía `9474b50..347f137` y **ninguna de las
> cinco cifras de la tabla era de ese rango**: sobre `347f137` salen **175** commits, **52**
> ficheros de `app/`, **6** de `routes/` y **SEIS** migraciones. Los 191/54/7/SIETE que había aquí
> son exactamente `9474b50..aebf4ed` —la fusión del suelo del horario, dos horas más tarde el mismo
> día—, o sea que **las cifras se remidieron tras fusionar y la etiqueta del rango se quedó del
> sondeo anterior**. Comprobado commit a commit: `aebf4ed` es el **único** commit del rango en el
> que `git rev-list --count 9474b50..<x>` da 191.
>
> **Iba en la dirección que no se nota**: las cifras eran ciertas, el rótulo no, y un rótulo no lo
> pone rojo ningún test. Quien hubiera ido a `347f137` a comprobar la tabla habría encontrado seis
> migraciones donde dice siete y habría creído que la tabla envejeció, cuando lo que estaba mal era
> dónde miraba. *Un extremo de rango se copia del comando que produjo las cifras, no de la frase de
> antes.*

> **La base es `9474b50` y no `eb95cbc`, y esto costó una medición entera.** El rango que había
> escrito arrancaba en `eb95cbc`, que es la tanda del **25 ago**; la del 25–30 ago
> (`eb95cbc..9474b50`, 44 commits) **se desplegó el 31** y está registrada más abajo en este mismo
> documento. Medir desde `eb95cbc` da 219 commits y **cuenta dos veces lo ya desplegado**: no es
> sólo ruido, es que la tabla de migraciones acababa listando `2026_08_30_200000_notas_finales_en_decimal`
> como pendiente cuando lleva dentro desde el 31. *Un rango sin desplegar se remide entero cada vez
> que se le toca; y lo primero que se remide es **desde dónde**.*

### Las siete, ordenadas por lo que tumban

> **Eran OCHO ficheros hasta el 4 sep 2026 y son CINCO, sin que cambie una sola columna.**
> Se fusionaron `2026_09_02_200000_nivelacion_de_la_definitiva` y
> `2026_09_02_300000_acta_de_la_recuperacion_final` dentro de
> `2026_09_02_100000_nivelaciones_columnas`, y `2026_09_04_200000_tono_del_docente` dentro de
> `2026_09_04_100000_horario_versiones`. **Ninguna se había desplegado nunca** —el despliegue
> está congelado— y el día que salgan esto ya no se puede hacer.
>
> Comprobado construyendo las dos bases desde cero y comparando `information_schema`:
> **1.526 columnas, mismas posiciones físicas, mismos índices y mismas foráneas**, 102 tablas
> en las dos. La fusión de nivelaciones además **quita una reconstrucción de `notas_finales`**
> en un colegio que corra MySQL 5.7 (dos `ALTER` sobre esa tabla pasan a uno), que ~~es la única
> incógnita abierta de este despliegue: no sabemos qué MySQL corren los diecisiete~~ **era la última
> incógnita abierta, y la cerró Joseth el 5 sep 2026** — el bloque siguiente.

> ### PRODUCCIÓN CORRE MARIADB 10.5.25, NO MYSQL 8 — contestado por Joseth el 5 sep 2026
>
> `SELECT VERSION();` desde phpMyAdmin contesta **`10.5.25-MariaDB-cll-lve`**, y **lo mismo en los
> dos shared hostings** —el bucle de `micolev1` y la cuenta de `lalvirtual.edu.co`—; el sufijo es
> CloudLinux con límites LVE por cuenta. **El docker corre MySQL 8.0.42**, así que el ensayo de la
> tanda se hizo sobre OTRO motor. Lo que eso cambia y lo que no, medido sobre `d606839`:
>
> - **La reconstrucción de `notas` que temía el bloque de arriba no ocurre.** MariaDB añade y quita
>   columnas al instante desde la 10.4, también con `AFTER`, siempre que la tabla sea InnoDB sin
>   `ROW_FORMAT=COMPRESSED`: **las 90 del volcado lo son**. Las cinco columnas de `notas` deberían
>   costar lo del docker (11,8 ms), no los 4.870 ms del `ALGORITHM=COPY`. *«Deberían»: se midió en
>   MySQL 8 y se razona para MariaDB; el número de MariaDB no existe todavía.*
> - **El código nuevo no usa nada que MariaDB 10.5 no tenga.** `grep` sobre las **14.264 líneas
>   añadidas** entre `9474b50` y `d606839` en `app/`, `database/` y `routes/` buscando `JSON_TABLE`,
>   `LATERAL`, `->>`, `->'`, `MEMBER OF`, `REGEXP_LIKE`, `JSON_ARRAYAGG`, `JSON_OVERLAPS` y
>   `utf8mb4_0900`: **cero fuera de comentarios** (los cuatro que salen son docblocks que hablan de
>   `ALGORITHM=INSTANT`). La población es sólo lo nuevo a propósito: lo desplegado corre allí desde
>   el 31 ago y ya contestó por sí mismo.
> - **Lo que sigue sin medir: la tanda sobre MariaDB.** `tools/ensayo-de-la-tanda.sh` la corrió
>   sobre MySQL 8. Convertir «deberían» en un número es un contenedor `mariadb:10.5` con la copia de
>   `simonbolivar` y las siete encima. No cambia el plan del día 10; es lo único que queda entre la
>   tanda y un ensayo sobre el motor de verdad.
>
> ### Y una pregunta que la versión NO contesta: el motor de cada tabla, colegio por colegio
>
> La preguntó Joseth el 5 sep 2026 —*«¿es posible que algunas de mis bases sean muy viejas y tengan
> tablas no InnoDB?»*— y **desde este repositorio no se puede contestar**: el volcado y la copia del
> docker son **un** colegio (90 tablas en el volcado, 102 vivas, todas InnoDB), y un colegio nuevo se
> crea copiando la base de otro, con sus motores dentro. Nadie ha censado los otros.
>
> **Y no es una pregunta de tiempo, es de si la tanda termina.** `rubricas` crea
> `rubrica_valoraciones` con una clave ajena hacia **`notas`**, y `horario_versiones` y `rubricas`
> apuntan a `years`, `asignaturas`, `profesores` y `subunidades`. InnoDB **no puede apuntar a una
> tabla MyISAM**: el `CREATE TABLE` falla con el errno 150 y la tanda se queda **a medias**, que es el
> estado que este documento llama el peor. En el colegio del volcado esas cuatro ya reciben claves
> ajenas (13 hacia `years`, 10 hacia `asignaturas`, 6 hacia `profesores`, 1 hacia `subunidades`),
> así que allí son InnoDB; **`notas` no recibe ninguna hoy y es la que la tanda estrena.** Las
> migraciones ya desplegadas el 31 ago sólo probaron `alumnos` y `periodos`.
>
> **Se contesta con dos consultas en el phpMyAdmin de cada hosting**, sin elegir base —van contra
> `information_schema` y ven todas las de la cuenta—. La primera es la población; sin ella un
> resultado vacío de la segunda no distingue *«revisé diecisiete bases»* de *«no vi ninguna»*:
>
> ```sql
> -- 1. población: cuántas tablas y de qué motor tiene cada base de la cuenta
> SELECT table_schema, engine, COUNT(*) AS tablas
> FROM information_schema.tables
> WHERE table_type = 'BASE TABLE'
>   AND table_schema NOT IN ('information_schema', 'mysql', 'performance_schema')
> GROUP BY table_schema, engine ORDER BY table_schema, engine;
>
> -- 2. las que rompen la tanda: cualquier fila aquí es un colegio donde NO se migra hasta convertirla
> SELECT table_schema, table_name, engine, row_format
> FROM information_schema.tables
> WHERE table_name IN ('notas', 'notas_finales', 'recuperacion_final', 'matriculas', 'years',
>                      'asignaturas', 'profesores', 'subunidades', 'unidades_por_defecto')
>   AND (engine <> 'InnoDB' OR row_format = 'Compressed');
> ```
>
> Si la segunda devuelve filas, la salida es `ALTER TABLE x ENGINE=InnoDB` **antes** de la tanda y en
> ese colegio sólo, y es una reconstrucción entera de esa tabla: se mide allí, no aquí. Se corre en
> **los dos hostings**, porque la versión salió igual en los dos y eso no dice nada del motor.
>
> #### CONTESTADA para `micolev1` el 5 sep 2026: 18 bases, todas InnoDB, y la segunda consulta vacía
>
> Joseth las corrió esa tarde en el phpMyAdmin de `micolev1`. **Las 18 bases de la cuenta salen
> InnoDB en todas sus tablas** —los dieciséis colegios, `demo` y `edilson_feryz`, que no es un
> colegio— y la segunda consulta devolvió **cero filas con esa población delante**. El otro hosting es
> el servidor viejo de `lal` (`micolevi`, [TRASLADO-LAL.md](TRASLADO-LAL.md)), y la base de `lal` ya
> vive aquí como `micolev1_lal_db`: **las diecisiete bases que recorre el bucle del día 10 están en
> esta lista**, así que la pregunta del motor queda cerrada para lo que se despliega.
>
> **Y la misma consulta trajo un número que nadie tenía: las bases NO tienen las mismas tablas.**
>
> ```
> demo, simonbolivar_medellin           94   <- lo que debe tener un colegio en `9474b50`
> coab_saravena, lal_db                 93
> arauca_maranatha                      92
> cads_itagui, coal_bucara, colbosque_tame, coljordan, semillitas   89
> bethel_arauquita, caz_zaragoza, comad_san_andres, fortul_adventista,
> la_hermosa, quibdo_db                 88
> amiguitosdejesus                      87
> ```
>
> El 94 no es una suposición: el docker con las siete migraciones pendientes tiene **102**, y la
> tanda crea **8** tablas (cinco de `rubricas`, tres de `horario`); 102 − 8 = 94, contado sobre
> `information_schema`. O sea que **quince colegios tienen entre una y siete tablas menos que el
> esquema del repositorio**, y desde aquí no se sabe cuáles. Con el código desplegado funcionando en
> los quince, lo que falta son tablas viejas que esas bases nunca tuvieron, no las cuatro que creó
> la tanda del 31 ago; pero eso es una inferencia, no una medición.
>
> **Lo que le importa a la tanda es una sola pregunta: si las once tablas que ALTERA existen en las
> diecisiete.** Un `Schema::table()` sobre una tabla que no está es el mismo errno que una MyISAM:
> la tanda a medias. Y de las once, `recuperacion_final` y `unidades_por_defecto` son tablas de
> función, no de núcleo, o sea las que una base vieja podría no tener:
>
> ```sql
> -- 3. las once que altera la tanda: tiene que salir 11 en las diecisiete bases
> SELECT table_schema, COUNT(*) AS de_once
> FROM information_schema.tables
> WHERE table_schema LIKE 'micolev1\_%'
>   AND table_name IN ('notas', 'notas_finales', 'recuperacion_final', 'matriculas', 'years',
>                      'asignaturas', 'profesores', 'subunidades', 'unidades_por_defecto',
>                      'permissions', 'permission_role')
> GROUP BY table_schema ORDER BY de_once, table_schema;
>
> -- 4. el censo de la deriva: qué tablas NO están en las diecisiete, y en cuántas sí
> SELECT table_name, COUNT(*) AS en_bases
> FROM information_schema.tables
> WHERE table_schema LIKE 'micolev1\_%' AND table_schema <> 'micolev1_edilson_feryz'
>   AND table_type = 'BASE TABLE'
> GROUP BY table_name HAVING COUNT(*) < 17 ORDER BY en_bases, table_name;
> ```
>
> La 3 es la que decide; la 4 es la que explica el 87 y evita que el día 10 alguien lo descubra con
> un `SELECT *`. ~~Ninguna de las dos se ha corrido todavía.~~ **Las dos las corrió Joseth esa misma
> tarde:**
>
> - **La 3 da `11` en las diecisiete.** La tanda no se va a encontrar ninguna tabla que falte:
>   **cerrado.**
> - **La 4 nombra las siete** y ninguna la toca la tanda: `df_notas_finales` (en 4 bases, muerta),
>   las cinco `piars_*` (en 5, y `piars_actas_acuerdo` en 7: el módulo PIAR de `myvc_front_2`) y
>   `uniformes` (en 16 **el 5 sep por la tarde; esa misma noche pasó a los diecisiete, ver abajo**:
>   la que no la tenía era `amiguitosdejesus`, y su panel de inicio la
>   consulta sin condición — contesta 500 allí hoy, para alumnos y profesores con grupo). El censo
>   entero, con las catorce tablas que no lee nadie, está en
>   [05 §246](migracion/05-codigo-muerto-y-roto.md). **Nada de esto cambia el día 10.**

> #### ⚠️ `PUT calendario/mes` NO EXISTE EN LA API, y `app2` come de ahí — 6 sep 2026
>
> **Salió de contestarle una pregunta al front, y no lo buscaba nadie.** `myvc-front-05` y
> `myvc-front-21` preguntaron, por separado, si `calendario/mes` pierde `created_by_nombres` como
> `ChangesAsked/to-me`. La respuesta a eso es **no** —el recorte vive sólo en
> `ChangeAskedController:102`, `putThisYear` hace `SELECT *`, el controlador no cambió entre
> `9474b50` y hoy y su snapshot tampoco—, pero al mirarlo salió lo otro:
>
> ```
> routes/api/perfiles.php tiene HOY:  crear-evento · eliminar-evento · guardar-evento
>                                     sincronizar-cumples · this-year
> calendario/mes y calendario/proximos:  SÓLO en la rama `feat/calendario`
> ```
>
> Esa rama tiene **2 commits propios y 230 de retraso, y trae una migración**, que es por lo que
> Joseth la dejó fuera de la tanda el 5 sep: entrar cuesta reensayar la tanda entera. O sea que
> **esa ruta no existe hoy y tampoco existirá el día 10**, y `app2` tiene un fijo de contrato
> (`paginas/calendario/contrato-mes.spec.ts`) con un volcado real de una respuesta que en
> producción es un **404**.
>
> **Lo que esto NO es**: un problema que arregle fundir la rama. Fundirla mete la octava migración
> en una tanda ensayada sobre siete.
>
> ##### CONTESTADO por `myvc-front-05` el 6 sep: la pantalla está PUBLICADA
>
> ```
> ruta      /calendario, en app.routes.ts:1608
> permiso   `conSesion` — CUALQUIERA con sesión: docentes, alumnos y acudientes
> menú      entrada «Calendario» visible con el mismo criterio
> entró     en su `main` el 23 ago 2026
> llama     `PUT calendario/mes` al abrir, y `calendario/proximos`
> ```
>
> O sea que **las dos rutas que sólo existen en la rama congelada son justo las dos que usa**, y la
> entrada del menú la ve todo el colegio.
>
> **Y la mitad que ya estaba escrita, para no atribuirse el hallazgo entero:** el acoplamiento lleva
> documentado **desde el 2 sep 2026** en `myvc_front@DESPLIEGUE-UP2.md`, primera fila de «lo que hay
> que mirar ANTES de desplegar», y con una distinción que aquí no teníamos: **falta la ruta → 404 y
> «No se pudo cargar el calendario»; `pull` hecho y `migrate` sin correr → 500**, que es peor. O sea
> que **`up2/` no se despliega a ciegas**: esto no es un riesgo desconocido.
>
> **Lo que aporta este bloque es la otra mitad, y es la que cambia el plan:** *por qué* falta la
> ruta. `feat/calendario` está fuera de la tanda del día 10 **a propósito**, así que **esperar a que
> llegue no es esperar unos días**. Juntas, las dos mitades dicen: *si el día 10 se sube `up2/`, el
> calendario se rompe en los dieciséis y la entrada la ve cualquiera con sesión.* **Ruidoso y no
> peligroso** —404 y un mensaje, no un dato mal guardado— pero visible para todos.
>
> ##### Y NO HAY ATAJO: las rutas no se pueden llevar sin la migración
>
> Comprobado aquí antes de ofrecerlo como salida, porque era la tentación evidente —*«funde sólo
> las dos rutas y deja la migración fuera»*—:
>
> ```
> eventosManualesDelRango()  SELECT c.descripcion, c.recordatorio_minutos   <- columnas NUEVAS
> filtroDeDestinatarios()    NOT EXISTS (SELECT 1 FROM calendario_destinatarios d …)  <- tabla NUEVA
> ```
>
> Las dos las usa `respuestaDelRango()`, que es de donde comen `putMes` y `putProximos`. **Sin la
> migración, esas dos rutas contestan 500 en vez de 404**, que es peor. La rama entra entera o no
> entra.
>
> ##### LAS TRES SALIDAS, y ninguna la decide una sesión
>
> | | qué cuesta |
> |---|---|
> | **(a)** meter `feat/calendario` en la tanda | reensayar la tanda entera con la octava migración, sobre una copia con datos, antes de tocar dieciséis colegios |
> | **(b)** subir `up2/` con el calendario tapado | esconder la entrada del menú y guardar la ruta hasta que exista su backend. **Es trabajo del front y es poco** |
> | **(c)** no subir `up2/` el día 10 | **la más barata, y puede que se le haya pasado a todo el mundo**: lo que el día 10 necesita es la API y `up/` —el arreglo del tooltip viaja por ahí—. `up2/` no hace falta para nada de ese día |
>
> ##### Lo que NADIE sabe todavía: si ya está roto HOY
>
> El front no puede verlo —no tiene copia local de `myvc_dist2`, así que no sabe cuándo se
> construyó `up2/` por última vez—, y desde aquí tampoco. **Lo sabe Joseth, que es quien despliega.**
> Lo único medido es que `myvc_dist`, el de la aplicación vieja, se construyó por última vez el
> **21 ago 2026**, o sea antes de que esa pantalla existiera. *«No medido» no es «bien».*
>
> *Y el fijo del front no se pondría rojo: es un volcado literal, no una llamada viva. Una spec de
> contrato que describe una ruta inexistente **envejece en silencio**, que es justo lo que esas
> specs existen para evitar.*

> #### Y `uniformes` ya está creada — 5 sep 2026, 22:4x, en producción
>
> **La creó Joseth desde phpMyAdmin** con [`tools/crear-uniformes-donde-falta.sql`](../tools/crear-uniformes-donde-falta.sql),
> y las comprobaciones del script contestaron sobre la base de verdad
> (`micolev1_amiguitosdejesus`, motor `10.5.25-MariaDB-cll-lve`): antes, la tabla no estaba y los
> tres destinos eran InnoDB con su `id` en `INT UNSIGNED`; después, **22 columnas, 3 claves ajenas,
> 0 filas, InnoDB**. Ese colegio pasa de 87 a 88 tablas y **`uniformes` está en los diecisiete**.
>
> *De paso, el paso 1 convirtió en medición lo que hasta entonces era aritmética: que el colegio de
> 87 fuera el que no tenía `uniformes` se había deducido de 94 − 7 = 87, y ahora lo dijo la base.*
>
> **Esto no toca la tanda del día 10 y no cambia una línea de código**: quita 500 que ya estaban
> ocurriendo. **Lo que queda debiendo es la migración con `Schema::hasTable('uniformes')`**, que va
> después del día 10 y que en ese colegio será un no-op.

> **Y desde el 5 sep 2026 son SIETE, no cinco: entraron las dos de la plantilla de notas.**
> `2026_09_05_200000_alcance_de_la_plantilla` y
> `2026_09_05_300000_create_permiso_can_edit_plantilla_notas`, con la Entrega 1 del
> [28](migracion/28-competencias-e-indicadores.md). **Contadas con
> `git diff --name-only 9474b50 HEAD -- database/migrations/`, no sumadas** — 5 + 2 habría dado
> el número correcto describiendo mal la tanda, que es justo lo que avisa el bloque de rollback:
> desde la fusión **una migración son varias columnas de varias tablas**.
>
> Las dos son **aditivas y no tumban ninguna ruta viva** —ver sus filas—, que es lo que las
> distingue de las cinco de arriba y por lo que **no cambian el veredicto de esta tanda**.

> #### Y la tanda de SIETE está ensayada, no supuesta — 5 sep 2026
>
> `tools/ensayo-de-la-tanda.sh` sobre una copia de `simonbolivar` (210 MB, 102 tablas,
> **1.166.139 filas en `notas`**), con las dos ramas dentro:
>
> | | |
> |---|---|
> | migraciones que entran | **7**, preguntadas a git en el rango `9474b50..HEAD` |
> | corren | **7 de 7**, en **1.659 ms** de migraciones · **4.569 ms** el comando entero |
> | delta real | **8 tablas nuevas**, **20 columnas nuevas** en 7 tablas, **1 columna retirada** |
> | la copia migrada contra `simonbolivar` | idénticas: **1.528 columnas, mismo tipo** |
> | pendientes al terminar | **0** |
> | el módulo de horario en la copia | `200` con `total: 0` y el `403` donde toca — **LLEGÓ** |
>
> **Los dos controles saltan**, que es lo que hace que ese «OK» valga: contra la copia **sin
> migrar** la comprobación enumera las veinte cosas que faltan —las dos columnas de
> `unidades_por_defecto` y el permiso incluidos— y después de migrar dice `OK - la tanda entera
> dentro`. Y el ensayo verifica además la **cobertura**: *«de cada tabla que cambia pregunta al
> menos una cosa»*, que es la regla que se escribió el día que a la comprobación se le quedó
> fuera `profesores.tono`.
>
> > ⚠️ **Y una trampa del propio ensayo, pagada aquí para que no la pague el siguiente:
> > `PHP_EXEC` no lleva `-w` por defecto**, así que `artisan` corre en el árbol **principal**.
> > Desde un worktree, las migraciones que sólo existen en tu rama salen **`Migration not
> > found`** en el rebobinado y el ensayo aborta con `NO MEDIDO` — que se lee como «la tanda
> > está mal» y es «estás midiendo otro árbol». Es la misma trampa que
> > `tools/construir-bd-test.sh` ya documenta y **detecta**, y que ésta todavía no detecta. Se
> > corre así:
> >
> > ```bash
> > DB_ENSAYO=simonbolivar_ensayo_x \
> >   PHP_EXEC="docker exec -i -w /app/.worktrees/x 8myvc-app-1" \
> >   tools/ensayo-de-la-tanda.sh --limpiar
> > ```

| Migración | Qué rompe si falta | Radio |
|---|---|---|
| `2026_09_02_100000_nivelaciones_columnas` | **`years.regla_nivelacion`: el guard y los dos logins.** Y aparte: `notas.nota_original` y las de `notas_finales` las nombran la planilla (`NotasController:256` y `:306`), los boletines (`BoletinesController:298`, `Boletines2Controller:224`) y el boletín final (`BolfinalesController:529`); las dos del acta de la definitiva —absorbidas de `..._200000`— las nombra `DefinitivasPeriodosController:524-526` y `:718-721`; y las tres del acta de la recuperación del año —absorbidas de `..._300000`— **rompen una ruta que ya existe**: `putUpdateRecuperacion` nombra `nivelada_at, nivelada_por, observacion` en su `UPDATE`, su `INSERT` y su `SELECT` (`:818`, `:856`, `:884`) | **el colegio entero**, más `PUT definitivas_periodos/nivelar` y `PUT definitivas_periodos/update-recuperacion` |
| `2026_08_31_200000_puestos_con_bol_independiente` | `BoletinIndependiente::puestosCuentanIndependientes()` hace `SELECT puestos_con_bol_independiente FROM years WHERE id = ?` **sin condición y sin rescate** (`app/Services/BoletinIndependiente.php:394`), y todos los boletines pasan por ahí | los tres boletines, los certificados, preescolar, promovidos, `editnota` y los cuatro informes de puestos |
| `2026_09_03_100000_rubricas` | cinco tablas nuevas y `subunidades.rubrica_id`. **Nadie fuera de `RubricasController` las nombra** —comprobado uno a uno: la planilla, unidades, asignaturas y `ChangeAsked` pasaron a nombrar sus columnas justamente para que `rubrica_id` no se les colara— | las **10** rutas de `rubricas/` |
| `2026_09_04_100000_horario_versiones` | tres tablas nuevas, `years.horario_version_id` y —absorbida de `..._200000`— **`profesores.tono`, `varchar(32)` nullable**. Sin las tablas, `POST horario/versiones` revienta; sin `tono` caen `GET horario/versiones/{id}/lecciones`, que la nombra en su `SELECT`, y `PUT horario/docentes/{profesor_id}/tono`, que la escribe. **Y el radio de `years.horario_version_id` no es de este módulo**: la lee `ChangeAskedController::horarioOficialDelAnio()` desde `getToMe` en sus dos ramas, o sea `GET ChangesAsked/to-me` — la que pide la app al abrir. Sin la migración **cae el panel de todo el mundo** (23 §11.5.1) | **`GET ChangesAsked/to-me` + las rutas de `horario/`.** Con esta fila el colegio SÍ se cae |
| `2026_08_31_100000_retirar_boletin_independiente_de_matriculas` | nada del código nuevo: **retira** `matriculas.boletin_independiente`, que ya no lee nadie | ninguno hacia delante — **pero mira la fila de abajo** |
| `2026_09_05_200000_alcance_de_la_plantilla` | dos columnas anulables en `unidades_por_defecto` —`nivel_educativo_id` y `materia_id`—. Sin ellas caen las **9 rutas de `plantilla-notas/`** y **`GET unidades/de-asignatura-periodo`**, que desde esta tanda resuelve el alcance por `App\Support\AlcanceDeLaPlantilla` y **nombra esas dos columnas**. **Y ninguna respuesta viva las reparte sola**: las tres puertas están cerradas —el `SELECT *` de `UnidadesController` se retiró aquí, el de `YearsController` no devuelve las filas, y **no hay modelo Eloquent de esa tabla**—, así que a diferencia de `profesores.tono` no aparece en ningún sitio que nadie haya decidido | **las 9 de `plantilla-notas/` y la rejilla de cada mañana.** Con esta fila el colegio SÍ se nota |
| `2026_09_05_300000_create_permiso_can_edit_plantilla_notas` | una fila en `permissions`, **sin repartir a ningún rol**. Si falta, `Autoriza::puedeEditarPlantillaNotas` no lo encuentra en `perms` y la pantalla de la plantilla **queda sólo para superusuarios** — o sea que funciona, y por eso el fallo es mudo: nadie ve un error, ve que a rectoría «no le sale el menú» | ninguna ruta se cae; **cambia quién entra**, que es más difícil de detectar |

> ### La fila del horario ha envejecido DOS veces, y la segunda con la cifra quieta
>
> Esa fila decía, hasta esta reescritura, *«**1 ruta**, y detrás de `esAdministrativo`: el
> colegio no se cae»*, más *«`getVersiones` y `putOficial` siguen a 501 y no las tocan»* y
> *«`years.horario_version_id` sigue sin leerla nadie»*. **Las tres eran falsas.** Medido en
> `main` el 5 sep 2026, y reproducido por dos sesiones:
>
> ```
> ChangeAskedController:140 y :219   'horario_version_id' => horarioOficialDelAnio(...)
> ChangeAskedController:1274         SELECT horario_version_id FROM years WHERE id = ?
> HorarioController:907, :1113, :1851   la leen y la escriben
> routes/api/disciplina.php:44       ChangesAsked/to-me — SIN middleware extra
> ```
>
> `auth.token` a secas **son todos los roles**, alumnos y acudientes incluidos, y `to-me` es
> lo que pinta el muro de `myvc_flutter`. Sobre una copia sin la columna, `to-me` contesta
> **500 `Unknown column 'horario_version_id'`**. O sea que sin esta migración no es «quien
> suba un horario recibe un 500»: **es la pantalla de inicio de la app contestando 500 a
> todo el mundo.** El login vive y el colegio no se cae entero; el muro sí.
>
> **Y lo que hay que sacar de aquí no es el radio corregido, es que la corrección no bastó.**
> Esta fila **ya documenta su propio envejecimiento** —lleva escrito que decía «no rompe en
> ninguna dirección, porque su código son tres 501» y que dejó de ser cierto el mismo día con
> `371062c`—, y **ha vuelto a envejecer exactamente igual, por segunda vez, sin que la cifra
> ni el rango se movieran**. La regla que ese párrafo enuncia se le aplicó a sí misma y no
> alcanzó. *Por eso el radio se REMIDE el día del despliegue en vez de leerse: una fila que
> avisa de que caduca sigue caducando.*
>
> Medida por `8myvc-c3`, reproducida por `8myvc-7c` y comprobada aquí antes de escribirla.

> ### ⚠ Una afirmación de esta tabla NACIÓ MAL, y se corrigió el 4 sep 2026
>
> La fila de `tono` decía: *«Entra con `AFTER regla_nivelacion`, que llega en `2026_09_02_100000`
> de esta misma tanda»*. **Es falso, y no es una cifra que envejeciera: nunca fue cierto.**
> `2026_09_04_200000_tono_del_docente` **no tenía ni un `->after()`** —su cabecera lo declaraba
> como decisión: *«SIN `after`, y es a propósito … no se compra la dependencia»*— y además `tono`
> va sobre **`profesores`**, mientras que `regla_nivelacion` es una columna de **`years`**: ese
> `AFTER` no era ni siquiera expresable.
>
> ```bash
> grep -c 'after(' database/migrations/2026_09_04_200000_tono_del_docente.php   # → 0
> ```
>
> La que sí lleva `->after('regla_nivelacion')` es **`2026_09_04_100000_horario_versiones`**, con
> `years.horario_version_id`; y `23 §11.2`, que aquella fila citaba, **lo dice bien**. O sea que la
> fila citaba correctamente una sección que decía otra cosa.
>
> **Queda escrito para que nadie la «restaure» dentro de dos meses creyendo que se rompió aquí.**
> Y es de una especie que `23 §11.5` no tenía catalogada: las otras tres afirmaciones caducadas de
> este documento **describen el servidor y fallan contra él** el día del despliegue; ésta no falla
> contra nada — si despliegas en orden funciona, y si no, el error acusa al fichero equivocado.
> *La dependencia de orden es real y sigue viva: `09_02` antes que `09_04`. Lo que estaba mal era
> de quién.*

**Cuatro de las cinco son aditivas en `up()`** —`ADD COLUMN` y `CREATE TABLE`, sin un solo `UPDATE`
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

### La comprobación de diez segundos, que ahora son siete migraciones y no una

`php artisan migrate:status` **no basta**: dice que la migración corrió, no que la columna esté.
Esto pregunta por el esquema, que es lo que leen las consultas:

```bash
php artisan tinker --execute='$f=[]; foreach ([["years","regla_nivelacion"],["years","puestos_con_bol_independiente"],["years","horario_version_id"],["notas","nota_original"],["notas_finales","nota_nivelacion"],["recuperacion_final","nivelada_at"],["subunidades","rubrica_id"],["profesores","tono"],["unidades_por_defecto","nivel_educativo_id"],["unidades_por_defecto","materia_id"]] as $c) { if (!Schema::hasColumn($c[0],$c[1])) $f[]=$c[0].".".$c[1]; } foreach (["rubricas","rubrica_criterios","rubrica_niveles","rubrica_descriptores","rubrica_valoraciones","horario_versiones","horario_lecciones","horario_pieza_docente"] as $t) { if (!Schema::hasTable($t)) $f[]="tabla ".$t; } if (!DB::table("permissions")->where("name","can_edit_plantilla_notas")->exists()) $f[]="permiso can_edit_plantilla_notas"; if (Schema::hasColumn("matriculas","boletin_independiente")) $f[]="matriculas.boletin_independiente SIGUE AHI"; echo ($f ? "FALTA -> ".implode(" | ",$f) : "OK - la tanda entera dentro").PHP_EOL;'
```

> **Esto pregunta por COSAS, no por migraciones, y desde la consolidación no son lo mismo.** La
> tanda son **siete ficheros** y esta comprobación hace **veinte preguntas**: diez columnas, ocho
> tablas, **una fila de `permissions`** y **una columna que tiene que haber DESAPARECIDO**
> (`matriculas.boletin_independiente`, la única de la tanda que se retira). Se toca **esto** en
> cuanto la tanda añade algo, y no
> se espera al día del despliegue como las tablas de arriba: aquéllas son lo que se midió el día
> que se midió; **este fragmento es una comprobación que alguien va a ejecutar**, y una que no
> pregunta por lo último que entró contesta que todo está bien en un colegio al que le falta algo.
>
> `profesores.tono` se le añadió el 4 sep 2026 (decisión de Joseth, `23-horarios.md` §9.bis.3) y
> **ya no vive en una migración propia**: la absorbió `2026_09_04_100000_horario_versiones`. Que su
> fichero desapareciera **no le quitó ni una columna a la tanda**, y es exactamente por eso que
> esta lista se escribe por columnas y no por migraciones.
>
> **Y desde el 5 sep comprueba también las dos de la plantilla**, con una fila por cada cosa que
> la tanda añade y no sólo por migración: las **dos columnas de `unidades_por_defecto`** y —lo que
> no es una columna— **la fila `can_edit_plantilla_notas` de `permissions`**. Esa última es la que
> ninguna comprobación de esquema habría cazado, y es justo la que falla en silencio: sin ella la
> pantalla de la plantilla funciona **sólo para superusuarios**, así que el síntoma no es un error
> sino que a rectoría «no le sale el menú». Es la misma regla que habría cazado el hueco de
> `profesores.tono`: **al menos una comprobación por cada tabla que la tanda toca.**
>
> > ⚠️ **Y esa línea del permiso dirá siempre `FALTA` si la corres contra una base de TESTS.**
> > No es un fallo de la comprobación ni de la migración: `database/dumps/test-seed.sql` hace
> > `TRUNCATE TABLE permissions` **después** de que corran las migraciones
> > (`tools/construir-bd-test.sh` las ejecuta antes del seed), así que la fila que siembra
> > `2026_09_05_300000` se la lleva el seed por delante. **Medido el 5 sep 2026** sobre
> > `simonbolivar_testing_p`: las dos columnas de `unidades_por_defecto` sí aparecen y el permiso
> > no, con 19 filas en `permissions`. En un colegio de verdad no hay nada que trunque, así que
> > ahí la línea es válida — **se corre contra la base del colegio, no contra una de pruebas**.
>
> **Y desde el 4 sep la etiqueta ya no lleva número: dice `OK - la tanda entera dentro`.**
> Contar migraciones ahí era contar la cosa equivocada —el fragmento pregunta por columnas y
> tablas, no por ficheros—, y la fusión de ese día lo dejó claro: **las mismas columnas pasaron
> de ocho ficheros a cinco sin que la comprobación cambiara una letra**. Una etiqueta con un
> número dentro es una que hay que acordarse de mover; ésta ya no.

**Dice qué falta, no sólo que falta**, y **tiene control negativo**: probado el 2 sep 2026 contra la
base migrada (`OK - la tanda entera dentro`) **y** contra una sin migrar y con nombres inventados, donde
imprime la lista. Un `OK` que no sabe fallar es el que archiva el asunto.

**La otra acción del día, que no es una tabla:** correr `tools/independientes-sin-estructura.php`
**en los dieciséis**, uno por uno, después de migrar. Contesta la §9.1 —qué alumnos están marcados y
**no tienen ni una unidad propia**, cuya definitiva sale 0 sin que nadie reciba un error—, y **sin
la tabla contesta `exit=2 · NO CONCLUYENTE` a propósito**: un `0` limpio sería la respuesta que
archiva el asunto justo en el colegio donde no se ha mirado nada. Lo medido hasta hoy es **cero
marcados en desarrollo**, que **no** es «cero en los dieciséis»: eso sólo se sabe allí.

### Los avisos para el front que viajan en esta tanda

> **Repasados los nueve (K–S) el 5 sep 2026, y no se repasaron a ojo.** La pregunta no es
> «¿siguen siendo ciertos?» sino la de la regla de arriba —*una afirmación caduca cuando el código
> cambia aunque su número no se mueva*—, así que **se midió la población entera** en vez de releer
> las filas: se restaron las claves, una a una y hasta el fondo del árbol, de los **125 snapshots
> de `tests/Contrato/Snapshots/` entre `9474b50` y `main`**.
>
> **Cambian 29.** Descontando los **3** que no son respuestas (`rutas.json`, `guard-por-familia.json`
> y `familias-que-nunca-entran-en-el-candado.json`) quedan **26 respuestas que ya existían y cambian
> de forma**, más **4 snapshots nuevos**: tres son el mismo endpoint nuevo
> (`grupos/{grupo_id}/alumnos-de/{que}`, uno por valor de `{que}`) y el cuarto,
> `editnota-alum-asignatura`, es de **una ruta que ya existía** y a la que sólo ahora se le puso test.
>
> **Lo único bueno de la lista:** de esas 26, **la única que PIERDE claves es `ChangesAsked/to-me`**
> (las ocho del aviso K). **Las otras 25 sólo ganan.**
>
> **Y así salieron los tres que faltaban.** Q, R y S no son un olvido de quien escribió esta tabla:
> son **campos que se reparten solos**, por un `SELECT *`, por un modelo Eloquent devuelto entero o
> por una clave nueva de primer nivel. *Los avisos que faltan no los escribe quien escribió el
> campo, porque nadie escribió el campo.*

| | aviso | estado |
|---|---|---|
| **K** | `GET ChangesAsked/to-me` deja de mandar **ocho** columnas de cada evento del calendario y conserva nueve. **Aquí decía «nueve» y era la cifra de las que se QUEDAN**, contada como si fueran las que se van; medido sobre el snapshot el 2 sep 2026, el evento pasa de 17 claves a 9. Las ocho que se van son `created_at`, `created_by`, `created_by_nombres`, `deleted_at`, `deleted_by`, `type`, `updated_at` y `updated_by`. Una de ellas, **`created_by_nombres`, la pinta la aplicación vieja** en el tooltip del evento (`AnunciosCtrl.ts:596`): al desplegar dirá **«Por: undefined»** hasta que se arregle allí, que es una línea | **AVISADO Y ARREGLADO EN EL FRONT el 6 sep 2026** (`myvc_front@9419ccc3`, condicional en vez de borrado). ⚠️ **Y trae una trampa de despliegue que no era nuestra**: ese arreglo viaja por `myvc_dist` a **`up/`**, no por `myvc_dist2` a `up2/`. **Si ese día sólo se sube `up2/`, el «Por: undefined» se queda.** Hay que subir las dos |
| **L** | **`POST tardanzas/login/traer-datos` desaparece**: pasa a 404. Decisión de Joseth del 2 sep. El único llamante de toda la máquina es `tardanzasMyvc-old` (último commit feb 2020), y Joseth confirmó que ese repositorio está inactivo — el dato que lo cerró **no estaba en el repositorio** | **DECIDIDO** — es la única ruta que la tanda quita |
| **M** | **`regla_nivelacion` aparece en el bloque de la sesión**, en las cuatro ramas (alumno, acudiente, profesor y usuario). Es un campo **nuevo**, para previsualizar en el diálogo de nivelación qué nota va a quedar (22 §1.4 y §5.1) | **ADITIVO** — Flutter no se rompe: `ConfiguracionColegio.deLogin` lee campo a campo y no hay `json_serializable` ni `freezed`. Medido, no supuesto (22 §3.2bis) |
| **N** | **Campos nuevos de nivelación en respuestas que ya existían.** Aquí decía «la planilla (`PUT notas/detailed`), los boletines, el boletín final y `PUT editnota/alum-asignatura`»: **son cuatro nombres para DIEZ respuestas, y faltaban dos sitios** — `PUT notas-actuales-alumnos/{grupo_id}` y **`GET notas/alumno/…`, la que llama un ALUMNO para ver sus propias notas** (gana `nota_original_asignatura` y `nivelada_at_asignatura`). Y «los boletines» son **`boletines` y `boletines2`, cuatro respuestas: `boletines3` no gana ni una clave**, que es justo lo que aquí no se veía. Qué respuesta abre las columnas **a propósito** y cuál las tiene **congeladas** está decidido sitio por sitio en la tabla de [22 §3.4](migracion/22-nivelaciones.md) y en el [27](migracion/27-nivelaciones-en-los-informes.md) | **ADITIVO** — ningún cliente pierde una clave. La enumeración, **corregida el 5 sep 2026** restando los snapshots: 10 respuestas, no 4, y una de ellas es de un alumno sobre sí mismo |
| **O** | **36 rutas nuevas**: las 10 de `rubricas/`, las **9 de `plantilla-notas/`**, las 5 de `boletin-independiente/`, las **6** de `horario/` —**las seis con cuerpo: ninguna contesta ya 501**—, las 4 de nivelar, `GET grupos/{grupo_id}/alumnos-de/{que}` y `GET colegio/logo`. **Todas menos `colegio/logo` llevan `auth.personal`**: un alumno o un acudiente que las llame recibe **403**, y las de nivelar además exigen `periodos.profes_pueden_nivelar` al profesor (`User.php:425`). **Y TRES de `horario/` no bastan con `auth.personal`**, que es de lo que va este aviso: `PUT horario/versiones/{id}/oficial`, `PUT horario/docentes/{profesor_id}/tono` y **`GET horario/versiones/{id}/proyecto`** exigen además `puedePublicarHorario` **dentro** —superusuario o `Coord académico`, un rol con **cero usuarios**—, así que a un docente llano le contestan 403 aunque pase el guard | **POR AVISAR** — es de las de «quién puede llamarla». **Aquí decía 26 y luego 35; son 36**: las nueve de `plantilla-notas/` entraron el 5 sep por la mañana y **la sexta de `horario/` esa misma tarde**. *Tercera vez que esta fila se queda corta en el RECUENTO, y las tres por lo mismo: se escribe una lista y sigue entrando código.* **Y la corrección de las 36 se quedó corta a su vez en el CRITERIO**: dijo «dos de `horario/`» cuando son **tres** —se le pasó `putTonoDocente`, que ya estaba nombrada veinte líneas más abajo con ese mismo criterio—. Recontado sobre `HorarioController` el 5 sep 2026: tres llamadas a `puedePublicarHorario`, ni una más. *Contar rutas y contar quién puede llamarlas son dos censos distintos, y arreglar el primero no arregla el segundo.* |
| **P** | **`profesores.tono` es un campo NUEVO en TRECE respuestas vivas**, y sólo dos son del horario. Once por Eloquent —las cinco de `profesores/` (`store`, `update`, `destroy`, `forcedelete`, `restore`), las **tres de `perfiles/`** (`show/{id}`, `update/{id}`, `cambiarimgunprofe/{id}`), las **dos de `images-users/`** (`cambiar-foto-un-usuario/{id}`, `cambiar-firma-un-profe/{id}`) y el `titular` de `GET grupos/show/{id}`— y **dos por `SELECT p.*`**: `PUT profesores/listado` y `PUT participantes/profesores`, ésta de **votaciones**. Vale `null` en los diecisiete hasta que alguien reparta colores | **ADITIVO** — ningún cliente pierde una clave. Va dicho porque **un campo nuevo se manda dicho, no descubierto**, y porque **cinco de las trece son pantallas de perfil e imágenes que no tienen nada que ver con el horario**. *Medido por dos sesiones que no se copiaron: 7 + 5 + 1 = 13.* **Y es de RESPUESTAS, no de pantallas**: `myvc_front` midió que dos de esas trece no las llama —`perfiles/show/{id}` y `perfiles/cambiarimgunprofe/{id}`— y `myvc_flutter` y `myvc_front_2` **no están medidos** |
| **Q** | **`GET ChangesAsked/to-me` gana una clave de primer nivel: `horario_version_id`** (`null` mientras el año no tenga horario publicado, que es hoy en los diecisiete). **Es la misma respuesta del aviso K y va aparte a propósito**: K cuenta lo que se VA y esto es lo que LLEGA. La respuesta la piden **todos los roles** —lleva `auth.token` y nada más— y es lo que la app llama al abrir (`MuroApi.traerMuro`) | **POR AVISAR** — campo nuevo. **Faltaba en esta tabla**: se detectó restando los snapshots, no leyendo el commit |
| **R** | **Tres respuestas de años reparten las tres columnas nuevas de `years` sin que nadie lo pidiera**: `GET years`, `GET years/colegio` y `GET years/trashed` ganan `regla_nivelacion`, `puestos_con_bol_independiente` y `horario_version_id`. **No lo hace un cambio: lo hace el `SELECT y.*` de `YearsController`**, que estaba ahí desde antes. Las dos primeras llevan sólo `auth.token`, o sea que le llegan también a un alumno y a un acudiente | **POR AVISAR** — campo nuevo, y de los que **no aparecen en ningún diff de la respuesta**. **Faltaba en esta tabla** |
| **S** | **Los campos del boletín independiente también se reparten solos, y no tenían aviso**: `bol_independiente` en los boletines y `bol_independiente_aparte_en` en el acta de promoción; `bol_independiente_periodo` en los dos informes de notas perdidas y en `PUT puestos/detailed-notas-year` (que además gana `puestos_con_bol_independiente`); y `bol_independiente_datos` más `independientes` en la planilla. **ONCE respuestas** — contadas restando snapshots, sin contar las tres de `years`, que van en **R** | **CERRADO el 6 sep 2026: SÍ estaba avisado**, y en cinco documentos del front —`PANTALLAS-HISTORIAL-Y-BOLETIN.md` (§B, con la tabla de qué pantalla necesita qué campo), `TAREAS-NIVELACIONES-Y-RUBRICAS.md:282`, `TAREAS-PLANTILLA-Y-COMPETENCIAS.md` (§4.bis), `COORDINACION-NOCHE.md` y `MIGRATION.md`—, confirmado por `myvc-front-05` y `myvc-front-21` por separado. *Lo que no se podía comprobar desde este repositorio lo contestó preguntando.* — texto original: es posible que viajara en el documento del front (`DESPLIEGUE-NIVELACIONES-Y-RUBRICAS.md`), que **no se puede comprobar desde este repositorio**. Lo que sí se comprobó es que **en esta tabla no estaba** |

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
> **Las 36 están CONTADAS contra la base desplegada, no sumadas** — que es lo que este repo
> exige de un número que se escribe: `9474b50` declara **543** rutas y `main` (`d606839`)
> **578**; comparados los dos conjuntos de URIs, **36 entran y 1 se va** (`POST
> tardanzas/login/traer-datos`, el aviso L), y **543 + 36 − 1 = 578**.
>
> **Las TRES de `horario/` que no bastan con `auth.personal`** —el detalle que hace que este
> aviso sea de «quién puede llamarla» y no de «qué rutas hay»— son
> `PUT horario/versiones/{id}/oficial`, `PUT horario/docentes/{profesor_id}/tono` y
> `GET horario/versiones/{id}/proyecto`. Las tres llaman a
> `Autoriza::exigir(Autoriza::puedePublicarHorario(...))` **dentro del método**
> (`HorarioController` :2614, :2649 y :3050), o sea **superusuario o `Coord académico`**, un rol
> que tiene **cero usuarios**. **A un docente llano le contestan 403 aunque pase el guard.**
>
> **Y las nueve de `plantilla-notas/` tienen el suyo, que es el que hay que avisar:** las
> nueve llevan `auth.token` + `auth.personal`, y **las nueve —la de leer incluida—** exigen
> además `Autoriza::puedeEditarPlantillaNotas()`, o sea superusuario **o** el permiso
> `can_edit_plantilla_notas`. **Ese permiso nace repartido a nadie a propósito** (lo explica
> su migración), así que **el día del despliegue la pantalla entera es de los superusuarios y
> de nadie más en los diecisiete** — incluido el `GET`. No es un error ni un 500: a rectoría
> «no le sale el menú» hasta que el colegio le dé el permiso desde su pantalla de roles.
>
> *Recontado entero el 5 sep 2026 al fusionar la plantilla. **Se volvió a contar, no se le
> sumaron nueve a 26** — que es la única forma de que el 577 signifique algo. Y de paso salió
> que el párrafo anterior **no cuadraba consigo mismo**: decía `HEAD` **567** y a la vez
> `543 + 26 − 1 = 568`. Las dos no podían ser ciertas, y **no hay test que mire la aritmética
> de una frase**.*
>
> *Y se volvió a contar **esa misma tarde**, porque entró la sexta de `horario/`: **578**, y
> `543 + 36 − 1 = 578` vuelve a cuadrar. **El 577 de arriba no se corrige: era cierto a
> mediodía** y describe cómo se contó aquella vez. Lo que caduca es el número vivo, y ése
> está en la tabla del rango y en el aviso **O**. La lección de la mañana, cobrada dos veces
> el mismo día: **una cifra se anota con el momento en que se contó, o se lee como una
> contradicción.**
>
> **Lo que NO se ha tocado y sigue esperando al día del despliegue** es la fila de
> `2026_09_04_100000_horario_versiones` de la tabla de migraciones, que dice que sólo
> `POST horario/versiones` pasa de 501 a 500: ésa describe el servidor, falla contra él, y
> su caducidad está escrita en [23 §11.5](migracion/23-horarios.md).

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
> esa**: `2026_08_31_100000` es la **primera** de las siete por orden de ejecución, así que llegar a
> ella es deshacer las siete. Como corrieron en el mismo `migrate`, son un solo lote:
>
> **`--step` de `migrate:rollback` cuenta MIGRACIONES, no lotes** —lo contrario de lo que hace
> `--step` en `migrate`—, y desde la fusión del 4 sep una migración son varias columnas de varias
> tablas: **`--step=7` deshace la tanda entera, no siete columnas.**
>
> ```bash
> php artisan migrate:status | tail -9      # confirma que el lote pendiente son las SIETE
> php artisan migrate:rollback --step=7     # las siete, en orden inverso
> ```
>
> **`--step=1` NO sirve aquí**: revierte la última, que desde el 5 sep 2026 es
> `2026_09_05_300000_create_permiso_can_edit_plantilla_notas` —una fila de `permissions`— y deja
> la columna retirada, y todo lo demás, exactamente igual.
>
> **El 7 se recontó, no se le sumó 2 al 5**, y por lo que este mismo bloque dice dos párrafos más
> arriba: `--step` cuenta **migraciones**, y desde la fusión una migración son varias columnas de
> varias tablas. Sumar habría acertado el número describiendo mal la tanda.
>
> **Y esto sí pierde datos, al revés que en las tandas anteriores.** El `down()` de la del
> `dropColumn` es exacto —devuelve la columna **a 0 en todas las filas, que es lo que había**: nunca
> llegó a tener un 1 en ninguna base—, pero los `down()` de las otras cuatro se llevan **lo que se
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
