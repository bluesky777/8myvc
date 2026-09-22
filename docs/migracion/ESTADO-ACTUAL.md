# Dónde está la aguja ahora mismo

> **Léeme el primero.** Este documento existe para que una sesión nueva pueda
> continuar **sin que Joseth tenga que dar contexto**. Es corto a propósito: dice
> qué se está haciendo, qué acaba de terminar, qué es lo siguiente y qué espera una
> decisión suya. El detalle de cada cosa vive en su documento y está enlazado.
>
> **Se actualiza en el mismo commit que el trabajo**, no en uno aparte al final:
> un commit aparte es el que no se hace cuando la sesión se corta.

> ## 🔜 EL HISTORIAL DE TODO: ES LA FASE 5 DEL 18, NO UN DISEÑO NUEVO (21 sep 2026)
>
> **Joseth pidió «historial de prácticamente todo cambio», con una columna `Historial`
> en casi todas las pantallas.** Lo primero que hay que saber es que **eso ya está
> diseñado y medio construido**: [`18-auditoria.md`](18-auditoria.md), 8 fases y seis
> decisiones suyas. Hay tabla `auditoria`, escritor único `App\Services\Auditoria`,
> **71 llamadas en 27 ficheros**, reloj único y el permiso `can_view_auditoria`
> sembrado. **Lo que no hay es un solo lector**: `grep "FROM auditoria" app` → **0**.
> Se lleva escribiendo rastro desde agosto y nadie lo puede ver.
>
> **Dos decisiones nuevas, contestadas el 21 sep** (`18-auditoria.md`, «La quinta y la
> sexta»): una tabla **no estrena** la columna `Historial` hasta que todos los caminos
> que la escriben pongan `updated_at` y `updated_by`; y el alcance va **por dominio, en
> orden de reclamo**, no en un barrido de los 178 métodos. Y queda escrito por qué NO se
> hace una tabla `_history` por tabla.
>
> **La columna es gratis y yo dije que no lo era.** Sale de `updated_at`, que ya viaja
> en la fila; el modal es `auditoria/entidad/{tipo}/{id}`, ya especificada. **Cero rutas
> nuevas, cero instantáneas movidas.** Lo caro es que `updated_at` sea cierto: **102 de
> los 169 `DB::update(` de `app/` no lo escriben**, y las **83 de 83** columnas
> `updated_at` del volcado son `timestamp`, que convierte, contra el `DATETIME(3)` de
> Bogotá de la auditoría — la celda y el modal pueden discrepar en cinco horas.
>
> ### El orden, y lo que bloquea a qué
>
> | | Qué | Estado |
> |---|---|---|
> | **L0a** | `CentinelaDeLosEscritoresDeBitacoraTest` **está rojo en `main`**: espera 12 escritores de `bitacoras` y hay **14** — `app/Services/EscrituraDeNotasImportadas.php` entró con `3e16747` y no se declaró ni en el test ni en `tools/salud-de-la-bitacora.php`, que hay que mover a la vez | **pendiente, y es de quien tocó la planilla** |
> | **L0b** | Fase 0 en los dieciséis (`tools/fase-cero-de-los-dieciseis.php`): sin ese número, la retención de la fase 6 y cualquier índice nuevo son adivinanza | pendiente |
> | **L1** | **Fase 5: las cuatro rutas de lectura.** Es lo que desbloquea todo lo demás | pendiente |
> | **L2** | El contrato con `myvc_front`: la columna y el modal | tras L1 |
> | **L3…** | Ensanchar la fase 4 por dominio. Hoy **42 de 221 métodos con rastro; 178 sin ninguno** (`tools/escrituras-sin-auditoria.php`, que **no ve Eloquent**: 178 es suelo) | en curso |
>
> ### 🔜 DESPLIEGUE PREPARADO, NO LANZADO — decisión de Joseth del 21 sep
>
> **Él decidió: se prepara ahora y la suite entera se corre DESPUÉS, justo antes del
> despliegue de verdad.** No se corre hoy. Lo que queda escrito aquí es el orden, para que
> quien lo lance no lo reconstruya de memoria a las tres de la mañana.
>
> **1 · La suite entera, con su interruptor.** Es la regla del repo y no es negociable
> antes de desplegar: lo fundido es barato de deshacer, lo desplegado viaja a dieciséis
> colegios copia a copia.
>
> ```bash
> docker exec -d -e COBERTURA_RUTAS=/tmp/rutas-tocadas.txt 8myvc-app-1 \
>     sh -c 'php artisan test > /tmp/suite-despliegue.txt 2>&1'
> ```
>
> Se lanza con `-d` y se consulta el fichero **dentro** del contenedor: matar el
> `docker exec` no mata el `php`. Antes de lanzarla, comprobar **contra qué base** corre
> cada `phpunit` vivo (la orden está en el `CLAUDE.md`): la contención es por base, no por
> árbol. Y al leer el resultado, **la línea `Tests:` o no hubo suite** — un exit 0 sin esa
> línea es una suite muerta, no una verde.
>
> **2 · Las migraciones que sobrescriben datos son cuatro, y son el riesgo real de la
> tanda**: `la_casilla_vacia` (notas), `reparar_la_hora_escrita_dos_veces`,
> `interruptores_de_certificados` (`years`) y `el_correo_del_acudiente_en_su_cuenta`, que
> pisa `users.email` fila a fila sin dejar rastro. De 48 migraciones, 7 tocan datos y sólo
> esas 4 destruyen. **Ninguna de las cuatro escribe todavía sus filas de auditoría antes
> del `UPDATE`** (decisión 7, escrita y sin construir): quien despliegue lo sabe, y ése es
> el punto donde se decide si se construye antes o se acepta el riesgo.
>
> **3 · Y sigue pendiente de antes**: redesplegar los dieciséis por el arreglo del reloj
> —los tres relojes en la misma comparación—, que se cerró el 21 sep y no ha llegado a
> ningún colegio.
>
> ### Hecho el 21 sep — **la fase 5 ya tiene lector**
>
> `auditoria/ingresos`, `/ingresos/{id}`, `/entidad/{tipo}/{id}` y `/alumno/{id}` en
> `Auditoria/AuditoriaController`. Se acabó el «se escribe rastro desde agosto y nadie lo
> ve». Router **660 → 664**, las tres instantáneas regeneradas, familia con 4 de 4 guards.
> Permiso partido: lo propio siempre, lo ajeno con `can_view_auditoria`. Las tres
> preguntas que el front tenía abiertas (2, 3 y 7 de su A.4) estaban **ya resueltas en
> código desde AUD-5** y sólo faltaba trasladarlas: contestadas en `myvc_front` `8486821d`.
> El centinela de escritores de bitácora lo dejó verde otra sesión en `3ebdc07`. **El acudiente
> todavía no ve la auditoría de sus acudidos** — restricción consciente, pide la consulta
> de parentesco. Sin tests, a petición de Joseth. Falta el front (L2).
>
> ### Hecho el 21 sep, `88beb44` — la decisión 7
>
> `auditoria` gana **`valor_anterior_num` / `valor_nuevo_num`** (`int`, nullable, sin
> índice): restaurar deja de necesitar funciones de JSON, que en MariaDB 10.5 no son lo
> mismo que en el MySQL 8 del docker. Las rellena `de()`/`a()` sólo si el valor es un
> entero exacto. **Queda escrito y sin construir**: una migración que sobrescribe datos
> escribe sus filas de auditoría antes, en UNA sentencia con el mismo `WHERE`. De 48
> migraciones, 7 tocan datos y **4 sobrescriben lo que ya existía** — una es
> `el_correo_del_acudiente_en_su_cuenta`, que pisa `users.email` sin dejar rastro.
> Y falta el `tools/` que liste, antes de desplegar, qué migraciones de la tanda escriben
> datos.
>
> Las tres reglas de respuesta que no vuelven a preguntarse: las rutas nuevas son
> **aditivas** (no retiran nada hasta la fase 7), **un vacío es `200` con lista vacía y
> nunca un `400`**, y el modal lee de `auditoria`, jamás de `bitacoras`.

> ## ✅ LA IMPORTACIÓN DE ALUMNOS, REHECHA ENTERA (20–21 sep 2026)
>
> **Joseth pidió «rápido, confiable y lo más failover posible» y está en `main`.** Cinco
> commits: `c9c2411`, `8071970`, `ca5ee8f`, `a0b5b7e`, `fb5a146`, `f150949`, `5a97e21`.
>
> ### Lo que se midió antes de diseñar, porque el problema no estaba donde parecía
>
> | | medido |
> |---|---|
> | leer el libro (4.000 filas × 40 col) | **4,5 s y +45 MB** — NO era el cuello |
> | escribir | **6,7 consultas por fila** → 4.000 filas ≈ 27.000 consultas |
> | de esas, lastre | 1 `INSERT INTO debugging` por fila, con 17.457 filas acumuladas |
> | cola de trabajos | **no hay**: `sync`, cero `app/Jobs`. En cPanel no hay demonio |
> | `max_execution_time` | **30 s** en el `php.ini` del contenedor (web). El **300 de cPanel es dato heredado**, no medición |
>
> *No era lento por leer Excel: era lento por hablar con la base fila a fila. Y no puede
> irse a segundo plano porque no hay dónde.*
>
> ### Lo que quedó hecho
>
> 1. **Fuera la transacción global de maatwebsite** (`config/excel.php`, `'handler' => 'null'`
>    — la **cadena**, no el `null` de PHP: costó 55 rojos). Envolvía la importación entera,
>    así que el ROLLBACK se llevaba las filas **y el avance**: «reanudar» sólo podía
>    significar «no se escribió nada». La transacción **por fila** ya existía y es la que
>    impide dejar medio alumno.
> 2. **Troceado por petición**: 20 s, punto de control, `terminado: false` + `faltan`. El
>    front reenvía. Peor caso de un corte: 20 s en vez de los 300 que tardaba en morir.
> 3. **Lotes de 25** filas, una transacción y **una marca por lote**. Con lo anterior:
>    **6,7 → 4,73 consultas por fila (−29 %)**.
> 4. **Siempre se escribe al menos un lote por petición** — sin eso, un libro que agota el
>    presupuesto al leerlo diría «faltan N» para siempre. *La guarda del front era la única
>    red.*
> 5. **Cerrojo** `GET_LOCK` por archivo y año (la segunda ventana recibe **409**). Se suelta
>    solo al caerse la conexión, que es lo que hace falta sin cron.
> 6. **`filas_totales`** en `importaciones`, para que el aviso de «a medias» tenga
>    denominador. `NULL` significa **«no se sabe»**, no cero.
> 7. **El estado que CABE y no existe** (`ACTV`): catálogo **medido, no declarado** — lo que
>    el código nombra ∪ lo que ese colegio ya tiene escrito.
> 8. **El ensayo se recorta** en vez de reventar a los 30 s, y `puede_importarse` pasa a
>    **`null`** —«no se sabe», distinto de `false`— cuando el plan está recortado.
> 9. **Las cuatro secciones de decisiones se aplican**: `vacios`, `hojas`, `duplicados`,
>    `repetidos`. `no_aplicadas` queda vacío.
>
> ### 🔴 LO QUE ESPERA A JOSETH, y no lo decide una sesión
>
> | | |
> |---|---|
> | **Que se vea que un año quedó a medias** | Él eligió «un aviso mientras esté a medias». El backend ya da `filas`/`filas_totales`; **la pantalla es del front y hay que pedírsela** |
> | ~~**Nada reanuda solo** si se cierra el navegador~~ | **Cerrado 21 sep 2026.** Joseth decidió: no se guarda el archivo en disco (coste de persistir datos de alumnos en los 16 cPanel + el mismo tope de 300s dentro del propio cron), así que el cron no reanuda — sólo evita que la fila mienta. `importaciones:marcar-abandonadas` pasa a `fallida` lo `en_proceso` sin actividad en 10 min, mismo cron que `notificaciones:enviar` (`app/Console/Kernel.php`). Sigue siendo una persona quien vuelve a subir el archivo |
> | **El 500 sin cabeceras de CORS** | Una línea en `public/.htaccess` (`Header always set`), pero **puede romper lo que hoy funciona** si la cabecera sale duplicada. Hay que medirlo en un cPanel de verdad |
> | **`cerrado_por_nombres` sale `NULL`** para administrativos (47 §7.7) | `users` sólo tiene `username`: qué se enseña sin ficha es producto |
>
> ### Tres avisos para quien siga
>
> - **La suite entera se debe**: esta tanda tocó `config/` y `database/migrations/`.
> - **`php artisan migrate` con `DB_TEST_DATABASE` migra la base de DESARROLLO** — esa
>   variable sólo la lee phpunit. Dice `DONE` y la columna no aparece. Lo que sirve es
>   `-e DB_DATABASE=<la de tests>`.
> - **`stan` da 7 errores que NO son de esta tanda**: `CandadoDeLaPlantilla` y su test están
>   **modificados sin commitear** en el árbol por otra sesión.
>
> ### Y la forma que se repitió TRES veces, que es lo más transferible
>
> `myvc-front-51` encontró **conduciendo contra el docker** lo que los tests de aquí no
> vieron, tres veces y siempre igual: **una capa se entera de la decisión y la de al lado
> no.** El `consecuencia` del ensayo prometía el plan de antes de decidir; el veredicto
> `puede_importarse` seguía bloqueando una hoja que la persona acababa de mandar omitir; y
> antes, el 500 llegaba sin cabeceras y la pantalla lo confundía con un fichero ilegible.
> *Los tres se vieron ejecutando el ciclo entero, no leyendo el código.*

> ## 🟡 ESPERA A JOSETH — LA CITACIÓN DESTAPÓ QUE `ver-ausencias` VE EL 0,04 % (20 sep 2026)
>
> **No hay nada escrito y no se va a escribir hasta que él lo diga.** Salió de conducir
> `ausencias/de-alumno` contra la rama, y lo midieron las dos sesiones por separado.
>
> `PlanillasController::getVerAusencias` lleva `WHERE a.entrada=true`, o sea que **sólo ve
> las faltas de portería**. Medido en la copia de desarrollo, y reproducido en los dos
> lados:
>
> | año | filas de `ausencias` | las que ve `ver-ausencias` |
> |---|---|---|
> | 2026 | 3 | **0** |
> | 2025 | 1.379 | 15 |
> | 2024 | 10.311 | **0** |
> | 2023 | 10.192 | **0** |
> | **total vivas** | **46.478** | **17** (0,04 %) |
>
> **El informe de inasistencias de `myvc_front` lee de ahí.** No engañaba —su pie ya decía
> «sólo faltas a la institución»— pero **el recorte declarado resulta ser casi todo**, y una
> nota que dice «una clase suelta» se lee como un caso de borde cuando es el 99,96 %. El
> front ya endureció ese aviso (`3ec30747`). *No hay que reparar una hoja que engaña: hay
> que decidir si se le cambia la fuente a una hoja honesta que no puede hacer su trabajo.*
>
> ### LAS TRES SALIDAS, con lo que cuesta cada una
>
> 1. **Una ruta de faltas por GRUPO**, hermana de `ausencias/de-alumno`. Arregla el papel
>    **sin cambiarle la pantalla a nadie**. Cuesta una ruta (647 → 648) y tres instantáneas.
>    Es lo que recomiendan las dos sesiones.
> 2. **Quitarle el `entrada=true` a `ver-ausencias`.** Cero rutas nuevas, pero **cambia lo
>    que ve hoy** la planilla del front viejo en los dieciséis colegios y **dos** pantallas
>    de `app2`. Eso no lo decide una sesión.
> 3. **Dejarlo**: el papel sigue declarando el recorte, ahora con el aviso fuerte.
>
> **Nadie ha tocado `ver-ausencias` ni ha escrito la ruta nueva.** El detalle y las opciones
> del lado del front están en `myvc_front/INFORMES-NUEVOS-CIERRE.md` §3.
>
> > **Y de paso se cayó una premisa del relevo del front** —«no hay ni una ausencia en todo
> > el año, 29 alumnos matriculados, cero registros»—: estaba medida **por esa misma puerta
> > ciega**. Hay 3 en 2026 y 46.478 en la base. Retirada allí. *Es el caso de libro de una
> > cifra correcta sobre la población equivocada.*

> ## 🚀 EL AVISO QUE NO SE PODÍA APAGAR — CERRADO, HAY QUE REDESPLEGAR (21 sep 2026)
>
> **Lo reportó Zaragoza el día después del despliegue del 20 sep**: «Notas finales
> desactualizadas Per 1…4» en el tablero de informes, y **los botones no lo quitan por más
> que se pulsen**. Lo trajo el detector nuevo del 17 sep (`6c1c77b`), que es el que ahora
> alimenta `periodos_desactualizados`. Reproducido sobre una copia de su base.
>
> **Eran dos fallos, y los dos están cerrados.**
>
> ### 1. `faltan` contaba lo que ese botón no escribe nunca
>
> `putCalcularGrupoPeriodo` sale de un `INNER JOIN notas`: al alumno sin notas no le crea
> la fila. De los **39 grupo-periodo marcados en Zaragoza, los 39 lo estaban por esto** —
> la alumna matriculada en junio no tiene notas del per1, y el per4, que aún no se
> califica, marcaba **los dieciséis grupos**. `estadoDelGrupo()` pasa a contar sólo lo que
> tiene notas detrás. Con eso, de los 39 quedaban **4**.
>
> ### 2. Tres relojes en la misma comparación — unificados
>
> Los 4 que quedaban estaban **al día** y marcados igual: su definitiva era de las 12:20 y
> su última nota de las 11:38, las dos en Bogotá; lo que los encendía era
> `subunidades.updated_at`, el **mismo guardado** escrito cinco horas después porque lo
> sella Eloquent en UTC. La prueba limpia: la nota nace en la misma petición que su
> subunidad, y en Zaragoza hay **34.903 pares separados 18.000 s exactos** contra **6.188
> en el mismo segundo** (`putCopiar`, que crea las dos por Eloquent), **de 2018 a 2026
> entremezclados** — por eso no se puede arreglar en el que lee.
>
> Se arregló en el que escribe: `App\Support\SellaConElReloj` en `Nota`, `Subunidad`,
> `Unidad` y `Matricula`, y los tres `NOW()` que escribían en esas columnas
> (`DefinitivasDeAsignatura::calcular`, `CierreDeLoNoCalificado::pasarACero`) pasan a
> `Reloj::ahora()`. **El rasgo NO se sube a un modelo base**: las sesiones y los tokens se
> comparan contra un `now()` de UTC y moverles el reloj les cambia la vida útil.
>
> ### Que no rompe nada — comprobado, no supuesto
>
> - **Nada caduca ni se limpia por estas fechas**: las expiraciones del proyecto son de
>   `personal_access_tokens` y `password_reminders`.
> - **El predicado que vació las casillas el 20 sep selecciona exactamente lo mismo.**
>   `created_at <=> updated_at` son dos columnas de la misma fila, se mueven juntas — y
>   medido: **cero** filas en las cuatro tablas tienen sus dos fechas a cinco horas
>   exactas, o sea que ninguna las tiene escritas por caminos distintos.
> - **Las filas viejas no se tocan**: las que quedaron en UTC siguen cinco horas por
>   delante hasta que alguien las vuelva a guardar, así que el aviso residual se apaga en
>   horas, no el día del despliegue.
> - **Cabo suelto anotado**: `Matricula::ORDEN_DEL_ANIO` ordena por `created_at`, y
>   mientras convivan viejas en UTC con nuevas en Bogotá una vieja puede ganarle a una
>   nueva creada hasta cinco horas después. Un caso vivo en `simonbolivar`.
>
> > **Advertencia para la siguiente sesión**: el primer intento fue escribir el sello con
> > `NOW()`. **Está mal** — `config/database.php` no fija la zona de la sesión, así que
> > `NOW()` es la del servidor y son dieciséis cuentas de cPanel distintas. Revertido, con
> > el porqué en el propio método.
>
> ### Lo que hay que hacer con esto
>
> - **Redesplegar los dieciséis colegios.** El aviso lo ven todos, no sólo Zaragoza.
> - **Lo atan cuatro tests**, entre ellos `los_modelos_del_sello_sellan_en_bogota`
>   (`RelojUnicoTest`) —quitar el rasgo no rompía ninguna otra prueba: la fecha se guarda
>   igual, sólo que movida— y `test_tras_pulsar_el_boton_el_tablero_deja_de_marcar_el_grupo`,
>   que une el botón con el aviso.
> - **Lo que NO arregla:** las filas que faltan de verdad —las 11.988 de la fase 0— siguen
>   faltando. Ya no se anuncian con un botón que no puede crearlas.
> - **Y queda una cosa del front viejo**, que es de `myvc_front`: `informes.html` pinta el
>   bloque con bindings de una sola pasada (`::`), así que **el aviso sólo desaparece al
>   recargar la pantalla**, no al pulsar. El tablero de `app2` sí se actualiza en el sitio.
> - **Cabo suelto de otra sesión**: `app/Support/NotasAlCambiarDeGrupo.php` escribe
>   `notas_finales` con `now()` a secas (UTC). Hoy no muerde porque esas filas van con
>   `manual = 1` y el detector las excluye; si alguien quita ese `manual`, es un tercer
>   reloj dentro de `notas_finales`.

> ## ✅ LOS TRES INFORMES DEL CATÁLOGO — TRES RUTAS, UNA COLUMNA Y EL CONSECUTIVO (20 sep 2026)
>
> **Escrito en `.worktrees/inf`, rama `feat/los-tres-informes-del-catalogo`, base
> `simonbolivar_testing_inf`.** Lo pidió `myvc_front` —sesión `myvc-front-38`— con la
> especificación medida contra este código, y **el alcance lo eligió Joseth con el precio
> delante**: lo pidió todo, citación incluida, y quemar el consecutivo por hoja. Contrato y
> porqués en [48](48-los-informes-del-catalogo.md).
>
> De los seis informes nuevos de `/informes` iban tres; los tres que faltaban —directorio del
> grupo, citación al acudiente y acta de nivelación— **no estaban sin hacer por maquetación: les
> faltaba el dato.**
>
> ```
> PUT  informes/nivelaciones-del-grupo   la sección A del acta, de una vez
> PUT  ausencias/de-alumno               las faltas de uno, para la citación
> GET  informes/membrete                 lo que hace falta para firmar un papel
> ```
>
> **Router en 647**, **recontadas en el ÁRBOL PRINCIPAL sobre `main` y después de fundir**
> (`fdecea7`, 20 sep 2026) — y coincidieron con las 647 contadas antes en `.worktrees/inf`, que es
> la única forma de saber que coincidía. Esta línea decía *«SIN FUNDIR: hay que recontarlas en el
> ÁRBOL PRINCIPAL el día que entren»* y **aquél fue ese día**. Ninguna es pública:
> `RutasPreLoginTest` no se mueve.
>
> Más, sin gastar ruta: `alumno_id` en los alumnos de un acudiente; las tres columnas del acta en
> la consulta de recuperaciones; `years.titulo_constancia_estudio` (6 instantáneas); y el
> consecutivo **uno por hoja**.
>
> ### 🔴 EL AVISO QUE ESTABA ESCRITO ENCIMA DE LA CONSULTA, CUMPLIDO
>
> Tres líneas por encima de la consulta de recuperaciones ponía desde el 2 sep: *«los metadatos de
> acta que A9 le añada no salen impresos hasta que alguien los nombre aquí»*. **Eso es
> exactamente lo que había pasado**: `nivelada_at`, `nivelada_por` y `observacion` se escribían
> desde entonces y **no las leía nadie**, así que el acta salía con la nota y sin fecha, sin
> responsable y sin actividad. Es `profesores.tono` con el aviso ya puesto al lado.
>
> **Y el nombre sale de `users` y no de `profesores`**, que es donde la petición ofrecía las dos:
> `nivelada_por` guarda un id de `users` y **0 de las 22 cuentas `Usuario` tienen ficha** en
> `profesores`. Unir contra la ficha dejaría sin nombre justo a secretaría. Es la misma trampa que
> `getRecorrido` cometió ese día; aquí se evitó **yendo a mirar quién escribe la columna antes de
> elegir con qué unirla**.
>
> ### ⚠️ EL CONSECUTIVO VA DETRÁS DE UNA LLAVE, Y ESO NO ES RECORTAR LA DECISIÓN
>
> Joseth mandó quemar por hoja. El reparto va detrás de `consecutivo_por_hoja` porque el número
> viaja en `year.contador_certificados` —uno para toda la respuesta— y los dieciséis llevan fronts
> de versiones distintas: sin la llave, un colegio con el front viejo **gastaría 37 folios
> oficiales para imprimir 37 veces el mismo número**. Quemar es la dirección irreversible. *Es la
> decisión sin el efecto que no pidió,* y lo protege
> `ConsecutivoPorHojaTest::sin_pedirlo_se_sigue_quemando_exactamente_uno`.
>
> ### Lo que queda apuntado
>
> La **tabla de certificados emitidos** sigue sin existir —un número quemado por abrir la pantalla
> es indistinguible de uno emitido— y **reimprimir un acta vieja tal como se firmó no se puede**:
> la fila de `notas` sólo guarda la última nivelación y `auditoria` **no la lee nadie** (`FROM
> auditoria` en `app/`: **0**, contado sin truncar).

> ### ✅ CONDUCIDO POR EL FRONT CONTRA LA RAMA, no sólo tipado (20 sep 2026)
>
> `myvc-front-38` lo probó contra un servidor de esta rama, **no contra `main`**, y cerró
> dos de los tres informes: citación 12/12 (`d25f0a14`) y acta 11/11 (`82d5be16`). El
> **directorio del grupo** se queda **sin empezar** —decisión de Joseth de parar ahí—, y le
> falta la pantalla, **no el dato**: `a.id as alumno_id` ya está en esta rama.
>
> **El entorno sigue vivo por si hay que mirarlo antes de decidir**, y se apaga con dos
> órdenes:
>
> ```bash
> docker rm -f 8myvc-inf                                    # el servidor en :8081
> # y la copia de la base, 215 MB:
> docker exec 8myvc-database-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
>     -e "DROP DATABASE simonbolivar_inf"'
> ```
>
> **No se sirvió desde `8myvc-app-1`, y el motivo importa si alguien lo repite**: ese
> contenedor **sólo publica el 80**, así que un `artisan serve` dentro es inalcanzable, y
> publicar otro puerto exige recrearlo —tumbando a las demás sesiones—. Se levantó un
> contenedor **aparte** con la misma imagen y montaje. Y **no contra `simonbolivar`**:
> aquella base tiene tres migraciones pendientes y **dos son de otras sesiones**, así que
> migrarla habría sido aplicar trabajo ajeno a la base que comparten ocho. Se copió.
>
> > **⚠️ La copia lleva datos FALSOS sembrados para poder conducir**, y uno está mal a
> > propósito de nadie: **las 12 nivelaciones del grupo 117 van con `nota_original: 70` y
> > `nota_nivelacion: 72`, que en este colegio son notas IMPOSIBLES.** Medido después:
> > `nota_minima_aceptada` es **30** y en 2026, descontando la semilla, hay **70 notas ≤50 y
> > ninguna >50** —en toda la tabla, 108 de 1.166.707 pasan de 50, el 0,009 %—. O sea que la
> > escala es 0–50 y las sembré sobre 100.
> >
> > *Lo descubrí al sembrar las recuperaciones, avisé al front de esa misma trampa… y no
> > volví a mirar lo que ya había sembrado una hora antes.* No hizo daño —el acta las marca
> > las doce con «se registró bajo otra regla», que es literalmente cierto, y la copia se
> > borra— pero es la forma exacta en que una cifra mala sobrevive: **se aprende la regla y
> > no se repasa lo hecho antes de aprenderla.**

> ## ✅ LAS DOS RESPUESTAS DE JOSETH, APLICADAS — y la segunda destapó tres faltas (20 sep 2026)
>
> **La casilla que había aquí era un traspaso: `8myvc-6f` se quedó sin ventana con dos
> decisiones suyas recién dadas y sin escribir.** Las dos están hechas, en la misma rama
> `feat/el-proceso-de-matriculas` de `.worktrees/mat`. **FUNDIDO el 20 sep 2026** (`74d5028`);
> la rama y su worktree ya no existen. Esta línea decía *«lo que sigue sin fundir es todo,
> incluido esto»*, y dejó de ser cierta el día que entró.
>
> ### 1 · ✅ EL COORDINADOR ACADÉMICO ADMITE
>
> *«el coordinador académico puede admitir estudiantes también.»* `puedeDecidirAdmision` deja
> de ser `esAdministrativo()` y pasa a la forma de `puedeCambiarLaNotaNumerica` —lista de roles
> contra `Role::getUserRoles()` en una consulta—.
>
> **Recontado aquí por `role_id`, no heredado del traspaso**: `Coord académico` tiene **1
> titular y no es superusuario**, así que quien admite pasa de **12 a 13** de las **75** cuentas
> de personal. `Rector` **no entró**: nombró un rol, y meterlo sería inerte (cero titulares) y
> además nadie lo pidió. Lo fijan **dos** tests —uno que admite con el rol puesto sobre el mismo
> sujeto que acaba de recibir un 403, y otro que se pondrá rojo el día que `Rector` se cuele—.
>
> ### 2 · ✅ LA PANTALLA 13 SE RETIRA… y el aviso al tesorero NO era «una fuente más»
>
> *«No entiendo lo del pagaré en papel…»* — con eso se cae la pantalla 13 y la **fase 4** entera,
> que se queda sin contenido. Escrito en el [47](47-el-portal-de-la-familia.md) §9.
>
> **Y la pieza que él da por hecha —«le mandamos la notificación al tesorero»— se midió antes de
> prometerla, que era el encargo. No falta un cable: faltan tres piezas.**
>
> | | medido |
> |---|---|
> | no hay ningún tema de **persona** | los temas son 4 por alumno + 2 de colegio; el nombre se deriva del **id del alumno**, y eso es la única puerta del diseño |
> | la app **no puede recibir push** | `myvc_flutter` trae `firebase_core` y `firebase_analytics`, **no `firebase_messaging`**; `subscribeToTopic`, 0 veces en código |
> | **no hay a quién avisar** | `years.tesorero_id` en NULL en los **9 años vivos**; su único lector es `Autoriza::puedeAprobarColilla`, que por eso lleva respaldo de secretaría |
>
> > **Y esto corrige una frase del [41](41-el-formulario-de-inscripcion.md) §7**, que decía que el
> > tesorero *«tiene cuenta y tiene app, o sea push, que ya existe… no porque falte canal»*.
> > **Falta el canal.** Corregida allí con la medición al lado.
>
> > **Además la bandeja no la abre nadie todavía**: `GET colillas-inscripcion/pendientes` existe
> > desde el 19 sep y `colillas-inscripcion` aparece **0 veces** en los tres clientes. *Conectar un
> > aviso a una bandeja sin pantalla sería avisar de algo que no se puede ir a mirar.*
>
> **✅ Y ELIGIÓ UNA CUARTA, mejor que las tres:** *«los tesoreros tienen cuenta administradora,
> al entrar les aparece que hay cambios solicitados… reutilizar eso.»* Eso existe y es
> **`GET ChangesAsked/to-me`**, que ya agrega siete bloques distintos. **No necesita push, ni
> correo, ni pantalla nueva.** Medido antes de darlo por fácil, y salieron dos cosas:
>
> - **`getToMe` tiene cinco ramas por `tipo` y una no devuelve bandeja**: de las 22 cuentas
>   `Usuario`, las **11 que no son superusuario** sólo reciben `publicaciones` y `eventos`. El
>   bloque tiene que colgar de `Autoriza::puedeAprobarColilla`, **no de la rama del `switch`**.
> - **Un tesorero con «cuenta administradora» NO puede ser nombrado tesorero**: `years.tesorero_id`
>   es un `profesores.id` y **0 de 22 `Usuario` tienen ficha ahí**. El flujo de hoy funciona por
>   el respaldo (`esAdministrativo`), no por esa columna.
>
> **Es la primera tarea de la tanda siguiente** —`ChangeAskedController` es legado de 1.400 líneas
> y su respuesta la leen los tres clientes—, y con esto ya no queda nada que investigar.

> ### 🔴 Y EL TEST DE ESTA TANDA DESTAPÓ UN DEFECTO VIVO EN `main`
>
> **«Con su nombre y su hora» — y el nombre sale vacío justo para quien atiende.**
> `getRecorrido` saca `cerrado_por_nombres` de `profesores`, y **0 de las 22 cuentas `Usuario`
> tienen ficha ahí** (0 de 20 en la base de tests). Cuando cierra el paso un administrativo el id
> viaja y el nombre viaja en `NULL`. Es la trampa que el `CLAUDE.md` ya tiene escrita, cometida
> en el módulo cuyo argumento entero es que quede el nombre.
>
> **No se arregla solo: `users` no tiene ninguna columna de nombre, sólo `username`**, así que
> qué se enseña cuando no hay ficha es producto —«ADRIANA GÓMEZ» y «secretaria2» no se leen
> igual—. **Espera a Joseth.** El test lo fija en el estado en que está, con la línea a cambiar
> señalada, para que el arreglo salga en rojo en vez de pasar inadvertido. 47 §7.7.

> ## ✅ EL PORTAL DE LA FAMILIA Y EL TABLERO DEL DÍA — DIEZ RUTAS (20 sep 2026)
>
> **Escrito en `.worktrees/mat`, rama `feat/el-proceso-de-matriculas`, base
> `simonbolivar_testing_mat`.** Cierra el proceso de matrículas por el lado del backend:
> **de las veintisiete pantallas diseñadas entre los dos clientes ya no le falta endpoint a
> ninguna** — la 13 no es la excepción: se retiró (casilla de arriba), no se aplazó. Contrato y
> porqués en [47](47-el-portal-de-la-familia.md).
>
> **Router en 644**, **recontadas con `route:list --json` en el ÁRBOL PRINCIPAL sobre `main` y
> después de fundir** (`74d5028`) — y coincidieron con las 644 contadas antes en `.worktrees/mat`.
> Esta línea decía *«SIN FUNDIR: hay que recontarlas en el ÁRBOL PRINCIPAL el día que entren»* y
> **aquél fue ese día**: la séptima vez seguida que esa frase salva el número.
>
> ```
> GET  estaciones/tablero                    el tablero del día (pantalla 15)
> GET  requisitos/mi-recorrido/{alumno_id}   la familia ve lo suyo (pantalla 04)
> GET  inscripcion/{codigo}                  PÚBLICA · el asistente
> PUT  inscripcion/{codigo}                  PÚBLICA · guarda el formulario
> POST inscripcion/{codigo}/documento/{id}   PÚBLICA · sube un documento
> GET  aspirantes                            la bandeja
> GET  aspirantes/{id}                       la ficha
> PUT  aspirantes/{id}/documento/{doc_id}    revisar: recibido | devuelto con motivo
> PUT  aspirantes/{id}/cita                  agendar Y resolver, la misma fila
> PUT  aspirantes/{id}/decision              admitir — LA ÚNICA con permiso dentro
> ```
>
> ### EL ALCANCE LO ELIGIÓ JOSETH, y lo que quedó fuera está bloqueado por él
>
> Se le pusieron tres delante y eligió el segundo: **cerrar lo decidido sin dueño más el
> portal de la familia**. La firma y la pasarela no entran porque dependen de dos
> preguntas suyas que siguen sin contestar (`INVESTIGACION-MATRICULAS.md` §10.2 y §10.3).
>
> ### 🔴 UN FALLO VIVO, encontrado midiendo y no revisando
>
> **Corregir una observación cerraba el paso y lo firmaba.**
> `prematriculas.ts::guardarObservacion` manda `estado: observacion.estado ?? ''` al
> guardar el texto, y en `postAlumno` la cadena vacía no era `falta` ni `devuelto`: entraba
> por la rama de cerrar, con **el nombre de quien escribió la tilde y su hora**. Sin error
> y con un `'Actualizado'` de vuelta.
>
> Es la misma familia que el fallo del 1 sep, cometido por el otro lado: *aquél borraba el
> estado cuando no venía, éste lo cerraba cuando venía vacío.*
>
> **Y la premisa se corrigió al intentar reproducirla**: este documento decía «hay filas
> con `estado` NULL» y es falso —la columna es `NOT NULL`—. Lo que aquel `UPDATE` dejó en
> los dieciséis es **la cadena vacía**, que es lo que un servidor no estricto escribe en
> una columna `NOT NULL`. *El test no pudo construir el caso, y por eso la premisa mejoró.*
>
> ### El vocabulario del 46 estaba MAL, y rechazar por él habría roto los dieciséis
>
> El 46 §2 proponía `Falta|Cumple|Observado|Devuelto`. **Ninguno de los cuatro es lo que
> escriben las pantallas desplegadas**: mandan `falta`, `ya` y `n/a`, en minúscula.
>
> Y no se midió censando una base —hay dieciséis y sólo se ve una—: se midió **contando los
> escritores**, que son tres y tienen los tres su lista cerrada en el fuente. *Una base dice
> qué pasó en un colegio; los escritores dicen qué puede pasar en los dieciséis.*
>
> ### Las tres públicas suben a DIECINUEVE, y la llave es DOBLE
>
> `GET colillas-inscripcion/{codigo}` fijó que **un código no puede revelar el nombre de un
> menor**. Este portal **sí tiene que devolver la persona** —la familia entra a seguir
> llenando lo que dejó a medias—, así que el código solo abre un formulario **en blanco** y
> en cuanto lleva nombre dentro pide además **el documento del aspirante**.
>
> *La llave no se entrega: la escribe quien la va a usar.* Lo fija un test que busca el
> nombre, el documento y el teléfono **en el JSON entero**.
>
> ### UN CONTROL QUE NO SE PUSO ROJO — y es lo que más enseña de esta tanda
>
> Al romper `sinTerminar()` quitándole el marcador `tocado`, **el test que parecía cubrirlo
> siguió en verde**. El caso que de verdad lo sostiene es el contrario: **a quien le
> devuelven un paso no le queda nada cerrado** —devolver limpia `cerrado_at` a propósito— y
> aun así sigue en el patio. Se escribió el test que nombra ese caso, y con él el control sí
> cae, y sólo él.
>
> *Es la tercera vez que este repo escribe «un control vale por haberse visto en rojo», y la
> primera en que lo encuentra por NO haberse puesto rojo.*
>
> ### Lo que mueve
>
> **Cinco instantáneas**, con el diff de las cinco mirado entero: `rutas.json` (634 → 644,
> las diez y ninguna más), `guards-por-ruta.json`, `guard-por-familia.json`
> (`aspirantes: 5/5`, `inscripcion: 3/0`, `estaciones: 9→10`, `requisitos: 7→8`),
> `familias-que-nunca-entran-en-el-candado.json` (**una línea: `inscripcion: 0 de 3`**) y
> `escrituras-donde-el-candado-no-llega.json` (26 → 28, y la diferencia de conjuntos son
> exactamente las dos del portal).
>
> Más tres listas: `AutenticacionTest::SIN_GUARD` (+3 con motivo),
> `RutasPreLoginTest::TOTAL_PUBLICAS` (**16 → 19**) y `FamiliasQueNuncaEntranTest` (26 → 28).
>
> **Y una que no estaba prevista**: `TemasDeNotificacion::TIPOS` pasa de tres a cuatro con
> `matricula`, así que **`GET notificaciones/temas` devuelve cuatro claves en vez de tres**.
> Una app vieja no se apunta al tema nuevo y no recibe estos avisos — degradarse bien.
>
> ### Estado — las cifras con la orden que las produjo
>
> | | |
> |---|---|
> | `ElPortalDeLaFamiliaTest`: **33 passed (278 aserciones)** | `--filter=ElPortalDeLaFamiliaTest --testsuite=Contrato` |
> 
> > **Este renglón decía 28 (225) y ya era falso antes de tocarlo**: en `a8d036d` el fichero
> > tenía **30** métodos de prueba, o sea que la cifra se escribió y el commit siguiente la
> > movió sin recontarla. Con los dos de la admisión son 32. *Recontado, no sumado* — que es
> > lo que el propio traspaso mandaba hacer con cualquier cifra suya.
> | `PASS 494 files` | `composer run pint:test` |
> | `[OK] No errors`, 716 ficheros | `composer run stan` (nivel 7) |
> | `0 imports en 0 ficheros, de 723` | `php tools/imports-de-facades.php --dry-run`, y **detrás `pint:test`** |
>
> > **`RequisitosController` NO entra en la lista curada de Pint, y se dice con su motivo**:
> > esta tanda lo tocó, pero es legado con tabuladores y formatearlo enterraría el diff bajo
> > un fichero reescrito entero. Es la misma decisión que tomó la tanda de las estaciones con
> > este mismo fichero. Los dos controladores **nuevos** sí entran, y **gratis**: están
> > escritos ya en el estilo de Pint, así que apuntarlos no reformatea ni una línea de legado.
>
> ### Lo que queda abierto — y lo primero es de Joseth
>
> 1. ~~**¿Qué pasarela tiene contratada cada colegio?**~~ — **CONTESTADA el 20 sep 2026**:
>    *«cada colegio maneja su propio sistema de cartera y contabilidad, unos ni tienen pagos
>    en línea. Eso no importa.»* Llevaba abierta desde `INVESTIGACION-MATRICULAS.md` §10.2 y
>    se citaba como bloqueante: **no lo era**. La «pasarela» de este módulo es sólo la del
>    **formulario** —el checkout del 19 sep—, y el colegio sin credenciales ya contesta 404.
>
>    **Y de ahí sale lo que sí importa: la PANTALLA 12 no se puede construir aquí.** Promete
>    *«estado de cuenta de la familia entera, plan de pensiones y cobro»*, y medido en el
>    esquema **no hay ni una tabla** de cartera, pagos, saldos, recibos ni pensiones: lo
>    único que esta API sabe de deuda es `alumnos.pazysalvo`, un `tinyint`. La contabilidad
>    vive fuera. *Se dice para que el front no la construya contra un dato que no existe.*
> 2. **¿El contrato y el pagaré se quedan en papel?** Decide si hace falta proveedor de
>    firma, y **es lo único que queda de la fase 4**.
> 3. **¿Quién admite: el rector solo o un comité?** Hoy `puedeDecidirAdmision` es **secretaría
>    o superusuario, y es provisional** — va dicho en el propio método para que dentro de un
>    mes no se lea como una decisión que alguien tomó.
> 4. **`tools/requisitos-de-matricula.php` sigue sin correrse en `lal`**, que es el colegio
>    que de verdad usa esto.
> 5. **Las pantallas 02, 04, 05, 06 y 15** (`myvc_front`) y **las doce de estaciones**
>    (`myvc_flutter`). El backend está; sin ellas no lo usa nadie.
> 6. **El vocabulario de `estado` MIGRADO** en los dieciséis sigue pendiente. Lo que se paga
>    hoy es que **ninguna ruta pueda escribir fuera de la lista**, no que lo escrito antes se
>    normalice.

> ## ✅ LA FASE 2, VISTA FUNCIONANDO CON DATOS DE VERDAD (20 sep 2026, noche)
>
> **Ya no es «la lógica es correcta»: es el papel impreso.** Lo condujo `myvc-front-a7` contra el
> docker **sin inyectar nada**, en dos grupos de **2025** —año en `porcentaje`, 60.825 notas de
> indicador y 20.022 sin calificar, que es el escenario del 43 tal cual—.
>
> | grupo | lo que tenía | lo que imprimió |
> |---|---|---|
> | Quinto, periodo 1 | 10 asignaturas con plan, 3.997 notas, **todas calificadas** | 0 grises, 7 con color, «corte · 100 % evaluado» |
> | Segundo, periodo 2 | sólo NAT con plan, **64,7 % sin calificar** | **9 grises sin `%`** y NAT con su nota y su **81** al lado |
>
> **El segundo es el papel que todo esto vino a arreglar**: nueve asignaturas que no han reportado
> salen **calladas** en vez de en rojo, y la que sí reportó enseña su nota con el porcentaje que le
> da su tamaño. Y el invariante se cumple en Quinto —parcial y acumulada idénticas en las diez—,
> que es lo que acota la §7 del 43; está anotado allí con su salvedad.
>
> > **⚠️ LA SEGUNDA TANDA CAYÓ EN EL PERIODO SUCIO, y se salvó por dónde cayó cada cosa.**
> > «Segundo, periodo 2» es el **grupo 96 del periodo 31**, que es justamente donde el censo de la
> > §7 encuentra las **616 discrepancias** entre la definitiva guardada y la recalculada —los otros
> > tres periodos de 2025 dan cero—. Lo vio `myvc-front-a7` al leer el censo, no al medir.
> >
> > **Sus nueve veredictos valen enteros**, y el motivo es concreto: son todos sobre **el papel**
> > —cuántas casillas grises, si la que imprime enseña su `%`, si alguna gris enseña un cero— y
> > **ninguno compara la parcial con la acumulada**, que es lo único que el periodo 31 envenena. El
> > invariante se midió en **Quinto, periodo 30**, que es de los limpios.
> >
> > *O sea que la medición buena y el periodo sucio se cruzaron y no se tocaron por suerte, no por
> > diseño.* Queda escrito para el siguiente: **para comparar números, el periodo 30; el 96/31
> > sirve para mirar el papel y no para cuadrar cifras.**
>
> ### Y el caso real SÍ se podía medir aquí: había que mirar otro año
>
> Este documento llegó a decir que el escenario real no se veía en el docker y que había que
> esperar a que un colegio eligiera algo. **Falso.** 2026 es el año trasteado, pero **2025 está en
> `porcentaje` y a medio calificar**. *Los dos nos quedamos mirando el año en curso porque era el
> que estaba roto* — y el año en curso era justamente el único que no servía.
>
> ### Tres trampas del camino, medidas al conducirlo
>
> 1. **`POST auth/login` deshace la preparación del año.** Medido aislado por `myvc-front-a7`:
>    `PUT years/useractive/8` deja la fila en 2025 y **al entrar vuelve a 2026**. Preparar el año
>    por fuera y entrar después **no sirve, y no avisa**: la primera medida salió con las diez
>    casillas grises y **se leía como un fallo del semáforo**.
>
>    > **RESUELTO, y el mecanismo es explícito y está documentado en su propio sitio.** Es
>    > `App\Services\Login::ponerEnElPeriodoActual()` (`app/Services/Login.php:194`, el `UPDATE`
>    > en el **216**), que llama `entrar()` en cada login como `'cambia_anio' => …`:
>    >
>    > ```php
>    > if ($anio->id != $fila->year_id || $periodo->id != $fila->periodo_id) {
>    >     DB::update('UPDATE users SET periodo_id=? WHERE id=?', [$periodo->id, $fila->id]);
>    >     return (int) $periodo->id;
>    > }
>    > ```
>    >
>    > Su docblock lo dice con todas las letras: *«Si el usuario se quedó en el periodo de otro
>    > año, se le pasa al actual»*. Así que **no es que `useractive` no mande sobre `actual`: es
>    > que el login te devuelve al actual a propósito, y además te lo dice** en la respuesta. Lo
>    > encontró `myvc-front-a7`, y hay dos pruebas independientes —su medición leyendo
>    > `users.periodo_id` por SQL antes y después de un login, y este código—.
>
>    > ### ⚠️ Y POR QUÉ NO LO ENCONTRÉ YO: UN `| head -8`, Y LA REGLA SE ESCRIBIÓ ESTA MAÑANA
>    >
>    > Al buscar el escritor desde este lado concluí que *«el único es `ContextoDeUsuario:42`»* y
>    > que **no había otro**. La explicación que me ofrecieron —que el nombre del campo vive dentro
>    > de una cadena SQL y el `grep` no lo alcanza— **es falsa y conviene no quedarse con ella**:
>    > `periodo_id=?` casa perfectamente con el patrón que usé.
>    >
>    > Lo que pasó es que la orden acababa en **`| head -8`** y devolvía **14 líneas**.
>    > `Services/Login.php` era **la línea 14**. Recontado sin truncar:
>    >
>    > ```bash
>    > grep -rn "periodo_id\s*=" app/Http/Controllers/*.php app/Services/*.php \
>    >   | grep -iE "user|->save|UPDATE users" | wc -l      # 14, no 8
>    > ```
>    >
>    > Es **la cuarta forma en que un detector miente**, que está en `CLAUDE.md` desde `47553e8`
>    > —el commit que era `HEAD` cuando empezó esta sesión— y que dice literalmente *«un número se
>    > cuenta con `wc -l`, y la lista se enseña aparte; nunca de una orden que acabe en `| head`»*.
>    > Y es la forma más cara de las cuatro por lo que aquel texto ya avisaba: **la salida sale
>    > plausible y del tamaño que esperabas**, así que nada te invita a mirar.
>    >
>    > *Un aviso escrito no protege solo; sólo protege el día que alguien hace lo que dice* — y
>    > aquí no lo hizo quien lo tenía delante desde el primer minuto de la sesión.
>
> 2. **El `periodo_a_calcular` de la URL no elige el periodo**: sale de la fila de `users`. Pedir
>    el 2 y recibir el 1 devuelve **el papel de otro periodo y con pinta de correcto**.
> 3. **Un login colgado es `throttle:login`** (`routes/api/auth.php:36`), no la contraseña.
>    Comprobado: lo llevan las ocho rutas de login del fichero.

> ## 🟠 LA FASE 2 NO CALCULA EN MODO `promedio` — REAL, PERO NO ES BLOQUEANTE (20 sep 2026)
>
> ### ⚠️ ESTA CASILLA SE ESCRIBIÓ COMO «BLOQUEANTE DE DESPLIEGUE» Y ERA FALSO. LA CORRECCIÓN VA PRIMERA
>
> **El mecanismo es real y está bien medido. La población NO: no hay ningún colegio en
> `promedio`.** Lo preguntó Joseth —*«todos los colegios hasta ahora sólo han creado notas con
> modalidad de porcentaje»*— y tenía razón. Medido después de que lo preguntara:
>
> ```
> id  año   reparto_subunidades  modelo_evaluacion  actual  updated_at
>  1  2018  porcentaje           ponderado            0     2025-06-23
>  …      … los ocho iguales …
>  8  2025  porcentaje           ponderado            0     2026-09-16
>  9  2026  promedio             competencias         1     2026-09-20 00:18:37   <-- HOY
> ```
>
> La columna es `enum('porcentaje','promedio') NOT NULL DEFAULT 'porcentaje'` y crear un año
> **lo copia del anterior** (`YearsController:248`), así que `promedio` no sale solo: lo puso
> alguien, **hoy a las 00:18** y junto con `modelo_evaluacion = competencias`. *Es una sesión de
> desarrollo probando las dos cosas nuevas del mes sobre la copia compartida.*
>
> > **Por qué camino entró, NO se sabe — y esta casilla llegó a decir que sí.** Decía *«no por la
> > API, o sea a mano en la base»*, deducido de que no hay ninguna auditoría del cambio y de que
> > la última `years/guardar-cambios` de ese año es del 18 sep. **La segunda mitad es cierta y la
> > primera era inventada**: medido después, `years.updated_at` **no lleva `ON UPDATE
> > CURRENT_TIMESTAMP`** (`EXTRA` vacío en `information_schema`), así que un `UPDATE` a pelo
> > **no lo habría movido** — y está movido. O sea que lo escribió algo que sí mantiene
> > timestamps: Eloquent o una ruta que no audita. *«No hay auditoría» prueba que esa ruta no
> > audita, no que no hubiera ruta.* La conclusión no cambia —no es la configuración de ningún
> > colegio— pero el camino no se sabe y no se finge saberlo.
>
> **Lo que esto cambia y lo que no.** El fallo sigue existiendo y el arreglo sigue haciendo falta
> el día que un colegio elija `promedio` —que es una opción que la pantalla ofrece—. Lo que se cae
> es la urgencia: **no hay nada que bloquear**, porque no hay ningún colegio al que esto le pase.
>
> **Y cómo se coló: contando bien y concluyendo de más.** La consulta que dio «el único año en
> `promedio` es el año en curso» es correcta y se reprodujo por dos lados. Lo que nadie miró es
> **por qué** ese año estaba así, y de una medición cierta sobre la copia de desarrollo se dedujo
> una afirmación sobre dieciséis colegios. *Es la trampa de siempre de este repo, en su forma más
> cara: el detector no falló, la pregunta que se le hizo no era la que hacía falta.* Lo cazó
> Joseth con una frase sobre el negocio que ninguna consulta iba a dar.
>
> ### Lo que sigue siendo cierto, y por eso la casilla no se borra
>
> Lo encontró `myvc-front-a7` imprimiendo el PDF contra el docker con `main` en `9185879`, y está
> reproducido por separado desde este lado.
>
> ### Lo que sale por pantalla
>
> `PUT boletines/detailed-notas-group/113`, periodo 1: **120 de 120** pares vienen con
> `nota_parcial: null` **y** `cobertura: null`, mientras `nota_asignatura` trae 42.3, 50, 34, 10…
> En el papel: diez asignaturas, **diez rayas**, la columna del `%` **vacía entera**, sin rótulo
> de corte y con «**0 asignaturas perdidas**». *La hoja que ayer decía quién va en rojo hoy no
> dice nada — y se firma y se archiva.*
>
> ### La causa, medida en la base
>
> ```
> año         reparto_subunidades   subunidades   con_peso   en_cero
> 2018–2025   porcentaje                 33.113     33.091        22
> 2026        promedio                    2.205          0     2.205
> ```
>
> **El único año en `promedio` es el único con los pesos a cero, y es el año en curso.** Ni una
> sola subunidad de 2026 tiene peso. *(Medido por los dos lados con consultas distintas:
> `myvc-front-a7` da 2.233/2.232 y esto 2.205/2.205 —entra por `grupos → asignaturas → unidades`—
> y la cifra que decide, `con_peso = 0`, es idéntica en las dos.)*
>
> ### El mecanismo, que ya estaba escrito en el propio 43
>
> La §Fase 1.bis dice que `calculoAlumnoNotas` **nunca ha pasado por `RepartoDeLaNota`**: pesa
> `s.porcentaje` crudo. En `promedio` ese campo no se usa —el peso lo pone `1/n`— así que se
> queda en 0. Entonces `peso_total = Σ(porcentaje_unidad × 0) = 0`, y por la regla del propio
> helper `peso_total = 0 → cobertura null`. El front lee `null` como «no hay nada que calificar»,
> lo pinta gris y **deja de imprimir la nota**, que es justo lo que mandó D2.
>
> **Y va más hondo de como se descubrió**: el **numerador** del helper también se acumula con
> `porcentaje_subunidad`, así que en `promedio` su `nota_asignatura` interna sale 0 igual. No es
> que la Fase 2 calcule mal en `promedio` — **es que no calcula nada calculable**.
>
> ### Lo que esto enseña, y es más grande que el fallo
>
> **`LaParcialYLaCobertura` asume el modo `porcentaje` y eso no está escrito en ningún sitio** —ni
> en su cabecera, ni en la §Fase 2 del 43, ni en la casilla que la dio por cerrada—. Cada pieza
> hace lo correcto por separado; juntas vacían el papel. *Una premisa que no está dicha no la
> puede comprobar nadie*, y por eso el arreglo no es ninguno de los dos parches hasta que la
> premisa esté escrita.
>
> ### El censo de los dieciséis: DESCARTADO por Joseth, y ya no hace falta
>
> Esta casilla llegó a decir que había que contar cuántos de los dieciséis están en `promedio`
> antes de desplegar. **Joseth dijo que no quiere censar los colegios**, y con su respuesta sobre
> el negocio el censo además **dejó de hacer falta**: si ningún colegio ha usado nunca otra cosa
> que `porcentaje`, no hay nada que contar. *El dato que habría costado un barrido por diecisiete
> instalaciones lo contestó quien conoce el producto, en una frase.*
>
> ### Las salidas, y por qué ninguna se toma sin Joseth
>
> | | qué cambia |
> |---|---|
> | Que la cobertura use el peso de `RepartoDeLaNota` cuando el año está en `promedio` | es el arreglo de fondo, y cambia lo que significa el número en **las cinco pantallas que ya lo publican** |
> | Que `peso_total = 0` con unidades existentes signifique `0` y no `null` | más barato, pero convierte «no hay plan» en «no se evaluó nada», que son cosas distintas y la pantalla las pinta distinto |
> | ~~**Defensa del front**: si en el grupo entero no hay ni una cobertura medible, volver a imprimir la acumulada~~ | **DESCARTADA POR JOSETH el 20 sep.** El número correcto tiene que venir de la API y una red en el front taparía el síntoma |
>
> ### Y con esa decisión, el orden del despliegue es la ÚNICA protección
>
> Joseth descartó la defensa del front, así que **no hay nada en `app2` que amortigüe esto**: el
> día que la Fase 2 llegue a un colegio en `promedio`, ese colegio se queda sin semáforo. *El
> arreglo tiene que estar hecho antes, no en paralelo.* Es lo que convierte esta casilla de
> «hallazgo» en «bloqueante de despliegue» — y la Fase 2 ya está en `main`, así que lo que la
> retiene hoy es únicamente que nadie ha desplegado.
>
> **Lo que el front SÍ dejó hecho** (`myvc-front-a7`, en `RELEVO-SEMAFORO.md` §9): el guion
> `conducir-semaforo-con-corte.mjs` mide ya contra la API de verdad (`MYVC_SIN_INYECTAR=1`) y
> **caza la hoja en blanco**, diagnosticándola en el terminal y mandando mirar
> `years.reparto_subunidades`. Con los campos inyectados los doce veredictos siguen en verde: *con
> datos correctos el front hace lo correcto*, así que el fallo es entero de este lado.
>
> ### Lo que NO es
>
> **No está desplegado.** Vive en `main`, y `main` lleva 42 commits sin subir a `origin`. El papel
> en blanco sólo existe en el docker de `myvc-front-a7`. Y Joseth ya dijo que desplegará **a un
> solo colegio y subiendo front y back a la vez**, así que el escenario de «la API llega sola» no
> va a pasar.

> ## 🔴 LA FASE 2 MIENTE EN EL BOLETÍN INDEPENDIENTE — MEDIDO AL FUNDIRLA (20 sep 2026)
>
> **Espera decisión de Joseth. No es una deuda vieja: es la fase que se acaba de fundir dando un
> número falso en un caso concreto, y el número es nuevo.**
>
> `BoletinIndependienteController` tiene **dos siembras vivas** —`sembrarLasCasillasDeSusUnidades`
> (`:2363`) y `sembrarLasNotasQueFaltan` (`:2431`)— que hacen
> `INSERT INTO notas (… nota …) SELECT s.id, ?, s.nota_default, …`, o sea que crean la casilla
> **con el valor por defecto de la subunidad en vez de `NULL`**. Es el bug de origen del 43, y el
> commit de la Fase 0 censó *«las tres siembras vivas»* cuando son **cinco**: lo levantó `8myvc-d7`
> y quedó sin contestar.
>
> ### Lo que no estaba medido, y es lo que lo cambia de sitio
>
> La deuda venía contada como *«además le ensucia la cobertura»*. Medido en la copia de desarrollo:
>
> ```sql
> SELECT COUNT(*) FROM subunidades WHERE deleted_at IS NULL AND nota_default IS NULL;  -- 0
> SELECT COUNT(*) FROM subunidades WHERE deleted_at IS NULL AND nota_default = 0;      -- 32.288
> SELECT COUNT(*) FROM subunidades WHERE deleted_at IS NULL AND nota_default > 0;      --  4.445
> ```
>
> **Ninguna subunidad tiene `nota_default` nulo**, así que esas dos siembras **nunca** escriben
> `NULL`: toda casilla que crean nace con una `nota` no nula. No es un caso de borde, es el 100 %.
>
> ### La consecuencia, que es nueva desde hoy
>
> `LaParcialYLaCobertura` cuenta como evaluada toda fila con `nota !== null` —y eso **es
> correcto**, porque el `0` que teclea un docente es una nota—. Pero una casilla sembrada tiene
> `nota_id` no nulo **y** `nota` no nula, así que para un alumno con boletín independiente
> sembrado sale **`cobertura: 1`** y **la parcial igual que la acumulada**.
>
> O sea: la pantalla afirma **con confianza y con un número al lado que se evaluó todo**,
> justamente en los alumnos donde no se evaluó nada. Antes el semáforo decía BAJO sin dar razones;
> ahora da una razón y es falsa. *Para esos alumnos el 43 sale al revés de lo que pretendía*, y la
> Fase 2 es lo que lo hace visible — el fallo es de la siembra, no del helper.
>
> ### El volumen, medido después — y NO es lo que sostiene el argumento
>
> Lo midió `8myvc-d7` a petición de esta casilla: `bol_ind_periodos` tiene **11 filas**, **2** con
> `aplica = 1`, y son **2 alumnos**. Y de esos dos, mirando su año:
>
> ```
> alumno 1109 -> 2025, porcentaje   <- aquí la cobertura SÍ sale 1
> alumno 1055 -> 2026, promedio     <- aquí sale null, tapado por el bloqueante de arriba
> ```
>
> O sea que **hoy, en esta copia, el fallo se ve en UN alumno**, no en dos. *Se dice porque esta
> casilla llegó a decir «dos» y el dato exacto es uno.*
>
> **Y por eso el argumento NO es el volumen, que hoy es ridículo: es que es DETERMINISTA.** No es
> que a veces salga mal — es que para **toda** casilla creada por esas dos rutas la cobertura sale
> 1 **siempre**. Un fallo que ocurre el 100 % de las veces sobre un alumno se arregla; uno que
> ocurre a veces sobre muchos se discute. Aquí es el primero, y el módulo es nuevo —fase 6 del
> 19—, así que la población sólo puede crecer.
>
> ### Y «nunca escriben NULL» es cierto HOY POR LOS DATOS, no por construcción
>
> Lo corrigió `8myvc-d7`: el esquema dice `nota_default int DEFAULT NULL`
> (`database/schema/mysql-schema.sql:525`) y `PlantillaNotasController:296-300` **acepta
> explícitamente `null`** al editar la plantilla. La columna admite nulos y hoy no hay ninguno.
> O sea que ese mismo código **sería correcto para una subunidad con `nota_default` nulo** e
> incorrecto para las 36.733 restantes: correcto por accidente y según el dato. *Por eso el
> arreglo es escribir `NULL` literal y no apoyarse en que `nota_default` nunca sea nulo, que sería
> cierto hoy y falso el día que un colegio ponga uno.*
>
> ### Lo que NO se midió, y por qué no se midió aquí
>
> **Cuántos de los 82.975 ceros de `notas` los tecleó un docente y cuántos los sembró esto.** Sin
> esa separación el número no dice nada: ese censo es del §7 del 43 y su dueño es `8myvc-79`.
> *Una cifra sobre la población equivocada no falla, contesta.*
>
> ### Las dos salidas, para que se elija con el precio delante
>
> | | qué cuesta |
> |---|---|
> | **Sembrar `NULL`** en las dos siembras | arregla el origen; hay que mirar qué lee hoy esas casillas esperando un número, y si alguna pantalla cuenta filas en vez de valores |
> | **Dejarlo y que la cobertura lo absorba** | no toca nada, pero deja la pantalla afirmando 100 % de cobertura sobre casillas que nadie calificó |
>
> No se toca ninguna de las dos sin que Joseth conteste: la primera reescribe filas de `notas`.

> ## ✅ LA PARCIAL EN EL BOLETÍN — FASE 2 DEL 43, **FUNDIDA** (20 sep 2026, `e14e675`)
>
> **Fundida en el ÁRBOL PRINCIPAL sobre `main`**, viniendo de `feat/la-parcial-en-el-boletin`
> (`057f89b`), que la escribió y la midió `8myvc-d7`. El ensayo de la fusión se hizo antes con
> `git merge-tree --write-tree` **para no tocar el índice compartido del árbol principal**, que es
> donde un merge ajeno abierto haría fallar el tuyo sin dejar rastro.
>
> **Router recontado en el ÁRBOL PRINCIPAL tras fundir: 634**, igual que antes — esta rama no
> añade ninguna ruta. Se cuenta igualmente porque el contador de `CLAUDE.md` **se cuenta y no se
> hereda**, y hoy han entrado varias tandas: *«no añade rutas» es una afirmación sobre esta rama,
> no sobre el árbol en que cae.*
>
> ### La suite NO se volvió a correr, y el motivo es más fuerte que correrla
>
> `main` sólo se había movido en **documentación** desde la base de la rama (`CLAUDE.md` y tres
> ficheros de `docs/`), así que `git diff 057f89b..HEAD -- app/ tests/` sale **vacío**: el código
> que hay hoy en `main` es **byte a byte** el que dio las 399 passed. Volver a correrla habría
> medido el mismo árbol y costado seis minutos de una máquina que comparten ocho sesiones. *Una
> corrida repetida sobre un árbol idéntico no es una segunda medición: es la misma.*
>
> ### Y lo que sí se comprobó aquí, porque la prueba que traía no lo cubría del todo
>
> La rama ofrecía *«cero cambio de comportamiento en `Asignatura.php`»* con la prueba de que **no
> se movió ninguna instantánea de la planilla**. Eso por sí solo no distingue *«no cambió»* de
> *«no lo mira nadie»*, así que se leyó el diff: las dos expresiones que se van al helper son
> **idénticas carácter a carácter** a las que había, y el bucle, los `(int)` del peso y el
> `!== null` no se movieron. Se sostiene por las dos vías.
>
> **El front ya la fundió esta mañana en `myvc_front`; lo que faltaba era la API**, y la §Fase 2
> del [43](43-lo-que-todavia-no-se-ha-calificado.md) decía justo lo contrario —*«no necesita
> desplegar la API»*—. Lo destapó `myvc-front-a7` midiendo a qué endpoint llama de verdad su
> pantalla: `PUT boletines/detailed-notas-group/{grupo}`, con el formato **fijo en el cliente**
> (`semaforo-grupo.ts:73`, `FORMATO = 1`), o sea el mismo en los dieciséis. Y ese camino
> —`Grupo::detailed_materias_notafinal` + `notas_finales`— **no era ninguno de los siete del censo
> de la §Fase 1.bis**: es un OCTAVO calculador.
>
> | | |
> |---|---|
> | Nuevo | `app/Support/LaParcialYLaCobertura.php` |
> | Tocados | `app/Models/Asignatura.php` (sólo las dos expresiones finales), `Informes/BoletinesController`, `Informes/NotasActualesAlumnosController` |
> | Prueba | `tests/Contrato/LaParcialEnElBoletinTest.php` |
> | Rutas · columnas · migraciones · consultas nuevas | **0 · 0 · 0 · 0** |
> | Instantáneas movidas | **3**, y el diff entero son **6 líneas**: dos claves por fichero. `nota_asignatura` intacta, que es la prueba de que la acumulada no se movió |
> | `composer.json` | **no se tocó**: los dos ficheros caen bajo `app/Support` y `tests`, que ya van como directorios enteros |
>
> **Tests: 3 failed → 399 passed (2.741 aserciones)** con el subconjunto ampliado de 26 clases
> (`--testsuite=Contrato`), y los tres rojos eran **las tres instantáneas previstas**, regeneradas
> y miradas enteras. Del fichero nuevo: **5 passed (32 aserciones)**, y **los dos controles se
> vieron en rojo** — la guarda del `nota_id` tira 2 casos y `!== null` → `> 0` tira 1.
> `composer run stan`: **`[OK] No errors`, 710 ficheros**. `composer run pint:test`: **`PASS`,
> 488**.
>
> ### El subconjunto lo eligió Joseth, porque la herramienta no supo
>
> `tools/tests-que-tocan.py` salió con **2**: no sabe mapear `Asignatura.php` ni el helper porque
> no son controladores enrutados. Se le pusieron las dos cifras delante —26 clases y ~6 min contra
> la suite entera y ~23— y eligió el subconjunto ampliado, que son las 17 que mapeó más las 9 que
> cubren los seis lectores de `calculoAlumnoNotas`.
>
> ### El censo decía TRES y son CINCO, y el detector mintió sin chirriar
>
> Los que pasan por `Grupo::detailed_materias_notafinal` son **cinco**. El tres salió de un
> `grep … | head`, o sea de **una lista truncada por el propio comando que la producía**: plausible,
> del tamaño esperado y sin nada que invitara a mirar. Lo recontó `8myvc-79` y ya está escrito en
> `CLAUDE.md` como la cuarta forma en que un detector miente. Quién los gana y por qué no los otros
> tres está en la tabla de la §Fase 2 del 43 — y el único candidato de verdad que queda fuera es
> `Nota::alumnoPeriodoDetalle`, porque **no lo pide ninguna pantalla todavía**.
>
> ### Lo que queda por hacer
>
> 1. ~~**Fundir**~~ y ~~**recontar el router**~~ — hechas: `e14e675`, router **634** en el árbol
>    principal.
> 2. ~~**Avisar a `myvc-front-a7`**~~ — avisado con los tipos de la instantánea delante. Va a
>    volver a correr `conducir-semaforo-con-corte.mjs` **sin la interceptación** contra el docker;
>    si los doce veredictos salen igual con los datos de verdad que con los inyectados, la fase 2
>    está encendida y medida. **Pendiente el resultado, que es de él.**
> 3. **PENDIENTE — hablar con Joseth del orden del despliegue**: el front aguanta sin los campos
>    —`hayCorte()` pregunta por `undefined`, así que un colegio sin desplegar imprime el papel del
>    19 de septiembre byte por byte—, pero la API tiene que llegar antes que nada que los espere.
>
> ### Los tipos que se le pasaron al front, y el matiz que importa
>
> Lo que registraron las tres instantáneas, tal cual:
>
> ```
> boletines-detailed-notas-group.json   cobertura "int|null"   nota_parcial "float|int|null"
> boletines-detailed-notas.json         cobertura "int|null"   nota_parcial "int|null"
> muestreo-notas-actuales-alumnos.json  cobertura "int"        nota_parcial "float|int"
> ```
>
> **Que `cobertura` salga `int` no es que sea entera: es que en el seed sólo salen 0 y 1**, y sin
> `JSON_PRESERVE_ZERO_FRACTION` `json_encode` manda `0.0` como `0` y `1.0` como `1`. Con un
> colegio a medio calificar llegará `0.35`. Es lo mismo que le pasa a `nota_asignatura`, que lleva
> años siendo `float|int`. **Quien lo lea compara valores, no tipos** — una guarda estricta de
> tipo en el front se rompería justo en los extremos, que son los casos más frecuentes.
>
> **Y los dos `null` no son ceros**, que es el bug entero del 43. Los cuatro casos de borde,
> **medidos llamando al helper e imprimiendo el JSON** —no deducidos leyéndolo—:
>
> | caso | `nota_parcial` | `cobertura` |
> |---|---|---|
> | sin plan (ninguna fila en `notas`) | `null` | `null` |
> | plan con peso, **nada** calificado | `null` | **`0`** |
> | una de dos calificada con 40 | **`40`** | `0.5` |
> | todo calificado | `35` | `1` |
>
> **El caso mixto existe y es legítimo**: `cobertura: 0` con `nota_parcial: null` es *«hay plan y
> no se ha evaluado nada»*, distinto de *«no hay plan»*. Un `0` en la parcial afirmaría que le fue
> mal, que es justo la mentira que esta fase viene a quitar.
>
> **La tercera fila es para qué existe todo esto.** Ese alumno lleva `nota_asignatura` = **20** —la
> mitad del periodo sin evaluar cuenta como cero— y el semáforo lo pinta BAJO. Su parcial es
> **40**, con cobertura 0,5: *no va mal, va a la mitad*. Y la cuarta fila enseña el invariante que
> lo cierra: **con todo calificado, parcial y acumulada son el mismo número** (35 y 35), o sea que
> la parcial sólo existe como cifra distinta mientras falte algo.
>
> **Y ahí se ve el matiz de los tipos sin tener que creérselo**: `40` y `1` salen **enteros** en el
> JSON y `0.5` sale **float**, en el mismo campo y en la misma respuesta.
>
> ### Lo que queda abierto, y es de Joseth
>
> - **La parcial no cuadra con la acumulada impresa al lado en modo `promedio`**, porque el
>   numerador es el del bucle y la `nota_asignatura` publicada es la guardada. Es la discrepancia
>   preexistente de los dos calculadores —14 pares de 99, hasta 42,3 puntos sobre 0–50—, ahora con
>   un número al lado que la deja ver. **Unificarlos sigue siendo una decisión suya y no un
>   arreglo.**
> - **Pint**: los tres ficheros tocados que no están en la lista curada —`Asignatura.php` y los dos
>   controladores de `Informes/`— **no se han añadido, a propósito**: van con tabuladores y
>   formatearlos enterraría el diff. Es la misma decisión que la Fase 4 con sus cinco.

> ## ✅ LAS ESTACIONES EN LA APP — NUEVE RUTAS, **FUNDIDA** (20 sep 2026)
>
> **FUNDIDA en `98dfa73`. Router en 634, RECONTADAS en el árbol principal sobre `main` y
> después de fundir** — y coincidieron con las 634 que esta misma casilla había contado en
> `.worktrees/est`, que es la única forma de saber que coincidía.
>
> Escrita en `.worktrees/est` con base `simonbolivar_testing_est`. Que alguien del personal atienda una estación del día de
> matrículas **desde el teléfono, sin web**: que al cerrar su paso la persona aparezca en la
> estación siguiente, y que se pueda buscar a cualquiera para ver en qué va. Es la fase 2 del
> proceso de admisión, encima de la fase 1 que entró esta mañana
> ([44](44-el-dia-de-matriculas.md)). El contrato y lo que la construcción destapó están en
> [46](46-las-estaciones-en-la-app.md).
>
> **Router en 634, recontado en el árbol principal.** Son **nueve** sobre las 625 de `main`,
> todas `auth.personal` y familia nueva `estaciones/`.
>
> ```
> GET  estaciones                        el recorrido del colegio y cuántos esperan
> GET  estaciones/huella                 ~300 bytes: ¿cambió algo?
> GET  estaciones/alumno/{id}            la ficha; con ?estacion= dice si puede atenderlo
> GET  estaciones/codigo/{codigo}        lo mismo, por el QR del formulario
> GET  estaciones/{nro}/cola             los que me llegan
> PUT  estaciones/{nro}/marcar           cumple | observado | devuelto(motivo)
> PUT  estaciones/{nro}/enviar-a/{dest}  el salteado: registra el intento, NO escribe el paso
> POST estaciones/{nro}/nota             una nota en CUALQUIER estación, la tuya o no
> PUT  estaciones/nota/{id}/resuelta     LA NOVENA, que el contrato de ocho no tenía
> ```
>
> ### El contrato decía OCHO y son NUEVE, y sin la novena dos columnas nacen muertas
>
> El 46 §3.3 describe **quién puede dar por resuelta una nota pendiente** y `estaciones.md`
> §2.10 describe **el botón que lo hace**, con el texto que enseña apagado. Ninguna de las
> ocho escribía `resuelta_por` ni `resuelta_at`. *Es `profesores.tono` por sexta vez en un
> mes, y esta vez dentro del documento que lo cita como error.* Se cuenta y se dice.
>
> ### Lo que la construcción destapó, y ninguna lectura del código habría visto
>
> 1. **La huella necesita TRES cifras y el 34 pedía dos.** `timestamp` tiene precisión de
>    **segundo**: una nota escrita en el mismo segundo que el cierre del paso anterior **no
>    mueve el `MAX`** y tampoco mueve `n`, porque una nota no cambia quién espera. Sería
>    invisible hasta el siguiente cambio de la cola — y si no hay ninguno, para siempre. La
>    tercera es `notas`. *Cuando el reloj no puede, cuenta.*
> 2. **La primera estación hacía desaparecer a quien reabren.** La cola de la primera se
>    definió como «los que ya entraron al recorrido», y «entró» se escribió como *«tiene algo
>    cerrado»*. Al reabrirle un paso a alguien, **desaparecía de todas las colas**: justo la
>    desaparición silenciosa que este módulo existe para impedir. El marcador correcto es
>    `updated_by`, que sobrevive a reabrir y que la fila creada por `AlumnosController` deja
>    en NULL.
> 3. **`matriculas` no tiene `year_id`.** El año viaja por `grupos.year_id`. Aquí costó un
>    `Unknown column`, que es la forma barata de descubrirlo; la cara habría sido que la
>    columna existiera y filtrara por otra cosa.
> 4. **El rol `Secretario` no existe en la base de tests** (11 roles, no 12) aunque sí en
>    desarrollo. Por eso el permiso pregunta **por nombre** y no por `role_id`: `roles` es una
>    tabla por colegio y no está garantizado que las dieciséis tengan las mismas filas.
>
> ### Los dos hallazgos anteriores los encontraron los TESTS, no una revisión
>
> Los dos primeros se escribieron en verde, fallaron al correr y **el código estaba mal, no el
> test**. Y los cinco controles se vieron en rojo, cada uno cazado por el test que lo nombra:
>
> | lo que se rompió | qué cayó |
> |---|---|
> | la huella cuenta sólo las notas de su estación | el de la nota en la 5 |
> | la cola mira `estado` en vez de `cerrado_at` | el de la pantalla vieja |
> | `devuelto` cierra el paso | el de devolver |
> | la estación se cierra con uno de dos requisitos | el de uno de dos |
> | reabrir no limpia la firma | el de reabrir |
>
> ### Lo que mueve
>
> **Tres instantáneas y NO la cuarta**, comprobado y no supuesto: `rutas.json`,
> `guards-por-ruta.json` y `guard-por-familia.json` (`estaciones: 9 de 9`).
> `familias-que-nunca-entran-en-el-candado.json` **no se toca** —la familia entra con nueve
> guardadas— y `FamiliasQueNuncaEntranTest` sigue en **26**. Tampoco se mueve
> `RutasPreLoginTest::TOTAL_PUBLICAS`: ninguna es pública ni puede serlo.
>
> **Y el candado que el 46 anunciaba NO habla**: avisaba de que `AutorizacionTest` delataría
> `POST …/nota` como «sola entre sus hermanas», pero con la decisión de Joseth las nueve
> llevan el mismo guard, así que no hay excepción que declarar. *Es la decisión funcionando,
> no el candado fallando.*
>
> ### UN CANDADO QUE YA EXISTÍA CAZÓ LA TABLA NUEVA EL DÍA QUE NACIÓ
>
> La suite entera salió con **dos rojos**, y los dos eran
> `CentinelaDeLasTablasDelAnioNuevoTest`: **`envios_estacion` lleva `year_id` y nadie la
> copia al crear un año**. Es exactamente para lo que se escribió ese fichero el 13 sep, y
> la forma cara de ese fallo es la que describe su propia cabecera — *«no rompe nada el día
> que pasa, no deja una línea en ningún log, y se nota en enero»*.
>
> Se declara **con el motivo escrito** en `DATOS_DEL_ANIO`, que es la única forma
> aceptable: copiar esos intentos diría que hubo doce salteados en un año cuyo día de
> matrículas todavía no ha ocurrido. *La salida barata —añadir el nombre y pasar— es
> justo lo que ese test existe para impedir.*
>
> Y su hermana `notas_estacion` **no entra, y no es un olvido**: no tiene `year_id` —cuelga
> de `requisitos_matricula`, que es quien lleva el año—, así que el centinela no la mira.
> La que sí la miraría es **el tercer censo de tablas hijas que ese fichero dice que no
> existe todavía**, y su respuesta sería la misma.
>
> ### Estado — las cifras con la orden que las produjo
>
> | | |
> |---|---|
> | `Tests: 2 failed, 1 skipped, 2592 passed (54.146 aserciones)` · 972,71 s | `php artisan test` (las tres testsuites) en `.worktrees/est`, sobre `766da42`, con `COBERTURA_RUTAS` puesta. **Los dos rojos son el centinela de arriba**, y con él declarado las cuatro clases implicadas dan **92 passed** |
> | `LasEstacionesEnLaAppTest`: **33 passed (325 aserciones)** | `--filter=LasEstacionesEnLaAppTest --testsuite=Contrato` |
> | `PASS 486 files` | `composer run pint:test` |
> | `[OK] No errors`, 708 ficheros | `composer run stan` (nivel 7) |
> | `0 imports en 0 ficheros, de 715` | `php tools/imports-de-facades.php --dry-run`, y detrás `pint:test` |
>
> > **La suite se lanzó desprendida** (`docker exec -d … > /tmp/est-suite.txt`) y con el
> > contenedor comprobado vacío de otras suites **antes** de lanzarla, preguntando **contra
> > qué base** corría cada una y no cuántas había. Y se leyó de la línea `Tests:`, no del
> > código de salida: *una suite sin esa línea no ha terminado, diga lo que diga el exit.*
> >
> > **Y una cosa que se dice en vez de taparse:** entre que la suite arrancó y terminó se
> > tocó el `INSERT` de `escribirElPaso` —dejar que la base ponga el defecto de `estado` en
> > vez de nombrarlo—, así que **esas 2.592 describen el árbol de `766da42` y no el final**.
> > No toca `routes/` ni `database/migrations/`, así que le aplica el subconjunto y no la
> > entera; queda cubierto por las 92 de arriba. *Una medición vale por el árbol contra el
> > que corrió, y el que la publica es quien sabe si el árbol se movió.*
>
> ### Lo que queda abierto — y lo primero es de Joseth
>
> 1. ~~**¿Un superusuario sin el rol `Admin` puede dar por resuelta una nota?**~~ —
>    **CONTESTADO por Joseth el 20 sep 2026: sí.** Se le puso delante con las dos personas que
>    se quedaban fuera (12 superusuarios, 10 con el rol) y la regla queda en **quien la
>    escribió, o superusuario, `Admin`, `Secretario` o `Rector`**. Con `esSuperusuario()` y no
>    con `esAdministrativo()`, para que ensanchar aquel método no ensanche esta puerta sin que
>    nadie lo decida.
>
>    **Y el control destapó que el test escrito para protegerlo no lo protegía**: los diez
>    superusuarios del seed tienen los diez el rol `Admin`, así que la rama nueva queda tapada
>    por la vieja. El test construye el caso, pero en su primera versión **la nota la escribía
>    el mismo usuario que la resolvía**, o sea que entraba por la rama del autor y seguía verde
>    con la línea quitada. Lo delató que **sólo caía uno de los dos tests nuevos**.
> 2. **El vocabulario de `estado` sigue sin migrar en los dieciséis colegios.** La cola no
>    depende de él —se apoya en `cerrado_at`— y la ruta nueva rechaza con 422 lo que no esté en
>    la lista, pero `postAlumno` sigue aceptando lo que le manden las tres pantallas vivas.
> 3. **`aspirante_id` no entra**, con el motivo medido en el 46: un aspirante **no tiene nombre
>    en la base**, así que su cola serían renglones en blanco. Es de la fase 2 del portal.
> 4. **`tools/requisitos-de-matricula.php` sigue sin correrse en `lal`**, que es el colegio que
>    de verdad usa esto. No bloquea el código; dice qué se encuentra un colegio al desplegar.
> 5. **Las doce pantallas de `myvc_flutter`**, que es lo que hace que esto lo use alguien.
>
> ### ⛔ Y una decisión que se RECTIFICÓ el mismo día: el push inmediato NO entra
>
> El 46 §5.3 decía *«ahora»* y `myvc_flutter/docs/estaciones.md` mandaba meter
> `firebase_messaging` en `pubspec.yaml` por ello. **Al ir a construir la mitad del servidor se
> destapó que dos documentos se contradecían**, se le puso delante a Joseth y contestó: *«que
> llegue cuando tenga que llegar, no me voy a complicar con que le llegue de inmediato, por
> ahora no importa»*. Corregido en los dos documentos, tachando y no borrando.
>
> **La contradicción y lo medido, que es lo que se guarda:** `notificaciones.md` §«Cuándo se
> envía» prohíbe **publicar dentro de una petición** y lo prohíbe **con la medición hecha** —*«el
> docente espera a que Google responda»*—, que era exactamente lo que el 46 pedía. Y esa
> prohibición tiene **dos motivos, de los que aquí sólo aplicaba uno, el peor**: el de *volumen*
> no aplica —cerrar un paso es **una** acción por familia, no treinta— y el de *latencia* aplica
> **más fuerte**, porque quien atiende tiene una fila delante y está en un patio con mala señal.
>
> **Y la salida que ninguno de los dos documentos contemplaba: el cron YA entra cada minuto** en
> los dieciséis (`DESPLIEGUE-REFERENCIA.md:1404`). El cuarto de hora es una elección de Laravel
> y **su motivo escrito en `Kernel.php` es agrupar las notas**, que no aplica a una estación. Un
> comando propio en `everyMinute()` daba ≤60 s sin tocar el camino crítico, sin tocar el agrupado
> y sin cron nuevo — era lo recomendado, y la respuesta **contestó al problema en vez de a la
> solución**, que es mejor.
>
> **Lo que quedó SIN MEDIR y hay que medir antes de prometerlo** si alguien retoma la vía de
> contestar-y-publicar-después: `fastcgi_finish_request()` depende del SAPI de **producción**
> (cPanel/LiteSpeed). *Desde el docker sólo se ve el de CLI, donde esa función no existe nunca —
> así que medirlo aquí contesta «no» y esa respuesta no significa nada.*

> ## ✅ QUE EL IMPORTADOR OBEDEZCA — LA FASE 2, CERRADA Y CONDUCIDA (21 sep 2026)
>
> **En `main`.** Router sigue en **623** —esta entrega no añade rutas: todo viaja por el cuerpo de
> las dos que ya existían—. Suite entera: **2.354 passed, 1 skipped** (`--testsuite=Contrato`,
> 1.013 s). El plan entero, en [`45-la-importacion-dinamica.md`](45-la-importacion-dinamica.md).
>
> El ciclo funciona de punta a punta: **el ensayo avisa → la persona decide → el ensayo refleja la
> corrección → la importación la escribe**. Conducido contra el servidor por `myvc-front-41`, y los
> números lo demuestran sin discusión: sin decidir, `sin_cambios 32`; con `usar_id: 1`,
> **`actualizar 24` + `sin_cambios 8`**. *Si el plan no se hubiera movido, meter las equivalencias
> en el traductor habría sido la decisión equivocada.*
>
> ### Lo que la conducción destapó y ninguna revisión de código habría visto
>
> 1. **El servidor se contradecía dentro del mismo JSON**: decía «usé tu corrección 14 veces» y
>    seguía avisando de que esas 14 no se escriben. Eran **dos** fallos —los truncados miraban el
>    valor crudo, y el estado de la matrícula no entraba en el plan porque no vive en `alumnos`—.
> 2. **El ensayo no decía si la importación iba a fallar entera.** Tenía el dato y no la
>    consecuencia, así que la pantalla pintó una hoja que detiene la importación como «Vacía · no se
>    importa», dejó pulsar «Importar 32 alumnos» y la subida contestó 500.
> 3. **Y el defecto se esconde solo cuando coincide con lo que ya había**: sin corregir, el plan
>    dice «a los 32 no les cambia nada» **y es verdad** —el importador adivina Tarjeta de Identidad
>    y esos alumnos ya lo son—. El error no deja rastro ni en la base ni en el plan: **sólo existe
>    en el aviso que la Fase 1 inventó**. *El aviso no sobra ni cuando el plan dice que no cambia
>    nada, y es justo entonces cuando es lo único que hay.*
>
> ### Lo que sigue abierto, y no está en el alcance de hoy
>
> - **Las otras cuatro secciones de decisiones** —`vacios`, `repetidos`, `duplicados`, `hojas`— se
>   aceptan, se guardan y **no se interpretan**. Salen declaradas en `no_aplicadas` con su motivo,
>   para que la pantalla no ofrezca lo que no ocurre.
> - **La Fase 3** entera, y los **otros quince colegios**: todo lo medido es de la copia de
>   desarrollo.

> ## CÓMO SE AVERIGUA ESTE ESTADO — cinco órdenes, y van ANTES que las cifras
>
> **Escrito el 5 sep 2026, después del apagón que mató cinco sesiones a la vez.** Lo que hizo
> falta esa mañana para reconstruir dónde estaba cada una fueron cinco órdenes, y **ninguna
> estaba escrita en ningún sitio**: cada sesión las volvió a deducir. Un documento que dice
> *«esto es lo que hay»* caduca con el siguiente commit; uno que dice **cómo se averigua lo que
> hay** no caduca nunca — y por eso esto va arriba y las cifras van debajo.
>
> > **Y la frase de arriba tiene un agujero que se vio a los cinco minutos de escribirla, así
> > que va aquí y no en una nota al pie.** «Cómo se averigua» sólo cubre lo que **es una
> > medición**. Una **instrucción** —«no toques esto», «no lo commitees», «espera a que X
> > termine»— **no se repone corriendo ninguna orden**, y no envejece a *«hecho»*: **envejece a
> > mentira**. La foto de más abajo llevó las dos y sólo una se defendía sola; lo destapó
> > `8myvc-29` y está contado ahí mismo. **Regla que sale de ahí: una instrucción se escribe con
> > su condición de caducidad al lado** —*hasta que el fichero esté commiteado*, *mientras la
> > rama X exista*—, porque el lector no puede comprobarla y el que la escribió ya no está.
>
> ```bash
> git -C <árbol> branch --show-current && git -C <árbol> status --porcelain   # 1. dónde estoy y qué cuelga
> git log --oneline -1 main                                                   # 2. hasta dónde llegó lo fundido
> git branch --no-merged main --format='%(refname:short)'                     # 3. qué no está en main
> git worktree list                                                           # 4. quién más está trabajando
> docker ps --format '{{.Names}}\t{{.Status}}'                                # 5. si hay con qué medir
> ```
>
> ### Las cinco trampas, una por orden — y las cinco están medidas, no supuestas
>
> 1. **`git status` en el árbol principal no contesta por ti.** El principal lo ocupa otra
>    sesión y **puede no estar en `main`**: el 5 sep estaba en `fix/disponibilidad-si-se-guarda`.
>    Lo que cuelgue ahí sin commitear **es de otro**, y se avisa, no se commitea
>    ([La autorización no se delega](15-la-noche-en-paralelo.md)).
> 2. **`main` no es donde estás.** Las dos cifras que la gente cita —el router y la tanda de
>    migraciones— se leen de un árbol concreto, y los árboles **no coinciden**.
> 3. **`--no-merged` cuenta referencias, no trabajo.** El 5 sep daba **once** ramas y sólo
>    **cuatro** tenían algo vivo: las otras siete iban de **36 a 165 commits por detrás** de
>    `main` y son restos, no cola. La orden que separa una cosa de la otra es
>    `git rev-list --left-right --count main...<rama>` — **adelante y atrás**, porque una rama
>    con 3 commits propios y 55 de retraso no es lo mismo que una con 1 y 0.
> 4. **`git worktree list` es el censo de quién más puede pisarte**, y el nombre de la carpeta
>    **ya está cogido aunque no lo parezca**: `.worktrees/g` estaba ocupado por el Lote G
>    cuando fui a crearlo. El script avisa (`Ya existe .worktrees/g`) y no lo pisa.
> 5. **Docker puede estar caído**, y lo estaba esa mañana. Sin él no hay `route:list`, ni
>    `artisan`, ni las herramientas de `tools/` — y **la base es la de desarrollo compartida**:
>    lo que midas ahí lo puede estar cambiando otra sesión mientras lo lees.
>
> ### Y la prueba de que un número aquí nace caducado
>
> **Conté las ramas sin fundir cuatro veces en una hora y salieron `8`, `10`, `11` y `8`.** No
> me equivoqué ninguna de las cuatro, y las dos direcciones tienen causas distintas: subió
> porque otras sesiones creaban ramas mientras yo medía, y **volvió a bajar porque `main`
> avanzó y se llevó dos dentro**. Lo mismo con los worktrees, de 15 a 17. **Por eso el bloque
> de abajo lleva la hora puesta y esta lista no la necesita.**
>
> ### El número que más se cita, y que depende del árbol en el que estés
>
> ```
> árbol principal   fix/disponibilidad-si-se-guarda    route:list --json  ->  568
> .worktrees/p      feat/plantilla-de-notas            route:list --json  ->  577
> ```
>
> **Las dos son ciertas.** `CLAUDE.md` dice 568 porque describe lo fundido; la plantilla de
> notas trae nueve rutas que todavía no están en `main`. **Quien cite «el router está en N» sin
> decir desde qué árbol lo contó no ha dicho un número**, y ésta es la forma en que esa cifra
> lleva envejeciendo desde agosto.

> ## ✅ EL ÁRBOL, VERDE Y MEDIDO — Y UNA CIFRA DE RELEVO QUE NO RECONCILIA (20 sep 2026)

> **Medido sobre `main` en `57bf6b9`, árbol limpio y sin moverse durante la corrida**, con base
> propia `simonbolivar_testing_rel` recién construida:
>
> ```
> Tests:    1 skipped, 2520 passed (53416 assertions)     php artisan test  (las tres testsuites)
> Duration: 1359.02 s
> pint:test  PASS 475     ·     stan  [OK] 697     ·     route:list --json  623
> ```
>
> **0 rojos, 0 `FAILED`, 0 `ERROR`.** Las 2.520 coinciden con las que midió por su cuenta la
> sesión de `tests-que-tocan.py` (`5383eb8`), que es la única forma de saber que coincidían.
>
> ### La cifra que no cuadra, y lo que se descartó antes de decirlo
>
> **El relevo de `8myvc-b2` publicó 2.475 pruebas diciendo `main` en `26ef140`.** Sobre ese
> mismo árbol más dos commits que **no tocan ni tests ni código** salen **2.520**. Los 45 de
> diferencia no los explica nada de lo que se pudo comprobar:
>
> | descartado | cómo |
> |---|---|
> | la fusión de definitivas los trajo | trae **un** fichero de test y, **corriéndolo**, son **10** |
> | el árbol cambió de tests | en todo el tramo reciente difieren **2** ficheros |
> | la cuenta depende del seed | **no hay ningún proveedor de datos que consulte la base** |
>
> **No se reconstruye desde aquí y no se le inventa una causa.** Lo que sí deja es que la regla
> del repo —*una cifra de pruebas se publica con la orden que la produjo*— **se queda corta**:
> `b2` dio la orden correcta y el número sigue sin cuadrar con el árbol que nombró. Le falta la
> tercera pata, que es la barata: **el commit exacto**. Una cifra con orden y sin commit es
> media cifra, y la mitad que falta es justo la que envejece.
>
> > **Y el «no hay proveedores que lean la base» costó un detector equivocado antes de ser
> > cierto.** El primer barrido dio **siete**, y los siete eran métodos de test con la palabra
> > «datos» en el nombre — ninguno era un proveedor. *Un detector que casa por el nombre cuenta
> > nombres.* Es la tercera vez en la misma sesión: antes fue leer «suite desnuda» de `ps`,
> > donde `docker exec -e` no escribe.
> ## ✅ LA PARCIAL Y LA COBERTURA, EN EL SEGUNDO CALCULADOR — FASE 1.bis DEL 43 (20 sep 2026)
>
> **FUNDIDA el 20 sep 2026.** Escrita en `.worktrees/pla` con base `simonbolivar_testing_pla`;
> el árbol principal ya publica la parcial y la cobertura en los cinco lectores.
>
> > **Esta casilla decía «SIN FUNDIR: mientras esta línea diga «sin fundir», el árbol principal
> > no tiene nada de esto» — y ésa es la frase que la salvó.** Se sustituyó **en el mismo commit
> > que la fusión**, que es lo que pedía. Van cuatro veces seguidas que esa condición de
> > caducidad hace su trabajo, y es la segunda en que quien la escribió y quien la cumple son
> > sesiones distintas: *una cifra sin su condición de caducidad al lado se lee como cierta para
> > siempre.*
>
> La fase 1 le dio `parcial` y `cobertura` a `DefinitivasDeAsignatura`, **que es el que escribe**.
> La planilla no pasa por ahí: pasa por `App\Models\Asignatura::calculoAlumnoNotas`, un segundo
> calculador entero y paralelo, en PHP. Hasta hoy los dos números existían y **no los veía nadie**.
> Ahora ese método devuelve además **`nota_parcial`** y **`cobertura`**, y **cinco de sus seis
> lectores las publican**. El detalle entero está en la
> [§Fase 1.bis del 43](43-lo-que-todavia-no-se-ha-calificado.md).
>
> | | |
> |---|---|
> | Ficheros | `app/Models/Asignatura.php`, `app/Models/Nota.php`, `PlanillasController`, `DetallesController`, `EditnotaController`, `Informes/NotasPerdidasController`, `Informes/PlanillasAusenciasController`, `tests/Contrato/LaParcialEnLaPlanillaTest.php`, `tests/Contrato/Concerns/LaPlanillaDelLienzo.php`, `tests/Contrato/LaParcialYLaCoberturaTest.php`, docs 43 y éste |
> | Rutas · columnas · migraciones · clientes | **0 · 0 · 0 · 0** |
> | Instantáneas movidas | **2 de 129**, CONTADO con la suite entera y no previsto — `muestreo-notas-perdidas-show-profesor` y `muestreo-planillas-ausencias-show-profesor`, las dos únicas de los seis lectores. **El diff de cada una es +2 líneas y nada más**: `nota_asignatura` queda intacta, que es la prueba de que la acumulada no se movió |
> | `composer.json` | **no se tocó** (ver «lo que queda abierto», punto 3) |
>
> ### Los seis lectores, censados — y cuál NO los gana
>
> | lector | ruta | ¿los publica? |
> |---|---|---|
> | `PlanillasController::getShowProfesor` | `GET planillas/show-profesor/{id}` | **sí**, por periodo |
> | `Informes\NotasPerdidasController::getShowProfesor` | `GET notas-perdidas/show-profesor/{id}` | **sí**, por periodo |
> | `Informes\PlanillasAusenciasController::getShowProfesor` | `GET planillas-ausencias/show-profesor/{id}` | **sí**, por periodo |
> | `DetallesController::putGruposPeriodos` | `PUT detalles/grupos-periodos` | **sí**, sin una línea |
> | `EditnotaController::allNotasAlumno` | `PUT editnota/detailed-notas/{grupo}` | **sí**, sin una línea |
> | `Nota::alumnoAsignaturasPeriodosDetailed` | `GET boletines{,2,3}/detailed-notas-year/…` | **no** |
>
> Los tres primeros son **el mismo método byte a byte** en tres controladores: dejar dos iguales
> y uno distinto es un renglón que el siguiente no puede leer como decisión.
>
> **El sexto no, y ahí esta rama se queda corta respecto a su encargo** —que decía «la planilla
> **y los boletines**»—: ese método **no publica ninguna definitiva por periodo**, promedia los
> cuatro y saca `nota_asignatura_year`, así que el único sitio donde cabrían sería *«la parcial
> del año»*, **que no está definida en ninguna parte**. No es la media de las cuatro —cada una
> tiene su propio denominador— y mezclaría los cerrados con el abierto, diluyendo justo la señal.
> *Quedarse corto se cuenta.* **Y el boletín de un periodo no pasa por ninguno de los dos**: va por
> un TERCER camino, `Unidad::deAsignaturaCalculada` más `notas_finales`.
>
> > **Y hay un SÉPTIMO calculador que el censo del 43 no tenía**:
> > `EditnotaController::notasDeLaAsignatura` (`:111` y `:118`) lleva la misma cuenta **escrita en
> > línea**, no llama a `calculoAlumnoNotas` y sirve `PUT editnota/alum-asignatura`. No gana los dos
> > números: unificarla mueve `editnota-alum-asignatura.json`. Queda dicho para que el siguiente
> > censo dé siete y no seis.
>
> ### El hallazgo: los dos calculadores YA discrepaban, y en la acumulada
>
> `calculoAlumnoNotas` **nunca ha pasado por `RepartoDeLaNota`**: pesa `s.porcentaje` crudo,
> porque es lo que le traen `Unidad::deAsignatura` y `Subunidad::deUnidad`. En modo `promedio` el
> servicio pesa `1/n`. Medido sobre `simonbolivar`, año 2026 —el único de la copia en `promedio`,
> con sus cuatro periodos abiertos—: **14 pares de 99 dan distinto, y el peor 42,3 puntos sobre
> una escala de 0 a 50.** No lo trae esta rama: **estaba**.
>
> **Por eso el divisor de la parcial sale de los mismos dos números que la acumulada y NO de
> `RepartoDeLaNota`.** Sacarlo de allí habría arreglado el divisor y dejado el cociente midiendo
> dos repartos distintos — y, peor, **habría hecho creer que el problema estaba resuelto**: una
> parcial impecable al lado de una acumulada que sigue discrepando de la que se guarda.
>
> ### Un caso pasó en verde sin probar nada, otra vez, y otra vez lo delató mutar el código
>
> Con las nueve pruebas en verde se mutó `if ($nota->nota !== null)` a `if ($nota->nota > 0)` —o
> sea, *«el 0 del docente cuenta como sin calificar»*, que es **el bug de origen del 43 reintroducido
> en el divisor**— y **los nueve casos siguieron pasando**. El lienzo no tenía ninguna casilla
> calificada con 0: las dos que valen 0 son `null`, así que las dos escrituras daban idénticos los
> tres números. Se añadió `test_un_cero_tecleado_si_cuenta_en_el_divisor` —son **3.940** ceros
> tecleados en la copia— y con él las cuatro mutaciones probadas ponen en rojo **1, 5, 1 y 3** casos.
>
> ### La cobertura llega al cliente como `int` en los extremos, y yo había escrito lo contrario
>
> El código castea a `(float)`, y **eso no fija el tipo en el JSON**: sin
> `JSON_PRESERVE_ZERO_FRACTION`, `json_encode(0.0)` sale `0` y `json_encode(1.0)` sale `1`. La
> instantánea regenerada lo dice: `cobertura` es **`int|null`** —en el seed sólo salen 0 y 1— y
> `nota_parcial` es `float|int|null`. **Es lo que le pasa a `nota_asignatura` desde siempre**
> (`float|int`), así que no es nuevo; pero el comentario del método decía que el cast servía para
> eso y **era falso**. Corregido. *Una instantánea contestó una pregunta que yo no le había hecho.*
>
> ### Coste: cero, y medido con los contadores porque el reloj no puede
>
> Asignatura 432 periodo 10 —la más cargada de la copia: 986 notas, 45 alumnos, 22 subunidades—,
> cargada como la cargan los seis lectores, 5 vueltas por pasada, cuatro pasadas alternando el
> fichero viejo y el nuevo:
>
> | | `Handler_read_key` | `Handler_read_next` | reloj (mediana de 4) |
> |---|---:|---:|---:|
> | antes | 16.630 | 3.827.505 | 4.762 ms |
> | después | **16.630** | **3.827.505** | 5.187 ms |
>
> **Idénticos en las ocho corridas: cero trabajo de base añadido**, porque lo que se añade es
> aritmética dentro de bucles que ya existían. **El reloj no mide esto**: la dispersión del *mismo*
> código entre pasadas es del 11 % y la del nuevo del 31 %, o sea un orden de magnitud más que el
> efecto. El techo del trabajo añadido, medido aparte: **4.950 casillas en 0,679 ms**, el **0,014 %**
> de una pasada.
>
> > **Y el banco midió el árbol equivocado en su primera versión.** Arrancaba Laravel con
> > `require __DIR__.'/../../../vendor/autoload.php'` desde `.worktrees/pla/tools/`, o sea **el
> > `vendor/` del principal**, y `autoload_psr4.php` resuelve su `$baseDir` a `/app`: las tres
> > primeras pasadas compararon el código nuevo **contra sí mismo**. Lo delató que el guion imprime
> > la parcial del último alumno y salía `NO EXISTE` también en la columna «después». *Un banco que
> > sólo imprime milisegundos no puede avisar de que midió otra cosa.* Es la trampa que la cabecera
> > de `tools/worktree-de-sesion.sh` lleva escrita, cometida desde un guion suelto.
>
> ### Estado — las cifras con la orden que las produjo
>
> | | |
> |---|---|
> | `Tests: 1 skipped, 2352 passed (22966 assertions)` · 1.136 s | `php artisan test --testsuite=Contrato` en `.worktrees/pla` con `DB_TEST_DATABASE=simonbolivar_testing_pla`, **después** de regenerar las dos instantáneas |
> | `LaParcialEnLaPlanillaTest`: **10 passed (51 assertions)** · `LaParcialYLaCoberturaTest`: **10 passed** | `--filter="LaParcialEnLaPlanillaTest|LaParcialYLaCoberturaTest" --testsuite=Contrato` |
> | `PASS 477 files` | `composer run pint:test` |
> | `[OK] No errors` · 699 ficheros | `composer run stan` (nivel 7), con `COMPOSER_PROCESS_TIMEOUT=0` |
>
> > **Se contó DOS veces y la primera se perdió entera.** La pasada de antes de regenerar dio
> > `2 failed, 1 skipped, 2350 passed` en 1.349 s —los dos rojos eran las dos instantáneas— y la
> > de después **2352 passed en 1.136 s**. Cuadra con 2350 + 2, pero **no se escribió sumando**:
> > se volvió a correr, que es la regla de la casa.
> >
> > Y antes de esas dos hubo una que **no dejó ninguna cifra**: el `docker exec` murió con
> > `context canceled` (exit 144) **y el `php` de dentro del contenedor siguió vivo**, con el
> > fichero de salida en **0 bytes**. Es la trampa de *«matar el `docker exec` no mata el `php`»*
> > vista desde el otro lado: no es que la suite siguiera corriendo de más, es que **midió
> > veintidós minutos y no se lo dijo a nadie**. Las dos buenas se lanzaron con
> > `docker exec -d … > /tmp/pla-suite.log`, detached y escribiendo dentro del contenedor, para
> > que una desconexión del host no se lleve la medición.
>
> ### Lo que queda abierto
>
> 1. **Decisión de Joseth: unificar los dos calculadores.** Está medido que hoy discrepan en
>    `promedio` (14/99, hasta 42,3 puntos) y que la discrepancia es **de la acumulada**, o sea del
>    número impreso. Lo que **no** está medido es cuántos números se mueven en los dieciséis colegios
>    y **cuál de los dos es el que hay que conservar**. Eso es una decisión, no un arreglo.
> 2. **Los boletines siguen sin los dos números**, por el motivo de arriba. Si la fase 2 los quiere
>    ahí, lo primero que hay que decidir es qué significa «la parcial» de un año.
> 3. **`composer.json` no se tocó, y es una decisión con precio.** Cinco de los siete ficheros
>    tocados —`Asignatura`, `PlanillasController`, `DetallesController`, `EditnotaController` y los
>    dos de `Informes/`— **no están en la lista curada de Pint** y van con tabuladores. Meterlos hoy
>    reformatea ficheros de 300 a 900 líneas que tocan otras ramas vivas, y **el precio se le pone
>    delante a Joseth antes**, no después. `app/Models/Nota.php` sí está en la lista y su cambio va
>    con espacios: `pint:test` pasa.
> 4. Fases 2, 3 y 4 del [43](43-lo-que-todavia-no-se-ha-calificado.md), sin empezar.
> ## ✅ EL CIERRE Y LO NO CALIFICADO — FASE 4 DEL 43, **FUNDIDA** (20 sep 2026)
>
> **FUNDIDA en `04b6852`. Router en 625, RECONTADAS en el árbol principal sobre `main` y después
> de fundir** — y coincidieron con las 625 que esta misma casilla había contado en
> `.worktrees/cie`, que es la única forma de saber que coincidía.
>
> Escrita en `.worktrees/cie` con base `simonbolivar_testing_cie`. Autorizada por Joseth el 20 sep. Es **D3**: al cerrar, qué pasa con
> lo que nadie calificó lo elige cada rector — *pasa a cero*, *queda fuera de la cuenta* o *no dejar
> cerrar*—, con **`cero` de fábrica**, que es el comportamiento de hoy.
>
> **Router en 625, recontado en el árbol principal.** Las dos que suben sobre las 623 de `main`
> son `PUT years/cierre-sin-calificar` —la elección, con permiso dentro— y
> `GET periodos/sin-calificar/{periodo_id}` —el diálogo: cuántas casillas quedan, de quién son y qué
> va a pasar con ellas—. **Cerrar no gasta ruta**: ya era
> `periodos/toggle-profes-pueden-editar-notas`, y lo que cambia es que ahora aplica la decisión.
>
> **Tests: 2361 passed, 1 skipped, 23.181 aserciones (`--testsuite=Contrato`, en
> `.worktrees/cie`)**, con las 23 instantáneas regeneradas dentro. Del fichero nuevo son **19
> (`--filter=ElCierreYLoNoCalificadoTest`), 267 aserciones, y los DOS controles salieron en rojo**
> —apagando la normalización caen 4, apagando la escritura de los ceros caen 2—, que es lo único
> que distingue «el candado protege» de «no hay nada que proteger». `composer run stan`: **`[OK] No
> errors`, 700 ficheros**. `composer run pint:test`: **`PASS`, 478**.
>
> **Las 23 instantáneas se miraron ENTERAS antes de darlas por buenas**, no se regeneraron y se
> pasó: el diff completo son 46 renglones de `cierre_sin_calificar`, las dos rutas nuevas en
> `rutas.json` y en `guards-por-ruta.json`, y `guard-por-familia.json` pasando de `years 23/21` a
> `24/22` y de `periodos 12/10` a `13/11`. **Nada más se movió**, que es la prueba de que la
> definitiva no cambió con el defecto puesto.
>
> **Y la corrida con `COBERTURA_RUTAS` va a `/tmp/rutas-tocadas-cie.txt`, no al nombre genérico**:
> el propio registrador de `tests/TestCase.php` avisa de que *«lo vacía la corrida, y con varias
> sesiones a la vez cada una quiere su nombre»*, y esta tarde ha habido **seis suites en el mismo
> contenedor**. Con el nombre compartido, la última en arrancar le borra el mapa a las cinco.
>
> **El mapa está hecho y es completo**: `php artisan test` entero, **desprendido** (`docker exec
> -d`, log dentro del contenedor) porque una corrida en primer plano se pierde entera si se cae el
> cliente — **2.539 passed, 1 skipped, 53.679 aserciones, `EXIT_REAL=0`**, leído de la línea
> `Tests:` y no del exit code. El fichero son **7.125 renglones y las 625 rutas del árbol**, o sea
> que no le falta ninguna, y las dos nuevas las tocan **nueve tests de contrato de verdad** además
> del barrido de 401 — que es la distinción para la que existe la columna del test. Copia en el
> bloc de notas de la sesión por si el contenedor se reinicia.
>
> ### Las tres cosas que hay que saber antes de tocar esto
>
> 1. **Son DOS columnas y el plan decía una, y la de más es la regla dura hecha mecanismo.**
>    `years.cierre_sin_calificar` es la elección del rector; `periodos.cierre_sin_calificar` es **lo
>    que se aplicó al cerrar**, y es la única que lee el cálculo. Si leyera la elección, un rector
>    que cambiara de opinión en octubre movería las definitivas de los periodos que ya tiene
>    cerrados e impresos. Con la congelada **no hay pulsación que alcance un periodo cerrado**.
>    `NULL` = «nunca se cerró por este camino» = los 36 periodos de la copia y los de los dieciséis.
>
> 2. **`fuera` MUEVE la definitiva, a propósito, y `cero` NO.** La definitiva no normaliza, así que
>    `SUM(peso × NULL)` y `SUM(peso × 0)` dan lo mismo: sin normalizar, las dos salidas imprimirían
>    el mismo boletín y D3 sería un adorno. Con el lienzo del 43: `cero` deja **16,66** y `fuera`
>    deja **47,60**. Y `cero` sí tiene que **escribir** los ceros aunque no muevan la definitiva,
>    porque lo que mueven es la cobertura (a 1) y la parcial — sin eso, un periodo cerrado se queda
>    gris en el semáforo para siempre y la familia ve 47,60 donde el papel dice 16,66.
>
> 3. **El cierre es la ÚLTIMA escritura posible**, y no es una elección: `ponerAlDiaUnInforme()` no
>    escribe en un periodo cerrado (Joseth, 17 sep) y las notas ya no se tocan, así que ningún
>    recálculo posterior se dispara. Por eso `fuera` rehace las definitivas **dentro del cierre**:
>    **7.779 consultas y 7,88 s** medidos sobre el periodo 2 de 2025 (3.611 definitivas) contra el
>    código que se entrega. El reloj de ese banco dio entre 7 y 34 s para lo mismo según la carga —
>    **la cifra que vale es la de consultas**. Si la petición se corta, la marca ya está congelada,
>    así que volver a pulsar «cerrar» reanuda.
>
>    **Y el 1 % que cuesta la fase a los dieciséis, dicho aparte:** las 80 consultas de diferencia
>    con las 7.699 de antes son **una por asignatura**, la que `calcular()` hace para preguntarle al
>    periodo cómo se cerró — y se paga también con el defecto puesto.
>
> ### Lo que salió al medir y no estaba en el plan
>
> - **`cerrar con cero` es IRREVERSIBLE.** Escribe un 0 real, y desde ahí «nadie lo calificó» y
>   «sacó cero» vuelven a ser indistinguibles. Reabrir y cerrar con `fuera` ya no devuelve 47,60.
>   No se arregla —hacerlo reversible pediría la columna `calificada_at` que **D6 descartó**— y
>   tiene test. La pantalla que pregunte «¿seguro?» tiene que poder decir por qué.
> - **Había una puerta de atrás viva y está cerrada.** `definitivas_periodos/calcular-grupo-periodo`
>   —que llaman los dos fronts— escribe `notas_finales` con **su propia consulta, la acumulada a
>   pelo**: pulsarlo tras cerrar con `fuera` habría devuelto las definitivas a la otra fórmula en
>   silencio y con 200. Ahora contesta 422 y dice a dónde ir. Los otros dos escritores de una
>   definitiva automática **no tienen camino**: uno está muerto y el otro roto (comprobado).
> - **La §Fase 1 decía que la planilla Y LOS BOLETINES no pasan por el servicio, y la segunda mitad
>   es falsa.** El boletín lee `notas_finales` (`BoletinesController:335`), que es lo que el cierre
>   reescribe, así que **el boletín sí queda bien**. Lo que se queda con el otro calculador es la
>   planilla y cinco informes. Corregido en el 43.
>
> ### Lo que queda abierto, y es de Joseth
>
> - **La planilla de un periodo cerrado con `fuera` sigue pintando la acumulada**, porque la produce
>   `Asignatura::calculoAlumnoNotas` —PHP, sin denominador, seis lectores— y no el servicio.
>   Unificar los dos calculadores sigue sin medirse.
> - **Las pantallas**: el diálogo de cierre y el selector de tres opciones los pinta `app2`.
> - **Pint**: los cinco ficheros tocados que **no** están en la lista curada —`PeriodosController`,
>   `YearsController`, `DefinitivasPeriodosController`, `Models/Year` y `Models/Periodo`— **no se
>   han añadido**, a propósito. Formatearlos aquí enterraría el diff de la fase bajo cinco
>   controladores reescritos, y `DefinitivasPeriodosController` ya lleva dentro el `use \Log;` que
>   pone `AliasDeFacadesTest` en rojo. **Es la decisión que Joseth toma con el precio delante**, como
>   la de `Profesor.php` el 19 sep. `pint:test` sigue verde porque sólo mira la lista curada.
>
> ### Lo que NO entra, y está decidido
>
> **D4, el estado NE por celda.** La §5 dice que no por ahora: con `fuera`, una casilla vacía ya
> hace lo que haría NE. Sólo vuelve el día que un colegio elija «pasa a 0» **y quiera excepciones**.

> ## ✅ LA IMPORTACIÓN DINÁMICA — LAS TRES PIEZAS DE LA FASE 2, ROUTER EN 623 (20 sep 2026)
>
> **FUNDIDA** en `bdf3c89`. **623 recontadas en el árbol principal, sobre `main` y después de
> fundir**, coincidiendo con las 623 contadas antes en `.worktrees/imp`. Suite entera sobre el árbol
> fundido: **2.331 passed, 1 skipped** (`--testsuite=Contrato`).
>
> > *La condición de caducidad que esta casilla llevaba escrita —«hay que recontarlas en el árbol
> > principal el día que entren»— se cumplió el mismo día en que se escribió, y es la cuarta vez
> > seguida que esa frase salva el número.*
>
> > **Esta casilla decía 622 y era cierta cuando se escribió.** `main` estaba en 620; mientras esto
> > se construía entró `GET requisitos/recorrido/{alumno_id}` —la casilla de abajo— y lo dejó en
> > 621. *El número no envejeció: describía otro árbol desde el momento en que salió del suyo.* Y
> > con él se cruzaron otros dos: las dos sesiones estrenaron un **doc 44** el mismo día y
> > eligieron el **mismo minuto** para su migración. Los tres se corrigen mirando `main` — el
> > documento pasa a **45**, la migración a `…_400000`— y ninguno lo habría evitado trabajar con
> > más cuidado: sólo mirar después.
>
> El plan vivía fuera del repo *«mientras fuera un plan»*; la Fase 1 entró el 19 sep, así que se
> mudó con número: **[`45-la-importacion-dinamica.md`](45-la-importacion-dinamica.md)**. En
> `myvc-ia-prototipo` queda un puntero y ninguna copia — dos copias de un plan divergen y las dos
> se leen como ciertas.
>
> | | |
> |---|---|
> | **Fase 1** — los arreglos, sin IA | ✅ fundida el 19 sep |
> | **Fase 2** — las pantallas | 🚧 mocks publicados por `myvc-front-41`; **el backend entero, en esta rama** |
> | **Fase 3** — el traductor con IA | ⬜ después de la 2 |
>
> ### Las tres piezas, y lo que costó cada una
>
> 1. **La respuesta de la subida pasa a JSON** — 0 rutas. **Y arregla un fallo vivo**: medido, `POST
>    importar/algo` devolvía `'Importados.'` en `text/html`, y como `app2` sube con `responseType`
>    por defecto `'json'`, **Angular convertía en error una importación que había funcionado**. Lo
>    predijo el front leyendo su propio código; lo confirmó un test contra el docker.
> 2. **Lo que recuerda entre tandas** — 1 migración + `GET importar/alumnos/pendiente/{year}`. Los
>    avisos **acumulan** (son historia) y las respuestas **pisan** (son instrucción vigente); un
>    `null` no borra. Las respuestas viajan en el cuerpo de la subida, no en ruta propia, y eso
>    ahorró la segunda ruta que el alcance parecía pedir.
> 3. **El ensayo** — `POST importar/alumnos/ensayo/{year}`. Dice **qué va a pasar** sin escribir una
>    fila: hojas, columnas con su `si_falta`, valores no reconocidos agrupados, truncados con su
>    consecuencia, el plan fila a fila, los posibles repetidos de D4, los duplicados del propio
>    libro con cuál ganaría, totales y catálogos del colegio.
>
> ### Lo que este trabajo destapó y no buscaba nadie
>
> **El `UPDATE` del importador escribe `nro_sisben` DOS VECES en el mismo `SET`** —una en la lista
> fija y otra en el fragmento que arma `verificar()`— **y gana la segunda**, así que un «No aplica»
> de la hoja acaba en `NULL`. El ensayo prometía el valor crudo y **habría mentido en las 37 filas**
> del seed. No lo vio ninguna lectura del código: lo cazó el test que ensaya, importa y **compara
> contra la base**. *Un ensayo que promete distinto de lo que hace es peor que no tenerlo.*
>
> Y una distinción para quien pinte la pantalla: **«sin cambios» significa que ningún DATO cambia,
> no que la fila no se toque** — el `UPDATE` corre igual y mueve `updated_at`, medido en las 37.
>
> ### Dos premisas que caducaron, y las destapó tener un consumidor de verdad
>
> **La hora de `importaciones` dejó de ser invisible.** Esa tabla escribe en UTC y está declarada
> como excepción en `RelojUnicoTest` porque *«nunca sale por pantalla»*. La Fase 2 **es** esa
> pantalla: «empezada a las 9:41» habría dicho las 14:41. Se arregla **convirtiendo al leer** —no al
> escribir, que dejaría dos relojes en la misma columna— y el contador de `PERMITIDOS` sube de 8 a
> 10 con el motivo. *La decisión de mover la tabla entera sigue siendo de quien lleve las
> importaciones, y ya no la fuerza ninguna pantalla.*
>
> **Y el 422 del fichero ilegible filtraba la ruta del despliegue**: el mensaje de PhpSpreadsheet
> trae `zip:///app/…/storage/…` dentro, así que la respuesta puesta **para no filtrar el `.env` por
> un 500** filtraba el camino del servidor por su cuenta. **Lo cazó el test escrito para ese mismo
> 422.**
>
> ### Lo que NO hizo falta, y baja el precio que se había estimado
>
> La escritura del escenario 6 —cambiar documento y tipo, el caso RC→TI— **ya existe**:
> `PUT alumnos/guardar-valor` escribe cualquier columna por `ColumnaSegura`, y el front la usa en
> siete sitios. **Con un aviso**: no comprueba que el documento nuevo no exista ya, así que antes de
> ofrecer «es el mismo» hay que llamar a `PUT alumnos/documento-check`.
>
> ### Instantáneas: TRES, y el diff se miró en vez de regenerar y pasar
>
> `rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json` —`importar` pasa de **4/4 a 6/6**
> con guard—. **`familias-que-nunca-entran-en-el-candado.json` NO se mueve**: esa familia ya tenía
> cuatro hermanas con guard, o sea ≥ 2, así que el candado de familia ya la miraba. Nada más se
> movió en el diff.
>
> ### D2, CERRADA el 20 sep: **(B) y (A) detrás**
>
> Se aprueba **el mapa** —≈13 renglones— y el `.xlsx` corregido se puede bajar igual **después**.
> Joseth lo decidió con los mocks delante, y el argumento que lo cerró **no es el de la IA**: la
> pantalla del mapa **hay que construirla de todos modos** para que la importación mejore sin
> comprar nada; la IA sólo añade un botón que la rellena. `myvc-front-41` ya la está empezando en
> `app2` contra este contrato.
>
> ### CONDUCIDA contra el docker, y lo que salió
>
> `myvc-front-41` la condujo con Chrome, entrando como `administrador` y con el fichero modelo de la
> propia aplicación. **El ensayo contesta 200 y la pantalla se pinta entera** —13 hojas, tres con
> datos, y `vacios` dando RH 30 y EPS 30 sobre 32 filas, que es exactamente el caso que justificaba
> ese contador: 30 celdas que se guardan sin que nadie las haya decidido—.
>
> **Dos cosas salieron, y ninguna la habría visto un test:**
>
> 1. **`pendiente` daba 500 porque la migración no estaba corrida en la base de DESARROLLO.** La
>    suite migra la suya, el docker no lo hace solo. Corregido corriendo `migrate`; el hueco es del
>    proceso y no del código, y le pasará a cualquiera que traiga `main` sin migrar.
> 2. **`sobran` mezclaba «no lo lee nadie» con «el ensayo no lo mira todavía»**, así que la pantalla
>    decía «se ignoran» de **34 columnas con las que el importador crea personas**. Partido en dos.
>    Misma familia que el `acudientes_tocados: 0`: el dato era correcto y la lectura que inducía,
>    falsa.
>
> ### Y una prueba EN VIVO del pendiente del `.env`, que conviene guardar
>
> Ese 500 llegó al navegador con **`Host: database`, `Port: 3306` y `Database: simonbolivar`**
> dentro del cuerpo. Es el pendiente que el [01](01-plan-seguridad.md) tiene sin verificar y que el
> [09](09-pendientes.md) manda comprobar colegio a colegio: **con `APP_DEBUG=true`, un error filtra
> datos de la conexión**. Aquí está ocurriendo, en una ruta nueva y vista desde una pantalla.
>
> **No se tapa con un `try/catch`**: ahí un 500 es legítimo —la base no tiene la columna, y eso hay
> que verlo— y taparlo cambiaría un fallo de despliegue por un silencio. Lo que falta sigue siendo
> lo mismo: **saber cómo está `APP_DEBUG` en los dieciséis**, que no lo ha mirado nadie.
>
> ### Lo que sigue abierto
>
> - **Aplicar lo que la pantalla decide**: hoy `respuestas` se guarda y se devuelve, pero **el
>   importador todavía no cambia su comportamiento con ella**. Es el siguiente paso y **no estaba en
>   el alcance del 20 sep** — se dice para que nadie lo dé por hecho leyendo que «las respuestas ya
>   viajan».

> ## ⚠️ YO CONTÉ DIEZ SITIOS Y ERAN CATORCE — Y EL QUE FALTABA ERA EL PEOR (20 sep 2026)

> **Lo levantó `myvc-front-2e` verificando nuestro código en vez de creérselo.** Yo dije que
> la regla de `CorreoDeLaCuenta` cubría «los diez sitios que escriben `users.email`». Eran
> más, y **mi diez era lo que había cambiado, no lo que existe**.
>
> **El motivo de que faltaran cuatro es el censo**: lo saqué de un `grep` de
> `$usuario->email = `, y **ninguno de los cuatro está escrito así**. Tres son `UPDATE users
> SET email=?` crudos y el cuarto es un `switch` genérico por nombre de columna. *Un detector
> que enumera las formas que ya imaginaste es ciego a la que no.*
>
> ### El que faltaba y más importaba
>
> **`perfiles/guardar-mi-email-restore`** hace un `UPDATE` crudo con
> `Request::input('email_restore')`. Es **literalmente el correo de recuperación que se pone
> el propio usuario**: el sitio donde la regla más importa de toda la API, porque quien se lo
> pone cree que lo tiene puesto y lo que queda en la columna no sirve para recuperar nada. Con
> él, `putCambiarpassword` y `putCambiaremailrestore`, y `GuardarAlumno` `case 'email'` —la
> rejilla genérica de alumnos—.
>
> **Ahí la regla va dentro de un `if ($propiedad === 'email')`** y no envolviendo `$valor`,
> porque ese bloque lo comparten `username` e `is_active`: envolverlo habría dejado en null
> **todos los usernames**.
>
> ### Y la mitad que NO se toca, comprobada una por una
>
> `PerfilesController` escribe además `$perfil->email` en cuatro sitios donde `$perfil` es un
> `Profesor`, un `Alumno` o un `Acudiente` — o sea **la ficha**. Se miró de dónde sale cada
> `$perfil` antes de decidir, en vez de tratar todos los `->email` igual: eso es justo lo que
> me hizo contar diez. **Son 18 puntos de paso por la regla.**
>
> ### Y el Pint destapó un test que no medía lo que decía
>
> `PoblacionDePerfilesTest::test_son_ocho_los_metodos_que_nombran_grupos` se puso rojo al
> formatear, diciendo que habían cambiado los métodos que nombran `grupos`. **No cambió
> ninguno**: el test lee el fuente y buscaba `/\n\tpublic function/` **con un tabulador**, así
> que al pasar Pint los tabuladores a espacios dejó de casar con nada. *Un rojo que se archiva
> como ruido y no lo es: señalaba un sitio real por un motivo falso.* Barrido el resto de tests
> que leen código fuente — sólo había otro con ese patrón y ya aceptaba espacios.

> ## ✅ EL SEGUNDO CORREO FABRICADO: `'@gmail.com'`, CUARENTA VECES MÁS GRANDE (20 sep 2026)

> **Quitar el `else` no cerró el grifo entero**, y lo levantó `myvc-front-2e`. Hay un segundo
> invento y **pasa por la rama que dejamos en pie**: el alta de la aplicación **vieja** manda
> `email: '@gmail.com'` —el literal, sin nada delante— cuando no se teclea correo, y como es
> una cadena no vacía pasaba el `if (Request::input('email'))`.
>
> | | |
> |---|---|
> | Cuentas vivas con el literal | **678**, todas activas |
> | Alumnos que la recuperación alcanza | 853 |
> | …de ésos, con el literal | **655** |
> | …de ésos, con un correo de verdad | **196** |
>
> **655 frente a 16**: el mismo daño que `@myvc.com` y cuarenta veces mayor. Y corrige el dato
> que llegó esa mañana —*«los alumnos están sanos, 851 de 853»*—, que sólo filtraba
> `@myvc.com`. Tercera vez en el día que una cifra es cierta y la frase de al lado no.
>
> ### Lo decidido, y son dos cosas distintas
>
> **El grifo se cierra desde el backend** (`b130194`): `app/Support/CorreoDeLaCuenta` dice qué
> puede vivir en `users.email`, y una cadena que empieza por `@` no. **`app/` no se toca por
> decisión de Joseth**, así que esa pantalla va a seguir mandándolo — por eso la regla vive
> **donde el dato entra** y no en quien lo manda.
>
> **Las 678 ya escritas se quedan**, como las 30 de `@myvc.com`, decidido con los dos órdenes
> de magnitud delante. *Sabido y decidido, no pendiente.*
>
> **Y la ficha lo conserva.** Sólo tiene regla la columna que es la llave del reseteo;
> `alumnos.email` es un dato de contacto que el colegio mira en pantalla.
>
> ### Lo que este arreglo enseña y no estaba escrito en ningún sitio
>
> **`AlumnosController::postStore` escribe `users.email` desde `email` y NO desde `email2`**,
> o sea que se salta entera la red de `sanarInputUser`. Un guardián puesto sólo en la
> derivación —que es donde lo habría puesto cualquiera, porque es donde estaba el `else`— **no
> habría tocado el alta de alumnos, que es justo donde nacen los 655.** Por eso la regla va en
> los diez sitios que escriben esa columna desde entrada del cliente y no en uno.
>
> **El test se comprobó desarmando la regla**, no leyéndolo: con el `str_starts_with` fuera se
> pone rojo ése y sólo ése. Es la tercera vez hoy que un test se valida rompiendo el código a
> propósito, y las tres veces hizo falta.

> ## ✅ A NADIE SE LE INVENTA UN CORREO (20 sep 2026) — Y EL PINT SE LO LLEVÓ OTRA SESIÓN

> **Decisión de Joseth, confirmada directamente a esta sesión**: al crear a cualquiera
> —docente, alumno, acudiente— **no se le fabrica un correo**. Quitado el `else` de los dos
> `sanarInputUser` (`ProfesoresController`, `AlumnosController`), que eran los dos únicos
> sitios del backend que construían `username@myvc.com`. Commit `4c82661`.
>
> **Las 30 cuentas que ya lo tienen se quedan** —16 activas: 11 profesores, 2 alumnos, 3 sin
> ficha—, decidido el mismo día con esos números delante. *Está sabido y decidido, no
> pendiente*, y se escribe así a propósito para que dentro de un año no parezca un olvido.
>
> **Y cambia la EDICIÓN además del alta, a sabiendas**: si una pantalla mandaba `email2`
> vacío, se fabricaba uno y **se escribía encima del que hubiera**. Ahora vaciar el campo se
> respeta.
>
> ### El test se comprobó volviendo a poner el `else`, y las dos primeras versiones no medían nada
>
> | versión | por dónde iba | con el `else` puesto |
> |---|---|---|
> | 1ª | `guardar-valor` | **verde** — ni siquiera llama a `sanarInputUser` |
> | 2ª | `update/{id}` | **verde** — lo llama, pero sólo escribe si la clave `email2` vino |
> | buena | `profesores/store` | **roja**, que es lo que hacía falta |
>
> *Un test verde que pasaría igual sin el arreglo es peor que no tenerlo, porque hace
> archivar el asunto.* Con el `else` restaurado los tres se ponen rojos; sin él, los tres
> verdes.
>
> ### El Pint acabó dentro del commit de otra sesión, y no se rehace
>
> Dejé los dos controladores reformateados en el árbol principal mientras corría la suite, y
> **`2503b27` —una casilla de documentación de otra sesión— se los llevó**, junto con la
> línea de `composer.json`. Es literalmente el aviso de `CLAUDE.md`: *«formatear no estaña,
> pero le deja a otro cambios que no hizo, y el siguiente que commitee ahí se los lleva»*.
>
> **No se rehace la historia**: el contenido está bien, `pint:test` da PASS con 465 y `main`
> es compartido. Lo que se perdió es el porqué, así que va aquí — **el coste del reformateo
> se midió antes y con la orden buena**, el merge-base y no `git diff main..<rama>`, que
> mezcla «la rama cambió el fichero» con «la rama va por detrás»:
>
> ```bash
> for b in $(git for-each-ref --format='%(refname:short)' refs/heads/); do
>   base=$(git merge-base main "$b") || continue
>   git diff --name-only "$base".."$b" -- <el fichero> | grep -q . && echo "$b"
> done
> ```
>
> **Ninguna rama viva toca ninguno de los dos**, así que reformatearlos no le costó nada a
> nadie. *La lección no es «no formatees»: es que lo que dejes sin commitear en el árbol
> principal deja de ser tuyo en cuanto otro haga `add`.*

> ## ✅ EL CORREO DE LA CUENTA DEL ACUDIENTE — DECIDIDO Y HECHO (20 sep 2026)

> **Las tres decisiones de Joseth sobre la casilla de abajo, tomadas con las poblaciones
> delante y construidas el mismo día.** Commits `4b82d8e` (comportamiento) y `69d08fa`
> (Pint, aparte). Coordinado con `myvc-front-2e`, que hizo su mitad y la probó en Chrome.
>
> | Decisión | Qué se hizo |
> |---|---|
> | **D1** · `AcudientesController` copia la ficha a la cuenta | …y **NO inventa nada** cuando no hay correo. Es la mitad buena de la red de `AlumnosController:496`; la que pone `username@myvc.com` se descartó a propósito |
> | **D2** · la rejilla puede editarlo | `case 'email2'` en `valorAcudiente` |
> | **D3** · migrar lo ya escrito | `2026_09_20_200000`, sólo donde la cuenta está vacía |
> | *(no autorizado)* | vaciar los 16 `@myvc.com` y el `@gmail.com` de 678 alumnos **no se toca** |
>
> **Acudientes recuperables: de 0 a 91.** La migración dio **91 copiados de 100 candidatos y
> 9 saltados por colisión** — y no 94/6 como se había calculado antes de correrla: seis
> chocaban con una cuenta que ya existía **y tres entre ellos mismos**, dos acudientes con el
> mismo correo de ficha. *Por eso la comprobación va DENTRO del bucle; preguntarlo una vez
> antes no habría visto esos tres.*
>
> ### Lo que salió al construirlo y no estaba en la casilla
>
> **`postCrearUsuario` era el agujero mayor y nadie lo había mirado.** Es el otro camino que
> crea cuenta —a un acudiente que ya tiene ficha— y **no ponía correo nunca, ni el que la
> ficha ya tenía escrito**. La migración arregla los que hay; esto arregla el grifo.
>
> **Y una línea que el front necesitaba para que D2 sirviera de algo**: `u.email as email2`
> en las **seis** consultas de acudientes. Ninguna devolvía el correo de la cuenta, así que
> la rejilla no podía pintarlo **y la rama de escritura no la podía llamar nadie**. No
> amplía la exposición: `ac.email` —la misma dirección en los 91— ya viajaba en las seis.
>
> ### Dos cosas que se comprobaron para NO hacer trabajo
>
> **El recorte del valor ya está hecho y es global**: `TrimStrings` está en la pila de
> `Kernel.php:22` y sólo excluye contraseñas y el `.myvch` del horario, así que un correo con
> un espacio delante llega recortado **también por la rama `default`**, la de la ficha. No
> hace falta tocar nada ahí.
>
> **85 acudientes no tienen cuenta** (1.085 vivos, 1.000 con `user_id`), y para ésos la rama
> `email2` contesta **404** por `FilaQueSeVaAEscribir::exigir`. Se deja así: el front no
> ofrece la celda cuando la fila no tiene cuenta.
>
> ### Lo que sigue abierto, y es de Joseth
>
> Los **9 correos que la migración saltó** —personas distintas compartiendo dirección,
> normalmente una familia— se deciden uno a uno. Y los **909 acudientes que siguen sin correo
> de cuenta** no los arregla ningún commit: es dato que hay que pedir.
> ## ✅ UN CANDADO QUE DECÍA CUBRIR EL ORDEN DE LAS RUTAS Y NO LO CUBRÍA (20 sep 2026, EN `main`)
>
> Laravel sirve **la primera ruta que casa**, así que un comodín declarado antes que una literal
> se la traga. Ha pasado **dos veces en la misma familia**: `…/{lote}` comiéndose `…/campos`, y
> `…/{codigo}` comiéndose `…/pendientes` —ésta contestaba `getEstado` a quien pedía la bandeja del
> tesorero—. Las dos se cazaron **a mano, una por una, cuando alguien las vio**.
>
> **`RutasTest` decía en su docblock que cubría exactamente esto, y no lo cubría.** La instantánea
> guarda la acción **declarada** de cada URI, y reordenar no la mueve: `rutas.json` queda byte a
> byte igual. Reproducidos los dos casos reales, **el test viejo se queda verde en los dos**.
>
> Lo cierra `RutasTest::test_ninguna_ruta_literal_la_atiende_un_comodin`, que no lee lo declarado:
> recorre el router y **le pregunta a `getRoutes()->match()` quién atiende cada ruta literal**, que
> es lo que hará el servidor. Cubre las **620** de hoy y las de mañana sin que nadie se acuerde.
> *(**623** al recontarlas el 20 sep en el árbol principal sobre `main`; la cifra se movió, no
> envejeció mal — el candado las cubre sin tocarlo, que es el motivo de que sea genérico.)*
> Control visto en rojo con los dos casos históricos; hoy el router está limpio.
>
> > **REPRODUCIDO POR UNA SEGUNDA SESIÓN, Y EL CONTROL LLEVA SU CUENTA DE ASERCIONES AL LADO**
> > (`8myvc-97`, 20 sep 2026, en un árbol propio para no tocar el principal con tres suites
> > corriendo). Movido `GET colillas-inscripcion/{codigo}` por delante de `pendientes` —el fallo
> > del tesorero, literal— y corrido `RutasTest`:
> >
> > ```
> > en verde     3 passed             (5 assertions)
> > roto         1 failed, 2 passed   (5 assertions)
> > ```
> >
> > **Las aserciones no se movieron**, y ése es el dato: es lo único que distingue haber roto la
> > propiedad de haber roto el fichero. Un rojo que se lleva por delante la cuenta de aserciones
> > no ha medido nada, y se lee igual que éste.
> >
> > Y las otras dos patas salieron en la misma corrida, sin buscarlas:
> > **`test_cada_uri_la_atiende_la_misma_accion` se quedó VERDE** —el candado viejo mintiendo en
> > vivo, no en el relato— y `route:list` listó `pendientes -> getPendientes` **el primero, por
> > orden alfabético**, que es justo al revés de lo que hacía el servidor, con el total intacto.
> >
> > **Esto no hacía falta para dar por bueno el trabajo de `8myvc-dd`.** Hacía falta porque esta
> > misma casilla dice *«no leas su docblock»*, y creerse la frase «control visto en rojo» de un
> > relevo es leer un docblock más largo.
>
> **La lección no es del router, es del candado**: *un detector puede contar bien un síntoma sin
> estar contando la causa* — y éste llevaba **el nombre de la causa escrito en el docblock**, que
> es justo lo que hizo que nadie fuera a mirar. Cuando un test dice que protege algo, la forma de
> saberlo es **romper ese algo y verlo en rojo**, no leer su docblock. (Y `route:list` tampoco lo
> delata: ordena alfabéticamente, no por orden de registro.)
>
> Salió de una revisión de pasada de `8myvc-b2`, que lo vio, no lo escribió por estar cerrándose y
> lo dejó dicho. **Un hallazgo que se escribe en vez de llevárselo no se pierde.**

> ## ✅ LA PARCIAL Y LA COBERTURA — FASE 1 DEL 43, **FUNDIDA** (20 sep 2026)
>
> **Rama `feat/la-parcial-y-la-cobertura`, escrita en `.worktrees/f1` con base
> `simonbolivar_testing_f1`; la rama y su worktree ya no existen.** Esta línea decía *«NO está en
> `main`: mientras esta línea diga «sin fundir», el árbol principal no tiene nada de esto y
> `calcular()` sigue devolviendo dos números»*, **y era falsa**: el encabezado se actualizó a
> FUNDIDA y el párrafo de dentro no, así que la casilla se contradecía consigo misma. Comprobado
> en el código y no en el documento — `calcular()` devuelve `parcial` y `cobertura` en las líneas
> 527-528, y `LaParcialYLaCoberturaTest` existe. *Una condición de caducidad sólo protege si se
> descarga entera: media casilla al día es una casilla que miente.*
>
> `App\Services\DefinitivasDeAsignatura::calcular()` devuelve además, por alumno, **`parcial`**
> (Σ aporte ÷ Σ peso **de lo calificado**) y **`cobertura`** (Σ peso calificado ÷ Σ peso total), y
> `recalcular()` las pasa **como claves hermanas de `definitiva`** cuando se le pidió un alumno.
> El porqué entero está en la [§Fase 1 del 43](43-lo-que-todavia-no-se-ha-calificado.md).
>
> | | |
> |---|---|
> | Ficheros | `app/Services/DefinitivasDeAsignatura.php`, `app/Support/RepartoDeLaNota.php` (+`pesoDeLaNota`), `tests/Contrato/LaParcialYLaCoberturaTest.php`, doc 43 |
> | Rutas · columnas · migraciones · clientes | **0 · 0 · 0 · 0** |
> | Instantáneas movidas | **ninguna de las 129**, y eso es la prueba de que la definitiva no cambió |
> | `composer.json` | **no se tocó**: `app/Services`, `app/Support` y `tests` ya van como directorios enteros |
>
> ### Las cuatro cosas que el doc 43 decía mal, medidas antes de creérselas
>
> 1. **La cobertura NO puede dar 120 %.** La §3.bis c consecuencia 2 lo prometía; con la fórmula
>    del propio doc el mismo `Σ peso` está arriba y abajo, así que vive en `[0, 1]`. **0 de 9.422
>    pares por encima del 100 %, con 328 asignaturas mal repartidas dentro de la muestra** (hasta
>    `Σ peso` = 2,54). **El delator del reparto malo ya existía**: `porcentaje_unidades`. Tachado
>    en el doc y atado con test, para que nadie lo «arregle» metiendo un 1 en el divisor.
> 2. **Falta un segundo cero de división y es grande.** El doc cubre `Σ peso calificado = 0 →
>    parcial NULL`; no cubre **`Σ peso TOTAL = 0`**, donde la que se queda sin respuesta es la
>    **cobertura**: **3.158 de 9.422 pares, el 33,5 %** —3.059 sin una sola fila en `notas` y 99
>    con todas sus casillas a peso 0—. Ahí es **`NULL`, no 0**. Con test propio.
> 3. **No mueve las instantáneas de boletines y planilla — porque ésas no pasan por aquí.** Las
>    calcula **`App\Models\Asignatura::calculoAlumnoNotas`** (219–270), **un segundo calculador de
>    la definitiva entero y paralelo, en PHP, sin denominador**, con **seis lectores**
>    (`PlanillasController`, `DetallesController`, `EditnotaController`, `NotasPerdidasController`,
>    `PlanillasAusenciasController`, `Nota::alumnoAsignaturas`). **Así que la parcial NO llega hoy a
>    la planilla**, y eso es una decisión de Joseth, no un olvido de esta rama.
> 4. **El coste sólo existe en un modo.** En `porcentaje` —ocho de los nueve años de la copia y el
>    defecto de los dieciséis— las filas leídas son **idénticas**. En `promedio` —**un año de la
>    copia ya lo usa**— `Handler_read_next` **5.707 → 13.791** (×2,42) y el reloj **8,6 → 17,7 ms**,
>    porque el peso arrastra la subconsulta correlacionada de `cuantasSubunidades`, que pasa de una
>    evaluación por fila a tres. Bajarlo cambia el texto de `aportacionALaDefinitiva`, que es
>    contrato: **medido y no resuelto**.
>
> > **Y `tools/coste-del-recalculo.php` no vale para medir esto**, que es un hallazgo aparte: su
> > reloj oscila más que el efecto —el `sello`, que nadie tocó, dio entre **2,0 y 4,8 ms** entre
> > pasadas, y `calcular` entre 2,94 y 7,75 **sobre el código sin tocar**—. Lo que separa la señal
> > del ruido aquí son los contadores `Handler_read_*`, que son deterministas.
>
> > **Un caso pasó en verde sin probar nada y lo delató mutar el código, no correrlo.** El del
> > modo `promedio` daba el número bueno **con el reparto cableado a `porcentaje`**: con los cuatro
> > indicadores del lienzo el divisor sale 0,35 en los dos modos (0,30+0,20 en uno, 2×0,25 en el
> > otro), o sea que coincidía por casualidad. Se arregló añadiendo un quinto indicador de peso 0 —
> > inerte en `porcentaje`, repartiendo en `promedio`—. Las tres mutaciones que se probaron ahora
> > ponen en rojo 1, 2 y 6 casos.
>
> ### Estado — las cifras con la orden que las produjo
>
> | | |
> |---|---|
> | `Tests: 1 skipped, 2288 passed (22441 assertions)` · 1.170 s | `php artisan test --testsuite=Contrato` en `.worktrees/f1` con `DB_TEST_DATABASE=simonbolivar_testing_f1` |
> | `LaParcialYLaCoberturaTest`: **10 passed (43 assertions)** | `--filter=LaParcialYLaCoberturaTest --testsuite=Contrato` |
> | `PASS 463 files` | `composer run pint:test` |
> | `[OK] No errors` | `composer run stan` (nivel 7) |
>
> **Y la primera cifra hubo que medirla DOS veces.** La primera pasada se lanzó en paralelo con
> una corrida filtrada **contra la misma base**, que es el caso de deadlock que avisa
> `03-tests.md`; al matarla, su fichero de salida se quedó con la traza del `kill` y **un
> `grep '⨯'` sobre él daba 0 rojos con la misma cara que una suite verde**. La que vale es la
> segunda, corrida sola. *Un contador de rojos sobre una salida truncada dice «verde».*
>
> ### Lo que queda abierto
>
> 1. **Decisión de Joseth: por dónde salen los dos números a los clientes.** Hoy no los sirve
>    ningún endpoint. La fase 2 (`app2`) y la fase 3 (`myvc_flutter`) los necesitan, y el sitio
>    natural —la planilla— **lo calcula el otro calculador**. Es la pregunta 3 de arriba.
> 2. **Para la fase 2: D2 no dice de qué lado cae el 15,00 % exacto.** Hay **152 pares clavados
>    en 15,00 %** —ocho asignaturas enteras: el docente que calificó un solo indicador que vale el
>    15 %—, y eso es **más que los 42 pares que separan el 15 % del 10 %**. `<` da **2.113** grises
>    y `<=` da **2.265**. Y la cobertura es un **factor de 0 a 1**: quien compare contra `15` en vez
>    de contra `0.15` pinta de color absolutamente todo.
> 3. Fases 2, 3 y 4 del [43](43-lo-que-todavia-no-se-ha-calificado.md), sin empezar.

> ## ❗ LA RECUPERACIÓN DE CONTRASEÑA ALCANZA A **CERO** ACUDIENTES (20 sep 2026)

> **Lo levantó `myvc-front-2e` corrigiendo una cifra mía, y está reproducido aquí antes de
> escribirlo.** El transporte de correo quedó arreglado hoy en las dieciocho instalaciones — y
> eso destapa que **no había nadie al otro lado**.
>
> | | |
> |---|---|
> | Acudientes vivos | 1.085 |
> | …con correo de **ficha** (`acudientes.email`) | 100 (9,2 %) |
> | …con cuenta viva y activa | 1.000 |
> | **…con correo de CUENTA (`users.email`)** | **0** |
> | **Alcanzables por la recuperación** | **0** |
>
> **Yo cité el 9,2 % y contaba la columna equivocada.** `LoginController.php:240-266` busca por
> `users.email` en cuatro consultas seguidas; `acudientes.email` **no lo mira nadie** en ese
> flujo. Y como el método contesta `Enviado` aunque el correo no esté registrado —a propósito,
> para no confirmar qué direcciones existen—, **los mil acudientes ven la misma pantalla que si
> el correo hubiera salido**. Es el mismo silencio que el `mail()` viejo, en otro sitio.
>
> ### La causa es una asimetría entre tres controladores de este repositorio
>
> `AlumnosController.php:496-502` y `ProfesoresController.php:248-254` derivan `email2` desde
> `email` cuando el cliente no lo manda. **`AcudientesController` no tiene esa red**: su línea
> 400 asigna `Request::input('email2')` a pelo y el front sólo manda `email`. *Los tres
> formularios se comportan igual; los tres controladores no.*
>
> **Y no se arregla entero en un solo lado**: `Alumnos/GuardarAlumno.php:147` (`valorAcudiente`)
> sólo tiene rama a `users` para `username`, así que cualquier columna de correo que el front
> añada a la rejilla caería en el `default` y **volvería a escribir la ficha**.
>
> ### Lo que espera decisión de Joseth — NADA DE CÓDIGO TOCADO
>
> Son tres piezas y ninguna es obvia: **(1)** darle a `AcudientesController` la misma red que
> sus dos hermanos —y entonces hay que decidir si un acudiente sin correo recibe el
> `username@myvc.com` inventado, que es lo que hoy se les pone a alumnos y profesores y que **no
> sirve para recuperar nada**—; **(2)** la rama `email2` en `valorAcudiente`; **(3)** qué se hace
> con los **94 correos de ficha que no están en ninguna cuenta** — hay direcciones reales
> inutilizadas. `myvc-front-2e` lo dejó escrito en su `PREGUNTAS-MANANA.md` y tampoco ha tocado
> nada.
>
> **Ojo al alcance**: medido en el docker, que es **un** colegio. Si los dieciséis están igual no
> lo sabe nadie, y se contesta con la misma consulta en cada base.
>
> *Y una que sale de paso: para profesores la recuperación alcanza a **12**, no a 34, porque
> exige `is_active=1` y veintidós cuentas con correo no lo están.*

> ## ✅ EL CALENDARIO SE LEE POR AÑO — SEIS CONSULTAS EN UNA (20 sep 2026, EN `main`)
>
> **Decidido por Joseth el 20 sep con las tres opciones delante y sabiendo lo que apaga.**
> **Fundido en `main` (`d2073a7`); la rama y su worktree ya no existen.** No mueve ninguna ruta:
> recontado en el árbol principal después de fundir, **620**, igual que antes.
>
> **Lo medido**: `calendario` tiene **593 filas visibles, de 2019 a 2025, y ni una de 2026** —
> nadie la ha curado—. Las seis consultas que la leían **no filtraban ni por año ni por
> fecha**: 128 KB en `ChangesAsked/to-me` y **215,5 KB** en `calendario/this-year`, que con
> `SELECT *` mandaba más que su hermana con los mismos datos. Ahora las seis pasan por
> `App\Support\EventosDelAnio`.
>
> **⚠️ Lo que esto apaga, y se verá antes que el ahorro**: en un colegio cuyo año en curso sea
> 2026, **el calendario del panel sale vacío**. No es una avería: es que no hay ni un evento de
> 2026 cargado. Quien lo vea **no tiene que revertir esto, tiene que cargar el año.**
>
> ### Tres cosas que salieron por el camino
>
> **1 · El `SELECT *` ya lo había arreglado otra tanda, y sus diecisiete columnas NO se
> tocan.** `feat/calendario` las nombró a propósito —son las que tenía la tabla antes de su
> migración, para que esa respuesta no moviera ni una clave—. `EventosDelAnio` comparte **la
> regla del año y no las columnas**: `to-me` sigue con diez y `this-year` con diecisiete.
> Estrecharlas de paso habría deshecho una decisión ajena sin discutirla.
>
> **2 · El solape, y no `YEAR(start)`.** Un evento del 20 de diciembre al 15 de enero
> pertenece a los dos años; con `YEAR(start)` desaparece del año en que la gente lo vive. Es
> además la forma que ya usaba `eventosManualesDelRango`, así que **las dos maneras de leer el
> calendario por fin coinciden** — antes el panel cargaba una lista al entrar y otra distinta
> al pulsar «Actualizar».
>
> **3 · El año sale del TOKEN, no del reloj ni de `users.periodo_id`.** Entrar mueve a la
> persona al periodo actual: en el seed la fila del primer profesor dice **2021** y su token
> resuelve **2025**. Escribir un test contra el año de la fila lo pone rojo con el código
> bien, y el síntoma engaña —parece que el filtro no filtra—. Se le pregunta a
> `POST /api/login`, que es de donde lo saca el propio controlador.
>
> ### Y lo que este cambio estuvo a punto de desarmar sin avisar
>
> **`CalendarioInternoTest` creaba sus eventos con `start => now()`**, o sea 2026, mientras el
> seed vive en 2025. Con el filtro, los tres caían fuera del año:
>
> - las **dos** pruebas que exigen **ver** los internos se pusieron **rojas** y avisaron;
> - las **tres** que exigen **NO** verlos **siguieron verdes** — y ésas son las peligrosas:
>   pasaban porque los eventos estaban fuera del año, o sea que **habrían pasado igual con el
>   filtro de `solo_profes` quitado**, que es justo el agujero que esa clase existe para cazar.
>
> Arreglado haciendo que los eventos nazcan **dentro del año del token**. Control: quitando
> `solo_profes = 0` ahora caen las tres; antes del arreglo no caía ninguna.
>
> ### Dos instantáneas se estrechan, y no es el contrato
>
> `muestreo-ChangesAsked-to-me` y `muestreo-calendario-this-year` pasan de `'end' =>
> 'null|string'` a `'end' => 'null'`. **La muestra encogió**: en las filas del año en curso del
> seed ningún evento tiene `end`. El día que haya uno de varios días volverán a `null|string`
> y **eso no será una regresión**.
>
> ### Estado
>
> `ElCalendarioEsDelAnioTest` **4 passed**, `CalendarioInternoTest` **5 passed**,
> `MuestreoDeLecturas*` **77 passed**, y el bloque ancho
> (`--filter='Calendario|Muestreo|Muro|Login|ChangeAsked|Panel'`) **159 passed** — todo con
> `--testsuite=Contrato` en `.worktrees/cal` con `DB_TEST_DATABASE=simonbolivar_testing_cal`.
> `pint:test` **PASS, 458 ficheros**.
> ## 🔧 `tools/correo-de-los-colegios.sh` — QUÉ INSTALACIONES NO PUEDEN MANDAR CORREO (20 sep 2026)

> **Pedido por Joseth**: un guion para subir al shared host que diga **qué subdominios siguen sin
> el bloque de correo arreglado**. Sólo lectura: no toca ningún `.env`, que es la regla del
> [29](29-los-env-no-son-uniformes.md) y no una precaución del guion.
>
> **Cierra el pendiente nº 1 del §8 del 29** —«la instalación viva de `lal` no la alcanza el
> censo»— del único modo en que se puede cerrar desde aquí: **haciendo que se note**. Mientras el
> barrido no haya mirado nada fuera de `/home/micolev1/`, esa instalación cuenta como NO MEDIDA y
> el guion **sale con código 2**. Un «17 de 17 correctos» en verde es exactamente la lectura falsa
> contra la que existe.
>
> ### Las cuatro formas de estar roto que comprueba, y la cuarta no está en ningún `.env`
>
> `MAIL_FROM_ADDRESS=null` —la cadena se lee como null de verdad y `Mail` rechaza antes de
> intentarlo—, `smtp` contra `mailhog`, el remitente en `lalvirtual.com` (NXDOMAIN) y
> **`bootstrap/cache/config.php` más viejo que el `.env`**. La cuarta es la que mordió en `demo`
> el 15 sep y **no se ve leyendo el fichero**: ahí el `.env` no es la fuente de la verdad, así que
> sale NO MEDIDO y no «OK». *Un colegio con el bloque perfecto y la caché vieja se comporta como
> uno sin arreglar.*
>
> **Y una que es del servidor y no del colegio**, por eso se imprime una vez y no por carpeta: el
> `sendmail_path` del `php.ini` y si ese binario existe. Con `MAIL_MAILER=sendmail` bien escrito y
> el binario ausente no sale nada igual, y ningún `.env` lo delata.
>
> ### Lo que NO contesta
>
> Que la configuración sea correcta **no es que el correo llegue**. Eso sólo lo dice
> `php artisan correo:probar` y mirar la bandeja — y sigue abierta la cuenta del 3 sep: **de
> diecisiete envíos volvieron cuatro rebotes** y de los otros trece no se sabe nada (§8 nº 2).
>
> **NO es un paso de despliegue**: el `.env` no viaja en el despliegue, así que desplegar no
> rompe el correo ni lo arregla.

> ### CORRIDO EN EL SERVIDOR EL 20 SEP 2026, Y SALIERON DOS COSAS
>
> **Los diecisiete de `micolev1`: `sendmail` y `admin@micolevirtual.com`, los diecisiete.** El
> arreglo del 3 sep sigue en pie diecisiete días después y nadie lo ha movido.
>
> **1. `lal.micolevirtual.com` sale en verde y NO es el `lal` que falta.** Esa carpeta existe en
> `micolev1` desde el 30 ago —es el document root que se preparó para el traslado— así que el
> bucle la barrió y la arregló el 3 sep con las demás. Pero el subdominio no resuelve y ahí no
> entra nadie: **el `lal` vivo se sirve desde `lalvirtual.edu.co`, cuenta `micolevi`, y además en
> otro servidor** (`mi3-ss54`, no `mi3-ss55`). Sin la fila de NO MEDIDO del final, «17 ok» se lee
> como «lal incluido» — que es exactamente para lo que está esa fila.
>
> **2. El `.env` vivo de `lal` es de OTRA GENERACIÓN, y es una tercera forma.** No es el
> andamiaje del docker ni el bloque arreglado:
>
> ```env
> MAIL_DRIVER=smtp            # <- Laravel <=6. Se renombró a MAIL_MAILER en la 7: NO LO LEE NADIE
> MAIL_HOST=smtp.gmail.com
> MAIL_PORT=587
> MAIL_USERNAME=davidguerrero777@gmail.com
> MAIL_PASSWORD=null          # <- la CADENA null: se autentica SIN contraseña
> MAIL_ENCRYPTION=tls
> ```
>
> Y **sin `MAIL_FROM_ADDRESS`**, que es lo que lo tumba primero: no es `null` como en los otros
> dieciséis, es que **la línea no está**, así que `config/mail.php` cae a su defecto
> —`hello@example.com`— y el correo *saldría* con ese remitente si el resto funcionara. **Es una
> forma de fallar peor que la de los dieciséis**: aquélla se detenía antes de intentarlo; ésta
> parece configurada.
>
> *La tesis del [29](29-los-env-no-son-uniformes.md) aguanta una vuelta más: no hay dos formas de
> `.env`, hay tres, y la tercera es la instalación que ningún censo ha mirado nunca.*
>
> **El guion aprendió las dos** en el mismo commit: el transporte **efectivo** —sin `MAIL_MAILER`
> el defecto es `smtp`, no «el valor por defecto», que no dice nada—, `MAIL_PASSWORD=null` en la
> otra punta del mismo defecto que el remitente, y el fósil `MAIL_DRIVER`, que se avisa **gane el
> veredicto que gane**: es la línea que hace leer un `.env` como configurado cuando no lo está.

> ## 🔜 LA FASE 1 (EL DÍA DE MATRÍCULAS): DOS DECISIONES DE JOSETH, Y LO QUE FALTA (20 sep 2026)
>
> Documento nuevo: [`44-el-dia-de-matriculas.md`](44-el-dia-de-matriculas.md). **No hay una línea
> de código y no se propone escribirla** hasta tener el dato de `lal` (§4 de ese documento).
>
> **Decidido por Joseth el 20 sep**: las estaciones son **las que el colegio quiera** —editor de
> pasos, no plantilla— y cada una es **«obligatoria antes de continuar» u «opcional»**.
>
> > **Esa segunda decisión COLAPSA algo que la propuesta separaba, y va dicho sin re-litigarlo.**
> > `PANTALLAS-MATRICULA.md` §3 distinguía *obligatorio* (hay que cumplirlo para matricular) de
> > *bloqueante* (impide pasar al siguiente). Joseth describió **una sola**: bloqueante o nada. Es
> > más simple —una columna y un interruptor— y **lo que se pierde es el caso «hay que hacerlo,
> > pero no aquí ni ahora»**: la entrevista de orientación, que es obligatoria y no debería frenar
> > la cola. Se construye con un interruptor, que es lo decidido; queda escrito para que el día que
> > un colegio pida «que no frene pero que no se me olvide», la respuesta sea revisar esta decisión
> > con el caso delante y no un parche.
>
> **La pregunta que decide si hay migración o ninguna**: ¿el número de estación **es**
> `requisitos_matricula.orden`, o son dos cosas? El relato admite las dos lecturas —*«el requisito
> 2… la estación 4»*— y **lo contesta el dato de `lal`, no discutirlo**. Una columna añadida sin
> saberlo es `profesores.tono` otra vez.

> ## ⚠️ ANTES DE LA FASE 1 DEL PROCESO: `requisitos_matricula` ESTÁ VACÍA **AQUÍ**, NO EN LOS DIECISÉIS
>
> **Medido el 20 sep 2026 en la copia de desarrollo (UN colegio), y el denominador es la mitad del
> hallazgo.** La fase 1 del proceso de admisión —`myvc_front/PANTALLAS-MATRICULA.md`— **ensancha**
> `requisitos_matricula` y `requisitos_alumno` en vez de empezar de cero. Aquí no hay nada sobre lo
> que construir:
>
> ```sql
> SELECT COUNT(*) FROM requisitos_matricula WHERE deleted_at IS NULL;            -- 1
> SELECT COUNT(*) FROM requisitos_matricula r INNER JOIN years y
>   ON y.id=r.year_id AND y.actual=1 WHERE r.deleted_at IS NULL;                 -- 0
> SELECT COUNT(*) FROM requisitos_alumno;                                        -- 12, TODAS en «falta»
> SELECT COUNT(*) FROM requisitos_matricula WHERE editable_por_profe_id IS NOT NULL; -- 0
> ```
>
> Y la única fila es **`Fotocopia del documento (verificacion 234754)`, creada el 22 ago 2026** —un
> dato de prueba, no de un colegio trabajando.
>
> **Joseth lo contestó el 20 sep: «sí lo han usado en otros colegios».** O sea que el mecanismo
> está vivo y el diseño de la fase 1 se sostiene — **lo que NO se sostiene es diseñarlo sobre lo
> medido aquí**. Los repartos por defecto, los nombres de los pasos y cuántas estaciones tiene un
> día de matrículas salen de un colegio que usa esto, y **en esta copia no se pueden ver**.
>
> **Esto no es una cifra que haya que corregir: es una cifra cuyo DENOMINADOR hay que decir.** «La
> tabla está vacía» a secas habría hecho archivar la fase 1 como «funcionalidad muerta», que es
> exactamente la lectura falsa de las dos — y la que hace tirar trabajo bueno. Es la regla de
> *«ninguna herramienta imprime OK sin decir su población»* aplicada a una consulta a mano.
>
> ### ✅ JOSETH NOMBRÓ EL COLEGIO EL 20 SEP: **`lalvirtual`**, Y LA MEDICIÓN QUEDA LISTA
>
> **`tools/requisitos-de-matricula.php`**, escrita ese día. No es un `SELECT` suelto: contesta las
> cinco preguntas que la fase 1 necesita —cuántos pasos, si el `orden` se usa, si alguien es dueño,
> qué proporción se cierra de verdad y cuándo fue la última vez— y **dice el nombre de la base en
> cada bloque**, que es lo único que impide volver a leer un cero sin su población.
>
> ```bash
> php tools/requisitos-de-matricula.php micolev1_lal_db      # o la base de lal donde viva
> php tools/requisitos-de-matricula.php --csv BASE [BASE…]   # para pegarlo aquí
> ```
>
> **No se pudo correr desde el repositorio**: el docker sólo tiene `simonbolivar`, y la base de
> `lal` vive en el servidor. Probada contra los cuatro caminos —el colegio que no lo usa, una base
> que no existe (**`NO MEDIDO`, nunca `0`**), el CSV y un argumento con forma rara—, y larastan en
> `[OK]`.
>
> **Lo que NO contesta, dicho para que nadie lo suponga**: cuántas estaciones tiene un día de
> matrículas —eso va en cartulinas, no en la base—, si un paso bloquea al siguiente, y si «falta»
> significa que no se entregó o que nadie lo marcó. **Esas tres deciden la mitad de la fase 1 y
> sólo las sabe el colegio.**

> ## ✅ LA FASE 1 DEL PROCESO: EL DÍA DE MATRÍCULAS, ROUTER EN 621 (20 sep 2026, `939ec20`)
>
> **Cuatro decisiones de Joseth, cada una con las opciones delante.** Documento:
> [`44-el-dia-de-matriculas.md`](44-el-dia-de-matriculas.md).
>
>     GET requisitos/recorrido/{alumno_id}    auth.personal    NO escribe
>
> | | |
> |---|---|
> | estaciones | **las que el colegio quiera** — editor de pasos, no plantilla |
> | frenar | cada una es **«obligatoria antes de continuar» u «opcional»** |
> | el número impreso | **ES `requisitos_matricula.orden`**, que ya existía |
> | quién cierra | **cualquiera del personal, con su nombre y su hora** |
>
> **Tres columnas donde la propuesta pedía diez**, y ninguna de las siete que faltan se cae por
> recorte: cada una la cerró una respuesta. *Una columna sin pantalla es `profesores.tono`, y van
> cinco en un mes.*
>
> ### La respuesta que parecía la más floja es la que hace esto desplegable
>
> *«¿«falta» significa que la familia no entregó, o que nadie lo marcó?»* — **«las dos cosas, según
> la estación»**. Eso convierte `bloquea` de lujo en necesidad: **el bloqueo no se puede encender de
> golpe**. Donde el dato es fiable frena; donde nadie marca, informa y deja pasar. Un bloqueo global
> habría mandado de vuelta a familias que sí entregaron, **el primer día y en la cola**.
>
> ### Un fallo visto antes de cometerlo, y uno cometido
>
> **Visto**: el `JOIN` natural para el nombre de quien cierra es `users.profesor_id`. Medido: **0
> filas** con esa columna, **47** con `profesores.user_id`. Al revés, el renglón habría salido en
> blanco en los diecisiete sin que nada fallara.
>
> **Cometido, y es mío**: `git add -A` en el árbol principal se llevó en `2503b27` **el Pint sin
> commitear de otra sesión** —`AlumnosController` y `ProfesoresController`, 3.600 líneas—. Rompí la
> regla que yo mismo tengo escrita: *respaldar sí, commitear no*. No se deshizo —`main` ya había
> avanzado por encima— y el rojo que introdujo está arreglado en `1b2368d`.
>
> > **Y cazarlo enseñó algo**: `git diff main..<rama>` dijo que el fichero era mío **y mentía**,
> > porque mezcla *«mi rama lo cambió»* con *«main lo cambió mientras yo iba por detrás»*. Es la
> > trampa que este documento ya tenía escrita para contar ramas. Quien lo contestó fue `git blame`.
>
> | | |
> |---|---|
> | `php artisan test` | **2.475 passed, 1 skipped**, sobre `1b2368d` con `main` dentro |
> | `route:list --json` | **621** en el árbol principal tras fundir (`939ec20`) |
> | `imports-de-facades.php --dry-run` | **0 de 698** — no queda ningún `use` por alias |
>
> ### Lo que falta, y no es código
>
> Correr `tools/requisitos-de-matricula.php` en `lal`: **ya no bloquea** —Joseth contestó a mano las
> tres preguntas que dependían de ella— pero dice **qué encuentra un colegio el día que despliegue
> esto**. Y las pantallas, que son de `myvc_front`.

> ## ✅ EL LIMITADOR: PREGUNTAR YA NO GASTA SUBIDAS (20 sep 2026, `f400145`)
>
> **Lo encontró `8myvc-dd` revisando `GET colillas-inscripcion/{codigo}` unas horas después de
> fundirlo**, y la causa no era un número mal puesto: la clave de un limitador con nombre es
> `md5($limiterName.$limit->key)` —`ThrottleRequests::handleRequestUsingNamedLimiter`, comprobado
> en el fuente— **sin el verbo y sin la ruta**. Con el mismo `throttle:colilla` y los mismos
> `by()`, el `GET` y el `POST` eran **un solo cubo de diez por hora**.
>
> **Y el síntoma llegaba antes de lo previsto.** El informe decía que fallaría el `POST` tras diez
> consultas; reproducido, **falla la undécima CONSULTA**: la familia ni siquiera podía preguntar
> once veces. Arreglado con `throttle:consulta-inscripcion` (60/h por IP **y** por código).
>
> > **Y un test mío que NO medía lo que decía, del mismo género.** `test_subir_sigue_topado` subía
> > dos comprobantes **a la misma orden** y esperaba 429 — pero eso pasa por el tope de «una
> > pendiente por orden», no por el limitador. Se destapó porque un `sed` pisó la línea del `POST`
> > y lo dejó con el limitador generoso, **y el test siguió en verde**. Reescrito para gastarlo
> > **por IP**, que es lo único que ese tope no tapa.
>
> **La cifra del correo, corregida en mis cuatro sitios y en dos capas.** La primera la levantó
> `8myvc-9a`: el 9,2 % es `acudientes.email` y todo busca por `users.email` —eran **0**, su arreglo
> los dejó en **91**—. **La segunda es de fondo: ninguna de las dos cuenta a esta gente.** Son
> acudientes de **matriculados**, y quien paga un formulario es la familia de un **aspirante**, que
> no tiene fila en `users` ni en `acudientes`. Medido: **este flujo no le pide el correo y ninguna
> de sus tres tablas tiene esa columna.** Para él el correo **no es un canal**.
>
> | | |
> |---|---|
> | `php artisan test` (las tres testsuites) | **2.459 passed, 1 skipped**, sobre `2454459` con `main` dentro |
> | `composer run pint:test` | **PASS**, 462 ficheros |
> | `route:list --json` | **620**, árbol principal sobre `main` tras fundir (`f400145`) |
>
> ### PENDIENTE QUE SALE DE AQUÍ, escrito sin hacer
>
> **Dos veces en la misma familia ya no es casualidad**: `…/campos` tragada por `{lote}` el 19 sep
> y `…/pendientes` tragada por `{codigo}` el 20. Un test **genérico** que recorra el router y
> compruebe con `getRoutes()->match()` que cada URI literal la atiende **su** acción cazaría la
> familia entera y las futuras. **Es idea de `8myvc-dd`.**

> ## ✅ LA FAMILIA PREGUNTA — LA 16ª PÚBLICA Y LA PRIMERA DE LECTURA, ROUTER EN 620 (20 sep 2026)
>
> **Encargo de Joseth**, al preguntar qué faltaba del formulario. El porqué entero está en
> [`41 §10`](41-el-formulario-de-inscripcion.md).
>
> **FUNDIDA el 20 sep 2026.** El 620 se contó primero en `.worktrees/es` con su condición de
> caducidad al lado y **se recontó al fundir, en el árbol principal sobre `main`**.
>
>     GET colillas-inscripcion/{codigo}    PÚBLICA    la llama la familia, sin cuenta
>
> ### El hueco estaba medido, y era de forma
>
> De las catorce rutas del formulario, **las tres públicas eran las tres de ESCRITURA**. La familia
> mandaba su comprobante y **no podía saber si se lo aprobaron, se lo rechazaron ni por qué** — el
> motivo ya se guardaba desde el 19 sep, y **sólo lo veía el personal**.
>
> **No espera al correo, y ése es el punto**: el aviso que debía cerrarlo va por correo, que está
> en rojo desde el 2 sep y **falla callado**. Esto es *pull* en vez de *push*: la familia entra con
> el código que lleva impreso el papel. *Arreglar el correo sigue haciendo falta; lo que ya no hace
> falta es esperarlo.*
>
> > **Y aquí se citaba el 9,2 % de acudientes con correo, que está mal DOS veces.** La primera la
> > levantó `8myvc-9a`: ese 9,2 % es `acudientes.email` y todo lo que manda correo busca por
> > `users.email` —eran **0**, y su arreglo los dejó en **91**—. **La segunda es la de fondo y es
> > mía: ninguna de las dos cifras cuenta a esta gente.** Las dos son acudientes de alumnos **ya
> > matriculados**, y quien paga un formulario es la familia de un **aspirante**, que no tiene fila
> > en `users` ni en `acudientes`. Medido: **este flujo no le pide el correo en ningún momento y
> > ninguna de sus tres tablas tiene esa columna.** Para él el correo **no es un canal**, y esta
> > ruta no es la mejor opción: es **la única**. Detalle en el [`41 §10`](41-el-formulario-de-inscripcion.md).
>
> ### Lo que devuelve lo decide que sea pública, no que le sirva a la familia
>
> La llave se dicta por teléfono y viaja en un papel que pasa de mano en mano, así que la pregunta
> de cada campo fue **«¿qué pasa si esto lo lee quien se encontró el papel?»**. Salen el estado, el
> valor, la fecha límite y **el motivo del rechazo** —que el tesorero escribe *para* la familia—.
> **No** salen el nombre del alumno, su documento, sus teléfonos ni el fichero del recibo: la URL
> es la llave. **Un código no puede revelar el nombre de un menor.**
>
> Eso **no lo ve ningún candado de autorización**, así que lo fija un test que busca el dato del
> alumno **en el JSON entero**, no campo a campo. *Un campo de más en una respuesta pública no
> rompe nada, no pone nada en rojo y no se nota hasta que importa.*
>
> ### EL AGUJERO QUE DESTAPÓ, Y ERA DE UNAS HORAS ANTES
>
> **La §9 había dejado medio cerrado su propio invariante.** `putCodigo` guarda el código retirado
> en `codigo_anterior` para que el papel viejo no quede huérfano, y **sólo la ruta del PERSONAL
> aprendió a buscar por él**: las dos públicas —subir el comprobante y pagar— seguían con `WHERE
> codigo=?`.
>
> **Corregir un código dejaba a la familia sin poder pagar**, y era **silencioso para las dos
> partes**: secretaría corrige creyendo que es inocuo, y la familia se estrella contra un 404 que
> no puede reportarle a nadie porque no tiene cuenta. Ni error, ni registro, ni llamada — sólo una
> inscripción que no se paga.
>
> Se arregló con una clase compartida (`App\Services\OrdenDeInscripcion`) **y no parcheando las dos
> consultas**, que era lo obvio y lo insuficiente: habría tapado el de hoy y dejado el de mañana,
> porque la siguiente ruta que reciba un código —van quince— se escribiría con la consulta obvia,
> que es la mala. *Un sitio compartido convierte «acordarse» en «no tener que acordarse».*
>
> ### Y LA TRAMPA Nº 3 DEL MÓDULO, COMETIDA OTRA VEZ AL DÍA SIGUIENTE
>
> `{codigo}` se registró **delante** de `…/pendientes` y **se tragaba la bandeja del tesorero**,
> que pasaba a contestar 422. Lo delató `getRoutes()->match()` — **no `route:list`, que ordena
> alfabéticamente y no por orden de registro**, así que la forma natural de comprobarlo miente.
> Es la misma trampa que `…/campos` antes que `…/{lote}`, en la familia de al lado. *Un aviso
> escrito no protege solo.* Lo fija un test que pide `…/pendientes` sin token y exige **401**: si
> se la tragara `getEstado`, daría 422.
>
> ### Los cinco sitios, que son los cinco justos
>
>     AutenticacionTest::SIN_GUARD              declarada con su motivo
>     RutasPreLoginTest::TOTAL_PUBLICAS         15 -> 16
>     AutorizacionTest::EXCEPCIONES_DE_FAMILIA  declarada: sus hermanas llevan guard
>     rutas.json                                619 -> 620
>     guard-por-familia.json                    colillas-inscripcion 4 -> 5 (con_guard sigue en 3)
>
> **Las dos que NO se movieron explican dónde ponerla**: `guards-por-ruta.json` lista las que
> **llevan** guard, y ésta no; y `familias-que-nunca-entran-en-el-candado.json` no la recoge porque
> `colillas-inscripcion` tiene **3 hermanas con guard** —≥ 2— así que el candado de familia sigue
> mirándola. *Por eso va ahí y no en `pagos-inscripcion`, que está en ese censo como «0 de 2».*
> `FamiliasQueNuncaEntranTest` sigue en **26**: esto lee.

> ## ✅ DEL PAPEL AL ALUMNO — LAS CUATRO QUE CIERRAN EL FORMULARIO, ROUTER EN 619 (20 sep 2026)
>
> **Encargo de Joseth**: *«terminemos lo del formulario de inscripción… lo del código único que no
> cambia cuando se le asigna a un alumno ni se repite cuando ya le dimos el formulario a un nuevo
> acudiente que vino por él»*. Alcance y permiso autorizados por él con las poblaciones delante.
> El porqué entero está en [`41 §9`](41-el-formulario-de-inscripcion.md); esto es dónde quedó.
>
> **FUNDIDA el 20 sep 2026** (`032a1a6`). El 619 se contó primero en `.worktrees/fi` **con su
> condición de caducidad al lado** —*«hay que recontarlo en el árbol principal el día que entre»*—
> y **se recontó al fundir, en el árbol principal sobre `main`: 619 otra vez.** Coincidir es lo
> único que no se puede saber sin contar las dos veces.
>
> | | |
> |---|---|
> | `GET informes/formularios-inscripcion/campana` | el informe: vendidos, recaudado y **quién compró y no volvió** |
> | `GET …/codigo/{codigo}` | lo que hay detrás de un código, **y encuentra también por el viejo** |
> | `PUT …/codigo/{codigo}` | corrige el código · `puedeAtarFormularios` dentro |
> | `PUT …/codigo/{codigo}/alumno` | lo ata · `puedeAtarFormularios` dentro |
>
> Más `Autoriza::puedeAtarFormularios`, `CodigoDeInscripcion::componer()` y **una migración**
> (`2026_09_20_100000`): `codigo_anterior` y dos índices.
>
> ### LAS CIFRAS, CON LA ORDEN QUE LAS PRODUJO Y EL ÁRBOL DESDE EL QUE SE CONTARON
>
> | | |
> |---|---|
> | `php artisan test --filter=DelPapelAlAlumnoTest` | **23 passed** |
> | `php artisan test` (las tres testsuites) | **2.437 passed, 1 skipped**, dos veces: sobre `9bf336d` en `.worktrees/fi` (52.721 aserciones) **y sobre `42508a2` en el ÁRBOL PRINCIPAL tras fundir**, con la base reconstruida 39/39 (52.733) |
> | `composer run stan` | `[OK] No errors`, **680** ficheros |
> | `composer run pint:test` | **PASS**, **454** ficheros |
> | `pint --test` (el repo entero, otra población) | `FAIL`, **695** ficheros y **182** avisos |
> | `route:list --json` | **619**, en `.worktrees/fi` · **recontar en el principal al fundir** |
>
> **Las aserciones NO cuadran entre las dos —52.721 y 52.733— y eso está bien**: `cb50685` ya
> dejó escrito que ese número se mueve solo y no es una huella. Lo que tiene que cuadrar es
> **2.437 y cero rojos**, y cuadra. *Medir dos veces sirve justo para esto: para saber qué parte
> del número es la que significa algo.*
>
> **Y son DOS índices y no tres, comprobado con `EXPLAIN` y no supuesto**: el informe filtra por
> `year_campana`, y el `UNIQUE (year_campana, alumno_id)` que ya existía **lo lleva de primera
> columna**, así que lo usa como prefijo. Un índice de más se mantiene en cada `INSERT` de una
> tabla que se escribe de cincuenta en cincuenta al imprimir una tanda.
>
> ### Las tres instantáneas que se movieron, revisadas una a una
>
> No se regeneraron y se pasó: se guardó la versión anterior y **se diferenció**.
>
>     rutas.json                615 -> 619
>     guard-por-familia.json    la familia del formulario, 6/6 -> 10/10 con guard
>     guards-por-ruta.json      las cuatro, dos en el grupo del GET y dos en el del PUT
>
> **La cuarta NO se movió, y por eso no se tocó**:
> `familias-que-nunca-entran-en-el-candado.json` lista las familias con menos de dos hermanas con
> guard, e `informes/` tiene de sobra más de dos. `RutasPreLoginTest` y `AutenticacionTest`
> tampoco: **ninguna de las cuatro es pública**.
>
> ### EL HUECO QUE TAPA, Y ERA EL CASO PRINCIPAL
>
> Las diez rutas del 19 sep acuñan, imprimen y cobran, y ahí se acababa. Medido con un `grep` de
> los `UPDATE` de esa tabla en todo `app/`: **hay dos y las dos escriben `estado="PAGADA"`**. O
> sea que **`alumno_id` sólo se escribía al acuñar una renovación**, y un formulario del modo
> `nuevos` —el del aspirante, que es el flujo que motivó el encargo— **no se ataba a nadie
> jamás**. `matricula_id` y `estado='MATRICULADA'` **no los escribía nadie**, aunque
> `PagosInscripcionController` ya nombre el segundo en `YA_NO_SE_COBRA`.
>
> Es **`profesores.tono` por cuarta vez en un mes** y otra vez **no lo destapó un barrido**
> —`interruptores-que-nadie-lee.py` mira `tinyint(1)` y esto es un `int` y dos `varchar`—: lo
> destapó que la función siguiente necesitaba el dato.
>
> ### EL CONTROL VISTO EN ROJO ENSEÑÓ ALGO QUE NO SE SABÍA
>
> | lo que se rompió | qué cayó |
> |---|---|
> | el informe cuenta `estado` en vez del `JOIN` | el test que lo nombra, y sólo ése |
> | corregir sin guardar `codigo_anterior` | dos |
> | atar reacuña el código | seis, el primero el que lo nombra |
> | la lectura previa de «¿ya tiene el suyo?» | **NADA** |
>
> **Ese último renglón no es un test flojo: la propiedad la sostienen DOS mecanismos
> independientes.** Quitando sólo la lectura previa, el `UNIQUE (year_campana, alumno_id)` la
> atrapa igual; quitando sólo el `catch`, la atrapa la lectura; quitando **los dos**, cae
> exactamente `test_un_segundo_acudiente_no_gasta_otro_codigo` y ningún otro. Es lo que hay que
> querer de un test así: **comprueba la propiedad, no el camino**.
>
> > **Y el primer intento de ese control NO MIDIÓ NADA y decía que sí.** Neutralizar el `catch`
> > con una sustitución de texto dejó el método mal formado, y los **23** tests cayeron con
> > **61 aserciones** en vez de 198. Leído deprisa, «23 en rojo» parece un control potentísimo;
> > lo que era es un fichero roto. **El delator fue la cuenta de aserciones, no la de tests** — y
> > es la trampa ya escrita en `CLAUDE.md`: *el detector corre, contesta lo que le preguntaron y
> > contesta bien; quien pregunta mal es uno.*
>
> ### Las tres decisiones que no eran obvias
>
> 1. **Los matriculados no se cuentan por `estado`: `JOIN` vivo contra `matriculas`.** Esa tabla
>    tiene **ocho escritores** en `app/` y ninguno es de este módulo, así que la columna va por
>    detrás siempre que alguien matricule por los otros siete caminos: contarla daría una cifra
>    que **baja sola** y metería en la lista de llamadas a gente que ya está en clase. Enganchar
>    una escritura nuestra en los ocho era tocar el camino caliente de los dieciséis colegios por
>    una columna que sólo lee este informe. *Una caché con ocho escritores ajenos es
>    `notas_finales` otra vez.*
> 2. **`codigo_anterior`, y es lo que hace seguro corregir.** El código viejo está impreso en un
>    papel que está en casa de una familia: sin guardarlo, corregir lo convierte en basura
>    silenciosa —«no existe», y nadie puede saber que existió—. La respuesta dice
>    `encontrado_por` para que la pantalla lo avise. Guarda **uno solo**; corregir dos veces deja
>    huérfano el primero, y va dicho.
> 3. **El permiso se parte en dos, al revés que las otras cuatro de la familia.** Las lecturas
>    con `auth.personal` a secas —mirar el papel que a uno le ponen delante es lo que hace un
>    docente en la estación de documentos—; las dos escrituras con criterio dentro, porque atar
>    decide de quién es un cobro. **No se reusó `esAdministrativo()`** aunque hoy devuelva lo
>    mismo: lo comparten quince llamadas y ensancharlo ensancharía esta puerta sin que nadie lo
>    decidiera.
>
> ### DOS hallazgos de nombre, y el segundo lo cometí yo el mismo día
>
> **`pagado_at`, en la lista de «compró y no volvió», era `updated_at`** — o sea la última
> modificación de la fila, no la fecha del pago: corregir el código de un formulario ya pagado la
> movería y la lista diría que pagó hoy. **Es el mismo pecado que `vendida_at`, escrito en el
> mismo fichero y el mismo día que lo denuncié**, y no lo cazó ningún test —el nombre de una
> clave no lo comprueba nadie— sino releer la consulta antes de darla por buena. Se llama
> `actualizado_at`, y el test fija ahora que `pagado_at` **no** está.
>
> *Un nombre que miente no falla: pasa la suite, pasa larastan y llega a la pantalla.*
>
> ### Y el primero, que es el de la tanda anterior: `vendida_at` NO es la fecha de venta
>
> Se escribe **al acuñar**, o sea al imprimir. Cincuenta formularios en blanco no son cincuenta
> ventas, así que un informe que sumara sobre esa fecha contaría como recaudado todo lo que salió
> de la impresora. `recaudado` suma sobre el **estado**. Las columnas no se renombran —están
> desplegadas— pero el nombre queda desmentido por escrito.
>
> ### ⚠️ LAS 29 BASES DE TEST DEL DOCKER NO TIENEN LA COLUMNA, Y NO SE LES PUSO A MANO
>
> Medido el 20 sep 2026: de las 30 bases `%testing%` del contenedor, **sólo
> `simonbolivar_testing_fi` tiene `ordenes_inscripcion.codigo_anterior`**. Cualquier sesión que
> traiga `main` y corra la suite **sin reconstruir su base** verá `DelPapelAlAlumnoTest` en rojo,
> y **el rojo no dirá por qué**: dirá que una propiedad no existe.
>
> **No se les añadió a mano, y el porqué es lo que importa:** un `ALTER` manual deja una base que
> **miente sobre su propio estado de migración** —la columna está y la fila de `migrations` no—,
> así que el siguiente `artisan migrate` de esa sesión moriría con `Duplicate column name` y le
> pararía la tanda entera por un favor que nadie pidió. La orden que lo arregla ya está escrita y
> es la de siempre:
>
> ```bash
> DB_TEST_DATABASE=simonbolivar_testing_<x> PHP_EXEC="docker exec -i -w /app/.worktrees/<x> 8myvc-app-1" \
>     tools/construir-bd-test.sh
> ```
>
> **Lo que sí se hizo es que la migración no pueda romper esa tanda**: comprueba
> `Schema::hasColumn` y dice *«ya existe, no se toca»*, igual que
> `crear_uniformes_donde_falte`. Protege los dos casos reales — una tanda cortada que se
> reintenta, y una base a la que alguien le puso la columna a mano.
>
> > **Y se vio el rojo antes que el verde, por accidente y por el mismo sitio de siempre.** El
> > primer intento corrió `migrate` con `-w /app/.worktrees/fi`, que usa **la copia del worktree**
> > —donde la rama aún tenía la versión sin idempotencia— y dio `Duplicate column name`. El
> > detector contestó bien; **quien preguntó mal fui yo**. Repetido desde el árbol principal,
> > `DONE`. El accidente salió gratis: es el control en rojo que había que buscar a propósito.
>
> ### Lo que NO hace, para que nadie lo suponga
>
> - **No crea el alumno**: ata a uno que ya existe. Es donde encajará `aspirantes` (§7 de
>   `myvc_front/INVESTIGACION-MATRICULAS.md`).
> - **No engancha en el flujo de matrícula**, por el punto 1.
> - **No manda ningún aviso**: sigue abierto y sigue dependiendo del correo, que está en rojo
>   desde el 2 sep.

> ## 📐 LAS ESTACIONES DE MATRÍCULA, ATENDIDAS DESDE LA APP — DISEÑO Y CONTRATO, **CERO CÓDIGO** (20 sep 2026)
>
> **Pedido: que alguien del personal pueda atender una estación del día de matrículas desde el
> teléfono, sin web**, que al cerrar su paso le aparezca esa persona a la estación siguiente, y
> que pueda seguir buscando a cualquier alumno del colegio para ver en qué va.
>
> El plan de matrículas del front —`myvc_front/INVESTIGACION-MATRICULAS.md` y
> `PANTALLAS-MATRICULA.md`— pone las estaciones en **`app2`, o sea en la web**. Quien atiende
> la estación 2 es un docente de pie con una fila delante. De ahí sale esto:
>
> - **[46-las-estaciones-en-la-app.md](46-las-estaciones-en-la-app.md)** — el contrato: **ocho
>   rutas**, la tanda de columnas, el permiso partido en dos y lo que mueve.
> - **`myvc_flutter/docs/estaciones.md`** — las doce pantallas de teléfono con sus porqués.
> - Maqueta navegable: https://claude.ai/artifact/3fixY3xaQsjGT2V4LAWPbE
>
> ### Lo que salió midiendo, y no estaba en el plan
>
> 1. **`requisitos_alumno.estado` no tiene vocabulario cerrado** y `postAlumno` escribe tal cual
>    lo que venga en el cuerpo. **Toda la cola de una estación es la pregunta «¿está cerrado el
>    paso anterior?»**, así que una minúscula escrita por una pantalla vieja borra a una persona
>    de la fila siguiente sin que nadie se entere. Fijar esa columna va **antes** que las siete
>    rutas. Es el mismo sitio donde ya vivía el `estado=NULL` que se arregló el 1 sep.
> 2. **El aviso a la estación siguiente hoy no puede ser un push.** `myvc_flutter/pubspec.yaml`
>    tiene `firebase_core` y `firebase_analytics` y **no `firebase_messaging`**; y el disparo
>    del servidor que sí está desplegado desde el 25 ago va **cada quince minutos**, que para
>    una fila en un patio es no avisar. Lo que funciona es **la cola sondeada con una huella
>    barata**, el patrón del [34](34-la-huella-de-sincronizacion.md).
> 3. **La cola es una consulta, no una bandeja de avisos.** Un aviso perdido deja a la familia
>    en la fila igual; una bandeja con un aviso perdido la deja invisible para siempre.
> 4. **El globo de notas —lo pidió Joseth el 20 sep— destapa una trampa de la huella, y la
>    regla del [34](34-la-huella-de-sincronizacion.md) ya la tenía resuelta.** Una nota escrita
>    en la estación 5 tiene que hacer aparecer el globo al que atiende la 2, así que la huella
>    de la 2 **se calcula sobre lo que devuelve su cola** —que trae el conteo de notas de todas
>    las estaciones— y no sobre la tabla anotada. Escrito hace trece días para otro módulo y
>    contesta ésta sin tocarla.
>
> ### Lo que esta rama daba por abierto y `main` ya contestó — leído al fundir
>
> **La que esta rama llamaba «la que más bloquea» está decidida.** Preguntaba *«¿estación con
> rol o con persona?»*; Joseth contestó ese mismo día, en el [44](44-el-dia-de-matriculas.md)
> §2: **ninguna de las dos** — *«cualquiera del personal puede cerrar, pero queda con su nombre
> y su hora»*—, y **no hace falta `rol_id`**. No es una previsión: está desplegado —
> `postAlumno` escribe `cerrado_por`/`cerrado_at` con `COALESCE`, y la migración
> `2026_09_20_300000` ya puso las tres columnas.
>
> **Las dos sesiones se cruzaron por diez minutos** y ninguna podía ver a la otra: esta rama
> salió de `fe74442` y cerró a las 17:29 UTC; la fusión del día de matrículas entró a las
> 17:39. Es la **segunda** colisión de doc 44 del mismo día, y se corrige igual que la primera
> —mirando `main`—: este documento pasa a **46**.
>
> **Y eso mueve el diseño, que es lo que hay que mirar antes de construir**: `estaciones.md`
> §2.9 dice *«ver todo, cerrar sólo lo tuyo»* y lo pone en el servidor. Con la respuesta de
> Joseth, cerrar lo abre **cualquiera del personal** y lo que protege no es un 403: es la firma
> visible y el deshacer. Está dicho aquí, sin re-litigar, porque la §2.9 se escribió sin esta
> respuesta delante.
>
> **Lo que sigue abierto de verdad**: las otras cuatro de la §5 del [46](46-las-estaciones-en-la-app.md)
> —el aviso al acudiente, el push inmediato, el aviso de una nota, y las ocho rutas con el
> precio delante—. Y el vocabulario de `estado`, que sigue siendo un `varchar` sin cerrar.
>
> **Nada de esto toca código todavía**: no hay rutas nuevas, ni migraciones, ni instantáneas
> movidas. El router está en **623**, no en las 615 que esta rama contó: salió de `fe74442`.


> ## ✅ LA COLA DE RAMAS, VACIADA: OCHO FUSIONES, ROUTER EN 615 Y `origin/main` AL DÍA (19 sep 2026, noche)
>
> **Encargo de Joseth: reunir lo que estaban haciendo las otras sesiones, arreglar `main` —que
> estaba 1 adelante y 7 por detrás de `origin/main`— y dejar local y remoto sincronizados.**
> Hecho en el árbol principal por `8myvc-8b`, con `8myvc-47` trabajando a la vez y repartiéndose
> el árbol por mensaje.
>
> ### Lo primero, que no era fusionar nada: los dos duplicados
>
> El commit que `main` tenía de más —`1be0414`, el de las imágenes publicadas— era **byte a byte
> idéntico** a `52f1b98`, que ya estaba en `origin/main`. Lo dijo `git rebase` él solo:
> *«skipped previously applied commit»*. Y lo que colgaba sin commitear en el árbol principal era
> **una segunda redacción entera de la P6**, escrita por otra sesión en paralelo: `ContextoDeUsuario`
> y las cuatro instantáneas `login-contexto-*` salieron **byte a byte iguales** a las publicadas, y
> el candado era otra implementación con los mismos tres puntos de llamada. Está guardada en
> `rescate/p6-copia-paralela-del-arbol-principal`, **no fundida y con el porqué dentro**.
>
> > **Y ese trabajo «duplicado» pagó solo unas horas después**: `8myvc-47` comparó su candado
> > contra el publicado y encontró **dos fallos reales** —reordenar escribía la mitad del lote
> > antes de abortar con 403, y un `null` se colaba— que `8myvc-14` reprodujo y arregló
> > (`befdd41`, fundido aquí). Lo que parecía esfuerzo tirado era una **revisión cruzada sin
> > acordarla**.
>
> ### Las ocho, y las tres que entraron A MEDIAS a propósito
>
> | rama | qué entró |
> |---|---|
> | `docs/despliegue-remedido` | **sólo la casilla**: `DESPLIEGUE.md` decía 232 commits (4 sep) y `main` lleva **321** (5 sep). Fundirla habría hecho retroceder el número |
> | `docs/appkey-compartida-fortul-lal` | todo **menos** `29-los-env` §5: la rama la cerraba el 3 sep y `main` la tiene cerrada el **7**, con las dos mediciones de Tauri dentro |
> | `fix/columnas-en-los-modelos-no-borra` | entera; el conflicto de `AutopruebasDeLasHerramientasTest` se resolvió como **unión**: conviven las tres herramientas |
> | `fix/importacion-fase-1` | el código y el Pint; los docs **40 y 41 se quedan los de `main`** — la §5 de la rama son preguntas que Joseth contestó el 19 sep |
> | `feat/la-casilla-vacia` | entera (sin su Pint, que su sesión tenía a medias) |
> | `feat/calendario` | la funcionalidad; **`ChangeAskedController` se queda el de `main`** — ver abajo |
> | `fix/reparar-la-hora-y-uniformes` | entera: dos migraciones que llevaban **trece días** escritas y probadas |
> | `fix/candado-los-dos-agujeros` | entera: los dos fallos del candado P6 |
>
> **`feat/muro-para-la-app` la fundió `8myvc-47`** en paralelo, y recontó el router en 613.
>
> ### Lo que costó más y no se ve en el diff: lo que NO se adoptó
>
> **`feat/calendario` llevaba dieciocho días fuera y `ChangeAskedController` lo han reescrito tres
> sesiones en ese tiempo.** Lo único que la rama le cambia es sustituir cinco `SELECT *` por
> `CalendarioController::COLUMNAS` —la mejora correcta **de entonces**—, pero esa constante nombra
> **diecisiete** columnas y `main` hizo después la versión medida: **nueve**, porque el calendario
> era el 84–96% de esa respuesta y con nueve pesa **un 47% menos**. Fundirla habría devuelto siete
> columnas de contabilidad a las cuatro ramas de `getToMe` **sin poner nada en rojo**.
>
> *Una rama vieja no se funde comprobando que no rompa: se funde comprobando qué deshace.* Las
> tres fusiones a medias de la tabla son el mismo caso — documentación que esperó dos semanas y
> llegó **desmentida por lo de después**, no pendiente.
>
> ### Las cifras, y de dónde salen
>
> - **615 rutas**, `route:list --json` en el **árbol principal, sobre `main` y después de fundir**
>   (`b5f5345`). 613 + las dos del calendario.
> - **`FamiliasQueNuncaEntranTest`: 24 → 26**, y la cifra **se midió corriendo el test**, no
>   sumando: la rama traía `assertCount(25)`, cierto el día que se escribió. El motivo del renglón
>   es un **tercero** —preguntan de quién es la fila dentro del método—, distinto del guard y
>   distinto de «la llave es el dato» de `pagos-inscripcion`.
> - **Base de tests reconstruida** (entraron cuatro migraciones): 38/38, 112 tablas.
>
> ### Y una cosa que no era del encargo y salió de camino: la tanda ha TRIPLICADO
>
> `DESPLIEGUE.md` describe la tanda pendiente como **SIETE migraciones y 321 commits**, medido el
> 5 sep. Recontado esta noche sobre `8279c62`: **25 migraciones y 632 commits**, con el router en
> 615 y no en 578. **La tabla no se rehace** —la regla es que se remide entera el día del
> despliegue, no fila a fila— pero lleva ya un aviso fechado encima con las tres cifras y las
> órdenes que las rehacen.
>
> **Se escribe hoy y no el día del despliegue porque el congelado se levantó hoy**: el siguiente
> que abra ese documento puede ser alguien a punto de subir a los dieciséis colegios, y llegar
> con «son siete» cuando son veinticinco es decidir a las tres de la mañana si las dieciocho que
> sobran son legítimas.
>
> ### La suite entera, y los TRES ROJOS que destapó — los tres de antes
>
> **`php artisan test` sobre el árbol fundido: 3 rojos y 2.411 verdes**, los tres en la testsuite
> `Unit` (`Contrato` 2.236 y `Feature` 20, verdes). **Ninguno lo causaron las fusiones**: dos ya
> estaban en `origin/main` y el tercero es deuda vieja que un test recién fundido acaba de poder
> ver. Arreglados los tres en `d6d6471`:
>
> 1. **`use Log;` en `UnidadesController`** — lo dejó el Pint de la P6 (`3ce3056`) y resuelve por
>    el array `aliases`. Es **el mismo fallo que `e4686ba` arregló en `ImporterFixer` el mismo
>    día**, en otro fichero. Aquí se borra la línea: ese controlador no nombra `Log` ni una vez.
> 2. **El censo de interruptores, 93 contra 92** — `por_defecto` lleva **tres** cruces entre
>    «decorativa» y «decide algo» (D14 el 13 sep, la Fase 2 deshaciéndolo el 17, y
>    `CandadoDeLaPlantilla:99` el 19). Los dos primeros los apuntó quien los causó; el tercero no.
> 3. **Cuatro funciones globales declaradas en siete herramientas** —`pedir`, `token`, `contar`,
>    `pedirMidiendo`— copiadas con **firma idéntica**, que es justo lo que larastan no denuncia.
>
> > **Y el porqué de que nadie los viera es el aviso que ya estaba escrito**: aquí la cifra se
> > publica casi siempre con `--testsuite=Contrato`, **que no ejecuta `Unit`**. `CLAUDE.md` lo dice
> > desde el 7 sep. *Un aviso que ya está escrito no protege solo; sólo protege el día que alguien
> > hace lo que dice* — y esta noche el que lo hizo fue correr la suite entera antes de empujar.
>
> **Verde tras arreglarlos: `Tests: 1 skipped, 2414 passed (52539 assertions)` (`php artisan
> test`), larastan `[OK] No errors` (678 ficheros), `pint:test` PASS (450).** Con eso empujado:
> `origin/main` pasa de `befdd41` a `d6d6471`, 37 commits, y local y remoto quedan a cero.
>
> ### Lo que NO se tocó, y por qué
>
> - **El Pint sin commitear de `.worktrees/vacia`** (3.391 + 985 líneas) y el de `e6`: eran de
>   sesiones que en ese momento tenían **una suite entera corriendo encima** (41% y 59% de CPU,
>   comprobado en el contenedor, no supuesto). Commitearlo desde fuera les habría cambiado el
>   árbol bajo una medición en curso.
> - **`medicion/dos-columnas-en-listasignaturas`**: obsoleta. Su única línea —`a.materia_id,
>   g.grado_id`— ya está en `origin/main` por `0d9af81`.
> - **`.worktrees/2e`**: sólo una sonda de medición sin commitear y un `Snapshots.parcial/`.

> ## ✅ `GET muro/app` — EL MURO SIN EL CALENDARIO, ROUTER EN 613 (19 sep 2026 — **FUNDIDA esa noche**, ver la casilla de la integración arriba)
>
> **Pedido por `myvc_flutter` (§5 de su `backend-pendiente.md`) y autorizado por Joseth el
> 19 sep con las cuatro opciones delante.** Está en `.worktrees/muro`, rama
> `feat/muro-para-la-app`, **sin fundir**: el contador de `CLAUDE.md` dice 613 con esa
> condición escrita al lado, y hay que recontarlo en el árbol principal el día que entre.
>
> **El problema**: `GET ChangesAsked/to-me` manda **128 KB de calendario** (593 filas) que la
> app **no lee en ningún rol** —comprobado: `eventos` no aparece ni una vez en todo `lib/` de
> Flutter—, y siete consultas por acudido de las que sólo mira una. El argumento no es el
> coste medio sino el pico: una notificación push hace que cientos de teléfonos abran a la
> vez contra un hosting de un núcleo.
>
> ### Lo que cambió respecto al encargo, y las tres cosas son medidas
>
> **1 · La opción barata no existía.** §5 ofrecía «no mandar `eventos` a quien no lo pinta,
> sin estrenar ruta». **No se puede**: este backend **no distingue la app del front web** —el
> mismo token, el mismo `tipo`, y no hay cabecera de cliente—, así que vaciar `eventos` para
> un acudiente se lo quita también al acudiente que abre el panel **en el navegador**, cuya
> carga inicial sale justamente de ahí (`myvc_front`, `AnunciosCtrl.ts:1485`). O sea que la
> ruta nueva no es la opción cara: **es la única que no le quita nada a nadie.**
>
> **2 · El contrato pedía tres claves y la app lee CINCO** (`MuroApi.dart`, `cuerpo['...']`).
> Faltaban **`horario_version_id`** —que existe por decisión de Joseth del 2 sep para que
> `horario_hoy: []` deje de significar dos cosas— y **`ausencias_periodo`**, la asistencia
> del propio alumno. Es *«un encargo correcto deja atrás lo que no nombra»* otra vez.
>
> > **Y el modo de fallo que yo escribí para la primera estaba DEL REVÉS.** Dije que sin ella
> > volvía el mensaje falso de agosto —«Hoy no tienes clases» a todo el mundo—; lo corrigió
> > `myvc_flutter-c2` y se comprobó en su fuente: `HorarioDeHoy.tomar` hace
> > `_clases = versionOficial == null ? null : clasesDeHoy`, así que sin la clave `seSabe`
> > vale **`false`** y la app **no dice nada** — `MuroScreen` esconde el bloque y el filtro
> > «sólo las de hoy» de `NotasScreen` se apaga. **Omitirla apaga la función en silencio en
> > vez de mentir**, que es más barato y exactamente igual de invisible.
> >
> > La conclusión no se movió —la clave viaja, y el test la exige— pero el argumento escrito
> > sí, y la diferencia importa: es lo que hay que buscar el día que alguien la quite. Estaba
> > mal en el controlador, en el test y en el mensaje del commit `eb13d8e`, que no se puede
> > reescribir; corregido en los dos primeros.
>
> **3 · El calendario no tiene ni una fila de 2026.** Las 593 van de 2019 a 2025 y 86 son
> anteriores a 2024: **nadie ha curado esa tabla**. Filtrar por año lo dejaría hoy vacío en el
> front web —que es la verdad y parece una avería—, así que **no se hace aquí** y queda
> escrito. De paso: `calendario/this-year` **no filtra por año** pese al nombre y sigue
> mandando los **215,5 KB** con `SELECT *` —el recorte del 2 sep arregló `to-me` y no a ella—,
> y es la que llama el botón «Actualizar» del panel.
>
> ### Lo que cazaron las pruebas y habría salido el día del despliegue
>
> - **`persona_id` y no `profesor_id`**: para un Profesor esa propiedad **no existe** en el
>   contexto (la de `profesor_id` es la rama del superusuario) — «Undefined property» y 500.
> - **El seed no tiene ni un acudiente con acudidos**: los **76** viven en el año `1` y las
>   matrículas están en el `7` y el `8`. Sin fabricar el caso dentro del test, `alumnos` sale
>   `[]` y los casos de columnas pasan **sin mirar una sola fila** — y lo que no se miraría
>   son datos personales de un menor.
>
> ### Lo que mueve, y el renglón que hay que leer con cuidado
>
> **Cuatro instantáneas, no tres**, porque estrena familia: `rutas.json`,
> `guard-por-familia.json`, `familias-que-nunca-entran-en-el-candado.json` —donde entra como
> **`muro: 0 de 1`**— y `guards-por-ruta.json` **no se movió** (lista las que declaran guard).
>
> **Ese `0 de 1` es la forma exacta que tendría un agujero**, y se acepta **con el motivo
> escrito**: el censo cuenta `->middleware(...)` **declarado en la ruta**, y ésta no declara
> ninguno porque vive dentro del grupo `auth.token` de `routes/api.php`. Es el mismo caso que
> `notificaciones: 0 de 1`. Lo que lo distingue de un agujero de verdad no es este párrafo:
> es `MuroParaLaAppTest::test_sin_token_no_contesta`, que exige **401**.
>
> ### Estado
>
> `MuroParaLaAppTest`: **7 passed (35 assertions)** (`--filter=MuroParaLaAppTest`,
> `--testsuite=Contrato`, en `.worktrees/muro` con `DB_TEST_DATABASE=simonbolivar_testing_muro`).
> Los candados de ruta: **82 passed** (`--filter='Rutas|Autoriza|Autenticacion|FamiliasQueNuncaEntran'`).
> `pint:test` **PASS, 433 ficheros** —`MuroController` entra en la lista curada en este mismo
> commit—. **`ChangesAsked/to-me` no se tocó**, y hay un test que lo ata: le sigue mandando el
> calendario al front web.
>
> **Lo único que se tocó del fichero viejo** es que `asignaturas_dia` pasa de `private` a
> `public static` —no usa `$this`, comprobado antes de moverla— para que las clases de hoy se
> lean **de un solo sitio**. Cuatro llamadas actualizadas, ninguna respuesta movida.

> ## ✅ LA CASILLA VACÍA — FASE 0, ENTERA Y DENTRO (19 sep 2026, noche)
>
> **Rama `feat/la-casilla-vacia`, FUNDIDA el 19 sep 2026 por la noche.** El párrafo que había
> aquí era una instrucción con su condición de caducidad puesta —*«mientras la rama no esté
> fundida, el árbol principal NO tiene nada de esto y `notas.nota` sigue siendo `NOT NULL`»*— y
> hoy se cumplió esa condición, así que se sustituye en vez de dejarla envejecer a mentira.
> `notas.nota` ya es anulable en el árbol principal.
>
> **Y el Pint también entró, que era lo único que quedaba fuera.** La línea de arriba decía *«lo
> que sigue fuera es sólo su Pint, que su sesión tenía a medias»*, y ya no: `NotasController` y
> `Models/Nota` están formateados y en la lista curada de `composer.json` —23 rutas—, en un commit
> aparte del cambio de comportamiento porque son 4.378 líneas de diff y meterlas juntas lo dejaba
> sin poder revisarse. **Nada de esta entrada sigue pendiente de fundir.**
>
> Suite sobre el árbol fusionado: `Tests: 1 skipped, 2414 passed` (`php artisan test`);
> `PASS 452` (`composer run pint:test`); `OK` (`composer run stan`).
>
> El porqué entero, medido, en [`43`](43-lo-que-todavia-no-se-ha-calificado.md). En una línea: una
> casilla de `notas` nace con el indicador y vale 0 desde ese instante, así que **a mitad de periodo
> el plan entero pesa** — el semáforo sale rojo completo y el acudiente ve definitivas perdidas con
> todo lo calificado en SUPERIOR. Medido en el docker: en el periodo 2 de 2025, de **767** pares
> alumno-asignatura con alguna nota puesta salían **767 en rojo**, **539** no estaban perdidos y
> **258** iban en SUPERIOR.
>
> ### Las siete decisiones de Joseth del 19 sep, para no re-litigarlas
>
> | | |
> |---|---|
> | **D1** | La familia ve **la parcial** (sobre lo evaluado). Sin interruptor: una forma para los dieciséis. |
> | **D2** | El semáforo pinta **gris por debajo del 15 % evaluado**. |
> | **D3** | Al cerrar, lo no calificado: **lo elige cada rector** (columna en `years`), **con «pasa a 0» de fábrica**. |
> | **D4** | El estado **NE** por celda: **no por ahora**. |
> | **D5** | Las **fechas** de los indicadores: **fuera de alcance**. |
> | **D6** | «Sin calificar» se escribe como **`notas.nota` anulable**, no como una columna al lado. **Propuesta de Joseth**, contra la que traía la sesión. |
> | **D7** | «Quitar la nota» es **`update` con `nota: null`**; `destroy` se queda para borrar la fila. |
>
> ### Lo que la fase 0 deja escrito, y lo que NO se puede desplegar suelto
>
> - **Migración `2026_09_19_500000_la_casilla_vacia`**: `nota` anulable + relleno **acotado a
>   periodos abiertos** (`profes_pueden_editar_notas = 1`). En la copia de desarrollo eso son
>   **20.655** filas de 1.166.608 — el 1,8 % — y deja intactas las **1.053.592** de periodos
>   cerrados, cuyas definitivas ya están impresas.
> - **Las tres siembras de `notas` nacen con `NULL`** en vez de con `subunidades.nota_default`:
>   `Nota::verificarCrearNotas`, `Nota::verificarCrearNota` (que **pierde un parámetro**) y
>   `NotasController::putSubunidad`.
> - **`putUpdate` gana `Request::has('nota')` → 422**, y esto **va en el mismo commit que la
>   migración, obligatoriamente**: hasta hoy el `NOT NULL` de la columna era lo único que impedía
>   que un cuerpo sin `nota` borrara la nota, y lo hacía abortando en producción (MariaDB estricto,
>   `1048`). Con la columna anulable, eso pasa a ser **un borrado silencioso**.
> - **`putLote`**: el vacío explícito quita la nota; el ítem **sin la clave** sigue siendo un fallo.
>
> ### Lo que falta, por orden
>
> 1. ~~**Medir el `ALTER` contra MariaDB 10.5.**~~ **HECHO el 20 sep 2026, y sale bien:
>    `tools/ensayo-del-alter-en-maria.sh`.** MariaDB 10.5 lo hace **`INPLACE` con `LOCK=NONE`**
>    —`INSTANT` y `NOCOPY` no los soporta, y el propio motor contesta *«Try ALGORITHM=INPLACE»*—,
>    o sea que **no bloquea el guardado de notas**. `ALTER` **6,96 s** sin cláusula (MySQL 8 daba
>    8 s), `UPDATE` del relleno **0,49 s** y 20.655 filas con un plan que **no recorre `notas`**.
>    Comprobado escribiendo desde otra conexión mientras corría, **en dos pasadas**: peor latencia
>    **355 ms** y **1.003 ms**, frente a los **9.448 ms** y **6.874 ms** del control con
>    `COPY, LOCK=SHARED` —que es lo que hace que el verde signifique algo—. **No hace falta ventana
>    de mantenimiento**, pero *«no bloquea»* no es *«no se nota»*: **puede haber un tirón de ~1 s**
>    por el cerrojo de metadatos breve que un DDL en línea toma al principio y al final. La
>    escritura se completa; nadie pierde una nota.
>
>    > **Y esta casilla decía que era «lo único que puede tumbar la forma de la fase 0», que estaba
>    > sobredimensionado y se vio al releer la migración.** El modo de fallo «revienta» ya estaba
>    > cerrado por construcción —la migración **no escribe `ALGORITHM=`** a propósito, así que un
>    > motor que no pueda hacerlo `INPLACE` cae a copia y termina— y el del motor lo cerró Joseth el
>    > 5 sep censando las 18 bases (todas InnoDB, ninguna `COMPRESSED`). Lo que de verdad quedaba
>    > abierto era **la ventana de despliegue**, no el diseño. *Un pendiente heredado se relee antes
>    > de repetirlo: el que lo escribió no sabía lo que se arregló después.*
>
>    **Lo que sigue sin medir** es el reloj sobre CloudLinux, que limita I/O por cuenta; el ensayo
>    corrió en un Mac con Docker. Viaja el algoritmo, no los segundos — y al no bloquear escrituras,
>    los segundos dejan de gobernar nada.
> 2. **El relleno no lo ejercita la base de tests**: `construir-bd-test.sh` migra **antes** de cargar
>    el seed, así que el `UPDATE` encuentra cero filas y sale `0`. Hay que probarlo aparte.
> 3. `nota_default` **queda inerte pero sigue aceptándose** en `PlantillaNotasController`: es un
>    interruptor que ya no lee nadie, y retirarlo de la pantalla es trabajo del front.
> 4. Fases 1 a 4 del [`43`](43-lo-que-todavia-no-se-ha-calificado.md).

> ## ✅ EL FORMULARIO DE INSCRIPCIÓN IMPRESO Y SU COBRO — LAS DIEZ RUTAS, ROUTER EN 612 (19 sep 2026)
>
> **Autorizado por Joseth con el precio delante**, que es como entra una familia nueva aquí. El
> porqué entero está en [`41`](41-el-formulario-de-inscripcion.md); esto es dónde quedó.
>
> | | |
> |---|---|
> | `POST` / `GET informes/formularios-inscripcion[/{lote}]` | acuña el lote · relee sin acuñar |
> | `GET` / `PUT informes/formularios-inscripcion/campos` | qué campos imprime cada colegio |
> | `POST colillas-inscripcion/{codigo}` | **PÚBLICA** — la manda la familia |
> | `GET` / `PUT colillas-inscripcion/pendientes · {id}/aprobar · {id}/rechazar` | el tesorero |
> | `POST pagos-inscripcion/{codigo}/checkout` | **PÚBLICA** — el pago en línea |
> | `POST pagos-inscripcion/webhook` | **PÚBLICA** — la llama la pasarela, no una persona |
> | **612** contado en el árbol principal tras fundir (`982a8cb`), no sumado; **15 públicas** | |
> | `php artisan test`: **2.348 passed, 1 skipped (50.757 assertions)**, `.worktrees/92` | |
>
> Más `App\Services\CodigoDeInscripcion`, `App\Services\Pasarela\Wompi`, **cinco tablas** y tres
> migraciones (`2026_09_19_100000`, `_200000` y `_300000`).
>
> ### EL HUECO QUE DESTAPÓ EL PAGO EN LÍNEA, Y QUE JOSETH CERRÓ EL MISMO DÍA
>
> **`ordenes_inscripcion.valor` no lo escribía nadie.** La columna entró con el comentario *«el
> código queda atado a un cobro: cuánto, quién lo vendió y cuándo»* y de las tres sólo se escribían
> las dos últimas. La bandeja del tesorero ya la **leía**, así que enseñaba `null` en los
> diecisiete, y el checkout no tenía importe que cobrar.
>
> Es `profesores.tono` **otra vez** —van tres en un mes—, y **no lo destapó ningún barrido**:
> `interruptores-que-nadie-lee.py` mira `tinyint(1)` y esto es un `int`, así que no podía verlo ni
> corriéndolo. Lo destapó **que la función siguiente necesitó el dato y no estaba**. Merece quedar
> escrito: *construir encima encuentra huecos que un detector no busca, porque el detector sólo
> enumera las formas que alguien ya imaginó.*
>
> **No se tapó desde el código** —inventarse el importe es cobrar una cifra que nadie decidió, y
> aceptarlo del cliente es que la familia elija cuánto paga; las dos habrían sido decidir por
> Joseth el precio de un producto—, sino poniéndole las tres formas con su coste delante. Eligió
> **un precio por campaña**, y está entregado ([`41 §5.ter`](41-el-formulario-de-inscripcion.md)):
>
> - **Sin ruta nueva**: va en `config_formulario_inscripcion`, la misma fila y la misma ruta que
>   los campos. El router se queda en 612.
> - **Se ESTAMPA al acuñar, no se referencia.** Subir el precio en marzo no reescribe lo que se
>   vendió en enero — con una clave ajena, la bandeja del tesorero enseñaría meses después una
>   cifra distinta de la que la familia pagó. Visto en rojo implementando la versión por
>   referencia.
> - ⚠️ **Es un cambio de contrato y el front no lo sabe**: `GET`/`PUT …/campos` ganan `valor`.
>   `myvc-front-bf` construyó esa pantalla antes de que existiera y su sesión ya no está. Como
>   `valor` es opcional, **la pantalla vieja no revienta: apaga el cobro sin querer.**
>
> ### ⚠️ Y UNA CORRECCIÓN AL DOC 40 §4, QUE CAMBIÓ EL DISEÑO ANTES DE ESCRIBIRLO
>
> Aquel documento mandaba *«no te creas el webhook: vuelve a preguntarle a la pasarela **con la
> llave pública**»*. Comprobado contra la documentación de Wompi al ir a implementarlo, **la llave
> era otra** —`GET /v1/transactions/{id}` va con la **privada**, y con la pública Wompi contesta
> **404**— y lo que recomienda para validar un evento es justo lo que aquel documento descartaba:
> **la firma del evento**. Ese 404 es lo que lo convierte de errata en avería: al pie de la letra,
> un pago bueno se habría leído como *«no puedo confirmarlo»*, 503, y la pasarela reintentando
> para siempre — **ni un pago registrado en los diecisiete**.
>
> No es un nombre: es el precio. Reconsultar **exige guardar la llave privada del colegio**, la
> única credencial de todo esto que toca dinero. Así que hay dos cerraduras de tamaño distinto y no
> se les exige lo mismo — **la firma del evento es obligatoria** (su secreto, filtrado, sólo regala
> un formulario) y **la reconsulta es opcional** (la privada toca la cuenta del colegio). Cada pago
> guarda en `verificado_por` cuál de las dos lo admitió, porque una comprobación opcional sin rastro
> es una que nadie sabe si está encendida.
>
> **El fallo no fue creerse un dato: fue razonar sobre una arquitectura sin abrir la documentación
> del proveedor**, en el mismo documento cuyas tarifas se midieron una a una.
>
> ### Las cuatro trampas que costaron tiempo, para que no lo cuesten otra vez
>
> 1. **`UploadedFile::fake()` MIENTE sobre su tipo.** Lo declara por la extensión, no por el
>    contenido: un `recibo.jpg` con PHP dentro dice `image/jpeg` y **pasa una lista blanca que en
>    producción lo rechaza** (`text/x-php`). El test daba 200 sobre un controlador que estaba
>    BIEN, y la reacción natural habría sido arreglar lo que no estaba roto. Para probar subidas,
>    `new UploadedFile($ruta, $nombre, $mime, null, true)` con contenido real en disco.
> 2. **`years.tesorero_id` es un `profesores.id`, NO un `users.id`.** Medido: el id 5 es la
>    profesora MARYELINE y el usuario 5 es MARYOLY. Comparar contra `user_id` —el reflejo
>    natural— **no da un 403 ruidoso: le da permiso de aprobar pagos a otra persona**. Vale igual
>    para `rector_id` y `secretario_id`.
> 3. **`…/campos` se registra ANTES que `…/{lote}`**, o el comodín se la traga y contesta 404 sin
>    decir por qué. Lo fija un test, porque es un fallo que vive en una línea invisible.
> 4. **El nombre del método entra en un candado.** `postIndex` caía en la cohorte de `@postIndex`
>    —tres públicas por diseño— y `AutorizacionTest` lo delató. El arreglo no fue una excepción:
>    fue **llamarlo `postAcunar`, que es lo que hace**.
> 5. **Una ruta pública mueve cinco sitios… y SEIS cuando además escribe.**
>    `FamiliasQueNuncaEntranTest` cuenta las escrituras que viven en familias que el candado de
>    familia no mira nunca, y pasó de **22 a 24**. La colilla no lo movió porque sus tres hermanas
>    llevan guard; `pagos-inscripcion` **no tiene ninguna**. O sea que el «cinco» vale mientras la
>    familia esté guardada por otro lado: **una familia nueva entera de públicas mueve seis**.
> 6. **`UploadedFile::fake()` tiene gemelo en el otro sentido**: un test que llama a nuestro propio
>    método para comprobar una firma **pasa también cuando la fórmula está mal**. Las dos firmas de
>    la pasarela se comprueban contra la fórmula publicada por Wompi, no contra `Wompi::`.
>
> ### Lo que espera a Joseth (no se decide aquí)
>
> - **Los dos avisos que este flujo no manda.** Esta línea decía *«el canal es WhatsApp»* y
>   **Joseth lo descartó esa misma noche** ([42](42-avisos-por-whatsapp.md)): matriculado → app y
>   push, aspirante → correo. Al **tesorero** se le puede avisar por push, que ya funciona y es
>   gratis —es personal del colegio, tiene cuenta—; a la **familia** hay que avisarle por correo,
>   **y el correo de esta API está en rojo desde el 2 sep**: `lalvirtual.com`, el
>   `MAIL_FROM_ADDRESS` de quince colegios, no está registrado. Y **falla callado**: un «pago
>   aprobado» que no llega no se reintenta, porque el aspirante no sabe que existía.
>   Consecuencia concreta para este módulo: **rechazar una colilla exige un motivo para que la
>   familia sepa qué corregir, y hoy ese motivo no sale de la base.**
> - ¿Secretaría tiene **lector de código de barras**? Es lo único que devolvería el QR a esta fase.
> - La lista al día está en [`41 §8`](41-el-formulario-de-inscripcion.md).
>
> ### Y el orden de despliegue NO es libre
>
> `myvc_front` dejó sus pantallas terminadas y empujadas, y espera una sola cosa: **las rutas
> desplegadas en los diecisiete ANTES que el bundle de `app2`**. Al revés, toda secretaría ve
> «Formularios de inscripción» en el buscador de informes y **se come un 404**
> (`myvc_front/INVESTIGACION-MATRICULAS.md` §11). Los dos interruptores de prematrícula son la
> excepción: ya están en `main` y pueden ir cuando quieran.

> ## ❌ WHATSAPP: ANALIZADO Y DESCARTADO — EL CANAL ES EL CORREO (19 sep 2026)
>
> **Encargo de Joseth por la sesión `8myvc-92`**: los mensajes de WhatsApp, la
> implementación, los costos, cuánto cobrarle al colegio, y **el límite para no mandar un
> mensaje por cada nota que teclea un docente**. Sale del recorte de pagos en línea del
> mismo día: MYVC no cobra pensiones, y el flujo de la colilla → tesorero → aprobación
> termina en un aviso.
>
> Está entero en [`42-avisos-por-whatsapp.md`](42-avisos-por-whatsapp.md). **No hay una
> línea de código y no se propone escribirla sin D1 y D2.** Lo que hay que saber sin
> abrirlo:
>
> - **Agrupar no es para ahorrar dinero.** Un mensaje *utility* en Colombia cuesta **COP
>   2,55**, y el peor escenario medido —uno por cada nota, un colegio, un año— son 117.381
>   mensajes y **COP 398.142 al año**. Lo que lo descarta es que **una familia recibiría
>   100 mensajes en un día** (medido), bloquearía el número, y los bloqueos bajan la
>   calidad → baja el cupo → dejan de llegar el pago aprobado y el boletín.
> - **Lo que multiplica la factura viene de fuera**: que Meta recategorice la plantilla a
>   *marketing* (**×17,5**) o contratar un BSP (**×7,25**; el recargo de Twilio es 6,25
>   veces la tarifa colombiana de Meta). Elegir la frecuencia mueve un ×1,5.
> - **Recomendado**: un resumen por alumno y día (**COP 99 por alumno y año**), y encender
>   **prematrícula primero** (COP 12.261/año para los dieciséis).
> - **Ya existe el subsistema**: `notificaciones:enviar` agrupa cada 15 min desde el cron
>   único. WhatsApp no reutiliza `Publicador` —no hay temas, hay teléfonos— pero sí su
>   forma, y **no necesita ninguna dependencia nueva de composer**.
> - **Hallazgo que cambia otro documento**: de 1.085 acudientes vivos, **sólo 100 (9,2 %)
>   tienen correo**. El flujo de prematrícula termina en *«al aprobar sale un correo»*, y
>   ese correo **hoy no llega casi a nadie**. Avisado a quien lleva el 41.
> - **Y el alcance es 80 %, no 100 %**: 75 de los 377 matriculados de 2025 no tienen
>   ningún móvil colombiano válido en la ficha. Eso se arregla en la matrícula, no aquí.
>
> **DECIDIDO: no se usa WhatsApp en ningún caso.** Joseth estrechó el alcance hasta el único
> hueco real —el aspirante, que no tiene la app— y ahí **basta con exigirle un correo válido
> en el formulario**. El mapa queda sin huecos: **matriculado → app y push** (ya funciona,
> agrupado y gratis), **aspirante → correo**.
>
> **Lo que queda vivo de esto es UNA tarea, y es de Joseth**: el correo de esta API está
> medido en rojo desde el 2 sep (29 §2) — `cads-itagui` tiene el `.env` de desarrollo sin
> tocar y **no ha enviado un correo nunca**, y `lalvirtual.com`, el `MAIL_FROM_ADDRESS` de
> quince colegios, **no está registrado**. Se contesta corriendo **`correo:probar` en los
> diecisiete**, que existe, detecta los tres fallos y **nadie lo ha corrido nunca**. Y el
> formulario debería **validar el correo mandándolo**, no con una expresión regular: es el
> único momento en que el aspirante está delante y puede corregir una errata.
>
> **La diferencia que decide esto no es el precio, es el silencio**: WhatsApp avisa cuando no
> entrega y el correo falla callado. El «pago aprobado» que no llega no se reintenta, porque
> el aspirante no sabe que existía.

> ## ✅ P6 · EL CANDADO DE LA PLANTILLA — CERO RUTAS, Y LE QUITA ALGO AL DOCENTE (19 sep 2026)
>
> **Autorizado por Joseth el 19 sep, y con el alcance recortado por él**: de las dos mitades de P6
> entra **sólo el candado**. «Volver a aplicar» se queda fuera. **El router no se mueve: 602.**
>
> | | |
> |---|---|
> | `unidades/update`, `subunidades/update` y `unidades/update-orden` rechazan **nombre y porcentaje** de una fila con `por_defecto = 1` | 403, y la fila no se toca |
> | …salvo quien tiene `can_edit_plantilla_notas` | el criterio va **dentro** del método, no en la ruta |
> | Añadir subunidades **dentro** de una unidad del colegio sigue siendo del docente | **D14** |
> | `years.reparto_subunidades` pasa a viajar en el contexto del login, en las cuatro ramas | 6 instantáneas |
> | `Tests: 2122 passed (--testsuite=Contrato)` · larastan `[OK]` · `pint:test` PASS 421 | `.worktrees/e6` |
>
> ### El agujero era real y llevaba abierto desde siempre
>
> `PUT unidades/update/{id}` lleva sólo `auth.personal`, que cierra la puerta a alumnos y acudientes
> **y a nadie más**. Así que cualquier docente podía renombrar y recambiar el porcentaje de una fila
> sembrada desde `unidades_por_defecto`. Y el porcentaje de la unidad **es el factor de fuera de la
> definitiva** —`(u.porcentaje/100) * …`—, con el recálculo diez líneas más abajo en el mismo
> método: mover el 60 al 90 movía las notas del curso.
>
> ### Las tres decisiones de diseño, y las tres tienen su test
>
> **1. Se compara el VALOR, no la presencia del campo.** El front reenvía el formulario entero, así
> que un candado que salte porque *«vino `porcentaje`»* contesta 403 a quien no cambió nada. Se
> leería como una avería, y el arreglo evidente —quitar el candado— reabre el agujero.
>
> **2. `update-orden` se frena por el ORDEN, y eso es una interpretación.** P6 dice que las tres
> rutas *«rechazan el cambio de nombre y de porcentaje»*, y esa ruta **no escribe ninguno de los
> dos**: lo único que puede hacerle a una fila del colegio es moverla de sitio. Si se lee literal,
> nombrarla no significa nada. Queda fijado en el test para que sea discutible en vez de invisible.
>
> **3. 403 y no 422.** El cuerpo es correcto: lo que falta es permiso. Un 422 haría que el front
> dijera «revisa los campos», que es mentira — no hay nada que revisar.
>
> ### `reparto_subunidades` viaja porque si no el candado se nota en el sitio equivocado
>
> Lo pidió la app y **la asimetría la vio ella**: su hermana `modelo_evaluacion` salía en las cuatro
> ramas del contexto y ésta en ninguna —`grep -c` daba 5 y 0—, siendo las dos columnas de `years`
> que gobiernan lo que un docente ve. Sin ella, con `reparto_subunidades = 'promedio'` el cliente le
> sigue pintando un campo de porcentaje **que ya no decide nada**: sin error y sin log.
>
> ### LO QUE JOSETH DECIDIÓ Y NO ESTÁ HECHO — se escribe para que no se re-litigue
>
> - **«Volver a aplicar» queda fuera de este lote.** Y antes de escribirlo hay que medir una cosa:
>   **`PUT plantilla-notas/sembrar` ya acepta `reemplazar`**, ya salta los periodos cerrados
>   (`saltadas_por_periodo_cerrado`), ya resiembra las que tienen unidades pero **cero notas**, y ya
>   contesta con su recuento. Puede que la ruta nueva **no haga falta**.
> - **Y cuando se haga: las asignaturas que YA tienen notas se actualizan igual**, nombre y
>   porcentaje. Decisión de Joseth, 19 sep, **tomada con la consecuencia delante**: eso cambia
>   definitivas ya calculadas, así que un boletín impreso y la pantalla pueden dejar de coincidir.
>   Hoy `sembrar` las salta; ahí está toda la diferencia entre lo que hay y lo que pide P6.
> - **Se despliega SIN avisar a los colegios.** P6 dice *«hay que decirlo colegio a colegio antes,
>   no después»* y Joseth decidió lo contrario el 19 sep, a sabiendas. Queda escrito aquí para que
>   dentro de un mes se sepa que se sabía: el síntoma en el colegio será un docente que guarda y
>   recibe un error donde antes guardaba.
>
> **Falta**: fundir y desplegar. Rama `feat/candado-de-la-plantilla`, sobre `99060be`.
> El front tiene que pintar el campo como bloqueado; hasta que lo haga, el docente ve el campo
> editable y se lleva el 403 al guardar.

> ## ✅ `materia_id` Y `grado_id` EN LAS ASIGNATURAS DE UN DOCENTE — DOS COLUMNAS, CERO RUTAS (19 sep 2026)
>
> **Autorizado por Joseth con el precio delante.** Empezó como una **propuesta de ruta nueva** de
> `myvc_flutter` —`GET desempenos/mis-clases`— y **la retiró la propia sesión de la app** al ir a
> justificarla: lo que le faltaba era un campo, no un endpoint. **Esto no añade ninguna ruta**
> —el contador lo movió a **602** la prematrícula de más abajo, que entró en `main` mientras
> esto se escribía; se dice así y no «sigue en 600» porque un número absoluto en una rama
> describe un árbol que a las dos horas ya no existe—.
>
> | | |
> |---|---|
> | `a.materia_id` y `g.grado_id` en `Profesor::asignaturas` | ningún JOIN nuevo: los dos ya estaban |
> | …y en su **gemelo copiado a mano**, `PiarsAsignaturasController` rama `Usuario` | mismo commit |
> | 3 instantáneas regeneradas, `+2` claves cada una y nada más | |
> | `Tests: 2117 passed (--testsuite=Contrato)` · larastan `[OK] No errors` | `.worktrees/e6` |
>
> ### Por qué dos ids y no un nombre: el esquema no impide la ambigüedad
>
> El plan de área se dirige por ids —`Autoriza::puedeEscribirDesempenos` filtra literalmente por
> `a.materia_id` y `g.grado_id`— y ese SELECT sólo devolvía **nombres**. Así que `myvc_front` lo
> reconstruía desde fuera (`app2/…/docente-competencias/alcance.ts`): el grado por `grupo_id`
> —exacto— y **la materia emparejando `materia`+`alias` contra `GET materias`**, descartando la
> asignatura en silencio cuando el par no era único. Funciona hoy: en la base de desarrollo hay
> **35 materias vivas y cero pares repetidos**. Y **eso es una casualidad de los datos de UN
> colegio**: `materias` **no tiene índice único sobre `(materia, alias)`** —comprobado en el
> volcado— y hay dieciséis. Ése es el argumento que sostiene el cambio; el de *«la regla escrita
> dos veces se separa»* es más débil y no lo habría pagado.
>
> ### LO QUE SE PAGA, Y NO ES LO QUE SE PUSO ROJO
>
> `Profesor::asignaturas` lo comparten **once llamantes en nueve controladores**, y **los once
> devuelven esas filas al cliente**. De los once, **la suite de contrato mira dos**. O sea que las
> otras nueve cambiaron de forma **sin que nada se pusiera rojo** — y una de ellas es
> `asignaturas/listasignaturas`, que es **la única puerta de `myvc_flutter` a las asignaturas de un
> docente**. Añadir es inocuo (los clientes ignoran lo que no conocen, y ninguno pinta estas filas
> recorriendo sus claves: comprobado en los tres repositorios); **quitar o renombrar ahí no lo
> caza nadie**.
>
> ### El gemelo quedó demostrado por lo que NO se movió
>
> `PiarsAsignaturasController` lleva ese mismo SELECT **copiado a mano** para su rama `Usuario`,
> con otro `WHERE` —aquél pregunta por el docente, éste por el grupo—, así que no se pueden fundir.
> En la medición previa, tocando **sólo** el método, `muestreo-piars-asignaturas` **no se movió**:
> esa instantánea es de la rama `Usuario`, y la del `Profesor` no tiene ninguna. O sea que esa ruta
> habría contestado **dos formas según quién pregunte y la suite habría seguido verde**. Por eso
> los dos van en el mismo commit, y por eso cada lado lleva el aviso escrito apuntando al otro.
> *(Siguen diferenciándose en `caritas`, que el gemelo nunca trajo: es anterior a esto y se deja.)*
>
> ### Lo que esto borra en el front, y lo que NO se ha hecho
>
> Con las dos columnas, `alcance.ts` (12 KB) sobra entero y con él el emparejamiento por nombre.
> **Ese borrado no está hecho**: es del repositorio del front y no se toca desde aquí. La app de
> Flutter escribe su pantalla contra lo que existe hoy, **detrás de un interruptor apagado**, y lo
> encenderá cuando esto esté **desplegado** —no fundido—, verificado por el hash de la tanda.
>
> ### Y la lección que no era nuestra: un umbral no lleva un número dentro
>
> De aquí salió que `myvc_flutter` tenía en `Interruptores.dart` la condición de encendido escrita
> como **«desplegado en los quince»**. Son **dieciséis** desde que entró `lal` el 30 ago
> ([DESPLIEGUE.md](../DESPLIEGUE.md):966), así que **encendía con un colegio sin desplegar** — y el
> fallo le salía justo a ése, en la pantalla que usa a diario. Ya está cambiado a *«todos los que
> recorre el bucle de despliegue»*, **sin cifra**. La sesión de la app lo había propagado hasta el
> punto de **«corregir» a Joseth** un documento que estaba bien.
>
> > **CONTESTADO por Joseth el 19 sep 2026: `lal` está bien.** La duda era que el interruptor
> > `disciplinaMisFichas` se encendió el 26 ago contra la tanda `eb95cbc`, **cuatro días antes de
> > que `lal` existiera**, y que `lal` llegó **por traslado desde otro servidor**
> > ([TRASLADO-LAL.md](../TRASLADO-LAL.md)) — o sea que lo que tenga dependía de qué copia se
> > llevó y no de la fecha. **Queda cerrado porque lo dice quien lo sabe, no porque se dedujera**:
> > eso es exactamente lo que no se podía hacer desde aquí. Si alguien vuelve a abrirlo, lo que
> > lo contesta es el hash desplegado de `lal`, no este párrafo.
>
> ### Qué falta, y en qué orden
>
> **FUNDIDA el 19 sep 2026 en `6b20cda`. Falta desplegar.** La rama era
> `feat/materia-id-y-grado-id-en-asignaturas`, **rebasada sobre `99060be`** y con tres commits:
> `0d9af81` el cambio, `bfd27c7` el Pint de los dos ficheros que toca y `d130bca` el `chore` que
> los mete en la lista curada de `composer.json` (**419**, apuntado en `CLAUDE.md` en el mismo
> commit). Es *fast-forward*: `git branch -f main d130bca && git push origin main` — **y se
> reverifica antes**, que `main` ya se movió una vez debajo de esta rama.
>
> > **Una cifra de esta casilla era falsa y se corrige aquí, no se borra.** Decía que
> > `Profesor.php` *«lo tocan cuatro ramas vivas y el reformateo les deja conflicto»*: **no lo
> > toca ninguna**. Se contó con `git diff main..<rama>`, que da positivo también cuando la rama
> > va **por detrás** y fue `main` quien tocó el fichero. Medido contra el merge-base de cada
> > rama —que es la pregunta que se quería hacer—, las únicas que lo tocan son las de este
> > trabajo. **Es la trampa nº 3 de la cabecera de este documento, la de `--no-merged`, cometida
> > con otra orden**: contar referencias en vez de trabajo. La decisión de Joseth no cambia; el
> > coste con el que se la tomó era inventado, y por eso vale más la orden que el número.
>
> **Desplegar ya es posible** desde el 19 sep — ver la casilla del congelado—, pero **esto no
> corre prisa**: mientras no esté en los dieciséis, el front sigue con su rodeo y la app con su
> interruptor apagado, y las dos cosas funcionan. Lo que **no** se puede hacer hasta entonces es
> borrar `alcance.ts`.
>
> La medición previa, con el árbol sin el gemelo tocado, quedó aparte en
> `medicion/dos-columnas-en-listasignaturas` (`7a2df03`, **NO FUSIONAR**): es la prueba de las
> dos formas de `piars/asignaturas`, y sólo sirve emparejada con ésta.

> ## ✅ ABRIR Y CERRAR LA CAMPAÑA DE PREMATRÍCULA — DOS RUTAS, ROUTER EN 602 (19 sep 2026)
>
> **Lo reportó Joseth con el error delante**: la pantalla de ajustes del año de `app2` ya pintaba
> los dos interruptores y contestaban *«The route api/years/toggle-prematricula-nuevos could not be
> found»*. El front las había dejado escritas con el aviso puesto y el precio delante
> (`myvc_front/app2/src/app/datos/years.ts`), que es exactamente como se pide una ruta que no
> existe.
>
> | | |
> |---|---|
> | `PUT years/toggle-prematricula-nuevos` | `years.prematr_nuevos` — el enlace público del login |
> | `PUT years/toggle-prematricula-antiguos` | `years.prematr_antiguos` — la portada del acudiente |
> | `auth.personal` en las dos y **nada dentro** (decisión de Joseth de ese día) | |
> | **602** contado con `route:list --json` en el árbol principal, no sumado | |
>
> ### El hueco que tapan estaba medido, y es `profesores.tono` otra vez
>
> Hasta ese día **ninguna aplicación podía escribir esas dos columnas**: `years/guardar-cambios`
> nombra veintiún campos y ninguno es éste, ninguna ruta las nombraba, y lo único que las tocaba en
> todo el backend era **crear un año** (`YearsController::postStore`), que las copia del anterior.
> O sea que abrir la campaña de 2027 era heredar el valor bueno o un `UPDATE` a mano en la base,
> **colegio por colegio**. Las lee media aplicación —el enlace público del login, la portada del
> acudiente (`ChangeAskedController`, tres sitios), `Perfiles/PublicacionesController` y los modos
> de la pantalla de formularios de inscripción ([41](41-el-formulario-de-inscripcion.md))— y no las
> escribía nadie.
>
> ### Dos rutas, y aquí `toggle-cambiar-valor` SÍ podía — se dice
>
> A diferencia de `modelo_evaluacion`, el conmutador genérico escribe cualquier columna de `years`
> con este mismo `auth.personal`, así que la ruta nueva **no es una necesidad: es una decisión de
> forma**. Los otros diez interruptores del año son `{year_id, can}` contra ruta propia y aquél
> pide **el nombre de la columna dentro del cuerpo**; serían doce interruptores con once formas, y
> el raro es el que un día se llama mal. Por lo mismo se descartó una sola ruta con
> `flujo: 'nuevos'|'antiguos'`.
>
> ### El permiso va al revés que el del día anterior, y NO es un olvido
>
> `auth.personal` y **sin permiso dentro** —las 74 cuentas de personal—, preguntado con las tres
> opciones y sus poblaciones delante. `toggle-mostrar-nota-numerica` (18 sep) hizo lo contrario, y
> el caso de aquí ni siquiera es el más inocente: `prematr_nuevos` enciende **una puerta que se ve
> desde internet sin cuenta**. Por eso la diferencia va escrita en los dos métodos y en el router:
> *quien lea las dos familias seguidas tiene que poder distinguir una decisión de un olvido.*
>
> **Lo que queda abierto si algún día se estrecha**: la pantalla de `app2` va tras el rol `Admin`,
> y el backend no tiene ningún predicado que signifique eso —`esAdministrativo` es
> `is_superuser || Secretario`—. `Admin` e `is_superuser` **coinciden por población y no por
> definición**, así que estrechar el backend sin tocar el menú le pintaría el interruptor a alguien
> que va a rebotar en un 403.
>
> ### Cuatro pruebas, y las dos que importan vistas en ROJO
>
> Las dos rutas entran en la tabla de `test_los_conmutadores_guardan_lo_que_dicen`, más un test de
> que **mover uno no mueve el otro** —el fallo natural de un par de métodos gemelos escritos
> seguidos—, otro de que la respuesta es **texto y no un objeto** (la pantalla los llama con
> `putTexto`, o sea `responseType: 'text'`: un array dejaría el aviso enseñando JSON en crudo, y
> eso no sale en rojo en ningún sitio) y otro de que un año inexistente es **404**. Control visto:
> haciendo que el gemelo escriba la columna del otro caen **dos**; devolviendo un array cae el de
> la frase. `YearsTest`: **46 passed (510 assertions)** (`--filter=YearsTest`, `--testsuite=Contrato`).
>
> Instantáneas movidas, las **tres** de una ruta nueva: `rutas.json`, `guards-por-ruta.json` y
> `guard-por-familia.json` (`years` pasa de 21/19 a **23/21**).
> `familias-que-nunca-entran-en-el-candado.json` **no** se mueve: `years` ya tenía muchas hermanas
> con guard.

> ## ✅ EL HISTORIAL DE INFORMES Y LOS FAVORITOS DEL MENÚ — CINCO RUTAS, ROUTER EN 600 (18 sep 2026)
>
> **Autorizado por Joseth con el precio delante**, que es como entra una familia nueva aquí. Las
> dos tablas llevaban desde esa mañana en `main` (`2026_09_18_200000`) **sin un solo endpoint**.
>
> | | |
> |---|---|
> | `GET` / `POST` / `DELETE informes-recientes` | `Informes/InformesRecientesController` |
> | `GET` / `PUT accesos-favoritos` | `Perfiles/AccesosFavoritosController` |
> | `auth.personal` en las cinco — un alumno recibe **403**, medido | |
> | **600** contado con `route:list --json` en el árbol principal, no sumado | |
>
> ### El plan decía SEIS y son CINCO, y eso se cuenta, no se rellena
>
> Favoritos iba a llevar tres rutas y lleva dos. **`orden` es una propiedad de la LISTA y no de un
> renglón**: con rutas por elemento el cliente tiene que mantener un orden total coherente a lo
> largo de N peticiones, y un fallo a mitad deja huecos o empates en una columna que nadie vuelve a
> mirar. Con un `PUT` de la lista entera, marcar, desmarcar, reordenar y renombrar son **una sola
> escritura atómica**, y entonces un `DELETE` aparte no hace nada que no haga mandar la lista sin
> ese renglón. Mismo caso que la Fase 6 del [35](35-el-modelo-de-evaluacion-del-colegio.md), que
> preveió cuatro y entregó dos.
>
> **Lo que se paga, escrito antes de que muerda**: dos pestañas se pisan y gana la última. Se
> acepta porque es el menú de una persona —el conflicto sólo puede ser con uno mismo y se ve al
> instante—, y a cambio se quita la clase entera de fallos de orden, que no se ven.
>
> ### LA LECCIÓN CARA DEL DÍA: un resumen conserva el dato y pierde la ADVERTENCIA
>
> La especificación viajó `8myvc-33` → `myvc-front-dc` → aquí, y **se degradó justo en el punto
> sobre el que iba la advertencia**. `33` había avisado de que `periodo_id` y `periodo_a_calcular`
> **son dos campos distintos** —uno es el **id** de la fila de `periodos`, el otro el **número**
> (1..4)— y de que el `3` de una URL como `/boletines-periodo/96/3` parece el número. Llegó
> resumido como *«`periodo_a_calcular` guarda el número»*: cierto de ese campo, **y sin la
> advertencia**.
>
> Se resolvió midiendo, no deduciendo. `dc` lo comprobó contra el docker: en el año 2026 los
> periodos son `id 34, 40, 41, 42` → `numero 1, 2, 3, 4`. **Ni siquiera son seguidos**, así que
> confundirlos no es un error que se disimule — y si se hashea hoy el número y mañana el id, el
> mismo informe da dos huellas y dos filas, que es lo que el `UNIQUE` existe para impedir.
>
> *Un dato sobrevive a un resumen; una advertencia no, porque no parece información. Cuando algo
> pase por tres sesiones, lo que hay que repreguntar es la advertencia.*
>
> ### Conducido contra el docker, mirando el RESULTADO y no el 200
>
> Claves de `eleccion` **al revés → la misma huella**; campos vacíos descartados → la misma
> huella; el mismo informe dos veces → **una fila**; siete distintos → **seis**; el favorito que
> falta en el `PUT` se quita y **`created_at` se conserva** al reordenar; los seis 422 con su
> mensaje; 401 sin token y **403 con token de alumno**.
>
> > **Y un rojo que era mío y no del código**: el primer barrido dio **400** en las cinco contra el
> > token de alumno, y `ExigirPersonal` hace `abort(403)`. Era un fallo de **mi bucle de bash**, no
> > de la API — rehecho, **403 en las cinco**. Es literalmente lo que avisa `CLAUDE.md`: *el primer
> > sitio donde mirar cuando el número sale raro es el detector*.
>
> ### ⚠️ DOS PRUEBAS EN ROJO EN `main`, Y NO SON DE ESTE TRABAJO
>
> `AutorizacionTest > el alumno recibe su boletin y solo el suyo` y
> `AutorizacionTest > boletines3 sale con un area sin asignaturas` **fallan también sin este
> cambio**. Comprobado guardando el trabajo con `git stash` y corriendo la clase sobre el árbol
> limpio: **2 failed, 53 passed**. Con el cambio son las mismas dos.
>
> Se dejan dichas y **no se tocan**: los tests esperan a que Joseth pruebe a mano, que es regla
> suya. Pero el relevo anterior decía *«está todo en verde»*, y de estas dos no se había enterado
> nadie. *Una línea base se establece antes de atribuirse un rojo, en las dos direcciones.*
>
> ### Revisado por el front, y cuatro apuntes suyos que ya están en el código
>
> `myvc-front-dc` —que heredó `cascara/favoritos/` de `myvc-front-e7`— confirmó el contrato antes
> de commitear. El `PUT` de lista entera **es lo que su servicio ya hacía** (`guardar(lista)`
> reescribe entera, porque el orden es del array), así que enchufarlo no le cuesta refactor. Y
> aporta una mitigación gratis del «gana la última pestaña»: como el `PUT` **devuelve la lista
> guardada**, el front adopta la respuesta del servidor y la pestaña perdedora se corrige sola.
>
> Los otros tres están escritos en el docblock de `AccesosFavoritosController`, que es donde se
> buscan: que **`ruta` lleva parámetros** y no es una pantalla; que el tope del front es **12** y
> el de aquí **30** —y que el cliente sea más estricto es lo correcto—; y el que más vale:
> **favoritos no tiene `year_id` y una dirección con parámetros sí depende del año**. Un favorito
> a `/informes/boletines-periodo/96/3` guardado en 2026 y abierto en 2025 lleva a otro grupo. **Se
> deja así a sabiendas** —meter `year_id` haría desaparecer `/lista-alumnos` al cambiar de año— y
> se escribe porque el síntoma será *«me lleva al grupo equivocado»* y se irá a buscar a informes.
>
> ### Lo que hay que avisar el día del despliegue
>
> **Nada aquí añade migración**: las dos tablas ya entraron esa mañana con `2026_09_18_200000`.
> Lo único que mirar es que esa migración **llegue antes que el código**, como todas.
>
> Y un número medido por si alguna falla: el `UNIQUE (user_id, ruta)` de `accesos_favoritos` son
> **1.024 bytes** (4 + 255×4 en `utf8mb4`). Con `ROW_FORMAT=Dynamic` el tope es **3.072** y sobra;
> con el `COMPACT` antiguo serían **767** y no se podría crear. MariaDB 10.5 trae `dynamic` de
> serie desde la 10.2, así que haría falta un colegio con esa variable cambiada a mano. **Si pasa,
> la salida es un índice con prefijo y NO acortar la columna**, que perdería direcciones largas en
> silencio.
>
> ### Los tests — HECHOS, con luz verde expresa de Joseth (18 sep 2026)
>
> `InformesRecientesTest` (13 casos) y `AccesosFavoritosTest` (18) — **31 passed**, y `tests/` ya
> estaba en la lista de Pint, así que sube a **417** sin tocar `composer.json`.
>
> **Se le preguntó señalando que iba contra su propia regla** de esperar a la pasada a mano, y
> los pidió igual. Eso hace la luz verde **de este trozo y no general**.
>
> Lo que cubren es lo que **rompe en silencio**, porque esta familia no tiene ningún camino que
> reviente: la huella no se acepta del cuerpo; las claves desordenadas y las vacías dan la misma
> huella; `params` **no** entra en la huella —si entrara, renombrar un grupo orfanaría la fila—;
> el recorte al tope está en el servidor; `user_id`/`year_id` salen de la sesión; una lista de
> favoritos rechazada **no escribe ni los renglones buenos que van delante del malo**; y reordenar
> conserva `created_at`.
>
> > **Dos rojos al escribirlos, los dos míos y ninguno del código**, que valen como aviso: `+`
> > entre arrays **no sobrescribe** —la clave larga nunca se mandaba, de ahí un 200 donde se
> > esperaba 422—, y acuñar un token por petición hace un `login` por llamada, así que el bucle
> > del tope se comió un **429** del limitador. El token se cachea por test desde entonces.
> >
> > Y una cifra que bajó al arreglarlo: de **166** aserciones a **138** con dos tests más pasando.
> > No es que se compruebe menos — `tokenDe()` lleva un `assertStatus(200)` **dentro**, así que
> > cada login sumaba una. *Una cifra que se mueve en la dirección rara se explica antes de seguir,
> > también cuando el resultado es verde.*
>
> ### Lo que NO está hecho
>
> Las
> instantáneas sí se movieron —`rutas.json`, `guards-por-ruta.json` y `guard-por-familia.json`, con
> las dos familias como **«3 de 3»** y **«2 de 2»**— y
> `familias-que-nunca-entran-en-el-candado.json` **no se movió**, que era la predicción: con dos o
> más hermanas guardadas, una familia no entra en ese censo.

> ## ⚠️ LAS DOS JEFATURAS DE PRUEBA DEL DOCKER SIGUEN PUESTAS — A PROPÓSITO (18 sep 2026, 09:3x)
>
> **`8myvc-e2` dejó dicho que se borraran y NO se han borrado.** No es un olvido: es que al ir a
> hacerlo apareció el dato que cambiaba la pregunta, y la salida barata resultó no ser la buena.
>
> | | |
> |---|---|
> | `jefes_de_area` tiene **dos filas y son éstas** — year 9, áreas 2 y 17 | sembradas hoy 14:03 UTC |
> | Las sembró `8myvc-e2` para que `/areas/directores` de `app2` enseñara **sus tres estados** | |
> | Se van con `DELETE FROM jefes_de_area WHERE year_id=9;` | y vuelven con tres `PUT areas/jefes` |
>
> ### Por qué no se borran, que es lo único que no se puede rehacer midiendo
>
> **Borrar cuesta algo y no borrar no cuesta nada.** Son las **dos únicas filas de la tabla**, así
> que borrarlas deja `/areas/directores` sin ningún director — y esa pantalla entró en `myvc_front`
> hoy mismo (`22284af1`, 09:02 local). Contra eso, el único daño de dejarlas es que alguien las
> confunda con datos de verdad, **y eso lo arregla este párrafo, no un `DELETE`**.
>
> Se preguntó a las tres sesiones de front vivas y **ninguna las usa**: `myvc-front-dc` está en
> `/informes-nuevo`, `myvc-front-eb` conduciendo otras nueve pantallas y `myvc-front-57` en un
> documento de notas. Pero **ninguna de las tres es quien escribió la pantalla**, y a esa no se la
> encuentra: `dc` mandó a una `myvc-front-fb` que **no aparece en `ListAgents`**. *Un censo de
> sesiones no prueba una ausencia — los nombres cambian, y a una sesión viva se la puede estar
> buscando por un nombre que ya no tiene.*
>
> ### CUÁNDO SE BORRAN — la condición, porque una instrucción sin ella envejece a mentira
>
> **En cuanto Joseth haya mirado `/areas/directores` a mano**, que es la prueba que estas filas
> existen para permitir. Hecho eso no sirven para nada y se van. Si alguien llega antes y las ve,
> que sepa que **están puestas a propósito**.
>
> Y el revés, para quien venga dentro de dos días y ya no estén: **`/areas/directores` vacía es el
> estado normal de este docker y no una regresión.** La tabla nació vacía el 17 sep con `6630e4d`
> y ningún seed la llena.
>
> > **De camino, una comprobación que salió gratis y vale para cualquier medición de este docker:
> > la base guarda UTC y las sesiones hablan en hora local (UTC−5).** Las filas dicen `14:03:17` y
> > el commit de la pantalla dice `09:02`: no son cinco horas de diferencia, **son el mismo
> > minuto**. Quien compare una marca de la base con la hora de un commit sin convertir va a
> > deducir un orden de los hechos que no ocurrió.

> ## 🔧 LA NOTA NUMÉRICA DEL BOLETÍN Y LAS DOS TABLAS DE LA PANTALLA DE INFORMES (18 sep 2026)
>
> **Encargo de Joseth por la sesión de `myvc_front`**, que está rehaciendo `/informes` en
> `app2`. Tres piezas de backend; **las tres están en `main` salvo el consumo**, que es del
> front.
>
> ### 1. El interruptor de la nota numérica — EN `main`
>
> Hoy es un «boletín tipo 5» —*el tipo 1 pero sin números*— que se elige **al imprimir**, y
> pasa a ser **configuración del año**: es una decisión del SIEE, no algo que se decida cada
> vez que alguien saca un papel. `apagado` → sólo el texto del desempeño; `encendido` → el
> número **y** el desempeño.
>
> | | |
> |---|---|
> | `years.mostrar_nota_numerica_boletin`, `tinyint(1) NOT NULL DEFAULT 1` | `2026_09_18_100000` |
> | `PUT years/toggle-mostrar-nota-numerica` — **la ruta 595** | `estructura.php` |
> | `Autoriza::puedeCambiarLaNotaNumerica()` | superusuario, Secretario, Coord académico, Rector |
> | excluida de `years/toggle-cambiar-valor` (lista `$conDueno`, ahora son **tres**) | `YearsController` |
> | la hereda el año nuevo desde el anterior | `YearsController:226` |
>
> **Quién la cambia costó una pregunta y conviene saber por qué**: Joseth contestó
> **«interruptor corriente»** en una sesión y **«superadmin, secretario, coord académico»**
> —más el rector, después— en otra. Son **74 personas y 12**. No se eligió: se le puso
> delante que había contestado dos cosas, con las dos cifras medidas, y eligió los cuatro.
> *Dos respuestas de la misma persona en dos sesiones no se promedian ni se deduce cuál es
> la buena; se le enseñan las dos.*
>
> Y dos trampas que estaban escondidas en ese conjunto:
>
> - **`is_superuser` y NO el rol `Admin`.** Dijo «superadmin». Hoy los diez `Admin` de
>   `simonbolivar` son diez de los once `is_superuser`, así que **coinciden por población y
>   no por definición**; con el rol, el conjunto cambiaría solo el día que un colegio dé
>   `Admin` a alguien sin la bandera.
> - **La vecina que se le parece y no es igual.** `puedeMarcarBoletinIndependiente()` es
>   `Admin`, `Secretario`, `Rector`; ésta cambia `Admin` por `Coord académico`. Las dos
>   frases suenan igual en voz alta. Aquí decide **lo académico**, allí **la administración**.
>
> ### 2 y 3. Historial de informes y favoritos del menú — LAS TABLAS, EN `main`
>
> `2026_09_18_200000`: `informes_recientes` y `accesos_favoritos`. **Dos tablas y no una**
> aunque las dos sean «preferencias del usuario»: la primera es un log que se escribe solo y
> se poda, la segunda una lista corta que el usuario ordena a mano. Juntarlas obliga a una
> columna `tipo` y entonces **la poda tiene que acordarse de no borrar favoritos**.
>
> **La dedup es un `UNIQUE`, no un `GROUP BY`**: `(user_id, year_id, clave,
> huella_parametros)`, con la huella siendo un `sha256` de los parámetros **normalizados y
> ordenados** —sin ordenar, `{grupo:1,periodo:2}` y `{periodo:2,grupo:1}` dan dos huellas—.
> El motivo está a la vista en este mismo repositorio: media § del [10](10-definitivas.md)
> existe porque `notas_finales` es una caché sin clave única.
>
> `year_id` va en el historial —repetir de un clic un informe del año pasado saca el papel
> equivocado— y **no** en los favoritos, que son del menú.
>
> **Lo que NO está hecho**: los endpoints de esas dos tablas. El front avanza con
> `localStorage` y no los necesita todavía.
>
> ### Lo que hay que avisar el día del despliegue
>
> **La migración tiene que llegar antes o con el código.** `Year::datos` nombra las columnas
> una a una, así que en un colegio sin la migración esto no es «falta un campo»: es
> `Unknown column` y **500 en todo lo que pida el año**. El front ya lee con «sin campo,
> encendido», pero esa regla protege al cliente, no al servidor.
>
> > **Y aquí decía «es un rojo que este docker no puede enseñar, porque aquí la migración sí
> > corrió». Es FALSO, y se demostró solo a las pocas horas.** `8myvc-d7` estableció línea base
> > con `git stash` y encontró **dos rojos que no eran suyos** —`AutorizacionTest > el alumno
> > recibe su boletin y solo el suyo` y `> boletines3 sale con un area sin asignaturas`—, los dos
> > con `PDOException: Unknown column 'y.mostrar_nota_numerica_boletin'` desde `Year::datos`.
> > Causa: **`simonbolivar_testing` no tenía esta migración**, porque yo migré la base de
> > desarrollo y **la mía**, y en este docker hay **veinticuatro bases de test**.
> >
> > Medido el 18 sep 2026 después de que él arreglara la compartida: **22 de las 24 siguen sin
> > la columna**. La mayoría son de sesiones muertas y no molestan a nadie; la que molesta es la
> > que esté usando alguien vivo, y el modo de fallo es el peor —**dos rojos que parecen del
> > trabajo de otro**, en ficheros que ese otro no ha tocado.
> >
> > **Lo que NO es cierto es la parte tranquilizadora, y es la que hace daño**: la frase le dice
> > al lector que no busque el fallo en local, que es exactamente cuando lo tiene delante. Lo que
> > sí es cierto es más estrecho: **la base de DESARROLLO no lo enseña** —está migrada—, y
> > cualquier otra que no lo esté, sí.
> >
> > **Y este mismo documento ya llevaba la reproducción escrita**, en la casilla de la tanda de
> > migraciones: *«Reproducido contra una base sin migrar: `Unknown column 'y.regla_nivelacion'
> > in 'field list'`»*. O sea que la afirmación no sólo era falsa: **se contradecía con algo que
> > ya estaba escrito unos miles de líneas más abajo, sobre la misma tabla y el mismo mecanismo.**
> >
> > **La regla que sale de aquí, y es la accionable:** una migración que añade una columna que
> > una consulta nombra **explícitamente** no se termina migrando la base de desarrollo. Hay que
> > **migrar también la base de tests que se vaya a usar y avisar a las sesiones vivas**, porque
> > el resto de bases del docker son suyas y no se tocan sin decírselo. La orden que lo ve:
> >
> > ```bash
> > docker exec -e DB_TEST_DATABASE=<la_suya> 8myvc-app-1 \
> >     php artisan migrate:status --database=mysql_testing | grep -i pending
> > ```
> >
> > **Y el renglón que hace que esas 22 importen MÁS ADELANTE, que no es lo de hoy.**
> > Comprobado con `ListAgents`: las 22 son de sesiones muertas y no había a quién
> > avisar. Lo que queda es una mina con retardo — **una sesión nueva que reutilice el
> > nombre de un worktree viejo** (`.worktrees/b`, `.worktrees/f`…) se encuentra una
> > base **que ya existe y está varias migraciones atrás**, y no la reconstruye
> > precisamente porque «ya está». El síntoma será otra vez `Unknown column` en
> > ficheros que esa sesión no ha tocado.
> >
> > **El remedio es trivial y por eso conviene que esté escrito:
> > `tools/construir-bd-test.sh` hace `DROP DATABASE IF EXISTS` y la vuelve a crear**,
> > así que no hay nada que reparar a mano ni riesgo de dejarla a medias — **correrlo
> > siempre da una base limpia y migrada**. O sea que el peligro no está en el script,
> > está en **no correrlo** por creer que una base que existe está al día. Reutilizar
> > el nombre de un worktree es exactamente cuando esa creencia es falsa.
>
> Y el vecino con el que se va a confundir: **`years.solo_escalas_valorativas` también vacía
> un número**, pero sólo en la cabecera de comportamiento y con la polaridad invertida.
> **No se unifican** —los colegios que la tienen encendida imprimen así desde hace años— y
> lo acordado con `myvc_front` es que la pantalla de configuración los enseñe **juntos y con
> el alcance escrito al lado de cada uno**. Separados, el colegio enciende uno esperando lo
> del otro.

> ## ✅ LAS DEFINITIVAS SE CALCULAN SOLAS — LOS DOS AGUJEROS, CERRADOS (17 sep 2026)
>
> **Encargo de Joseth por la sesión de `myvc_front`**: *«solucionar el problema de las
> definitivas automáticas, que haga el recorrido para saber dónde tiene que hacer estos
> cálculos y podamos quitar los botones de la UI que manualmente calculan… pienso
> esconderlos para ponerlos en otro sitio para resolución de errores. Pero no quiero que se
> tenga que usar.»*
>
> **El recorrido está en la §«El recorrido para quitar los botones» del
> [10](10-definitivas.md), y su resultado fue que casi todo estaba hecho**: la fase 3
> cableó el recalculador en **las once** escrituras de notas. Lo que quedaba eran dos
> escrituras que cambian el resultado **sin tocar nada de lo que el sello vigila**, y por
> eso ni el recálculo ansioso ni el perezoso las veían. **Eran exactamente el trabajo que
> le quedaba al botón.**
>
> | | |
> |---|---|
> | desmarcar `manual` congelaba el valor tecleado | **cerrado** — recalcula y devuelve la definitiva |
> | cambiar `reparto_subunidades` no recalculaba el año | **cerrado** — 536 pares, 1.680 definitivas, 2,5 s |
> | el detector del tablero no veía las filas que faltan | **cambiado** a `estadoDelGrupo()` |
> | `BoletinPorCompetencias` imprimía a ciegas | **cerrado** — avisa y repara según el periodo |
> | `DefinitivasQueSeCalculanSolasTest` — 9 casos, los cuatro arreglos en negativo | en `main` |
>
> ### La decisión de Joseth, y dónde vive
>
> A *«qué hace un informe cuando descubre que está por detrás»* —abierta desde el 27 ago—
> contestó **reparar el periodo abierto, avisar en los cerrados**. Vive en
> `DefinitivasDeAsignatura::ponerAlDiaUnInforme()`, en el servicio y no en cada
> controlador, por lo mismo que el recalculador único: la necesitan los otros doce
> informes.
>
> **«Abierto» es `periodos.profes_pueden_editar_notas`, no `periodos.actual`** — si las
> notas todavía se pueden mover, la definitiva tiene que seguirlas, y así la regla se
> cuelga del interruptor que el colegio ya baja para cerrar un periodo en vez de inventar
> un segundo criterio. **Y se compone con la del 15 sep sin sustituirla**: *pedir un
> periodo pasado NO recalcula* es más fuerte y va antes, porque un periodo pasado puede
> tener el interruptor levantado.
>
> ### Lo que hay que saber antes de esconder los botones
>
> 1. **`periodos_desactualizados` NO servía de semáforo, y por construcción.** Era
>    `MAX(notas.updated_at) > MAX(notas_finales.updated_at)` **por grupo** y con `INNER
>    JOIN`, así que las filas que faltan eran invisibles. Medido: daba **0** en el año en
>    curso de la copia de desarrollo con **205 definitivas inexistentes**. La lista se
>    vaciaba sin que el trabajo estuviera hecho.
> 2. **El botón tampoco crea la fila que falta.** Su `INSERT` sale de un `inner join
>    notas`, o sea sólo repone al alumno que tiene notas. Para el caso que más duele —§6
>    del 10: sin fila, el puesto cuenta cero, el boletín cuenta cero y la planilla borra al
>    alumno— pulsarlo **no cambia nada**. El rincón de mantenimiento no puede anunciarse
>    como «aquí se arregla lo que no calculó».
> 3. **Sí respeta `manual` y `recuperada`**, comprobado: la preocupación que traía el
>    encargo no se materializa por ese lado.
>
> ### Lo que queda, y el único bloqueante
>
> - **Once informes siguen imprimiendo a ciegas** (`Boletines2`, `Boletines3`,
>   `Bolfinales`, `BolfinalesPreescolar`, `NotasActualesAlumnos`, `Puestos`, `Planillas`,
>   `Promovidos`, `Historiales`, `Editnota`, `CertificadosPersona` y
>   `CalcPerdidasDefinitivas`). Cablearlos es llamar a `ponerAlDiaUnInforme()` y decidir
>   dónde va el aviso en cada respuesta — **no hace falta ninguna decisión más**.
> - **El punto 6 de la fase 2** —rellenar las filas que faltan— sigue bloqueado por los
>   dieciséis números de la fase 0. **Es lo único que puede vaciar la lista honesta**, así
>   que mientras no exista, el tablero va a seguir marcando grupos y eso es correcto.
>
> ### Y un hallazgo que no es de este trabajo
>
> **`tests/Contrato/Snapshots/boletines-competencias-detailed-notas.json` está huérfana**:
> ningún test la referencia, y su `poblacion` todavía lleva `competencias`,
> `desempenos_del_grupo` y `desempenos_sueltos`, que el modelo plano quitó. O sea que
> describe una respuesta que ya no existe y **nada se va a poner rojo por ello**. Es de la
> tanda del modelo plano, no de ésta; se avisa y no se toca.

> ## 🤝 RELEVO DE `8myvc-e2` → LO QUE DEJA HECHO Y LO QUE QUEDA (18 sep 2026, 09:2x)
>
> Cierro sesión y esto es lo que hay que saber para seguir. **El árbol principal está limpio** y
> `main` en `6bf8e33`; el router, **595** contado con `route:list --json`.
>
> ### Lo que entró, en orden
>
> | | |
> |---|---|
> | El **modelo plano por competencias** entero — 21 rutas en 7, el boletín deriva el nivel, preescolar por grupo, el área pondera | contrato en **[39](39-el-modelo-plano-por-competencias.md)** |
> | `d54cef6` · el área ponderada **deja de redondear** (decisión de Joseth) | |
> | `6630e4d` · **`jefes_de_area` por año**, `areas.jefe_id` retirada y el `CASCADE` desarmado | |
> | `7f17342` · el año nuevo **hereda** las jefaturas, sin filtrar por contrato | |
> | `bb85752` · las **tres rutas** del director de área · `212fb39` · la foto como `foto_nombre` | |
> | `myvc_front` `22284af1` + `8f3ca707` · **la pantalla `/areas/directores`** y su botón | |
>
> ### Lo que NO está hecho, y es lo que pediría yo primero
>
> 1. **Nada de esto está desplegado.** Ni el backend ni `app2`. Y hay un orden que muerde: la
>    **migración del jefe de área va antes que la pantalla** — si la pantalla llega primero a un
>    colegio, escribe contra una tabla que no está.
> 2. **La forma nueva del bloque de desempeños no la prueba nadie.** La ruta sí sigue cubierta
>    (`BoletinDeUnPeriodoPasadoTest`, 5 casos). Los tests esperan a que Joseth pruebe a mano, que es
>    su regla — no es un olvido. La §6 del 39 dice **cuáles tres de los quince retirados no se deben
>    reponer nunca**, porque hoy afirman lo contrario de lo decidido.
> 3. **Adoptar del MEN son 43 peticiones desde el navegador**, con la deduplicación y el orden en el
>    cliente. Joseth dijo **ahora no**; la forma elegida para el día que estorbe es
>    `POST desempenos/lote`, no abrir `copiar` (eso sería re-litigar D12).
>
> ### Tres cosas que te van a morder si no las sabes
>
> - **Dos jefaturas de prueba quedaron en el docker** (year 9, áreas 2 y 17) para que la pantalla
>   enseñara sus tres estados. Se van con `DELETE FROM jefes_de_area WHERE year_id=9;`.
> - **`app.routes.ts` de `myvc_front` lleva ~104 inserciones de otra sesión sin commitear.** Mis dos
>   líneas entraron reconstruyendo el índice desde `HEAD`; **no lo commitees entero**.
> - **`git rm` stagea solo.** Un `git commit -m` sin pathspec se lleva lo que otro dejó preparado en
>   el índice sin que nadie escribiera `git add`. Me pasó ayer y me llevé por delante trabajo a
>   medias de un agente. Se deshace con `reset --soft HEAD~1` mientras nadie haya commiteado encima.
>
> ### Y dos lecciones de medición que costaron caro, por si sirven
>
> - **Un `grep` por nombre no demuestra que nadie usa un fichero** cuando el lector lo encuentra por
>   `glob`. `HuecosDelSeedTest` lee **todas** las instantáneas por barrido del directorio, así que
>   **en este repositorio ninguna está huérfana**: el censo se acopla a la LISTA de ficheros, y lo
>   mueven el alta y la baja, no regenerar.
> - **Compilar no es ver.** La pantalla de directores compilaba y salía titulada «Áreas»: la cáscara
>   pinta el nombre de la ruta y **esconde el `h1` de la pantalla**. Sólo apareció al conducirla en
>   Chrome y preguntarle al DOM.

> ## ✅ EL MODELO PLANO POR COMPETENCIAS — LAS CUATRO TAREAS DENTRO, ROUTER EN 591 (17 sep 2026)
>
> **«Competencia» y «desempeño» son la misma cosa**, así que sobraba un piso entero. El contrato
> está en **[39](39-el-modelo-plano-por-competencias.md)**; las decisiones y su porqué, en
> `myvc_front/CORRECCIONES-MODELO-DE-EVALUACION.md` (P1.bis, P1.ter, P1.quater, D31, D32).
>
> | | |
> |---|---|
> | `09788cd` · el boletín **deriva** el nivel de la definitiva + prefijo por banda | en `main` |
> | `8329718` · una tabla, **21 rutas se quedan en 7**, permiso con alcance del docente | en `main` |
> | `53b50fa` · preescolar: el grupo entero de frases, **322 peticiones a 14** | en `main` |
> | `b7c69d2` · el área **pondera** cuando el colegio reparte los pesos | en `main` |
> | `6f34459` · las **tres** instantáneas · `d7155d0` · el rojo heredado de `d540906` | en `main` |
> | `5007346` · fuera los quince casos que afirmaban el modelo de dos pisos | en `main` |
> | **router en 591**, contado con `route:list --json` a las 20:48 · `CLAUDE.md` movido | — |
>
> ### Lo que ESPERA UNA LÍNEA TUYA, y es lo único que falta
>
> **`Area.php`: quitar el `round()` sólo en la rama ponderada.** Está **escrito y verde** (`php -l`
> limpio, phpstan nivel 7 sin errores) y **sin commitear**, porque la decisión me llegó relatada por
> la sesión del front y no directamente — *el OK a una sesión no vale para las otras*. Es una línea
> de confirmación.
>
> Al hacerlo salieron dos cosas que nadie había visto y **la primera es mejor argumento que el que
> motivó la decisión**: desde `bd02f66` (13 sep) el resto del sistema **ya no redondea**
> —`valoracion()` perdió su `round()` en los trece sitios—, así que **el área era la que se había
> quedado sola con la regla vieja**. Y la segunda es contraintuitiva: como `valoracion()` ya no
> redondea, un ponderado de 29,6 **se queda en la banda de 29** en vez de subir a la de 30, o sea
> que quitar el redondeo puede **bajar** de banda, no sólo subir.
>
> ### Tres cosas que salieron de construirlo y no estaban en ningún plan
>
> 1. **`DELETE escalas/{id}` sin `acepto_desviacion` es un 500 en producción desde el 14 sep**, y
>    no lo estrena esto: contaba `frases_asignatura.escala_id`, una columna que **nunca corrió en
>    ningún colegio**. Arreglado en `8329718`.
> 2. **`YearsController::copiarElPlanDeArea` leía `competencias`**, así que borrar la tabla dejaba
>    `POST years` —la creación del año siguiente— en 500. Arreglado en el mismo commit.
> 3. **La premisa del borrado tiene una prueba mejor que la que estaba escrita.** La de siempre era
>    «ningún cliente desplegado llama a esas rutas», que depende de que nadie despliegue. La nueva
>    es el **volcado**: `frases_asignatura` tiene doce columnas y ninguna de las cuatro de la
>    rejilla, o sea que esas migraciones **nunca corrieron en ningún colegio**. No depende de nada.
>
> ### Lo que queda sin cobertura, dicho porque un hueco callado parece un descuido
>
> **La forma nueva del bloque de desempeños no la prueba nadie** — que salga el catálogo entero, que
> el nivel se derive, que el prefijo se anteponga, que `asignaturas_sin_catalogo` cuente. La **ruta**
> sí sigue cubierta (`BoletinDeUnPeriodoPasadoTest`, 5 casos verdes). Los tests nuevos esperan a que
> Joseth pruebe a mano, que es su regla. Y **tres de los quince casos retirados no se deben reponer
> nunca tal como estaban**: la §6 del 39 dice cuáles y por qué.
>
> ### Y lo que hay que avisar el día del despliegue
>
> - El área **no cambia nada** hasta que un coordinador rellene `porcentaje_area`: hoy 0 de 1.219.
>   Cuando la rellene, **cambia el papel ya impreso** de cinco informes, y hay que rellenarla
>   **entera** por área o no pasa nada.
> - El texto del desempeño **se lee vivo al imprimir** (decisión aceptada, §3 del 39): corregir una
>   errata en octubre cambia el boletín del periodo 1 que ya fue a casa.
> - `GET desempenos` lo lee ahora **cualquier docente**; antes exigía `can_edit_plantilla_notas`.

> ## ✅ EL BOLETÍN DE UN PERIODO CERRADO — `periodo_id` EN LAS OCHO (15 sep 2026)
>
> **Regla de Joseth**: *«no importa si el periodo cerró, siempre se puede imprimir
> boletines del mismo»*. Contestó con una regla a una pregunta de tres formas, así
> que la forma la eligió después: **un campo opcional en las ocho `PUT
> …/detailed-notas[-group]`** — `boletines`, `boletines2`, `boletines3` y
> `boletines-competencias`.
>
> **Lo que faltaba no era lo que parecía.** El personal ya podía (el selector de la
> barra mueve su contexto a cualquier periodo vivo) y el alumno ya veía sus **notas**
> de todos los periodos (`notas/alumno` devuelve el año entero). Lo que no tenía
> camino era **su boletín**: esas ocho rutas imprimen `$user->periodo_id` y
> `periodos/useractive` es `auth.personal`. La familia estaba clavada al periodo
> activo del colegio **sin nada con que moverlo**.
>
> | | |
> |---|---|
> | `App\Support\PeriodoDelBoletin` — resuelve y mueve el contexto de la petición | en `main` |
> | las ocho rutas aceptan `periodo_id`; sin él, lo de siempre | en `main` |
> | `BoletinDeUnPeriodoPasadoTest` — 5 casos | en `main` |
> | **ninguna ruta nueva** — el contador de `CLAUDE.md` y los tres snapshots, quietos | — |
>
> ### Dos cosas que salieron de construirlo y no estaban en la decisión
>
> 1. **Pedir un periodo pasado NO recalcula.** `putDetailedNotas` **escribe** —pone al
>    día las definitivas del alumno que abre—, y eso se razonó inocuo *para el periodo
>    activo*, que era el único alcanzable. Con un periodo cerrado quien abre puede ser
>    el acudiente, y lo que se reescribiría son definitivas **ya impresas**.
> 2. **El periodo tiene que ser del año del usuario**, o 422: los cuatro controladores
>    leen `$user->year_id` en una docena de sitios, así que uno de otro año daría un
>    boletín **mezclando dos años en 200**.
>
> ### El año pasado: CERRADO el mismo día, y conviene saber en qué dirección
>
> A *«¿y un periodo de un año pasado?»* contestó **«se puede imprimir cualquier cosa
> de periodos pasados si es super admin o coordinador»** — criterio que ya existe
> (`Autoriza::puedePublicarHorario`). **No se implementó, y por eso**: `years/useractive`
> y `periodos/useractive` son las dos `auth.personal`, así que **cualquiera de los 74 del
> personal** se mueve hoy a 2024 y lo imprime. Ponerlo sólo en el campo nuevo dejaría dos
> puertas al mismo dato con reglas distintas, y la que se usa de verdad sería la que no
> comprueba nada.
>
> Puesta así, Joseth eligió **dejarlo como está**: la frase es la política del colegio y
> el código no la comprueba. **No es un agujero abierto: es una puerta que lleva años
> abierta y que nadie pidió cerrar**, y cerrarla se lo habría quitado a docentes que hoy
> imprimen años pasados sin molestar a nadie. El día que se pida, lo que se toca es
> `years/useractive`.

> ## 📋 LOS DOS CENSOS, LISTOS PARA PEGAR — Y LA REGLA DEL PERIODO CERRADO (15 sep 2026)
>
> **Joseth volvió a preguntar qué falta de calificar por competencias en backend**, y la
> respuesta de la casilla de abajo sigue en pie: **nada bloqueante**. Lo que cambió es lo
> que colgaba de ella.
>
> **1. Los dos censos que bloqueaban D14 y D21 ya son una orden, no una pregunta.**
> Estaban escritos como *«hay que contar esto en los dieciséis»* y ahora son un bucle
> pegable, probado **tal cual se lee** dentro del contenedor —comillas incluidas— contra la
> copia de desarrollo: [35 §7](35-el-modelo-de-evaluacion-del-colegio.md). Los dos van en
> el mismo bucle porque entrar dos veces a diecisiete colegios para contar cuatro cosas es
> el trabajo que hace que no se cuente ninguna.
>
> Y de construirlo salieron tres cosas que la lista no decía:
>
> - **El número del candado se mueve solo, y sólo hacia arriba**: `plantilla-notas/sembrar`
>   —desplegada el 4 sep— **inserta con `por_defecto = 1`**. El censo es una foto: se corre
>   **el mismo día** que entre el candado, no la semana anterior.
> - **Ningún camino de la API pasa una fila de 0 a 1** —no hay un `UPDATE` sobre esa columna
>   en todo `app/`—, así que una fila marcada **nació marcada**. Ése es el mecanismo, y es
>   lo que hace que el número no pueda bajar solo.
> - **La copia de desarrollo ya no sirve de referencia para ese renglón**: da `1/17109`
>   donde el doc 28 midió **cero de 51.519**, porque el 13 sep alguien marcó dos filas a
>   mano. No es el producto: es una sesión gastando datos de desarrollo.
>
> **2. La regla del periodo cerrado, y el hallazgo que mueve el problema de sitio.**
> Joseth contestó a las tres opciones **con una regla**: *«no importa si el periodo cerró,
> siempre se puede imprimir boletines del mismo»*. Medido quién puede hoy:
>
> | quién | hoy |
> |---|---|
> | personal | **puede** — `periodos/useractive` mueve el contexto a cualquier periodo vivo de cualquier año (el selector de la barra) |
> | alumno/acudiente, sus **notas** | **puede** — `notas/alumno` devuelve **todos los periodos del año** y la app pinta un desplegable |
> | alumno/acudiente, su **boletín** | **no puede, y no hay camino**: las ocho `PUT …/detailed-notas[-group]` imprimen `$this->user->periodo_id` y `periodos/useractive` es `auth.personal` |
>
> O sea que lo que falta **no es «que el boletín acepte un periodo»** —para el personal eso
> ya funciona— sino **el camino que no existe para la familia**, y vale para los cuatro
> boletines: el de competencias no estrena el problema, lo hereda. **La forma sigue abierta
> y es lo único que bloquea escribirlo**; las tres candidatas y lo que cuestan están en la
> §6 del 35, con la trampa del nombre `periodo_a_calcular` —que ya existe y significa otra
> cosa— delante.

> ## 🔧 `cors-de-los-colegios.sh` DECÍA «BLOQUEADO» DE LOS COLEGIOS SANOS — ARREGLADO (15 sep 2026)
>
> **Hallazgo de `myvc-horarios-69`, reproducido aquí antes de tocar nada.** Joseth no
> podía entrar desde el escritorio a `demo`; se corrió la herramienta escrita para ese
> día y dijo `404 BLOQUEADO`. **Acertó por casualidad** —demo sí estaba roto, por un
> `.env` que Laravel no leía (config cacheada), no por CORS— y de paso habría dicho lo
> mismo de **los diez colegios sanos**.
>
> Dos defectos, y el segundo es el caro: la ruta sondeada no llevaba el prefijo
> `/8myvc/public/`, y **un 404 se clasificaba como BLOQUEADO**. Con los dos juntos, la
> herramienta contestaba lo mismo pasara lo que pasara — que no es una herramienta con
> un fallo, es un cartel.
>
> **El prefijo NO se cableó, y por una medida:**
>
> ```
> producción (4 de los 11 del listado, uno con dominio propio)
>     /api/login/credentials                404
>     /8myvc/public/api/login/credentials   204
> docker local (http://localhost)
>     /api/login/credentials                204
>     /8myvc/public/api/login/credentials   404
> ```
>
> **Están al revés**, así que la salida barata —cambiar la constante por la buena—
> habría roto el otro uso. La ruta se **descubre** por servidor y se imprime cuál salió;
> si ninguna responde es **NO MEDIDO**, que ya manda sobre lo medido en el código de
> salida.
>
> Comprobado en los tres casos, y el último es el que acredita el arreglo:
>
> | | antes | ahora |
> |---|---|---|
> | docker | — | descubre `/api/…`, 3 pasan, salida 0 |
> | producción, colegio sano | **3 BLOQUEADO, salida 1** | descubre el prefijo, 3 pasan, salida 0 |
> | host sin API | BLOQUEADO | NO MEDIDO, salida 2 |

> ## ✅ CALIFICAR POR COMPETENCIAS: RECORRIDO ENTERO Y CERRADO — EN `main` (15 sep 2026)
>
> **Pregunta de Joseth: *«¿qué falta de calificar por competencias en backend?»*.**
> Respuesta, después de cruzar el plan con lo enrutado y con el esquema real: **nada
> bloqueante**. Las siete fases del [35](35-el-modelo-de-evaluacion-del-colegio.md)
> están en `main`, la herencia del plan de área al año nuevo también, y la **Entrega 7
> del [28](28-competencias-e-indicadores.md) —preescolar— estaba cubierta pero sin
> recorrer**.
>
> Ahora está recorrida: `PreescolarCalificaPorCompetenciasTest` construye un grupo de
> Transición con `caritas = 1` y hace el camino entero —sembrar el plan de área, leer
> la rejilla, marcar, verlo impreso en el boletín por competencias—. Verde, y **sin
> backend nuevo**.
>
> ```
> Tests: 1 skipped, 2327 passed   (php artisan test, .worktrees/pre, 7703a6b)
> composer run stan -> [OK] No errors
> route:list --json en el árbol principal -> 603, quieto
> ```
>
> ### Los dos hallazgos que la composición no podía dar
>
> 1. **El seed no tiene preescolar**: sus únicos niveles son `Educación Básica
>    Académica3` con Tercero y Cuarto, y **cero grupos con `caritas`**. Así que ningún
>    test que espere el caso del seed lo cubre, y `BolfinalesPreescolarController` /
>    `FrasesPreescolarTest` **tampoco**: ejercitan rutas, no un grupo de preescolar. El
>    caso se construye, y va con un centinela que se pone rojo el día que el seed lo
>    traiga.
> 2. **La rejilla de preescolar viene sin premarcar, y es lo correcto**: premarca desde
>    la definitiva numérica y preescolar no pone notas. Es el §5.7.c cumpliéndose —*«la
>    valoración se MARCA, no se teclea»*—, y está fijado con un número.
>
> ### Las dos decisiones de Joseth de ese día
>
> - **La escala del alumno ERE** — *«no necesita escalas de valoración propias, debe
>   usar las mismas que usan todos, las definidas para el año»*. Cierra la última
>   pregunta abierta de D17, **y en una dirección que no estaba en la propuesta**: no
>   es una carencia que tapar, es la regla. La pregunta estaba planteada como «dónde
>   guardamos su escala», que da por hecho que la necesita.
> - **`modelo_evaluacion` no lo lee ningún controlador, y es a propósito** (D3). Queda
>   anotado en `Year.php` para que nadie lo tome por un `profesores.tono`, con el
>   motivo por el que se descartó el 403: con ese `if`, volver atrás dejaría de ser
>   cambiar el enum.
>
> ### Lo que queda, y no bloquea a nadie
>
> | | |
> |---|---|
> | el candado del docente sobre unidades/subunidades (D14) | espera un censo de `por_defecto = 1` en los dieciséis |
> | `default_unidades` / `default_subunidades` | no las lee nadie; contar filas antes de borrar |
> | la maqueta del boletín nuevo | es del front |

> ## ✅ LOS TÍTULOS DEL CERTIFICADO — EN `main` (15 sep 2026)
>
> **Rama `feat/titulos-del-certificado`, worktree `.worktrees/tc`, base
> `simonbolivar_testing_tc`.** Encargo de Joseth de ese día; documento completo en
> [`38-los-titulos-del-certificado.md`](38-los-titulos-del-certificado.md).
>
> El título de «Certificado final» y «Certificado periodos» estaba **escrito dentro de
> la plantilla** de los dos fronts y el colegio no podía cambiarlo. Pasa a ser dos
> columnas de `years`, con su pantalla y su herencia al año siguiente.
>
> | | |
> |---|---|
> | `2026_09_15_100000_titulos_del_certificado` — dos columnas | en la rama |
> | `Year::datos()` (las **dos** ramas), `TITULOS_POR_DEFECTO`, `LARGO_DEL_TITULO` | en la rama |
> | `putEncabezado` acepta los tres textos, cada uno opcional | en la rama |
> | `putToggleCambiarValor` los excluye — invariante de valor | en la rama |
> | `postStore` los hereda del año anterior | en la rama |
> | `TitulosDelCertificadoTest` — 20 casos | en la rama |
>
> **Ninguna ruta nueva**: no mueve el contador de `CLAUDE.md` ni los tres snapshots de
> rutas.
>
> ### Las TRES decisiones de Joseth de ese día, que es lo que no se puede reconstruir
>
> Se le llevaron medidas y las tomó con el precio delante. **La primera es la que
> cambia papel firmado en los dieciséis colegios**, así que va entera:
>
> 1. **Defecto `CONSTANCIA DE DESEMPEÑO ACADÉMICO` para los dieciséis**, sabiendo que
>    el legacy gana la palabra «ACADÉMICO» en catorce. Hoy se imprimen **tres** textos
>    distintos —el legacy dice «CONSTANCIA DE DESEMPEÑO», `app2` le añadió «ACADÉMICO»
>    al migrar y nadie lo notó, y `coal`/`coljordan` dicen «CERTIFICADO DE DESEMPEÑO»
>    por `document.domain`—, así que **no había un «lo de hoy» que conservar**.
> 2. **`coal` y `coljordan` amanecen con el defecto** y lo corrigen desde la pantalla,
>    en vez de dejar vivo el condicional de dominio como respaldo. **A esos dos hay que
>    avisarles antes de desplegar** — es papel que firman.
> 3. **El parcial lleva `PARCIAL` detrás** (mismo día, sobre la primera versión de la
>    entrega, que les había puesto el mismo defecto a los dos). Se emite con el año sin
>    cerrar; decían lo mismo **porque comparten plantilla**, no porque nadie lo
>    decidiera.
>
> ### ✅ Verde, medido sobre `ce198db` con el contenedor sin ninguna otra suite viva
>
> ```
> Tests: 1 skipped, 2319 passed   (php artisan test, .worktrees/tc, ce198db, 21:13 UTC 15 sep)
> Tests: 1 skipped, 2163 passed   (--testsuite=Contrato)
> Tests: 147 passed               (--testsuite=Unit)
> Tests: 9 passed                 (--testsuite=Feature)
> ```
>
> **La resta cuadra** —2163 + 147 + 9 = 2319—, que es la única forma de saber que las
> cuatro hablan de lo mismo. `composer run stan` → `[OK] No errors`; `composer run
> pint:test` → `PASS, 408 files`; `tools/secciones-citadas.py` → 0 huérfanas de 2.321
> citas. Las **25 instantáneas** regeneradas: `25 files changed, 50 insertions(+)`,
> **cero borrados**.
>
> > **Y antes de esa cifra hubo dos corridas sucias que NO eran del código, apuntadas
> > porque el patrón vuelve.** Una dio `45 failed` y otra `70 failed`, repartidos por
> > `LoginTest`, `CiudadesTest`, `SesionTest` — clases que esta rama no toca. Eran
> > **184 deadlocks en `personal_access_tokens`**, todos contra
> > `simonbolivar_testing_tc`: había **dos suites mías a la vez contra la misma base**,
> > porque encadené `--testsuite=Contrato` a una suite entera que aún no había muerto.
> > Es el deadlock que ya describe la cabecera de `tools/construir-bd-test.sh`.
> >
> > Lo que lo distingue de una regresión en un vistazo: **los rojos no tienen nada que
> > ver entre sí ni con lo que se tocó**. Y la orden que lo dice sin interpretar nada
> > —el censo hay que hacerlo **por árbol**, porque otra sesión corriendo en su propio
> > worktree y su propia base no estorba:
> >
> > ```bash
> > docker exec 8myvc-app-1 ps -eo pid,lstart,args | grep "[a]rtisan test"
> > docker exec 8myvc-app-1 readlink /proc/<pid>/cwd     # de qué worktree es
> > ```
>
> ### ✅ Fundida, y remedida después de fundir
>
> `main` había avanzado **tres commits** mientras esto se escribía —el aviso del
> reparto, que toca **el mismo `YearsController`**—, así que `main` se fundió **primero
> dentro de la rama** y se midió ahí: fundir y medir después deja `main` roto mientras
> se mide. Auto-fundió sin conflicto, y eso **no basta**: git funde por líneas y no por
> sentido, así que se comprobó a mano que los bloques siguen donde deben —`main` tocó
> `putModeloEvaluacion`, esta rama `postStore` y `putToggleCambiarValor`, y no se
> pisan—.
>
> ```
> Tests: 1 skipped, 2325 passed   (php artisan test, .worktrees/tc, 7285380, 22:42 UTC)
> composer run stan      -> [OK] No errors
> composer run pint:test -> PASS, 409 files
> ```
>
> **2.325 y no 2.319**: los seis de diferencia son de `AceptoRecalcularElRepartoTest`,
> que vino con `main`. Se dice porque la cifra sube sin que esta entrega añada un solo
> test, y sin esa línea el próximo que reste se pregunta de dónde salieron.
>
> **`route:list --json` en el ÁRBOL PRINCIPAL, después de fundir: 603**, el mismo que
> dice `CLAUDE.md`. Esta entrega afirmaba «cero rutas nuevas», y **ésa es la
> comprobación de que era verdad**, no una formalidad: se cuenta igual cuando se espera
> que no se mueva, porque un contador sólo verificado cuando cambia no verifica nada.
>
> ### Lo que falta
>
> 1. **El front**, en sesión aparte y con el encargo ya escrito (§10 del doc 38). Ojo a
>    la trampa: `isCoalSchool()`/`cabeceraPropia()` gobiernan **cinco sitios** y sólo
>    uno es el título — borrarlas cambia cuatro cosas más del papel de esos dos
>    colegios.
> 2. **El front NO se publica hasta que la API esté desplegada**, no sólo fusionada:
>    contra una API sin la columna, la cabecera del certificado sale **en blanco**.
>
> **Y a `coal` y `coljordan` NO se les avisa** (Joseth, 15 sep: *«no importa lo de
> avisar»*). Se dice en negativo y no se borra la línea, porque el §4 del doc 38 llegó
> a recomendar lo contrario: una recomendación retirada que no se tacha se lee como
> pendiente, y pararía el despliegue de la siguiente sesión buscando un correo que
> nadie tiene que mandar.
>
> ### Y una cosa que se vio de camino y NO entra aquí
>
> **`years.frase_final_certificado` no la escribe nadie.** Se imprime en los dos fronts
> y la única escritura en toda la API es la copia al año siguiente: ni pantalla, ni
> ruta, ni cliente. Es `profesores.tono` antes del 4 sep con otro nombre. Se deja
> escrito en el §11 del doc 38 y **no se arregla en esta entrega**, porque es otra
> decisión —¿misma pantalla?, ¿mismo `PUT`?— y meterla aquí la convertiría en dos.

> ## ✅ ENTREGA 5 (modo promedio) — EN `main` (15 sep 2026)
>
> **Rama `feat/modo-promedio`, worktree `.worktrees/f7`, base `simonbolivar_testing_f7`.**
> Autorizada por Joseth el 14 sep: fase 0 + la columna + D30, y el redondeo **en un solo
> sitio, el que escribe la definitiva**.
>
> | | |
> |---|---|
> | **Fase 0** — la fórmula de 16 sitios a `App\Support\RepartoDeLaNota` | **EN `main`** (`ebfe43f`) |
> | **Fase 1** — `years.reparto_subunidades` | en la rama (`bbf6000`) |
> | **D30** — el modo encendido en los 16 sitios y las dos representaciones | en la rama (`754b872`) |
> | **Los 13 tests, las 6 instantáneas y los dos rojos que salieron** | en la rama, este commit |
>
> ### Lo que encontró correr la suite entera, que es exactamente para lo que se corre
>
> `Tests: 7 failed, 1 skipped, 2275 passed (php artisan test, .worktrees/f7, 9d30bcb)`, con
> `HEAD` y pendientes **idénticos antes y después** (06:17 → 06:34). **Seis eran
> instantáneas y el séptimo no lo era**, que es el que importa:
>
> 1. **`CentinelaDeLasColumnasDelAnioNuevoTest` — `postStore` no nombraba
>    `reparto_subunidades`.** El colegio que eligiera `promedio` **amanecería en
>    `porcentaje` cada enero**, y aquí ese defecto no deja una pantalla apagada: deja
>    **otras notas**. Vuelve a repartir por `subunidades.porcentaje`, que en un colegio de
>    promedio es justo la columna que ya no cuadra nadie —el modo existe para no tener que
>    teclearla—. Arreglado copiándola del año anterior, pegada a las cuatro del modelo de
>    evaluación y por el motivo que ya estaba escrito ahí. **No lo habría cazado ningún
>    test de la entrega, porque la entrega no tenía ninguno.**
> 2. **Las instantáneas de la fila entera de `years` son SEIS, no tres.** El relevo decía
>    «la terna, y si se mueve una cuarta, para y mira por qué». Se movieron tres más
>    —`years-store`, `years-guardar-cambios`, `years-delete`— y el porqué **ya estaba
>    escrito en el propio doc 35**: `b428153` (13 sep) les puso instantánea, y el documento
>    registra que `tools/lo-que-reparte-una-columna.py years` *«pasa de decir 3 a decir
>    6»*. O sea que §1.3 y su corrección conviven en el mismo fichero y **se lee primero la
>    que envejeció**. Las seis regeneradas; el diff es **seis líneas, una por fichero**, y
>    ninguna otra cosa se movió.
>
> ### Y `stan` estaba rojo antes de que nadie tocara la rama
>
> No venía en el relevo. `YearsController:1066` —el segundo bucle que rearmaba el resumen
> de auditoría leyendo `$antes[$campo]`— daba `offsetAccess.notFound` en nivel 7.
> **Comprobado sobre el fichero tal y como lo dejó `9d30bcb`**, no deducido. Arreglado
> donde estaba la causa: el «de» y el «a» salen ahora de la misma iteración, así que no
> hay dos listas cuyas claves haya que demostrar iguales. `[OK] No errors`.
>
> ### Lo que ahora sí sujeta la entrega — `tests/Contrato/RepartoDeLasSubunidadesTest.php`
>
> **Antes de esto, `grep -rn reparto_subunidades tests/` no devolvía nada**: los 156
> dirigidos que pasaban corrían todos en `porcentaje`, que es el defecto. Trece casos,
> montaje de **cuatro subunidades al 40/30/20/10 con notas 40/40/20/20** — desiguales a
> propósito, porque con cuatro al 25 % los dos modos dan el mismo número y el caso pasaría
> en verde con el interruptor desconectado. Da **34 en porcentaje y 30 en promedio**, los
> dos enteros (`notas_finales.nota` es `int`).
>
> **Y se comprobó que miden algo, con dos mutaciones deliberadas:**
>
> | mutación | qué se pone rojo |
> |---|---|
> | `pesoDeSubunidad` ignora el modo | 2 casos — la definitiva se queda en 34 |
> | sólo `putDetailed` ignora el modo | 2 casos — *«El cliente reconstruye 34 y la base guardó 30»* |
>
> La segunda es la que acredita el caso central: es **la separación entre lo guardado y lo
> servido**, que es la afirmación entera de la entrega, y ninguna de las dos mitades falla
> sola.
>
> ### La D30 está medida contra `myvc_flutter`, no supuesta
>
> «Los cuatro clientes quedan correctos sin tocar una línea» es comprobable en local y se
> comprobó: `myvc_flutter/lib/Http/LibroNotasApi.dart:68` multiplica por
> `subunidad.porcentaje` **del árbol** —que es la representación que el controlador
> convierte— y `lib/Models/UnidadModel.dart:46` lo parsea con `decimalO` sobre un `double`,
> así que el `25.0` del modo promedio **no rompe la deserialización**. Y el `0.5` de
> tolerancia de `porcentajeSubunidades` (`UnidadModel.dart:83`) absorbe el redondeo a dos
> decimales para cualquier `n` (con 7 subunidades: 14,29 × 7 = 100,03).
>
> ### Y la corrida que la cierra
>
> `Tests: 1 skipped, 2295 passed (22472 assertions)` — `php artisan test`, `.worktrees/f7`,
> sobre **`cfbee15`**, con `HEAD` y pendientes idénticos antes y después (06:39 → 06:54).
> **2295 = los 2282 de `main` + los 13 nuevos**, y ninguna prueba existente se movió.
> `composer run pint:test` PASS sobre 405 ficheros y `composer run stan` `[OK] No errors`.
>
> ### ✅ El año cerrado no cambia de modo, y el recálculo usa el suyo — EN `main`, a DIECISÉIS (15 sep)
>
> **Fundido en `d0827d5`.** `Tests: 1 skipped, 2336 passed (22776 assertions)` —
> `php artisan test`, `.worktrees/f7`, sobre **`7646671`**, con `HEAD` y pendientes
> idénticos antes y después. **603 rutas** contadas en el árbol principal después de
> fundir: ninguna de las cuatro entregas de hoy añade ruta.
>
> > **`main` se movió DOS VECES durante las corridas de hoy** —los títulos del
> > certificado y el preescolar por competencias—, y la segunda se vio porque el registro
> > de la corrida empezó a anotar también `main`, no sólo `HEAD`. Lo que `HEAD` idéntico
> > demuestra es que **nadie tocó mi árbol mientras medía**; no demuestra que el árbol
> > siguiera siendo el que iba a fundirse. Con trece worktrees vivos, **son dos
> > preguntas distintas y hacían falta las dos**.
>
> Dos huecos de la misma familia, los dos levantados por `myvc_front` con una regla que
> formularon ellos: **no es el cálculo, es de qué año se pregunta el modo.**
>
> - **`PUT years/modelo-evaluacion`** recibía el `year_id` por el cuerpo y no miraba el
>   año: con `can_edit_plantilla_notas` se podía poner 2023 en `promedio` y reescribir
>   sus definitivas guardadas. Ahora `exigirEscrituraEnElAnio` — superusuario, **la lista
>   pasa a dieciséis**— y el 422 del recuento delante. Se descartó el absoluto («ni el
>   superusuario», que es lo que dice la letra del plan) porque dejaría a un colegio con
>   el modo mal puesto sin más salida que un `UPDATE` a mano.
> - **`PUT definitivas_periodos/calcular-grupo-periodo`** resolvía el modo desde la barra
>   mientras el `DELETE` y el `SELECT` usan el `periodo_id` del cuerpo. **No se llega por
>   la interfaz** —medido por el front en los tres clientes—, lo que cambia la urgencia y
>   no la corrección.
>
> > **La raíz, que vale más que los dos arreglos**: el doc 28 §5.5 decía *«un año cerrado
> > conserva su modo para siempre… Ésa es la garantía, y es la misma de §4»*. **No era la
> > misma.** La de §4 es **estructural** —ninguna nota apunta a la plantilla, no se puede
> > incumplir— y ésta era **una costumbre**, que sólo se cumplía mientras nadie pulsara.
> > *Las dos se escriben igual*, así que la segunda heredó la solidez de la primera y
> > viajó por **tres documentos** sin que nadie la comprobara — el front encontró que
> > repetía la misma confianza dos veces, una de ellas como premisa **en el texto donde
> > estaba destapando que no lo era**.
> >
> > Y la mitad de este lado: **esta sesión se hizo la pregunta construyendo el 422, la
> > apartó y no la escribió en ninguna parte.** La duda que no se escribe no la hereda
> > nadie: se vuelve a descubrir un día, y puede ser tarde.
>
> ### ✅ Las dos de disciplina — EN `main`, de trece a QUINCE (15 sep)
>
> `nota_comportamiento/guardar-libro` y `disciplina/cambiar-situacion-derivante`,
> decididas por Joseth con la población delante: **1.593 de 2.047** filas de
> `dis_libro_rojo` y **316 de 327** de `dis_procesos` están en años cerrados. El 97 % de
> la segunda. Es el argumento de `ordinales`: allí el artículo del manual, aquí la
> anotación que lo cita.
>
> Entra con ellas el rastro que el doc 37 llevaba fichado: `cambiar-situacion-derivante`
> **no escribía `updated_by` ni `updated_at`** —estaba comentado a propósito— así que la
> línea de `bitacoras` era el único rastro. Ahora está en los dos sitios. Sin migración:
> las columnas ya existían.
>
> ### ✅ El avance del docente en promedio — EN `main` (15 sep)
>
> **Cuarta que levanta `myvc-front-8d` desde fuera, y la primera que no es un hueco de
> autorización sino una cuenta que deja de medir lo que dice.**
> `ChangeAskedController::getToMe` publica un avance por docente, mitad unidades y
> mitad subunidades. En promedio la segunda mitad no puede estar bien nunca —nadie
> mantiene `subunidades.porcentaje`, que es el trabajo que la entrega le quita al
> docente, y la columna entra a **0**— así que **el colegio entero se quedaría al 50 %
> como techo**. Medido con mutación: sin el arreglo sale exactamente `50.0`.
>
> Es la misma familia que el fallo que ya estaba documentado encima de esa consulta
> —un boletín independiente hacía sumar 200 y bajaba el avance igual—: **la métrica
> castiga una condición que el docente no controla.**
>
> Y el front no podía taparla como las demás: los otros llamantes de
> `porcentajeParaPintar` son rótulos, y **éste sale como juicio sobre el trabajo de una
> persona**.
>
> ### ✅ Y el `acepto_recalcular` que faltaba — EN `main` (`9684a6f`, 15 sep)
>
> El doc 28 §5.5 pedía que encender el reparto avisara con el recuento antes de
> escribir. **No estaba, y no era decisión de nadie**: ni en el código, ni en la lista
> de cinco pendientes del commit de la D30, ni en ningún sitio. Lo levantó
> `myvc_front` preguntando con qué forma escribía su pestaña — **la tercera vez en dos
> días que el hueco lo ve quien está al otro lado del contrato**, después de las tres
> altas del año cerrado y de la línea de `postStore`.
>
> Construido con el patrón de `acepto_desviacion`, sin ruta nueva. Lo caro no era el
> 422: era que **el número fuera el de verdad**, porque una cifra que exagera se
> aprende a ignorar. Seis casos y dos mutaciones que lo demuestran.
>
> `Tests: 1 skipped, 2305 passed (22572 assertions)` — `php artisan test`,
> `.worktrees/f7`, sobre `9684a6f`, con `HEAD` y pendientes idénticos antes y después.
> **603 rutas** contadas en el árbol principal después de fundir: sin ruta nueva.
>
> > **Y costó un rojo que no era del aviso sino de cómo se comprobó**: tres casos de
> > `RepartoDeLasSubunidadesTest` cayeron en la suite entera y pasaban solos, porque al
> > construir el aviso **se corrió el fichero nuevo y no el hermano que comparte
> > `PUT years/modelo-evaluacion`**. «Un subconjunto verde no basta», cometido por quien
> > lo tenía escrito delante ocho horas antes.
> >
> > Lo que **no** cayó se deja dicho porque vale tanto: el 403 del docente llano y los
> > dos 422 de validación siguieron verdes, o sea que el orden **permiso → validación →
> > aviso** es el correcto. Un aviso que se adelantara al 403 le diría a quien no puede
> > cuántas definitivas tiene el colegio.
>
> ### Y lo que quedaba, hecho
>
> - **Fundida** por fast-forward: `main` = `9134e57`, con la Entrega 5 en `a25a9a6`.
> - **El contador de rutas NO se mueve, y se contó en vez de suponerse**: `route:list
>   --json` en el **árbol principal, sobre `main` y después de fundir** da **603**, que es
>   lo que ya decía `CLAUDE.md`. Una entrega sin rutas nuevas no lo toca — pero eso se
>   comprueba, porque «no añade rutas» es exactamente lo que creía la sesión que dejó el
>   contador en 599 con el router en 601.
> - **`myvc-front-8d` avisado**: la Entrega 5 no añade ninguna escritura a su lista de
>   403 por año cerrado.
>
> > **Y este bloque decía «SIN FUNDIR» diez minutos después de estar fundido**, que es la
> > misma trampa que esta sesión acababa de señalar dos secciones más abajo. Se arregla
> > en el mismo commit que la fusión y no en el siguiente: **el estado de fusión es la
> > única línea de este documento que caduca por algo que hace uno mismo.**
>
> ### Lo que hay que saber sin abrir el diff
>
> - **Los 16 sitios son 16 y el doc 28 dice 18**: dos de los dieciocho son la fórmula
>   escrita **en un comentario** (`SubunidadesController:240`, `UnidadesController:457`).
>   El reparto por fichero del doc 28 se reproduce exacto doce días después, así que no
>   envejeció: **nació contando líneas**. *Un `grep` no distingue una fórmula de una
>   frase que habla de la fórmula.* Esas dos frases **dejan de ser verdad** con el modo
>   encendido y hay que tocarlas.
> - **Los 16 viven dentro de cadenas SQL**, ninguno es una expresión PHP. Por eso
>   `RepartoDeLaNota` devuelve **fragmentos de SQL** y no números, y por eso lo que entra
>   por parámetro son **alias de tabla, nunca valores de una petición**.
> - **Dos llamantes de `Unidad::deAsignaturaCalculada` NO pasaban `$year_id`**
>   (`Informes\BoletinesController:351`, `Informes\NotasActualesAlumnosController:187`).
>   Con el modo resuelto por año, esos dos boletines se habrían quedado en porcentaje
>   mientras la definitiva se guardaba en promedio. Arreglado; **no cambia nada hoy**
>   porque `$year_id` sólo se usa en la rama `con_desempenio` y ésos van por la otra.
> - **Quién escribe la columna: opción A.** `PUT years/modelo-evaluacion` acepta las dos
>   políticas, cada una opcional. **Cero rutas nuevas.** Y `toggle-cambiar-valor` pasa de
>   un `if` por columna a **una lista de columnas con dueño**, que es lo que su propio
>   comentario del 13 sep pedía para cuando entrara la segunda.
> - **La asimetría del redondeo está escrita en el docblock de `RepartoDeLaNota`**, no
>   sólo en un documento: el número **guardado** es el bueno y el que el cliente calcula
>   es una reconstrucción. Ante una diferencia **se corrige la pantalla, nunca al revés**.
>   La misma nota está del otro lado, en `promedio-ponderado.ts` de `myvc_front`.
> - **`Subunidad::deUnidad2` no tiene ni un llamante** en `app/` ni en `tests/`.
>   Encontrado de paso, no tocado.
>
> ### Y un aviso de `myvc_horarios` que llegó sin contestar (15 sep)
>
> El programa de escritorio se presenta con **`tauri://localhost` en macOS** y
> **`http://tauri.localhost` en Windows**, y el instalador de Windows se construye esta
> semana. Hoy `config/cors.php` cae a `*` y **funciona**; el peligro es al revés de lo
> intuitivo: **se rompe el día que alguien lo configure bien** y olvide el de Windows —
> y entonces falla **sólo en Windows**, con el Mac perfecto. **Sin contestar y sin
> comprobar**: hay una decisión cerrada de Joseth de que CORS va a `*`, así que lo que
> procede es comprobar si esa decisión sigue en pie antes de tocar nada.

> ## ✅ UN AÑO CERRADO YA NO LO ESCRIBE CUALQUIERA — 14 sep 2026, EN `main`
>
> **Fundido**: `b294ace` y `cfa45fa`. La rama `fix/escribir-en-un-anio-cerrado` ya no
> existe y el worktree `.worktrees/anios` no hace falta para leer esto.
>
> > **Este renglón decía «Sin fundir» hasta el 15 sep**, con las dos mitades en `main`
> > desde el día anterior. No lo cazó ningún test —aquí no hay ninguno que mire— ni nadie
> > de este repositorio: lo dijo **`myvc-front-8d`**, que había implementado su lado
> > contra esa lista y sabía dónde estaba. Es lo que avisa la cabecera de este documento:
> > **una instrucción no envejece a «hecho», envejece a mentira**, y «sin fundir» es una
> > instrucción disfrazada de hecho — le dice al que llega que le queda un trabajo que no
> > le queda. Su caducidad se comprueba en una orden:
> > `git merge-base --is-ancestor <commit> main`.
>
> ### ✅ RESUELTO EL MISMO DÍA — los tres `store` entran, y la lista sube a TRECE (15 sep)
>
> **Decisión de Joseth del 15 sep**, con la premisa medida delante. Cerrados
> `frases/store`, `escalas/store` y `POST contratos`; lo fija
> [`AltaEnUnAnioCerradoTest`](../../tests/Contrato/AltaEnUnAnioCerradoTest.php) —cuatro
> casos, y el primero **comprueba la premisa y no el candado**—, y el antes y el después
> está en [16](16-escribir-en-un-anio-pasado.md) §1. **`myvc-front-8d` avisado: son
> trece, y de su lado son tres líneas.** Lo que sigue es el porqué, que se conserva
> porque es la parte que costó:
>
> ### La asimetría de los `store`, y la premisa que no aguantó
>
> Hoy, en un año cerrado, **no se corrige la errata de una frase y sí se añade una frase
> nueva**: `update` y `destroy` están cerrados en `frases`, `escalas` y `contratos`, y el
> alta no. En `ordinales` el alta también se cerró.
>
> **La pregunta no es la asimetría: es sobre qué premisa se decidió.** El doc 16 razona
> que los otros tres `store` no necesitan candado porque «estampan `$user->year_id`, así
> que no pueden sembrar fuera de su año». **Eso es falso, y medido el 15 sep**: el año de
> la sesión **lo elige la barra de año**, y estos son los cuatro eslabones, leídos y no
> inferidos —lo levantó `myvc-front-8d`, y esta sesión lo había dado por bueno al revés—:
>
> ```
> routes/api/estructura.php:165   PUT years/useractive/{year_id}      <- auth.personal, los 74
> YearsController:758-778         $usuario->periodo_id = $peri->id    <- de CUALQUIER año con periodos
> ContextoDeUsuario:169           left join years y on y.id=per.year_id  ->  y.id as year_id
> FrasesController:31             $frase->year_id = $user->year_id
> ```
>
> Y el dato, en la copia de desarrollo: `years` id **6** = 2023 con `actual = 0`, y los
> `periodos` **22–25** cuelgan de él. Con `AnioCerrado` —cerrado es
> `year < MIN(year donde actual = 1)`, o sea `< 2025`— **2023 está cerrado**, y
> `putUseractive` no mira el año: si tiene periodos, mete al usuario dentro.
>
> **O sea que sí se siembra en un año cerrado, y por el camino normal.** Si Joseth quiere
> la asimetría tal cual, se queda y se escribe por qué; si se decidió creyendo que el alta
> no llegaba, **faltan tres `store`** en la lista de diez — tres guardas aquí y tres líneas
> en `myvc_front`, que ya tiene el predicado montado. **No se mueve nada hasta que
> conteste**: la lista de diez es decisión suya del 14 sep.
>
> `ordinales/store` sigue siendo distinto de los otros tres y por lo que dice el doc 16:
> es el único que toma el `year_id` **del cuerpo**, así que en él un año cerrado es
> alcanzable **sin pasar por la barra**. Por la barra lo son los cuatro.
>
> Salió de una pregunta de Joseth sobre si las competencias podían estropear los boletines
> viejos. **La respuesta a eso es que no** —todo lo de la feature nueva es por año, las
> migraciones son aditivas y `FraseAsignatura::deAlumno` nombra sus columnas—, pero
> comprobándolo salió **un agujero que no es de esa feature y lleva ahí desde siempre**:
>
> > `EscalasDeValoracionController::putUpdate` y `deleteDestroy` **no miraban de qué año
> > era la fila**. Con `auth.personal`, cualquiera de los **74** del personal podía
> > renombrar la banda «BÁSICO» de 2023 y cambiar lo que dicen sus boletines la próxima vez
> > que se impriman. Lo mismo en `frases/update`, `frases/destroy` y `contratos/destroy`.
>
> **Dos decisiones de Joseth, las dos del 14 sep con las poblaciones delante:**
>
> | | |
> |---|---|
> | **Año cerrado** | sólo **superusuario**, y sobre **las DIEZ** escrituras con año: `frases` ×2, `escalas` ×2, `contratos` ×1 y **`ordinales` ×5** |
> | **Borrar una banda del año en curso** | **avisar y dejar pasar**: 422 con la población, se borra con `acepto_desviacion`. *«Igual pueden crear un nuevo listón que reemplace el que eliminaron»* |
>
> Es la **opción B** que [16-escribir-en-un-anio-pasado.md](16-escribir-en-un-anio-pasado.md)
> tenía escrita desde el 23 ago, elegida tres semanas después — y **completa**: los cuatro
> catálogos, `ordinales` incluido.
>
> > ### ⚠️ `ordinales` SÍ ROMPE UNA PANTALLA — hay que avisar al front antes de desplegar
> >
> > Es el único de los cuatro al que **llega un profesor** (`panel.ordinales` pide
> > `can_work_like_teacher` **o** `can_work_like_admin`) y su pantalla —Disciplina ▸
> > Ordinales— tiene un **selector de año de verdad**. Con esto desplegado, ese selector
> > deja de guardar en los años cerrados para quien no sea superusuario, y el síntoma será
> > **un 403 al guardar**, no un control deshabilitado. Hay que esconderlo o deshabilitarlo
> > para los años que no sean el corriente.
> >
> > **El año corriente no cambia para nadie**, y lo sujeta
> > `ElManualDeConvivenciaDeUnAnioCerradoTest`.
>
> **`ordinales` son CINCO escrituras y la familia parecía de cuatro**: la quinta es
> `putGuardarValorConfig`, que escribe en `dis_configuraciones` —otra tabla con `year_id`—.
> Y **`ordinales/store` es el único `store` de los cuatro catálogos con candado**, porque es
> el único que toma el `year_id` **del cuerpo**: los otros tres estampan `$user->year_id` y
> no pueden sembrar fuera de su año.
>
> ### Lo que hay que saber sin abrirlo
>
> - **«Cerrado» es ANTERIOR al actual, no «distinto del actual».** `years` tiene **2026 vivo
>   con `actual = 0`** mientras corre 2025: el colegio monta el año siguiente antes de
>   conmutarlo. La regla obvia le habría cerrado esa pantalla.
> - **No rompe el panel de Colegio ▸ Años**, que es lo que el doc 16 avisaba que rompería.
>   Medido: el rol `Admin` son **10 personas y las diez son superusuarias**, y **ningún
>   permiso de rol está repartido a nadie** en ese colegio. **Es de UN colegio**: la
>   comprobación del día del despliegue es si algún otro le dio ese panel a alguien sin
>   `is_superuser`.
> - **Cinco tests existentes se pusieron rojos y ninguno era una regresión**: los cinco
>   elegían su fila con `ORDER BY id LIMIT 1`, que en este seed cae en el **año 1 o el 7**
>   —cerrados—, con un token llano. Ninguno de los cinco mide años. Acotados a su año, que
>   es lo que siempre quisieron decir. *Un test que se apoya en «la primera fila que salga»
>   hereda todas las propiedades de esa fila, incluidas las que no está midiendo.*
> - **`EscrituraDeCatalogoDeOtroAnioTest` cambió de afirmación, no se relajó.** Estaba
>   puesto ahí para caer el día que alguien cerrara **una** de las cinco sin mirar las
>   otras — y funcionó exactamente así. Ahora mide **los dos lados**: 403 para el personal
>   llano y **200 para el superusuario**, que es lo que mantiene viva la 05 §27.4.
>
>   > **Y tenía dentro un verde falso que sólo se vio al tocarlo:** con el guard ya puesto
>   > seguía en verde, porque su sujeto era `users_1`, que es **superusuario**. Decía «el
>   > personal alcanza el año de al lado» y demostraba «el superusuario alcanza el año de
>   > al lado». Es el caso contra el que avisa el docblock de
>   > `CasoDeContrato::usuarioLlanoDelPersonal()`, dos ficheros más allá.
>
> - **Una cobertura que falta y se dice**: el caso del año futuro **se salta**, porque en el
>   seed 2026 está en la papelera y no hay ningún año vivo posterior al corriente contra el
>   que medir. Y **nada demuestra** que la regla aguante con varios `actual = 1`: ese dato
>   lo documenta el comentario de `putSetActual`, pero **no se ha reproducido en ninguna de
>   las dos bases** — en las dos hay exactamente uno vivo.
>
> ### Y de paso: `composer run stan` vuelve a `[OK] No errors`
>
> Estaba **rojo en `main` desde la Fase 6** con 8 errores en dos scripts de `tools/`, y la
> causa no era el código: **siete scripts de `tools/` declaraban `function pedir()` en el
> espacio global**, cinco devolviendo `{estado, cuerpo}` y dos devolviendo además `ms` y
> `consultas`. PHPStan resuelve un nombre global a **una sola** declaración, se quedaba con
> la de dos claves y marcaba las otras dos como offsets inexistentes.
>
> **Anotar el `@return` no lo arregla** —gana la declaración que phpstan resolvió, no la que
> se anota—, así que lo que se separa es el nombre: las dos que miden pasan a llamarse
> `pedirMidiendo()`. Deja de ser un accidente y pasa a decir algo: **medir y no medir son
> dos funciones**.
>
> `tools/` no lo ejecuta ninguna suite y no entra en el alcance de Pint: **larastan es lo
> único que pasa por esa carpeta**, así que un rojo ahí sólo lo ve quien corra `stan`.

> ### La suite entera, con la orden que la produjo
>
> ```
> Tests: 1 skipped, 2282 passed (22361 assertions)
> ```
>
> `docker exec -w /app/.worktrees/anios -e DB_TEST_DATABASE=simonbolivar_testing_anios
> 8myvc-app-1 php artisan test` — las cuatro testsuites, grupo `barrido` excluido—,
> **1.327,32 s**, cero rojos. Árbol `.worktrees/anios` en **`b294ace`** más los seis
> ficheros de `ordinales`, `tools/` y documentación sin commitear; **`git status` daba los
> mismos seis antes y después y `HEAD` no se movió**, que es lo que hace comparable la
> cifra.
>
> `composer run pint:test`: **PASS, 401 ficheros**. `composer run stan`: **[OK] No errors**.
>
> > **La PRIMERA corrida se descartó entera, y conviene que se sepa por qué.** Dio un rojo
> > —`RejillaPremarcadaTest > un desempeño de 388 caracteres viaja entero`— que **pasa
> > aislado**. No era una regresión: **estuve editando ficheros mientras corría**, así que
> > esa corrida medía un árbol que dejó de existir a mitad. Se tiró y se relanzó con el
> > árbol quieto.
> >
> > *Una suite de veintidós minutos no mide «la rama»: mide los ficheros que haya en el
> > disco cada vez que carga una clase.* De ahí que esta cifra lleve al lado el `HEAD` y el
> > conteo de pendientes de antes y después, y no sólo el nombre de la rama.
>
> **Los casos nuevos son 12** —6 de `BorrarUnaBandaDeLaEscalaTest`, 3 de
> `ElManualDeConvivenciaDeUnAnioCerradoTest` y 3 que `EscrituraDeCatalogoDeOtroAnioTest`
> pasa de 2 a 5—, uno de ellos el que se salta. Los otros cuatro ficheros tocados
> **conservan su número de casos**: se les acotó el sujeto, no se les quitó nada.
>
> **No hay una cifra de `main` del mismo día contra la que restar**, así que la resta no se
> publica: lo que se afirma es el número, su orden y su árbol.

> ### Y el censo de al lado, que este cierre dejó abierto
>
> **¿Eran de verdad los únicos?** Hay **23 tablas con `year_id`**, no las tres del lote A.
> Medido y escrito en
> **[37-quien-mas-escribe-en-un-anio-ajeno.md](37-quien-mas-escribe-en-un-anio-ajeno.md)**:
> **58** rutas de escritura tocan una de esas tablas y **8** no miran el año.
>
> > **Dije 42 primero, y el 42 era de mi detector.** Contaba *«el método menciona la
> > tabla»* —en un `SELECT`, o **dentro de un comentario**— en vez de exigir el nombre
> > pegado al verbo que escribe. En este repositorio, donde los comentarios explican la
> > consulta de al lado, eso es la mayoría del ruido. *El primer sitio donde mirar cuando
> > el número sale raro es el detector* — y lo caro no es el número: **42 suena a una
> > semana y 8 suena a una tarde**.
>
> Y **de las ocho, cuatro se cayeron al medirlas**: las del PIAR parecían escribir todos
> los años de un alumno a la vez —`WHERE alumno_id=?`— y en la base hay **65 filas y 65
> alumnos**, una por alumno. `putField` además tenía su lista blanca de columnas, que yo
> había dado por comprobar.
>
> **Quedan cuatro candidatas, y NINGUNA se ha cerrado** — el encargo era medir:
> `requisitos/update`, `requisitos/destroy` (los requisitos de matrícula de un año pasado)
> y `nota_comportamiento/guardar-libro`, `disciplina/cambiar-situacion-derivante`
> (**registro disciplinario de menores, por año** — el mismo argumento que cerró
> `ordinales`, un piso más abajo).

> ### Lo que le toca al front
>
> `DELETE api/escalas/destroy/{id}` **pasa a contestar 422 la primera vez**, con
> `{message, definitivas, celdas, year_id}`, y se borra repitiendo con
> `acepto_desviacion` (vale en el cuerpo o como `?acepto_desviacion=1`). Avisado a la
> sesión de `myvc_front` el mismo día; **su §11.2 llamaba a esta ruta
> `escalas-de-valoracion/{id}`, que no existe**.

> ## EL MODELO DE EVALUACIÓN PASA A SER UNA ELECCIÓN DEL COLEGIO — plan escrito, SIN CÓDIGO (13 sep 2026)
>
> **Joseth cerró la noche del 13 sep las nueve decisiones que bloqueaban las competencias**, en una
> tanda de 22 tomadas una a una con sus alternativas delante:
> `myvc_front/DECISIONES-MODELO-DE-EVALUACION.md`. Lo que cambia el marco y no estaba en el doc 28:
> **lo de hoy no se sustituye, se queda como una opción del colegio** —
> `years.modelo_evaluacion enum('ponderado','competencias') DEFAULT 'ponderado'` — que gobierna **lo
> que se ve y lo que se escribe** y **ningún cálculo**. Volver atrás es cambiar el enum.
>
> **El plan del backend está en [35-el-modelo-de-evaluacion-del-colegio.md](35-el-modelo-de-evaluacion-del-colegio.md).**
> Siete fases desplegables, **26 rutas** y **cinco migraciones**. **Las cuatro decisiones que el
> plan abrió se cerraron el mismo día** (D23–D26); no queda nada bloqueado.
>
> > ### DÓNDE ESTÁ A LAS 13:0x DEL 13 SEP — **cuatro fases dentro de `main`, y 20 de las 26 rutas**
> >
> > | fase | estado |
> > |---|---|
> > | **0** — el `ALTER` a `text` | **EN `main`**, suite entera `2098 passed`, árbol limpio |
> > | **1** — `years.modelo_evaluacion` + displaynames | **EN `main`** (`4e0033c`), suite `2115 passed` |
> > | **2** — competencias + catálogo del MEN | **EN `main`**, 7 rutas |
> > | **3** — desempeños: catálogo, siembra y los del docente | **EN `main`** (`644d0f3`), 12 rutas |
> > | **4** — la rejilla premarcada | **EN `main`**, 2 rutas (en la familia `desempenos`) |
> > | **5** — qué comparten los tres boletines *(medición)* | **EN `main`** (`3d6a509`), 0 rutas |
> > | **6** — el boletín nuevo | **EN `main`**, **2 rutas** y no 4 |
> >
> > **LAS SIETE FASES ESTÁN CONSTRUIDAS.** Lo que queda del 35 no es código: es el despliegue, y
> > los dos censos que sólo se pueden correr contra el servidor.
> >
> > **El router está en 603**, contado con `route:list --json` en el árbol principal.
> > **603 − 579 = 24**, y el plan decía **26**: la diferencia es que **la Fase 6 necesitó DOS rutas
> > y no las cuatro** que estimé «calcadas de `boletines3`». O sea que el plan se pasó por dos, y
> > se dice — las seis que se fueron sumando desde las 20 originales salieron de huecos reales, y
> > ésta sobraba.
> >
> > Comprobado familia por familia: `competencias` **7** con el catálogo del MEN **dentro** de la
> > familia —la condición para no mover `familias-que-nunca-entran-en-el-candado.json`, que en
> > efecto **no se ha movido en todo el día**—, `desempenos` **14** (12 de la Fase 3 más las 2 de
> > la rejilla, que viven en esa familia) y `boletines-competencias` **2**.
> >
> > **Lo que fue la prueba del plan y no una casualidad**: la Fase 1 movió **12 instantáneas** y
> > **tres se movieron solas** —`muestreo-years`, `-colegio`, `-trashed`—, que es exactamente la
> > terna que §1.3 había predicho **antes de que existiera el código**, y `familias-que-nunca-...`
> > **no se movió**, que era la otra predicción. Las otras seis las ensanchó el autor a mano, y
> > tres son de ruta.
> >
> > ### Y un fallo VIVO que salió de construir la Fase 4, y no es del módulo
> >
> > **`notas_finales.nota` es `decimal(7,4)` y las bandas de `escalas_de_valoracion` siguen siendo
> > `int`**, así que una nota en una frontera no casaba con ninguna: el boletín salía **sin nivel y
> > en silencio**. Medido: **13 de 127.891** definitivas en `simonbolivar`, 4 exactamente por el
> > decimal. Lo destapó `myvc-front-50` construyendo la rejilla; está medido en el
> > **[36](36-la-nota-decimal-y-las-bandas-enteras.md)** y **arreglado en `bd02f66`**.
> >
> > **Eran TRECE sitios y el 36 contaba seis**: los ocho de SQL dejaban la fila sin nivel y los
> > **cinco de PHP ya hacían `round()`**, o sea que imprimían SUPERIOR. **El mismo alumno tenía dos
> > niveles según qué informe se imprimiera.** Regla única en los trece —`nota < porc_final + 1`—,
> > las 13 huérfanas pasan a 9 y **ninguna asciende**; las nueve que quedan son notas por encima
> > del techo, que es un dato malo y no un hueco.
> >
> > ⚠️ **La suite entera NO se ha corrido sobre ese arreglo** —ventana de silencio pedida por
> > `myvc-horarios-8f` hasta las 18:35— y **hace falta antes de desplegar**: toca ocho consultas de
> > informes.
> >
> > **Y una población que cambió de verdad** (`690ac87`): `por_defecto` cruza en
> > `CensoDeInterruptoresTest` de *«no decide nada»* a *«alguien decide con ella»* —28/65 en vez
> > de 29/64—, porque el candado de la D14 es **el primer sitio del backend que la lee para
> > decidir**. No es un fallo: es lo que la decisión 14 existía para construir.
>
> ### ⏳ ESPERA UNA DECISIÓN TUYA — seis respuestas que reparten la fila entera de `years`
>
> **No entra en ninguna fase y por eso se escribe aquí**: es cobertura ausente en endpoints
> **vivos**, descubierta midiendo la Fase 1 pero anterior a ella. Detalle y tablas en
> [35 §1.3](35-el-modelo-de-evaluacion-del-colegio.md).
>
> **Nueve rutas devuelven la fila entera de `years`; tres las vigila una instantánea y seis no.**
> A las seis, cualquier columna que se añada a esa tabla les sale en la respuesta **sin que nada
> se ponga rojo** — hoy `modelo_evaluacion`, mañana la siguiente.
>
> | sin vigilar | quién puede llamarla |
> |---|---|
> | `POST years/store` · `PUT years/guardar-cambios` · `DELETE years/delete/{id}` | `auth.personal` |
> | `DELETE years/destroy/{id}` · `PUT years/restore/{id}` | `esSuperusuario` |
> | `PUT myimages/cambiarlogocolegio` | `esAdministrativo` |
>
> **La pregunta es una y no urge**: ¿esas seis **ganan las columnas nuevas a propósito**, o alguna
> debería pasar a columnas nombradas? Lo que no se puede es seguir sin decidirlo, porque hoy la
> respuesta la da el azar de qué `SELECT` se escribió en 2018.
>
> **El noveno —`cambiarlogocolegio`— vive fuera de `YearsController`**, que es donde tres sesiones
> miramos: cambiar el logo del colegio devuelve el año entero. Censamos esa carpeta **porque la
> tabla se llama `years`**; no fue contar mal, fue contar bien sobre la carpeta equivocada.
>
> > **Y al mirarlo salió otra cosa, que es independiente de la decisión**: las cifras que
> > dimensionaban esa exposición —«45 personas»— **no se reproducen**. `auth.personal` son **74**
> > en la base de desarrollo y **71** en la de tests, no 45; y el par de `CLAUDE.md` («2.328
> > cuentas a 45») tampoco sale: hoy son **2.358**. No es que envejeciera —sólo hay tres cuentas
> > de personal creadas en 2026—. Está anotado en el 35 como *«no sé de dónde salió el 45»* en vez
> > de sustituido en silencio. **Ninguna de esas cifras es de producción: son del docker.**
>
> ### ✅ FASE 0 HECHA — 13 sep 2026, y el `ALTER` YA ESTÁ EN `main`
>
> `frases_asignatura.frase` es **`text`** desde `10a09a8`. Los dos ficheros de
> `fix/frases-asignatura-text` —la migración `2026_09_05_100000_frase_del_boletin_en_text` y
> `tests/Contrato/FraseLargaEnElBoletinTest`— están dentro de ese commit, que es **de
> documentación y no los menciona**: otra sesión los recogió del índice mientras ésta los tenía
> preparados. Se anota en vez de reescribirse, porque rehacer el historial debajo de otra sesión
> rompe más de lo que ordena. **Lo que hay que retener para el despliegue: `10a09a8` trae una
> migración dentro.**
>
> **`Tests: 2098 passed (18717 assertions)`**, con
> `docker exec 8myvc-app-1 php artisan test` —las cuatro testsuites, grupo `barrido` excluido—,
> 1.372,64 s, base reconstruida entera, árbol principal con el contenido de `main` @ `10a09a8`,
> `git status` limpio al terminar. **No se compara con las 1.926 de la rama**: aquéllas son de
> `ab23e2d`, 169 commits y siete migraciones antes.
>
> ### ✅ FASE 1 HECHA — 13 sep 2026, `4e0033c`. **El front ya puede llamar a la ruta**
>
> `years` tiene sus cuatro columnas —`modelo_evaluacion enum('ponderado','competencias')` más los
> tres rótulos del desempeño— y el colegio las cambia desde:
>
> ```
> PUT /api/years/modelo-evaluacion     auth.personal + can_edit_plantilla_notas DENTRO (D24)
> { "modelo_evaluacion": "ponderado"|"competencias", "year_id": <opcional, por defecto el de la sesión> }
> 200 { year_id, modelo_evaluacion, anterior, desempeno_displayname, desempenos_displayname, genero_desempeno }
> 403 sin permiso · 422 fuera del enum · 404 año inexistente o en la papelera
> ```
>
> **`modelo_evaluacion` viaja en la sesión** —`POST auth/login` dentro de `usuario`, `GET auth/me`
> y `POST login` en la raíz—, que es de lo que depende el front para decidir si enseña lo nuevo:
> **si la clave no llega, el front se comporta como `ponderado`**. Comprobado contra el docker.
>
> **La ruta 580**, contada con `route:list --json` en el árbol principal. **DOCE** instantáneas
> movidas: **3** por la columna sola (las que predecía §1.3), **6** porque este commit ensanchó a
> mano el contexto de sesión —proyecciones nombradas, no un cuarto comodín— y **3** por la ruta.
> `TOTAL_PUBLICAS` sigue en doce y el censo del candado no se movió.
>
> > **Aquí decía «diez», y era mío y era falso — dos veces en la misma frase.** El titular de
> > `23d1d98` dice diez y su cuerpo dice «las otras siete»; el desglose correcto es **3 + 6 + 3 =
> > 12**, o sea que ninguna de las dos cifras del encabezado cuadra con el reparto que va debajo
> > de ella. **El reparto siempre estuvo bien**: lo que falló es que el titular se escribió antes
> > que el desglose y nadie los restó. Contado ahora con
> > `git show --name-only --format="" 4e0033c | grep -c "tests/Contrato/Snapshots/"` → **12**, y
> > **no con `--stat`**, que trunca las rutas largas y hace desaparecer `Snapshots/` de algunas
> > líneas: por ahí salió un 11 en otra sesión y por ahí salió mi diez.
> >
> > **Y la corrección que importa más que el número.** El titular de `23d1d98` —*«por qué se
> > movieron DIEZ instantáneas y no tres»*— se lee como que §1.3 se equivocó, y **mi propio
> > cuerpo demuestra lo contrario**: las tres que predijo se movieron **solas** y por la razón
> > prevista. Las otras nueve las movió este commit a mano —seis proyecciones nombradas y tres
> > de la ruta—, y eso no es un fallo del pronóstico: es trabajo que el pronóstico no estaba
> > contando porque nadie se lo había pedido. Con varias sesiones citando ese documento, **lo
> > que sobrevive de un commit es el titular**, así que la frase queda enderezada aquí:
> > **§1.3 acertó, y la Fase 1 real es su confirmación.**
> >
> > Las seis proyecciones que hubo que ensanchar son **exactamente** las seis que `8myvc-c1`
> > había medido una hora antes desde el otro lado, sin vernos. Dos mediciones separadas que
> > coinciden no son dos avisos: son una cosa sabida.
>
> **Lo que se cerró de paso y no estaba en el plan**: `PUT years/toggle-cambiar-valor` escribía
> **cualquier** columna de `years` con sólo `auth.personal`, así que D24 se saltaba con un `PUT`
> de tres campos. Excluida ahí igual que `actual`.
>
> **Nada de esto recalcula ni borra (D3)**, y lo sujetan tres casos de
> `ModeloDeEvaluacionDelAnioTest`: el censo alrededor del endpoint, las lecturas en los dos modos
> y la definitiva llevada a punto fijo.
>
> ### La suite entera de la Fase 1: `Tests: 2115 passed (18882 assertions)`
>
> `docker exec -e DB_TEST_DATABASE=simonbolivar_testing_f1 8myvc-app-1 php artisan test` —las
> cuatro testsuites con el grupo `barrido` excluido—, **1.590,23 s**, cero fallos. Árbol principal
> con el contenido de `main` @ `a208e80`; `05c82af` entró a mitad de corrida y es un
> `array_values()` en un ayudante del test nuevo, semánticamente idéntico. `composer run stan`:
> **[OK] No errors** sobre 609 ficheros, y `pint --test` en verde sobre los cinco ficheros de la
> fase que entran en su alcance.
>
> **2.115 = 2.098 + 17**, y esa resta es lo único que hace comparables las dos cifras: los 17 son
> exactamente los casos de `ModeloDeEvaluacionDelAnioTest`. Que cuadre a la unidad es lo que dice
> que la Fase 1 **no movió ninguna prueba existente** — que es la afirmación que la fase necesita,
> no el «2.115 en verde».
>
> **Y la base es propia, `simonbolivar_testing_f1`, y eso no es un detalle.** La primera corrida
> contra la compartida **murió con SIGTERM** (`EXIT=143`) mientras otra sesión lanzaba
> `--filter=CompetenciasTest` en el mismo contenedor. Dos suites contra la misma base dan
> deadlocks —está escrito en `CLAUDE.md` y en 03— y aquí se cobró una corrida de veinte minutos.
> Con cuatro sesiones vivas, **una base por sesión no es higiene: es la diferencia entre medir y
> volver a empezar.**
>
> ### Lo que hay que saber sin abrirlo
>
> - **La Fase 0 era rebasar y volver a medir, no «fusionar `fix/frases-asignatura-text`»** — hecho,
>   ver arriba. La
>   rama está bien y hace lo que dice —tres commits, dos ficheros, ninguna instantánea tocada, y
>   `frases_asignatura.frase` sigue `varchar(255)` en `main`—, pero su base es **`ab23e2d`, 169
>   commits por detrás**, con **siete migraciones** en medio. Sus **1.926 verdes son ciertas y son
>   de aquel árbol**; el propio commit lo dice. Una cifra de pruebas se publica con su orden y con
>   el hash contra el que corrió.
> - **Una columna nueva de `years` mueve TRES instantáneas** — `muestreo-years`, `-colegio` y
>   `-trashed`—, que son los tres únicos caminos que publican la fila entera (`getIndex` y
>   `getColegio` con comodín en SQL crudo, `getTrashed` con Eloquent). Es
>   [30](30-lo-que-reparte-una-columna-nueva.md) otra vez, nueve días después.
>
>   > **Esta línea dijo «~30» durante unas horas, y era mío y era falso.** Conté *instantáneas
>   > que mencionan una columna de `years`* —31 de 125— cuando lo que mueve una instantánea es
>   > que la respuesta **gane una clave**, y eso sólo pasa donde viaja la fila entera: las otras
>   > 28 llevan **proyecciones nombradas** y son inmunes. Corregido en
>   > [35](35-el-modelo-de-evaluacion-del-colegio.md) §1.3, y con ello **el riesgo de la Fase 1
>   > vuelve a ser el que decía la decisión, no el que dije yo**.
> - **Y nadie podría escribir `modelo_evaluacion`**: `putGuardarCambios` nombra **21 columnas** una
>   a una. Es `profesores.tono` visto **antes** de cometerlo. De ahí sale una ruta que la tanda de
>   decisiones no contaba, y la pregunta de quién puede llamarla: **los dieciséis `years/*` de
>   escritura son `auth.personal`, o sea cualquier docente.**
>
> ### Las tres decisiones que abría — **CERRADAS el mismo 13 sep**, y ninguna fase queda bloqueada
>
> | | qué decidió Joseth |
> |---|---|
> | **D24** | ¿quién cambia el modelo del año? **La propuesta entera**: `PUT years/modelo-evaluacion` con `can_edit_plantilla_notas` dentro |
> | **D25** | los desempeños de «todos los grados» y los de 6.º **acumulan** — y es **deliberadamente lo contrario** que la plantilla, donde gana la más específica |
> | **D23** | **la celda de la rejilla ES el nivel**: Superior/Alto/Básico/Bajo por cruce, premarcado por la nota |
>
> **Y la que este plan abrió de vuelta también se cerró: D26.** La escala del alumno con PIAR
> **usa las mismas del año, y no se construye nada**. Joseth no eligió entre las opciones: puso en
> duda la premisa. La escala decide la promoción y tiene que seguir siendo equivalente a la
> nacional (1290 art. 5); lo que se flexibiliza es el **desempeño**, que ya tiene su
> `alumno_id`. **La pregunta salía de mezclar dos casos** que el doc 28 tenía en la misma casilla:
> preescolar es un grupo entero sin números y va **por grupo** (`grupos.caritas`); el alumno con
> PIAR es un individuo dentro de un grupo numérico. Juntarlos hacía aparecer una escala por alumno,
> que no existe.
>
> **D23 es la que hay que leer, y no porque contradiga el hallazgo.** El desajuste era real —
> `escalas_de_valoracion` da un nivel y la fila de `desempenos` no lleva ninguno — y sigue
> siéndolo. Lo que estaba mal era **el marco**: el plan preguntó *«qué texto escoge el nivel»*
> dando por supuesto que la celda era un **sí/no**, y con ese supuesto las dos únicas salidas eran
> malas. La pregunta que sí tenía respuesta era *«¿por qué es binaria la celda?»*. **Cuesta tres
> columnas anulables en `frases_asignatura`** —`desempeno_id`, `escala_id` y `nivel`— y desbloquea
> la Fase 4 entera. Medido en [35](35-el-modelo-de-evaluacion-del-colegio.md) §1.5.
>
> ### Y una casilla vieja de este documento que ya no dice lo que hay
>
> La decisión **2** de «LO QUE ESPERA UNA DECISIÓN» contestaba **no** a fundir
> `fix/frases-asignatura-text`, y el argumento era la **tanda del día 10**: sería la octava
> migración de una tanda ensayada sobre siete. **Hoy es el 13** y **D22 la pone de paso cero de
> todo lo demás**. No la tacho porque **no sé si la tanda del día 10 llegó a salir** —no hay
> casilla que lo diga y no lo puedo comprobar desde aquí—, y eso es exactamente lo que hay que
> averiguar antes de fundir: *una instrucción no envejece a «hecho», envejece a mentira*, y ésta
> lleva tres días pudiendo hacerlo.


> ## LA FOTO DEL 5 SEP 2026, 07:36 — Y ES UNA FOTO, NO UN ESTADO
>
> Recontada con las órdenes de arriba, **no heredada de nadie**. Si la lees más tarde,
> **vuelve a correrlas**: lo de abajo es lo que se vio, no lo que hay.
>
> | | |
> |---|---|
> | `main` | `ac09cb7` |
> | árbol principal | rama `fix/el-ensayo-mide-el-arbol-de-artisan` @ `ac09cb7` — **no es `main`**, y es de otra sesión |
> | sin commitear en el principal | `tools/ensayo-de-la-tanda.sh` — huérfano del apagón, **en rescate por otra sesión**; no lo commitees — **CERRADO 07:41: commiteado en `ed542ff`**, ya rige lo contrario |
> | ramas `--no-merged main` | **8**, y **ninguna** está a menos de 3 commits de retraso |
> | worktrees | **17** |
> | docker | estaba **caído** al empezar la mañana; lo levantó `8myvc-25` |
> | bases `simonbolivar*` | **20**, y `simonbolivar_testing_h` sigue **rota** (95 tablas, parada en `2026_08_31_100000`) |
>
> **Las ocho, con adelante y atrás — que es lo único que separa una cola de un resto:**
>
> ```
> feat/plantilla-de-notas             5 adelante    3 atrás    <- trae el router a 577
> test/guard-del-ensayo               5 adelante    3 atrás    <- BORRADA 07:40, era de usar y tirar
> docs/barrido-profesor-serializado   8 adelante   37 atrás
> docs/appkey-compartida-fortul-lal   5 adelante   43 atrás
> docs/despliegue-remedido            3 adelante   37 atrás
> fix/frases-asignatura-text          3 adelante   56 atrás
> feat/calendario                     2 adelante  166 atrás
> fix/columnas-en-los-modelos-no-borra 1 adelante  41 atrás
> ```
>
> ### Y lo que esta tabla enseña al compararla con la de hace una hora
>
> **Ninguna rama desapareció: lo que se movió fue `main`.** A las 06:2x había **once**
> y cuatro parecían vivas; a las 07:36 hay **ocho** y las dos que estaban a `0 atrás`
> —`fix/disponibilidad-si-se-guarda` y la rama reservada del Lote G— **están dentro de `main`**.
> Una fusión **reclasifica el censo entero de golpe**, y por eso «cuántas ramas quedan» no es
> una cifra que se pueda heredar de una casilla: se cuenta o no se dice.

> ### Lo que se cerró de esta foto entre las 07:36 y las 07:41, y por qué se cierra aquí dentro
>
> **Dos renglones de arriba envejecieron en cinco minutos, y no envejecieron igual.** El de las
> ramas es una **medición**: `test/guard-del-ensayo` era un árbol de usar y tirar que hice para
> ver **abortar** el guard desde un worktree, y se fue con su base y su carpeta en cuanto lo vio;
> una medición vieja se relee corriendo la orden, y este bloque ya lo dice.
>
> El otro **no era una medición, era una instrucción**: *«no lo commitees»*. Y una instrucción
> no envejece a «hecho», **envejece a mentira** — es la misma especie que el aviso escrito en
> futuro que nadie mueve el día que se despliega, y que la casilla de más abajo ya cobró una vez.
> El fichero está commiteado en `ed542ff` con el guard **ejercitado por los tres lados**, así que
> a partir de las 07:41 la fila decía lo contrario de lo que hay que hacer: quien la leyera
> dejaría el trabajo colgando esperando un rescate que ya ocurrió.
>
> *La fila se corrige y no se borra: explica por qué el árbol principal tenía algo colgando esa
> mañana, que es información que no se repone corriendo nada.*

> **FUNDIDA el 19 sep 2026, y esta casilla lo dijo mal catorce días.** Comprobado el 20 sep con
> `git merge-base --is-ancestor origin/fix/reparar-la-hora-y-uniformes main` y con las dos
> migraciones `2026_09_06_*` presentes en el árbol principal. Entró en la integración que vació la
> cola de ramas (`b8abca2`), y **nadie volvió aquí a tacharlo**.
>
> *Es exactamente lo que avisa la cabecera de este documento: una **medición** envejece a «vieja»
> y se defiende sola; una **instrucción** —«sin fundir», «no lo toques»— **envejece a mentira**, y
> la única forma de que no pase es escribirla con su condición de caducidad al lado. Ésta no la
> llevaba. Las tres casillas de hoy sí, y las tres se cerraron el día que les tocaba.*

**6 sep 2026 — LA REPARACIÓN DE LA HORA Y LA DEUDA DE `uniformes`, ESCRITAS Y **SIN FUNDIR**
· `database/migrations/2026_09_06_100000_reparar_la_hora_escrita_dos_veces.php`,
`2026_09_06_200000_crear_uniformes_donde_falte.php`,
`tests/Feature/RepararLaHoraEscritaDosVecesTest.php` (**11 casos**) · rama
`fix/reparar-la-hora-y-uniformes`, worktree `.worktrees/r` · pint PASS · larastan `[OK] No errors` ·
**suite entera: `Tests: 2050 passed (18366 assertions)`, 0 fallos, 1131 s**, corrida contra `e279f0b`
en `.worktrees/r` con `simonbolivar_testing_r` a 22/22 migraciones — *la cifra se lee de la línea
`Tests:` y no del código de salida, que en una tubería miente*

> **Encargo de Joseth: *«avanza a reparar en local, aún no desplegaré»*.** Están las dos
> migraciones que quedaban a deber, probadas, **y NO se funden**: cada una sería la octava —o la
> novena— de una tanda congelada en SIETE y ya ensayada. `main` sigue en siete.
>
> ### La de la hora: lo que repara, y lo que renuncia a reparar
>
> Repara las tres columnas medidas en la [§250](05-codigo-muerto-y-roto.md) —`change_asked.deleted_at`
> y `ausencias.created_at`/`updated_at` con `uploaded IS NOT NULL`— con
> `TIMESTAMP(DATE(col), MAKETIME(HOUR(col), SECOND(col), 0))`, que **devuelve hora y minuto** y
> pierde sólo los segundos.
>
> **Y excluye `SECOND(col) = 0` a propósito, que es lo que la hace segura de repetir.** Una fila ya
> reparada queda con los segundos en cero; sin esa exclusión, una segunda pasada sobre una fila
> como `21:21:21` la llevaría a **21:00:00**, esta vez sin arreglo. **El precio de la exclusión**:
> las filas cuyo minuto real era `00` se quedan sin reparar, porque son indistinguibles de una ya
> reparada. *Se prefiere no repararlas a arriesgarse a estropearlas.*
>
> ### Los once casos, y la mitad que importa es la que dice DÓNDE NO TOCA
>
> Ejecutan **las sentencias de la propia migración**, no una copia «equivalente» escrita en el test:
> la migración las expone en `sentencias()` justo para eso. Fijan que no se toca `fecha_hora` —la
> hora a la que llegó tarde el alumno—, ni las 52.157 ausencias del alta normal, ni las filas sanas,
> ni un `deleted_at` nulo; y que **sí** entra `uploaded = 'deleted'`, que el filtro estrecho perdía.
>
> **Uno de los once fija EL PRECIO por escrito**: una fila sana escrita en el minuto de su hora
> —21:21:35— **también se mueve**, a 21:35:00. Es el falso positivo de ~1 de cada 60 que Joseth
> aceptó. Está en verde a propósito: si algún día se decide no pagarlo, ese test se pone rojo y
> obliga a leer la cabecera.
>
> ### Vistos en ROJO, que es lo único que hace valer los verdes
>
> ```
> quitando `uploaded IS NOT NULL`   -> 1 rojo: repara ausencias que no subió el lector
> quitando `SECOND(col) <> 0`       -> 2 rojos: la segunda pasada vuelve a mover la fila
> ```
>
> *Y el primer intento de romper la segunda guardia **no llegó a aplicarse** y el test siguió verde:
> se leyó como «la guardia no hace falta» durante un minuto. Lo delató contar las apariciones que
> quedaban en el fichero. **Un rojo que no aparece hay que comprobar que se intentó de verdad.***
>
> ### La de `uniformes`: paga la deuda del 5 sep, y en los diecisiete es un no-op
>
> `Schema::hasTable()` delante. Su valor es **el colegio dieciocho** —uno nuevo se crea copiando la
> base de otro— y el día que una copia venga incompleta. Comprobado que **crea la tabla idéntica**:
> se copió la base de test, se le quitó `uniformes`, se migró, y `information_schema` da **22
> columnas, 4 renglones de índice y 3 claves ajenas iguales uno a uno** — incluida la collation
> `utf8mb4_general_ci` de `descripcion`, que no es la de la tabla y en los dieciséis es así.
>
> **Ninguna de las dos borra nada en `down()`**, y las dos lo dicen en su cabecera. La de la hora
> porque la vuelta atrás es aritméticamente posible pero **no se sabe qué filas se tocaron**: una
> fila sana de las 14:30:00 tiene la forma de una reparada, y desandar a ciegas la convertiría en
> 14:14:30. La de `uniformes` porque un `dropIfExists` se llevaría las faltas de dieciséis colegios
> que siempre tuvieron la tabla. **El camino de vuelta es la copia de seguridad, no el `down()`.**
>
> ### LO QUE FALTA, y no es código
>
> **La decisión de Joseth sobre si se reparan las filas**, que sigue abierta: reparar cuesta ese ~1
> de cada 60 —despreciable donde la columna salió al 100 %, unas 3 o 4 filas en `cads_itagui`, que
> salió 90 de 214—. Escribir la migración no la toma; la deja lista.

> ## EL ESPACIO DE TRABAJO, LIMPIADO — 5 sep 2026, 22:1x, y queda en CINCO árboles
>
> **Encargo de Joseth: *«limpia el espacio de trabajo, todo a origin main»*.** Lo que había era el
> sedimento de trece sesiones en paralelo: **catorce worktrees y veintitrés ramas**, y la mayoría
> ya estaba dentro de `main` sin que nadie lo hubiera recogido.
>
> **Antes de tocar nada se comprobó quién estaba vivo**, porque un worktree es el censo de quién
> puede pisarte: `ListAgents` daba **dos sesiones interactivas y las dos de otros repositorios**
> (`myvc-horarios-5d`, `myvc-front-83`), cero procesos de `phpunit` o `artisan`, y **ningún fichero
> tocado en los últimos sesenta minutos** dentro de `.worktrees/`. Con eso, ninguno de los árboles
> retirados era de nadie.
>
> | | antes | después |
> |---|---|---|
> | worktrees | 14 | **5** |
> | ramas locales | 23 | **6** (`main` + cinco sin fundir) |
> | refs de seguimiento huérfanos | 1 (`local/main`) | **0** |
> | bases `simonbolivar*` | 20 (~450 MB) | **7** (~264 MB) |
> | ramas en `origin` | 2 | **1** (`main`) |
>
> **Se retiraron nueve worktrees y se borraron diecisiete ramas, todas fundidas en `main`** — cada
> una comprobada con `git branch --merged main`, y `git worktree remove` **sin `--force`**, que se
> niega si el árbol tiene algo sin commitear. Ninguno lo tenía. Y **el ref `refs/remotes/local/main`
> era basura de verdad**: apuntaba a un commit **211 por detrás y con cero propios**, y el remoto
> `local` ya no está configurado — un `git remote -v` sólo devuelve `origin`.
>
> ### Los cinco que se quedan, y ninguno es un resto
>
> ```
> .worktrees/a    fix/columnas-en-los-modelos-no-borra    1 propio ·  87 atrás
> .worktrees/b    docs/despliegue-remedido                3 propios · 83 atrás
> .worktrees/d5b  docs/appkey-compartida-fortul-lal       5 propios · 89 atrás
> .worktrees/f    fix/frases-asignatura-text              3 propios · 102 atrás   <- CONGELADA hasta el día 10
> .worktrees/h    feat/calendario                         2 propios · 212 atrás   <- CONGELADA hasta el día 10
> ```
>
> **Las dos congeladas siguen congeladas**: la instrucción de las 13:4x —*no fundir hasta que el
> despliegue del día 10 esté hecho, porque cada una añade una octava migración a una tanda ensayada
> sobre siete*— **no la deroga una limpieza**. Se han conservado con su árbol y su rama intactos, que
> es justo lo contrario de limpiarlas.
>
> ### La que sí se fundió, y por qué ésa y no las otras
>
> **`docs/despliegue-remedido-2`**, que estaba en el censo de abajo como cola. **No traía un número
> viejo: traía un criterio que `main` decía mal.** El aviso O afirmaba que *«DOS de `horario/` no
> bastan con `auth.personal`»* y son **TRES** — se le pasaba `PUT horario/docentes/{profesor_id}/tono`,
> **que ya estaba nombrada veinte líneas más abajo del mismo documento con ese mismo criterio**.
> Verificado contra el código de hoy antes de fundir: `HorarioController` tiene **tres** llamadas a
> `Autoriza::puedePublicarHorario` y ni una más (`getProyecto`, `putOficial`, `putTonoDocente`).
> Docs sola, cero migraciones: **no toca la tanda de siete**.
>
> *Y de las seis del censo de abajo ya sólo quedan cinco por otra vía: `docs/barrido-profesor-serializado`
> entró en `main` a las 14:04 y aquí se borró como fundida.*
>
> ### Las bases de test, también — de VEINTE a SIETE
>
> **Esto estuvo escrito veinte minutos como «no se pudo hacer»**, porque Docker se lo había llevado
> el reinicio de la máquina. Volvió, y **la frase se corrige en vez de quedarse**: una casilla que
> dice «pendiente» sobre algo hecho manda a trabajar dos veces.
>
> Se borraron **doce bases**: las de los nueve árboles retirados (`_b1 _b2 _b3 _d5 _e _est _f0 _p`)
> y cuatro que ya estaban huérfanas de antes (`_9c _ac _ff _g`), a ~11 MB cada una. Y
> **`simonbolivar_ensayo`, 211 MB**, con el gesto que trae el propio script
> —`tools/ensayo-de-la-tanda.sh --solo-limpiar`—, porque **deja la copia en pie a propósito** si no
> se le pide lo contrario: no era un olvido de nadie. **En total ~343 MB.**
>
> **Las doce se nombraron una a una en un fichero de órdenes que se leyó antes de correrlo**, sin
> comodines, y con la comprobación de que ninguna tocaba las siete que se quedan. Quedan
> `simonbolivar` (desarrollo), `simonbolivar_testing` (la de `main`) y las cinco de los árboles
> vivos. Todas se rehacen con `tools/construir-bd-test.sh`.
>
> > **Y una trampa del propio borrado, que este repositorio ya tiene escrita:** la tubería terminó
> > en `grep -v 'Using a password'` y el `$?` que se imprimió fue **`1`** — el del `grep`, que no
> > encontró nada que imprimir, **no el del `mysql`**. *El exit code de una tubería miente*, y lo
> > que dice si el borrado salió fue **volver a listar las bases**, no el número de salida.
>
> `simonbolivar_testing_h` sigue **rota** (95 tablas de 102) y **se queda rota a propósito**: es la
> del árbol de `feat/calendario`, que sigue vivo, y rehacerla es de quien lo retome.
>
> ### Y de paso, la medición que faltaba
>
> Con Docker en pie se contó el router: **`route:list --json` da 578 en el árbol principal sobre
> `c0ebcbb`**, idéntico a lo que había dado restar los dos `rutas.json` con Docker caído. **Las dos
> vías coinciden**, que es lo único que convierte una en control de la otra.
>
> **Y nada de lo borrado es irrecuperable**: las diecisiete ramas están dentro de `main` y el reflog
> sigue entero, así que cualquiera vuelve con `git branch <nombre> <hash>` — los hashes están en el
> mensaje del commit de esta limpieza.

> ## LAS SEIS RAMAS VIEJAS, ABIERTAS UNA A UNA — 5 sep 2026, 08:0x
>
> **Nadie las había mirado nunca, y el censo no lo puede contestar: hay que abrirlas.** Encargo
> de `8myvc-25`, **de lectura: no se fundió ninguna**. La pregunta era *«¿cola o resto?»*, y
> **el resultado es que no hay ni un resto**: las seis traen trabajo propio que no está en
> `main`, incluida la que más lo parecía.
>
> | rama | propios · retraso | ¿funde limpio? | qué trae que `main` NO tiene | veredicto |
> |---|---|---|---|---|
> | `fix/frases-asignatura-text` | 3 · 57 | **sí, limpio** | la migración de `frases_asignatura.frase` a `text` y su test | **cola, y la única barata** |
> | `feat/calendario` | 2 · 167 | no (3 ficheros) | **864 líneas** de controlador, **dos rutas** (`calendario/mes`, `calendario/proximos`), una migración, 2 tests y 5 snapshots | **cola cara — y es la que más parecía resto** |
> | `docs/despliegue-remedido` | 3 · 38 | no (2 ficheros) | el rango sin desplegar remedido y cuatro avisos que faltaban | **cola, pero su número ya caducó** |
> | `docs/barrido-profesor-serializado` | 8 · 38 | no (1 fichero) | dos documentos nuevos y **el arreglo de `tools/filas-enteras-al-cliente.php`** | **cola — la conclusión llegó a `main`, la herramienta no** |
> | `docs/appkey-compartida-fortul-lal` | 5 · 44 | no (1 fichero) | 311 líneas sobre el `29`, más `05` y `DESPLIEGUE-REFERENCIA` | **cola, y es material del día 10** |
> | `fix/columnas-en-los-modelos-no-borra` | 1 · 42 | no (2 ficheros) | 425 líneas: la herramienta **mueve** las anotaciones a mano en vez de borrarlas | **cola** |
>
> ### 1. `fix/frases-asignatura-text` — barata de fundir y cara el día 10
>
> **Es la única que funde limpia** y toca dos ficheros. Y **sigue haciendo falta**: comprobado
> en la base de desarrollo, `frases_asignatura.frase` sigue siendo `varchar(255)`.
>
> **Pero su precio no está en la fusión, está en la tanda.** Hoy son **cinco ficheros de
> migración** sin desplegar en `main`, que con las dos de `feat/plantilla-de-notas` hacen las
> **siete** sobre las que se ensayó la tanda anoche. Ésta sería **la octava**, y
> **el ensayo de anoche no la midió**. *No es «una migración más»: es reensayar la tanda antes
> del día 10.* **Decisión de Joseth, y va con ese precio delante.**
>
> ### 2. `feat/calendario` — 167 de retraso y ni un gramo de resto
>
> **La que más olía a resto es la que más trabajo vivo tiene.** `main` tiene un
> `CalendarioController` de **221 líneas**; el de la rama tiene **1.034**, y **ninguna de sus
> dos rutas nuevas existe en `main`**. Trae además su propia migración —o sea que también
> mueve la tanda— y cinco snapshots.
>
> **El retraso no la invalida, lo que hace es poner el precio:** conflictos en
> `ChangeAskedController.php` —que se ha reescrito mucho desde el 1 sep—, en el test de
> familias y en esta casilla; rebase de verdad, no un `merge -X`; **los tres snapshots que
> mueve una ruta nueva** y el contador de `CLAUDE.md`. *Un `git merge` a ciegas aquí es cómo se
> pierde el trabajo de otro, no cómo se recupera.*
>
> ### 3. `docs/despliegue-remedido` — el caso que enseña por qué no se funde un número
>
> ```
> main dice hoy                          191 commits sin desplegar
> la rama remidió el 4 sep               232
> contados hoy (git rev-list 9474b50..main)  274
> ```
>
> **Fundirla arregla `main` y mete un número que ya no es cierto.** Y el propio `DESPLIEGUE.md`
> tiene la regla que lo resuelve —*un rango sin desplegar se remide entero cuando se le toca*—,
> así que **lo que hay que llevarse de esta rama no es el 232: son los cuatro avisos que
> faltaban y el hallazgo del aviso R** (eran **trece** respuestas y no seis), que no caducan.
> El número se recuenta el día que se funda. *Hoy son 274 commits, 55 ficheros de `app/`, 7 de
> `routes/` y 5 migraciones.*
>
> ### 4. `docs/barrido-profesor-serializado` — la conclusión llegó y la herramienta no
>
> **El hallazgo ya está en `main`**: «trece respuestas vivas» sale cuatro veces en esta misma
> casilla. **Lo que no llegó es con qué se midió**: `tools/filas-enteras-al-cliente.php` está en
> `main` **234 líneas por detrás**, o sea en la versión que —según su propio commit— *«contestaba
> con la cara de haber mirado»* y daba **1 donde el barrido daba 13**. Tampoco están sus dos
> documentos nuevos.
>
> > **Y esto es exactamente el caso contra el que avisa `CLAUDE.md`**: *el primer sitio donde
> > mirar cuando el número sale raro es el detector*. Hoy `main` tiene el número bueno y el
> > detector malo, así que **quien lo vuelva a correr para comprobarlo obtendrá el `1` y creerá
> > que el trece estaba mal.**
>
> ### 5 y 6, en corto
>
> - **`docs/appkey-compartida-fortul-lal`**: sus dos documentos nuevos **ya están en `main`**,
>   pero en versiones anteriores —311 líneas de diferencia sobre `29-los-env-no-son-uniformes`—.
>   Trae la clave compartida entre `fortul` y `lal`, el correo caído en los dieciséis, el
>   `APP_DEBUG=true` de cinco y el CORS. **Es material del día 10**, no documentación de fondo.
> - **`fix/columnas-en-los-modelos-no-borra`**: un commit, 425 líneas. La herramienta que
>   regenera las `@property` **borraba** las anotaciones escritas a mano; la rama las **mueve**.
>   `main` tiene todavía la que borra.
>
> ### Lo que este censo NO contesta
>
> **Si el contenido de cada una sigue siendo correcto.** Se midió qué traen y si `main` ya lo
> tiene, **no** si lo que afirman sigue siendo verdad contra el código de hoy — y dos de ellas
> son documentos llenos de cifras con 38 y 44 commits de retraso encima. **Se abrieron, no se
> auditaron.** Y ninguna se corrió: los tests de `feat/calendario` no se han ejecutado contra
> `main` de hoy.

> ## EL CRUCE QUE `8myvc-29` DEJÓ ESCRITO Y NO LLEGÓ A MEDIR — CERRADO EN VERDE, 5 sep 2026
>
> **Su fusión de la plantilla (`f0f72eb`) dice, con todas las letras, que el verde de
> `8myvc-ac` describe LA RAMA SOLA y que «el cruce se mide en el commit siguiente».** Ese
> commit no existió: Joseth cerró su sesión antes. **Así que la suite entera nunca se había
> corrido sobre `main` fundido**, con las dos migraciones nuevas dentro y los siete commits
> que la rama no tenía.
>
> Corrida sobre `0167eaa` en árbol y base propios (`simonbolivar_testing_est`, reconstruida a
> **20/20** migraciones):
>
> ```
> Tests: 2012 passed (18033 assertions)      0 fallos
> Duration: 1348.09s                          git status --porcelain -> vacío
> ```
>
> **La cifra se leyó de la línea `Tests:`, no del código de salida**, y el `git status` de
> después es la mitad que importa: **ninguna instantánea se movió.**
>
> #### Contra qué hash corrió exactamente, porque ya se leyó mal una vez
>
> **Sobre `61cc721`** —`main` en `0167eaa` más los commits de documentación de esta rama—, a
> las **14:00**. `docs/barrido-profesor-serializado` entró en `main` a las **14:04**
> (`fca1802`), o sea **cuatro minutos después: la suite NO lo tenía dentro.**
>
> `8myvc-ac` dedujo lo contrario —*«`fca1802` es ancestro de `2cf2794`, así que estaba
> dentro»*— y **la deducción es falsa**: que un commit sea ancestro de la fusión final no dice
> nada sobre qué había en el árbol cuando se corrió la suite. **Ser ancestro es una relación
> del grafo; estar dentro cuando se midió es una relación con el reloj**, y la segunda no se
> deduce de la primera.
>
> **Lo que sí salva la conclusión es su OTRA comprobación**, que es la buena:
> `git diff --stat 0167eaa 2cf2794 -- tests/ app/ database/ routes/` sale **vacío**. Lo que
> entró en medio son documentos y `tools/filas-enteras-al-cliente.php`, y `tools/` **no lo
> ejecuta ninguna suite** — su único test, `AutopruebasDeLasHerramientasTest` (14 casos), lo
> corrió `8myvc-ff` sobre el árbol ya fusionado. **Así que no hay hueco de cobertura, pero no
> por la razón que se dio primero.**
>
> ### Y no salió limpio por suerte — el porqué está medido
>
> El miedo era concreto y con precedente: el 2 sep, `nivelaciones_columnas` movió **siete**
> instantáneas sin que nadie tocara su método, y dos de esos sitios no estaban en la lista de
> ficheros de ninguna sesión. Aquí no pasó, y la razón es que **la población no se toca**:
>
> | | |
> |---|---|
> | instantáneas que nombran `unidades_por_defecto` | **ninguna** — así que no hay dónde aparecer |
> | `SELECT *` vivos cerca | **dos**, y los dos sobre `subunidades_por_defecto`, **otra tabla** (`UnidadesController:188`, `YearsController:317`). El tercer resultado del `grep` es un **comentario** que cuenta que allí hubo uno |
> | tests de contrato que sí recorren esos caminos | **seis**, y los seis en verde |
>
> **Y la comprobación que lo remata: el total es idéntico al de la rama sola** —2012 y 18033
> las dos veces—, o sea que fundir **no estrenó ni un test ni una aserción**. Si las columnas
> se hubieran repartido a alguna respuesta, ese número habría cambiado aunque todo siguiera en
> verde.
>
> *Lo que esto NO dice: que la tanda sea inocua en un colegio real. Es la base de test con el
> seed, no una copia de producción — eso lo mide `tools/ensayo-de-la-tanda.sh`, y ya está hecho
> aparte: 7 de 7 en 932 ms sobre 1.166.139 notas.*

> ## DECISIÓN DE JOSETH, 5 sep 2026: EL PERMISO DE LA PLANTILLA **NO SE REPARTE**
>
> `can_edit_plantilla_notas` **nace repartido a nadie y así se queda**. Cada colegio se lo da a
> quien quiera desde su pantalla de roles: **es una fila, no una migración**, porque el criterio
> es `Autoriza::puedeEditarPlantillaNotas` y no `esSuperusuario`.
>
> ### La primera respuesta fue otra, y lo que la cambió fue leer el código
>
> La decisión inicial fue **repartirlo a los superusuarios**. Al ir a ejecutarla se comprobó
> `Autoriza.php:386` y resultó que **el superusuario ya pasa por la primera rama, sin el
> permiso**:
>
> ```php
> if (self::esSuperusuario($user)) { return true; }
> ```
>
> O sea que esa migración **habría costado la octava de una tanda congelada en siete —con su
> reensayo— y no habría cambiado el comportamiento de nadie.** Con eso encima de la mesa,
> Joseth la retiró. *No se re-litigó una decisión suya: se le devolvió con un dato que no
> estaba cuando la tomó.*
>
> ### Lo que hay que avisarle al front, y es lo que de verdad muerde
>
> **Las nueve rutas de `plantilla-notas/` —la de LEER incluida— exigen ese permiso.** Así que
> el día del despliegue esa pantalla es **sólo de superusuarios en los dieciséis colegios**, y
> **el menú no se publica hasta que el colegio reparta el permiso**: si se publica antes, a
> rectoría le sale la entrada y le da **403**. Lo midió `8myvc-ac` restando claves sobre los
> **125 snapshots** del rango, y por eso el aviso O pasa de 26 rutas a **35**.

> ## DECISIÓN DE JOSETH, 5 sep 2026 13:4x: LA TANDA DEL DÍA 10 SE QUEDA EN **SIETE**
>
> **No entra ninguna migración más antes del despliegue.** Lo que se despliega el día 10 es
> exactamente lo que se ensayó anoche, y el argumento fue ése: *un ensayo vale para la tanda que
> midió*. Quedan cinco días, y gastarlos en reensayar para meter dos arreglos que pueden esperar
> es cambiar lo medido por lo nuevo justo antes de tocar dieciséis colegios.
>
> ### La consecuencia que NO estaba en la pregunta, y hay que leerla
>
> **«Siete» obliga a fundir `feat/plantilla-de-notas`.** Contadas hoy:
>
> ```
> en `main` desde 9474b50   5   retirar_boletin · puestos · nivelaciones · rubricas · horario_versiones
> feat/plantilla-de-notas   2   alcance_de_la_plantilla · create_permiso_can_edit_plantilla_notas
>                          ───
>                           7   <- las que ensayó `tools/ensayo-de-la-tanda.sh` anoche
> ```
>
> **Si la plantilla no entra, la tanda es de CINCO y el ensayo tampoco la describe.** O sea que
> «congelar» no es «no tocar nada»: es **fundir la plantilla y parar ahí**. Hoy funde con
> conflicto en dos ficheros —esta casilla y `tools/ensayo-de-la-tanda.sh`—, ninguno de código.
>
> ### Las dos que se quedan fuera, y esto es una INSTRUCCIÓN con fecha de caducidad
>
> > **NO FUNDIR EN `main` HASTA QUE EL DESPLIEGUE DEL DÍA 10 ESTÉ HECHO.** Las dos siguen
> > vivas, probadas y con trabajo bueno dentro; lo único que las frena es que **cada una añade
> > una migración a una tanda ya ensayada**.
> >
> > - `fix/frases-asignatura-text` — la columna sigue en `varchar(255)`, comprobado.
> > - `feat/calendario` — dos rutas nuevas, y además pediría rebase de verdad.
> >
> > **Caduca sola:** en cuanto el despliegue del 10 esté hecho, esta instrucción no dice nada y
> > las dos vuelven a ser cola normal. *Va escrita con su condición al lado a propósito — una
> > instrucción no envejece a «hecho», envejece a mentira, y este documento ya lo pagó una vez
> > esta mañana.*

> ## LO QUE ESPERA UNA DECISIÓN, Y ES LO PRIMERO QUE SE PIERDE EN UN APAGÓN
>
> **Ninguna de las dos que quedan la puede resolver una sesión midiendo**, que es exactamente por qué
> están aquí arriba y no dentro de una casilla fechada donde hay que ir a buscarlas.
>
> > **A 5 sep 2026 por la tarde queda UNA abierta, la 4, y nació de contestar la 1.** La 1 la
> > contestó Joseth; la 2 ya estaba contestada; y la 3 **estaba HECHA y fundida mientras este bloque
> > la daba por sin empezar** — se deja tachada y no borrada porque es un hallazgo sobre este propio
> > documento, no sobre el código.
>
> ### 1. ~~`SELECT VERSION();` en un colegio~~ **CONTESTADA el 5 sep 2026: `10.5.25-MariaDB-cll-lve`, y lo mismo en los dos shared hostings**
>
> **Producción corre MariaDB 10.5, no MySQL 8 como el docker.** Lo que cambia: MariaDB añade
> columnas al instante desde la 10.4 también con `AFTER`, y las 90 tablas son InnoDB sin compresión,
> así que la reconstrucción de `notas` que temía la medición **no ocurre** y el plan del día 10 no se
> mueve. Lo que queda sin número: la tanda nunca se ha ensayado sobre MariaDB. Está desglosado en
> [DESPLIEGUE.md](../DESPLIEGUE.md), bloque «PRODUCCIÓN CORRE MARIADB 10.5.25».
>
> **Nadie de aquí puede correrla**: hay que entrar a un cPanel. El detalle y la medición están
> en la casilla del 4 sep («LA TANDA ENSAYADA SOBRE UNA BASE CON DATOS»), y el resumen es que
> la misma migración sobre `notas` cuesta **11,8 ms bajo MySQL 8** y **4.870 ms bajo 5.7**, con
> la tabla bloqueada y escalando con las filas. Por diecisiete colegios, eso **deja de ser un
> detalle y pasa a ser el plan**. *Es lo más barato que se puede hacer antes del día 10 y sigue
> sin hacerse.*
>
> ### 2. ~~¿Entra `fix/frases-asignatura-text` antes del día 10?~~ **CONTESTADA: no** — ver el bloque de arriba
>
> La rama funde limpia y **hace falta**: `frases_asignatura.frase` sigue siendo `varchar(255)`.
> Pero sería **la octava migración** de una tanda que anoche se ensayó sobre **siete**, así que
> entrar no cuesta la fusión: cuesta **volver a correr el ensayo sobre una copia con datos**.
> Y `feat/calendario` trae otra, o sea que la pregunta real es *cuántas migraciones entran
> antes del día 10*, no si entra ésta. **Las dos salidas son legítimas y ninguna es gratis:**
> dejarlas fuera congela dos arreglos ya escritos y probados; meterlas obliga a reensayar.
>
> ### 4. ~~El motor de cada tabla en los dos hostings~~ **CONTESTADA el 5 sep 2026: 18 bases, todas InnoDB** — y abrió la 5
>
> Salió de su propia pregunta al contestar la 1: *«¿es posible que algunas de mis bases sean muy
> viejas y tengan tablas no InnoDB?»*. Joseth corrió las dos consultas en `micolev1` esa misma tarde:
> **InnoDB en las 18 bases y cero filas en la que busca las que rompen**, con la población delante.
> Las diecisiete del bucle están en esa cuenta —`lal` ya vive como `micolev1_lal_db`—, así que el
> errno 150 por MyISAM queda descartado para lo que se despliega. Detalle en
> [DESPLIEGUE.md](../DESPLIEGUE.md), bloque «el motor de cada tabla».
>
> ### 5. ~~Las bases NO tienen las mismas tablas: de 87 a 94~~ **CONTESTADA: las once que altera la tanda están en las diecisiete** — y abrió la 6
>
> **Lo trajo la consulta de la 4 sin buscarlo.** Un colegio en `9474b50` debe tener **94** tablas
> (docker con la tanda pendiente: 102, menos las 8 que crea); las tienen `demo` y
> `simonbolivar_medellin`, y **los otros quince tienen entre 87 y 93**. Joseth corrió las dos
> consultas esa tarde: **`11` de once en las diecisiete bases**, así que la tanda no se encuentra
> ninguna tabla que falte; y las siete que faltan en alguna base son `df_notas_finales` (muerta),
> las cinco `piars_*` y `uniformes`. El censo del código que pidió a continuación —*«qué tablas se
> usan»*— está en [05 §246](05-codigo-muerto-y-roto.md): **14 de 102 no las lee nadie**, dos de
> ellas sin censar hasta hoy (`agrupacion_puestos` y su detalle). Borrarlas es una migración: **después
> del día 10**, y con las filas de los dieciséis contadas delante.
>
> ### 6. ~~`amiguitosdejesus` no tiene la tabla `uniformes`~~ **DECIDIDA por Joseth el 5 sep 2026: se crea a mano, y queda una migración pendiente**
>
> > **APLICADA el 5 sep 2026 a las 22:4x, y salió limpia.** Sobre `micolev1_amiguitosdejesus`,
> > motor `10.5.25-MariaDB-cll-lve`: el paso 1 dio OK —la tabla no estaba, los tres destinos InnoDB
> > y sus `id` en `INT UNSIGNED`— y el paso 3, **22 columnas, 3 claves ajenas, 0 filas, InnoDB**.
> > Ese colegio pasa de 87 a 88 tablas. **Falta la comprobación que de verdad vale**, que no es de
> > esquema: entrar a la aplicación de ese colegio con una cuenta de alumno y ver el panel de inicio
> > donde antes había un 500.
> >
> > **Salida elegida: (c), crearla desde phpMyAdmin**, con el script
> > [`tools/crear-uniformes-donde-falta.sql`](../../tools/crear-uniformes-donde-falta.sql). La (a)
> > —una migración con `hasTable()`— era la ortodoxa y **la frena una fecha**: la tanda del día 10
> > está congelada en siete y ya se ensayó sobre esas siete, así que una octava obliga a repetir el
> > ensayo entero antes de tocar dieciséis colegios.
> >
> > **LO QUE ESTO DEJA DEBIENDO, y es la mitad de la decisión:** *después* del día 10, esa migración
> > con `Schema::hasTable('uniformes')` **hay que escribirla igual**. En este colegio será un no-op
> > y en cualquier otro al que le falte la creará. Sin ella, el repositorio deja de ser la fuente de
> > la verdad del esquema, que es justo lo que `CLAUDE.md` protege con *«migración o no existe»*.
> > *Esta línea es la deuda: si se borra sin escribir la migración, nadie la va a echar de menos.*
>
> **El DDL no está escrito a mano**: sale literal de `database/schema/mysql-schema.sql`, y se
> comprobó que **ninguna migración del repositorio ha tocado `uniformes` nunca**, así que el volcado
> la describe entera. El script lleva delante sus comprobaciones y **se corrió en los dos motores
> antes de entregarlo** —MySQL 8.0.42 y **MariaDB 10.5.29 en un contenedor levantado para esto**,
> que es la familia del motor de producción—, con **los tres guards vistos abortar**. Y lo que de
> verdad protege, medido: **ignorando el veredicto contra un destino MyISAM, la sentencia falla
> entera y la tabla no queda a medias**. El peor caso del script es que no haga nada.
>
> *Y de camino salió una trampa que iba a costar un susto: `SHOW CREATE TABLE` dibuja la columna
> `materia` con `CHARACTER SET` explícito donde el otro colegio no lo dibuja, y **no es una
> diferencia** — charset y collation son los mismos en `information_schema`. Comparar el texto del
> `SHOW CREATE` entre dos colegios es comparar el dibujo, no la tabla.*
>
> **Lo que NO entra**: las otras seis que le faltan a ese colegio. `df_notas_finales` está muerta
> ([05 §246](05-codigo-muerto-y-roto.md)) y las cinco `piars_*` son el módulo PIAR, que sólo se
> despliega donde se usa. Ninguna de las seis rompe una pantalla de todos los días, que es lo que
> distinguía a `uniformes`.
>
> **El estado de partida, que es lo que hacía falta arreglar:**
>
> Sale de la 5: es el colegio de 87 tablas y le faltan las siete. Cinco ficheros consultan
> `uniformes`, y tres son de todos los días —el panel de inicio de alumno (`ChangeAskedController:258`)
> y de profesor con grupo (`:404`), la planilla (`NotasController:1222`) y disciplina
> (`DisciplinaController:306`)—: **allí contestan 500 hoy**. Lo que no está medido es si alguien
> entra: es un preescolar y puede no tener un alumno con cuenta. Las salidas, con su precio:
> **(a)** una migración con `hasTable()` que la cree donde falte — la octava de una tanda congelada
> en siete, o sea después del día 10; **(b)** nada, si el colegio no usa esas pantallas; **(c)** a
> mano en phpMyAdmin, que es lo que `CLAUDE.md` prohíbe. **No es del día 10**, y no la decide una
> sesión.
>
> ### 3. ~~El Lote G: ensanchar `GET horario/versiones/{id}/lecciones` con las cuatro listas~~ **HECHA y fundida en `abcd23a` — y este bloque decía lo contrario**
>
> **Comprobado el 5 sep 2026 por la tarde contra `main` en `d606839`**: `HorarioController` lleva
> las cuatro listas, `feat/horario-cuatro-listas` sale en `--merged main` y la casilla «LO SIGUIENTE»
> del sobre ensanchado, más abajo, la da por fundida detrás de la plantilla. Este bloque se escribió
> a las 07:4x con la ruta contestando `sin_catalogo` y **nadie lo movió al fundir**: la lista de
> decisiones abiertas y la casilla del trabajo se contradecían dentro del mismo fichero, y de las dos
> se lee primero ésta. *Lo de abajo se conserva porque describe bien el estado desde el que se partió.*
>
> Es la decisión 38 de `myvc_horarios`. **Ya no es una hipótesis:** el 5 sep se midió que este
> servidor **sí guarda** las disponibilidades declaradas —dentro de
> `horario_versiones.proyecto`, 47 docentes con la clave y 26 con marcas de verdad— y que dos
> cadenas afirmaban lo contrario (`ac09cb7`). **Eso cambia el trabajo que se pide**: no es
> inventar un dato, es parsear un blob que ya está.
>
> **El trabajo NO está hecho, y el censo de ramas no sirve para saberlo.** Hay una rama
> reservada —`feat/horario-cuatro-listas`, con su worktree en `.worktrees/g`— que apuntaba al
> mismo commit que `main` cuando la miré, así que **en cuanto `main` avanzó dejó de salir en
> `--no-merged` sin que nadie escribiera una línea**. Una rama reservada y una rama terminada
> **se ven igual desde fuera**: lo que dice si el Lote G está hecho **es la respuesta de
> la ruta, no la lista de ramas**. Comprobado el 5 sep a las 07:4x sobre la versión oficial 8:
>
> ```
> GET horario/versiones/8/lecciones -> 200
>   catalogos.disponibilidad = {"estado":"sin_catalogo",
>                               "motivo":"está guardada dentro del fichero de proyecto,
>                                         no en una tabla: esta ruta todavía no la parsea"}
>   el sobre sigue con cinco claves: version · ejes · catalogos · lecciones · total_lecciones
> ```
>
> *Y ese `motivo` ya es el bueno —dice que el dato **está** y que falta parsearlo—, que es
> justo lo que arregló `ac09cb7`. O sea que el trabajo pendiente está bien descrito y sin
> empezar.*

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

> ## LA DERIVA QUE ESTUVO PUESTA A PROPÓSITO — YA NO ESTÁ, Y SE REPONE EN UNA LÍNEA
>
> **Estado a 5 sep 2026, 05:4x: `DESCUADRADAS 0`, exit 0, año 8 regido por la versión 8.**
> Si mides ahora y te sale `0`, **ese cero no prueba nada** — es el estado que la marca existía
> para desmentir.
>
> ### Lo que se midió, que es lo que hay que conservar
>
> El **4 sep 2026 a las 21:40** se apagó a mano el martes de `asignaturas.id = 1234`
> (`PUT asignaturas/toggle-dia`, `profesor_id 9`, MATEMÁTICAS de Tercero), con la versión 7
> oficial dándole los días 1 y 2. `tools/deriva-del-horario.php --year=8` contestó
> **`133 de 134 · DESCUADRADAS 1 · exit 1`**, y con `--detalle` nombró la asignatura y el día.
>
> **Ése es el único número de todo el módulo que no podía salir por accidente.** Los ceros no
> demuestran nada —recién publicada una versión, cuadrar es aritmética—, así que el `1` es lo que
> prueba que **el detector detecta lo que dice su nombre**. Lo midió `8myvc-84` con las escrituras
> autorizadas por Joseth en su canal, y lo reprodujo `8myvc-7c`.
>
> **Y el radio no era de laboratorio:** en martes la versión oficial le daba al profesor 9 **cinco**
> asignaciones y `asignaturas` le daba **cuatro**. Una clase desaparecida de su portada, **sin error
> y sin aviso**.
>
> ### Por qué ya no está, y por qué eso también estuvo decidido
>
> El **5 sep de madrugada** `myvc-horarios-16` publicó la **versión 8** —la primera con una pieza de
> varios grupos, una misa que junta once— y `putOficial` **reescribe las siete columnas de día de
> todo el alcance del año**. La 8 coloca la 1234 en martes en dos casillas, así que `martes` volvió
> a `1` y la deriva desapareció.
>
> **No fue un descuido: se paró el `PUT` a medias para preguntarlo otra vez.** La autorización que
> Joseth había dado decía *«reescribe las columnas de día del año 8»* —cierto y genérico—, no
> *«borra la marca que decidiste dejar puesta hace unas horas»*. Con la marca nombrada eligió
> **«publica: la marca es repetible»**. *Dos decisiones suyas separadas por horas y tomadas en
> repositorios distintos, y la segunda no sabía de la primera.*
>
> ### Reponerla, si alguien vuelve a necesitar el `1`
>
> ```sql
> UPDATE asignaturas SET martes = 0 WHERE id = 1234;   -- y deriva vuelve a 1
> ```
>
> **Comprobar antes que la versión oficial le sigue dando el martes a la 1234**: sobre una versión
> que no la coloque ahí, ese `UPDATE` no descuadra nada y el `0` volvería a no significar nada.
>
> > **Y este bloque está reescrito y no borrado a propósito.** Mientras la marca estuvo puesta, esta
> > casilla decía **«NO LA ARREGLES»**; en cuanto se publicó la 8, esa frase pasó a ser **el
> > documento que hace que un `0` se lea mal** — que es exactamente el daño que venía a evitar.
> > *Un aviso sobre un estado tiene la fecha de caducidad del estado*, y el que avisa de una
> > excepción **envejece hacia el peligro**: de proteger a engañar, sin que nada se ponga rojo.
>
> *En los dieciséis colegios esto no existe: cero versiones desplegadas.*

**6 sep 2026 — LA ENTRADA DE LA APP DE ESCRITORIO: entra hoy, y hay una cuenta atrás
que nadie ve** · `tools/cors-de-los-colegios.sh` (nuevo), `tests/Contrato/CorsDelEscritorioTest.php`
(nuevo, **9 casos / 14 aserciones**), `.env.example`, [29 §5](29-los-env-no-son-uniformes.md),
[**32**](32-la-entrada-de-la-app-de-escritorio.md) (nuevo). **Cero rutas nuevas, cero guards nuevos,
cero cambios de contrato** — el router sigue en **578**.

La pregunta era *«¿cómo consigue el token `myvc_horarios`?»* y la premisa estaba medio
equivocada: **ya lo consigue**, por `POST login/credentials`, con su razón escrita en aquel
repositorio. Lo que no se había mirado nunca es **desde este lado**, y ahí sí había cosas.

- **Ningún filtro por cliente ni por versión le afecta.** `APP_MOVIL_VERSION_MINIMA` **no es
  un middleware**: es un campo que se adjunta a la respuesta y que decide la app. La trampa
  dormida es la contraria: ese número es el **`versionCode` de Flutter**, así que un cliente
  que lo respetara **se bloquearía con un número que no habla de él** — y `login/credentials`,
  la ruta del escritorio, **ya se lo manda hoy** (`LoginController.php:105`). Está dormido
  porque hacen falta dos cosas y no se da ninguna: ningún colegio tiene número puesto y el
  escritorio no lee el campo. **La mitad barata es la peligrosa**: escribir el número es una
  línea en un `.env`, sin despliegue ni revisión. Escrito en [32 §4.3](32-la-entrada-de-la-app-de-escritorio.md)
  con qué lo despertaría, y avisado en `.env.example`, que es donde se va a leer.

> **6 sep 2026, añadido después — la escalera del horario ya se puede probar entera.** El rol
> `Coord académico` tenía **cero usuarios** en `simonbolivar`, así que la mitad de arriba de esa
> tabla estaba escrita **desde el código y no desde una respuesta**. Se creó
> `coord.academico.prueba` (`users.id = 2449`, clave `test-1234`, **no superusuario**) **en la
> base de desarrollo local** y se ejercitó por HTTP: **200 en listar, mirar, descargar, publicar
> y tono, y 403 en subir**. La asimetría de la decisión 10 —*el coordinador publica y no sube;
> secretaría sube y no publica*— **está vista funcionar, no deducida**. Ficha completa y las dos
> escrituras repuestas en [32 §2.1](32-la-entrada-de-la-app-de-escritorio.md). **En los dieciséis
> colegios el rol tiene cero usuarios y eso no lo cambia esto** — es un dato del sistema, y desde
> el 7 sep tampoco es una tarea: ver el renglón siguiente.
>
> **7 sep 2026 — Y una corrección que va antes que nada, porque está suelta en `git log`.** Los
> mensajes de `9032c10` y de la fusión `e9af70f` afirman que comparar la columna contra
> `CONVERT(UNHEX(…) USING utf8mb4)` *«revienta en MariaDB y funciona en MySQL 8»*, y el segundo
> lo amplía a *«aplica a cualquier receta futura»*. **Es falso: revienta en los dos.** Se
> compararon dos sentencias distintas —una con `COLLATE` explícito y otra sin él— y **nunca se
> corrió la misma en los dos motores**; lo hizo creíble que **el mensaje de error nombra una
> collation distinta en cada uno**, porque es la suya por defecto, así que *parece* específico
> del motor. Los mensajes no se reescriben —historia fundida— y la corrección vive con sus
> hashes en **[33-la-tilde-que-sql-no-ve.md](33-la-tilde-que-sql-no-ve.md)**, donde también está
> lo que **sí** sobrevive y no depende del motor: `roles.name` es insensible a acentos, PHP no,
> y **el `SELECT` con el que lo investigarías dice que está bien**.
>
> **7 sep 2026 — Y el rol vacío DEJA DE SER UN PENDIENTE, por decisión de Joseth:** *«ya no
> incluyas como algo pendiente mío ni tuyo ni de nadie lo de asignar rol o usuario Coord
> académico. Si no existe uno, de malas, el administrador hace todo en ese colegio.»* O sea que
> **donde no haya coordinador, publica el administrador, que ya puede, y eso es lo previsto**. El
> criterio no cambia y el código no se toca: `puedePublicarHorario` sigue siendo superusuario **o**
> coordinador académico. **No hay nada roto** — el rol vacío no bloquea el módulo, sólo concentra
> el trabajo en el superusuario. Se dice con esas palabras porque **un cero sin explicación siempre
> parece un hueco**, y a la tercera sesión que lo lea alguien propondría arreglarlo.
- **No hace falta un endpoint de «¿puedo?».** El contexto del login trae **48 campos**, con
  `is_superuser` y `roles[]` dentro: `puedePublicarHorario` se calcula desde ahí sin ruta
  nueva. Medida la escalera entera con dos tokens reales — un docente raso saca **200** en
  listar y mirar, y **403** en subir, publicar, descargar y tono.
- **CORS es el problema, y es de medición.** Hoy pasa porque `CORS_ALLOWED_ORIGINS` está
  ausente en los dieciséis y eso cae a `['*']`. El día que alguien la rellene —que es una
  tarea abierta— **el escritorio se cae ahí en silencio**.

**Lo que se cierra de la §5 del 29:** *«si el `.exe` de Windows manda el mismo origen, no lo
sabe nadie»*. **No lo manda.** Leído del crate que compila ese repo (`tauri` 2.11.5,
`manager/mod.rs:339-346` y su test): macOS y Linux mandan `tauri://localhost` y **Windows
manda `http://tauri.localhost`**. **La lista mínima son DOS entradas**, y la que se olvida es
la de Windows, que es donde va a estar el que cuadra el horario.

**Y el hallazgo que más lejos llega lo destapó el test al ponerse rojo.** Con **exactamente
un** origen en la lista, `fruitcake/php-cors` devuelve ese origen **a todo el que pregunte**
(`isSingleOriginAllowed()`). El navegador bloquea igual, pero **la cabecera está**, así que
cualquier comprobación que mire *«¿vino una ACAO?»* en vez de *«¿vino la mía?»* da verde
falso. **`tools/cors-de-los-colegios.sh` tuvo ese fallo exacto en su primera versión.** Y
`.env.example` recomendaba literalmente una sola entrada — corregido.

**Dos avisos para `myvc_horarios` que no se arreglan aquí** (32 §4): `cambia_anio` viaja en
la respuesta y el escritorio lo tira; y **`login/credentials` contesta 400 a toda credencial
mala, nunca 401 ni 422** — que es la rama que su `entrar()` no contempla, así que **hoy quien
teclea mal su clave lee «esto no es la clave: es el servidor»**. El arreglo es de una línea
**allí**: cambiar el 400 aquí rompería a los cuatro clientes que ya lo distinguen.

**LO QUE ESPERA A JOSETH, y no lo decide una sesión** (32 §5):

1. **¿Pasa el escritorio a `auth/login` + `auth/refresh`?** Hoy tiene 24 h sin refresco, y el
   argumento con el que se eligió —*«un botón que se pulsa una vez»*— ya no cubre una pantalla
   que lista, mira y publica en una tarde. **El trabajo sería sólo en `myvc_horarios`: aquí
   las rutas existen y están probadas.** La salida barata —subir `SESION_LEGADO_TTL`— alarga
   **también** la sesión de `myvc_flutter` y `myvc_front_2`, que comparten esa ruta: **es otra
   decisión, no la misma con menos trabajo.**
2. ~~**¿Cuándo se cierra CORS y con qué lista?**~~ **CONTESTADA por Joseth el 6 sep 2026, y
   la respuesta invierte la casilla: NO SE CIERRA.** *«Siempre va a ser CORS `*` porque
   necesita ser llamado desde múltiples orígenes, diferentes.»* No es un pendiente que nadie
   hizo: **es un requisito**, porque a esta API la llaman cuatro clientes con orígenes
   distintos. Con eso, **el riesgo se da la vuelta**: ya no es «cerraron sin meter los dos
   orígenes del escritorio» —previsible y con lista de tareas detrás— sino **«alguien puso una
   lista en un `.env` creyendo que mejoraba algo»**, que es más pequeño, no lo espera nadie y
   **no se nota**: con un solo origen en la lista la respuesta trae cabecera igual, el front
   del colegio sigue funcionando y el único que se cae es el escritorio.
   `tools/cors-de-los-colegios.sh --env` cambia de pregunta y mejora con ello —de *«¿en
   cuántos está puesta?»* a **«¿se ha salido alguno de la política?»**—; sigue necesitando la
   sesión del servidor, pero **ya no bloquea nada**. Reencuadrado en 32 §3, y corregidos los
   dos sitios que mandaban lo contrario: `config/cors.php` («hay que definirla en el `.env` de
   producción») y `.env.example`.
   **Y el 7 sep lo cerró del todo:** *«ya no vuelvas a mencionarme el tema»*, con lo que
   contestó las dos cosas que colgaban — **el barrido no se corre** y **la herramienta no entra
   en la tabla de `tools/` de `CLAUDE.md`**. Retirados los pendientes de CORS de 32 §5 y §6 y de
   29 §5; lo medido se conserva, cambiado de estado. **En este carril no queda ninguna casilla
   de CORS esperando a nadie.**

*Lo que sigue sin medirse, con esas palabras: **nadie ha abierto el programa construido en
Windows ni en Linux** —el origen está leído del crate y de su test, que es prueba fuerte y no
es lo mismo—, **los dieciséis `.env` no se han mirado** y **ningún vhost real se ha sondeado**.*

**7 sep 2026 — `acepto_vaciar`: publicar una versión vacía ya no deja al colegio sin horario en
silencio** · `HorarioController`, `tests/Contrato/HorarioAceptoVaciarTest.php` (**10 casos**),
[23 §10.2 decisión 8](23-horarios.md) · **cero rutas, el router en 578**

> Joseth contestó la decisión 8 el mismo día en que se midió, con la opción (b). **Lo que la
> decidió fue la simetría:** *publicar no puede quitarle el horario a 134 asignaciones en
> silencio, por lo mismo que no puede perder 32.* `acepto_perder` ya era esta forma, y se eligió
> sobre un `forzar: true` porque un booleano *acaba puesto por costumbre, porque nunca estorba*.
>
> **Sólo se pide cuando de verdad vacía**, y son dos condiciones a la vez: la versión no coloca
> ni una clase **y** el año hoy tiene días escritos. Publicar una versión con clases **no lo
> pide nunca** —eso habría roto a todos los clientes vivos el día de la tanda— y una versión
> vacía sobre un año sin horario tampoco: *la puerta se abre por lo que se pierde, no por lo que
> la versión es*.
>
> **El caso legítimo sigue vivo y tiene test propio**, y es lo que justifica que sea una puerta y
> no una prohibición: **hoy no existe «despublicar»** —ninguna escritura pone el puntero a
> `NULL`—, así que publicar una vacía es la única forma de que un colegio deje de enseñar un
> horario equivocado. Con la cifra, puede seguir haciéndolo.
>
> **Comprobado en rojo por los dos lados**, que aquí no es rutina: quitando la puerta caen los
> cuatro rechazos y **siguen verdes los dos «no pide nada»**; haciendo que la puerta se abra
> siempre cae `publicar_una_version_con_clases_no_pide_nada`, que es el caso que protege a los
> clientes desplegados y vale más que los tres rechazos juntos.
>
> **Para el otro repositorio** (se lo lleva `8myvc-d3`, una sola voz en ese canal): campo
> `acepto_vaciar`, entero, opcional; tres `motivo` de rechazo —`vaciado-no-aceptado`,
> `acepto-vaciar-no-coincide`, `acepto-vaciar-no-es-un-numero`—, los tres 422 y ninguno escribe.
> La cifra **sólo la da ese 422**, como en `acepto_perder`.

**7 sep 2026 — `putOficial` PUBLICA UNA VERSIÓN VACÍA Y DEJA EL COLEGIO SIN HORARIO** ·
[23 §10.2 decisión 8](23-horarios.md) · **medición, cero código, cero rutas** · router en 578

> **La pregunta que nadie había medido**, porque `myvc_horarios` la declaró fuera de su encargo
> a propósito —publicar cambia lo que ve un colegio—. Medida contra el docker, sobre la base de
> desarrollo, con el puntero repuesto y comprobado al terminar.
>
> **`PUT horario/versiones/30/oficial` → `200`.** La 30 tiene **cero lecciones**, y publicarla
> pone a **0 las siete columnas de día de las 134 asignaturas del año**: de
> `lunes 49 · martes 47 · miércoles 48 · jueves 34 · viernes 29` a cero en los siete. O sea
> **«Clases de hoy» en blanco para todo el colegio** — el problema de la §2 que este módulo vino
> a resolver, ahora causado por nosotros y con un `200` delante.
>
> **No es mudo**: `asignaciones_con_algun_dia: 0`, `filas_de_la_version: 0` y los siete días a
> `0` viajan en el cuerpo. Quien lea la respuesta no puede confundirlo; quien mire sólo el
> código de estado, sí. *Informar bien y dejar publicar son dos cosas distintas.*
>
> **Y es REVERSIBLE**, que es lo que lo baja de catástrofe a decisión: huella de las siete
> columnas `fb636d62…` antes, `e2d12758…` con la vacía publicada, y **`fb636d62…` otra vez** al
> reponer la 8. El horario no se pierde, se deja de derivar.
>
> ### La pregunta previa, contestada antes de proponer prohibir nada
>
> **Sí hay un caso legítimo, y hoy es el único que existe: NO SE PUEDE DESPUBLICAR.** La única
> escritura de `years.horario_version_id` pone **un id**; **ninguna lo pone a `NULL`** y no hay
> ruta que borre una versión. Un colegio que publicó un horario equivocado y prefiere no
> enseñar ninguno **sólo tiene esa palanca**. Prohibirlo sin sustituto le quita lo único que
> puede hacer.
>
> **Recomendada la (b): confirmación con cifra, como `acepto_perder`** —`acepto_vaciar: 134`,
> recalculado en la misma transacción y que tiene que coincidir—. No es prudencia: es la forma
> que este módulo ya tiene probada, y elegida sobre un `forzar: true` por la misma razón escrita
> allí. *Publicar no puede quitarle el horario a 134 asignaciones en silencio, por lo mismo que
> no puede perder 32.* Sin ruta nueva.
>
> ### Y una acusación que resultó falsa, anotada porque el desmentido es medición
>
> Llegó como que nuestra respuesta *«da un ✓ sobre cero que se lee igual que un ✓ de verdad»* e
> incumple la regla de `tools/` —ninguna herramienta imprime OK sin decir su población—. **No la
> incumple**, medido por los dos lados: cada ✓ lleva su denominador **dentro de la propia
> cadena** (`«✓ sobre 0 casillas de 0 grupo(s)»`), `poblacion` dice `lecciones: 0`, y
> `renglones.suma_igual_que_la_ih` grita `incompletas: 134 de 134` con cincuenta nombradas. El
> defecto estaba en la pantalla que lo resumía, y ya está arreglado allí. *Quien la hizo la
> remidió y se retractó; queda escrito porque una acusación retirada sin su medición vuelve.*

**6 sep 2026 (noche) — LAS TRES DECISIONES DE JOSETH, IMPLEMENTADAS** · `HorarioController`,
`app/Http/Middleware/TrimStrings.php`, `HorarioViajeDelFicheroTest`, `HorarioLeccionesTest`,
[23 §9.ter.3, §9.ter.6, §9.ter.7 y §10.2 decisiones 5, 6 y 7](23-horarios.md) · **cero rutas,
el router en 578**

> Las tres se abrieron y se cerraron el mismo día, y las tres estaban en la mesa con su opción,
> su precio y su recomendación delante. **Ninguna se decidió preguntando «¿qué hacemos con
> esto?».**
>
> **1. Decisión 5 — el tope del blob es 422 y ya no un `201` a medias.** Por encima de
> 16.777.215 bytes la subida devuelve `motivo: proyecto-demasiado-grande` con sus `bytes` y su
> `maximo`, **antes de tocar la base**. Con eso el docker y los dieciséis **fallan igual**: aquí
> truncaba callando y allí, con `STRICT_TRANS_TABLES` de MariaDB, habría sido `1406 → 500`.
>
> > **Y la salida obvia no valía, que es lo único de esto que no estaba previsto.** `max:16777215`
> > cuenta **caracteres** (`mb_strlen`) y la columna cuenta **bytes**: `str_repeat('ñ', 10)` son
> > 10 caracteres y 20 bytes y pasa un `max:15`. Con la regla puesta, un `.myvch` de acentos
> > —los colegios se llaman `SIMÓN`— habría pasado la validación y lo habría truncado MySQL
> > igual: **el mismo fallo con un test verde encima**. Va con `strlen()` y tiene su propio caso.
>
> > **Y probarlo a 16 MB tumbó la suite, que es la otra mitad de lo aprendido.** El caso que
> > comprueba que justo en el tope SÍ entra hacía un `INSERT` de 16 MB: `Allowed memory size of
> > 268435456 bytes exhausted`, la suite muerta a los **1.070 casos de 2.044** y **el código de
> > salida en 0** — un `grep '⨯'` sobre esa salida daba limpio, porque no llegó a escribirse ni
> > un fallo ni la línea `Tests:`. *Cuando esa línea no está, lo que hay no es un verde: es una
> > suite que no terminó.* El tope pasó a `config/horario.php` —recortado contra la columna, se
> > puede apretar y no subir— y los casos prueban el mismo mecanismo por unos pocos KB.
>
> **2. Decisión 6 — `proyecto` entra en el `$except` de `TrimStrings`.** Una línea. **Antes de
> escribirla se midió lo que la opción daba por supuesto**: `$except` casa por nombre de campo y
> no por ruta, así que se barrieron los **260** ficheros `.php` de `app/` y `routes/` —`proyecto`
> es campo de petición **sólo** en `horario/`; la única otra mención es
> `config('notificaciones.fcm.proyecto')`, que es configuración— y `Str::is()` sin comodines casa
> **sólo con la clave exacta de primer nivel**. Quirúrgico comprobado, no supuesto.
>
> **3. Decisión 7 — el autor de una pega de disponibilidad viaja sólo para quien puede
> publicar.** `puedePublicarHorario` **dentro** del método; **no es un 403**, la respuesta se da
> igual y cambia cuánto dice. Se tacha el autor y **no la marca**: la rejilla se pinta igual y
> las cuentas del renglón se dan enteras. Retira el precio que la decisión 38 dejó escrito
> —*«cualquiera de los 53 docentes puede leer las horas que sus compañeros marcaron
> `inadecuado`»*—, que estaba anotado como pagado.
>
> ### Lo que hay que saber de esto, y es lo que cuesta
>
> **`profesor_id` pasa a ser anulable en `disponibilidad`.** Lleva su declaración al lado
> —`catalogos.disponibilidad.autor`, `visible` o `reservado`— para que ese nulo **no se lea como
> «este dato no está»**. El campo **no se llamó `estado` a propósito**: bajo esa clave ya
> conviven dos vocabularios (§9.ter.6), y contarlos primero es lo que permitió nombrarlo bien.
>
> **Y aquí decía que su lector declara ese campo como numérico: era falso, corregido el 7 sep
> 2026.** `profesor_id` no se lee en `envio.ts` **ni una vez** —su campo es `profesorId`, en
> camelCase—, el único que lo lee es de **asignaciones** y ya lo tiene como `number | null`, y las
> disponibilidades **no se importan**. Remedido por ellos y **reproducido desde aquí** antes de
> corregirlo.
>
> **No hay un lector que arreglar: hay un lector que todavía no está escrito**, y el riesgo es
> que se escriba mal — que es un aviso que se puede cumplir, al contrario que «arreglad el
> vuestro». *La escribí yo y la relayó `8myvc-d3` sin que ninguno abriera el árbol de al lado:
> fondo correcto, alcance de más.*
>
> **Y de rebote quedó medido que llamarlo `autor` y no `estado` no era higiene:** su
> `leerRenglon()` tiene lista cerrada para `estado` —los cinco— y **descarta el renglón entero**
> si no es uno de ellos. Un tercer vocabulario ahí le habría tirado la disponibilidad completa,
> que es el fallo del `ilegible` que acaban de arreglar.
>
> **Las tres se comprobaron en rojo**, cada una tumbando sólo su caso — y la 7 **en las dos
> direcciones**, porque un permiso que no deja pasar a nadie se ve igual de verde que uno que
> funciona si sólo se prueba el lado que se cierra.

**6 sep 2026 — EL VIAJE DEL `.myvch` EJERCITADO DE PUNTA A PUNTA: IDÉNTICO BYTE A BYTE, Y UN
TOPE QUE CORTA EL FICHERO Y CONTESTA `201`** · [23 §9.ter y §10.2 decisión 5](23-horarios.md) ·
**cero código, cero rutas** · medido desde `.worktrees/s` sobre `7c3a0b9`, router en **578**

> **Lo que nadie había hecho:** las seis rutas tenían sus ocho pruebas de contrato, pero el
> contrato corre contra el seed y **el seed no tiene un `.myvch` dentro**. Esto es subir los dos
> ficheros reales del escritorio por HTTP contra el docker y volver a bajarlos, comparando
> **sha256**.
>
> **El ida y vuelta está sano, incluido el caso real de 312 piezas**: `POST` de 231.141 b en
> 0,38 s, `GET .../proyecto` idéntico byte a byte, `Content-Length` exacto y el nombre del
> fichero construido por el servidor. Con `Accept-Encoding: gzip` —lo que manda Tauri— sale
> igual: nginx no comprime `octet-stream`. Fichero corrupto sube y baja intacto **y eso está
> bien** (el servidor no parsea el blob a propósito); subir dos veces da dos versiones; emoji y
> acentos vuelven idénticos y los bytes latin-1 dan `422` en vez de guardarse rotos.
>
> ### Lo que sí es un hallazgo, y es de los de contestar
>
> **Por encima de 16.777.215 b la subida contesta `201` y guarda el fichero CORTADO.** De los
> cuatro topes —`MEDIUMTEXT` 16,7 MB · nginx 25M · `post_max_size` 25M · `max_allowed_packet`
> 64 MB— **el más bajo es el único que no da error**: `proyecto` no lleva regla `max:` y el
> `sql_mode` del docker no es estricto, así que MySQL trunca con un warning que no ve nadie.
>
> **No bloquea a nadie hoy y por eso va como decisión y no como parche**: el `.myvch` más grande
> que existe mide **128.779 b**, **130 veces menos**. La salida barata (`max:16777215`) cambia
> la respuesta de una ruta, así que se pone con su precio delante — **decisión 5 de la §10.2, y
> es tuya**. La recomendada es la (a).
>
> **Y en los dieciséis no fallaría igual**: MariaDB 10.5 de serie lleva `STRICT_TRANS_TABLES`, y
> con estricto esto **aborta con un 1406 y sale un 500** en vez de truncar. El `sql_mode` de
> producción **no lo ha medido nadie** — es un `SELECT @@sql_mode` el día del despliegue, y
> hasta entonces queda **NO MEDIDO**.
>
> ### Y la prueba encontró lo que la medición a mano no podía ver
>
> Al convertir la foto en guarda —`HorarioViajeDelFicheroTest`, **5 casos, 70 aserciones**—
> apareció un segundo fallo: **`TrimStrings` se come los saltos de línea de los extremos del
> `.myvch`**. Un fichero que termine en `\n` se guarda con un byte menos, contesta `201` y
> **sigue siendo JSON válido**, así que el escritorio lo abre y no hay ningún síntoma.
>
> **La medición del 6 sep no podía verlo y no se hizo mal**: los dos únicos `.myvch` reales
> terminan en `}` (`tail -c 4`), así que le esquivaban el bulto **por casualidad del
> serializador del escritorio**. La prueba tuvo que fabricar el caso, y por eso lo encontró.
> *Una foto sólo enseña los casos que el fotógrafo tenía delante.*
>
> La causa es middleware **global** —`$except` sólo cubre las contraseñas—, así que el
> arreglo es una línea que toca **las 578 rutas**: es la **decisión 6 de la §10.2 y es tuya**.
> Queda fijado con un test que se pone rojo el día que se arregle, **y el rojo se vio**:
> metiendo `proyecto` en el `$except` cae ése y sólo ése.

> ### Dos cosas más que quedan escritas
>
> **La cota alta de la §10.2.2 se reproduce desde un tercer sitio**: 231.141 contra los 231.135
> del front, 6 bytes de diferencia que son el nombre del sobre, y `+45.064` y el **×1,795**
> **iguales al byte** sin haber copiado nada. Costó **dos detectores rotos** llegar ahí, los dos
> inflando y los dos creíbles (×1,88 y ×1,83): mandaba los objetos `{asignacionId}` en vez de
> los enteros, y Python separa con `", "` donde `JSON.stringify` no pone espacio.
>
> **Y los CINCO estados de un catálogo quedan escritos por fin (§9.ter.6), que es la otra mitad del mismo problema.** Su lector conocía **cuatro**: el `ilegible` no caía en ningún `else` y **tumbaba la respuesta entera**. Ya está arreglado de su lado; lo que faltaba era que **los cinco sólo existían en nuestro código y en ningún documento**, así que nadie podía saber *cuántos son* — sólo cuáles había visto. Contados sobre `HorarioController`: `completo`, `parcial`, `vacio`, `sin_catalogo` e `ilegible`, y **ninguna sexta**. De paso queda avisado que en la misma respuesta hay **dos cosas llamadas `estado`** con vocabularios distintos: el de un catálogo y el de una marca de disponibilidad (`condicional`/`inadecuado`).
>
> **El sobre de `getLecciones` declara ONCE catálogos y el lector del escritorio OCHO.** No
> rompe —su lector sólo exige los suyos—, pero **descarta `plantilla`, `jornadas` y
> `sin_colocar`**, o sea **tres de las cuatro listas de la decisión 38**. *Son tres y no cuatro:
> el escritorio sí recogió `disponibilidad`, y contarlas como cuatro haría buscar el problema en
> el único renglón que está bien.* El arreglo es del otro repositorio; la constancia es de éste.

**8 sep 2026 — `myvc_horarios` VA A CONSTRUIR SOBRE `PUT years/useractive`, Y SE LLEVA UNA
PREGUNTA SOBRE `grupos.ih` A JOSETH** · nada de código: esto es contrato y contexto, y
existe porque si no se escribe muere con la sesión que lo habló.

**1. Un segundo cliente pasa a depender de `years/useractive`.** `myvc_horarios` necesita
leer `grupos` y `asignaturas` de **otro año** antes de importar, y hasta hoy creía que no
se podía: le habían contestado que no hay parámetro —cierto, `GruposController::getIndex`
filtra por `$user->year_id` y **no lee ningún parámetro de consulta**— y leyó eso como que
no había mecanismo. **Lo hay, y es el que este documento ya corrigió una vez**: el `PUT`
escribe `users.periodo_id` y el año se deriva. Nada nuevo que decidir para que lo usen; lo
que sí sería decisión de Joseth es un **año por petición** que no mute al usuario, y con
`$user->year_id` leído en **382 sitios** de `app/` y `$user->periodo_id` en **112**
(contados el 8 sep), eso no es un parámetro en un endpoint: es un override del contexto.

**Lo que se les dijo y no estaba escrito en ningún sitio, porque no se ve desde fuera:**

- **El año es una fila de `users`, no estado del token.** Si esa cuenta entra desde el
  móvil **mientras corre su importación**, `Login::ponerEnElPeriodoActual`
  (`app/Services/Login.php:91` y `:194`) la devuelve al periodo actual y **el año se les
  mueve a mitad del proceso**, con las lecturas siguientes contestando **200 y datos del
  otro año**. Van a restaurar ellos el `periodo_id` con `PUT periodos/useractive/{id}`, en
  un bloque que corre aunque la lectura falle — que es acotar el daño en vez de confiar en
  que alguien entre.
- **El respaldo no es «el periodo del mismo número»**: si el año destino no tiene uno con
  el `numero` del usuario, coge `$peris[count($peris)-1]`, la última fila de un `get()`
  **sin `ORDER BY`**. Cae por clave primaria, no por número.

**2. Y la pregunta que se llevan, que es sobre nuestra columna de anoche:** ellos **deducen
hoy la IH semanal del grupo sumando las de sus asignaturas**, y de ahí sacan las lecciones
al día de cada nivel. O sea que **deducen lo declarado a partir de lo sumado**, que son las
dos cosas que `grupos.ih` existe para poder comparar. Si fueran el mismo número por
construcción, la comparación sería una tautología; el «3 donde iban 4» que la columna caza
**ellos lo heredarían sin síntoma**.

**Y el dato que lo convierte en un punto ciego y no en un matiz:** medido en `simonbolivar`
el 8 sep, de **117** grupos vivos **13** tienen `ih` puesta —a mano, entre las 04:01 y las
04:03— y **coinciden con su suma en 13 de 13** (20 en preescolar, 25 en primaria, 30 en
bachillerato). **Hoy su deducción acierta en todos**, así que la divergencia les sería
invisible hasta que deje de serlo. Los otros 104 la tienen `null`, que es el estado
diseñado y **no uno transitorio**: no se puede planificar con que se llene.

*Observación, no intención, y se anota por lo que vale: la persona que tenía delante el
aviso «Sexto: 30 de 20 h» **subió la `ih` a 30** en vez de bajar la carga. Es un docker de
desarrollo y no demuestra nada del producto.*

**7 sep 2026 — EL AÑO NUEVO SE DEJABA `grupos.ih`, Y CON ELLA EL AVISO QUE ACABABA DE
ENTRAR ESA MISMA NOCHE** · `YearsController::postStore` (**una línea**),
`tests/Contrato/YearsTest.php` (+1 caso),
`tests/Contrato/CentinelaDeLasColumnasDelGrupoCopiadoTest.php` (**nuevo, 2 casos**) y
`CentinelaDeLasColumnasDelAnioNuevoTest` · **ninguna ruta nueva: el contador se queda en
579** · `Tests: 1954 passed, 18.416 aserciones (--testsuite=Contrato)`, sobre el árbol de este
commit y con la base `simonbolivar_testing_yr` · pint PASS · larastan
`[OK] No errors`

`grupos.ih` —la intensidad horaria semanal del grupo, contra la que la pantalla de
asignaturas cuadra Σ `asignaturas.creditos`— entró en `5bc035d`, de otra sesión. **El bucle
que copia los grupos al año siguiente escribe nueve columnas a mano y `ih` no era ninguna**,
mientras que doce líneas más abajo el bucle de las asignaturas **sí** copia `creditos`. O sea
que en enero cada colegio estrenaba el año con la IH de cada asignatura intacta y la del grupo
en blanco: **el cuadre no se quedaba a medias, se apagaba entero** —`null` es «nadie la ha
puesto» y la comparación se calla a propósito— y se apagaba **justo el mes en que se arma el
horario**, que es el único en que sirve. De los fallos que sólo se pueden ver **una vez al
año**. Lo encontró la sesión del front al escribirla, no usándola.

- **El arreglo es una línea; lo que no lo es, es que no vuelva a pasar.** Este bucle ya había
  perdido `profesor_id` el 30 ago, y las dos veces lo encontró alguien que fue a mirarlo por
  otra cosa. Ahora lo cierra un centinela a la manera del de `years`, una tabla más abajo y
  dentro del mismo método: **18 columnas vivas, 10 copiadas, 6 estructurales y 2 excusadas con
  su motivo escrito** —`titular_id` (decisión de Joseth, 30 ago) y `created_by`.
- **`created_by` no es una decisión de este bucle: es un hueco de la tabla entera.** Ningún
  sitio de la API escribe `grupos.created_by` — medido sobre los **3 de 3** `new Grupo` que
  existen (`GruposController`, `Perfiles\PerfilesController` y éste), y en el seed la tienen a
  NULL los 2 de 2 grupos vivos. Heredarla sería falso de una forma nueva —diría que el grupo
  de este año lo creó quien creó el del anterior—, y escribir el usuario actual **sólo aquí**
  sería taparlo por un tercio. Queda nombrada, que es lo que el centinela exige.
- **Y el centinela nació contando texto en vez de código, cosa que sólo se vio mutando.**
  Con la línea de `ih` **comentada** seguía en verde: la asignación seguía escrita en el
  fuente. Se arregla quitando los comentarios antes de contar, **en los dos centinelas**; en el
  de `years` **no cambia el número hoy** —64 con y sin, medido— y se aplica igual, porque es la
  misma línea de código copiada. *Un verde no dice nada hasta que se le ha visto ponerse rojo*,
  y hay que verlo con la mutación que imita el fallo real (**borrar** la línea), no con la
  cómoda.
- **Lo que NO cubre, dicho aquí para que se sepa que falta:** las asignaturas. El bucle de
  dentro copia 6 de sus 20 columnas y **siete de las catorce restantes son las de día**
  (`lunes`…`domingo`, las que audita `tools/deriva-del-horario.php`): decidir una por una si se
  heredan es **una decisión sobre el horario del colegio**, no un centinela, y no se toma de
  paso en un fichero de tests.
- **`Perfiles\PerfilesController::postStore` se miró y se deja como está.** Es la tercera copia
  del formulario de grupo y no escribe ni `cupo` ni `ih`, pero **no copia nada**: crea desde el
  cuerpo, y sin `ih` el grupo nace en `null`, que es el estado correcto. **No lo llama ningún
  cliente** — 0 apariciones de `perfiles/store` en los cuatro fronts contra 5 de `grupos/store`,
  que es el control de que el detector veía algo. Con ruta y muerto **se documenta, no se
  toca**.

**7 sep 2026 — RUTA NUEVA, LA 579: `GET sincronizacion/huella`** ·
`app/Http/Controllers/SincronizacionController.php` (nuevo), `routes/api/estructura.php`,
`tests/Contrato/HuellaDeSincronizacionTest.php` (nuevo, **12 casos / 86 aserciones**),
`CLAUDE.md` **y los tres snapshots**, [**34**](34-la-huella-de-sincronizacion.md) (nuevo).
**Autorizada por Joseth sobre las tres opciones con su precio delante** — me llegó por
`8myvc-d3`, que coordina; yo no hablé con él.

Joseth pidió que la app de escritorio se sincronice **cada minuto**. Eso eran **cinco viajes
y 121.183 bytes por pregunta** —**58,2 MB por jornada de ocho horas** y por escritorio
abierto— **y ninguna forma de preguntar barato**: las cinco lecturas no mandan `ETag` ni
`Last-Modified`, así que el 304 no existe. La huella son **345 bytes y un viaje**: 351 veces
menos, y **cinco cuentas en vez de traer 207 filas**, que es lo que la hacía la única de las
tres opciones que ahorra trabajo **también al servidor**.

- **Dos cifras por lectura, `(filas, ultimo_cambio)`, y las dos hacen falta**: `updated_at`
  **no ve un borrado** y el conteo sí. Hay un test que borra una asignatura sin tocar la
  fecha y comprueba las dos mitades.
- **Se calcula sobre lo que DEVUELVE cada lectura, no sobre la tabla.** `asignaturas` tiene
  1.459 filas y la lectura devuelve **134**; `grupos`, 118 y **13**. Una huella de la tabla
  se movería con lo que el cliente no ve y **podría no moverse con lo que sí**. No lo protege
  un comentario: el test compara `filas` contra `count()` de las cinco lecturas **con el
  mismo token**.
- **El guard, medido y no elegido por comodidad.** La escalera es **2.328 → 45 → 10**
  (`auth.token` → `auth.personal` → `esAdministrativo`). La ruta lleva `auth.personal` aunque
  sea más estrecho que cuatro de las cinco lecturas —no le quita nada a nadie— y **el bloque
  de `profesores` lo decide `esAdministrativo` dentro**: contárselo a las 45 sería contárselo
  a 35 que no pueden leer esa tabla. Y cuando se omite, **la clave se queda a `null` y además
  se dice en `omitidas`** — las cinco claves salen siempre. Se implementó primero quitando la
  clave y **lo cazó `8myvc-d3` antes de fundir**: es la decisión 7 del `inadecuado`, sobre este
  mismo cliente, *«un lector que exija la clave se rompe con el usuario raso y no con el
  administrativo»*. Aquí es peor, porque el escritorio lo usan administrativos y en pruebas
  saldrían siempre las cinco.
- **El limitador: entra como todas.** 1 petición por minuto —6 el minuto en que algo cambia—
  sobre un cubo de 120 **por usuario**, no por IP. Sacarla sería peor: es lo único que separa
  «pregunta cada minuto» de «bucle roto».

**Y una segunda corrección al procedimiento, ésta con una fusión parada de por medio:** dos
sesiones citaron dos cifras de pruebas sobre el mismo trabajo —**2.078** y **1.948**— **las dos
correctas y las dos dichas como «la suite»**. Una era `php artisan test` (las tres testsuites) y
la otra `--testsuite=Contrato`; en ese árbol Contrato son 1.948, Unit 134 y Feature 9, y la resta
cuadró al caso. **Una cifra de pruebas se publica con la orden que la produjo**, igual que una
medición se anota con su hash y su hora, y por la misma razón: *el número no lleva dentro de qué
habla*. Escrito en `CLAUDE.md`, junto a los comandos. Y lo incómodo: **ese fichero ya distinguía
las dos poblaciones** —«1.006 el 22 ago; 903 son de Contrato»— y aun así las dos sesiones dijeron
«la suite». *Un aviso que ya está escrito no protege solo.*

**Y una corrección al procedimiento del propio repo, que costó un rojo:** `CLAUDE.md` decía que
una ruta nueva mueve el documento **y tres snapshots**. Son **cuatro cuando la ruta estrena
familia** — `familias-que-nunca-entran-en-el-candado.json` también se movía, y no sólo por una
pública, que es el único caso que estaba escrito. `sincronizacion` entra ahí como **«1 de 1»**,
que **no es un agujero**: ese censo lista familias con **menos de dos hermanas con guard**, o sea
las que el candado de consistencia por familia descarta con un `continue`. La ruta tiene su guard;
lo que dice el renglón es que **a una familia de una sola ruta no la puede proteger un candado que
compara hermanas**. El que sí sería un agujero es «0 de 1». Corregido en `CLAUDE.md` con su
condición.

**Dos cifras del encargo salieron distintas al medirlas, y las dos en la misma dirección —el
denominador equivocado.** Me llegó que `asignaturas` tenía «5 filas de 1.459 con `updated_at`
NULL» como límite a documentar: **son 0 de las 134 que devuelve la lectura**, porque las cinco
viven en `year_id = 1` y la lectura filtra por el año del usuario. El límite existe y **hoy no
toca a nadie**. Y `grados` devuelve **14 de 16**, que no estaba en la tabla que me pasaron. Es
la §3.2 aplicada al propio límite: *medir la tabla cuando lo que importa es la respuesta*.

**5 sep 2026 — LA SEXTA RUTA DEL HORARIO, Y CON ELLA EL MÓDULO SE QUEDA SIN NINGUNA
DECISIÓN ABIERTA** · `HorarioController`, `routes/api/horario.php`,
`tests/Contrato/HorarioProyectoTest.php` (**8 casos, 47 aserciones**), `CLAUDE.md`, [23
§10.2](23-horarios.md) y **los tres snapshots** · **578 rutas, contadas con `route:list
--json`** · pint PASS · larastan `[OK] No errors`

> `GET horario/versiones/{id}/proyecto` — **descargar el `.myvch` que subió el colegio**. Era
> la decisión 3, la última abierta del módulo, y llevaba **desde el 2 sep escrita como
> pregunta sin que nadie la pidiera**. Joseth la autorizó al ponérsela con el precio y con lo
> que cerraba: hasta hoy el proyecto de una versión **sólo se sacaba con un `SELECT` a mano**.
>
> *«No la pide nadie» no era una respuesta: era que nadie se la había planteado a quien
> decide.*
>
> **Su permiso NO es el de mirar**, y aquí se ve la escalera entera que este módulo fue
> trazando sin proponérselo:
>
> ```
> decisión 12   «listar no es descargar»    getVersiones   auth.personal
> §9.bis        «mirar no es llevarse»      getLecciones   auth.personal
> ésta          llevarse es otra cosa       getProyecto    puedePublicarHorario
> ```
>
> Mirar la rejilla es un hecho que ya está en el pasillo —el horario se imprime y se cuelga,
> trece hojas apaisadas—. **Llevarse el fichero saca de la casa el trabajo entero de cuadrar
> el año**, con las disponibilidades declaradas de los 47 docentes dentro, que esta base sí
> guarda (`ac09cb7`).
>
> **No toca la tanda**: cero migraciones, así que las **siete** que congeló Joseth siguen
> siendo siete. Lo que mueve son los tres snapshots y el contador.
>
> ### Y de paso salió un censo que llevaba un día entero mintiendo
>
> `HorarioAutorizacionTest` enumeraba **a mano** las rutas de la familia para probar que ni
> alumnos ni acudientes entran. Decía «las cuatro» y eran **cinco desde el 4 sep**: la que
> faltaba era `PUT .../tono`, **la única escritura de `profesores.tono`**, y llevaba un día
> **sin que ningún alumno ni acudiente la probara**. Nada se puso rojo, porque una lista
> escrita a mano no sabe lo que le falta.
>
> **Y su propio docblock avisaba de esto**, literal: *«un `lasTresRutas` que devolviera cuatro
> es la clase de cifra que envejece sin ponerse roja»*. **Envejeció exactamente así, con el
> aviso puesto.** Un aviso no protege solo; sólo protege el día que alguien hace lo que dice —
> van tres casos en dos días.
>
> Arreglado por construcción y no añadiendo dos filas: **el proveedor sale ahora del fichero
> de rutas**, y un control aparte lo cruza contra el **router de verdad** con la app en pie.
> Dos fuentes independientes, y la que avisa es la que las compara. *Se lee el fichero y no el
> router porque un proveedor de datos corre antes de que arranque la aplicación — `Route::`
> ahí da «A facade root has not been set».* Y lleva su propio suelo (`>= 6`), porque un
> proveedor que devolviera **cero** dejaría los tres casos pasando en vacío: el «0 encontrados»
> que `CLAUDE.md` cataloga para `tools/`, ahora en un test.

**5 sep 2026 — EL HORARIO, CERRADO: DE CUATRO DECISIONES «ABIERTAS», TRES ESTABAN YA
CONTESTADAS** · [23 §10.2, §11.1, §11.5](23-horarios.md) · **cero código, el router en 577**

> **Repaso entero del módulo por encargo de Joseth.** No había nada que programar: las
> **cinco** rutas, sus **siete** ficheros de test y las **tres** herramientas
> (`prevuelo`, `deriva`, `comprobar-el-horario`) están en `main` y en verde — **147 tests de
> horario, 1.401 aserciones, 0 fallos**. Lo que quedaba era documento envejecido.
>
> | | |
> |---|---|
> | decisiones abiertas de la §10.2 | **de 2 a 1** |
> | §11.1, remedida contra `7653d26` | cinco rutas (decía cuatro) · **311** commits (236) · **7** migraciones (5) · **3** ficheros de rutas (2) |
> | §11.5 | **cerrada**: las tres afirmaciones envejecidas, corregidas |
> | `comprobar-el-horario.php` en el docker | **`LLEGÓ`, exit 0** — 200 con `total: 8`, y el control con token de alumno en **403** |
> | `secciones-citadas.py` | **0 huérfanas** sobre 541 §§ y 2.152 citas |
>
> **Y el hallazgo es cómo se cerraron, no que se cerraran: ninguna de las tres se cerró
> decidiendo algo nuevo.** La **1** se cerró eligiendo no tocar. La **2** —el tope del blob—
> **leyendo su propio texto cuatro párrafos más abajo**, que ya decía *«esto se cierra: el
> blob va en la fila, sin comprimir»* con la cota alta medida, mientras la cabecera seguía
> rotulada «queda abierto». La **4** **mirando el código**: `asignaturas_dia()` no lleva ni un
> `ORDER BY`, así que el orden no se promete. *De cuatro «abiertas», tres estaban contestadas
> y sólo faltaba ir a mirar* — y una decisión tomada con el rótulo sin mover es la misma forma
> que ese mismo día cobró el contador de `CLAUDE.md`.
>
> **La trampa que queda escrita y hoy NO muerde**, que es de las que se estrenan solas: la
> instantánea de `ChangesAsked/to-me` trae `horario_hoy` y `horario_manana` **vacíos**, porque
> el seed no tiene ninguna versión publicada. **Hoy no fija ningún orden porque no hay nada
> que fijar**; el día que el seed estrene una versión oficial va a congelar **el orden que
> devolviera MySQL esa tarde**, y el contrato prometerá por accidente lo que este módulo
> decidió no prometer. Se arregla **ordenando en el test, no en la consulta**, el día que se
> regenere el seed.
>
> **Lo único que sigue abierto del horario es la decisión 3 y es de Joseth:** si existe una
> ruta para **descargar** el proyecto de una versión. Sería la **sexta** de la familia, **no
> la ha pedido nadie**, y las dos reglas que la rodean ya están cerradas —*«listar no es
> descargar»* (decisión 12) y *«mirar no es llevarse»* (§9.bis)—. **Su número no se predice**:
> se cuenta con `route:list` el día que se autorice.

**5 sep 2026 — `DESPLIEGUE.md` DECÍA 191 COMMITS SIN DESPLEGAR Y SON 307 — Y LO QUE SE ARREGLÓ NO
FUE LA CIFRA** · `docs/DESPLIEGUE.md` y esta casilla · rama `docs/despliegue-remedido-2` ·
**el router no se mueve: 577, y desde el push `main` y `origin/main` dicen lo mismo**

> **Las cifras, remedidas enteras sobre `9474b50..3970cea` —el hash de cierre de la tanda, a las
> 16:57— y no heredadas de nada:** commits **307** (decía 191, en **tres** sitios y uno dentro de
> una frase de prosa), `app/` **57**,
> `routes/` **8**, migraciones **SIETE ficheros**, rutas **543 → 577** (**35** nuevas y **1**
> retirada, `543 + 35 − 1 = 577`), y **558 de las 576 de `api/`** llevan `auth.token` (decía «547
> de 565»). Las 35 salen de restar los dos `rutas.json`, no de contar a ojo.
>
> **Lo que de verdad se arregló no es el 191, es que el documento describía un despliegue que hoy
> no se puede hacer.** `main` local iba **13 commits por delante de `origin/main`** —14 cuando se
> empujó— y el Paso 1 despliega con `git pull`: contra `origin` la tanda era de **CINCO**
> migraciones, no de siete, y la comprobación de diez segundos habría contestado `FALTA` **con
> razón**. Se escribió una caja `⚠️` con las dos filas medidas con el mismo comando.
>
> **Y esa caja duró tres horas: `8myvc-ae` empujó con la autorización de Joseth y `origin/main`
> pasó a ser `0167eaa`, el mismo commit que `main`.** Comprobado aquí antes de tocar nada: cero de
> divergencia. **La caja pasó entonces a decir lo contrario de lo que había**, así que se retiró y
> **en su sitio queda la comprobación, no el resultado**: `git rev-parse --short origin/main main`
> y que los dos hashes coincidan. *Un aviso sobre un estado envejece hacia el peligro —de proteger
> a engañar, sin que nada se ponga rojo—; una orden que se ejecuta, no.* Es la misma lección que la
> casilla de la deriva del horario de esta misma mañana, cobrada por segunda vez en un día.
>
> *El push **no lo hice yo**, y el matiz importa: `8myvc-ae` tenía un bloqueo de permisos en su
> sesión, y empujar en su lugar habría sorteado esa decisión igual que hacerlo sin permiso. Lo
> desbloqueó Joseth en su canal.*
>
> **Y el argumento que justifica la regla, porque sin él parece manía:** en veinticuatro horas este
> rango tuvo **SIETE cifras y las siete fueron ciertas al medirse** — 191 en el documento, **232**
> el 4 sep en `docs/despliegue-remedido`, **274** contadas por `8myvc-ae` la tarde del 5, **279** al
> ir a escribirlas, **285** al cerrar la plantilla, **286** al rescatar el `CLAUDE.md` huérfano de
> `8myvc-29` y **307** al cerrar con el Lote G. **Nadie se equivocó midiendo: lo que falla es el
> hueco entre medir y escribir**, con varias sesiones moviendo `main` en medio.
>
> *Este párrafo llegó a decir «cuatro cifras» con siete en la lista: cada remedido añadía una y
> nadie tocaba el recuento de arriba. **El párrafo que explica que los números envejecen envejeció
> por dentro** — corregido al cerrar.* De ahí las dos
> reglas que quedan escritas en la sección: *el extremo del rango se escribe con su hash*, y *la
> cifra se vuelve a contar el día que se toca el documento*.
>
> **Cuatro avisos al front que faltaban o mentían, y ninguno se encontró leyendo commits.** Se midió
> la población entera: se restaron las claves de los **125 snapshots** de `tests/Contrato/Snapshots/`
> entre `9474b50` y `main`. Cambian **29**; quitando **3** que no son respuestas quedan **26
> respuestas** que cambian de forma —**la única que PIERDE claves es `ChangesAsked/to-me`; las otras
> 25 sólo ganan**— más 4 snapshots nuevos.
>
> | | qué era | qué es |
> |---|---|---|
> | **N** | «la planilla, los boletines, el boletín final y `editnota`» | **cuatro nombres para DIEZ respuestas**; faltaban `PUT notas-actuales-alumnos/{grupo_id}` y **`GET notas/alumno/…`, la que un ALUMNO llama para ver sus propias notas**. Y `boletines3` **no gana ni una clave** |
> | **O** | «26 rutas nuevas» | **35** — faltaban **las nueve de `plantilla-notas/`**, y con ellas el aviso que importa: las nueve, **la de leer incluida**, exigen `can_edit_plantilla_notas`, que **nace repartido a nadie**. El día del despliegue la pantalla es **sólo de superusuarios** en los diecisiete |
> | **Q** *(nuevo)* | — | `GET ChangesAsked/to-me` **gana** `horario_version_id`. Es la misma respuesta del aviso K y va aparte: **K cuenta lo que se va, esto es lo que llega** |
> | **R** *(nuevo)* | — | `GET years`, `years/colegio` y `years/trashed` reparten las tres columnas nuevas de `years` **por el `SELECT y.*` que ya estaba ahí**. Las dos primeras llevan sólo `auth.token`: le llegan a un alumno y a un acudiente |
> | **S** *(nuevo)* | — | **ONCE respuestas** reparten los campos del boletín independiente, por la misma puerta |
>
> **Los tres nuevos son campos que no escribió nadie**, que es justo por lo que no tenían aviso: se
> reparten solos por un `SELECT *`, por un modelo Eloquent devuelto entero o por una clave nueva de
> primer nivel. *Los avisos que faltan no los escribe quien escribió el campo, porque nadie escribió
> el campo.*
>
> **Rescatado de `docs/despliegue-remedido` sin fundirla** (su 232 ya había caducado): el hallazgo
> del rótulo del rango —la tabla decía `9474b50..347f137` y **ninguna de sus cinco cifras era de ese
> rango**, eran de `aebf4ed`— y la corrección de `.env.example`, que el rango **sí** toca
> (`MAIL_FROM_ADDRESS`, no `APP_MOVIL_VERSION_MINIMA`): *la conclusión aguantaba y la premisa que la
> sostenía era falsa*. **Esa rama se deja como está, de registro.**
>
> **Y dos que se corrigieron de paso, las dos del tipo que ningún test mira:** el bloque de las
> rutas nuevas **no cuadraba consigo mismo** —decía `HEAD` **567** y a la vez `543 + 26 − 1 = 568`—,
> y la nota de la comprobación de diez segundos seguía razonando en «OCHO migraciones» cuando
> `profesores.tono` ya no tiene fichero propio. La comprobación **en sí estaba bien**: hace
> **veinte preguntas** —diez columnas, ocho tablas, la fila de `permissions` y una columna que tiene
> que haber **desaparecido**—. Se reescribió su nota para que cuente **cosas y no migraciones**,
> que desde la consolidación no son lo mismo.
>
> *Los «quince» que seguían vivos pasan a **dieciséis** (ocho sitios). **Los fechados del despliegue
> del 31 ago se quedan en quince**: se midieron sobre quince, y lo que se actualiza es lo que sigue
> vivo.*
>
> **PENDIENTE, y es de quien cierre la tanda:** `8myvc-ff` mete el Lote G y luego funde
> **REABIERTO Y VUELTO A CERRAR una hora después, y la lección es la del día:** entró la **sexta
> ruta de `horario/`** (`GET horario/versiones/{id}/proyecto`, autorizada por Joseth) y `8myvc-ae`
> le actualizó a la tabla **la fila de rutas** (577 → 578) dejando el resto y el rótulo de
> `3970cea`. **Durante una hora la tabla mezcló dos hashes**: commits de las 16:57 y rutas de las
> 18:0x, bajo un encabezado que anunciaba un solo rango, y las dos cifras eran ciertas. *Una tabla
> con dos extremos es peor que una vieja: la vieja se remide, la mezclada se cree.* Remedida
> **entera** contra `d606839`: commits **315**, `app/` **57**, `routes/` **8**, migraciones
> **SIETE**, rutas **543 → 578** (**36** nuevas, 1 retirada). Van **ocho** cifras del rango.
>
> **Y el aviso O se quedó corto por segunda vez en la misma hora, ahora en el criterio y no en el
> recuento.** La corrección a 36 rutas decía *«DOS de `horario/` no bastan con `auth.personal`»* y
> son **TRES**: se le pasó `PUT horario/docentes/{profesor_id}/tono`, **que ya estaba nombrada
> veinte líneas más abajo del mismo documento con ese mismo criterio**. Contado sobre
> `HorarioController`: tres llamadas a `Autoriza::puedePublicarHorario` (:2614, :2649, :3050), ni
> una más — `putOficial`, `putTonoDocente` y `getProyecto`, o sea **superusuario o `Coord
> académico`, un rol con cero usuarios**. A un docente llano le contestan **403 aunque pase el
> guard**. *Contar rutas y contar quién puede llamarlas son dos censos distintos, y arreglar el
> primero no arregla el segundo.*
>
> `docs/barrido-profesor-serializado`. **CERRADO**: las dos entraron y las cinco cifras se
> remidieron enteras contra el hash de cierre **`3970cea`** a las 16:57, no se les sumó nada. Sólo
> se movió la de commits (**300 → 307**); `app/` **57**, `routes/` **8**, migraciones **SIETE** y
> rutas **577** salieron idénticas, porque el Lote G toca `HorarioController` y su test y nada más
> —comprobado, no supuesto—. La aritmética de rutas vuelve a cerrar sola: **543 + 35 − 1 = 577**.
**5 sep 2026 — EL SOBRE DE LA CUARTA RUTA SE ENSANCHA CON LAS CUATRO LISTAS DE LA DECISIÓN 38, Y
`tono` DEJA DE CONTAR SOBRE LA POBLACIÓN QUE NO ERA** · `HorarioController::getLecciones` y sus
lectores del blob, `HorarioLeccionesTest` (18 → 30 casos, 187 → 429 aserciones, los doce nuevos
vistos en rojo con nueve mutaciones), [23 §9.bis.6](23-horarios.md) y esta casilla · **el router no
se mueve: 577 antes y después, contado con `route:list --json` sobre la rama ya fusionada con
`main`** · pint PASS · larastan nivel 7 `[OK] No errors` · rama `feat/horario-cuatro-listas`

> **El 577 y el 568 son el mismo hecho y por eso van los dos.** Esta rama sale de `ac09cb7`,
> donde el router iba por **568**, y ahí se contó al escribir el código; con `main` dentro son
> **577**, porque la plantilla de notas trajo nueve. **Ninguna de las dos cifras la mueve este
> lote** — es el sobre de una ruta que ya existía—, y se dicen las dos porque *«no se mueve»
> sin decir desde dónde es la frase que hace que el siguiente reste mal.*

> **`GET horario/versiones/{id}/lecciones` manda cuatro claves más —`plantilla`, `jornadas`,
> `disponibilidad`, `sin_colocar`—, todas sacadas del fichero de proyecto que ya leía, cada una
> con su renglón en `catalogos` y `null` cuando no se entiende entera.** Conducido con `curl`
> contra la versión 8 del docker con token de admin y de profesor raso:
>
> ```
> plantilla       47 · con_leccion 12 · sin_leccion 35        <- por las lecciones sólo viajaban 12
> tono            parcial · 12 de 47                           <- decía completo · 12 de 12
> jornadas        4 niveles · 13 grupos, los 13 `porque: nivel`
> timbres         vacio · 0 de 5                               <- decía sin_catalogo, y era falso
> disponibilidad  26 de 47 con marcas · 134 (92 condicional · 42 inadecuado)
> sin_colocar     1 · piezas 301 · colocadas 300 · incompletas 3   (⊆, no ===)
> ```
>
> Coste: el método pasa de 6,3 a 8,3 ms y el sobre de 117 a 132 KB (7,6 → 9,5 KB en gzip).
>
> ### LO QUE HAY QUE SABER PARA NO DESHACERLO
>
> - **La población va dentro de cada renglón con su `criterio`, lo exige un test de los once, y
>   `tono` se mide sobre los docentes que viajan en la misma respuesta.** Ese test encontró de
>   paso que `salones` decía `87 de 312` sin decir de qué eran los 312.
> - **Dos vacíos que no eran vacíos, encontrados repasando el propio lote**: el de `salones` y
>   el del ternario de `plantilla`, que preguntaba primero por el total y devolvía `vacio`
>   —legítimo, sin llamada a la acción— cuando hay docentes dando clase que la plantilla no
>   declara. Ahora `parcial`. *Los dos son la forma de esta noche: una cifra correcta que se
>   lee como otra cosa.* Es lo que cierra la trampa del denominador de la
>   [§9.bis.5](23-horarios.md) —53 filas, 47 vivos, 12 con asignación— y lo que el lector del
>   front no podía comprobar: *una cuenta que cuadra sobre la población equivocada no falla*.
> - **Ninguna cadena libre del blob sale.** Nombres por id contra las tablas; enumerados cerrados;
>   hora `HH:MM` con expresión regular; el `pieza_id` es el único texto y va acotado a la forma
>   de su columna. **Una entrada rota tira la lista entera** y el renglón dice `ilegible`
>   nombrando la parte. El test de fuga mete la marca **dentro** de cada lista.
> - **`timbres` ya no es `sin_catalogo`**: las horas viajan dentro de cada jornada, así que la
>   frase pasó a ser falsa. Que el colegio no las haya dado es `vacio`. `restricciones` es el
>   único que sigue sin viajar por diseño.
> - **La casilla de abajo («el servidor no guarda la disponibilidad») queda superada por ésta**:
>   el motivo que corrigió —*«esta ruta todavía no la parsea»*— ya no es cierto tampoco, y el
>   renglón `disponibilidad` es un catálogo con población.
>
> ### BAJO QUÉ AUTORIZACIÓN ENTRA, CON EL ARGUMENTO
>
> La sustancia es la **decisión 38 de Joseth en `myvc_horarios`**, con su precio escrito (el
> permiso sigue `auth.personal` y lo declarado no está en ninguna pared). **Que entre aquí antes
> del 10 lo decidió `8myvc-ae` como coordinador**, y verificó la premisa antes: **cero migraciones,
> cero rutas, y `routes/api/horario.php` no existe en `9474b50`, lo desplegado** — ningún colegio
> tiene hoy la ruta, así que cambiar su forma no rompe nada; entrar después obligaría a los dos
> clientes a aguantar dos formas de la misma ruta para siempre. *«Que no se detengan» era una
> instrucción de ritmo, no una decisión sobre este lote.* Está en la §9.bis.6 con el argumento
> entero.
>
> ### LO SIGUIENTE
>
> - **Fundida detrás de `feat/plantilla-de-notas`**, como pedía la secuencia de `8myvc-ae` (la
>   plantilla trae dos de las siete migraciones del día 10 y va primera). El conflicto fue de una
>   sola casilla, la de arriba, contra la del ensayo de la tanda: las dos se insertaban en el
>   mismo punto y ninguna se pisa.
> - `myvc-horarios-66` y `myvc-front-f1` escriben su lector **sobre la respuesta conducida**; se
>   les manda el `curl`, no el controlador.
> - **No medido:** los otros quince colegios; la invariante de `quien-esta-libre` (es del
>   consumidor); `ilegible` por partes y los `porque` distintos de `nivel` con datos reales.

**5 sep 2026 — EL ENSAYO DE LA TANDA MEDÍA UN ÁRBOL Y LE PREGUNTABA A OTRO** ·
`tools/ensayo-de-la-tanda.sh` (punto 1.bis) y esta casilla · **el router no se mueve: 568** ·
ejercitado **por los tres lados** sobre la copia de `simonbolivar` (102 tablas, 210 MB, 1.166.139 notas)

> **La mitad de arriba del script le pregunta a `git` AQUÍ; `artisan` corre ALLÍ.** `PHP_EXEC` es
> un `docker exec`, y **sin `-w` trabaja en `/app` —el árbol principal— aunque llames al script
> desde un worktree**. Entonces la tanda se calcula con las migraciones de tu rama y el
> rebobinado se le pide a un árbol que no las tiene.
>
> **Lo caro no era fallar: era CÓMO fallaba.** Lo pagó `8myvc-24` la noche del 4 al 5 sep desde el
> worktree `p`: las migraciones de `main` rodaban, las suyas salían `Migration not found` y el
> script abortaba con un `NO MEDIDO` a secas. Eso **se lee como «la tanda está mal»** cuando lo
> que pasa es **«estás midiendo otro árbol»** — y quien lo lea a las tres de la mañana del día 10
> se va a ir a mirar sus migraciones, que están perfectamente.
>
> **Se compara por NOMBRES y no por recuento**, que es la única diferencia con el guard gemelo de
> `tools/construir-bd-test.sh`: dos árboles pueden tener veinte migraciones cada uno sin ser las
> mismas veinte, y con un recuento eso pasa el guard y muere después, otra vez sin decir por qué.
>
> ### Lo que se ejercitó — un guard que no se ha visto abortar no está probado
>
> ```
> árbol principal (main @ ac09cb7)        18 aquí · 18 allí (/app)              PASA    exit 0
> worktree, SIN -w                        20 aquí · 18 allí (/app)              ABORTA  exit 2
> worktree, CON -w /app/.worktrees/z      20 aquí · 20 allí (el worktree)       PASA    exit 0
> ```
>
> El aborto **nombra las dos migraciones que no existen al otro lado** —`alcance_de_la_plantilla`
> y `create_permiso_can_edit_plantilla_notas`— y sugiere la orden con la ruta buena. **Y no crea
> la copia**: el guard va antes del punto 2, así que un aborto no deja ninguna base `_ensayo`
> detrás — comprobado con `SHOW DATABASES` antes y después, que es la forma que tendría el
> destrozo si el orden estuviera al revés.
>
> **Las dos pasadas que pasan midieron entero, no sólo el guard**: **5 migraciones en 1.163 ms**
> (10.314 ms el comando) desde `main`, y **7 en 932 ms** (6.409 ms) desde el worktree puesto en la
> punta de `feat/plantilla-de-notas`; las dos con los dos controles saltando, el esquema idéntico
> a `simonbolivar` (1.528 columnas) y `LLEGO` en el módulo de horario. *La de siete es la tanda
> que quedará cuando esa rama entre, y ya está ensayada.*
>
> **Y un borde que se cerró de camino, porque era el mismo daño con otra cara:** la orden que se
> sugiere deriva la raíz de `git rev-parse --git-common-dir` —**`--show-toplevel` NO sirve aquí**,
> dentro de un worktree devuelve el propio worktree y la orden salía `-w /app`, justo el árbol
> equivocado que acababa de provocar el aborto—. Y si esa orden no contesta, `dirname` daba `.`,
> el `sed` se comía el primer carácter del `pwd` y salía `-w /appUsers/josethguerrero/…`, una ruta
> que no existe. Ahora dice que la ruta va a mano. **Se vio en rojo**, con un `git` falso en el
> `PATH` que hace fallar sólo esa llamada: un guard cuyo motivo entero es no mandar a nadie a
> mirar donde no es no puede permitirse imprimir una orden inventada.

**5 sep 2026 — LA TANDA DEL DÍA 10 ES DE SIETE Y ESTÁ ENSAYADA: 1.659 ms SOBRE 1,17 MILLONES
DE NOTAS** · `docs/DESPLIEGUE.md` (tabla, rollback y la comprobación), `tools/ensayo-de-la-tanda.sh`
(cabecera) y esta casilla · **cero código de la API, el router quieto en 577**

> Las dos migraciones de la plantilla entraron detrás de la consolidación de `8myvc-47`, así que
> **la tanda cambió de contenido y había que remedirla**. Remedida, no supuesta.
>
> | | |
> |---|---|
> | migraciones que entran | **7** — `git diff --name-only 9474b50 HEAD -- database/migrations/` |
> | corren | **7 de 7**, **1.659 ms** las migraciones · **4.569 ms** el comando |
> | población | copia de `simonbolivar`: 210 MB, 102 tablas, **1.166.139 filas en `notas`** |
> | delta real | **8 tablas nuevas**, **20 columnas nuevas** en 7 tablas, **1 retirada** |
> | la copia migrada contra el origen | idénticas: **1.528 columnas, mismo tipo** · pendientes **0** |
> | horario en la copia | `200` con `total: 0` y el `403` donde toca — **LLEGÓ** |
>
> **El 7 se contó, no se sumó.** `47` dejó 5 en `main` y yo traigo 2; 5 + 2 habría acertado el
> número **describiendo mal la tanda**, que es justo lo que avisa el bloque de rollback desde su
> fusión: **una migración son ahora varias columnas de varias tablas**, así que el `--step` y «lo
> que cambia» dejaron de ser intercambiables. El bloque de rollback pasa a `--step=7` y `tail -9`.
>
> ### LA COMPROBACIÓN DE DIEZ SEGUNDOS AHORA PREGUNTA POR LO QUE NO ES UNA COLUMNA
>
> Se le añadieron las dos columnas de `unidades_por_defecto` **y la fila
> `can_edit_plantilla_notas` de `permissions`**. Esa última es la que ninguna comprobación de
> esquema habría cazado y **es la que falla en silencio**: sin ella la pantalla de la plantilla
> funciona, pero **sólo para superusuarios** — el síntoma no es un error, es que a rectoría «no le
> sale el menú». El ensayo verifica la cobertura y dice *«de cada tabla que cambia pregunta al
> menos una cosa»*, que es la regla que nació el día que se le quedó fuera `profesores.tono`.
>
> > ⚠️ **Y esa línea del permiso dirá siempre `FALTA` contra una base de TESTS**, y no es un
> > fallo: `test-seed.sql` hace `TRUNCATE TABLE permissions` **después** de migrar. Medido sobre
> > `simonbolivar_testing_p`: las dos columnas aparecen y el permiso no, con 19 filas en
> > `permissions`. Se corre contra la base del colegio, no contra una de pruebas — está escrito
> > al lado de la comprobación para que nadie la «arregle».
>
> ### Y UNA TRAMPA DEL PROPIO ENSAYO, PAGADA AQUÍ
>
> **`PHP_EXEC` no lleva `-w` por defecto**, así que `artisan` corre en el árbol **principal**.
> Desde un worktree, las migraciones que sólo existen en tu rama salen **`Migration not found`**
> en el rebobinado y el ensayo aborta con `NO MEDIDO` — que **se lee como «la tanda está mal» y
> es «estás midiendo otro árbol»**, porque las de `main` sí ruedan y las tuyas no. Es la misma
> trampa que `construir-bd-test.sh` ya documenta **y detecta**; **ésta la detecta desde
> `ed542ff`** —punto 1.bis, ejercitado por los tres lados y visto abortar—, y la cabecera lleva
> la orden completa al lado.
>
> > *Cuando se escribió esta casilla todavía no la detectaba, y era cierta. **Se corrige en la
> > fusión y no se deja envejecer**: «hay que acordarse» es una instrucción, y una instrucción
> > no envejece a «hecho», envejece a mentira. Es la misma lección de la casilla de arriba, y
> > la primera vez que se aplica sin que nadie tropiece antes.*

**5 sep 2026 — «EL SERVIDOR NO GUARDA LA DISPONIBILIDAD» ERA FALSO, Y LA FRASE TENÍA UN
LECTOR HOY** · `HorarioController` (el veredicto de la subida y el renglón del catálogo) y
esta casilla · **el router no se mueve: 568** · pint PASS · larastan `[OK] No errors`

> **Dos cadenas decían que este servidor no guarda las disponibilidades declaradas. Las
> guarda.** Medido sobre el proyecto de la versión oficial (la 8):
>
> ```
> docentes en el blob ......... 47   (los 47 traen la clave `disponibilidad`)
> con marcas de verdad ........ 26
> marcas ..................... 134   92 `condicional` · 42 `inadecuado`
> ```
>
> Y eso vive en `horario_versiones.proyecto`, **que es una columna de esta base**. Lo que no
> hay es **tabla que consultar**, que es otra cosa.
>
> ### LA DIFERENCIA NO ES DE ESTILO: DECIDE QUÉ TRABAJO SE PIDE
>
> | la frase | lo que pide |
> |---|---|
> | *«el servidor no la guarda»* | **inventar un dato** — y eso se defiende poco |
> | *«la guarda y esta ruta no la parsea»* | **parsear el blob** — otro trabajo y otro coste |
>
> **Con la decisión 38 encima de la mesa, la frase de ayer empujaba al primero.**
>
> ### Y ÉSTA NO ERA UNA CADENA DORMIDA: TENÍA UN LECTOR HOY
>
> Es lo que la separa del `motivo` de `timbres` y del `:753`, que esperaban al despliegue.
> `escritorio/src/app/subir/veredicto.ts:212` imprime nuestro `porque` **verbatim** —no tiene
> copia propia del texto—, así que **la frase falsa se la estaba enseñando a un coordinador de
> colegio cada vez que alguien sube un horario**. Se arregla en su pantalla en cuanto cambia
> aquí, sin que ellos toquen nada.
>
> **Y el daño ya había salido de este repositorio.** `myvc-front-90` escribió y **commiteó**
> en su catálogo que las disponibilidades «no se guardan en el servidor», y con eso clasificó
> su informe como **no derivable**; al abrir el blob la categoría pasó a **«falta ruta»**, que
> es un cartel completamente distinto. *No fue un error de lectura suyo: se creyó un cartel
> nuestro.* Y su `servidor.md` repetía la frase citándonos, así que **la caducidad llegó a dos
> repositorios**.
>
> ### LA TERCERA DE LA MISMA ESPECIE EN DOS DÍAS, Y AHORA SE PUEDEN CONTAR
>
> `catalogos.timbres`, el veredicto `:753` de la jornada, y ésta. **Las tres decían dónde vive
> un dato y se leían como que el servidor no lo tiene**; las tres estaban en un renglón que
> parece cerrado por diseño. *Un `no_comprobadas` es donde una imposibilidad falsa vive más
> tiempo, porque nadie audita lo que ya se declaró imposible* — y de las tres, **ninguna la
> encontró un test: las tres las tropezó un cliente de frente.*

**5 sep 2026 — EL PRE-VUELO YA DICE CON QUÉ AÑO MIDIÓ DONDE SE LEE, Y NO SÓLO ARRIBA** ·
`tools/prevuelo-del-horario.php` y esta casilla · **cero código de la API, el router no se
mueve: 568** · larastan nivel 7 `[OK] No errors` · **sin Pint, a propósito**

> **El aviso existía y estaba en el sitio donde no se lee.** La cabecera imprime `año 2026
> (year_id 9, NO es el actual)` desde siempre — y **se pierde**, porque esta herramienta se
> lee de dos maneras que se comen justo esa línea: `| tail` en el bucle de los diecisiete, y
> el ojo que baja directo al veredicto. *Un aviso que sólo vive arriba no existe en el único
> momento en que hace falta.*
>
> **Lo que lo destapó**: correr el pre-vuelo con `--year=9` sobre `simonbolivar`. El 2026 no
> tiene **ni un docente asignado**, y el informe sale **completo y creíble** —13 grupos, 134
> asignaciones, ΣIH 345— con **los trece grupos imposibles** y `exit = 1`. Que es el mismo
> código que un colegio sucio de verdad: en el bucle del día 10, ese colegio entraría en el
> recuento como **mirado y sucio**.
>
> ### LO QUE **NO** SE TOCA, Y ESA ES LA MITAD DE LA DECISIÓN
>
> **El código de salida se queda en `1`.** El `2` es NO MEDIDO y aquí **sí se ha medido**: un
> año pasado o futuro es un año **legítimo de mirar** —hay colegios preparando el siguiente—.
> Mover el código movería a quien lo consuma para arreglar **una lectura**. *Lo que estaba mal
> no era el veredicto: era dónde se decía con qué año se sacó.*
>
> ### LO QUE CAMBIA, Y SON TRES COSAS PEQUEÑAS
>
> - **El aviso va al final, después del veredicto** — lo último que se imprime es lo único que
>   sobrevive a un `| tail`. Nombra el año, el `year_id`, la opción con la que se pidió, y
>   **desmiente el código de salida**: *«sale `1` igual»*.
> - **Sólo avisa si el año se pidió a mano.** Sin `--year` la herramienta coge el `actual` ella
>   sola y no hay nada que advertir.
> - **El CSV lleva `es_el_actual` y `year_pedido_a_mano`**, y no sobra ninguna: la primera dice
>   **qué se miró** y la segunda **por qué**. La cabecera que lo avisa **no viaja en el CSV**,
>   así que sin esas dos columnas una fila medida sobre un año vacío se lee, semanas después,
>   como un colegio con problemas.
>
> ### EL CONTROL PASA DE 14 A 21 FORMAS, Y LAS TRES PRIMERAS SON DE CALLARSE
>
> **La forma de mentir de este aviso es callarse**, y callarse de más **no se ve**: no falta
> nada en la pantalla, sólo un aviso que nadie echa en falta. Por eso las tres maneras
> legítimas de callarse se fijan una a una —sin `--year`, con `--year` sobre el actual, y el
> caso imposible por construcción— además de las cuatro de hablar. Y lo que se comprueba de la
> que habla **no es que diga algo**, sino que **nombre el año y desmienta el código de salida**:
> un aviso que no hace eso deja el informe igual de creíble, que es de lo que venía.
>
> *`AutopruebasDeLasHerramientasTest` lo ejecuta: 14 herramientas en verde, y ésta con **21
> formas** en vez de 14. **A este fichero no se le ha pasado Pint** —`tools/` no está en el
> script `pint` de `composer.json` y ninguna suite lo ejecuta, así que ahí Pint rompe en
> tiempo de ejecución sin poner nada en rojo—; larastan, que es el único que mira esa carpeta,
> sale `[OK] No errors`.*

**5 sep 2026 — EL ÚLTIMO `motivo` QUE AFIRMABA UNA IMPOSIBILIDAD, Y ERA FALSO POR LAS DOS
MITADES** · `HorarioController` (el veredicto de `postVersiones`) y esta casilla · **decisión
de Joseth** · **el router no se mueve: 568** · pint PASS · larastan nivel 7 `[OK] No errors`

> **El cabo que quedaba del quinto estado, y al medirlo salió peor de lo que se creía.** El
> veredicto que se guarda en cada subida decía:
>
> > *«NO COMPROBADA: la rejilla y los timbres viven en el fichero de proyecto, así que aquí no
> > se sabe si la franja cae dentro de la jornada del nivel ni si cruza un descanso.»*
>
> Se sabía que **la segunda mitad** había dejado de ser cierta —los descansos se leen desde el
> 4 sep—. **Medido el 5 sep sobre las siete versiones de `simonbolivar`, la primera tampoco lo
> era, y nunca lo fue:**
>
> ```
> proyecto.jornadaPorDefecto     -> {dias, franjas, timbres, descansosTras}
> proyecto.niveles[].jornada     -> lo mismo, para los CUATRO niveles del colegio
> ```
>
> **La jornada por nivel estaba en el blob desde el principio**, en el cuerpo de la misma
> petición que escribe esa frase. O sea que el veredicto afirmaba no tener un dato que venía
> **dentro de la propia subida que estaba juzgando**.
>
> ### LO QUE CAMBIA NO ES QUE SE COMPRUEBE: ES EL PORQUÉ DE QUE NO
>
> Sigue sin comprobarse y sigue diciendo `NO COMPROBADA`. **Lo que se corrige es que la razón
> era falsa**, y esa diferencia decide quién vuelve a preguntar: *«no puedo»* y *«no lo hago»*
> se leen igual en un veredicto y **sólo el segundo se puede resolver**. Es literalmente lo
> que le pasó a los descansos —llevaban dos días legibles y nadie los pidió porque el renglón
> de al lado decía que vivían en otro sitio—, así que esta frase estaba montando el mismo
> archivado para la comprobación de la jornada.
>
> **Y lo que falta ahora es una decisión, no un dato**: si una franja fuera de la jornada de
> su nivel **frena la subida (422) o sólo avisa**. El texto nuevo lo dice, para que la próxima
> persona sepa qué preguntar en vez de creer que no hay nada que preguntar.
>
> ### LO QUE SE ACEPTA AL TOCARLO, Y POR ESO LO DECIDIÓ JOSETH
>
> Ese texto **se guarda dentro del veredicto de cada subida**, así que cambia lo que registran
> las **futuras**; las siete que ya existen conservan la frase con la que se guardaron. *Eso
> no es un daño: es para lo que existe el veredicto guardado —decir lo que se opinó el día que
> se subió—.* Y por eso no se tocó por cuenta propia el 4 sep: no es aseo, es cambiar el
> registro hacia adelante.
>
> *`HorarioSubidaTest` sigue exigiendo que las tres reglas no comprobadas vayan **nombradas**
> —un `if` contra un dato que no se tiene pasa siempre, se ve verde y no comprueba nada—: 138
> casos y 1.165 aserciones de `--filter=Horario` en verde.*

**4 sep 2026 — EL QUINTO ESTADO: `ilegible`, EL ÚNICO DE LOS CINCO QUE ALGUIEN PUEDE
ARREGLAR** · `HorarioController` (`catalogosDeLaVersion`, `renglonDelProyecto` y
`proyectoDeLaVersion` nuevos), `tests/Contrato/HorarioLeccionesTest.php` (**18 casos, 187
aserciones**) y esta casilla · **decisión de Joseth** · **el router NO se mueve: 568** ·
pint PASS · larastan nivel 7 `[OK] No errors`

> **Salió de arreglar una frase, no de buscar un estado.** `catalogos.timbres` decía que la
> jornada *«vive en el fichero de proyecto (§4)»* — verdad que engaña, porque el fichero está
> **en la columna de al lado de la misma tabla**. Al reescribirla salió que **no había palabra
> para «lo tenemos guardado y no se deja leer»**.
>
> ### LOS CINCO, Y POR QUÉ EL CUARTO NO SERVÍA
>
> ```
> completo      lo guardamos y está todo
> parcial       lo guardamos y hay menos de lo que la versión usa
> vacio         lo guardamos, el colegio no creó ninguno, y es LEGÍTIMO
> sin_catalogo  esta API no puede saberlo POR DISEÑO
> ilegible      lo tenemos guardado y NO SE DEJA LEER  <- el quinto
> ```
>
> `sin_catalogo` es una frase **sobre el producto**: idéntica en los dieciséis colegios y que
> **no la arregla nadie desde la web**. *«El proyecto de este colegio no se deja leer»* es
> sobre **ese colegio y esa subida**, y **tiene arreglo: volver a subirlo**. Juntarlas habría
> sido `[]` contra `null` otra vez, con la agravante de que **tres renglones ya usan
> `sin_catalogo` con el primer significado**, así que el cuarto lo habría usado con otro sin
> avisar.
>
> **De los cinco es el único que cambia lo que la persona debería hacer** — los otros cuatro
> explícitamente no llevan llamada a la acción. *Ése es el argumento de que sea un estado y no
> un matiz dentro de otro.*
>
> ### CÓMO SE LLEGÓ, Y ES LO MEJOR DEL HILO: DOS PROPUESTAS, LAS DOS TUMBADAS POR SU AUTOR
>
> `myvc-front-b2` pidió un campo `porque` dentro de `catalogos.timbres`; yo propuse un renglón
> `catalogos.proyecto`. **Luego cada uno adoptó la del otro, y cada uno tumbó la que acababa de
> adoptar:**
>
> | la propuesta | quién la mató | por qué |
> |---|---|---|
> | renglón `catalogos.proyecto` | `b2` | diría la verdad **al lado de tres renglones que siguen diciendo «no viaja»** |
> | `porque` dentro de `sin_catalogo` | yo | mete «lo tenemos y está roto» **debajo de «por diseño no lo tenemos»** |
>
> **Y lo que decidió entre el estado y el campo no fue la limpieza: fue quién queda obligado.**
> Medido por `b2` en su repositorio: sus cuatro `Record<EstadoDeCatalogo, …>` —palabra, icono,
> color y frase— **dejan de compilar** con un valor más; con un campo al lado **no se rompe
> nada** y su panel pintaría, ante un proyecto ilegible, la palabra que hoy tiene para
> `sin_catalogo`: ***«No lo guarda el servidor»***, que es lo contrario de la verdad. `tsc`
> verde, pruebas verdes, pantalla mintiendo. *Con el estado obliga el compilador; con el campo
> se obliga el cliente a sí mismo.* **Puso el argumento en contra de su propia propuesta, y por
> eso vale.**
>
> *Su coste, medido por él: cuatro entradas que el `tsc` le exige **más una regla de
> `estado-catalogos.scss` que no le exige nadie** — la fila sale sin borde y no da error. **El
> coste de verdad es el que el compilador no señala, y es una línea de CSS.***
>
> ### LOS TRES CAMBIAN JUNTOS, Y ESO ES LA MITAD DEL DISEÑO
>
> `timbres`, `disponibilidad` y `restricciones` salen del blob, así que si el fichero no se lee
> **los tres son `ilegible` a la vez**: es un hecho **del fichero**, no de cada catálogo.
> Marcar sólo `timbres` —donde el front lo pidió primero— habría dejado a los otros dos
> diciendo «no viaja».
>
> **Y lo que NO contagia, con su caso propio:** `grupos`, `asignaciones`, `docentes`, `tono` y
> `salones` salen de **tablas**. Sin ese caso, marcarlos todos por descuido pasaría inadvertido
> y la pantalla diría que no se sabe nada de un colegio del que se sabe casi todo.
>
> ### EL BLOB SE DECODIFICA UNA VEZ, Y NO ES SÓLO POR LOS 2 ms
>
> `proyectoDeLaVersion()` se llama **una vez por petición** y su resultado alimenta las dos
> cosas: los descansos y el estado de los tres catálogos. Decodificarlo dos veces costaría
> otros 2 ms medidos **y, peor, las dos lecturas podrían discrepar** — que es la forma de la
> que salen dos verdades sobre el mismo hecho.
>
> ### LOS TRES CONTROLES, VISTOS EN ROJO
>
> | se rompe a propósito | qué cae |
> |---|---|
> | el quinto estado no se usa nunca (siempre `sin_catalogo`) | **1** de 18 |
> | contagia a los catálogos de tabla | **1** de 18 |
> | el blob por defecto de los tests vuelve al de mentira | **2** de 18 |
>
> **El tercero no es un control del código, es del andamio, y por eso importa.** El blob por
> defecto de este fichero era `{"proyecto":"<marca>"}` —con `proyecto` como **cadena**—, que
> antes daba igual y **desde hoy es un proyecto ilegible**: con él, *todos* los casos del
> fichero habrían corrido contra un colegio cuyo proyecto no se puede leer. **Verdes igual,
> midiendo otra cosa.** Ahora es un proyecto de verdad con la marca dentro, en `programa`,
> donde la busca el control de la fuga.
>
> ### ⚠️ Y LA SALVEDAD QUE ACOMPAÑA AL VERDE
>
> **Esta rama no se ha visto nunca con datos reales.** Las siete versiones de `simonbolivar`
> parsean, así que `ilegible` **tiene cero ejemplos** y vive atado por cuatro formas rotas a
> mano. Va escrito en el docblock del caso porque *comprobado que hoy no hace falta* no es
> *comprobado que funciona*, y unas pruebas verdes se leen dentro de seis meses como lo
> segundo.

**4 sep 2026 — LA LÍNEA GRUESA DEL RECREO YA VIAJA: `ejes.descansos_tras`, CON SUS TRES
ESTADOS** · `HorarioController` (`getLecciones`, `ejesDeLaVersion`, `descansosDelProyecto`
nuevo y el `motivo` de `catalogos.timbres`), `tests/Contrato/HorarioLeccionesTest.php`
(**16 casos, 161 aserciones**) y esta casilla · **el router NO se mueve: 568, contado con
`route:list --json`** · pint PASS · larastan nivel 7 `[OK] No errors`

> **El dato llevaba dos días dentro del blob que esta ruta ya leía, a un `json_decode` de
> distancia, y nadie lo pidió.** El camino es
> `proyecto.jornadaPorDefecto.descansosTras`, y las **siete** versiones reales de
> `simonbolivar` traen la misma jornada:
> `{"dias":[1,2,3,4,5],"franjas":7,"timbres":null,"descansosTras":[3,5]}`.
>
> **Por qué nadie lo pidió, que es el hallazgo de verdad**: el renglón `catalogos.timbres`
> decía *«la rejilla, los timbres y las jornadas por nivel viven en el fichero de proyecto
> (§4)»*. **Es verdad y engaña.** Describe **de dónde viene el dato** y se lee como **que
> el servidor no lo tiene** — y el fichero está en la columna de al lado de la misma tabla.
> Quien leyera eso archivaba la pregunta. Lo levantó `myvc-front-b2` **abriendo el blob, no
> leyendo código**, y `myvc-front-c0` señaló la frase.
>
> ### LOS TRES ESTADOS, QUE SON EL LOTE ENTERO
>
> | el proyecto dice | viaja | qué significa |
> |---|---|---|
> | `descansosTras: [3,5]` | `[3, 5]` | dónde van las líneas gruesas |
> | `descansosTras: []` | `[]` | **el colegio no descansa** — un dato afirmativo |
> | no hay clave · el blob no parsea · no es lo que se espera | `null` | **no lo sabemos** |
>
> Aplastar las dos últimas a `[]` convertiría «no lo sé» en «no hay recreo» y el front
> pintaría una parrilla corrida **con toda la confianza del mundo**, sin que nadie recibiera
> un error. Es la misma distinción que sostiene `vacio` contra `sin_catalogo`, y la razón de
> que esta ruta tenga cuatro estados de catálogo y no dos.
>
> **Base 1 y «tras»**: un `3` es *después de la tercera lección*, no *en la tercera*.
>
> ### EL COSTE, MEDIDO ANTES Y DESPUÉS — Y LA OPCIÓN QUE PARECÍA BARATA ES LA CARA
>
> `ejesDeLaVersion()` no abría el blob: los ejes salen de las lecciones. Esto le mete un
> `json_decode` de hasta 129.550 bytes, así que se midió sobre la versión 7 de
> `simonbolivar`, 200 repeticiones:
>
> | | |
> |---|---|
> | la consulta de la versión, sin el blob | **0,78 ms** |
> | + traerlo + `json_decode` en PHP | **2,86 ms** (+2,07) |
> | `JSON_EXTRACT` dentro del `SELECT` | **5,42 ms** (+4,64) |
>
> **La columna es `mediumtext`, no `json`**, así que MySQL no tiene un documento parseado
> que indexar: reconstruye el JSON entero en cada llamada y lo hace **más despacio que PHP**.
> *Un `JSON_EXTRACT` sobre una columna de texto no es una lectura barata: es un `json_decode`
> en el otro lado del cable.* Los +2,07 ms van sobre una ruta que tarda **21,9 ms** y
> devuelve **114 KB** con 312 lecciones: **+9%**, y se paga.
>
> **La salida que NO se tomó**: guardar `descansos_tras` en una columna al subir. Toca
> `postVersiones`, toca migración —con la tanda del día 10 ya ensayada— y deja las **siete
> versiones que ya existen con `null` para siempre**, o sea peor que el coste que venía a
> evitar. *No era la misma decisión con menos trabajo: era otra decisión.*
>
> ### LOS CINCO CONTROLES, Y EL QUINTO NO LO BUSCABA NADIE
>
> | se rompe a propósito | qué cae |
> |---|---|
> | «no lo sé» aplastado a `[]` | **3** de 16 |
> | se cae la clave `descansos_tras` | **3** de 16 |
> | la lista con basura se filtra en vez de salir `null` | **1** |
> | la cadena ingenua `$blob[…][…][…] ?? null` | **1** |
>
> **Y el hallazgo del cuarto control, que corrige lo que yo mismo iba a escribir:** la cadena
> ingenua **NO revienta la ruta** —el `??` sobre un offset de cadena devuelve `null` y ya
> está—, así que *«si no compruebas las formas, se cae»* **era falso** y no es el argumento.
> Lo que sí hace es **devolver tal cual lo que hubiera ahí**, y ahí puede haber cualquier
> cosa: el blob lo escribe un programa de escritorio. O sea que el campo se convertiría en
> **una puerta de salida del fichero de proyecto con nombre de dato**, justo en la ruta cuyo
> contrato es que *mirar no es llevarse* (decisión 12, §9.bis).
>
> **Y el test que ya vigilaba la fuga no lo habría visto**: su marca vive en
> `programa`, fuera de `descansosTras`. Por eso entra un caso más —`la_comprobacion_de_forma_tambien_cierra_la_fuga`—
> que mete la marca **dentro del propio valor**, que es el único sitio donde la cadena
> ingenua la dejaría salir. Con él, el quinto control cae por la aserción de la fuga.
>
> *Comprobar la forma se escribió por corrección y resultó ser una guarda de seguridad. Se
> deja escrito porque el motivo bueno no era el que se pensaba.*
>
> ### LO QUE NO SE HACE, Y NO POR FALTA DE GANAS
>
> - **Las horas de reloj.** `timbres` sigue `null` en los siete proyectos: el colegio no las
>   ha dado. `years.minu_hora_clase = 50` es **cuánto dura** una lección, no a qué hora
>   empieza, y con `descansosTras` sin `timbres` no se sabe ni cuánto dura el recreo. **La
>   línea gruesa sí; la hora no.**
> - **No se recorta contra `franjas`.** `franjas` son las que *esa versión* usa y el proyecto
>   declara la jornada del *colegio*: un descanso tras la 7 en una versión que llega a la 5
>   es legítimo. Filtrarlo escondería el dato en vez de dejar comprobarlo.
>
> ### ⚠️ DOS COSAS QUE ESTE LOTE DESTAPA Y NO TOCA
>
> 1. **`catalogos.timbres` ya podría distinguir `vacio` de `sin_catalogo`.** El blob dice
>    `timbres: null` —el colegio no los dio—, y eso ya no es «esta API no puede saberlo».
>    Hoy se queda en `sin_catalogo`, que es **defendible pero ya no es forzoso**. Es una
>    decisión, no un arreglo.
> 2. **El `motivo` de la comprobación de subida (`HorarioController:753`) tiene la misma
>    forma**: dice que *«aquí no se sabe si la franja cruza un descanso»*, y desde hoy en
>    parte sí se sabe. **No se toca**: ese texto se **guarda** dentro del veredicto de cada
>    subida, así que cambiarlo cambia lo que registran las subidas futuras — y el veredicto
>    guardado existe precisamente para no perder lo que se opinó el día que se subió.
> 3. **Y una pregunta ABIERTA del front, que llegó después de escribir el código**: hoy los
>    tres `null` posibles —no hay clave, el blob no parsea, la forma no es la esperada— son
>    **un solo `null`**, y yo recomendé juntarlos porque *para pintar una línea son
>    idénticos*. `myvc-front-c0` trajo el precedente que yo no podía ver: **en esa casa ya se
>    decidió dos veces al revés** —`comprobaciones.ts` y el listado de `90` separan «no vino»
>    de «vino y no se pudo leer»—, así que juntarlos aquí deja **dos gramáticas para la misma
>    idea** en la misma pantalla. Lo decide `myvc-front-b2`, que es quien pinta.
>
>    **El dato que le hace falta para decidirlo sin teorizar: hoy ese caso tiene CERO
>    ejemplos.** Las siete versiones reales parsean, las siete traen `jornadaPorDefecto` y
>    las siete traen `descansosTras: [3,5]`. Y si dice que sí, **la propuesta no es meter un
>    cuarto estado en `descansos_tras`**: es un renglón `catalogos.proyecto` con la forma de
>    sus vecinos —`estado` + `motivo`—, porque *«el proyecto no se pudo leer»* es una
>    afirmación sobre **el conjunto** y no sobre los descansos: si el blob no parsea, tampoco
>    se sabrá nada de lo próximo que salga de él. **El momento barato de añadirlo es antes de
>    que la pantalla se escriba.**
>
>    **CERRADO con `myvc-front-b2` el 4 sep 2026, y lo que queda es UNA decisión de Joseth
>    —no de forma.** El camino hasta ella importa porque los dos nos tumbamos mutuamente y
>    cada propuesta murió por el mismo motivo: **`b2` pidió un discriminante `porque` dentro
>    de `catalogos.timbres`; yo propuse un renglón `catalogos.proyecto`; luego cada uno
>    adoptó la del otro. Las dos estaban mal, y por la misma razón.**
>
>    - **El renglón `catalogos.proyecto` decía la verdad al lado de tres renglones que
>      seguían mintiendo**: con el blob ilegible, `timbres`, `disponibilidad` y
>      `restricciones` seguirían diciendo «no viaja» —afirmación sobre **el diseño de la
>      API**— cuando lo cierto es «no se pudo leer» —sobre **ese colegio**—. Lo vio `b2`.
>    - **El `porque` dentro de `sin_catalogo` metía «lo tenemos y está roto» debajo de «esta
>      API no puede saberlo por diseño»**, que es `[]` contra `null` con otro traje. Lo vi
>      yo, y tumba también mi propia versión anterior.
>
>    **Los dos esquemas convergen en cuanto existe la palabra que falta**, y ésa es la
>    propuesta: **un quinto estado, `ilegible`, en los tres renglones que salen del blob.**
>    Sin renglón nuevo —sería el mismo hecho dicho dos veces, con el riesgo de que se
>    separen— y sin campo nuevo.
>
>    **Y por qué es un ESTADO y no un matiz dentro de otro, que es el argumento que decide:**
>    de los cinco, `ilegible` sería **el único que acusa a un fichero concreto y tiene
>    arreglo** —volver a subir el proyecto—, o sea el único que **cambia lo que la persona
>    debería hacer**. `vacio` es legítimo y `sin_catalogo` no lo arregla nadie desde la web:
>    los cuatro de hoy explícitamente no llevan llamada a la acción.
>
>    **El coste, medido por `b2` en su repositorio y corregido a la baja sobre mi
>    estimación:** cuatro `Record<EstadoDeCatalogo, …>` —`PALABRAS`, `ICONOS`, `COLORES`,
>    `FRASES_GENERICAS`— que **dejan de compilar** con un valor más, por diseño explícito de
>    su cabecera; más **una regla de `estado-catalogos.scss` que no exige nadie** y cuya
>    ausencia deja la fila sin borde sin dar error. *El coste de verdad es el que el
>    compilador no señala, y es una línea de CSS.*
>
>    **Hoy tiene CERO ejemplos**: las siete versiones reales de `simonbolivar` parsean, las
>    siete traen `jornadaPorDefecto` y las siete traen `descansosTras: [3,5]`. `b2` dice que
>    **no le bloquea** y que pinta la línea igual.
>
>    **La pregunta que sube a Joseth es «¿los cuatro estados admiten un quinto que acusa a
>    una subida concreta?», no «¿añadimos un campo?»** — la primera es de producto y la
>    segunda de forma, y sólo la primera necesita su decisión.
>
>    **Y el argumento definitivo lo puso `b2` sobre su propia propuesta, en su contra.** Al
>    revisar el `porque` dentro de `sin_catalogo` —que él mismo había vuelto a aceptar— midió
>    lo que le costaba de verdad: **con un quinto estado sus cuatro
>    `Record<EstadoDeCatalogo, …>` se ponen rojas y no puede olvidarse; con `porque` no se
>    rompe ninguna**, y su panel pintaría para un proyecto ilegible la palabra que hoy tiene
>    puesta para `sin_catalogo` — ***«No lo guarda el servidor»***, que es **exactamente lo
>    contrario de la verdad**: sí lo guarda, y por eso hay algo que arreglar. `tsc` verde,
>    pruebas verdes, y la pantalla diciendo lo que no es. *Es el hueco silencioso del `motivo`
>    de `timbres` otra vez, esta vez del lado del cliente.*
>
>    Él lo cierra por su cuenta —colapsa `estado` + `porque` en un discriminante local de
>    cinco valores y tipa sus tablas contra ése, sin pedir que la API cambie—, así que **nada
>    de esto bloquea**. Queda escrito porque cambia el argumento: *el motivo para preferir el
>    quinto estado no es que sea más limpio, es **quién queda obligado**. Con el estado obliga
>    el compilador; con el discriminante se obliga el cliente a sí mismo.*
>
>    ### ✅ DECIDIDO POR JOSETH, 4 sep 2026: **SÍ, el quinto estado `ilegible`**
>
>    Con el reencuadre delante y sabiendo que hoy tiene **cero ejemplos** y que **no bloquea
>    a nadie**. **No entró en aquel commit**: se llamaba «los descansos», y un estado nuevo en
>    un contrato es su propio trabajo con sus propios controles. **Hecho justo después — la
>    casilla de arriba.**

**4 sep 2026, noche — `acepto_perder` NO ES UNA CONFIRMACIÓN, ES UN PEAJE — Y LA §7.2 SE
CONTRADECÍA CONSIGO MISMA** · [`23-horarios.md` §7.2](23-horarios.md) y esta casilla · **cero
código, el router no se mueve: 568** · lo destapó una publicación accidental de `myvc-front-90`

> **El apartado ya tenía el contrato bien escrito y aun así indujo el fallo.** Su tabla dice, en
> la primera fila, *«sin `acepto_perder` · 0 · **200**, publica»*. Cinco párrafos más abajo el
> mismo apartado afirma **sin condición** que *«hay una persona en medio cada vez»*. Las dos
> cosas no caben juntas, y quien se equivocó **leyó el párrafo, no la tabla**.
>
> `HorarioController.php:1457` es `if ($aceptoPerder === null && $sePierden !== 0)`: con cero
> pérdidas y sin la clave, la condición es falsa y **cae directa al `UPDATE`**. O sea que
> publicar una versión **limpia** es **una** llamada sin ninguna puerta, y una **sucia** son
> dos. La garantía existe **sólo cuando hay algo que perder**.
>
> ### Cómo se destapó, y por qué el susto era menor de lo que parecía
>
> El carril «publicar» de `myvc_front` condujo su pantalla dando por hecho que el primer paso no
> escribe, y mandó `PUT horario/versiones/5/oficial` con **`{}` de cuerpo entero** → **200**, y el
> puntero del año 8 se movió de la 6 a la 5 sin confirmación. **Le pasó a quien había escrito la
> frase correcta en su propio repositorio.**
>
> **La primera lectura fue «la base cambió debajo» y era falsa.** Medido antes de reaccionar: las
> versiones **5 y 6 son idénticas**, las 312 filas una a una, **incluyendo franja, duración y
> salón** — que es justo lo que `tools/deriva-del-horario.php` NO mira, y lo único que habría
> hecho cierta la alarma. El `UPDATE` reescribió las siete columnas **con los valores que ya
> estaban**: *destruye el reloj, no el dato*. Devolver el puntero a la 6 fue **higiene, no
> reparación**, y así se le dijo a Joseth antes de que lo autorizara.
>
> **Y la lectura de deriva de las 19:03 —0 descuadradas, exit real 0, con 23 h de reposo
> detrás— NO se tiró**, porque el estado que midió no había cambiado. Tres sesiones habían
> pedido tirarla.
>
> ### Lo que NO se capturó, dicho para que nadie lo invente
>
> **El cuerpo de aquel 200 no existe en ningún sitio.** Pasó por el `HttpClient` de la pantalla, y
> `myvc-front-90` **se negó a reconstruirlo** desde la forma que devuelve el controlador: sería
> meter una medición inventada en el fichero donde se guardan las medidas. Lo que sí va escrito es
> otro 200 del mismo caso limpio —el `PUT` de la restauración, con `curl` y cuerpo `{}`— **con la
> salvedad de quien lo midió pegada a él**: demuestra *«sin la clave y sin pérdidas, escribe»*, no
> narra el accidente.
>
> **`putOficial` no se toca y no se propone tocarlo.** El peaje está bien pensado y el 200 del
> caso limpio puede ser la decisión correcta. **Lo que faltaba era que estuviera dicho.**
**4 sep 2026 — LA PLANTILLA DE NOTAS SALE DE phpMyAdmin: LAS NUEVE DE `plantilla-notas`,
EL ALCANCE DE PREESCOLAR Y EL ROUTER EN 577** · `PlantillaNotasController`,
`App\Support\AlcanceDeLaPlantilla`, `routes/api/plantilla.php`, **dos migraciones**,
`UnidadesController`, `YearsController`, `Autoriza`,
`tests/Contrato/PlantillaNotasTest` (**21 casos**) y
`tests/Contrato/AlcanceDeLaPlantillaTest` (**9 casos**), `CLAUDE.md`,
[28](28-competencias-e-indicadores.md) y **los tres snapshots** · **577 rutas, contadas
con `route:list --json`** · pint PASS · larastan nivel 7 `[OK] No errors`

> **La Entrega 1 y la 7(a) del [28](28-competencias-e-indicadores.md), juntas.** Hasta hoy
> `unidades_por_defecto` y `subunidades_por_defecto` **se editaban a mano en la base**: ésa
> es la frase entera del problema que esto cierra.
>
> ### POR QUÉ VAN JUNTAS, Y NO ES POR COMODIDAD
>
> La 7(a) sola son **dos columnas que no puede escribir nadie** —la única pantalla que crea
> filas de plantilla es la Entrega 1—, o sea el caso `profesores.tono` de ayer **otra vez**,
> con la única diferencia de que esta vez se vio **antes** de escribirlo. Y la Entrega 1
> sola le siembra la plantilla de una fila de preescolar **a todo el bachillerato**. Joseth
> aprobó el lote con el precio delante: **9 rutas, 2 migraciones, el arreglo de
> `YearsController` y el router de 568 a 577**.
>
> ### LO QUE NO ENTRÓ, DICHO AQUÍ Y NO EN UNA NOTA AL PIE
>
> **El candado del docente (decisión 5) NO está.** El documento dice «entra con la Entrega
> 1, no después» y **tiene razón en el orden y aun así no se puede cumplir hoy**: cambia
> respuestas de éxito por **403 en nueve rutas vivas** que notan los tres editores, y la
> §5.1.e exige contar antes el censo de `por_defecto = 1` **en los diecisiete colegios** —
> que no se corre desde una sesión de desarrollo, hace falta el servidor. Lo que había que
> decidir no era si se pone sino **si se corre el censo antes o se acepta ponerlo a ciegas**,
> y **Joseth eligió el censo** (decisión 14, cerrada el 4 sep 2026). O sea que **el candado
> es trabajo del día del despliegue**, con el bucle de `DESPLIEGUE.md`, y no de una noche de
> código.
>
> ### DOS HALLAZGOS QUE NO ESTABAN EN EL DOCUMENTO
>
> **1. `YearsController:278` dejaba escapar el alcance, y el síntoma habría sido el
> contrario del que se busca.** Ese `INSERT` copia la plantilla al año siguiente **nombrando
> columnas**. Sin añadir las dos nuevas no habría sido «no se copia el alcance», que se
> nota: habrían nacido a NULL, y **NULL significa «a todos»** — la plantilla de una fila de
> preescolar sembrada en enero **en todo el bachillerato**, con un 200 y sin un error en
> ningún log. Es el **hermano por el lado contrario** del fallo de la §1.bis, que hacía
> llegar la plantilla **vacía**. Los dos son mudos y los dos aparecen en enero.
>
> **2. La rejilla de indicadores (7c) está BLOQUEADA por una contradicción del propio
> documento.** §5.7.c dice que marca con «el mismo `indicador_id` que ya propone §5.3»;
> §5.3 pone esa columna en **`subunidades`**, que es otra tabla, y la declara *decisión
> aparte*. Comprobado contra el volcado: **`frases_asignatura` no tiene `indicador_id`** —
> sólo `frase_id` (→ `frases`, el catálogo que §5.3 descarta) y `frase`. Las dos salidas y
> la recomendación quedan escritas en §5.7.c; **es la decisión 15, abierta**.
>
> ### EL CONTROL QUE NO SE PUSO ROJO, QUE ES LO QUE HAY QUE CONTAR DE ESTA NOCHE
>
> | control | qué cae |
> |---|---|
> | quitar `puedeEditarPlantillaNotas` del controlador | **9** de 21 |
> | quitar el filtro de alcance del sembrador | **7** de 9 |
> | `YearsController` sin copiar las dos columnas | **1**, el de la fuga |
> | quitar la regla 5 (`alumno_id IS NULL`) de `sembrar` | **1** — *a la segunda* |
>
> **El cuarto salió VERDE la primera vez, con el candado quitado.** El caso del boletín
> independiente usaba la primera asignatura del seed, que ya tiene rejilla y notas: `sembrar`
> la saltaba por `saltadas_por_notas` **antes de llegar a la regla 5**. O sea que el test
> llevaba media hora afirmando que protegía el reparto de un independiente y lo que medía
> era su propio nombre. Reescrito para montarse una asignatura limpia, cae con el mensaje
> correcto. *Un control que no se corre no es un control, y uno que sale verde hay que mirar
> por qué.*
>
> ### ⚠️ Y UNA CIFRA DE ESTA MISMA MAÑANA QUE HA ENVEJECIDO, DICHA Y NO ARREGLADA
>
> La casilla de más abajo dice **«568 de 568 — 100 %»** de cobertura de rutas. El router
> está en **577**. Las nueve nuevas las cubre `PlantillaNotasTest`, **pero eso lo dice esta
> casilla y no `cobertura-de-rutas.py`**: la pasada entera se corrió sin `COBERTURA_RUTAS`.
> Así que a día de hoy el 100 % **está sin comprobar desde que entraron las nueve**, y se
> escribe así en vez de darlo por bueno.
>
> **Se remide el día del despliegue y no ahora**, y no por tiempo: la medición útil es con
> esta rama **y** la de las migraciones dentro de `main`, contando con `route:list --json`
> sobre `main`. Medirla sobre una rama da un dato que caduca al fusionar.
>
> ### TRES COSAS QUE EL DISEÑO NO TENÍA Y EL CÓDIGO SÍ NECESITÓ
>
> - **El 422 del porcentaje es por grupo de alcance, no uno.** Desde que una fila puede ir
>   dirigida, la suma que debe dar 100 **ya no es una**: comprobar la de la tabla entera daría
>   200 en cuanto un colegio tenga una plantilla general y otra de preescolar, **las dos
>   correctas**.
> - **`sembrar` devuelve siete contadores, y uno cambió de nombre.**
>   `saltadas_por_independiente`, que proponía la §5.1.c, **valdría cero siempre** —las filas
>   con dueño no hacen saltar nada, que es justo la regla 5—, y un contador que no puede subir
>   no dice si la regla llegó a correr. En su lugar, `independientes_respetadas`.
> - **La precedencia necesitaba una decisión más**: «gana la más específica» es ambiguo con
>   **dos** ejes. Se resolvió con cuatro gradas, aplicando **la grada entera** —mezclar dos da
>   un reparto de 200 que nadie escribió— y con el **nivel ganando a la materia**, que es la
>   única parte discutible y está argumentada en §5.7.a.

**4 sep 2026 — LAS OCHO MIGRACIONES SIN DESPLEGAR, CONSOLIDADAS EN CINCO** · rama
`fix/consolidar-migraciones` · `database/migrations/` (−3 ficheros), `docs/DESPLIEGUE.md`,
`docs/migracion/23-horarios.md`, `docs/migracion/22-nivelaciones.md`,
`app/Http/Controllers/PromovidosController.php`, `app/Models/Profesor.php`,
`tools/ensayo-de-la-tanda.sh` y esta casilla · **cero columnas nuevas, el router no se
mueve: 568**

> Lo pidió Joseth —*«sólo si es fácil»*— y lo repartió `8myvc-7c`. **Se puede hacer sólo
> porque estas ocho no se han desplegado nunca**: las trece que sí están en los diecisiete
> no se tocan jamás, porque editarlas no re-ejecuta nada y la divergencia sería muda.
>
> | | |
> |---|---|
> | ficheros | **8 → 5** |
> | `2026_09_02_200000_nivelacion_de_la_definitiva` (A8) | fusionada en `2026_09_02_100000_nivelaciones_columnas` |
> | `2026_09_02_300000_acta_de_la_recuperacion_final` (A9) | ídem |
> | `2026_09_04_200000_tono_del_docente` | fusionada en `2026_09_04_100000_horario_versiones` |
> | solas, y por qué | `2026_09_03_100000_rubricas` y `2026_08_31_200000_puestos_con_bol_independiente` (dominios propios); **`2026_08_31_100000_retirar_boletin_independiente_de_matriculas`**, la única destructiva |
>
> **Los tres nombres de arriba ya no existen como fichero.** Las entradas viejas de este
> diario que los citan **se dejan como están** —eran ciertas el día que se escribieron— y
> esta tabla es lo que las resuelve.
>
> ### Qué se comprobó, y no fue leyendo el diff
>
> Se construyeron **dos bases desde cero** —una desde `main` con las 21 migraciones, otra
> desde la rama con 18— y se compararon en `information_schema`:
>
> | | |
> |---|---|
> | columnas, con su `ORDINAL_POSITION` | **1.526 = 1.526**, diff vacío |
> | índices y foráneas | diff vacío |
> | tablas | **102 = 102**, y las dos con 2.351 usuarios |
> | suite entera | ver el pie de esta casilla |
>
> El orden **físico** era el riesgo real y no el evidente: la vieja `..._200000` metía
> `notas_finales.nota_nivelacion` **entre** dos columnas que la `..._100000` acababa de
> crear (`after('nota_original')`). Declararlas juntas en el orden «natural» las habría
> dejado en otra posición, y esto es un proyecto que lee con `SELECT *` por todas partes y
> fija el orden de los campos en instantáneas de contrato: **no habría fallado la
> migración, habrían fallado las instantáneas — o peor, no habrían fallado y el front
> habría recibido los campos movidos.**
>
> ### Lo que la fusión GANA, que no es tener menos ficheros
>
> `notas_finales` pasa de **dos `ALTER` a uno**. Las tres cabeceras originales pedían
> *«UN `Schema::table` por tabla»* y entre ficheros se contradecían. En MySQL 8.0 da igual
> —`ADD COLUMN` es `INSTANT`—, pero **nadie sabe qué MySQL corren los diecisiete** y en 5.7
> cada sentencia reconstruye la tabla entera: **4.870 ms contra 11,8**, medido. Es una
> reconstrucción menos de `notas_finales` en el peor caso, o sea reducción de riesgo del
> día del despliegue.
>
> La del horario **no gana nada mecánico** y está dicho en su cabecera: `years` y
> `profesores` son tablas distintas, eran dos `ALTER` y siguen siendo dos. Es recuento, y
> Joseth la pidió sabiéndolo.
>
> ### ⚠ Y de camino salió que una fila de `DESPLIEGUE.md` NACIÓ MAL
>
> Decía que `2026_09_04_200000_tono_del_docente` entraba con `AFTER regla_nivelacion`.
> **Esa migración no tenía ni un `->after()`** —su cabecera lo declaraba como decisión— y
> `tono` va sobre `profesores`, mientras `regla_nivelacion` es de `years`: el `AFTER` no era
> ni expresable. La que sí lo lleva es `2026_09_04_100000_horario_versiones`, y `23 §11.2`,
> que aquella fila citaba, **lo decía bien**.
>
> **No es una caducidad: nunca fue cierto**, y por eso no la arreglaba remedir el día del
> despliegue. Es de la especie que `23 §11.5` no tenía catalogada — las otras tres describen
> el servidor y fallan contra él; **ésta no falla contra nada**: si despliegas en orden
> funciona, y si no, el error acusa al fichero equivocado. Corregida en su sitio **con el
> `grep` delante**, para que nadie la «restaure» dentro de dos meses.
>
> Se había propagado: `8myvc-7c` la repitió en el reparto de este lote y se la pasó a Joseth
> y a otras dos sesiones, y `myvc-front-c0` la recibió por otro lado. Avisadas las dos.
> *Dos fuentes que discrepan son un hallazgo.*
>
> ### La trampa que nace CON la fusión, y va escrita en el fichero
>
> Al conservar el nombre más antiguo de cada grupo, **una base que tenga aplicada la vieja
> `2026_09_02_100000` y no las otras dos queda inalcanzable por nombre**: `migrate` la ve
> `Ran` y sus columnas nuevas no llegan nunca. La salida es **reconstruir, no migrar**.
> A los diecisiete colegios no les afecta (no tienen ninguna de las ocho);
> **`simonbolivar_testing_h` está exactamente así** desde antes de esto. Y `simonbolivar`,
> la de desarrollo, queda con **tres filas fantasma** en `migrations` apuntando a ficheros
> que ya no existen: inofensivas, porque `migrate:status` sólo lista las que tienen fichero.
>
> ### Lo que NO se tocó
>
> `tools/construir-bd-test.sh` y `tools/ensayo-de-la-tanda.sh` **no llevaban el número
> escrito**: los dos lo derivan (`ls database/migrations/*.php` y
> `git diff --name-only 9474b50 HEAD`), así que se ajustaron solos a 18 y a 5. Del ensayo
> sólo se movió la prosa, que decía «las ocho» en siete sitios.
>
> **Y una herramienta que no existe y hoy hizo falta**: nada en el repo caza un **nombre de
> migración muerto**. `tools/secciones-citadas.py` persigue §§, no ficheros. Los ocho sitios
> que citaban los tres nombres retirados —dos de ellos en `app/`— se encontraron con `grep`
> a mano. Propuesta a `8myvc-7c`, fuera de este lote.

**4 sep 2026 — EL ENSAYO DE LA TANDA YA SE PUEDE REPETIR, Y DE PASO TRAE UN DETECTOR DE
COMPROBACIONES CORTAS** · `tools/ensayo-de-la-tanda.sh` y `tools/comprobar-el-horario.php`
(nuevos) y esta casilla · **cero código de la API, el router no se mueve: 568** · larastan
nivel 7 `[OK] No errors`

> **`8myvc-06` midió las ocho migraciones en ~1,0 s sobre una copia de `simonbolivar` y
> luego borró la copia.** Lo que quedó fue una cifra sin forma de volver a sacarla: ni el
> estado de partida, ni cómo se rebobinó, ni qué controles saltaron. *Una cifra que nadie
> puede repetir no es una medición, es un recuerdo.* Ahora es un script.
>
> ### LO MEDIDO HOY, y la población va delante
>
> | | |
> |---|---|
> | copia de | `simonbolivar` — **102 tablas, 210 MB, 1.166.139 notas**, 127.887 `notas_finales` |
> | las ocho, sólo las migraciones | **1,0–1,5 s** (tres corridas: 1.059, 1.318 y 1.531 ms) |
> | el comando entero, con el arranque de Laravel | **1,4–2,0 s** |
> | la más cara, siempre | `2026_09_03_100000_rubricas`, **0,57–0,92 s** — cinco tablas |
> | copiar la base | **22–25 s**, que es el 95% de la corrida |
>
> **La que manda para la ventana del colegio es la segunda**: lo que el colegio está caído
> es lo que tarda el *comando*, no la suma de los renglones que imprime Laravel.
>
> ### EL REBOBINADO TIENE DOS TRAMPAS, Y LAS DOS DAN UN VERDE FALSO
>
> - **`migrate:rollback --step` cuenta MIGRACIONES, no lotes** —al revés que `--step` de
>   `migrate`—. Con `--step=1` se deshace **una**, y el ensayo mediría **una de ocho**
>   creyéndose completo.
> - **Los lotes no cuadran con la tanda**: en esta base el lote 14 lleva dentro
>   `2026_08_30_200000_notas_finales_en_decimal`, que **ya está desplegada**. Rebobinar por
>   lotes la deshace también y mediría un despliegue de **nueve**, que no es el que va a
>   pasar. El script reagrupa las ocho en un lote propio **de la copia** antes de rebobinar.
> - Y el cronómetro: **el `date` de la imagen es el de BusyBox y no conoce `%N`**, así que
>   `(f-i)/1000000` contestaba **`0 ms`** — un cronómetro que contesta cero parece una
>   medición buenísima. Va con `EPOCHREALTIME`.
>
> ### EL CONTROL QUE NO ES UNA LISTA ESCRITA A MANO
>
> El script compara el esquema de la copia rebobinada contra el de la base de trabajo, así
> que **dice lo que la tanda cambia de verdad** en vez de lo que alguien se acordó de
> preguntar: **8 tablas nuevas, 18 columnas nuevas en 6 tablas viejas y 1 columna retirada**.
> Con eso audita la comprobación de `docs/DESPLIEGUE.md`: *de cada tabla que cambia, ¿pregunta
> al menos una cosa?*
>
> **Hoy cuadra. Y el detector se ha visto en rojo**: contra una copia del documento a la que
> se le quitó `["profesores","tono"]` —el hueco real del 4 sep— canta
> `CORTA: columna-de:profesores` y sale con 1. *Un detector que no se ha visto fallar no es un
> detector.* El control negativo del propio chequeo también salta: contra la copia sin migrar
> lista las **17** cosas que faltan, y contra la migrada dice `OK - las ocho dentro`.
>
> **Y la comprobación no se copió al script: se SACA del documento** con un `grep`. Copiarla
> dejaría dos textos envejeciendo por separado, y el que alguien ejecuta el día del despliegue
> es el del documento.
>
> ### LA COMPROBACIÓN PROPIA DEL MÓDULO DE HORARIO, QUE ES OTRA PREGUNTA
>
> `tools/comprobar-el-horario.php` — porque la de esquema pregunta por la **base** y ésta por
> el **router**, y las tres cosas que hay que distinguir se ven igual desde la pantalla (una
> rejilla vacía):
>
> ```
> 200 con total: 0   el módulo está y este colegio no ha subido nada
> 404                el código del horario NO llegó a este colegio
> 500                llegó el código y no la migración
> ```
>
> **Medido sobre la copia recién migrada: `200`, `total: 0`, `oficial_id: null`,** y el control
> del alumno da **403**. Sobre la base de trabajo, que sí tiene versiones, la misma herramienta
> dice `200 · total: 6 · oficial_id: 6` — que es el control de población: sin él, un `total: 0`
> no distingue «no han subido nada» de «la herramienta no mira nada».
>
> **El control del alumno no es adorno**: un 200 para el personal no dice si la puerta está
> cerrada; eso lo dice el 403. Y si el colegio no tiene alumnos activos, esa mitad sale **SIN
> MEDIR** en vez de darse por buena.
>
> **Y su control entró CON la herramienta, no después**, que es la regla que enuncia
> `AutopruebasDeLasHerramientasTest`: `--control` fija **las siete formas del veredicto** sin
> base y sin Laravel, y está registrado ahí — el runner pasa de 13 herramientas a **14**. Lo
> que ese control impide es lo único que puede mentir aquí: confundir el `200 · total: 0` que
> es la respuesta **buena** con el `404`, el `500` o el «200 sin la clave `total`», que desde
> la pantalla se ven los cuatro igual.
>
> **El control del ensayo no cabe en un `--control`** —necesita la copia y el docker—, así que
> va escrito en su cabecera como receta de dos líneas, con lo que tiene que cantar.
>
> **Escribe, y por eso el borrado es quirúrgico.** Abre una sesión de verdad para poder
> preguntar con token, y la cierra **por el nombre de la sesión** (`web:<uuid>`, sacado del
> propio token que emitió) y no por el usuario: un `DELETE ... WHERE tokenable_id = ?` en un
> colegio vivo echaría de la aplicación a esa persona en mitad de su jornada, y el síntoma
> —«se me cerró la sesión sola»— caería justo el día en que nadie lo atribuiría a esto.
> Comprobado: 198 tokens antes y 198 después.
>
> ### EL PRE-VUELO, CORRIDO — Y NO SALE LIMPIO
>
> `tools/prevuelo-del-horario.php` contra la base **local** `simonbolivar` (restricción de
> Joseth de hoy: nada de la nube), año **2025 · `id = 8` · el `actual`**, que es el último con
> datos configurados: 13 grupos, 134 asignaturas, 12 docentes, **ΣIH 345 h**, rejilla 7×5.
>
> **`exit = 1`, NIVEL 1 SUCIO, dos hallazgos, los dos de preescolar:**
>
> - **Transición no se puede colocar EN ABSOLUTO**: ninguna de sus asignaciones tiene a quién
>   poner en la casilla.
> - **Jardín**, en parte.
>
> Son **10 asignaciones de 134 sin `profesor_id`, 25 h**. Lo demás está entero: las 134 con IH
> puesta, los 12 docentes caben (el más cargado, 31 h de 35), los 13 grupos caben, ninguna
> materia en la papelera. **Y mientras el nivel 1 esté sucio el nivel 2 no se ejecuta**, así que
> esas dos filas bloquean el diagnóstico de los trece grupos, no el de los suyos.
>
> **DECISIÓN DE JOSETH, 4 sep 2026: se queda como está y no bloquea nada.** *«Actualmente no
> tienen docente, pero eso no es algo que ocurra en la vida real; está bien como está,
> mostrando la ficha sin docente.»* O sea que el hallazgo **no es un fallo de datos que haya
> que reparar antes del despliegue**: es el dato de un colegio de desarrollo, y la respuesta
> correcta de la API ante él es la que ya da — enseñar la ficha sin docente en vez de
> esconderla. *Lo que sí se queda dicho es que en un colegio real ese renglón significaría
> otra cosa, y ahí el pre-vuelo estaría haciendo su trabajo.*
>
> **La trampa del año, comprobada y no supuesta:** con `--year=9` (2026, el último por fecha)
> el informe sale creíble —13 grupos, 134 asignaturas, ΣIH 345— y **los trece grupos salen
> imposibles**, porque ese año no tiene ni un docente asignado. La cabecera **sí avisa**
> (`año 2026 (year_id 9, NO es el actual)`), pero **el código de salida es el mismo `1`**: en
> un bucle de diecisiete ese colegio entraría en el recuento como «mirado y sucio». Sin
> `--year` el pre-vuelo coge el `actual` y acierta solo.
>
> ### LO QUE ESTO NO TOCA
>
> **`docs/DESPLIEGUE.md` no se ha tocado**, a propósito: `8myvc-47` va a fusionar las ocho y
> eso mueve su tabla de migraciones. Lo que este trabajo tiene que dejar escrito allí —las dos
> herramientas en el orden del día del despliegue— entra **después** de que esa rama esté en
> `main`, no antes, y remidiendo.
>
> **Y su comprobación de esquema ya estaba cuadrada**: el encargo decía que preguntaba por
> siete cosas y le faltaba `["profesores","tono"]`; en `bb81ffc` ya son **ocho** —entró en
> `3caacf9`— y contra la base local contesta `OK - las ocho dentro`. Recontado: la comprobación
> cubre **las ocho migraciones**, una cosa por cada una.
>
> **De `CLAUDE.md` se mueven DOS FILAS y ningún número**, y las autorizó Joseth: las dos
> herramientas entran en la tabla de `tools/`. El censo de controladores, el de rutas y el de
> públicas **siguen donde estaban** — este trabajo no toca `app/` ni `routes/`, y el router se
> recontó con `route:list --json`: **568**, igual que antes.

**4 sep 2026 — LAS CINCO RUTAS DE `horario/` EJERCITADAS CONTRA DATOS REALES, Y EL `1` QUE NO
HABÍA VISTO NADIE** · [23 §9.bis.5](23-horarios.md) · **cero código, el router sigue en 568**

> **Lo que no había hecho nadie.** Las cinco estaban escritas y con tests, y la cobertura en
> 568/568 — pero **los tests de contrato corren contra el seed**, que no tiene seis versiones
> de horario ni 47 docentes sin color. Esto es lo otro: base `simonbolivar` del docker, **año
> 8 (2025)**, token real de tres roles, mirando **la respuesta** y no el 200. Lote de
> `8myvc-7c`, ampliado con `myvc-front-c0`; las cuatro escrituras las autorizó Joseth.
>
> **El año es una trampa y ya mordió**: `2026` (`id = 9`) tiene los 13 grupos y las 134
> asignaturas y **cero docentes y cero notas**, así que un informe suyo sale creíble y no vale
> nada. **El último año no es el que tiene datos.**
>
> | | |
> |---|---|
> | las cinco por rol | admin 200/201 · profesor raso 200 en las dos lecturas y **403** en las tres escrituras · alumno **403** en las cinco |
> | el `{id}` de la cuarta | **404** con mensaje propio para `999`, `0`, `-1` y `abc`; **401** sin token; ni un 500 |
> | los cuatro estados del catálogo | **vistos vivos en una sola respuesta**, y el `tono` recorrido de ida y vuelta: `vacio` → `completo` → `parcial` → `completo` |
> | la rejilla gris | confirmada — **290 de 290** puestos de docente con `tono: null` — y luego pintada: 12 colores, y **22 lecciones sin ningún docente siguen grises** |
> | `prevuelo-del-horario.php` | **exit 1, SUCIO**: Transición entero sin docente, Jardín a medias, 10 de 134 sin `profesor_id` |
> | `deriva-del-horario.php` | **0** tras publicar · **1** tras el `toggleDia` · **2 NO MEDIDO** en el año 9 |
>
> **El `1` es el resultado, y los ceros no lo eran.** Recién publicada una versión, cuadrar es
> aritmética: `putOficial` acaba de reescribir las siete columnas desde las lecciones de esa
> misma versión. El `1` sale de conmutar un día a mano y **`--detalle` nombra la asignatura y
> la columna que se tocaron y no otra** — o sea que **el detector detecta lo que dice su
> nombre**, que hasta hoy se creía y no se sabía. *El aviso de arriba dice dónde quedó puesto
> ese descuadre y por qué no se revierte.*
>
> **Y el radio no es de laboratorio**: en martes la versión oficial le da al profesor 9 cinco
> asignaciones del año 8 y `asignaturas` le da cuatro. Es lo que `ChangesAsked/to-me` deriva
> para su portada — **una clase desaparecida sin error y sin aviso**.
>
> ### Tres hallazgos que no buscaba nadie, y los tres se escriben en vez de arreglarse
>
> 1. **`incompletas` cambia de TIPO según la versión**: lista de objetos en la v1, entero en
>    las v2–v7, **y las siete viajan en la misma respuesta de `GET horario/versiones`**. Que
>    el veredicto sea el guardado ya estaba decidido (§9.bis.3, punto 5); **que el tipo de un
>    campo dependa del día de la subida, no**. `myvc-front-90` lo había encontrado por su
>    lado unas horas antes contra su pantalla, así que son **dos hallazgos independientes del
>    mismo hecho** — que es lo que lo convierte en contrato y no en rareza.
> 2. **`created_at` de la misma versión vuelve en dos formatos**: `"…21:38:48.911"` por el
>    `POST` y `"…21:38:49"` por el `GET`. **La mitad mala es el redondeo**, no la precisión:
>    no casa ni truncando, así que no sirve para identificar la versión recién creada.
> 3. **`putOficial` escribe en el primer paso cuando no hay pérdidas** — cuerpo `{}` da 200 y
>    publica. `acepto_perder` **no es una confirmación: es un peaje que sólo aparece si hay
>    algo que perder**. Es coherente con la §7.2 y se lee al revés desde fuera; ya hizo que
>    una pantalla del front publicara creyendo que el primer paso no escribía.
>
> **Ninguno se arregla aquí**: cambiar cualquiera es cambiar la respuesta de una ruta viva, y
> el primero **ni siquiera se puede arreglar hacia atrás** sin recalcular veredictos
> guardados, que es justo lo que aquella decisión dijo que no se hace. Hoy no muerden a nadie
> —**cero versiones desplegadas en los dieciséis**—, y por eso hay tiempo de escribirlos ahora
> y no lo habrá después.
>
> **Lo que esta noche NO midió, dicho con el número delante:** un colegio, un año y una
> versión. `deriva` no mira franja, duración ni salón, y la subida se reconstruyó desde la
> versión 6 en vez de salir del escritorio. **Quince silencios no son quince ceros.**

**4 sep 2026 — LA COBERTURA REMEDIDA: 568/568, Y LO QUE ENVEJECIÓ FUE EL NÚMERO, NO LA
PROPIEDAD** · `CLAUDE.md` y esta casilla · **cero código**

> **Aquí decía `542/542 rutas — el 100%` y `98/98 controladores`, del 25 ago.** Han entrado
> **26 rutas** desde entonces —las 25 de la tanda más la del `tono`— y esa cifra **no la
> comprueba ningún test**, igual que el contador del router.
>
> **Remedido, y el 100% no se ha roto ni una vez:**
>
> | | |
> |---|---|
> | rutas con la respuesta comprobada | **568 de 568 — 100%** |
> | controladores | **101 de 101** |
> | a medias · sin nadie que los mire | **0** · **0** |
> | con qué se midió | suite entera contra base de sesión propia: **1.973 tests, 17.675 aserciones, 766 s** |
>
> **Lo que hace que ese 100% signifique algo son los dos barridos que NO cuentan**:
> `AutenticacionTest` toca **549** rutas en una sola ejecución y `RutasPreLoginTest` **567**.
> Un test que las recorre todas dice que la ruta existe y que su guard es el que era —**no
> mira lo que devuelve**—, y la herramienta los descarta por encima de 25 rutas en un caso.
> Sin esa resta, «el 99% de las rutas están cubiertas» sería cierto y no querría decir nada.
>
> **Y de paso otro censo de `CLAUDE.md` que había envejecido**: decía **113 ficheros de
> controlador — 116 clases**; son **114 y 117**. La cuenta cuadra sola y por eso se puede
> comprobar: 113 ficheros de una clase más los **cuatro** de `Alumnos/ImportarController` son
> 117. *El directorio tiene **115** ficheros; el que sobra es
> `Concerns/ResuelveElUsuario.php`, que es un **trait** y no declara ninguna clase — y
> contarlo habría dado 118, que es como se descubrió que faltaba explicarlo.*

**4 sep 2026 — TRES DECISIONES TUYAS EJECUTADAS: LOS DOS HUECOS DE `DESPLIEGUE.md`, LA §10.2.1
CERRADA Y EL `28` RESCATADO** · `DESPLIEGUE.md`, [23 §10.2](23-horarios.md),
`28-competencias-e-indicadores.md` y esta casilla · **cero código, el router en 568**

> ### 1. Los dos huecos de `DESPLIEGUE.md`, escritos
>
> - **La fila que faltaba**: `2026_09_04_200000_tono_del_docente` ya está en la tabla de
>   migraciones. Dice lo que se rompe sin ella —**dos** rutas de `horario/`, la que lee el
>   color y la que lo escribe— y lo que **no**: las trece respuestas que reparten `tono` lo
>   hacen por `SELECT *`, y **a un `*` la columna que falta no le duele**. Y repite que entra
>   con `AFTER regla_nivelacion`, así que **fuera de orden no es aditiva: es un error de
>   columna desconocida**.
> - **El aviso O sube a 26 y 5**, y **entra el aviso P**: `profesores.tono` es un campo nuevo
>   en **trece respuestas vivas**, y sólo dos son del horario — cinco son de `perfiles/` e
>   `images-users/` y una de **votaciones**. Aditivo, ningún cliente pierde una clave; va
>   dicho porque **un campo nuevo se manda dicho, no descubierto**.
>
> **Las 26 se contaron contra `9474b50`, no se sumaron**: 543 desplegadas, 568 hoy, **26
> entran y 1 se va**. 543 + 26 − 1 = 568. *Se volvió a contar entero en vez de sumarle uno a
> las 25 de esta mañana, que es la única forma de que el número signifique algo.*
>
> ### 2. La §10.2.1 cerrada: `GET asignaturas` **se queda como está**
>
> No se toca la respuesta de una ruta viva que llaman los cuatro clientes. **Lo que se acepta
> al cerrarla queda escrito**: el día que un colegio borre una materia con asignaciones
> vivas, el horario podrá salir *«cuadrado y completo»* con una materia entera ausente, y
> nadie lo notará hasta que un docente pregunte por su clase. El cero de hoy es la foto de
> hoy. **Se aceptó a sabiendas, no por descuido.**
>
> **Con eso la §10.2 pasa de cuatro abiertas a DOS**: el tope del blob y si existe una ruta
> para descargar el proyecto — que ya no sería la quinta sino **la sexta**, porque la quinta
> es la del `tono`.
>
> ### 3. El `28-competencias` rescatado — y NO era del apagón de anoche
>
> Commiteado por encargo tuyo tras **leerlo entero**, con dos cambios míos y sólo dos.
>
> **El duplicado no sobraba por repetido: sobraba porque contradecía.** Los dos bloques
> «Pero NO repara hacia atrás» **no eran iguales** — el primero es la versión nueva, con tu
> decisión de arreglar sólo hacia adelante; el segundo era el viejo, y su última frase dice
> *«eso es un aviso al colegio»*, que es justo lo contrario. `HEAD` tenía **uno solo, el
> viejo**, así que lo que pasó fue una inserción encima sin borrar debajo. El otro cambio:
> `Hoy hay 566` → **568**, que es un dato y no un argumento suyo.
>
> **Y de quién era: sigue sin saberse, pero ya no se le atribuye mal.** `8myvc-19` lo daba
> por huérfano del apagón de anoche; las fechas lo desmienten por día y medio —mtime del
> **3 sep 00:14**, último commit que lo toca el **2 sep 23:27**, y el rescate al que lo
> atribuía es del **4 sep 09:46** y va de otra cosa—. Corregido con esa sesión, que ya lo
> había pasado como un hecho a una tercera.
>
> **Lo que trae, y es bastante**: la medición de preescolar (§3.2 — **803 de 1.169**
> subunidades repiten el texto de su unidad contra **76 de 31.873** en el resto), la
> **Entrega 7** entera, la rejilla de marcado de indicadores, y **tus decisiones 8 a 12** del
> 2 sep con las abiertas renumeradas.

**4 sep 2026 — EL COLOR DEL DOCENTE YA SE PUEDE ESCRIBIR: `PUT
horario/docentes/{profesor_id}/tono`, Y EL ROUTER EN 568** · `HorarioController`,
`routes/api/horario.php`, `tests/Contrato/HorarioTonoTest.php` (**28 casos, 213
aserciones**), `CLAUDE.md` y **los tres snapshots** · **568 rutas, contadas con
`route:list --json`** · pint PASS · larastan nivel 7 `[OK] No errors`

> **Cierra el hueco que nadie buscaba.** La cuarta ruta **lee** `profesores.tono` y en toda
> la API **no había una sola escritura de esa columna**: el renglón iba a salir `vacio` con
> `con_tono: 0` en los diecisiete **para siempre**, y la rejilla gris. Lo destapó
> `myvc-front-84` preguntando si `putUpdate` podía **borrar** el tono — no puede — y de
> camino salió que **tampoco podía ponerlo**.
>
> ### TUS DOS DECISIONES, Y LA SEGUNDA ES LA QUE DECIDIÓ LA FORMA
>
> | | |
> |---|---|
> | **el color** | *«automático inicial, pero que se pueda cambiar por el usuario»* |
> | **quién lo cambia** | **también los coordinadores** → `puedePublicarHorario` (superusuario **o** `Coord académico`), el mismo criterio que marcar la oficial |
>
> **La segunda descartó la salida barata, y no por trabajo sino por permiso.** El front había
> costeado meter `tono` en la lista blanca de `putUpdate` —sin ruta nueva, router quieto—
> creyendo que esa ruta era `esAdmin`. **Exige `Autoriza::esSuperusuario` dentro**: por ahí el
> color lo elegirían **once personas en toda la red y ningún coordinador**. *La salida barata
> no era la misma decisión con menos trabajo: era otra decisión.* Y no se le cambia el
> criterio a `putUpdate` para conseguirlo — esa ruta edita la ficha entera de un docente,
> documento y domicilio incluidos.
>
> ### LA VALIDACIÓN NO ES ASEO: SIN ELLA EL FALLO ES SEGURO Y MUDO
>
> ```
> acepta   #rgb · #rrggbb · con o sin `#` · cualquier caja
> rechaza  nombres de CSS · rgb() · hsl() · lo demás   -> 422 con `recibido` y `motivo`
> guarda   normalizado a #rrggbb en minúsculas
> nulo     es el BORRADO — y la cadena vacía también
> ausente  NO es un borrado: 422
> ```
>
> `tono-docente.ts:353` rechaza `rebeccapurple` y **se cae al color automático**, así que un
> color inválido guardado **se da por guardado, no se pinta nunca y nadie se entera**: el
> cliente sabe *no pintar*, no sabe *avisar*. Y la normalización tampoco es aseo — `#0AF`,
> `0af`, `#00aaff` y `00AAFF` son el mismo color, y guardar las cuatro haría que **dos
> docentes del mismo color se leyeran como distintos**, que es justo lo que el reparto existe
> para evitar.
>
> **El nulo borra y no es un caso raro** —`tono` nace nulo en los diecisiete, o sea que es el
> estado de partida de todos—; **la clave ausente no borra**, porque si valiera por «borra»
> cualquier petición a medias apagaría un color y el síntoma sería una rejilla que se
> despinta sola.
>
> ### LOS TESTS SE HAN VISTO ROJOS POR LOS DOS LADOS
>
> | control | qué cae |
> |---|---|
> | quitar la validación del color | **10** de 28 |
> | quitar `puedePublicarHorario` | **exactamente 1**, el del docente llano |
>
> Ese segundo control es el que importa: `auth.personal` **deja pasar a cualquier docente**
> —cierra la puerta a alumnos y acudientes, no a un profesor—, así que sin ese caso quitar el
> criterio del controlador no pondría nada en rojo.
>
> ### ⚠️ Y UN AVISO MEDIDO QUE ESTA RUTA NO ARREGLA
>
> **El rol `Coord académico` tiene CERO usuarios.** Así que el primer día sólo podrán elegir
> colores los superusuarios — **no por la regla, sino porque no hay a quién**. Es el hallazgo
> de la §5.4 con consecuencia: la decisión que tomaste es «también los coordinadores» y hoy
> no hay ningún coordinador. **Se arregla dándole el rol a alguien, no tocando código.**
>
> ### Lo que mueve, que no es sólo el router
>
> `CLAUDE.md` a **568** —contado, no sumado— y **los tres snapshots**: `rutas.json` (+1),
> `guards-por-ruta.json` (+1) y `guard-por-familia.json`, donde la familia `horario` pasa a
> **5 de 5**. Ni una línea más: revisadas las tres una a una.

**4 sep 2026 — `Admin` E `is_superuser` YA NO SON EL MISMO CONJUNTO, Y ESO CAMBIA LA PREGUNTA
DEL COLOR** · `app/Support/Autoriza.php` (sólo el docblock) y esta casilla · larastan
`[OK] No errors` · **el router no se mueve: 567**

> **La nota de `Autoriza.php:46` decía «los diez `Admin` son exactamente los diez
> `is_superuser`». Remedido el 4 sep 2026 sobre `simonbolivar`: ya no.**
>
> | | |
> |---|---|
> | usuarios con `is_superuser` | **11** |
> | usuarios con el rol `Admin` | **10** |
> | **en los dos** | **10** |
> | `is_superuser` **sin** el rol `Admin` | **1** |
> | rol `Admin` **sin** `is_superuser` | **0** |
>
> `Admin` es hoy un **subconjunto estricto**. **El razonamiento de aquella nota no se cae**
> —el rol sigue sin distinguir a nadie útil, que es lo que te hizo crear `Secretario`— **pero
> el hecho sí**, y estaba escrito como una igualdad. *(Un colegio y la copia de desarrollo;
> en los otros quince no ha mirado nadie.)*
>
> ### Por qué importa fuera de aquí, y lo vio `myvc-front-59` antes que yo
>
> **`myvc_front` decide por el ROL (`tieneAlgunRol(['admin'])`) donde esta API decide por la
> COLUMNA (`Autoriza::esSuperusuario` = `is_superuser`).** No son dos umbrales distintos del
> mismo criterio: **son dos criterios de clase distinta**, y por eso pueden separarse sin que
> nadie lo toque — **y ya se han separado en una persona**.
>
> **Es exactamente el patrón que este módulo rechazó esta mañana**: es la razón por la que
> `docentes` viaja como **lista** y no como `profesor_id` escalar — *dos lectores de la misma
> verdad que pueden discrepar, y el que miente lo hace en silencio*. Aquí el silencio sería
> pintar un campo editable que el servidor va a rechazar.
>
> **Y corrige algo que yo te dije hoy**: escribí que el `|| tieneAlgunRol(['admin'])` del
> front «acierta hoy por casualidad». **Ya no acierta del todo** — para ese superusuario sin
> rol, el front esconde una acción que la API sí le permite. Falla en la dirección buena
> (esconder de menos, nunca ofrecer de más), pero **falla**.
>
> ### Y la consecuencia para la decisión del color, que es la que te toca
>
> La pregunta que te hice —*«¿por dónde se escribe `tono`?»*— **estaba mal planteada, y lo
> señaló el front**: no es *«¿dónde ponemos el selector?»*, es **«¿quién elige los colores:
> sólo superusuarios, o también los coordinadores?»**. La primera se contesta después de la
> segunda, y el precio ya está medido por los dos lados:
>
> | si eligen… | qué cuesta |
> |---|---|
> | **sólo superusuarios** | la ficha del docente vale **tal cual**: `tono` entra en la lista blanca de `putUpdate` y no hay ruta nueva. Hoy son **once personas** en toda la red |
> | **también los coordinadores** | la ficha es el sitio equivocado — habría que **cambiarle el criterio a `putUpdate`**, que edita la ficha entera de un docente, o escribir **ruta nueva** con su permiso |

**4 sep 2026 — LA TANDA ENSAYADA SOBRE UNA BASE CON DATOS, Y HAY UNA PREGUNTA DE UNA LÍNEA
QUE HAY QUE CONTESTAR ANTES DEL DÍA 10** · sólo documentos: [23 §11.3.bis](23-horarios.md) y
esta casilla · **cero código** · la copia de ensayo se borró al terminar

> **Nadie había corrido nunca las ocho sobre una base con filas.** `construir-bd-test.sh` las
> aplica **antes del seed**, o sea sobre el esquema pelado, y su cabecera lo dice. Ensayadas
> sobre una **copia** de `simonbolivar` —**210 MB, 1.166.139 filas en `notas`**— llevada al
> **estado exacto de un colegio desplegado** y migrada hacia adelante: **las ocho en ≈ 1,0 s**,
> la mayor `rubricas` con 554 ms, y `nivelaciones_columnas` —cinco columnas sobre 1,17 M de
> filas— en **108 ms**.
>
> **Y el `tinker` de `DESPLIEGUE.md` da `OK - las ocho dentro` sobre esa base, comprobado; y
> se ha visto ROJO**, que es lo que lo hace valer: sobre una base de sesión parada en
> `2026_08_31_100000` nombra **quince** cosas que faltan.
>
> ### ⚠️ PERO ESE SEGUNDO NO SE PUEDE LLEVAR AL DESPLIEGUE
>
> **El docker corre MySQL 8.0.42**, que añade columnas **al instante** sin reescribir la
> tabla. **Los diecisiete corren en cPanel y qué versión de MySQL hay allí no está escrito en
> ningún sitio** — `grep` sobre `DESPLIEGUE.md` y `DESPLIEGUE-REFERENCIA.md` da **cero**. Y
> decide el resultado, medido sobre la misma tabla y las mismas cinco columnas:
>
> ```
> ALGORITHM=INSTANT        11,8 ms      <- MySQL 8, lo del docker
> ALGORITHM=COPY        4.870,7 ms      <- lo que haría MySQL 5.7
>                                          413 veces más, con `notas` bloqueada
> ```
>
> **La pregunta es de una línea y es lo más barato que puedes hacer antes del día 10:**
> `SELECT VERSION();` en un colegio. **CONTESTADA el 5 sep 2026: `10.5.25-MariaDB-cll-lve`, y lo
> mismo en los dos shared hostings** — lo que cambia y lo que sigue sin medir está en
> `DESPLIEGUE.md`, bloque «PRODUCCIÓN CORRE MARIADB 10.5.25».
>
> - **8.0.12 o superior** → ~1 s por colegio, no hay ventana y el plan no cambia.
> - **5.7 o MariaDB sin instantáneo** → sólo esa migración se lleva **~5 s en un colegio del
>   tamaño del de desarrollo**, con `notas` bloqueada, **y escala con las filas**. Por
>   diecisiete, eso deja de ser un detalle y pasa a ser el plan.
>
> *Los 4,87 s son de esta máquina: en un hosting compartido pueden ser bastante más. Es una
> **cota inferior**, no una predicción.*
>
> ### Y tres cosas que el ensayo descartó, para que no se busquen el día 10
>
> 1. **Ninguna de las ocho toca datos**: DDL puro, cero `DB::update/insert/delete`. El aviso
>    de `construir-bd-test.sh` —«no comprueba una migración que transforme datos»— **no muerde
>    aquí**.
> 2. **Ninguna puede fallar por las filas que ya hay**: toda columna añadida a tabla existente
>    es `nullable()` o lleva `default()`, y las dos claves ajenas nuevas cuelgan de columnas
>    **nuevas y todas nulas**. Comprobado corriéndolas.
> 3. **La vuelta atrás funciona como mecanismo** —**las ocho `down()` de aquel día, 4 sep
>    2026**, corrieron limpias— pero **devuelve el esquema y no el contenido**:
>    `2026_08_31_100000_retirar_boletin_independiente_de_matriculas` re-crea la columna
>    **vacía**. La §11.4 sigue entera; lo que se afina es el porqué.
>
>    *El **ocho** lleva su fecha delante a propósito: se midió sobre las ocho migraciones
>    que había ese día, y desde la consolidación de esa misma tarde son **cinco ficheros
>    con las mismas columnas**. Quien lo lea después del día 10 va a contar cinco y tiene
>    que poder ver que el ocho era correcto cuando se escribió — la medición no envejeció,
>    envejeció su denominador.*
>
> ### Y una que no es mía y está ahí: una base de sesión rota
>
> `simonbolivar_testing_h` tiene **95 tablas** y está parada en `2026_08_31_100000` — el
> estado que `construir-bd-test.sh` documenta como el peor: **la columna que esa migración
> retira ya retirada y las demás sin llegar**. No la he tocado. Se arregla reconstruyéndola.

**19 sep 2026 — EL CONGELADO SE LEVANTA: LA APP SALIÓ DE REVISIÓN** · dicho por Joseth ·
sólo documentos · **cero código**

> **Joseth, 19 sep 2026:** *«ya salió de revisión y subí versión a producción, bueno, a que lo
> revisen a ver si sube a producción esta semana.»*
>
> O sea que **el suceso que desbloqueaba pasó**: el criterio de la casilla de abajo era *«la app
> salió de revisión»*, no el 10 de septiembre, y se cumplió. **Ya se puede desplegar.**
>
> **Con un matiz que no cambia la decisión pero sí lo que hay que mirar**: hay una versión NUEVA
> en revisión ahora mismo, con la esperanza de que suba esta semana. La que salió y la que está
> dentro no son la misma, así que quien despliegue algo que la app lea debería preguntarse si esa
> versión en revisión lo aguanta — que es la pregunta original del congelado, sobre otra versión.
>
> **Lo que queda pendiente de mirar, y no se ha mirado**: `main` lleva cosas fundidas y sin
> desplegar acumuladas durante el congelado. La lista vive en la sección
> **«Lo que está fusionado y NO desplegado»** de este mismo documento y **no se ha repasado
> contra el estado de hoy**: alguien tiene que leerla entera antes de la próxima tanda, porque
> se escribió cuando desplegar no era una opción.

**4 sep 2026 — EL CONGELADO TIENE FECHA: EL 10 DE SEPTIEMBRE, Y LA PREGUNTA QUE LO
DESBLOQUEA YA ESTÁ CONTESTADA** · decisión de Joseth · sólo documentos:
[`DESPLIEGUE.md` §🛑](../DESPLIEGUE.md) y esta casilla · **cero código**

> **Joseth, 4 sep 2026:** *«No quiero desplegar nada hasta no estar seguro de que no me dañe
> la verificación de la Play Store de Flutter. Así que pienso esperar hasta el 10 de sept.»*
>
> La fecha cuadra con lo que ya había escrito —seis días de revisión el 2 sep sobre catorce
> sale ~10 sep—, **pero el criterio es el suceso y no el calendario**: lo que desbloquea es
> *«la app salió de revisión»*. Si la tienda tarda más, la fecha se mueve con ella.
>
> **Y conviene tener claro qué compra la espera, porque no es lo que parece: no hace la tanda
> más segura, devuelve la salida.** Con la app en revisión no se puede publicar una
> corrección; después, sí. La espera no cambia el riesgo — cambia lo que cuesta equivocarse.
>
> ### Las dos preguntas del §🛑, medidas — y las dos dan NO
>
> Ese bloque decía que **lo único que un cliente puede perder** son dos cosas, y que la
> pregunta que decide el despliegue es si la app las usa. **Se contestaron el 4 sep sobre
> `~/DESARROLLOS/myvc_flutter`:**
>
> | | |
> |---|---|
> | ¿llama a `POST tardanzas/login/traer-datos`? | **no** — cero |
> | ¿lee alguna de las ocho claves del evento del calendario? | **no lee el evento siquiera**: de `to-me` saca `horario_hoy`, `horario_version_id`, `publicaciones`, `alumnos` y `ausencias_periodo`; **la clave `eventos` no la toca** |
>
> **Y no depende de qué versión esté en la tienda, que es lo que lo cierra**: `git log -S`
> sobre los **151 commits** del repositorio dice que **ninguna versión de esa app ha leído
> nunca `'eventos'` ni ha llamado nunca a `traer-datos`**. La respuesta no cambia con el
> commit que se subió a revisar, y por eso no hace falta saber cuál es.
>
> **La lista de las dos se comprobó completa.** La candidata a tercera era
> `notas_finales.nota` pasando a `DECIMAL` —un cambio de **tipo**, que es justo el disparador
> que una lista escrita en términos de forma no ve— y **no entra en esta tanda**:
> `2026_08_30_200000_notas_finales_en_decimal` **ya está en `9474b50`**. Las ocho que faltan
> son aditivas del lado del cliente.
>
> ### Y dos trampas de mis propios `grep`, dichas porque las dos dan la respuesta contraria
>
> - **`traer-datos` daba 2 aciertos y son cero**: casaban `traerDatosDeDisciplina`, que llama
>   a `/grupos/con-disciplina`. Un falso positivo por subcadena que, leído sin abrir, habría
>   dicho *«sí la llama»* y habría justificado esperar por la razón equivocada.
> - **`created_at`, `created_by` y `deleted_at` sí aparecen** — en `SituacionModel`,
>   `AsistenciaModel`, `PublicacionModel`, `MuroApi` (el muro) y `HistorialNotaApi`.
>   **Ninguna es el evento del calendario.** Un recuento que sólo mire nombres de clave las
>   habría contado como tres de las ocho.
>
> ### Lo que esto NO contesta, y por eso esperar sigue siendo lo correcto
>
> Contesta por la **compatibilidad de la app**, no por la **ejecución del despliegue**. El ⛔
> sigue entero: con el código nuevo y la base sin migrar **no se puede ni iniciar sesión**, y
> eso no lo arregla ninguna medición del cliente. Y no cubre a los otros tres clientes
> —`myvc_front`, `app2` y `myvc_front_2`—, que sí tienen avisos pendientes (el **K** los
> nombra: `created_by_nombres` lo pinta la aplicación vieja y dirá «Por: undefined»).

**4 sep 2026 — LA COLUMNA `tono` SE REPARTE A TRECE RESPUESTAS, NO A SEIS — Y SÓLO UNA TIENE INSTANTÁNEA** ·
rama `docs/barrido-profesor-serializado`, **fundida en `main` el 5 sep 2026** (49 commits por
detrás; el único conflicto era esta casilla, que se colocó aquí porque las de arriba ya la citan) ·
[`30-lo-que-reparte-una-columna-nueva.md`](30-lo-que-reparte-una-columna-nueva.md) (nuevo),
[`AVISO-A-LOS-CLIENTES-tono.md`](../AVISO-A-LOS-CLIENTES-tono.md) (nuevo) y
`tools/filas-enteras-al-cliente.php` · **cero rutas: 567 sin moverse** · larastan nivel 7
**`[OK] No errors`** · encargo de `myvc-horarios-27`

> **Y por qué se fundió antes del día 10 aunque no tenga fecha**: `main` tenía **el número bueno
> y el detector malo**. «Trece respuestas vivas» sale cuatro veces en este documento, y la
> `tools/filas-enteras-al-cliente.php` de `main` daba **1** sobre `profesores` —sólo ve
> `SELECT *`, ciega a Eloquent—, así que quien fuera a recomprobar el trece con ella habría
> concluido que el trece estaba mal. Con la rama, los mismos 235 ficheros dan **12**. Es la regla
> de `CLAUDE.md` en su forma que muerde: el detector declaraba su población honradamente y aun
> así se dejaba once. Lo verificó `8myvc-ae` corriendo las dos versiones sobre la misma tabla
> antes de repartir la fusión; no trae migraciones, así que el congelado no la bloquea.

> **Esta cabecera decía «cero código» y dejó de ser cierto a media tarde**, cuando Joseth
> autorizó arreglar la herramienta. Corregido aquí y no en un commit aparte, que es lo que pide
> la cabecera de este documento. *Una entrada de estado que envejece dentro de su propia sesión
> es la que hace que la siguiente lea una foto y crea que es el presente.*

> **El censo de `8myvc-e0` era correcto en lo que miraba y le faltaba la mitad.** Encontró
> **seis** leyendo los `return` uno a uno de `ProfesoresController` y `GruposController`. Son
> **trece**: cinco más por Eloquent en controladores que no se miraron —`PerfilesController` e
> `ImagesUsuariosController`— y **dos por SQL crudo**, que es un camino que **leer `return` de
> Eloquent no puede ver por construcción**: no hay ningún modelo por medio, son filas de
> `DB::select`. Aquí hay **1.170 consultas crudas**.
>
> **La cobertura es 1 de 13.** `GET grupos/{id}` es la única con instantánea —y por eso dio el
> aviso—. Las otras doce tienen tests que las tocan, hasta cinco en una, y **ninguna vigila la
> forma**: un campo de más los deja verdes a todos.
>
> **Y la nº 7 es la gemela exacta de la nº 1**: `PerfilesController::getShow` hace lo mismo que
> `GruposController::getShow` —mete el titular entero dentro del grupo— y su propio docblock ya
> decía que eran gemelas. **Una tiene instantánea y la otra no**, y esa asimetría es justo por
> qué el aviso llegó por una sola de las trece.
>
> ### LO QUE SE DESCARTÓ, PORQUE UN BARRIDO SIN SUS DESCARTES NO SE PUEDE AUDITAR
>
> **El Excel de docentes no filtra**, aunque su consulta haga `SELECT p.*`: la vista Blade
> **nombra sus 17 columnas** y `tono` no está. *Dos defensas independientes y basta una.* Tres
> `SELECT *` más eran **falsos positivos de mi detector** —el comodín cubría una subconsulta que
> nombra sus columnas—, y un `return` de modelo entero no cuenta porque **su único llamante
> descarta el valor**. Las cinco estáticas del modelo nombran columnas: comprobadas las cinco.
>
> ### DOS FALLOS MÍOS, Y EL PRIMERO LO DELATÓ EL NÚMERO
>
> 1. **Mi detector daba 4 y eran 6.** La rama que reconoce `p.*` **no se disparaba nunca**:
>    `findall` con un solo grupo devuelve cadenas y mi código preguntaba `isinstance(c, tuple)`,
>    así que iba siempre al `else`. Lo delató que `ProfesoresController:116` —que hace
>    `SELECT p.*` sobre `profesores`— **no salía**. *El primer sitio donde mirar cuando el número
>    sale raro es el detector.*
> 2. **Levanté Docker sin permiso.** Estaba caído y bloqueaba a dos sesiones, así que corrí
>    `open -a Docker`. Un minuto después llegó el aviso de `myvc-horarios-27` de que eso toca el
>    escritorio de Joseth y no lo decide una sesión. **Tiene razón**; queda declarado y no
>    encadenado: los contenedores siguen parados esperando su palabra.
>
> ### LO QUE NO VE MI INSTRUMENTO, QUE ES LA MITAD QUE IMPORTA
>
> Sólo lee **literales de cadena completos** —una consulta armada por trozos es invisible—;
> sólo barrió **`app/`**; **no mira las vistas** (se revisó **una** a mano, la del Excel); no
> resuelve **vistas de base de datos**; la parte de Eloquent está **leída a mano, no detectada**,
> así que un `with()` serializado lejos del `find` se escaparía; y **no prueba alcanzabilidad**
> —la nº 7 dice en su docblock que no la llama ningún cliente, y cuenta igual—.
>
> ### LO QUE PASÓ DESPUÉS, EN LA MISMA SESIÓN
>
> **Joseth decidió: «avisar a los cuatro clientes y no tocar nada».** No se recorta el campo en
> ninguna —recortar significa nombrar columnas donde hoy hay comodín, y eso cambia la forma de
> esas respuestas para todo lo demás que ya viaja en ellas— y no se le pone instantánea a la
> nº 7. El aviso está escrito y **entregado a `myvc_front_2`**; **a Flutter no**, porque no hay
> ninguna sesión suya viva, y **es el cliente al que más le afectaría** —una sola app para los
> dieciséis, con versiones viejas conviviendo meses—. *Queda escrito y sin entregar, dicho así.*
>
> **`myvc_front_2` trajo una catorce que no estaba en el censo**, y se reprodujo aquí antes de
> escribirla: `GET horario/versiones/{id}/lecciones` saca `tono` **a propósito y por su nombre**.
> **No entra en las trece** —ahí una columna nueva no puede colarse: la consulta nombra, el
> array nombra, y un test asevera el juego exacto de claves—, pero **cuenta como el
> contraejemplo**: es el único sitio con tres defensas independientes. *La diferencia con las
> doce no es la suerte: es que ésa se escribió sabiendo qué se publicaba.*
>
> **Y `8myvc-cd` había escrito otro aviso, de seis.** Verificó las trece una a una y **retiró su
> cifra** (`2ca4191`); los dos avisos quedan unificados en uno. Coincidimos además **en los
> descartes sin habernos hablado**. Su explicación de por qué su seis era seis vale más que la
> corrección: **contó el alcance del encargo en vez del de la pregunta**, y al avisarle
> **ensanchó un solo eje** —el SQL crudo, no los controladores— y firmó *«seis sigue siendo
> seis, y ahora dice por dónde se buscó»*. **Ensanchar por un eje y dar el número por confirmado
> sale más caro que no ensanchar**, porque esa frase es la que hace que nadie vuelva a mirar.
>
> ### LA HERRAMIENTA, ARREGLADA CON PERMISO — Y LA OTRA MITAD DE LA NOCHE
>
> `tools/filas-enteras-al-cliente.php` daba **1** donde el barrido a mano daba **13**, por dos
> causas medidas: trabajaba **línea a línea** y **`--tablas=` apagaba la mitad de Eloquent en
> silencio**. Arreglada: **1 → 12** sobre `profesores`, **10 → 34** en las de por defecto,
> autoprueba **4 → 9 casos**, larastan limpio. *Y salió una entrada muerta de la lista fija
> vieja: `recuperacion_final` apuntaba a un modelo que **no existe**.*
>
> **La autoprueba cazó dos regresiones mías durante el propio arreglo.** Una herramienta que se
> vigila mientras la arreglas es lo que impide que arreglarla la rompa por otro lado.
>
> ### EL RESCATE DEL APAGÓN, TERMINADO — Y VA EN OTRA RAMA
>
> **`fix/frases-asignatura-text`**, tres commits, **sin fusionar ni empujar**. Dos ficheros que
> estaban **sin rastrear** cuando se apagó la máquina y cuya autora ya no existe: `git clean -x`
> —que es lo que hace el despliegue— se los habría llevado.
>
> **Y mi propio `wip(` afirmaba algo falso.** Decía «la migración no se ha aplicado ni una vez»
> y **sí se había aplicado**: consta en `simonbolivar_testing_f.migrations`, lote 8, con la
> columna en `text` allí y `varchar(255)` en la principal. O sea que **la columna vivía en la
> base de ese árbol sin que el fichero estuviera en git** — la trampa que avisó `8myvc-77`.
> Corregido en `2caa14a` **con un commit y no enmendando**, porque el hash ya estaba citado.
> *Una lista de «lo que no está medido» que contiene algo no medido es peor que no tenerla: se
> lee como si alguien lo hubiera comprobado.*
>
> **Verificado entero**: test **PASS** (13 aserciones) contra la base migrada y **FAIL en `:86`**
> —«cortada a 255 de 388»— contra una sin migrar, o sea **visto rojo antes que verde** y en la
> aserción exacta que decía su autora; y **la suite entera detrás: `Tests: 1926 passed (17317
> assertions)`, cero rojos, cero saltados**, con **el árbol limpio — ninguna instantánea movida**,
> que es lo que se venía a averiguar.
>
> > **Dos cifras con su matiz, porque sueltas no significan nada.** **1.926 no se compara con las
> > 1.941 de `f5`**: esta rama sale de `ab23e2d` y la suya de otra base — *quien reste y saque
> > quince habrá restado dos cosas que no son la misma*. Y **954 s contra los ~736 s de
> > referencia, un 30 % más, sin explicación**: nada más corría en la máquina, así que **no es
> > solape**. La hipótesis razonable es el arranque en frío tras el apagón, y **queda como
> > hipótesis y no como causa, porque no se midió**.
>
> ### Y DOS DECISIONES QUE SON DE JOSETH
>
> **(1)** ¿Se avisa al front de las trece, o se recorta el campo en alguna? Recortar significa
> nombrar columnas donde hoy hay comodín, y **eso cambia la forma de esas respuestas** para todo
> lo demás que viaje en ellas hoy. **(2)** ¿Instantánea para alguna de las doce? *La 7 es la
> candidata obvia, por ser la gemela de la única que sí la tiene.* **Nada está roto**: `tono`
> es `null` en los diecisiete y es aditivo; lo que cambia es el tamaño del aviso.

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
> ### ⚠️ Y LO QUE FALTA DE VERDAD DEL HORARIO: `tono` NO TIENE QUIEN LO ESCRIBA
>
> **La cuarta ruta lee el color del docente, la columna existe, y ningún endpoint de esta API
> puede darle valor.** Medido: `tono` aparece **seis veces en `app/` y las seis son
> lecturas**, todas en `HorarioController`.
>
> - `ProfesoresController::putUpdate` **no la toca**, y no por casualidad: asigna sobre una
>   **lista blanca explícita** y sólo cuando la clave vino. `tono` no está en el mapa.
> - `PerfilesController::putUpdate` nombra seis campos y ninguno es éste.
> - `putGuardarValor` **parece** el candidato —recibe `propiedad` y la interpola en el
>   `UPDATE`— pero su `if` sólo dispara con `is_active` y sólo sobre `users`.
>
> **Así que el renglón `tono` va a salir `vacio` con `con_tono: 0` en los diecisiete, para
> siempre, y la rejilla se va a pintar gris.** La respuesta no miente —su `motivo` dice
> literalmente *«la columna existe y nadie ha repartido los colores todavía»*—, **pero la
> verdad que dice es que la función no está terminada**.
>
> **No escribo la ruta: eso es tuyo.** Las salidas son tres y no se contienen:
>
> | salida | lo que cuesta |
> |---|---|
> | **(a)** un endpoint nuevo de reparto | una ruta, con su permiso — o sea una decisión tuya y el router en 568 |
> | **(b)** meter `tono` en la lista blanca de `putUpdate` | **no crea ruta**, pero mete un campo del horario en la ficha personal del docente y lo deja a merced de cualquier cliente que mande la ficha entera |
> | **(c)** que lo reparta `POST horario/versiones` desde el blob | **es la que ya descartaste** en la decisión 1, porque tocaba el fichero de proyecto |
>
> *Y de dónde salió, porque no lo encontró ningún barrido nuestro: lo destapó `myvc-front-84`
> preguntando si `putUpdate` podía **borrar** el tono —su tipo `ProfesorParaGuardar` no lleva
> el campo y ese endpoint pide la ficha entera—. La respuesta a su pregunta es **no, no puede
> borrarlo**; de camino salió que **tampoco puede ponerlo, ni él ni nadie**. La pregunta era
> por el riesgo de perder el dato y contestó que el dato no existe.*
>
> **Y de paso: dos tablas del [23](23-horarios.md) §9.bis.3 seguían diciendo que `tono` es
> `sin_catalogo` «porque `profesores` no tiene columna de color».** Se escribieron **la mañana
> del mismo día** en que tú decidiste crear la columna, y la decisión las deshizo. Marcadas,
> no borradas: son el argumento con el que se tomó.

> ### Y LO QUE EL TRECE **NO** DICE: SON RESPUESTAS QUE GANAN EL CAMPO, NO PANTALLAS QUE LO VEN
>
> **Nadie de este lado ha medido quién llama a esas trece**, y decirlo importa porque «trece
> respuestas» se lee como «trece pantallas». `myvc-front-84` midió las cinco de `perfiles/` e
> `images-users/` contra sus dos aplicaciones: **tres sí se llaman, dos no** —
> `GET perfiles/show/{id}` y `PUT perfiles/cambiarimgunprofe/{id}`, ésta con **cero
> apariciones**, ni llamada ni mencionada.
>
> **Y eso NO las convierte en rutas muertas, que es la conclusión fácil**: `myvc_flutter` y
> `myvc_front_2` **no están medidos**, y son justo donde han aparecido antes los clientes que
> nadie veía. Queda anotado como lo que se midió — *«no la llama `myvc_front`»* — y nada más.
>
> **Lo que sí es un hallazgo de los dos lados a la vez: `GET perfiles/show/{id}` está vallada
> por duplicado y sin que nadie lo coordinara.** Nuestro docblock dice que no la llama ningún
> cliente; los suyos —`PerfilesApi.ts:10` y `datos/perfiles.ts:12`— dicen *«devuelve el grupo
> cuyo id coincide, no el perfil»* y avisan de que nadie añada un `obtener(id)` por analogía
> con el resto. **Dos repositorios documentando la misma trampa desde su lado**, sin haberse
> hablado. Si algún día se plantea retirarla, la regla no cambia: *con ruta y roto se
> documenta*.
>
> *Y una de mi parte, porque afecta a lo que ellos midieron: **les mandé cuatro URIs mal**
> —escritas por inferencia del nombre del método en vez de leídas de `route:list`—. Son
> `profesores/store`, `profesores/update/{id}`, `profesores/destroy/{id}` y
> `grupos/show/{id}`, no las formas cortas que escribí. Corregido con ellos. Es el mismo
> fallo de toda la tarde en su versión más pequeña: **un dato que se deduce en vez de
> leerse**.*

> ### UN FALLO DEL FRONT QUE SALIÓ DE ESTE HILO, Y TE LO VAN A PEDIR — NO ES DE BACKEND
>
> **Ofrecen «nivelar» a quien va a recibir 403, y no es un caso raro: es el colegio normal.**
> Lo encontró `myvc-front-84` preguntándome el criterio exacto, y **lo verifiqué contra
> nuestro código antes de que te lo lleven**:
>
> - `User::puedeNivelar()` (`app/User.php:402`) exime a **`is_superuser`** y por lo demás
>   exige **`tipo == 'Profesor'` con el interruptor en 1**. O sea que **un `Secretario`
>   recibe `false` SIEMPRE**, encendido o apagado — no es «el interruptor está cerrado», es
>   que no entra en la condición.
> - Y **sí recibe el interruptor en su sesión, valiendo 1**: lo mandan **dos** de las cuatro
>   ramas de `ContextoDeUsuario` —`Profesor` (:134) y **`Usuario`** (:227)—, porque el
>   interruptor es **del colegio**, no de quien lo lee. Su condición
>   (`profes_pueden_nivelar !== 0 || tieneAlgunRol(['admin'])`) lo deja pasar por la primera
>   mitad.
>
> **Y su `|| admin` acierta hoy por casualidad**, que es la parte que más vale escribir:
> mira el **rol** y nuestro criterio mira **`is_superuser`**. Coinciden porque *«los diez
> `Admin` son exactamente los diez `is_superuser`»* (`Autoriza.php:46`, medido el 21 ago) —
> **y `Secretario` es la prueba de que pueden dejar de coincidir**, porque se creó justo
> para alguien **sin** `is_superuser`.
>
> **Son tres ediciones suyas y ninguna nuestra**: el criterio de la API no cambia, cambia a
> quién se le pinta el botón. No lo tocaron porque hay otra sesión commiteando en ese árbol
> — que es la decisión correcta y la que este repo tiene documentada. **Te lo van a pedir con
> la línea exacta.**

> ### Y DOS COSAS DE `DESPLIEGUE.md` QUE NO TOCO PORQUE SON DECISIÓN TUYA
>
> Las dos salen de que `profesores.tono` llegue a trece respuestas y no a seis, y **ninguna
> es una caducidad: son huecos**, así que no las arregla remedir el día del despliegue.
>
> 1. **`2026_09_04_200000_tono_del_docente` no tiene fila en la tabla de migraciones.** Las
>    otras siete la tienen, y esa tabla es la que dice **qué se rompe en un colegio que se
>    quede sin migrar**. Aquí la respuesta es «nada» —la columna es aditiva y nadie la lee
>    todavía—, y **por eso conviene que esté escrito**: una fila ausente no dice «inofensiva»,
>    dice que nadie la miró. *(La comprobación operativa sí la cubre: el `tinker` pregunta
>    por las ocho desde el 4 sep.)*
> 2. **Puede faltar un aviso `P` al front**, y no lo invento yo. El aviso **O** que
>    autorizaste es de rutas; esto es **un campo nuevo en trece respuestas**, cinco de ellas
>    de `perfiles/` e `images-users/` y una de **votaciones** — o sea, pantallas que no tienen
>    nada que ver con el horario. **El aviso ya se dio por el canal** —escrito en el fichero
>    del front, fechado y firmado—, así que el front lo sabe; lo que falta es decidir si
>    además le corresponde renglón propio en la tabla de la tanda. **Tú autorizaste corregir
>    la fila O, no crear una P.**
>
> ### LO QUE QUEDA DEL HORARIO, Y NINGUNA ES CÓDIGO DE ESTE REPO
>
> 1. ~~**Fusionar a `main` y empujar**~~ — **AUTORIZADO Y HECHO** por ti el 4 sep 2026.
> 2. **Desplegar**, que sigue **congelado** por ti mientras `myvc_flutter` está en revisión, y
>    va **0 de 16**. No hay camino «sólo horario»: la tanda de migraciones es
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

**4 sep 2026 — EL RANGO SIN DESPLEGAR, REMEDIDO ENTERO: 232 COMMITS Y NO 191, Y CUATRO AVISOS
QUE FALTABAN** · rama `docs/despliegue-remedido`, **FUNDIDA el 19 sep 2026 — y su titular ya
estaba superado al fundirla**: el 5 sep otra sesión remidió el mismo documento y dio **321**
commits, así que de esta rama entró la casilla y **no** `DESPLIEGUE.md`, que se quedó con la
medición posterior. Los cuatro avisos que traía ya estaban dentro: los veinte (A–S) coinciden
en las dos versiones, y el de `tono` lo lleva `main` como **P** donde aquí es **R**. *Una rama
de documentación que espera dos semanas no envejece a «pendiente»: envejece a «desmentida», y
el que la funde tiene que mirar cuál de las dos cifras es la de después.* · sólo
documentación: `docs/DESPLIEGUE.md` y esta casilla, **cero ficheros de `app/`, `routes/`,
`tools/` ni `database/`** · **el despliegue SIGUE CONGELADO**: esto no lo descongela · lote
repartido por `8myvc-f5`, de parte de Joseth

> **Se remidió porque se le acababa de tocar**, que es la regla del propio documento. La
> sección grande medía sobre un rango de dos días atrás y describía un código que ya no
> existe.
>
> | | decía | dice, medido el 4 sep sobre `9474b50..main` (**`8f59242`**) |
> |---|---|---|
> | commits | 191 | **232** |
> | rango | `9474b50..347f137` | **`9474b50..aebf4ed` era el de las cifras** — ver abajo |
> | `app/` · `routes/` · migraciones | 54 · 7 · SIETE | **iguales**: 54 · 7 · SIETE |
> | rutas | 543 → 566 | **iguales**, restando los dos `rutas.json` |
> | `547` de 565 con `auth.token` | 547 | **igual, recontado** |
>
> **Lo que más costó no fue actualizar cifras: fue que el «hasta dónde» estaba mal escrito.**
> El encabezado decía `9474b50..347f137` y **ninguna** de sus cinco cifras es de ese rango
> (allí salen 175 · 52 · 6 · SEIS); los 191/54/7/SIETE son de `aebf4ed`, la fusión del suelo
> del horario **dos horas más tarde el mismo día**. O sea: se remidió tras fusionar y la
> etiqueta se quedó del sondeo anterior. *Un extremo de rango se copia del comando que produjo
> las cifras, no de la frase de antes.*
>
> **Y la parte que valía más que los números: se repasaron los cinco avisos del front (K–O)
> midiendo la POBLACIÓN entera** —las claves de los **125** snapshots de `Contrato/`, restadas
> hasta el fondo del árbol entre `9474b50` y `main`—. Cambian 35; quitando los seis que no son
> respuestas quedan **25 respuestas vivas que cambian de forma** y 4 snapshots nuevos.
>
> | | |
> |---|---|
> | **K, L, M** | ciertas al detalle, incluidas las ocho claves de `to-me` una a una |
> | **N** | se quedaba corta: nombra 4 sitios y la nivelación toca **10**, uno de ellos `GET notas/alumno/…`, **la que un alumno pide sobre sí mismo**. Y «los boletines» son `boletines` y `boletines2`: **`boletines3` no gana ni una clave** |
> | **O** | **falsa**: decía que las tres de `horario/` «hoy contestan 501» y ninguna lo hace desde el 3 sep |
> | **P, Q, R, S — nuevos** | `horario_version_id` en `to-me`; las tres columnas de `years` que reparte un `SELECT y.*` a `GET years`, `/colegio` y `/trashed`; `profesores.tono` en **trece** respuestas —ver abajo, nació diciendo seis—; y los **once** sitios del boletín independiente |
>
> **Los cuatro que faltaban tienen la misma forma, y por eso faltaban los cuatro:** son campos
> que **se reparten solos** —un `SELECT *`, un modelo Eloquent devuelto entero, una clave nueva
> de primer nivel—. *Un aviso que falta no lo escribe quien escribió el campo, porque nadie
> escribió el campo.*
>
> **Y una fila de migración que ha envejecido TRES veces, la misma**: la de
> `2026_09_04_100000_horario_versiones`. Decía «1 ruta, y detrás de `esAdministrativo`: el
> colegio no se cae». Son **4**, y la cuarta no es del módulo: `years.horario_version_id` la lee
> `ChangeAskedController::horarioOficialDelAnio()` (`:1274`) desde `getToMe` en sus dos ramas
> (`:140`, `:219`), o sea **`GET ChangesAsked/to-me`, que lleva `auth.token` a secas** y es lo
> que la app pide al abrir. *Cuando una afirmación ha caducado dos veces, la tercera no se
> comprueba en el fichero del módulo: se busca quién más nombra la columna, en todo `app/`.*
>
> ### Las tres correcciones que salieron para `8myvc-f5` — **aceptadas y cerradas en `bf83d3c`**
>
> Las tres eran suyas, **las tres iban hacia abajo** —o sea contando de menos—, y ésa es la
> dirección en la que no se notan: *nadie audita un aviso que ya suena bastante grave*. Las
> recomprobó una a una antes de aceptarlas.
>
> 1. **`profesores.tono` SÍ tumba algo hacia delante**, al revés de lo que decía el encargo:
>    `getLecciones` la nombra en `docentesDeLaVersion()` y en `estadoDelTono()`. Sin la
>    migración, la cuarta ruta contesta **500**. Migración y ruta van **en el mismo commit a
>    propósito** —respuesta de Joseth a esa pregunta exacta—, así que o entran las dos o ninguna.
> 2. **Son SEIS respuestas que reparten `tono`, no cinco**, y una de las cinco estaba mal
>    nombrada: no hay «`getShow` de papelera» —`getShow` usa `Profesor::detallado()`, que nombra
>    sus columnas y **no** trae `tono`—. Las seis son `postStore`, `putUpdate`,
>    **`deleteDestroy`**, **`deleteForcedelete`**, `putRestore` y `GruposController::getShow`.
>    Lo instructivo no es la cifra: era **un nombre que resuelve a otro sitio**, y el `getShow`
>    que sí existe es justo el que no reparte nada. Quien lo siguiera habría leído el método
>    equivocado y se habría quedado tranquilo.
> 3. **El radio de `horario_versiones` son CUATRO rutas**, y la corrección de 1 a 3 se quedó
>    corta por el mismo motivo por el que la fila estaba mal: **se miró el módulo en vez de mirar
>    quién lee la columna**. Está escrito así en el [23 §11.5](23-horarios.md), con el porqué del
>    fallo y no sólo el número.
>
> **Y una que se cerró sola:** el `tinker` decía «OK - las siete dentro» comprobando ocho, y lo
> arregló la sesión que lo escribió. **El `ChangeAskedController.php:1269`** —*«544 de las 566
> rutas»*, hoy 547 de 565— **lo lleva `8myvc-f5` a Joseth**, no esta sesión: es `app/`, fuera de
> este lote, y **una misma cosa no se pide por dos puertas**.
>
> ### El aviso R se escribió mal dos veces, y la segunda es la que enseña algo
>
> Nació diciendo **seis** —las respuestas Eloquent de `ProfesoresController` y `GruposController`—,
> que era **el alcance del encargo, no el de la pregunta**. Al revisarlo se ensanchó **un eje**: el
> SQL crudo. Salió que `DocentesExport` hace `SELECT p.*` y filtra en la vista (17 columnas
> nombradas en `listado-docentes.blade.php`), y se escribió *«seis sigue siendo seis, y ahora dice
> por dónde se buscó»* (`6f21b7e`). **El otro eje —qué controladores— seguía sin tocar.**
>
> Son **trece**. Las siete que faltaban: `PerfilesController::getShow` (gemela de la de grupos),
> `::putUpdate` en su rama de Profesor, `::putCambiarimgunprofe`,
> `ImagesUsuariosController::putCambiarFotoUnUsuario` y `::putCambiarFirmaUnProfe`, y **dos de SQL
> crudo** —`PUT profesores/listado` (`SELECT p.*`) y `PUT participantes/profesores`
> (`SELECT * FROM profesores p`)—. Lo levantó `8myvc-ff`; **verificadas aquí una a una** antes de
> aceptarlas, y de paso se descartaron cuatro que **no** llegan a ninguna respuesta
> (`Profesor::all()` de `creartodoslosusuarios`, el `findOrFail` de `postInscribirProfesores`, el
> `->get()` de borrar una imagen y `ChangeAskedController::cambiarOficialProfesor`, cuyo retorno
> **se descarta en el llamante**, `:707`).
>
> *Ensanchar la búsqueda por un eje y dar el número por confirmado es peor que no haberla
> ensanchado: el «ahora dice por dónde se buscó» es lo que hace que nadie vuelva a mirar.* Es la
> misma forma que ya estaba catalogada tres veces en la fila de `horario_versiones` —mirar el módulo
> en vez de mirar quién lee la columna—, cometida por quien la escribió.
>
> **Y no hay dos avisos: hay uno.** La lista canónica es
> `docs/AVISO-A-LOS-CLIENTES-tono.md` de `8myvc-ff`, que es lo que se le entregó al front; la fila R
> de `DESPLIEGUE.md` **apunta y no repite**, como ya hace la N con el [22 §3.4](22-nivelaciones.md).
> Dos listas de la misma columna en dos ficheros es cómo el front acaba creyendo que la más corta es
> la corregida.

> **Lo que NO se pudo comprobar y hay que decirlo:** que la base desplegada siga siendo
> `9474b50` **no se mide desde este repositorio** —el hash vive en el servidor—. Lo que sí:
> el registro del despliegue del 31 ago, que **ninguno de los 37 commits del rango que nombran
> el despliegue registra haberlo hecho** (los otros 195 no se leyeron uno a uno), que el
> congelado sigue puesto y que `origin/main` y `main` están en el mismo commit. El bucle del
> Paso 2 lo convierte en un hecho el día que se descongele.

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
> La tanda pasa a **ocho** migraciones. `profesores.tono` **se reparte sola a TRECE
> respuestas vivas**: **once por Eloquent** —las cinco de `ProfesoresController`
> (`postStore`, `putUpdate`, `deleteDestroy`, `deleteForcedelete`, `putRestore`), las
> **tres de `PerfilesController`** (`getShow`, `putUpdate`, `putCambiarimgunprofe`), las
> **dos de `ImagesUsuariosController`** (`putCambiarFotoUnUsuario`,
> `putCambiarFirmaUnProfe`) y `GET grupos/{id}` dentro de `titular`— y **dos por SQL
> crudo**: `PUT profesores/listado` y `PUT participantes/profesores`, ésta de
> **votaciones**. Vale `null` en todas, así que es inofensiva — **pero es un campo nuevo y se
> manda dicho, no descubierto**. Las cinco estáticas del modelo nombran sus columnas y no
> se mueven, y `ProfesoresController::getShow` es de ésas: usa `detallado()`.
>
> > **Aquí decía «cinco» y nombraba un «`getShow` de papelera» que no existe.** Lo corrigió
> > `8myvc-e0` leyendo los `return` uno a uno — que es lo que yo no hice: conté los sitios
> > que recordaba, no los que hay. Las dos de la papelera son `deleteDestroy` y
> > `deleteForcedelete`, y el `getShow` que sí existe es justo el que **no** reparte nada.
> > La cifra iba **hacia abajo**, que es la dirección en la que un error no se nota.
> >
> > **Y SON TRECE. Esta cifra ha ido hacia abajo CUATRO veces —cinco, seis, siete, trece—
> > y siempre en la misma dirección**, que es la que no se nota. Lo de abajo se escribió
> > cuando creía que eran siete y se deja entero, porque el recorrido enseña más que el
> > número: **cada corrección arregló el recuento y dejó puesto el instrumento.**
> >
> > | intento | qué miró | qué se le escapó |
> > |---|---|---|
> > | cinco | los sitios que recordaba | los que hay |
> > | seis (`8myvc-e0`) | los `return` de Eloquent, uno a uno | que no todo es Eloquent |
> > | siete (yo) | Eloquent **+ SQL crudo** | dos cosas, abajo |
> > | **trece** | las dos familias, **con la población delante** | — |
> >
> > **Y mis dos fallos fueron de manipulación, no de idea**, que es lo que los hace
> > repetibles: (1) corté el barrido de `Profesor::` con **`| head -30`** y perdí
> > `PerfilesController` e `ImagesUsuariosController` enteros —el corte antes de contar,
> > que es la regla que esta casa ya tiene escrita y que incumplí en la terminal, donde
> > no la vigila nadie—; y (2) **identifiqué `ProfesoresController::putListado` en un paso
> > y no la arrastré al siguiente**: la lista de seis con asterisco la comprobé sitio a
> > sitio y **dejé dos fuera sin decirlo**. Ninguno de los dos es un patrón mal pensado.
> >
> > **La reconciliación con `8myvc-ff` cuadra al sitio: 7 + 5 + 1 = 13**, y **no había dos
> > definiciones, había dos coberturas** — las dos contamos *respuestas donde el campo
> > llega al cliente*. Su censo entero está en
> > [30](30-lo-que-reparte-una-columna-nueva.md) (rama `docs/barrido-profesor-serializado`,
> > sin fusionar). **Este renglón lo escribo yo y no cito el suyo**: dos documentos que
> > afirman lo mismo por separado valen más que uno citando al otro.
> >
> > **Lo que las dos medimos por separado y salió idéntico**, que es lo más parecido a una
> > prueba que hay aquí: los **tres falsos positivos** —`SELECT *` sobre una subconsulta
> > que **nombra** sus columnas, en `VtParticipante:79`, `PerfilesController:129` y
> > `:810`—, que **`DocentesExport` queda fuera** —trae `p.*` pero su Blade nombra 17
> > columnas y `tono` no está— y que **`ChangeAskedController::cambiarOficialProfesor`
> > tampoco cuenta**: hace `return $prof` y su único llamante **descarta el retorno**
> > (`:707`). Ése es el que más se parece a un sitio real y no lo es.
> >
> > *Lo de abajo, escrito con siete:*
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

**4 sep 2026 — `columnas-en-los-modelos.php` BORRABA ANOTACIONES A MANO, Y NADIE PODÍA
ENTERARSE** · `tools/columnas-en-los-modelos.php`, `app/Models/Subunidad.php`,
`tests/Unit/AutopruebasDeLasHerramientasTest.php`,
`tests/Unit/FuncionesGlobalesDeLasHerramientasTest.php` (nuevo), `docs/migracion/05-codigo-muerto-y-roto.md` ·
**cero rutas, cero esquema** · rama
`fix/columnas-en-los-modelos-no-borra` sobre `main` (`8f59242`) · lote repartido por
`8myvc-f5`, medido y arreglado por esta sesión en `.worktrees/a` /
`simonbolivar_testing_a` · **larastan `[OK] No errors`** · suite entera
**`Tests: 1933 passed (17365 assertions)`**, cero rojos

> ### Y LA PRIMERA VUELTA DE LA SUITE SALIÓ CON UN ROJO QUE NO ERA DE NADIE
>
> `Tests: 1 failed, 1930 passed` — `GruposTest > grupos-show`, con `+ 'tono' => 'null'` en
> la instantánea. **Ni del arreglo ni de la rama del horario**: el worktree nació de
> `0345ad5`, se rebajó a `main` con `git reset --hard`, y **la base de sesión se quedó con
> `profesores.tono` migrada**. Esa consulta hace `SELECT *`, así que la columna se coló en
> la respuesta.
>
> **Cambiar de rama en un worktree no deshace las migraciones de su base**, y el guardia de
> `construir-bd-test.sh` no lo ve porque compara árboles **al construir**, no después.
> Reconstruida la base desde el árbol de `main` —`PHP_EXEC` con `-w`, y comprobado con
> `Schema::hasColumn` imprimiendo `getDatabaseName()` al lado— `tono` desaparece y
> `GruposTest` pasa 15/15.
>
> **La salida fácil era regenerar la instantánea, y habría metido en `main` una columna sin
> su migración** — sin dejar rastro, que es lo que hace peligrosa esa tecla.

> La herramienta que genera las `@property` de los modelos lee
> `database/schema/mysql-schema.sql`, que está **congelado**. Una columna que entra por
> migración no está ahí, así que alguien la anota a mano — y la escribe donde están todas
> las demás, o sea **dentro** de las marcas, que es justo el trozo que la herramienta
> reemplaza entero. La siguiente corrida se la lleva.
>
> ### LA POBLACIÓN ENTERA, QUE ES LA MITAD QUE FALTABA DEL HALLAZGO
>
> Regenerando los **54** ficheros de modelo **sobre copias** en `/tmp` y diffeando
> `@property` a `@property` (no «ficheros que cambian», que es lo que decía la herramienta
> y no contesta la pregunta):
>
> | | |
> |---|---|
> | ficheros mirados | **54** — 53 en `app/Models` + `app/User.php` |
> | con bloque generado | **47** · **7** saltados por no encontrar su tabla |
> | `@property` dentro de las marcas | **760** · **77** fuera |
> | **se perdían** | **2** · **entraban 0** |
> | comentarios a mano sobre líneas generadas | **1** de 760 |
>
> Las dos, medidas sobre `0345ad5`: `Subunidad.rubrica_id` (migración de rúbricas) y
> `Profesor.tono` (migración del 4 sep). Sobre **`main` (`8f59242`) es una**, la de
> `Subunidad`: la de `Profesor` entra con la rama del horario.
>
> **Lo que NO miré**: los 7 sin tabla nunca reciben bloque, así que no pueden perder nada
> por aquí; y no miré si alguna de esas 760 líneas está *mal* — la pregunta era qué se
> borra, no qué se anota bien.
>
> ### Y LA DEDUCCIÓN QUE VENÍA EN EL LOTE ERA FALSA EN UN CASO — POR SUERTE
>
> El lote citaba `Year.php` (`regla_nivelacion`, `horario_version_id`) como víctima. **No
> lo es**: las dos viven **debajo** de la marca de fin, con su prosa al lado, y la
> herramienta no toca nada de ahí. `grep` en el volcado da cero para las dos y aun así
> están a salvo — o sea que *«no está en el volcado»* **no** es el detector de esto; el
> detector es *«no está en el volcado **y** está dentro de las marcas»*. Es la segunda
> forma de la regla de `CLAUDE.md`: el síntoma estaba bien contado y la causa que se le
> puso al lado era otra.
>
> **Y ese error fue el que dio el arreglo.** `Year.php` es la prueba viva de que el sitio
> seguro existe y ya se usaba.
>
> ### EL ARREGLO: MOVER, NO FUSIONAR
>
> Una `@property` de dentro de las marcas cuyo nombre no es columna de la tabla **se mueve
> literal —con su comentario— justo debajo de la marca de fin**, y se dice en pantalla.
> Después de una corrida, dentro sólo hay generado y fuera sólo hay mano.
>
> **Fusionar** —conservarla donde está— era la otra salida y se descartó con motivo:
> convierte el bloque generado en un sitio donde se puede escribir a mano, y entonces
> nadie que lo mire puede saber qué es qué. **Leer el esquema vivo** se descartó medido:
> el 4 sep, `years` daba **64** columnas en el volcado y **70** en `simonbolivar_testing_a`
> migrada — anotar desde ahí metería en los dieciséis colegios columnas que allí no
> existen.
>
> ### LO QUE SIGUE PERDIÉNDOSE, DICHO EN VOZ ALTA
>
> Un comentario a mano pegado a una línea que **sí** es columna se pierde igual: esa línea
> se regenera. No se puede conservar sin reabrir el bloque a la mano. **Ahora se avisa y se
> cuenta aparte** — que se pierda no es el fallo; que se perdiera callando, sí.
>
> ### AUTOPRUEBA, Y EJERCIDA CONTRA LA HERRAMIENTA VIEJA
>
> `--control`, seis formas, registrada en `AutopruebasDeLasHerramientasTest` (13 casos,
> todos verdes). **Se corrió contra el comportamiento de antes antes de darla por buena:
> sale `exit 1` con dos formas en rojo.** Un control que pasa con la herramienta rota es
> peor que ninguno.
>
> **Por qué hacía falta**: ninguna suite ejecuta `tools/` y borrar una `@property` no rompe
> nada — larastan sólo deja de saber que la columna existe. El daño sale semanas después,
> en otro fichero, como un nivel 7 que alguien «arregla» volviendo a anotarla dentro del
> bloque, para que la próxima corrida la borre otra vez.
>
> ### Y UN SEGUNDO HALLAZGO, QUE SALIÓ DE ESCRIBIR EL CONTROL
>
> Los ficheros de `tools/` son scripts sueltos **sin namespace**: sus funciones caen en el
> global. En ejecución da igual —cada uno corre en su proceso—, pero **larastan analiza la
> carpeta entera como un proyecto**. Este control se escribió con los nombres naturales,
> `casosDeControl()` y `control()`, que son los que ya usa
> `independientes-sin-estructura.php`, y larastan resolvió la llamada **contra la función
> del otro fichero**: `callable.nonCallable`, «Trying to invoke `array<string, mixed>`» —
> la firma del OTRO `casosDeControl`. La anotación de aquí era correcta y no se estaba
> usando.
>
> **Y la mitad que importa**: con `control()` —los dos devuelven `int`— **no salió ningún
> error**. La colisión que no cambia de tipo no se delata, así que se renombraron las dos
> (`formasDeControl`, `controlDeLasColumnas`) y no sólo la que cantó.
>
> O sea que **larastan no es el detector de esto**: lo fue por casualidad. Por eso el
> hallazgo entra como test propio y no como nota —
> `tests/Unit/FuncionesGlobalesDeLasHerramientasTest`—, que mira la carpeta entera y **dice
> su población**: **21 ficheros de `tools/`, 81 nombres de función distintos, 0 con
> namespace**. Sin esa línea, el día que alguien meta un `namespace` ahí el detector dejaría
> de encontrar declaraciones y **el cero se leería como «no hay colisiones»**.
>
> **Y se demostró rojo por las dos puntas**, porque un `uniq -d` vacío sin control negativo
> es el «0 encontrados» de siempre: un caso sintético ejerce el detector con ficheros que
> chocan —y con uno bajo `namespace` que **no** debe contar—, y además se metió a mano un
> `tools/zz-prueba-de-colision.php` declarando `tipoPhp()` para ver caer la aserción de
> verdad. Cae, y nombra los dos ficheros. Borrado.
>
> ### Y UNA FRASE QUE EL REPO SE CONTRADECÍA A SÍ MISMO
>
> [`05-codigo-muerto-y-roto.md`](05-codigo-muerto-y-roto.md) §9 decía que las columnas se
> generan «desde el **esquema real**», que es exactamente lo contrario de lo que hace la
> herramienta —lee el volcado **congelado**— y es de donde salía este fallo. Corregida con
> la medición delante. La frase buena **ya estaba escrita** en
> [`26-rubricas.md`](26-rubricas.md) §4.7 y la mala siguió viva al lado; el barrido lo hizo
> `8myvc-f5`, que encontró **tres** sitios: los otros dos son `CLAUDE.md` —que **no se ha
> tocado**, se lo lleva esa sesión a Joseth— y el propio 26, que es el correcto.
>
> ### PENDIENTE DE JOSETH — UNA, Y NO LA DECIDE UNA SESIÓN
>
> **¿Se refresca `database/schema/mysql-schema.sql` desde producción?** Mientras no se
> haga, cada columna nueva sigue anotándose a mano (ahora sin perderse). Refrescarlo es
> cambiar «la verdad» del esquema, con lo que eso arrastra: `CLAUDE.md` dice que ese
> volcado **es** la verdad, y el seed y la BD de tests salen de ahí. **No se ha tocado.**
>
> ### Y UN AVISO PARA LA RAMA DEL HORARIO
>
> `Profesor.tono` está hoy **dentro** de las marcas en `docs/horario-cuarta-ruta-y-despliegue`.
> Con esta herramienta ya no se borra —se mueve—, pero hasta que esta rama se funda, correr
> la versión vieja allí se lo lleva. Avisado a `8myvc-f5`.

**Anterior: 3 sep 2026 — EL CÍRCULO DEL HORARIO, CERRADO DE PUNTA A PUNTA CON DATOS REALES** ·
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

**3 sep 2026 — CORS SE QUEDA EN `*` POR DECISIÓN, Y EL FRENTE DE LOS `.env` CIERRA CON OCHO PENDIENTES ESCRITOS** ·
misma rama `docs/appkey-compartida-fortul-lal` · **FUNDIDA el 19 sep 2026** (decía «NO FUSIONADA, NO EMPUJADA», y lo fue dos semanas) · sólo
documentación · [`29`](29-los-env-no-son-uniformes.md) §5 y **§8 nuevo**,
[`DESPLIEGUE-REFERENCIA.md`](../DESPLIEGUE-REFERENCIA.md) · decidido por Joseth

> **`CORS_ALLOWED_ORIGINS` deja de ser un `PENDIENTE`: se queda en `*`.** Estaba definida en
> **1 de 17**, y la lectura fácil era «llevan dieciséis meses sin aplicar el arreglo del PR #3».
> **La decisión de Joseth es que `*` es el valor bueno**, porque *cada colegio recibe conexiones
> de aplicaciones externas* y una lista blanca no describe a los clientes reales de esta API.
>
> **Y se comprobó antes de darlo por bueno, que es lo que hacía falta:** `supports_credentials`
> es **`false`** (`config/cors.php:71`) y el token viaja en `Authorization: Bearer`
> (`Sesion.php:412`), **no en cookie**. El navegador no manda credenciales a otro origen y el
> JavaScript tiene que adjuntar el token a mano, así que una página cualquiera puede *llamar* a
> la API pero **sigue necesitando un token**, y el token vive en el almacenamiento del cliente
> legítimo, que es por origen. ***`*` abre la puerta; no reparte llaves.***
>
> > **Lo que obligaría a volver aquí, y por eso queda escrito**: poner `supports_credentials`
> > en `true` —el navegador **rechaza** esa combinación con `*`— o mover la sesión a cookie.
> > **Las dos son un cambio de una línea en `config/`**, o sea que este razonamiento descansa
> > en dos valores que hoy no vigila nadie.
>
> **`FRONTEND_URL`, también 1 de 17, no es lo mismo y no se cierra:** es el respaldo de la URL
> de retorno del reseteo, y si falta y el cliente no manda una `ruta` del mismo host, el método
> hace `abort(422)`. Hoy no afecta a nadie **y el motivo correcto no es que todos compartan
> host** —la app Flutter no lo hace— **sino que la app Flutter no tiene recuperación de
> contraseña**. El día que se la añadan: **422 en dieciséis**. Estaba escrito como hipótesis en
> el [04](04-auditoria-autenticacion.md); ahora está contado.
>
> ### EL BARRIDO DE LAS SIETE VARIABLES ESTÁ COMPLETO, Y CINCO DIERON HALLAZGO
>
> El §7 proponía un bucle de siete variables sobre los diecisiete y decía que era «lo único que
> separa *no se sabe* de *está bien*». **Corrido entero**: `APP_KEY` compartida entre dos
> colegios, el correo sin configurar en dieciséis, `APP_DEBUG` encendido en cinco,
> `CORS_ALLOWED_ORIGINS` en uno —y era la decisión correcta— y `FRONTEND_URL` en uno. **Y una
> octava que nadie pidió: cinco juegos de claves distintos entre diecisiete**, que es la fila
> que sostiene el título del documento.
>
> ### LOS OCHO PENDIENTES, EN EL §8 DEL 29, CON SU CONSECUENCIA Y DE QUIÉN SON
>
> Ninguno bloquea nada hoy; están escritos para no tener que volver a descubrirlos. Los tres
> primeros por consecuencia: **(1)** la instalación viva de `lal` en la cuenta vieja, que el
> censo **no alcanza** —es la número dieciocho y su correo sigue como estaban los otros—;
> **(2)** de diecisiete envíos volvieron **cuatro** rebotes, y un límite por hora convertiría
> una tanda de recuperaciones en correo perdido **sin ningún error**; **(3)** el logo del correo
> sale de `lalvirtual.edu.co`, así que **el correo de los dieciséis depende de que un colegio
> conserve su dominio**.
>
> **Y la limpieza de variables muertas va la última a propósito** —`JWT_*` en 16 de 17,
> `AWS_*`, `PUSHER_*`, `MEMCACHED_*`, `REDIS_*`—: es segura y **no arregla nada**. *Borrar
> líneas que no hacen nada no mejora ningún comportamiento, y tocar diecisiete `.env` sí tiene
> riesgo.*


**Anterior: 3 sep 2026 — `APP_DEBUG=true` EN CINCO COLEGIOS, Y EL ARREGLO DE CORS SIN APLICAR EN DIECISÉIS** ·
misma rama `docs/appkey-compartida-fortul-lal` · **FUNDIDA el 19 sep 2026** (decía «NO FUSIONADA, NO EMPUJADA», y lo fue dos semanas) · sólo
documentación · [`29`](29-los-env-no-son-uniformes.md) §5 y §6 · **censado y corregido en el
servidor por Joseth**, escrito por esta sesión

> **El pendiente más viejo de la lista ya está medido, y estaba encendido.** `01:395` decía
> «**Verificar producción**» y `09:1159` «comprobarlo colegio a colegio» desde el principio,
> porque hacía falta la sesión del servidor. Censado: **`APP_DEBUG=true` en 5 de 17** —
> `caz-zaragoza`, `coabsaravena`, `coal`, `inseaq`, `maranathaarauca`—. **Ya no**: puestos a
> `false` con respaldo fuera del docroot y `config:clear && config:cache`, los cinco `OK`.
>
> **No era teórico**: el cuerpo de cualquier 500 lleva `Host`, `Port` y `Database`, y hay **doce
> rutas públicas sin token**, entre ellas `PUT login/crear-prematricula` —viva, con
> `withoutMiddleware('auth.token')`—, que es la que la casilla 1bis describe llegando a un 500.
>
> **Y `CORS_ALLOWED_ORIGINS` está en 1 de 17** (`FRONTEND_URL`, también en 1). Ausente,
> `config/cors.php` cae a `['*']`: **el punto 2 del PR #3 no hace nada en dieciséis**. Es la
> tercera vez esta noche que un `PENDIENTE` resulta estar sin aplicar en casi todos —correo,
> CORS— y **la primera que se cuenta**. *Éste no se cierra con un bucle: el valor es el dominio
> de cada colegio, así que es una decisión de Joseth antes que un script.*
>
> ### LA FILA QUE SOSTIENE EL TÍTULO DEL DOCUMENTO
>
> **Los diecisiete tienen CINCO juegos de claves distintos** (6 · 8 · 1 · 1 · 1). No divergen
> sólo en valores: divergen en **qué líneas existen**. `JWT_SECRET` está en **16 de 17** y es
> peso muerto —`tymon/jwt-auth` se quitó al saltar a Laravel 10, no hay `config/jwt.php`, el
> paquete no está en `composer.json`, `composer.lock` ni `vendor/`, y `jwt:secret` no existe—.
> `FCM_CREDENCIALES` y los cuatro `SESION_*_TTL`: **0 de 17**.
>
> ### DOS SOSPECHAS MÍAS QUE LA MEDICIÓN TUMBÓ
>
> 1. **`APP_ENV=local` en quince asusta y no hace nada.** Sólo `demo` y `eal` están en
>    `production`. Pero **el código no consulta `APP_ENV` ni una vez** —cero usos de
>    `App::environment()`, `app()->environment()` y `config('app.env')` en `app/`, `config/`,
>    `bootstrap/` y `routes/`, contados—. Lo que sí cambia: **artisan no pide confirmación**
>    para comandos destructivos, así que un `migrate:fresh` correría sin preguntar en quince.
>    *Riesgo operativo, no de exposición, y conviene no venderlo como lo segundo.*
> 2. **El «`\r` de Windows» no existía.** Tres censos salieron con `FILESYSTEM_DRIVER=local`
>    pegado a `QUEUE_CONNECTION=sync` sin espacio, y la hipótesis razonable era un CRLF, que
>    metería el `\r` dentro del valor. `cat -A` sobre los cuatro: `FILESYSTEM_DRIVER=local$`,
>    fin de línea limpio. **Era el terminal comiéndose un espacio al ajustar la línea.**
>    *El primer intento de medirlo además estaba mal escrito —`grep -c` imprime `0` **y** sale
>    con código 1, así que el `|| echo 0` añadía un segundo cero y `[` reventaba con
>    `"0\n0"`—: los diecisiete errores insinuaban la respuesta buena por el motivo equivocado.*
>
> ### Y UN FALLO MÍO EDITANDO, QUE ES EL DEL PROPIO DOCUMENTO
>
> Reescribir el §5 entero **huerfanó una subsección de `myvc-horarios-42`** —la de cómo NO medir
> CORS, con lo del `Origin` como *forbidden header name*—: mi reemplazo cortó en el primer `---`
> y dejó sus 31 líneas colgando **bajo el §6**, en la sección equivocada. **No da error y el
> fichero se lee casi bien.** Devuelta a su sitio y comprobada contra `main`: **31 líneas y 31,
> diff vacío, ni una perdida.** *Es exactamente la familia que este documento persigue, cometida
> dentro del documento que la persigue.*


**Anterior: 3 sep 2026 — EL CORREO ESTABA CAÍDO EN LOS DIECISÉIS COLEGIOS REALES, Y EL ÚNICO CONFIGURADO ERA `demo`** ·
misma rama `docs/appkey-compartida-fortul-lal` · **FUNDIDA el 19 sep 2026** (decía «NO FUSIONADA, NO EMPUJADA», y lo fue dos semanas) · sólo
documentación · [`29`](29-los-env-no-son-uniformes.md) §3 · **censado, arreglado y
verificado en el servidor por Joseth**, escrito por esta sesión

> **El §3 del 29 decía «en cuántos de los dieciséis se aplicó no lo sabe nadie». Ya se sabe:
> en UNO, y era `demo`.** El censo de los cuatro `MAIL_*` sobre las diecisiete carpetas dio
> **dieciséis idénticos** —`smtp` + `mailhog` + `MAIL_FROM_ADDRESS=null` +
> `MAIL_FROM_NAME="${APP_NAME}"`, el andamiaje de desarrollo intacto— y **uno distinto**,
> `demo`, con el `sendmail` del PR #3 y el remitente del dominio que no existe.
>
> **No era un riesgo, era una función caída.** `null` hace que Laravel rechace el envío antes
> de intentarlo, así que **el reseteo de contraseña devolvía 500 en los dieciséis colegios
> reales** desde que se cambió `mail()` por `Mail`. Nadie lo vio porque el reseteo se usa menos
> de una vez al día y el síntoma es correo que no llega. *`cads-itagui` no era una excepción:
> era la única muestra que alguien había mirado.*
>
> ### Y EL DOCUMENTO SE CORRIGE EN SU PROPIA TESIS
>
> Se titula «los `.env` no son uniformes», y para `APP_KEY` lo eran de menos (§1). **Para
> `MAIL_*` salió lo contrario: dieciséis idénticos.** El daño no lo hizo la divergencia sino
> que **toda la documentación daba por aplicado un cambio que no estaba en ningún sitio**, y un
> `PENDIENTE` se leyó meses como «pendiente en alguno». *«Cada uno tiene lo suyo» y «todos
> tienen lo mismo» son **igual de indistinguibles** desde el repositorio; lo único que las
> separa es el bucle.*
>
> ### EL ARREGLO
>
> Los cuatro campos en los diecisiete, con respaldo de cada `.env` **fuera del docroot** y
> `config:clear && config:cache` detrás: **17 tocados, 0 saltados**, y el `diff` de los dos
> censos mueve las diecisiete líneas.
>
> **Y llegó.** `correo:probar` contra un buzón real, 3 sep 2026: el correo **sale, se releva y
> se entrega**. Es lo único que ningún paso anterior demostraba —todos probaban que la
> configuración era correcta, no que el mensaje llegara—.
>
> **La primera pasada rebotó, y el rebote enseñó más que un envío bueno.** Fue a un marcador de
> posición que resultó ser **un dominio real con MX** (`550 5.1.1`, usuario desconocido), y sus
> cabeceras traían `X-Postfix-Sender: admin@micolevirtual.com` —el arreglo llegó al envío, no
> sólo al fichero— y `Reporting-MTA: relay.mailchannels.net`. **Eso último corrige el
> razonamiento del SPF de esta misma noche**: el servidor **no envía directo**, sino que releva
> por MailChannels, que el SPF autoriza por `include`. La IP que envía **nunca** es la del
> servidor, así que excluir `lal` por estar en `.70` no tenía fundamento. *Era correcto para un
> modelo de envío que este servidor no usa, y sólo se vio leyendo las cabeceras de un mensaje
> real.*
>
> **Y una cuenta que no cuadra:** de los diecisiete envíos volvieron **cuatro** rebotes, no
> diecisiete. Cola, límite de MailChannels o no salieron: **no se sabe**, y la diferencia
> importa, porque un límite por hora convertiría una tanda de recuperaciones en correo perdido
> sin ningún error.
>
> ### TRES COSAS QUE SE MIDIERON POR EL CAMINO Y CORRIGEN LO ESCRITO
>
> 1. **`MAIL_FROM_NAME="${APP_NAME}"` no estaba roto.** Llegó de otra sesión como «hereda
>    *Laravel*»; el Dotenv de Laravel **sí interpola** —comprobado ejecutándolo—, así que
>    resolvía al `APP_NAME` de cada colegio. Una variable **sin definir** sí se queda literal, y
>    ésa es la forma en que este campo sí podría fallar.
> 2. **Excluir `lal` del script era un error mío.** Lo excluí por SPF razonando sobre dónde
>    *sirve* `lal` (`.70`), pero **la carpeta que el script toca está en `micolev1`, que es
>    `.72`**, donde el remitente sí está autorizado. *El razonamiento del SPF era correcto y se
>    aplicó al servidor equivocado.*
> 3. **Los respaldos iban dentro de una carpeta servida por web.** El docroot es la carpeta del
>    colegio, así que `8myvc/` cuelga dentro. Se comprobó que `.env` **no** se descarga —devuelve
>    la página del front, mientras `composer.json` sí se sirve—, pero **eso no dice nada de un
>    `.env.bak-*`**, y averiguarlo exigía crear uno, que es el riesgo. Los respaldos se movieron
>    a `~/respaldos-env/`, fuera de todo docroot, con permisos 700.
>
> > **Y un aviso sobre medir con códigos de estado**, que casi produce un «está bloqueado,
> > tranquilo» falso: la primera prueba dio **403** y la segunda dio **200 para todo**, incluida
> > una ruta inexistente. Ese servidor **contesta 200 a cualquier cosa** con la página del
> > front. Hubo que comparar **el contenido**, no el código. *Un 403 y un 404 pueden significar
> > lo mismo que un 200 si no se mira lo que viene dentro.*


**Anterior: 3 sep 2026 — `APP_KEY` COMPARTIDA ENTRE `fortul` Y `lal`: LA PREMISA ERA FALSA, Y LA CAUSA ESTABA ESCRITA EN NUESTRO PROPIO PROCEDIMIENTO** ·
rama `docs/appkey-compartida-fortul-lal` · **FUNDIDA el 19 sep 2026** (decía «NO FUSIONADA, NO EMPUJADA», y lo fue dos semanas) · sólo documentación:
**cero código, cero rutas, cero tests** — 566 sin moverse ·
[`29-los-env-no-son-uniformes.md`](29-los-env-no-son-uniformes.md) §1,
[`TRASLADO-LAL.md`](../TRASLADO-LAL.md) paso E, [`05`](05-codigo-muerto-y-roto.md) ·
**medido por Joseth en el servidor**, escrito por esta sesión

> **El §1 del 29 deja de ser una pregunta.** Se escribió el 2 sep como «nadie las ha
> comparado» y la respuesta llegó al día siguiente: sobre las **diecisiete** carpetas,
> **`fortul` y `lal` tienen el mismo `APP_KEY`** (`42bb720f546f`); los otros quince, distintos.
>
> **Y no se llamó hallazgo hasta descartar las lecturas benignas**, porque un hash repetido
> también sale de dos colegios *sin* clave, que sería otro problema: sin línea `APP_KEY` da
> `d41d8cd98f00`, `APP_KEY=` vacía da `adef725c7222`, truncada `75f9a7bb3d5c`, el
> `SomeRandomString` del andamiaje `1dbf3bbd1d09`. **Ninguno es el que salió.** Clave real.
>
> ### LA CAUSA, QUE ES LO QUE DE VERDAD SE ARREGLA
>
> No fue un descuido: **el procedimiento lo mandaba hacer así**. El paso E de `TRASLADO-LAL.md`
> —como nació `lal` el 30 ago— decía *«nano .env — **SOLO** `DB_DATABASE`, `DB_USERNAME`,
> `DB_PASSWORD`»*. El `git clone` **no trae `.env`**, así que el fichero salió de una copia de
> otro colegio y **todo lo que ese paso no nombraba se heredó**. `fortul` fue el donante.
> **Y `key:generate` no aparecía en ningún procedimiento del repositorio** —comprobado con
> `grep` sobre `docs/` y `CLAUDE.md`: sus cinco menciones estaban todas en textos que *suponían*
> que se corría—. La premisa «`key:generate` hace uno por instalación» describía **un paso que
> nadie tenía escrito**. Añadido al paso E, que es el arreglo que impide el próximo.
>
> ### EL RADIO, ACOTADO ANTES DE ALARMAR A NADIE — Y LA PRIMERA LECTURA ERA PEOR QUE LA VERDAD
>
> La sospecha era que `APP_KEY` firmara los tokens, y entonces **un token de un colegio valdría
> en otro hoy**. **No los firma**, y se midió en vez de suponerse: `APP_KEY` se lee en
> **exactamente dos sitios** (`config/app.php:136` y `config/notificaciones.php:38`); en `app/`
> hay **cero** usos de `Crypt::`, `encrypt(`, `decrypt(`, `signedRoute`, `hasValidSignature` y
> `temporarySignedRoute`; y **el token no es una firma, es una fila** —`findToken()` busca un
> hash **en la base de ese colegio**—. **Nadie estuvo expuesto.** Lo que hacía era bloquear el
> push, y ahí el solape no habría sido raro sino **casi total en los ids bajos**, porque cada
> base tiene su autoincremento y el `alumno_id` 345 existe en los dos.
>
> ### EL ARREGLO, Y POR QUÉ SÓLO SE TOCÓ UNO
>
> **Se rotó `lal`, no `fortul`.** `lal` **todavía no sirve desde `micolev1`** —sigue en la
> cuenta vieja y su subdominio ni resuelve—, así que esa carpeta es una copia preparada y
> rotarla no le tocó a nadie; `fortul` está vivo y se quedó igual. Era doblemente gratis:
> **al completar el traslado, la clave de `lal` iba a cambiar de todos modos.**
>
> **Verificado con la población delante y comparando los dos censos, no con un vacío a pelo**:
> población **17**, repetidos **0**, y **17 hashes distintos de 17** —que es más fuerte que «sin
> repetidos»—; **cambió exactamente una fila**, `lal` de `42bb720f546f` a `136f77f109de`.
> *Esa última cifra es la mitad importante:* «ya no hay repetidos» lo cumpliría igual un `.env`
> roto por el camino o un bucle que dejara de ver carpetas. `fortul` conserva la suya y los
> otros quince están donde estaban.
>
> ### TRES COSAS QUE QUEDAN ESCRITAS Y NO SON DE ESTE ARREGLO
>
> 1. **Rotar `APP_KEY` con el push encendido re-apunta todos los temas** y los teléfonos
>    siguen escuchando el nombre viejo: los avisos dejan de llegar **sin un solo error**. Toda
>    rotación futura va **antes** de encender Firebase, o con resuscripción de la app.
> 2. **El censo NO cubre la instalación viva de `lal`**, que está en la otra cuenta: barre
>    `/home/micolev1/*`. Hay **una instalación dieciocho fuera del censo** y su clave sigue sin
>    medir. Hoy no cambia nada; el día que se diga «están todas comprobadas», esa no lo está.
> 3. **Las otras seis variables del §7 siguen sin mirarse.** Que la primera que se miró diera
>    positivo no las rompe: las deja igual de sin medir, sólo que ahora «se creó copiando»
>    produce colisiones **medidas** y no teóricas.
>
> ### Y DOS LECCIONES DEL DETECTOR, QUE SON LA FAMILIA DE SIEMPRE
>
> **Un `uniq -d` a secas dice QUE hay un repetido y no dice ni cuál ni cuántos.** La primera
> salida fue exactamente eso, y no se pudo escribir nada hasta la segunda pasada con el nombre
> al lado y la población al final. **Y una salida vacía sin población no distingue «miré
> diecisiete y ninguno» de «el bucle no miró nada»** — por eso la verificación del arreglo se
> pidió con `tee` y `wc -l`, no como un vacío a pelo.
>
> > **Sobre la corrección de nombres de arriba, y va aquí porque me alcanza:** esas entradas me
> > llaman **`8myvc-d5`** —por el sufijo de mi worktree—, pero mi propio `ListAgents` me nombra
> > **`8myvc-1f`**. No sé cuál es el bueno y **por eso esta entrada no firma con ninguno**: dice
> > quién midió (Joseth, en el servidor) y quién escribió (esta sesión). *Es el mismo fallo que
> > se acaba de corregir arriba: un nombre copiado de buena fe que resuelve a otro sitio.*


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

**Última actualización: 1 sep 2026, noche — LA ÉPICA DEL CALENDARIO: PASOS 1, 2 Y 3 HECHOS EN
`feat/calendario`, SIN FUSIONAR Y SIN DESPLEGAR** · migración + `PUT calendario/mes` y
`PUT calendario/proximos` + guardado con cuerpo, recordatorio y destinatarios · **dos rutas nuevas**
· detalle completo en [22-calendario.md](22-calendario.md) · lo coordina la sesión de front
`myvc-front-14`; la pantalla la escribe `myvc-front-08`, con el contrato confirmado con los dos

> **Los cumpleaños dejan de ser filas y pasan a calcularse al pedir el mes.**
> `putSincronizarCumples()` **sella el año** —los 507 cumpleaños de la base caen todos en 2025 y en
> 2026 no hay ninguno—, **congela la matrícula** y **empieza por un `DELETE` sin `WHERE` de año**.
> Los tres son el mismo fallo: estado derivado que se guarda. **Las 507 filas NO se borran todavía**
> (es el paso 5, y son la red hasta que la pantalla nueva funcione), así que `calendario/mes` las
> excluye para no pintar cada cumpleaños dos veces.

> ### EL HALLAZGO QUE NO BUSCABA NADIE: «no tocar `this-year`» ERA LO QUE LA TOCABA
>
> El encargo insistía en que `calendario/this-year` se quedara **exactamente** como está.
> `putThisYear()` hace `SELECT * FROM calendario`, así que **las tres columnas nuevas de la migración
> se colaron solas en su respuesta** — sin escribir una línea en esa ruta. Y no era una: `grep` sobre
> `app/` da **siete** lectores con `*`, dos de `putThisYear()` y **cinco de `ChangeAskedController`**.
>
> **Se vio porque movió dos instantáneas**, no porque nadie lo pensara. Y **sólo una de las cinco de
> `ChangeAsked` está cubierta por el muestreo: las otras cuatro habrían llegado a producción
> calladas** — por eso el arreglo se hizo contando la población entera con `grep`, y no fichero a
> fichero según lo que se pusiera rojo. La lista vive ahora en **un solo sitio**
> (`CalendarioController::COLUMNAS`): con siete copias, la próxima columna entra por las seis que
> alguien olvide.
>
> Es lo mismo que se pagó el 24 ago en los cuatro `SELECT *` sobre `matriculas`. **La forma general,
> que es lo que se hereda: añadir una columna a una tabla es tocar todas las respuestas que la leen
> con `*`, y una épica puede prometer «esta ruta no se toca» y romperla sin tocarla.**

> ### DOS CORRECCIONES AL ENCARGO, Y LA PRIMERA HABRÍA ABIERTO 37 EVENTOS INTERNOS
>
> 1. **«Sin filas de destinatarios = público» rompía `solo_profes`.** Los eventos internos que ya
>    existen no tienen filas —la tabla es nueva— y la aplicación vieja y `myvc_flutter` van a seguir
>    creándolos así mientras los quince no estén desplegados. Medido por la coordinación del front:
>    de los 139 eventos manuales vivos, **37 tienen `solo_profes = 1`**, y con la regla literal **el
>    día del despliegue esos 37 se vuelven públicos, sin ningún error**. La regla que entra: sin filas
>    y `solo_profes=0` → público; **sin filas y `solo_profes=1` → sólo personal**; con filas → mandan
>    las filas.
> 2. **El personal lo ve todo.** El encargo lo filtraba como a los demás; con eso **el docente que
>    acaba de crear «Salida de 7º» no la vería en su propio calendario**. Los destinatarios existen
>    para no llenar de ruido a las familias, no para esconderle el calendario al colegio.

> ### LA DECISIÓN QUE ESPERA, Y ES LA ÚNICA QUE CAMBIA LO QUE VE UN COLEGIO
>
> **Los cumpleaños de los alumnos RETIRADOS siguen saliendo.** `putSincronizarCumples()` une
> `matriculas` sin mirar `estado`, así que las 507 filas de hoy ya los incluyen, y se ha mantenido la
> paridad **a propósito** para no cambiar quién sale por la puerta de atrás. **No es un matiz:** en el
> seed hay **59 `RETI` contra 65 `MATR`**. Si el colegio no quiere el cumpleaños de quien ya no está,
> se toma añadiendo `m.estado IN (...)` en `cumplesDelRango()`. Es de Joseth.

> ### DOS INSTRUMENTOS QUE FALLARON, Y LOS DOS SON MÍOS
>
> 1. **Escribí en el árbol principal creyéndome en mi worktree.** Un `cd` al principal en el mismo
>    comando que lanzaba la suite, **el directorio de trabajo persiste entre llamadas**, y tres
>    comandos después un script con ruta relativa aterrizó allí. Es literalmente el incidente del 31
>    ago —«estado que persiste donde no lo estás mirando»— y **la regla que ese incidente dejó
>    escrita, rutas absolutas, es la que no seguí**. Revertido con `git checkout --` de ese fichero
>    solo, comprobado después, y contado a la otra sesión de backend antes de que lo viera ella.
> 2. **Atribuí por descarte.** Vi dos documentos sin commitear en el principal, vi que `8myvc-ab`
>    había desaparecido de `ListAgents`, y **junté las dos cosas sin comprobar ninguna**: avisé de
>    «huérfanos de una sesión muerta». Eran de `8myvc-2d`, viva y escribiéndolos. Lo corrigió ella
>    con el dato que yo no tenía —había corrido `git status` en limpio justo antes—. **Que una sesión
>    no salga en `ListAgents` demuestra que ella no está, no que el trabajo sin commitear sea suyo.**
>    Mi aviso era más peligroso que el error que venía a contar: le habría dicho a la siguiente sesión
>    que «rescatara» documentos vivos.
>
> Y una tercera, que sí funcionó y por eso se cuenta: **el centinela de
> `FamiliasQueNuncaEntranTest` saltó con las dos rutas nuevas** (23 → 25 escrituras en familias que
> el candado no mira). Está escrito para que subir el número sea **una decisión escrita y no un
> ajuste**, así que lo que se hizo fue explicar en su docblock por qué esas dos están bien —no tienen
> guard y no deben tenerlo, pero **preguntan de quién es cada fila dentro del método**— en vez de
> cambiar el 23 por un 25 y seguir.

> ### AL FUSIONAR — hay OTRA sesión moviendo los mismos snapshots
>
> `8myvc-2d` añade **dos rutas** en el árbol principal (548 y 549) y esta épica añade **otras dos**.
> Acordado: **fusiona ella primero y remido yo**. Con las cuatro dentro son **551**, y hay que mover
> `rutas.json`, `guards-por-ruta.json`, `guard-por-familia.json`, **más** los dos de
> `FamiliasQueNuncaEntran` y la cifra de `CLAUDE.md`. **Los snapshots se borran y se regeneran, no se
> resuelven a mano.**

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
autenticación real. Hoy: **568/568 rutas con la respuesta comprobada — el 100% —,
101/101 controladores, larastan nivel 7 `[OK]`, pint PASS.**

> **REMEDIDO el 4 sep 2026, y el resultado es mejor que el número:** aquí decía
> **542/542** y **98/98**, que era la cifra del 25 ago. Han entrado **26 rutas** desde
> entonces y **el 100% no se ha roto ni una vez** — o sea que lo que envejeció fue el
> número, no la propiedad. Medido con la suite entera (**1.973 tests, 17.675
> aserciones, 766 s**) contra una base de sesión propia y
> `tools/cobertura-de-rutas.py`: **568/568, 101/101 controladores, 0 a medias y 0 donde
> nadie mira ninguna respuesta**.
>
> **Y con qué suite se midió, que aquí decide el número:** el número citable es el de
> la **suite entera**, no el de `--testsuite=Contrato` — `GET /` sólo la toca el stub de
> `laravel new` y con Contrato a secas cae siempre del lado de las no comprobadas.
>
> **Los dos barridos siguen sin contar como comprobar**, y ésa es la mitad que hace que
> el 100% signifique algo: `AutenticacionTest` toca **549** rutas en una ejecución y
> `RutasPreLoginTest` **567**. Un test que las recorre todas dice que la ruta existe y
> que su guard es el que era; **no mira lo que devuelve**, y por eso la herramienta los
> descarta por encima de 25 rutas en un solo caso.
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

- **La hora mal escrita** en filas ya guardadas — **MEDIDA el 5 sep 2026, y esta
  entrada era la que tenía parada la decisión** ([05 §247](05-codigo-muerto-y-roto.md)).
  Decía *«se midió y el dato no distingue una fila mal escrita de una normal»*, y
  eso es cierto **de una fila suelta** y falso del conjunto: en `hora:hora:minutos`
  los minutos se quedan con el valor de la hora, así que una fila del bug cumple
  **`HOUR = MINUTE`** siempre, y una sana ~1 de cada 60. Se comprobó también para
  las horas de una cifra —`G` da `"9"` y `H` da `"09"`— contra **MySQL 8.0.42 y
  MariaDB 10.5.29**, que es la serie de producción: las dos guardan la cadena sin
  warning y la firma se mantiene. En `simonbolivar`: **0 de 85** en
  `change_asked.deleted_at` y **0 de 7** en las ausencias del lector, con los
  cuatro controles en ruido. **Lo que falta no es una decisión, es una vuelta**:
  correr `tools/hora-escrita-dos-veces.php` en los diecisiete —sólo lectura, sin
  migración, una visita— y cerrar con eso. Las tres opciones con su precio están
  en la §247, y ninguna toca la tanda del día 10.

  > ## LA VUELTA SE DIO — 6 sep 2026, y la copia local decía CERO
  >
  > Joseth la corrió en el servidor con la forma que **no supone credenciales** (una carpeta
  > por colegio, cada uno con su `.env`): **17 medidos, 0 NO MEDIDOS, 13 CON DAÑO**. Detalle
  > y cifras por colegio en [05 §250](05-codigo-muerto-y-roto.md).
  >
  > - **708 filas** de `change_asked.deleted_at`, en doce colegios (de 9 a 185 por colegio).
  > - **~25.200 filas** de `ausencias.created_at` y `updated_at`, en ocho. **Seis columnas
  >   salen al 100 %.**
  > - **`ausencias.fecha_hora` NO está dañada en ninguno**: la hora a la que llegó tarde el
  >   alumno está bien. Lo dañado son **marcas de auditoría**.
  >
  > **Y el dato SE RECUPERA**, que es lo que cambia la decisión: el campo de los minutos
  > lleva la hora y el de los segundos el minuto real, así que
  > `DATE(col) + TIME(HOUR(col), SECOND(col), 0)` devuelve hora y minuto y **sólo pierde los
  > segundos**. La §247 decía «no puede recuperar los segundos» —cierto— y se leía como «no
  > se puede recuperar».
  >
  > **LO QUE ESPERA A JOSETH, y ya no es «migración o nota»:** reparar, sabiendo que el
  > filtro `HOUR = MINUTE` estropea las filas sanas escritas en el minuto `:HH` de su hora
  > —despreciable donde la columna sale al 100 %, unas 3-4 filas en `cads_itagui`, que sale
  > 90 de 214—, o dejarlo escrito y no tocar producción. **En cualquier caso va DESPUÉS del
  > día 10**: una migración de datos sería la octava de una tanda congelada en siete.
  >
  > ⚠️ **El hallazgo de método, que vale para todo lo demás que se mida aquí:** esta misma
  > medición sobre la copia de desarrollo dio **CERO**, con su piso de detección al lado y
  > sin que nadie se equivocara. **0 contra 13 de 17.** Comprobado hoy: la copia local tiene
  > 85 filas con `deleted_at`, ninguna con la firma, mientras el colegio del mismo nombre en
  > producción tiene 87 y 67 con firma — **no son los mismos datos**. *Un piso de detección
  > dice qué no podías ver en la base que miraste; no dice que estuvieras mirando la base
  > que importa.*
- **Los interruptores `para_*`** — hay que contestarlos con los tres delante.
- **Quién del personal puede qué** — cinco lotes preguntan variantes.
- **Los quince números de la fase 0** de definitivas: la herramienta está, hay
  que correrla en el servidor colegio por colegio (`for` de una línea en el 10).

  > **Y antes de ir al servidor, léete esto, que se escribió el 5 sep 2026 y puede
  > ahorrarte el viaje.** `fase-cero-de-los-dieciseis.php` **supone que UNA
  > credencial alcanza las bases de TODOS los colegios** —cambia el nombre de la
  > base y se queda con el usuario del `.env`—, y en un cPanel eso no es lo normal.
  > **Nadie lo ha comprobado**, y no se puede desde aquí. Lo destapó `8myvc-4d`
  > escribiendo una herramienta con la misma forma. **No es riesgo de números
  > falsos**: si no alcanza, lo nota al abrir y sale con código 2 diciendo cuántos
  > colegios quedaron NO MEDIDOS. La forma que no supone nada —una vez por carpeta,
  > con el `.env` de cada colegio— está escrita en la cabecera del propio fichero.
  >
  > **Y el barrido que salió de ahí, del mismo día ([05 §248](05-codigo-muerto-y-roto.md)):
  > la suposición la llevan DIEZ herramientas de `tools/`, no una.** Lo que se midió
  > no fue cuántas —eso se lee— sino **qué hace cada una al chocar**, corriéndolas
  > con `DB_DATABASE` apuntando a una base que no existe: **siete lo dicen** (código
  > 2 y aviso por stderr) y **tres se callaban** —`salud-de-la-bitacora`,
  > `historial-que-cuenta-de-menos` y `coste-del-recalculo`—, porque el `bootstrap()`
  > de Laravel pinta la excepción **por stdout** y el proceso **sale con 0**. *En
  > `tools/`, «no petó» y «el `$?` fue 0» no son lo mismo.* **Ya están las tres
  > arregladas** con la guardia que ya tenían sus hermanas. Y la frase de arriba
  > —«no es riesgo de números falsos»— **es cierta de `fase-cero`, comprobada, y era
  > falsa del barrido de la bitácora**: su `for` documentado llevaba `| tail -1`, que
  > se tragaba el error y metía **una línea en blanco** en el CSV con código 0. Ese
  > bucle también está corregido.
  >
  > **Y la segunda vuelta, del mismo día: el código 0 no lo produce la base, lo
  > produce `bootstrap()`**, así que alcanza a **cualquier** guion de `tools/` que
  > arranque el framework. De las siete que quedaban sin medir, **seis se
  > callaban**; llevan guardia **cuatro** —`generar-seed-test` y `route-emit`
  > porque **escriben** (el seed y `routes/api/`), `auditar-autenticacion` porque
  > una lista de guards corta tiene la forma de un agujero, e `indices-que-faltan`
  > porque su cero se lee como «no falta ningún índice»— y **se dejan dos**
  > —`route-table-dump` y `route-match-check`—, cuya salida **es** el fichero que
  > un humano compara: si fallan, el `diff` sale entero. *La guardia se pone por
  > la consecuencia, no por la línea que falta.*
  >
  > **Dos avisos para quien vaya a correr algo de `tools/`:** `route-emit` escribe
  > los ficheros de rutas —una corrida **sana** modifica **13 de 17**, así que no
  > es un no-op—, y la guardia **no coge un error de sintaxis**, que es un fatal
  > de compilación y no un `Throwable`. Las dos cosas están medidas en la §248.
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

---

## LICENCIA DEL HORARIO — el emisor, medido y a medio escribir (6 sep 2026, worktree `l`)

**Rama `feat/licencia-del-horario`. Sin fundir.** Documento entero:
[31-licencia-del-horario.md](31-licencia-del-horario.md).

`myvc_horarios` (el programa de escritorio que cuadra el horario) lleva una licencia
firmada, y **su decisión 25 dice que la emite `8myvc`**. Este carril es esa mitad. Antes de
escribir nada se comprobó que no estuviera hecho: `grep -rniE 'ed25519|sodium_crypto_sign'
app/ routes/ config/` salía **vacío**.

**Lo que está hecho y verde** — `licencia:emitir`, un comando de consola (**no una ruta**:
ninguna ruta nueva se ha escrito, y por eso el contador de `CLAUDE.md` **no se mueve**):

    app/Services/FirmaDeLicencia.php          firma, y sólo eso; la clave entra por constructor
    app/Console/Commands/EmitirLicencia.php   `licencia:emitir`, y `--publica` para los 32 bytes de Rust
    config/licencia.php                       una RUTA a un fichero, no la clave
    tests/Unit/FirmaDeLicenciaTest.php        11 pruebas, verdes; fijan el vector de referencia

**Lo medido, que es lo que separa esto de un plan:** la carga y la firma que produce este
PHP son **byte a byte idénticas** a las del emisor de TypeScript del otro repositorio, y
esa firma **es la que está pegada dentro de su verificador de Rust** — o sea, la verifica
una prueba verde suya. Y la licencia emitida por el comando se metió por su núcleo real y
salió `valida`, con su pie impreso. `cargo test` **no** se corrió: la aceptación por Rust es
**transitiva**, y así está escrito.

### JOSETH CONTESTÓ LAS DOS (6 sep 2026), y queda una

1. **`colegioId` = un número que asigna Joseth**, del 1 al 17. Lo que ganó la opción:
   **la licencia tiene que poder emitirse para un colegio que no está en ninguna base de
   `8myvc`** —el caso `independiente`—, y **`colegioId` no existe en esta API**: no hay tabla
   de colegios en las 90, `grep -rn 'colegio_id' app/` sale vacío, y cada colegio es una base.
   Eso **contesta una pregunta que el otro repositorio ya tenía escrita** en
   `nucleo/licencia.ts`. **El precio aceptado, que no es un descuido:** la cuenta se lleva
   **fuera del sistema** y **dos licencias con el mismo número no las caza nadie**.
2. **La clave privada vive SÓLO en la máquina desde la que se emite.** Nada en los diecisiete
   `.env`, nada en ningún respaldo de cPanel. Sale de que el binario incrusta **una sola**
   clave pública —así que **sólo puede existir una privada**— y de que repartirla convertiría
   cualquiera de los diecisiete hostings compartidos en el sitio desde donde se fabrican
   licencias para todos los demás.

**Y esa decisión tumbó la pregunta de `sodium` en producción**: el único PHP que necesita
sodium es el de la máquina que emite. **Lo que NO se cayó con ella** es el hallazgo de al
lado, y por eso vive en la §0 del documento con su propio recuadro: `lcobucci/jwt` era lo
único que exigía `ext-sodium`, ya no está, y **`vendor/composer/platform_check.php` no
comprueba ni una extensión**. Deja de ser un problema de licencias y pasa a ser una trampa
para **la próxima dependencia que exija una extensión**: entrará en verde y fallará en los
diecisiete.

**Y LO ÚLTIMO QUE FALTABA LO CONTESTÓ JOSETH EL MISMO DÍA: la clave NO se fabrica
todavía.** Preguntado por el respaldo, contestó que **la licencia no le importa aún** —esa
parte de la aplicación **se enseñará bloqueada** y por ahora el sistema sólo se usará
**entrando contra el servidor web**—. O sea que **el carril queda cerrado por decisión, no
por falta de nada**: el emisor está escrito y probado, y lo único que falta es una clave que
alguien decidió no fabricar todavía.

**Se escribe así de explícito porque las dos lecturas llevan a sitios distintos**: quien lo
lea como un cabo suelto va a fabricar la clave, y fabricarla es irreversible en un sentido
concreto —su pública se incrusta en binarios que se instalan en los colegios, así que
cambiarla después obliga a reinstalarlos—.

**La pregunta del respaldo no se cae, cambia de fecha:** se contesta el día que se fabrique.
**Perderla es peor que filtrarla** — filtrarla se arregla emitiendo clave nueva y
actualizando el binario; perderla obliga a lo mismo **sin poder emitirle una licencia a nadie
mientras tanto**. Y la instrucción va con su condición de caducidad, que es lo que pide esta
casa: **no se fabrica la clave mientras no haya que empaquetar para ningún colegio.**

Y la petición del otro lado —los 32 bytes de nuestra clave pública— **queda EN ESPERA por la
misma decisión**: bloquea que empaqueten para un colegio, y no se va a empaquetar todavía.
Sale de `licencia:emitir --publica` el día que exista la clave. Mientras tanto, su binario
lleva una **clave de desarrollo cuya semilla está publicada** —cualquiera que lea aquel
repositorio puede fabricar una licencia que acepte—, y **eso es correcto exactamente hasta el
día que se empaquete**: es la misma condición de caducidad vista desde su lado, y por eso las
dos cosas se aplazan juntas.
