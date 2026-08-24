# MED-2 — los identificadores del cuerpo, cruzados con la auditoría

> **Lote de la noche del 24 ago 2026, sesión `8myvc-39`.** Es **leer y reportar**:
> no toca código, no añade rutas, no toca la máquina.
>
> Se pidió con un encargo concreto: *«acabas de escribir el escritor de la
> auditoría, así que eres quien mejor puede decir cuáles de esos 230
> identificadores importan para atribuir una acción a alguien — un identificador
> que llega por el cuerpo y nadie comprueba **es una fila de auditoría que dice el
> actor equivocado**, y eso no lo ve ninguna de las dos herramientas por
> separado.»*
>
> **La premisa era falsa y el cruce sí vale.** Las dos cosas están medidas abajo,
> y la segunda sale más incómoda que la primera.

---

## 1. Lo primero: la premisa, desmentida con los diez sitios delante

**Ningún escritor de bitácora saca el actor del cuerpo.** Ninguno de los diez.
Leídos uno a uno:

| Sitio | De dónde sale el actor |
|---|---|
| `NotasController:341`, `:602` · `SubunidadesController:68` · `DefinitivasPeriodosController:231`, `:349` · `YearsController:359` | `$bit_by = $user->user_id` — **del token** |
| `ExigirPersonaPropia:297` · `ExigirBoletinPropio:166` | `$usuario->user_id` — **del token** |
| `Sesion.php:473` | del token |
| `Login.php:127` | **el literal `0`** — el intento fallido, que no tiene actor |

O sea: **9 del token y 1 el cero conocido. Cero del cuerpo.** Un identificador
sin comprobar **no puede falsear el actor**, porque el actor no se pide: se
resuelve. Y en la tabla nueva menos aún — `App\Services\Auditoria` lo saca del
contexto que ya resolvió `auth.token` y **quien llama no tiene dónde ponerlo**.

Dicho de otra forma, y es la parte que conviene que quede: **la fase 3 ya cerró
la vía que este cruce temía.**

---

## 2. Lo que sí pasa, y es peor de leer

El identificador sin comprobar no ensucia el actor: ensucia **la acción**. Y la
auditoría, haciendo su trabajo bien, la registra como si fuera normal.

> Un profesor manda `alumno_id` de un alumno que no es suyo. La escritura ocurre.
> La línea de auditoría dice **«el profesor X editó la nota N del alumno Y»**, y
> **todos sus campos son ciertos**. Lo que la fila no puede decir es que X no
> tenía derecho a tocar a Y.
>
> **El rastro no distingue una acción autorizada de una que nadie comprobó.** No
> porque esté mal escrito, sino porque esa comprobación no ocurrió en ninguna
> parte y no hay nada que registrar.

Es la misma forma que el §4.5.1 del [18](../18-auditoria.md) ya nombró para otra
cosa —*«una nota fuera de la escala es un dato que la auditoría va a registrar
como si fuera normal»*— y aquí aplica al sujeto entero de la acción.

### 2.1 Y la vuelta de tuerca: el rastro está *debajo* del hueco, no encima

Si el sujeto de la línea de auditoría sale **del mismo identificador que nadie
comprobó**, entonces el rastro **no sirve para encontrar el abuso**: repite la
afirmación del que llamó.

Medido, sitio por sitio, y sale mejor de lo que temía:

| Sitio | De dónde sale el **sujeto** de la fila | |
|---|---|---|
| `NotasController:341`, `:602` | `$nota->alumno_id` | **de la fila leída** |
| `DefinitivasPeriodosController:231`, `:349` | `$nota->alumno_id` | **de la fila leída** |
| `SubunidadesController:68` | `$subunidad->id` | **de la fila leída** |
| `ExigirPersonaPropia`, `ExigirBoletinPropio` | el id **pedido** | correcto: registran lo que se pidió, no afirman que valga |
| **`YearsController:359`** | **`Request::input('id')`** | **del cuerpo, sin comprobar** |

**Nueve de diez derivan el sujeto de la fila**, que es exactamente la lección de
la [05 §50](../05-codigo-muerto-y-roto.md) —*«`data_id` derivado del cuerpo y no
de la fila»*, el mismo error encontrado cinco veces en tres pasadas—. Quien
escribió esas nueve lo hizo bien, y probablemente sin saber que estaba
contestando esa pregunta.

**El que queda es `YearsController:359`**, que escribe `affected_element_id =
Request::input('id')` tal cual. Y `id` es **la peor familia de las 28: 35 rutas,
30 sin comprobar propiedad.**

