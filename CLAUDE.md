`8myvc` es la API del sistema escolar MyVc: Laravel 13 + PHP 8.4, ~37.000 líneas
en `app/`, **118 ficheros de controlador — 121 clases** (recontados el 13 sep 2026 al
entrar `CompetenciasController` y `DesempenosController`; el 5 sep eran 115 y 118 al entrar
`PlantillaNotasController`, el 4 sep 114 y 117, y antes decían 113 y 116, y el trait
`Concerns/ResuelveElUsuario.php` **no cuenta**: no declara ninguna clase, así que el
directorio tiene 119 ficheros y 118 son controladores. **De los tres que entraron entre el
5 y el 13 de septiembre sólo dos son de esta tanda**: el tercero es
`SincronizacionController`, del 7 sep, que nadie recontó — que es exactamente por lo que
este número se cuenta y no se supone), porque
`Alumnos/ImportarController.php` declara cuatro (tres son ayudantes de Excel), y
**623 rutas** (contadas con `route:list --json` el **20 sep 2026 en el ÁRBOL PRINCIPAL, sobre
`main` y después de fundir** (`bdf3c89`) — y coincidieron con las 623 contadas antes en
`.worktrees/imp` tras traer `main`, que es la única forma de saber que coincidía). Las dos
que suben sobre las 621 son las de la **Fase 2 de la importación dinámica** —`GET
importar/alumnos/pendiente/{year}`, que dice si hay una importación a medias, y **`POST
importar/alumnos/ensayo/{year}`, que contesta qué va a pasar sin escribir una sola fila**—, las dos
con `auth.personal`, el mismo guard que la subida.

> **Y aquí las dos ramas del día se cruzaron en TRES sitios y ninguna podía verlo.** Esta cifra se
> escribió **622** mientras `main` estaba en 620; entre medias entró `GET
> requisitos/recorrido/{alumno_id}` y dejó `main` en 621, así que **622 era cierto en su árbol y
> falso en cuanto salió de él**. Lo mismo con el número de documento —las dos sesiones estrenaron
> un **44** el mismo día— y con la migración, donde las dos eligieron el mismo minuto
> (`2026_09_20_300000`). *Tres números elegidos mirando el árbol propio, y los tres describían un
> árbol que ya no existía al fundir.* Se corrigen mirando `main`, no discutiendo cuál tenía razón:
> el documento pasa a **45** y la migración a `…_400000`.

> **Mueven TRES instantáneas y ni una más, y la que NO se mueve explica la regla.** `importar` ya
> tenía **cuatro hermanas con guard** —o sea ≥ 2— así que el candado de familia ya la miraba y
> `familias-que-nunca-entran-en-el-candado.json` no se toca; en `guard-por-familia.json` pasa de
> **4/4 a 6/6**. *El diff de las tres se miró entero en vez de regenerar y pasar: no se movió nada
> que no fueran estas dos líneas.*

> **Y el ensayo trae la regla que este repo lleva meses escribiendo, aplicada a una respuesta que
> no escribe nada: lo que promete tiene que ser lo que pasa.** Lo fija un test que ensaya, importa
> y **compara contra la base** — y ya cazó uno: el `UPDATE` del importador escribe `nro_sisben`
> **dos veces en el mismo `SET`** y gana la segunda, así que un «No aplica» de la hoja acaba en
> `NULL`. El ensayo prometía el valor crudo y **habría mentido en las 37 filas** del seed. *Eso no
> lo ve ninguna lectura del código: sólo lo ve mirar el resultado.*

El número anterior era **620**, contado con `route:list --json` el **20 sep 2026 en el ÁRBOL
PRINCIPAL, sobre `main` y después de fundir** (`b8b3853`) — y coincidieron con las 620 contadas
antes en `.worktrees/es`. La que subía sobre las 619 era **`GET colillas-inscripcion/{codigo}`**, la
**decimosexta pública y la primera de LECTURA** de todo el módulo del formulario: hasta ella las
tres públicas eran las tres de escritura, así que la familia mandaba su comprobante y no podía
saber si se lo aprobaron ni por qué.

El número anterior era **621**, contado con `route:list --json` el **20 sep 2026 en el ÁRBOL
PRINCIPAL, sobre `main` y después de fundir** (`939ec20`) — y coincidieron con las 621 contadas
antes en `.worktrees/est`. La que subía sobre las 620 era **`GET requisitos/recorrido/{alumno_id}`**,
la **fase 1 del proceso de admisión**: el recorrido del día de matrículas, que contesta *«¿puede
atenderlo, o hay que devolverlo, y a dónde?»* — hoy eso depende de que quien atiende mire bien la
hoja.

> **Y la fase 1 entra con TRES columnas donde la propuesta pedía diez, y ninguna de las siete que
> faltan se cae por recorte: cada una la cerró una respuesta de Joseth.** `estacion_nro` no, porque
> el número impreso **es** `requisitos_matricula.orden`, que ya existía; `rol_id` no, porque cierra
> cualquiera del personal; `obligatorio` no, porque describió **un** interruptor y no dos. *Una
> columna sin pantalla no la escribe nadie — es `profesores.tono`, y van cinco en un mes.*

El número anterior era **620**, contado en el ÁRBOL PRINCIPAL sobre `main` tras fundir (`b8b3853`) — y coincidieron con las 620 contadas antes en
`.worktrees/es`, que es la única forma de saber que coincidía. La que sube sobre las
619 es **`GET colillas-inscripcion/{codigo}`**, la **decimosexta pública y la primera de LECTURA**
de todo el módulo del formulario: hasta ella las tres públicas eran las tres de escritura, así que
la familia mandaba su comprobante y no podía saber si se lo aprobaron ni por qué.

> **Mueve los CINCO sitios de la regla y ni uno más, y las dos que NO mueve explican dónde
> ponerla.** `guards-por-ruta.json` lista las que **llevan** guard, y ésta no lleva; y
> `familias-que-nunca-entran-en-el-candado.json` no la recoge porque `colillas-inscripcion` tiene
> **3 hermanas con guard** —o sea ≥ 2— así que el candado de familia sigue mirándola. *Ése fue el
> motivo de colgarla ahí y no de `pagos-inscripcion`, que está en ese censo como «0 de 2».*
> `FamiliasQueNuncaEntranTest` sigue en **26**: esto lee, no escribe.

> **Y aquí la trampa nº 3 de este mismo fichero se cometió otra vez, en la familia de al lado y al
> día siguiente.** `{codigo}` se registró **delante** de `…/pendientes` y **se tragaba la bandeja
> del tesorero**, que pasaba a contestar 422. Lo delató `getRoutes()->match()`, no `route:list`:
> **ése ordena alfabéticamente y no por orden de registro**, así que la forma natural de
> comprobarlo miente. *Un aviso escrito no protege solo; sólo protege el día que alguien hace lo
> que dice* — y esta vez no lo hizo.

El número anterior era **619**, contado con `route:list --json` el **20 sep 2026 en el ÁRBOL
PRINCIPAL, sobre `main` y después de fundir** (`032a1a6`) — y coincidió con las 619 contadas antes
en `.worktrees/fi`, que es la única forma de saber que coincidía. Las cuatro que subían sobre las 615 eran las de **del
papel al alumno** —`GET …/campana`, `GET`/`PUT …/codigo/{codigo}` y `PUT
…/codigo/{codigo}/alumno`—, que cierran el formulario de inscripción: hasta ellas, un formulario
del modo `nuevos` **no se ataba a ningún alumno jamás** y `matricula_id` no lo escribía nadie.
**Mueven TRES instantáneas y no cuatro**: `informes/` tiene de sobra más de dos hermanas con
guard, así que `familias-que-nunca-entran-en-el-candado.json` no se toca.

> **Esta cifra se escribió primero con su condición de caducidad al lado** —*«SIN FUNDIR: hay que
> recontarlas en el árbol principal el día que entren»*— **y se recontó el mismo día, en cuanto
> entró.** Es la tercera vez seguida que esa frase salva el número, y la primera en que quien la
> escribió y quien la cumplió son la misma sesión: *una cifra sin su condición de caducidad al
> lado se lee como cierta para siempre.*

> **Y son las primeras de esta familia con el permiso PARTIDO EN DOS, que es lo que hay que leer
> antes de copiarlas.** Las cuatro del 19 sep llevan `auth.personal` y nada dentro; de éstas, las
> **dos lecturas** van igual —mirar el papel que a uno le ponen delante es lo que hace un docente
> en la estación de documentos— y las **dos escrituras** llevan `Autoriza::puedeAtarFormularios`
> **dentro del método**, porque atar decide de quién es un cobro y `auth.personal` deja pasar a
> las 74 cuentas de personal, de las que 53 son docentes. *Quien lea las dos mitades seguidas
> tiene que poder distinguir una decisión de un olvido.*

El número anterior era **615**, contado con `route:list --json` el **19 sep 2026 por la noche en
el ÁRBOL PRINCIPAL, sobre `main` y después de fundir** (`b5f5345`), en la integración que vació
la cola de ramas. Las dos que suben sobre las 613 son las del **calendario con destinatarios** —`PUT
calendario/mes` y `PUT calendario/proximos`—, que llevaban **dieciocho días** escritas y probadas
en `feat/calendario` sin fundir.

> **Y aquí este contador hizo lo que lleva meses pidiendo, pero al revés de como se esperaba: lo
> que se quedó corto no fue el número, fue la LISTA DE RAMAS.** El primer intento de fundir
> `fix/reparar-la-hora-y-uniformes` murió con un `fatal: Exiting because of an unresolved
> conflict` —otra sesión tenía un merge abierto en el índice compartido del árbol principal— y al
> retomar el hilo no se repitió. No lo delató `git branch --no-merged`, que se había leído antes:
> lo delató **contar las migraciones del árbol** y ver que faltaban dos que sí estaban en la rama.
> *Una fusión que falla por el árbol y no por su contenido deja el mismo rastro que una que nunca
> se intentó — ninguno.*

> **Las dos del calendario suben además `FamiliasQueNuncaEntranTest` de 24 a 26 escrituras**, y el
> renglón está bien por un motivo TERCERO, distinto de los dos que ya había escritos: no es que
> tengan guard —la familia `calendario/*` sigue con **0 de 7**— ni que la llave sea el dato, como
> en `pagos-inscripcion`: es que **preguntan de quién es cada fila dentro del método**, con el
> token, y un evento que no le toca a quien pregunta no viaja. Lo fija `CalendarioMesTest`. **La
> cifra se midió corriendo el test, no sumando**: la rama traía `assertCount(25)`, cierto el día
> que se escribió y falso desde entonces.

El número anterior era **613**, **recontado con `route:list --json` el 19 sep 2026 en el ÁRBOL
PRINCIPAL, sobre `main` y después de fundir** — y coincidió con las 613 contadas antes en
`.worktrees/muro`, que es la única forma de saber que coincidía. La línea anterior decía *«SIN
FUNDIR: hay que recontarlas en el árbol principal el día que entren»*, y aquél fue ese día — la
que subía sobre las 612 era
**`GET muro/app`**, el muro para la app, y **estrena familia**, así que mueve **cuatro**
instantáneas y no tres: las tres de siempre más `familias-que-nunca-entran-en-el-candado.json`,
donde entra como **`muro: 0 de 1`**.

