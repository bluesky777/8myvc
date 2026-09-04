# Dónde está la aguja ahora mismo

> **Léeme el primero.** Este documento existe para que una sesión nueva pueda
> continuar **sin que Joseth tenga que dar contexto**. Es corto a propósito: dice
> qué se está haciendo, qué acaba de terminar, qué es lo siguiente y qué espera una
> decisión suya. El detalle de cada cosa vive en su documento y está enlazado.
>
> **Se actualiza en el mismo commit que el trabajo**, no en uno aparte al final:
> un commit aparte es el que no se hace cuando la sesión se corta.

> **Y una corrección de nombre que NO es cosmética.** Las tres entradas de esta noche que
> atribuían trabajo a `8myvc-d2` decían mal el nombre de la sesión que coordinó: es
> **`8myvc-7d`**. Firmé así toda la noche —y `8myvc-d5` lo copió de mí de buena fe— hasta
> que `myvc-horarios-4a` avisó de que en su `ListAgents` yo salía como `7d`, y lo comprobé.
>
> **Importa porque `8myvc-d2` existe y es otra**: es la sesión de guardas de cuentas del
> **24 ago 2026**, y aparece en cinco documentos más ([18](18-auditoria.md),
> [05](05-codigo-muerto-y-roto.md) ×2, `noche-2026-08-24/exp-1.md`,
> `noche-2026-08-25/aud-2.md`). O sea que no era un nombre inventado que no resolviera:
> era **un nombre que resuelve a otro sitio**, y quien siguiera el rastro habría acabado
> leyendo el relevo de una noche distinta sin nada que le dijera que se había equivocado.
> Los mensajes de commit están limpios —cero apariciones—, así que sólo había que tocar
> estas tres líneas.

**4 sep 2026 — EL HORARIO, REPASADO ENTERO ANTES DE ENTREGARLO: LA SUITE COMPLETA EN VERDE Y
SEIS AFIRMACIONES QUE LA CUARTA RUTA DEJÓ VIEJAS — Y EL HORARIO ENTERO EN `main`** ·
**FUSIONADO Y EMPUJADO** el 4 sep 2026 con tu autorización: `main` en **`200c566`**, que es
`8f59242` + los seis commits de `docs/horario-cuarta-ruta-y-despliegue` en avance rápido ·
sólo documentos por mi parte: [23](23-horarios.md) §§ cabecera, 5.3, 8, 10.2.3, 11 y 11.5,
[`DESPLIEGUE.md`](../DESPLIEGUE.md) aviso O, y esta casilla · **cero código, el router no se
mueve: siguen 567**

> **Las entradas de más abajo dicen «sin fusionar y sin empujar» y NO se corrigen**: eran
> ciertas el día que se escribieron y son el registro de que este trabajo pasó por una rama.
> Lo que vale es esta línea, que es la de arriba.

> **El módulo está terminado en este repo, y ahora está medido de punta a punta y no sólo por
> el filtro de su nombre:**
>
> | | |
> |---|---|
> | `route:list --json` | **567**, y la familia `horario` son **4** rutas |
> | `--filter=Horario` | **102 pasan (855 aserciones)** |
> | **la suite entera** | **`Tests: 1945 passed (17462 assertions)`**, cero rojos, **828 s** |
> | pint · larastan nivel 7 | **PASS** · **`[OK] No errors`** |
> | `tools/secciones-citadas.py` | **0 huérfanas** sobre 541 §§ declaradas y 2.122 citas |
>
> **La suite entera hacía falta y no era ceremonia**: esta rama trae una **migración que
> añade una columna** (`profesores.tono`), y una columna nueva **se reparte sola** por todo
> `SELECT *` que la toque — el snapshot `grupos-show.json` ya lo había cazado. El
> `--filter=Horario` de la entrega anterior no podía verlo: mira el módulo, y el radio de
> una columna no es el módulo. **1.930 → 1.945** contra `main`, y los quince son los de esta
> rama.
>
> ### LAS SEIS AFIRMACIONES VIEJAS, Y LAS SEIS IBAN EN LA MISMA DIRECCIÓN: DECÍAN «TRES»
>
> Ninguna la envejeció el tiempo: **las envejeció el commit siguiente al que las escribió**.
> `0345ad5` metió la cuarta ruta y no volvió a los sitios donde este documento contaba tres.
>
> | dónde | decía |
> |---|---|
> | **cabecera del 23** — lo primero que se lee | *«Nada de esto está construido. Las tres rutas de la §5.3 son una propuesta»* |
> | **bloque del lote A** | *«Lo que falta es el cuerpo de los tres, y todo lo que este documento dice de la §6 y la §7 sigue sin construir»* |
> | **§5.3** | la familia `horario` entra como **3 de 3** — el snapshot dice **4 de 4** |
> | **§8**, la frontera del escritorio | *«Siguen siendo las tres de la §5.3»* |
> | **§10.2.3**, descargar el proyecto | *«Sería una **cuarta** ruta»* — y la cuarta ya se llama `lecciones`: es la **quinta** |
> | **§11.1** | **tres** rutas dando 404 allí, y **232** commits sin desplegar — remedido sobre `bf83d3c`: **cuatro** y **236** |
>
> **Las tachadas van tachadas y no borradas**: este documento es también el registro de cómo
> se decidió, y una frase que nació bien y se leyó mal dos días después enseña más entera que
> desaparecida.
>
> ### Y UNA QUE NO ERA UNA CADUCIDAD: EL AVISO **O** CUENTA MAL, Y VA HACIA FUERA
>
> `DESPLIEGUE.md` dice **«24 rutas nuevas… las 3 de `horario/`»** y está marcado **POR
> AVISAR**. Con la cuarta son **25 y 4**. La §11.5 pasa de dos afirmaciones envejecidas a
> **tres**, y la tercera es de otra clase:
>
> - **Las otras dos se arreglan solas el día del despliegue** — dicen que las rutas
>   contestan 501, y quien las lea las ve fallar contra el servidor.
> - **Ésta no falla contra nada.** Remedir contesta *«¿siguen a 501?»*; **nadie va a
>   recontar un «24» si no sabe que hay que hacerlo**. Y es una lista que se le manda al
>   front: un aviso que nombra tres rutas cuando hay cuatro **deja la cuarta sin avisar sin
>   que se note el hueco**, porque el front construye su menú con lo que le dijeron. Es
>   exactamente el caso que la regla del canal manda avisar: *una ruta nueva, o quién puede
>   llamarla*.
>
> **Joseth lo decidió al revés de la regla, y a propósito: «hazlo tú y comunícale a front».**
> Así que el aviso O **se corrigió a mano en `DESPLIEGUE.md`** —con el porqué de la excepción
> escrito al lado de la tabla— y **el aviso se dio**, en
> `~/DESARROLLOS/myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, sección C, fechado y firmado
> por esta sesión. **Escrito allí y sin commitear allí**, que es la regla del canal: una
> sesión se cierra, el fichero queda, y ese repositorio es suyo.
>
> **Las 25 se CONTARON, no se sumaron**: `9474b50` —lo desplegado— declara **543** rutas y
> `HEAD` **567**; comparados los dos conjuntos de URIs entran **25** y se va **1** (`POST
> tardanzas/login/traer-datos`, el aviso L). **543 + 25 − 1 = 567.** Que cuadre con el router
> es la comprobación; el método fue comparar los conjuntos.
>
> **Y lo que se le dijo al front no es sólo la cifra**, porque una cifra no construye un
> menú: las 25 con su método y su guard, los **dos 403 de `horario/` con su texto exacto**
> —`esAdministrativo` para subir, `puedePublicarHorario` para publicar—, que las cuatro de
> nivelar exigen además `periodos.profes_pueden_nivelar` y eso **no se ve en la ruta**, y de
> la cuarta del horario **los cuatro estados de catálogo** (`vacio` y `sin_catalogo` no se
> pueden pintar igual), la lista de docentes en vez del escalar y `nombre_salon` sin
> `salon_id`. **Lo que hoy contestan las 25 en los diecisiete es 404**, y esa es la señal
> buena: *«esta versión del servidor no tiene el módulo»*.
>
> ### LO QUE QUEDA DEL HORARIO, Y NINGUNA ES CÓDIGO DE ESTE REPO
>
> 1. ~~**Fusionar a `main` y empujar**~~ — **AUTORIZADO Y HECHO** por ti el 4 sep 2026.
> 2. **Desplegar**, que sigue **congelado** por ti mientras `myvc_flutter` está en revisión, y
>    va **0 de 16**. No hay camino «sólo horario»: la tanda de ocho migraciones es
>    indivisible (§11.2).
> 3. ~~**Dar el aviso O al front** con **25 y 4**.~~ **DADO el 4 sep 2026**, por encargo tuyo.
>    Lo que queda de él **es del front**: contestar si alguna pantalla suya ya llama a alguna
>    de las 25 contando con el 404 de hoy, y si la cuarta del horario les cambia lo que tenían
>    escrito para el menú.
> 4. **Las cuatro decisiones abiertas de la §10.2**, que son tuyas: si `GET asignaturas` debe
>    traer las asignaciones con la materia en la papelera, el tope del blob, si existe una
>    ruta para **descargar** el proyecto (la quinta), y confirmar que el orden de «Clases de
>    hoy» no se promete.
> 5. **El fallo del sábado sigue sin verse con datos reales**: el `% 7` sólo se ha visto en un
>    test que congela el reloj. Eso no lo cierra ninguna sesión — lo cierra un sábado.
>
> **Y una que no es del horario, apuntada porque estaba en el árbol:**
> `docs/migracion/28-competencias-e-indicadores.md` tiene **312 líneas sin commitear** y
> **dentro hay un bloque duplicado** —«Pero NO repara hacia atrás» aparece dos veces, en las
> líneas 166 y 178—. **No es mío y no lo he tocado ni commiteado**; está respaldado en el
> scratchpad de esta sesión. Lo escribió quien lleva `fix/frases-asignatura-text`, que es un
> worktree: en el árbol principal eso se queda huérfano.

**4 sep 2026 — LA CUARTA RUTA DEL HORARIO, ESCRITA: `GET horario/versiones/{id}/lecciones`,
Y EL ROUTER EN 567** · rama `docs/horario-cuarta-ruta-y-despliegue`, **sin fusionar y sin
empujar** · `HorarioController`, `routes/api/horario.php`,
`2026_09_04_200000_tono_del_docente`, `tests/Contrato/HorarioLeccionesTest.php` (**11 casos,
92 aserciones**), `HorarioAutorizacionTest` y `tools/deriva-del-horario.php` · **567 rutas,
contadas con `route:list --json` ese día** · pint **PASS** · larastan nivel 7 `[OK] No errors`

> **Era lo único que bloqueaba la web del horario**: se podía subir, listar y publicar, y no
> había forma de mirar lo publicado. Joseth cerró las cuatro decisiones que faltaban ese
> mismo día (§9.bis.3 del [23](23-horarios.md)), y **tres de ellas descartaron la opción que
> parecía más cómoda**:
>
> | decisión | y lo que descartó |
> |---|---|
> | **`tono` es del docente y lo guarda el back** — columna nueva en `profesores` | descartó dejarlo `sin_catalogo` para siempre **y leer el blob para sacar los colores**, que era la única que tocaba el fichero de proyecto |
> | **`{id}` explícito**, no `horario/oficial` | por su propia asimetría: quien va a publicar necesita **mirar una versión que todavía no es la oficial** |
> | **el menú lo abre el permiso de Referencias académicas** | no hay permiso nuevo; `auth.personal` sigue siendo el de *leer*, y ver y crear no se mezclan |
> | **los booleanos de `asignaturas` NO alimentan el horario y se quedan** | son del panel del docente, y tienen que servir a un colegio que nunca use este sistema |
>
> ### LO QUE DECIDIÓ LA FORMA FUE QUE ESTA BASE NO PUEDE DEVOLVER UN PROYECTO COMPLETO
>
> Por eso cada catálogo viaja con su estado y su población, y **son cuatro estados y no
> dos**: `completo`, `parcial`, **`vacio`** («el colegio no creó ninguno, y es legítimo») y
> **`sin_catalogo`** («esta API no puede saberlo»). Separarlos es lo único que impide que la
> ruta convierta salones y timbres en obligatorios para que la pantalla no mienta — o sea,
> que deshaga por la puerta de atrás la restricción de Joseth de que **el horario es
> opcional**. Va atado por un test: *una versión sin un solo salón, sin ninguna doble y sin
> colores repartidos devuelve **200** con sus renglones en `vacio`, nunca un 422.*
>
> ### LOS DOS SITIOS DONDE ESTA RUTA SE APARTA DE LO QUE LE PIDIERON, Y POR QUÉ
>
> 1. **Los docentes van en lista, no en `profesor_id` escalar.** El escalar funcionaría hoy
>    —**0 de 312** piezas tienen dos docentes— y se rompería **en silencio** el día que
>    exista la misa, que es justo el caso para el que los docentes cuelgan de la pieza y no
>    de la asignación (§5.1). Hay test.
> 2. **`salon_id` no viaja.** No hay tabla de salones: un campo que sale `null` siempre
>    entrena al cliente a ignorarlo. Viaja `nombre_salon`, y el catálogo dice `hay_ids: false`.
>
> ### Y EL VIGILANTE DE LA DERIVA YA EXISTE: `tools/deriva-del-horario.php`
>
> Joseth lo decidió **con el radio delante** —lo que se descuadra sin aviso no es un menú
> opcional: es la portada con la que aterriza todo docente, porque `horario_hoy` sale de esas
> mismas siete columnas— y decidió que **sea lo único**: ni se toca `putOficial`, ni se avisa
> al conmutar desde el front.
>
> Da **0 de 134** en el docker, **y ese cero tiene control**: conmutando `sabado` en una
> asignación real —y devolviéndola después a sus siete valores— pasa a **1 de 134**, nombra
> la fila y sale con **código 1**. **Un año sin versión oficial sale `2`, NO MEDIDO**, nunca
> `0`: ahí no hay contra qué comparar y un cero diría lo mismo que un año perfecto.
>
> ### Lo que hay que saber para el despliegue, y no cambia el congelado
>
> La tanda pasa a **ocho** migraciones. `profesores.tono` **se reparte sola a SIETE
> respuestas vivas**: seis que devuelven la ficha por Eloquent (`postStore`, `putUpdate`,
> `deleteDestroy`, `deleteForcedelete`, `putRestore` y `GET grupos/{id}` dentro de
> `titular`) **y una cruda que no es de este módulo ni de su pantalla** —`PUT
> participantes/profesores`, de votaciones—: vale `null` en todas, así que es inofensiva — **pero es un campo nuevo y se
> manda dicho, no descubierto**. Las cinco estáticas del modelo nombran sus columnas y no
> se mueven, y `ProfesoresController::getShow` es de ésas: usa `detallado()`.
>
> > **Aquí decía «cinco» y nombraba un «`getShow` de papelera» que no existe.** Lo corrigió
> > `8myvc-e0` leyendo los `return` uno a uno — que es lo que yo no hice: conté los sitios
> > que recordaba, no los que hay. Las dos de la papelera son `deleteDestroy` y
> > `deleteForcedelete`, y el `getShow` que sí existe es justo el que **no** reparte nada.
> > La cifra iba **hacia abajo**, que es la dirección en la que un error no se nota.
> >
> > **Y SON SIETE, no seis: la misma cifra volvió a ir hacia abajo por la misma puerta.**
> > `8myvc-e0` corrigió «cinco» leyendo los `return` de Eloquent uno a uno, y yo escribí
> > «seis» **heredando su instrumento**: mirar Eloquent. La séptima **no es de Eloquent**,
> > es una consulta cruda —`VtParticipantesController::putProfesores`, `PUT
> > participantes/profesores` con `auth.personal`—: `SELECT * FROM profesores p INNER JOIN
> > contratos c`, devuelta tal cual en `['participantes' => …]`. *La primera corrección
> > arregló el recuento y dejó puesta la definición estrecha; contar mejor dentro del
> > conjunto equivocado no saca del conjunto equivocado.*
> >
> > **Medido el 4 sep 2026 con su población, que es lo que hace que este siete valga más
> > que los dos anteriores:** **191** cadenas SQL de `app/` nombran `profesores`; **6**
> > proyectan con asterisco; y de esas seis **tres son falsos positivos del detector** —un
> > `SELECT *` sobre una **subconsulta que nombra sus columnas** (`VtParticipante:79`,
> > `PerfilesController:129` y `:810`), donde el asterisco no ve la tabla—. Queda **una**
> > cruda viva, más `DocentesExport`, que **no cuenta**: es `FromView` y su Blade no nombra
> > `tono`, así que la hoja no gana columna. Total: **6 de Eloquent + 1 cruda = 7
> > respuestas JSON**.
> >
> > **Y de aquí sale el aviso que importa para el despliegue**, porque la séptima no es la
> > ficha de un docente en su pantalla: `PUT participantes/profesores` es de **votaciones**,
> > y allí nadie está mirando si al docente le sobra un campo. Sigue siendo inofensiva
> > —`null` en los diecisiete— pero **el radio de una columna no es el módulo que la
> > pidió**, y ése era justamente el argumento con el que esta misma entrada justificó
> > correr la suite entera. *Lo apliqué al elegir la suite y no al escribir el número.*

**Anterior: 4 sep 2026 — LA CUARTA RUTA DEL HORARIO: LO QUE ESTA BASE PUEDE DEVOLVER, MEDIDO, Y EL
DESPLIEGUE ESCRITO SIN DESCONGELARLO** · rama `docs/horario-cuarta-ruta-y-despliegue`,
**sin fusionar y sin empujar** · [23 §9.bis.3, §9.bis.4 y §11](23-horarios.md) y
[29 §5](29-los-env-no-son-uniformes.md) · **cero código: el router no se mueve, siguen 566** ·
lo pidió `myvc-horarios-43` con la forma que midió `myvc-front-4f`

> **La ruta sigue sin escribirse a propósito**: su forma es decisión de Joseth y quedan
> **cuatro** preguntas suyas listadas al final de la §9.bis.3. Lo que se hizo fue quitarles
> de encima todo lo que se podía medir, sobre `8f59242`, base `simonbolivar` del docker,
> año 8 y versión oficial 6 — **población: un colegio, una versión, 312 lecciones**.
>
> | lo que decide la forma | medido |
> |---|---|
> | **`profesores` NO tiene columna de color** | así que `tono` no es «vacío»: es **`sin_catalogo`**. Allí el dato está previsto y vacío; aquí no hay dónde ponerlo, y **las dos cosas se leerían igual** |
> | **salones a medias, con datos nuestros** | **87 de 312** lecciones con salón y **3 nombres** contra los 17 del proyecto real. El caso que `myvc-horarios-90` midió como el peor **es el que hay** |
> | **el caso nulo es el normal** | **22 de 312** piezas sin ninguna fila en `horario_pieza_docente` · **10 de 134** asignaciones sin `profesor_id` |
> | **las dos codificaciones de `dia` COINCIDEN** | `0 = domingo` en los dos lados, y la versión 6 va de `dia` 1 a 5. No hay conversión que escribir — **y por eso hay que dejarlo escrito** |
>
> **Y la restricción nueva de Joseth —el horario es OPCIONAL— cambió la forma:** hacen falta
> **tres** estados de vacío y no dos. `vacio` («el colegio no creó ninguno, y es legítimo»)
> separado de `sin_catalogo` («esta API no puede saberlo») es lo único que impide que la
> ruta convierta salones y timbres en obligatorios para que la pantalla no mienta.
>
> ### Y de camino salió que ya hay DOS escritores de las siete columnas de día
>
> `toggleDia` de la pantalla `asignaturas/` (lado obligatorio, **vivo en los dieciséis**) y
> `putOficial` (§7.1), que las reescribe **de todo el año**. **En la dirección «publicar» la
> colisión ya estaba resuelta sin saberlo**: lo que se borra es justo lo que cuenta
> `acepto_perder`. **En la contraria no hay nada** — conmutar un día después de publicar
> descuadra las dos pantallas y no lo detecta nadie. Medido hoy: **0 de 134 descuadradas**,
> que es el cero más fácil de conseguir *tres días después de publicar* y el que menos dice.
>
> ### El despliegue: **0 de 16**, y sigue CONGELADO — lo que se hizo fue escribirlo
>
> Contra un colegio real las tres rutas dan **404** (allí no existe el fichero de rutas), que
> no es el 501 del docker. **No hay camino «sólo horario»**: la columna entra con
> `AFTER regla_nivelacion`, que llega en otra migración de la misma tanda. Pasos, primer
> colegio (`demo`, y por API: su login lo rompe un `if` del front) y qué se rompe si se hace
> mal, en la §11. **TRES afirmaciones de `DESPLIEGUE.md` sobre este módulo han envejecido** y
> **no se corrigen allí**: aquellas tablas se remiden el día del despliegue (§11.5). *Aquí
> decía «dos», y la tercera la abrió el commit siguiente al que escribió esa frase: el aviso
> **O** dice «24 rutas nuevas» y «las 3 de `horario/`», y con la cuarta son **25 y 4**. Es
> la que NO se arregla sola al remedir —remedir contesta si siguen a 501, no recuenta un 24—
> y es la única de las tres que va **hacia fuera**: ese aviso está POR AVISAR, y nombrar tres
> rutas cuando hay cuatro deja la cuarta sin avisar sin que se note el hueco.*
>
> ### Las otras dos que preguntó `myvc_horarios`
>
> - **`acepto_perder` no se ha movido desde `4f66e48`** en lo que es: sigue siendo el número
>   y sigue costando dos viajes. Lo que cambió fue el **mensaje** (`0faf099`): mandaba a
>   «releer el listado» a buscar una cifra fresca que `getVersiones` **no da**.
> - **La forma de `GET horario/versiones` sigue siendo la de `e25b643`.** Cero commits tocan
>   `HorarioController` ni `routes/api/horario.php` en `e25b643..HEAD`.

**3 sep 2026 — EL CÍRCULO DEL HORARIO, CERRADO DE PUNTA A PUNTA CON DATOS REALES** ·
cero ficheros tocados: es una **medición**, no un cambio · **566 rutas** · lo condujo
`myvc-horarios-f3` en el docker, con permiso de Joseth; la última lectura la corrió esta
sesión, también con su permiso

> **La frase con la que empezó este módulo era ésta**: *«"Clases de hoy" devuelve una lista
> vacía a todos los docentes todos los días, y nadie lo ha reportado porque un `[]` se
> parece a "hoy no tengo clase"».* **Ya no la devuelve, y está demostrado por el camino
> largo y no por un test.**
>
> | paso | qué se hizo | evidencia |
> |---|---|---|
> | **bajar** | el carril `datos` del otro repo, por arnés y por pantalla | 345 lecciones, piezas derivadas |
> | **subir** | `/subir` conducida en Chrome headless contra este docker | versión 5, luego la 6 |
> | **publicar** | `putOficial` | `years[8].horario_version_id` **4 → 6** |
> | **y que se vea** | `GET api/ChangesAsked/to-me` con token de un docente real | `horario_hoy` **5**, `horario_manana` **2** |
>
> ### EL CONTROL ES LO QUE LO HACE VALER, NO LAS CIFRAS
>
> Las 5 y las 2 **coinciden con las columnas `jueves` y `viernes`** de ese docente en la
> base —tiene 13 asignaturas y recibe 5—, **con `sabado` y `domingo` en cero**. Sin ese
> control, **un horario corrido un día habría dado exactamente lo mismo**: 5 y 2, creíbles,
> y mal. Es el fallo que el convenio de la §5.2.5 existe para impedir y el que no da ningún
> error.
>
> ### LAS CUATRO COSAS QUE ESTE CÍRCULO **NO** CUBRE
>
> Se escriben para que nadie lo lea más ancho de lo que es:
>
> 1. **No es uno de los dieciséis.** Es el docker. El módulo está desplegado en **cero**
>    colegios — `routes/api/horario.php` no existía en `9474b50`, la base desplegada.
> 2. **Al colegio del docker le faltan siete datos** que el escritorio sí maneja.
> 3. **No se baja el horario**: esa ruta **no existe** (§9.bis, decidida a medias y sin
>    escribir).
> 4. **Todo se condujo por Chrome**, no por el programa instalado.
>
> ### Y EL FALLO DEL SÁBADO SIGUE SIN VERSE CON DATOS REALES
>
> Hoy era jueves. El `% 7` que entró con el lote C **sólo se ha visto en un test que congela
> el reloj**: está bien que sea así, pero **no es lo mismo que haberlo visto**. El día que
> alguien mire un sábado, «mañana» tiene que salir vacío y el domingo lleno — con los dos
> lados, porque un vacío solo es indistinguible de un endpoint roto.

**Anterior: 3 sep 2026, madrugada — DOS COSAS QUE ENCONTRÓ EL CLIENTE MIDIENDO CONTRA NUESTRO DOCKER,
Y UNA DE ELLAS ERA UN ERROR MÍO** · `HorarioController`, `HorarioSubidaTest` y
`HorarioAceptoPerderTest` · **566 rutas, sin moverse** · suite entera
**`Tests: 1930 passed (17355 assertions)`**, cero rojos, cero deadlocks · las midió
`myvc-horarios-83` **sin escribir nada** en el docker compartido

> ### 1. «RELEER EL LISTADO» NO EXISTE, Y EL MENSAJE MANDABA ALLÍ
>
> El 422 de `acepto-perder-no-coincide` decía *«vuelve a leer el listado y confirma con la
> cifra que salga»*. Lo escribí yo anoche. **`getVersiones` no devuelve la deriva** — su
> `comprobaciones` es el veredicto guardado **el día de la subida**, no una cuenta de hoy—,
> así que **la única lectura fresca es el propio 422**. Se descubrió porque `-83` fue a
> escribir esa relectura y no encontró de dónde.
>
> Mandar a una pantalla a buscar un número que allí no está **es peor que no decir nada**:
> se busca, no se encuentra, y se acaba tecleando el que se recuerde. Corregido, con su
> aserción — que además me cazó a mí en el primer intento, porque la reescritura seguía
> usando la palabra «listado» dentro de una negación.
>
> **Y el reencuadre es a mejor**: la garantía de `acepto_perder` no es «el número vino de
> otro sitio» —no hay otro sitio— sino que **hay una persona en medio cada vez**, porque no
> se puede saber la cifra sin provocar el 422 que la enseña. La redacción del mensaje **es
> el mecanismo**, no un adorno alrededor. Y estrecha el agujero conocido: remandar el número
> del error exige **provocar un 422 por cada intento**, que es un argumento en contra del
> testigo de un solo uso que no se tenía al plantearlo.
>
> ### 2. `motivo` NO ESTABA GARANTIZADO EN TODOS LOS 422
>
> Los seis rechazos de dominio de la familia lo traen; el de `Request::validate` **no**
> —sale con `errors` y un `message` de `validation.required (and 6 more errors)`—. Una
> pantalla que dé `motivo` por seguro se rompe **justo en el caso más tonto**, el del cuerpo
> mal formado, y es el único 422 de la familia que no escribe una línea nuestra: por eso se
> escapaba.
>
> Cerrado **sólo en `horario/`** (decisión de Joseth): la familia es de tres rutas y ningún
> cliente suyo está desplegado, así que cuesta un `try`/`catch`; hacerlo global movería la
> respuesta de muchas rutas vivas para un contrato que pidió un cliente. **`errors` se
> conserva**, que es lo que lo hace aditivo. Test **visto rojo** quitando el `catch`.
>
> ### 3. Y UNA TABLA QUE FALTABA: EL AÑO SALE DE TRES SITIOS DISTINTOS
>
> `POST` lo saca del **cuerpo** (`:196`), `GET` del **token** (`:826`), `PUT` de la **fila**
> (`:981`). Cada uno con su razón, y **no se unifican**. Pero juntos: se sube una versión
> del año 5, el listado enseña las del 8, la recién subida no aparece —se lee como que la
> subida falló— y **lo que se marque oficial se publica en el año del token**, con el
> servidor aceptándolo porque para él es coherente. Ni 4xx ni nada rojo en ninguno de los
> dos lados. Escrito en la §7.1.bis del [23](23-horarios.md), que es lo que faltaba: hasta
> hoy la respuesta exigía leer tres controladores.
>
> El cliente ya lo cerró de su lado sin pedirnos nada, comparando el `year_id` del envoltorio
> de `getVersiones` con el del proyecto y **bloqueando publicar** mientras no cuadren —avisar
> sin bloquear no valía—. Segundo uso que le sale a un campo que este contrato estuvo a punto
> de dejar como un array pelado.

**Anterior: 2 sep 2026, noche — LA ENTREGA 0 FUSIONADA, Y EL DISEÑO DE LA PLANTILLA REPLANTEADO
CON CINCO DECISIONES DE JOSETH** · rama `feat/plantilla-del-anio-nuevo` (`7952d49`), **fusionada en `main`**
> por `8myvc-7d`; la cifra de abajo es la que midió `f0` sobre SU árbol y se queda como
> se midió — la del resultado de la fusión va al final de esta entrada · [`28-competencias-e-indicadores.md`](28-competencias-e-indicadores.md) (nuevo) ·
**`Tests: 1927 passed (17316 assertions)`**, cero rojos, cero saltados, cero deadlocks —
1925 + 2, **exacto** · `pint:test` PASS (364) · larastan nivel 7 `[OK]` (582) ·
**el de rutas no se mueve: 566** · sesión `f0`

> ### La Entrega 0 — `YearsController:250`
>
> Copiaba `unidades_por_defecto` al crear un año y **no** `subunidades_por_defecto`. Como las
> unidades copiadas nacen con ids nuevos, las subunidades se quedaban colgadas del año viejo y
> la plantilla del año nuevo amanecía con **contenedores sin casillas**: un 200, ningún error
> en el log, y una pantalla con pinta de configurada.
>
> **Los dos tests se vieron rojos antes que verdes, y hacía falta**: con un año origen **sin**
> plantilla —que es como está el seed y como está la base de desarrollo, medido: nueve años y
> cero filas en las dos tablas— pasan con el arreglo y sin él. El segundo test cuenta **bajo
> qué unidad** cae cada subunidad: `lastInsertId()` leído fuera del bucle las mete todas bajo
> la misma y deja el reparto en 100/0 **con el mismo número de filas**, así que el primero
> pasaría igual.
>
> **NO REPARA HACIA ATRÁS**, y está escrito dentro del propio bloque arreglado y no sólo aquí:
> un año copiado mal antes de esto sigue mal. **Cuántos años y cuántos colegios no se sabe** —
> se mide con la consulta de la §1.bis del 28, en los diecisiete del servidor. Es lo único de
> la Entrega 0 que sigue abierto, y ningún test puede contestarlo.
>
> **Y el centinela que falta.** `CentinelaDeLasColumnasDelAnioNuevoTest` no podía cazar esto:
> vigila **columnas** de `years`, y esto es una **tabla hija**. Un censo de tablas con
> `year_id` tampoco —`subunidades_por_defecto` no tiene `year_id`, cuelga de
> `unidades_por_defecto`—. El que cerraría la puerta es el de **las tablas que se copian al
> crear un año**. No está escrito.
>
> ### Las cuatro decisiones de Joseth, y una borra trabajo
>
> **La plantilla es POR AÑO**, los dos niveles — y eso **retira la columna `numero_periodo`
> que el plan proponía, y con ella la migración entera de la Entrega 1**: el alcance por año
> ya estaba en el esquema. **El boletín independiente se adapta** (Entrega 4: un tercer
> `origen: "plantilla"` en `copiar`, siembra al marcar y `competencias.alumno_id` — **cero
> rutas nuevas**). **El promedio será opcional** (Entrega 5), y ahí está el riesgo de todo
> esto: **la fórmula `nota × porcentaje / 100` está en 18 sitios de 9 ficheros**, contados, así
> que «un interruptor» es un `if` dieciocho veces, y el primero que falte hará que **el boletín
> enseñe un número y la definitiva guarde otro**, los dos creíbles y nadie los compara. Por eso
> la fase 0 de esa entrega es un **refactor que no cambia ningún resultado**; es lo que espera
> aprobación.
>
> ### La quinta decisión, la de esta sesión: **el docente NO cambia lo que sembró el colegio**
>
> *«Sí, por defecto el docente NO puede cambiar las unidades/subunidades que se crearon
> basando en las "por defecto"»*. Eso **sube el candado a la Entrega 1** — el 28 lo tenía
> como una entrega aparte (1.b) que «mueve una instantánea y los tres clientes»— y **la
> mitad de ese precio era falsa**, medido antes de escribirlo:
>
> - **La marca ya existe y ya está puesta**: `unidades.por_defecto` y
>   `subunidades.por_defecto`. De los **tres** `INSERT` que crean filas en esas tablas en
>   los 235 ficheros de `app/`, **sólo el sembrador** las pone a 1 (`UnidadesController:158`
>   y `:167`); las del docente y las del boletín independiente nacen en 0. O sea que
>   `por_defecto = 1` **ya significa «esto lo sembró la plantilla»**, hacia atrás y en los
>   dieciséis colegios, sin migración ni backfill.
> - **Y ya viaja**: está nombrada en los `SELECT` de cuatro controladores, y `app2` hasta la
>   declara en sus tipos sin que ningún `if` la mire. **La instantánea no se mueve.**
> - **Lo que sí cuesta**: nueve rutas vivas pasan a contestar **403** — los dos `update`, los
>   dos `destroy`, los dos `forcedelete` y las tres de orden. Sólo los `update` dejaría el
>   candado decorativo: borrar y volver a crear es editarlo en dos pasos, y la fila nueva
>   nace con `por_defecto = 0`, libre para siempre.
>
> **Las cuatro preguntas que abría, contestadas la misma noche**: se candan **los cuatro
> campos** (`definicion`, `porcentaje`, `orden`, `nota_default`), **tampoco se puede
> borrar**, el candado es **binario** —sin excepción fila a fila— y queda **exento quien
> tenga `can_edit_plantilla_notas`**. Lo binario es lo que más ahorra: **retira las cuatro
> columnas `can_change_*`**, así que la Entrega 1, que ya se había quedado sin
> `numero_periodo`, **no toca el esquema salvo para dar de alta un permiso**.
>
> **Las dos trampas que hay que tener delante al implementarlo**, las dos medidas sobre los
> clientes y las dos capaces de romper producción sin salir en un test:
>
> 1. **El candado compara VALORES, no la presencia del campo.** Los clientes mandan el
>    objeto entero en cada guardado y lo llevan escrito en su propio código
>    (`myvc_flutter/lib/Http/UnidadesApi.dart`: *«`nota_default` va siempre, aunque no se
>    haya tocado»*). Mirando la presencia, **todos** los guardados darían 403.
> 2. **`Unidad::arreglarOrden` queda exenta.** Reescribe `orden` de todas las unidades y
>    subunidades **en cada `GET unidades/de-asignatura-periodo`**: con `orden` candado y sin
>    excepción, **abrir la planilla por la mañana sería un 403 en una lectura**.
>
> **Población, con denominador**: en la base `simonbolivar` del contenedor hay **17.080
> unidades vivas y 34.439 subunidades, las 51.519 con `por_defecto = 0`** — ninguna sembrada
> nunca. Ahí el candado no cierra nada el día uno. **De los otros quince no se sabe**, y el
> censo de `por_defecto = 1` se cuenta con el bucle **antes** de desplegar: es el número de
> filas que dejan de poder tocarse mañana. La consulta está en la §5.1.e del 28.
>
> **Y hay que avisar al front**, que ya tiene su `TAREAS-PLANTILLA-Y-COMPETENCIAS.md` escrito
> con el modelo viejo: su tabla dice que las `can_change_*` se editan por fila en la pantalla
> de la plantilla, y con el candado binario **esa columna de la pantalla no existe**. Es de
> las de *«quién puede llamarla»*.
>
>
> ### `git checkout -- <fichero>` es el cuarto gesto que se lleva trabajo ajeno, y el peor
>
> Esta sesión liberó `ESTADO-ACTUAL.md` para que otra pudiera fusionar, con
> `git checkout -- docs/migracion/ESTADO-ACTUAL.md`, creyendo que revertía **su** entrada. Para
> entonces el fichero llevaba también la de otra sesión: **97 líneas destruidas**, sin aviso y
> sin sitio de donde sacarlas. Se recuperaron **por suerte y no por procedimiento** —había una
> copia entera del fichero de un segundo antes— y se vio porque el `--stat` del commit daba
> **103 insertions donde estaban medidas 54**, y ese número se fue a mirar.
>
> Junto a `git add -A` y al `git diff` pelado, es el cuarto caso de lo mismo: **todas las
> herramientas de git son del árbol y ninguna es de la sesión**. Pero éste no se arregla
> nombrando rutas —`checkout --` ya lleva la ruta— porque la ruta es del árbol y no de quien
> escribió, y a diferencia de los otros tres **destruye**. La regla: **antes de revertir un
> fichero compartido, leer el diff entero**, no basta con ver ` M` en `git status` y dar por
> hecho que lo de dentro es tuyo.

> ### LA CIFRA DEL RESULTADO DE LA FUSIÓN, que es otra que la de arriba
>
> **`Tests: 1929 passed (17346 assertions)`**, cero rojos, cero deadlocks, corrida sobre el
> merge por `8myvc-7d` — **1927 de `main` + los 2 de esta rama**. La de la cabecera (1927
> con 17 316 aserciones) es la que midió `f0` **sobre su árbol**, que salía de `ab23e2d`;
> `main` se había movido dos veces desde entonces, así que las dos son 1927 y **no son la
> misma medición**. Se dejan las dos: cada una dice qué árbol midió.


**Anterior: 2 sep 2026, noche — `horario_version_id` EN `getToMe`: LA APP LLEVA MESES DICIENDO «HOY NO
TIENES CLASES» A TODOS LOS DOCENTES** · `ChangeAskedController` y dos casos en
`HorarioOficialTest` · **el router no se mueve**: 566, es un campo de una respuesta ·
**decisión de Joseth**, levantado por `myvc-flutter-14`

> ### EL FALLO ESTÁ VIVO EN PRODUCCIÓN, Y ES EL DE ESTA NOCHE OTRA VEZ
>
> `horario_hoy` **viaja siempre**: nace en `[]` ([`ChangeAskedController:116`]) y se manda
> esté como esté. Así que desde un cliente **«este colegio no ha publicado su horario» y
> «hoy este docente no tiene clases» son el mismo mensaje**.
>
> Y no es teórico. `myvc-flutter-14` lo midió en `lib/`: la app tiene un `seSabe` escrito
> **a propósito** para separar las dos —con su docblock diciéndolo— y un array vacío **no
> es `null`**, así que `seSabe` vale `true` con cero clases. Consecuencia, hoy, en los
> dieciséis colegios: **el muro le dice a TODOS los docentes, todos los días, «Hoy no
> tienes clases»**, y `NotasScreen` pinta el chip «Hoy (ninguna)». Meses así, sin un solo
> reporte — **porque un vacío se parece a una respuesta legítima**. El código de la app
> estaba bien; la señal que le mandábamos, no.
>
> ### LO QUE SE HIZO Y LO QUE **NO** ARREGLA
>
> Se **añade** `horario_version_id` —`null` si el año no tiene versión oficial, su id si la
> tiene— y **no se omite `horario_hoy`**. Joseth eligió así sabiendo el coste, y **esto hay
> que leerlo entero antes de dar el asunto por cerrado**: omitir la clave habría arreglado
> el mensaje falso el día del despliegue; **añadir el puntero NO lo arregla** — sigue igual
> hasta que salga el build siguiente de la app usando la señal. A cambio no cambia la forma
> de nada que ya viaje, en una respuesta que leen cuatro clientes y con una app cuyas
> versiones viejas conviven meses.
>
> Consulta local a `getToMe` y **no** en `ContextoDeUsuario`: ese contexto lo monta el
> guard, o sea **544 de las 566 rutas**, y esto lo necesita una respuesta. Va en **las dos
> ramas** que devuelven `horario_hoy`, y el test las recorre por separado — una sola
> dejaría a la mitad de los usuarios sin el campo.
>
> ### Y UN HALLAZGO QUE SALIÓ DE ESCRIBIR EL TEST, QUE VALE MÁS QUE EL CAMPO
>
> **`login/credentials` MUEVE AL USUARIO AL AÑO ACTUAL.** El profesor que devuelve
> `usuarioDeTipo('Profesor')` está en el **año 4** antes de entrar y en el **8** después.
> Un año derivado de `users.periodo_id` **antes** de pedir el token es **un año distinto
> del que va a ver el endpoint**.
>
> Aquí salió como un rojo honesto —`null` donde había un id—, pero **la forma peligrosa es
> la otra**: con otro seed habría salido el id de **otro año** y habría **pasado en verde
> midiendo la respuesta equivocada**. Se arregla preguntándole a `auth/me` en qué año está
> el token, en vez de deducirlo. Es la trampa de `asignaturas` y la de `users` con una
> vuelta más: aquí el año no está en otra tabla — **cambia al autenticarse**.
>
> **Controles, los dos rojos**: sin el campo caen los dos casos (12 aserciones); con el
> campo pero **siempre `null`** caen igual pero llegando más lejos (30 aserciones), que es
> el segundo lado del test haciendo su trabajo — sin él, un campo que siempre valiera
> `null` habría pasado.

**Anterior: 2 sep 2026, noche — LOS `.env` NO SON UNIFORMES: EL CORREO DE UN COLEGIO, Y EL CENSO DE TODO LO QUE SE CONCLUYÓ «PARA LOS QUINCE» DESDE UN SOLO FICHERO** ·
rama `fix/los-env-no-son-uniformes` · **fusionada en `main`** · sólo documentación y
`.env.example`: **cero código, cero rutas, cero tests** — el de rutas sigue en **566** ·
[`29-los-env-no-son-uniformes.md`](29-los-env-no-son-uniformes.md) (nuevo),
`.env.example`, [`DESPLIEGUE-REFERENCIA.md`](../DESPLIEGUE-REFERENCIA.md) · encargo de
`8myvc-7d`, medido por esta sesión

> **El arreglo pequeño**: el remitente de `.env.example` era `josethmaster@lalvirtual.com` y
> **`lalvirtual.com` no está registrado** — NXDOMAIN, reproducido aquí con `dig` y no heredado
> de la sesión que lo trajo. Pasa a `admin@micolevirtual.com`, que tiene MX propio y cuyo SPF
> autoriza al servidor **por dos caminos independientes** (`+a` — la `A` del dominio *es* la IP
> del servidor — e `ip4:70.32.23.72`). **Y el buzón `admin@` existe: lo confirmó Joseth el
> 2 sep 2026 en el servidor**, que era lo último que faltaba —el SPF autoriza a *enviar* y no
> dice nada de quién *recibe los rebotes*—, así que **la parte del correo queda cerrada
> entera**. Lo mismo en `DESPLIEGUE-REFERENCIA.md:456`. **El `From:`
> crudo del `mail()` viejo de la 488 se deja intacto a propósito**: no es una instrucción, es
> de dónde salió el dominio muerto.
>
> **El hallazgo grande no es el correo.** El `.env` de producción de `cads-itagui` es **el
> primero que se lee entero desde que empezó la migración** —**1 de 17**— y trae el andamiaje
> de desarrollo sin tocar: `smtp` + `mailhog` + `MAIL_FROM_ADDRESS=null`. **Ese colegio no ha
> enviado un correo nunca.** O sea que **los `.env` no son copias de una plantilla**, y todo lo
> que este repositorio concluyó «para los quince» a partir de uno solo —o de ninguno— es una
> hipótesis con decisiones encima.
>
> **Y `null` no es «vacío», que era la duda razonable**: medido dentro del contenedor, Laravel
> convierte la **cadena** `null` en un null real **y el valor por defecto de `env()` no llega a
> aplicarse** (`MAIL_FROM_ADDRESS=null` → `NULL`, no `hello@example.com`). Por eso `Mail` aborta
> antes de intentarlo. **`correo:probar` sí lo detecta** —`if (! $remitente)` y `null` es
> falsy—: la herramienta funcionaba, **nadie la corrió allí**.
>
> ### LO QUE ESPERA UNA DECISIÓN DE JOSETH — Y LA PRIMERA ES LA GRAVE
>
> 1. **`APP_KEY`: la separación de los avisos de push descansa en que sean distintos, y nadie
>    los ha comparado.** Los temas de FCM son `HMAC(alumno_id, APP_KEY)`, y la decisión está
>    escrita *porque* «`key:generate` hace uno por instalación». **Esa premisa no se ha
>    medido**, y las dos cosas que sí se saben apuntan en contra: un colegio nuevo se crea
>    **copiando otro** y `.env` es **copia real**, no plantilla. El propio 05 escribió la letra
>    pequeña —«si dos colegios compartieran `APP_KEY`… sus temas colisionarían»— y **nombró el
>    camino sin recorrerlo**. Consecuencia si es cierto: **un acudiente recibe los avisos de un
>    menor de otro colegio, y publicar en un tema ajeno no da ningún error**. · **Es el único
>    momento gratis**: el push **no está vivo** (falta Firebase, el JSON y `FCM_PROYECTO`), así
>    que hoy no hay nadie expuesto y comprobarlo cuesta **un bucle de lectura que compara
>    hashes, no claves** — no saca ningún secreto del servidor. Está escrito en el §1 del 29.
> 2. **Correr el bucle de siete variables en los diecisiete** (§7 del 29). Sólo lectura. Es lo
>    único que separa «no se sabe» de «está bien», y **no lo puede correr una sesión**: hace
>    falta la sesión del servidor.
> 3. **Los `.env` de los dieciséis colegios no los toca ninguna sesión.** El del correo incluido.
>
> ### LO QUE ENSEÑÓ EL BARRIDO, Y ES OTRA VEZ EL DETECTOR
>
> **Este documento se corrige a sí mismo dentro (§5).** La primera lectura de
> `config/cors.php:43` fue que *ausente* daba `["*"]` y *presente y vacía* daba `[]` — o sea que
> copiar `.env.example` **bloquearía todos los orígenes**. **Falso**: la expresión termina en
> **`?: ["*"]`**, en la línea siguiente a la que se leyó. Ejecutado, las tres formas salen
> `["*"]`, `["*"]` y el dominio. *Se deja escrito porque el fallo —leer media expresión y
> deducir la consecuencia contraria— es exactamente el del documento: concluir sin medir.*
>
> **Y el método ya existía.** `DESPLIEGUE.md` corrió el 2 sep el barrido bien hecho para
> `APP_MOVIL_VERSION_MINIMA` sobre las diecisiete carpetas, y **ya destapó un `.env` distinto**
> (`demo`, presente y vacía). Se archivó con razón —en *esa* variable ausente y vacía son lo
> mismo— pero **el dato que llevaba dentro no se generalizó**: los `.env` divergen.
>
> **Población del barrido, porque sin ella un «0 encontrados» no se puede leer**: **107**
> ficheros `.md` y **61.826** líneas bajo `docs/`; **100** variables distintas que el código lee
> con `env()`, **50** documentadas en `.env.example`, **52 leídas y no documentadas** — entre
> ellas los cuatro `SESION_*_TTL` (la vida de los tokens) y `NOTIFICACIONES_SECRETO`, que es
> justo la salida del punto 1. Contado con `-c` **antes** de cortar: con `| head`, «no aparece»
> y «no miré» se leen igual.

**Anterior: 2 sep 2026, noche — `acepto_perder`: PUBLICAR YA NO PUEDE PERDER CLASES EN SILENCIO** ·
`HorarioController` y `tests/Contrato/HorarioAceptoPerderTest.php` (**12 casos, 103
aserciones**) · **el router no se mueve**: 566, es un campo del cuerpo · suite entera
**`Tests: 1925 passed (17303 assertions)`**, cero rojos, cero deadlocks — 1913 + 12,
exacto · `pint` PASS · larastan nivel 7 `[OK]` · contrato en la §7.2 del
[23](23-horarios.md) · **decisión de Joseth**, propuesta por el equipo de `myvc_horarios`

> **El agujero**: la §6 comprueba que cada asignación de la versión es del año **el día
> que se sube**, y publicar es otro día (decisión 17). Entre los dos alguien borra una
> asignatura y esas clases **desaparecen del horario al derivar**. Se contaban y salían
> en un campo de la respuesta de **éxito** — o sea que se avisaba después de haberlas
> perdido, y en el sitio donde no mira nadie.
>
> ### POR QUÉ UN NÚMERO Y NO UN `forzar: true`, QUE ES TODA LA DECISIÓN
>
> Un booleano no caza la deriva: dice «adelante pase lo que pase», así que el día que se
> pierdan treinta en vez de las dos que el coordinador vio en pantalla, pasa igual — y
> acaba puesto por costumbre, porque nunca estorba. Un número **tiene que coincidir con
> el que el servidor cuenta en ese instante**, así que sólo lo acierta quien acaba de
> mirar.
>
> **Y rebota también el número de MÁS**, incluido `acepto_perder: 1` cuando no se pierde
> nada. Parece rigidez gratuita y es la mitad que sostiene la otra: sin ella, una
> constante puesta en el cliente pasaría siempre que la deriva midiera eso.
>
> ### LOS CONTROLES, Y EL TERCERO ES EL QUE MÁS DICE
>
> - Puerta desactivada → **9 de 12 rojos**; los 3 verdes son los caminos felices.
> - Puerta convertida en `forzar: true` → **7 rojos**, exactamente los tres `no-coincide`
>   y los cuatro `no-es-un-numero`. **Sin este control**, «es un número y no una bandera»
>   sería una frase de un comentario.
> - **Control positivo del rollback**: moviendo la puerta a **después** de los dos
>   `UPDATE`, los doce siguen verdes. O sea que las escrituras **ocurrieron** y `abort()`
>   dentro de `DB::transaction` las deshizo — el «Nada se escribió» de los tres mensajes
>   está medido, no prometido.
>
> ### DOS COSAS QUE VINIERON DE FUERA Y MEJORARON EL DISEÑO
>
> **`myvc-horarios-5e` pidió que el 422 nombrara los DOS números** —el del cliente y el
> del servidor— y no sólo que no coinciden: su pantalla se lo tiene que explicar a un
> coordinador, y *«esperaba 32, mandaste 28»* se puede comprobar contra lo que hay en
> pantalla mientras que *«no coinciden»* sólo se puede creer.
>
> **Y levantaron un hueco en el `message` que yo había abierto.** Decía *«vuelve a llamar
> con `acepto_perder: 3`»*, que es correcto para un humano y **es una invitación a que el
> emisor reintente solo** con el número que vino en el error. Eso *funciona*, y
> reconstruye el `forzar: true` en dos viajes sin que nada se ponga rojo. Reescrito a
> *«enséñale esas 3 a quien publica y confirma con la cifra que él diga»*, **y atado por
> un test** que exige que el mensaje NO diga «vuelve a llamar»: la instrucción es el
> fallo, no el número.
>
> ### Y UN COMENTARIO QUE PASÓ A RAZONAR HACIA LA CONCLUSIÓN CONTRARIA
>
> El docblock de `poblacionDeLaDerivacion` decía que convertir la deriva en 422 *«sería
> impedir publicar por algo que pasó después de validar, y eso es decisión del colegio»*.
> El colegio decidió, así que ese párrafo pasó a **argumentar contra el código de al
> lado**. Reescrito y no borrado, con el porqué: un comentario así es peor que ninguno —
> se lee entero, es convincente, y manda a quien lo lea a «arreglar» la puerta que sí
> funciona.

**Anterior: 2 sep 2026, noche — EL LOTE C ESTÁ EN `main`: LAS TRES RUTAS DEL HORARIO DEJAN DE SER 501** ·
tres merges (`5dcc1ae`, `9f1f32d`, `19a1a73`) sobre `beaeeeb` · **NO EMPUJADO a `origin`**:
Joseth autorizó fusionar y correr la suite, y el push lo decide él con el número delante ·
**`Tests: 1913 passed (17200 assertions)`**, cero rojos, cero saltados, cero deadlocks —
1859 + 26 + 12 + 16, **exacto** · **566 rutas, sin moverse** · `pint:test` **PASS** (363) ·
larastan nivel 7 **`[OK] No errors`** · coordina esta sesión, relevo de `8myvc-af`

> **Lo que entra**: `getVersiones` (b3), `putOficial` con la derivación de las siete columnas de
> día y el fallo del sábado de `ChangeAskedController` (b2), y los 26 tests de `postVersiones`
> que nadie había escrito (b1). Con esto **«Clases de hoy» deja de estar vacía**, que es el
> problema que el módulo venía a resolver.
>
> **Ninguna de las tres tocó `routes/` ni `database/`** —comprobado con `git diff --name-only`,
> no supuesto—, que es justo la forma que tiene que tener un lote que sólo rellena métodos que
> ya existían a 501. Por eso el de rutas no se mueve y **566 se volvió a contar igualmente**.
>
> ### LO QUE ENSEÑÓ LA FUSIÓN, Y NO ES EL CONFLICTO
>
> Las tres ramas insertan en la **línea 8** de este fichero, así que el choque estaba previsto y
> se resuelve conservando **las tres** entradas, ordenadas por la hora de su commit (listado
> 20:08, oficial 20:03, subida 19:49) y **no por el lado del merge**, que es el orden que git
> ofrece y no significa nada.
>
> **Lo que no estaba previsto**: el bloque de HEAD del tercer merge terminaba **a media entrada**
> de `postVersiones` —la rama la había re-partido en líneas distintas, y por eso git conflictó
> ahí—, así que insertar la tercera entrada entre los dos lados **partió esa entrada en dos** y
> dejó dos líneas huérfanas arriba y el cuerpo cincuenta líneas más abajo. No da error, no sale
> en ningún rojo y el fichero se lee casi bien. Se cazó comprobando que **las únicas líneas que
> el diff BORRA son las dos del fragmento**, y que su texto vuelve entero más abajo — mirar el
> resultado, no el 0 de `git commit`.
>
> **Y una fecha dos días en el futuro.** La entrada de `listado` se fechaba **«4 sep 2026»** y su
> commit (`597da90`) es del **2 sep a las 20:08**. En el documento que una sesión nueva lee
> **primero**, y que se ordena por fecha, esa entrada se habría quedado arriba indefinidamente.
> Corregida a la fecha del commit. Es otra vez la misma familia: **algo que no da error y produce
> un resultado creíble**.
>
> ### DOS COSAS QUE QUEDAN ABIERTAS Y SON DE JOSETH
>
> 1. **`acepto_perder` NO está implementado en ninguna de las tres ramas** —comprobado con
>    `git grep` sobre `app/`, `tests/` y `docs/` en las tres: cero—. Fusionar deja en `main` un
>    `putOficial` **sin** esa comprobación, así que la deriva silenciosa de
>    `asignaciones_de_la_version_fuera_del_alcance` está viva en el código. **No está desplegado**,
>    o sea que no hay nadie expuesto; lo que cambia es que deja de ser una decisión sobre código
>    que no existe. Si se aprueba, **es contrato** y hay que avisar al front.
> 2. **El push a `origin/main`.**
>
> **Y la duración, que en este repo es una señal y no un dato de color**: 1050,63 s contra los
> **801,86 s** de la línea base medida esta misma noche sobre `beaeeeb`. **No es la suite fantasma**
> —`pgrep` limpio antes de arrancar, cero deadlocks y la cuenta exacta—: es que se corrió
> `phpstan --memory-limit=-1` **en paralelo dentro del mismo contenedor**. Queda escrito porque una
> subida del 31 % sin explicación es exactamente lo que la próxima sesión leerá como solape.

**Anterior: 2 sep 2026, noche — `GET horario/versiones` YA LISTA, Y LISTAR SIGUE SIN SER DESCARGAR** ·
rama `feat/horario-listado`, **fusionada en `main`** · lote B3 · `HorarioController` y
`tests/Contrato/HorarioListadoTest.php` (**12 casos, 67 aserciones**) · pint **PASS** ·
larastan nivel 7 **`[OK] No errors`** · **el router no se mueve**: la ruta ya existía a 501 ·
**suite entera: `Tests: 1869 passed (16733 assertions)`, `Duration: 805.67s`, cero rojos y cero
saltados** —1869 = 1859 + los míos, saliendo de `04ad296`; **no** los 1885 de `9c`, que llevan
sus 26— · coordinó `8myvc-af`

> ### EL LISTADO VA ENVUELTO, Y UN DATO REPETIDO SÓLO SE TOLERA SI ES UN INVARIANTE
>
> La respuesta pasó de un array pelado a **`{year_id, oficial_id, total, versiones}`**. Lo
> propuso `myvc-horarios-cc` comparando su versión de la ruta con ésta, y el argumento obliga
> porque **ya estaba en este método**: se usaba para justificar el `LEFT JOIN years` y no se
> aplicaba a la salida. Un `[]` no distingue «este año no tiene versiones» —que va a ser **lo
> normal** hasta que cada colegio suba el primero— de «algo salió mal». Es el `[]` de la §2.
>
> **`oficial_id` arriba y `es_oficial` por fila son el mismo hecho dos veces**, a propósito y
> con una condición: un test que lo vuelve **invariante**. Hoy no pueden discrepar —salen de la
> misma lectura en la misma petición—, pero ésa es la forma de la que sale un segundo escritor,
> el día que alguien pagine y `oficial_id` venga de otra consulta. Es `DefinitivasDeAsignatura`
> en miniatura. **Y era el único momento gratis**: la ruta contestaba 501, así que no hay ningún
> cliente al que le cambie la forma.
>
> **Los dos casos nuevos, vistos rojos antes de darlos por buenos**: volviendo al array pelado
> caen **11 de 12** —el único que aguanta es el control de que el blob existe, que no mira la
> respuesta—, y haciendo que `oficial_id` salga de otra fuente cae **exactamente uno**, el del
> invariante. *Un control que tumba un solo caso, y el que toca, es el que demuestra que ese
> caso mide lo que dice.*
>
> **Instantáneas: cero movidas, comprobado.** La ruta **no tiene instantánea de forma** —era
> 501—; las que nombran «horario» lo hacen por la columna `horario_version_id` (`muestreo-years*`)
> o porque listan URIs y guards (`rutas`, `guards-por-ruta`, `guard-por-familia`), que no cambian.

> **Lo que decide este lote no es el listado, es lo que NO sale.** La ruta lleva
> `auth.personal` y nada más, o sea cualquiera de los **53 docentes**, y esa apertura se
> concedió *porque* devuelve nombre, fecha, quién subió, si es la oficial y el veredicto — y
> **ni el `.myvch` ni las lecciones**. También: filtra por el año del token **sin mirar
> `y.actual`** (decisión 13), la oficial sale del **puntero** `years.horario_version_id` y el
> veredicto viaja **como se guardó**, no recalculado.
>
> ### QUÉ PROTEGE QUÉ: MEDIDO MUTANDO EL CONTROLADOR, Y MI PRIMERA VERSIÓN ERA FALSA
>
> El docblock del test decía que se pondría rojo si alguien cambiaba el `SELECT` a `hv.*`.
> **Se probó y no**: el `array_map` nombra sus claves, así que el blob no sale y el verde es
> **la respuesta correcta**, no un test flojo. Mutado al revés —devolver `$filas` crudas— sí
> hay rojo, pero **por la forma y no por el blob**, porque el `SELECT` nombra sus columnas.
> **Sólo filtra con las dos a la vez**, y ahí el test canta. Son **dos defensas
> independientes y cada una basta sola**; el test es lo único que queda el día que caigan
> las dos. *Un control negativo puede ponerse rojo por el motivo equivocado y parecer que
> funciona.*
>
> ### Y UN AYUDANTE DE TESTS QUE NO PUEDE HACER LO QUE DICE SU NOMBRE
>
> **`CasoDeContrato::tokenDelPersonalLlanoDe($yearId)` no devuelve un token de ese año si el
> año no es el actual.** Elige bien al usuario, pero el token se saca entrando por
> `login/credentials`, y **`Login::entrar()` mueve al usuario al periodo del año actual**
> (`app/Services/Login.php:188`). Pedido el año **7**, `$user->year_id` sale **8** — medido,
> no deducido.
>
> **Hoy no hay ningún test mal por esto**: los seis llamantes le pasan `$grupo->year_id` de
> grupos del año actual, así que aciertan por donde no falla. Es una mina, no un fallo
> vivo — y la mina es justo la que su propio docblock avisa: *«un sujeto de otro año
> devuelve la lista vacía en 200 y el test pasa sin haber calculado nada»*. **El primero que
> le pida un año pasado se lo come**, y fui yo.
>
> **No lo arreglo**: es un ayudante compartido y su arreglo es una decisión (¿el ayudante
> coloca al usuario, o se declara que sólo sirve para el año actual?). El test de la
> decisión 13 construye el estado a mano **y explica por qué**. `af` propone que el ayudante
> entre y después llame a **`PUT years/useractive/{year_id}`**, que es la ruta que mueve de
> año de verdad — así el estado se produciría **como lo produce el producto** en vez de a
> mano, que es la regla de la casa.
>
> ### Y LA CORRECCIÓN QUE MÁS ENSEÑA DE LAS TRES: «esa ruta no existe» era MÍO Y FALSO
>
> Escribí aquí que **ninguna ruta mueve a un usuario de año**. La hay:
> `YearsController::putUseractive` escribe `users.periodo_id` —el año del usuario **no se
> guarda, se deriva** del periodo—. Lo levantó `af`, y su explicación de por qué se me
> escapó no era la buena: propuso que mi patrón buscaba una columna que no existe. **Lo
> medí: el patrón SÍ la encontraba, en las posiciones 18 y 19 de 19 coincidencias** — y yo
> había cortado la salida con `| head`, que enseña diez. *Leí el corte como si fuera la
> población.*
>
> Es la regla de `tools/` —**ninguna imprime OK sin decir su población**— incumplida en la
> terminal, que es donde no la vigila nadie: un `| head` convierte «no aparece» en
> indistinguible de «no miré». Y encaja con lo de arriba: **un `grep` correcto y una lectura
> truncada dan un hallazgo falso con la misma cara que uno bueno.**
>
> ### Y una operativa que manda a mirar al sitio equivocado
>
> **`composer run stan` se corta a los 300 s y no es phpstan**: `composer.json` no declara
> `process-timeout`, así que el que corta es el lanzador. Directo
> —`./vendor/bin/phpstan analyse --memory-limit=-1 --no-progress`— termina y da `[OK]`. El
> síntoma es «stan falla» y el sitio donde está la causa es Composer.

**Anterior: 2 sep 2026, noche — LAS SIETE COLUMNAS DE DÍA YA SE DERIVAN: `putOficial` deja de ser 501** ·
rama `feat/horario-oficial`, **fusionada en `main`** · lote C2, lo repartió `8myvc-af` ·
**el router no se mueve**: la ruta ya existía y contestaba 501 · `Tests: 15 passed` en
`HorarioOficialTest`, 30 con las vecinas · pint PASS · larastan nivel 7 `[OK] No errors`

> **Marcar la oficial mueve el puntero del año Y deriva las siete columnas de `asignaturas`,
> en la misma transacción** (§7.1 del [23](23-horarios.md)). Con eso **«Clases de hoy» deja de
> estar vacía**, que es el problema que el módulo venía a resolver.
>
> **Y en el mismo commit va el fallo del sábado** (§2.1): `getToMe` pedía mañana como
> `$dia + 1`, el sábado eso da **7**, `asignaturas_dia()` no tiene caso 7 y «mañana» devolvía
> **todas** las asignaturas del docente. Era invisible con las columnas vacías y **se estrenaba
> justo con este lote**; arreglarlo después habría convertido el estreno del horario en un fallo
> nuevo. Son los dos sitios de `ChangeAskedController`, con `% 7`.
>
> Tres cosas que el lote decidió y conviene no re-litigar: el alcance es **un solo `UPDATE`** y
> no dos pasos —«a 0» y «a 1» son dos sitios donde el alcance puede dejar de ser el mismo—;
> es `EXISTS` y no una tabla derivada porque eso **no vale en MySQL 5.7** y de los quince
> colegios no está verificada la versión de ninguno; y la derivación es **inmune a la misa por
> construcción**, porque `EXISTS` contesta *sí o no* y no *cuántas*.
>
> **Queda abierto y es de Joseth**: `asignaciones_de_la_version_fuera_del_alcance`. La versión se
> valida el día que se **sube** y se publica otro día; entre medias alguien puede borrar una
> asignatura, y esas clases desaparecen del horario **en silencio**. Hoy se cuentan y salen en la
> respuesta; convertirlo en 422 sería impedir publicar por algo que pasó después de validar.

**Anterior: 2 sep 2026, noche — LA SUBIDA DEL HORARIO YA TIENE QUIEN LA VIGILE, Y TRES DE SUS REGLAS NO
LAS EJERCITA NINGÚN DATO REAL** · rama `feat/horario-tests-subida`, **fusionada en `main`** · un fichero
de `tests/` y **cero de `app/`** · **`Tests: 1885 passed (16989 assertions)`, `Duration: 808.75s`,
`exit=0`**, cero rojos, cero saltados y cero deadlocks — 1859 + 26, exacto · pint **PASS** (361) ·
larastan nivel 7 **`[OK] No errors`** · coordina `8myvc-af`

> **El hueco era éste**: `371062c` escribió 732 líneas —validación de forma, seis comprobaciones,
> veredicto, transacción y dieciocho rechazos— y **cero ficheros de test**. Lo único que tocaba la
> ruta era `HorarioAutorizacionTest`, que prueba **quién** puede llamarla y usa
> `assertNotSame(403, …)` a propósito para no fijar el 501 del andamio: **pasa igual contra un
> 422, un 500 o un 200**. El «probadas rojas» de aquel commit fue **a mano contra el docker** —
> cierto el día que se hizo, y no algo que vuelva a correr mañana.
>
> ### LO QUE EL ÚNICO PROYECTO REAL NO DISTINGUE
>
> Sobre `lleno.myvch`: 312 piezas, **0 de varios grupos, 0 sin asignación y cero choques en las
> dos lecturas posibles**. Subirlo **no separa** la implementación correcta de tres formas de
> romperla, así que las tres se fabrican — y se comprobaron rompiendo el controlador **una cosa
> cada vez**, restaurándolo después:
>
> - **la casilla es la unidad de choque, no la pieza** (un bloque de `duracion` 2 en la franja 3
>   ocupa la 3 **y la 4**): fijando el bucle a una casilla, **2 rojos**;
> - **Σ ≤ IH suma `duracion`, no cuenta filas** —sobre el fichero real serían 312 frente a 344,
>   o sea **32 horas de menos**—: sumando 1 por fila, **1 rojo**;
> - **el `pieza_id` como clave de la ocupación: NINGÚN rojo.**
>
> ### EL ROJO QUE NO LLEGÓ ES EL HALLAZGO, Y SE PUBLICA
>
> Sustituir la clave `[$pieza['pieza_id']]` por un `[]` **deja los 26 casos en verde**. No es que
> faltara un caso: **hoy no hay ninguna entrada por donde una pieza pueda ocupar dos veces la
> misma casilla** — `grupos` y `docentes` van por `array_unique` antes de llegar ahí, y una pieza
> repetida en el cuerpo se rechaza antes con su propio 422.
>
> **El controlador no se toca**: esa clave es defensa en profundidad correcta, y **vuelve a hacer
> falta el día que la ocupación se construya desde las filas de `horario_lecciones`** — que es de
> donde va a salir la derivación de las siete columnas de la §7, o sea el lote de al lado. Lo que
> sí se corrigió es **el docblock del propio test**, que ya afirmaba cubrirlo: sin la comprobación
> en rojo, el fichero se quedaba diciendo que prueba algo que no prueba, **con el verde encima**.
>
> ### Y EL GRUPO ÚNICO DEL SEED HACÍA QUE DOS REGLAS SE VALIDARAN ENTRE ELLAS
>
> Con un solo grupo por año —98 en el actual, 84 en el anterior—, dos piezas en la misma casilla
> chocan **por grupo y por docente a la vez**, así que romper una de las dos comprobaciones **la
> cazaba la otra**. Son **dos de las seis** de la §6, cada una con su 422. Se fabrica un segundo
> grupo con su asignatura dentro de la transacción del test y se separan: mismo docente en dos
> grupos → choque de docente **y `choques_de_grupo` vacío**; dos docentes en el mismo grupo → el
> contrario. **El aserto sobre el vacío es lo que demuestra que el escenario separa algo.**
> Comprobados en rojo anulando una sola comprobación cada vez: cada uno cae con la suya.
>
> **La asignatura sin IH tampoco está en el seed** (0 de 1219 en el colegio real): se vacía dentro
> del test y la transacción lo deshace. Sin eso, ese camino —el `SUM(...) = creditos` que con un
> `NULL` dentro no da falso sino que **se cae del resultado**— no lo ejercía nada.

**Anterior: 2 sep 2026, noche — `postVersiones` TIENE CUERPO, Y LAS TRES RUTAS SE EJERCITARON POR
PRIMERA VEZ** · sobre `09c23bc` · un fichero de `app/`, cero de `routes/` y cero de `database/` ·
contrato en [`23-horarios.md`](23-horarios.md) · lo escribió el carril `servidor` de
`myvc_horarios`, que es el único que vive en los dos repositorios

> **Primero lo que no había: evidencia de que ese servidor estuviera vivo para esto.** Las tres
> rutas existían desde `3524a22` y **nadie le había mandado nunca una petición a ninguna**.
> Medido contra el docker: `POST`, `GET` y `PUT` contestaron **501 y no 500**, con `auth/me` a
> **200** como control y `2026_09_04_100000_horario_versiones` en **`[16] Ran`**. Los dos datos
> hacían falta juntos, porque **«falta la implementación» y «faltan migraciones sin correr» se
> parecen muchísimo desde fuera** y se arreglan en sitios distintos.
>
> **Lo que entra**: el cuerpo de `postVersiones` —las seis comprobaciones de la §6, el veredicto
> de la opción B con su población, y la escritura en una transacción—. `getVersiones` y
> `putOficial` **siguen a 501 a propósito**: la forma de una versión en el listado la fija el
> `GET`, y escribirla desde el `POST` es cómo se acaba con dos formas de la misma cosa.
>
> **Corrido de punta a punta con el fichero real**, no con un doble: `lleno.myvch` (128 779
> bytes) → **201**, 312 lecciones, 312 `pieza_id` distintos, 290 pares pieza-docente, Σ duración
> 344, días 1–5 y **`years.horario_version_id` intacto en `NULL`** — *subir no es publicar*
> (decisión 17), demostrado y no supuesto. El veredicto guardado ocupa **1 621 bytes** y dice
> exactamente lo que la §6 predijo: **133 de 134 completas y una con 2 de 3** (EDUCACIÓN
> RELIGIOSA de Once). La regla dura `Σ = IH` habría rechazado el único dato real que existe.
>
> ### Las comprobaciones se probaron ROJAS, y dos de los verdes no valían
>
> **17 casos negativos contra el docker.** Los importantes: `anio` 2019 sobre `years.id` 8 →
> 422 `anio-no-coincide`; asignatura del año 1 en una versión del 8 → 422 nombrando la pieza y
> la intrusa; asignatura de la papelera → 422; día 7 → 422; docente que no es `profesores.id` →
> **422 en vez del 500** que daría la clave foránea; `pieza_id` repetido → **422 en vez del 500**
> que daría el índice único; `nombre_colegio` cambiado → **201**, que es lo que tiene que pasar
> porque es blando.
>
> **Y lo que hay que contar aunque salga verde:** el caso de `Σ > IH` se escribió inflando una
> duración a 40, contestó 422 y **estaba mal**: el `motivo` decía `choque`, no
> `suma-mayor-que-la-ih`. Un bloque de 40 casillas choca con medio horario, así que el detector
> medía bien el síntoma y **no estaba midiendo la causa**. Rehecho llevando la pieza al **sábado
> vacío**, donde no hay con qué chocar: 422 `suma-mayor-que-la-ih`, «MATEMÁTICAS de Tercero, 12
> de 4», sobre 134 asignaciones revisadas. El otro verde que no valía era el del bloque de dos
> casillas: **no se llegó a correr** porque el caso se *buscaba* en el fichero y no había par
> consecutivo del mismo grupo. Construido a mano en el sábado, **con su control**: las dos piezas
> separadas en (6,1) y (6,2) dan **201**, y con `duracion: 2` en la primera da 422 nombrando
> grupo 97, día 6, franja 2 y las dos piezas. Sin el control, el rojo no distingue «lo cazó» de
> «siempre dice rojo».
>
> ### Tres cosas que salieron de mirar el esquema y no el documento
>
> - **`proyecto` faltaba en el boceto de la §5.2** y la columna es `mediumText()` **sin
>   `nullable()`**. Salió de comparar el emisor de `myvc_horarios` con esta sección **campo a
>   campo**: 13 campos, **12 exactos**, y el que no viajaba sin que el contrato lo pidiera. No era
>   una discrepancia entre las dos mitades: **era que una no lo decía**, y esa forma no la caza
>   releer el lado que sí lo dice. §5.2 corregida.
> - **Dos rechazos que no son de la §6 y evitan un 500**: `horario_pieza_docente.profesor_id`
>   tiene clave foránea y `horario_lecciones` tiene único `(version_id, pieza_id, asignatura_id)`,
>   así que un docente inventado o un `pieza_id` repetido reventaban el `INSERT` con un error que
>   no dice a quién culpa.
> - **El veredicto va a una columna `text` (65 535 bytes)** y sus listas crecen con las
>   asignaciones del año. Se acotan **los nombres a cincuenta y nunca la cuenta**, que es lo que
>   la §6 exige: la población va siempre.
>
> **Lo siguiente**: `getVersiones` y `putOficial`. El segundo lleva dentro las dos trampas ya
> medidas —el alcance de la derivación es **el año entero** y «las asignaciones de este año» es
> un **JOIN**, no un `WHERE`— y **el fallo del sábado de la §2.1 va en el mismo lote**: es
> invisible mientras las siete columnas estén vacías y se estrena el día que se rellenen.
>
> **Verde**: `phpstan` nivel 7 limpio sobre el fichero y `pint --test` en PASS.

**Anterior: 2 sep 2026, noche — EL ⛔ REMEDIDO DESPUÉS DE LA FUSIÓN: 566 RUTAS Y SIETE
MIGRACIONES, CONTADAS** · rama `docs/tanda-tras-la-fusion`, **sin fusionar** · sólo documentación: **cero
ficheros de `app/`, `routes/` y `database/`** · sobre `aebf4ed` · coordinó `8myvc-af`

> **Salió lo previsto y por eso se contó.** `af` y yo dábamos por hecho 566 y siete; el rango se
> volvió a medir entero con `route:list --json` y `git diff --name-only` desde `9474b50`, sin
> partir de esa cifra. **191 commits, 54 de `app/`, 7 de `routes/`, 0 de `config/`, dependencias
> y volcado.** Coincidir no es lo mismo que estar comprobado, y la única forma de saber que
> coincidía era contarlo.
>
> **Y la séptima migración no es lo que parecía.** `2026_09_04_100000_horario_versiones` es
> aditiva —tres `CREATE TABLE` y un `ADD COLUMN` nullable— pero **no rompe nada si falta, y está
> comprobado**: los tres métodos de `HorarioController` contestan **501**, ninguna consulta
> nombra las tres tablas y **nadie nombra `years.horario_version_id`**. Entra igual en el
> `migrate` y en la comprobación de diez segundos, porque ésa contesta *«¿está la tanda entera
> dentro?»* y no *«¿qué se rompe?»* — un colegio con seis de siete es uno del que nadie sabe en
> qué estado está. Lo que sí sigue siendo cierto es que **no se puede desplegar suelta**: su
> columna nace `after('regla_nivelacion')`.
>
> > **⚠ Esto dejó de ser cierto unas horas después, y se marca en vez de reescribirse.** El
> > *«no rompe nada si falta»* se apoyaba en que **ninguna consulta nombraba las tres tablas**,
> > y eso era verdad mientras los tres métodos contestaban 501. Con el cuerpo de
> > `postVersiones` escrito (entrada de arriba), `horario_versiones`, `horario_lecciones` y
> > `horario_pieza_docente` **sí se nombran**, así que en un colegio sin migrar
> > `POST horario/versiones` pasa de 501 a **500**. La medición de aquel día era correcta; lo
> > que cambió es el código que medía. Es justo la forma que tiene una comprobación de
> > despliegue de envejecer sin ponerse roja.
>
> El rollback de la tanda pasa de `--step=6` a **`--step=7`**, y `--step=1` ahora revierte
> `horario_versiones` en vez de `rubricas`. El aviso **O** al front sube de 21 a **24 rutas
> nuevas**.
>
> **Y una que no es de despliegue y va al [15](15-la-noche-en-paralelo.md):** la regla «una suite
> a la vez» se lee como «que no la lance nadie más» y le faltaba la otra mitad — **matar el
> `docker exec` desde fuera no mata el `php` de dentro**, así que una corrida cortada por el tope
> de tiempo sigue viva y se solapa con la siguiente. Le dio a `af` **30 rojos, 88 deadlocks y
> `exit=0`** al fusionar, y **lo delató la duración —1247 s contra ~670— antes que los rojos**.
> El `pgrep` que lo comprueba lleva corchetes porque sin ellos **se encuentra a sí mismo**:
> probado en las dos direcciones antes de escribirlo.

**Anterior: 2 sep 2026, noche — LA TANDA PENDIENTE ESTABA MAL MEDIDA, Y EN LA DIRECCIÓN PELIGROSA** ·
rama `docs/tanda-pendiente-remedida`, **fusionada en `main`** · sólo documentación: **cero ficheros de
`app/`, `routes/` ni `database/`** · coordinó `8myvc-af`

> **La base del rango era `eb95cbc` y la buena es `9474b50`**, que se desplegó el 31 ago. Con la
> base mala, `ESTADO-ACTUAL.md` prometía «542 rutas sin mover, 27 ficheros de `app/`, **UNA**
> migración» donde hay **563 rutas, 52 ficheros y SEIS migraciones** — y de ese párrafo sale la
> respuesta a *«¿este despliegue lleva `migrate --force`?»*. Remedido con el comando, no a ojo:
> `9474b50..347f137` son **175 commits**, 52 de `app/`, 6 de `routes/`, **0** de `config/`,
> dependencias y volcado.
>
> **El aviso que decía «sin migrar, los tres boletines contestan 500» se quedaba corto: sin migrar
> no se puede ni entrar.** `years.regla_nivelacion` la nombra `ContextoDeUsuario::construir()` en
> las cuatro ramas, y esa consulta la dispara **el guard** (`ExigirAutenticacion:39` →
> `User::fromToken()`), no un controlador: **544 de las 562 rutas de `api/` caen antes de llegar a
> su método**, y `POST login` y `POST auth/login` con ellas, porque montan el contexto ellos mismos.
> Reproducido contra una base sin migrar: `Unknown column 'y.regla_nivelacion' in 'field list'`.
>
> **Y una que no estaba mirada: cinco de las seis son aditivas en `up()`, la sexta no.**
> `2026_08_31_100000` hace `dropColumn('boletin_independiente')` sobre `matriculas`, y el código
> que está **hoy** en los quince nombra esa columna en cinco consultas vivas de
> `BoletinIndependiente.php`. Eso rompe el «Paso 4. Volver atrás» de `DESPLIEGUE.md`, que decía
> que las migraciones se quedan puestas porque son aditivas: **cierto en todas las tandas
> anteriores y falso en ésta**. Corregido allí, con el `rollback` que sí sirve —`--step=6`, porque
> la del `dropColumn` es la **primera** de las seis y `--step=1` revierte `rubricas`— y con lo que
> ese rollback se lleva por delante.
>
> **El estado peor no es «sin migrar», es «migrado a medias»**, y apareció solo: la base de tests
> de esta sesión estaba parada en `2026_08_31_100000`, con `matriculas.boletin_independiente` ya
> retirada y `years.regla_nivelacion` sin llegar. **No funciona ni el código viejo ni el nuevo.**
> Por eso «si el `migrate` de un colegio falla, ese colegio se arregla antes de tocar el
> siguiente» deja de ser una precaución y pasa a tener una forma concreta.
>
> **Nada de esto se ha desplegado**: desplegar a los quince lo autoriza Joseth. Lo que cambia es
> que el documento dice la verdad el día que dé la orden.

> **Y esta entrada y la de abajo son de la misma tanda, por eso van juntas.** El suelo del
> horario mete **tres rutas y una migración** en el mismo rango sin desplegar que acaba de
> remedirse aquí, así que **las cifras de este bloque y las del ⛔ de `DESPLIEGUE.md` son las de
> antes de fusionarlo**: dejan de valer el día que `feat/horario-suelo` entre en `main`. **Ese
> día se remide el rango entero con `route:list` y con `git diff`, no se le suma tres a 563 ni
> uno a seis** — es la regla que este mismo bloque vino a escribir, y aquí no se predice el
> resultado a propósito: un número escrito antes de que sea verdad es lo único que este
> repositorio no sabe mantener. Lo único que sí se puede afirmar hoy, porque está leído en la
> migración y no calculado: la de `horario_versiones` es **aditiva** —tres `CREATE TABLE` y un
> `ADD COLUMN` nullable— y **no se puede desplegar suelta**, porque su columna nace
> `after('regla_nivelacion')` y exige dentro la migración de nivelación de esta misma tanda.

**Anterior: 2 sep 2026, noche — EL SUELO DEL HORARIO ESTÁ ESCRITO: 566 RUTAS, Y LA COLUMNA NUEVA
DE `years` SE REPARTIÓ A TRES RESPUESTAS Y A NADA MÁS** · rama `feat/horario-suelo`
(lote A del reparto en tres), **fusionada en `main`** · **566 rutas**, contadas con
`route:list --json` sobre ese árbol — 563 + 3, y contarlo es la única forma de saber que
coincidía · pint **PASS** (360 ficheros) · larastan nivel 7 **`[OK] No errors`** ·
contrato en [`23-horarios.md`](23-horarios.md), coordina `8myvc-af`

> **Lo que entra**: las tres tablas de la §5.1 —`horario_versiones`,
> `horario_lecciones`, `horario_pieza_docente`— más **`years.horario_version_id`**,
> `Role::isCoordAcademico()`, `Autoriza::puedePublicarHorario()` (método **nuevo**: no
> es `esSuperusuario`, que deja fuera al coordinador, ni `esAdministrativo`, que mete al
> Secretario que Joseth no nombró), `routes/api/horario.php` con las tres rutas de la
> §5.3 y un `HorarioController` con **los tres métodos a 501** y su autorización ya
> puesta delante. **El cuerpo de los tres no está escrito.**
>
> ### LA COLUMNA NUEVA MOVIÓ SEIS SNAPSHOTS, Y ESO SE MIDIÓ ANTES DE REGENERAR NINGUNO
>
> Se corrieron `RutasTest`, `AutorizacionTest`, `MuestreoDeLecturasTest` y el centinela
> del año nuevo **con los snapshots viejos delante**: `6 failed, 105 passed`. Los seis
> previstos y ni uno más — `rutas`, `guards-por-ruta`, `guard-por-familia` y las **tres**
> de `years`—, **13 líneas de diff en total**. Regenerar primero y mirar después habría
> dado el mismo fichero sin la medición.
>
> **`familias-que-nunca-entran-en-el-candado.json` NO se movió, y era la comprobación
> que más valía**: con `auth.personal` en las tres, la familia `horario` entra como
> **3 de 3** y **entra** en el candado en vez de salirse. Un renglón «0 de 1» ahí es la
> forma exacta que tendría un agujero nuevo, y no apareció.
>
> **Y el `auth.personal` no está por el contador.** Está porque cierra la puerta a
> alumnos y acudientes antes de tocar el controlador, y porque es la forma de la
> referencia que dio Joseth (`myimages/cambiarlogocolegio`: guard en la ruta **más**
> `Autoriza` dentro). El «3 de 3» es una consecuencia — si se lee al revés, el próximo
> lote quita el guard el día que el contador le estorbe.
>
> ### `years.horario_version_id` MOVIÓ UN CUARTO TEST QUE NO ESTABA EN LA LISTA
>
> `CentinelaDeLasColumnasDelAnioNuevoTest` lee `SHOW COLUMNS FROM years` **de la base
> viva** y exige que **cada** columna esté decidida: copiada por
> `YearsController::postStore`, o excusada en `NACEN_VACIAS` **con su motivo**. Una
> columna nueva de `years` lo pone rojo el mismo día, y lo que fuerza es una decisión de
> dominio: **el puntero no se copia**. Copiarlo dejaría al año nuevo afirmando que su
> horario oficial es **una versión del año anterior**, y con la decisión 13 —publicar
> vale en cualquier año— ése es justo el estado que el puntero en `years` existe para
> impedir. `firmantes_acta` era hasta hoy el único precedente de esa lista.
>
> ### `Year::actual()` — CONTESTADO CON LA SUITE ENTERA, NO RAZONANDO SOBRE LLAMANTES
>
> El 23 §5.1 avisaba de un **cuarto `SELECT *` sobre `years`** —`Year::actual()`, con
> `LoginController` entre sus tres llamantes— del que sólo se sabía que *«hoy no deja esa
> fila en ninguna instantánea»*, y avisaba también de que eso **no es que sea seguro: es
> que nadie ha mirado por ahí**. No se cerró leyendo llamantes, que es la forma en que
> ese mismo asunto ya produjo dos cuentas de más el 2 sep: se cerró corriendo la suite
> entera y mirando **qué snapshot se movía**. Fuera de los tres de `years`, ninguno.
>
> ### DOS COSAS QUE SALIERON MIDIENDO Y NO SON DE HORARIOS
>
> **1. En la base de tests falta el rol `Secretario`.** `tools/construir-bd-test.sh`
> corre `migrate` **y después** carga `test-seed.sql`, que hace `TRUNCATE TABLE roles`
> —igual que de `role_user`, `permissions` y `permission_role`—, así que lo que una
> migración de 2026 siembre ahí **se borra a continuación**. `Coord académico` sí está
> (es de 2018 y viene dentro del seed); `Secretario`, creado el 21 ago 2026 por
> migración, **no**. La consecuencia no es de este módulo: la rama `Role::isSecretario()`
> de **`Autoriza::esAdministrativo()`** —que leen otros seis sitios— **no la ejerce nadie
> en toda la suite**, porque `hasRole()` compara el nombre literal y un rol ausente
> devuelve `false` para todo el mundo. Un test que diga «un Secretario puede X» está
> demostrando «un superusuario puede X», que es menos. Queda **declarado con su número**
> en `HorarioAutorizacionTest`, a la manera del `count` de `phpstan.neon`; el seed no se
> tocó, que es decisión de otro.
>
> **2. `can_view_auditoria` no existe en la base de tests, por lo mismo.** En producción
> se le siembra a `Coord académico` desde el 25 ago, así que **dar ese rol reparte allí
> dos permisos y no uno** —publicar el horario y ver el rastro de auditoría ajeno—. Aquí
> el verde de un test **no demuestra** que vayan separados, y por eso el test que lo
> ejerce lleva el aviso pegado y una comprobación que fija el cero.
>
> ### LOS DOS SITIOS DONDE UN HORARIO SALE MAL SIN DAR NINGÚN ERROR, PEGADOS AL CÓDIGO
>
> Los dos van en comentario **junto a su columna**, no en un documento: quien vaya a
> tocar eso no va a leer el 23.
>
> - **`pieza_id` es `varchar(64)` y su unicidad es (`version_id`, `pieza_id`).** Medido
>   por el front sobre el proyecto real: 313 piezas, longitud 7 exactos, de la forma
>   `a<asignatura_id>-<índice>`, **0 de 313 sólo dígitos** — un `int` no aguanta ni la
>   primera subida. Los identificadores derivan de `asignaturas.id`, que es **estable
>   entre versiones**, así que dos versiones del mismo año contienen **las dos**
>   `a1196-0`: un único global sólo rompería **la segunda subida del año**, y pasaría
>   entero cualquier test que suba una sola vez. El índice escrito ya va con
>   `version_id` delante.
> - **`years.horario_version_id` va a volver por `PUT years/guardar-cambios`.** El front
>   viejo manda el objeto `year` entero tal como se lo dio `GET years/colegio`. Hoy es
>   inerte porque `putGuardarCambios` asigna **campo a campo** y esta columna no está en
>   su lista —comprobado: cero `Request::all()` y cero `fill()` en todo el controlador—.
>   **Simplificar ese método a asignación masiva abriría un camino sin permiso para
>   escribir la versión oficial**, y con el valor **caducado** que la página tenía al
>   cargarse: dos pestañas abiertas revertirían la oficial sin que nadie tocara el
>   horario. Se estrena el día que haya una oficial de verdad. Ese método **ya fue el
>   sitio de esta clase de fallo** y lleva la lección escrita encima.
>
> **Aviso de canal, ya cursado por `8myvc-af` a los cuatro clientes**: `GET years`,
> `GET years/colegio` y la de papelera empiezan a traer `horario_version_id`
> (`int unsigned NULL`, `null` en los nueve años). Ninguno rompe — y la población de esa
> afirmación es **lectura de los consumidores, no ejecución de los fronts**.
>
> **Lo siguiente — sin empezar**: el lote B, `postVersiones` con la revalidación de la opción B. Son
> **seis** comprobaciones y no tres —la quinta y la sexta, `anio` duro y `nombre_colegio`
> blando, las trajo Joseth el 2 sep por la tarde (§5.2.0)— y el veredicto **lleva su
> población dentro, sacada de esa corrida y no escrita a mano**.

> **Nota de orden (`8myvc-c`, al ordenar el bloque):** el hallazgo de arriba sobre
> `construir-bd-test.sh` —`migrate` y **después** `test-seed.sql`, que hace `TRUNCATE TABLE
> roles`— y el cambio que le entró a ese mismo script esta noche desde
> `docs/tanda-pendiente-remedida` **son la misma costura**: la comprobación de migraciones
> pendientes se planta justo entre esos dos pasos. No chocan —una mira roles, la otra
> migraciones— pero quien toque el script tiene que leer las dos.

**Anterior: 2 sep 2026, noche — EL PRE-VUELO DEL HORARIO YA ES UN SCRIPT** · rama
`feat/prevuelo-horario`, **fusionada en `main`** · `tools/prevuelo-del-horario.php`, el nivel 1 de la
[§9.2 del 23](23-horarios.md) · **el router no se mueve**: no hay ruta, es una herramienta ·
`--control` verde y dentro de `AutopruebasDeLasHerramientasTest` (12 pasan) · pint y
larastan nivel 7 **`[OK] No errors`** sobre este árbol · lo repartió `8myvc-af`

> **Reproduce las siete cifras del control de la §9.1 sobre `simonbolivar`, año 8**, y también
> el reparto que es el hallazgo: **Transición 7 de 7 sin docente y Jardín 3 de 7**, o sea que el
> horario de Transición no se puede colocar en absoluto. Con `--lecciones=6` —el supuesto de la
> v1— sale el tercer hallazgo, *«JOEL HERNÁNDEZ tiene 31 h y sólo caben 30»*: **el supuesto que
> costaba el proyecto es ejecutable en los dos sentidos**, y está fijado en el control.
>
> **Lo que contesta y todavía no se ha corrido: los otros catorce colegios.** El script está
> listo para el bucle (`--csv`, y `0` limpio / `1` sucio / `2` NO MEDIDO), pero correrlo colegio
> a colegio **lo decide Joseth**, porque es tocar las quince bases de producción.
>
> **Y en la misma rama, la cota alta del blob corregida: 231.135 bytes de cuerpo, no 185.997.**
> Los 185.997 eran **el cuerpo con la lista de piezas vacía** (`lecciones: []` en el arnés del
> front): el horario no estaba dentro. El factor es **× 1,795, no × 1,45**, y el **5,51 %** de los
> 4 MB del peor caso. **La decisión NO se mueve** —el blob va en la fila, sin comprimir—, pero la
> frase que decía que *«un colegio más grande sube por más filas, no por un factor peor»* **era
> falsa**: sube por las dos, porque las piezas escalan con las filas y el blob no. Lo encontró
> `myvc-front-8e` y **se reprodujo desde este árbol** sobre el mismo `lleno.myvch` — las cifras
> salen idénticas. **El arnés sigue sin arreglar en las dos copias**, así que quien lo corra hoy
> volverá a imprimir 185.997.
>
> Y trae cinco preguntas que en `simonbolivar` dan cero y de los otros catorce no se sabe nada:
> IH **nula** —que no se evapora, desaparece del `SUM`, y el total sale cuadrado habiendo mirado
> de menos—, IH **0**, docente **borrado** o **inexistente**, **materia en la papelera** (la
> decisión abierta 1 de la §10.2) y grupos **sin ninguna asignación**.

**Anterior: 2 sep 2026, noche — `main` LIMPIO, MEDIDO Y **SUBIDO A `origin`** · `d43d028`** ·
**`Tests: 1843 passed (16614 assertions)`, `Duration: 693.98s`, `exit=0`**, cero rojos y cero
saltados · pint **PASS** (357 ficheros) · larastan nivel 7 **`[OK] No errors`** · **563 rutas**
(contadas con `route:list --json` sobre este árbol) · **cero commits sin subir**, y la base de
desarrollo del docker **migrada** · coordinó `8myvc-5e`

> **Se fusionaron cuatro ramas sueltas** —`niv/integracion` (46 commits: nivelación, rúbricas y el
> par impreso), `fix/planilla-sin-profesor`, `fix/prematriculas-cant-faltantes` y
> `chore/vscode-python-envfile`—, se borraron las trece ramas ya fundidas y sus worktrees, y
> `.vscode/settings.json` dejó de estar sin seguimiento. **Queda un solo worktree y una sola rama
> viva**: `.worktrees/h`, con `feat/calendario`.
>
> ### LOS DOS ROJOS DE LA FUSIÓN NO ERAN UNA REGRESIÓN, Y ES EL HALLAZGO DE LA NOCHE
>
> `AsignaturaSinProfesorTest` fijaba que una asignatura sin docente da **404** con el mensaje
> correcto; `PlanillaSinProfesorTest` fijaba que **abre y da 200**, que es lo que decidió Joseth.
> **Las dos ramas arreglaron el mismo fallo por lados distintos y las dos estaban en VERDE en su
> propio árbol**: ninguna sesión podía verlo sin la otra delante. *Dos tests en verde pueden
> afirmar cosas opuestas mientras vivan en ramas separadas.*
>
> Se retiró el caso obsoleto —con su porqué, y **sin reescribirlo**, porque el comportamiento nuevo
> ya lo prueba mejor el test de la otra rama— y se **conservó** el del cuerpo del 404 cambiándole
> **el caso que lo dispara y no la comprobación**. El conflicto de `Asignatura.php` era de
> docblocks y también decía algo: uno afirmaba que el `LEFT` «espera a Joseth» y el otro lo traía
> ya decidido; se conservaron los dos y se corrigió la contradicción, más una consecuencia que
> ninguna rama podía ver sola —con el `LEFT`, una rama de `porQueNoSalio()` deja de ser
> alcanzable—.
>
> **Y larastan cazó lo que dejó ese arreglo**: al retirar los dos casos, su ayudante se quedó sin
> llamantes. Por eso `stan` se corre **antes** de subir y no después.
>
> ### UN INCIDENTE QUE ES LA REGLA DE DESPLIEGUE EN PEQUEÑO
>
> Al fusionar, el docker se quedó devolviendo **500 en toda ruta autenticada**: el código pasó a
> leer `years.regla_nivelacion` con la base sin migrar. **Lo detectó la sesión del front, no
> nosotros.** Las cuatro migraciones pendientes son puramente aditivas en `up()` —comprobado antes
> de correrlas— y ya están dentro. Es exactamente lo que la lista de despliegue dice de los quince
> colegios: **un `git pull` con el `migrate` sin correr deja el colegio caído.**

**Anterior: 2 sep 2026, tarde — UNA ASIGNATURA SIN DOCENTE YA ABRE SU PLANILLA** ·
rama `fix/planilla-sin-profesor`, **fusionada el 2 sep en `9437df5`** —este renglón decía «sin fusionar» y llevaba un día siendo falso— · **el router sigue en 550**: no hay ruta nueva,
es una línea de SQL · lo decidió Joseth y lo montó la sesión que relevó al backend

> **`Asignatura::detallada()` unía `profesores` por `INNER JOIN`, y `asignaturas.profesor_id` es
> NULLABLE.** Una materia sin docente asignado no devolvía ninguna fila, así que saltaba el
> `abort(404)` de ese mismo método **diciendo lo que no era**: «Esa asignatura no es de este año».
> Su planilla no abría.
>
> **Medido sobre desarrollo el 2 sep, y el reparto importa más que el total:** de **1219
> asignaturas vivas, 146 sin `profesor_id`** — **2** en 2019, **10 en el año actual (2025)** y
> **las 134 de 134 de 2026**. Cero apuntan a un profesor inexistente y cero a uno borrado, o sea
> que **no es corrupción: es cómo empieza un año**. Hoy el 404 lo pegan diez; **el día que 2026
> pase a ser el año actual lo pegarían todas**. Otra sesión llegó al mismo dato por su cuenta
> desde horarios («Transición no tiene docente en NINGUNA de sus siete»), y
> `BoletinIndependienteController::estructuraDelGrupo()` ya lo había resuelto con `LEFT` por su
> lado en agosto, dejándolo escrito: esto es la otra mitad.
>
> **El riesgo no era el `LEFT`, era lo que hay detrás: `p.id as profesor_id` pasa a poder ser
> `null`.** Los **cinco** llamadores se leyeron uno a uno —`AsignaturasController:154`,
> `NotasController:100`, `AusenciasController:70` y `BoletinIndependienteController:263` y
> `:1331`— y **ninguno lee el profesor**: `getShow` devuelve la asignatura tal cual y los otros
> cuatro sólo le sacan `grupo_id` y `asignatura_id`. Nada se rompe dentro.
>
> **Y en el mismo `ON` entró `p.deleted_at is null`**, que el resto del fichero ya hacía. Se metió
> aquí y no se dejó anotado porque **es el `LEFT` lo que lo vuelve inocuo**: con `INNER` habría
> hecho desaparecer la asignatura entera —un 404 nuevo cada vez que el colegio borra a un
> docente— y con `LEFT` se queda en «no tiene profesor», que es lo que es.
>
> **Corrección a la medición que venía con el encargo: no son «cero casos».** Cero entre las
> **vivas**, pero `detallada()` **no filtra `a.deleted_at`** y sirve asignaturas de la papelera;
> ahí hay **una**, la 187 de 2018, con su profesor 16 también borrado. Alcanzarla exige un token
> del año 2018, así que en la práctica no la pide nadie — pero es una fila real cuya respuesta
> cambia, y estaba fuera de la población que se midió. **Lo que NO se tocó es el `a.deleted_at`
> que falta en esa consulta**: añadirlo convertiría en 404 las asignaturas de la papelera que hoy
> contestan 200, y eso es decisión del colegio.
>
> **La prueba: `tests/Contrato/PlanillaSinProfesorTest.php`, tres casos, comprobados en rojo
> contra el `INNER` restaurado** — dos fallan con 404 y el tercero con `'Pedro' is null`, el
> nombre del docente borrado saliendo por la respuesta. **El escenario se construye en el test**
> porque el seed **no tiene ninguna** asignatura sin profesor, y eso trae la advertencia que hay
> que leer antes de dar nada por cubierto: **el seed tiene 20 asignaturas vivas y las 20 con
> profesor vivo, así que el `LEFT` devuelve exactamente las mismas filas que el `INNER` y este
> cambio es INVISIBLE para la suite entera**. Los 1.006 tests en verde no demuestran nada sobre
> esto; el único que lo ejerce es el nuevo. Vecinas en verde igualmente: 225 pasan
> (`Asignatura|Notas|Ausencia|Planilla|BoletinIndependiente|Definitivas`), y `8myvc-5e` confirmó
> que esas mismas clases ya estaban verdes en su línea base.
>
> **Lo que sí es un cambio de contrato, y el orden de despliegue NO es simétrico:** `profesor_id`,
> `nombres_profesor` y `apellidos_profesor` pueden venir `null` donde antes no lo eran nunca. Las
> **cuatro plantillas** que imprimen el nombre sin comprobarlo van en
> **`fix/profesor-nulo-en-papel`** del front, y la asimetría es la que hay que respetar:
>
> - **el front puede ir solo y es seguro** — hoy no hay nulos que pintar;
> - **este backend nunca antes que el front**, o las plantillas imprimen «Prof.: » vacío **en
>   papel**.
>
> Fijado en `DESPLIEGUE-NIVELACIONES-Y-RUBRICAS.md` del front, bajo el encabezado
> **«### 1 · Planilla sin profesor»** —y se cita así, por el encabezado y no por el número de
> línea, porque la primera versión de esto decía «líneas 128-129» y **duró unas horas**: otra
> sesión insertó once líneas más arriba y esa cita pasó a apuntar, sin dar ningún error, a un
> párrafo sobre nivelaciones y el SIEE. Un ancla que apunta con precisión al sitio equivocado se
> lee, cuadra y dice otra cosa.
>
> **Y tampoco vale anclar a una frase suelta**, que fue lo primero que se propuso para
> arreglarlo: «puede ir solo» sale **dos veces** en ese fichero y **en direcciones opuestas** — la
> otra dice que el *backend* de las nivelaciones puede ir solo, que es justo lo contrario de lo que
> aquí hace falta. El encabezado es único; la frase no.
>
> La planilla en sí no necesita nada del front: los alumnos salen del grupo, no del profesor.
>
> **Ojo al `profesor_id` duplicado del SELECT, que no se limpió a propósito:** viajan
> `a.profesor_id` y `p.id as profesor_id`, y con PDO **gana el último**. Eso es lo que mantiene la
> respuesta coherente consigo misma —`profesor_id` es `null` exactamente cuando los nombres lo
> son—; cambiarlo a `a.profesor_id` haría salir un id con los nombres vacíos al lado, que es como
> una plantilla acaba imprimiendo «Prof.: » sin que nadie sepa por qué.
>
> **Y lo que este arreglo NO arregla — mirado, preguntado y CERRADO por Joseth el mismo día:** el
> mismo patrón vive en `Grupo::detailed_materias` (`INNER JOIN profesores` sobre `a.profesor_id`)
> y, peor, en `Grupo::detailed_materias_notas_finales`, que además lleva **`a.profesor_id is not
> null` explícito en el `WHERE`**, cuatro veces. Eso alimenta las **notas finales**, o sea los
> boletines, así que la pregunta era si el día que 2026 fuese el año actual un boletín saldría sin
> esas 134 materias y sin error.
>
> **Joseth dice que no llega a pasar, y el motivo es de dominio, no de código: «siempre habrá un
> docente asignado a una asignatura al momento de entrar a las planillas de notas».** O sea que
> las 146 sin profesor son un estado de *montaje del año*, y para cuando alguien califica —que es
> lo único que alimenta un boletín— ya tienen docente. **No se mide y no se toca**, y esto queda
> escrito para que nadie lo vuelva a abrir como hallazgo: no lo es.
>
> Y las dos cosas encajan en vez de contradecirse, que es lo que hace creíble la respuesta: **la
> planilla tiene que abrir justo durante ese montaje** —es cuando aún no hay docente y es
> exactamente el 404 que se arregla arriba—, mientras que el boletín se imprime después, cuando ya
> lo hay. El arreglo de esta entrada cubre la ventana; el `is not null` de `Grupo::` vive fuera de
> ella.

**Anterior: 2 sep 2026, tarde — EL PANEL DE UN ALUMNO PASA DE 620 ms A 20 ms, Y EL CALENDARIO
RESULTÓ SER OTRA COSA** · [`24-el-panel-de-inicio.md`](24-el-panel-de-inicio.md) nuevo, con
`GET ChangesAsked/to-me` medido rol por rol · **el router sigue en 550**: no hay ruta nueva ·
lo levantó la sesión de `myvc_flutter`, y **la pregunta ya estaba escrita en el otro repo desde
el 1 sep** (`myvc_front/MIGRATION.md`, «un endpoint único para la portada»)

> **Tres recortes hechos; las diez claves de la respuesta siguen todas** (la tercera sí cambia la
> forma de una fila, y va explicada abajo):
> **(1)** `profes_actuales` vuelve **vacío** para un alumno — eran **dos consultas agregadas por
> cada uno de los 16 docentes** para calcular `porcentaje`, *lo al día que va cada profesor con su
> planeación*, y **no lo pinta ningún cliente** (en las dos aplicaciones el recuadro va bajo
> `admin || profesor`, y Flutter no lee la clave). **49 → 24 consultas, ~620 ms → ~20 ms.**
> **(2)** el horario del docente deja de ser un **N+1 de dos pisos** —una consulta de unidades por
> asignatura y una de subunidades por unidad— y pasa a dos consultas con `IN`: **75 → 17
> consultas**, y la respuesta comprobada **idéntica byte a byte** contra el algoritmo viejo sobre
> la población real (17 asignaturas, 36 unidades, 54 subunidades). Fijado por
> `tests/Contrato/PanelDeInicioTest.php`, comprobado al revés.
>
> **(3) El calendario deja de ir con `SELECT *`** y manda las **nueve columnas que se pintan**:
> **231 → 114 KB**. Con eso **el panel pesa la mitad para todos**: Usuario 274→157 KB,
> Profesor 279→162, Alumno 225→112, Acudiente 218→108. **Ninguna fila se borra de la base**: lo
> único que cambió es qué columnas manda el endpoint.
>
> **Y el hallazgo que corrigió la decisión que se había tomado dos horas antes.** Joseth autorizó
> primero recortar `eventos` **por rango de fechas**. Midiendo después: **el año en curso de esta
> copia es 2025**, sus **507 filas son todas cumpleaños** (184 KB, el 80% del peso) y lo viejo son
> **123 filas de 2019–2023** (19%). O sea que **cortar por fecha quita lo que la gente mira y deja
> lo que no mira nadie**; el recorte de columnas vale el doble y no esconde un solo evento. Se hizo
> así.
>
> **Lo único que se ve en una pantalla, y va avisado:** entre las columnas quitadas está
> `created_by_nombres`, que la aplicación **vieja** pinta en el tooltip del evento («Por:
> administrador»). Hasta que se arregle allí —una línea— dirá **«Por: undefined»**. Joseth lo
> decidió sabiéndolo, y dejó dicha una tercera cosa **sin decidir**: *inhabilitar estos endpoints
> en el panel viejo para que los colegios pasen a `app2`*. **La sesión del front tiene que
> enterarse**: quitar un campo de una respuesta es de las cuatro cosas que se avisan por el canal.
> El snapshot `muestreo-ChangesAsked-to-me.json` se regeneró a propósito y su diff son exactamente
> esas nueve claves.
>
> **Lo siguiente, ya decidido por Joseth y sin empezar:** el agregador `panel/portada` **al lado**,
> con `to-me` intacto (ruta nueva → 551 el día que se autorice, y se cuenta ese día). Y **los
> pedidos de cambio se rediseñan** en la forma estrecha: el diseño está en
> [`25-pedidos-de-cambio.md`](25-pedidos-de-cambio.md), **nada construido**, y lleva delante una
> medición que no está hecha — **cuántos pedidos vivos hay en los quince colegios**, porque si el
> mecanismo está muerto en trece la respuesta buena puede ser retirarlo. De sus **31 columnas
> `_new`, sólo seis se escriben**.

**Anterior: 2 sep 2026 — HORARIOS: CAMBIÓ EL DISEÑO ENTERO Y NO HAY UNA LÍNEA DE CÓDIGO** ·
[`23-horarios.md`](23-horarios.md) reescrito a **v2** · **el router sigue en 550** (contado con
`route:list --json` ese día) y **no se tocó nada de `app/`**, así que todo lo que va debajo
—el despliegue pendiente, las decisiones abiertas del boletín— **sigue vigente sin un cambio** ·
lo trajo la sesión `myvc-front-ea` de parte de Joseth

> El horario **ya no es un módulo web** con cinco tablas y nueve rutas: es un **programa de
> escritorio** (Tauri 2 + Angular) con su fichero de proyecto local, y a esta API le queda
> **guardar versiones del horario de un año y decir cuál es la oficial**. Salones, disponibilidad,
> rejilla, timbres y pesos **no existen en el servidor**. El diseño del cliente vive en el
> artefacto del front; la mitad de backend, en el 23.
>
> **Las tres rutas propuestas —`POST horario/versiones`, `GET horario/versiones`,
> `PUT horario/versiones/{id}/oficial`— NO están autorizadas.** 550 → 553 el día que lo estén, y
> ese número se vuelve a contar ese día.
>
> **Y salió un hallazgo midiendo, que corrige a la v1 en la dirección cara.** La v1 escribió que
> «Clases de hoy» *«cae por la rama de enséñalo todo»*. **Es falso en el año abierto de este
> colegio**: `years.show_materias_todas` vale **0** en el año 8, así que la pantalla **sí** filtra
> por las siete columnas de día; las **2 de 134** filas que tienen día puesto tienen
> `profesor_id` **nulo** y la consulta filtra por docente. O sea que **`horario_hoy` y
> `horario_manana` vuelven vacíos para todos los docentes, todos los días** — la pantalla desde la
> que se toma asistencia está en blanco, y nadie lo ha reportado porque **un `[]` se parece a «hoy
> no tengo clase»**. Debajo hay un fallo dormido: `$dia+1` el **sábado** da 7, el `switch` no tiene
> caso 7 y «mañana» devuelve **todas** las asignaturas del docente. Hoy no se ve; se estrena el día
> que las columnas se rellenen. Las dos cosas, con su medición, en la §2 del 23.
>
> **Segunda vuelta con el front, la misma tarde, ya incorporada al 23:** el cuerpo del
> `POST` concretado —y corregido en cuatro sitios (§5.2): los `docentes[]` de la pieza son
> **`profesores.id`, no `users.id`** (dos columnas de la misma fila, y la lectura que ya usa el
> panel devuelve las dos; aquí no fallaría porque los 47 profesores tienen `user_id`, pero la
> columna es NULLable y el que no lo tenga desaparecería de la revalidación **sin error**);
> `subida_por` y la fecha salen **del token y del reloj del servidor**, no del cuerpo; el
> veredicto `comprobaciones` lo escribe el servidor y **no se lee del cuerpo nunca**; y el salón
> y su capacidad viajan **para imprimir y para nombrar el dato que faltó**, sin ascender a regla—.
> **Las dos sesiones recomiendan la opción B** con el veredicto guardado junto a la versión **y
> con su población dentro** («345 lecciones y 134 asignaciones revisadas · salón NO COMPROBADO,
> falta `capacidad_grupos`…»), porque un veredicto sin población vuelve a leerse como «todo bien».
>
> **Y una frontera nueva, de Joseth:** el escritorio se tiene que poder **vender por licencia sin
> MyVC detrás**. No añade rutas ni pantallas aquí; lo que sí obliga a escribir es que
> `asignatura_id` es una clave de MyVC, así que un proyecto armado sin MyVC **no se puede subir**
> y la ruta tiene que decirlo con un **422** en vez de aceptar nulos que luego no derivan ninguna
> columna (§8 del 23) — y **nunca emparejando por nombres**, que es la salida que parece
> amable y acaba metiendo las horas de «Matemáticas de 3°A» en 3°B sin dar ningún error.
>
> **Tercera vuelta, y quedó una forma propuesta para el año pasado:** *subir sí, volver oficial
> sólo el año actual* — o sea **mover el puntero `years.horario_version_id` sólo en el año
> abierto**, dejando quieto el de los cerrados, que son el historial. Sigue **sin decidir**, y va
> con su precisión al lado: **no** impide que el panel enseñe el horario de un año pasado (quien
> se mueve a 2024 lee las asignaturas de 2024, que es el producto que cerró la
> [16](16-escribir-en-un-anio-pasado.md)); impide **cambiárselo por debajo**.
>
> ### JOSETH CONTESTÓ TRES DE LAS SIETE, EL MISMO 2 SEP — Y LA TERCERA TRAJO UN HALLAZGO
>
> 1. **Las tres rutas quedan AUTORIZADAS**, las tres a la vez (con sólo dos, nadie puede marcar
>    la oficial y «Clases de hoy» sigue vacía). **550 → 553** el día que se escriban, contado
>    entonces con `route:list`, no sumado.
> 2. **La revalidación es la opción B**: el servidor comprueba las tres que puede y guarda un
>    veredicto que **nombra lo no comprobado y dice su población**.
> 3. **Marca la oficial un superusuario o el coordinador académico.** Secretaría sube pero **no**
>    publica.
>
> **Y la tercera no se puede escribir con ningún criterio de los que ya hay**: no es
> `esSuperusuario` (deja fuera al coordinador) ni `esAdministrativo` (mete al `Secretario`, que
> Joseth no nombró). Es un **método nuevo** en `Autoriza`, que es lo correcto: un criterio nuevo se
> escribe con su nombre y no se cuela ensanchando uno que leen otros seis sitios.
>
> **El hallazgo, medido antes de escribir nada:** «coordinador académico» nombra **dos cosas** en
> esta base — el **rol** `Coord académico` (`roles.id = 9`, de 2018) y la columna
> `years.coordinador_academico_id` —, y **hoy ninguna de las dos identifica a nadie**: el rol tiene
> **0 usuarios** y la columna está **`NULL`** en el año 8. Se usa el **rol**, porque la columna se
> escribe en un solo sitio (al copiar un año) y **no la lee nadie en todo `app/`**: es un dato que
> se arrastra, no un permiso. Consecuencia que hay que decir entera: el día que esto se escriba,
> **la oficial la marcan los 11 superusuarios y nadie más**, hasta que alguien le dé el rol a la
> coordinadora. **La regla nace correcta e inerte** — que no es un fallo, pero leer «también el
> coordinador académico» y suponer que ya hay alguien detrás sí lo sería. Falta además un
> `Role::isCoordAcademico()`, que no existe (sí están `isCoorDisciplinario`, `isSecretario`,
> `isEnfermero` y `isPsicologo`).
>
> **Y contestó tres más, dos de ellas MÁS ABIERTAS que lo que proponían las dos sesiones** — se
> escriben con su consecuencia al lado, que es lo que hace que no envejezcan mal:
>
> 4. **El rol vacío se escribe igual.** Asignárselo a alguien es operación de cada colegio: quince
>    decisiones, no una nuestra.
> 5. **Listar las versiones: `auth.personal`**, o sea cualquier docente, y no «el mismo que sube».
>    **Con una condición que va antes que la ruta: listar NO es descargar.** `GET
>    horario/versiones` devuelve nombre, fecha, quién y el veredicto — **nunca el blob ni las
>    lecciones**: un `SELECT *` ahí le entregaría a los 53 docentes el fichero de proyecto entero.
> 6. **Subir y volver oficial valen en CUALQUIER año**, también los cerrados. Coherente con la
>    [16](16-escribir-en-un-anio-pasado.md), y con la consecuencia dicha entera: **marcar oficial
>    una versión de 2024 reescribe las siete columnas de las asignaturas de 2024**, y quien se
>    mueva a ese año verá ese horario. Es la razón por la que el puntero vive en `years`: cada año
>    tiene el suyo y no se pisan.
>
> 7. **El blob del proyecto sube siempre**, no opcional: sin él el trabajo de un mes vive en un
>    portátil. *(Contestada en paralelo por Joseth, vía la sesión del front.)*
>
> **EL PRE-VUELO NIVEL 1 YA SE CORRIÓ, y da un hallazgo que no es de horarios.** Con la rejilla de
> 7 × 5 **ningún docente es imposible**: los 12 caben en las 35 casillas y el más cargado —31 h—
> tiene 4 de holgura (con el 6 × 5 que supuso la v1, ése **no tenía horario**: el supuesto que
> costaba el proyecto lo deshizo un pantallazo). Pero **las 10 asignaciones sin docente son las 10
> de preescolar, y no están repartidas: Transición tiene 7 de 7 sin docente —el grupo entero— y
> Jardín 3 de 7.** Son 25 de las 345 horas, y dicho como lo diría la herramienta: *el horario de
> Transición no se puede colocar en absoluto, porque ninguna de sus siete asignaciones tiene a
> quién poner en la casilla*. Encaja con lo de arriba: las dos únicas filas con día marcado de las
> 134 son de Transición y las dos tienen `profesor_id` nulo. **Es un dato del colegio que hoy no
> enseña nadie**, y sale de una consulta — por eso el nivel 1 como script de `tools/` sigue siendo
> lo más barato que se puede hacer, y ahora con un ejemplo de lo que encuentra.
>
> **Quedan tres decisiones abiertas** en la §10.2 del 23, y las tres son del día que se escriba el
> código: el blob (dónde vive y con qué tope), **si existe una ruta para DESCARGAR el proyecto**
> —sería la **cuarta, 554**, no está pedida, y el front vota por que su permiso sea el de publicar
> y no el de subir— y qué ata las siete columnas derivadas.

> **El contrato quedó cerrado entre las dos sesiones tras tres vueltas**, y **seis de las siete
> decisiones están contestadas** (arriba). Lo que falta no es acuerdo técnico ni permiso: es
> escribir el código. **La forma no se vuelve a negociar**: si se cambia, se cambia con él y
> avisando al front, que tiene su mitad escrita sobre ésta.

> **La decisión de fondo, ya contestada (opción B)**, es la §6 del 23: el servidor **no tiene** la
> disponibilidad ni los salones ni la rejilla, así que **no puede revalidarlos**. Revalida las tres
> que puede **y lo dice en la respuesta**, con su población dentro. Aceptar y callar habría sido un
> «validado» encima de un horario ilegal.

**Última actualización: 1 sep 2026, tarde — LA ÉPICA DEL BOLETÍN INDEPENDIENTE ESTÁ TERMINADA EN
ESTE REPO: LAS SEIS FASES ESTÁN EN `main`** · **`Tests: 1736 passed (13495 assertions)`,
`Duration: 619.55s`, `exit=0`**, cero rojos y cero saltados · pint **no reescribió nada** ·
larastan nivel 7 **`[OK] No errors`** · **549 rutas** —548 y 549 son las dos lecturas por estudiante del 1 sep— · **sin subir —104 commits por delante de `origin/main` (`6573916`), contados el 1 sep
sobre `1cb7092`— y sin desplegar** ·
coordina `8myvc-ab`

> ### Nivelaciones — rama `niv/backend`, 2 sep 2026 (sesión A del reparto en tres)
>
> Joseth decidió el 2 sep las cuatro preguntas de `myvc_front/PLAN-NIVELACIONES-Y-RUBRICAS.md`
> y el trabajo se repartió en tres sesiones (`myvc_front/TAREAS-NIVELACIONES-Y-RUBRICAS.md`):
> **A** backend de nivelación (esta rama), **B** front, **C** rúbricas. Lo primero de A fue
> **el contrato**, porque B construye contra un doble y estaba parada esperándolo:
> [22-nivelaciones.md](22-nivelaciones.md). **Cambiar ese documento es avisar a B.**
>
> Lo que fija: `PUT`/`DELETE notas/nivelar/{id}`, `PUT notas/nivelar/lote` con los tres
> desenlaces de `notas/lote`, los seis campos nuevos de `notas/detailed` y
> `PUT years/regla-nivelacion`. **Cuatro rutas nuevas cuando entren**, y `notas/update` y
> `notas/lote` **no cambian ni una línea**: los usa `myvc_flutter`, que es una sola app para
> los quince. Dos desviaciones del plan, escritas allí: la columna `nota_nivelacion` (bajo
> `topada` el 90 que queda en 70 desaparecería) y **403** donde el guard viejo contesta 400.
>
> **A1 hecha el 2 sep**: `putUpdate` y `putLote` ya auditaban; lo que faltaba era
> `deleteDestroy`, **el único escritor de `notas` sin rastro en ninguna de las dos tablas**,
> y con borrado físico — hoy en los quince colegios nadie puede contestar «quién borró esta
> nota». Instrumentado con dos tests en `AuditoriaDeLosDiezEscritoresTest`; el porqué de
> dejar `putSubunidad` sin auditar está en el [18](18-auditoria.md), fase 4.
>
> **A3 y A4 hechas el 2 sep.** La migración es aditiva pura y **un solo `Schema::table` por
> tabla** —en MySQL 5.7 cada `ALTER` reconstruye la tabla, y `notas` es la grande—; la regla vive
> en `App\Services\Nivelacion` y una regla desconocida en la base **lanza** en vez de caer a
> `topada` en silencio.
>
> **Y lo que encontró la suite, que es lo que hay que heredar:** siete instantáneas se movieron
> **sin que nadie tocara su método**, porque siete consultas leían la fila entera con `*` y un
> `ALTER TABLE` las llena solo. Cinco eran mías y están **congeladas** nombrando columnas
> (`notas/update`, `notas/show`, `Nota::alumnoPeriodoDetalle` con `Nota::LAS_DIEZ_COLUMNAS`,
> `Asignatura::calculoAlumnoNotas`/`2` y `PromovidosController:189`); las **tres de `years/*` se
> regeneraron a propósito**, porque `regla_nivelacion` viaja por contrato (22 §5). **Quedan dos
> rojos que NO son míos**: `Informes/BolfinalesController:508` (`SELECT nf.*`), de `8myvc-f2` con
> A10. La tabla de quién devuelve las columnas nuevas a propósito y quién las tiene congeladas es
> la **§3.4** del [22](22-nivelaciones.md), y la regla que deja: **una columna viaja porque
> alguien la nombró**, nunca por un asterisco.
>
> **A5 y A6 hechas el 2 sep, y van en el mismo commit a propósito.** Las tres rutas nuevas
> —`PUT`/`DELETE notas/nivelar/{id}` y `PUT notas/nivelar/lote`— dejan el router en **553**
> (contado con `route:list`), y con ellas **22 tests que miran lo que queda escrito**. La mitad
> es el centinela: con la regla `topada` encendida, `notas/update` y `notas/lote` siguen
> guardando lo que se les manda, y **sobre una nota ya nivelada escriben la vigente sin tocar
> el acta** — ni la limpian (sería borrar un registro académico desde un móvil) ni recalculan
> (sería aprender a nivelar por la puerta de atrás).
>
> **Y una trampa del seed, para quien escriba tests de notas:** la escala de este colegio es
> **0 a 50**, no 0 a 100. Un caso escrito con 90 y 95 sale **422 por `EscalaDeNotas`** y pasa
> sin haber medido nada de lo que dice medir.
>
> **A7 hecha el 2 sep.** `notas/detailed` devuelve las seis claves de la nivelación en cada
> celda y las cuatro del acta en la definitiva, **siempre presentes y en `null` cuando no hay
> nivelación** — una clave que a veces no viene obliga al front a distinguir «vacío» de «no
> vino». `notas-detailed-profesor.json` se regeneró **a propósito**, y el diff son esas diez y
> nada más. Dos tests nuevos: uno comprueba que los campos llegan **con valores** tras nivelar
> (la instantánea sólo prueba que las claves existen, no que se llenen) y otro que las notas
> **sin** nivelar siguen saliendo, que es lo que se rompería si el `JOIN` con `users` fuera
> `INNER` en vez de `LEFT`.
>
> **La trampa que costó dos vueltas, y está medida en `NotasTest::contexto()` desde el 20 ago:**
> `Services\Login` **reescribe `users.periodo_id` en cada inicio de sesión**, y `periodos.actual`
> es el actual **de su año** mientras el año del colegio lo dice `years.actual`. Un test de
> `notas/detailed` que no pida las dos cosas elige una asignatura de otro año y recibe un 404.
>
> **A8 hecha el 2 sep.** `PUT definitivas_periodos/nivelar`, endpoint nuevo por lo mismo que
> los otros tres: `definitivas_periodos/update` lo llama Flutter para teclear a mano, y hay un
> test que fija que no aprendió a nivelar. **Marca `recuperada` y `manual`**, que es lo que la
> desengancha del recálculo — sin eso la nivelación duraría hasta que alguien abriera la
> planilla. Y con la regla `mayor` la definitiva **conserva sus decimales**: 43,7500 no se
> convierte en 44 por nivelar por debajo. Dos columnas más en `notas_finales`
> (`2026_09_02_200000`), con el mismo argumento que `notas.nota_nivelacion`. Router en **554**,
> contado.
>
> **A9 hecha el 2 sep: EL CARRIL A ESTÁ TERMINADO** (A10 pasó a `8myvc-f2` con `Informes/**`).
> El acta de la recuperación del año va **sin endpoint nuevo**, y es la decisión: en esa tabla
> la fila entera **es** la recuperación, así que cada escritura es el acta. `observacion` y
> `fecha` son opcionales, el cliente que hoy manda `{rf_id, nota}` sigue igual, y hay un test
> que fija que **`year` sigue siendo el número y no el id** — el refactor está decidido en
> `PeriodoDeLaFila` y es tentador de hacer «de paso».
>
> **Y una corrección de método que conviene heredar:** cuatro de los siete casos de A9 nacieron
> `skipped` porque `recuperacion_final` está **vacía en el seed**. Un test saltado no mide nada
> y se lee como verde. Ahora la fila se **fabrica por la API** —no con un `INSERT` a mano—, así
> que si el camino de crear se rompiera, esos casos fallarían en vez de saltarse.
>
> El router queda en **554** y las cuatro rutas nuevas son de nivelar. Lo que falta del plan es
> de otros carriles: la impresión (A10, `8myvc-f2`) y el front entero (B).
>
> ### Y cinco cosas que entraron DESPUÉS de A9, todas medidas
>
> 1. **Los escritores de bitácora pasaron de 10 a 12**, y lo cazó su centinela en la corrida
>    completa, no una persona. Sin él, `salud-de-la-bitacora.php` habría impreso un reparto de
>    relojes con dos escritores sin clasificar **y con toda la confianza del mundo**. Las dos
>    nuevas escriben en Bogotá y **reutilizan los tipos `Nota` y `NF_UPDATE`** a propósito: dos
>    pantallas del front buscan el historial de una nota por tipo, y una nivelación con tipo
>    nuevo desaparecería de ahí.
> 2. **`regla_nivelacion` viaja en el bloque de la sesión**, en las cuatro ramas (22 §5.1), para
>    que el diálogo previsualice sin otra petición. Mueve cinco instantáneas, regeneradas a
>    propósito y con el diff comprobado: una clave nueva en cada una.
> 3. **`tools/filas-enteras-al-cliente.php`**: qué consultas leen la fila entera de una tabla del
>    dominio **y la publican**. Sale de que la migración movió siete instantáneas sin que nadie
>    tocara su método. Va **después** de la regla, no en su lugar: lo primero sigue siendo
>    **correr la suite entera después de cada migración que añada columnas**.
> 4. **El 404 de la planilla decía «no es de este año» cuando lo que falta es el profesor.**
>    Medido: **146 de 1219 asignaturas vivas sin profesor**, cero con profesor inexistente o
>    borrado — no es corrupción, es un estado normal del dominio, y son **134 de 134 en el año
>    siguiente**. Ahora el mensaje dice cuál de las cuatro cosas pasó. **El `inner join
>    profesores` NO se tocó**: que la planilla deba abrirse sin docente es decisión de Joseth.
> 5. **El cuerpo de un 404 sí llega con `APP_DEBUG=false`**, medido con el kernel de verdad:
>    `abort(404, 'texto')` devuelve el mensaje entero y sólo el `abort(404)` **sin** texto sale
>    vacío — y en `app/` no queda ninguno vivo. El front excluye el cuerpo de todos los 404
>    dando por hecho lo contrario, así que **hasta que quite esa exclusión, ningún mensaje de
>    404 del backend lo ve nadie**.
>
> ### La cifra de este carril, con sus coordenadas pegadas
>
> **`Tests: 3 failed, 1680 passed (15736 assertions)`, `Duration: 658.65s`** — 2 sep 2026,
> sobre `niv/backend` **con `main` (`805e08f`) fusionado dentro**, base
> `simonbolivar_testing_niv`, **un solo proceso** (comprobado con `ps` en el contenedor antes y
> después). De los tres, **dos son de otro carril** —`BoletinesTest`, por el `SELECT nf.*` de
> `Informes/BolfinalesController:508`, de `8myvc-f2`— y **el tercero ya está arreglado**:
> `muestreo-auth-me`, porque `GET auth/me` devuelve el mismo bloque de contexto y también gana
> `regla_nivelacion`. Eran **seis** instantáneas del contexto y se habían regenerado cinco.
>
> **Y ése es el argumento entero de la regla nueva:** las cinco se corrieron por clase, sabiendo
> cuáles se tocaban, y por eso la sexta no se vio. `MuestreoDeLecturasTest` cubre veinte
> lecturas; **sólo la corrida completa las mira todas**. Con el arreglo dentro, la cifra de esta
> rama es **1681 verdes y 2 rojos, los dos ajenos**.
>
> **Y la trampa de la noche, que costó 31 rojos falsos:** una corrida de tests cortada por el
> tiempo de espera del cliente **sigue viva dentro del contenedor**. Lanzar la segunda contra la
> misma base da deadlocks en `personal_access_tokens` que se leen como fallos del código. Se
> mata el proceso **dentro** del contenedor; que muera el `docker exec` no basta. **A10 ya no es de este carril**: la
> impresión y `Informes/**` pasaron a `8myvc-f2` el 2 sep. Base de tests de esta sesión:
> `simonbolivar_testing_niv`.

> ### A10 — la impresión del par, rama `niv/informes` (carril C-back, 2 sep 2026)
>
> `app/Http/Controllers/Informes/**` es de este carril desde el 2 sep. El reconocimiento
> —qué informe imprime notas, cuál debe imprimir el par y **dónde se toca el puesto**— está en
> el [27](27-nivelaciones-en-los-informes.md), medido con fichero y línea.
>
> **Hecho, y desplegable sin esperar a nadie:**
>
> | Qué | Dónde | Commit |
> |---|---|---|
> | Las cuatro consultas con `*` de `notas_finales` y `recuperacion_final` nombran sus columnas | `Informes/BolfinalesController`, `CertificadosPersonaController` | `db26dd3` — **puso en verde los dos rojos de A sin regenerar ningún snapshot** |
> | El par del **indicador** y de la **definitiva** | `Subunidad::deUnidadCalculada`, `Grupo::detailed_materias_notafinal`, `BoletinesController:293`, `BolfinalesController:508` | `11e0266` |
> | La tabla de periodos del **tipo 2**, y los dos snapshots de `notas/alumno` | `Boletines2Controller:217` | `d39a316` |
> | El **tipo 3**: veintiséis proyecciones en cuatro consultas | `Grupo::detailed_materias_notas_finales` | `78fe02a` |
>
> Lo imprimen el **boletín tipo 1 y 5**, el **tipo 2** y el **tipo 3** (los dos, sólo la
> definitiva), el **boletín final** y las **notas actuales del alumno**. Ocho instantáneas regeneradas y **leídas**: el diff es
> **sólo claves nuevas en `null`**, ninguna quitada — que es la prueba de que `nota` sigue siendo
> la vigente y de que ningún cliente pierde un campo.
>
> **Dos decisiones tomadas que conviene no re-litigar:**
>
>   - **El certificado firmado: opción 2** (Joseth, 2 sep) —vigente más la novedad al pie, sin par
>     tachado y sin interruptor—. **No necesita backend**: ese papel lo arma el front desde
>     `bolfinales/detailed-notas-year`, cuya respuesta ya trae lo necesario. Escribirlo en
>     `CertificadosPersonaController` habría sido código muerto (27 §5.2).
>   - **`GET notas/alumno` gana el par a sabiendas**, aunque la §3.4 del 22 la marcara congelada:
>     Flutter la llama y **no se rompe** —comprobado leyendo su parser—, y esconderle al alumno la
>     novedad que sí lleva su certificado firmado no se sostiene.
>
> **Lo que falta de A10** (27 §5.3): el **criterio de «recuperó»** de `:574`, que **no es
> aditivo** —cambia lo que imprimen los quince hoy— y por eso espera decisión, y **el puesto**,
> que espera a Joseth: si elige congelarlo va **antes** que todo lo anterior, porque son los
> mismos cinco ficheros.
>
> **Y un hueco del contrato que quedó medido de paso, sin arreglar:** `boletines3/detailed-notas`
> **sin `periodo_a_calcular`** devuelve las áreas **sin una sola asignatura**, en 200 y sin
> avisar —el defecto es 10 y la consulta sólo tiene ramas para 1..4—. El front sí lo manda, así
> que la pantalla real funciona; lo que estaba ciego era la instantánea, que por eso guardaba
> `asignaturas: []` y no vigilaba ni una columna de ese informe.
>
> Base de tests de esta sesión: `simonbolivar_testing_inf`. **Y una regla de la noche: no se lanzan
> dos suites completas a la vez en el mismo contenedor** —25 rojos por deadlock el 2 sep, que al
> re-correr solos daban 136 verdes—, y **el `exit code` de una corrida canalizada no es el de la
> suite**: es el de `tail`.

> **Esa cifra va con sus coordenadas pegadas y así se copia o no se copia: medida el 1 sep 2026,
> desde la raíz, desasida, sobre `1cb7092`** —con los cuatro merges de hoy dentro—. Es la primera
> corrida que describe este `main`: las de los lotes miden **su árbol**, y una suite de antes de un
> merge no describe el árbol de después. Es la lección del «43 en 23» en su forma aplicable: **el
> fallo no fue que la cifra estuviera mal, fue que ninguna de las veces que se copió llevó delante
> la fecha de su medición.**

> ### CARRIL C — RÚBRICAS, mitad backend: rama `niv/rubricas`, 2 sep 2026 — C2, C3 y C4 HECHAS
>
> Es la sesión C-back del reparto de `myvc_front/TAREAS-NIVELACIONES-Y-RUBRICAS.md` (§5 «C»),
> coordinada por `myvc-front-0f`; la mitad de `app2` la lleva `myvc-front-4f`. **No está en
> `main`**: tres ramas —A `niv/backend`, B `niv/front`, C `niv/rubricas`— y se integran de una
> en una, A, B y luego C. Nada de esto toca `NotasController`, `routes/api/academico.php` ni
> `DefinitivasDeAsignatura`: **la rúbrica produce la nota y no la escribe** — `notas/update` y
> `notas/lote` siguen siendo los únicos escritores, tal como están.
>
> | Qué | Dónde | Estado |
> |---|---|---|
> | El contrato, escrito **antes** que el código y enviado al front | [26-rubricas.md](26-rubricas.md) | `694562e` |
> | C2 · migración: cinco tablas + `subunidades.rubrica_id` NULL, `momento` dentro de la clave única (C9 absorbida) | `2026_09_03_100000_rubricas` | `511ce3f`, corrida y devuelta sobre `simonbolivar_testing_rub` |
> | C3 · cinco modelos con `@property` a mano y `RubricasController` | `app/Models/Rubrica*.php`, `app/Http/Controllers/RubricasController.php` | hecho |
> | C4 · `routes/api/rubricas.php` y **una** línea en `routes/api.php` | diez rutas: **551–560**, familia «10 de 10» en los tres snapshots | hecho |
> | Tests: permisos de las diez, suma de pesos que no se corrige, nivelación que no pisa la original, lote todo-o-nada, y `notas.nota` que no se mueve | `tests/Contrato/RubricasTest.php` | **14 tests, 234 aserciones, verde** |
> | larastan 7 sobre lo nuevo · pint sobre lo nuevo | | `[OK] No errors` · sin avisos |
>
> **Lo que espera a Joseth de este carril, y NO bloquea:** el §5 del 24 — si el docente que
> edita o califica con una rúbrica tiene que **dar esa asignatura**. Hoy `notas/update` tampoco lo
> comprueba y el carril mantiene paridad; estrecharlo se hace en los dos sitios a la vez o en
> ninguno. La lleva el coordinador.
>
> **Lo que NO se hizo a propósito:** `App\Services\Auditoria` no registra rúbricas (la fase 4 del
> 18 no ha llegado a notas); `recuperacion_final`, boletines y certificados no saben de rúbricas
> (decisión 4: no tienen que saber). Volver atrás es `migrate:rollback` de una migración: cinco
> `DROP TABLE` y una columna, nada más — probado.

> ### Las seis fases, y las cuatro últimas entraron hoy
>
> | Fase | Qué | Merge |
> |---|---|---|
> | **1** | el alcance en los sitios de trabajo — 4 del lote A + 7 del B + 7 del C | `9515642` · `5bcc441` |
> | **2** | la marca por periodo, su guarda y su ruta | `878dee7` |
> | **3** | las planillas normales sin los independientes | `9515642` |
> | **4** | `PUT boletin-independiente/planilla` y `POST boletin-independiente/copiar` | `da26efb` |
> | **5** | los tres boletines probados **en negativo** | `8dc982c` |
> | **6** | los puestos y su interruptor | `9304441` |
>
> Y encima de las seis, hoy: **`POST unidades` acepta `alumno_id`** (`c0f0e31`) —que era **una
> promesa del §8 que nunca se escribió**, y mandar el campo era peor que no mandarlo: la unidad
> nacía del grupo y el reparto del curso se iba al 110 %—, los **tres campos del front**, la **§9.5
> cerrada**, y `salud-de-las-definitivas.php` que **ya no sale `exit=0` cuando no pudo mirar**.

> ### LO QUE FALTA NO ES CÓDIGO, Y ÉSTE ES EL ORDEN
>
> 1. ~~**Subir `main`.**~~ **HECHO el 2 sep 2026 por la noche**: `d43d028` está en `origin/main`
>    y no queda ningún commit sin subir. *(Se deja tachado y no se borra: un pendiente en futuro
>    no envejece a «hecho» solo, y quien lea esta lista tiene que poder ver que se cerró.)*
> 2. **Desplegar los quince, colegio a colegio, con las migraciones EN EL MISMO DESPLIEGUE.** Sin
>    `puestos_con_bol_independiente` los tres boletines contestan **500**: un colegio con el `git
>    pull` hecho y el `migrate` sin correr **está caído**. `git pull` → `migrate --force` →
>    **comprobar un boletín antes de pasar al siguiente**. Los comandos, en
>    [DESPLIEGUE.md](../DESPLIEGUE.md).
> 3. **Las DOS herramientas del día del despliegue, quince veces cada una, después de migrar**:
>    `independientes-sin-estructura.php` (§9.1, el alumno que se cae por el hueco) y
>    `salud-de-las-definitivas.php` (el `ALTER` de la fase 2 del [10](10-definitivas.md)). **Las dos
>    contestan `exit=2` si no pudieron mirar**, que es lo que impide que un colegio caído se lea como
>    un colegio limpio.
> 4. **Avisar al front**, que tiene pantallas escritas y escondidas — y **no publica hasta
>    DESPLEGADO**, no fusionado.
> 5. **`myvc_flutter`**: la tarea está escrita en su repo
>    (`~/DESARROLLOS/myvc_flutter/docs/boletin-independiente.md`, 1 sep 2026). **Es una sola app para
>    los quince** y el despliegue va colegio a colegio, así que tiene que tolerar **las dos formas a
>    la vez**; hoy lee `alumnos` y nada más, y un alumno marcado **desaparece de su planilla sin
>    ningún error**.

> ### LAS DECISIONES QUE ESPERAN A JOSETH — son cuatro, y dos de ellas parecen una sola y NO lo son
>
> 1. **El criterio del recálculo de definitivas.** La §9.1, el código y la herramienta usan **tres
>    criterios distintos para el mismo conjunto**, y de cuál sea el bueno depende si el trabajo son
>    **12.320** filas (`MATR`/`ASIS`, lo que cuenta la herramienta), **12.455** (+`PREM`) o **26.221**
>    (todos los estados, que es lo que cubre el recálculo por decisión del 28 ago). Casi todo el hueco
>    es `RETI`.
> 2. **La fila duplicada que para el `ALTER TABLE`** de la fase 2 de definitivas — Noveno 2025,
>    Ciencias Naturales, periodo 2, `auto+auto`, las dos a `0.0000`.
>
>    > **Y estas dos NO son la misma decisión, aunque se propuso escribirlas juntas.** El índice único
>    > **mira la tabla entera**: su consulta no tiene ni un `JOIN` ni un filtro de estado, así que el
>    > criterio **no puede** cambiar la limpieza. **La fila bloquea el `ALTER` por sí sola y se limpia
>    > sin decidir nada; el criterio decide el tamaño del recálculo y no toca la limpieza.** Juntarlas
>    > sería **la corrección del 24 ago del revés** —aquel día la herramienta contaba duplicados
>    > *vivos* mientras el índice miraba la tabla entera, y por eso podía decir «se puede poner sin
>    > limpiar nada» con el `ALTER` fallando igual—, y pondría rojo el control que ancla ese caso.
>    > **La prueba que lo cierra es la que hoy no tenemos delante: si el duplicado fuera de un `RETI`,
>    > «MATR/ASIS» daría 0 y la tabla entera daría 1.**
>
> 3. **Dos vueltas atrás candidatas, ya fundidas y de un commit cada una**, que sufre el front: el
>    **400→404** de la rama de `matriculas` en `PUT alumnos/guardar-valor`, y que
>    `guardar-valor-varios` **corte el bucle en el 404** dejando escritos los alumnos anteriores en
>    vez de «saltar y seguir».
> 4. **El aviso al front del 422 nuevo de `POST unidades`** y de la guarda sobre quién puede mandar
>    `alumno_id` (`auth.personal` + `pueden_editar_notas`). Está escrito en la §8.1 del
>    [19](19-boletin-independiente.md); su sitio es el buzón del front, y **cuándo se le habla lo
>    decide Joseth**.
>
> **Y una quinta que es del otro lado:** qué hace `myvc_flutter` con los marcados — **ocultarlos y
> decirlo** (la app sólo cuenta lo que el backend ya manda; **el docente no podrá ponerles nota desde
> la app**) o **enseñarlos con su propia estructura**, que es una segunda planilla dentro de la
> pantalla y es trabajo de verdad.
>
> **Y la SEXTA, que llegó el 1 sep por la tarde y YA ESTÁ CONTESTADA — «las dos»:** el front pidió
> dos lecturas nuevas para la pantalla del boletín aparte **por estudiante**,
> `PUT boletin-independiente/marcados` (la lista del menú) y `PUT boletin-independiente/alumno` (el
> detalle), sin ninguna escritura. Se le pusieron a Joseth las tres opciones —sólo `marcados`, las
> dos, o ninguna hasta desplegar— y eligió **las dos**. **Escritas, con nueve tests de contrato en
> verde**: son la **548 y la 549**, y con ellas `CLAUDE.md` pasa a **549 rutas**. El diseño que llegó
> traía **cinco cosas que no cuadraban** —la primera pintaba de gris el caso del §9.1— y las cinco se
> corrigieron antes de escribir una línea: está en la **§13** del
> [19](19-boletin-independiente.md), y la respuesta al front en su canal
> (`myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, §C, `8myvc-2d`), con el contrato ya rehecho por
> ellos.
>
> > **Y la corrida completa de este commit NO se puede dar por medida, que es distinto de darla por
> > verde.** `php artisan test` sobre el árbol principal salió **`9 failed, 1736 passed`**, y los
> > nueve son de **otra sesión trabajando en el mismo árbol** (`8myvc-dc`, `GET colegio/logo`
> > pública): su ruta estaba en el router y no en los snapshots que yo acababa de regenerar. **Ni uno
> > es de estas dos rutas.** Lo que sí está medido sobre este árbol es lo propio: **63 tests del
> > boletín independiente en verde (540 aserciones), de ellos los 9 nuevos**, más `pint` sin
> > reescribir nada y larastan **`[OK] No errors`**. La cifra limpia de la suite entera **la dará
> > quien fusione el último**, que es quien tendrá el árbol final — hoy, `8myvc-2c` con
> > `feat/calendario`. *Una cifra de suite medida sobre un árbol con trabajo ajeno a medias describe
> > ese árbol, no este commit.*

> ### EL SEED, MEDIDO — y la frase que estuvo a punto de entrar aquí mal
>
> La base de tests tiene **68 alumnos y DOS grupos, de dos años distintos**: el **98** («Cuarto»,
> year 8) con los 68 y el **84** («Tercero», year 7) con 56 — **12 alumnos están sólo en el 98**.
> **Ninguna matrícula está borrada** (124 de 124 vivas), y los **40** son los alumnos con alguna
> matrícula en estado `MATR`.
>
> **Iba a escribirse «68 alumnos y los 68 en el mismo grupo», que es cierta de un grupo y falsa del
> seed** — y la conclusión que induce, *«el seed tiene un grupo»*, **ya costó 36 rutas sin medir**:
> el barrido pedía `grupo_id=0` dando por hecho que no había grupo ajeno, y boletines, planillas,
> observador, certificados y actas **de otro grupo** contestaban vacío sin medir nada, *«y un vacío
> se parece a un guard que funciona»*. Lo levantó `8myvc-e7` **midiendo**, y lo confirmó la
> coordinación antes de escribirlo.
>
> **Para quien busque «alguien de otro grupo»**: un `LIMIT 1` que devuelve `null` puede ser
> **población y no consulta**, y las dos trampas de en medio son `grupo_id != ?` —mete a quien está
> en los dos— y `NOT IN` con un `NULL` dentro, que no devuelve a nadie. Y ojo: **«matrícula viva»
> tiene aquí dos lecturas que dan 40 y 68**, y las dos son ciertas (§10quinquies).
>
> **La tercera mitad, que es la que se hereda:** el arreglo del lote F es bueno —fabricar el grupo
> ajeno es más robusto que depender de la población— pero **su razón escrita era más ancha que el
> dato**. *Un arreglo correcto con una razón demasiado ancha es peor que uno sin razón: el siguiente
> hereda la razón, no el arreglo, y la aplica donde no vale.*

> ### CINCO INSTRUMENTOS QUE FALLARON HOY, Y DOS SON DE LA COORDINACIÓN
>
> Los cinco dan el mismo error con caras distintas: **el instrumento con el que compruebas también se
> mueve**.
>
> 1. **Una rama leída diez minutos antes.** La coordinación le dijo al lote G que su commit ya estaba
>    fundido citando un `git branch -av` anterior a su último commit. **Una lectura de hace diez
>    minutos no describe una rama que otra sesión está moviendo.**
> 2. **Un hash anotado antes de un `--amend` ya no identifica ese commit.** El lote G se dio por
>    faltando un commit que estaba dentro con otro nombre. **Lo cazó tener dos instrumentos y que
>    discreparan**, y el que estaba mal era su lista, no `main`.
> 3. **Un `grep` anclado que dijo que la suite estaba muerta.** `grep -E "^Tests:"` sobre la salida de
>    esta suite **no devuelve nada**, porque PHPUnit indenta esa línea. Por el documento de esta
>    misma casa, *«una suite sin línea `Tests:` al final no es una suite verde: es una suite
>    muerta»* — y la suite estaba **verde**. **El ancla era mía, no de la suite.**
> 4. **Una herramienta que contesta una pregunta parecida a la que se le hizo.** La coordinación dio
>    por demostrado que el enlace nuevo de la §9.2 resuelve **porque `secciones-citadas.py` da 0
>    huérfanas**. Esa herramienta compara **§§ citadas *del código* contra §§ declaradas en `docs/`**
>    —lo dice su propia salida: *«§§ declaradas 529 · §§ distintas citadas del código 276»*—, así que
>    **un `](fichero.md)` de un documento a otro no entra en su población** y su `0` habría salido
>    igual con el enlace apuntando a un fichero inexistente. **La conclusión era cierta y la prueba no
>    la sostenía**, que es la forma más difícil de cazar porque **nada se pone rojo**. Lo comprobó
>    `8myvc-e7` resolviendo cada ruta contra el directorio del fichero que la cita: el de ida y los
>    seis de vuelta, los siete OK. **Y queda el hueco escrito: hoy nada comprueba que un enlace entre
>    documentos de `docs/` apunte a algo que existe.**
> 5. Y una quinta, del mismo día: **el `19` se está moviendo**, así que la cifra que no hay que tocar
>    —los «dieciséis números» de la fase 0 de definitivas— **cambió de la línea 1192 a la 1309**. En
>    un fichero vivo **se cita por contenido, nunca por número de línea**.

**Anterior: 31 ago 2026, noche — LOS CINCO LOTES FUNDIDOS: LAS FASES 1, 2, 3 Y 6 DEL
BOLETÍN INDEPENDIENTE ESTÁN EN `main`** · **`Tests: 1645 passed (12750 assertions)`, `exit=0`, 228
clases con veredicto, cero fallos** · pint **PASS** (329 ficheros) · larastan nivel 7 **`[OK]`** ·
**545 rutas** (una nueva: `PUT boletin-independiente/periodo`) · **sin subir y sin desplegar**

> **La fase 1 está cerrada, y con la cuenta que se puede repetir**: de los **23 sitios** que lista el
> detector, **cinco están decididos** —dos sellos de caché, un falso positivo con el alcance
> traspasado, uno ya acotado y código muerto— y los **18 de trabajo** se repartieron **4 del lote A +
> 7 del B + 7 del C**, sin sobrar ni faltar ninguno. **El criterio no es «0 en la columna»** —los
> cerrados *decidiendo no tocarlos* se quedan contados ahí— sino **cada fila acotada o con una
> decisión escrita**.
>
> **Fases 2, 3 y 6 también dentro.** La marca por periodo con su escritor y su guarda (D), las
> planillas normales sin los independientes (B) y los puestos con su interruptor (E). **Quedan la 4**
> —`planilla` y `copiar`— **y la 5**, los boletines probados en negativo.
>
> *(Las dos entraron el 1 sep 2026 y lo contesta el bloque de arriba. Se deja escrito porque **un
> pendiente en futuro no envejece a «hecho»**: sin esta línea, el párrafo seguiría pidiendo trabajo
> hecho.)*
>
> **Dos migraciones bloqueantes esperando** para la tanda siguiente: retirar
> `matriculas.boletin_independiente` y `years.puestos_con_bol_independiente`.

> **UNA ACCIÓN DEL DÍA DEL DESPLIEGUE QUE NO ES UNA TABLA: `tools/independientes-sin-estructura.php`
> SE CORRE QUINCE VECES, UNA POR COLEGIO.** Contesta la [§9.1](19-boletin-independiente.md) —qué
> pares (alumno, asignatura) están **marcados y no tienen ni una unidad propia**—, que es el riesgo
> grave del módulo y **el único que no avisa de ninguna forma**: sin estructura propia la definitiva
> sale **0**, el boletín en blanco, y nadie recibe un error.
>
> **Y hoy es peor que cuando se escribió el plan, medido por el lote F:** con la fase 1 fundida, un
> marcado sin unidades propias **ya ni siquiera aparece** en el informe de notas perdidas —la
> consulta pide `u.alumno_id <=> ALCANCE` y no empareja con ninguna fila—. Antes la pantalla le
> acusaba de perderlo todo; **ahora se lo calla**, y el alumno se cae del radar sin aviso. Esa
> herramienta es lo único que puede verlo.
>
> **Corrida hoy en desarrollo: cero marcados, cero pares revisados** — y eso es todo lo que afirma.
> *«Nadie está marcado en ningún colegio»* lo escribió la coordinación y **es una extrapolación de 1
> a 15, no una medición**: en este MySQL sólo viven `laravel` y `simonbolivar`, y los quince están en
> producción con la suya. Que ninguno pueda tener filas todavía **porque la ruta que marca no está
> desplegada** es un argumento correcto y **va dicho aparte del número**. La medición son **quince
> corridas el día del despliegue**, después de las migraciones.
>
> **Y sin la tabla contesta `exit=2 · NO CONCLUYENTE` a propósito**, diciendo que no ha revisado ni un
> par: un `0` limpio ahí sería **la respuesta que archiva el asunto justo en el colegio donde no se
> ha mirado nada** — que es la regla de la casa sobre las herramientas en el sitio donde de verdad
> muerde. *(Diseño y corrección: lote G.)*

> **Y LA PEOR DE LAS SEIS, ENCONTRADA AL FINAL: el detector que repartió la noche entera era
> justamente el que no comprobaba nadie.** `AutopruebasDeLasHerramientasTest` corre cinco
> herramientas y **`unidades-sin-alcance.py` no era una de ellas** — no tenía `--control` ni
> `--autoprueba` que registrar. O sea que la coordinación mandó *«corre las autopruebas con el
> detector cambiado»* creyendo que eso lo comprobaba, **y no comprobaba nada de esto**.
>
> **Y lo que hay que conservar no es que la coordinación afirmara una garantía sin mirarla: es que
> la §1.5 y un detector que cuenta de más forman un BUCLE CERRADO.** Sus cinco cegueras contaban
> **de más**, que es el error que no se delata solo — la lista gana sitios donde no hay nada, quien
> los revisa los cierra *«decidiendo no tocarlos»*, **y cada absorción parece trabajo bien hecho**.
> La regla que existe para no fiarse del instrumento es justo la que se traga sus falsos positivos,
> y el instrumento nunca queda mal.
>
> **La salida de ese bucle no es leer mejor las filas: es que el instrumento tenga un control que no
> dependa de las filas** *(formulación del lote G, que es quien lo escribió)*. Ya lo tiene:
> `--control` con **16 formas literales** *(eran 13 el 31 ago; las tres nuevas son las cegueras que
> cerró el lote G)*, registrado en el runner, y **medido desde tres sitios
> distintos —`/tmp`, la raíz y un worktree— con `exit=0` y el mismo `md5` en las tres salidas**. No
> abre ficheros, no llama a `git`, no mira el `cwd` y no toca la base, así que **no tiene desde dónde
> correr mal**: es la otra mitad de anclar formas en vez de un número, porque un número anclado al
> árbol hereda **todas las maneras que tiene un árbol de estar en otro estado** — el worktree, el
> clon del CI, el `cwd` de una shell. Las tres ya pagadas esta noche.
>
> **Y con él, la cifra de la fase 1 que este documento repitió tres veces era vieja.** «43 lecturas
> pendientes en 23 sitios» se midió **antes** de fundir A, B, C, D y E; sobre `main` con todo dentro
> y **antes de tocar el detector** ya eran **26 en 14**, y con tres cegueras cerradas, **21 en 9**.
> La coordinación la repitió sin remedirla después de cada fusión: **una cifra medida antes de cinco
> merges no describe el árbol de después**, y ninguna de las veces que se copió llevó delante la
> fecha de su medición.
>
> **Y el 1 sep 2026 le pasó otra vez, al propio párrafo de arriba: decía «21 en 9» y hoy son 20 en
> 8.** La secuencia entera es el dato, y sin ella el último paso se lee al revés:
>
> | | 43 en 23 | 26 en 14 | 21 en 9 | **20 en 8** |
> |---|---|---|---|---|
> | qué lo movió | — | **cinco merges** | tres cegueras del detector | **la sexta ceguera** (`332a37a`, el `IN`) |
>
> **Entre las dos últimas no se tocó ni una consulta: cambió el instrumento.** Escrito sin esa
> frase, «21 → 20» se lee como *«se acotó una más»*, que es exactamente lo contrario de lo que pasó.
> Medido hoy sobre `1cb7092`: `--control` **16 formas, 0 fallan**; `--csv`, **20 lecturas
> pendientes en 8 sitios**. Y las cegueras son **seis**, no tres.

> **EL ARREGLO QUE NADIE ENCARGÓ Y QUE ES EL MÁS CARO QUE SE EVITÓ: una memoria estática que
> contestaba lo de antes después de escribir.** `BoletinIndependiente::alcance()` memoiza en una
> propiedad `static` cuyo docblock dice «vive lo que vive la petición» — **cierto en producción, una
> petición un proceso; falso en la suite, donde un proceso son mil peticiones**. `DatabaseTransactions`
> deshace la base y **no deshace un `static`**.
>
> Se llegó a él persiguiendo **dos rojos de `BoletinesTest` que pasaban en aislamiento y fallaban
> dentro de la suite**, y que fallaban **rápido** —7,94 s frente a los 43,91 s que tardan cuando
> pasan—, *porque una instantánea que no cuadra falla antes de terminar de calcular*. La primera
> hipótesis fue **contención de cuatro suites contra el mismo MySQL**; se midió en la condición buena
> —dos suites, bases distintas—, **volvió a salir roja, y la sesión que la defendía la retiró ella
> misma**.
>
> **Y las dos mitades no son la misma cosa, que es lo que hace que valga:** vaciar las memorias entre
> tests es higiene y va en `CasoDeContrato::setUp()` —**las tres**: `BoletinIndependiente`,
> `EscalaDeNotas` y `NombreDelAlumno`—; **invalidar en quien escribe es producción**, porque la
> petición que cambia la respuesta no puede contestar con lo que cacheó antes, y la ruta de la fase 4
> **lee el alcance en la misma petición en que se puede haber escrito**. **Cerrado como *flaky*,
> habríamos fundido sin la invalidación y lo habría cobrado la fase 4, en una ruta nueva y con el
> front encima.**
>
> Y va en `CasoDeContrato` y no test a test **porque test a test ya se estaba haciendo y no escaló**:
> seis llamadas sueltas a `olvidar()` en dos clases más el helper `marcarIndependiente()` — quien lo
> escribió se topó con esto tres veces y lo resolvió a mano cada una, y aun así la fuga volvió a
> entrar por el camino nuevo de esta noche, **marcar por HTTP**, que no pasa por ese helper.

> **EL SEED DEL ROL `Secretario`: FUNDIDO Y REVERTIDO LA MISMA NOCHE, y las dos veces por decisión de
> Joseth.** Entró con una premisa que resultó falsa —«la rama `Secretario` de `esAdministrativo()` no
> la ejerce ni un test»: la ejercitan **seis o siete ficheros** que se fabrican el rol, y
> `SecretarioTest` está montado entero sobre ella— y al fundirlo **destapó su coste, que nadie había
> previsto**: tres rojos.
>
> **Uno de los tres no era un test roto: era un centinela disparando.** `LoQueDecideUnRolTest` lleva
> escrito que *«si alguien crea ese rol, este test se pone rojo. **Eso es lo que hace**: no impedirlo,
> avisar de que en ese momento cambia quién puede qué»* — y nombra la consecuencia:
> `Autoriza::esAdministrativo()` deja de ser `is_superuser` a secas, y con él cambian las escrituras
> de alumnos, las de acudientes y los tres `forcedelete`.
>
> **Se revirtió por lo que se supo después de decidirlo, no por lo que se sabía al encargarlo.** Y
> dos mediciones que el centinela no puede hacer de sí mismo, las dos del lote D: **el rol nace con
> cero personas**, así que `esAdministrativo()` habría seguido admitiendo exactamente a los diez
> `is_superuser` —el centinela afirma sobre la **existencia** y no sobre la población, o sea que su
> aviso es «esto ya puede cambiar», no «esto ha cambiado»—; y las dos instantáneas se movían por
> **ensanchamiento de tipo y no de contenido** (`description` y `display_name` de `null` a
> `null|string`), porque los once roles del seed los tienen a NULL y **la fila nueva llegaba con los
> dos rellenos**: con esos dos campos a NULL **no se habría movido ninguna instantánea**. Quien lo
> reintente tiene ahí la línea que hay que decidir a sabiendas en vez de heredarla.

**Anterior: 31 ago 2026, noche — LA NOCHE EN PARALELO DEL BOLETÍN INDEPENDIENTE, Y LA
CONTABILIDAD DE LA FASE 1 REMEDIDA** · cinco lotes en cinco árboles y cinco bases
([reparto](noche-2026-08-31/reparto.md)), coordinación traspasada de `8myvc-2a` a `8myvc-c1`
([traspaso](noche-2026-08-31/traspaso-coordinacion.md)) · **nada desplegado**

> ### 🔴 SI ERES UNA SESIÓN NUEVA Y VIENES A TRABAJAR EN ESTO, LEE PRIMERO [`noche-2026-08-31/estado-de-la-cola.md`](noche-2026-08-31/estado-de-la-cola.md)
>
> Dice **qué está fundido, qué está commiteado sin fundir y por qué, y las ocho cosas que faltan con
> su dueño y su dependencia**. Existe porque **las cinco sesiones de lote murieron a la vez y dos sin
> commitear**, así que hay trabajo a medias en cuatro ramas: **dos sesiones sobre el mismo fichero es
> lo que cuesta la noche entera**, y ése es el único motivo por el que la cola la reparte quien
> coordina y no se coge. Las reglas están en [reparto.md](noche-2026-08-31/reparto.md) —la **§1.7** y
> la **§1.8** cambiaron a mitad—. Cuando la cola se vacíe, ese documento se borra; éste no.

> **LA CIFRA DE LA FASE 1, MEDIDA POR LA COORDINACIÓN Y NO COPIADA — y esta vez el número aguantó.**
> Detector con el arreglo de `ce56351`, `--csv` desde la raíz con `main` en `5b79c42`: **43 lecturas
> pendientes en 23 sitios**. De esos 23, **cinco no son trabajo** —`selloDeVersion` y `estadoDelGrupo`
> (sellos de caché, decididos), `calcular` (el falso positivo del alcance traspasado), `recalcular`
> (ya acotado) y `NotaFinal:315` (código muerto)— así que quedan **18**, que es exactamente la cifra
> del traspaso. Y los 18 se reparten **4 del lote A + 7 de B + 7 de C**, sin sobrar ni faltar
> ninguno. La lista de la [§5 del 19](19-boletin-independiente.md) está vieja en dos filas
> (`NotaFinal:70` y las dos de `DefinitivasDeAsignatura` que ya se movieron); **el traspaso era el
> documento bueno**.
>
> **Y el criterio de terminación sigue sin poder llegar a 0, por una razón NUEVA.** Se corrigió de
> «0 sin alcance» a «0 en la columna *hay que acotarla*», pero **los sitios cerrados decidiendo no
> tocarlos se quedan contados en esa columna** —los dos sellos, el código muerto, los dos de C—, así
> que el 0 tampoco existe. El criterio que sí se puede cumplir es: **cada fila de esa columna está
> acotada o tiene una decisión escrita**. Es la tercera vez esta noche que un criterio nace
> inalcanzable, y las tres por la misma causa: *contar bien el síntoma no es haber contado la causa*.

> **TRES INSTRUMENTOS SE CAYERON, Y NINGUNO LO LEVANTÓ LA COORDINACIÓN.**
>
> **1 · PHPUNIT ZOMBIS EN EL CONTENEDOR, de los cinco lotes a la vez** (lo levantó `8myvc-8f`).
> **Matar un `docker exec` mata al cliente, no al proceso de dentro.** `ps` dentro del contenedor dio
> **trece phpunit vivos** —`a` ×3, `b` ×2, `c` ×2, `d` ×2, `e` ×2—, alguno con **33 minutos**. Dos
> suites contra la misma base dan `SQLSTATE[40001] 1213 Deadlock`, y **la pista es que los rojos
> cambian de sitio entre corridas**. La firma del zombi es **`ppid=1`** —el huérfano que adoptó init—
> y el `etimes` desempata:
>
> ```bash
> docker exec 8myvc-app-1 ps -eo pid,ppid,etimes,args | grep phpunit
> ```
>
> **El `ps` del host no ve dentro del contenedor**, así que comprobar ahí que el cliente murió mira
> justo donde el proceso ya no está: es lo que hizo dar por muerto un zombi que corrió 33 minutos. La
> forma que no los crea es lanzar la suite **desasida**, sin cliente que matar, y leer el fichero:
>
> ```bash
> docker exec -d -w /app/.worktrees/<x> -e DB_TEST_DATABASE=simonbolivar_testing_<x> \
>   8myvc-app-1 sh -c 'php artisan test > /tmp/suite-<x>.txt 2>&1; echo "exit=$?" >> /tmp/suite-<x>.txt'
> ```
>
> **Y el `exit=` se comprueba siempre, porque el de una tubería no es el de PHPUnit.** Dos lotes
> distintos dieron por buena una corrida con un `exit code 0` **que era el del `tail` del final**, y
> a uno el `tail` se comió además la línea `Tests:` — un verde sin cifra y sin haber medido nada.
> **Una suite sin línea `Tests:` al final no es una suite verde: es una suite muerta.**
>
> **2 · `Tests\Unit\AutopruebasDeLasHerramientasTest` NO PUEDE PASAR EN NINGÚN WORKTREE**, y no es
> de esta noche. Dice *«CONTROL NO CONCLUYENTE: no se pudo leer 2837171^ (¿worktree sin ese
> commit?)»* y **el paréntesis es la hipótesis equivocada, que es lo que invita a archivarlo**: el
> commit se lee sin problema. Lo que no funciona es **`git` dentro del contenedor** — un worktree no
> tiene `.git` de verdad, tiene un fichero que apunta a **una ruta del host** que dentro no existe, y
> el control se ejecuta desde el test. Falla igual en los cinco árboles y **no es un rojo de nadie**.
> Queda **sin arreglar y con la decisión dentro**: o el control se salta cuando `git` no resuelve, o
> `tools/worktree-de-sesion.sh` monta el gitdir de forma que el contenedor lo vea.
>
> **3 · TERCERA CEGUERA DEL DETECTOR DE ALCANCE** (medida por la coordinación; la sospechó el lote C
> desde el otro lado). Su aviso *«1 consulta compara `alumno_id` SIN ALIAS uniendo `unidades`: son un
> 500 (1052 ambiguous)»* apunta a `DefinitivasDeAsignatura:910`, que es `porcentajeDeLasUnidades`:
> **un `SELECT` de una sola tabla, sin un solo `JOIN`**. No puede ser ambiguo y no es un 500 — cuenta
> las desnudas **sin mirar si hay más de una tabla en el ámbito**, y le dice «esto es un 500» al
> sitio bandera de la noche, que está bien. La de `ce56351` era otra (el literal partido por la
> concatenación). **Van tres.**
>
> **Y EL CUARTO INSTRUMENTO NO ERA UNA HERRAMIENTA: ERA LA SHELL.** La coordinación hizo `cd` al
> árbol de un lote para verificarlo y **el directorio de trabajo persiste entre comandos**, así que
> tres comandos después escribió `ESTADO-ACTUAL.md` **en el árbol de ese lote** creyéndose en la
> raíz — y al commitearlo, el `git commit` se llevó **los doce ficheros que el lote tenía staged**,
> bajo un mensaje ajeno. Deshecho con `reset --soft` tras comprobar que la rama no se había movido,
> y **contado al lote antes de que lo viera él**, que es lo que lo convierte en un incidente y no en
> un misterio del día siguiente. Desde entonces, **rutas absolutas**.
>
> **La forma de la trampa es la misma que la del `ps` que no ve dentro del contenedor, y merece
> nombre propio: estado que persiste donde no lo estás mirando.** Los dos instrumentos contestaron
> con la cara de lo correcto — el `ps` del host dijo «muerto» de un proceso vivo, el prompt dijo
> «raíz» de un árbol ajeno—. Es lo que el `CLAUDE.md` lleva describiendo de las herramientas, sólo
> que aquí el instrumento era **el entorno**, que es el que nadie audita porque no se llama a sí
> mismo herramienta. *(Formulación del lote D.)*
>
> **Y una corrección, porque el propio incidente produjo una cifra falsa en la dirección contraria:**
> se dijo que `CensoDeInterruptoresTest` y `AutopruebasDeLasHerramientasTest` **leen
> `docs/migracion/`**, y **no lo hacen** — medido, no releído. El censo recorre `app`, `routes`,
> `config` y `database/seeders` más el volcado del esquema; las autopruebas ejecutan
> `secciones-citadas.py --autoprueba`, y **ahí la comprobación fácil se queda corta**: ese modo no
> sólo evalúa las cadenas trampa inyectadas, también llama a `citadas()`, **que sí recorre un árbol**
> — pero el que recorre es `CODIGO = ('app', 'tests', 'tools', 'routes', 'config', 'database')`, y
> `DOCS`, definido en la línea de al lado, **no lo usa nunca**. O sea que la conclusión aguanta por
> el camino que faltaba mirar: no es que la autoprueba no lea nada, es que **lo que lee no es
> `docs/`**. *(Ese último paso lo cerró el lote D, sobre una verificación de la coordinación que se
> había parado un paso antes.)* **Ningún test de la suite lee `docs/migracion/`.** El `grep` que decía lo
> contrario acertaba en las líneas —cuatro— y **las cuatro eran comentarios**: el síntoma bien
> contado y la causa no. Lo que sí estuvo bien fue **relanzar la suite en vez de suponer**: la duda
> era legítima aunque la cifra que la sostenía fuera falsa.
>
> El resumen que dejó `8myvc-8f` sobre sí mismo es el que hay que conservar: **de cuatro números que
> sacó con instrumentos esa noche, cuatro nacieron mal y tres le habrían hecho actuar** — un regex
> que se comía las líneas alineadas con tabuladores (50 columnas en vez de 60), el volcado congelado
> de `mysql-schema.sql` con **64 columnas de `years` cuando la tabla viva tiene 68** (las cuatro de
> diferencia son justo las que entraron por migración, o sea que **medir contra el volcado es medir
> contra el sitio donde ninguna candidata existe**), el `exit=0` del `tail`, y el paréntesis de
> arriba. **Los cuatro eran creíbles.**

> **DOS FORMAS NUEVAS DE FALLAR QUE ENTRAN EN LAS REGLAS DE LA NOCHE, las dos levantadas por lotes.**
>
> **El escenario equilibrado** (lote A, [reparto §1.4](noche-2026-08-31/reparto.md)). Su test estaba
> escrito **antes** del arreglo, como manda la regla, y **pasaba en verde con la forma ingenua**: su
> caso tenía «las del grupo» y «la suya» valiendo **las dos 1**, así que contar las contrarias daba
> el mismo número. Al desequilibrarlo —dos del grupo, una propia— el control se puso rojo. **Escribir
> el test primero no basta: la regla se cumple ejecutándolo contra la forma mala**, y al montar el
> caso los dos lados tienen que dar números distintos. Su variante, del lote D: el sujeto del test se
> monta **sobre la misma fila**, cambiando sólo `role_user`, porque con dos personas distintas el
> test demostraría que dos personas se comportan distinto.
>
> **Un `=` que es correcto** (lote D, [reparto §1.6](noche-2026-08-31/reparto.md)). La regla decía
> «`<=>` y NUNCA `=`». Vale para *«¿qué unidades le tocan a este alumno?»*; **no vale para «¿tiene
> alguna unidad SUYA?»**, que es un `EXISTS`: con `<=>` el alumno normal empareja con las del grupo y
> el campo saldría **`true` para los treinta**, con lo que el badge de la planilla dejaría de
> distinguir nada. **El detector lo señala igual**, porque cuenta la forma y no la pregunta.

> **HALLAZGOS QUE NO BUSCABA NADIE, y ninguno es de esta noche:**
>
> - **El rol `Secretario` no está en la base de tests** (lote D): su migración lo inserta y
>   `test-seed.sql` hace **`TRUNCATE TABLE roles`** a continuación, así que quedan **once** roles y
>   sin él. **Ese hecho es cierto. La consecuencia que se le colgó NO lo es, y la corrigió el lote A
>   yendo a mirar** — es la quinta cifra de la noche que nace mal, y la única que llegó a este
>   documento antes de caerse.
>
>   Se dijo que **la rama `Secretario` de `Autoriza::esAdministrativo()` no la ejerce ni un test**, y
>   sí la ejerce: `SecretarioTest` **entero** está montado sobre ella —`test_un_administrativo_sin_superusuario_crea_acudientes_solo_con_el_rol`
>   coge un `Usuario` con `is_superuser = 0`, comprueba 403 sin el rol, lo inserta y comprueba 200
>   con él: **misma fila, sólo cambia `role_user`**—. Y tampoco se había «rodeado sin nombrar la
>   causa»: **la causa está escrita en su propio docblock**, *«por qué cada test se fabrica su
>   Secretario: el seed se genera desde la base»*. Y no eran «dos rodeos»: medido sobre los
>   **240 ficheros `.php` de `tests/`**, **doce nombran el rol** y **seis o siete se lo
>   fabrican**.
>
>   **El error de origen NO fue leer mal un instrumento — fue no usar ninguno, y lo precisó la
>   propia sesión que lo cometió.** No hubo `grep` mal leído aquí *(ése fue su otro error de la
>   noche, el de `docs/`)*: hubo una consulta a la base que estaba **bien** —once roles, sin
>   `Secretario`— y un salto desde ahí a **una afirmación sobre la cobertura de la suite sin
>   buscarla**, generalizando desde la única muestra que conocía por casualidad. `grep 'Secretario'
>   tests/` son cuatro segundos y contesta la pregunta entera. **De las seis cifras malas de esta
>   noche, es la única que no se explica por un instrumento que engaña: el fallo no fue medir mal,
>   fue no medir y sonar igual de seguro.**
>
>   **Y lo que la medición buena destapa es mejor que lo que se buscaba: el arreglo no es durable.**
>   `test-seed.sql` **lo genera `tools/generar-seed-test.php` desde una base real**, y una base real
>   tiene once roles porque el doce lo pone una migración: **quien regenere el volcado vuelve a
>   dejarlo en once y la fila se va sin que falle nada**. O sea que el truncado es deliberado y está
>   documentado en las dos migraciones de datos afectadas —la del rol y la de
>   `create_permiso_can_view_auditoria`, que cita a la primera como precedente—, y **fabricarse el
>   rol dentro de la transacción es justo lo que hace a esos tests inmunes a una regeneración**.
>
>   **Joseth decidió arreglarlo igual**, con la alternativa de no tocarlo delante y planteada por el
>   lote A. Entra con un test que se pone rojo si una regeneración se lleva la fila, y **los dos
>   rodeos se quedan**, con el porqué escrito: quitarlos los dejaría colgando de una fila que
>   `generar-seed-test.php` se lleva sin avisar. **`can_view_auditoria` y sus dos filas de
>   `permission_role` NO se tocan** —mismo truncado, misma decisión documentada— y quedan anotadas
>   aquí en vez de arregladas sobre una premisa que ya se cayó.
> - **El interruptor nuevo de puestos no sobrevive al cambio de año** (lote E).
>   `YearsController:158` copia del año anterior **60 de las 68 columnas vivas** de `years`, y
>   `puestos_con_bol_independiente` no está: **el colegio que lo ponga a 0 lo recupera a 1 al crear
>   el año siguiente, en silencio**, y sus dos vecinas de esa lista —`mostrar_puesto_boletin` y
>   `puestos_alfabeticamente`— **sí** se copian, así que quien lea el bloque leerá que los tres se
>   comportan igual. Va al lote E en commit aparte. **El patrón importa más que la columna: el commit
>   del 30 ago cerró esa lista tal como estaba ese día y nada la mantiene cerrada** — de las cuatro
>   columnas que han entrado a `years` por migración desde el volcado, **dos se acordaron de la lista
>   y dos no**.
> - **`firmantes_acta` NO se hereda, y es decisión de Joseth (31 ago 2026)**, no un olvido de esa
>   lista. **Los firmantes se confirman cada año a propósito**: nacer en blanco obliga a que alguien
>   vuelva a poner quién firma, y **un acta firmada por quien ya no está es peor que un acta sin
>   firmantes** — el hueco se ve la primera vez que alguien imprime. No se toca, y el día que se
>   escriba un centinela de esa lista, ésta es la primera excepción con su porqué.
> - **`mostrar_puesto_boletin` no tiene ni un lector en el backend** (lote E, medido): 6 líneas en 3
>   ficheros de los 225 de `app/`, y ninguna se bifurca con ella — se transporta, se copia al crear
>   un año y se escribe desde una ruta. Con eso queda contestada **la pregunta del front del 24 ago**
>   sobre el choque de dos columnas de puestos en `years`: **son dos capas distintas**, la nueva
>   decide **quién entra en el recuento** y la vieja **si el puesto se pinta**, y no pueden
>   contradecirse dentro de `app/` porque sólo una se consulta aquí. **La precedencia sólo se puede
>   hacer cumplir en el front**, y que en el backend no haya nada escrito para ella **no es un
>   olvido**: sería un segundo sitio decidiendo lo mismo.
> - **`ContextoDeUsuario:113` pone `mostrar_puesto_boletin` sólo en la rama `Profesor`** (lote E),
>   no en las otras tres. Familia del §140 —`year_pasado_en_bol` que le faltaba a `Acudiente` y daba
>   500—. Preexistente y anotado, sin tocar.
> - **`composer run pint` no cubre `app/Http/Controllers/`** (lote D), así que el controlador nuevo
>   del boletín independiente **no lo formatea**. Comprobado a mano con `pint --test`: PASS. Meterlo
>   en el ámbito arrastraría los otros 112 de golpe, que es lo que la regla de la casa evita.

> **DÓNDE ESTÁ CADA LOTE — y NADA está fundido.** `main` no se ha movido más que por documentación.
>
> | Lote | Qué lleva | Estado |
> |---|---|---|
> | **A** `8myvc-5e` | los dos `Bolfinales` + el `puesto: null` | 4 sitios de alcance cerrados con `alcanceCorrelacionado`; los llamadores de puestos van en **commit aparte**, rojo a propósito hasta que E esté en `main` |
> | **B** `8myvc-cf` | planilla, unidades, subunidades, `Nota.php` + fase 3 | **es el que cierra la fase 1**: sus 7 sitios son los últimos |
> | **C** `8myvc-53` | `putCopiar`, `Unidad` y los sueltos | 7 cerrados: 5 acotados y **2 razonados sin tocar** |
> | **D** `8myvc-82` | la marca: ruta 545, guarda, los dos campos | entregado y verificado; **es el que desbloquea al front** |
> | **E** `8myvc-8f` | puestos e interruptor (fase 6) | entregado y verificado; `ponerPuestos()` desbloqueó a A |
>
> **`putCopiar` eran DOS fallos, no uno** (lote C), y el segundo **el detector no podía señalarlo
> porque no hay ningún `SELECT` implicado**: `new Unidad` no tocaba `alumno_id`, así que **una unidad
> con dueño se copiaba como una del grupo** — el reparto de porcentajes de un solo alumno pasaba a
> ser el de los treinta. Es el argumento de por qué esa lista **ordena candidatos y no lista fallos**.
>
> **La verificación antes de fundir, que es la lección del traspaso:** `git diff --stat` **contra la
> base común** (`$(git merge-base main fix/bi-lote-<x>)`) y no contra `main` a dos puntos —que
> enseña como borrados los ficheros que sólo existen en `main` y parece que el lote borró el
> traspaso—; cero instantáneas salvo donde es legítimo, mirando el diff; y **la suite entera después
> de cada fusión**, no sólo la del lote.
>
> **Y un aviso para cuando se mire el diff de la cola:** `familias-que-nunca-entran-en-el-candado.json`
> ganó `"boletin-independiente": "1 de 1"` con la ruta de D —legítimo: esa instantánea lista las
> familias con **menos de dos** rutas con guard, y una familia de una ruta no puede establecer la
> costumbre que el candado comprueba— y **volverá a moverse en dirección contraria**, desapareciendo,
> cuando la cola añada `planilla` y `copiar` y la familia pase a `3 de 3`. **No es un guard que
> alguien quitó.**

**Anterior: 31 ago 2026, noche — LA MARCA DEL BOLETÍN INDEPENDIENTE PASA A SER POR
PERIODO, Y `matriculas.boletin_independiente` SE RETIRA** · las **tres decisiones de Joseth** las
tomó en la sesión del front `myvc-front-c5` y la 7 revisa la 2 del 24 ago: *«a veces el estudiante
tuvo un periodo normal y en el segundo un accidente … no se le puede borrar el boletín del primero,
**tienen que convivir**»* ([19 §2.1](19-boletin-independiente.md)) · **el arreglo era un carácter**,
`COALESCE(bip.aplica, 1)` → `COALESCE(bip.aplica, 0)`: fila ausente pasa de significar «lo que diga
la matrícula» a «va con el grupo», y con el default viejo **marcar a un alumno en octubre le
repintaba el boletín del primer periodo** · **la tabla estaba bien; el sentido del default estaba al
revés** · `2026_08_31_100000_retirar_boletin_independiente_de_matriculas`

> **ES UNA MIGRACIÓN BLOQUEANTE MÁS PARA LA TANDA SIGUIENTE**, y no hay que apuntarla a mano en
> `DESPLIEGUE.md`: esa tabla se remide con el comando el día del despliegue, que es la regla que ya
> está escrita ahí. Lo que sí hay que llevar delante ese día es que **`DROP COLUMN` sea `INSTANT` en
> los quince** — medido aquí en 15,2 ms sobre MySQL 8.0.42, y **la versión de los quince cPanel no la
> conocemos**. El peor caso es reconstruir una tabla de 0,4 MB, así que no es bloqueo: es una cifra
> que hay que mirar y no suponer.

> **LA PREGUNTA QUE ERA NUESTRA Y ESTÁ CONTESTADA: la columna se retira, no se queda de espejo.**
> El front pedía una sola fuente y tenía razón, pero midiéndolo salió mejor de lo que su argumento
> decía. La columna vivía en `matriculas`, que **no tiene clave única sobre (alumno, año)**: es
> literalmente la [§9.5](19-boletin-independiente.md) —la ficha lee una matrícula y el guardado
> escribe otra—. `bol_ind_periodos` cuelga de `(alumno_id, periodo_id)` **con clave única**, así que
> **la §9.5 deja de existir para esta marca** (sigue viva para `repitente`, `promovido` y
> `nro_folio`).
>
> **Y se llevó por delante treinta líneas de SQL que sólo estaban para adivinar una fila.**
> `alcanceCorrelacionado()` entraba por `periodos`, bajaba a `grupos` del mismo `year_id`, unía
> `matriculas` y desempataba con `ORDER BY created_at DESC, id DESC LIMIT 1` — un `LIMIT 1` que era
> una degradación consciente, «una de las dos» en vez de reventar. **Hoy son cuatro líneas**: un
> `SELECT` sobre `bol_ind_periodos`. Un periodo pertenece a un año y sólo a uno, así que **el año se
> hereda en vez de derivarse**, y de paso el `LEFT JOIN` de `JOIN_ESTADO` deja de poder duplicar una
> fila.
>
> **Quitar una columna de producción no movió una sola instantánea, y eso NO fue suerte.** La
> migración del esqueleto es **anterior a `eb95cbc`** —comprobado con `git merge-base
> --is-ancestor`—, o sea que la columna lleva desplegada en los quince desde antes de la tanda del
> 25–30. Lo que la hace inocua de quitar es el trabajo defensivo del **24 ago**: los cuatro sitios
> que hacían `SELECT *` sobre `matriculas` se pasaron a columnas nombradas para que la columna nueva
> no se colara, y **ninguna de esas cuatro listas la nombra**. Se pagó para que añadirla no moviera
> nada y se cobra hoy para que quitarla tampoco. Los cuatro comentarios están actualizados: **la
> regla no caduca con la columna**, la próxima que se añada a `matriculas` entra por `*` igual de
> callada.
>
> **El coste medido, no supuesto:** `DROP COLUMN` con `ALGORITHM=INSTANT` sobre una copia real de
> `matriculas` (**3.542 filas, 0,4 MB**, MySQL **8.0.42**) tarda **15,2 ms** y no reconstruye la
> tabla. Lo que no sabemos es la versión de MySQL de los quince cPanel; el peor caso es reconstruir
> 0,4 MB.
>
> **EL TEST QUE NO EXISTÍA Y ES EL QUE IMPORTA: `test_marcar_un_periodo_no_toca_el_alcance_de_los_demas`.**
> Marca el periodo 2 y comprueba que los otros tres siguen yendo con el grupo. **Se pone rojo con ese
> solo carácter de vuelta**, y no había nada que lo cazara: con nadie marcado, el default bueno y el
> malo dan el mismo verde. Los nueve ficheros de test que montaban la marca con
> `UPDATE matriculas SET boletin_independiente = 1` pasan por un helper único,
> `CasoDeContrato::marcarIndependiente($alumno, $periodo)` — un test que siguiera escribiendo la
> columna no fallaría de forma útil: **montaría un escenario que ya no existe**.

> **Y LO SEGUNDO, QUE ES DE MÉTODO Y VALE MÁS QUE EL ARREGLO: «0 sin alcance» era un criterio
> inalcanzable.** La fase 1 decía que termina cuando `tools/unidades-sin-alcance.py` diga **0 sin
> alcance**. Corrido hoy dice **72 de 78** y **62 de 72**, y el mensaje del front lo leyó como «queda
> eso por hacer». Las dos cifras son ciertas y juntas engañan: **84 de esas lecturas entran por
> `unidad_id` o por una nota y NUNCA van a nombrar `alumno_id`** —el id ya es de su dueño, la
> consulta no elige nada—, así que el detector no puede llegar a 0 y la fase 1 no podría darse por
> terminada jamás.
>
> **La población real de la fase 1 son 29 sitios**, no 134: 60 lecturas «hay que acotarla» sin
> acotar, y una misma consulta cuenta una vez por tabla y por `join` —`selloDeVersion` sale cinco
> veces y es un método—. El criterio corregido es **0 en la columna «hay que acotarla»**, y los 29
> están listados uno a uno en la [§5](19-boletin-independiente.md).
>
> **Es la regla del `CLAUDE.md` en su forma que muerde**, otra vez y en un sitio nuevo: *contar bien
> el síntoma no es haber contado la causa*. El detector no está mal — **contesta otra pregunta**, y
> era el plan quien le pedía la cifra de la columna equivocada. **Y hay un falso positivo demostrado
> dentro de la propia lista**, que sirve de patrón para las otras 28: `DefinitivasDeAsignatura::calcular`
> sale como «sin alcance» **y está acotada** — su `u` vive dentro de una derivada y la comparación
> ocurre fuera, en `c.dueno <=> ALCANCE`. **Antes de tocar una fila de esa lista se mira si ya hay un
> test que la cubra.**

> **LO QUE ENCONTRÉ Y NO ESTABA EN EL ENCARGO — es de la fase 2 y hoy es invisible por población.**
> La [§9.3](19-boletin-independiente.md) dice que `PUT boletin-independiente/periodo` **crea las notas
> que falten** al APAGAR la marca, para que el alumno no vuelva a la planilla sin casillas. Ese
> sembrado pasa por `Nota::verificarCrearNotas` → `quienCreaLasNotas` → `User::permiteEditarNotas`,
> que termina en `is_superuser || tipo == 'Profesor'`. **Un secretario o un rector que no sean
> superusuarios reciben `false` — también con el periodo ABIERTO**: la gente que la decisión 5 puso a
> cargo es exactamente la que no siembra nada, en silencio, y desde Flutter esa ventana dura días
> porque esa app no llama a `/notas` nunca.
>
> **Hoy funcionaría por coincidencia de población, que es la forma exacta del paso 0 de
> `DESPLIEGUE.md`**: en `simonbolivar` los roles `Rector` (#10) y `Secretario` (#12) existen y tienen
> **cero personas**, y los diez `Admin` son los diez `is_superuser`. El colegio que le dé el rol a un
> secretario de verdad es el que lo descubre. **La recomendación está escrita en la §2.4**: ese
> sembrado no debe preguntar `permiteEditarNotas`, porque la pregunta es otra.
>
> **Y la guarda de la decisión 5 no se puede escribir con los nombres del mensaje:**
> `Role::hasRoleOrPerm` es del **front** — en este backend aparece en cinco comentarios y en ninguna
> línea de código. Va como método nuevo de `Autoriza`, y **no reutilizando `esAdministrativo`**, que
> es `is_superuser || Secretario` y **no incluye el rol `Admin`** al que la decisión 5 nombra
> explícitamente.

> **Y UNA SEGUNDA VUELTA LA MISMA NOCHE: COPIAR TIENE DOS ORÍGENES, NO UNO.** Encargo de Joseth por
> la misma sesión del front — *«que se puedan copiar unidades/subunidades tanto de otro boletín que se
> le creó de manera independiente a otro estudiante como de las unidades/sub específicas de
> asignaturas en algún periodo»*. La [§6.2](19-boletin-independiente.md) tenía **un solo origen
> implícito** —otro alumno, misma asignatura, mismo periodo— y **el caso normal no cabía**: el
> estudiante que vuelve y sigue el plan del curso, copiando del periodo que sí está montado.
> Reescrita entera; **es contrato, no código: la ruta es de la fase 4 y la fase 1 sigue abierta.**
>
> **Los dos orígenes se leen con alcances CONTRARIOS** —`u.alumno_id IS NULL` para el grupo,
> `= origen.alumno_id` para el alumno— y ésa es la trampa que no se ve en el JSON: un `=` copiado a
> la rama del grupo devuelve cero filas y **copia una estructura vacía en 200**.
>
> **Las tres preguntas del front, contestadas midiendo:**
>
>   1. **Sólo la misma asignatura**, con 422. `asignaturas` es `(materia_id, grupo_id)` y **no tiene
>      `periodo_id`**, así que «otro periodo» ya cabe sin abrir nada; lo que un `origen.asignatura_id`
>      abriría es **otra materia o, peor, otro grupo** — un id del cuerpo que no comprueba nadie. Y
>      **esa puerta ya existe y es otra**: `PUT periodos/copiar`. Dos puertas para la misma operación
>      con reglas distintas es de donde salió el recalculador único.
>   2. **`si_ya_tiene`: `saltar` (defecto) · `anadir` · `reemplazar`** — y aquí va **una corrección al
>      aviso que el front iba a pintar.** `reemplazar` **no borra ni una nota**: medido en
>      `UnidadesController::deleteDestroy`, retirar una unidad es un borrado en blando **de la unidad
>      y de nada más**; subunidades y notas se quedan con `deleted_at` a null y salen de los cálculos
>      sólo porque cada lectura une `u.deleted_at IS NULL`. **`PUT unidades/restore/{id}` la devuelve
>      entera con sus notas dentro.** Por eso el campo es `notas_que_dejan_de_contar` y no
>      `notas_borradas`: *«se borrarán 9 notas»* es **falso**, y asusta de una forma que hace que el
>      docente no use el botón.
>   3. **La suma resultante viaja por destino**, con el mismo nombre que ya usa la planilla
>      (`porcentaje_unidades`) y **sin corregirse**, que es la regla del [10 §9.3](10-definitivas.md).
>
> **Y una que ellos no preguntaron y hay que prohibir: `con_notas` con el periodo de origen distinto
> del de destino → 422.** Copiar la estructura del periodo 1 al 3 es preparar la planilla; copiar
> también las notas es **escribir en el 3 las calificaciones del 1**. Desde la pantalla las dos
> casillas parecen igual de inocentes, así que **no lo puede decidir el navegador**.
>
> **EL FRONT CORRIGIÓ LA §6.3 Y TENÍAN RAZÓN: `periodo_id` va en el CUERPO.** Decía «el periodo es el
> del usuario», copiado de `notas/detailed`, y con esa forma la pantalla 1 **no puede marcar el
> periodo del accidente**: el del token es el activo. Un backend que lo sacara del token marcaría
> **siempre el activo, en silencio y con 200**. Con el cuerpo entra una guarda que antes no hacía
> falta —la familia de `identificadores-del-cuerpo.py`—: que el periodo sea de un año sobre el que se
> puede actuar, y que **el alumno esté matriculado en el año de ese periodo**. La clave foránea no lo
> obliga, y `consultar()` **ya no lo comprueba a propósito** (§2.2).

> **Y LA FASE 1 ARRANCA: 29 → 28 SITIOS, y los dos primeros eran los que más dolían.**
>
> **(1) `porcentajeDeLasUnidades` sale del grupo `rojo` y entra en la suite.** Era **el único de la
> lista donde acotar no era añadir una condición**: contestaba *«¿las unidades de esta asignatura
> suman 100?»* devolviendo un `float`, y con dos boletines esa pregunta **no tiene una sola
> respuesta** — sumaba el reparto del grupo y el de cada marcado y daba **un número que no era el de
> ninguno**. Llevaba el rojo puesto desde el 25 ago esperando *«las dos preguntas del 19 §2, que son
> de Joseth»*; contestadas esta misma noche, **el bloqueo se levantó y el rojo se cobró**, que es
> exactamente para lo que un rojo a propósito existe: ser la red del arreglo y no una queja
> archivada. Ahora recibe `?int $alcance` **sin defecto** — un defecto habría dejado a los llamadores
> viejos compilando y cambiándoles el significado en silencio.
>
> **(2) La guarda «sin unidades no se escribe» del 28 ago contestaba la pregunta de otro, en las dos
> direcciones.** Era un `EXISTS` sobre la asignatura entera, exacto mientras cada asignatura tuviera
> un solo reparto. Con dos boletines: si **el grupo** no tiene unidades y sí un independiente,
> `hay = 1` y a los del grupo se les escribe **el cero que esa guarda existe para no escribir** —el
> fallo del 28 ago entrando otra vez por una puerta nueva, **67 definitivas** al reproducirlo sobre el
> seed—; y si es **el marcado** quien no tiene nada suyo, se escribe **su** cero, que es la §9.1 con
> cara de nota. Ahora la pregunta es **por dueño**, con una consulta y sin una por alumno:
> `calcular()` devuelve además el `dueno` de cada fila.
>
> **Los dos casos son inalcanzables con nadie marcado** —«el boletín del grupo tiene unidades» y «la
> asignatura tiene unidades» son la misma frase—, así que la suite entera no podía verlos.
> `PuertaSinUnidadesPorBoletinTest` los **construye**, y se comprobó **en rojo contra la puerta vieja
> antes de darlo por bueno**: un test escrito después del arreglo no comprueba el arreglo.
>
> **SEGUNDA TANDA DE LA FASE 1, la misma noche: 29 → 22 pendientes de verdad.**
>
> **(3) `NotaFinal::consultaAlumnosGrupoNotaFinal`, la consulta de la pantalla de definitivas por
> periodo.** Sus cuatro derivadas —una por periodo— le sumaban a un alumno marcado **las notas que
> conserva en las subunidades del grupo** —que marcar **no borra**, y eso es la petición literal del
> colegio— **más** las de sus unidades propias. Es la forma «de más» de la §9.2 y sale por pantalla
> de la peor manera: la columna «automática» inflada **al lado de la guardada, que es la correcta**,
> o sea acusando de estar mal a la que está bien; quien pulse «actualizar» ahí guarda el número
> inflado. **Era una propiedad estática y pasó a método**: PHP no admite una llamada en el
> inicializador de una propiedad, así que la forma vieja era el techo y no una preferencia.
>
> **(4) Tres cerrados leyéndolos y NO tocándolos, que también es cerrarlos.** `selloDeVersion` y
> `estadoDelGrupo` son **sellos de caché**: sobre-aproximar les hace recalcular de más —cuesta
> tiempo, nunca sirve un dato viejo— y **acotarlos les haría servir un dato viejo sin un error en el
> log**. Ahí el criterio del lote es el que mete el fallo, y el porqué ya vivía en el propio código.
> `NotaFinal::calcularAsignaturaPeriodo` es **código muerto** —cero llamadores en todo `app/`— y no
> se acota código muerto; pero **escribe definitivas**, así que lleva un aviso de que resucitarlo sin
> alcance guardaría los dos repartos sumados.
>
> **LA CONTABILIDAD, con la distinción que se pierde al copiar una cifra.** De los 29 originales:
> **7 resueltos** —4 acotados, 2 leídos y descartados a propósito, 1 muerto y anotado— y **22
> pendientes de verdad**. El detector dice **23**, porque sigue contando el muerto, y **27** en
> total, porque sigue contando los cuatro de `DefinitivasDeAsignatura` ya decididos. **Ninguna de las
> tres está mal: contestan preguntas distintas**, y por eso van las tres escritas en la §5. *(Y una
> corrección: a `myvc-front-c5` se le dijo «cinco cerrados y quedan 23», que no cuadraba con la
> propia lista. Son siete y veintidós.)*
>
> **UN HUECO DE CONTRATO QUE CAZÓ EL FRONT, y es de los buenos.** La §6.4 decía que el badge de la
> planilla era `alumno.bol_independiente_periodo`. Pero a `alumnos` sólo llegan los que van **con el
> grupo**, así que ese campo **no es ambiguo: es constante `false`** en las treinta filas, siempre.
> Un campo que no varía no es un campo pobre — es uno sobre el que alguien ramificará sin que su rama
> muerta se note nunca. Entra **`bol_independiente_datos`** con nombre propio. **Y la misma medicina
> destapa un segundo que nadie había mirado**: `aplica` dentro de `independientes` es `true` **por
> construcción**. Los dos son restos del modelo por año que la decisión 7 eliminó.
>
> **LO QUE SIGUE SIN HACERSE, Y NO ES UN OLVIDO: los 22 sitios restantes.** Es el trabajo de verdad
> que queda. Lo que hay es **la lista medida con nombre y línea**, el criterio de terminación
> corregido —«0 en la columna *hay que acotarla*», no «0 sin alcance»— y el patrón de falso positivo,
> para que el siguiente los cierre sin volver a medir. **Sin fase 1 no hay fase 2, y sin fase 2 no hay
> nada que escriba la marca.**
>
> **Y el front ya escribió las pantallas 1 y 3 contra estos nombres**, en verde y escondidas: la
> pestaña sólo existe si el campo viene, así que donde no esté desplegado no hay nada que pulsar. Su
> §B.6.5 pidió `estructura_del_grupo` en la §6.1 y **entra**, porque la alternativa está envenenada:
> `GET unidades/de-asignatura-periodo` **escribe** —inserta las unidades y subunidades por defecto del
> año, **sin `alumno_id`, o sea del grupo**, y `Unidad::arreglarOrden` reescribe `orden` en cada
> lectura—, así que usarla de vista previa montaría el periodo entero del curso. **Esa ruta no se
> cambia**: que lea y escriba es decisión tomada (05 §47.2) y con el periodo abierto crea queriendo;
> lo que se arregla es que el front no tenga que llamarla. *(De paso: el `$orden_duplicado` que ese
> método calcula veinte líneas antes **no lo lee nadie** — variable muerta dentro de un método que
> escribe.)*
>
> ## ✅ VERDE: 1.586 pruebas MÍAS de 1.590 · pint PASS · larastan nivel 7 `[OK]`
>
> **El desglose, que es lo que hace que el número esté medido y no copiado:** 1.578 eran el 30 ago;
> **+1** el test de la decisión 7; **+3** `PorcentajeDeUnidadesConIndependienteTest`, que estaba
> excluido por el grupo `rojo` y ahora corre; **+2** `PuertaSinUnidadesPorBoletinTest`; **+2** `DefinitivaAutomaticaDeLaPantallaTest`. Suman **1.586**.
>
> **Los otros 4 hasta 1.590 NO son míos**: son `AlumnosDetrasDelNumeroTest`, de la sesión que trabaja
> en paralelo en este mismo árbol y que todavía no ha comiteado. Se dice porque el número de la suite
> **ya no es atribuible a una sola sesión** mientras dos trabajen sobre el mismo `tests/`, y sumarlo
> entero a este lote sería la forma exacta de que la próxima cifra nazca mal.
>
> **Y la corrida anterior murió con señal 15 a mitad** —no un rojo: cero fallos en las ~430 que
> alcanzó—. Se relanzó entera en vez de dar por bueno lo que había corrido: media suite verde no es
> la suite verde. La suite entera, nunca con `--filter`:
> `docker exec 8myvc-app-1 php artisan test | tail -3`.
>
> **Y va contra una base de tests reconstruida**, porque este lote lleva migración: la de por defecto
> todavía tiene `matriculas.boletin_independiente`. Se corrió con
> `DB_TEST_DATABASE=simonbolivar_testing_bi7` para no pisar a las otras sesiones, y quien lo repita
> reconstruye antes con `tools/construir-bd-test.sh` o hace lo mismo. **El primer sitio donde mirar
> cuando el número sale raro es el instrumento**, y aquí lo sería.
>
> **Cero instantáneas regeneradas**, que es el criterio de aceptación de la §4 y esta vez apuntaba a
> quitar una columna en vez de a añadirla.

> **Y esto NO está comiteado**: el árbol traía ya cinco ficheros modificados de otras sesiones y
> `myvc-front-c5` había editado el 19 sin commitear. El OK de Joseth a otra sesión no vale para ésta
> ([[autorizacion-no-se-delega]]). El aviso al front está escrito en su buzón
> (`myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`), que es donde manda el acuerdo del 24 ago.

**Y EN PARALELO, LA MISMA NOCHE — EL MODAL DE «ALUMNOS POR GRUPO» YA TIENE DE DÓNDE LEER**
(sesión distinta, encargo del front `myvc-front-ca` para el panel de `app2`) · ruta **544**:
`GET grupos/{grupo_id}/alumnos-de/{que}` con `auth.personal`, `{que}` ∈
`alumnos|hombres|mujeres|retirados|matriculados` y `?periodo=N` en los dos últimos. Devuelve un
**array plano** de alumnos —`alumno_id, nombres, apellidos, sexo, estado, foto_id, foto_nombre`, más
`fecha_matricula` o `fecha_retiro` según la celda—, ordenado por `apellidos, nombres` como
`grupos/listado/{grupo_id}`.

> **Lo único que este endpoint tiene que garantizar es el CUADRE, y por eso duplica SQL a propósito.**
> Cada uno de los cinco casos repite el `WHERE` de su contador —`getCantAlumnos` y los cuatro bloques
> de `putConCantidadAlumnos`— y sólo le cambia el `SELECT`. `grupos/listado/{grupo_id}` no servía:
> incluye los **PREM**, que ninguno de los cinco cuenta desde el arreglo de las 199 vs 221 de esta
> misma noche, así que el listado habría enseñado seis alumnos debajo de un 5. **Una pantalla que
> miente por uno no parece rota: parece un dato.**
>
> Se copian tal cual **dos cosas que parecen fallos** —la tercera, los `>` estrictos, se arregló esa
> misma noche y está más abajo—, porque arreglarlas aquí y no en el contador es exactamente
> descuadrarlos: que `matriculados` **no filtre por estado** —cuenta hasta RETI y FORM— y que un
> alumno con dos matrículas vivas en el mismo grupo **salga dos veces**, que es como lo cuenta el
> `count(m.id)`. **Esa segunda no es teórica**: el front tenía `track a.alumno_id` y con dos filas de
> la misma clave Angular no pinta una lista rara, tira NG0955 y **el diálogo entero sale en blanco**.
> Deduplicar en el backend habría cambiado un fallo visible por uno mudo, y encima descuadrado.
>
> **EL HALLAZGO, que no lo buscaba nadie: en el seed los cuatro periodos del año actual tienen
> `fecha_inicio` y `fecha_fin` a NULL.** Con nulos, `m.fecha_retiro > NULL` no es ni verdadero ni
> falso: los dos contadores devuelven **0 siempre**, y las ocho columnas Ret_N/Mat_N de la tabla salen
> vacías **todo el año sin que nada esté roto**. Es lo mismo que el front ve en su base local y había
> leído como «todavía no hay movimiento». No es un fallo del código: es que **esas fechas no están
> puestas**, y hasta que un colegio las ponga esas ocho columnas no pueden pintar nada. `MatriculasController`
> y compañía no las escriben; se ponen desde la pantalla de periodos.
>
> **El test es de cuadre, no de forma** (`tests/Contrato/AlumnosDetrasDelNumeroTest.php`, 4 casos):
> pregunta `grupos/cant-alumnos` y `grupos/con-cantidad-alumnos` —las dos respuestas que alimentan la
> tabla— y enfrenta **cada cifra con la longitud de su listado**, grupo a grupo; cruza además los tres
> listados entre sí, fabrica el movimiento de un periodo (fechas incluidas, porque el seed no las
> tiene) y fija los 422. **Dice su población**: hoy son *1 grupo y 37 alumnos*, porque la base de
> tests tiene **un grupo por año** — un cuadre de trece grupos vacíos cuadra y no comprueba nada.
>
> **LAS DOS PREGUNTAS LAS CONTESTÓ JOSETH LA MISMA NOCHE** (por la sesión del front, que se las pasó
> sin adelantarse a ninguna de las dos):
>
>   1. **EL `>` ESTRICTO ERA UN FALLO Y ESTÁ ARREGLADO.** A `>=` y `<=` **en las tres consultas a la
>      vez** —los dos contadores de `putConCantidadAlumnos` y el listado nuevo—, que era la condición:
>      tocar una sola descuadra la celda con su listado. Quien se matriculaba o se retiraba **el
>      primer o el último día** de un periodo no estaba en ninguna de las dos cifras. **Esas columnas
>      pueden SUBIR en algunos colegios y no es una regresión**: es gente que no se contaba en ningún
>      sitio. Es lo único de esta tanda que cambia una cifra que los colegios ya están mirando.
>   2. **El docente sigue viendo el listado de cualquier grupo**: se queda con `auth.personal`, como
>      el resto de `grupos/*`. No hay guarda que escribir. El razonamiento de Joseth es el que ya
>      estaba en el código: es lo que un docente puede hacer hoy por otras pantallas, y estrecharlo
>      sería quitarle algo que tiene.
>
> **Lo que el arreglo NO iguala, para que nadie lo «arregle» después: la columna y su total siguen sin
> ser la misma cuenta**, y ahora que comparan igual la tentación de sumarlas es mayor. `total_reti` y
> `total_matr` **no filtran por grupo NI por año** —recorren los grupos de los ocho años del seed—, y
> el `total_matr` del periodo **1** no tiene extremo inferior en absoluto (`m.fecha_matricula<=?`).
> El total no es la suma de las columnas y nunca lo fue.
>
> **Y el test lo comprueba por el borde, no por el medio**: fabrica cuatro movimientos, dos de ellos
> **el día exacto de `fecha_inicio` y el de `fecha_fin`**. Comprobado que se pone rojo con los `>`
> estrictos de vuelta —y por los dos lados, celda y listado—, que es la única forma de saber que el
> caso que gana está cubierto: volver a los estrictos **no descuadra nada**, sólo cuenta de menos, así
> que un test que sólo mirara el cuadre habría seguido en verde.
>
> **Mueve tres instantáneas, no dos** —`rutas.json`, `guards-por-ruta.json` y **`guard-por-familia.json`**,
> donde la familia `grupos` pasa de 16/15 a 17/16—, y **no toca las tablas de `DESPLIEGUE.md`**: esas
> son lo que se midió el día del despliegue y se remiden el del siguiente. **Sin fundir y sin
> desplegar**: el front no puede publicar el modal hasta que la ruta esté **desplegada**, no fusionada.

**Anterior: 31 ago 2026 — LA TANDA DEL 25–30 AGO ESTÁ DESPLEGADA, Y CON ELLA LA
DEFINITIVA DECIMAL** · de `eb95cbc` a **`9474b50`**, 44 commits, **en los quince del bucle de
`micolev1` y en la cuenta de `lalvirtual.edu.co`**, con el front de la misma vuelta · **dos
migraciones, las dos bloqueantes** (`interruptores_de_certificados` y `notas_finales_en_decimal`) ·
**543 rutas** · **38 ficheros de `app/`** · los **diez avisos al front cerrados**: A, B y C salieron
en el front de esa vuelta; D y F no requerían trabajo; E lo habían pedido ellos; G, H e I avisados

> **LO SIGUIENTE, Y ES DE `myvc_flutter`: el paso 3 del aviso J acaba de pasar de prohibido a
> obligatorio.** El orden era `app2` → **backend en los quince, verificado** → Flutter, y hacer el
> tercero antes que el segundo era el error caro. Los dos primeros están hechos, así que toca
> **quitar el `roundToDouble()` de `LibroNotasApi.dart:439`**, contra el hash **`9474b50`** y no
> contra `main`. Mientras no salga, la app enseña `44` con `43,75` guardado **tras guardar una nota
> y hasta recargar**: es la ventana pequeña, la que se elige a propósito, y la abre un colegio cada
> vez en lugar de los quince a la vez. **Y el sitio a mirar para pintar es quien llama a
> `notaEscrita` (`LibroAsignaturaScreen:453`), no el formateador** — redondear ahí reintroduciría
> desde el cliente justo el redondeo que la migración quita, porque ese formateador alimenta seis
> casillas de edición.
>
> **Y una acción nuestra que sigue sin hacerse, escrita aquí para que no se caiga con la sesión:
> decirle a `myvc_flutter` el hash desplegado.** `b369020` está dentro de `9474b50` —comprobado con
> `git merge-base --is-ancestor`— y su `temasDelColegio` lleva un interruptor apagado esperando
> exactamente ese dato. No hay ventana rota: leen las dos formas, sólo hay un interruptor que
> encender. La otra, la del desglose por año del bloque 5, sigue esperando al `for` de la fase 0.
>
> **La tabla de la tanda decía UNA migración y veintinueve ficheros; el día del despliegue eran DOS
> y treinta y ocho.** No era una cifra vieja: la tanda **creció** después de escribirla. Ésa es la
> diferencia entre *remedir* y *sumar*, y por eso el recálculo va con el comando delante **el día
> del despliegue**, no el día en que se escribe la tabla.

**Anterior: 30 ago 2026 — LA DEFINITIVA DE UNA MATERIA DEJA DE SER UN ENTERO** ·
`notas_finales.nota` pasa a **`DECIMAL(7,4)`** y el cálculo deja de redondear
(`2026_08_30_200000_notas_finales_en_decimal`) · encargo de Joseth por la sesión del front
`myvc-front-b8`, que ya hizo su mitad: la planilla de puestos numeraba filas (`$index + 1`) y decía
otra cosa que el boletín, y al arreglarlo apareció lo de fondo · **sobre la base real son 96.608 de
125.352 definitivas (77,1 %) las que hoy se guardan redondeadas** — tres de cada cuatro — y de ahí
salen los empates de puesto, porque `Nota::puestoAlumno` cuenta a cuántos les gana el promedio ·
**la aritmética no perdía nada y el techo era la columna**: el promedio ya se calculaba sin
redondear y `puestoAlumno` compara con `>` a secas · **verde: 1.578 pruebas, 11.846 aserciones,
pint PASS, larastan nivel 7 `[OK]`**

> **COMITEADO el 30 ago 2026 con el OK expreso de Joseth y DESPLEGADO en los quince el 31**, que era
> justo lo que este aviso estaba esperando. Se escribió aquí *«comiteado no es desplegado»* porque la
> migración corre en quince producciones y **le cambia el puesto a alumnos reales**; ya está corrida,
> así que **lo que queda no es el despliegue sino el paso 3 de Flutter** — ver la entrada del 31.
>
> **Y la trampa que se llevó diez minutos al verificarlo, apuntada para el siguiente:** esta rama
> **da seis rojos contra la base de tests por defecto**. No es una regresión — es que
> `simonbolivar_testing` sigue con `notas_finales.nota` en `int` y la columna redondea (`35` donde
> se calculó `34`). El verde de 1.578 es contra una base con la migración puesta:
> `docker exec -e DB_TEST_DATABASE=simonbolivar_testing_dec …`, o reconstruir la de por defecto con
> `tools/construir-bd-test.sh`. **El primer sitio donde mirar cuando el número sale raro es el
> instrumento**, y aquí lo era.
>
> **`DECIMAL(7,4)` y no `(6,2)`, y se decidió con el cálculo delante, no con la corazonada que traía
> el encargo.** La fórmula es `SUM(nota × pct_sub × pct_uni / 10000)` con los tres factores enteros,
> así que **cada sumando tiene exactamente 4 decimales**. Contado sobre las 125.352: con 2 decimales
> **no caben 21.148 (16,9 %)**, con 3 no caben 3.371, **con 4 no cabe fuera ninguna**. `(6,2)` habría
> vuelto a redondear una de cada seis por la puerta de atrás. Lo fija
> `test_la_definitiva_guarda_cuatro_decimales`, que monta el caso 33 % × 33 % → **0,4356** y se pone
> rojo si alguien afloja la escala.
>
> **EL HALLAZGO QUE NO ESTABA EN EL ENCARGO Y ERA EL QUE PODÍA ROMPER LOS QUINCE: el tipo del JSON.**
> PDO devuelve un `DECIMAL` como **cadena**, así que la migración a pelo cambiaba `45` por
> `"43.7500"` en **17 respuestas** (boletines, notas, puestos, promovidos, planillas) — un cambio de
> **tipo**, no de decimales. Lo destapó la suite de contrato, que guarda **el tipo de cada campo** en
> sus instantáneas: es exactamente el instrumento que hacía falta y por eso los 20 rojos del primer
> intento fueron el sistema funcionando. Se cerró casteando **~40 lecturas** (`CAST(... AS DOUBLE)`
> en SQL, `(float)` en los dos sitios de PHP), y el marcador fue **20 → 14 → 7 → 0**. Los siete
> últimos eran regeneración legítima: **9 líneas en 7 ficheros, todas ensanchando `int` → `float`,
> ni un campo añadido, quitado ni renombrado**.
>
> **Y `(int)` era peor que el `round()` que quitábamos**, en dos sitios que van al JSON
> (`DefinitivasDeAsignatura:403`, `NotasController:861`): `(int)"43.7500"` **trunca** a 43, no
> redondea a 44. Un sesgo sistemático hacia abajo, justo donde el front lee.
>
> **LO QUE NO SE HIZO, Y ES DECISIÓN TOMADA, NO OLVIDO: `notas.nota` se queda en `int`.** El encargo
> pedía las dos columnas. Medido aquí: se escribe **sólo** desde `Request::input('nota')` y desde
> `subunidades.nota_default`, y **no hay un solo `round()` en ese camino** — el redondeo que empata
> los puestos ocurre **al guardar la definitiva**, no al guardar la nota, así que migrarla no
> desempata a nadie.
>
> **Y aquí por poco escribo una falsedad, que es el aviso que vale la pena guardar.** Iba a razonarlo
> con *«el docente teclea un entero y se guarda un entero»*. **Es mentira, y la verdad no está en este
> repositorio**: las cuatro pantallas de los dos fronts llevan `<input type="number">` **sin `step`**
> y ninguna valida, así que **sí se puede teclear `85,5`** — lo midió `myvc-front-10` el 23 ago 2026
> en `myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`. Lo que no hay es un `round()` **de PHP**; quien
> redondea es **MySQL al meterlo en un `int`**. Lo encontré leyendo el fichero del front **después**
> de haber escrito mi conclusión, y sólo porque la memoria dice que ese fichero existe. **Un camino
> de escritura no se declara limpio mirando sólo el backend: el cliente es parte del camino.**
>
> **Corrección al front, y va en la dirección contraria a la suya:** esa entrada dice que «MySQL
> trunca en silencio» y que un `85,5` deja `85`. **Redondea, no trunca.** Medido contra el
> contenedor, ligado y como literal: `85.5 → 86`, `85.4 → 85`, `43.75 → 44`.
>
> **La columna se queda porque la decisión ya está tomada y es la contraria:** el 23 ago 2026 Joseth
> decidió cerrar esa puerta **en el teclado, no redondeando al guardar** —*«si un 85,5 tiene que ser
> 86, lo decide quien pone la nota»*—, y `myvc_flutter` ya lo hizo (`lib/Utils/TecladoDeNota.dart`).
> Volver decimal la columna sería **deshacer esa decisión por la puerta de atrás**, y arrastraría la
> escala de la definitiva a `4 + d`. Confirmado con Joseth el 30 ago. **Lo que sigue abierto y es del
> front:** los dos Angular no tienen todavía el arreglo del teclado que Flutter sí tiene.
>
> **FLUTTER: NO BLOQUEA, Y ESTE PÁRRAFO DECÍA QUE SÍ.** Escribí que `json['nota'] as int` lanzaría
> excepción. **El hecho de Dart es cierto y lo apliqué a un código que no había mirado** — que es
> justo el error que este documento lleva dos semanas nombrando en otras formas. Lo midió
> `myvc-front-b8` y lo confirmé contra el fichero: en las 112 clases de `myvc_flutter/lib` hay **cero
> `as int` y cero `as double`**, los tres `toInt()` van guardados por `is num`, las notas se leen por
> `_decimal()` —que traga `num` **y** cadena— y los campos son `double`. Hay además una capa
> tolerante entera (`Utils/JsonBackend.dart`) escrita para exactamente este problema. **El caso
> estaba previsto en el otro repositorio y yo no fui a mirarlo.**
>
> **PERO SÍ HAY TRABAJO DE FLUTTER ANTES DEL DESPLIEGUE, Y ES MÁS SERIO QUE PINTAR — no lo teníamos
> ninguno de los dos.** `LibroNotasApi.dart:439` **replica en Dart el `CAST` que esta migración
> cambia**, y lo dice su propio comentario: *«el backend … castea a `DECIMAL(4,0)`. Aquí se hace lo
> mismo para que lo que se ve sea lo que hay guardado y no una aproximación parecida»*. Hace
> `promedio.roundToDouble()` al guardar una nota, así que con la migración puesta **la app enseña 44
> mientras el servidor guarda 43,75**: la «aproximación parecida» que ese código existe para evitar,
> **sin error y hasta la siguiente recarga**. Es el quinto disparador de [[canal-con-el-front]] en
> vivo — **la premisa del fallo vivía en el otro repositorio**, cableada por nombre.
>
> **Y lo cosmético, medido:** hay **cinco** formateadores de nota en `lib/`; **tres** dan un decimal
> (`43.8` donde hoy `44`) y **dos** —`LibroNotasApi:841`, `UnidadesScreen:1027`— caen en `toString()`
> y sacarían **`43.75` entero**. `myvc-front-b8` lo describió como «un `toString()` suelto»; medido,
> **ese `toString()` es la rama else del formateador**, no un caso aparte, y su predicado es
> `valor == valor.roundToDouble()` y no el `% 1 == 0` que citaban — **`% 1 == 0` no aparece ni una vez
> en `lib/`**. La conclusión de ambos aguanta; el reparto no era el que decía el mensaje.
>
> **Y a mí me corrigieron a continuación, con razón: ese quinto NO se arregla donde yo lo puse.** Lo
> llamé «el peor de los cinco» y lo listé junto a los otros cuatro — una tabla que invita a meterle
> un `toStringAsFixed(0)`. Se llama **`notaEscrita`**, va emparejado con `notaLeida` y su docblock
> dice que es *«cómo se escribe una nota **dentro de un campo**»*: alimenta **seis
> `TextEditingController`** y sólo **dos** usos de pintar. **Redondearlo ahí reintroduciría desde el
> cliente el redondeo que esta migración quita** — abrir la planilla y guardar convertiría un 43,75
> en 44. Se parte en dos, y es de Flutter. (Ellos dijeron cuatro casillas; verificado, son **seis**.)
> **Una lista de sitios «que sacan el mismo síntoma» no es una lista de sitios que se arreglan
> igual**: la misma trampa que el `CLAUDE.md` nombra en las herramientas — *contar bien el síntoma no
> es haber contado la causa*. La fila de `DESPLIEGUE.md` ya señala **a quien lo llama para pintar**
> (`LibroAsignaturaScreen:453`) y no al formateador.
>
> **EL ORDEN DE DESPLIEGUE VA AL REVÉS DE LO QUE YO ESCRIBÍ, Y ÉSTE ERA EL ERROR CARO.** Dejé en la
> fila «el `roundToDouble` primero» —queriendo decir «antes que lo cosmético»— y **se lee como «antes
> que el backend»**. Lo corrigió la sesión de Flutter con el argumento bueno: **hoy el cliente y el
> servidor redondean los dos y coinciden**, así que esa línea no está mal — está atada a un contrato
> que hoy sigue vigente. Quitarla antes de que la migración esté desplegada deja al cliente
> enseñando `43,75` con el servidor guardando `44`, **de golpe en los quince**, porque `myvc_flutter`
> es **una sola app publicada por Play** mientras que esto son **quince despliegues que tardan
> días**. El orden bueno es **`app2` → backend en los quince, verificado → Flutter**, con la línea
> sin redondeo escrita ya **detrás de un interruptor apagado** y encendida **contra el hash de la
> tanda, no contra `main`**. Está como sección propia bajo la tabla de
> [`DESPLIEGUE.md`](../DESPLIEGUE.md).
>
> **Y la ventana que el orden NO cierra, que hay que saber:** mientras el backend rueda por los
> quince, un colegio ya migrado con la app todavía redondeando enseñará `44` con `43,75` guardado
> **tras guardar una nota y hasta recargar**. Es inevitable y es la pequeña: la abre un colegio cada
> vez y se cierra sola. La otra la abren los quince a la vez y no.
>
> **Y DOS COSAS MÁS QUE SON TUYAS.** **(1)** Las dos columnas de valor de `bitacoras` son `int`, así
> que el rastro **viejo** de una definitiva a mano guarda 44 donde el valor es 43,75. Se redondea
> ahí **a propósito y explícitamente**, porque con `sql_mode` vacío —el del contenedor— MySQL lo
> hacía en silencio y **con `STRICT_TRANS_TABLES` lo habría rechazado**, o sea un 500 al guardar; y
> no sabemos el `sql_mode` de los quince cPanel. **El decimal exacto no se pierde**: el rastro nuevo
> (`auditoria`) lo guarda en columnas **JSON**. Ensanchar `bitacoras` es otra migración con su propia
> decisión — esas dos columnas las comparten `Nota`, `Nueva subunidad` y `AlumnoPideAjeno:user_id`.
> **(2)** **Los porcentajes mal configurados quedan como están**, que es la regla 2 de
> `DefinitivasDeAsignatura` y es deliberada: hay **25 unidades de 16.931** cuyas subunidades no suman
> 100 y **15 pares (asignatura, periodo) de 3.930** cuyas unidades tampoco. Antes el redondeo tapaba
> parte de ese sesgo; ahora se verá en la planilla, que es justo lo que esa regla quiere.

**Anterior: 30 ago 2026 — CREAR UN AÑO DEJA DE ENTREGARLO A MEDIO MONTAR** ·
`POST years/store` creaba **un** periodo —`numero=1, actual=1`, sin fechas, sin `created_at` y sin
`created_by`— y se dejaba **diez columnas de `years`** sin copiar del año anterior · el resultado
está en la base del colegio del seed y no hay que deducirlo: sus **ocho años viejos tienen los
cuatro periodos**, puestos a mano uno a uno después, y **el único año creado por esta ruta tiene
uno** · ahora nacen **cuatro**, numerados 1–4, sólo el primero `actual`, con `created_by` y con
fechas · decisiones de Joseth (30 ago): **siempre cuatro**; si el año anterior trae su calendario
**completo** se traslada `+1 año ajustando al mismo día de la semana` —un `+1` literal mueve el día
de la semana, y al tercer año el curso arrancaría en sábado—, y si no, se calculan desde
`years.calendario` (**A**: 3er lunes de enero → último viernes de noviembre; **B**: agosto → junio),
en cuatro tramos con dos semanas de receso entre el 2º y el 3º · las asignaturas se llevan además su **docente**, que era la única de las dos rutas que duplican asignaturas que no lo copiaba · **o los cuatro o ninguno**: un
calendario a medias en el año anterior se calcula entero, porque trasladarlo a trozos deja
exactamente el agujero que esto tapa · `app/Services/CalendarioDePeriodos.php`, y doce casos nuevos
en `tests/Contrato/YearsTest.php`

> **Que las fechas estén en NULL no era cosmético.** `Informes\ActasEvaluacionController` reparte
> las ausencias por periodo **contra `fecha_inicio` y `fecha_fin`**, y ya llevaba escrito que «hay
> colegios con el calendario sin llenar»: las que no caen en ningún periodo van al balde
> `fuera_calendario`. Con los cuatro periodos sin fechas, el balde se lo lleva **todo**. En el seed,
> de nueve años **tres** tienen fechas —2018, 2019 y 2020— y **ninguno desde 2021**.
>
> **Y cuatro de las diez columnas se imprimen en papel oficial.** `caracter`, `calendario` y
> `jornada` salen literalmente en el certificado de estudio —«de carácter X, calendario Y, jornada
> Z», en `certificadoEstudioDir.html`— y las tres tienen **defecto en el esquema**, así que el año
> nuevo no salía en blanco: salía diciendo «Privado», «A» y «Mañana y tarde» **fuera cual fuera el
> colegio**, que es peor que vacío porque nadie lo nota. `frase_final_certificado` es la frase de
> cierre de ese mismo papel y sí nacía vacía. Las otras seis: `genero_colegio`, `img_encabezado_id`,
> `texto_acta_eval` (el acta de evaluación), `show_materias_todas`, `prematr_antiguos` y
> `prematr_nuevos` (el enlace público de prematrícula del login). También se copian ahora los
> **requisitos de matrícula**, la única tabla de configuración por año que no se copiaba, y los dos
> interruptores de cada periodo —`profes_pueden_editar_notas` y `profes_pueden_nivelar`—, que nacían
> en el `1` del esquema: hay años en el seed con los cuatro **cerrados**, y nacer abiertos abre la
> planilla de un año lectivo entero a los 51 docentes sin que nadie lo pida.
>
> **La ruta no se mueve: siguen siendo 543.** Esto es todo dentro de `POST years/store`. Lo único
> que cambia en la respuesta es que ahora **trae `periodos`** — `YearsCtrl.crearNewYear` hace
> `$ctrl.years.push(r)` y `years.html` recorre `year.periodos`, que hasta hoy llegaba vacío y
> obligaba a recargar. Es aditivo; ningún cliente pierde una clave.
>
> **EL DOCENTE DE LA ASIGNATURA SÍ SE COPIA, Y EL TITULAR DEL GRUPO NO — y la primera mitad va
> escrita porque me equivoqué y la corrigió Joseth.** Argumenté que copiar `profesor_id` era
> peligroso: cuando se crea el año **no hay ni un contrato en él**, y
> `Profesor::paraElegirEnAsignaturas` lista **sólo docentes con contrato**, así que el copiado no
> sale en el desplegable — «queda mal en silencio», dije, con **1 de 10 asignaturas** medidas en el
> seed. **Lo que faltaba es que ese silencio se deshace solo**: la columna «Profesor» de la rejilla
> resuelve el nombre **filtrando esa misma lista**, así que la celda sale **en blanco** —no con un
> nombre falso— y `profesor_id` **sigue en la fila**; se le hace el contrato y **aparece**. No es un
> dato erróneo, es uno **pendiente**, y el reparto del año pasado queda de borrador que se
> materializa según se contrata. La cifra no cambia; cambia lo que significa. **Medir bien el
> síntoma no basta si se le atribuye la consecuencia equivocada.** Y había una pista delante:
> **`POST asignaturas/copiar` ya copiaba `profesor_id`** de grupo a grupo — de las dos rutas que
> duplican asignaturas, ésta era la única que no.
>
> **El titular va al revés y por eso no se copia:** `GruposController` lista los grupos con
> `left join profesores p on p.id=g.titular_id`, **join directo, sin pasar por `contratos`**, así
> que un titular copiado sale **con nombre y apellidos**, como si estuviera en la planta. Un dato
> que se ve y parece cierto no es un borrador pendiente. **La regla que queda**: se copia la
> referencia a una persona **cuando el cliente la resuelve contra la planta del año** —y entonces se
> esconde sola hasta que la planta la incluya—, y no se copia **cuando la resuelve contra la tabla
> de personas**. Es *qué ve quien mira*, no *qué hay en la fila*.
>
> **Y lo que queda propuesto, en un lote aparte porque es una ruta nueva** (y una ruta nueva es una
> decisión, no un efecto secundario): **«copiar la carga académica de un docente a otro»**, para el
> que se fue o cambió de materias — lo pidió Joseth el 30 ago, y hoy no hay forma de hacerlo:
> `POST asignaturas/copiar` copia de **grupo a grupo**, no de docente a docente. La otra mitad que
> había propuesto —«heredar la carga del año pasado» corrida después de los contratos— **ya no hace
> falta**: la herencia ocurre al crear el año, y el contrato es lo que la hace visible.

---

**Última actualización: 28 ago 2026, noche — LA HOJA DE VIDA DE LOS 47 EMPLEADOS DEJA DE
LEERLA CUALQUIERA DEL PERSONAL** · `GET profesores` iba con `auth.personal` y nada más, y le daba a
**un docente cualquiera las mismas 28 claves y los mismos 47 registros** que a un administrador:
35 documentos de identidad, 41 fechas de nacimiento, 11 domicilios y el `is_superuser` de cada uno
—que además dice a quién apuntar— ([05 §243](05-codigo-muerto-y-roto.md)) · lo midió
`myvc-front-6b` conduciendo Chrome con un token de docente, **la primera vez en la fase 11 que
alguien usa la aplicación sin ser `administrador`**, y lo autorizó Joseth · ahora exige
`Autoriza::esAdministrativo` —superusuario o `Secretario`—, que es **el criterio que ya gobernaba
la escritura de este mismo controlador**: la asimetría era que el expediente no se podía editar sin
ser superusuario y se podía leer siendo cualquiera del personal · **se cierra la puerta y no se
recorta la respuesta**, al revés que en `contratos()`, porque las tres pantallas que la consumen
son de administración y una de ellas es la de **editar la ficha** · aviso **H** en
[`DESPLIEGUE.md`](../DESPLIEGUE.md), sin trabajo del front · `tests/Contrato/FichaDelPersonalTest.php`,
cinco casos

> **Y la bitácora, que era la otra mitad del encargo, NO necesitaba arreglo: ya la cerró `abaf6b2`
> el 24 ago**, un día antes de que la midieran. Reproducido aquí con un token de **Profesor** —el
> rol con el que se midió, y que no es el que cubrían los tests de AUD-5, todos sobre
> `tipo = 'Usuario'`—: **403**. La medición del front era correcta y la conclusión no, porque
> **midió un entorno que no tenía el arreglo**. Antes de abrir un lote por un hallazgo que llega de
> otro repositorio: **reproducirlo aquí primero**, que cuesta un test.
>
> **LO QUE NO CIERRA, Y ES TUYO, JOSETH:** `GET profesores` era **una de cuatro**. El censo de la
> familia está hecho y en la [§243](05-codigo-muerto-y-roto.md): `profesores/todos` (19 registros,
> **las mismas 28 claves**), `PUT profesores/listado` (**37 claves**), `profesores/show/{id}` (la
> ficha de cualquiera, por id) y `profesores/conyears` (leve). **No se tocan porque cerrarlas con
> este mismo criterio podría romper una pantalla:** el informe «listado de profesores» va en `app2`
> con el permiso `informes`, que incluye a **`Coord disciplinario`** — y un coordinador
> disciplinario **no** es `esAdministrativo`. **Medido después: en `simonbolivar` ese rol lo tiene
> una sola persona y además es superusuaria**, así que ahí no rompería nada — pero es **una base de
> quince**. Y **`profesores/trashed` da 500**: está rota además de abierta.
>
> **Y hay un paso 0 nuevo en [`DESPLIEGUE.md`](../DESPLIEGUE.md), que va ANTES de esta tanda.**
> `esAdministrativo` es `is_superuser || Secretario` y **no incluye el rol `Admin`**, al que `app2`
> sí le abre la pantalla de Docentes: coinciden sólo porque los diez `Admin` medidos son los diez
> `is_superuser`. **Es una coincidencia de población, no un criterio**, y un colegio que la rompa
> deja a esa persona sin la pantalla. Como no se puede medir desde el repositorio —cada colegio
> tiene su base—, el despliegue corre el `SELECT` en los quince y **para si alguno no da cero**.
>
> **Y lo que evita el tercer caso:** el censo de IDOR del [08](08-revision-idor.md) **ya tenía
> estas rutas** y no las cerró porque **se corrió con un token de alumno**, y su herramienta deja
> fuera todo lo que lleva `auth.personal` — **un Profesor ES personal**. El detector no falló: la
> pregunta era otra. **Hay que volver a correrlo con un rol del personal.**

**Anterior: 28 ago 2026, noche — EL BOLETÍN DEJA DE INVENTAR EL CERO, Y LA FASE 3
VUELVE A CUBRIR A LOS RETIRADOS** · dos escrituras vivas en los quince y **van juntas**: por
separado, la segunda sin la primera ensancha a los retirados el sembrado de ceros que la primera
quita ([`noche-2026-08-28/desact-1.md`](noche-2026-08-28/desact-1.md)) · **(1)** `DefinitivasDeAsignatura`
ya no escribe fila cuando la asignatura **no tiene ninguna unidad viva** en el periodo — su regla 1
escribía *una definitiva a cero por cada matriculado* sobre un periodo sin montar, y llegaba por una
puerta que nadie miraba: `UnidadesController::deleteDestroy` llama a `recalcularPorUnidad` **después**
del borrado, así que **borrar la última unidad escribía treinta ceros** firmados por quien la borró
· **(2)** fuera el `m.estado IN ("MATR","ASIS")` de `calcular()`, que al sustituir al botón le quitaba
la definitiva a **6.435 pares de 314 retirados** sin un solo error · **cambia el contrato del boletín**:
`PUT boletines` devuelve `null` en seis campos en **9.648 celdas de 10.532**, snapshot regenerado a
propósito, y los cuatro clientes medidos por el front — ninguno se rompe · siete tests en dos clases

> **Dos cosas de Joseth que hay que leer antes de tocar esto.** **No es «como los informes»**: el
> boletín y `Grupo::alumnos` admiten `MATR`, `ASIS` y `PREM` y **ninguno enseña a los retirados**, así
> que la (2) es *más* que los informes y está elegido a sabiendas — «alinearlo con los informes» sería
> deshacerlo. Y aprobó el `null` **con una condición**: *«si el usuario edita el input vacío espero que
> pueda crear y guardar el nuevo valor manual»*, cuya mitad de backend es la **rama sin `nf_id` de
> `putUpdate`** — antes casi nunca hacía falta porque el boletín sembraba la fila al abrirse, y ahora
> es la **única** puerta por la que nace la definitiva de una casilla vacía. Atada con test.
>
> **Lo que NO se hizo y no está autorizado:** limpiar las **884 celdas** que ya tienen su cero
> sembrado. Se quedan, y convivirán con las vacías hasta que el botón pase por su grupo.

**Anterior: 28 ago 2026 — `users.profesor_id` DEJA DE SER UNA COLUMNA QUE SÓLO SE
LEE** · `PUT users/mi-docente` (ruta **543**, y la primera desde las tres de Flutter del 24) escribe
qué docente mira una cuenta administrativa · **la columna existía y no la escribía nadie**: las
dieciséis cuentas de tipo `Usuario` la tienen en `NULL`, y los cuatro `UPDATE users` del repositorio
son de contraseña, correo, username y `periodo_id` — mientras que **leerla la leen dos sitios vivos**,
`ContextoDeUsuario` (viaja dentro de la sesión) y `ChangeAskedController::getToMe` (el horario de hoy
y el de mañana) · sólo `tipo = 'Usuario'` (un profesor recibe **403**) y sólo un profesor **contratado
en el año en curso** (si no, **422**: la columna no tiene clave foránea) · `tests/Contrato/MiDocenteTest.php`,
cinco casos

> **Lo pidió Joseth para el panel de `app2`**, donde el saludo de la portada se cambió por un botón
> con el nombre del docente y un diálogo con las caras. **`app2` ya llama a esta ruta**, así que
> este backend va **antes** que ese front en cada colegio: sin él, elegir docente funciona en
> pantalla y avisa de que no quedó guardado (404). Está como aviso **G** en
> [`DESPLIEGUE.md`](../DESPLIEGUE.md).
>
> **Y el efecto secundario que se quiso y hay que saber:** con la columna puesta, **el panel VIEJO
> le empieza a pintar a esa cuenta el horario de ese docente**. Es la mitad de la función que ya
> estaba escrita esperando a que alguien rellenara el dato.

**Anterior: 27 ago 2026 — UNA FALTA SIN FECHA YA NO SE PUEDE ESCRIBIR POR
`ausencias/store`** · el endpoint guardaba `fecha_hora` a null cuando el cliente no la mandaba, y
una falta sin día **cuenta en los totales del boletín y no sale en ningún listado por día** — el
calendario de Flutter la descarta con `esDelDia()`. Ahora se rellena con `Reloj::ahora()`
([05 §242](05-codigo-muerto-y-roto.md)) · **en la copia de un colegio hay 5.071 así, de 46.470
vivas (10,9%)**, y **las 5.071 llevan `uploaded` a null**, que es lo que señala a esta puerta y no
a los tres `poner-ausencia` · **y el front ya lo daba por hecho**: `myvc_front` tiene commiteado
(`eb0b4d25`) un comentario que dice *«desde el 2026-08-27 el backend rellena la que no se manda»*
y una prueba que lee **los dos formatos** en que llega esa columna

> **Lo que NO cierra, y es lo tuyo, Joseth:** las **5.071 ya escritas se quedan**. Rellenarlas con
> su `created_at` es inventar un día plausible —cuándo se tecleó no es cuándo faltó el alumno—, y
> eso es mejor que null para el calendario y peor para quien crea que el dato es cierto. **Y los
> tres `poner-ausencia` siguen aceptando null**: no escribieron ninguna de las 5.071, pero la
> puerta está abierta. Las dos cosas, con su medición, en la [§242](05-codigo-muerto-y-roto.md).

**Y antes, el 26 ago 2026, tarde — LA PREMATRÍCULA PÚBLICA YA NO DEJA HUÉRFANO
AL MENOR** · el `1bis(a)` estaba escrito como *«se cierra con una transacción, y eso no
espera a nadie»*, y **eso es exactamente lo que se hizo**: las cuatro escrituras en
transacción, y **422 delante de todo** para que el 500 —que en una ruta **pública y sin
autenticar** trae `Host`, `Port` y `Database` en el cuerpo— deje de ser alcanzable
([05 §236](05-codigo-muerto-y-roto.md)) · **el `1bis(b)` sigue entero y es tuyo**: los
huérfanos ya escritos en los quince, que **hoy no sabe contar nadie porque la consulta no
se ha corrido en ningún colegio**

> **Y el aviso al front sube a cuatro** (`DESPLIEGUE.md`, fila **D**): esa ruta cambia el
> 500 por un 422 con mensaje. · **De paso, la fila `app/` de la tanda decía «ocho ficheros»
> y eran diez antes de tocar nada** — faltaba `BolfinalesController` **del raíz**, que con
> **308 líneas es el que más se movió de toda la tanda** y es el desanidado de GEMELO-1 que
> la propia tabla de al lado anuncia. Corregida a once. **La lista se escribió a mano; el
> `git diff` de su columna derecha la desmiente.**

**Y antes, el 26 por la mañana — CERT-2: EL PUNTO 1 DE LA LISTA YA NO
ESPERA NADA** · el consecutivo de certificados **pasa a ser de secretaría** y **deja
rastro por primera vez**, con las tres respuestas de Joseth de esta mañana delante
([`noche-2026-08-26/cert-2.md`](noche-2026-08-26/cert-2.md)) · y **la lista de la mañana
del 25 estaba vieja en sus dos primeras filas**: la carrera y la validación entraron la
noche del 25 y sus tests llevan desde entonces verdes dentro de la suite — quien retome
esto, **abra el test antes que el documento**

> ## ✅ VERDE: 1.566 pruebas, 11.703 aserciones
>
> **Cinco son de la [§243](05-codigo-muerto-y-roto.md)**, y las otras dieciocho llevaban tres
> commits sin contarse: **este bloque decía 1.543, y en HEAD ya eran 1.561.** El desglose, que es
> lo que hace que la corrección sea comprobable y no otra cifra escrita a mano — `850a76e` **+7**,
> `9e8aa96` **+5**, `e906064` **+6**; suman los dieciocho exactos, y `50b0f10`, que **es el commit
> de este mismo documento**, no tocó ninguno.
>
> **La regla que falló no es «actualizar el estado»: es que el número se copió en vez de
> medirse.** `docs(estado)` se escribió al día en todo menos en la única línea que sale de correr
> algo. Se remide con la suite entera, nunca con `--filter`:
> `docker exec 8myvc-app-1 php artisan test | tail -3`.
>
> **La de la [§242](05-codigo-muerto-y-roto.md) hizo falta la suite entera**: con el `--filter` del
> módulo el arreglo salía verde con `Carbon::now()` dentro, que es justo lo que `RelojUnicoTest`
> existe para impedir.
>
> **1.525 eran la mañana del 26.** Los diecisiete de más son de la tarde: **siete** de la
> prematrícula pública ([§236](05-codigo-muerto-y-roto.md)), **cuatro** del acotado al dueño
> ([§237](05-codigo-muerto-y-roto.md)), **tres** del tema del muro
> ([§238](05-codigo-muerto-y-roto.md)) y **tres** del boletín del independiente
> ([§239](05-codigo-muerto-y-roto.md)). La suite entera, no el filtro.
>
> El `ROJO A PROPÓSITO` de `acd189b` está **arreglado, no explicado**. Joseth decidió
> regenerar, y se regeneraron **21 instantáneas** — las nombró el propio fallo, no se
> borraron a ojo las que contenían el objeto `year`.
>
> **Y se comprobó el diff antes de aceptarlo, que es lo que hace que regenerar no sea firmar
> en blanco**: **0 líneas quitadas, 42 añadidas**, que son `usa_consecutivo_certificados` y
> `usa_folio_certificados` × 21 ficheros. **Ni un campo cambiado ni renombrado**, así que
> ningún cliente se rompe por recibir dos campos de más. El aviso al front va en
> [DESPLIEGUE.md](../DESPLIEGUE.md) **con estado y con los endpoints exactos**.
>
> De los dos fallos que quedaron al regenerar, **sólo uno era real**:
>
> - `HuecosDelSeedTest` **no lo era**: corrió mientras el fichero estaba borrado, así que el
>   hueco faltaba **por el orden de la regeneración**. Recalculado sobre el fichero
>   regenerado, coincide exactamente. *Se comprobó replicando el detector, no suponiéndolo.*
> - `MuestreoDeLecturasTest` **sí**: el barrido de lecturas esperaba 200 de `folios/iniciar`.
>   Se sacó de ahí, **y no se metió en `lecturasRotas()`** —esa lista dice de sí misma que
>   «ninguna es reciente», y meter una retirada a mano volvería falsa esa frase—. Tiene
>   proveedor propio, `lecturasRetiradas()`: **una rota es una deuda, una retirada es una
>   decisión**, que es la distinción de `CLAUDE.md`. Sin esa entrada `folios/iniciar`
>   desaparecía del muestreo y no volvía a mirarla nadie.
>
> larastan nivel 7 `[OK]`, pint PASS.

**Antes de eso: TODO FUNDIDO, NINGUNA RAMA, Y EL CI
OTRA VEZ EN VERDE** · `main` subido · **1.516 pruebas, 11.401 aserciones, larastan nivel 7
`[OK]`, pint PASS**, medido **en la fusión** y no en ninguna rama · **el CI llevaba tres
pushes en rojo** por un control que el clon superficial de Actions no podía ejercer
([abajo](#y-un-tercer-árbol-tres-pushes-después-el-ci-llevaba-tres-correos-en-rojo)) · **un colegio dado
de baja y borrado del servidor el 25 ago: son QUINCE, no dieciséis** — las cifras
fechadas antes del 25 ago dicen dieciséis y **así se quedan**, porque se midieron sobre
dieciséis; lo que se actualizó es lo que sigue vivo · **sin coordinación**: `8myvc-94`
ya no está y nadie ha ocupado su sitio, así que **nadie está mirando el conjunto** —
quien llegue, que lo lea entero antes de coger nada

> **Ya no hay ramas ni worktrees: `main` es lo único que hay.** Se cerró la noche en
> paralelo por decisión de Joseth. Lo que entró de una vez, además de lo que ya estaba:
> **notas de alumno** (con su revisión), **CONTROLES-1** y **GEMELO-1**. Las trece ramas
> que ya estaban fundidas se borraron sin perder nada; las cinco carpetas de
> `.worktrees/` se quitaron.
>
> **Y tres tests sin trackear se rescataron antes de quitar sus árboles**, porque eran
> trabajo vivo de dos sesiones y no estaban en ningún commit. Están en
> `8myvc-cola/rescatado-2026-08-25/`, **fuera del repo**:
> `DiagnosticoPrematriculaTest.php` (de `.worktrees/79`),
> `AlumnoEnAsisSigueSaliendoTest.php` y `CensoDelAsisTest.php` (de `.worktrees/e0`).
> **Nadie los ha ejecutado ni revisado**: quien los quiera, los mueve a `tests/` y los
> corre — no se metieron en `main` a ciegas.
>
> **La fusión encontró un rojo que ninguna rama tenía**, y es el aval de que medir en la
> fusión no es ceremonia: [ver abajo](#controles-1-y-gemelo-1-fundidas--y-un-rojo-que-solo-existia-en-main).

---

## La migración planeada está terminada

Las fases 0–4 del [plan](00-plan-migracion.md) están cerradas, la 5 recortada y la
6 es continua por diseño. **Laravel 13 sobre PHP 8.4**, con red de seguridad y
autenticación real. Hoy: **542/542 rutas con la respuesta comprobada — el 100% —,
98/98 controladores, larastan nivel 7 `[OK]`, pint PASS.**

> **Y con qué suite se midió, que aquí decide el número:** las 542 salen de la
> **suite entera** (`medicion/lote-y-cobertura`, 1.362 tests, 9.223 aserciones,
> 848 s). Con `--testsuite=Contrato` se ven **541**, porque `GET /` sólo la toca el
> stub de `laravel new` y ahí cae siempre del lado de las no comprobadas. **El
> número citable es el de la suite entera**, y no es lo mismo que el de Contrato.
>
> **Los dos barridos siguen sin contar como comprobar** —`AutenticacionTest` toca
> 523 rutas en una ejecución y `RutasPreLoginTest` 530— y eso es lo que hace que el
> 100% signifique algo.
>
> El total de tests **varía por rama esta noche**: hay cuatro sin fundir. `7b` cerró
> con 1.374 en la suya y `ad` con 1.362 en la suya; **no se suman**, y el de `main`
> se cuenta el día que se fundan.

> Ese `[OK]` estuvo **en rojo** un rato la noche del 24: `ProfesoresController:473`
> llegó a `main` dentro de un commit que arrastró trabajo de cinco sesiones, **sin
> la pasada de larastan de su autor** ([05 §178](05-codigo-muerto-y-roto.md)).
> Arreglado en `955125a`, y **verde comprobado con la base contada antes de medir**
> —92 tablas, 2.351 usuarios—, que es el paso que la [§176.3](05-codigo-muerto-y-roto.md)
> convirtió en obligatorio. Al empezar había **0 tests** y
`route:list` estaba roto.

Lo que sigue **no son fases pendientes de la migración**: es el trabajo que se
decidió hacer después.

---

## LO QUE ESPERA TU RESPUESTA — la lista de la mañana del 25, por consecuencia

**Ordenada por lo que pasa si no se contesta**, no por antigüedad. El detalle de cada
una está en el 05 o en el 09; aquí sólo lo que decide.

### Papel oficial y cuentas — lo primero

| | Qué | Si no se contesta |
|---|---|---|
| **1** | ~~**Abrir el certificado quema un consecutivo, y la lectura+escritura no está en transacción.**~~ **CERRADO.** La carrera y el `FOR UPDATE` entraron la **noche del 25** ([cert-1](noche-2026-08-25/cert-1.md)); **el rastro, el 26** ([cert-2](noche-2026-08-26/cert-2.md)) | Nada. Los tests llevan verdes en la suite desde el 25, y **desde el 26 cada quema deja escrito quién, cuándo y de qué número a cuál** — que era la mitad de la [05 §231](05-codigo-muerto-y-roto.md) que se podía cerrar sin migración. **Lo que sigue abierto y es tuyo: la tabla de certificados emitidos**, o sea *«¿cuántos emitimos este año y a quién?»*, que apartaste a propósito |
| **2** | ~~**`cambiar-contador-certificados` y `-folios` fijan el consecutivo sin validación, con `auth.personal`.**~~ **CERRADO.** La validación (`^\d+$`, 422) el 25; **el permiso, el 26**: elegiste `esAdministrativo` y es una línea en `consecutivoValidado()`, que cubre los dos endpoints porque los dos pasan por ahí | Nada aquí. **Pero abre una del front, que no se entera solo**: las dos pantallas que llaman a `-certificados` —la vieja y `app2`— **enseñan el control sin mirar el rol**, así que un docente verá «Contador no guardado». Está en [cert-2 §6](noche-2026-08-26/cert-2.md) con lo que hay que decirles. *(Y `-folios` **no lo llama nadie vivo**: el «Folio» de la pantalla vieja escribe `nro_folio` por `alumnos/guardar-valor`, que es otra cosa.)* |
| **2bis** | **¿Manda el backend `version_minima_app` en la respuesta de `/login`?** Tú ya dijiste **sí a que la app bloquee**; **la app ya lo tiene escrito y probado** (414 pruebas), enganchado en los tres sitios por los que pasa una respuesta de `/login` —incluido el refresco, que es el único punto en el que se entera **sin que el usuario salga y vuelva**—. **El campo es `version_minima_app` y el valor es el `versionCode` (el `+N`), no la versión con puntos**; se lee **tolerante** (`"12"` como cadena también vale). **Y hay un plazo:** si se prefiere otro nombre, **hay que decirlo antes de que se publique una versión de la app leyendo éste** — después, cambiarlo obliga a mandar **los dos campos** durante un tiempo | **Sin ese campo, lo de la app es código dormido**: con el fallo abierto por defecto no bloquea a nadie mientras nadie lo mande. **El día que se mande, empieza a bloquear** — y **es lo único que hoy permitiría retirar un endpoint** en los quince. Con la carga dicha: **subir ese número es una ceremonia de despliegue**, porque **desde el cliente no se distingue un `.env` mal puesto de un colegio exigente** |
| **1bis** | ~~**La prematrícula pública deja escrita la ficha de un menor sin matrícula y sin usuario, y no hay transacción.**~~ **LA (a) CERRADA el 26 ago por la tarde** ([05 §236](05-codigo-muerto-y-roto.md)); **la (b) sigue entera y es tuya.** Medido, determinista, **no es una carrera**: en `PUT login/crear-prematricula` —**una de las once rutas públicas**, la llama alguien **sin cuenta**— si falta `grupo_id` o es uno que no existe, **el `INSERT` de `alumnos` ya pasó** y revienta el de `matriculas`. Queda escrito **nombres, apellidos, documento y celular de un menor**, huérfano. *Y las tres primeras filas de la matriz dicen lo contrario y también importan: si falta `nombres` o `sexo` no escribe nada — **el daño no es «cuerpo incompleto», es «llegó a `matriculas`»**.* · **Y el reintento es peor que el fallo: el segundo intento no da otro 500, da un 200 que MIENTE.** Encuentra la ficha huérfana y contesta *«Ya existe el alumno. Entre con su cuenta»* — **y esa cuenta nunca se creó**, porque el `INSERT` de `users` va después del que reventó. **El padre queda fuera del formulario para siempre para ese hijo**, mandado a una puerta que no existe y **sin ningún error que reportar**. Predicho por escrito antes de medirlo. Y **es el camino normal**: el front no tiene `ng-disabled` en ese botón y **el formulario sigue relleno tras el error** | **Hay dos cosas que decidir y son distintas.** ~~**(a) El mecanismo**: se cierra con una transacción, y eso no espera a nadie.~~ **HECHO**: las cuatro escrituras en transacción **y** `grupoQueExiste()` con **422 delante de todo** — las dos, porque la transacción quita el huérfano pero **deja el 500 intacto**, y el 500 de una ruta pública y sin autenticar es el camino nuevo al pendiente del `.env`. Siete tests, y **el control visto rojo**: quitando la transacción y dejando el guard cae **exactamente uno** de los siete, que es el que la nombra — los otros seis pasaban sin ella. **(b) Lo que ya haya escrito en los quince**: eso **no lo decide ninguna sesión**, y hoy **no lo sabe nadie** — la consulta de sólo lectura que lo cuenta está escrita y **no se ha corrido en ningún colegio**: `SELECT COUNT(*) FROM alumnos a LEFT JOIN matriculas m ON m.alumno_id=a.id WHERE m.id IS NULL AND a.deleted_at IS NULL AND a.user_id IS NULL`. · **Y la exposición está sin medir, no en cero:** en la base de desarrollo **`prematr_nuevos = 0` en los ocho años**, así que **ahí la pantalla ni se enseña** — pero *cuáles de los quince la tienen encendida no lo sabe nadie*, y ésa es otra pregunta para la fase 0. · **Y un pendiente viejo gana un camino público:** el [01](01-plan-seguridad.md) tiene sin verificar *«con debug on, un error filtra el `.env` entero»* y el [09](09-pendientes.md) dice «comprobarlo colegio a colegio». **El hallazgo no es que filtre —eso depende del `.env` de cada uno— sino que esta ruta le da a ese pendiente un camino público y sin autenticar**: el cuerpo del 500 trae `Host`, `Port` y `Database`. Medido con `APP_DEBUG=true`, que es lo del contenedor; **en producción depende de cada colegio y nadie lo ha mirado**. · **El censo de huérfanos en la base de tests da 0 y NO vale**: tiene 68 alumnos y **cero matrículas en `PREA`**, o sea que por ese endpoint no ha pasado nunca una prematrícula ahí. *No distingue «no ocurre» de «no ha ocurrido en esta copia».* |
| **2ter** | **Cuatro columnas en blanco en la rejilla «Docentes contratados»** —la de abajo de `/panel/profesores` en la web vieja—: Usuario (`username`), Nacimiento (`fecha_nac`), Email (`email_usu`) y Celular (`celular`), en `ProfesoresCtrl.ts:266-269`. Las vació `c47ab50` al recortar `Profesor::contratos()`. El recorte está bien hecho (`GET contratos` es la única ruta de su controlador **sin `auth.personal`** y entregaba el documento, el domicilio y el móvil de los docentes a cualquier sesión válida) y **no se deshace**; lo que falló fue el censo de consumidores del propio commit, que acertó con Flutter y **se dejó esta rejilla** | **YA NO ES UNA VENTANA FUTURA: está abierta.** Joseth desplegó el backend el 25 ago (`eb95cbc`, mismo hash comprobado en los quince), así que **esas cuatro columnas están vacías ahora mismo en todos**. La comparación la da la propia pantalla: la rejilla de ARRIBA sigue llena, porque viene de `GET profesores` —con `auth.personal`—. **Llenarlas cuesta cero peticiones** (un `valueGetter` cruzando por `profesor_id`; los cuatro campos ya están en memoria) **y no deshace el recorte**, porque el dato volvería por la ruta que sí lleva guard. La otra salida es quitar las cuatro columnas. **Decide Joseth.** · *Y una que salió bien sin que nadie lo planeara: esa rejilla guarda la FILA ENTERA al editar cualquier celda, así que con el código del 21 ago habría BORRADO esos cuatro campos en la base —y `users.username` es UNIQUE—. No pasa porque `putUpdate` los guarda detrás de `$vinieron->trae(...)`, y ese arreglo iba en la MISMA tanda. Separar los dos commits en dos despliegues habría borrado datos.* |
| **2quater** | **`app2` se rompe la primera vez que alguien pulsa F5, y el arreglo vive en un repositorio que no documenta nadie.** La vieja usa rutas con almohadilla (`html5Mode` comentado) y **por eso este fallo no puede existir en ella**; `app2` usa rutas de camino y **el `.htaccess` no tiene reescritura**. Servido el build real con un servidor estático: `/` da 200, `/alumnos` y `/panel` dan **404**. Lo midió `myvc-front-3b` | **Aparece el primer día de producción de la nueva, no antes**, y en la forma peor: arranca bien, se navega bien, y **se cae al recargar, al abrir un marcador o un enlace compartido**. En los quince. Dos salidas, las dos costeadas por el front: `RewriteRule` en el `.htaccess` (probada **al revés** también, que un `.js` o el logo no se los trague la regla) o `withHashLocation()` — **cambia todas las URLs, así que es decisión tuya**. Y el argumento *«la almohadilla conserva los marcadores»* **probablemente es falso**: la vieja usa `/#/panel/alumnos` y la nueva usaría `/#/alumnos` |
| **2quinquies** | **`app2` no arranca desde `up/`: pantalla en blanco, y no es el F5.** Medido con Apache 2.4 de verdad y el build de verdad: `GET /up/` da 200, pero el navegador pide `/chunk-….js` **en la raíz del dominio** y recibe 404 — el fichero está en `/up/chunk-….js`. En Chrome: título «MyVC», **texto visible vacío, `app-root` inexistente, once recursos fallidos**. La vieja funciona desde `up/` porque usa rutas relativas y lleva el `<base href>` comentado; **`app2` lleva `<base href="/">`** | **Degrada la casilla anterior: no es «se rompe al recargar», es que no arranca nunca, ni la primera vez.** Y mata el último argumento de la almohadilla: `<base href="/">` rompe igual con `#` que sin él. **La primera decisión ya no es «reescritura o almohadilla»: es «¿`app2` vive en `up/` o en la raíz del dominio?»**, y de ahí cuelgan el `base href`, la `RewriteBase` y todas las URLs. **Desde el backend hay una razón dura para `up/`**: la API se sirve en **el mismo subdominio, bajo `/8myvc/public/api`** (`DESPLIEGUE-REFERENCIA.md:232`), así que **un `RewriteRule . /index.html` en la raíz se tragaría las llamadas a la API** salvo que alguien acierte a excluirlas. En `up/`, con `RewriteBase /up/`, eso no puede pasar **por construcción**. El front ya escribe todo para `/up/`, y **el ensayo pasó**: un colegio de mentira con Apache 2.4.66, `up/` con el build nuevo y **este backend real por `ProxyPass`**, conducido en Chrome —entrar, cuatro pantallas con datos, **F5 en cada una**, enlace profundo, salir y volver— **con cero errores y cero recursos en 404**. Sin probar, y dicho para que no se dé por probado: el refresco silencioso y **las pantallas de impresión e informes pesados**, que son justo las fichadas por dar 504 y 500 · ***MEDIDO EL 30 AGO, Y LA PREGUNTA YA TIENE RESPUESTA EN PRODUCCIÓN: `app2` NO vive ni en `up/` ni en la raíz — vive en `up2/`, y está desplegado en los DIECISÉIS.*** *`/up2/` contesta 200 en los quince colegios, en `demo` y en `lal`; la carpeta es un clone de **`myvc_dist2`** (`ef42e3e`, 29 ago) y sirve `<base href="/up2/">`, que es el valor correcto para esa ruta. **La decisión de dónde vive está tomada de hecho**; lo que sigue abierto es la reescritura del `.htaccess` para el F5, que es otra cosa. [TRASLADO-LAL §2B](../TRASLADO-LAL.md)* |
| **2sexies** | **Y la casilla que sí falta es pequeña: no hay bucle escrito para `up/`.** *(Corrección: escribí que «el despliegue del front no está escrito en ningún sitio» y **era falso** — `DESPLIEGUE-REFERENCIA.md:25 y 202` documentan que el front vive en la carpeta `up` de cada subdominio y `myvc_front_2` en `plus`. Mis dos `grep` daban cero porque busqué `myvc_dist`, y **aquí eso se llama `up`**: un `grep` contesta por el nombre y la pregunta era por la cosa. Lo encontró `myvc-front-3b`.)* | El bucle de `DESPLIEGUE.md:272` es de `/8myvc` y **no hay ninguno escrito para `up/`**. **Y no es el mismo con la ruta cambiada:** `up/` es un `git pull` del repositorio construido (`myvc_dist`, con remoto propio en GitHub) — **sin `migrate`, sin `config:cache`, sin `route:cache`**, que es la mitad del bucle del backend. Lo que sí se repite igual: **la segunda cuenta de cPanel (`lalvirtual.edu.co`) que el `for` no alcanza** y hoy se hace a mano |
| **2septies** | **`demo` no está en ninguna lista de despliegue, y su login lo rompe un `if` cableado en el front** (29 ago 2026, medido en el servidor con Joseth). Joseth vio que `demo` iba atrasada; el `git pull` de su `up/` abortó por una modificación local del bundle y **no se pudo leer qué era**: un bundle minificado es **una sola línea**, así que `1 insertion(+), 1 deletion(-)` vale igual para un carácter que para el fichero entero — el `--stat` no distinguía nada. Se descartó con el `checkout -f` documentado y **acto seguido el login empezó a dar 404** contra `…/8myvc/public/demo/5myvc/public/auth/login`. La causa está en `app.ts` de `myvc_front`: `if(location.href.indexOf('demo') > 0) { server = dominio + 'demo/5myvc/public/'; }` — **concatena en vez de sustituir**, apunta a la API vieja y **a una carpeta que ya no existe** (`~/demo.micolevirtual.com` sólo tiene `8myvc/`, `up/` y `up2/`). Encaja con que la modificación descartada fuera ese mismo parche a mano, aunque **no se puede probar: el contenido ya no existe** | **Tres decisiones, y son distintas.** **(a) El arreglo del `if`.** Borrarlo en el repo del front es una línea y deja a `demo` en el `server = dominio + 'api/'` de todos — pero **mueve el hash del bundle en los quince** y se convierte en tanda de front. El `sed` en el `up/` de `demo` desbloquea hoy sin mover a nadie, y **muere en el siguiente `checkout -f`**, que es exactamente lo que acaba de pasar. **(b) Si `demo` entra en las listas como uno más**: hoy no está ni en el `for` de comprobación ni en el recuento de quince, y por eso se quedó atrás **sin que nada lo señalara**. **(c) Dos colegios que destapó el barrido de hashes**: `coljordan` sirve `index-DDM1FZCB.js` —atrasado— y **`lal` no contestó al `curl`**, que no es lo mismo que ir atrasado. ***CONTESTADO el 30 ago, y `lal` sale limpio:*** *`lal.micolevirtual.com` da **NXDOMAIN** —ese subdominio **no existe**, `lal` es el único colegio que vive en la otra cuenta, bajo `lalvirtual.edu.co`— y por su URL de verdad sirve **`index-Bermvdik.js`, el mismo que `casb`, `coab`, `cads` y `coal`**. **El que sigue atrasado es `coljordan`, y sólo él.** El barrido no falló: preguntó por una dirección que nunca ha existido, y un `curl` mudo se leyó como «colegio que no contesta» en vez de como «URL que no existe». [TRASLADO-LAL §9.2](../TRASLADO-LAL.md).* · **Y la condición mira la URL entera, no el host** (`indexOf('demo') > 0`): el defecto **viaja en el bundle compartido a los quince** y hoy sólo dispara donde la cadena `demo` aparece en la URL. · *Lo que se descartó por el camino y NO hay que volver a mirar: el backend de `demo` está al día (`50b0f10`, desplegado el 28); el **302** de `POST api/auth/login` **no es un fallo** —`curl` sin `Accept: application/json`, y `casb` da el mismo—; y el `<base href="http://localhost:9000/">` **está comentado**, mi `grep '<base[^>]*>'` casó dentro del comentario y lo leí como etiqueta viva.*
| **2nonies** | **`storage/logs/laravel.log` SE LEE DESDE INTERNET, Y NO ES SOLO DE `lal`** (30 ago 2026, medido con `curl` desde fuera). `https://<colegio>/8myvc/storage/logs/laravel.log` devuelve **200 con el contenido del log** en **`lal`, `casb`, `coab`, `cads`, `coljordan` y `coal`** — seis de seis probados; `demo` da 404, probablemente porque no hay fichero. Lo que sale son trazas de excepción con rutas absolutas del servidor (`/home/micolevi/public_html/8myvc/vendor/...`) y lo que la aplicación haya registrado. **La causa es la topología, no el código**: `8myvc/` cuelga entero del docroot y sólo `public/` debería ser alcanzable. · ***Lo que NO es, y lo comprobé porque era mi primera sospecha:*** *los `.php` **se ejecutan, no se descargan** — `bootstrap/cache/config.php` da 200 con **cuerpo vacío** y `config/database.php` da 500. **Las credenciales no se filtran por ahí.** Y `.env` da **403** por la regla de dotfiles del servidor.* | **Dos arreglos, y son de riesgo muy distinto.** **(a) El de hoy, sin riesgo: un `.htaccess` con `Require all denied` DENTRO de `8myvc/storage/`.** Laravel nunca sirve ficheros de ahí por HTTP —los entrega por PHP—, así que denegarlo **no puede romper nada**, y cierra esto en un colegio con una línea. **(b) El completo, que puede tumbar a los quince si sale mal:** `Require all denied` en la raíz de `8myvc/` más `Require all granted` al principio de `public/.htaccess` — que es un fichero **versionado**, así que llegaría por `git pull` a todos. Es lo correcto y **hay que probarlo con Apache de verdad antes**, porque si el `granted` no surte efecto **la API entera da 403**. · **Mide primero cuánto hay expuesto**, en el servidor y no descargándolo: `ls -lh 8myvc/storage/logs/` y `grep -c` por nivel. Si el log lleva años, lo que hay dentro decide si además hay que avisar · **HECHO el 30 ago 2026 con `tools/proteger-storage.sh`:** 16 rutas en `micolev1` —los colegios y `demo`—, **`ya estaban: 0`** (o sea que la exposición era universal, ninguno tenía `.htaccess`), y **comprobado por URL: 15 pasan de 200 a 403 y la API sigue dando 401**, que es lo que no podía romperse. **Queda `lal`**, que está en la otra cuenta y ningún glob alcanza: se corre allí con `--aplicar ~/public_html/8myvc`. **Falta el arreglo (b)**, el completo, que sigue sin probar |
| **2decies** | **Las cifras de colegios no cuadran, y lo medido dice DIECISÉIS** (30 ago 2026). En `micolev1` hay **quince** carpetas de colegio con `8myvc/storage` —más `demo`—, y **`lal` es la dieciseisava**, en la otra cuenta. *(Lo que parecía una anomalía no lo era: la carpeta `fortul.micolevirtual.com` **se sirve como `coaf.micolevirtual.com`**, lo dijo Joseth; contesta, y da 403 y 401 como los demás. Es un caso más de «la carpeta no se llama como el host», junto con `casb`, `coab`, `cads`, `caz`, `comad` y `maranatha`.)* **Y el conjunto de quince es IDÉNTICO, nombre a nombre, al inventario de `vendor/` del 18 ago** que hay en [DESPLIEGUE-REFERENCIA](../DESPLIEGUE-REFERENCIA.md): cero altas, cero bajas. | **CLAUDE.md dice que el 25 ago un colegio se dio de baja y «se borró entero del servidor», y en `micolev1` no se borró nada.** Las salidas son dos y **decide Joseth cuál es**: *(a)* el colegio que se fue **no vivía en `micolev1`** — y entonces la pregunta es dónde vivía, porque **el `~` de la cuenta vieja tiene 31 entradas y sólo se ha inventariado `public_html`**: si ahí hay más sitios, **la baja del alojamiento se los lleva también** y el plan de traslado está incompleto; *(b)* la baja no se ejecutó en el disco, y entonces sobra un colegio entero en un volumen al 99% **al que todos los bucles siguen haciendo `git pull` y `migrate --force`**. Se contesta con un `ls -la ~` en `micolevi` y mirando qué colegio fue |
| **2octies** | **Trasladar `lal` a la cuenta de `micolevirtual.com` y dar de baja el segundo alojamiento** (pedido por Joseth el 29 ago 2026; **plan escrito, nada ejecutado**: [TRASLADO-LAL.md](../TRASLADO-LAL.md)). Su plan de partida era dejar un `index.html` que redirigiera al subdominio, y **eso no consigue lo que pide**: un redirect **cambia la URL** —que es justo lo que quiere evitar—, **necesita el alojamiento viejo vivo** para servirlo, **no cubre `/8myvc/public/api`** —así que rompe a todo el que tenga `lalvirtual.edu.co` guardado como servidor en la app de Flutter, que no se despliega por colegio— y **apaga el logo del correo de recuperación de los quince**, porque `reset-password.blade.php:23` lo pide a `https://lalvirtual.edu.co/up/images/`. La forma que sí: **el dominio se queda y sólo cambia la IP a la que apunta** —dominio adicional en la cuenta de `micolev1`, mismo document root—, con lo que **no cambia una línea de código, de `.env` ni de los tres front** | **No corre prisa y no bloquea nada, pero tiene una trampa que hay que contestar ANTES de pedir la baja: si la zona DNS del dominio la sirven los nameservers de esa misma cuenta, darla de baja no deja el sitio raro, deja el dominio SIN RESOLVER.** Es la 0.1 del plan y es lo primero que hay que mirar. · **Y lo que se gana no es el dinero:** hoy `lal` es el único colegio fuera de todos los bucles —despliegue, paso 0, hashes del front, cron—, y cada uno dice «repetir a mano en la otra cuenta». *Lo que se hace a mano es lo que un día no se hace*, que es literalmente lo que le pasó a `demo` en la casilla de arriba. · **A cambio empeora una cosa**: los quince quedan en una sola cuenta de cPanel, y un problema de la cuenta pasa de afectar a catorce a afectar a quince. · **Ya decidiste dos cosas y quedan dos por mirar:** la URL **se queda en `lalvirtual.edu.co` para siempre** —de ahí que el traslado no toque código— y **hay buzones `@lalvirtual.edu.co` en uso**; falta saber **si están en ese cPanel o en un Google Workspace** (se contesta con un `dig MX`, §2 bis del plan) y **dónde vive la zona DNS**. · **MEDIDO TODO el 30 ago, y las dos salieron con respuesta:** la zona la sirven `ns1..ns4.a2hosting.com` —**los mismos nameservers para los dos dominios, mismo proveedor**—, así que no hay que emigrar a otro DNS: **hay que pedirle al proveedor que mueva el dominio de una cuenta a la otra**, y eso desatasca de paso el «el dominio ya existe» de cPanel. Y **no hay Google Workspace**: `MX` al propio servidor, SPF de A2, DKIM `default._domainkey` — **los 16 buzones (~341 MB) están dentro de la cuenta que se da de baja y se borran con ella**, así que el traslado tiene dos mitades. · **Y salieron tres cosas que nadie buscaba:** `lal.micolevirtual.com` **no existe** (NXDOMAIN), la raíz de `lalvirtual.edu.co` **redirige a un `/landing/`** que no está en ningún inventario y que huele a WordPress, y **`lalvirtual.com` —el `MAIL_FROM_ADDRESS` de los quince— NO ESTÁ REGISTRADO**, que es la [§9.1](../TRASLADO-LAL.md) y es más importante que el traslado |
| **3** | ~~**Publicar lo terminado.**~~ **HECHO el 25 ago**: `eb95cbc` desplegado en los quince con sus cuatro migraciones, comprobado con el mismo hash en todos | Lo que abrió y lo que desbloqueó, en [DESPLIEGUE-REFERENCIA.md](../DESPLIEGUE-REFERENCIA.md#lo-que-trajo-la-tanda-del-2225-ago-2026--desplegada-el-25-ago-en-eb95cbc). **Desbloqueadas dos cosas de otros repositorios**: la versión de `myvc_flutter` que llama a las tres rutas nuevas —la condición era estar en los quince— y el typo de `PapeleraCtrl:62`, que era lo único que tapaba `grupos/forcedelete` desde la interfaz |
| **4** | **La firma del profesor: dos endpoints, permisos distintos, y sólo uno comprueba de quién es la imagen** ([05 §168](05-codigo-muerto-y-roto.md), §182) | La mina sigue puesta. **Y los dos criterios no se contienen**, así que *«cuál gana»* **no se puede contestar eligiendo el más restrictivo** |

### Y un hecho administrativo, que se apunta porque no lo vio nadie

**La noche del 24 al 25 se quedó sin coordinación en `8myvc`.** El briefing
(`8myvc-cola/noche-2026-08-24/BRIEFING.md`) dice que coordina `8myvc-34` y que **`main` no lo
mueve nadie más que quien coordina, y sólo en el árbol raíz**. `8myvc-34` **dejó de estar viva
en algún momento de la madrugada**, y el documento siguió escrito **sin nadie que lo
administrara**: el turno, la tabla de ficheros cogidos y quién mueve `main` quedaron congelados
en la foto de hace horas.

**Lo que sí funcionó, y por eso esto es un apunte y no un incidente:** las dos sesiones vivas de
`8myvc` se preguntaron entre ellas y lo cerraron —`main` lo movía `8myvc-7b`, con autorización
tuya en persona y con sus motivos escritos, no una sesión fantasma—, y la coordinación de
`myvc_front` **declaró que no tenía autoridad aquí** en cuanto se le preguntó, en vez de ocupar
el hueco.

> **Un briefing escrito sin nadie que lo administre es un hecho que se deja por escrito, no un
> hueco que se ocupa porque está vacío.** Ninguna de las dos sesiones se postuló para coordinar,
> y eso fue lo correcto: **nadie hereda una autorización por ser el que queda**.

Se apunta aquí para que mañana leas *«faltó coordinación en `8myvc` esta noche»* y no
*«nadie se dio cuenta»*.

### Disciplina, certificados e interruptores ([09 §15](09-pendientes.md))

| | Qué | Si no se contesta |
|---|---|---|
| **5** | **`dis_procesos.firma_alumno` / `firma_acudiente`**: módulo vivo, **nadie las lee** | **Hoy el sistema no puede contestar si un proceso disciplinario se firmó** — el dato que hace falta meses después, cuando alguien reclama. **¿Abandonada o sin terminar?** |
| **6** | **Dos interruptores de `config_certificados` que se marcan y no se aplican** | Un documento que se entrega firmado **sale distinto de lo que el colegio pidió, y quien lo marcó no tiene forma de saberlo** |
| **7** | **Seis tablas `df_*` sin una sola referencia** | Nada, hasta que alguien las borre: **es una migración destructiva en quince producciones** |

### Y una que ya tiene su número, medida esta madrugada

| | Qué | Por qué decide |
|---|---|---|
| **7bis** | **«Quién del personal puede qué»: hoy la respuesta es casi todo.** Un token de `Usuario` **activo, no superusuario y sin un solo rol** escribe en **87 endpoints** —años, periodos, escalas, materias, asignaturas, ausencias, disciplina, certificados, contratos, enfermería—. Un `Profesor` escribe en **93**: **seis de diferencia** ([05 §213](05-codigo-muerto-y-roto.md)) | **Tener el rol de profesor no es lo que abre la API**: la abre `auth.personal`, haciendo lo que dice. Esa pregunta llevaba días esperando **sin número**; ahora lo tiene. **Y cuatro de esos endpoints son `GET` que escriben** — uno **inserta en tres tablas**, que es el contraejemplo exacto de la decisión que se tomó para `disciplina/mis-fichas` |

### Código muerto: 34 métodos, 1.019 líneas — **con sus límites pegados al número**

| | Qué | Y qué NO prueba |
|---|---|---|
| **7ter** | **34 métodos públicos de controlador sin ningún camino desde una ruta: 1.019 líneas.** Revisados **8.351 ficheros en once árboles de cliente** —incluidas las seis worktrees del front y `tardanzasMyvc-old`, que **es** un cliente— **y ningún cliente los llama**. Tres cajones: **25 que nadie nombra**, **4 que la documentación del front cita** (borrarlos **invalida documentación viva de otro repositorio**, ya avisado en su buzón), y **11 en dos subárboles que se borran enteros o no se borran** ([05 §216](05-codigo-muerto-y-roto.md), §217) | **no ve ramas de cliente que no estén en disco**; **no prueba que nadie esté añadiendo un `Route::` a uno de los 34 ahora mismo**; y **sigue llamadas, no ramas**, así que un método invocado sólo dentro de un `if` que nunca se cumple **cuenta como vivo**. *Sin estas tres líneas, esto sería una decisión tomada sobre una certeza que no tenemos.* · **Y una cuarta, añadida el 25 y de otra clase que las tres:** esto es un **censo de llamadores**, y un censo de llamadores **mide el presente**. La noche del 25, en el lote del boletín independiente, apareció un método cuya lista de consumidores está **vacía** y cuyo riesgo está **entero**, porque su consumidor está **previsto y no construido** —lo dice la cabecera del propio servicio—. **El primero que se rompe puede ser un consumidor que aún no existe, y ésos no salen en ningún censo.** No propone revisar los 34; dice qué clase de certeza da su número |

### Servidor — cuatro `for` que ahora son uno

| | Qué | Si no se contesta |
|---|---|---|
| **8** | **`php tools/fase-cero-de-los-dieciseis.php --csv $(cat colegios.txt) > fase0.csv`** — junta los `for` pendientes en **una visita y un formato**. **Eran cuatro y desde el 26 ago por la tarde son SEIS**: le entraron el censo de la prematrícula —la (b) del `1bis`— y el de las notas fuera de escala, que es la comprobación previa que la validación desplegada el 25 **nunca tuvo** ([05 §240](05-codigo-muerto-y-roto.md), lo cazó `myvc_flutter`) | **La fase 2 de las definitivas sigue bloqueada**, que es lo que pediste desde el principio. Y de paso: **el esquema congelado se da por igual en los quince y nunca se ha comprobado** — y **cuántas fichas de menores quedaron huérfanas tampoco lo sabe nadie** |

### Y el frente que abrió el front esta noche, que es de los de contestar

> **⚠ REPASADO EL 3 SEP 2026, Y UNO DE LOS TRES YA ESTABA CERRADO.** El de
> `GET bitacoras/{user_id?}` **está arreglado desde AUD-5**: el método llama a
> `Autoriza::exigirVerAuditoriaDe($user, $user_id)` —lo propio siempre, lo de otro con
> `can_view_auditoria`— y lo dice en un comentario dentro. **La casilla de abajo sigue
> describiéndolo como *«detrás de `auth.personal` y sin `persona.propia`»*, que era cierto
> el 26 ago y hoy no.** No se borra el texto: se marca, porque el hallazgo original y su
> medición siguen siendo el motivo de que exista el guard que hoy lo tapa.
>
> **Los otros dos siguen abiertos de verdad, comprobados en el código y no en esta tabla**:
> `GET profesores` y `GET alumnos/sin-matriculas` llevan **sólo `auth.personal`**, y
> `getSinMatriculas` conserva el `INNER JOIN matriculas` y sigue devolviendo `religion`,
> `celular`, `direccion` y `fecha_nac` de los matriculados del año.
>
> **Y que salieran dos abiertos y uno cerrado es lo que hace que el repaso valga**, igual
> que decía la casilla 13 unas líneas más abajo: si los tres hubieran salido cerrados, lo
> sospechoso sería el método. Un pendiente escrito en futuro **no envejece a «hecho»:
> envejece a mentira**, y una lista «por consecuencia» con una consecuencia que ya no
> existe hace perder el tiempo justo donde pedía urgencia.
>
> El repaso lo disparó `myvc-horarios-4a`, del otro repositorio, contando que su
> `docs/siguiente.md` tenía **dos encargos ya hechos** —uno con el commit que lo hacía en
> un `git log` que su coordinadora había leído esa misma tarde—. Nadie había vuelto a
> mirar esta lista.


| | Qué |
|---|---|
| **8bis** | **Nadie ha censado «personal contra personal», y `auth.personal` la contesta que sí.** El [08](08-revision-idor.md) revisó la autorización horizontal **con un alumno como sujeto**, y su herramienta marcaba las rutas que reciben un identificador del cliente **y no tienen `auth.personal`** — así que **todo lo que ese guard protege quedó fuera por construcción**. Frente a un alumno están cerradas; **un `Profesor` es personal del colegio.** Medido por `myvc-front-94` con dos sesiones delante: **`GET profesores` devuelve a un docente exactamente lo mismo que al administrador** —47 empleados, `num_doc` de 35, `username` de 20, `direccion` de 11— **y el menú del docente no le ofrece esa pantalla**, así que la puerta la abre el endpoint. Es la hermana de `GET contratos`: **se curó aquélla sobre aquella ruta y nadie censó la familia.** Y `GET bitacoras/{user_id?}` es el patrón, **y ya no es una lectura de código: está visto en el navegador**. Con el token de un `Profesor`: `GET bitacoras` sin parámetro da **0 filas** —por eso parece acotada— y **`GET bitacoras/1` devuelve las 22 filas del administrador**, con `created_by=1` comprobado en las 22. Detrás de `auth.personal` y sin `persona.propia`. · **Y un tercero que sale del mismo tirón: `GET alumnos/sin-matriculas` no hace lo que dice su nombre.** Su consulta lleva `INNER JOIN matriculas`, así que devuelve a los alumnos **matriculados en el año en curso** —494—, y con ellos `fecha_nac`, `celular`, `direccion` y **`religion`**. La pregunta no es si un docente puede listar alumnos sin matrícula: es **si un docente debe recibir el domicilio, el teléfono y la religión de los 494**. Eso sí es del colegio. Lote `FICHAS-1`, **que mide y propone: no recorta nada** |

### Frentes nuevos que nadie ha abierto porque no los pediste

| | Qué |
|---|---|
| **9** | **El boletín final tarda 24–63 s y se cae bajo carga**, y ya está medido de dónde viene: **2.602 de 3.355 consultas por petición — el 78% — son dos bucles anidados**: **1.480 en `asignaturasPerdidasDeAlumno`** y **1.122 en `definitivasMateriasXPeriodo:415`** *(corregido: esta coordinación las había bautizado con otros dos métodos y la etiqueta se propagó sin comprobar)*. **Arreglarlo es una agregación por grupo, o sea un frente**, y no lo abre nadie sin ti. **Y son DOS caminos vivos, no uno:** el mismo problema está en `app/Http/Controllers/BolfinalesController.php` —alcanzable por `new` desde `certificados-estudio/certificado-grupo`, con las tres invariantes en Eloquent (líneas 67, 86 y 267)—, **y ya está medido: 3.820 consultas y 11,4 s para devolver un 500** ([05 §224](05-codigo-muerto-y-roto.md)). **Cuesta más que el que dio el 504 antes de curarlo** (3.763) **y no devuelve nada**: la vista `certificados.estudio` no existe en el repositorio, así que el 500 es del 100% de las llamadas, y `detailedNotasGrupo` corre entero antes de reventar. ~~**Por eso no se optimiza**~~ · **MEDIDO Y ESCRITO la noche del 25, y cambia el precio de las dos ramas.** El desanidado está hecho, medido y **commiteado sin fundir a propósito** en `perf/gemelo-de-bolfinales`: **3.820 → 455 consultas** y **408 → 1** en la invariante, con el instrumento que ya existía —tres rutas por HTTP en una corrida, pasada en frío descartada— y **con su control positivo: el hermano marca 755 antes y 755 después, sin moverse ni una consulta**, o sea que esto tocó el gemelo y sólo el gemelo. *(Los milisegundos no se citan: los 969 ms se midieron con carga 4,82 y los 11.433 con carga 1,42; **lo que se defiende son las consultas**.)* · **Y hay una SEGUNDA causa del 500 que no estaba medida y que sube el precio de la rama 1:** además de que la vista `certificados.estudio` no existe, **no hay ningún paquete de PDF en el proyecto y nunca lo hubo** —`composer.json`, `composer.lock`, `vendor/` y `config/` comprobados los cuatro vacíos, y `dompdf.wrapper` se nombra en **un solo sitio de todo `app/`**—. Así que la rama 1 no es «escribir la vista y curar el patrón»: es **escribir la vista + curar el patrón + meter una dependencia nueva en un `vendor/` que los quince comparten por symlink**, o sea un cambio a todos a la vez. **La rama 2 no se movió y sigue siendo la barata.** **La decisión es tuya y son dos ramas:** *si esa pantalla debe existir*, hay que escribir la vista **y** curar el patrón —y entonces será **la página más cara del sistema, más que la que dio el 504**—; *si no debe existir*, se retira la ruta y **no hay nada que optimizar**. **Mientras tanto no se borra: con ruta y roto se documenta** — borrarla convertiría el 500 en un 404 sin decirle a nadie qué pretendía esa pantalla. *(Y va corregido lo que esta coordinación escribió antes: sacar la consulta invariante del bucle **quita 407 consultas y no mueve el tiempo**, así que **la fase 2 de definitivas sigue siendo el bloqueante**, no deja de serlo.)* |
| **10** | **Los seis `DB::select` que escriben** ([05 §191](05-codigo-muerto-y-roto.md)). Una palabra por sitio, **ningún cambio de conducta hoy** — y **ningún test rojo delante**, dos ficheros cogidos, y uno corre en cada petición |
| **10bis** | **La pregunta que BI-2 deja lista para que la contestes, con las tres salidas costeadas y ocho consultas colgando de ella:** *cuando una pantalla enseña «las unidades de esta asignatura» y en el grupo hay un alumno con boletín propio, **¿enseña las del grupo, las de él, o las dos?*** · **hoy** salen mezcladas, sin nada que las distinga. · **con alcance** la pantalla enseña **un** boletín — y en la planilla eso significa que **al independiente no se le puede poner nota desde la rejilla del grupo**. · **sin alcance** la rejilla **deja de ser un rectángulo** y los porcentajes pueden sumar 140. **Las tres son coherentes y las tres rompen algo distinto.** · **Y las dos de la papelera llevan arruga aparte**: acotarlas **esconde lo borrado de un boletín**, y una papelera que esconde es peor que una que enseña de más — ahí la respuesta probablemente sea «no se acotan», **pero no la da ninguna sesión** |
| **10ter** | **Cinco de los 25 sitios de BI-2 estaban mal etiquetados por el mismo mecanismo**, y eso mancha los números de los dos lotes que salen de ahí: el ancla real está en un `WHERE` o un `JOIN` **por id**, y **el clasificador ve primero un filtro más grueso**. En `EnviarNotificaciones:195` **acotar sería un riesgo, no una mejora**: la cadena es pura por id y la condición podría quitar la fila, dejando al alumno **sin el aviso de su propia nota y sin error ninguno**. **El «59 a acotar» está inflado y no se sabe en cuánto.** No se recensa —eso es otro barrido— pero **quien coja BI-3 o BI-4 no puede fiarse de una etiqueta producida por el mismo detector que hizo su lista** |
| **8ter** | **MEDIDO: la ficha del profesorado sale por SIETE rutas, y la decisión es UNA.** `GET contratos` se recortó sobre `GET contratos` y **tiene siete hermanas vivas** con la misma proyección de **ocho campos** —`barrio, celular, direccion, email, estado_civil, fecha_nac, num_doc, telefono`—: `GET profesores`, `profesores/todos`, `profesores/show/{id}`, `PUT participantes/profesores`, `PUT unidades/de-profesor`, `GET asignaturas/listasignaturas/{persona_id?}` y `PUT profesores/listado` (la misma menos `telefono`). **Lo que hay que decidir no son siete rutas: es qué campos lleva la ficha de un empleado**, una vez · **Eran OCHO hasta el 25 ago y hoy son SIETE**: `GET contratos` no salía en la medición porque su recorte estaba fundido y sin desplegar, y **ya está desplegado** (`eb95cbc`). La octava se cerró; las siete hermanas siguen vivas · **Y un detalle que decide cómo se arregla la familia: el recorte de `c47ab50` NO está en `ContratosController`** —su `getIndex()` sigue siendo una línea sin tocar— **sino en `Profesor::contratos()`, en el modelo**. Quien recorte las siete hermanas ruta por ruta **se va a encontrar con que al menos una no se arregla en su controlador** |
| **8quater** | **Y un `Usuario` sin un solo rol recibe EXACTAMENTE lo mismo que un `Profesor`: las mismas 52 rutas y las mismas 17 proyecciones, idénticas línea por línea.** Las escrituras difieren en seis (91 contra 85); **los datos personales, en nada.** La [bar-1](noche-2026-08-24/bar-1.md) dijo *«tener el rol de profesor no es lo que abre la API»* sobre las escrituras; **sobre la lectura de fichas la diferencia es cero** · **Y el ruido del detector está medido en el rol donde sabemos la respuesta:** de las 5 del `Alumno`, **3 son su propia sesión y 2 son el teléfono del COLEGIO** —dato institucional, ni propio ni ajeno, un cuarto sesgo que no estaba en ninguna caracterización—. **Cero de terceros: el ruido es del 100% en ese rol.** Así que **52 sigue sin ser un censo**, pero **17 proyecciones sí es la lista de decisiones** |
| **10quater** | ~~**Y el arreglo de una de esas ocho ya existe y a dos sitios se les pasó.**~~ **CERRADA el 26 ago por la tarde** ([05 §237](05-codigo-muerto-y-roto.md)) — **y no eran cinco arreglos, era uno**: ninguno de los cinco llamadores tiene un alumno a mano, y no es descuido suyo. Lo que distingue los dos casos está en `unidades.alumno_id`, dos capas más abajo. **El detector contaba bien el síntoma y no la causa.** Cuatro tests, y el control que importa es el que NO cae: «una unidad del grupo sigue recalculando a todos», que es el caso que corre hoy en los quince. Texto viejo:  `DefinitivasDeAsignatura::recalcular()` **acepta un cuarto argumento `$soloAlumno`** y filtra por él: de sus **tres** puertas, **una lo pasa y dos no** —`recalcularPorUnidad` (la llaman 2 sitios de `UnidadesController`) y `recalcularPorSubunidad` (3 de `SubunidadesController`)—. **No es que no se pudiera acotar: es que a dos se les pasó.** Medido con detector nuevo y **control ejecutable**, sobre 222 ficheros y 58 lecturas acotadas por id: **cinco traspasan a una dimensión más ancha y tres van sin acotar** —los dos anteriores más `SubunidadesController:94`—. Hoy no falla porque ninguna unidad tiene dueño; **el día que lo tenga, recalcula las definitivas de toda la asignatura y crea notas a los treinta**, sin un error en el log |
| **10quinquies** | **«Matrícula viva» está escrito de seis formas distintas en `app/`, y no se diferencian en el orden sino en el CONJUNTO.** Unas llevan `PREM` y otras no; una lleva **`PREA`**. Y el seed de tests tiene **`MATR` y `RETI` y cero de todo lo demás**, así que **ninguna de las seis es distinguible de las otras por ningún test** — la diferencia entre incluir `PREM` y no incluirlo **tampoco se ve**, y ésa sí cambia quién sale en un listado. **La pregunta que no sale del código y es tuya: ¿`PREM` y `PREA` cuentan como matrícula viva?** · *Va como sitio donde mirar y **no** como lista de fallos: son expresiones a mano en SQL crudo, y el caso que lo destapó enseña que **dos variantes distintas pueden estar las dos bien** —el boletín final y su gemelo filtran distinto y ninguno está mal—.* El lote `ASIS-1` pone la red; **no unifica nada** |
| **10sexies** | ~~**Y una que se ENCOGE, que esta noche es noticia:**~~ **CERRADA el 26 ago por la tarde** ([05 §239](05-codigo-muerto-y-roto.md)) — **el censo acertó y el mecanismo que proponía no existía**: `deAsignaturaCalculada` NO es «el mismo método con el alcance puesto», hace `join` a notas y devuelve `nota_unidad`. Cambiar los 17 a ella les habría movido la forma de la respuesta y metido un join por alumno en los boletines de 24–63 s. El alcance entró en `deAsignatura`, con el alumno como **tercer parámetro obligatorio** — y larastan cazó dos llamadas que se me habían pasado. **Tercera vez esta semana que una lista acierta el número y falla el verbo.** Texto viejo:  los **17 llamadores** de `Unidad::deAsignatura` **tienen todos el alumno a mano** —13 por parámetro, 3 dentro de un `foreach` verificado por saldo de llaves, 1 por `Request::input`— y **los 17 calculan algo de un alumno concreto**; **ninguno pinta la estructura del grupo**. Así que **no añaden ninguna pregunta a la tuya**: la respuesta es la misma en los diecisiete y se lee en el código. El mecanismo también existe ya —`Unidad::deAsignaturaCalculada()` es el mismo método con el alcance puesto—, así que **acotarlos es mecánico y no espera a nadie**; queda sin hacer sólo porque son diecisiete redes y un commit por acotada |
| **11** | **Las dos del boletín independiente** ([19](19-boletin-independiente.md) §2): quién marca a un alumno, y qué puesto lleva su boletín |
| **12** | **Unificar los cuatro informes de puestos con los ocho de impresión**: les cambia la conducta a cuatro que hoy no preguntan nada |

### Dos escrituras que el cliente puede invertir con una cadena ([05 §232](05-codigo-muerto-y-roto.md))

Salieron de preguntar **quién más** hace lo del contador de certificados: una comparación
**laxa**, sobre un valor del **cliente**, que decide si se **escribe**. **De 980 `if` del
proyecto, 21 cumplen las tres condiciones y tres tienen consecuencia.** El tercero
—`bolfinales`— ya es el punto 1.

| | Qué | Si no se contesta |
|---|---|---|
| **16** | **`PUT periodos/copiar` crea NOTAS que nadie pidió.** `if ($copiar_notas and …)` → `new Nota; save()`. Un cliente que mande `copiar_notas: "false"` **escribe en la tabla `notas`** — la del [plan de definitivas](10-definitivas.md) — y **después no hay forma de distinguir una nota copiada de una puesta a mano** | El front midió que **hoy ningún cliente manda esas cadenas** (11 llamadas, 0 cadenas), así que **no es un fallo vivo**: es una puerta abierta que **basta un control nuevo para cruzar** |
| **17** | **`PUT votaciones/set-actual` y `set-in-action` no se saltan la escritura: la INVIERTEN.** Las dos ramas escriben, así que `"false"` **activa** la votación, desactiva las demás del usuario, y contesta `'Cambiado true'` — **el cliente recibe confirmación de lo que no pidió**. Y `Request::input('actual', true)` por defecto **activa**, así que **omitir la clave tampoco salva** | **Es una forma peor que la del contador y no estaba nombrada**: allí el valor laxo produce una escritura **de más**; aquí produce la **contraria** |

> **Y la precisión del front, que estrecha el triaje:** *«manda una cadena» no es la
> condición*. En PHP `'0'` es falsy y `'1'` truthy, así que **las dos formas que un
> checkbox de AngularJS produce de verdad se comportan bien, por accidente**. La única
> cadena fatal es una no vacía distinta de `'0'` — `"false"`, `"off"`, `"no"`.

### Una nota que NO es una decisión, y por eso va al final ([05 §233](05-codigo-muerto-y-roto.md))

**Diez sitios de `app/` meten una variable como nombre de columna en un `UPDATE`, y los
diez son seguros hoy** — por **cinco mecanismos distintos**, de los cuales `ColumnaSegura`
—la clase que existe para esto— **no es ninguno**. **Cero fallos vivos, nada que decidir.**

Va escrito porque **el barrido que creó `ColumnaSegura` sólo vio una de las dos sintaxis**:
la concatenación `SET '.$x.'` (4 sitios) y no la interpolación `SET $x=` (6). *Los seis no
se descartaron: no se miraron*, y salieron seguros por listas blancas y literales — **eso se
sabe hoy y no se sabía entonces**. Lo único barato que falta, **el día que se toque ese
fichero por otra cosa**: dos comentarios que digan que la protección vive en el `switch` de
arriba y no en la línea.

### Y tres números viejos en documentos que no toco sin ti — **uno ya no lo era**

> **Repasados los tres el 26 ago por la tarde, y el 13 estaba cerrado desde antes.** No es que
> envejeciera la cifra: es que **el pendiente se arregló y nadie volvió a esta tabla**. Es la
> misma forma que la [§235](05-codigo-muerto-y-roto.md) —la lista de la mañana dando por
> enteros dos puntos cuya mitad había entrado esa noche— y la que `DESPLIEGUE.md` avisa: **un
> pendiente escrito en futuro no envejece a «hecho», envejece a mentira.**
>
> **Y el sitio donde se vio fue el test, no el documento.** Los otros dos se repasaron igual y
> **siguen abiertos de verdad**, que es lo que hace que este repaso valga: si los tres hubieran
> salido cerrados, lo sospechoso sería el método.

| | |
|---|---|
| **13** | ~~**`CLAUDE.md` dice que las excepciones públicas son quince y son once**, y **`RutasPreLoginTest` no es un inventario**~~ **CERRADO POR LAS DOS MITADES, y la lista no lo sabía.** `CLAUDE.md:141` dice **once** y cita `RutasPreLoginTest::TOTAL_PUBLICAS`; y el test **sí es un inventario** — `test_el_inventario_de_publicas_no_tiene_de_mas_ni_de_menos` recorre **todas** las rutas de `api/`, las llama **sin token** y ata el conjunto por las dos direcciones: *de más* («contesta sin token y no está en la lista») y *de menos* («está en la lista y ya no contesta»). **Comprobado corriéndolo, no leyéndolo**: los once verdes, 51 aserciones. Es exactamente la forma que este documento lleva todo el día pidiendo — **un número que un test obliga no envejece** |
| **14** | **Una decisión mía, revertible en un commit**: congelar ocho `SELECT *` para que la migración del boletín independiente **no mueva ninguna respuesta**. La alternativa —regenerar instantáneas— **era tuya**, porque obliga a avisar al front y a Flutter |
| **15** | **La §12 de arriba y la §14** del 09 siguen esperando desde el 24 — **repasadas hoy y las dos siguen abiertas de verdad**: la [§12](09-pendientes.md) porque *«la C se propuso sin ese dato delante y hay que volver a preguntarla»* —las cuatro `cambiar-usuarios/*` **ya son** una decisión tuya del 21 ago, anotada en un test y no en el código—, y la §14 porque **su número lo trae la fase 0** (bloque 3, `Admin` sin `is_superuser`) |

---

## NOTIFICACIONES — el tema del muro era el mismo en los quince (26 ago, tarde)

Detalle en [05 §238](05-codigo-muerto-y-roto.md). **Lo encontró la sesión de `myvc_flutter`**,
no ésta, y ahí está lo que hay que quedarse.

`TemasDeNotificacion::DEL_COLEGIO` eran dos cadenas literales —`colegio_muro` y
`colegio_avisos`— sin nada que dijera de qué colegio. **El proyecto de Firebase es UNO para
los quince**: una sola app, un solo `google-services.json`. Un tema llamado igual en dos
colegios **es el mismo tema**, así que el muro de uno le habría llegado a las familias de los
otros catorce.

### No es fuga de contenido, y por eso es peor de lo que suena

El cuerpo es genérico a propósito —«hay 3 publicaciones nuevas»— así que no se filtra nada de
ningún menor. Lo que pasa es **el aviso equivocado a la familia equivocada**: quince veces más
avisos del muro de los que le tocan, y catorce llevándola a un muro donde no hay nada nuevo.
**Multiplicado por quince, la función es ruido y la gente la apaga.**

### Por qué se escapó, y la explicación es mejor que «se olvidó»

El docblock lo razonaba, y razonaba bien **una de las dos cosas**. **El HMAC del tema del
alumno hace dos trabajos a la vez**: esconder *de quién* es, y separar *un colegio de otro*.
Se descartó el primero —con razón— **y con él se fue el segundo**.

> **Y no había forma de verlo desde este repositorio.** La premisa que convierte esas dos
> cadenas en un fallo —*un solo proyecto de Firebase*— **vive en `myvc_flutter`**. Aquí
> `colegio_muro` es un nombre perfectamente razonable. Lo vieron **leyendo el contrato antes de
> cablearlo**, que es la única postura desde la que se veía.

### Hecho, y con una forma distinta de la que pidieron

`c_` + HMAC del nombre lógico con el secreto del colegio — mismo aspecto que el del alumno.
Ellos proponían HMAC del *identificador del colegio*; es lo mismo con un dato de menos, porque
`secreto()` **ya es el `APP_KEY` de cada colegio**, y ese identificador **no existe en
`config/`**: meterlo obligaría a editar quince `.env`, que es justo lo que
`config/notificaciones.php` dice que no se le puede pedir a un despliegue.

**Cambia la forma de la respuesta** de `GET notificaciones/temas`: `colegio` pasa de **lista** a
**objeto** `nombre lógico → tema`. Aviso **E** en [DESPLIEGUE.md](../DESPLIEGUE.md). No rompe a
nadie hoy: la app no está publicada y ellos escribieron que no suscriben esos dos temas hasta
que llevaran prefijo. **Es el único cliente de ese endpoint.**

Tres tests nuevos y **el control visto rojo**: volviendo al literal caen dos, y **no** cae el
del endpoint — que es correcto, porque mide *«entrega lo que el servicio compone»* y no el
nombre. Las dos preguntas viven en tests distintos a propósito.

### Y lo que queda para ti, que es una sola cosa

**`colegio_avisos` está declarado y no lo publica nadie.** Se queda —componerlo no cuesta nada—
pero `myvc_flutter` pregunta si esa función va a existir. **Si no va a existir, se retira de los
dos lados a la vez**, y eso no lo decide una sesión.

---

## PREMATRÍCULA — la mitad que no esperaba a nadie, cerrada (26 ago, tarde)

Detalle en [05 §236](05-codigo-muerto-y-roto.md). **Cierra la (a) del `1bis`**, que estaba
escrita como *«se cierra con una transacción, y eso no espera a nadie»*.

`PUT login/crear-prematricula` es **la única de las once rutas públicas que escribe**, y la
llama alguien **sin cuenta**. Escribía en cuatro sitios sin transacción, así que con un
`grupo_id` que faltara o que no existiera quedaba escrita la ficha de un menor —nombres,
apellidos, documento y celular— **sin matrícula y sin cuenta**, y **el reintento contestaba
200 mintiendo**: *«Ya existe el alumno. Entre con su cuenta»* por una cuenta que nunca se
creó.

### Son dos arreglos y no se contienen — por eso van los dos

| | Qué cubre | Qué NO cubre |
|---|---|---|
| **La transacción** | **cualquiera** de los cuatro `INSERT` que falle | el **500**, que sigue siendo un 500 |
| **`grupoQueExiste()`, 422 delante de todo** | el 500 —y con él, el cuerpo que trae `Host`, `Port` y `Database` con `APP_DEBUG=true`— | un fallo que no sea el del grupo |

La segunda no es cosmética: esta ruta le daba al pendiente del [01](01-plan-seguridad.md)
—*«con debug on, un error filtra el `.env` entero»*— **un camino público y sin autenticar**.
La transacción sola lo dejaba abierto.

### El control, porque seis de los siete tests pasan sin la transacción

Los que mandan un grupo malo pasan **sólo con el 422**: frenan antes del primer `INSERT`, y
su verde no distingue *«la transacción funciona»* de *«no se llegó a escribir»*. Hace falta
un fallo **después** del `INSERT` de `alumnos`, y hay uno:
`test_un_fallo_a_media_escritura_tampoco_deja_la_ficha` renombra el rol `Alumno` y revienta
en `$role[0]['id']` con la ficha, la matrícula y el usuario ya escritos.

**Visto rojo antes de darlo por bueno**: quitando el `DB::transaction` y dejando el guard,
de los siete cae **exactamente ése**. Los otros seis siguen verdes — que es la demostración
de que el resto del fichero no prueba lo que dice su nombre.

### La (b): ya no hace falta una visita aparte para contarlos — bloque 4 de la fase 0

**Los huérfanos ya escritos en los quince** siguen siendo tuyos: para ellos el 200 mentiroso
es el que sale, y qué se hace con ellos —adoptarlos, crearles la cuenta, borrarlos— lo decide
el colegio. Lo que ya no espera es **contarlos**: entró en
`tools/fase-cero-de-los-dieciseis.php` como **bloque 4**, así que sale con el mismo `for` que
ya estaba pendiente. **Cinco preguntas en una visita, no seis en dos.**

**Y son DOS formas, que es lo que la consulta de la lista no veía.** El `INSERT` que reventaba
podía ser el segundo o el tercero:

| forma | qué reventó | ¿la ve el censo de la lista? |
|---|---|---|
| ficha **sin matrícula** | `matriculas` | **sí** — es la consulta escrita el 25 |
| ficha **con matrícula `PREA` y sin cuenta** | `users` o el rol, ya con la matrícula puesta | **no** |

La segunda es justo la que produce el *«ya existe, entre con su cuenta»* **con la matrícula
delante**. Contar sólo la primera habría dado un número tranquilizador.

### Y el primer dato real, que es el de la copia de desarrollo

**4 fichas huérfanas de 1.245 alumnos — y las cuatro NO son de este endpoint.** Se ve sin
salir del CSV: la cota estrecha (`tipo_doc = 3`, que este endpoint escribe fijo) da **0**, y
la fila que explica el hueco —sin `tipo_doc`, sin `documento` y sin `celular`— da **4**. Son
de 2018 y de 2020, tres de ellas con **34 segundos entre sí**. Y encaja con que
`prematr_nuevos = 0` en los ocho años de esa base: **ahí el formulario no se ha enseñado
nunca**.

> **Eso es un dato de UNA copia y no vale por los quince**, que es exactamente el motivo por
> el que la pregunta va al `for`. Lo que sí deja demostrado es que **las dos cotas hacen
> falta**: con sólo la ancha, ese colegio se habría reportado con «4 fichas de menores» y
> ninguna lo era.

> **Y lo único que estrecha, fijado a propósito:** un grupo **en la papelera** ahora da 422.
> Antes pasaba —la clave foránea sólo mira que el id exista— y dejaba la prematrícula
> colgada de un grupo borrado, donde no la ve nadie.

### El aviso al front es el primero que NO pide trabajo, y eso se comprobó

Fila **D** de [DESPLIEGUE.md](../DESPLIEGUE.md). `mensajeError.ts` lleva **422** en su lista
`CON_MENSAJE`, así que `LoginCtrl:217` **ya** pinta el texto del servidor. El 500 **no** está
en esa lista: hasta hoy salía el genérico. **El cambio le mejora la pantalla al front sin que
toque una línea.**

> **Lo escribí primero como *«hay que enseñar el mensaje»* y era falso.** Lo desmintió mirar
> el fichero del front en vez de deducirlo del síntoma. Se apunta porque el error iba en la
> dirección cara: **un aviso que pide trabajo que no hace falta gasta a otro equipo.**

**Y de camino salieron dos cosas del front, y las dos están ARREGLADAS** —`myvc_front`,
`8321f9a5`, **commiteado en su `main`, sin subir y sin publicar**—:

| | qué era | cómo se veía |
|---|---|---|
| **a** | el desplegable de grupo lleva `allow-clear="true"` y el controlador hacía `year.grupo_prematr.id` **sin comprobar nada** | `TypeError` dentro del `ng-click`: **botón mudo**. Ni petición, ni aviso, ni error en consola |
| **b** | `$ctrl.guardando` se ponía a `true` al enviar y **no lo leía nadie** | era el `ng-disabled` que falta, a medio poner: nada frenaba el segundo clic |

La (a) **es el mismo fallo que ese fichero ya había arreglado doce líneas más arriba, en el
campo de al lado** — y el tipo era parte del fallo: `grupo_prematr: { id: number }`
obligatorio hacía que compilara. Ahora es opcional, que es lo que el backend manda de verdad.

Siete pruebas nuevas y **los dos controles vistos rojos**: quitando la comprobación caen 2 de
7 y quitando la reposición caen otras 2, cada una las suyas. En el front: **488 pruebas, 49
ficheros, typecheck, lint y las 22 puertas de `npm run check` en verde.**

> **Su despliegue es otro bucle** —`up/`, un `git pull` de `myvc_dist`—, así que el 422 del
> backend y estos dos **no tienen que salir juntos**: son independientes en las dos
> direcciones.

---

## CERT-2 — el consecutivo ya no lo mueve cualquiera, y por primera vez deja rastro (26 ago)

Detalle en [`noche-2026-08-26/cert-2.md`](noche-2026-08-26/cert-2.md). **Cierra el punto 1
de la lista de la mañana del 25**, que era lo primero por consecuencia.

**Lo que contestaste esta mañana y lo que se hizo con cada respuesta:**

| contestaste | qué entró |
|---|---|
| **validar el entero + `esAdministrativo`** | la validación ya estaba (25 ago). El permiso es **una línea** en `consecutivoValidado()`, y va ahí y no en la ruta **porque cubre los dos endpoints**: los dos pasan por ese método |
| **backend estricto + avisar al front** | el backend estricto **ya estaba hecho** (`FILTER_VALIDATE_BOOLEAN`, 25 ago). Queda viva **la otra mitad**: avisar, y está escrita en [DESPLIEGUE.md](../DESPLIEGUE.md) para el día del despliegue |
| **bitácora en los contadores, ya** | **los dos** sitios que mueven el contador anotan en `auditoria` — la quema al abrir el certificado y el cambio a mano —, con resúmenes distintos porque **no son el mismo suceso** |

### Lo que hay que quedarse, y no es el arreglo

**La lista de la mañana del 25 estaba vieja en sus dos primeras filas.** Daba los puntos 1
y 2 por enteros pendientes cuando la carrera, el `FOR UPDATE` y la validación **habían
entrado esa misma noche** y sus tests llevaban desde entonces verdes dentro de la suite.

No es una cifra que envejeciera: **es una lista que nadie releyó después de que su propio
punto se hiciera.** Y el sitio donde eso se ve en dos segundos **es el test**, no el
documento — `ConsecutivoDeCertificadosTest` lo decía en la primera línea de su docblock.
*Antes de coger un punto de esta lista, abre su test.*

### Los controles, porque un verde no dice nada hasta que se le ha visto ponerse rojo

Se revirtió cada mitad por separado sobre el árbol de verdad: sin el guard cae el test del
docente **y el del Secretario sigue verde** —que es lo correcto: `abort(403)` a secas
también habría pasado el primero, y habría cerrado la pantalla a secretaría—; sin cada
anotación cae su rastro. **11 tests** en la clase.

### Y la tabla de emitidos no se puede diseñar todavía — **lo primero que se midió lo frenó**

Al reabrirla salió la pregunta que había que contestar antes: **qué es «un certificado
emitido»**. Medido en las dos partes: el backend quema **un número por petición**, la
respuesta trae **un `year` y N alumnos**, y las dos plantillas le pasan **el mismo `year`**
a cada alumno. O sea:

> **Abrir el certificado de periodo de un grupo de 37 quema UN número e imprime 37 papeles,
> los 37 con el mismo número encima.**

**No se puede diseñar la tabla sin saber si su clave es un papel o una apertura**, y eso no
lo decide el código: *¿el consecutivo numera el papel o numera la tanda?* Lo que manda es
qué escribe secretaría en el libro. **Es una pregunta que se contesta antes de una
migración en quince producciones, no dentro de ella.** Detalle en
[cert-2 §7](noche-2026-08-26/cert-2.md).

### Y las tareas del front quedaron escritas donde las van a leer

`~/DESARROLLOS/myvc_front/TAREAS-AUDITORIA-CERTIFICADOS.md`, a petición tuya. Dentro va lo
de los certificados, lo de la pantalla de la fase 5 y **una que nadie les había dicho: las
cinco lecturas de auditoría ya exigen `can_view_auditoria` y están DESPLEGADAS desde el
25** — no es un aviso de futuro, es algo que ya les está contestando 403 hoy. Con los
ganchos exactos de cada app dentro. **Y hay una sesión viva ahí, que se lo llevó dentro
de su commit.** No lo commiteé yo: a las 15:45 `c1029fcb` —§237 de `PREGUNTAS-MANANA.md`, un
lote suyo que no tiene nada que ver— **barrió el fichero con un `add -A`** y se llevó dentro
la versión de diez minutos antes. La mejora que le hice después —los ganchos exactos de cada
app— **quedó suelta en su árbol**, y está respaldada fuera, en el scratchpad de esta sesión.

> **Es el fallo del 24 ago en el otro sentido**, y por eso se apunta: allí una sesión
> commiteó documentos de otras dos creyéndolos huérfanos; aquí un `add -A` de una sesión se
> lleva un fichero que otra estaba escribiendo. **Con dos sesiones en un árbol, `git add -A`
> no es «lo mío».** Y lo que lo destapó fue mirar `git status` **dos veces, con diez minutos
> de diferencia** — la primera decía doce ficheros de `app2` modificados y la segunda, uno
> solo y con mi nombre.
>
> **Se resolvió solo y conviene decirlo**: media hora después su historia se había rehecho
> —tres commits nuevos— y **el fichero ya no está en ninguno**, o sea que aquella sesión lo
> sacó de su lote. Vuelve a estar sin trackear en su árbol, con el contenido al día. **No
> hizo falta tocarles nada.** Se deja escrito por el método, no por el daño: el respaldo
> fuera del árbol es lo que hacía que esto fuera un apunte y no una pérdida.

### Y el folio: preguntaste qué era y resultó no ser un contador — [21](21-certificados-y-folios.md)

Documento nuevo, con la norma por un lado y **lo medido en la copia local** por otro, que es
lo que decide. Los tres números que importan:

- **`contador_certificados` funciona**: es un consecutivo por año y en 2025 va por **143**.
- **`contador_folios` no es un contador: es un interruptor.** Nadie lo incrementa, el
  endpoint que lo fija **no lo llama ninguna pantalla viva**, `YearsController` lo copia de
  un año al siguiente —por eso lleva **congelado en 249 desde 2021**— y el front sólo mira
  **si está vacío o no**, para decidir si imprime el bloque «Folio:». El valor daría igual
  que fuera `1`.
- **`nro_folio` son cuatro poblaciones y sólo una es un folio**: 1.440 vacías, **1.612
  automáticas** (`año-alumno_id`, que no es la hoja de ningún libro), **257 que nombran a
  OTRO alumno** y **233 folios de verdad**, una práctica que **se murió sola en 2023**.

**Y la decisión ya la tomaste**: *«hay colegios a los que no les importa llevar esos
contadores o folios; que tengan la opción. Los que sí, que funcionen con la opción A»*.
Hecho el 26 ago, y salió más barato de lo que parecía porque **los dos interruptores ya
existían escondidos**: el front oculta cada casilla cuando su columna está vacía, así que
esto **no estrena una conducta, le pone nombre a la que había**.

- **Migración `2026_08_26_100000_interruptores_de_certificados`**: `usa_consecutivo_certificados`
  y `usa_folio_certificados` en `years` —que es donde vive la configuración del colegio—, y
  `YearsController` los copia al año siguiente. **Sin valor por defecto**: se derivan de
  `contador <> ''`, colegio a colegio, así que **ninguno imprime nada distinto el día del
  despliegue**.
- **Lo que sí cambia, y es el arreglo:** un colegio que no imprimía el número **seguía
  gastándolo** en cada apertura. Ya no. Y los dos endpoints contestan **409** ahí.
- **El folio deja de fabricarse**: fuera los **siete** sitios que escribían `año-alumno_id`,
  y `GET folios/iniciar` —el que llenaba todos los huecos del año de una sentencia, y que
  **no llama ningún cliente de los siete árboles**— contesta 409.
- Lo ata `FolioQueNoSeFabricaTest`, cuyo tercer test barre los 224 ficheros de `app/` **con
  control positivo dentro**. Y ahí el detector se equivocó primero: cazaba `m.nro_folio,` en
  la lista de columnas de dos `SELECT` que **leen** el folio. Lo que separa leer de fabricar
  es **construir el valor**.

**Lo que NO entró:** los **1.869 folios ya escritos** —1.612 fabricados + 257 que nombran a
otro alumno— se quedan; borrarlos cambia lo que hoy sale impreso y es un `UPDATE` y una
decisión tuya.

**Y la pregunta que la bloqueaba la contestaste el 26 ago: un número POR PAPEL.** El plan
está en el [21 §7](21-certificados-y-folios.md) —**sin código y sin migración**, esperando tu
visto bueno—, y lo que trae de nuevo es que **no es sólo una tabla**: numerar por papel obliga
a dar N números por emisión donde hoy se da uno, y eso **mueve la forma de la respuesta y toca
a los cuatro clientes**.

> **Y sale una que hay que arreglar ANTES o esto multiplica un fallo por 37.** Hoy el
> disparador de la quema es **abrir** la pantalla: recargar gasta un número. Con un número
> por papel, **recargar gasta N** — treinta y siete de golpe cada vez que alguien pulsa F5.
> Así que el orden que propongo no es tabla → números → botón, sino al revés: **primero que
> el número se queme al EMITIR y no al MIRAR**, que es barato y ya arregla algo que hoy está
> mal. Si esto se queda a medias, que se quede después de ese paso.

### Lo que sigue siendo tuyo y lo apartaste a propósito

- **La tabla de certificados emitidos.** El rastro nuevo dice **quién movió el número**, no
  **a quién se le entregó el papel**: *«¿cuántos emitimos este año y a quién?»* **sigue sin
  respuesta**. Es una migración en quince producciones y trae dentro una pregunta sin
  contestar — qué se hace con el histórico, que no existe y **no se puede reconstruir**.
  Candidato natural de **AUD-4**.
- **El relleno de ceros** (`'007'` → `8` al quemar). Formato del papel, no fallo. Una línea
  (`str_pad`) y una decisión de colegio. El rastro ahora **lo deja ver** en vez de taparlo.

---

## CONTROLES-1 y GEMELO-1 fundidas — y un rojo que sólo existía en `main`

**GEMELO-1** (`merge(79)`): el gemelo vivo de `BolfinalesController`, de **3.820
consultas y 11,4 s para dar un 500** a **455**, con su control positivo. **CONTROLES-1**
(`merge(12)`): las autopruebas que `tools/` llevaba escritas en las cabeceras **y que no
corría nadie** pasan a ser un test.

### El rojo, que es lo que hay que quedarse

Al fundir, `AutopruebasDeLasHerramientasTest` cayó. Traía a `consultas-en-bucle.py`
marcada **NO CONCLUYENTE** con este motivo: *«dentro del contenedor `git show` no
funciona»*. En `main` sale **exit 0** y el caso cae con su propio mensaje — *«está
apuntada como no concluyente y hoy concluye: quítala de la lista»*.

**El motivo estaba mal, y no por poco.** Medido en los dos sitios y **los dos dentro**
del contenedor:

    /app                  ->  «antes de 2837171: 10 … despues: 4 … OK»,  exit 0
    /app/.worktrees/12    ->  «CONTROL NO CONCLUYENTE: no se pudo leer 2837171^»

El `.git` de un árbol de trabajo es un **fichero** que apunta a una ruta del host; el del
árbol principal es un directorio. **La diferencia no es dónde corre: es desde qué árbol.**
Y eso cambia la conclusión entera: no era *«un control que la suite no puede ejercer»*
—que lo habría dejado sin comprobar para siempre, con su excepción escrita y con razón
aparente— sino *«uno que **sólo la noche en paralelo** no puede ejercer»*.

Tres cosas que deja:

1. **El runner funcionó el primer día, y contra su propio autor.** Es para lo que se
   puso: la lista de excepciones **se fija**, así que una que sobra avisa.
2. **Es la regla de `CLAUDE.md` en su segunda forma** —la que no se arregla repitiendo la
   medición—: el detector contaba bien el síntoma y **la causa que llevaba al lado era
   otra**. Repetirlo desde el worktree da 2 otra vez, para siempre.
3. **Vaciar la lista puso a larastan en rojo, y también tenía razón**: con una constante
   vacía deduce `array{}` y da por muerta la rama del `skip`. Cierto *mientras la lista
   esté vacía* — y por eso convierte «hoy no hay ninguna» en «no puede haber ninguna».
   Pasó a método con el tipo declarado: el mecanismo sigue en pie y volver a apuntar una
   es añadir una línea.

**Lista de no concluyentes: 0, era 1.** Las cinco autopruebas concluyen.

### Y un tercer árbol, tres pushes después: el CI llevaba tres correos en rojo

El mismo control volvió a salir **2** en GitHub Actions, y por un tercer motivo que no
es ninguno de los dos anteriores: **`actions/checkout` clona superficial de serie**
(`fetch-depth: 1`), así que `2837171^` no existe en el runner. Los tres pushes desde que
`CONTROLES-1` entró en `main` (`be05a28`, `2de6c1d`, `e66e99e`) mandaron correo de fallo
con **1.515 casos en verde y uno rojo**.

Reproducido en vez de deducido, que es lo que separa esto de la primera vez:

    git clone --depth 1  ->  «CONTROL NO CONCLUYENTE: no se pudo leer 2837171^»
    árbol completo       ->  «antes de 2837171: 10 … despues: 4 … OK»,  exit 0

**Arreglado en `.github/workflows/ci.yml` con `fetch-depth: 0`, no en la herramienta ni
en la lista de excepciones.** Apuntarla como no concluyente habría apagado el correo
dejando el detector sin comprobar — exactamente lo que el runner se puso a impedir. Son
999 commits y 25 MB: el clon entero cuesta segundos.

**Tres árboles, tres causas, una sola respuesta: *mira desde qué árbol corre*.** Worktree
(`.git` que apunta al host), fusión (el commit sí estaba) y CI (clon superficial). Las
tres veces la herramienta estuvo bien. Queda escrito en la cabecera del test para que la
cuarta no se investigue desde cero.

> **Lo que no se tocó:** el aviso de que `actions/checkout@v4` y `actions/cache@v4` van
> a Node 20 deprecado. No es lo que rompía nada, y las últimas son **v7** y **v6** —tres
> majors de salto—, así que subirlas es su propio commit o no se sabrá cuál fue.

---

## Notas de alumno: la casilla que no existía — **hecha, sin fundir, y toca los quince**

**Rama `fix/notas-alumno-crea-las-notas-que-faltan`, sin fundir** (25 ago, tarde).
Vino de un parte real —*«en notas de alumno no se pueden editar notas»*— que
arrancó en el front y acabó siendo del backend. El detalle entero está en
[05 §234](05-codigo-muerto-y-roto.md); aquí lo que decide.

> **Y una advertencia sobre el commit, que es de las que este documento existe para
> dar.** El código entró en **`60e4fa9`**, y **su mensaje no describe lo que ese
> commit contiene**: dice *«1.504 pruebas»* y *«las cuatro nuevas de `NotasTest`»*
> cuando el árbol que commiteó tiene **1.507 y siete**. No es un descuido de
> redacción — es el árbol compartido: **la revisión estaba sin commitear en el
> mismo directorio** cuando esa sesión hizo `checkout -b` y `commit`, y se llevó
> dentro los tres arreglos de abajo sin verlos. No se reescribe el commit —es de
> otra sesión y estaba viva—, **se corrige por escrito**, que es lo que dice la
> regla de las cifras. *Quien lea `60e4fa9` a secas se lleva tres cambios que su
> mensaje no menciona.*

**Qué era.** `notas/alumno` **sólo leía**: si la fila de `notas` no existía, la
subunidad viajaba sin la clave `nota`, el front pintaba la casilla vacía y al
teclear mandaba `PUT notas/update/undefined` → 422 «No se pudo guardar la nota».
La planilla del profesor no lo sufría porque `notas/detailed` **siembra antes de
devolver**. **240 casillas** así en la copia de desarrollo, 228 en el tercer
periodo, 40 alumnos — sobre todo **el que entra a mitad de año**.

**Qué se hizo.** `Nota::alumnoPeriodoDetalle` recibe **el usuario** y le pregunta a
`Nota::quienCreaLasNotas` **periodo a periodo**: superusuario siempre, profesor sólo
con el periodo abierto, alumno y acudiente **nunca**. No es una decisión nueva —es
`User::permiteEditarNotas` y la [§47.2](05-codigo-muerto-y-roto.md) aplicadas a otra
ruta que lee y de paso escribe.

### Lo que añadió la revisión, y es lo que importa para el relevo

- **El segundo camino.** El arreglo entró resolviendo el `user_id` en `getAlumno`,
  o sea **sólo para el llamante que se acordó**. Por `alumnoPeriodoDetalle` entran
  **dos** rutas, y la otra —`PUT notas/alumno-periodo-grupo`, la pantalla
  **«Promocionar notas»**— guarda con el mismo `NotasApi.actualizar(nota.id)`
  (`PromocionarNotasCtrl:463`, `app2/paginas/promocionar-notas:429`). **Seguía
  rota**, y ahí duele más: lo que se pide es el **periodo de destino**, que es
  justo el que no tiene filas. Es la §47.2 mordiéndonos en nuestro propio arreglo.
- **`DB::insert()` no cuenta filas.** `verificarCrearNota` devolvía «la creé»
  **siempre**: `DB::insert` devuelve el bool de «la sentencia se ejecutó». Medido:
  `DB::insert(...)` → `true`, `DB::affectingStatement(...)` → `0`, misma consulta,
  cero filas. Cambiado, y con test — el único de los tres que no se puede
  comprobar por HTTP.
- **Comprobado al revés uno a uno.** Quitando el séptimo argumento cae
  *promocionar*; con `DB::insert` cae *dice que creó*; tapándole el periodo cerrado
  al superusuario cae *el superusuario siembra*. **Cada uno cae solo.**

**El número: 1.507 pasados, 10.751 aserciones, 0 fallos** (1.504 antes de la
revisión, 1.500 en `eb95cbc`). `larastan [OK]`, `pint PASS`.

### Lo que espera y no lo decide una sesión

1. **No está desplegado, y `app/` es copia por colegio.** Hasta que llegue a los
   quince, **lo único que protege al profesor es la guarda del front** —el
   `if (!nota.id)` de `NotasAlumnoCtrl`, también sin commitear—. Y esa guarda le
   dice *«se crea desde la planilla de la asignatura»*, que **en el caso que
   sobrevive al arreglo —periodo cerrado— es falso**: ahí la planilla tampoco
   puede crearla (400). Es texto del front, pero **el que lo sabe es el backend**.
2. **Dos escrituras nuevas en rutas de lectura**, y las dos en pantallas que se
   abren a diario. Está acotado por permiso y por periodo, pero **es un cambio de
   forma**: quien mire consultas lentas después del despliegue lo verá.
3. **La gemela borrada, para quien ponga la clave única de la fase 2 del
   [10](10-definitivas.md).** El `NOT EXISTS` filtra `deleted_at IS NULL`; el
   índice **mira la tabla entera**. Población hoy: **cero** pares con fila borrada
   y sin fila viva, de 1.165.685 notas. Lo que cambia es la frecuencia: de
   ejecutarse **al dar de alta una subunidad** a hacerlo **en cada carga de dos
   pantallas**.
4. **Para el censo de la fase 1 del [19](19-boletin-independiente.md):**
   `Unidad::deAsignatura` **no filtra `unidades.alumno_id`** —al revés que
   `deAsignaturaCalculada`, que ya lleva el `<=>`—. Hoy inerte porque la columna es
   `NULL` en los quince; el día que alguien marque al primer alumno, **este camino
   sembraría notas del alumno pedido en unidades de otro**. Es un **escritor nuevo
   puesto sobre una lectura que aún no ha pasado por la fase 1**.

---

## AUD-5 hecha — el rastro de la auditoría deja de leerlo cualquiera del personal

**Fundida en `main` — `merge(48)` en `847137a`.** Rama `feat/auditoria-permiso`,
árbol `.worktrees/48`.

> **El número, medido EN EL ÁRBOL FUNDIDO:** **1.500 pasados, 10.702 aserciones, 0
> fallos, 497 s** (suite entera, sin `--testsuite`). **Larastan `[OK]`, 505
> ficheros. Pint PASS, 304 ficheros.** Base reconstruida antes, con la migración
> corriendo dentro.
>
> **Y aquí el número de la rama y el de la fusión salen IGUALES —1.500 / 10.702—,
> al revés que en AUD-2**, donde eran 1.479 y 1.483. No es que esta vez no hiciera
> falta correrlo: es que **`main` no se movió** entre ramificar y fundir, así que no
> había nada que pudiera romperse. *Lo que decide si el número de la fusión importa
> no es el lote: es cuántos commits ajenos entraron por debajo*, y eso sólo se sabe
> mirándolo. Es la **decisión 3** de
[18-auditoria.md](18-auditoria.md), abierta con el visto bueno expreso de Joseth —la
ficha del lote lo exigía porque **cambia quién ve qué**—. El detalle entero, con lo
que quedó fuera y por qué, en [`noche-2026-08-25/aud-5.md`](noche-2026-08-25/aud-5.md).

**Lo que había:** las seis rutas viejas de la auditoría iban con `auth.personal` **y
nada más**. Cualquiera del personal leía la bitácora de un compañero —o la de su
rector— poniendo su número en la URL, y `historiales/de-usuario` cogía el `user_id`
**del cuerpo** y devolvía sus sesiones **y sus intentos de login fallidos** sin
mirar de quién eran: `$user` se resolvía y no se usaba.

**Lo que hay:** lo propio siempre y sin permiso; lo de otro sólo con
`can_view_auditoria`, sembrado por migración a `Rector` y `Coord académico`.

> **Esto QUITA algo, y cae en la pantalla principal del docente — no en un rincón.**
> Lo corrigió `myvc-front-23` grepeando los dos frontales, y va aquí porque **yo lo
> había escrito mal en la dirección que subestima**: dije `/panel/bitacora`, y esa
> pantalla **no llama** a las dos rutas de 403-siempre (usa `GET bitacoras`, la que
> conserva la mitad «lo tuyo»). Quien las llama es **la planilla de notas**
> (`nota-detalle`) y **promocionar notas** (`nota-final-detalle`), detrás de «Ver
> historial» + doble clic en la celda. **Y el disparador no es un permiso, es una
> bandera de `localStorage`** —`historial_activado`— que enciende cualquiera: para
> un docente sin `can_view_auditoria` el 403 es **garantizado y repetible, en su
> herramienta de todos los días**.
>
> No cambia la decisión 4 —esas dos preguntan por una **nota** y contestan quién la
> cambió, con nombre, así que no hay mitad «lo tuyo»—, **cambia el volumen y dónde
> mirar cuando llegue el reporte**. Si un colegio quiere que sigan entrando, **se les
> siembra el permiso**; la respuesta no es revertir esto.
>
> **El hueco dura minutos, no semanas:** el reparto a `Rector` y `Coord académico`
> corre **dentro de la migración**, así que en cada colegio el permiso existe y está
> dado en el mismo `migrate` que trae el guard. No hay ventana con el guard puesto y
> el permiso ausente.
>
> > **Y la lección, que es de esta casa:** el radio de impacto de un cambio de
> > autorización **no se mide en el repositorio que lo hace**. Até el 403 a la
> > pantalla que tenía a mano —la de auditoría, que es de la que iba el lote— y la que
> > lo recibe es **de otro dominio entero**. Misma forma que el detector que no ve
> > Eloquent: *el universo de lo que miras no es el universo de lo que pasa*.

**Ninguna ruta nueva** (siguen **542**), ningún cuerpo cambia de forma, ningún campo
se retira. Lo único que cambia es **quién recibe 403 donde antes recibía 200**.

### La decisión que tomé y que se tumba con una fila

**`Coord disciplinario` NO recibe el permiso.** La decisión 3 dice «rector y
coordinación» y en `roles` hay **dos** coordinaciones. Quien lleva la disciplina no
es obviamente quien puede ver quién cambió una nota, y eso lo decide el colegio.
Queda en el lado seguro; **añadirlo es una fila en `permission_role`**, sin
migración y sin desplegar.

### Y lo que enseñó, que no es una anécdota

**Cuatro tests que ya existían se pusieron rojos, y dos estaban ahí justamente para
eso.** `BitacorasTest` decía *«se mide y se fija; quién puede leer el rastro de
quién es decisión del colegio»* y `QuienDecideDeQuienEsUnAlumnoTest` decía *«sigue
abierto en las dos»*. **No comprobaban que algo estuviera bien: fijaban un agujero
medido mientras esperaba una decisión.** Cuando la decisión llegó, **se pusieron
rojos solos y señalaron los dos sitios exactos** — sin que nadie tuviera que
acordarse de ellos. Se invierten y conservan dentro la frase que decían antes: *un
caso que desaparece se lleva el motivo por el que existió*.

Y de los otros dos sale una regla que no es la misma para los dos: **cuando una
guarda nueva pone rojo un test que no va de guardas, la pregunta es si ese test
NECESITABA el privilegio o sólo lo usaba de paso.** Uno leía el listado de otro sin
que ese fuera su asunto —se le quita la dependencia— y el otro necesita llegar a
`nota-detalle` de verdad —se le siembra el permiso—. *Concederlo siempre es lo
cómodo, y es lo que convierte una suite en una que ya no puede encontrar el
agujero.*

### Lo que NO entra, y no es olvido

- **`DELETE bitacoras/destroy/{id}` se queda como está**, o sea que **hoy cualquiera
  del personal sigue pudiendo borrar el registro que lo vigila, incluido el suyo**.
  No se cuelga del permiso porque **ya está decidido y es otra cosa**: la decisión 4
  dice que **nadie borra** y que la ruta **se retira en la fase 7**. *Borrar la
  auditoría no es verla.* **El agujero sigue abierto entre hoy y la fase 7**, y por
  eso queda escrito aquí y no sólo en el documento del lote.
- **Ninguna ruta que lea la tabla `auditoria`**: no hay ninguna todavía —medido,
  cero `FROM`/`JOIN` en `app/Http/Controllers/`—. Son la fase 5.
- **La pregunta grande sigue siendo grande.** Con `GET profesores` y sus hermanas
  sirviendo la ficha del profesorado a cualquier docente, y con el rol sin cambiar
  nada en lectura de fichas ([FICHAS-1](noche-2026-08-25/fichas-1.md)), **cerrar la
  auditoría no cierra el resto**: las casillas `8bis`–`8quater` siguen esperando.

## AUD-2 fundida — 25 ago, y trae una migración que deja viejas las bases de test

**`merge(9a)` en `e5b5c59`.** La fase 2 de la [auditoría](18-auditoria.md): el
ingreso sale del token en vez de un `order by id desc limit 1` sobre `historiales`.
La escribió `8myvc-9a`; la cerró y la fundió `8myvc-48`, que recogió el relevo con
los dos deberes de cierre abiertos.

> **Lo primero, porque muerde a la siguiente sesión que corra tests:** trae
> `ALTER TABLE personal_access_tokens ADD historial_id`. **No añade ninguna tabla
> —siguen siendo 94—, así que contar tablas NO demuestra que esté aplicada.** Quien
> no reconstruya verá `Unknown column 'historial_id'` con muy buena cara:
>
> ```bash
> DB_TEST_DATABASE=simonbolivar_testing_<sufijo> \
> PHP_EXEC="docker exec -w /app/.worktrees/<sufijo> -i 8myvc-app-1" \
>     tools/construir-bd-test.sh
> ```

**El número, medido EN EL ÁRBOL FUNDIDO y no heredado de la rama:** **1.483
pasados, 10.646 aserciones, 0 fallos, 499 s**, suite entera sin `--testsuite`
(Unit + Feature + Contrato). **Larastan `[OK]`, 505 ficheros. Pint PASS, 302
ficheros.** Base reconstruida antes, con la columna comprobada en
`information_schema` — no con el recuento de tablas.

**Los dos números de esta fusión, y por qué son dos.** La rama daba **1.479 /
10.556** y el árbol fundido da **1.483 / 10.646**: los cuatro tests y las noventa
aserciones de diferencia son de los 18 commits que `main` tenía delante. *El verde
de una rama no es el verde de la fusión*, y esta vez la fusión salió bien — pero se
corrió para saberlo, no para confirmarlo.

**Qué cambia para un cliente**, y esto va al buzón de los fronts: ningún cuerpo se
rompe, pero **el contexto gana dos claves aditivas —`sesion_id` e `historial_id`—
y el contexto se serializa entero**, así que salen en `auth/me`, en `auth/login` y
en `POST /login`. Seis instantáneas regeneradas y **el diff son doce líneas, las
doce `+`**. Además `bitacoras.historial_id` pasa a ser cierto en vez de adivinado y
**puede venir NULL** durante la ventana de despliegue —hasta 14 días, se cierra
sola—, `logout_at` se marca en la sesión que se cierra y no en la última de la
persona, y una petición que antes daba **422 por no encontrar un ingreso** ahora
guarda.

### Y dos cosas que el cierre corrigió, las dos de método

- **`--testsuite=Contrato` no era el criterio, y aquí se ve por qué.** El test
  propio del lote sí vivía en `Contrato`; lo que `Contrato` sola se saltaba era
  `app/User.php` —66 líneas cambiadas— y su guardián `tests/Unit/UsuarioPerezosoTest.php`.
  **Fuera de `Contrato` no hay ni un test que nombre `historial_id` ni `sesion_id`:**
  la cobertura que aporta la suite entera es **por fichero, no por nombre**, y
  buscarla por el nombre de lo tocado habría contestado «ninguno», en falso.
- **«Pint verde» no es «el lote formateado».** El scope del `composer.json` deja
  fuera los seis controladores que toca, `app/Models/TokenDeSesion.php` y
  `app/User.php`. No es un hueco —CLAUDE.md lo decide así—, pero la frase corta se
  lee como una cobertura que no tiene.

> **Y el detector de la auditoría está dicho corto en su propia cabecera**, en la
> dirección mala: `escrituras-sin-auditoria.php` **no cuenta de menos** a
> `Ausencias`, `Frases`, `FrasesAsignatura` y `DefinicionesComportamiento` — **no
> puede verlos**. Tienen **9 `Auditoria::registrar()` entre los cuatro y CERO
> `DB::insert/update/delete/statement`**, y él sólo cuenta formas `DB::`. «32 donde
> había 52» era el síntoma; la causa es que cuatro dominios caen **fuera de su
> universo entero**.

## Todo está en `main`, y nada está publicado — 25 ago, de madrugada

**Joseth pidió unirlo todo y limpiar el espacio de trabajo, y está hecho.** Las
cuatro ramas de la noche del 24 están dentro de `main`; los tres worktrees
huérfanos, fuera; las tres ramas fundidas, borradas; y **ocho workers de larastan
que llevaban vivos desde las 05:11 de un worktree que ya no existía**, muertos.

| Rama | Qué llevaba | Estado |
|---|---|---|
| `perf/hermanas-de-asignaturas-perdidas` | las tres herramientas nuevas, seis tests, 05 §219–§233 | **fundida** (`b995d03`) |
| `medicion/lote-y-cobertura` | cobertura 542/542, el cronómetro del lote, HIST-1 | **fundida** (`911b214`) |
| `feat/boletin-independiente-esqueleto` | el esqueleto, el inventario de las 144 lecturas, los 34 métodos sin camino | **fundida** (`3bfe0ce`) |
| `feat/auditoria-tabla-y-escritor` | la tabla `auditoria` y su escritor único, la fase 0 de los quince | **fundida** (`5912997`) |

**Un solo conflicto en las cuatro**, y de los buenos: `12` y `ad` sacaron **la misma
consulta invariante del bucle del boletín final por caminos distintos, y las dos con
test**. No se eligió una: **se conservan las dos** —se clonan los periodos que el
llamador ya trae resueltos, y se cae al memo de `periodosDelAnio()` cuando no los
pasa—. Verificado después por `8myvc-12` con sus propios instrumentos, no leyendo:
**755 consultas y 1 invariante, idéntico a antes de fundir**, y el test del `clone`
—el que caza la versión ingenua— en verde.

> **Y el motivo que esta coordinación escribió al resolverlo era falso.** Dije que el
> fallback cubría llamadas de tres argumentos *«como lo llaman sus gemelos»*, y los
> gemelos **tienen cada uno su propia copia** del método dentro de su clase: a éste
> sólo lo llama una línea y pasa los cinco. Lo cazó `8myvc-12` leyendo el comentario
> que le dejé sobre su propio código. Corregido en `6f9e734`, **en el sitio y no en
> un documento**: la razón escrita al lado es lo que alguien va a creer dentro de
> seis meses.

### Los dos fallos que sólo existen cuando las cuatro ramas están en el mismo árbol

La suite entera sobre el `main` fundido —**1 fallo de 1.433 tests, 10.087
aserciones, 830 s**, con la base contada antes: **94 tablas, 2.351 usuarios**— y
larastan con **1 error**. Los dos son de la fusión y **ninguna rama podía verlos
sola**:

1. **El censo de interruptores se movió**, y el centinela saltó con razón:
   `matriculas.profes_editar_notas` pasó de «ni se nombran» a «no deciden nada»
   porque `9e` cambió tres `SELECT m.*` por la lista de columnas nombradas, y
   **nombrar no es leer**. El guardián venía de `39`. **El 49 y el 53 del §105 no se
   mueven**: los dos montones que cambiaron son las dos mitades de lo mismo y su
   suma —93— es idéntica, que es lo que aquel número cruza con los clientes. Queda
   afirmado aparte.
2. **`assertStatus()` recibe un parámetro y allí había dos.** El mensaje no lo leía
   nadie: el test pasaba en verde y el día que ese 200 se rompiera el fallo habría
   salido pelado.

Los dos arreglados en `3a27c4e`. **Suite y larastan en verde, pint PASS.**

> **Nada está desplegado y nada está publicado.** `main` va **72 commits por delante
> de `origin`** y lleva dentro todo el registro, así que quien lo publique publica
> también el porqué de cada cosa. **El último despliegue real sigue siendo el del 21
> ago (`a82cec3`).**

---

## La noche del 25: en curso mientras lees esto

**Coordina `8myvc-94` en `8myvc` y `myvc-front-1f` en el front**, con una sola
interfaz entre las dos. El reparto vive fuera de git, en
`8myvc-cola/noche-2026-08-25/` — con su `BRIEFING.md`, su `TABLERO.md` y una ficha
por lote en `lotes/`.

| Sesión | Lote | Qué contesta |
|---|---|---|
| `8myvc-e0` | **CERT-1** | el consecutivo de certificados: la carrera en transacción con `FOR UPDATE`, y la validación de `cambiar-contador-certificados`. **El permiso NO entra: es tuyo** |
| `8myvc-9a` | **AUD-4** | los diez `INSERT INTO bitacoras` al servicio único, y las cinco familias que hoy no graban nada |
| `8myvc-79` | **GEMELO-1** | el gemelo vivo de `BolfinalesController`: **3.820 consultas y 11,4 s para dar un 500**. El 500 primero |
| `8myvc-12` | **BI-2** | acotar las lecturas de `unidades`/`subunidades` que BI-1 dejó clasificadas |
| `myvc-front-*` (6) | los reparte `myvc-front-1f` | su tablero |

En cola, en este orden: **PUB-1** (tres números distintos para las rutas públicas:
`CLAUDE.md` dice quince, el test enumera siete y `routes/` tiene diecinueve líneas),
**LOGIN-VER** (`version_minima_app` en `/login`, inerte hasta que un `.env` lo
rellene), **VERBOS-1** (los seis `DB::select` que escriben), **AUD-2** (la sesión
atada al token) y **AUD-5** (el permiso de la auditoría, que espera tu palabra).

### Lo que ya enseñó esta noche, y no es una anécdota

- **El clasificador decide por el filtro más grueso, y eso mueve etiquetas sin mover
  riesgo.** El arreglo del 504 (`2837171`) añadió `a.grupo_id = ?` a dos consultas
  para poder agregar, y con ello **cuatro lecturas cambiaron de «bien por
  construcción» a «hay que acotar»** — sin que ninguna perdiera el alcance: el
  alumno sigue en el `WHERE` y en el `GROUP BY` de las dos. **Esta coordinación lo
  publicó como «el arreglo perdió el alcance» y lo retiró `8myvc-12` antes de que
  costara nada**, que es la tercera retractación seguida hecha por quien trajo el
  hallazgo. *La versión vistosa habría mandado a alguien a acotar dos consultas que
  ya estaban acotadas.*
- **Y la que sí queda de ahí, que es de otra forma: la clasificación es por lectura
  y no ve que una lectura segura entregue su resultado a una insegura.**
  `SubunidadesController:86` deriva el grupo desde la unidad —lectura impecable— y
  llama a `Nota::verificarCrearNotas($grupo->grupo_id, …)`, que **crea notas para el
  grupo entero**: el día que una unidad sea de un solo alumno, **añadirle una
  subunidad le crea notas a los treinta**. Y `DefinitivasDeAsignatura::recalcularPorUnidad`
  lee la unidad por id y llama a `recalcular($asignatura_id, $periodo_id)`. **El
  alcance no se pierde en la lectura: se pierde en el traspaso**, y ninguno de los
  dos está en las 59 del lote.
- **Un rojo que no puede volverse verde no es una red, es un párrafo con
  paréntesis.** `8myvc-e0` encontró que el test de la carrera del consecutivo
  ejecuta **su propia copia** del `SELECT`+`UPDATE` en vez de llamar al endpoint, así
  que **seguiría rojo con el arreglo puesto** — y encima `DatabaseTransactions` usa
  una sola conexión, donde un `FOR UPDATE` no se bloquea contra sí mismo. El sitio
  donde eso se detecta es preguntando **qué objeto mide el test**, no si pasa.
- **Un censo de consumidores mira los clientes que alguien listó, y la lista se hace
  de memoria.** El de `c47ab50` acertó con `myvc_flutter` y **falló con la web
  vieja**, o sea justo con lo que está desplegado.
- **Una lista de lo que dejaron las sesiones no cubre lo que dejan los guiones.**
  Ocho workers de larastan de un worktree borrado por este lado, y un `ng serve` de
  dieciocho horas sirviendo el `dist` de otro worktree borrado por el del front —
  **ése no lo dejó una sesión, lo dejó un guion, y por eso no estaba en ninguna
  lista**. `lsof -d cwd` sí los ve.
- **La parte menos vigilada del sistema no es el código ni el instrumento: es qué
  pregunta se le hace y qué trozo de la respuesta se lee.** El hallazgo de que
  `GET profesores` entrega la ficha de los 47 empleados a cualquier docente
  **llevaba impreso desde la noche del 24** en la salida del barrido de un token —
  su constante `PERSONALES` es exactamente esa lista—. El informe de aquella pasada
  tabuló *«93 escrituras alcanzadas»* para el rol `Profesor` **y no la otra
  columna**. No es un detector roto ni una medición que falte: **es una salida
  correcta leída para otra pregunta, y el informe que la perdió es un informe
  bueno.** Hizo falta que alguien llegara desde el front comparando **dos sesiones**
  para que se viera. *De las formas registradas esta noche, ninguna es «el
  instrumento falló».*
- **Curar donde se vio el síntoma y no preguntar quién más hace lo mismo — con el
  hermano a nueve líneas.** El barrido de un token tenía **dos** columnas que
  prometían más de lo que medían. Una está curada, y con su porqué escrito:
  *«"EJECUTA" y no "ESCRIBE": `DB::listen` ve la sentencia, no las filas afectadas…
  deja de prometer lo que no mide»*. **La de al lado tenía la misma enfermedad y
  nadie la miró**: `PERSONALES` marca **por nombre de campo, no por dueño del
  dato** —un `preg_match` sobre el JSON—, así que **un endpoint que te devuelve tu
  propia ficha cuenta igual que uno que te da la del vecino**. Quien vio la
  enfermedad la nombró bien y la curó **en la columna donde la vio**.
- **Y el efecto sobre el número, que es lo que hay que saber antes de citarlo:** las
  52 rutas del `Usuario` sin rol **no son «rutas que devuelven datos de terceros»**,
  son «rutas que devuelven campos personales de alguien», **con dos sesgos de signo
  contrario y magnitud desconocida**: cota **baja** porque las 93 con escritura
  nunca se miraron por esa pregunta, y cota **alta** porque lo propio cuenta como
  ajeno. **No es un censo: es una lista de sitios donde mirar**, y separar lo propio
  de lo ajeno se hace **a mano, fila a fila**. Eso es el lote `FICHAS-1`, no un
  número que se copie.
- **Y su hermana, del otro lado: un guarda que acusa a quien no debe.** Toda la
  noche se cazaron guardianes que dejan pasar; el de CERT-1 iba a **rechazar el caso
  bueno** —`filter_var('007', FILTER_VALIDATE_INT)` es `false`, el `<input>` del
  front no es `type="number"` y **7 de los 8 years llevan el consecutivo relleno a
  tres dígitos**—. Y **el dato que lo decide vive en el repositorio del front**, no
  en éste.
- **Empujar tu rama de trabajo no es publicar, y las dos coordinaciones lo teníamos
  distinto.** En `myvc_front` se empuja la rama propia y está bien. **En `8myvc` no
  se empuja nada**, y no por simetría: de `origin/main` es de donde tiran los
  despliegues de los quince, así que aquí un `push` está a un paso de ser un
  despliegue.

---

## La noche del 24 al 25: catorce sesiones, tres repositorios, dos coordinaciones

**Coordinó `8myvc-34` en `8myvc` y `myvc-front-98` en el front**, con una sola
interfaz entre las dos y ninguna mandando lotes a las sesiones de la otra. El
reparto vive fuera de git, en `8myvc-cola/noche-2026-08-24/`. **Lo hecho:**

| Lote | Qué quedó |
|---|---|
| **AUD-1 + ESC** (`7b`) | el `Reloj` único con centinela y su vuelta (`desdeTexto`), y **la escala validada en el servidor** — Joseth lo pidió esa noche. Cambia respuestas: `notas/update` puede dar **422** donde daba 200 |
| **AUD-3** (`39`) | la tabla `auditoria` y `App\Services\Auditoria`, append-only, **con la primera regla puesta en la forma de la clase** — no tiene dónde recibir «cuántas filas salieron» |
| **BI-1** (`9e`) | el esqueleto del boletín independiente: cuatro migraciones y **el inventario de las 144 lecturas de `unidades`/`subunidades`** (88 bien por construcción, 55 a acotar, 1 sin saber — *corregido el 25: el documento decía 146 y 57, y el total nunca fue 146*) |
| **MED-1** (`ad`) | **cobertura al 100%: 542/542 rutas**; `notas/lote` cronometrado (**3,8×–5,9×**, **717→220 consultas**) y el **429 de la §1 confirmado en la petición 121 de 135** |
| **EXP-1 + PROFES-1** (`d2`) | dos exportaciones **vivas y rotas** desde el salto a Laravel Excel 3.x, y `profesores/update`, que **renombraba y degradaba la cuenta al corregir un teléfono** |

**Trece secciones nuevas en el [05](05-codigo-muerto-y-roto.md), §168 a §180.** Las
dos que más lejos llegan: **86 escrituras crudas** que ningún detector de esta fase
mira —buscan asignaciones de Eloquent y una `UPDATE … SET` no tiene ninguna— y
**115 rutas no-`GET` que no escriben nada**, que a la auditoría le importa porque
**lo que clasifique «qué escribe» por el método HTTP mete esas 115 en el cajón
equivocado**.

### Lo que esta noche enseñó, y no es una anécdota

**Siete instrumentos mintieron, y ninguno mirando el resultado**: un `PDO` con la
contraseña inventada, un `cd` que dejó el shell en el árbol de otros, dos suites de
la misma sesión escribiendo en el mismo fichero, una base a medio construir, una
caché de larastan a medio llenar, `construir-bd-test.sh` sin `-w`, y un `ng serve`
sirviendo un árbol **borrado** y contestando **200**. La forma general:

> **El instrumento correcto sobre el objeto equivocado.** No se ve mirando el
> resultado, porque el resultado es correcto. Sólo se ve preguntando **sobre qué**
> se midió.

Y las dos reglas hermanas, que explican por qué **las siete tenían a alguien que ya
lo sabía**: **una medición no es un guardián** —dice que el índice sirve, no que siga
ahí— y **un aviso no es un control**: *«saberla no basta, hay que tener el paso
puesto»*, dicho por quien se comió la trampa **después de avisar dos veces esa misma
noche de esa forma exacta**. **Cinco de las siete se cierran con un paso en el
procedimiento, no con más conocimiento**, y por eso las reglas que quedaron caben en
una línea: contar tablas y usuarios antes de correr, `ps` **dentro** del contenedor,
`git rev-parse` antes del commit, y **nombrar los ficheros uno a uno**.

**Tres conclusiones se retiraron, las tres por quien las trajo**, y las tres más
baratas que el trabajo que habrían mandado hacer al sitio equivocado. La más caras
de las tres: *«tres peticiones colgadas tumban el backend entero»* —refutada por el
reloj de nginx— y **los porcentajes de hueco de definitivas, que eran míos**: mi
denominador daba por hecho que toda combinación debe existir, y **de 1.196
«ausentes» unos 400 eran de alumnos que se habían ido**.

---

## En curso: las definitivas — **fase 3 terminada**, la 2 esperando un dato tuyo

**El plan entero está en [10-definitivas.md](10-definitivas.md).** Resumen de por
qué se hace: seis sitios escriben en `notas_finales` con cinco criterios distintos
de qué borrar, ninguno transaccional, sobre una tabla sin clave única. De ahí
salen los tres síntomas que se reportaban por separado —definitivas que
desaparecen, duplicadas, y notas puestas que no aparecen— y son el mismo problema.

### Lo hecho

| | |
|---|---|
| **Fase 0** — medir | **hecha**, y la herramienta **corregida el 24 ago** (medía de menos: ver abajo). `tools/salud-de-las-definitivas.php`, sólo SELECT. Medido en un colegio: **11.988 definitivas que deberían existir y no existen**, 718 que discrepan teniendo notas detrás, 1 duplicado |
| **Fase 1** — recalculador único | **escrita y probada.** `App\Services\DefinitivasDeAsignatura`, 14 tests de ida y vuelta. **Cableada sólo en el boletín** |

### La fase 3 — hecha el 24 ago 2026

Los siete disparadores cableados al recalculador único, y con ellos los seis
escritores de la §0 reducidos a uno:

| Disparador | Estado |
|---|---|
| Abrir un boletín | **hecho** |
| Editar una nota (`putUpdate`) y **borrarla** (`deleteDestroy`) | **hecho** — era la petición de origen |
| `putSubunidad`, la nota rápida del horario | **hecho**, y de paso arreglada la §3.1: no guardaba nada **y era una inyección** |
| Unidades y subunidades (crear, editar, borrar) | **hecho** — las cuatro llamadas al calculador viejo, y **ya no dependen de que el cliente mande `asignatura_id`** |
| Copiar un periodo | **hecho** — traía la estructura y no avisaba a nadie |
| Cada carga de /notas (`putDetailed`) | **hecho** — era un DELETE+INSERT por alumno en cada carga; ahora pregunta primero |
| Crear la subunidad y sus notas en la misma transacción | **hecho** — §5.1 cerrada: nacía sola y la ventana podía durar días desde Flutter |

**La fase 3 está completa, y con ella la fase 2 queda desbloqueada.** Auditados
otra vez los `INSERT INTO notas_finales`: **ninguno alcanzable queda sin guarda.**

| Sitio | Estado |
|---|---|
| El servicio, `NotaFinal:309`, `DefinitivasPeriodosController:146` | protegidos desde antes |
| `DefinitivasPeriodosController::putUpdate` (rama sin `nf_id`) | **cerrado el 24 ago** — decide por existencia, en transacción y con `FOR UPDATE` |
| `NotaFinal::alumnos_grupo_nota_final` (4) | **cerrados el 24 ago** — sustituidos por el servicio |
| `Alumnos/Definitivas:53,83` | **sin guarda pero inalcanzables**: uno responde 410 antes de llegar, al otro no lo llama nadie. La fase 5 borra la clase entera |

### La herramienta de la fase 0 medía de menos — arreglado el 24 ago

Antes de que ese `for` salga hacia los quince colegios, había que arreglarlo:
sus bloques 1 y 2 contaban duplicados **dentro del alcance mirado** (con
`--year`, filtrando `deleted_at`, exigiendo que la subunidad siguiera viva) y **un
índice único mira la tabla entera**. `notas` usa SoftDeletes, hay **35.796 notas
colgando de subunidades borradas** sólo en esta base, y `asignaturas.grupo_id` no
tiene clave foránea. Los tres caminos dejaban fuera filas que el `ALTER TABLE` sí
encuentra: un colegio podía leer *«se puede poner el índice sin limpiar nada»* y
que fallara igual.

Ahora los dos bloques dan **dos números** —el de la tabla entera, que es la
condición de entrada, y el del alcance, que dice a cuántas definitivas cambia
limpiar— y avisan cuando difieren. En esta base coinciden (1 y 2); que coincidan
es suerte de esta base, no del esquema. Está detallado en el
[10](10-definitivas.md), en la fase 0.

**No cambia el orden ni desbloquea nada**: la fase 2 sigue esperando los dieciséis
números. Lo que cambia es que ahora contestan la pregunta correcta a la primera.

### Y del backend, lo que salió de la fase 4 — 24 ago

- **`putUpdate` devuelve la definitiva recalculada** en su propia respuesta (clave
  `definitiva`). Ahorra **una petición HTTP por nota tecleada**, no milisegundos
  de base. Campo añadido: la nota sigue con sus mismas claves.
- **¿Pesa recalcular en siete sitios? No** — ~4 ms por nota tecleada, contra los
  ~40–80 ms que cuesta sólo resolver quién pregunta. Medido con
  `tools/coste-del-recalculo.php`. Y **un 3× que resultó ser la caché** se
  escribió, se midió y se revirtió: está en el [02](02-plan-rendimiento.md) para
  que no se reintente.
- **Tres 500 menos, los tres encontrados por el front verificando en el
  navegador**: `perfiles/username` reventaba para **todo acudiente** (1.000 de
  1.067 cuentas) y tapaba una fuga del directorio entero; `Grupo::datos()` daba
  500 por **diecisiete rutas** con cualquier grupo borrado —el grupo 1 lleva en la
  papelera desde 2018—; y falta `num_periodo` contestaba «no tienes permiso».
  **Ninguna suite nuestra los habría encontrado: todos nuestros tests piden ids
  que existen.**
- **Arreglado**: si falta `num_periodo`, `DefinitivasPeriodosController::putUpdate`
  reventaba en la guarda de permisos antes que en la del periodo, así que el
  profesor leía «no tienes permiso» cuando lo que faltaba era un campo. Ahora es
  **422 nombrando el campo**, comprobado antes de la guarda.

### Por qué el botón sigue haciendo falta: los informes leen a ciegas — 27 ago

**No sigue ahí porque falte quitarlo, sino porque ningún informe sabe si lo que va
a imprimir está al día**, así que se pulsa *antes* de imprimir. Censado: **dieciocho
ficheros de `app/Http/Controllers/` nombran `notas_finales`** y el único que
pregunta por el sello antes de pintar es `Informes/BoletinesController`, y sólo en
el boletín individual.

**Y en `app2` pesa más que en la vieja.** La vieja llama a `calcular-grupo-periodo`
en tres sitios y sólo dos son el botón: el tercero recalcula solo al abrir los
boletines de un grupo desactualizado. `app2` se trajo el botón y no ese tercero, así
que **la única defensa allí es acordarse de pulsarlo**.

**Hecho hoy, y sin cablear a propósito**: `DefinitivasDeAsignatura::estadoDelGrupo()`
contesta lo mismo que `estaDesactualizada()` pero por el grupo entero en **una
consulta** — medido, **506 → 1** en un grupo de 10 asignaturas × 28 alumnos. Seis
tests nuevos, y **uno de ellos cazó un fallo de la propia consulta**: con
`COALESCE(x, 0)` dentro de `GREATEST`, MySQL compara **como números** y
`2026-08-28 04:16:41` vale 2026, o sea **cero desactualizadas siempre** y sin una
línea en el log. Detalle y el porqué del centinela, en el [10](10-definitivas.md).

**Lo que espera decisión tuya** es qué hace un informe cuando descubre que está por
detrás: repararlo antes de pintar (lo único que quita el botón), avisar y no
escribir, o reparar sólo el periodo abierto. Las tres, con sus contrapartidas, en el
[10](10-definitivas.md).

### Lo siguiente

1. **La fase 2**: la migración con los dos índices únicos, la limpieza de
   duplicados y el relleno de las que faltan. **Necesita antes los dieciséis
   números de la fase 0** — la herramienta está **y ya mide bien**, hay que
   correrla en el servidor, y es un `for` de una línea que está escrito en el 10.
   La limpieza de `notas` va **sobre la tabla entera**, no sobre las filas vivas.
2. **La fase 4 está HECHA** (24 ago, `myvc_front`, sesión `myvc-front-9a`): los
   cinco puntos en ocho commits sobre `fase-11/definitivas-9a`, con 415 pruebas
   —32 nuevas y **25 de ellas comprobadas en negativo**—. **Sin mezclar a la
   madre.** El punto que depende del backend (`cambiaNotaDef` sin `nf_id`) va
   **aislado en el último commit**, para que sacarlo sea un `reset --hard`: no
   entra hasta que esta tanda esté **desplegada**, no fusionada. Detalle y las
   cinco cosas que el plan daba por ciertas y no lo eran, en el
   [10](10-definitivas.md).
3. **La fase 5 —quitar los botones «Calcular definitivas per N»— no antes** de que
   las 1–4 estén **desplegadas** y la fase 0 dé cero discrepancias durante un
   periodo completo. Hoy esos botones son el parche con el que un colegio se
   arregla; quitarlos antes deja el problema y quita el parche. **Y falta una condición
   más, que se vio el 27 ago**: mientras los informes lean a ciegas, el botón es lo
   único que los pone al día antes de imprimir — la casilla de arriba.

### Y el orden, que se corrigió el 24 ago

**La fase 2 —los índices únicos— no puede ir antes que la 3.** Auditados los once
`INSERT INTO notas_finales`: tres están protegidos, dos son código muerto y
**seis están en pantallas vivas sin guarda**. Con el índice puesto, cada choque es
**un 500 en la pantalla de un profesor** — el peor, `putUpdate`, es el que teclea
la definitiva. Está detallado en el 10, justo antes de la fase 2.

---

## Y en paralelo: las tres cosas que pidió la app — **hechas las tres**

Joseth las autorizó el **24 ago 2026**. Vienen de
`~/DESARROLLOS/myvc_flutter/docs/backend-pendiente.md`, que lleva el contrato de
cada una y la evidencia que la justifica. **No son de la migración**: son lo que
`myvc_flutter` no puede resolver desde su lado.

| | Qué | Estado |
|---|---|---|
| 1 | `PUT notas/lote` — pasar una columna en una petición | **hecho el 24 ago**, 12 tests · **desplegado en los quince el 25** (`eb95cbc`) · **la app lo deja APAGADO a propósito, y espera a Joseth** — ver abajo |
| 2 | `GET disciplina/mis-fichas/{alumno_id?}` — que el alumno y el acudiente vean lo suyo | **hecho el 24 ago** · **desplegado el 25** · **encendido en la app el 26** · **son 11 tests, no 10**: contados ejecutando (`--filter=FichaDisciplinaPropiaTest`, 11 en verde, 73 aserciones) |
| 3 | Notificaciones: endpoint de temas con HMAC, `notificaciones:enviar` y la entrada de cron | **hecho el 24 ago**, 19 tests — falta que Joseth cree el proyecto de Firebase |

### 1 — `PUT notas/lote`, hecho

Una columna de treinta alumnos eran treinta peticiones. Ahora es una, con
`auth.personal`, el permiso comprobado **una vez y antes de escribir**, las
escrituras en **una transacción** y **un recálculo por par (asignatura,
periodo)** al final y fuera de ella. Devuelve `{guardadas, fallidas, definitivas}`
— las fallidas con su motivo, para que la app reintente sólo ésas.

**Y la justificación que traía escrita era la equivocada, lo cual importa más que
el endpoint.** El contrato decía que lo caro era la agregación del recálculo. No
lo es: la sesión de al lado lo midió el mismo día y lo dejó en el
[02](02-plan-rendimiento.md) — **~1,7 ms**, y el *3×* que parecía haber al
estrecharla a un alumno **era la caché**. Lo que sí ahorra es otra cosa y es más
grande:

- **treinta peticiones son treinta veces el coste fijo de resolver quién
  pregunta**, ~40–80 ms (02 §4). Un orden de magnitud por encima del recálculo, y
  sin depender de ninguna caché;
- y **treinta transacciones independientes** dejan, cuando una columna se guarda a
  medias, definitivas calculadas sobre estados intermedios. Un lote entra entero o
  no entra. Eso no es velocidad, es la misma familia de fallos que la fase 3.

**De paso, una trampa que estaba esperando a cualquiera**, no sólo al lote:
`User::aplicarBanderasDelPeriodo` decide con `count($filas) === count($ids)` para
que un periodo borrado cuente como cerrado. Con la lista **sin deduplicar**,
treinta notas del mismo periodo son treinta ids contra una fila y **deniega la
petición entera** con un *«no tienes permiso»* que manda a buscar el fallo donde
no está. Ahora **deduplica ella**, en vez de exigírselo a cada llamante.

> **CUMPLIDA el 25 ago, y la condición estaba mal escrita.** Decía «los
> **dieciséis**», y desde el 25 ago **son quince** — o sea que tal como estaba
> **no se podía cumplir nunca** y dejaba el interruptor en `false` para siempre
> esperando a un colegio dado de baja. La razón de fondo sigue valiendo entera:
> `app/` es copia por colegio y `myvc_flutter` es **una sola app para todos**, así
> que no hay forma de escalonar el cliente, y en el colegio que faltara sería un
> 404 gastado antes de caer al método viejo. Está en
> [DESPLIEGUE.md](../DESPLIEGUE.md) §5.b.
>
> **Lo que la app hizo el 26 con las tres, avisada de que ya estaban en los
> quince:** encendió `disciplinaMisFichas` y `cambiarUsername` (y un tercero suyo,
> `cambiarClavesArreglado`, que va en el mismo `0e7208c`), y **dejó
> `notasLote` APAGADO a propósito** — *«toca la pantalla del trabajo diario de un
> docente, pasar una columna de treinta notas, y eso lo decide Joseth, no lo
> encendemos de paso»*. **Así que el endpoint está desplegado y sin usar, y lo que
> falta no es técnico: es que Joseth diga que sí.** Cuando lo diga, piden una
> comprobación fina antes de encender.

### 2 — `GET disciplina/mis-fichas/{alumno_id?}`, hecho

**El alumno y su familia ya pueden ver su situación disciplinaria.** No entraban
porque los cuatro controladores que tocan `dis_procesos` llevan `auth.personal`
en **todas** sus rutas, y ése aborta con 403 a `Alumno` y `Acudiente`. No era una
decisión de privacidad: era que nadie había escrito la puerta de lectura.

La guarda **ya existía** y hace exactamente esto: `boletin.propio:sin-paz-y-salvo`.
Sin id significa «lo mío» y lo resuelve el controlador —el middleware, al no ver
alumno concreto, deja pasar—, igual que `notas/alumno`. Un acudiente recibe 400 si
no dice de cuál de sus acudidos habla.

**El paz y salvo no aplica**, y es la misma decisión de `notas/alumno` y
`matriculas/prematricular`: retener el boletín de quien debe es una cosa, y
esconderle a una familia la situación disciplinaria de su hijo es otra, y esa
nadie la ha pedido. Tiene su test, con la deuda puesta a mano.

Devuelve `{alumno, config, ordinales}`. **`alumno` con la forma exacta de un
elemento de `PUT disciplina/alumnos`**, y eso no es comodidad: la app reutiliza
`AlumnoDisciplinaModel` y `FichaDisciplinaScreen` tal cual, en modo lectura, y esa
pantalla ya está escrita. **El test que lo sostiene compara las dos respuestas
clave a clave**, no contra una lista escrita a mano — una lista se queda vieja el
día que alguien añada una columna a `Grupo::alumnos`, y el test seguiría verde con
la promesa rota. Sin `grupos` ni `descripciones_typeahead`: eso es del editor.

Dos cosas que salieron por el camino:

- **Las dos consultas de este repo que devuelven «un alumno para disciplina» no
  traen lo mismo.** `Grupo::alumnos` —la del editor— lleva siete columnas que
  `fichaDelAlumno` —la de las tres escrituras— no. Reusar la segunda habría sido
  más corto y habría roto el contrato en silencio.
- **Aquí no se crea la configuración del año si falta.** Sus dos hermanas
  —`grupos/con-disciplina` y `ordinales/ordinales`— insertan la fila. Ésta la abre
  una familia, y una lectura que escribe es la forma más silenciosa de que un
  endpoint de sólo lectura deje de serlo. Sin fila va `config: null` y el cliente
  usa sus valores por defecto.

### 3 — Las notificaciones: endpoint, comando y cron

Las tres piezas escritas. Lo que falta **no es código**: es que Joseth cree el
proyecto de Firebase (ver abajo).

**El endpoint, `GET notificaciones/temas`, es la pieza de seguridad de todo el
diseño.** Firebase reparte por *temas* y **el teléfono se apunta él mismo**, así
que el nombre del tema es en la práctica la única puerta: si se llamara
`alumno_345`, cualquiera con la app se apuntaría al `alumno_346` y recibiría los
avisos de un menor que no es suyo. Por eso el nombre **no se calcula en el
teléfono**: se deriva con `HMAC-SHA256(alumno_id, secreto)` y el teléfono lo
recibe ya hecho, sólo los suyos —los propios si es alumno, los de sus acudidos si
es acudiente, ninguno si es personal—.

El secreto **es `APP_KEY` por defecto, y es una decisión**: hace falta uno
distinto por colegio y que no salga del servidor, y `APP_KEY` ya es las dos
cosas. Así esto funciona sin editar dieciséis `.env`.

**El comando, `notificaciones:enviar`**, saca de `bitacoras`, `ausencias`,
`dis_procesos` y `publicaciones` lo ocurrido desde la última pasada, **agrupa** y
publica. Tres decisiones que valen más que el código:

- **Agrupar es lo que lo hace viable y de paso lo hace mejor.** Un docente que
  pasa una columna genera treinta cambios en dos minutos: sin agrupar son treinta
  avisos y el acudiente apaga las notificaciones para siempre. Agrupado por
  alumno y asignatura es uno.
- **La primera pasada no manda nada**: pone la marca y se va. Sin eso, encender
  el push en un colegio le manda a cada familia un aviso por cada nota del año.
- **La marca se guarda después de publicar, no antes.** Si el proceso se cae en
  medio, la pasada siguiente repite; guardándola antes, lo perdería. Un aviso
  repetido es una molestia, uno perdido es la función sin cumplir.

Y **ningún aviso lleva el dato dentro**: «hay 4 notas nuevas en Matemáticas»,
nunca «sacó 45». Se ve en la pantalla bloqueada, con gente al lado. Tiene su
test, con un valor inconfundible metido a propósito.

**El cron no es el que decía el plan de la app, y es mejor.** Aquel proponía una
entrada nueva con un bucle por los dieciséis directorios. No hace falta: este
proyecto ya decidió **un solo cron por colegio** —`schedule:run` cada minuto— y lo
que corre se decide en `app/Console/Kernel.php`, que **viaja con el `app/`**. Así
que la tercera pieza son tres líneas ahí, `everyFifteenMinutes()` con
`withoutOverlapping()`, y **cero visitas a paneles de cPanel**. Ver
[17-cron.md](17-cron.md).

> **Lo que hace falta de Joseth para que esto llegue a un teléfono**, y hasta
> entonces el comando corre, no manda nada y lo dice:
>
> 1. **Un proyecto de Firebase** y una cuenta de servicio (un JSON).
> 2. Ese JSON **en `storage/` de cada colegio** —no en el repositorio: `app/` es
>    copia por colegio pero el repositorio es común, así que meterlo dentro sería
>    publicar la credencial de push de los quince— y `FCM_PROYECTO` en su
>    `.env`.
> 3. Para iOS, una clave de APNs, que pide cuenta de desarrollador de Apple de
>    pago. Si no la hay, esto sale primero en Android.
>
> Se puede probar antes de todo eso con `php artisan notificaciones:enviar --seco`,
> que dice qué mandaría sin mandar nada y sin mover la marca.

---

## Lo siguiente que se pidió: la auditoría — **plan escrito, sin código**

**[18-auditoria.md](18-auditoria.md).** Salió de tres peticiones que resultaron ser
el mismo problema: un historial fiable de notas modificadas, unas horas que no
salgan raras, y una pantalla de «qué hizo este usuario en este ingreso».

Lo medido el 24 ago, que es lo que decide el plan:

- **10 `INSERT INTO bitacoras` contra 256 escrituras de datos** en 56 controladores.
  Cinco de los diez son de seguridad. **Asistencia, comportamiento, disciplina,
  situaciones y frases no graban nada** — la pantalla pedida no se puede construir
  hoy porque no hay filas que mostrar.
- **Las horas raras son tres causas a la vez**: 118 sitios escriben en Bogotá y
  **17 en UTC** (`config/app.php` dice `UTC`) **sobre la misma columna**; las columnas son
  `TIMESTAMP` y nadie fija la zona de la conexión (`@@session.time_zone = SYSTEM`),
  así que **la lectura depende del hosting de cada colegio**; y conviven
  `TIMESTAMP` con `DATETIME` en la misma tabla.
- **`historial_id` es una adivinanza**: se resuelve con `order by id desc limit 1`
  sobre `historiales`, o sea **el último login del usuario, no la sesión que hizo
  el cambio**. Con el móvil y el navegador abiertos, la pantalla mostraría una
  lista falsa sin ningún error visible. El token y el ingreso no se conocen.
- **La auditoría se puede borrar**: `DELETE bitacoras/destroy/{id}` va con
  `auth.personal`.
- Y `PUT historiales/sesion` **ya intenta ser esa pantalla**, pero sólo trae notas
  y con `INNER JOIN`, así que una nota borrada desaparece del historial.

El plan: tabla `auditoria` nueva (`bitacoras` se congela, no se migra sobre
quince producciones), un solo escritor `App\Services\Auditoria`, append-only,
`DATETIME(3)` con un `Reloj` único y su test, y **la sesión atada al token** antes
de nada. Seis fases; las dos primeras —el reloj y la sesión— **no dependen de
ninguna decisión y ya mejoran la bitácora vieja**.

**Las tres decisiones que lo bloqueaban están contestadas** (24 ago): `ocurrido_en`
en hora de Bogotá con `DATETIME`; `config/app.php` **se queda en UTC** y el `Reloj`
es la única fuente de lo que se guarda; y la auditoría se ve con un permiso nuevo
`can_view_auditoria`, **sembrado sólo a rector y coordinación**, con la regla
añadida de que **cada quien ve siempre lo suyo** sin permiso. Eso obliga a cerrar
en la misma fase las seis rutas viejas que hoy van con `auth.personal` — dejarlas
abiertas convertiría el permiso nuevo en decoración.

**La fase 0 ya tiene herramienta**: `tools/salud-de-la-bitacora.php` (sólo
`SELECT`, diez bloques, `--csv` para juntar los dieciséis). Corrida sobre el seed
da **18 de 3.229 ingresos con algo que enseñar** (99,4% vacíos), **12 filas en UTC
contra 74 en Bogotá** en la misma columna, y **67,6% de las atribuciones a un
ingreso sin poder comprobar**. Sus bloques 3 y 4 se cruzan solos —clasifican por
caminos que no comparten supuesto— y **coincidieron: 12 y 12**, así que el
desfase de cinco horas está confirmado y no supuesto.

Su lista de escritores es a mano y por eso lleva centinela:
`CentinelaDeLosEscritoresDeBitacoraTest` fija que sigan siendo diez, en los mismos
ficheros, **y que los tres de UTC no cambien de reloj** — lo que ningún conteo
vería. Cazó un error en su primera ejecución: se habían publicado 9 escritores y
son 10.

**Lo siguiente es correrla en los quince**, como el `for` de la fase 0 de
definitivas, y con esos números decidir si la historia vieja se reinterpreta o se
da por perdida.

### Lo que la noche del 24 añadió al plan — vino de las otras sesiones

El documento pasó de 740 a ~880 líneas hablando con `myvc-front-10`, `8myvc-dd`,
`8myvc-d2` y (vía el front) `myvc-flutter-fe`. **Los cuatro hallazgos eran ciertos
y los cuatro apuntaban un poco al lado**; se verificaron todos contra el código
antes de aceptarlos, y dos corrigieron el esquema:

| Vino de | Qué era | Qué cambió |
|---|---|---|
| `front` | el plan **no mencionaba `myvc_front` ni una vez** y las fases 5–6 tocaban 6 pantallas vivas | **§4.6 nueva**; las rutas nuevas son **aditivas**; la retirada se va a una **fase 7** |
| `front`/`flutter` | los `intento_login` los pinta `mis-sesiones` | destapó que **`actor_user_id NOT NULL` era un error**: un login fallido no tiene actor (hoy `created_by = 0`) |
| `dd` ([§13](09-pendientes.md)) | `DB::update` devuelve filas **afectadas**, y son 0 si el valor no cambia | **primera regla del escritor**: la escritura ocurrió porque no hubo excepción, no por filas. Y un reguardado sin cambio **sí se registra** |
| `d2` | el `order by id desc limit 1` está en **9 sitios**, no en 2 | la §2 reescrita — y **son 7 + 2**: dos son middlewares que anotan un intento **rechazado**. Mismo arreglo, **fila distinta**: `accion` gana un quinto valor, `denegado` |

Y **la fase 7 pasó a estar sin fecha, que no es lo mismo que lejana**:
`myvc_flutter` **no comprueba versión mínima en ninguna parte**, así que un
teléfono viejo llama indefinidamente y nadie se entera. Mientras eso no exista,
**retirar cualquier endpoint depende de la buena voluntad de dieciséis colegios** —
le pasa igual a la Fase 5 del [00](00-plan-migracion.md), no sólo a esto.

### Y tres cosas que NO son de la auditoría y salieron de camino

Ninguna se buscaba y ninguna estaba en la pregunta original. **No se arreglan en
el 18** — están escritas en su §4.5.1 con la medición, y esperan decisión:

1. **Se pueden teclear decimales en las cuatro pantallas de notas y nada los
   valida** — `notas.nota` es `int` y MySQL trunca en silencio. Y por eso no lo ha
   reportado nadie en veinte años: el aviso verde **repite el número tecleado, no
   el guardado** (`planilla-notas.ts:253`). El profesor lee «Cambiada: 85,5» y hay
   85.
2. **La escala de este colegio es de 0 a 50**, no de 0 a 100 como se suponía, y
   `porc_inicial`/`porc_final` son `int`: el sistema de calificación entero está
   construido sobre enteros. **Es configurable por colegio y por año**, así que si
   en alguno fuera de 1 a 5 la pregunta pasa a ser cuántos años llevan
   perdiéndolos. Se mide con el `for` de la fase 0.
3. **Nada en el backend rechaza una nota por pasarse de la escala.** Diez sitios
   comparan contra `porc_final` y **los diez son para pintar la banda**; ninguno
   aborta. El único guardián es el cliente, y de tres pantallas hermanas **dos
   guardan y una no**.

---

## Y lo último que pidieron los colegios: el boletín independiente — **plan escrito, sin código**

**[19-boletin-independiente.md](19-boletin-independiente.md).** Un alumno se
puede marcar como PIAR; los colegios quieren marcarlo además como **«requiere
boletín independiente»**: sale de las planillas normales, tiene una pantalla
propia donde su docente le escribe **sus** unidades y subunidades del periodo,
y en el boletín aparece como todos pero con las suyas.

Lo medido el 24 ago, que es lo que decide el diseño:

- **74 consultas leen `unidades` y 70 leen `subunidades`** en `app/`, repartidas
  en 24 ficheros, y **todas dan por hecho que una unidad es de la asignatura y de
  nadie más**. El diseño es `unidades.alumno_id` (NULL = del grupo), así que en
  cuanto exista, **cada una de esas 74 está corregida o equivocada** — y una
  consulta sin alcance no falla: devuelve las filas de otro.
- **`notas` y `notas_finales` no se tocan.** La nota del independiente es una
  nota normal colgada de una subunidad normal, así que `notas/update`,
  `notas/lote`, la bitácora y el recalculador único **funcionan sin cambio**, y
  el alumno sale en puestos, finales, actas y certificados sin escribir nada.
- **Los tres boletines se cubren en dos funciones**: `Unidad::deAsignaturaCalculada`
  y `Subunidad::deUnidadCalculada`.
- **`Nota::puestoAlumno` está copiado en ocho sitios**, así que el interruptor de
  los puestos se lee en un servicio y preguntan los ocho.

**Cuatro decisiones tomadas** (todas las asignaturas · la marca en `matriculas`,
por año · el interruptor de puestos en `years` · copiar estructura y preguntar
por las notas) y **la regla que lo hace desplegable**: con las migraciones
puestas y nadie marcado, **los 1.344 tests pasan sin regenerar un solo
snapshot**. Tres rutas nuevas, de 542 a 545.

**El canal con el front es `myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, sección
C**, no este repo: lo pidió Joseth el 24 ago porque **el front no lee `8myvc` por
su cuenta** y este plan estuvo un día escrito sin que nadie lo viera. Toda
decisión que cambie un cuerpo, un nombre de campo o una ruta se escribe **ahí
además de aquí**.

**Comunicado a `myvc_front` el 24 ago** para hacerlo conjuntamente, en dos vueltas
—`myvc-front-12` y `myvc-front-10`, ésta con el inventario de pantallas—. Sus
siete avisos y preguntas están dentro del plan y contestados en su buzón; **uno de
ellos destapó un fallo vivo que no era el que preguntaban** (§9.5: la ficha lee de
una matrícula y escribe en otra cuando hay dos del mismo año) —el más útil, que **un vacío tiene que decir por
qué está vacío**, arregló el punto más flojo (§6.1)—. El front no publica hasta
que esto esté **desplegado** en los quince, y espera además el aviso de que la
tanda de DESPLIEGUE.md salga: tiene cuatro cosas congeladas detrás.

---

## Y la planilla de notas por lotes — **plan escrito, y el endpoint ya estaba**

**[20-pantalla-de-notas.md](20-pantalla-de-notas.md).** Lo pidió Joseth el 24 ago:
que el docente teclee varias notas seguidas sin esperar a cada guardado, que cada
celda diga por sí misma si ya viajó, y que la nota rápida deje de mandar una
petición por nota.

**La noticia que abarata el plan entero: el endpoint del backend ya existe.**
`PUT notas/lote` se escribió el 24 ago *para `myvc_flutter`* y sirve igual para la
planilla web sin tocar una línea — recibe ids de nota sueltos, así que una
columna, una fila y un puñado de celdas recién tecleadas son **el mismo
endpoint**. Casi todo el trabajo es de `myvc_front`.

Lo que el plan deja escrito y no era evidente:

- **El error que sale hoy al pulsar la nota rápida es, con toda probabilidad, un
  `429`**: `throttle:api` son 120/min por usuario y tres columnas de 45 son 135.
  El arreglo es el lote, **no subir el límite**.
- **Un docente pulsando una columna ocupa hasta seis `Entry Processes` a la vez**
  (el navegador abre ~6 conexiones por dominio) y las repone hasta acabar las 45.
  Ocho docentes a la vez, que es lo que pasa en cierre de periodo, son 48 de 50.
  Con lotes, un docente es **una** ranura.
- **El borde no es un borde**: es un elemento flotante **detrás del input y un
  poco más grande**, del que sólo asoma el reborde. Así el input hace de máscara
  —no hay que recortar ningún anillo—, nada queda por encima del campo y
  `box-sizing` ni entra en la conversación. Y tiene que ser así porque
  `_estado-notas.scss` **ya usa el `border-color` del input** para decir *perdida*
  (rojo), *superior* (azul) y *hover de nota rápida* (ámbar), y una nota recién
  tecleada puede ser perdida **y** estar sin guardar a la vez.
- **El truco depende de que el input sea opaco, y hoy lo es por accidente**:
  `input.input-nota` no declara `background-color` — el blanco es el valor por
  defecto del navegador. Se declara como parte del trabajo, o un tema oscuro
  forzado convierte el reborde en un relleno.
- **«El borde se queda pero la animación quieta» es una sola propiedad**:
  `animation-play-state: paused`.
- **Ya hay un temporizador puesto que hay que contar**: el input trae
  `ng-model-options` con `debounce: 1000`, así que el modelo se entera un segundo
  tarde. Con los 2 s del agrupador son **3 s** hasta el PUT, y el halo saldría un
  segundo después de teclear si el estado cuelga de `ng-change`.
- **Y una carrera que está abierta hoy**: `DefinitivasDeAsignatura::recalcular`
  decide crear o actualizar con un `SELECT … ORDER BY id LIMIT 1` **sin `FOR
  UPDATE`**, así que dos recálculos concurrentes del mismo par pueden insertar los
  dos. El flood de 45 peticiones simultáneas de hoy **ya la está ejerciendo**. El
  lote la mitiga; **lo que la cierra es la clave única de la
  [fase 2](10-definitivas.md)**, y una mitigación en uno de los cuatro clientes no
  protege a los otros tres.

**Aquí el front sí se puede escalonar**, al revés que las tres cosas de la app:
`myvc_front` es copia por colegio, así que se publica en el colegio cuyo backend
ya lo tiene.

Falta una medición y está anotada como tal: **nadie ha cronometrado `putLote`**
(tiene 13 tests y ninguna medida). La tabla de la §2 del plan dice «estimado»
hasta que exista.

---

## Lo que espera una decisión de Joseth

Están en [09-pendientes.md](09-pendientes.md), agrupadas. Las que quedan sin
contestar:

- **La hora mal escrita** en filas ya guardadas — y ojo, **se midió y el dato no
  distingue** una fila mal escrita de una normal.
- **Los interruptores `para_*`** — hay que contestarlos con los tres delante.
- **Quién del personal puede qué** — cinco lotes preguntan variantes.
- **Los quince números de la fase 0** de definitivas: la herramienta está, hay
  que correrla en el servidor colegio por colegio (`for` de una línea en el 10).
- **Las tres primeras de la auditoría** se contestaron el 24 ago y están cerradas
  en el [18](18-auditoria.md). Quedaron abiertas **tres** después (eran cuatro hasta que se comprobó que la (a) ya
  estaba contestada):
  **(a)** ~~`/panel/bitacora`, ¿se jubila o se queda?~~ **CERRADA, y esta lista estaba mal:
  llevaba contestada desde el 24 ago.** El [18](18-auditoria.md) la tiene como **DECISIÓN 4
  — se jubila**, con sus tres consecuencias escritas y la tarea puesta como obligatoria en
  `myvc_front/PANTALLAS-HISTORIAL-Y-BOLETIN.md`, que la nombra 13 veces. Joseth la volvió a
  contestar el 26 ago —«se jubila cuando llegue la nueva»— **y dijo lo mismo**, así que no
  hay conflicto de fondo: lo que había era **una lista que no se releyó**, y de paso
  **decía que bloqueaba al front cuando ellos ya tenían la tarea escrita**. *Dos fuentes
  que discrepan son un hallazgo, y aquí la equivocada era ésta*;
  **(b)** tras retirar `bitacoras/destroy`, ¿quién borra un intento fallido? hay
  dos botones encima; **(c)** ¿se persigue lo de los decimales? la consulta de
  escalas en los quince dice si es cosmético o si un colegio lleva años
  perdiéndolos; **(d)** ¿validación de escala en el servidor? es la que cierra el
  agujero de verdad y la más cara — necesita su propia medición.
  **Ninguna de las cuatro bloquea las fases 0 a 6.**

- **[§13](09-pendientes.md) — «No guardado» con 200 cuando sí se guardó.** Salió
  de coordinar el 19 con el front. `DB::update` devuelve filas **afectadas** y
  MySQL devuelve 0 cuando el UPDATE no cambia nada: **guardar el valor que ya
  estaba contesta «No guardado» y el estado es correcto**. Medido: **4 sitios, 6
  rutas**, entre ellas las ~20 propiedades de la ficha del alumno y la rejilla de
  configuración del colegio. **Es el reverso de los «200 que mienten»** —allí el
  tipo, aquí el texto— y **no se arregla en un solo lado**: cambia el cuerpo de
  seis rutas vivas y `myvc_flutter` es una sola app para los quince.

- **Las dos del boletín independiente** ([19](19-boletin-independiente.md) §2):
  **quién puede marcar a un alumno** —hoy la propiedad de matrícula la escriben
  titular y administrativo, y `nee` la escribe además el psicólogo: la propuesta
  es igualarlas— y **qué puesto lleva el boletín de un independiente** cuando el
  interruptor dice que no cuentan (la propuesta es `—`, no un puesto calculado
  sobre una lista de la que se le sacó).

### Y cuatro nuevas del 24 ago, las cuatro con la medición delante

- **[§7](09-pendientes.md) — «restaurar» contesta tres cosas distintas.** Diez
  endpoints: seis devuelven el objeto, tres `'Retaurada'` (mal escrito) y uno
  `'Restaurada'`. **Corregir sólo uno de los tres es la peor opción**: deja la
  misma operación contestando dos cadenas dentro del mismo colegio. Y su
  despliegue va **al revés**: el front delante.
- **[§8](09-pendientes.md) — el año se queda viejo mientras la sesión sigue
  abierta.** No es de acudientes: el login repara `users.periodo_id`, pero nada lo
  mueve con la sesión ya abierta. Decidir si se arregla **en general** o endpoint a
  endpoint.
- **[§9](09-pendientes.md) — el personal ve la ficha de cualquiera por su nombre
  de usuario.** Es la decisión del 21 ago funcionando; lo que nadie llegó a
  preguntarse es qué debe ver un **docente**. **Pasan 43 cuentas y sólo 10 son
  Admin**; para las otras 33 no hay pantalla que lleve ahí.
- **[§10](09-pendientes.md) — `GET api/contratos`. RECORTADO, y la decisión era
  tuya.** Entregaba el domicilio y el móvil de los quince docentes a cualquier
  alumno. El §5 reservaba «qué columnas se recortan» y la tomé con la medición
  delante —los once consumidores leen id, nombre, foto y `user_id`—. **Sin
  desplegar; revertirlo es un commit.**

### Y una de las dos del 24 ago por la tarde sigue abierta — la otra se cerró

- **[§12](09-pendientes.md) — las masivas de cuentas: elegiste la C (por alcance)
  y hecha está la mitad de abajo.** `alumnos/cambiar-claves` pasa a
  `esAdministrativo`. **La mitad de arriba está parada a propósito**: bajar las
  cuatro `cambiar-usuarios/*` a `esSuperusuario` **reversaría una decisión tuya
  del 21 ago** —«puede cambiarle la contraseña/username a los alumnos y acudientes
  solamente», citada literal en `SecretarioTest`—, y la C se propuso sin ese dato
  delante. Las dos salidas que quedan están en el 09. **Nada se toca hasta que
  contestes.**

  > Falló el método, no la conclusión: el barrido miró `Autoriza`, los
  > controladores y sus docblocks, y **no miró los tests**, que es donde vivía tu
  > frase. Aquí una decisión tuya puede estar anotada en un test y no en el código
  > que la aplica.

- **[§11](09-pendientes.md) — cualquier profesor renombraba cualquier cuenta.
  ARREGLADO, no espera nada.** Está aquí sólo para que se despliegue: con
  `users.username` UNIQUE, dejaba a un superusuario fuera del sistema en una
  petición. Lo encontró la sesión de `myvc_flutter` leyendo la ruta que su pantalla
  nueva iba a consumir, y avisó en vez de cablearla.

- **[§14](09-pendientes.md) — ninguna guarda del backend mira el rol `Admin`.**
  `Autoriza::esAdministrativo` es `is_superuser || Secretario`; el `esAdmin` del
  front es `tieneAlgunRol(['admin'])`. **Se llaman casi igual, protegen las mismas
  pantallas y no son la misma condición**, y eso es anterior a todo lo de esta
  noche. En la copia local coinciden —10 y 10, ni uno suelto por ningún lado—
  **pero eso es un colegio y no lo impone el esquema**. Si en alguno hay un `Admin`
  sin `is_superuser`, hoy ya está rebotando en los **once** sitios que piden
  `esAdministrativo`. **Falta el `for` de los quince**; la consulta está escrita
  en el 09.

### El relevo de la sesión de guardas de cuentas (`8myvc-d2`), 24 ago noche

Lo que dejo cerrado, lo que dejo a medias y por qué, para que no haya que
reconstruirlo:

**Commiteado en `fix/username-y-simetria-de-guardas`** (sin publicar, sin fusionar,
sin desplegar): `0e7208c` la §11 y la mitad de abajo de la §12; `8e4d089` la forma
del 422; `e7632cf` los cuatro ficheros de `7b` y `dd` que sólo estaban en el árbol.

**Lo que NO hice y no es un olvido:**

- **La mitad de arriba de la §12** —bajar las cuatro `cambiar-usuarios/*` a
  `esSuperusuario`—. Joseth eligió la C, pero la C se le propuso **sin saber que
  reversaba una decisión suya del 21 ago** que vivía citada en un test y en ningún
  otro sitio. Hay que volver a preguntársela; las dos salidas están en el 09 §12.
- **Los ocho endpoints de la pantalla de cuentas de la app.** Sin autorizar.
  `myvc-flutter-fe` avisa de que **cada uno tiene su interruptor y se encienden por
  separado**, así que se pueden autorizar sueltos y no hace falta el paquete.
- **El `for` de la §14.** Necesita servidor.

**Lo que hay que decirle al front cuando esto se despliegue**, porque no se entera
solo:

1. `PUT alumnos/cambiar-claves` **cambia de forma** —`"Cambiadas"` pasa a
   `{resultado, cambiadas}`— y `app2` la lee con `responseType: 'text'`
   (`datos/alumnos.ts:90-93`, con su prueba en `alumnos.spec.ts:122-130`). **Se
   migra el día del despliegue y no antes**: en un colegio sin desplegar sigue
   llegando texto.
2. Esa misma ruta **ya no alcanza a retirados ni a cuentas borradas**, así que la
   N que `panel-alumnos.ts:684-696` promete antes de apretar deja de coincidir con
   las que cambian. Por eso ahora devuelve el número.
3. `myvc_flutter` tiene **tres interruptores apagados** esperando el despliegue, no
   la fusión.

> **CERRADO el 26 ago 2026: los tres avisos de arriba ya se dieron, porque esa tanda
> se desplegó.** El punto 3 era el que se quedaba viejo de la peor manera —decía
> «esperando el despliegue» **después** de que el despliegue ocurriera—, y costó una
> vuelta entera: la sesión de `myvc_flutter` pidió el 26 ago que se fusionara y
> desplegara una rama con la guarda de `guardar-username` y el alcance de
> `cambiar-claves`, **y pidió además que se escribiera `GET disciplina/mis-fichas`**.
> Las tres cosas llevaban tres días desplegadas.
>
> **No había ninguna rama**: `0e7208c` (las dos de cuentas, el mismo commit) y
> `83bf717` (`mis-fichas`) son **ancestros de `eb95cbc`**, o sea que entraron en los
> quince el 25 ago. Comprobado con `git merge-base --is-ancestor`, y `git diff
> eb95cbc HEAD` da **cero líneas** en esos tres métodos: lo que corre en producción
> es byte a byte lo que hay en `main`. `mis-fichas` es además **una de las tres que
> subieron el contador de 539 a 542** en esa misma tanda, así que estuvo a punto de
> escribirse por cuarta vez una ruta que ya existía — lo que lo evitó fue mirar
> `routes/` antes de escribir el método, y no la memoria de nadie.
>
> **Qué lo escondió, que es lo reutilizable:** el aviso está redactado en futuro
> («cuando esto se despliegue») y **nada lo mueve el día que se despliega**. Un
> pendiente escrito en futuro no envejece a «hecho», envejece a **mentira**, y el
> lector de enfrente no tiene cómo saberlo. Lo mismo vale para la lista de arriba:
> los puntos 1 y 2 —la forma de `cambiar-claves` y su alcance— **también se dieron
> ya**. Cuando una tanda se despliegue, **el mismo commit que lo anota aquí cierra
> sus avisos**.
>
> **Y una cifra suya que hay que corregirles y no es cosmética:** sus tres
> interruptores tienen la condición de encendido escrita como «los dieciséis
> colegios». Desde el 25 ago **son quince**, así que esa condición **no se puede
> cumplir nunca** y los dejaba en `false` para siempre esperando a un colegio que ya
> no existe. Avisados el 26 ago, junto con tres cosas del código desplegado que su
> contrato contradecía —un acudiente **sin `alumno_id` recibe 400** y no la ficha
> (deliberado, con test propio: «lo mío» no significa nada para quien tiene varios
> acudidos), **`config` llega como objeto o `null`** y no como lista, y el año de la
> ficha **sale del alumno y no de quien pregunta**—. La recomendación de encender no
> se dejó apoyada en la lectura: `FichaDisciplinaPropiaTest` (11) y
> `GuardarUsernameTest` (7), **18 en verde y 114 aserciones**, ejecutados contra el
> esquema real.

> **Y una advertencia de método que costó cara esta noche, escrita aquí porque es
> donde la va a leer quien releve:** llegó el aviso de que una sesión se había
> cerrado dejando trabajo sin commitear, y se leyó como *«todo lo que no está
> trackeado es huérfano»*. No lo era: dos de esos ficheros los estaban escribiendo
> sesiones vivas y uno había crecido 10 KB en veinte minutos. **Lo caro no fue
> commitearlo —eso dejó el trabajo a salvo— sino repetirlo**: el error llegó al
> front, que se lo dijo a los dos autores, y un plan que circula como huérfano lo
> re-litiga cualquiera desde cero. **Lo que una sesión te cuenta del árbol se
> comprueba en el árbol**, y costaba un `git status`.

- **Y lo que espera de la pantalla de cuentas de la app**: ocho endpoints nuevos
  que aún **no están autorizados**. El detalle, con lo que ya existe y lo que de
  verdad falta, en el 09 §12 y en
  `~/DESARROLLOS/myvc_flutter/docs/backend-pendiente.md`.

> **La copia local tiene cuatro cuentas con contraseña de prueba y once bitácoras
> borradas** — lo que se le hizo a `simonbolivar` no está en git:
> [15](15-la-noche-en-paralelo.md).

---

## Lo que está fusionado y NO desplegado

**Fusionado no es desplegado**, y `app/` es copia por colegio.

**La base es `9474b50`, desplegado el 31 ago en los quince**, no `eb95cbc`, que es la
tanda del 25 ago. La tanda pendiente está medida y escrita en
[DESPLIEGUE.md](../DESPLIEGUE.md), con qué rompe cada migración y con qué radio.

Medido sobre el rango entero (`9474b50..aebf4ed`) el 2 sep 2026 **después de la fusión de
las tres ramas**, no sumando commit a commit: **191 commits**, **54 ficheros de `app/`**,
**0 de dependencias**, **0 en `config/`**, **0 en `database/schema/`** — y **7 ficheros de
`routes/`: las rutas SÍ se movieron, de 543 a 566** (24 nuevas y **1 retirada**,
`tardanzas/login/traer-datos`). Hay **SIETE migraciones y cinco son bloqueantes**.

> **Estas cifras son de después de fusionar, y por eso se volvieron a contar enteras.** Antes
> de la fusión decían `9474b50..347f137`, 175 commits, 52 de `app/`, 563 rutas y SEIS
> migraciones — **todas ciertas cuando se escribieron y todas viejas cuatro horas después**.
> No se les sumó lo que traían las otras dos ramas: se contó el rango otra vez con
> `route:list --json` y `git diff --name-only`. Que saliera exactamente lo previsto (566 y
> siete) **no es motivo para no haberlo contado**: es la única forma de saber que coincidía.

> ### La cifra peligrosa de este párrafo estaba en la dirección peligrosa
>
> Decía **«`eb95cbc..HEAD`, 542 rutas sin mover, 27 ficheros de `app/`, UNA migración»**, y
> de aquí sale la respuesta a *«¿este despliegue lleva `migrate --force`?»*. Las cuatro
> cifras eran falsas a la vez **porque la base lo era**: medir desde `eb95cbc` cuenta otra
> vez los 44 commits que salieron el 31 ago, y aun así **daba de menos** en todo lo demás,
> que es la dirección que no se nota. *Un rango sin desplegar se remide entero cada vez que
> se le toca — y lo primero que se remide es **desde dónde**.*
>
> **Lo que se estaba prometiendo era «una migración» donde hay seis, y una de ellas retira
> una columna.** El aviso de `DESPLIEGUE.md` decía que sin migrar «los tres boletines
> contestan 500»; el radio real es **el colegio entero, empezando por el login**:
> `years.regla_nivelacion` la nombra `ContextoDeUsuario::construir()` en las cuatro ramas, y
> a esa consulta la dispara **el propio guard** (`ExigirAutenticacion:39` →
> `User::fromToken()`), no un controlador. **544 de las 562 rutas de `api/` caen ahí mismo**,
> y `POST login` y `POST auth/login` con ellas.
>
> **No es teoría: le pasó al docker la madrugada del 2 sep al fusionar, y lo detectó la
> sesión del front, no nosotros.** Es el mismo modo de fallo que cazó `myvc-front-a2` el 27
> ago con `y.usa_consecutivo_certificados`, y **la segunda vez que este párrafo lo dice mal
> mientras `DESPLIEGUE.md` lo dice a medias**. De dos documentos que se contradicen, el que
> se lee primero es éste.
>
> **Y el estado peor no es «sin migrar», es «migrado a medias»**: con la primera de las seis
> corrida y las cinco siguientes no, `matriculas.boletin_independiente` ya no está y
> `years.regla_nivelacion` todavía no — **no funciona ni el código viejo ni el nuevo**. Apareció
> así, sin buscarlo, en **dos** bases de sesión de esta misma noche. **No se sabe cómo llegaron
> ahí**: reconstruirlas con `tools/construir-bd-test.sh` sale completo, así que **no está
> demostrado que sea culpa del script** y escribirlo como si lo estuviera haría que el próximo lo
> diera por conocido y no lo mirara. Lo que sí se arregló es que ese estado **no lo delataba
> nadie**: el script terminaba en `Listo: N tablas` igual de contento con 94 que con 99, y ahora
> cuenta las migraciones y se planta.

Dentro está la nivelación entera (las cuatro rutas de `notas/nivelar` y
`definitivas_periodos/nivelar`), las **diez** de `rubricas/`, el boletín independiente con
sus cinco rutas, `GET colegio/logo` y el panel de inicio adelgazado a la mitad.

**Y trae cinco avisos para el front**, en la tabla de [DESPLIEGUE.md](../DESPLIEGUE.md):
**K** (nueve columnas menos en `ChangesAsked/to-me`, «Por: undefined» en el panel viejo),
**L** (`tardanzas/login/traer-datos` pasa a 404), **M** (`regla_nivelacion` nueva en el
bloque de la sesión), **N** (campos de nivelación en respuestas que ya existían) y **O**
(21 rutas nuevas, todas con `auth.personal` salvo `colegio/logo` — de las de *«quién puede
llamarla»*).

Y en `myvc_front` queda apuntado, sin hacer, el arreglo de **las cuatro altas de la
planilla de notas que no mandan `fecha_hora`** (`MIGRATION.md` §4b.3b).
