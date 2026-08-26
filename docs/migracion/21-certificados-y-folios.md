# El folio, el consecutivo y qué habría que auditar de un certificado

**26 ago 2026.** Joseth: *«yo puse número de folio hace tiempo sin entender realmente qué
era lo que hacía»*. Este documento contesta eso con dos cosas y en este orden: **qué es un
folio en el archivo escolar colombiano** y **qué hace hoy tu sistema, medido**. Lo segundo
es lo que decide, porque no coinciden.

> **El límite de lo que hay aquí, y va delante y no en una nota al pie.** Lo de la norma
> sale de búsquedas y **no pude abrir los textos primarios**: el PDF del Decreto 180 de
> 1981 del MEN llegó ilegible y `funcionpublica.gov.co` rechazó el certificado. O sea que
> **lo normativo de abajo es orientación, no es una cita comprobada**, y antes de apoyar
> una decisión cara en ello hay que verlo en la fuente o preguntárselo a una secretaria de
> los quince — que además es quien sabe qué se hace de verdad. **Lo medido sí es firme**:
> sale de la base y del código.

---

## 1. Qué es un folio, y por qué el nombre importa

**Un folio es una hoja de un libro empastado.** No es un identificador del alumno ni un
número de documento: es **una posición física en un archivo**. Viene del mundo del papel,
donde un colegio lleva **libros reglamentarios** —el libro de matrículas, el de actas de
grado— cuyas hojas van **numeradas y refrendadas una a una** para que nadie pueda meter,
sacar o cambiar una hoja después.

De ahí sale la frase que aparece en los certificados colombianos:

> *«…consta en el **folio 237** del libro de matrículas del año 2018.»*

**Eso es lo que un folio hace: le dice a quien lee el papel dónde ir a comprobarlo.** Si
mañana alguien duda del certificado, va al archivo, abre el libro por la hoja 237 y ahí
está la matrícula. **Un folio sin libro detrás no significa nada** — y esto es exactamente
el problema de tu sistema, como se ve abajo.

### Y el otro número, que es distinto

El **«No.»** que va arriba del certificado no es el folio: es un **consecutivo de emisión**,
lo que en una oficina se llama un radicado. Cuenta **documentos que salieron**, no hojas de
un libro. Sirve para lo contrario: no para encontrar el dato, sino para **saber cuántos
papeles firmó el colegio y poder reclamar si aparece uno que no cuadra**.

**Son dos ejes distintos y los dos van en el papel:**

| | qué cuenta | para qué sirve |
|---|---|---|
| **Folio** | una hoja del libro | *«dónde está el respaldo de esto»* |
| **Consecutivo / No.** | un documento emitido | *«cuántos hemos firmado, y este cuál es»* |

### Qué exige la norma, hasta donde alcancé

- **Decreto 1290 de 2009, art. 16 — registro escolar.** El colegio debe llevar **un
  registro actualizado** de cada estudiante: identificación, valoración por grados y estado
  de la evaluación con sus novedades. **Ese registro, hoy, es tu base de datos.** No exige
  ni folio ni consecutivo.
- **Decreto 1290 de 2009, art. 17 — constancias de desempeño.** A solicitud del padre, el
  colegio **debe** emitir constancias de cada grado cursado con los resultados de los
  informes periódicos. **Tampoco exige numerarlas.**
- **Decreto 1860 de 1994, art. 51 — registro escolar de valoración.** El antecesor del
  anterior, en el mismo sentido.
- **Decreto 180 de 1981 — actas de grado.** Aquí sí: las actas de grado van en un **libro
  especial, foliado y refrendado hoja por hoja** por la secretaría de educación. **La
  foliación obligatoria vive en el mundo de los grados y los diplomas, no en el de las
  constancias de estudio.**

> **La conclusión práctica, y es la que ordena todo lo demás:** para una **constancia de
> estudio** el folio y el consecutivo son **buena práctica de archivo, no obligación
> legal**. Para un **acta de grado** el libro foliado sí es la figura seria. **Hoy tu
> sistema pone folio en la constancia y no lleva ningún libro de actas** — o sea, está
> haciendo el gesto en el sitio donde menos hace falta.

---

## 2. Qué hace de verdad tu sistema — medido

**Población:** la copia local de `simonbolivar`, **un colegio de los quince**, con **8 años
vivos** y **3.542 matrículas vivas**. No es el censo de los quince: es de dónde salen los
números de abajo.

### 2.1 `years.contador_certificados` — **esto sí es un consecutivo, y funciona**