> **Ese `0 de 1` es el renglón que tendría un agujero, y aquí no lo es — va escrito porque la
> regla dice que se acepta con el motivo y nunca regenerando y pasando.** El censo cuenta
> `->middleware(...)` **declarados en la ruta**, y `muro/app` no declara ninguno porque vive
> dentro del grupo `auth.token` de `routes/api.php`, que cubre toda la API. Es **exactamente el
> mismo caso que `notificaciones: 0 de 1`**, que lleva meses ahí por lo mismo. Lo que lo
> distingue de un agujero de verdad no es este texto: es
> `MuroParaLaAppTest::test_sin_token_no_contesta`, que exige **401**.

El número anterior era **612**, contado con `route:list --json` el **19 sep 2026 a las 18:54 en el ÁRBOL
PRINCIPAL, sobre `main` y después de fundir** (`982a8cb`) — y **coincidió con las 612 contadas
antes en `.worktrees/92`, que es la única forma de saber que coincidía**. Las dos que suben sobre las 610 son
las de `pagos-inscripcion/` —el checkout y el webhook del pago en línea del formulario—, que
**cierran las diez** que autorizó Joseth ese día. Las 610 anteriores se contaron a las 18:35 **en
el ÁRBOL PRINCIPAL, sobre `main` y después de fundir** (`d3e57c7`), y coincidieron con las 610
contadas antes en el worktree; las cuatro que subían sobre las 606 eran las de
`colillas-inscripcion/`.

> **Y estas dos suben las públicas de trece a QUINCE, que es donde está el trabajo de verdad.**
> Una ruta pública mueve cinco sitios —las tres instantáneas más `AutenticacionTest::SIN_GUARD` y
> `RutasPreLoginTest::TOTAL_PUBLICAS`— y **son SEIS cuando además ESCRIBE**, cosa que no decía
> ninguna línea de este fichero y que costó un rojo el 19 sep:
> `FamiliasQueNuncaEntranTest::test_cuantas_escrituras_viven_donde_el_candado_no_llega` lleva la
> cuenta de las escrituras que viven en familias que el candado de familia **no mira nunca**, y
> pasa de **22 a 24**. *(Y a **26** esa misma noche, al fundir las dos del calendario: ahí el
> motivo es un tercero —preguntan de quién es la fila **dentro del método**— y está escrito en el
> propio test. Tres subidas seguidas con tres motivos distintos y ninguna que sea un agujero: lo
> que este centinela busca sigue sin aparecer, que es exactamente por qué no se regenera.)*
>
> **La colilla no lo movió y éstas sí, y la diferencia no es de mérito**: `colillas-inscripcion`
> tiene tres hermanas con guard, así que el candado ya la miraba; `pagos-inscripcion` **no tiene
> ninguna**. O sea que *«una pública mueve cinco sitios»* es verdad sólo mientras la familia esté
> guardada por otro lado — y **una familia nueva entera de rutas públicas mueve seis**.
>
> Ese renglón se acepta **con el motivo escrito y nunca metiendo la familia en la lista de
> exclusiones**, que era el atajo que había a mano. Lo que ese candado pregunta es *«¿algún
> mecanismo comprueba de quién es la fila que toca?»*, y aquí la respuesta es **sí, pero no es un
> guard: la llave es el dato** —un código que valida su propio carácter de control, una referencia
> de 64 caracteres que acuñamos nosotros—. El día que acepten un `orden_id` suelto en el cuerpo
> **seguirán contando 24 y ya serán un agujero**: el número no es la garantía, el porqué de cada
> renglón sí.

> **Esta línea la dejó escrita la sesión anterior como una instrucción, no como un dato** —«SIN
> FUNDIR: hay que recontarlas en el árbol principal el día que entren»— y ése es el único motivo
> de que no haya envejecido a mentira. Una cifra sin su condición de caducidad al lado se lee como
> cierta para siempre; ésta llevaba dentro el día en que dejaba de serlo.

> **Y aquí sube LA DECIMOTERCERA PÚBLICA, la primera que RECIBE algo en vez de darlo.** Las doce
> anteriores entregan datos sin token; `POST colillas-inscripcion/{codigo}` **acepta un fichero de
> un desconocido**. No es por comodidad: quien paga el formulario es la familia de un aspirante que
> todavía no es alumno, **no tiene cuenta y no puede tenerla**, así que no hay guard de propiedad
> que aplicarle. Por eso mueve **cinco** sitios y no tres — las tres instantáneas más
> `AutenticacionTest::SIN_GUARD` y `RutasPreLoginTest::TOTAL_PUBLICAS` (12 → 13)— y además hay que
> declararla en `AutorizacionTest::EXCEPCIONES_DE_FAMILIA`, porque sus tres hermanas sí llevan
> guard y el candado la delata como «sola sin el guard de su familia». **Se declara con el motivo,
> nunca regenerando y pasando.**
>
> Lo que la acota no es un middleware: el carácter de control del código —comprobado antes de tocar
> la base—, el limitador `colilla` por IP y por código, la lista blanca de tipos **por extensión y
> por contenido**, y sobre todo **tres comprobantes por orden con uno solo pendiente**, que es lo
> único que no se reinicia con el reloj. Un limitador protege la base; lo que protege el disco es
> la cuenta por fila.

El número anterior era **606**, contado con `route:list --json` el **19 sep 2026 a las 17:19 en el ÁRBOL PRINCIPAL,
sobre `main` y después de fundir** (`45f6e4f`) — y **coincidió con las 606 contadas antes en
`.worktrees/92`, que es la única forma de saber que coincidía**. Las cuatro que suben
sobre las 602 son las del **formulario de inscripción impreso**: `POST
informes/formularios-inscripcion` que acuña, `GET …/{lote}` que relee sin acuñar, y `GET`/`PUT
…/campos`, que son la lista de campos que cada colegio elige para su papel. Las cuatro con
`auth.personal`.

> **`campos` se registra ANTES que `{lote}` y eso no es estilo.** Laravel casa por orden, así que
> con el comodín delante una petición a `…/campos` entraría por el lote llamado «campos» y
> contestaría 404: la pantalla de configuración no funcionaría y el error no diría por qué. Lo fija
> un test, porque es un fallo que vive en una línea invisible. Las autorizó Joseth el 19 sep con el precio delante, y el alcance entero del
formulario son **diez**: éstas dos, dos de configuración de campos, cuatro de la colilla del pago y
dos de la pasarela, con **tres públicas** entre ellas (12 → 15).

> **La segunda no es comodidad y por poco no existe: la pidió el front y tenía razón.** Mi contrato
> sólo llevaba el `POST`, y con eso **recargar la pantalla vuelve a acuñar**: una impresora atascada
> cuesta diez códigos, y acuñar es irreversible. Es el mismo error que el `antiguos` que yo había
> escrito como «acuña» cuando el requisito era «un código por alumno y año» — las dos frases no
> podían ser ciertas a la vez.
>
> **Y el candado del nombre del método cazó un tercero.** El método se llamaba `postIndex`, que lo
> metía en la cohorte de `@postIndex` —donde hay tres rutas públicas por diseño— y
> `AutorizacionTest` lo delató como «una ruta sola entre sus hermanas». El arreglo no fue añadirlo a
> ninguna lista: fue **llamarlo `postAcunar`, que es lo que hace**. Un candado de consistencia
> diciendo la verdad sobre un nombre.

El número anterior era **602**, contado con `route:list --json` el **19 sep 2026 en el ÁRBOL PRINCIPAL, sobre
`main` y después de fundir**. Las dos que entraron sobre las 600 son los **dos interruptores de
la campaña de prematrícula** —`PUT years/toggle-prematricula-nuevos` y `…-antiguos`—, y **no las
pidió una pantalla nueva: las pidió un 404**. `app2` ya las llamaba desde su pantalla de ajustes
del año, y el front las había dejado escritas con el aviso puesto. El hueco que tapan estaba
medido: hasta ese día `years.prematr_nuevos` y `years.prematr_antiguos` **no las escribía ninguna
aplicación** —`years/guardar-cambios` nombra veintiún campos y ninguno es éste, y lo único que las
tocaba en todo el backend era **crear un año**, que las copia del anterior—, así que abrir la
campaña de 2027 era heredar el valor bueno o un `UPDATE` a mano, colegio por colegio. Es
`profesores.tono` otra vez.

> **Son DOS y no una, y aquí `toggle-cambiar-valor` SÍ podía.** A diferencia de
> `modelo_evaluacion` —que no cabía en ninguna ruta existente— el conmutador genérico escribe
> cualquier columna de `years` con este mismo `auth.personal`, así que esto no es una
> imposibilidad: es una decisión de forma. Los otros diez interruptores del año son
> `{year_id, can}` contra ruta propia y aquél pide **el nombre de la columna dentro del cuerpo**;
> serían doce interruptores con once formas. Por lo mismo se descartó una sola ruta con
> `flujo: 'nuevos'|'antiguos'`. **Cuando la ruta nueva es forma y no necesidad, se dice.**
>
> **Y el permiso va al revés que el del día anterior, a propósito**: `auth.personal` y **nada
> dentro** —las 74 cuentas de personal—, decidido por Joseth el 19 sep con las dos poblaciones
> delante, mientras que `toggle-mostrar-nota-numerica` (18 sep) puso el permiso dentro. El caso
> ni siquiera es el más inocente: `prematr_nuevos` enciende **una puerta que se ve desde
> internet sin cuenta**. Por eso la diferencia va escrita en los dos métodos y en el router —
> quien lea las dos familias seguidas tiene que poder distinguir una decisión de un olvido.

El número anterior era **600**, contado con `route:list --json` el **18 sep 2026 en el ÁRBOL
PRINCIPAL, sobre `main`**. Las cinco que entraron sobre las 595 son **dos familias nuevas de una vez**, y el
alcance lo autorizó Joseth con el precio delante: **tres** de `informes-recientes` —`GET`,
`POST` idempotente por huella y `DELETE`— y **dos** de `accesos-favoritos` —`GET` y `PUT`—,
que son el historial de informes y los favoritos del menú de la pantalla nueva de `/informes`
en `app2`. Las tablas llevaban desde esa mañana en `main` sin ningún endpoint.

> **El plan decía SEIS y son CINCO, y no por recorte.** Favoritos iba a llevar tres y lleva
> dos: `orden` es una propiedad de la **lista** y no de un renglón, así que el `PUT` manda la
> lista entera y con eso marcar, desmarcar, reordenar y renombrar son **una sola escritura
> atómica**; un `DELETE` aparte no haría nada que no haga mandar la lista sin ese renglón, y
> una ruta que duplica a otra hay que mantenerla, documentarla y probarla para siempre. Es el
> mismo caso que la Fase 6 del doc 35, que preveió cuatro y entregó dos. **Quedarse corto
> respecto a lo autorizado se cuenta y se dice; no se rellena para cuadrar.**

Y las cuatro anteriores, que entraron sobre las 591: **tres** son la familia nueva `areas/jefes`
—`GET`, `PUT` y `DELETE`, el jefe de área— y **una** es
`PUT years/toggle-mostrar-nota-numerica`, el interruptor de si el boletín imprime el número
además del desempeño. **Esta cifra la mueven varias sesiones a la vez y por eso se vuelve a
contar entera**: quien añadió las tres de `areas/` regeneró sus instantáneas y **no tocó este
número**, así que el contador ya llevaba tres de retraso antes de que la cuarta se escribiera —
que es exactamente lo que el recuadro de más abajo lleva meses avisando, ocurriendo otra vez.
La forma de verlo sin contar de memoria es diferenciar `rutas.json` contra el commit que
registró la cifra anterior, que es de donde salen estas cuatro.

