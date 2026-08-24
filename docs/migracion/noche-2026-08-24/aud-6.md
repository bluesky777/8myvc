# AUD-6 — la fase 0 de los dieciséis, en una visita

> **Sesión `8myvc-39`, noche del 24 ago 2026.** Entrega:
> `tools/fase-cero-de-los-dieciseis.php`. **Escrito y probado, NO corrido**: es
> servidor y no es de esta sesión.

---

## 1. Qué junta, y qué compra

Había **cuatro `for` de sólo lectura** pendientes, en cuatro documentos, con cuatro
formatos y **cuatro visitas** a un servidor de cPanel al que se entra a mano. Por
eso llevaban días sin correrse — y uno de ellos, los dieciséis números de la fase 0
de las definitivas, **es lo único que bloquea su fase 2**.

Cuatro visitas no son cuatro veces el trabajo de una: son la diferencia entre
hacerse y no hacerse.

| Bloque | La pregunta | De dónde viene |
|---|---|---|
| 1 · identidad y población | qué base es, qué MySQL, qué zonas, cuántas filas | nuevo, y **va primero** |
| 2 · interruptores | ¿hay alguno de los 49 encendido en algún colegio? | [int-1.md](int-1.md) |
| 3 · el rol `Admin` | ¿hay algún `Admin` sin `is_superuser`? | [09 §14](../09-pendientes.md) |
| 4 · bitácora | `salud-de-la-bitacora.php --csv` | fase 0 del [18](../18-auditoria.md) |
| 5 · definitivas | `salud-de-las-definitivas.php` | fase 0 del [10](../10-definitivas.md) |

```bash
# en el servidor, una vez
php tools/fase-cero-de-los-dieciseis.php --csv $(cat /ruta/colegios.txt) > fase0.csv
```

## 2. Las dos medidas se **delegan**, no se reteclean

Es la decisión de diseño que más importa y va contra la letra del encargo —«una
única pasada por colegio, abre la conexión una vez»—, así que el motivo:

**Una medición reteclada es una segunda medición, y dos mediciones de lo mismo
acaban discrepando.** Este repositorio tiene la cicatriz: la herramienta de la fase
0 de definitivas **medía de menos** —contaba duplicados dentro del alcance mirado
cuando un índice único mira la tabla entera— y se corrigió **en ella**. Copiar aquí
sus consultas reintroduciría esa clase de error y dejaría dos sitios que arreglar
cada vez.

**El precio, dicho: no es una conexión por colegio, son tres.** Lo que se compra no
es una conexión: es **una visita y un formato**, que es lo que faltaba.

Y su salida se emite **verbatim**: este guion no la interpreta, así que no puede
equivocarse al interpretarla.

## 3. Que no pueda escribir — tres capas, y la tercera se comprueba

1. **`leer()`** rechaza lo que no empiece por `SELECT` o `SHOW`.
2. Cada colegio se abre dentro de **`START TRANSACTION READ ONLY`**: rechaza el
   **servidor**, no este código.
3. **`comprobarQueNoPuedeEscribir()` intenta escribir y exige que falle.** Si el
   servidor la dejara pasar, **el colegio no se mide**.

La escritura de prueba es `UPDATE users SET id = id WHERE 1 = 0`: la rechaza la
transacción, y **si por lo que fuera se ejecutara, no toca ninguna fila y no cambia
ningún valor.** Las dos cosas a la vez, a propósito.

> Es la lección del escritor de la fase 3 aplicada aquí: **una garantía que no se
> comprueba es un comentario.** Y comprobada al revés, no supuesta — ver §5.

**Lo que estas tres capas NO cubren, y hay que decirlo:** las dos herramientas
delegadas corren en su propio proceso con su propia conexión.
`salud-de-la-bitacora.php` es sólo lectura. `salud-de-las-definitivas.php` **crea y
borra una tabla `TEMPORARY`**, o sea que **escribe** — pero sólo en una tabla de su
propia sesión, que muere al cerrar la conexión, y **nunca en los datos del
colegio**. No es lo mismo que «no escribe», y por eso está escrito.

## 4. El CSV es **largo**, no ancho

`colegio,bloque,clave,valor,limite` — una fila por dato. 78 filas para un colegio,
así que ~1.250 para dieciséis.

