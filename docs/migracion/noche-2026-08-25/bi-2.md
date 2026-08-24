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

### Y una corrección que no llegó a salir de la sesión

**Estuve a punto de reportar que casi todas las 59 dependían del 19 §2 y que el lote
estaba bloqueado.** Era falso: **BI-1 ya había resuelto el cómo**. `BoletinIndependiente`
trae `alcance($alumno, $periodo)` para las consultas con alumno en la mano y las
constantes `ALCANCE` + `JOIN_ESTADO` para las que resuelven un grupo entero, y con
`matriculas.boletin_independiente` a 0 en todas las filas y `unidades.alumno_id` NULL en
todas, **el alcance resuelve a `NULL` y `u.alumno_id <=> NULL` selecciona exactamente las
filas de hoy**. *Se vio por abrir el servicio antes de escribir el parte, no después.*

### Hecho

**`DefinitivasDeAsignatura::calcular()` — el recalculador único.** La derivada agrupa
además por `u.alumno_id` y el emparejamiento va con `<=>` contra `ALCANCE`; el `LEFT JOIN`
a `bol_ind_periodos` lleva el periodo por parámetro porque `u` vive dentro de la derivada
y no está en el ámbito de fuera — la excepción que la cabecera del servicio pide declarar
en vez de copiar a mano.

Con **control positivo**, que es lo que lo hace valer:

    sobre el código SIN acotar  ->  falla: «el alumno 460 está marcado como de boletín
                                    independiente y NO tiene unidades propias […] siguió
                                    llevándose la del grupo»
    sobre el código acotado     ->  pasa, 42 aserciones

`Tests\Contrato\DefinitivaConAlcanceTest` tiene **dos mitades a propósito**: que hoy no
se mueva nada, y que marcar a un alumno cambie **sólo lo suyo**. *La primera sola pasaría
idéntica sobre el código de antes — comprobar que algo no cambió no prueba que el cambio
esté.*

---

## §6. `porcentajeDeLasUnidades()` — se anota, y no por su firma

**La única de las 59 donde acotar no es añadir una condición.** Contesta *«¿las unidades
de esta asignatura suman 100?»* y devuelve **un `float`**. Con independientes **esa
pregunta deja de tener una sola respuesta**: hay un reparto por boletín. No es una
acotación mal hecha ni un cambio de firma — **la función está definida sobre un mundo
donde cada asignatura tiene un solo reparto**, y ese mundo es el que el 19 viene a
terminar. Qué significa «suman 100» con dos boletines es del **19 §2**.

### Sus consumidores, que es lo que convierte la nota en ficha

| quién | qué hace con el número |
|---|---|
| `DefinitivasDeAsignatura::recalcular()` | lo devuelve en `porcentaje_unidades` |
| `NotasController:392` — **el único que guarda ese retorno** | lee **sólo** `definitiva` |
| `myvc_front` · `myvc_front_2` · `myvc_flutter` | **cero menciones** en los tres repos |

**Hoy no hay ningún consumidor que decida nada con él.** El suyo está **previsto y no
construido**: la cabecera del servicio dice que se devuelve *«para que quien pinte la
planilla pueda señalarla en vez de taparla»*.

> Eso lo hace **barato de dejar y peligroso de olvidar**: el día que alguien construya esa
> señal, la construirá sobre un número que ya está mal y **nada se lo dirá**. **El primero
> que se rompe es un consumidor que aún no existe**, y ésos no aparecen en ningún censo de
> llamadores — que es justo por qué la anotación sola no bastaba y hay un rojo puesto.

Fijado en `Tests\Contrato\PorcentajeDeUnidadesConIndependienteTest`, grupo `rojo`.
**Qué lo pondría verde**: que reciba el alcance y devuelva el reparto de **ese** boletín,
o un mapa `[alcance => float]`.

---

## §6.bis. El criterio del lote, aplicado a ciegas, mete un fallo

**Hay al menos un sitio de los 25 donde acotar es INCORRECTO**, y quien coja BI-3 o BI-4
tiene que leer esto antes que la tabla.

`DefinitivasDeAsignatura::selloDeVersion()` calcula un **sello de versión** —el
`MAX(updated_at)` de unidades, subunidades y matrículas de la asignatura— para decidir si
hay que recalcular.

    sin acotar   el sello cambia cuando un independiente toca SU unidad
                 -> se recalcula de más.  Cuesta tiempo.  Nunca sirve un dato viejo.

    acotado      el sello deja de moverse cuando cambia el boletín de ese alumno
                 -> se sirve un dato VIEJO, sin ningún error.

> **La regla «acotar es siempre más correcto» es falsa.** Aquí la sobre-aproximación no es
> un defecto que se tolera: **es lo que lo hace correcto**, porque el modo de fallo de este
> dato no es simétrico — de más cuesta tiempo, de menos miente.