El número anterior era **591**, contado con `route:list --json` el **17 sep 2026 a las 20:48 en el ÁRBOL
PRINCIPAL, sobre `main` y después de fundir** las cuatro tareas del modelo plano por competencias
—`docs/migracion/39-el-modelo-plano-por-competencias.md`—. **Es la primera vez que este número
BAJA**, y el motivo es que «competencia» y «desempeño» resultaron ser la misma cosa: sobraba un
piso entero, así que las **21** de `competencias/` y `desempenos/` se quedan en **7**, y suben **2**
de `frases_asignatura/grupo` para las pantallas de preescolar. 603 − 14 + 2 = 591, **y coincidió
con lo contado, que es la única forma de saber que coincidía**.

> **Y aquí el contador hizo por fin lo que esta línea lleva meses pidiendo: delatar.** A media
> tarde dio **589** —14 rutas menos y sin las dos de preescolar, que aún no estaban— y a las 20:48
> dio **591**. Las dos eran ciertas **y ninguna era el árbol que iba a quedar**: entre una y otra
> se fundió otra tarea. Contar al principio de una tanda de trabajo paralelo no vale; **hay que
> contar cuando `main` ya no se mueve**, que es lo que dice la frase de arriba y lo que esta vez sí
> se hizo.

El número anterior era **603**, contado con
`route:list --json` el **13 sep 2026 por la noche en el ÁRBOL PRINCIPAL, sobre `main` y después de
fundir**, con las fases 2, 3, **4 y 6** del doc 35 dentro. Las cuatro últimas, sobre las 599 de las
fases 2 y 3: **600–601** son las **dos de la rejilla premarcada** —`GET`/`PUT desempenos/rejilla`,
Fase 4— y **602–603** las **dos del boletín por competencias** —`PUT
boletines-competencias/detailed-notas` y `…/detailed-notas-group`, Fase 6—. El plan preveía
**cuatro** para la Fase 6 y son dos: las otras dos no se podían calcar —una manda un ALUMNO a la
papelera y la otra es byte a byte la misma en los tres y no la llama nadie—, y el porqué está en la
§Fase 6 del doc 35.

> **Este contador se mueve a mano, no lo comprueba ningún test, y ya se quedó corto otra vez.**
> Entre la fusión de la Fase 4 y ésta, el árbol principal respondía **601** y esta línea decía
> **599**: la rejilla añadió sus dos rutas y no lo recontó. No es un reproche —es exactamente para
> lo que esta línea dice en qué árbol y en qué momento se contó—, pero sí la razón de que **se
> cuente después de avanzar `main` y en el árbol principal**: un número contado en un worktree
> describe un árbol que mañana no existe.
El 24 ago el de rutas se movió por primera vez,
de 539 a 542, con los tres endpoints que pidió `myvc_flutter`, el 28 a 543 con
`PUT users/mi-docente`, que pidió Joseth para el panel de `app2`, el 31 a 544 con
`GET grupos/{grupo_id}/alumnos-de/{que}`, que pidió el front para el modal de
«Alumnos por grupo» del mismo panel, y el 1 sep a 549: **545–547** con las tres del
boletín independiente —`periodo`, `planilla` y `copiar`— y **548–549** con
`boletin-independiente/marcados` y `/alumno`, las dos lecturas de la pantalla por
estudiante que autorizó Joseth ese día, y **550** con `GET colegio/logo`, la
pública que deja a la pantalla de login pedir el logo del colegio sin token, también
decidida por Joseth ese día, y el 2 sep a **563** de una vez, con las dos épicas
de esa noche: **las cuatro de nivelar** —`PUT`/`DELETE notas/nivelar/{id}`,
`PUT notas/nivelar/lote` y `PUT definitivas_periodos/nivelar`—, que son **endpoints
nuevos por diseño**, porque `notas/update` y `notas/lote` no pueden aprender a nivelar:
`myvc_flutter` es una sola app para los dieciséis y una versión vieja convive meses, así que
un 95 tecleado desde el móvil se guardaría topado
(`docs/migracion/22-nivelaciones.md`); y **las diez de `rubricas/`**, la familia entera de
la decisión 4 de Joseth de ese día —la rúbrica produce la nota—, contrato en
`docs/migracion/26-rubricas.md`. **Ese salto lo trajo una fusión de tres ramas y por eso
el número se contó entero, no se sumaron los dos tramos** —y contarlo fue lo que lo
salvó, porque el mismo día **bajó una**: Joseth mandó retirar
`tardanzas/login/traer-datos`, así que 550 + 4 + 10 − 1 = **563**—, y esa misma noche a
**566** con **las tres de `horario/`** —`POST` y `GET horario/versiones` y
`PUT horario/versiones/{id}/oficial`—, que Joseth autorizó **las tres a la vez** y con esa
razón escrita: con sólo las dos primeras se puede subir y listar, pero **nadie puede marcar
la oficial y «Clases de hoy» sigue vacía**, que es el problema que ese módulo viene a
resolver (`docs/migracion/23-horarios.md` §5.3). El horario **se cuadra en un programa de
escritorio**; a esta API le queda guardar versiones de un año y decir cuál es la oficial.
**566 se contó, no se sumó** — coincidió con 563 + 3, que es la única forma de saber que
coincidía. Y el 4 sep 2026 a **567** con **la cuarta de `horario/`** —`GET
horario/versiones/{id}/lecciones`, la que devuelve el horario para pintarlo—, que era **lo
único que bloqueaba la web del horario**: se podía subir, listar y publicar, y no había
forma de mirar lo publicado. Va con `auth.personal`, el mismo permiso que listar, y **sigue
sin ser descargar**: el fichero de proyecto no viaja (`docs/migracion/23-horarios.md`
§9.bis). Su forma la decidió Joseth ese día sobre lo medido en los dos repositorios, y lo
que la determinó fue que **esta base no puede devolver un proyecto completo**: por eso cada
catálogo viaja con su estado —y son **cuatro**, porque «el colegio no creó ninguno» y «esta
API no puede saberlo» no son lo mismo—. Y ese mismo día a **568** con **la quinta de
`horario/`** —`PUT horario/docentes/{profesor_id}/tono`, el color de un docente—, que salió
de un hueco que **no buscaba nadie**: la cuarta ruta **lee** `profesores.tono` y en toda la
API **no había una sola escritura de esa columna**, así que el renglón iba a salir `vacio`
en los diecisiete para siempre. Lo destapó `myvc_front` preguntando si `putUpdate` podía
**borrar** el tono —no puede— y de camino salió que **tampoco podía ponerlo, ni él ni
nadie**. Joseth decidió el color «automático inicial, cambiable», y **quién** lo cambia:
también los coordinadores, o sea `puedePublicarHorario` y **no** la ficha del docente, que
exige `esSuperusuario` dentro y lo habría dejado en once personas y ningún coordinador —**la
salida barata no era la misma decisión con menos trabajo, era otra decisión**. **568 se
contó con `route:list --json`, no se sumó.** Y ese mismo día a **577** con **las nueve de
`plantilla-notas/`** —el `GET`, los seis de unidad y subunidad, `orden` y `sembrar`—, que
son la **Entrega 1** de `docs/migracion/28-competencias-e-indicadores.md` y sacan la
plantilla de notas del colegio de **phpMyAdmin**, que es literalmente donde se editaba. Las
nueve las autorizó Joseth **a la vez y con el precio delante**, por la misma razón que las
tres primeras de `horario/`: con el `GET` y los `POST` se puede escribir una plantilla y
**nadie puede aplicarla**. Van con `auth.personal` en la ruta y un permiso nuevo
—`can_edit_plantilla_notas`— **dentro**, porque `auth.personal` deja pasar a cualquier
docente y una fila de esa plantilla **multiplica**: un 90 % escrito ahí es un 90 % en todas
las asignaturas que se siembren. Entraron junto con el **alcance** de la Entrega 7(a) —dos
columnas anulables en `unidades_por_defecto`— y no por comodidad: **las columnas sin
pantalla no las puede escribir nadie**, que es el caso `profesores.tono` del día anterior
visto antes de cometerlo, y la pantalla sin alcance le siembra la plantilla de una fila de
preescolar **a todo el bachillerato**. **577 se contó con `route:list --json`, no se
sumó** — coincidió con 568 + 9. Y ese mismo día a **578** con **la sexta de `horario/`**
—`GET horario/versiones/{id}/proyecto`, descargar el `.myvch` que subió el colegio—, que
es la **última decisión que le quedaba abierta a ese módulo** y llevaba desde el 2 sep
escrita como pregunta **sin que nadie la pidiera**. Joseth la autorizó al ponérsela con lo
que costaba y lo que cerraba: hasta ese día el proyecto de una versión **sólo se sacaba con
un `SELECT` a mano**. Su permiso **no es el de mirar**: `puedePublicarHorario` dentro del
método, que es el tercer escalón de una escalera que este módulo trazó en tres pasos
—*«listar no es descargar»*, *«mirar no es llevarse»* y ésta—, porque el fichero lleva
dentro las disponibilidades declaradas de los 47 docentes. **578 se contó desde el árbol en
el que se escribió, que es lo que ahora exige el recuadro de abajo.** Y el 7 sep 2026 a
**579** con `GET sincronizacion/huella`, la **primera ruta que no existe para traer datos
sino para no traerlos**: `myvc_horarios` pregunta cada minuto si el colegio ha cambiado, y
eso eran **cinco viajes y 121.183 bytes por pregunta** —58,2 MB por jornada de ocho horas y
por escritorio abierto, medido— **sin ninguna forma de preguntar barato**, porque ninguna de
las cinco manda `ETag` ni `Last-Modified` y el 304 no existe. La huella son **345 bytes**, un
viaje y cinco cuentas en vez de 207 filas: es la única de las tres opciones que ahorra
trabajo **también al servidor**. Devuelve `(filas, ultimo_cambio)` por lectura y **las dos
hacen falta**, porque `updated_at` no ve un borrado y el conteo sí. Su regla dura —y el único
sitio donde puede dejar de medir lo que dice— es que **se calcula sobre lo que devuelve cada
lectura y no sobre la tabla**: en el docker, `asignaturas` tiene 1.459 filas y la lectura
devuelve 134, así que una huella de la tabla se movería con lo que el cliente no ve y podría
no moverse con lo que sí. El guard es `auth.personal` **aunque cuatro de las cinco lecturas
se conformen con `auth.token`** —no le quita nada a nadie y baja la superficie de **2.358
cuentas a 74**—, y **el quinto bloque, `profesores`, lo decide `esAdministrativo` dentro del
método**, que son **11** de esas 74: una huella del personal para quien no puede leer el personal
sigue siendo información sobre personas.

