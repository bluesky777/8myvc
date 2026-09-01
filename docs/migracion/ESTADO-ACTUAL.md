# Dónde está la aguja ahora mismo

> **Léeme el primero.** Este documento existe para que una sesión nueva pueda
> continuar **sin que Joseth tenga que dar contexto**. Es corto a propósito: dice
> qué se está haciendo, qué acaba de terminar, qué es lo siguiente y qué espera una
> decisión suya. El detalle de cada cosa vive en su documento y está enlazado.
>
> **Se actualiza en el mismo commit que el trabajo**, no en uno aparte al final:
> un commit aparte es el que no se hace cuando la sesión se corta.

**Última actualización: 31 ago 2026, noche — LA MARCA DEL BOLETÍN INDEPENDIENTE PASA A SER POR
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

> **LO QUE NO SE HIZO, Y NO ES UN OLVIDO: los 29 sitios de la fase 1.** Es el trabajo de verdad que
> queda y no cabía en esta tanda. Lo que sí queda es **la lista medida con nombre y línea**, el
> criterio de terminación corregido y el patrón de falso positivo, que es lo que hace que el
> siguiente los pueda ir cerrando sin volver a medir. **Sin fase 1 no hay fase 2, y sin fase 2 no hay
> nada que escriba la marca.**
>
> ## ✅ VERDE: 1.579 pruebas, 11.857 aserciones · pint PASS · larastan nivel 7 `[OK]`
>
> **1.578 eran el 30 ago y el de más es el test nuevo de la decisión 7** — el desglose cuadra exacto,
> así que el número está medido y no copiado. La suite entera, nunca con `--filter`:
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

**La tanda del 22 al 25 ago se desplegó el 25** (`eb95cbc`, mismo hash en los quince).
**Lo de después no**, y ya hay una tanda nueva medida en
[DESPLIEGUE.md](../DESPLIEGUE.md) — que hasta hoy decía «no hay tanda pendiente» y era
falso desde que se fundieron GEMELO-1 y las notas de alumno.

Medido sobre el rango entero (`eb95cbc..HEAD`), no sumando commit a commit: **0 de
dependencias, 0 en `config/`, 0 en `routes/` — las 542 rutas sin mover**, y **27 ficheros
de `app/`**. El volcado congelado (`database/schema/`) tampoco se movió, pero **eso no
significa que no haya cambio de esquema**: hay **UNA migración y es bloqueante**,
`2026_08_26_100000_interruptores_de_certificados`.

> **Este párrafo decía «0 migraciones, 0 cambios de esquema y tres ficheros de `app/`», y
> el despliegue se decide leyéndolo.** Esta vez la cifra sí envejeció, que es el caso que
> no cubre la regla de las cifras que nacen mal: era **cierta al escribirse** en `5e4ec63`
> —entonces el rango de verdad no tenía migraciones— y **se quedó ahí veinte commits**,
> entre ellos `acd189b`, que es justo el que añade la migración.
>
> **La cifra peligrosa era «0 migraciones»**, porque de este párrafo sale la respuesta a
> *«¿este despliegue lleva `migrate --force`?»*. Con el código y sin la migración,
> `Year::datos()` pide `y.usa_consecutivo_certificados` y contesta **500 en todo lo que
> cuelga de ella** —los trece de `boletines/*` y `bolfinales/*`, `informes/datos`,
> `piars-config`, `grupos-con-disciplina` y `notas/actuales-alumnos`—, en los quince
> colegios a la vez. `DESPLIEGUE.md` lo decía bien mientras este lo decía al revés, y
> **de dos documentos que se contradicen el que se lee primero es este**.
>
> **Lo cazó `myvc-front-a2` el 27 ago 2026** chocando de frente con el 500 en su docker
> —`Unknown column 'y.usa_consecutivo_certificados'`, con el menú Informes de `app2` en
> blanco—, no leyendo el documento. **Un rango sin desplegar se vuelve a medir entero cada
> vez que se le añade un commit**, no una vez: lo que envejece no es el commit nuevo, es
> el resumen del rango.

Dentro está **el boletín final de 3.820 consultas a 455** —la queja de los 24–63 s—, **la
ficha del alumno que crea las notas que faltan** y **lo del consecutivo de certificados**.

**Y trae dos cosas que hay que decirle al front el día que se despliegue**, las dos de
las de *«quién puede llamarla»*: el 403 de `cambiar-contador-*` a quien no sea
administrativo —las dos pantallas que lo llaman enseñan el control sin mirar el rol— y
que `aumentar_contador` hay que **omitirlo**, no mandar `false`. Están en
[DESPLIEGUE.md](../DESPLIEGUE.md) y en [cert-2 §6](noche-2026-08-26/cert-2.md).

Y en `myvc_front` queda apuntado, sin hacer, el arreglo de **las cuatro altas de la
planilla de notas que no mandan `fecha_hora`** (`MIGRATION.md` §4b.3b).