El porqué queda **pegado al código** y no sólo aquí, que es lo que impide que alguien lo
«arregle» en la pasada siguiente aplicando el criterio del lote sin mirar qué calcula.

---

## §6.ter. Cómo se corre esto sin comerse el docker de la noche

La suite entera tarda **~17 minutos** y es el criterio de aceptación de cada acotada. Con
siete sesiones y dos carriles, una suite por acotada **serializa la noche**. El
procedimiento acordado con quien coordina:

| | |
|---|---|
| **por acotada** | sólo sus tests dirigidos **y su control positivo** — segundos, y es lo que de verdad prueba esa acotada |
| **por tanda** (cinco o seis) | la suite entera, **una vez** |
| **siempre** | **un commit por acotada, nunca uno por tanda** |
| **si la tanda sale roja** | **no se arregla dentro**: se bisecta, la culpable sale a su propio commit y la tanda se cierra sin ella |

**Y el coste que se acepta a sabiendas:** la suite no corre entre acotada y acotada, así
que **una interacción entre dos de la misma tanda no se ve hasta el final**. Con un commit
por acotada eso cuesta **una bisección**; sin él, cuesta la noche. *Meter el arreglo dentro
de la tanda que ya está roja es cómo se pierde el rastro.*

---

## §7. Veredictos, uno a uno

**25 sitios distintos** (los 59 cuentan `unidades` y la `subunidades` que hereda por
separado). Con veredicto **5**; aplicado **1**. Lo que queda, abajo.

| sitio | veredicto |
|---|---|
| `Services/DefinitivasDeAsignatura:324` `calcular` | **ENTRA** — acotada, con control positivo (§5) |
| `Services/DefinitivasDeAsignatura:460` `porcentajeDeLasUnidades` | **SE ANOTA** — la pregunta pierde su respuesta única (§6), rojo puesto |
| `Services/DefinitivasDeAsignatura:375` `selloDeVersion` | **NO ENTRA, y es inocuo** — ver abajo |
| `Models/NotaFinal:267` `calcularAsignaturaPeriodo` | **NO ENTRA — sin camino** |
| `Informes/BolfinalesController:611`, `:659` | **YA ACOTADAS por el ancla** — son las cuatro de la §2 |

### `selloDeVersion`: la sobre-aproximación es la segura

Calcula un **sello de versión** —`MAX(updated_at)` sobre unidades, subunidades y
matrículas de la asignatura— para saber si hay que recalcular. Si un independiente
toca su unidad, el sello cambia para todos: **se recalcula de más, nunca de menos.**

> **Acotarlo lo empeoraría en la dirección peligrosa.** Un sello que ignore las unidades
> de un boletín deja de moverse cuando ese boletín cambia — y entonces sirve un dato
> viejo. *Aquí la sobre-aproximación no es un defecto que se tolera: es lo que lo hace
> correcto.* Se deja, y se deja escrito **por qué**, que es lo que impide que alguien lo
> «arregle» en la pasada siguiente.

### `NotaFinal::calcularAsignaturaPeriodo`: sin camino, y dos documentos que dicen lo contrario

**No la llama nada en todo `app/`** — comprobado, y ya lo habían encontrado
independientemente el [05](../05-codigo-muerto-y-roto.md) y
[med-5](../noche-2026-08-24/med-5.md). Es el camino viejo que `DefinitivasDeAsignatura`
sustituyó.

**Pero dos documentos siguen listando sus llamadores como si existieran:**

    10-definitivas.md:27       «al crear/editar/borrar unidad o subunidad
                                (UnidadesController:221,249, SubunidadesController:160,189)»
    noche-2026-08-23/o.md:204  «sí, los cuatro»

Son de antes de la fase 3, y **las cuatro llamadas se quitaron al cablear el recalculador
único**. Se deja anotado y **no se corrige aquí**: los documentos del plan los funde quien
coordina. *Es la misma forma que el 146 de `bi-1.md` — un número correcto cuando se
escribió que nadie volvió a mirar.*

### Lo que queda: 20 sitios

`AsignaturasController:55` · `ChangeAsked:511,1232` · `DefinitivasPeriodos:108` ·
`EnviarNotificaciones:195` · `Informes/Informes:107` · `NotasPerdidas:54,64,269,284` ·
`Notas:71,148` · `Periodos:274` · `Subunidades:345` · `Unidades:64,359,398` ·
`Models/Unidad:73,184`

Las cuatro de `NotasPerdidasController` anclan en `notas` de alumnos que salen de
`matriculas` del grupo, **así que tienen `m` en el ámbito** y les vale la misma forma que
a `calcular()`: `JOIN_ESTADO` + `ALCANCE`.

### Y una de las veinte no es un sitio: son diecisiete decisiones

