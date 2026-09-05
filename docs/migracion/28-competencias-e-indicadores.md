# 28 · Competencias, indicadores y la plantilla del colegio

> **Estado: la Entrega 0 y la Entrega 1 están ESCRITAS; las demás siguen siendo
> propuesta.** Este documento existe para que la decisión se tome sobre algo medido
> y no sobre una intuición, y para que las **siete entregas** que propone se puedan
> aprobar, aplazar o cancelar **por separado**. (Eran tres el día que se escribió;
> las 4, 5 y 6 nacieron de las decisiones de Joseth de esa noche y la 7 de medir qué
> hace preescolar con la rejilla — §3.2.)
>
> | entrega | estado |
> |---|---|
> | **0** — las subunidades del año nuevo | **HECHA** el 2 sep 2026 |
> | **1** — la plantilla sale de phpMyAdmin | **HECHA** el 4 sep 2026, **menos el candado** (§5.1.e) |
> | **7(a)** — el alcance de la plantilla | **HECHA** el 4 sep 2026, con la Entrega 1 |
> | 2, 3, 4, 5, 6, 7(b–d) | propuesta |
>
> **Lo que la Entrega 1 dejó fuera va dicho aquí arriba y no en una nota al pie**:
> el candado del docente (decisión 5) **no entró**. No es un olvido y no es que
> cueste: cambia respuestas de éxito por **403 en nueve rutas que ya existen**, lo
> notan los tres editores, y la propia §5.1.e exige contar antes el censo de
> `por_defecto = 1` **en los diecisiete colegios** — que no se puede correr desde
> una sesión de desarrollo, hace falta el servidor. El orden que pide el documento
> («el candado entra con la Entrega 1, no después») **tiene razón y aun así no se
> puede cumplir hoy**, así que se dice en vez de saltárselo en silencio.
>
> El 4 sep 2026 Joseth aprobó el lote **Entrega 1 + 7(a)** con el precio delante:
> nueve rutas nuevas, dos migraciones, el arreglo de `YearsController` y el router
> de **568 a 577** — contado con `route:list --json`, no sumado.
>
> Lo pidió Joseth el 2 sep 2026, con esta frase: *«creo que el enfoque que le di al
> sistema desde el principio no fue el correcto»*. Este documento dice **en qué
> tenía razón, en qué no, y qué cuesta arreglar cada parte**.

---

## 1. Lo que hay hoy, contado sobre el código

```
asignatura  (materia × grupo × año)
  └── unidad        (periodo_id, asignatura_id, definicion, porcentaje, orden)
        └── subunidad   (unidad_id, definicion, porcentaje, nota_default, orden)
              └── nota      (subunidad_id, alumno_id, nota int)
```

- **La definitiva** es `Σ (nota × %subunidad × %unidad / 10000)`, **sin normalizar**
  por la suma de los porcentajes. Está en `App\Services\DefinitivasDeAsignatura` y
  la regla de no normalizar es deliberada (§9.3 de
  [10-definitivas.md](10-definitivas.md)): una asignatura mal repartida tiene que
  **verse rara en la planilla**, no taparse.
- **El colegio renombra las dos capas**, y ya lo hace: `years` lleva seis columnas
  para eso — `unidad_displayname`, `unidades_displayname`, `genero_unidad`,
  `subunidad_displayname`, `subunidades_displayname`, `genero_subunidad`. El front
  ya las lee; el comentario que encabeza `UnidadesCtrl.ts` lo dice sin rodeos:
  *«Una subunidad — «logro», «indicador», según cómo lo llame cada colegio»*.
- **La plantilla existe y no tiene pantalla.** `unidades_por_defecto` (por
  `year_id`) y `subunidades_por_defecto` (colgada de la anterior). Hoy se editan a
  mano en la base.
- **Se siembra en una lectura.** `UnidadesController::getDeAsignaturaPeriodo`
  (`app/Http/Controllers/UnidadesController.php:146`) copia la plantilla a
  `unidades`/`subunidades` la primera vez que alguien abre la pantalla, y sólo si
  se cumplen las dos condiciones: `count($unidades) == 0` **y**
  `User::permiteEditarNotas(...)`. Ese `GET` que escribe está fichado en
  [05 §16](05-codigo-muerto-y-roto.md) y por eso lleva `auth.personal`.

### 1.bis · Dos hallazgos del código, y el primero es un fallo vivo

**(a) Al crear un año nuevo se copian las unidades por defecto y NO las
subunidades.** `YearsController` (línea 250) tiene su `/// COPIAREMOS LAS UNIDADES
POR DEFECTO` con un solo `INSERT`, sobre `unidades_por_defecto`. Las filas nuevas
nacen con **ids nuevos**, así que `subunidades_por_defecto.unidad_defec_id` sigue
apuntando a las unidades **del año anterior**. Resultado en el año nuevo: la
plantilla trae unidades **vacías**, y cada docente recibe una rejilla con
contenedores sin casillas y sin ningún error en el log.

**La población del barrido de código**, que es la mitad que sí se puede contar aquí:
sobre **235 ficheros `.php` de `app/`**, `subunidades_por_defecto` aparece en
**uno** —`UnidadesController:161`, el sembrador, y es una **lectura**— y
`unidades_por_defecto` en **dos**: ese mismo y `YearsController` (líneas 251 y 254,
el `SELECT` y el `INSERT`). Los cinco bloques `/// COPIAREMOS` de `YearsController`
son **el único camino** que crea un año a partir de otro, y `INSERT INTO
subunidades_por_defecto` **no existe en `app/`**: sólo en dos tests, que se montan su
propio escenario. O sea que no hay un segundo sitio que repare esto por detrás.

**La otra mitad —cuántos años y cuántos colegios están hoy así— NO se sabe, y no se
puede saber desde aquí.** Medido en la base de desarrollo `simonbolivar` del
contenedor: **9 años, y los nueve con 0 unidades por defecto y 0 subunidades**. Eso
no confirma ni desmiente nada — el fallo sólo muerde cuando el año del que se copia
**tiene** plantilla, y aquí no la tiene ninguno. Lo mismo dice el seed de tests, que
tampoco la trae (`UnidadesTest:443` se la inventa antes de poder probar la siembra).

> **Se dice «no se sabe» y no «ninguno»**, que es la diferencia que hace archivar un
> asunto: *«revisé nueve años y ninguno tenía plantilla»* no es *«esto no le pasa a
> nadie»*.

Es la Entrega 0 de este documento y **conviene arreglarla aunque todo lo demás se
cancele**.

#### Cómo se cuenta la población de verdad, el día del despliegue

Con el bucle de [DESPLIEGUE.md](../DESPLIEGUE.md) sobre
`/home/micolev1/*.micolevirtual.com/8myvc` —**diecisiete carpetas: dieciséis colegios
y `demo`**—, y esta consulta, que es de sólo lectura:

```sql
SELECT y.id AS year_id, y.year, y.actual,
       COUNT(DISTINCT u.id) AS uds_defecto,
       COUNT(s.id)          AS subuds_defecto
FROM years y
LEFT JOIN unidades_por_defecto u    ON u.year_id = y.id AND u.deleted_at IS NULL
LEFT JOIN subunidades_por_defecto s ON s.unidad_defec_id = u.id AND s.deleted_at IS NULL
GROUP BY y.id, y.year, y.actual
ORDER BY y.year;
```

**Un año afectado es el que tiene `uds_defecto > 0` y `subuds_defecto = 0`.** Y hay
un segundo síntoma que conviene contar a la vez, porque es el que dice si el fallo ya
ocurrió: **subunidades por defecto cuya unidad padre es de otro año que el suyo** —
huérfanas funcionales, vivas y apuntando al año viejo.

El resultado del barrido se escribe aquí **con la población delante**: «X de 17
colegios, Y años afectados de Z revisados». Un «ninguno» sin ese denominador no vale.

**(b) Hay un segundo par de tablas de plantilla, muerto.** `default_unidades` y
`default_subunidades` no las lee **nadie** en `app/`, y llevan justo las columnas
que esta propuesta necesita: `can_change_definicion`, `can_change_porcentaje`,
`can_change_orden`, `show_definicion`, `profesor_id`. Alguien diseñó esta misma
mejora y no la terminó. **No se construye encima de ellas** —no sabemos qué hay
dentro en los dieciséis colegios— pero la intención se recoge tal cual en la
Entrega 1.

### 1.ter · Un tercer hallazgo, y éste ya está impreso en boletines

**`frases_asignatura.frase` es `varchar(255)` y MySQL la trunca en silencio.** Es la
tabla donde vive la frase que sale en el boletín del alumno, y la §5.3 propone marcar
ahí los indicadores. Medido en la base de desarrollo `simonbolivar` del contenedor:

| | |
|---|---|
| filas en `frases_asignatura` | **12.294** |
| de catálogo (`frase_id`) | 698 |
| **escritas a mano** | **11.596** |
| **con exactamente 255 caracteres** | **626** — el 5,4 % de las escritas a mano |
| `sql_mode` | `NO_ENGINE_SUBSTITUTION` — **sin `STRICT_TRANS_TABLES`** |

**255 exactos no es una casualidad, es la firma de un corte**, y se lee en los datos:
las frases acaban a mitad de frase y sin punto — *«…para desarrollar las competencias
p»*, *«…de las funciones seno, coseno, tangente»*. Sin `STRICT_TRANS_TABLES` MySQL
**no da error**: corta y devuelve 200, así que el docente vio su frase guardada y el
acudiente la recibió cortada. Es la familia de `tools/respuestas-que-mienten.py`.

Y es una **anomalía, no un diseño**: las otras dos columnas del mismo camino
—`frases.frase` (el catálogo) y `definiciones_comportamiento.frase`— **son `text`**.
Sólo ésta se quedó en 255. El catálogo, que sí es `text`, tiene un máximo real de
**102 caracteres**: nadie se ha topado con su límite porque no lo tiene.

> **La población de los diecisiete NO se sabe**, igual que en §1.bis. Un colegio, el
> de la copia de desarrollo, tiene 626. Se cuenta el día del despliegue con:
>
> ```sql
> SELECT COUNT(*) AS total,
>        SUM(CHAR_LENGTH(frase) = 255) AS cortadas
> FROM frases_asignatura WHERE deleted_at IS NULL;
> ```
>
> Y se escribe aquí **con el denominador delante**: «X cortadas de Y, en Z de 17».

**El arreglo es un `ALTER` de una línea, y Joseth lo autorizó el 2 sep 2026**
(*«cambia el tipo de campo como necesites»*). `varchar(255)` → `text` no pierde ningún
dato, y la tabla **no tiene más índice que la primaria** (comprobado en
`database/schema/mysql-schema.sql`), así que no hay ningún índice que reconstruir.

> **HECHO esa misma noche**, en la rama `fix/frases-asignatura-text`:
> `2026_09_05_100000_frase_del_boletin_en_text` y
> `tests/Contrato/FraseLargaEnElBoletinTest`. **El test se vio rojo antes que verde**
> —contra la base sin migrar devolvía *«el POST devolvió la frase cortada a 255 de
> 388»*—, y mira el viaje de ida y vuelta en los tres sitios donde la frase puede
> perderse: lo que devuelve el `POST`, lo que devuelve el `GET` de después y lo que
> queda en la tabla. **El 200 no sirve de testigo**: nunca faltó, y es justo lo que
> hizo que el fallo viviera años.
>
> `text` y no un `varchar` más ancho **porque es lo que ya son sus dos hermanas**, y
> el `COLLATE` va escrito a mano: la tabla es `utf8mb4_unicode_ci` y las hermanas
> `utf8mb4_general_ci`, así que un `MODIFY` sin decirlo cambiaría la collation de las
> comparaciones en silencio.