| año | 2018 | 2019 | 2020 | 2021 | 2022 | 2023 | 2024 | 2025 |
|---|---|---|---|---|---|---|---|---|
| | 007 | 045 | 022 | 060 | 021 | 037 | 044 | **143** |

Se reinicia cada año y sube solo. **Es un consecutivo de certificados emitidos por año, y
está haciendo su trabajo** — en 2025 el colegio ha emitido 143.

### 2.2 `matriculas.nro_folio` — **cuatro poblaciones, y sólo una es un folio**

De 3.542 matrículas vivas, **2.102 tienen algo escrito** y 1.440 están vacías. Ese «algo»
son cuatro cosas distintas:

| | cuántas | qué son |
|---|---|---|
| **vacías** | **1.440** (41 %) | nunca se llenaron |
| **automáticas, suyas** | **1.612** (46 %) | el sistema escribió `año-alumno_id` sobre la fila de ese alumno: `2025-1234` |
| **automáticas, de OTRO** | **257** (7 %) | tienen la forma `año-N` pero **N no es su alumno**: son copias |
| **folios de verdad** | **233** (7 %) | números como `237`, `278`, `300`, `033` — y alguna basura (`2018`, `2018- 378`) |

**Sólo los 233 últimos son folios.** Los bare numbers van de 21 a 396 según el año, que es
justo el rango de hojas de un libro: alguien —una secretaria— estuvo copiando a mano la hoja
del libro de matrículas. **Y la práctica se murió sola**: 83 en 2021, 43 en 2022, **3 en
2023**, ninguno después.

**Los 1.612 automáticos no son folios.** `2025-1234` no es una hoja de ningún libro: es el
id del alumno con el año pegado delante.

**Y los 257 son peores que los 1.612**, que es lo que no se ve hasta contarlos por separado:
llevan la forma del automático **pero nombran a otro alumno**. `2023-156` está en cuatro
matrículas y **son cuatro alumnos distintos** —486, 658, 524 y 156—: sólo el último es a
quien ese número nombra; a los otros tres se les copió encima. En total **34 valores
repetidos sobre 71 matrículas** dentro del mismo año.

> **Lo que esto significa en una frase:** el **53 %** de las matrículas con folio imprime en
> el papel un número **que no apunta a ninguna parte**, y **257 de ellas imprimen un número
> que nombra a otro alumno**. Si alguien va a comprobar ese certificado, no hay libro ni hoja
> que abrir — y en el peor caso el número le lleva a la ficha de otra persona.

> **Y una corrección de método que va aquí porque es donde se lee:** la primera cuenta de
> esto decía *«490 escritos a mano, y ésos son los folios de verdad»*, y era falsa. Los 490
> eran **257 copias + 233 folios**, y se vio **abriendo una de las filas repetidas** en vez
> de fiarse del agregado: los cuatro `2023-156` no eran cuatro matrículas de un alumno, eran
> cuatro alumnos. **Un `GROUP BY … HAVING c>1` dice que algo se repite, no por qué**, y las
> dos explicaciones —«el mismo alumno dos veces» y «copiado a otro»— llevan a decisiones
> contrarias.

### 2.3 `years.contador_folios` — **no es un contador: es un interruptor**

Y esto es, casi seguro, **lo que pusiste hace tiempo**.

| año | 2018 | 2019 | 2020 | 2021 | 2022 | 2023 | 2024 | 2025 |
|---|---|---|---|---|---|---|---|---|
| | 237 | 278 | 158 | 249 | 249 | 249 | 249 | 249 |

**Congelado en 249 desde 2021.** Y al mirar quién lo usa:

- **Nadie lo incrementa.** No hay una sola línea en `app/` que le sume uno.
- **El endpoint que lo fija —`PUT bolfinales/cambiar-contador-folios`— no lo llama ninguna
  pantalla viva** de los cuatro clientes. Existe y está muerto.
- `YearsController:139` **lo copia del año anterior** al crear uno nuevo. Por eso 249 se
  arrastra desde 2021.
- Y el front lo lee **sólo para dos cosas**, las dos en
  `certificadoEstudioDir.html:37,39`:

      ng-class="{'hidden-print': year.contador_folios.length == 0}"

  o sea: **si está vacío, el bloque «Folio:» no se imprime; si tiene algo, se imprime.**

> **Su función real hoy es un sí/no: «¿este colegio imprime folio en el certificado?».** El
> valor `249` no lo lee nadie — daría exactamente igual que dijera `1` o `sí`. Es un
> interruptor disfrazado de contador, y por eso no había forma de entender qué hacía
> mirándolo.

