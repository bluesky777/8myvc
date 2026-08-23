# Lote K — Las columnas que se pisan donde no llega ningún lote

> Sesión `8myvc-2f`, árbol `.worktrees/k`, rama `fix/lote-k-columnas-huerfanas`,
> base `simonbolivar_testing_k`. Noche del 22 al 23 de agosto de 2026.
> Secciones asignadas del [05](../05-codigo-muerto-y-roto.md): **§118–121**.
> Esta sesión cerró antes los lotes [B](b.md) y [F](f.md).

El lote venía con una lista de **28 métodos y 224 columnas** que resuelven una
fila existente y le asignan columnas con `Request::input('x')` sin defecto. Seis
controladores sin dueño. Lo primero que hay que decir es lo que **no** resultó
ser trabajo:

> **La fila más grande de la lista era un falso positivo.**
> `AlumnosController::postStore` salía con **25 columnas**, la mayor de todas, y
> es un alta: `new Alumno` con un `Grupo::find()` sesenta líneas más abajo, que es
> lo que engaña al detector. **En un alta no hay nada que pisar.** Aplicar ese
> filtro antes que nada —como decía el parte— quitó la cuarta parte del lote en
> cinco minutos.

Y lo que sí resultó ser trabajo no era «pisar columnas». Era esto:

| § | Qué | Se arregla |
|---|---|---|
| §118 | `alumnos/update` **contesta 200 con los cambios dentro y no guarda nada** | **no** — el arreglo es peor |
| §119 | Cuando sí guarda, pisa 23 columnas de la ficha; y **la ida y la vuelta no encajan** | **no** — va con la §118 |
| §120 | La pregunta de un examen se queda **valiendo cero**; la opción, **sin texto** | **no** |
| §121 | El pedido de cambio se cerraba con **la hora escrita dos veces**, y escribía en `debugging` | **sí** |

Seis commits. **Un solo fichero de `app/` tocado**: `ChangeAskedController`.

---

## §118 — `alumnos/update` contesta que guardó y no guarda

Es el método que más columnas pisa del repo —23— y sobre la tabla más sensible.
Tres cuerpos distintos dan tres cosas distintas, y ninguna se ve leyéndolo:

| Cuerpo | Qué contesta | Qué escribe |
|---|---|---|
| **sin `username`** | 200 **con los cambios dentro** | **nada** |
| con `username` | 200 | la ficha, pisando lo que no venga |
| lo que devuelve `alumnos/show` | **500** | nada |

El `$alumno->save()` vive **dentro de los dos `if (… Request::has('username'))`**.
Sin esa clave no se ejecuta ninguno de los dos, así que el método devuelve el
modelo modificado **en memoria** —que es lo que Laravel serializa— y la fila no se
toca.

**La respuesta no es que no avise: afirma lo contrario.** El JSON trae los valores
nuevos. Quien guarda ve «Alumno actualizado correctamente», recarga, y su cambio
no está.

> `respuestas-que-mienten.py` da **un solo sitio** y este no es ese, y **no es un
> fallo de la herramienta**: busca métodos que *frenen* la escritura y contesten
> 200 igual, y aquí no hay nada que frene — **el `save()` sencillamente no está en
> ese camino**. Es la tercera ceguera de detector de esta sesión, y las tres son
> la misma forma: *la señal que se busca no es la forma que tiene el fallo*.

**Quién llega por ahí**, que es el discriminador que pedía el parte: la pantalla
de AngularJS **no**. `AlumnosEditCtrl` manda `$ctrl.alumno` entero y `alumnos/show`
sí devuelve `username`, así que el front pasa por el camino que guarda. Por ahí
entra cualquier cliente que arme un cuerpo parcial.

---

## §119 — Cuando sí guarda, pisa; y la ida y la vuelta no encajan

Con `username`, el mismo cuerpo escribe la fila entera y **lo que no viene se va a
null**: `eps`, `no_matricula`, `barrio`, `estrato`, `celular`, `religion`,
`facebook` y `deuda`. `eps` es la que un colegio necesita el día que hay que
llamar a una ambulancia.

**La [§68](../05-codigo-muerto-y-roto.md) cerró este mismo método por el lado de
`users`** —`is_active`, `email2`, `password`, con `CamposQueVinieron` capturado dos
líneas antes— **y dejó las 23 columnas de `alumnos` sin tocar**. La herramienta
está ahí, en el método, ya llamada. Es la **cuarta vez en esta sesión** que una
serie se cierra sobre media población.

### «Tiene defecto» tampoco aquí es «está a salvo»

