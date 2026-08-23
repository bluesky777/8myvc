# Lote A — Catálogos: editar y borrar (§81–84)

> Sesión `8myvc-06`, árbol `.worktrees/a`, rama `fix/lote-a-catalogos`, base
> `simonbolivar_testing_a`. Noche del 22 al 23 de agosto de 2026.
>
> Diez controladores: Areas, Frases, FrasesAsignatura, DefinicionesComportamiento,
> EscalasDeValoracion, TipoDocumento, Materias, Contratos, NivelesEducativos,
> Grados.

La pregunta del lote era **la mitad que la §78 no miró**: aquélla midió *crear*
un catálogo del colegio y salió con una conclusión que se leyó como
tranquilizadora. Esta es *editar* y *borrar* de las mismas parejas, y lo primero
que hay que decir es que **la conclusión de la §78 no se puede arrastrar hasta
aquí**.

---

## §81 — Editar un catálogo con el cuerpo vacío se lo lleva por delante

**Las seis rutas de editar del lote vaciaban la fila y contestaban 200.**

| Ruta | Con el cuerpo vacío quedaba | Contestaba |
|---|---|---|
| `PUT areas/update/{id}` | `nombre=''`, `alias`/`orden` a null | 200, cuerpo vacío |
| `PUT frases/update/{id}` | `tipo_frase=''`, `frase` a null | 200 con la fila ya vaciada |
| `PUT niveles_educativos/update/{id}` | `nombre=''` | 200 con la fila |
| `PUT tiposdocumento/{id}` | `tipo=''` **y** `abrev=''` | 200, cuerpo vacío |
| `PUT grados/update/{id}` | `nombre=''` | 200 `Cambiado` |
| `PUT materias/update/{id}` | `materia=''` | 200 con la fila |

### Por qué el mismo esquema salva a crear y no a editar

No es que estos controladores sean peores: son los mismos, línea por línea,
leyendo `Request::input(...)` sin una sola validación. Lo que cambia es qué hace
MySQL. Con `strict => false` —`config/database.php`, las dos conexiones— y sobre
la misma columna `areas.nombre`, medido directamente contra la base:

```
UPDATE areas SET nombre=NULL WHERE id=1    ->  Warning 1048, la fila queda con ''
INSERT INTO areas (nombre) VALUES (NULL)   ->  ERROR   1048, rechazado
```

**Mismo código 1048, distinta severidad.** El `NOT NULL` al que la §78 le
atribuyó el mérito de frenar los INSERT no frena ni uno solo de los UPDATE: los
convierte en `''` sin decir nada. Y como no lanza excepción, el `try/catch` que
tres de estos controladores tienen alrededor —el que sí traduce el fallo de crear
a 422— aquí **no tiene nada que traducir**.

> La §78 dice «lo que impide que ocho de los nueve escriban basura no es el
> código: es el esquema». Es cierto **para crear** y falso para editar, y tal como
> está escrita se lee como si valiera para las dos mitades. Es de las peores
> formas de estar mal: una conclusión cierta en su población, arrastrada a otra.

### Lo que costó verlo: dos de los seis parecían sanos

La primera medida dijo **«cuatro de seis»**, y era mentira. `grados` contestaba
422 y `materias` 500, así que los dos parecían defendidos. Los dos códigos salían
del mismo sitio y ninguno del campo que importa:

```php
Request::input('nivel')['id']    // sobre null
Request::input('area')['id']     // sobre null
```

Laravel convierte el aviso de PHP en `ErrorException`; en `grados` el `try/catch`
la traduce a 422 y en `materias` sale como 500. Con el cuerpo mínimo que pasa por
delante de ese offset —`{"nivel":{"id":1}}`, `{"area_id":3}`— los dos dan 200 y
vacían igual que los otros cuatro.

> **Una respuesta correcta por el motivo equivocado tapa justo lo que parece
> estar cubriendo.** Un 422 se lee como «se validó».

