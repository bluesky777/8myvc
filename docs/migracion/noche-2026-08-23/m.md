# Lote M — Descongelar los dos modelos

> Sesión `8myvc-2f`, árbol `.worktrees/m`, rama `fix/lote-m-descongelar-modelos`,
> base `simonbolivar_testing_m`. Noche del 22 al 23 de agosto de 2026.
> Secciones del [05](../05-codigo-muerto-y-roto.md): **§125–127**.
> *(El script asignó §124–126 y la §124 ya la usó el [lote L](l.md). Avisado.)*
> Esta sesión cerró antes los lotes [B](b.md), [F](f.md), [K](k.md), [H](h.md) y [L](l.md).

**Los dos modelos se quedan exactamente como están.** Este documento existe para
que esa decisión no haya que tomarla otra vez, y sobre todo **para que no se
deshaga**: hay un `?? null` de una línea que parece obviamente correcto, y está
medido que es peor por los dos caminos.

---

## La pregunta

`Profesor::detallado()` acaba en `return $profesor[0];` y `Year::de_un_periodo()`
en `Periodo::find(...)->year_id`, las dos **sin comprobar que la consulta trajera
fila**. Con un id que no existe —**o uno de la papelera**, que las dos consultas
descartan— eso es un aviso de PHP 8 que Laravel convierte en excepción: **500**.

Coordinación las congeló y puso una condición: **primero los llamantes**. El
porqué importa y era correcto: un `?? null` habría convertido seis 500 en **seis
comportamientos distintos sin medir cuál es el correcto en cada pantalla**.

Con los llamantes cerrados, la pregunta se puede contestar. Y no se contesta con
criterio: **se mide**.

---

## §125–126 — Lo medido: el mismo arreglo, resultados contrarios

Se puso el `?? null` en cada modelo **sin tocar ningún llamante**, y se llamó a
las rutas.

| Método | Hoy, sin guard delante | Con `?? null` en el modelo |
|---|---|---|
| `Year::de_un_periodo()` | 500 `…"year_id" on null` **en el modelo** | 500 `…"id" on null` **en el llamante, una línea después** |
| `Profesor::detallado()` vía `profesores/show` | 500 `Undefined array key 0` | **200 `[null]`** |
| `Profesor::detallado()` vía `planillas/show-profesor` | 500 `Undefined array key 0` | **200 con la planilla entera montada y el profesor vacío dentro** |

> **El mismo arreglo, en dos modelos, da resultados contrarios.** Y la diferencia
> no está en el modelo: está en **lo que el llamante hace con lo que recibe**.

- **`de_un_periodo`**: el llamante desreferencia el resultado en la línea
  siguiente, así que el null **no esconde nada** — mueve el 500 una línea y **le
  quita el nombre que lo identificaba**. `year_id` señala la consulta del periodo;
  `id` no señala nada. Peor de diagnosticar, igual de roto.
- **`detallado`**: el llamante **mete el resultado en la respuesta**, así que el
  null sale por la API. Un 200 con `[null]` en un caso y, en el otro, **un informe
  con su cabecera montada y el profesor en blanco**. **Un 500 se ve; una planilla
  impresa a nombre de nadie, no.**

### §127 — La regla que sale de los dos

**La respuesta a «¿debe el modelo devolver null?» no está en el modelo.** Y por
eso no puede tener una sola respuesta para seis llamantes de tres dominios.

Es la [§89](../05-codigo-muerto-y-roto.md) —*arregla la operación, no el sitio*—
con un matiz que la noche no había escrito:

> **Aquí la operación no es «leer un profesor»: es «contestar una petición».** Y
> eso lo decide la ruta, no el modelo. Un modelo que devuelve null está tomando
> por su cuenta una decisión de contrato en seis pantallas a la vez.

Lo que sí es del modelo es **fallar de forma diagnosticable**, y eso ya lo hace:
`Undefined array key 0` en `Profesor.php` nombra el índice y el archivo.

---

## Lo que este lote **no** puede afirmar todavía

La ficha del lote traía una puerta: *no midas hasta que el lote E esté fundido*.
**Comprobado en el árbol raíz, no en la ficha:**

```
$ git log --oneline main | grep "lote E"     # vacío
main: 5f4da32 (A, B, C, D y F)
```

**E no está fundido.** Así que de los seis llamantes de `detallado()`:

| Llamante | Estado en `main` |
|---|---|
| `AsignaturasController:275` | **cerrado** con 404 (lote D, §96) |
| `UnidadesController:186` | **cerrado** con 404 (lote D, §96) |
| `ProfesoresController:298` | **sin cerrar en `main`** — el arreglo vive en `.worktrees/e` |
| `PlanillasController:105` | idem |
| `Informes/PlanillasAusenciasController:70` | idem |
| `Informes/NotasPerdidasController:135` | idem |

**Las dos mediciones de `detallado()` se hicieron contra dos de esos cuatro**, que
es lo que `main` tiene hoy. Cuando E entre, esas dos rutas contestarán 404 antes
de llegar al modelo y **esa medición dejará de ser reproducible por ahí** — no
porque cambie la conclusión, sino porque el guard llegará antes.

> **La conclusión no depende del recuento**: depende de **qué hace cada llamante
> con el valor**, y eso se lee llamante a llamante. Por eso la tabla de arriba
> nombra la ruta y no dice «dos de seis».

`Year::de_un_periodo()` **no tenía puerta**: su único llamante,
`AsignaturasController:417`, es del lote D y **está fundido**. Por eso su medición
está completa y la de `detallado()` no.

### Lo que queda por hacer cuando E entre

Una sola cosa, y está preparada: **repetir la comprobación al revés sobre los
cuatro llamantes de E** —quitarles el 404 y poner el `?? null`— para confirmar que
los cuatro se comportan como los dos medidos. Si alguno **no** metiera el
resultado en la respuesta, ése sería el caso que cambia la regla, y hay que
buscarlo antes de dar el §127 por cerrado.

---

## PARA JOSETH

**Ninguna.** Este lote no pide decisiones: las dos que había —qué contesta cada
ruta— ya las tomaron los lotes D y E con su 404.

## PARA OTRO LOTE

- **Al fundir el lote E**, el §127 queda a falta de la comprobación al revés sobre
  sus cuatro llamantes. Está descrita arriba y es media hora.

## Lo que se nota en un colegio

**Nada.** Este lote no toca `app/`: los dos modelos quedan exactamente como
estaban, con siete tests que caen el día que alguien les ponga el `?? null` sin
leer esto.

## Migraciones

**Ninguna.**