---

## 3. La regla que esto deja para la fase 4, que es lo accionable

`Auditoria::deAlumno($id)` y `->editar('nota', $id)` **se creen lo que les
pasan**. El servicio no puede saber si ese id vino del cuerpo sin comprobar o
salió de la fila que se acaba de escribir — y **no debe poder**: adivinarlo sería
otro sitio decidiendo por su cuenta, que es de lo que viene todo esto.

Así que es una regla, con su sitio donde comprobarla:

> **Al instrumentar un dominio, el id que se le pasa a `Auditoria` es el que salió
> de la fila escrita, nunca el que entró por el cuerpo.** `$nota->alumno_id`, no
> `Request::input('alumno_id')`. Las nueve escrituras de bitácora que ya existen
> lo hacen así; es el patrón a copiar, no a inventar.

Y su corolario, que es el que evita una falsa tranquilidad:

> **Instrumentar un método NO cierra su hueco de autorización.** Al contrario: le
> pone un registro fiel encima. Si el método no comprueba de quién es el id, la
> fase 4 lo deja **igual de abierto y mejor documentado**. Las dos cosas hay que
> hacerlas, y la del guard no es de la auditoría.

---

## 4. Los números, con su población

`tools/identificadores-del-cuerpo.py` sobre las 542 rutas:

| | |
|---|---|
| Rutas que leen al menos un identificador del cuerpo | **230** |
| De ellas, **escriben** (UPDATE/DELETE/INSERT) | **177** |
| Familias distintas de identificador | **28** |
| **Rutas que escriben con 2+ identificadores sin comprobar** | **15** |

Las quince son la forma exacta de la [05 §49](../05-codigo-muerto-y-roto.md): **el
método comprueba uno y escribe con los otros.** Es donde hay que mirar primero, y
las de arriba de la lista tienen los cuatro a la vez:

```
PUT api/detalles/grupos-periodos          *alumno_id *grupo_id *matricula_id *year_id
PUT api/asistencias/poner-ausencia        *alumno_id *asignatura_id *periodo_id   (escribe)
PUT api/asistencias-app/poner-ausencia    *alumno_id *asignatura_id *periodo_id   (escribe)
POST api/disciplina/store                 *alumno_id *periodo_id *year_id         (escribe)
PUT api/tardanzas/subir/poner-ausencia    *alumno_id *asignatura_id *periodo_id   (escribe, y SIN GUARD)
PUT api/disciplina/destroy                *alumno_id *proceso_id                  (escribe)
```

> **`PUT api/tardanzas/subir/poner-ausencia` sale con el guard vacío** —columna
> `—`, ni `auth.token`—. Eso **no** se reporta aquí como fuga: las quince rutas
> públicas están fijadas por `RutasPreLoginTest` y esto puede ser una de ellas o
> un fallo de la tabla de rutas. **Es un sitio donde mirar**, y quien lo mire
> empieza por ahí y no por el resto.

### 4.1 Y el detector no dice cuántas familias hay

Lo que se pidió eran «29 familias». **Medido ahora mismo: 28.** No sé decir si el
29 era de otra pasada o un recuento de más, y **eso es justamente el problema**:
`identificadores-del-cuerpo.py` imprime el total de rutas (230) pero **no el de
familias**, así que ese número no lo puede cruzar nadie contra la herramienta —
sólo contando a mano la lista, que es cómo se publicó un 9 en vez de un 10 en la
§0 del 18.

Es la regla de CLAUDE.md por el lado que no se ve: **la herramienta imprime su
población, pero no la de todos los números que alguien va a citar de ella.** No lo
arreglo: la comparten catorce sesiones esta noche y MED-2 es leer y reportar. Es
una línea, y queda propuesto.

---

## 5. Lo que este lote NO hace

- **No toca ningún guard ni ninguna ruta.** Cuáles de las quince son fuga y cuáles
  ruido se decide **leyendo el método**, y la propia herramienta lo dice en su
  cabecera: *«su salida es una lista de sitios donde mirar, nunca una lista de
  fallos»*. Cuatro pasadas anteriores midieron este patrón y ninguna medida era la
  buena ([05 §52](../05-codigo-muerto-y-roto.md)).
- **No toca los diez escritores viejos.** AUD-4 va junta y necesita que Joseth la
  abra.
- **No toca `identificadores-del-cuerpo.py`** ni ninguna otra herramienta
  compartida.
