# Cola 1 — `PUT boletin-independiente/planilla`, la pantalla del docente

> Sesión `8myvc-82` (lote D), rama `fix/bi-lote-d`. §6.1 del
> [19](../19-boletin-independiente.md). Es la **ruta 546** y la segunda de la familia
> `boletin-independiente`.

**La pantalla entera en una petición**: la asignatura, el periodo, los alumnos con
boletín aparte —cada uno con sus unidades, sus subunidades y sus notas— y el recuento
de la estructura del grupo para la vista previa de copiar.

---

## 1 · A quién lista, y no es «todo el grupo»

**A quien tiene un boletín aparte en esta asignatura**, que son **dos** casos:

- los que van aparte (`aplica = 1`);
- **y los que tienen estructura propia guardada aunque el periodo vaya con el grupo**
  (`aplica = false` con `tiene_datos`).

Los segundos son exactamente los que en la planilla del curso llevan el badge
`bol_independiente_datos`, así que **las dos pantallas hablan del mismo conjunto** y una
no puede enseñar a alguien que la otra no conozca. Lo fija el front en su propio
documento: *«incluye a los `aplica: false`, que son justo los que sí están en la tabla
con el badge»*.

Un alumno sin marca y sin nada suyo **no sale**: no hay nada que gobernarle aquí. Hay
test.

## 2 · LA TRAMPA DE ESTA RUTA: se lee por PROPIEDAD y no por alcance

`Unidad::deAsignatura()` resuelve el **alcance**, y para un `aplica = false` el alcance
es `null`, o sea **las unidades del grupo**. Usarlo aquí daría lo contrario de lo que se
pide: la §1 dice que al desmarcar *«no debe borrar los datos … pero esos datos deben ser
ignorados»*, y **esta pantalla es justamente donde se ven los datos que se están
ignorando**. Con el alcance, un `aplica: false` saldría con la estructura del curso
pintada como si fuera suya, y el docente creería que su boletín aparte se ha llenado
solo.

Así que la condición es `u.alumno_id = :alumno` —**afirmación de propiedad**— y no
`u.alumno_id <=> :alcance`.

> **Es el segundo sitio del módulo donde `<=>` sería el error y no el acierto**, y por
> eso conviene decirlo junto: el primero fue el `EXISTS` de `tiene_datos`, que
> `unidades-sin-alcance.py` señala y **está bien**. La regla completa quedó escrita en la
> §1.6 del reparto. `<=>` contesta *«¿qué unidades le TOCAN?»*; estas dos preguntan
> *«¿cuáles son SUYAS?»*, que es otra pregunta y se escribe con `=`.

**Rojo R10**: leyendo por alcance, se pone en rojo
`test_el_desmarcado_con_datos_sale_con_sus_unidades_y_no_con_las_del_curso` y **sólo
ése** de los quince.

## 3 · Los tres `motivo`, y el orden entre dos de ellos

Un vacío dice **por qué** está vacío, y llega en **200**: no se contesta 400 para decir
«no hay». Los tres estados son legítimos.

| `motivo` | Qué pasó |
|---|---|
| `vaciada` | tuvo unidades propias y hoy están **todas borradas**. Sólo se sabe mirando `deleted_at` |
| `asignatura_sin_montar` | **tampoco hay unidades del grupo**: el docente no ha entrado. Les pasa igual a los treinta |
| `sin_estructura_propia` | el grupo sí las tiene y **este alumno no**. Es la §9.1 y el único que la pantalla tiene que gritar |

**`vaciada` se comprueba PRIMERO, y eso hay que fijarlo con un test propio.** Los dos
primeros pueden ser ciertos **a la vez** y sólo viaja uno: `vaciada` es un hecho sobre
**este alumno** y `asignatura_sin_montar` sobre la asignatura. Al revés, a quien le
vaciaron el boletín en una asignatura sin montar se le diría **«el docente no ha
entrado»** — que es lo contrario de lo que pasó: entró, le montó lo suyo y se lo quitó.

> **Y aquí me cacé a mí mismo, que es lo que hay que apuntar.** El primer
> `test_motivo_vaciada` **no ponía en rojo** la inversión de las dos ramas: deja el grupo
> con sus unidades, así que las dos ordenaciones devuelven `vaciada`. O sea que **la
> justificación del orden estaba escrita en el código y no la comprobaba nadie** — un
> comentario haciéndose pasar por una garantía. El caso que la distingue es tener **las
> dos condiciones ciertas**, y es `test_vaciada_gana_a_asignatura_sin_montar`. Con él, la
> inversión sí se pone roja (R11).

