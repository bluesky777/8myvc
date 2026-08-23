# Lote R — El boletín de una familia (§140–142 y §166)

> Sesión `8myvc-06`, árbol `.worktrees/r`, rama `fix/lote-r-boletin-de-la-familia`,
> base `simonbolivar_testing_r`. Noche del 22 al 23 de agosto de 2026.
>
> **Es el único hallazgo de la noche que le está pasando a un colegio ahora
> mismo**: una familia entra, pide el boletín y recibe un error del servidor.

Dos fallos distintos en los mismos dos ficheros, y los dos tapados por la misma
cosa: **la maqueta 1 se prueba y las otras dos no**.

---

## §140 — 500 en vez del boletín, en las maquetas 2 y 3

Medido con un acudiente pidiendo el boletín **de su acudido**:

```
PUT api/boletines/detailed-notas/{grupo}    ->  200
PUT api/boletines2/detailed-notas/{grupo}   ->  500  Undefined property:
PUT api/boletines3/detailed-notas/{grupo}   ->  500    stdClass::$year_pasado_en_bol
```

### La causa no está en los boletines

`year_pasado_en_bol` es una columna de `years` —dice si el boletín arrastra el
año pasado— y `ContextoDeUsuario` la selecciona en **tres de sus cuatro ramas**:
`Profesor`, `Alumno` y `Usuario`. En `Acudiente` no.

> Es una configuración **del año**, no del tipo de usuario, y estaba puesta por
> tipo de usuario. Un acudiente mira el boletín del mismo año que su acudido y
> necesita exactamente la misma configuración.

Se arregla ahí, en la rama, y no con un `isset` en los boletines.

### Por qué la maqueta 1 sí funcionaba

```php
BoletinesController.php:224    if (isset($this->user->year_pasado_en_bol)) {
Boletines2Controller.php:155   if ($this->user->year_pasado_en_bol) {
Boletines3Controller.php:157   if ($this->user->year_pasado_en_bol) {
```

El `isset` de la primera **tapaba el agujero del contexto** justo en la única
maqueta que alguien probaba con una familia. No es que la maqueta 1 esté bien:
se defiende de un dato que no debería faltar.

### Y por qué no lo cazó ningún test

`AutorizacionTest` prueba al **alumno** contra las tres maquetas —tiene un
`#[DataProvider]`— y al **acudiente** contra `boletines` a secas. La cobertura
decía que las tres estaban comprobadas, y lo estaban: **para el otro tipo de
usuario.**

> Un test verde no dice que la ruta esté bien: dice que alguien miró otra cosa.

Por eso el test nuevo lleva `#[DataProvider]` con las tres desde el principio.

### El snapshot, verificado y no dado por bueno

`login-contexto-acudiente.json` cambia, y debe cambiar. Se regeneró y se miró el
diff: **una sola clave**, `"year_pasado_en_bol": "int"`, y es un snapshot de
**forma** (clave→tipo), así que el cambio es aditivo para
`aplicacion-descargas/detailed`, la única ruta que devuelve el `$user` entero.

**Comprobado al revés**: sin el arreglo caen 3 de 4, y la que sobrevive es la
maqueta 1 — exactamente por su `isset`.

`BoletinDeLaFamiliaTest` · commit `7b27174`

### Una corrección de número, y la lección es del instrumento

Al medir qué más le falta a la rama del acudiente se parsearon los alias del
`SELECT` y salieron **13** columnas. El número bueno es **14**: el parser se
comía `profesor_id`, que se asigna en PHP fuera del `switch`.

Medido como se debe —desde el objeto real, por `/api/auth/me`—: **Profesor 48,
Usuario 47, Alumno 45, Acudiente 42** (43 con el arreglo).

La conclusión no cambia y ahora está medida en vez de deducida: **de las que
faltan, sólo `year_pasado_en_bol` se lee en un camino que un acudiente alcanza.**
Las otras cinco que se leen sin proteger viven detrás de `auth.personal` o de un
`$user->tipo == 'Profesor'`, y se comprobó que `ChangesAsked/to-me` —la pantalla
de inicio de los cuatro tipos— contesta **200** a un acudiente.

---

## §141 — Un centinela que no es falsy

`NotaComportamiento::nota_comportamiento()` tiene dos salidas de tipo distinto:

```php
return $nota;                        // hay nota: un OBJETO
return [ "notas_finales" => [] ];    // no hay: un ARRAY
```

Y los llamantes preguntan `if ($nota)`. **Un array no vacío es truthy**, así que
el `if` pasa y la línea siguiente lee una propiedad de un array:

```
PUT api/notas-actuales-alumnos/{grupo}
  -> 500  Attempt to read property "nota" on array
```

Medido con token de acudiente **y** con token del personal: los dos. No es de
autorización ni de familias — **lo dispara un solo alumno al que le falte la
nota del periodo, y tumba la petición del grupo entero**.

### El centinela no se toca, y no es prudencia

`["notas_finales" => []]` está **moldeado**. En `myvc_front`:

- cuatro plantillas de boletín recorren `alumno.comportamiento.notas_finales`
  con `ng-repeat`;
- el tipo declarado es `{ nota?: number } | never[]` — **modela las dos formas a
  propósito**;
- y dos controladores de puestos hacen `if (!isNaN(alumno.comportamiento.nota))`
  antes de sumar.