Un CSV **ancho** —una fila por colegio, una columna por dato— parece más cómodo y
**se rompe en cuanto un bloque gana un campo**: las dieciséis dejan de tener la
misma forma y hay que cruzarlas a mano, que es justo lo que se viene a quitar. En
largo, juntar dieciséis es `cat` y añadir un dato mañana no mueve ninguna columna.

**Y la columna `limite` no es documentación**: dice **qué no contesta ese número**,
en la misma fila, para que nadie lo cite sin su letra pequeña. Ejemplos reales de la
corrida de prueba:

```
"session_time_zone","SYSTEM","SYSTEM = la del hosting, no fijada por la aplicacion"
"con alguna fila en 1","1","una tabla vacia da 0 y no significa apagado: significa sin datos"
"Admin SIN is_superuser","0","si no es 0, esas personas NO entran a las once rutas de esAdministrativo"
```

## 5. Comprobado al revés — las cuatro cosas que tenían que fallar

Probado contra la base local tratada como un colegio, y **rompiendo cada garantía a
mano** para ver si se nota:

| Se rompe | Qué hace el guion |
|---|---|
| un colegio que **no existe** | `medido = NO` con el error dentro, se cuenta aparte, **y sale con código 2** |
| se quita el `START TRANSACTION READ ONLY` | **no mide ese colegio**: *«la transacción de sólo lectura NO está impidiendo escribir»* |
| se le cuela un `UPDATE` a un bloque | `leer()` lo rechaza por nombre: *«esto no es una lectura: UPDATE users…»* |
| nada | 78 filas de CSV, los cinco bloques |

> **La segunda es la que hacía falta comprobar.** Un `START TRANSACTION READ ONLY`
> escrito y no comprobado es exactamente el comentario que esto viene a no ser: si
> algún día alguien lo borra al refactorizar, el guion **deja de medir** en vez de
> pasar a escribir en silencio.

### Y la trampa del propio oficio, atendida

**Un colegio que no abre no es un colegio con ceros.** Su fila dice `medido = NO`
con el motivo, el resumen final los cuenta aparte, y termina con esto:

```
Un colegio NO MEDIDO no es un colegio limpio. Si este número no es 0,
la respuesta a las cuatro preguntas todavía no está completa.
```

Sin eso, **dieciséis ceros se leen como dieciséis colegios sanos** y pueden ser
dieciséis bases que no se abrieron. Es el fallo que este repositorio lleva
catalogado, aplicado al instrumento en vez de a su lectura.

## 6. Y un guardián de regalo: **¿tienen los dieciséis el mismo esquema?**

La lista de los 49 interruptores está **escrita a mano** —sale de
`interruptores-que-nadie-lee.py` del 24 ago— y por eso lleva
`comprobarQueLaListaEncaja()`: antes de contar, comprueba que **cada par
columna/tabla exista en el esquema de ESE colegio**, y emite los ausentes.

Eso hace dos cosas por el precio de una. Impide que una lista a mano se quede
vieja en silencio — y contesta una pregunta que nadie había hecho: **si en un
colegio falta una columna, sale por aquí.** El esquema congelado se da por igual en
los dieciséis y **eso nunca se ha comprobado**.

En la base de prueba: 53 pares revisados, 0 ausentes.

## 7. Lo que hay que pedir, y lo que falta

- **`salud-de-las-definitivas.php` no tiene `--csv`.** Es la única de las cinco
  cuya salida no es concatenable, y es la que desbloquea la fase 2 — o sea la que
  más falta hace en CSV. Su salida se captura **verbatim, línea a línea**, con lo
  que el CSV sale correcto pero no tabulado para ese bloque. **Añadirle `--csv` es
  una línea y no lo hago**: es una herramienta compartida y no es de este lote. Va
  propuesto a coordinación.
- **Este guion no se ha corrido.** Es servidor. Se entrega con el comando exacto
  en la cabecera y probado contra una base local tratada como colegio.

## 8. Lo que NO hace

- **No escribe nada**, y lo demuestra en vez de decirlo (§3, §5).
- **No interpreta** la salida de las dos herramientas delegadas.
- **No toca** ninguna de las dos, ni `interruptores-que-nadie-lee.py`.
- **No decide nada** de lo que mida: los cuatro números van a quien tiene que
  decidir, con su límite al lado.