> **Pero NO repara hacia atrás.** Las 626 frases ya cortadas **no vuelven**: el texto
> que el docente escribió de más nunca llegó a la base. El `ALTER` impide las
> siguientes, no recupera las de antes — igual que la Entrega 0.
>
> **Y no se avisa: Joseth decidió el 2 sep 2026 arreglar sólo hacia adelante.** Se
> propuso contarlas en los diecisiete y pasarle al colegio la lista de qué boletines
> llevan una frase cortada; la decisión fue que no. La consecuencia, escrita aquí para
> que no haya que descubrirla dentro de dos años: **cada reimpresión de un boletín de
> un año pasado seguirá saliendo con la frase cortada a mitad de palabra**, y ni el
> docente ni el acudiente van a saber por qué. La consulta de arriba queda escrita por
> si algún día se quiere contar.

**Y una nota para cuando se construya encima**, porque el marcado de indicadores de
§5.3 usaría este mismo endpoint: `FrasesAsignaturaController::postStore` sólo pasa por
`auth.personal` y `User::pueden_editar_notas`, que mira **el tipo de usuario y el
interruptor del periodo** ([User.php:377](../../app/User.php)) — **no comprueba que la
asignatura sea del profesor ni que el alumno esté en ella**. Está fuera del encargo de
este documento; queda fichado aquí porque una entrega que multiplique las llamadas a
ese endpoint multiplica también lo que no comprueba.

---

## 2. Cómo lo hacen otros sistemas

Master2000 se define por **modelos de evaluación**, en plural: *«calificación
numérica o descriptiva, **procesos**, **procesos con logros**, **logros con
procesos**»*. O sea que tiene **dos ejes que se combinan**: los *procesos* —los
contenedores con porcentaje, lo que aquí es la `unidad`— y los *logros* —los
textos—. En su pantalla de seguimiento, el acudiente *«ve cómo la asignatura se
divide en porcentajes, y **debajo de las notas**, los logros de la asignatura»*:
porcentajes y logros se **enseñan juntos y se guardan aparte**.

El marco normativo colombiano (Decreto 1290 de 2009, y los SIEE que cada colegio
publica) dice la relación entre las dos palabras que Joseth ve mezcladas:

> *«Para cada área y/o asignatura, y **para cada competencia**, se establecen
> **indicadores de desempeño** referidos a lo conceptual, procedimental y
> actitudinal.»*

Y sobre **quién decide los porcentajes** —que es la mitad del pedido—: los
componentes y sus pesos («Cognitivo-procedimental 60 %, Actitudinal 20 %, Prueba
Saber 20 %», o «34/33/33») los fija el **consejo académico en el SIEE**, no el
docente en su materia.

