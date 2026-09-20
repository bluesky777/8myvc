# 45 — La importación dinámica de alumnos

> **Nació como el 44 y pasó al 45 el mismo día.** Otra sesión estrenó
> `44-el-dia-de-matriculas.md` mientras esto se escribía, y las dos llevaban razón: **ninguna podía
> saber el número de la otra sin mirar `main` después**. Es el mismo caso que el contador de rutas,
> en otro sitio — *un número que se elige mirando el árbol propio describe un árbol que mañana no
> existe*. Y por lo mismo, la migración pasó de `2026_09_20_300000` a `…_400000`: las dos sesiones
> habían elegido el mismo minuto.

> **Movido aquí el 20 sep 2026 desde `myvc-ia-prototipo/docs/plan-importacion.md`**, que es lo que
> ese mismo documento mandaba hacer: *«mientras sea un plan no ensucia el repo; el día que la
> Fase 1 arranque, se mueve allí y se le da número»*. La Fase 1 arrancó el 19 sep y está fundida en
> `main` (`f0d0313`), así que le toca número. El análisis que lo sustenta —967 líneas, medidas
> contra la base real— **se queda en el prototipo** (`myvc-ia-prototipo/docs/importacion-asistida.md`):
> aquí viven las decisiones, allí la medición que las produjo.

---

## 0. La visión, en palabras de Joseth

> «Dar todas las herramientas para hacer una importación **dinámica**, con corrección de errores,
> preguntándole al usuario qué quiere hacer en tal y cual caso. Diciéndole qué va a pasar, etc.»

Lo que ordena todo el plan es esa frase y no la IA. **La IA es una opción dentro de** una
importación que tiene que mejorar igual sin ella. De ahí el orden de las fases: si el proyecto de
IA se cancelara mañana, la Fase 1 y la Fase 2 siguen mereciendo la pena.

---

## 1. Decisiones tomadas

| | Decisión | Fecha |
|---|---|---|
| **Prioridad** | Primero los arreglos del importador. Después las pantallas. | 19 sep |
| **D1** | El traductor de Excel vive en la **pasarela central**, no en `8myvc`. | 19 sep |
| **D2** | **(B) y (A) detrás**: se aprueba **el mapa** (≈13 renglones), y el `.xlsx` corregido se puede bajar igual **después** de aprobarlo. | 20 sep |
| **D3** | Las filas que la IA no sepa traducir van a una hoja `REVISAR`. | 19 sep |
| **D4** | Documento que no está en MyVc = **alumno nuevo**, salvo que coincidan nombre, apellidos y fecha de nacimiento con alguien existente: entonces va a `REVISAR` con las dos filas al lado. **La IA no fusiona nunca.** | 19 sep |
| **D5** | Los mocks de la Fase 2 son **HTML estático suelto**, fuera de `myvc_front` y de `myvc_front_2`. | 20 sep |
| **D6** | D2 se dibuja **en sus dos variantes** y Joseth elige viéndolas. | 20 sep |

**D1, el porqué:** una clave en vez de dieciséis, cero dependencias nuevas en el `vendor/`
compartido —que es un symlink y las reparte a todos—, el servidor débil fuera del camino caliente,
y un cambio de prompt llega a los dieciséis **sin desplegar colegio a colegio**.

**D4, el porqué:** el caso más común de todos es el chico que pasa de Registro Civil a Tarjeta de
Identidad. Hoy se duplica. Y fusionar dos expedientes mal es de lo poco aquí que **no se deshace
con un `DELETE`**, así que la IA señala y una persona decide.

**D5, el porqué:** dibujar dentro de un front obliga a elegir repo antes de saber qué se dibuja
—AngularJS donde vive la pantalla de hoy, o `app2` donde vive lo nuevo— y esa elección la debería
decidir el flujo, no al revés. Un HTML suelto se itera con Joseth en minutos y **no compromete
ningún repo**.

**D2, el porqué, y no es el argumento de IA:** lo que convenció a Joseth fue que **la pantalla del
mapa hay que construirla de todos modos** para que la importación mejore sin comprar nada — la IA
sólo añade un botón que la rellena. Lo demás lo decide la aritmética: se revisan **13 renglones en
vez de 800 filas**, y el mapa es un multiplicador, así que una decisión mala ejecutada 770 veces se
atrapa mirando trece. El `.xlsx` no se descarta, se pospone: se baja después de aprobar, que es
cuando ya no es el único control.

