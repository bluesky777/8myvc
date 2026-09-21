# Los tres informes del catálogo que quedaban

*20 sep 2026. Lo pidió `myvc_front` —sesión `myvc-front-38`— con la especificación
medida contra este código y contra el docker; el alcance lo eligió Joseth con el precio
delante. Entra en la rama `feat/los-tres-informes-del-catalogo`.*

De los seis informes nuevos de `/informes` iban tres. Los tres que faltaban —**directorio
del grupo**, **citación al acudiente** y **acta de nivelación**— no estaban sin hacer por
maquetación: **les faltaba el dato**. Esto es lo que se abrió, y cuánto costó cada cosa.

| | Qué | Precio |
|---|---|---|
| 1 | `alumno_id` en los alumnos de un acudiente | una clave |
| 2 | Las tres del acta en la consulta de recuperaciones | cuatro claves |
| 3 | `PUT informes/nivelaciones-del-grupo` | ruta nueva |
| 4 | `PUT ausencias/de-alumno` | ruta nueva |
| 5 | `GET informes/membrete` | ruta nueva |
| 6 | `years.titulo_constancia_estudio` | columna nueva, 6 instantáneas |
| 7 | El consecutivo, uno por hoja | conducta nueva **detrás de una llave** |

**Router de 644 a 647.** Tres rutas, no cuatro: eso se dijo mal al plantearlo y se
corrige contando (`route:list --json` en `.worktrees/inf`).

---

## 1 · La clave del alumno, que es lo que más rinde por línea

`Acudiente::$consulta_alumnos_de_acudiente` nombraba `no_matricula`, `nombres`,
`apellidos`, `documento`… y **nunca `a.id`**. El directorio del grupo se monta invirtiendo
`PUT acudientes/datos` —que trae el grupo entero en una llamada, pero del revés:
acudientes con sus alumnos colgando—, y para invertirla hace falta la llave del alumno.

**Cruzar por `documento` no vale, y eso está medido**: en el docker, de los 378 alumnos
con matrícula viva de 2025, **68 no tienen documento (18 %)**. Serían 68 renglones del
directorio sin teléfono de casa, por un cruce fallido y en silencio.

> **Lo único que el front no pudo verificar, verificado aquí: el Excel no la ve.** La
> consulta la comparten `AcudientesController:154` y `AcudientesExport:53`, y el export
> cuelga las filas de `$acudientes[$j]->alumnos` para pasárselas a `AcudientesSheet`, que
> es un `FromView`. Su plantilla —`resources/views/acudientes.blade.php` 60-68— pinta
> **nueve campos nombrados uno a uno**, así que una clave de más no le cambia ni una
> celda. *Comprobado leyendo la plantilla, no supuesto porque «añadir claves no rompe».*

Se llama `alumno_id` y no `id` a propósito: esas filas cuelgan de un acudiente que **ya
tiene su propio `id`**, y una clave `id` ahí dentro se lee como la del acudiente.

---

## 2 · El aviso que estaba escrito encima de la consulta, cumplido

En `Informes/BolfinalesController:364`, tres líneas por encima de la consulta de
recuperaciones, ponía esto desde el 2 de septiembre:

> *«los metadatos de acta que A9 le añada no salen impresos hasta que alguien los nombre
> aquí»*

**Eso es exactamente lo que había pasado.** `nivelada_at`, `nivelada_por` y `observacion`
las escriben desde entonces **las dos ramas** de
`DefinitivasPeriodosController::putUpdateRecuperacion` —en esa tabla la fila entera *es*
el acta— y **no las leía nadie**: el acta de nivelación salía con la nota y sin fecha, sin
responsable y sin actividad.

Es `profesores.tono` otra vez, con el agravante de que **el aviso ya estaba escrito en la
línea de al lado**. *Un aviso escrito no protege solo; sólo protege el día que alguien
hace lo que dice.*