Fuentes: [Master2000 · sistema académico](https://master2000.net/web/s.academico.html) ·
[Decreto 1290 de 2009](https://www.mineducacion.gov.co/1621/articles-187765_archivo_pdf_decreto_1290.pdf) ·
[SIEE de ejemplo, Master2000](https://www.master2000.net/recursos/menu/38/1582/mper_arch_34637_SIEE%20_%202025_%20Version%20Definitiva.pdf) ·
[Criterios de evaluación por componentes](https://institucioneducativalaunion.edu.co/gestion-academica/sistema-de-evaluacion/articulo-5-criterios-de-evaluacion/)

---

## 3. El diagnóstico

Joseth tiene razón, pero el problema **no es la jerarquía de dos niveles**: es que
**en la misma fila viven dos cosas con dueños distintos**.

Una `subunidad` es hoy, a la vez:

1. **una columna de la planilla** — un hueco con un porcentaje donde va un número, y
2. **la frase que se imprime en el boletín** — «Identifica los tipos de triángulo…».

Esas dos cosas no se parecen en nada:

| | la columna | la frase |
|---|---|---|
| quién la decide | el docente | el colegio / el jefe de área |
| a qué se pega | a **una** asignatura (materia × **grupo**) | a la materia y al **grado** — sirve para los ocho grupos de 6.º |
| cuántas hay | 3–5 | 8–15 |
| lleva porcentaje | sí, y mueve la definitiva | no |
| cambia entre grupos | sí | no debería |

Mientras las dos vayan en la misma fila, **todo lo que es del colegio hay que
teclearlo una vez por asignatura**, y un porcentaje mal puesto en una de ellas
mueve una definitiva sin que nadie lo vea. Eso es lo que se siente como
«complicado para el docente».

Y falta una capa entera: **las competencias no existen en la base**. Lo más
parecido es `frases_asignatura`, que es texto libre **por alumno** — otra cosa.

### 3.1 · Lo que NO está mal, y conviene no tocar

- **Los nombres.** Renombrar `unidad`/`subunidad` es cosmético, mueve las
  instantáneas de contrato, los dos fronts y la app, y no resuelve nada: el colegio
  **ya** las renombra con las seis columnas de `years`. Lo que hay que cambiar es
  **de quién son las filas**, no cómo se llaman.
- **La jerarquía con porcentajes.** Es exactamente el eje «procesos» de
  Master2000, y es lo que produce la nota. Se queda.
- **Que la nota cuelgue de una fila materializada.** Ver §4.

---

### 3.2 · Preescolar no es otro modelo: es un piso de más

Medido el 2 sep 2026 sobre la base de desarrollo `simonbolivar` — **un colegio de
los diecisiete**, años 2018–2024. (2025 y 2026 salen a cero en *todos* los niveles
porque la copia de desarrollo viene recortada, no porque hayan dejado de calificar.)

**Las dimensiones ya son materias.** Preescolar tiene tres grados —Prejardín,
Jardín, Transición— y sus 14 asignaturas por año son las siete dimensiones del
Decreto 2247 más una: COGNITIVA, COMUNICATIVA, CORPORAL, AFECTIVA, ESTÉTICA, ÉTICA,
ACTITUDINAL Y VALORATIVA (ESPIRITUAL) y TECNOLOGÍA E INFORMÁTICA. **Eso no hay que
inventarlo, ya está.**

Lo que sí está roto es **cómo usan la rejilla**:

| | Preescolar | Resto del colegio |
|---|---|---|
| subunidades por unidad | **1,03** | 2,00 |
| subunidades cuyo texto **repite el de su unidad** | **803 de 1.169 — 68,7 %** | 76 de 31.873 — **0,2 %** |
| unidades por asignatura+periodo | 3,5 | 4,4 |
| celdas cuyos porcentajes suman 100 | 330 de 330 | 3.585 de 3.600 |

Ese **68,7 % contra 0,2 %** es el hallazgo, y una fila real de DIMENSIÓN AFECTIVA lo
enseña entero:

```
unidad          «Crea hábitos de higiene personal y alimentación»    35 %
  └─ subunidad  «Crea hábitos de higiene personal y alimentación»   100 %
```

**La docente escribe el logro en la unidad y lo vuelve a teclear en una subunidad
porque la nota no puede colgar de otro sitio.** El segundo piso no le aporta nada:
es un peaje. Cuando sí lo usa para algo distinto lo usa para las **evidencias** —
«Elabora ficha *Aprendo a comer saludable*», «Aprende canción para el lavado de
manos»—, que es exactamente el corte de §3: el texto arriba, la actividad abajo.

Y los porcentajes son ceremonia: **las 330 celdas suman 100 %** y casi toda unidad
lleva **una** columna al 100 %. El reparto lo hace entero la unidad; la capa de
abajo sólo existe para sostener el número.

#### Lo que ya está construido para preescolar, y a medias

- **`grupos.caritas`** decide si el grupo se califica con la escala de preescolar en
  vez de con números, y es **por grupo** — más fino que por nivel. Se copia al año
  siguiente (`YearsController:382`) y viaja al front en once consultas;
  **ningún cálculo del backend la mira**.
- **`frases_preescolar`** (`asignatura_id`, `definicion`) con sus tres rutas
  `bolfinales-preescolar/{crear,guardar,eliminar}-frase`. El comentario del propio
  código lo dice: *«en el boletín de preescolar no hay notas, hay frases»*. Pero
  **no tiene `alumno_id` ni `periodo_id`**: es un texto por asignatura para el
  boletín de **fin de año**, y no sirve para el de periodo.
- **`escalas_de_valoracion`** lleva `icono_infantil` e `icono_adolescente`: se
  guardan (`EscalasDeValoracionController:109`), se copian de año a año
  (`YearsController:234`) y **no las lee ningún boletín**. Alguien ya pensó «los
  pequeños ven una carita» y no escribió la mitad de abajo. Es el mismo caso que
  `default_unidades` de §1.bis(b).
- **`years.solo_escalas_valorativas`**, leído en `BoletinesController:611` y
  `Boletines2Controller:585`: imprime el desempeño en vez del número — **para el
  colegio entero**. No se puede tener preescolar cualitativo y bachillerato
  numérico.

**El hueco es el de en medio: la valoración por alumno y periodo.** No existe, y por
eso el front acaba usando el **boletín independiente** —una pantalla diseñada para el
caso excepcional, un alumno cada vez— para los veinticinco de Transición. Joseth lo
dijo así el 2 sep 2026: *«se crea un boletín a cada estudiante de preescolar y se
imprime el boletín tipo 1 donde se muestra sólo ese texto; en cuestión de UX es
horrible»*.

---

## 4. El invariante que protege los boletines de los años pasados

> **La plantilla SIEMBRA, no manda.**

`notas.subunidad_id` apunta a una fila de `subunidades` que es una **copia** hecha
el día que se abrió la asignatura. Ninguna nota apunta a la plantilla, y ningún
informe vuelve a leerla. Por eso el pedido *«no puede dañar los boletines y
configuraciones de años pasados»* **ya se cumple hoy por construcción**, y la única
forma de romperlo sería introducir una lectura viva de la plantilla desde un
informe. **No se hace, en ninguna de las tres entregas.**

Las cinco reglas que hereda todo lo que se escriba a partir de aquí:

1. **Ninguna nota apunta nunca a una fila de plantilla.** Se copia, siempre.
2. **Nada se siembra en un periodo cerrado.** La guarda `User::permiteEditarNotas`
   que ya tiene `getDeAsignaturaPeriodo` es la que protege los años pasados, y va
   en **todos** los caminos nuevos de escritura, no sólo en el viejo.
3. **La fórmula de la definitiva no cambia** ([10-definitivas.md](10-definitivas.md)).
4. **Nada se siembra encima de lo que ya tiene notas.** Ver la guarda de §5.1.c.
5. **`unidades.alumno_id IS NULL`** en todo lo que hable del reparto del curso: el
   boletín independiente ([19](19-boletin-independiente.md)) mete filas con dueño
   en las mismas tablas, y una plantilla que las cuente se lleva por delante el
   reparto de treinta alumnos. Ya pasó una vez, medido: 51 estudiantes y una
   asignatura al 110 %.

---

## 5. El modelo propuesto: tres capas y no dos

| capa | tabla | quién la decide | ¿nota? | dónde sale |
|---|---|---|---|---|
| **Criterio** (hoy `unidad`) | `unidades` ← `unidades_por_defecto` | **el colegio**, por año y opcionalmente por periodo | no, contenedor con % | cabecera de la planilla y del boletín |
| **Columna** (hoy `subunidad`) | `subunidades` ← `subunidades_por_defecto` | el colegio la propone, el docente la ajusta **si el colegio lo permite** | **sí** | planilla |
| **Competencia → indicador** | `competencias`, `indicadores` (nuevas) | el colegio / jefe de área, por **materia + grado** | no, son textos | boletín |

Las tres entregas son independientes y se pueden parar en cualquier punto.

---

### 5.0 · Entrega 0 — el fallo de las subunidades del año nuevo · **HECHA**

> **Aprobada por Joseth el 2 sep 2026** por separado del resto, con el argumento de
> que es correcta en cualquier caso. Escrita esa misma noche; **sin commitear**.

En `YearsController`, dentro del bucle que copia `unidades_por_defecto`, se captura el
`lastInsertId()` y se copian las `subunidades_por_defecto` de cada unidad — la misma
forma que ya usa el sembrador de `UnidadesController:159`.

- **Los tests se vieron rojos antes que verdes**, que es lo único que los hace valer
  aquí: con un año origen **sin** plantilla —que es como está el seed y como está la
  base de desarrollo— pasan con el arreglo y sin él. El escenario monta dos unidades
  con 3 y 2 subunidades, y sin el arreglo el reparto sale `Cognitivo => 0,
  Actitudinal => 0`.
- **Son dos y el segundo no es de adorno**: cuenta **bajo qué unidad** cae cada
  subunidad. `lastInsertId()` leído fuera del bucle mete las cinco bajo la misma
  unidad y deja el reparto en 100/0 **con la misma cantidad de filas**, así que el
  primero pasaría igual.
- **`inicia_at` y `finaliza_at` no se copian**: son fechas del año viejo, y copiadas
  la plantilla nueva nace con casillas vencidas el día uno. Es la decisión que ya se
  tomó con `editable_por_profe_id` en los requisitos de matrícula.
- **No mueve rutas ni instantáneas.** Sólo corre al crear un año.

> **Y NO REPARA HACIA ATRÁS.** Un año copiado antes de este arreglo sigue con sus
> unidades por defecto vacías; ningún camino del código las repone. El daño ya hecho
> se mide con la consulta de la §1.bis, en los diecisiete del servidor.

**El centinela que falta, y que este fallo señala.**
`CentinelaDeLasColumnasDelAnioNuevoTest` vigila que `postStore` no se deje ninguna
**columna** de `years` — y no podía cazar esto, porque no es una columna sino una
**tabla hija**. Y un censo de tablas con `year_id` tampoco: `subunidades_por_defecto`
**no tiene `year_id`**, cuelga de `unidades_por_defecto`. El centinela que cerraría
esta puerta es el de **las tablas que se copian al crear un año**, con su lista de
excepciones y su motivo al lado. No está escrito.

---

### 5.1 · Entrega 1 — la plantilla del colegio deja de vivir en phpMyAdmin

Es **la entrega que resuelve el pedido de Joseth con el menor riesgo**: no inventa
conceptos, le pone pantalla a lo que ya existe y le añade el eje que falta.

> ### HECHA el 4 sep 2026 — menos el candado, y eso va dicho arriba del todo
>
> `PlantillaNotasController` (**9 rutas**, router de 568 a **577** contado con
> `route:list --json`), `routes/api/plantilla.php`,
> `2026_09_05_300000_create_permiso_can_edit_plantilla_notas`,
> `Autoriza::puedeEditarPlantillaNotas` y `tests/Contrato/PlantillaNotasTest`
> (**21 casos**). Va junta con el alcance de la Entrega 7(a) porque la una sin la
> otra no sirve: sin pantalla, las dos columnas del alcance **no las puede escribir
> nadie** —el caso `profesores.tono` del día anterior, con la diferencia de que esta
> vez se vio antes—, y sin alcance, la plantilla de una fila de preescolar le cae
> encima al bachillerato entero.
>
> **Se pidieron las nueve a la vez y con la razón escrita.** Con el `GET` y los
> `POST` se puede escribir una plantilla y **nadie puede aplicarla**; es la misma
> forma que tuvieron las tres primeras de `horario/`.
>
> #### Lo que NO entró, y por qué no es que costara
>
> **El candado del docente (decisión 5).** Cambia respuestas de éxito por **403 en
> nueve rutas vivas** y lo notan los tres editores; y §5.1.e exige contar antes el
> censo de `por_defecto = 1` **en los diecisiete**, que no se puede correr desde una
> sesión de desarrollo. Un candado puesto sin ese censo es un criterio ampliado sin
> saber quién cuelga de él. **Queda como lo siguiente de esta entrega**, con el censo
> nombrado como lo que lo bloquea.
>
> #### Tres cosas que el diseño de §5.1 no tenía y el código sí necesitó
>
> 1. **El 422 del porcentaje es por grupo de alcance, no uno.** §5.1.d pide 422 si
>    «la suma de las unidades aplicables» no es 100. Con el alcance de la Entrega 7
>    esa suma **ya no es una**: se comprueban los grupos que de verdad se van a
>    sembrar y el 422 los nombra uno a uno. Comprobar la suma de la tabla entera daría
>    200 en cuanto un colegio tenga una plantilla general y otra de preescolar, **las
>    dos correctas**.
> 2. **`sembrar` devuelve siete contadores y no cinco, y uno cambió de nombre.**
>    `saltadas_por_independiente`, que proponía §5.1.c, **valdría cero siempre**: las
>    filas con dueño no se cuentan al decidir si una asignatura está montada —eso es
>    justo la regla 5— así que no hacen saltar nada, y un contador que no puede subir
>    no dice si la regla llegó a correr. En su lugar va **`independientes_respetadas`**,
>    que sube cuando había filas con dueño y se dejaron intactas. Y
>    `saltadas_por_estructura` y `saltadas_sin_plantilla` salen de partir en dos lo que
>    el documento dejaba junto: «ya estaba montada» y «no hay ninguna fila que le
>    toque» son desenlaces distintos, y **el segundo es el que delata una plantilla mal
>    dirigida**.
> 3. **Borrar una unidad de plantilla borra sus subunidades a mano.** La FK es
>    `ON DELETE CASCADE` y eso **sólo actúa en un borrado físico**; aquí es lógico, así
>    que sin el `UPDATE` de la mano las subunidades quedarían vivas colgando de una
>    unidad de la papelera: invisibles en la pantalla y **cazadas por el sembrador**,
>    que las busca por `unidad_defec_id` y no vuelve a preguntar por el padre.
>
> #### Los controles: los tests se han visto rojos por los dos lados
>
> | control | qué cae |
> |---|---|
> | quitar `puedeEditarPlantillaNotas` del controlador | **9** de 21 — las nueve rutas |
> | quitar la regla 5 (`alumno_id IS NULL`) de `sembrar` | **1**, el del independiente |
>
> **Y el segundo control es el que hay que contar, porque la primera vez NO se puso
> rojo.** El caso del boletín independiente usaba la primera asignatura del seed, que
> ya tiene rejilla y notas: `sembrar` la saltaba por `saltadas_por_notas` **antes de
> llegar a la regla 5**, así que quitarle el `alumno_id IS NULL` al controlador dejaba
> el test en verde. Medía el nombre del caso y no el caso. Se reescribió para que se
> monte su propia asignatura limpia, y entonces sí cae. *Un test que no se ha visto
> rojo no dice nada, y éste llevaba media hora diciendo que sí.*

#### a) Migración

> **DECIDIDO POR JOSETH EL 2 SEP 2026: la plantilla es POR AÑO.** *«Las unidades con
> porcentajes y subunidades se establecen para el año.»* Los dos niveles, no sólo el
> de arriba.

**Y la decisión hace desaparecer la migración de esta entrega.**
`unidades_por_defecto` ya cuelga de `year_id` y `subunidades_por_defecto` cuelga de
ella: **el alcance por año ya está en el esquema**. La versión anterior de este
documento proponía una columna `numero_periodo` para no tener que elegir; elegido,
esa columna sobra, y una columna que no decide nadie es justo lo que caza
`tools/interruptores-que-nadie-lee.py`. **Se retira.**

```
(ninguna migración de esquema en las tablas de la plantilla)

2026_09_XX_100000_create_permiso_can_edit_plantilla_notas.php
    -- calcada de 2026_08_25_200000_create_permiso_can_view_auditoria.php
```

**Las cuatro columnas `can_change_*` que proponía la versión anterior de este apartado
tampoco se añaden**, y eso lo decidió Joseth el 2 sep 2026: el candado del docente es
**binario** —sembrada es candada, sin excepción fila a fila—, así que no hay nada que
guardar por fila. Lo que distingue una fila del colegio de una del docente es
`unidades.por_defecto`, **que ya existe y ya está puesta** (apartado (e)).

> **O sea que la Entrega 1 no toca el esquema salvo para dar de alta un permiso.** Las
> dos decisiones del 2 sep —la plantilla por año y el candado binario— se comieron una
> la columna `numero_periodo` y otra las cuatro `can_change_*`. La entrega que iba a
> traer una migración de cinco columnas trae **cero**, y las dos veces por lo mismo:
> se preguntó qué quería el colegio antes de escribirla.

**Lo que la decisión cierra, y conviene que esté escrito porque lo preguntó el propio
Joseth**: *«tal vez quieran que en el segundo periodo haya una unidad de parcial»*
**no cabe** con la plantilla por año. Sale de dos maneras, las dos sin migración: el
docente añade esa unidad a mano en su asignatura del 2.º periodo —que es lo que hace
hoy—, o el colegio la mete en la plantilla y vive los cuatro periodos. Si algún día
resulta que hace falta de verdad, **la vuelta atrás es barata y aditiva**: una columna
`numero_periodo int NULL` donde `NULL` = todos los periodos reproduce exactamente el
comportamiento de esta entrega, así que **no hay que diseñarlo ahora para no cerrarse
la puerta**. Eso es lo que la hace una decisión segura y no una apuesta.

> ⚠️ **`UnidadesController:148` hace `SELECT *` sobre `unidades_por_defecto`.** No
> rompe nada porque el `INSERT` de debajo nombra sus columnas, pero **los endpoints
> nuevos nombran columnas**, nunca `*` — es la regla que ya está escrita en la
> cabecera de ese fichero y la razón es la de siempre: una columna nueva se reparte sola en una respuesta con `*`.

#### b) Endpoints — **9 rutas nuevas**

```
GET    plantilla-notas                        la plantilla del año, unidades con sus subunidades
POST   plantilla-notas/unidad
PUT    plantilla-notas/unidad/{id}
DELETE plantilla-notas/unidad/{id}
POST   plantilla-notas/subunidad
PUT    plantilla-notas/subunidad/{id}
DELETE plantilla-notas/subunidad/{id}
PUT    plantilla-notas/orden                  reordena unidades y subunidades en una llamada
PUT    plantilla-notas/sembrar                aplica la plantilla a las asignaturas que faltan
```

Guard: `auth.personal` **más** un permiso nuevo `can_edit_plantilla_notas`, con la
migración calcada de `2026_08_25_200000_create_permiso_can_view_auditoria.php`. Un
docente **no** configura la plantilla del colegio; ése es justo el punto.

#### c) `PUT plantilla-notas/sembrar` es el único botón peligroso

Es el que el colegio va a querer el día que cambie la plantilla a mitad de año, y
el que puede hacer daño. Contrato:

- Sólo el **año actual** y sólo **periodos abiertos** (regla 2 de §4).
- Por defecto siembra **sólo asignaturas+periodo con cero unidades** de curso
  (`alumno_id IS NULL`). Nunca toca una asignatura ya montada.
- Con `reemplazar: true`, siembra además las que tengan unidades **pero cero notas
  puestas**. Una asignatura con una sola nota **no se toca jamás**, y sale en la
  respuesta con el motivo.
- Nunca toca filas con `alumno_id IS NOT NULL` (boletín independiente).
- Se registra con `Auditoria` — es una escritura masiva sobre la rejilla del
  colegio entero.
- **La respuesta dice la población, no `OK`**: `{revisadas, sembradas,
  saltadas_por_notas, saltadas_por_periodo_cerrado, saltadas_por_independiente}`.
  Un «0 sembradas» tiene que poder distinguirse de «no revisé nada». Es la regla de
  `tools/` aplicada a un endpoint.

#### d) El porcentaje que no suma 100

La plantilla **multiplica**: un 90 % en la plantilla es un 90 % en todas las
asignaturas del colegio. Pero bloquear el guardado de cada fila haría imposible
llegar a 100 (hay que pasar por estados intermedios). Por eso:

- `POST`/`PUT` de una unidad: **se guarda y se devuelve la suma** — el front la
  pinta en rojo. No se bloquea.
- `PUT sembrar`: **422 si la suma de las unidades aplicables al periodo no es 100**,
  con la suma en el mensaje, salvo que venga `acepto_desviacion: true`. Es el mismo
  patrón de `acepto_perder` de [23-horarios.md](23-horarios.md): el aviso donde
  duele, no donde estorba.

#### e) `can_change_*` — **decidido: el docente NO puede cambiar lo que sembró la plantilla**

> **Decisión de Joseth, 2 sep 2026**: *«sí, por defecto el docente NO puede cambiar
> las unidades/subunidades que se crearon basando en las "por defecto"»*.
>
> **Esto invierte el defecto y sube 1.b de «sólo si alguien lo pide» a parte de la
> entrega.** Lo que este apartado recomendaba —*«nadie ha pedido todavía impedirle al
> docente cambiar su reparto»*— **era falso el día siguiente de escribirlo**, y se deja
> escrito ahí arriba en vez de borrarlo: la recomendación no se equivocó en el
> mecanismo, se equivocó en dar por supuesto lo que quería el colegio sin preguntarlo.

Los cuatro interruptores nacen en la **plantilla**, y la plantilla **se copia**: la
fila de `unidades` que ve el docente no sabe de dónde salió. Ése era el argumento por
el que hacer cumplir el candado costaba una instantánea y los tres clientes.

**Y es falso: la marca ya existe, ya está puesta y ya viaja.**

`unidades.por_defecto` y `subunidades.por_defecto` —`tinyint(1) DEFAULT '0'`— están en
el volcado desde siempre. En `app/` hay **tres** `INSERT` que crean filas en esas dos
tablas, sobre **235 ficheros**, y sólo uno pone la marca a 1:

| quién escribe | `por_defecto` | qué fila es |
|---|---|---|
| `UnidadesController:158` y `:167` — el sembrador | **`true`, literal** | la que copió la plantilla |
| `UnidadesController::postIndex` (Eloquent, no la toca) | 0 por defecto | la que montó el docente |
| `BoletinIndependienteController:1440` y `:1456` | 0 por defecto | la copia de un independiente |

O sea que **`por_defecto = 1` ya significa exactamente «esta fila la sembró la
plantilla»**, en los dieciséis colegios y hacia atrás, **sin migración, sin backfill y
sin una columna nueva**. El predicado del candado que pide la decisión ya está escrito
en la base.

**Y ya está en la respuesta**: `por_defecto` va nombrada en los `SELECT` de
`UnidadesController` (`cons_unidades`, `cons_subunidades`), `AsignaturasController`,
`NotasController` y `ChangeAskedController`. `myvc_front/app2` incluso la declara en
sus tipos —`datos/unidades.ts:29`, `datos/subunidades.ts:23`— y **no la mira ningún
`if`**: es un interruptor del montón B de `tools/interruptores-que-nadie-lee.py`, que
llevaba años esperando a que alguien decidiera qué significaba. **La instantánea de
`unidades/de-asignatura-periodo` no se mueve.**

##### Lo que sigue costando, que no es la lectura sino el 403

`myvc_flutter` **no nombra `por_defecto` en ninguno de sus 201 ficheros**. Una app vieja
—y conviven meses— va a seguir pintando el lápiz sobre una unidad candada y se va a
comer un 403 al guardar. No corrompe nada, pero **el mensaje del 403 es lo único que
verá el docente**, así que se escribe para que se pueda leer en un móvil: *«Esta unidad
la puso el colegio y no se puede cambiar aquí»*.

Los editores que hay que avisar son **tres, no cuatro**: `myvc_front/app`
(`UnidadesCtrl.ts`, 922 líneas), `myvc_front/app2` y `myvc_flutter`
(`Screens/UnidadesScreen.dart`). `myvc_front_2` no toca unidades ni subunidades.

##### Los NUEVE caminos que pueden mover una fila sembrada

Poner el candado sólo en los dos `update` lo deja decorativo, y por el sitio de
siempre: **borrar y volver a crear**. Una unidad que el docente no puede editar pero
sí eliminar es una unidad que puede editar en dos pasos, y además con `alumno_id`
nuevo y `por_defecto = 0`, o sea que la segunda vez ya nace libre.

```
unidades/update/{id}              definicion, porcentaje
unidades/destroy/{id}             ← el rodeo
unidades/forcedelete/{id}         ← el rodeo, desde la papelera
unidades/update-orden             orden
subunidades/update/{id}           definicion, porcentaje, nota_default, orden
subunidades/destroy/{id}          ← el rodeo
subunidades/forcedelete/{id}      ← el rodeo
subunidades/update-orden          orden
subunidades/update-orden-varias   orden
```

Y `unidades/restore/{id}` y `subunidades/restore/{id}` **no hacen falta**: restaurar
devuelve la fila del colegio a su sitio, que es lo que el candado quiere.

##### La población, con su denominador

Medido en la base de desarrollo `simonbolivar` del contenedor: **17.080 unidades vivas
y 34.439 subunidades, y las 51.519 con `por_defecto = 0`. Ninguna sembrada, nunca.**
Coherente con la §1.bis —ese colegio tiene nueve años y ninguno con plantilla—, y quiere
decir que **en ese colegio el candado no cierra nada el día del despliegue**: no puede
haber un docente que pierda mañana un campo que hoy edita, porque no hay ni una fila
marcada.

**Eso no dice nada de los otros quince**, y es justo lo que hay que contar antes de
desplegar, con el bucle de [DESPLIEGUE.md](../DESPLIEGUE.md) y esta consulta de sólo
lectura, al lado de la de la §1.bis:

```sql
SELECT 'unidades' t, por_defecto, COUNT(*) c FROM unidades    WHERE deleted_at IS NULL GROUP BY por_defecto
UNION ALL
SELECT 'subunidades', por_defecto, COUNT(*)   FROM subunidades WHERE deleted_at IS NULL GROUP BY por_defecto;
```

**El número que importa es `por_defecto = 1`: son las filas que mañana dejan de poder
tocarse.** Un colegio con muchas es un colegio que usa la plantilla y donde el candado
va a notarse el primer día; un colegio con cero es uno donde no cambia nada. Se escribe
aquí con la forma «X de 17 colegios, N filas candadas de M revisadas».

##### Las cuatro preguntas que quedaban, contestadas el 2 sep 2026

| pregunta | decisión de Joseth |
|---|---|
| **¿Qué campos?** | **Todos**: `definicion`, `porcentaje`, `orden` y `nota_default` |
| **¿Puede borrarla?** | **No** — `destroy` y `forcedelete`, en los dos niveles |
| **¿Excepción por fila?** | **No: candado binario.** Sembrada es candada |
| **¿Quién queda exento?** | **Quien tenga `can_edit_plantilla_notas`** |

**La tercera hace desaparecer la migración de la Entrega 1 entera.** Sin excepción por
fila no hacen falta los cuatro `can_change_*` en la plantilla ni sus gemelos en las
tablas hijas: el candado es `por_defecto = 1`, que ya está en la base. De la Entrega 1
sólo queda **una** migración, la del permiso `can_edit_plantilla_notas`, calcada de
`2026_08_25_200000_create_permiso_can_view_auditoria.php`.

**Y la cuarta es la que hace que el candado no se convierta en una llamada a soporte**:
quien puso la plantilla puede corregir una errata en **una** asignatura sin cambiar la
del colegio entero. Es la misma persona, no un rodeo.

##### Las dos trampas de implementación, medidas sobre los clientes

**(1) El candado compara VALORES, no la presencia del campo.** Los clientes mandan el
objeto entero en cada guardado, y lo llevan escrito en su propio código:
`myvc_flutter/lib/Http/UnidadesApi.dart` — *«`nota_default` va siempre, aunque no se
haya tocado»*— y `myvc_front/app2/src/app/datos/subunidades.ts` — *«los tres se
escriben siempre; mandar sólo `{definicion}` deja `porcentaje` a null»*. Un candado que
mire si el campo **viene** rechazaría **todos** los guardados, incluidos los que no
cambian nada, y sobre todo los de la app vieja. Sólo hay 403 cuando el valor que llega
es **distinto** del que hay guardado. Es la familia de la §68/§96: el defecto de
`Request::input($campo, $valorActual)` ya distingue, y aquí se usa para lo mismo.

> Y tiene un efecto que conviene ver: con la comparación por valor, **la app vieja de
> `myvc_flutter` sigue funcionando entera** mientras el docente no intente cambiar de
> verdad una fila del colegio. El 403 sólo aparece cuando alguien empuja el candado.

**(2) `Unidad::arreglarOrden` queda exenta, y sin esto el candado rompe la pantalla.**
`arreglarOrden` (`app/Models/Unidad.php:49`) reescribe `orden` de **todas** las unidades
y **todas** las subunidades **en cada `GET unidades/de-asignatura-periodo`** de quien
puede escribir. Con `orden` candado y sin excepción, **abrir la planilla de una
asignatura sembrada sería un 403 en una lectura** — y la lectura es lo primero que hace
el docente cada mañana. Se exime porque **no es el docente cambiando el reparto: es el
servidor arreglando `orden` duplicados**, y deja las filas en el mismo sitio en que
estaban.

**Lo que sí cierra `orden`** es `unidades/update-orden`, `subunidades/update-orden` y
`update-orden-varias`, que son las tres que mueven una fila **porque alguien la
arrastró**. El criterio no es «el `sortHash` nombra una fila sembrada» —lo nombra
siempre, están todas— sino **«el `sortHash` la deja en otra posición»**: añadir una
unidad propia al final no mueve ninguna del colegio y tiene que seguir funcionando.

##### Lo que hay que dejar escrito el día que se implemente

- **El 403 se lee en un móvil**, porque en `myvc_flutter` viejo es lo único que verá el
  docente: *«Esta unidad la puso el colegio y no se puede cambiar aquí»*.
- **`can_edit_plantilla_notas` levanta el candado, no el permiso de notas.** Los nueve
  caminos siguen pidiendo `User::pueden_editar_notas` con su periodo: el candado es
  **una guarda más**, encima, nunca en lugar de la que ya está. Un periodo cerrado
  sigue cerrado para todo el mundo, con permiso y sin él.

#### f) Tests de contrato

- La siembra da **la misma respuesta byte a byte** que antes: esta entrega no toca
  el sembrador, sólo le pone pantalla a la tabla de la que lee.
- `sembrar` con una asignatura que tiene una nota puesta la deja intacta y la
  reporta en `saltadas_por_notas`.
- `sembrar` con el periodo cerrado no escribe **nada**.
- Un token de docente sin `can_edit_plantilla_notas` recibe 403 en las nueve.
- **El candado, en los nueve caminos de (e)**: una unidad y una subunidad con
  `por_defecto = 1` rechazan `update`, `destroy`, `forcedelete` y las tres de orden, y
  las mismas con `por_defecto = 0` los aceptan. **El control que hace valer estos
  tests**: apagar el candado tiene que ponerlos rojos **todos**. Si al quitarlo sólo
  caen los dos `update`, es que el rodeo de borrar-y-volver-a-crear sigue abierto y el
  test que lo cubría no lo cubría.
- **Nada se candó hacia atrás**: una fila creada por `postIndex` y otra copiada por el
  boletín independiente siguen editándose. Es la mitad que dice que el candado
  distingue **de quién es** la fila, y no sólo que exista.
- **Guardar sin cambiar nada sigue dando 200**, mandando el objeto entero tal como lo
  mandan hoy `myvc_flutter` y `app2`. Es el test que impide que el candado se escriba
  mirando si el campo viene en vez de si el valor cambia — y sin él, el candado rompe
  la app vieja de los dieciséis colegios el día del despliegue. Trampa (1) de (e).
- **`GET unidades/de-asignatura-periodo` sigue en 200 sobre una asignatura sembrada con
  el `orden` duplicado, y lo arregla.** Es `arreglarOrden`, que escribe `orden` en cada
  lectura: sin la exención, el candado convierte la pantalla de cada mañana en un 403.
  Trampa (2) de (e).
- **Un token con `can_edit_plantilla_notas` sí puede** cambiar y borrar una fila
  sembrada — y **no** puede con el periodo cerrado, que es lo que demuestra que el
  candado se añadió encima de `pueden_editar_notas` y no en su lugar.

---

### 5.2 · Entrega 2 — las competencias, que son textos

```
2026_09_XX_200000_competencias.php

competencias
  id, year_id, materia_id, grado_id NULL, alumno_id NULL,
  definicion text, orden int, created_by/updated_by/deleted_at/...
  KEY (year_id, materia_id, grado_id, alumno_id)

years + show_competencias_bol tinyint(1) NOT NULL DEFAULT 0
```

- **Por materia + grado, no por asignatura.** Una competencia de Matemáticas de 6.º
  sirve para los ocho grupos de 6.º. Es lo que hace que el colegio la escriba una
  vez. `grado_id NULL` = para todos los grados de esa materia.
- **Por año**, igual que la plantilla y por la misma decisión del 2 sep. Una
  competencia es del plan de área del grado: no cambia cada diez semanas.
- **No llevan porcentaje, no llevan nota, no tienen hijos.** Si alguien pide
  «ponerle nota a una competencia», la respuesta es que eso es una unidad.
- **No hay interruptor, y esto lo decidió Joseth el 2 sep 2026** (§5.6): los tres
  boletines de hoy **no cambian**, y las competencias salen en una **maqueta nueva**.
  `years + show_competencias_bol` **se retira de esta propuesta** — un boletín se
  elige llamando a su ruta, como ya pasa con `boletines2` y `boletines3`, así que el
  `tinyint(1)` no decidiría nada y sería justo lo que caza
  `tools/interruptores-que-nadie-lee.py`.

**6 rutas**: `GET competencias`, `POST competencias`, `PUT competencias/{id}`,
`DELETE competencias/{id}`, `PUT competencias/orden`, `PUT competencias/copiar`
(traer las de otro grado o del año pasado — es lo que evita teclear doce veces lo
mismo).

En el boletín nuevo salen como bloque de texto de la asignatura, y se leen de una
consulta por asignatura que ya sabe la materia y el grado. Los boletines están
fichados por tardar 24–63 s ([02-plan-rendimiento.md](02-plan-rendimiento.md)): la
consulta va **una vez por grupo**, no una por alumno.

---

### 5.3 · Entrega 3 — los indicadores de desempeño · **DECIDIDA: opción B, por pasos**

> **Joseth eligió el 2 sep 2026**, y con una condición que este documento no había
> previsto y que cambia el reparto: *«los boletines actuales seguirían mostrando
> subunidades con sus notas, pero toca crear otros boletines enfocados en ese nuevo
> texto por materia»*. O sea que el texto **no entra en los tres boletines de hoy**:
> es una maqueta nueva (§5.6). Falta el modelo de Master2000 que Joseth va a pasar.

Los dos caminos que se plantearon, y por qué ganó el segundo:

**Opción A — el indicador ES la subunidad.** No se crea nada. La plantilla del
colegio trae las frases y el docente las califica. Es el modelo *«logros con
procesos»* de Master2000. Barato, cero tablas nuevas. **Lo que no resuelve**: cada
indicador está obligado a llevar un porcentaje y una nota, que es exactamente lo que
Joseth dice que está mal.

**Opción B — el indicador es un texto y la nota va aparte** *(recomendada)*.

```
indicadores
  id, year_id, materia_id, grado_id NULL,
  competencia_id NULL, definicion text, orden, ...

subunidades + indicador_id int NULL      -- qué indicador evalúa esta columna
```

- El colegio escribe los indicadores una vez por materia+grado, colgados o no de una
  competencia.
- La **columna** de la planilla se llama «Evaluación 1», «Taller 2» — lo que el
  docente quiera — y **opcionalmente apunta a un indicador**. Eso es *«procesos con
  logros»*.
- En el boletín, los indicadores del alumno se marcan con el mecanismo que **ya
  existe y ya se imprime**: `frases_asignatura` (frase por alumno, asignatura y
  periodo, sea de catálogo o escrita a mano). No hace falta una tabla de unión nueva:
  el catálogo `frases` se amplía o se le pone al lado el de `indicadores`.

**B, en dos pasos, y el segundo puede no llegar nunca.** Primero el catálogo y su
salida en el boletín nuevo —que no toca ni una nota ni la planilla—, y sólo después el
`indicador_id` en `subunidades`, que sí mueve la instantánea de
`unidades/de-asignatura-periodo` y por tanto los tres clientes, con la app vieja
conviviendo meses. **Ese segundo paso es una decisión aparte**, no un remate del
primero.

**6 rutas**, calcadas de las de competencias — **más la rejilla de marcado, que NO se
cuenta aquí**: es la misma pareja `GET`/`PUT` por grupo y periodo que ya cuenta la
Entrega 7 (§5.7.c) para preescolar. Una rejilla, dos usos, dos rutas en total.

#### El marcado va en rejilla y en una sola llamada — decidido el 2 sep 2026

**Medido antes de proponerlo**: `FrasesAsignaturaController::postStore` guarda **una
frase por llamada** —el `frase_id` va en la URL— así que marcar diez indicadores a
treinta alumnos son **~300 peticiones** por asignatura y periodo. Ése es el número que
convierte una función nueva en una función que se abandona.

```
PUT indicadores/rejilla          (y su GET, §5.7.c)
  { asignatura_id, periodo_id,
    marcas: [ {alumno_id, indicador_id, si: true|false}, ... ] }
```

- **Devuelve la población, no `OK`**: `{recibidas, marcadas, quitadas,
  saltadas_por_periodo_cerrado, saltadas_por_no_ser_del_grupo}`. Misma regla que
  `plantilla-notas/sembrar` (§5.1.c) y que `tools/`.
- **Escribe en `frases_asignatura`**, que es lo que el boletín ya lee — no hay tabla
  de unión nueva.
- **Nada de marcar de oficio.** Se descartó el «todos alcanzan y el docente quita»:
  escribe ~10 filas por alumno que nadie ha revisado, y un boletín que afirma
  trescientos logros que nadie miró es peor que uno vacío.
- **Marca el docente de la asignatura** (decisión de Joseth, 2 sep 2026), con
  `auth.personal` y el interruptor del periodo — lo que ya se hace hoy con las frases.
  **Sin permiso nuevo y sin coordinador**: si un docente se va a mitad de periodo, eso
  se resuelve como se resuelve hoy con las notas, no con un rol nuevo.
- **La comprobación que hoy falta sí entra aquí**: el endpoint nuevo verifica que el
  alumno esté en el grupo de la asignatura, que es justo lo que `postStore` no mira
  (§1.ter). Es código nuevo, así que contesta **403/422**, no 400.

**El marcado por alumno no necesita tabla de unión, y está medido**: `frases_asignatura`
ya guarda N frases por alumno + asignatura + periodo, de catálogo (`frase_id`) o
escritas a mano, y `BoletinesController:359` ya la lee **en cada boletín**. Reutilizarla
**no añade ni una consulta** al camino que tarda 24–63 s: la consulta ya se hace, sólo
traería más filas. Dos avisos del esquema, los dos comprobados en
`database/schema/mysql-schema.sql`:

- **`frases_asignatura.frase` era `varchar(255)` y truncaba en silencio.** No era un
  riesgo futuro: **626 frases ya están cortadas** en la copia de desarrollo (§1.ter).
  Joseth autorizó el `ALTER` a `text` el 2 sep 2026, así que este camino queda
  válido — pero **la migración entra antes** que el marcado de indicadores, no
  después.
- **`frases` es por `year_id` y nada más** — no tiene materia ni grado. Por eso los
  indicadores **no viven ahí**: el docente de Matemáticas de 6.º vería el catálogo
  entero del colegio. Y `FrasesController::getIndex` devuelve el modelo completo, así
  que dos columnas nuevas en esa tabla se repartirían solas a los cuatro clientes y
  moverían `muestreo-frases.json`. Ése fue el argumento que descartó reutilizar
  `frases` como catálogo.

---

### 5.4 · Entrega 4 — el boletín independiente también sale de la plantilla

**Encargo de Joseth, 2 sep 2026:** *«debemos replantear entonces cómo sería el boletín
independiente, se debe adaptar a eso, y copiar los ítems por defecto y tener la parte
para crearle las competencias.»*

Hoy el independiente es el que **más** trabajo le da al docente, justo al revés de lo
que se pretende: el curso recibe la plantilla sembrada sola al abrir la pantalla
(`getDeAsignaturaPeriodo`), y al marcado **hay que montarle la rejilla a mano** o
copiársela de otro con `POST boletin-independiente/copiar`. Las dos cosas que faltan
son pequeñas porque el andamiaje ya está: `unidades.alumno_id` y
`BoletinIndependiente::alcance()`.

#### a) Un tercer origen en `copiar`, no una ruta nueva

`POST boletin-independiente/copiar` ya acepta `origen: {tipo: "grupo"}` y
`{tipo: "alumno"}` (§6.2 del [19](19-boletin-independiente.md)). Se le añade el
tercero:

```jsonc
"origen": { "tipo": "plantilla" }     // la del AÑO — no lleva periodo_id
```

Encaja entero en el contrato que ya existe: mismo `si_ya_tiene`
(`saltar`/`anadir`/`reemplazar`), misma respuesta con `copiadas` y
`porcentaje_unidades`, mismo alcance de destino. **Cero rutas nuevas**, y el front no
estrena pantalla: estrena una opción en el selector que ya tiene.

`origen.periodo_id` **no aplica** con `tipo: "plantilla"` y se rechaza con 422 si
viene, por la misma razón que se rechaza `origen.asignatura_id`: un campo que se
acepta y se ignora es el que hace creer que se copió otra cosa.

#### b) Y que se siembre solo al marcarlo, que es donde está el trabajo de verdad

Un tercer origen sigue siendo **un clic por alumno y por asignatura**. Un estudiante
marcado tiene ~10 asignaturas, así que lo que quita trabajo es sembrar **en el
momento de marcar**, en `PUT boletin-independiente/marcados`, con las mismas guardas
que el sembrador del curso:

- sólo asignatura+periodo del alumno con **cero unidades propias** (`alumno_id = él`);
- sólo **periodos abiertos** — la regla 2 de §4, otra vez;
- **no toca las del curso** (`alumno_id IS NULL`), que es lo que ya costó una
  asignatura al 110 % con 51 estudiantes;
- se registra con `Auditoria`: son ~10 asignaturas × 4 unidades × 3 subunidades =
  **~120 filas por estudiante marcado**, y eso no puede pasar en silencio;
- **la respuesta dice la población**: `{asignaturas_revisadas, sembradas,
  saltadas_por_estructura_propia, saltadas_por_periodo_cerrado}`.

Con eso, marcar a un estudiante como independiente deja de ser el principio del
trabajo y pasa a ser el final.

#### c) Competencias propias: `alumno_id`, no una tabla aparte

La competencia normal es del **grado**, así que un independiente **ya recibe las de su
grado sin que nadie haga nada** — que es lo correcto en el caso normal: sigue en 6.º.
Lo que pidió Joseth es poder escribirle las **suyas**, y eso es exactamente la forma
que este repositorio ya resolvió una vez: **`competencias.alumno_id NULL`**, leído con
`<=>` a través de `BoletinIndependiente::alcance()`.

> **`<=>` y no `=`**, por la misma razón que en `Unidad::deAsignatura`: el igual
> null-safe empareja NULL con NULL y una sola condición cubre las dos ramas. Con `=` a
> secas el alumno normal no empareja nada y **se queda sin competencias**, sin un
> error.

Y la regla que hace que esto no rompa el boletín de nadie: **con `alumno_id = null` se
seleccionan exactamente las filas de antes**, así que la columna entra sin mover una
sola instantánea.

---

### 5.5 · Entrega 5 — promedio en vez de porcentaje *(la más cara, y no se ve)*

**Encargo de Joseth, 2 sep 2026:** *«debería ser opcional que las subunidades se
manejen con porcentajes. Que el colegio pueda elegir que sean tratados con promedios.»*

Es la que más trabajo le quita al docente de las cinco —deja de teclear porcentajes y
desaparece la clase entera de fallos de «esto no suma 100»— y es, **con diferencia**,
la más cara de implementar bien. El motivo es un número:

> **La fórmula `nota × porcentaje / 100` está escrita en 18 sitios de 9 ficheros.**
> Contados el 2 sep 2026 con `grep -rn "porcentaje/100\|porcentaje / 100" app/`:
> `Models/NotaFinal` 5, `Models/Unidad` 4, `Models/Subunidad` 2, `NotasController` 2,
> y uno cada uno en `SubunidadesController`, `UnidadesController`,
> `BolfinalesController`, `DefinitivasPeriodosController` y
> `Services/DefinitivasDeAsignatura`.

O sea que **«un interruptor» es en realidad un `if` en dieciocho sitios**, y basta que
uno se quede sin él para que **el boletín enseñe un número y la definitiva guarde
otro** — que es el peor fallo posible de este módulo, porque los dos son creíbles y
nadie los compara. `DefinitivasDeAsignatura` es «el único sitio que **escribe** una
definitiva», pero no es el único que la **calcula**: `Unidad::deAsignaturaCalculada`
la recalcula para pintarla, en tres ramas distintas.

#### Cómo se hace sin que eso pase: la fase 0 es un refactor, no el interruptor

1. **Fase 0 — un solo sitio.** Extraer el fragmento de cálculo a un punto único
   (`App\Support\RepartoDeLaNota`, o el nombre que sea) y hacer que los 18 lo usen.
   **No cambia ni un resultado**, así que se despliega solo, se verifica con las
   instantáneas de contrato tal como están, y tiene valor aunque el interruptor no
   llegue nunca. Es la fase que hace posible la siguiente.
2. **Fase 1 — el modo.** `years.reparto_subunidades enum('porcentaje','promedio') NOT
   NULL DEFAULT 'porcentaje'`. Los dieciséis colegios y **todos los años pasados**
   siguen exactamente igual, por el `DEFAULT`.
3. **Fase 2 — la pantalla**, que en modo promedio **esconde el campo de porcentaje**.
   Si el campo sigue ahí, no se ha quitado trabajo.

#### La fórmula, y la decisión que lleva dentro

```
porcentaje:  nota_unidad = Σ (nota × %subunidad / 100)
promedio:    nota_unidad = Σ nota / (nº de SUBUNIDADES de la unidad)
definitiva = Σ (nota_unidad × %unidad / 100)      ← igual en los dos modos
```

**El porcentaje de la UNIDAD no cambia.** El interruptor es sólo de subunidades, y hay
un motivo que no es la pereza: los porcentajes de las unidades los pone **el colegio,
una vez al año, en la plantilla**, así que no le cuestan nada al docente. Los de las
subunidades los teclea él, en cada asignatura. Se quita el que cuesta.

**El denominador son las subunidades que existen, no las notas puestas**, y esto es
una decisión con consecuencia visible: un alumno con 1 de 5 notas puestas saca
`nota/5`, no `nota/1`. Es lo mismo que hace hoy el modo porcentaje —una nota que falta
no tiene fila en `notas` y aporta 0—, así que **las dos ramas se comportan igual a
mitad de periodo**, que es cuando alguien mira. Con el otro denominador, la definitiva
bailaría a cada nota que el docente escribe.

**`subunidades.porcentaje` no se borra ni se pone a 0**: en modo promedio simplemente
no se lee. Volver atrás es cambiar el enum, y todo reaparece como estaba.

#### El día que un colegio lo encienda

Encenderlo **cambia todas las definitivas guardadas del año**. No se puede tratar como
un ajuste de pantalla:

- El interruptor es **del año**, así que un año cerrado conserva su modo para siempre
  y **ningún boletín ya impreso se mueve**. Ésa es la garantía, y es la misma de §4.
- Cambiarlo en el año en curso: se calcula **antes de escribir** cuántas definitivas
  cambian y de cuánto es el salto mayor, se enseña, y se exige `acepto_recalcular`.
  Es el patrón de `acepto_perder` ([23](23-horarios.md)) y de `acepto_desviacion`
  (§5.1.d): el aviso donde duele.
- Queda en `Auditoria`, con el modo anterior y el nuevo.

#### Lo que hay que mirar y no es obvio

- **La celda del boletín.** `Subunidad::deUnidadCalculada` devuelve
  `valor_nota = nota × %/100`. En modo promedio ese número no significa nada: la celda
  enseña **la nota**. Si no se toca, el boletín queda con una columna que no suma a
  nada.
- **`porcentaje_unidades`** —la comprobación de que la asignatura suma 100— **sigue
  valiendo**: es de unidades, y las unidades no cambian de modo.
- **Rúbricas** ([26](26-rubricas.md)) y **nivelaciones** ([22](22-nivelaciones.md)) no
  se ven afectadas: las dos producen `notas.nota`, y el modo actúa **después**.

---

## 5.bis · La medida que importa: cuánto trabajo le quita esto al docente

Es lo que pidió Joseth —*«estoy buscando la forma de ponerle menos trabajo al
docente»*— y conviene medir cada entrega contra eso y no contra sí misma:

| Lo que el docente hace HOY | Después | Quién lo hace entonces | Entrega |
|---|---|---|---|
| Montar las unidades y sus porcentajes **en cada una de sus ~10 asignaturas** | nada: llegan sembradas | el colegio, una vez al año | ya pasa hoy — **1** le da pantalla |
| Teclear los porcentajes de las subunidades hasta que sumen 100 | **nada** | nadie: no hay porcentajes | **5** |
| Escribir la frase del logro en cada asignatura y cada grupo | elegirla de la lista de su grado | el jefe de área, una vez | **3** |
| Montarle la rejilla a mano a cada estudiante marcado | nada: se siembra al marcarlo | — | **4** |
| Pedirle al administrador que le toque la plantilla en la base de datos | lo hace el colegio en su pantalla | el colegio | **1** |

Y lo que **no** le quita trabajo, dicho para que nadie lo venda como que sí:

- **Las competencias (Entrega 2)** son calidad del boletín, no ahorro: es texto nuevo
  que alguien —el colegio, no el docente— tiene que escribir una vez.
- **Los `can_change_*`** son control, no ahorro. Le quitan opciones al docente; no le
  quitan tecleo.

---

### 5.6 · Entrega 6 — el boletín por competencias es una maqueta **nueva**

> **Decisión de Joseth, 2 sep 2026.** Los tres boletines de hoy siguen enseñando
> subunidades con sus notas y **no se tocan**: ni su código, ni sus rutas, ni sus
> instantáneas. Lo nuevo es **otro boletín**.

**Esto no inventa un patrón: es el que ya hay.** El comentario de
[`routes/api/informes.php:76`](../../routes/api/informes.php) lo dice: *«Los tres
controladores de boletines son copias con distinta maqueta y sirven el mismo dato»*.
`BoletinesController` (629 líneas), `Boletines2Controller` (605) y
`Boletines3Controller` (586) son eso, y **la maqueta la elige el front llamando a una
u otra ruta** — no hay ninguna columna de `years` que la seleccione. Un cuarto
boletín es, por tanto, **una decisión ya tomada antes**, no una excepción.

- **4 rutas**, calcadas de las de `boletines3`: `detailed-notas-group/{grupo_id}`,
  `detailed-notas-year/{grupo_id}/{periodo?}`, `detailed-notas/{grupo_id}` y
  `destroy/{id}`. Guard **`boletin.propio`** en las tres de lectura y `auth.personal`
  en el `DELETE`, exactamente como las otras. Un alumno no pide el de otro.
- **Sirve el mismo dato más dos bloques**: las competencias de la asignatura (§5.2) y
  los indicadores alcanzados por el alumno (§5.3). Las notas y las definitivas salen
  de donde salen hoy — **la fórmula no cambia** ([10](10-definitivas.md)).
- **El nombre y la maqueta esperan al modelo de Master2000** que Joseth va a pasar.
  Hasta entonces esta entrega **no se escribe**: copiar 600 líneas para maquetar a
  ojo es la forma de tener que copiarlas dos veces.

> ⚠️ **Y aquí está el coste que hay que decir en voz alta: sería la cuarta copia de
> 600 líneas.** Los tres de hoy ya divergen entre sí y un arreglo en uno no llega a
> los otros. Antes de escribir el cuarto **se mide qué comparten los tres** y se
> decide si el dato sale de un sitio común, porque de lo contrario esta entrega deja
> el problema un 33 % peor que como lo encontró. Esa medición no está hecha.

---

### 5.7 · Entrega 7 — preescolar deja de teclearse alumno por alumno

> **Decidida por Joseth el 2 sep 2026**, sobre la medición de §3.2 y con esta frase:
> *«hay una pantalla en myvc_front donde se crea un boletín a cada estudiante de
> preescolar y se imprime el boletín tipo 1 donde se muestra sólo ese texto; en
> cuestión de UX es horrible»*.
>
> **No inventa un modelo paralelo.** §3.2 mide que preescolar no usa otra estructura:
> usa la misma con un piso de más y sin sitio donde poner la valoración del periodo.

#### a) La plantilla de preescolar es **una sola fila** — y esto reabre una migración

| unidad | % | subunidades |
|---|---|---|
| «Valoración del periodo» | 100 | una, al 100 % |

La docente abre su asignatura y encuentra **una casilla donde poner la valoración**,
no una rejilla de porcentajes que cuadrar. Los 803 textos duplicados de §3.2 dejan de
escribirse porque el logro ya no va ahí: va en el catálogo de la Entrega 3.

Para que esa fila llegue **sólo** a preescolar hacen falta dos columnas anulables en
`unidades_por_defecto`:

```
nivel_educativo_id  int NULL   -- NULL = todos los niveles (lo de hoy)
materia_id          int NULL   -- NULL = todas las materias (lo de hoy)
```

> ⚠️ **Y hay que decirlo con todas las letras: esto le devuelve a la Entrega 1 la
> migración que la decisión 2 le había quitado.** Con `numero_periodo` retirado y los
> cuatro `can_change_*` retirados por la decisión 5, la Entrega 1 se había quedado
> **sin ningún cambio de esquema**. Éste vuelve a ponerle uno. El argumento de
> seguridad es el mismo que tenía aquél: **toda fila existente queda con las dos a
> NULL**, y la consulta del sembrador pasa a
> `WHERE year_id=? AND (nivel_educativo_id IS NULL OR nivel_educativo_id=?) AND
> (materia_id IS NULL OR materia_id=?)`, que **selecciona exactamente las mismas filas
> que hoy** hasta que alguien use la pantalla nueva.
>
> **Lo que sí es nuevo y no lo era antes: la precedencia.** Si una fila dice «todos los
> niveles» y otra dice «preescolar», hay que decidir cuál gana. La propuesta es **la
> más específica**, y se fija con un test — no con un `ORDER BY` que dependa del orden
> de inserción.

> ### HECHO el 4 sep 2026, y con dos cosas que este apartado no tenía
>
> `2026_09_05_200000_alcance_de_la_plantilla` (las dos columnas),
> `App\Support\AlcanceDeLaPlantilla` (la resolución, en **un solo sitio**) y
> `tests/Contrato/AlcanceDeLaPlantillaTest` (**9 casos**). El sembrador viejo
> —`UnidadesController::getDeAsignaturaPeriodo`— pasa a leer de ahí, y de camino
> pierde su `SELECT *`.
>
> **La resolución vive en una clase y no en las dos consultas**, porque son **dos**
> los sitios que tienen que decidir lo mismo: el sembrador viejo y la pantalla nueva
> de la Entrega 1. Con la regla copiada, el día que alguien afine la precedencia en
> uno **la pantalla enseñaría una plantilla y el sembrador aplicaría otra** — y eso
> no da error, da una rejilla distinta de la que el colegio vio.
>
> #### 1. La precedencia necesitaba una decisión más de la que este apartado pedía
>
> «Gana la más específica» está bien dicho con **un** eje y es ambiguo con **dos**:
> ¿quién gana entre «Lengua, en todo el colegio» y «todo preescolar»? Se resolvió
> con cuatro **gradas** ordenadas, y se aplica **la grada entera, nunca una mezcla**:
>
> | grada | la fila dice |
> |---|---|
> | 3 | nivel **y** materia |
> | 2 | sólo nivel |
> | 1 | sólo materia |
> | 0 | ninguna — la plantilla de siempre |
>
> **Que la grada 2 le gane a la 1 —el nivel a la materia— es la única decisión
> discutible de todo esto**, y va escrita porque se puede querer al revés. El
> argumento es el caso que hizo existir la entrega: si «Lengua, en todo el colegio»
> le ganara a «todo preescolar», la docente de preescolar volvería a recibir la
> rejilla de dos pisos que esto viene a quitarle, y el módulo no serviría para lo que
> se pidió. Y **la grada entera y no la fila** porque una plantilla son varias
> unidades que suman 100: «las cuatro de siempre **más** la única de preescolar»
> suma 200, y el síntoma no es un error sino una definitiva a la mitad.
>
> #### 2. Y un fallo que este documento no tenía: `YearsController` dejaba escapar el alcance
>
> `YearsController:278` copia la plantilla al año siguiente **nombrando columnas**.
> Sin añadir las dos nuevas, el fallo **no habría sido «no se copia el alcance»**,
> que se nota: habrían nacido a NULL, y **NULL aquí significa «a todos»**. O sea que
> la plantilla de **una fila** de preescolar se le habría sembrado en enero **a todo
> el bachillerato**, con su única casilla al 100 %, un 200 y ningún error en ningún
> log.
>
> **Es el hermano por el lado contrario del fallo de la §1.bis**: allí la plantilla
> del año nuevo llegaba **vacía**, aquí llegaría **a quien no era**. Los dos son
> mudos y los dos aparecen en enero. Arreglado en el mismo commit, con su caso en
> `YearsTest::test_el_ano_nuevo_hereda_el_alcance_de_la_plantilla` — al lado del de
> su hermano, que es donde alguien va a venir a mirar.
>
> #### 3. Sin claves foráneas, y las tres salidas están escritas
>
> `SET NULL` es **la peor** y parece la más suave: borrar un nivel convertiría la
> fila de preescolar en «a todos los niveles», que es la misma fuga de (2) por otra
> puerta. `CASCADE` borraría en silencio lo que el colegio escribió. Sin FK, una fila
> puede apuntar a un nivel que ya no existe — **deja de casar con nada y no se
> siembra en ninguna parte**, que es el único fallo de los tres que no hace daño, y
> tiene su caso de contrato. Que el id exista se comprueba **al escribir**, con 422.
>
> #### 4. Los controles: los tests se han visto rojos
>
> | control | qué cae |
> |---|---|
> | el sembrador sin filtro de alcance (como antes de la tanda) | **7** de 9 |
> | `YearsController` sin copiar las dos columnas | **1**, el de la fuga |
>
> ⚠️ **Falta la mitad que no se puede medir desde aquí.** Esto siembra bien la
> plantilla de preescolar; lo que **no** existe todavía es la plantilla de preescolar
> en ningún colegio. Crearla es la pantalla de la Entrega 1, y **quién la escribe el
> primer día son los superusuarios**, porque `can_edit_plantilla_notas` nace repartido
> a nadie (§5.1.a).

#### b) Los logros se escriben **una vez** por dimensión y grado

Es el catálogo de la Entrega 3 sin un solo cambio: `indicadores` con `materia_id` +
`grado_id`. La coordinadora escribe los logros de DIMENSIÓN COGNITIVA de Transición
**una vez** — no uno por grupo y otro por año, que es lo que hace hoy.

#### c) La valoración por alumno se **marca**, no se teclea — y la tabla ya existe

`frases_asignatura` es exactamente el hueco de §3.2: `(alumno_id, asignatura_id,
periodo_id, frase_id NULL, frase NULL)`. Por alumno, por asignatura y **por periodo**,
de catálogo o escrita a mano. Es la misma tabla y el mismo `indicador_id` que ya
propone §5.3 — preescolar **no añade tabla ninguna**, sólo la pantalla.

> ## ⚠️ BLOQUEADA: esa frase se contradice con §5.3, y hace falta una decisión
>
> **Comprobado en el volcado el 4 sep 2026.** Las columnas reales de
> `frases_asignatura` son `id, alumno_id, frase_id, frase, asignatura_id, periodo_id`
> más las seis de auditoría. **No hay `indicador_id`.** Y `frase_id` apunta a
> `frases`, que es el catálogo del colegio por `year_id` — el mismo que §5.3
> **descarta explícitamente** para los indicadores, porque no tiene materia ni grado y
> el docente de Matemáticas de 6.º vería el catálogo entero.
>
> Así que «el mismo `indicador_id` que ya propone §5.3» **no existe en ninguna
> parte**: §5.3 pone `indicador_id` en **`subunidades`**, que es otra tabla y otra
> cosa —«qué indicador evalúa esta columna de la planilla»—, y además lo declara
> *«decisión aparte, no un remate del primero»*. La rejilla de marcado no puede
> escribirse hasta que se elija entre estas dos, **y son dos decisiones distintas, no
> dos formas de la misma**:
>
> | salida | qué cuesta | qué se pierde |
> |---|---|---|
> | **`frases_asignatura.indicador_id`**, columna nueva anulable | una migración más, aditiva | nada, pero es una migración más en una tanda ya medida |
> | **copiar el texto del indicador en `frase`** | cero esquema | **el `GET` de la rejilla no puede saber qué casilla está marcada** salvo comparando cadenas de 200 caracteres, y una errata corregida en el catálogo desmarca a treinta alumnos |
>
> La segunda tiene a favor que es literalmente lo que hace hoy el marcado a mano, y
> que encaja con el invariante de §4 —*la plantilla siembra, no manda*: el texto
> impreso en un boletín viejo no debe cambiar porque alguien edite el catálogo—.
> Tiene en contra que **la pantalla que esta entrega existe para construir es una
> rejilla de casillas**, y una casilla necesita saber si está marcada.
>
> **La recomendación es la columna**, y el motivo es el segundo renglón de la tabla,
> no el primero: sin ella la rejilla no se puede pintar. El texto se sigue copiando en
> `frase` —eso no cambia, y es lo que protege los boletines viejos—; `indicador_id` es
> sólo para saber **de qué casilla salió**.
>
> **Y esto va detrás del `ALTER` de §1.ter, que a 4 sep 2026 sigue SIN FUSIONAR** en
> la rama `fix/frases-asignatura-text`. Marcar indicadores contra una `varchar(255)`
> es multiplicar por veinticinco alumnos el corte silencioso que la §1.ter acaba de
> medir.

Y la pantalla es **una por grupo** —alumnos en filas, indicadores del grado en
columnas, la valoración en la casilla— en vez de un boletín por estudiante. Es el
mismo cambio de eje que ya se hizo con `boletin-independiente/marcados` frente a
`/alumno`.

> **Va detrás del `ALTER` de §1.ter, y no es opcional.** Un indicador de preescolar
> —«Reconoce las partes de su cuerpo y practica hábitos de higiene y alimentación
> saludable»— pasa de 255 caracteres con holgura. Marcar indicadores contra una
> `varchar(255)` es multiplicar por veinticinco alumnos el corte silencioso que §1.ter
> acaba de medir.

> **`frases_preescolar` no se toca y no se migra.** Es el texto del boletín de **fin de
> año** (`asignatura_id` + `definicion`, sin alumno y sin periodo) y sigue sirviendo
> para eso. Fundirla con `frases_asignatura` movería la respuesta de
> `bolfinales-preescolar/detailed-notas-year-group`, que es una instantánea publicada,
> y no lo ha pedido nadie.

#### d) La escala: `caritas` ya existe, y es **más fina** que «por nivel»

La respuesta de Joseth fue «escala cualitativa **por nivel**». Medido, **no hace falta
la columna nueva**: `grupos.caritas` ya dice grupo a grupo si se califica con la escala
de preescolar, ya se copia al año siguiente (`YearsController:382`) y ya viaja al
front. Por nivel sería *menos* fino — un colegio puede querer caritas en un grupo de
fuera de preescolar, y con `nivel_educativo_id` no podría.

**Lo que falta es que el backend la honre.** Hoy los boletines de periodo miran
`years.solo_escalas_valorativas`, que es del colegio entero:

```
grupo con caritas                → desempeño, con el icono_infantil que ya está guardado
si no, y el año en solo_escalas  → desempeño
si no                            → el número, como hoy
```

**Dónde entra eso depende de la decisión 6.** Si el boletín por competencias es una
maqueta **nueva** (Entrega 6), lo barato es que la regla nazca ahí y **no se toquen**
`BoletinesController:611` ni `Boletines2Controller:585`, que son instantáneas
publicadas. Tocar los viejos es una entrega propia y sólo hace falta si el colegio
quiere el desempeño en el boletín de siempre.

Con esto `icono_infantil` e `icono_adolescente` dejan de ser columnas que se guardan,
se copian de año a año y **no lee nadie** (§3.2).

> **`caritas` se toca con el guante puesto.** Es la columna de la §153 de
> `GruposController`: tenía defecto `false` y por eso salía «a salvo» en el barrido,
> **pero ese defecto la apagaba** — corregirle el nombre a un grupo de preescolar le
> cambiaba la forma de evaluar. Todo endpoint nuevo que la lea o la escriba hereda ese
> aviso.

#### e) Lo que NO se hace

- **No se crea un módulo de preescolar.** Los datos dicen que usan la misma estructura,
  no otra; duplicar el módulo duplica también los boletines y los cuatro clientes.
- **No se toca `frases_preescolar`** ni sus tres rutas.
- **No se prohíbe la rejilla en preescolar.** El colegio que quiera calificar sus
  dimensiones con tres columnas y porcentajes sigue pudiendo: la plantilla **siembra,
  no manda** (§4).
- **No se migran los 803 duplicados.** Son años cerrados y sus boletines se imprimen
  como se imprimieron (§4, regla 1).

#### f) Tests de contrato

- Con las dos columnas a NULL en todas las filas, el sembrador da **la misma respuesta
  byte a byte** que antes de la migración. Es la prueba de que los dieciséis colegios
  no se enteran.
- Una fila de plantilla marcada para preescolar **no** se siembra en una asignatura de
  bachillerato, ni al revés.
- Con una fila «todos los niveles» y otra «preescolar» compitiendo, gana la específica
  — y el test lo fija **invirtiendo el orden de inserción**, que es lo único que
  distingue una precedencia de una casualidad.
- Un grupo **sin** `caritas` en un año **sin** `solo_escalas_valorativas` da la
  respuesta de boletín idéntica a la de hoy.
- Marcar un indicador a un alumno de un periodo cerrado no escribe **nada** (regla 2).

**Rutas**: (a) y (b) no añaden ninguna — van en las de la Entrega 1 y la Entrega 3.
(c) necesita **2**: `GET` y `PUT` de la rejilla de indicadores por grupo y periodo.
(d) no añade ninguna.

---

## 6. Lo que esto le cuesta al resto del sistema

- **Rutas.** Hoy hay **577** (contadas con `route:list --json` el 4 sep 2026, **después** de que entraran las nueve de la Entrega 1; decía 568 esa misma mañana y **566** el 2 sep, el día que se escribió esto). **Las 9 de la Entrega 1 ya están dentro de ese 577**, así que lo que queda por entrar de este documento son **18**, no 27. Las entregas 1, 2 y 3 sumaban **hasta 21** (9 + 6 + 6) y
  la **6 añade 4** —el boletín nuevo de §5.6, calcado de `boletines3`—, o sea **25**;
  la **4 y la 5 no añaden ninguna** —la 4 es un valor más en un campo que ya existe y
  una siembra dentro de una ruta que ya existe, y la 5 es una columna de `years`—, que
  es la señal de que las dos encajan en lo que ya hay en vez de abrir una puerta
  paralela; y la **7 añade 2** —la rejilla de indicadores por grupo de §5.7.c—, o sea
  **27**. El
  número **se cuenta con `route:list --json` el día que entren**, no se suma — y
  mueve `CLAUDE.md` y **tres** instantáneas: `rutas.json`, `guards-por-ruta.json` y
  `guard-por-familia.json`. Ninguna de las 27 es pública: `TOTAL_PUBLICAS` sigue en
  doce.
- **El candado del docente no mueve ninguna instantánea** —la marca `por_defecto` ya
  viaja (§5.1.e)— pero **cambia respuestas de éxito por 403 en nueve rutas que ya
  existen**, y eso lo notan los tres editores aunque no cambien: `myvc_front/app`,
  `myvc_front/app2` y `myvc_flutter`. Es un cambio de comportamiento sobre endpoints
  vivos, así que va **al final** del despliegue de la Entrega 1 y con el censo de
  `por_defecto = 1` de los diecisiete **contado antes, no después**.
- **`myvc_flutter` es una sola app para los dieciséis colegios y una versión vieja
  convive meses.** Por eso todo lo nuevo va en **rutas nuevas y campos opcionales**,
  nunca en una bandera sobre un endpoint que ya existe. Es la lección de
  [22-nivelaciones.md](22-nivelaciones.md) §6.1, y aquí muerde igual: una app vieja
  que no sepa de `numero_periodo` tiene que seguir viendo la rejilla de siempre.
- **Despliegue.** `app/` es copia real en cada colegio. Una pantalla del front que
  llame a `plantilla-notas` **no se publica hasta que el guard esté desplegado en
  los dieciséis**, no fusionado (`docs/DESPLIEGUE.md`).
- **Larastan nivel 7 y Pint** sobre lo que se toque, como siempre.

---

## 7. Las decisiones

### Tomadas ya

1. ✅ **La Entrega 0 se arregla** (2 sep 2026). Escrita esa noche, con los tests rojos
   antes que verdes. Y **no repara hacia atrás**: eso sigue abierto y se mide con la
   consulta de §1.bis.
2. ✅ **La plantilla es POR AÑO** (2 sep 2026), los dos niveles. Retira la columna
   `numero_periodo` que proponía la versión anterior, y con ella **la migración entera
   de la Entrega 1**. Lo que cierra —la unidad de parcial sólo en el 2.º periodo— y
   por qué la vuelta atrás es barata, en §5.1.a.
3. ✅ **El boletín independiente se adapta** (2 sep 2026): sale de la plantilla y
   puede tener competencias propias. Diseño en §5.4.
4. ✅ **El promedio será opcional** (2 sep 2026), a elección del colegio. Diseño y su
   precio real en §5.5.
5. ✅ **El docente NO puede cambiar lo que sembró la plantilla** (2 sep 2026): *«por
   defecto el docente NO puede cambiar las unidades/subunidades que se crearon basando
   en las "por defecto"»*. **El candado entra con la Entrega 1, no después**, y cuesta
   mucho menos de lo que decía este documento: la marca ya existe en la base
   (`unidades.por_defecto`), ya está puesta en los dieciséis colegios y **ya viaja en
   la respuesta** — la instantánea no se mueve. **Las cuatro preguntas que abría están
   contestadas el mismo día**: se candan los cuatro campos, tampoco se puede borrar, el
   candado es **binario** —lo que retira las cuatro columnas `can_change_*` y deja la
   Entrega 1 sin migración de esquema— y queda exento quien tenga
   `can_edit_plantilla_notas`. Los nueve caminos, las dos trampas de implementación y
   el censo que hay que contar antes de desplegar, en §5.1.e.
6. ✅ **Los indicadores son textos aparte: opción B, por pasos** (2 sep 2026), y con
   una condición que este documento no había previsto: *«los boletines actuales
   seguirían mostrando subunidades con sus notas, pero toca crear otros boletines
   enfocados en ese nuevo texto por materia»*. Eso **retira el interruptor
   `show_competencias_bol`** (§5.2) —un boletín se elige llamando a su ruta— y abre la
   **Entrega 6** (§5.6). Lo que **sigue abierto** son dos cosas distintas: el nombre y
   la maqueta del boletín nuevo, que esperan al modelo de Master2000 que Joseth va a
   pasar, y el segundo paso —`subunidades.indicador_id`—, que es **decisión propia**
   porque es el único que mueve los tres clientes.
7. ✅ **`frases_asignatura.frase` pasa de `varchar(255)` a `text`** (2 sep 2026):
   *«cambia el tipo de campo como necesites»*. La pregunta se hizo mirando al futuro
   —los indicadores no cabrían— y la medición encontró que **el daño ya está hecho**:
   626 frases cortadas a mitad de palabra en un solo colegio, impresas en boletines
   (§1.ter). **No repara hacia atrás.** Es un `ALTER` de una línea, sin índices que
   reconstruir, y **va antes** que cualquier entrega que escriba ahí.
8. ✅ **La plantilla tiene alcance, y preescolar es el primer caso** (2 sep 2026).
   Retira la pregunta de alcance, que estuvo abierta hasta que se midió qué hace
   preescolar con la rejilla que le damos (§3.2): la plantilla deja de ser «una sola
   para todas las asignaturas del año». Se decidió sobre la medición de §3.2 — **803 de 1.169 subunidades de
   preescolar repiten literalmente el texto de su unidad, contra 76 de 31.873 en el
   resto**—, que dice que preescolar no necesita otro modelo sino una plantilla de
   una fila. Son dos columnas NULL en `unidades_por_defecto` y **le devuelven a la
   Entrega 1 la migración que la decisión 2 le había quitado**; diseño y precio en
   §5.7.a.
9. ✅ **La escala cualitativa se resuelve con `grupos.caritas`** (2 sep 2026). Joseth
   pidió «por nivel»; medido, **la columna ya existe y es por grupo**, que es más
   fino. Lo que falta no es una columna sino que el backend la honre, y **dónde**
   depende de la decisión 6: si el boletín nuevo es una maqueta aparte, la regla nace
   ahí y no se toca ninguna instantánea publicada (§5.7.d).
10. ✅ **Los indicadores se marcan en rejilla y en una sola llamada** (2 sep 2026).
    Sale de una medición: `FrasesAsignaturaController::postStore` guarda **una frase
    por petición**, o sea ~300 por asignatura y periodo con diez indicadores y treinta
    alumnos — el número que convierte una función nueva en una función abandonada. Y
    **descarta marcarlas de oficio**: un boletín que afirma trescientos logros que
    nadie revisó es peor que uno vacío. Es **la misma rejilla** que la Entrega 7
    (§5.7.c), así que **no suma rutas**: diseño en §5.3.
11. ✅ **Marca el docente de la asignatura** (2 sep 2026), con lo que ya hay:
    `auth.personal` y el interruptor del periodo. **Sin permiso nuevo y sin
    coordinador**; si un docente se va a mitad de periodo se resuelve como se resuelve
    hoy con las notas.
12. ✅ **De las frases ya cortadas no se avisa** (2 sep 2026): sólo se arregla hacia
    adelante. Se propuso contarlas en los diecisiete y pasarle al colegio la lista de
    boletines afectados; la decisión fue que no. Lo que eso deja vivo, en §1.ter.

13. ✅ **La Entrega 1 y la 7(a) se escriben juntas** (4 sep 2026), con el precio
    delante: nueve rutas nuevas, dos migraciones, el arreglo de `YearsController` y
    el router de 568 a 577. **Se pidieron juntas y no de una en una porque por
    separado no sirven**: las dos columnas del alcance sin pantalla **no las puede
    escribir nadie** —el caso `profesores.tono` del día anterior— y la pantalla sin
    alcance le siembra la plantilla de preescolar a todo el bachillerato. Lo que
    quedó fuera y por qué, en §5.1.

14. ✅ **El candado del docente espera al censo** (4 sep 2026). Es la decisión 5, ya
    tomada, que **no se ejecutó** con la Entrega 1 — y la pregunta no era si se pone
    sino **si se corre el censo antes o se acepta ponerlo a ciegas**. Joseth eligió
    el censo: contar `por_defecto = 1` **en los diecisiete colegios** con la consulta
    de §5.1.e, que son literalmente **las filas que mañana dejan de poder tocarse**.
    Un colegio con muchas es uno donde el candado se nota el primer día; uno con cero
    es uno donde no cambia nada — y hoy sólo se sabe de `simonbolivar`, donde
    **ninguna de sus 51.519 filas está marcada**. **No se puede correr desde una
    sesión de desarrollo**: hace falta el bucle de `DESPLIEGUE.md` sobre el servidor,
    así que es trabajo del día del despliegue y no de una noche de código.

### Abiertas

15. **¿Cómo se ata una marca de indicador a `frases_asignatura`?** Es lo único que
    bloquea la rejilla de la Entrega 7(c), y sale de una contradicción del propio
    documento entre §5.7.c y §5.3 — medida contra el volcado el 4 sep 2026:
    **`frases_asignatura` no tiene `indicador_id`**. Las dos salidas, su precio y la
    recomendación, en §5.7.c.

16. **Entrega 4: ¿sólo el tercer origen, o también sembrar al marcar?** El tercer
    origen es un clic por asignatura; **sembrar al marcar es lo que de verdad quita
    trabajo**, y es el que escribe ~120 filas por estudiante. Recomendados los dos, y
    el segundo es el que hay que aprobar a conciencia.
17. **Entrega 5: ¿se aprueba la fase 0?** El refactor de los 18 sitios **no cambia
    ningún resultado** y tiene valor aunque el interruptor no llegue nunca. Sin él, el
    interruptor es un `if` repetido dieciocho veces y la primera vez que falte uno el
    boletín y la definitiva dejarán de coincidir.
18. **Entrega 5: ¿el modo promedio vale también para las unidades?** La propuesta es
    **no** —los porcentajes de las unidades los pone el colegio una vez al año y no le
    cuestan nada al docente—, pero es una decisión del colegio y no técnica.
19. **¿El desempeño entra también en el boletín de siempre?** Sólo hace falta si el
    colegio quiere ver caritas en los boletines que ya existen; eso sí mueve
    `BoletinesController:611`, `Boletines2Controller:585` y sus instantáneas, y es
    entrega propia.
20. **`default_unidades` y `default_subunidades`**: no las lee nadie. Antes de
    borrarlas, **contar sus filas en los dieciséis colegios**.

## 8. Lo que este documento NO propone

- **No renombra `unidad` ni `subunidad`.** §3.1.
- **No cambia la fórmula de la definitiva.**
- **No toca `notas`.**
- **No quita la siembra del `GET`**, aunque una lectura que escribe siga siendo
  feo. Quitarla es un cambio de contrato para los cuatro clientes y merece su propia
  decisión.
- **No toca nada de un año cerrado**, en ningún camino.