**D6, el porqué, y es la mitad importante:** D2 era *«¿qué recibe el coordinador cuando la IA
traduce su Excel?»*, y tenía dos respuestas vivas —un `.xlsx` corregido más la hoja `REVISAR`, que
es lo que Joseth pidió literalmente al principio, o una pantalla donde aprueba **el mapa**
(≈13 renglones) en vez del fichero (≈800 filas)—. La segunda no existía cuando se formuló la
pregunta: **la trajo la propia Fase 2**. Decidir antes de los mocks sería decidir sin ver la
opción nueva.

---

## 2. Lo que YA EXISTE y no hay que rehacer

**[`09-pendientes.md` §1](09-pendientes.md), «La importación de Excel, reanudable», hecha el 20 ago
2026.** Media infraestructura de lo que se quiere ya está puesta:

- **Tabla `importaciones`**: una fila por importación —archivo, huella, año, avance por hoja,
  filas, estado, error, inicio y fin—. *Es donde se apoya toda la Fase 2: una importación que
  pregunta necesita un sitio donde recordar por dónde iba y qué se le contestó.*
- **`App\Services\PuntoDeControlDeImportacion`**, que decide qué se reanuda.
- **La huella es el sha256 del CONTENIDO**, no el nombre: la secretaría sube tres veces
  `alumnos.xlsx` y son tres archivos distintos.
- **Idempotencia por el documento del alumno.** Antes, una fila sin `id` significaba «créalo» sin
  mirar si ese documento ya estaba, y eso duplicaba alumno, usuario y matrícula.
- **Índice en `alumnos.documento`**, creado ese día.
- Seis tests en `tests/Contrato/ImportacionReanudableTest.php`.

---

## 3. La contradicción del `EXPLAIN` — resuelta el 19 sep

El análisis midió `EXPLAIN` sobre la búsqueda por documento y obtuvo **`type=index, key=PRIMARY`**:
el índice de agosto existe **y la consulta lo esquiva**, porque el documento se compara como número
contra una columna de texto. Las dos cosas eran ciertas a la vez, que parecía el peor caso.

**Y aun así no se toca, porque la consecuencia que se le suponía era falsa:**

1. **No cuesta.** 200 búsquedas: **36 ms como texto, 26 ms como número.** La tabla son 1.284 filas
   y recorrer el índice sale más barato que resolver aparte el `ORDER BY id`. Lo de «800 barridos
   contra los 300 s de cPanel» estaba escrito por mí y la medición lo desmiente a esta escala.
2. **Y convertir habría roto gente.** `'01035123456' = 1035123456` es cierto y contra la cadena es
   falso. Hay **7 alumnos vivos con cero a la izquierda** que la comparación de hoy encuentra y la
   «arreglada» no: se habría duplicado a los siete, que es el fallo exacto que cerró la §1 de
   `09-pendientes.md`.

Lo que sí hacía falta era **descartar el 0**: `'AB1234' = 0` es cierto, así que dos documentos no
numéricos colisionan y el alumno se fundiría con un desconocido. Hecho, con el porqué en el
docblock de `idPorDocumento`.

*El síntoma era real y la consecuencia que se le suponía, falsa. Antes de actuar sobre un
`EXPLAIN`: medir el tiempo y mirar a quién deja fuera.*

---

## 4. Las fases

### Fase 1 — Los arreglos, sin IA de por medio — **HECHA el 19 sep**

Rama `fix/importacion-fase-1`, fundida en `f0d0313`. Larastan nivel 7 sin errores en los dos
ficheros, `pint:test` verde. Los cuatro puntos, con lo que resultó cada uno:

1. **Medido y NO tocado** — ver §3. Lo que se hizo fue descartar el 0.
2. **Hecho.** 20 `strtolower(` → 0, y las dos partes pasan por `normalizar()`. Comprobado contra el
   catálogo real: CÉDULA / Cédula / cedula / CC → id 1, y las cinco grafías de Medellín → id 4. De
   propina, dos que no se buscaban: **«Sí»** —como se escribe bien— no casaba con `'si'` y dejaba
   `is_urbana`, `es_nuevo` e `is_acudiente` sin tocar; y **«no aplica »** con espacio detrás se
   guardaba como que SÍ tiene SISBEN. Y una tercera: si el catálogo de un colegio tuviera una
   `abrev` vacía, un `''==''` le ponía ESE tipo al alumno.
