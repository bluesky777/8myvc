# Lote C — copiar periodos, el modelo y los cuatro sueltos

> Rama `fix/bi-lote-c`, sobre `main` en `693649e`. Siete sitios en seis ficheros:
> **cinco acotados, dos leídos y dejados igual a propósito** — y de los cinco, uno
> era además un fallo de escritura que el detector no señalaba.
>
> Lo que decide el reparto: [reparto.md](reparto.md) §1.5 y §1.6. El plan:
> [19-boletin-independiente.md](../19-boletin-independiente.md) §9.4.

---

## 1 · `PeriodosController::putCopiar` — la §9.4, y trae un fallo de más

`PUT periodos/copiar` copia la estructura de un periodo a otro. El detector lo
señalaba en la línea 274, que es **la consulta de la respuesta**; el fallo que
importa estaba **treinta líneas más arriba, en el bucle que escribe**, y ninguna
herramienta lo iba a listar porque ahí no hay ningún `SELECT`.

### 1.1 · Lo que faltaba: las unidades con dueño no se copiaban

`unidades_ids` la arma el front desde la pantalla de estructura del docente, y esa
pantalla enseña **la del grupo**. Las unidades de un marcado no están en esa lista,
así que copiar al periodo siguiente lo dejaba **sin una sola unidad propia**: su
definitiva sale 0, su boletín en blanco y **nadie recibe un error** — la §9.1
entrando por la puerta de copiar.

Se añaden a la lista antes del bucle, y **quien decide es el periodo DESTINO**:

```php
$independientes = ($grupo_to_id && $periodo_to_id)
    ? BoletinIndependiente::delGrupo((int) $grupo_to_id, (int) $periodo_to_id)['independientes']
    : [];

$unidades_ids = $this->conLasUnidadesConDueno($unidades_ids, $independientes);
```

**El destino y no el origen**, porque la marca es por periodo desde la decisión 7:
un alumno que fue aparte en el 1 y vuelve con el grupo en el 2 no se lleva sus
unidades al 2, o el segundo periodo le saldría aparte sin que nadie lo pidiera.

Y sale de `delGrupo(destino)` en vez de escribirse la condición a mano porque
**contesta las dos mitades de una vez** —alumnos *del grupo destino* Y marcados *en
el periodo destino*—. El efecto de eso es que copiar entre grupos distintos deja la
lista vacía sin una línea más, que es la respuesta correcta y no una casualidad: el
dueño de esas unidades no es alumno del grupo al que se copia. Es el mismo motivo
por el que el autor ya no copiaba las notas entre grupos.

### 1.2 · Y el que no estaba en el plan: el dueño no viajaba

`new Unidad` no tocaba `alumno_id`, así que **una unidad con dueño se copiaba como
una del grupo**. Es la forma «de más» de la §9.2 en su versión más cara: el reparto
de porcentajes de un solo alumno pasa a ser el de los treinta, y las definitivas de
todo el curso se calculan con él **sin que se mueva nada en el log**.

Dos líneas:

```php
if ($unidad_curr->alumno_id !== null
    && ! in_array((int) $unidad_curr->alumno_id, $independientes, true)) {
    continue;                       // ya no va aparte en el destino: no se copia
}
$unidad_new->alumno_id = $unidad_curr->alumno_id;   // el dueño viaja con la unidad
```

Hoy es inalcanzable —no hay ninguna unidad con dueño— y por eso **hubo que
construir el caso**: es el tercer test de abajo, y es el que se pone rojo contra el
código viejo con `5 is identical to 4`.

### 1.3 · El origen sale de las unidades pedidas, no de `periodo_from_id`

`putCopiar` recibe `periodo_from_id` en el cuerpo **y no lo usa para nada**: el
bucle va por id. Apoyar el ayudante nuevo en ese campo sería estrenar una
dependencia que hoy nadie comprueba y que nadie garantiza que case con la lista. El
par `(asignatura, periodo)` se lee de las filas pedidas.

Con **row constructor** y no dos `IN` cruzados:

```sql
WHERE (u.asignatura_id, u.periodo_id) IN ((?,?), ...)
```

Con dos listas sueltas, pedir dos asignaturas de dos periodos se traería los cuatro
pares y copiaría unidades de un periodo que nadie nombró.

### 1.4 · La consulta 274, que es la que listaba el detector

Es la que repinta la estructura del destino en la respuesta. Lleva
`alumno_id is null`: **aquí no hay ningún alumno en el ámbito**, y las unidades de
un independiente entrarían mezcladas **sin nada que las distinga** —la consulta
nombra columnas y `alumno_id` no está entre ellas—, o sea filas que el cliente no
puede atribuir a nadie.

