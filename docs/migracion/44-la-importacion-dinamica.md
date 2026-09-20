# 44 — La importación dinámica de alumnos

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
| **D2** | *Abierta a propósito — se decide mirando los mocks de la Fase 2.* | — |
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

## 5. El contrato de los avisos — lo que hoy se anota y no sale

La Fase 1 dejó `ImporterFixer::$avisos` lleno de lo que no se supo traducir. **Hoy ese array muere
en memoria**: `ImportarController::postAlgo` termina con `return 'Importados.';` —una cadena, no un
JSON— pase lo que pase. O sea que el cimiento está puesto y **la pantalla de la Fase 2 no tiene de
dónde leer**.

### 5.1 El radio de impacto, medido

Antes de tocar el cuerpo de una ruta viva hay que saber quién lo lee, y aquí lo leen **cuatro
sitios en dos repos** —`myvc_flutter` no llama a esta ruta—:

```bash
# desde ~/DESARROLLOS, 20 sep 2026
grep -rn "importar/algo" myvc_front myvc_front_2 myvc_flutter --include="*.ts" --include="*.dart"
```

| llamador | qué hace con el cuerpo en el caso de ÉXITO |
|---|---|
| `myvc_front` · `alumnos/AlumnosCtrl.ts:997` | `file.result = response.data` — **y ninguna plantilla lo pinta** |
| `myvc_front` · `informes/InformesCtrl.ts:746` | lo mismo, y tampoco se pinta |
| `app2` · `informes/tablero/tablero.ts:1258` | **lo ignora**: `complete:` avisa «Alumnos importados» |
| `app2` · `paginas/panel-alumnos/panel-alumnos.ts:1025` | **lo ignora**, igual |

**Ninguno de los cuatro lee el cuerpo cuando la importación va bien.** Los dos de AngularJS sí lo
pintan **en la rama de fallo** (`response.status + ': ' + response.data`), que no se toca.

*Esa tabla es la primera lectura y la hizo el backend; la confirma la sesión del front, que es
quien mide su propio radio de impacto.*

### 5.2 Las dos formas de entregarlos, con el precio delante

| | qué es | precio |
|---|---|---|
| **(a) Ampliar la respuesta** | `postAlgo` pasa de la cadena a `{importacion_id, filas, avisos: [...]}` | **0 rutas nuevas, 0 migraciones.** Cambia el cuerpo de una ruta viva —que hoy no lee nadie— y **los avisos son sólo los de esta tanda**: si la importación se reanuda, los de la anterior ya no están, y la reanudación es justo el caso en que más falta hacen |
| **(b) Persistirlos** | columna nueva en `importaciones` + una ruta de lectura | **1 migración + 1 ruta** (620 → 621) y las tres instantáneas. Sobrevive a la reanudación y a cerrar el navegador, que es lo que la Fase 2 va a necesitar de verdad |

**No se decide aquí y no corre prisa**: la forma exacta la fija lo que pidan los mocks. La sesión
del front deja la lista campo a campo en `mocks/importacion/lo-que-necesita-cada-pantalla.md`, y el
contrato se escribe **con esa lista delante** — diseñarlo antes es diseñarlo dos veces.

---

## 6. Lo que sigue abierto

- **D2**, a decidir mirando los mocks (**D6**).
- **La forma del contrato de avisos** (§5.2), a decidir con la lista del front delante. Si sale
  (b), es una ruta nueva y **la autoriza Joseth con el precio delante**.
- Si las **asignaturas por grupo** entran detrás de esto. Encajan en el agente que ya existe y con
  menos riesgo que la importación, **con un aviso**: `POST asignaturas/copiar` inserta sin
  comprobar si el destino ya las tiene, así que un modelo que la llame dos veces «por asegurarse»
  **duplica el grupo entero**.
- Los **otros quince colegios**: los vocabularios son tablas por colegio (`tipos_documentos`,
  `ciudades`) y todo lo medido aquí es de `simonbolivar`, la copia de desarrollo. **Un colegio, no
  los dieciséis.**