3. **Hecho.** Lo que se guarda no cambia; lo que cambia es que «no reconocí» deja un aviso en
   `ImporterFixer::$avisos` y «no puso nada» no.
4. **Hecho.** Lo que no cabe en `varchar(4)` no se escribe. No se traduce «Activo», porque podría
   ser MATR o ASIS y **eso lo decide el colegio**. Son **siete** códigos vivos: MATR, RETI, PREM,
   FORM, ASIS, PREA, DESE.

> **Lo que la Fase 1 NO es:** no es «preparar el terreno para la IA». Son defectos que hacen daño
> hoy, en los dieciséis colegios, todos los eneros, a colegios que no van a comprar IA nunca.

### Fase 2 — Las pantallas, primero SIN IA — **en curso desde el 20 sep**

La lleva la sesión del front (`myvc-front-41`), sobre `myvc_front`, y los mocks viven en
`myvc-ia-prototipo/mocks/importacion/` (**D5**). Nueve escenarios, y cada uno con las tres cosas:
**qué le decimos, qué opciones le damos y qué pasa con cada una.**

- El fichero no tiene las hojas esperadas / le sobran.
- Faltan columnas obligatorias; sobran columnas desconocidas.
- Un valor no está en el vocabulario (`tipo_doc`, sexo, estrato, EPS…).
- Un valor se va a truncar (`estado`, sexo, fecha).
- Un documento ya existe: ¿es el mismo alumno o uno nuevo?
- Un documento no existe pero nombre + apellidos + fecha coinciden (**D4**).
- Documentos duplicados **dentro del propio fichero**.
- La importación se cayó a medias: ¿reanudar o empezar de cero? *(ya hay tabla para esto)*
- Todo correcto: qué va a pasar exactamente, **antes** de que pase.

La regla que gobierna la fase es la frase de Joseth —*«diciéndole qué va a pasar»*—, y el tono sale
de lo medido: **el importador de hoy casi no valida, adivina.** Un valor que no reconoce no produce
un 422: produce **otro valor, plausible y equivocado**, y sigue. Por eso cada pantalla dice siempre
**qué se guardaría si no se hace nada** — que es donde se ve el `3 = Tarjeta de Identidad`.

### Fase 3 — El traductor con IA, en la pasarela

Sólo después de la 1 y la 2. El modelo **no ve el Excel**: ve las cabeceras y tres ejemplos por
columna y devuelve **un mapa, no datos** —871 bytes contra 106.386 del fichero, factor 122×—. La
máquina aplica el mapa, porque la máquina no se equivoca copiando un documento y el modelo sí
puede.

El modo de fallo tiene nombre: **el mapa es un multiplicador**, una decisión mala ejecutada 770
veces. La defensa es que **se aprueba el mapa (≈13 renglones), no el fichero (800 filas)**.

### Fuera de alcance — la barra de consumo

Joseth: *«mostrarle al usuario que adquirió tokens una pequeña barra con porcentaje… pero supongo
que esto último es otro scope»*. **Sí, y conviene saber que ya está medio resuelto**: la pasarela
central existe precisamente para medir el gasto por docente y por colegio, así que el número que la
barra necesita **lo produce ella sin trabajo extra**. Lo que falta es dónde vive el cupo, quién lo
recarga y qué pasa al agotarse — eso es producto, no ingeniería.

---

## 5. Lo construido el 20 sep — las tres piezas, y qué hace cada una

Joseth autorizó las tres con el precio delante. **Router de 620 a 622**, una migración, cero
permisos nuevos.

### 5.1 La respuesta de la subida deja de ser una cadena — y arregla un fallo vivo

`postAlgo` devolvía `'Importados.'` en `text/html`. Medido antes de tocarlo:

```
status 200 · Content-Type 'text/html; charset=utf-8' · cuerpo 'Importados.' · JSON válido: NO
```