### 2.4 Y el que rompe cualquier auditoría: **un número, N papeles**

El backend quema **un número por petición**. La respuesta trae **un año y N alumnos**. Las
dos versiones del front repiten sobre los alumnos y le pasan **el mismo año** a cada uno. Y
el papel imprime `No {{ year.contador_certificados }}`.

Hay **tres formas de llegar** a la pantalla, y las tres queman **un solo número**:

| desde dónde | cuántos papeles salen |
|---|---|
| la ficha de un alumno | **1** — *y aquí el consecutivo es correcto* |
| varios alumnos seleccionados | **N** — todos con el mismo número |
| el grupo entero | **todos los del grupo** — todos con el mismo número |

> **Abrir el certificado de periodo de un grupo de 37 quema un número e imprime 37 papeles,
> los 37 con el mismo «No. 144» encima.**

**Esto no se puede auditar sin decidirlo antes**, y es la pregunta que hay que contestar:

### ⚠️ La pregunta que lo decide todo

> **¿El «No.» numera el papel o numera la emisión?**

- **Si numera el papel** —lo normal en un libro radicador—, hoy está mal en dos de los tres
  caminos, y hay que dar **N números** por apertura.
- **Si numera la emisión** —«la tanda 144: 37 constancias del grupo 5B»—, hoy está bien y lo
  que sobra es que cada papel lo lleve impreso como si fuera suyo.

**Esto no lo decide el código: lo decide qué escribe tu secretaria en el libro.** Y la
pregunta exacta que hay que hacerle es *«cuando sacas constancias para un grupo entero,
¿anotas un renglón o treinta y siete?»*.

---

## 3. Lo que ya está hecho, para que no se cuente dos veces

Desde el 26 ago (`cert-2`), **mover el consecutivo deja rastro** en `auditoria`, y con dos
sucesos distintos a propósito:

- `Quemo un consecutivo de certificado: 143 -> 144` — alguien **abrió** el certificado.
- `Fijo a mano el contador de certificados: 143 -> 815` — alguien **lo escribió**.

Antes de eso no quedaba **nada** escrito, y eso importaba más de lo que parece: **el único
indicio de que se emitió un certificado era que un contador había subido**, así que quien
movía el contador a mano borraba la única cuenta que había.

**Lo que sigue sin poderse contestar:** *«¿cuántos certificados emitimos este año y a
quién?»*. El rastro dice **quién movió el número**; no dice **a quién se le entregó el
papel**.

---

## 4. **La decisión de Joseth, y lo que entró el 26 ago**

> *«Hay colegios a los que no les importa llevar esos contadores o folios; que tengan la
> opción. Los que sí, que funcionen con la opción A.»*

**Y al ir a implementarlo salió que los dos interruptores ya existían**, escondidos en el
vacío de los dos `varchar`. La misma plantilla oculta las dos casillas cuando la columna
está vacía:

    ng-class="{'hidden-print': year.contador_certificados.length == 0}"   (el «No.»)
    ng-class="{'hidden-print': year.contador_folios.length == 0}"         (el «Folio:»)

Eso cambia el coste entero: **esto no estrena una conducta, le pone nombre a la que había**.

### 4.1 Los dos interruptores, explícitos

`2026_08_26_100000_interruptores_de_certificados` añade a `years`:

| columna | gobierna |
|---|---|
| `usa_consecutivo_certificados` | el «No.» del certificado |
| `usa_folio_certificados` | la casilla «Folio:» |

**Van en `years` porque `years` ES la configuración del colegio** en este sistema —lleva
`nombre_colegio`, `codigo_dane`, `resolucion` y una docena de interruptores `tinyint(1)` del
mismo estilo—, y `YearsController` los copia al año siguiente, cosa que la migración también
cablea: sin eso, el certificado del año nuevo saldría sin número.

**Y no hay valor por defecto**, que es lo que hace esto seguro en quince producciones:

    usa_consecutivo_certificados = (contador_certificados <> '')
    usa_folio_certificados       = (contador_folios       <> '')

Cada colegio arranca **exactamente como está hoy**. El día del despliegue **ninguno imprime
nada distinto**.

### 4.2 Lo que sí cambia, y es el arreglo

