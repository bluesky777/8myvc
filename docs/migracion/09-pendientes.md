# Lo que queda, y lo que ya se sabe de cada cosa

Las fases 0–6 están cerradas y el plan de rendimiento también, salvo lo que hay
aquí. **Ninguna de estas es trabajo que falte por hacer sin más: cada una está
parada por algo concreto**, y lo que vale de este documento es ese algo — para
no volver a descubrirlo dentro de tres meses.

Orden: primero lo que decidió Joseth que se hará, después lo que espera una
decisión suya. Lo que se cierra **se deja aquí**, no se borra: el porqué de cada
desvío respecto al plan es justo lo que no se puede reconstruir después.

---

## Cierre de la noche del 22 al 23 de agosto de 2026 — veinte lotes en paralelo

**Lee esta sección entera antes de tocar nada.** Es el estado real al cerrar, no
un resumen de lo hecho: lo hecho está en los veinte cuadernos de
[`noche-2026-08-23/`](noche-2026-08-23/) y las secciones que salieron de ellos,
en el [05](05-codigo-muerto-y-roto.md).

> Va sin número a propósito. Las §0.0 y §0.1 se citan **desde el código** —la
> cabecera de `tools/worktree-de-sesion.sh` manda a las dos—, así que correr la
> numeración para meter ésta arriba rompería las citas. El orden sigue siendo el
> de siempre: lo más nuevo, primero.

### El estado, en dos commits sellados

Los dos números se midieron con **la suite entera** y con el commit sellado al
disparar, no al escribirlo:

| | `c2c2a04` (partida) | `9492a2b` (cierre) | Δ |
|---|---|---|---|
| Tests | **1.006** | **1.276** | **+270** |
| Aserciones | **6.546** | **8.594** | **+2.048** |
| Rutas con la respuesta comprobada | **462/539 (85%)** | **535/539 (99%)** | **+73** |
| Controladores con alguna comprobada | **97/97** | **97/97** | — |
| Controladores **a medias** | **41** | **4** | **−37** |
| Larastan nivel 7 | `[OK]` | `[OK]` | |
| Rojos · saltados | 0 · 0 | 0 · 0 | |

> **Y la corrida de cierre se repitió entera en `4c5fdbe`**, ya con todo fundido y
> los documentos escritos: **1.276 pasados, 8.594 aserciones, `EXIT=0`**, idéntico.
> No es redundancia: entre los dos sellos la rama se movió, y *«solo cambiaron
> comentarios»* es un argumento, mientras que una suite verde es una medición.

**Los 97/97 no son el mérito de la noche; los 41 → 4 sí.** Con la suite entera ya
había un test por controlador antes de empezar: lo que faltaba era **dentro** de
cada uno. Y las cifras de partida son 462 y 97/97 **porque son de la suite
entera**; el 461 y el 96/97 que circulan son de correr sólo Contrato, y mezclar
las dos poblaciones infla la mejora sin que haya mejorado nada.

### Lo que queda sin comprobar tiene nombre: tres métodos y un verbo

No es un resto difuso del 1%. Son cuatro filas de `route:list`, y una de las
cuatro **no es código sin mirar**:

| ruta | método | |
|---|---|---|
| `POST api/escalas/store` | `EscalasDeValoracionController@postStore` | crear |
| `POST api/definiciones_comportamiento/store-escrita` | `DefinicionesComportamientoController@postStoreEscrita` | crear |
| `POST api/frases_asignatura/store/{frase_id?}` | `FrasesAsignaturaController@postStore` | crear |
| `PATCH api/tiposdocumento/{tiposdocumento}` | `TipoDocumentoController@update` | **ya comprobado por `PUT`** |

Las tres primeras son **crear una fila de catálogo**, y no es casualidad: los
lotes de catálogos midieron **editar y borrar**, que es donde estaban los
síntomas. La cuarta es `routes/api/catalogos.php:41`, un `patch` registrado
aparte del `put` de la línea de arriba contra **el mismo `update`**: lo que no
está comprobado es el verbo, no el método.

> **Que la cobertura cuente rutas y no métodos es correcto —un verbo puede llevar
> otro guard— pero al leer el resto hay que separar las dos cosas**, o se sale a
> escribir un test para código que ya tiene uno.

### Lo que hace falta de Joseth, agrupado — y una que no espera a nadie

Catorce filas sueltas se posponen; seis decisiones se contestan de una sentada.
Esto es el cruce de los ocho cuadernos que preguntaban algo, hecho por una sesión
que **no había escrito ninguno** de ellos.

**Antes de las seis, la que no es una decisión de negocio — CERRADA el 23 ago
2026:** `putCambiarClaves` (`alumnos/cambiar-claves`) recibía una clave y un
`grupo_id` y hacía un `UPDATE` sobre **las contraseñas de todos los alumnos del
grupo**, con `auth.personal` por delante y **ninguna comprobación dentro**.
**Joseth: a superusuario.** Y no le quita el botón a nadie: el único cliente que
la llama la pinta en un menú `hasRoleOrPerm(['admin','secretario'])`, y hoy hay
**10 `is_superuser`, los mismos 10 con rol `Admin` y cero `Secretario`**.

> Y llegó a estar apuntada como *defendida*. La primera medición buscó
> `Autoriza::` **desde el nombre del método hasta el final del fichero**, sin
> acotar dónde termina el método, y se trajo el `Autoriza` de otro método de más
> abajo. **Cuando una comprobación se salta, no se salta al azar: se salta hacia
> la respuesta que da menos trabajo.** Fue la quinta de la noche que se cayó al
> mirarla de cerca, y las cinco fallaron en esa dirección; ninguna falló hacia la
> duda.

| | La decisión | Instancias que cuelgan de ella |
|---|---|---|
| ~~**A**~~ | ~~**¿Se puede escribir en un año pasado?**~~ — **CONTESTADA el 24 ago: sí, y no es un agujero, es el producto.** Todos pueden moverse por un año pasado; **lo que decide si se escribe es el interruptor del periodo, no el año**. No se cierra ninguno de los cuatro ([16](16-escribir-en-un-anio-pasado.md)) | frases y contratos de años pasados (§84) · ordinales del manual de convivencia de un año cerrado (lote B) · **escalas de valoración, que hoy lo permiten y llevan escrito que es a propósito** |
| ~~**B**~~ | ~~**Borrar un catálogo al que otra fila apunta**~~ — **CONTESTADA Y APLICADA el 23 ago: se impide, con la cuenta de lo que depende.** Cerrados `grados` y `dis_ordinales`, que son los dos con daño medido; **`frases`, `tipos_documentos` y `ciudades` NO**, y `niveles_educativos`, `areas` y `materias` esperan número (ver abajo) | (§70, lote B) |
| **C** | **La hora mal escrita en filas ya guardadas: ¿migración o nota?** | `change_asked.deleted_at` (§121) · `created_at`/`updated_at` de las ausencias del lector (§123) |
| **D** | **Los interruptores `para_*`** | `para_acudientes` y `para_profesores` (§104) son los gemelos de `para_alumnos` (§74) |
| **E** | **¿Quién del personal puede qué?** | las cinco rutas que un profesor alcanza de verdad · **quién reparte acudientes (§109)** · `bitacoras/*`, leerla y borrarla · `historiales/de-usuario` (§110) · `matriculas/*` 3 de 16 con guard, `alumnos/*` 8 de 17 · quién es el «Secretario» y para qué está `Manager` |
| **F** | **`strict => false` en las dos conexiones de `config/database.php`** — **aplazada el 24 ago: no se toca por ahora.** Encenderlo convierte warnings en errores en 990 consultas crudas que nadie ha probado en estricto, y va a dieciséis colegios. Queda documentada como **causa común** de varios síntomas, y se ataca cuando haya dónde probarlo entero | §81 y §122 son sus dos instancias medidas: el mismo `NULL` sale `Warning 1048` y `''` en un `UPDATE`, y `ERROR 1048` rechazado en un `INSERT` |

Tres avisos sobre esa tabla, que son la mitad del valor de haberla cruzado:

- **La A hay que contestarla junta, y con un aviso sobre la tercera: no está
  decidida, está deducida.** Lo que se decidió en la §27.4 es que **un periodo
  abierto de un año pasado deje escribir notas** —«manda solo el interruptor»— y
  eso cuelga de una herramienta que el colegio ya tiene en la mano. Lo de las
  escalas es esa decisión **aplicada por analogía la
  noche del 22 al 23**: el «a propósito» está
  en un comentario de `EscalasDeValoracionController::deleteDestroy` escrito el 22
  de agosto (`9fa55b5`), citando la §27.4. El argumento es bueno —una escala de
  2024 sigue pintando los boletines de 2024, así que corregirla desde 2026 tiene
  que poder hacerse— y vale igual para frases y contratos; pero **frases,
  contratos y ordinales no tienen interruptor que abrir ni que cerrar**, así que
  la respuesta no se copia sola. Aun así hay que verlas juntas, porque **la
  diferencia entre las tres tablas no es una decisión: es dónde se estaba
  mirando.** Presentadas por separado se pueden contestar distinto sin querer, que
  es exactamente cómo nació lo que las tres describen.
- **En la E, `historiales/de-usuario` no va con `bitacoras/*`.** Comprobado: lee
  `bitacoras`, `historiales`, `notas` **y `notas_finales`**. Si se funden y la
  respuesta es «sí, el personal puede leer el rastro», esa respuesta **autoriza de
  paso las calificaciones**, que es una pregunta que no ha hecho nadie. Y dentro
  de la E, la de los acudientes (§109) es **la única cuya consecuencia es darle a
  un adulto acceso a los datos de un menor**.
- **Dos filas se leen como contradicción y no lo son**: un lote dice que un
  profesor alcanza `profesores/destroy/{id}` por `auth.personal` y otro que está
  anclada a superusuario. Las dos son ciertas — **el guard deja pasar y el método
  aborta dentro**. De las seis rutas de esa familia, cinco las puede hacer un
  profesor de verdad y una contesta 403.

### Lo que abrió cerrar la B, y hace falta un número tuyo

Los tres catálogos que quedan tienen **la misma forma que `grados`** —el hijo se
queda huérfano y alguna consulta lo esconde— pero el tamaño manda, porque cerrar
significa que esa fila **ya no se puede retirar mientras tenga hijos vivos**:

| Catálogo | Hijo | Se bloquearían hoy | Qué pasa si no se cierra |
|---|---|---|---|
| `niveles_educativos` | `grados` | **4 de 4** — o sea, ninguno se podría borrar nunca | se lleva los grados por delante, y de ahí los grupos |
| `areas` | `materias` | **20 de 22** | las materias quedan sin área |
| `materias` | `asignaturas` | **27 de 35** | las asignaturas quedan sin materia |

> **Contestado el 24 ago: se cierran `areas` y `materias`; `niveles_educativos`
> no.** El motivo del que se queda fuera es el número: **4 de 4** significa que esa
> ruta no volvería a servir para nada, y **una ruta enrutada que siempre contesta
> 422 es peor que la que no existe** — no dice qué pretendía hacer la pantalla, que
> es la misma regla por la que aquí no se borra el código roto con ruta. Fijado al
> revés por `BorrarUnCatalogoEnUsoTest`: si alguien cierra niveles «por
> coherencia», cae y le enseña la cuenta.

**Y tres que NO se cierran, con su porqué medido**, para que no se amplíen por
analogía: `frases` —la definición ya guarda el texto copiado, 235 de 426 sin poder
retirar a cambio de nada—, `tipos_documentos` y `ciudades` —hueco visible medido
en la §70.2, y quedarían prácticamente inborrables—.

Y **tres filas que parecen nuevas y no lo son**: que `GET api/contratos` mande el
expediente completo ya está en el §5 de aquí abajo —lo nuevo es que ahora un test
fija sus quince columnas, así que el día que se recorte se ve qué se movió—;
borrar un grado también; y las dos del lote M las contestaron los lotes D y E con
su 404. **Si entran como nuevas, lo pendiente sube sin que haya subido nada, y una
lista que crece sin motivo deja de leerse.**

### Las ausencias sin fecha: el arreglo es del front, y ya está escrito allí

La coordinadora que reportó que **las ausencias y tardanzas no mostraban la
fecha** tenía razón, y **no es el formato de la hora** (§121): ese escribe un
datetime válido con la hora equivocada, nunca una fecha ausente.

Es que **6.681 de 52.146 ausencias tienen `fecha_hora` en NULL** —13%, y 19% en
tardanzas—, y el motivo está en `myvc_front`: la pantalla `/asistencias` ya se
arregló el 19 de agosto, pero **la planilla de notas sigue creando faltas sin
día** desde cuatro sitios, con la línea de la fecha **comentada justo debajo**.

Queda apuntado en el plan del front —`myvc_front/MIGRATION.md` §4b punto **3b**,
commit `965c9227`— para que lo arregle quien toque esas pantallas en la migración.
**Aquí no hay nada que hacer**: la API guarda fielmente el `null` que le mandan, y
poner un `now()` por defecto sería peor —`fecha_hora` es *el día que el alumno
faltó*, no el día que el profesor lo tecleó—.

> Y el aviso que va con el `UPDATE fecha_hora = created_at` que propone ese mismo
> plan: **rellenar un hueco con un dato plausible no es recuperarlo, es taparlo —
> y de forma que ya no se nota que faltaba.**

### Dónde está lo demás

- **Las secciones nuevas del 05**: §81–§167, con su índice por lote al final de
  [`05-codigo-muerto-y-roto.md`](05-codigo-muerto-y-roto.md). Los huecos de la
  numeración son números que nadie llegó a usar, no secciones perdidas.
- **Lo que se nota en un colegio** —qué de todo esto lo ve un usuario y qué se
  queda en el código— en
  [`noche-2026-08-23/que-se-nota-en-un-colegio.md`](noche-2026-08-23/que-se-nota-en-un-colegio.md).
- **Lo que hay que desplegar**, colegio a colegio, en
  [`../DESPLIEGUE.md`](../DESPLIEGUE.md). Recordar lo de siempre: **un arreglo
  fusionado no está desplegado**, y `vendor/` es compartido.
- **Cómo se trabajó de noche y qué falló**, en
  [`15-la-noche-en-paralelo.md`](15-la-noche-en-paralelo.md) y en el
  [`README`](noche-2026-08-23/README.md) de la carpeta de la noche.

---

## 0.0 Para quien retome esto — cierre del 21 de agosto de 2026

**Lee esta sección entera antes de tocar nada.** Es el estado real al cerrar, no
un resumen de lo hecho.

### Dónde está todo

`main`, con todo fusionado y subido a `origin/main`. **No queda ninguna rama con
trabajo fuera**: la última, `fix/piar-autorizacion-y-saneado-html`, entró el 21
ago —ver el commit de la fusión, que explica cómo se resolvieron sus tres
conflictos, que es lo que el diff no dice—. Las otras tres ramas locales están
fusionadas y se pueden borrar sin perder nada.

Al cerrar: **859 tests**, `pint` y `stan` en verde, `stan` en **nivel 7**.
Cobertura de rutas: **370 de 539 (69%)**, medida con
`tools/cobertura-de-rutas.py`.

> **Al cerrar el 22 ago**, con lo de la §0.1, las fichas, los catálogos, el
> cálculo de definitivas, `editnota`, los historiales y los interruptores de las
> actividades ya fusionados en `main`: **977 tests**, `pint` y `stan` (nivel 7) en
> verde y cobertura **434 de 539 — el 80%**, medido contra una base propia. El número de cobertura **no se movió con
> el último arreglo, y eso es el hallazgo**: la ruta que no guardaba nunca ya
> constaba comprobada, porque lo que la cubría era un caso de rechazo
> ([05 §69.7](05-codigo-muerto-y-roto.md)). La rama
> `fix/identificadores-del-cuerpo` entró entera con `--no-ff` —29 commits— y se
> puede borrar. **Sigue sin desplegarse nada**: son arreglos de `app/`, **sin
> migraciones nuevas** y sin tocar los cuatro clientes; la tanda está descrita
> por lo que se nota en un colegio en [DESPLIEGUE.md](../DESPLIEGUE.md), y lo del
> 21 ago quedó archivado en la referencia.
>
> **Y al cerrar la asistencia, ese mismo 22 ago**: **986 tests**, cobertura
> **441 de 539 — el 81%**, `pint` y `stan` (nivel 7) en verde. La rama es
> `fix/asistencia-quien-corrige-una-falta`, y trae **un arreglo desplegable** —
> `deleted_by` en `ausencias/destroy`—, sin migraciones y sin tocar los clientes.
> Lo que trae no es el número: el hueco de cobertura llegó **plano** —105 rutas en
> 48 controladores, mediana 2— y agrupar por la pregunta en vez de por la carpeta
> metió **siete rutas de cuatro controladores en una sola lectura**. Salió el `if`
> con el cuerpo vacío ([05 §75](05-codigo-muerto-y-roto.md)), que llevaba escrito
> desde antes de la migración y **sobrevivió al barrido de autorización, a la
> revisión IDOR y a la fase 11 del front**: las tres lo vieron y ninguna lo tocó,
> porque las tres buscaban comprobaciones que faltan y no **comprobaciones que
> sobran**.
>
> **Y detrás, la otra mitad de la papelera** ([05 §76](05-codigo-muerto-y-roto.md)):
> **988 tests**, cobertura **443 de 539 (82%)**. El 21 ago se cerraron las rutas
> destructivas de la papelera y **la mitad que devuelve se quedó abierta en los
> mismos cinco sitios** que nombra la cabecera de `Autoriza`. Cualquiera de los 51
> profesores sacaba de ella un grupo, un profesor o un año. **No se rompió ni un
> test al cerrarlo**, y ése es el dato: ninguno fijaba quién podía restaurar — el
> de `YearsTest` pasaba porque el `Usuario` del seed resulta ser `is_superuser`.
> Va a la tanda de despliegue.
>
> **Y detrás, el botón peligroso** ([05 §77](05-codigo-muerto-y-roto.md)): **992
> tests**, cobertura **445 de 539 (82%)**. `detalles/eliminar-notas-periodo` borra notas con un `DELETE` físico y
> no miraba nada. La §27 no podía verla — **su inventario se hizo de los sitios que
> ya llamaban al candado**, y un sitio que nunca preguntó no sale en una lista
> hecha así. Queda `tools/escrituras-en-las-notas.py`, que la rehace por la
> operación, con sus dos formas de mentir anotadas dentro.
>
> **Y la otra mitad de la §70** ([05 §78](05-codigo-muerto-y-roto.md)): **995
> tests**, cobertura **455 de 539 (84%)**. Crear un catálogo son veinte rutas que nadie había mirado, y el mismo
> cuerpo vacío saca **cuatro respuestas distintas** de nueve rutas gemelas. Lo que
> las separa no es el código —los nueve son igual de crédulos— **es el esquema**:
> las ocho que no escriben tienen una columna `NOT NULL` y `contratos`, que no
> tiene ninguna, escribía una fila huérfana y contestaba 200. Cero huérfanos en
> producción, así que era una mina.
>
> **Y al llegar a los 1.000 tests**, con cobertura **461 de 539 (85%)**
> ([05 §79](05-codigo-muerto-y-roto.md)): qué
> **escriben** las rutas que sólo piden token, que es la mitad que el barrido no
> midió. Cuatro comportamientos y **sólo uno es un problema** — que un alumno pueda
> quedarse con el correo de recuperación de cualquier cuenta con id más alto que la
> suya, y con su enlace. Las otras tres salen limpias, y eso también es un
> resultado.
>
> **Y para cerrar, las dos que le faltaban al candado del periodo**
> ([05 §80](05-codigo-muerto-y-roto.md)): **1.006 tests**, cobertura **462 de 539 (85%)**. `periodos/copiar` y
> `subunidades/destroy`. La primera no salió en la herramienta de la §77 porque
> **escribe con Eloquent y el detector sólo miraba SQL** —tres cegueras del mismo
> detector en un día, todas ya en su cabecera—. La segunda llevaba un mes tapada
> por **una frase escrita en el docblock de un test verde** que decía que esa ruta
> sí pedía el periodo. La frase se deja escrita con su error, porque la lección es
> la frase y no la ruta.