**`Models/Unidad:73` `deAsignatura($asignatura_id, $periodo_id)` tiene 17 llamadores en 13
ficheros**, y `informacionAsignatura` otros 2. Acotarla es añadirle un `?int $alcance` —y
entonces **cada uno de los diecisiete decide qué pasarle**:

- los que están **dentro de un bucle de alumnos** —boletines, `editnota`, certificados—
  tienen el alumno en la mano y les vale `BoletinIndependiente::alcance()`;
- los de **planilla y ausencias** resuelven una asignatura entera sin alumno, y ahí vuelve
  la pregunta de la §6: *¿las unidades de qué boletín?* — que es del 19 §2.

> **No es una lectura sin acotar: es un reparto de diecisiete.** Meterlo en BI-2 es
> exactamente el «lote que se redefine a mitad y no se cierra». **Va con su censo de
> llamadores a un lote propio**, y este documento deja la lista hecha para que quien lo
> coja no la rehaga.


---

## §8. La tanda siguiente, analizada antes de tocarla

Seis sitios leídos. **Cinco entran, uno se anota** — y dos de los que entran traen una
trampa que se escribe **antes** de tocar el SQL, no después.

### El criterio que separó la tanda, y sirve para las que quedan

> **Una consulta que calcula algo POR ALUMNO tiene alcance decision-free** —es el suyo, y
> no hay nada que preguntar—. **Una que construye una vista DE GRUPO, no**: qué ve el
> profesor en la rejilla cuando uno de los treinta lleva boletín propio es del
> [19 §2](../19-boletin-independiente.md).

Es la línea que separa `calcular()` —entró sin preguntar nada— de `porcentajeDeLasUnidades`
—se anotó—, y ahora también parte esta tanda.

| sitio | veredicto |
|---|---|
| `Informes/NotasPerdidas:54` · `:269` | **ENTRA** — la fila es (alumno, nota), y el alumno sale de `matriculas` del grupo |
| `Informes/NotasPerdidas:64` · `:284` | **ENTRA** — `where a.id=:alumno_id`, un alumno concreto |
| `NotasController:148` | **ENTRA** — dentro del bucle por alumno (`n.alumno_id=:alumno_id`) |
| `NotasController:71` | **SE ANOTA** — construye las columnas de la rejilla del grupo |
| `DefinitivasPeriodos:108` | **ENTRA, con cuidado: escribe** |

### `NotasController:71` — por qué no entra aunque parezca la más fácil

Lista `unidades WHERE asignatura_id = ? AND periodo_id = ?`: **son las columnas de la
planilla del profesor**. Acotarla a `<=> NULL` conserva el significado de hoy —las del
grupo— y es demostrablemente neutra, **pero decide un producto**: con esa acotación, las
unidades propias de un independiente **no aparecen en la rejilla y el profesor no puede
ponerle nota**. Sin ella, aparecen mezcladas y la rejilla deja de ser un rectángulo.

**Las dos opciones son decisiones, no acotaciones.** Se anota.

### Las dos trampas, escritas antes de tocar nada

**1. `NotasPerdidas` puede abarcar VARIOS periodos.** Su `$periodo_sql` es a veces
`p.numero <= N`, y `bol_ind_periodos` es **por periodo**: un alumno puede ir por
independiente en el 3 y no en el 2. Así que **`alcance($alumno, $periodo)` bindeado una
vez no vale** — hace falta la forma con `JOIN_ESTADO`, que correlaciona por
`bip.periodo_id = u.periodo_id`. *Bindear un solo valor daría el alcance del periodo
equivocado para el resto, y nada lo señalaría.*

**2. `DefinitivasPeriodos:108` escribe, y la forma obvia de acotarlo puede DUPLICAR.**
Es un `DELETE` + reconstrucción de `notas_finales`. Su consulta interior no une con
`matriculas`, así que para comparar con `ALCANCE` hay que traerla — y ahí están las dos
maneras de romperlo:

    INNER JOIN matriculas  ->  deja fuera al alumno con notas y sin matrícula en ese grupo
                               (hoy los hay: es la §1.1 del 10, lo que este proyecto vino a
                               arreglar) — la respuesta SE MUEVE
    LEFT JOIN matriculas   ->  no deja a nadie fuera, pero un alumno con DOS matrículas en
                               el mismo grupo multiplica las filas y la `SUM()` SE DOBLA

> **Acotar una consulta que agrega no es añadir una condición: es cambiarle el conjunto de
> filas a un `SUM()`.** Por eso ésta no entra en la misma pasada que las otras cuatro
> aunque su veredicto sea el mismo: necesita su propio test de ida y vuelta **con un alumno
> de dos matrículas dentro de la transacción**, y ése es el caso que ninguna de las 1.329
> pruebas de hoy ejerce.