Los dos únicos con segundo argumento no conservan el valor de la fila: **`sexo` se
pisa con `'M'`** y **`pazysalvo` con `true`**. O sea que guardar la ficha de una
alumna sin mandar el sexo **la convierte en hombre**, y guardar la de un alumno
con deuda **lo pone a paz y salvo**. Los dos salían «a salvo» en el detector.

### La ida y la vuelta no encajan

Lo que devuelve `alumnos/show` **no se le puede mandar a `alumnos/update`**: da
**500**. `show` entrega `tipo_doc`, `ciudad_nac`, `ciudad_doc` y `tipo_sangre`
planos, y `putUpdate` indexa `Request::input('tipo_sangre')['sangre']` sobre ellos.

O sea que **la pantalla tiene que reconstruir a mano cuatro campos que acaba de
recibir**, y ningún test lo veía porque todos los cuerpos de prueba estaban
escritos ya reconstruidos. Es la [§69](../05-codigo-muerto-y-roto.md) mirada desde
el otro extremo del viaje.

### Por qué no se arregla

> Añadir el `save()` que falta **enciende de golpe el pisado de las 23 columnas**
> en un camino donde hoy no se escribe nada. Se cambiaría una respuesta que miente
> por **pérdida de datos silenciosa en la ficha del alumno**. El `save()` y
> conservar los ausentes con `CamposQueVinieron` **van juntos o ninguno**, y eso es
> una decisión, no un arreglo de noche.