### Lo primero que hay que hacer, y no es código

**Nada de lo de hoy está desplegado.** `app/` es copia real en cada colegio
(CLAUDE.md), así que fusionar no es desplegar y hay **diez y pico arreglos de
autorización** esperando. Y hay **tres migraciones nuevas** —el rol `Secretario`,
`password_reminders.username` y `frases_preescolar.deleted_at`—.

**El orden importa en una de ellas**: la de `password_reminders` va **antes** que
`app/`, en cada colegio. Al revés, `postRecuperarClave` insertaría en una columna
que no existe y la recuperación de contraseña se cae entera — que es la única vía
que le queda al **91% de las cuentas**, medido. Está en `docs/DESPLIEGUE.md`.

### Cómo se trabaja aquí sin destrozarle la medición a otro

El 21 de agosto trabajaron **tres sesiones en paralelo** sobre este mismo árbol y
se perdieron tres mediciones antes de que ninguna diera un número bueno. Lo que
quedó aprendido, y que ahorra la tarde:

1. **Una base de tests por sesión.** `DB_TEST_DATABASE=simonbolivar_testing_b`.
   Dos suites contra la misma dan *deadlocks* en `personal_access_tokens`, y lo
   que se ve **no es un error de infraestructura sino tests de contrato en rojo**.
   Ver [03-tests.md](03-tests.md).
2. **La base deriva sin avisar, y es la trampa que más veces mordió.** Compara
   las migraciones **antes** de buscar un fallo en el código:

   ```bash
   for db in simonbolivar_testing simonbolivar_testing_b simonbolivar_testing_c; do
     printf "%-28s " "$db"
     docker exec 8myvc-app-1 php artisan tinker --execute="echo DB::connection('mysql_testing')
       ->selectOne('SELECT COUNT(*) n FROM \`$db\`.migrations')->n;" 2>/dev/null | tail -1
   done
   ```

   Mordió **tres veces el 21 de agosto**, y las tres distinto: la primera la
   migración que faltaba no cambiaba ningún resultado, la segunda rompió cinco
   tests, y la tercera **le pasó a quien acababa de leer esta regla**.

   Lo que hay que reconocer no es la causa, es **la cara que pone**: no falla la
   infraestructura ni sale un error de conexión — salen **tests de contrato en
   rojo con mensajes perfectamente creíbles**, del tipo «ni al crear ni al guardar
   se anota quién fue». Te pones a leer el código y el código está bien. Diez
   segundos de comparar migraciones ahorran media hora de leer un controlador
   correcto.
3. **Un fichero de medición por sesión** (`COBERTURA_RUTAS`, `EXPLICAR_CONSULTAS`).
   Y **no lo borres a mano**: lo vacía la propia corrida. Borrarlo mientras otro
   mide le desengancha el inode y su medición sale plausible y falsa —salió *86 de
   539 cuando eran 346*—.
4. **Di en voz alta qué ficheros coges antes de empezar.** Dos sesiones
   escribieron el mismo test del mismo endpoint en la misma hora.
5. **`git add -A` y `git commit -a` no son seguros con el árbol compartido**, ni
   siquiera cerrando un merge. Pasó al cerrar el del PIAR: el commit de fusión se
   llevó dentro un documento entero de otra sesión que estaba sin *stagear*, y se
   supo porque su autor fue a commitearlo y git le dijo «no changes added to
   commit». No hubo daño —el contenido está entero— pero el commit dice traer una
   cosa y trae otra, y reescribir un commit ya publicado es peor que eso.
   **Durante un merge, `git commit` a secas ya hace lo correcto**: lo que hay que
   incluir es lo que git puso en el índice, y lo demás que haya en el árbol puede
   ser de otro. Fuera del merge, `git add <rutas>` una a una.

### Las cuatro lecciones de método que más se repitieron

Están desarrolladas en el 05, pero se resumen porque **cada una costó al menos un
error real**:

- **Comprobar al revés solo vale si se revierte lo que de verdad cambió el
  comportamiento**, y hay que **contar cuántos tests caen**. Si el arreglo tapa
  dos caminos y cae uno, el otro no estaba probado. Cazó tres tests que pasaban
  sin medir nada. Ver [05 §45](05-codigo-muerto-y-roto.md) y [§47.2](05-codigo-muerto-y-roto.md).
- **Un detector da una lista de sitios donde mirar, nunca una lista de fallos.**
  El mismo patrón se midió cuatro veces (47, 37, 20, 10) y **ninguna era la
  buena**: las anchas tenían falsos positivos, la estrecha se dejaba verdaderos.
  Lo resolvió leer cada sitio. Ver [05 §52](05-codigo-muerto-y-roto.md) y [§48.2](05-codigo-muerto-y-roto.md).
- **Al tapar un camino, la pregunta siguiente es cuál es el otro.** Un arreglo de
  la mañana abrió por el GET el mismo agujero que cerraba por el PUT. Ver
  [05 §47.2](05-codigo-muerto-y-roto.md).
- **El seed vacío deja verdes tests que no miden nada**, y ya van seis veces. Si
  la tabla que necesitas llega vacía, móntala en el test — y comprueba que el test
  falla sin el arreglo.

### Qué mirar después, por orden de lo que ha dado fruto

1. **Seguir la cobertura**: al 21 ago 2026 por la noche, **385 de 539 (71%)**. El
   método que ha dado los hallazgos no es subir el número, es **elegir un hueco
   con forma de dominio y leer el controlador**. La lista sale de
   `tools/cobertura-de-rutas.py`.

   El último hueco leído fueron las **veintidós rutas de `auth.token` a secas que
   no había mirado nadie**, y salió **sin ningún fallo de autorización** — el
   primer barrido del mes que sale así, y eso es un dato y no un rato perdido:
   dice que ese trozo está cubierto. Lo que sí trajo son **ocho rechazos que
   contestan con el código de otra cosa** ([05 §54](05-codigo-muerto-y-roto.md)),
   y uno de los ocho no era cosmético: `enfermeria/*` respondía **401**, que
   `Sesion.ts` del front lee como «sesión caducada» — le rotaba los tokens al que
   llamaba y en la carrera lo echaba al login. Se reporta como «me saca», que
   manda a mirar el código de sesión, donde no está el fallo.

   **Y la lección que ya va por la tercera vez en dos días**: los tres hallazgos
   de ayer y hoy —el 500 de `ChangesAskedAssignment`, la exención de
   `imagenes-de-usuario` y este 401— **ya tenían un test o una anotación que los
   fijaba**. Un test que fija lo que hay deja fijado también lo que estaba mal, y
   lo vuelve más difícil de ver, porque a partir de ahí hay un verde que dice que
   es así. No es un argumento contra fijar lo que hay: es que hace falta escribir
   al lado **por qué ese valor es el que es**, aunque solo sea «no se juzgó».
2. ~~**La pregunta que junta cinco fallos y que sigue sin hacerse**~~ —
   **contestada el 21 ago 2026, y salieron tres** ([05 §53](05-codigo-muerto-y-roto.md)).
   Fueron el **sexto** `asked_id` —la copia literal de la ruta que arregló la §50,
   en otro controlador, entregando el documento y la dirección de cualquiera—, el
   **álbum privado de cualquiera** a cualquiera con token, y **`foto_id`**, el
   tercer nombre de una imagen que `persona.propia` no conocía. La herramienta es
   `tools/identificadores-del-cuerpo.py` y los fija `IdentificadoresDelCuerpoTest`,
   nueve casos.

   Lo que hay que llevarse no es el número: **dos de los tres ya estaban
   medidos**, y por eso nadie volvió. Al sexto `asked_id` se le había preguntado
   qué código devuelve con un id que no existe —tiene su test fijando el 500— y
   nunca de quién es la fila cuando sí existe; al álbum privado lo tapaba una
   exención de `AutorizacionTest` escrita contra `user_id` cuando el método lee
   `usuario_id`, que es **la segunda que se le cuela a esa lista** después de la
   §35. **Medir una ruta no es haberla juzgado**, y una exención es la única línea
   del repo cuya recompensa es que nadie la vuelva a mirar.

   **Y el cuarto salió de arreglar la herramienta**, que es de donde menos se
   espera: la señal buscaba `Autoriza::` y media API comprueba en un helper
   privado —llamado `exigirQue…` en un controlador y `exigeQue…` en otro, el mismo
   verbo conjugado de dos maneras—. Buscar por la raíz `exig` bajó los candidatos
   alcanzables por una familia de catorce a uno, y ese uno era
   `publicaciones/comentar`: un alumno comentaba en una publicación marcada solo
   para administradores, que no sale en su muro. **El detector también se queda
   ciego ante un nombre nuevo**, que es la misma trampa que persigue.

   Y no está agotada: la herramienta da **231 rutas** y no sabe distinguir un id
   de persona de uno del colegio. Lo leído es lo alcanzable por una familia y por
   el personal que no debería; el resto espera a que se decida quién configura el
   colegio (§0).