**Un colegio que no imprimía el número seguía gastándolo.** El front ya ocultaba el «No.»,
pero el backend incrementaba igual en cada apertura: el contador subía solo y nadie lo
miraba. Desde hoy, **con el interruptor apagado no se quema nada** — y los dos endpoints que
fijan un contador contestan **409** en ese colegio.

*(409 y no 403: no es que quien llama no pueda, es que la operación no aplica aquí. Y no 422,
porque el cuerpo puede estar perfectamente bien.)*

### 4.3 La opción A, completa: **el folio ya no se fabrica**

Se quitaron **los siete sitios** que escribían `año-alumno_id`:

| dónde | cuántos |
|---|---|
| `Models\Matricula` | **4** (las ramas de papelera, cambio de grupo, nueva y el `catch`) |
| `AlumnosController` · `MatriculasController` | 1 + 1 |
| `GET folios/iniciar` — el que llenaba **todos** los huecos del año de una sentencia | **contesta 409** |

`folios/iniciar` **no lo llamaba ningún cliente**: revisados los siete árboles de
`~/DESARROLLOS` y **cero ficheros la nombran**. No se borra la ruta —la regla de la casa es
que una ruta que existe se documenta— pero deja de fabricar.

Lo ata `FolioQueNoSeFabricaTest`, y lo que de verdad lo protege es su **tercer test**: un
barrido de los 224 ficheros PHP de `app/` que cae si alguien vuelve a escribir la línea en
cualquiera de los siete sitios. **Con control positivo dentro**, porque un barrido que no
encuentra nada puede estar limpio o estar roto y las dos cosas se leen igual —se le dan las
dos líneas que existieron y se exige que las cace, más una que **no** debe cazar.

> **Y ahí el detector se equivocó primero, que es la parte que hay que leer.** La primera
> versión pedía `nro_folio` seguido de `=` **o de una coma**, y la coma cazaba `m.nro_folio,`
> dentro de la lista de columnas de dos `SELECT` que **leen** el folio y no lo fabrican
> (`ActasEvaluacionController:797`, `AlumnosController:654`). Dos falsos positivos con el
> aspecto exacto del problema. Lo que separa leer de fabricar es **construir el valor**, así
> que la condición pasó a ser la concatenación —`CONCAT(...)` o `. '-' .`—. *El primer sitio
> donde mirar cuando el número sale raro es el detector.*

### 4.4 Lo que NO entró, y no es olvido

- **Los 1.869 folios ya escritos** —1.612 fabricados + 257 que nombran a otro alumno— **se
  quedan**. Borrarlos **cambia lo que hoy sale impreso**, y es un `UPDATE` y una decisión de
  Joseth, no una migración.
- **La tabla de emitidos (propuesta B)** sigue esperando la pregunta del §2.4.

---

## 5. Las tres propuestas, y dónde quedó cada una

Se pueden hacer **en este orden** y cada una vale por sí sola. **No hace falta elegir una:
hace falta decidir hasta dónde llegar.**

### Propuesta A — «que el papel no mienta» · **HECHA el 26 ago** (§4.3)

**El problema que resuelve:** hoy 1.612 certificados imprimen un folio inventado y 1.440
imprimen la casilla vacía.

1. **Dejar de fabricar folios automáticos.** Que `nro_folio` se llene **sólo a mano**, y
   cuando esté vacío **la casilla no se imprima**. Un folio en blanco es honesto; `2025-1234`
   no lo es. *(Los 1.869 que ya están escritos —1.612 + 257— se pueden dejar quietos o
   limpiar; limpiarlos es un `UPDATE` y **una decisión tuya**, porque borra lo que hoy sale
   impreso.)*
2. **Renombrar el interruptor.** `contador_folios` pasa a llamarse lo que es —*«imprimir
   folio en el certificado: sí/no»*— y deja de parecer un número que alguien tiene que
   mantener.
3. **Retirar `cambiar-contador-folios`**, que no lo llama nadie y es una puerta abierta al
   consecutivo del colegio.

**Coste:** un rato de backend y un rato de front. **Cero migraciones.**
**Lo que NO resuelve:** los 233 folios buenos siguen sin libro detrás, y la pregunta del §2.4
sigue sin contestar.

### Propuesta B — «el libro radicador» · una tabla, y contesta la pregunta de auditoría

**El problema que resuelve:** *«¿cuántos certificados emitimos este año y a quién?»*.

Una tabla nueva —`certificados_emitidos`— con **una fila por papel**: a qué alumno, qué año
y periodo, qué número le tocó, **quién lo emitió, cuándo y desde dónde**. Es el libro
radicador de toda la vida, en la base.