> ### EL NOMBRE SALE DE `users` Y NO DE `profesores`, y la petición proponía las dos
>
> `nivelada_por` guarda **`$user->user_id`**, un id de `users` —mírese el `INSERT` de
> `putUpdateRecuperacion`—, y **de las 22 cuentas de tipo `Usuario` ninguna tiene ficha en
> `profesores`**. Un `JOIN` contra la ficha dejaría sin nombre **justo a secretaría**, que
> es quien firma las actas.
>
> Es la trampa que el `CLAUDE.md` lleva escrita y que `getRecorrido` acababa de cometer el
> mismo día, en el módulo cuyo argumento entero es que quede el nombre. Aquí se evitó
> porque se fue a mirar quién escribe la columna antes de elegir con qué unirla.
>
> El alias es `nivelada_por_username`, que es el que ya usan `NotasController`,
> `EditnotaController` y `DefinitivasPeriodosController`.

**Con esto la sección B del acta queda entera**: su fuente es
`bolfinales/detailed-notas-year-group`, que es justo esta consulta.

---

## 3 · `PUT informes/nivelaciones-del-grupo` — la sección A

De las cinco rutas que tocan nivelar, **las cinco son de escritura sobre una fila**. A la
pregunta *«¿quién presentó nivelación en el grupo X?»* no contestaba ninguna: había que
barrer asignatura por asignatura con `PUT notas/detailed` —**la consulta más pesada del
proyecto**, así rotulada por el propio front— **y en serie**, porque paralelizarla con
`forkJoin` tumba el backend del colegio. Una docena de asignaturas, una docena de las
consultas más caras, con barra de progreso.

Aquí es **una consulta** que devuelve sólo las filas niveladas.

**Es `periodo_id` y no `year_id`, y la petición decía `year_id`.** La sección A es por
periodo —lo que se nivela es un indicador, y un indicador vive en una unidad que tiene
`periodo_id`—, y el propio diseño del front lo dice en su tabla de parámetros. Lo que es
del año es la sección B, y ésa no pasa por aquí.

**Nivelada es `nota_original IS NOT NULL`, y el cero cuenta.** El filtro va en la consulta
—que es la mitad del ahorro— y se escribe `IS NOT NULL` y no una verdad laxa porque un
alumno que venía de cero **está nivelado**, y un `if ($fila->nota_original)` lo dejaría
fuera sin dar ningún error. El front ya tropezó con esto.

> **La única diferencia deliberada con los `JOIN` de `notas/detailed` es `u.alumno_id`.**
> Allí va `<=> :alcance` porque es de UN alumno; aquí el grupo lleva dentro alumnos con
> boletín independiente y cada uno tiene su alcance. `IS NULL OR = n.alumno_id` es esa
> misma regla escrita para muchos. **Sin la segunda mitad, un marcado aparecería en el
> acta con los indicadores de otro.**

**Guard `auth.personal` y nada dentro, dicho a propósito**: son exactamente las filas que
`notas/detailed` ya sirve con ese mismo guard, sólo que en un viaje en vez de doce.
Estrechar aquí y no allí sería un cartel y no un candado, y dejaría el acta sin poder
sacarla justo a quien la firma.

---

## 4 · `PUT ausencias/de-alumno` — la citación

**No estaba bloqueada, y eso lo corrigió el front midiendo**: hoy sale de `GET
planillas/ver-ausencias`. Lo que pasa es que aquélla devuelve **todos los grupos del año,
con todos sus alumnos y todos sus periodos** para citar a uno, con forma N alumnos × 4
periodos y una consulta cada una.

> **La medición que la acompaña se publica con su población, porque si no engaña**: 150 ms
> y 108 KB en el docker — **pero esa copia tiene 42 alumnos y cero filas de ausencia**, así
> que ese número no predice un colegio de verdad. Lo que sostiene el cambio es la **forma**
> de la consulta, que crece lineal, no el milisegundo medido sobre una tabla vacía.

