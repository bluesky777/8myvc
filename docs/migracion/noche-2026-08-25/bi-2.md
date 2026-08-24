# BI-2 — acotar el boletín independiente

**Sesión `8myvc-12`** · rama `fix/boletin-independiente-alcance` · sobre `main` con las
cuatro ramas de la noche del 24 fundidas.

> **Estado: en curso.** Cerrado lo de abajo; las 59 lecturas, en marcha.

---

## §1. Lo primero fue el detector, y no cuadraba

La ficha del lote avisa: *«antes de fiarte de un número suyo, comprueba que su población
cuadra con la del documento»*. **No cuadra.**

    documento bi-1.md:   88 bien   57 acotar   1 sin saber   = 146
    detector hoy:        84 bien   59 acotar   1 sin saber   = 144

Y **antes de acusar a nadie hay que separar tres cosas que dan el mismo síntoma**: que el
detector derivara, que el código cambiara, o que el documento se equivocara. Se separan
pasando el instrumento por los dos objetos:

| | total |
|---|---|
| herramienta **de entonces** sobre código **de entonces** (`de42d90`) | **144** |
| herramienta **de hoy** sobre código **de entonces** | **144** |
| herramienta **de hoy** sobre código **de hoy** (`main` fundido) | **144** |

**El total nunca fue 146.** El detector no ha derivado y el código no movió el total: **la
cifra del documento está mal**, y su columna «bien» —88— es la única que reproduce exacto.
*El cajón que el documento acertó es justo el que más importa, y el que falla es el de
trabajo.* Corregido por `8myvc-94` en `bi-1.md`, que es quien lleva ese documento.

### Y el primer detector que falló fue el mío, en la cabeza

Sumé las filas de la sección «cómo elige cada lectura su conjunto de filas» y me dio
**133**, y estuve a punto de reportar «133 contra 146». Esa sección **no lista todas**: el
número que el detector llama lectura está en sus cabeceras de bloque —`unidades: 74` +
`subunidades: 70`—.

> **Mi forma de contar era otro detector, y estaba mal.** Es la regla de la casa mirándose
> al espejo: cuando el número sale raro, el primer sitio donde mirar es el detector — **y
> el detector puede ser el que uno lleva en la cabeza.**

---

## §2. Una conclusión mía, retirada media hora después de escribirla

**Lo que dije:** que el arreglo del 504 (`2837171`) había movido **cuatro lecturas** de
«bien por construcción» a «hay que acotarla», y que por tanto había creado cuatro sitios
que mezclarían las unidades del grupo con las de un independiente.

**Lo que es cierto:** el reparto **sí** cambió —88/55 pasó a 84/59— y las cuatro son las
dos agregaciones nuevas, contadas dos veces cada una (`unidades` y la `subunidades` que
hereda).

**Lo que era falso: que hubieran perdido el alcance.** Leídas enteras:

    perdidasPorAlumnoDelGrupo     ... AND n.alumno_id IN (los del grupo) ...
    perdidasPorDefinitivaDelGrupo GROUP BY n.alumno_id, u.asignatura_id, u.periodo_id

**El alumno sigue siendo el ancla, en el `WHERE` y en el `GROUP BY`**, y el mapa se
consume con `$perdidasDelGrupo[$alumno->alumno_id]`. La consulta original hacía lo mismo
una vez por alumno; la nueva lo hace para todos a la vez. **Mismo alcance, distinta
forma.**

Lo que pasó es que el arreglo **añadió `a.grupo_id = ?`** para poder agregar por grupo, y
**el predicado del detector decide con el filtro más grueso**: ve grupo/asignatura y
etiqueta `por-asignatura`, sin ver que `n.alumno_id` sigue ahí.

> **La versión que se sostiene:** *un cambio de forma que no toca el alcance puede mover
> una lectura de cajón, porque el clasificador decide por el filtro más grueso.* Cuatro de
> las 144 lo hicieron con el arreglo del 504, y **ninguna de las cuatro perdió el
> alcance**.
>
> **La versión que se retira**, y se deja escrita para que no se vuelva a escribir: *«una
> optimización que quita el 80% de las consultas puede mover cuatro lecturas de un cajón
> al otro»*. Es cierta de las etiquetas y **falsa del riesgo**, y publicada manda a alguien
> a acotar dos consultas que ya están acotadas.

---

## §3. La muestra del cajón «bien»: 12 de 12, y encontró lo que no buscaba

**Doce sitios**, elegidos **por variedad de forma y no al azar** —7 `por-nota`, 5
`por-id`, nueve ficheros distintos, incluidos `Services/` y `Support/`— y **rederivados
leyendo el SQL, sin volver a llamar al detector**. *Si para comprobar el cajón hay que
llamar a la herramienta que lo hizo, no se ha comprobado nada.*

| forma | qué las hace seguras | comprobadas |
|---|---|---|
| `por-nota` | cuelgan de una `notas` filtrada por `alumno_id` o por `n.id` | 7 |
| `por-id` | una fila por su id (`unidades WHERE id=?`, `subunidades WHERE unidad_id=?`) | 5 |

**Las doce correctas. El cajón se sostiene y no hay que recensarlo.**

**Y la muestra encontró una forma de fallo que no estaba en la clasificación** — que es lo
que distingue una muestra de un trámite. Va en la §4.

---

## §4. El alcance no se pierde en la lectura: se pierde en el traspaso

**Ficha de `BI-3 · los traspasos`.** No entra en BI-2 —*un lote que se redefine a mitad no
se cierra*— y el censo de traspasos es **otro barrido**: otra pregunta, otro detector, otro
número.

**Dos sitios cuya LECTURA es impecable y cuya CONSECUENCIA no lo es:**

**1. `SubunidadesController::postIndex` (`:86`)** — deriva el grupo desde la unidad con
`unidades WHERE u.id = ?`, una fila por su id, del cajón «bien». Y con él llama a

    Nota::verificarCrearNotas($grupo->grupo_id, $subunidad, $user->user_id)

que **recibe un `grupo_id`, recorre `Grupo::alumnos($grupo_id)` e inserta una nota por
cada uno**. Nadie le dice de quién es la unidad. **Medido**: sobre una unidad cuyo dueño
es un alumno, el alta de una subunidad crea notas para **37 de 37**.
Fijado en `tests/Contrato/SubunidadDeUnaUnidadConDuenoTest.php`, grupo `rojo`.

**2. `DefinitivasDeAsignatura::recalcularPorUnidad`** — lee la unidad por id (segura) y
entrega a `recalcular($asignatura_id, $periodo_id)`, que es **`por-asignatura`, del cajón
«acotar»**. El alcance no se pierde en la lectura: se pierde al entregarlo.

> **La clasificación es por lectura y no puede ver que una lectura segura entregue su
> resultado a una insegura.** No es un fallo del detector —no es su pregunta—: **es un
> agujero del método**, y por eso ninguno de los dos está entre las 59.

---

## §5. Las 59 — en curso

**Van separadas a propósito y no en un total:** **55 heredadas** del inventario de BI-1 y
**4 nacidas** con el arreglo del 504 (§2), que **no perdieron alcance** pero cambiaron de
etiqueta. *El número que hereden las sesiones de mañana tiene que traer pegado de dónde
salió cada mitad.*

La regla de cada una: si acotarla **no mueve la respuesta**, entra con su test de ida y
vuelta delante; si la mueve, **no entra** y se anota con el cuerpo exacto que cambiaría.
Y **las dos preguntas del [19 §2](../19-boletin-independiente.md) son de Joseth**: si una
de las 59 depende de ellas, se queda fuera y se anota.
