# Lote A — los informes de pérdidas y los boletines finales

> Noche del 31 ago 2026. Árbol `.worktrees/a`, rama `fix/bi-lote-a`, base
> `simonbolivar_testing_a`. Coordinó `8myvc-2a` hasta el traspaso a `8myvc-c1`.

El lote se repartió como **ocho sitios de alcance**. Resultaron ser **cuatro**, y
los otros cuatro llevaban acotados desde antes de la base del reparto. Cómo se
supo está en la §1, porque **la causa de los dos hallazgos es la misma** y es lo
más caro que se movió esta noche.

---

## 1. El detector no veía su propio arreglo, y eso invalidaba el criterio de aceptación de los cinco lotes

`tools/unidades-sin-alcance.py` marcaba como «sin alcance» los cuatro sitios de
`Informes/NotasPerdidasController.php` (`:54 :65 :271 :287`) **que ya estaban
acotados**. La causa no está en el código: está en el detector, que es lo primero
que dice el CLAUDE.md que hay que mirar cuando el número sale raro.

Su unidad de medida es **el literal PHP**, y con razón — su propia cabecera
explica que recortar por línea partiría el `FROM` de su `WHERE`. Pero la forma que
manda la §1.6 del reparto se escribe **concatenando**:

```php
."\n where a.deleted_at is null and u.alumno_id <=> ".BoletinIndependiente::ALCANCE."
```

Eso parte la consulta en **tres** literales. El que lleva `from unidades u` es el
primero y **no contiene el `<=>`**, que cae en el segundo. Comprobado llamando a
las propias funciones del detector:

```
linea  54 | alcance: no | ¿<=> en ESTE literal? False
linea  65 | alcance: no | ¿<=> en ESTE literal? False
linea 271 | alcance: no | ¿<=> en ESTE literal? False
linea 287 | alcance: no | ¿<=> en ESTE literal? False
```

**Lo grave no era el falso positivo: era que todo sitio arreglado esta noche iba a
salir «sin alcance».** La §1.3 pone el detector como comprobación final de los
cinco lotes, así que el criterio de aceptación estaba midiendo lo contrario de lo
que decía. Hoy eran 5 falsos positivos sobre `unidades` —mis cuatro y
`app/Services/DefinitivasDeAsignatura.php:524`—; mañana habrían sido todos.

**No se tocó el fichero**: no es de ningún lote y lo corren las cinco sesiones. Se
avisó, y lo arregló la coordinación (`ce56351`), fundiendo las cadenas contiguas
cuando entre ellas sólo hay una concatenación. Verificó además la dirección
peligrosa —que no *inventara* alcances, porque un `si` falso le diría a un lote
«ya está hecho» cuando no lo está— leyendo los quince uno a uno.

> **La regla que esto confirma, y que ya estaba escrita:** un detector puede contar
> bien un síntoma y no estar contando la causa. Aquí contaba bien «este literal no
> nombra `alumno_id`» y creía estar contando «esta consulta no acota».

### Y de ahí salió el segundo hallazgo, que era el primero disfrazado

`58b5714 fix(bi-2): acota las cuatro lecturas de NotasPerdidasController` es
**ancestro de la base del reparto**. Los cuatro sitios ya llevaban `JOIN_ESTADO` +
`ALCANCE` los dos de grupo y `alcanceCorrelacionado('a.id', 'u')` los dos de
alumno — que es exactamente la forma correcta para la trampa del lote. El reparto
los listaba porque los sacó del detector.

La coordinación corrigió su cifra: **repartió «22 pendientes» y eran 18.** La
diferencia son estos cuatro.

---

## 2. Los cuatro sitios que sí quedaban

Dos métodos privados repetidos en dos controladores, cuatro sitios:

| Fichero | Método | Sitio |
|---|---|---|
| `BolfinalesController.php` | `perdidasPorAlumnoDelGrupo` | `:474` |
| `BolfinalesController.php` | `perdidasPorDefinitivaDelGrupo` | `:536` |
| `Informes/BolfinalesController.php` | `perdidasPorAlumnoDelGrupo` | `:717` |
| `Informes/BolfinalesController.php` | `perdidasPorDefinitivaDelGrupo` | `:765` |

Los cuatro llevan la misma cota:

```php
AND u.alumno_id <=> '.BoletinIndependiente::alcanceCorrelacionado('n.alumno_id', 'u').'
```

### Por qué la correlada y no `JOIN_ESTADO`, con la medición

`perdidasPorAlumnoDelGrupo` **tiene `matriculas m` en el ámbito**, así que la forma
(1) de la §1.6 parecía la que tocaba. No lo es, y no por estilo:

