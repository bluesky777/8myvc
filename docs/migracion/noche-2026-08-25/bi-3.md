# BI-3 — los traspasos

**Rama:** `fix/alcance-en-los-traspasos` · **Sesión:** `8myvc-e0` ·
**Noche del 25 ago 2026**

**Lote de censo. No se cambió el alcance de ninguna llamada.**

[BI-2 §4](bi-2.md) dejó la frase y dos casos: *el alcance no se pierde en la
lectura, se pierde en el traspaso*. Esto es ese censo, con su detector y su
número.

---

## 1. El resultado

    POBLACION: 222 ficheros PHP, 58 lecturas acotadas por id,
               5 traspasan a una dimension mas ancha.
    De esas 5: 3 SIN ACOTAR y 2 que si llevan el alcance estrecho en la llamada.

| | sitio | traspaso |
|---|---|---|
| **SIN ACOTAR** | `SubunidadesController:94` `postIndex()` | `unidades` → `grupo_id` → `Nota::verificarCrearNotas()` |
| **SIN ACOTAR** | `DefinitivasDeAsignatura:149` `recalcularPorUnidad()` | `unidades` → `asignatura_id` → `recalcular()` |
| **SIN ACOTAR** | `DefinitivasDeAsignatura:170` `recalcularPorSubunidad()` | `subunidades` → `asignatura_id` → `recalcular()` |
| acota con `alumno_id` | `DefinitivasDeAsignatura:122` `recalcularPorNota()` | idem, **pero pasa `$soloAlumno`** |
| acota con `alumno_id` | `NotasController:805` `deleteDestroy()` | idem |

**Los dos que BI-2 midió a mano están. El tercero sin acotar es nuevo.**

---

## 2. Lo que hace el censo accionable: **el mecanismo de acotar ya existe**

`DefinitivasDeAsignatura::recalcular()` acepta un cuarto argumento **`$soloAlumno`**
y filtra por él (`:192`). Tiene **tres puertas**, y:

    recalcularPorNota        pasa $donde->alumno_id   ->  ACOTADO
    recalcularPorUnidad      no lo pasa               ->  toda la asignatura
    recalcularPorSubunidad   no lo pasa               ->  toda la asignatura

**No es que no se pudiera acotar: es que a dos de las tres se les pasó.** Y las
dos están vivas — `recalcularPorUnidad` la llaman **2** sitios de
`UnidadesController`; `recalcularPorSubunidad`, **3** de `SubunidadesController`.

### Y el detalle que enseña por qué el censo por lectura no podía verlo

`SubunidadesController:97` —una de esas tres— es **la línea siguiente** a la `:94`
que midió BI-2:

    $grupo = DB::selectOne('SELECT a.grupo_id FROM unidades u ... WHERE u.id = ?', ...);
    Nota::verificarCrearNotas($grupo->grupo_id, ...);                        // :94
    DefinitivasDeAsignatura::recalcularPorSubunidad((int) $subunidad->id, ...);  // :97

**El mismo método hace los dos traspasos**, y el censo anterior vio uno porque
miraba `verificarCrearNotas`. *No es que mirara mal: es que miraba otra cosa.*

---

## 3. El detector, y las dos veces que estuvo mal antes de estar bien

`tools/alcance-en-los-traspasos.py`. Busca una asignación `$v = DB::select(…)` cuyo
SQL lea de una tabla **estrecha** filtrando por id, y después, en el mismo método,
un `$v->campo` usado **como argumento de una llamada** con `campo` de dimensión más
ancha. La escala —lo único que el guion sabe del dominio— es:

    alumno  <  unidad/subunidad  <  asignatura  <  grupo  <  periodo/year

### 3.1 El control ejecutable lo pilló al primer intento

`--control` exige encontrar **los dos casos que BI-2 midió a mano**, y **sale con
código 1 si falta alguno**. Falló a la primera: encontraba **1 de 2**. Se le
escapaba

    self::recalcular((int) $donde->asignatura_id, ...)

porque la expresión **prohibía cualquier paréntesis** entre la llamada y la
variable — **y un cast es lo bastante común para que un detector que lo ignore
parezca funcionar**. Con el arreglo pasó de **1 a 5**.

