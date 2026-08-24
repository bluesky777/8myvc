# MED-3 — las quince leídas, y lo que la lectura les quita

> **Sesión `8myvc-39`, noche del 24 ago 2026.** Continúa
> [med-2.md](med-2.md). Tres encargos: poner la línea de población que le faltaba
> a `identificadores-del-cuerpo.py`, **leer** las quince rutas que escriben con dos
> o más identificadores sin comprobar y clasificarlas, y arreglar
> `YearsController:359`.
>
> **El titular: de las quince, ninguna es una fuga confirmada, y seis no admiten
> la pregunta.** No porque estén bien: porque la pregunta que queda abierta en las
> nueve restantes **es una que ya espera decisión de Joseth**, y la que sale sin
> guard **se autentica dentro del método**. Eso es lo que la lectura añade y el
> detector no puede.

---

## 1. La línea que faltaba (commit `fe07c50`)

`identificadores-del-cuerpo.py` imprimía **un** número —«230 rutas»— y los otros
tres se sacaban contando sus listas a mano. Ya salió mal dos veces aquí: la §0 del
[18](../18-auditoria.md) publicó **9** escritores de bitácora cuando eran **10**, y
a esta herramienta se le citaron **29** familias.

Ahora imprime cuatro, **todos de contar y ninguno de una constante**:

```
230 rutas leen al menos un identificador del cuerpo.
  de ellas, escriben (UPDATE/DELETE/INSERT) .......... 177
  y escriben con DOS O MÁS sin comprobar ............. 15   <- la forma de la §49
familias de identificador distintas ................... 72
  de ellas, con al menos una ruta sin comprobar ...... 28   <- las que se listan abajo
```

**Y ahí estaba el 29 explicado**: la lista de familias **salta** las que no tienen
ninguna ruta sin comprobar, así que su recuento (**28**) no es el número de
familias (**72**). Nadie podía notarlo porque ninguno de los dos se imprimía.

> **Y una tercera equivocación, mía, en el camino.** Medí las familias por fuera,
> cortando la columna de la tabla en un offset fijo, y salían **86**. El offset se
> rompe con las rutas largas y se traga texto del guard como si fuera un
> identificador. La cifra buena es la de `f['claves']`, que es la lista parseada;
> la mía era una regex con otro nombre. Queda escrita en la cabecera de la
> herramienta.

---

## 2. Las quince, leídas

### 2.1 Lo primero: seis de las quince no admiten la pregunta

La herramienta lo dice en su propia cabecera —*«no sabe distinguir un `year_id`,
que nombra una configuración del colegio y no a nadie, de un `alumno_id`»*— y al
leerlas se ve cuánto pesa eso:

| Ruta | Sus dos identificadores | Por qué no admite la pregunta |
|---|---|---|
| `PUT actividades/quitando-grupo-compartido` | `actividad_id`, `grupo_id` | ni una actividad ni un grupo son de una persona |
| `PUT bolfinales-preescolar/guardar-frase` | `asignatura_id`, `id` | una frase del boletín es material de asignatura |
| `POST disciplina/asignar-ordinal` | `id`, `proceso_id` | el `id` es el del ordinal, un catálogo del colegio |
| `PUT disciplina/quitar-ordinal` | `id`, `proceso_id` | idem |
| `PUT disciplina/cambiar-situacion-derivante` | `become_id`, `id` | los dos nombran situaciones del catálogo |
| `PUT opciones/set-opcion-correct` | `id`, `pregunta_id` | una opción y una pregunta de examen |

**«Comprobar propiedad» no significa nada sobre una fila que no es de nadie.** En
las seis, lo que quedaría por decidir es *qué miembro del personal puede editar el
catálogo del colegio*, que es la misma pregunta del §2.3 y no una distinta.

**Quedan nueve** en las que al menos un identificador nombra a una persona o a una
fila suya.

### 2.2 La que sale sin guard: **pública a propósito, y autenticada dentro**

`PUT api/tardanzas/subir/poner-ausencia` aparece con la columna de guard **vacía**.
Lo decide el fichero de rutas, no el detector:

```php
Route::put('tardanzas/subir/poner-ausencia', [...])->withoutMiddleware('auth.token');
```

Son **tres** en `routes/api/tardanzas.php`, las tres con `withoutMiddleware`. Y no
están entre las que enumera `RutasPreLoginTest`. Eso **parece** el hallazgo y no lo
es: está documentado en `LaHoraDelLectorDeTardanzasTest`, que lo dice con estas
palabras —

> *«El lector manda usuario y contraseña **en cada petición**, dentro de
> `loginData`: estas tres rutas no llevan `auth.token` y se autentican dentro del
> método. Tiene que ser `Profesor` o superusuario.»*