su `FROM` es una **lista de comas** (`FROM notas n, subunidades s, unidades u,
asignaturas a, matriculas m`), y en MySQL **la coma tiene menos precedencia que
`JOIN`**. Un `LEFT JOIN` pegado detrás sólo alcanza a nombrar la última tabla de la
lista, no `u`. Medido contra el esquema real, no supuesto:

```
JOIN_ESTADO tras comas: 1054 Unknown column 'u.periodo_id' in 'on clause'
correlada tras comas:   OK
```

O sea que ahí `JOIN_ESTADO` **no es una fila de más: es un 500**.

Y la segunda razón, que vale para los cuatro: **abarcan varios periodos de una
vez** (`u.periodo_id IN (…)`, y las dos de definitivas ni siquiera filtran
periodo, o sea el año entero) mientras la marca es **por periodo**. Es la misma
trampa que `p.numero <= :periodo` en `NotasPerdidasController`, en otro sitio.

---

## 3. El test, y las tres formas equivocadas contra las que se comprobó

`tests/Contrato/PerdidasDelGrupoAcotadasTest.php`. Con nadie marcado la forma
correcta y la incorrecta dan el mismo verde, así que **construye el caso**: un
alumno marcado en el periodo 1 y con el grupo en el 2, con unidades propias en los
dos, y un alumno normal al lado.

Se comprobó **en rojo** contra las tres maneras de escribirlo mal:

| Forma equivocada | Qué aserción la caza | Qué dijo |
|---|---|---|
| **sin alcance** (el código de antes) | «de más» | `Failed asserting that 2 is identical to 1` |
| **alcance bindeado una vez**, sin correlacionar por periodo | la trampa del lote | `Failed asserting that 1 is identical to 2` |
| **`=` en vez de `<=>`** | «de menos» | `Failed asserting that 0 is greater than 0` |

### Lo que costó y hay que dejar dicho: la primera versión del test NO cazaba la trampa

El control de la forma ingenua **pasó en verde** la primera vez. No porque el
alcance estuviera bien, sino porque en el periodo 2 «las del grupo» y «la suya»
valían **las dos 1**: la forma ingenua contaba justo las contrarias y la aserción
no podía distinguirlo. Se arregló desequilibrando el escenario —**dos** notas
perdidas del grupo en el periodo 2 y una propia—, y entonces el control se puso
rojo.

> Vale como recordatorio de que la §1.4 no se cumple escribiendo el test antes:
> **se cumple ejecutándolo contra la forma equivocada**. Aquí el test estaba escrito
> antes, estaba en verde, y no medía lo que decía medir.

Los métodos son privados y se llaman por reflexión —hay precedente en la suite—
porque **el mapa que devuelven es el resultado**: quien lo lee sólo hace `?? 0`
sobre él.

---

## 4. Lo que se decidió NO tocar

- **`tools/unidades-sin-alcance.py`** — el hallazgo es de la §1 y el arreglo era
  suyo, no mío: no es de ningún lote y lo corren las cinco sesiones. Arreglarlo en
  mi árbol lo habría dejado arreglado en un sitio y roto en cuatro.
- **Los cuatro sitios de `Informes/NotasPerdidasController.php`** — ya estaban, y
  bien. Se leyeron uno a uno antes de decirlo; no se dio por hecho desde el
  mensaje del commit.
- **`app/Services/DefinitivasDeAsignatura.php:524`** — el quinto falso positivo del
  detector. No es de mi lote; se avisó.
- **`Nota::puestoAlumno`** — es una función pura y sigue siéndolo. Sacar al
  independiente del recuento se hace **eligiendo la lista antes de llamarla**, en
  el llamador.
- **`BolfinalesController::definitivasMateriasXPeriodo` (`:177`)** — el detector lo
  lista, pero llega **por nota** (`n.alumno_id = …`): las notas ya son de un alumno,
  así que la consulta no elige de quién son las unidades. `bien por construcción`,
  no un sitio que se dejó.

---

## 5. Cifras

Detector, antes y después de estos cuatro sitios (con el detector ya arreglado):

| | antes | después |
|---|---|---|
| `unidades` con alcance | 15 | **19** |
| `subunidades` con alcance | 18 | **22** |

Los cuatro sitios mueven las dos columnas porque cada `unidades` acotada arrastra
la `subunidades` que hereda de ella.

**Cero instantáneas regeneradas**, que es el criterio de la §4 del plan: con nadie
marcado `alcanceCorrelacionado()` no devuelve ninguna fila, la subconsulta vale
`NULL`, y `u.alumno_id <=> NULL` selecciona exactamente las filas de hoy.
