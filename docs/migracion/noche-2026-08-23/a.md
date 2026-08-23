# Lote A — Catálogos: editar y borrar (§81–84 y §122)

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

## §122 — La séptima de la §81, la que ningún detector podía ver

> El rango del lote era §81–84. Ésta se sale, y quien coordina la numeró
> **§122**: §85–88 son del lote B, y §122+ queda abierto para lo que se salga de
> los repartos originales.

`PUT escalas/update` con el cuerpo `{"id":1}`, medido el 23 ago 2026:

```
SUPERIOR · S · 46-50 · orden 5   ->   '' · '' · 0-0 · orden 0
```

Seis de las nueve columnas que ese `UPDATE` escribe son `NOT NULL`
—`desempenio`, `valoracion`, `porc_inicial`, `porc_final`, `orden`, `perdido`—
y con `strict => false` eso no es un error: es `''` y `0` (§81).
**`porc_inicial=0, porc_final=0` es la banda colapsada** en la tabla que decide
cómo se pinta el desempeño en todos los boletines del año.

### Por qué no salió en ninguna de las dos listas que la tenían delante

**El barrido de la operación por todo `app/`** busca un método que resuelva una
fila existente con `find/findOrFail/first/onlyTrashed` **y** le asigne columnas
con `Request::input(...)`. Éste no hace ninguna de las dos cosas: comprueba la
existencia con un `SELECT` dentro de un helper privado y escribe con un
`DB::update` crudo.

> No es un falso negativo de la clasificación: **es que el método no llegó a ser
> candidato.** La población de partida no era `app/`, era la parte de `app/` que
> usa Eloquent — y en este repo hay 990 consultas crudas. El número bueno es
> «28 **de lo que ese patrón alcanza**», no «28 de `app/`».

Es la trampa que la §53 dejó escrita —*el detector también se queda ciego ante un
nombre nuevo*— con el nombre nuevo siendo, esta vez, **una escritura que no pasa
por el ORM**.

**Y en la §81 se cayó de mi propia lista** porque con el cuerpo vacío contesta
**404**. El 404 es correcto —el id va en el cuerpo, y sin cuerpo no hay id que
buscar— pero **contesta a otra pregunta**: la de verdad empieza justo después del
id. Tercera vez esta noche que una respuesta correcta por el motivo equivocado
tapa lo que parece cubrir. Las otras dos fueron `grados` con su 422 y `materias`
con su 500, y las tres son de la misma sección.

### El arreglo: el defecto de `input()`, no `CamposQueVinieron`

El discriminador entre las dos herramientas está medido: la clase hace falta
cuando el controlador tiene un `Request::merge()` o un `sanarInput*` **antes** de
leer, porque a esa altura `has()` ya no distingue lo que mandó el cliente.
`EscalasDeValoracionController` no tiene ninguno de los dos (cero coincidencias
en el fichero), así que basta el defecto.

Y el defecto sale de la fila que ya estaba en la base **sin costar una consulta**:
`exigirQueLaEscalaExista()` ya hacía ese `SELECT`, sólo que pedía `id`. Ahora
devuelve la fila entera.

### Comprobado al revés, dos veces — y la segunda es la que importa

| Qué se probó | Qué cae |
|---|---|
| Revertido del todo | 1 de 3 |
| **La solución equivocada que parecía buena**: `?: ` en vez del defecto de `input()` | **sólo el tercero** |

El `?:` pasa «no vacía la escala» y pasa «editar entero sigue escribiendo». Lo
único que lo caza es el tercer test, porque **los dos ceros de esta tabla son
legítimos**: `porc_inicial = 0` es el borde inferior de la escala más baja del
colegio y `perdido = 0` el valor normal de las tres que se aprueban. Un `?:`
habría convertido «poner la banda a 0» en «no cambiar nada», con los otros dos
verdes de rigor.

### Y ese tercer test se saltaba entero en su primera versión

Pedía una escala con `porc_inicial <> 0` **y** `perdido <> 0`. En las cuatro
escalas de cada año la única con `perdido = 1` es BAJO, que es justamente la que
empieza en `porc_inicial = 0`: cero filas, `markTestSkipped`, y **en la línea de
resumen un test saltado se lee igual que uno que pasa**. Va en dos filas
distintas.

Es la misma forma que el verde hueco del §84 —un listado vacío tampoco trae la
fila ajena— y me ha pasado **dos veces en una noche**, así que va escrito como
patrón y no como descuido: *afirmar sobre una población que puede estar vacía sin
comprobar que no lo está.*

`EditarUnaEscalaDeValoracionTest` · commit `57ce228`

### La población de la §81 en mis diez controladores, cerrada con la lista

| Método | Estado |
|---|---|
| `Areas::putUpdate`, `Frases::putUpdate`, `Materias::putUpdate`, `NivelesEducativos::putUpdate`, `Grados::putUpdate`, `TipoDocumento::update` | arreglados en §81 |
| `EscalasDeValoracion::putUpdate` | §122 |
| `Areas::putUpdateOrden`, `Materias::putUpdateOrden` | descartados: escriben una o dos columnas que **son el contenido del payload** (`orden`, y `area_id` sólo en la rama `partTo`), no campos pisados |
| `Contratos::postIndex` | descartado: resuelve una fila, pero para un `new Contrato` — el discriminador de la §68 |
| `DefinicionesComportamiento::update` | ya no existe, borrado en la §82 por estar sin ruta |

**Cero pendientes**, y dicho con la lista delante y no con un «ya está».

---

## Lo que queda anotado y no se tocó

### Para Joseth / el colegio

0. **§122 no cambia nada de lo anterior, pero conviene que se lea junto**: la
   séptima ruta de la §81 estaba en `escalas`, que es una de las tres tablas del
   §84. Las dos secciones tocan el mismo controlador por motivos distintos.
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

---

## Una nota sobre los instrumentos, porque esta noche mintieron tres

1. **`| tail -60` sobre la suite** me devolvió el código de salida del `tail` y
   no el de la suite: un `0` que no significaba nada, con la salida cortada por
   la F. Es la regla 6 de la noche y me mordió igual.
2. **Y esa misma tanda estaba contaminada** por un huérfano de `phpunit` corriendo
   contra mi misma base: un `pkill -f "artisan test"` de otra sesión mató el
   envoltorio y **dejó al hijo vivo, reparentado a init**. Dos tandas contra una
   base chocan en `personal_access_tokens` y el rojo sale en cualquier familia
   con toda la cara de ser un test roto.
   Para matar lo propio de verdad:
   `pgrep -f "phpunit.*worktrees/a" | xargs -r kill -9` — y comprobar que queda
   vacío. **El `cwd` no sirve para identificar el árbol**: un test se mete en un
   directorio temporal. Lo estable es el `--configuration=` del cmdline.
3. Las dos, más el `vendor/` enlazado de la cabecera del 15, son la misma forma:
   **un instrumento que contesta con la cara del problema.**

Todas las mediciones de este documento son de tandas limpias, con `pgrep`
comprobado vacío antes y el código de salida guardado aparte del texto.