## 4 · `estructura_del_grupo`, y por qué no se resuelve con la ruta que ya existe

Cuenta **sólo las del grupo** (`u.alumno_id IS NULL`), que es lo que el diálogo va a
copiar: contar las de todo el mundo diría *«se van a copiar 12 unidades»* cuando se van a
copiar 4. **Rojo R12.**

Existe porque la alternativa está envenenada:
`GET unidades/de-asignatura-periodo/{asignatura}/{periodo}` **escribe** —inserta las
unidades y subunidades por defecto del año, **sin `alumno_id`**, o sea del grupo, y
`Unidad::arreglarOrden` reescribe `orden` en cada lectura—. **Una vista previa montaría
el periodo entero del curso.** Esa ruta **no se cambia**: que lea y escriba es decisión
tomada (05 §47.2) y con el periodo abierto crea queriendo. Lo que se arregla es que el
front no tenga que llamarla.

`porcentaje_unidades` lleva **el mismo nombre y el mismo número** que el de cada alumno,
para que la pantalla no tenga dos campos que significan lo mismo.

## 5 · Lo que NO hace, y son decisiones

- **No escribe nada.** Ni siembra notas ni crea unidades: es la ruta hermana de la que
  escribe, y la que escribe es `PUT boletin-independiente/periodo`. Su `LEFT JOIN` a
  `notas` deja `nota: null` en la casilla que no existe, en vez de crearla.
- **No corrige `porcentaje_unidades`.** Regla 2 de `DefinitivasDeAsignatura` y
  [10 §9.3](../10-definitivas.md): un reparto mal configurado da una definitiva rara y
  **que se note es lo que la delata**. Rojo cubierto.
- **No comprueba el año por su cuenta.** El 404 de una asignatura de otro año lo tira
  `Asignatura::detallada()`, que ya une por el año del token (05 §16). Repetirlo aquí
  sería un segundo sitio decidiendo lo mismo, y el día que los dos mensajes discreparan
  nadie sabría cuál ve el colegio.
- **Una unidad sin subunidades vivas sale con la lista vacía y no desaparece.** Suma
  porcentaje y no tiene dónde poner nota: es media estructura mal montada, y esconderla
  dejaría la suma sin explicación.
- **`CAST(nota AS DOUBLE)` en la definitiva**, porque `notas_finales.nota` es
  `DECIMAL(7,4)` desde el 30 ago y **PDO devuelve un `DECIMAL` como cadena**. Sin el cast
  saldría `"78.0000"` donde las otras diecisiete respuestas mandan un número.
- **`ORDER BY id DESC LIMIT 1` en la definitiva es una degradación consciente**:
  `notas_finales` no tiene clave única sobre (alumno, asignatura, periodo) —es el
  [10](../10-definitivas.md)— así que puede haber dos. Se elige la última escrita, como
  las demás lecturas.

## 6 · La puerta: `auth.personal` y **no** la guarda de la decisión 5

Deliberado, y es la distinción que importa: **marcar** un boletín lo decide el colegio
—administradores, secretario y rector—, pero **montarle las unidades y ponerle las notas
al que ya está marcado es trabajo de aula**, y el docente tiene que poder verlo.
Estrecharlo le quitaría algo que hoy tiene por otras pantallas, que es el razonamiento
con el que `grupos/listado/{grupo_id}` se quedó en `auth.personal` el 31 ago. Hay test por
las dos direcciones: el alumno no entra, el docente sí.

## 7 · Las instantáneas: cuatro, y una se mueve al REVÉS

| Instantánea | Qué cambia |
|---|---|
| `rutas.json` | `PUT api/boletin-independiente/planilla`. **545 → 546** |
| `guards-por-ruta.json` | la ruta entra en la lista de `auth.personal` |
| `guard-por-familia.json` | `boletin-independiente` pasa de `1 de 1` a **`2 de 2`** |
| **`familias-que-nunca-entran-en-el-candado.json`** | **PIERDE la línea `boletin-independiente`** |

> **La cuarta va en dirección contraria y NO es un guard que alguien quitó.** Estaba
> avisado antes de verlo: `FamiliasQueNuncaEntranTest` lista las familias con **menos de
> dos** rutas con guard, y con dos hermanas guardadas esta familia **entra en el candado
> de familia** y deja de necesitar esa lista. Es el mismo mecanismo que la metió ahí al
> nacer, leído al revés.

**Ninguna instantánea de respuesta se movió**: la ruta es nueva y no toca ninguna que ya
existiera.