> **Ese par decía «2.328 a 45» y «10 de esas 45» hasta el 13 sep 2026, y NINGUNO de los tres se
> reproducía.** Remedidos ese día en la base de desarrollo con el criterio leído del middleware
> —`ExigirPersonal::FUERA`, o sea `tipo NOT IN ('Alumno','Acudiente')`— y no supuesto: **2.358**
> cuentas vivas, **74** de personal (53 `Profesor` + 21 `Usuario`) y **11** superusuarios.
> **El argumento no se mueve** —74 sigue siendo despreciable frente a 2.358— y por eso se corrigen
> las cifras y no la decisión. De dónde salió el 45 **no se sabe**: no es la base de tests (71),
> no es `profesores` (47), y no envejeció —sólo hay tres cuentas de personal creadas en 2026—.
>
> **Y se dejan con la orden que las recuenta, que es lo que impide que vuelva a pasar**, porque
> una cifra sin su forma de rehacerla envejece sin que nada se ponga rojo:
>
> ```sql
> SELECT COUNT(*) FROM users WHERE deleted_at IS NULL;                    -- 2.358
> SELECT COUNT(*) FROM users WHERE deleted_at IS NULL
>        AND tipo NOT IN ('Alumno','Acudiente');                          -- 74  (75 el 20 sep)
> SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_superuser=1; -- 11
> ```
>
> > **La segunda da 75 el 20 sep 2026**, remedida de camino al construir la Fase 2 de la
> > importación. Una cuenta de personal más en una semana: la cifra no envejeció mal, se movió. El
> > argumento sigue igual de válido y por eso se anota al lado en vez de reescribir el párrafo.
> >
> > **Y de esa misma medición sale un reparto que no estaba escrito y muerde en otro sitio: de las
> > 22 cuentas de tipo `Usuario` —los administrativos— NINGUNA tiene ficha en `profesores`.** Las
> > 47 que la tienen son docentes. Cualquier consulta que saque el nombre de una persona uniendo
> > sólo contra `profesores` **deja sin nombre justo a secretaría**, que es quien usa media
> > aplicación. Costó un campo vacío en `GET importar/alumnos/pendiente/{year}` el mismo día:
> >
> > ```sql
> > SELECT COUNT(*) FROM users u INNER JOIN profesores p ON p.user_id = u.id
> >  AND p.deleted_at IS NULL WHERE u.deleted_at IS NULL AND u.tipo = 'Usuario';   -- 0 de 22
> > ```
>
> **Son de UN colegio**, el de la copia de desarrollo, no de los dieciséis. Y `esAdministrativo`
> es `is_superuser || isSecretario`: hoy coincide con los 11 **porque el rol `Secretario` no tiene
> titulares**, no por definición — el colegio que nombre uno lo descubre. Cuando se omite **se dice**, en `omitidas`, porque
un bloque ausente y un bloque que no se movió se leen igual desde el cliente.
**579 se contó con `route:list --json` en `.worktrees/e5`, no se sumó.** Y el 13 sep 2026 a
**580** con **`PUT years/modelo-evaluacion`**, la **Fase 1** del modelo de evaluación
(`docs/migracion/35-el-modelo-de-evaluacion-del-colegio.md` §2): el colegio elige entre
`ponderado` —el de hoy— y `competencias`, y esa elección es **una columna de `years`**, o sea
**por año**, porque un año cerrado conserva el suyo para siempre. Es ruta propia **por decisión de
Joseth (D24)** y no una línea más en `years/guardar-cambios`, y las dos mitades del porqué valen
para la próxima columna igual que para ésta: aquel método **nombra veintiuna columnas una a una**,
así que la nueva no la escribiría nadie —`profesores.tono` visto antes de cometerlo—, y **los
dieciséis `years/*` de escritura son `auth.personal`**, o sea que colgarla de cualquiera de ellos
dejaría que **cualquier docente cambiara el modelo de evaluación del colegio entero**. Lleva
`auth.personal` en la ruta y `can_edit_plantilla_notas` **dentro**, la forma de `plantilla-notas/`
y **sin permisos nuevos**. Los tres `displayname` del desempeño **no** gastan ruta: van en
`guardar-cambios` con las seis de unidad y subunidad, porque son rótulos.
**Y la ruta sola no habría cerrado nada**: `PUT years/toggle-cambiar-valor` escribe **cualquier
columna de `years` que exista** con sólo `auth.personal`, así que `modelo_evaluacion` quedó
excluida ahí igual que `actual` —dos exclusiones y por motivos distintos: aquélla tiene un
invariante de fila, ésta tiene **dueño**—. *Al darle dueño a una columna se repasan **todos** los
caminos que escriben esa tabla, no sólo el que se está tocando.*
**580 se contó con `route:list --json` en el árbol principal, no se sumó** — coincidió con
579 + 1, que es la única forma de saber que coincidía. Y ese mismo 13 sep a **599** de una
vez, con **las fases 2 y 3** del mismo documento: **587–593** son las **siete de
`competencias/`** —el CRUD del plan de área más `GET competencias/catalogo-men`, que trae
los **Estándares Básicos del MEN empaquetados con el código** (523 enunciados, 173 KiB, 21
comprimido) y que va **dentro de esa familia y no en una propia** porque una ruta sola sin
hermanas entra en `familias-que-nunca-entran-en-el-candado.json` como «1 de 1», que es la
forma exacta que tendría un agujero—; y **594–599** son las **doce de `desempenos/`**, que
el plan daba por **ocho**: las cuatro que faltaban son las que el docente necesita para
**añadir los suyos** (D14), y sin ellas el candado de esa decisión **no tiene nada que
candar** y la pantalla del docente nace de sólo lectura. Es la §1.4 otra vez —un hueco que
el documento no vio— y por eso **el plan pasa de 22 rutas a 26**, contado y no discutido.
**599 se contó con `route:list --json` en el árbol principal**, después de fundir y con la
suite entera en verde detrás: **2.179 = 2.115 + 33 + 31**, y ninguna prueba existente se
movió.

> **Y los dos tramos de esa frase están mal numerados**, cosa que nadie había recontado: `587–593`
> son siete y `594–599` son seis, o sea **trece**, y las dos familias suman **diecinueve** (7 de
> `competencias/` + 12 de `desempenos/`). Con 580 antes de ellas, los tramos buenos son **581–587**
> y **588–599**. El total —599— **estaba bien**; lo que estaba mal era el reparto, que es justo lo
> que pasa cuando un tramo se escribe de memoria y el total se cuenta. *(Visto el 13 sep por la
> noche construyendo la Fase 6.)*
Una familia nueva entra **entera en un commit** por lo mismo que el catálogo del MEN no
tiene familia propia: a trozos, el censo la recogería como «1 de 1» y la sacaría después —
dos movimientos de una instantánea publicada para nada. «El de rutas no se
mueve»
sigue siendo la
regla: una ruta nueva es una decisión, no un efecto secundario, y mueve este
documento y **tres** snapshots, no dos: `rutas.json`, `guards-por-ruta.json` y
`guard-por-familia.json`, que cuenta cuántas rutas tiene cada familia y cuántas
llevan guard.

> **Y a veces son CUATRO, cosa que este párrafo no decía y costó un rojo el 7 sep
> 2026.** `familias-que-nunca-entran-en-el-candado.json` **también se mueve**, y no
> sólo por una ruta pública —que es el único caso que menciona la sección de las
> públicas—: ese censo lista las familias con **menos de dos hermanas con guard**,
> que son las que el candado de consistencia por familia **descarta con un
> `continue` y no mira nunca**. Así que se mueve cuando una ruta **estrena
> familia** —entra como «1 de 1» si lleva guard— y cuando una familia que tenía una
> sola guardada pasa a dos, porque entonces **sale** del censo. No se mueve si la
> familia ya tenía dos o más.
>
> **«1 de 1» ahí no es un agujero y no hay que arreglarlo**: la ruta tiene su
> guard. Lo que dice el renglón es que **una familia de una sola ruta no puede ser
> protegida por el candado de familia**, porque ese candado compara hermanas y no
> hay ninguna con la que comparar. Lo que la protege es su propio guard y su test.
> El renglón que sí sería un agujero es **«0 de 1»**.)

> **Y esta cifra se cuenta, no se hereda.** El 1 sep 2026 este párrafo decía **544**
> con el router en **547**: las tres rutas del boletín independiente entraron con su
> decisión y su documento, y nadie movió el número de aquí. Iba en la dirección que
> no se nota —**hacia abajo**, o sea contando de menos—, así que no había ningún
> rojo que lo delatara: los tres snapshots sí se actualizaron, porque los mueve un
> test. **El número de este fichero no lo comprueba nadie**, y por eso se cuenta con
> `route:list` el día que se toca en vez de sumarle uno al que había.
>
> **Y la mitad que faltaba, del 5 sep 2026: `route:list` cuenta EL ÁRBOL EN EL QUE
> ESTÁS.** Ese día dio **568** en el árbol principal y **577** en `.worktrees/p`, y
> las dos eran ciertas: la plantilla de notas traía nueve rutas que aún no estaban en
> `main`. O sea que *«se cuenta, no se hereda»* estaba incompleto — **se cuenta y se
> dice desde dónde**. Con trece worktrees vivos, «el router está en N» sin decir el
> árbol no es un número: es dos números y un lector que no sabe cuál le tocó. Lo
> destapó fundir la plantilla, y en el mismo fichero: la rama escribió arriba el
> relato de sus nueve rutas y su **577** y dejó el contador de la primera línea en
> **568**, contradiciéndose dentro del mismo párrafo que explica cómo contarlo.
>
> **Y volvió a pasar el 5 sep 2026, en la misma dirección y por el mismo sitio.** La rama de
> la plantilla de notas escribió arriba el relato entero de sus nueve rutas y su **577**
> —contado, con su razón y con el precio delante— **y dejó el contador de la primera línea en
> 568**. O sea que el párrafo que explica el número y el número **se contradecían dentro del
> mismo fichero**, y de los dos el que se lee primero es el de arriba. No lo cazó ningún test
> —no hay ninguno que mire esta cifra—: lo cazó contar el router en el árbol principal después
> de fundir, que es lo que este párrafo manda hacer. *Un aviso que ya está escrito no protege
> solo; sólo protege el día que alguien hace lo que dice.*

Las tablas de `DESPLIEGUE.md` **no** se tocan al añadir una ruta: son lo que se midió
el día de un despliegue, y se remiden el día del siguiente. El plan, las mediciones y
las decisiones ya tomadas viven en `docs/migracion/` y **se leen antes de re-litigar
nada**.

> **Lo primero, antes que este fichero: `docs/migracion/ESTADO-ACTUAL.md`.** Dice
> qué se está haciendo ahora mismo, qué es lo siguiente y qué espera una decisión
> de Joseth. Existe para que una sesión nueva continúe **sin que nadie le dé
> contexto**, así que **se actualiza en el mismo commit que el trabajo** — un
> commit aparte al final es el que no se hace cuando la sesión se corta.

## Idioma y convenciones de escritura

El código nuevo, los comentarios, los mensajes de commit y la documentación van
**en español**, salvo los términos del framework, que se dejan en inglés
(*guard*, *seeder*, *snapshot*, *middleware*). Los comentarios explican **por
qué**, no qué: casi todo lo que hay escrito en este repo es el resultado de una
medición o de una decisión, y sin el porqué se deshace solo.

## Comandos

Todo corre dentro del contenedor (`kool` sobre docker compose):

```bash
docker exec 8myvc-app-1 php artisan test                       # 1.006 el 22 ago; 903 son de Contrato
docker exec 8myvc-app-1 php artisan test --testsuite=Contrato  # solo contrato (necesita BD)
docker exec 8myvc-app-1 php artisan test --filter=NotasTest    # una clase
```