**Ruta nueva y no un retoque de las siete de `ausencias/*`**, que era el aviso del front y
es correcto: esa familia la comparte `myvc_flutter`, una sola app para los dieciséis
colegios cuya versión vieja convive meses con este backend. Es la razón por la que nivelar
estrenó endpoints en vez de enseñarle a `notas/update`.

**No agrega, y ésa es la decisión.** En este proyecto conviven **dos criterios de recuento
sobre estos mismos datos** —contar filas con `COUNT(*)` y sumar `cantidad_ausencia`—, y
dan números distintos porque una fila puede valer más de una falta. Un total aquí sería un
**tercer** número, y el papel que lo imprimiera no podría decir cuál de los tres es. Por lo
mismo **no se filtra `tipo`**, y `fecha_hora` se devuelve como está aunque sea `NULL`:
rellenarla con la fecha de creación sería inventarse el dato que el papel imprime.

**El guard, que es lo que el front preguntó:** `auth.personal` y nada dentro. **No abre
nada**: las mismas filas —y las de todos los demás alumnos del colegio— las sirve hoy
`planillas/ver-ausencias` con ese mismo guard. **No lo alcanza un acudiente**, a propósito:
la citación es el papel con el que el colegio llama a la familia, no lo que la familia
consulta. El día que se decida lo contrario, eso es `persona.propia` sobre una ruta suya,
no aflojar ésta.

---

## 5 · `GET informes/membrete` — un controlador de una línea con un porqué

El rector, su cédula, su firma, la ciudad, la resolución y el DANE viven **sólo** dentro de
`Year::datos()`, y `Year::datos()` no tenía ruta propia. Así que la constancia de estudio
se los pedía a **`GET piars-config`** —la configuración de la ruta de inclusión— para poder
firmar un papel que no tiene nada que ver con el PIAR. Funciona y tiene precedente, pero es
un nombre que miente, y **el día que alguien estreche el permiso de `piars-config` rompe
una constancia**.

**Las dos ramas de `Year::datos()` se sirven por la misma ruta, y eso se comprobó antes.**
Sin `year_id` contesta el año actual —lo que la constancia ya recibía—; con `year_id`
contesta el de ese año, que es lo que pide un papel de un año cerrado. Las dos devuelven
**las mismas 61 claves**, medido **sobre la respuesta y no sobre el SQL**: si no fuera así tendrían que ser dos
rutas, porque una respuesta que cambia de forma según un parámetro opcional es la que el
cliente tipa una vez y rompe la otra. Lo fija un test.

**Y la comprobación del año no es de cortesía**: `Year::datos()` termina en `[0]`, así que
un año que no existe reventaría con «Undefined array key 0» — un 500 contando un error de
quien llama.

---

## 6 · `years.titulo_constancia_estudio` — la tercera hermana

El título de la constancia era un literal dentro del informe nuevo, el mismo defecto que
tenían `titulo_certificado_final` y `titulo_certificado_periodos` antes del 15 sep. Va por
año porque **de los años cerrados se siguen pidiendo papeles**.

**El andamiaje ya estaba y funcionó como prometía.** La columna entra **sólo** en
`Year::TITULOS_POR_DEFECTO`, y con eso `putEncabezado` la valida y la escribe sin que nadie
se acuerde de ir — porque aquel método **recorre la constante** en vez de repetir la lista.
Eso lo dejó escrito la sesión del 15 sep y aquí se cobró.

> **Lo que NO se derivaba solo, y es el hallazgo de esta parte.** El corte de
> `years/toggle-cambiar-valor` —el conmutador genérico escribe *cualquier* columna de
> `years` con sólo `auth.personal`— llevaba los dos títulos **escritos a mano**, y nada
> comprobaba que la lista estuviera completa. La tercera columna habría entrado **con la
> puerta de al lado abierta y en silencio**: el invariante de «un título no puede quedarse
> vacío» sería un cartel.
>
> Se cierra con un test que **recorre `Year::TITULOS_POR_DEFECTO`** y exige 422 **y que la
> fila no se mueva** para cada una, así que el cuarto título entra en el bucle solo. *Al
> darle una regla a una columna se repasan todos los caminos que escriben esa tabla.*