### El arreglo, y por qué ése

El de la [§68](../05-codigo-muerto-y-roto.md), que ya está en el repo con su
clase: **un campo que no se manda no es un campo que no cambia, es un campo que
se pisa.** Se asigna sólo lo que vino (`App\Support\CamposQueVinieron`).

Se eligió frente a la otra opción —exigir la columna obligatoria y devolver 422,
que es lo que ya hace crear— porque **no le cambia el código de estado a ningún
cliente**: los cuatro fronts mandan la fila entera desde su formulario, así que
para ellos el comportamiento es idéntico. Un 422 nuevo habría que ir a mirarlo
pantalla por pantalla en dieciséis colegios.

En `grados` y en `materias` la captura va **antes del `Request::merge`**, y no es
colocación libre: el `merge` mete la clave en la petición, así que después de esa
línea `trae('nivel')` diría que sí aunque el cliente no lo mandara nunca. Lo
avisa el docblock de `CamposQueVinieron` y es el mismo tropiezo que la §68.

**Comprobado al revés**: revertidos los seis controladores, el test cuenta
**6 de 6**. Se acumula y se afirma una vez al final — con el `assertSame` dentro
del bucle sólo demostraba que había regresado `areas`.

`EditarUnCatalogoTest` · commit `8ef89ff`

### Lo que este lote NO unificó, a propósito

Las seis siguen contestando de tres formas: 200 con el cuerpo vacío (areas,
tiposdocumento), 200 con la fila (frases, niveles, materias) y 200 con la cadena
`Cambiado` (grados). Fijarlas es lo que hace que unificarlas sea **una decisión y
no un efecto**.

---

## §82 — Borrar lo que no existe: ocho decían 404 y dos decían que sí

| Ruta | Contestaba |
|---|---|
| `DELETE definiciones_comportamiento/destroy/{id}` | **200** con el texto plano `No se encontró` |
| `DELETE contratos/destroy/{id}` | **200** con el cuerpo `0` |

Las ocho restantes ya daban 404. Las dos son de la familia que persigue
`tools/respuestas-que-mienten.py` (§37, §45): **el 200 es lo que el front usa
para decidir que la fila se fue**, así que la rejilla la quita de la pantalla y
la fila sigue ahí. En contratos, lo que se quita de la pantalla es un profesor
que **sigue contratado**.

La primera además no devuelve JSON. Todas las demás respuestas de error de esta
API son un objeto con `message`; ésa es una cadena suelta, así que un cliente que
la parsee no saca el motivo — saca un fallo de parseo.

### Contratos es una que ya se debería haber cerrado

`postIndex` **del mismo controlador** se cerró en la §78: contratar a un profesor
que no existe escribía una fila huérfana y contestaba 200. Se arregló el alta y
se dejó la baja. Es la misma forma que la papelera de la §76 —cinco sitios
cerrados y la mitad que devuelve abierta un mes— y la misma que apareció esta
noche en `boletines2/destroy`.

`Contrato::destroy($id)` devuelve **cuántas filas borró**, y ese número se
devolvía tal cual: por eso el cuerpo era `0`.

Se mira también `deleted_at`, con el criterio que ya usa
`EscalasDeValoracionController::exigirQueLaEscalaExista`: un contrato ya en la
papelera no está, y volver a borrarlo tampoco es «hecho». Sin eso, la
comprobación de existencia diría que sí y `destroy` devolvería `0` — **el mismo
200 mentiroso por otra puerta**, y ése tiene su test aparte.

### Código muerto borrado

`DefinicionesComportamientoController::show()` y `::update()`, los dos **sin
ruta** (`routes/api/disciplina.php` sólo publica index, los dos store y destroy).
Regla de CLAUDE.md: sin ruta y roto se borra. `update` además llegaba roto —traía
la §81 entera dentro—, y un método muerto con un fallo dentro es peor que uno
muerto.