> **Y CUÁNTO TARDA, porque la duración es el único delator de una suite que
> midió mal.** Escrito el 21 sep 2026 después de perder una suite entera entre
> dos sesiones:
>
> | orden | cuánto tarda, sana | en qué condiciones se midió |
> |---|---|---|
> | `php artisan test --testsuite=Contrato` | **840–1.050 s** (841, 930, 1.000, 1.044 — cuatro, la noche del 20 sep) | base propia, **contenedor compartido con otras sesiones trabajando** |
> | `php artisan test` (las tres testsuites) | *sin medir* | — |
> | `--filter=<una clase de contrato>` | **10–40 s** | lo mismo |
>
> **Y la duración se publica CON SU ORDEN Y CON SU CONDICIÓN, por lo mismo que el
> conteo.** «La suite» son dos órdenes distintas —`--testsuite=Contrato` y
> `php artisan test`, que corre las tres— y por tanto dos duraciones: quien corra
> la completa y vea 1.300 s creería que tiene contención sin tenerla. Es
> exactamente el caso de las cuentas de tests que está tres párrafos más abajo,
> aplicado al reloj en vez de al número.
>
> **La condición no es un adorno: las cuatro medidas de arriba se tomaron con
> otras sesiones activas en el contenedor.** Sin deadlock —cada una con su base—
> pero compitiendo por CPU, así que **son un techo y no una línea base**. *Una
> duración medida con otra suite encima no sirve para detectar que hay otra suite
> encima*, y por eso la fila de referencia hay que tomarla en un contenedor
> quieto el día que haya uno.
>
> **Son DOS enfermedades y el mismo delator, y la segunda no deja ningún rastro
> en la salida:**
>
> 1. **La suite MUERTA** —le podan el árbol debajo y se queda sin `vendor/`—
>    termina **sin línea `Tests:`**, así que a un `grep '⨯'` le salen **0 rojos**
>    y al proceso de fondo **exit 0**. Verde perfecto, y no midió nada. Se caza
>    porque *falta* la línea.
> 2. **La suite CONTAMINADA** —dos corriendo contra la misma base de tests— sí
>    tiene su línea `Tests:`, **en verde**, y lo único raro es que tardó de más:
>    unos tests filtrados que se despachan en 20 s tardaron **319 s** medidos ese
>    día. Ahí no falta nada en la salida: hay que saber cuánto debería haber
>    tardado, y por eso está la tabla de arriba.
>
> El deadlock de dos suites sobre una base **se lee como un fallo real en un test
> que no tiene nada que ver con lo que estás tocando**, y la reacción natural es
> ir a mirar ese test. La salida es `DB_TEST_DATABASE=simonbolivar_testing_<sufijo>`.
>
> > **Y PARAR UNA SUITE NO ES MATARLA, que es de donde sale la mitad de los casos
> > de arriba.** Matar el `docker exec` —o la tarea de fondo que lo lanzó— deja
> > vivo el `php` de dentro. Se comprueba, y el `kill` va **al phpunit Y a su
> > padre**:
> >
> > ```bash
> > docker exec 8myvc-app-1 sh -c "ps -eo args | grep [p]hpunit"   # ANTES de lanzar otra
> > ```
> >
> > **Las dos enfermedades juntas, vividas el 21 sep 2026 por quien acababa de
> > escribir este bloque:** una suite «parada» media hora antes seguía corriendo,
> > se lanzó otra encima contra su misma base, y el resultado fue **exit 0**,
> > **sin línea `Tests:`** y con **cinco rojos** repartidos por cuatro clases que
> > no tenían nada que ver con el cambio. *Mirando el exit code: verde. Mirando
> > los rojos: cuatro investigaciones falsas. La única señal correcta era la que
> > faltaba.*
> >
> > La comprobación va **antes de lanzar**, no cuando el resultado sale raro.
> > Después sirve para diagnosticar; antes es lo único que lo evita.
> >
> > **Y UNA TERCERA FORMA, ésta del andamio: la suite dura MÁS que el tope de
> > quien la lanza.** Si el `docker exec` se corta por timeout —15 minutos de
> > suite contra un tope de 10—, deja `context canceled` al final del fichero,
> > **sin línea `Tests:`**, con los rojos que hubiera hasta ahí… **y el `php`
> > sigue vivo dentro**, escribiendo a un pipe que ya no lee nadie. Leer «cero
> > rojos» de ahí es leer una corrida que ni terminó.
> >
> > Lo que lo evita es no atarla al pipe:
> >
> > ```bash
> > docker exec -d -w /app/.worktrees/<x> -e DB_TEST_DATABASE=simonbolivar_testing_<x> \
> >     8myvc-app-1 sh -c "php artisan test --testsuite=Contrato > /tmp/<x>.txt 2>&1"
> > ```
> >
> > Y se consulta ese fichero **dentro del contenedor**. Así el corte de quien la
> > lanzó no la toca.
> >
> > **Y el detalle que lo hace útil: le pasó a quien acababa de escribir este
> > bloque, con la regla ya en el fichero.** Eso no es un descuido, es la forma
> > normal en que fallan estas cosas — *un aviso escrito no protege solo; sólo
> > protege el día que alguien hace lo que dice*, que es lo que este fichero
> > lleva repitiendo con el contador de rutas y con las cifras de tests. Aquí
> > está la tercera, y costó cuatro investigaciones falsas que un `ps` habría
> > ahorrado.
>
> > **Y para una suite que TODAVÍA ESTÁ CORRIENDO, la duración no sirve: sirve la
> > CPU del HIJO.** La tabla de arriba se lee cuando ya terminó; esto se lee en
> > una sola muestra:
> >
> > ```bash
> > docker exec 8myvc-app-1 sh -c "ps ax -o pid,ppid,etime,time,stat,args | grep -E '[p]hpunit|[a]rtisan test'"
> > ```
> >
> > **Se mira el `phpunit`, NUNCA el `artisan test` que lo lanzó.** El padre
> > arranca al hijo y se queda esperando, así que sale con **2 segundos de CPU en
> > dieciocho minutos** y parece muerto — el 21 sep 2026 una sesión estuvo a punto
> > de matar la corrida de otra por eso. Aplicada al padre, esta regla marca como
> > envenenado a **todo padre sano**.
> >
> > | CPU ÷ reloj, en el hijo | estado | qué es |
> > |---|---|---|
> > | **~0 %** | `S` | **atascado** esperando un lock o una base ocupada |
> > | **30–35 %** | `R` | **trabajando con normalidad** |
> >
> > **Y ahí se acaba lo que esta ratio sabe: NO dice si hay contención.** Se
> > escribió creyendo que sí —«31% es que somos cinco peleándonos»— y es falso,
> > medido el 21 sep: el contenedor tiene **10 núcleos y ninguna cuota**
> > (`nproc` 10, `cpu.max` = `max 100000`) y la carga estaba en **210% de 1000%**,
> > con cinco suites a la vez. **Con diez núcleos y cinco procesos de un solo hilo
> > no puede haber contención de CPU**: a cada uno le sobra un núcleo. Ese 30–35%
> > es lo que da esta suite **aunque corra sola**, porque se pasa el tiempo
> > esperando a MySQL —se la pilló dormida en `folio_wait_bit_common`—, no
> > calculando.
> >
> > **Consecuencia para la línea base, que es lo que casi se escribe mal:** un
> > umbral de *«~100% en `R` → el contenedor es suyo»* **no llega nunca**, así que
> > la fila que lo esperase quedaría abierta para siempre. La condición que sí es
> > comprobable de un vistazo es **que no haya otra**:
> >
> > ```bash
> > docker exec 8myvc-app-1 sh -c "ps -eo args | grep [p]hpunit"   # una sola línea
> > ```
> >
> > > **Y para mirar la carga, `/proc` y no `docker stats`.** `docker stats
> > > --no-stream` **no es una medición aquí: es una muestra**, y en este
> > > contenedor salta. Medido el 21 sep entre dos sesiones: el `app` dio **982%**
> > > y después 374 / 298 / 261; la base dio **11,59%** y después 161 / 173 / 191
> > > / 236 / 242. Con la primera de cada par se escribió *«el contenedor está
> > > saturado»* y *«la base no hace nada»*, y las dos eran falsas.
> > >
> > > Lo que aguanta es `/proc` —CPU acumulada, `stat`, `wchan`— porque son
> > > **contadores y no instantáneas**: se leen una vez y describen toda la vida
> > > del proceso. Si hace falta `docker stats`, **cinco muestras y el rango**,
> > > nunca una.

> **Y una cifra de pruebas se publica CON LA ORDEN QUE LA PRODUJO, nunca con la
> palabra «suite».** Costó una fusión parada el 7 sep 2026: una sesión citó
> **2.078** y otra **1.948** sobre el mismo trabajo, las dos correctas y las dos
> dichas como *«la suite»*. La primera era `php artisan test` —las tres
> testsuites— y la segunda `--testsuite=Contrato`. En ese árbol, Contrato eran
> **1.948**, Unit **134** y Feature **9**, y la resta cuadró al caso.
>
> Lo que hace falta escribir es `Tests: 1948 (--testsuite=Contrato)`: así no se
> confunden nunca. Es lo mismo que exigir el hash y la hora para una medición, y
> por la misma razón — **el número no lleva dentro de qué habla**.
>
> **Y lo incómodo es que estas dos líneas de aquí arriba YA distinguían las dos
> poblaciones** —«1.006 el 22 ago; 903 son de Contrato»— y aun así las dos sesiones
> citaron «la suite». *Un aviso que ya está escrito no protege solo; sólo protege el
> día que alguien hace lo que dice* — la misma frase que el recuadro del contador de
> rutas, y volvió a pasar con otra cifra.

```bash
# Qué alcanza de verdad un token. No corre con los demás: mide e imprime.
docker exec -e BARRIDO_TIPO=Alumno 8myvc-app-1 php artisan test --group=barrido
docker exec 8myvc-app-1 composer run pint                      # formato
docker exec 8myvc-app-1 composer run stan                      # larastan nivel 7

tools/construir-bd-test.sh                                     # crea/reconstruye la BD de tests

# Varias sesiones a la vez: un árbol y una base por sesión. Monta las dos, y
# comprueba el aislamiento imprimiendo desde dónde carga las clases.
tools/worktree-de-sesion.sh b fix/lo-que-toque
docker exec -w /app/.worktrees/b -e DB_TEST_DATABASE=simonbolivar_testing_b \
    8myvc-app-1 php artisan test

# Solo la base, si de verdad se comparte el árbol (dos suites contra la misma
# base dan deadlocks). El sufijo es libre mientras lleve _testing dentro.
DB_TEST_DATABASE=simonbolivar_testing_b tools/construir-bd-test.sh
```

> **`vendor/` no se enlaza con symlink en un worktree**, aunque sea lo que hace
> el despliegue: `__DIR__` resuelve los symlinks y el árbol acaba cargando el
> `app/` del principal —con los tests en verde—. Lo copia con enlaces duros
> `tools/worktree-de-sesion.sh`, que lleva la medición en la cabecera. El reparto
> y las reglas de una noche en paralelo están en
> `docs/migracion/15-la-noche-en-paralelo.md`.

La base de tests **no** se reconstruye entre tests: cada uno corre dentro de una
transacción. Solo hace falta reconstruirla al cambiar el esquema o el seed.

> Si alguna vez corres `php artisan config:cache` en local, bórralo antes de los
> tests (`config:clear`): el config cacheado congela el `.env` y `phpunit.xml`
> deja de poder apuntar a la base de tests.

### Herramientas de medición (`tools/`)

Ninguna se ejecuta sola; todas contestan una pregunta que no se puede contestar
leyendo el código. Cada una lleva su uso en la cabecera.

