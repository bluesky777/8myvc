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

## La comprobación al revés, hecha ya con el lote E fundido

La ficha traía una puerta: *no midas hasta que E esté fundido*. Cuando abrí el
lote **no lo estaba** —`main` iba por `5f4da32` con A, B, C, D y F— y de los seis
llamantes de `detallado()` solo había **dos cerrados**, los del lote D. Con E
dentro (`8ab9b9a`, `2dc6462`) se hizo lo que quedaba.

**La condición que puse yo mismo para cerrar el §127 era:** buscar si alguno de
los cuatro llamantes de E **no** mete el resultado en la respuesta, porque ése
cambiaría la regla. Quitados sus cuatro guards y puesto el `?? null`:

| Ruta | Con el guard puesto (hoy) | Sin guard y con `?? null` |
|---|---|---|
| `profesores/show/{id}` | 404 | **200 `[null]`** |
| `planillas/show-profesor/{id}` | 404 | **200 con la cabecera del informe y sin filas** |
| `planillas-ausencias/show-profesor/{id}` | 404 | idem |
| `notas-perdidas/show-profesor/{id}` | 404 | idem |

**Los cuatro contestan 200. Ninguno cambia la regla: la confirman.**

### Y el mecanismo es más fino de lo que se lee

Los tres informes **sí desreferencian** el resultado —`$profesor->nombres_profesor`,
`->apellidos_profesor`, `->foto_nombre`— así que leyendo el código parecería que
un null reventaría igual. No revienta, y el porqué es el hallazgo:

> **La desreferencia está dentro de un `foreach` sobre las asignaturas del
> profesor, y un profesor que no existe no tiene asignaturas.** El bucle no entra
> nunca. **La línea que habría reventado es inalcanzable justo cuando el id es
> malo.**

O sea que el null no se cuela *a pesar* de la desreferencia: se cuela **porque la
desreferencia depende de datos que ese id no tiene**. Es la misma forma que ha
dado casi todo esta noche —*mira el resultado, no el estado*— aplicada a una
lectura de código: **el `foreach` parece la protección y es la puerta**.

Con esto el §127 queda cerrado sobre **los seis llamantes**: cuatro medidos aquí y
los dos del lote D con sus tests de §96.

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