Es **el lector de tardanzas de la puerta**, un aparato: no puede iniciar sesión y
conservar un token, así que se autentica en cada llamada. **No es una ruta sin
autenticar: es una ruta que autentica en otro sitio**, y ahí es donde el detector
no puede llegar.

> **Lo que sí queda dicho, porque no es lo mismo:** `RutasPreLoginTest` **no afirma
> el complemento.** Enumera **once** entradas —contadas, no de memoria— y comprueba
> que ésas funcionan sin token; **no comprueba que ninguna otra sea pública**. Así
> que las tres del lector no faltan ahí por un fallo del test: **ese test no es un
> inventario.** Quien lea «las excepciones públicas son quince y son un test» está
> leyendo una promesa que ese fichero no hace.

**El inventario sí existe, y es otra herramienta.** Corrida ahora,
`tools/auditar-autenticacion.php` sobre las rutas reales del router:

```
Rutas analizadas: 541
  Resuelven al usuario:        524
  NO lo resuelven y ESCRIBEN:    8   <- lo urgente
  NO lo resuelven, solo leen:    9
```

**Diecisiete**, no quince. Y las tres del lector son tres de las ocho que
escriben; las otras cinco son `login/*` —crear prematrícula, logout, recuperar
clave, reset, ver-pass—, o sea la entrada al sistema.

> **Y aquí hay tres números que no coinciden, y ahora se puede decir qué es cada
> uno**, que es lo que faltaba:
>
> | | |
> |---|---|
> | **17** | rutas que no resuelven al usuario — medido hoy por `auditar-autenticacion.php` |
> | **11** | rutas que `RutasPreLoginTest` afirma que funcionan sin token |
> | **15** | lo que dicen CLAUDE.md **y la propia cabecera de la herramienta** («la respuesta corta es *todas menos quince*») |
>
> El **15 está viejo en los dos sitios**, y es de los que no fallan: nadie lo
> vuelve a contar porque suena a dato cerrado. Los otros dos **no se contradicen**
> —miden cosas distintas— pero sólo se puede saber leyendo los dos ficheros, que es
> exactamente lo que MED-3 vino a hacer. **No lo corrijo aquí**: son CLAUDE.md y una
> herramienta compartida, y ninguno de los dos es de este lote. Queda medido y
> propuesto.
>
> Y una diferencia menor que también queda dicha para que nadie la persiga: la
> herramienta analiza **541** rutas y CLAUDE.md dice **542**. Una de diferencia, sin
> mirar todavía cuál.

### 2.3 Las nueve que quedan: una sola pregunta, y ya está esperando

| Ruta | Guard | El identificador que nombra a alguien |
|---|---|---|
| `PUT asistencias/poner-ausencia` | `auth.personal` | `alumno_id` |
| `PUT asistencias-app/poner-ausencia` | `auth.personal` | `alumno_id` |
| `POST asistencias` | `auth.personal` | `alumno_id` |
| `POST asistencias-app` | `auth.personal` | `alumno_id` |
| `PUT tardanzas/subir/poner-ausencia` | dentro del método | `alumno_id` |
| `POST disciplina/store` | `auth.personal` | `alumno_id` |
| `PUT disciplina/update` | `auth.personal` | `alumno_id` |
| `PUT disciplina/destroy` | `auth.personal` | `alumno_id`, `proceso_id` |
| `POST piars-actas-acuerdo/document` | `auth.personal` | `alumno_id` |

Leído `AsistenciasController::putPonerAusencia`, que es la forma de todas:

```php
INSERT INTO ausencias (... alumno_id, asignatura_id, periodo_id, created_by ...)
   ':alumno_id'     => Request::input('alumno_id'),
   ':asignatura_id' => Request::input('asignatura_id'),
   ':periodo_id'    => Request::input('periodo_id'),
   ':created_by'    => $user->user_id,
```

**El actor sale del token; el sujeto sale del cuerpo y nadie lo acota.** O sea:
cualquiera del personal puede anotarle una falta a cualquier alumno, en cualquier
asignatura y en cualquier periodo.

**Y aquí está la clasificación, que es lo que se pedía:**

- **Fuga confirmada: ninguna de las nueve.** La regla confirmada y no
  re-litigable de este proyecto es *«un alumno sólo ve lo suyo; un acudiente, lo
  suyo y lo completo de sus acudidos»*, y **`auth.personal` aborta con 403 a
  `Alumno` y a `Acudiente`**. Ninguna de las nueve es alcanzable por una familia.
  La del lector exige `Profesor` o superusuario dentro del método.