`app2` sube por `comunes/subida/subida.ts`, que llama a `http.post` **sin `responseType`** —o sea
`'json'`—, y Angular convierte un 2xx cuyo cuerpo no parsea **en un error**. Así que las dos
pantallas nuevas enseñaban «no se pudieron importar» **después de una importación que había
funcionado**. Lo predijo la sesión del front leyendo Angular; la medición lo confirmó.

Ahora devuelve `importacion_id`, `reanudada`, `filas_del_archivo`, los `hechos` —filas, creados,
actualizados, **reencontrados**, usuarios y matrículas creadas, saltadas— y los `avisos`.
**Los errores siguen siendo texto** (decisión de Joseth): `AlumnosCtrl.ts:1021` pinta
`status + ': ' + data`, y con un JSON ahí saldría `500: [object Object]`.

*El radio de impacto se midió antes: cuatro llamadores en dos repos —`myvc_flutter` no llama a esa
ruta— y ninguno lee el cuerpo en la rama buena. Lo comprobaron las dos sesiones por separado.*

### 5.2 Lo que la importación recuerda entre dos tandas

Dos columnas en `importaciones` y `GET importar/alumnos/pendiente/{year}`, que es lo que la
pantalla pregunta **al entrar**, antes de que nadie elija fichero.

**Los dos se guardan con reglas contrarias, y ésa es la distinción que lo sostiene:**

| | regla | por qué |
|---|---|---|
| `avisos` | **acumulan** | son historia: la pantalla habla de *«las 63 filas ya escritas»*, que se escribieron en otra tanda |
| `respuestas` | **pisan** | son una instrucción vigente: quien vuelve a subir con el mapa corregido está corrigiendo lo que dijo |

Y un `null` **no borra**: «esta subida no traía instrucciones» no es «olvida las que te di» — con la
otra lectura, subir el archivo desde otra pantalla le vaciaría el trabajo a quien lo dejó a medias.

Las respuestas **viajan en el cuerpo de la subida**, no por una ruta propia: son parte de «sube
esto con estas instrucciones», no un recurso aparte. Eso ahorró la segunda ruta que este alcance
parecía pedir.

> **Y lo que esa fila NO guarda, que ordena el escenario 8 entero: el fichero.** `importaciones`
> conserva el **nombre saneado** y la **huella del contenido**, no el contenido —nadie hace
> `store()`; Laravel Excel lee de un temporal y lo descarta—. Así que al retomar una importación
> **la persona tiene que volver a elegir el fichero**, y el servidor no puede decir «13 de 41»
> porque no sabe cuántas filas tenía la hoja 6.
>
> No es un hueco que tapar guardando el libro: serían ficheros con el nombre, el documento, la
> dirección y el teléfono de cada menor acumulándose en el disco de dieciséis colegios para pintar
> un denominador. **El total sale del ensayo** —`por_hoja[].filas`— cuando la persona vuelve a
> subirlo, y así además el denominador es el del fichero que tiene delante y no el de enero. La
> huella sirve para lo que sí hace falta antes: decirle **si es el mismo libro**, porque con otro lo
> que va a pasar no es reanudar.

`longText` y no `json()`: producción es MariaDB 10.5, donde `JSON` es un alias de `LONGTEXT` con un
`CHECK` detrás, y el docker es MySQL 8, donde es nativo.

### 5.3 El ensayo — `POST importar/alumnos/ensayo/{year}`

La pieza grande, y la que da sentido a la frase de Joseth. Lee la hoja entera y contesta **qué va a
pasar**, sin escribir una sola fila. Cubre los escenarios 1–7 y 9 de la Fase 2.

Devuelve `hojas` (con `coincide_con`, encabezados, `faltan`/`sobran`), `grupos_del_year`,
`columnas_destino` **con su `si_falta`**, `catalogos`, `valores_no_reconocidos` agrupados por valor
con sus veces y sus filas, `truncados` con su consecuencia, `posibles_repetidos` (D4),
`duplicados_en_el_archivo` con `cual_ganaria_hoy`, `filas_sin_documento`, el `plan` fila a fila,
`totales`, `efectos_colaterales` y `servidor_estricto`.

**Tres reglas que no se tocan:**

1. **No escribe nada.** Lo fija un test que cuenta seis tablas antes y después — no sólo `alumnos`:
   una fila de alumno son ocho escrituras repartidas entre alumno, usuario, rol, matrícula y los dos
   acudientes.
