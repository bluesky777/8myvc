# Cola 2 — `POST boletin-independiente/copiar`

> Sesión `8myvc-82` (lote D), rama `fix/bi-lote-d`. §6.2 del
> [19](../19-boletin-independiente.md). **Ruta 547** y tercera de la familia
> `boletin-independiente`.

Montarle a alguien la estructura que ya existe en vez de a mano. **POST porque crea
filas**, al revés que sus dos hermanas.

---

## 1 · La trampa: los dos orígenes se leen con alcances CONTRARIOS

| `origen.tipo` | Qué filas de `unidades` lee |
|---|---|
| `grupo` | **`u.alumno_id IS NULL`** |
| `alumno` | **`u.alumno_id = origen.alumno_id`** |

**Las dos preguntas viven en el mismo método**, y el fallo es mudo por los dos lados:

- un `=` copiado a la rama del grupo **devuelve cero filas y copia una estructura vacía
  en 200** — la pantalla dice «copiado» y no hay nada;
- un `IS NULL` copiado a la rama del alumno **copia el curso entero** creyendo que copia
  a la persona.

Por eso las dos ramas van **escritas aparte y con su condición entera**, no con una
variable que alguien pueda pasar al revés. Y por eso **cada origen tiene su propio caso
de test**: uno parametrizado que recorriera los dos con la misma aserción **pasaría con
las dos ramas escritas igual**, que es exactamente el fallo.

> **Y ningún test se conforma con el 200.** Todos cuentan **las filas que quedaron
> escritas**, porque es lo único que distingue una copia de un cero. Es la regla de la
> casa —mirar el resultado y no el estado— aplicada al sitio donde más barato sale
> saltársela.

**Rojos R13 y R14**, uno por rama: cruzando las condiciones caen **3 y 4** casos
respectivamente, y son casos distintos. Ninguna de las dos inversiones produce un error.

## 2 · `si_ya_tiene`, y `reemplazar` no borra lo que parece

`saltar` (defecto) · `anadir` · `reemplazar`.

**`reemplazar` no borra ni una nota.** Medido en `UnidadesController::deleteDestroy`:
retirar una unidad es un **borrado en blando de la unidad y de nada más** — subunidades y
notas conservan su `deleted_at` a null y siguen ahí, y salen de los cálculos porque cada
lectura une `u.deleted_at IS NULL`. **`PUT unidades/restore/{id}` la devuelve entera con
sus notas dentro.**

Por eso el campo es **`notas_que_dejan_de_contar`** y no `notas_borradas`: *«se borrarán
9 notas»* es **falso** y asusta de una forma que hace que el docente no use el botón. El
test lo comprueba **por el lado que importa**: que la nota siga con `deleted_at` a null
después de reemplazar, o sea que `restore` seguiría funcionando.

Y **la cifra se cuenta ANTES de retirar**: después, la misma consulta las seguiría
contando —el borrado es de la unidad, no de la nota— y el número saldría igual sin decir
nada.

`anadir` **puede dejar la suma por encima de 100 y no se corrige**. Que un 160 se vea es
lo que lo delata (regla 2 de `DefinitivasDeAsignatura`). Hay test.

## 3 · Lo que se comprueba contra el periodo de DESTINO

Sólo se copia a quien va por independiente en `periodo_id`. Quien no, vuelve como
`resultado: "no_marcado"` **y nunca como 400**: la pantalla los estaba listando de buena
fe y que uno se desmarque entre la carga y el clic es normal; un 400 tumbaría el lote
entero por un alumno.

**Rojo R16**: comprobándolo contra el periodo de **origen**, a un alumno marcado en el
origen y **no** en el destino se le escribiría estructura propia en un periodo que va con
el grupo.

## 4 · `con_notas` son DOS implementaciones, no una con un parámetro

- **Entre periodos distintos → 422.** Copiar la estructura del 1 al 3 es preparar la
  planilla; copiar también las notas es **escribir en el 3 las calificaciones del 1**.
  Desde la pantalla las dos casillas parecen igual de inocentes, así que **no lo puede
  decidir el navegador**.