3. **Las definitivas ([§4](#4-las-definitivas-notas-que-se-pierden-se-duplican-y-no-se-actualizan))**,
   cuando Joseth lo reabra. Dato nuevo del 21 ago: de los seis métodos que
   escriben en la rejilla sin mirar el interruptor del periodo, **cuatro caen ahí**
   ([05 §47.1](05-codigo-muerto-y-roto.md)). No es casualidad y es parte del mismo
   trabajo.
4. **Lo que espera decisión del colegio** está en el [§5](#5-lo-que-espera-una-decisión-del-colegio),
   y es lo que de verdad frena — no falta código, faltan respuestas.

### Los documentos, que son cinco y no uno

- **[05](05-codigo-muerto-y-roto.md)** — el código roto y los hallazgos, §1 a §52.
- **[09](09-pendientes.md)** — éste: lo que queda y por qué está parado.
- **[11](11-votaciones.md)** — votaciones. Ojo: `in_action` **no es un candado**
  ahí (manda al usuario a la pantalla, no cierra la urna) y `locked` es una
  **pausa reversible**, no un cierre. **La misma columna significa cosas distintas
  en dos módulos.**
- **[12](12-larastan-nivel-7.md)** — la escalera de larastan hasta el 7, sesión,
  reseteo de contraseña y generadores de nombres de usuario.
- **[13](13-actividades.md)** y **[14](14-certificados.md)** — actividades y
  certificados.

**Se leen antes de re-litigar nada.** Es la regla de CLAUDE.md y hoy ha evitado
por lo menos dos arreglos que habrían apagado una pantalla en dieciséis colegios.

---

## 0.1 La noche del 21 al 22 de agosto de 2026 — cinco sesiones en paralelo

Cinco sesiones sobre **el mismo árbol y la misma rama**, coordinadas por una sexta
que no escribió código. Salieron **17 commits**, la cobertura pasó de **385/539
(71%) a 417/539 (77%)** y los tests de 887 a **939**, medido al cerrar contra una
base propia. Lo que sigue **no son los hallazgos** —ésos están en el 05, §55 a
§67— sino lo que costó aprenderlos.

### Quién hizo qué, porque `git log` no lo dice

**Las cinco sesiones commitean como «Joseth David».** `git log --author` no las
distingue, así que **cualquier reparto de crédito o de culpa leído del log es una
conjetura**. La única atribución fiable es esta tabla:

| Commit | Sesión | Qué |
|---|---|---|
| `fbfc796` · `5d7a885` · `2901602` | 22 | inyección en ordinales · `detailed_materias` fijado sin juzgar · **inyección de segundo orden** en calendario |
| `179c662` · `c24706e` · `640306e` · `10c0410` · `673043d` | 67 | definitivas fase 3 primer escritor · ligar `DefinitivasPeriodos` · el gemelo en `NotaFinal` · corregir una afirmación falsa propia · `perfiles/*` |
| `3fb58e0` · `f06e060` · `88d2671` · `a82cec3` · `f7c32ba` | c8 | inyección de la importación · §56/57/60 · método de la §57 · `asistencias-app` · `users` |
| `6411bcb` · `8ce3d4f` · `2e34d8f` | fe | los cuatro `destroy` de votaciones · observador del grupo · requisitos de matrícula |
| `c3b6d26` | f3 (coordinación) | cierre del 05: §61 y la ampliación de la §59 |

Dos commits traen **más de lo que dicen**: `6411bcb` se llevó dentro las §55 y §59
de otra sesión, y `c3b6d26` las §62 a §67. **No se reescribió ninguno**, y las
tres sesiones llegaron por separado a la misma razón: rehacer la historia de otra
sesión para arreglar una atribución destruye trabajo de verdad, y el problema de
provenencia se arregla con una nota. Es lo que ya decidió este repo con el merge
del PIAR. **El commit huérfano `f48f0a7` no es antepasado de `HEAD`**; su
contenido está entero en la rama, comprobado línea a línea.

### Lo que hay que montar antes de la próxima noche

**El documento compartido es el punto de fallo, y falló cuatro veces.** El 05 lo
escribieron cuatro sesiones a la vez y cuatro commits se llevaron trabajo ajeno
dentro. Se probaron tres reglas y **ninguna lo cerró**:

1. «`git add <ruta>` una a una» — **no ve el caso**: la ruta es una sola y el
   fichero es de todas.
2. «Nadie añade el 05; lo commitea la sesión que coordina» — estrecha la ventana;
   entre comprobar el diff y hacer `git add` cabe otra sesión, y cupo.
3. «Recorta tus hunks» — **falla por geometría**: cuando varias anexan al final,
   git funde todas las adiciones en **un solo hunk**.

**La conclusión, después de cuatro fallos en una noche: un documento por sesión y
una fusión en frío al final.** Cualquier secuencia de dos pasos tiene un antes y
un después, y con cinco sesiones sobre un árbol **«lo arreglo en un momento» ya es
una ventana**.

Si aun así hace falta commitear un documento compartido, el índice se arma a mano
y **se verifica con un número, no con una presencia**:

```bash
git show HEAD:<fichero> > /tmp/base && cat /tmp/mi-bloque >> /tmp/base
blob=$(git hash-object -w /tmp/base)
git update-index --cacheinfo 100644,$blob,<fichero>
git diff --cached --stat     # tiene que dar TU número de líneas
```

**Y una regla que no es de git**: un test a medias sin commitear **pone la suite
en rojo para todas**. Un fichero de `app/` a medias solo rompe lo suyo; un test
roto es un rojo global y quien lo ve no puede saber de quién es.

### El método que funcionó, y que no estaba escrito

- **La asimetría entre hermanas.** Una línea que no hace lo que hacen las de al
  lado: el `?` que falta entre dos consultas que sí ligan, el guard que falta
  entre dos rutas vecinas que sí lo llevan. Encontró **tres inyecciones y un guard
  ausente**. Concatenación a secas daba 40 sitios, casi todos ruido; la asimetría
  dio dos, y los dos eran fallos reales.
- **Con una condición, o da falsos positivos con buena pinta**: comprobar que el
  valor concatenado **llega de verdad al SQL que se ejecuta**.
- **«Viene de la base» no es motivo para descartar un sitio.** Solo vale si además
  se sabe **quién escribe esa columna**: si alguna ruta la llena desde
  `Request::input`, el sitio sigue vivo y la entrada está en otro fichero. Así se
  encontró la inyección de segundo orden. Los descartes por constante, por marcas
  de parámetro (`?,?,?`), por bloque comentado y por «no llega al SQL» **no
  dependen del origen y siguen siendo buenos**.
- **Revertir de dos maneras.** No basta revertir al código original: hay que
  revertir **también a la solución equivocada que parecía buena**. Es lo único que
  demuestra que los tests distinguen el arreglo del atajo — y de esa segunda
  reversión salió un verde hueco que leer el test no destapaba.
- **Una lista de sitios no vale sin su clasificación, y la clasificación no sale
  del nombre.** El mismo `Request::input('is_active', 1)` está en seis sitios y
  **solo dos son el fallo**: los otros cuatro son altas, donde ese valor por
  defecto es correcto. Clasificarlos por el nombre del método daba cuatro fallos
  —porque `putUpdate` **también da de alta**— y «arreglarlos» habría roto dos. El
  discriminador era `new User` contra `User::find()`. **Una lista sin clasificar
  es más peligrosa que no tenerla**, porque invita a arreglar en bloque.
- **La tercera pata: el llamante dice si el fallo está vivo o solo latente.** El
  controlador dice qué acepta y el `return` qué devuelve; **si nadie manda ese
  campo, el fallo existe y no muerde**. Dos de esta noche son así —
  `perfiles/destroy` borrando grupos y la condición invertida de la contraseña— y
  las dos **se encienden solas** el día que un cliente añada una línea. Se
  documentan como minas, no como fallos activos, y el aviso va **en el código**,
  no solo en el documento.
- **La asimetría entre hermanas vale también entre dos métodos del mismo
  controlador**, y el que se desvía no es siempre el nuevo: `postStore` leía tres
  campos bien y `putUpdate` los leía mal desde siempre ([05 §69](05-codigo-muerto-y-roto.md)).
- **Un grep de clientes vale lo que valen los ficheros que mira.** «No lo manda
  nadie» es la afirmación más fácil de hacer con una muestra incompleta, porque no
  hay ningún resultado que la contradiga a la vista: la casilla de contraseña que
  se dio por ausente estaba en el formulario, y se había grepeado en las rejillas.
- **Un detector que lee el fichero entero encuentra también lo que se escribió
  sobre él**, y el resultado tiene cara de hallazgo, no de fallo del detector: el
  de la bandera contó un docblock recién escrito como un sitio nuevo
  ([05 §72.5](05-codigo-muerto-y-roto.md)). Va con su corolario, que costó tres
  entradas falsas en una lista que ya se había usado para decidir: **una lista
  leída del código no es automáticamente cierta — hereda lo que el lector no sabe
  distinguir.**
- **«Si llegara a ejecutarse» es una hipótesis, y suele estar a una llamada de
  comprobarse.** Lo del cálculo de definitivas llevaba tres días escrito así
  ([05 §71](05-codigo-muerto-y-roto.md)); sí se ejecutaba, y borraba justo las
  notas que nadie puede rehacer. **Un roto documentado se lee como inofensivo**, y
  ésa es la trampa: lo que hay que preguntarle a un método roto no es qué
  responde, es **si escribe antes de morir**.
- **Escribir la prueba de un fallo encuentra otro.** El caso de la §68 no llegaba a
  la línea que quería medir, y ese 422 era una pantalla entera que no guardaba.
  **Un test que no alcanza lo que quiere medir es un dato, no un estorbo.**
- **Cuando una serie se cierra, anotar sobre qué población se cerró.** La §54
  cerró ocho rechazos barriendo las rutas de `auth.token`; los mismos fallos
  seguían vivos en las de `auth.personal` y nadie los buscaba, porque la serie
  constaba cerrada.
- **Cuando el hueco es plano, la pregunta agrupa mejor que la carpeta.** Con 143
  rutas repartidas en 57 controladores (mediana 2), agrupar por dominio multiplica
  lecturas. «Qué se lleva por delante un `destroy` y quién puede llamarlo» son
  cuatro rutas de cuatro controladores y **una sola lectura**.

### La forma de fallo más cara: el instrumento que miente con la cara del problema

Ocurrió **siete veces en una noche**, y es la que manda a investigar el sitio
equivocado con una pista convincente:

| El instrumento | La cara que puso |
|---|---|
| migraciones desincronizadas entre bases | tests de contrato en rojo con mensajes creíbles |
| `\| tail` tragándose el código de salida | un script que sigue como si nada — un commit borró 128 ficheros ajenos |
| `git status \| grep '^ M'` tras `git apply --cached` | «te llevaste trabajo ajeno» cuando no; **fallaba siempre** |
| ejecutar contra el backend real **con un cuerpo que la pantalla no manda** | midió de verdad, y midió otra cosa |
| probar una orden de git **contra una muestra sin el caso que la rompe** | salió bien; clasificaba mal cuatro ramas |
| «que el fichero siga mostrando líneas sin stagear» | pasaba mientras arrastraba 400 líneas ajenas |
| **entrar mueve `users.periodo_id`** | rojos con cara de «falta seed» |
| `grep … \| head -5` para contar | **un recuento truncado presentado como total** — dijo cinco donde había seis |

**Tres de las ocho son de la misma familia: un tubo que limita en silencio** —
`| tail` tragándose el código de salida, `| head -N` truncando un recuento— **y
contesta como si hubiera contestado entero.** Si el resultado de un comando va a
convertirse en un número o en un veredicto, no lleva tubo que corte.

**Las dos del medio son las peores porque hacen lo que este repo predica** —no
leas, ejecuta— y aun así miden otra cosa: una por **el cuerpo que mandas**, otra
por **la muestra contra la que pruebas**. Ejecutar con el cuerpo equivocado es una
forma nueva de mirar el 200.

Y la regla que las resume, que salió de nuestro propio proceso y vale para el
código:

> **Una comprobación que no puede fallar cuando el fallo ocurre no es una
> comprobación.** La buena es la que da un número, no una presencia.

**El seed vacío va por nueve**, dos de ellas en esta noche. Y **cinco de los
hallazgos vivían debajo de algo verde** — uno de ellos, debajo de una revisión que
se había dado por cerrada mirando solo la rama que abrió.

### Sobre trabajar en paralelo, que también se aprendió

- **El reparto no lo lleva una sesión: lo lleva una lista escrita.** Dos
  coordinadoras chocaron tres veces la primera hora. Con una cola escrita de la
  que cada una **se sirve sola** al terminar, nadie espera respuesta y la noche no
  se para cuando quien coordina tarda.
- **Un acuerdo bilateral cerrado y en ejecución no se reabre desde fuera**, ni
  siquiera por quien coordina.
- **Quien coordina es quien más ensancha las afirmaciones**, porque es quien menos
  toca el código y más lejos las manda. Una afirmación falsa sobre las claves
  ajenas de `notas_finales` llegó a «dato estructural» y a argumento para reabrir
  la fase 2 en dos saltos. **Lo que llega de otra sesión y suena a hallazgo
  estructural se comprueba antes de subirlo de rango**, no después.
- **Una sesión no puede autorizar a otra.** Coordinar es administrar el permiso
  que el usuario ya dio; **no es concederlo**. Vale también entre repositorios.
- **Lo que se autorizó fue un diagnóstico, no un fichero.** Cuando la medición
  cambia lo que pasa, el permiso se vuelve a pedir — aunque sea la misma pantalla
  y el arreglo sea más pequeño.
- **Un número medido en un solo sitio describe ese sitio.** «14 filas dañables»
  del árbol del front y «cero» de la base de tests eran el mismo mecanismo: lo
  afirmable era el mecanismo, no la cantidad. Ninguna de las dos podía verlo sola.

---

## 0. La noche del 20 al 21 de agosto de 2026 — lo que hay que mirar primero

Se cerró la serie del barrido y se abrió otra, la de **cobertura**: en vez de
«¿tiene guard esta ruta?», la pregunta fue **«¿alguien ha mirado alguna vez qué
responde?»**. `tools/cobertura-de-rutas.py` daba 261 de 539 rutas comprobadas y
cinco controladores con **cero**. Ahí estaban casi todos los hallazgos de abajo.
La cobertura quedó en **312 de 539 (58%)** y ningún controlador a cero.

**Y la pregunta funcionó tan bien que merece quedarse escrita.** Seis fallos de
autorización o de credenciales en una noche, todos en los dos huecos más grandes
que señaló la medición, y ninguno lo había encontrado ni el barrido, ni larastan,
ni las tres herramientas de autorización. Lo que ninguna miraba era **el resultado
de la ruta**: el barrido mira quién llega, larastan mira si el código puede
funcionar, y `inventario-autorizacion.py` mira la firma. La cobertura mira si
alguien ha leído la respuesta alguna vez, y donde nadie la había leído estaba
todo.

### Lo que se arregló, y **hay que desplegar**

Los seis son de autorización o de credenciales, y ninguno está desplegado: `app/`
es **copia real en cada colegio** (`docs/DESPLIEGUE.md`). Fusionar no es desplegar.

| Qué pasaba | Dónde |
|---|---|
| Un **alumno** sacaba, con su propia clave y sin token, **todas las ausencias y tardanzas del colegio** de cualquier año | [05 §25](05-codigo-muerto-y-roto.md) |
| El lector de tardanzas aceptaba la contraseña **en claro** contra la columna, un respaldo que su controlador hermano ya había quitado por escrito | [05 §25.1](05-codigo-muerto-y-roto.md) |
| Una llamada sin `clave` dejaba a **los 1.280 alumnos con la contraseña vacía** — y entrar con la contraseña vacía responde 200 | [05 §26](05-codigo-muerto-y-roto.md) |
| Cualquiera de los **51 profesores** reiniciaba la contraseña de todo el colegio y creaba las cuentas de todo el colegio | [05 §26.1](05-codigo-muerto-y-roto.md), [§29.3](05-codigo-muerto-y-roto.md) |
| Un **docente se hacía con la cuenta del superusuario** en una petición, y recibía la clave nueva en la respuesta | [05 §29](05-codigo-muerto-y-roto.md) |
| Cualquier profesor **se fabricaba un superusuario** mandando `is_superuser: 1` al crear un profesor | [05 §30](05-codigo-muerto-y-roto.md) |
| **`GET api/alumnos` entregaba el directorio del colegio entero** —nombre, fecha de nacimiento, celular, dirección, religión y deuda de cada alumno— a cualquier alumno o acudiente | [05 §34](05-codigo-muerto-y-roto.md) |
| «Ahora NO es año actual» dejaba el año **encendido**, por tres caminos distintos | [05 §28](05-codigo-muerto-y-roto.md) |

Y uno que no es del código sino de la red que lo vigila: **una ruta golpeada con
dos tokens en el mismo test medía dos veces al primero**, porque Laravel guarda la
instancia del controlador dentro de la ruta. Cerrado en `CasoDeContrato`, y
anotado como bloqueante de Octane — [03-tests.md](03-tests.md).

**El último salió de comprobar los otros seis.** Se volvió a correr el barrido
para medir el efecto de los arreglos y apareció `GET api/alumnos`, que llevaba
abierta desde siempre: cae justo entre dos criterios —no nombra a nadie, así que
ningún inventario la señalaba, y no está muda, así que tampoco entró en las listas
de «sin juzgar»— y se quedó en el grupo que se repasa a mano. Se repasaron once de
las doce. Vale la pena quedarse con eso: **una lista que hay que mirar a mano cada
vez acaba teniendo un hueco, y el hueco no se ve**.

### Lo que necesitaba una respuesta suya — **contestado el 21 ago 2026**

Las cuatro preguntas de la noche anterior se le hicieron a Joseth una a una y las
cuatro tienen respuesta. Se escriben aquí **con lo que dijo, no con lo que se le
propuso**, porque en dos de ellas la respuesta no era ninguna de las opciones y
eso es justo lo que había que aprender.

1. **El interruptor del periodo** ([05 §27](05-codigo-muerto-y-roto.md)) →
   **derivar el periodo de la fila que se toca**. Hecho el mismo día; son 23 de
   26 y no 26, porque `recuperacion_final` se guarda por año y no tiene
   `periodo_id`. La opción barata —exigir
   que `num_periodo` y `periodo_id` concuerden— queda descartada por lo que ya
   decía la §27.1: no cierra la rejilla de definitivas ni `notas/update`, que son
   las que más pesan.
2. **Quién es el «Secretario»** ([05 §30.2](05-codigo-muerto-y-roto.md)) → **un
   rol nuevo, `Secretario`, que se le asigna a un usuario docente.** No es
   `Admin`: la razón de existir del rol es precisamente **una secretaria docente
   que no es superusuario**, y con `Admin` eso no se puede porque los diez `Admin`
   son los diez `is_superuser`. `Role::isSecretario` ya lo busca exactamente por
   ese nombre, así que los once sitios empiezan a funcionar en cuanto la fila
   existe y alguien la tiene.

   **Y el alcance no es el que se había propuesto.** Se le ofreció «alumnos,
   matrículas, docencia e informes» y corrigió el corte, que va por otro sitio:

   | Puede | No puede |
   |---|---|
   | Las **configuraciones del colegio**: materias y su orden, las asignaturas de **todos** los grupos, los titulares de grado | **Crear usuarios** |
   | Alumnos y su edición, matrículas | |
   | La **configuración del año**, y **bloquear periodos** | |
   | Ver e imprimir **todos** los informes, no solo los de sus grupos | |
   | De unidades, subunidades y notas, **solo las suyas como docente** | Las de los demás docentes |

   O sea que el Secretario **no** es «un docente con más cosas» ni «un
   superusuario con menos»: es administrador de la **estructura** del colegio y
   docente normal en **su propia aula**. Los dos ejes son independientes, y
   confundirlos es lo que haría el arreglo mal.
3. **El «Psicólogo»** ([05 §30.2](05-codigo-muerto-y-roto.md)) → el rol
   `Psicólogo` (que ya existe, id 11, cuatro personas) **abre `nee` y
   `nee_descripcion`, y nada más**. La decisión se tomó después de ir a mirar el
   PIAR, y lo que se encontró allí cambió la pregunta — está en la [§35](05-codigo-muerto-y-roto.md).
4. **El hash del lector de tardanzas** ([05 §25.4](05-codigo-muerto-y-roto.md)) →
   **quitarlo del `SELECT`**, en `tardanzas/login` y en `traer-datos`.
5. **Los años actuales de los dieciséis colegios**
   ([05 §28.3](05-codigo-muerto-y-roto.md)) → **un comando de diagnóstico**, en vez
   de una consulta suelta que hay que pegar dieciséis veces. Y con contexto nuevo
   que Joseth dio al contestar y que no estaba escrito en ninguna parte:

   > Más o menos en **octubre se crea el año siguiente copiando todo del anterior**
   > excepto el número del año. El año que elige el usuario rige la plataforma con
   > sus configuraciones, **excepto en los informes, donde siempre salen el rector
   > y el secretario del año actual** — para que se puedan firmar informes viejos
   > cuando el rector de aquel año ya no trabaja en el colegio.

   Las dos mitades importan. La primera pone **fecha** a esto: la copia de octubre
   es exactamente el momento en que un colegio con dos años actuales se lleva la
   ambigüedad al año nuevo, así que el comando quiere estar corrido antes. La
   segunda explica el `$actual=true` de `Year::datos()`, que hasta hoy parecía un
   parámetro suelto: **es una regla de negocio, y de las que un refactor bienintencionado
   borra** por parecer un descuido.

### Lo que se hizo el 21 de agosto

Primero las seis decisiones de Joseth, y después la lista de cobertura, que
volvió a ser lo que más encontró. Cobertura: **312 → 346 de 539 (64%)**, y sigue
sin haber ningún controlador a cero.

**Lo que se decidió y se aplicó:**

| Qué | Dónde |
|---|---|
| El hash bcrypt sale de las respuestas del lector de tardanzas | [05 §25.4](05-codigo-muerto-y-roto.md) |
| El PIAR entero pasa a ser del personal — una de sus rutas no tenía guard ninguno | [05 §35](05-codigo-muerto-y-roto.md) |
| El rol `Secretario` existe, y el `Psicólogo` gobierna por fin algo | [05 §30.3–30.5](05-codigo-muerto-y-roto.md) |
| La bandera del periodo que se comprueba es la del periodo al que se escribe, en 25 de 26 llamadas | [05 §27.1.1](05-codigo-muerto-y-roto.md) |
| La recuperación final es del año, así que pide los cuatro periodos abiertos | [05 §27.1.1](05-codigo-muerto-y-roto.md) |
| `php artisan anios:actuales`, para los dieciséis | [05 §28.3](05-codigo-muerto-y-roto.md) |

**Y lo que salió de seguir mirando, que fueron cuatro cosas más:**

| Qué | Dónde |
|---|---|
| Un profesor cambiaba el **correo de recuperación** del superusuario y se llevaba su cuenta — y recibía su hash | [05 §36.1](05-codigo-muerto-y-roto.md) |
| Cinco rutas cambiaban la **firma o la imagen** de cualquiera, incluido el **logo que sale en cada boletín** | [05 §36.2](05-codigo-muerto-y-roto.md) |
| Tres rutas frenaban la escritura y respondían **200 diciendo que sí** | [05 §37](05-codigo-muerto-y-roto.md) |
| Los pedidos de cambio devolvían **filas de `users` enteras, con el hash**, por una ruta de familia | [05 §38](05-codigo-muerto-y-roto.md) |
| **Aprobar un cambio escribía lo que dijera el cuerpo**: renombrar a cualquier alumno, reasignar o borrar cualquier asignatura | [05 §39](05-codigo-muerto-y-roto.md) |
| El interruptor del periodo pasa a cerrar **las notas y no la asistencia**, decidido | [05 §40](05-codigo-muerto-y-roto.md) |
| `PUT alumnos/show` entregaba **la ficha completa de cualquier alumno** —incluidas las necesidades educativas— a otro alumno | [05 §41.1](05-codigo-muerto-y-roto.md) |
| La **enfermera** no podía escribir los antecedentes médicos: la tercera de la familia del Secretario | [05 §41.2](05-codigo-muerto-y-roto.md) |
| El **autor de un comentario no podía borrar el suyo** — un 500 que solo apareció con PHP 8 | [05 §42](05-codigo-muerto-y-roto.md) |

**Nada de esto está desplegado**: `app/` es copia real en cada colegio.

### Lo que se hizo el 21 de agosto, por la tarde — y con tres sesiones a la vez

La cobertura volvió a medirse desde cero y confirmó el punto de partida: **345 de
539**, y un solo controlador sin ninguna respuesta comprobada, que es la ruta `/`
sin controlador. La lista de trabajo ya no son controladores mudos sino **194
rutas sueltas**, y el hueco más grande con forma de dominio era el módulo de
actividades y exámenes. Cerró el día en **348 de 539 (64%)**.

Y ahí hay algo que conviene decir antes de que el número engañe: **la cobertura ya
no es la medida que manda**. Mientras hubo controladores mudos, subirla encontraba
cosas a cada paso —seis fallos en una noche—. Ahora sube de tres en tres porque lo
que queda son rutas sueltas dentro de controladores ya mirados, y el hallazgo de
hoy no salió de subir el número sino de **elegir el hueco con forma de dominio** y
leer el controlador. La lista de 194 sigue diciendo dónde mirar; ya no dice cuánto
falta.

**Lo que salió: el examen de otro grupo, por su número** — [05 §43](05-codigo-muerto-y-roto.md).
`mis-actividades/mi-actividad` entregaba a un alumno el examen entero de un grupo
en el que no está matriculado, sin soltar y con las preguntas dentro, y **le abría
un intento** que después aparece en la pantalla de corregir de ese profesor.
Cerrado dentro del controlador —la ruta no puede llevar guard, porque esa pantalla
es también la vista previa del profesor— y comprobado al revés.

Salieron con él dos de la misma familia, que no son agujeros sino **reglas de
procedimiento que nadie comprobaba**, y Joseth las decidió el mismo día
([05 §43.1](05-codigo-muerto-y-roto.md)): **el examen se abre cuando el profesor
lo suelta** —`in_action` e `inicia_at`, solo para la familia, porque abrirla antes
que nadie es lo que es la vista previa del profesor— y **entregar es entregar**.
De la segunda se eligió a sabiendas la consecuencia: quien entregue sin querer se
queda fuera, y el día que eso moleste el sitio del arreglo es una ruta nueva del
profesor, no relajar la comprobación. Queda abierto `oportunidades`, en el §5.

Y esa forma —**la regla de procedimiento que no comprueba nadie porque no es un
guard**— resultó ser de las que se repiten: la sesión que llevaba votaciones
encontró la misma en su módulo, donde se vota con `locked = 1` e `in_action = 0`
porque nadie lee esas columnas, y donde `PUT votos/show` **destapa el recuento en
vivo** de una votación con `can_see_results = 0` si se le manda `permitir` en el
cuerpo. Está entero en [11-votaciones.md](11-votaciones.md), y su §1 es primo
hermano del `in_action` de aquí. Larastan del 5 al 6 y al 7 quedó medido y
decidido en [12-larastan-nivel-7.md](12-larastan-nivel-7.md): **el 6 no se sube**
—1.940 errores, ninguno señala código que pueda fallar, y el 68% cae en los
controladores, o sea el diff ilegible que CLAUDE.md prohíbe— y el valor está en
los seis identificadores del 7, que son la familia del `count()` sobre Builder de
la §13.1.

**Y lo segundo del día, ya con la lista del nivel 7 delante**
([12 §5](12-larastan-nivel-7.md)): `PUT images-users/cambiar-foto-un-usuario/{user_id}`
tenía **tres fallos en el mismo método** — [05 §44](05-codigo-muerto-y-roto.md).
Un `switch` sin rama para el cuarto tipo de usuario dejaba un `stdClass` vacío y
`->save()` daba **500** a cualquier intento de cambiarle la foto a un
administrativo; una cuenta viva con la ficha en la papelera —lo que queda al
retirar a un alumno— reventaba igual; y el `if` sin `else` devolvía **200 con el
cuerpo vacío** a los administrativos sin `is_superuser`. Ahora 422, 404 y 403.

Lo que enseña, y por eso está aquí y no solo en el 05: **ninguna herramienta de la
serie podía ver esto.** El barrido no llega —la ruta lleva `auth.personal`—, y los
inventarios de autorización tampoco, porque el guard está puesto y es el correcto.
No era un agujero de autorización: eran tres formas de que la respuesta no se
corresponda con lo que pasó. La serie de cobertura y la de larastan se cruzan
justo ahí, y es donde conviene seguir mirando.

### Y lo que enseñó trabajar tres sesiones sobre el mismo árbol

Esto no estaba previsto y vale más que el arreglo, porque va a volver a pasar.
Tres sesiones de Claude a la vez sobre el mismo repo, sin ramas separadas.
**Tres mediciones se perdieron antes de que ninguna diera un número bueno:**

1. **La base de tests es lo que limita el paralelismo, no la CPU.** Dos suites a
   la vez contra `simonbolivar_testing` dan *deadlocks* en el `insert` de
   `personal_access_tokens` de `CasoDeContrato::token()`. Y lo que se ve no es un
   error de infraestructura: son **tests de contrato en rojo** —36 en una corrida,
   5 en otra— y las mismas clases pasan solas. Resuelto con `DB_TEST_DATABASE`
   (ver [03-tests.md](03-tests.md)).
2. **`rm -f` sobre el fichero de medición borra la medición de otro sin avisar.**
   Estaba documentado como primer paso en `tools/cobertura-de-rutas.py`. Borrarlo
   mientras otra corrida lo tiene abierto le desengancha el inode; ni `FILE_APPEND`
   ni `LOCK_EX` protegen de eso. Dio **86 de 539 cuando eran 346**, con 135 casos
   de 588 registrados — y el número era **lo bastante plausible como para
   creérselo**. Ahora el fichero lo vacía la propia corrida y el paso ya no existe.
3. **Dos sesiones escribieron el mismo test del mismo endpoint en la misma hora**,
   y una sobrescribió a la otra en disco. Se recuperó porque se comparó antes de
   fusionar, no porque nada lo impidiera.

Lo que queda como regla: **el trabajo se reparte por ficheros y se dice en voz
alta antes de empezar**, y toda medición compartida —base de datos y fichero de
salida— quiere un nombre por sesión. Lo barato es acordarlo; lo caro es un número
plausible que nadie sabe que está mal.

Y una cuarta, que es la que más se repite: **el fixture que el seed no puede
expresar da un test que pasa sin comprobar lo que dice.** Las dos sesiones que
midieron esto cayeron en la misma trampa por separado —«el otro grupo» del seed es
el otro grupo *suyo*— y es la **tercera** vez que muerde después de la §16 y de
`tokenDelPersonalDe()`. Merece un ayudante en `CasoDeContrato`, no una nota más.

### Las dos cosas que enseñó el día, y que valen más que los arreglos

1. **La misma protección, dos caminos, y solo uno cubierto.** `App\User` tiene
   `password` en `$hidden` y eso funciona — pero solo si el usuario se lee **con
   el modelo**. La §36.1 lo saltaba concatenando en una cadena y la §38 leyendo
   con `DB::select`. En un proyecto con **990 consultas crudas**, `$hidden`
   protege el camino que este proyecto casi no usa. Vale la pena buscar con esa
   forma en la cabeza.
2. **Una respuesta que miente es peor que un error**, porque el que la lee deja de
   mirar. Ha aparecido ya **cinco** veces con cinco caras: los `abort()` de la
   §12, el `response()->json()` sin `return` de la §35, el `if` de permiso sin
   `else` de la §37, el mismo `if` sin `else` de la [§44](05-codigo-muerto-y-roto.md)
   —que devolvía 200 con el cuerpo vacío y la foto sin tocar—, y
   `Matricula::matricularUno()` —
   [12 §1](12-larastan-nivel-7.md)—, que en PHP 7 **no revivía ninguna matrícula
   y respondía 200 igual**. Esta tercera tiene ya herramienta:
   `tools/respuestas-que-mienten.py`.

   Y la cuarta añade algo que las otras tres no enseñaban: **el 500 de PHP 8 es
   la buena noticia.** Mientras la conversión silenciosa de `false` a `stdClass`
   funcionó, aquello no dejó ni excepción, ni log, ni código de error — solo una
   secretaría viendo un 200 y un alumno sin matrícula. El fallo se volvió medible
   el día que dejó de ser silencioso, que es el argumento entero de por qué
   existe esa herramienta.

### Lo que hace falta de Joseth ahora — por orden

1. **Asignarle el rol `Secretario` a alguien, colegio por colegio.** La migración
   crea la fila y **no se la da a nadie**, a propósito: hasta que alguien lo tenga,
   no cambia nada en ningún colegio. Es el paso que convierte el trabajo en efecto.
2. **Confirmar una lectura de «no crea usuarios».** `acudientes/crear` crea también
   la **cuenta** del acudiente —sin usuario no puede entrar—, así que «no crea
   usuarios» y «puede crear acudientes» se tocan. Se entendió que se refiere a las
   cuentas del **personal** y a la creación **masiva**
   (`perfiles/creartodoslosusuarios`, que quedó de superusuario), y no a la cuenta
   que nace con cada acudiente, porque desbloquear justo eso era el problema
   visible de la §30.2.
3. **Confirmar los tres `forcedelete`.** Grupos, profesores y perfiles se anclaron
   a superusuario por la regla de no regalar permisos, y porque la §28.4 ya lo
   había fijado. Si la secretaria debe poder borrar definitivamente, se cambia en
   tres líneas.
4. ~~La nivelación no se puede cerrar por periodo~~ — **contestado el 21 ago
   2026**: si lo que se toca es del año, el permiso se pide para el año. Las dos
   rutas de recuperación exigen ahora **los cuatro periodos abiertos**. Con uno
   cerrado no se puede tocar la recuperación final, y eso se eligió a sabiendas.
5. **Correr `php artisan anios:actuales` en los dieciséis**, antes de la copia de
   octubre. En desarrollo ya sale un aviso: el 2026 encendido en la papelera.
6. ~~**Confirmar los superusuarios de cada colegio**~~ — **contestado el 21 ago
   2026: se queda.** Ni se apagan ni se sale a correr el comando ahora. Se deja
   escrito porque el dato sigue siendo el mismo y el comando lo repite cada vez.
   Con
   `php artisan usuarios:superusuarios` ([12 §15](12-larastan-nivel-7.md)). En la
   copia de desarrollo hay diez encendidos y **seis se llaman
   `algo(inhabilitado)`**: el colegio los dio por apagados renombrándolos, y lo
   que el sistema lee es `is_active`. No se toca desde aquí —puede que alguna se
   siga usando pese al nombre—, pero el alcance del rol `Secretario` se diseñó
   sobre «los diez `Admin` son los diez `is_superuser`», y seis de esos diez son
   cuentas que nadie cree que existan.

### Contestado también el 21 de agosto: qué cierra el interruptor del periodo

Se le preguntó con las dos listas medidas delante y contestó las dos
([05 §40](05-codigo-muerto-y-roto.md)):

> **«Que poner asistencias no se bloquee al bloquear periodos.»**

Y corregir o borrar una ausencia, también libre. El criterio que queda: **el
interruptor cierra las notas, no la asistencia**. Las cinco rutas de ausencias
salieron del candado —eran tres de las 26 de la §27— y las cuatro que escriben
nota de comportamiento entraron, porque esa sí es una nota del boletín.

### Y una cosa que no encaja con lo que se dio por hecho

Al medir para el rol salió que **las 44 rutas de escritura de materias,
asignaturas, grupos, años y periodos llevan solo `auth.personal`**: hoy cualquiera
de los 51 profesores renombra materias, reasigna las asignaturas de todos los
grupos, cambia titulares, mueve la configuración del año y bloquea o desbloquea
periodos. Joseth decidió **no cerrarlas todavía**, y está bien decidido —cerrarlas
puede dejar fuera a un coordinador que hoy configura y no tiene el rol—, pero
conviene que quede escrito el efecto: **«la secretaria puede configurar el colegio»
ya era cierto antes del rol, y también lo es para los otros cincuenta.**

Lo demás de esa noche está abajo, en la tabla del §5, con las anteriores.

---

## 1. La importación de Excel, reanudable — **hecha el 20 ago 2026**

**Estado: cerrada.** Acordada por Joseth ese mismo día y hecha a continuación.
Se deja escrito lo que se hizo y, sobre todo, **en qué se apartó del plan de
arriba y por qué**, que es lo que no se puede reconstruir leyendo el diff.

`max_execution_time` está en **300 s** en la cuenta de cPanel, y está así **por
esto**: las importaciones de alumnos tardaban mucho. Bajarlo exige que la
importación deje de ser una sola petición que o entra entera o se pierde.

### Lo que ya estaba, y que era la mitad del diseño

Joseth había puesto un punto de control. No era un `Log::info` —eso es lo que
decía este documento antes de ir a mirarlo— sino **`Debugging::pin`, que escribe
en una tabla**, con el comentario `//No eliminar para continuar si se cae el
servidor!!` al lado. La intuición era la correcta y el sitio también; lo que le
faltaba era **forma**: tres cadenas sueltas por alumno (`'Alum_id: 431'`,
`'Grupo: 5A'`) sin decir de qué archivo ni de qué año son. Un humano puede
leerlas. El importador no.

Las líneas siguen ahí, porque son el único rastro de las importaciones
anteriores a hoy en las dieciséis bases, con el comentario reescrito para que se
sepa qué las reemplazó.

### Lo que se hizo

- **`importaciones`**, una fila por importación, no por alumno: archivo, huella,
  año, avance por hoja, filas, estado, error, inicio y fin
  (`2026_08_20_200000_create_importaciones_table`).
- **`App\Services\PuntoDeControlDeImportacion`**, que es quien decide qué se
  reanuda y qué no. Todo el porqué está en su cabecera.
- **La huella es el sha256 del CONTENIDO**, no el nombre: la secretaría sube
  tres veces `alumnos.xlsx` y son tres archivos distintos.
- **Idempotencia por el documento del alumno**, la clave natural. Antes, una
  fila sin `id` significaba «créalo» sin mirar si ese documento ya estaba; eso
  duplicaba alumno, usuario y matrícula.
- **Índice en `alumnos.documento`**, que hasta hoy no tenía ninguno porque nada
  buscaba por ahí. El `EXPLAIN` da el mismo criterio del paso 12: `type: ALL`,
  `possible_keys: NULL`.
- **La respuesta no cambia**: sigue siendo la cadena `'Importados.'`. Ese era el
  punto — es lo que separa esto de las colas (§3).
- Seis tests en `tests/Contrato/ImportacionReanudableTest.php`. Los tres que
  fijan comportamiento nuevo se comprobaron al revés, desactivando el arreglo,
  para que no pasaran por casualidad.
- Un método nuevo en `SafeUpload`, `nombreParaGuardar()`, porque
  `GuardsDestructivosTest` falló en cuanto el código nuevo leyó el nombre del
  archivo subido. Tenía razón: lo que se guarda en una columna acaba saliendo
  por una pantalla, y `getClientOriginalName()` vive en un solo sitio.

### Dónde se apartó del plan, y por qué

**«Por lotes, de N en N» → una transacción por fila.** El plan pedía lotes
pensando en la memoria, y **la memoria no es el problema**: `memory_limit` son
768M y una hoja de un colegio entero cabe de sobra. Lo que se agota es el
tiempo. Y anotar de N en N tiene un coste que el plan no había visto: obliga a
**reprocesar hasta N-1 filas** al reanudar, y reprocesar una fila de alumno no
es inocuo —el camino de acudientes inserta sin mirar si ya estaba—. Con la fila
entera y su marca de avance **en la misma transacción**, no se reprocesa
ninguna: una fila está aplicada si y solo si el punto de control la da por hecha.

Cuesta un `UPDATE` más por alumno sobre las ocho escrituras que ya hacía cada
fila, y la transacción ahorra los `fsync` sueltos de esas ocho. No se paga: se
cambia de sitio.

**«Medirlo antes» → la tabla es la medición.** No había forma de medir una
importación de producción desde aquí, y la tabla contesta la pregunta ella
misma, en cada colegio, sin instrumentar nada:

```sql
SELECT archivo, year, filas, TIMESTAMPDIFF(SECOND, inicio, fin) AS segundos
FROM importaciones WHERE estado = 'completada' ORDER BY id DESC;
```

**Ese número sigue siendo el que falta**, y ahora sí se puede recoger: hace
falta una temporada de matrículas en el colegio que más importa antes de tocar
`max_execution_time`. La otra pregunta que quedó abierta —si hay **otro**
endpoint apoyado en esos 300 s— sigue abierta y sigue necesitando
`CONSULTAS_LENTAS_MS`.

### Y una corrección: importador vivo hay uno, no dos

Este documento decía que los dos importadores eran
`ImportarController::postAlgo()` y `::postCartera()`. **`postCartera` está roto**
desde el salto a `maatwebsite/excel` 3.x, con el mismo error exacto que
`GET api/importar`: la firma de la 2.x. No había salido antes porque el muestreo
de la P2 solo golpeaba lecturas sin parámetro, y esta es un POST con un archivo
dentro. Queda fijado en `ExcelTest` y descrito en
[05 §8](05-codigo-muerto-y-roto.md); qué debe hacer la importación de cartera es
una decisión del colegio, como los otros tres de esa familia.

### Lo que esto NO cubre

Si la secretaría, en vez de volver a subir el mismo archivo, **exporta uno
nuevo** y sube ese, la huella cambia y no se reanuda nada. No hace falta que lo
haga: la hoja recién exportada ya trae el `id` de los alumnos que sí entraron.
Los dos caminos reales están cubiertos, cada uno por su lado — el punto de
control el primero, la clave natural el segundo.

Lo que sigue sin cubrir es **duplicar acudientes** en ese segundo camino: sus
tres ramas dependen de lo que la secretaría escribió en la hoja, y hacerlas
idempotentes exige decidir qué significa «este acudiente ya está» cuando la fila
viene sin documento. No se tocó a propósito.

---

## 2. Unificar las fechas en `-05`

**Estado: propuesta de Joseth (20 ago 2026)**, razonable y sin urgencia.

Hoy conviven dos zonas: `config/app.php` dice `UTC`, el código de siempre llama
114 veces a `Carbon::now('America/Bogota')` y la sesión de la Fase 3 llama 8
veces a `Carbon::now()`. **Se revisaron las ocho y no hay fallo**
([§10](05-codigo-muerto-y-roto.md)): cada grupo escribe y compara en su propia
zona, y una duración calculada entera en una zona da lo mismo que en la otra.

La propuesta —todos los clientes están en Colombia, así que `-05` en todas
partes— es defendible. Como dice el propio Joseth, cualquier zona sirve mientras
se maneje bien; el valor de unificar no es la zona, es dejar de tener dos.

**La trampa, que es la razón de que esto no sea un cambio de una línea:** poner
`'timezone' => 'America/Bogota'` en `config/app.php` cambia lo que devuelve
`Carbon::now()` **para los datos que ya están escritos**. Las filas de
`personal_access_tokens` tienen `expires_at` en UTC; con el `now()` nuevo,
cinco horas por detrás, **esos tokens vivirían cinco horas de más**. No se ve, no
falla, y no lo detecta ningún test que no lo esté buscando.

O sea que el cambio son dos cosas: la línea de configuración **y** decidir qué
pasa con lo ya escrito. Lo barato es hacerlo en una ventana en la que se puedan
invalidar todas las sesiones vivas —`sesion:limpiar` con `--dias=0`, o vaciar la
tabla— y avisar de que todo el mundo vuelve a entrar. Es lo mismo que ya se hizo
una vez al pasar de JWT a Sanctum.

Las demás tablas no necesitan conversión: sus fechas ya están en hora de
Colombia, que es la que pasarían a leerse.

`importaciones`, que es de hoy, escribe en UTC como la sesión — pero sus dos
marcas solo se restan entre sí, nunca se comparan con otra tabla, así que el
cambio no la afecta.

---

## 3. Colas para importadores e informes

**Estado: posible desde el 20 ago 2026** —sí hay cron—, y frenado por otra cosa.

Ya no es un problema de infraestructura: un worker es `queue:work
--stop-when-empty` desde el scheduler, y el cron está. Lo que frena es que
encolar **cambia el contrato de los cuatro clientes** —hoy el importador
responde con el resultado; encolado responde con un identificador y hay que
preguntar—, y uno de esos clientes es la app de Flutter, que es **una sola para
los dieciséis colegios** y por tanto no se puede escalonar.

Y sigue faltando el número: «los imports dan timeout» es una impresión, y el
techo real son cinco minutos. Ver [02-plan-rendimiento.md](02-plan-rendimiento.md) §5.

---

## 4. Las definitivas: notas que se pierden, se duplican y no se actualizan

**Estado: analizado, planificado y decidido — parado hasta que termine la
migración en curso.** Lo decidió Joseth el 20 ago 2026, y la razón es de orden,
no de duda: el trabajo entra de lleno en el cálculo de notas, que el §5 del plan
protege, y abrirlo a la vez que la migración deja dos frentes tocando lo mismo.

El análisis completo está en **[10-definitivas.md](10-definitivas.md)**: seis
sitios distintos escriben en `notas_finales` con cinco criterios distintos de qué
borrar, ninguno transaccional, sobre una tabla **sin clave única**. De ahí salen
los tres síntomas que se venían reportando por separado y resultaron ser el mismo
problema: definitivas que desaparecen al cambiar de periodo, definitivas
duplicadas que el profesor puede editar dos veces, y notas que los profesores
juraban haber puesto —y tenían razón.

Lo que **no** hay que volver a averiguar cuando se retome:

- La causa principal del borrado es `BoletinesController::putDetailedNotas`, con
  su propio `// CALCULAMOS SIN VERIFICAR QUE ESTÉ DESACTUALIZADO` al lado. Usa el
  periodo **del usuario que mira**, no el del boletín, y su ruta es
  `boletin.propio`: también lo dispara un acudiente.
- La comprobación de «desactualizada» se calcula en /notas y **el `if` de al lado
  no la mira**. Y aunque la mirara, es un `MAX(notas.updated_at)`: ciega a los
  borrados, a los porcentajes y a los alumnos nuevos.
- `putSubunidad` no guarda nada: la consulta está en comillas dobles con sintaxis
  de concatenación de simples.
- El front no revierte el valor cuando falla el guardado, y pierde la última nota
  tecleada si se cambia de asignatura antes del segundo del `debounce`.

Las **tres decisiones ya están tomadas** y no se re-litigan (10 §9): la fila
existe siempre que exista la matrícula; entre notas duplicadas gana la más alta
—pero entre definitivas duplicadas gana la manual—; y la fórmula **no** se
normaliza, para que los porcentajes mal puestos se vean en la planilla.

Cuando se retome, se empieza por la fase 0: la herramienta de medición, para
saber el tamaño real del daño en las dieciséis bases antes de tocar código.
**Antes de optimizar algo: medirlo.**

---

## 5. Lo que espera una decisión del colegio

| Qué | Dónde está descrito | Qué falta decidir |
|---|---|---|
| Cuatro endpoints rotos desde siempre | [05 §6.5, §7.2, §8, §9.2](05-codigo-muerto-y-roto.md) | qué debe devolver cada uno; en dos de ellos, si la operación debe existir. **De uno ya no hay que averiguar nada más** (21 ago 2026): `periodos/update/{id}` falla con y sin el campo que la §9 dejó en duda, y arreglarla enciende la única forma de dejar dos periodos actuales en un año — [05 §31.1](05-codigo-muerto-y-roto.md) |
| La estructura de roles y permisos | [06 §4](06-autorizacion.md) | si los roles de la base se quedan y se pueblan, o se borran las cuatro tablas |
| 9 rutas de catálogo sin guard | [08](08-revision-idor.md) | a quién se abren; no exponen a nadie, pero no están decididas. Vuelto a medir el 20 ago 2026 tras [05 §16](05-codigo-muerto-y-roto.md): 12 → 11 → 9. La que salió de la lista no se decidió, **se recategorizó**: `unidades/de-asignatura-periodo` no era una lectura, escribía |
| `APP_DEBUG` en producción | [01](01-plan-seguridad.md) | comprobarlo colegio a colegio. `display_errors` de PHP está en Off, así que la mitad del riesgo ya está cubierta |
| Los correos `username@myvc.com` autogenerados | [01](01-plan-seguridad.md) · [12 §9](12-larastan-nivel-7.md) | **Ya no es el reseteo cruzado**, que se cerró el 21 ago 2026 ([12 §10](12-larastan-nivel-7.md)). Lo que queda es qué son esas 29 direcciones: el generador pega el username delante del dominio del **proveedor**, así que no es un buzón de la familia, y cuatro ni siquiera son direcciones válidas porque el nombre lleva tilde. **Contestado el 21 ago 2026: se queda como está** — ni se repara la tilde ni se deja de generar. El número ya está medido para el día que se toque |
| **Ocho cuentas que compartían correo se quedaron sin recuperación** | [12 §13](12-larastan-nivel-7.md) | consecuencia medida del arreglo anterior: de un correo compartido, el enlace se emite para la cuenta de **id más bajo** y las demás no pueden pedirlo. Tres opciones escritas allí; la del medio —que `postRecuperarClave` acepte un `username` para elegir dentro del grupo— **no reabre el agujero**, pero no hace nada hasta que `myvc_front` y la app de Flutter lo manden. **Contestado el 21 ago 2026: se deja como está**, dependen del reseteo a mano igual que las otras 2.112 |
| ~~`GET api/contratos` manda el expediente y el cliente solo quiere el nombre~~ | [05 §14.4](05-codigo-muerto-y-roto.md) · **[§10](#10-get-apicontratos-entrega-la-ficha-personal-de-los-docentes-a-un-alumno--24-ago-2026)** | **Recortado el 24 ago 2026, y la decisión que aquí se reservaba —«qué columnas»— la tomé yo con la medición delante: los once consumidores leen id, nombre, foto y `user_id`, y ninguno toca lo personal. Lo tomé porque la ruta sólo pide un token y entregaba domicilio y móvil de dieciséis adultos a cualquier alumno. **Sigue sin desplegar y revertirlo es un commit**; si prefieres otras columnas, se cambian y ya |
| `GET api/perfiles/usernames` devuelve los 2.351 usuarios del colegio | [05 §14.4](05-codigo-muerto-y-roto.md) | apuntar `UserConfiguracionCtrl` a `comprobarusername/{username}`, que ya existe, **y desplegar el front antes** de cerrar la ruta |
| `GET api/perfiles/username/{username}` no comprueba que el usuario sea el tuyo | [05 §14.4](05-codigo-muerto-y-roto.md) | si `ExigirPersonaPropia` aprende a resolver un nombre de usuario, o si la ruta deja de aceptar parámetro y lo saca del token |
| `GET api/asignaturas/listasignaturas-alone` le da a un alumno las asignaturas del profesor con su mismo id | [05 §16.6](05-codigo-muerto-y-roto.md) | es la misma pregunta que Joseth dejó abierta en [05 §11.2](05-codigo-muerto-y-roto.md): si esa pantalla debe enseñarle sus asignaturas de verdad. Cerrarla con `auth.personal` es de una línea; decidir qué ve el alumno, no |
| `PUT api/publicaciones/borrar-comentario` responde 500 a todo el que no sea superusuario | [05 §22.3](05-codigo-muerto-y-roto.md) | si se arregla. Hoy nadie borra un comentario suyo; arreglarlo enciende esa función en los dieciséis colegios de golpe |
| `GET api/candidatos/conaspiraciones` responde 500 a alumnos y acudientes desde siempre | [05 §18.4](05-codigo-muerto-y-roto.md) | qué votación es «la suya» cuando hay varias en curso. Y que arreglarlo **enciende** para los alumnos una pantalla que hoy no funciona en los dieciséis colegios, que es una decisión y no un arreglo |
| El lector de Tardanzas devuelve el hash bcrypt del usuario | [05 §25.4](05-codigo-muerto-y-roto.md) | **solo decir que sí.** Se temía que el lector validara contra ese hash estando sin red; se fue a mirarlo (`tardanzasMyvc-old`) y no: `insertUser()` hace `user.password = localStorage.password` antes de guardar, así que la columna local lleva la contraseña **en claro**, y el login sin red compara contra eso. El único sitio que conserva el hash es `localStorage.USER`, que nadie lee. Ningún otro cliente llama a estas rutas |
| Un `Usuario` administrativo sin `is_superuser` lee en Tardanzas pero no puede subir | [05 §25.3](05-codigo-muerto-y-roto.md) | si el `if` de `TSubirController::user()` debe decir `Profesor o Usuario` como el de lectura, o si dejar fuera al administrativo era la intención. Hoy entra al lector, ve los datos y recibe 400 al subir |
| `years.profes_can_edit_alumnos` decide más cosas de las que dice su nombre | [05 §29.1](05-codigo-muerto-y-roto.md) · [12 §20](12-larastan-nivel-7.md) | **Contestado el 21 ago 2026, y aplazado a propósito.** No es un permiso que se desbordó: es un **módulo** —una pantalla parecida a la tabla de Alumnos donde los profesores editan alumnos para ayudar a la secretaria—, y la bandera es su interruptor, así que las 19 rutas son el módulo entero y ese es el tamaño que le toca. Recontado antes de preguntar: 25 apariciones y 19 rutas, catorce de ellas el módulo de matrículas completo. **Hoy está apagada en todos los colegios por seguridad**, no por olvido, así que no hay nadie esperando la respuesta: qué debe poder hacer un docente con ella encendida **se decide después de la migración**. La superficie queda medida y fijada por `BanderaProfesEditaAlumnosTest`, que es lo que había que dejar hecho |
| **Quién es el «Secretario»** —y el «Psicólogo»—, que el código busca donde no están | [05 §30.2](05-codigo-muerto-y-roto.md) | ocho sitios preguntan `Role::isSecretario()` y la tabla `roles` no tiene ese nombre; otros tres preguntan `users.tipo == 'Secretario'`, y `tipo` solo toma los cuatro valores del `switch` del contexto, así que es siempre falso. Hoy el criterio efectivo en los once es `is_superuser` — y en `AcudientesController` eso deja a un administrativo sin poder crear acudientes, que es lo contrario de lo que la línea pretendía. Si la respuesta es el rol `Admin`, hoy no cambia nada y mañana sí. Y con el psicólogo pasa al revés: su rama de `putGuardarValor` compara `tipo` con `'Psicólogo'` y **nunca se ejecuta**, así que las necesidades educativas especiales de un alumno solo las escribe hoy un superusuario — con el comentario del propio autor al lado diciendo que quería el rol |
| Los intentos de un examen son ilimitados: `oportunidades` no lo mira nadie | [05 §43.1](05-codigo-muerto-y-roto.md) | si se limitan. Es la que más puede sorprender a un colegio a mitad de periodo, y por eso quedó fuera cuando el 21 ago 2026 se cerraron `in_action` e `inicia_at`. **Y lo de `para_alumnos` ya está medido (22 ago 2026, [05 §74](05-codigo-muerto-y-roto.md)): no es que su uso no esté claro — es que para el alumno no decide NADA.** Con él apagado, y con `compartida` apagada, el alumno abre el examen igual; los dos sólo se leen en listados del lado del profesor. O sea que el profesor marca «no es para alumnos», la actividad le desaparece de su lista y el alumno la sigue abriendo. Encenderlo es una línea y **esconde de golpe actividades que hoy se ven**, así que es decisión del colegio y va con la de los intentos |
| El interruptor con el que el colegio cierra el periodo a los profesores lo elige el cliente | [05 §27](05-codigo-muerto-y-roto.md) | **Contestado y hecho el 21 ago 2026: la 2**, derivar el periodo de la fila — vive en `App\Support\PeriodoDeLaFila` ([05 §27.1.1](05-codigo-muerto-y-roto.md)). La 1 se descartó por barata —no cerraba la rejilla de definitivas ni `notas/update/{id}`, que no mandan `periodo_id`— y la 3, ignorar `num_periodo`, por medir el front: apagaría la rejilla. **Y el mismo día se cerraron las dos preguntas que abría** ([05 §27.4](05-codigo-muerto-y-roto.md)), las dos con «se queda»: escribir en un año pasado cuyo periodo quedó abierto **se permite** —el par (año, periodo) es la única palabra; exigir además `years.actual` apagaría las correcciones de enero— y la nivelación de otro año **la sigue gobernando el año en curso**, porque `recuperacion_final` se guarda por año y ninguna pantalla manda un `rf_id` viejo. Las dos con su test, y el segundo falla a propósito el día que alguien lo cierre |
| **Borrar un grado apaga la planilla de sus profesores, y no hay forma de deshacerlo** | [05 §70](05-codigo-muerto-y-roto.md) | Medido el 22 ago 2026: mandar un grado a la papelera deja al profesor con **0 asignaturas** donde tenía 1 —`Profesor::asignaturas` filtra `gr.deleted_at is null`— mientras **la rejilla de grupos sigue enseñando el grupo**, así que desde administración no se ve nada raro. No se ha movido ninguna fila y las notas siguen ahí; lo que hay que decidir es qué debe pasar: que `destroy` **se niegue** si el grado tiene grupos vivos, o que haya un `restore` (hoy `GradosController` no lo tiene, así que sólo se deshace entrando a la base). Las dos son código pequeño; elegir no lo es |
| **La planilla de la puerta manda el correo de cada alumno, a 392 consultas por petición** | [05 §75.6](05-codigo-muerto-y-roto.md) | Medido el 22 ago 2026: `planillas-ausencias/tardanza-entrada` monta el año entero y llama a `Alumno::userData()` **una vez por alumno** — 1 + 13 + **378** consultas en una sola petición, en un colegio de 378 matriculados. Y esas 378 añaden sobre lo que `Grupo::alumnos()` ya traía **una sola columna: el correo**, a dos hojas para imprimir que del alumno leen `nombres`, `apellidos` y `estado`. Quitar `userData` es un `foreach` menos; lo que hay que decidir es encoger una respuesta que es **contrato con dieciséis copias del front** que no se pueden grepear desde aquí. `PlanillasAusenciasTest` deja el arreglo comprobable: el día que se quite, las consultas por alumno tienen que ser cero |
| **Quién puede corregir y borrar una falta** | [05 §75](05-codigo-muerto-y-roto.md) | **Contestado el 22 ago 2026: se queda abierto al personal.** No es una pregunta que quede, es una que se cerró — y se deja aquí porque el porqué es lo que no se puede reconstruir. `AusenciasController` calculaba `Role::isCoorDisciplinario()` y lo tiraba en un `if` con el cuerpo vacío, en corregir y en borrar. Rellenarlo parecía el arreglo y era el error: el rol **no gobierna esto en ningún cliente** —el menú de AngularJS enseña «Asistencias» a `profesor`, `crearFaltaModal` repite el botón «Eliminar» tres veces y sólo uno mira el rol, y `myvc_flutter` no mira ninguno—, así que cerrarlo dejaba a los 51 profesores sin poder corregir una falta mal puesta, en dieciséis colegios y por una app que no se publica el mismo día. Lo que sí se cerró es el rastro: **5.684 de 5.689 borrados no tenían autor** |
| **Si el `Secretario` restaura desde la papelera** | [05 §76.2](05-codigo-muerto-y-roto.md) | Las cinco rutas de `restore` que se cerraron el 22 ago piden `Autoriza::esSuperusuario`, que es **el criterio del gemelo que borra** de cada pareja y no uno nuevo — la regla de la propia clase es que crear un rol no regale permisos, y el alcance del `Secretario` repartido el 21 ago no nombra la papelera. Hoy `esSuperusuario` y `esAdministrativo` son las mismas diez personas, así que no cambia nada; el día que exista el rol, sí. Subirlo a `esAdministrativo` es **una palabra en cinco sitios**, y no se hace desde aquí porque sería concederle algo por la puerta de atrás de un arreglo |
| **Los nueve catálogos contestan de cuatro maneras al mismo cuerpo vacío** | [05 §78](05-codigo-muerto-y-roto.md) | Medido el 22 ago 2026: cinco dan **422** «Datos incorrectos», tres dan **500** con el `SQLSTATE` de MySQL y uno da **500** por un error de PHP. Ninguno escribe —lo impide el `NOT NULL` del esquema, no el código— y el que sí escribía, `contratos`, ya está cerrado. Unificar los cuatro 500 a 422 son cuatro `try/catch`; lo que hay que decidir es que **el front pinta el mensaje del cuerpo**, así que hoy a un administrador se le enseña el `SQLSTATE` entero y mañana «Datos incorrectos». `CrearUnCatalogoTest` deja la tabla fijada para que el cambio sea deliberado |
| **Cualquiera puede quedarse con el correo de recuperación de otra cuenta** | [05 §79.1](05-codigo-muerto-y-roto.md) | **Contestado el 22 ago 2026: no se toca, se mide.** `perfiles/guardar-mi-email-restore` escribe `users.email` —la llave de la cuenta— sin validar nada, y sólo pide token: las 2.321 cuentas la alcanzan. Demostrado el viaje entero: un alumno se pone el correo de otra cuenta con id más alto, la dueña pide su reseteo y el enlace sale **a nombre del alumno**. No es robo de cuenta —el correo llega al buzón de la víctima— pero le quita la recuperación para siempre. Cerrarlo son dos `if`; lo que frena es el coste medido del segundo: rechazar un correo repetido deja a una familia sin poder poner la dirección del padre en dos cuentas, que es el caso legítimo de la [§13 del 12](12-larastan-nivel-7.md). `EscriturasConSoloTokenTest` fija el agujero, no el arreglo |
| **`alumnos/guardar-valor-varios` guardará uno de cada N el día que se encienda la bandera** | [05 §79.2](05-codigo-muerto-y-roto.md) | Mina con fecha: la rama del profesor tiene el `return` **dentro** del bucle y la del administrativo fuera. Cuelga de `years.profes_can_edit_alumnos`, apagada en los dieciséis y con su decisión aplazada a después de la migración (§29.1), así que hoy no muerde. Va en la misma fila que esa bandera: el día que se decida encenderla, esto se arregla antes |
| `Login::ponerEnElPeriodoActual` se queda con el primer año actual, sin `ORDER BY` | [05 §28.3](05-codigo-muerto-y-roto.md) | qué hacer si un colegio tiene dos años marcados como actuales. Los tres caminos que los creaban están cerrados, así que esto solo puede venir de datos de antes; poner `ORDER BY year DESC` es una línea, pero **decide en qué año amanece un colegio** que hoy entra en el otro. Se contesta mirando las dieciséis bases: `SELECT id, year FROM years WHERE actual=1 AND deleted_at IS NULL` |

---

## 6. Continuo, sin final

- **Larastan del 2 al 3 — hecho el 20 ago 2026.** Sigue siendo cierto que cada
  subida encuentra cosas: 21 endpoints rotos en el 1, cuatro en el 2, y en el 3
  un fallo de otra clase, porque el nivel es de otra clase. El 3 no comprueba
  que algo exista sino que **sea lo que dice ser**, y lo que salió fue eso:

  - **Siete columnas `tinyint(1)` escritas con booleanos de PHP.** Eloquent no
    relee la fila tras `save()`, así que el JSON de la llamada que crea la fila
    lleva `false` y el de cualquier lectura posterior lleva `0` — el mismo campo
    del mismo registro con dos tipos según por dónde se pida. En
    `vt_participantes` las dos formas salen **en la misma respuesta**: los
    restaurados de la papelera con `0`, los creados en esa llamada con `false`.
    33 sitios; larastan veía 14 y el resto estaban detrás de un
    `Request::input('is_active', true)`, que para el análisis es `mixed`.
    Arreglado hacia `0` porque es lo que reciben los clientes casi siempre —
    medido: con `EMULATE_PREPARES` en false, MySQL devuelve `int`—, y fijado por
    el viaje de ida y vuelta en `BanderasDeUnBitTest`.
  - **El generador de columnas tiraba el `NOT NULL`.** `tools/columnas-en-los-modelos.php`
    leía el tipo de cada columna y descartaba el resto de la línea, así que los
    47 modelos con columnas nulables las anotaban como obligatorias. Arreglado en
    la herramienta, no en los modelos.
  - **Un `[0]` sobre el entero que devuelve `DB::update()`**, dentro del bucle
    del importador: un warning de PHP por cada alumno actualizado de cada
    importación, en la operación más lenta que tiene la API.

  Y una cosa que no había pasado antes: **el nivel 3 no dejó ninguna excepción
  nueva** en `phpstan.neon`. El 1 dejó once y el 2 tres, todas endpoints rotos
  que esperan una decisión; los hallazgos del 3 o tenían arreglo claro o eran
  anotaciones que mentían.

- **Larastan del 3 al 4 — cerrado el 20 ago 2026.** Medido al empezar: 55
  errores, todos de la familia «esta condición no decide nada» y «esta rama no
  se ejecuta». Acertó el pronóstico: es donde estaban los fallos. Se arreglaron
  primero los que tenían arreglo claro, quedaron 30 mecánicos, y se cerraron
  así: **24 borrados o simplificados, 1 reescrito sin cambiar comportamiento y
  5 anotados en `phpstan.neon`** con su motivo y su `count`.

  Los cinco que se quedan no son pereza, y merece la pena el porqué de cada uno
  —está entero en [05 §12](05-codigo-muerto-y-roto.md), aquí va en una línea—:
  en tres de ellos **la línea que sobra es la única pista de lo que se
  pretendía** (el `$alumnos[$i]` suelto de `Definitivas`, el `return $user`
  de `aplicacion-descargas/detailed`, el cuerpo 2.x de `simat/alumnos-exportar`
  con las instrucciones de la plantilla del SIMAT dentro), y en los otros dos
  hay una decisión que dice que se queden (el `$todos_anios = true` de §11.2, y
  el `if` que protege el `$cantidad_pregs = 4` de las actividades, que es el
  guardia que hará falta el día que ese 4 se sustituya por un `COUNT(*)`).

  Y lo que se llevó por delante, que es el hallazgo del cierre: los tres
  `Request::input('year_selected') == true || ... == 'true'` de los informes
  **no se escribieron muertos, murieron con el salto a PHP 8**. En PHP 7 la
  rama derecha atrapaba los valores falsy, porque `0 == 'true'` valía true; en
  PHP 8 ya no. Un cliente que mandara `year_selected=0` recibía el año
  seleccionado antes de la migración y el actual después, sin que nadie
  cambiara una línea. Es el mismo patrón que los `tinyint(1)` del nivel 3: el
  analizador no encuentra código muerto, encuentra **cambios de comportamiento
  del salto de versión** que llevaban ahí sin mirar.

  Lo que salió, que es la razón de haberlo empezado:

  - **Cambiar la contraseña borraba el correo de recuperación**, en los
    dieciséis colegios y ahora mismo. Dos `if` escritos
    `has('x') || has('x') == ''`, que son siempre ciertos porque `false == ''`
    vale true en PHP. Uno asignaba `null` al correo cuando el cliente no mandaba
    el campo —y el front nunca lo manda, comprobado en `UserConfiguracionCtrl.js`—;
    el otro, el de `oldpassword`, resultó ser **lo único que defiende el
    endpoint**: al ser siempre cierto, la contraseña antigua se comprueba
    también cuando no la mandan. Arreglados los dos y fijados en
    `CambiarPasswordTest`.
  - **Doce `abort()` inalcanzables** en los `forcedelete` y `restore` de la
    papelera: `findOrFail()` ya lanza, así que el `else` prometía un 400 que
    nunca ocurre —y en dos ficheros con un código distinto del de al lado para
    el mismo caso—. Lo que devuelven de verdad es el 404 de `findOrFail`, que
    además es el correcto.
  - **Dos que esperaban decisión, y Joseth las decidió el mismo día** — en
    [05 §11](05-codigo-muerto-y-roto.md), con el análisis entero por qué se
    esperó:
    - El `case 'Profesor' or 'Usuario':`, que es `case true`, y cuyo error de
      escritura era lo único que impedía que un alumno viera las asignaturas del
      profesor con su mismo id (34 alumnos en la base de desarrollo, uno con 92
      ajenas). Con la regla puesta —**un alumno o acudiente solo alcanza
      asignaturas de su grupo o de todos sus grupos**— el `switch` queda escrito
      como se pretendía y la consulta ajena se retira. Sigue abierto si esa
      pantalla debe enseñarle sus asignaturas de verdad, que Joseth dejó fuera a
      propósito.
    - El `$todos_anios = true` fijado a mano: **se queda**. Que un profesor vea
      a todos los estudiantes del plantel sin importar el año está bien, así que
      no era un descuido pendiente de revertir; lo que faltaba era tenerlo
      escrito.
  - **Y de esa misma decisión salió lo que no se estaba mirando:** los
    buscadores `alumnos/personas-check` y `alumnos/documento-check` iban sin más
    guard que `auth.token`. Un alumno obtenía 61 compañeros con nombre y foto, y
    51 **con su número de documento**; un acudiente, lo mismo. Ahora son
    `auth.personal`, fijado por `BuscadoresDePersonasTest` — [05 §11.3](05-codigo-muerto-y-roto.md).
    Queda la mitad del front: la caja de búsqueda del `sidebarMenu` se pinta sin
    `ng-if` y un alumno la ve.

  **Y el aviso que dejó escrito el 3 se cumplió al pie de la letra**: las
  excepciones del 4 no se podían poner antes de subir el nivel, porque el
  analizador avisa de las que no llegan a usarse y habrían roto el análisis del
  3. Van con `count`, como todas. El mismo mecanismo mordió en el cierre por el
  otro lado: la sesión del PIAR arregló los `$document` de dos controladores de
  Piars y **eso dejó sin casar las dos entradas que los documentaban**, con lo
  que el análisis se puso en rojo sin que ninguno de los dos hubiera tocado
  `phpstan.neon`. Es lo que hace ese `count`: cuando el fallo se arregla de
  verdad, la anotación grita en vez de callarse.

  Y una cosa aprendida sobre el seed, que vale para todo lo que venga: **la base
  de tests no puede demostrar los fallos que dependen de que dos numeraciones se
  crucen**, porque copia un solo grupo de alumnos y ahí los ids de alumno y de
  profesor no se solapan. El candado del `switch` se intentó escribir y se tiró:
  habría pasado siempre, dijera lo que dijera el código.
- **Larastan del 4 al 5 — cerrado el 20 ago 2026.** Medido al empezar: **45
  errores**, el número más bajo de todas las subidas —el 1 encontró 341, el 2
  465, el 3 61, el 4 55—. Y aun así trajo el fallo más caro de la serie, que es
  lo que hay que recordar de este nivel: **el número de errores no dice nada del
  tamaño de lo que hay dentro**.

  El 5 comprueba los argumentos. La mayoría de lo que encuentra son cadenas
  donde se espera un entero, y PHP las convierte solo: 31 de los 45 eran eso
  —22 `abort('400', …)` y tres `Carbon::createFromDate('2010','08','05')`
  copiados— y funcionan hoy, comprobados en el contenedor. Se hicieron
  explícitos y ya. Otros cinco eran relaciones Eloquent con la sintaxis de
  Laravel 4 (`hasMany('Alumno')`, sin namespace) que no llamaba nadie, borradas.

  **Y una era `count()` sobre un Builder, que no se convierte: es un TypeError.**
  De ahí salieron los dos hallazgos, y salieron juntos porque se tapaban el uno
  al otro — [05 §13](05-codigo-muerto-y-roto.md):

  - **`DELETE api/images-users/destroy/{id}` borraba la imagen y después
    respondía 500.** El `count()` está en la última línea del método: cuando
    revienta, el archivo ya no está en el disco, la fila de `images` está marcada
    y las cinco referencias —alumnos, profesores, acudientes, usuarios y años—
    puestas a `null`. El cliente recibía un error de una operación que sí había
    ocurrido, y quien reintentara vería el 404 del `findOrFail`, que parece otro
    fallo distinto. En PHP 7 ese `count()` era un warning que devolvía 1: es el
    tercer cambio de comportamiento del salto de versión que encuentra el
    analizador, después de los `tinyint(1)` del 3 y los `== 'true'` del 4.

    El bloque buscaba `change_asked.oficial_image_id`, una columna que no existe
    en ninguna de las 90 tablas — las buenas son cuatro y están en
    `change_asked_data`. **Lo que pretendía sí hacía falta, y Joseth lo decidió
    el mismo día: se borra la petición**, no se pone su referencia a `null`. Una
    que pide cambiar la foto por una imagen que ya no está no es una petición a
    medias, es una que solo se puede rechazar. Se borra como lo hace
    `putDestruir`, en las tres tablas y en una transacción. El efecto que no se
    ve venir —que una petición es una por usuario y año, así que arrastra el
    cambio de asignatura que viajara dentro— tiene su propio test para que no
    sea una sorpresa.

  - **Y detrás, un alumno borrando la foto de cualquiera.** La ruta llevaba
    `persona.propia` desde la revisión de IDOR y el guard **no miraba nada**:
    recoge los identificadores por su NOMBRE, y esta es la única ruta de imagen
    que llama `{id}` a lo que sus cuatro hermanas llaman `{imagen_id}`. Un alumno
    borraba la foto de un profesor —o el logo del colegio, que vive en
    `years.logo_id`— y recibía el 500 de arriba con el borrado ya hecho.

    Es el **tercer punto ciego de la misma familia**, después de los buscadores
    de [05 §11.3](05-codigo-muerto-y-roto.md) y del inventario de
    [08 §4](08-revision-idor.md), y los tres caben en una frase: *el guard estaba
    puesto y la pregunta era otra*. Aquí no era «¿tiene guard esta ruta?» —lo
    tenía— sino «¿el guard reconoce lo que esta ruta llama id?».
    **`inventario-autorizacion.py` no contesta esa**, y esta sí es mecánica:
    comparar el nombre del parámetro de cada ruta con las claves que busca su
    middleware. **Se escribió como test y no como herramienta** —decisión de
    Joseth el mismo día—, porque así corre con los otros y no depende de que
    alguien se acuerde de lanzar un script: son los dos últimos de
    `AutorizacionTest`, leen las claves del propio middleware por reflexión, y el
    primero se comprobó al revés devolviendo la ruta a `persona.propia` a secas.
    Lo que siguen sin ver son las claves que viajan en el cuerpo: eso no tiene
    atajo estático y hay que golpearlo.

  Lo que queda anotado en `phpstan.neon` son seis errores que son un solo fallo
  contado tres veces: los tres endpoints del importador con la firma de
  maatwebsite 2.x. **El tercero —`GET api/importar/modificar/{year}`— no estaba
  en ninguna lista hasta este nivel**, y no salió antes porque el muestreo de la
  P2 golpeaba lecturas sin parámetro y esta lleva `{year}`. Es la contraria de la
  lección de la §8: allí, lo que no se golpea no se sabe si funciona; aquí, lo
  que no se puede golpear a veces se puede leer.

- **El barrido de lo que sale, hecho el 20 ago 2026.** Las herramientas de
  autorización preguntan todas por la petición —qué identificador viaja, qué
  guard lo mira—. Golpear las 121 lecturas con el token de un alumno y mirar si
  en la respuesta salía el dato personal de alguien encontró **siete rutas** que
  no nombran a nadie y devuelven a todo el mundo: la planilla SIMAT del colegio
  entero, el directorio de las 2.279 personas, la hoja de vida de los 47
  docentes. Cerradas con `auth.personal` y fijadas por catorce casos de
  `SuperficieDeUnAlumnoTest`; las tres que no se pueden cerrar sin romper una
  pantalla de familia están arriba, en la tabla del §5. Todo el detalle en
  [05 §14](05-codigo-muerto-y-roto.md).

  Lo que hay que recordar de esto no es el número: es que **la medición del
  resultado encuentra lo que la medición de la petición no puede ver**, y que era
  el mismo criterio que ya hacía útiles a los tests de contrato, sin aplicar a la
  autorización. Y no está agotado: el barrido solo miró **lecturas**, y solo con
  token de alumno.

- **El barrido de las escrituras, hecho el 20 ago 2026.** La otra mitad del
  anterior, y con la pregunta cambiada: no qué código responde una ruta sino **si
  llegó a escribir**, que aquí no es lo mismo porque este proyecto lee con `PUT`.
  Medido escuchando las consultas: de 417 escrituras, 133 llegaban al controlador
  con token de alumno y **27 cambiaban datos**. Entre ellas, ponerle la
  contraseña a todos los alumnos de un grupo, los seis interruptores de la
  elección del colegio, y quedarse con la imagen de otro —que no es una fuga sino
  una escalada: hecha suya, los demás guards ya la dan por suya—. Cerradas, más
  veinte casos nuevos en `SuperficieDeUnAlumnoTest`. Detalle en
  [05 §15](05-codigo-muerto-y-roto.md).

  De paso corrigió al barrido de lecturas del mismo día, que **solo había mirado
  las GET**: el fichero de acudientes se lee con `PUT` y por eso no había salido.

  **Y el barrido se quedó**, que es lo que permite retomarlo:
  `tests/Barrido/SuperficieDeUnTokenTest.php`, fuera de la corrida normal, con
  el tipo de usuario en `BARRIDO_TIPO`. Reproduce las dos medidas —qué sale y
  qué escribe— y afirma una sola cosa: que su mapa de identificadores cubra
  todos los parámetros de las 539 rutas. Un barrido que se encoge en silencio
  sería peor que no tenerlo.

  Sigue fuera de su alcance lo que un alumno sí puede escribir pero sobre lo de
  otro sin que el guard pueda verlo, como fue el caso del muro: eso no lo
  encuentra un barrido, lo encuentra leer el controlador.

- **El barrido del acudiente, hecho el 20 ago 2026 — y el barrido, arreglado.**
  El acudiente **no encontró ningún agujero**: alcanza dos rutas más que el
  alumno y las dos le devuelven lo de su acudido, que es la regla. Lo que sí
  encontró la segunda pasada fue **tres fallos del propio barrido**, y por eso
  están aquí y no solo en [05 §16](05-codigo-muerto-y-roto.md):

  - **Imprimía menos de lo que contaba** —una respuesta de archivo vacía el
    buffer de salida al enviarse—, y las seis líneas que se perdían eran siempre
    las primeras. O sea las mismas seis en las medidas de la §14 y la §15.
  - **Pedía en el año equivocado**, porque el login reescribe `users.periodo_id`
    y el barrido elegía los identificadores con la fila leída antes de entrar.
    Es la trampa que `tokenDelPersonalDe()` lleva documentada desde la P1.
  - **36 rutas no se estaban midiendo.** El seed tiene dos grupos y el sujeto de
    siempre está matriculado en los dos, así que no había ningún grupo ajeno y
    boletines, planillas, observador y certificados **de otro grupo** se pedían
    con un cero. Arreglado eligiendo un sujeto que deje uno libre: las 34 de
    grupo dan 403. Para el acudiente no hay sujeto posible en este seed, y el
    barrido lo imprime en vez de callárselo.

  Y lo que se aprendió, que es lo que hay que llevarse: **hay una tercera
  categoría que este detector no mide**. Su criterio de fuga son los datos
  personales y las escrituras, y entre las dos cabe *lo del colegio que no es de
  nadie en particular*: `unidades/trashed` le devolvió a un alumno 29 KB con la
  papelera académica del colegio y el barrido la vio pasar. De ahí salieron un
  GET que escribía, cuatro papeleras sin guard —dos de ellas devolviendo alumnos
  borrados con su documento— y un 500 que era un 404. Todo en
  [05 §16](05-codigo-muerto-y-roto.md).

- **La hermana que se quedó sin el guard, hecho el 20 ago 2026.** Los cinco
  agujeros de la §16 tenían la misma forma —ser la única de su familia sin
  guard—, y eso es mecánico. Está en `AutorizacionTest` como test y no como
  herramienta, por lo mismo que el candado de los identificadores: así corre con
  los demás. Las excepciones legítimas van en `EXCEPCIONES_DE_FAMILIA` con su
  motivo, y un segundo test impide que la lista solo crezca.

  Las 27 que marcó estaban todas explicadas. **Lo que no lo estaba fue lo que
  enseñó su gemelo**, el snapshot `guard-por-familia`: de 95 familias, doce no
  tienen ningún guard, y por eso la regla no las mira — no hay hermana con la que
  comparar. Nueve son correctas; las otras tres eran
  [05 §17](05-codigo-muerto-y-roto.md):

  - **`promovidos/calcular-grupo` escribe `matriculas.promovido`** —si el alumno
    pasa el año— de cualquier grupo nombrado en el cuerpo, y devuelve 331 KB con
    sus notas. Es lo más caro de toda la serie, y el barrido no podía verlo
    porque golpea con el cuerpo vacío.
  - **La cartera entera**, que no mira el token ni una vez: los deudores del
    colegio con su documento y su deuda, cualquier grupo, y el Excel de deudores
    sin parámetros. El barrido falló aquí por sus dos mitades a la vez — dos
    piden por el cuerpo y la tercera devuelve un `xlsx`.
  - **`buscar/por-nombre` y `buscar/por-apellido`**, los otros dos buscadores de
    la §11.3: 49 compañeros a cualquier alumno. Y con el texto **interpolado en
    la consulta** — que no hace falta un atacante para verlo, basta un alumno
    apellidado O'Brien: 500.

  Lo que queda de esto para lo que venga: **cada herramienta de la serie
  encuentra lo que las anteriores no pueden ver.** El inventario mira la
  petición, el barrido el resultado, y ésta la forma de la tabla de rutas — que
  es lo único que ve las que no reciben identificador y las que solo escriben
  con el cuerpo lleno.

- **Rector**, configurado y sin correr: por carpeta y revisando cada diff.
- **FormRequests**: hay 2 validaciones en 32.000 líneas. Cada endpoint que se
  toque estrena la suya.
- **Renombrar los métodos** `getIndex` → `index`. Cosmético, y ahora hay tests
  que lo harían seguro.
- **`User::$nota_minima_aceptada`**, la última estática mutable. La leen 26
  sitios del cálculo de notas, que el §5 del plan protege.

- **El cuerpo lleno, hecho el 20 ago 2026.** Lo que la entrada anterior dejaba
  pendiente. El barrido manda ahora los mismos identificadores ajenos con los
  nombres que usan los cuerpos, todos a la vez. Dos efectos inmediatos:
  `images-users/move-img-to-me` **dejó** de aparecer —con el `img_id` ajeno en el
  cuerpo, `persona.propia` lo corta; antes pasaba porque el cuerpo iba vacío y el
  guard entendía «lo mío»— y salió el módulo de votaciones entero.

  De sus cinco familias, **la única ruta con guard era `destroy/{id}` en todas**,
  que es el patrón de la §15 sin una variación. Un alumno creaba votaciones,
  creaba y editaba los cargos, inscribía de candidato a cualquier `user_id`, y
  leía el censo con los datos personales de todos y **a quién votó cada uno**, más
  los 52 KB de `VtVoto::all()`. Catorce cerradas — [05 §18](05-codigo-muerto-y-roto.md).

  Lo que hay que recordar de esta pasada es **cómo se comprobó que no se rompía
  nada**, porque cerrar catorce rutas de un módulo a ojo es dejar sin elecciones a
  dieciséis colegios. Primero el front: `VotacionesInicioCtrl` manda a un alumno o
  acudiente a la pantalla de votar y a admin o profesor a la de configuración, y
  la de votar llama a dos endpoints. **Pero eso es leer.** Así que hay además un
  test que monta una elección de verdad y vota con un token de alumno de punta a
  punta, comprobando la fila en `vt_votos` y no el código de respuesta. Se
  verificó al revés de las dos maneras de romperlo, cerrando `votos/store` y
  cerrando `en-accion-inscrito`: falla con cada una.

  Y el candado de la §17 se ganó el sueldo el mismo día: al cerrar el módulo, las
  tres del flujo de votar pasaron a ser «la que se quedó sola» y el test falló.
  Es para lo que está — no dice que estén mal, dice que hay que decidir.

  Lo que sigue sin cubrir: el barrido manda **todas** las claves a la vez, así que
  una ruta que lea dos recibe una combinación que puede no casar, y ahí el vacío
  vuelve a no probar nada. Y sigue sin haber forma estática de saber qué claves
  lee un controlador — la lista de nombres del cuerpo se amplía a mano, como el
  mapa de la URL.

- **Las dos familias que quedaban, hechas el 20 ago 2026.** El snapshot por
  familia decía que doce no tenían ningún guard y que nueve estaban bien. **Dos
  de esas nueve no lo estaban** — [05 §19](05-codigo-muerto-y-roto.md):

  - **`POST importar/algo/{year}` es el importador vivo y no llevaba guard.** Un
    alumno sube una hoja y la importación se ejecuta entera a su nombre:
    `completada`, 37 filas, y 37 alumnos, 37 matrículas, 44 acudientes y 44
    parentescos escritos. Es la escritura más grande que ha alcanzado un token de
    familia en toda la serie. Que no crecieran los alumnos es mérito de la
    idempotencia por documento del §1 de este documento, no del guard.
  - **`GET folios/iniciar` numera de golpe las matrículas del año** y no llama a
    `fromToken()` ni una vez.

  Lo que hay que llevarse, porque es lo que decide dónde mirar después:

  - **El barrido mide lo que sabe construir.** Tres sabores del mismo límite, ya
    con nombre: el cuerpo vacío (§17), el `xlsx` de salida que no sabe leer
    (§17), y el archivo de entrada que no sabe mandar (§19). Si mañana aparece un
    endpoint que solo actúa con una cabecera concreta, será el cuarto.
  - **El seed vacío tapa hallazgos, y ya van cuatro**: `unidades_por_defecto`,
    los alumnos borrados, `pazysalvo` y ahora los folios. `folios/iniciar` salía
    en el barrido desde la primera pasada **escribiendo**, y se dejó pasar porque
    afectaba a cero filas. Una consulta que se ejecuta sobre cero filas se
    parece demasiado a una que no se ejecuta.

- **El cuerpo entero, hecho el 20 ago 2026.** La §18 mandaba veinte claves
  escritas a mano; los controladores leen **setenta y ocho**. Y con eso cae la
  frase que aquella dejó escrita —«no hay forma estática de saber qué claves lee
  un controlador»—: no es exacta, pero encuentra la que alguien añada
  escribiéndola, que es como se añaden. El barrido tiene ya por el lado del
  cuerpo el mismo candado que tenía por el de la URL.

  Lo que salió — [05 §20](05-codigo-muerto-y-roto.md): **un alumno respondía el
  examen de otro.** `mis-actividades/seleccionar-opcion` recibe el
  `actividad_resuelta_id` por el cuerpo y no miraba de quién es, así que borraba
  la respuesta del otro y escribía la suya; y `finalizar-actividad` le cerraba el
  examen en mitad de la prueba. Ninguna de las dos puede llevar `auth.personal`
  —responder un examen es lo que hace un alumno— ni `persona.propia` —el
  identificador nombra un intento, no una persona—, así que la comprobación va
  dentro del controlador. Es la forma de la §13.2 vista del revés: allí el guard
  estaba puesto y no reconocía el nombre; aquí no hay nombre que reconocer.

- **El `{id}`, hecho el 20 ago 2026.** Cerrado el cuerpo, quedaba mal medido el
  otro lado de la URL: **85 rutas llevan `{id}` y el barrido les mandaba a las
  ochenta y cinco el mismo número**, el `users.id` del superusuario, porque el
  mapa resuelve por nombre de parámetro y `{id}` es un nombre solo. Contra
  `perfiles/*` era el correcto; contra las otras setenta y tantas era un id de
  otra tabla, y **un 404 por «esa fila no está» se lee igual que un guard que
  funciona**. Casi todas son `DELETE` y `PUT`. Ver [05 §21](05-codigo-muerto-y-roto.md).

  Ahora el `{id}` se resuelve contra la tabla que la ruta nombra de verdad, que
  no es la que dice la URL: `boletines2/destroy/{id}` y las tres de `editnota`
  operan sobre **alumnos**, y `definitivas_periodos` borra de `notas_finales`. Y
  **la papelera se detecta leyendo el método, no el nombre**: `years/destroy/{id}`
  hace `forceDelete()` sobre `onlyTrashed()` —el borrado que arrastra 59 tablas—
  y `years/delete/{id}` es el que manda a la papelera. Con el nombre por
  criterio, la peligrosa se habría golpeado con un año vivo. Es el tercer candado
  del barrido contra encogerse en silencio, después del de la URL y el del cuerpo.

  Lo que salió: **se podía pedir que borraran la foto de otro.** Borrar una imagen
  se pide por dos rutas y son la misma operación; `images-users/destroy/{id}`
  lleva `persona.propia:imagen_id` desde la §13.1 y `myimages/destroy/{id}` —la
  que usan las familias, porque se llama «mis imágenes»— no llevaba ninguna. Con
  una imagen ajena no la borra: el controlador solo mira `created_by` —quién la
  subió— y nunca `user_id` —de quién es—, así que cae en la rama de la petición de
  cambio y deja el id ajeno escrito. Desde ahí lo ejecuta un clic de quien revisa
  peticiones. Alcanzaba también a las imágenes sin dueño, que son las del colegio:
  el logo del año, la firma de un profesor.

  Aquí el arreglo **sí** es el guard y no una comprobación dentro, al revés que el
  del examen: el identificador nombra una imagen y `ExigirPersonaPropia` ya sabe
  de quién es una imagen. Es la misma línea que lleva su ruta hermana.

  Y las otras setenta y dos aguantaron — que no es un hallazgo, pero antes no
  estaba medido.

- **El control, hecho el 20 ago 2026 — y es el hallazgo de método de toda la
  serie.** Las §14 a §21 se apoyan todas en leer «vacío» como «cerrado», y esa
  lectura **es falsa la mitad de las veces**: un silencio puede ser el guard o
  puede ser que los identificadores no nombren nada. Ahora las mudas se repiten con
  un token de superusuario —sin guard que lo pare, mismos identificadores, mismo
  cuerpo—; si tampoco saca nada, el silencio de la primera pasada no prueba nada.
  Cada control va en su savepoint y se deshace, porque son escrituras de verdad
  hechas por quien sí puede hacerlas. Ver [05 §22](05-codigo-muerto-y-roto.md).

  **59 de 106.** Y no son las que dan 403 —ésas ni entran, un 403 sí es un juicio—:
  son rutas por las que un token de alumno pasa y sobre las que el barrido no tenía
  nada que decir. Tres causas, que estaban nombradas por separado sin saber que
  eran la misma: el **desajuste de año** —el sujeto trabaja en 2025 y el único
  grupo ajeno del seed es de 2024, así que las 36 rutas que la §16 dio por cerradas
  pueden no haberse medido nunca—, las tablas vacías de §21.5, y el cuerpo que no
  casa que la §18 dejó escrito sin número.

  Lo que salió del primer vistazo a las cuarenta que no eran límites ya conocidos:

  - **`POST perfiles/store` no crea un perfil: crea un grupo.** Un alumno creaba un
    grupo del colegio en el año en curso; medido, de 2 a 3, con un 201. `PerfilesApi.ts`
    ya tenía anotado que cinco métodos de ese controlador operan sobre grupo y no
    sobre persona; ésta es la sexta y la única que escribe, y las otras cuatro ya
    llevaban `auth.personal`. La §17 otra vez. Invisible al barrido porque lee
    `Request::input('titular')['id']` y el barrido manda `titular_id` plano: el
    índice sobre `null` lanza y el `catch` lo convierte en 422.
  - **`PUT publicaciones/guardar-edicion` reescribe cualquier publicación**, y no
    solo el texto: también a quién se le enseña. La §17 por segunda vez en la misma
    pasada — `putDelete` y `putRestaurar` ya llevaban la comprobación y la edición
    se quedó fuera **porque nombra la publicación `id` y no `publi_id`**. Invisible
    porque sin `publi_para` la petición muere en 500 antes del `UPDATE`.
  - Y una que se queda rota a propósito: **`publicaciones/borrar-comentario` tiene
    sintaxis de JavaScript en PHP** —`$user.persona_id==comentario.persona_id`—, así
    que con el `||` en corto un superusuario borra cualquier comentario y todos los
    demás reciben un 500. No es un agujero, es un botón que no funciona; arreglarlo
    **enciende** una función en los dieciséis colegios, que es decisión y no arreglo.

- **El cuerpo anidado, hecho el 20 ago 2026.** Las 57 no juzgables de la §22
  estaban infladas: el bucle salta los 403 por ser la respuesta correcta, pero
  este legacy rechaza con **400** —y con 401 y 422—, y un 4xx es un juicio igual.
  Descontadas, **57 pasan a 14**; 47 de las mudas eran rechazos con el código
  equivocado, que se quedan como están porque la regla es que el legacy no se
  toca. Con catorce se mira una por una, y salió la causa común.

  **El barrido manda números planos y esos controladores leen objetos**:
  `Request::input('titular')['id']`, `$acu['nombres']`, `$grupo_actual['id']`. El
  índice sobre un `int` lanza, la ruta responde 500 y desde fuera se ve una que no
  hace nada. Es la cuarta cara del mismo límite —tras el cuerpo vacío, el `xlsx`
  de salida y el archivo de entrada— y la que más ha escondido. Ahora se golpea con
  **las dos formas**, porque la misma clave se lee de las dos maneras en sitios
  distintos. Cuarto candado, y señaló al escribirlo `encabezado_img_id` y
  `piepagina_img_id`, que se leen como objeto aunque se llamen `_id`.

  Cinco rutas salieron, y **las cinco son la §17 otra vez** — ver
  [05 §23](05-codigo-muerto-y-roto.md):

  - **`POST perfiles/store` no crea un perfil: crea un grupo.** De 2 a 3 con un
    token de alumno.
  - **`PUT publicaciones/guardar-edicion` reescribe cualquier publicación**, y no
    solo el texto: también a quién se le enseña.
  - **`POST acudientes/crear-usuario` crea cuentas** de tipo Acudiente con
    `Hash::make('123456')` y **reapunta `acudientes.user_id`**: si ese acudiente ya
    tenía cuenta, se queda fuera y entra una cuya contraseña conoce quien la pidió.
    Y un acudiente ve lo completo de sus acudidos. La más seria de las cinco.
  - **`PUT acudientes/datos`** devuelve los acudientes del grupo que le nombren con
    documento, teléfono, email y dirección — y la consulta filtra por grupo y **no
    por año**, así que vale cualquiera del colegio.
  - **`PUT matriculas/alumnos-grado-anterior`** devuelve el grupo entero con
    `fecha_nac`, `celular`, `direccion` y `religion`. Sus tres hermanas
    —`matriculas/alumnos-con-grado-anterior` y las dos de `prematriculas`— llevan
    `auth.personal` desde siempre.

  **Y esta última dice algo del candado de la §17 que hay que apuntar:** ese
  candado comprueba que no quede una sola ruta sin guard **en su familia**, y la
  familia `matriculas` tiene muchas con él, así que la que faltaba no estaba sola.
  Sí lo estaba entre sus **hermanas de operación** —el mismo nombre de método en
  cuatro controladores, tres con guard—. Son dos preguntas distintas y hoy solo se
  hace la primera. **La segunda ya tiene candado** —hecho a continuación, el mismo
  día—: agrupa por `Controlador@metodo` en vez de por prefijo de URL.

  Y al escribirlo salió el detalle que lo hace funcionar: **el umbral no puede ser
  el mismo**. En el de familia hacen falta dos hermanas con guard porque compartir
  prefijo es una relación floja; aquí basta una, porque compartir nombre de método
  significa que la operación está copiada y pegada en dos controladores.
  `putAlumnosGradoAnterior` existe exactamente dos veces, así que con el umbral de
  familia el caso que motivó el candado se le habría escapado — comprobado
  quitándole el guard a la ruta: con dos pasa, con uno falla y la nombra.

- **Las once sin juzgar, miradas una por una el 20 ago 2026.** Nueve ya tenían
  sitio —una pública de pre-login, dos que esperan decisión, dos rotas conocidas,
  el muro de publicaciones y las tres del flujo de votar—. Las dos de actividades
  estaban mudas porque `ws_actividades` está vacía, y se midieron **montando la
  actividad**, que es justo la regla que salió al partir la decisión del seed.

  Salió una: **`PUT respuestas/actividad` es la pantalla de corregir del profesor**
  —por cada grupo al que se compartió la actividad, todos sus alumnos con lo que
  contestaron, su `puntaje_manual` y su respuesta a cada pregunta— y no llevaba
  guard. `panel.respuestas` tiene dos entradas en el front y las dos son del autor;
  `actividades/datos`, que abre esa lista, lleva `auth.personal` desde siempre.

  **Y el comentario de la ruta decía lo contrario** —«el lado del alumno es
  `mis-actividades/*` y `respuestas/actividad`»—, que es de donde salió que se
  quedara abierta. Corregido con el guard.

  Se queda rota, y documentada, la otra rama del mismo método: para una actividad
  **no compartida** hace `DB::select('')` con la consulta vacía, así que el
  profesor que abra «Ver resultados» de cualquier actividad de un solo grupo
  recibe un 500 desde que existe la pantalla. Con su test fijando el error. Ver
  [05 §24](05-codigo-muerto-y-roto.md).

  Quedan **diez sin juzgar, las diez con nombre y motivo**. Es el punto en el que
  la serie deja de encontrar por barrido: lo que queda son decisiones.

- **La decisión del seed, tomada el 20 ago 2026: partirla en dos.** El seed vacío
  llevaba seis hallazgos tapados —`unidades_por_defecto`, los alumnos borrados,
  `pazysalvo`, los folios, `ws_actividades` y trece rutas con `{id}`—, y lo que la
  volvió decidible fue ver que las trece se parten en dos mitades con costes
  distintos.

  **Las ocho de papelera van dentro del barrido, y salieron gratis.** Necesitan
  una fila con `deleted_at` puesto, y para eso no hace falta un dato nuevo: vale
  marcar una que ya está. **Preparar el sujeto no es fabricar el efecto** —lo que
  se mide sigue siendo si el token restaura la fila de otro—, así que la
  objeción de «poner al barrido a fabricar lo vuelve turbio» no se le aplica: es
  lo mismo que ya hace al elegir a quién se le da el token. Se presta y se
  devuelve en cuanto se golpea la ruta, porque el seed tiene dos grupos y dejar
  borrado el único ajeno mediría mal otras treinta y seis. Ninguna dio nada, que
  ahora está medido y antes no.

  **Las cinco restantes quedan anotadas y sin hacer, y por un motivo que no se
  sabía al decidir:** el generador del seed no puede traerlas porque **no están
  solo vacías en el seed, están vacías en la base de desarrollo** —las once tablas
  de la familia—. No es ampliar el generador, es fabricar, y eso rompe su
  contrato: «una rebanada de la base real, determinista a partir del id».
  Medido lo que costaría: solo dos snapshots tocan esa familia y los dos están ya
  en `huecos-del-seed.json`, y la forma del examen la escribió la §20. Y medido lo
  que compraría: **nada**, porque las cinco llevan `auth.personal` o comprueban
  dentro. Honestidad de la medición, no hallazgos. Ver [05 §21.5](05-codigo-muerto-y-roto.md).

  El patrón que queda escrito, porque va a volver: **el seed copia un grupo y sus
  datos, y todo lo que un colegio acumula alrededor —papeleras, deudas, exámenes,
  plantillas— llega vacío**, así que un `[]` no distingue «cerrado» de «no había
  nada». La regla con la que se resolvió: si lo que falta es **estado** de una
  fila que ya existe, lo prepara quien mide; si lo que falta es la **fila**, se
  monta en el test que la necesita — y llevarla al seed es una decisión aparte,
  que se toma cuando compre hallazgos y no solo cobertura.

---

## 7. «Restaurar» contesta tres cosas distintas, y una está mal escrita — 24 ago 2026

**Espera una decisión, y su despliegue va al revés del habitual.** Lo levantó
`myvc-front-12` avisando de una errata —`Retaurada`, sin la `s`— y midiendo que
está **replicada en tres controladores**, así que quien la corrija arreglará uno
y dejará dos. Al ir a mirarlo salió que la errata es la punta.

**Los diez endpoints que restauran de la papelera no contestan lo mismo:**

| Qué devuelve | Cuántos | Dónde |
|---|---|---|
| **el objeto restaurado** | **6** | `years`, `grupos`, `alumnos`, `editnota`, `profesores`, `perfiles` |
| `'Retaurada'` *(mal escrito)* | **3** | `asignaturas/restaurar`, `unidades/restore/{id}`, `subunidades/restore/{id}` |
| `'Restaurada'` *(bien escrito)* | **1** | `publicaciones/restaurar` |

Los diez están **enrutados y son alcanzables**; ninguno es código muerto.

O sea que la misma operación contesta **un objeto, una cadena bien escrita o una
cadena mal escrita** según por dónde entres, y **la errata es lo de menos**: un
cliente no puede tratar «restaurar» de forma uniforme aunque se corrija la `s`.
Que tres de cada cuatro cadenas estén mal escritas es además la señal de que se
copiaron entre sí, no de que alguien tecleara mal una vez.

### Por qué no se toca sin decidirlo, y por qué el orden se invierte

**Hay pantallas del front que sólo pueden distinguir dos respuestas por esa
palabra.** Corregir la cadena en el backend **rompe esas pantallas** en cuanto se
despliegue, y son dieciséis despliegues escalonados: durante días convivirían
colegios que dicen `Retaurada` con colegios que dicen `Restaurada`.

Así que **el orden es el contrario del de siempre**:

1. **primero el front**, que pase a aceptar las dos formas —o a no mirar la
   cadena—, publicado y desplegado;
2. **y el backend detrás**, cuando ya no haya nadie que dependa de la errata.

Es el único caso conocido en el que la regla «el backend delante» no vale. Todo lo
demás en este proyecto va al revés porque el backend **añade** capacidades; aquí
**cambia una respuesta que alguien ya lee**, y eso es una rotura, no una mejora.

### Las tres salidas

1. **Corregir sólo la `s`** en los tres sitios. Barato, y deja la incoherencia de
   fondo —seis devuelven objeto y cuatro cadena— exactamente igual.
2. **Unificar a la cadena correcta** en los cuatro que devuelven cadena, y dejar
   los seis del objeto como están. Es lo que hace que «restaurar» tenga dos
   contratos en vez de tres.
3. **Unificar los diez al objeto restaurado.** Es el contrato bueno —el cliente
   sabe qué fila volvió— y el que más rompe: cuatro pantallas a la vez.

**Sin decisión, no se toca ninguno.** Y quien la tome debe saber que corregir
sólo uno de los tres `Retaurada` es la peor opción posible: deja la misma
operación contestando dos cadenas distintas **dentro del mismo colegio**.

---

## 8. El año se queda viejo mientras la sesión sigue abierta — 24 ago 2026

> **Este apartado se escribió mal y se reescribió el mismo día.** La primera
> versión decía que *«un acudiente creado en 2018 sigue en 2018 para siempre»* y
> que **sus boletines y sus definitivas son las de hace ocho años**. Eso es falso,
> lo desmontó `8myvc-d0` con el código delante, y **cómo se llegó a afirmarlo está
> al final** — porque el error de método vale más que el hallazgo.

### Lo que es cierto: 992 filas viejas, en reposo

De los 1.000 acudientes, **992 tienen `users.periodo_id = 1`** —el periodo 1 de
2018, la tanda con la que se cargó el colegio— y los otros ocho apuntan a 2019,
2020 y 2021. **Ninguno al año activo.** Los alumnos también están repartidos por
años viejos: sólo 264 de 1.283 en 2025.

### Lo que NO se sigue de ahí: que alguien vea 2018

**`Login::ponerEnElPeriodoActual()` repara esa columna en cada login**
(`app/Services/Login.php:165`, llamada desde `entrar()`). Mira `years.actual`, y
si el año del usuario no es ése, hace `UPDATE users SET periodo_id=?` **antes de
emitir el token**. No hay `switch` por tipo ni ningún `FUERA`: vale igual para un
acudiente que para un profesor.

O sea que esas 992 filas están viejas **en reposo**, y **con ese id no llega
ninguna petición real**: para tener token hay que haber pasado por el login.

### Lo que sí queda, y es real — y no es de acudientes

**El colegio pasa de año y la familia no ha vuelto a entrar.**
`ContextoDeUsuario` lee `users.periodo_id` **en cada petición**, y la reparación
es sólo del login: **nada lo mueve con la sesión abierta.** Ahí
`disciplina/mis-fichas` devolvía **404 sobre una ficha que existe**, justo cuando
la familia abre la app a ver el curso nuevo.

Y **es de cualquier sesión que sobreviva al cambio de año**, no de un tipo de
usuario. `8myvc-d0` lo arregló dentro de su endpoint en `7ce66a9` —el año sale del
alumno, que es de quien es la ficha—, verificado por los dos lados y **con el
token tomado antes de mover el periodo**, que es lo único que reproduce «la sesión
sigue abierta».

**Queda por decidir si eso se arregla en general o endpoint a endpoint.** En
general significaría que el contexto deje de confiar en `users.periodo_id` cuando
`years.actual` dice otra cosa, y eso toca a los cuatro tipos de usuario.

### La puerta cerrada, que sí es una decisión y sigue en pie

`years/useractive/{year_id}` lleva `auth.personal`, y `ExigirPersonal` declara
`FUERA = ['Alumno', 'Acudiente']`, citando una decisión de Joseth del 18 ago 2026.
Así que **un alumno o un acudiente no puede cambiarse el año** — y el desplegable
se les pinta igual: `GET years` no lleva middleware y el selector de
`panel.html:38-40` no tiene condición de visibilidad, así que lo abren, eligen, y
reciben «No se pudo cambiar el año. Problema» (`PanelCtrl:161`).

**Eso es lo único que queda para el front** y es cosmético comparado con lo de
arriba: ocultar el desplegable a quien no puede usarlo. Está en el
`PREGUNTAS-MANANA.md` de `myvc_front`.

---

### Cómo se llegó a afirmar lo falso, que es lo que hay que leer

Tres pasos, y **cada uno corrigiendo al anterior en la dirección equivocada**:

1. **Se reprodujo con un test y dio 2025** — o sea, lo correcto. Pero el resultado
   contradecía el hallazgo, así que se buscó una explicación y se encontró una
   plausible: *«la base de tests tiene datos sembrados distintos»*. **Se descartó
   la medición buena por incómoda.**
2. **Se «corrigió» midiendo el SQL crudo contra la copia de producción**, que dio
   2018. Ese número es **exacto** — y contesta otra pregunta: cuál es el estado
   **en reposo**, no qué ve una petición real, que siempre llega después de un
   login.
3. **Y se publicó como decisión para Joseth**, con la autoridad de venir de dos
   mediciones.

Es la [8c y la 8d de `las-cegueras.md`](noche-2026-08-23/las-cegueras.md)
**encadenadas**: medir bien en el sitio equivocado, y luego contar bien una cosa
distinta de la que se afirma. Y lo que faltaba era una sola pregunta:
**¿por dónde llega de verdad esa petición?** El login estaba en el camino y no se
miró.

> Lo cazó `8myvc-d0` **revirtiendo su propio arreglo para ver si el test se ponía
> rojo**. No se puso. *«Un test que pasa con el código roto no prueba nada, y ahí
> estaba la pista: algo reparaba el periodo por el camino.»*


---

## 9. El personal ve la ficha de cualquiera por su nombre de usuario — 24 ago 2026

**Esto ya se decidió el 21 ago y se vuelve a abrir con una pregunta más
estrecha.** No es un hallazgo nuevo ni una re-litigación de aquello: es que la
decisión contestó *«cómo cerramos el agujero de las familias»* y **no llegó a
contestar** *«qué debe ver un docente»*.

### Lo medido, entrando de verdad con cada rol

Lo midió `myvc-front-ce` el 24 ago con las tres sesiones abiertas en el navegador,
que es la primera vez que se prueba esta ruta con alguien que no sea el
administrador:

```
GET perfiles/username/administrador
  como DOCENTE (DANIEL1)      -> 200
  como ALUMNO  (JuanEsteban2) -> 403
```

El 200 devuelve, **de la persona consultada**: `documento` y `tipo_doc`,
**`email_restore`** —el correo al que llega el enlace de reseteo—,
`email_persona`, `fecha_nac`, **`deuda`**, `pazysalvo`, `ciudad_nac`, `ciudad_doc`
y las imágenes. Y se ve en pantalla: tecleando `/panel/perfil/administrador` sale
la ficha del administrador con su correo de recuperación.

### Por qué pasa, y es exactamente lo que se decidió

`ExigirPersonaPropia` empieza así (`:81`):

```php
if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') {
    return $next($request);
}
```

**El guard sólo estrecha a familias.** Es literal la decisión del 21 ago —de las
tres salidas de la [§14.4](05-codigo-muerto-y-roto.md), Joseth eligió *«que el
guard resuelva el username»*, y el commit lo dejó escrito: *«lo único que cambia
es que una familia ya no alcanza a nadie que no sea suyo»*—. La tercera salida,
**recortar las columnas de la respuesta, no se tomó.**

Así que el 403 al alumno es el arreglo funcionando, y el 200 al docente es el
estado que se decidió conservar.

### Lo que la decisión no llegó a mirar

**Que un docente pueda leer el correo de recuperación del administrador.** La
§14.4 nombraba los campos, pero la pregunta que se contestó era sobre la puerta y
no sobre la respuesta.

Y conviene decir lo que **no** es, para no inflarlo: el reseteo manda el enlace
**a ese correo** (`LoginController::postRecuperarClave` busca por `email` y
escribe a esa dirección), así que **saberlo no da acceso a nada**. Es fuga de
información —útil para dirigir un intento de suplantación fuera del sistema—, no
una vía de entrada.

### Cuántas cuentas pasan de verdad, y eso acota la decisión

Contado por `myvc-front-ce` el 24 ago y **vuelto a contar aquí con un filtro que
faltaba**:

| | Cuentas | |
|---|---|---|
| `Alumno` · `Acudiente` | 1.283 · 1.000 | **las estrecha el guard** |
| `Profesor` **activos** | **23** | pasan |
| `Usuario` | **20** | pasan |

**Pasan 43, no 71.** El primer conteo dio 51 docentes, que es el total de la
tabla; **28 están inactivos** y no pueden llegar: `Login::entrar` corta en
`:74` y `Sesion` vuelve a comprobarlo en `:323` para un token ya emitido. Es la
misma familia de las [cegueras](noche-2026-08-23/las-cegueras.md): el número era
correcto y contaba una población que no es la que pasa por la puerta.

Los 20 `Usuario`, por rol:

```
Admin                10
(SIN ROL)             5     <- ni una fila en `role_user`
Psicólogo             4
Coord disciplinario   1
Enfermero             1
```

**Y el dato que más acota:** de esas 43, **sólo 10 son Admin**. Para las otras 33
—docentes, psicólogos, enfermería, coordinación y las cinco sin rol— **no hay
ninguna pantalla que lleve a la ficha de otro**: el docente llega por su propio
perfil, y los demás roles no tienen entrada de menú a `perfil/:username`. O sea
que **para 33 de 43 cuentas ese 200 no lo pide ninguna pantalla: sólo se alcanza
tecleando la dirección.**

Eso hace la salida (a) —recortar columnas— mucho más barata de lo que parecía: lo
que se recorte no se lo quita a ninguna pantalla viva salvo, quizá, a las diez de
Admin.

> **Las cinco sin rol son el caso que conviene mirar primero.** Pasan el guard por
> `tipo = 'Usuario'` sin que ningún rol diga qué son, así que ni siquiera se puede
> razonar sobre lo que deberían ver: **no hay nada escrito que lo diga.**

### Las salidas, y ninguna es un guard

- **(a) Recortar la respuesta**, que es la tercera salida de la §14.4 sin tomar.
  Quitar `email_restore` de esta ruta, y quizá `deuda` y `documento` para quien no
  sea superusuario. **Cambia lo que reciben los clientes**, así que va con el
  orden invertido —front delante— si alguna pantalla los pinta.
- **(b) Distinguir «sus alumnos» de «cualquiera»** para el docente. Es lo que
  `myvc_front_2` está apuntando como *pertenencia*: «este usuario, sobre lo suyo»,
  que un permiso por rol no sabe expresar. Es el arreglo correcto y el más caro.
- **(c) Dejarlo como está**, y entonces conviene que quede escrito **que se miró
  con el dato delante** y no por omisión.

> **Un guard en el front no vale**, y es lo que hace que esto sea del backend:
> evitaría abrir la pantalla, pero **el endpoint seguiría contestando 200 a quien
> llame por su cuenta** — y `app/`, la que corre hoy en los dieciséis colegios,
> llama a los mismos sitios.

> **De paso, lo que este hallazgo dice del método:** con la cuenta de
> `administrador` no se veía nada de esto, porque el administrador puede verlo
> todo. En la primera vuelta probando con **un docente, un alumno y un acudiente
> de verdad** salieron tres cosas en una tarde. **Verificar con el usuario más
> poderoso es verificar la mitad** — la hermana de «verificar con el caso bonito».

---

## 9.b El informe de notas perdidas manda el móvil y el correo de cada alumno — 24 ago 2026

Salió al recortar `contratos` y **no es lo mismo**, por eso va aparte: aquella
ruta la alcanza un alumno; ésta pide `auth.personal`, o sea las 43 cuentas.

`NotasPerdidasController` monta sus tres listas con consultas que traen
`a.celular`, `a.email` y `a.fecha_nac` **de cada alumno**, y las incrusta en la
respuesta. Se ve en `Snapshots/muestreo-notas-perdidas-*.json`.

**Lo que hay que decidir es si un informe de notas perdidas necesita el móvil de
cada alumno.** Si es para llamar a la familia del que va perdiendo, sí y es su
razón de ser; si es para imprimir un listado, no. **Es una pregunta de para qué
sirve la pantalla, no de código** — quitarlo son tres columnas de tres `SELECT`.

> **De paso, ese mismo método incrustaba la fila entera del docente** —era el
> mismo `Profesor::contratos()` de la [§10](#10-get-apicontratos-entrega-la-ficha-personal-de-los-docentes-a-un-alumno--24-ago-2026)—
> y eso **sí quedó arreglado** al recortarla. Lo que queda es lo de los alumnos.
>
> Y una lección de método que costó un susto: al comprobar quién usaba
> `contratos()` se vio que este controlador **sólo lee `profesor_id`**, y era
> cierto — pero **empujaba el objeto entero a la respuesta**. *Leer* un campo y
> *devolver* una fila son cosas distintas, y buscar lo primero no encuentra lo
> segundo.

---

## 10. `GET api/contratos` entrega la ficha personal de los docentes a un alumno — 24 ago 2026

**Está vivo hoy, en los dieciséis colegios.** Es la primera de las tres de la
[§14.4](05-codigo-muerto-y-roto.md) que se prueba **con un token de alumno de
verdad**, y contesta 200.

### Lo medido

`myvc-front-ce`, el 24 ago, con las tres sesiones abiertas:

```
GET api/contratos           ALUMNO 200 · ACUDIENTE 200 · DOCENTE 200    16 filas
GET api/perfiles/usernames  ALUMNO 200 · ACUDIENTE 200 · DOCENTE 200  2.355 filas
```

`contratos` no lleva más guard que `auth.token` (`routes/api/estructura.php:79`) y
devuelve **por cada uno de los 16 docentes**:

```
tipo_doc · num_doc · fecha_nac · ciudad_nac · ciudad_doc · estado_civil · titulo
barrio · direccion · telefono · celular · facebook · email · username · email_usu
tipo_profesor · is_superuser · contrato_id · year_id
```

**Un alumno de noveno se baja el documento de identidad, el domicilio, el barrio,
el teléfono fijo y el móvil de sus dieciséis profesores en una sola llamada.** Y
el `is_superuser` de cada uno.

> **Esto no es de la misma clase que la §9.** Aquella es fuga de información útil
> fuera del sistema; ésta es **el domicilio y el teléfono de un empleado en manos
> de un menor**, sin que nadie tenga que pedir nada ni saber a quién nombrar: la
> lista viene entera.

### Por qué se quedó abierta, y qué ha cambiado

La §14.4 la dejó con el diagnóstico correcto —*«sólo la usa para pasar de un id a
un nombre; lo que hay que recortar es la respuesta, no la puerta»*— y con el
motivo de no tocarla: **recortar cambia el contrato de los dieciséis colegios a la
vez, porque la app de Flutter es una sola.**

Lo que cambia hoy es que **ese coste se puede medir en vez de suponerse**:

**Los seis consumidores de `contratos()` en `myvc_front` leen sólo nombre e id.**
Comprobado fichero a fichero: `sidebarMenu`, `GruposEditCtrl`, `GradosConfig`,
`GruposNewCtrl`, `DisciplinaCtrl` y `RequisitosCtrl`. **Ninguno toca `num_doc`,
`direccion`, `telefono`, `celular`, `estado_civil`, `barrio` ni `facebook`.**

> **Y un aviso de método, porque casi lo mido mal:** el primer intento contó esos
> campos **por todo el repositorio** y dio treinta y tantos usos, o sea «el front
> los necesita». Era la [8c](noche-2026-08-23/las-cegueras.md): contaba
> `profesor.direccion` **de cualquier origen**, no de `contratos`. Acotado a los
> ficheros que llaman a `contratos()`, **cero**.

Falta el mismo conteo en `myvc_flutter`, que la §14.4 sitúa en `FaltasAlumnoScreen`,
`AsistenciaClaseScreen`, `UnidadesApi` y `NotasApi`. **Si Flutter también sólo
mapea id→nombre, el recorte deja de ser un cambio de contrato y pasa a ser quitar
campos que no lee nadie.**

### La salida propuesta

Recortar `Profesor::contratos()` a lo que se usa —`contrato_id`, `profesor_id`,
`nombres`, `apellidos`, `tipo_profesor`, `year_id`— y **dejar los datos personales
para las rutas que ya piden `auth.personal`**.

**No se toca hasta tener el número de Flutter**, por la razón de siempre: la app
es una para los dieciséis y no se puede escalonar.

### Y la combinación, que es peor que las partes

`perfiles/usernames` devuelve **2.355 nombres de usuario** —todo el colegio— a
cualquiera con sesión. Solo, es un directorio.

**Combinado con la §9 no**: un **docente** puede pedir `perfiles/username/{u}` de
cualquiera, así que **con esas dos llamadas recorre las 2.355 cuentas y se lleva
documento, correo de recuperación, fecha de nacimiento y deuda de todo el
colegio.** El directorio es lo que convierte *«puedo mirar a quien sepa nombrar»*
en *«puedo mirarlos a todos»*.

A una familia eso no se le abre: ahí el 403 del 21 ago sí funciona. **La
combinación mala es la del personal**, y por eso la §9 y ésta se deciden juntas o
la mitad no sirve.

> La §14.4 ya anotó que el único uso de `usernames` es comprobar si un nombre está
> libre, y que **ya existe** `GET api/perfiles/comprobarusername/{username}`, que
> contesta eso en 17 bytes. La salida barata está escrita desde entonces.

> **Nadie ha recorrido nada.** `ce` pidió cada lista una vez, miró los nombres de
> los campos y paró. No hay ni un valor en ningún mensaje ni en ningún documento.

---

## 11. Cualquier profesor renombra cualquier cuenta — **arreglado el 24 ago 2026**

**Estaba vivo en los dieciséis colegios.** `PUT perfiles/guardar-username/{id}` no
comprobaba a quién apunta: **cualquiera de los 51 profesores le cambiaba el nombre
de usuario a cualquier cuenta, incluida la de un superusuario.**

Y como `users.username` es UNIQUE (esquema, línea 1888), eso no es una molestia
cosmética: **deja a alguien fuera del sistema sin conocer su contraseña.** No hay
que tomar la cuenta de nadie, basta quitarle el nombre con el que entra.

### Por qué pasaba, que es lo que hay que leer

Los tres sitios, y ninguno estaba mal por sí solo:

| | |
|---|---|
| `routes/api/perfiles.php:51` | la ruta lleva `persona.propia:user_id`, o sea *parece* protegida |
| `ExigirPersonaPropia.php:80-82` | ese guard hace `if ($usuario->tipo !== 'Alumno' && $usuario->tipo !== 'Acudiente') return $next($request);` — **está escrito para atar a las familias, y a nadie más** |
| `PerfilesController.php:243` | el método resolvía `$user`… **y no lo volvía a usar**. Solo miraba que el nombre no viniera vacío |

**La forma de fallo es la de un guard que contesta una pregunta distinta de la que
el lector cree.** `persona.propia` significa *«una familia solo se toca a sí
misma»*, y se leyó como *«esta ruta está protegida»*. Es la misma familia que la
[§142](noche-2026-08-23/r.md) del CLAUDE.md: el detector detectaba bien, pero no
detectaba lo que su nombre sugiere.

### Y es la §29 sin terminar

En `putResetPassword` —el hermano, en este mismo fichero— **el mismo agujero se
cerró el 20 ago anclando la comprobación AL OBJETIVO**
(`PerfilesController.php:496`). Aquí no. Cambiarle a una cuenta el nombre con el
que entra y cambiarle la contraseña con la que entra son la misma operación sobre
la misma cuenta, y que una pidiera más que la otra solo enseñaba por dónde
colarse.

> **Lo encontró la sesión de `myvc_flutter`**, leyendo la ruta que su pantalla
> nueva de administración de cuentas iba a consumir, y **avisó en vez de
> cablearla**. Merece quedar escrito porque es el orden correcto: el cliente que
> descubre una puerta abierta no la usa y luego avisa.

### Lo que se hizo

Copiado el criterio del hermano, para que las dos digan lo mismo:

- superusuario, cualquiera;
- **el caso propio se conserva** —el guard ya dejaba que un alumno cambiara SU
  nombre de usuario, y quitarlo sería un cambio de comportamiento escondido dentro
  de un arreglo de seguridad—;
- docente con `profes_can_edit_alumnos`, **solo sobre un alumno**;
- todo lo demás, 403.

Y de paso, **un nombre ocupado es 422 y no un 500 de MySQL**: la columna es UNIQUE
y no lo comprobaba nadie, así que pedir uno ocupado reventaba con un SQLSTATE
23000 que el front enseña como error genérico.

`tests/Contrato/GuardarUsernameTest.php`, 7 casos, **todos comprobados en
negativo**: no basta con que la respuesta sea 403, se mira que el username **no
cambió**. Un guard que aborta después de escribir responde 403 igual.

### Lo que hay que hacer con esto

**Desplegarlo.** No hace falta tocar ningún cliente: hoy nadie llama a esa ruta
más que el front web con un superusuario, y la app de Flutter la tiene **escrita y
apagada** detrás de `PendientesUsuarios.cambiarUsername = false`, con una prueba
suya que falla si alguien la enciende sin darse cuenta.

---

## 12. La misma operación pide más cuanto menos alcanza — decidida la C el 24 ago 2026

Dos rutas de la misma clase, con los guardas al revés de su alcance:

| Ruta | Alcance | Pedía |
|---|---|---|
| `alumnos/cambiar-claves` | **un grupo**, ~30 alumnos | `esSuperusuario` |
| `cambiar-usuarios/poner-password-todos-alumnos` y sus tres hermanas | **el colegio**, 1.280 | `esAdministrativo` (superusuario **o** Secretario) |

**La operación de treinta pedía más que la de mil doscientos.** Las dos son
irreversibles por la misma razón —el hash anterior no se guarda en ningún sitio—,
así que no hay nada que justifique el orden.

Lo vio la sesión de `myvc_flutter` comparando las dos guardas al escribir la
pantalla que aprieta los dos botones.

### Hoy no se nota, y por eso llevaba ahí

Cero `Secretario` en la base —el rol existe desde el 21 ago y no lo tiene nadie— y
los 10 `Admin` son los mismos 10 `is_superuser` (§28.4). O sea que **los dos
criterios devuelven hoy exactamente el mismo conjunto de personas.** La asimetría
está dormida hasta que alguien cree el primer Secretario, que es justo lo que la
pantalla nueva necesita.

### La decisión: C, por alcance

Joseth, 24 ago 2026, de tres salidas planteadas:

- **(A)** las cuatro bajan a `esSuperusuario` — coherente con el patrón, pero deja
  a la pantalla sin el Secretario que se pidió para ella;
- **(B)** todas suben a `esAdministrativo` — es lo que se pidió, pero le da a un
  Secretario reescribir 1.280 contraseñas de una vez;
- **(C) por alcance: lo de un grupo, `esAdministrativo`; lo de colegio entero,
  `esSuperusuario`.** ← **elegida**

La regla que deja enunciada, y que es lo que vale más que el cambio: **el criterio
se ordena por radio de daño, no por antigüedad.** Es lo único de las tres que cabe
en una frase, y una regla que no cabe en una frase vuelve a divergir en seis meses.

### Hecha la mitad de abajo, PARADA la de arriba — y esto es lo que espera respuesta

**Hecho el 24 ago**: `alumnos/cambiar-claves` pasa a `esAdministrativo`. Nadie gana
un botón hoy (cero Secretario), y coincide además con lo que Joseth ya había dicho
el 21 ago.

**NO hecho, y a propósito: bajar las cuatro `cambiar-usuarios/*` a
`esSuperusuario`.** Al ir a tocarlas apareció que **eso reversaría una decisión
suya, tomada y anotada**:

> «Puede cambiarle la contraseña/username a los alumnos y acudientes solamente»
> — Joseth, 21 ago 2026, citado literal en
> `SecretarioTest::test_las_masivas_de_alumnos_y_acudientes_si_son_suyas`.

Y las cuatro `cambiar-usuarios/*` son **exactamente** eso: la contraseña y el
username de alumnos y acudientes. No hay ninguna de profesores ni de
administrativos. O sea que su `esAdministrativo` **no es un descuido: es esa
decisión, implementada.**

**La C se propuso sin ese dato delante y hay que volver a preguntarla.** Falló el
método, no la conclusión: el barrido miró `Autoriza`, los controladores y sus
docblocks, y **no miró los tests**, que es donde vivía la frase de Joseth. En este
repositorio una decisión suya puede estar anotada en un test y no en el código que
la aplica — es la forma de fallo de la [§8c](noche-2026-08-23/las-cegueras.md)
otra vez: medir bien sobre la población equivocada.

> Lo que **sí** era un descuido, y sigue siéndolo: el comentario de
> `CambiarUsuariosController:19-23` justifica su `esAdministrativo` diciendo
> *«mismo criterio que la papelera de grupos y profesores»*, y **esa papelera está
> en `esSuperusuario`** (`GruposController:719,749,783`;
> `ProfesoresController:528,548,580`). El guard es correcto por la decisión del 21
> ago; el precedente que cita, no. Se corrige el comentario, no el guard.

**Las dos salidas que quedan, y ninguna se toca sin que Joseth conteste:**

1. **Se queda como está** —masivas de colegio en `esAdministrativo`— y entonces C
   se enuncia distinto: no «por alcance» sino «por a quién alcanza: las cuentas de
   familias las lleva el administrativo, cualquiera sea el tamaño». Es coherente
   con el 21 ago y con la mitad ya hecha, y deja las dos rutas diciendo lo mismo,
   que era el objetivo.
2. **Se aplica C entera** y las cuatro bajan a `esSuperusuario`, revocando
   explícitamente la frase del 21 ago. Hay que decirlo en voz alta y cambiar el
   test que la cita, no dejarlo caer.

### Y dos defectos de la consulta, arreglados de paso

`alumnos/cambiar-claves` no filtraba `m.estado` ni `u.deleted_at`, así que
reescribía la contraseña de **retirados del grupo y de cuentas en la papelera**.
Que era descuido y no criterio lo dice el vecino: la masiva de colegio entero sí
lleva `u.deleted_at is null`, y `alumnos/de-grupo` sí filtra MATR/ASIS. El docblock
discutía a quién se le permite llamar y no decía una palabra sobre a quién
alcanza: **se decidió el guard y no se miró la consulta.**

Y ahora **devuelve cuántas cambió** (`{resultado, cambiadas}`), que es lo que
permite decir «cambiadas 31» en vez de un «Listo» a ciegas sobre algo
irreversible. Comprobado en los dos clientes que la llaman que el cambio de forma
no rompe nada: `myvc_front` enseña un texto fijo suyo y no mira el cuerpo
(`AlumnosCtrl.ts:454`), y `myvc_flutter` solo mira el código de estado.

Dos tests nuevos en `SecretarioTest`, los dos en negativo: que el conteo cuadra con
los matriculados del grupo, y que **a un retirado no le cambia la contraseña**.