**Con eso el colegio puede contestar, por primera vez:**
- cuántas constancias salieron este año, y de qué grupos;
- **si un número se repitió** —hoy no se detecta ni cuando pasa—;
- si alguien pidió la misma constancia cinco veces;
- y cuando alguien llegue con un papel raro, si salió de aquí.

**Coste:** una migración en los quince, y **la pregunta del §2.4 hay que contestarla
antes**, porque decide si la clave de la tabla es un papel o una apertura.
**Lo que hay que decidir de paso, y no tiene respuesta buena:** **el histórico no existe y
no se puede reconstruir.** El libro empieza el día que se despliegue. Las 143 constancias de
2025 no se pueden meter dentro: no queda de ellas más que el número 143.

### Propuesta C — «el libro de verdad» · la seria, y es sobre todo trabajo del colegio

Si lo que quieres es el archivo escolar bien llevado, y no sólo la constancia:

1. **El folio vuelve a apuntar a algo.** Se decide qué libro es —el de matrículas— y el
   número que va en `nro_folio` es **su hoja**, escrita por quien matricula, no por el
   sistema. El sistema puede ayudar: **avisar si se repite dentro del mismo año**, que hoy
   pasa 34 veces sobre 71 matrículas y no lo detecta nadie.
2. **El libro de actas de grado**, que es donde la foliación **sí** es la figura seria y hoy
   no existe en el sistema.

**Coste:** esto es **más un procedimiento del colegio que software**. Y aquí hay que ser
honesto: la práctica de copiar el folio a mano **ya se murió sola en 2023**. Reactivarla
exige que alguien en secretaría se comprometa a hacerlo — si eso no va a pasar, **la A es
mejor que la C**, porque una casilla vacía es mejor que una llena de mentira.

---

## 6. Lo que queda, y lo que necesito de ti

**La A está hecha** (§4). Lo que sigue abierto:

| | qué | de quién es |
|---|---|---|
| **B** | la tabla de certificados emitidos | **bloqueada** por la pregunta del §2.4 |
| **C** | que el folio vuelva a apuntar a una hoja real | del colegio, no del software |
| — | limpiar los 1.869 folios inventados que ya están escritos | de Joseth: cambia lo impreso |

**Y las dos preguntas de una frase, que te las contesta una secretaria en dos minutos:**

1. **Cuando secretaría saca constancias para un grupo entero, ¿anota un renglón o treinta y
   siete?** — decide si la clave de la tabla de emitidos es **el papel o la emisión**, y
   construirla sin eso es construirla mal.
2. **¿Este colegio quiere seguir imprimiendo folio en las constancias?** — ahora ya es un
   interruptor, así que la respuesta se aplica sin tocar código: se enciende o se apaga. Y si
   la respuesta es *«que lo lleve quien quiera»*, **ya está resuelto**: cada colegio decide.

> **Sobre la C, y va escrito porque es la tentación:** la práctica de copiar el folio a mano
> **se murió sola en 2023**. Reactivarla exige que alguien en secretaría se comprometa a
> hacerlo. Si eso no va a pasar, **apagar el interruptor es mejor que la C** — una casilla que
> no se imprime es mejor que una que imprime un número que no lleva a ninguna parte.


---

## 7. La tabla de certificados emitidos — **plan, sin código y sin migración**

Joseth, 26 ago 2026, sobre la pregunta del §2.4: **«diseña asumiendo un número por papel»**.
Esto es el plan para que lo apruebes o lo tumbes; **no hay una línea escrita** y la migración
no se toca hasta que lo digas.

### 7.1 Lo primero, porque cambia el coste: **esto no es sólo una tabla**

Con un número por papel, **el backend tiene que dar N números por emisión y hoy da uno**. Eso
arrastra tres cosas que la tabla sola no arregla:

| | qué cambia |
|---|---|
| el incremento | de `contador += 1` a **`contador += N`**, repartiendo `n+1 … n+N` entre los alumnos **en el orden en que se imprimen** |
| la respuesta | el número deja de vivir en `year.contador_certificados` y pasa a **cada alumno** |
| el papel | la plantilla imprime `alumno.consecutivo` en vez de `year.contador_certificados` — **cambio del front, en las dos aplicaciones** |

**O sea que mueve la forma de una respuesta y toca a los cuatro clientes.** No es caro de
escribir; es caro de coordinar, y esa es la parte que hay que ver antes de empezar.

### 7.2 Y una cosa que hay que arreglar ANTES, o esto multiplica un fallo por 37

