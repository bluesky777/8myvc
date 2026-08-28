# DESACT-1 — «recalcular un grupo no lo saca de la lista de desactualizados»

**28 ago 2026.** Lo trajo la sesión del front del tablero de Informes de `app2`, y
lo que preguntaba no era lo que pasaba. De perseguirlo salieron cuatro cosas: el
fallo real (del front), dos defectos del backend que nadie había mirado, una
divergencia entre los dos recalculadores que **contradice una decisión de Joseth de
esta misma mañana**, y una corrección mía.

Todo lo de aquí está medido contra la base de desarrollo, no leído. Las
simulaciones de escritura corrieron dentro de transacciones con `ROLLBACK`.

---

## 1. Qué compara `periodos_desactualizados`, para que no haya que releerlo

`InformesController::grupos_desactualizados()` ([:99-132](../../../app/Http/Controllers/Informes/InformesController.php#L99-L132)),
un bucle por cada periodo del año. Por periodo, **dos `MAX` por grupo**:

- `MAX(notas.updated_at)` — `notas → subunidades → unidades` (acotadas al periodo)
  `→ asignaturas → grupos` del año;
- `MAX(notas_finales.updated_at)` — del mismo periodo, por `asignaturas → grupos`;
- y `INNER JOIN ... ON n.grupo_id = nf.grupo_id AND n.updated_at > nf.updated_at`.

Es `updated_at`, no `created_at`, y es **por grupo**, no por asignatura ni por
alumno.

## 2. El síntoma no era del backend, y la forma en que engañaba merece quedar

El aviso no desaparecía al recalcular. La causa: dentro de `grupos_desactualizados`
la clave del grupo se llama **`grupo_id`, no `id`** —la consulta la aliasa a mano
(`g.id as grupo_id`)— mientras que **en el nivel de periodo sí es `id`**, porque
eso es un `SELECT *` de `periodos`. El front leía `grupo.id`, salía `undefined`,
`HttpClient` omitía la propiedad y el backend recibía la petición sin `grupo_id`.

**Y no se notaba por ningún lado**: `grupo.abrev` y `grupo.nombre` sí existen, así
que los botones se pintaban con su abreviatura correcta. Un nombre de clave
equivocado sólo en `id` deja la pantalla perfecta y el botón inerte.

Lo que hace el backend con `grupo_id` ausente, medido:

| `periodo_id` enviado | borra | escribe | respuesta |
|---|---|---|---|
| 30 (el id) | 160 | 170 | `Calculado` |
| 1 (el número) | **0** | **0** | **`Calculado`** |

`a.grupo_id = NULL` no es cierto para ninguna fila, el bucle del `INSERT` no se
ejecuta ni una vez, y el método termina en `return 'Calculado'`. Es la familia de
las **respuestas que mienten** ([05 §74](../05-codigo-muerto-y-roto.md)): un 200
que no distingue «recalculé 170» de «no hice nada».

> **No se renombró el alias.** Esa respuesta la lee también `myvc_front` en tres
> sitios ([09 §90](../09-pendientes.md)), y cambiar una clave del JSON es tocar el
> contrato de los quince colegios para arreglar algo que se arregla en una línea de
> un cliente que aún no está desplegado.

## 3. El detector sub-informa, y falla hacia el lado seguro

El `MAX` es **por grupo**, así que una asignatura desactualizada queda tapada
cuando otra del mismo grupo se recalculó después y arrastra el máximo hacia arriba.

Medido, aplicando el mismo criterio por asignatura:

```
asignaturas desactualizadas (criterio por asignatura):  47
  en grupos que el tablero SÍ marca:                    41
  en grupos que el tablero NO marca:                     6   <- invisibles hoy
pares grupo+periodo que el tablero marca:               17
pares grupo+periodo que se le escapan:                   4   (2025 per2: g98, g100, g103, g105)
```

Ninguno de más: los 17 que marca son ciertos. **No se ha tocado**, por decisión de
Joseth de esta mañana («no hagas nada con eso todavía»).

## 4. El botón borra más de lo que escribe — y mi corrección

Simulando el recálculo real sobre los 17 pares desactualizados: los 17 quedan
limpios, y **4 tienen pérdida neta, 295 definitivas en total**.

**Aquí me equivoqué y esto es lo que más vale de este documento.** Conté
`borradas − insertadas` y lo presenté como «definitivas que desaparecen», que se
lee como notas perdidas. El número era correcto; el encuadre, no. Es exactamente
la forma de fallo que el [CLAUDE.md](../../../CLAUDE.md) tiene escrita —contar bien
un síntoma sin comprobar qué se está contando— y la cometí después de citarla.

Lo que son de verdad, mirando el contenido de las filas:

| Caso | Se pierden | Nota | Qué son |
|---|---|---|---|
| 2025 per2 g104 | 146 | **todas 0** | 5 asignaturas vivas, con docente, **sin ninguna unidad en el periodo 2** |
| 2025 per2 g101 | 80 | **todas 0** | 2 asignaturas, lo mismo |
| 2025 per3 g105 | 43 | **todas 0** | 1 asignatura, lo mismo |
| 2024 per1 g84 | 33 | **con nota > 0** | **una sola asignatura, la 1103, borrada el 17 ene 2025** |

**269 son ceros y 33 son restos de una asignatura borrada. Ninguna es una nota viva
perdida.** Las 33 caen porque el `DELETE` **no** filtra `a.deleted_at` y el `INSERT`
**sí**: se las lleva y no puede reponerlas.

Y en el camino conté mal una segunda vez: al clasificar el g84 encontré 14 filas con
nota que «se perdían» y **eran `manual = 1`**, o sea que el `DELETE` ni las toca.
`borradas − insertadas` y «filas que hoy están y el cálculo no cubre» **no son el
mismo conjunto**, y mezclarlos da dos respuestas distintas al mismo número.

### Qué le pasa al boletín de uno concreto

Alumno **917**, grupo 104, periodo 2: tiene 15 definitivas y perdería 5. Lo que
imprime hoy su boletín, con la consulta literal de
[`BoletinesController:270`](../../../app/Http/Controllers/Informes/BoletinesController.php#L270):

```
EDUCACIÓN ÉTICA          p1=41  p2=0
TECNOLOGÍA E INFORMÁTICA p1=30  p2=0
FILOSOFÍA                p1=32  p2=0
EDUCACIÓN RELIGIOSA      p1=32  p2=0
EDUCACIÓN FÍSICA         p1=43  p2=0
LENGUA CASTELLANA        p1=37  p2=3   <- ésta sí se repone
```

O sea que **el boletín de 30 alumnos matriculados imprime hoy un cero en cinco
asignaturas de un periodo que nunca se calificó.** Tras el recálculo esas casillas
quedan vacías en vez de con un 0.

**La definitiva del año no cambia**, y aquí también me corregí: la calculé primero
con la rama de `>3` periodos y daba un salto de 21 a 41. Con la fórmula real
([`BoletinesController:274-291`](../../../app/Http/Controllers/Informes/BoletinesController.php#L274-L291)),
con dos o tres periodos es `round(suma / numero_periodo)` y quitar un cero no mueve
la suma. Comprobado con `numero_periodo` a 2, 3 y 4: **igual en las seis
asignaturas.**

**Joseth aprobó el 28 ago** que esas casillas pasen de imprimir `0` a quedar vacías.

> **Y esto es una casualidad de estos datos, no una propiedad del botón.** El
> mecanismo sigue siendo «borro todo lo automático del grupo y repongo sólo lo que
> tenga notas vivas hoy», sin `a.deleted_at` en el `DELETE` y con él en el `INSERT`.
> Con una unidad borrada por error, el mismo botón se lleva definitivas buenas y
> contesta `Calculado` igual.

## 5. Quién escribe una definitiva a cero en un periodo sin unidades

La pregunta que quedó abierta, y la que más puede destapar: los 269 ceros los
escribió alguien.

**El mecanismo está reproducido y vive hoy en los quince colegios.** No es el botón:
es **borrar la última unidad de una asignatura en un periodo**.
`UnidadesController::deleteDestroy` llama a
`DefinitivasDeAsignatura::recalcularPorUnidad` **después** del borrado —y a
propósito, «no se filtra `deleted_at` porque el caso que más lo necesita es justo el
borrado»—, el servicio no encuentra ninguna unidad viva, y su **regla 1** («los
alumnos salen de `matriculas`, no de `notas`») le hace escribir **una fila a cero
por cada matriculado**.

Medido, en transacción, sobre la asignatura 1300 del grupo 104 en el periodo 2:

```
1 unidad viva · definitivas antes: 31 filas, 14 ceros
-> se borra la unidad, se dispara recalcularPorUnidad
recalcular() -> escritas=30  creadas=0  porcentaje_unidades=0
definitivas después: 31 filas, 31 ceros
escritas con la firma de quien borró: 30 filas, todas nota=0
```

Coincide con la firma de los datos de 2025: **un lote por asignatura, ~30 filas, un
instante, todas a cero, `updated_by` de una cuenta de docente.** Lo que **no** puedo
demostrar es que fuera este camino el que escribió aquellas filas: el servicio es de
agosto de 2026 y la rejilla de definitivas —el otro sospechoso— lleva su guarda
`&& $alumnos[$i]->updated_at_def_N` **desde el primer commit** (`2971bfb`), así que
no pudo. El autor histórico queda sin probar; el mecanismo vivo, probado.

Y el propio servicio lo tiene escrito como riesgo conocido, en el docblock de
`duenoDeLaUnidad`: *«aparecerían definitivas a cero donde no había ninguna, firmadas
por quien editó la unidad de otro»*.

### Decidido y hecho: sin unidades no se escribe

**Joseth, 28 ago 2026**, sobre tres salidas: no escribir (elegida), borrar lo
viejo, y medir antes. Implementado en `DefinitivasDeAsignatura::recalcular`.

**La guarda pregunta «¿hay unidades vivas?» y NO por `porcentaje_unidades`**,
aunque la decisión se enunciara así. Dos motivos y ninguno es el dato de hoy:
el esquema **no impide** `porcentaje = 0` —hoy 0 pares de 3.930, y una medición
usada como guardián es lo que aquí ya costó una noche—, y
`porcentajeDeLasUnidades()` es **la única de las 59 lecturas sin acotar** al
boletín independiente, con su rojo ya puesto en
`PorcentajeDeUnidadesConIndependienteTest`. Colgar una escritura de ese número
sería atarla a algo que ya se sabe que con dos boletines no tiene una sola
respuesta.

**No borra lo que ya hubiera**: la decisión fue no escribir, no limpiar. Las
**884 celdas** que ya tienen su cero sembrado se quedan y seguirán enseñando `0`
hasta que alguien pase el botón por ese grupo. Joseth lo eligió sabiendo que
convivirán dos aspectos. **No hay barrido de borrado y no está autorizado.**

Fijado por `tests/Contrato/SinUnidadesNoSeEscribeTest.php`, cuatro casos, y los
tres primeros existen para separar los dos conjuntos que dan el mismo síntoma:

| Situación | ¿Se escribe la fila a cero? |
|---|---|
| La asignatura no tiene ninguna unidad en el periodo | **NO** |
| Hay unidades y este alumno no tiene notas | **SÍ** — es la regla 1, intacta |

**El control, visto en rojo**: quitando la guarda cae el primer caso y **el
tercero sigue verde**, que es lo que demuestra que la condición separa los dos
conjuntos y no corta de más. Un test que sólo comprobara el primero pasaría igual
si alguien deshace la regla 1 por el camino.

## 6. La divergencia que contradice la decisión del 28 ago

Joseth decidió esta mañana: **«el recálculo debe cubrir a todos los alumnos,
incluidos los que se fueron, igual que hacen los informes»**.

La primera respuesta fue «ya se cumple, nada que redesplegar», y **estaba
incompleta**. La respuesta depende de a cuál de los dos recalculadores se le
pregunte:

| Quién recalcula | ¿Filtra por `matriculas.estado`? |
|---|---|
| `PUT definitivas_periodos/calcular-grupo-periodo` | **no** — entra quien tenga notas |
| `App\Services\DefinitivasDeAsignatura` | **sí** — `m.estado IN ("MATR","ASIS")` |

Y no son alternativas: el servicio es **la fase 3** del [10](../10-definitivas.md),
el que sustituye al botón y a los otros cinco escritores, y **ya está vivo** en
`unidades/*`, `subunidades/*` y `notas/*`.

Medido sobre un caso real (asignatura 126, periodo 1, alumno RETI 13):

```
recalcular() -> escritas=17   ¿tocó al RETI? NO — el servicio lo deja fuera
¿lo incluye el botón calcular-grupo-periodo? SÍ
```

Y lo que hay que no perder, sobre las 379 combinaciones grupo+periodo de la base:

```
pares (alumno, asignatura) que el botón repone, por estado de matrícula:
  MATR           118.133   94,44 %
  RETI             6.435    5,14 %   <- los repone
  SIN MATRICULA      442    0,35 %   <- también
  PREM/ASIS/DESE      72    0,05 %
  TOTAL          125.082
```

**La regla de Joseth se cumple en el que se va y se incumple en el que llega.** Sin
nada escrito, la fase 3 se la lleva por delante sin un solo error: sólo un retirado
que deja de tener definitiva.

### Decidido: el filtro fuera, y **no es «como los informes»**

La primera respuesta a Joseth fue «ya se cumple, nada que redesplegar», y **estaba
incompleta**: valía del botón y sólo de él. Con la tabla de arriba delante decidió
**quitar el filtro entero** — `RETI`, `PREM` y `DESE` dentro, como el botón—, y
descartó expresamente «como los informes de verdad (MATR+ASIS+PREM)» y dejarlo como
estaba.

**Lo eligió sabiendo que es MÁS que los informes.** Su razonamiento original era
«igual que hacen los informes, que sí muestran a los retirados», y eso resultó
falso: el boletín y `Grupo::alumnos` admiten `MATR`, `ASIS` y `PREM`, y **ninguno
enseña a los retirados**. Así que habrá definitivas calculadas para alumnos que no
salen en ningún papel — el histórico se conserva aunque su boletín no se imprima.
Quien venga a «alinear esto con los informes» estaría **deshaciendo la decisión, no
completándola**, y por eso el porqué está escrito en el propio `calcular()`, donde
estaba la línea.

**El duplicado que esto podría abrir, medido**: `matriculas` no tiene clave única
sobre `(alumno_id, grupo_id)`, así que sin el filtro un alumno con dos matrículas
vivas en el mismo grupo daría dos filas. Hoy **0 pares de 3.542**, con filtro y sin
él — y no se apoya en ese dato: `recalcular()` decide **por si la fila existe**, así
que la segunda vuelta actualiza en vez de insertar.

### Fijado por `tests/Contrato/RecalculoYLaMatriculaTest.php`

Ya no fija la divergencia: fija que **los dos** recalculadores reponen al retirado,
y su mensaje de fallo nombra la línea que lo deshace. Es la conducta que costó
encontrar, y `m.estado IN ("MATR","ASIS")` es lo que cualquiera vuelve a escribir
creyendo que limpia.

**El control, visto en rojo** (con el filtro todavía puesto): añadiéndole al
`SELECT` del botón el `inner join matriculas ... and mm.estado in ("MATR","ASIS")`,
el caso del botón cae por su propia aserción con el 200 intacto. La primera versión
del control caía por un error de SQL —reusaba un parámetro con nombre— y eso **no
es ver el control en rojo**: es verlo caer por otra cosa.

> **Aviso para quien venga a «limpiar» el filtro que falta.** En el `SELECT` del
> botón hay un sitio que toca `matriculas` y **no** es un filtro de estado: la
> subconsulta de `BoletinIndependiente::alcanceCorrelacionado`. Sólo mira
> `boletin_independiente`, y cuando el alumno no tiene matrícula devuelve `NULL`
> —«no es independiente»—, que es por lo que los 442 sin matrícula entran. Quitarla
> creyendo que es el filtro de los retirados rompe el [19](../19-boletin-independiente.md).
> Población hoy: **0 matrículas con `boletin_independiente = 1` y 0 filas en
> `bol_ind_periodos`**, o sea que está dormida entera.

---

## 7. Lo que cambia en el boletín, y la condición con la que se aprobó

La guarda de la §5 vive **dentro del servicio**, así que alcanza a todos sus
llamantes — y el grande no es el que la motivó. `BoletinesController:191-197` no
recalcula lo que va a pintar: **recorre todas las asignaturas del grupo** y
recalcula las que `estaDesactualizada` señale. O sea que **era el boletín el que
sembraba el cero al abrirse**.

Consecuencia: `PUT boletines` de un alumno devuelve `null` en seis campos que antes
siempre venían — `nf_id`, `nota_asignatura`, `desempenio`, `manual`, `recuperada`,
`created_at`. **Es un cambio de contrato**, y el snapshot
`boletines-detailed-notas.json` se regeneró **a propósito y con permiso**, no por
inercia: cambia esas seis líneas y ninguna más.

Cuánto se ve, medido:

```
pares asignatura+periodo, total:            4.474
   sin ninguna unidad viva:                   559   (12,5 %)

celdas de boletín (alumno, asignatura, periodo), MATR/ASIS/PREM:
   en pares sin unidades:                  10.532
      con fila hoy (siguen en 0):             884   ( 8,4 %)
      SIN fila hoy (estrenan null):         9.648   (91,6 %)
```

**No cae despacio: cae la primera vez que alguien abre cada boletín**, porque hoy
es el propio boletín el que inventa el cero al abrirlo. Los 884 son la excepción.

Los cuatro clientes los midió la sesión del front y **ninguno se rompe**: `app2`
hace `parseFloat(String(null))` → `NaN` que no marca y `Number(x ?? 0)` en la
gráfica; el `app/` viejo hace `Number(null)` → 0, idéntico al cero de hoy;
`myvc_flutter` no llama a `boletines` y tiene los seis campos tipados anulables.

### La condición de Joseth, y es del backend

Aprobó el `null` **con una condición**: *«quisiera que saliera vacío, nulo, pero si
el usuario edita el input vacío espero que pueda crear y guardar el nuevo valor
manual»*.

El front comprobó su mitad. **La del backend es la rama `else` de
`putUpdate`** —la que no recibe `nf_id`, resuelve el periodo por `num_periodo` e
inserta con `manual = 1`—, y antes de esta guarda casi nunca hacía falta, porque el
boletín sembraba la fila al abrirse. **Ahora es la única puerta por la que nace la
definitiva de una casilla vacía.** Atada por
`test_una_casilla_sin_fila_se_puede_crear_escribiendola`.

Y encaja con la §4: nace `manual = 1`, así que **lo que el docente escriba en una
casilla vacía sobrevive al botón**, cuyo `DELETE` respeta las manuales.

> Si algún día se «limpia» `putUpdate` quitando esa rama por parecer un duplicado
> de la de arriba, **las casillas vacías se vuelven de sólo lectura** y no hay
> ningún error que lo diga: el front manda la petición y recibe un 4xx que parece
> de permisos.

---

## Lo que queda para Joseth

1. **Los 4 grupos que el tablero no marca** — §3. Parado a petición suya.
2. **¿`calcular-grupo-periodo` debe devolver qué hizo** en vez del literal
   `'Calculado'`? — §2. Cambia el contrato de una ruta que corre en los quince; lo
   plantea la sesión del front.
3. **Las 884 celdas con el cero ya sembrado.** No se limpian —no está autorizado— y
   convivirán con las vacías hasta que el botón pase por su grupo.

## Y lo que hay que desplegar

Las dos escrituras de esta noche cambian **comportamiento vivo en los quince
colegios**, y ninguna es un arreglo interno:

| Cambio | Qué se nota |
|---|---|
| Filtro de matrícula fuera de `calcular()` | los retirados vuelven a tener definitiva; `unidades/*`, `subunidades/*`, `notas/*` y el boletín |
| Sin unidades no se escribe | el boletín deja de inventar el cero: 9.648 celdas pasan a vacío |

**Van juntas o no van**: por separado, la primera sin la segunda ensancha a los
retirados el sembrado de ceros que la segunda viene a quitar.