### 1.5 · El contrato: `unidades_copiadas` NO cambia de significado

Copiar de más obliga a decidir qué cuenta el número que ya se devolvía. **Se parte
en dos campos en vez de cambiar el que hay**:

| Campo | Qué cuenta |
|---|---|
| `unidades_copiadas` | **las de la lista que mandó el front**, y sólo ésas — como siempre |
| `unidades_de_independientes_copiadas` | **nuevo**: las que añadió el backend por su cuenta |

Es un **añadido**: quien no lo lea sigue funcionando igual, y `0` es la respuesta
honesta mientras no haya nadie marcado — o sea hoy, en los quince colegios.

> **Y esto se midió en `myvc_front` antes de decidirlo, no se supuso.** Hay **tres**
> consumidores de esta respuesta —`UnidadesCtrl.ts:890`, `CopiarCtrl.ts:432` y
> `app2/.../copiar-unidades.ts:439`— y **ninguno de los tres compara
> `unidades_copiadas` con `unidades_ids.length`**: los tres lo pintan en una cadena
> («Unidades copiadas: N»). O sea que **ningún código se habría roto**.
>
> Se parte igual, y el motivo es el que queda al quitar la reconciliación de código:
> **quien reconcilia es la persona**. El docente acaba de marcar una lista con la
> mano y a continuación lee un número; si es mayor que lo que marcó, no lo puede
> contar en su lista. Un `length` que no existe no era el argumento; el docente sí.

Con eso, la lista de la respuesta sigue siendo la del grupo y no hay ningún número
que no cuadre.

---

## 2 · Los cuatro acotados, y el criterio que los separa de los dos que no

Los cinco sitios acotados de esta noche comparten una propiedad, y merece la pena
escribirla porque es la que decide sin tener que abrir el front:

> **Cuando una consulta pinta o mide una ESTRUCTURA y no recibe ningún alumno, la
> única respuesta con significado es la del grupo** — y el alcance correcto es
> `alumno_id IS NULL`, la segunda forma de la §1.6, no un `<=>` contra un alcance
> que ahí no hay de quién pedir.

| Sitio | Qué mide | Qué pasaba sin acotar |
|---|---|---|
| `Unidad::informacionAsignatura` (:237) | la estructura de una asignatura **con sus tres banderas** | `porc_unidades` sumaría el reparto del grupo **más** el del marcado —100 + 100 = 200— y la pantalla acusaría de mal configurada a una asignatura que está bien |
| `AsignaturasController::putDetalleAsignatura` (:55) | la rejilla de los cuatro periodos + `cantidad_notas` | filas en la columna de su periodo que el cliente **no puede atribuir**, y un avance de la asignatura que cuenta notas de otro boletín |
| `ChangeAsked::datos_de_docentes_este_anio` (:511) | el **porcentaje de avance del docente** | `sum(u.porcentaje) = 100` da 200, la asignatura deja de contar como correcta y **al docente le baja el porcentaje sin haber hecho nada mal** |
| `ChangeAsked::asignaturas_dia` (:1232) | el horario de hoy y de mañana, con su estructura | la pantalla de inicio listaría las unidades de un independiente junto a las del grupo, otra vez sin poder atribuirlas |

**`informacionAsignatura` no cambia de firma**, que era lo que había que comprobar
antes de tocar un modelo. Su hermana `deAsignaturaCalculada` recibe `$alumno_id` y
por eso pregunta a `alcance()`; ésta no recibe ninguno **y no es un olvido**: sus
dos llamantes (`AsignaturasController` :303 y :426) pintan «mis asignaturas» de un
docente.

Los dos primeros son el mismo fallo que ya se le arregló a
`DefinitivasDeAsignatura::porcentajeDeLasUnidades`: *«¿las unidades suman 100?»* con
dos boletines **no tiene una sola respuesta**. Allí se resolvió obligando al llamante
a decir de qué boletín pregunta; aquí no hace falta preguntarlo, porque el llamante
es la pantalla del grupo.

> **Lo que esto deja fuera a propósito.** Si la estructura propia de un
> independiente está rota, el docente no lo ve en ninguna de las cuatro. Lo
> contestan la §6.1 con su `motivo = "sin_estructura_propia"` y
> `tools/independientes-sin-estructura.php` (§9.1), que son los dos sitios que
> existen para eso. Mezclarlo en un porcentaje del grupo no lo avisaría: **lo
> taparía detrás de un número que ya no querría decir nada**.