> **La primera versión perdía el 80% y su salida no tenía ningún aspecto
> sospechoso.** Un control positivo escrito en prosa —*«tiene que encontrar X»*—
> no habría hecho nada: **nadie ejecuta una frase.**

### 3.2 Y la población salió rara, que fue la segunda

Decía **17 lecturas en 222 ficheros**. Medido el hueco: **133 `DB::select` con SQL
inline frente a 558 que reciben una variable**, y **100** asignaciones
`$consulta = '… WHERE … id = ?'`. **El detector veía menos del 20% del terreno.**

Al seguir la variable dentro del método, la población pasó de **17 a 58** — y **los
hallazgos siguieron siendo 5**.

> **El terreno se triplicó y no apareció ningún traspaso nuevo.** Eso es lo que
> hace creíble el 5, mucho más que el 5 solo. *Un número no gana credibilidad
> repitiéndolo: la gana cuando cambia su denominador y él no se mueve.*

### 3.3 Lo que no ve, dicho antes de que alguien lea un 0 como un OK

- **Sólo `DB::select`/`DB::selectOne`.** No ve Eloquent, ni query builder, ni SQL
  armado con `.=`. Es el hueco por el que `escrituras-sin-auditoria.php` dijo 32
  donde había 52, y allí **lo que no veía eran cuatro dominios enteros**.
- **Sólo dentro del mismo método**, y **no sigue variables intermedias**
  (`$g = $fila->grupo_id; f($g);` se le escapa).
- **No sabe si la ampliación es correcta**, y casi siempre lo es.

**Su número es una cota baja por los cuatro lados.** Un 0 no diría «no hay
traspasos»: diría «no hay traspasos de esta forma, en `DB::select`, dentro de un
método».

### 3.4 La columna que lo hace utilizable

El detector marca **`SIN ACOTAR` / `ACOTA con …`** según si en la misma llamada
viaja también un campo de dimensión estrecha. **No filtra: informa.** Sin esa
columna las cinco salen iguales y **la fila que está bien cuesta lo mismo de
revisar que las que no**.

---

## 4. El rojo

`tests/Contrato/RecalculoPorUnidadConDuenoTest.php`, `#[Group('rojo')]`.

**No duplica el de BI-2**: aquél fija la línea **94** —crea **notas** al grupo—; éste
fija la **97** —recalcula **definitivas** de toda la asignatura—. Medido al caer:

    Recalcular por la unidad 15252 movio la definitiva de 21 alumno(s)
    que no son su dueno, de 56 con definitiva en esa asignatura.

### El control va DENTRO, y aquí está lo que descarta

Antes del aserto que importa, el test llama a `recalcular()` **con** `$soloAlumno` y
exige que toque **una** fila. Si tocara todas, la premisa —*que `recalcular` sabe
acotar y a estas dos puertas se les pasó pasárselo*— **sería falsa**, y el rojo no
estaría midiendo un traspaso: estaría midiendo que el recálculo no distingue a
nadie, **que es otro problema y tiene otro arreglo**.

Sin eso, el test **cae igual por el motivo equivocado y manda a arreglar el sitio
equivocado.** El control pasa: 5 asertos, y el que falla es el último.

> Y una que me toca: **el docblock prometía ese control antes de que existiera.**
> Lo escribí describiendo lo que el test haría y lo implementé después de verlo
> caer. Es exactamente lo que este repositorio lleva dos días cazando —*prometer lo
> que no se mide*— cometido en el fichero que venía a fijar un método.

---

## 5. Lo que NO entra

- **No se tocó `verificarCrearNotas` ni `recalcular`.** Recalcular la asignatura
  entera **es lo correcto en el caso normal** —una unidad del grupo afecta a
  todos—, así que cambiar de dónde sale el alcance **es una decisión**, no un
  arreglo.
- **Lo que la decisión tiene delante ahora**: el mecanismo existe, se usa en una de
  las tres puertas, y las otras dos tienen **cinco llamadores** entre las dos.
- **No se rebasó la rama.** Sale de `d9d8b4e` y `main` lleva 48 commits más, así que
  el test de BI-2 **no está en este árbol**: se leyó con `git show main:` para no
  duplicarlo. Al fundir son **dos ficheros distintos**, no un conflicto.