- **Mismo periodo, origen `grupo`** → las notas que **el propio destino ya tenía** en las
  subunidades del curso. Es lo que hace útil la operación: iba en la planilla, se le marca
  a mitad de periodo y **se lleva lo suyo**.
- **Mismo periodo, origen `alumno`** → las **del alumno de origen**. Eso es calificar a
  varios de golpe, y por eso `con_notas` nace apagado.

**Quien escriba sólo la segunda creerá que ha hecho las dos**: en las dos el SQL sale de
`notas` por `subunidad_id` y lo único que cambia es **de quién es el `alumno_id`**. Cada
una tiene su caso.

## 5 · Sólo la misma asignatura, y se RECHAZA en vez de ignorarse

`origen.asignatura_id` no existe y da **422**. `asignaturas` es `(materia_id, grupo_id)` y
**no tiene `periodo_id`**, así que «otro periodo» ya cabe; lo que ese campo abriría es
**otra materia o, peor, otro grupo** — el docente de 5A tirando de la estructura de 11B.
Y **esa puerta ya existe y es otra**: `PUT periodos/copiar`.

**Se rechaza y no se ignora**, que es la parte que importa: ignorar un campo que el
cliente manda deja al docente creyendo que copió de otra asignatura **habiendo copiado de
la suya, en 200**.

## 6 · Una transacción, y el recálculo FUERA y por alumno

Es lo que aprendió `PUT notas/lote`: media copia deja definitivas calculadas sobre
estados intermedios. **Por alumno y no por asignatura**, porque lo que cambió es **su**
boletín y no el reparto del curso: recalcular el grupo entero reescribiría las treinta
definitivas para arreglar una.

**No se reutiliza `PUT periodos/copiar`**: escribe en un `foreach` **sin transacción**,
según su propio test de contrato.

## 7 · LO QUE ME CACÉ, y es la segunda vez esta noche

**R15 no se puso rojo.** El `UPDATE` que retira lleva `AND alumno_id = ?` además del
`IN (...)`, y yo había escrito en su docblock que la invariante *«tiene su test en los dos
sentidos»*. **Es falso para esa línea**: los `$ids` salen de un `SELECT` que ya filtró por
`alumno_id = destino`, así que **la condición del `UPDATE` es inalcanzable** y quitarla no
rompe nada.

La invariante **sí** está probada —por el `SELECT`, y
`test_reemplazar_no_toca_las_del_grupo_ni_las_de_otro` la cubre por los dos lados—, pero
el segundo candado es **defensa, no garantía medida**. Se queda —el día que alguien cambie
de dónde salen los ids, la escritura sigue acotada— **y ahora lo dice el propio comentario**.

> Es exactamente lo mismo que pasó con el orden de los `motivo` en la cola 1: **un
> comentario que documenta una protección con su razón, y que al quitarla no se pone
> rojo, es un comentario haciéndose pasar por un test.** Dos veces en dos rutas seguidas,
> así que no es un descuido: es la forma que tiene este error de aparecer cuando uno
> escribe la explicación **a la vez** que el código.

## 8 · Las instantáneas: TRES, no cuatro

| Instantánea | Qué cambia |
|---|---|
| `rutas.json` | `POST api/boletin-independiente/copiar`. **546 → 547** |
| `guards-por-ruta.json` | la ruta entra en `auth.personal` |
| `guard-por-familia.json` | `boletin-independiente` de `2 de 2` a **`3 de 3`** |

**`familias-que-nunca-entran-en-el-candado.json` NO se mueve**, y conviene decirlo porque
se esperaba que sí: **ya salió de esa lista en la cola 1**, cuando la familia llegó a dos
hermanas guardadas y entró en el candado de familia. Una familia sólo sale de esa lista
una vez; la tercera ruta no la vuelve a sacar.

**Ninguna instantánea de respuesta se movió**: la ruta es nueva.