Es la misma forma que `mis-actividades/guardar` en el [lote F](f.md#§104): **el
arreglo evidente es peor que el fallo**. Van las dos a la lista de Joseth.

---

## §120 — El examen: cero puntos y una opción sin texto

Cuatro métodos de la misma forma, en sitios que no son de ningún lote. **El tamaño
de la fila no dice nada sobre su peso**: la de dos columnas es la que deja una
opción de examen en blanco.

- **`preguntas/guardar`** (7 columnas) — sin `puntos`, la pregunta se queda
  **valiendo cero**. La columna es `NOT NULL DEFAULT 0` y el null se convierte en
  0 en silencio, porque `config/database.php` lleva `'strict' => false`. **El
  `NOT NULL` no frena un UPDATE**: solo frena un alta. Y sin `duracion`, sin
  tiempo. Corregir la redacción de una pregunta ya puntuada, desde un cliente que
  mande solo el enunciado, la deja sin valor en el examen.
- **`opciones/guardar`** (2) — reordenar las opciones borra **el texto que el
  alumno lee para elegirla**. `is_correct` no está en la lista, así que la opción
  **sigue siendo la correcta y ya no dice nada**.
- **`aspiraciones/update`** (2) — cambiar la abreviatura borra el nombre de la
  candidatura, y las dos columnas son `NOT NULL`. Su hermana `store` lo enseña por
  el otro lado: escribe **solo `votacion_id`**, así que crea la candidatura con el
  nombre ya en blanco.
- **`paises/store`** — pide **`pais_new`**, no `pais`; mandar el nombre de la
  columna da 500. Es la misma asimetría de nombres que
  [`certificados`](f.md#§103), y aquí el esquema sí frena. No escribe `abrev`
  —que es lo que devuelve `ciudades/paisdeciudad` ([§85](b.md))— ni las fechas.
  Y **`update()` y `destroy()` existen sin ruta**: comprobado contra
  `route:list`, **un país creado por la API no se puede arreglar ni borrar**.

Ninguno se arregla: los cuatro tienen detrás la misma pregunta —*¿quién los llama,
y manda la fila entera?*— y la respuesta no está en el backend.

---

## §121 — La hora escrita dos veces, y cinco filas de depuración

```php
$dt = Carbon::now('America/Bogota')->format('Y-m-d G:H:i');
```

**`G` y `H` son las dos la hora del día** —una sin cero delante, la otra con él—,
así que el formato es `hora:hora:minutos` y **los segundos no llegan nunca**: las
21:07:33 se guardan como **21:21:07**.

Lo que lo hace comprobable sin discutirlo es que **la ruta escribe esa misma
columna dos veces, y la otra vez lo hace bien**: la rama de `asignatura`, ochenta
líneas más arriba, liga el `Carbon` directamente. Dos escrituras a
`change_asked.deleted_at` en el mismo método, una correcta y otra no. **Ese
contraste es el que dice cuál de las dos es el arreglo**, y no una preferencia de
estilo.

**La población es de tres, no de una**, medida con un grep del formato en todo
`app/`:

| Dónde | Qué hace |
|---|---|
| `ChangeAskedController:947` | lo escribe — **arreglado** |
| `Tardanzas/TSubirController:103` | lo escribe igual — **anotado** |
| `AusenciasController:177` | **lo lee** con ese mismo formato — **anotado** |

> **Las filas ya escritas llevan la hora mal**, y eso no lo arregla el commit.
> Quien lea la auditoría de un pedido cerrado antes del despliegue está leyendo
> `hora:hora:minutos`. Es un dato, no un formato.

### Y los cinco `Debugging::pin`

No eran comentarios. `Debugging::pin()` hace `new Debugging` y `save()`, o sea
**una fila de verdad en la tabla `debugging`**. Aceptar o rechazar un pedido de
cambio escribía hasta cinco, una de ellas con el texto `'ENTROOOOO'` dentro, en
los dieciséis colegios. Se van.

El volumen real **no se puede medir desde aquí**: `debugging` está vacía en el
seed —que sale de producción—, así que lo que se fija es que esta ruta ya no la
toca.

---

## Lo que aprendió este lote

1. **Aplicar el filtro de falsos positivos primero quita más trabajo que
   cualquier otra cosa.** La fila más grande de la lista era un alta.
2. **Una lista de columnas ordena mal el trabajo.** El detector puso `postStore`
   (25) arriba y `opciones/guardar` (2) abajo, y la de dos es la que deja una
   pregunta de examen sin respuesta legible.
3. **El `NOT NULL` no protege lo que parece.** Frena el alta y no frena el UPDATE
   —con `'strict' => false`, que es lo que hay—, así que una columna obligatoria
   se queda en blanco sin que nada falle. Medido en `vt_aspiraciones` y en
   `ws_preguntas.puntos`.
4. **Dos rutas de esta sesión donde el arreglo evidente es peor que el fallo**
   (§118 y la §104 del lote F). Las dos comparten forma: un 500 o un no-guardado
   ruidoso que, «arreglado», se convierte en una escritura silenciosa que borra.

---

## PARA JOSETH

1. **`alumnos/update`: ¿se arregla entero o no se toca?** Hoy sin `username` no
   guarda y miente; con `username` guarda y pisa media ficha. Las dos mitades se
   arreglan juntas —el `save()` que falta más `CamposQueVinieron` sobre las 23
   columnas— o ninguna, porque arreglar solo una convierte la mentira en pérdida
   de datos. (§118–119)
2. **¿`show` y `update` deben encajar?** Hoy la pantalla reconstruye a mano cuatro
   campos que acaba de recibir, y devolver la ficha tal cual da 500. (§119)
3. **La hora mal escrita está en filas ya guardadas.** ¿Se corrigen las de
   `change_asked.deleted_at` con una migración, o se deja escrito que las
   anteriores a este despliegue llevan `hora:hora:minutos`? (§121)
4. **¿`preguntas/guardar` debe conservar los puntos?** Hoy un guardado parcial
   deja la pregunta valiendo cero en un examen ya calificado. (§120)

## PARA OTRO LOTE

- **`GruposController::putUpdate`** (lote E) — medido aquí antes de saber que el
  controlador había cambiado de lote, y **es lo más caro que vi esta noche**:
  `$grupo->year_id = $user->year_id`, sin leer el cuerpo. Editar un grupo de otro
  año **lo mueve al año de quien lo edita**, con sus matrículas dentro. Medido:
  el grupo 84, con **56 matrículas**, pasó de `year_id = 7` a `8` en una llamada.
  Y en la misma llamada `titular_id`, `cupo`, `abrev`, `valormatricula`,
  `valorpension` y `orden` se fueron a null. Sin `grado_id` sí frena —422, por el
  `NOT NULL` con clave ajena—, y sin `id` es 404.
- **`Tardanzas/TSubirController:103`** y **`AusenciasController:177`** — el mismo
  `'Y-m-d G:H:i'`, uno escribiendo y otro **leyendo**. (§121)
- **Huecos del seed**, para quien lo regenere: `ws_actividades`, `change_asked`,
  `change_asked_data` y `debugging` están **vacías**.

## Lo que se nota en un colegio

- **Al cerrar un pedido de cambio, la hora que queda apuntada es la hora.** Las
  anteriores al despliegue siguen mal.
- **La tabla `debugging` deja de crecer** cada vez que alguien acepta o rechaza un
  pedido.
- Nada más cambia. Las otras once rutas del lote quedan exactamente como estaban,
  con diecinueve tests nuevos que caen si alguna se mueve.

## Migraciones

**Ninguna.** El esquema no se toca.