**Comprobado al revés** contra `HEAD~1`: el test cuenta **2 de 10**, y el caso
del contrato ya en la papelera cae por su lado. Son dos caminos y no uno.

`BorrarUnCatalogoQueNoExisteTest` · commit `4737440`

### Sobre qué población se cierra

Sobre **las diez tablas de catálogo del lote A**, y comprobado que la operación
no vive en otro sitio:

```bash
grep -rn -E '(Area|Frase|...|Grado)::(destroy|find|findOrFail)' app/
grep -rn -iE '(delete +from +(areas|frases|...))|(update +... +set[^\x27]*deleted_at)' app/
```

Ninguna escritura ni borrado de esas diez tablas fuera de estos diez
controladores. Es la comprobación que faltó en las series de arriba: **el nombre
del fichero no dice sobre qué tabla escribe.**

---

## §83 — Qué alcanza un alumno por las lecturas de catálogo

La pregunta literal del lote era qué ve un alumno por los `GET .../show/{id}` que
sólo piden `auth.token`. Los dos de este lote —`niveles_educativos/show/{id}` y
`grados/show/{id}`— **no le dan nada que su listado hermano, también
`auth.token`, no le diera ya**. Eso es un resultado, no un hueco.

Lo que sí faltaba es la pregunta de al lado: **de las once lecturas del lote,
¿cuántas sacan datos de una persona?** Barridas las once con token de alumno
mirando el cuerpo: **exactamente una.**

`GET api/contratos` devuelve, de cada docente contratado del año: `num_doc`,
`tipo_doc`, `fecha_nac`, `ciudad_nac`, `ciudad_doc`, `direccion`, `barrio`,
`telefono`, `celular`, `email`, `email_usu`, `estado_civil`, `facebook`,
`username` e `is_superuser`. Sale de `Profesor::contratos()`, y la ruta lleva
`auth.token` a secas mientras sus dos hermanas del mismo controlador llevan
`auth.personal`.

**No se cierra**, y no por falta de ganas: está medida y decidida en la
[§14.4](../05-codigo-muerto-y-roto.md) y esperando en la tabla del §5 de
[09-pendientes.md](../09-pendientes.md). La app de Flutter la llama desde
pantallas de alumno y de acudiente y **sólo la usa para pasar de un id a un
nombre**, así que lo que hay que recortar es la respuesta y no la puerta — y la
app es una sola para los dieciséis colegios.

El test existe para que la afirmación sea **«exactamente una de once»** y no «que
se sepa, una», y para que el día que se recorte `contratos` quede fijado qué
había antes. **Un hallazgo escrito en prosa no vigila nada.**

### Una corrección que hizo la medición

Al escribir esta sección se dio por hecho que `grados/show` enseñaba **una** clave
de más respecto a su listado, `nivel`, porque es la única que el método añade a
mano y por tanto la única que se ve leyéndolo. Son **cinco**: `created_by`,
`updated_by`, `deleted_by`, `deleted_at` y `nivel`.

Las otras cuatro no las pone nadie: **las quita el hermano.** `getIndex` de
grados es un `SELECT` con las columnas nombradas una a una y `getShow` devuelve
el modelo Eloquent entero. Eso no se ve en `getShow` por mucho que se lea — la
asimetría no vive en el método que se está mirando. Ninguna de las cinco es dato
de nadie: las tres `*_by` están a null en las dieciséis filas de `grados`.

`LecturasDeCatalogoConTokenDeAlumnoTest` · commit `7fa04fe`

---

## §84 — Los tres catálogos con año, y las cinco escrituras que lo cruzan

De las diez tablas del lote, **exactamente tres tienen `year_id`**: `frases`,
`escalas_de_valoracion` y `contratos`. Las otras siete son del colegio entero.

En las tres el listado filtra por `$user->year_id`. **Ninguna de sus cinco
escrituras lo comprueba.** Medido con un usuario del año 8:

```
GET    api/frases                 ->  47 frases, y la id 1 (año 1) NO está
PUT    api/frases/update/1        ->  200, y la frase del año 1 queda pisada
DELETE api/frases/destroy/1       ->  200, y se va a la papelera
DELETE api/contratos/destroy/124  ->  200, y el contrato del año 7 se va
```

O sea: **se edita y se borra una fila que no se puede ni ver desde el propio
listado.**

### Por qué se fija y no se cierra

Porque en una de las tres ya está decidido que sí, y a propósito.
`EscalasDeValoracionController::deleteDestroy` lo lleva escrito desde la §27.4:
*«se puede borrar la escala de otro año a propósito»*. El motivo es bueno — las
escalas de un año pasado siguen decidiendo cómo se pinta el desempeño en los
boletines **de ese año** —, y el mismo argumento vale igual de bien para el banco
de frases y para descontratar a alguien de un año cerrado.

Lo que **no** está decidido es si vale para las otras dos, y la diferencia entre
las tres no es una decisión: **la §27.4 se tomó mirando escalas y quedó escrita
en escalas.** Es lo mismo de esta noche —cerrar sobre una población no cierra
sobre la de al lado— sólo que aquí lo que se arrastró de más fue **el permiso** y
no el arreglo.

El test afirma que **las cinco se comportan igual entre sí**: el día que alguien
cierre una porque la tenía delante, cae y obliga a decidir sobre las cinco a la
vez. Eso es lo único que no se consigue escribiéndolo en prosa.

### Dos trampas que estaban a una línea

- El listado de contratos **no devuelve el id del contrato como `id`** sino como
  `contrato_id`, porque la fila que sale es la del profesor. Mirar `id` habría
  comparado ids de `profesores` con uno de `contratos` y habría dicho «no está»
  siempre.
- **Un listado vacío tampoco trae la fila ajena**, así que «no la enseña» se
  cumpliría sin filtrar nada. Las tres comprobaciones de no-vacío son las tres
  assertions de más del test.

`EscrituraDeCatalogoDeOtroAnioTest` · commit `143889a`

---

## Lo que queda anotado y no se tocó

### Para Joseth / el colegio

1. **Las cinco escrituras del §84**: ¿se pueden editar y borrar frases y
   contratos de años pasados, como ya está decidido para las escalas? Hoy sí, en
   las cinco. Cerrarlo dejaría a un colegio sin poder corregir el banco de frases
   de un año que aún imprime boletines.
2. **`GET api/contratos`** (§83): sigue esperando en el §5 de 09. Ahora hay un
   test que fija exactamente qué columnas se van hoy, así que el día que se
   recorte se ve qué se movió.
3. **Borrar un grado apaga la planilla de sus profesores** (§70): no se tocó, ya
   estaba en la tabla del §5.

### Para otros lotes

- `MateriasController::postIndex` (línea 82) tiene el mismo
  `Request::input('area')['id']` sobre null que se arregló en `putUpdate`, y da
  **500** con el cuerpo vacío. **No se tocó**: está fijado por
  `CrearUnCatalogoTest` y pertenece a la mitad de *crear*, que la §78 cerró.
  Arreglarlo cambiaría un 500 por otro 500 con distinto mensaje — el `INSERT`
  seguiría fallando por `materia NOT NULL`, y sin `try/catch` que lo traduzca.
- **La §78 hay que corregirla al fundir**: su conclusión sobre el `NOT NULL` sólo
  vale para crear. La medición está en el §81 de aquí arriba.

### Nada de esto

- Ninguna migración.
- `composer.json` sin tocar: el alcance de pint no incluye
  `app/Http/Controllers`, y meter los ocho ficheros ahí es editar un fichero
  compartido por seis sesiones. Acordado con quien coordina que lo hace al
  amanecer, después de fundir. Se escribió respetando el estilo de alrededor.