> **La misma forma es inofensiva en el cliente y fatal en el servidor.** En JS,
> `.nota` sobre un arreglo da `undefined`; en PHP, `->nota` lanza. El front lo
> tropezó, lo midió y se defendió; el backend nunca se enteró.

Se para en el llamante con `is_object`, que es además lo que ya se decidió esta
misma noche para `Profesor::detallado()` por un camino independiente.

### Y la forma de la respuesta no cambia

Tres de los llamantes ya sobrevivían **por accidente**: su `catch (\Throwable)`
escribe `$alumno->comportamiento['definiciones'] = []`. O sea que el array que ve
el cliente lleva **hoy** esa clave. Los dos que no tenían `catch` la escriben
ahora en un `else` explícito — si no, arreglar el 500 le habría cambiado la forma
a dos rutas.

**Comprobado al revés, dos veces:**

| Qué se probó | Qué cae |
|---|---|
| Revertido del todo | 3 de 4 |
| **Sólo el llamante**, sin `encabezado_comportamiento_boletin` | las mismas 3 |

O sea que el llamante **no es donde revienta primero**, y la solución que parecía
suficiente no lo era.

`CentinelaDelComportamientoTest` · commit `b6b83a7`

---

## §166 — La quinta copia que hubo que revertir

`encabezado_comportamiento_boletin()` está copiada en **cinco** controladores de
`Informes/`, así que se cambiaron las cinco. **Larastan paró la quinta**:
`binaryOp.invalid` en `BolfinalesPreescolarController:276`.

Al medir por qué: **esa copia no recibe lo mismo que las otras cuatro.** Su único
llamante (línea 209) le pasa `$alumno->nota_comportamiento_year`, que sale de
`NotaComportamiento::notas_comportamiento_year()` y es una **lista de periodos**,
no el objeto ni el centinela.

Con una lista, `is_object` es **siempre falso**: el cambio habría apagado la
cabecera de comportamiento del boletín de preescolar **en silencio y con los
tests en verde**. Revertida.

> **Ampliar un arreglo a «todas las copias» sin comprobar qué recibe cada una es
> la forma de romper la que estaba bien.**

Y lo que destapó estrechar el tipo, anotado y sin tocar: esa quinta copia hace
`$la_nota = $nota;` donde **las otras cuatro hacen `$la_nota = $nota->nota;`**, y
ese valor se concatena en el HTML doce líneas más abajo. Es la única de las cinco
que difiere. Con el seed de hoy las dos rutas de preescolar contestan **200**, así
que **no está probado que reviente**.

---

## §142 — Pedir el grupo sin la lista de alumnos es un 500 seguro

Salió de paso y no tiene que ver con las notas:

```php
$requested_alumnos = Request::input('requested_alumnos', '');   // una CADENA
…
foreach ($requested_alumnos as $req_alumno) {                    // doce líneas después
```

Cualquiera que llame a `PUT api/notas-actuales-alumnos/{grupo}` sin esa clave
recibe `foreach() argument must be of type array|object, string given`, con
cualquier token.

**Se fija y no se arregla.** El bucle interior sólo procesa a los alumnos que
aparecen en la lista, así que un `[]` por defecto devolvería **200 con el grupo
vacío** —un 200 hueco, que en este repo es peor que el error— y la otra salida es
**422**, que es el código correcto pero cambia lo que recibe una ruta enrutada.
Cuál de las dos es una decisión, y R se abrió por el boletín de una familia.

Con ruta y roto se documenta.

---

## Tres veces que el test estuvo mal y el mensaje culpaba al código

Vale la pena escribirlas juntas porque las tres dan mensajes que **apuntan al
sitio equivocado**:

| Lo que decía el test | Dónde estaba el error |
|---|---|
| «El seed no tiene ningún alumno sin nota del periodo actual» | El controlador no usa el periodo `actual` ni el del usuario: usa **el de cada alumno** (`$periodo_id = $alumno->periodo_id`, línea 134). Se buscaba por el periodo equivocado sobre un caso que existía |
| «El alumno no sale en la respuesta del grupo» | La respuesta es una **tupla** `[$grupo, $year, $alumnos]`, no una lista con clave `alumnos` |
| «Undefined array key comportamiento» | `comportamiento` no cuelga del alumno sino **de cada periodo suyo** |

Y una cuarta, que es de población: el alumno «con nota» se elegía sin filtrar por
`estado`, y el que salía no entraba en la lista que se pedía.

La condición del centinela acabó **provocándose** dentro del test —se le borran
las notas a un alumno, dentro de la transacción— en vez de buscarse en el seed.
Un caso que depende de un accidente de los datos es un caso que desaparece el día
que se regenera el seed.

---

## Lo que queda anotado y no se tocó

### Para Joseth / el colegio

- **§142**: qué debe contestar `notas-actuales-alumnos` sin `requested_alumnos`
  —200 con el grupo vacío o 422—. Hoy es 500.

### Para otros lotes

- **§166**: `BolfinalesPreescolarController` es la única de las cinco copias con
  `$la_nota = $nota;`. No está probado que reviente.
- **`PuestosController:271`** escribe a mano `count($comportamiento) > 0 ?
  $comportamiento[0] : []`: **la misma dualidad en un séptimo sitio**, o sea que
  no es «un modelo con dos formas» sino un modismo del proyecto. No es de este
  lote y no se tocó.

### Nada de esto

- Ninguna migración.
- El modelo `NotaComportamiento` **sin tocar**: su centinela es contrato con
  cuatro plantillas.