En `:511` la condición va **sólo en la `u` de fuera**: la derivada `r` entra por
`r.unidad_id = u.id`, así que ya sólo puede emparejar unidades que hayan pasado el
filtro. Repetirla dentro no cambia una fila.

---

## 3 · Los dos que NO se tocan, y por qué

**Un «no se toca» razonado vale lo mismo que un arreglo**, y sin el porqué escrito
el siguiente lo re-litiga. Los dos llevan el motivo **en el propio código**, que es
donde ya vivía el de `selloDeVersion` y `estadoDelGrupo`.

### 3.1 · `Informes/InformesController::grupos_desactualizados` (:107)

Contesta *qué grupos tienen notas más nuevas que sus definitivas*. **No pinta una
estructura: mide una fecha.** Es la familia de los dos sellos de caché, con las dos
direcciones contadas:

- **sobre-aproximar** dice «desactualizado» de más → alguien recalcula sin
  necesidad, y eso cuesta tiempo;
- **acotar** dejaría que la nota de un independiente **no marcara nada** → el
  colegio sirve una definitiva vieja **sin un error en el log**.

Y aquí hay una razón que los sellos no tienen: el independiente **está** en ese
grupo, y su definitiva es justo la que nadie va a echar de menos. **Acotar
escondería precisamente al alumno de la §9.1**, que es el que este módulo entero
existe para no perder.

### 3.2 · `EnviarNotificaciones::avisosDeNotas` (:195)

El camino `nota → subunidad → unidad → asignatura → materia` arranca de **una nota
ya identificada** por `b.affected_element_id` y sube por claves ajenas. No
selecciona un conjunto: **resuelve un nombre**. Una condición sobre `u.alumno_id`
no puede quitar filas de más — sólo quitar la única que hay, y eso es una familia
que se queda sin el aviso.

Y tendría un caso donde lo haría de verdad: marcar a un alumno **no le borra las
notas que ya tiene en las subunidades del grupo** (§1 del plan). Un alcance
correlacionado le daría a esas notas dueño = el alumno y a la unidad `alumno_id`
NULL, no emparejarían, y **el aviso de un cambio real se perdería en silencio**.

> La distinción con `grupos_desactualizados` —que tampoco se acota— es que aquélla
> **agrega sobre muchas notas** y ésta **sube desde una sola**. Los dos son «no se
> toca» y los dos por motivos distintos; copiar el motivo de uno al otro habría
> dejado el segundo sin justificar.

---

## 4 · Los tests, y cuál se pone rojo

`tests/Contrato/CopiarUnidadesTest.php` **se amplió, no se escribió otro**: de tres
casos a seis.

| Test | ¿Rojo contra el código viejo? |
|---|---|
| `copiar_se_lleva_las_unidades_del_independiente` | **Sí** — la unidad propia no llegaba al destino |
| `una_unidad_con_dueno_no_se_copia_como_del_grupo` | **Sí** — `Failed asserting that 5 is identical to 4`: se creaba una del grupo |
| `no_copia_las_del_que_ya_no_va_aparte_en_el_destino` | **No**, y se escribe igual |

El tercero no se pone rojo porque el código viejo **acierta por no hacer nada**: no
copiaba ninguna unidad con dueño. Lo que fija es que el arreglo mire el **destino** y
no el origen, que es el único sitio donde una implementación razonable se equivoca.

> **Y eso se midió, no se afirmó.** Se escribió la implementación ingenua —
> `delGrupo($grupo_to_id, $periodo_from_id)`, o sea el origen en vez del destino— y
> se corrió: el tercero **se pone rojo con su propio mensaje**. Así que su verde
> significa «no puede ponerse rojo con el código viejo», no «no midió nada».

### El escenario está DESEQUILIBRADO a propósito

Los tres casos nuevos copian **dos unidades del grupo y una del independiente**, no
una y una. Con los dos lados valiendo lo mismo, **contar el conjunto contrario da el
mismo número** y la forma ingenua pasa en verde: es la tercera manera de fallar un
test de esta noche, y la levantó el lote A sobre un caso suyo que estaba 1 a 1. Por
eso cada caso afirma **los dos lados** —las suyas y las del grupo— y además el
desglose de la respuesta.

**El caso hay que construirlo**, y ésa es la mitad que cuesta: con nadie marcado no
existe ninguna unidad con dueño, así que la forma correcta y la incorrecta dan el
mismo verde. `contexto()` gana un alumno del grupo (con un `EXISTS` sobre
`matriculas` en la elección de la asignatura, para que no salga una de un grupo
vacío) y una ayuda que le monta una unidad propia.