2. **Usa el MISMO traductor que la importación de verdad** (`ImporterFixer::verificar()`, que no
   escribe). Una copia se separaría del original en la primera corrección y nadie se enteraría.
3. **Y lo que promete es lo que pasa**, atado por un test que ensaya, importa y compara contra la
   base.

> **Esa tercera regla ya cazó un fallo mientras se escribía, y es el que explica por qué hace
> falta.** El `UPDATE` del importador escribe `nro_sisben` **dos veces** en el mismo `SET` —una en
> la lista fija y otra en el fragmento que arma `verificar()`— y **gana la segunda**, así que un
> «No aplica» de la hoja acaba en `NULL`. El ensayo prometía el valor crudo: habría mentido en las
> **37 filas** del seed, y nadie lo habría notado mirando el código.

> **Y una distinción que la pantalla tiene que respetar: «sin cambios» significa que ningún DATO
> cambia, no que la fila no se toque.** El `UPDATE` se ejecuta igual y mueve `updated_at` — medido:
> de las 37 fichas dadas por «sin cambios», la única columna que se movió fue ésa, en las 37.

### 5.3 bis Dos cosas que pidió la Fase 2 con el mock delante

**El contador de celdas vacías, por columna y nunca por celda.** El traductor no avisa de un hueco,
y hace bien —avisar por celda llenaría la pantalla con los huecos de las dieciséis bases—, pero
**el vacío no es neutro**: por el defecto, cinco celdas vacías de `tipo_de_documento` se guardan
como Tarjeta de Identidad. O sea que **«no sé» se convierte en una afirmación sobre un menor**, y
eso hay que poder decirlo con un número delante. Va en `vacios[]`, con su consecuencia.

**Y el `valor_por_defecto` estructurado** —`{id: 3, literal: "…", existe_en_el_catalogo: true}`—
además de la frase, porque va en una celda de tabla y partir la frase en el front sería adivinar
dónde. **El literal se lee del catálogo del colegio**: el importador clava `tipo_doc = 3` —eso es
código— pero qué es el 3 lo dice cada base. De ahí sale `existe_en_el_catalogo`, que es el caso que
nadie ha mirado: **un colegio sin esa fila recibiría una referencia rota**, hoy en silencio.

### 5.3 ter Dos premisas que caducaron el mismo día, y las dos las destapó tener un consumidor

**La hora de `importaciones` dejó de ser invisible.** Esa tabla escribe `inicio`, `fin` y
`updated_at` con `now()` —o sea **UTC**— y está declarada como excepción en `RelojUnicoTest` con un
motivo escrito: *«sólo se restan entre sí, nunca se comparan con otra tabla, así que unificar la
zona no cambia ningún resultado — sólo desplaza cinco horas lo que se lee en pantalla»*. La Fase 2
**es** esa pantalla: «empezada el 14 de enero a las 9:41» habría dicho las 14:41.

Se resolvió **convirtiendo al leer** y no al escribir. Cambiar la escritura habría dejado esa
columna con **dos relojes en su historia** y filas que nadie podría distinguir, que es exactamente
la enfermedad que ese test existe para evitar; y la decisión de mover la tabla entera sigue anotada
como de quien lleve las importaciones. *Ahora ya no la fuerza ninguna pantalla.* El contador de
`PERMITIDOS` sube de 8 a 10 —las dos escrituras nuevas van con `now()` **por consistencia con la
misma columna**, no por inercia— y el motivo va escrito al lado.

**Y el 422 del fichero ilegible filtraba la ruta del despliegue.** El mensaje de PhpSpreadsheet
trae dentro `zip:///app/…/storage/framework/…`, así que la respuesta que se puso **para no filtrar
el `.env` por el cuerpo de un 500** estaba filtrando el camino del servidor por su cuenta — y en
los dieciséis esa ruta lleva el subdominio del colegio. **Lo cazó el test que se había escrito para
ese mismo 422**, que es la mitad que importa: el arreglo no se buscó, se tropezó con él quien ya
estaba mirando.

### 5.3 quater El campo que habría salido vacío para todos los que usan la pantalla

`empezada_por` sale de unir `importaciones.created_by` con la ficha de la persona, y la primera
versión unía **sólo contra `profesores`**. Medido el 20 sep 2026 en la copia de desarrollo:

```sql
SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND tipo = 'Usuario';            -- 22
SELECT COUNT(*) FROM users u INNER JOIN profesores p ON p.user_id = u.id
 AND p.deleted_at IS NULL WHERE u.deleted_at IS NULL AND u.tipo = 'Usuario';         --  0
```

**Cero de veintidós.** Los administrativos —que son quienes importan alumnos— no tienen ficha de
profesor; las 47 que la tienen son docentes. O sea que la cabecera habría dicho *«empezada el 14 de
enero a las 9:41 por»* y nada, **justo para todos los que usan esa pantalla**. Ahora cae al
`username`, con su test.

*Lo destapó que el front preguntara la forma exacta del campo en vez de dibujarlo por su nombre.*

### 5.3 quinquies Dos números que se llamaban casi igual y contaban cosas distintas

El escenario 9 pide que el informe final **compare lo prometido con lo hecho**. Al ir a hacerlo
salió que los dos conjuntos no eran comparables:

| ensayo | subida | |
|---|---|---|
| `totales.se_saltan` — filas **sin primer nombre**, que no se escriben nunca | `hechos.saltadas` — filas **ya aplicadas en una tanda anterior** | **el mismo nombre, cosas distintas** |
| `totales.actualizar` + `totales.sin_cambios` | `hechos.actualizados` | desde abajo **no se distinguen**: el `UPDATE` corre igual |
| — | `hechos.ya_estaban_hechas` | **sin pareja**: el ensayo no sabe de puntos de control |

Restar los dos primeros habría dado **una diferencia inventada con aspecto de fallo real**, que es
la peor clase: la pantalla diría *«prometí 3 saltadas y salieron 0»* y no habría nada que arreglar.
`saltadas` pasa a llamarse **`ya_estaban_hechas`**, y se añade **`sin_primer_nombre`**, que no
existía — la subida no contaba las filas que no escriben nada, así que el informe no podía cuadrar.

**Se renombra el día en que alguien fue a compararlos y antes de que exista un cliente que los
lea.** Un test ensaya, importa el mismo fichero y comprueba los cinco números; si los dos caminos
se separan, se pone rojo ahí.

### 5.3 sexies El propio ensayo cometió el fallo que persigue

`efectos_colaterales` devolvía **`acudientes_tocados: 0`**, y era falso. La v1 del ensayo sólo
estudia al alumno —decisión del 19 sep— pero **la subida sí escribe acudientes y parentescos**. Ese
cero no decía *«no se tocan»*: decía *«no los he mirado»*, **y las dos cosas se leen igual en una
pantalla**.

Es exactamente lo que este módulo entero persigue —un número plausible que afirma algo que nadie
comprobó— cometido en la respuesta que viene a evitarlo. Ahora el campo **dice lo que pasa**
(`los_estudia_el_ensayo: false`, `los_escribe_la_subida: true`) y un test comprueba **que la clave
vieja no esté**, no sólo que la nueva sí: si alguien la devuelve «porque falta un número», se pone
rojo con el motivo al lado.

*Salió escribiendo el mensaje que avisaba al front de esa diferencia. Nadie lo estaba buscando, y
ningún test lo habría encontrado — porque el número era correcto: cero acudientes estudiados. Lo
que estaba mal era lo que ese cero decía.*

### 5.3 septies La promesa es condicionada, y la condición ahora se puede comprobar

El plan es cierto **para el fichero que se estudió**, y hay dos formas de que deje de serlo sin que
nada se ponga rojo:

1. **Que se suba otro fichero** — aunque sea el mismo con una celda corregida. Por eso la respuesta
   lleva la **`huella`** del libro que estudió, el mismo sha256 con el que el punto de control
   reconoce «el mismo archivo». Con ella delante, la pantalla puede decirlo antes de que nadie
   pulse; sin ella, los números siguen saliendo, sólo que son otros.
2. **Que la persona corrija algo en la pantalla y eso no llegue al importador.** Hoy no puede
   ocurrir porque la pantalla no deja cambiar nada. El día que se puedan corregir equivalencias,
   **la subida tiene que mandarlas y el importador aplicarlas**, o el plan deja de ser una promesa y
   pasa a ser una casualidad que se cumple mientras nadie toque nada.