> **Ninguna imprime `OK` sin decir su población.** Un «0 encontrados» no
> distingue *«revisé 466 y ninguno lo era»* de *«no revisé nada»*, y de las dos
> lecturas la falsa es la que hace archivar el asunto. Pasó dos veces el 24 ago
> 2026 en direcciones opuestas: una herramienta contaba duplicados **vivos**
> cuando el índice que iba a rechazarlos mira la tabla entera, y un detector del
> front afirmaba cubrir **385** llamadas cuando había **411**. La segunda mitad de
> la regla es la que muerde: **el primer sitio donde mirar cuando el número sale
> raro es el detector**, no el código.
>
> Y una segunda forma, que no se arregla repitiendo la medición: **un detector
> puede contar bien un síntoma y no estar contando la causa.** El barrido de la
> [§142](noche-2026-08-23/r.md) dio **nueve** sitios y los nueve eran ciertos —
> pero se leyeron como «nueve sin guarda» y **ocho la tenían**. Repetirlo da nueve
> otra vez. Ahí lo que hay que comprobar es que **el detector detecta lo que dice
> su nombre**.
>
> Y una tercera, que es de otra especie y por eso no la caza mirar el detector:
> **el detector corre, contesta lo que le preguntaron y contesta bien — y quien
> pregunta mal es uno.** El 5 sep 2026 se comprobó si una suite había cubierto un
> commit con `git merge-base --is-ancestor <commit> <la fusión que registra el
> resultado>`. La orden contestó que sí, correctamente, y la conclusión era falsa:
> la suite había corrido **cuatro minutos antes** de que ese commit entrara. **Ser
> ancestro es una relación del grafo; estar dentro del árbol cuando se midió es una
> relación con el reloj**, y la segunda no se deduce de la primera — menos aún
> preguntándosela al commit que por construcción se escribe *después* de medir. De
> ahí sale la regla que evita la familia entera: **una medición se anota con el hash
> exacto contra el que corrió y su hora, no con el nombre de una rama.** *«Sobre
> `main`»* no es reproducible con cuatro sesiones moviendo `main`.

> **Antes de pasarle Pint a un fichero de `tools/`: correr `stan` detrás.** `tools/`
> **no está en el script `pint` de `composer.json`** —nunca se ha formateado— y
> **ninguna suite lo ejecuta**, así que aquí Pint puede romper en tiempo de ejecución
> sin poner nada en rojo. Medido el 2 sep 2026 copiando los ficheros y pintando las
> copias: `fully_qualified_strict_types` acorta
> `$app->make(Illuminate\Contracts\Console\Kernel::class)` a `Kernel::class` y deja el
> `use` **debajo** de esa línea; PHP registra los `use` según los va compilando, así que
> el de abajo no cuenta y sale `Target class [Kernel] does not exist` **antes de la
> primera consulta**. Pasa en **ocho de los doce** que arrancan Laravel así (los cuatro
> que se salvan ya tienen el `use` arriba). Lo cazó larastan —`class.notFound`—, que es
> el único que mira esta carpeta.

| Herramienta | Contesta |
|---|---|
| `cobertura-de-rutas.py` | qué rutas tienen la respuesta comprobada por algún test |
| `indices-que-faltan.php` | qué consultas recorren una tabla sin índice aplicable |
| `consultas-lentas.py` | qué consultas se llevan el tiempo en producción |
| `columnas-en-los-modelos.php` | reescribe las `@property` de los modelos desde el esquema real |
| `route-inventory.php` · `route-match-check.php` | la tabla de rutas, comparable 1:1 |
| `inventario-autorizacion.py` · `auditar-autenticacion.php` | qué guard cubre cada ruta |
| `respuestas-que-mienten.py` | qué métodos frenan la escritura y responden 200 igual |
| `interruptores-que-nadie-lee.py` | qué columnas `tinyint(1)` no decide nadie — con `--clientes`, tampoco los cuatro fronts |
| `identificadores-del-cuerpo.py` | qué rutas reciben un id por el cuerpo que no comprueba nadie |
| `escrituras-en-las-notas.py` | qué métodos escriben en las notas sin preguntar por el interruptor del periodo |
| `coste-del-recalculo.php` | qué cuesta recalcular una definitiva, sobre las asignaturas reales |
| `secciones-citadas.py` | qué §§ cita el código y ya no existen en `docs/` — se corre **después de cada renumerado** |
| `consultas-en-bucle.py` | en qué profundidad de bucle vive cada consulta — **ordena candidatos, no mide coste**; trae su propio control (`--control`) |
| `guardas-sin-respaldo.py` | qué métodos dependen enteros del middleware de su ruta — **ordena candidatos, y se equivocó en las dos direcciones**: cada fila se lee |
| `verdad-laxa-que-escribe.py` | dónde una cadena cualquiera del cliente vale por «sí» **y gobierna una escritura** — 21 de 980 `if`, tres con consecuencia |
| `prevuelo-del-horario.php` | si los datos de un colegio sirven para cuadrar un horario — **la rejilla es un parámetro** (`--lecciones`), y con la de 6×5 que supuso la v1 el docente de 31 h era imposible |
| `deriva-del-horario.php` | si las siete columnas de día siguen cuadrando con la versión oficial — **sin versión publicada sale `2`, NO MEDIDO**, porque ahí un `0` diría lo mismo que un año perfecto |
| `ensayo-de-la-tanda.sh` | si la tanda de migraciones corre entera sobre una copia de un colegio de verdad y cuánto tarda — y **audita la comprobación de `DESPLIEGUE.md`**, que la saca del documento con `grep` en vez de copiarla |
| `comprobar-el-horario.php` | si el módulo de horario **llegó** a un colegio: `200` con `total: 0` no es lo mismo que `404` ni que `500`, y desde la pantalla los tres son una rejilla vacía |
| `imports-de-facades.php` | qué `use` resuelven por el array `aliases` en vez de por el nombre completo — **`--dry-run` NO es opcional (sin él ESCRIBE) y NO mira el orden: detrás va `pint:test`** |
| `requisitos-de-matricula.php` | cómo usa un colegio **de verdad** los requisitos: cuántos pasos, en qué orden, con qué dueño y cuántos se cierran — **imprime el nombre de la base en cada bloque**, porque en desarrollo sale 1 paso y 0 cerrados y eso contesta bien a otra pregunta |
| `lo-que-reparte-una-columna.py` | qué instantáneas se mueven el día que una tabla gane una columna — **cobertura, no exposición**: son los ficheros que hay que regenerar, no las respuestas que ganan la columna |
| `ensayo-del-alter-en-maria.sh` | si el `ALTER` de la casilla vacía bloquea el guardado de notas en **MariaDB**, que es lo que corre producción — **la señal no es que la escritura falle, es la LATENCIA**, así que trae su propio control que sí bloquea (`COPY, LOCK=SHARED`) |
| `correo-de-los-colegios.sh` | qué instalaciones no pueden mandar correo, leído de su `.env` — **la caché de configuración manda sobre el fichero**, y la instalación viva de `lal` queda fuera del bucle: sale `2`, nunca verde |

Y una que **no** está en `tools/` y contesta la pregunta contraria:
`tests/Barrido/SuperficieDeUnTokenTest.php` golpea la API entera con un token y
mira **el resultado** —qué datos personales salen y qué filas se escriben de
verdad— en vez de la petición. Vive en `tests/` porque barrer las escrituras es
ejecutarlas, y la transacción de cada test es lo único que hace eso inocuo. De
ahí salieron las §14 y §15 de `docs/migracion/05-codigo-muerto-y-roto.md`.

## Arquitectura

**No es una aplicación Laravel idiomática, y tratarla como si lo fuera rompe
cosas.** Hay 990 consultas crudas (`DB::select/insert/update`), los modelos
Eloquent se usan marginalmente y hay 2 validaciones en todo el proyecto. El
framework casi no se toca, que es justo lo que hizo viable la migración.

### El objeto `$this->user` NO es un modelo

`User::fromToken()` devuelve un **`stdClass`**: persona + grupo + año + periodo +
configuración del colegio + roles + permisos, aplanado en un objeto con ~40
columnas de un `switch` de cuatro ramas (Profesor / Alumno / Acudiente /
Usuario). Lo monta `App\Services\ContextoDeUsuario`; el token lo valida
`App\Services\Sesion`, y ninguno sabe del otro.

- En los controladores llega por el trait `Concerns\ResuelveElUsuario`, que lo
  resuelve **en la primera lectura**, no en el constructor. Un constructor que
  resuelva al usuario rompe `route:list` y `route:cache` — hay un test que lo
  impide.
- `$user->user_id` es el id de `users`; `$user->persona_id` es el de la ficha.
  No son lo mismo.

### Rutas y autorización

`routes/api/*.php`, un fichero por dominio. **El guard va por defecto a toda la
API** y las excepciones públicas se marcan una a una — son
**`RutasPreLoginTest::TOTAL_PUBLICAS`, hoy dieciséis**, y son un test que las ata por las
dos direcciones: que la lista no tenga de más y que el router no tenga de menos.

> **La decimosexta entró el 20 sep 2026 y es la PRIMERA DE LECTURA del formulario de
> inscripción**, que es lo interesante: hasta ella, las tres públicas de ese módulo eran **las tres
> de ESCRITURA**, así que la familia mandaba su comprobante y **no tenía forma de saber si se lo
> aprobaron, se lo rechazaron ni por qué**. `GET colillas-inscripcion/{codigo}`.
>
> **Y lo que la hace aceptable no es su limitador: es lo que NO devuelve.** La llave es un código
> que se dicta por teléfono y viaja en un papel que pasa de mano en mano, así que la pregunta de
> cada campo no fue *«¿le sirve a la familia?»* sino *«¿qué pasa si esto lo lee quien se encontró
> el papel?»* — no salen el nombre del alumno, su documento, sus teléfonos ni el fichero del
> recibo. **Un código no puede revelar el nombre de un menor.** Eso **no lo puede ver ningún
> candado de autorización**, así que lo fija un test que busca el dato en el JSON entero, no campo
> a campo: *un campo de más en una respuesta pública no rompe nada, no pone nada en rojo y no se
> nota hasta que importa.*

Las tres anteriores son del mismo formulario y entraron el 19 sep 2026 —la colilla del
pago y las dos de la pasarela—, y las tres por el mismo motivo: **quien paga es la familia de un
aspirante que todavía no es alumno, no tiene cuenta y no puede tenerla**; la del webhook ni
siquiera la llama una persona. La duodécima entró el 1 sep 2026 y es la única que no va del login ni del logout:
`GET colegio/logo`, para que la puerta de entrada del colegio pueda enseñar el logo
que se cambió dentro. La exposición se midió antes de proponerla y está en la §245
del 05 — el fichero ya se descargaba sin sesión.

> **Y una pública mueve dos sitios más que una normal —y TRES cuando además escribe**, que es
> lo que nadie previó el día que entró la duodécima y cantó la suite entera: `AutenticacionTest::SIN_GUARD`
> —la lista de las que no exigen token, **con el motivo al lado**— y el censo
> `familias-que-nunca-entran-en-el-candado.json`, donde una familia nueva de una sola
> ruta sin guard entra como «0 de 1». Ese renglón **es la forma que tendría un agujero
> nuevo**, así que se acepta escribiendo por qué no lo es, nunca regenerando y pasando.
> En cambio `guards-por-ruta.json` **no** se mueve: lista las que llevan guard.
>
> **El tercero, medido el 19 sep 2026 con las dos de `pagos-inscripcion/`:**
> `FamiliasQueNuncaEntranTest::test_cuantas_escrituras_viven_donde_el_candado_no_llega` y su
> instantánea, que cuentan las **escrituras** que viven en familias que el candado de familia no
> mira nunca — de **22 a 24**, y a **26** con las dos del calendario. Sólo se mueve si la ruta
> nueva **escribe** y su familia tiene menos
> de dos hermanas con guard: la colilla no lo movió porque sus tres hermanas sí lo llevan. O sea
> que *«una pública mueve cinco sitios»* vale mientras la familia esté guardada por otro lado, y
> **una familia nueva entera de públicas que escriben mueve seis**.