`unidadesDe()` cuenta con `alumno_id <=> ?` y **nunca `=`**: `null` es el boletín
del grupo, y con el igual a secas esa rama contaría cero y el test verde no diría
nada.

---

## 4.bis · El detector NO ve tres de los cinco, y está aislado

`tools/unidades-sin-alcance.py` con `ce56351` dentro: **de mis cinco sitios acotados
declara `estado = si` en dos y `estado = no` en tres.** Los tres invisibles están
bien; lo que no ve es la forma.

| Sitio | Cómo lo escribí | `estado` |
|---|---|---|
| `AsignaturasController:66` | `u.alumno_id is null` | **si** |
| `ChangeAsked:525` | `u.alumno_id is null` | **si** |
| `ChangeAsked:1256` | `alumno_id is null` | **no** |
| `Unidad.php:275` | `alumno_id is null` | **no** |
| `PeriodosController:327` | `alumno_id is null` | **no** |

La causa está en `alcance_de_unidades()` y es **una asimetría entre sus dos ramas**:

```python
ref = r'(?:\b' + re.escape(alias) + r'\.)?alumno_id'      # alias OPCIONAL
if re.search(ref + r'\s*<=>', sql, re.I):                  #   -> `<=>` desnudo SÍ vale
    return 'si'
if re.search(r'\b' + re.escape(alias) + r'\.alumno_id\s+is\s+(?:not\s+)?null', ...):
    return 'si'                                            # alias OBLIGATORIO
```

Comprobado con las cuatro combinaciones contra la función misma:

```
aliased  IS NULL   -> si
desnuda  IS NULL   -> no      <- las tres mías
aliased  <=>       -> si
desnuda  <=>       -> si
```

**No se ha tocado el SQL para que el detector lo vea.** Escribir `unidades.alumno_id`
en una consulta de una sola tabla sería contorsionar el código para satisfacer al
instrumento, que es justo lo que prohíbe CLAUDE.md: *el primer sitio donde mirar
cuando el número sale raro es el detector, no el código*. `tools/` no es de este
lote; queda reportado.

> **Y el arreglo no es dar la vuelta a la regex**, que es lo que parece. El alias es
> opcional sin riesgo en las tres mías porque son `FROM unidades` **sin un solo
> `JOIN`**: no hay otra tabla que pueda aportar un `alumno_id`. En una consulta con
> `notas` o `matriculas` dentro, un `alumno_id` desnudo puede ser de cualquiera de
> las tres — que es exactamente el aviso de `desnudas` que el detector ya imprime.
> El alias debería ser opcional **sólo cuando `unidades` es la única tabla del
> ámbito con esa columna**, y eso vale para las dos ramas, no sólo para la de
> `IS NULL`: hoy la de `<=>` acepta el desnudo **sin** esa comprobación.

---

## 5 · Cero instantáneas regeneradas

Ninguna de las cinco condiciones nuevas mueve una fila hoy: `bol_ind_periodos` nace
vacía, todas las unidades tienen `alumno_id` NULL y `alumno_id IS NULL` selecciona
exactamente lo de siempre. Es el criterio de aceptación de la §4 del plan.

---

## 6 · Lo que queda anotado para quien siga

- **`putCopiar` sigue escribiendo dentro de un `foreach` sin transacción.** No es
  de esta noche —lo dice ya el primer test del fichero— pero ahora escribe más
  filas por llamada, así que un fallo a mitad deja más a medias que antes.
- **`unidades_copiadas` cuenta ahora también las que el front no pidió.** No se
  cambió el nombre ni se añadió un desglose: sería contrato, y el contrato lo lleva
  quien coordina. Si el front lo enseña como «se copiaron N unidades», el número
  sigue siendo cierto pero ya no es el largo de la lista que mandó.
- **`Unidad::arreglarOrden` está en mi fichero y se deja como está.** Reescribe
  `orden` con `UPDATE ... WHERE id=?` sobre **la lista que le pasa el llamante**, así
  que es correcta por construcción y el detector la da por buena. Lo que decide si
  mezcla dueños es **quién le pasa la lista**, y sus dos llamantes —
  `UnidadesController:167` y `NotasController:109`— son del **lote B**. Si alguno le
  pasa una lista mezclada, un independiente y el grupo se renumeran entre sí; aquí
  no hay nada que arreglar para evitarlo.
- **Copiar unidades con dueño ordena por `(alumno_id, orden, id)`**, así que las
  del grupo y las de cada independiente llegan al destino con su propio `orden`
  intacto y solapado. Es lo correcto —son dos rejillas distintas— pero significa que
  un `ORDER BY orden` sin alcance en el destino las intercala.