*Lo segundo no es un «no hagas esto»: es que, si se hace sin lo otro, esto deja de ser un ensayo y
se convierte en una pantalla decorativa. Lo nombró la sesión del front al construir la pantalla.*

### 5.3 octies El lazo, conducido — y los dos relojes vistos desde fuera

`myvc-front-41` condujo el ciclo entero contra el docker, con permiso de Joseth para escribir. **El
informe final cuadra**: 32 filas leídas, 0 creados, 32 actualizados, 0 que no hacen nada — los
cuatro renglones, lo prometido contra lo hecho.

Y salió un hallazgo que no buscaba nadie: **el importador escribe en DOS RELOJES en la misma
petición.**

```sql
SELECT inicio FROM importaciones ORDER BY id DESC LIMIT 1;  -- 2026-09-20 17:37:03  UTC
SELECT MAX(updated_at) FROM alumnos;                        -- 2026-09-20 12:37:03  Bogotá
SELECT MAX(updated_at) FROM matriculas;                     -- 2026-09-20 12:37:03
SELECT MAX(updated_at) FROM acudientes;                     -- 2026-09-20 12:37:03
```

**Mismo segundo, cinco horas de diferencia.** Las dos zonas son las que este repo decidió —
`ImportarController` usa `Carbon::now('America/Bogota')`, que es la regla, y `importaciones` usa
`now()`, que es la excepción declarada en `RelojUnicoTest`— así que **nada está roto**.

**Lo que ha caducado es la mitad del motivo de esa excepción**, que decía *«sólo se restan entre sí,
nunca se comparan con otra tabla»*. Conduciendo, el front consultó qué había escrito la importación
usando la ventana de `importaciones.inicio` contra `alumnos.updated_at`, le salieron **cero filas**
mientras la pantalla decía 32, y estuvo a punto de anotar que no había escrito nada. *Molesta a
quien consulta la base, que es lo que hace todo el que viene a diagnosticar una importación.* La
evidencia queda al lado de la decisión, en el propio test.

**Y los acudientes, medidos:** 40 actualizados y 40 parentescos, **0 creados** —el fichero venía del
export y trae `id_acud1`, así que entra por la rama que actualiza—. O sea **80 filas escritas que el
plan no menciona**, que es exactamente lo que el aviso de §5.3 sexies vino a decir. Ahora con
números.

### 5.4 Lo que NO hizo falta construir

- **La escritura del escenario 6** (cambiar documento y tipo de un alumno existente, el caso
  RC→TI). **Ya existe**: `PUT alumnos/guardar-valor` escribe cualquier columna de `alumnos` pasando
  por `ColumnaSegura::exigir`. El front lo confirmó: la usa en siete sitios.
  **Con un aviso**: no comprueba que el documento nuevo no exista ya, así que antes de ofrecer «es
  el mismo» hay que llamar a `PUT alumnos/documento-check`.
- **Rutas de catálogo.** `ciudades` y `grupos` ya tienen `GET`; `tipos_documentos` no tenía ninguna
  y va **dentro del ensayo**, que ya los lee para poder decir «se guardaría TARJETA DE IDENTIDAD».

---

## 5.5 Que el importador obedezca — 21 sep 2026

Autorizado por Joseth el 21 sep. Es el paso que convierte el ensayo en una herramienta: hasta aquí
el coordinador **veía** qué iba a pasar y no podía cambiarlo.

**El ciclo entero, y lo fija un test que hace los cuatro pasos:** el ensayo avisa de lo que no
entiende → la persona decide → **el ensayo refleja la corrección** → la importación la escribe.

### Las equivalencias viven en `ImporterFixer`, y ahí está toda la garantía

Por esa clase pasan **los dos caminos**. Si se aplicaran en el controlador, la pantalla enseñaría un
plan **sin** las correcciones que la persona acaba de escribir y el resultado sería otro — el mismo
fallo que este módulo persigue, con un paso más de disimulo. De ahí salió además que
`estado_matricula` tuviera que pasar por el traductor, cosa que no hacía: lo leía el importador
directamente, así que una equivalencia puesta allí la habría visto la subida y **no** el ensayo.

### La forma la dibujó el front, y sus cuatro decisiones se adoptaron