> **Ese número no se cuenta con un `grep`, y aquí está el porqué porque ya costó
> tres cifras.** Hay **21** rutas `api/` sin `auth.token` —remedidas el 19 sep 2026 en el árbol
> principal sobre `main` (`982a8cb`), con `route:list --json`; eran 18 antes de la colilla y las
> dos de la pasarela— y **seis contestan 401 igual** porque se defienden dentro del método:
> **quitarle el guard a una ruta no la hace pública**. 21 − 6 = **15**, que es `TOTAL_PUBLICAS`
> y cuadra — pero *cuadrar no es de dónde sale*: el quince lo da el test, y esta resta sólo
> sirve para comprobarlo después. (Eran 19 y siete hasta el 2 sep 2026, cuando
> Joseth mandó retirar `tardanzas/login/traer-datos`: **las dos bajaron y la docena
> de públicas no se movió**, que es lo que demuestra que aquella contestaba 401.) Ese número —doce
> entonces, quince hoy— es del **resultado** —quién recibe datos sin
> presentar token—, no del mecanismo, y por eso el día que entró la duodécima se
> **corrió el test** en vez de restar 19 − 7.
>
> Este fichero decía **quince**, el docblock del test decía **siete** y `grep` daba
> **diecinueve** (una era un comentario; hoy da diecinueve otra vez: subió a veinte con
> la duodécima pública y bajó con la de tardanzas).
> Ninguno era una cifra que hubiera envejecido: **los tres nacieron mal**, y se
> demuestra con que las 18 sin guard de aquel día **eran exactamente las mismas
> cinco días después** — el código no se movió. El
> «siete» se escribió cuando el modelo era el contrario (el guard se ponía ruta a
> ruta y `withoutMiddleware` no existía), así que **la pregunta no tenía todavía un
> conjunto que contar**. Medido y desglosado commit a commit en
> [`noche-2026-08-25/pub-1.md`](docs/migracion/noche-2026-08-25/pub-1.md).

Middlewares propios en `app/Http/Middleware/`:

| Guard | Qué exige |
|---|---|
| `auth.token` | sesión válida |
| `auth.personal` | que sea personal del colegio, no alumno ni acudiente |
| `boletin.propio` | que el boletín pedido sea suyo o de un acudido |
| `persona.propia` | que el id del cuerpo o de la URL sea suyo |

La regla de negocio, confirmada y no re-litigable: **un alumno solo ve lo suyo;
un acudiente, lo suyo y lo completo de sus acudidos.** Los métodos conservan sus
nombres viejos (`getIndex`, `putGuardarValor`): renombrarlos es cosmético y va
después.

**En código nuevo se usan los códigos HTTP correctos** —403, 404, 422, 429—
aunque el legacy de al lado devuelva 400 para todo.

### El esquema vive en un volcado, no en migraciones

`database/schema/mysql-schema.sql` es la verdad: 90 tablas congeladas desde
producción. Las 3 migraciones viejas están archivadas en `legacy/`.

- **Ningún cambio de esquema a mano en phpMyAdmin: migración o no existe.**
- Las columnas de los modelos se generan desde ese volcado
  (`tools/columnas-en-los-modelos.php`), no se escriben a mano.
- `migrate:fresh` no sirve para nada aquí: un colegio nuevo se crea **copiando la
  base de otro**, porque necesita datos básicos dentro.

### Tests de contrato

No comprueban que el código esté bien: comprueban que **la respuesta no ha
cambiado**. `tests/Contrato/` con snapshots en `Snapshots/`. Lo que los hace
encontrar cosas es **mirar el resultado y no el estado**: el píxel en vez del
200, la forma de la hoja de Excel en vez de los bytes, el viaje de ida y vuelta
en vez de una llamada. Ese criterio ha encontrado todo lo que se ha encontrado.

Cómo se usan, cómo se regenera el seed y qué no cubre: `docs/migracion/03-tests.md`.

> **Y un candado que mintió durante meses, corregido el 20 sep 2026: el orden de
> registro de las rutas.** Laravel sirve **la primera que casa**, así que un comodín
> declarado antes que una ruta literal se la traga —`…/{lote}` comiéndose `…/campos`,
> `…/{codigo}` comiéndose `…/pendientes`—, y eso **no cambia el conjunto de rutas ni
> la acción declarada de ninguna**: las dos siguen ahí. Sólo cambia cuál gana.
>
> `RutasTest` decía en su docblock que cubría exactamente esto —*«reordenar puede
> tapar `puestos/detailed` con `puestos/{id}`… lo que se guarda aquí es, para cada URI
> literal, QUÉ acción la atiende»*— **y no lo cubría**: la instantánea guarda la acción
> **declarada**, y reordenar deja `rutas.json` byte a byte igual. Comprobado
> reproduciendo los dos casos reales: el test viejo **se queda verde** en los dos.
>
> Los dos pasaron con ese test en verde y se cazaron **a mano, uno por uno**. Ahora los
> caza `test_ninguna_ruta_literal_la_atiende_un_comodin`, que no lee lo declarado: **le
> pregunta al router** con `getRoutes()->match()`, que es lo que hará el servidor.
>
> **La lección no es del router, es del candado**: *un detector puede contar bien un
> síntoma sin estar contando la causa* — y éste llevaba **el nombre de la causa escrito
> en el docblock**, que es justo lo que hizo que nadie fuera a mirar. Cuando un test
> dice que protege algo, la forma de saberlo es **romper ese algo y verlo en rojo**, no
> leer su docblock.
>
> Y `route:list` tampoco lo delata: **ordena alfabéticamente y no por orden de
> registro**, así que la forma natural de comprobarlo miente.

### Calidad

- **Pint** solo sobre lo que escribió la migración (ver `composer.json`).
  Reformatear los 113 de golpe sería un diff ilegible; se formatea el día
  que se toca cada fichero.

  > **Y son TRES comandos parecidos que miden y hacen cosas distintas. Costó tres
  > diagnósticos equivocados entre dos sesiones el 13 sep 2026**, así que va aquí:
  >
  > | | |
  > |---|---|
  > | `composer run pint:test` | **comprueba** y no toca nada — **es el que dice si el repo está verde** |
  > | `composer run pint` | **ESCRIBE**: formatea los ficheros de la lista |
  > | `pint --test` a secas | mide **todo el repo**, incluido lo que no se formatea a propósito |
  >
  > Los dos primeros corren sobre **la lista curada de `composer.json`** —hoy **454**
  > ficheros—; el tercero sobre **695**, y da `FAIL` con **182** avisos.
  >
  > > *Remedidas las tres el **20 sep 2026 en `.worktrees/fi`**, sobre `main` en `fd0bc44` más
  > > las cuatro rutas de «del papel al alumno»: **454** (`PASS`), **695** y **182**. Decían
  > > 437, 682 y 185 la noche del 19. **No hubo que tocar `composer.json`**: los dos ficheros
  > > que entraron —la migración y el test— caen bajo `database/migrations` y `tests`, que ya
  > > van como directorios enteros, así que **el número subió solo**. Y la tercera **volvió a
  > > bajar** —185 a 182—, que es otra vez la dirección que nadie supondría sumando: un fichero
  > > que entra en la lista curada **sale** de la cuenta de avisos al formatearse. Larastan
  > > analiza **680** en este árbol.* **Las dos cifras
  > son correctas y cuentan poblaciones distintas**: con la tercera se archivó como ruido
  > un rojo real que llevaba desde el 7 sep en `CorsDelEscritorioTest`.
  >
  > > > **Y las tres se remidieron OTRA VEZ al fundir, esa misma noche: 437, 682 y 185.**
  > > > Las de `.worktrees/92` —430, 679, 189— y las de la otra tanda —423, 662, 189— eran
  > > > **ciertas las seis**, y ninguna describía el árbol fundido: dos tandas en paralelo
  > > > metieron ficheros en la misma lista sin verse. **437 no es 430 + 7 ni 423 + 14**: es
  > > > lo que contestó `composer run pint:test` sobre el árbol fundido — y hubo que
