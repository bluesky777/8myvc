# Lote S — La única escritura que alcanza una familia

> Sesión `8myvc-2f`, árbol `.worktrees/s`, rama `fix/lote-s-prematricular`,
> base `simonbolivar_testing_s`. Noche del 22 al 23 de agosto de 2026.
> Secciones del [05](../05-codigo-muerto-y-roto.md): **§143–145**.
> Séptimo lote de esta sesión, tras [B](b.md), [F](f.md), [K](k.md), [H](h.md),
> [L](l.md) y [M](m.md).

`PUT matriculas/prematricular` es **la única ruta de la noche en que un acudiente
escribe**. Las demás que toca una familia son lecturas. Y escribe en
`matriculas`, que es la tabla que dice **si un alumno está en el colegio y en qué
grupo**.

Lo encontró el [lote I](i.md) contestando otra pregunta.

---

## Lo que sí funciona, y va primero

**El guard hace bien su trabajo.** `boletin.propio:sin-paz-y-salvo` comprueba **de
quién es el alumno**: con un alumno ajeno son **403** y no se toca ninguna fila.
Está fijado con test, porque es lo que no puede romperse al arreglar lo demás.

Y **un grupo que no existe se para antes de escribir**: 400, con el mensaje que ya
devolvía. La comprobación del grupo está donde tiene que estar.

---

## §143 — Lo que nadie comprueba: el año del grupo y el estado pedido

Los dos vienen del cuerpo, y **la escritura va al año de ese grupo**. Con su
propio acudido —justo lo que el guard permite— un acudiente le pone a la matrícula
del **año en curso** cualquiera de los estados:

| `estado` mandado | La fila queda |
|---|---|
| `MATR` | `MATR` **con su `fecha_matricula`** |
| `PREA` | `PREA` con `prematriculado` |
| `ASIS` | `ASIS` |
| `FORM` | `FORM` |

Los cuatro firmados con el `updated_by` del acudiente, que es lo único que los
hace localizables después. **Y partiendo de `RETI`, la llamada deshace un
retiro.**

> **No se arregla.** Que el grupo tenga que ser del año siguiente es una regla de
> negocio y **la decide el colegio**: hoy la ruta sirve para prematricular, y
> cerrarla por el año es decidir qué significa prematricular. Se mide y se fija,
> que es lo que hay que tener delante el día que se decida.

Y un detalle que no se ve leyendo la ruta y sí ejecutándola: **`anio_sig` sale del
cuerpo con defecto 1**, así que **es el cliente quien elige en qué año se busca la
respuesta**. Con `anio_sig = 0` la consulta final mira el año en curso, encuentra
la matrícula recién escrita y contesta **200** donde si no daría error.

---

## §144 — Contestaba 500 **después** de haber escrito — *arreglado*

La consulta que arma la respuesta busca la matrícula en el año
`$user->year + $anio_sig`, y ese año puede no existir. En el seed, **2026 está en
la papelera**. El `[0]` caía sobre una consulta vacía **con la fila ya cambiada**.

> **El 500 no acusaba a la ruta: acusaba al año que falta.** Lo que acusa a la
> ruta es **lo que ya escribió antes de llegar ahí**.

Y es la peor forma posible **en esta ruta en concreto**, porque es la única que
alcanza una familia: `AnunciosDir.ts` lee `r.matricula.prematriculado` **dentro
del `.then`**, así que con un error no llega nunca. El acudiente ve un fallo sobre
una prematrícula que **sí ocurrió**, y lo natural es volver a darle al botón.

Ahora contesta **404**, que es lo que ya eligieron la [§52](../05-codigo-muerto-y-roto.md)
y la §86 para esta misma forma. Y **el mensaje dice que la escritura se hizo**,
porque eso es lo único que un código de estado no cuenta.

### Las dos mitades van en commits distintos, a propósito

La del año **necesita una decisión** y la del 500 **no**. Juntas, la segunda
—que se puede desplegar sola y no espera a nadie— se volvería indesplegable.

---

## §145 — Desde la pantalla real no se alcanza, y por qué eso importa

Medido en `myvc_front`, que es lo que decide si el fallo está vivo:

| Lo que el front hace | Dónde |
|---|---|
| El desplegable de grupos se llena con **`grados_sig`**, que es `y.year = user->year + 1` | `ChangeAskedController:428` |
| El botón entero **se esconde** si el estado ya es `PREM`, `PREA`, `MATR`, `ASIS`, `RETI` o `DESE` —enseña «Acérquese a secretaría para cambiar»— | `anunciosDir.html:157` |
| El botón exige **`next_year`**, que solo existe si el año siguiente existe | `AnunciosCtrl.ts:1552` |
| Y manda **`estado: 'PREA'`** fijo | `AnunciosCtrl.ts:1563` |
| `myvc_flutter` **no la llama** | comprobado con grep en `lib/` |

**O sea que ni el año en curso ni el `RETI → PREM` se alcanzan desde esa
pantalla.** Es una **mina**, de la misma familia que las tres que la noche ya
tenía: no se nota al desplegar, y espera a que alguien haga lo razonable.

**Lo que encendería el fallo**, en orden de probabilidad:

1. **Que `grados_sig` deje de ser `year + 1`** — un cambio de una palabra en una
   consulta que hoy nadie relaciona con esto.
2. **Otro cliente**: la ruta está abierta a la familia con solo el guard de
   propiedad, y las tres comprobaciones que la sostienen **viven en el front**.
3. Que alguien quite el `ng-hide` de los estados ya decididos, que es lo que hoy
   impide deshacer un retiro.

> Escribirlo importa porque **el número asusta y la situación no lo justifica**:
> «un acudiente matricula a su hijo» suena a incidente en curso y no lo es. Lo que
> sí es, es una comprobación de negocio que **hoy solo existe en la pantalla**.

---

## PARA JOSETH

1. **¿Debe `matriculas/prematricular` exigir que el grupo sea del año siguiente?**
   Hoy no lo mira, y las tres comprobaciones que lo sostienen están en el front.
   (§143)
2. **¿Debe una familia poder mandar el `estado`?** Hoy puede mandar los cinco,
   incluido `MATR` con su fecha. El front manda siempre `PREA`. (§143)
3. **¿Debe poder deshacer un retiro?** Partiendo de `RETI`, la llamada lo
   deshace; la pantalla lo impide escondiendo el botón. (§143)

## PARA OTRO LOTE

- **`ChangeAskedController:305` y `:428`** — las dos consultas de `grados_sig`.
  Si alguna vez cambian de año, **encienden la §143**. No son de este lote y no se
  tocan; queda escrito aquí y en la tabla de despliegue.

## Lo que se nota en un colegio

| Columna | Qué |
|---|---|
| **Deja de pasar** | Que un acudiente reciba **un error después de que su prematrícula sí se haya guardado** — y que vuelva a darle al botón. Ahora es 404 y el mensaje dice que se guardó |
| **Quita capacidad** | Nada |
| **Enciende** | Nada |
| **Riesgo** | **Bajo**: desde la pantalla real, con el año siguiente creado, este camino no se recorre. Se recorre cuando el colegio **todavía no ha creado el año siguiente** |

## Migraciones

**Ninguna.**