| | |
|---|---|
| **La llave es `(columna, valor_original)`, nunca la fila** | Es el punto de todo esto: **una decisión y no ochocientas**. Con la fila como llave volverían las 800 con otro nombre |
| **`decision` siempre explícita, `a_revisar` incluido** | Ninguna ausencia significa nada: «no se ha decidido» tiene que distinguirse de «se decidió dejarlo». Es el `tipo_doc = 3` de la Fase 1, una capa más arriba |
| **`vacios` aparte de `vocabularios`** | «No lo entendí» y «no venía nada» son dos cosas, y la respuesta puede ser distinta |
| **`huella` dentro de las respuestas** | El que más valía — ver abajo |

**`a_revisar` no aplica nada y el aviso sigue**: es una decisión, no un olvido. Convertirlo en el
valor por defecto sería decidir por alguien y no decirlo.

### La huella cierra el agujero que este módulo llevaba rodeando

Alguien aprueba trece decisiones, **cambia una celda** y sube. Las respuestas siguen encajando
—casan por nombre de columna, no por contenido— y se aplicarían a un libro que nadie revisó: **los
números saldrían igual, sólo que serían otros.** Con la huella dentro, eso es un **422** en las dos
rutas. Sin huella se aceptan, porque exigirla rompería a quien mande instrucciones a mano y lo que
se busca es cazar el cambio silencioso, no imponer un formato.

### Y lo que NO se aplica se declara, con el motivo

`vacios`, `repetidos`, `duplicados` y `hojas` se aceptan, se guardan y **no se interpretan
todavía** — y la respuesta lo dice en `no_aplicadas` con su motivo. Aceptar una sección y callarlo
sería el mismo silencio que esto empezó quitando: la pantalla prometería algo que no ocurre.

Dos números distintos y los dos hacen falta: **`vocabularios_decididos`** —cuántas aprobó la
persona— y **`veces_que_se_usaron`** —cuántas fichas cambiaron—. Aprobar una equivalencia cuyo valor
no está en el fichero no es un fallo, pero tampoco es haberla aplicado.

### 5.5 bis Conducido contra el servidor — y el defecto que se esconde solo

`myvc-front-41` condujo el ciclo **sin pantalla de por medio**, llamando al ensayo tres veces, para
comprobar el contrato antes de construir controles encima. Con un libro cuyo tipo de documento
decía `CARNÉ DIPLOMÁTICO`:

| | `valores_no_reconocidos` | `totales` | `respuestas` |
|---|---|---|---|
| **sin decidir** | `CARNÉ DIPLOMÁTICO ×24` | crear 0 · actualizar 0 · **sin_cambios 32** | `null` |
| **con `usar_id: 1`** | *vacío* | crear 0 · **actualizar 24** · sin_cambios 8 | decididos 1 · **usadas 24** |
| **con la huella de otro** | — | — | **422** |

**El plan se movió: 24 filas pasaron de «no les cambia nada» a «se actualiza».** Ésa era la
pregunta que decidía si las equivalencias iban en el traductor o en el controlador, y la contesta
sin discusión.

> **Y el paso 1 enseña algo que no habíamos dicho en voz alta: EL DEFECTO SE ESCONDE SOLO CUANDO
> COINCIDE CON LO QUE YA HABÍA.**
>
> Sin la corrección, el plan dice *«a los 32 no les cambia ningún dato»* — **y es verdad**. El
> importador adivina Tarjeta de Identidad, esos alumnos **ya son** Tarjeta de Identidad en la base,
> así que no hay nada que escribir. El error no deja rastro **ni en la base ni en el plan**.
>
> Sólo se ve en el renglón de `valores_no_reconocidos`, que es la pieza que la Fase 1 tuvo que
> inventar y que no cambia lo que se guarda. *O sea que el aviso no sobra ni cuando el plan dice
> que no cambia nada* — y es justo entonces cuando es lo único que hay.

---

## 6. Lo que sigue abierto
- **La Fase 3** entera, que va después de la 2.
- Si las **asignaturas por grupo** entran detrás de esto, **con un aviso**: `POST
  asignaturas/copiar` inserta sin comprobar si el destino ya las tiene, así que un modelo que la
  llame dos veces «por asegurarse» **duplica el grupo entero**.
- Los **otros quince colegios**: los vocabularios son tablas por colegio y todo lo medido aquí es de
  `simonbolivar`, la copia de desarrollo. **Un colegio, no los dieciséis.**