> > > medirlo DOS veces, porque entre la primera y la segunda entraron tres commits más
> > > en `main` y pasó de 436 a 437. Y la tercera cifra
  > > > **bajó** —189 a 185—, que es la dirección que nadie habría supuesto sumando: un
  > > > fichero que entra en la lista curada **sale** de la cuenta de avisos al formatearse.
  > > > *Sumar dos cifras ciertas da una falsa; contar da la buena.*
  > >
  > > *Remedidas las tres el 19 sep 2026 por la noche en `.worktrees/92`: **430** la lista
  > > curada (`PASS`), **679** el repo entero y **189** avisos, que es el único de los tres
  > > que no se movió. Decían 423, 662 y 189 esa misma mañana. **Que la primera suba es lo
  > > normal** —a `composer.json` se le añade cada fichero el día que se formatea— y que la
  > > segunda suba también: cualquiera que añada un fichero la mueve.*
  >
  > > **Remedidas el 18 sep 2026 en el árbol principal sobre `main` (`83cbcd9`), y las
  > > tres habían envejecido**: decían **398**, **647** y **190**. Ninguna es un error de
  > > quien las escribió —la lista curada crece sola, porque a `composer.json` se le añade
  > > cada fichero el día que se formatea— y por eso se remiden en vez de discutirse. Se
  > > rehacen con las tres órdenes de la tabla de arriba, que son las que las produjeron.
  > >
  > > *Y subía otra vez esa misma tarde, de **413** a **415**: las dos familias nuevas de
  > > `informes-recientes` y `accesos-favoritos` metieron sus dos controladores en la lista.
  > > Se apuntan aquí **en el mismo commit que los escribe**, que es la única forma de que
  > > este número no vuelva a llevar meses de retraso. Y **417** un rato después, con los dos
  > > ficheros de prueba de esas mismas familias —`tests/` ya estaba en la lista, así que ahí
  > > no hubo nada que añadir: el número sube solo. **Que se mueva tres veces en una tarde no
  > > es que esté mal medido; es lo que hace esta cifra**, y por eso lleva al lado la orden que
  > > la rehace en vez de una fecha de caducidad.*
  > >
  > > *Y **419** el 19 sep 2026, con `app/Models/Profesor.php` y
  > > `app/Http/Controllers/Piars/PiarsAsignaturasController.php`, que los formateó el día que
  > > los tocó el cambio de `materia_id`/`grado_id`. Lo decidió Joseth con el precio delante,
  > > que es la única forma en que este número debería moverse.*
  > >
  > > > **Y el precio que se le puso delante ERA FALSO, así que aquí queda la orden que lo
  > > > mide bien.** Esta nota dijo que `Profesor.php` *«lo tocan cuatro ramas vivas y el
  > > > reformateo les deja conflicto a las cuatro»*. **No lo toca ninguna.** Salió de contar
  > > > con `git diff --name-only main..<rama>`, que mezcla dos cosas distintas: *«la rama
  > > > cambió este fichero»* y **«la rama va por detrás y fue `main` quien lo cambió»**. Con
  > > > diecisiete worktrees vivos, lo segundo es casi siempre. Lo que contesta la pregunta
  > > > que se quería hacer es el **merge-base**:
  > > >
  > > > ```bash
  > > > for b in $(git for-each-ref --format='%(refname:short)' refs/heads/); do
  > > >   base=$(git merge-base origin/main "$b") || continue
  > > >   git diff --name-only "$base".."$b" -- <el fichero> | grep -q . && echo "$b"
  > > > done
  > > > ```
  > > >
  > > > **La decisión no cambia** —formatear el fichero el día que se toca— pero el coste que
  > > > se usó para tomarla era inventado, y eso se dice. Es la trampa nº 3 de
  > > > `ESTADO-ACTUAL.md`, la de `--no-merged`, cometida con otra orden.*
  > >
  > > *Y **423** un rato después, el mismo 19 sep, con el candado de la plantilla:
  > > `CandadoDeLaPlantilla` y su test entran solos —`app/Support` y `tests` ya estaban en la
  > > lista— y `UnidadesController` y `SubunidadesController` se apuntan a mano, formateados el
  > > día que se tocaron.*
  > >
  > > > **Y este 423 es la prueba de que la regla de arriba sirve para algo.** Las dos ramas
  > > > que lo movieron se escribieron a la vez y cada una contó desde su propio árbol: una dijo
  > > > **419** y la otra **421**, **las dos ciertas y ninguna describiendo el `main` que iba a
  > > > quedar**. Al fundir la segunda no se sumó —419 + 2 daría 421, que es lo que decía la
  > > > otra rama y habría cuadrado de mentira—: se volvió a correr `composer run pint:test`
  > > > sobre el árbol fundido y dio **423**. *Dos cifras ciertas se contradicen en cuanto
  > > > salen de su árbol; la única que vale es la que se cuenta después de fundir.*
  > >
  > > **Y el 647 tiene una trampa que conviene dejar dicha: hoy es el número de LARASTAN,
  > > no el de Pint.** `composer run stan` analiza **647** ficheros —**651 el 19 sep 2026 sobre
  > > `main` en el árbol principal**, o sea que se movió cuatro en un día: esta cifra la sube
  > > cualquiera que añada un fichero, igual que la de Pint; y **664** esa misma noche en
  > > `.worktrees/92`, con la pasarela dentro— y `pint --test` mide **662**, **679** hoy. Que la cifra vieja de Pint coincida exactamente con la de stan puede ser
  > > casualidad o puede ser que se copiara de la salida equivocada —**no se puede saber
  > > desde aquí**, y da igual: son dos poblaciones distintas y a partir de ahora van las
  > > dos escritas, que es lo que impide volver a confundirlas.
  >
  > **Y los 189 avisos NO son «deuda de `tools/`», que es lo que decía este párrafo hasta
  > el 18 sep 2026.** Repartidos, son **`app/Http` 106** y **`app/Models` 48** —o sea
  > **163 de 189, el 86%**—, y sólo después `tools/` 17, `app/Exports` 6, `config/` 5 y
  > siete sueltos. Nombrar la cola y callar el grueso hacía leer ese `FAIL` como un rincón
  > raro, cuando es exactamente **la regla de dos líneas más arriba funcionando**: los
  > controladores y los modelos no se formatean hasta el día que se tocan. *Un desglose no
  > es un adorno del número: aquí es lo único que distingue «deuda conocida» de «algo se
  > rompió».*
  >
  > ### ⚠️ PINT DEJA `use Log;` EN LOS CONTROLADORES VIEJOS, Y ESO PONE LA SUITE EN ROJO
  >
  > **Visto TRES veces, en tres ficheros y por tres sesiones distintas**, así que ya no es el
  > descuido de nadie: es lo que hace Pint con estos ficheros.
  >
  > ```
  > 19 sep 2026   UnidadesController   el Pint de la P6            3ce3056
  > 19 sep 2026   ImporterFixer        el mismo día, otro fichero  e4686ba
  > 20 sep 2026   AlumnosController    el Pint de los perfiles     2503b27
  > ```
  >
  > Lo que pasa es esto: el fichero viejo usa `Log::info(...)` sin importar nada —resolvía por el
  > array `aliases` de `config/app.php`—, Pint ordena los `use` y **añade `use Log;`**, que sigue
  > resolviendo por el alias en vez de por el nombre completo. **No rompe en ejecución**, así que
  > el fichero funciona; lo que se pone rojo es `AliasDeFacadesTest`, y **en la testsuite `Unit`**,
  > que es justo la que no corre quien publica con `--testsuite=Contrato`.
  >
  > **Después de pintar un controlador viejo, una orden:**
  >
  > ```bash
  > php tools/imports-de-facades.php --dry-run      # dice qué resolvería por el alias
  > php tools/imports-de-facades.php                # ⚠️ SIN EL FLAG, ESCRIBE
  > ```
  >
  > **`--dry-run` no es opcional y el nombre de la herramienta no lo sugiere**: sin él no es un
  > informe, es una reparación, y en un árbol compartido eso le deja a otro un fichero cambiado que
  > no tocó. Lo descubrió `8myvc-9a` el 20 sep corriéndola para *ver el alcance* y encontrándose el
  > fichero ya arreglado.
  >
  > ### Y **NO SUSTITUYE A `pint:test`**: arregla el alias y NO MIRA EL ORDEN
  >
  > La herramienta cambia `use Log;` por el nombre completo **en el mismo sitio donde estaba**. Pint
  > ordena los imports alfabéticamente, así que el fichero queda **con el alias resuelto y el `use`
  > en el sitio equivocado** — y entonces:
  >
  > ```
  > imports-de-facades.php --dry-run   ->  0 imports en 0 ficheros, de 698     ✅
  > composer run pint:test             ->  FAIL, 469 ficheros, 1 rojo          ❌
  > ```
  >
  > **Las dos son ciertas y ninguna cubre a la otra.** Pasó el 20 sep 2026: el arreglo del `use Log;`
  > dejó Pint en rojo para las cuatro sesiones, y **este `0 de 698` se publicó como prueba de que
  > todo estaba bien**. Lo cazó `8myvc-9a` corriendo `pint:test`.
  >
  > Es la forma del día otra vez: *un instrumento que contesta bien a lo que le preguntas y no a lo
  > que necesitas saber*. **Después de esta herramienta, `pint:test`. Siempre.**

  > ### Y LA FAMILIA ENTERA: UN TEST QUE LEE UN FICHERO COMO FUENTE SE ROMPE AL FORMATEARLO
  >
  > El caso de arriba es uno de dos vistos el mismo día. El otro:
  > **`PoblacionDePerfilesTest` buscaba `/\n\tpublic function/` — con un TABULADOR**, así que
  > pintar `PerfilesController` lo puso rojo diciendo que habían cambiado los métodos que nombran
  > grupos. **No cambió ninguno**: cambió la indentación.
  >
  > *Un test que mira el código como texto mide el formato aunque crea que mide el código.*
  >
  > **La orden, que es lo único que hay que recordar de esto** — antes de pintar un fichero,
  > mirar quién lo lee como fuente:
  >
  > ```bash
  > grep -rl "<NombreDelFichero>" tests/
  > ```

  > **La confusión cara es la segunda**, no la tercera: quien corre `composer run pint`
  > *para comprobar* **reformatea ficheros sin pedirlo**. En un árbol que comparten
  > varias sesiones eso no rompe nada por sí solo —formatear no estaña— pero **le deja a
  > otro cambios que no hizo**, y el siguiente que commitee ahí se los lleva.
- **Larastan nivel 7**, y no baja. El **6 se salta a propósito**: sus 1.940
  errores son anotación pura, ninguno señala código que pueda fallar y el 68% cae
  en los controladores — se paga fichero a fichero, como el formato. El porqué
  está medido en `docs/migracion/12-larastan-nivel-7.md` y resumido en el propio
  `phpstan.neon`. Lo que no se puede arreglar va anotado en
  `phpstan.neon` **con nombre, motivo y `count`** — nunca en un baseline
  generado, que los escondería.
- **Rector** está configurado y sin correr: por carpeta y revisando cada diff.

**La regla para el código roto: sin ruta y roto se borra; con ruta y roto se
documenta.** Borrar un endpoint enrutado convierte un 500 en un 404 sin decirle
a nadie qué pretendía hacer esa pantalla. Los que están rotos a propósito
—porque arreglarlos exige una decisión del colegio— están en
`docs/migracion/05-codigo-muerto-y-roto.md` con su test fijando el error exacto.

## Despliegue: lo copiado y lo compartido van al revés de lo que parece

**Dieciséis colegios más `demo`**, cada uno con su subdominio, su base de datos y
su copia. (El número ha ido y vuelto: eran dieciséis hasta el 25 ago 2026, cuando uno
se dio de baja y se borró entero del servidor, y **volvieron a ser dieciséis el 30 ago**
con la entrada de `lal` — que nadie sumó aquí, así que este párrafo dijo «quince»
tres días de más. **Las cifras fechadas entre esos dos días dicen quince y así se
quedan**, porque se midieron sobre quince: lo que se actualiza es lo que sigue vivo.)

> **Y no se cuenta de memoria: se cuenta con el bucle.** Los dieciséis salieron de
> contar las carpetas que devuelve `/home/micolev1/*.micolevirtual.com/8myvc` el
> 2 sep 2026 — diecisiete, dieciséis colegios y `demo` (`docs/DESPLIEGUE.md`, barrido
> de `APP_MOVIL_VERSION_MINIMA`). Importa el día del despliegue y no es cosmético:
> **el bucle alcanza a los dieciséis y a `demo`**, así que quien despliegue contando
> quince va a ver un colegio de más y tendrá que decidir a las tres de la mañana si es
> legítimo. Lo es.

- `app/`, `routes/`, `config/`, `.env`: **copia real en cada colegio**. Un
  arreglo fusionado **no está desplegado**; llega colegio a colegio.
- `vendor/`: **compartido por symlink**. Un `composer install` dentro de un
  colegio sigue el symlink y cambia todos los que cuelguen de esa carpeta.
- `storage/`: propia de cada colegio.
- **Producción corre MariaDB 10.5.25, no MySQL 8.** `SELECT VERSION();` dio
  `10.5.25-MariaDB-cll-lve` en los dos shared hostings (Joseth, 5 sep 2026); el
  docker corre MySQL 8.0.42. Un `JSON_TABLE`, un `LATERAL` o un `->>` pasan la
  suite entera y revientan en los dieciséis. Lo que sí hay: columnas al instante
  desde la 10.4, así que la tanda no reconstruye `notas`.

Hay **cuatro clientes**, no uno: `myvc_front` (AngularJS, uno por colegio),
`myvc_front_2` (Angular, solo el PIAR), `myvc_flutter` (**una sola app para
todos** — lo que la rompa los rompe a todos) y esta API. Un arreglo del front que
exponga un endpoint no se publica hasta que el guard del backend esté
**desplegado**, no solo fusionado.

Los comandos están en `docs/DESPLIEGUE.md` y el porqué en
`docs/DESPLIEGUE-REFERENCIA.md`.

## Rendimiento

Medido, no supuesto: `docs/migracion/02-plan-rendimiento.md` lleva la cuenta de
qué se probó y qué resultó ser ruido. Dos interruptores, los dos apagados de
serie: `CONSULTAS_LENTAS_MS` (registro de consultas lentas, porque en cPanel no
hay acceso al `slow_query_log` de MySQL) y `CONTEXTO_SEGUNDOS` (caché del
contexto de usuario, que medida ahorra 0,75 ms y por eso no se enciende).

Antes de optimizar algo: medirlo. Antes de crear un índice: `EXPLAIN`.
