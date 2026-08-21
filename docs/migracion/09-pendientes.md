# Lo que queda, y lo que ya se sabe de cada cosa

Las fases 0–6 están cerradas y el plan de rendimiento también, salvo lo que hay
aquí. **Ninguna de estas es trabajo que falte por hacer sin más: cada una está
parada por algo concreto**, y lo que vale de este documento es ese algo — para
no volver a descubrirlo dentro de tres meses.

Orden: primero lo que decidió Joseth que se hará, después lo que espera una
decisión suya. Lo que se cierra **se deja aquí**, no se borra: el porqué de cada
desvío respecto al plan es justo lo que no se puede reconstruir después.

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
| Cuatro endpoints rotos desde siempre | [05 §6.5, §7.2, §8, §9.2](05-codigo-muerto-y-roto.md) | qué debe devolver cada uno; en dos de ellos, si la operación debe existir |
| La estructura de roles y permisos | [06 §4](06-autorizacion.md) | si los roles de la base se quedan y se pueblan, o se borran las cuatro tablas |
| 9 rutas de catálogo sin guard | [08](08-revision-idor.md) | a quién se abren; no exponen a nadie, pero no están decididas. Vuelto a medir el 20 ago 2026 tras [05 §16](05-codigo-muerto-y-roto.md): 12 → 11 → 9. La que salió de la lista no se decidió, **se recategorizó**: `unidades/de-asignatura-periodo` no era una lectura, escribía |
| `APP_DEBUG` en producción | [01](01-plan-seguridad.md) | comprobarlo colegio a colegio. `display_errors` de PHP está en Off, así que la mitad del riesgo ya está cubierta |
| Los correos `username@myvc.com` autogenerados | [01](01-plan-seguridad.md) | dos usuarios que compartan correo comparten reseteo de contraseña |
| `GET api/contratos` manda el expediente y el cliente solo quiere el nombre | [05 §14.4](05-codigo-muerto-y-roto.md) | qué columnas se recortan. Lo llama la app de Flutter desde pantallas de familia, así que el cambio entra en los dieciséis colegios a la vez |
| `GET api/perfiles/usernames` devuelve los 2.351 usuarios del colegio | [05 §14.4](05-codigo-muerto-y-roto.md) | apuntar `UserConfiguracionCtrl` a `comprobarusername/{username}`, que ya existe, **y desplegar el front antes** de cerrar la ruta |
| `GET api/perfiles/username/{username}` no comprueba que el usuario sea el tuyo | [05 §14.4](05-codigo-muerto-y-roto.md) | si `ExigirPersonaPropia` aprende a resolver un nombre de usuario, o si la ruta deja de aceptar parámetro y lo saca del token |
| `GET api/asignaturas/listasignaturas-alone` le da a un alumno las asignaturas del profesor con su mismo id | [05 §16.6](05-codigo-muerto-y-roto.md) | es la misma pregunta que Joseth dejó abierta en [05 §11.2](05-codigo-muerto-y-roto.md): si esa pantalla debe enseñarle sus asignaturas de verdad. Cerrarla con `auth.personal` es de una línea; decidir qué ve el alumno, no |
| `GET api/candidatos/conaspiraciones` responde 500 a alumnos y acudientes desde siempre | [05 §18.4](05-codigo-muerto-y-roto.md) | qué votación es «la suya» cuando hay varias en curso. Y que arreglarlo **enciende** para los alumnos una pantalla que hoy no funciona en los dieciséis colegios, que es una decisión y no un arreglo |

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