- **Pública a propósito: una** —la del lector—, y con su test que lo dice.
- **No se sabe: las nueve, y las seis del §2.1 con ellas.** Lo que queda abierto es
  **si un miembro del personal puede escribir sobre un alumno que no es suyo**, y
  eso **no es una pregunta nueva**: es literalmente *«quién del personal puede
  qué»*, que el briefing de esta noche lista como **esperando respuesta de
  Joseth** en [09-pendientes.md](../09-pendientes.md).

> **Lo que MED-3 aporta, entonces, no es una lista de fallos: es que las quince se
> reducen a una decisión que ya está pedida.** Ninguna necesita un arreglo esta
> noche, y ninguna se queda sin dueño. Es lo contrario de lo que el «15» sugiere
> leído solo, y es exactamente el motivo por el que la herramienta dice en su
> cabecera que su salida son **sitios donde mirar y nunca una lista de fallos** —
> cuatro pasadas anteriores midieron este patrón y ninguna medida era la buena
> ([05 §52](../05-codigo-muerto-y-roto.md)).

### 2.4 Y una consecuencia para la fase 4 que sale de aquí

Las nueve son **exactamente** los dominios 4, 5 y 6 de la fase 4 de la auditoría
—asistencia, comportamiento, disciplina—. O sea que **la fase 4 va a instrumentar
estas nueve rutas**, y por la regla de [med-2](med-2.md#3):

> Instrumentarlas **no cierra su hueco**: le pone un registro fiel encima. Y como
> el sujeto que registrarían saldría del **mismo** `alumno_id` sin comprobar, el
> rastro **repite la afirmación del que llamó** y no sirve para encontrar el abuso.
>
> Por eso, cuando la fase 4 llegue a asistencia y disciplina, el `alumno_id` de la
> línea de auditoría tiene que salir **de la fila insertada** —el `id` que devuelve
> el `INSERT`, releído— y no de `Request::input('alumno_id')`. Hoy esas nueve no
> graban nada, así que no hay nada que corregir; hay algo que **no** repetir.

---

## 3. `YearsController` — el décimo escritor, arreglado

Era **el único de los diez** que derivaba el sujeto de la bitácora del **cuerpo**:

```php
- DB::insert($consulta, [ $bit_by, $bit_hist, 'YEAR CONFIGURACION', Request::input('id'), ...
+ DB::insert($consulta, [ $bit_by, $bit_hist, 'YEAR CONFIGURACION', $year->id, ...
```

La fila está garantizada desde la línea 298 —`Year::findOrFail(Request::input('id'))`,
**fuera del `try`**, así que un id que no existe es 404 antes de llegar—, o sea que
`$year->id` es el id de la fila que se acaba de guardar.

### 3.1 Lo que el test prueba y lo que no — comprobado, no supuesto

`YearsTest::test_la_bitacora_del_ano_apunta_a_la_fila_guardada`. Y **hay que decir
lo que no prueba, porque se comprobó al revés**: revertido el arreglo, **el test
sigue pasando**.

No por estar mal escrito. Porque `config/database.php` lleva **`strict => false`**,
así que un `id` no numérico se convierte **en silencio** al entrar en la columna
`int` y las dos formas guardan el mismo número. **No hay ningún cuerpo alcanzable
hoy que las distinga**, y se buscó: con espacios, con ceros delante, con decimales
y con texto detrás.

**Lo que el arreglo quita es un fallo latente**, y merece escribirse porque es de
los que sólo aparecen el día que alguien endurece algo:

> Con el modo estricto puesto —un endurecimiento razonable y no descartado—, la
> versión vieja **lanzaría después de `$year->save()`**. Y como el `catch` de ese
> método contesta `abort(422, 'Datos incorrectos')`, el resultado sería: **el año
> queda cambiado, el cliente lee que ha fallado, y del rastro no queda nada.** Las
> tres cosas mal a la vez, y ninguna visible hoy.

Así que el test es **la red que impide que alguien lo devuelva al cuerpo**, no la
prueba de que hiciera falta cambiarlo. Está dicho así dentro del propio test.

Con esto, **los diez escritores de bitácora derivan el sujeto de la fila leída.**

---

## 4. Lo que este lote NO hace

- **No toca ninguno de los nueve guards.** La decisión que les falta ya está pedida
  y no es de esta noche.
- **No toca las tres rutas del lector de tardanzas.** Están documentadas y
  autenticadas dentro; cambiarlas rompe un aparato en dieciséis colegios.
- **No añade el inventario que `RutasPreLoginTest` no es.** Queda dicho que no lo
  es, que es lo que hacía falta; construirlo es otra cosa y tiene herramienta
  propia.
- **No toca AUD-4, `can_view_auditoria` ni `bitacoras/destroy`.** Esperan a Joseth,
  y la última va además detrás del front.