**Y `TitulosDelCertificadoTest` se puso rojo solo al añadirla**, que es literalmente lo que
su propio comentario prometía: *«es lo que hace que añadir un tercer título no pase
inadvertido por aquí»*. Un candado diciendo la verdad.

> ### Y LA MEDICIÓN DIJO 6 INSTANTÁNEAS. SON 22, Y LA HERRAMIENTA NO SE EQUIVOCÓ
>
> `tools/lo-que-reparte-una-columna.py years` contesta **6**, y esas seis son ciertas: las
> respuestas que llevan la fila de `years` entera por `SELECT *`. Al correr los tests se
> movieron **22**.
>
> **No es un fallo del detector: es que la pregunta tenía dos mitades y él contesta una.**
> Su propia salida lo dice —*«6 es COBERTURA, no exposición»*— y lista aparte **27
> INMUNES**, que son las que viajan por una proyección nombrada. Lo que no puede saber es
> que la columna nueva **también se iba a nombrar en esa proyección**: `Year::datos()`
> nombra sus 63 columnas una a una, y ahí entró. Las 16 restantes son las 13 del boletín
> —`boletines`, `boletines2`, `boletines3`, `bolfinales` y `bolfinales-preescolar`, que
> llevan el año dentro— y otras tres (`piars-config`, `informes/datos`, las notas actuales).
>
> O sea que la herramienta mide **el reparto automático**, y una columna que además se
> escribe a mano en un `SELECT` explícito se reparte **por los dos caminos**. La regla que
> queda: *cuando además de migrar la columna la nombras en una proyección, la cifra de la
> herramienta es un suelo, no un total.* Lo que da el total es correr los tests.
>
> Se cuenta así, que es lo que hace la cifra rehacible — **por FICHEROS y con la clave
> entrecomillada**:
>
> ```bash
> n=0; for f in $(git diff --name-only -- tests/Contrato/Snapshots/); do
>   git diff -- "$f" | grep -qE '"titulo_constancia_estudio"' && n=$((n+1)); done; echo $n   # 22
> ```
>
> > **Las dos precauciones de esa orden son las dos formas en que la primera versión mintió,
> > y las dos se cometieron aquí antes de escribirla.**
> >
> > La primera contaba **líneas** con `grep -c` y publicaba el número como
> > «instantáneas»: coincidió en 22 por casualidad, porque cada fichero gana una línea.
> >
> > La segunda no llevaba comillas, y al contar la otra clave de esta tanda dio **16
> > instantáneas** donde son **2** — porque `usa_consecutivo_certificados` **contiene**
> > `consecutivo_certificado`. El 16 no parecía absurdo: `muestreo-piars-config.json`
> > dentro de la lista fue lo único que chirrió. *El primer sitio donde mirar cuando un
> > número sale raro es el detector*, y aquí el detector era mío y de tres líneas.

Y la otra clave de esta tanda, `consecutivo_certificado`, mueve **2**: las dos rutas de
`Informes/BolfinalesController`. **Las de preescolar no**, y eso confirma que el cambio no
se desbordó: `BolfinalesPreescolarController` tiene su propia copia de
`detailedNotasGrupo` y no se tocó.

En total, **25 instantáneas** entre las 22 de la columna, las 2 del consecutivo y las 3 de
las rutas.

Mueve **22 instantáneas**: **6** por `SELECT *` —medidas antes de escribirla con
`tools/lo-que-reparte-una-columna.py`— y **16 más** por la proyección nombrada de
`Year::datos()`.

---

## 7 · El consecutivo, uno por hoja — y la llave que lo hace desplegable