**Hoy el disparador de la quema es abrir la pantalla.** Recargar el «Certificado periodos»
gasta un número. Con un número por papel, **recargar gasta N** — treinta y siete de golpe en
un grupo de 37, cada vez que alguien pulsa F5.

> **La numeración por papel no se puede montar encima del disparador actual.** Primero el
> número se quema al **emitir** —un botón explícito, «Emitir e imprimir»— y no al **mirar**.
> Es un cambio del front con una condición del backend: que `aumentar_contador` deje de
> llegar en la carga del informe y llegue en una acción aparte.
>
> Esto ya es cierto hoy, sólo que hoy cuesta un número y nadie lo nota. **Es la razón por la
> que la §231 no pudo contestar cuántos se han quemado**: no hay forma de distinguir un
> número gastado por mirar de uno gastado por emitir. Con la tabla **sí la hay** —lo emitido
> queda escrito— pero sólo si el disparador es la emisión.

### 7.3 La tabla

`certificados_emitidos`, **una fila por papel**:

| columna | por qué |
|---|---|
| `year_id`, `periodo_id` | el periodo va nulo en el certificado de año |
| `tipo` | `'periodo'` \| `'anio'` — son dos documentos distintos y hoy comparten pantalla |
| `alumno_id`, `alumno_nombre`, `grupo_id` | **el nombre congelado dentro**, como hace `Auditoria`: el alumno se puede borrar y el papel sigue existiendo |
| `consecutivo` (int) | el número, **entero** |
| `consecutivo_texto` (varchar) | **lo que se imprimió de verdad**, con su relleno de ceros. Son dos columnas y no una a propósito: el entero es para contar y ordenar, el texto es para comparar contra un papel que alguien trae en la mano |
| `emitido_por`, `emitido_por_nombre`, `emitido_en` | quién y cuándo, con el reloj único de la [18 §4.5](18-auditoria.md) |
| `ip`, `ruta` | lo mismo que ya resuelve `Auditoria`, y por el mismo motivo |
| `anulado_en`, `anulado_por`, `anulado_motivo` | **hace falta y no es opcional**: un papel se atasca en la impresora y ese número no se puede reusar ni desaparecer. Se anula, con motivo. **No se borra la fila** |

**Sin `deleted_at`.** Un libro radicador no pierde renglones: por eso la anulación es un
estado y no un borrado. Es la misma regla que la [§4.4 de la 18](18-auditoria.md), y conviene
que la fije un test como allí.

### 7.4 Lo que sí contesta, que es la prueba de que vale la pena

- *«¿Cuántas constancias emitimos este año, y de qué grupos?»*
- *«¿A quién se le emitió el número 144?»* — hoy **no tiene respuesta ni con acceso total a
  la base**.
- *«¿Este número se repitió?»* — hoy **no se detecta ni cuando ocurre**.
- *«Este papel que me traen, ¿salió de aquí?»*

### 7.5 Lo que hay que decidir antes de escribir la migración

1. **El histórico no existe y no se puede reconstruir.** De las 143 constancias de 2025 no
   queda más que el número 143. El libro **empieza el día que se despliegue**, y eso hay que
   poder explicárselo a un colegio que pregunte por marzo. *(Alternativa costeada: sembrar
   una fila «apertura del libro» con el consecutivo de partida, para que el primer número
   nuevo no parezca el primero de la historia.)*
2. **El relleno de ceros** ([cert-1 §6.1](noche-2026-08-25/cert-1.md)), que sigue abierto
   desde el 25. Con una sola columna daba igual; **con `consecutivo_texto` hay que decidir
   qué se escribe**, y ahí el ancho cambia al pasar de 999.
3. **Los colegios con el interruptor apagado no emiten nada** y su tabla queda vacía. Es
   correcto y es gratis, pero conviene decirlo antes de que alguien lo lea como un fallo.

### 7.6 El orden que propongo, y por qué

**No es tabla → números → botón. Es al revés:**

1. **El botón de emitir** (§7.2), que es lo único que hace que numerar por papel no sea una
   trampa. Sin código de tabla: sólo mover el disparador.
2. **Los N números y la fila por papel**, juntos — la tabla sin los N números no sirve de
   nada, y los N números sin la tabla son una regresión.
3. **La pantalla del libro**, que es lo que el colegio va a querer en cuanto exista.

**El 1 es barato y ya arregla algo que hoy está mal.** Si esto se queda a medias, que se
quede después del 1 y no después del 2.