**El defecto, medido:** el consecutivo se quema **una vez por PETICIÓN**, así que un grupo
de 37 sale con 37 papeles que dicen todos «No. 144». Por eso la constancia nueva **no
imprime número**: antes sin número que con uno repetido o con uno que nadie reservó.

Joseth decidió el 20 sep quemarlo por hoja.

> ### PERO EL REPARTO VA DETRÁS DE `consecutivo_por_hoja`, Y LA LLAVE NO ES CEREMONIA
>
> El número viaja hoy en `year.contador_certificados`, **uno solo para toda la respuesta**,
> y los dieciséis colegios llevan copias de `myvc_front` en versiones distintas. Si esto
> quemara N sin más, un colegio con el front viejo **gastaría 37 números para imprimir 37
> veces el mismo**: repetido igual que antes y con **36 folios oficiales tirados**.
>
> En una cuenta de papel oficial la dirección irreversible es quemar: un folio no quemado
> se quema después, uno quemado no vuelve. Así que el reparto lo pide quien sabe leerlo, y
> **quien no lo pida sigue exactamente como estaba**. Es la misma forma que nivelar.
>
> *Esto no es recortar la decisión de Joseth: es la decisión sin el efecto que no pidió.*

**Lo que cambió, en orden:**

1. `Grupo::alumnos()` sube **antes** de la quema. No dependía de nada de en medio, y
   `Year::datos()` sigue leyéndose **después**, que es lo que mantiene
   `year.contador_certificados` como el número de después y no el de antes.
2. Las hojas son `alumnosDeLaRespuesta()` y **no `count($alumnos)`**: cuando el cliente
   pide alumnos sueltos, el informe imprime ésos. Quemar por el grupo entero gastaría un
   folio por cada compañero que no se imprime.
3. Ese filtro **se movió, no se copió**. Vivía al final del método; dos copias del mismo
   filtro son dos criterios en cuanto alguien toque una.
4. El bloque se reserva **en una sola escritura** dentro de la transacción con `FOR UPDATE`
   que ya existía: N incrementos sueltos son N carreras, y dos secretarias imprimiendo a la
   vez se llevarían bloques entrelazados.
5. **Cero hojas no queman nada.** La rama de siempre sigue quemando uno pase lo que pase,
   porque cambiar eso sería estrenar conducta en el camino que usan los dieciséis.
6. `consecutivo_certificado` viaja **siempre**, en `null` cuando no hay número. Una clave
   que a veces no viene obliga a distinguir «vacío» de «no vino», y en una plantilla esas
   dos cosas se parecen demasiado.

---

## Lo que queda apuntado y no se hizo

- **La tabla de certificados emitidos.** Sigue sin existir minuendo: un número quemado por
  abrir la pantalla es indistinguible de uno emitido, y *«¿cuántos emitimos este año y a
  quién?»* no tiene respuesta ni con acceso total a la base. El rastro de auditoría dice
  **de qué número a cuál**; a quién se le entregó el papel, no.
- **Reimprimir un acta vieja tal como se firmó.** La fila de `notas` sólo guarda la última
  nivelación. `auditoria` tiene el rastro y **no lo lee nadie**: `FROM auditoria` en
  `app/` da **0**, contado sin truncar.

  > **La otra mitad de esa frase, tal como la trajo el front, es falsa y se corrige aquí.**
  > Decía que *«`can_view_auditoria` existe sin endpoint que lo use»* — y lo decía
  > honestamente, marcado como **no comprobado por ellos**. Comprobado: ese permiso sale
  > **8 veces en `app/`**, es `Autoriza::PERMISO_AUDITORIA` y lo nombra
  > `BitacorasController`. Lo que no tiene lector es **la tabla**, no el permiso. *Un dato
  > que llega marcado como sin comprobar se comprueba antes de copiarlo, y más cuando la
  > conclusión que sostiene sí es cierta: eso es justo lo que hace que nadie vaya a
  > mirar.*

  Es una tarea, no un hueco de este lote.
